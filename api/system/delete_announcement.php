<?php
/**
 * API (admin): delete an announcement (Phase 7b). POST JSON { id }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    requireAdminJson($conn);

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
