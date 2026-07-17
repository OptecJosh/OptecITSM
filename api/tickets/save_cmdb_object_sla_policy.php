<?php
/**
 * API: Assign a CMDB object (device / configuration item) to an SLA policy, or
 * clear the assignment so the device carries no SLA of its own.
 *
 * POST JSON { object_id, policy_id (null/'' = clear) }
 *
 * One row per object (uq_cmdb_object_sla_policies_object) → this is an upsert.
 * When a ticket's PRIMARY affected CI has an assignment here, the ticket adopts
 * this policy, overriding the company's tier (device → company → default; see
 * includes/sla.php sla_resolve_ticket_policy).
 *
 * Gated on CMDB module access to match the other CMDB write endpoints — the SLA
 * tier is an attribute of the configuration item, set where the CI is edited.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('cmdb');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $objectId = isset($data['object_id']) ? (int)$data['object_id'] : 0;
    if ($objectId <= 0) throw new Exception('object_id is required');

    $policyId = (isset($data['policy_id']) && $data['policy_id'] !== '' && $data['policy_id'] !== null)
        ? (int)$data['policy_id'] : null;

    $conn = connectToDatabase();

    $chk = $conn->prepare("SELECT 1 FROM cmdb_objects WHERE id = ?");
    $chk->execute([$objectId]);
    if (!$chk->fetchColumn()) throw new Exception('CMDB object not found');

    if ($policyId === null) {
        // Clear → the device carries no SLA; its tickets fall back to the company.
        $conn->prepare("DELETE FROM cmdb_object_sla_policies WHERE object_id = ?")->execute([$objectId]);
        echo json_encode(['success' => true, 'cleared' => true]);
        exit;
    }

    $p = $conn->prepare("SELECT COUNT(*) FROM sla_policies WHERE id = ? AND is_active = 1");
    $p->execute([$policyId]);
    if ((int)$p->fetchColumn() === 0) throw new Exception('SLA policy not found or inactive');

    $conn->prepare(
        "INSERT INTO cmdb_object_sla_policies (object_id, policy_id, created_datetime)
         VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE policy_id = VALUES(policy_id)"
    )->execute([$objectId, $policyId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
