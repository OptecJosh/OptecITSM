<?php
/**
 * API Endpoint: Get all analysts
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, username, full_name, email, is_active, auth_provider_id, can_access_all_tenants, created_datetime, last_login_datetime, last_modified_datetime
            FROM analysts
            ORDER BY full_name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $analysts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Multi-tenancy: which companies each analyst is granted (only consulted when
    // they're NOT all-access). Degrades to empty if the table isn't there yet.
    $grantsByAnalyst = [];
    try {
        foreach ($conn->query("SELECT analyst_id, tenant_id FROM analyst_tenant_access") as $row) {
            $grantsByAnalyst[(int)$row['analyst_id']][] = (int)$row['tenant_id'];
        }
    } catch (Exception $e) {
        $grantsByAnalyst = [];
    }

    // Convert fields to proper types
    foreach ($analysts as &$analyst) {
        $analyst['id'] = (int)$analyst['id'];
        $analyst['is_active'] = (bool)$analyst['is_active'];
        $analyst['auth_provider_id'] = $analyst['auth_provider_id'] !== null ? (int)$analyst['auth_provider_id'] : null;
        // Default to all-access if the column is somehow NULL (matches the migration default).
        $analyst['can_access_all_tenants'] = !isset($analyst['can_access_all_tenants']) || (int)$analyst['can_access_all_tenants'] === 1;
        $analyst['tenant_ids'] = $grantsByAnalyst[$analyst['id']] ?? [];
    }

    echo json_encode(['success' => true, 'analysts' => $analysts]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
