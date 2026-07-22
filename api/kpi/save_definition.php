<?php
/**
 * API: Edit a KPI definition's target/thresholds (K0). Admin only.
 * POST JSON { id, target_text?, direction?, green_threshold?, amber_threshold?, is_active? }
 * The provisional targets in the seed are editable as they firm up each quarter.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $chk = $conn->prepare("SELECT 1 FROM kpi_definitions WHERE id = ?");
    $chk->execute([$id]);
    if (!$chk->fetchColumn()) throw new Exception('KPI not found');

    $sets = [];
    $args = [];
    if (array_key_exists('target_text', $data)) {
        $t = trim((string)$data['target_text']); if (mb_strlen($t) > 400) $t = mb_substr($t, 0, 400);
        $sets[] = 'target_text = ?'; $args[] = $t !== '' ? $t : null;
    }
    if (array_key_exists('direction', $data) && in_array($data['direction'], ['higher','lower','band','info'], true)) {
        $sets[] = 'direction = ?'; $args[] = $data['direction'];
    }
    foreach (['green_threshold','amber_threshold'] as $col) {
        if (array_key_exists($col, $data)) {
            $v = ($data[$col] === '' || $data[$col] === null) ? null : (float)$data[$col];
            $sets[] = "$col = ?"; $args[] = $v;
        }
    }
    if (array_key_exists('is_active', $data)) {
        $sets[] = 'is_active = ?'; $args[] = !empty($data['is_active']) ? 1 : 0;
    }
    if (!$sets) throw new Exception('Nothing to update');

    $sets[] = 'updated_datetime = UTC_TIMESTAMP()';
    $args[] = $id;
    $conn->prepare("UPDATE kpi_definitions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
