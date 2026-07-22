/*
 * Overtime approvals (Phase 11b): pending queue + approve/reject with a note.
 */

const OT_API = '../api/overtime/';
const OT_TYPE_LABEL = { standard: 'Standard', time_and_half: 'Time ½', double: 'Double' };

function otEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function otLoadQueue() {
    const host = document.getElementById('otQueue');
    let data;
    try { data = await (await fetch(OT_API + 'get_overtime_approvals.php')).json(); }
    catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) { host.innerHTML = `<div class="ot-note ot-err">${otEsc(data.error || 'Failed to load')}</div>`; return; }
    if (!data.can_approve) { host.innerHTML = '<div class="ot-note">You have no one reporting to you, so there is nothing to approve.</div>'; return; }
    if (!data.requests.length) { host.innerHTML = '<div class="ot-note">No overtime awaiting your decision.</div>'; return; }

    const rows = data.requests.map(r => `
        <tr>
            <td>${otEsc(r.agent_name)}</td>
            <td>${otEsc(r.work_date)}</td>
            <td>${otEsc(r.start_time)}&ndash;${otEsc(r.end_time)}</td>
            <td>${r.hours}h</td>
            <td>${otEsc(OT_TYPE_LABEL[r.overtime_type] || r.overtime_type)} (${r.rate_multiplier}&times;)</td>
            <td>${otEsc(r.reason || '')}</td>
            <td class="ot-actions">
                <button class="btn btn-primary btn-sm" onclick="otDecide(${r.id}, 'approve')">Approve</button>
                <button class="btn btn-secondary btn-sm" onclick="otDecide(${r.id}, 'reject')">Reject</button>
            </td>
        </tr>`).join('');

    host.innerHTML = `<table class="ot">
        <thead><tr><th>Agent</th><th>Date</th><th>Time</th><th>Hours</th><th>Type</th><th>Reason</th><th></th></tr></thead>
        <tbody>${rows}</tbody></table>`;
}

function otDecide(id, action) {
    document.getElementById('otDecideId').value = id;
    document.getElementById('otDecideAction').value = action;
    document.getElementById('otDecideTitle').textContent = action === 'approve' ? 'Approve overtime' : 'Reject overtime';
    document.getElementById('otDecideNote').value = '';
    document.getElementById('otDecideBack').classList.add('active');
}
function otCloseDecide() { document.getElementById('otDecideBack').classList.remove('active'); }

async function otConfirmDecide() {
    const id = parseInt(document.getElementById('otDecideId').value, 10);
    const decision = document.getElementById('otDecideAction').value;
    const note = document.getElementById('otDecideNote').value.trim();
    let data;
    try {
        data = await (await fetch(OT_API + 'decide_overtime_request.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, decision, note }),
        })).json();
    } catch (e) { data = { success: false, error: 'Network error' }; }
    if (!data.success) { alert(data.error || 'Decision failed'); return; }
    otCloseDecide();
    otLoadQueue();
}

document.addEventListener('DOMContentLoaded', otLoadQueue);
