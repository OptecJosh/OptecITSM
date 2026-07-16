<?php
/**
 * API Endpoint: Save ticket category (create or update) — multi-tenancy aware.
 *
 * Context decides scope (design §7), mirroring save_ticket_type.php:
 *   - Single-company install, OR working in the MSP/Default company → the
 *     category is a GLOBAL default (tenant_id NULL).
 *   - Working in a client company's context → a NEW category belongs to THAT
 *     company (tenant_id set). You may edit/delete only that company's own
 *     categories; the shared defaults are managed from the MSP/Default context
 *     (and hidden, not edited, per company).
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
    $data = json_decode(file_get_contents('php://input'), true);

    $id            = !empty($data['id']) ? (int)$data['id'] : null;
    $name          = trim($data['name'] ?? '');
    $description   = $data['description'] ?? '';
    $display_order = (int)($data['display_order'] ?? 0);
    $is_active     = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    // The scope this save targets: NULL = global default, else this company.
    $scopeTenant = $isDefaultCtx ? null : $activeId;

    // Name must be unique within what this company would actually see: its own
    // categories + the global defaults it hasn't hidden.
    $clashSql = $isDefaultCtx
        ? "SELECT id FROM ticket_categories WHERE tenant_id IS NULL AND LOWER(name) = LOWER(?)"
        : "SELECT id FROM ticket_categories
             WHERE LOWER(name) = LOWER(?)
               AND ( tenant_id = " . (int)$activeId . "
                     OR ( tenant_id IS NULL
                          AND id NOT IN (SELECT entity_id FROM tenant_config_hidden
                                         WHERE tenant_id = " . (int)$activeId . " AND entity_type = 'ticket_category') ) )";
    $clashParams = [$name];
    if ($id) { $clashSql .= " AND id <> ?"; $clashParams[] = $id; }
    $cs = $conn->prepare($clashSql);
    $cs->execute($clashParams);
    if ($cs->fetch()) {
        throw new Exception('A category with that name already exists here');
    }

    if ($id) {
        // Editing: confirm the row exists and that this context owns it.
        $cur = $conn->prepare("SELECT tenant_id FROM ticket_categories WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Category not found');
        }
        $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

        if ($isDefaultCtx) {
            if ($owner !== null) {
                throw new Exception("That's a company's own category — switch to that company to edit it.");
            }
        } else {
            if ($owner === null) {
                throw new Exception('Shared default categories are managed from the MSP (default) company — here you can hide them instead.');
            }
            if ($owner !== $activeId) {
                throw new Exception('That category belongs to another company.');
            }
        }

        $stmt = $conn->prepare("UPDATE ticket_categories SET name = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $description, $display_order, $is_active, $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO ticket_categories (name, description, display_order, is_active, tenant_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $display_order, $is_active, $scopeTenant]);
    }

    wf_emit('ticket_category', $id ? 'updated' : 'created', $id ? (int)$id : (int)$conn->lastInsertId(), $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
