<?php
/**
 * API Endpoint: approve or reject a change as its named approver.
 *
 * POST JSON { id, decision: 'Approve'|'Reject', comment? }
 *
 * The CAB path already had a decision mechanism — submit_cab_vote.php, voted on
 * in the review panel on the change itself. A change with cab_required = 0 had
 * none: changes.approver_id named someone, and that person had no way to act on
 * it. "Approval" meant an ordinary edit that set the status to Approved, which
 * anyone could do, left no record of who decided, and never stamped
 * approval_datetime — so the detail view's Approved timestamp stayed blank for
 * every non-CAB change in the system.
 *
 * Guards, in order: the change must exist and be visible to the caller, it must
 * be a non-CAB change (CAB decisions belong to the vote endpoint so the tally
 * stays the single source of truth), it must be awaiting approval, and the
 * caller must be the named approver or an admin. Admins are allowed through
 * because the named approver leaves, and a change that cannot be decided by
 * anybody is worse than one decided by the wrong person on the record.
 *
 * The status move itself goes through ChangesService::updateChange so it earns
 * the same per-field audit row and the same change.approved workflow event as
 * any other status change; only the approval stamp is written here.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/changes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

try {
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $changeId = !empty($data['id']) ? (int)$data['id'] : 0;
    $decision = trim((string)($data['decision'] ?? ''));
    $comment  = trim((string)($data['comment'] ?? ''));

    if (!$changeId)                                     throw new Exception('Change id is required');
    if (!in_array($decision, ['Approve', 'Reject'], true)) throw new Exception('Decision must be Approve or Reject');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessChange($conn, $analystId, $changeId)) {
        throw new Exception('Change not found');
    }

    $stmt = $conn->prepare(
        "SELECT c.id, c.approver_id, c.cab_required, cs.name AS status_name
           FROM changes c
      LEFT JOIN change_statuses cs ON cs.id = c.status_id
          WHERE c.id = ?"
    );
    $stmt->execute([$changeId]);
    $change = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$change) throw new Exception('Change not found');

    if ((int)$change['cab_required'] === 1) {
        throw new Exception('This change is decided by CAB vote — use the CAB panel.');
    }
    if ($change['status_name'] !== 'Pending Approval') {
        throw new Exception('Only a change awaiting approval can be decided (this one is ' . ($change['status_name'] ?: 'unset') . ')');
    }

    $isApprover = ((int)$change['approver_id'] === $analystId);
    if (!$isApprover && !analystIsAdmin($conn, $analystId)) {
        throw new Exception('Only the named approver or an administrator can decide this change');
    }

    $ctx       = ActorContext::fromSession($conn);
    $newStatus = $decision === 'Approve' ? 'Approved' : 'Rejected';
    ChangesService::updateChange($conn, $ctx, $changeId, ['status' => $newStatus]);

    // Who decided it and when. The CAB path stamps this from the vote tally; for
    // a single approver this is the only record, so an admin deciding on someone
    // else's behalf overwrites approver_id with themselves rather than leaving
    // the record claiming the original approver did it.
    $conn->prepare("UPDATE changes SET approval_datetime = UTC_TIMESTAMP(), approver_id = ? WHERE id = ?")
         ->execute([$analystId, $changeId]);

    if ($comment !== '') {
        try {
            $conn->prepare(
                "INSERT INTO change_comments (change_id, analyst_id, comment_text, is_internal, created_datetime)
                 VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
            )->execute([$changeId, $analystId, $decision . 'd: ' . $comment]);
        } catch (Exception $e) {
            // A comment is a nicety; losing it must not undo a recorded decision.
            error_log('[decide_approval] comment failed for change ' . $changeId . ': ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'status' => $newStatus]);

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
