<?php
/**
 * API (admin): read/set whether the public status page is enabled (Phase 7b).
 * GET  → { enabled: bool }
 * POST { enabled } → persists the `status_page_public` system setting.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('service-status');

try {
    $conn = connectToDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $enabled = !empty($data['enabled']) ? '1' : '0';
        // Upsert without assuming a unique key on setting_key.
        $u = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'status_page_public'");
        $u->execute([$enabled]);
        $exists = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'status_page_public' LIMIT 1")->fetchColumn();
        if (!$exists) {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('status_page_public', ?)")->execute([$enabled]);
        }
        echo json_encode(['success' => true, 'enabled' => $enabled === '1']);
    } else {
        $val = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'status_page_public' LIMIT 1")->fetchColumn();
        echo json_encode(['success' => true, 'enabled' => (string)$val === '1']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
