/*
 * LMS > Development journal.
 *
 * Reads api/lms/journal.php for one analyst's journal — your own by default, or a
 * direct report's from the picker. Anyone who can see a journal can add to it;
 * only an entry's author can edit or delete it, which the UI reflects and the
 * endpoints enforce.
 */

let jrData = null;
let jrAnalystId = null;

function jrEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function jrLabel(v) { return String(v || '').replace(/_/g, ' '); }
function jrClose() { document.getElementById('jrBack').classList.remove('active'); }

async function jrLoad() {
    const qs = jrAnalystId ? '?analyst_id=' + encodeURIComponent(jrAnalystId) : '';
    let d;
    try { d = await (await fetch(window.API_BASE + 'journal.php' + qs)).json(); }
    catch (e) { d = { success: false, error: 'Network error' }; }

    if (!d.success) {
        document.getElementById('jrSub').innerHTML = '<span style="color:#b91c1c;">' + jrEsc(d.error || 'Failed to load') + '</span>';
        document.getElementById('jrList').innerHTML = '';
        document.getElementById('jrAdd').style.display = 'none';
        return;
    }
    jrData = d;
    jrAnalystId = d.analyst.id;

    document.getElementById('jrTitle').textContent = d.is_own
        ? 'My development journal'
        : d.analyst.full_name + ' — development journal';
    document.getElementById('jrSub').textContent = d.is_own
        ? (d.analyst.manager_name ? 'Shared with your line manager, ' + d.analyst.manager_name : 'No line manager is set, so only you can see this')
        : 'You are their line manager';
    document.getElementById('jrPrivacy').textContent = d.is_own
        ? 'This journal is visible to you and your line manager only. Nobody else — not LMS managers, not administrators — can open it, and it is excluded from data exports.'
        : 'You can see this journal because you are ' + d.analyst.full_name + "'s line manager. Nobody else can open it. Entries you add are visible to them.";

    document.getElementById('jrAdd').style.display = d.can_write ? '' : 'none';
    document.getElementById('jrCategory').innerHTML = (d.categories || []).map(c =>
        `<option value="${c}">${jrEsc(jrLabel(c))}</option>`).join('');

    const wrap = document.getElementById('jrPickWrap');
    if ((d.journals || []).length > 1) {
        document.getElementById('jrPick').innerHTML = d.journals.map(j =>
            `<option value="${j.id}"${j.id === jrAnalystId ? ' selected' : ''}>${jrEsc(j.full_name)}</option>`).join('');
        wrap.style.display = '';
    } else {
        wrap.style.display = 'none';
    }

    jrRender();
}

function jrSwitch() {
    jrAnalystId = parseInt(document.getElementById('jrPick').value, 10);
    jrLoad();
}

function jrRender() {
    const host = document.getElementById('jrList');
    const rows = jrData.entries || [];
    if (!rows.length) {
        host.innerHTML = `<div class="jr-empty">
            <h3>Nothing here yet</h3>
            <p>Record a goal, what you have been working on, feedback from a 1:1, or a reflection on a piece of work.</p>
        </div>`;
        return;
    }
    host.innerHTML = rows.map(e => `
        <div class="jr-entry">
            <div class="jr-entry-head">
                <h3>${jrEsc(e.title)}</h3>
                <span class="jr-cat ${jrEsc(e.category)}">${jrEsc(jrLabel(e.category))}</span>
                <div class="spacer"></div>
                ${e.is_mine ? `
                    <button class="btn btn-secondary btn-sm" onclick="jrModal(${e.id})">Edit</button>
                    <button class="btn btn-secondary btn-sm" onclick="jrDelete(${e.id})">Delete</button>` : ''}
            </div>
            <div class="jr-meta">${jrEsc(e.entry_date)} &middot; ${jrEsc(e.author_name || 'Unknown author')}${e.is_mine ? ' (you)' : ''}</div>
            ${e.body ? `<div class="jr-body">${jrEsc(e.body)}</div>` : ''}
        </div>`).join('');
}

function jrModal(id) {
    const e = id ? (jrData.entries || []).find(x => Number(x.id) === Number(id)) : null;
    document.getElementById('jrModalTitle').textContent = e ? 'Edit entry' : 'New entry';
    document.getElementById('jrId').value = e ? e.id : '';
    document.getElementById('jrDate').value = e ? e.entry_date : new Date().toISOString().slice(0, 10);
    document.getElementById('jrCategory').value = e ? e.category : 'progress';
    document.getElementById('jrEntryTitle').value = e ? e.title : '';
    document.getElementById('jrBody').value = e && e.body ? e.body : '';
    document.getElementById('jrErr').textContent = '';
    document.getElementById('jrBack').classList.add('active');
}

async function jrSave() {
    const payload = {
        id: document.getElementById('jrId').value || null,
        analyst_id: jrAnalystId,
        entry_date: document.getElementById('jrDate').value,
        category: document.getElementById('jrCategory').value,
        title: document.getElementById('jrEntryTitle').value.trim(),
        body: document.getElementById('jrBody').value,
    };
    if (!payload.title) { document.getElementById('jrErr').textContent = 'A title is required.'; return; }

    let d;
    try {
        d = await (await fetch(window.API_BASE + 'save_journal_entry.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        })).json();
    } catch (e) { d = { success: false, error: 'Network error' }; }

    if (!d.success) { document.getElementById('jrErr').textContent = d.error || 'Save failed'; return; }
    jrClose();
    jrLoad();
}

async function jrDelete(id) {
    if (!confirm('Delete this journal entry?')) return;
    let d;
    try {
        d = await (await fetch(window.API_BASE + 'delete_journal_entry.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: id }),
        })).json();
    } catch (e) { d = { success: false, error: 'Network error' }; }
    if (!d.success) { alert(d.error || 'Delete failed'); return; }
    jrLoad();
}

document.addEventListener('DOMContentLoaded', jrLoad);
