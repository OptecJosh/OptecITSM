<?php
/**
 * API: Pending overtime awaiting the current analyst's decision (Phase 11b).
 * GET (no params). Admins see all pending; a line manager sees pending from the
 * analysts who report to them (analysts.manager_id = me). Routing is by the
 * manager relationship, so no company filter is applied — a manager sees exactly
 * their own reports.
 * Returns { success, requests:[...], can_approve:bool }.
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
    $isAdmin = analystIsAdmin($conn, $analystId);

    if ($isAdmin) {
        $stmt = $conn->prepare(
            "SELECT o.id, o.analyst_id, a.full_name AS agent_name, o.work_date, o.start_time, o.end_time,
                    o.hours, o.overtime_type, o.rate_multiplier, o.reason, o.submitted_datetime
               FROM overtime_requests o
               JOIN analysts a ON a.id = o.analyst_id
              WHERE o.status = 'pending'
           ORDER BY o.work_date ASC, o.id ASC"
        );
        $stmt->execute();
    } else {
        $stmt = $conn->prepare(
            "SELECT o.id, o.analyst_id, a.full_name AS agent_name, o.work_date, o.start_time, o.end_time,
                    o.hours, o.overtime_type, o.rate_multiplier, o.reason, o.submitted_datetime
               FROM overtime_requests o
               JOIN analysts a ON a.id = o.analyst_id
              WHERE o.status = 'pending' AND a.manager_id = ?
           ORDER BY o.work_date ASC, o.id ASC"
        );
        $stmt->execute([$analystId]);
    }

    $requests = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $requests[] = [
            'id'              => (int)$r['id'],
            'agent_name'      => $r['agent_name'],
            'work_date'       => $r['work_date'],
            'start_time'      => substr((string)$r['start_time'], 0, 5),
            'end_time'        => substr((string)$r['end_time'], 0, 5),
            'hours'           => (float)$r['hours'],
            'overtime_type'   => $r['overtime_type'],
            'rate_multiplier' => (float)$r['rate_multiplier'],
            'reason'          => $r['reason'],
            'submitted'       => $r['submitted_datetime'],
        ];
    }

    // can_approve gates the queue UI; a non-admin with no reports sees nothing.
    $canApprove = $isAdmin;
    if (!$isAdmin) {
        $c = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE manager_id = ?");
        $c->execute([$analystId]);
        $canApprove = (int)$c->fetchColumn() > 0;
    }

    echo json_encode(['success' => true, 'requests' => $requests, 'can_approve' => $canApprove]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
