<?php
/**
 * SLA Engine — Service Level Agreement computation.
 *
 * Design: see docs/sla.md. The principle is **compute on read** — no stored
 * counters on the ticket, no background jobs, no drift. Each call walks the
 * ticket's status-change audit history, splits the lifetime into "running"
 * vs. "paused" intervals based on which statuses are flagged `pauses_sla`,
 * intersects each running interval with the priority's business calendar
 * (week pattern + holidays + timezone), and sums to get elapsed business
 * minutes.
 *
 * Two public functions:
 *   sla_get_state($conn, $ticket_id)   — returns the full SLA state of a ticket
 *   sla_business_minutes($start, $end, $calendar)
 *                                       — pure function, the intersection helper
 *
 * Both treat all DateTimes as UTC unless explicitly converted via the
 * calendar's timezone for day-walking. The DB stores everything in UTC.
 *
 * SLA POLICIES — targets are no longer read from ticket_priorities. Each ticket
 * resolves an SLA policy (sla_resolve_ticket_policy) most-specific-first: the
 * primary affected CI's device policy, else the company's assignment, else the
 * global default. The response/resolution/calendar targets then come from that
 * policy's sla_policy_targets row for the ticket's priority. This keeps
 * compute-on-read intact: resolution happens per call, exactly like the calendar
 * lookup, and no counters are stored — so linking a device with a tighter policy
 * re-resolves the target on the next read (as a priority change already does).
 */

require_once __DIR__ . '/tenancy.php';

/**
 * Resolve which SLA policy applies to a company:
 *   1. the company's own assignment (tenant_sla_policies), once effective_from
 *      has passed (a future date means "not yet on this tier"), else
 *   2. the global default policy (is_default = 1), else
 *   3. null — no SLA applies.
 *
 * Defensive: returns null rather than throwing on a part-migrated install that
 * has no policy tables yet, which surfaces as "SLA not configured".
 */
function sla_resolve_policy(PDO $conn, ?int $tenantId): ?array {
    try {
        if ($tenantId !== null) {
            $stmt = $conn->prepare(
                "SELECT p.* FROM tenant_sla_policies tsp
                   JOIN sla_policies p ON p.id = tsp.policy_id
                  WHERE tsp.tenant_id = ?
                    AND p.is_active = 1
                    AND (tsp.effective_from IS NULL OR tsp.effective_from <= UTC_DATE())
                  LIMIT 1"
            );
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        $row = $conn->query("SELECT * FROM sla_policies WHERE is_default = 1 AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;   // policy tables not present yet
    }
}

/**
 * Resolve which SLA policy drives a ticket, most-specific first:
 *   1. DEVICE — the ticket's primary affected CI (ticket_cmdb_objects.is_primary),
 *      if that CI carries its own policy (cmdb_object_sla_policies). This is the
 *      Phase 3b override: a critical box can pull a ticket onto a tighter tier.
 *   2. CUSTOMER — the company's own assignment (tenant_sla_policies).
 *   3. DEFAULT — the global default policy.
 *
 * Returns [ 'policy' => ?array, 'source' => 'device'|'customer'|'default'|null,
 *           'device' => ?['object_id'=>int,'name'=>string] ]. Only the primary
 *   CI is consulted — secondary "also affected" CIs never change the SLA, so the
 *   tier a ticket is on is always explainable by one device (or the company).
 *
 * Defensive throughout: a part-migrated install missing cmdb_object_sla_policies
 * simply falls through to the company/default resolution.
 */
function sla_resolve_ticket_policy(PDO $conn, array $ticket): array {
    $result = ['policy' => null, 'source' => null, 'device' => null];

    // --- 1. Device: primary affected CI with its own active policy ---
    try {
        $stmt = $conn->prepare(
            "SELECT p.*, o.id AS _obj_id, o.name AS _obj_name
               FROM ticket_cmdb_objects tco
               JOIN cmdb_object_sla_policies cosp ON cosp.object_id = tco.cmdb_object_id
               JOIN sla_policies p ON p.id = cosp.policy_id
               JOIN cmdb_objects o ON o.id = tco.cmdb_object_id
              WHERE tco.ticket_id = ? AND tco.is_primary = 1 AND p.is_active = 1
              LIMIT 1"
        );
        $stmt->execute([(int)$ticket['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result['device'] = ['object_id' => (int)$row['_obj_id'], 'name' => $row['_obj_name']];
            unset($row['_obj_id'], $row['_obj_name']);
            $result['policy'] = $row;
            $result['source'] = 'device';
            return $result;
        }
    } catch (Exception $e) {
        // cmdb_object_sla_policies not present yet — fall through to company/default.
    }

    // --- 2/3. Company assignment, else global default ---
    $tenantId = $ticket['tenant_id'] !== null ? (int)$ticket['tenant_id'] : getDefaultTenantId($conn);

    // Distinguish "customer" (the company has its own active assignment) from
    // "default" (falling back). Mirrors sla_resolve_policy's own condition.
    $hasTenantAssignment = false;
    if ($tenantId !== null) {
        try {
            $chk = $conn->prepare(
                "SELECT 1 FROM tenant_sla_policies tsp
                   JOIN sla_policies p ON p.id = tsp.policy_id
                  WHERE tsp.tenant_id = ? AND p.is_active = 1
                    AND (tsp.effective_from IS NULL OR tsp.effective_from <= UTC_DATE())
                  LIMIT 1"
            );
            $chk->execute([$tenantId]);
            $hasTenantAssignment = (bool)$chk->fetchColumn();
        } catch (Exception $e) { /* table absent — treat as no assignment */ }
    }

    $policy = sla_resolve_policy($conn, $tenantId);
    if ($policy) {
        $result['policy'] = $policy;
        $result['source'] = $hasTenantAssignment ? 'customer' : 'default';
    }
    return $result;
}

/**
 * Compute the SLA state of a single ticket. Returns:
 *   [
 *     'enabled'         => bool,
 *     'reason_disabled' => ?string,
 *     'policy'          => ?array (the resolved SLA policy),
 *     'priority'        => ?array,
 *     'calendar'        => ?array,
 *     'response'        => ?array (target_minutes, elapsed_minutes, remaining_minutes,
 *                                  percent, breached, achieved_at, achieved_minutes),
 *     'resolution'      => ?array (same shape as response, or null if no target),
 *   ]
 *
 * If `enabled` is false, the other fields are best-effort / informational only.
 */
function sla_get_state(PDO $conn, int $ticket_id): array {
    $state = [
        'enabled'         => false,
        'reason_disabled' => null,
        'policy'          => null,
        'policy_source'   => null,   // 'device' | 'customer' | 'default'
        'policy_device'   => null,   // ?['object_id','name'] — the primary CI, when source = 'device'
        'priority'        => null,
        'calendar'        => null,
        'response'        => null,
        'resolution'      => null,
    ];

    // --- 1. Load ticket ---
    $stmt = $conn->prepare("SELECT t.id, t.ticket_number, t.created_datetime, t.priority_id, t.status_id,
                                   t.closed_datetime, t.tenant_id, ts.name AS current_status_name
                            FROM tickets t
                            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
                            WHERE t.id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        $state['reason_disabled'] = 'Ticket not found';
        return $state;
    }

    // --- 2. Check global enforcement ---
    $settings = sla_load_settings($conn);
    if (empty($settings['sla_enforce_from'])) {
        $state['reason_disabled'] = 'SLA enforcement is disabled globally';
        return $state;
    }
    $enforceFrom = new DateTimeImmutable($settings['sla_enforce_from'], new DateTimeZone('UTC'));
    $createdAt = new DateTimeImmutable($ticket['created_datetime'], new DateTimeZone('UTC'));
    if ($createdAt < $enforceFrom) {
        $state['reason_disabled'] = 'Ticket was created before the SLA enforcement cutoff';
        return $state;
    }

    // --- 3. Resolve the SLA policy: device (primary CI) → company → default ---
    // The ticket's primary affected CI can override the company's tier; otherwise
    // the company owns it (its own assignment, else the default policy). A ticket
    // with no company (unrouted) follows the Default company.
    $resolved = sla_resolve_ticket_policy($conn, $ticket);
    $policy = $resolved['policy'];
    if (!$policy) {
        $state['reason_disabled'] = 'No SLA policy applies to this ticket and no default policy exists';
        return $state;
    }
    $state['policy']        = $policy;
    $state['policy_source'] = $resolved['source'];
    $state['policy_device'] = $resolved['device'];

    // --- 4. Load priority, and its targets under the resolved policy ---
    if (!$ticket['priority_id']) {
        $state['reason_disabled'] = 'Ticket has no priority assigned';
        return $state;
    }
    $stmt = $conn->prepare("SELECT id, name, colour FROM ticket_priorities WHERE id = ?");
    $stmt->execute([$ticket['priority_id']]);
    $priority = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$priority) {
        $state['reason_disabled'] = 'Ticket priority not found';
        return $state;
    }

    // Targets come from the policy, not the priority row. Merging them onto
    // $priority keeps its shape, so every downstream computation is unchanged.
    $tStmt = $conn->prepare("SELECT sla_response_minutes, sla_resolution_minutes, sla_calendar_id
                             FROM sla_policy_targets WHERE policy_id = ? AND priority_id = ?");
    $tStmt->execute([(int)$policy['id'], (int)$ticket['priority_id']]);
    $target = $tStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'sla_response_minutes' => null, 'sla_resolution_minutes' => null, 'sla_calendar_id' => null,
    ];
    $priority = array_merge($priority, $target);
    $state['priority'] = $priority;

    if (empty($priority['sla_response_minutes']) && empty($priority['sla_resolution_minutes'])) {
        $state['reason_disabled'] = "This priority has no SLA target in the '{$policy['name']}' policy";
        return $state;
    }

    // --- 5. Load calendar (the policy target's calendar, or default if NULL) ---
    $calId = $priority['sla_calendar_id'];
    if (!$calId) {
        $defStmt = $conn->query("SELECT id FROM sla_calendars WHERE is_default = 1 AND is_active = 1 LIMIT 1");
        $row = $defStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $calId = (int)$row['id'];
    }
    if (!$calId) {
        $state['reason_disabled'] = 'No SLA calendar set on this policy target and no default calendar exists';
        return $state;
    }
    $calendar = sla_load_calendar($conn, (int)$calId);
    if (!$calendar) {
        $state['reason_disabled'] = 'SLA calendar not found';
        return $state;
    }
    $state['calendar'] = $calendar;

    // --- 6. Build status timeline from audit log ---
    // Initial state: default status when ticket was created (audit only records changes,
    // not the initial state). If we can't find a default status, fall back to "not pausing".
    $defStatusStmt = $conn->query("SELECT name, pauses_sla FROM ticket_statuses WHERE is_default = 1 LIMIT 1");
    $defStatus = $defStatusStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => null, 'pauses_sla' => 0];

    $auditStmt = $conn->prepare("SELECT new_value, created_datetime
                                 FROM ticket_audit
                                 WHERE ticket_id = ? AND field_name = 'Status'
                                 ORDER BY created_datetime ASC, id ASC");
    $auditStmt->execute([$ticket_id]);
    $statusChanges = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

    // Map status name → pauses_sla flag (case-insensitive)
    $statusFlags = [];
    $allStatusStmt = $conn->query("SELECT name, pauses_sla, is_closed FROM ticket_statuses");
    foreach ($allStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $statusFlags[strtolower($s['name'])] = ['pauses_sla' => (bool)$s['pauses_sla'], 'is_closed' => (bool)$s['is_closed']];
    }

    // Timeline: array of { start: DateTimeImmutable UTC, status: name, pauses: bool }
    $timeline = [['start' => $createdAt, 'status' => $defStatus['name'], 'pauses' => (bool)$defStatus['pauses_sla']]];
    foreach ($statusChanges as $sc) {
        $changedAt = new DateTimeImmutable($sc['created_datetime'], new DateTimeZone('UTC'));
        $newStatus = $sc['new_value'];
        $flags = $statusFlags[strtolower($newStatus)] ?? ['pauses_sla' => false, 'is_closed' => false];
        $timeline[] = ['start' => $changedAt, 'status' => $newStatus, 'pauses' => $flags['pauses_sla']];
    }

    // --- 7. Response SLA ---
    if (!empty($priority['sla_response_minutes'])) {
        $state['response'] = sla_compute_response($conn, $ticket, $priority, $calendar, $timeline, $settings);
    }

    // --- 8. Resolution SLA ---
    if (!empty($priority['sla_resolution_minutes'])) {
        $state['resolution'] = sla_compute_resolution($ticket, $priority, $calendar, $timeline);
    }

    $state['enabled'] = true;
    return $state;
}

/**
 * Map one SLA target sub-state (the 'response' or 'resolution' array from
 * sla_get_state) to a snapshot state string + remaining minutes.
 *
 * Precedence — breach WINS over achievement: a ticket resolved *late* has both
 * `achieved_at` set and `breached` true, and its authoritative outcome is
 * 'breached', not 'met'. Order: breached → met (achieved within target) →
 * approaching (past the warning threshold) → ok. A missing target → 'na'.
 *
 * @return array{0:string,1:?int}  [state, remaining_minutes]
 */
function sla_snapshot_state_for(?array $target, float $warnThreshold): array {
    if (!$target) {
        return ['na', null];
    }
    $remaining = isset($target['remaining_minutes']) ? (int)$target['remaining_minutes'] : null;
    if (!empty($target['breached']))        return ['breached', $remaining];
    if (($target['achieved_at'] ?? null) !== null) return ['met', $remaining];
    if ((float)($target['percent'] ?? 0) >= $warnThreshold) return ['approaching', $remaining];
    return ['ok', $remaining];
}

/**
 * Upsert a ticket's SLA snapshot row (Phase 8a) from an already-computed
 * sla_get_state() result. The whole point is that the caller is *already*
 * holding the state, so stamping is near-free and drift-free by construction.
 *
 * A disabled/untracked ticket is still stamped ('na'/'na') so the cache has a
 * row that says "no SLA here" rather than a silent gap. `computed_at` is set to
 * UTC (the DB stores everything in UTC — the column DEFAULT is server-local, so
 * we set it explicitly).
 *
 * $warnThreshold lets a bulk caller (the cron loop) pass the threshold it has
 * already loaded; when omitted it's read from settings once.
 *
 * Best-effort by design — never throws. SLA reporting is a convenience layer;
 * a snapshot write failing must not break the caller (a cron run or a ticket
 * update). Returns true on success.
 */
function sla_write_snapshot(PDO $conn, int $ticketId, array $state, ?float $warnThreshold = null): bool {
    try {
        if ($warnThreshold === null) {
            $settings = sla_load_settings($conn);
            $warnThreshold = (float)($settings['sla_warning_threshold_percent'] ?? 80);
        }

        [$respState, $respRemain] = sla_snapshot_state_for($state['response'] ?? null, $warnThreshold);
        [$resoState, $resoRemain] = sla_snapshot_state_for($state['resolution'] ?? null, $warnThreshold);
        $policySource = $state['policy_source'] ?? null;

        $stmt = $conn->prepare(
            "INSERT INTO ticket_sla_snapshot
                (ticket_id, response_state, response_remaining_mins,
                 resolution_state, resolution_remaining_mins, policy_source, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                response_state = VALUES(response_state),
                response_remaining_mins = VALUES(response_remaining_mins),
                resolution_state = VALUES(resolution_state),
                resolution_remaining_mins = VALUES(resolution_remaining_mins),
                policy_source = VALUES(policy_source),
                computed_at = VALUES(computed_at)"
        );
        $stmt->execute([$ticketId, $respState, $respRemain, $resoState, $resoRemain, $policySource]);
        return true;
    } catch (Exception $e) {
        error_log('[sla_write_snapshot] ticket ' . $ticketId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Compute response-time SLA state. The clock stops at the first response,
 * where "first response" is defined per the sla_first_response_definition
 * setting. v1 supports 'status_change' (first audit row that moves status
 * away from the default) for all three options — outbound-email detection
 * is deferred to a follow-up (see docs/sla.md).
 */
function sla_compute_response(PDO $conn, array $ticket, array $priority, array $calendar, array $timeline, array $settings): array {
    $target = (int)$priority['sla_response_minutes'];
    $createdAt = $timeline[0]['start'];

    // Find first response time. For v1: first non-default status change.
    // 'outbound_email' and 'either' fall through to this same detection in v1.
    $firstResponseAt = null;
    foreach ($timeline as $i => $segment) {
        if ($i === 0) continue; // skip the implicit initial segment
        $firstResponseAt = $segment['start'];
        break;
    }

    // Clock end = first response if it happened, else now
    $clockEnd = $firstResponseAt ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $elapsed = sla_elapsed_business_minutes($timeline, $createdAt, $clockEnd, $calendar);

    $remaining = $target - $elapsed;
    $percent   = $target > 0 ? min(100, max(0, ($elapsed / $target) * 100)) : 0;
    $breached  = $elapsed > $target;

    return [
        'target_minutes'     => $target,
        'elapsed_minutes'    => $elapsed,
        'remaining_minutes'  => $remaining,
        'percent'            => round($percent, 1),
        'breached'           => $breached,
        'achieved_at'        => $firstResponseAt ? $firstResponseAt->format('Y-m-d H:i:s') : null,
        'achieved_minutes'   => $firstResponseAt ? $elapsed : null,
    ];
}

/**
 * Compute resolution-time SLA state. The clock stops at ticket close time
 * (read from tickets.closed_datetime or the first audit row that lands on a
 * status with is_closed = 1).
 */
function sla_compute_resolution(array $ticket, array $priority, array $calendar, array $timeline): array {
    $target = (int)$priority['sla_resolution_minutes'];
    $createdAt = $timeline[0]['start'];

    $closedAt = null;
    if (!empty($ticket['closed_datetime'])) {
        $closedAt = new DateTimeImmutable($ticket['closed_datetime'], new DateTimeZone('UTC'));
    } else {
        // Walk the timeline for the first transition into a closed status
        foreach ($timeline as $segment) {
            if (!empty($segment['_is_closed'])) {
                $closedAt = $segment['start'];
                break;
            }
        }
    }

    $clockEnd = $closedAt ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $elapsed = sla_elapsed_business_minutes($timeline, $createdAt, $clockEnd, $calendar);

    $remaining = $target - $elapsed;
    $percent   = $target > 0 ? min(100, max(0, ($elapsed / $target) * 100)) : 0;
    $breached  = $elapsed > $target;

    return [
        'target_minutes'     => $target,
        'elapsed_minutes'    => $elapsed,
        'remaining_minutes'  => $remaining,
        'percent'            => round($percent, 1),
        'breached'           => $breached,
        'achieved_at'        => $closedAt ? $closedAt->format('Y-m-d H:i:s') : null,
        'achieved_minutes'   => $closedAt ? $elapsed : null,
    ];
}

/**
 * Walk the status timeline, summing business minutes in intervals where the
 * status was NOT pausing. Bounded between [start, end].
 */
function sla_elapsed_business_minutes(array $timeline, DateTimeImmutable $start, DateTimeImmutable $end, array $calendar): int {
    if ($end <= $start) return 0;

    $total = 0;
    $n = count($timeline);
    for ($i = 0; $i < $n; $i++) {
        $segStart = $timeline[$i]['start'];
        $segEnd   = ($i + 1 < $n) ? $timeline[$i + 1]['start'] : $end;
        $pauses   = $timeline[$i]['pauses'];

        if ($pauses) continue; // clock was paused for this segment

        // Intersect [segStart, segEnd] with [start, end]
        $iStart = $segStart > $start ? $segStart : $start;
        $iEnd   = $segEnd   < $end   ? $segEnd   : $end;
        if ($iEnd <= $iStart) continue;

        $total += sla_business_minutes($iStart, $iEnd, $calendar);
    }

    return $total;
}

/**
 * Pure function: total business minutes in the interval [start, end] given
 * the calendar's timezone, weekly working hours, and holiday list.
 *
 * @param DateTimeImmutable $start  UTC
 * @param DateTimeImmutable $end    UTC
 * @param array $calendar           { timezone, hours: [{weekday, start_time, end_time}], holidays: [{holiday_date}] }
 */
function sla_business_minutes(DateTimeImmutable $start, DateTimeImmutable $end, array $calendar): int {
    if ($end <= $start) return 0;

    $tz = new DateTimeZone($calendar['timezone'] ?? 'UTC');
    $startLocal = $start->setTimezone($tz);
    $endLocal   = $end->setTimezone($tz);

    // Build hours lookup: weekday (1-7) => ['start' => 'HH:MM:SS', 'end' => 'HH:MM:SS']
    $hoursByWd = [];
    foreach ($calendar['hours'] ?? [] as $h) {
        $hoursByWd[(int)$h['weekday']] = [
            'start' => substr($h['start_time'], 0, 8),
            'end'   => substr($h['end_time'], 0, 8),
        ];
    }
    $holidays = [];
    foreach ($calendar['holidays'] ?? [] as $h) {
        $holidays[$h['holiday_date']] = true;
    }

    $totalSeconds = 0;
    // Walk day by day in the calendar's local time
    $cursor = $startLocal->setTime(0, 0, 0);
    $lastDay = $endLocal->setTime(0, 0, 0);
    $guard = 0; // sanity: a single SLA computation shouldn't span > ~10 years
    while ($cursor <= $lastDay && $guard++ < 4000) {
        $cursorDate = $cursor->format('Y-m-d');
        $weekday    = (int)$cursor->format('N'); // 1=Mon ... 7=Sun

        // Skip holidays and non-working days
        if (!isset($holidays[$cursorDate]) && isset($hoursByWd[$weekday])) {
            $h = $hoursByWd[$weekday];
            // Day's working window, in this calendar's tz
            list($sh, $sm, $ss) = array_pad(explode(':', $h['start']), 3, '0');
            list($eh, $em, $ee) = array_pad(explode(':', $h['end']),   3, '0');
            $dayStart = $cursor->setTime((int)$sh, (int)$sm, (int)$ss);
            $dayEnd   = $cursor->setTime((int)$eh, (int)$em, (int)$ee);

            // Intersect [dayStart, dayEnd] with [startLocal, endLocal]
            $iStart = $dayStart > $startLocal ? $dayStart : $startLocal;
            $iEnd   = $dayEnd   < $endLocal   ? $dayEnd   : $endLocal;
            if ($iEnd > $iStart) {
                $totalSeconds += $iEnd->getTimestamp() - $iStart->getTimestamp();
            }
        }
        $cursor = $cursor->modify('+1 day');
    }

    return (int)round($totalSeconds / 60);
}

/**
 * Load the seven SLA system_settings rows into a plain array.
 */
function sla_load_settings(PDO $conn): array {
    $keys = [
        'sla_enforce_from',
        'sla_priority_change_behaviour',
        'sla_reopen_behaviour',
        'sla_warning_threshold_percent',
        'sla_notify_assignee_at_warning',
        'sla_notify_lead_at_breach',
        'sla_first_response_definition',
    ];
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
    $stmt->execute($keys);
    $out = [];
    foreach ($keys as $k) $out[$k] = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

/**
 * Load a calendar with its hours + holidays nested. Returns null if not found.
 */
function sla_load_calendar(PDO $conn, int $calendar_id): ?array {
    $stmt = $conn->prepare("SELECT id, name, timezone FROM sla_calendars WHERE id = ? AND is_active = 1");
    $stmt->execute([$calendar_id]);
    $cal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cal) return null;

    $hStmt = $conn->prepare("SELECT weekday, start_time, end_time FROM sla_calendar_hours WHERE calendar_id = ? ORDER BY weekday");
    $hStmt->execute([$calendar_id]);
    $cal['hours'] = $hStmt->fetchAll(PDO::FETCH_ASSOC);

    $holStmt = $conn->prepare("SELECT holiday_date FROM sla_calendar_holidays WHERE calendar_id = ?");
    $holStmt->execute([$calendar_id]);
    $cal['holidays'] = $holStmt->fetchAll(PDO::FETCH_ASSOC);

    return $cal;
}

/**
 * Format a minute count as a short human-friendly string: "1h 30m", "45m", "2h", "-15m" (negative).
 */
function sla_format_minutes(int $minutes): string {
    $neg = $minutes < 0 ? '-' : '';
    $m = abs($minutes);
    if ($m < 60) return $neg . $m . 'm';
    $h = intdiv($m, 60);
    $r = $m % 60;
    return $neg . ($r ? "{$h}h {$r}m" : "{$h}h");
}
