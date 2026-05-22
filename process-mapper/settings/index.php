<?php
/**
 * Process Mapper — Settings
 *
 * Manages the `process_step_types` palette (name + shape + colour). The
 * editor pulls this same list at page-load time so the toolbar, the detail-
 * panel Type dropdown and the right-click "Create new" submenu all reflect
 * whatever the user has configured here. Built-in types are protected from
 * deletion; deactivating a type hides it from the toolbar/context menu but
 * keeps it in the detail-panel dropdown (marked with a `*`) so existing
 * steps with that stored type are still selectable.
 *
 * Visually aligned with the tickets settings page — same shared inbox.css
 * primitives (.tab-content card, .section-header, .add-btn, .table-action-btn,
 * .status-badge, .modal) so the two settings pages feel like one product.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'settings';
$path_prefix  = '../../';

$translationNamespaces = ['common', 'process-mapper'];
$shapes = include '../includes/shapes.php';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('process-mapper.title') . ' — ' . t('process-mapper.nav.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/process-mapper.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        /* Settings pages scroll the page rather than the body. */
        body { overflow: auto; height: auto; background: #f5f5f5; }

        /* Match tickets/settings: the shared .container caps at 1200px, which
           is the right feel for a single-table settings page. */

        /* Shape picker grid — module-specific, no shared equivalent. */
        .pms-shape-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }
        .pms-shape-opt {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 10px 4px 7px;
            background: #fafafa;
            border: 2px solid #eee;
            border-radius: 8px;
            cursor: pointer;
        }
        .pms-shape-opt:hover { border-color: #c7d2fe; }
        .pms-shape-opt.selected {
            border-color: #0078d4;
            background: #eaf4fc;
        }
        .pms-shape-name {
            font-size: 11px;
            color: #666;
            text-align: center;
        }
        .pms-shape-opt.selected .pms-shape-name { color: #005a9e; font-weight: 600; }

        /* Built-in marker pill — sits next to the row name. */
        .pms-builtin {
            display: inline-block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #eef2ff;
            color: #4338ca;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 6px;
            vertical-align: 1px;
        }

        /* Per-row reorder buttons sit alongside the order number. */
        .pms-order-cell {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .pms-order-num {
            min-width: 18px;
            color: #555;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tab-content active">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('process-mapper.settings.title')); ?></h2>
                <button class="add-btn" onclick="PMS.openAdd()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: #666;"><?php echo htmlspecialchars(t('process-mapper.settings.intro')); ?></p>

            <table class="settings-table">
                <thead>
                    <tr>
                        <th style="width: 110px;"><?php echo htmlspecialchars(t('process-mapper.settings.col_order')); ?></th>
                        <th style="width: 90px;"><?php echo htmlspecialchars(t('process-mapper.settings.col_shape')); ?></th>
                        <th><?php echo htmlspecialchars(t('process-mapper.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('process-mapper.settings.col_colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('process-mapper.settings.col_active')); ?></th>
                        <th style="width: 110px;"><?php echo htmlspecialchars(t('process-mapper.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="pmsRows">
                    <tr><td colspan="6" style="text-align: center;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add / Edit modal — mirrors the tickets edit modal structure -->
    <div class="modal" id="pmsModal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header" id="pmsModalTitle"></div>
            <form id="pmsForm" autocomplete="off" onsubmit="event.preventDefault(); PMS.save();">
                <div style="padding: 24px;">
                    <div class="form-group">
                        <label for="pmsName"><?php echo htmlspecialchars(t('process-mapper.settings.field_name')); ?></label>
                        <input type="text" id="pmsName" maxlength="100" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('process-mapper.settings.name_placeholder')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('process-mapper.settings.field_shape')); ?></label>
                        <div class="pms-shape-grid" id="pmsShapeGrid">
                            <?php foreach ($shapes as $key => $dim): ?>
                            <button type="button" class="pms-shape-opt" data-shape-key="<?php echo htmlspecialchars($key); ?>" onclick="PMS.pickShape('<?php echo htmlspecialchars($key); ?>')">
                                <span class="pm-shape-preview" data-shape="<?php echo htmlspecialchars($key); ?>"></span>
                                <span class="pms-shape-name"><?php echo htmlspecialchars(t('process-mapper.shapes.' . $key)); ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="pmsColor"><?php echo htmlspecialchars(t('process-mapper.settings.field_colour')); ?></label>
                        <input type="color" id="pmsColor" value="#0078d4" autocomplete="off" style="width: 60px; height: 32px; padding: 2px;">
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" id="pmsActive" checked autocomplete="off"> <?php echo htmlspecialchars(t('process-mapper.settings.field_active')); ?></label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="PMS.closeModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Cloud shape clip-path (objectBoundingBox so it scales with the element) -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <defs>
            <clipPath id="pmShapeCloud" clipPathUnits="objectBoundingBox">
                <ellipse cx="0.30" cy="0.62" rx="0.27" ry="0.33"/>
                <ellipse cx="0.50" cy="0.42" rx="0.30" ry="0.40"/>
                <ellipse cx="0.70" cy="0.60" rx="0.28" ry="0.34"/>
                <ellipse cx="0.50" cy="0.73" rx="0.40" ry="0.26"/>
            </clipPath>
        </defs>
    </svg>

    <div class="toast" id="toast"></div>

    <script>
    const PMS = (() => {
        const API = '../../api/process-mapper/';
        const SHAPE_KEYS = <?php echo json_encode(array_keys($shapes)); ?>;
        let types = [];
        let editingId = null;
        let pickedShape = 'rounded';

        function esc(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        function toast(msg, type = 'success') {
            const el = document.getElementById('toast');
            el.textContent = msg;
            el.className = 'toast show ' + type;
            setTimeout(() => { el.className = 'toast'; }, 2500);
        }

        async function loadTypes() {
            try {
                const r = await fetch(API + 'get_step_types.php', { credentials: 'same-origin' });
                const d = await r.json();
                if (d.success) { types = d.types || []; render(); }
                else toast(d.error || 'Failed to load', 'error');
            } catch (e) { toast('Failed to load', 'error'); }
        }

        // Inline SVGs match tickets/settings table action buttons — same pencil
        // and trash icons so the two pages feel like the same product.
        const ICON_EDIT   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
        const ICON_UP     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>';
        const ICON_DOWN   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';

        function render() {
            const tb = document.getElementById('pmsRows');
            if (!types.length) {
                tb.innerHTML = '<tr><td colspan="6" style="text-align: center;">' + esc(window.t('process-mapper.settings.none')) + '</td></tr>';
                return;
            }
            tb.innerHTML = types.map((row, i) => {
                const builtin = row.is_builtin ? ' <span class="pms-builtin">' + esc(window.t('process-mapper.settings.builtin')) + '</span>' : '';
                const activeCell = row.is_active
                    ? '<span class="status-badge status-active">' + esc(window.t('common.yes')) + '</span>'
                    : '<span class="status-badge status-inactive">' + esc(window.t('common.no')) + '</span>';
                const upDisabled   = i === 0 ? 'disabled' : '';
                const downDisabled = i === types.length - 1 ? 'disabled' : '';
                const deleteDisabled = row.is_builtin ? 'disabled' : '';
                return `<tr>
                    <td>
                        <span class="pms-order-cell">
                            <button class="table-action-btn" ${upDisabled} onclick="PMS.move(${row.id},-1)" title="Up">${ICON_UP}</button>
                            <button class="table-action-btn" ${downDisabled} onclick="PMS.move(${row.id},1)" title="Down">${ICON_DOWN}</button>
                            <span class="pms-order-num">${row.display_order}</span>
                        </span>
                    </td>
                    <td><span class="pm-shape-preview" data-shape="${esc(row.shape)}" style="background:${esc(row.color)}"></span></td>
                    <td><strong>${esc(row.name)}</strong>${builtin}</td>
                    <td>
                        <span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:${esc(row.color)}; vertical-align:middle; border:1px solid #ddd; margin-right:6px;"></span>
                        <code style="font-size:12px;">${esc(row.color)}</code>
                    </td>
                    <td>${activeCell}</td>
                    <td>
                        <button class="table-action-btn" onclick="PMS.openEdit(${row.id})" title="${esc(window.t('common.edit'))}">${ICON_EDIT}</button>
                        <button class="table-action-btn delete" ${deleteDisabled} onclick="PMS.del(${row.id})" title="${esc(window.t('common.delete'))}">${ICON_DELETE}</button>
                    </td>
                </tr>`;
            }).join('');
        }

        function pickShape(key) {
            pickedShape = key;
            document.querySelectorAll('.pms-shape-opt').forEach(b => {
                b.classList.toggle('selected', b.dataset.shapeKey === key);
            });
        }

        function showModal()  { document.getElementById('pmsModal').classList.add('active'); }
        function closeModal() { document.getElementById('pmsModal').classList.remove('active'); }

        function openAdd() {
            editingId = null;
            document.getElementById('pmsModalTitle').textContent = window.t('process-mapper.settings.add_title');
            document.getElementById('pmsName').value = '';
            document.getElementById('pmsColor').value = '#0078d4';
            document.getElementById('pmsActive').checked = true;
            pickShape('rounded');
            showModal();
            document.getElementById('pmsName').focus();
        }

        function openEdit(id) {
            const row = types.find(x => x.id === id);
            if (!row) return;
            editingId = id;
            document.getElementById('pmsModalTitle').textContent = window.t('process-mapper.settings.edit_title');
            document.getElementById('pmsName').value = row.name || '';
            document.getElementById('pmsColor').value = /^#[0-9a-fA-F]{6}$/.test(row.color) ? row.color : '#0078d4';
            document.getElementById('pmsActive').checked = !!row.is_active;
            pickShape(SHAPE_KEYS.includes(row.shape) ? row.shape : 'rounded');
            showModal();
            document.getElementById('pmsName').focus();
        }

        async function save() {
            const name = document.getElementById('pmsName').value.trim();
            if (!name) { toast(window.t('process-mapper.settings.name_required'), 'error'); return; }
            const payload = {
                id: editingId,
                name: name,
                shape: pickedShape,
                color: document.getElementById('pmsColor').value,
                is_active: document.getElementById('pmsActive').checked ? 1 : 0
            };
            try {
                const r = await fetch(API + 'save_step_type.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const d = await r.json();
                if (d.success) { closeModal(); toast(window.t('process-mapper.settings.saved')); loadTypes(); }
                else toast(d.error || 'Save failed', 'error');
            } catch (e) { toast('Save failed', 'error'); }
        }

        async function del(id) {
            if (!confirm(window.t('process-mapper.settings.delete_confirm'))) return;
            try {
                const r = await fetch(API + 'delete_step_type.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const d = await r.json();
                if (d.success) { toast(window.t('process-mapper.settings.deleted')); loadTypes(); }
                else toast(d.error || 'Delete failed', 'error');
            } catch (e) { toast('Delete failed', 'error'); }
        }

        async function move(id, dir) {
            const i = types.findIndex(row => row.id === id);
            const j = i + dir;
            if (i < 0 || j < 0 || j >= types.length) return;
            [types[i], types[j]] = [types[j], types[i]];
            render();
            try {
                await fetch(API + 'reorder_step_types.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order: types.map(row => row.id) })
                });
            } catch (e) { toast('Reorder failed', 'error'); }
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });
        // Backdrop click on the modal overlay closes it (matches the modal layer behaviour).
        document.getElementById('pmsModal').addEventListener('click', e => {
            if (e.target.id === 'pmsModal') closeModal();
        });
        document.addEventListener('DOMContentLoaded', loadTypes);

        return { openAdd, openEdit, pickShape, save, del, move, closeModal };
    })();
    </script>
</body>
</html>
