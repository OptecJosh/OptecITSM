<?php
/**
 * Overtime — Report (Phase 11d): per-agent hours + payroll-weighted totals for a
 * period, with CSV export. Managers/admins only (header-gated); the API scopes
 * data to own+reports (or all for admins) and to the active company.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('overtime');

$conn = connectToDatabase();
$current_page = 'report';
$path_prefix = '../';
$translationNamespaces = ['common'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overtime - Report</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--ot-accent, #c026d3); --accent-hover: var(--ot-accent-hover, #a21caf); }
        .ot-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .ot-wrap h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .ot-controls { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .ot-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .ot-field { display: flex; flex-direction: column; gap: 4px; }
        .ot-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .ot-field input, .ot-field select { padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); }
        .ot-results { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        table.ot { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.ot th, table.ot td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); }
        table.ot th { color: var(--text-dim, #6b7280); font-weight: 600; }
        table.ot td.num, table.ot th.num { text-align: right; }
        table.ot tfoot td { font-weight: 700; border-top: 2px solid var(--border, #e5e7eb); }
        .ot-note { padding: 30px; text-align: center; color: var(--text-dim, #888); font-size: 14px; }
        .ot-err { color: #b91c1c; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <div class="ot-wrap">
            <h2>Overtime report</h2>
            <div class="ot-controls">
                <div class="ot-row">
                    <div class="ot-field"><label>From</label><input type="date" id="rFrom"></div>
                    <div class="ot-field"><label>To</label><input type="date" id="rTo"></div>
                    <div class="ot-field">
                        <label>Status</label>
                        <select id="rStatus">
                            <option value="approved">Approved</option>
                            <option value="pending">Pending</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" onclick="otReport()">Run</button>
                    <button class="btn btn-secondary" onclick="otReportCsv()">Export CSV</button>
                </div>
            </div>
            <div class="ot-results" id="rResults"><div class="ot-note">Set a period and run the report.</div></div>
        </div>
    </div>

    <script src="report.js?v=1"></script>
</body>
</html>
