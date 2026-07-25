<?php
/**
 * Reporting — Data export.
 *
 * Pick a module, pick a dataset, optionally bound it by date, download CSV or
 * JSON. The dataset list comes from api/export/get_datasets.php, which only
 * offers what this analyst may see, so the page needs no permission logic of its
 * own beyond the Reporting module gate.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('reporting');

$current_page = 'export';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporting - Data export</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .ex-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .ex-wrap h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .ex-lead { margin: 0; font-size: 13px; color: var(--text-dim, #6b7280); max-width: 760px; }
        .ex-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); max-width: 860px; }
        .ex-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .ex-field { display: flex; flex-direction: column; gap: 4px; }
        .ex-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .ex-field select, .ex-field input {
            padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #222); min-width: 160px;
        }
        .ex-field select#exDataset { min-width: 260px; }
        .ex-desc { margin-top: 12px; font-size: 13px; color: var(--text-dim, #6b7280); min-height: 20px; }
        .ex-desc .ex-tag { display: inline-block; margin-right: 6px; padding: 1px 7px; border-radius: 10px; font-size: 11px; font-weight: 600; background: var(--surface-2, #eceff3); color: var(--text, #333); }
        .ex-note { font-size: 12px; color: var(--text-dim, #6b7280); margin-top: 10px; }
        .ex-err { color: #b91c1c; font-size: 13px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="ex-wrap">
            <h2>Data export</h2>
            <p class="ex-lead">Export the data behind any module you have access to, as CSV (opens in Excel) or JSON.
               Exports are read-only and respect your permissions: you only see datasets for your modules, and
               company-scoped data is limited to your active company.</p>

            <div class="ex-card">
                <div class="ex-row">
                    <div class="ex-field">
                        <label for="exModule">Module</label>
                        <select id="exModule" onchange="exRenderDatasets()"></select>
                    </div>
                    <div class="ex-field">
                        <label for="exDataset">Dataset</label>
                        <select id="exDataset" onchange="exRenderDesc()"></select>
                    </div>
                    <div class="ex-field">
                        <label for="exFrom">From</label>
                        <input type="date" id="exFrom">
                    </div>
                    <div class="ex-field">
                        <label for="exTo">To</label>
                        <input type="date" id="exTo">
                    </div>
                    <div class="ex-field">
                        <label for="exFormat">Format</label>
                        <select id="exFormat">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" id="exGo" onclick="exDownload()">Export</button>
                </div>
                <div class="ex-desc" id="exDesc">Loading datasets&hellip;</div>
                <div class="ex-note" id="exNote"></div>
            </div>
        </div>
    </div>

    <script src="export.js?v=1"></script>
</body>
</html>
