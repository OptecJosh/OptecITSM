<?php
/**
 * API (self-service): the form attached to a catalog item, if any (Phase 7c-2).
 * GET ?catalog_item_id=N → { success, form: null | { id, title, description,
 * fields:[{ id, field_type, label, options, is_required }] } }
 *
 * Only returns a form when the catalog item is active, has a form attached, and
 * that form is itself active — so the portal never renders a retired form.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$catalogItemId = (int)($_GET['catalog_item_id'] ?? 0);
if ($catalogItemId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing catalog_item_id']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT f.id, f.title, f.description
           FROM service_catalog_items sci
           JOIN forms f ON f.id = sci.form_id
          WHERE sci.id = ? AND sci.is_active = 1 AND f.is_active = 1"
    );
    $stmt->execute([$catalogItemId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        echo json_encode(['success' => true, 'form' => null]);
        exit;
    }

    $form['id'] = (int)$form['id'];
    $fstmt = $conn->prepare(
        "SELECT id, field_type, label, options, is_required
           FROM form_fields WHERE form_id = ? ORDER BY sort_order, id"
    );
    $fstmt->execute([$form['id']]);
    $fields = [];
    foreach ($fstmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $fields[] = [
            'id'          => (int)$f['id'],
            'field_type'  => $f['field_type'],
            'label'       => $f['label'],
            'options'     => $f['options'],   // JSON string or null (client parses)
            'is_required' => (int)$f['is_required'] === 1,
        ];
    }
    $form['fields'] = $fields;

    echo json_encode(['success' => true, 'form' => $form]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
