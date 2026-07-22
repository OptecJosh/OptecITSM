<?php
/**
 * Change freeze windows (Phase 9b) — admin screen to define blackout periods.
 * Data via api/change-management/{get,save,delete}_freeze_window.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}
requireModuleAccess('changes');

$current_page = 'freeze';
$path_prefix = '../../';
$translationNamespaces = ['common', 'change-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Change freeze windows</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--cm-accent, #00897b); --accent-hover: var(--cm-accent-hover, #00695c); }
        .fz-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .fz-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .fz-head h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .fz-head p { margin: 4px 0 0; font-size: 13px; color: var(--text-dim, #6b7280); max-width: 640px; }
        .fz-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        table.fz { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.fz th, table.fz td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); vertical-align: top; }
        table.fz th { color: var(--text-dim, #6b7280); font-weight: 600; }
        .fz-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .fz-badge.on { background: #dcfce7; color: #166534; }
        .fz-badge.off { background: #f3f4f6; color: #6b7280; }
        .fz-badge.now { background: #fee2e2; color: #b91c1c; }
        .fz-actions button { margin-left: 6px; }
        .fz-note { padding: 24px; text-align: center; color: var(--text-dim, #888); font-size: 13px; }
        .fz-error { color: #b91c1c; }
        /* modal */
        .fz-modal-back { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 600; align-items: center; justify-content: center; }
        .fz-modal-back.active { display: flex; }
        .fz-modal { background: var(--surface, #fff); border-radius: 10px; padding: 20px; width: 440px; max-width: 92vw; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .fz-modal h3 { margin: 0 0 14px; font-size: 16px; color: var(--text, #222); }
        .fz-field { margin-bottom: 12px; }
        .fz-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); margin-bottom: 4px; }
        .fz-field input[type=text], .fz-field input[type=datetime-local], .fz-field textarea {
            width: 100%; padding: 8px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #222); box-sizing: border-box;
        }
        .fz-field textarea { resize: vertical; min-height: 56px; }
        .fz-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text, #222); }
        .fz-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="fz-wrap">
            <div class="fz-head">
                <div>
                    <h2>Change freeze windows</h2>
                    <p>Blackout periods when changes should not be scheduled. Scheduling or approving a change inside a freeze raises a soft warning; Emergency-type changes are exempt.</p>
                </div>
                <button class="btn btn-primary" id="fzAddBtn" onclick="fzOpenModal()" style="display:none;">New freeze window</button>
            </div>
            <div class="fz-card" id="fzTable"><div class="fz-note">Loading&hellip;</div></div>
        </div>
    </div>

    <div class="fz-modal-back" id="fzModalBack">
        <div class="fz-modal">
            <h3 id="fzModalTitle">New freeze window</h3>
            <input type="hidden" id="fzId">
            <div class="fz-field"><label>Name</label><input type="text" id="fzName" placeholder="e.g. December change freeze"></div>
            <div class="fz-field"><label>Starts</label><input type="datetime-local" id="fzStart"></div>
            <div class="fz-field"><label>Ends</label><input type="datetime-local" id="fzEnd"></div>
            <div class="fz-field"><label>Reason (optional)</label><textarea id="fzReason" maxlength="500"></textarea></div>
            <div class="fz-field"><label class="fz-check"><input type="checkbox" id="fzActive" checked> Active</label></div>
            <div class="fz-modal-actions">
                <button class="btn btn-secondary" onclick="fzCloseModal()">Cancel</button>
                <button class="btn btn-primary" onclick="fzSave()">Save</button>
            </div>
        </div>
    </div>

    <script src="freeze.js?v=1"></script>
</body>
</html>
