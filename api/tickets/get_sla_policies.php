<?php
/**
 * API: List SLA policies, each with its per-priority targets and the companies
 * assigned to it. Drives the Policies section of the SLA settings tab.
 *
 * Returns:
 *   policies:    [{ id, name, description, is_default, is_active,
 *                   targets: { priority_id: {sla_response_minutes, sla_resolution_minutes, sla_calendar_id} },
 *                   tenant_count }]
 *   assignments: [{ tenant_id, tenant_name, policy_id, effective_from }]   (multi-company only)
 *   multi_tenant: bool
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();

    $policies = $conn->query("SELECT id, name, description, is_default, is_active FROM sla_policies ORDER BY is_default DESC, name")
                     ->fetchAll(PDO::FETCH_ASSOC);

    // Targets per policy, keyed by priority id.
    $targetsByPolicy = [];
    foreach ($conn->query("SELECT policy_id, priority_id, sla_response_minutes, sla_resolution_minutes, sla_calendar_id FROM sla_policy_targets")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $targetsByPolicy[(int)$t['policy_id']][(int)$t['priority_id']] = [
            'sla_response_minutes'   => $t['sla_response_minutes'] !== null ? (int)$t['sla_response_minutes'] : null,
            'sla_resolution_minutes' => $t['sla_resolution_minutes'] !== null ? (int)$t['sla_resolution_minutes'] : null,
            'sla_calendar_id'        => $t['sla_calendar_id'] !== null ? (int)$t['sla_calendar_id'] : null,
        ];
    }

    // Company assignments (only meaningful once a second company exists).
    $multi = isMultiTenant($conn);
    $assignments = [];
    $countByPolicy = [];
    if ($multi) {
        $rows = $conn->query(
            "SELECT tsp.tenant_id, tsp.policy_id, tsp.effective_from, tn.name AS tenant_name
               FROM tenant_sla_policies tsp
               JOIN tenants tn ON tn.id = tsp.tenant_id
           ORDER BY tn.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $assignments[] = [
                'tenant_id'      => (int)$r['tenant_id'],
                'tenant_name'    => $r['tenant_name'],
                'policy_id'      => (int)$r['policy_id'],
                'effective_from' => $r['effective_from'],
            ];
            $countByPolicy[(int)$r['policy_id']] = ($countByPolicy[(int)$r['policy_id']] ?? 0) + 1;
        }
    }

    foreach ($policies as &$p) {
        $p['id']           = (int)$p['id'];
        $p['is_default']   = (int)$p['is_default'] === 1;
        $p['is_active']    = (int)$p['is_active'] === 1;
        $p['targets']      = $targetsByPolicy[$p['id']] ?? [];
        $p['tenant_count'] = $countByPolicy[$p['id']] ?? 0;
    }
    unset($p);

    echo json_encode([
        'success'      => true,
        'policies'     => $policies,
        'assignments'  => $assignments,
        'multi_tenant' => $multi,
        'companies'    => $multi ? getAllTenants($conn, true) : [],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
