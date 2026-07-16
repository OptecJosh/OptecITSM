<?php
/**
 * CustomFieldsService — the shared, entity-typed custom-attribute engine.
 *
 * This is the generalization of what used to be private to CmdbService: typed
 * property definitions (text/number/date/boolean/dropdown/object_ref), dropdown
 * option lists, and strongly-typed value storage with per-type validation.
 * One engine now serves every module — CMDB objects, tickets, and any future
 * entity — over the shared custom_field_definitions / _options / _values tables,
 * discriminated by `entity_type`.
 *
 * Behaviour is a faithful port of CmdbService's original property logic, so the
 * CMDB write path is unchanged after it delegates here:
 *   - values stored strongly typed (numbers numeric, dropdowns against the option
 *     list, dates parsed, object_ref existence + target-class + no self-reference)
 *   - required fields enforced on create, and on touched fields on update
 *   - unknown field keys -> 422
 *
 * object_ref today only targets CMDB objects (`target_entity_type = 'cmdb_object'`),
 * whether the owning entity is a cmdb_object or a ticket. The self-reference guard
 * therefore only applies when the owning entity IS a cmdb_object.
 */

require_once __DIR__ . '/../service_context.php';

class CustomFieldsService
{
    /**
     * Field definitions for a CMDB class, keyed by field_key.
     * (entity_type = 'cmdb_object', scoped to one class.)
     */
    public static function fieldDefsForClass(PDO $conn, int $classId): array
    {
        $stmt = $conn->prepare(
            "SELECT id, field_key, label, field_type, target_entity_type, target_class_id, is_required
               FROM custom_field_definitions
              WHERE entity_type = 'cmdb_object' AND class_id = ?
           ORDER BY display_order, id"
        );
        $stmt->execute([$classId]);
        return self::keyById($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Field definitions that apply to a ticket, keyed by field_key: the global
     * defaults (tenant_id IS NULL) plus the ticket's own company's fields. When a
     * company field shares a key with a global one, the company's wins.
     */
    public static function fieldDefsForTicket(PDO $conn, ?int $tenantId): array
    {
        if ($tenantId === null) {
            $stmt = $conn->prepare(
                "SELECT id, field_key, label, field_type, target_entity_type, target_class_id, is_required, tenant_id
                   FROM custom_field_definitions
                  WHERE entity_type = 'ticket' AND class_id IS NULL AND is_active = 1 AND tenant_id IS NULL
               ORDER BY display_order, id"
            );
            $stmt->execute();
        } else {
            $stmt = $conn->prepare(
                "SELECT id, field_key, label, field_type, target_entity_type, target_class_id, is_required, tenant_id
                   FROM custom_field_definitions
                  WHERE entity_type = 'ticket' AND class_id IS NULL AND is_active = 1
                        AND (tenant_id IS NULL OR tenant_id = ?)
               ORDER BY (tenant_id IS NULL) DESC, display_order, id"
            );
            $stmt->execute([$tenantId]);
        }
        // ORDER puts globals first so a same-key company field overwrites it below.
        return self::keyById($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Key a def list by field_key (later entries overwrite earlier — see fieldDefsForTicket). */
    private static function keyById(array $rows): array
    {
        $defs = [];
        foreach ($rows as $d) {
            $defs[$d['field_key']] = $d;
        }
        return $defs;
    }

    /** Dropdown option values for one field (by id), in display order. */
    public static function fieldOptionValues(PDO $conn, int $fieldId): array
    {
        $stmt = $conn->prepare("SELECT option_value FROM custom_field_options WHERE field_id = ? ORDER BY display_order, id");
        $stmt->execute([$fieldId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Validate + write values for one entity. $values is keyed by field_key;
     * $defs is the scoped definition map (from fieldDefsFor*). Unknown key -> 422.
     * An empty/null value clears that field. Wipe-then-insert per field, so the
     * caller should already be inside a transaction.
     */
    public static function writeValues(PDO $conn, string $entityType, int $entityId, array $defs, array $values): void
    {
        $del = $conn->prepare("DELETE FROM custom_field_values WHERE entity_type = ? AND entity_id = ? AND field_id = ?");
        $ins = $conn->prepare(
            "INSERT INTO custom_field_values
                 (entity_type, entity_id, field_id, value_text, value_number, value_date, value_boolean, value_ref_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($values as $key => $rawValue) {
            if (!isset($defs[$key])) {
                throw new ServiceError('validation', 'invalid_field', "Unknown custom field '{$key}' for this {$entityType}.");
            }
            $def = $defs[$key];
            $fid = (int)$def['id'];
            $del->execute([$entityType, $entityId, $fid]);

            if ($rawValue === null || $rawValue === '') {
                continue; // clear this field
            }

            $vText = null; $vNumber = null; $vDate = null; $vBool = null; $vRef = null;
            switch ($def['field_type']) {
                case 'text':
                    $vText = (string)$rawValue;
                    break;
                case 'dropdown':
                    $vText = (string)$rawValue;
                    $allowed = self::fieldOptionValues($conn, $fid);
                    if ($allowed && !in_array($vText, $allowed, true)) {
                        throw new ServiceError('validation', 'invalid_field', "Field '{$def['label']}' must be one of: " . implode(', ', $allowed));
                    }
                    break;
                case 'number':
                    if (!is_numeric($rawValue)) {
                        throw new ServiceError('validation', 'invalid_field', "Field '{$def['label']}' expects a number.");
                    }
                    $vNumber = (float)$rawValue;
                    break;
                case 'date':
                    $vDate = self::parseDate((string)$rawValue, $key);
                    break;
                case 'boolean':
                    $vBool = ($rawValue === true || $rawValue === 1 || $rawValue === '1' || $rawValue === 'true') ? 1 : 0;
                    break;
                case 'object_ref':
                    $vRef = (int)$rawValue;
                    if ($vRef <= 0) {
                        continue 2;
                    }
                    $targetType = !empty($def['target_entity_type']) ? $def['target_entity_type'] : 'cmdb_object';
                    if ($targetType !== 'cmdb_object') {
                        throw new ServiceError('validation', 'invalid_field', "Field '{$def['label']}' has an unsupported reference target.");
                    }
                    // Self-reference is only possible when the owning entity is itself a CMDB object.
                    if ($entityType === 'cmdb_object' && $vRef === $entityId) {
                        throw new ServiceError('validation', 'invalid_field', "Field '{$def['label']}' can't reference its own object.");
                    }
                    $rs = $conn->prepare("SELECT class_id FROM cmdb_objects WHERE id = ?");
                    $rs->execute([$vRef]);
                    $refClassId = $rs->fetchColumn();
                    if ($refClassId === false) {
                        throw new ServiceError('validation', 'invalid_field', "Field '{$def['label']}' references an object that doesn't exist.");
                    }
                    if ($def['target_class_id'] !== null && (int)$refClassId !== (int)$def['target_class_id']) {
                        throw new ServiceError('validation', 'invalid_field', "Field '{$def['label']}' can only reference objects of its target class.");
                    }
                    break;
                default:
                    throw new ServiceError('validation', 'invalid_field', "Unknown field type: {$def['field_type']}");
            }

            $ins->execute([$entityType, $entityId, $fid, $vText, $vNumber, $vDate, $vBool, $vRef]);
        }
    }

    /**
     * Required-field enforcement — create/update asymmetry. On create, every
     * required field must be present and non-empty. On update, only fields the
     * caller actually touched are checked (inline-edit friendly).
     */
    public static function checkRequired(array $defs, array $values, bool $isCreate): void
    {
        foreach ($defs as $key => $def) {
            if ((int)$def['is_required'] !== 1) {
                continue;
            }
            if (array_key_exists($key, $values)) {
                $v = $values[$key];
                if ($v === null || $v === '' || (is_array($v) && empty($v))) {
                    throw new ServiceError('validation', 'missing_field', "Required field missing: {$def['label']}");
                }
            } elseif ($isCreate) {
                throw new ServiceError('validation', 'missing_field', "Required field missing: {$def['label']}");
            }
        }
    }

    /**
     * Current values for one entity, keyed by field_id, each row carrying the
     * typed value columns plus the resolved object_ref target name/class (for
     * rendering). Mirrors the shape the CMDB detail page expects.
     */
    public static function readValues(PDO $conn, string $entityType, int $entityId): array
    {
        $stmt = $conn->prepare(
            "SELECT v.field_id, v.value_text, v.value_number, v.value_date, v.value_boolean, v.value_ref_id,
                    refo.name AS value_object_name, refoc.name AS value_object_class_name
               FROM custom_field_values v
          LEFT JOIN cmdb_objects refo ON refo.id = v.value_ref_id
          LEFT JOIN cmdb_classes refoc ON refoc.id = refo.class_id
              WHERE v.entity_type = ? AND v.entity_id = ?"
        );
        $stmt->execute([$entityType, $entityId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $out[(int)$v['field_id']] = $v;
        }
        return $out;
    }

    /**
     * Present a typed value for display/audit given a definition + a raw value row
     * (from readValues). Returns a scalar string or null. Used for the ticket
     * audit trail and the reading-pane payload.
     */
    public static function presentValue(array $def, ?array $valueRow): ?string
    {
        if ($valueRow === null) {
            return null;
        }
        switch ($def['field_type']) {
            case 'text':
            case 'dropdown':
                return $valueRow['value_text'];
            case 'number':
                return $valueRow['value_number'] !== null ? (string)(float)$valueRow['value_number'] : null;
            case 'date':
                return $valueRow['value_date'];
            case 'boolean':
                return $valueRow['value_boolean'] !== null ? ((int)$valueRow['value_boolean'] === 1 ? 'Yes' : 'No') : null;
            case 'object_ref':
                return $valueRow['value_ref_id'] !== null ? ($valueRow['value_object_name'] ?? ('#' . (int)$valueRow['value_ref_id'])) : null;
        }
        return null;
    }

    /** Parse a date/time to 'Y-m-d H:i:s' UTC (throwing; 400 on bad input). */
    public static function parseDate(string $value, string $field): string
    {
        $v = trim($value);
        try {
            $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new ServiceError('bad_request', 'invalid_parameter', "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }
}
