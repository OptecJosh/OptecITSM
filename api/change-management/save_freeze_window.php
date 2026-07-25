<?php
/**
 * API: Create or update a change freeze window (Phase 9b). Admin only.
 * POST JSON { id?, name, starts_at, ends_at, reason?, is_active?,
 *             category_ids?: int[], tenant_ids?: int[] }
 * Datetimes are 'YYYY-MM-DD HH:MM[:SS]' (from a datetime-local input).
 *
 * category_ids / tenant_ids replace the window's scope rows wholesale; empty (or
 * absent on create) means the window is global. Only ids that exist are kept.
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

/** Normalise a datetime-local value to 'Y-m-d H:i:s', or null if unparseable. */
function freeze_norm_dt($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Positive ints from a payload list that actually exist in $table. */
function freeze_valid_ids(PDO $conn, $raw, string $table): array {
    if (!is_array($raw)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($i) => $i > 0)));
    if (!$ids) return [];
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE id IN ($ph)");
        $stmt->execute($ids);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];   // table absent on a minimal install
    }
}

/** Replace a window's scope rows with the given category/company ids. */
function freeze_write_scopes(PDO $conn, int $windowId, array $categoryIds, array $tenantIds): void {
    try {
        $conn->prepare("DELETE FROM change_freeze_scopes WHERE freeze_window_id = ?")->execute([$windowId]);
        $ins = $conn->prepare("INSERT INTO change_freeze_scopes (freeze_window_id, scope_type, scope_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");
        foreach ($categoryIds as $id) $ins->execute([$windowId, 'category', $id]);
        foreach ($tenantIds as $id)   $ins->execute([$windowId, 'tenant', $id]);
    } catch (Exception $e) {
        error_log('freeze scope write failed: ' . $e->getMessage());   // window itself still saved
    }
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    if (!analystIsAdmin($conn, $analystId)) {
        throw new Exception('Only administrators can manage freeze windows');
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id       = !empty($data['id']) ? (int)$data['id'] : null;
    $name     = trim((string)($data['name'] ?? ''));
    $starts   = freeze_norm_dt($data['starts_at'] ?? '');
    $ends     = freeze_norm_dt($data['ends_at'] ?? '');
    $reason   = trim((string)($data['reason'] ?? ''));
    $isActive = array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : 1;

    if ($name === '') throw new Exception('Name is required');
    if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
    if (!$starts || !$ends) throw new Exception('Valid start and end date/times are required');
    if ($ends <= $starts) throw new Exception('End must be after start');
    if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);
    $reason = $reason === '' ? null : $reason;

    $categoryIds = freeze_valid_ids($conn, $data['category_ids'] ?? null, 'change_categories');
    $tenantIds   = freeze_valid_ids($conn, $data['tenant_ids'] ?? null, 'tenants');

    if ($id) {
        $chk = $conn->prepare("SELECT 1 FROM change_freeze_windows WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) throw new Exception('Freeze window not found');
        $conn->prepare(
            "UPDATE change_freeze_windows
                SET name = ?, starts_at = ?, ends_at = ?, reason = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([$name, $starts, $ends, $reason, $isActive, $id]);
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO change_freeze_windows
                (name, starts_at, ends_at, reason, is_active, created_by_analyst_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$name, $starts, $ends, $reason, $isActive, $analystId]);
        $newId = (int)$conn->lastInsertId();
    }

    freeze_write_scopes($conn, $newId, $categoryIds, $tenantIds);

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
