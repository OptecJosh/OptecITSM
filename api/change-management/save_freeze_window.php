<?php
/**
 * API: Create or update a change freeze window (Phase 9b). Admin only.
 * POST JSON { id?, name, starts_at, ends_at, reason?, is_active? }
 * Datetimes are 'YYYY-MM-DD HH:MM[:SS]' (from a datetime-local input).
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

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
