<?php
/**
 * API: Create or update a customer.
 * POST JSON { id?, name, account_ref?, contact_name?, contact_email?,
 *             contact_phone?, tenant_id?, notes?, is_active? }
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
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id    = !empty($data['id']) ? (int)$data['id'] : null;
    $name  = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new Exception('Customer name is required');
    if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);

    $email = trim((string)($data['contact_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Contact email is not valid');

    $fields = [
        'account_ref'   => trim((string)($data['account_ref'] ?? '')) ?: null,
        'contact_name'  => trim((string)($data['contact_name'] ?? '')) ?: null,
        'contact_email' => $email ?: null,
        'contact_phone' => trim((string)($data['contact_phone'] ?? '')) ?: null,
        'tenant_id'     => !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null,
        'notes'         => trim((string)($data['notes'] ?? '')) ?: null,
        'is_active'     => array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : 1,
    ];

    if ($id) {
        $chk = $conn->prepare("SELECT 1 FROM customers WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) throw new Exception('Customer not found');
        $conn->prepare(
            "UPDATE customers SET name=?, account_ref=?, contact_name=?, contact_email=?, contact_phone=?,
                    tenant_id=?, notes=?, is_active=?, updated_datetime=UTC_TIMESTAMP() WHERE id=?"
        )->execute([$name, $fields['account_ref'], $fields['contact_name'], $fields['contact_email'],
                    $fields['contact_phone'], $fields['tenant_id'], $fields['notes'], $fields['is_active'], $id]);
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO customers (name, account_ref, contact_name, contact_email, contact_phone, tenant_id, notes, is_active, created_by_analyst_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$name, $fields['account_ref'], $fields['contact_name'], $fields['contact_email'],
                    $fields['contact_phone'], $fields['tenant_id'], $fields['notes'], $fields['is_active'], $analystId]);
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
