<?php
/**
 * API: The portal users linked to a customer (13b). GET ?customer_id=<id>
 *
 * Each row says whether that user has finished signing up (`registered`), which
 * is the difference between an account somebody can already log into and one
 * still waiting to be claimed on the portal.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/customer_users.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('customers');

try {
    $conn = connectToDatabase();
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    if ($customerId <= 0) throw new Exception('customer_id is required');

    echo json_encode([
        'success'   => true,
        'users'     => customerLinkedUsers($conn, $customerId),
        'available' => usersCustomerColumnExists($conn),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
