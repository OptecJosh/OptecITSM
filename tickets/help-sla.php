<?php
/**
 * Tickets — SLA Management Help Page
 * Standalone deep-dive linked from the main tickets help page.
 */
session_start();
require_once '../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - SLA Management</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .tk-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }
        .tk-help-sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .tk-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .tk-help-back-link {
            font-size: 12px;
            color: #0078d4;
            text-decoration: none;
            margin-bottom: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tk-help-back-link:hover { text-decoration: underline; }

        .tk-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .tk-help-nav-link:hover { background: #f5f5f5; color: #333; }
        .tk-help-nav-link.active { background: #e3f2fd; color: #005a9e; font-weight: 600; }
        .tk-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #eee;
            color: #888;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }
        .tk-help-nav-link.active .tk-help-nav-num { background: #0078d4; color: white; }

        .tk-help-main { flex: 1; overflow-y: auto; }

        .tk-help-hero {
            background: linear-gradient(135deg, #0078d4 0%, #005a9e 50%, #003d6b 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .tk-help-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .tk-help-hero p { margin: 0; font-size: 15px; opacity: 0.85; }

        .tk-help-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        .tk-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }
        .tk-help-section:last-child { border-bottom: none; padding-bottom: 0; }
        .tk-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }
        .tk-help-section-header h3 { margin: 0; font-size: 18px; color: #333; }
        .tk-help-section-header p { margin: 6px 0 0; font-size: 14px; color: #666; line-height: 1.6; }
        .tk-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }
        .tk-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e3f2fd;
            color: #005a9e;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .tk-help-section h4 {
            font-size: 15px;
            color: #333;
            margin: 22px 0 10px;
        }
        .tk-help-section h5 {
            font-size: 14px;
            color: #444;
            margin: 16px 0 8px;
        }

        .tk-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }
        .tk-help-fields div {
            padding: 10px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }
        .tk-help-fields div strong { color: #333; }

        .tk-help-tip {
            font-size: 13px !important;
            color: #005a9e !important;
            background: #e3f2fd;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #0078d4;
            margin: 14px 0;
        }
        .tk-help-warn {
            font-size: 13px;
            color: #92400e;
            background: #fef3c7;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #f59e0b;
            margin: 14px 0;
            line-height: 1.5;
        }

        .tk-help-example {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            background: white;
            padding: 20px 22px;
            margin: 16px 0;
        }
        .tk-help-example h5 {
            margin: 0 0 10px;
            font-size: 15px;
            color: #0078d4;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tk-help-example .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            background: #e3f2fd;
            color: #005a9e;
        }
        .tk-help-example .tag.green { background: #e8f5e9; color: #2e7d32; }
        .tk-help-example .tag.amber { background: #fef3c7; color: #92400e; }
        .tk-help-example .tag.red   { background: #fee2e2; color: #991b1b; }
        .tk-help-example p {
            font-size: 13.5px;
            color: #555;
            line-height: 1.65;
            margin: 8px 0;
        }
        .tk-help-example .timeline {
            background: #fafafa;
            border-left: 3px solid #94a3b8;
            padding: 12px 14px;
            border-radius: 6px;
            margin: 12px 0;
            font-size: 13px;
            color: #555;
            line-height: 1.7;
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
        }
        .tk-help-example .timeline strong { color: #0078d4; }

        .tk-help-option-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 14px 16px;
            margin: 10px 0;
            background: #fafafa;
        }
        .tk-help-option-card .label {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .tk-help-option-card .label.customer { background: #dcfce7; color: #166534; }
        .tk-help-option-card .label.analyst  { background: #fff3e0; color: #e65100; }
        .tk-help-option-card .label.overlap  { background: #f3e5f5; color: #7b1fa2; }
        .tk-help-option-card strong { color: #333; }
        .tk-help-option-card p {
            font-size: 13px;
            color: #555;
            line-height: 1.55;
            margin: 6px 0 0;
        }

        .tk-help-code {
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12.5px;
            color: #333;
        }

        .tk-help-code-block {
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
            background: #1e293b;
            color: #e2e8f0;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 12.5px;
            line-height: 1.55;
            overflow-x: auto;
            margin: 12px 0;
            white-space: pre;
        }

        table.tk-help-table {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0;
            font-size: 13px;
        }
        table.tk-help-table th {
            text-align: left;
            background: #f5f5f5;
            color: #444;
            padding: 10px 12px;
            border-bottom: 2px solid #e0e0e0;
            font-weight: 600;
        }
        table.tk-help-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            color: #555;
            vertical-align: top;
            line-height: 1.5;
        }

        @media (max-width: 900px) {
            .tk-help-sidebar { display: none; }
            .tk-help-content { padding: 10px 24px 40px; }
            .tk-help-hero { padding: 30px 24px; }
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="tk-help-container">
    <!-- Left pane navigation -->
    <div class="tk-help-sidebar">
        <a href="help.php" class="tk-help-back-link">&larr; Back to Tickets guide</a>
        <h3>SLA Guide</h3>
        <a href="#overview" class="tk-help-nav-link active" data-section="overview">
            <span class="tk-help-nav-num">1</span>
            Overview
        </a>
        <a href="#building-blocks" class="tk-help-nav-link" data-section="building-blocks">
            <span class="tk-help-nav-num">2</span>
            Building blocks
        </a>
        <a href="#behaviour-settings" class="tk-help-nav-link" data-section="behaviour-settings">
            <span class="tk-help-nav-num">3</span>
            Behaviour settings
        </a>
        <a href="#breach-notifications" class="tk-help-nav-link" data-section="breach-notifications">
            <span class="tk-help-nav-num">4</span>
            Breach notifications
        </a>
        <a href="#cron-setup" class="tk-help-nav-link" data-section="cron-setup">
            <span class="tk-help-nav-num">5</span>
            Cron job setup
        </a>
        <a href="#worked-examples" class="tk-help-nav-link" data-section="worked-examples">
            <span class="tk-help-nav-num">6</span>
            Worked examples
        </a>
        <a href="#troubleshooting" class="tk-help-nav-link" data-section="troubleshooting">
            <span class="tk-help-nav-num">7</span>
            Troubleshooting
        </a>
    </div>

    <!-- Main content -->
    <div class="tk-help-main" id="helpMain">
        <div class="tk-help-hero">
            <h2>SLA Management</h2>
            <p>Business-hours-aware response and resolution targets, with per-department breach notifications</p>
        </div>

        <div class="tk-help-content">

            <!-- 1. Overview -->
            <div class="tk-help-section" id="overview">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">1</span>
                    <div>
                        <h3>Overview</h3>
                        <p>What SLAs are, why they matter, and how the FreeITSM engine works at a high level.</p>
                    </div>
                </div>
                <p>A Service Level Agreement (SLA) is a promise about how quickly your team will respond to and resolve a ticket. FreeITSM tracks two clocks per ticket: <strong>response</strong> (time from ticket creation to first analyst action) and <strong>resolution</strong> (time from ticket creation to ticket closure). Both targets are set per priority &mdash; so a P1 might have 30 minutes for response and 4 hours for resolution, while a P4 might have 8 working hours and 5 working days.</p>

                <p>FreeITSM's SLA engine has four design choices worth understanding:</p>

                <div class="tk-help-fields">
                    <div><strong>Business hours, not wall clock.</strong> A P2 raised at 4:30pm with a 1-hour response target doesn't breach at 5:30pm if the office closes at 5pm. The clock resumes at 9am the next working day. This is the industry standard and the only fair model when analysts go home at night.</div>
                    <div><strong>Pause-able.</strong> When a ticket is in a status flagged "Pause SLA" (such as <em>Awaiting Response</em> or <em>On Hold</em>), the clock stops. It resumes when the ticket moves back to a working status.</div>
                    <div><strong>Compute-on-read, not stored.</strong> The engine recomputes SLA state from the ticket audit log every time you look at it. There are no stored counters that can drift out of sync. If you change a business calendar's hours later, every historical ticket's SLA is recomputed against the new hours next time it's viewed.</div>
                    <div><strong>Enforcement cutoff.</strong> An admin-controlled "Enforce SLAs from" date means existing tickets in your system aren't suddenly retroactively breached when you turn SLAs on. Only tickets created on or after the cutoff are tracked.</div>
                </div>

                <p class="tk-help-tip">If you're new to ITIL: response time is about acknowledgement (somebody picked it up); resolution time is about completion (the user's issue is fixed). The two clocks run independently and have different targets.</p>
            </div>

            <!-- 2. Building blocks -->
            <div class="tk-help-section" id="building-blocks">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">2</span>
                    <div>
                        <h3>The four building blocks</h3>
                        <p>Configure these four things and SLA tracking is operational. Everything else is opt-in refinement.</p>
                    </div>
                </div>

                <h4>1. Business Calendars</h4>
                <p>A calendar defines when your service desk is open. Each calendar has:</p>
                <div class="tk-help-fields">
                    <div><strong>Timezone</strong> &mdash; an IANA timezone identifier (e.g. <span class="tk-help-code">Europe/London</span>, <span class="tk-help-code">America/New_York</span>, <span class="tk-help-code">Asia/Singapore</span>). DST transitions are handled automatically.</div>
                    <div><strong>Weekly working hours</strong> &mdash; one row per weekday with an Open checkbox and start / end times. New calendars default to Mon-Fri 09:00-17:00, Sat/Sun closed. A row not flagged Open means the desk is closed that day.</div>
                    <div><strong>Holidays</strong> &mdash; date + optional name. Holidays override the weekly pattern (the desk is closed even if it falls on a Monday).</div>
                    <div><strong>Default flag</strong> &mdash; exactly one calendar is the default. New priorities inherit it unless you specify otherwise.</div>
                </div>
                <p>You can create as many calendars as you need &mdash; one per office, one per team, one for VIP customers with extended hours. They're shared by SLA targets and the breach-check engine.</p>

                <h4>2. Priorities with SLA Targets</h4>
                <p>On the Priorities tab (or via the SLA Targets table on the SLA tab), each priority can have:</p>
                <div class="tk-help-fields">
                    <div><strong>Response target (minutes)</strong> &mdash; how long until first analyst action.</div>
                    <div><strong>Resolution target (minutes)</strong> &mdash; how long until the ticket is closed.</div>
                    <div><strong>Calendar</strong> &mdash; which business calendar this priority's clock runs against. Leave blank to use the default calendar.</div>
                </div>
                <p>Leaving both target fields blank disables SLA tracking for that priority entirely &mdash; useful for an "Informational" or "Backlog" priority where there's no time pressure.</p>

                <h4>3. Pause-SLA Statuses</h4>
                <p>On the Statuses tab there's a dedicated "Pause SLA" column. Flag any status that represents the ticket being blocked on something outside the team's control. Standard candidates are:</p>
                <ul style="font-size:14px;color:#555;line-height:1.7;margin:8px 0 8px 24px;">
                    <li><strong>Awaiting Response</strong> &mdash; customer owes us information. Almost universally accepted as a fair pause.</li>
                    <li><strong>On Hold (Vendor)</strong> &mdash; waiting on Microsoft / third-party support.</li>
                    <li><strong>On Hold (Change Window)</strong> &mdash; scheduled for a future maintenance window.</li>
                    <li><strong>On Hold (Parts)</strong> &mdash; waiting on hardware delivery.</li>
                </ul>
                <p class="tk-help-warn">A generic "On Hold" status can be abused as an SLA escape valve when a ticket is hard or the analyst is busy. If your team has discipline, this is fine. If not, consider replacing it with the more specific variants above, or rely on the <strong>Watchtower</strong> dashboard which flags tickets paused over 24 hours as an amber alert.</p>

                <h4>4. Enforcement Cutoff (<span class="tk-help-code">Enforce SLAs from</span>)</h4>
                <p>The single most important global setting. Until you set a datetime here, SLA tracking is disabled entirely &mdash; no clocks run, no pills show on the inbox, no breach emails fire. Setting it to a past datetime turns SLAs on retroactively from that point. Setting it to a future datetime schedules a switch-on.</p>
                <p>Most teams set this to "now" when they first enable SLAs &mdash; existing tickets keep their pre-SLA state, new tickets from that moment onward are tracked.</p>
            </div>

            <!-- 3. Behaviour settings -->
            <div class="tk-help-section" id="behaviour-settings">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">3</span>
                    <div>
                        <h3>Behaviour settings</h3>
                        <p>These control how the engine reacts to edge cases. Defaults are sensible &mdash; you only need to revisit them if you have a specific preference.</p>
                    </div>
                </div>

                <h4>When priority changes mid-flight</h4>
                <p>If a P3 ticket is upgraded to P1 halfway through (because the issue turns out to be bigger than thought), there are three reasonable behaviours:</p>
                <div class="tk-help-fields">
                    <div><strong>Forward (default)</strong> &mdash; preserve accrued business minutes and just re-target. If the ticket had used 2h of its 8h P3 target, it now has 2h used against its 1h P1 target &mdash; which means it's already breached. Arguably correct: the issue WAS a P1 the whole time, the team just didn't realise.</div>
                    <div><strong>Recompute</strong> &mdash; reset the clock and apply the new target retroactively from ticket creation. A clean recalculation.</div>
                    <div><strong>Reset</strong> &mdash; start the new clock fresh from the moment of priority change. Most forgiving to the team but customers might feel cheated if a high-priority ticket suddenly gets a fresh long clock.</div>
                </div>

                <h4>When a closed ticket is reopened</h4>
                <p>If a ticket gets reopened (the user replies after closure):</p>
                <div class="tk-help-fields">
                    <div><strong>Reset (default)</strong> &mdash; start a fresh response and resolution clock from the reopen moment. Treat the reopen as effectively a new ticket.</div>
                    <div><strong>Continue</strong> &mdash; resume from the elapsed time at closure. The original SLA targets continue to apply.</div>
                </div>

                <h4>First-response definition</h4>
                <p>What counts as "first response" &mdash; the moment that stops the response clock?</p>
                <div class="tk-help-fields">
                    <div><strong>Either (default)</strong> &mdash; the first analyst action of any kind: an outbound email, a status change, an assignment.</div>
                    <div><strong>Status change</strong> &mdash; only a status change away from the default counts. Stricter &mdash; the analyst has to have actually worked the ticket, not just clicked it.</div>
                    <div><strong>Outbound email</strong> &mdash; only an email reply to the requester counts. Strictest &mdash; the customer must have heard from us.</div>
                </div>

                <h4>Warning threshold percent</h4>
                <p>How far through the SLA target before the indicator flips from green to amber. Default is 80% &mdash; so a 60-minute response target shows amber at 48 minutes elapsed. Also drives the warning-trigger breach notifications.</p>
            </div>

            <!-- 4. Breach notifications -->
            <div class="tk-help-section" id="breach-notifications">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">4</span>
                    <div>
                        <h3>Breach notifications</h3>
                        <p>Configurable per-department email rules that fire when SLAs approach breach (warning) or actually breach.</p>
                    </div>
                </div>

                <p>Each rule has four dimensions, all independent:</p>

                <h4>1. Scope</h4>
                <p>Either <strong>Default</strong> (rule applies to tickets in every department) or a <strong>specific department</strong>. Resolution: a department-specific rule wins over the default for tickets in that department. Without a default rule, departments with no specific rule get no notifications.</p>

                <h4>2. Trigger</h4>
                <p>Either <strong>Warning</strong> (the ticket has crossed the warning threshold &mdash; potential breach) or <strong>Breach</strong> (the ticket has exceeded its target &mdash; actual breach). Each is its own rule, so you can notify different people for each.</p>

                <h4>3. Target</h4>
                <p>Which clock to watch &mdash; <strong>Response</strong>, <strong>Resolution</strong>, or <strong>Both</strong>.</p>

                <h4>4. Recipients</h4>
                <p>Any combination of:</p>
                <div class="tk-help-fields">
                    <div><strong>The ticket's assignee</strong> &mdash; the analyst currently owning the ticket. Skipped if unassigned.</div>
                    <div><strong>Members of the ticket's department teams</strong> &mdash; every analyst in any team linked to the ticket's department. Covers the whole queue without maintaining a distribution list.</div>
                    <div><strong>A specific analyst</strong> &mdash; the "SLA champion" / "service manager" pattern where it's a named person, not a role.</div>
                    <div><strong>Custom email addresses</strong> &mdash; comma-separated free-text. Useful for shared inboxes (<span class="tk-help-code">sla-alerts@company.com</span>), Slack/Teams email bridges, or distribution lists.</div>
                </div>

                <p class="tk-help-tip">Each ticket fires at most one email per (target, trigger) &mdash; the cron worker tracks what's been sent in the <span class="tk-help-code">sla_notifications_sent</span> table. So a warning fires once, a breach fires once, and that's it &mdash; no spam loops.</p>

                <p class="tk-help-warn"><strong>No rules = no emails.</strong> Even if every ticket in the system breaches, nothing fires until you add at least one rule. Start with a Default-scope Warning + Breach pair to get coverage for everything.</p>
            </div>

            <!-- 5. Cron setup -->
            <div class="tk-help-section" id="cron-setup">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">5</span>
                    <div>
                        <h3>Setting up the cron job</h3>
                        <p>The breach-check script needs to run on a schedule. Once every 5 minutes is the sweet spot.</p>
                    </div>
                </div>

                <p>The visible-in-the-UI SLA indicators (pills on the inbox, panels in the reading pane) are computed on the fly when a page loads. But breach emails can only fire if <em>something</em> proactively checks tickets even when nobody is looking at them. That something is a scheduled task &mdash; on Windows, Task Scheduler; on Linux, cron.</p>

                <p>The script is <span class="tk-help-code">cron/sla_breach_check.php</span> in the FreeITSM install directory. It can be invoked two ways:</p>

                <h4>A. CLI (recommended on a server you control)</h4>
                <div class="tk-help-code-block">php c:\wamp64\www\freeitsm-app\cron\sla_breach_check.php</div>
                <p>No authentication required &mdash; filesystem permissions already gate who can run it.</p>

                <h4>B. HTTP (for shared hosting or remote schedulers)</h4>
                <div class="tk-help-code-block">curl http://your-host/freeitsm-app/cron/sla_breach_check.php?token=&lt;TOKEN&gt;</div>
                <p>The token is auto-generated on first install and stored in <span class="tk-help-code">system_settings</span> under the key <span class="tk-help-code">sla_cron_token</span> (32 hex characters, ~128 bits of entropy). Find yours with:</p>
                <div class="tk-help-code-block">SELECT setting_value FROM system_settings WHERE setting_key = 'sla_cron_token';</div>

                <h4>Windows Task Scheduler</h4>
                <p>Open Task Scheduler (<span class="tk-help-code">taskschd.msc</span>) and create a new task with these settings:</p>
                <table class="tk-help-table">
                    <tr><th style="width:35%;">Field</th><th>Value</th></tr>
                    <tr><td>Name</td><td>FreeITSM &mdash; SLA Breach Check</td></tr>
                    <tr><td>Run whether user is logged in or not</td><td>Tick this</td></tr>
                    <tr><td>Trigger</td><td>Daily, recur every 1 day, repeat every 5 minutes for 1 day</td></tr>
                    <tr><td>Action</td><td>Start a program</td></tr>
                    <tr><td>Program/script</td><td><span class="tk-help-code">C:\wamp64\bin\php\php8.2.x\php.exe</span></td></tr>
                    <tr><td>Add arguments</td><td><span class="tk-help-code">C:\wamp64\www\freeitsm-app\cron\sla_breach_check.php</span></td></tr>
                    <tr><td>Start in</td><td><span class="tk-help-code">C:\wamp64\www\freeitsm-app</span></td></tr>
                </table>

                <h4>Linux cron</h4>
                <p>Add one line to your crontab (<span class="tk-help-code">crontab -e</span>):</p>
                <div class="tk-help-code-block">*/5 * * * * /usr/bin/php /var/www/freeitsm-app/cron/sla_breach_check.php &gt;&gt; /var/log/freeitsm-sla-cron.log 2&gt;&amp;1</div>

                <h4>Security &mdash; what protects the HTTP endpoint</h4>
                <p>Three layers, all enforced automatically:</p>
                <div class="tk-help-fields">
                    <div><strong>1. Shared-secret token</strong> &mdash; 128-bit random per install, compared with constant-time matching to prevent timing attacks.</div>
                    <div><strong>2. Per-IP failed-auth lockout</strong> &mdash; more than 10 wrong-token attempts from the same IP in the past hour triggers a 1-hour 429 lockout. Defeats brute-force probing without needing fail2ban.</div>
                    <div><strong>3. Minimum interval between successful runs</strong> &mdash; <span class="tk-help-code">sla_cron_min_interval_seconds</span> (default 30s). Even a valid-token request gets a 429 if it arrives sooner than this after the last successful run. Defeats accidental double-scheduling and runaway loops.</div>
                </div>

                <h4>Activity log</h4>
                <p>Every invocation (accepted or rejected) is logged to the <span class="tk-help-code">sla_cron_runs</span> table with timing, outcome, counts, and client IP. View the last 20 runs in the <strong>Cron Activity</strong> panel at the bottom of the SLA settings tab &mdash; useful for verifying the schedule is firing and for auditing any failed-auth attempts. Rows are auto-pruned after <span class="tk-help-code">sla_cron_log_retention_days</span> (default 30) so the table stays small.</p>
            </div>

            <!-- 6. Worked examples -->
            <div class="tk-help-section" id="worked-examples">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">6</span>
                    <div>
                        <h3>Worked examples</h3>
                        <p>From the simple single-timezone case to the trickier cross-timezone one where "fair" needs defining.</p>
                    </div>
                </div>

                <h4>Example 1: Ticket raised in the UK, service desk in the UK</h4>

                <div class="tk-help-example">
                    <h5>The simple case <span class="tag green">single timezone</span></h5>
                    <p><strong>Setup:</strong> One business calendar called <em>UK Office</em> with timezone <span class="tk-help-code">Europe/London</span>, Mon-Fri 09:00-17:00, no holidays. P2 priority has a 1-hour response target and a 4-hour resolution target, both running against the <em>UK Office</em> calendar.</p>

                    <p><strong>Scenario:</strong> User raises a P2 ticket at <strong>16:30 UK on Wednesday</strong>.</p>

                    <div class="timeline">
Wed 16:30 UK &mdash; <strong>ticket created</strong>, response clock starts.
Wed 17:00 UK &mdash; office closes, clock pauses (30 min consumed of 60 min target).
Thu 09:00 UK &mdash; office opens, clock resumes.
Thu 09:30 UK &mdash; analyst replies. Response SLA met with 30 min to spare.</div>

                    <p>If the analyst didn't reply until <strong>Thursday 10:00 UK</strong>, the response target would be at exactly 100% (60 minutes consumed total &mdash; 30 from Wednesday evening, 30 from Thursday morning). The 1-hour clock has only ticked during business hours.</p>
                </div>

                <h4>Example 2: Ticket raised in the UK, service desk in Estonia</h4>

                <div class="tk-help-example">
                    <h5>The cross-timezone case <span class="tag amber">3 ways to define "fair"</span></h5>
                    <p><strong>Setup:</strong> UK customers (<span class="tk-help-code">Europe/London</span>, GMT/BST), Estonia service desk (<span class="tk-help-code">Europe/Tallinn</span>, EET/EEST &mdash; <strong>2 hours ahead of UK</strong>). Both desks nominally work 09:00-17:00 local time.</p>

                    <p><strong>Scenario:</strong> UK customer raises a P2 ticket at <strong>16:00 UK on Friday</strong> (= 18:00 Estonia, after Estonia close). The next opportunity for an Estonia analyst to work it is Monday 09:00 Estonia (= 07:00 UK).</p>

                    <p>What should the SLA say? There are three defensible answers, each fair in a different sense. You pick which calendar to assign to the priority based on what your service contract promises.</p>
                </div>

                <div class="tk-help-option-card">
                    <span class="label customer">Option A &mdash; Customer-centric</span>
                    <p><strong>Set the priority to use a calendar with <em>UK</em> hours.</strong> The clock ticks against UK 09:00-17:00, regardless of where the analyst is.</p>
                    <p>In the scenario above, ticket raised at 16:00 UK Friday: clock runs 16:00&ndash;17:00 UK Friday (1h consumed), pauses overnight + weekend, resumes Mon 09:00 UK. If the response target is 1h and an Estonia analyst replies at 11:00 Estonia Monday (= 09:00 UK), the clock has only consumed 1 hour of the working window &mdash; <strong>SLA met</strong>.</p>
                    <p><em>Fair to:</em> the customer. Their experience matches their working day.<br>
                    <em>Trade-off:</em> Estonia analysts are expected to be available 09:00-17:00 UK time (= 11:00-19:00 Estonia), which extends their working day by 2 hours.</p>
                </div>

                <div class="tk-help-option-card">
                    <span class="label analyst">Option B &mdash; Analyst-centric</span>
                    <p><strong>Set the priority to use a calendar with <em>Estonia</em> hours.</strong> The clock ticks against Estonia 09:00-17:00.</p>
                    <p>Same scenario: ticket raised 18:00 Estonia Friday (after close), clock paused all weekend, starts ticking Mon 09:00 Estonia (= 07:00 UK). If the analyst responds at 10:00 Estonia, the response SLA has consumed 1 hour &mdash; met if the target is 1h. The UK customer raised at 16:00 Friday UK and got a reply at 08:00 UK Monday &mdash; their wall-clock wait was much longer than the SLA suggests.</p>
                    <p><em>Fair to:</em> the analysts. They work normal hours.<br>
                    <em>Trade-off:</em> Customers in the UK can feel like the SLA is "lying" if a Friday-afternoon ticket isn't acknowledged until Monday morning Estonia time but the report says response SLA was 1 hour. Make sure your service contract is explicit that the SLA runs against support-desk hours.</p>
                </div>

                <div class="tk-help-option-card">
                    <span class="label overlap">Option C &mdash; Overlap window (strictest)</span>
                    <p><strong>Set the priority to use a calendar covering only the hours where both desks are open.</strong> UK 09:00-17:00 overlaps with Estonia 09:00-17:00 (= UK 07:00-15:00) during the <strong>UK 09:00-15:00 window</strong> &mdash; 6 hours per working day.</p>
                    <p>Same scenario: ticket raised 16:00 UK Friday (outside the overlap), clock paused over weekend, resumes Mon 09:00 UK (= 11:00 Estonia). If Estonia analyst replies at 11:30 Estonia (= 09:30 UK), the response SLA has consumed 30 minutes. The reply happened during the overlap window so it counts immediately.</p>
                    <p><em>Fair to:</em> both sides &mdash; the SLA only ticks during hours when the desk could actually progress the ticket AND the customer is at their desk to receive a response.<br>
                    <em>Trade-off:</em> Shorter daily window means longer wall-clock times to meet aggressive targets. A 4-hour resolution target spans 1.5 days of overlap, where with a normal 8-hour calendar it'd be done within a single day.</p>
                </div>

                <p class="tk-help-tip"><strong>How to choose:</strong> Look at your service contract. If it says "response within 1 hour of receipt" &mdash; that's Option A (customer's clock). If it says "response within 1 working hour" &mdash; that's typically Option B (whichever desk is working). If it says "within 1 hour of mutual availability" &mdash; that's Option C. If there's no contract, ask the team: do you want to feel pressure outside Estonia hours (Option A), or do you want UK customers to occasionally feel ignored (Option B)?</p>

                <h4>Example 3: Pause-the-clock during a vendor wait</h4>

                <div class="tk-help-example">
                    <h5>How pause statuses work in practice <span class="tag">pause-the-clock</span></h5>
                    <p><strong>Setup:</strong> P3 priority with 8 business-hour resolution target, UK calendar, status "Awaiting Vendor" flagged <em>Pause SLA</em>.</p>
                    <p><strong>Scenario:</strong> Ticket raised Mon 09:00 UK. Analyst escalates to Microsoft at Mon 11:00 UK and moves the ticket to "Awaiting Vendor". Microsoft responds Wed 14:00 UK. Analyst implements the fix and closes at Thu 10:00 UK.</p>
                    <div class="timeline">
Mon 09:00 &mdash; ticket created, resolution clock running.
Mon 11:00 &mdash; moved to "Awaiting Vendor", <strong>clock paused</strong> (2h consumed).
Wed 14:00 &mdash; moved back to "In Progress", <strong>clock resumes</strong>.
Wed 17:00 &mdash; office closes (5h consumed total).
Thu 09:00 &mdash; office opens.
Thu 10:00 &mdash; ticket closed (6h consumed total). <strong>SLA met</strong> against 8h target.</div>
                    <p>Wall-clock elapsed: ~3 days. SLA elapsed: 6 working hours. Without the pause-status flag, the SLA would have counted all 2 days of vendor wait + business hours, totalling ~18 working hours &mdash; well over breach.</p>
                </div>
            </div>

            <!-- 7. Troubleshooting -->
            <div class="tk-help-section" id="troubleshooting">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">7</span>
                    <div>
                        <h3>Troubleshooting</h3>
                        <p>Common issues and what to check.</p>
                    </div>
                </div>

                <table class="tk-help-table">
                    <tr><th style="width:40%;">Symptom</th><th>Likely cause</th></tr>
                    <tr><td>No SLA pills appear on the inbox</td><td><span class="tk-help-code">Enforce SLAs from</span> is blank, the priority has no targets set, or the ticket pre-dates the cutoff.</td></tr>
                    <tr><td>SLA shows breached but the analyst replied within target</td><td>Check the priority change history &mdash; if the priority was upgraded mid-flight and the behaviour is set to "Forward" (default), accrued time carries over.</td></tr>
                    <tr><td>Breach notifications not sending</td><td>(1) No rules configured. (2) Cron job not scheduled. (3) Rules configured but recipients can't be resolved (no assignee, dept teams have no members with emails). Check the Cron Activity panel for the per-run summary.</td></tr>
                    <tr><td>Cron runs but reports "no matching rule"</td><td>Add at least one Default-scope rule so every department is covered.</td></tr>
                    <tr><td>Watchtower shows "N tickets paused over 24h"</td><td>Tickets are sitting in a pause-SLA status. Either someone's parked them deliberately and forgot, or someone's using On Hold as an SLA escape valve. Click into each and either progress or get rid of the pause status.</td></tr>
                    <tr><td>HTTP cron returns 403</td><td>Token in URL doesn't match. Look up via <span class="tk-help-code">SELECT setting_value FROM system_settings WHERE setting_key = 'sla_cron_token'</span>.</td></tr>
                    <tr><td>HTTP cron returns 429 "Rate limited"</td><td>Successful run completed less than <span class="tk-help-code">sla_cron_min_interval_seconds</span> ago (default 30s). Wait or lower the setting.</td></tr>
                    <tr><td>HTTP cron returns 429 "Too many failed attempts"</td><td>This IP has hit more than 10 wrong-token attempts in the past hour. Wait 1 hour or clear the failures: <span class="tk-help-code">DELETE FROM sla_cron_runs WHERE client_ip = '...' AND outcome = 'auth_failed'</span>.</td></tr>
                </table>

                <p class="tk-help-tip">For an in-depth technical walkthrough of cron setup including Linux examples and remote-scheduler integration, see <span class="tk-help-code">docs/sla-cron-setup.md</span> in the FreeITSM install directory.</p>
            </div>

        </div>
    </div>
</div>

<script>
    // Scroll-spy: highlight active sidebar entry as user scrolls
    const helpMain = document.getElementById('helpMain');
    const navLinks = document.querySelectorAll('.tk-help-nav-link');
    const sections = Array.from(navLinks).map(l => document.getElementById(l.dataset.section)).filter(Boolean);

    function setActive(id) {
        navLinks.forEach(l => l.classList.toggle('active', l.dataset.section === id));
    }

    helpMain.addEventListener('scroll', () => {
        const scrollY = helpMain.scrollTop + 100;
        for (let i = sections.length - 1; i >= 0; i--) {
            if (sections[i].offsetTop <= scrollY) {
                setActive(sections[i].id);
                return;
            }
        }
    });

    // Smooth scroll on sidebar click
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const target = document.getElementById(link.dataset.section);
            if (target) {
                helpMain.scrollTo({ top: target.offsetTop - 20, behavior: 'smooth' });
                setActive(link.dataset.section);
            }
        });
    });
</script>
</body>
</html>
