<?php
/**
 * API: Replace a ticket's tag set (Phase 6b).
 * POST JSON { ticket_id, tag_ids:[] }
 * Access-gated on the ticket. Rewrites ticket_tag_map for that ticket in a
 * transaction. Returns the ticket's hydrated tags.
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

    $tagIds = [];
    foreach ((array)($data['tag_ids'] ?? []) as $v) { $n = (int)$v; if ($n > 0) $tagIds[$n] = $n; }

    $conn->beginTransaction();
    $conn->prepare("DELETE FROM ticket_tag_map WHERE ticket_id = ?")->execute([$ticketId]);
    if ($tagIds) {
        // Only insert ids that reference a real tag (guards against stale/forged ids).
        $ph = implode(',', array_fill(0, count($tagIds), '?'));
        $valid = $conn->prepare("SELECT id FROM ticket_tags WHERE id IN ($ph)");
        $valid->execute(array_values($tagIds));
        $ins = $conn->prepare("INSERT IGNORE INTO ticket_tag_map (ticket_id, tag_id) VALUES (?, ?)");
        foreach ($valid->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $ins->execute([$ticketId, (int)$tid]);
        }
    }
    $conn->commit();

    // Return the hydrated set for the pane to re-render.
    $stmt = $conn->prepare(
        "SELECT tg.id, tg.name, tg.colour
           FROM ticket_tag_map m JOIN ticket_tags tg ON tg.id = m.tag_id
          WHERE m.ticket_id = ? ORDER BY tg.display_order ASC, tg.name ASC"
    );
    $stmt->execute([$ticketId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tags as &$t) { $t['id'] = (int)$t['id']; }
    unset($t);

    echo json_encode(['success' => true, 'tags' => $tags]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
