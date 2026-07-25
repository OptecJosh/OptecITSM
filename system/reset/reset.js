/*
 * System > Reset data.
 *
 * Groups with live row counts, a preview that lists the exact tables, and a typed
 * confirmation phrase. The delete button is disabled unless all three of these
 * hold: at least one group ticked, a successful preview for that exact selection,
 * and the phrase typed exactly. Changing the selection invalidates the preview,
 * so you can never confirm one thing and delete another.
 */

const RS_API = '../../api/system/';
let rsGroups = [];
let rsPreviewed = null;   // the selection string a successful preview covered

function rsEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function rsNum(n) { return Number(n || 0).toLocaleString(); }

/** The ticked groups, as a stable string so a changed selection is detectable. */
function rsSelection() {
    return Array.from(document.querySelectorAll('.rs-group input:checked')).map(i => i.value);
}
function rsSelectionKey() { return rsSelection().slice().sort().join(','); }

function rsSyncButton() {
    const sel = rsSelection();
    const typed = document.getElementById('rsConfirm').value.trim() === window.RS_PHRASE;
    const fresh = rsPreviewed !== null && rsPreviewed === rsSelectionKey();
    const btn = document.getElementById('rsGo');
    btn.disabled = !(sel.length && typed && fresh);
    btn.textContent = sel.length ? 'Delete the data in ' + sel.length + ' group' + (sel.length === 1 ? '' : 's') : 'Delete the data';
}

async function rsLoad() {
    let d;
    try { d = await (await fetch(RS_API + 'get_reset_groups.php')).json(); }
    catch (e) { d = { success: false, error: 'Network error' }; }

    const state = document.getElementById('rsLoadState');
    if (!d.success) {
        state.innerHTML = '<span style="color:#b91c1c">' + rsEsc(d.error || 'Failed to load') + '</span>';
        return;
    }
    rsGroups = d.groups || [];
    state.textContent = rsNum(d.total) + ' row(s) of data across ' + rsGroups.length + ' groups';

    document.getElementById('rsGroups').innerHTML = rsGroups.map(g => `
        <label class="rs-group">
            <input type="checkbox" value="${rsEsc(g.id)}" onchange="rsInvalidate()">
            <span>
                <span class="nm">${rsEsc(g.label)}</span>
                <span class="ds">${rsEsc(g.description)}</span>
            </span>
            <span class="ct"><strong>${rsNum(g.rows)}</strong> rows</span>
        </label>`).join('');

    rsSyncButton();
}

/** Any change to the selection retires the previous preview. */
function rsInvalidate() {
    rsPreviewed = null;
    rsSyncButton();
}

function rsAll(on) {
    document.querySelectorAll('.rs-group input').forEach(i => { i.checked = on; });
    rsInvalidate();
}

async function rsCall(mode) {
    const groups = rsSelection();
    const host = document.getElementById('rsResult');
    if (!groups.length) { host.innerHTML = '<span class="err">Tick at least one group first.</span>'; return null; }

    host.textContent = mode === 'execute' ? 'Deleting…' : 'Counting…';
    let d;
    try {
        d = await (await fetch(RS_API + 'reset_data.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                mode: mode, groups: groups,
                confirm: document.getElementById('rsConfirm').value.trim(),
            }),
        })).json();
    } catch (e) { d = { success: false, error: 'Network error' }; }

    if (!d.success) {
        host.innerHTML = '<span class="err">' + rsEsc(d.error || 'Failed') + '</span>';
        return null;
    }
    return d;
}

async function rsPreview() {
    const d = await rsCall('preview');
    if (!d) { rsInvalidate(); return; }

    // Show a table's filter where it has one, so "custom_field_values" doesn't
    // read as if the whole table is going.
    const rows = [];
    d.groups.forEach(g => g.tables.forEach(t => rows.push(
        `<tr><td>${rsEsc(g.label)}</td><td><code>${rsEsc(t.table)}</code>`
        + (t.where ? ` <span style="color:var(--text-dim,#9ca3af)">where ${rsEsc(t.where)}</span>` : '')
        + `</td><td class="n">${rsNum(t.rows)}</td></tr>`)));

    document.getElementById('rsResult').innerHTML = `
        <div class="rs-total">This will delete <strong>${rsNum(d.total)}</strong> row(s) from ${d.tables.length} table(s).
            Nothing has been deleted yet.</div>
        <table>
            <thead><tr><th>Group</th><th>Table</th><th class="n">Rows</th></tr></thead>
            <tbody>${rows.join('')}</tbody>
        </table>
        ${d.missing && d.missing.length
            ? '<div style="margin-top:8px;color:var(--text-dim,#6b7280);font-size:12px;">Not present on this install (skipped): '
              + d.missing.map(rsEsc).join(', ') + '</div>'
            : ''}`;

    rsPreviewed = rsSelectionKey();
    rsSyncButton();
}

async function rsExecute() {
    const groups = rsSelection();
    const labels = rsGroups.filter(g => groups.includes(g.id)).map(g => g.label);
    if (!confirm('Permanently delete:\n\n' + labels.join('\n') + '\n\nThis cannot be undone.')) return;

    const d = await rsCall('execute');
    if (!d) return;

    const rows = Object.entries(d.deleted || {})
        .filter(([, n]) => n > 0)
        .map(([t, n]) => `<tr><td><code>${rsEsc(t)}</code></td><td class="n">${rsNum(n)}</td></tr>`).join('');

    document.getElementById('rsResult').innerHTML = `
        <div class="ok">Deleted ${rsNum(d.total)} row(s).</div>
        ${rows ? `<table><thead><tr><th>Table</th><th class="n">Deleted</th></tr></thead><tbody>${rows}</tbody></table>` : ''}
        <div style="margin-top:8px;">Logged to the audit trail. Reload to see the new counts.</div>`;

    document.getElementById('rsConfirm').value = '';
    rsPreviewed = null;
    rsSyncButton();
    rsLoad();   // refresh the counts in place
}

document.addEventListener('DOMContentLoaded', () => {
    rsLoad();
    document.getElementById('rsConfirm').addEventListener('input', rsSyncButton);
});
