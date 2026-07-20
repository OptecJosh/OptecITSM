<?php
/**
 * API: Delete a ticket tag (Phase 6b). Admin-only — it removes the tag from
 * every ticket (ticket_tag_map cascades). POST JSON { id }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    if (!analystIsAdmin($conn, (int)$_SESSION['analyst_id'])) throw new Exception('Only administrators can delete tags');

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn->prepare("DELETE FROM ticket_tags WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
