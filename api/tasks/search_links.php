<?php
/**
 * API: Tasks — Search tickets/changes for linking
 * GET ?type=ticket|change&q=searchterm
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

$type = $_GET['type'] ?? '';
$q = trim($_GET['q'] ?? '');

if (!$type || !$q) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

try {
    $conn = connectToDatabase();
    $searchTerm = '%' . $q . '%';

    $analystId = (int)$_SESSION['analyst_id'];
    if ($type === 'ticket') {
        // Scope to the analyst's accessible companies (Phase 10e).
        [$tenantSql, $tenantParams] = ticketTenantFilter($conn, $analystId, 't');
        $stmt = $conn->prepare(
            "SELECT t.id, t.ticket_number, t.subject
             FROM tickets t
             WHERE (t.ticket_number LIKE ? OR t.subject LIKE ?)" . $tenantSql . "
             ORDER BY t.created_datetime DESC
             LIMIT 10"
        );
        $stmt->execute(array_merge([$searchTerm, $searchTerm], $tenantParams));
    } elseif ($type === 'change') {
        // Scope to the analyst's active company (Phase 10e).
        [$tenantSql, $tenantParams] = activeTenantFilter($conn, $analystId, 'c');
        $stmt = $conn->prepare(
            "SELECT c.id, c.title
             FROM changes c
             WHERE c.title LIKE ?" . $tenantSql . "
             ORDER BY c.created_datetime DESC
             LIMIT 10"
        );
        $stmt->execute(array_merge([$searchTerm], $tenantParams));
    } else {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    echo json_encode(['success' => true, 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
