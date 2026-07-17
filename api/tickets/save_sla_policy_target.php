<?php
/**
 * API: Upsert one policy+priority SLA target.
 *
 * POST JSON { policy_id, priority_id, sla_response_minutes, sla_resolution_minutes, sla_calendar_id }
 *
 * This is the canonical write for SLA targets now that they're per-policy
 * (save_priority_sla.php remains as a back-compat shim that writes the default
 * policy's target). Pass null/'' minutes to clear a target; a row with no
 * targets at all is deleted, which is how "this priority has no SLA under this
 * policy" is represented.
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
requireCapabilityJson(Cap::TICKETS_SLA);

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $policyId   = isset($data['policy_id']) ? (int)$data['policy_id'] : 0;
    $priorityId = isset($data['priority_id']) ? (int)$data['priority_id'] : 0;
    if ($policyId <= 0)   throw new Exception('policy_id is required');
    if ($priorityId <= 0) throw new Exception('priority_id is required');

    $response   = isset($data['sla_response_minutes'])   && $data['sla_response_minutes']   !== '' && $data['sla_response_minutes']   !== null ? max(0, (int)$data['sla_response_minutes'])   : null;
    $resolution = isset($data['sla_resolution_minutes']) && $data['sla_resolution_minutes'] !== '' && $data['sla_resolution_minutes'] !== null ? max(0, (int)$data['sla_resolution_minutes']) : null;
    $calendarId = isset($data['sla_calendar_id'])        && $data['sla_calendar_id']        !== '' && $data['sla_calendar_id']        !== null ? (int)$data['sla_calendar_id']                : null;

    $conn = connectToDatabase();

    $p = $conn->prepare("SELECT COUNT(*) FROM sla_policies WHERE id = ?");
    $p->execute([$policyId]);
    if ((int)$p->fetchColumn() === 0) throw new Exception('SLA policy not found');

    if ($calendarId !== null) {
        $check = $conn->prepare("SELECT COUNT(*) FROM sla_calendars WHERE id = ? AND is_active = 1");
        $check->execute([$calendarId]);
        if ((int)$check->fetchColumn() === 0) throw new Exception('Calendar not found');
    }

    if ($response === null && $resolution === null && $calendarId === null) {
        // Nothing set — remove the row entirely (= no SLA for this priority here).
        $conn->prepare("DELETE FROM sla_policy_targets WHERE policy_id = ? AND priority_id = ?")
             ->execute([$policyId, $priorityId]);
    } else {
        $conn->prepare(
            "INSERT INTO sla_policy_targets (policy_id, priority_id, sla_response_minutes, sla_resolution_minutes, sla_calendar_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sla_response_minutes = VALUES(sla_response_minutes),
                                     sla_resolution_minutes = VALUES(sla_resolution_minutes),
                                     sla_calendar_id = VALUES(sla_calendar_id)"
        )->execute([$policyId, $priorityId, $response, $resolution, $calendarId]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
