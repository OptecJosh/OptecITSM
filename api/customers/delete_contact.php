<?php
/**
 * API: Delete a customer contact (13a). POST JSON { id }
 *
 * A hard delete, because tickets.customer_contact_id is ON DELETE SET NULL: a
 * ticket that referenced this contact falls back to "the customer's default",
 * which is what NULL has meant there all along. Nothing is orphaned and no
 * ticket is lost.
 *
 * Deleting the default hands the role to the oldest remaining active contact and
 * refreshes the customers.contact_* mirror. Deleting the LAST contact leaves the
 * mirror as it was rather than blanking it — a customer that has lost its only
 * contact should not also lose the details every export still reads.
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
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $stmt = $conn->prepare("SELECT customer_id FROM customer_contacts WHERE id = ?");
    $stmt->execute([$id]);
    $customerId = $stmt->fetchColumn();
    if ($customerId === false) throw new Exception('Contact not found');

    $conn->prepare("DELETE FROM customer_contacts WHERE id = ?")->execute([$id]);

    customerContactsEnsureDefault($conn, (int)$customerId);
    customerContactsSyncDefault($conn, (int)$customerId);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
