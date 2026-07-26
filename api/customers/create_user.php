<?php
/**
 * API: Create a portal user for a customer (13b).
 * POST JSON { customer_id, email, display_name?, preferred_name? }
 *
 * The account is created WITHOUT a password, on purpose. A user row with
 * password_hash NULL is a claimable account: the person visits the portal, signs
 * up with this address, and sets their own password (api/self-service/register.php
 * claims the existing row rather than making a second one). Nobody here handles
 * a password, and no new token plumbing was needed.
 *
 * Not the emailed set-password link the design notes assumed — that would have
 * meant restructuring password_reset_tokens, which is analyst-only (analyst_id
 * NOT NULL) and cannot address a portal user as it stands.
 *
 * The trade, stated rather than buried: claiming is authenticated by knowing the
 * email address. That is how portal registration already works, but pre-creating
 * accounts means more claimable rows exist, so it is a choice worth being
 * explicit about.
 *
 * An existing unattached user with this address is LINKED rather than duplicated
 * — users.email is unique, and an analyst typing an address that already exists
 * means "this person", not "make me a second account".
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
    if (!usersCustomerColumnExists($conn)) {
        throw new Exception('Portal user links are not set up yet — run Database Verify.');
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $customerId    = !empty($data['customer_id']) ? (int)$data['customer_id'] : 0;
    $email         = strtolower(trim((string)($data['email'] ?? '')));
    $displayName   = trim((string)($data['display_name'] ?? ''));
    $preferredName = trim((string)($data['preferred_name'] ?? '')) ?: null;

    if ($customerId <= 0) throw new Exception('customer_id is required');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid email address is required');
    }

    $chk = $conn->prepare("SELECT 1 FROM customers WHERE id = ?");
    $chk->execute([$customerId]);
    if (!$chk->fetchColumn()) throw new Exception('Customer not found');

    // An analyst account with this address is a different kind of login and must
    // not be shadowed by a portal user.
    $a = $conn->prepare("SELECT 1 FROM analysts WHERE LOWER(email) = ?");
    $a->execute([$email]);
    if ($a->fetchColumn()) throw new Exception('That address belongs to an analyst account');

    $u = $conn->prepare("SELECT id, customer_id FROM users WHERE LOWER(email) = ?");
    $u->execute([$email]);
    $existing = $u->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $owner = $existing['customer_id'] !== null ? (int)$existing['customer_id'] : null;
        if ($owner !== null && $owner !== $customerId) {
            throw new Exception('That user already belongs to another customer');
        }
        $conn->prepare(
            "UPDATE users SET customer_id = ?,
                              display_name   = COALESCE(NULLIF(?, ''), display_name),
                              preferred_name = COALESCE(?, preferred_name)
              WHERE id = ?"
        )->execute([$customerId, $displayName, $preferredName, (int)$existing['id']]);

        echo json_encode(['success' => true, 'id' => (int)$existing['id'], 'linked_existing' => true]);
        exit;
    }

    $conn->prepare(
        "INSERT INTO users (email, display_name, preferred_name, password_hash, customer_id, created_at)
         VALUES (?, ?, ?, NULL, ?, UTC_TIMESTAMP())"
    )->execute([$email, $displayName !== '' ? $displayName : $email, $preferredName, $customerId]);

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'linked_existing' => false]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
