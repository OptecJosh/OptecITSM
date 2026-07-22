<?php
/**
 * Change freeze / blackout windows (Phase 9b).
 *
 * A freeze window is a period during which changes should not be scheduled or
 * implemented. The guardrail is a SOFT WARNING, not a hard block: callers use
 * these helpers to detect a conflict and surface it, leaving the human to
 * decide. Emergency-type changes are exempt (an emergency is precisely the thing
 * a freeze must not stop).
 *
 * v1 is global scope — every active window applies to every change. Category /
 * department scoping is deferred.
 */

/**
 * Active freeze windows that are current or upcoming (ends_at in the future),
 * soonest first. For the calendar panel and the admin screen's "in effect" hint.
 */
function change_freeze_active_windows(PDO $conn): array {
    try {
        $stmt = $conn->query(
            "SELECT id, name, starts_at, ends_at, reason, is_active
               FROM change_freeze_windows
              WHERE is_active = 1 AND ends_at >= UTC_TIMESTAMP()
           ORDER BY starts_at ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];   // table absent (pre-Database-Verify)
    }
}

/**
 * Is this change type an emergency (and therefore freeze-exempt)? Matched by
 * name = 'Emergency' (case-insensitive), the seeded emergency type.
 */
function change_freeze_is_emergency_type(PDO $conn, ?int $changeTypeId): bool {
    if (!$changeTypeId) return false;
    try {
        $stmt = $conn->prepare("SELECT name FROM change_types WHERE id = ?");
        $stmt->execute([$changeTypeId]);
        $name = $stmt->fetchColumn();
        return $name !== false && strcasecmp(trim((string)$name), 'Emergency') === 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Active freeze windows overlapping a planned change window [$start, $end].
 *
 * Returns [] when: the change is emergency (exempt); it has no planned start
 * (nothing to conflict with); or nothing overlaps. A null $end collapses to a
 * point at $start. Overlap test: window.starts_at <= end AND window.ends_at >= start.
 */
function change_freeze_conflicts(PDO $conn, ?string $start, ?string $end, bool $isEmergency): array {
    if ($isEmergency) return [];
    if (empty($start)) return [];
    if (empty($end)) $end = $start;

    try {
        $stmt = $conn->prepare(
            "SELECT id, name, starts_at, ends_at, reason
               FROM change_freeze_windows
              WHERE is_active = 1
                AND starts_at <= ?
                AND ends_at   >= ?
           ORDER BY starts_at ASC"
        );
        $stmt->execute([$end, $start]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Convenience: build a soft-warning string for a saved change, or null if it's
 * clear (unscheduled, emergency, or no freeze overlaps its planned window).
 * Loads the change's schedule + type, then delegates to change_freeze_conflicts.
 */
function change_freeze_warning_for_change(PDO $conn, int $changeId): ?string {
    try {
        $stmt = $conn->prepare(
            "SELECT work_start_datetime, work_end_datetime, change_type_id FROM changes WHERE id = ?"
        );
        $stmt->execute([$changeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $isEmergency = change_freeze_is_emergency_type($conn, $row['change_type_id'] !== null ? (int)$row['change_type_id'] : null);
        $conflicts = change_freeze_conflicts($conn, $row['work_start_datetime'], $row['work_end_datetime'], $isEmergency);
        if (!$conflicts) return null;

        $names = array_map(fn($w) => $w['name'], $conflicts);
        return 'This change is scheduled during a change freeze: ' . implode(', ', $names)
             . '. Reschedule, or proceed only with appropriate approval.';
    } catch (Exception $e) {
        return null;
    }
}
