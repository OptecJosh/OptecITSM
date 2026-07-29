<?php
/**
 * API: Reverse view (Phase 15b) — which customers a CMDB object belongs to.
 * GET ?cmdb_object_id=<id>. Returns { success, customers:[{id, name,
 * account_ref, is_active, company_name}] }.
 *
 * Powers the "Customers" panel on the CMDB object page. Many-to-many on purpose:
 * shared infrastructure (a hypervisor hosting three clients) genuinely belongs
 * to more than one account.
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
    $objectId = isset($_GET['cmdb_object_id']) ? (int)$_GET['cmdb_object_id'] : 0;
    if ($objectId <= 0) throw new Exception('cmdb_object_id is required');

    // Customers are not company-scoped (shared by design), so no tenant filter —
    // but the owning company is returned so the panel can show it.
    $stmt = $conn->prepare(
        "SELECT c.id, c.name, c.account_ref, c.is_active, tn.name AS company_name
           FROM customer_cmdb_objects cco
           JOIN customers c ON c.id = cco.customer_id
      LEFT JOIN tenants tn ON tn.id = c.tenant_id
          WHERE cco.cmdb_object_id = ?
       ORDER BY c.name ASC"
    );
    $stmt->execute([$objectId]);

    $customers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $customers[] = [
            'id'           => (int)$r['id'],
            'name'         => $r['name'],
            'account_ref'  => $r['account_ref'],
            'is_active'    => (int)$r['is_active'] === 1,
            'company_name' => $r['company_name'],
        ];
    }

    echo json_encode(['success' => true, 'customers' => $customers]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
