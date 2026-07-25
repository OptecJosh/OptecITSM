<?php
/**
 * LMS API: an analyst's training / development journal.
 *
 * GET ?analyst_id=<id> (defaults to the caller) →
 *   { success, analyst, is_own, can_write, entries[], categories[], journals[] }
 *
 * VISIBILITY: the analyst and their line manager (analysts.manager_id) — and
 * nobody else. Not LMS managers, not admins. See lmsJournalCanAccess(). Both
 * parties may add entries (a manager's 1:1 note belongs in the same place as the
 * analyst's own reflection); each entry records its author, and only the author
 * can edit or delete their own entry.
 *
 * `journals` lists the journals this caller may open — their own plus their direct
 * reports' — so the page can offer a picker without probing ids.
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
    $analystId = !empty($_GET['analyst_id']) ? (int)$_GET['analyst_id'] : $viewerId;

    if (!lmsJournalCanAccess($conn, $viewerId, $analystId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'A development journal is visible only to the analyst it belongs to and their line manager.',
        ]);
        exit;
    }

    $who = $conn->prepare("SELECT a.id, a.full_name, m.full_name AS manager_name
                             FROM analysts a LEFT JOIN analysts m ON m.id = a.manager_id
                            WHERE a.id = ?");
    $who->execute([$analystId]);
    $analyst = $who->fetch(PDO::FETCH_ASSOC);
    if (!$analyst) throw new Exception('Analyst not found');

    $stmt = $conn->prepare(
        "SELECT j.id, j.analyst_id, j.author_id, j.entry_date, j.category, j.title, j.body,
                j.created_datetime, j.updated_datetime, au.full_name AS author_name
           FROM analyst_journal_entries j
      LEFT JOIN analysts au ON au.id = j.author_id
          WHERE j.analyst_id = ?
       ORDER BY j.entry_date DESC, j.id DESC"
    );
    $stmt->execute([$analystId]);
    $entries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $entries[] = [
            'id'          => (int)$r['id'],
            'entry_date'  => $r['entry_date'],
            'category'    => $r['category'],
            'title'       => $r['title'],
            'body'        => $r['body'],
            'author_id'   => $r['author_id'] !== null ? (int)$r['author_id'] : null,
            'author_name' => $r['author_name'],
            'is_mine'     => $r['author_id'] !== null && (int)$r['author_id'] === $viewerId,
            'updated'     => $r['updated_datetime'],
        ];
    }

    // Journals this caller may open: their own, plus their direct reports'.
    $journals = [['id' => $viewerId, 'full_name' => 'My journal', 'is_self' => true]];
    foreach (lmsDirectReports($conn, $viewerId) as $r) {
        $journals[] = ['id' => (int)$r['id'], 'full_name' => $r['full_name'], 'is_self' => false];
    }

    echo json_encode([
        'success'    => true,
        'analyst'    => [
            'id'           => (int)$analyst['id'],
            'full_name'    => $analyst['full_name'],
            'manager_name' => $analyst['manager_name'],
        ],
        'is_own'     => $analystId === $viewerId,
        'can_write'  => true,               // if you can see a journal, you can add to it
        'entries'    => $entries,
        'categories' => lmsJournalCategories(),
        'journals'   => $journals,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
