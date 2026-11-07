/**
 * Manual commission rules — ONE "Commission rules" card on the Commission Rules page. "+ Add rule" lets you tick ONE OR MORE
 * kinds of rule at the same time (Fixed commission, Order-code, Order rating, Panel & date), choose who it applies to and
 * WHEN (all days, or from–to dates). The kinds ticked together are saved as one rule (they share a grp id), listed as one row
 * with one on/off switch, and work together on the same orders. A filter on top shows one kind at a time.
 *
 * layout.js loads this file on commission_rules.html only, and it adds its own block at the end of the
 * page's content area, so the page itself needs no edit. (These cards used to sit on the Commission page;
 * the Commission page still READS the same rules and applies them to the numbers.)
 *
 *  - Order-rating rules : an order rated up to N stars pays X% of its commission; if it also has no code,
 *                         Y% of that.  Example: ₹1000 · 2★ → 50% = ₹500 · no code → 50% of that = ₹250.
 *  - Panel & date rules : orders of the chosen panels on the chosen days pay a fixed % of sales (wins over everything else).
 *  - Order-code rules   : when the share of an employee's orders that have a code is above / below a number,
 *                         pay full commission, a fixed % of sales, a % of the normal commission or ₹ per order.
 *  - Fixed commission   : selected people are paid a fixed % of sales (or ₹ per order) instead of the slab.
 *
 * Saved through commission_rules.star_* / mix_* / fixed_*; read through commission_rules.manual_list.
 */
(function () {
  if (window.__manualRulesLoaded) return;
  window.__manualRulesLoaded = true;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const fmtN = (v) => String(Math.round(Number(v) * 100) / 100);
  const starTxt = (v) => fmtN(v) + '★';
  const payTxt = (mode, v) => mode === 'full' ? 'full commission' : mode === 'rate' ? fmtN(v) + '% of sales' : mode === 'pct' ? fmtN(v) + '% of the normal commission' : inr(v) + ' per order';

  const css = `
#mrc{display:flex; flex-direction:column; gap:20px; margin-top:20px;}
#mrc .mcard{background:#fff; border:1px solid var(--line); border-radius:var(--radius, 18px); padding:18px 22px 20px;}
#mrc h3{margin:0 0 4px; font-size:17px;}
#mrc .rsub{font-size:12.5px; color:var(--muted); line-height:1.5; margin:0 0 14px; max-width:820px;}
#mrc .rrule{display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding:12px 14px; border:1px solid #e6e9ee; border-radius:14px; background:#fafbfc; margin-bottom:8px;}
#mrc .rrule.off .rtxt{opacity:.5;}
#mrc .rrule .rtxt{flex:1 1 300px; font-size:13.5px; line-height:1.6; min-width:0;}
#mrc .rrule .ex{display:block; font-size:12px; color:var(--muted); margin-top:2px;}
#mrc .x{border:1px solid var(--line); background:#fff; border-radius:999px; width:30px; height:30px; cursor:pointer; font-size:13px; color:#6b7280; padding:0; min-height:0;}
#mrc .x:hover{color:#b91c1c; border-color:#f1b4b4;}
#mrc .radd{margin-top:6px; border:1px dashed #b9c0cc; background:#fff; border-radius:12px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; color:var(--ink); min-height:0;}
#mrc .radd:hover{border-color:var(--accent);}
#mrc .rtbl{width:100%; border-collapse:collapse; font-size:13.5px; margin-bottom:8px;}
#mrc .rtbl th{font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); font-weight:700; background:none;}
#mrc .rtbl td{padding:10px 8px; border-bottom:1px solid #f0f1f3; vertical-align:middle; white-space:normal;}
#mrc .rtbl tr:last-child td{border-bottom:none;}
#mrc .lnk{border:none; background:none; padding:0; font:inherit; font-weight:700; color:var(--blue, #2563eb); cursor:pointer; text-align:left; min-height:0;}
#mrc .lnk:hover{text-decoration:underline;}
#mrc .muted{color:var(--muted);} #mrc .small{font-size:12px;}
#mrc .tgl, .mrc-modal .tgl{display:inline-flex; align-items:center; cursor:pointer; user-select:none; position:relative;}
#mrc .tgl input, .mrc-modal .tgl input{position:absolute; opacity:0; width:0; height:0;}
#mrc .tgl i, .mrc-modal .tgl i{width:40px; height:24px; border-radius:20px; background:#cbd2dc; position:relative; transition:.15s; flex:none; display:inline-block;}
#mrc .tgl i::after, .mrc-modal .tgl i::after{content:''; position:absolute; left:3px; top:3px; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.3); transition:left .15s;}
#mrc .tgl input:checked + i, .mrc-modal .tgl input:checked + i{background:#16a34a;}
#mrc .tgl input:checked + i::after, .mrc-modal .tgl input:checked + i::after{left:19px;}
#mrc .tgl input:disabled + i{opacity:.5; cursor:not-allowed;}
#mrc .flash{margin-bottom:12px;}
#mrc .chip, .mrc-modal .chip{display:inline-block; font-size:11px; font-weight:800; border-radius:999px; padding:2px 10px; margin:0 4px 2px 0;}
#mrc .c-live{background:#e7f7ee; color:#0f7a41;} #mrc .c-soon{background:#fff4db; color:#8a5a00;} #mrc .c-done{background:#f1f3f6; color:#6b7280;}
#mrc .c-tmph{background:#ede9fe; color:#5b21b6;} #mrc .c-tm4u{background:#dbeafe; color:#1d4ed8;} #mrc .c-tmsg{background:#dcfce7; color:#15803d;} #mrc .c-tmh4{background:#ffe4e6; color:#be123c;}
.mrc-modal .pick{display:flex; gap:6px; flex-wrap:wrap;}
.mrc-modal .pick label{display:inline-flex; align-items:center; gap:6px; border:1px solid var(--line); border-radius:999px; padding:7px 14px; font-size:13px; font-weight:700; cursor:pointer; background:#fff; text-transform:none; letter-spacing:0; margin:0;}
.mrc-modal .pick label:has(input:checked){background:#14161a; color:#fff; border-color:#14161a;}
.mrc-modal .pick input{position:absolute; opacity:0; width:0; height:0;}
.mrc-modal .pv{font-size:12.5px; background:#f6f7f9; border-radius:12px; padding:9px 12px; line-height:1.6; margin-bottom:6px;}
.mrc-modal .mxpeople{max-height:170px; overflow-y:auto; border:1px solid var(--line); border-radius:12px; padding:6px 10px; margin-top:8px;}
.mrc-modal .mxpeople[hidden]{display:none;}
.mrc-modal .mxpeople label{display:flex; gap:8px; align-items:center; font-size:13px; padding:4px 0; text-transform:none; letter-spacing:0; font-weight:500; margin:0;}
.mrc-modal .mxpeople input{width:auto;}
.mrc-modal .pctrow{display:flex; align-items:center; gap:8px;}
.mrc-modal .pctrow input{width:110px; text-align:center; font-size:18px; font-weight:800;}
.mrc-modal .pctrow span{font-size:18px; font-weight:800;}
#mrc .rhead{display:flex; gap:12px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap;}
#mrc .rhead .btn{white-space:nowrap;}
#mrc .fchips{display:flex; gap:6px; flex-wrap:wrap; margin:4px 0 14px;}
#mrc .fchip{border:1px solid var(--line); background:#fff; border-radius:999px; padding:6px 13px; font-size:12.5px; font-weight:700; cursor:pointer; color:var(--ink); min-height:0;}
#mrc .fchip small{opacity:.6; font-weight:700; margin-left:3px;}
#mrc .fchip.on{background:#14161a; color:#fff; border-color:#14161a;}
#mrc .tag{display:inline-block; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; border-radius:8px; padding:2px 9px; margin-right:6px; vertical-align:1px;}
#mrc .t-fx{background:#ffe9d9; color:#b4440a;} #mrc .t-cd{background:#ede9fe; color:#5b21b6;} #mrc .t-st{background:#fff4db; color:#8a5a00;} #mrc .t-pn{background:#dbeafe; color:#1d4ed8;}
#mrc .rnote{background:#fff3ea; border:1px solid #f6d3b9; color:#8a3a08; border-radius:12px; padding:9px 12px; font-size:12.5px; margin-top:10px; line-height:1.5;}
.mrc-modal .types{display:grid; grid-template-columns:1fr 1fr; gap:8px;}
.mrc-modal .type{border:1.5px solid var(--line); border-radius:14px; padding:10px 12px; cursor:pointer; background:#fff; text-align:left; min-height:0; font:inherit; color:var(--ink);}
.mrc-modal .type b{display:block; font-size:13.5px;}
.mrc-modal .type span{display:block; font-size:11.8px; color:var(--muted); line-height:1.35; margin-top:2px; font-weight:400;}
.mrc-modal .type.on{border-color:#e8590c; background:#fff3ea;}
.mrc-modal .type:disabled{opacity:.45; cursor:not-allowed;}
.mrc-modal [hidden]{display:none !important;}
@media (max-width:480px){ .mrc-modal .types{grid-template-columns:1fr;} }
#mrc .rl{display:block; margin-top:3px;}
#mrc .rmeta{display:block; font-size:12px; color:var(--muted); margin-top:4px;}
.mrc-modal .type{position:relative;}
.mrc-modal .type.on::after{content:'✓'; position:absolute; right:10px; top:8px; width:20px; height:20px; border-radius:50%; background:#e8590c; color:#fff; font-size:12px; font-weight:800; display:flex; align-items:center; justify-content:center;}
.mrc-modal .seg{display:inline-flex; border:1px solid var(--line); border-radius:10px; overflow:hidden; background:#fff;}
.mrc-modal .seg button{border:0; background:#fff; padding:8px 16px; font-weight:700; font-size:13px; cursor:pointer; min-height:0; color:var(--ink);}
.mrc-modal .seg button.on{background:#14161a; color:#fff;}
.mrc-modal .secbox{border:1px solid #f0d9c8; background:#fffaf5; border-radius:14px; padding:12px 14px 4px; margin-bottom:12px;}
.mrc-modal .sechd{font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#b4440a; margin:0 0 8px;}
.mrc-modal .warn{font-size:12.5px; background:#fff4db; color:#8a5a00; border-radius:10px; padding:8px 11px; margin-bottom:10px;}
.mrc-modal .hint{font-size:12px; color:var(--muted); margin:6px 0 0; line-height:1.5;}
@media (max-width:820px){
  #mrc .mcard{padding:14px;}
  #mrc .rtbl thead{display:none;}
  #mrc .rtbl, #mrc .rtbl tbody{display:block;}
  #mrc .rtbl tr{display:block; padding:8px 0; border-bottom:1px solid #f0f1f3;}
  #mrc .rtbl td{display:flex; justify-content:space-between; align-items:center; border:none; padding:4px 6px;}
  #mrc .rtbl td::before{content:attr(data-label); color:var(--muted); font-size:11px; font-weight:700;}
}`;

  const mount = document.querySelector('.content') || document.querySelector('.main') || document.body;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const root = document.createElement('div');
  root.id = 'mrc';
  root.innerHTML = '<div class="mcard"><div class="muted">Loading manual commission rules…</div></div>';
  mount.appendChild(root);

  const PANELS = [['tmph', 'TM Perfume House'], ['tm4u', 'TM4U'], ['tmsg', 'TM Shower Gel'], ['tmh4', 'TM House 4th']];
  const panelName = (k) => (PANELS.find((x) => x[0] === k) || [k, k])[1];
  const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const dShow = (iso) => { const p = String(iso).split('-'); return p.length === 3 ? p[2] + ' ' + MON[Number(p[1]) - 1] + ' ' + p[0] : iso; };
  const starOpts = (() => { const a = []; for (let v = 0.5; v <= 5; v += 0.5) a.push(`<option value="${v}">${starTxt(v)}</option>`); return a.join(''); })();

  const wrap = document.createElement('div');
  wrap.innerHTML = `
  <div class="modal-overlay mrc-modal" id="ruModal"><div class="modal" style="max-width:580px;">
    <h3 id="ruTitle">Add a commission rule</h3>
    <div id="ruAlert"></div>
    <div class="field"><label>Kind of rule — tick one or more</label>
      <div class="types" id="ruTypes">
        <button type="button" class="type" data-k="fx"><b>Fixed commission</b><span>Fixed % of sales or ₹ per order instead of the slab</span></button>
        <button type="button" class="type" data-k="mx"><b>Order-code rule</b><span>Pay by the share of orders that have a code</span></button>
        <button type="button" class="type" data-k="st"><b>Order rating</b><span>Cut commission by the order's star rating</span></button>
        <button type="button" class="type" data-k="pn"><b>Panel &amp; date</b><span>Fixed % for chosen panels</span></button>
      </div>
      <div class="hint" id="ruTogether" hidden>Ticked together, the Order-code rule is the CONDITION: the Fixed commission (or Panel &amp; date rule) is paid only while that condition is met (e.g. orders with a code are at least 80%); if it is not met, the normal commission is paid. The rating cut is applied on top.</div>
    </div>
    <div class="field" id="ruNameWrap"><label for="ruName">Rule name <span style="text-transform:none; font-weight:400;">(optional)</span></label><input id="ruName" maxlength="120" placeholder="e.g. Diwali sale"></div>
    <div class="field" id="ruWhoWrap"><label for="ruWho">Applies to</label><select id="ruWho"><option value="all">Everyone</option><option value="sel">Selected people</option></select><div id="ruPeople" class="mxpeople" hidden></div></div>
    <div class="field"><label>When</label>
      <div class="seg" id="ruWhen"><button type="button" data-w="all">All days</button><button type="button" data-w="range">Date range</button></div>
      <div class="field-grid" id="ruDates" style="margin-top:10px;" hidden>
        <div class="field"><label for="ruFrom">Start date</label><input id="ruFrom" type="date"></div>
        <div class="field"><label for="ruTo">End date <span style="text-transform:none; font-weight:400;">(optional)</span></label><input id="ruTo" type="date"></div>
      </div>
      <div class="hint" id="ruWhenHint"></div>
    </div>

    <div class="secbox" id="secFx" hidden>
      <div class="sechd">Fixed commission</div>
      <div class="field-grid">
        <div class="field"><label for="fxMode">Fixed commission as</label><select id="fxMode"><option value="rate">% of sales</option><option value="flat">₹ per order</option></select></div>
        <div class="field"><label for="fxPer" id="fxPerLbl">Fixed commission (%)</label><input id="fxPer" type="number" min="0" step="0.01" inputmode="decimal"></div>
      </div>
      <div class="field-grid">
        <div class="field"><label for="fxType">Minimum is counted in</label><select id="fxType"><option value="count">Number of orders</option><option value="amount">Achieved amount (₹)</option></select></div>
        <div class="field"><label for="fxMin" id="fxMinLbl">Minimum orders to unlock — optional</label><input id="fxMin" type="number" min="0" step="1" inputmode="numeric" placeholder="blank = no minimum"></div>
      </div>
    </div>

    <div class="secbox" id="secMx" hidden>
      <div class="sechd">Order-code rule</div>
      <div class="field-grid">
        <div class="field"><label for="mxOp">When orders with a code are</label><select id="mxOp"><option value="gte">at least</option><option value="lte">at most</option></select></div>
        <div class="field"><label for="mxThr">% of the employee's orders</label><input id="mxThr" type="number" min="0" max="100" step="0.01" inputmode="decimal"></div>
      </div>
      <div class="field-grid">
        <div class="field"><label for="mxMode">Then pay</label><select id="mxMode"><option value="full">Full commission (no cut)</option><option value="rate">Fixed commission % of sales</option><option value="pct">% of the normal commission</option><option value="flat">₹ per order</option></select></div>
        <div class="field" id="mxValWrap"><label for="mxVal" id="mxValLbl">Commission %</label><input id="mxVal" type="number" min="0" step="0.01" inputmode="decimal"></div>
      </div>
    </div>

    <div class="secbox" id="secSt" hidden>
      <div class="sechd">Order rating</div>
      <div class="field-grid">
        <div class="field"><label for="stStar">When the order is rated up to</label><select id="stStar">${starOpts}</select></div>
        <div class="field"><label for="stPay">Then pay (% of its commission)</label><div class="pctrow"><input id="stPay" type="number" min="0" max="500" step="0.01" inputmode="decimal"><span>%</span></div></div>
      </div>
      <div class="field"><label for="stNc">If the order also has <b>no code</b>, pay (% of that)</label><div class="pctrow"><input id="stNc" type="number" min="0" max="500" step="0.01" inputmode="decimal" value="100"><span>%</span></div>
        <div class="small muted" style="margin-top:6px;">100 = no extra cut for a missing code. 50 = half of what is left.</div></div>
    </div>

    <div class="secbox" id="secPn" hidden>
      <div class="sechd">Panel &amp; date</div>
      <div class="field"><label>Panel — tick one or more</label><div class="pick" id="pnPanels">${PANELS.map((x) => `<label><input type="checkbox" value="${x[0]}"> ${x[1]}</label>`).join('')}</div></div>
      <div class="field"><label for="pnPct">Fixed commission (% of sales)</label><div class="pctrow"><input id="pnPct" type="number" min="0" max="100" step="0.01" inputmode="decimal"><span>%</span></div></div>
    </div>

    <div class="warn" id="ruRemoveWarn" hidden></div>
    <div class="pv" id="ruPrev"></div>
    <div class="actions"><button type="button" class="btn secondary" id="ruCancel">Cancel</button><button type="button" class="btn" id="ruSave">Save</button></div>
  </div></div>`;
  while (wrap.firstChild) document.body.appendChild(wrap.firstChild);
  const $ = (id) => document.getElementById(id);

  let D = { panel: [], star: [], mix: [], fixed: [], employees: [] };
  const empName = (id) => { const e = D.employees.find((x) => x.employee_id === id); return e ? e.full_name : 'Employee ' + id; };

  function flash(msg) {
    let b = root.querySelector('.flash');
    if (!b) { b = document.createElement('div'); b.className = 'flash'; root.prepend(b); }
    b.innerHTML = msg ? `<div class="alert error">${esc(msg)}</div>` : '';
  }
  async function load() {
    try {
      const d = await api.get('commission_rules.manual_list');
      const dt = (r) => ({ grp: String(r.grp || ''), from: String(r.date_from || ''), to: String(r.date_to || '') });
      D = {
        employees: (d.employees || []).map((e) => ({ employee_id: Number(e.employee_id), full_name: e.full_name })),
        panel: (d.panel || []).map((r) => Object.assign({ id: Number(r.rule_id), label: r.label || '', panels: r.panels || [], pct: Number(r.pct), who: (r.who || []).map(Number), on: !!Number(r.enabled) }, dt(r))),
        star: (d.star || []).map((r) => Object.assign({ id: Number(r.rule_id), label: r.label || '', star: Number(r.max_star), pay: Number(r.pay_pct), nc: Number(r.nocode_pct), who: (r.who || []).map(Number), on: !!Number(r.enabled) }, dt(r))),
        mix: (d.mix || []).map((r) => Object.assign({ id: Number(r.rule_id), label: r.label || '', op: r.op === 'lte' ? 'lte' : 'gte', thr: Number(r.thr), mode: ['full', 'rate', 'pct', 'flat'].indexOf(r.pay_mode) > -1 ? r.pay_mode : 'full', v: Number(r.pay_value || 0), who: (r.who || []).map(Number), on: !!Number(r.enabled) }, dt(r))),
        fixed: (d.fixed || []).map((p) => Object.assign({ id: Number(p.plan_id), emp: Number(p.employee_id), per: Number(p.per), mode: p.pay_mode === 'rate' ? 'rate' : 'flat', minType: p.min_type === 'amount' ? 'amount' : 'count', min: Number(p.min), on: !!Number(p.enabled) }, dt(p))),
      };
      render();
    } catch (err) {
      root.innerHTML = `<div class="mcard"><div class="alert error" style="margin:0;">${esc(err.message || 'The manual commission rules could not be read.')} — upload the latest api.php, then reload this page.</div></div>`;
    }
  }
  async function act(fn) {
    try { flash(''); await fn(); } catch (err) { flash(err.message); }
    await load();
  }

  // ---------- rules → one row each (kinds saved together share a grp id and are one row) ----------
  const KINDS = [['fx', 'Fixed', 't-fx'], ['mx', 'Order code', 't-cd'], ['st', 'Order rating', 't-st'], ['pn', 'Panel & date', 't-pn']];
  const KEY = { pn: 'panel', fx: 'fixed', mx: 'mix', st: 'star' };
  function buildUnits() {
    const map = new Map(), list = [];
    const add = (k, r) => {
      const key = r.grp ? 'g:' + r.grp : k + ':' + r.id;
      let u = map.get(key);
      if (!u) { u = { key, grp: r.grp || '', recs: { pn: [], fx: [], mx: [], st: [] } }; map.set(key, u); list.push(u); }
      u.recs[k].push(r);
    };
    D.panel.forEach((r) => add('pn', r)); D.fixed.forEach((r) => add('fx', r)); D.mix.forEach((r) => add('mx', r));
    D.star.slice().sort((a, b) => a.star - b.star || a.id - b.id).forEach((r) => add('st', r));
    list.forEach((u) => {
      const all = [].concat(u.recs.pn, u.recs.fx, u.recs.mx, u.recs.st);
      u.kinds = KINDS.map((x) => x[0]).filter((k) => u.recs[k].length);
      u.on = all.some((r) => r.on);
      const w = all.find((r) => r.from || r.to);
      u.from = w ? w.from : ''; u.to = w ? w.to : '';
      u.all = all;
      u.who = u.recs.fx.length ? u.recs.fx.map((r) => r.emp) : ((u.recs.mx[0] || u.recs.st[0] || u.recs.pn[0] || { who: [] }).who || []);
      u.label = ((u.recs.mx[0] || u.recs.st[0] || u.recs.pn[0] || {}).label) || '';
    });
    return list;
  }

  // ---------- rendering: ONE card with every rule ----------
  const starText = (r) => `Order rated <b>up to ${starTxt(r.star)}</b> → pays <b>${fmtN(r.pay)}%</b> of its commission` + (Number(r.nc) !== 100 ? `, and if it has <b>no code</b> → <b>${fmtN(r.nc)}%</b> of that (${fmtN(r.pay * r.nc / 100)}% of the full commission)` : '');
  const tagOf = (k) => { const x = KINDS.find((q) => q[0] === k); return `<span class="tag ${x[2]}">${esc(x[1])}</span>`; };
  let FILTER = 'all';
  const minTxt = (p) => !(p.min > 0) ? 'no minimum' : p.minType === 'amount' ? 'unlocks after ' + inr(p.min) + ' achieved' : 'unlocks after ' + fmtN(p.min) + ' orders';
  function lineOf(u, k) {
    const rs = u.recs[k];
    if (k === 'fx') {   // people with the same pay are listed together
      const g = {};
      rs.forEach((p) => { const key = [p.mode, p.per, p.minType, p.min].join('|'); (g[key] = g[key] || { p, names: [] }).names.push(empName(p.emp)); });
      return Object.keys(g).map((x) => `<span class="rl">${tagOf('fx')}<b>${g[x].names.map(esc).join(', ')}</b> → <b>${esc(payTxt(g[x].p.mode, g[x].p.per))}</b> · ${esc(minTxt(g[x].p))}</span>`).join('');
    }
    const r = rs[0];
    if (k === 'mx') return `<span class="rl">${tagOf('mx')}If orders with a code are <b>${r.op === 'lte' ? 'at most' : 'at least'} ${fmtN(r.thr)}%</b> of an employee's orders → pay <b>${esc(payTxt(r.mode, r.v))}</b></span>`;
    if (k === 'st') return `<span class="rl">${tagOf('st')}${starText(r)}</span>`;
    return `<span class="rl">${tagOf('pn')}${r.panels.map((p) => `<span class="chip c-${esc(p)}">${esc(panelName(p))}</span>`).join('')} → fixed <b>${fmtN(r.pct)}%</b> of sales</span>`;
  }
  function render() {
    root.querySelectorAll(':scope > .mcard').forEach((n) => n.remove());
    const today = ymd(new Date());
    const units = buildUnits();
    if (FILTER !== 'all' && !units.some((u) => u.kinds.indexOf(FILTER) > -1)) FILTER = 'all';
    const cnt = (k) => units.filter((u) => k === 'all' || u.kinds.indexOf(k) > -1).length;
    const shown = units.filter((u) => FILTER === 'all' || u.kinds.indexOf(FILTER) > -1);
    const row = (u) => {
      const dated = !!(u.from || u.to);
      const st = !dated ? ['c-live', 'all days'] : (u.to && u.to < today) ? ['c-done', 'ended'] : u.from > today ? ['c-soon', 'starts ' + dShow(u.from)] : ['c-live', '● running now'];
      const who = u.recs.fx.length ? (u.who.length + ' selected ' + (u.who.length === 1 ? 'person' : 'people')) : (u.who.length ? u.who.length + ' selected ' + (u.who.length === 1 ? 'person' : 'people') : 'everyone');
      return `<div class="rrule ${u.on ? '' : 'off'}">
        <label class="tgl" title="Switch this rule on or off"><input type="checkbox" data-on="${esc(u.key)}" ${u.on ? 'checked' : ''}><i></i></label>
        <div class="rtxt">${u.label ? `<b>${esc(u.label)}</b>` : ''}${u.kinds.length > 1 ? `<span class="chip c-done">${u.kinds.length} rules together</span>` : ''}${u.kinds.map((k) => lineOf(u, k)).join('')}
          <span class="rmeta">${esc(who)} · ${dated ? '<b>' + esc(dShow(u.from)) + ' → ' + (u.to ? esc(dShow(u.to)) : 'no end date') + '</b> ' : ''}<span class="chip ${st[0]}">${esc(st[1])}</span></span></div>
        <button type="button" class="x" data-edit="${esc(u.key)}" title="Change this rule" aria-label="Change this rule">✎</button>
        <button type="button" class="x" data-del="${esc(u.key)}" title="Delete this rule" aria-label="Delete this rule">✕</button></div>`;
    };
    root.insertAdjacentHTML('beforeend', `
    <div class="mcard" id="ruleCard">
      <div class="rhead"><div><h3>Commission rules</h3>
        <p class="rsub">All your manual rules in one place. Press <b>+ Add rule</b>, tick one or more kinds of rule (Fixed commission, Order-code, Order rating, Panel &amp; date), choose who it applies to and when (all days, or from–to dates). Switch any rule on or off — a rule that is off is saved but not used.</p></div>
        <button type="button" class="btn" id="ruAdd">+ Add rule</button></div>
      <div class="fchips" id="ruFilter">${[['all', 'All']].concat(KINDS.map((x) => [x[0], x[1]])).map((x) => `<button type="button" class="fchip ${FILTER === x[0] ? 'on' : ''}" data-f="${x[0]}">${esc(x[1])} <small>${cnt(x[0])}</small></button>`).join('')}</div>
      ${shown.map(row).join('') || `<div class="muted small" style="margin-bottom:8px;">${units.length ? 'No rule of this kind yet.' : 'No rule yet — press “+ Add rule” to make the first one.'}</div>`}
      <div class="rnote">Rules ticked together work as one: when an Order-code rule is in the bundle, its condition (e.g. orders with a code are at least 80%) must be met for the Fixed commission / Panel &amp; date rule of that bundle to be paid — otherwise the normal commission is paid. The rating cut is applied on top. Rules saved separately work on their own: Fixed commission or the Order-code rule sets the pay, a Panel &amp; date rule replaces it for the orders it covers. If two rules of the same kind match, the first one decides. (An order whose share was changed by hand on the Commission page keeps that share.)</div>
    </div>`);
  }

  // ---------- switches, edit, delete, filter ----------
  const bodyOf = {
    pn: (r, over) => Object.assign({ rule_id: r.id, label: r.label, panels: r.panels, date_from: r.from, date_to: r.to, pct: r.pct, who: r.who, enabled: r.on ? 1 : 0, grp: r.grp }, over || {}),
    st: (r, over) => Object.assign({ rule_id: r.id, label: r.label, max_star: r.star, pay_pct: r.pay, nocode_pct: r.nc, who: r.who, enabled: r.on ? 1 : 0, grp: r.grp, date_from: r.from, date_to: r.to }, over || {}),
    mx: (r, over) => Object.assign({ rule_id: r.id, label: r.label, op: r.op, thr: r.thr, pay_mode: r.mode, pay_value: r.v, who: r.who, enabled: r.on ? 1 : 0, grp: r.grp, date_from: r.from, date_to: r.to }, over || {}),
  };
  const SAVE_EP = { pn: 'commission_rules.panel_save', st: 'commission_rules.star_save', mx: 'commission_rules.mix_save' };
  const DEL_EP = { pn: ['commission_rules.panel_delete', 'rule_id'], st: ['commission_rules.star_delete', 'rule_id'], mx: ['commission_rules.mix_delete', 'rule_id'], fx: ['commission_rules.fixed_delete', 'plan_id'] };
  const unitOf = (key) => buildUnits().find((u) => u.key === key);
  const delRec = (k, r) => api.post(DEL_EP[k][0], { [DEL_EP[k][1]]: r.id });
  root.addEventListener('change', (e) => {
    const t = e.target; if (!t.dataset.on) return;
    const u = unitOf(t.dataset.on); if (!u) return;
    const en = t.checked ? 1 : 0;
    act(async () => {
      for (const k of u.kinds) for (const r of u.recs[k]) {
        if (k === 'fx') await api.post('commission_rules.fixed_toggle', { plan_id: r.id, enabled: en });
        else await api.post(SAVE_EP[k], bodyOf[k](r, { enabled: en }));
      }
    });
  });
  root.addEventListener('click', (e) => {
    const t = e.target.closest('button'); if (!t) return;
    if (t.id === 'ruAdd') openRu(null);
    else if (t.dataset.f) { FILTER = t.dataset.f; render(); }
    else if (t.dataset.edit) { const u = unitOf(t.dataset.edit); if (u) openRu(u); }
    else if (t.dataset.del) {
      const u = unitOf(t.dataset.del); if (!u) return;
      if (confirm(u.kinds.length > 1 ? 'Delete this rule (all ' + u.kinds.length + ' rules in it)?' : 'Delete this rule?')) act(async () => { for (const k of u.kinds) for (const r of u.recs[k]) await delRec(k, r); });
    }
  });

  // ---------- the one Add / Change rule window ----------
  const peopleHtml = (sel) => D.employees.map((e) => `<label><input type="checkbox" value="${e.employee_id}" ${sel && sel.indexOf(e.employee_id) !== -1 ? 'checked' : ''}> ${esc(e.full_name)}</label>`).join('');
  const picked = () => [...$('ruPeople').querySelectorAll('input:checked')].map((i) => Number(i.value));
  const pnPicked = () => [...$('pnPanels').querySelectorAll('input:checked')].map((i) => i.value);
  const closeRu = () => $('ruModal').classList.remove('open');
  $('ruModal').addEventListener('click', (e) => { if (e.target === $('ruModal')) closeRu(); });
  $('ruCancel').onclick = closeRu;
  let RU = { kinds: new Set(['fx']), unit: null, when: 'all' };
  const newGrp = () => 'g' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

  function ruSync() {
    const K = RU.kinds, unit = RU.unit;
    $('ruTypes').querySelectorAll('.type').forEach((b) => b.classList.toggle('on', K.has(b.dataset.k)));
    ['fx', 'mx', 'st', 'pn'].forEach((k) => { $('sec' + k[0].toUpperCase() + k[1]).hidden = !K.has(k); });
    $('ruTogether').hidden = K.size < 2;
    $('ruNameWrap').hidden = !(K.has('mx') || K.has('st') || K.has('pn'));
    // Fixed commission is always for selected people
    const whoSel = $('ruWho');
    whoSel.options[0].disabled = K.has('fx');
    if (K.has('fx') && whoSel.value === 'all') whoSel.value = 'sel';
    $('ruPeople').hidden = whoSel.value !== 'sel';
    // when
    $('ruWhen').querySelectorAll('button').forEach((b) => b.classList.toggle('on', b.dataset.w === RU.when));
    $('ruDates').hidden = RU.when !== 'range';
    $('ruWhenHint').textContent = RU.when === 'all' ? 'The rule works on every day, from now on.' : 'The rule works only on orders from the start date onwards — orders before it are not touched. Leave the end date blank and it keeps running with no end. (A day runs 10 AM to 10 AM, as on the Commission page.)';
    // labels
    const fm = $('fxMode').value, ft = $('fxType').value;
    $('fxPerLbl').textContent = fm === 'rate' ? 'Fixed commission (% of sales)' : 'Fixed commission (₹ per order)';
    $('fxMinLbl').textContent = (ft === 'amount' ? 'Minimum achieved amount to unlock (₹)' : 'Minimum orders to unlock') + ' — optional';
    const mm = $('mxMode').value;
    $('mxValWrap').style.display = mm === 'full' ? 'none' : '';
    $('mxValLbl').textContent = mm === 'rate' ? 'Commission (% of sales)' : mm === 'pct' ? 'Share of the normal commission (%)' : 'Amount per order (₹)';
    // un-ticking a kind that already exists removes it on save
    const gone = unit ? unit.kinds.filter((k) => !K.has(k)) : [];
    $('ruRemoveWarn').hidden = !gone.length;
    if (gone.length) $('ruRemoveWarn').textContent = 'Un-ticked ' + gone.map((k) => KINDS.find((x) => x[0] === k)[1]).join(', ') + ' will be removed from this rule when you save.';
    ruPrev();
  }
  function whenTxt() { return RU.when === 'all' ? 'all days' : ($('ruFrom').value ? ($('ruTo').value ? dShow($('ruFrom').value) + ' → ' + dShow($('ruTo').value) : 'from ' + dShow($('ruFrom').value) + ', no end date') : 'the dates you choose'); }
  function ruPrev() {
    const K = RU.kinds, box = $('ruPrev'), sel = $('ruWho').value === 'sel', who = sel ? picked() : [];
    if (!K.size) { box.textContent = 'Tick at least one kind of rule.'; return; }
    const wtxt = who.length ? who.map(empName).map(esc).join(', ') : (sel || K.has('fx') ? 'the people you tick' : 'everyone');
    const L = [];
    if (K.has('fx')) {
      const per = $('fxPer').value, min = $('fxMin').value, mode = $('fxMode').value;
      L.push(per === '' ? 'Fixed commission: fill in the amount.' : `<b>Fixed commission</b> → <b>${esc(payTxt(mode, Number(per)))}</b>, ${min === '' || Number(min) <= 0 ? 'always (no minimum)' : ($('fxType').value === 'amount' ? 'once ' + inr(min) + ' is achieved' : 'once ' + fmtN(min) + ' orders are reached')}. Example: ${inr(3000)} order → <b>${inr(mode === 'rate' ? 3000 * Number(per) / 100 : Number(per))}</b>`);
    }
    if (K.has('mx')) {
      const thr = $('mxThr').value, mode = $('mxMode').value, val = $('mxVal').value;
      L.push(thr === '' ? 'Order-code rule: fill in the %.' : `<b>Order-code</b>: if orders with a code are <b>${$('mxOp').value === 'lte' ? 'at most' : 'at least'} ${fmtN(thr)}%</b> → pay <b>${esc(payTxt(mode, Number(val || 0)))}</b>`);
    }
    if (K.has('st')) {
      const pay = $('stPay').value, nc = $('stNc').value;
      if (pay === '') L.push('Order rating: fill in the %.');
      else { const p = Number(pay), n = nc === '' ? 100 : Number(nc); L.push(`<b>Order rating</b>: rated <b>${starTxt($('stStar').value)}</b> or less → pays <b>${fmtN(p)}%</b> of its commission` + (n !== 100 ? `, with no code <b>${fmtN(p * n / 100)}%</b>` : '')); }
    }
    if (K.has('pn')) {
      const ps = pnPicked(), pct = $('pnPct').value;
      L.push(!ps.length || pct === '' ? 'Panel &amp; date: choose the panel and the %.' : `<b>Panel</b>: every <b>${ps.map((x) => esc(panelName(x))).join(' + ')}</b> order pays <b>${fmtN(pct)}%</b> of its amount`);
    }
    box.innerHTML = L.join('<br>') + `<br><span class="muted">For ${wtxt} · ${esc(whenTxt())}</span>`;
  }
  function openRu(unit) {
    RU = { kinds: new Set(unit ? unit.kinds : ['fx']), unit: unit || null, when: unit && (unit.from || unit.to) ? 'range' : 'all' };
    $('ruTitle').textContent = unit ? 'Change this rule' : 'Add a commission rule';
    ['ruName', 'fxPer', 'fxMin', 'mxThr', 'mxVal', 'stPay', 'pnPct', 'ruFrom', 'ruTo'].forEach((i) => { $(i).value = ''; });
    $('fxMode').value = 'rate'; $('fxType').value = 'count'; $('mxOp').value = 'gte'; $('mxMode').value = 'full'; $('stStar').value = '1'; $('stNc').value = 100;
    $('pnPanels').querySelectorAll('input').forEach((i) => { i.checked = false; });
    let who = [];
    if (unit) {
      const f = unit.recs.fx[0], m = unit.recs.mx[0], s = unit.recs.st[0], p = unit.recs.pn[0];
      if (f) { $('fxMode').value = f.mode; $('fxPer').value = f.per; $('fxType').value = f.minType; $('fxMin').value = f.min > 0 ? f.min : ''; }
      if (m) { $('mxOp').value = m.op; $('mxThr').value = m.thr; $('mxMode').value = m.mode; $('mxVal').value = m.mode !== 'full' ? m.v : ''; }
      if (s) { $('stStar').value = String(s.star); $('stPay').value = s.pay; $('stNc').value = s.nc; }
      if (p) { $('pnPanels').querySelectorAll('input').forEach((i) => { i.checked = p.panels.indexOf(i.value) !== -1; }); $('pnPct').value = p.pct; }
      $('ruName').value = unit.label || '';
      $('ruFrom').value = unit.from; $('ruTo').value = unit.to;
      who = unit.who;
    }
    $('ruWho').value = who.length || (!unit) ? 'sel' : 'all';
    $('ruPeople').innerHTML = peopleHtml(who);
    $('ruAlert').innerHTML = ''; ruSync(); $('ruModal').classList.add('open');
  }
  $('ruTypes').addEventListener('click', (e) => {
    const b = e.target.closest('.type'); if (!b) return;
    const k = b.dataset.k;
    if (RU.kinds.has(k)) RU.kinds.delete(k); else RU.kinds.add(k);
    if (!RU.kinds.has('fx') && $('ruWho').value === 'sel' && !picked().length) $('ruWho').value = 'all';
    $('ruAlert').innerHTML = ''; ruSync();
  });
  $('ruWhen').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    RU.when = b.dataset.w;
    if (RU.when === 'range' && !$('ruFrom').value) { $('ruFrom').value = ymd(new Date()); $('ruTo').value = ''; }      // starts today, no end date
    $('ruAlert').innerHTML = ''; ruSync();
  });
  $('ruModal').addEventListener('input', () => { $('ruAlert').innerHTML = ''; ruSync(); });
  $('ruModal').addEventListener('change', () => { $('ruAlert').innerHTML = ''; ruSync(); });

  $('ruSave').onclick = () => {
    const K = RU.kinds, unit = RU.unit, bad = (m) => { $('ruAlert').innerHTML = `<div class="alert error">${m}</div>`; };
    if (!K.size) return bad('Tick at least one kind of rule.');
    const sel = $('ruWho').value === 'sel', who = sel ? picked() : [];
    if (K.has('fx') && !who.length) return bad('Tick at least one person — fixed commission is for selected people.');
    if (sel && !who.length) return bad('Tick at least one person, or choose Everyone.');
    let from = '', to = '';
    if (RU.when === 'range') {
      from = $('ruFrom').value; to = $('ruTo').value;
      if (!from) return bad('Choose the start date, or choose All days.');
      if (to && from > to) return bad('The start date must be on or before the end date.');
    }
    const dates = RU.when === 'range' ? { date_from: from, date_to: to } : { date_from: '', date_to: '', all_days: 1 };
    // field checks for every ticked kind
    let fx, mx, st, pn;
    if (K.has('fx')) {
      const mode = $('fxMode').value, per = $('fxPer').value, min = $('fxMin').value;
      if (per === '' || Number(per) < 0 || (mode === 'rate' && Number(per) > 100)) return bad(mode === 'rate' ? 'Fixed commission: enter the % (0 to 100).' : 'Fixed commission: enter the ₹ per order.');
      if (min !== '' && Number(min) < 0) return bad('Fixed commission: the minimum cannot be negative — or leave it blank for no minimum.');
      fx = { pay_mode: mode, per: Number(per), min_type: $('fxType').value, min: min === '' ? 0 : Number(min) };
    }
    if (K.has('mx')) {
      const mode = $('mxMode').value, thr = $('mxThr').value, val = $('mxVal').value;
      if (thr === '' || Number(thr) < 0 || Number(thr) > 100) return bad('Order-code rule: enter the % of orders with a code (0 to 100).');
      if (mode !== 'full' && (val === '' || Number(val) < 0)) return bad('Order-code rule: enter how much it pays.');
      mx = { op: $('mxOp').value, thr: Number(thr), pay_mode: mode, pay_value: mode === 'full' ? 0 : Number(val) };
    }
    if (K.has('st')) {
      const pay = $('stPay').value, nc = $('stNc').value;
      if (pay === '' || Number(pay) < 0 || Number(pay) > 500) return bad('Order rating: enter what the order pays (0 to 500 %). Use 0 for no commission.');
      if (nc === '' || Number(nc) < 0 || Number(nc) > 500) return bad('Order rating: enter the no-code % (0 to 500). Use 100 for no extra cut.');
      st = { max_star: Number($('stStar').value), pay_pct: Number(pay), nocode_pct: Number(nc) };
    }
    if (K.has('pn')) {
      const ps = pnPicked(), pct = $('pnPct').value;
      if (!ps.length) return bad('Panel & date: tick at least one panel.');
      if (pct === '' || Number(pct) < 0 || Number(pct) > 100) return bad('Panel & date: enter the fixed commission % (0 to 100).');
      pn = { panels: ps, pct: Number(pct) };
    }
    const label = $('ruName').value.trim();
    const grp = K.size > 1 || (unit && unit.grp) ? ((unit && unit.grp) || newGrp()) : '';
    const unitOn = unit ? unit.on : true;
    closeRu();
    act(async () => {
      const common = Object.assign({ grp }, dates);
      if (mx) { const r = unit && unit.recs.mx[0]; await api.post('commission_rules.mix_save', Object.assign({ rule_id: r ? r.id : 0, label, who, enabled: r ? (r.on ? 1 : 0) : (unitOn ? 1 : 0) }, mx, common)); }
      if (st) { const r = unit && unit.recs.st[0]; await api.post('commission_rules.star_save', Object.assign({ rule_id: r ? r.id : 0, label, who, enabled: r ? (r.on ? 1 : 0) : (unitOn ? 1 : 0) }, st, common)); }
      if (pn) { const r = unit && unit.recs.pn[0]; await api.post('commission_rules.panel_save', Object.assign({ rule_id: r ? r.id : 0, label, who, enabled: r ? (r.on ? 1 : 0) : (unitOn ? 1 : 0) }, pn, common)); }
      if (fx) {
        for (const emp of who) {
          const old = D.fixed.find((p) => p.emp === emp);   // one fixed plan per person: saving again updates it
          await api.post('commission_rules.fixed_save', Object.assign({ employee_id: emp, enabled: old ? (old.on ? 1 : 0) : (unitOn ? 1 : 0) }, fx, common));
        }
      }
      if (unit) {   // kinds un-ticked, and people taken off a fixed commission, are removed
        for (const k of unit.kinds) {
          if (!K.has(k)) { for (const r of unit.recs[k]) await delRec(k, r); }
          else if (k === 'fx') { for (const r of unit.recs.fx) if (who.indexOf(r.emp) === -1) await delRec('fx', r); }
        }
      }
    });
  };

  load();
})();
