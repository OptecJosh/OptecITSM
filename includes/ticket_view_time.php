<?php
/**
 * Automatic time tracking (12b) — the view timer.
 *
 * The reading pane beats every 30 seconds while the analyst is actually looking
 * at a ticket, and this file turns those beats into a session: one open row per
 * analyst per ticket, carrying the seconds they were focused on it.
 *
 * THE RULE THAT MAKES IT TRUSTWORTHY: a session is a PROPOSAL, never a time
 * entry. Nothing reaches ticket_time_entries until the analyst accepts it.
 * Silently billing observed time would make every cost figure in the system
 * suspect the day it shipped.
 *
 * Accepted time IS counted by the KPI engine, exactly like typed time - cost per
 * ticket, utilisation, tickets per FTE and the out-of-hours rate all include it.
 * `source` still records where each entry came from, so the split stays
 * reportable and exportable, but it no longer changes what the metrics count.
 *
 * What is deliberately NOT counted:
 *   - a hidden or unfocused tab (the client only beats while visible)
 *   - idle time past time_auto_idle_seconds (a new session starts instead, so a
 *     ticket left open overnight proposes nothing for the night)
 *   - anything under time_auto_min_minutes, so opening a ticket to read it does
 *     not litter the log with one-minute entries
 *
 * Two analysts on the same ticket both accumulate their own session. That is
 * correct: they are both spending time on it.
 */

/** Read the 12b settings once per request, with safe defaults. */
function viewTimeSettings(PDO $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = ['time_auto_track_enabled' => 1, 'time_auto_idle_seconds' => 300, 'time_auto_min_minutes' => 2];
    $out = $defaults;
    try {
        $keys = array_keys($defaults);
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['setting_key']] = (int)$row['setting_value'];
        }
    } catch (Exception $e) { /* pre-seed install — defaults stand */ }

    $out['time_auto_idle_seconds'] = max(60, min(3600, $out['time_auto_idle_seconds']));
    $out['time_auto_min_minutes']  = max(1, min(120, $out['time_auto_min_minutes']));
    $cache = $out;
    return $cache;
}

/** Is the view timer switched on for this install? */
function viewTimeEnabled(PDO $conn): bool {
    return !empty(viewTimeSettings($conn)['time_auto_track_enabled']);
}

/**
 * Does ticket_time_entries.source exist yet? Code always deploys before Database
 * Verify runs, so every reader has to cope with the gap rather than 500 in it.
 */
function viewTimeHasSourceColumn(PDO $conn): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM ticket_time_entries LIKE 'source'");
        $has = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * Record a heartbeat. Adds $seconds to the analyst's open session for this
 * ticket, starting a new one if there is none or the last one went idle.
 *
 * Returns the session's accumulated seconds, or null when tracking is off.
 * Never throws: a failed heartbeat must not disturb the reading pane.
 */
function viewTimeHeartbeat(PDO $conn, int $ticketId, int $analystId, int $seconds): ?int {
    if ($ticketId <= 0 || $analystId <= 0) return null;
    if (!viewTimeEnabled($conn)) return null;

    // Clamp: one beat can only ever be worth a little more than its interval, so
    // a tab resumed from sleep (or a doctored request) cannot claim an hour.
    $seconds = max(0, min(120, $seconds));
    $idle = viewTimeSettings($conn)['time_auto_idle_seconds'];

    try {
        $stmt = $conn->prepare(
            "SELECT id, focused_seconds, TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS gap
               FROM ticket_view_sessions
              WHERE analyst_id = ? AND ticket_id = ? AND converted_entry_id IS NULL AND dismissed_at IS NULL
           ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$analystId, $ticketId]);
        $open = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($open && (int)$open['gap'] <= $idle) {
            $total = (int)$open['focused_seconds'] + $seconds;
            $conn->prepare("UPDATE ticket_view_sessions SET focused_seconds = ?, last_seen_at = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$total, (int)$open['id']]);
            return $total;
        }

        // No open session, or the previous one went idle: start a fresh one. The
        // idle session is left as it is - the analyst can still accept it.
        $conn->prepare(
            "INSERT INTO ticket_view_sessions (ticket_id, analyst_id, started_at, last_seen_at, focused_seconds)
             VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)"
        )->execute([$ticketId, $analystId, $seconds]);
        return $seconds;
    } catch (Exception $e) {
        error_log('[view-time] heartbeat: ' . $e->getMessage());
        return null;
    }
}

/**
 * The unclaimed time this analyst has accumulated on this ticket:
 * ['seconds' => int, 'minutes' => int, 'session_ids' => int[], 'proposable' => bool]
 *
 * `proposable` is false below the minimum, so the UI can stay quiet rather than
 * offering to log 40 seconds.
 */
function viewTimePending(PDO $conn, int $ticketId, int $analystId): array {
    $empty = ['seconds' => 0, 'minutes' => 0, 'session_ids' => [], 'proposable' => false];
    if (!viewTimeEnabled($conn)) return $empty;

    try {
        $stmt = $conn->prepare(
            "SELECT id, focused_seconds FROM ticket_view_sessions
              WHERE analyst_id = ? AND ticket_id = ? AND converted_entry_id IS NULL AND dismissed_at IS NULL"
        );
        $stmt->execute([$analystId, $ticketId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $empty;   // table absent (pre-Database-Verify)
    }

    $seconds = 0;
    $ids = [];
    foreach ($rows as $r) {
        $seconds += (int)$r['focused_seconds'];
        $ids[] = (int)$r['id'];
    }
    // Round to the nearest minute, but never propose zero for real time spent.
    $minutes = $seconds > 0 ? max(1, (int)round($seconds / 60)) : 0;
    $min = viewTimeSettings($conn)['time_auto_min_minutes'];

    return [
        'seconds'     => $seconds,
        'minutes'     => $minutes,
        'session_ids' => $ids,
        'proposable'  => $minutes >= $min,
    ];
}

/** Close out this analyst's open sessions on a ticket, converted or dismissed. */
function viewTimeResolve(PDO $conn, int $ticketId, int $analystId, ?int $entryId): void {
    try {
        if ($entryId !== null) {
            $conn->prepare(
                "UPDATE ticket_view_sessions SET converted_entry_id = ?
                  WHERE analyst_id = ? AND ticket_id = ? AND converted_entry_id IS NULL AND dismissed_at IS NULL"
            )->execute([$entryId, $analystId, $ticketId]);
        } else {
            $conn->prepare(
                "UPDATE ticket_view_sessions SET dismissed_at = UTC_TIMESTAMP()
                  WHERE analyst_id = ? AND ticket_id = ? AND converted_entry_id IS NULL AND dismissed_at IS NULL"
            )->execute([$analystId, $ticketId]);
        }
    } catch (Exception $e) {
        error_log('[view-time] resolve: ' . $e->getMessage());
    }
}
