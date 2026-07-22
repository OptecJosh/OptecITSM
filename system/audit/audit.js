/*
 * Unified audit log (Phase 10a). Filters + paged table + CSV export over
 * api/system/get_audit_log.php.
 */

const AUDIT_API = '../../api/system/get_audit_log.php';
let auditPage = 1;

function aEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function aFmtWhen(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ', 'T') + 'Z');
    if (isNaN(d)) return aEsc(dt);
    return d.toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function auditFilters() {
    return {
        module:    document.getElementById('fModule').value,
        actor_id:  document.getElementById('fActor').value,
        date_from: document.getElementById('fFrom').value,
        date_to:   document.getElementById('fTo').value,
        keyword:   document.getElementById('fKeyword').value.trim(),
    };
}
function auditQuery(extra) {
    const f = auditFilters();
    const p = new URLSearchParams();
    Object.keys(f).forEach(k => { if (f[k]) p.set(k, f[k]); });
    Object.keys(extra || {}).forEach(k => p.set(k, extra[k]));
    return p.toString();
}

async function auditLoadActors() {
    try {
        const data = await (await fetch('../../api/tickets/get_analysts.php')).json();
        const sel = document.getElementById('fActor');
        (data.analysts || []).forEach(a => {
            const o = document.createElement('option');
            o.value = a.id; o.textContent = a.full_name;
            sel.appendChild(o);
        });
    } catch (e) { /* leave "Anyone" only */ }
}

function auditApply() { auditPage = 1; auditLoad(); }

async function auditLoad() {
    const host = document.getElementById('auditResults');
    host.innerHTML = '<div class="audit-note">Loading&hellip;</div>';
    let data;
    try {
        data = await (await fetch(AUDIT_API + '?' + auditQuery({ page: auditPage, limit: 50 }))).json();
    } catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) {
        host.innerHTML = `<div class="audit-note audit-error">${aEsc(data.error || 'Failed to load')}</div>`;
        return;
    }
    if (!data.rows.length) {
        host.innerHTML = '<div class="audit-note">No audit entries match these filters.</div>';
        return;
    }

    const rows = data.rows.map(r => `
        <tr>
            <td class="audit-when">${aFmtWhen(r.when)}</td>
            <td>${aEsc(r.actor || '—')}</td>
            <td><span class="audit-pill">${aEsc(r.module)}</span></td>
            <td>${aEsc(r.entity || '')}</td>
            <td>${aEsc(r.action || '')}</td>
            <td class="detail">${aEsc(r.detail || '')}</td>
        </tr>`).join('');

    host.innerHTML = `
        <table class="audit">
            <thead><tr><th>When</th><th>Actor</th><th>Module</th><th>Entity</th><th>Action</th><th>Detail</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
        <div class="audit-foot">
            <button class="btn btn-secondary btn-sm" ${auditPage <= 1 ? 'disabled' : ''} onclick="auditPrev()">&lsaquo; Newer</button>
            <span style="font-size:12px;color:var(--text-dim,#6b7280);">Page ${auditPage}</span>
            <button class="btn btn-secondary btn-sm" ${data.has_more ? '' : 'disabled'} onclick="auditNext()">Older &rsaquo;</button>
        </div>`;
}

function auditPrev() { if (auditPage > 1) { auditPage--; auditLoad(); } }
function auditNext() { auditPage++; auditLoad(); }

function auditExportCsv() {
    window.location = AUDIT_API + '?' + auditQuery({ format: 'csv' });
}

document.addEventListener('DOMContentLoaded', () => {
    auditLoadActors();
    auditLoad();
});
