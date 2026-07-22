<?php
/**
 * API: Delete a change freeze window (Phase 9b). Admin only.
 * POST JSON { id }
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
    if (!analystIsAdmin($conn, $analystId)) {
        throw new Exception('Only administrators can manage freeze windows');
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn->prepare("DELETE FROM change_freeze_windows WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
