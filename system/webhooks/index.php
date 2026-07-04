<?php
/**
 * System — Webhooks queue.
 *
 * Admin view over the outbound-webhook delivery queue (webhook_deliveries):
 *   1. A prominent SETUP card that makes it unmistakable what has to be in place
 *      for webhooks to actually leave the building — chiefly the background
 *      delivery worker (cron/webhook_deliveries.php). We detect live whether that
 *      worker is running (webhook_cron_last_run) and show the exact command to
 *      schedule if not.
 *   2. Queue health — counts per status.
 *   3. The delivery log — every queued webhook, its full request payload, the
 *      full response from the endpoint, and a Replay button.
 *
 * Read/replay data comes from api/workflow/deliveries.php + deliveries_replay.php
 * (shared with the Workflows nav link, which points here).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();
require_once '../../includes/functions.php';

$current_page = 'webhooks';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

$conn = connectToDatabase();

// --- Load the delivery-cron settings -------------------------------------
$settings = [];
foreach ($conn->query(
    "SELECT setting_key, setting_value FROM system_settings
     WHERE setting_key IN ('webhook_cron_token','webhook_cron_last_run',
                           'webhook_cron_min_interval_seconds','webhook_delivery_retention_days')"
) as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
$cronToken     = $settings['webhook_cron_token'] ?? '';
$retentionDays = (int)($settings['webhook_delivery_retention_days'] ?? 30);

// Age of the last cron run, computed in the DB so UTC comparison is exact.
$lastRun    = $settings['webhook_cron_last_run'] ?? null;
$lastRunAge = null; // seconds since last run, or null if never
if ($lastRun) {
    $ageStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
    $ageStmt->execute([$lastRun]);
    $lastRunAge = (int)$ageStmt->fetchColumn();
}

// How many webhooks are waiting to go out right now.
$pendingCount = (int)$conn->query(
    "SELECT COUNT(*) FROM webhook_deliveries WHERE status IN ('pending','failed')"
)->fetchColumn();

// Worker health: cron runs every minute, so <3min = healthy, <15min = stale, else down.
if ($lastRunAge === null)      { $cronState = 'never'; }
elseif ($lastRunAge <= 180)    { $cronState = 'running'; }
elseif ($lastRunAge <= 900)    { $cronState = 'stale'; }
else                           { $cronState = 'down'; }

// Exact commands for this install.
$scriptPath = realpath(__DIR__ . '/../../cron/webhook_deliveries.php') ?: 'cron/webhook_deliveries.php';
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$httpUrl    = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL
            . 'cron/webhook_deliveries.php?token=' . urlencode($cronToken);
$cliCmd     = 'php ' . $scriptPath;

function whAgo($s) {
    if ($s === null) return '';
    if ($s < 90)    return $s . ' seconds ago';
    if ($s < 5400)  return round($s / 60) . ' minutes ago';
    if ($s < 129600) return round($s / 3600) . ' hours ago';
    return round($s / 86400) . ' days ago';
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Webhooks queue</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .wh-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: #888; margin: 0 0 26px 0; max-width: 720px; line-height: 1.5; }

        .card { background: #fff; border-radius: 8px; padding: 22px 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 22px; }
        .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 4px 0; }
        .card .desc { font-size: 13px; color: #888; margin: 0 0 16px 0; line-height: 1.5; }

        /* ---- Setup / status banner ---- */
        .setup { border-left: 4px solid #90a4ae; }
        .setup.running { border-left-color: #2e7d32; }
        .setup.stale   { border-left-color: #f39c12; }
        .setup.down, .setup.never { border-left-color: #c0392b; }
        .setup-head { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
        .setup-pill { display: inline-flex; align-items: center; gap: 7px; padding: 5px 13px; border-radius: 16px; font-size: 12.5px; font-weight: 600; }
        .setup-pill .dot { width: 8px; height: 8px; border-radius: 50%; }
        .setup-pill.running { background: #e6f4ea; color: #1e7e34; } .setup-pill.running .dot { background: #1e7e34; }
        .setup-pill.stale   { background: #fef6e7; color: #b26a00; } .setup-pill.stale .dot { background: #f39c12; }
        .setup-pill.down, .setup-pill.never { background: #fce8e8; color: #c0392b; } .setup-pill.down .dot, .setup-pill.never .dot { background: #c0392b; }
        .setup-when { font-size: 12.5px; color: #78909c; }

        .setup-explain { font-size: 13px; color: #55606a; line-height: 1.6; margin: 12px 0 0; }
        .setup-explain strong { color: #333; }
        .steps { margin: 16px 0 0; padding: 0; list-style: none; counter-reset: step; }
        .steps li { position: relative; padding: 0 0 16px 34px; font-size: 13px; color: #444; line-height: 1.55; }
        .steps li:last-child { padding-bottom: 0; }
        .steps li::before { counter-increment: step; content: counter(step); position: absolute; left: 0; top: -1px; width: 22px; height: 22px; border-radius: 50%; background: #546e7a; color: #fff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        .steps li strong { color: #333; }
        .cmd-row { display: flex; align-items: stretch; gap: 8px; margin: 8px 0 4px; }
        .cmd-row code { flex: 1; background: #263238; color: #eceff1; border-radius: 5px; padding: 9px 12px; font-size: 12px; font-family: Consolas, Monaco, monospace; overflow-x: auto; white-space: nowrap; }
        .copy-btn { padding: 6px 14px; background: #eceff1; color: #455a64; border: none; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .copy-btn:hover { background: #cfd8dc; }
        .sub { font-size: 12px; color: #90a4ae; margin: 2px 0 0; }
        .sub a { color: #546e7a; }

        .facts { display: flex; flex-wrap: wrap; gap: 26px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #eef2f4; }
        .fact { font-size: 12px; color: #90a4ae; }
        .fact b { display: block; font-size: 13px; color: #37474f; font-weight: 600; margin-top: 2px; }

        /* ---- Queue table ---- */
        .wh-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .wh-filters { display: flex; gap: 8px; flex-wrap: wrap; }
        .wh-chip { padding: 5px 12px; border: 1px solid #d7dce1; border-radius: 16px; background: #fff; font-size: 12.5px; color: #445; cursor: pointer; }
        .wh-chip.active { background: #546e7a; color: #fff; border-color: #546e7a; }
        .wh-chip .n { opacity: 0.7; margin-left: 4px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #546e7a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.wh { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.wh th { text-align: left; color: #78909c; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 8px 10px; border-bottom: 1px solid #e8ecef; }
        table.wh td { padding: 8px 10px; border-bottom: 1px solid #f2f4f6; vertical-align: middle; }
        .st { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .st.delivered { background: #e6f4ea; color: #1e7e34; }
        .st.pending, .st.delivering { background: #e8eef2; color: #465a66; }
        .st.failed { background: #fdf0e2; color: #b26a00; }
        .st.dead { background: #fce8e8; color: #c0392b; }
        .wh-url { font-family: Consolas, Monaco, monospace; font-size: 11.5px; color: #37474f; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom; }
        .table-action-btn { padding: 4px 10px; background: #f4f6f7; border: 1px solid #e0e5e8; border-radius: 5px; font-size: 12px; color: #455a64; cursor: pointer; }
        .table-action-btn:hover { background: #e8ecef; }
        .wh-empty { padding: 30px; text-align: center; color: #90a4ae; font-size: 13px; }
        .modal-body pre { background: #263238; color: #eceff1; border-radius: 6px; padding: 12px; font-size: 11.5px; overflow: auto; max-height: 300px; white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="wh-container">
        <h1 class="page-title">Webhooks queue</h1>
        <p class="page-subtitle">
            Outbound webhooks — queued by the <em>Send a webhook</em> workflow action — are delivered in the
            background with automatic retries, so a slow or dead endpoint never holds up a ticket. This page shows
            whether delivery is set up correctly and lets you inspect every request, its full response, and replay any of them.
        </p>

        <!-- ============ SETUP / STATUS ============ -->
        <div class="card setup <?php echo $cronState; ?>">
            <div class="setup-head">
                <?php
                    $pillLabel = ['running' => 'Delivery worker is running',
                                  'stale'   => 'Delivery worker is delayed',
                                  'down'    => 'Delivery worker has stopped',
                                  'never'   => 'Not set up yet'][$cronState];
                ?>
                <span class="setup-pill <?php echo $cronState; ?>"><span class="dot"></span><?php echo $pillLabel; ?></span>
                <?php if ($lastRun): ?>
                    <span class="setup-when">Last ran <?php echo htmlspecialchars(whAgo($lastRunAge)); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($cronState === 'running'): ?>
                <p class="setup-explain">
                    The background worker is running and delivering the queue.
                    <?php if ($pendingCount > 0): ?>
                        <strong><?php echo $pendingCount; ?></strong> webhook<?php echo $pendingCount === 1 ? '' : 's'; ?>
                        waiting — <?php echo $pendingCount === 1 ? 'it' : 'they'; ?> will go out within a minute.
                    <?php else: ?>
                        Nothing is currently waiting.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="setup-explain">
                    <?php if ($cronState === 'never'): ?>
                        <strong>Webhooks will not be sent</strong> until the background delivery worker is scheduled to run.
                    <?php elseif ($cronState === 'down'): ?>
                        <strong>Webhooks are no longer being sent</strong> — the worker last ran
                        <?php echo htmlspecialchars(whAgo($lastRunAge)); ?> and appears to have stopped.
                    <?php else: ?>
                        The worker is running but behind schedule (last run <?php echo htmlspecialchars(whAgo($lastRunAge)); ?>).
                        It should run every minute.
                    <?php endif; ?>
                    <?php if ($pendingCount > 0): ?>
                        <strong><?php echo $pendingCount; ?></strong> webhook<?php echo $pendingCount === 1 ? '' : 's'; ?>
                        <?php echo $pendingCount === 1 ? 'is' : 'are'; ?> queued and waiting.
                    <?php endif; ?>
                </p>

                <ol class="steps">
                    <li>
                        <strong>Schedule the delivery worker to run every minute.</strong> On the server, run this command
                        from a scheduled task (Windows Task Scheduler) or cron (Linux):
                        <div class="cmd-row">
                            <code id="cliCmd"><?php echo htmlspecialchars($cliCmd); ?></code>
                            <button class="copy-btn" data-copy="cliCmd" type="button">Copy</button>
                        </div>
                        <p class="sub">Can't run PHP from the shell? Call it over HTTP instead (e.g. from a hosted cron service):</p>
                        <div class="cmd-row">
                            <code id="httpCmd"><?php echo htmlspecialchars($httpUrl); ?></code>
                            <button class="copy-btn" data-copy="httpCmd" type="button">Copy</button>
                        </div>
                    </li>
                    <li>
                        <strong>Add the <em>Send a webhook</em> action to a workflow</strong> so events start filling the queue —
                        under <a href="<?php echo BASE_URL; ?>workflow/">Workflows</a>.
                    </li>
                    <li>
                        <strong>Watch this page.</strong> Once the worker runs, the status above turns green and deliveries appear below.
                        Full setup notes (Windows &amp; Linux, signature verification) are in
                        <a href="https://github.com/edmozley/freeitsm/wiki/Workflows" target="_blank" rel="noopener">the Workflows wiki</a>.
                    </li>
                </ol>
            <?php endif; ?>

            <div class="facts">
                <div class="fact">Worker script<b>cron/webhook_deliveries.php</b></div>
                <div class="fact">Retry schedule<b>1m · 5m · 15m · 1h · 6h, then failed</b></div>
                <div class="fact">Log retention<b><?php echo $retentionDays; ?> days</b></div>
                <?php if ($cronState !== 'never'): ?>
                    <div class="fact">Queued now<b><?php echo $pendingCount; ?></b></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ DELIVERY LOG ============ -->
        <div class="card">
            <div class="wh-head">
                <div class="wh-filters" id="filters"></div>
                <button class="add-btn" id="refreshBtn" type="button">Refresh</button>
            </div>
            <div id="tableWrap">
                <table class="wh">
                    <thead>
                        <tr>
                            <th>When</th><th>Workflow</th><th>Format</th><th>URL</th>
                            <th>Status</th><th>Attempts</th><th>Last code</th><th>Next retry</th><th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rows"></tbody>
                </table>
                <div class="wh-empty" id="empty" style="display:none;">No webhook deliveries yet. Add a <em>Send a webhook</em> action to a workflow and trigger it.</div>
            </div>
        </div>
    </div>

    <!-- Payload modal -->
    <div class="modal" id="payloadModal" style="display:none;">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header"><h3 id="pmTitle">Delivery</h3><button class="modal-close" id="pmClose" type="button">&times;</button></div>
            <div class="modal-body" id="pmBody"></div>
        </div>
    </div>

    <script>
    const API = '<?php echo htmlspecialchars(BASE_URL . 'api/workflow'); ?>';
    let filter = '';
    let cache = [];
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const host = u => { try { return new URL(u).host; } catch (e) { return u; } };
    const fmt = s => s ? s.replace('T', ' ').replace(/\.\d+Z?$/, '') + ' UTC' : '';

    async function load() {
        const res = await fetch(API + '/deliveries.php' + (filter ? '?status=' + filter : ''), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { document.getElementById('rows').innerHTML = '<tr><td colspan="9">' + esc(data.error || 'Error') + '</td></tr>'; return; }
        cache = data.deliveries;
        renderFilters(data.summary);
        renderRows(data.deliveries);
    }

    function renderFilters(summary) {
        const total = Object.values(summary).reduce((a, b) => a + b, 0);
        const defs = [['', 'All', total], ['pending', 'Pending', summary.pending || 0], ['delivered', 'Delivered', summary.delivered || 0],
                      ['failed', 'Retrying', summary.failed || 0], ['dead', 'Failed', summary.dead || 0]];
        document.getElementById('filters').innerHTML = defs.map(([v, l, n]) =>
            `<button class="wh-chip ${filter === v ? 'active' : ''}" data-f="${v}">${l}<span class="n">${n}</span></button>`).join('');
        document.querySelectorAll('.wh-chip').forEach(c => c.onclick = () => { filter = c.dataset.f; load(); });
    }

    function renderRows(rows) {
        document.getElementById('empty').style.display = rows.length ? 'none' : 'block';
        document.getElementById('rows').innerHTML = rows.map(r => {
            const statusLabel = r.status === 'failed' ? 'retrying' : (r.status === 'dead' ? 'failed' : r.status);
            const replay = (r.status === 'delivered' || r.status === 'failed' || r.status === 'dead')
                ? `<button class="table-action-btn" data-replay="${r.id}" title="Send again">Replay</button>` : '';
            return `<tr>
                <td>${esc(fmt(r.created))}</td>
                <td>${esc(r.workflow)}</td>
                <td>${esc(r.preset || 'custom')}</td>
                <td><span class="wh-url" title="${esc(r.url)}">${esc(host(r.url))}</span></td>
                <td><span class="st ${r.status}">${esc(statusLabel)}</span></td>
                <td>${r.attempts}/${r.max_attempts}</td>
                <td>${r.last_status !== null ? r.last_status : '—'}</td>
                <td>${r.status === 'failed' && r.next_attempt ? esc(fmt(r.next_attempt)) : '—'}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <button class="table-action-btn" data-view="${r.id}">View</button> ${replay}
                </td></tr>`;
        }).join('');
        document.querySelectorAll('[data-view]').forEach(b => b.onclick = () => view(+b.dataset.view));
        document.querySelectorAll('[data-replay]').forEach(b => b.onclick = () => replay(+b.dataset.replay));
    }

    function view(id) {
        const r = cache.find(x => x.id === id);
        if (!r) return;
        document.getElementById('pmTitle').textContent = 'Delivery #' + r.id + ' — ' + (r.preset || 'custom');
        document.getElementById('pmBody').innerHTML =
            '<p style="font-size:12px;color:#667;margin:0 0 8px;">' + esc(r.method) + ' ' + esc(r.url) + '</p>'
            + '<strong style="font-size:12px;">Request headers</strong><pre>' + esc((r.headers || []).join('\n')) + '</pre>'
            + '<strong style="font-size:12px;">Request body (sent)</strong><pre>' + esc(r.body || '') + '</pre>'
            + (r.response ? '<strong style="font-size:12px;">Response' + (r.last_status ? ' (HTTP ' + r.last_status + ')' : '') + '</strong><pre>' + esc(r.response) + '</pre>' : '')
            + (r.last_error ? '<strong style="font-size:12px;color:#c0392b;">Last error</strong><pre>' + esc(r.last_error) + '</pre>' : '');
        document.getElementById('payloadModal').style.display = 'flex';
    }

    async function replay(id) {
        const res = await fetch(API + '/deliveries_replay.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!data.success) alert(data.error || 'Replay failed');
        load();
    }

    document.querySelectorAll('[data-copy]').forEach(b => b.onclick = () => {
        const t = document.getElementById(b.dataset.copy).textContent;
        navigator.clipboard.writeText(t).then(() => { const o = b.textContent; b.textContent = 'Copied'; setTimeout(() => b.textContent = o, 1200); });
    });
    document.getElementById('refreshBtn').onclick = load;
    document.getElementById('pmClose').onclick = () => document.getElementById('payloadModal').style.display = 'none';
    document.getElementById('payloadModal').onclick = e => { if (e.target.id === 'payloadModal') e.target.style.display = 'none'; };
    load();
    </script>
</body>
</html>
