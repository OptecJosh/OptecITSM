<?php
/**
 * API Endpoint: Hide / show a global ticket subcategory for the active company.
 *
 * Mirrors set_ticket_category_hidden.php. The global row itself is never
 * touched — hiding only removes it from that company's pickers, and is
 * reversible.
 *
 * POST JSON { ticket_subcategory_id, hidden: true|false }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CATEGORIES);

try {
    $data     = json_decode(file_get_contents('php://input'), true);
    $subcatId = !empty($data['ticket_subcategory_id']) ? (int)$data['ticket_subcategory_id'] : 0;
    $hidden   = !empty($data['hidden']);
    if ($subcatId <= 0) {
        throw new Exception('Missing ticket subcategory');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!isMultiTenant($conn)) {
        throw new Exception('Hiding applies only when more than one company exists.');
    }
    $activeId  = getActiveTenantId($conn, $analystId);
    $defaultId = getDefaultTenantId($conn);
    if ($activeId === $defaultId) {
        throw new Exception('Switch to a client company to hide a shared default from it.');
    }

    $cur = $conn->prepare("SELECT tenant_id FROM ticket_subcategories WHERE id = ?");
    $cur->execute([$subcatId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Subcategory not found');
    }
    if ($row['tenant_id'] !== null) {
        throw new Exception('Only shared default subcategories are hidden per company.');
    }

    if ($hidden) {
        $g = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND subcategory_id = ? AND closed_datetime IS NULL");
        $g->execute([$activeId, $subcatId]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Open tickets in this company use this subcategory — reassign or close them first.');
        }
        $ins = $conn->prepare("INSERT IGNORE INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, 'ticket_subcategory', ?)");
        $ins->execute([$activeId, $subcatId]);
    } else {
        $del = $conn->prepare("DELETE FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_subcategory' AND entity_id = ?");
        $del->execute([$activeId, $subcatId]);
    }

    echo json_encode(['success' => true, 'hidden' => $hidden]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
