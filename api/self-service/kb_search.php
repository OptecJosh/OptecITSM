<?php
/**
 * API (self-service): Search PUBLISHED knowledge-base articles for the portal.
 * GET ?q=  → { articles: [{ id, title, snippet }] }
 * Blank/short q returns recent published articles (for browse). Only published
 * articles are ever returned; internal/unpublished KB is never exposed here.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $q = trim((string)($_GET['q'] ?? ''));

    if (mb_strlen($q) < 2) {
        $stmt = $conn->query("SELECT id, title, body FROM knowledge_articles WHERE is_published = 1 ORDER BY id DESC LIMIT 20");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $like = '%' . $q . '%';
        $stmt = $conn->prepare(
            "SELECT id, title, body FROM knowledge_articles
              WHERE is_published = 1 AND (title LIKE ? OR body LIKE ?)
           ORDER BY (title LIKE ?) DESC, id DESC
              LIMIT 15"
        );
        $stmt->execute([$like, $like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $out = [];
    foreach ($rows as $r) {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)($r['body'] ?? ''))));
        $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'snippet' => mb_substr($text, 0, 160)];
    }
    echo json_encode(['success' => true, 'articles' => $out]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
