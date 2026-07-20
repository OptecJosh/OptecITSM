<?php
/**
 * API: Create or rename a ticket tag (Phase 6b).
 * POST JSON { id?, name, colour? }
 * Creating is open to any tickets analyst; renaming/recolouring an existing tag
 * is admin-only (it changes a shared taxonomy). Creating a name that already
 * exists returns the existing tag rather than erroring on the unique key.
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id     = !empty($data['id']) ? (int)$data['id'] : null;
    $name   = trim((string)($data['name'] ?? ''));
    $colour = (isset($data['colour']) && trim((string)$data['colour']) !== '') ? trim((string)$data['colour']) : null;

    if ($name === '') throw new Exception('Tag name is required');
    if (mb_strlen($name) > 50) $name = mb_substr($name, 0, 50);

    if ($id) {
        if (!analystIsAdmin($conn, (int)$_SESSION['analyst_id'])) throw new Exception('Only administrators can edit tags');
        $conn->prepare("UPDATE ticket_tags SET name = ?, colour = ? WHERE id = ?")->execute([$name, $colour, $id]);
        $newId = $id;
    } else {
        // Reuse an existing tag of the same name (case-insensitive) instead of erroring.
        $chk = $conn->prepare("SELECT id FROM ticket_tags WHERE LOWER(name) = LOWER(?)");
        $chk->execute([$name]);
        $existing = $chk->fetchColumn();
        if ($existing) {
            $newId = (int)$existing;
        } else {
            $conn->prepare("INSERT INTO ticket_tags (name, colour, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$name, $colour]);
            $newId = (int)$conn->lastInsertId();
        }
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
