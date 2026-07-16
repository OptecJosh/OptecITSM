<?php
/**
 * API Endpoint: Hide / show a global ticket category for the active company.
 *
 * The "hide" half of the add+hide override model (design §7), mirroring
 * set_ticket_type_hidden.php. The global row itself is never touched, so
 * closed/historic tickets still resolve it, and the action is fully reversible.
 *
 * POST JSON { ticket_category_id, hidden: true|false }
 *
 * Only meaningful inside a client company's context (not at N=1, not in the
 * MSP/Default context). Hiding is blocked while open tickets in the company
 * still use the category — the in-use guard.
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
    $data   = json_decode(file_get_contents('php://input'), true);
    $catId  = !empty($data['ticket_category_id']) ? (int)$data['ticket_category_id'] : 0;
    $hidden = !empty($data['hidden']);
    if ($catId <= 0) {
        throw new Exception('Missing ticket category');
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

    // Only a global default (tenant_id NULL) can be hidden; a company's own
    // category is removed by deleting it, not hiding.
    $cur = $conn->prepare("SELECT tenant_id FROM ticket_categories WHERE id = ?");
    $cur->execute([$catId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Category not found');
    }
    if ($row['tenant_id'] !== null) {
        throw new Exception('Only shared default categories are hidden per company.');
    }

    if ($hidden) {
        // In-use guard: don't hide a category open tickets in this company depend on.
        $g = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND category_id = ? AND closed_datetime IS NULL");
        $g->execute([$activeId, $catId]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Open tickets in this company use this category — reassign or close them first.');
        }
        $ins = $conn->prepare("INSERT IGNORE INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, 'ticket_category', ?)");
        $ins->execute([$activeId, $catId]);
    } else {
        $del = $conn->prepare("DELETE FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_category' AND entity_id = ?");
        $del->execute([$activeId, $catId]);
    }

    echo json_encode(['success' => true, 'hidden' => $hidden]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
