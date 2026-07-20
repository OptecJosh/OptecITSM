<?php
/**
 * API: List canned responses visible to the current analyst — their own personal
 * ones plus every shared (admin-owned) one. Names/folders only (bodies are
 * fetched per-insert via render_canned_response.php to keep this payload small).
 *
 * GET. Returns { responses: [{id, name, folder, is_shared, is_own}], can_manage_shared }.
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

    $stmt = $conn->prepare(
        "SELECT id, name, folder, owner_analyst_id
           FROM ticket_canned_responses
          WHERE owner_analyst_id = ? OR owner_analyst_id IS NULL
       ORDER BY (owner_analyst_id IS NULL) ASC, (folder IS NULL) ASC, folder ASC, display_order ASC, name ASC"
    );
    $stmt->execute([$analystId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'folder'    => $r['folder'],
            'is_shared' => $r['owner_analyst_id'] === null,
            'is_own'    => $r['owner_analyst_id'] !== null && (int)$r['owner_analyst_id'] === $analystId,
        ];
    }

    echo json_encode([
        'success'           => true,
        'responses'         => $out,
        'can_manage_shared' => analystIsAdmin($conn, $analystId),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
