<?php
/**
 * System — Unified audit log (Phase 10a).
 *
 * Read-only aggregator view over every module's audit trail
 * (api/system/get_audit_log.php). Admin-gated by the system header.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';

$current_page = 'audit';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System - Audit log</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .audit-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .audit-head h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .audit-head p { margin: 4px 0 0; font-size: 13px; color: var(--text-dim, #6b7280); }
        .audit-controls { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .audit-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .audit-field { display: flex; flex-direction: column; gap: 4px; }
        .audit-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .audit-field select, .audit-field input { padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); }
        .audit-results { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 8px 0; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        table.audit { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.audit th, table.audit td { text-align: left; padding: 8px 14px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); vertical-align: top; }
        table.audit th { color: var(--text-dim, #6b7280); font-weight: 600; white-space: nowrap; }
        table.audit td.detail { color: var(--text-dim, #4b5563); max-width: 420px; word-break: break-word; }
        .audit-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #eef2ff; color: #3730a3; }
        .audit-when { white-space: nowrap; color: var(--text-dim, #6b7280); }
        .audit-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; }
        .audit-note { padding: 30px; text-align: center; color: var(--text-dim, #888); font-size: 14px; }
        .audit-error { color: #b91c1c; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="audit-wrap">
            <div class="audit-head">
                <h2>Audit log</h2>
                <p>Every recorded change across tickets, changes, problems, assets and system events, in one stream. Read-only; the per-module trails remain authoritative.</p>
            </div>
            <div class="audit-controls">
                <div class="audit-row">
                    <div class="audit-field">
                        <label>Module</label>
                        <select id="fModule">
                            <option value="">All modules</option>
                            <option value="tickets">Tickets</option>
                            <option value="changes">Changes</option>
                            <option value="problems">Problems</option>
                            <option value="assets">Assets</option>
                            <option value="system">System</option>
                        </select>
                    </div>
                    <div class="audit-field">
                        <label>Actor</label>
                        <select id="fActor"><option value="">Anyone</option></select>
                    </div>
                    <div class="audit-field"><label>From</label><input type="date" id="fFrom"></div>
                    <div class="audit-field"><label>To</label><input type="date" id="fTo"></div>
                    <div class="audit-field"><label>Keyword</label><input type="text" id="fKeyword" placeholder="Entity or detail"></div>
                    <button class="btn btn-primary" onclick="auditApply()">Apply</button>
                    <button class="btn btn-secondary" onclick="auditExportCsv()">Export CSV</button>
                </div>
            </div>
            <div class="audit-results" id="auditResults">
                <div class="audit-note">Loading&hellip;</div>
            </div>
        </div>
    </div>

    <script src="audit.js?v=1"></script>
</body>
</html>
