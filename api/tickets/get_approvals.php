<?php
/**
 * API (analyst): approval queue (Phase 7d).
 * GET ?scope=mine|all&state=pending|decided
 *   mine   → approvals assigned to me (default)
 *   all    → every approval (admins only; falls back to mine otherwise)
 *   state  → pending (default) or decided (approved/rejected history)
 * Returns { success, approvals:[{ id, ticket_id, ticket_number, subject,
 *   requester, item_name, status, approver_name, requested, decided, note }], is_admin }.
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
    $isAdmin = analystIsAdmin($conn, $me);

    $scope = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
    $state = ($_GET['state'] ?? 'pending') === 'decided' ? 'decided' : 'pending';

    $where = [];
    $params = [];
    $where[] = $state === 'pending' ? "a.status = 'pending'" : "a.status IN ('approved','rejected')";
    // Non-admins only ever see their own queue, whatever scope they ask for.
    if ($scope === 'mine' || !$isAdmin) {
        $where[] = "a.approver_analyst_id = ?";
        $params[] = $me;
    }

    $sql = "SELECT a.id, a.ticket_id, a.status, a.decision_note,
                   DATE_FORMAT(a.requested_datetime, '%Y-%m-%d %H:%i') AS requested,
                   DATE_FORMAT(a.decided_datetime,   '%Y-%m-%d %H:%i') AS decided,
                   t.ticket_number, t.subject,
                   COALESCE(u.display_name, u.email, 'Unknown') AS requester,
                   sci.name AS item_name,
                   ap.full_name AS approver_name
              FROM ticket_approvals a
              JOIN tickets t ON t.id = a.ticket_id
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN service_catalog_items sci ON sci.id = a.catalog_item_id
         LEFT JOIN analysts ap ON ap.id = a.approver_analyst_id
             WHERE " . implode(' AND ', $where) . "
          ORDER BY a.requested_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $approvals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $approvals[] = [
            'id'            => (int)$r['id'],
            'ticket_id'     => (int)$r['ticket_id'],
            'ticket_number' => $r['ticket_number'],
            'subject'       => $r['subject'],
            'requester'     => $r['requester'],
            'item_name'     => $r['item_name'] ?: 'Request',
            'status'        => $r['status'],
            'approver_name' => $r['approver_name'],
            'requested'     => $r['requested'],
            'decided'       => $r['decided'],
            'note'          => $r['decision_note'],
        ];
    }

    echo json_encode(['success' => true, 'approvals' => $approvals, 'is_admin' => $isAdmin]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
