<?php
/**
 * Reporting demo data generator — an instrumented ticket history you can build a
 * management report on.
 *
 * Why this is not a JSON demo module: the demo-data loader takes a static file,
 * and a credible reporting demo needs ~2,000 tickets across 18 months, each with
 * its own child rows (time entries, audit trail, escalations, hold intervals, QA
 * reviews, CSAT, SLA snapshot). That is tens of thousands of rows whose dates all
 * have to be relative to today. It has to be generated, not authored.
 *
 * Why it matters that the data has SHAPE: a director report built on uniform
 * random data shows flat lines, which demonstrates nothing. Every metric here
 * moves deliberately over the period — response and resolution times fall, SLA
 * attainment and CSAT climb, reopen and escalation rates drop, volume grows with
 * a seasonal wobble — so the charts show a service improving, which is the story
 * a management pack exists to tell. The curves are declared in
 * demoReportingCurves() so they can be tuned in one place.
 *
 * IMPORTANT — configure SLA targets BEFORE generating. Cycle times are generated
 * as a multiple of each priority's own SLA target (see demoReportingTargets()),
 * so the outcome the SLA engine later computes matches the intended story. An
 * earlier version invented absolute durations instead, and on an install whose
 * targets were tighter than those durations every single ticket breached — SLA
 * attainment came out a flat 0% and the trend was worthless. A priority with no
 * target still gets plausible cycle times, but its tickets are recorded 'na'
 * and drop out of attainment entirely.
 *
 * Safety
 *   - Every generated ticket is numbered DMO-YYMM-NNNN. That prefix is the only
 *     thing purge looks at, so it can never delete a real ticket.
 *   - It writes ONLY ticket data and its children. It does not touch settings,
 *     analysts (unless assign_tiers is explicitly requested), or any lookup.
 *   - Lookups are read, never created: the generator uses the statuses,
 *     priorities and categories the install already has, and says so plainly
 *     when something it needs is missing.
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/ticket_seed_email.php';

/** Ticket-number prefix that marks a row as generated demo data. */
function demoReportingPrefix(): string { return 'DMO'; }

/** Hard ceilings, so a mistyped form can't generate a million rows. */
function demoReportingMaxMonths(): int { return 36; }
function demoReportingMaxPerMonth(): int { return 400; }

/**
 * The shape of the story, in one place.
 *
 * $m is "maturity": 0.0 at the start of the period, 1.0 at the most recent
 * month. Everything below is expressed as a function of it, so the whole
 * narrative can be re-tuned without touching the generation loop.
 */
function demoReportingCurves(float $m): array {
    return [
        // Volume grows ~50% across the period.
        'volume_factor'    => 0.78 + 0.55 * $m,
        // Cycle times are expressed as a MULTIPLE OF THE INSTALL'S OWN SLA TARGET,
        // not as absolute minutes. A median at 0.95x target breaches about half
        // the time once the long tail is applied; a median at 0.55x breaches
        // rarely. That is what moves attainment from roughly 55% to the low 90s
        // across the period, and it means the generator adapts to whatever
        // targets are configured instead of assuming any particular scale.
        // See demoReportingTargets(). Absolute fallbacks below are used only for
        // priorities that have no target at all.
        'ack_multiplier'   => 0.90 - 0.38 * $m,
        'res_multiplier'   => 0.95 - 0.40 * $m,
        // Fallback breach rate, used only when a priority has no SLA target and
        // met/breached therefore cannot be derived from one.
        'breach_rate'      => 0.22 - 0.16 * $m,
        // Satisfaction climbs from ~3.7 to ~4.6.
        'csat_mean'        => 3.70 + 0.90 * $m,
        // Rework and escalation both improve.
        'reopen_rate'      => 0.13 - 0.09 * $m,
        'escalation_rate'  => 0.24 - 0.10 * $m,
        'hold_rate'        => 0.26 - 0.08 * $m,
        // Quality: QA pass rate rises; sampling stays constant.
        'qa_pass_rate'     => 0.72 + 0.24 * $m,
        'qa_sample_rate'   => 0.12,
        // First-time fix improves.
        'ftf_rate'         => 0.42 + 0.28 * $m,
    ];
}

/** Demand is seasonal: September and January are busy, August and December are not. */
function demoReportingSeasonality(int $calendarMonth): float {
    static $s = [1 => 1.16, 2 => 1.06, 3 => 1.05, 4 => 0.95, 5 => 1.00, 6 => 0.98,
                 7 => 0.90, 8 => 0.82, 9 => 1.22, 10 => 1.12, 11 => 1.06, 12 => 0.78];
    return $s[$calendarMonth] ?? 1.0;
}

/**
 * Rank a priority from its name: 1 = most urgent … 4 = least.
 *
 * Used for both frequency and the absolute fallback times. Handles the two
 * naming styles installs actually use — words (Critical / High / Normal / Low)
 * and codes (P1…P4, Sev1…Sev4) — and treats anything unrecognised as middling,
 * which is the safe default because it neither floods the demo with fake P1s nor
 * hides a priority entirely.
 */
function demoReportingPriorityRank(string $name): int {
    $n = strtolower(trim($name));
    // Codes first: a name like "P1 - 24/7" must not fall through to the word
    // matching below and end up ranked as a mid-priority.
    if (preg_match('/\b(?:p|sev|sev\.|severity|tier)\s*([1-4])\b/', $n, $m)) return (int)$m[1];
    if (str_contains($n, 'critical') || str_contains($n, 'urgent') || str_contains($n, 'emergency')) return 1;
    if (str_contains($n, 'high')) return 2;
    if (str_contains($n, 'low') || str_contains($n, 'minor') || str_contains($n, 'planning')) return 4;
    return 3;   // normal / medium / anything unrecognised
}

/**
 * Absolute [ack, resolve] minutes, used ONLY when a priority has no SLA target
 * to scale against. Kept deliberately generous — with no target there is nothing
 * to breach, so these only need to look plausible in the cycle-time charts.
 */
function demoReportingPriorityBase(string $name): array {
    switch (demoReportingPriorityRank($name)) {
        case 1:  return [9, 300];
        case 2:  return [25, 700];
        case 4:  return [180, 4600];
        default: return [65, 1700];
    }
}

/** Relative frequency of each priority: a healthy desk is mostly P3/P4. */
function demoReportingPriorityWeight(string $name): int {
    switch (demoReportingPriorityRank($name)) {
        case 1:  return 5;
        case 2:  return 20;
        case 4:  return 25;
        default: return 50;
    }
}

/**
 * The install's own SLA targets, per priority id.
 *
 * Reads the DEFAULT policy's targets (sla_policy_targets), which is what the SLA
 * engine resolves for a ticket with no company- or device-specific override, and
 * falls back to the legacy per-priority columns for installs that predate
 * policies. Returns [priority_id => ['response' => ?int, 'resolution' => ?int]].
 *
 * This is the whole point of the rework: generated cycle times are scaled to
 * these numbers, so the SLA outcomes the engine later computes agree with the
 * story the data is meant to tell. Previously the generator invented absolute
 * durations and a separate breach rate, and if the install's targets happened to
 * be tighter than those durations every single ticket breached — which made SLA
 * attainment a flat zero and the whole trend useless.
 */
function demoReportingTargets(PDO $conn): array {
    $out = [];

    // Legacy per-priority columns first, so a policy target can overwrite them.
    try {
        foreach ($conn->query("SELECT id, sla_response_minutes, sla_resolution_minutes FROM ticket_priorities")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = [
                'response'   => $r['sla_response_minutes']   !== null ? (int)$r['sla_response_minutes']   : null,
                'resolution' => $r['sla_resolution_minutes'] !== null ? (int)$r['sla_resolution_minutes'] : null,
            ];
        }
    } catch (Exception $e) { /* pre-SLA install */ }

    try {
        $rows = $conn->query(
            "SELECT t.priority_id, t.sla_response_minutes, t.sla_resolution_minutes
               FROM sla_policy_targets t
               JOIN sla_policies p ON p.id = t.policy_id
              WHERE p.is_default = 1 AND p.is_active = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[(int)$r['priority_id']] = [
                'response'   => $r['sla_response_minutes']   !== null ? (int)$r['sla_response_minutes']   : null,
                'resolution' => $r['sla_resolution_minutes'] !== null ? (int)$r['sla_resolution_minutes'] : null,
            ];
        }
    } catch (Exception $e) { /* policy tables absent */ }

    return $out;
}

/**
 * Pick a key from [key => weight]. Returns null for an empty or zero-weight set,
 * so callers must handle "nothing to pick".
 */
function demoWeightedPick(array $weights) {
    $total = array_sum($weights);
    if ($total <= 0) return null;
    $r = mt_rand(1, (int)$total);
    foreach ($weights as $k => $w) {
        $r -= $w;
        if ($r <= 0) return $k;
    }
    return array_key_first($weights);
}

/** Uniform pick from a list, or null when empty. */
function demoPickOne(array $list) {
    return $list ? $list[mt_rand(0, count($list) - 1)] : null;
}

/**
 * A log-normal-ish positive jitter around 1.0. Real cycle times are skewed —
 * a few tickets take far longer than the rest — and a symmetric jitter would
 * produce an unrealistically tidy distribution and a suspiciously clean chart.
 */
function demoSkewedFactor(): float {
    $u = mt_rand(1, 999) / 1000;
    $f = exp(($u - 0.5) * 1.4);
    return max(0.15, min(6.0, $f));
}

/** Business-hours-weighted timestamp inside a given month. */
function demoReportingCreatedAt(int $year, int $month, float $outOfHoursRate): string {
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    // Try a few times for a weekday; fall through to whatever we last drew so an
    // all-weekend draw can still terminate.
    $day = mt_rand(1, $daysInMonth);
    for ($i = 0; $i < 6; $i++) {
        $dow = (int)date('N', mktime(0, 0, 0, $month, $day, $year));
        if ($dow <= 5) break;
        $day = mt_rand(1, $daysInMonth);
    }
    $outOfHours = (mt_rand(1, 1000) / 1000) < $outOfHoursRate;
    if ($outOfHours) {
        $hour = demoPickOne([0, 1, 2, 3, 4, 5, 6, 7, 19, 20, 21, 22, 23]);
    } else {
        // Two humps: mid-morning and just after lunch, like a real service desk.
        $hour = demoWeightedPick([
            8 => 8, 9 => 16, 10 => 18, 11 => 14, 12 => 8,
            13 => 10, 14 => 15, 15 => 13, 16 => 11, 17 => 6,
        ]);
    }
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day,
        (int)$hour, mt_rand(0, 59), mt_rand(0, 59));
}

/**
 * Read the lookups the generator needs.
 *
 * Returns ['ok' => bool, 'missing' => [...], ...lists]. Nothing is created: a
 * generator that invents statuses would leave the install in a state the admin
 * never chose.
 */
function demoReportingLookups(PDO $conn): array {
    $q = function (string $sql) use ($conn): array {
        try { return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (Exception $e) { return []; }
    };

    $statuses = $q("SELECT id, name, is_closed FROM ticket_statuses ORDER BY display_order, id");
    $out = [
        'statuses_open'   => array_values(array_filter($statuses, fn($s) => !(int)$s['is_closed'])),
        'statuses_closed' => array_values(array_filter($statuses, fn($s) => (int)$s['is_closed'])),
        'priorities'      => $q("SELECT id, name, sla_response_minutes, sla_resolution_minutes FROM ticket_priorities ORDER BY display_order, id"),
        'types'           => $q("SELECT id, name FROM ticket_types ORDER BY display_order, id"),
        'categories'      => $q("SELECT id, name FROM ticket_categories ORDER BY display_order, id"),
        'origins'         => $q("SELECT id, name FROM ticket_origins ORDER BY display_order, id"),
        'streams'         => $q("SELECT id, name FROM ticket_streams ORDER BY id"),
        'departments'     => $q("SELECT id, name FROM departments ORDER BY id"),
        'analysts'        => $q("SELECT id, full_name, tier, loaded_rate FROM analysts WHERE is_active = 1 OR is_active IS NULL"),
        'customers'       => $q("SELECT id, name FROM customers WHERE is_active = 1"),
        // email + display_name so the seeded initial email has a real sender —
        // that address is what the ticket list shows in the From column.
        'users'           => $q("SELECT id, email, display_name FROM users LIMIT 500"),
    ];
    // Cycle times are generated relative to these, so they must be read before
    // any ticket is built.
    $out['targets'] = demoReportingTargets($conn);

    $missing = [];
    if (!$out['statuses_open'])   $missing[] = 'an open ticket status';
    if (!$out['statuses_closed']) $missing[] = 'a closed ticket status (one with is_closed = 1)';
    if (!$out['priorities'])      $missing[] = 'ticket priorities';
    if (!$out['analysts'])        $missing[] = 'at least one active analyst';

    // The KPI instrumentation columns are what make this history worth
    // generating. Check them up front so a pre-KPI install gets a sentence it can
    // act on instead of a raw SQL error halfway through 2,000 inserts.
    try {
        $cols = array_map(fn($r) => $r['Field'], $conn->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_ASSOC));
        foreach (['acknowledged_datetime', 'stream_id', 'playbook_eligible', 'customer_id'] as $need) {
            if (!in_array($need, $cols, true)) {
                $missing[] = "the tickets.{$need} column (run Database Verify to add the KPI instrumentation)";
            }
        }
    } catch (Exception $e) {
        $missing[] = 'a readable tickets table';
    }

    $out['missing'] = $missing;
    $out['ok'] = !$missing;
    return $out;
}

/**
 * Delete every generated demo ticket.
 *
 * Matches on the DMO- ticket-number prefix only. Child rows go with the ticket
 * through the ON DELETE CASCADE foreign keys added by Database Verify; the
 * explicit deletes below cover installs where a cascade FK failed to apply, so
 * a purge doesn't strand orphans.
 */
function demoReportingPurge(PDO $conn): array {
    $prefix = demoReportingPrefix() . '-%';
    $ids = $conn->prepare("SELECT id FROM tickets WHERE ticket_number LIKE ?");
    $ids->execute([$prefix]);
    $ticketIds = $ids->fetchAll(PDO::FETCH_COLUMN);
    if (!$ticketIds) return ['tickets' => 0, 'children' => 0];

    $children = 0;
    // Chunked so a large purge doesn't build a 2,000-placeholder statement.
    foreach (array_chunk($ticketIds, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (['ticket_time_entries', 'ticket_notes', 'ticket_audit', 'ticket_csat_responses',
                  'ticket_escalations', 'ticket_hold_events', 'ticket_qa_reviews',
                  'ticket_sla_snapshot'] as $table) {
            try {
                $st = $conn->prepare("DELETE FROM {$table} WHERE ticket_id IN ({$ph})");
                $st->execute($chunk);
                $children += $st->rowCount();
            } catch (Exception $e) { /* table may not exist on this install */ }
        }
        $st = $conn->prepare("DELETE FROM tickets WHERE id IN ({$ph})");
        $st->execute($chunk);
    }
    return ['tickets' => count($ticketIds), 'children' => $children];
}

/**
 * Give analysts a tier, loaded rate and contracted hours where they have none.
 *
 * Opt-in only. Tier drives every KPI split and cost-per-ticket needs a rate, so
 * without these the demo report has a large blank in it — but these are real
 * fields on real staff records, so the caller has to ask.
 */
function demoReportingAssignTiers(PDO $conn): int {
    $rows = $conn->query("SELECT id FROM analysts WHERE tier IS NULL OR tier = ''")->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) return 0;
    // A realistic pyramid: mostly L1, fewer L2, a couple of L3.
    $plan = [['L1', 26.00, 37.5], ['L1', 24.50, 37.5], ['L1', 27.00, 37.5],
             ['L2', 34.00, 37.5], ['L2', 36.50, 37.5], ['L3', 48.00, 37.5]];
    $st = $conn->prepare("UPDATE analysts SET tier = ?, loaded_rate = COALESCE(loaded_rate, ?), contracted_weekly_hours = COALESCE(contracted_weekly_hours, ?) WHERE id = ?");
    $n = 0;
    foreach ($rows as $i => $id) {
        [$tier, $rate, $hours] = $plan[$i % count($plan)];
        $st->execute([$tier, $rate, $hours, $id]);
        $n++;
    }
    return $n;
}

/**
 * Generate the history.
 *
 * @param  array $opts months, per_month, seed, assign_tiers, purge_first
 * @return array counts + notes for the caller to display
 */
function demoReportingGenerate(PDO $conn, int $analystId, array $opts): array {
    $months   = max(1, min(demoReportingMaxMonths(), (int)($opts['months'] ?? 18)));
    $perMonth = max(1, min(demoReportingMaxPerMonth(), (int)($opts['per_month'] ?? 120)));
    $seed     = isset($opts['seed']) && $opts['seed'] !== '' ? (int)$opts['seed'] : null;

    // A seed makes a demo reproducible, which matters when you are showing the
    // same numbers to the same people twice.
    mt_srand($seed ?? random_int(1, PHP_INT_MAX));

    $look = demoReportingLookups($conn);
    if (!$look['ok']) {
        throw new Exception('Cannot generate: this install is missing ' . implode(', ', $look['missing'])
            . '. Run Database Verify, and import the core demo module or create these in Settings first.');
    }

    $notes = [];
    $tiersSet = 0;
    if (!empty($opts['assign_tiers'])) {
        $tiersSet = demoReportingAssignTiers($conn);
        if ($tiersSet) {
            $notes[] = "Set a tier, loaded rate and contracted hours on {$tiersSet} analyst(s) that had none.";
            $look = demoReportingLookups($conn);   // re-read so tiers are visible below
        }
    }

    $purged = ['tickets' => 0, 'children' => 0];
    if (!empty($opts['purge_first'])) {
        $purged = demoReportingPurge($conn);
        if ($purged['tickets']) $notes[] = "Removed {$purged['tickets']} previously generated demo ticket(s).";
    }

    $withTier = array_values(array_filter($look['analysts'], fn($a) => !empty($a['tier'])));
    if (!$withTier) {
        $notes[] = 'No analyst has a tier set, so every tier-split KPI will be blank. '
                 . 'Re-run with "assign tiers" ticked, or set tiers in System → Analysts.';
        $withTier = $look['analysts'];
    }
    $byTier = ['L1' => [], 'L2' => [], 'L3' => []];
    foreach ($withTier as $a) {
        $t = $a['tier'] ?: 'L1';
        if (isset($byTier[$t])) $byTier[$t][] = $a;
    }
    foreach ($byTier as $t => $list) { if (!$list) $byTier[$t] = $withTier; }

    if (!$look['customers']) $notes[] = 'No active customers exist, so the customer dimension will be empty.';
    if (!$look['users'])     $notes[] = 'No portal users exist, so tickets will have no requester.';

    $tenantId = null;
    try { $tenantId = getActiveTenantId($conn, $analystId); } catch (Exception $e) { /* single-tenant */ }

    // Weighted lookup sets, computed once.
    $prioWeights = [];
    foreach ($look['priorities'] as $i => $p) $prioWeights[$i] = demoReportingPriorityWeight($p['name']);
    // Front-load the category and type distributions: a real desk has a few very
    // common categories and a long tail, not an even spread.
    $catWeights = [];
    foreach ($look['categories'] as $i => $c) $catWeights[$i] = max(1, 40 - $i * 5);
    $typeWeights = [];
    foreach ($look['types'] as $i => $t) $typeWeights[$i] = max(1, 30 - $i * 6);

    $stmts = demoReportingPrepare($conn);

    $counts = ['tickets' => 0, 'time_entries' => 0, 'audit' => 0, 'escalations' => 0,
               'holds' => 0, 'qa' => 0, 'csat' => 0, 'sla_snapshots' => 0, 'notes_rows' => 0];

    $conn->beginTransaction();
    try {
        $seq = 0;
        for ($mi = 0; $mi < $months; $mi++) {
            // mi = 0 is the OLDEST month, so maturity rises with mi.
            $monthsAgo = $months - 1 - $mi;
            $maturity  = $months > 1 ? $mi / ($months - 1) : 1.0;
            $curves    = demoReportingCurves($maturity);

            $base = new DateTime('first day of this month', new DateTimeZone('UTC'));
            $base->modify("-{$monthsAgo} months");
            $year  = (int)$base->format('Y');
            $month = (int)$base->format('n');
            $isCurrentMonth = ($monthsAgo === 0);

            $target = (int)round($perMonth * $curves['volume_factor'] * demoReportingSeasonality($month));
            // Part-way through the current month, only expect part of its volume.
            if ($isCurrentMonth) {
                $frac = (int)date('j') / (int)date('t');
                $target = max(1, (int)round($target * $frac));
            }

            for ($k = 0; $k < $target; $k++) {
                $seq++;
                $counts['tickets']++;
                demoReportingOneTicket($conn, $stmts, $counts, [
                    'seq' => $seq, 'year' => $year, 'month' => $month,
                    'curves' => $curves, 'maturity' => $maturity,
                    'is_current_month' => $isCurrentMonth,
                    'look' => $look, 'byTier' => $byTier, 'tenantId' => $tenantId,
                    'prioWeights' => $prioWeights, 'catWeights' => $catWeights,
                    'typeWeights' => $typeWeights,
                ]);
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    // Audit the generation, same as the importer does.
    try {
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('demo_data', ?, ?, UTC_TIMESTAMP())")
             ->execute([$analystId, "Generated reporting demo: {$counts['tickets']} tickets over {$months} months"
                 . ($seed !== null ? " (seed {$seed})" : '')]);
    } catch (Exception $e) { /* non-fatal */ }

    return [
        'counts'  => $counts,
        'purged'  => $purged,
        'months'  => $months,
        'seed'    => $seed,
        'tiers_set' => $tiersSet,
        'notes'   => $notes,
    ];
}

/** Prepare every insert once — this loop runs tens of thousands of times. */
function demoReportingPrepare(PDO $conn): array {
    $has = function (string $table) use ($conn): bool {
        try { $conn->query("SELECT 1 FROM `{$table}` LIMIT 0"); return true; }
        catch (Exception $e) { return false; }
    };

    $s = [];
    $s['ticket'] = $conn->prepare(
        "INSERT INTO tickets (tenant_id, ticket_number, subject, status_id, priority_id, department_id,
             ticket_type_id, category_id, assigned_analyst_id, owner_id, user_id, customer_id, origin_id,
             stream_id, playbook_eligible, first_time_fix, acknowledged_datetime,
             created_datetime, updated_datetime, closed_datetime)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $s['time']  = $has('ticket_time_entries') ? $conn->prepare(
        "INSERT INTO ticket_time_entries (ticket_id, analyst_id, notes, time_spent_minutes, entry_datetime, is_active, created_datetime)
         VALUES (?,?,?,?,?,1,?)") : null;
    $s['audit'] = $has('ticket_audit') ? $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?,?,?,?,?,?)") : null;
    $s['esc']   = $has('ticket_escalations') ? $conn->prepare(
        "INSERT INTO ticket_escalations (ticket_id, from_analyst_id, to_analyst_id, from_tier, to_tier, escalated_at, picked_up_at, note)
         VALUES (?,?,?,?,?,?,?,?)") : null;
    $s['hold']  = $has('ticket_hold_events') ? $conn->prepare(
        "INSERT INTO ticket_hold_events (ticket_id, reason, entered_at, exited_at, status_name)
         VALUES (?,?,?,?,?)") : null;
    $s['qa']    = $has('ticket_qa_reviews') ? $conn->prepare(
        "INSERT INTO ticket_qa_reviews (ticket_id, reviewer_analyst_id, review_type, passed, score, note, created_datetime)
         VALUES (?,?,?,?,?,?,?)") : null;
    $s['csat']  = $has('ticket_csat_responses') ? $conn->prepare(
        "INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, responded_datetime, rating, comment, analyst_id, created_at)
         VALUES (?,?,?,?,?,?,?,?)") : null;
    $s['sla']   = $has('ticket_sla_snapshot') ? $conn->prepare(
        "INSERT INTO ticket_sla_snapshot (ticket_id, response_state, response_remaining_mins, resolution_state, resolution_remaining_mins, policy_source, computed_at)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE response_state = VALUES(response_state), resolution_state = VALUES(resolution_state)") : null;
    $s['note']  = $has('ticket_notes') ? $conn->prepare(
        "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
         VALUES (?,?,?,1,?)") : null;
    return $s;
}

/** Subject lines that look like a service desk's, keyed loosely to category. */
function demoReportingSubject(?string $category): string {
    $generic = [
        'Cannot log in after password change', 'Outlook stuck on "disconnected"',
        'Laptop very slow since update', 'Request: new starter account setup',
        'Printer on 2nd floor offline', 'Shared drive permissions incorrect',
        'VPN drops every few minutes', 'Teams audio not working on headset',
        'Request: additional monitor', 'Mobile email not syncing',
        'Excel crashes opening finance workbook', 'Screen flickering on docking station',
        'Access request: HR folder', 'Backup job reported a failure overnight',
        'Website returning 502 for external users', 'Two-factor prompt not arriving',
        'Disk almost full on file server', 'New joiner needs software installed',
        'Card reader not recognised', 'Guest wifi rejecting credentials',
    ];
    $byCat = [
        'network'  => ['Site link down at branch office', 'Intermittent packet loss to datacentre', 'Switch port flapping'],
        'hardware' => ['Laptop will not power on', 'Keyboard keys unresponsive', 'Docking station not charging'],
        'software' => ['Application crashes on launch', 'Licence prompt on shared PC', 'Update failed and rolled back'],
        'security' => ['Suspicious sign-in alert to review', 'Phishing email reported by user', 'Account locked repeatedly'],
        'account'  => ['Password reset required', 'Mailbox access for leaver', 'Distribution list change'],
        'email'    => ['Mail delivery delayed', 'Mailbox over quota', 'External sender flagged'],
    ];
    if ($category) {
        $key = strtolower($category);
        foreach ($byCat as $needle => $lines) {
            if (str_contains($key, $needle)) return demoPickOne($lines);
        }
    }
    return demoPickOne($generic);
}

/**
 * Create one ticket and its child rows.
 *
 * Split out of the loop purely for readability; $counts is passed by reference so
 * the caller keeps a running total.
 */
function demoReportingOneTicket(PDO $conn, array $stmts, array &$counts, array $ctx): void {
    $look = $ctx['look'];
    $curves = $ctx['curves'];

    // ---- dimensions --------------------------------------------------------
    $pi = demoWeightedPick($ctx['prioWeights']);
    $priority = $look['priorities'][$pi];
    $category = $look['categories'] ? $look['categories'][demoWeightedPick($ctx['catWeights'])] : null;
    $type     = $look['types'] ? $look['types'][demoWeightedPick($ctx['typeWeights'])] : null;
    $origin   = demoPickOne($look['origins']);
    $stream   = demoPickOne($look['streams']);
    $dept     = demoPickOne($look['departments']);
    $customer = $look['customers'] && mt_rand(1, 100) <= 80 ? demoPickOne($look['customers']) : null;
    $user     = demoPickOne($look['users']);

    // Tier mix: most work lands on L1, less on L2, least on L3.
    $tier  = demoWeightedPick(['L1' => 60, 'L2' => 30, 'L3' => 10]);
    $owner = demoPickOne($ctx['byTier'][$tier]);

    $createdAt = demoReportingCreatedAt($ctx['year'], $ctx['month'], 0.15);
    $createdTs = strtotime($createdAt . ' UTC');

    // ---- cycle times -------------------------------------------------------
    // Scaled to this priority's own SLA target where it has one, so the outcome
    // the SLA engine computes later matches the intended narrative. Only where a
    // priority has no target do we fall back to absolute minutes.
    $target = $look['targets'][(int)$priority['id']] ?? ['response' => null, 'resolution' => null];
    [$ackBase, $resBase] = demoReportingPriorityBase($priority['name']);

    $ackMins = $target['response'] !== null && $target['response'] > 0
        ? max(1, (int)round($target['response'] * $curves['ack_multiplier'] * demoSkewedFactor()))
        : max(1, (int)round($ackBase * 1.4 * demoSkewedFactor()));

    $resMins = $target['resolution'] !== null && $target['resolution'] > 0
        ? (int)round($target['resolution'] * $curves['res_multiplier'] * demoSkewedFactor())
        : (int)round($resBase * 1.2 * demoSkewedFactor());
    // Resolution must always trail acknowledgement, whatever the draws produced.
    $resMins = max($ackMins + 5, $resMins);

    // Recent tickets are still in flight; older ones are essentially all done.
    $stillOpen = $ctx['is_current_month'] ? (mt_rand(1, 100) <= 35) : (mt_rand(1, 1000) <= 12);
    $ackAt   = gmdate('Y-m-d H:i:s', $createdTs + $ackMins * 60);
    $closedAt = $stillOpen ? null : gmdate('Y-m-d H:i:s', $createdTs + $resMins * 60);
    // Never let a generated ticket close in the future.
    if ($closedAt !== null && strtotime($closedAt . ' UTC') > time()) {
        $closedAt = null;
        $stillOpen = true;
    }

    $status = $stillOpen ? demoPickOne($look['statuses_open']) : demoPickOne($look['statuses_closed']);
    $ftf = !$stillOpen && (mt_rand(1, 1000) / 1000) < $curves['ftf_rate'] ? 1 : 0;

    $number = sprintf('%s-%02d%02d-%04d', demoReportingPrefix(),
        (int)substr((string)$ctx['year'], 2), $ctx['month'], $ctx['seq']);

    $subjectText = demoReportingSubject($category['name'] ?? null);
    $stmts['ticket']->execute([
        $ctx['tenantId'], $number, $subjectText,
        $status['id'], $priority['id'], $dept['id'] ?? null,
        $type['id'] ?? null, $category['id'] ?? null,
        $owner['id'], $owner['id'], $user['id'] ?? null, $customer['id'] ?? null,
        $origin['id'] ?? null, $stream['id'] ?? null,
        mt_rand(1, 100) <= 60 ? 1 : 0, $ftf,
        $ackAt, $createdAt, $closedAt ?? $ackAt, $closedAt,
    ]);
    $ticketId = (int)$conn->lastInsertId();

    // Without an initial email the ticket never appears in the ticket list - the
    // list is built from the emails table. Generated tickets used to skip this,
    // so a reporting demo produced hundreds of tickets that every folder badge
    // counted and no folder could show. See includes/ticket_seed_email.php.
    ticketSeedInitialEmail(
        $conn,
        $ticketId,
        $subjectText,
        $subjectText . "

Reported by the customer and logged by the service desk.",
        $user['email'] ?? null,
        $user['display_name'] ?? null,
        $createdAt,
        'Demo'
    );

    $endTs = $closedAt ? strtotime($closedAt . ' UTC') : time();

    // ---- SLA outcome -------------------------------------------------------
    // Derived from the generated durations against the real target — the same
    // comparison sla_get_state() makes — rather than rolled independently. That
    // way this snapshot and a later cron/sla_snapshot_rebuild.php agree instead
    // of contradicting each other. (The engine counts BUSINESS minutes against
    // the policy's calendar, so on a business-hours calendar it will be slightly
    // more generous than the wall-clock arithmetic here; on a 24/7 calendar the
    // two match.)
    if ($stmts['sla']) {
        $respTarget = (int)($target['response'] ?? 0);
        $resTarget  = (int)($target['resolution'] ?? 0);

        if (!$respTarget && !$resTarget) {
            // No target on this priority: nothing to be met or breached.
            $stmts['sla']->execute([$ticketId, 'na', null, 'na', null, 'priority', gmdate('Y-m-d H:i:s')]);
        } else {
            $respState  = 'na';
            $respRemain = null;
            if ($respTarget) {
                $respState  = $ackMins <= $respTarget ? 'met' : 'breached';
                $respRemain = $respTarget - $ackMins;
            }

            $resState  = 'na';
            $resRemain = null;
            if ($resTarget) {
                if ($stillOpen) {
                    // Elapsed so far, not the resolution it would have had.
                    $elapsed   = max(0, (int)round((time() - $createdTs) / 60));
                    $resRemain = $resTarget - $elapsed;
                    $resState  = $resRemain < 0 ? 'breached'
                               : ($resRemain <= $resTarget * 0.2 ? 'approaching' : 'ok');
                } else {
                    $resState  = $resMins <= $resTarget ? 'met' : 'breached';
                    $resRemain = $resTarget - $resMins;
                }
            }

            $stmts['sla']->execute([$ticketId, $respState, $respRemain,
                $resState, $resRemain, 'priority', gmdate('Y-m-d H:i:s')]);
        }
        $counts['sla_snapshots']++;
    }

    // ---- time entries ------------------------------------------------------
    if ($stmts['time']) {
        $n = demoWeightedPick([1 => 45, 2 => 30, 3 => 17, 4 => 8]);
        // Logged effort is a fraction of elapsed time, not equal to it.
        $budget = max(10, (int)round(min($resMins, 8 * 60) * (mt_rand(15, 60) / 100)));
        for ($i = 0; $i < $n; $i++) {
            $who = mt_rand(1, 100) <= 80 ? $owner : demoPickOne($look['analysts']);
            $at  = gmdate('Y-m-d H:i:s', $createdTs + (int)round(($endTs - $createdTs) * (($i + 1) / ($n + 1))));
            $stmts['time']->execute([$ticketId, $who['id'],
                demoPickOne(['Investigated and reproduced', 'Applied fix and tested', 'Liaised with user',
                             'Remote session', 'Checked logs', 'Awaiting confirmation']),
                max(5, (int)round($budget / $n)), $at, $at]);
            $counts['time_entries']++;
        }
    }

    // ---- audit: closure, reassignment (bounce) and reopens -----------------
    if ($stmts['audit']) {
        $openName   = $look['statuses_open'] ? $look['statuses_open'][0]['name'] : 'Open';
        $closedName = $look['statuses_closed'] ? $look['statuses_closed'][0]['name'] : 'Closed';

        // Bounce: 0-2 owner reassignments, fewer as the service matures.
        $bounces = demoWeightedPick([0 => (int)round(55 + 25 * $ctx['maturity']), 1 => 30, 2 => 12]);
        for ($i = 0; $i < (int)$bounces; $i++) {
            $other = demoPickOne($look['analysts']);
            $at = gmdate('Y-m-d H:i:s', $createdTs + (int)round(($endTs - $createdTs) * mt_rand(10, 60) / 100));
            $stmts['audit']->execute([$ticketId, $owner['id'], 'Owner', $other['full_name'], $owner['full_name'], $at]);
            $counts['audit']++;
        }

        if (!$stillOpen) {
            // Reopen: closed -> open, then closed again. Written in that order so
            // the reopen-rate query (which looks for a status leaving a closed
            // status) sees exactly one transition per reopen.
            if ((mt_rand(1, 1000) / 1000) < $curves['reopen_rate']) {
                $reopenAt = gmdate('Y-m-d H:i:s', $createdTs + (int)round(($endTs - $createdTs) * 0.6));
                $stmts['audit']->execute([$ticketId, $owner['id'], 'Status', $closedName, $openName, $reopenAt]);
                $counts['audit']++;
            }
            $stmts['audit']->execute([$ticketId, $owner['id'], 'Status', $openName, $closedName, $closedAt]);
            $counts['audit']++;
        }
    }

    // ---- escalation --------------------------------------------------------
    if ($stmts['esc'] && $tier !== 'L3' && (mt_rand(1, 1000) / 1000) < $curves['escalation_rate']) {
        $toTier = $tier === 'L1' ? 'L2' : 'L3';
        $to = demoPickOne($ctx['byTier'][$toTier]);
        $escTs = $createdTs + ($ackMins + mt_rand(10, 400)) * 60;
        if ($escTs < $endTs) {
            $stmts['esc']->execute([$ticketId, $owner['id'], $to['id'], $tier, $toTier,
                gmdate('Y-m-d H:i:s', $escTs),
                gmdate('Y-m-d H:i:s', $escTs + mt_rand(5, 180) * 60),
                'Needed ' . $toTier . ' input']);
            $counts['escalations']++;
        }
    }

    // ---- hold events -------------------------------------------------------
    if ($stmts['hold'] && (mt_rand(1, 1000) / 1000) < $curves['hold_rate']) {
        $holdStart = $createdTs + (int)round(($endTs - $createdTs) * 0.3);
        $holdMins  = mt_rand(60, 5000);
        $holdEnd   = min($endTs, $holdStart + $holdMins * 60);
        if ($holdEnd > $holdStart) {
            $stmts['hold']->execute([$ticketId,
                demoWeightedPick(['client' => 55, 'vendor' => 25, 'internal' => 20]),
                gmdate('Y-m-d H:i:s', $holdStart),
                // A still-open ticket can legitimately still be on hold.
                $stillOpen && mt_rand(1, 100) <= 30 ? null : gmdate('Y-m-d H:i:s', $holdEnd),
                'On hold']);
            $counts['holds']++;
        }
    }

    // ---- QA review ---------------------------------------------------------
    if ($stmts['qa'] && !$stillOpen && (mt_rand(1, 1000) / 1000) < $curves['qa_sample_rate']) {
        $passed = (mt_rand(1, 1000) / 1000) < $curves['qa_pass_rate'];
        $stmts['qa']->execute([$ticketId, demoPickOne($look['analysts'])['id'],
            demoWeightedPick(['triage' => 50, 'resolution' => 35, 'handover' => 15]),
            $passed ? 1 : 0,
            $passed ? mt_rand(75, 100) : mt_rand(35, 74),
            $passed ? 'Met the standard' : 'Categorisation and notes need improvement',
            $closedAt]);
        $counts['qa']++;
    }

    // ---- CSAT --------------------------------------------------------------
    if ($stmts['csat'] && !$stillOpen && mt_rand(1, 100) <= 35) {
        // Ratings cluster around the period's mean, clamped to 1-5.
        $mean = $curves['csat_mean'];
        $rating = (int)max(1, min(5, round($mean + (mt_rand(-100, 100) / 100) * 0.9)));
        $sentAt = gmdate('Y-m-d H:i:s', min(time(), strtotime($closedAt . ' UTC') + 3600));
        $respAt = gmdate('Y-m-d H:i:s', min(time(), strtotime($sentAt . ' UTC') + mt_rand(1, 72) * 3600));
        $stmts['csat']->execute([$ticketId, bin2hex(random_bytes(16)), $sentAt, $respAt, $rating,
            $rating >= 4 ? 'Quick and helpful, thanks' : ($rating == 3 ? 'Resolved eventually' : 'Took too long to get a reply'),
            $owner['id'], $sentAt]);
        $counts['csat']++;
    }

    // ---- a note, on some tickets, so the reading pane isn't bare ------------
    if ($stmts['note'] && mt_rand(1, 100) <= 40) {
        $stmts['note']->execute([$ticketId, $owner['id'],
            demoPickOne(['Called the user back, confirmed the symptom.',
                         'Reproduced in a test session; raising with the vendor.',
                         'Applied the documented workaround from the KB.',
                         'User confirmed working; closing.']),
            gmdate('Y-m-d H:i:s', $createdTs + max(60, (int)round(($endTs - $createdTs) * 0.4)))]);
        $counts['notes_rows']++;
    }
}
