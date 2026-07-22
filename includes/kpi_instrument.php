<?php
/**
 * KPI instrumentation (K1) — event capture that can only happen at the moment a
 * ticket changes state. Called from TicketsService::updateTicket. Every function
 * is best-effort (never throws) so it can never break a ticket update; the KPI
 * layer is a read-side convenience.
 *
 *   - acknowledged_datetime: first human ack (MTTA anchor).
 *   - ticket_escalations:    an owner change to a HIGHER tier (tier = owner's tier).
 *   - ticket_hold_events:    open/close as a ticket enters/leaves a pausing status.
 */

/** Numeric rank of a tier for escalation comparison. */
function kpi_tier_rank(?string $tier): int {
    return ['L1' => 1, 'L2' => 2, 'L3' => 3][$tier ?? ''] ?? 0;
}

/** Stamp the first-acknowledged time if not already set. */
function kpi_ticket_ack(PDO $conn, int $ticketId): void {
    try {
        $conn->prepare("UPDATE tickets SET acknowledged_datetime = UTC_TIMESTAMP() WHERE id = ? AND acknowledged_datetime IS NULL")
             ->execute([$ticketId]);
    } catch (Exception $e) { error_log('[kpi] ack stamp: ' . $e->getMessage()); }
}

/**
 * Record an escalation when ownership moves to a higher tier. Tier comes from
 * the analyst (a ticket's tier = its owner's tier), so an owner change from an
 * L1 to an L2/L3 (or L2->L3) is the escalation event.
 */
function kpi_ticket_escalation(PDO $conn, int $ticketId, ?int $fromAnalyst, ?int $toAnalyst): void {
    if (!$toAnalyst || $fromAnalyst === $toAnalyst) return;
    try {
        $tiers = [];
        $ids = array_values(array_filter([$fromAnalyst, $toAnalyst]));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s = $conn->prepare("SELECT id, tier FROM analysts WHERE id IN ($ph)");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $tiers[(int)$r['id']] = $r['tier'];
        }
        $fromTier = $fromAnalyst ? ($tiers[$fromAnalyst] ?? null) : null;
        $toTier   = $tiers[$toAnalyst] ?? null;
        if (kpi_tier_rank($toTier) > kpi_tier_rank($fromTier)) {
            $conn->prepare(
                "INSERT INTO ticket_escalations (ticket_id, from_analyst_id, to_analyst_id, from_tier, to_tier, escalated_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$ticketId, $fromAnalyst, $toAnalyst, $fromTier, $toTier]);
        }
    } catch (Exception $e) { error_log('[kpi] escalation: ' . $e->getMessage()); }
}

/** True if the named status pauses the SLA clock (i.e. an on-hold status). */
function kpi_status_pauses(PDO $conn, ?string $statusName): bool {
    if ($statusName === null || $statusName === '') return false;
    try {
        $s = $conn->prepare("SELECT pauses_sla FROM ticket_statuses WHERE name = ? LIMIT 1");
        $s->execute([$statusName]);
        return (bool)$s->fetchColumn();
    } catch (Exception $e) { return false; }
}

/**
 * Open a hold interval when a ticket enters a pausing status; close the open one
 * when it leaves. Reason (client/vendor/internal) is optional.
 */
function kpi_ticket_hold(PDO $conn, int $ticketId, ?string $oldStatus, ?string $newStatus, ?string $reason): void {
    try {
        $wasHold = kpi_status_pauses($conn, $oldStatus);
        $isHold  = kpi_status_pauses($conn, $newStatus);
        if ($isHold && !$wasHold) {
            $r = $reason ? mb_substr($reason, 0, 40) : null;
            $conn->prepare("INSERT INTO ticket_hold_events (ticket_id, reason, entered_at, status_name) VALUES (?, ?, UTC_TIMESTAMP(), ?)")
                 ->execute([$ticketId, $r, $newStatus]);
        } elseif ($wasHold && !$isHold) {
            $conn->prepare("UPDATE ticket_hold_events SET exited_at = UTC_TIMESTAMP() WHERE ticket_id = ? AND exited_at IS NULL")
                 ->execute([$ticketId]);
        }
    } catch (Exception $e) { error_log('[kpi] hold: ' . $e->getMessage()); }
}
