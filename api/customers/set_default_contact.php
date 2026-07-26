<?php
/**
 * API: Make one contact a customer's default (13a).
 * POST JSON { customer_id, contact_id }
 *
 * Separate from save_contact.php because promoting a contact is a one-click act
 * from the list, not an edit — the panel should not have to round-trip a whole
 * contact's fields to change which one is default.
 *
 * Demotes whichever contact held the role, then refreshes the
 * customers.contact_* mirror that the rest of the application reads.
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
    if (!customerContactsTableExists($conn)) {
        throw new Exception('Customer contacts are not set up yet — run Database Verify.');
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : 0;
    $contactId  = !empty($data['contact_id'])  ? (int)$data['contact_id']  : 0;
    if ($customerId <= 0 || $contactId <= 0) throw new Exception('customer_id and contact_id are required');

    if (!customerContactsSetDefault($conn, $customerId, $contactId)) {
        throw new Exception('That contact does not belong to this customer');
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
