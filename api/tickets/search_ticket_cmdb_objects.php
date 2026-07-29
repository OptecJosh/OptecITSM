<?php
/**
 * API: Search the configuration items available to a ticket (Phase 15c).
 *
 * GET ?ticket_id=<id>&q=<text>. Returns:
 *   { success, results:[…], scope: { state, customer_id, customer_name, ci_count } }
 *
 * `scope.state` tells the picker WHY a result set is empty, which is the whole
 * reason this returns a scope block at all:
 *   no_customer  — the ticket has no customer, so nothing is available
 *   no_cis       — the customer has no CIs linked yet
 *   ok           — the customer has CIs; an empty results list means "no matches"
 *
 * The scope is resolved from the ticket server-side rather than taken as a
 * parameter, so a caller cannot widen it by passing a different customer.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_ci_scope.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    $q        = trim((string)($_GET['q'] ?? ''));
    if ($ticketId <= 0) throw new Exception('ticket_id is required');

    $conn = connectToDatabase();

    // Same access rule as every other ticket endpoint.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    $customerId = ticketCiScopeCustomerId($conn, $ticketId);
    if ($customerId === null) {
        echo json_encode([
            'success' => true,
            'results' => [],
            'scope'   => ['state' => 'no_customer', 'customer_id' => null,
                          'customer_name' => null, 'ci_count' => 0],
        ]);
        exit;
    }

    $ciCount = ticketCiCustomerCiCount($conn, $customerId);
    $scope = [
        'state'         => $ciCount === 0 ? 'no_cis' : 'ok',
        'customer_id'   => $customerId,
        'customer_name' => ticketCiCustomerName($conn, $customerId),
        'ci_count'      => $ciCount,
    ];

    echo json_encode([
        'success' => true,
        'results' => $ciCount === 0 ? [] : ticketCiSearch($conn, $customerId, $q),
        'scope'   => $scope,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
