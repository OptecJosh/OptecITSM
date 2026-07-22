<?php
/**
 * API: CSV import for an entity (Phase 10b). Admin only.
 *
 * POST JSON { entity: assets|users, csv: "<raw csv text>", mode: preview|commit }.
 *
 * Header-driven: the CSV's first row names columns; only columns in the entity's
 * whitelist (data_io.php) are read, everything else is ignored. Rows are matched
 * to existing records by the entity's natural key (assets=hostname within the
 * admin's active company, users=email) → create-or-update. Only columns actually
 * present in the CSV are written, so a partial CSV never nulls untouched fields.
 *
 * preview  → { total, to_create, to_update, errors:[{row,message}] }, no writes.
 * commit   → applies valid rows in a transaction; { created, updated, skipped,
 *            errors }, and logs a system_logs summary. Rows with errors are
 *            skipped, not aborted.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/tenancy.php';
require_once '../../includes/data_io.php';
header('Content-Type: application/json');

const IMPORT_ROW_CAP = 5000;

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $key  = $data['entity'] ?? '';
    $mode = ($data['mode'] ?? 'preview') === 'commit' ? 'commit' : 'preview';
    $csv  = (string)($data['csv'] ?? '');

    $entities = data_io_entities();
    if (!isset($entities[$key])) throw new Exception('Invalid entity');
    $spec = $entities[$key];
    if (trim($csv) === '') throw new Exception('No CSV supplied');

    // Parse via a temp stream so quoted fields / embedded newlines are handled.
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    $header = fgetcsv($fh);
    if ($header === false || !$header) throw new Exception('CSV has no header row');
    // Strip UTF-8 BOM from the first header cell.
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

    // Which whitelisted columns are present, and at which CSV index.
    $colIndex = [];
    foreach ($header as $i => $h) {
        if (isset($spec['columns'][$h])) $colIndex[$h] = $i;
    }
    $matchCol = $spec['match'];
    if (!isset($colIndex[$matchCol])) {
        throw new Exception("CSV must include the '$matchCol' column");
    }

    // Tenant handling for scoped entities (assets).
    $multi = isMultiTenant($conn);
    $activeTenant = $multi ? getActiveTenantId($conn, $analystId) : null;
    $defaultTenant = getDefaultTenantId($conn);

    // Build a match-lookup for the natural key.
    $lookupExisting = function (string $matchVal) use ($conn, $spec, $matchCol, $multi, $activeTenant, $defaultTenant) {
        if (!empty($spec['tenant_scoped']) && $multi) {
            if ($activeTenant !== null && $activeTenant === $defaultTenant) {
                $s = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE `$matchCol` = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
                $s->execute([$matchVal, $activeTenant]);
            } else {
                $s = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE `$matchCol` = ? AND tenant_id = ? LIMIT 1");
                $s->execute([$matchVal, $activeTenant]);
            }
        } else {
            $s = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE `$matchCol` = ? LIMIT 1");
            $s->execute([$matchVal]);
        }
        $id = $s->fetchColumn();
        return $id !== false ? (int)$id : null;
    };

    $errors = [];
    $plan = [];   // valid rows: ['existingId'=>?, 'values'=>[col=>val]]
    $toCreate = 0; $toUpdate = 0;
    $rowNum = 1;  // header was row 1

    while (($cells = fgetcsv($fh)) !== false) {
        $rowNum++;
        if ($rowNum - 1 > IMPORT_ROW_CAP) { $errors[] = ['row' => $rowNum, 'message' => 'Row cap (' . IMPORT_ROW_CAP . ') exceeded; import truncated']; break; }
        // Skip wholly-empty lines.
        if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) continue;

        $values = [];
        $rowError = null;
        foreach ($colIndex as $col => $idx) {
            $raw = $cells[$idx] ?? '';
            [$ok, $val, $err] = data_io_normalise($col, $spec['columns'][$col], $raw);
            if (!$ok) { $rowError = $err; break; }
            $values[$col] = $val;
        }
        if ($rowError) { $errors[] = ['row' => $rowNum, 'message' => $rowError]; continue; }

        $matchVal = $values[$matchCol] ?? null;
        if ($matchVal === null || $matchVal === '') { $errors[] = ['row' => $rowNum, 'message' => "$matchCol is required"]; continue; }

        $existingId = $lookupExisting((string)$matchVal);
        if ($existingId) $toUpdate++; else $toCreate++;
        $plan[] = ['id' => $existingId, 'values' => $values];
    }
    fclose($fh);

    if ($mode === 'preview') {
        echo json_encode([
            'success'   => true,
            'mode'      => 'preview',
            'total'     => count($plan) + count($errors),
            'to_create' => $toCreate,
            'to_update' => $toUpdate,
            'errors'    => array_slice($errors, 0, 200),
        ]);
        exit;
    }

    // ---- Commit ----
    $created = 0; $updated = 0;
    $conn->beginTransaction();
    try {
        foreach ($plan as $p) {
            $vals = $p['values'];
            if ($p['id']) {
                // UPDATE only the present columns (never the match key itself).
                $set = [];
                $args = [];
                foreach ($vals as $col => $v) {
                    if ($col === $matchCol) continue;
                    $set[] = "`$col` = ?";
                    $args[] = $v;
                }
                if ($set) {
                    $args[] = $p['id'];
                    $conn->prepare("UPDATE `{$spec['table']}` SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
                }
                $updated++;
            } else {
                $cols = array_keys($vals);
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                $args = array_values($vals);
                // Stamp tenant for scoped entities.
                $extraCols = '';
                if (!empty($spec['tenant_scoped']) && $multi && $activeTenant !== null) {
                    $extraCols = ', tenant_id';
                    $ph .= ', ?';
                    $args[] = $activeTenant;
                }
                $colSql = implode(', ', array_map(fn($c) => "`$c`", $cols)) . $extraCols;
                $conn->prepare("INSERT INTO `{$spec['table']}` ($colSql) VALUES ($ph)")->execute($args);
                $created++;
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    // Audit the import to the system log (surfaces in the Phase 10a audit view).
    try {
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('data_import', ?, ?, UTC_TIMESTAMP())")
             ->execute([$analystId, "CSV import ({$key}): created {$created}, updated {$updated}, skipped " . count($errors)]);
    } catch (Exception $e) { /* non-fatal */ }

    echo json_encode([
        'success' => true,
        'mode'    => 'commit',
        'created' => $created,
        'updated' => $updated,
        'skipped' => count($errors),
        'errors'  => array_slice($errors, 0, 200),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
