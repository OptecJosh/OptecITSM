<?php
/**
 * API: Reverse coverage view (Phase 15a) — contracts that cover a CMDB object.
 * GET ?cmdb_object_id=<id>. Returns { success, contracts:[{id, contract_number,
 * title, contract_end, is_active, supplier_name, service_hours, response_sla,
 * resolution_sla, coverage_notes}] }.
 *
 * Lives under api/contracts/ rather than api/cmdb/ to sit beside its sibling
 * get_asset_contracts.php — both are the same question asked of a different
 * entity, and both gate on the contracts module.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// An analyst without the contracts module gets a 403-shaped refusal here and the
// CMDB object page simply omits the panel — contract terms are commercial data.
requireModuleAccessJson('contracts');

try {
    $conn = connectToDatabase();
    $objectId = isset($_GET['cmdb_object_id']) ? (int)$_GET['cmdb_object_id'] : 0;
    if ($objectId <= 0) throw new Exception('cmdb_object_id is required');

    $stmt = $conn->prepare(
        "SELECT c.id, c.contract_number, c.title,
                DATE_FORMAT(c.contract_end, '%Y-%m-%d') AS contract_end, c.is_active,
                c.service_hours, c.response_sla, c.resolution_sla, c.coverage_notes,
                s.legal_name AS supplier_name
           FROM contract_cmdb_objects cco
           JOIN contracts c ON c.id = cco.contract_id
      LEFT JOIN suppliers s ON s.id = c.supplier_id
          WHERE cco.cmdb_object_id = ?
       ORDER BY c.contract_end IS NULL, c.contract_end ASC"
    );
    $stmt->execute([$objectId]);

    $contracts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $contracts[] = [
            'id'              => (int)$r['id'],
            'contract_number' => $r['contract_number'],
            'title'           => $r['title'],
            'contract_end'    => $r['contract_end'],
            'is_active'       => (int)$r['is_active'] === 1,
            'supplier_name'   => $r['supplier_name'],
            'service_hours'   => $r['service_hours'],
            'response_sla'    => $r['response_sla'],
            'resolution_sla'  => $r['resolution_sla'],
            'coverage_notes'  => $r['coverage_notes'],
        ];
    }

    echo json_encode(['success' => true, 'contracts' => $contracts]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
