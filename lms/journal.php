<?php
/**
 * LMS — Training & development journal.
 *
 * A private, two-person record: goals, progress, reflections, feedback and 1:1
 * notes. Visible ONLY to the analyst it belongs to and their line manager
 * (analysts.manager_id) — LMS managers and admins are not given access, by
 * design. The gate is lmsJournalCanAccess(), enforced by every journal endpoint.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
require_once '../includes/rbac.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('lms');

$current_page = 'journal';
$path_prefix = '../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LMS - Development journal</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/lms.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--lms-accent, #2563eb); }
        .jr-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f6fa); }
        .jr-inner { max-width: 860px; margin: 0 auto; padding: 28px 28px 56px; }
        .jr-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 8px; }
        .jr-head h1 { margin: 0 0 3px; font-size: 22px; color: var(--text, #1f2330); }
        .jr-head p { margin: 0; font-size: 13px; color: var(--text-muted, #6b7280); }
        .jr-head .spacer { flex: 1; }
        .jr-privacy {
            display: flex; align-items: flex-start; gap: 8px; margin: 0 0 20px;
            padding: 10px 13px; border-radius: 8px; font-size: 12.5px; line-height: 1.5;
            background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe;
        }
        .jr-field { display: flex; flex-direction: column; gap: 4px; }
        .jr-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #6b7280); }
        .jr-field select, .jr-field input, .jr-field textarea {
            padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #222); box-sizing: border-box;
        }
        .jr-entry { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 15px 18px; margin-bottom: 12px; }
        .jr-entry-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .jr-entry-head h3 { margin: 0; font-size: 15px; color: var(--text, #1f2330); }
        .jr-entry-head .spacer { flex: 1; }
        .jr-cat { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: capitalize; background: var(--surface-2, #eceff3); color: var(--text, #333); }
        .jr-cat.goal { background: #ede9fe; color: #5b21b6; }
        .jr-cat.feedback, .jr-cat.one_to_one { background: #fef3c7; color: #92400e; }
        .jr-cat.reflection { background: #e0f2fe; color: #075985; }
        .jr-meta { font-size: 12px; color: var(--text-muted, #6b7280); margin-top: 3px; }
        .jr-body { margin-top: 10px; font-size: 13.5px; line-height: 1.6; color: var(--text, #222); white-space: pre-wrap; }
        .jr-empty { text-align: center; padding: 50px 20px; color: var(--text-muted, #6b7280); }
        .jr-empty h3 { margin: 0 0 6px; font-size: 16px; color: var(--text, #1f2330); }
        .jr-err { color: #b91c1c; font-size: 13px; margin-top: 8px; }
        .jr-modal-back { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 700; align-items: center; justify-content: center; }
        .jr-modal-back.active { display: flex; }
        .jr-modal { background: var(--surface, #fff); border-radius: 10px; padding: 20px; width: 560px; max-width: 94vw; max-height: 90vh; overflow: auto; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .jr-modal h3 { margin: 0 0 14px; font-size: 16px; color: var(--text, #222); }
        .jr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .jr-grid .full { grid-column: 1 / -1; }
        .jr-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="jr-wrap">
        <div class="jr-inner">
            <div class="jr-head">
                <div>
                    <h1 id="jrTitle">Development journal</h1>
                    <p id="jrSub">Loading&hellip;</p>
                </div>
                <div class="spacer"></div>
                <div class="jr-field" id="jrPickWrap" style="display:none;">
                    <label for="jrPick">Journal</label>
                    <select id="jrPick" onchange="jrSwitch()"></select>
                </div>
                <button class="btn btn-primary" id="jrAdd" onclick="jrModal()" style="display:none;">New entry</button>
            </div>

            <div class="jr-privacy" id="jrPrivacy">
                This journal is visible to you and your line manager only. Nobody else &mdash; not LMS managers,
                not administrators &mdash; can open it, and it is excluded from data exports.
            </div>

            <div id="jrList"></div>
        </div>
    </div>

    <div class="jr-modal-back" id="jrBack">
        <div class="jr-modal">
            <h3 id="jrModalTitle">New entry</h3>
            <input type="hidden" id="jrId">
            <div class="jr-grid">
                <div class="jr-field">
                    <label for="jrDate">Date</label>
                    <input type="date" id="jrDate">
                </div>
                <div class="jr-field">
                    <label for="jrCategory">Category</label>
                    <select id="jrCategory"></select>
                </div>
                <div class="jr-field full">
                    <label for="jrEntryTitle">Title *</label>
                    <input type="text" id="jrEntryTitle" maxlength="200" placeholder="e.g. Goal: pass CCNA by Q4">
                </div>
                <div class="jr-field full">
                    <label for="jrBody">Detail</label>
                    <textarea id="jrBody" rows="9" maxlength="20000" placeholder="What happened, what you learned, what you agreed, what happens next&hellip;"></textarea>
                </div>
            </div>
            <div class="jr-err" id="jrErr"></div>
            <div class="jr-modal-actions">
                <button class="btn btn-secondary" onclick="jrClose()">Cancel</button>
                <button class="btn btn-primary" onclick="jrSave()">Save</button>
            </div>
        </div>
    </div>

    <script>window.API_BASE = '../api/lms/';</script>
    <script src="../assets/js/lms-journal.js?v=1"></script>
</body>
</html>
