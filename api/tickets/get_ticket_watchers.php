<?php
/**
 * API: List a ticket's watchers (Phase 6d).
 * GET ?ticket_id=N → { watchers:[{id, analyst_id, name, email}], you_are_watching }
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
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($ticketId <= 0) throw new Exception('ticket_id is required');
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) throw new Exception('Ticket not found');

    $stmt = $conn->prepare(
        "SELECT w.id, w.analyst_id, w.email, a.full_name AS name
           FROM ticket_watchers w
      LEFT JOIN analysts a ON a.id = w.analyst_id
          WHERE w.ticket_id = ?
       ORDER BY a.full_name IS NULL, a.full_name ASC, w.email ASC"
    );
    $stmt->execute([$ticketId]);

    $watchers = [];
    $youWatch = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aid = $r['analyst_id'] !== null ? (int)$r['analyst_id'] : null;
        if ($aid === $analystId) $youWatch = true;
        $watchers[] = [
            'id'         => (int)$r['id'],
            'analyst_id' => $aid,
            'name'       => $r['name'] ?: $r['email'],
            'email'      => $r['email'],
        ];
    }

    echo json_encode(['success' => true, 'watchers' => $watchers, 'you_are_watching' => $youWatch]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
