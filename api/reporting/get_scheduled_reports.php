<?php
/**
 * API: List scheduled reports visible to the current analyst (Phase 8b) — their
 * own personal schedules plus every shared (admin-owned) one.
 *
 * GET (no params). Returns {
 *   success, reports: [{ id, name, group_by, group_label, filters, cadence,
 *                        format, recipients, is_active, is_shared, is_own,
 *                        next_run_at, last_run_at }],
 *   can_manage_shared: bool
 * }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_report.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $stmt = $conn->prepare(
        "SELECT id, name, group_by, filters_json, cadence, format, recipients,
                owner_analyst_id, is_active, next_run_at, last_run_at
           FROM scheduled_report
          WHERE owner_analyst_id = ? OR owner_analyst_id IS NULL
       ORDER BY (owner_analyst_id IS NULL) ASC, name ASC"
    );
    $stmt->execute([$analystId]);

    $reports = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $filters = [];
        if (!empty($r['filters_json'])) {
            $decoded = json_decode($r['filters_json'], true);
            if (is_array($decoded)) $filters = $decoded;
        }
        $reports[] = [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'group_by'    => $r['group_by'],
            'group_label' => ticket_report_dim_label($r['group_by']),
            'filters'     => $filters,
            'cadence'     => $r['cadence'],
            'format'      => $r['format'],
            'recipients'  => $r['recipients'] ?? '',
            'is_active'   => (int)$r['is_active'] === 1,
            'is_shared'   => $r['owner_analyst_id'] === null,
            'is_own'      => $r['owner_analyst_id'] !== null && (int)$r['owner_analyst_id'] === $analystId,
            'next_run_at' => $r['next_run_at'],
            'last_run_at' => $r['last_run_at'],
        ];
    }

    echo json_encode([
        'success'           => true,
        'reports'           => $reports,
        'can_manage_shared' => analystIsAdmin($conn, $analystId),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
