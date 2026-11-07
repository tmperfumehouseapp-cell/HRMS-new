<?php
// restock.php — Auto restock (admin). Plan every 2 days → send memo → watch dispatch → scan cross-check → alerts.
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
$user = require_login();
require_once __DIR__ . '/includes/restock_lib.php';
ensure_restock_schema($pdo);
$isAdmin = (($user['role'] ?? '') === 'admin');
if (!$isAdmin && empty($user['dispatch']) && !rs_is_dispatcher($pdo, (int)$user['id'])) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Restock — TM Perfume House</title>
<link rel="stylesheet" href="assets/ui.css">
<style>
 .wrap{max-width:1200px;margin:0 auto;padding:12px 16px 50px}
 .tabs{display:flex;gap:8px;margin:10px 0 14px;overflow-x:auto}.tabs button{border:1px solid var(--line);background:#fff;border-radius:99px;padding:9px 16px;font:inherit;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap}.tabs button.on{background:var(--ink,#14161a);color:#fff;border-color:var(--ink,#14161a)}
 .tabs b{background:#d9423b;color:#fff;border-radius:99px;padding:1px 7px;font-size:11px;margin-left:5px}
 .card{background:#fff;border:1px solid var(--cardln);border-radius:16px;padding:16px;margin-bottom:14px;box-shadow:var(--shadow-1)}
 .card h3{font-size:15px;margin-bottom:4px}.sub{font-size:12.5px;color:var(--mut);margin-bottom:10px}
 table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #f1f3f6;font-size:13.5px}th{font-size:11px;text-transform:uppercase;color:var(--dim);letter-spacing:.5px}
 td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
 input.q{width:64px;padding:7px;border:1px solid var(--line);border-radius:9px;font:inherit;text-align:center}
 .why{border-radius:99px;padding:2px 10px;font-size:11.5px;font-weight:800;white-space:nowrap}
 .why.empty{background:#fdecea;color:#b91c1c}.why.top_seller{background:#e4f5ee;color:#0f6e56}.why.low_cover{background:#fff4dc;color:#9a6700}.why.manual{background:#eef0f3;color:#555}
 .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.row .inp{flex:1;min-width:160px}
 .pill{border-radius:99px;padding:3px 11px;font-size:12px;font-weight:800}.s-draft{background:#eef0f3}.s-sent{background:#fff4dc;color:#9a6700}.s-partial{background:#fdecea;color:#b91c1c}.s-dispatched{background:#e3eefb;color:#1d4ed8}.s-received,.s-closed{background:#e4f5ee;color:#0f6e56}.s-cancelled{background:#eef0f3;color:#888}
 .bar{height:8px;background:#eef0f3;border-radius:6px;overflow:hidden;min-width:90px}.bar i{display:block;height:100%;background:var(--gold)}
 .al{border-left:4px solid #f59e0b;padding:10px 12px;background:#fffaf0;border-radius:10px;margin-bottom:8px}.al.red{border-color:#d9423b;background:#fff5f4}.al b{display:block;font-size:13.5px}.al span{font-size:12.5px;color:var(--mut)}.al em{font-style:normal;font-size:11px;font-weight:800;color:#555;text-transform:uppercase;float:right}
 .learn{background:#f3faf7;border:1px solid #cfe9df;border-radius:12px;padding:10px 12px;font-size:13px;margin-bottom:12px}
 .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#14161a;color:#fff;padding:12px 18px;border-radius:14px;font-size:14px;opacity:0;transition:.2s;pointer-events:none;z-index:90}.toast.show{opacity:1}
 .muted{color:var(--dim)} .x{background:none;border:none;color:#b91c1c;cursor:pointer;font-size:16px}
 @media(max-width:700px){.hide-s{display:none}}
</style></head><body>
<header class="tm-header"><div class="tm-brand"><div class="tm-logo">TM</div><div><h1>Restock</h1><p>Auto order every 2 days · dispatch · scan check</p></div></div>
 <?php if ($isAdmin && empty($user['only_restock'])) { require_once __DIR__ . '/includes/nav.php'; pos_nav('restock', '', 'boot();return false;'); } else { ?><nav class="tm-nav"><a href="#" onclick="boot();return false;">&#8635; Refresh</a><a href="restock_dispatch.php">Dispatch</a></nav><?php } ?></header>
<div class="wrap">
 <div class="tabs" id="tabs"></div><div id="body"><div class="muted">Loading…</div></div>
</div><div class="toast" id="toast"></div>
<script>
const $=id=>document.getElementById(id), esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const IS_ADMIN=<?= json_encode($isAdmin) ?>;
let TAB='plan', SEL=null, ALERTS=[];
const toast=(m)=>{const t=$('toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2600)};
async function api(a,body,qs){const r=await fetch('restock_api.php?action='+a+(qs||''),body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{});const d=await r.json().catch(()=>({ok:false,error:'Bad reply'}));if(!d.ok)throw new Error(d.error||'Failed');return d}
const run=async(f)=>{try{return await f()}catch(e){toast(e.message);return null}};
const WHY={empty:'Empty',top_seller:'Top seller',low_cover:'Running low',manual:'Added by you'};
function tabs(){const T=[['plan','Plan'],['orders','Memos'],['alerts','Delays'+(ALERTS.length?'<b>'+ALERTS.length+'</b>':'')],['learn','What it learned'],...(IS_ADMIN?[['people','People & rules']]:[])];
 $('tabs').innerHTML=T.map(t=>'<button data-t="'+t[0]+'" class="'+(TAB===t[0]?'on':'')+'">'+t[1]+'</button>').join('');
 $('tabs').onclick=e=>{const b=e.target.closest('button');if(!b)return;TAB=b.dataset.t;draw()}}
async function boot(){try{const d=await api('alerts');ALERTS=d.alerts}catch(e){}draw()}
async function draw(){tabs();const b=$('body');b.innerHTML='<div class="muted">Loading…</div>';
 try{({plan:plan,orders:orders,alerts:alerts,learn:learn,people:people})[TAB](b)}catch(e){b.innerHTML='<div class="card">'+esc(e.message)+'</div>'}}

async function plan(b,refresh){const d=await api('plan',null,refresh?'&refresh=1':''); const o=d.order, c=d.cfg;
 const ls=o.lines.filter(l=>l.status!=='removed'), tot=ls.reduce((a,l)=>a+l.ordered,0);
 b.innerHTML='<div class="learn">'+(c.learned?'🧠 Learned from your stock: it arrives about every <b>'+esc(c.gap)+'</b> days, so it keeps <b>'+c.cover+'</b> days on the shelf (+ <b>'+c.lead+'</b> days to get here).':'🧠 Starting numbers: <b>'+c.cover+'</b> days on the shelf + <b>'+c.lead+'</b> days to arrive. It adjusts itself once 3 deliveries have been scanned in.')+(d.auto_made?' · <b>New draft made automatically.</b>':'')+'</div>'+
 '<div class="card"><div class="row"><div style="flex:2"><h3>Draft memo '+esc(o.code)+' <span class="pill s-draft">draft</span></h3><div class="sub">Made from sales (14 days), what is on the shelf, what is already ordered and what is empty. Change any number, remove a line, or add your own — then send.</div></div>'+
 '<button class="btn ghost" id="rf">↻ Refresh suggestions</button></div>'+
 '<table><tr><th>Perfume</th><th class="n hide-s">Shelf</th><th>Why</th><th class="n">Order</th><th></th></tr>'+
 (ls.length?ls.map(l=>'<tr><td><b>'+esc(l.name)+'</b> '+esc(l.size)+(l.size?'ml':'')+(l.note?'<br><span class="muted">'+esc(l.note)+'</span>':'')+'</td><td class="n hide-s">'+l.in_stock+'</td><td><span class="why '+esc(l.reason)+'">'+(WHY[l.reason]||l.reason)+'</span></td><td class="n"><input class="q" type="number" min="0" value="'+l.ordered+'" data-i="'+l.id+'"></td><td><button class="x" data-rm="'+l.id+'" title="Remove">✕</button></td></tr>').join(''):'<tr><td colspan="6" class="muted">Nothing needs ordering right now.</td></tr>')+
 '</table><div class="row" style="margin-top:12px"><input class="inp" id="srch" placeholder="+ Add a perfume yourself (type name or SKU)…"><div id="hits" style="width:100%"></div></div>'+
 '<div class="row" style="margin-top:12px;justify-content:space-between"><b>'+tot+' bottles · '+ls.length+' lines</b><span><button class="btn ghost" id="dis">Discard draft</button> <button class="btn" id="snd"'+(tot?'':' disabled')+'>Send to dispatch</button></span></div></div>';
 $('rf').onclick=()=>run(async()=>{await plan(b,true);toast('Suggestions refreshed')});
 b.querySelectorAll('input.q').forEach(i=>i.onchange=()=>run(async()=>{await api('set',{item_id:+i.dataset.i,qty:+i.value});toast('Saved')}));
 b.querySelectorAll('[data-rm]').forEach(x=>x.onclick=()=>run(async()=>{await api('remove',{item_id:+x.dataset.rm});plan(b)}));
 let tm;$('srch').oninput=e=>{clearTimeout(tm);tm=setTimeout(()=>run(async()=>{const q=e.target.value.trim();if(!q){$('hits').innerHTML='';return}const r=await api('search',null,'&q='+encodeURIComponent(q));
  $('hits').innerHTML=r.rows.map(p=>'<div class="row" style="padding:5px 0;border-bottom:1px solid #f1f3f6"><span style="flex:1"><b>'+esc(p.name)+'</b> '+esc(p.size)+'ml</span><input class="q" type="number" min="1" value="3" id="aq'+p.product_id+'"><button class="btn ghost" data-add="'+p.product_id+'">Add</button></div>').join('')||'<div class="muted">No match</div>';
  $('hits').querySelectorAll('[data-add]').forEach(x=>x.onclick=()=>run(async()=>{await api('add',{product_id:+x.dataset.add,qty:+$('aq'+x.dataset.add).value||1});plan(b)}))}),250)};
 $('dis').onclick=()=>confirm('Throw this draft away?')&&run(async()=>{await api('cancel',{order_id:o.id});plan(b)});
 $('snd').onclick=()=>confirm('Send '+o.code+' ('+tot+' bottles) to the dispatcher now?')&&run(async()=>{const r=await api('send',{order_id:o.id});try{await navigator.clipboard.writeText(r.memo)}catch(e){}toast('Sent — memo text copied for WhatsApp');TAB='orders';boot()})}

const pct=(a,b)=>b?Math.min(100,Math.round(a/b*100)):0;
async function orders(b){const d=await api('orders',null,'&f='+(window.OF||'open'));
 b.innerHTML='<div class="row" style="margin-bottom:10px"><button class="btn ghost" data-f="open">Open</button><button class="btn ghost" data-f="done">Finished</button><button class="btn ghost" data-f="all">All history</button></div>'+
 (d.orders.length?d.orders.map(o=>'<div class="card"><div class="row" style="justify-content:space-between"><div><h3>'+esc(o.code)+' <span class="pill s-'+o.live+'">'+o.live+'</span></h3><div class="sub">Sent '+esc(o.sent_at||'—')+(o.note?' · '+esc(o.note):'')+'</div></div><div style="min-width:220px"><div class="sub">Ordered '+o.totals.ordered+' · Sent '+o.totals.dispatched+' · Scanned in '+o.totals.received+' · <b style="color:'+(o.totals.pending?'#b91c1c':'#0f6e56')+'">Pending '+o.totals.pending+'</b></div><div class="bar"><i style="width:'+pct(o.totals.received,o.totals.ordered)+'%"></i></div></div></div>'+
  '<table><tr><th>Perfume</th><th class="n">Ordered</th><th class="n">Sent</th><th class="n">Scanned</th><th class="n">Pending</th><th class="n hide-s">Gap</th><th></th></tr>'+o.lines.filter(l=>l.status!=='removed').map(l=>'<tr><td><b>'+esc(l.name)+'</b> '+esc(l.size)+'ml'+(l.note?'<br><span class="muted">'+esc(l.note)+'</span>':'')+'</td><td class="n"><input class="q" type="number" min="'+l.dispatched+'" value="'+l.ordered+'" data-i="'+l.id+'"></td><td class="n">'+l.dispatched+'</td><td class="n">'+l.received+'</td><td class="n" style="color:'+(l.pending?'#b91c1c':'inherit')+'">'+l.pending+'</td><td class="n hide-s">'+(l.in_transit?'<b style="color:#b45309">'+l.in_transit+' not scanned</b>':'—')+'</td><td><button class="x" data-rm="'+l.id+'" title="Cancel the pending part">✕</button></td></tr>').join('')+'</table>'+
  (o.ships.length?'<div class="sub" style="margin-top:8px">Shipments: '+o.ships.map(s=>esc(s.created_at.slice(5,16))+' · '+s.qty+(s.parcel_ref?' · '+esc(s.parcel_ref):'')+' ('+esc(s.by_name)+')').join(' &nbsp;|&nbsp; ')+'</div>':'')+
  '<div class="row" style="margin-top:8px"><button class="btn ghost" data-memo="'+o.id+'">Copy memo text</button><button class="btn ghost" data-print="'+o.id+'">Print</button></div></div>').join(''):'<div class="card muted">No memos here yet.</div>');
 b.querySelectorAll('[data-f]').forEach(x=>x.onclick=()=>{window.OF=x.dataset.f;orders(b)});
 b.querySelectorAll('input.q').forEach(i=>i.onchange=()=>run(async()=>{await api('set',{item_id:+i.dataset.i,qty:+i.value});toast('Saved');orders(b)}));
 b.querySelectorAll('[data-rm]').forEach(x=>x.onclick=()=>confirm('Cancel the part that is not dispatched yet? (Sent parcels stay.)')&&run(async()=>{await api('remove',{item_id:+x.dataset.rm});orders(b)}));
 b.querySelectorAll('[data-memo]').forEach(x=>x.onclick=()=>run(async()=>{const r=await api('memo',null,'&id='+x.dataset.memo);try{await navigator.clipboard.writeText(r.text);toast('Copied')}catch(e){prompt('Copy:',r.text)}}));
 b.querySelectorAll('[data-print]').forEach(x=>x.onclick=()=>run(async()=>{const r=await api('memo',null,'&id='+x.dataset.print);const w=window.open('','_blank');w.document.write('<pre style="font:15px monospace;padding:20px">'+esc(r.text)+'</pre>');w.document.close();w.print()}))}

async function alerts(b){const d=await api('alerts');ALERTS=d.alerts;tabs();
 b.innerHTML='<div class="card"><h3>Delays & mismatches</h3><div class="sub">Memo not sent · dispatch late · parcel sent but not scanned · barcodes printed but not scanned. Only scanned bottles count as stock.</div>'+
 (d.alerts.length?d.alerts.map(a=>'<div class="al '+(a.sev==='red'?'red':'')+'"><em>'+esc(a.to)+'</em><b>'+esc(a.title)+'</b><span>'+esc(a.detail)+(a.ref?' · '+esc(a.ref):'')+'</span></div>').join(''):'<div class="muted">All on time. 🎉</div>')+'</div>'}

async function learn(b){const d=await api('learn');const n=d.now,N=['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
 b.innerHTML='<div class="card"><h3>What it has learned</h3><div class="sub">Every day it checks how often stock really arrives, how long a memo really takes, and which weekdays sell more. It starts at 7 days and changes by itself; turn it off in People & rules.</div>'+
 '<p>Shelf target: <b>'+n.cover+' days</b> · Time to arrive: <b>'+n.lead+' days</b> · '+(n.learned?'<b style="color:#0f6e56">learned from your data</b>':'starting numbers')+'</p>'+
 (Object.keys(n.dow||{}).length?'<p class="sub">Weekday weight: '+[1,2,3,4,5,6,7].map(i=>N[i]+' ×'+(n.dow[i]||1)).join(' · ')+'</p>':'')+
 '<table><tr><th>Day</th><th class="n">Cover</th><th class="n">Lead</th><th class="n">Delivery gap</th><th class="n">Delivery days</th><th>Note</th></tr>'+d.log.map(r=>'<tr><td>'+esc(r.day)+'</td><td class="n">'+r.cover+'</td><td class="n">'+r.lead_days+'</td><td class="n">'+(r.gap||'—')+'</td><td class="n">'+r.arrivals+'</td><td class="muted">'+esc(r.note)+'</td></tr>').join('')+'</table></div>'}

async function people(b){const d=await api('dispatchers');const s=await api('settings',{});const c=s.cfg;
 b.innerHTML='<div class="card"><h3>Who is the dispatcher?</h3><div class="sub">Tick the person (create them first in Users). They sign in and see only the Dispatch page — no billing, no money. Everything they do is logged by name.</div>'+
 d.users.map(u=>'<div class="row" style="padding:6px 0;border-bottom:1px solid #f1f3f6"><label style="flex:1"><input type="checkbox" data-u="'+u.id+'" '+(+u.is_disp?'checked':'')+'> <b>'+esc(u.name)+'</b> <span class="muted">@'+esc(u.username)+(+u.status?'':' (off)')+'</span></label></div>').join('')+'</div>'+
 '<div class="card"><h3>Rules</h3><div class="sub">Starting numbers. With learning on, cover and lead adjust by themselves.</div><div class="row">'+
 [['rs_cover_days','Days on shelf',c.cover],['rs_lead_days','Days to arrive',c.lead],['rs_cycle_days','Order every (days)',c.cycle],['rs_empty_min','Empty refill min',c.empty_min],['rs_slow_min','Empty slow-seller min',c.slow_min],['rs_top_pct','Top sellers %',c.top_pct],['rs_over_days','Overstock after (days)',c.over_days],['rs_dispatch_hours','Dispatch within (h)',c.disp_hrs],['rs_scan_hours','Scan within (h)',c.scan_hrs]].map(r=>'<label style="font-size:12px;width:140px">'+r[1]+'<input class="inp" data-k="'+r[0]+'" value="'+r[2]+'"></label>').join('')+
 '</div><div class="row" style="margin-top:10px"><label><input type="checkbox" id="lrn" '+(c.learn?'checked':'')+'> Learn the pattern every day</label><button class="btn" id="sv">Save</button></div></div>';
 b.querySelectorAll('[data-u]').forEach(x=>x.onchange=()=>run(async()=>{await api(x.checked?'disp_add':'disp_del',{user_id:+x.dataset.u});toast('Saved')}));
 $('sv').onclick=()=>run(async()=>{const o={rs_learn:$('lrn').checked?'1':'0'};b.querySelectorAll('[data-k]').forEach(i=>o[i.dataset.k]=i.value);await api('settings',o);toast('Saved')});
}
boot();
</script></body></html>
