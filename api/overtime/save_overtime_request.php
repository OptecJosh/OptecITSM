<?php
/**
 * API: Submit an overtime request (Phase 11a).
 * POST JSON { work_date, start_time, end_time, overtime_type, reason? }
 * Server computes hours, snapshots the rate multiplier, stamps the active
 * company, and (if approval is required) leaves it pending for the manager.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/overtime.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('overtime');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $workDate = trim((string)($data['work_date'] ?? ''));
    $start    = trim((string)($data['start_time'] ?? ''));
    $end      = trim((string)($data['end_time'] ?? ''));
    $type     = (string)($data['overtime_type'] ?? 'standard');
    $reason   = trim((string)($data['reason'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) throw new Exception('A valid work date is required');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
        throw new Exception('Valid start and end times are required');
    }
    if (!in_array($type, overtime_types(), true)) throw new Exception('Invalid overtime type');
    if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

    // Not in the future beyond today (UTC date).
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    if ($workDate > $today) throw new Exception('Overtime cannot be logged for a future date');

    $hours = overtime_hours_between($start, $end);
    if ($hours <= 0) throw new Exception('End time must be after start time');

    $settings = overtime_settings($conn);
    $maxDaily = (float)($settings['ot_max_daily_hours'] ?? 16);
    if ($hours > $maxDaily) throw new Exception("Overtime exceeds the daily maximum of {$maxDaily}h");

    // No overlap with an existing active (pending/approved) entry that day.
    $ov = $conn->prepare(
        "SELECT COUNT(*) FROM overtime_requests
          WHERE analyst_id = ? AND work_date = ? AND status IN ('pending','approved')
            AND NOT (end_time <= ? OR start_time >= ?)"
    );
    $ov->execute([$analystId, $workDate, $start, $end]);
    if ((int)$ov->fetchColumn() > 0) throw new Exception('This overlaps an existing overtime entry for that day');

    $multiplier = overtime_multiplier_for($settings, $type);
    $approvalRequired = (string)($settings['ot_approval_required'] ?? '1') === '1';
    $status = $approvalRequired ? 'pending' : 'approved';
    $tenantId = getActiveTenantId($conn, $analystId);

    $conn->prepare(
        "INSERT INTO overtime_requests
            (analyst_id, tenant_id, work_date, start_time, end_time, hours, overtime_type,
             rate_multiplier, reason, status, submitted_datetime, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    )->execute([$analystId, $tenantId, $workDate, $start, $end, $hours, $type, $multiplier, $reason ?: null, $status]);
    $id = (int)$conn->lastInsertId();

    overtime_audit($conn, $id, $analystId, 'submitted', null, $status, $reason ?: null);
    if ($status === 'approved') {
        overtime_audit($conn, $id, null, 'auto_approved', null, null, 'Approval not required by settings');
    }

    echo json_encode(['success' => true, 'id' => $id, 'status' => $status, 'hours' => $hours]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
