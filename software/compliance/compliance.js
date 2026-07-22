/*
 * Software licence compliance (Phase 9a). Renders summary tiles + a per-app
 * true-up table from api/software/get_licence_compliance.php.
 */

const CMP_API = '../../api/software/get_licence_compliance.php';

function cmpEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const STATUS_LABEL = { over: 'Over-deployed', ok: 'Compliant', unused: 'Unused' };

function cmpDelta(app) {
    if (app.delta > 0) return '+' + app.delta;
    return String(app.delta);
}

async function cmpInit() {
    const tableHost = document.getElementById('compTable');
    let data;
    try {
        const r = await fetch(CMP_API);
        data = await r.json();
    } catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) {
        document.getElementById('compTiles').innerHTML = '';
        tableHost.innerHTML = `<div class="comp-note comp-error">${cmpEsc(data.error || 'Failed to load compliance')}</div>`;
        return;
    }

    const s = data.summary || {};
    document.getElementById('compTiles').innerHTML = `
        <div class="comp-tile"><div class="t-label">Licensed apps</div><div class="t-value">${Number(s.licensed_apps || 0)}</div></div>
        <div class="comp-tile ${s.over_deployed_apps ? 'warn' : ''}"><div class="t-label">Over-deployed apps</div><div class="t-value">${Number(s.over_deployed_apps || 0)}</div></div>
        <div class="comp-tile ${s.over_deployed_seats ? 'warn' : ''}"><div class="t-label">Over-deployed seats</div><div class="t-value">${Number(s.over_deployed_seats || 0)}</div></div>
        <div class="comp-tile"><div class="t-label">Under-utilised apps</div><div class="t-value">${Number(s.under_utilised_apps || 0)}</div></div>`;

    if (!data.apps || !data.apps.length) {
        tableHost.innerHTML = '<div class="comp-note">No licensed apps found. Add licences under Licences to track compliance.</div>';
        return;
    }

    const rows = data.apps.map(a => `
        <tr>
            <td>${cmpEsc(a.app_name)}</td>
            <td>${cmpEsc(a.publisher || '—')}</td>
            <td class="num">${Number(a.entitled)}</td>
            <td class="num">${Number(a.installed)}</td>
            <td class="num">${cmpEsc(cmpDelta(a))}</td>
            <td><span class="badge ${cmpEsc(a.status)}">${cmpEsc(STATUS_LABEL[a.status] || a.status)}</span></td>
        </tr>`).join('');

    tableHost.innerHTML = `
        <table class="comp">
            <thead><tr>
                <th>Application</th><th>Publisher</th>
                <th class="num">Entitled</th><th class="num">Installed</th>
                <th class="num">Delta</th><th>Status</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
}

document.addEventListener('DOMContentLoaded', cmpInit);
