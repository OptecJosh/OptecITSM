<?php
/**
 * API: List all ticket tags. With ?ticket_id=N, also returns that ticket's
 * assigned tag ids (for the reading-pane picker).
 *
 * GET [?ticket_id=N] → { tags:[{id,name,colour}], can_manage, ticket_tag_ids?:[] }
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

    $tags = $conn->query("SELECT id, name, colour FROM ticket_tags ORDER BY display_order ASC, name ASC")
                 ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tags as &$t) { $t['id'] = (int)$t['id']; }
    unset($t);

    $out = ['success' => true, 'tags' => $tags, 'can_manage' => analystIsAdmin($conn, $analystId)];

    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($ticketId > 0) {
        if (!analystCanAccessTicket($conn, $analystId, $ticketId)) throw new Exception('Ticket not found');
        $stmt = $conn->prepare("SELECT tag_id FROM ticket_tag_map WHERE ticket_id = ?");
        $stmt->execute([$ticketId]);
        $out['ticket_tag_ids'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    echo json_encode($out);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
