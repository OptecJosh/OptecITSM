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
