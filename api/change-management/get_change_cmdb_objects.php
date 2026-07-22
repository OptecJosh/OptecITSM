<?php
/**
 * API: List CMDB objects (affected CIs) linked to a change (Phase 9c).
 * GET ?change_id=<id>. Returns { success, objects:[{id, object_id, name, class_name}] }.
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
    $conn = connectToDatabase();
    $changeId = isset($_GET['change_id']) ? (int)$_GET['change_id'] : 0;
    if ($changeId <= 0) throw new Exception('change_id is required');

    $stmt = $conn->prepare(
        "SELECT co.id AS link_id, o.id AS object_id, o.name, c.name AS class_name
           FROM change_cmdb_objects co
           JOIN cmdb_objects o ON o.id = co.cmdb_object_id
      LEFT JOIN cmdb_classes c ON c.id = o.class_id
          WHERE co.change_id = ?
       ORDER BY o.name ASC"
    );
    $stmt->execute([$changeId]);

    $objects = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $objects[] = [
            'link_id'    => (int)$r['link_id'],
            'object_id'  => (int)$r['object_id'],
            'name'       => $r['name'],
            'class_name' => $r['class_name'],
        ];
    }

    echo json_encode(['success' => true, 'objects' => $objects]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
