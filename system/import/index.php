<?php
/**
 * System — Mass import.
 *
 * Load CSV into any data module: pick a dataset, download its template, paste or
 * choose a file, preview, then commit. Admin-gated by the system header (and again
 * by every api/import endpoint). The read-only counterpart is Reporting > Export,
 * and the two use the same column names so an export can be edited and re-imported.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';

$current_page = 'import';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System - Mass import</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=53">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .im-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .im-wrap h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .im-lead { margin: 0; font-size: 13px; color: var(--text-dim, #6b7280); max-width: 820px; }
        .im-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); max-width: 900px; }
        .im-card h3 { margin: 0 0 6px; font-size: 15px; color: var(--text, #222); }
        .im-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .im-field { display: flex; flex-direction: column; gap: 4px; }
        .im-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .im-field select, .im-field input { padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); min-width: 170px; }
        .im-field select#imDataset { min-width: 260px; }
        textarea#imCsv { width: 100%; min-height: 170px; box-sizing: border-box; font-family: ui-monospace, monospace; font-size: 12px; padding: 8px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #222); margin-top: 10px; }
        .im-contract { margin-top: 12px; font-size: 12.5px; color: var(--text-dim, #6b7280); }
        .im-contract code { background: var(--surface-2, #eceff3); padding: 1px 5px; border-radius: 4px; font-size: 11.5px; color: var(--text, #333); }
        .im-contract .req { color: #b45309; font-weight: 600; }
        .im-contract table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .im-contract td, .im-contract th { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border, #f0f1f3); font-size: 12px; vertical-align: top; }
        .im-warn { color: #b45309; font-size: 12.5px; margin-top: 10px; }
        .im-detected { margin-top: 8px; font-size: 12.5px; color: var(--text-dim, #6b7280); min-height: 18px; }
        .im-detected .ok { color: #166534; }
        .im-target { font-size: 13px; color: var(--text-dim, #6b7280); margin-bottom: 10px; }
        .im-target strong { color: var(--text, #222); }
        .im-muted { color: var(--text-dim, #9ca3af); }
        .im-target code { background: var(--surface-2, #eceff3); padding: 1px 5px; border-radius: 4px; font-size: 11.5px; }
        .im-result { margin-top: 14px; font-size: 13px; }
        .im-result .err { color: #b91c1c; }
        .im-result .ok { color: #166534; font-weight: 600; }
        .im-tiles { display: flex; gap: 12px; flex-wrap: wrap; margin: 10px 0; }
        .im-tile { background: var(--surface-alt, #f9fafb); border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 8px 14px; font-size: 12.5px; color: var(--text-dim, #6b7280); }
        .im-tile strong { display: block; font-size: 20px; color: var(--text, #222); }
        .im-result table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
        .im-result td, .im-result th { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border, #eee); }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="im-wrap">
            <h2>Mass import</h2>
            <p class="im-lead">Load CSV into any data module. Lookups are matched by name &mdash; a status, priority, supplier,
               analyst or requester must already exist, and an unrecognised name is reported rather than silently blanked.
               Nothing is written until you commit, and a commit is one transaction: it either all lands or none of it does.
               The column names match <a href="../../reporting/export/">Reporting &rsaquo; Export</a>, so you can export, edit and re-import.</p>
            <p class="im-lead"><strong>Coming from another helpdesk?</strong> Use
               <a href="../migrate/">Migrate from another system</a> instead. It takes the file your old platform
               exported as-is &mdash; you map its column names and its status and category values onto ours, and it
               reconciles what was loaded so you can prove nothing was lost before cutover.</p>
            <p class="im-lead"><strong>Need test data?</strong> The repository ships a ready-made UAT bundle in
               <code>database/demo-csv/</code> &mdash; seventeen interlocking files (customers, suppliers, contracts, assets,
               tickets, problems, changes, certifications and more) with a README giving the load order. Import them in
               filename order and you have a populated system to test against.</p>

            <div class="im-card">
                <h3>1. Choose what you are importing</h3>
                <div class="im-row">
                    <div class="im-field">
                        <label for="imModule">Module</label>
                        <select id="imModule" onchange="imRenderDatasets()"></select>
                    </div>
                    <div class="im-field">
                        <label for="imDataset">Dataset</label>
                        <select id="imDataset" onchange="imRenderContract()"></select>
                    </div>
                    <button class="btn btn-secondary" onclick="imTemplate()">Download template</button>
                </div>
                <div class="im-contract" id="imContract">Loading datasets&hellip;</div>
            </div>

            <div class="im-card">
                <h3>2. Paste or choose your CSV</h3>
                <div class="im-row">
                    <div class="im-field">
                        <label for="imFile">CSV file</label>
                        <input type="file" id="imFile" accept=".csv,text/csv" onchange="imReadFile()">
                    </div>
                    <span class="im-tile" id="imFileState">or paste below</span>
                </div>
                <textarea id="imCsv" placeholder="first row = column names, e.g.&#10;subject,status,priority,owner&#10;Printer offline,Open,High,Jane Bloggs"></textarea>
                <div class="im-detected" id="imDetected"></div>
            </div>

            <div class="im-card">
                <h3>3. Preview, then commit</h3>
                <div class="im-target" id="imTarget"></div>
                <div class="im-row">
                    <button class="btn btn-secondary" onclick="imPreview()">Preview</button>
                    <button class="btn btn-primary" id="imCommit" onclick="imCommit()" disabled>Commit import</button>
                    <span class="im-tile" id="imCommitHint">Preview first &mdash; commit unlocks once a preview succeeds.</span>
                </div>
                <div class="im-result" id="imResult"></div>
            </div>
        </div>
    </div>

    <script src="import.js?v=1"></script>
</body>
</html>
