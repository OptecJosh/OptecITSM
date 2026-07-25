/*
 * System > Migrate from another system.
 *
 * Holds the uploaded CSV in the page and re-posts it for each step, so analyse,
 * preview and commit all run against exactly the same bytes — the server keeps no
 * partial state between steps, and a stale server-side copy can never be
 * committed by mistake.
 *
 * The operator's two decisions live in:
 *   mgMapping   source column -> our field ('' = ignore)
 *   mgValueMap  source column -> { source value -> replacement }
 * plus mgCreate, the list of lookup values to create on commit.
 */

const MG_API = '../../api/system/migrate.php';

let mgTargets = [];
let mgSources = [];
let mgCsv = '';
let mgAnalysis = null;
let mgMapping = {};
let mgValueMap = {};
let mgCreate = [];
let mgBackfillReport = null;

function mgEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function mgShow(id, on) {
    document.getElementById(id).classList.toggle('mg-hidden', !on);
}

function mgError(id, msg) {
    const el = document.getElementById(id);
    if (!msg) { el.classList.add('mg-hidden'); el.textContent = ''; return; }
    el.textContent = msg;
    el.classList.remove('mg-hidden');
}

async function mgPost(payload) {
    const r = await fetch(MG_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    return await r.json();
}

/* ---- step 1: targets and file ---------------------------------------- */

async function mgLoadTargets() {
    let data;
    try { data = await mgPost({ mode: 'targets' }); }
    catch (e) { mgError('mgErr1', 'Could not load the target list.'); return; }
    if (!data.success) { mgError('mgErr1', data.error); return; }

    mgTargets = data.targets || [];
    mgSources = data.sources || [];
    mgBackfillReport = data.backfill || null;
    document.getElementById('mgDataset').innerHTML =
        mgTargets.map(t => `<option value="${mgEsc(t.key)}">${mgEsc(t.label)}</option>`).join('');
    document.getElementById('mgSource').innerHTML =
        '<option value="">Another system (map columns myself)</option>'
        + mgSources.map(s => `<option value="${mgEsc(s.key)}">${mgEsc(s.label)}</option>`).join('');
    mgRenderDatasetHint();
}

function mgCurrentTarget() {
    const k = document.getElementById('mgDataset').value;
    return mgTargets.find(t => t.key === k) || null;
}

function mgCurrentSource() {
    const k = document.getElementById('mgSource').value;
    return mgSources.find(s => s.key === k) || null;
}

/* A preset knows which dataset it loads into, so pin it rather than let the two
   selects disagree and fail on the server. */
function mgSyncDatasetToSource() {
    const s = mgCurrentSource();
    const ds = document.getElementById('mgDataset');
    if (s && mgTargets.some(t => t.key === s.dataset)) {
        ds.value = s.dataset;
        ds.disabled = true;
    } else {
        ds.disabled = false;
    }
}

function mgRenderDatasetHint() {
    mgSyncDatasetToSource();
    const t = mgCurrentTarget();
    const s = mgCurrentSource();
    const el = document.getElementById('mgDatasetHint');
    if (!t) { el.textContent = ''; return; }
    let html = '';
    if (s && s.notes) html += '<div class="mg-warn" style="margin-bottom:10px">' + mgEsc(s.notes) + '</div>';
    html += mgEsc(t.hint)
        + (t.required.length ? '<br>Required: <strong>' + mgEsc(t.required.join(', ')) + '</strong>' : '')
        + '<br>Fields available: ' + mgEsc(t.columns.join(', '));
    el.innerHTML = html;
}

/* Changing the target invalidates every downstream decision. */
function mgReset() {
    mgAnalysis = null;
    mgMapping = {};
    mgValueMap = {};
    mgCreate = [];
    ['mgCardMap', 'mgCardValues', 'mgCardRun', 'mgCardBackfill'].forEach(id => mgShow(id, false));
    document.getElementById('mgResult').innerHTML = '';
    mgError('mgErr1', '');
    mgError('mgErr2', '');
    mgRenderDatasetHint();
    if (mgCsv) mgAnalyseCsv();
}

function mgAnalyse() {
    const f = document.getElementById('mgFile').files[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = () => { mgCsv = String(reader.result || ''); mgAnalyseCsv(); };
    reader.onerror = () => mgError('mgErr1', 'Could not read that file.');
    reader.readAsText(f);
}

async function mgAnalyseCsv() {
    mgError('mgErr1', '');
    const t = mgCurrentTarget();
    if (!t || !mgCsv) return;

    let data;
    const src = mgCurrentSource();
    try { data = await mgPost({ mode: 'analyse', dataset: t.key, csv: mgCsv, source: src ? src.key : '' }); }
    catch (e) { mgError('mgErr1', 'Request failed: ' + e.message); return; }
    if (!data.success) { mgError('mgErr1', data.error); return; }

    mgAnalysis = data;
    mgMapping = Object.assign({}, data.mapping);
    // A preset ships value translations too (its statuses and priorities onto
    // ours); adopt them so the operator reviews decisions rather than makes
    // fifteen of them from scratch.
    mgValueMap = (data.value_map && typeof data.value_map === 'object') ? data.value_map : {};
    mgCreate = [];

    mgRenderMap();
    mgRenderValues();
    mgShow('mgCardMap', true);
    mgShow('mgCardRun', true);
    mgShow('mgCardBackfill', true);
    mgRenderBackfill();
    document.getElementById('mgCommitBtn').disabled = true;
    document.getElementById('mgResult').innerHTML = '';
}

/* ---- step 2: column mapping ------------------------------------------ */

function mgConfClass(c) {
    if (c >= 90) return 'mg-conf-hi';
    if (c >= 70) return 'mg-conf-mid';
    return 'mg-conf-lo';
}

function mgRenderMap() {
    const a = mgAnalysis;
    const t = mgCurrentTarget();
    const opts = ['<option value="">— ignore —</option>']
        .concat(t.columns.map(c => `<option value="${mgEsc(c)}">${mgEsc(c)}</option>`)).join('');

    let html = '<thead><tr><th>Their column</th><th>Sample value</th><th>Our field</th><th>Match</th></tr></thead><tbody>';
    a.source_columns.forEach((src, i) => {
        const d = a.detail[src];
        const sample = (a.sample || []).map(r => r[i]).find(v => v != null && String(v).trim() !== '');
        const chosen = mgMapping[src] || '';
        html += '<tr>'
            + '<td><strong>' + mgEsc(src) + '</strong></td>'
            + '<td style="color:var(--text-dim,#6b7280)">' + mgEsc(sample == null ? '' : String(sample).slice(0, 40)) + '</td>'
            + '<td><select data-src="' + mgEsc(src) + '" onchange="mgSetMapping(this)">' + opts + '</select></td>'
            + '<td>' + (d ? '<span class="mg-conf ' + mgConfClass(d.confidence) + '">' + d.confidence + '%</span> '
                + '<span style="font-size:11px;color:var(--text-dim,#6b7280)">' + mgEsc(d.reason) + '</span>'
                : '<span style="font-size:11px;color:var(--text-dim,#6b7280)">no match suggested</span>')
            + '</td></tr>';
    });
    html += '</tbody>';
    const table = document.getElementById('mgMapTable');
    table.innerHTML = html;
    // Set the selects after insertion so the values stick.
    table.querySelectorAll('select[data-src]').forEach(sel => {
        sel.value = mgMapping[sel.dataset.src] || '';
    });

    const notes = [];
    notes.push(a.row_count.toLocaleString() + ' data row(s) read.');
    if (a.truncated) notes.push('<span class="mg-new">' + a.truncated.toLocaleString() + ' row(s) beyond the cap were not read.</span>');
    const missing = (a.required || []).filter(r => !Object.values(mgMapping).includes(r));
    if (missing.length) notes.push('<span class="mg-new">Still unmapped and required: ' + mgEsc(missing.join(', ')) + '</span>');
    if (a.conflicts && a.conflicts.length) {
        notes.push('Also considered: ' + a.conflicts.map(c => mgEsc(c.source) + ' → ' + mgEsc(c.target)).join('; '));
    }
    document.getElementById('mgMapNote').innerHTML = notes.join('<br>');
}

function mgSetMapping(sel) {
    const src = sel.dataset.src;
    const target = sel.value;

    // A field can only be fed by one column; taking it releases the previous one.
    if (target) {
        Object.keys(mgMapping).forEach(k => {
            if (k !== src && mgMapping[k] === target) delete mgMapping[k];
        });
    }
    if (target) mgMapping[src] = target; else delete mgMapping[src];

    // The value decisions belong to a mapping that no longer exists.
    mgValueMap = {};
    mgCreate = [];
    mgRenderMap();
    mgRefreshValues();
    document.getElementById('mgCommitBtn').disabled = true;
}

/* Re-ask the server which values resolve, since the mapping changed. */
async function mgRefreshValues() {
    const t = mgCurrentTarget();
    if (!t || !mgCsv) return;
    try {
        const src = mgCurrentSource();
        const data = await mgPost({ mode: 'preview', dataset: t.key, csv: mgCsv, source: src ? src.key : '', mapping: mgMapping, value_map: mgValueMap });
        if (data.success) { mgAnalysis.values = data.values; mgRenderValues(); }
    } catch (e) { /* the preview button will surface any real problem */ }
}

/* ---- step 3: value mapping ------------------------------------------- */

function mgRenderValues() {
    const vals = (mgAnalysis && mgAnalysis.values) || {};
    const keys = Object.keys(vals);
    mgShow('mgCardValues', keys.length > 0);
    if (!keys.length) return;

    let html = '';
    keys.forEach(src => {
        const v = vals[src];
        const unresolved = v.values.filter(x => !x.resolves).length;
        html += '<div style="margin-bottom:18px">'
            + '<div style="font-size:13px;font-weight:600;margin-bottom:2px">' + mgEsc(src)
            + ' <span class="mg-pill">→ ' + mgEsc(v.target) + '</span>'
            + (v.required ? ' <span class="mg-pill">required</span>' : '')
            + (unresolved ? ' <span class="mg-new">' + unresolved + ' unrecognised</span>'
                          : ' <span class="mg-ok">all recognised</span>')
            + '</div>'
            + '<div style="font-size:11px;color:var(--text-dim,#6b7280);margin-bottom:6px">'
            + 'Resolved against <code>' + mgEsc(v.table) + '</code>.'
            + (v.creatable ? ' New values can be created.'
                           : ' <strong>New values cannot be created here</strong> — map each one onto an existing record.')
            + (v.blank_rows ? ' ' + v.blank_rows.toLocaleString() + ' row(s) leave this blank.' : '')
            + '</div>'
            + '<table class="mg-table"><thead><tr><th>Their value</th><th>Rows</th><th>Status</th>'
            + '<th>Use instead (leave blank to keep)</th>' + (v.creatable ? '<th>Create</th>' : '') + '</tr></thead><tbody>';

        v.values.forEach(x => {
            const id = 'v_' + btoa(unescape(encodeURIComponent(src + '||' + x.value))).replace(/[^A-Za-z0-9]/g, '');
            const isCreating = mgCreate.some(c => c.table === v.table && c.value === x.value);
            html += '<tr>'
                + '<td><strong>' + mgEsc(x.value) + '</strong></td>'
                + '<td>' + x.rows.toLocaleString() + '</td>'
                + '<td>' + (x.resolves ? '<span class="mg-ok">recognised</span>' : '<span class="mg-new">not recognised</span>') + '</td>'
                + '<td><input type="text" id="' + id + '" value="' + mgEsc((mgValueMap[src] || {})[x.value] || '')
                + '" placeholder="' + (x.resolves ? '' : 'e.g. an existing name') + '"'
                + ' oninput="mgSetValue(this,' + JSON.stringify(src).replace(/"/g, '&quot;') + ','
                + JSON.stringify(x.value).replace(/"/g, '&quot;') + ')"></td>'
                + (v.creatable
                    ? '<td><input type="checkbox" ' + (isCreating ? 'checked' : '') + (x.resolves ? ' disabled' : '')
                      + ' onchange="mgSetCreate(this,' + JSON.stringify(v.table).replace(/"/g, '&quot;') + ','
                      + JSON.stringify(x.value).replace(/"/g, '&quot;') + ')"></td>'
                    : '')
                + '</tr>';
        });
        html += '</tbody></table></div>';
    });
    document.getElementById('mgValues').innerHTML = html;
}

function mgSetValue(input, src, value) {
    const v = input.value.trim();
    if (!mgValueMap[src]) mgValueMap[src] = {};
    if (v === '') delete mgValueMap[src][value];
    else mgValueMap[src][value] = v;
    if (Object.keys(mgValueMap[src]).length === 0) delete mgValueMap[src];
    document.getElementById('mgCommitBtn').disabled = true;
}

function mgSetCreate(cb, table, value) {
    mgCreate = mgCreate.filter(c => !(c.table === table && c.value === value));
    if (cb.checked) mgCreate.push({ table: table, value: value });
    document.getElementById('mgCommitBtn').disabled = true;
}

/* ---- step 4: preview / commit ---------------------------------------- */

async function mgRun(mode) {
    const t = mgCurrentTarget();
    if (!t || !mgCsv) return;
    mgError('mgErr2', '');

    if (mode === 'commit') {
        let msg = 'This will write to the ' + t.label + ' table.';
        if (mgCreate.length) msg += ' ' + mgCreate.length + ' new lookup value(s) will be created.';
        msg += ' Take a backup first if you have not already (System › Backup & data).';
        if (!(await showConfirm({ title: 'Commit the migration?', message: msg, okLabel: 'Commit' }))) return;
    }

    const btn = document.getElementById(mode === 'commit' ? 'mgCommitBtn' : 'mgPreviewBtn');
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = mode === 'commit' ? 'Committing…' : 'Checking…';

    let data;
    try {
        const src = mgCurrentSource();
        data = await mgPost({
            mode: mode, dataset: t.key, csv: mgCsv,
            source: src ? src.key : '',
            mapping: mgMapping, value_map: mgValueMap,
            create_values: mode === 'commit' ? mgCreate : []
        });
    } catch (e) {
        mgError('mgErr2', 'Request failed: ' + e.message);
        btn.disabled = false; btn.textContent = orig;
        return;
    }

    btn.textContent = orig;
    btn.disabled = false;

    if (!data.success) {
        mgError('mgErr2', data.error);
        document.getElementById('mgCommitBtn').disabled = true;
        return;
    }

    mgRenderResult(data, mode);
    if (mode === 'preview') {
        // Only allow a commit once a preview has actually planned something.
        document.getElementById('mgCommitBtn').disabled = (data.reconcile.rows_planned || 0) === 0;
    } else {
        document.getElementById('mgCommitBtn').disabled = true;
        if (data.values) { mgAnalysis.values = data.values; }
    }
}

function mgRenderResult(data, mode) {
    const r = data.reconcile;
    const isCommit = mode === 'commit';

    let html = '<div class="mg-rec ' + (r.balanced ? 'mg-balanced' : 'mg-unbalanced') + '">'
        + mgTile('Source rows', r.source_rows)
        + (isCommit ? mgTile('Created', r.created) + mgTile('Updated', r.updated)
                    : mgTile('Will create', r.to_create) + mgTile('Will update', r.to_update))
        + mgTile('Row errors', r.rows_with_errors)
        + (r.truncated_rows ? mgTile('Not read (cap)', r.truncated_rows) : '')
        + '</div>';

    html += '<div class="mg-note">'
        + (r.balanced
            ? '<span class="mg-ok">Reconciled — every source row is accounted for.</span>'
            : '<span class="mg-new">' + Math.abs(r.unaccounted) + ' row(s) unaccounted for. Do not sign this off until that is explained.</span>')
        + '</div>';

    if (r.ignored_columns && r.ignored_columns.length) {
        html += '<div class="mg-note">Ignored columns (not mapped to any field): '
            + mgEsc(r.ignored_columns.join(', ')) + '</div>';
    }

    if (data.created_values && data.created_values.created) {
        html += '<div class="mg-note">Created ' + data.created_values.created + ' new lookup value(s).</div>';
    }
    if (data.created_values && (data.created_values.refused || []).length) {
        html += '<div class="mg-note mg-new">Refused to create: '
            + data.created_values.refused.map(x => mgEsc(x.value) + ' in ' + mgEsc(x.table) + ' (' + mgEsc(x.reason) + ')').join('; ')
            + '</div>';
    }

    if (data.derived && !data.derived.error) {
        const d = data.derived;
        html += '<div class="mg-note"><strong>Carried across from the source’s own figures:</strong> '
            + (d.sla || 0).toLocaleString() + ' SLA outcome(s), '
            + (d.reopen_audit || 0).toLocaleString() + ' reopen(s), '
            + (d.reassign_audit || 0).toLocaleString() + ' reassignment(s), '
            + (d.escalations || 0).toLocaleString() + ' escalation(s), '
            + (d.time_entries || 0).toLocaleString() + ' time entr(ies).'
            + (d.skipped ? ' ' + d.skipped.toLocaleString() + ' row(s) had no matching ticket.' : '')
            + '</div>';
    } else if (data.derived && data.derived.error) {
        html += '<div class="mg-note mg-new">Could not carry across the source’s measured figures: '
            + mgEsc(data.derived.error) + '</div>';
    }

    if (data.backfill && data.backfill.note) {
        html += '<div class="mg-note">' + mgEsc(data.backfill.note) + '</div>';
    } else if (data.backfill) {
        const b = data.backfill;
        html += '<div class="mg-note">SLA outcomes computed for ' + (b.processed || 0).toLocaleString()
            + ' ticket(s); ' + (b.tracked || 0).toLocaleString() + ' have an SLA that applies.'
            + ((b.errors || []).length ? ' <span class="mg-new">' + mgEsc(b.errors.slice(0, 3).join('; ')) + '</span>' : '')
            + '</div>';
    }

    if (data.error_total) {
        html += '<div class="mg-note"><strong>' + data.error_total.toLocaleString() + ' row problem(s)</strong>'
            + (data.error_total > (data.errors || []).length ? ' (first ' + data.errors.length + ' shown)' : '') + ':</div>'
            + '<div class="mg-scroll"><table class="mg-table"><thead><tr><th>Row</th><th>Problem</th></tr></thead><tbody>'
            + (data.errors || []).map(e => '<tr><td>' + e.row + '</td><td>' + mgEsc(e.message) + '</td></tr>').join('')
            + '</tbody></table></div>';
    }

    if (!isCommit && (data.first_rows || []).length) {
        const cols = Object.keys(data.first_rows[0]);
        html += '<div class="mg-note">First rows as they will be written:</div>'
            + '<div class="mg-scroll"><table class="mg-table"><thead><tr>'
            + cols.map(c => '<th>' + mgEsc(c) + '</th>').join('') + '</tr></thead><tbody>'
            + data.first_rows.map(row => '<tr>' + cols.map(c => '<td>' + mgEsc(row[c]) + '</td>').join('') + '</tr>').join('')
            + '</tbody></table></div>';
    }

    if (isCommit && r.balanced) {
        html += '<div class="mg-note"><span class="mg-ok">Done.</span> Next: migrate the remaining datasets, '
            + 'then check <a href="../../reporting/export/">Reporting › Export</a> and the KPI scorecard. '
            + 'Run <code>cron/kpi_snapshot.php</code> to compute monthly KPI values over the migrated history.</div>';
    }

    document.getElementById('mgResult').innerHTML = html;
}

function mgTile(label, n) {
    return '<div><span>' + mgEsc(label) + '</span><strong>' + Number(n || 0).toLocaleString() + '</strong></div>';
}

/* ---- what cannot be backfilled --------------------------------------- */

function mgRenderBackfill() {
    if (!mgBackfillReport) return;
    const b = mgBackfillReport;
    let html = '<div class="mg-note"><strong>Derived from what you import:</strong><ul style="margin:6px 0 0 18px">'
        + (b.derived || []).map(x => '<li>' + mgEsc(x) + '</li>').join('') + '</ul></div>';
    html += '<div class="mg-note"><strong>Cannot be recovered from a ticket export</strong> — left empty rather than '
        + 'invented, because a fabricated history would be indistinguishable from a real one a year from now:'
        + '<ul style="margin:6px 0 0 18px">'
        + Object.keys(b.not_filled || {}).map(k => '<li><code>' + mgEsc(k) + '</code> — ' + mgEsc(b.not_filled[k]) + '</li>').join('')
        + '</ul></div>';
    if (b.advice) html += '<div class="mg-warn" style="margin-top:12px">' + mgEsc(b.advice) + '</div>';
    document.getElementById('mgBackfill').innerHTML = html;
}

document.addEventListener('DOMContentLoaded', mgLoadTargets);
