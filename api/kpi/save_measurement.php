<?php
/**
 * API: Record/update a KPI value for a period (K0).
 * POST JSON { kpi_id, period, value?, status?, note? }
 * Upsert on (kpi_id, period). If status omitted, auto-derive from thresholds.
 * Module-access analysts may record; targets/definitions need admin (separate).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/kpi.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('kpi');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $kpiId  = !empty($data['kpi_id']) ? (int)$data['kpi_id'] : 0;
    $period = kpi_valid_period($data['period'] ?? '');
    if ($kpiId <= 0) throw new Exception('kpi_id is required');
    if (!$period) throw new Exception('A valid period (YYYY-MM) is required');

    $d = $conn->prepare("SELECT direction, green_threshold, amber_threshold FROM kpi_definitions WHERE id = ?");
    $d->execute([$kpiId]);
    $def = $d->fetch(PDO::FETCH_ASSOC);
    if (!$def) throw new Exception('KPI not found');

    $value = (isset($data['value']) && $data['value'] !== '' && $data['value'] !== null) ? (float)$data['value'] : null;
    $note  = trim((string)($data['note'] ?? ''));
    if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

    $allowed = ['green','amber','red','na','info'];
    $status  = in_array($data['status'] ?? '', $allowed, true) ? $data['status'] : null;
    if (!$status) {
        $status = kpi_compute_status($def['direction'], $def['green_threshold'], $def['amber_threshold'], $value);
    }

    $conn->prepare(
        "INSERT INTO kpi_measurements (kpi_id, period_month, value, status, note, entered_by_analyst_id, entered_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE value = VALUES(value), status = VALUES(status), note = VALUES(note),
                                 entered_by_analyst_id = VALUES(entered_by_analyst_id), updated_at = UTC_TIMESTAMP()"
    )->execute([$kpiId, $period, $value, $status, $note ?: null, $analystId]);

    echo json_encode(['success' => true, 'status' => $status]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
