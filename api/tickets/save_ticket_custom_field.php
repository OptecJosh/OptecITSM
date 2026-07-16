<?php
/**
 * API: Create or update a ticket custom-field definition (with dropdown options).
 * Mirrors api/cmdb/save_class_property.php, but for global ticket fields
 * (entity_type='ticket', class_id NULL, tenant_id NULL).
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
requireCapabilityJson(Cap::TICKETS_CUSTOM_FIELDS);

const VALID_TICKET_FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'dropdown', 'object_ref'];

function slugifyField($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'field';
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $label = trim((string)($data['label'] ?? ''));
    $key = trim((string)($data['field_key'] ?? ''));
    $type = trim((string)($data['field_type'] ?? ''));
    $targetClassId = isset($data['target_class_id']) && $data['target_class_id'] !== '' ? (int)$data['target_class_id'] : null;
    $isRequired = !empty($data['is_required']) ? 1 : 0;
    $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;
    $options = $data['options'] ?? [];

    if ($label === '') throw new Exception('Label is required');
    if (!in_array($type, VALID_TICKET_FIELD_TYPES, true)) throw new Exception('Invalid field type');
    if ($type === 'object_ref' && $targetClassId === null) {
        throw new Exception('Object reference fields need a target class');
    }
    $targetEntityType = $type === 'object_ref' ? 'cmdb_object' : null;
    if ($type !== 'object_ref') $targetClassId = null;

    if ($key === '') {
        $key = slugifyField($label);
    } elseif (!preg_match('/^[a-z0-9_]+$/', $key)) {
        throw new Exception('Key may only contain lowercase letters, numbers, and underscores');
    }

    $conn = connectToDatabase();
    $conn->beginTransaction();

    if ($id === null) {
        // Auto-resolve key collisions across global ticket fields.
        $base = $key; $n = 2;
        $check = $conn->prepare("SELECT id FROM custom_field_definitions WHERE entity_type = 'ticket' AND class_id IS NULL AND tenant_id IS NULL AND field_key = ?");
        while (true) {
            $check->execute([$key]);
            if (!$check->fetch()) break;
            $key = $base . '_' . $n++;
            if ($n > 50) throw new Exception('Could not generate a unique key — please supply one explicitly');
        }
        $stmt = $conn->prepare(
            "INSERT INTO custom_field_definitions
                 (entity_type, class_id, tenant_id, field_key, label, field_type, target_entity_type, target_class_id, is_required, display_order, is_active, created_datetime)
             VALUES ('ticket', NULL, NULL, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())"
        );
        $stmt->execute([$key, $label, $type, $targetEntityType, $targetClassId, $isRequired, $displayOrder]);
        $newId = (int)$conn->lastInsertId();
    } else {
        $check = $conn->prepare("SELECT id FROM custom_field_definitions WHERE entity_type = 'ticket' AND class_id IS NULL AND tenant_id IS NULL AND field_key = ? AND id <> ?");
        $check->execute([$key, $id]);
        if ($check->fetch()) {
            throw new Exception('Another ticket field already uses that key');
        }
        $stmt = $conn->prepare(
            "UPDATE custom_field_definitions
                SET field_key = ?, label = ?, field_type = ?, target_entity_type = ?, target_class_id = ?, is_required = ?, display_order = ?
              WHERE id = ? AND entity_type = 'ticket' AND class_id IS NULL AND tenant_id IS NULL"
        );
        $stmt->execute([$key, $label, $type, $targetEntityType, $targetClassId, $isRequired, $displayOrder, $id]);
        $newId = $id;
    }

    // Refresh dropdown options (wipe + reinsert). Options may be plain strings or {value, colour}.
    if ($type === 'dropdown' && is_array($options)) {
        $conn->prepare("DELETE FROM custom_field_options WHERE field_id = ?")->execute([$newId]);
        $insOpt = $conn->prepare("INSERT INTO custom_field_options (field_id, option_value, colour, display_order) VALUES (?, ?, ?, ?)");
        $order = 0;
        foreach ($options as $opt) {
            if (is_array($opt)) {
                $value  = trim((string)($opt['value']  ?? ''));
                $colour = trim((string)($opt['colour'] ?? ''));
            } else {
                $value  = trim((string)$opt);
                $colour = '';
            }
            if ($value === '') continue;
            if ($colour !== '' && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $colour)) {
                throw new Exception('Invalid colour for option "' . $value . '" (must be a #RGB or #RRGGBB hex)');
            }
            $insOpt->execute([$newId, $value, $colour !== '' ? $colour : null, $order]);
            $order += 10;
        }
    } elseif ($type !== 'dropdown') {
        $conn->prepare("DELETE FROM custom_field_options WHERE field_id = ?")->execute([$newId]);
    }

    $conn->commit();
    wf_emit('ticket_custom_field', $id === null ? 'created' : 'updated', $newId, $label);
    echo json_encode(['success' => true, 'id' => $newId, 'field_key' => $key]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
