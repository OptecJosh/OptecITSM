<?php
/**
 * API: List customers (with optional ?q= search on name/contact). Also serves
 * the ticket customer picker. Returns id, name, account_ref, contact_*, company,
 * ci_count, is_active.
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
    $q = trim((string)($_GET['q'] ?? ''));
    $activeOnly = isset($_GET['active']) && $_GET['active'] === '1';

    $where = ' WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (c.name LIKE ? OR c.contact_name LIKE ? OR c.contact_email LIKE ? OR c.account_ref LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($activeOnly) $where .= ' AND c.is_active = 1';

    $sql = "SELECT c.id, c.name, c.account_ref, c.contact_name, c.contact_email, c.contact_phone,
                   c.tenant_id, c.is_active, tn.name AS company_name,
                   (SELECT COUNT(*) FROM customer_cmdb_objects cco WHERE cco.customer_id = c.id) AS ci_count
              FROM customers c
         LEFT JOIN tenants tn ON tn.id = c.tenant_id
              $where
          ORDER BY c.name ASC
             LIMIT 500";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'account_ref'   => $r['account_ref'],
            'contact_name'  => $r['contact_name'],
            'contact_email' => $r['contact_email'],
            'contact_phone' => $r['contact_phone'],
            'tenant_id'     => $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null,
            'company_name'  => $r['company_name'],
            'is_active'     => (int)$r['is_active'] === 1,
            'ci_count'      => (int)$r['ci_count'],
        ];
    }
    echo json_encode(['success' => true, 'customers' => $rows]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
