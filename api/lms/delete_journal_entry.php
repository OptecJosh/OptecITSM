<?php
/**
 * LMS API: delete a development journal entry. POST JSON { id }.
 * The author of the entry only — the same rule as editing one.
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

    $row = $conn->prepare("SELECT analyst_id, author_id FROM analyst_journal_entries WHERE id = ?");
    $row->execute([$id]);
    $entry = $row->fetch(PDO::FETCH_ASSOC);
    if (!$entry) throw new Exception('Journal entry not found');

    // Must be able to see the journal AND be the entry's author.
    if (!lmsJournalCanAccess($conn, $viewerId, (int)$entry['analyst_id'])
        || $entry['author_id'] === null || (int)$entry['author_id'] !== $viewerId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only the author of an entry can delete it.']);
        exit;
    }

    $conn->prepare("DELETE FROM analyst_journal_entries WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
