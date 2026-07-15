<?php
/**
 * PUBLIC endpoint: return a widget's browser-facing config so widget.js can render.
 * No authentication — a website visitor hits this. Guarded by the origin allowlist.
 * Never returns anything sensitive (no key, no company internals) — just look & feel.
 */
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/webchat/public.php';

webchatHandlePreflight();

$key  = trim((string) ($_GET['key'] ?? ''));
$conn = connectToDatabase();
$widget = webchatPublicResolve($conn, $key);

echo json_encode([
    'success' => true,
    'config'  => [
        'name'            => $widget['name'],
        'greeting'        => $widget['greeting'] ?: '',
        'accent'          => $widget['accent_colour'] ?: '#2563eb',
        'launcher_text'   => $widget['launcher_text'] ?: '',
        'require_email'   => (bool) $widget['require_email'],
        'offline_message' => $widget['offline_message'] ?: '',
    ],
]);
