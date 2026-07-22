<?php
/**
 * Executive dashboard (Phase 8c) — a curated cross-module KPI view: open
 * tickets, SLA breach rate (from the Phase 8a snapshot), and by-dimension
 * breakdowns across tickets / assets / changes. All data comes from the single
 * api/reporting/get_exec_dashboard_data.php dispatcher, scoped to the analyst's
 * active company.
 *
 * v1 is deliberately curated (fixed KPI set), not the per-analyst customizable
 * widget-library of the module dashboards — per-analyst placement is a future
 * enhancement.
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

$current_page = 'executive';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Executive dashboard</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .exec-dash { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .exec-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .exec-head h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .exec-scope { font-size: 13px; color: var(--text-dim, #6b7280); }
        .exec-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
        .exec-tile { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .exec-tile .tile-label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); text-transform: uppercase; letter-spacing: 0.03em; }
        .exec-tile .tile-value { font-size: 32px; font-weight: 700; color: var(--text, #111827); margin-top: 6px; line-height: 1.1; }
        .exec-tile .tile-sub { font-size: 12px; color: var(--text-dim, #6b7280); margin-top: 4px; }
        .exec-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
        .exec-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .exec-card h3 { margin: 0 0 12px; font-size: 14px; color: var(--text, #222); }
        .exec-chart-wrap { height: 260px; position: relative; }
        .exec-empty { padding: 40px 10px; text-align: center; color: var(--text-dim, #999); font-size: 13px; }
        .exec-note { padding: 30px; text-align: center; color: var(--text-dim, #888); font-size: 14px; }
        .exec-error { color: #b91c1c; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="exec-dash">
            <div class="exec-head">
                <h2>Executive dashboard</h2>
                <span class="exec-scope" id="exScope"></span>
            </div>
            <div class="exec-tiles" id="exTiles"></div>
            <div class="exec-grid" id="exGrid">
                <div class="exec-note">Loading&hellip;</div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/chart.min.js?v=1"></script>
    <script src="exec.js?v=1"></script>
</body>
</html>
