<?php
/**
 * API: Delete a property definition.
 * Refuses if any objects have a value set for this property — analyst must clear values first.
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

requireModuleAccessJson('cmdb');
requireCapabilityJson(Cap::CMDB_CLASSES);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    // Definitions + values now live in the generalized custom_field_* tables.
    $cnt = $conn->prepare("SELECT COUNT(*) FROM custom_field_values WHERE entity_type = 'cmdb_object' AND field_id = ?");
    $cnt->execute([$id]);
    $valueCount = (int)$cnt->fetchColumn();
    if ($valueCount > 0) {
        throw new Exception("Cannot delete: $valueCount object(s) have a value set for this property. Clear those values first.");
    }

    $name = $conn->query("SELECT label FROM custom_field_definitions WHERE id = " . (int)$id . " AND entity_type = 'cmdb_object'")->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM custom_field_definitions WHERE id = ? AND entity_type = 'cmdb_object'");
    $stmt->execute([$id]);

    wf_emit('cmdb_property', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
