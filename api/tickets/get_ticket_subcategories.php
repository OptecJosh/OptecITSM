<?php
/**
 * API Endpoint: Get ticket subcategories for a category — multi-tenancy aware.
 *
 * Mirrors get_ticket_categories.php, scoped to a single parent category
 * (?category_id=). Subcategories follow the same "global default + per-company
 * add/hide" model (design §7) as categories themselves.
 *
 * Two response shapes:
 *   - default (consumer, e.g. the ticket form): `ticket_subcategories` = the
 *     RESOLVED visible list for the active company (global-not-hidden + own).
 *   - ?manage=1 (the settings screen): additionally returns `scoped`.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
if ($categoryId <= 0) {
    echo json_encode(['success' => false, 'error' => 'category_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $manage    = !empty($_GET['manage']);

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $rows = getTenantConfigRows(
        $conn, 'ticket_subcategories', 'ticket_subcategory', $activeId,
        'id, category_id, name, description, is_active, display_order, tenant_id, created_datetime',
        'category_id = ' . $categoryId, 'display_order, name'
    );
    foreach ($rows as &$r) {
        $r['is_active'] = (bool)$r['is_active'];
        $r['scope']     = ($r['tenant_id'] === null) ? 'global' : 'company';
    }
    unset($r);

    $resp = ['success' => true, 'ticket_subcategories' => $rows, 'multi_tenant' => $multi];

    if ($manage && $multi && !$isDefaultCtx) {
        $company = getTenantById($conn, $activeId);

        $hiddenIds = [];
        $hs = $conn->prepare("SELECT entity_id FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_subcategory'");
        $hs->execute([$activeId]);
        foreach ($hs->fetchAll(PDO::FETCH_COLUMN) as $eid) { $hiddenIds[(int)$eid] = true; }

        $globalsStmt = $conn->prepare("SELECT id, name, description, is_active, display_order FROM ticket_subcategories WHERE category_id = ? AND tenant_id IS NULL ORDER BY display_order, name");
        $globalsStmt->execute([$categoryId]);
        $globals = [];
        foreach ($globalsStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $globals[] = [
                'id'            => (int)$g['id'],
                'name'          => $g['name'],
                'description'   => $g['description'],
                'is_active'     => (bool)$g['is_active'],
                'display_order' => (int)$g['display_order'],
                'hidden'        => isset($hiddenIds[(int)$g['id']]),
            ];
        }

        $ownStmt = $conn->prepare("SELECT id, name, description, is_active, display_order FROM ticket_subcategories WHERE category_id = ? AND tenant_id = ? ORDER BY display_order, name");
        $ownStmt->execute([$categoryId, $activeId]);
        $own = [];
        foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $own[] = [
                'id'            => (int)$o['id'],
                'name'          => $o['name'],
                'description'   => $o['description'],
                'is_active'     => (bool)$o['is_active'],
                'display_order' => (int)$o['display_order'],
            ];
        }

        $resp['scoped'] = [
            'is_default'  => false,
            'company'     => ['id' => $activeId, 'name' => $company['name'] ?? ''],
            'category_id' => $categoryId,
            'globals'     => $globals,
            'own'         => $own,
        ];
    }

    echo json_encode($resp);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
