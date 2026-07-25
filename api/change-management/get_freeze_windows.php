<?php
/**
 * API: List change freeze windows (Phase 9b).
 * GET (no params). Returns { success, windows:[...], can_manage:bool,
 * categories:[{id,name}], companies:[{id,name}] }.
 * Each window carries its scope (category_ids / tenant_ids + display names);
 * empty scope = global. can_manage (admin) gates create/edit/delete in the UI.
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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Scope pick-lists + per-window scope rows (empty = the window is global).
    $listOf = function (string $table, string $order) use ($conn): array {
        try {
            $out = [];
            foreach ($conn->query("SELECT id, name FROM {$table} {$order}")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['id']] = $r['name'];
            }
            return $out;
        } catch (Exception $e) {
            return [];
        }
    };
    $categories = $listOf('change_categories', 'WHERE is_active = 1 ORDER BY display_order, name');
    $companies  = $listOf('tenants', 'ORDER BY name');

    $scopes = [];
    try {
        foreach ($conn->query("SELECT freeze_window_id, scope_type, scope_id FROM change_freeze_scopes")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $scopes[(int)$r['freeze_window_id']][$r['scope_type']][] = (int)$r['scope_id'];
        }
    } catch (Exception $e) { /* table absent (pre-Database-Verify) */ }

    $windows = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $catIds = $scopes[$id]['category'] ?? [];
        $tenIds = $scopes[$id]['tenant'] ?? [];
        $windows[] = [
            'id'              => $id,
            'name'            => $r['name'],
            'starts_at'       => $r['starts_at'],
            'ends_at'         => $r['ends_at'],
            'reason'          => $r['reason'],
            'is_active'       => (int)$r['is_active'] === 1,
            'in_effect'       => (int)$r['in_effect'] === 1,
            'created_by_name' => $r['created_by_name'],
            'category_ids'    => $catIds,
            'tenant_ids'      => $tenIds,
            'category_names'  => array_values(array_filter(array_map(fn($i) => $categories[$i] ?? null, $catIds))),
            'tenant_names'    => array_values(array_filter(array_map(fn($i) => $companies[$i] ?? null, $tenIds))),
        ];
    }

    $asList = fn(array $m) => array_map(fn($k, $v) => ['id' => $k, 'name' => $v], array_keys($m), array_values($m));

    echo json_encode([
        'success'    => true,
        'windows'    => $windows,
        'can_manage' => analystIsAdmin($conn, $analystId),
        'categories' => $asList($categories),
        'companies'  => $asList($companies),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
