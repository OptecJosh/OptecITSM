<?php
/**
 * API: Approve or reject a pending overtime request (Phase 11b).
 * POST JSON { id, decision: 'approve'|'reject', note? }
 * Permitted for an admin, or the requesting analyst's line manager. Writes the
 * decision + an audit row, and best-effort emails the agent.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/overtime.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('overtime');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    $decision = (string)($data['decision'] ?? '');
    $note = trim((string)($data['note'] ?? ''));
    if ($id <= 0) throw new Exception('id is required');
    if (!in_array($decision, ['approve', 'reject'], true)) throw new Exception('Invalid decision');
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    // Load the request + its agent (for permission + notify).
    $q = $conn->prepare(
        "SELECT o.status, o.analyst_id, a.full_name AS agent_name, a.email AS agent_email, a.manager_id
           FROM overtime_requests o JOIN analysts a ON a.id = o.analyst_id
          WHERE o.id = ?"
    );
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Overtime request not found');
    if ($row['status'] !== 'pending') throw new Exception('Only pending overtime can be decided');

    $isAdmin = analystIsAdmin($conn, $analystId);
    $isManager = $row['manager_id'] !== null && (int)$row['manager_id'] === $analystId;
    if (!$isAdmin && !$isManager) throw new Exception('You are not authorised to decide this request');

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $conn->prepare(
        "UPDATE overtime_requests
            SET status = ?, decided_by_id = ?, decided_datetime = UTC_TIMESTAMP(),
                decision_note = ?, updated_datetime = UTC_TIMESTAMP()
          WHERE id = ?"
    )->execute([$newStatus, $analystId, $note ?: null, $id]);

    overtime_audit($conn, $id, $analystId, $decision === 'approve' ? 'approved' : 'rejected', 'pending', $newStatus, $note ?: null);

    // Best-effort email to the agent (never blocks the decision).
    if (!empty($row['agent_email'])) {
        try {
            require_once __DIR__ . '/../../includes/mailer.php';
            $verb = $decision === 'approve' ? 'approved' : 'rejected';
            $subject = "Your overtime request was {$verb}";
            $body = '<div style="font-family:Arial,sans-serif;color:#333;">'
                  . '<p>Hi ' . htmlspecialchars($row['agent_name']) . ',</p>'
                  . '<p>Your overtime request has been <strong>' . $verb . '</strong>.</p>'
                  . ($note !== '' ? '<p><strong>Note:</strong> ' . htmlspecialchars($note) . '</p>' : '')
                  . '<p>See details under Overtime &rsaquo; My overtime.</p></div>';
            mailer_send_html($conn, [$row['agent_email']], $subject, $body);
        } catch (Exception $e) {
            error_log('overtime decision notify failed for request ' . $id . ': ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'status' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
