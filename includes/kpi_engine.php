<?php
/**
 * KPI compute engine (K2).
 *
 * Computes the ticket-derived KPIs from freeitsm's own data for a given month,
 * sliced by tier (a ticket's tier = its owner's tier, analysts.tier). Only the
 * metrics that map cleanly to data we hold are implemented; the rest return null
 * and stay manual/imported. cron/kpi_snapshot.php calls kpi_engine_compute() for
 * every seeded KPI and writes the results to kpi_measurements.
 *
 * Data sources: tickets, ticket_audit, ticket_statuses, ticket_sla_snapshot
 * (Phase 8a), ticket_escalations / ticket_hold_events / ticket_qa_reviews (K1),
 * analysts.tier (K1).
 */

require_once __DIR__ . '/kpi.php';

/** [startInclusive, endExclusive] as 'Y-m-d H:i:s' for a 'YYYY-MM' period. */
function kpi_month_bounds(string $period): array {
    $start = $period . '-01 00:00:00';
    $end = date('Y-m-01 00:00:00', strtotime($period . '-01 +1 month'));
    return [$start, $end];
}

/** Days in the month (for per-day rates). */
function kpi_days_in_month(string $period): int {
    return (int)date('t', strtotime($period . '-01'));
}

/** Owner-tier filter on the `tickets t` alias. Returns [joinSql, whereSql, params]. */
function kpi_owner_filter(?string $tier): array {
    if ($tier === null) return ['', '', []];
    return [' JOIN analysts ka ON ka.id = t.owner_id', ' AND ka.tier = ?', [$tier]];
}

/** Run a query, return the first column of the first row as float|null. */
function kpi_scalar(PDO $conn, string $sql, array $params) {
    $s = $conn->prepare($sql);
    $s->execute($params);
    $v = $s->fetchColumn();
    return ($v === false || $v === null) ? null : (float)$v;
}

/**
 * Which metric names this engine can actually compute, and what branch each one
 * runs. This is the ONLY place metric names appear — kpi_engine_compute()
 * switches on the key it returns, so "is there an implementation for this
 * metric?" is answerable without executing any SQL.
 *
 * That question needs an honest answer because kpi_definitions.source_status is
 * hand-seeded prose. It said `Ready` (meaning "the engine fills this in for
 * you") on 14 metrics this file has never had a branch for, and those metrics
 * sat blank forever while the badge above them promised otherwise. Deriving the
 * badge from this map instead means a seed typo can no longer mislead anyone,
 * and adding a branch below without listing it here simply makes it unreachable
 * rather than silently half-wired.
 *
 * Ordered — the first matching entry wins. 'prefix' matches names that START
 * with the needle, which is how tier-suffixed variants ("Reopen rate (L1-closed)",
 * "MTTR (resolve), L3-owned") reach the same branch as their base metric.
 */
function kpi_engine_dispatch_map(): array {
    return [
        ['sla_response',       'prefix', ['SLA response attainment']],
        ['sla_resolution',     'prefix', ['SLA resolution attainment', 'SLA attainment']],
        ['mttr_resolve',       'prefix', ['MTTR (resolve)']],
        ['mtta',               'exact',  ['MTTA', 'Avg first response time']],
        ['age_at_closure',     'exact',  ['Avg ticket age at closure']],
        ['closed_per_day',     'exact',  ['Avg tickets closed / day (team)']],
        ['closed_per_analyst', 'exact',  ['Avg tickets closed / analyst / day']],
        ['backlog_health',     'exact',  ['Backlog health']],
        ['reopen_rate',        'prefix', ['Reopen rate']],
        ['ticket_bounce',      'exact',  ['Ticket bounce (avg reassignments)']],
        ['escalation_rate',    'exact',  ['Escalation rate']],
        ['time_to_escalate',   'prefix', ['Avg time to escalate']],
        ['on_hold_time',       'prefix', ['Avg on-hold time']],
        ['qa_pass',            'exact',  ['QA pass rate', 'QA pass rate (team)']],
        ['qa_triage',          'exact',  ['Triage accuracy']],
        ['qa_handover',        'exact',  ['Escalation handover quality']],
        ['kb_articles',        'exact',  ['Knowledge base articles']],
        ['time_per_ticket',    'exact',  ['Avg time spent per ticket']],
        ['cost_per_ticket',    'exact',  ['Cost per ticket']],
        ['utilisation',        'exact',  ['Utilisation %']],
        ['tickets_per_fte',    'exact',  ['Tickets per FTE']],
        ['out_of_hours',       'exact',  ['Out-of-hours work rate']],
        ['csat',               'exact',  ['CSAT / customer happiness']],
    ];
}

/** The branch key for a metric name, or null when the engine has no branch. */
function kpi_engine_dispatch_key(string $name): ?string {
    foreach (kpi_engine_dispatch_map() as [$key, $mode, $needles]) {
        foreach ($needles as $needle) {
            $hit = $mode === 'exact' ? ($name === $needle) : (strpos($name, $needle) === 0);
            if ($hit) return $key;
        }
    }
    return null;
}

/**
 * Can this metric be computed from freeitsm's own data? False means it is
 * manual or fed from outside — nothing will ever populate it on its own.
 */
function kpi_engine_can_compute(string $name): bool {
    return kpi_engine_dispatch_key($name) !== null;
}

/**
 * Compute a KPI value. Returns float|null. A null means one of two different
 * things, and callers that care should ask kpi_engine_can_compute() first:
 * either there is no branch for this metric, or the branch ran and the month
 * held no qualifying rows. $scorecard maps to a tier: L1/L2/L3, L3_BAU→L3,
 * COMBINED→all.
 */
function kpi_engine_compute(PDO $conn, string $scorecard, string $name, string $period) {
    $k = kpi_engine_dispatch_key($name);
    if ($k === null) return null;

    $tier = ['L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'L3_BAU' => 'L3', 'COMBINED' => null][$scorecard] ?? null;
    [$start, $end] = kpi_month_bounds($period);
    [$oj, $ow, $op] = kpi_owner_filter($tier);

    try {
        // --- SLA attainment (% met of tracked, tickets closed in month) ---
        if ($k === 'sla_response' || $k === 'sla_resolution') {
            $col = $k === 'sla_response' ? 'response_state' : 'resolution_state';
            $sql = "SELECT
                        SUM(s.$col = 'met') AS met,
                        SUM(s.$col IN ('met','breached')) AS tracked
                      FROM tickets t
                      JOIN ticket_sla_snapshot s ON s.ticket_id = t.id$oj
                     WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow";
            $stmt = $conn->prepare($sql); $stmt->execute(array_merge([$start, $end], $op));
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $tracked = (int)($r['tracked'] ?? 0);
            return $tracked > 0 ? round((int)$r['met'] / $tracked * 100, 1) : null;
        }

        // --- MTTR (resolve): created -> closed, hours ---
        if ($k === 'mttr_resolve') {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_datetime, t.closed_datetime))/60
                   FROM tickets t$oj
                  WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- MTTA / first response: created -> acknowledged, minutes ---
        if ($k === 'mtta') {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_datetime, t.acknowledged_datetime))
                   FROM tickets t$oj
                  WHERE t.acknowledged_datetime IS NOT NULL AND t.acknowledged_datetime >= ? AND t.acknowledged_datetime < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- Avg ticket age at closure: created -> closed, days ---
        if ($k === 'age_at_closure') {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(HOUR, t.created_datetime, t.closed_datetime))/24
                   FROM tickets t$oj
                  WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- Throughput ---
        if ($k === 'closed_per_day') {
            $c = kpi_scalar($conn,
                "SELECT COUNT(*) FROM tickets t$oj
                  WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow",
                array_merge([$start, $end], $op));
            return $c === null ? null : round($c / kpi_days_in_month($period), 2);
        }
        if ($k === 'closed_per_analyst') {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) c, COUNT(DISTINCT t.owner_id) a FROM tickets t$oj
                  WHERE t.deleted_datetime IS NULL AND t.owner_id IS NOT NULL
                    AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow");
            $stmt->execute(array_merge([$start, $end], $op));
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $a = (int)($r['a'] ?? 0);
            return $a > 0 ? round((int)$r['c'] / ($a * kpi_days_in_month($period)), 2) : null;
        }

        // --- Backlog health: closed - opened in month (team) ---
        if ($k === 'backlog_health') {
            $closed = (int)kpi_scalar($conn, "SELECT COUNT(*) FROM tickets t WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?", [$start, $end]);
            $opened = (int)kpi_scalar($conn, "SELECT COUNT(*) FROM tickets t WHERE t.deleted_datetime IS NULL AND t.created_datetime >= ? AND t.created_datetime < ?", [$start, $end]);
            return $closed - $opened;
        }

        // --- Reopen rate: closed->open transitions / tickets closed in month ---
        if ($k === 'reopen_rate') {
            $closedNames = $conn->query("SELECT name FROM ticket_statuses WHERE is_closed = 1")->fetchAll(PDO::FETCH_COLUMN);
            if (!$closedNames) return null;
            $ph = implode(',', array_fill(0, count($closedNames), '?'));
            $reopens = (int)kpi_scalar($conn,
                "SELECT COUNT(*) FROM ticket_audit ta JOIN tickets t ON t.id = ta.ticket_id$oj
                  WHERE ta.field_name = 'Status' AND ta.created_datetime >= ? AND ta.created_datetime < ?
                    AND ta.old_value IN ($ph) AND (ta.new_value NOT IN ($ph) OR ta.new_value IS NULL)$ow",
                array_merge([$start, $end], $closedNames, $closedNames, $op));
            $closed = (int)kpi_scalar($conn,
                "SELECT COUNT(*) FROM tickets t$oj WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow",
                array_merge([$start, $end], $op));
            return $closed > 0 ? round($reopens / $closed * 100, 1) : null;
        }

        // --- Ticket bounce: avg 'Owner' audit changes per ticket created in month ---
        if ($k === 'ticket_bounce') {
            return kpi_scalar($conn,
                "SELECT AVG(cnt) FROM (
                    SELECT ta.ticket_id, COUNT(*) cnt
                      FROM ticket_audit ta JOIN tickets t ON t.id = ta.ticket_id
                     WHERE ta.field_name = 'Owner' AND t.created_datetime >= ? AND t.created_datetime < ?
                  GROUP BY ta.ticket_id) x", [$start, $end]);
        }

        // --- Escalation rate: escalations from tier / tickets owned by tier (created in month) ---
        if ($k === 'escalation_rate') {
            $esc = (int)kpi_scalar($conn,
                "SELECT COUNT(*) FROM ticket_escalations e WHERE e.escalated_at >= ? AND e.escalated_at < ? AND e.from_tier = ?",
                [$start, $end, $tier ?? 'L1']);
            $tot = (int)kpi_scalar($conn,
                "SELECT COUNT(*) FROM tickets t$oj WHERE t.deleted_datetime IS NULL AND t.created_datetime >= ? AND t.created_datetime < ?$ow",
                array_merge([$start, $end], $op));
            return $tot > 0 ? round($esc / $tot * 100, 1) : null;
        }

        // --- Avg time to escalate: ack -> escalation, minutes (from this tier) ---
        if ($k === 'time_to_escalate') {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.acknowledged_datetime, e.escalated_at))
                   FROM ticket_escalations e JOIN tickets t ON t.id = e.ticket_id
                  WHERE e.escalated_at >= ? AND e.escalated_at < ? AND e.from_tier = ? AND t.acknowledged_datetime IS NOT NULL",
                [$start, $end, $tier ?? 'L1']);
        }

        // --- Avg on-hold time: avg closed hold interval in month, hours ---
        if ($k === 'on_hold_time') {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, h.entered_at, h.exited_at))/60
                   FROM ticket_hold_events h JOIN tickets t ON t.id = h.ticket_id$oj
                  WHERE h.exited_at IS NOT NULL AND h.entered_at >= ? AND h.entered_at < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- QA pass rates (% passed of reviews in month) ---
        if ($k === 'qa_pass' || $k === 'qa_triage' || $k === 'qa_handover') {
            $typeWhere = ''; $typeParam = [];
            if ($k === 'qa_triage') { $typeWhere = ' AND q.review_type = ?'; $typeParam = ['triage']; }
            if ($k === 'qa_handover') { $typeWhere = ' AND q.review_type = ?'; $typeParam = ['handover']; }
            $v = kpi_scalar($conn,
                "SELECT AVG(q.passed) * 100
                   FROM ticket_qa_reviews q JOIN tickets t ON t.id = q.ticket_id$oj
                  WHERE q.created_datetime >= ? AND q.created_datetime < ?$typeWhere$ow",
                array_merge([$start, $end], $typeParam, $op));
            return $v === null ? null : round($v, 1);
        }

        // --- KB articles written or revised in the month, by tier (14a) ---
        //
        // Was a manual count typed into the scorecard, which is absurd when the KB
        // is in the same database. An article counts once for the tier even if it
        // was both created and revised in the month, and a revision counts for
        // whoever saved that version - which is the behaviour the target
        // ("N per month across the tier, peer-reviewed, no stubs") describes.
        if ($k === 'kb_articles') {
            $tierWhere = $tier !== null ? ' AND a.tier = ?' : '';
            $tierArgs  = $tier !== null ? [$tier] : [];
            return kpi_scalar($conn,
                "SELECT COUNT(DISTINCT x.article_id) FROM (
                        SELECT ka.id AS article_id
                          FROM knowledge_articles ka
                          JOIN analysts a ON a.id = ka.author_id
                         WHERE ka.created_datetime >= ? AND ka.created_datetime < ?$tierWhere
                        UNION
                        SELECT kav.article_id
                          FROM knowledge_article_versions kav
                          JOIN analysts a ON a.id = kav.saved_by_id
                         WHERE kav.saved_datetime >= ? AND kav.saved_datetime < ?$tierWhere
                    ) x",
                array_merge([$start, $end], $tierArgs, [$start, $end], $tierArgs));
        }

        // --- Avg time spent per ticket (hours), from time entries in month ---
        if ($k === 'time_per_ticket') {
            return kpi_scalar($conn,
                "SELECT SUM(te.time_spent_minutes)/60 / NULLIF(COUNT(DISTINCT te.ticket_id),0)
                   FROM ticket_time_entries te JOIN tickets t ON t.id = te.ticket_id$oj
                  WHERE te.is_active = 1 AND te.entry_datetime >= ? AND te.entry_datetime < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- K3 cost/capacity (team-wide) ---
        if ($k === 'cost_per_ticket') {
            return kpi_scalar($conn,
                "SELECT SUM(te.time_spent_minutes/60 * COALESCE(a2.loaded_rate,0)) / NULLIF(COUNT(DISTINCT te.ticket_id),0)
                   FROM ticket_time_entries te JOIN analysts a2 ON a2.id = te.analyst_id
                  WHERE te.is_active = 1 AND te.entry_datetime >= ? AND te.entry_datetime < ?",
                [$start, $end]);
        }
        if ($k === 'utilisation') {
            $logged = kpi_scalar($conn, "SELECT SUM(time_spent_minutes)/60 FROM ticket_time_entries WHERE is_active = 1 AND entry_datetime >= ? AND entry_datetime < ?", [$start, $end]);
            $avail = kpi_scalar($conn, "SELECT SUM(COALESCE(contracted_weekly_hours,0)) FROM analysts WHERE is_active = 1");
            $weeks = kpi_days_in_month($period) / 7;
            return ($avail && $avail > 0) ? round(($logged ?? 0) / ($avail * $weeks) * 100, 1) : null;
        }
        if ($k === 'tickets_per_fte') {
            $closed = kpi_scalar($conn, "SELECT COUNT(*) FROM tickets WHERE deleted_datetime IS NULL AND closed_datetime >= ? AND closed_datetime < ?", [$start, $end]);
            $fte = kpi_scalar($conn, "SELECT COUNT(*) FROM analysts WHERE is_active = 1");
            return ($fte && $fte > 0) ? round(($closed ?? 0) / $fte, 1) : null;
        }
        if ($k === 'out_of_hours') {
            return kpi_scalar($conn,
                "SELECT SUM(CASE WHEN HOUR(entry_datetime) < 8 OR HOUR(entry_datetime) >= 18 OR DAYOFWEEK(entry_datetime) IN (1,7) THEN time_spent_minutes ELSE 0 END)
                        / NULLIF(SUM(time_spent_minutes),0) * 100
                   FROM ticket_time_entries WHERE is_active = 1 AND entry_datetime >= ? AND entry_datetime < ?",
                [$start, $end]);
        }
        if ($k === 'csat') {
            return kpi_scalar($conn,
                "SELECT AVG(rating >= 4) * 100 FROM ticket_csat_responses
                  WHERE responded_datetime >= ? AND responded_datetime < ? AND rating IS NOT NULL",
                [$start, $end]);
        }

    } catch (Exception $e) {
        error_log('[kpi_engine] ' . $scorecard . '/' . $name . ': ' . $e->getMessage());
        return null;
    }

    // Unreachable while every key in the dispatch map has a branch above; kept so
    // a key added to the map without its branch degrades to "no value" rather
    // than falling off the end of the function.
    return null;
}
