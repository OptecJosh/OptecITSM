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

function imRenderDatasets(selectKey) {
    const group = document.getElementById('imModule').value;
    const inGroup = imDatasets.filter(d => d.group === group);
    document.getElementById('imDataset').innerHTML = inGroup.map(d => `<option value="${imEsc(d.key)}">${imEsc(d.label)}</option>`).join('');
    if (selectKey && inGroup.some(d => d.key === selectKey)) {
        document.getElementById('imDataset').value = selectKey;
    }
    imRenderContract();
}

/** Select a dataset by key, switching the module group with it. */
function imSelectDataset(key) {
    const d = imDatasets.find(x => x.key === key);
    if (!d) return;
    document.getElementById('imModule').value = d.group;
    imRenderDatasets(key);
}

/**
 * Which dataset does the pasted/chosen CSV look like? Same ranking the server
 * uses: a dataset that has everything it needs beats one that merely shares
 * column names, then best coverage of the header row wins.
 */
function imDetect(csv) {
    const firstLine = String(csv || '').split(/\r?\n/)[0] || '';
    const header = firstLine.replace(/^﻿/, '').split(',')
        .map(h => h.trim().replace(/^"|"$/g, '').toLowerCase())
        .filter(Boolean);
    if (!header.length) return null;

    const scored = imDatasets.map(d => {
        const accepted = (d.template || []).map(c => c.toLowerCase());
        const required = d.columns.filter(c => c.required).map(c => c.name.toLowerCase())
            .concat(d.lookups.filter(l => l.required).map(l => l.name.toLowerCase()));
        const matched = header.filter(h => accepted.includes(h)).length;
        return {
            key: d.key, label: d.label, matched: matched,
            coverage: matched / header.length,
            usable: required.every(r => header.includes(r)),
        };
    }).filter(s => s.matched > 0);

    scored.sort((a, b) => (a.usable !== b.usable) ? (a.usable ? -1 : 1)
        : (b.coverage - a.coverage) || (b.matched - a.matched));

    const best = scored[0];
    if (!best || !best.usable || best.coverage < 0.5) return null;

    // Two datasets fitting equally well is a coin toss, and switching on a coin
    // toss is worse than not switching. Offer both instead.
    const next = scored[1];
    if (next && next.usable === best.usable && next.coverage === best.coverage) {
        return { ambiguous: true, options: [best, next] };
    }
    return best;
}

/**
 * Point the dataset at whatever the CSV looks like. Called whenever the CSV
 * changes, so the commonest mistake here - right file, wrong dropdown - fixes
 * itself before Preview is ever pressed.
 */
function imAutoSelect(csv, sourceLabel) {
    const best = imDetect(csv);
    const note = document.getElementById('imDetected');
    if (!best) { note.innerHTML = ''; return; }

    if (best.ambiguous) {
        note.innerHTML = 'These columns fit more than one dataset &mdash; pick the one you meant: '
            + best.options.map(o =>
                `<button class="btn btn-secondary btn-sm" onclick="imSelectDataset('${imEsc(o.key)}')">${imEsc(o.label)}</button>`
              ).join(' ');
        return;
    }

    const current = imCurrent();
    if (current && current.key === best.key) {
        note.innerHTML = '<span class="ok">These columns match the selected dataset (' + imEsc(best.label) + ').</span>';
        return;
    }
    imSelectDataset(best.key);
    note.innerHTML = '<span class="ok">Dataset switched to <strong>' + imEsc(best.label) + '</strong></span> '
        + '&mdash; that is what ' + imEsc(sourceLabel || 'this CSV') + ' looks like. Change it above if that is not what you meant.';
}

function imRenderContract() {
    imLock('Preview first — commit unlocks once a preview succeeds.');
    const d = imCurrent();
    const host = document.getElementById('imContract');
    const target = document.getElementById('imTarget');
    if (!d) { host.textContent = ''; if (target) target.textContent = ''; return; }

    // Say out loud what is about to be written to, above the buttons — the wrong
    // dataset is otherwise invisible until an error mentions a stray column.
    if (target) {
        target.innerHTML = 'Importing into <strong>' + imEsc(d.group) + ' &rsaquo; ' + imEsc(d.label)
            + '</strong> <span class="im-muted">(table <code>' + imEsc(d.table) + '</code>)</span>';
    }

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
        const csv = String(reader.result || '');
        document.getElementById('imCsv').value = csv;
        state.textContent = file.name + ' loaded (' + Math.round(file.size / 1024) + ' KB)';
        imLock('CSV changed — preview again before committing.');
        imAutoSelect(csv, file.name);
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
        // A header mismatch usually means the wrong dataset, not a bad file — so
        // offer the switch rather than just restating the missing column.
        const alts = (r.suggestions || []).map(s =>
            `<button class="btn btn-secondary btn-sm" style="margin-top:6px;" onclick="imSwitchAndPreview('${imEsc(s.key)}')">`
            + `Switch to ${imEsc(s.label)} and preview</button>`).join(' ');
        host.innerHTML = '<span class="err">' + imEsc(r.error || 'Failed') + '</span>'
            + (alts ? '<div style="margin-top:6px;">This file\'s columns look like a different dataset:</div><div>' + alts + '</div>' : '');
        imLock('Fix the problem above, then preview again.');
        return null;
    }
    return r;
}

async function imSwitchAndPreview(key) {
    imSelectDataset(key);
    document.getElementById('imDetected').innerHTML =
        '<span class="ok">Dataset switched to <strong>' + imEsc(imCurrent() ? imCurrent().label : key) + '</strong>.</span>';
    await imPreview();
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
    const csvBox = document.getElementById('imCsv');
    csvBox.addEventListener('input', () => imLock('CSV changed — preview again before committing.'));
    // Detect on paste and on leaving the box, not per keystroke — switching the
    // dataset under someone mid-type would be worse than the problem it solves.
    csvBox.addEventListener('paste', () => setTimeout(() => imAutoSelect(csvBox.value, 'the pasted CSV'), 0));
    csvBox.addEventListener('change', () => imAutoSelect(csvBox.value, 'this CSV'));
});
