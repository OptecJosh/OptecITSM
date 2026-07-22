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
 * Compute a KPI value. Returns float|null (null = not auto-computable → leave
 * to manual/import). $scorecard maps to a tier: L1/L2/L3, L3_BAU→L3, COMBINED→all.
 */
function kpi_engine_compute(PDO $conn, string $scorecard, string $name, string $period) {
    $tier = ['L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'L3_BAU' => 'L3', 'COMBINED' => null][$scorecard] ?? null;
    [$start, $end] = kpi_month_bounds($period);
    [$oj, $ow, $op] = kpi_owner_filter($tier);
    $begins = fn($needle) => strpos($name, $needle) === 0;

    try {
        // --- SLA attainment (% met of tracked, tickets closed in month) ---
        if ($begins('SLA response attainment') || $begins('SLA resolution attainment') || $begins('SLA attainment')) {
            $col = $begins('SLA response attainment') ? 'response_state' : 'resolution_state';
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
        if ($begins('MTTR (resolve)')) {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_datetime, t.closed_datetime))/60
                   FROM tickets t$oj
                  WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- MTTA / first response: created -> acknowledged, minutes ---
        if ($name === 'MTTA' || $name === 'Avg first response time') {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_datetime, t.acknowledged_datetime))
                   FROM tickets t$oj
                  WHERE t.acknowledged_datetime IS NOT NULL AND t.acknowledged_datetime >= ? AND t.acknowledged_datetime < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- Avg ticket age at closure: created -> closed, days ---
        if ($name === 'Avg ticket age at closure') {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(HOUR, t.created_datetime, t.closed_datetime))/24
                   FROM tickets t$oj
                  WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- Throughput ---
        if ($name === 'Avg tickets closed / day (team)') {
            $c = kpi_scalar($conn,
                "SELECT COUNT(*) FROM tickets t$oj
                  WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?$ow",
                array_merge([$start, $end], $op));
            return $c === null ? null : round($c / kpi_days_in_month($period), 2);
        }
        if ($name === 'Avg tickets closed / analyst / day') {
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
        if ($name === 'Backlog health') {
            $closed = (int)kpi_scalar($conn, "SELECT COUNT(*) FROM tickets t WHERE t.deleted_datetime IS NULL AND t.closed_datetime >= ? AND t.closed_datetime < ?", [$start, $end]);
            $opened = (int)kpi_scalar($conn, "SELECT COUNT(*) FROM tickets t WHERE t.deleted_datetime IS NULL AND t.created_datetime >= ? AND t.created_datetime < ?", [$start, $end]);
            return $closed - $opened;
        }

        // --- Reopen rate: closed->open transitions / tickets closed in month ---
        if ($begins('Reopen rate')) {
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
        if ($name === 'Ticket bounce (avg reassignments)') {
            return kpi_scalar($conn,
                "SELECT AVG(cnt) FROM (
                    SELECT ta.ticket_id, COUNT(*) cnt
                      FROM ticket_audit ta JOIN tickets t ON t.id = ta.ticket_id
                     WHERE ta.field_name = 'Owner' AND t.created_datetime >= ? AND t.created_datetime < ?
                  GROUP BY ta.ticket_id) x", [$start, $end]);
        }

        // --- Escalation rate: escalations from tier / tickets owned by tier (created in month) ---
        if ($name === 'Escalation rate') {
            $esc = (int)kpi_scalar($conn,
                "SELECT COUNT(*) FROM ticket_escalations e WHERE e.escalated_at >= ? AND e.escalated_at < ? AND e.from_tier = ?",
                [$start, $end, $tier ?? 'L1']);
            $tot = (int)kpi_scalar($conn,
                "SELECT COUNT(*) FROM tickets t$oj WHERE t.deleted_datetime IS NULL AND t.created_datetime >= ? AND t.created_datetime < ?$ow",
                array_merge([$start, $end], $op));
            return $tot > 0 ? round($esc / $tot * 100, 1) : null;
        }

        // --- Avg time to escalate: ack -> escalation, minutes (from this tier) ---
        if ($begins('Avg time to escalate')) {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.acknowledged_datetime, e.escalated_at))
                   FROM ticket_escalations e JOIN tickets t ON t.id = e.ticket_id
                  WHERE e.escalated_at >= ? AND e.escalated_at < ? AND e.from_tier = ? AND t.acknowledged_datetime IS NOT NULL",
                [$start, $end, $tier ?? 'L1']);
        }

        // --- Avg on-hold time: avg closed hold interval in month, hours ---
        if ($begins('Avg on-hold time')) {
            return kpi_scalar($conn,
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, h.entered_at, h.exited_at))/60
                   FROM ticket_hold_events h JOIN tickets t ON t.id = h.ticket_id$oj
                  WHERE h.exited_at IS NOT NULL AND h.entered_at >= ? AND h.entered_at < ?$ow",
                array_merge([$start, $end], $op));
        }

        // --- QA pass rates (% passed of reviews in month) ---
        if ($name === 'QA pass rate' || $name === 'QA pass rate (team)' || $name === 'Triage accuracy' || $name === 'Escalation handover quality') {
            $typeWhere = ''; $typeParam = [];
            if ($name === 'Triage accuracy') { $typeWhere = ' AND q.review_type = ?'; $typeParam = ['triage']; }
            if ($name === 'Escalation handover quality') { $typeWhere = ' AND q.review_type = ?'; $typeParam = ['handover']; }
            $v = kpi_scalar($conn,
                "SELECT AVG(q.passed) * 100
                   FROM ticket_qa_reviews q JOIN tickets t ON t.id = q.ticket_id$oj
                  WHERE q.created_datetime >= ? AND q.created_datetime < ?$typeWhere$ow",
                array_merge([$start, $end], $typeParam, $op));
            return $v === null ? null : round($v, 1);
        }

    } catch (Exception $e) {
        error_log('[kpi_engine] ' . $scorecard . '/' . $name . ': ' . $e->getMessage());
        return null;
    }

    return null;   // not auto-computable
}
