<?php
/**
 * Inbound webhooks — turning someone else's POST into a ticket.
 *
 * The mirror image of the outbound engine: instead of us calling a URL when
 * something happens here, a monitoring tool, alerting platform, form or script
 * calls a URL of ours when something happens there.
 *
 * Each source is a ROW, not a deploy. A new integration means creating a webhook
 * in System > Inbound webhooks, copying its URL and secret into the sending tool,
 * and mapping a few payload paths onto ticket fields.
 *
 * Three things make this safe to expose publicly:
 *
 *   1. AUTHENTICITY. Every request is verified before the payload is read for
 *      meaning — HMAC over the raw body (the strong option, what GitHub and
 *      Grafana speak), a shared secret in a header, or a token in the URL for
 *      tools that offer nothing better. Comparisons use hash_equals.
 *   2. NO INTERPRETATION WITHOUT CONFIGURATION. The payload is only ever read
 *      through the field map an admin wrote. An unmapped payload creates a
 *      ticket with a fixed subject, never something derived from attacker input
 *      in an unexpected place.
 *   3. EVERY DELIVERY IS LOGGED, accepted or not, with the raw payload. When an
 *      integration misbehaves the argument is always about what was actually
 *      sent, so keep the evidence.
 *
 * CORRELATION is the feature that separates useful alerting from noise. A
 * monitoring tool fires repeatedly for one condition; with `dedupe_path` set to
 * whatever it calls its alert id, the second delivery appends to the open ticket
 * instead of raising a duplicate, and a delivery matching the resolve rule can
 * close it. Without it, a flapping check makes a hundred tickets.
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/services/tickets.php';

/** Ticket fields a webhook may set, and how each is resolved. */
function inboundWebhookFields(): array {
    return [
        'subject'         => ['label' => 'Subject',        'kind' => 'text',   'required' => true],
        'description'     => ['label' => 'Message body',   'kind' => 'text'],
        'requester_email' => ['label' => 'Requester email','kind' => 'text'],
        'status'          => ['label' => 'Status',         'kind' => 'lookup', 'table' => 'ticket_statuses'],
        'priority'        => ['label' => 'Priority',       'kind' => 'lookup', 'table' => 'ticket_priorities'],
        'ticket_type'     => ['label' => 'Type',           'kind' => 'lookup', 'table' => 'ticket_types',         'target' => 'ticket_type_id'],
        'category'        => ['label' => 'Category',       'kind' => 'lookup', 'table' => 'ticket_categories',    'target' => 'category_id'],
        'subcategory'     => ['label' => 'Subcategory',    'kind' => 'lookup', 'table' => 'ticket_subcategories', 'target' => 'subcategory_id'],
        'department'      => ['label' => 'Department',     'kind' => 'lookup', 'table' => 'departments',          'target' => 'department_id'],
        'origin'          => ['label' => 'Origin',         'kind' => 'lookup', 'table' => 'ticket_origins',       'target' => 'origin_id'],
        'customer'        => ['label' => 'Customer',       'kind' => 'lookup', 'table' => 'customers',            'target' => 'customer_id'],
    ];
}

/** A URL-safe, unguessable slug for a new webhook. */
function inboundWebhookSlug(): string { return bin2hex(random_bytes(12)); }

/** A secret worth using as an HMAC key. */
function inboundWebhookSecret(): string { return bin2hex(random_bytes(24)); }

/** Load an active webhook by its URL slug, or null. */
function inboundWebhookBySlug(PDO $conn, string $slug): ?array {
    try {
        $stmt = $conn->prepare("SELECT * FROM inbound_webhooks WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Is this request genuinely from the configured sender?
 *
 * Returns [bool ok, string reason]. The reason is logged, never returned to the
 * caller — an attacker probing a URL learns only "403".
 */
function inboundWebhookVerify(array $hook, string $rawBody, array $headers, array $query): array {
    $secret = (string)($hook['secret'] ?? '');
    if ($secret === '') return [false, 'no secret configured'];

    $headers = array_change_key_case($headers, CASE_LOWER);

    switch ($hook['auth_type']) {
        case 'hmac_sha256':
            $headerName = strtolower(trim((string)($hook['signature_header'] ?: 'x-signature')));
            $sent = (string)($headers[$headerName] ?? '');
            if ($sent === '') return [false, "signature header {$headerName} missing"];

            $prefix = (string)($hook['signature_prefix'] ?? '');
            if ($prefix !== '' && strpos($sent, $prefix) === 0) {
                $sent = substr($sent, strlen($prefix));
            }
            $raw = hash_hmac('sha256', $rawBody, $secret, true);
            $expected = ($hook['signature_encoding'] ?? 'hex') === 'base64' ? base64_encode($raw) : bin2hex($raw);
            return hash_equals($expected, trim($sent))
                ? [true, 'hmac ok']
                : [false, 'signature mismatch'];

        case 'token':
            $sent = (string)($query['token'] ?? '');
            return ($sent !== '' && hash_equals($secret, $sent)) ? [true, 'token ok'] : [false, 'bad or missing token'];

        case 'header_secret':
        default:
            $headerName = strtolower(trim((string)($hook['signature_header'] ?: 'x-webhook-secret')));
            $sent = (string)($headers[$headerName] ?? '');
            return ($sent !== '' && hash_equals($secret, trim($sent)))
                ? [true, 'header secret ok']
                : [false, "bad or missing {$headerName}"];
    }
}

/**
 * Read a dot-path out of a decoded payload. Array indices are numeric segments,
 * so `alerts.0.labels.alertname` works on the shape most alerting tools send.
 * Returns null when any segment is missing — a missing path is never an error,
 * it just means that field goes unset.
 */
function inboundWebhookPath(array $payload, string $path) {
    $node = $payload;
    foreach (explode('.', trim($path)) as $seg) {
        if ($seg === '') continue;
        if (is_array($node) && array_key_exists($seg, $node)) {
            $node = $node[$seg];
            continue;
        }
        return null;
    }
    if (is_array($node) || is_object($node)) {
        return json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (is_bool($node)) return $node ? 'true' : 'false';
    return $node === null ? null : (string)$node;
}

/**
 * Resolve one mapping value: literal text, or a template with {{dot.path}}
 * placeholders. A placeholder that resolves to nothing collapses to an empty
 * string, so "Alert: {{labels.alertname}}" degrades to "Alert:" rather than
 * leaking the template.
 */
function inboundWebhookResolve(string $template, array $payload): string {
    if (strpos($template, '{{') === false) return $template;
    return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/', function ($m) use ($payload) {
        $v = inboundWebhookPath($payload, $m[1]);
        return $v === null ? '' : $v;
    }, $template);
}

/** The webhook's field map as an array (field => template). */
function inboundWebhookMap(array $hook): array {
    $map = json_decode((string)($hook['field_map'] ?? ''), true);
    return is_array($map) ? $map : [];
}

/** Record a delivery. The payload is kept, truncated, whatever the outcome. */
function inboundWebhookLog(PDO $conn, ?int $hookId, string $outcome, ?int $ticketId, ?string $dedupe, string $message, string $rawBody, ?string $ip): void {
    try {
        $conn->prepare(
            "INSERT INTO inbound_webhook_events
                (webhook_id, received_at, remote_ip, outcome, ticket_id, dedupe_key, message, payload)
             VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)"
        )->execute([
            $hookId, $ip, $outcome, $ticketId, $dedupe !== null ? mb_substr($dedupe, 0, 200) : null,
            mb_substr($message, 0, 500), mb_substr($rawBody, 0, 60000),
        ]);
    } catch (Exception $e) {
        error_log('[inbound-webhook] log: ' . $e->getMessage());
    }
}

/**
 * Turn a verified payload into a ticket (or into an update of the one it
 * correlates with).
 *
 * @return array{outcome:string,ticket_id:?int,message:string,dedupe:?string}
 */
function inboundWebhookProcess(PDO $conn, array $hook, array $payload): array {
    $map = inboundWebhookMap($hook);
    $fields = inboundWebhookFields();

    // ---- correlation ------------------------------------------------------
    $dedupe = null;
    if (!empty($hook['dedupe_path'])) {
        $dedupe = inboundWebhookPath($payload, (string)$hook['dedupe_path']);
        if ($dedupe !== null) $dedupe = mb_substr(trim($dedupe), 0, 200);
        if ($dedupe === '') $dedupe = null;
    }

    $existing = null;
    if ($dedupe !== null) {
        $stmt = $conn->prepare(
            "SELECT t.id, t.ticket_number FROM tickets t
               JOIN ticket_statuses ts ON ts.id = t.status_id
              WHERE t.external_ref = ? AND t.deleted_datetime IS NULL AND ts.is_closed = 0
           ORDER BY t.id DESC LIMIT 1"
        );
        $stmt->execute([$dedupe]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ---- did this delivery say "resolved"? --------------------------------
    $isResolve = false;
    if (!empty($hook['resolve_path']) && $hook['resolve_value'] !== null && $hook['resolve_value'] !== '') {
        $v = inboundWebhookPath($payload, (string)$hook['resolve_path']);
        $isResolve = $v !== null && strcasecmp(trim($v), (string)$hook['resolve_value']) === 0;
    }

    if ($isResolve) {
        if (!$existing) {
            return ['outcome' => 'ignored', 'ticket_id' => null, 'dedupe' => $dedupe,
                    'message' => 'Resolve delivery with no matching open ticket'];
        }
        $ctx = inboundWebhookActor($conn, $hook);
        $note = "Resolved by the sending system.\n\n" . inboundWebhookSummary($payload);
        try {
            TicketsService::createNote($conn, $ctx, (int)$existing['id'], ['text' => $note]);
        } catch (Exception $e) { /* the status change matters more than the note */ }

        if (!empty($hook['resolve_status'])) {
            try {
                // writeAudit=true: the UI writes its audit client-side, but nobody is
                // here to do that for a webhook, so the service must.
                TicketsService::updateTicket($conn, $ctx, (int)$existing['id'], ['status' => $hook['resolve_status']], true);
            } catch (Exception $e) {
                return ['outcome' => 'appended', 'ticket_id' => (int)$existing['id'], 'dedupe' => $dedupe,
                        'message' => 'Noted, but could not set status: ' . $e->getMessage()];
            }
        }
        return ['outcome' => 'resolved', 'ticket_id' => (int)$existing['id'], 'dedupe' => $dedupe,
                'message' => 'Resolved ' . $existing['ticket_number']];
    }

    // ---- a repeat of something already open -------------------------------
    if ($existing) {
        $ctx = inboundWebhookActor($conn, $hook);
        try {
            TicketsService::createNote($conn, $ctx, (int)$existing['id'], [
                'text' => "Repeat delivery from the sending system.

" . inboundWebhookSummary($payload),
            ]);
        } catch (Exception $e) {
            return ['outcome' => 'error', 'ticket_id' => (int)$existing['id'], 'dedupe' => $dedupe,
                    'message' => 'Could not append: ' . $e->getMessage()];
        }
        return ['outcome' => 'appended', 'ticket_id' => (int)$existing['id'], 'dedupe' => $dedupe,
                'message' => 'Appended to ' . $existing['ticket_number']];
    }

    // ---- create -----------------------------------------------------------
    $in = [];
    foreach ($map as $field => $template) {
        if (!isset($fields[$field]) || !is_string($template) || trim($template) === '') continue;
        $value = trim(inboundWebhookResolve($template, $payload));
        if ($value === '') continue;

        $def = $fields[$field];
        if ($def['kind'] === 'lookup') {
            $id = inboundWebhookLookup($conn, $def['table'], $value);
            if ($id === null) continue;                       // unknown name: leave the field unset
            if ($field === 'status' || $field === 'priority') $in[$field] = $value;   // service takes these by name
            else $in[$def['target']] = $id;
        } else {
            $in[$field] = $value;
        }
    }

    if (empty($in['subject'])) {
        $in['subject'] = 'Inbound: ' . $hook['name'];
    }
    if (empty($in['requester_email'])) {
        // The service requires a requester and will create the portal user if it
        // has to. A per-webhook identity keeps automated tickets attributable.
        $in['requester_email'] = 'webhook+' . $hook['slug'] . '@localhost';
        $in['requester_name'] = $hook['name'];
    }
    if (empty($in['description'])) {
        $in['description'] = inboundWebhookSummary($payload);
    }

    $tenantId = !empty($hook['tenant_id']) ? (int)$hook['tenant_id'] : getDefaultTenantId($conn);
    $ctx = inboundWebhookActor($conn, $hook);

    try {
        $ticketId = TicketsService::createTicket($conn, $ctx, $tenantId, $in, null,
            'Created by inbound webhook: ' . $hook['name']);
    } catch (Exception $e) {
        return ['outcome' => 'error', 'ticket_id' => null, 'dedupe' => $dedupe,
                'message' => 'Create failed: ' . $e->getMessage()];
    }

    if ($dedupe !== null) {
        try {
            $conn->prepare("UPDATE tickets SET external_ref = ? WHERE id = ?")->execute([$dedupe, $ticketId]);
        } catch (Exception $e) { /* correlation is a nicety; the ticket exists */ }
    }

    return ['outcome' => 'created', 'ticket_id' => (int)$ticketId, 'dedupe' => $dedupe, 'message' => 'Ticket created'];
}

/** Resolve a lookup name to an id, case-insensitively. */
function inboundWebhookLookup(PDO $conn, string $table, string $value): ?int {
    static $cache = [];
    $key = $table . '|' . mb_strtolower($value);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $conn->prepare("SELECT id FROM `{$table}` WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        $cache[$key] = $id !== false ? (int)$id : null;
    } catch (Exception $e) {
        $cache[$key] = null;
    }
    return $cache[$key];
}

/**
 * Who the ticket is attributed to. The webhook's configured analyst, else the
 * admin who created it — so the audit trail names a person, and company scope
 * behaves like any other actor's.
 */
function inboundWebhookActor(PDO $conn, array $hook): ActorContext {
    $analystId = (int)($hook['act_as_analyst_id'] ?: $hook['created_by_id'] ?: 0);
    $name = 'Inbound webhook';
    if ($analystId > 0) {
        try {
            $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $stmt->execute([$analystId]);
            $name = (string)($stmt->fetchColumn() ?: $name);
        } catch (Exception $e) { /* keep the default */ }
    }
    return new ActorContext(
        actorId: $analystId,
        companyScope: null,          // the webhook's own tenant_id decides where it lands
        source: 'api',
        locale: 'en',
        actorName: $name
    );
}

/** A readable rendering of the payload, for the ticket body and repeat notes. */
function inboundWebhookSummary(array $payload): string {
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return mb_substr((string)$json, 0, 8000);
}
