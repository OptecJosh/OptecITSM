<?php
/**
 * PUBLIC endpoint: a visitor sends a message. Ingests it onto the ticket membrane
 * (creating the ticket on the first message), so it appears in the analyst's inbox
 * thread exactly like an email or WhatsApp message. Needs the conversation token.
 */
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/webchat/public.php';

webchatHandlePreflight();

$conn = connectToDatabase();
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$key     = trim((string) ($data['key'] ?? ''));
$token   = trim((string) ($data['token'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));

$widget = webchatPublicResolve($conn, $key);

if ($message === '') {
    webchatFail('Message is empty');
}
$message = mb_substr($message, 0, 5000);

$conv = webchatLoadConversation($conn, $token, (int) $widget['channel_id']);
if (!$conv) {
    webchatFail('Chat session not found — please refresh and start again', 404);
}

webchatRateLimitSend($conn, (int) ($conv['ticket_id'] ?? 0));

// The messaging_channels row (id / name / tenant_id) is what the ingest helper needs.
$channel = loadMessagingChannel($conn, (int) $widget['channel_id']);
if (!$channel) {
    webchatFail('This chat widget is no longer available', 410);
}

$ticketId = webchatIngestMessage($conn, $conv, $channel, $message);

echo json_encode(['success' => true, 'ticket_id' => $ticketId]);
