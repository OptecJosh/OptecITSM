<?php
/**
 * API Endpoint: Update a single field on an asset (type or status)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $asset_id = $data['asset_id'] ?? null;
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? null;

    if (!$asset_id) {
        throw new Exception('Asset ID is required');
    }

    // Whitelist allowed fields to prevent SQL injection
    $allowedFields = ['asset_type_id', 'asset_status_id', 'location_id',
                      'purchase_date', 'purchase_cost', 'supplier_id', 'order_number', 'warranty_expiry'];
    if (!in_array($field, $allowedFields)) {
        throw new Exception('Invalid field');
    }

    // Convert empty string to null
    if ($value === '' || $value === null) {
        $value = null;
    }

    $conn = connectToDatabase();

    // Get the current value before updating
    $oldStmt = $conn->prepare("SELECT $field FROM assets WHERE id = ?");
    $oldStmt->execute([$asset_id]);
    $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
    $oldValue = $oldRow ? $oldRow[$field] : null;

    $sql = "UPDATE assets SET $field = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$value, $asset_id]);

    // Resolve display names for type/status IDs
    $oldDisplay = $oldValue;
    $newDisplay = $value;
    if ($field === 'asset_type_id') {
        $nameQuery = "SELECT name FROM asset_types WHERE id = ?";
        if ($oldValue) { $n = $conn->prepare($nameQuery); $n->execute([$oldValue]); $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $oldDisplay = $r['name']; }
        if ($value)    { $n = $conn->prepare($nameQuery); $n->execute([$value]);    $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $newDisplay = $r['name']; }
    } elseif ($field === 'asset_status_id') {
        $nameQuery = "SELECT name FROM asset_status_types WHERE id = ?";
        if ($oldValue) { $n = $conn->prepare($nameQuery); $n->execute([$oldValue]); $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $oldDisplay = $r['name']; }
        if ($value)    { $n = $conn->prepare($nameQuery); $n->execute([$value]);    $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $newDisplay = $r['name']; }
    } elseif ($field === 'location_id') {
        $nameQuery = "SELECT name FROM asset_locations WHERE id = ?";
        if ($oldValue) { $n = $conn->prepare($nameQuery); $n->execute([$oldValue]); $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $oldDisplay = $r['name']; }
        if ($value)    { $n = $conn->prepare($nameQuery); $n->execute([$value]);    $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $newDisplay = $r['name']; }
    } elseif ($field === 'supplier_id') {
        $nameQuery = "SELECT COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) AS name FROM suppliers WHERE id = ?";
        if ($oldValue) { $n = $conn->prepare($nameQuery); $n->execute([$oldValue]); $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $oldDisplay = $r['name']; }
        if ($value)    { $n = $conn->prepare($nameQuery); $n->execute([$value]);    $r = $n->fetch(PDO::FETCH_ASSOC); if ($r) $newDisplay = $r['name']; }
    }

    // Log the change to asset_history. Store a stable field KEY (not an English
    // label) so the history view can translate it at render time via
    // t('asset-management.field.<key>'). Legacy rows hold English labels and are
    // shown as-is by the renderer.
    $fieldKeys = [
        'asset_type_id' => 'type', 'asset_status_id' => 'status', 'location_id' => 'location',
        'purchase_date' => 'purchase_date', 'purchase_cost' => 'purchase_cost', 'supplier_id' => 'supplier',
        'order_number' => 'order_number', 'warranty_expiry' => 'warranty_expiry',
    ];
    $fieldLabel = $fieldKeys[$field] ?? $field;
    $auditSql = "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())";
    $auditStmt = $conn->prepare($auditSql);
    $auditStmt->execute([$asset_id, $_SESSION['analyst_id'], $fieldLabel, $oldDisplay, $newDisplay]);

    // Keep the calendar's warranty events in step when a warranty date changes
    // (no-op unless the warranty-alert setting includes the calendar).
    if ($field === 'warranty_expiry') {
        require_once '../../includes/asset_warranty_calendar.php';
        try { syncAssetWarrantyCalendar($conn); } catch (Exception $syncEx) { /* non-critical */ }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
