<?php
/**
 * API: Merge one or more source tickets into a target (Phase 6e).
 * POST JSON { source_ids:[], target_id }
 *
 * Moves each source's conversation (emails + their attachments), notes, time
 * entries, tags, watchers and affected-CIs onto the target; closes the source
 * and stamps merged_into_ticket_id; writes an audit row + internal note on both
 * sides. All-or-nothing (single transaction); every ticket is access-gated.
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
    $targetId = isset($data['target_id']) ? (int)$data['target_id'] : 0;

    $sourceIds = [];
    foreach ((array)($data['source_ids'] ?? []) as $v) {
        $n = (int)$v;
        if ($n > 0 && $n !== $targetId) $sourceIds[$n] = $n;
    }
    $sourceIds = array_values($sourceIds);

    if ($targetId <= 0) throw new Exception('A target ticket is required');
    if (!$sourceIds) throw new Exception('No source tickets to merge');
    if (count($sourceIds) > 50) throw new Exception('Too many tickets to merge at once (max 50)');

    // --- Validate access + state up front (before any writes) ---
    if (!analystCanAccessTicket($conn, $analystId, $targetId)) throw new Exception('Target ticket not found');
    $tStmt = $conn->prepare("SELECT ticket_number, merged_into_ticket_id FROM tickets WHERE id = ?");
    $tStmt->execute([$targetId]);
    $target = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new Exception('Target ticket not found');
    if ($target['merged_into_ticket_id'] !== null) throw new Exception('The target ticket is itself merged into another');
    $targetNumber = $target['ticket_number'];

    $srcNumbers = [];
    foreach ($sourceIds as $sid) {
        if (!analystCanAccessTicket($conn, $analystId, $sid)) throw new Exception("Ticket #$sid not found");
        $s = $conn->prepare("SELECT ticket_number, merged_into_ticket_id FROM tickets WHERE id = ?");
        $s->execute([$sid]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Ticket #$sid not found");
        if ($row['merged_into_ticket_id'] !== null) throw new Exception("Ticket {$row['ticket_number']} is already merged");
        $srcNumbers[$sid] = $row['ticket_number'];
    }

    // A closed status to move sources into (if the install has one).
    $closedId = $conn->query("SELECT id FROM ticket_statuses WHERE is_closed = 1 ORDER BY display_order ASC, id ASC LIMIT 1")->fetchColumn();
    $closedId = $closedId !== false ? (int)$closedId : null;

    $conn->beginTransaction();

    foreach ($sourceIds as $sid) {
        // Conversation + work move to the target. Moved emails lose is_initial so
        // the target keeps a single opening message. Attachments follow emails.
        $conn->prepare("UPDATE emails SET ticket_id = ?, is_initial = 0 WHERE ticket_id = ?")->execute([$targetId, $sid]);
        $conn->prepare("UPDATE ticket_notes SET ticket_id = ? WHERE ticket_id = ?")->execute([$targetId, $sid]);
        $conn->prepare("UPDATE ticket_time_entries SET ticket_id = ? WHERE ticket_id = ?")->execute([$targetId, $sid]);

        // M:N sets: copy-ignore then drop, so duplicates on the target are skipped.
        $conn->prepare("INSERT IGNORE INTO ticket_tag_map (ticket_id, tag_id) SELECT ?, tag_id FROM ticket_tag_map WHERE ticket_id = ?")->execute([$targetId, $sid]);
        $conn->prepare("DELETE FROM ticket_tag_map WHERE ticket_id = ?")->execute([$sid]);

        $conn->prepare("INSERT IGNORE INTO ticket_watchers (ticket_id, analyst_id, email, created_datetime) SELECT ?, analyst_id, email, created_datetime FROM ticket_watchers WHERE ticket_id = ?")->execute([$targetId, $sid]);
        $conn->prepare("DELETE FROM ticket_watchers WHERE ticket_id = ?")->execute([$sid]);

        // Moved CIs are demoted to non-primary so the target keeps its own primary.
        $conn->prepare("INSERT IGNORE INTO ticket_cmdb_objects (ticket_id, cmdb_object_id, is_primary, created_datetime, created_by_analyst_id) SELECT ?, cmdb_object_id, 0, created_datetime, created_by_analyst_id FROM ticket_cmdb_objects WHERE ticket_id = ?")->execute([$targetId, $sid]);
        $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE ticket_id = ?")->execute([$sid]);

        // Close + stamp the source.
        if ($closedId !== null) {
            $conn->prepare("UPDATE tickets SET merged_into_ticket_id = ?, status_id = ?, closed_datetime = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$targetId, $closedId, $sid]);
        } else {
            $conn->prepare("UPDATE tickets SET merged_into_ticket_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$targetId, $sid]);
        }

        // Audit + internal note on the source.
        $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, 'Merge', NULL, ?, UTC_TIMESTAMP())")
             ->execute([$sid, $analystId, "Merged into $targetNumber"]);
        $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal) VALUES (?, ?, ?, 1)")
             ->execute([$sid, $analystId, "This ticket was merged into $targetNumber."]);
    }

    // Audit + one summary note on the target.
    $mergedList = implode(', ', array_values($srcNumbers));
    $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, 'Merge', NULL, ?, UTC_TIMESTAMP())")
         ->execute([$targetId, $analystId, "Absorbed: $mergedList"]);
    $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal) VALUES (?, ?, ?, 1)")
         ->execute([$targetId, $analystId, "Merged in: $mergedList"]);

    $conn->commit();

    echo json_encode(['success' => true, 'merged' => count($sourceIds), 'target_id' => $targetId, 'target_number' => $targetNumber]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
