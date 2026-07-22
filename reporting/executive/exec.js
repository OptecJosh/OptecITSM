/*
 * Executive dashboard (Phase 8c). Fetches the single KPI dispatcher
 * (api/reporting/get_exec_dashboard_data.php) and renders curated tiles + a
 * chart grid. Curated/fixed — not user-customizable (a future enhancement).
 */

const EX_API = '../../api/reporting/get_exec_dashboard_data.php';
const exCharts = [];

// SLA outcome → fixed colour so "Breached" is always red, "Met" green, etc.
const SLA_COLOURS = {
    'On track': '#2563eb',
    'Approaching breach': '#f59e0b',
    'Breached': '#dc2626',
    'Met': '#16a34a',
    'Not tracked': '#9ca3af',
};
// Generic categorical palette for the other charts.
const EX_PALETTE = ['#ca5010', '#2563eb', '#16a34a', '#9333ea', '#f59e0b', '#0891b2', '#dc2626', '#6b7280', '#db2777', '#65a30d'];

function exEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Legible chart text/grid colours for the active theme.
function exThemeColours() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    return {
        text: dark ? '#d1d5db' : '#374151',
        grid: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.07)',
    };
}

async function exInit() {
    let data;
    try {
        const r = await fetch(EX_API);
        data = await r.json();
    } catch (e) { data = { success: false, error: 'Network error' }; }

    const grid = document.getElementById('exGrid');
    if (!data.success) {
        grid.innerHTML = `<div class="exec-note exec-error">${exEsc(data.error || 'Failed to load dashboard')}</div>`;
        return;
    }

    document.getElementById('exScope').textContent = 'Scope: ' + (data.scope_label || '—');
    renderTiles(data.tiles || {});
    renderGrid(data.charts || {});
}

function renderTiles(tiles) {
    const host = document.getElementById('exTiles');
    const sla = tiles.sla || {};
    const slaValue = (sla.pct === null || sla.pct === undefined) ? '—' : sla.pct + '%';
    const slaSub = (sla.tracked > 0)
        ? `${sla.breached} breached of ${sla.tracked} tracked`
        : 'No tracked SLAs yet';
    host.innerHTML = `
        <div class="exec-tile">
            <div class="tile-label">Open tickets</div>
            <div class="tile-value">${Number(tiles.open_tickets || 0).toLocaleString()}</div>
            <div class="tile-sub">Not closed, in scope</div>
        </div>
        <div class="exec-tile">
            <div class="tile-label">SLA breach rate</div>
            <div class="tile-value">${exEsc(slaValue)}</div>
            <div class="tile-sub">${exEsc(slaSub)} · resolution</div>
        </div>`;
}

function renderGrid(charts) {
    const grid = document.getElementById('exGrid');
    grid.innerHTML = '';
    exCharts.forEach(c => c.destroy());
    exCharts.length = 0;

    exCard(grid, 'Open tickets by priority', charts.open_by_priority, 'bar');
    exCard(grid, 'SLA resolution outcome', charts.sla_resolution, 'doughnut', SLA_COLOURS);
    exCard(grid, 'Assets by status', charts.assets_by_status, 'doughnut');
    exCard(grid, 'Changes by state', charts.changes_by_state, 'bar');
}

function exCard(grid, title, rows, type, colourMap) {
    const card = document.createElement('div');
    card.className = 'exec-card';
    const h = document.createElement('h3');
    h.textContent = title;
    card.appendChild(h);

    if (!rows || !rows.length) {
        const empty = document.createElement('div');
        empty.className = 'exec-empty';
        empty.textContent = 'No data';
        card.appendChild(empty);
        grid.appendChild(card);
        return;
    }

    const wrap = document.createElement('div');
    wrap.className = 'exec-chart-wrap';
    const canvas = document.createElement('canvas');
    wrap.appendChild(canvas);
    card.appendChild(wrap);
    grid.appendChild(card);

    if (typeof Chart === 'undefined') return;
    const top = rows.slice(0, 12);
    const labels = top.map(r => r.label);
    const values = top.map(r => r.count);
    const colours = colourMap
        ? labels.map((l, i) => colourMap[l] || EX_PALETTE[i % EX_PALETTE.length])
        : labels.map((l, i) => EX_PALETTE[i % EX_PALETTE.length]);
    const theme = exThemeColours();

    const isPie = (type === 'doughnut' || type === 'pie');
    const chart = new Chart(canvas, {
        type,
        data: {
            labels,
            datasets: [{
                label: 'Count',
                data: values,
                backgroundColor: isPie ? colours : (colourMap ? colours : '#ca5010'),
                borderWidth: 0,
            }],
        },
        options: {
            indexAxis: (type === 'bar') ? 'y' : 'x',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: isPie,
                    position: 'right',
                    labels: { color: theme.text, boxWidth: 12, font: { size: 11 } },
                },
            },
            scales: isPie ? {} : {
                x: { beginAtZero: true, ticks: { precision: 0, color: theme.text }, grid: { color: theme.grid } },
                y: { ticks: { color: theme.text }, grid: { color: theme.grid } },
            },
        },
    });
    exCharts.push(chart);
}

document.addEventListener('DOMContentLoaded', exInit);
