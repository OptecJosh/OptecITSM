<?php
/**
 * Web chat widgets — shared helpers.
 *
 * A web chat widget is the self-hosted twin of a WhatsApp number: it drives one
 * `messaging_channels` row (channel_type='webchat', provider='freeitsm') so that
 * once a visitor's message is ingested it flows through the same ticket membrane,
 * inbox and reply pipeline as every other channel. This file holds the helpers the
 * config screen and (later) the public widget endpoints share.
 *
 *   webchatGenerateKey()               → a fresh public widget key
 *   webchatBaseUrl($conn)              → scheme://host + app root for widget URLs
 *   webchatEmbedSnippet($conn, $key)   → the <script> a customer pastes into their site
 *   webchatLoadByChannel($conn, $id)   → a widget row by its channel id (or null)
 *   webchatLoadByKey($conn, $key)      → a widget row by its public key (or null)
 *   webchatParseOrigins($raw)          → a cleaned list of allowed origins
 *   webchatOriginAllowed($list, $orig) → is this request origin permitted?
 *
 * The widget key is PUBLIC — it ships in the customer's page source. It is not a
 * secret: abuse is contained by the origin allowlist + rate limiting, never by
 * keeping the key hidden.
 */

require_once __DIR__ . '/../messaging/messaging.php';

/** A fresh, URL-safe public widget key (e.g. wc_1a2b…). */
function webchatGenerateKey(): string
{
    return 'wc_' . bin2hex(random_bytes(16));
}

/**
 * Public base URL + app root used to build widget asset / API URLs. Reuses the
 * messaging public base (system setting or derived from the request) and derives
 * the app root from SCRIPT_NAME so it works under any sub-path. Only ever called
 * from a script under /api/webchat/, so that prefix is what we strip.
 */
function webchatBaseUrl(PDO $conn): string
{
    $base = messagingPublicBaseUrl($conn);
    $root = preg_replace('#/api/webchat/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '');
    return rtrim($base . $root, '/');
}

/** The <script> embed snippet a customer pastes just before </body> on their site. */
function webchatEmbedSnippet(PDO $conn, string $widgetKey): string
{
    $src = webchatBaseUrl($conn) . '/api/webchat/widget.js';
    return "<script>\n"
        . "  (function(d){\n"
        . "    var s=d.createElement('script');\n"
        . "    s.src=" . json_encode($src) . ";\n"
        . "    s.async=true;\n"
        . "    s.setAttribute('data-freeitsm-widget'," . json_encode($widgetKey) . ");\n"
        . "    d.head.appendChild(s);\n"
        . "  })(document);\n"
        . "</script>";
}

/** Load a widget row joined to its channel, keyed by channel id. Null if missing. */
function webchatLoadByChannel(PDO $conn, int $channelId): ?array
{
    try {
        $stmt = $conn->prepare(
            "SELECT w.*, c.name, c.tenant_id, c.is_active
             FROM webchat_widgets w
             JOIN messaging_channels c ON c.id = w.channel_id
             WHERE w.channel_id = ? LIMIT 1"
        );
        $stmt->execute([$channelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    return $row ?: null;
}

/** Load a widget row joined to its channel, keyed by public widget key. Null if missing. */
function webchatLoadByKey(PDO $conn, string $widgetKey): ?array
{
    try {
        $stmt = $conn->prepare(
            "SELECT w.*, c.name, c.tenant_id, c.is_active
             FROM webchat_widgets w
             JOIN messaging_channels c ON c.id = w.channel_id
             WHERE w.widget_key = ? LIMIT 1"
        );
        $stmt->execute([$widgetKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    return $row ?: null;
}

/**
 * Parse the stored allowed_origins blob into a clean list of origins. Accepts one
 * per line (or comma-separated), trims trailing slashes, drops blanks/comments.
 */
function webchatParseOrigins(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $parts = preg_split('/[\r\n,]+/', $raw);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '' || $p[0] === '#') {
            continue;
        }
        $out[] = rtrim($p, '/');
    }
    return array_values(array_unique($out));
}

/**
 * Is the request origin permitted for this widget? An empty allowlist means "any"
 * (testing only). Matching is exact on scheme+host (+port), trailing slash ignored.
 */
function webchatOriginAllowed(array $allowed, ?string $origin): bool
{
    if (empty($allowed)) {
        return true;
    }
    if ($origin === null || $origin === '') {
        return false;
    }
    $origin = rtrim(trim($origin), '/');
    return in_array($origin, $allowed, true);
}

/**
 * Find-or-create the requester for a web chat conversation, keyed by the real email
 * the visitor gave. Returns the user id, or null if the users table is unavailable.
 */
function webchatGetOrCreateUser(PDO $conn, string $email, string $name): ?int
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id) {
            if ($name !== '') {
                $conn->prepare("UPDATE users SET display_name = ? WHERE id = ? AND (display_name IS NULL OR display_name = '')")
                     ->execute([$name, (int) $id]);
            }
            return (int) $id;
        }
        $ins = $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())");
        $ins->execute([$email, $name !== '' ? $name : $email]);
        return (int) $conn->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Ingest one inbound visitor message for a web chat conversation. On the first
 * message it opens a ticket and pins the conversation to it (so one conversation ==
 * one ticket); afterwards it appends. Stores the message in the shared `emails` table
 * with channel='webchat', so the reading-pane thread and reply path work unchanged.
 * Returns the ticket id.
 */
function webchatIngestMessage(PDO $conn, array $conversation, array $channel, string $body): int
{
    require_once __DIR__ . '/../messaging/ingest.php';

    $body = trim($body);
    if ($body === '') {
        $body = '[empty message]';
    }

    $email       = trim((string) ($conversation['visitor_email'] ?? ''));
    $name        = trim((string) ($conversation['visitor_name'] ?? ''));
    $displayName = $name !== '' ? $name : ($email !== '' ? $email : 'Website visitor');
    // The emails.from_address threads the conversation; use the visitor's email if given,
    // else a stable per-conversation pseudo-identifier derived from the token.
    $from = $email !== '' ? $email : ('web-' . ($conversation['token'] ?? ''));

    $ticketId  = !empty($conversation['ticket_id']) ? (int) $conversation['ticket_id'] : 0;
    $isInitial = $ticketId ? 0 : 1;

    if (!$ticketId) {
        $userId       = webchatGetOrCreateUser($conn, $email, $name);
        $ticketNumber = messagingGenerateTicketNumber($conn);
        $tenantId     = $channel['tenant_id'] !== null ? (int) $channel['tenant_id'] : null;
        $originId     = getChannelOriginId($conn, 'webchat');
        $subject      = buildChannelSubject('webchat', $displayName, $body);

        $sql = "INSERT INTO tickets (
                    ticket_number, subject, status_id, priority_id,
                    created_datetime, updated_datetime, last_inbound_at,
                    user_id, tenant_id, origin_id
                ) VALUES (
                    ?, ?,
                    (SELECT id FROM ticket_statuses   WHERE name = 'Open'   LIMIT 1),
                    (SELECT id FROM ticket_priorities WHERE name = 'Normal' LIMIT 1),
                    UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                    ?, ?, ?
                )";
        $conn->prepare($sql)->execute([$ticketNumber, $subject, $userId, $tenantId, $originId]);
        $ticketId = (int) $conn->lastInsertId();

        $conn->prepare("UPDATE webchat_conversations SET ticket_id = ?, last_activity_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ticketId, (int) $conversation['id']]);
    } else {
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP(), last_inbound_at = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ticketId]);
        $conn->prepare("UPDATE webchat_conversations SET last_activity_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int) $conversation['id']]);
    }

    $ins = $conn->prepare(
        "INSERT INTO emails (
            exchange_message_id, subject, from_address, from_name, to_recipients,
            received_datetime, body_content, body_type, has_attachments, is_read,
            processed_datetime, ticket_id, is_initial, direction, channel, channel_id
        ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', 0, 0, UTC_TIMESTAMP(), ?, ?, 'Inbound', 'webchat', ?)"
    );
    $ins->execute([
        'wc_in_' . bin2hex(random_bytes(12)),
        $isInitial ? buildChannelSubject('webchat', $displayName, $body) : null,
        $from,
        $displayName,
        $channel['name'] ?? 'Web chat',
        $body,
        $ticketId,
        $isInitial,
        (int) $channel['id'],
    ]);

    try {
        $conn->prepare("UPDATE messaging_channels SET last_inbound_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int) $channel['id']]);
    } catch (Exception $e) { /* non-fatal */ }

    return $ticketId;
}
