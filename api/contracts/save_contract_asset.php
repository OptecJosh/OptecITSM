<?php
/**
 * API: Link an asset to a contract (Phase 9d). Idempotent (unique key).
 * POST JSON { contract_id, asset_id }.
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
    $assetId    = isset($data['asset_id']) ? (int)$data['asset_id'] : 0;
    if ($contractId <= 0 || $assetId <= 0) {
        throw new Exception('contract_id and asset_id are required');
    }

    $conn = connectToDatabase();

    $check = $conn->prepare("SELECT 1 FROM contracts WHERE id = ?");
    $check->execute([$contractId]);
    if (!$check->fetchColumn()) throw new Exception('Contract not found');

    $check = $conn->prepare("SELECT 1 FROM assets WHERE id = ?");
    $check->execute([$assetId]);
    if (!$check->fetchColumn()) throw new Exception('Asset not found');

    try {
        $ins = $conn->prepare(
            "INSERT INTO contract_assets (contract_id, asset_id, created_datetime, created_by_analyst_id)
             VALUES (?, ?, UTC_TIMESTAMP(), ?)"
        );
        $ins->execute([$contractId, $assetId, (int)$_SESSION['analyst_id']]);
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
