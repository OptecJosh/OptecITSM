<?php
/**
 * API: List configuration items covered by a contract (Phase 15a).
 * GET ?contract_id=<id>. Returns { success, objects:[{link_id, object_id, name,
 * class_name, parent_name, is_planned}] }.
 *
 * No tenant filter: neither contracts nor CMDB objects are company-scoped (they
 * are shared by design — see docs/system-overview.md §4), so adding one here
 * would hide rows that legitimately belong to every company.
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
        "SELECT cco.id AS link_id, o.id AS object_id, o.name, o.is_planned,
                c.name AS class_name, p.name AS parent_name
           FROM contract_cmdb_objects cco
           JOIN cmdb_objects o  ON o.id = cco.cmdb_object_id
           JOIN cmdb_classes c  ON c.id = o.class_id
      LEFT JOIN cmdb_objects p  ON p.id = o.parent_id
          WHERE cco.contract_id = ?
       ORDER BY c.name ASC, o.name ASC"
    );
    $stmt->execute([$contractId]);

    $objects = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $objects[] = [
            'link_id'     => (int)$r['link_id'],
            'object_id'   => (int)$r['object_id'],
            'name'        => $r['name'],
            'class_name'  => $r['class_name'],
            'parent_name' => $r['parent_name'],
            'is_planned'  => (int)$r['is_planned'] === 1,
        ];
    }

    echo json_encode(['success' => true, 'objects' => $objects]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
