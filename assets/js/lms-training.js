/*
 * LMS > Training & certifications.
 *
 * Renders one analyst's development record from api/lms/development.php:
 * certifications (with expiry warnings), external training, and their LMS course
 * completions. Editing is only offered when the API says can_edit, and the
 * catalogue card only when can_manage — the endpoints enforce both again.
 */

let trData = null;
let trAnalystId = null;

function trEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function trClose(id) { document.getElementById(id).classList.remove('active'); }
function trOpen(id) { document.getElementById(id).classList.add('active'); }
function trLabel(v) { return String(v || '').replace(/_/g, ' '); }
function trDate(d) { return d ? trEsc(d) : '<span class="tr-muted">—</span>'; }

async function trPost(endpoint, payload) {
    try {
        const r = await fetch(window.API_BASE + endpoint, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        return await r.json();
    } catch (e) {
        return { success: false, error: 'Network error' };
    }
}

async function trLoad() {
    const qs = trAnalystId ? '?analyst_id=' + encodeURIComponent(trAnalystId) : '';
    let d;
    try { d = await (await fetch(window.API_BASE + 'development.php' + qs)).json(); }
    catch (e) { d = { success: false, error: 'Network error' }; }

    if (!d.success) {
        document.getElementById('trSub').innerHTML = '<span class="tr-bad">' + trEsc(d.error || 'Failed to load') + '</span>';
        return;
    }
    trData = d;
    trAnalystId = d.analyst.id;

    document.getElementById('trTitle').textContent = d.is_own
        ? 'My training & certifications'
        : d.analyst.full_name + ' — training & certifications';
    const bits = [];
    if (d.analyst.tier) bits.push('Tier ' + d.analyst.tier);
    if (d.analyst.manager_name) bits.push('Line manager: ' + d.analyst.manager_name);
    bits.push(d.can_edit ? 'You can add and edit records here' : 'Read-only');
    document.getElementById('trSub').textContent = bits.join(' · ');

    // Person picker — only worth showing when there is someone else to look at.
    const wrap = document.getElementById('trPersonWrap');
    if ((d.roster || []).length > 1) {
        document.getElementById('trPerson').innerHTML = d.roster.map(r =>
            `<option value="${r.id}"${r.id === trAnalystId ? ' selected' : ''}>${trEsc(r.full_name)}${r.is_self ? ' (me)' : ''}</option>`).join('');
        wrap.style.display = '';
    } else {
        wrap.style.display = 'none';
    }

    document.getElementById('trCertAdd').style.display = d.can_edit ? '' : 'none';
    document.getElementById('trTrainAdd').style.display = d.can_edit ? '' : 'none';
    document.getElementById('trCatalogueCard').style.display = d.can_manage ? '' : 'none';

    document.getElementById('trCertStatus').innerHTML = (d.statuses || []).map(s =>
        `<option value="${s}">${trEsc(trLabel(s))}</option>`).join('');
    document.getElementById('trTrainType').innerHTML = (d.types || []).map(s =>
        `<option value="${s}">${trEsc(trLabel(s))}</option>`).join('');

    trRenderCerts();
    trRenderTraining();
    trRenderCourses();
    if (d.can_manage) trRenderCatalogue();
}

function trSwitchPerson() {
    trAnalystId = parseInt(document.getElementById('trPerson').value, 10);
    trLoad();
}

// ---- certifications ----
function trExpiryCell(c) {
    if (!c.expires_date) return '<span class="tr-muted">No expiry</span>';
    const days = c.days_to_expiry === null ? null : parseInt(c.days_to_expiry, 10);
    if (days === null || isNaN(days)) return trEsc(c.expires_date);
    if (days < 0) return `<span class="tr-bad">${trEsc(c.expires_date)} · expired</span>`;
    if (days <= 90) return `<span class="tr-warn">${trEsc(c.expires_date)} · ${days}d left</span>`;
    return trEsc(c.expires_date);
}

function trRenderCerts() {
    const host = document.getElementById('trCerts');
    const rows = trData.certifications || [];
    document.getElementById('trCertCount').textContent = rows.length ? rows.length + ' recorded' : '';
    if (!rows.length) {
        host.innerHTML = '<div class="tr-empty">No certifications recorded yet.</div>';
        return;
    }
    host.innerHTML = `<table class="tr">
        <thead><tr><th>Certification</th><th>Status</th><th>Awarded</th><th>Expires</th><th>Credential</th><th></th></tr></thead>
        <tbody>${rows.map(c => `
            <tr>
                <td><strong>${trEsc(c.title)}</strong>${c.issuer ? `<div class="tr-muted">${trEsc(c.issuer)}</div>` : ''}
                    ${c.notes ? `<div class="tr-muted">${trEsc(c.notes)}</div>` : ''}</td>
                <td><span class="tr-pill ${trEsc(c.status)}">${trEsc(trLabel(c.status))}</span></td>
                <td>${trDate(c.awarded_date)}</td>
                <td>${trExpiryCell(c)}</td>
                <td>${c.credential_id ? trEsc(c.credential_id) : '<span class="tr-muted">—</span>'}
                    ${c.evidence_url ? `<div><a href="${trEsc(c.evidence_url)}" target="_blank" rel="noopener">Evidence</a></div>` : ''}</td>
                <td class="tr-actions" style="text-align:right;white-space:nowrap;">${trData.can_edit ? `
                    <button class="btn btn-secondary btn-sm" onclick="trCertModal(${c.id})">Edit</button>
                    <button class="btn btn-secondary btn-sm" onclick="trDelete('certification', ${c.id})">Delete</button>` : ''}</td>
            </tr>`).join('')}</tbody></table>`;
}

function trCertModal(id) {
    const c = id ? (trData.certifications || []).find(x => Number(x.id) === Number(id)) : null;
    document.getElementById('trCertTitle').textContent = c ? 'Edit certification' : 'Add certification';
    document.getElementById('trCertId').value = c ? c.id : '';
    document.getElementById('trCertCatalogue').innerHTML = '<option value="">— Free text —</option>' +
        (trData.catalogue || []).map(o => `<option value="${o.id}"${c && Number(c.certification_id) === Number(o.id) ? ' selected' : ''}>${trEsc(o.name)}</option>`).join('');
    document.getElementById('trCertName').value = c ? (c.title || '') : '';
    document.getElementById('trCertIssuer').value = c ? (c.issuer || '') : '';
    document.getElementById('trCertStatus').value = c ? c.status : 'achieved';
    document.getElementById('trCertAwarded').value = c ? (c.awarded_date || '') : '';
    document.getElementById('trCertExpires').value = c ? (c.expires_date || '') : '';
    document.getElementById('trCertCredential').value = c ? (c.credential_id || '') : '';
    document.getElementById('trCertEvidence').value = c ? (c.evidence_url || '') : '';
    document.getElementById('trCertNotes').value = c ? (c.notes || '') : '';
    document.getElementById('trCertErr').textContent = '';
    trOpen('trCertBack');
}

// Picking a catalogue entry fills in the name, issuer and (from validity_months
// and the awarded date) a suggested expiry — the server derives it too, but
// showing it means no surprises on save.
function trCertPickCatalogue() {
    const id = document.getElementById('trCertCatalogue').value;
    if (!id) return;
    const o = (trData.catalogue || []).find(x => Number(x.id) === Number(id));
    if (!o) return;
    document.getElementById('trCertName').value = o.name || '';
    if (o.issuer) document.getElementById('trCertIssuer').value = o.issuer;
    const awarded = document.getElementById('trCertAwarded').value;
    if (o.validity_months && awarded) {
        const d = new Date(awarded + 'T00:00:00');
        d.setMonth(d.getMonth() + parseInt(o.validity_months, 10));
        document.getElementById('trCertExpires').value = d.toISOString().slice(0, 10);
    }
}

async function trCertSave() {
    const payload = {
        id: document.getElementById('trCertId').value || null,
        analyst_id: trAnalystId,
        certification_id: document.getElementById('trCertCatalogue').value || null,
        title: document.getElementById('trCertName').value.trim(),
        issuer: document.getElementById('trCertIssuer').value.trim(),
        status: document.getElementById('trCertStatus').value,
        awarded_date: document.getElementById('trCertAwarded').value,
        expires_date: document.getElementById('trCertExpires').value,
        credential_id: document.getElementById('trCertCredential').value.trim(),
        evidence_url: document.getElementById('trCertEvidence').value.trim(),
        notes: document.getElementById('trCertNotes').value.trim(),
    };
    if (!payload.title) { document.getElementById('trCertErr').textContent = 'A certification name is required.'; return; }
    const d = await trPost('save_certification.php', payload);
    if (!d.success) { document.getElementById('trCertErr').textContent = d.error || 'Save failed'; return; }
    trClose('trCertBack');
    trLoad();
}

// ---- training records ----
function trRenderTraining() {
    const host = document.getElementById('trTraining');
    const rows = trData.training || [];
    const hours = rows.reduce((sum, r) => sum + (parseFloat(r.hours) || 0), 0);
    document.getElementById('trTrainCount').textContent =
        rows.length ? rows.length + ' item' + (rows.length === 1 ? '' : 's') + (hours ? ' · ' + hours + ' hours' : '') : '';
    if (!rows.length) {
        host.innerHTML = '<div class="tr-empty">No training recorded yet. Use this for anything completed outside the LMS — vendor courses, conferences, exams, on-the-job.</div>';
        return;
    }
    host.innerHTML = `<table class="tr">
        <thead><tr><th>Title</th><th>Type</th><th>Provider</th><th>Completed</th><th>Hours</th><th></th></tr></thead>
        <tbody>${rows.map(r => `
            <tr>
                <td><strong>${trEsc(r.title)}</strong>${r.notes ? `<div class="tr-muted">${trEsc(r.notes)}</div>` : ''}
                    ${r.evidence_url ? `<div><a href="${trEsc(r.evidence_url)}" target="_blank" rel="noopener">Evidence</a></div>` : ''}</td>
                <td>${trEsc(trLabel(r.training_type))}</td>
                <td>${r.provider ? trEsc(r.provider) : '<span class="tr-muted">—</span>'}</td>
                <td>${trDate(r.completed_date)}</td>
                <td>${r.hours ? trEsc(r.hours) : '<span class="tr-muted">—</span>'}</td>
                <td class="tr-actions" style="text-align:right;white-space:nowrap;">${trData.can_edit ? `
                    <button class="btn btn-secondary btn-sm" onclick="trTrainModal(${r.id})">Edit</button>
                    <button class="btn btn-secondary btn-sm" onclick="trDelete('training', ${r.id})">Delete</button>` : ''}</td>
            </tr>`).join('')}</tbody></table>`;
}

function trTrainModal(id) {
    const r = id ? (trData.training || []).find(x => Number(x.id) === Number(id)) : null;
    document.getElementById('trTrainTitle').textContent = r ? 'Edit training' : 'Add training';
    document.getElementById('trTrainId').value = r ? r.id : '';
    document.getElementById('trTrainName').value = r ? (r.title || '') : '';
    document.getElementById('trTrainProvider').value = r ? (r.provider || '') : '';
    document.getElementById('trTrainType').value = r ? r.training_type : 'course';
    document.getElementById('trTrainDate').value = r ? (r.completed_date || '') : '';
    document.getElementById('trTrainHours').value = r && r.hours !== null ? r.hours : '';
    document.getElementById('trTrainCost').value = r && r.cost !== null ? r.cost : '';
    document.getElementById('trTrainEvidence').value = r ? (r.evidence_url || '') : '';
    document.getElementById('trTrainNotes').value = r ? (r.notes || '') : '';
    document.getElementById('trTrainErr').textContent = '';
    trOpen('trTrainBack');
}

async function trTrainSave() {
    const payload = {
        id: document.getElementById('trTrainId').value || null,
        analyst_id: trAnalystId,
        title: document.getElementById('trTrainName').value.trim(),
        provider: document.getElementById('trTrainProvider').value.trim(),
        training_type: document.getElementById('trTrainType').value,
        completed_date: document.getElementById('trTrainDate').value,
        hours: document.getElementById('trTrainHours').value,
        cost: document.getElementById('trTrainCost').value,
        evidence_url: document.getElementById('trTrainEvidence').value.trim(),
        notes: document.getElementById('trTrainNotes').value.trim(),
    };
    if (!payload.title) { document.getElementById('trTrainErr').textContent = 'A title is required.'; return; }
    const d = await trPost('save_training.php', payload);
    if (!d.success) { document.getElementById('trTrainErr').textContent = d.error || 'Save failed'; return; }
    trClose('trTrainBack');
    trLoad();
}

async function trDelete(type, id) {
    if (!confirm('Delete this ' + (type === 'certification' ? 'certification' : 'training record') + '?')) return;
    const d = await trPost('delete_development_item.php', { type: type, id: id });
    if (!d.success) { alert(d.error || 'Delete failed'); return; }
    trLoad();
}

// ---- LMS completions (read-only) ----
function trRenderCourses() {
    const host = document.getElementById('trCourses');
    const rows = trData.completions || [];
    const done = rows.filter(r => ['completed', 'passed'].includes(String(r.status))).length;
    document.getElementById('trCourseCount').textContent =
        rows.length ? done + ' of ' + rows.length + ' complete' : '';
    if (!rows.length) {
        host.innerHTML = '<div class="tr-empty">No LMS course activity yet.</div>';
        return;
    }
    host.innerHTML = `<table class="tr">
        <thead><tr><th>Course</th><th>Status</th><th>Score</th><th>Completed</th></tr></thead>
        <tbody>${rows.map(r => `
            <tr>
                <td>${trEsc(r.title)}</td>
                <td>${trEsc(trLabel(r.status))}</td>
                <td>${r.score_raw !== null ? trEsc(r.score_raw) + (r.score_max ? ' / ' + trEsc(r.score_max) : '') : '<span class="tr-muted">—</span>'}</td>
                <td>${r.completion_datetime ? trEsc(String(r.completion_datetime).slice(0, 10)) : '<span class="tr-muted">—</span>'}</td>
            </tr>`).join('')}</tbody></table>`;
}

// ---- catalogue (LMS managers) ----
function trRenderCatalogue() {
    const host = document.getElementById('trCatalogue');
    const rows = trData.catalogue || [];
    if (!rows.length) {
        host.innerHTML = '<div class="tr-empty">Nothing in the catalogue yet. Add the certifications your team is expected to hold, with how long each stays valid.</div>';
        return;
    }
    host.innerHTML = `<table class="tr">
        <thead><tr><th>Name</th><th>Issuer</th><th>Valid for</th><th></th></tr></thead>
        <tbody>${rows.map(o => `
            <tr>
                <td><strong>${trEsc(o.name)}</strong>${o.description ? `<div class="tr-muted">${trEsc(o.description)}</div>` : ''}</td>
                <td>${o.issuer ? trEsc(o.issuer) : '<span class="tr-muted">—</span>'}</td>
                <td>${o.validity_months ? trEsc(o.validity_months) + ' months' : '<span class="tr-muted">No expiry</span>'}</td>
                <td class="tr-actions" style="text-align:right;white-space:nowrap;">
                    <button class="btn btn-secondary btn-sm" onclick="trCatModal(${o.id})">Edit</button>
                    <button class="btn btn-secondary btn-sm" onclick="trCatDelete(${o.id})">Delete</button>
                </td>
            </tr>`).join('')}</tbody></table>`;
}

function trCatModal(id) {
    const o = id ? (trData.catalogue || []).find(x => Number(x.id) === Number(id)) : null;
    document.getElementById('trCatTitle').textContent = o ? 'Edit catalogue entry' : 'Add to catalogue';
    document.getElementById('trCatId').value = o ? o.id : '';
    document.getElementById('trCatName').value = o ? (o.name || '') : '';
    document.getElementById('trCatIssuer').value = o ? (o.issuer || '') : '';
    document.getElementById('trCatValidity').value = o && o.validity_months ? o.validity_months : '';
    document.getElementById('trCatDesc').value = o ? (o.description || '') : '';
    document.getElementById('trCatOrder').value = o ? (o.display_order || 0) : 0;
    document.getElementById('trCatActive').checked = o ? !!Number(o.is_active) : true;
    document.getElementById('trCatErr').textContent = '';
    trOpen('trCatBack');
}

async function trCatSave() {
    const payload = {
        id: document.getElementById('trCatId').value || null,
        name: document.getElementById('trCatName').value.trim(),
        issuer: document.getElementById('trCatIssuer').value.trim(),
        validity_months: document.getElementById('trCatValidity').value,
        description: document.getElementById('trCatDesc').value.trim(),
        display_order: document.getElementById('trCatOrder').value || 0,
        is_active: document.getElementById('trCatActive').checked ? 1 : 0,
    };
    if (!payload.name) { document.getElementById('trCatErr').textContent = 'A name is required.'; return; }
    const d = await trPost('save_certification_type.php', payload);
    if (!d.success) { document.getElementById('trCatErr').textContent = d.error || 'Save failed'; return; }
    trClose('trCatBack');
    trLoad();
}

async function trCatDelete(id) {
    if (!confirm('Remove this from the catalogue? Certifications already recorded against people are kept.')) return;
    const d = await trPost('save_certification_type.php', { id: id, delete: true });
    if (!d.success) { alert(d.error || 'Delete failed'); return; }
    trLoad();
}

document.addEventListener('DOMContentLoaded', trLoad);
