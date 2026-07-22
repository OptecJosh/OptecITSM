<?php
/**
 * System — Backup & data (Phase 10b).
 *
 * On-demand DB backup (streaming mysqldump download) + CSV export/import for
 * assets and portal users. Admin-gated by the system header.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';

$current_page = 'backup';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System - Backup &amp; data</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .bk-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .bk-wrap h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .bk-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); max-width: 760px; }
        .bk-card h3 { margin: 0 0 6px; font-size: 15px; color: var(--text, #222); }
        .bk-card p { margin: 0 0 12px; font-size: 13px; color: var(--text-dim, #6b7280); }
        .bk-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .bk-field { display: flex; flex-direction: column; gap: 4px; }
        .bk-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        select, .bk-field input { padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); }
        textarea#bkCsv { width: 100%; min-height: 120px; box-sizing: border-box; font-family: ui-monospace, monospace; font-size: 12px; padding: 8px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #222); margin-top: 10px; }
        .bk-warn { color: #b45309; font-size: 13px; }
        .bk-muted { color: var(--text-dim, #6b7280); font-size: 12px; }
        .bk-result { margin-top: 12px; font-size: 13px; }
        .bk-result .err { color: #b91c1c; }
        .bk-result table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
        .bk-result td, .bk-result th { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--border, #eee); }
        .bk-tiles { display: flex; gap: 16px; flex-wrap: wrap; }
        .bk-tile { background: var(--surface-alt,#f9fafb); border: 1px solid var(--border,#e5e7eb); border-radius: 8px; padding: 10px 14px; font-size: 13px; }
        .bk-tile strong { font-size: 20px; display: block; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="bk-wrap">
            <h2>Backup &amp; data</h2>

            <div class="bk-card">
                <h3>Database backup</h3>
                <p>Download a full SQL dump of the database. It streams straight to your browser and is never stored on the server &mdash; keep it wherever your backup policy requires.</p>
                <div class="bk-row">
                    <button class="btn btn-primary" id="bkBackupBtn" onclick="bkDownloadBackup()" disabled>Download backup</button>
                    <span id="bkBackupState" class="bk-muted">Checking availability&hellip;</span>
                </div>
            </div>

            <div class="bk-card">
                <h3>Export</h3>
                <p>Export an entity to CSV. The header row matches what import expects, so you can export, edit in a spreadsheet, and re-import.</p>
                <div class="bk-row">
                    <div class="bk-field">
                        <label>Entity</label>
                        <select id="bkExportEntity">
                            <option value="assets">Assets</option>
                            <option value="users">Users (portal)</option>
                        </select>
                    </div>
                    <button class="btn btn-secondary" onclick="bkExport()">Export CSV</button>
                </div>
            </div>

            <div class="bk-card">
                <h3>Import</h3>
                <p>Paste CSV (first row = column names). Recognised columns are matched by
                   <strong>hostname</strong> (assets) or <strong>email</strong> (users) to create or update records;
                   other columns are ignored. Preview first &mdash; nothing is written until you commit.</p>
                <div class="bk-row">
                    <div class="bk-field">
                        <label>Entity</label>
                        <select id="bkImportEntity">
                            <option value="assets">Assets</option>
                            <option value="users">Users (portal)</option>
                        </select>
                    </div>
                    <button class="btn btn-secondary" onclick="bkPreview()">Preview</button>
                    <button class="btn btn-primary" id="bkCommitBtn" onclick="bkCommit()" disabled>Commit import</button>
                </div>
                <textarea id="bkCsv" placeholder="hostname,manufacturer,model&#10;LAPTOP-01,Dell,Latitude 7440" oninput="bkResetCommit()"></textarea>
                <div class="bk-result" id="bkResult"></div>
            </div>
        </div>
    </div>

    <script src="backup.js?v=1"></script>
</body>
</html>
