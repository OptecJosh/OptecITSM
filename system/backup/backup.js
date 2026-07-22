/*
 * Backup & data (Phase 10b): DB backup download (probe-gated), CSV export, and
 * CSV import with a preview→commit gate.
 */

const BK_API = '../../api/system/';

function bkEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ---- Backup availability probe ----
async function bkProbe() {
    const state = document.getElementById('bkBackupState');
    const btn = document.getElementById('bkBackupBtn');
    try {
        const data = await (await fetch(BK_API + 'backup_database.php?probe=1')).json();
        if (data.success && data.available) {
            btn.disabled = false;
            state.textContent = 'Ready.';
        } else {
            btn.disabled = true;
            state.textContent = data.reason || 'Backup tool unavailable on this server.';
        }
    } catch (e) {
        btn.disabled = true;
        state.textContent = 'Could not check backup availability.';
    }
}
function bkDownloadBackup() { window.location = BK_API + 'backup_database.php'; }

// ---- Export ----
function bkExport() {
    const entity = document.getElementById('bkExportEntity').value;
    window.location = BK_API + 'export_entity.php?entity=' + encodeURIComponent(entity);
}

// ---- Import ----
function bkResetCommit() {
    document.getElementById('bkCommitBtn').disabled = true;
}

async function bkImportCall(mode) {
    const entity = document.getElementById('bkImportEntity').value;
    const csv = document.getElementById('bkCsv').value;
    if (!csv.trim()) { alert('Paste some CSV first'); return null; }
    try {
        return await (await fetch(BK_API + 'import_entity.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ entity, csv, mode }),
        })).json();
    } catch (e) { return { success: false, error: 'Network error' }; }
}

function bkRenderErrors(errors) {
    if (!errors || !errors.length) return '';
    const rows = errors.map(e => `<tr><td>${bkEsc(e.row)}</td><td>${bkEsc(e.message)}</td></tr>`).join('');
    return `<table><thead><tr><th>Row</th><th>Problem</th></tr></thead><tbody>${rows}</tbody></table>`;
}

async function bkPreview() {
    const res = document.getElementById('bkResult');
    res.innerHTML = 'Previewing&hellip;';
    const data = await bkImportCall('preview');
    if (!data) { res.innerHTML = ''; return; }
    if (!data.success) { res.innerHTML = `<span class="err">${bkEsc(data.error)}</span>`; document.getElementById('bkCommitBtn').disabled = true; return; }

    res.innerHTML = `
        <div class="bk-tiles">
            <div class="bk-tile"><strong>${data.to_create}</strong> to create</div>
            <div class="bk-tile"><strong>${data.to_update}</strong> to update</div>
            <div class="bk-tile"><strong>${data.errors.length}</strong> row error${data.errors.length === 1 ? '' : 's'}</div>
        </div>
        ${data.errors.length ? '<div class="err" style="margin-top:8px;">These rows will be skipped:</div>' + bkRenderErrors(data.errors) : ''}
        <div class="bk-muted" style="margin-top:8px;">Preview only &mdash; nothing has been written. Click <strong>Commit import</strong> to apply.</div>`;

    // Enable commit only if there's something valid to write.
    document.getElementById('bkCommitBtn').disabled = (data.to_create + data.to_update) === 0;
}

async function bkCommit() {
    if (!confirm('Apply this import? Valid rows will be created or updated.')) return;
    const res = document.getElementById('bkResult');
    res.innerHTML = 'Importing&hellip;';
    const data = await bkImportCall('commit');
    if (!data) { res.innerHTML = ''; return; }
    if (!data.success) { res.innerHTML = `<span class="err">${bkEsc(data.error)}</span>`; return; }
    res.innerHTML = `
        <div class="bk-tiles">
            <div class="bk-tile"><strong>${data.created}</strong> created</div>
            <div class="bk-tile"><strong>${data.updated}</strong> updated</div>
            <div class="bk-tile"><strong>${data.skipped}</strong> skipped</div>
        </div>
        ${data.errors.length ? '<div class="err" style="margin-top:8px;">Skipped rows:</div>' + bkRenderErrors(data.errors) : ''}`;
    document.getElementById('bkCommitBtn').disabled = true;
}

document.addEventListener('DOMContentLoaded', bkProbe);
