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

    $period = kpi_valid_period($_GET['period'] ?? '') ?? date('Y-m');

    $defs = $conn->query("SELECT id, scorecard, name, direction, green_threshold, amber_threshold FROM kpi_definitions WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $upsert = $conn->prepare(
        "INSERT INTO kpi_measurements (kpi_id, period_month, value, status, note, entered_by_analyst_id, entered_at, updated_at)
         VALUES (?, ?, ?, ?, 'Computed by KPI engine', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE value = VALUES(value), status = VALUES(status), note = VALUES(note), updated_at = UTC_TIMESTAMP()"
    );

    $computed = 0; $skipped = 0; $errors = 0;
    foreach ($defs as $d) {
        try {
            $val = kpi_engine_compute($conn, $d['scorecard'], $d['name'], $period);
            if ($val === null) { $skipped++; continue; }
            $status = kpi_compute_status($d['direction'], $d['green_threshold'], $d['amber_threshold'], $val);
            $upsert->execute([(int)$d['id'], $period, $val, $status]);
            $computed++;
        } catch (Exception $e) { $errors++; error_log('[kpi_snapshot] ' . $d['name'] . ': ' . $e->getMessage()); }
    }

    $elapsed = round((microtime(true) - $startedAt) * 1000);
    $outcome = ($errors > 0 && $computed === 0) ? 'error' : 'ok';
    kpicron_log_finish($conn, $runId, $outcome, ['computed' => $computed, 'skipped' => $skipped, 'errors' => $errors], "period=$period");

    echo "KPI snapshot ($period) done in {$elapsed}ms\n  Computed: $computed\n  Skipped (manual/feed): $skipped\n  Errors: $errors\n";

} catch (Exception $e) {
    http_response_code(500);
    if (isset($conn) && $runId) kpicron_log_finish($conn, $runId, 'error', [], $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log('KPI snapshot cron error: ' . $e->getMessage());
    exit(1);
}
