<?php
/**
 * Software Licence Compliance (Phase 9a) — installed vs entitled true-up.
 * Data from api/software/get_licence_compliance.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('software');

$current_page = 'compliance';
$path_prefix = '../../';
$translationNamespaces = ['common', 'software'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Software - Licence compliance</title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { --accent: var(--sw-accent, #5c6bc0); --accent-hover: var(--sw-accent-hover, #3f51b5); }
        .comp-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .comp-head h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .comp-head p { margin: 4px 0 0; font-size: 13px; color: var(--text-dim, #6b7280); }
        .comp-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 16px; }
        .comp-tile { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .comp-tile .t-label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); text-transform: uppercase; letter-spacing: 0.03em; }
        .comp-tile .t-value { font-size: 28px; font-weight: 700; color: var(--text, #111827); margin-top: 4px; }
        .comp-tile.warn .t-value { color: #b45309; }
        .comp-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        table.comp { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.comp th, table.comp td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); }
        table.comp th { color: var(--text-dim, #6b7280); font-weight: 600; }
        table.comp td.num, table.comp th.num { text-align: right; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge.over { background: #fee2e2; color: #b91c1c; }
        .badge.ok { background: #dcfce7; color: #166534; }
        .badge.unused { background: #f3f4f6; color: #6b7280; }
        .comp-note { padding: 30px; text-align: center; color: var(--text-dim, #888); font-size: 14px; }
        .comp-error { color: #b91c1c; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="comp-wrap">
            <div class="comp-head">
                <h2>Licence compliance</h2>
                <p>Installed vs entitled across apps that carry an active licence. Over-deployment is a compliance risk; unused entitlement is spend to review.</p>
            </div>
            <div class="comp-tiles" id="compTiles"></div>
            <div class="comp-card" id="compTable">
                <div class="comp-note">Loading&hellip;</div>
            </div>
        </div>
    </div>

    <script src="compliance.js?v=1"></script>
</body>
</html>
