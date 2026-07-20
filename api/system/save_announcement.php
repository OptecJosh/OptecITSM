<?php
/**
 * API (admin): create or update an announcement (Phase 7b).
 * POST JSON { id?, title, body?, is_active?, show_portal?, show_status?, starts_at?, ends_at? }
 * Dates accept 'YYYY-MM-DD HH:MM[:SS]' or the datetime-local 'YYYY-MM-DDTHH:MM'; blank = none.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    requireAdminJson($conn);
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id          = !empty($data['id']) ? (int)$data['id'] : null;
    $title       = trim((string)($data['title'] ?? ''));
    $body        = (string)($data['body'] ?? '');
    $isActive    = !empty($data['is_active']) ? 1 : 0;
    $showPortal  = !empty($data['show_portal']) ? 1 : 0;
    $showStatus  = !empty($data['show_status']) ? 1 : 0;

    if ($title === '') throw new Exception('Title is required');
    if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);

    // Normalise optional datetimes; store NULL when blank.
    $norm = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace('T', ' ', $v);
        if (strlen($v) === 16) $v .= ':00'; // add seconds if datetime-local
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) ? $v : null;
    };
    $startsAt = $norm($data['starts_at'] ?? '');
    $endsAt   = $norm($data['ends_at'] ?? '');

    if ($id) {
        $chk = $conn->prepare("SELECT 1 FROM announcements WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) throw new Exception('Announcement not found');
        $conn->prepare(
            "UPDATE announcements SET title=?, body=?, is_active=?, show_portal=?, show_status=?, starts_at=?, ends_at=?, updated_datetime=UTC_TIMESTAMP() WHERE id=?"
        )->execute([$title, $body, $isActive, $showPortal, $showStatus, $startsAt, $endsAt, $id]);
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO announcements (title, body, is_active, show_portal, show_status, starts_at, ends_at, created_by_analyst_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$title, $body, $isActive, $showPortal, $showStatus, $startsAt, $endsAt, $analystId]);
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
