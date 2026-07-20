<?php
/**
 * API: Create or update a canned response (Phase 6a).
 * POST JSON { id?, name, body, folder?, is_shared? }
 * Shared (owner NULL) requires admin. Personal responses are owned by the caller;
 * editing a personal one requires ownership (or admin).
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
    $analystId = (int)$_SESSION['analyst_id'];
    $isAdmin = analystIsAdmin($conn, $analystId);

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id       = !empty($data['id']) ? (int)$data['id'] : null;
    $name     = trim((string)($data['name'] ?? ''));
    $body     = (string)($data['body'] ?? '');
    $folder   = (isset($data['folder']) && trim((string)$data['folder']) !== '') ? trim((string)$data['folder']) : null;
    $isShared = !empty($data['is_shared']);

    if ($name === '') throw new Exception('Name is required');
    if (trim(strip_tags($body)) === '') throw new Exception('Body is required');
    if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
    if ($isShared && !$isAdmin) throw new Exception('Only administrators can create shared responses');

    if ($id) {
        $cur = $conn->prepare("SELECT owner_analyst_id FROM ticket_canned_responses WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Response not found');
        $wasShared = $row['owner_analyst_id'] === null;
        if ($wasShared) {
            if (!$isAdmin) throw new Exception('Only administrators can edit shared responses');
        } elseif ((int)$row['owner_analyst_id'] !== $analystId && !$isAdmin) {
            throw new Exception('You can only edit your own responses');
        }
        $newOwner = $isShared ? null : ($wasShared ? $analystId : (int)$row['owner_analyst_id']);
        $conn->prepare("UPDATE ticket_canned_responses SET name = ?, body = ?, folder = ?, owner_analyst_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$name, $body, $folder, $newOwner, $id]);
        $newId = $id;
    } else {
        $owner = $isShared ? null : $analystId;
        $conn->prepare("INSERT INTO ticket_canned_responses (name, body, folder, owner_analyst_id, created_by_analyst_id, created_datetime, updated_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$name, $body, $folder, $owner, $analystId]);
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
