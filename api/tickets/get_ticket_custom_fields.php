<?php
/**
 * API: List ticket custom-field definitions (with dropdown options), and —
 * when ?ticket_id is given — the current values for that ticket, hydrated by type
 * so the reading pane can render inputs in one round-trip.
 *
 * Ticket custom fields are global in v1 (entity_type='ticket', class_id NULL,
 * tenant_id NULL) — they apply to every ticket. The schema keeps tenant_id for
 * future per-company fields.
 *
 * Consumers:
 *   - the ticket form / reading pane: default call (optionally ?ticket_id=X for values)
 *   - the settings tab: same list; management writes go through save/delete endpoints
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/custom_fields.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $ticketId = isset($_GET['ticket_id']) && $_GET['ticket_id'] !== '' ? (int)$_GET['ticket_id'] : null;

    // Global ticket field definitions.
    $defStmt = $conn->prepare(
        "SELECT id, field_key, label, field_type, target_entity_type, target_class_id, is_required, display_order
           FROM custom_field_definitions
          WHERE entity_type = 'ticket' AND class_id IS NULL AND tenant_id IS NULL AND is_active = 1
       ORDER BY display_order, id"
    );
    $defStmt->execute();
    $defs = $defStmt->fetchAll(PDO::FETCH_ASSOC);

    // Options per field (dropdowns).
    $optStmt = $conn->query(
        "SELECT o.field_id, o.option_value, o.colour, o.display_order
           FROM custom_field_options o
           JOIN custom_field_definitions p ON p.id = o.field_id
          WHERE p.entity_type = 'ticket' AND p.class_id IS NULL AND p.tenant_id IS NULL
       ORDER BY o.display_order, o.id"
    );
    $optionsByField = [];
    foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $optionsByField[(int)$o['field_id']][] = [
            'value'  => $o['option_value'],
            'colour' => $o['colour'],
        ];
    }

    // Current values for a specific ticket (optional), keyed by field_id.
    $valuesByField = [];
    if ($ticketId !== null) {
        $valuesByField = CustomFieldsService::readValues($conn, 'ticket', $ticketId);
    }

    $fields = [];
    foreach ($defs as $def) {
        $fid = (int)$def['id'];
        $entry = [
            'id'                => $fid,
            'field_key'         => $def['field_key'],
            'label'             => $def['label'],
            'field_type'        => $def['field_type'],
            'target_entity_type'=> $def['target_entity_type'],
            'target_class_id'   => $def['target_class_id'] !== null ? (int)$def['target_class_id'] : null,
            'is_required'       => (int)$def['is_required'] === 1,
            'display_order'     => (int)$def['display_order'],
            'options'           => $optionsByField[$fid] ?? [],
            'value'             => null,
            'value_object'      => null,
        ];

        if ($ticketId !== null && isset($valuesByField[$fid])) {
            $v = $valuesByField[$fid];
            switch ($def['field_type']) {
                case 'text':
                case 'dropdown':   $entry['value'] = $v['value_text']; break;
                case 'number':     $entry['value'] = $v['value_number'] !== null ? (float)$v['value_number'] : null; break;
                case 'date':       $entry['value'] = $v['value_date']; break;
                case 'boolean':    $entry['value'] = $v['value_boolean'] !== null ? ((int)$v['value_boolean'] === 1) : null; break;
                case 'object_ref':
                    $entry['value'] = $v['value_ref_id'] !== null ? (int)$v['value_ref_id'] : null;
                    if ($v['value_ref_id'] !== null) {
                        $entry['value_object'] = [
                            'id'         => (int)$v['value_ref_id'],
                            'name'       => $v['value_object_name'],
                            'class_name' => $v['value_object_class_name'],
                        ];
                    }
                    break;
            }
        }

        $fields[] = $entry;
    }

    echo json_encode(['success' => true, 'custom_fields' => $fields]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
