<?php
/**
 * API: Ad-hoc ticket report (Phase 4A).
 *
 * A thin adapter over includes/ticket_report.php — the aggregation itself
 * (dimensions, filter engine, tenant scope) lives there so the scheduled-reports
 * cron (Phase 8b) runs the identical query. A report is "the same filters as a
 * queue, plus a GROUP BY".
 *
 * GET/POST:
 *   group_by = see ticket_report_dims() (status | priority | type | ... |
 *              sla_response_outcome | sla_resolution_outcome)
 *   filters  = JSON object (see includes/ticket_filter.php); optional
 *
 * Returns { success, group_by, total, rows: [{ label, count }] } ordered by
 * count desc. NULL dimension values collapse to a "None/Unassigned" label.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_report.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $groupBy = $_GET['group_by'] ?? $_POST['group_by'] ?? 'status';

    $filtersRaw = $_GET['filters'] ?? $_POST['filters'] ?? '';
    $filters = [];
    if ($filtersRaw !== '') {
        $decoded = json_decode($filtersRaw, true);
        if (is_array($decoded)) $filters = $decoded;
    }

    $report = ticket_report_run($conn, $analystId, $groupBy, $filters);

    echo json_encode([
        'success'  => true,
        'group_by' => $report['group_by'],
        'total'    => $report['total'],
        'rows'     => $report['rows'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
