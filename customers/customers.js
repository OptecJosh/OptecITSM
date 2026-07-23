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
            <div class="cu-field"><label>Contact name</label><input type="text" id="cuContact" value="${cuEsc(c.contact_name||'')}"></div>
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
        ${isNew ? '' : cuCiSection()}`;
    if (!isNew) cuLoadCis();
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
