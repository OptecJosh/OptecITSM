<?php
/**
 * API: Remove a watcher from a ticket (Phase 6d).
 * POST JSON { ticket_id, analyst_id? } — omit analyst_id to unwatch yourself.
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

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
    if ($ticketId <= 0) throw new Exception('ticket_id is required');
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) throw new Exception('Ticket not found');

    $watcherId = (isset($data['analyst_id']) && $data['analyst_id'] !== '' && $data['analyst_id'] !== null)
        ? (int)$data['analyst_id'] : $analystId;

    $conn->prepare("DELETE FROM ticket_watchers WHERE ticket_id = ? AND analyst_id = ?")
         ->execute([$ticketId, $watcherId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
