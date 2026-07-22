<?php
/**
 * API: The current analyst's own overtime (Phase 11a).
 * GET (no params). Returns { success, requests:[...], totals:{...} }.
 * "My" data — scoped by analyst_id, so no cross-company concern.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('overtime');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $stmt = $conn->prepare(
        "SELECT id, work_date, start_time, end_time, hours, overtime_type, rate_multiplier,
                reason, status, submitted_datetime, decided_datetime, decision_note,
                decided_by_id, (hours * rate_multiplier) AS weighted_hours
           FROM overtime_requests
          WHERE analyst_id = ?
       ORDER BY work_date DESC, id DESC"
    );
    $stmt->execute([$analystId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['pending_hours' => 0.0, 'approved_hours' => 0.0, 'approved_weighted' => 0.0];
    $requests = [];
    foreach ($rows as $r) {
        $h = (float)$r['hours'];
        if ($r['status'] === 'pending')  $totals['pending_hours']  += $h;
        if ($r['status'] === 'approved') { $totals['approved_hours'] += $h; $totals['approved_weighted'] += (float)$r['weighted_hours']; }
        $requests[] = [
            'id'              => (int)$r['id'],
            'work_date'       => $r['work_date'],
            'start_time'      => substr((string)$r['start_time'], 0, 5),
            'end_time'        => substr((string)$r['end_time'], 0, 5),
            'hours'           => $h,
            'overtime_type'   => $r['overtime_type'],
            'rate_multiplier' => (float)$r['rate_multiplier'],
            'reason'          => $r['reason'],
            'status'          => $r['status'],
            'decision_note'   => $r['decision_note'],
        ];
    }
    foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

    echo json_encode(['success' => true, 'requests' => $requests, 'totals' => $totals]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
