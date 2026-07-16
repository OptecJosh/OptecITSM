<?php
/**
 * API Endpoint: Delete ticket category — multi-tenancy aware.
 *
 * Mirrors delete_ticket_type.php:
 *   - Single-company / MSP-Default context → deletes a global default category.
 *   - Client company context → you can only delete THAT company's own
 *     categories; shared defaults are hidden (not deleted) per company.
 *
 * Blocked if open tickets still use the category (in this company), or if the
 * category has any subcategories (delete/reassign those first — deleting the
 * parent out from under them would orphan every ticket filed against them).
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
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $cur = $conn->prepare("SELECT tenant_id FROM ticket_categories WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Category not found');
    }
    $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

    if ($isDefaultCtx) {
        if ($owner !== null) {
            throw new Exception("That's a company's own category — switch to that company to delete it.");
        }
    } else {
        if ($owner === null) {
            throw new Exception('Shared default categories are managed from the MSP (default) company — here you can hide it from this company instead.');
        }
        if ($owner !== $activeId) {
            throw new Exception('That category belongs to another company.');
        }
        // In-use guard: don't pull a category out from under open tickets in this company.
        $g = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND category_id = ? AND closed_datetime IS NULL");
        $g->execute([$activeId, $id]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Open tickets still use this category — reassign or close them first.');
        }
    }

    $subCount = $conn->prepare("SELECT COUNT(*) FROM ticket_subcategories WHERE category_id = ?");
    $subCount->execute([$id]);
    if ((int)$subCount->fetchColumn() > 0) {
        throw new Exception('This category still has subcategories — delete or reassign them first.');
    }

    $name = $conn->query("SELECT name FROM ticket_categories WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM ticket_categories WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('ticket_category', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
