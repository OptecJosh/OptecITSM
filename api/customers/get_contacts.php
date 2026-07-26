<?php
/**
 * API: A customer's contacts (13a). GET ?customer_id=<id>[&all=1]
 *
 * Default first, then by name — the order both the customer page's panel and the
 * reading pane's picker want. `all=1` includes deactivated contacts, which the
 * management panel needs and a picker does not.
 *
 * Returns an empty list rather than an error when the table has not been created
 * yet, so the customer page still renders before Database Verify has run.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/customer_contacts.php';
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

    $activeOnly = empty($_GET['all']);

    echo json_encode([
        'success'   => true,
        'contacts'  => customerContactsList($conn, $customerId, $activeOnly),
        'available' => customerContactsTableExists($conn),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
