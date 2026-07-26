<?php
/**
 * Is automatic time tracking actually recording? — CLI diagnostic.
 *
 * The view timer fails quietly by design: a heartbeat that cannot write must
 * never disturb the reading pane, so a missing table, a disabled setting and an
 * analyst who simply has not sat on a ticket long enough all look identical from
 * the browser — nothing appears. This script tells the three apart.
 *
 * Usage (from the repo root, inside the app container):
 *
 *   php scripts/check-view-time.php
 *
 * In Docker:
 *   docker compose exec app php scripts/check-view-time.php
 *
 * Read-only: it writes nothing and changes nothing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ticket_view_time.php';

$conn = connectToDatabase();

$tableExists = function (string $t) use ($conn): bool {
    try { return $conn->query("SHOW TABLES LIKE " . $conn->quote($t))->fetch() !== false; }
    catch (Exception $e) { return false; }
};

$problems = [];

echo "\n=== Automatic time tracking (12b) ===\n\n";

// 1. Schema — the single most common reason for silence is that Database Verify
//    has not run since the feature deployed.
$hasSessions = $tableExists('ticket_view_sessions');
$hasSource   = viewTimeHasSourceColumn($conn);

printf("  %-34s %s\n", 'ticket_view_sessions table', $hasSessions ? 'present' : 'MISSING');
printf("  %-34s %s\n", 'ticket_time_entries.source', $hasSource ? 'present' : 'MISSING');

if (!$hasSessions) $problems[] = "ticket_view_sessions does not exist — run Database Verify (System → Database Verify).";
if (!$hasSource)   $problems[] = "ticket_time_entries.source does not exist — run Database Verify. Tracking still works without it; entries just cannot be labelled 'tracked'.";

// 2. Settings — seeded by Database Verify, so an unseeded install shows the
//    built-in defaults here rather than stored values.
$settings = viewTimeSettings($conn);
echo "\n";
printf("  %-34s %s\n", 'time_auto_track_enabled', $settings['time_auto_track_enabled'] ? '1 (on)' : '0 (OFF)');
printf("  %-34s %ds\n", 'time_auto_idle_seconds', $settings['time_auto_idle_seconds']);
printf("  %-34s %dm\n", 'time_auto_min_minutes', $settings['time_auto_min_minutes']);

if (empty($settings['time_auto_track_enabled'])) {
    $problems[] = "time_auto_track_enabled is 0 — every heartbeat is discarded before it is written.";
}

// 3. Evidence. Sessions prove beats are landing; entries prove analysts are
//    accepting what is offered. The two fail separately and mean different
//    things, so count them separately.
if ($hasSessions) {
    $total = (int) $conn->query("SELECT COUNT(*) FROM ticket_view_sessions")->fetchColumn();
    $open  = (int) $conn->query("SELECT COUNT(*) FROM ticket_view_sessions WHERE converted_entry_id IS NULL AND dismissed_at IS NULL")->fetchColumn();
    $recent = (int) $conn->query("SELECT COUNT(*) FROM ticket_view_sessions WHERE last_seen_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)")->fetchColumn();

    echo "\n";
    printf("  %-34s %d\n", 'sessions recorded, all time', $total);
    printf("  %-34s %d\n", 'still unclaimed', $open);
    printf("  %-34s %d\n", 'touched in the last hour', $recent);

    if ($total === 0) {
        $problems[] = "No sessions have ever been recorded. Beats are not reaching the server: check the browser Network tab for ticket_heartbeat.php while a ticket is open.";
    } elseif ($recent === 0) {
        echo "\n  (Nothing in the last hour — expected if nobody has had a ticket open.)\n";
    }

    $rows = $conn->query(
        "SELECT s.id, s.ticket_id, t.ticket_number, a.full_name AS analyst,
                s.focused_seconds, s.started_at, s.last_seen_at,
                s.converted_entry_id, s.dismissed_at
           FROM ticket_view_sessions s
           LEFT JOIN tickets  t ON t.id = s.ticket_id
           LEFT JOIN analysts a ON a.id = s.analyst_id
       ORDER BY s.id DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        echo "\n  Ten most recent sessions:\n";
        printf("    %-6s %-14s %-22s %8s  %-19s %s\n", 'id', 'ticket', 'analyst', 'focused', 'last seen (UTC)', 'state');
        foreach ($rows as $r) {
            $state = $r['converted_entry_id'] ? 'logged #' . $r['converted_entry_id']
                   : ($r['dismissed_at'] ? 'discarded' : 'unclaimed');
            printf("    %-6d %-14s %-22s %7ds  %-19s %s\n",
                $r['id'],
                substr((string)($r['ticket_number'] ?: $r['ticket_id']), 0, 14),
                substr((string)($r['analyst'] ?: '?'), 0, 22),
                (int)$r['focused_seconds'],
                (string)$r['last_seen_at'],
                $state
            );
        }

        // A session below the minimum is invisible in the pane, which reads as
        // "not working" but is the feature behaving as configured.
        $minSeconds = $settings['time_auto_min_minutes'] * 60;
        $belowMin = 0;
        foreach ($rows as $r) {
            if (!$r['converted_entry_id'] && !$r['dismissed_at'] && (int)$r['focused_seconds'] < $minSeconds) $belowMin++;
        }
        if ($belowMin > 0) {
            echo "\n  Note: {$belowMin} unclaimed session(s) above are under time_auto_min_minutes\n";
            echo "  ({$settings['time_auto_min_minutes']}m), so the pane deliberately stays quiet about them.\n";
        }
    }
}

if ($hasSource) {
    $auto = (int) $conn->query("SELECT COUNT(*) FROM ticket_time_entries WHERE source = 'auto' AND is_active = 1")->fetchColumn();
    $man  = (int) $conn->query("SELECT COUNT(*) FROM ticket_time_entries WHERE source <> 'auto' AND is_active = 1")->fetchColumn();
    echo "\n";
    printf("  %-34s %d\n", "time entries, source = 'auto'", $auto);
    printf("  %-34s %d\n", "time entries, typed by hand", $man);
    echo "\n  Both feed the K3 cost KPIs identically; source is a label, not a filter.\n";
}

echo "\n";
if ($problems) {
    echo "Problems found:\n";
    foreach ($problems as $i => $p) echo '  ' . ($i + 1) . ". $p\n";
    echo "\n";
    exit(1);
}

echo "No configuration problems found.\n";
echo "If the pane still offers nothing, the analyst has not yet accrued\n";
echo "{$settings['time_auto_min_minutes']} minute(s) of focused time on that ticket in one sitting.\n\n";
