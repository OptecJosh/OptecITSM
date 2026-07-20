<?php
/**
 * Auto-assignment engine (Phase 6f).
 *
 * Picks an assignee for a newly-created, unassigned ticket based on its
 * department's configured strategy (off | round_robin | least_loaded), drawing
 * from the pool of active analysts on that department's teams. Best-effort and
 * non-throwing — it must never block ticket creation.
 *
 * Call autoassign_run($conn, $ticketId, $actorId) right after a ticket is
 * created by any path that can leave it unassigned (inbound email, self-service,
 * chat, agent/API create with no explicit assignee).
 */

/**
 * Ordered pool of eligible analyst ids: active analysts on any team assigned to
 * the department. Ordered by id so the round-robin cursor is stable.
 */
function autoassign_pool(PDO $conn, int $departmentId): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT a.id
           FROM analysts a
           JOIN analyst_teams at2 ON at2.analyst_id = a.id
           JOIN department_teams dt ON dt.team_id = at2.team_id
          WHERE dt.department_id = ? AND a.is_active = 1
       ORDER BY a.id ASC"
    );
    $stmt->execute([$departmentId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Pick the next assignee for a department per its strategy, or null if
 * auto-assign is off / unconfigured / the pool is empty. Advances the
 * round-robin cursor (and records the pick for least-loaded too).
 */
function autoassign_pick(PDO $conn, int $departmentId): ?int {
    try {
        $cfg = $conn->prepare("SELECT strategy, last_assigned_analyst_id FROM department_assignment_config WHERE department_id = ?");
        $cfg->execute([$departmentId]);
        $row = $cfg->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null; // config table not present yet (part-migrated install)
    }
    if (!$row) return null;
    $strategy = $row['strategy'] ?? 'off';
    if ($strategy !== 'round_robin' && $strategy !== 'least_loaded') return null;

    $pool = autoassign_pool($conn, $departmentId);
    if (!$pool) return null;

    if ($strategy === 'least_loaded') {
        // Whoever in the pool has the fewest open (non-closed, non-deleted)
        // tickets; ties break by pool order (id).
        $ph = implode(',', array_fill(0, count($pool), '?'));
        $cntStmt = $conn->prepare(
            "SELECT t.assigned_analyst_id AS aid, COUNT(*) AS c
               FROM tickets t
          LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
              WHERE t.assigned_analyst_id IN ($ph)
                AND t.deleted_datetime IS NULL
                AND COALESCE(ts.is_closed, 0) = 0
           GROUP BY t.assigned_analyst_id"
        );
        $cntStmt->execute($pool);
        $counts = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[(int)$r['aid']] = (int)$r['c'];
        $chosen = null; $best = PHP_INT_MAX;
        foreach ($pool as $aid) {
            $load = $counts[$aid] ?? 0;
            if ($load < $best) { $best = $load; $chosen = $aid; }
        }
        $conn->prepare("UPDATE department_assignment_config SET last_assigned_analyst_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE department_id = ?")->execute([$chosen, $departmentId]);
        return $chosen;
    }

    // round_robin: the pool member after the last-assigned one (wrapping).
    $last = $row['last_assigned_analyst_id'] !== null ? (int)$row['last_assigned_analyst_id'] : null;
    $idx = -1;
    if ($last !== null) {
        $pos = array_search($last, $pool, true);
        if ($pos !== false) $idx = $pos;
    }
    $next = $pool[($idx + 1) % count($pool)];
    $conn->prepare("UPDATE department_assignment_config SET last_assigned_analyst_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE department_id = ?")->execute([$next, $departmentId]);
    return $next;
}

/**
 * Resolve a ticket's department and, if a strategy applies, assign the ticket.
 * Only touches currently-unassigned tickets. Writes an audit row. Non-throwing.
 * Returns the assigned analyst id, or null if nothing was assigned.
 */
function autoassign_run(PDO $conn, int $ticketId, ?int $actorId = null): ?int {
    try {
        $t = $conn->prepare("SELECT department_id, assigned_analyst_id FROM tickets WHERE id = ?");
        $t->execute([$ticketId]);
        $ticket = $t->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) return null;
        if ($ticket['assigned_analyst_id'] !== null) return null;          // already assigned
        $deptId = $ticket['department_id'] !== null ? (int)$ticket['department_id'] : 0;
        if ($deptId <= 0) return null;                                     // no department → nothing to route on

        $assignee = autoassign_pick($conn, $deptId);
        if ($assignee === null) return null;

        // Guard the UPDATE with IS NULL so a concurrent assignment isn't clobbered.
        $upd = $conn->prepare("UPDATE tickets SET assigned_analyst_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ? AND assigned_analyst_id IS NULL");
        $upd->execute([$assignee, $ticketId]);
        if ($upd->rowCount() === 0) return null;                           // lost the race

        // ticket_audit.analyst_id is NOT NULL — use the actor if known, else the assignee.
        $nameStmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
        $nameStmt->execute([$assignee]);
        $name = (string)($nameStmt->fetchColumn() ?: ('#' . $assignee));
        $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, 'Assignee', NULL, ?, UTC_TIMESTAMP())")
             ->execute([$ticketId, $actorId !== null ? $actorId : $assignee, "Auto-assigned to $name"]);

        return $assignee;
    } catch (Exception $e) {
        return null; // best-effort; never block ticket creation
    }
}
