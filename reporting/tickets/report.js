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
        (rbMultiTenant ? rbGroup('Customer', 'tenant_id') : '');
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

document.addEventListener('DOMContentLoaded', rbInit);
