<?php
/**
 * API: Cancel one of the current analyst's own PENDING overtime requests (11a).
 * POST JSON { id }. Approved/rejected entries can't be cancelled here.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/overtime.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('overtime');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $cur = $conn->prepare("SELECT analyst_id, status FROM overtime_requests WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Overtime request not found');
    if ((int)$row['analyst_id'] !== $analystId) throw new Exception('You can only cancel your own overtime');
    if ($row['status'] !== 'pending') throw new Exception('Only pending overtime can be cancelled');

    $conn->prepare("UPDATE overtime_requests SET status = 'cancelled', updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
    overtime_audit($conn, $id, $analystId, 'cancelled', 'pending', 'cancelled');

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
