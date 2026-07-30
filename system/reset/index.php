<?php
/**
 * System — Reset data.
 *
 * Deletes records while keeping the configured platform. The split lives in
 * includes/data_reset.php and is shared with scripts/wipe-test-data.php, so the
 * page and the CLI can never disagree about what "test data" means.
 *
 * Three brakes, because this is the most destructive page in the application:
 * a preview that must succeed first, a typed confirmation phrase, and a plain
 * list of exactly which tables will be emptied and how many rows are in them.
 * Admin-gated by the system header and again by api/system/reset_data.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/data_reset.php';

$current_page = 'reset';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];
$confirmPhrase = data_reset_confirm_phrase();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System - Reset data</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=53">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .rs-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .rs-wrap h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .rs-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); max-width: 900px; }
        .rs-card h3 { margin: 0 0 6px; font-size: 15px; color: var(--text, #222); }
        .rs-danger { border-left: 4px solid #dc2626; }
        .rs-alert { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 12px 14px; font-size: 13px; line-height: 1.55; max-width: 900px; }
        .rs-alert strong { font-weight: 700; }
        .rs-lead { margin: 0; font-size: 13px; color: var(--text-dim, #6b7280); max-width: 900px; }
        .rs-groups { display: grid; grid-template-columns: 1fr; gap: 2px; margin-top: 4px; }
        .rs-group { display: flex; gap: 10px; align-items: flex-start; padding: 9px 10px; border-radius: 6px; }
        .rs-group:hover { background: var(--surface-2, #f7f8fa); }
        .rs-group input { margin-top: 3px; }
        .rs-group .nm { font-size: 13.5px; font-weight: 600; color: var(--text, #222); }
        .rs-group .ds { font-size: 12.5px; color: var(--text-dim, #6b7280); margin-top: 2px; line-height: 1.5; }
        .rs-group .ct { margin-left: auto; white-space: nowrap; font-size: 12.5px; color: var(--text-dim, #6b7280); font-variant-numeric: tabular-nums; }
        .rs-group .ct strong { color: var(--text, #222); font-size: 15px; }
        .rs-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px; }
        .rs-keep { font-size: 12.5px; color: var(--text-dim, #6b7280); margin-top: 12px; line-height: 1.6; }
        .rs-confirm { display: flex; flex-direction: column; gap: 5px; }
        .rs-confirm label { font-size: 12.5px; color: var(--text-dim, #6b7280); }
        .rs-confirm input { padding: 8px 10px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; font-family: ui-monospace, monospace; min-width: 240px; background: var(--surface, #fff); color: var(--text, #222); }
        .rs-result { margin-top: 14px; font-size: 13px; }
        .rs-result .err { color: #b91c1c; }
        .rs-result .ok { color: #166534; font-weight: 600; }
        .rs-result table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
        .rs-result td, .rs-result th { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border, #eee); }
        .rs-result td.n { text-align: right; font-variant-numeric: tabular-nums; }
        .rs-total { font-size: 13px; margin-top: 10px; }
        .rs-total strong { font-size: 18px; }
        button.rs-go[disabled] { opacity: .5; cursor: not-allowed; }
        .btn-danger { background: #dc2626; border-color: #dc2626; color: #fff; }
        .btn-danger:hover:not([disabled]) { background: #b91c1c; border-color: #b91c1c; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="rs-wrap">
            <h2>Reset data</h2>

            <div class="rs-alert">
                <strong>This deletes records permanently.</strong> There is no undo.
                <a href="../backup/">Download a database backup</a> before you use it.
                Your configuration is kept &mdash; analysts, teams, companies, permissions, mailboxes,
                SLA policies and calendars, every status and priority list, CMDB classes, forms,
                catalogue items, workflows, KPI definitions and LMS courses all survive. Only the
                groups you tick below are emptied.
            </div>

            <p class="rs-lead">Use this after a test round to get back to a configured but empty platform.
               If you want a genuinely factory-fresh install instead &mdash; configuration included &mdash;
               recreate the database from <code>database/freeitsm.sql</code>; see <code>docs/reset-data.md</code>.
               The same split is available on the command line as
               <code>php scripts/wipe-test-data.php</code>.</p>

            <div class="rs-card">
                <h3>1. Choose what to delete</h3>
                <div class="rs-row" style="margin-top:0;">
                    <button class="btn btn-secondary btn-sm" onclick="rsAll(true)">Select all</button>
                    <button class="btn btn-secondary btn-sm" onclick="rsAll(false)">Clear</button>
                    <span id="rsLoadState" style="font-size:12.5px;color:var(--text-dim,#6b7280);">Loading row counts&hellip;</span>
                </div>
                <div class="rs-groups" id="rsGroups"></div>
            </div>

            <div class="rs-card rs-danger">
                <h3>2. Preview, then confirm</h3>
                <div class="rs-row" style="margin-top:0;">
                    <button class="btn btn-secondary" onclick="rsPreview()">Preview</button>
                    <div class="rs-confirm">
                        <label for="rsConfirm">Type <code><?php echo htmlspecialchars($confirmPhrase); ?></code> to enable the button</label>
                        <input type="text" id="rsConfirm" autocomplete="off" spellcheck="false" placeholder="<?php echo htmlspecialchars($confirmPhrase); ?>">
                    </div>
                    <button class="btn btn-danger rs-go" id="rsGo" onclick="rsExecute()" disabled>Delete the data</button>
                </div>
                <div class="rs-result" id="rsResult"></div>
            </div>
        </div>
    </div>

    <script>window.RS_PHRASE = <?php echo json_encode($confirmPhrase); ?>;</script>
    <script src="reset.js?v=1"></script>
</body>
</html>
