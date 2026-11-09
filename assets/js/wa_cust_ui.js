/**
 * wa_cust_ui.js — 👥 the Customers screen of the WhatsApp console (Customers → Contacts). window.WaCust.mount(el, ctx, opts)
 *   1. 📅 Date range FIRST (last wrote / added / last order / got a campaign) · 2. 🏷 tags: include ✅ or leave out 🚫, match any / all
 *   3. More filters (status, orders, ₹ spent, last order, number, salesperson, source, ignored) · search · ready views + your 📁 segments
 *   4. live match count · sort · 25 / 50 / 100 / 200 / 500 a page · tick people, "select page", "select all N"
 *   5. bar: 📣 Manual campaign · ✨ Smart campaign · 🏷 Tag · 📁 Segment · ⬇ Excel
 * Server: wa.contacts (cf = these filters, sort, lim ≤ 500) + wa.cs_* (wa_cust.php). The filters are remembered on this device.
 */
(function () {
  'use strict';
  if (window.WaCust) return;
  const CSS = `.wcs-t{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.wcs-t>div{background:#fff;border:1px solid #eceef2;border-radius:14px;padding:9px 14px;min-width:120px}.wcs-t .m{background:#14161a;color:#fff;border-color:#14161a}.wcs-t small{display:block;font-size:11.5px;opacity:.75}.wcs-t b{font-size:20px;line-height:1.2}

.wcs{--ln:#e3e8ef; --mut:#64748b; --g:#12794a; --gb:#e7f6ee; --r:#b42318; --rb:#fde8e8; display:grid; grid-template-columns:minmax(0,1fr); gap:12px; align-items:start;}
.wcs > *{min-width:0;}
@media(min-width:1100px){ .wcs{grid-template-columns:340px minmax(0,1fr);} .wcs-f{position:sticky; top:8px; max-height:calc(100vh - 140px); overflow:auto;} }
.wcs .wp-cd{margin:0;}
.wcs-h{font-size:12px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:var(--mut); margin:0 0 6px; display:flex; align-items:center; gap:6px;}
.wcs-sec{padding:12px 0; border-top:1px solid #edf1ee;} .wcs-sec:first-child{border-top:0; padding-top:0;}
.wcs-pre{display:flex; flex-wrap:wrap; gap:6px; margin:6px 0;}
.wcs-pre button,.wcs-seg button{border:1px solid var(--ln); background:#fff; border-radius:999px; padding:5px 11px; font:inherit; font-size:12.5px; cursor:pointer; color:#1f2937;}
.wcs-pre button.on,.wcs-seg button.on{background:#0f172a; color:#fff; border-color:#0f172a;}
.wcs-seg{display:inline-flex; gap:4px;}
.wcs-2{display:grid; grid-template-columns:1fr 1fr; gap:6px;} .wcs-2 .wp-in{min-width:0;}
.wcs-tg{display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;}
.wcs-tg .t{display:inline-flex; align-items:center; gap:4px; border-radius:999px; padding:4px 6px 4px 10px; font-size:12.5px; font-weight:600; border:1px solid; cursor:pointer;}
.wcs-tg .t.in{background:var(--gb); color:var(--g); border-color:#bfe6cf;} .wcs-tg .t.out{background:var(--rb); color:var(--r); border-color:#f6c4c4; text-decoration:line-through;}
.wcs-tg .t i{font-style:normal; border:0; background:rgba(0,0,0,.06); border-radius:50%; width:18px; height:18px; display:inline-grid; place-items:center; font-size:11px;}
.wcs-pick{position:relative;}
.wcs-dd{position:absolute; left:0; right:0; top:100%; z-index:30; background:#fff; border:1px solid var(--ln); border-radius:10px; box-shadow:0 12px 30px rgba(15,23,42,.14); max-height:320px; overflow:auto; margin-top:4px;}
.wcs-dd .g{font-size:11px; font-weight:800; color:var(--mut); text-transform:uppercase; padding:8px 10px 2px;}
.wcs-dd button{display:flex; width:100%; justify-content:space-between; gap:8px; border:0; background:none; padding:7px 10px; font:inherit; font-size:13px; text-align:left; cursor:pointer;}
.wcs-dd button:hover,.wcs-dd button:focus{background:#f1f5f9;} .wcs-dd small{color:var(--mut);}
.wcs-views{display:flex; gap:6px; overflow-x:auto; padding-bottom:2px; scrollbar-width:thin;}
.wcs-views button{flex:none; border:1px solid var(--ln); background:#fff; border-radius:10px; padding:6px 10px; font:inherit; font-size:12.5px; cursor:pointer; white-space:nowrap;}
.wcs-views button.on{border-color:var(--g); background:var(--gb); color:var(--g); font-weight:700;}
.wcs-cnt{display:flex; flex-wrap:wrap; align-items:center; gap:8px;} .wcs-cnt b.n{font-size:20px;}
.wcs-on{display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;}
.wcs-on span{background:#f1f5f9; border-radius:999px; padding:3px 4px 3px 10px; font-size:12px; display:inline-flex; align-items:center; gap:4px;}
.wcs-on button{border:0; background:#e2e8f0; border-radius:50%; width:18px; height:18px; font-size:11px; cursor:pointer; line-height:1;}
.wcs-lh{display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:4px 2px 8px;}
.wcs-lh label{display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer;}
.wcs-lh .sp{flex:1;} .wcs-lh select{width:auto; padding:6px 8px; font-size:12.5px;}
.wcs input[type=checkbox]{width:18px; height:18px; margin:0; flex:none; accent-color:#12794a;}
.wcs-r{display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border:1px solid var(--ln); border-radius:12px; background:#fff; margin-bottom:6px;}
.wcs-r.sel{border-color:#9bd8b4; background:#f6fcf8;} .wcs-r .b{flex:1; min-width:0;} .wcs-r .nm{font-weight:700;} .wcs-r .ph{color:var(--mut); font-size:12.5px;}
.wcs-r .wp-pill{margin:4px 4px 0 0;} .wcs-r .mo{border:0; background:none; color:#2563eb; font-size:12px; cursor:pointer; padding:0 2px;}
.wcs-r .ln{font-size:12px; color:var(--mut); margin-top:4px;}
.wcs-pg{display:flex; flex-wrap:wrap; justify-content:center; gap:4px; margin:12px 0;}
.wcs-pg button{min-width:34px; border:1px solid var(--ln); background:#fff; border-radius:8px; padding:5px 8px; font:inherit; font-size:13px; cursor:pointer;}
.wcs-pg button.on{background:#0f172a; color:#fff; border-color:#0f172a;} .wcs-pg button:disabled{opacity:.4; cursor:default;}
.wcs-bar{position:fixed; left:50%; transform:translateX(-50%); width:min(820px, calc(100% - 16px)); box-sizing:border-box; bottom:16px; z-index:60; display:flex; flex-wrap:wrap; align-items:center; gap:6px; background:#0f172a; color:#fff; border-radius:14px; padding:10px 12px; box-shadow:0 10px 30px rgba(15,23,42,.3); margin-top:8px;}
.wcs-bar b{margin-right:4px;} .wcs-bar .sp{flex:1;}
.wcs-bar button{border:0; border-radius:9px; padding:8px 11px; font:inherit; font-size:13px; font-weight:700; cursor:pointer; background:#1e293b; color:#fff;}
.wcs-bar button.p{background:#22c55e; color:#04130e;} .wcs-bar button.x{background:none; color:#cbd5e1; padding:8px;}
.wcs-note{font-size:12px; color:var(--mut);} .wcs-pad{height:90px;}
.wcs-fh{display:none;}
@media(max-width:799px){
  .wcs-fh{display:flex; align-items:center; justify-content:space-between; gap:8px; width:100%; border:0; background:none; padding:0; font:inherit; font-weight:800; font-size:14px; cursor:pointer;}
  .wcs-f.shut .wcs-fb{display:none;} .wcs-f:not(.shut) .wcs-fh{margin-bottom:10px;}
  .wcs-bar{bottom:calc(66px + env(safe-area-inset-bottom)); gap:5px;} .wcs-bar .lg{display:none;} .wcs-bar .sp{display:none;} .wcs-bar b{width:100%;} .wcs-pad{height:150px;} .wcs-bar button{padding:8px 9px; font-size:12.5px;}
  .wcs-r{padding:9px 10px;}
}
html.wxdk .wcs{--ln:#25343e; --gb:#0f2e20; --rb:#3a1515;} html.wxdk .wcs-r,html.wxdk .wcs-dd,html.wxdk .wcs-pre button,html.wxdk .wcs-seg button,html.wxdk .wcs-views button,html.wxdk .wcs-pg button{background:#111b21; color:#e9edef;}
`;
  const DK = [['in', 'Last wrote to us'], ['added', 'Added / imported / first message'], ['order', 'Last order'], ['camp', 'Got a campaign']];
  const PRE = [['', 'Any time'], ['today', 'Today'], ['7', '7 days'], ['30', '30 days'], ['90', '90 days'], ['custom', 'Custom']];
  const ST = [['', 'Everyone'], ['reach', 'Can be messaged'], ['waiting', 'Waiting for our reply'], ['noreply', 'Never wrote to us'], ['optout', 'Opted out']];
  const ORD = [['', 'Any number of orders'], ['0', 'Never ordered'], ['1', 'Ordered 1×'], ['2', 'Ordered 2×'], ['3', 'Ordered 3× or more']];
  const DSL = [['', 'Any time since last order'], ['30', 'Last order within 30 days'], ['60', 'Last order 31–60 days ago'], ['90', 'Last order 61–90 days ago'], ['91', 'Last order over 90 days ago']];
  const SRC = [['', 'Any source'], ['query', 'Query (Meta ad)'], ['campaign', 'Campaign reply'], ['community', 'Community'], ['none', 'No source']];
  const IGN = [['', 'Any'], ['2', 'Ignored 2+ of our messages'], ['3', 'Ignored 3+']];
  const SORT = [['latest', 'Latest message'], ['spent', 'Highest ₹ spent'], ['orders', 'Most orders'], ['order_new', 'Newest order'], ['order_old', 'Oldest order'], ['added', 'Newest customer'], ['name', 'Name A–Z']];
  const PER = [25, 50, 100, 200, 500];
  const BLANK = () => ({ dk: 'in', dp: '', df: '', dt: '', tags: [], nums: [], match: 'any', st: '', ord: '', dsl: '', sp_min: '', sp_max: '', num: '', emp: '', src: '', ign: '' });
  const VIEWS = [
    { k: 'new7', t: '🆕 New this week', cf: { dk: 'added', dp: '7' } },
    { k: 'wrote7', t: '💬 Wrote in 7 days', cf: { dk: 'in', dp: '7' } },
    { k: 'buyers', t: '🛍 Bought, wrote in 30 days', cf: { dk: 'in', dp: '30', tags: [{ k: 'bought', m: 'in' }] } },
    { k: 'repeat', t: '🔁 Repeat buyers', cf: { tags: [{ k: 'repeat', m: 'in' }] } },
    { k: 'top', t: '💎 Top spenders ₹3,000+', cf: { sp_min: '3000' }, sort: 'spent' },
    { k: 'winback', t: '😴 Win back (no order 90+ days)', cf: { dsl: '91', st: 'reach' } },
    { k: 'never', t: '🛒 Wrote in 30 days, never ordered', cf: { dk: 'in', dp: '30', ord: '0' } },
    { k: 'cod', t: '💰 COD pending', cf: { tags: [{ k: 'cod_pending', m: 'in' }] } },
    { k: 'cx', t: '⚠️ Complaints', cf: { tags: [{ k: 'complaint', m: 'in' }] } },
    { k: 'wait', t: '⏳ Waiting for our reply', cf: { st: 'waiting' } },
  ];
  const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const ago = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return ymd(d); };
  // tag groups for the picker (keys and labels, Claude tags have spaces)
  const GROUPS = [
    ['seg', '📁 Your segments', (k, x) => x.kind === 'segment' || /^seg_/.test(k)],
    ['buy', '🛍 Buying', (k) => /^(bought|repeat|vip|not_ordered|cod_pending|came_back)$|premium|buyer|order|deliver|purchase|customer/.test(k)],
    ['prob', '⚠️ Problems', (k) => /complain|issue|fail|broken|wrong|refund|return|damag|missing|not spray|leak|late|delay|bad/.test(k)],
    ['want', '💬 Wants & asks', (k) => /^(wants_buy|price_issue|not_interested)$|want|ask|likes|interest|price|discount|sample|call|offer|scent|perfume|combo|bulk/.test(k)],
    ['time', '🕐 When they write', (k) => /^arrive_/.test(k)],
    ['query', '📥 Query type', (k) => /_query$|query/.test(k)],
    ['imp', '📄 Imports, lists & campaigns', (k, x) => x.kind === 'import' || /^(import_|list_|campaign$|fp_)/.test(k)],
    ['other', '🏷 Other', () => true],
  ];

  /** The filter panel (date range first, tags include / leave out, more filters) on a state S = {cf, q, meta, shut, dd}. Used by the Customers screen and the campaign "Who" step. */
  function filterKit(S, U, onChange, o) {
    o = o || {}; const { esc, $, fmt, sel } = U;
    function presetDates() { const p = S.cf.dp; if (p === '') { S.cf.df = ''; S.cf.dt = ''; } else if (p === 'today') { S.cf.df = ago(0); S.cf.dt = ago(0); } else if (p !== 'custom') { S.cf.df = ago(+p); S.cf.dt = ago(0); } }
    // what goes to the server
    function cfOut() {
      const c = S.cf, o = { dk: c.dk, df: c.df, dt: c.dt }; const inc = c.tags.filter((t) => t.m === 'in').map((t) => t.k), out = c.tags.filter((t) => t.m === 'out').map((t) => t.k);
      if ((c.nums || []).length) o.nums = c.nums;
      if (inc.length) o[c.match === 'all' ? 'tags_all' : 'tags_any'] = inc; if (out.length) o.tags_not = out;
      ['st', 'ord', 'dsl', 'sp_min', 'sp_max', 'num', 'emp', 'src', 'ign'].forEach((k) => { if (c[k] !== '' && c[k] != null) o[k] = c[k]; }); return o;
    }
    const nOn = () => { const c = S.cf; let n = (c.df || c.dt ? 1 : 0) + c.tags.length + ((c.nums || []).length ? 1 : 0); ['st', 'ord', 'dsl', 'num', 'emp', 'src', 'ign'].forEach((k) => { if (c[k] !== '') n++; }); if (c.sp_min !== '' || c.sp_max !== '') n++; return n + (S.q ? 1 : 0); };
    const tagLabel = (k) => { const x = S.meta.tags.find((t) => t.k === k); return x ? x.t : String(k).replace(/_/g, ' '); };
    function drawF(F) {
      const c = S.cf; const n = nOn();
      const optsOf = (L, v) => L.map(([k, l]) => `<option value="${esc(k)}" ${String(k) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('');
      F.innerHTML = `<button type="button" class="wcs-fh" id="cxFh"><span>⚙️ Filters${n ? ' · ' + n + ' on' : ''}</span><span>${S.shut ? '▾' : '▴'}</span></button><div class="wcs-fb">
        <div class="wcs-sec"><div class="wcs-h">📅 Date range</div><select class="wp-in" id="cxDk">${optsOf(DK, c.dk)}</select>
          <div class="wcs-pre">${PRE.map(([k, l]) => `<button type="button" data-dp="${k}" class="${c.dp === k ? 'on' : ''}">${l}</button>`).join('')}</div>
          <div class="wcs-2"><label class="wcs-note">From<input class="wp-in" type="date" id="cxDf" value="${esc(c.df)}"></label><label class="wcs-note">To<input class="wp-in" type="date" id="cxDt" value="${esc(c.dt)}"></label></div></div>
        <div class="wcs-sec"><div class="wcs-h">📱 Number <span class="wcs-note" style="margin-left:auto;text-transform:none;letter-spacing:0">customers who wrote on…</span></div>
          <div class="wcs-pre">${(S.meta.numbers || []).map((x) => `<button type="button" data-nm="${x.id}" class="${(c.nums || []).includes(x.id) ? 'on' : ''}">${esc(String(x.label || x.display || 'Number ' + x.id))}</button>`).join('') || '<span class="wcs-note">No numbers found.</span>'}</div>
          ${(c.nums || []).length ? '<div class="wcs-note" style="margin-top:6px">Tap a number again to remove it. Everyone who wrote on the picked number(s) is included.</div>' : ''}</div>
        <div class="wcs-sec"><div class="wcs-h">🏷 Tags ${c.tags.filter((t) => t.m === 'in').length > 1 ? `<span class="wcs-seg" style="margin-left:auto;text-transform:none;letter-spacing:0">${[['any', 'Any of'], ['all', 'All of']].map(([k, l]) => `<button type="button" data-mt="${k}" class="${c.match === k ? 'on' : ''}">${l}</button>`).join('')}</span>` : ''}</div>
          <div class="wcs-pick"><input class="wp-in" id="cxTq" placeholder="＋ Add a tag — type to search" autocomplete="off"><div id="cxDd"></div></div>
          <div class="wcs-tg">${c.tags.map((t, i) => `<span class="t ${t.m}" data-ti="${i}" title="Tap to switch: include ✅ / leave out 🚫">${t.m === 'in' ? '✅' : '🚫'} ${esc(tagLabel(t.k))}<i data-tx="${i}">✕</i></span>`).join('')}</div>
          ${c.tags.length ? '<div class="wcs-note" style="margin-top:6px">Tap a tag to switch between ✅ include and 🚫 leave out.</div>' : ''}</div>
        <details class="wcs-sec" ${['st', 'ord', 'dsl', 'sp_min', 'sp_max', 'num', 'emp', 'src', 'ign'].some((k) => c[k] !== '') ? 'open' : ''}><summary style="cursor:pointer;font-weight:800;font-size:13px">More filters</summary>
          <div style="display:grid;gap:8px;margin-top:10px">
            <label class="wcs-note">Who${sel('cxSt', ST, c.st)}</label>
            <div class="wcs-2"><label class="wcs-note">Orders${sel('cxOrd', ORD, c.ord)}</label><label class="wcs-note">Last order${sel('cxDsl', DSL, c.dsl)}</label></div>
            <div class="wcs-2"><label class="wcs-note">₹ spent from<input class="wp-in" type="number" min="0" id="cxSp1" value="${esc(c.sp_min)}" placeholder="0"></label><label class="wcs-note">to<input class="wp-in" type="number" min="0" id="cxSp2" value="${esc(c.sp_max)}" placeholder="any"></label></div>
            <div class="wcs-2"><label class="wcs-note">WhatsApp number${sel('cxNum', [['', 'Any number']].concat(S.meta.numbers.map((x) => [x.id, x.label])), c.num)}</label><label class="wcs-note">Salesperson${sel('cxEmp', [['', 'Anyone'], ['-1', 'Nobody (not given)']].concat(S.meta.people.map((x) => [x.emp_id, x.name])), c.emp)}</label></div>
            <div class="wcs-2"><label class="wcs-note">Came from${sel('cxSrc', SRC, c.src)}</label><label class="wcs-note">Ignored us${sel('cxIgn', IGN, c.ign)}</label></div>
          </div></details>
        ${o.search ? `<div class="wcs-sec"><div class="wcs-h">🔎 Search</div><input class="wp-in" id="cxQ" type="search" placeholder="Name or number…" value="${esc(S.q)}"></div>` : ''}</div>`;
      $(F, '#cxFh').onclick = () => { S.shut = !S.shut; F.classList.toggle('shut', S.shut); drawF(F); };
      $(F, '#cxDk').onchange = function () { c.dk = this.value; onChange(); };
      F.querySelectorAll('[data-dp]').forEach((b) => { b.onclick = () => { c.dp = b.dataset.dp; presetDates(); onChange(true); }; });
      $(F, '#cxDf').onchange = function () { c.df = this.value; c.dp = c.df || c.dt ? 'custom' : ''; onChange(true); };
      $(F, '#cxDt').onchange = function () { c.dt = this.value; c.dp = c.df || c.dt ? 'custom' : ''; onChange(true); };
      F.querySelectorAll('[data-mt]').forEach((b) => { b.onclick = () => { c.match = b.dataset.mt; onChange(true); }; });
      F.querySelectorAll('[data-ti]').forEach((x) => { x.onclick = (e) => { const i = +x.dataset.ti; if (e.target.dataset.tx !== undefined) c.tags.splice(i, 1); else c.tags[i].m = c.tags[i].m === 'in' ? 'out' : 'in'; onChange(true); }; });
      F.querySelectorAll('[data-nm]').forEach((b) => { b.onclick = () => { const id = +b.dataset.nm; c.nums = c.nums || []; const i = c.nums.indexOf(id); if (i >= 0) c.nums.splice(i, 1); else c.nums.push(id); onChange(true); }; });
      const tq = $(F, '#cxTq'); tq.onfocus = () => { S.dd = tq.value; drawDd(F); }; tq.oninput = () => { S.dd = tq.value; drawDd(F); };
      tq.onkeydown = (e) => { if (e.key === 'Escape') { tq.blur(); $(F, '#cxDd').innerHTML = ''; } if (e.key === 'Enter') { const b = $(F, '#cxDd button'); if (b) b.click(); } };
      [['cxSt', 'st'], ['cxOrd', 'ord'], ['cxDsl', 'dsl'], ['cxNum', 'num'], ['cxEmp', 'emp'], ['cxSrc', 'src'], ['cxIgn', 'ign']].forEach(([id, k]) => { $(F, '#' + id).onchange = function () { c[k] = this.value; onChange(); }; });
      [['cxSp1', 'sp_min'], ['cxSp2', 'sp_max']].forEach(([id, k]) => { let t = 0; $(F, '#' + id).oninput = function () { clearTimeout(t); const v = this.value; t = setTimeout(() => { c[k] = v; onChange(); }, 450); }; });
      let qt = 0; if ($(F, '#cxQ')) $(F, '#cxQ').oninput = function () { clearTimeout(qt); const v = this.value.trim(); qt = setTimeout(() => { S.q = v; onChange(); }, 300); };
    }
    function drawDd(F) {
      const box = $(F, '#cxDd'); if (!box) return; const q = S.dd.trim().toLowerCase(), have = new Set(S.cf.tags.map((t) => t.k));
      const list = S.meta.tags.filter((t) => !have.has(t.k) && (!q || (t.t + ' ' + t.k).toLowerCase().includes(q)));
      const by = {}; list.forEach((t) => { const k = String(t.k).toLowerCase().replace(/_/g, ' ').replace(/\s+/g, ' '); const g = GROUPS.find((G) => G[2](k.replace(/ /g, '_'), t) || G[2](k, t)); (by[g[0]] = by[g[0]] || []).push(t); });
      let h = GROUPS.filter((G) => by[G[0]]).map((G) => `<div class="g">${G[1]}</div>` + by[G[0]].slice(0, q ? 40 : 25).map((t) => `<button type="button" data-tk="${esc(t.k)}"><span>${esc(t.t)}</span><small>${fmt(t.all)}</small></button>`).join('')).join('');
      if (!h) h = '<div class="wcs-note" style="padding:10px">No tag like that.</div>';
      box.innerHTML = `<div class="wcs-dd">${h}</div>`;
      box.querySelectorAll('[data-tk]').forEach((b) => { b.onmousedown = (e) => e.preventDefault(); b.onclick = () => { S.cf.tags.push({ k: b.dataset.tk, m: 'in' }); S.dd = ''; onChange(true); }; });
      const close = (e) => { if (!e.target.closest || !e.target.closest('.wcs-pick')) { box.innerHTML = ''; document.removeEventListener('pointerdown', close); } }; document.addEventListener('pointerdown', close);
    }
    return { presetDates, cfOut, nOn, tagLabel, drawF, drawDd };
  }
  function ensureCss() { if (!document.getElementById('wcsCss')) { const s = document.createElement('style'); s.id = 'wcsCss'; s.textContent = CSS; document.head.appendChild(s); } }

  function mount(el, ctx, opts) {
    const U = opts.util, { esc, $, fmt, money, pretty, when, loading, errBox, toast, modal, sel } = U;
    ensureCss();
    const LS = 'wcs_v1'; let saved = {}; try { saved = JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) { saved = {}; }
    const S = { cf: Object.assign(BLANK(), saved.cf || {}), q: '', sort: saved.sort || 'latest', per: PER.includes(+saved.per) ? +saved.per : 50, page: 1, rows: [], total: 0, all: 0, reach: null, view: saved.view || '',
      meta: { tags: [], numbers: [], people: [], segments: [] }, sel: { mode: 'none', ids: new Set(), except: new Set() }, seen: new Map(), busy: false, seq: 0, dd: '', shut: window.innerWidth < 800 };
    const K = filterKit(S, U, (a, b) => changed(a, b), { search: true }); const { presetDates, cfOut, nOn, tagLabel } = K; const drawF = () => K.drawF($(el, '#cxF'));
    if (S.cf.dp && S.cf.dp !== 'custom') presetDates();
    const keep = () => { try { localStorage.setItem(LS, JSON.stringify({ cf: S.cf, sort: S.sort, per: S.per, view: S.view })); } catch (e) { } };
    // ---------------------------------------------------------------------------------------------------------- shell
    el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Customers</h2><p>Pick a date range, add tags, and act on the people who match: campaign, tag or save as a segment.</p></div><button type="button" class="wp-btn" id="cxExp">⬇ Download all matching</button><button type="button" class="wp-btn p" id="cxAdd">＋ Add customers</button></div>
      <div id="cxT" class="wcs-t"></div><div class="wcs"><div class="wp-cd wcs-f ${S.shut ? 'shut' : ''}" id="cxF"></div><div><div class="wp-cd" style="margin-bottom:10px"><div id="cxV"></div><div id="cxC" style="margin-top:10px"></div></div><div id="cxL"></div><div id="cxB"></div></div></div></div>`;
    (async () => {   // how many customers we have in all (customer_facts: the same numbers as Dispatch -> Customers)
      try { const d = await api.get('wa.cs_totals', {}); const n = (x) => Number(x || 0).toLocaleString('en-IN'); const t = $(el, '#cxT'); if (!t) return;
        t.innerHTML = '<div class="m"><small>👥 Total customers</small><b>' + n(d.people) + '</b></div><div><small>Repeat (2+ orders)</small><b>' + n(d.repeat) + '</b></div><div><small>New in last 30 days</small><b>' + n(d.new30) + '</b></div><div><small>Store + Online</small><b>' + n(d.both) + '</b></div><div><small>With a risk flag</small><b>' + n(d.risk) + '</b></div>' + (d.spent != null ? '<div><small>Their total spend</small><b>₹' + n(d.spent) + '</b></div>' : '');
      } catch (e) { }
    })();
    $(el, '#cxAdd').onclick = () => opts.addCustomers(() => { loadMeta(); load(); });
    $(el, '#cxExp').onclick = function () { exportCsv({ all: 1, cf: cfOut(), q: S.q, except: [] }, S.total, this); };
    // ---------------------------------------------------------------------------------------------------------- filters (left)
    // ---------------------------------------------------------------------------------------------------------- views (ready + segments)
    function drawV() {
      const segs = S.meta.segments.filter((s) => s.kind !== 'pick');
      $(el, '#cxV').innerHTML = `<div class="wcs-h">⚡ Quick views<span style="margin-left:auto;text-transform:none;letter-spacing:0;font-weight:600">${S.meta.segments.length ? '<button type="button" class="wp-btn s" id="cxSm">📁 Segments (' + S.meta.segments.length + ')</button>' : ''}</span></div>
        <div class="wcs-views">${VIEWS.map((v) => `<button type="button" data-v="${v.k}" class="${S.view === v.k ? 'on' : ''}">${v.t}</button>`).join('')}${segs.map((s) => `<button type="button" data-sg="${s.id}" class="${S.view === 'sg' + s.id ? 'on' : ''}">📁 ${esc(s.name)} <small>${fmt(s.n)}</small></button>`).join('')}</div>`;
      el.querySelectorAll('[data-v]').forEach((b) => { b.onclick = () => { if (S.view === b.dataset.v) { S.view = ''; S.cf = BLANK(); } else { const v = VIEWS.find((x) => x.k === b.dataset.v); S.view = v.k; S.cf = Object.assign(BLANK(), JSON.parse(JSON.stringify(v.cf))); if (v.sort) S.sort = v.sort; presetDates(); } S.q = ''; changed(true, true); }; });
      el.querySelectorAll('[data-sg]').forEach((b) => { b.onclick = () => { const s = S.meta.segments.find((x) => x.id === +b.dataset.sg); if (!s) return; if (S.view === 'sg' + s.id) { S.view = ''; S.cf = BLANK(); } else { S.view = 'sg' + s.id; S.cf = Object.assign(BLANK(), { tags: [{ k: s.tag, m: 'in' }] }); } S.q = ''; changed(true, true); }; });
      const sm = $(el, '#cxSm'); if (sm) sm.onclick = segManager;
    }
    // ---------------------------------------------------------------------------------------------------------- count + active filters
    function drawC() {
      const c = S.cf, on = [];
      if (c.df || c.dt) on.push(['date', (DK.find((x) => x[0] === c.dk) || DK[0])[1] + ': ' + (c.dp && c.dp !== 'custom' ? (PRE.find((x) => x[0] === c.dp) || ['', ''])[1] : (c.df || '…') + ' → ' + (c.dt || 'today'))]);
      c.tags.forEach((t, i) => on.push(['t' + i, (t.m === 'in' ? '✅ ' : '🚫 not ') + tagLabel(t.k)]));
      const lab = (L, v) => (L.find((x) => String(x[0]) === String(v)) || ['', v])[1];
      if (c.st) on.push(['st', lab(ST, c.st)]); if (c.ord) on.push(['ord', lab(ORD, c.ord)]); if (c.dsl) on.push(['dsl', lab(DSL, c.dsl)]);
      if (c.sp_min !== '' || c.sp_max !== '') on.push(['sp', '₹ ' + (c.sp_min || 0) + ' – ' + (c.sp_max || 'any')]);
      if ((c.nums || []).length) on.push(['nums', '📱 ' + c.nums.map((id) => ((S.meta.numbers.find((x) => x.id === id) || {}).label || id)).join(' + ')]);
      if (c.num) on.push(['num', 'Number: ' + ((S.meta.numbers.find((x) => String(x.id) === String(c.num)) || {}).label || c.num)]);
      if (c.emp) on.push(['emp', c.emp === '-1' ? 'Not given to anyone' : 'With ' + ((S.meta.people.find((x) => String(x.emp_id) === String(c.emp)) || {}).name || c.emp)]);
      if (c.src) on.push(['src', lab(SRC, c.src)]); if (c.ign) on.push(['ign', lab(IGN, c.ign)]); if (S.q) on.push(['q', '“' + S.q + '”']);
      $(el, '#cxC').innerHTML = `<div class="wcs-cnt"><span><b class="n">${S.busy && !S.rows.length ? '…' : fmt(S.total)}</b> ${S.total === 1 ? 'customer matches' : 'customers match'}</span><span class="wcs-note">of ${fmt(S.all)}${S.reach != null ? ' · <b>' + fmt(S.reach) + '</b> can be messaged' : ''}</span>
        <span style="flex:1"></span>${on.length ? '<button type="button" class="wp-btn s" id="cxClr">Clear all</button>' : ''}<button type="button" class="wp-btn s" id="cxSave" title="Save these filters as a segment that keeps itself up to date">💾 Save as segment</button></div>
        ${on.length ? `<div class="wcs-on">${on.map(([k, l]) => `<span>${esc(l)}<button type="button" data-off="${k}" aria-label="Remove">✕</button></span>`).join('')}</div>` : ''}`;
      const cl = $(el, '#cxClr'); if (cl) cl.onclick = () => { S.cf = BLANK(); S.q = ''; S.view = ''; changed(true, true); };
      $(el, '#cxSave').onclick = () => segSave('live');
      el.querySelectorAll('[data-off]').forEach((b) => { b.onclick = () => { const k = b.dataset.off;
        if (k === 'date') { c.dp = ''; c.df = ''; c.dt = ''; } else if (k[0] === 't' && k !== 'q') c.tags.splice(+k.slice(1), 1); else if (k === 'sp') { c.sp_min = ''; c.sp_max = ''; } else if (k === 'nums') c.nums = []; else if (k === 'q') S.q = ''; else c[k] = '';
        changed(true, k === 'q'); }; });
    }
    // ---------------------------------------------------------------------------------------------------------- list
    const isSel = (p) => (S.sel.mode === 'all' ? !S.sel.except.has(p) : S.sel.ids.has(p));
    const nSel = () => (S.sel.mode === 'all' ? Math.max(0, S.total - S.sel.except.size) : S.sel.ids.size);
    function rowHtml(r) {
      let h = r.orders === 0 ? '<span class="wp-pill">No order yet</span>' : `<span class="wp-pill ${r.orders >= 3 ? 'p' : (r.orders === 2 ? 'b' : 'ok')}">Ordered ${r.orders}×</span>`;
      if (r.days != null) h += `<span class="wp-pill">${r.days === 0 ? 'Ordered today' : 'Last order ' + r.days + ' d ago'}</span>`; if (r.spent) h += `<span class="wp-pill ok">${money(r.spent)}</span>`;
      if (r.optout) h += '<span class="wp-pill bad">Opted out</span>'; if (r.ignored >= 2) h += `<span class="wp-pill warn">Ignored ${r.ignored}</span>`; if (r.assigned_name) h += `<span class="wp-pill">👤 ${esc(r.assigned_name)}</span>`;
      const tg = r.tags || [], more = tg.length - 3;
      h += tg.map((t, i) => `<span class="wp-pill ${/_query$/.test(t.k) ? 'o' : /^seg_/.test(t.k) ? 'p' : ''}" ${i >= 3 ? 'data-mx hidden' : ''}>🏷 ${esc(t.t)}</span>`).join('') + (more > 0 ? `<button type="button" class="mo" data-mo>+${more} more</button>` : '');
      return `<div class="wcs-r ${isSel(r.phone) ? 'sel' : ''}" data-p="${esc(r.phone)}"><input type="checkbox" data-ck ${isSel(r.phone) ? 'checked' : ''} aria-label="Select"><div class="b"><div><span class="nm">${esc(r.name || pretty(r.phone))}</span>${r.name ? ` <span class="ph">${esc(pretty(r.phone))}</span>` : ''}</div><div>${h}</div>
        <div class="ln">${r.last_in_at ? 'Last wrote ' + esc(when(r.last_in_at)) : 'Has not written to us'}${r.last_number ? ' · via ' + esc(r.last_number) : ''}</div></div>${r.known && r.last_in_at ? '<button type="button" class="wp-btn s" data-oc>Open chat</button>' : ''}</div>`;
    }
    function drawL() {
      const L = $(el, '#cxL'); if (!S.rows.length) { L.innerHTML = S.busy ? loading : '<div class="wp-empty">Nobody matches these filters. Try a wider date range or fewer tags.</div>'; drawB(); return; }
      const pages = Math.max(1, Math.ceil(S.total / S.per)), allPage = S.rows.every((r) => isSel(r.phone)), n = nSel();
      const pg = []; const add = (p) => { if (p >= 1 && p <= pages && !pg.includes(p)) pg.push(p); }; [1, S.page - 2, S.page - 1, S.page, S.page + 1, S.page + 2, pages].forEach(add); pg.sort((a, b) => a - b);
      const from = (S.page - 1) * S.per + 1, to = Math.min(S.total, S.page * S.per);
      L.innerHTML = `<div class="wcs-lh"><label><input type="checkbox" id="cxAp" ${allPage ? 'checked' : ''}> Select page</label>${S.sel.mode !== 'all' && S.total > S.rows.length ? `<button type="button" class="wp-btn s" id="cxAll">Select all ${fmt(S.total)}</button>` : ''}${S.sel.mode === 'all' ? `<span class="wcs-note">All ${fmt(S.total)} matching are selected</span>` : ''}
        <span class="sp"></span><span class="wcs-note">${fmt(from)}–${fmt(to)} of ${fmt(S.total)}</span>${sel('cxSort', SORT, S.sort)}${sel('cxPer', PER.map((x) => [x, x + ' a page']), S.per)}</div>
        <div id="cxRows">${S.rows.map(rowHtml).join('')}</div>
        ${pages > 1 ? `<div class="wcs-pg"><button type="button" data-pg="${S.page - 1}" ${S.page <= 1 ? 'disabled' : ''}>‹</button>${pg.map((p, i) => (i && p - pg[i - 1] > 1 ? '<span class="wcs-note" style="align-self:center">…</span>' : '') + `<button type="button" data-pg="${p}" class="${p === S.page ? 'on' : ''}">${p}</button>`).join('')}<button type="button" data-pg="${S.page + 1}" ${S.page >= pages ? 'disabled' : ''}>›</button></div>` : ''}`;
      $(L, '#cxAp').onchange = function () { S.rows.forEach((r) => setSel(r.phone, this.checked)); drawL(); };
      const al = $(L, '#cxAll'); if (al) al.onclick = () => { S.sel = { mode: 'all', ids: new Set(), except: new Set() }; drawL(); };
      $(L, '#cxSort').onchange = function () { S.sort = this.value; keep(); S.page = 1; load(); };
      $(L, '#cxPer').onchange = function () { S.per = +this.value; keep(); S.page = 1; load(); };
      L.querySelectorAll('[data-pg]').forEach((b) => { b.onclick = () => { S.page = +b.dataset.pg; load(); el.scrollIntoView({ block: 'start' }); }; });
      L.querySelectorAll('.wcs-r').forEach((x) => {
        const p = x.dataset.p; x.querySelector('[data-ck]').onchange = function () { setSel(p, this.checked); x.classList.toggle('sel', this.checked); drawB(); const ap = $(L, '#cxAp'); if (ap) ap.checked = S.rows.every((r) => isSel(r.phone)); };
        const oc = x.querySelector('[data-oc]'); if (oc) oc.onclick = () => ctx.openChat(p);
        const mo = x.querySelector('[data-mo]'); if (mo) mo.onclick = () => { x.querySelectorAll('[data-mx]').forEach((t) => { t.hidden = false; }); mo.remove(); };
      });
      drawB();
    }
    function setSel(p, on) { if (S.sel.mode === 'all') { if (on) S.sel.except.delete(p); else S.sel.except.add(p); } else { if (on) S.sel.ids.add(p); else S.sel.ids.delete(p); S.sel.mode = S.sel.ids.size ? 'some' : 'none'; } }
    const selPayload = () => (S.sel.mode === 'all' ? { all: 1, cf: cfOut(), q: S.q, except: [...S.sel.except] } : { phones: [...S.sel.ids] });
    // ---------------------------------------------------------------------------------------------------------- action bar
    function drawB() {
      const B = $(el, '#cxB'), n = nSel(); if (!n) { B.innerHTML = ''; return; }
      B.innerHTML = `<div class="wcs-pad"></div><div class="wcs-bar"><b>✔ ${fmt(n)} selected</b><span class="sp"></span><button type="button" class="p" data-act="camp">📣 <span class="lg">Manual </span>campaign</button><button type="button" data-act="smart">✨ Smart<span class="lg"> campaign</span></button><button type="button" data-act="tag">🏷 Tag</button><button type="button" data-act="seg">📁 Segment</button><button type="button" data-act="csv">⬇ Excel</button><button type="button" class="x" data-act="x" aria-label="Clear selection">✕</button></div>`;
      B.querySelectorAll('[data-act]').forEach((b) => { b.onclick = () => act(b.dataset.act, b); });
    }
    async function act(a, b) {
      if (a === 'x') { S.sel = { mode: 'none', ids: new Set(), except: new Set() }; drawL(); return; }
      if (a === 'tag') return tagBox(); if (a === 'seg') return segSave('fixed');
      if (a === 'csv') return exportCsv(selPayload(), nSel(), b);
      if (a === 'camp' || a === 'smart') {
        if (a === 'smart' && !window.WaSmart) { toast('Smart campaign is not available here.', true); return; }
        b.disabled = true; const t = b.textContent; b.textContent = 'Getting them ready…';
        try { const r = await api.post('wa.cs_pick', { sel: selPayload() }); window.WaCampGo = { mode: a === 'smart' ? 'smart' : 'normal', seg: r.segment }; ctx.go('campaigns'); }
        catch (e) { toast(e.message, true); } b.disabled = false; b.textContent = t;
      }
    }
    function tagBox() {
      const m = modal('🏷 Tag ' + fmt(nSel()) + ' customers'); const known = S.meta.tags.filter((t) => t.kind !== 'segment');
      m.body.innerHTML = `<label class="wp-fl" style="margin-top:0">Tag</label><input class="wp-in" id="tgN" list="tgDl" placeholder="e.g. diwali_vip" maxlength="34"><datalist id="tgDl">${known.map((t) => `<option value="${esc(t.k)}">${esc(t.t)}</option>`).join('')}</datalist>
        <div class="wp-hint" style="margin:6px 0 12px">Type a new tag or pick one. Letters, numbers and _ only. The tag shows in filters and Campaigns at once.</div><div id="tgE"></div>
        <div class="wp-row"><button type="button" class="wp-btn p" id="tgA">Add tag</button><button type="button" class="wp-btn r" id="tgR">Remove tag</button></div>`;
      const go = async (remove) => {
        const v = $(m.body, '#tgN').value.trim(); if (!v) { $(m.body, '#tgE').innerHTML = errBox('Type a tag.'); return; }
        if (remove && !confirm('Remove “' + v + '” from ' + fmt(nSel()) + ' customers?')) return;
        m.body.querySelectorAll('button').forEach((x) => { x.disabled = true; });
        try { const r = await api.post('wa.cs_tag', { sel: selPayload(), tag: v, remove: remove ? 1 : 0 }); m.close(); toast((remove ? 'Removed “' : 'Tagged “') + r.tag + '” · ' + fmt(r.changed) + ' changed'); loadMeta(); load(); }
        catch (e) { $(m.body, '#tgE').innerHTML = errBox(e.message); m.body.querySelectorAll('button').forEach((x) => { x.disabled = false; }); }
      };
      $(m.body, '#tgA').onclick = () => go(false); $(m.body, '#tgR').onclick = () => go(true); setTimeout(() => $(m.body, '#tgN').focus(), 50);
    }
    function segSave(kind0) {
      const m = modal('📁 Save as segment'); let kind = kind0; const n = nSel();
      const draw = () => {
        m.body.innerHTML = `<label class="wp-fl" style="margin-top:0">Name</label><input class="wp-in" id="sgN" maxlength="80" placeholder="e.g. Diwali buyers 2026" value="${esc(m._n || '')}">
          <div style="display:grid;gap:8px;margin:12px 0">
            <label class="wp-cd" style="margin:0;display:flex;gap:10px;cursor:pointer;padding:10px"><input type="radio" name="sgK" value="live" ${kind === 'live' ? 'checked' : ''}><span><b>🔄 Live</b> — everyone matching these filters (${fmt(S.total)} now). New customers who match join by themselves every hour.</span></label>
            <label class="wp-cd" style="margin:0;display:flex;gap:10px;cursor:pointer;padding:10px;${n ? '' : 'opacity:.5'}"><input type="radio" name="sgK" value="fixed" ${kind === 'fixed' ? 'checked' : ''} ${n ? '' : 'disabled'}><span><b>📌 Fixed</b> — exactly the ${fmt(n)} people selected now. Nobody joins or leaves.</span></label></div>
          <div class="wp-hint" style="margin-bottom:12px">A segment works as a tag: use it in Campaigns, Smart campaigns, Tags &amp; audience and in these filters.</div><div id="sgE"></div>
          <div class="wp-row"><button type="button" class="wp-btn p" id="sgGo">Save segment</button></div>`;
        m.body.querySelectorAll('[name=sgK]').forEach((r) => { r.onchange = () => { m._n = $(m.body, '#sgN').value; kind = r.value; draw(); }; });
        $(m.body, '#sgGo').onclick = async function () {
          const name = $(m.body, '#sgN').value.trim(); if (!name) { $(m.body, '#sgE').innerHTML = errBox('Give the segment a name.'); return; }
          this.disabled = true; this.textContent = 'Saving…';
          try { const r = await api.post('wa.cs_seg_save', { name, kind, cf: cfOut(), q: S.q, sel: kind === 'fixed' ? selPayload() : null }); m.close(); toast('Saved 📁 ' + r.segment.name + ' · ' + fmt(r.segment.n) + ' customers'); await loadMeta(); drawV(); }
          catch (e) { $(m.body, '#sgE').innerHTML = errBox(e.message); this.disabled = false; this.textContent = 'Save segment'; }
        };
      };
      draw(); setTimeout(() => { const x = $(m.body, '#sgN'); if (x) x.focus(); }, 50);
    }
    function segManager() {
      const m = modal('📁 Segments');
      const draw = () => {
        const L = S.meta.segments; const K = { live: '🔄 Live', fixed: '📌 Fixed', pick: '🎯 Picked for a campaign' };
        m.body.innerHTML = L.length ? L.map((s) => `<div class="wp-row" style="padding:8px 0;border-top:1px solid #edf1ee;flex-wrap:nowrap"><div class="wp-grow"><b>${esc(s.name)}</b><div class="wp-hint">${K[s.kind] || s.kind} · ${fmt(s.n)} customers · tag <code>${esc(s.tag)}</code>${s.refreshed_at ? ' · updated ' + esc(when(s.refreshed_at)) : ''}</div></div>
          <button type="button" class="wp-btn s" data-use="${s.id}">Show</button>${s.kind === 'live' ? `<button type="button" class="wp-btn s" data-rf="${s.id}" title="Update now">↻</button>` : ''}<button type="button" class="wp-btn r s" data-del="${s.id}">Delete</button></div>`).join('') : '<div class="wp-empty">No segments yet.</div>';
        m.body.querySelectorAll('[data-use]').forEach((b) => { b.onclick = () => { const s = L.find((x) => x.id === +b.dataset.use); m.close(); S.view = 'sg' + s.id; S.cf = Object.assign(BLANK(), { tags: [{ k: s.tag, m: 'in' }] }); S.q = ''; changed(true, true); }; });
        m.body.querySelectorAll('[data-rf]').forEach((b) => { b.onclick = async () => { b.disabled = true; try { const r = await api.post('wa.cs_seg_refresh', { id: +b.dataset.rf }); toast('Updated · ' + fmt(r.n) + ' customers'); await loadMeta(); draw(); } catch (e) { toast(e.message, true); b.disabled = false; } }; });
        m.body.querySelectorAll('[data-del]').forEach((b) => { b.onclick = async () => { const s = L.find((x) => x.id === +b.dataset.del); if (!confirm('Delete the segment “' + s.name + '”? Its tag is removed from ' + fmt(s.n) + ' customers. Customers are not deleted.')) return; b.disabled = true; try { await api.post('wa.cs_seg_del', { id: s.id }); await loadMeta(); drawV(); draw(); } catch (e) { toast(e.message, true); b.disabled = false; } }; });
      };
      draw();
    }
    async function exportCsv(sel0, n, btn) {
      if (!n) { toast('Nobody to download.', true); return; } const t = btn ? btn.textContent : ''; if (btn) { btn.disabled = true; btn.textContent = 'Preparing…'; }
      try {
        let rows = [];
        if (sel0.all) { const ex = new Set(sel0.except || []); for (let off = 0, i = 0; i < 120; i++) { const d = await api.get('wa.contacts', { cf: JSON.stringify(sel0.cf), q: sel0.q || '', sort: S.sort, off, lim: 500 }); d.contacts.forEach((r) => { if (!ex.has(r.phone)) rows.push(r); }); off += d.contacts.length; if (!d.contacts.length || off >= d.total) break; } }
        else rows = sel0.phones.map((p) => S.seen.get(p)).filter(Boolean);
        const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
        const csv = ['Phone,Name,Orders,Last order,Days since last order,Total spent,Ignored messages,Opted out,Source,Handled by,Last number,Last wrote,Tags'].concat(rows.map((r) => [r.phone, r.name, r.orders, r.last_order, r.days == null ? '' : r.days, r.spent, r.ignored, r.optout ? 'yes' : '', r.source, r.assigned_name, r.last_number, r.last_in_at || '', (r.tags || []).map((x) => x.t).join('; ')].map(q).join(','))).join('\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = 'whatsapp-customers-' + ymd(new Date()) + '.csv'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000);
        toast('Downloaded ' + fmt(rows.length) + ' customers');
      } catch (e) { toast(e.message, true); }
      if (btn) { btn.disabled = false; btn.textContent = t; }
    }
    // ---------------------------------------------------------------------------------------------------------- data
    function changed(redrawF, keepView) {
      if (!keepView) S.view = '';
      if (S.sel.mode === 'all') S.sel = { mode: 'none', ids: new Set(), except: new Set() };   // "all matching" means the old filters: start again
      keep(); S.page = 1; drawF(); drawV(); load();
    }
    async function load() {
      const my = ++S.seq; S.busy = true; drawC(); if (!S.rows.length) drawL(); else { const r = $(el, '#cxRows'); if (r) r.style.opacity = '.55'; }
      const cf = JSON.stringify(cfOut());
      try {
        const [d, c] = await Promise.all([api.get('wa.contacts', { cf, q: S.q, sort: S.sort, off: (S.page - 1) * S.per, lim: S.per }), api.get('wa.cs_count', { cf, q: S.q }).catch(() => null)]);
        if (my !== S.seq || !el.isConnected) return;
        S.rows = d.contacts || []; S.total = d.total || 0; S.all = d.all || 0; S.reach = c ? c.reach : null; S.rows.forEach((r) => S.seen.set(r.phone, r));
        const pages = Math.max(1, Math.ceil(S.total / S.per)); if (S.page > pages) { S.page = pages; return load(); }
      } catch (e) { if (my !== S.seq) return; S.busy = false; $(el, '#cxL').innerHTML = errBox(e.message); return; }
      S.busy = false; drawC(); drawL();
    }
    async function loadMeta() { try { const d = await api.get('wa.cs_meta'); S.meta = { tags: d.tags || [], numbers: d.numbers || [], people: d.people || [], segments: d.segments || [] }; } catch (e) { } if (el.isConnected) { drawF(); drawV(); drawC(); } }
    drawF(); drawV(); drawC(); loadMeta(); load();
    return () => { loadMeta(); load(); };
  }
  /** WaCust.panel(host, {cf (UI form), onChange(uiCf, serverCf)}) — the same filters inside another screen (campaign "Who" step). */
  function panel(host, o) {
    ensureCss(); const P0 = WaPanels.util; const U = { esc: P0.esc, $: P0.$, fmt: P0.fmt, sel: P0.sel };
    const S = { cf: Object.assign(BLANK(), JSON.parse(JSON.stringify(o.cf || {}))), q: '', meta: { tags: [], numbers: [], people: [], segments: [] }, shut: false, dd: '' };
    let K = null; const fire = () => { K.drawF(host); if (o.onChange) o.onChange(S.cf, K.cfOut()); };
    K = filterKit(S, U, fire, { search: false }); if (S.cf.dp && S.cf.dp !== 'custom') K.presetDates();
    host.classList.add('wcs-f'); K.drawF(host);
    api.get('wa.cs_meta').then((d) => { S.meta = { tags: d.tags || [], numbers: d.numbers || [], people: d.people || [], segments: d.segments || [] }; if (host.isConnected) K.drawF(host); }).catch(() => { });
    return { ui: () => S.cf, out: () => K.cfOut(), count: () => K.nOn(), set(cf) { S.cf = Object.assign(BLANK(), cf || {}); K.drawF(host); } };
  }
  window.WaCust = { mount, panel, blank: BLANK };
})();
