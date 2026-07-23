<?php
/**
 * API: One customer with its full detail + linked CMDB objects (CIs).
 * GET ?id=<id>.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('customers');

try {
    $conn = connectToDatabase();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception('Customer not found');

    $ci = $conn->prepare(
        "SELECT o.id AS object_id, o.name, cl.name AS class_name
           FROM customer_cmdb_objects cco
           JOIN cmdb_objects o ON o.id = cco.cmdb_object_id
      LEFT JOIN cmdb_classes cl ON cl.id = o.class_id
          WHERE cco.customer_id = ?
       ORDER BY o.name ASC");
    $ci->execute([$id]);
    $cis = array_map(fn($r) => ['object_id' => (int)$r['object_id'], 'name' => $r['name'], 'class_name' => $r['class_name']], $ci->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success'  => true,
        'customer' => [
            'id'            => (int)$c['id'],
            'name'          => $c['name'],
            'account_ref'   => $c['account_ref'],
            'contact_name'  => $c['contact_name'],
            'contact_email' => $c['contact_email'],
            'contact_phone' => $c['contact_phone'],
            'tenant_id'     => $c['tenant_id'] !== null ? (int)$c['tenant_id'] : null,
            'notes'         => $c['notes'],
            'is_active'     => (int)$c['is_active'] === 1,
        ],
        'cis' => $cis,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
