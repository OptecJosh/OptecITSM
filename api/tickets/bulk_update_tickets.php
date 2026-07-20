<?php
/**
 * API: Apply one action to many tickets (Phase 6c).
 * POST JSON { ticket_ids:[], action, value }
 *   action = status | priority | assignee | add_tag | trash
 *
 * Each ticket is handled independently through TicketsService (which enforces
 * access scope, writes audit, and fires the usual side-effects) so a failure on
 * one ticket doesn't abort the rest. Returns per-batch { updated, failed, errors }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/service_context.php';
require_once '../../includes/services/tickets.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $ctx = ActorContext::fromSession($conn);
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($data['action'] ?? '');
    $value  = $data['value'] ?? null;

    $ids = [];
    foreach ((array)($data['ticket_ids'] ?? []) as $v) { $n = (int)$v; if ($n > 0) $ids[$n] = $n; }
    $ids = array_values($ids);
    if (!$ids) throw new Exception('No tickets selected');
    if (count($ids) > 200) throw new Exception('Too many tickets selected (max 200)');

    // Map action → the field-update array applied via TicketsService::updateTicket.
    // 'add_tag' and 'trash' take their own paths below.
    $in = null;
    switch ($action) {
        case 'status':   $in = ['status' => (string)$value]; break;
        case 'priority': $in = ['priority_id' => ($value === '' || $value === null) ? null : (int)$value]; break;
        case 'assignee': $in = ['assigned_analyst_id' => ($value === '' || $value === null) ? null : (int)$value]; break;
        case 'add_tag':
        case 'trash':    break;
        default: throw new Exception('Invalid action');
    }

    // Validate an add_tag target once, up front.
    $tagId = 0;
    if ($action === 'add_tag') {
        $tagId = (int)$value;
        if ($tagId <= 0) throw new Exception('Invalid tag');
        $chk = $conn->prepare("SELECT 1 FROM ticket_tags WHERE id = ?");
        $chk->execute([$tagId]);
        if (!$chk->fetchColumn()) throw new Exception('Unknown tag');
    }

    $updated = 0; $failed = 0; $errors = [];
    foreach ($ids as $tid) {
        try {
            if ($action === 'trash') {
                TicketsService::deleteTicket($conn, $ctx, $tid, true);
            } elseif ($action === 'add_tag') {
                if (!analystCanAccessTicket($conn, $analystId, $tid)) throw new Exception('No access');
                $conn->prepare("INSERT IGNORE INTO ticket_tag_map (ticket_id, tag_id) VALUES (?, ?)")->execute([$tid, $tagId]);
            } else {
                TicketsService::updateTicket($conn, $ctx, $tid, $in, true);
            }
            $updated++;
        } catch (Throwable $e) {
            $failed++;
            if (count($errors) < 5) $errors[] = "#$tid: " . $e->getMessage();
        }
    }

    echo json_encode(['success' => true, 'updated' => $updated, 'failed' => $failed, 'errors' => $errors]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
