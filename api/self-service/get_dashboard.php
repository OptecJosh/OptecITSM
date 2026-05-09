<?php
/**
 * API: Self-Service Dashboard Data
 * GET - Returns ticket summary, recent tickets, and service status for the logged-in user
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();

    // Ticket summary counts by status
    $countStmt = $conn->prepare(
        "SELECT ts.name AS status, COUNT(*) as count
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE t.user_id = ?
         GROUP BY ts.name"
    );
    $countStmt->execute([$userId]);
    $rows = $countStmt->fetchAll(PDO::FETCH_ASSOC);

    $ticketSummary = ['Open' => 0, 'In Progress' => 0, 'On Hold' => 0, 'Closed' => 0, 'total' => 0];
    foreach ($rows as $row) {
        $ticketSummary[$row['status']] = (int)$row['count'];
        $ticketSummary['total'] += (int)$row['count'];
    }

    // Recent tickets (last 10)
    $ticketStmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, tp.name AS priority,
                t.created_datetime, t.updated_datetime,
                d.name as department_name
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
         LEFT JOIN departments d ON t.department_id = d.id
         WHERE t.user_id = ?
         ORDER BY t.updated_datetime DESC
         LIMIT 10"
    );
    $ticketStmt->execute([$userId]);
    $recentTickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

    // Service status - active services with worst current impact (severity_order from lookup)
    $svcStmt = $conn->prepare(
        "SELECT ss.id, ss.name,
            COALESCE(
                (SELECT il.name
                 FROM status_incident_services sis
                 JOIN status_incidents si ON sis.incident_id = si.id
                 JOIN service_impact_levels il ON il.id = sis.impact_level_id
                 LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
                 WHERE sis.service_id = ss.id
                   AND (sst.is_resolved = 0 OR sst.id IS NULL)
                 ORDER BY il.severity_order ASC
                 LIMIT 1),
                'Operational'
            ) AS current_status
        FROM status_services ss
        WHERE ss.is_active = 1
        ORDER BY ss.display_order, ss.name"
    );
    $svcStmt->execute();
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ticket_summary' => $ticketSummary,
        'recent_tickets' => $recentTickets,
        'services' => $services
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
