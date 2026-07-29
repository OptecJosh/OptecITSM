<?php
/**
 * Phase 15c — which configuration items may be attached to a ticket.
 *
 * The rule: a ticket may only be linked to CIs that belong to the ticket's
 * customer (`customer_cmdb_objects`). A ticket with no customer set has nothing
 * available to it. This is deliberately strict — there is no "search everything"
 * escape hatch, because the point is that a ticket's affected CI is always one of
 * the things that customer actually has.
 *
 * The rule lives here, once, because there are two write paths for this link and
 * a rule enforced in only one of them is a rule that does not exist:
 *   - api/tickets/save_ticket_cmdb_object.php  (the reading-pane picker)
 *   - api/v1/resources/cmdb.php               (the public REST API)
 * The search endpoint reads from the same helpers, so what the picker offers and
 * what the writers accept can never drift apart.
 *
 * Note on history: this constrains NEW links only. Links that already exist —
 * including any made before this rule, or brought in by a migration — keep
 * working and keep displaying. Retro-validating them would silently detach real
 * data on deploy.
 */

/**
 * The customer a ticket belongs to, or null if it has none (or the ticket is
 * missing / trashed).
 */
function ticketCiScopeCustomerId(PDO $conn, int $ticketId): ?int {
    $stmt = $conn->prepare("SELECT customer_id FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
    $stmt->execute([$ticketId]);
    $val = $stmt->fetchColumn();
    if ($val === false || $val === null || $val === '') return null;
    return (int)$val;
}

/** How many CIs a customer has linked — drives the picker's empty-state wording. */
function ticketCiCustomerCiCount(PDO $conn, int $customerId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM customer_cmdb_objects WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    return (int)$stmt->fetchColumn();
}

/** A customer's display name, for messages that name it. */
function ticketCiCustomerName(PDO $conn, int $customerId): ?string {
    $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : (string)$val;
}

/**
 * May this CI be linked to this ticket?
 *
 * Returns ['ok' => bool, 'reason' => string, 'message' => string]. The reason
 * codes are stable so callers can map them to their own error shape:
 *   no_customer  — the ticket has no customer, so nothing is in scope
 *   not_in_scope — the CI exists but is not linked to that customer
 */
function ticketCiCanLink(PDO $conn, int $ticketId, int $objectId): array {
    $customerId = ticketCiScopeCustomerId($conn, $ticketId);
    if ($customerId === null) {
        return [
            'ok'      => false,
            'reason'  => 'no_customer',
            'message' => 'This ticket has no customer set, so no configuration items are available to it. '
                       . 'Set the ticket\'s customer first.',
        ];
    }

    $stmt = $conn->prepare(
        "SELECT 1 FROM customer_cmdb_objects WHERE customer_id = ? AND cmdb_object_id = ?"
    );
    $stmt->execute([$customerId, $objectId]);
    if ($stmt->fetchColumn()) return ['ok' => true, 'reason' => 'ok', 'message' => ''];

    $custName = ticketCiCustomerName($conn, $customerId);
    return [
        'ok'      => false,
        'reason'  => 'not_in_scope',
        'message' => 'That configuration item is not linked to '
                   . ($custName !== null ? '"' . $custName . '"' : 'this ticket\'s customer')
                   . '. Link it to the customer first (CMDB → the item → Customers).',
    ];
}

/**
 * Search the CIs available to a ticket.
 *
 * An empty $q returns the first $limit CIs the customer has rather than nothing,
 * so opening the picker shows what is available instead of a blank box — with a
 * strict scope the list is short enough to be worth browsing.
 */
function ticketCiSearch(PDO $conn, int $customerId, string $q, int $limit = 20): array {
    $limit = max(1, min(50, $limit));

    $sql = "SELECT o.id, o.name, o.is_planned, c.id AS class_id, c.name AS class_name,
                   p.name AS parent_name
              FROM customer_cmdb_objects cco
              JOIN cmdb_objects o ON o.id = cco.cmdb_object_id
              JOIN cmdb_classes c ON c.id = o.class_id
         LEFT JOIN cmdb_objects p ON p.id = o.parent_id
             WHERE cco.customer_id = ?";
    $params = [$customerId];

    if ($q !== '') {
        $sql .= " AND o.name LIKE ?";
        $params[] = '%' . $q . '%';
    }
    $sql .= " ORDER BY o.name ASC LIMIT $limit";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'class_id'    => (int)$r['class_id'],
            'class_name'  => $r['class_name'],
            'parent_name' => $r['parent_name'],
            'is_planned'  => (int)$r['is_planned'] === 1,
        ];
    }
    return $out;
}
