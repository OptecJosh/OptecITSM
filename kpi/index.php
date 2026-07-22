<?php
/**
 * KPI scorecards (K0). Per-scorecard metric tables for a chosen month, with
 * target, value, RAG, trend and source; inline value entry, admin target edit.
 * Values are entered/imported now; K2 auto-fills the ticket-derived ones.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('kpi');

$current_page = 'scorecards';
$path_prefix = '../';
$translationNamespaces = ['common'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KPIs - Scorecards</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--kpi-accent, #4338ca); --accent-hover: var(--kpi-accent-hover, #3730a3); }
        .k-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .k-head { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .k-head h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .k-head .spacer { flex: 1; }
        .k-field { display: flex; flex-direction: column; gap: 3px; }
        .k-field label { font-size: 11px; font-weight: 600; color: var(--text-dim, #6b7280); text-transform: uppercase; letter-spacing: .04em; }
        .k-field input, .k-field select { padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #222); }
        .k-legend { display: flex; gap: 12px; font-size: 12px; color: var(--text-dim,#6b7280); flex-wrap: wrap; align-items: center; }
        .k-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
        .k-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 12px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); overflow: hidden; }
        .k-sc-head { padding: 12px 16px; background: var(--surface-2, #eceff3); border-bottom: 1px solid var(--border,#e5e7eb); font-weight: 700; font-size: 14px; color: var(--text,#222); }
        .k-sec { padding: 6px 16px 2px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--kpi-accent,#4338ca); }
        table.k { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.k th, table.k td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--border,#eee); vertical-align: top; color: var(--text,#222); }
        table.k th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim,#6b7280); font-weight: 700; }
        table.k td.num, table.k th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .k-metric b { font-weight: 600; }
        .k-metric small { display:block; color: var(--text-dim,#6b7280); font-size: 11.5px; margin-top: 2px; max-width: 52ch; }
        .k-rag { display:inline-block; width: 12px; height: 12px; border-radius: 50%; }
        .rag-green{background:#16a34a}.rag-amber{background:#d97706}.rag-red{background:#dc2626}.rag-na{background:#cbd5e1}.rag-info{background:#6366f1}
        .k-src { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing:.04em; padding: 2px 7px; border-radius: 999px; background: var(--surface-2,#eceff3); color: var(--text-dim,#6b7280); }
        .k-val-btn { border: 1px dashed var(--border,#cbd5e1); background: transparent; border-radius: 6px; padding: 4px 8px; font: inherit; font-size: 13px; cursor: pointer; color: var(--text,#222); min-width: 60px; text-align: right; }
        .k-val-btn:hover { border-color: var(--kpi-accent,#4338ca); color: var(--kpi-accent,#4338ca); }
        .k-val-btn.has { border-style: solid; font-weight: 600; }
        .k-note { color: var(--text-dim,#6b7280); font-size: 11px; margin-top:2px; }
        .k-modal-back { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:600; align-items:center; justify-content:center; }
        .k-modal-back.active { display:flex; }
        .k-modal { background: var(--surface,#fff); border-radius:10px; padding:20px; width:460px; max-width:92vw; box-shadow:0 10px 40px rgba(0,0,0,.25); }
        .k-modal h3 { margin:0 0 4px; font-size:15px; color:var(--text,#222); }
        .k-modal .sub { font-size:12px; color:var(--text-dim,#6b7280); margin-bottom:12px; }
        .k-modal .fld { margin-bottom:10px; display:flex; flex-direction:column; gap:4px; }
        .k-modal label { font-size:12px; font-weight:600; color:var(--text-dim,#6b7280); }
        .k-modal input, .k-modal select, .k-modal textarea { padding:8px 9px; border:1px solid var(--border,#e5e7eb); border-radius:6px; font-size:13px; background:var(--surface,#fff); color:var(--text,#222); }
        .k-modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:14px; }
        .k-note-line { padding: 24px; text-align:center; color: var(--text-dim,#888); }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="main-container">
        <div class="k-wrap">
            <div class="k-head">
                <h2>KPI scorecards</h2>
                <div class="k-field"><label>Period</label><input type="month" id="kPeriod"></div>
                <button class="btn btn-secondary" onclick="kImport()">Import CSV</button>
                <div class="spacer"></div>
                <div class="k-legend">
                    <span><span class="k-dot" style="background:#16a34a"></span>On target</span>
                    <span><span class="k-dot" style="background:#d97706"></span>Watch</span>
                    <span><span class="k-dot" style="background:#dc2626"></span>Off target</span>
                    <span><span class="k-dot" style="background:#6366f1"></span>Info/manual</span>
                    <span><span class="k-dot" style="background:#cbd5e1"></span>No data</span>
                </div>
            </div>
            <div id="kBody"><div class="k-note-line">Loading&hellip;</div></div>
        </div>
    </div>

    <!-- value entry modal -->
    <div class="k-modal-back" id="kValBack">
        <div class="k-modal">
            <h3 id="kValTitle">Record value</h3>
            <div class="sub" id="kValTarget"></div>
            <input type="hidden" id="kValId">
            <div class="fld"><label>Value <span id="kValUnit" style="text-transform:none;color:var(--text-dim,#6b7280)"></span></label><input type="number" step="any" id="kValValue"></div>
            <div class="fld"><label>Status</label>
                <select id="kValStatus">
                    <option value="">Auto (from target)</option>
                    <option value="green">On target</option>
                    <option value="amber">Watch</option>
                    <option value="red">Off target</option>
                    <option value="info">Info</option>
                    <option value="na">No data</option>
                </select>
            </div>
            <div class="fld"><label>Note (optional)</label><textarea id="kValNote" rows="2" maxlength="500"></textarea></div>
            <div class="k-modal-actions">
                <button class="btn btn-secondary" onclick="kCloseVal()">Cancel</button>
                <button class="btn btn-primary" onclick="kSaveVal()">Save</button>
            </div>
        </div>
    </div>

    <!-- csv import modal -->
    <div class="k-modal-back" id="kImpBack">
        <div class="k-modal">
            <h3>Import values (CSV)</h3>
            <div class="sub">Columns: <code>id,value</code> (optional <code>status,note</code>). One row per KPI; applies to the selected period. Export a scorecard first to get the id column.</div>
            <div class="fld"><textarea id="kImpCsv" rows="8" placeholder="id,value,status,note&#10;12,96.4,,&#10;15,3.1,,"></textarea></div>
            <div class="k-modal-actions">
                <button class="btn btn-secondary" onclick="kCloseImp()">Cancel</button>
                <button class="btn btn-secondary" onclick="kExportCsv()">Export template</button>
                <button class="btn btn-primary" onclick="kRunImport()">Import</button>
            </div>
            <div id="kImpResult" style="font-size:13px;margin-top:10px;"></div>
        </div>
    </div>

    <script src="kpi.js?v=1"></script>
</body>
</html>
