<?php
/**
 * API: Create or update a customer contact (13a).
 * POST JSON { id?, customer_id, name, email?, phone?, job_title?, notes?,
 *             is_default?, is_active? }
 *
 * Every path through here ends in a mirror refresh, because customers.contact_*
 * is kept as a copy of the default contact and half the application still reads
 * it. Making a contact the default, editing the one that already is, or
 * deactivating it all change what that mirror should say.
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

    $analystId = (int)$_SESSION['analyst_id'];
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $id         = !empty($data['id']) ? (int)$data['id'] : null;
    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : 0;
    $name       = trim((string)($data['name'] ?? ''));
    $email      = trim((string)($data['email'] ?? ''));

    if ($customerId <= 0) throw new Exception('customer_id is required');
    if ($name === '')     throw new Exception('Contact name is required');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Contact email is not valid');
    }

    $chk = $conn->prepare("SELECT 1 FROM customers WHERE id = ?");
    $chk->execute([$customerId]);
    if (!$chk->fetchColumn()) throw new Exception('Customer not found');

    $fields = [
        'name'      => mb_substr($name, 0, 150),
        'email'     => $email !== '' ? mb_substr($email, 0, 255) : null,
        'phone'     => trim((string)($data['phone'] ?? '')) ?: null,
        'job_title' => trim((string)($data['job_title'] ?? '')) ?: null,
        'notes'     => trim((string)($data['notes'] ?? '')) ?: null,
        'is_active' => array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : 1,
    ];
    $wantsDefault = !empty($data['is_default']);

    if ($id) {
        $own = $conn->prepare("SELECT customer_id FROM customer_contacts WHERE id = ?");
        $own->execute([$id]);
        $owner = $own->fetchColumn();
        if ($owner === false) throw new Exception('Contact not found');
        if ((int)$owner !== $customerId) throw new Exception('That contact belongs to another customer');

        $conn->prepare(
            "UPDATE customer_contacts SET name = ?, email = ?, phone = ?, job_title = ?, notes = ?,
                    is_active = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([$fields['name'], $fields['email'], $fields['phone'], $fields['job_title'],
                    $fields['notes'], $fields['is_active'], $id]);
        $contactId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO customer_contacts (customer_id, name, email, phone, job_title, notes, is_active,
                                            is_default, created_by_analyst_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$customerId, $fields['name'], $fields['email'], $fields['phone'], $fields['job_title'],
                    $fields['notes'], $fields['is_active'], $analystId]);
        $contactId = (int)$conn->lastInsertId();
    }

    // A customer's FIRST contact becomes the default whether or not it was asked
    // for: a customer with contacts and no default would stop mirroring, and
    // every surface reading customers.contact_* would freeze on stale details.
    $count = (int)$conn->query("SELECT COUNT(*) FROM customer_contacts WHERE customer_id = " . (int)$customerId . " AND is_active = 1")->fetchColumn();
    if ($wantsDefault || $count === 1) {
        customerContactsSetDefault($conn, $customerId, $contactId);
    } else {
        // Deactivating the current default hands the role on rather than leaving
        // the customer without one.
        customerContactsEnsureDefault($conn, $customerId);
        customerContactsSyncDefault($conn, $customerId);
    }

    echo json_encode(['success' => true, 'id' => $contactId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
