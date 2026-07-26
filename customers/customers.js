/*
 * Customers module: searchable master list + detail edit form + linked CMDB CIs.
 */

const CU_API = '../api/customers/';
let cuList = [];
let cuCurrent = null;   // loaded customer detail
let cuTenants = [];
let cuSearchTimer = null, cuCiTimer = null;

function cuEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function cuLoadTenants(){
    try {
        const d = await (await fetch('../api/system/get_tenants.php')).json();
        cuTenants = (d.tenants || d.companies || []);
    } catch(e){ cuTenants = []; }
}

async function cuLoadList(){
    const host = document.getElementById('cuList');
    const q = document.getElementById('cuSearch').value.trim();
    try {
        const d = await (await fetch(CU_API + 'get_customers.php?q=' + encodeURIComponent(q))).json();
        if(!d.success){ host.innerHTML = '<div class="cu-empty cu-err">'+cuEsc(d.error||'Failed')+'</div>'; return; }
        cuList = d.customers || [];
        if(!cuList.length){ host.innerHTML = '<div class="cu-empty">No customers'+(q?' match "'+cuEsc(q)+'"':' yet')+'.</div>'; return; }
        host.innerHTML = cuList.map(c => `
            <div class="cu-row ${cuCurrent && cuCurrent.id===c.id ? 'active':''}" onclick="cuOpen(${c.id})">
                <div class="nm">${cuEsc(c.name)}${c.is_active?'':' <span class="pill">inactive</span>'}</div>
                <div class="meta">${cuEsc(c.contact_name||'No contact')}${c.company_name?' &middot; '+cuEsc(c.company_name):''}${c.ci_count?' &middot; '+c.ci_count+' CI'+(c.ci_count===1?'':'s'):''}</div>
            </div>`).join('');
    } catch(e){ host.innerHTML = '<div class="cu-empty cu-err">Network error</div>'; }
}
function cuSearchDebounced(){ clearTimeout(cuSearchTimer); cuSearchTimer = setTimeout(cuLoadList, 250); }

function cuCompanyOptions(sel){
    return '<option value="">&mdash; None &mdash;</option>' + cuTenants.map(t =>
        `<option value="${t.id}" ${sel==t.id?'selected':''}>${cuEsc(t.name)}</option>`).join('');
}

function cuRenderForm(c){
    cuCurrent = c;
    const isNew = !c.id;
    document.getElementById('cuDetail').innerHTML = `
        <h3>${isNew ? 'New customer' : cuEsc(c.name)}</h3>
        <div class="cu-grid">
            <div class="cu-field full"><label>Name *</label><input type="text" id="cuName" value="${cuEsc(c.name||'')}"></div>
            <div class="cu-field"><label>Account reference</label><input type="text" id="cuRef" value="${cuEsc(c.account_ref||'')}"></div>
            <div class="cu-field"><label>Company</label><select id="cuTenant">${cuCompanyOptions(c.tenant_id)}</select></div>
            <div class="cu-field"><label>Contact name${isNew?'':' <span class="cu-hint">(default contact)</span>'}</label><input type="text" id="cuContact" value="${cuEsc(c.contact_name||'')}"></div>
            <div class="cu-field"><label>Contact email</label><input type="email" id="cuEmail" value="${cuEsc(c.contact_email||'')}"></div>
            <div class="cu-field"><label>Contact phone</label><input type="text" id="cuPhone" value="${cuEsc(c.contact_phone||'')}"></div>
            <div class="cu-field"><label class="toggle-inline"><input type="checkbox" id="cuActive" ${c.is_active!==false?'checked':''}> Active</label></div>
            <div class="cu-field full"><label>Notes</label><textarea id="cuNotes" rows="2">${cuEsc(c.notes||'')}</textarea></div>
        </div>
        <div class="cu-actions">
            <button class="btn btn-primary" onclick="cuSave()">Save</button>
            ${isNew ? '' : '<button class="btn btn-secondary" onclick="cuDelete()">Delete</button>'}
            <span class="spacer"></span>
        </div>
        <div class="cu-err" id="cuErr"></div>
        ${isNew ? '' : cuContactsSection()}
        ${isNew ? '' : cuUsersSection()}
        ${isNew ? '' : cuCiSection()}`;
    if (!isNew) { cuLoadContacts(); cuLoadUsers(); cuLoadCis(); }
}

// ---- 13b: portal users ---------------------------------------------------
// A linked user's tickets arrive already attributed to this customer, which is
// the whole reason to link them.
function cuUsersSection(){
    return `
        <div class="cu-ci">
            <h4>Portal users</h4>
            <div id="cuUsersList"></div>
            <div class="cu-actions" style="margin-top:10px;">
                <button class="btn btn-secondary" onclick="cuUserForm('link')">Link existing user</button>
                <button class="btn btn-secondary" onclick="cuUserForm('create')">Create user</button>
            </div>
            <div id="cuUserForm"></div>
        </div>`;
}

let cuUsers = [];

async function cuLoadUsers(){
    if(!cuCurrent || !cuCurrent.id) return;
    try {
        const d = await (await fetch(CU_API + 'get_users.php?customer_id=' + cuCurrent.id)).json();
        cuUsers = (d.success && d.users) ? d.users : [];
        cuRenderUsers(d.success ? d.available !== false : true);
    } catch(e){ cuUsers = []; cuRenderUsers(true); }
}

function cuRenderUsers(available){
    const el = document.getElementById('cuUsersList');
    if(!el) return;
    if(!available){
        el.innerHTML = '<div style="color:var(--text-dim,#6b7280);font-size:13px;margin-top:10px;">Run Database Verify to enable portal user links.</div>';
        return;
    }
    if(!cuUsers.length){
        el.innerHTML = '<div style="color:var(--text-dim,#6b7280);font-size:13px;margin-top:10px;">No portal users linked. Tickets from a linked user are attributed to this customer automatically.</div>';
        return;
    }
    el.innerHTML = '<table class="cu-ci-tbl"><tbody>' + cuUsers.map(u => `
        <tr>
            <td>
                <strong>${cuEsc(u.display_name || u.email)}</strong>
                ${u.registered ? '' : ' <span class="cu-badge" style="background:#fef3c7;color:#92400e;">Not signed up</span>'}
                ${u.preferred_name ? `<div class="cu-hint">Prefers ${cuEsc(u.preferred_name)}</div>` : ''}
            </td>
            <td>${cuEsc(u.email)}</td>
            <td style="text-align:right;white-space:nowrap;">
                <button class="btn btn-link" onclick="cuUserUnlink(${u.id})">Unlink</button>
            </td>
        </tr>`).join('') + '</tbody></table>';
}

function cuUserForm(mode){
    const el = document.getElementById('cuUserForm');
    if(!el) return;
    const creating = mode === 'create';
    el.innerHTML = `
        <div class="cu-grid" style="margin-top:12px;">
            <div class="cu-field${creating?'':' full'}"><label>Email *</label><input type="email" id="cuUEmail" placeholder="name@company.com"></div>
            ${creating ? `
            <div class="cu-field"><label>Display name</label><input type="text" id="cuUName"></div>
            <div class="cu-field"><label>Preferred name</label><input type="text" id="cuUPref"></div>` : ''}
        </div>
        ${creating ? `<div class="cu-hint" style="margin-top:6px;">The account is created without a password. They set their own by signing up on the portal with this address — nobody here handles a password.</div>` : ''}
        <div class="cu-actions">
            <button class="btn btn-primary" onclick="${creating ? 'cuUserCreate()' : 'cuUserLink()'}">${creating ? 'Create user' : 'Link user'}</button>
            <button class="btn btn-secondary" onclick="document.getElementById('cuUserForm').innerHTML=''">Cancel</button>
        </div>
        <div class="cu-err" id="cuUErr"></div>`;
}

async function cuUserLink(){
    if(!cuCurrent || !cuCurrent.id) return;
    const email = document.getElementById('cuUEmail').value.trim();
    if(!email){ document.getElementById('cuUErr').textContent = 'Email is required.'; return; }
    try {
        const d = await (await fetch(CU_API + 'link_user.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer_id:cuCurrent.id, email:email})})).json();
        if(!d.success){ document.getElementById('cuUErr').textContent = d.error || 'Link failed'; return; }
        document.getElementById('cuUserForm').innerHTML = '';
        cuLoadUsers();
    } catch(e){ document.getElementById('cuUErr').textContent = 'Network error'; }
}

async function cuUserCreate(){
    if(!cuCurrent || !cuCurrent.id) return;
    const payload = {
        customer_id: cuCurrent.id,
        email: document.getElementById('cuUEmail').value.trim(),
        display_name: document.getElementById('cuUName').value.trim(),
        preferred_name: document.getElementById('cuUPref').value.trim(),
    };
    if(!payload.email){ document.getElementById('cuUErr').textContent = 'Email is required.'; return; }
    try {
        const d = await (await fetch(CU_API + 'create_user.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json();
        if(!d.success){ document.getElementById('cuUErr').textContent = d.error || 'Create failed'; return; }
        document.getElementById('cuUserForm').innerHTML = '';
        cuLoadUsers();
    } catch(e){ document.getElementById('cuUErr').textContent = 'Network error'; }
}

async function cuUserUnlink(id){
    const u = cuUsers.find(x => x.id === id);
    if(!confirm('Unlink "' + (u?(u.display_name||u.email):'') + '" from this customer? The account itself is kept.')) return;
    try {
        const d = await (await fetch(CU_API + 'link_user.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_id:id, unlink:true})})).json();
        if(!d.success){ alert(d.error||'Unlink failed'); return; }
        cuLoadUsers();
    } catch(e){ alert('Network error'); }
}

// ---- 13a: contacts -------------------------------------------------------
// The three contact fields above are the DEFAULT contact's, kept in step by the
// API. This panel is where the customer's other people live.
function cuContactsSection(){
    return `
        <div class="cu-ci">
            <h4>Contacts</h4>
            <div id="cuContactsList"></div>
            <div class="cu-actions" style="margin-top:10px;">
                <button class="btn btn-secondary" onclick="cuContactEdit(null)">Add contact</button>
            </div>
            <div id="cuContactForm"></div>
        </div>`;
}

let cuContacts = [];

async function cuLoadContacts(){
    if(!cuCurrent || !cuCurrent.id) return;
    try {
        const d = await (await fetch(CU_API + 'get_contacts.php?customer_id=' + cuCurrent.id + '&all=1')).json();
        cuContacts = (d.success && d.contacts) ? d.contacts : [];
        cuRenderContacts(d.success ? d.available !== false : true);
    } catch(e){ cuContacts = []; cuRenderContacts(true); }
}

function cuRenderContacts(available){
    const el = document.getElementById('cuContactsList');
    if(!el) return;
    if(!available){
        el.innerHTML = '<div style="color:var(--text-dim,#6b7280);font-size:13px;margin-top:10px;">Run Database Verify to enable multiple contacts.</div>';
        return;
    }
    if(!cuContacts.length){
        el.innerHTML = '<div style="color:var(--text-dim,#6b7280);font-size:13px;margin-top:10px;">No contacts yet — the contact details above become the first one when you save.</div>';
        return;
    }
    el.innerHTML = '<table class="cu-ci-tbl"><tbody>' + cuContacts.map(k => `
        <tr${k.is_active ? '' : ' style="opacity:0.55;"'}>
            <td>
                <strong>${cuEsc(k.name)}</strong>
                ${k.is_default ? ' <span class="cu-badge">Default</span>' : ''}
                ${k.is_active ? '' : ' <span class="cu-hint">(inactive)</span>'}
                ${k.job_title ? `<div class="cu-hint">${cuEsc(k.job_title)}</div>` : ''}
            </td>
            <td>${cuEsc(k.email||'')}${k.phone ? `<div class="cu-hint">${cuEsc(k.phone)}</div>` : ''}</td>
            <td style="text-align:right;white-space:nowrap;">
                ${k.is_default ? '' : `<button class="btn btn-link" onclick="cuContactMakeDefault(${k.id})">Make default</button>`}
                <button class="btn btn-link" onclick="cuContactEdit(${k.id})">Edit</button>
                <button class="btn btn-link" onclick="cuContactDelete(${k.id})">Delete</button>
            </td>
        </tr>`).join('') + '</tbody></table>';
}

function cuContactEdit(id){
    const k = id ? cuContacts.find(x => x.id === id) : null;
    const el = document.getElementById('cuContactForm');
    if(!el) return;
    el.innerHTML = `
        <div class="cu-grid" style="margin-top:12px;">
            <div class="cu-field"><label>Name *</label><input type="text" id="cuKName" value="${cuEsc(k?k.name:'')}"></div>
            <div class="cu-field"><label>Job title</label><input type="text" id="cuKTitle" value="${cuEsc(k?(k.job_title||''):'')}"></div>
            <div class="cu-field"><label>Email</label><input type="email" id="cuKEmail" value="${cuEsc(k?(k.email||''):'')}"></div>
            <div class="cu-field"><label>Phone</label><input type="text" id="cuKPhone" value="${cuEsc(k?(k.phone||''):'')}"></div>
            <div class="cu-field full"><label>Notes</label><textarea id="cuKNotes" rows="2">${cuEsc(k?(k.notes||''):'')}</textarea></div>
            <div class="cu-field"><label class="toggle-inline"><input type="checkbox" id="cuKActive" ${(!k||k.is_active)?'checked':''}> Active</label></div>
            <div class="cu-field"><label class="toggle-inline"><input type="checkbox" id="cuKDefault" ${k&&k.is_default?'checked':''}> Default contact</label></div>
        </div>
        <div class="cu-actions">
            <button class="btn btn-primary" onclick="cuContactSave(${k?k.id:'null'})">Save contact</button>
            <button class="btn btn-secondary" onclick="document.getElementById('cuContactForm').innerHTML=''">Cancel</button>
        </div>
        <div class="cu-err" id="cuKErr"></div>`;
}

async function cuContactSave(id){
    if(!cuCurrent || !cuCurrent.id) return;
    const payload = {
        id: id || null,
        customer_id: cuCurrent.id,
        name: document.getElementById('cuKName').value.trim(),
        job_title: document.getElementById('cuKTitle').value.trim(),
        email: document.getElementById('cuKEmail').value.trim(),
        phone: document.getElementById('cuKPhone').value.trim(),
        notes: document.getElementById('cuKNotes').value.trim(),
        is_active: document.getElementById('cuKActive').checked ? 1 : 0,
        is_default: document.getElementById('cuKDefault').checked ? 1 : 0,
    };
    if(!payload.name){ document.getElementById('cuKErr').textContent = 'Name is required.'; return; }
    try {
        const d = await (await fetch(CU_API + 'save_contact.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json();
        if(!d.success){ document.getElementById('cuKErr').textContent = d.error || 'Save failed'; return; }
        document.getElementById('cuContactForm').innerHTML = '';
        // Reopen: the default may have moved, and the fields at the top of the
        // form mirror it.
        cuOpen(cuCurrent.id);
    } catch(e){ document.getElementById('cuKErr').textContent = 'Network error'; }
}

async function cuContactMakeDefault(id){
    if(!cuCurrent || !cuCurrent.id) return;
    try {
        const d = await (await fetch(CU_API + 'set_default_contact.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer_id:cuCurrent.id, contact_id:id})})).json();
        if(!d.success){ alert(d.error||'Failed'); return; }
        cuOpen(cuCurrent.id);
    } catch(e){ alert('Network error'); }
}

async function cuContactDelete(id){
    const k = cuContacts.find(x => x.id === id);
    if(!confirm('Delete contact "' + (k?k.name:'') + '"? Tickets naming it fall back to the customer\'s default.')) return;
    try {
        const d = await (await fetch(CU_API + 'delete_contact.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})})).json();
        if(!d.success){ alert(d.error||'Delete failed'); return; }
        cuOpen(cuCurrent.id);
    } catch(e){ alert('Network error'); }
}

function cuCiSection(){
    return `
        <div class="cu-ci">
            <h4>Configuration items (CMDB)</h4>
            <div class="cu-ci-search">
                <input type="text" id="cuCiSearch" placeholder="Search CMDB to link a CI&hellip;" oninput="cuCiSearchDebounced()">
                <div id="cuCiResults" class="ci-search-results"></div>
            </div>
            <div id="cuCiList"></div>
        </div>`;
}

async function cuOpen(id){
    try {
        const d = await (await fetch(CU_API + 'get_customer.php?id=' + id)).json();
        if(!d.success){ alert(d.error||'Failed'); return; }
        cuRenderForm(d.customer);
        cuRenderCis(d.cis || []);
        cuLoadList();   // refresh active highlight
    } catch(e){ alert('Network error'); }
}

function cuNew(){ cuRenderForm({ is_active: true }); cuLoadList(); }

async function cuSave(){
    const payload = {
        id: cuCurrent && cuCurrent.id ? cuCurrent.id : null,
        name: document.getElementById('cuName').value.trim(),
        account_ref: document.getElementById('cuRef').value.trim(),
        tenant_id: document.getElementById('cuTenant').value || null,
        contact_name: document.getElementById('cuContact').value.trim(),
        contact_email: document.getElementById('cuEmail').value.trim(),
        contact_phone: document.getElementById('cuPhone').value.trim(),
        is_active: document.getElementById('cuActive').checked ? 1 : 0,
        notes: document.getElementById('cuNotes').value.trim(),
    };
    if(!payload.name){ document.getElementById('cuErr').textContent = 'Name is required.'; return; }
    try {
        const d = await (await fetch(CU_API + 'save_customer.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json();
        if(!d.success){ document.getElementById('cuErr').textContent = d.error || 'Save failed'; return; }
        await cuLoadList();
        cuOpen(d.id);
    } catch(e){ document.getElementById('cuErr').textContent = 'Network error'; }
}

async function cuDelete(){
    if(!cuCurrent || !cuCurrent.id) return;
    if(!confirm('Delete "'+cuCurrent.name+'"? Tickets referencing it will be detached.')) return;
    try {
        const d = await (await fetch(CU_API + 'delete_customer.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:cuCurrent.id})})).json();
        if(!d.success){ alert(d.error||'Delete failed'); return; }
        cuCurrent = null;
        document.getElementById('cuDetail').innerHTML = '<div class="cu-empty">Select a customer, or create a new one.</div>';
        cuLoadList();
    } catch(e){ alert('Network error'); }
}

// ---- CMDB CI linking ----
async function cuLoadCis(){
    if(!cuCurrent || !cuCurrent.id) return;
    try {
        const d = await (await fetch(CU_API + 'get_customer.php?id=' + cuCurrent.id)).json();
        if(d.success) cuRenderCis(d.cis || []);
    } catch(e){}
}
function cuRenderCis(cis){
    const el = document.getElementById('cuCiList');
    if(!el) return;
    if(!cis.length){ el.innerHTML = '<div style="color:var(--text-dim,#6b7280);font-size:13px;margin-top:10px;">No configuration items linked.</div>'; return; }
    el.innerHTML = '<table class="cu-ci-tbl"><tbody>' + cis.map(o => `
        <tr>
            <td><strong>${cuEsc(o.name)}</strong></td>
            <td>${cuEsc(o.class_name||'')}</td>
            <td style="text-align:right;"><button class="btn btn-secondary btn-sm" onclick="cuUnlinkCi(${o.object_id})">Remove</button></td>
        </tr>`).join('') + '</tbody></table>';
}
function cuCiSearchDebounced(){ clearTimeout(cuCiTimer); cuCiTimer = setTimeout(cuRunCiSearch, 250); }
async function cuRunCiSearch(){
    const input = document.getElementById('cuCiSearch'); const box = document.getElementById('cuCiResults');
    if(!input||!box) return;
    const q = input.value.trim();
    if(q===''){ box.innerHTML=''; box.classList.remove('active'); return; }
    try {
        const d = await (await fetch('../api/cmdb/search_objects.php?q=' + encodeURIComponent(q) + '&limit=10')).json();
        const results = d.results || [];
        box.innerHTML = results.length
            ? results.map(r => `<button type="button" class="ci-search-row" onclick="cuLinkCi(${r.id})"><span>${cuEsc(r.name||('#'+r.id))}</span><span class="ci-search-class">${cuEsc(r.class_name||'')}</span></button>`).join('')
            : '<div class="ci-search-row" style="cursor:default;">No matches</div>';
        box.classList.add('active');
    } catch(e){ box.innerHTML = '<div class="ci-search-row" style="cursor:default;">Search failed</div>'; box.classList.add('active'); }
}
async function cuLinkCi(objectId){
    try {
        const d = await (await fetch(CU_API + 'link_cmdb.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer_id:cuCurrent.id, cmdb_object_id:objectId, action:'link'})})).json();
        if(!d.success){ alert(d.error||'Link failed'); return; }
        const input = document.getElementById('cuCiSearch'); const box = document.getElementById('cuCiResults');
        if(input) input.value=''; if(box){ box.innerHTML=''; box.classList.remove('active'); }
        cuLoadCis();
    } catch(e){ alert('Network error'); }
}
async function cuUnlinkCi(objectId){
    try {
        const d = await (await fetch(CU_API + 'link_cmdb.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer_id:cuCurrent.id, cmdb_object_id:objectId, action:'unlink'})})).json();
        if(!d.success){ alert(d.error||'Unlink failed'); return; }
        cuLoadCis();
    } catch(e){ alert('Network error'); }
}

document.addEventListener('DOMContentLoaded', async () => {
    await cuLoadTenants();
    cuLoadList();
});
