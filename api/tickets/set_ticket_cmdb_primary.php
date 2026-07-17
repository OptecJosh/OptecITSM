<?php
/**
 * API: Mark one linked CMDB object as the ticket's PRIMARY affected CI — the CI
 * whose SLA policy (if any) drives the ticket's SLA. Clears the flag on the
 * ticket's other links so exactly one stays primary.
 *
 * POST JSON { link_id }  OR  { ticket_id, cmdb_object_id }
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $linkId   = isset($data['link_id'])        ? (int)$data['link_id']        : 0;
    $ticketId = isset($data['ticket_id'])      ? (int)$data['ticket_id']      : 0;
    $objectId = isset($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // Resolve the target link → (link id, ticket id), gating on ticket access.
    if ($linkId > 0) {
        $own = $conn->prepare("SELECT id, ticket_id FROM ticket_cmdb_objects WHERE id = ?");
        $own->execute([$linkId]);
    } elseif ($ticketId > 0 && $objectId > 0) {
        $own = $conn->prepare("SELECT id, ticket_id FROM ticket_cmdb_objects WHERE ticket_id = ? AND cmdb_object_id = ?");
        $own->execute([$ticketId, $objectId]);
    } else {
        throw new Exception('Pass link_id OR (ticket_id + cmdb_object_id)');
    }
    $row = $own->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Link not found');

    $tid = (int)$row['ticket_id'];
    $lid = (int)$row['id'];
    if (!analystCanAccessTicket($conn, $analystId, $tid)) throw new Exception('Ticket not found');

    $conn->beginTransaction();
    $conn->prepare("UPDATE ticket_cmdb_objects SET is_primary = 0 WHERE ticket_id = ? AND id <> ?")->execute([$tid, $lid]);
    $conn->prepare("UPDATE ticket_cmdb_objects SET is_primary = 1 WHERE id = ?")->execute([$lid]);
    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
