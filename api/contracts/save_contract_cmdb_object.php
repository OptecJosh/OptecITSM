<?php
/**
 * API: Link a configuration item to a contract (Phase 15a).
 * POST JSON { contract_id, cmdb_object_id }. Idempotent — a repeat link is
 * reported as success with already_linked, matching save_contract_asset.php, so
 * double-clicking the picker is quiet rather than an error.
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

    // Verify both rows exist first — a clearer error than an FK failure.
    $check = $conn->prepare("SELECT 1 FROM contracts WHERE id = ?");
    $check->execute([$contractId]);
    if (!$check->fetchColumn()) throw new Exception('Contract not found');

    $check = $conn->prepare("SELECT 1 FROM cmdb_objects WHERE id = ?");
    $check->execute([$objectId]);
    if (!$check->fetchColumn()) throw new Exception('CMDB object not found');

    try {
        $ins = $conn->prepare(
            "INSERT INTO contract_cmdb_objects (contract_id, cmdb_object_id, created_datetime, created_by_analyst_id)
             VALUES (?, ?, UTC_TIMESTAMP(), ?)"
        );
        $ins->execute([$contractId, $objectId, (int)$_SESSION['analyst_id']]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'already_linked' => false]);
    } catch (PDOException $pe) {
        if ($pe->errorInfo[1] == 1062) {
            echo json_encode(['success' => true, 'already_linked' => true]);
            exit;
        }
        throw $pe;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
