<?php
// restock_dispatch.php — the dispatcher's only page. Sees what the store ordered, enters what he sends now, the rest stays PENDING.
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/restock_lib.php';
$user = require_login();
if (($user['role'] ?? '') !== 'admin' && empty($user['dispatch']) && !rs_is_dispatcher($pdo, (int)$user['id'])) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dispatch — TM Perfume House</title>
<link rel="stylesheet" href="assets/ui.css">
<style>
 .wrap{max-width:820px;margin:0 auto;padding:12px 14px 60px}.card{background:#fff;border:1px solid var(--cardln);border-radius:16px;padding:14px;margin-bottom:14px}
 h3{font-size:16px}.sub{font-size:12.5px;color:var(--mut);margin:2px 0 10px}.muted{color:var(--dim)}
 .ln{display:grid;grid-template-columns:1fr 54px 54px 80px;gap:8px;align-items:center;padding:9px 0;border-bottom:1px solid #f1f3f6;font-size:14px}.ln.h{font-size:10.5px;text-transform:uppercase;color:var(--dim);font-weight:700}
 .ln input{width:100%;padding:9px 4px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:16px;text-align:center}.ln .n{text-align:center}
 .pend{color:#b91c1c;font-weight:800}.ok{color:#0f6e56;font-weight:800}
 .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.row .inp{flex:1;min-width:140px}
 .tag{display:inline-block;border-radius:99px;padding:2px 10px;font-size:12px;font-weight:800;background:#fff4dc;color:#9a6700}
 .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#14161a;color:#fff;padding:12px 18px;border-radius:14px;font-size:14px;opacity:0;transition:.2s;pointer-events:none}.toast.show{opacity:1}
</style></head><body>
<header class="tm-header"><div class="tm-brand"><div class="tm-logo">TM</div><div><h1>Dispatch</h1><p>Hi <?= htmlspecialchars($user['name'] ?? '') ?> — send what you can, the rest stays pending</p></div></div>
 <nav class="tm-nav"><a href="#" onclick="load();return false;">&#8635; Refresh</a><a href="restock.php">Plan &amp; memos</a></nav></header>
<div class="wrap" id="root"><div class="muted">Loading…</div></div><div class="toast" id="toast"></div>
<script>
const $=id=>document.getElementById(id), esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const toast=m=>{const t=$('toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2600)};
async function api(a,body){const r=await fetch('restock_api.php?action='+a,body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{});const d=await r.json().catch(()=>({ok:false,error:'Bad reply'}));if(!d.ok)throw new Error(d.error||'Failed');return d}
async function load(){try{const d=await api('open');
 $('root').innerHTML=d.orders.length?d.orders.map(o=>{const hrs=o.sent_at?Math.floor((Date.now()-new Date(o.sent_at.replace(' ','T')))/36e5):0;
  return '<div class="card" data-o="'+o.id+'"><h3>'+esc(o.code)+' <span class="tag">'+o.totals.pending+' pending</span></h3><div class="sub">Memo sent '+esc(o.sent_at||'')+' ('+hrs+' h ago)'+(o.note?' · '+esc(o.note):'')+'</div>'+
  '<div class="ln h"><span>Perfume</span><span class="n">Ordered</span><span class="n">Sent</span><span class="n">Send now</span></div>'+
  o.lines.map(l=>'<div class="ln"><span><b>'+esc(l.name)+'</b> '+esc(l.size)+'ml'+(l.pending?'<br><span class="pend">pending '+l.pending+'</span>':'<br><span class="ok">all sent</span>')+(l.note?'<br><span class="muted">'+esc(l.note)+'</span>':'')+'</span><span class="n">'+(l.ordered-l.cancelled)+'</span><span class="n">'+l.dispatched+'</span>'+
   (l.pending?'<span><input type="number" inputmode="numeric" min="0" max="'+l.pending+'" data-i="'+l.id+'" placeholder="0"></span>':'<span class="n muted">—</span>')+'</div>').join('')+
  '<div class="row"><input class="inp" placeholder="Parcel / courier ref (optional)" data-ref><input class="inp" placeholder="Note (optional)" data-note></div>'+
  '<div class="row"><button class="btn ghost" data-all>Send all pending</button><button class="btn" data-go style="flex:1">Save shipment</button></div>'+
  (o.ships.length?'<div class="sub" style="margin-top:10px">Already sent: '+o.ships.map(s=>esc(s.created_at.slice(5,16))+' · '+s.qty+(s.parcel_ref?' · '+esc(s.parcel_ref):'')).join(' | ')+'</div>':'')+'</div>'}).join(''):'<div class="card muted">Nothing waiting. 🎉 New memos from the store show here.</div>';
 document.querySelectorAll('[data-all]').forEach(b=>b.onclick=()=>b.closest('.card').querySelectorAll('input[data-i]').forEach(i=>i.value=i.max));
 document.querySelectorAll('[data-go]').forEach(b=>b.onclick=async()=>{const c=b.closest('.card');const lines=[...c.querySelectorAll('input[data-i]')].map(i=>({item_id:+i.dataset.i,qty:+i.value||0})).filter(x=>x.qty>0);
  if(!lines.length)return toast('Enter how many you are sending');
  try{const r=await api('ship',{order_id:+c.dataset.o,parcel_ref:c.querySelector('[data-ref]').value,note:c.querySelector('[data-note]').value,lines});toast('Saved — '+r.sent+' bottles sent. Rest stays pending.');load()}catch(e){toast(e.message)}})
 }catch(e){$('root').innerHTML='<div class="card">'+esc(e.message)+'</div>'}}
load();
</script></body></html>
