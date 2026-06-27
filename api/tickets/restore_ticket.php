<?php
/**
 * API Endpoint: Restore a ticket from the trash (undo a soft-delete).
 * POST { ticket_id }
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

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['ticket_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}
$ticketId = (int)$data['ticket_id'];
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // Only restore a ticket that is actually trashed.
    $chk = $conn->prepare("SELECT id FROM tickets WHERE id = ? AND deleted_datetime IS NOT NULL");
    $chk->execute([$ticketId]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Ticket is not in the trash']);
        exit;
    }

    $conn->prepare("UPDATE tickets SET deleted_datetime = NULL, deleted_by = NULL WHERE id = ?")
         ->execute([$ticketId]);

    $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, 'Trash', 'in trash', 'restored', UTC_TIMESTAMP())"
    )->execute([$ticketId, $analystId]);

    echo json_encode(['success' => true, 'message' => 'Ticket restored']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
