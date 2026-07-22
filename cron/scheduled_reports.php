<?php
/**
 * Scheduled Reports — cron entry point (Phase 8b).
 *
 * Picks every due scheduled_report row (is_active = 1, next_run_at <= now UTC),
 * runs its saved group_by + filters through the shared ticket_report_run()
 * (scoped to the report creator's company access), renders a CSV + HTML summary
 * per its `format`, emails the recipients via the generic mailer, then advances
 * next_run_at to the next cadence boundary and stamps last_run_at.
 *
 * Reuses the SLA breach cron's security + logging harness verbatim (shared
 * secret token, per-IP lockout, min-interval, run-logging in sla_cron_runs),
 * tagged with a "[scheduled-reports]" notes marker so the sibling SLA jobs never
 * rate-limit each other. Recommended cadence: hourly (a report is due at most
 * once a day, so the cron just needs to fire often enough to catch its 07:00
 * slot promptly).
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ticket_report.php';
require_once __DIR__ . '/../includes/scheduled_report.php';
require_once __DIR__ . '/../includes/mailer.php';

const SCHEDULED_REPORTS_MARKER = '[scheduled-reports]';

$isCli = (PHP_SAPI === 'cli');
$clientIp = $isCli ? null : ($_SERVER['REMOTE_ADDR'] ?? null);
$startedAt = microtime(true);
$runId = null;

function schrep_log_start(PDO $conn, bool $isCli, ?string $clientIp): ?int {
    try {
        $stmt = $conn->prepare("INSERT INTO sla_cron_runs (started_at, invocation, client_ip, outcome, notes) VALUES (UTC_TIMESTAMP(), ?, ?, 'error', ?)");
        $stmt->execute([$isCli ? 'cli' : 'http', $clientIp, SCHEDULED_REPORTS_MARKER]);
        return (int)$conn->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

function schrep_log_finish(PDO $conn, ?int $runId, string $outcome, array $counts = [], ?string $notes = null): void {
    if (!$runId) return;
    $notes = SCHEDULED_REPORTS_MARKER . ($notes !== null ? ' ' . $notes : '');
    try {
        $stmt = $conn->prepare("
            UPDATE sla_cron_runs
               SET ended_at = UTC_TIMESTAMP(),
                   duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, UTC_TIMESTAMP()) DIV 1000,
                   outcome = ?, sent_count = ?, skipped_count = ?, error_count = ?, notes = ?
             WHERE id = ?
        ");
        $stmt->execute([
            $outcome,
            (int)($counts['sent'] ?? 0),
            (int)($counts['skipped'] ?? 0),
            (int)($counts['errors'] ?? 0),
            mb_substr($notes, 0, 65000),
            $runId,
        ]);
    } catch (Exception $e) {
        error_log("schrep_log_finish failed: " . $e->getMessage());
    }
}

try {
    $conn = connectToDatabase();
    $runId = schrep_log_start($conn, $isCli, $clientIp);

    $settingsStmt = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sla_cron_token','sla_cron_min_interval_seconds')");
    $settings = [];
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $minInterval = max(0, (int)($settings['sla_cron_min_interval_seconds'] ?? 30));

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
                schrep_log_finish($conn, $runId, 'auth_failed', [], "IP locked out (>=10 failed attempts in last hour)");
                echo "Too many failed attempts from this IP. Locked out for 1 hour.\n";
                exit;
            }
        }

        $expected = $settings['sla_cron_token'] ?? null;
        if (empty($expected)) {
            http_response_code(503);
            schrep_log_finish($conn, $runId, 'config_missing', [], "sla_cron_token not seeded");
            echo "Cron token not set. Run api/system/db_verify.php to seed it, or insert manually.\n";
            exit;
        }
        $supplied = $_GET['token'] ?? '';
        if (!hash_equals($expected, (string)$supplied)) {
            http_response_code(403);
            schrep_log_finish($conn, $runId, 'auth_failed', [], "wrong or missing token");
            echo "Forbidden\n";
            exit;
        }
    }

    // ---- LAYER 3: Min interval between successful runs (scoped to this job) ----
    if ($minInterval > 0) {
        $lastStmt = $conn->prepare("
            SELECT TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) AS age
              FROM sla_cron_runs
             WHERE outcome = 'ok'
               AND id <> ?
               AND notes LIKE '[scheduled-reports]%'
          ORDER BY started_at DESC
             LIMIT 1
        ");
        $lastStmt->execute([(int)$runId]);
        $age = $lastStmt->fetchColumn();
        if ($age !== false && (int)$age < $minInterval) {
            $wait = $minInterval - (int)$age;
            http_response_code(429);
            schrep_log_finish($conn, $runId, 'rate_limited', [], "min interval not met (last ok run {$age}s ago, need {$minInterval}s)");
            echo "Rate limited. Try again in {$wait}s.\n";
            exit;
        }
    }

    // ---- Run due reports ----
    $summary = ['sent' => [], 'skipped' => [], 'errors' => []];

    $due = $conn->query(
        "SELECT * FROM scheduled_report
          WHERE is_active = 1 AND next_run_at <= UTC_TIMESTAMP()
       ORDER BY next_run_at ASC
          LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($due as $schedule) {
        $id = (int)$schedule['id'];
        // Always advance the schedule after an attempt (success or failure) so a
        // broken report can't retry-storm every cron tick.
        $nextRun = scheduled_report_next_run($schedule['cadence']);

        try {
            $scopeAnalystId = $schedule['created_by_analyst_id'] !== null ? (int)$schedule['created_by_analyst_id'] : 0;
            if ($scopeAnalystId <= 0) {
                // No creator to scope by (the analyst was deleted) — can't safely
                // decide which companies' tickets to include. Skip, but still
                // advance so it doesn't jam the queue.
                $summary['skipped'][] = "report $id: no creator to scope by";
                $conn->prepare("UPDATE scheduled_report SET next_run_at = ? WHERE id = ?")->execute([$nextRun, $id]);
                continue;
            }

            $recipients = scheduled_report_parse_recipients((string)($schedule['recipients'] ?? ''));
            if (!$recipients) {
                $summary['skipped'][] = "report $id: no valid recipients";
                $conn->prepare("UPDATE scheduled_report SET next_run_at = ? WHERE id = ?")->execute([$nextRun, $id]);
                continue;
            }

            $filters = [];
            if (!empty($schedule['filters_json'])) {
                $decoded = json_decode($schedule['filters_json'], true);
                if (is_array($decoded)) $filters = $decoded;
            }

            $report = ticket_report_run($conn, $scopeAnalystId, (string)$schedule['group_by'], $filters);
            [$subject, $html] = scheduled_report_render_email($schedule, $report);
            mailer_send_html($conn, $recipients, $subject, $html);

            $conn->prepare("UPDATE scheduled_report SET next_run_at = ?, last_run_at = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$nextRun, $id]);
            $summary['sent'][] = "report $id ('{$schedule['name']}') to " . implode(', ', $recipients);
        } catch (Exception $e) {
            $summary['errors'][] = "report $id: " . $e->getMessage();
            // Advance next_run_at even on failure (last_run_at stays unchanged).
            try { $conn->prepare("UPDATE scheduled_report SET next_run_at = ? WHERE id = ?")->execute([$nextRun, $id]); } catch (Exception $e2) {}
        }
    }

    $elapsed = round((microtime(true) - $startedAt) * 1000);
    $counts = [
        'sent'    => count($summary['sent']),
        'skipped' => count($summary['skipped']),
        'errors'  => count($summary['errors']),
    ];
    $notesLines = [];
    if (!empty($summary['sent']))   $notesLines[] = "SENT: " . implode(' | ', $summary['sent']);
    if (!empty($summary['errors'])) $notesLines[] = "ERRORS: " . implode(' | ', $summary['errors']);
    $notes = $notesLines ? implode("\n", $notesLines) : null;

    $outcome = (!empty($summary['errors']) && empty($summary['sent'])) ? 'error' : 'ok';
    schrep_log_finish($conn, $runId, $outcome, $counts, $notes);

    echo "Scheduled reports completed in {$elapsed}ms\n";
    echo "  Sent:    {$counts['sent']}\n";
    echo "  Skipped: {$counts['skipped']}\n";
    echo "  Errors:  {$counts['errors']}\n";
    if (!empty($summary['sent'])) {
        echo "\nSENT:\n";
        foreach ($summary['sent'] as $line) echo "  - $line\n";
    }
    if (!empty($summary['errors'])) {
        echo "\nERRORS:\n";
        foreach ($summary['errors'] as $line) echo "  - $line\n";
    }

} catch (Exception $e) {
    http_response_code(500);
    if (isset($conn) && $runId) {
        schrep_log_finish($conn, $runId, 'error', [], $e->getMessage());
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Scheduled reports cron error: " . $e->getMessage());
    exit(1);
}
