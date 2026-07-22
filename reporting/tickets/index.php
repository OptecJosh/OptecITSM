<?php
/**
 * Ticket Reports (Phase 4A) — ad-hoc reporting: filter the ticket set and group
 * it by a dimension for a count breakdown (table + bar chart + CSV). Reuses the
 * shared filter engine via api/reporting/get_ticket_report.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('reporting');

$current_page = 'tickets';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('reporting.tickets.heading')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .report-builder { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .rb-header h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .rb-controls { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .rb-row { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
        .rb-inline { display: flex; flex-direction: column; gap: 4px; }
        .rb-inline label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .rb-inline select, .rb-inline input { padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); }
        .rb-filters-wrap { margin-top: 12px; }
        .rb-filters-wrap summary { cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .rb-filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-top: 12px; }
        .rb-field-label { font-size: 12px; font-weight: 600; margin-bottom: 5px; color: var(--text, #111827); }
        .rb-options { max-height: 140px; overflow: auto; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; padding: 6px 8px; background: var(--surface, #fff); display: flex; flex-direction: column; gap: 3px; }
        .rb-opt { display: flex; align-items: center; gap: 6px; font-size: 12.5px; cursor: pointer; color: var(--text, #222); }
        .report-results { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .rb-summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; font-size: 14px; color: var(--text, #222); flex-wrap: wrap; }
        .rb-chart-wrap { height: 360px; margin-bottom: 16px; position: relative; }
        .rb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rb-table th, .rb-table td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); }
        .rb-table th { color: var(--text-dim, #6b7280); font-weight: 600; }
        .rb-num { text-align: right; }
        .rb-note { padding: 30px; text-align: center; color: var(--text-dim, #888); font-size: 14px; }
        .rb-error { color: #b91c1c; }
        /* Scheduled reports (Phase 8b) */
        .rb-sched-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .rb-sched-head h3 { margin: 0; font-size: 15px; color: var(--text, #222); }
        .rb-sched-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        .rb-sched-table th, .rb-sched-table td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); }
        .rb-sched-table th { color: var(--text-dim, #6b7280); font-weight: 600; }
        .rb-sched-badge { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 10px; background: var(--border, #eef); color: var(--text-dim, #555); margin-left: 6px; }
        .rb-sched-off { opacity: 0.55; }
        .rb-linkbtn { background: none; border: none; color: #ca5010; cursor: pointer; font-size: 12.5px; padding: 0 4px; }
        .rb-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
        .rb-modal { background: var(--surface, #fff); border-radius: 10px; padding: 20px 22px; width: 440px; max-width: 92vw; max-height: 90vh; overflow: auto; box-shadow: 0 8px 30px rgba(0,0,0,0.2); }
        .rb-modal h3 { margin: 0 0 14px; font-size: 17px; color: var(--text, #222); }
        .rb-modal label { display: block; font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); margin-top: 12px; }
        .rb-modal input[type=text], .rb-modal select, .rb-modal textarea { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); }
        .rb-modal textarea { min-height: 58px; resize: vertical; }
        .rb-modal-context { margin-top: 10px; padding: 8px 10px; background: var(--app-bg, #f5f7fa); border-radius: 6px; font-size: 12.5px; color: var(--text-dim, #555); }
        .rb-modal-check { display: flex; align-items: center; gap: 7px; font-weight: 500; margin-top: 12px; color: var(--text, #222); }
        .rb-modal-check input { width: auto; margin: 0; }
        .rb-modal-err { color: #b91c1c; font-size: 12.5px; margin-top: 10px; min-height: 16px; }
        .rb-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="report-builder">
            <div class="rb-header"><h2><?php echo htmlspecialchars(t('reporting.tickets.heading')); ?></h2></div>
            <div class="rb-controls">
                <div class="rb-row">
                    <div class="rb-inline"><label>Group by</label><select id="rbGroupBy"></select></div>
                    <div class="rb-inline"><label>Created from</label><input type="date" id="rbFrom"></div>
                    <div class="rb-inline"><label>Created to</label><input type="date" id="rbTo"></div>
                    <div class="rb-inline"><label>Keyword</label><input type="text" id="rbKeyword" placeholder="Subject or ticket #"></div>
                    <button class="btn btn-primary" onclick="runReport()">Run report</button>
                    <button class="btn btn-secondary" onclick="openScheduleModal()">Schedule&hellip;</button>
                </div>
                <details class="rb-filters-wrap" open>
                    <summary>Filters</summary>
                    <div class="rb-filters" id="rbFilters"></div>
                </details>
            </div>
            <div class="report-results" id="rbResults">
                <div class="rb-note">Choose a grouping, set any filters, then click &ldquo;Run report&rdquo;.</div>
            </div>

            <div class="rb-controls" id="rbScheduledCard" style="display:none;">
                <div class="rb-sched-head"><h3>Scheduled reports</h3></div>
                <div id="rbScheduled"></div>
            </div>
        </div>
    </div>

    <div id="rbSchedModal" class="rb-modal-overlay" style="display:none;">
        <div class="rb-modal">
            <h3 id="rbSchedModalTitle">Schedule report</h3>
            <input type="hidden" id="rbSchedId">
            <div class="rb-modal-context" id="rbSchedContext"></div>
            <label>Name<input type="text" id="rbSchedName" maxlength="150" placeholder="e.g. Weekly open tickets by priority"></label>
            <label>Cadence
                <select id="rbSchedCadence">
                    <option value="daily">Daily</option>
                    <option value="weekly" selected>Weekly (Mondays)</option>
                    <option value="monthly">Monthly (1st)</option>
                </select>
            </label>
            <label>Delivery format
                <select id="rbSchedFormat">
                    <option value="both" selected>Summary + CSV</option>
                    <option value="summary">Summary only</option>
                    <option value="csv">CSV only</option>
                </select>
            </label>
            <label>Recipients<textarea id="rbSchedRecipients" placeholder="email@example.com, another@example.com"></textarea></label>
            <label class="rb-modal-check" id="rbSchedSharedWrap" style="display:none;"><input type="checkbox" id="rbSchedShared"> Shared (visible to all analysts)</label>
            <label class="rb-modal-check"><input type="checkbox" id="rbSchedActive" checked> Active</label>
            <div class="rb-modal-err" id="rbSchedErr"></div>
            <div class="rb-modal-actions">
                <button class="btn btn-secondary" onclick="closeScheduleModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveSchedule()">Save schedule</button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/chart.min.js?v=1"></script>
    <script src="report.js?v=3"></script>
</body>
</html>
