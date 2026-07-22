<?php
/**
 * Overtime — Approvals (Phase 11b): a line manager / admin approves or rejects
 * pending overtime. The header only shows this tab to managers/admins; the API
 * re-checks authoritatively.
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
$current_page = 'approvals';
$path_prefix = '../';
$translationNamespaces = ['common'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overtime - Approvals</title>
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
        .ot-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 8px 0; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); }
        table.ot { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.ot th, table.ot td { text-align: left; padding: 8px 14px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); vertical-align: top; }
        table.ot th { color: var(--text-dim, #6b7280); font-weight: 600; }
        .ot-actions { display: flex; gap: 6px; }
        .ot-note { padding: 30px; text-align: center; color: var(--text-dim, #888); font-size: 14px; }
        .ot-err { color: #b91c1c; }
        .ot-modal-back { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 600; align-items: center; justify-content: center; }
        .ot-modal-back.active { display: flex; }
        .ot-modal { background: var(--surface, #fff); border-radius: 10px; padding: 20px; width: 420px; max-width: 92vw; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .ot-modal h3 { margin: 0 0 12px; font-size: 16px; color: var(--text, #222); }
        .ot-modal textarea { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; min-height: 70px; font-size: 13px; background: var(--surface,#fff); color: var(--text,#222); }
        .ot-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <div class="ot-wrap">
            <h2>Overtime approvals</h2>
            <div class="ot-card" id="otQueue"><div class="ot-note">Loading&hellip;</div></div>
        </div>
    </div>

    <div class="ot-modal-back" id="otDecideBack">
        <div class="ot-modal">
            <h3 id="otDecideTitle">Decision</h3>
            <input type="hidden" id="otDecideId"><input type="hidden" id="otDecideAction">
            <label style="font-size:12px;font-weight:600;color:var(--text-dim,#6b7280);">Note (optional)</label>
            <textarea id="otDecideNote" maxlength="500" placeholder="Reason / context for the agent"></textarea>
            <div class="ot-modal-actions">
                <button class="btn btn-secondary" onclick="otCloseDecide()">Cancel</button>
                <button class="btn btn-primary" id="otDecideConfirm" onclick="otConfirmDecide()">Confirm</button>
            </div>
        </div>
    </div>

    <script src="approvals.js?v=1"></script>
</body>
</html>
