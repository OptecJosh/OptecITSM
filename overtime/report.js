/*
 * Overtime report (Phase 11d): per-agent hours + weighted totals + CSV export.
 */

const OT_API = '../api/overtime/get_overtime_report.php';

function otEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function otReportQuery(extra) {
    const p = new URLSearchParams();
    const f = document.getElementById('rFrom').value;
    const t = document.getElementById('rTo').value;
    const s = document.getElementById('rStatus').value;
    if (f) p.set('date_from', f);
    if (t) p.set('date_to', t);
    if (s) p.set('status', s);
    Object.keys(extra || {}).forEach(k => p.set(k, extra[k]));
    return p.toString();
}

async function otReport() {
    const host = document.getElementById('rResults');
    host.innerHTML = '<div class="ot-note">Running&hellip;</div>';
    let data;
    try { data = await (await fetch(OT_API + '?' + otReportQuery())).json(); }
    catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) { host.innerHTML = `<div class="ot-note ot-err">${otEsc(data.error || 'Report failed')}</div>`; return; }
    if (!data.rows.length) { host.innerHTML = '<div class="ot-note">No overtime matches these filters.</div>'; return; }

    const rows = data.rows.map(r => `
        <tr>
            <td>${otEsc(r.agent_name)}</td>
            <td class="num">${r.count}</td>
            <td class="num">${r.hours}</td>
            <td class="num">${r.weighted}</td>
        </tr>`).join('');
    const t = data.totals || {};

    host.innerHTML = `
        <table class="ot">
            <thead><tr><th>Agent</th><th class="num">Entries</th><th class="num">Hours</th><th class="num">Weighted hours</th></tr></thead>
            <tbody>${rows}</tbody>
            <tfoot><tr><td>Total</td><td class="num">${t.count ?? 0}</td><td class="num">${t.hours ?? 0}</td><td class="num">${t.weighted ?? 0}</td></tr></tfoot>
        </table>`;
}

function otReportCsv() {
    window.location = OT_API + '?' + otReportQuery({ format: 'csv' });
}

document.addEventListener('DOMContentLoaded', () => {
    // Default to the current month.
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    const pad = n => String(n).padStart(2, '0');
    const fmt = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    document.getElementById('rFrom').value = fmt(first);
    document.getElementById('rTo').value = fmt(now);
    otReport();
});
