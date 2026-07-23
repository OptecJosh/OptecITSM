<?php
/**
 * Customers — accounts + contacts, with linked CMDB configuration items.
 * Master/detail: searchable list on the left, edit form + CIs on the right.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('customers');

$current_page = 'customers';
$path_prefix = '../';
$translationNamespaces = ['common'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--cust-accent, #0369a1); --accent-hover: var(--cust-accent-hover, #075985); }
        .cu-wrap { flex: 1; display: grid; grid-template-columns: 320px minmax(0,1fr); gap: 16px; padding: 20px 24px; overflow: hidden; background: var(--app-bg, #f5f7fa); }
        .cu-panel { background: var(--surface,#fff); border: 1px solid var(--border,#e5e7eb); border-radius: 10px; box-shadow: 0 1px 3px var(--shadow,rgba(0,0,0,0.05)); display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
        .cu-list-head { padding: 12px; border-bottom: 1px solid var(--border,#eee); display: flex; gap: 8px; }
        .cu-list-head input { flex: 1; padding: 7px 9px; border: 1px solid var(--border,#e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface,#fff); color: var(--text,#222); }
        .cu-list { overflow-y: auto; }
        .cu-row { padding: 10px 12px; border-bottom: 1px solid var(--border,#f0f0f0); cursor: pointer; }
        .cu-row:hover { background: var(--surface-2,#eef2f6); }
        .cu-row.active { background: var(--accent-soft,#e0f2fe); }
        .cu-row .nm { font-weight: 600; font-size: 13.5px; color: var(--text,#222); }
        .cu-row .meta { font-size: 12px; color: var(--text-dim,#6b7280); margin-top: 2px; }
        .cu-row .pill { display:inline-block; font-size:10.5px; background: var(--surface-2,#eceff3); color: var(--text-dim,#6b7280); border-radius: 999px; padding: 1px 7px; margin-left: 4px; }
        .cu-detail { padding: 18px 20px; overflow-y: auto; }
        .cu-detail h3 { margin: 0 0 12px; font-size: 16px; color: var(--text,#222); }
        .cu-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .cu-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 4px; }
        .cu-field.full { grid-column: 1 / -1; }
        .cu-field label { font-size: 12px; font-weight: 600; color: var(--text-dim,#6b7280); }
        .cu-field input, .cu-field select, .cu-field textarea { padding: 8px 9px; border: 1px solid var(--border,#e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface,#fff); color: var(--text,#222); }
        .cu-actions { display: flex; gap: 8px; margin-top: 14px; }
        .cu-actions .spacer { flex: 1; }
        .cu-ci { margin-top: 22px; border-top: 1px solid var(--border,#eee); padding-top: 16px; }
        .cu-ci h4 { margin: 0 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-dim,#6b7280); }
        .cu-ci-search { position: relative; max-width: 380px; }
        .cu-ci-search input { width: 100%; box-sizing: border-box; padding: 8px 9px; border: 1px solid var(--border,#e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface,#fff); color: var(--text,#222); }
        .ci-search-results { display:none; position:absolute; top:100%; left:0; right:0; z-index:40; background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:6px; margin-top:2px; max-height:220px; overflow:auto; box-shadow:0 4px 14px var(--shadow,rgba(0,0,0,.12)); }
        .ci-search-results.active { display:block; }
        .ci-search-row { display:flex; justify-content:space-between; gap:12px; width:100%; text-align:left; padding:8px 10px; border:none; background:none; cursor:pointer; font-size:13px; color:var(--text,#222); border-bottom:1px solid var(--border-soft,#f0f0f0); }
        .ci-search-row:hover { background: var(--surface-2,#f3f4f6); }
        .ci-search-class { font-size:12px; color: var(--text-muted,#6b7280); }
        table.cu-ci-tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        table.cu-ci-tbl td { padding: 7px 8px; border-bottom: 1px solid var(--border,#eee); color: var(--text,#222); }
        .cu-empty { padding: 40px; text-align: center; color: var(--text-dim,#888); font-size: 14px; }
        .cu-err { color: #b91c1c; font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="main-container">
        <div class="cu-wrap">
            <div class="cu-panel">
                <div class="cu-list-head">
                    <input type="text" id="cuSearch" placeholder="Search customers&hellip;" oninput="cuSearchDebounced()">
                    <button class="btn btn-primary" onclick="cuNew()">New</button>
                </div>
                <div class="cu-list" id="cuList"><div class="cu-empty">Loading&hellip;</div></div>
            </div>
            <div class="cu-panel">
                <div class="cu-detail" id="cuDetail"><div class="cu-empty">Select a customer, or create a new one.</div></div>
            </div>
        </div>
    </div>
    <script src="customers.js?v=1"></script>
</body>
</html>
