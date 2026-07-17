<?php
/**
 * API: Assign a company to an SLA policy (or clear the assignment so it falls
 * back to the default policy).
 *
 * POST JSON { tenant_id, policy_id (null/'' = clear), effective_from? (YYYY-MM-DD) }
 *
 * One row per company (uq_tenant_sla_policies_tenant), so this is an upsert.
 * effective_from is optional: until that date the company still resolves to the
 * default policy — that's how a staged tier change (e.g. a contract renewal) is
 * represented.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_SLA);

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $tenantId = isset($data['tenant_id']) ? (int)$data['tenant_id'] : 0;
    if ($tenantId <= 0) throw new Exception('tenant_id is required');

    $policyId = (isset($data['policy_id']) && $data['policy_id'] !== '' && $data['policy_id'] !== null)
        ? (int)$data['policy_id'] : null;
    $effectiveFrom = (isset($data['effective_from']) && $data['effective_from'] !== '') ? trim((string)$data['effective_from']) : null;
    if ($effectiveFrom !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
        throw new Exception('effective_from must be a date (YYYY-MM-DD)');
    }

    $conn = connectToDatabase();

    if (!getTenantById($conn, $tenantId)) throw new Exception('Company not found');

    if ($policyId === null) {
        // Clear → the company falls back to the default policy.
        $conn->prepare("DELETE FROM tenant_sla_policies WHERE tenant_id = ?")->execute([$tenantId]);
        echo json_encode(['success' => true, 'cleared' => true]);
        exit;
    }

    $p = $conn->prepare("SELECT COUNT(*) FROM sla_policies WHERE id = ? AND is_active = 1");
    $p->execute([$policyId]);
    if ((int)$p->fetchColumn() === 0) throw new Exception('SLA policy not found or inactive');

    $conn->prepare(
        "INSERT INTO tenant_sla_policies (tenant_id, policy_id, effective_from, created_datetime)
         VALUES (?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE policy_id = VALUES(policy_id), effective_from = VALUES(effective_from)"
    )->execute([$tenantId, $policyId, $effectiveFrom]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
