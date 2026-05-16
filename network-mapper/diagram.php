<?php
/**
 * Network Mapper — Diagram editor (chunk B).
 *
 * Renders the editor shell and hands off to assets/js/network-mapper.js for
 * data loading, autosave, save-as-new-version, and (in later chunks) the
 * drag/bind/connector logic. The PHP side here is intentionally thin — most
 * of the live behaviour lives client-side.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$diagramId = (int)($_GET['id'] ?? 0);
if ($diagramId <= 0) {
    header('Location: index.php');
    exit;
}

$current_page = 'diagrams';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM &mdash; Network Diagram</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <style>
        body { background: #f5f5f5; height: 100vh; overflow: hidden; }

        .nm-editor {
            height: calc(100vh - 60px);
            display: flex;
            flex-direction: column;
            background: #f5f5f5;
        }

        /* ---- Top bar ---- */
        .nm-editor-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            gap: 16px;
        }
        .nm-editor-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }
        .nm-back-btn {
            background: transparent;
            border: 1px solid #e5e7eb;
            color: #6b7280;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            flex-shrink: 0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .nm-back-btn:hover { background: #f9fafb; color: #111827; }
        .nm-editor-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .nm-version-pill {
            display: inline-block;
            background: #ecfeff;
            color: #0e7490;
            border: 1px solid #a5f3fc;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .nm-version-pill.readonly { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }

        .nm-editor-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-shrink: 0;
        }

        /* ---- Autosave toggle ---- */
        .nm-autosave-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 8px;
            border-right: 1px solid #e5e7eb;
            border-left: 1px solid #e5e7eb;
            height: 32px;
        }
        .nm-autosave-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 12px;
            color: #4b5563;
            user-select: none;
        }
        .nm-autosave-toggle input { display: none; }
        .nm-autosave-switch {
            position: relative;
            display: inline-block;
            width: 26px;
            height: 14px;
            background: #d1d5db;
            border-radius: 999px;
            transition: background 0.15s;
        }
        .nm-autosave-switch::after {
            content: '';
            position: absolute;
            top: 1px;
            left: 1px;
            width: 12px;
            height: 12px;
            background: white;
            border-radius: 50%;
            transition: left 0.15s;
        }
        .nm-autosave-toggle input:checked + .nm-autosave-switch { background: #06b6d4; }
        .nm-autosave-toggle input:checked + .nm-autosave-switch::after { left: 13px; }

        /* ---- Status indicator ---- */
        .nm-status {
            font-size: 12px;
            color: #6b7280;
            min-width: 120px;
            text-align: right;
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .nm-status-unsaved { color: #b45309; }
        .nm-status-saving  { color: #0e7490; }
        .nm-status-saved   { color: #166534; }
        .nm-status-failed  { color: #b91c1c; }
        .nm-status-off     { color: #9ca3af; font-style: italic; }
        .nm-status-tick    { color: #16a34a; font-weight: 600; }
        .nm-status-warn    { color: #dc2626; font-weight: 600; }
        .nm-status-failed a { color: #b91c1c; text-decoration: underline; cursor: pointer; }
        .nm-status-spinner {
            width: 10px;
            height: 10px;
            border: 2px solid #a5f3fc;
            border-top-color: #06b6d4;
            border-radius: 50%;
            display: inline-block;
            animation: nm-spin 0.7s linear infinite;
        }
        @keyframes nm-spin { to { transform: rotate(360deg); } }

        /* ---- Buttons ---- */
        .nm-btn {
            padding: 7px 14px;
            background: #06b6d4;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
        }
        .nm-btn:hover { background: #0891b2; }
        .nm-btn.secondary { background: white; color: #374151; border: 1px solid #d1d5db; }
        .nm-btn.secondary:hover { background: #f9fafb; }
        .nm-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ---- Meta row ---- */
        .nm-meta-row {
            font-size: 12px;
            color: #6b7280;
            padding: 8px 20px;
            background: #fafbfc;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 18px;
        }
        .nm-meta-row strong { color: #374151; font-weight: 500; }

        /* ---- Read-only banner ---- */
        .nm-readonly-banner {
            padding: 10px 20px;
            background: #fff7ed;
            border-bottom: 1px solid #fed7aa;
            color: #9a3412;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .nm-readonly-banner strong { color: #7c2d12; }
        .nm-readonly-banner a { color: #c2410c; text-decoration: underline; }

        /* ---- Main canvas area ---- */
        .nm-canvas-wrap {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        /* ---- Palette ---- */
        .nm-palette {
            width: 240px;
            background: white;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .nm-palette-header {
            padding: 11px 14px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .nm-palette-hint {
            font-size: 11px;
            color: #9ca3af;
            font-weight: 400;
        }
        .nm-palette-body {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            align-content: start;
        }
        .nm-palette-empty {
            grid-column: 1 / -1;
            color: #9ca3af;
            font-size: 13px;
            line-height: 1.5;
            padding: 14px 8px;
        }
        .nm-palette-empty a { color: #06b6d4; }
        .nm-palette-tile {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px 6px 8px 6px;
            cursor: grab;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            transition: border-color 0.12s, box-shadow 0.12s, background 0.12s;
            user-select: none;
            background: white;
        }
        .nm-palette-tile:hover {
            border-color: #06b6d4;
            background: #ecfeff;
            box-shadow: 0 2px 6px rgba(6,182,212,0.10);
        }
        .nm-palette-tile:active { cursor: grabbing; }
        .nm-palette-tile-icon {
            color: #0e7490;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 32px;
        }
        .nm-palette-tile-name {
            font-size: 11px;
            font-weight: 600;
            color: #111827;
            text-align: center;
            line-height: 1.2;
            word-break: break-word;
        }
        .nm-palette-tile-count {
            font-size: 10px;
            color: #9ca3af;
        }

        /* ---- Canvas ---- */
        .nm-canvas {
            flex: 1;
            position: relative;
            background:
                radial-gradient(circle, #d1d5db 1px, transparent 1px) 0 0 / 20px 20px,
                #fafbfc;
            overflow: auto;
        }
        .nm-canvas-empty {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #9ca3af;
            font-size: 14px;
            max-width: 380px;
            line-height: 1.5;
        }
        .nm-canvas-empty h3 { color: #6b7280; font-weight: 600; margin: 0 0 6px 0; }

        /* ---- Modal (Save as new version) ---- */
        .nm-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .nm-modal-overlay.active { display: flex; }
        .nm-modal {
            background: white;
            border-radius: 8px;
            width: 480px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .nm-modal-header {
            padding: 16px 22px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: 16px;
            color: #111827;
        }
        .nm-modal-body { padding: 22px; flex: 1; overflow-y: auto; }
        .nm-modal-actions {
            padding: 14px 22px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .nm-form-group { margin-bottom: 14px; }
        .nm-form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        .nm-form-group input, .nm-form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
        }
        .nm-form-group textarea { resize: vertical; min-height: 70px; }
        .nm-form-group input:focus, .nm-form-group textarea:focus {
            outline: none;
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6,182,212,0.12);
        }
        .nm-form-group small { color: #6b7280; font-size: 12px; display: block; margin-top: 4px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="nm-editor">
        <div class="nm-editor-bar">
            <div class="nm-editor-title-area">
                <a class="nm-back-btn" href="index.php">&larr; All diagrams</a>
                <h1 class="nm-editor-title" id="diagramTitle">Loading&hellip;</h1>
                <span class="nm-version-pill" id="versionPill" style="display:none;"></span>
            </div>
            <div class="nm-editor-actions">
                <div class="nm-autosave-wrap" id="autosaveWrap">
                    <label class="nm-autosave-toggle" title="Auto-save changes ~2s after the last edit">
                        <input type="checkbox" id="nmAutosaveToggle" onchange="NM.toggleAutosave(this.checked)">
                        <span class="nm-autosave-switch"></span>
                        <span>Autosave</span>
                    </label>
                </div>
                <span class="nm-status" id="saveStatus"></span>
                <button class="nm-btn secondary" id="saveVersionBtn" onclick="NM.openNewVersionModal()" title="Clone the current version forward into a new editable version">Save as new version</button>
                <button class="nm-btn" id="saveBtn" onclick="NM.save()" title="Save (Ctrl+S)">Save</button>
            </div>
        </div>

        <div class="nm-meta-row" id="metaRow" style="display:none;">
            <span><strong>Author:</strong> <span id="metaAuthor">&mdash;</span></span>
            <span><strong>Created:</strong> <span id="metaCreated">&mdash;</span></span>
            <span><strong>Updated:</strong> <span id="metaUpdated">&mdash;</span></span>
        </div>

        <div class="nm-readonly-banner" id="readonlyBanner" style="display:none;">
            <span><strong>Read-only version.</strong> This is a historical version of the diagram. To make changes, fork it into a new version from the current (leaf) version.</span>
            <a href="index.php">&larr; Back to diagrams</a>
        </div>

        <div class="nm-canvas-wrap">
            <aside class="nm-palette">
                <div class="nm-palette-header">
                    <span>CMDB classes</span>
                    <span class="nm-palette-hint">drag to canvas</span>
                </div>
                <div class="nm-palette-body" id="paletteBody">
                    <div class="nm-palette-empty">Loading classes&hellip;</div>
                </div>
            </aside>
            <div class="nm-canvas" id="canvas">
                <div class="nm-canvas-empty">
                    <h3>Empty diagram</h3>
                    <p>Drag a class from the palette onto the canvas to start placing nodes. Drop-handling lands in the next chunk &mdash; for now, the palette + autosave + versioning are wired up so you can save metadata changes and fork versions.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Save as new version modal -->
    <div class="nm-modal-overlay" id="newVersionModal">
        <div class="nm-modal">
            <div class="nm-modal-header">Save as new version</div>
            <div class="nm-modal-body">
                <p style="font-size:13px;color:#6b7280;margin:0 0 16px 0;line-height:1.5;">
                    Clones the current diagram (nodes, connectors, metadata) forward into a new editable version. The current version becomes a read-only historical record.
                </p>
                <div class="nm-form-group">
                    <label for="nvTitle">Title *</label>
                    <input type="text" id="nvTitle" maxlength="255">
                </div>
                <div class="nm-form-group">
                    <label for="nvDescription">Description</label>
                    <textarea id="nvDescription" maxlength="2000"></textarea>
                </div>
                <div class="nm-form-group">
                    <label for="nvVersionLabel">Version label</label>
                    <input type="text" id="nvVersionLabel" maxlength="50" placeholder="v2">
                    <small>Free text &mdash; e.g. &ldquo;v2&rdquo;, &ldquo;Q2 baseline&rdquo;, &ldquo;Post-migration&rdquo;.</small>
                </div>
            </div>
            <div class="nm-modal-actions">
                <button class="nm-btn secondary" onclick="NM.closeNewVersionModal()">Cancel</button>
                <button class="nm-btn" id="nvCreateBtn" onclick="NM.createNewVersion()">Create version</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/network-mapper-icons.js"></script>
    <script src="../assets/js/network-mapper.js"></script>
    <script>
        NM.init(<?php echo $diagramId; ?>);

        // Keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                NM.save();
            }
            if (e.key === 'Escape') {
                NM.closeNewVersionModal();
            }
        });

        // Click-out to close modal
        document.getElementById('newVersionModal').addEventListener('click', function (e) {
            if (e.target === e.currentTarget) NM.closeNewVersionModal();
        });

        // Warn on unload if there are unsaved changes — guard against the user
        // hitting back/refresh after editing without saving
        window.addEventListener('beforeunload', function (e) {
            // The flag lives in the JS module; we ask it via a quick lookup.
            // No public getter — we rely on the autosave status DOM as a proxy.
            const status = document.getElementById('saveStatus');
            if (status && status.classList.contains('nm-status-unsaved')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>
