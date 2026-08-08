<?php
/**
 * KPI snapshot — cron entry point (K2).
 *
 * Computes every auto-computable KPI (kpi_engine) for a period and writes the
 * results to kpi_measurements with an auto-derived RAG. KPIs the engine can't
 * compute (external feeds / QA-less months) are left untouched, so hand-entered
 * and imported values persist. Run daily to keep the current month live; it also
 * accepts ?period=YYYY-MM to (re)compute a closed month.
 *
 * From the command line (no token needed):
 *   php cron/kpi_snapshot.php                       current month
 *   php cron/kpi_snapshot.php --period=2026-03      one specific month
 *   php cron/kpi_snapshot.php --backfill=18         the last 18 months, oldest first
 * $_GET is empty under the CLI SAPI, so ?period= is unreachable there — hence the
 * flags. --backfill is what you want after migrating history or generating the
 * reporting demo, otherwise the scorecard has a single month in it and no trend.
 *
 * Cutover: if system_settings.kpi_cutover_month is set (YYYY-MM), periods before
 * it are never computed. That protects KPI values migrated from a previous
 * platform — the figures already published to customers — from being silently
 * recomputed by this cron. See docs/migration.md.
 *
 * Reuses the SLA breach cron's security + logging harness (shared token, per-IP
 * lockout, min-interval, sla_cron_runs logging) with a "[kpi-snapshot]" marker so
 * the sibling crons never rate-limit each other.
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/kpi.php';
require_once __DIR__ . '/../includes/kpi_engine.php';

const KPI_SNAPSHOT_MARKER = '[kpi-snapshot]';

$isCli = (PHP_SAPI === 'cli');
$clientIp = $isCli ? null : ($_SERVER['REMOTE_ADDR'] ?? null);
$startedAt = microtime(true);
$runId = null;

function kpicron_log_start(PDO $conn, bool $isCli, ?string $clientIp): ?int {
    try {
        $stmt = $conn->prepare("INSERT INTO sla_cron_runs (started_at, invocation, client_ip, outcome, notes) VALUES (UTC_TIMESTAMP(), ?, ?, 'error', ?)");
        $stmt->execute([$isCli ? 'cli' : 'http', $clientIp, KPI_SNAPSHOT_MARKER]);
        return (int)$conn->lastInsertId();
    } catch (Exception $e) { return null; }
}
function kpicron_log_finish(PDO $conn, ?int $runId, string $outcome, array $counts = [], ?string $notes = null): void {
    if (!$runId) return;
    $notes = KPI_SNAPSHOT_MARKER . ($notes !== null ? ' ' . $notes : '');
    try {
        $conn->prepare("UPDATE sla_cron_runs SET ended_at = UTC_TIMESTAMP(),
                          duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, UTC_TIMESTAMP()) DIV 1000,
                          outcome = ?, sent_count = ?, skipped_count = ?, error_count = ?, notes = ? WHERE id = ?")
             ->execute([$outcome, (int)($counts['computed'] ?? 0), (int)($counts['skipped'] ?? 0), (int)($counts['errors'] ?? 0), mb_substr($notes, 0, 65000), $runId]);
    } catch (Exception $e) { error_log('kpicron_log_finish: ' . $e->getMessage()); }
}

try {
    $conn = connectToDatabase();
    $runId = kpicron_log_start($conn, $isCli, $clientIp);

    $settings = [];
    foreach ($conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sla_cron_token','sla_cron_min_interval_seconds')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $minInterval = max(0, (int)($settings['sla_cron_min_interval_seconds'] ?? 30));

    if (!$isCli) {
        if ($clientIp) {
            $lock = $conn->prepare("SELECT COUNT(*) FROM sla_cron_runs WHERE outcome='auth_failed' AND client_ip=? AND started_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR");
            $lock->execute([$clientIp]);
            if ((int)$lock->fetchColumn() >= 10) { http_response_code(429); kpicron_log_finish($conn, $runId, 'auth_failed', [], 'IP locked out'); echo "Locked out.\n"; exit; }
        }
        $expected = $settings['sla_cron_token'] ?? null;
        if (empty($expected)) { http_response_code(503); kpicron_log_finish($conn, $runId, 'config_missing', [], 'sla_cron_token not seeded'); echo "Cron token not set.\n"; exit; }
        if (!hash_equals($expected, (string)($_GET['token'] ?? ''))) { http_response_code(403); kpicron_log_finish($conn, $runId, 'auth_failed', [], 'wrong token'); echo "Forbidden\n"; exit; }
    }
    if ($minInterval > 0) {
        $last = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) FROM sla_cron_runs WHERE outcome='ok' AND id<>? AND notes LIKE '[kpi-snapshot]%' ORDER BY started_at DESC LIMIT 1");
        $last->execute([(int)$runId]);
        $age = $last->fetchColumn();
        if ($age !== false && (int)$age < $minInterval) { http_response_code(429); kpicron_log_finish($conn, $runId, 'rate_limited', [], "min interval"); echo "Rate limited.\n"; exit; }
    }

    // Which period(s) to compute.
    //
    // Over HTTP that is ?period=YYYY-MM, defaulting to the current month — the
    // daily-cron behaviour, unchanged. From the CLI $_GET is always empty, so a
    // command-line run could only ever do the current month; that made it
    // impossible to populate a scorecard over back-dated history (a migration, or
    // the reporting demo generator) without issuing one HTTP request per month and
    // waiting out the rate limit between each. --period and --backfill fix that in
    // a single invocation: the min-interval check above has already run once, and
    // the loop below is inside it.
    $periods = [];
    if ($isCli) {
        $argPeriod = null;
        $backfill = 0;
        foreach (array_slice($argv ?? [], 1) as $arg) {
            if (preg_match('/^--period=(.+)$/', $arg, $m))   $argPeriod = kpi_valid_period(trim($m[1]));
            elseif (preg_match('/^--backfill=(\d+)$/', $arg, $m)) $backfill = (int)$m[1];
        }
        if ($backfill > 0) {
            // N whole months ending with the current one, oldest first.
            $backfill = min($backfill, 120);
            $anchor = new DateTime(($argPeriod ?? date('Y-m')) . '-01', new DateTimeZone('UTC'));
            for ($i = $backfill - 1; $i >= 0; $i--) {
                $d = (clone $anchor)->modify("-{$i} months");
                $periods[] = $d->format('Y-m');
            }
        } else {
            $periods[] = $argPeriod ?? date('Y-m');
        }
    } else {
        $periods[] = kpi_valid_period($_GET['period'] ?? '') ?? date('Y-m');
    }

    // ---- cutover guard ---------------------------------------------------
    // Months migrated from a previous platform hold ITS numbers — the ones
    // already shown to customers in monthly reviews. Recomputing them here with
    // our engine, our calendars and our targets would silently change published
    // history. kpi_measurements has no 'source' or 'locked' column to protect
    // them, so this setting is the only thing standing between a routine
    // --backfill and a rewritten past. Set kpi_cutover_month to the first month
    // this system is authoritative for; anything earlier is left alone.
    $cutover = null;
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'kpi_cutover_month'");
        $st->execute();
        $raw = (string)$st->fetchColumn();
        $cutover = kpi_valid_period($raw);
    } catch (Exception $e) { /* setting absent = no cutover configured */ }

    $skippedPeriods = [];
    if ($cutover !== null) {
        $before = $periods;
        $periods = array_values(array_filter($periods, fn($p) => $p >= $cutover));
        $skippedPeriods = array_values(array_diff($before, $periods));
        if ($skippedPeriods) {
            echo "  Cutover {$cutover}: leaving " . count($skippedPeriods)
               . " earlier period(s) untouched (migrated history)\n";
        }
        if (!$periods) {
            kpicron_log_finish($conn, $runId, 'ok', ['computed' => 0, 'skipped' => 0, 'errors' => 0],
                "all requested periods precede cutover {$cutover}");
            echo "Nothing to do: every requested period is before the cutover month {$cutover}.\n";
            exit;
        }
    }

    $defs = $conn->query("SELECT id, scorecard, name, direction, green_threshold, amber_threshold FROM kpi_definitions WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $upsert = $conn->prepare(
        "INSERT INTO kpi_measurements (kpi_id, period_month, value, status, note, entered_by_analyst_id, entered_at, updated_at)
         VALUES (?, ?, ?, ?, 'Computed by KPI engine', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE value = VALUES(value), status = VALUES(status), note = VALUES(note), updated_at = UTC_TIMESTAMP()"
    );

    // Three outcomes, not two. A metric the engine has no branch for is manual or
    // fed from outside and will never fill itself; a metric that HAS a branch but
    // returns null just had no qualifying rows this month. Both used to land in
    // one "Skipped (manual/feed)" bucket, which is how 14 metrics badged as
    // auto-computed sat permanently blank without anything in the run log
    // suggesting they were missing an implementation.
    $computed = 0; $skipped = 0; $noData = 0; $errors = 0;
    foreach ($periods as $period) {
        $pComputed = 0; $pSkipped = 0; $pNoData = 0;
        foreach ($defs as $d) {
            try {
                if (!kpi_engine_can_compute($d['name'])) { $pSkipped++; continue; }
                $val = kpi_engine_compute($conn, $d['scorecard'], $d['name'], $period);
                if ($val === null) { $pNoData++; continue; }
                $status = kpi_compute_status($d['direction'], $d['green_threshold'], $d['amber_threshold'], $val);
                $upsert->execute([(int)$d['id'], $period, $val, $status]);
                $pComputed++;
            } catch (Exception $e) { $errors++; error_log('[kpi_snapshot] ' . $d['name'] . ': ' . $e->getMessage()); }
        }
        $computed += $pComputed;
        $skipped  += $pSkipped;
        $noData   += $pNoData;
        // Per-period line, so a backfill shows its progress rather than going
        // quiet for a minute and then printing one total.
        if (count($periods) > 1) echo "  {$period}: computed {$pComputed}, no data {$pNoData}, manual/feed {$pSkipped}\n";
    }

    $elapsed = round((microtime(true) - $startedAt) * 1000);
    $outcome = ($errors > 0 && $computed === 0) ? 'error' : 'ok';
    $periodNote = count($periods) === 1 ? "period={$periods[0]}"
        : 'periods=' . $periods[0] . '..' . end($periods) . ' (' . count($periods) . ')';
    kpicron_log_finish($conn, $runId, $outcome,
        ['computed' => $computed, 'skipped' => $skipped + $noData, 'errors' => $errors],
        $periodNote . " no_data={$noData} manual_or_feed={$skipped}");

    echo "KPI snapshot ({$periodNote}) done in {$elapsed}ms\n  Computed: $computed\n  No data this period: $noData\n  Manual or fed externally: $skipped\n  Errors: $errors\n";

} catch (Exception $e) {
    http_response_code(500);
    if (isset($conn) && $runId) kpicron_log_finish($conn, $runId, 'error', [], $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log('KPI snapshot cron error: ' . $e->getMessage());
    exit(1);
}
