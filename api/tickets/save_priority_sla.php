<?php
/**
 * API: Save a priority's SLA targets under the DEFAULT policy.
 *
 * POST JSON: { id, sla_response_minutes, sla_resolution_minutes, sla_calendar_id }
 *
 * BACK-COMPAT SHIM. SLA targets are per-policy now (sla_policy_targets); this
 * endpoint's original contract is preserved, and it writes the default policy's
 * target — the same thing it effectively did when there was only one SLA. Use
 * save_sla_policy_target.php to target a specific policy.
 *
 * Pass nulls for minutes to clear targets; pass null sla_calendar_id to detach
 * the calendar.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_SLA);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if (!$id) throw new Exception('Priority id required');

    $response   = isset($data['sla_response_minutes'])   && $data['sla_response_minutes']   !== '' ? max(0, (int)$data['sla_response_minutes'])   : null;
    $resolution = isset($data['sla_resolution_minutes']) && $data['sla_resolution_minutes'] !== '' ? max(0, (int)$data['sla_resolution_minutes']) : null;
    $calendarId = isset($data['sla_calendar_id'])        && $data['sla_calendar_id']        !== '' ? (int)$data['sla_calendar_id']                : null;

    $conn = connectToDatabase();

    // Validate calendar exists if one was passed (FK will catch it too but a friendly error reads better)
    if ($calendarId !== null) {
        $check = $conn->prepare("SELECT COUNT(*) FROM sla_calendars WHERE id = ? AND is_active = 1");
        $check->execute([$calendarId]);
        if ((int)$check->fetchColumn() === 0) throw new Exception('Calendar not found');
    }

    // Targets live on the default policy now, not on the priority row.
    $policyId = $conn->query("SELECT id FROM sla_policies WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($policyId === false) throw new Exception('No default SLA policy exists');

    if ($response === null && $resolution === null && $calendarId === null) {
        $conn->prepare("DELETE FROM sla_policy_targets WHERE policy_id = ? AND priority_id = ?")
             ->execute([(int)$policyId, $id]);
    } else {
        $conn->prepare(
            "INSERT INTO sla_policy_targets (policy_id, priority_id, sla_response_minutes, sla_resolution_minutes, sla_calendar_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sla_response_minutes = VALUES(sla_response_minutes),
                                     sla_resolution_minutes = VALUES(sla_resolution_minutes),
                                     sla_calendar_id = VALUES(sla_calendar_id)"
        )->execute([(int)$policyId, $id, $response, $resolution, $calendarId]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
