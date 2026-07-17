<?php
/**
 * API: Unlink a CMDB object from a ticket. Accepts either the link row id
 * (link_id), or a ticket_id + cmdb_object_id pair.
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
    $linkId   = isset($data['link_id'])  ? (int)$data['link_id']  : 0;
    $ticketId = isset($data['ticket_id'])     ? (int)$data['ticket_id'] : 0;
    $objectId = isset($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;

    $conn = connectToDatabase();

    $analystId = (int)$_SESSION['analyst_id'];
    $targetTicketId = 0;
    $wasPrimary = false;

    if ($linkId > 0) {
        // Multi-tenancy: resolve the link's ticket and gate on access.
        $own = $conn->prepare("SELECT ticket_id, is_primary FROM ticket_cmdb_objects WHERE id = ?");
        $own->execute([$linkId]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row || !analystCanAccessTicket($conn, $analystId, (int)$row['ticket_id'])) {
            throw new Exception('Ticket not found');
        }
        $targetTicketId = (int)$row['ticket_id'];
        $wasPrimary = ((int)$row['is_primary'] === 1);
        $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE id = ?")->execute([$linkId]);
    } elseif ($ticketId > 0 && $objectId > 0) {
        if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
            throw new Exception('Ticket not found');
        }
        $own = $conn->prepare("SELECT is_primary FROM ticket_cmdb_objects WHERE ticket_id = ? AND cmdb_object_id = ?");
        $own->execute([$ticketId, $objectId]);
        $isP = $own->fetchColumn();
        $targetTicketId = $ticketId;
        $wasPrimary = ($isP !== false && (int)$isP === 1);
        $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE ticket_id = ? AND cmdb_object_id = ?")->execute([$ticketId, $objectId]);
    } else {
        throw new Exception('Pass link_id OR (ticket_id + cmdb_object_id)');
    }

    // If the removed CI was the primary, promote the earliest remaining link so
    // the ticket keeps exactly one primary (the CI that drives its SLA).
    if ($wasPrimary && $targetTicketId > 0) {
        $m = $conn->prepare("SELECT MIN(id) FROM ticket_cmdb_objects WHERE ticket_id = ?");
        $m->execute([$targetTicketId]);
        $newPrimaryId = $m->fetchColumn();
        if ($newPrimaryId !== false && $newPrimaryId !== null) {
            $conn->prepare("UPDATE ticket_cmdb_objects SET is_primary = 1 WHERE id = ?")->execute([(int)$newPrimaryId]);
        }
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
