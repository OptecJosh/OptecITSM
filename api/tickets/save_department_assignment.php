<?php
/**
 * API: Set a department's auto-assign strategy (Phase 6f).
 * POST JSON { department_id, strategy } — strategy ∈ off | round_robin | least_loaded.
 * Upsert; the round-robin cursor (last_assigned_analyst_id) is preserved on update.
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
requireCapabilityJson(Cap::TICKETS_DEPARTMENTS);

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $deptId = isset($data['department_id']) ? (int)$data['department_id'] : 0;
    $strategy = (string)($data['strategy'] ?? '');

    if ($deptId <= 0) throw new Exception('department_id is required');
    if (!in_array($strategy, ['off', 'round_robin', 'least_loaded'], true)) throw new Exception('Invalid strategy');

    $chk = $conn->prepare("SELECT 1 FROM departments WHERE id = ?");
    $chk->execute([$deptId]);
    if (!$chk->fetchColumn()) throw new Exception('Department not found');

    $conn->prepare(
        "INSERT INTO department_assignment_config (department_id, strategy, updated_datetime)
         VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE strategy = VALUES(strategy), updated_datetime = UTC_TIMESTAMP()"
    )->execute([$deptId, $strategy]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
