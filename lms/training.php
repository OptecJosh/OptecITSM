<?php
/**
 * LMS — Training & certifications.
 *
 * One person's development record: certifications held (with expiry), training
 * completed outside the LMS, and their internal course completions. Everyone with
 * the LMS module sees their own; a line manager can switch to a direct report,
 * and an LMS manager to anyone. The certification catalogue is managed here too,
 * by LMS managers only.
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

$current_page = 'training';
$path_prefix = '../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LMS - Training &amp; certifications</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=57">
    <link rel="stylesheet" href="../assets/css/lms.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--lms-accent, #2563eb); }
        .tr-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f6fa); }
        .tr-inner { max-width: 1040px; margin: 0 auto; padding: 28px 28px 56px; }
        .tr-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .tr-head h1 { margin: 0 0 3px; font-size: 22px; color: var(--text, #1f2330); }
        .tr-head p { margin: 0; font-size: 13px; color: var(--text-muted, #6b7280); }
        .tr-head .spacer { flex: 1; }
        .tr-field { display: flex; flex-direction: column; gap: 4px; }
        .tr-field label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #6b7280); }
        .tr-field select, .tr-field input, .tr-field textarea {
            padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #222); box-sizing: border-box;
        }
        .tr-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; margin-bottom: 18px; overflow: hidden; }
        .tr-card-head { display: flex; align-items: center; gap: 10px; padding: 13px 18px; border-bottom: 1px solid var(--border, #e5e7eb); background: var(--surface-2, #f7f8fa); }
        .tr-card-head h2 { margin: 0; font-size: 14.5px; color: var(--text, #1f2330); }
        .tr-card-head .count { font-size: 12px; color: var(--text-muted, #6b7280); }
        .tr-card-head .spacer { flex: 1; }
        .tr-card-body { padding: 4px 0; }
        table.tr { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.tr th, table.tr td { text-align: left; padding: 9px 18px; border-bottom: 1px solid var(--border, #f0f1f3); color: var(--text, #222); vertical-align: top; }
        table.tr th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #6b7280); font-weight: 700; }
        table.tr tr:last-child td { border-bottom: none; }
        .tr-empty { padding: 22px 18px; color: var(--text-muted, #6b7280); font-size: 13px; }
        .tr-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
        .tr-pill.achieved { background: #dcfce7; color: #166534; }
        .tr-pill.in_progress, .tr-pill.planned { background: #e0f2fe; color: #075985; }
        .tr-pill.expired, .tr-pill.revoked { background: #fee2e2; color: #b91c1c; }
        .tr-warn { color: #b45309; font-weight: 600; }
        .tr-bad { color: #b91c1c; font-weight: 600; }
        .tr-muted { color: var(--text-muted, #6b7280); }
        .tr-actions button { margin-left: 6px; }
        .tr-modal-back { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 700; align-items: center; justify-content: center; }
        .tr-modal-back.active { display: flex; }
        .tr-modal { background: var(--surface, #fff); border-radius: 10px; padding: 20px; width: 520px; max-width: 94vw; max-height: 90vh; overflow: auto; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .tr-modal h3 { margin: 0 0 14px; font-size: 16px; color: var(--text, #222); }
        .tr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .tr-grid .full { grid-column: 1 / -1; }
        .tr-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
        .tr-err { color: #b91c1c; font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="tr-wrap">
        <div class="tr-inner">
            <div class="tr-head">
                <div>
                    <h1 id="trTitle">Training &amp; certifications</h1>
                    <p id="trSub">Loading&hellip;</p>
                </div>
                <div class="spacer"></div>
                <div class="tr-field" id="trPersonWrap" style="display:none;">
                    <label for="trPerson">Person</label>
                    <select id="trPerson" onchange="trSwitchPerson()"></select>
                </div>
            </div>

            <div class="tr-card">
                <div class="tr-card-head">
                    <h2>Certifications</h2>
                    <span class="count" id="trCertCount"></span>
                    <div class="spacer"></div>
                    <button class="btn btn-primary btn-sm" id="trCertAdd" onclick="trCertModal()" style="display:none;">Add certification</button>
                </div>
                <div class="tr-card-body" id="trCerts"></div>
            </div>

            <div class="tr-card">
                <div class="tr-card-head">
                    <h2>Training completed</h2>
                    <span class="count" id="trTrainCount"></span>
                    <div class="spacer"></div>
                    <button class="btn btn-primary btn-sm" id="trTrainAdd" onclick="trTrainModal()" style="display:none;">Add training</button>
                </div>
                <div class="tr-card-body" id="trTraining"></div>
            </div>

            <div class="tr-card">
                <div class="tr-card-head">
                    <h2>Courses in this LMS</h2>
                    <span class="count" id="trCourseCount"></span>
                </div>
                <div class="tr-card-body" id="trCourses"></div>
            </div>

            <div class="tr-card" id="trCatalogueCard" style="display:none;">
                <div class="tr-card-head">
                    <h2>Certification catalogue</h2>
                    <span class="count tr-muted">Organisation-wide &middot; LMS managers only</span>
                    <div class="spacer"></div>
                    <button class="btn btn-secondary btn-sm" onclick="trCatModal()">Add to catalogue</button>
                </div>
                <div class="tr-card-body" id="trCatalogue"></div>
            </div>
        </div>
    </div>

    <!-- certification (held) -->
    <div class="tr-modal-back" id="trCertBack">
        <div class="tr-modal">
            <h3 id="trCertTitle">Add certification</h3>
            <input type="hidden" id="trCertId">
            <div class="tr-grid">
                <div class="tr-field full">
                    <label for="trCertCatalogue">From the catalogue</label>
                    <select id="trCertCatalogue" onchange="trCertPickCatalogue()"></select>
                </div>
                <div class="tr-field full">
                    <label for="trCertName">Certification *</label>
                    <input type="text" id="trCertName" maxlength="150" placeholder="e.g. ITIL 4 Foundation">
                </div>
                <div class="tr-field">
                    <label for="trCertIssuer">Issuer</label>
                    <input type="text" id="trCertIssuer" maxlength="150" placeholder="e.g. PeopleCert">
                </div>
                <div class="tr-field">
                    <label for="trCertStatus">Status</label>
                    <select id="trCertStatus"></select>
                </div>
                <div class="tr-field">
                    <label for="trCertAwarded">Awarded</label>
                    <input type="date" id="trCertAwarded">
                </div>
                <div class="tr-field">
                    <label for="trCertExpires">Expires</label>
                    <input type="date" id="trCertExpires">
                </div>
                <div class="tr-field">
                    <label for="trCertCredential">Credential / certificate ID</label>
                    <input type="text" id="trCertCredential" maxlength="120">
                </div>
                <div class="tr-field">
                    <label for="trCertEvidence">Evidence link</label>
                    <input type="url" id="trCertEvidence" maxlength="500" placeholder="https://...">
                </div>
                <div class="tr-field full">
                    <label for="trCertNotes">Notes</label>
                    <textarea id="trCertNotes" rows="2" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="tr-err" id="trCertErr"></div>
            <div class="tr-modal-actions">
                <button class="btn btn-secondary" onclick="trClose('trCertBack')">Cancel</button>
                <button class="btn btn-primary" onclick="trCertSave()">Save</button>
            </div>
        </div>
    </div>

    <!-- training record -->
    <div class="tr-modal-back" id="trTrainBack">
        <div class="tr-modal">
            <h3 id="trTrainTitle">Add training</h3>
            <input type="hidden" id="trTrainId">
            <div class="tr-grid">
                <div class="tr-field full">
                    <label for="trTrainName">Title *</label>
                    <input type="text" id="trTrainName" maxlength="200" placeholder="e.g. Cisco DNA Center workshop">
                </div>
                <div class="tr-field">
                    <label for="trTrainProvider">Provider</label>
                    <input type="text" id="trTrainProvider" maxlength="150">
                </div>
                <div class="tr-field">
                    <label for="trTrainType">Type</label>
                    <select id="trTrainType"></select>
                </div>
                <div class="tr-field">
                    <label for="trTrainDate">Completed</label>
                    <input type="date" id="trTrainDate">
                </div>
                <div class="tr-field">
                    <label for="trTrainHours">Hours</label>
                    <input type="number" step="0.25" min="0" id="trTrainHours">
                </div>
                <div class="tr-field">
                    <label for="trTrainCost">Cost</label>
                    <input type="number" step="0.01" min="0" id="trTrainCost">
                </div>
                <div class="tr-field">
                    <label for="trTrainEvidence">Evidence link</label>
                    <input type="url" id="trTrainEvidence" maxlength="500" placeholder="https://...">
                </div>
                <div class="tr-field full">
                    <label for="trTrainNotes">Notes</label>
                    <textarea id="trTrainNotes" rows="2" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="tr-err" id="trTrainErr"></div>
            <div class="tr-modal-actions">
                <button class="btn btn-secondary" onclick="trClose('trTrainBack')">Cancel</button>
                <button class="btn btn-primary" onclick="trTrainSave()">Save</button>
            </div>
        </div>
    </div>

    <!-- catalogue entry -->
    <div class="tr-modal-back" id="trCatBack">
        <div class="tr-modal">
            <h3 id="trCatTitle">Add to catalogue</h3>
            <input type="hidden" id="trCatId">
            <div class="tr-grid">
                <div class="tr-field full">
                    <label for="trCatName">Name *</label>
                    <input type="text" id="trCatName" maxlength="150">
                </div>
                <div class="tr-field">
                    <label for="trCatIssuer">Issuer</label>
                    <input type="text" id="trCatIssuer" maxlength="150">
                </div>
                <div class="tr-field">
                    <label for="trCatValidity">Valid for (months)</label>
                    <input type="number" min="1" step="1" id="trCatValidity" placeholder="blank = no expiry">
                </div>
                <div class="tr-field full">
                    <label for="trCatDesc">Description</label>
                    <input type="text" id="trCatDesc" maxlength="500">
                </div>
                <div class="tr-field">
                    <label for="trCatOrder">Display order</label>
                    <input type="number" step="1" id="trCatOrder" value="0">
                </div>
                <div class="tr-field">
                    <label><input type="checkbox" id="trCatActive" checked> Active</label>
                </div>
            </div>
            <div class="tr-err" id="trCatErr"></div>
            <div class="tr-modal-actions">
                <button class="btn btn-secondary" onclick="trClose('trCatBack')">Cancel</button>
                <button class="btn btn-primary" onclick="trCatSave()">Save</button>
            </div>
        </div>
    </div>

    <script>window.API_BASE = '../api/lms/';</script>
    <script src="../assets/js/lms-training.js?v=1"></script>
</body>
</html>
