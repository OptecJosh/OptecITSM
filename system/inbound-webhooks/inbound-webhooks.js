/*
 * System > Inbound webhooks.
 *
 * List, edit and watch the deliveries. The field catalogue comes from the server
 * (inboundWebhookFields) so the mapping boxes can never offer a field the
 * receiver would ignore.
 *
 * The secret is shown ONCE, when it is created or rotated — after that only its
 * existence is visible. That is the same bargain as an API key: recoverable by
 * rotation, not by looking it up, so a screenshot of this page is not a key.
 */

const IW_API = '../../api/system/inbound_webhooks.php';
let iwHooks = [];
let iwFields = {};
let iwCompanies = [];
let iwStatuses = [];

function iwEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function iwLoad() {
    let d;
    try { d = await (await fetch(IW_API + '?action=list')).json(); }
    catch (e) { d = { success: false, error: 'Network error' }; }

    const host = document.getElementById('iwList');
    if (!d.success) { host.innerHTML = '<span style="color:#b91c1c">' + iwEsc(d.error) + '</span>'; return; }

    iwHooks = d.webhooks || [];
    iwFields = d.fields || {};
    iwCompanies = d.companies || [];
    iwStatuses = d.statuses || [];

    if (!iwHooks.length) {
        host.innerHTML = '<div style="color:var(--text-dim,#6b7280);font-size:13px;">Nothing configured yet. '
            + 'Create one, then paste its URL into the tool that should raise tickets.</div>';
    } else {
        host.innerHTML = `<table class="iw">
            <thead><tr><th>Name</th><th>URL</th><th>Auth</th><th>Company</th><th>Last received</th><th>Events</th><th></th></tr></thead>
            <tbody>${iwHooks.map(h => `
                <tr>
                    <td><strong>${iwEsc(h.name)}</strong>
                        <span class="iw-pill ${Number(h.is_active) ? 'on' : 'off'}">${Number(h.is_active) ? 'active' : 'off'}</span>
                        ${h.description ? `<div style="color:var(--text-dim,#6b7280);font-size:12px;">${iwEsc(h.description)}</div>` : ''}</td>
                    <td><span class="iw-url">${iwEsc(h.url)}</span>
                        <div><button class="btn btn-secondary btn-sm" style="margin-top:4px;" onclick="iwCopy('${iwEsc(h.url)}')">Copy</button></div></td>
                    <td>${iwEsc(h.auth_type)}</td>
                    <td>${h.company_name ? iwEsc(h.company_name) : '<span style="color:var(--text-dim,#9ca3af)">Default</span>'}</td>
                    <td>${h.last_received_at ? iwEsc(h.last_received_at) : '<span style="color:var(--text-dim,#9ca3af)">never</span>'}</td>
                    <td>${h.event_count}</td>
                    <td style="white-space:nowrap;text-align:right;">
                        <button class="btn btn-secondary btn-sm" onclick="iwEdit(${h.id})">Edit</button>
                        <button class="btn btn-secondary btn-sm" onclick="iwRotate(${h.id})">Rotate secret</button>
                        <button class="btn btn-secondary btn-sm" onclick="iwDelete(${h.id})">Delete</button>
                    </td>
                </tr>`).join('')}</tbody></table>`;
    }
    iwLoadEvents();
}

function iwCopy(url) {
    navigator.clipboard.writeText(url).then(
        () => alert('URL copied.'),
        () => prompt('Copy this URL:', url)
    );
}

function iwAuthChanged() {
    const type = document.getElementById('iwAuth').value;
    document.getElementById('iwHeaderWrap').style.display = type === 'token' ? 'none' : '';
    document.getElementById('iwPrefixWrap').style.display = type === 'hmac_sha256' ? '' : 'none';
    document.getElementById('iwEncWrap').style.display = type === 'hmac_sha256' ? '' : 'none';

    const hints = {
        header_secret: 'The sender puts the secret verbatim in the named header. Simple, and fine over HTTPS — most tools can do this.',
        hmac_sha256: 'The sender signs the RAW body with the secret and sends the digest in the named header. The strongest option: '
            + 'the signature covers the payload, so it cannot be replayed against different content. GitHub uses '
            + '<code>X-Hub-Signature-256</code> with prefix <code>sha256=</code> and hex encoding.',
        token: 'The secret travels in the URL as <code>&amp;token=…</code>. Weakest — URLs end up in logs and proxies — but some '
            + 'tools offer no way to set a header. Use it only when you must, and rotate it if the URL leaks.',
    };
    document.getElementById('iwAuthHint').innerHTML = hints[type] || '';
}

function iwEdit(id) {
    const h = id ? iwHooks.find(x => Number(x.id) === Number(id)) : null;
    document.getElementById('iwEditor').style.display = '';
    document.getElementById('iwEditorTitle').textContent = h ? 'Edit: ' + h.name : 'New webhook';
    document.getElementById('iwId').value = h ? h.id : '';
    document.getElementById('iwName').value = h ? h.name : '';
    document.getElementById('iwActive').value = h ? (Number(h.is_active) ? '1' : '0') : '1';
    document.getElementById('iwAuth').value = h ? h.auth_type : 'header_secret';
    document.getElementById('iwHeader').value = h ? (h.signature_header || '') : '';
    document.getElementById('iwPrefix').value = h ? (h.signature_prefix || '') : '';
    document.getElementById('iwEnc').value = h ? (h.signature_encoding || 'hex') : 'hex';
    document.getElementById('iwDedupe').value = h ? (h.dedupe_path || '') : '';
    document.getElementById('iwResolvePath').value = h ? (h.resolve_path || '') : '';
    document.getElementById('iwResolveValue').value = h ? (h.resolve_value || '') : '';
    document.getElementById('iwErr').textContent = '';
    document.getElementById('iwSecretBox').innerHTML = '';

    document.getElementById('iwCompany').innerHTML = '<option value="">Default</option>' +
        iwCompanies.map(c => `<option value="${c.id}" ${h && Number(h.tenant_id) === Number(c.id) ? 'selected' : ''}>${iwEsc(c.name)}</option>`).join('');
    document.getElementById('iwResolveStatus').innerHTML = '<option value="">— leave the status alone —</option>' +
        iwStatuses.map(s => `<option value="${iwEsc(s)}" ${h && h.resolve_status === s ? 'selected' : ''}>${iwEsc(s)}</option>`).join('');

    const map = (h && h.field_map && typeof h.field_map === 'object') ? h.field_map : {};
    document.getElementById('iwMap').innerHTML = Object.entries(iwFields).map(([key, def]) => `
        <label for="iwmap_${key}">${iwEsc(def.label)}${def.required ? ' *' : ''}</label>
        <input type="text" id="iwmap_${key}" value="${iwEsc(map[key] || '')}"
               placeholder="${key === 'subject' ? '{{alerts.0.labels.alertname}}' : (def.kind === 'lookup' ? 'High' : '')}">`).join('');

    iwAuthChanged();
    document.getElementById('iwEditor').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function iwCancel() { document.getElementById('iwEditor').style.display = 'none'; }

async function iwSave() {
    const map = {};
    Object.keys(iwFields).forEach(key => {
        const el = document.getElementById('iwmap_' + key);
        if (el && el.value.trim()) map[key] = el.value.trim();
    });

    const payload = {
        action: 'save',
        id: document.getElementById('iwId').value || null,
        name: document.getElementById('iwName').value.trim(),
        is_active: document.getElementById('iwActive').value,
        auth_type: document.getElementById('iwAuth').value,
        signature_header: document.getElementById('iwHeader').value.trim(),
        signature_prefix: document.getElementById('iwPrefix').value.trim(),
        signature_encoding: document.getElementById('iwEnc').value,
        tenant_id: document.getElementById('iwCompany').value,
        field_map: map,
        dedupe_path: document.getElementById('iwDedupe').value.trim(),
        resolve_path: document.getElementById('iwResolvePath').value.trim(),
        resolve_value: document.getElementById('iwResolveValue').value.trim(),
        resolve_status: document.getElementById('iwResolveStatus').value,
    };
    if (!payload.name) { document.getElementById('iwErr').textContent = 'A name is required.'; return; }

    let d;
    try {
        d = await (await fetch(IW_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })).json();
    } catch (e) { d = { success: false, error: 'Network error' }; }
    if (!d.success) { document.getElementById('iwErr').textContent = d.error || 'Save failed'; return; }

    if (d.secret) {
        // Shown once, on purpose — see the file header.
        document.getElementById('iwSecretBox').innerHTML =
            '<div class="iw-secret"><strong>Copy the secret now — it is not shown again.</strong>'
            + '<div style="margin-top:6px;">URL: <code>' + iwEsc(d.url) + '</code></div>'
            + '<div style="margin-top:4px;">Secret: <code>' + iwEsc(d.secret) + '</code></div>'
            + '<div style="margin-top:6px;">Lost it? Rotate the secret and update the sender.</div></div>';
    } else {
        iwCancel();
    }
    iwLoad();
}

async function iwRotate(id) {
    if (!confirm('Rotate the secret? The sending system stops working until you update it there.')) return;
    let d;
    try {
        d = await (await fetch(IW_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'rotate', id: id }) })).json();
    } catch (e) { d = { success: false, error: 'Network error' }; }
    if (!d.success) { alert(d.error || 'Rotate failed'); return; }
    document.getElementById('iwEditor').style.display = '';
    document.getElementById('iwSecretBox').innerHTML =
        '<div class="iw-secret"><strong>New secret — copy it now.</strong><div style="margin-top:6px;"><code>' + iwEsc(d.secret) + '</code></div></div>';
}

async function iwDelete(id) {
    const h = iwHooks.find(x => Number(x.id) === Number(id));
    if (!confirm('Delete "' + (h ? h.name : id) + '"? Its delivery history goes too, and the sender starts getting 404s.')) return;
    let d;
    try {
        d = await (await fetch(IW_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: id }) })).json();
    } catch (e) { d = { success: false, error: 'Network error' }; }
    if (!d.success) { alert(d.error || 'Delete failed'); return; }
    iwLoad();
}

async function iwLoadEvents() {
    const host = document.getElementById('iwEvents');
    let d;
    try { d = await (await fetch(IW_API + '?action=events&webhook_id=0')).json(); }
    catch (e) { d = { success: false, error: 'Network error' }; }
    if (!d.success) { host.innerHTML = '<span style="color:#b91c1c">' + iwEsc(d.error) + '</span>'; return; }

    const rows = d.events || [];
    if (!rows.length) {
        host.innerHTML = '<div style="color:var(--text-dim,#6b7280);font-size:13px;">No deliveries yet. '
            + 'Rejected attempts appear here too, which is usually how you find out a secret is wrong.</div>';
        return;
    }
    host.innerHTML = `<table class="iw">
        <thead><tr><th>When</th><th>Outcome</th><th>Ticket</th><th>Detail</th><th>Payload</th></tr></thead>
        <tbody>${rows.map(e => `
            <tr>
                <td style="white-space:nowrap;">${iwEsc(e.received_at)}<div style="color:var(--text-dim,#9ca3af);font-size:11px;">${iwEsc(e.remote_ip || '')}</div></td>
                <td class="iw-out-${iwEsc(e.outcome)}">${iwEsc(e.outcome)}</td>
                <td>${e.ticket_number ? iwEsc(e.ticket_number) : '—'}</td>
                <td>${iwEsc(e.message || '')}${e.dedupe_key ? `<div style="color:var(--text-dim,#9ca3af);font-size:11px;">key: ${iwEsc(e.dedupe_key)}</div>` : ''}</td>
                <td><div class="iw-payload">${iwEsc((e.payload || '').slice(0, 1200))}</div></td>
            </tr>`).join('')}</tbody></table>`;
}

document.addEventListener('DOMContentLoaded', iwLoad);
