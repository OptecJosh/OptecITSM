/*
 * Reporting > Data export. Loads the datasets this analyst may export, groups
 * them by module in the Module select, and builds the download URL for
 * api/export/export_dataset.php. Date inputs only apply to datasets that declare
 * a date field; they are disabled (and cleared) for the rest.
 *
 * ?dataset=<key> in the URL preselects a dataset, so a module page can deep-link
 * straight to its own export.
 */

const EX_API = '../../api/export/';
let exDatasets = [];
let exBundles = [];
let exMaxRows = 0;

function exEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function exCurrent() {
    const key = document.getElementById('exDataset').value;
    return exDatasets.find(d => d.key === key) || null;
}

async function exLoad() {
    const desc = document.getElementById('exDesc');
    let data;
    try { data = await (await fetch(EX_API + 'get_datasets.php')).json(); }
    catch (e) { data = { success: false, error: 'Network error' }; }

    if (!data.success) {
        desc.innerHTML = '<span class="ex-err">' + exEsc(data.error || 'Failed to load datasets') + '</span>';
        document.getElementById('exGo').disabled = true;
        return;
    }
    exDatasets = data.datasets || [];
    exBundles = data.bundles || [];
    exMaxRows = data.max_rows || 0;
    exRenderBundles();

    if (!exDatasets.length) {
        desc.textContent = 'No datasets are available for your modules.';
        document.getElementById('exGo').disabled = true;
        return;
    }

    const groups = [];
    exDatasets.forEach(d => { if (!groups.includes(d.group)) groups.push(d.group); });
    groups.sort((a, b) => a.localeCompare(b));
    document.getElementById('exModule').innerHTML = groups.map(g => `<option value="${exEsc(g)}">${exEsc(g)}</option>`).join('');

    // Deep link: ?dataset=tickets lands on that dataset's module.
    const wanted = new URLSearchParams(location.search).get('dataset');
    const preset = wanted ? exDatasets.find(d => d.key === wanted) : null;
    if (preset) document.getElementById('exModule').value = preset.group;

    exRenderDatasets(preset ? preset.key : null);
}

function exRenderDatasets(selectKey) {
    const group = document.getElementById('exModule').value;
    const inGroup = exDatasets.filter(d => d.group === group);
    document.getElementById('exDataset').innerHTML =
        inGroup.map(d => `<option value="${exEsc(d.key)}">${exEsc(d.label)}</option>`).join('');
    if (selectKey && inGroup.some(d => d.key === selectKey)) {
        document.getElementById('exDataset').value = selectKey;
    }
    exRenderDesc();
}

function exRenderDesc() {
    const d = exCurrent();
    const desc = document.getElementById('exDesc');
    const note = document.getElementById('exNote');
    const from = document.getElementById('exFrom');
    const to = document.getElementById('exTo');

    if (!d) { desc.textContent = ''; note.textContent = ''; return; }

    const tags = [];
    if (d.admin_only) tags.push('<span class="ex-tag">Admin only</span>');
    if (d.tenant) tags.push('<span class="ex-tag">Active company only</span>');
    if (d.has_date) tags.push('<span class="ex-tag">Date filter: ' + exEsc(d.date_field) + '</span>');
    desc.innerHTML = tags.join('') + exEsc(d.description || '');

    // Dates only mean something where the dataset declares a date column.
    [from, to].forEach(el => {
        el.disabled = !d.has_date;
        if (!d.has_date) el.value = '';
        el.title = d.has_date ? 'Filters on ' + d.date_field : 'This dataset has no date field to filter on';
    });

    note.textContent = 'Columns come from the live schema; credentials, tokens and other secrets are never included.'
        + (exMaxRows ? ' Up to ' + exMaxRows.toLocaleString() + ' rows per export.' : '');
}

function exDownload() {
    const d = exCurrent();
    if (!d) return;
    const params = new URLSearchParams({ dataset: d.key, format: document.getElementById('exFormat').value });
    const from = document.getElementById('exFrom').value;
    const to = document.getElementById('exTo').value;
    if (d.has_date && from) params.set('from', from);
    if (d.has_date && to) params.set('to', to);
    window.location = EX_API + 'export_dataset.php?' + params.toString();
}

/* ---- bundle export ---------------------------------------------------- */

/* Hide the whole card when no bundle has any dataset this analyst can reach —
   an empty select with a live button is worse than no card at all. */
function exRenderBundles() {
    const card = document.getElementById('exBundleCard');
    if (!card) return;
    if (!exBundles.length) { card.style.display = 'none'; return; }
    card.style.display = '';
    document.getElementById('exBundle').innerHTML =
        exBundles.map(b => `<option value="${exEsc(b.key)}">${exEsc(b.label)}</option>`).join('');
    exRenderBundle();
}

function exRenderBundle() {
    const b = exBundles.find(x => x.key === document.getElementById('exBundle').value);
    const desc = document.getElementById('exBundleDesc');
    if (!b) { desc.textContent = ''; return; }
    desc.innerHTML = '<span class="ex-tag">' + b.count + ' dataset' + (b.count === 1 ? '' : 's') + '</span>'
        + exEsc(b.description || '')
        + '<br><span style="font-size:12px">Includes: ' + exEsc(b.datasets.join(', ')) + '</span>'
        + '<br><span style="font-size:12px">Dates filter each dataset on its own date column; datasets without one '
        + 'are exported in full.</span>';
}

function exDownloadBundle() {
    const b = exBundles.find(x => x.key === document.getElementById('exBundle').value);
    if (!b) return;
    const params = new URLSearchParams({ bundle: b.key });
    const from = document.getElementById('exBFrom').value;
    const to = document.getElementById('exBTo').value;
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    window.location = EX_API + 'export_bundle.php?' + params.toString();
}

document.addEventListener('DOMContentLoaded', exLoad);
