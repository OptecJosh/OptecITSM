<?php
/**
 * Overtime — My overtime (Phase 11a): submit a request + see your own history.
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

$conn = connectToDatabase();   // header uses it to decide the Approvals tab
$current_page = 'mine';
$path_prefix = '../';
$translationNamespaces = ['common'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overtime - My overtime</title>
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
        .ot-cols { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start; }
        .ot-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        .ot-form { width: 320px; flex: 0 0 auto; }
        .ot-form h3, .ot-list h3 { margin: 0 0 12px; font-size: 15px; color: var(--text, #222); }
        .ot-list { flex: 1; min-width: 360px; }
        .ot-field { margin-bottom: 12px; display: flex; flex-direction: column; gap: 4px; }
        .ot-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .ot-field input, .ot-field select, .ot-field textarea { padding: 8px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); }
        .ot-times { display: flex; gap: 10px; }
        .ot-times .ot-field { flex: 1; }
        .ot-tiles { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
        .ot-tile { background: var(--surface-alt,#f9fafb); border: 1px solid var(--border,#e5e7eb); border-radius: 8px; padding: 8px 12px; font-size: 12px; color: var(--text-dim,#6b7280); }
        .ot-tile strong { display: block; font-size: 18px; color: var(--text,#111827); }
        table.ot { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.ot th, table.ot td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); }
        table.ot th { color: var(--text-dim, #6b7280); font-weight: 600; }
        .ot-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .ot-badge.pending { background: #fef3c7; color: #92400e; }
        .ot-badge.approved { background: #dcfce7; color: #166534; }
        .ot-badge.rejected { background: #fee2e2; color: #b91c1c; }
        .ot-badge.cancelled { background: #f3f4f6; color: #6b7280; }
        .ot-note { padding: 24px; text-align: center; color: var(--text-dim, #888); font-size: 13px; }
        .ot-err { color: #b91c1c; font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <div class="ot-wrap">
            <h2>My overtime</h2>
            <div class="ot-cols">
                <div class="ot-card ot-form">
                    <h3>Log overtime</h3>
                    <div class="ot-field"><label>Date</label><input type="date" id="otDate"></div>
                    <div class="ot-times">
                        <div class="ot-field"><label>Start</label><input type="time" id="otStart"></div>
                        <div class="ot-field"><label>End</label><input type="time" id="otEnd"></div>
                    </div>
                    <div class="ot-field">
                        <label>Type</label>
                        <select id="otType">
                            <option value="standard">Standard (1&times;)</option>
                            <option value="time_and_half">Time and a half (1.5&times;)</option>
                            <option value="double">Double (2&times;)</option>
                        </select>
                    </div>
                    <div class="ot-field"><label>Reason (optional)</label><textarea id="otReason" rows="2" maxlength="500"></textarea></div>
                    <button class="btn btn-primary" onclick="otSubmit()">Submit</button>
                    <div class="ot-err" id="otErr"></div>
                </div>
                <div class="ot-card ot-list">
                    <h3>History</h3>
                    <div class="ot-tiles" id="otTiles"></div>
                    <div id="otHistory"><div class="ot-note">Loading&hellip;</div></div>
                </div>
            </div>
        </div>
    </div>

    <script src="overtime.js?v=1"></script>
</body>
</html>
