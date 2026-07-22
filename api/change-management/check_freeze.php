<?php
/**
 * API: Check whether a planned change window hits a freeze (Phase 9b).
 *
 * GET params (either form):
 *   change_id=<id>                          — check the saved change's schedule/type
 *   start=<dt>&end=<dt>[&change_type_id=<id>] — check an in-progress edit
 *
 * Returns { success, is_emergency, conflicts:[{id,name,starts_at,ends_at,reason}] }.
 * conflicts is empty when clear / emergency / unscheduled — a soft signal only.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/change_freeze.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

try {
    $conn = connectToDatabase();

    $start = null; $end = null; $typeId = null;

    if (!empty($_GET['change_id'])) {
        // Don't leak another company's change schedule via a guessed id (Phase 10e).
        if (!analystCanAccessChange($conn, (int)$_SESSION['analyst_id'], (int)$_GET['change_id'])) {
            throw new Exception('Change not found');
        }
        $stmt = $conn->prepare("SELECT work_start_datetime, work_end_datetime, change_type_id FROM changes WHERE id = ?");
        $stmt->execute([(int)$_GET['change_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $start  = $row['work_start_datetime'];
            $end    = $row['work_end_datetime'];
            $typeId = $row['change_type_id'] !== null ? (int)$row['change_type_id'] : null;
        }
    } else {
        $start  = $_GET['start'] ?? null;
        $end    = $_GET['end'] ?? null;
        $typeId = !empty($_GET['change_type_id']) ? (int)$_GET['change_type_id'] : null;
    }

    $isEmergency = change_freeze_is_emergency_type($conn, $typeId);
    $conflicts = change_freeze_conflicts($conn, $start, $end, $isEmergency);

    echo json_encode([
        'success'      => true,
        'is_emergency' => $isEmergency,
        'conflicts'    => array_map(fn($w) => [
            'id'        => (int)$w['id'],
            'name'      => $w['name'],
            'starts_at' => $w['starts_at'],
            'ends_at'   => $w['ends_at'],
            'reason'    => $w['reason'],
        ], $conflicts),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
