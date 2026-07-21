<?php
/**
 * API (analyst): approve or reject a service-request approval (Phase 7d).
 * POST JSON { approval_id, decision: "approve"|"reject", note? }
 *
 * Only the named approver (or an admin) may decide, and only while the approval
 * is still pending. On approval the ticket is released to its department's
 * auto-assign strategy (6f); on rejection it's recorded with the note. Either
 * way a ticket_audit row is written.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $me = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $approvalId = !empty($data['approval_id']) ? (int)$data['approval_id'] : 0;
    $decision = ($data['decision'] ?? '') === 'approve' ? 'approve' : (($data['decision'] ?? '') === 'reject' ? 'reject' : '');
    $note = trim((string)($data['note'] ?? ''));
    if (mb_strlen($note) > 1000) $note = mb_substr($note, 0, 1000);

    if ($approvalId <= 0) throw new Exception('approval_id is required');
    if ($decision === '') throw new Exception('decision must be approve or reject');

    $stmt = $conn->prepare("SELECT id, ticket_id, approver_analyst_id, status FROM ticket_approvals WHERE id = ?");
    $stmt->execute([$approvalId]);
    $appr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appr) throw new Exception('Approval not found');

    $isAdmin = analystIsAdmin($conn, $me);
    if (!$isAdmin && (int)$appr['approver_analyst_id'] !== $me) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You are not the approver for this request']);
        exit;
    }
    if ($appr['status'] !== 'pending') {
        throw new Exception('This request has already been decided');
    }

    $ticketId = (int)$appr['ticket_id'];
    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';

    $conn->beginTransaction();
    try {
        $conn->prepare(
            "UPDATE ticket_approvals SET status = ?, decision_note = ?, decided_by_analyst_id = ?, decided_datetime = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$newStatus, $note !== '' ? $note : null, $me, $approvalId]);

        // Audit trail on the ticket.
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, 'approval', 'pending', ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $me, $newStatus . ($note !== '' ? ' — ' . $note : '')]);

        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    // On approval, release the ticket to auto-assign (best-effort, post-commit).
    if ($decision === 'approve') {
        require_once __DIR__ . '/../../includes/ticket_autoassign.php';
        try { autoassign_run($conn, $ticketId, $me); } catch (\Throwable $e) { error_log('autoassign (approval) failed: ' . $e->getMessage()); }
    }

    echo json_encode(['success' => true, 'status' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
