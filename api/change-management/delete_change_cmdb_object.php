<?php
/**
 * API: Unlink a CMDB object from a change (Phase 9c).
 * POST JSON { change_id, cmdb_object_id }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $changeId = isset($data['change_id']) ? (int)$data['change_id'] : 0;
    $objectId = isset($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;
    if ($changeId <= 0 || $objectId <= 0) {
        throw new Exception('change_id and cmdb_object_id are required');
    }

    $conn = connectToDatabase();
    $conn->prepare("DELETE FROM change_cmdb_objects WHERE change_id = ? AND cmdb_object_id = ?")
         ->execute([$changeId, $objectId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
