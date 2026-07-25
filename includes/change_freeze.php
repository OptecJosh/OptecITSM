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
 * SCOPING (change_freeze_scopes): a window with no scope rows is global — every
 * active window applies to every change, as it did in v1. Scope rows narrow it to
 * change categories and/or companies: OR within a scope type, AND across types
 * ("the Finance company AND the Network category"). A change whose category or
 * company is unknown (an unsaved edit that hasn't picked one) is treated as
 * matching, so the soft warning errs towards showing up.
 *
 * There is no department scoping: a change carries no department, only a
 * requester and an assignee.
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
 * Scope rows for a set of freeze windows, as
 * [windowId => ['category' => [ids], 'tenant' => [ids]]]. Windows with no rows
 * are simply absent from the map (= global).
 */
function change_freeze_scopes_for(PDO $conn, array $windowIds): array {
    $windowIds = array_values(array_unique(array_map('intval', $windowIds)));
    if (!$windowIds) return [];
    try {
        $ph = implode(',', array_fill(0, count($windowIds), '?'));
        $stmt = $conn->prepare("SELECT freeze_window_id, scope_type, scope_id FROM change_freeze_scopes WHERE freeze_window_id IN ($ph)");
        $stmt->execute($windowIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['freeze_window_id']][$r['scope_type']][] = (int)$r['scope_id'];
        }
        return $out;
    } catch (Exception $e) {
        return [];   // table absent (pre-Database-Verify) → every window stays global
    }
}

/**
 * Does a window's scope cover a change in $categoryId / $tenantId? A missing
 * scope type is unrestricted; a null change value matches (conservative warning).
 */
function change_freeze_scope_matches(array $scope, ?int $categoryId, ?int $tenantId): bool {
    foreach ([['category', $categoryId], ['tenant', $tenantId]] as [$type, $value]) {
        if (empty($scope[$type])) continue;               // unrestricted on this axis
        if ($value === null) continue;                    // unknown → don't rule the window out
        if (!in_array($value, $scope[$type], true)) return false;
    }
    return true;
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
 * Active freeze windows overlapping a planned change window [$start, $end] AND
 * in scope for the change's category / company.
 *
 * Returns [] when: the change is emergency (exempt); it has no planned start
 * (nothing to conflict with); nothing overlaps; or every overlapping window is
 * scoped to other categories/companies. A null $end collapses to a point at
 * $start. Overlap test: window.starts_at <= end AND window.ends_at >= start.
 */
function change_freeze_conflicts(PDO $conn, ?string $start, ?string $end, bool $isEmergency, ?int $categoryId = null, ?int $tenantId = null): array {
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
        $windows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$windows) return [];

        $scopes = change_freeze_scopes_for($conn, array_column($windows, 'id'));
        if (!$scopes) return $windows;

        return array_values(array_filter($windows, function ($w) use ($scopes, $categoryId, $tenantId) {
            $scope = $scopes[(int)$w['id']] ?? [];
            return !$scope || change_freeze_scope_matches($scope, $categoryId, $tenantId);
        }));
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
            "SELECT work_start_datetime, work_end_datetime, change_type_id, category_id, tenant_id FROM changes WHERE id = ?"
        );
        $stmt->execute([$changeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $isEmergency = change_freeze_is_emergency_type($conn, $row['change_type_id'] !== null ? (int)$row['change_type_id'] : null);
        $conflicts = change_freeze_conflicts(
            $conn, $row['work_start_datetime'], $row['work_end_datetime'], $isEmergency,
            $row['category_id'] !== null ? (int)$row['category_id'] : null,
            $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null
        );
        if (!$conflicts) return null;

        $names = array_map(fn($w) => $w['name'], $conflicts);
        return 'This change is scheduled during a change freeze: ' . implode(', ', $names)
             . '. Reschedule, or proceed only with appropriate approval.';
    } catch (Exception $e) {
        return null;
    }
}
