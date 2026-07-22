<?php
/**
 * API: Unlink an asset from a contract (Phase 9d).
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
    $conn->prepare("DELETE FROM contract_assets WHERE contract_id = ? AND asset_id = ?")
         ->execute([$contractId, $assetId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
