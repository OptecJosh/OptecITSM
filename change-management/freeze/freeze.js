/*
 * Change freeze windows admin (Phase 9b). CRUD over
 * api/change-management/{get,save,delete}_freeze_window.php. Read-only for
 * non-admins (the New button + row actions stay hidden).
 *
 * A window can be scoped to change categories and/or companies; with nothing
 * selected it applies to every change ("All changes" in the Applies to column).
 */

const FZ_API = '../../api/change-management/';
let fzCanManage = false;
let fzWindows = [];
let fzCategories = [];   // [{id,name}] change categories a window can be scoped to
let fzCompanies = [];    // [{id,name}] companies (only offered on a multi-company install)

function fzEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// 'YYYY-MM-DD HH:MM:SS' (UTC) → local display + the value a datetime-local wants.
function fzFmt(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ', 'T') + 'Z');
    if (isNaN(d)) return fzEsc(dt);
    return d.toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function fzToInput(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ', 'T') + 'Z');
    if (isNaN(d)) return '';
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

async function fzLoad() {
    const host = document.getElementById('fzTable');
    let data;
    try { data = await (await fetch(FZ_API + 'get_freeze_windows.php')).json(); }
    catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) {
        host.innerHTML = `<div class="fz-note fz-error">${fzEsc(data.error || 'Failed to load')}</div>`;
        return;
    }
    fzCanManage = !!data.can_manage;
    fzWindows = data.windows || [];
    fzCategories = data.categories || [];
    fzCompanies = data.companies || [];
    document.getElementById('fzAddBtn').style.display = fzCanManage ? '' : 'none';
    // Company scoping is meaningless on a single-company install.
    document.getElementById('fzTenantField').style.display = fzCompanies.length > 1 ? '' : 'none';

    if (!fzWindows.length) {
        host.innerHTML = '<div class="fz-note">No freeze windows defined.</div>';
        return;
    }

    const rows = fzWindows.map(w => {
        const status = !w.is_active
            ? '<span class="fz-badge off">Inactive</span>'
            : (w.in_effect ? '<span class="fz-badge now">In effect</span>' : '<span class="fz-badge on">Active</span>');
        const actions = fzCanManage
            ? `<div class="fz-actions">
                   <button class="btn btn-secondary btn-sm" onclick="fzOpenModal(${w.id})">Edit</button>
                   <button class="btn btn-secondary btn-sm" onclick="fzDelete(${w.id})">Delete</button>
               </div>`
            : '';
        return `<tr>
            <td><strong>${fzEsc(w.name)}</strong>${w.reason ? `<br><span style="color:var(--text-dim,#6b7280);font-size:12px;">${fzEsc(w.reason)}</span>` : ''}</td>
            <td>${fzFmt(w.starts_at)}</td>
            <td>${fzFmt(w.ends_at)}</td>
            <td class="fz-scope">${fzScopeLabel(w)}</td>
            <td>${status}</td>
            <td>${actions}</td>
        </tr>`;
    }).join('');

    host.innerHTML = `
        <table class="fz">
            <thead><tr><th>Name</th><th>Starts</th><th>Ends</th><th>Applies to</th><th>Status</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
}

// "All changes", or the categories / companies the window is narrowed to.
function fzScopeLabel(w) {
    const cats = w.category_names || [];
    const tens = w.tenant_names || [];
    if (!cats.length && !tens.length) return 'All changes';
    const chips = [];
    tens.forEach(n => chips.push(`<span class="fz-chip">${fzEsc(n)}</span>`));
    cats.forEach(n => chips.push(`<span class="fz-chip">${fzEsc(n)}</span>`));
    return chips.join('');
}

// Fill a multi-select from [{id,name}] and preselect the given ids.
function fzFillSelect(elId, options, selectedIds) {
    const el = document.getElementById(elId);
    if (!el) return;
    const sel = (selectedIds || []).map(Number);
    el.innerHTML = options.map(o => `<option value="${o.id}"${sel.includes(Number(o.id)) ? ' selected' : ''}>${fzEsc(o.name)}</option>`).join('');
}
function fzSelectedIds(elId) {
    const el = document.getElementById(elId);
    if (!el) return [];
    return Array.from(el.selectedOptions).map(o => parseInt(o.value, 10)).filter(n => n > 0);
}

function fzOpenModal(id) {
    if (!fzCanManage) return;
    const w = id ? fzWindows.find(x => x.id === id) : null;
    document.getElementById('fzModalTitle').textContent = w ? 'Edit freeze window' : 'New freeze window';
    document.getElementById('fzId').value = w ? w.id : '';
    document.getElementById('fzName').value = w ? w.name : '';
    document.getElementById('fzStart').value = w ? fzToInput(w.starts_at) : '';
    document.getElementById('fzEnd').value = w ? fzToInput(w.ends_at) : '';
    document.getElementById('fzReason').value = w ? (w.reason || '') : '';
    document.getElementById('fzActive').checked = w ? !!w.is_active : true;
    fzFillSelect('fzCategories', fzCategories, w ? w.category_ids : []);
    fzFillSelect('fzTenants', fzCompanies, w ? w.tenant_ids : []);
    document.getElementById('fzModalBack').classList.add('active');
}
function fzCloseModal() { document.getElementById('fzModalBack').classList.remove('active'); }

async function fzSave() {
    const payload = {
        id: document.getElementById('fzId').value || null,
        name: document.getElementById('fzName').value.trim(),
        starts_at: document.getElementById('fzStart').value,
        ends_at: document.getElementById('fzEnd').value,
        reason: document.getElementById('fzReason').value.trim(),
        is_active: document.getElementById('fzActive').checked ? 1 : 0,
        category_ids: fzSelectedIds('fzCategories'),
        tenant_ids: fzCompanies.length > 1 ? fzSelectedIds('fzTenants') : [],
    };
    if (!payload.name) { alert('Name is required'); return; }
    if (!payload.starts_at || !payload.ends_at) { alert('Start and end are required'); return; }

    let data;
    try {
        data = await (await fetch(FZ_API + 'save_freeze_window.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        })).json();
    } catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) { alert(data.error || 'Save failed'); return; }
    fzCloseModal();
    fzLoad();
}

async function fzDelete(id) {
    if (!confirm('Delete this freeze window?')) return;
    let data;
    try {
        data = await (await fetch(FZ_API + 'delete_freeze_window.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
        })).json();
    } catch (e) { data = { success: false, error: 'Network error' }; }
    if (!data.success) { alert(data.error || 'Delete failed'); return; }
    fzLoad();
}

document.addEventListener('DOMContentLoaded', fzLoad);
