<?php
/**
 * API: manage inbound webhooks (list / save / delete / rotate secret) and read
 * their delivery log. Admin only — a webhook is a publicly reachable door into
 * ticket creation, so who may cut one is the same question as who may configure
 * a mailbox.
 *
 * GET  ?action=list                 → webhooks + the field catalogue + companies
 * GET  ?action=events&webhook_id=N  → recent deliveries with payloads
 * POST {action:'save', ...}         → create or update
 * POST {action:'rotate', id}        → new secret
 * POST {action:'delete', id}        → remove (its event log goes with it)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/inbound_webhook.php';
header('Content-Type: application/json');

/** The receiver's public URL, built from the request so multi-domain installs work. */
function inboundWebhookUrl(string $slug): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // .../api/system/inbound_webhooks.php → strip the two trailing segments.
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return "{$scheme}://{$host}{$base}/inbound/webhook.php?hook={$slug}";
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $action = $_GET['action'] ?? ($_POST['action'] ?? null);

    $body = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $body['action'] ?? $action;
    }

    // ---- list -------------------------------------------------------------
    if ($action === 'list' || $action === null) {
        $rows = $conn->query(
            "SELECT w.*, t.name AS company_name, a.full_name AS act_as_name,
                    (SELECT COUNT(*) FROM inbound_webhook_events e WHERE e.webhook_id = w.id) AS event_count
               FROM inbound_webhooks w
          LEFT JOIN tenants t ON t.id = w.tenant_id
          LEFT JOIN analysts a ON a.id = w.act_as_analyst_id
           ORDER BY w.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['url'] = inboundWebhookUrl($r['slug']);
            $r['field_map'] = json_decode((string)$r['field_map'], true) ?: new stdClass();
        }
        unset($r);

        echo json_encode([
            'success'   => true,
            'webhooks'  => $rows,
            'fields'    => inboundWebhookFields(),
            'companies' => getAllTenants($conn, true),
            'statuses'  => $conn->query("SELECT name FROM ticket_statuses ORDER BY display_order, name")->fetchAll(PDO::FETCH_COLUMN),
        ]);
        exit;
    }

    // ---- delivery log -----------------------------------------------------
    if ($action === 'events') {
        $id = (int)($_GET['webhook_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT e.*, t.ticket_number
               FROM inbound_webhook_events e
          LEFT JOIN tickets t ON t.id = e.ticket_id
              WHERE (? = 0 OR e.webhook_id = ?)
           ORDER BY e.id DESC LIMIT 50"
        );
        $stmt->execute([$id, $id]);
        echo json_encode(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Unknown action');

    // ---- delete -----------------------------------------------------------
    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) throw new Exception('id is required');
        $conn->prepare("DELETE FROM inbound_webhooks WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- rotate the secret -------------------------------------------------
    if ($action === 'rotate') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) throw new Exception('id is required');
        $secret = inboundWebhookSecret();
        $conn->prepare("UPDATE inbound_webhooks SET secret = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$secret, $id]);
        echo json_encode(['success' => true, 'secret' => $secret]);
        exit;
    }

    // ---- create / update ---------------------------------------------------
    if ($action === 'save') {
        $id = !empty($body['id']) ? (int)$body['id'] : null;
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new Exception('A name is required');

        $authType = in_array($body['auth_type'] ?? '', ['hmac_sha256', 'header_secret', 'token'], true)
            ? $body['auth_type'] : 'header_secret';

        // Only the fields the receiver knows about, so a typo in the UI cannot
        // quietly create a mapping that never fires.
        $known = array_keys(inboundWebhookFields());
        $map = [];
        foreach ((array)($body['field_map'] ?? []) as $field => $template) {
            if (!in_array($field, $known, true)) continue;
            $template = trim((string)$template);
            if ($template !== '') $map[$field] = mb_substr($template, 0, 500);
        }

        $fields = [
            $name,
            trim((string)($body['description'] ?? '')) ?: null,
            array_key_exists('is_active', $body) ? (int)(bool)$body['is_active'] : 1,
            $authType,
            trim((string)($body['signature_header'] ?? '')) ?: null,
            trim((string)($body['signature_prefix'] ?? '')) ?: null,
            ($body['signature_encoding'] ?? 'hex') === 'base64' ? 'base64' : 'hex',
            !empty($body['tenant_id']) ? (int)$body['tenant_id'] : null,
            !empty($body['act_as_analyst_id']) ? (int)$body['act_as_analyst_id'] : $analystId,
            json_encode($map),
            trim((string)($body['dedupe_path'] ?? '')) ?: null,
            trim((string)($body['resolve_path'] ?? '')) ?: null,
            trim((string)($body['resolve_value'] ?? '')) ?: null,
            trim((string)($body['resolve_status'] ?? '')) ?: null,
        ];

        if ($id) {
            $conn->prepare(
                "UPDATE inbound_webhooks SET name=?, description=?, is_active=?, auth_type=?, signature_header=?,
                        signature_prefix=?, signature_encoding=?, tenant_id=?, act_as_analyst_id=?, field_map=?,
                        dedupe_path=?, resolve_path=?, resolve_value=?, resolve_status=?, updated_datetime=UTC_TIMESTAMP()
                  WHERE id=?"
            )->execute(array_merge($fields, [$id]));
            $newId = $id;
            $secret = null;
        } else {
            $slug = inboundWebhookSlug();
            $secret = inboundWebhookSecret();
            $conn->prepare(
                "INSERT INTO inbound_webhooks
                    (name, description, is_active, auth_type, signature_header, signature_prefix, signature_encoding,
                     tenant_id, act_as_analyst_id, field_map, dedupe_path, resolve_path, resolve_value, resolve_status,
                     slug, secret, created_by_id, created_datetime, updated_datetime)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
            )->execute(array_merge($fields, [$slug, $secret, $analystId]));
            $newId = (int)$conn->lastInsertId();
        }

        $row = $conn->prepare("SELECT slug FROM inbound_webhooks WHERE id = ?");
        $row->execute([$newId]);
        $slug = (string)$row->fetchColumn();

        echo json_encode(['success' => true, 'id' => $newId, 'url' => inboundWebhookUrl($slug), 'secret' => $secret]);
        exit;
    }

    throw new Exception('Unknown action');

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
