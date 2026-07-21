<?php
/**
 * API: Create or update a company (tenant).
 * POST JSON { id?, name, is_active }
 *
 * "Company" is the user-facing word for a tenant; the underlying table/code
 * stays `tenants`. is_default is out of scope here and never edited.
 *
 * GUARD: the default company (is_default = 1) can never be set inactive.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// --- Validate fields ---
$name = trim($data['name'] ?? '');
if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Name is required']);
    exit;
}
$isActive = !empty($data['is_active']) ? 1 : 0;
$id       = isset($data['id']) ? (int)$data['id'] : 0;

// Phase 7e portal branding (all optional). A blank string clears the value.
$brandColor    = trim((string)($data['brand_color'] ?? ''));
$portalName    = trim((string)($data['portal_name'] ?? ''));
$portalWelcome = trim((string)($data['portal_welcome'] ?? ''));
// Accept only a #RGB / #RRGGBB hex colour; anything else is dropped (kept NULL).
if ($brandColor !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $brandColor)) {
    $brandColor = '';
}
if (mb_strlen($portalName) > 150)     $portalName = mb_substr($portalName, 0, 150);
if (mb_strlen($portalWelcome) > 500)  $portalWelcome = mb_substr($portalWelcome, 0, 500);

try {
    $conn = connectToDatabase();

    if ($id > 0) {
        // --- Update existing company ---
        $existing = getTenantById($conn, $id);
        if (!$existing) {
            echo json_encode(['success' => false, 'error' => 'Company not found']);
            exit;
        }
        // Never let the default company be deactivated.
        if ($existing['is_default'] && !$isActive) {
            echo json_encode(['success' => false, 'error' => 'The default company cannot be set inactive']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE tenants SET name = ?, is_active = ?, brand_color = ?, portal_name = ?, portal_welcome = ? WHERE id = ?");
        $stmt->execute([$name, $isActive, $brandColor !== '' ? $brandColor : null, $portalName !== '' ? $portalName : null, $portalWelcome !== '' ? $portalWelcome : null, $id]);
        echo json_encode(['success' => true, 'id' => $id]);

    } else {
        // --- Create new company (always non-default) ---
        $stmt = $conn->prepare(
            "INSERT INTO tenants (name, is_default, is_active, brand_color, portal_name, portal_welcome, created_datetime)
             VALUES (?, 0, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$name, $isActive, $brandColor !== '' ? $brandColor : null, $portalName !== '' ? $portalName : null, $portalWelcome !== '' ? $portalWelcome : null]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
