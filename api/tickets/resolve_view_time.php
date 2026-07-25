<?php
/**
 * API: accept or discard tracked view time (12b).
 *
 * POST JSON { ticket_id, action: log|dismiss, minutes?, notes? }
 *
 *   log     → creates a time entry stamped source='auto' (minutes defaults to
 *             the tracked total, but the analyst may edit it down or up before
 *             accepting) and marks the sessions converted.
 *   dismiss → marks the sessions dismissed and writes nothing.
 *
 * The entry goes through TicketsService like any other, so the ticket's audit and
 * totals behave identically; only `source` distinguishes it afterwards.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_view_time.php';
require_once '../../includes/services/tickets.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = (int)($data['ticket_id'] ?? 0);
    $action = ($data['action'] ?? 'log') === 'dismiss' ? 'dismiss' : 'log';
    if ($ticketId <= 0) throw new Exception('ticket_id is required');

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    if ($action === 'dismiss') {
        viewTimeResolve($conn, $ticketId, $analystId, null);
        echo json_encode(['success' => true, 'dismissed' => true]);
        exit;
    }

    $pending = viewTimePending($conn, $ticketId, $analystId);
    $minutes = isset($data['minutes']) && $data['minutes'] !== '' ? (int)$data['minutes'] : $pending['minutes'];
    if ($minutes <= 0) throw new Exception('There is no tracked time to log');
    if ($minutes > 1440) throw new Exception('That is more than a day — log it in smaller pieces');

    $ctx = ActorContext::fromSession($conn);
    $entryId = TicketsService::createTimeEntry($conn, $ctx, $ticketId, [
        'minutes'  => $minutes,
        'notes'    => trim((string)($data['notes'] ?? '')) ?: 'Tracked automatically while the ticket was open',
        'entry_at' => null,
    ]);

    // Stamp it as tracked rather than typed, so the KPI engine can tell them
    // apart. Best-effort: a pre-Database-Verify install has no source column and
    // the entry is still perfectly valid without it.
    try {
        $conn->prepare("UPDATE ticket_time_entries SET source = 'auto' WHERE id = ?")->execute([$entryId]);
    } catch (Exception $e) { /* column not there yet */ }

    viewTimeResolve($conn, $ticketId, $analystId, (int)$entryId);
    echo json_encode(['success' => true, 'id' => $entryId, 'minutes' => $minutes]);

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
