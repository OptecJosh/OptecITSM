<?php
/**
 * API: mass CSV import — preview or commit.
 *
 * POST JSON { dataset, csv, mode: preview|commit }
 *
 * preview → { total, to_create, to_update, errors[], ignored_columns[], sample[] }
 *           and writes nothing.
 * commit  → { created, updated, skipped, errors[] } in one transaction, plus a
 *           system_logs entry so the import shows in the unified audit log.
 *
 * Admin only, and the admin must also have the dataset's module. Rows that fail
 * validation are reported and skipped; a row that fails its actual INSERT/UPDATE
 * rolls the whole run back rather than half-loading a module.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/functions.php';
require_once '../../includes/data_import.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = (string)($in['dataset'] ?? '');
    $mode = ($in['mode'] ?? 'preview') === 'commit' ? 'commit' : 'preview';
    $csv = (string)($in['csv'] ?? '');

    $datasets = data_import_datasets();
    if (!isset($datasets[$key])) throw new Exception('Unknown dataset');
    $spec = $datasets[$key];

    if (!analystCanAccessModule($conn, $analystId, $spec['module'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have access to that module']);
        exit;
    }

    $planned = data_import_plan($conn, $spec, $analystId, $csv);

    if ($mode === 'preview') {
        // A few resolved rows so the tester can see what will actually be written.
        $sample = [];
        foreach (array_slice($planned['plan'], 0, 5) as $p) {
            $sample[] = [
                'row'    => $p['row'],
                'action' => $p['id'] ? 'update' : 'create',
                'values' => $p['values'],
            ];
        }
        echo json_encode([
            'success'          => true,
            'mode'             => 'preview',
            'total'            => count($planned['plan']) + count($planned['errors']),
            'to_create'        => $planned['to_create'],
            'to_update'        => $planned['to_update'],
            'errors'           => array_slice($planned['errors'], 0, 200),
            'error_count'      => count($planned['errors']),
            'ignored_columns'  => $planned['ignored'],
            'sample'           => $sample,
        ]);
        exit;
    }

    $result = data_import_commit($conn, $spec, $analystId, $planned['plan']);

    try {
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('data_import', ?, ?, UTC_TIMESTAMP())")
             ->execute([$analystId, "Mass import ({$key}): created {$result['created']}, updated {$result['updated']}, skipped " . count($planned['errors'])]);
    } catch (Exception $e) { /* non-fatal */ }

    echo json_encode([
        'success' => true,
        'mode'    => 'commit',
        'created' => $result['created'],
        'updated' => $result['updated'],
        'skipped' => count($planned['errors']),
        'errors'  => array_slice($planned['errors'], 0, 200),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
