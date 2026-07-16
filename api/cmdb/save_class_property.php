<?php
/**
 * API: Create or update a property definition on a class.
 * Handles dropdown options inline (passed as `options` array; we wipe and re-insert).
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

requireModuleAccessJson('cmdb');
requireCapabilityJson(Cap::CMDB_CLASSES);   // settings tab — see docs/design/rbac.md

const VALID_PROPERTY_TYPES = ['text', 'number', 'date', 'boolean', 'dropdown', 'object_ref'];

function slugifyProp($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'property';
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $classId = isset($data['class_id']) ? (int)$data['class_id'] : 0;
    $label = trim((string)($data['label'] ?? ''));
    $key = trim((string)($data['property_key'] ?? ''));
    $type = trim((string)($data['property_type'] ?? ''));
    $targetClassId = isset($data['target_class_id']) && $data['target_class_id'] !== '' ? (int)$data['target_class_id'] : null;
    $isRequired = !empty($data['is_required']) ? 1 : 0;
    $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;
    $options = $data['options'] ?? [];

    if ($classId <= 0) throw new Exception('class_id is required');
    if ($label === '') throw new Exception('Label is required');
    if (!in_array($type, VALID_PROPERTY_TYPES, true)) throw new Exception('Invalid property type');
    if ($type === 'object_ref' && $targetClassId === null) {
        throw new Exception('Object reference properties need a target class');
    }
    if ($type !== 'object_ref') $targetClassId = null;

    if ($key === '') {
        $key = slugifyProp($label);
    } else {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new Exception('Key may only contain lowercase letters, numbers, and underscores');
        }
    }

    $conn = connectToDatabase();
    $conn->beginTransaction();

    // Property definitions now live in the generalized custom_field_definitions
    // table (entity_type = 'cmdb_object'); property_key/type map to field_key/type.
    $targetEntityType = $type === 'object_ref' ? 'cmdb_object' : null;

    if ($id === null) {
        // Auto-resolve key collisions inside the same class
        $base = $key; $n = 2;
        $check = $conn->prepare("SELECT id FROM custom_field_definitions WHERE entity_type = 'cmdb_object' AND class_id = ? AND field_key = ?");
        while (true) {
            $check->execute([$classId, $key]);
            if (!$check->fetch()) break;
            $key = $base . '_' . $n++;
            if ($n > 50) throw new Exception('Could not generate a unique key — please supply one explicitly');
        }

        $stmt = $conn->prepare(
            "INSERT INTO custom_field_definitions
                 (entity_type, class_id, tenant_id, field_key, label, field_type, target_entity_type, target_class_id, is_required, display_order, is_active, created_datetime)
             VALUES ('cmdb_object', ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())"
        );
        $stmt->execute([$classId, $key, $label, $type, $targetEntityType, $targetClassId, $isRequired, $displayOrder]);
        $newId = (int)$conn->lastInsertId();
    } else {
        // Refuse a key change that would collide
        $check = $conn->prepare("SELECT id FROM custom_field_definitions WHERE entity_type = 'cmdb_object' AND class_id = ? AND field_key = ? AND id <> ?");
        $check->execute([$classId, $key, $id]);
        if ($check->fetch()) {
            throw new Exception('Another property in this class already uses that key');
        }

        $stmt = $conn->prepare(
            "UPDATE custom_field_definitions
                SET field_key = ?, label = ?, field_type = ?, target_entity_type = ?, target_class_id = ?, is_required = ?, display_order = ?
              WHERE id = ? AND class_id = ? AND entity_type = 'cmdb_object'"
        );
        $stmt->execute([$key, $label, $type, $targetEntityType, $targetClassId, $isRequired, $displayOrder, $id, $classId]);
        $newId = $id;
    }

    // Refresh dropdown options. Wipe + reinsert is simplest and safe inside the txn.
    // Each option may be either a plain string (legacy / from AI suggest flow)
    // or an object {value, colour} from the new row-based editor.
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
            // Validate colour as #RGB or #RRGGBB; otherwise store as null
            if ($colour !== '' && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $colour)) {
                throw new Exception('Invalid colour for option "' . $value . '" (must be a #RGB or #RRGGBB hex)');
            }
            $insOpt->execute([$newId, $value, $colour !== '' ? $colour : null, $order]);
            $order += 10;
        }
    } elseif ($type !== 'dropdown') {
        // Switching away from dropdown — clear any leftover options.
        $conn->prepare("DELETE FROM custom_field_options WHERE field_id = ?")->execute([$newId]);
    }

    $conn->commit();
    wf_emit('cmdb_property', $id === null ? 'created' : 'updated', $newId, $label);
    echo json_encode(['success' => true, 'id' => $newId, 'property_key' => $key]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
