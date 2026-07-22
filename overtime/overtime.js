/*
 * My overtime (Phase 11a): submit + history over api/overtime/*.
 */

const OT_API = '../api/overtime/';
const OT_TYPE_LABEL = { standard: 'Standard', time_and_half: 'Time ½', double: 'Double' };

function otEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function otLoad() {
    const host = document.getElementById('otHistory');
    let data;
    try { data = await (await fetch(OT_API + 'get_my_overtime.php')).json(); }
    catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) { host.innerHTML = `<div class="ot-note">${otEsc(data.error || 'Failed to load')}</div>`; return; }

    const t = data.totals || {};
    document.getElementById('otTiles').innerHTML = `
        <div class="ot-tile">Pending<strong>${t.pending_hours ?? 0}h</strong></div>
        <div class="ot-tile">Approved<strong>${t.approved_hours ?? 0}h</strong></div>
        <div class="ot-tile">Approved (weighted)<strong>${t.approved_weighted ?? 0}h</strong></div>`;

    if (!data.requests.length) { host.innerHTML = '<div class="ot-note">No overtime logged yet.</div>'; return; }

    const rows = data.requests.map(r => {
        const cancel = r.status === 'pending'
            ? `<button class="btn btn-secondary btn-sm" onclick="otCancel(${r.id})">Cancel</button>` : '';
        return `<tr>
            <td>${otEsc(r.work_date)}</td>
            <td>${otEsc(r.start_time)}&ndash;${otEsc(r.end_time)}</td>
            <td>${r.hours}h</td>
            <td>${otEsc(OT_TYPE_LABEL[r.overtime_type] || r.overtime_type)} (${r.rate_multiplier}&times;)</td>
            <td><span class="ot-badge ${otEsc(r.status)}">${otEsc(r.status)}</span>${r.decision_note ? `<div style="font-size:11px;color:var(--text-dim,#6b7280);">${otEsc(r.decision_note)}</div>` : ''}</td>
            <td>${cancel}</td>
        </tr>`;
    }).join('');

    host.innerHTML = `<table class="ot">
        <thead><tr><th>Date</th><th>Time</th><th>Hours</th><th>Type</th><th>Status</th><th></th></tr></thead>
        <tbody>${rows}</tbody></table>`;
}

async function otSubmit() {
    const err = document.getElementById('otErr');
    err.textContent = '';
    const payload = {
        work_date: document.getElementById('otDate').value,
        start_time: document.getElementById('otStart').value,
        end_time: document.getElementById('otEnd').value,
        overtime_type: document.getElementById('otType').value,
        reason: document.getElementById('otReason').value.trim(),
    };
    if (!payload.work_date || !payload.start_time || !payload.end_time) { err.textContent = 'Date, start and end are required.'; return; }

    let data;
    try {
        data = await (await fetch(OT_API + 'save_overtime_request.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        })).json();
    } catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) { err.textContent = data.error || 'Submit failed'; return; }
    document.getElementById('otReason').value = '';
    document.getElementById('otStart').value = '';
    document.getElementById('otEnd').value = '';
    otLoad();
}

async function otCancel(id) {
    if (!confirm('Cancel this overtime request?')) return;
    let data;
    try {
        data = await (await fetch(OT_API + 'cancel_overtime_request.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
        })).json();
    } catch (e) { data = { success: false, error: 'Network error' }; }
    if (!data.success) { alert(data.error || 'Cancel failed'); return; }
    otLoad();
}

document.addEventListener('DOMContentLoaded', () => {
    const d = document.getElementById('otDate');
    if (d && !d.value) d.value = new Date().toISOString().slice(0, 10);
    otLoad();
});
