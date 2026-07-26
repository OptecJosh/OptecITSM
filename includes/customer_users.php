<?php
/**
 * Portal users linked to customers (13b).
 *
 * users.customer_id says which customer a portal user belongs to. One user, one
 * customer; NULL means unattached, which every self-registered portal user is
 * until somebody links them.
 *
 * The point of the link is what it lets the helpdesk infer: a ticket raised by a
 * linked user already knows which customer it concerns, so nobody has to set it
 * by hand. See customerForUser().
 *
 * HOW A CREATED USER GETS IN — worth stating plainly, because it is not what the
 * design notes assumed. password_reset_tokens is analyst-only (it has an
 * analyst_id, NOT NULL), so it cannot issue a portal set-password link without
 * being restructured. The portal already solves this a different way: a user row
 * with password_hash NULL is a CLAIMABLE account, and self-service registration
 * lets whoever owns that email set their own password on it and claim it. So a
 * user created here is created passwordless, and nobody ever handles a password.
 *
 * The security property that comes with it, stated rather than buried: claiming
 * is authenticated by knowing the email address alone. That is pre-existing
 * portal behaviour, not something introduced here, but pre-creating accounts
 * makes more claimable rows exist, so it is a deliberate trade rather than an
 * accident.
 */

/** Does users.customer_id exist yet? Code deploys before Database Verify runs. */
function usersCustomerColumnExists(PDO $conn): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = $conn->query("SHOW COLUMNS FROM users LIKE 'customer_id'")->fetch() !== false;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * The customer a portal user belongs to, or null.
 *
 * This is what makes linking worth doing: a ticket arriving from this user can
 * default its customer instead of waiting for an analyst to pick one.
 */
function customerForUser(PDO $conn, ?int $userId): ?int {
    if (!$userId || !usersCustomerColumnExists($conn)) return null;
    try {
        $stmt = $conn->prepare("SELECT customer_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? null : (int)$val;
    } catch (Exception $e) {
        return null;
    }
}

/** The portal users linked to a customer, for the customer page's panel. */
function customerLinkedUsers(PDO $conn, int $customerId): array {
    if ($customerId <= 0 || !usersCustomerColumnExists($conn)) return [];
    try {
        $stmt = $conn->prepare(
            "SELECT id, email, display_name, preferred_name, password_hash, created_at
               FROM users WHERE customer_id = ? ORDER BY display_name, email"
        );
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    return array_map(fn($r) => [
        'id'             => (int)$r['id'],
        'email'          => $r['email'],
        'display_name'   => $r['display_name'],
        'preferred_name' => $r['preferred_name'],
        // Never expose the hash — only whether they have finished signing up.
        'registered'     => !empty($r['password_hash']),
        'created_at'     => $r['created_at'],
    ], $rows);
}
