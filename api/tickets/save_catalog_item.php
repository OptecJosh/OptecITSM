<?php
/**
 * API (admin/analyst): create or update a service-catalog item (Phase 7c).
 * POST JSON { id?, name, description?, category_id?, department_id?, priority_id?,
 *             icon?, is_active?, display_order? }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CATEGORIES);   // catalog config sits with the taxonomy caps

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id       = !empty($data['id']) ? (int)$data['id'] : null;
    $name     = trim((string)($data['name'] ?? ''));
    $desc     = trim((string)($data['description'] ?? ''));
    $catId    = (isset($data['category_id'])   && $data['category_id']   !== '' && $data['category_id']   !== null) ? (int)$data['category_id']   : null;
    $deptId   = (isset($data['department_id']) && $data['department_id'] !== '' && $data['department_id'] !== null) ? (int)$data['department_id'] : null;
    $prioId   = (isset($data['priority_id'])   && $data['priority_id']   !== '' && $data['priority_id']   !== null) ? (int)$data['priority_id']   : null;
    $formId   = (isset($data['form_id'])       && $data['form_id']       !== '' && $data['form_id']       !== null) ? (int)$data['form_id']       : null;
    $icon     = trim((string)($data['icon'] ?? ''));
    $isActive = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;
    $order    = isset($data['display_order']) ? (int)$data['display_order'] : 0;

    if ($name === '') throw new Exception('Name is required');
    if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
    if ($icon !== '' && mb_strlen($icon) > 40) $icon = mb_substr($icon, 0, 40);

    // A form can only be attached if it exists, is active, and is the current
    // (leaf) version — a frozen version can't take submissions.
    if ($formId !== null) {
        $fchk = $conn->prepare(
            "SELECT 1 FROM forms f WHERE f.id = ? AND f.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)"
        );
        $fchk->execute([$formId]);
        if (!$fchk->fetchColumn()) throw new Exception('The selected form is not available');
    }

    if ($id) {
        $chk = $conn->prepare("SELECT 1 FROM service_catalog_items WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) throw new Exception('Catalog item not found');
        $conn->prepare(
            "UPDATE service_catalog_items SET name=?, description=?, category_id=?, department_id=?, priority_id=?, form_id=?, icon=?, is_active=?, display_order=?, updated_datetime=UTC_TIMESTAMP() WHERE id=?"
        )->execute([$name, $desc !== '' ? $desc : null, $catId, $deptId, $prioId, $formId, $icon !== '' ? $icon : null, $isActive, $order, $id]);
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO service_catalog_items (name, description, category_id, department_id, priority_id, form_id, icon, is_active, display_order, created_by_analyst_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$name, $desc !== '' ? $desc : null, $catId, $deptId, $prioId, $formId, $icon !== '' ? $icon : null, $isActive, $order, $analystId]);
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
