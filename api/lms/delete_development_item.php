<?php
/**
 * LMS API: delete a certification or training record.
 * POST JSON { type: 'certification'|'training', id }
 * Permission comes from the row's owner (lmsCanEditTraining), never the payload.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('lms');

try {
    $conn = connectToDatabase();
    $viewerId = (int)$_SESSION['analyst_id'];
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    $id = !empty($in['id']) ? (int)$in['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $table = ($in['type'] ?? '') === 'certification' ? 'analyst_certifications'
           : (($in['type'] ?? '') === 'training' ? 'analyst_training_records' : null);
    if ($table === null) throw new Exception("type must be 'certification' or 'training'");

    $owner = $conn->prepare("SELECT analyst_id FROM {$table} WHERE id = ?");
    $owner->execute([$id]);
    $ownerId = $owner->fetchColumn();
    if ($ownerId === false) throw new Exception('Record not found');

    if (!lmsCanEditTraining($conn, $viewerId, (int)$ownerId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot change this training record.']);
        exit;
    }

    $conn->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
