<?php
/**
 * API: Tasks — Delete a task and its subtasks
 * POST — JSON body with {id}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing task ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    $check = $conn->prepare("SELECT id FROM tasks WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    // Delete children explicitly rather than relying on FK cascades — installs
    // grown via Database Verify were missing the parent/comments cascade FKs
    // (db_verify adds FKs separately from columns), which orphaned subtasks
    // and comments. Walk the subtask tree, then remove comments + tag links
    // for every task in it, then the tasks themselves (children first).
    $ids = [$id];
    $frontier = [$id];
    while ($frontier) {
        $ph = implode(',', array_fill(0, count($frontier), '?'));
        $kids = $conn->prepare("SELECT id FROM tasks WHERE parent_task_id IN ($ph)");
        $kids->execute($frontier);
        $frontier = array_map('intval', $kids->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_merge($ids, $frontier);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $conn->prepare("DELETE FROM task_comments WHERE task_id IN ($ph)")->execute($ids);
    $conn->prepare("DELETE FROM task_tag_map WHERE task_id IN ($ph)")->execute($ids);
    foreach (array_reverse($ids) as $taskId) {
        $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
    }

    echo json_encode(['success' => true, 'message' => 'Task deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
