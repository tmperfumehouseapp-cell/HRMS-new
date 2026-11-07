/**
 * ML price slab — the editor card on the Commission Rules page (layout.js loads this file there, after manual_rules.js).
 *
 * One chart: rows = order size (total ML, free ML included), columns = commission % and, in each cell, the lowest price that still
 * earns that %. Switch it ON and every allocated order with a known total ML is paid by it on the Commission page, the Orders page
 * and My Sales (assets/js/ml_slab.js does the maths). Saved through ml_slab.save, read through ml_slab.get.
 */
(function () {
  if (window.__mlSlabRulesLoaded) return;
  window.__mlSlabRulesLoaded = true;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const fmtN = (v) => String(Math.round(Number(v) * 100) / 100);

  const css = `
#mls{margin-top:20px; background:#fff; border:1px solid var(--line); border-radius:var(--radius, 18px); padding:18px 22px 20px;}
#mls h3{margin:0 0 4px; font-size:17px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
#mls .rsub{font-size:12.5px; color:var(--muted); line-height:1.5; margin:0 0 14px; max-width:860px;}
#mls .st{font-size:11px; font-weight:800; border-radius:999px; padding:3px 10px;}
#mls .st.on{background:#e7f7ee; color:#0f7a41;} #mls .st.off{background:#f1f3f6; color:#6b7280;}
#mls .tgl{display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none; position:relative; font-size:13px; font-weight:700;}
#mls .tgl input{position:absolute; opacity:0; width:0; height:0;}
#mls .tgl i{width:40px; height:24px; border-radius:20px; background:#cbd2dc; position:relative; transition:.15s; flex:none; display:inline-block;}
#mls .tgl i::after{content:''; position:absolute; left:3px; top:3px; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.3); transition:left .15s;}
#mls .tgl input:checked + i{background:#16a34a;} #mls .tgl input:checked + i::after{left:19px;}
#mls .row3{display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px;}
#mls .fld{flex:1 1 200px; background:#fafbfc; border:1px solid #e6e9ee; border-radius:12px; padding:10px 12px;}
#mls .fld label{display:block; font-size:12px; font-weight:700; margin-bottom:6px;}
#mls .fld .in{display:flex; align-items:center; gap:6px; font-size:13px;}
#mls .fld input{width:80px; padding:6px 8px; min-height:0;}
#mls .fld small{display:block; font-size:11.5px; color:var(--muted); margin-top:5px; line-height:1.4;}
#mls .tester{border:2px solid #ddd6fe; background:#faf8ff; border-radius:14px; padding:12px 14px; margin-bottom:14px;}
#mls .tester b.t{display:block; font-size:13.5px; color:#6d28d9; margin-bottom:8px;}
#mls .tin{display:flex; gap:10px; flex-wrap:wrap; align-items:center; font-size:13px;}
#mls .tin input[type=number]{width:96px; padding:6px 8px; min-height:0;}
#mls .tin label{display:inline-flex; align-items:center; gap:5px; cursor:pointer;}
#mls .tout{margin-top:10px; font-size:13px; line-height:1.6;}
#mls .tout .big{font-size:18px; font-weight:800;}
#mls .tout ol{margin:4px 0 0; padding-left:18px; color:#4b5563;}
#mls .tools{display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0 0 8px;}
#mls .tools .btn{width:auto; padding:7px 14px; min-height:0; font-size:12.5px;}
#mls .tools .sp{flex:1;}
#mls .wrap{overflow:auto; max-height:62vh; border:1px solid #e6e9ee; border-radius:12px; -webkit-overflow-scrolling:touch;}
#mls table{border-collapse:separate; border-spacing:0; font-size:12.5px; min-width:100%;}
#mls th, #mls td{padding:3px; border-bottom:1px solid #f0f1f3; border-right:1px solid #f4f5f7; text-align:center; white-space:nowrap; background:#fff;}
#mls thead th{position:sticky; top:0; z-index:2; background:#f8fafc; font-size:11px; color:#475569; padding:5px 3px;}
#mls thead tr:nth-child(2) th{top:31px;}
#mls th.c0, #mls td.c0{position:sticky; left:0; z-index:1; background:#f8fafc; font-weight:700;}
#mls thead th.c0{z-index:3;}
#mls td input, #mls th input{width:70px; padding:4px 5px; min-height:0; text-align:right; font-size:12.5px; border:1px solid transparent; border-radius:6px; background:transparent;}
#mls td input:focus, #mls th input:focus{border-color:var(--accent); background:#fff; outline:none;}
#mls th input.nm{width:100px; text-align:center; font-weight:700; font-size:11px; color:#475569;}
#mls th input.pc{width:58px; text-align:center; font-weight:800; font-size:13px; color:#111827;}
#mls td.c0 input{width:58px; font-weight:700; text-align:center;}
#mls td.c1 input{color:#1d4ed8; font-weight:700;} #mls td.c3 input{background:#fff8e1;}
#mls th.lw, #mls td.lw{background:#fff1f2; color:#9f1239;} #mls thead th.lw{background:#fee2e2; color:#9f1239;}
#mls td.lw{font-size:12px; font-weight:700; padding:3px 8px;} #mls th.lw input.pc{color:#9f1239;}
#mls .x{border:1px solid var(--line); background:#fff; border-radius:999px; width:22px; height:22px; cursor:pointer; font-size:11px; color:#9ca3af; padding:0; min-height:0; line-height:1;}
#mls .x:hover{color:#b91c1c; border-color:#f1b4b4;}
#mls tr.bad td{background:#fff1f1;}
#mls .foot{display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:12px;}
#mls .foot .btn{width:auto; padding:9px 20px; min-height:0;}
#mls .foot .muted{font-size:12px; color:var(--muted);}
#mls .msg{margin:0 0 12px;}
#mls .paste{display:none; margin:0 0 10px;}
#mls .paste textarea{width:100%; min-height:110px; font-family:ui-monospace,monospace; font-size:12px;}
#mls .ov{display:block; margin-top:7px; background:#fff7ed; border:1px solid #fdba74; color:#9a3412; border-radius:9px; padding:5px 8px; font-size:11.5px; font-weight:700; line-height:1.4;}
#mls .spbar{display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 12px;}
#mls .spbtn{display:inline-flex; align-items:center; gap:7px; border-radius:10px; padding:9px 14px; font-weight:800; font-size:13.5px; background:#7c3aed; color:#fff; border:1px solid #7c3aed; cursor:pointer; min-height:0; width:auto;}
#mls .spbtn b{background:#fff; color:#7c3aed; border-radius:99px; padding:0 7px; font-size:12px;}
#mls .spbtn.open{background:#5b21b6;}
#mls .sp{border:2px solid #ddd6fe; background:#fff; border-radius:14px; padding:14px; margin:0 0 14px;}
#mls .sp h4{margin:0 0 4px; font-size:15.5px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
#mls .sp h4 small{font-weight:500; font-size:12px; color:var(--muted);}
#mls .sp .lst{overflow:auto;}
#mls .sp table{width:100%; border-collapse:collapse; font-size:13px;}
#mls .sp th{position:static; font-size:11px; color:#64748b; text-align:left; padding:7px 6px; border-bottom:1px solid #e2e8f0; background:#fff; letter-spacing:.04em;}
#mls .sp td{padding:9px 6px; border-bottom:1px solid #f1f5f9; text-align:left; white-space:normal; vertical-align:top; background:#fff; border-right:0;}
#mls .pill{display:inline-block; border-radius:99px; padding:3px 9px; font-size:12px; font-weight:800; margin:1px 3px 1px 0; white-space:nowrap;}
#mls .p1{background:#ede9fe; color:#5b21b6;} #mls .p2{background:#dcfce7; color:#166534;} #mls .p3{background:#fff7ed; color:#9a3412;} #mls .p4{background:#e0f2fe; color:#075985;} #mls .p5{background:#f1f5f9; color:#334155;} #mls .p6{background:#fee2e2; color:#991b1b;}
#mls .sst{font-weight:800; font-size:12px; white-space:nowrap;} #mls .sst.on{color:#16a34a;} #mls .sst.up{color:#2563eb;} #mls .sst.past{color:#94a3b8;}
#mls tr.past td{color:#94a3b8;}
#mls .lk{background:none; border:0; color:#ea580c; font-weight:700; font-size:12.5px; cursor:pointer; padding:0 3px; min-height:0; width:auto;}
#mls .spf{display:grid; grid-template-columns:1fr 1.2fr 1.8fr; gap:16px; margin-top:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:14px;}
#mls .spf h5{margin:0 0 8px; font-size:11.5px; color:#64748b; letter-spacing:.05em;}
#mls .spf .r{display:flex; align-items:center; gap:8px; font-size:13px; margin:6px 0; flex-wrap:wrap;}
#mls .spf input[type=date]{width:150px; padding:5px 8px; min-height:0;}
#mls .spf input[type=number]{width:72px; padding:5px 8px; min-height:0;}
#mls .spf input[type=text]{flex:1; min-width:160px; padding:6px 8px; min-height:0;}
#mls .spf input:disabled{opacity:.45;}
#mls .spf select{width:auto; padding:5px 8px; min-height:0;}
#mls .spf label{display:inline-flex; align-items:center; gap:6px; cursor:pointer;}
#mls .chs{display:flex; flex-wrap:wrap; gap:5px; margin-top:6px;}
#mls .ch{border:1px solid #cbd5e1; border-radius:99px; padding:3px 10px; font-size:12px; background:#fff; cursor:pointer; min-height:0; width:auto; color:#111827;}
#mls .ch.on{background:#111827; color:#fff; border-color:#111827;}
#mls .spf .go{background:#16a34a; color:#fff; border:0; border-radius:10px; padding:9px 18px; font-weight:800; cursor:pointer; width:auto; min-height:0;}
@media (max-width:900px){ #mls .spf{grid-template-columns:1fr;} }
@media (max-width:640px){ #mls{padding:14px 12px;} #mls .fld{flex-basis:100%;} }
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const mount = document.querySelector('.content') || document.querySelector('.main') || document.body;
  const root = document.createElement('div'); root.id = 'mls';
  root.innerHTML = '<div class="muted" style="font-size:13px;">Loading the ML price slab…</div>';
  mount.appendChild(root);
  if (!window.MLSLAB) { const sc = document.createElement('script'); sc.src = 'assets/js/ml_slab.js?v=261023s'; sc.onload = () => tryRender(); document.body.appendChild(sc); }

  let C = null, dirty = false;
  // 📅 Specific date / person: special days saved straight away (ml_slab.special_save / special_delete), not with "Save chart".
  let PEOPLE = [], SP_OPEN = false, SPF = null, T_DAY = '', T_EMP = 0;
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (t) => t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate());
  const addDays = (d, n) => { const t = new Date(d + 'T00:00:00'); t.setDate(t.getDate() + n); return ymd(t); };
  const TODAY = () => ymd(new Date());
  const dS = (d) => { const t = new Date(d + 'T00:00:00'); return isNaN(t) ? d : t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); };
  const nameOf = (id) => { const p = PEOPLE.find((x) => x.id === Number(id)); return p ? p.name : '#' + id; };
  const SPK = [
    ['bonus', 'Daily target achieved → bonus +', '% on top', 'p1', (v) => '🎯 Target bonus +' + fmtN(v) + '%', 1, 0, 500],
    ['cap', 'Daily target not achieved → at most', '% (0 = no cut)', 'p4', (v) => Number(v) > 0 ? '⬇ Not achieved: at most ' + fmtN(v) + '%' : '⬇ Not achieved: no cut', 0.1, 0, 100],
    ['nocode', 'Order without code → pays', '% of it', 'p3', (v) => '🔑 No code pays ' + fmtN(v) + '%', 1, 0, 100],
    ['codepct', 'Code goal → full commission once', '% of the day\'s orders have a code (0 = off)', 'p3', (v) => Number(v) > 0 ? '🔑 Code goal ' + fmtN(v) + '% of orders' : '🔑 Code goal off', 1, 0, 100],
    ['maxdisc', 'Max discount → more than', '% off = ₹0 (0 = no limit)', 'p6', (v, x) => Number(v) > 0 ? '✂ Max discount ' + fmtN(v) + '%' + (x && x.maxdisc_for === 'all' ? ' (all orders)' : ' (no code)') : '✂ No discount limit', 0.5, 0, 100],
    ['floor', 'Lowest commission →', '%', 'p2', (v) => '⤓ Lowest ' + fmtN(v) + '%', 0.1, 0, 100],
    ['prepaid', 'Prepaid discount →', '% borne by the company', 'p5', (v) => '💳 Prepaid ' + fmtN(v) + '%', 0.5, 0, 20],
  ];
  const spList = () => (C && C.special) || [];
  const spStatus = (x) => { const t = TODAY(); return x.from <= t && x.to >= t ? 'on' : x.from > t ? 'up' : 'past'; };
  const spDates = (x) => { const t = TODAY(); const a = dS(x.from) + (x.to !== x.from ? ' – ' + dS(x.to) : ''); return a + (x.from === x.to && x.from === t ? ' (today)' : ''); };
  const spWho = (x) => (x.emps || []).length ? x.emps.map(nameOf).join(', ') : 'everyone';
  // Orange note under a rule box: today's and the next upcoming special days that change this number.
  function spNote(k) {
    const t = TODAY(), def = SPK.find((d) => d[0] === k);
    const l = spList().filter((x) => x.set && x.set[k] != null && x.to >= t).sort((a, b) => (a.from < b.from ? -1 : 1)).slice(0, 2);
    return l.map((x) => `<span class="ov">📅 ${x.from <= t ? 'Today ' + dS(t) : spDates(x)}: ${esc(def[4](x.set[k], x.set).replace(/^\S+ /, ''))} for ${esc(spWho(x))}</span>`).join('');
  }
  const $ = (id) => root.querySelector('#' + id);

  async function load() {
    try {
      const [d, pp] = await Promise.all([api.get('ml_slab.get'), api.get('ml_slab.people').catch(() => ({ people: [] }))]);
      if (d.error) throw new Error(d.error);
      C = JSON.parse(JSON.stringify(d.chart));
      PEOPLE = (pp && pp.people) || [];
      tryRender();
    } catch (e) { root.innerHTML = `<div class="alert error">The ML price slab could not be loaded (${esc(e.message)}). Is the new api.php uploaded?</div>`; }
  }
  function tryRender() { if (C && window.MLSLAB) render(); }

  function render() {
    const on = !!Number(C.on);
    root.innerHTML = `
      <h3>ML price slab <span class="st ${on ? 'on' : 'off'}">${on ? 'ON' : 'OFF'}</span>
        <label class="tgl" style="margin-left:auto;"><input type="checkbox" id="mlsOn" ${on ? 'checked' : ''}><i></i><span id="mlsOnTxt">${on ? 'Switched on' : 'Switched off'}</span></label></h3>
      <p class="rsub">Each allocated order is paid by its <b>total ML</b> (free ML included) and the <b>price</b> the customer paid: find the size's row, find the price — that column's % is the commission. A price between two columns: see "Price between two columns" below. Below the last column = the lowest % (never less). Orders with no ML (MySales orders, very old orders) or a size outside the chart keep the normal commission. A panel rule, a fixed commission or a commission set by hand still wins.</p>
      <div id="mlsMsg" class="msg"></div>
      <div class="fld" style="margin-bottom:12px; border-color:#c7d2fe; background:#f5f7ff;"><label>Dates this chart applies (order business day)</label>
        <div class="in" style="flex-wrap:wrap;">From <input type="date" id="mlsFrom" style="width:150px;" value="${esc(C.from || '')}"> To <input type="date" id="mlsTo" style="width:150px;" value="${esc(C.to || '')}"> <button type="button" class="x" id="mlsToClr" title="No end date" style="width:auto; padding:0 10px; border-radius:8px; height:30px;">No end</button></div>
        <small>From empty = from the start. <b>To empty = keeps going</b> (no end). Orders outside these dates keep the normal commission. Now: <b id="mlsDates">${esc(MLSLAB.datesTxt(C))}</b></small></div>
      <div class="spbar"><button type="button" class="spbtn ${SP_OPEN ? 'open' : ''}" id="spBtn">📅 Specific date / person <b>${spList().filter((x) => spStatus(x) !== 'past').length}</b></button><span class="muted" style="font-size:12.5px;">Change the numbers below for chosen dates and people · normal rules apply on all other days</span></div>
      ${SP_OPEN ? spHtml() : ''}
      <div class="row3">
        <div class="fld" style="border-color:#bbf7d0; background:#f0fdf4;"><label>Daily target achieved</label><div class="in">bonus + <input type="number" id="mlsBonus" min="0" max="500" step="1" value="${esc(Number(C.bonus) || '')}" placeholder="0"> % on top</div><small>Commission ₹100 + 20% = ₹120, for every order of a day whose target is achieved. No target set that day = no bonus.</small>${spNote('bonus')}</div>
        <div class="fld"><label>Daily target not achieved</label><div class="in">at most <input type="number" id="mlsCap" min="0" max="100" step="0.1" value="${esc(Number(C.cap) || '')}" placeholder="no cut"> %</div><small>Empty = <b>no cut</b> (the order keeps its chart %).</small>${spNote('cap')}</div>
        <div class="fld"><label>Order without code</label><div class="in">pays <input type="number" id="mlsNc" min="0" max="100" step="1" value="${esc(C.nocode)}"> % of it</div><small>50 = half. (While this slab is on, it replaces the order-code rules for these orders.)</small>${spNote('nocode')}</div>
        <div class="fld" style="border-color:#bbf7d0; background:#f0fdf4;"><label>Code goal (per person, per day)</label><div class="in">full commission once <input type="number" id="mlsCode" min="0" max="100" step="1" value="${esc(C.codepct != null ? C.codepct : 65)}"> % of the day's orders have a code</div><small>65 → 6 orders need 4 codes, 10 orders need 7. Once reached, <b>every</b> order of that day pays full — also the ones without a code. Not reached → each no-code order pays the "Order without code" % above. 0 = off.</small>${spNote('codepct')}</div>
        <div class="fld" style="border-color:#fecaca; background:#fff5f5;"><label>Max discount</label><div class="in">more than <input type="number" id="mlsMax" min="0" max="100" step="0.5" value="${esc(Number(C.maxdisc) || '')}" placeholder="off"> % off → ₹0</div>
          <div class="in" style="margin-top:6px;">for <select id="mlsMaxFor" style="width:auto; padding:5px 8px; min-height:0;"><option value="nocode" ${C.maxdisc_for === 'all' ? '' : 'selected'}>orders WITHOUT code only</option><option value="all" ${C.maxdisc_for === 'all' ? 'selected' : ''}>all orders</option></select></div>
          <small>With a code, any discount is allowed (the chart % still applies). Discount = how far the price paid is below the size's chart price (first column), free ML included. Example: ₹1,810 − 10% = ₹1,629 → below ₹1,629 pays ₹0. Empty = no limit.</small>${spNote('maxdisc')}</div>
        <div class="fld" style="border-color:#bbf7d0; background:#f0fdf4;"><label>Lowest commission</label><div class="in">below the last column → <input type="number" id="mlsFloor" min="0" max="100" step="0.1" value="${esc(Number(C.floor) || '')}" placeholder="0"> %</div><small>However low the price, never less than this. No code still halves it (2.8% → 1.4%). Empty = 0%.</small>${spNote('floor')}</div>
        <div class="fld"><label>Price between two columns</label><div class="in"><select id="mlsMode" style="width:auto; padding:5px 8px; min-height:0;"><option value="step" ${C.mode === 'between' ? '' : 'selected'}>the lower column's %</option><option value="between" ${C.mode === 'between' ? 'selected' : ''}>a % in between</option></select></div><small>Lower column: ₹1,690 (between ₹1,700 = 6% and ₹1,670 = 4%) → 4%.</small></div>
        <div class="fld"><label>Prepaid discount</label><div class="in"><input type="number" id="mlsPre" min="0" max="20" step="0.5" value="${esc(C.prepaid)}"> % borne by the company</div><small>A prepaid order is looked up at its price before this discount, so it is not pushed into a lower column.</small>${spNote('prepaid')}</div>
      </div>
      <div class="tester"><b class="t">Try it — what does one order earn?</b>
        <div class="tin">
          <label>Date <input type="date" id="tDay" value="${esc(T_DAY || TODAY())}" style="width:150px; padding:6px 8px; min-height:0;"></label>
          <label>Person <select id="tEmp" style="width:auto; padding:6px 8px; min-height:0;"><option value="0">Everyone</option>${PEOPLE.map((p) => `<option value="${p.id}" ${Number(T_EMP) === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
          <label>Total ML <input type="number" id="tMl" value="100" min="1"></label>
          <label>Price ₹ <input type="number" id="tPr" value="1730" min="0"></label>
          <label><input type="checkbox" id="tPre"> Prepaid</label>
          <label><input type="checkbox" id="tCode" checked> Has code</label>
          <label><input type="checkbox" id="tHit" checked> Target achieved</label>
        </div>
        <div class="tout" id="tOut"></div>
      </div>
      <div class="tools">
        <button type="button" class="btn secondary" id="mlsAddRow">+ Add size</button>
        <button type="button" class="btn secondary" id="mlsAddCol">+ Add column</button>
        <button type="button" class="btn secondary" id="mlsPasteBtn">Paste from Excel</button>
        <span class="sp"></span><span class="muted" style="font-size:12px;">${C.rows.length} sizes · ${C.cols.length} columns · tap a price to change it</span>
      </div>
      <div class="paste" id="mlsPaste"><textarea id="mlsPasteTxt" placeholder="Copy the table from Excel / Google Sheets and paste it here: one size per line — size (ml), then one price per column. Example:&#10;100	1810	1760	1700	…&#10;110	1990	1940	1870	…"></textarea>
        <div class="tools" style="margin-top:6px;"><button type="button" class="btn" id="mlsPasteOk">Replace the prices</button><button type="button" class="btn secondary" id="mlsPasteNo">Cancel</button></div></div>
      <div class="wrap"><table><thead>
        <tr><th class="c0" rowspan="2">Size<br>(ml)</th>${C.cols.map((c, j) => `<th><input class="nm" data-nm="${j}" value="${esc(C.names[j] || '')}" placeholder="name"></th>`).join('')}<th class="lw" title="Every price below the last column pays this % (never less). No code = half.">Lowest</th><th rowspan="2"></th></tr>
        <tr>${C.cols.map((c, j) => `<th><input class="pc" type="number" step="0.1" data-pc="${j}" value="${esc(c)}">%${C.cols.length > 1 ? ` <button type="button" class="x" data-dc="${j}" title="Remove this column">✕</button>` : ''}</th>`).join('')}<th class="lw"><input class="pc" type="number" step="0.1" min="0" id="mlsFloorCol" value="${esc(Number(C.floor) || 0)}">%</th></tr>
      </thead><tbody>${C.rows.map((r, i) => rowHtml(r, i)).join('')}</tbody></table></div>
      <div class="foot"><button type="button" class="btn" id="mlsSave">Save chart</button><span class="muted" id="mlsDirty">${C.updated_at ? 'Last saved ' + esc(C.updated_at) + (C.updated_by ? ' by ' + esc(C.updated_by) : '') : 'Not saved yet — this is the starting chart.'}</span></div>`;
    wire(); test();
  }
  const rowHtml = (r, i) => `<tr data-r="${i}"><td class="c0"><input type="number" data-ml="${i}" value="${esc(r.ml)}"></td>${r.p.map((v, j) => `<td class="c${j + 1}"><input type="number" data-p="${i}|${j}" value="${esc(v)}"></td>`).join('')}<td class="lw" data-lw="${i}">${lowTxt(r)}</td><td><button type="button" class="x" data-dr="${i}" title="Remove this size">✕</button></td></tr>`;

  // "Lowest" column: every price below the row's last price pays the lowest % (auto, from the last column).
  const lowTxt = (r) => 'Below ₹' + (Number(r.p[r.p.length - 1]) || 0).toLocaleString('en-IN');
  function markDirty() { dirty = true; const el = $('mlsDirty'); if (el) { el.textContent = 'Changes not saved yet'; el.style.color = '#b45309'; } }
  function flash(html, cls) { $('mlsMsg').innerHTML = `<div class="alert ${cls || 'success'}">${html}</div>`; }

  function wire() {
    $('mlsOn').onchange = (e) => { C.on = e.target.checked ? 1 : 0; $('mlsOnTxt').textContent = C.on ? 'Switched on — Save to apply' : 'Switched off — Save to apply'; markDirty(); test(); };
    $('mlsCap').oninput = (e) => { C.cap = Number(e.target.value) || 0; markDirty(); test(); };
    $('mlsBonus').oninput = (e) => { C.bonus = Number(e.target.value) || 0; markDirty(); test(); };
    $('mlsNc').oninput = (e) => { C.nocode = Number(e.target.value); markDirty(); test(); };
    if ($('mlsCode')) $('mlsCode').oninput = (e) => { C.codepct = e.target.value === '' ? 0 : Number(e.target.value); markDirty(); test(); };
    $('mlsPre').oninput = (e) => { C.prepaid = Number(e.target.value); markDirty(); test(); };
    $('mlsMax').oninput = (e) => { C.maxdisc = Number(e.target.value) || 0; markDirty(); test(); };
    $('mlsFloor').oninput = (e) => { C.floor = Number(e.target.value) || 0; const fc = $('mlsFloorCol'); if (fc) fc.value = C.floor; markDirty(); test(); };
    $('mlsMode').onchange = (e) => { C.mode = e.target.value === 'between' ? 'between' : 'step'; markDirty(); test(); };
    $('mlsMaxFor').onchange = (e) => { C.maxdisc_for = e.target.value === 'all' ? 'all' : 'nocode'; markDirty(); test(); };
    const dates = () => { C.from = $('mlsFrom').value || ''; C.to = $('mlsTo').value || ''; $('mlsDates').textContent = MLSLAB.datesTxt(C); markDirty(); };
    $('mlsFrom').onchange = dates; $('mlsTo').onchange = dates;
    $('mlsToClr').onclick = () => { $('mlsTo').value = ''; dates(); };
    ['tMl', 'tPr', 'tPre', 'tCode', 'tHit', 'tDay', 'tEmp'].forEach((id) => { $(id).oninput = test; $(id).onchange = test; });
    spWire();
    root.querySelector('table').addEventListener('input', (e) => {
      const t = e.target;
      if (t.id === 'mlsFloorCol') { C.floor = Number(t.value) || 0; const f = $('mlsFloor'); if (f) f.value = C.floor || ''; }
      else if (t.dataset.p) { const [i, j] = t.dataset.p.split('|').map(Number); C.rows[i].p[j] = Number(t.value); if (j === C.rows[i].p.length - 1) { const c = root.querySelector(`td[data-lw="${i}"]`); if (c) c.textContent = lowTxt(C.rows[i]); } }
      else if (t.dataset.ml) C.rows[Number(t.dataset.ml)].ml = Number(t.value);
      else if (t.dataset.pc) C.cols[Number(t.dataset.pc)] = Number(t.value);
      else if (t.dataset.nm) C.names[Number(t.dataset.nm)] = t.value;
      else return;
      markDirty(); test();
    });
    root.querySelector('table').addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.dr) { const i = Number(b.dataset.dr); if (!confirm('Remove the ' + C.rows[i].ml + ' ml row?')) return; C.rows.splice(i, 1); markDirty(); keepDirty(); }
      if (b.dataset.dc) { const j = Number(b.dataset.dc); if (!confirm('Remove the ' + C.cols[j] + '% column?')) return; C.cols.splice(j, 1); C.names.splice(j, 1); C.rows.forEach((r) => r.p.splice(j, 1)); markDirty(); keepDirty(); }
    });
    $('mlsAddRow').onclick = () => {
      const last = C.rows[C.rows.length - 1];
      C.rows.push({ ml: last ? last.ml + 10 : 100, p: last ? last.p.slice() : C.cols.map(() => 0) }); markDirty(); keepDirty();
      const w = root.querySelector('.wrap'); w.scrollTop = w.scrollHeight;
    };
    $('mlsAddCol').onclick = () => {
      const lc = C.cols[C.cols.length - 1];
      C.cols.push(Math.max(0, Math.round((lc - 0.1) * 100) / 100)); C.names.push('');
      C.rows.forEach((r) => r.p.push(Math.round(r.p[r.p.length - 1] * 0.98 / 10) * 10)); markDirty(); keepDirty();
    };
    $('mlsPasteBtn').onclick = () => { $('mlsPaste').style.display = 'block'; $('mlsPasteTxt').focus(); };
    $('mlsPasteNo').onclick = () => { $('mlsPaste').style.display = 'none'; };
    $('mlsPasteOk').onclick = pasteRows;
    $('mlsSave').onclick = save;
  }
  function keepDirty() { render(); if (dirty) markDirty(); }

  function pasteRows() {
    const lines = $('mlsPasteTxt').value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    const rows = [];
    for (const l of lines) {
      const cells = l.split(/\t|,|;|\s{2,}|\s/).map((c) => c.replace(/[₹,★\s]|ml/gi, '')).filter((c) => c !== '');
      const nums = cells.map(Number);
      if (nums.length < 2 || nums.some((v) => !isFinite(v))) continue;   // header lines are skipped
      rows.push({ ml: nums[0], p: nums.slice(1) });
    }
    if (!rows.length) { alert('No rows found. Each line needs: size, then the prices.'); return; }
    const w = rows[0].p.length;
    if (rows.some((r) => r.p.length !== w)) { alert('Every line must have the same number of prices.'); return; }
    if (w !== C.cols.length && !confirm(`The pasted table has ${w} price columns, the chart has ${C.cols.length}. Continue? (Set the % of each column after pasting.)`)) return;
    while (C.cols.length < w) { C.cols.push(0); C.names.push(''); }
    if (C.cols.length > w) { C.cols.length = w; C.names.length = w; }
    C.rows = rows.sort((a, b) => a.ml - b.ml);
    markDirty(); keepDirty();
    flash(`${rows.length} sizes pasted — check them, then <b>Save chart</b>.`, 'success');
  }

  // ---- 📅 Specific date / person ----
  function spHtml() {
    const t = TODAY();
    const l = spList().slice().sort((a, b) => { const o = { on: 0, up: 1, past: 2 }; const sa = spStatus(a), sb = spStatus(b); return o[sa] - o[sb] || (sa === 'past' ? (a.from < b.from ? 1 : -1) : (a.from < b.from ? -1 : 1)); });
    const rows = l.map((x) => {
      const stt = spStatus(x);
      const what = SPK.filter((d) => x.set && x.set[d[0]] != null).map((d) => `<span class="pill ${d[3]}">${esc(d[4](x.set[d[0]], x.set))}</span>`).join('');
      const who = (x.emps || []).length ? x.emps.map((id) => `<span class="pill p2">${esc(nameOf(id))}</span>`).join('') : '<span class="pill p5">All sales employees</span>';
      return `<tr class="${stt === 'past' ? 'past' : ''}"><td style="white-space:nowrap;"><b>${esc(spDates(x))}</b></td><td>${who}</td><td>${what}</td><td>${esc(x.note || '')}</td>
        <td><span class="sst ${stt}">${stt === 'on' ? '● Active today' : stt === 'up' ? 'Upcoming' : 'Past'}</span></td>
        <td style="white-space:nowrap;">${stt === 'past' ? '' : `<button type="button" class="lk" data-spe="${esc(x.id)}">Edit</button>·`}<button type="button" class="lk" data-spc="${esc(x.id)}">Copy</button>·<button type="button" class="lk" data-spd="${esc(x.id)}">Delete</button></td></tr>`;
    }).join('');
    const F = SPF || spNew();
    const tick = (d) => { const on = F.set[d[0]] != null; return `<div class="r"><label><input type="checkbox" data-spt="${d[0]}" ${on ? 'checked' : ''}> ${esc(d[1])}</label> <input type="number" data-spv="${d[0]}" step="${d[5]}" min="${d[6]}" max="${d[7]}" value="${on ? esc(F.set[d[0]]) : ''}" placeholder="${esc(C[d[0]] != null ? fmtN(C[d[0]]) : '')}" ${on ? '' : 'disabled'}> ${esc(d[2])}${d[0] === 'maxdisc' ? ` <select id="spMaxFor" ${on ? '' : 'disabled'}><option value="nocode" ${F.set.maxdisc_for === 'all' ? '' : 'selected'}>orders WITHOUT code</option><option value="all" ${F.set.maxdisc_for === 'all' ? 'selected' : ''}>all orders</option></select>` : ''}</div>`; };
    const wk = addDays(t, (7 - new Date(t + 'T00:00:00').getDay()) % 7);
    const chip = (lbl, a, b) => `<button type="button" class="ch ${F.from === a && F.to === b ? 'on' : ''}" data-spq="${a}|${b}">${lbl}</button>`;
    const left = PEOPLE.filter((p) => F.emps.indexOf(p.id) < 0);
    return `<div class="sp" id="spBox">
      <h4>📅 Specific date / person <small>On these dates these numbers replace the normal rules · everything else stays normal · saved straight away</small></h4>
      <div class="lst">${l.length ? `<table><thead><tr><th>DATES</th><th>WHO</th><th>WHAT CHANGES</th><th>NOTE</th><th>STATUS</th><th></th></tr></thead><tbody>${rows}</tbody></table>` : '<div class="muted" style="font-size:13px; padding:8px 0;">No special days yet — add one below.</div>'}</div>
      <div class="spf">
        <div><h5>1 · DATES</h5>
          <div class="r">From <input type="date" id="spFrom" value="${esc(F.from)}"></div>
          <div class="r">To&nbsp;&nbsp;&nbsp;&nbsp; <input type="date" id="spTo" value="${esc(F.to)}"></div>
          <div class="chs">${chip('Today', t, t)}${chip('Tomorrow', addDays(t, 1), addDays(t, 1))}${chip('This week', t, wk)}</div></div>
        <div><h5>2 · WHO</h5>
          <div class="r"><label><input type="radio" name="spWho" value="all" ${F.who === 'all' ? 'checked' : ''}> All sales employees</label></div>
          <div class="r"><label><input type="radio" name="spWho" value="some" ${F.who === 'some' ? 'checked' : ''}> Only these people:</label></div>
          ${F.who === 'some' ? `<div class="chs">${F.emps.map((id) => `<button type="button" class="ch on" data-spx="${id}">${esc(nameOf(id))} ✕</button>`).join('')}</div>
          <div class="r"><select id="spAdd"><option value="">+ Add person…</option>${left.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></div>` : ''}</div>
        <div><h5>3 · WHAT CHANGES (tick only what you want to change)</h5>
          ${SPK.map(tick).join('')}
          <div class="r">Note <input type="text" id="spNote" maxlength="80" value="${esc(F.note)}" placeholder="e.g. Diwali push"></div>
          <div class="r"><button type="button" class="go" id="spSave">${F.id ? 'Save changes' : 'Save special day'}</button>${F.id ? '<button type="button" class="lk" id="spCancel">Cancel edit</button>' : ''}<span id="spMsg" style="font-size:12.5px;"></span></div></div>
      </div></div>`;
  }
  function spNew() { const t = TODAY(); return { id: '', from: t, to: t, who: 'all', emps: [], set: {}, note: '' }; }
  function spFrom(x, copy) {
    const t = TODAY();
    return { id: copy ? '' : x.id, from: copy ? t : x.from, to: copy ? addDays(t, Math.max(0, (new Date(x.to) - new Date(x.from)) / 864e5)) : x.to,
      who: (x.emps || []).length ? 'some' : 'all', emps: (x.emps || []).map(Number), set: Object.assign({}, x.set || {}), note: x.note || '' };
  }
  function spRedraw() { keepDirty(); const b = $('spBox'); if (b) b.scrollIntoView({ block: 'nearest' }); }
  function spWire() {
    $('spBtn').onclick = () => { SP_OPEN = !SP_OPEN; if (SP_OPEN && !SPF) SPF = spNew(); keepDirty(); };
    const box = $('spBox'); if (!box) return;
    const F = SPF || (SPF = spNew());
    $('spFrom').onchange = (e) => { F.from = e.target.value; if (!F.to || F.to < F.from) F.to = F.from; spRedraw(); };
    $('spTo').onchange = (e) => { F.to = e.target.value; };
    $('spNote').oninput = (e) => { F.note = e.target.value; };
    box.querySelectorAll('input[name=spWho]').forEach((r) => { r.onchange = () => { F.who = r.value; spRedraw(); }; });
    const add = $('spAdd'); if (add) add.onchange = () => { const v = Number(add.value); if (v && F.emps.indexOf(v) < 0) F.emps.push(v); spRedraw(); };
    const mf = $('spMaxFor'); if (mf) mf.onchange = () => { F.set.maxdisc_for = mf.value; };
    box.addEventListener('click', async (e) => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.spq) { [F.from, F.to] = b.dataset.spq.split('|'); spRedraw(); }
      else if (b.dataset.spx) { F.emps = F.emps.filter((id) => id !== Number(b.dataset.spx)); spRedraw(); }
      else if (b.dataset.spe || b.dataset.spc) { const x = spList().find((y) => y.id === (b.dataset.spe || b.dataset.spc)); if (x) { SPF = spFrom(x, !!b.dataset.spc); spRedraw(); } }
      else if (b.dataset.spd) {
        const x = spList().find((y) => y.id === b.dataset.spd); if (!x || !confirm('Delete the special day ' + spDates(x) + ' for ' + spWho(x) + '?')) return;
        try { const d = await api.post('ml_slab.special_delete', { id: x.id }); if (d.error) throw new Error(d.error); C.special = d.special || []; if (SPF && SPF.id === x.id) SPF = spNew(); spRedraw(); flash(esc(d.message || 'Deleted.'), 'success'); }
        catch (er) { flash(esc(er.message), 'error'); }
      }
      else if (b.id === 'spCancel') { SPF = spNew(); spRedraw(); }
      else if (b.id === 'spSave') spSave(b);
    });
    box.addEventListener('change', (e) => {
      const t = e.target;
      if (t.dataset.spt) { const k = t.dataset.spt; if (t.checked) { F.set[k] = C[k] != null ? Number(C[k]) || 0 : 0; if (k === 'maxdisc') F.set.maxdisc_for = C.maxdisc_for === 'all' ? 'all' : 'nocode'; } else { delete F.set[k]; if (k === 'maxdisc') delete F.set.maxdisc_for; } spRedraw(); }
    });
    box.addEventListener('input', (e) => { const t = e.target; if (t.dataset.spv) F.set[t.dataset.spv] = t.value === '' ? 0 : Number(t.value); });
  }
  async function spSave(b) {
    const F = SPF, m = $('spMsg');
    const err = (t) => { m.innerHTML = `<b style="color:#b91c1c;">${esc(t)}</b>`; };
    if (!F.from || !F.to) return err('Pick the dates.');
    if (F.to < F.from) return err('To is before From.');
    if (F.who === 'some' && !F.emps.length) return err('Add at least one person (or pick "All sales employees").');
    if (!SPK.some((d) => F.set[d[0]] != null)) return err('Tick at least one thing that changes.');
    b.disabled = true; b.textContent = 'Saving…';
    try {
      const d = await api.post('ml_slab.special_save', { id: F.id, special: { from: F.from, to: F.to, emps: F.who === 'some' ? F.emps : [], set: F.set, note: F.note } });
      if (d.error) throw new Error(d.error);
      C.special = d.special || []; SPF = spNew(); spRedraw(); flash(esc(d.message || 'Saved.') + ' It applies to the Commission page, My Sales, Orders and the coach straight away.', 'success');
    } catch (er) { b.disabled = false; b.textContent = F.id ? 'Save changes' : 'Save special day'; err(er.message); }
  }

  function test() {
    const out = $('tOut'); if (!out) return;
    T_DAY = $('tDay').value || ''; T_EMP = Number($('tEmp').value) || 0;
    const o = { ml: Number($('tMl').value), amt: Number($('tPr').value), pre: $('tPre').checked, code: $('tCode').checked ? 'X' : '', day: T_DAY, emp: T_EMP };
    if (T_DAY && !MLSLAB.activeOn(C, T_DAY)) { out.innerHTML = `<span style="color:#b91c1c; font-weight:700;">The chart is not active on ${esc(dS(T_DAY))}</span> (chart dates: ${esc(MLSLAB.datesTxt(C))}) — orders that day keep the normal commission.`; return; }
    const r = MLSLAB.orderCom(Object.assign({}, C, { on: 1 }), o, $('tHit').checked);
    if (!r) { out.innerHTML = `<span style="color:#b91c1c; font-weight:700;">${esc(o.ml)} ml is not in the chart</span> — this order keeps the normal commission.`; return; }
    out.innerHTML = `<span class="big">${fmtN(r.pct)}% = ${inr(r.v)}</span> on ${inr(o.amt)}<ol>${r.steps.map((s) => '<li>' + esc(s) + '</li>').join('')}</ol>`;
  }

  async function save() {
    const b = $('mlsSave'); b.disabled = true; b.textContent = 'Saving…';
    try {
      const d = await api.post('ml_slab.save', { chart: { on: C.on, cap: Number(C.cap) || 0, bonus: Number(C.bonus) || 0, nocode: C.nocode, codepct: C.codepct != null ? Number(C.codepct) : 65, prepaid: C.prepaid, maxdisc: C.maxdisc || 0, maxdisc_for: C.maxdisc_for === 'all' ? 'all' : 'nocode', floor: Number(C.floor) || 0, mode: C.mode === 'between' ? 'between' : 'step', from: C.from || '', to: C.to || '', cols: C.cols, names: C.names, rows: C.rows } });
      if (d.error) throw new Error(d.error);
      C = JSON.parse(JSON.stringify(d.chart)); dirty = false;
      render(); flash(esc(d.message || 'Saved.'), 'success');
    } catch (e) { flash(esc(e.message), 'error'); b.disabled = false; b.textContent = 'Save chart'; }
  }
  addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  load();
})();
