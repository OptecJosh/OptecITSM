<?php
/**
 * API: List departments with their auto-assign strategy (Phase 6f).
 * GET → { departments: [{ id, name, strategy }] }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $rows = $conn->query(
        "SELECT d.id, d.name, COALESCE(c.strategy, 'off') AS strategy
           FROM departments d
      LEFT JOIN department_assignment_config c ON c.department_id = d.id
       ORDER BY d.display_order ASC, d.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'strategy' => $r['strategy'] ?: 'off'];
    }
    echo json_encode(['success' => true, 'departments' => $out]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
