/* cx_more.js — Complaints v2 screens: Today's sheet, Log a call, Customers, Return check, Store credit, Start-here guide,
   and the extra panels inside a ticket (Claude flag, stage line, fix decided, photos, return check, store credit, courier claim, root cause, one timeline).
   Loaded by complaints.html BEFORE cx_app.js; cx_app.js hands it the small toolbox (CXM.init(kit)).  Talks to bd.cx_* (complaints_more.php). */
window.CXM = (function () {
  'use strict';
  let K = null, SD = { date: '', f: 'all', q: '' }, hideGuide = false, blobs = {};
  try { hideGuide = localStorage.getItem('cxGuideHide') === '1'; } catch (e) { /* ok */ }
  const PROB = [['missing', '🧩', 'Missing item'], ['leak', '💧', 'Leak / damaged'], ['notdispatched', '🚚', 'Parcel not dispatched'], ['notdelivered', '📦', 'Not delivered'], ['tracking', '📍', 'Tracking ID / status'], ['ndr', '⚠️', 'NDR (delivery failed)'], ['rto', '↩️', 'RTO (coming back)'], ['wrong', '🔁', 'Wrong item'], ['notliked', '🙁', 'Not liked / return'], ['quality', '👃', 'Smell / quality'], ['refund', '💰', 'Refund'], ['staff', '🙍', 'Staff'], ['other', '…', 'Other']];
  const SRCI = { whatsapp: '💬', call: '☎️', store: '🏬', order: '🛒', social: '📸', email: '✉️', other: '•' };
  const PLANS = { exchange: 'Exchange', resend: 'Resend parcel', refund: 'Refund', credit: 'Store credit', explain: 'Explain, no action' };
  const BASIS = { qc: 'After the return check', goodwill: 'Goodwill (customer did not like it)', lost: 'Parcel never reached the customer', claim: 'Courier\'s fault (we claim from the courier)' };
  const STAGES = ['new', 'fix', 'pickup', 'booked', 'transit', 'qc', 'settle', 'ready'];
  const SLAB = { new: 'New', fix: 'Fix', pickup: 'Book pickup', booked: 'Booked', transit: 'Coming back', qc: 'Check', settle: 'Settle', ready: 'Close' };

  function init(kit) { K = kit; css(); M.ready = true; }
  const esc = (s) => K.esc(s);

  /* ------------------------------ look ------------------------------ */
  function css() {
    if (document.getElementById('cmCss')) return;
    const s = document.createElement('style'); s.id = 'cmCss';
    s.textContent = `
.cm-guide{background:#e7f6f1;border:1px solid #bfe6da;border-radius:14px;padding:9px 14px;margin-bottom:10px;display:flex;gap:12px;align-items:flex-start;font-size:13px}.cm-guide b{color:#008069}.cm-guide ol{margin:3px 0 0;padding-left:18px;columns:2;column-gap:28px}.cm-guide li{margin:1px 0;break-inside:avoid}.cm-guide .x{margin-left:auto;border:0;background:none;color:#667781;font-weight:800;cursor:pointer;white-space:nowrap}
.cm-kp{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:8px;margin-bottom:10px}.cm-kp .cx-k{padding:7px 12px;border-radius:12px}.cm-kp .cx-k b{font-size:20px}.cm-kp .cx-k.am b{color:#b7791f}
.cm-dt{display:inline-flex;align-items:center;gap:2px;background:#fff;border:1px solid var(--ln);border-radius:999px;padding:2px 4px;font-weight:800}.cm-dt button{border:0;background:none;width:28px;height:28px;border-radius:50%;font-size:16px;cursor:pointer;color:#54656f}.cm-dt input{border:0;font:inherit;font-weight:800;background:none;width:128px;outline:0;color:#111b21}
.cm-chips{display:contents}
.cm-wrap{overflow-x:auto;background:#fff;border:1px solid var(--ln);border-radius:12px}
.cm-h,.cm-r{display:grid;grid-template-columns:70px 44px 44px minmax(120px,1.1fr) 92px 124px 96px 84px 120px 92px minmax(170px,2fr);gap:8px;align-items:center;padding:6px 10px;min-width:1150px}
.cm-h{background:#f7f8fa;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);border-bottom:1px solid var(--ln);position:sticky;top:0}
.cm-r{border-bottom:1px solid var(--ln);font-size:12.5px;cursor:pointer;min-height:42px}.cm-r:last-child{border:0}.cm-r:hover{background:#f7fbfa}.cm-r.late{background:#fff5f5}.cm-r.due{background:#fffaf0}.cm-r.done{opacity:.62}
.cm-r > div{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.25}.cm-r small{display:block;color:var(--mut);font-size:11px;overflow:hidden;text-overflow:ellipsis}
.cm-r .c-code{font-weight:800}.cm-r .c-prob .cx-pill{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}.cm-r .c-flag{white-space:normal;color:#3d2a7a;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}.cm-r .c-flag i{font-style:normal;background:#ede7ff;border-radius:6px;padding:0 5px;font-weight:800;font-size:11px;margin-right:4px}
.cm-w{font-weight:800}.cm-w.r{color:var(--red)}.cm-w.o{color:#b7791f}.cm-w.g{color:#0a7a55}
.cm-av{display:inline-grid;place-items:center;width:20px;height:20px;border-radius:50%;color:#fff;font-size:11px;font-weight:800;margin-right:4px;vertical-align:middle}
.cm-guide-s{display:none;gap:8px;align-items:center;background:#e7f6f1;border-radius:12px;padding:6px 12px;margin-bottom:8px;font-size:12.5px}.cm-guide-s a{margin-left:auto;font-weight:800;color:#008069;cursor:pointer}
.cm-info{background:#f0f2f5;border-radius:12px;padding:9px 12px;font-size:13px;margin:8px 0;line-height:1.45}.cm-info.bad{background:#fde4e4;color:#7a1d17}.cm-info.warn{background:#fff1d6;color:#7a4b00}.cm-info.good{background:#e7fbf3;color:#006b55}.cm-info small{display:block;color:inherit;opacity:.75}
.cm-seg{display:flex;gap:6px;margin:6px 0}.cm-seg button{flex:1;border:1.5px solid var(--ln);background:#fff;border-radius:12px;padding:8px 6px;font:inherit;font-size:13px;font-weight:700;color:#54656f;cursor:pointer}.cm-seg button.on{border-color:#00a884;background:#e7fbf3;color:#007a62}
.cm-flag{background:linear-gradient(180deg,#f6f1ff,#fff);border:1px solid #dccffb;border-radius:16px;padding:12px 14px;margin-bottom:12px}.cm-flag h4{flex-wrap:wrap;margin:0 0 6px;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#5b34b5;display:flex;gap:6px;align-items:center}.cm-flag p{margin:0;font-size:14px;line-height:1.45;color:#2b1d5c}.cm-flag .nx{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}.cm-flag .nx button{border:0;background:#5b34b5;color:#fff;border-radius:999px;padding:6px 12px;font:inherit;font-size:12.5px;font-weight:800;cursor:pointer}
.cm-stg{display:flex;align-items:center;gap:0;margin:2px 0 8px;overflow-x:auto;scrollbar-width:none}.cm-stg::-webkit-scrollbar{display:none}.cm-stg div{flex:1;min-width:52px;text-align:center;font-size:11px;font-weight:700;color:#8696a0;position:relative;padding-top:15px}.cm-stg div:before{content:"";position:absolute;left:0;right:0;top:5px;height:3px;background:#e1e6ea}.cm-stg div:after{content:"";position:absolute;left:calc(50% - 5px);top:1px;width:11px;height:11px;border-radius:50%;background:#e1e6ea;border:2px solid #fff;box-sizing:border-box}
.cm-stg div.d{color:#54656f}.cm-stg div.d:before,.cm-stg div.d:after{background:#00a884}.cm-stg div.n{color:#111b21;font-weight:800}.cm-stg div.n:after{background:#fff;border:3px solid #00a884}.cm-stg div.n.l{color:var(--red)}.cm-stg div.n.l:after{border-color:var(--red)}
.cm-plans{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:6px}.cm-plans button{border:1.5px solid var(--ln);background:#fff;border-radius:12px;padding:9px 6px;font:inherit;font-size:13px;font-weight:800;cursor:pointer;color:#111b21}.cm-plans button:hover{border-color:#00a884;background:#e7fbf3}
.cm-ph{display:grid;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));gap:8px;margin:6px 0}.cm-ph a{display:block;position:relative;border-radius:10px;overflow:hidden;background:#e9edef;aspect-ratio:1;cursor:zoom-in}.cm-ph img{width:100%;height:100%;object-fit:cover;display:block}.cm-ph em{position:absolute;left:0;right:0;bottom:0;background:rgba(17,27,33,.62);color:#fff;font-style:normal;font-size:11px;font-weight:800;text-align:center;padding:2px}
.cm-up{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.cm-lg{display:flex;gap:8px;padding:6px 0;border-top:1px solid var(--ln);font-size:12.5px}.cm-lg:first-child{border-top:0}.cm-lg > div{min-width:0;overflow-wrap:anywhere}.cm-lg > i{font-style:normal;flex:none;width:20px;text-align:center}.cm-lg small{color:var(--mut);margin-right:4px}.cm-lg .cs{color:#5b34b5;font-weight:800;font-size:11px;margin-left:4px}
.cm-big{max-width:660px !important}.cx-kpis.cm-k4{grid-template-columns:repeat(4,minmax(0,1fr))}
.cm-gd h3{margin:16px 0 6px;font-size:15px}.cm-gd ol,.cm-gd ul{margin:4px 0;padding-left:20px}.cm-gd li{margin:4px 0}.cm-chk{display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-top:1px solid var(--ln)}.cm-chk:first-child{border:0}.cm-chk i{font-style:normal;flex:none;width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:800;color:#fff;background:#00a884}.cm-chk.no i{background:#d9a21b}.cm-chk small{display:block;color:var(--mut)}
@media (max-width:900px){
 .cm-kp{grid-template-columns:repeat(4,minmax(0,1fr));gap:6px}.cm-kp .cx-k{padding:5px 8px}.cm-kp .cx-k b{font-size:17px}.cm-kp .cx-k small{font-size:11px}
 .cm-wrap{background:none;border:0;overflow:visible}.cm-h{display:none}
 .cm-r{min-width:0;grid-template-columns:auto minmax(0,1fr) auto;grid-template-areas:"code cust due" "prob stage own" "flag flag flag";gap:2px 8px;background:#fff;border:1px solid var(--ln);border-radius:12px;margin-bottom:7px;padding:8px 10px;border-bottom:1px solid var(--ln)}
 .cm-r .c-time,.cm-r .c-from,.cm-r .c-ord,.cm-r .c-desk{display:none}
 .cm-r .c-code{grid-area:code}.cm-r .c-cust{grid-area:cust;font-weight:800}.cm-r .c-due{grid-area:due;text-align:right}.cm-r .c-prob{grid-area:prob}.cm-r .c-stage{grid-area:stage;color:var(--mut);font-size:11.5px}.cm-r .c-own{grid-area:own}.cm-r .c-flag{grid-area:flag;font-size:12px;-webkit-line-clamp:2}
 .cm-r.late{border-color:#f5c2c0}
 .cm-guide{display:none}.cm-guide-s{display:flex !important}.cm-fl{flex-wrap:wrap}.cm-chips{display:flex;order:3;flex:1 0 100%;gap:6px;overflow-x:auto;scrollbar-width:none;padding-bottom:2px}.cm-chips::-webkit-scrollbar{display:none}.cm-chips .cx-ch{flex:none;padding:4px 10px}.cm-fl #cmQ{flex:1;min-width:0 !important;max-width:none !important}.cm-stg div{min-width:40px;font-size:11px}
 .cm-guide ol{columns:1}.cm-plans{grid-template-columns:repeat(2,1fr)}.cm-big{max-width:100% !important}.cx-kpis.cm-k4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:820px){
 .cx .cm-plans button,.cx-ov .cm-plans button,.cx-ov .cm-seg button{font-size:13px !important;padding:10px 6px !important;min-height:44px}
 .cx .cm-flag .nx button{font-size:12.5px !important;padding:8px 12px !important;min-height:40px}
 .cx .cm-dt button{width:40px;height:40px;padding:0 !important;font-size:18px !important}
 .cx .cm-guide-s a{padding:11px 4px !important}}`;
    document.head.appendChild(s);
  }
  const money = (n) => K.inr(n);
  const dy = (s) => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); };
  const isOpenRow = (c) => c.status === 'open' || c.status === 'reopened';
  const has = (c, k) => (c.sources || []).some((s) => s.k === k) || c.source === k;
  function whenLabel(c) {
    if (!isOpenRow(c)) return ['', c.res_label ? '✔ ' + c.res_label : '✔ ' + (K.STAT[c.status] || '')];
    if (c.stage_due) return [c.stage_late ? 'r' : (c.stage_left && /^\d+h|^\d+ ?m|^[0-9] ?h/.test(String(c.stage_left)) ? 'o' : 'g'), c.stage_late ? '⏰ ' + c.stage_left + ' late' : c.stage_left + ' left'];
    return [c.late ? 'r' : '', c.late ? '⏰ ' + (c.time_label || '') : (c.time_label || '') + ' left'];
  }
  const phoneFmt = (p) => (p ? '+91 ' + String(p).replace(/^(\d{5})(\d+)$/, '$1 $2') : 'no number');

  /* ------------------------------ Today's sheet ------------------------------ */
  async function today(quiet) {
    if (!SD.date) SD.date = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    if (!quiet) K.body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await K.call(() => api.get('bd.cx_sheet', { date: SD.date, q: SD.q }), false); if (!d) return; drawToday(d);
    K.setTick(() => { if (!document.hidden && !document.querySelector('.cx-ov') && !document.activeElement.matches('#cmQ')) today(true); }, 45000);
  }
  function drawToday(d) {
    const k = d.kpi, f = SD.f, B = K.body();
    const F = {
      all: () => true, mine: (c) => c.owner === d.me, late: (c) => isOpenRow(c) && (c.late || c.stage_late), unowned: (c) => isOpenRow(c) && !c.owner, open: isOpenRow, solved: (c) => !isOpenRow(c),
      wa: (c) => has(c, 'whatsapp'), call: (c) => has(c, 'call'), social: (c) => has(c, 'social') || has(c, 'email'), store: (c) => has(c, 'store'),
      track: (c) => ['tracking', 'ndr', 'rto', 'notdelivered'].indexOf(c.problem) > -1, pickup: (c) => isOpenRow(c) && ['pickup', 'booked', 'transit'].indexOf(c.stage) > -1, qc: (c) => isOpenRow(c) && c.stage === 'qc', settle: (c) => isOpenRow(c) && c.stage === 'settle', auto: (c) => !!c.auto && isOpenRow(c)
    };
    const rows = d.cases.filter(F[f] || F.all).sort((a, b) => { const A = isOpenRow(a) ? 0 : 1, Bz = isOpenRow(b) ? 0 : 1; if (A !== Bz) return A - Bz; const la = (a.late || a.stage_late) ? 0 : 1, lb = (b.late || b.stage_late) ? 0 : 1; if (la !== lb) return la - lb; return b.id - a.id; });
    const chip = (key, t, n) => '<button type="button" class="cx-ch' + (f === key ? ' on' : '') + '" data-f="' + key + '">' + t + (n ? ' <b>' + n + '</b>' : '') + '</button>';
    const cnt = (key) => d.cases.filter(F[key]).length;
    let h = '';
    if (d.no_team) h += '<div class="cx-warn">Nobody sits on the desks yet, so new complaints have no owner. Open <b>Settings</b> and choose the people for each desk.</div>';
    if (!hideGuide) h += '<div class="cm-guide"><div style="font-size:22px">🧭</div><div><b>Start here</b> <span style="color:var(--mut)">— the 6 steps of your day</span><ol><li>Red rows first — they are late.</li><li>Phone rang? <b>☎ Log a call</b>: number + 2 lines.</li><li>Open a ticket, read Claude\'s line, tap the next step.</li><li>Pickup booked? Paste the AWB — the clock stops.</li><li>Parcel back? <b>Return check</b> with a photo.</li><li>Only after the check: store credit or refund.</li></ol></div><button class="x" type="button" id="cmHide">Hide ✕</button></div><div class="cm-guide-s"><span>🧭</span><span>New here? Read the 1-page guide.</span><a id="cmGo">Open</a></div>';
    h += '<div class="cm-kp">' + [['Open', k.open, ''], ['Late', k.late, k.late ? 'red' : ''], ['New today', k.new, ''], ['Pickup pending', k.pickup, k.pickup ? 'am' : ''], ['Waiting check', k.qc, k.qc ? 'am' : ''], ['To settle', k.settle, ''], ['Solved today', k.solved, ''], ['Credit today', money(k.credit) + (k.credit_wait ? ' · ' + k.credit_wait + ' wait' : ''), '']].map((x) => '<div class="cx-k ' + x[2] + '"><small>' + x[0] + '</small><b>' + x[1] + '</b></div>').join('') + '</div>';
    h += '<div class="cx-fl cm-fl"><span class="cm-dt"><button type="button" id="cmPrev">‹</button><input type="date" id="cmDate" value="' + esc(SD.date) + '"><button type="button" id="cmNext">›</button></span><div class="cm-chips">'
      + chip('all', 'All', d.cases.length) + chip('mine', 'Mine') + chip('late', '⏰ Late', cnt('late')) + chip('unowned', 'No owner', cnt('unowned')) + chip('wa', '💬 WhatsApp') + chip('call', '☎️ Calls') + chip('social', '📸 Social') + chip('store', '🏬 Store') + chip('track', '📍 Tracking / NDR') + chip('pickup', '🚚 Pickup') + chip('qc', '🔎 Check', cnt('qc')) + chip('settle', '💳 Settle') + chip('solved', '✔ Solved')
      + '</div><input class="cx-in" id="cmQ" placeholder="🔎 Name / phone / order / AWB / C-0012" value="' + esc(SD.q) + '" style="min-width:210px;flex:1;max-width:340px"></div>';
    h += '<div class="cm-wrap"><div class="cm-h"><div>Ticket</div><div>Time</div><div>From</div><div>Customer</div><div>Order · AWB</div><div>Problem</div><div>Desk</div><div>Owner</div><div>Stage</div><div>Due</div><div>Claude flag</div></div>';
    h += rows.length ? rows.map((c) => {
      const w = whenLabel(c); const ico = (c.sources && c.sources.length ? c.sources : [{ k: c.source }]).map((s) => SRCI[s.k] || '•').join('');
      const late = isOpenRow(c) && (c.late || c.stage_late);
      return '<div class="cm-r' + (late ? ' late' : w[0] === 'o' ? ' due' : '') + (isOpenRow(c) ? '' : ' done') + '" data-id="' + c.id + '"><div class="c-code">' + esc(c.code) + (c.reopen_n ? ' <span title="Reopened">↻</span>' : '') + '</div><div class="c-time">' + esc(String(c.raised_at).slice(0, 10) === d.date ? String(c.raised_at).slice(11, 16) : K.dm(c.raised_at)) + '</div><div class="c-from" title="' + esc((c.sources || []).map((s) => s.label).join(' + ')) + '">' + ico + '</div>'
        + '<div class="c-cust">' + esc(c.name) + '<small>' + esc(phoneFmt(c.phone)) + '</small></div><div class="c-ord">' + (c.order_name ? '#' + esc(String(c.order_name).replace(/^#/, '')) : '—') + '<small>' + esc(c.awb || '') + '</small></div>'
        + '<div class="c-prob">' + K.pill(c.problem_icon + ' ' + c.problem_label, c.problem === 'leak' || c.problem === 'quality' ? 'r' : c.problem === 'ndr' || c.problem === 'rto' || c.problem === 'tracking' || c.problem === 'notdelivered' ? 'o' : '') + '</div><div class="c-desk">' + esc(c.desk_label) + '</div>'
        + '<div class="c-own">' + (c.owner ? '<span class="cm-av" style="background:' + K.col(c.owner) + '">' + K.ini(c.owner_name) + '</span>' + esc((c.owner_name || '').split(' ')[0]) : '<span style="color:var(--red);font-weight:800">—</span>') + '</div>'
        + '<div class="c-stage">' + esc((c.stage_icon || '') + ' ' + (c.stage_label || '')) + '</div><div class="c-due cm-w ' + w[0] + '">' + esc(w[1]) + '</div><div class="c-flag" title="' + esc(c.flag || '') + '"><i>Claude</i>' + esc(c.flag || '') + '</div></div>';
    }).join('') : '<div class="cx-empty" style="background:#fff">Nothing in this view.</div>';
    h += '</div><div style="color:var(--mut);font-size:12px;margin-top:8px">All open tickets are always listed, plus everything raised or solved on ' + esc(SD.date) + '. Red = past its time · amber = due within hours.</div>';
    B.innerHTML = h;
    B.querySelectorAll('[data-f]').forEach((b) => b.onclick = () => { SD.f = b.dataset.f; drawToday(d); });
    B.querySelectorAll('.cm-r').forEach((r) => r.onclick = () => K.openCase(Number(r.dataset.id)));
    const gg = B.querySelector('#cmGo'); if (gg) gg.onclick = () => K.go('guide');
    const hd = B.querySelector('#cmHide'); if (hd) hd.onclick = () => { hideGuide = true; try { localStorage.setItem('cxGuideHide', '1'); } catch (e) { /* ok */ } drawToday(d); };
    const sh = (n) => { const t = new Date(SD.date + 'T12:00:00'); t.setDate(t.getDate() + n); SD.date = t.toISOString().slice(0, 10); today(); };
    B.querySelector('#cmPrev').onclick = () => sh(-1); B.querySelector('#cmNext').onclick = () => sh(1); B.querySelector('#cmDate').onchange = (e) => { if (e.target.value) { SD.date = e.target.value; today(); } };
    const qi = B.querySelector('#cmQ'); qi.oninput = () => { clearTimeout(SD.t); SD.t = setTimeout(async () => { SD.q = qi.value.trim(); const p = qi.selectionStart; await today(true); const n = K.body().querySelector('#cmQ'); if (n) { n.focus(); try { n.setSelectionRange(p, p); } catch (e) { /* ok */ } } }, 350); };
  }

  /* ------------------------------ Log a call ------------------------------ */
  function callSheet(o) {
    o = o || {}; let line = 'mobile', dir = 'in', lk = null;
    const m = K.modal('<h3>☎ Log a call</h3><div style="color:var(--mut);font-size:12.5px">One ticket per customer. If this number already has a ticket, the call is added to it.</div>'
      + '<label>Customer phone</label><input class="cx-in" id="clP" inputmode="tel" placeholder="98xxx xxxxx" value="' + esc(o.phone || '') + '"><div id="clInfo" class="cm-info">Type the 10-digit number.</div>'
      + '<div class="cm-seg" id="clLine"><button type="button" data-v="mobile" class="on">📱 Mobile</button><button type="button" data-v="landline">☎️ Landline</button></div><div class="cm-seg" id="clDir"><button type="button" data-v="in" class="on">Customer called us</button><button type="button" data-v="out">We called</button></div>'
      + '<label>Problem</label><select class="cx-sel" id="clPr"><option value="auto">Let Claude decide from what you write</option>' + PROB.map((p) => '<option value="' + p[0] + '">' + p[1] + ' ' + p[2] + '</option>').join('') + '</select>'
      + '<label>Order number (if known)</label><input class="cx-in" id="clO" list="clOl" placeholder="#TM…" value="' + esc(o.order || '') + '"><datalist id="clOl"></datalist>'
      + '<label>Customer name (only if new)</label><input class="cx-in" id="clN" value="' + esc(o.name || '') + '">'
      + '<label>What did the customer say?</label><textarea id="clT" rows="3" placeholder="Two lines are enough"></textarea>'
      + '<label>Call recording / photo (optional)</label><input type="file" id="clF" accept="audio/*,image/*" multiple style="width:100%;font-size:13px">'
      + '<div class="ac"><button class="cx-btn gh" type="button" id="clNo">Cancel</button><button class="cx-btn" type="button" id="clOk">Save call</button></div>');
    const g = (id) => m.el.querySelector(id), info = g('#clInfo');
    m.el.querySelectorAll('#clLine button').forEach((b) => b.onclick = () => { line = b.dataset.v; m.el.querySelectorAll('#clLine button').forEach((x) => x.classList.toggle('on', x === b)); });
    m.el.querySelectorAll('#clDir button').forEach((b) => b.onclick = () => { dir = b.dataset.v; m.el.querySelectorAll('#clDir button').forEach((x) => x.classList.toggle('on', x === b)); });
    g('#clNo').onclick = m.close;
    const look = async () => {
      const raw = g('#clP').value; const dg = raw.replace(/\D+/g, ''); if (dg.length < 10) { info.className = 'cm-info'; info.innerHTML = 'Type the 10-digit number.'; lk = null; return; }
      const d = await api.get('bd.cx_lookup', { phone: raw }).catch(() => null); if (!d || !d.valid || g('#clP').value !== raw) return; lk = d;
      let h = '<b>' + esc(d.name || 'New customer — no order found for this number') + '</b>'; let cls = 'cm-info';
      if (d.open) { cls += ' warn'; h += '<br>Open ticket <b>' + esc(d.open.code) + '</b> · ' + esc(d.open.problem) + ' · ' + esc(d.open.stage || d.open.desk) + ' · with ' + esc(d.open.owner || 'nobody') + '<small>This call will be added to that ticket.</small>'; }
      else if (d.recent) { cls += ' warn'; h += '<br>Solved recently: <b>' + esc(d.recent.code) + '</b> · ' + esc(d.recent.problem) + '<small>This call will open that ticket again.</small>'; }
      else h += '<br><small>No open ticket — a new one will be opened.</small>';
      if (d.orders && d.orders.length) h += '<small>' + d.orders.slice(0, 3).map((x) => '#' + esc(String(x.name).replace(/^#/, '')) + ' · ' + esc(x.at) + ' · ' + money(x.total) + ' · ' + esc(x.ship || x.pay || '')).join('<br>') + '</small>';
      if (d.balance && d.balance.balance > 0) h += '<small>💳 Store credit ' + money(d.balance.balance) + '</small>';
      if (d.earlier) h += '<small>' + d.earlier + ' earlier ticket' + (d.earlier > 1 ? 's' : '') + ' in 90 days</small>';
      info.className = cls; info.innerHTML = h;
      g('#clOl').innerHTML = (d.orders || []).map((x) => '<option value="' + esc(x.name) + '">').join(''); if (!g('#clO').value && d.order) g('#clO').value = d.order.name;
      if (!g('#clN').value && d.name) g('#clN').value = d.name;
    };
    let tm; g('#clP').oninput = () => { clearTimeout(tm); tm = setTimeout(look, 400); }; if (o.phone) look(); setTimeout(() => g('#clP').focus(), 50);
    g('#clOk').onclick = async () => {
      const b = g('#clOk'); b.disabled = true;
      const r = await K.call(() => api.post('bd.cx_call', { phone: g('#clP').value, line, dir, problem: g('#clPr').value, order_name: g('#clO').value.trim(), name: g('#clN').value.trim(), text: g('#clT').value.trim() }), false);
      if (!r) { b.disabled = false; return; }
      const files = [...(g('#clF').files || [])]; let bad = 0;
      for (const f of files) { const fd = new FormData(); fd.append('id', r.id); fd.append('kind', /^audio/.test(f.type) ? 'call' : 'other'); fd.append('file', f); try { await api.upload('bd.cx_upload', fd); } catch (e) { bad++; } }
      m.el.innerHTML = '<h3>✔ Call saved</h3><div class="cm-info good">' + esc(r.message) + (files.length ? '<small>' + (files.length - bad) + ' file' + (files.length - bad === 1 ? '' : 's') + ' attached' + (bad ? ', ' + bad + ' could not be added' : '') + '.</small>' : '') + '</div><div class="ac"><button class="cx-btn gh" type="button" id="clAgain">Log another call</button><button class="cx-btn" type="button" id="clOpen">Open ' + esc(r.code) + '</button></div>';
      m.el.querySelector('#clOpen').onclick = () => { m.close(); K.openCase(r.id); };
      m.el.querySelector('#clAgain').onclick = () => { m.close(); callSheet({}); };
      if (!K.C()) K.go(K.tab());
    };
  }

  /* ------------------------------ Customers ------------------------------ */
  async function custs() {
    K.body().innerHTML = '<div class="cx-fl"><input class="cx-in" id="cuQ" placeholder="🔎 Customer name or phone" style="min-width:240px;flex:1;max-width:380px"><button class="cx-btn gh s" type="button" id="cuCall">☎ Log a call</button></div><div id="cuL" class="cx-box"></div>';
    const q = K.body().querySelector('#cuQ'); K.body().querySelector('#cuCall').onclick = () => callSheet({});
    const load = async () => {
      const d = await K.call(() => api.get('bd.cx_customers', { q: q.value.trim() }), false); if (!d) return; const L = K.body().querySelector('#cuL');
      L.innerHTML = d.customers.length ? d.customers.map((c) => '<div class="cx-row" data-p="' + esc(c.phone) + '"><div class="m"><b>' + esc(c.name) + '</b><small>' + esc(phoneFmt(c.phone)) + ' · ' + c.n + ' ticket' + (c.n > 1 ? 's' : '') + ' · last ' + esc(c.ago) + ' ago</small></div>' + (c.repeat ? K.pill('Repeat ×' + c.n90, 'o') : '') + (c.open ? K.pill(c.open + ' open', 'r') : '') + (c.balance > 0 ? K.pill('💳 ' + money(c.balance), 'g') : '') + '</div>').join('') : '<div class="cx-empty">No customer found.</div>';
      L.querySelectorAll('.cx-row').forEach((r) => r.onclick = () => custSheet(r.dataset.p));
    };
    q.oninput = () => { clearTimeout(q.t); q.t = setTimeout(load, 300); }; load();
  }
  async function custSheet(phone) {
    const d = await K.call(() => api.get('bd.cx_customer', { phone }), false); if (!d) return;
    const m = K.modal('<div class="cm-bigbox"></div>'); m.el.classList.add('cm-big');
    const bal = d.balance || {};
    m.el.innerHTML = '<h3>' + esc(d.name || 'Customer') + ' <span style="font-weight:600;color:var(--mut);font-size:13px">' + esc(phoneFmt(d.phone)) + '</span></h3>'
      + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">' + K.pill(d.cases.length + ' ticket' + (d.cases.length === 1 ? '' : 's')) + K.pill('💳 Credit ' + money(bal.balance || 0), bal.balance > 0 ? 'g' : '') + (bal.waiting ? K.pill(money(bal.waiting) + ' waiting for the boss', 'o') : '') + '<button class="cx-btn gh s" type="button" id="csCall">☎ Log a call</button>' + (bal.balance > 0 ? '<button class="cx-btn gh s" type="button" id="csUse">Use credit</button>' : '') + '</div>'
      + '<div class="cx-box"><h4>Tickets</h4>' + (d.cases.map((c) => '<div class="cx-row" data-id="' + c.id + '"><div class="m"><b>' + esc(c.code) + ' · ' + c.problem_icon + ' ' + esc(c.problem_label) + '</b><small>' + esc(c.stage_label || '') + ' · ' + esc(c.ago) + ' ago' + (c.res_label ? ' · ' + esc(c.res_label) : '') + '</small></div>' + K.pill(K.STAT[c.status] || c.status, c.status === 'open' || c.status === 'reopened' ? 'b' : 'g') + '</div>').join('') || '<div class="cx-empty">None.</div>') + '</div>'
      + (d.orders && d.orders.length ? '<div class="cx-box"><h4>Orders</h4>' + d.orders.slice(0, 6).map((o) => '<div class="cx-ev"><b>#' + esc(String(o.name).replace(/^#/, '')) + '</b> · ' + esc(o.at) + ' · ' + money(o.total) + ' · ' + esc(o.ship || o.pay || '') + '</div>').join('') + '</div>' : '')
      + (d.ledger && d.ledger.length ? '<div class="cx-box"><h4>Store credit</h4>' + d.ledger.map((l) => '<div class="cx-ev"><b>' + (l.amount < 0 ? '−' : '+') + money(Math.abs(l.amount)) + '</b> ' + esc(l.kind === 'use' ? 'used' + (l.order ? ' on ' + l.order : '') : (BASIS[l.basis] || l.basis)) + (l.code ? ' · <b>' + esc(l.code) + '</b>' : '') + ' · ' + K.pill(l.status === 'ok' ? 'active' : l.status === 'wait' ? 'waiting boss' : l.status, l.status === 'ok' ? 'g' : 'o') + ' <small>' + K.dm(l.at) + '</small></div>').join('') + '</div>' : '')
      + '<div class="cx-box"><h4>Everything with this customer</h4>' + timelineHtml(d.timeline, true) + '</div><div class="ac"><button class="cx-btn gh" type="button" id="csNo">Close</button></div>';
    m.el.querySelector('#csNo').onclick = m.close; m.el.querySelector('#csCall').onclick = () => { m.close(); callSheet({ phone: d.phone, name: d.name }); };
    const u = m.el.querySelector('#csUse'); if (u) u.onclick = () => { m.close(); useSheet(d.phone, bal.balance); };
    m.el.querySelectorAll('.cx-row[data-id]').forEach((r) => r.onclick = () => { m.close(); K.openCase(Number(r.dataset.id)); });
  }
  function useSheet(phone, bal) {
    const m = K.modal('<h3>💳 Customer used store credit</h3><div class="cm-info">Available: <b>' + money(bal) + '</b><small>Write it down here when the customer spends it on an order, so the balance stays right.</small></div><label>Amount used (₹)</label><input class="cx-in" id="usA" type="number" inputmode="numeric" min="1" max="' + Math.round(bal) + '"><label>Order number</label><input class="cx-in" id="usO" placeholder="#TM…"><div class="ac"><button class="cx-btn gh" type="button" id="usNo">Cancel</button><button class="cx-btn" type="button" id="usOk">Save</button></div>');
    m.el.querySelector('#usNo').onclick = m.close; m.el.querySelector('#usOk').onclick = async () => { const r = await K.call(() => api.post('bd.cx_credit_use', { phone, amount: Number(m.el.querySelector('#usA').value) || 0, order: m.el.querySelector('#usO').value.trim() })); if (r) { m.close(); if (K.tab() === 'credit') credit(); } };
  }

  /* ------------------------------ Return check tab ------------------------------ */
  async function qcTab() {
    K.body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await K.call(() => api.get('bd.cx_qc_list'), false); if (!d) return;
    const row = (c, extra) => '<div class="cx-row" data-id="' + c.id + '"><div class="m"><b>' + esc(c.code) + ' · ' + esc(c.name) + '</b><small>' + c.problem_icon + ' ' + esc(c.problem_label) + (c.plan_label ? ' · ' + esc(c.plan_label) : '') + (c.order_name ? ' · #' + esc(String(c.order_name).replace(/^#/, '')) : '') + ' · ' + esc(c.stage_label || '') + '</small></div>' + (extra || '') + '</div>';
    let h = '<div class="cx-box"><h4>🔎 Parcels waiting for the check (' + d.waiting.length + ')</h4>' + (d.waiting.length ? d.waiting.map((c) => row(c, (c.stage_late ? K.pill('⏰ ' + c.stage_left + ' late', 'r') : K.pill(c.stage_left + ' left', 'o')) + '<button class="cx-btn s" type="button" data-qc="' + c.id + '">Check now</button>')).join('') : '<div class="cx-empty">Nothing waiting — good.</div>') + '</div>';
    h += '<div class="cx-box"><h4>🚚 On the way back (' + d.coming.length + ')</h4>' + (d.coming.length ? d.coming.map((c) => row(c, K.pill(c.stage_label, c.stage === 'pickup' ? (c.stage_late ? 'r' : 'o') : 'b'))).join('') : '<div class="cx-empty">No returns on the way.</div>') + '</div>';
    h += '<div class="cx-box"><h4>✔ Checked recently</h4>' + (d.done.length ? d.done.map((c) => row(c, K.pill(c.qc_label, c.qc_result === 'ok' ? 'g' : 'r') + '<small style="color:var(--mut)">' + esc(c.qc_by || '') + ' · ' + K.dm(c.qc_at) + '</small>')).join('') : '<div class="cx-empty">No checks yet.</div>') + '</div>';
    K.body().innerHTML = h;
    K.body().querySelectorAll('.cx-row').forEach((r) => r.onclick = () => K.openCase(Number(r.dataset.id)));
    K.body().querySelectorAll('[data-qc]').forEach((b) => b.onclick = async (e) => { e.stopPropagation(); const x = await K.call(() => api.get('bd.cx_case', { id: Number(b.dataset.qc) }), false); if (x) qcSheet(x, () => qcTab()); });
  }
  /** the return check: photos first (taken here with the camera), then the verdict */
  function qcSheet(X, after) {
    const v = X.case, id = v.id; let result = '', shots = (X.files || []).filter((f) => f.kind === 'qc').length;
    const R = X.qc_results || { ok: 'OK — as sent', damaged: 'Damaged / used', notours: 'Not our product', missing: 'Item missing / empty' };
    const m = K.modal('<h3>🔎 Return check · ' + esc(v.code) + '</h3><div style="color:var(--mut);font-size:13px">' + esc(v.name) + (v.order_name ? ' · #' + esc(String(v.order_name).replace(/^#/, '')) : '') + (v.plan_label ? ' · ' + esc(v.plan_label) : '') + '</div>'
      + '<label>1 · Photos of the returned product <span id="qcN" style="text-transform:none;letter-spacing:0">(' + shots + ' added)</span></label><div class="cm-up"><button class="cx-btn gh s" type="button" id="qcCam">📷 Take / add photo</button></div>'
      + '<label>2 · What did you find?</label><div class="cm-plans" id="qcR" style="grid-template-columns:1fr 1fr">' + Object.keys(R).map((k) => '<button type="button" data-r="' + k + '">' + esc(R[k]) + '</button>').join('') + '</div>'
      + '<label>Batch code on the bottle (optional)</label><input class="cx-in" id="qcB" placeholder="e.g. B-1204"><label>Note</label><textarea id="qcT" rows="2" placeholder="Seal, cap, smell, box condition…"></textarea><div class="cm-info warn" style="margin-top:10px">Store credit or refund can be given only after this check is saved.</div>'
      + '<div class="ac"><button class="cx-btn gh" type="button" id="qcNo">Cancel</button><button class="cx-btn" type="button" id="qcOk">Save check</button></div>');
    m.el.querySelector('#qcNo').onclick = m.close;
    m.el.querySelector('#qcCam').onclick = () => pick('qc', true, async (n) => { shots += n; m.el.querySelector('#qcN').textContent = '(' + shots + ' added)'; }, id);
    m.el.querySelectorAll('#qcR button').forEach((b) => b.onclick = () => { result = b.dataset.r; m.el.querySelectorAll('#qcR button').forEach((x) => { x.style.borderColor = x === b ? '#00a884' : ''; x.style.background = x === b ? '#e7fbf3' : ''; }); });
    m.el.querySelector('#qcOk').onclick = async () => {
      if (!result) { K.toast('Choose what you found', true); return; } const b = m.el.querySelector('#qcOk'); b.disabled = true;
      const r = await K.call(() => api.post('bd.cx_qc', { id, result, batch: m.el.querySelector('#qcB').value.trim(), note: m.el.querySelector('#qcT').value.trim() })); if (!r) { b.disabled = false; return; }
      m.close(); if (after) after(); else if (K.C()) K.refresh();
    };
  }
  /** choose / take photos → upload (kind: pickup | qc | call | other) */
  function pick(kind, photo, done, id) {
    const inp = document.createElement('input'); inp.type = 'file'; inp.multiple = true; inp.accept = photo ? 'image/*' : kind === 'call' ? 'audio/*' : 'image/*,audio/*'; if (photo) inp.setAttribute('capture', 'environment');
    inp.onchange = async () => {
      let ok = 0; const caseId = id || (K.C() && K.C().case.id); if (!caseId) return;
      for (const f of inp.files) { const fd = new FormData(); fd.append('id', caseId); fd.append('kind', kind); fd.append('file', f); try { await api.upload('bd.cx_upload', fd); ok++; } catch (e) { K.toast(e.message || 'Could not add the file', true); } }
      if (ok) { K.toast(ok + ' added.'); if (done) await done(ok); if (K.C() && K.C().case.id === caseId) K.refresh(); }
    };
    inp.click();
  }

  /* ------------------------------ Store credit ------------------------------ */
  async function credit() {
    K.body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await K.call(() => api.get('bd.cx_credit_tab'), false); if (!d) return; const k = d.kpi;
    let h = '<div class="cx-kpis cm-k4">' + [['Given this month', money(k.issued_month)], ['Used this month', money(k.used_month)], ['Customers still hold', money(k.open_balance)], ['Waiting for the boss', k.waiting]].map((x) => '<div class="cx-k"><small>' + x[0] + '</small><b>' + x[1] + '</b></div>').join('') + '</div>';
    h += '<div class="cm-info">Store credit is given <b>only after the returned product is checked</b> (or as goodwill / for a lost parcel / for a courier fault). Up to ' + money(d.limits.credit) + ' the team can give it; goodwill up to ' + money(d.limits.goodwill) + '; above that the boss approves.</div>';
    if (d.pending.length) h += '<div class="cx-box"><h4>⏳ Waiting for the boss (' + d.pending.length + ')</h4>' + d.pending.map((p) => '<div class="cx-row" data-id="' + p.case_id + '"><div class="m"><b>' + money(p.amount) + ' · ' + esc(p.name || phoneFmt(p.phone)) + '</b><small>' + esc(BASIS[p.basis] || p.basis) + (p.reason ? ' — ' + esc(p.reason) : '') + ' · ' + esc(p.case) + ' · by ' + esc(p.by) + '</small></div>' + (d.mgr ? '<button class="cx-btn s" type="button" data-ok="' + p.id + '">Approve</button><button class="cx-btn red s" type="button" data-no="' + p.id + '">Reject</button>' : K.pill('boss decides', 'o')) + '</div>').join('') + '</div>';
    h += '<div class="cx-box"><h4>Customers with credit</h4>' + (d.customers.length ? d.customers.map((c) => '<div class="cx-row" data-p="' + esc(c.phone) + '"><div class="m"><b>' + esc(c.name || phoneFmt(c.phone)) + '</b><small>' + esc(phoneFmt(c.phone)) + (c.expires ? ' · valid till ' + dy(c.expires) : '') + '</small></div>' + K.pill(money(c.balance), 'g') + '<button class="cx-btn gh s" type="button" data-use="' + esc(c.phone) + '" data-b="' + c.balance + '">Used</button></div>').join('') : '<div class="cx-empty">Nobody holds credit now.</div>') + '</div>';
    h += '<div class="cx-box"><h4>Ledger</h4>' + (d.ledger.length ? d.ledger.map((l) => '<div class="cx-row" ' + (l.case_id ? 'data-id="' + l.case_id + '"' : '') + '><div class="m"><b>' + (l.amount < 0 ? '−' : '+') + money(Math.abs(l.amount)) + ' · ' + esc(l.name || phoneFmt(l.phone)) + '</b><small>' + (l.kind === 'use' ? 'used' + (l.order ? ' on ' + esc(l.order) : '') : esc(BASIS[l.basis] || l.basis)) + (l.code ? ' · ' + esc(l.code) : '') + (l.case ? ' · ' + esc(l.case) : '') + ' · ' + esc(l.by) + ' · ' + K.dm(l.at) + '</small></div>' + K.pill(l.status === 'ok' ? 'active' : l.status === 'wait' ? 'waiting' : l.status, l.status === 'ok' ? 'g' : 'o') + (d.mgr && l.kind === 'issue' && (l.status === 'ok' || l.status === 'wait') ? '<button class="cx-btn red s" type="button" data-void="' + l.id + '">Cancel</button>' : '') + '</div>').join('') : '<div class="cx-empty">No entries yet.</div>') + '</div>';
    K.body().innerHTML = h;
    const B = K.body(); B.querySelectorAll('.cx-row[data-id]').forEach((r) => { if (r.dataset.id && r.dataset.id !== '0') r.onclick = () => K.openCase(Number(r.dataset.id)); }); B.querySelectorAll('.cx-row[data-p]').forEach((r) => r.onclick = () => custSheet(r.dataset.p));
    const act = (sel, fn) => B.querySelectorAll(sel).forEach((b) => b.onclick = async (e) => { e.stopPropagation(); await fn(b); credit(); });
    act('[data-ok]', (b) => K.call(() => api.post('bd.cx_credit_ok', { credit_id: Number(b.dataset.ok) }))); act('[data-no]', (b) => K.call(() => api.post('bd.cx_credit_ok', { credit_id: Number(b.dataset.no), reject: 1 }))); act('[data-void]', (b) => confirm('Cancel this credit?') ? K.call(() => api.post('bd.cx_credit_void', { credit_id: Number(b.dataset.void) })) : null);
    B.querySelectorAll('[data-use]').forEach((b) => b.onclick = (e) => { e.stopPropagation(); useSheet(b.dataset.use, Number(b.dataset.b)); });
  }
  function creditSheet(X) {
    const v = X.case, tot = X.order_total, L = X.limits || {};
    const m = K.modal('<h3>💳 Give store credit · ' + esc(v.code) + '</h3><div class="cm-info">Balance now: <b>' + money((X.balance || {}).balance || 0) + '</b>' + (tot ? '<small>Order value ' + money(tot) + '</small>' : '') + '</div>'
      + '<label>Why</label><select class="cx-sel" id="crB">' + Object.keys(BASIS).map((k) => '<option value="' + k + '">' + esc(BASIS[k]) + '</option>').join('') + '</select><label>Amount (₹)</label><input class="cx-in" id="crA" type="number" inputmode="numeric" min="1" value="' + (tot ? Math.round(tot) : '') + '"><label>Reason / note</label><textarea id="crR" rows="2" placeholder="Required for goodwill"></textarea>'
      + '<div style="font-size:12px;color:var(--mut);margin-top:6px">The team can give up to ' + money(L.credit) + ' (goodwill ' + money(L.goodwill) + '). Above that the boss is asked.</div><div class="ac"><button class="cx-btn gh" type="button" id="crNo">Cancel</button><button class="cx-btn" type="button" id="crOk">Give credit</button></div>');
    m.el.querySelector('#crNo').onclick = m.close; if (X.qc && X.qc.length) m.el.querySelector('#crB').value = 'qc'; else m.el.querySelector('#crB').value = ['notdelivered', 'tracking', 'ndr', 'rto'].indexOf(v.problem) > -1 ? 'lost' : 'goodwill';
    m.el.querySelector('#crOk').onclick = async () => { const b = m.el.querySelector('#crOk'); b.disabled = true; const r = await K.call(() => api.post('bd.cx_credit_issue', { id: v.id, basis: m.el.querySelector('#crB').value, amount: Number(m.el.querySelector('#crA').value) || 0, reason: m.el.querySelector('#crR').value.trim() })); if (r) { m.close(); K.refresh(); } else b.disabled = false; };
  }

  /* ------------------------------ Start here ------------------------------ */
  async function guide() {
    K.body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const [d, s] = await Promise.all([K.call(() => api.get('bd.cx_guide'), false), api.get('bd.cx_settings').catch(() => null)]); if (!d) return; const c = (s && s.cfg) || {}; const pen = d.pen || {};
    let h = '<div class="cx-box cm-gd"><h4>🧭 Start here — the Complaints desk in one page</h4><div>One customer = one ticket, keyed by the phone number. A WhatsApp message, a phone call, an Instagram DM or an e-mail from the same person all land in <b>the same ticket</b>. If they write again after it is solved (within ' + (c.reopen_days || 7) + ' days), the same ticket opens again.</div>'
      + '<h3>Your day, step by step</h3><ol><li>Open <b>Today</b>. Red rows are late — start there. Everything else is sorted by time.</li><li>A customer phones (mobile or landline)? Tap <b>☎ Log a call</b>, type the number. Claude shows who it is and whether a ticket already exists. Write two lines. Attach the recording if you have it.</li><li>Open a ticket. At the top, <b>Claude</b> has already read the order, the tracking and the messages and says what happened and what to do next. Tap a suggested step.</li><li><b>Decide the fix</b>: Exchange, Resend, Refund, Store credit, or just Explain. The ticket moves to the right desk by itself.</li><li>Product must come back? Book the reverse pickup, paste the tracking ID (AWB) and add the <b>pickup photo</b>. Tracking updates the ticket by itself.</li><li>Parcel is back: <b>Return check</b> — photograph the product, choose OK / damaged / not our product / missing.</li><li>Only after the check: give <b>store credit</b> (or refund). Credit above the limit waits for the boss.</li><li>Press <b>Mark solved</b>, choose the root cause (courier, packing, product…). The bot can ask the customer “are you happy?”.</li></ol>'
      + '<h3>Which problem goes to which desk</h3><ul><li>📍 Tracking ID, NDR, RTO, Not delivered → <b>Tracking / NDR / RTO</b> desk</li><li>💧 Leak, 🔁 Wrong item, 🙁 Not liked, 👃 Smell, 💰 Refund, 🙍 Staff → <b>Customer Care</b> first, then the ticket moves by its stage: Reverse pickup → Return check → Exchange / Resend / Credit-Refund</li></ul>'
      + '<h3>The clocks (and what happens when one runs out)</h3><ul><li>Reverse pickup must be booked within <b>' + d.pickup_hours + ' h</b> of the fix being decided.</li><li>A returned parcel must be checked within <b>' + d.qc_hours + ' h</b> of arriving.</li><li>Credit / refund / replacement must be given within <b>' + d.settle_hours + ' h</b> of the check.</li><li>The customer must get the first reply within the time of its urgency (Settings).</li><li>If a clock runs out, the boss gets an alert in the Boss Engine' + (pen.on ? ' and a <b>penalty request</b> is raised for the person who owns the ticket: ₹' + pen.pickup + ' pickup, ₹' + pen.qc + ' check, ₹' + pen.settle + ' settle, ₹' + pen.reply + ' no reply. The boss decides in Boss Engine → Penalties; nobody is charged automatically.' : '. (Penalties are switched off in Settings.)') + '</li></ul>'
      + '<h3>Store credit rules</h3><ul><li>Given <b>after</b> the return check, as goodwill (needs a reason), for a parcel that never reached the customer, or for a courier fault (we then claim the money from the courier).</li><li>Team limit ' + money(c.credit_limit || 1000) + ' (goodwill ' + money(c.goodwill_limit || 500) + '); above that the boss approves. Valid ' + (c.credit_days || 365) + ' days. Each credit has a code like TMC-AB12CD.</li><li>The website cannot read the credit yet, so when a customer spends it, tap <b>Used</b> in Customers / Credit.</li></ul>'
      + '<h3>Instagram / Google / e-mail names</h3><div>A ticket without a phone number shows <b>“Possible customer”</b> when the name matches an order. A person confirms with one tap — Claude never links by itself.</div></div>';
    h += '<div class="cx-box"><h4>Is everything ready?</h4>' + d.checks.map((x) => '<div class="cm-chk' + (x.ok ? '' : ' no') + '"><i>' + (x.ok ? '✓' : '!') + '</i><div>' + esc(x.text) + (x.ok ? '' : '<small>Fix: ' + esc(x.fix) + '</small>') + '</div></div>').join('') + '</div>';
    h += '<div><button class="cx-btn gh s" type="button" id="cmShow">Show the short guide on Today again</button></div>';
    K.body().innerHTML = h; K.body().querySelector('#cmShow').onclick = () => { hideGuide = false; try { localStorage.removeItem('cxGuideHide'); } catch (e) { /* ok */ } K.toast('The short guide is back on Today.'); };
  }

  /* ------------------------------ inside a ticket ------------------------------ */
  function timelineHtml(items, withCase) {
    const IC = { wa_in: '💬', wa_out: '↗️', social: '📸', call: '☎️', file: '📎', stage: '📍', plan: '🛠', credit: '💳', claim: '🧾', qc: '🔎', alert: '⏰', note: '🔒', desk: '➡️', owner: '👤', raised: '🆕', resolve: '✔', reopen: '↻', link: '🚚', cause: '🏷' };
    if (!items || !items.length) return '<div style="color:var(--mut)">Nothing yet.</div>';
    return items.map((e) => '<div class="cm-lg"><i>' + (IC[e.kind] || '•') + '</i><div><small>' + K.dm(e.at) + ' ' + K.hm(e.at) + (e.by ? ' · ' + esc(e.by) : '') + '</small>' + (e.kind === 'wa_in' ? 'Customer: ' : '') + esc(e.text) + (withCase && e.case ? '<span class="cs">' + esc(e.case) + '</span>' : '') + '</div></div>').join('');
  }
  function top(X) {
    const v = X.case, w = X.can_work, open = K.isOpen(); let h = '';
    if (!v.phone && X.match && X.match.length && w && open) h += '<div class="cm-info warn"><b>Possible customer</b> (the name matches an order — confirm before linking)<br>' + X.match.slice(0, 3).map((x) => '<div style="display:flex;gap:8px;align-items:center;margin-top:6px"><span style="flex:1;min-width:0"><b>' + esc(x.name) + '</b> · ' + esc(phoneFmt(x.phone)) + '<small>' + (x.order ? '#' + esc(String(x.order).replace(/^#/, '')) + ' · ' : '') + esc(x.at || '') + ' · ' + money(x.total || 0) + '</small></span><button class="cx-btn s" type="button" data-link="' + esc(x.phone) + '">This is them</button></div>').join('') + '</div>';
    if (X.flag && X.flag.text) h += '<div class="cm-flag"><h4>🤖 Claude' + (X.flag.ai ? '' : ' <span style="font-weight:600;text-transform:none;letter-spacing:0;color:#8a78b8">· built-in</span>') + (X.flag.cause_label ? ' <span style="margin-left:auto">' + K.pill('Likely cause: ' + X.flag.cause_label, 'p') + '</span>' : '') + '</h4><p>' + esc(X.flag.text) + '</p>' + (w && open && X.flag.next && X.flag.next.length ? '<div class="nx">' + X.flag.next.map((n) => '<button type="button" data-nx="' + esc(n.k) + '">' + esc(n.label) + '</button>').join('') + '</div>' : '') + (w ? '<div style="margin-top:8px"><button class="cx-btn gh s" type="button" id="cmReflag">↻ Read again</button></div>' : '') + '</div>';
    const need = v.need_return !== 0 && v.plan; const seq = v.plan && !need ? ['new', 'fix', 'ready'] : STAGES; const cur = v.stage || 'new', ci = Math.max(0, seq.indexOf(cur));
    if (open || v.stage === 'done') h += '<div class="cx-box" style="padding-bottom:10px"><h4>Where it is</h4><div class="cm-stg">' + seq.map((s, i) => '<div class="' + (i < ci ? 'd' : i === ci ? 'n' + (v.stage_late ? ' l' : '') : '') + '">' + SLAB[s] + '</div>').join('') + '</div>' + (v.stage_due && open ? '<div style="font-size:12.5px" class="cm-w ' + (v.stage_late ? 'r' : 'g') + '">' + (v.stage_late ? '⏰ ' + esc(v.stage_left) + ' over the limit' : '⏳ ' + esc(v.stage_left) + ' left for this step') + '</div>' : '')
      + (v.plan ? '<div style="margin-top:6px">' + K.pill('Fix: ' + v.plan_label, 'b') + ' ' + K.pill(v.need_return ? 'Product must come back' : 'No return needed', v.need_return ? 'o' : 'g') + (w && open ? ' <button class="cx-btn gh s" type="button" data-plan="change">Change</button>' : '') + '</div>' : (w && open ? '<div style="margin-top:6px"><b>Decide the fix</b><div class="cm-plans">' + Object.keys(PLANS).map((k) => '<button type="button" data-plan="' + k + '">' + esc(PLANS[k]) + '</button>').join('') + '</div></div>' : '')) + '</div>';
    return h;
  }
  function mid(X) {
    const v = X.case, w = X.can_work, open = K.isOpen(); let h = '';
    if (w || (X.files && X.files.length)) {
      const imgs = (X.files || []).filter((f) => !f.audio), aud = (X.files || []).filter((f) => f.audio); const KL = { pickup: 'Pickup', qc: 'Return', other: 'Photo', call: 'Call' };
      h += '<div class="cx-box"><h4>📎 Photos &amp; recordings</h4>' + (imgs.length ? '<div class="cm-ph">' + imgs.map((f) => '<a data-fid="' + f.id + '" title="' + esc(f.by + ' · ' + f.at) + '"><img alt=""><em>' + esc(KL[f.kind] || 'Photo') + '</em></a>').join('') + '</div>' : '') + aud.map((f) => '<div style="margin:6px 0"><small style="color:var(--mut)">🎙 ' + esc(f.by) + ' · ' + K.dm(f.at) + '</small><br><audio controls data-aid="' + f.id + '" style="max-width:100%"></audio></div>').join('') + (!imgs.length && !aud.length ? '<div style="color:var(--mut)">Nothing attached yet.</div>' : '')
        + (w ? '<div class="cm-up"><button class="cx-btn gh s" type="button" data-up="pickup">📷 Pickup photo</button><button class="cx-btn gh s" type="button" data-up="qc">📷 Return photo</button><button class="cx-btn gh s" type="button" data-up="call">🎙 Call recording</button><button class="cx-btn gh s" type="button" data-up="other">📎 Other</button></div>' : '') + '</div>';
    }
    if (v.plan && (v.need_return || (X.qc && X.qc.length))) {
      h += '<div class="cx-box"><h4>🔎 Return check</h4>' + (X.qc && X.qc.length ? X.qc.map((q) => '<div class="cx-ord" style="margin-bottom:6px">' + K.pill(q.label, q.result === 'ok' ? 'g' : 'r') + ' <small style="display:inline">' + esc(q.by) + ' · ' + K.dm(q.at) + (q.batch ? ' · batch ' + esc(q.batch) : '') + '</small>' + (q.note ? '<small>' + esc(q.note) + '</small>' : '') + '</div>').join('') : '<div style="color:var(--mut)">Not checked yet. ' + (v.stage === 'qc' ? '<b style="color:#9a6200">The parcel is here — check it now.</b>' : 'It happens when the parcel is back.') + '</div>') + (w && open ? '<div style="margin-top:6px"><button class="cx-btn' + (v.stage === 'qc' ? '' : ' gh') + ' s" type="button" id="cmQc">🔎 ' + (X.qc && X.qc.length ? 'Check again' : 'Do the return check') + '</button></div>' : '') + '</div>';
    }
    if (v.phone && (w || (X.credits && X.credits.length))) {
      const bl = X.balance || {};
      h += '<div class="cx-box"><h4>💳 Store credit</h4><div style="margin-bottom:6px">' + K.pill('Balance ' + money(bl.balance || 0), bl.balance > 0 ? 'g' : '') + (bl.waiting ? ' ' + K.pill(money(bl.waiting) + ' waiting for the boss', 'o') : '') + '</div>' + (X.credits && X.credits.length ? X.credits.map((c) => '<div class="cx-ord" style="margin-bottom:6px"><b>' + money(c.amount) + '</b> · ' + esc(BASIS[c.basis] || c.basis) + ' · ' + K.pill(c.status === 'ok' ? 'active' : c.status === 'wait' ? 'waiting for the boss' : c.status, c.status === 'ok' ? 'g' : 'o') + '<small>' + [(c.status === 'ok' && c.code ? 'Code <b>' + esc(c.code) + '</b>' : ''), esc(c.by), K.dm(c.at), c.reason ? esc(c.reason) : ''].filter(Boolean).join(' · ') + '</small>' + (X.mgr && c.status === 'wait' ? '<div style="margin-top:6px"><button class="cx-btn s" type="button" data-cok="' + c.id + '">Approve</button> <button class="cx-btn red s" type="button" data-cno="' + c.id + '">Reject</button></div>' : '') + '</div>').join('') : '') + (w && (open || X.mgr) ? '<button class="cx-btn gh s" type="button" id="cmCr">＋ Give store credit</button>' : '') + '</div>';
    }
    if (w || v.cause || v.claim) {
      h += '<div class="cx-box"><h4>🏷 Root cause &amp; courier claim</h4><label style="display:block;font-size:11px;font-weight:800;color:var(--mut);margin:0 0 4px">Why did it happen?</label><select class="cx-sel" id="cmCause"' + (w ? '' : ' disabled') + ' style="width:100%;border-radius:12px"><option value="">— not set (you can fix this later) —</option>' + Object.keys(X.causes || {}).map((k) => '<option value="' + k + '"' + (v.cause === k ? ' selected' : '') + '>' + esc(X.causes[k]) + '</option>').join('') + '</select>'
        + '<label style="display:block;font-size:11px;font-weight:800;color:var(--mut);margin:10px 0 4px">Courier claim</label><div style="display:flex;gap:6px;flex-wrap:wrap"><select class="cx-sel" id="cmCl"' + (w ? '' : ' disabled') + '>' + [['', 'No claim'], ['filed', 'Filed'], ['received', 'Money received'], ['rejected', 'Rejected']].map((x) => '<option value="' + x[0] + '"' + ((X.claim || {}).status === x[0] ? ' selected' : '') + '>' + x[1] + '</option>').join('') + '</select><input class="cx-in" id="cmClA" type="number" min="0" placeholder="₹" value="' + ((X.claim || {}).amount || '') + '" style="width:100px"' + (w ? '' : ' disabled') + '><input class="cx-in" id="cmClR" placeholder="Claim no." value="' + esc((X.claim || {}).ref || '') + '" style="flex:1;min-width:110px"' + (w ? '' : ' disabled') + '>' + (w ? '<button class="cx-btn gh s" type="button" id="cmClS">Save</button>' : '') + '</div><div style="font-size:12px;color:var(--mut);margin-top:6px">The customer is paid by store credit; the claim brings the money back from the courier.</div></div>';
    }
    return h;
  }
  function timeline(X) { return '<div class="cx-box"><h4>Timeline — chat, calls, social and steps</h4>' + timelineHtml(X.timeline, true) + '</div>'; }
  async function blobFile(id) {
    if (blobs[id]) return blobs[id];
    const r = await fetch(api.buildUrl('bd.cx_file', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('file');
    return (blobs[id] = URL.createObjectURL(await r.blob()));
  }
  function bind(el) {
    const X = K.C(), v = X.case, q = (s, fn) => el.querySelectorAll(s).forEach(fn);
    el.querySelectorAll('.cm-ph a').forEach(async (a) => { try { const u = await blobFile(a.dataset.fid); a.querySelector('img').src = u; a.onclick = () => window.open(u, '_blank'); } catch (e) { a.style.opacity = .4; } });
    el.querySelectorAll('audio[data-aid]').forEach(async (a) => { try { a.src = await blobFile(a.dataset.aid); } catch (e) { a.replaceWith(document.createTextNode('Recording could not be loaded')); } });
    q('[data-up]', (b) => b.onclick = () => pick(b.dataset.up, b.dataset.up === 'pickup' || b.dataset.up === 'qc', null));
    q('[data-plan]', (b) => b.onclick = () => planSheet(b.dataset.plan === 'change' ? '' : b.dataset.plan));
    q('[data-link]', (b) => b.onclick = async () => { const r = await K.call(() => api.post('bd.cx_link_phone', { id: v.id, phone: b.dataset.link })); if (r) { if (r.id && r.id !== v.id) K.openCase(r.id); else K.refresh(); } });
    q('[data-nx]', (b) => b.onclick = () => nextStep(b.dataset.nx));
    q('[data-cok]', (b) => b.onclick = async () => { const r = await K.call(() => api.post('bd.cx_credit_ok', { credit_id: Number(b.dataset.cok) })); if (r) K.refresh(); });
    q('[data-cno]', (b) => b.onclick = async () => { const r = await K.call(() => api.post('bd.cx_credit_ok', { credit_id: Number(b.dataset.cno), reject: 1 })); if (r) K.refresh(); });
    const rf = el.querySelector('#cmReflag'); if (rf) rf.onclick = async () => { rf.disabled = true; await K.call(() => api.post('bd.cx_flag', { id: v.id, refresh: 1 })); K.refresh(); };
    const qc = el.querySelector('#cmQc'); if (qc) qc.onclick = () => qcSheet(X);
    const cr = el.querySelector('#cmCr'); if (cr) cr.onclick = () => creditSheet(X);
    const ca = el.querySelector('#cmCause'); if (ca) ca.onchange = async () => { await K.call(() => api.post('bd.cx_cause', { id: v.id, cause: ca.value }), 'Saved.'); };
    const cs = el.querySelector('#cmClS'); if (cs) cs.onclick = async () => { const r = await K.call(() => api.post('bd.cx_claim', { id: v.id, status: el.querySelector('#cmCl').value, amount: Number(el.querySelector('#cmClA').value) || 0, ref: el.querySelector('#cmClR').value.trim() })); if (r) K.refresh(); };
  }
  function nextStep(k) {
    const X = K.C(), v = X.case;
    if (k.indexOf('plan:') === 0) return planSheet(k.slice(5));
    if (k === 'qc') return qcSheet(X);
    if (k === 'edit') return K.editSheet();
    if (k === 'pickup') return K.deskSheet('pickup');
    if (k === 'settle') return v.plan === 'refund' ? K.deskSheet('refund') : v.plan === 'exchange' ? K.deskSheet('exchange') : v.plan === 'resend' ? K.deskSheet('resend') : creditSheet(X);
    if (k === 'close') return K.resolveSheet();
    if (k === 'call') return callSheet({ phone: v.phone, name: v.name, order: v.order_name });
    if (k === 'tracking') { K.toast('Check the parcel on the Dispatch page, or call the courier, then write what they said as a note.'); return; }
    if (k === 'ask_photo') { const t = document.querySelector('#cxTx'); if (t) { t.value = 'Sorry for the trouble, ' + String(v.name || '').split(' ')[0] + '. Could you please send a clear photo of the product and the parcel box? We will sort it out right away.'; t.focus(); t.oninput && t.oninput(); const sw = document.querySelector('.cx-seg button[data-v="r"]'); if (sw) sw.click(); } else K.toast('Ask the customer for photos on the phone, then add them with “Other”.'); }
  }
  function planSheet(kind) {
    const X = K.C(), v = X.case; let plan = kind || v.plan || '';
    const m = K.modal('<h3>🛠 Decide the fix</h3><div class="cm-plans" id="plK">' + Object.keys(PLANS).map((k) => '<button type="button" data-k="' + k + '">' + esc(PLANS[k]) + '</button>').join('') + '</div><div id="plR" style="margin-top:10px"></div><label>Note for the next person</label><textarea id="plN" rows="2"></textarea><div class="ac"><button class="cx-btn gh" type="button" id="plNo">Cancel</button><button class="cx-btn" type="button" id="plOk">Save</button></div>');
    const defRet = { exchange: 1, resend: 0, refund: 1, credit: 1, explain: 0 };
    const draw = () => { m.el.querySelectorAll('#plK button').forEach((b) => { const on = b.dataset.k === plan; b.style.borderColor = on ? '#00a884' : ''; b.style.background = on ? '#e7fbf3' : ''; }); const r = m.el.querySelector('#plR'); r.innerHTML = plan ? '<label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0;font-size:14px;color:#111b21;font-weight:600"><input type="checkbox" id="plRet"' + (defRet[plan] ? ' checked' : '') + '> The product must come back first (reverse pickup + return check)</label>' + (plan === 'refund' || plan === 'credit' ? '<div style="font-size:12px;color:var(--mut);margin-top:4px">Refund and store credit are given only after the returned product is checked.</div>' : '') : ''; };
    m.el.querySelectorAll('#plK button').forEach((b) => b.onclick = () => { plan = b.dataset.k; draw(); }); draw();
    m.el.querySelector('#plNo').onclick = m.close;
    m.el.querySelector('#plOk').onclick = async () => { if (!plan) { K.toast('Choose the fix', true); return; } const rt = m.el.querySelector('#plRet'); const r = await K.call(() => api.post('bd.cx_plan', { id: v.id, plan, need_return: rt && rt.checked ? 1 : 0, note: m.el.querySelector('#plN').value.trim() })); if (r) { m.close(); K.refresh(); } };
  }

  /* ------------------------------ small hooks for cx_app.js ------------------------------ */
  function causeSelect(v) { return '<label>Root cause — why did it happen?</label><select class="cx-sel" id="rsCause"><option value="">— not sure yet (fix later) —</option>' + Object.keys((K.C() || {}).causes || {}).map((k) => '<option value="' + k + '"' + (v.cause === k ? ' selected' : '') + '>' + esc(K.C().causes[k]) + '</option>').join('') + '</select>'; }
  function settingsBox(c, mg, dis) {
    const n = (id, label, key, w) => '<div style="display:flex;gap:8px;align-items:center;margin:6px 0"><span style="flex:1;min-width:0">' + label + '</span><input class="cx-in" type="number" min="0" id="v2_' + id + '" value="' + esc(c[key]) + '" style="width:' + (w || 90) + 'px"' + dis + '></div>';
    return '<div class="cx-box"><h4>Step deadlines, penalties and store credit</h4><div style="color:var(--mut);margin-bottom:6px">When a step runs over its time, the boss is alerted in the Boss Engine and a penalty request is raised — <b>the boss decides there</b>, nothing is charged by itself.</div>'
      + n('pickup_hours', 'Book the reverse pickup within (hours)', 'pickup_hours') + n('qc_hours', 'Check the returned parcel within (hours)', 'qc_hours') + n('settle_hours', 'Give credit / refund / replacement within (hours after the check)', 'settle_hours')
      + '<label style="display:flex;gap:8px;align-items:center;margin:10px 0 4px"><input type="checkbox" id="v2_pen_on"' + (c.pen_on === '1' ? ' checked' : '') + dis + '> Ask the boss for a penalty when a step is late</label>'
      + n('pen_pickup', 'Penalty ask — pickup not booked (₹)', 'pen_pickup') + n('pen_qc', 'Penalty ask — parcel not checked (₹)', 'pen_qc') + n('pen_settle', 'Penalty ask — credit / refund not given (₹)', 'pen_settle') + n('pen_reply', 'Penalty ask — customer not answered in time (₹)', 'pen_reply')
      + '<div style="border-top:1px solid var(--ln);margin:10px 0 4px"></div>' + n('credit_limit', 'Store credit the team can give by itself, up to (₹)', 'credit_limit') + n('goodwill_limit', 'Goodwill credit the team can give, up to (₹)', 'goodwill_limit') + n('credit_days', 'Store credit valid for (days)', 'credit_days') + n('reopen_days', 'A solved ticket opens again if the customer writes within (days)', 'reopen_days')
      + '<label style="display:flex;gap:8px;align-items:center;margin:10px 0 4px"><input type="checkbox" id="v2_auto_detect"' + (c.auto_detect === '1' ? ' checked' : '') + dis + '> Claude opens a ticket when a customer with an order clearly describes a problem on WhatsApp (nothing is sent to the customer)</label></div>';
  }
  function settingsVals() {
    const o = {}; ['pickup_hours', 'qc_hours', 'settle_hours', 'pen_pickup', 'pen_qc', 'pen_settle', 'pen_reply', 'credit_limit', 'goodwill_limit', 'credit_days', 'reopen_days'].forEach((k) => { const e = document.getElementById('v2_' + k); if (e) o[k] = Number(e.value) || 0; });
    const a = document.getElementById('v2_pen_on'); if (a) o.pen_on = a.checked ? 1 : 0; const b = document.getElementById('v2_auto_detect'); if (b) o.auto_detect = b.checked ? 1 : 0; return o;
  }
  async function reportExtra(host, days) {
    const d = await api.get('bd.cx_report2', { days }).catch(() => null); if (!d) return;
    const tbl = (rows, f) => '<table class="cx-tbl"><tbody>' + (rows.length ? rows.map((r) => '<tr><td>' + esc(r.label) + '</td><td class="n"><b>' + r.n + '</b></td>' + (f ? '<td class="n" style="color:var(--mut)">' + f(r) + '</td>' : '') + '</tr>').join('') : '<tr><td style="color:var(--mut)">No data yet</td></tr>') + '</tbody></table>';
    const div = document.createElement('div'); div.innerHTML = '<div class="cx-2"><div class="cx-box"><h4>Root cause (why it happened)</h4>' + tbl(d.by_cause) + '</div><div class="cx-box"><h4>Where open tickets are stuck</h4>' + tbl(d.by_stage) + '</div><div class="cx-box"><h4>Store credit given</h4>' + tbl(d.credit, (r) => money(r.amt)) + '</div><div class="cx-box"><h4>Courier claims</h4>' + tbl(d.claims, (r) => money(r.amt)) + '</div></div><div class="cx-box"><h4>Penalty requests sent to the boss</h4>' + tbl(d.penalties, (r) => money(r.amt)) + '<div style="color:var(--mut);font-size:12px;margin-top:6px">“ask” = waiting for the boss in Boss Engine · “shadow” = practice mode, recorded only.</div></div>';
    host.appendChild(div);
  }

  const M = { ready: false, init, tabs: { today, custs, qc: qcTab, credit, guide }, callSheet, top, mid, timeline, bind, causeSelect, settingsBox, settingsVals, reportExtra, qcSheet, creditSheet, planSheet };
  return M;
})();
