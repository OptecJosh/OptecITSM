<?php
/**
 * API (self-service): Record a "was this helpful?" rating on a published article.
 * POST JSON { article_id, helpful } — helpful is 1 (yes) or 0 (no). Upsert: one
 * rating per portal user per article.
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

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $articleId = isset($data['article_id']) ? (int)$data['article_id'] : 0;
    $helpful = !empty($data['helpful']) ? 1 : 0;
    if ($articleId <= 0) throw new Exception('article_id is required');

    // Only allow rating a published article.
    $chk = $conn->prepare("SELECT 1 FROM knowledge_articles WHERE id = ? AND is_published = 1");
    $chk->execute([$articleId]);
    if (!$chk->fetchColumn()) throw new Exception('Article not found');

    $conn->prepare(
        "INSERT INTO knowledge_article_ratings (article_id, user_id, helpful, created_datetime, updated_datetime)
         VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE helpful = VALUES(helpful), updated_datetime = UTC_TIMESTAMP()"
    )->execute([$articleId, $userId, $helpful]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
