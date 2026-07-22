<?php
/**
 * API: List assets covered by a contract (Phase 9d).
 * GET ?contract_id=<id>. Returns { success, assets:[{asset_id, hostname, model,
 * manufacturer, status}] }.
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
    $contractId = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0;
    if ($contractId <= 0) throw new Exception('contract_id is required');

    $stmt = $conn->prepare(
        "SELECT ca.id AS link_id, a.id AS asset_id, a.hostname, a.manufacturer, a.model,
                ast.name AS status
           FROM contract_assets ca
           JOIN assets a ON a.id = ca.asset_id
      LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
          WHERE ca.contract_id = ?
       ORDER BY a.hostname ASC"
    );
    $stmt->execute([$contractId]);

    $assets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $assets[] = [
            'link_id'      => (int)$r['link_id'],
            'asset_id'     => (int)$r['asset_id'],
            'hostname'     => $r['hostname'],
            'manufacturer' => $r['manufacturer'],
            'model'        => $r['model'],
            'status'       => $r['status'],
        ];
    }

    echo json_encode(['success' => true, 'assets' => $assets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
