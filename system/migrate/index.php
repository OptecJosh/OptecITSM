<?php
/**
 * System — Migrate from another system.
 *
 * Four steps: choose a target and a file, confirm the column mapping, decide what
 * to do with status/category values we have never seen, then commit and read the
 * reconciliation. Admin-gated by the system header and again by api/system/migrate.php.
 *
 * Distinct from Mass import: that expects our own column names. This one expects
 * somebody else's, and its job is to get from theirs to ours without silently
 * guessing.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';

$current_page = 'migrate';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System - Migrate from another system</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .mg-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .mg-wrap h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .mg-lead { margin: 0; font-size: 13px; color: var(--text-dim, #6b7280); max-width: 900px; line-height: 1.55; }
        .mg-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); max-width: 1000px; }
        .mg-card h3 { margin: 0 0 4px; font-size: 15px; color: var(--text, #222); display: flex; align-items: center; gap: 8px; }
        .mg-step { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: var(--accent, #546e7a); color: #fff; font-size: 11px; font-weight: 700; flex-shrink: 0; }
        .mg-card p.mg-hint { margin: 0 0 12px; font-size: 12px; color: var(--text-dim, #6b7280); line-height: 1.5; }
        .mg-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .mg-field { display: flex; flex-direction: column; gap: 4px; }
        .mg-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .mg-field select, .mg-field input[type="file"] {
            padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #222); min-width: 240px;
        }
        .mg-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        .mg-table th, .mg-table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--border, #e5e7eb); vertical-align: middle; }
        .mg-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #6b7280); font-weight: 700; }
        .mg-table select { padding: 5px 7px; border: 1px solid var(--border, #e5e7eb); border-radius: 5px; font-size: 12px; background: var(--surface, #fff); color: var(--text, #222); min-width: 190px; }
        .mg-table input[type="text"] { padding: 5px 7px; border: 1px solid var(--border, #e5e7eb); border-radius: 5px; font-size: 12px; background: var(--surface, #fff); color: var(--text, #222); min-width: 160px; }
        .mg-conf { font-size: 11px; padding: 1px 6px; border-radius: 9px; font-weight: 600; }
        .mg-conf-hi { background: #dcfce7; color: #15803d; }
        .mg-conf-mid { background: #fef3c7; color: #92400e; }
        .mg-conf-lo { background: #fee2e2; color: #b91c1c; }
        .mg-pill { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 9px; background: var(--surface-2, #eceff3); color: var(--text-dim, #6b7280); font-weight: 600; }
        .mg-ok { color: #15803d; font-weight: 600; }
        .mg-new { color: #b45309; font-weight: 600; }
        .mg-scroll { max-height: 420px; overflow: auto; }
        .mg-note { font-size: 12px; color: var(--text-dim, #6b7280); margin-top: 10px; line-height: 1.55; }
        .mg-err { color: #b91c1c; font-size: 13px; margin-top: 10px; }
        .mg-warn { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; font-size: 12.5px; color: #92400e; line-height: 1.55; }
        .mg-warn strong { color: #78350f; }
        .mg-rec { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 12px; }
        .mg-rec div { background: var(--surface-2, #f6f8fa); border-radius: 8px; padding: 10px 12px; }
        .mg-rec span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #6b7280); font-weight: 700; }
        .mg-rec strong { font-size: 19px; color: var(--text, #222); }
        .mg-balanced { border-left: 3px solid #16a34a !important; }
        .mg-unbalanced { border-left: 3px solid #dc2626 !important; }
        .mg-hidden { display: none; }
        [data-theme-mode="dark"] .mg-conf-hi { background: #14532d; color: #86efac; }
        [data-theme-mode="dark"] .mg-conf-mid { background: #4a3410; color: #fcd34d; }
        [data-theme-mode="dark"] .mg-conf-lo { background: #4c1d1d; color: #fca5a5; }
        [data-theme-mode="dark"] .mg-warn { background: #3a2e12; border-color: #5a4a1e; color: #fcd34d; }
        [data-theme-mode="dark"] .mg-warn strong { color: #ffb74d; }
        [data-theme-mode="dark"] .mg-ok { color: #86efac; }
        [data-theme-mode="dark"] .mg-new { color: #fbbf24; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="mg-wrap">
            <h2>Migrate from another system</h2>
            <p class="mg-lead">Bring your history across from your previous helpdesk. Unlike
               <a href="../import/">Mass import</a>, this does not expect our column names: upload the file your old
               platform produced, and map its columns and its status and category values onto ours. Nothing is written
               until you commit, and the preview reports exactly what the commit will do.</p>

            <div class="mg-warn">
                <strong>Load in dependency order.</strong> Requesters, customers and any assets a ticket refers to must
                exist before the tickets that point at them, or those rows will fail with an unresolved name. Migrate
                <em>Requesters</em> and <em>Customers</em> first, then tickets. Staff accounts are never created by a
                migration &mdash; map an owner column onto analysts that already exist, or create them in
                <a href="../analysts/">System &rsaquo; Analysts</a> first.
            </div>

            <!-- Step 1 -->
            <div class="mg-card">
                <h3><span class="mg-step">1</span> Choose what you are migrating</h3>
                <p class="mg-hint">Pick the target, then the CSV your old system exported.</p>
                <div class="mg-row">
                    <div class="mg-field">
                        <label for="mgSource">Coming from</label>
                        <select id="mgSource" onchange="mgReset()"></select>
                    </div>
                    <div class="mg-field">
                        <label for="mgDataset">Migrate into</label>
                        <select id="mgDataset" onchange="mgReset()"></select>
                    </div>
                    <div class="mg-field">
                        <label for="mgFile">CSV file</label>
                        <input type="file" id="mgFile" accept=".csv,text/csv" onchange="mgAnalyse()">
                    </div>
                </div>
                <div class="mg-note" id="mgDatasetHint"></div>
                <div class="mg-err mg-hidden" id="mgErr1"></div>
            </div>

            <!-- Step 2 -->
            <div class="mg-card mg-hidden" id="mgCardMap">
                <h3><span class="mg-step">2</span> Check the column mapping</h3>
                <p class="mg-hint">Each of your columns has been matched to one of ours where the name made that safe.
                   Confidence is shown so you can see why &mdash; a wrong mapping here imports every row
                   &ldquo;successfully&rdquo; with the wrong data in it, so it is worth a read. Set anything you do not
                   want to bring across to <em>&mdash; ignore &mdash;</em>.</p>
                <div class="mg-scroll">
                    <table class="mg-table" id="mgMapTable"></table>
                </div>
                <div class="mg-note" id="mgMapNote"></div>
            </div>

            <!-- Step 3 -->
            <div class="mg-card mg-hidden" id="mgCardValues">
                <h3><span class="mg-step">3</span> Match up the values</h3>
                <p class="mg-hint">Your old system's statuses, priorities and categories are listed with how many rows
                   use each. Anything we already recognise is ticked. For the rest, either map it onto one of ours or
                   have it created &mdash; most frequent first, because that is where the decisions matter.</p>
                <div class="mg-scroll" id="mgValues"></div>
            </div>

            <!-- Step 4 -->
            <div class="mg-card mg-hidden" id="mgCardRun">
                <h3><span class="mg-step">4</span> Preview, then commit</h3>
                <p class="mg-hint">The preview writes nothing. Check the reconciliation balances &mdash; every source
                   row should be accounted for as written, errored or skipped &mdash; before committing.</p>
                <div class="mg-row">
                    <button class="btn" id="mgPreviewBtn" onclick="mgRun('preview')">Preview</button>
                    <button class="btn btn-primary" id="mgCommitBtn" onclick="mgRun('commit')" disabled>Commit</button>
                </div>
                <div id="mgResult"></div>
                <div class="mg-err mg-hidden" id="mgErr2"></div>
            </div>

            <!-- What migration cannot backfill -->
            <div class="mg-card mg-hidden" id="mgCardBackfill">
                <h3>What reporting will and will not have</h3>
                <p class="mg-hint">Read this before you present trends that span the cutover.</p>
                <div id="mgBackfill"></div>
            </div>
        </div>
    </div>

    <script src="migrate.js?v=2"></script>
</body>
</html>
