<?php
/**
 * API: migrate data from another ticketing system. Admin only.
 *
 * POST JSON { dataset, csv, mapping, value_map, create_values, mode }
 *   mode=analyse  → source header, suggested mapping, per-lookup value report.
 *                   Reads nothing but the file; writes nothing.
 *   mode=preview  → applies the mapping, runs the importer's plan, returns the
 *                   reconciliation and per-row problems. Still writes nothing.
 *   mode=commit   → creates any requested lookup values, writes the rows, then
 *                   backfills SLA outcomes. Returns the reconciliation to sign off.
 *
 * The three modes share one code path up to the point of writing, so a preview
 * reports exactly what a commit will do.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/functions.php';
require_once '../../includes/data_migrate.php';
require_once '../../includes/migrate_sources.php';

header('Content-Type: application/json');

// A migration file can be large and the commit does a lot of work per row.
@set_time_limit(900);

/**
 * Turn a fatal into JSON.
 *
 * Exhausting memory or time on a big file makes PHP emit an HTML error page,
 * which reaches the browser as "Unexpected token '<'" — an error that says
 * nothing about the actual problem and sends people looking for a bug in the
 * file. Report the real cause and the setting that governs it.
 */
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    $msg = $e['message'];
    if (stripos($msg, 'allowed memory size') !== false) {
        $msg = 'The server ran out of memory reading this file (memory_limit is '
             . ini_get('memory_limit') . '). Split the export by date, or raise memory_limit '
             . 'in docker/php.ini and rebuild.';
    } elseif (stripos($msg, 'maximum execution time') !== false) {
        $msg = 'The server timed out processing this file (max_execution_time is '
             . ini_get('max_execution_time') . 's). Split the export by date, or raise it '
             . 'in docker/php.ini and rebuild.';
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => $msg]);
});

// Read the body once — php://input is not reliably re-readable across SAPIs.
$rawInput = file_get_contents('php://input');

// A body larger than post_max_size is discarded by PHP before this script runs,
// leaving an empty input and a baffling "No CSV supplied". Say what happened.
$declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > 0 && $rawInput === '') {
    echo json_encode(['success' => false, 'error' => 'The upload (' . round($declared / 1048576, 1)
        . ' MB) is larger than this server accepts (post_max_size is ' . ini_get('post_max_size')
        . '). Raise it in docker/php.ini and rebuild, or split the export by date.']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $in = json_decode($rawInput, true) ?: [];
    // Free the raw body before parsing: on a 30 MB migration it is a 30 MB string
    // that is never needed again, and the parse below is the memory high-water mark.
    $rawInput = '';
    $mode = $in['mode'] ?? 'analyse';

    // ---- what can this admin migrate into? ------------------------------
    if ($mode === 'targets') {
        $out = [];
        foreach (data_migrate_targets() as $key => $hint) {
            try { $spec = data_migrate_spec($key); } catch (Exception $e) { continue; }
            if (!analystCanAccessModule($conn, $analystId, $spec['module'])) continue;
            $out[] = [
                'key'      => $key,
                'label'    => $spec['label'],
                'hint'     => $hint,
                'columns'  => data_import_template_columns($spec),
                'required' => data_import_required_columns($spec),
            ];
        }
        $sources = [];
        foreach (migrate_sources() as $key => $s) {
            $sources[] = ['key' => $key, 'label' => $s['label'],
                          'dataset' => $s['dataset'], 'notes' => $s['notes'] ?? ''];
        }

        echo json_encode([
            'success'  => true,
            'targets'  => $out,
            'sources'  => $sources,
            'row_cap'  => data_migrate_row_cap(),
            'backfill' => data_migrate_backfill_report(),
        ]);
        exit;
    }

    $datasetKey = (string)($in['dataset'] ?? '');
    $spec = data_migrate_spec($datasetKey);
    if (!analystCanAccessModule($conn, $analystId, $spec['module'])) {
        throw new Exception('You do not have access to the ' . $spec['module'] . ' module');
    }

    $csv = (string)($in['csv'] ?? '');
    if (trim($csv) === '') throw new Exception('No CSV supplied');

    // A named source preset replaces the guessed mapping with a known-good one
    // and, crucially, normalises the cells first — day-first dates and
    // millisecond durations would otherwise be imported as-is and be wrong in a
    // way nothing downstream could detect.
    $sourceKey = trim((string)($in['source'] ?? ''));
    $preset = null;
    $applied = null;
    $keepIdx = null;

    if ($sourceKey !== '') {
        $allSources = migrate_sources();
        if (!isset($allSources[$sourceKey])) throw new Exception('Unknown source preset: ' . $sourceKey);
        $preset = $allSources[$sourceKey];
        if ($preset['dataset'] !== $datasetKey) {
            throw new Exception("The {$preset['label']} preset imports into '{$preset['dataset']}', not '{$datasetKey}'");
        }
        // Read the header alone first so the parse can discard the columns this
        // preset will never touch — on a wide export that is the difference
        // between ~500 MB and ~60 MB of resident rows.
        $keepIdx = data_migrate_preset_indices($preset, data_migrate_header($csv));
    }

    $parsed = data_migrate_parse($csv, $keepIdx);
    [$header, $rows, $truncated] = $parsed;
    if (!$rows) throw new Exception('The CSV has a header but no data rows');

    if ($preset !== null) {
        // Rewrites $rows in place — see migrate_apply_source().
        $applied = migrate_apply_source($preset, $header, $rows);
        $parsed = [$header, $rows, $truncated];
    }

    // ---- analyse ---------------------------------------------------------
    if ($mode === 'analyse') {
        // A preset is authoritative; only fall back to guessing without one.
        $suggest = $applied !== null
            ? ['mapping' => $applied['mapping'], 'detail' => [], 'conflicts' => [],
               'unmapped' => array_values(array_diff($header, array_keys($applied['mapping'])))]
            : data_migrate_suggest($header, $spec);
        echo json_encode([
            'source'         => $sourceKey ?: null,
            'source_label'   => $preset['label'] ?? null,
            'source_notes'   => $preset['notes'] ?? null,
            'source_missing' => $applied['missing'] ?? [],
            'source_derives' => $applied ? array_keys($applied['derive_idx']) : [],
            'value_map'      => $applied['value_map'] ?? new stdClass(),
            'success'    => true,
            'dataset'    => $datasetKey,
            'label'      => $spec['label'],
            'source_columns' => $header,
            'row_count'  => count($rows),
            'truncated'  => $truncated,
            'mapping'    => $suggest['mapping'],
            'detail'     => $suggest['detail'],
            'conflicts'  => $suggest['conflicts'],
            'unmapped'   => $suggest['unmapped'],
            'targets'    => data_import_template_columns($spec),
            'required'   => data_import_required_columns($spec),
            'lookups'    => array_keys($spec['lookups'] ?? []),
            'values'     => data_migrate_value_report($conn, $spec, $header, $rows, $suggest['mapping']),
            'sample'     => array_slice($rows, 0, 3),
        ]);
        exit;
    }

    // preview and commit both need a mapping: the caller's, or the preset's when
    // the caller has not overridden it.
    $mapping = is_array($in['mapping'] ?? null) ? $in['mapping'] : [];
    $mapping = array_filter($mapping, fn($t) => is_string($t) && $t !== '');
    if (!$mapping && $applied !== null) $mapping = $applied['mapping'];
    if (!$mapping) throw new Exception('No column mapping was supplied');

    $valueMap = is_array($in['value_map'] ?? null) ? $in['value_map'] : [];
    if (!$valueMap && $applied !== null) $valueMap = $applied['value_map'];

    // Every mapped target must be a real field of this dataset — the mapping
    // arrives from the browser, so it is not trusted.
    $accepted = data_import_accepted_columns($spec);
    foreach ($mapping as $src => $target) {
        if (!in_array(strtolower($target), $accepted, true)) {
            throw new Exception("'{$target}' is not a field of " . $spec['label']);
        }
    }

    // ---- preview ---------------------------------------------------------
    if ($mode === 'preview') {
        $canonical = data_migrate_rewrite($header, $rows, $mapping, $valueMap);
        $plan = data_import_plan($conn, $spec, $analystId, $canonical);
        echo json_encode([
            'success'      => true,
            'mode'         => 'preview',
            'reconcile'    => data_migrate_reconcile($parsed, $plan),
            'errors'       => array_slice($plan['errors'], 0, 100),
            'error_total'  => count($plan['errors']),
            'ignored'      => $plan['ignored'],
            'first_rows'   => array_slice(array_map(fn($p) => $p['values'], $plan['plan']), 0, 5),
            'values'       => data_migrate_value_report($conn, $spec, $header, $rows, $mapping),
        ]);
        exit;
    }

    // ---- commit ----------------------------------------------------------
    if ($mode === 'commit') {
        // 1. Create any lookup values the operator approved, so the rows that
        //    reference them stop failing. Done before planning, not during, so
        //    the plan sees a settled set of lookups.
        $createdValues = ['created' => 0, 'refused' => []];
        $requests = is_array($in['create_values'] ?? null) ? $in['create_values'] : [];
        if ($requests) {
            $createdValues = data_migrate_create_lookup_values($conn, $spec, $requests);
        }

        $canonical = data_migrate_rewrite($header, $rows, $mapping, $valueMap);
        $plan = data_import_plan($conn, $spec, $analystId, $canonical);
        if (!$plan['plan']) {
            throw new Exception('No valid rows to import — see the preview for the reasons.');
        }

        $commit = data_import_commit($conn, $spec, $analystId, $plan['plan']);

        // 2. Recover the ids we just touched. data_import_commit reports counts,
        //    not ids, so match on the dataset's natural key — the same key it
        //    used to decide create-vs-update.
        $touchedIds = [];
        $matchCol = $spec['match'] ?? null;
        if ($matchCol) {
            $keys = [];
            foreach ($plan['plan'] as $p) {
                if (isset($p['values'][$matchCol]) && $p['values'][$matchCol] !== null) {
                    $keys[] = $p['values'][$matchCol];
                }
            }
            foreach (array_chunk($keys, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE `{$matchCol}` IN ({$ph})");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $touchedIds[] = (int)$id;
            }
        }

        // 3. Reshape the source's own measured aggregates into our child rows —
        //    SLA verdict, reopens, reassignments, escalation, effort. Done before
        //    the SLA rebuild below so that, where the source gave us a verdict,
        //    it is already in place.
        $derivedCounts = null;
        if ($applied !== null && $applied['derive_idx']) {
            $keyIdx = array_search($preset['key_column'] ?? '', $header, true);
            if ($keyIdx !== false) {
                $derived = [];
                foreach ($rows as $cells) {
                    $num = trim((string)($cells[$keyIdx] ?? ''));
                    if ($num === '') continue;
                    $vals = [];
                    foreach ($applied['derive_idx'] as $name => $i) {
                        $vals[$name] = (string)($cells[$i] ?? '');
                    }
                    $derived[$num] = $vals;
                }
                try {
                    $derivedCounts = data_migrate_derive_children($conn, $derived, $analystId);
                } catch (Exception $e) {
                    $derivedCounts = ['error' => $e->getMessage()];
                    error_log('migrate: child derivation failed: ' . $e->getMessage());
                }
            }
        }

        // 4. Backfill what can honestly be derived. For tickets that means SLA
        //    outcomes, computed by the real SLA engine from the imported dates.
        //    Skipped when the source supplied its own verdict: recomputing would
        //    overwrite the figures already published to customers, which is
        //    exactly what the migration is trying to preserve.
        $backfill = null;
        $sourceGaveSla = is_array($derivedCounts) && !empty($derivedCounts['sla']);
        if ($sourceGaveSla) {
            $backfill = ['processed' => 0, 'tracked' => 0, 'errors' => [],
                'note' => 'SLA outcomes were taken from the source export (' . $derivedCounts['sla']
                        . ' tickets) and deliberately NOT recomputed, so historic attainment matches '
                        . 'what the previous system reported.'];
        } elseif ($datasetKey === 'tickets' && $touchedIds) {
            try {
                $backfill = data_migrate_backfill_sla($conn, $touchedIds);
            } catch (Exception $e) {
                // A failed backfill must not invalidate a successful import: the
                // rows are in, and the snapshot can be rebuilt by its own cron.
                $backfill = ['processed' => 0, 'tracked' => 0,
                             'errors' => ['SLA backfill failed: ' . $e->getMessage()
                                 . ' — run cron/sla_snapshot_rebuild.php to complete it.']];
            }
        }

        try {
            $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('data_migration', ?, ?, UTC_TIMESTAMP())")
                 ->execute([$analystId, "Migration into {$datasetKey}: created {$commit['created']}, updated {$commit['updated']}, "
                     . count($plan['errors']) . ' row error(s), ' . $createdValues['created'] . ' lookup value(s) created']);
        } catch (Exception $e) { /* non-fatal */ }

        echo json_encode([
            'success'        => true,
            'mode'           => 'commit',
            'reconcile'      => data_migrate_reconcile($parsed, $plan, $commit),
            'created_values' => $createdValues,
            'derived'        => $derivedCounts,
            'backfill'       => $backfill,
            'backfill_report'=> data_migrate_backfill_report(),
            'errors'         => array_slice($plan['errors'], 0, 100),
            'error_total'    => count($plan['errors']),
        ]);
        exit;
    }

    throw new Exception('Unknown mode');

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
