<?php
/**
 * API: Return a canned response's body with placeholders resolved for a ticket.
 * GET ?id=N&ticket_id=M
 *
 * Placeholder set (v1): {{agent.name}} {{ticket.number}} {{ticket.subject}}
 * {{requester.name}} {{company.name}}. All resolved from known columns; any that
 * can't be resolved (e.g. no ticket context) collapse to empty string.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    // Visibility: the response must be the analyst's own or shared.
    $stmt = $conn->prepare("SELECT body, owner_analyst_id FROM ticket_canned_responses WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Response not found');
    if ($row['owner_analyst_id'] !== null && (int)$row['owner_analyst_id'] !== $analystId) {
        throw new Exception('Response not found');
    }

    $repl = [
        '{{agent.name}}'     => '',
        '{{ticket.number}}'  => '',
        '{{ticket.subject}}' => '',
        '{{requester.name}}' => '',
        '{{company.name}}'   => '',
    ];

    // Agent = the logged-in analyst.
    $a = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
    $a->execute([$analystId]);
    $repl['{{agent.name}}'] = (string)($a->fetchColumn() ?: '');

    // Ticket context (best-effort; only if the analyst can access it).
    if ($ticketId > 0 && analystCanAccessTicket($conn, $analystId, $ticketId)) {
        $t = $conn->prepare(
            "SELECT t.ticket_number, t.subject, tn.name AS company_name
               FROM tickets t
          LEFT JOIN tenants tn ON tn.id = t.tenant_id
              WHERE t.id = ?"
        );
        $t->execute([$ticketId]);
        if ($tk = $t->fetch(PDO::FETCH_ASSOC)) {
            $repl['{{ticket.number}}']  = (string)($tk['ticket_number'] ?? '');
            $repl['{{ticket.subject}}'] = (string)($tk['subject'] ?? '');
            $repl['{{company.name}}']   = (string)($tk['company_name'] ?? '');
        }
        // Requester = the name on the ticket's first inbound email.
        $r = $conn->prepare("SELECT from_name FROM emails WHERE ticket_id = ? ORDER BY received_datetime ASC, id ASC LIMIT 1");
        $r->execute([$ticketId]);
        $repl['{{requester.name}}'] = (string)($r->fetchColumn() ?: '');
    }

    echo json_encode(['success' => true, 'body' => strtr($row['body'], $repl)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
