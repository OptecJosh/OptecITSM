<?php
/**
 * API: Unlink a configuration item from a contract (Phase 15a).
 * POST JSON { contract_id, cmdb_object_id }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $contractId = isset($data['contract_id']) ? (int)$data['contract_id'] : 0;
    $objectId   = isset($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;
    if ($contractId <= 0 || $objectId <= 0) {
        throw new Exception('contract_id and cmdb_object_id are required');
    }

    $conn = connectToDatabase();
    $conn->prepare("DELETE FROM contract_cmdb_objects WHERE contract_id = ? AND cmdb_object_id = ?")
         ->execute([$contractId, $objectId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
