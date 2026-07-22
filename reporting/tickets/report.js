/*
 * Ticket report builder (Phase 4A).
 * Self-contained: fetches its own lookups, renders filter checkbox groups, and
 * runs reports through api/reporting/get_ticket_report.php — which reuses the
 * SAME server-side filter engine as the ticket list and saved queues. Results
 * render as a summary, a Chart.js bar chart, and a table (with CSV export).
 */

const RB_API = '../../api/';
const GROUP_DIMS = [
    { key: 'status',        label: 'Status' },
    { key: 'priority',      label: 'Priority' },
    { key: 'type',          label: 'Type' },
    { key: 'category',      label: 'Category' },
    { key: 'subcategory',   label: 'Subcategory' },
    { key: 'assignee',      label: 'Assignee' },
    { key: 'department',    label: 'Department' },
    { key: 'customer',      label: 'Customer' },
    { key: 'origin',        label: 'Origin' },
    { key: 'tag',           label: 'Tag' },
    { key: 'created_month', label: 'Created month' },
    { key: 'sla_response_outcome',   label: 'SLA response outcome' },
    { key: 'sla_resolution_outcome', label: 'SLA resolution outcome' },
];

// SLA snapshot states (Phase 8a) — static options for the filter checkbox
// groups; 'na' is deliberately omitted from the UI (filtering for "not tracked"
// is niche). Values match ticket_sla_snapshot.<col> / the shared filter engine.
const SLA_STATE_OPTS = [
    { v: 'ok',          l: 'On track' },
    { v: 'approaching', l: 'Approaching breach' },
    { v: 'breached',    l: 'Breached' },
    { v: 'met',         l: 'Met' },
];

let rbLookups = {};        // field key -> [{ v, l }]
let rbMultiTenant = false;
let rbChart = null;
let rbLastReport = null;

function rbEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
async function rbGet(url) {
    try { const r = await fetch(url); return await r.json(); } catch (e) { return {}; }
}

async function rbInit() {
    const gb = document.getElementById('rbGroupBy');
    gb.innerHTML = GROUP_DIMS.map(d => `<option value="${d.key}">${rbEsc(d.label)}</option>`).join('');

    const [st, pr, ty, ca, or2, dep, an, tn, tg] = await Promise.all([
        rbGet(RB_API + 'tickets/get_ticket_statuses.php'),
        rbGet(RB_API + 'tickets/get_ticket_priorities.php'),
        rbGet(RB_API + 'tickets/get_ticket_types.php'),
        rbGet(RB_API + 'tickets/get_ticket_categories.php'),
        rbGet(RB_API + 'tickets/get_ticket_origins.php'),
        rbGet(RB_API + 'tickets/get_my_departments.php'),
        rbGet(RB_API + 'tickets/get_analysts.php'),
        rbGet(RB_API + 'system/get_tenants.php?accessible=1'),
        rbGet(RB_API + 'tickets/get_ticket_tags.php'),
    ]);

    const map = (arr, vk, lk) => (arr || []).map(x => ({ v: x[vk], l: x[lk] }));
    rbLookups.status         = map(st.statuses, 'name', 'name');
    rbLookups.priority_id    = map(pr.priorities, 'id', 'name');
    rbLookups.ticket_type_id = map(ty.ticket_types || ty.types, 'id', 'name');
    rbLookups.category_id    = map(ca.ticket_categories || ca.categories, 'id', 'name');
    rbLookups.origin_id      = map(or2.origins || or2.ticket_origins, 'id', 'name');
    rbLookups.department_id  = map(dep.departments, 'id', 'name');
    rbLookups.assignee_id    = map(an.analysts, 'id', 'full_name');
    const tenants = tn.tenants || tn.companies || [];
    rbLookups.tenant_id      = tenants.map(x => ({ v: x.id, l: x.name }));
    rbLookups.tag_id         = map(tg.tags, 'id', 'name');
    rbMultiTenant = tenants.length > 1;

    renderRbFilters();
}

function rbGroup(label, field) {
    const opts = rbLookups[field] || [];
    if (!opts.length) return '';
    const kind = field === 'status' ? 'str' : 'int';
    const items = opts.map(o =>
        `<label class="rb-opt"><input type="checkbox" data-field="${field}" data-kind="${kind}" value="${rbEsc(String(o.v))}"> ${rbEsc(o.l)}</label>`
    ).join('');
    return `<div class="rb-field"><div class="rb-field-label">${rbEsc(label)}</div><div class="rb-options">${items}</div></div>`;
}

// Static checkbox group (fixed options, not a server lookup). Same markup as
// rbGroup so rbReadFilters picks it up generically — data-field becomes the
// filter key, kind 'str' keeps the value a string.
function rbStaticGroup(label, field, opts) {
    const items = opts.map(o =>
        `<label class="rb-opt"><input type="checkbox" data-field="${field}" data-kind="str" value="${rbEsc(String(o.v))}"> ${rbEsc(o.l)}</label>`
    ).join('');
    return `<div class="rb-field"><div class="rb-field-label">${rbEsc(label)}</div><div class="rb-options">${items}</div></div>`;
}

function renderRbFilters() {
    document.getElementById('rbFilters').innerHTML =
        rbGroup('Status', 'status') +
        rbGroup('Priority', 'priority_id') +
        rbGroup('Type', 'ticket_type_id') +
        rbGroup('Category', 'category_id') +
        rbGroup('Origin', 'origin_id') +
        rbGroup('Assignee', 'assignee_id') +
        rbGroup('Department', 'department_id') +
        rbGroup('Tags', 'tag_id') +
        (rbMultiTenant ? rbGroup('Customer', 'tenant_id') : '') +
        rbStaticGroup('SLA response', 'sla_response_state', SLA_STATE_OPTS) +
        rbStaticGroup('SLA resolution', 'sla_resolution_state', SLA_STATE_OPTS);
}

function rbReadFilters() {
    const f = {};
    document.querySelectorAll('#rbFilters input[type=checkbox]:checked').forEach(cb => {
        const field = cb.dataset.field;
        const val = cb.dataset.kind === 'int' ? Number(cb.value) : cb.value;
        (f[field] = f[field] || []).push(val);
    });
    const kw = document.getElementById('rbKeyword').value.trim();
    if (kw) f.keyword = kw;
    const cf = document.getElementById('rbFrom').value;
    if (cf) f.created_from = cf;
    const ct = document.getElementById('rbTo').value;
    if (ct) f.created_to = ct;
    return f;
}

async function runReport() {
    const groupBy = document.getElementById('rbGroupBy').value;
    const filters = rbReadFilters();
    const url = RB_API + 'reporting/get_ticket_report.php?group_by=' + encodeURIComponent(groupBy)
        + '&filters=' + encodeURIComponent(JSON.stringify(filters));
    const resEl = document.getElementById('rbResults');
    resEl.innerHTML = '<div class="rb-note">Running…</div>';
    const data = await rbGet(url);
    if (!data.success) {
        resEl.innerHTML = `<div class="rb-note rb-error">${rbEsc(data.error || 'Report failed')}</div>`;
        return;
    }
    renderReport(data);
}

function renderReport(data) {
    const resEl = document.getElementById('rbResults');
    const dimLabel = (GROUP_DIMS.find(d => d.key === data.group_by) || {}).label || data.group_by;
    if (!data.rows.length) {
        resEl.innerHTML = '<div class="rb-note">No tickets match these filters.</div>';
        if (rbChart) { rbChart.destroy(); rbChart = null; }
        return;
    }
    const total = data.total || 0;
    const rowsHtml = data.rows.map(r => {
        const pct = total ? Math.round((r.count / total) * 1000) / 10 : 0;
        return `<tr><td>${rbEsc(r.label)}</td><td class="rb-num">${r.count}</td><td class="rb-num">${pct}%</td></tr>`;
    }).join('');
    resEl.innerHTML = `
        <div class="rb-summary">
            <span><strong>${total}</strong> ticket${total === 1 ? '' : 's'} · grouped by ${rbEsc(dimLabel)}</span>
            <button class="btn btn-secondary" onclick="exportReportCsv()">Export CSV</button>
        </div>
        <div class="rb-chart-wrap"><canvas id="rbChart"></canvas></div>
        <table class="rb-table">
            <thead><tr><th>${rbEsc(dimLabel)}</th><th class="rb-num">Count</th><th class="rb-num">%</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
        </table>`;
    rbLastReport = data;
    drawChart(data);
}

function drawChart(data) {
    const ctx = document.getElementById('rbChart');
    if (!ctx || typeof Chart === 'undefined') return;
    const top = data.rows.slice(0, 20);
    if (rbChart) { rbChart.destroy(); rbChart = null; }
    rbChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: top.map(r => r.label),
            datasets: [{ label: 'Tickets', data: top.map(r => r.count), backgroundColor: '#ca5010' }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
}

function exportReportCsv() {
    if (!rbLastReport) return;
    const dimLabel = (GROUP_DIMS.find(d => d.key === rbLastReport.group_by) || {}).label || rbLastReport.group_by;
    const total = rbLastReport.total || 0;
    let csv = `"${dimLabel}","Count","Percent"\n`;
    rbLastReport.rows.forEach(r => {
        const pct = total ? (r.count / total * 100).toFixed(1) : '0';
        csv += `"${String(r.label).replace(/"/g, '""')}",${r.count},${pct}\n`;
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'ticket-report-' + rbLastReport.group_by + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
}

/* ---------------------------------------------------------------------------
 * Scheduled reports (Phase 8b)
 * A schedule captures the builder's CURRENT group-by + filters. Editing an
 * existing schedule lets you change name/cadence/format/recipients/active/shared
 * but not its grouping/filters (shown as read-only context) — to change those,
 * set them in the builder and create a new schedule.
 * ------------------------------------------------------------------------- */
let rbSchedules = [];
let rbCanManageShared = false;
let rbSchedDraft = null;   // { group_by, filters } captured when the modal opens

const SCHED_API = RB_API + 'reporting/';

async function loadSchedules() {
    const data = await rbGet(SCHED_API + 'get_scheduled_reports.php');
    if (!data.success) return;
    rbSchedules = data.reports || [];
    rbCanManageShared = !!data.can_manage_shared;
    renderSchedules();
}

function groupLabelFor(key) {
    return (GROUP_DIMS.find(d => d.key === key) || {}).label || key;
}

function renderSchedules() {
    const card = document.getElementById('rbScheduledCard');
    const host = document.getElementById('rbScheduled');
    if (!rbSchedules.length) { card.style.display = 'none'; return; }
    card.style.display = '';
    const rows = rbSchedules.map(s => {
        const next = s.next_run_at ? rbEsc(String(s.next_run_at).slice(0, 16)) + ' UTC' : '—';
        const cad = s.cadence.charAt(0).toUpperCase() + s.cadence.slice(1);
        return `<tr class="${s.is_active ? '' : 'rb-sched-off'}">
            <td>${rbEsc(s.name)}${s.is_shared ? '<span class="rb-sched-badge">Shared</span>' : ''}${s.is_active ? '' : '<span class="rb-sched-badge">Paused</span>'}</td>
            <td>${rbEsc(groupLabelFor(s.group_by))}</td>
            <td>${rbEsc(cad)}</td>
            <td>${rbEsc(s.recipients || '—')}</td>
            <td>${next}</td>
            <td style="white-space:nowrap;">
                <button class="rb-linkbtn" onclick="editSchedule(${s.id})">Edit</button>
                <button class="rb-linkbtn" onclick="deleteSchedule(${s.id})">Delete</button>
            </td></tr>`;
    }).join('');
    host.innerHTML = `<table class="rb-sched-table">
        <thead><tr><th>Name</th><th>Grouped by</th><th>Cadence</th><th>Recipients</th><th>Next run</th><th></th></tr></thead>
        <tbody>${rows}</tbody></table>`;
}

function setSchedContext(groupBy, filters) {
    const n = Object.keys(filters || {}).length;
    document.getElementById('rbSchedContext').textContent =
        `Grouped by ${groupLabelFor(groupBy)} · ${n} filter${n === 1 ? '' : 's'} applied`;
}

function openScheduleModal() {
    rbSchedDraft = { group_by: document.getElementById('rbGroupBy').value, filters: rbReadFilters() };
    document.getElementById('rbSchedModalTitle').textContent = 'Schedule report';
    document.getElementById('rbSchedId').value = '';
    document.getElementById('rbSchedName').value = '';
    document.getElementById('rbSchedCadence').value = 'weekly';
    document.getElementById('rbSchedFormat').value = 'both';
    document.getElementById('rbSchedRecipients').value = '';
    document.getElementById('rbSchedShared').checked = false;
    document.getElementById('rbSchedActive').checked = true;
    document.getElementById('rbSchedErr').textContent = '';
    document.getElementById('rbSchedSharedWrap').style.display = rbCanManageShared ? '' : 'none';
    setSchedContext(rbSchedDraft.group_by, rbSchedDraft.filters);
    document.getElementById('rbSchedModal').style.display = 'flex';
}

function editSchedule(id) {
    const s = rbSchedules.find(x => x.id === id);
    if (!s) return;
    rbSchedDraft = { group_by: s.group_by, filters: s.filters || {} };
    document.getElementById('rbSchedModalTitle').textContent = 'Edit scheduled report';
    document.getElementById('rbSchedId').value = s.id;
    document.getElementById('rbSchedName').value = s.name;
    document.getElementById('rbSchedCadence').value = s.cadence;
    document.getElementById('rbSchedFormat').value = s.format;
    document.getElementById('rbSchedRecipients').value = s.recipients || '';
    document.getElementById('rbSchedShared').checked = !!s.is_shared;
    document.getElementById('rbSchedActive').checked = !!s.is_active;
    document.getElementById('rbSchedErr').textContent = '';
    // Only admins can manage shared; also show the toggle if it's already shared.
    document.getElementById('rbSchedSharedWrap').style.display = (rbCanManageShared || s.is_shared) ? '' : 'none';
    setSchedContext(rbSchedDraft.group_by, rbSchedDraft.filters);
    document.getElementById('rbSchedModal').style.display = 'flex';
}

function closeScheduleModal() {
    document.getElementById('rbSchedModal').style.display = 'none';
}

async function saveSchedule() {
    const errEl = document.getElementById('rbSchedErr');
    const body = {
        id: document.getElementById('rbSchedId').value || undefined,
        name: document.getElementById('rbSchedName').value.trim(),
        group_by: rbSchedDraft.group_by,
        filters: rbSchedDraft.filters,
        cadence: document.getElementById('rbSchedCadence').value,
        format: document.getElementById('rbSchedFormat').value,
        recipients: document.getElementById('rbSchedRecipients').value.trim(),
        is_shared: document.getElementById('rbSchedShared').checked,
        is_active: document.getElementById('rbSchedActive').checked,
    };
    if (!body.name) { errEl.textContent = 'A name is required.'; return; }
    if (!body.recipients) { errEl.textContent = 'At least one recipient email is required.'; return; }
    errEl.textContent = 'Saving…';
    let res;
    try {
        const r = await fetch(SCHED_API + 'save_scheduled_report.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
        });
        res = await r.json();
    } catch (e) { res = { success: false, error: 'Network error' }; }
    if (!res.success) { errEl.textContent = res.error || 'Save failed'; return; }
    closeScheduleModal();
    loadSchedules();
}

async function deleteSchedule(id) {
    const s = rbSchedules.find(x => x.id === id);
    if (!confirm('Delete scheduled report "' + (s ? s.name : id) + '"?')) return;
    try {
        await fetch(SCHED_API + 'delete_scheduled_report.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
        });
    } catch (e) { /* ignore */ }
    loadSchedules();
}

document.addEventListener('DOMContentLoaded', () => { rbInit(); loadSchedules(); });
