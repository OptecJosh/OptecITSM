<?php
/**
 * LMS API: add or edit a development journal entry.
 *
 * POST JSON { id?, analyst_id, entry_date?, category?, title, body? }
 *
 * Who may write: anyone who may READ the journal — the analyst and their line
 * manager (lmsJournalCanAccess). Who may EDIT an existing entry: its author only,
 * so a manager cannot rewrite the analyst's reflection and vice versa.
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

    $id = !empty($in['id']) ? (int)$in['id'] : null;
    $analystId = !empty($in['analyst_id']) ? (int)$in['analyst_id'] : $viewerId;

    if ($id) {
        $row = $conn->prepare("SELECT analyst_id, author_id FROM analyst_journal_entries WHERE id = ?");
        $row->execute([$id]);
        $existing = $row->fetch(PDO::FETCH_ASSOC);
        if (!$existing) throw new Exception('Journal entry not found');
        $analystId = (int)$existing['analyst_id'];
        if ($existing['author_id'] === null || (int)$existing['author_id'] !== $viewerId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only the author of an entry can change it.']);
            exit;
        }
    }

    if (!lmsJournalCanAccess($conn, $viewerId, $analystId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'A development journal can only be written to by the analyst it belongs to or their line manager.',
        ]);
        exit;
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new Exception('A title is required');
    $title = mb_substr($title, 0, 200);

    $category = in_array($in['category'] ?? '', lmsJournalCategories(), true) ? $in['category'] : 'progress';

    $date = trim((string)($in['entry_date'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    } else {
        $ts = strtotime($date);
        if ($ts === false) throw new Exception('Entry date is not a valid date');
        $date = date('Y-m-d', $ts);
    }

    $body = trim((string)($in['body'] ?? ''));
    if (mb_strlen($body) > 20000) $body = mb_substr($body, 0, 20000);
    $body = $body !== '' ? $body : null;

    if ($id) {
        $conn->prepare(
            "UPDATE analyst_journal_entries
                SET entry_date = ?, category = ?, title = ?, body = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([$date, $category, $title, $body, $id]);
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO analyst_journal_entries
                (analyst_id, author_id, entry_date, category, title, body, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$analystId, $viewerId, $date, $category, $title, $body]);
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
