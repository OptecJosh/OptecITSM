<?php
/**
 * API Endpoint: Get ticket categories — multi-tenancy aware.
 *
 * Categories are a "global default + per-company add/hide" list (design §7),
 * exactly mirroring ticket_types (see get_ticket_types.php):
 *   - global defaults  → rows with tenant_id IS NULL (shared by every company)
 *   - a company's own  → rows with tenant_id = that company
 *   - a company can hide a global default from its own lists (tenant_config_hidden)
 *
 * Two response shapes:
 *   - default (consumer, e.g. the ticket form): `ticket_categories` = the RESOLVED
 *     visible list for the active company (global-not-hidden + own).
 *   - ?manage=1 (the settings screen): additionally returns `scoped` describing
 *     the two groups to manage when working in a *client* company's context.
 *
 * 12c: pass ?ticket_type_id=N to get only the categories valid for that type —
 * the ones scoped to it, plus every unscoped category. Omit it (or the settings
 * screen's ?manage=1) to get the unfiltered list. Filtering is deliberately not
 * applied to the manage view: settings has to show a category in order to let
 * you change which type it belongs to.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_categories.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
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

    // 12c: the type column only exists after Database Verify, so select it only
    // when it is there — the same pre-verify tolerance every other reader has.
    $hasTypeCol = ticketCategoryTypeColumnExists($conn);
    $typeCol    = $hasTypeCol ? 'ticket_type_id, ' : 'NULL AS ticket_type_id, ';

    // Scope to a ticket type when one was asked for. The manage view is exempt:
    // you cannot re-assign a category's type from a list that has already
    // filtered it out.
    $typeId    = !empty($_GET['ticket_type_id']) ? (int)$_GET['ticket_type_id'] : null;
    $typeWhere = $manage ? '' : ticketCategoryTypeWhere($conn, $typeId);

    // Consumer-safe RESOLVED list (global-not-hidden + this company's own).
    $rows = getTenantConfigRows(
        $conn, 'ticket_categories', 'ticket_category', $activeId,
        'id, name, description, is_active, display_order, ' . $typeCol . 'tenant_id, created_datetime',
        $typeWhere, 'display_order, name'
    );
    foreach ($rows as &$r) {
        $r['is_active']      = (bool)$r['is_active'];
        $r['scope']          = ($r['tenant_id'] === null) ? 'global' : 'company';
        $r['ticket_type_id'] = isset($r['ticket_type_id']) ? (int)$r['ticket_type_id'] : null;
    }
    unset($r);

    $resp = [
        'success'           => true,
        'ticket_categories' => $rows,
        'multi_tenant'      => $multi,
        'type_scoped'       => $hasTypeCol,
        'ticket_type_id'    => $typeId,
    ];

    // Settings management view, only meaningful inside a *client* company context.
    if ($manage && $multi && !$isDefaultCtx) {
        $company = getTenantById($conn, $activeId);

        $hiddenIds = [];
        $hs = $conn->prepare("SELECT entity_id FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_category'");
        $hs->execute([$activeId]);
        foreach ($hs->fetchAll(PDO::FETCH_COLUMN) as $eid) { $hiddenIds[(int)$eid] = true; }

        $globals = [];
        foreach ($conn->query("SELECT id, name, description, is_active, display_order, {$typeCol}tenant_id FROM ticket_categories WHERE tenant_id IS NULL ORDER BY display_order, name") as $g) {
            $globals[] = [
                'id'             => (int)$g['id'],
                'name'           => $g['name'],
                'description'    => $g['description'],
                'is_active'      => (bool)$g['is_active'],
                'display_order'  => (int)$g['display_order'],
                'ticket_type_id' => isset($g['ticket_type_id']) ? (int)$g['ticket_type_id'] : null,
                'hidden'         => isset($hiddenIds[(int)$g['id']]),
            ];
        }

        $ownStmt = $conn->prepare("SELECT id, name, description, is_active, display_order, {$typeCol}tenant_id FROM ticket_categories WHERE tenant_id = ? ORDER BY display_order, name");
        $ownStmt->execute([$activeId]);
        $own = [];
        foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $own[] = [
                'id'             => (int)$o['id'],
                'name'           => $o['name'],
                'description'    => $o['description'],
                'is_active'      => (bool)$o['is_active'],
                'display_order'  => (int)$o['display_order'],
                'ticket_type_id' => isset($o['ticket_type_id']) ? (int)$o['ticket_type_id'] : null,
            ];
        }

        $resp['scoped'] = [
            'is_default' => false,
            'company'    => ['id' => $activeId, 'name' => $company['name'] ?? ''],
            'globals'    => $globals,
            'own'        => $own,
        ];
    }

    echo json_encode($resp);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
