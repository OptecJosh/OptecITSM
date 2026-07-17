<?php
/**
 * API: Get a CMDB object's SLA policy assignment plus the list of active
 * policies to choose from — one call drives the "SLA policy" selector on the
 * CMDB object page.
 *
 * GET ?object_id=N
 * Returns {
 *   assigned_policy_id: ?int,
 *   policies: [{ id, name, is_default }],
 *   default_policy_name: ?string       // shown as the "(inherits company / Default)" hint
 * }
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
    $objectId = isset($_GET['object_id']) ? (int)$_GET['object_id'] : 0;
    if ($objectId <= 0) throw new Exception('object_id is required');

    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT policy_id FROM cmdb_object_sla_policies WHERE object_id = ?");
    $stmt->execute([$objectId]);
    $assigned = $stmt->fetchColumn();

    $policies = $conn->query(
        "SELECT id, name, is_default FROM sla_policies WHERE is_active = 1 ORDER BY is_default DESC, name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $defaultName = null;
    foreach ($policies as &$p) {
        $p['id']         = (int)$p['id'];
        $p['is_default'] = (int)$p['is_default'] === 1;
        if ($p['is_default']) $defaultName = $p['name'];
    }
    unset($p);

    echo json_encode([
        'success'             => true,
        'assigned_policy_id'  => $assigned !== false ? (int)$assigned : null,
        'policies'            => $policies,
        'default_policy_name' => $defaultName,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
