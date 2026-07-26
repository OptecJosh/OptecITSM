<?php
/**
 * API: Link or unlink a portal user to a customer (13b).
 * POST JSON { customer_id, user_id }        → link
 * POST JSON { customer_id, email }          → link by address
 * POST JSON { user_id, unlink: true }       → unlink
 *
 * A user belongs to one customer, so linking one that is already attached
 * elsewhere is refused rather than silently reassigned — moving somebody between
 * customers should be a decision, not a side effect of a search result.
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
    $userId = !empty($data['user_id']) ? (int)$data['user_id'] : 0;
    $email  = strtolower(trim((string)($data['email'] ?? '')));

    if (!empty($data['unlink'])) {
        if ($userId <= 0) throw new Exception('user_id is required');
        $conn->prepare("UPDATE users SET customer_id = NULL WHERE id = ?")->execute([$userId]);
        echo json_encode(['success' => true, 'unlinked' => true]);
        exit;
    }

    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : 0;
    if ($customerId <= 0) throw new Exception('customer_id is required');

    $chk = $conn->prepare("SELECT 1 FROM customers WHERE id = ?");
    $chk->execute([$customerId]);
    if (!$chk->fetchColumn()) throw new Exception('Customer not found');

    if ($userId <= 0) {
        if ($email === '') throw new Exception('user_id or email is required');
        $u = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
        $u->execute([$email]);
        $found = $u->fetchColumn();
        if ($found === false) throw new Exception('No portal user with that email address');
        $userId = (int)$found;
    }

    $cur = $conn->prepare("SELECT customer_id FROM users WHERE id = ?");
    $cur->execute([$userId]);
    $existing = $cur->fetchColumn();
    if ($existing === false) throw new Exception('User not found');
    if ($existing !== null && (int)$existing !== $customerId) {
        $nameStmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
        $nameStmt->execute([(int)$existing]);
        $other = $nameStmt->fetchColumn() ?: ('#' . (int)$existing);
        throw new Exception("That user is already linked to {$other} — unlink them there first");
    }

    $conn->prepare("UPDATE users SET customer_id = ? WHERE id = ?")->execute([$customerId, $userId]);
    echo json_encode(['success' => true, 'user_id' => $userId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
