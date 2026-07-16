<?php
/**
 * API: Delete a ticket custom-field definition.
 * Refuses if any tickets have a value set for it — clear those first
 * (mirrors api/cmdb/delete_class_property.php's in-use guard).
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
requireCapabilityJson(Cap::TICKETS_CUSTOM_FIELDS);

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    $cnt = $conn->prepare("SELECT COUNT(*) FROM custom_field_values WHERE entity_type = 'ticket' AND field_id = ?");
    $cnt->execute([$id]);
    $valueCount = (int)$cnt->fetchColumn();
    if ($valueCount > 0) {
        throw new Exception("Cannot delete: $valueCount ticket(s) have a value set for this field. Clear those values first.");
    }

    // Only global ticket fields are managed here.
    $name = $conn->query("SELECT label FROM custom_field_definitions WHERE id = " . (int)$id . " AND entity_type = 'ticket'")->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM custom_field_definitions WHERE id = ? AND entity_type = 'ticket'");
    $stmt->execute([$id]);

    wf_emit('ticket_custom_field', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
