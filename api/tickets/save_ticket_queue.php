<?php
/**
 * API: Create or update a ticket queue (Phase 5).
 *
 * POST JSON { id?, name, is_shared?, filters }
 *
 * Shared queues (is_shared = true, owner_analyst_id NULL) require admin. Personal
 * queues are owned by the creating analyst. Editing: a personal queue must be
 * owned by the caller (or caller is admin); a shared queue requires admin. Only
 * recognised filter keys are persisted, so stored JSON can't accumulate junk.
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
    $isShared = !empty($data['is_shared']);
    $filters  = (isset($data['filters']) && is_array($data['filters'])) ? $data['filters'] : [];

    if ($name === '') throw new Exception('Queue name is required');
    if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
    if ($isShared && !$isAdmin) throw new Exception('Only administrators can create shared queues');

    // Persist only recognised filter keys (defends the stored JSON against junk).
    $allowed = ['status','priority_id','ticket_type_id','category_id','subcategory_id',
                'tenant_id','origin_id','assignee_id','department_id','created_from','created_to','keyword'];
    $clean = [];
    foreach ($allowed as $k) { if (isset($filters[$k])) $clean[$k] = $filters[$k]; }
    $json = json_encode($clean);

    if ($id) {
        $cur = $conn->prepare("SELECT owner_analyst_id FROM ticket_queues WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Queue not found');
        $ownerId   = $row['owner_analyst_id'];
        $wasShared = ($ownerId === null);

        if ($wasShared) {
            if (!$isAdmin) throw new Exception('Only administrators can edit shared queues');
        } elseif ((int)$ownerId !== $analystId && !$isAdmin) {
            throw new Exception('You can only edit your own queues');
        }

        // Owner reflects the requested sharing: share → NULL (admin only, checked
        // above); otherwise keep the existing personal owner.
        $newOwner = $isShared ? null : ($wasShared ? $analystId : (int)$ownerId);
        $conn->prepare("UPDATE ticket_queues SET name = ?, owner_analyst_id = ?, filters_json = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$name, $newOwner, $json, $id]);
        $newId = $id;
    } else {
        $owner = $isShared ? null : $analystId;
        $conn->prepare("INSERT INTO ticket_queues (name, owner_analyst_id, filters_json, created_by_analyst_id, created_datetime, updated_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$name, $owner, $json, $analystId]);
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
