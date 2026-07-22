<?php
/**
 * API: Reverse coverage view (Phase 9d) — contracts that cover an asset.
 * GET ?asset_id=<id>. Returns { success, contracts:[{id, contract_number, title,
 * contract_end, is_active}] }. Powers an asset's "covered by contract X" panel
 * (and is available to the REST/API surface).
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
    $conn = connectToDatabase();
    $assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
    if ($assetId <= 0) throw new Exception('asset_id is required');

    $stmt = $conn->prepare(
        "SELECT c.id, c.contract_number, c.title,
                DATE_FORMAT(c.contract_end, '%Y-%m-%d') AS contract_end, c.is_active
           FROM contract_assets ca
           JOIN contracts c ON c.id = ca.contract_id
          WHERE ca.asset_id = ?
       ORDER BY c.contract_end IS NULL, c.contract_end ASC"
    );
    $stmt->execute([$assetId]);

    $contracts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $contracts[] = [
            'id'              => (int)$r['id'],
            'contract_number' => $r['contract_number'],
            'title'           => $r['title'],
            'contract_end'    => $r['contract_end'],
            'is_active'       => (int)$r['is_active'] === 1,
        ];
    }

    echo json_encode(['success' => true, 'contracts' => $contracts]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
