<?php
/**
 * API: Delete an SLA policy.
 *
 * Guarded: the default policy can never be deleted (the engine falls back to it),
 * and a policy still assigned to a company must be reassigned first — otherwise
 * those companies would silently drop onto the default tier.
 * Its targets cascade away with it (fk_sla_policy_targets_policy).
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
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    $cur = $conn->prepare("SELECT name, is_default FROM sla_policies WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('SLA policy not found');
    if ((int)$row['is_default'] === 1) {
        throw new Exception("The default policy can't be deleted — make another policy the default first.");
    }

    $g = $conn->prepare("SELECT COUNT(*) FROM tenant_sla_policies WHERE policy_id = ?");
    $g->execute([$id]);
    $assigned = (int)$g->fetchColumn();
    if ($assigned > 0) {
        throw new Exception("$assigned company(ies) are on this policy — move them to another policy first.");
    }

    $conn->prepare("DELETE FROM sla_policies WHERE id = ?")->execute([$id]);

    wf_emit('sla_policy', 'deleted', $id, $row['name']);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
