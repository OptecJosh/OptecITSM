<?php
/**
 * API Endpoint: Move a ticket to the trash (soft-delete).
 *
 * Sets deleted_datetime/deleted_by rather than removing anything, so the ticket
 * (and all its emails, attachments, notes, history) can be restored. List and
 * count queries hide trashed tickets (deleted_datetime IS NULL filter). The
 * real, irreversible removal lives in permanently_delete_ticket.php, reachable
 * from the Trash view.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['ticket_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$ticketId = (int)$data['ticket_id'];

try {
    $conn = connectToDatabase();

    // Multi-tenancy: never delete a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // Only act on a live ticket (don't re-trash one already in the bin).
    $checkStmt = $conn->prepare("SELECT id FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
    $checkStmt->execute([$ticketId]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $analystId = (int)$_SESSION['analyst_id'];

    // Soft-delete: flag it as trashed. Nothing is removed, so it can be restored.
    $conn->prepare("UPDATE tickets SET deleted_datetime = UTC_TIMESTAMP(), deleted_by = ? WHERE id = ?")
         ->execute([$analystId, $ticketId]);

    $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, 'Trash', 'active', 'moved to trash', UTC_TIMESTAMP())"
    )->execute([$ticketId, $analystId]);

    echo json_encode(['success' => true, 'message' => 'Ticket moved to trash']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
