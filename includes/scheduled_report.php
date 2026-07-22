<?php
/**
 * Scheduled-report helpers (Phase 8b) — shared by the save endpoint and the
 * cron (cron/scheduled_reports.php) so the cadence maths and recipient parsing
 * live in one place.
 */

require_once __DIR__ . '/ticket_report.php';

/**
 * The next due time (UTC 'Y-m-d H:i:s') for a cadence, strictly AFTER $from
 * (defaults to now). Runs land at 07:00 UTC — early enough to be a "morning"
 * report in most business timezones, late enough that overnight tickets are in.
 *   daily   → the next 07:00
 *   weekly  → the next Monday 07:00
 *   monthly → 07:00 on the 1st of the next month
 */
function scheduled_report_next_run(string $cadence, ?DateTimeImmutable $from = null): string {
    $tz = new DateTimeZone('UTC');
    $from = $from ?: new DateTimeImmutable('now', $tz);
    $hour = 7;

    switch ($cadence) {
        case 'daily':
            $c = $from->setTime($hour, 0, 0);
            if ($c <= $from) $c = $c->modify('+1 day');
            break;
        case 'monthly':
            $c = $from->modify('first day of this month')->setTime($hour, 0, 0);
            if ($c <= $from) $c = $from->modify('first day of next month')->setTime($hour, 0, 0);
            break;
        case 'weekly':
        default:
            $c = $from->modify('monday this week')->setTime($hour, 0, 0);
            if ($c <= $from) $c = $from->modify('monday next week')->setTime($hour, 0, 0);
            break;
    }
    return $c->format('Y-m-d H:i:s');
}

/** Valid cadence values. */
function scheduled_report_cadences(): array { return ['daily', 'weekly', 'monthly']; }

/** Valid delivery formats. */
function scheduled_report_formats(): array { return ['csv', 'summary', 'both']; }

/**
 * Parse a free-text recipients blob (comma / semicolon / whitespace separated)
 * into a deduped list of valid email addresses. Returns [] if none are valid.
 */
function scheduled_report_parse_recipients(string $raw): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $email) {
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($email)] = $email;
        }
    }
    return array_values($out);
}

/**
 * Keep only recognised filter keys from a raw filters array (defends stored
 * JSON against junk), reusing the report's own whitelist.
 */
function scheduled_report_clean_filters($filters): array {
    if (!is_array($filters)) return [];
    $clean = [];
    foreach (ticket_report_allowed_filter_keys() as $k) {
        if (isset($filters[$k])) $clean[$k] = $filters[$k];
    }
    return $clean;
}

/**
 * Render a report result as CSV text (matches the report builder's export:
 * "<Dimension>","Count","Percent").
 */
function scheduled_report_render_csv(array $report): string {
    $dimLabel = $report['dim_label'] ?? 'Group';
    $total = (int)($report['total'] ?? 0);
    $csv = '"' . str_replace('"', '""', $dimLabel) . '","Count","Percent"' . "\n";
    foreach ($report['rows'] as $r) {
        $pct = $total ? number_format($r['count'] / $total * 100, 1) : '0';
        $csv .= '"' . str_replace('"', '""', (string)$r['label']) . '",' . (int)$r['count'] . ',' . $pct . "\n";
    }
    return $csv;
}

/**
 * Build the [subject, htmlBody] for a scheduled report email. The body always
 * carries a summary table; when the format includes 'csv' the raw CSV is
 * appended as a copy-pasteable <pre> block (the provider send paths are
 * HTML-only — file attachments are a future enhancement).
 */
function scheduled_report_render_email(array $schedule, array $report): array {
    $name     = (string)($schedule['name'] ?? 'Ticket report');
    $format   = (string)($schedule['format'] ?? 'both');
    $cadence  = (string)($schedule['cadence'] ?? 'weekly');
    $dimLabel = $report['dim_label'] ?? 'Group';
    $total    = (int)($report['total'] ?? 0);
    $generated = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i') . ' UTC';

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $subject = "[Report] {$name} — {$total} ticket" . ($total === 1 ? '' : 's');

    $rowsHtml = '';
    if ($report['rows']) {
        foreach ($report['rows'] as $r) {
            $pct = $total ? round($r['count'] / $total * 100, 1) : 0;
            $rowsHtml .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $esc($r['label'])
                . '</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;">' . (int)$r['count']
                . '</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;">' . $pct . '%</td></tr>';
        }
    } else {
        $rowsHtml = '<tr><td colspan="3" style="padding:12px;color:#666;">No tickets match this report\'s filters.</td></tr>';
    }

    $showSummary = ($format === 'summary' || $format === 'both');
    $showCsv     = ($format === 'csv' || $format === 'both');

    $summaryBlock = '';
    if ($showSummary || !$showCsv) {
        $summaryBlock = '
        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">
            <thead><tr>
                <th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e5e7eb;color:#6b7280;">' . $esc($dimLabel) . '</th>
                <th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e5e7eb;color:#6b7280;">Count</th>
                <th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e5e7eb;color:#6b7280;">%</th>
            </tr></thead>
            <tbody>' . $rowsHtml . '</tbody>
        </table>';
    }

    $csvBlock = '';
    if ($showCsv) {
        $csvBlock = '
        <div style="margin-top:16px;">
            <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">CSV</div>
            <pre style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:10px;font-size:12px;overflow:auto;white-space:pre;">'
            . $esc(scheduled_report_render_csv($report)) . '</pre>
        </div>';
    }

    $html = '
<div style="font-family: Arial, sans-serif; color:#333; line-height:1.5; max-width:640px;">
    <div style="background:#ca5010;color:#fff;padding:14px 18px;border-radius:4px 4px 0 0;font-weight:600;font-size:15px;">
        ' . $esc($name) . '
    </div>
    <div style="border:1px solid #e5e7eb;border-top:none;padding:18px;border-radius:0 0 4px 4px;">
        <div style="font-size:13px;color:#666;margin-bottom:6px;">
            Grouped by <strong>' . $esc($dimLabel) . '</strong> &middot; ' . $esc(ucfirst($cadence)) . ' &middot;
            <strong>' . $total . '</strong> ticket' . ($total === 1 ? '' : 's') . ' &middot; generated ' . $esc($generated) . '
        </div>'
        . $summaryBlock
        . $csvBlock . '
    </div>
    <div style="margin-top:12px;font-size:11px;color:#999;">
        This is an automated scheduled report. Manage schedules under Reporting &rsaquo; Tickets.
    </div>
</div>';

    return [$subject, $html];
}
