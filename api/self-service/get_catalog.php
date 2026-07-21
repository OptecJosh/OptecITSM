<?php
/**
 * API (self-service): active service-catalog items for the portal (Phase 7c).
 * GET → { items: [{ id, name, description, icon, category, has_form }] }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $rows = $conn->query(
        "SELECT sci.id, sci.name, sci.description, sci.icon, sci.form_id, tc.name AS category
           FROM service_catalog_items sci
      LEFT JOIN ticket_categories tc ON tc.id = sci.category_id
          WHERE sci.is_active = 1
       ORDER BY sci.display_order, sci.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'description' => $r['description'],
            'icon'        => $r['icon'],
            'category'    => $r['category'] ?: 'General',
            'has_form'    => $r['form_id'] !== null,
        ];
    }
    echo json_encode(['success' => true, 'items' => $items]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
