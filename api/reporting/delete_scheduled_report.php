<?php
/**
 * API: Delete a scheduled report (Phase 8b).
 * POST JSON { id }
 * Shared reports require admin; personal reports must be owned by the caller
 * (or caller is admin) — same ownership model as ticket_queues.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $cur = $conn->prepare("SELECT owner_analyst_id FROM scheduled_report WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Scheduled report not found');

    $isAdmin = analystIsAdmin($conn, $analystId);
    if ($row['owner_analyst_id'] === null) {
        if (!$isAdmin) throw new Exception('Only administrators can delete shared reports');
    } elseif ((int)$row['owner_analyst_id'] !== $analystId && !$isAdmin) {
        throw new Exception('You can only delete your own reports');
    }

    $conn->prepare("DELETE FROM scheduled_report WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
