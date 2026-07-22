<?php
/**
 * API: List change freeze windows (Phase 9b).
 * GET (no params). Returns { success, windows:[...], can_manage:bool }.
 * can_manage (admin) gates create/edit/delete in the UI.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $stmt = $conn->query(
        "SELECT w.id, w.name, w.starts_at, w.ends_at, w.reason, w.is_active,
                a.full_name AS created_by_name,
                (w.is_active = 1 AND w.starts_at <= UTC_TIMESTAMP() AND w.ends_at >= UTC_TIMESTAMP()) AS in_effect
           FROM change_freeze_windows w
      LEFT JOIN analysts a ON a.id = w.created_by_analyst_id
       ORDER BY w.starts_at DESC"
    );
    $windows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $windows[] = [
            'id'              => (int)$r['id'],
            'name'            => $r['name'],
            'starts_at'       => $r['starts_at'],
            'ends_at'         => $r['ends_at'],
            'reason'          => $r['reason'],
            'is_active'       => (int)$r['is_active'] === 1,
            'in_effect'       => (int)$r['in_effect'] === 1,
            'created_by_name' => $r['created_by_name'],
        ];
    }

    echo json_encode([
        'success'    => true,
        'windows'    => $windows,
        'can_manage' => analystIsAdmin($conn, $analystId),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
