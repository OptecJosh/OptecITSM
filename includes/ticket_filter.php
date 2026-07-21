<?php
/**
 * Shared ticket filter engine (Phase 5).
 *
 * Turns a normalised filter array into a SQL WHERE fragment + bound params,
 * against the `tickets t` / `ticket_statuses ts` aliases used by the ticket
 * list (get_emails.php), custom queues, and — later — the report builder.
 * Every clause is optional; an absent/empty field contributes nothing, so the
 * same builder serves an empty "All tickets" view and a fully-specified queue.
 *
 * Supported keys (all optional):
 *   status          string[]              ticket_statuses.name IN (...)
 *   priority_id     int[]                 t.priority_id IN (...)
 *   ticket_type_id  int[]
 *   category_id     int[]
 *   subcategory_id  int[]
 *   tenant_id       int[]                 customer / company
 *   origin_id       int[]
 *   assignee_id     int[] | 'unassigned'  t.assigned_analyst_id
 *   department_id   int[] | 'unassigned'  t.department_id
 *   created_from    'YYYY-MM-DD'          t.created_datetime >= start of day
 *   created_to      'YYYY-MM-DD'          t.created_datetime <  day after (inclusive)
 *   keyword         string                t.subject / t.ticket_number contains
 *   sla_response_state[]    string[]        ticket_sla_snapshot.response_state IN (...)
 *   sla_resolution_state[]  string[]        ticket_sla_snapshot.resolution_state IN (...)
 *
 * SLA state (Phase 8a): now a real SQL predicate, backed by the cached
 * ticket_sla_snapshot table (stamped by cron/sla_breach_check.php + the ticket
 * status-change path, rebuildable via cron/sla_snapshot_rebuild.php). Valid
 * values: ok | approaching | breached | met | na. An EXISTS subquery keeps it
 * join-free, so it composes with every other clause. Freshness is the snapshot's
 * (~5 min for open tickets); single-ticket views should still read live SLA.
 *
 * Returns [ string $sql, array $params ]. $sql is zero or more " AND (...)"
 * clauses (may be ''), safe to append after an existing WHERE. Params are
 * positional, in the same order the clauses are appended.
 */
function ticket_filter_build(array $f): array {
    $sql = '';
    $params = [];

    // Append " AND $col IN (?,?,…)" for a list of positive ints. No-op on empty.
    $addIntIn = function (string $col, $vals) use (&$sql, &$params) {
        if (!is_array($vals)) return;
        $ids = [];
        foreach ($vals as $v) {
            $n = (int)$v;
            if ($n > 0) $ids[] = $n;
        }
        if (!$ids) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND $col IN ($ph)";
        foreach ($ids as $id) $params[] = $id;
    };

    // Status by NAME — matches how the list joins ticket_statuses (ts.name).
    if (!empty($f['status']) && is_array($f['status'])) {
        $names = [];
        foreach ($f['status'] as $s) {
            $s = (string)$s;
            if ($s !== '') $names[] = $s;
        }
        if ($names) {
            $ph = implode(',', array_fill(0, count($names), '?'));
            $sql .= " AND ts.name IN ($ph)";
            foreach ($names as $n) $params[] = $n;
        }
    }

    $addIntIn('t.priority_id',    isset($f['priority_id'])    ? $f['priority_id']    : null);
    $addIntIn('t.ticket_type_id', isset($f['ticket_type_id']) ? $f['ticket_type_id'] : null);
    $addIntIn('t.category_id',    isset($f['category_id'])    ? $f['category_id']    : null);
    $addIntIn('t.subcategory_id', isset($f['subcategory_id']) ? $f['subcategory_id'] : null);
    $addIntIn('t.tenant_id',      isset($f['tenant_id'])      ? $f['tenant_id']      : null);
    $addIntIn('t.origin_id',      isset($f['origin_id'])      ? $f['origin_id']      : null);

    // Tags (M:N via ticket_tag_map) — EXISTS avoids row multiplication. A ticket
    // matches if it carries ANY of the selected tags.
    if (!empty($f['tag_id']) && is_array($f['tag_id'])) {
        $tagIds = [];
        foreach ($f['tag_id'] as $v) { $n = (int)$v; if ($n > 0) $tagIds[] = $n; }
        if ($tagIds) {
            $ph = implode(',', array_fill(0, count($tagIds), '?'));
            $sql .= " AND EXISTS (SELECT 1 FROM ticket_tag_map _tm WHERE _tm.ticket_id = t.id AND _tm.tag_id IN ($ph))";
            foreach ($tagIds as $id) $params[] = $id;
        }
    }

    // Watchers (Phase 6d) — tickets watched by any of the given analyst ids.
    // Callers resolve the literal 'me' to the session analyst id before this.
    if (!empty($f['watched_by']) && is_array($f['watched_by'])) {
        $wIds = [];
        foreach ($f['watched_by'] as $v) { $n = (int)$v; if ($n > 0) $wIds[] = $n; }
        if ($wIds) {
            $ph = implode(',', array_fill(0, count($wIds), '?'));
            $sql .= " AND EXISTS (SELECT 1 FROM ticket_watchers _w WHERE _w.ticket_id = t.id AND _w.analyst_id IN ($ph))";
            foreach ($wIds as $id) $params[] = $id;
        }
    }

    // Assignee: a list of analyst ids, or the literal 'unassigned'.
    if (isset($f['assignee_id']) && $f['assignee_id'] === 'unassigned') {
        $sql .= " AND t.assigned_analyst_id IS NULL";
    } else {
        $addIntIn('t.assigned_analyst_id', isset($f['assignee_id']) ? $f['assignee_id'] : null);
    }

    // Department: a list of ids, or the literal 'unassigned'.
    if (isset($f['department_id']) && $f['department_id'] === 'unassigned') {
        $sql .= " AND t.department_id IS NULL";
    } else {
        $addIntIn('t.department_id', isset($f['department_id']) ? $f['department_id'] : null);
    }

    // Created-date range (inclusive of the whole "to" day).
    if (!empty($f['created_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['created_from'])) {
        $sql .= " AND t.created_datetime >= ?";
        $params[] = $f['created_from'] . ' 00:00:00';
    }
    if (!empty($f['created_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['created_to'])) {
        $sql .= " AND t.created_datetime < DATE_ADD(?, INTERVAL 1 DAY)";
        $params[] = $f['created_to'] . ' 00:00:00';
    }

    // SLA state (Phase 8a) — response / resolution, each against the cached
    // snapshot. EXISTS (not a JOIN) so a ticket with no snapshot row simply
    // doesn't match, and no row multiplication. Only whitelisted state strings
    // are bound.
    $slaAllowed = ['ok', 'approaching', 'breached', 'met', 'na'];
    foreach (['sla_response_state' => 'response_state', 'sla_resolution_state' => 'resolution_state'] as $key => $col) {
        if (empty($f[$key]) || !is_array($f[$key])) continue;
        $states = [];
        foreach ($f[$key] as $s) {
            $s = (string)$s;
            if (in_array($s, $slaAllowed, true)) $states[] = $s;
        }
        if (!$states) continue;
        $ph = implode(',', array_fill(0, count($states), '?'));
        $sql .= " AND EXISTS (SELECT 1 FROM ticket_sla_snapshot _ss WHERE _ss.ticket_id = t.id AND _ss.$col IN ($ph))";
        foreach ($states as $s) $params[] = $s;
    }

    // Keyword: subject / ticket_number contains. (Requester-name match would
    // need a users join; kept to ticket columns for v1 to stay index-friendly.)
    if (!empty($f['keyword']) && is_string($f['keyword'])) {
        $kw = trim($f['keyword']);
        if ($kw !== '') {
            $like = '%' . $kw . '%';
            $sql .= " AND (t.subject LIKE ? OR t.ticket_number LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
    }

    return [$sql, $params];
}

/**
 * Count how many fields a filter array actually constrains — used to show an
 * "N filters active" badge and to decide whether a filter is worth saving.
 */
function ticket_filter_active_count(array $f): int {
    $keys = ['status','priority_id','ticket_type_id','category_id','subcategory_id','tag_id','watched_by',
             'tenant_id','origin_id','assignee_id','department_id','created_from',
             'created_to','keyword','sla_response_state','sla_resolution_state'];
    $n = 0;
    foreach ($keys as $k) {
        if (!isset($f[$k])) continue;
        $v = $f[$k];
        if (is_array($v)) { if (count($v) > 0) $n++; }
        elseif (is_string($v)) { if (trim($v) !== '') $n++; }
        elseif ($v !== null) { $n++; }
    }
    return $n;
}
