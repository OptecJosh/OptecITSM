/*
 * Change freeze windows admin (Phase 9b). CRUD over
 * api/change-management/{get,save,delete}_freeze_window.php. Read-only for
 * non-admins (the New button + row actions stay hidden).
 */

const FZ_API = '../../api/change-management/';
let fzCanManage = false;
let fzWindows = [];

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
    document.getElementById('fzAddBtn').style.display = fzCanManage ? '' : 'none';

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
            <td>${status}</td>
            <td>${actions}</td>
        </tr>`;
    }).join('');

    host.innerHTML = `
        <table class="fz">
            <thead><tr><th>Name</th><th>Starts</th><th>Ends</th><th>Status</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
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
