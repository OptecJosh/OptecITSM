<?php
/**
 * API: List the CMDB objects linked to a ticket.
 * Returns hydrated info-card data (name, class, parent name+class, optional
 * Owner) so the reading pane can render each link without follow-up calls.
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

try {
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($ticketId <= 0) throw new Exception('ticket_id is required');

    $conn = connectToDatabase();

    // Multi-tenancy: don't reveal a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // is_primary marks the CI that drives the ticket's SLA; sla_policy_* expose
    // whether that CI carries its own policy (Phase 3b). Primary sorts first.
    $stmt = $conn->prepare(
        "SELECT tco.id AS link_id, tco.is_primary,
                o.id AS object_id, o.name, c.name AS class_name,
                o.parent_id, p.name AS parent_name, pc.name AS parent_class_name,
                tco.created_datetime,
                cosp.policy_id AS sla_policy_id, sp.name AS sla_policy_name
           FROM ticket_cmdb_objects tco
           JOIN cmdb_objects o ON o.id = tco.cmdb_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_objects p ON p.id = o.parent_id
      LEFT JOIN cmdb_classes pc ON pc.id = p.class_id
      LEFT JOIN cmdb_object_sla_policies cosp ON cosp.object_id = o.id
      LEFT JOIN sla_policies sp ON sp.id = cosp.policy_id AND sp.is_active = 1
          WHERE tco.ticket_id = ?
       ORDER BY tco.is_primary DESC, c.name, o.name"
    );
    $stmt->execute([$ticketId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['link_id'] = (int)$r['link_id'];
        $r['object_id'] = (int)$r['object_id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
        $r['is_primary'] = (int)$r['is_primary'] === 1;
        $r['sla_policy_id'] = $r['sla_policy_id'] !== null ? (int)$r['sla_policy_id'] : null;
    }

    echo json_encode(['success' => true, 'links' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
