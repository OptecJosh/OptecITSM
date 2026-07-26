<?php
/**
 * Public inbound webhook receiver.
 *
 *   POST /api/inbound/webhook.php?hook=<slug>
 *
 * No session: the caller is a monitoring tool, an alerting platform or a script.
 * Authenticity comes from the webhook's configured scheme (HMAC over the raw
 * body, a shared header secret, or a URL token) — see includes/inbound_webhook.php.
 *
 * Responses are deliberately terse. A caller that fails authentication learns
 * "forbidden" and nothing else; the reason goes to the event log where an admin
 * can see it. Successful deliveries answer 200 quickly with the outcome, because
 * most senders retry on anything else and a retry storm helps nobody.
 *
 * Ordering matters here: EVERY delivery is logged, including the ones we reject,
 * so an integration that is failing can be diagnosed from the receiving end
 * rather than by asking the sender what they think they sent.
 */

require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/inbound_webhook.php';

header('Content-Type: application/json');

/** Request headers, lower-cased, with a fallback for non-Apache SAPIs. */
function inboundHeaders(): array {
    if (function_exists('getallheaders')) {
        $out = [];
        foreach (getallheaders() as $k => $v) $out[strtolower($k)] = $v;
        return $out;
    }
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
    }
    return $out;
}

function inboundRespond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$slug = (string)($_GET['hook'] ?? '');

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    inboundRespond(503, ['error' => 'unavailable']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    inboundRespond(405, ['error' => 'method_not_allowed']);
}

$hook = $slug !== '' ? inboundWebhookBySlug($conn, $slug) : null;
if (!$hook) {
    // Not logged against a webhook, because we do not know which one was meant —
    // and logging every probe of a guessed URL would be a free disk-fill.
    inboundRespond(404, ['error' => 'unknown_hook']);
}

if (empty($hook['is_active'])) {
    inboundWebhookLog($conn, (int)$hook['id'], 'ignored', null, null, 'Webhook is disabled', $rawBody, $ip);
    inboundRespond(409, ['error' => 'inactive']);
}

[$ok, $reason] = inboundWebhookVerify($hook, $rawBody, inboundHeaders(), $_GET);
if (!$ok) {
    inboundWebhookLog($conn, (int)$hook['id'], 'auth_failed', null, null, $reason, $rawBody, $ip);
    inboundRespond(403, ['error' => 'forbidden']);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    // Some senders post form-encoded rather than JSON; accept that too rather
    // than bouncing an integration over a content type.
    if ($rawBody !== '' && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') !== false) {
        parse_str($rawBody, $parsed);
        $payload = is_array($parsed) ? $parsed : null;
    }
}
if (!is_array($payload)) {
    inboundWebhookLog($conn, (int)$hook['id'], 'invalid', null, null, 'Body is not JSON or form-encoded', $rawBody, $ip);
    inboundRespond(400, ['error' => 'invalid_payload']);
}

try {
    $result = inboundWebhookProcess($conn, $hook, $payload);
} catch (Exception $e) {
    inboundWebhookLog($conn, (int)$hook['id'], 'error', null, null, $e->getMessage(), $rawBody, $ip);
    error_log('[inbound-webhook] ' . $hook['slug'] . ': ' . $e->getMessage());
    inboundRespond(500, ['error' => 'processing_failed']);
}

inboundWebhookLog($conn, (int)$hook['id'], $result['outcome'], $result['ticket_id'], $result['dedupe'], $result['message'], $rawBody, $ip);
try {
    $conn->prepare("UPDATE inbound_webhooks SET last_received_at = UTC_TIMESTAMP() WHERE id = ?")->execute([(int)$hook['id']]);
} catch (Exception $e) { /* cosmetic */ }

// 'error' is the one outcome the sender should retry on; everything else is a
// decision we made on purpose and a retry would only repeat it.
$code = $result['outcome'] === 'error' ? 500 : 200;
inboundRespond($code, [
    'outcome'   => $result['outcome'],
    'ticket_id' => $result['ticket_id'],
    'message'   => $result['message'],
]);
