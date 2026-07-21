<?php
/**
 * SLA Snapshot Rebuild — cron / maintenance entry point (Phase 8a).
 *
 * Backfills / repairs the ticket_sla_snapshot cache from the authoritative
 * compute-on-read engine (sla_get_state), across EVERY non-deleted ticket —
 * open and closed. The regular breach cron (cron/sla_breach_check.php) stamps
 * open tickets each run and the ticket status-change path stamps at close, so
 * this is the occasional "rebuild the whole cache" job: run once after
 * deploying Phase 8a to populate the table, and any time the cache is suspected
 * stale (e.g. after a bulk import or an SLA-policy change).
 *
 * Reuses the breach cron's security + logging harness verbatim:
 *   1. Shared-secret ?token=<value> matching sla_cron_token in system_settings.
 *   2. Per-IP failed-auth lockout (>=10 wrong tokens in an hour = 1-hour 429).
 *   3. Min interval between successful rebuilds (>= 60s), scoped to rebuild runs.
 * Every invocation is logged to sla_cron_runs with a "[snapshot-rebuild]" notes
 * marker so the two jobs never rate-limit each other.
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sla_notifications.php';

const SNAPSHOT_REBUILD_MARKER = '[snapshot-rebuild]';

$isCli = (PHP_SAPI === 'cli');
$clientIp = $isCli ? null : ($_SERVER['REMOTE_ADDR'] ?? null);
$startedAt = microtime(true);
$runId = null;

function snap_log_start(PDO $conn, bool $isCli, ?string $clientIp): ?int {
    try {
        $stmt = $conn->prepare("INSERT INTO sla_cron_runs (started_at, invocation, client_ip, outcome, notes) VALUES (UTC_TIMESTAMP(), ?, ?, 'error', ?)");
        $stmt->execute([$isCli ? 'cli' : 'http', $clientIp, SNAPSHOT_REBUILD_MARKER]);
        return (int)$conn->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

function snap_log_finish(PDO $conn, ?int $runId, string $outcome, array $counts = [], ?string $notes = null): void {
    if (!$runId) return;
    // Every rebuild row carries the marker prefix so the breach cron's
    // min-interval query can exclude it.
    $notes = SNAPSHOT_REBUILD_MARKER . ($notes !== null ? ' ' . $notes : '');
    try {
        $stmt = $conn->prepare("
            UPDATE sla_cron_runs
               SET ended_at = UTC_TIMESTAMP(),
                   duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, UTC_TIMESTAMP()) DIV 1000,
                   outcome = ?,
                   sent_count = ?,
                   skipped_count = ?,
                   error_count = ?,
                   notes = ?
             WHERE id = ?
        ");
        $stmt->execute([
            $outcome,
            (int)($counts['processed'] ?? 0),   // reuse sent_count as "processed"
            (int)($counts['tracked'] ?? 0),      // reuse skipped_count as "tracked"
            (int)($counts['errors'] ?? 0),
            mb_substr($notes, 0, 65000),
            $runId,
        ]);
    } catch (Exception $e) {
        error_log("snap_log_finish failed: " . $e->getMessage());
    }
}

try {
    $conn = connectToDatabase();
    $runId = snap_log_start($conn, $isCli, $clientIp);

    $settingsStmt = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sla_cron_token','sla_cron_min_interval_seconds')");
    $settings = [];
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    // A full rebuild is heavier than a breach check — hold a floor of 60s.
    $minInterval = max(60, (int)($settings['sla_cron_min_interval_seconds'] ?? 30));

    // ---- LAYER 1+2: HTTP auth + per-IP brute-force lockout ----
    if (!$isCli) {
        if ($clientIp) {
            $lockStmt = $conn->prepare("
                SELECT COUNT(*) FROM sla_cron_runs
                 WHERE outcome = 'auth_failed'
                   AND client_ip = ?
                   AND started_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR
            ");
            $lockStmt->execute([$clientIp]);
            if ((int)$lockStmt->fetchColumn() >= 10) {
                http_response_code(429);
                snap_log_finish($conn, $runId, 'auth_failed', [], "IP locked out (>=10 failed attempts in last hour)");
                echo "Too many failed attempts from this IP. Locked out for 1 hour.\n";
                exit;
            }
        }

        $expected = $settings['sla_cron_token'] ?? null;
        if (empty($expected)) {
            http_response_code(503);
            snap_log_finish($conn, $runId, 'config_missing', [], "sla_cron_token not seeded");
            echo "Cron token not set. Run api/system/db_verify.php to seed it, or insert manually.\n";
            exit;
        }
        $supplied = $_GET['token'] ?? '';
        if (!hash_equals($expected, (string)$supplied)) {
            http_response_code(403);
            snap_log_finish($conn, $runId, 'auth_failed', [], "wrong or missing token");
            echo "Forbidden\n";
            exit;
        }
    }

    // ---- LAYER 3: Min interval between successful rebuilds ----
    // Scoped to rebuild runs (marker prefix) so it's independent of the breach cron.
    if ($minInterval > 0) {
        $lastStmt = $conn->prepare("
            SELECT TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) AS age
              FROM sla_cron_runs
             WHERE outcome = 'ok'
               AND id <> ?
               AND notes LIKE '[snapshot-rebuild]%'
          ORDER BY started_at DESC
             LIMIT 1
        ");
        $lastStmt->execute([(int)$runId]);
        $age = $lastStmt->fetchColumn();
        if ($age !== false && (int)$age < $minInterval) {
            $wait = $minInterval - (int)$age;
            http_response_code(429);
            snap_log_finish($conn, $runId, 'rate_limited', [], "min interval not met (last ok rebuild {$age}s ago, need {$minInterval}s)");
            echo "Rate limited. Last successful rebuild was {$age}s ago; minimum interval is {$minInterval}s. Try again in {$wait}s.\n";
            exit;
        }
    }

    // ---- Run the rebuild ----
    $summary = sla_rebuild_snapshots($conn);
    $elapsed = round((microtime(true) - $startedAt) * 1000);
    $counts = [
        'processed' => (int)$summary['processed'],
        'tracked'   => (int)$summary['tracked'],
        'errors'    => count($summary['errors']),
    ];
    $notes = $summary['errors'] ? ("ERRORS: " . implode(' | ', array_slice($summary['errors'], 0, 50))) : null;
    $outcome = ($counts['errors'] > 0 && $counts['processed'] === 0) ? 'error' : 'ok';
    snap_log_finish($conn, $runId, $outcome, $counts, $notes);

    echo "SLA snapshot rebuild completed in {$elapsed}ms\n";
    echo "  Processed: {$counts['processed']}\n";
    echo "  Tracked:   {$counts['tracked']}\n";
    echo "  Errors:    {$counts['errors']}\n";
    if (!empty($summary['errors'])) {
        echo "\nERRORS:\n";
        foreach ($summary['errors'] as $line) echo "  - $line\n";
    }

} catch (Exception $e) {
    http_response_code(500);
    if (isset($conn) && $runId) {
        snap_log_finish($conn, $runId, 'error', [], $e->getMessage());
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("SLA snapshot rebuild error: " . $e->getMessage());
    exit(1);
}
