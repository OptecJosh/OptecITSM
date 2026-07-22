/*
 * KPI scorecards (K0). Renders per-scorecard metric tables for a month, with
 * inline value entry, a 6-month sparkline, RAG, source badge, CSV import/export,
 * and (admin) target editing. Values are manual/imported here; K2 auto-fills.
 */

const K_API = '../api/kpi/';
let kData = null;      // last loaded payload
let kFlat = [];        // flat [{id,name,value,unit,target_text,green,amber,direction}]

function kEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function kPeriod(){ return document.getElementById('kPeriod').value; }

function kSparkline(trend){
  const vals = trend.filter(v => v !== null);
  if (vals.length < 2) return '<span style="color:var(--text-dim,#9ca3af);font-size:11px;">-</span>';
  const min = Math.min(...vals), max = Math.max(...vals), span = (max-min)||1;
  const w=80, h=22, n=trend.length;
  const pts = [];
  trend.forEach((v,i)=>{ if(v===null) return; const x=(i/(n-1))*w; const y=h-((v-min)/span)*h; pts.push(x.toFixed(1)+','+y.toFixed(1)); });
  const last = trend.filter(v=>v!==null).slice(-1)[0];
  const lastIdx = (function(){ for(let i=trend.length-1;i>=0;i--) if(trend[i]!==null) return i; return 0; })();
  const lx=((lastIdx/(n-1))*w).toFixed(1), ly=(h-((last-min)/span)*h).toFixed(1);
  return '<svg width="'+w+'" height="'+h+'" viewBox="0 0 '+w+' '+h+'" style="overflow:visible;">'
    + '<polyline points="'+pts.join(' ')+'" fill="none" stroke="var(--kpi-accent,#4338ca)" stroke-width="1.6"/>'
    + '<circle cx="'+lx+'" cy="'+ly+'" r="2.4" fill="var(--kpi-accent,#4338ca)"/></svg>';
}

function kFmtVal(v, unit){
  if (v === null) return null;
  let s = (Math.round(v*100)/100).toString();
  if (unit === '%') s += '%';
  else if (unit === 'min') s += 'm';
  else if (unit === 'hrs') s += 'h';
  else if (unit === 'days') s += 'd';
  return s;
}

async function kLoad(){
  const body = document.getElementById('kBody');
  body.innerHTML = '<div class="k-note-line">Loading&hellip;</div>';
  let d;
  try { d = await (await fetch(K_API+'get_scorecard.php?period='+encodeURIComponent(kPeriod()))).json(); }
  catch(e){ d = {success:false, error:'Network error'}; }
  if (!d.success){ body.innerHTML = '<div class="k-note-line" style="color:#b91c1c">'+kEsc(d.error||'Failed to load')+'</div>'; return; }
  kData = d; kFlat = [];

  const order = Object.keys(d.labels);
  let html = '';
  order.forEach(sc => {
    const secs = d.scorecards[sc];
    if (!secs) return;
    html += '<div class="k-card" style="margin-bottom:16px;"><div class="k-sc-head">'+kEsc(d.labels[sc])+'</div>';
    Object.keys(secs).forEach(sec => {
      if (sec) html += '<div class="k-sec">'+kEsc(sec)+'</div>';
      html += '<table class="k"><thead><tr><th>Metric</th><th>Target</th><th class="num">Value</th><th>Status</th><th>Trend</th><th>Source</th></tr></thead><tbody>';
      secs[sec].forEach(k => {
        kFlat.push(k);
        const disp = kFmtVal(k.value, k.unit);
        const editT = d.can_edit ? ' <span title="Edit target" style="cursor:pointer;color:var(--kpi-accent,#4338ca)" onclick="kEditTarget('+k.id+')">&#9998;</span>' : '';
        html += '<tr>'
          + '<td class="k-metric"><b>'+kEsc(k.name)+'</b><small>'+kEsc(k.description||'')+'</small>'+(k.note?'<div class="k-note">Note: '+kEsc(k.note)+'</div>':'')+'</td>'
          + '<td style="max-width:26ch;color:var(--text-dim,#6b7280);font-size:12px;">'+kEsc(k.target_text||'-')+editT+'</td>'
          + '<td class="num"><button class="k-val-btn'+(disp!==null?' has':'')+'" onclick="kEditVal('+k.id+')">'+(disp!==null?kEsc(disp):'+ add')+'</button></td>'
          + '<td><span class="k-rag rag-'+kEsc(k.status)+'" title="'+kEsc(k.status)+'"></span></td>'
          + '<td>'+kSparkline(k.trend)+'</td>'
          + '<td><span class="k-src">'+kEsc(k.source||'-')+'</span></td>'
          + '</tr>';
      });
      html += '</tbody></table>';
    });
    html += '</div>';
  });
  body.innerHTML = html || '<div class="k-note-line">No KPIs defined.</div>';
}

// ---- value entry ----
function kEditVal(id){
  const k = kFlat.find(x=>x.id===id); if(!k) return;
  document.getElementById('kValTitle').textContent = k.name;
  document.getElementById('kValTarget').textContent = 'Target: ' + (k.target_text||'-');
  document.getElementById('kValId').value = id;
  document.getElementById('kValValue').value = (k.value!==null?k.value:'');
  document.getElementById('kValUnit').textContent = k.unit ? '('+k.unit+')' : '';
  document.getElementById('kValStatus').value = '';
  document.getElementById('kValNote').value = k.note || '';
  document.getElementById('kValBack').classList.add('active');
}
function kCloseVal(){ document.getElementById('kValBack').classList.remove('active'); }
async function kSaveVal(){
  const id = parseInt(document.getElementById('kValId').value,10);
  const payload = {
    kpi_id: id, period: kPeriod(),
    value: document.getElementById('kValValue').value,
    status: document.getElementById('kValStatus').value,
    note: document.getElementById('kValNote').value.trim(),
  };
  let d;
  try { d = await (await fetch(K_API+'save_measurement.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json(); }
  catch(e){ d={success:false,error:'Network error'}; }
  if(!d.success){ alert(d.error||'Save failed'); return; }
  kCloseVal(); kLoad();
}

// ---- admin target edit (target text; thresholds via API) ----
async function kEditTarget(id){
  const k = kFlat.find(x=>x.id===id); if(!k) return;
  const t = prompt('Target for "'+k.name+'":', k.target_text||'');
  if (t === null) return;
  let d;
  try { d = await (await fetch(K_API+'save_definition.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,target_text:t})})).json(); }
  catch(e){ d={success:false,error:'Network error'}; }
  if(!d.success){ alert(d.error||'Update failed'); return; }
  kLoad();
}

// ---- CSV import / export ----
function kImport(){ document.getElementById('kImpResult').innerHTML=''; document.getElementById('kImpCsv').value=''; document.getElementById('kImpBack').classList.add('active'); }
function kCloseImp(){ document.getElementById('kImpBack').classList.remove('active'); }
function kExportCsv(){
  let csv = 'id,name,value\n';
  kFlat.forEach(k => { csv += k.id+',"'+String(k.name).replace(/"/g,'""')+'",'+(k.value!==null?k.value:'')+'\n'; });
  const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
  a.download='kpi-'+kPeriod()+'.csv'; a.click(); URL.revokeObjectURL(a.href);
}
async function kRunImport(){
  const raw = document.getElementById('kImpCsv').value.trim();
  const res = document.getElementById('kImpResult');
  if(!raw){ res.textContent='Paste some CSV first.'; return; }
  const lines = raw.split(/\r?\n/).filter(l=>l.trim());
  const header = lines.shift().split(',').map(h=>h.trim().toLowerCase().replace(/^"|"$/g,''));
  const idi = header.indexOf('id'), vi = header.indexOf('value'), si = header.indexOf('status'), ni = header.indexOf('note');
  if (idi<0 || vi<0){ res.textContent='CSV needs at least id and value columns.'; return; }
  let ok=0, fail=0;
  res.textContent='Importing...';
  for (const line of lines){
    const cells = line.split(',');
    const id = parseInt((cells[idi]||'').replace(/"/g,''),10);
    if(!id){ fail++; continue; }
    const payload = { kpi_id:id, period:kPeriod(), value:(cells[vi]||'').replace(/"/g,'').trim(),
                      status:(si>=0?(cells[si]||'').trim():''), note:(ni>=0?(cells[ni]||'').replace(/"/g,'').trim():'') };
    try { const d = await (await fetch(K_API+'save_measurement.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json(); d.success?ok++:fail++; }
    catch(e){ fail++; }
  }
  res.textContent = 'Imported '+ok+' row(s)'+(fail?', '+fail+' failed':'')+'.';
  kLoad();
}

document.addEventListener('DOMContentLoaded', ()=>{
  const p = document.getElementById('kPeriod');
  const now = new Date();
  p.value = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  p.addEventListener('change', kLoad);
  kLoad();
});
