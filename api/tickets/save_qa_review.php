<?php
/**
 * API: Record a QA review of a ticket (K1c) — feeds the KPI QA metrics
 * (pass rate, triage accuracy, escalation handover quality).
 * POST JSON { ticket_id, review_type: triage|resolution|handover, passed, score?, note? }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = !empty($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
    if ($ticketId <= 0) throw new Exception('ticket_id is required');
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) throw new Exception('Ticket not found');

    $type = in_array($data['review_type'] ?? '', ['triage','resolution','handover'], true) ? $data['review_type'] : 'triage';
    $passed = !empty($data['passed']) ? 1 : 0;
    $score = (isset($data['score']) && $data['score'] !== '' && $data['score'] !== null) ? (float)$data['score'] : null;
    $note = trim((string)($data['note'] ?? ''));
    if (mb_strlen($note) > 1000) $note = mb_substr($note, 0, 1000);

    $conn->prepare(
        "INSERT INTO ticket_qa_reviews (ticket_id, reviewer_analyst_id, review_type, passed, score, note, created_datetime)
         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([$ticketId, $analystId, $type, $passed, $score, $note ?: null]);

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
