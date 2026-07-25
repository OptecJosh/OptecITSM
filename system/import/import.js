/*
 * System > Mass import. Dataset picker + column contract, CSV paste or file,
 * preview → commit. The column contract shown here comes from
 * api/import/get_datasets.php, so it can never disagree with what the importer
 * will actually accept. Commit stays locked until a preview has succeeded, and
 * re-locks whenever the dataset or the CSV changes.
 */

const IM_API = '../../api/import/';
let imDatasets = [];
let imRowCap = 0;
let imPreviewed = false;

function imEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function imCurrent() {
    return imDatasets.find(d => d.key === document.getElementById('imDataset').value) || null;
}
function imLock(reason) {
    imPreviewed = false;
    document.getElementById('imCommit').disabled = true;
    document.getElementById('imCommitHint').textContent = reason;
}

async function imLoad() {
    let d;
    try { d = await (await fetch(IM_API + 'get_datasets.php')).json(); }
    catch (e) { d = { success: false, error: 'Network error' }; }
    if (!d.success) {
        document.getElementById('imContract').innerHTML = '<span class="err" style="color:#b91c1c">' + imEsc(d.error || 'Failed to load') + '</span>';
        return;
    }
    imDatasets = d.datasets || [];
    imRowCap = d.row_cap || 0;
    if (!imDatasets.length) {
        document.getElementById('imContract').textContent = 'No importable datasets are available for your modules.';
        return;
    }
    const groups = [];
    imDatasets.forEach(x => { if (!groups.includes(x.group)) groups.push(x.group); });
    groups.sort((a, b) => a.localeCompare(b));
    document.getElementById('imModule').innerHTML = groups.map(g => `<option value="${imEsc(g)}">${imEsc(g)}</option>`).join('');
    imRenderDatasets();
}

function imRenderDatasets() {
    const group = document.getElementById('imModule').value;
    const inGroup = imDatasets.filter(d => d.group === group);
    document.getElementById('imDataset').innerHTML = inGroup.map(d => `<option value="${imEsc(d.key)}">${imEsc(d.label)}</option>`).join('');
    imRenderContract();
}

function imRenderContract() {
    imLock('Preview first — commit unlocks once a preview succeeds.');
    const d = imCurrent();
    const host = document.getElementById('imContract');
    if (!d) { host.textContent = ''; return; }

    const required = d.columns.filter(c => c.required).map(c => c.name)
        .concat(d.lookups.filter(l => l.required).map(l => l.name));

    let how;
    if (d.creates_only) {
        how = 'Every row creates a new record (this dataset has no natural key).';
    } else if (d.upsert_on) {
        how = 'Rows are matched on <code>' + d.upsert_on.join('</code> + <code>') + '</code> — an existing match is updated, otherwise a row is created.';
    } else {
        how = 'Rows are matched on <code>' + imEsc(d.match) + '</code> — an existing match is updated, otherwise a row is created.';
    }

    host.innerHTML = `
        <div>${imEsc(d.notes)}</div>
        <div style="margin-top:8px;">${how}${d.tenant ? ' New rows are stamped with your <strong>active company</strong>.' : ''}</div>
        <div style="margin-top:8px;">Required: ${required.length ? '<span class="req">' + required.map(imEsc).join(', ') + '</span>' : 'nothing beyond a header row'}
            ${imRowCap ? ' &middot; up to ' + imRowCap.toLocaleString() + ' rows per run' : ''}</div>
        <table>
            <thead><tr><th>Column</th><th>Type</th><th>Notes</th></tr></thead>
            <tbody>
                ${d.columns.map(c => `<tr>
                    <td><code>${imEsc(c.name)}</code>${c.required ? ' <span class="req">*</span>' : ''}</td>
                    <td>${imEsc(c.type)}</td>
                    <td>${c.values ? 'one of: ' + c.values.map(imEsc).join(', ') : (c.type === 'bool' ? 'yes / no' : '')}</td>
                </tr>`).join('')}
                ${d.lookups.map(l => `<tr>
                    <td><code>${imEsc(l.name)}</code>${l.required ? ' <span class="req">*</span>' : ''}</td>
                    <td>lookup</td>
                    <td>matched against ${imEsc(l.of)}.${imEsc(l.by)} — must already exist</td>
                </tr>`).join('')}
            </tbody>
        </table>
        <div class="im-warn">Any column not listed here is ignored (the preview names them), so an export with extra columns imports cleanly.</div>`;
}

function imTemplate() {
    const d = imCurrent();
    if (d) window.location = IM_API + 'template.php?dataset=' + encodeURIComponent(d.key);
}

function imReadFile() {
    const input = document.getElementById('imFile');
    const state = document.getElementById('imFileState');
    if (!input.files || !input.files[0]) { state.textContent = 'or paste below'; return; }
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = () => {
        document.getElementById('imCsv').value = String(reader.result || '');
        state.textContent = file.name + ' loaded (' + Math.round(file.size / 1024) + ' KB)';
        imLock('CSV changed — preview again before committing.');
    };
    reader.onerror = () => { state.textContent = 'Could not read that file'; };
    reader.readAsText(file);
}

async function imRun(mode) {
    const d = imCurrent();
    const csv = document.getElementById('imCsv').value;
    const host = document.getElementById('imResult');
    if (!d) return null;
    if (!csv.trim()) { host.innerHTML = '<span class="err">Paste some CSV or choose a file first.</span>'; return null; }

    host.textContent = mode === 'commit' ? 'Importing…' : 'Checking…';
    let r;
    try {
        r = await (await fetch(IM_API + 'run_import.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dataset: d.key, csv: csv, mode: mode }),
        })).json();
    } catch (e) { r = { success: false, error: 'Network error' }; }

    if (!r.success) {
        host.innerHTML = '<span class="err">' + imEsc(r.error || 'Failed') + '</span>';
        imLock('Fix the problem above, then preview again.');
        return null;
    }
    return r;
}

function imErrorTable(errors, count) {
    if (!errors || !errors.length) return '';
    return `<table>
        <thead><tr><th style="width:70px;">Row</th><th>Problem</th></tr></thead>
        <tbody>${errors.map(e => `<tr><td>${imEsc(e.row)}</td><td class="err">${imEsc(e.message)}</td></tr>`).join('')}</tbody>
    </table>${count > errors.length ? '<div class="im-warn">' + (count - errors.length) + ' further problem(s) not shown.</div>' : ''}`;
}

async function imPreview() {
    const r = await imRun('preview');
    if (!r) return;
    const host = document.getElementById('imResult');

    const sample = (r.sample || []).map(s => `<tr>
            <td>${imEsc(s.row)}</td><td>${imEsc(s.action)}</td>
            <td>${Object.entries(s.values).map(([k, v]) => imEsc(k) + '=' + imEsc(v)).join(', ')}</td>
        </tr>`).join('');

    host.innerHTML = `
        <div class="im-tiles">
            <div class="im-tile"><strong>${r.total}</strong>rows read</div>
            <div class="im-tile"><strong>${r.to_create}</strong>to create</div>
            <div class="im-tile"><strong>${r.to_update}</strong>to update</div>
            <div class="im-tile"><strong>${r.error_count}</strong>with problems</div>
        </div>
        ${r.ignored_columns && r.ignored_columns.length
            ? '<div class="im-warn">Ignored columns: ' + r.ignored_columns.map(imEsc).join(', ') + '</div>' : ''}
        ${imErrorTable(r.errors, r.error_count)}
        ${sample ? `<div style="margin-top:10px;">First rows as they will be written:</div>
            <table><thead><tr><th style="width:70px;">Row</th><th style="width:80px;">Action</th><th>Values</th></tr></thead><tbody>${sample}</tbody></table>` : ''}
        <div style="margin-top:10px;">Nothing has been written yet.</div>`;

    if (r.to_create + r.to_update > 0) {
        imPreviewed = true;
        document.getElementById('imCommit').disabled = false;
        document.getElementById('imCommitHint').textContent =
            'Ready: ' + r.to_create + ' to create, ' + r.to_update + ' to update'
            + (r.error_count ? ', ' + r.error_count + ' row(s) will be skipped.' : '.');
    } else {
        imLock('Nothing to import — every row had a problem.');
    }
}

async function imCommit() {
    if (!imPreviewed) return;
    const d = imCurrent();
    if (!confirm('Import into ' + d.label + '? This writes to the live database.')) return;

    const r = await imRun('commit');
    if (!r) return;
    document.getElementById('imResult').innerHTML = `
        <div class="ok">Imported: ${r.created} created, ${r.updated} updated${r.skipped ? ', ' + r.skipped + ' skipped' : ''}.</div>
        ${imErrorTable(r.errors, r.skipped)}
        <div style="margin-top:8px;">Logged to the audit trail (System &rsaquo; Audit log).</div>`;
    imLock('Imported. Preview again to run another batch.');
}

document.addEventListener('DOMContentLoaded', () => {
    imLoad();
    document.getElementById('imCsv').addEventListener('input', () => imLock('CSV changed — preview again before committing.'));
});
