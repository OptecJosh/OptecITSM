<?php
/**
 * API: view-timer heartbeat (12b).
 * POST JSON { ticket_id, seconds } — seconds focused since the last beat.
 *
 * Called every ~30s by the reading pane while the tab is visible and the analyst
 * has interacted recently. Returns the running total and whether it is yet worth
 * proposing, so the section header can count up live.
 *
 * Cheap and quiet by design: no audit row, no workflow dispatch, and any failure
 * returns success:false without disturbing the pane.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_view_time.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = (int)($data['ticket_id'] ?? 0);
    $seconds = (int)($data['seconds'] ?? 0);
    if ($ticketId <= 0) throw new Exception('ticket_id is required');

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    viewTimeHeartbeat($conn, $ticketId, $analystId, $seconds);
    echo json_encode(['success' => true, 'pending' => viewTimePending($conn, $ticketId, $analystId)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
