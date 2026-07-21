<?php
/**
 * API (admin/analyst): all service-catalog items + the lookups needed to edit
 * them (Phase 7c). GET → { items:[...], categories:[...], departments:[...], priorities:[...] }
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

    $items = $conn->query(
        "SELECT sci.id, sci.name, sci.description, sci.category_id, sci.department_id, sci.priority_id,
                sci.icon, sci.is_active, sci.display_order,
                tc.name AS category_name, d.name AS department_name, tp.name AS priority_name
           FROM service_catalog_items sci
      LEFT JOIN ticket_categories tc ON tc.id = sci.category_id
      LEFT JOIN departments d ON d.id = sci.department_id
      LEFT JOIN ticket_priorities tp ON tp.id = sci.priority_id
       ORDER BY sci.display_order, sci.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$it) {
        $it['id']            = (int)$it['id'];
        $it['category_id']   = $it['category_id']   !== null ? (int)$it['category_id']   : null;
        $it['department_id'] = $it['department_id'] !== null ? (int)$it['department_id'] : null;
        $it['priority_id']   = $it['priority_id']   !== null ? (int)$it['priority_id']   : null;
        $it['is_active']     = (int)$it['is_active'] === 1;
    }
    unset($it);

    $categories = $conn->query("SELECT id, name FROM ticket_categories WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $conn->query("SELECT id, name FROM departments ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    $priorities = $conn->query("SELECT id, name FROM ticket_priorities WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'items' => $items, 'categories' => $categories, 'departments' => $departments, 'priorities' => $priorities]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
