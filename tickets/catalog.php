<?php
/**
 * Service Catalog — admin management (Phase 7c).
 *
 * Analysts with the categories capability define the request items that appear
 * in the self-service portal's "Request something" page. Each item carries
 * default routing (category / department / priority) that create_ticket.php
 * applies when a requester raises it. Standalone page (not a settings tab) so it
 * can grow its own layout as forms (7c-2) and approvals (7d) land.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/rbac.php';
I18n::initFromSession();
requireModuleAccess('tickets');
requireCapability(Cap::TICKETS_CATEGORIES);

$current_page = '';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Service Catalog</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .sc-wrap { flex: 1; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .sc-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 6px; }
        .sc-head h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .sc-lede { color: var(--text-dim, #6b7280); font-size: 13.5px; margin: 0 0 16px; max-width: 720px; }
        .sc-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); overflow: hidden; }
        table.sc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .sc-table th, .sc-table td { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); vertical-align: middle; }
        .sc-table th { color: var(--text-dim, #6b7280); font-weight: 600; background: var(--surface-alt, #f9fafb); }
        .sc-table tr:last-child td { border-bottom: none; }
        .sc-item-name { font-weight: 600; }
        .sc-item-icon { margin-right: 7px; }
        .sc-item-desc { color: var(--text-dim, #6b7280); font-size: 12px; margin-top: 2px; }
        .sc-item-form { color: #0f4c81; font-size: 11.5px; font-weight: 600; margin-top: 4px; }
        .sc-item-appr { color: #92400e; font-size: 11.5px; font-weight: 600; margin-top: 4px; }
        .sc-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
        .sc-pill.on  { background: #dcfce7; color: #166534; }
        .sc-pill.off { background: #f3f4f6; color: #6b7280; }
        .sc-muted { color: var(--text-dim, #9ca3af); }
        .sc-actions { display: flex; gap: 8px; }
        .sc-note { padding: 40px; text-align: center; color: var(--text-dim, #888); }

        /* Editor modal */
        .sc-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .sc-modal-backdrop.open { display: flex; }
        .sc-modal { background: var(--surface, #fff); border-radius: 10px; width: 520px; max-width: calc(100vw - 40px); max-height: calc(100vh - 60px); overflow: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.25); }
        .sc-modal-head { padding: 16px 20px; border-bottom: 1px solid var(--border, #eee); font-size: 16px; font-weight: 600; color: var(--text, #222); }
        .sc-modal-body { padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; }
        .sc-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); margin-bottom: 5px; }
        .sc-field input[type=text], .sc-field textarea, .sc-field select {
            width: 100%; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px;
            font-size: 13px; font-family: inherit; background: var(--surface, #fff); color: var(--text, #222); box-sizing: border-box;
        }
        .sc-field textarea { min-height: 64px; resize: vertical; }
        .sc-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .sc-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text, #222); }
        .sc-modal-foot { padding: 14px 20px; border-top: 1px solid var(--border, #eee); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .sc-modal-err { color: #b91c1c; font-size: 12.5px; min-height: 16px; }
        .sc-icon-input { width: 70px !important; text-align: center; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <div class="sc-wrap">
            <div class="sc-head">
                <h2>Service Catalog</h2>
                <button class="btn btn-primary" onclick="scOpenEditor()">+ New request item</button>
            </div>
            <p class="sc-lede">These items appear on the portal's <strong>Request something</strong> page. Each one can pre-set the category, department and priority of the ticket it raises, so requests land with the right team automatically.</p>
            <div class="sc-card">
                <table class="sc-table">
                    <thead>
                        <tr>
                            <th style="width:34%;">Item</th>
                            <th>Category</th>
                            <th>Routes to</th>
                            <th>Priority</th>
                            <th style="width:80px;">Status</th>
                            <th style="width:140px;"></th>
                        </tr>
                    </thead>
                    <tbody id="scList">
                        <tr><td colspan="6" class="sc-note">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Editor modal -->
    <div class="sc-modal-backdrop" id="scModal">
        <div class="sc-modal">
            <div class="sc-modal-head" id="scModalTitle">New request item</div>
            <div class="sc-modal-body">
                <input type="hidden" id="scId">
                <div class="sc-field">
                    <label for="scName">Name *</label>
                    <input type="text" id="scName" maxlength="150" placeholder="e.g. Request a new laptop">
                </div>
                <div class="sc-field">
                    <label for="scDesc">Description</label>
                    <textarea id="scDesc" maxlength="1000" placeholder="Shown under the item on the portal"></textarea>
                </div>
                <div class="sc-row2">
                    <div class="sc-field">
                        <label for="scCategory">Category</label>
                        <select id="scCategory"><option value="">— None —</option></select>
                    </div>
                    <div class="sc-field">
                        <label for="scDepartment">Route to department</label>
                        <select id="scDepartment"><option value="">— None —</option></select>
                    </div>
                </div>
                <div class="sc-row2">
                    <div class="sc-field">
                        <label for="scPriority">Priority</label>
                        <select id="scPriority"><option value="">— Requester chooses —</option></select>
                    </div>
                    <div class="sc-field">
                        <label for="scOrder">Display order</label>
                        <input type="text" id="scOrder" inputmode="numeric" placeholder="0">
                    </div>
                </div>
                <div class="sc-field">
                    <label for="scForm">Attach a form <span class="sc-muted" style="font-weight:400;">— the requester fills it in when raising this request</span></label>
                    <select id="scForm"><option value="">— No form —</option></select>
                </div>
                <div class="sc-field" style="border-top:1px solid var(--border,#eee);padding-top:14px;">
                    <label class="sc-check"><input type="checkbox" id="scRequiresApproval" onchange="scToggleApprover()"> Require approval before this request is worked</label>
                    <div id="scApproverWrap" style="margin-top:10px;display:none;">
                        <label for="scApprover">Approver</label>
                        <select id="scApprover"><option value="">— Select approver —</option></select>
                    </div>
                </div>
                <div class="sc-row2">
                    <div class="sc-field">
                        <label for="scIcon">Icon (emoji)</label>
                        <input type="text" id="scIcon" class="sc-icon-input" maxlength="8" placeholder="🧾">
                    </div>
                    <div class="sc-field" style="display:flex;align-items:flex-end;">
                        <label class="sc-check"><input type="checkbox" id="scActive" checked> Active (visible on the portal)</label>
                    </div>
                </div>
            </div>
            <div class="sc-modal-foot">
                <span class="sc-modal-err" id="scErr"></span>
                <div class="sc-actions">
                    <button class="btn btn-secondary" onclick="scCloseEditor()">Cancel</button>
                    <button class="btn btn-primary" id="scSaveBtn" onclick="scSave()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const SC_API = '../api/tickets/';
        let scLookups = { categories: [], departments: [], priorities: [], forms: [], analysts: [] };
        let scItems = [];

        function scEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }

        async function scLoad() {
            let data;
            try { data = await (await fetch(SC_API + 'get_catalog_items.php')).json(); }
            catch (e) { document.getElementById('scList').innerHTML = '<tr><td colspan="6" class="sc-note">Could not load the catalog.</td></tr>'; return; }
            if (!data.success) { document.getElementById('scList').innerHTML = '<tr><td colspan="6" class="sc-note">' + scEsc(data.error || 'Could not load the catalog.') + '</td></tr>'; return; }
            scItems = data.items || [];
            scLookups.categories = data.categories || [];
            scLookups.departments = data.departments || [];
            scLookups.priorities = data.priorities || [];
            // Forms expose `title`; normalise to {id,name} so scFillSelect works.
            scLookups.forms = (data.forms || []).map(f => ({ id: f.id, name: f.title }));
            scLookups.analysts = data.analysts || [];
            scFillSelect('scCategory', scLookups.categories, '— None —');
            scFillSelect('scDepartment', scLookups.departments, '— None —');
            scFillSelect('scPriority', scLookups.priorities, '— Requester chooses —');
            scFillSelect('scForm', scLookups.forms, '— No form —');
            scFillSelect('scApprover', scLookups.analysts, '— Select approver —');
            scRender();
        }

        function scFillSelect(id, rows, noneLabel) {
            const el = document.getElementById(id);
            el.innerHTML = '<option value="">' + scEsc(noneLabel) + '</option>' +
                rows.map(r => '<option value="' + r.id + '">' + scEsc(r.name) + '</option>').join('');
        }

        function scRender() {
            const tb = document.getElementById('scList');
            if (!scItems.length) {
                tb.innerHTML = '<tr><td colspan="6" class="sc-note">No request items yet. Click <strong>New request item</strong> to add your first.</td></tr>';
                return;
            }
            tb.innerHTML = scItems.map(it => {
                const icon = it.icon ? '<span class="sc-item-icon">' + scEsc(it.icon) + '</span>' : '';
                const form = it.form_title ? '<div class="sc-item-form">📋 ' + scEsc(it.form_title) + '</div>' : '';
                const appr = it.requires_approval ? '<div class="sc-item-appr">🔒 Approval: ' + scEsc(it.approver_name || 'unassigned') + '</div>' : '';
                const desc = (it.description ? '<div class="sc-item-desc">' + scEsc(it.description) + '</div>' : '') + form + appr;
                const cat = it.category_name ? scEsc(it.category_name) : '<span class="sc-muted">—</span>';
                const dep = it.department_name ? scEsc(it.department_name) : '<span class="sc-muted">Unrouted</span>';
                const pri = it.priority_name ? scEsc(it.priority_name) : '<span class="sc-muted">Requester picks</span>';
                const status = it.is_active ? '<span class="sc-pill on">Active</span>' : '<span class="sc-pill off">Hidden</span>';
                return '<tr>' +
                    '<td><div class="sc-item-name">' + icon + scEsc(it.name) + '</div>' + desc + '</td>' +
                    '<td>' + cat + '</td>' +
                    '<td>' + dep + '</td>' +
                    '<td>' + pri + '</td>' +
                    '<td>' + status + '</td>' +
                    '<td><div class="sc-actions">' +
                        '<button class="action-btn" onclick="scOpenEditor(' + it.id + ')">Edit</button>' +
                        '<button class="action-btn action-btn-danger" onclick="scDelete(' + it.id + ')">Delete</button>' +
                    '</div></td>' +
                '</tr>';
            }).join('');
        }

        function scOpenEditor(id) {
            const it = id ? scItems.find(x => x.id === id) : null;
            document.getElementById('scModalTitle').textContent = it ? 'Edit request item' : 'New request item';
            document.getElementById('scId').value = it ? it.id : '';
            document.getElementById('scName').value = it ? it.name : '';
            document.getElementById('scDesc').value = it && it.description ? it.description : '';
            document.getElementById('scCategory').value = it && it.category_id ? it.category_id : '';
            document.getElementById('scDepartment').value = it && it.department_id ? it.department_id : '';
            document.getElementById('scPriority').value = it && it.priority_id ? it.priority_id : '';
            document.getElementById('scForm').value = it && it.form_id ? it.form_id : '';
            document.getElementById('scOrder').value = it ? (it.display_order || 0) : 0;
            document.getElementById('scIcon').value = it && it.icon ? it.icon : '';
            document.getElementById('scActive').checked = it ? !!it.is_active : true;
            document.getElementById('scRequiresApproval').checked = it ? !!it.requires_approval : false;
            document.getElementById('scApprover').value = it && it.approver_analyst_id ? it.approver_analyst_id : '';
            scToggleApprover();
            document.getElementById('scErr').textContent = '';
            document.getElementById('scModal').classList.add('open');
            document.getElementById('scName').focus();
        }

        function scToggleApprover() {
            document.getElementById('scApproverWrap').style.display =
                document.getElementById('scRequiresApproval').checked ? 'block' : 'none';
        }

        function scCloseEditor() { document.getElementById('scModal').classList.remove('open'); }

        async function scSave() {
            const name = document.getElementById('scName').value.trim();
            const errEl = document.getElementById('scErr');
            if (!name) { errEl.textContent = 'Name is required.'; return; }
            const btn = document.getElementById('scSaveBtn');
            btn.disabled = true; btn.textContent = 'Saving…';
            const payload = {
                id: document.getElementById('scId').value || null,
                name: name,
                description: document.getElementById('scDesc').value.trim(),
                category_id: document.getElementById('scCategory').value || null,
                department_id: document.getElementById('scDepartment').value || null,
                priority_id: document.getElementById('scPriority').value || null,
                form_id: document.getElementById('scForm').value || null,
                requires_approval: document.getElementById('scRequiresApproval').checked ? 1 : 0,
                approver_analyst_id: document.getElementById('scApprover').value || null,
                display_order: parseInt(document.getElementById('scOrder').value, 10) || 0,
                icon: document.getElementById('scIcon').value.trim(),
                is_active: document.getElementById('scActive').checked ? 1 : 0
            };
            if (payload.requires_approval && !payload.approver_analyst_id) {
                errEl.textContent = 'Choose an approver, or turn off approval.';
                btn.disabled = false; btn.textContent = 'Save';
                return;
            }
            try {
                const data = await (await fetch(SC_API + 'save_catalog_item.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                })).json();
                if (!data.success) { errEl.textContent = data.error || 'Could not save.'; return; }
                scCloseEditor();
                await scLoad();
            } catch (e) {
                errEl.textContent = 'Could not save — please try again.';
            } finally {
                btn.disabled = false; btn.textContent = 'Save';
            }
        }

        async function scDelete(id) {
            const it = scItems.find(x => x.id === id);
            if (!it) return;
            if (!confirm('Delete "' + it.name + '"? Tickets already raised from it are kept.')) return;
            try {
                const data = await (await fetch(SC_API + 'delete_catalog_item.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
                })).json();
                if (!data.success) { alert(data.error || 'Could not delete.'); return; }
                await scLoad();
            } catch (e) { alert('Could not delete — please try again.'); }
        }

        document.getElementById('scModal').addEventListener('click', e => { if (e.target.id === 'scModal') scCloseEditor(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') scCloseEditor(); });
        scLoad();
    </script>
</body>
</html>
