<?php
/**
 * API: List ticket queues visible to the current analyst — their own personal
 * queues plus every shared (admin-owned) queue — each with a live ticket count
 * computed through the shared filter engine.
 *
 * GET (no params). Returns {
 *   queues: [{ id, name, is_shared, is_own, filters, count }],
 *   can_manage_shared: bool   // admin — may create/edit/delete shared queues
 * }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_filter.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $stmt = $conn->prepare(
        "SELECT id, name, owner_analyst_id, filters_json
           FROM ticket_queues
          WHERE owner_analyst_id = ? OR owner_analyst_id IS NULL
       ORDER BY (owner_analyst_id IS NULL) ASC, display_order ASC, name ASC"
    );
    $stmt->execute([$analystId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tenant scope + hide trashed — the same base predicate get_ticket_counts uses.
    list($ttSql, $ttParams) = ticketTenantFilter($conn, $analystId, 't');
    $ttSql .= " AND t.deleted_datetime IS NULL";

    $queues = [];
    foreach ($rows as $r) {
        $filters = [];
        if (!empty($r['filters_json'])) {
            $decoded = json_decode($r['filters_json'], true);
            if (is_array($decoded)) $filters = $decoded;
        }
        list($fSql, $fParams) = ticket_filter_build($filters);
        $countSql = "SELECT COUNT(*) FROM tickets t
                       LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
                      WHERE 1=1" . $ttSql . $fSql;
        $cs = $conn->prepare($countSql);
        $cs->execute(array_merge($ttParams, $fParams));

        $queues[] = [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'is_shared' => $r['owner_analyst_id'] === null,
            'is_own'    => $r['owner_analyst_id'] !== null && (int)$r['owner_analyst_id'] === $analystId,
            'filters'   => $filters,
            'count'     => (int)$cs->fetchColumn(),
        ];
    }

    echo json_encode([
        'success'           => true,
        'queues'            => $queues,
        'can_manage_shared' => analystIsAdmin($conn, $analystId),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
