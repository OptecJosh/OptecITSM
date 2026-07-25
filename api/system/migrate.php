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

header('Content-Type: application/json');

// A migration file can be large and the commit does a lot of work per row.
@set_time_limit(900);

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
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
        echo json_encode([
            'success'  => true,
            'targets'  => $out,
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

    $parsed = data_migrate_parse($csv);
    [$header, $rows, $truncated] = $parsed;
    if (!$rows) throw new Exception('The CSV has a header but no data rows');

    // ---- analyse ---------------------------------------------------------
    if ($mode === 'analyse') {
        $suggest = data_migrate_suggest($header, $spec);
        echo json_encode([
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

    // preview and commit both need the caller's chosen mapping.
    $mapping = is_array($in['mapping'] ?? null) ? $in['mapping'] : [];
    $mapping = array_filter($mapping, fn($t) => is_string($t) && $t !== '');
    if (!$mapping) throw new Exception('No column mapping was supplied');

    $valueMap = is_array($in['value_map'] ?? null) ? $in['value_map'] : [];

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

        // 3. Backfill what can honestly be derived. For tickets that means SLA
        //    outcomes, computed by the real SLA engine from the imported dates.
        $backfill = null;
        if ($datasetKey === 'tickets' && $touchedIds) {
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
