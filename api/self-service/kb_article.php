<?php
/**
 * API (self-service): Read one PUBLISHED knowledge-base article + its rating.
 * GET ?id=  → { article: { id, title, body }, rating: { yes, total, mine } }
 * 404s if the article doesn't exist or isn't published.
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
    $userId = (int)$_SESSION['ss_user_id'];
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $stmt = $conn->prepare("SELECT id, title, body FROM knowledge_articles WHERE id = ? AND is_published = 1");
    $stmt->execute([$id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception('Article not found');

    $sum = $conn->prepare("SELECT COALESCE(SUM(helpful), 0) AS yes, COUNT(*) AS total FROM knowledge_article_ratings WHERE article_id = ?");
    $sum->execute([$id]);
    $s = $sum->fetch(PDO::FETCH_ASSOC) ?: ['yes' => 0, 'total' => 0];

    $mineStmt = $conn->prepare("SELECT helpful FROM knowledge_article_ratings WHERE article_id = ? AND user_id = ?");
    $mineStmt->execute([$id, $userId]);
    $mine = $mineStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'article' => ['id' => (int)$a['id'], 'title' => $a['title'], 'body' => $a['body']],
        'rating'  => [
            'yes'   => (int)$s['yes'],
            'total' => (int)$s['total'],
            'mine'  => $mine === false ? null : (int)$mine,
        ],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
