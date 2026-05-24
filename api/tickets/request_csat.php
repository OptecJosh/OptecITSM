<?php
/**
 * API: Manually request a CSAT survey for a ticket.
 *
 * Used by the "Request feedback" toolbar button. Bypasses the auto-trigger
 * (which only fires on close transitions) and the one-per-ticket guard (an
 * analyst clicking the button is a deliberate request, not survey-spam).
 *
 * Refuses if csat_mode is 'off' — settings are still the master switch.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/csat.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ticketId = (int)($data['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ticket_id required']);
    exit;
}

try {
    $conn = connectToDatabase();
    if (csatGetSetting($conn, 'csat_mode', 'off') === 'off') {
        echo json_encode(['success' => false, 'error' => 'CSAT is turned off — enable it under Tickets → Settings → CSAT first']);
        exit;
    }

    $responseId = sendCsatSurvey($conn, $ticketId, (int)$_SESSION['analyst_id'], true);
    if ($responseId === null) {
        echo json_encode(['success' => false, 'error' => 'Survey not sent (no active CSAT template?)']);
        exit;
    }

    echo json_encode(['success' => true, 'response_id' => $responseId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    error_log('request_csat.php: ' . $e->getMessage());
}
