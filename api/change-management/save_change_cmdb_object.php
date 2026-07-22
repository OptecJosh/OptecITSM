<?php
/**
 * API: Link a CMDB object (affected CI) to a change (Phase 9c).
 * POST JSON { change_id, cmdb_object_id }. Idempotent (unique key).
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $changeId = isset($data['change_id']) ? (int)$data['change_id'] : 0;
    $objectId = isset($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;
    if ($changeId <= 0 || $objectId <= 0) {
        throw new Exception('change_id and cmdb_object_id are required');
    }

    $conn = connectToDatabase();

    $check = $conn->prepare("SELECT 1 FROM changes WHERE id = ?");
    $check->execute([$changeId]);
    if (!$check->fetchColumn()) throw new Exception('Change not found');

    $check = $conn->prepare("SELECT 1 FROM cmdb_objects WHERE id = ?");
    $check->execute([$objectId]);
    if (!$check->fetchColumn()) throw new Exception('CMDB object not found');

    try {
        $ins = $conn->prepare(
            "INSERT INTO change_cmdb_objects (change_id, cmdb_object_id, created_datetime, created_by_analyst_id)
             VALUES (?, ?, UTC_TIMESTAMP(), ?)"
        );
        $ins->execute([$changeId, $objectId, (int)$_SESSION['analyst_id']]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'already_linked' => false]);
    } catch (PDOException $pe) {
        if ($pe->errorInfo[1] == 1062) {
            echo json_encode(['success' => true, 'already_linked' => true]);
            exit;
        }
        throw $pe;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
