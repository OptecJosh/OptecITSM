<?php
/**
 * API (admin): list all announcements for management (Phase 7b).
 * GET → { announcements: [{ id, title, body, is_active, show_portal, show_status, starts_at, ends_at }] }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    requireAdminJson($conn);

    $rows = $conn->query(
        "SELECT id, title, body, is_active, show_portal, show_status, starts_at, ends_at
           FROM announcements ORDER BY is_active DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'          => (int)$r['id'],
            'title'       => $r['title'],
            'body'        => $r['body'],
            'is_active'   => (int)$r['is_active'] === 1,
            'show_portal' => (int)$r['show_portal'] === 1,
            'show_status' => (int)$r['show_status'] === 1,
            'starts_at'   => $r['starts_at'],
            'ends_at'     => $r['ends_at'],
        ];
    }
    echo json_encode(['success' => true, 'announcements' => $out]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
