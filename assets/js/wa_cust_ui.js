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
  const CSS = `.wcs-t{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px}.wcs-t>div{background:#fff;border:1px solid #eceef2;border-radius:9px;padding:3px 8px;min-width:0}.wcs-t .m{background:#14161a;color:#fff;border-color:#14161a}.wcs-t small{display:block;font-size:9.5px;opacity:.75}.wcs-t b{font-size:12.5px;line-height:1.2}

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
.wcs-cnt{display:flex; flex-wrap:wrap; align-items:center; gap:8px;} .wcs-cnt b.n{font-size:15px;} .wcs-cnt{font-size:12px} .wcs-lh .wp-btn.s{min-height:24px;font-size:11px;padding:1px 9px} .wcs-lh select.wp-in,.wcs-lh select{min-height:28px;font-size:11.5px;padding:2px 6px}
.wcs-on{display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;}
.wcs-on span{background:#f1f5f9; border-radius:999px; padding:3px 4px 3px 10px; font-size:12px; display:inline-flex; align-items:center; gap:4px;}
.wcs-on button{border:0; background:#e2e8f0; border-radius:50%; width:18px; height:18px; font-size:11px; cursor:pointer; line-height:1;}
.wcs-lh{display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:4px 2px 8px;}
.wcs-lh label{display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer;}
.wcs-lh .sp{flex:1;} .wcs-lh select{width:auto; padding:6px 8px; font-size:12.5px;}
.wcs input[type=checkbox]{width:18px; height:18px; margin:0; flex:none; accent-color:#12794a;}
.wcs-r{display:flex; gap:8px; align-items:center; padding:5px 9px; border:1px solid var(--ln); border-radius:10px; background:#fff; margin-bottom:4px;} .wcs-r input[type=checkbox]{width:15px;height:15px;flex:none;margin:0}
.wcs-r.sel{border-color:#9bd8b4; background:#f6fcf8;} .wcs-r .b{flex:1; min-width:0;} .wcs-r .nm{font-weight:700;font-size:13px;} .wcs-r .ph{color:var(--mut); font-size:11px;} .wcs-r .l1{line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap} .wcs-r .l2{line-height:1.2} .wcs-r .oc{min-height:26px;padding:2px 10px;font-size:11px;flex:none}
.wcs-r .cp{display:inline-block;font-size:10.5px;line-height:1.5;padding:0 7px;border-radius:99px;background:rgba(128,128,128,.14);margin:2px 3px 0 0;white-space:nowrap} .wcs-r .cp[hidden]{display:none} .wcs-r .cp.ok{background:#dcfce7;color:#166534} .wcs-r .cp.b{background:#dbeafe;color:#1e40af} .wcs-r .cp.p{background:#ede9fe;color:#5b21b6} .wcs-r .cp.o{background:#ffedd5;color:#9a3412} .wcs-r .cp.bad{background:#fee2e2;color:#b91c1c} .wcs-r .cp.warn{background:#fef3c7;color:#92400e}
.wcs-r .wp-pill{margin:4px 4px 0 0;} .wcs-r .mo{border:0; background:none; color:#2563eb; font-size:10.5px; cursor:pointer; padding:0 2px;}
.wcs-r .ln{font-size:10px; color:var(--mut); margin-left:2px;}
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
  .wcs-r{padding:5px 8px;}
}
html.wxdk .wcs{--ln:#25343e; --gb:#0f2e20; --rb:#3a1515;} html.wxdk .wcs-r,html.wxdk .wcs-dd,html.wxdk .wcs-pre button,html.wxdk .wcs-seg button,html.wxdk .wcs-views button,html.wxdk .wcs-pg button{background:#111b21; color:#e9edef;}
`;
  const DK = [['in', 'Last wrote to us'], ['added', 'Added / imported / first message'], ['order', 'Last order'], ['camp', 'Got a campaign'], ['sent', 'Last message we sent'], ['deliv', 'Last message delivered'], ['fail', 'Last message failed']];
  const PRE = [['', 'Any time'], ['today', 'Today'], ['7', '7 days'], ['30', '30 days'], ['90', '90 days'], ['custom', 'Custom']];
  const ST = [['', 'Everyone'], ['reach', 'Can be messaged'], ['waiting', 'Waiting for our reply'], ['noreply', 'Never wrote to us'], ['optout', 'Opted out']];
  const ORD = [['', 'Any number of orders'], ['0', 'Never ordered'], ['1', 'Ordered 1×'], ['2', 'Ordered 2×'], ['3', 'Ordered 3× or more']];
  const DSL = [['', 'Any time since last order'], ['30', 'Last order within 30 days'], ['60', 'Last order 31–60 days ago'], ['90', 'Last order 61–90 days ago'], ['91', 'Last order over 90 days ago']];
  const SRC = [['', 'Any source'], ['query', 'Query (Meta ad)'], ['campaign', 'Campaign reply'], ['community', 'Community'], ['none', 'No source']];
  const IGN = [['', 'Any'], ['2', 'Ignored 2+ of our messages'], ['3', 'Ignored 3+']];
  const SORT = [['latest', 'Latest message'], ['spent', 'Highest ₹ spent'], ['orders', 'Most orders'], ['order_new', 'Newest order'], ['order_old', 'Oldest order'], ['added', 'Newest customer'], ['name', 'Name A–Z']];
  const PER = [25, 50, 100, 200, 500];
  const BLANK = () => ({ dk: 'in', dp: '', df: '', dt: '', tags: [], nums: [], match: 'any', st: '', ord: '', tier: '', dsl: '', sp_min: '', sp_max: '', num: '', emp: '', src: '', ign: '', fg: [], fc: [], fq: [], fi: [], fb: [], fh: [], fv: [], lby: '', rin_min: '', out_min: '', out_max: '', sc_min: '', sc_max: '' });
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
    // 📊 from the customer funnel, value, quality and reach (counted by mobile number)
    { k: 'hot', t: '🔥 Ready to pay', cf: { fi: ['hot'] } },
    { k: 'intr', t: '👀 Interested', cf: { fi: ['interested'] } },
    { k: 'dnr', t: '📩 Delivered lately, no reply', cf: { dk: 'deliv', dp: '30', fg: ['deliv'], fh: ['ok'] } },
    { k: 'newb', t: '🆕 New buyers — thank them', cf: { fv: ['new'] } },
    { k: 'loyal', t: '⭐ Loyal buyers', cf: { fv: ['loyal'] } },
    { k: 'atrisk', t: '⚠ At risk — win back now', cf: { fv: ['atrisk'] }, sort: 'order_old' },
    { k: 'lapsed', t: '💤 Lapsed buyers', cf: { fv: ['lapsed', 'past'], fh: ['ok'] } },
    { k: 'scoreA', t: '🏆 Quality A (70+)', cf: { fc: ['s_a'] } },
    { k: 'ready', t: '✅ Can receive, never ordered, delivered', cf: { fh: ['ok'], fc: ['reached'], ord: '0' } },
    { k: 'fixnum', t: '📵 Fix these numbers', cf: { fh: ['notwa', 'dead', 'held', 'stopped'] } },
    { k: 'impnd', t: '📥 Excel import, nothing delivered', cf: { fc: ['imp_nodel'] } },
  ];
  const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const ago = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return ymd(d); };
  // tag groups for the picker (keys and labels, Claude tags have spaces)
  const GROUPS = [
    ['seg', '📁 Your segments', (k, x) => x.kind === 'segment' || /^seg_/.test(k)],
    ['val', '💎 Value & quality', (k) => /^(value_|reach_|score_)/.test(k)],
    ['beh', '🧭 Behaviour', (k) => /^(no_haggle|price_sensitive|quick_decider|slow_decider|replies_to_followups|second_order|third_order_plus|one_time_buyer|lapsed_buyer|premium_buyer|budget_buyer)$/.test(k)],
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
      ['st', 'ord', 'tier', 'dsl', 'sp_min', 'sp_max', 'num', 'emp', 'src', 'ign', 'lby', 'rin_min', 'out_min', 'out_max', 'sc_min', 'sc_max'].forEach((k) => { if (c[k] !== '' && c[k] != null) o[k] = c[k]; });
      ['fg', 'fc', 'fq', 'fi', 'fb', 'fh', 'fv'].forEach((k) => { if ((c[k] || []).length) o[k] = c[k]; }); return o;
    }
    const nOn = () => { const c = S.cf; let n = (c.df || c.dt ? 1 : 0) + c.tags.length + ((c.nums || []).length ? 1 : 0); ['st', 'ord', 'tier', 'dsl', 'num', 'emp', 'src', 'ign'].forEach((k) => { if (c[k] !== '') n++; }); if (c.sp_min !== '' || c.sp_max !== '') n++; ['fg', 'fc', 'fq', 'fi', 'fb', 'fh', 'fv'].forEach((k) => { n += (c[k] || []).length ? 1 : 0; }); ['lby', 'rin_min', 'out_min', 'out_max', 'sc_min', 'sc_max'].forEach((k) => { if (c[k] !== '' && c[k] != null) n++; }); return n + (S.q ? 1 : 0); };
    const tagLabel = (k) => { const x = S.meta.tags.find((t) => t.k === k); return x ? x.t : String(k).replace(/_/g, ' '); };
    function drawF(F) {
      const c = S.cf; const n = nOn();
      const FFG = [['never','📭 Never messaged'],['sent','⏳ Sent, not delivered'],['failed','✖ Failed'],['deliv','📩 Delivered, no reply'],['replied','💬 Replied, no order'],['ord1','🛒 Ordered once'],['ord2','🛍 Ordered 2+']], FFC = [['messaged', '📤 Messaged'], ['reached', '✅ Delivered'], ['not_delivered', '📭 Not delivered'], ['failed', '❌ Failed (last 3)'], ['any_failed', '⚠ Had a failed msg'], ['replied', '💬 Replied'], ['never_replied', '🔇 Never replied'], ['wrote_first', '🆕 Wrote first'], ['ordered', '🛍 Ordered'], ['repeat_n', '🔁 2+ orders'], ['named', '🏷 Has a name'], ['unnamed', '❔ No name'], ['assigned', '🧑‍💼 Assigned'], ['unassigned', '📥 Not assigned'], ['imp', '📥 From Excel'], ['imp_nodel', '🚫 Excel, none delivered'], ['optout', '🚫 Opted out'], ['s_a', '🏆 Score A (70+)'], ['s_b', '🥈 Score B (45–69)'], ['s_c', '🥉 Score C (20–44)'], ['s_d', 'Score D (1–19)'], ['dl_no', '🆕 Not downloaded yet'], ['dl_yes', '⬇ Downloaded before']];
      const FFH = [['ok', '✅ Can receive'], ['held', '⏸ Held by Meta'], ['notwa', '📵 Not on WhatsApp'], ['stopped', '🛑 Stopped marketing'], ['dead', '💤 Last 3 failed'], ['optout', '🚫 Opted out']], FFV = [['new', '🆕 New buyer'], ['one', '1️⃣ One-time buyer'], ['repeat', '🔁 Repeat buyer'], ['loyal', '⭐ Loyal (3+)'], ['atrisk', '⚠ At risk'], ['lapsed', '💤 Lapsed'], ['past', '📜 Past buyer (no date)']];
      const FFQ = [['premium', '💎 Premium buyer'], ['std', '🛒 Standard buyer'], ['budget', '🪙 Budget buyer']], FFI = [['hot', '🔥 Ready to pay'], ['interested', '👀 Interested'], ['considering', '🤔 Thinking'], ['not_interested', '🙅 Not interested']];
      const chips = (key, L) => L.map(([k, l]) => `<button type="button" data-fx="${key}|${esc(k)}" class="${(c[key] || []).includes(k) ? 'on' : ''}">${esc(l)}</button>`).join('');
      const fnOn = ['fg', 'fc', 'fq', 'fi', 'fb', 'fh', 'fv'].reduce((n, k) => n + (c[k] || []).length, 0) + ['lby', 'rin_min', 'out_min', 'out_max', 'sc_min', 'sc_max'].filter((k) => c[k] !== '' && c[k] != null).length;
      const optsOf = (L, v) => L.map(([k, l]) => `<option value="${esc(k)}" ${String(k) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('');
      F.innerHTML = `<button type="button" class="wcs-fh" id="cxFh"><span>⚙️ Filters${n ? ' · ' + n + ' on' : ''}</span><span>${S.shut ? '▾' : '▴'}</span></button><div class="wcs-fb">
        <div class="wcs-sec"><div class="wcs-h">🛍 Buying group <span class="wcs-note" style="margin-left:auto;text-transform:none;letter-spacing:0">how often they ordered · what they spend</span></div><div class="wcs-pre">${[['', '', 'Everyone'], ['ord', '0', 'Never ordered'], ['ord', '1', 'Ordered once'], ['ord', '2', 'Ordered twice'], ['ord', '3', '3+ orders'], ['tier', 'prem', '⭐ Premium'], ['tier', 'std', 'Standard'], ['tier', 'bud', 'Budget']].map(([k, v, l]) => `<button type="button" data-bg="${k}|${v}" class="${k === '' ? (c.ord === '' && c.tier === '' ? 'on' : '') : (c[k] === v ? 'on' : '')}">${l}</button>`).join('')}</div></div>
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
        <details class="wcs-sec" ${fnOn ? 'open' : ''}><summary style="cursor:pointer;font-weight:800;font-size:13px">📊 Funnel &amp; conversation${fnOn ? ' · ' + fnOn + ' on' : ''}</summary>
          <div style="display:grid;gap:9px;margin-top:10px">
            ${S.meta.funnel && S.meta.funnel.walking ? '<div class="wcs-note">⏳ The funnel is still being counted — these filters only see customers counted so far.</div>' : ''}
            <div><div class="wcs-note">Furthest step reached (any of)</div><div class="wcs-pre">${chips('fg', FFG)}</div></div>
            <div><div class="wcs-note">Must also be (all of)</div><div class="wcs-pre">${chips('fc', FFC)}</div></div>
            <div><div class="wcs-note">Customer value (any of)</div><div class="wcs-pre">${chips('fv', FFV)}</div></div>
            <div><div class="wcs-note">Can a message reach them? (any of)</div><div class="wcs-pre">${chips('fh', FFH)}</div></div>
            <div><div class="wcs-note">Quality (any of)</div><div class="wcs-pre">${chips('fq', FFQ)}</div></div>
            <div><div class="wcs-note">Interest (any of)</div><div class="wcs-pre">${chips('fi', FFI)}</div></div>
            ${((S.meta.funnel || {}).batches || []).length ? `<div><div class="wcs-note">Excel import (any of)</div><div class="wcs-pre">${chips('fb', ((S.meta.funnel || {}).batches || []).slice(0, 14).map((b) => { const L = ((S.meta.funnel || {}).imports || {})[b.b]; return [b.b, '📥 ' + String(b.b).replace(/^import_/, '') + ' · ' + (L ? L.kept.toLocaleString('en-IN') + ' to send' + (L.left ? ' · ' + L.left.toLocaleString('en-IN') + ' left out of ' + L.total.toLocaleString('en-IN') : '') : b.n)]; }))}</div></div>` : ''}
            <div class="wcs-2"><label class="wcs-note">Last message was by${sel('cxLby', [['', 'Anyone'], ['them', 'Customer (waiting for us)'], ['us', 'Us (no reply yet)']], c.lby)}</label><label class="wcs-note">Customer wrote at least<input class="wp-in" type="number" min="0" id="cxRin" value="${esc(c.rin_min)}" placeholder="any"> times</label></div>
            <div class="wcs-2"><label class="wcs-note">Quality score from (0–100)<input class="wp-in" type="number" min="0" max="100" id="cxSc1" value="${esc(c.sc_min)}" placeholder="0"></label><label class="wcs-note">to<input class="wp-in" type="number" min="0" max="100" id="cxSc2" value="${esc(c.sc_max)}" placeholder="100"></label></div>
            <div class="wcs-2"><label class="wcs-note">We sent at least<input class="wp-in" type="number" min="0" id="cxOmin" value="${esc(c.out_min)}" placeholder="any"></label><label class="wcs-note">…and at most<input class="wp-in" type="number" min="0" id="cxOmax" value="${esc(c.out_max)}" placeholder="any"></label></div>
            <div class="wcs-note">Dates of the last message sent / delivered / failed are in “📅 Date range” above.</div>
          </div></details>
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
      F.querySelectorAll('[data-bg]').forEach((b) => { b.onclick = () => { const [k, v] = b.dataset.bg.split('|'); if (!k) { c.ord = ''; c.tier = ''; } else c[k] = c[k] === v ? '' : v; onChange(true); }; });
      F.querySelectorAll('[data-dp]').forEach((b) => { b.onclick = () => { c.dp = b.dataset.dp; presetDates(); onChange(true); }; });
      $(F, '#cxDf').onchange = function () { c.df = this.value; c.dp = c.df || c.dt ? 'custom' : ''; onChange(true); };
      $(F, '#cxDt').onchange = function () { c.dt = this.value; c.dp = c.df || c.dt ? 'custom' : ''; onChange(true); };
      F.querySelectorAll('[data-fx]').forEach((b) => { b.onclick = () => { const [k, v] = b.dataset.fx.split('|'); c[k] = c[k] || []; const i = c[k].indexOf(v); if (i >= 0) c[k].splice(i, 1); else c[k].push(v); onChange(true); }; });
      if ($(F, '#cxLby')) $(F, '#cxLby').onchange = function () { c.lby = this.value; onChange(true); };
      [['cxRin', 'rin_min'], ['cxOmin', 'out_min'], ['cxOmax', 'out_max'], ['cxSc1', 'sc_min'], ['cxSc2', 'sc_max']].forEach(([id, k]) => { const e = $(F, '#' + id); if (!e) return; let t = 0; e.oninput = function () { clearTimeout(t); const v = this.value; t = setTimeout(() => { c[k] = v; onChange(); }, 450); }; });
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
      <div id="cxT" class="wcs-t"></div><div id="cxS" style="flex-basis:100%"></div><div class="wcs"><div class="wp-cd wcs-f ${S.shut ? 'shut' : ''}" id="cxF"></div><div><div class="wp-cd" style="margin-bottom:10px"><div id="cxV"></div><div id="cxC" style="margin-top:10px"></div></div><div id="cxL"></div><div id="cxB"></div></div></div></div>`;
    (async () => {   // how many customers we have in all (customer_facts: the same numbers as Dispatch -> Customers)
      try { const d = await api.get('wa.cs_totals', {}); const n = (x) => Number(x || 0).toLocaleString('en-IN'); const t = $(el, '#cxT'); if (!t) return;
        t.innerHTML = '<div class="m"><small>👥 Total customers</small><b>' + n(d.people) + '</b></div><div><small>Repeat (2+ orders)</small><b>' + n(d.repeat) + '</b></div><div><small>New in last 30 days</small><b>' + n(d.new30) + '</b></div><div><small>Store + Online</small><b>' + n(d.both) + '</b></div><div><small>With a risk flag</small><b>' + n(d.risk) + '</b></div>' + (d.spent != null ? '<div><small>Their total spend</small><b>₹' + n(d.spent) + '</b></div>' : '');
      } catch (e) { }
    })();
    { const fb = document.createElement('div'); const sb = $(el, '#cxS'); sb.parentNode.insertBefore(fb, sb); funnelBox(fb, false); summary(sb); }   // the funnel is new; the old “at a glance” card stays
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
      if (c.st) on.push(['st', lab(ST, c.st)]); if (c.ord) on.push(['ord', lab(ORD, c.ord)]); if (c.tier) on.push(['tier', { prem: '⭐ Premium', std: 'Standard', bud: 'Budget' }[c.tier] || c.tier]); if (c.dsl) on.push(['dsl', lab(DSL, c.dsl)]);
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
    function rowHtml(r) {   // compact: name + number and the Open chat button on the first line, a few small chips on the second
      const cp = (c, t, x) => `<span class="cp ${c}" ${x || ''}>${t}</span>`, cu = S.meta.cuts || { hi: 3000, lo: 800 };
      let h = r.orders === 0 ? cp('', 'No order') : cp(r.orders >= 3 ? 'p' : r.orders === 2 ? 'b' : 'ok', r.orders + '× ' + (r.spent ? money(r.spent) : ''));
      if (r.orders > 0 && r.spent) { const av = r.spent / r.orders; if (av >= cu.hi) h += cp('p', '💎 Premium'); else if (av <= cu.lo) h += cp('', 'Budget'); }
      if (r.days != null && r.days <= 120) h += cp('', r.days === 0 ? 'ordered today' : r.days + ' d ago');
      const fx = r.fx; if (fx) {   // 📊 funnel facts: quality score band, customer value, reach problem, last delivered
        const sc = +fx.score || 0, band = sc >= 70 ? ['ok', 'A'] : sc >= 45 ? ['b', 'B'] : sc >= 20 ? ['o', 'C'] : sc >= 1 ? ['', 'D'] : null, VAL = { new: '🆕 New', one: '1️⃣ One-time', repeat: '🔁 Repeat', loyal: '⭐ Loyal', atrisk: '⚠ At risk', lapsed: '💤 Lapsed', past: '📜 Past buyer' }, HEA = { held: '⏸ Held by Meta', notwa: '📵 Not on WhatsApp', stopped: '🛑 Stopped marketing', dead: '💤 Last 3 failed' };
        if (band) h += cp(band[0], '🏆 ' + band[1] + ' · ' + sc, 'title="Quality score ' + sc + '/100 — open the customer card to see why"'); if (VAL[fx.val]) h += cp('p', VAL[fx.val]); if (HEA[fx.health]) h += cp('bad', HEA[fx.health]);
        if (fx.last_deliv) { const dd = Math.max(0, Math.floor((Date.now() - new Date(String(fx.last_deliv).replace(' ', 'T')).getTime()) / 86400000)); h += cp('', '✅ delivered ' + (dd === 0 ? 'today' : dd + ' d ago'), 'title="Last message that reached the phone"'); }
        else if (+fx.out_n > 0) h += cp('warn', 'nothing delivered yet');
      }
      if (r.optout) h += cp('bad', 'Opted out'); if (r.ignored >= 2) h += cp('warn', 'Ignored ' + r.ignored);
      if (r.assigned_name) h += cp('', '👤 ' + esc(String(r.assigned_name).split(' ')[0]));
      const tg = r.tags || [], more = tg.length - 2;
      h += tg.map((t, i) => cp(/_query$/.test(t.k) ? 'o' : /^seg_/.test(t.k) ? 'p' : '', esc(t.t), i >= 2 ? 'data-mx hidden' : '')).join('') + (more > 0 ? `<button type="button" class="mo" data-mo>+${more}</button>` : '');
      return `<div class="wcs-r ${isSel(r.phone) ? 'sel' : ''}" data-p="${esc(r.phone)}"><input type="checkbox" data-ck ${isSel(r.phone) ? 'checked' : ''} aria-label="Select"><div class="b"><div class="l1"><span class="nm">${esc(r.name || pretty(r.phone))}</span>${r.name ? ` <span class="ph">${esc(pretty(r.phone))}</span>` : ''}</div><div class="l2">${h}${r.last_in_at ? `<span class="ln">${esc(when(r.last_in_at))}</span>` : ''}</div></div>${r.known && r.last_in_at ? '<button type="button" class="wp-btn s oc" data-oc>Chat</button>' : ''}</div>`;
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
        const l1 = x.querySelector('.l1'); if (l1) { l1.style.cursor = 'pointer'; l1.title = 'Open the customer card'; l1.onclick = () => openCard(p); }
        const mo = x.querySelector('[data-mo]'); if (mo) mo.onclick = () => { x.querySelectorAll('[data-mx]').forEach((t) => { t.hidden = false; }); mo.remove(); };
      });
      drawB();
    }
    function setSel(p, on) { if (S.sel.mode === 'all') { if (on) S.sel.except.delete(p); else S.sel.except.add(p); } else { if (on) S.sel.ids.add(p); else S.sel.ids.delete(p); S.sel.mode = S.sel.ids.size ? 'some' : 'none'; } }
    const selPayload = () => (S.sel.mode === 'all' ? { all: 1, cf: cfOut(), q: S.q, except: [...S.sel.except] } : { phones: [...S.sel.ids] });
    // ---------------------------------------------------------------------------------------------------------- 🪪 the customer card
    const CARD_CSS = `.cc{font-size:12px}.cc h4{margin:10px 0 4px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;opacity:.65}.cc .hd{display:flex;gap:8px;align-items:flex-start;justify-content:space-between}.cc .hd b{font-size:15px}.cc .ph{opacity:.65;font-size:11.5px}
      .cc .bt{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.cc .bt button{min-height:26px;padding:2px 10px;font-size:11.5px}.cc .g{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px}.cc .g div{background:rgba(128,128,128,.1);border-radius:9px;padding:4px 6px;min-width:0}.cc .g small{display:block;font-size:9.5px;opacity:.7}.cc .g b{font-size:12.5px}
      .cc .tg{display:flex;flex-wrap:wrap;gap:3px;margin:2px 0 4px;align-items:center}.cc .tg>small{font-size:9.5px;opacity:.6;width:62px;flex:none}.cc .tg span.c{font-size:11px;line-height:1.5;padding:0 8px;border-radius:99px;background:rgba(128,128,128,.14);cursor:pointer}.cc .tg span.c.on{outline:2px solid #2563eb}
      .cc .why{background:rgba(37,99,235,.09);border-radius:8px;padding:4px 8px;margin:2px 0 4px;font-size:11px}.cc .ln{display:flex;gap:6px;align-items:baseline;border-top:1px solid rgba(128,128,128,.2);padding:3px 0;font-size:11.5px}.cc .ln .d{width:50px;flex:none;opacity:.65;font-size:10.5px}.cc .ln .t{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cc .ok{color:#15803d}.cc .bad{color:#c2262b}`;
    const TAGGRP = [['Value & quality', /^(value_|reach_|score_)/], ['Behaviour', /^(no_haggle|price_sensitive|quick_decider|slow_decider|replies_to_followups|second_order|third_order_plus|one_time_buyer|lapsed_buyer|premium_buyer|budget_buyer|ignored_fu|returning_enquiry|considering|interested|hot)$/], ['Came from', /^(meta_ad|from_ad|community|campaign|instagram|friend|facebook|query)$|_query$/], ['Buying', /^(bought|repeat|vip|not_ordered|cod_pending|came_back|order_query|order_campaign|premium_)/], ['Time', /^arrive_/], ['Lists', /^(seg_|list_|import_|camp_)/]];
    async function openCard(phone) {
      if (!document.getElementById('ccCss')) { const st = document.createElement('style'); st.id = 'ccCss'; st.textContent = CARD_CSS; document.head.appendChild(st); }
      const m = modal('Customer'); m.body.innerHTML = loading;
      let d; try { d = await api.get('wa.cust_card', { phone }); } catch (e) { m.body.innerHTML = errBox(e.message); return; }
      const dtt = (x) => (x ? new Date(String(x).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: '2-digit' }) : '—'), inr = (v) => '₹' + fmt(Math.round(v || 0));
      const SRC = { query: 'Wrote to us first', campaign: 'Replied to a campaign', community: 'From the community', import: 'Imported' }, QT = { meta: 'Meta ad', campaign: 'Campaign', community: 'Community', regenerated: 'Came back', new: 'New', other: 'Other', unclear: 'Not typed', '': 'Query' };
      const T = d.tags || [], used = new Set();
      const groups = TAGGRP.map(([g, re]) => { const a = T.filter((t) => !used.has(t.k) && re.test(t.k)); a.forEach((t) => used.add(t.k)); return [g, a]; }); const rest = T.filter((t) => !used.has(t.k)); if (rest.length) groups.push(['Other', rest]);
      const C = d.campaigns || [], cs = { dl: C.filter((c) => c.status === 'delivered' || c.status === 'read').length, rd: C.filter((c) => c.status === 'read').length, f: C.filter((c) => c.status === 'failed').length, r: C.filter((c) => c.replied).length };
      let lim = 8;
      const paint = () => {
        m.body.innerHTML = `<div class="cc"><div class="hd"><div><b>${esc(d.name || pretty(d.phone))}</b> ${d.name ? `<span class="ph">${esc(pretty(d.phone))}</span>` : ''}<div class="ph">${d.optout ? '<span class="bad">Opted out</span> · ' : ''}${d.assigned ? '👤 ' + esc(d.assigned) + ' · ' : ''}${esc(SRC[d.source] || d.source || '')}</div></div></div>
          <div class="bt">${d.known && d.last_in_at ? '<button type="button" class="wp-btn s" data-cc="chat">💬 Chat</button>' : ''}<button type="button" class="wp-btn s p" data-cc="camp">📣 Add to campaign</button></div>
          <h4>Tags <span style="text-transform:none;letter-spacing:0;font-weight:400">tap one to see why</span></h4>${groups.length ? groups.map(([g, a]) => `<div class="tg"><small>${g}</small>${a.map((t) => `<span class="c" data-tk="${esc(t.k)}">${esc(t.t)}</span>`).join('')}</div>`).join('') : '<div class="wcs-note">No tags yet.</div>'}<div id="ccWhy"></div>
          ${d.orders ? `<h4>Orders</h4><div class="g"><div><small>Orders</small><b>${d.orders.n}</b></div><div><small>Spent</small><b>${inr(d.orders.spent)}</b></div><div><small>Per order</small><b>${inr(d.orders.avg)}</b></div><div><small>${esc(d.orders.tier)}</small><b>${dtt(d.orders.last_order)}</b></div></div>` : '<h4>Orders</h4><div class="wcs-note">No order yet.</div>'}
          <h4>When &amp; how they came</h4><div class="g"><div><small>First wrote</small><b>${dtt(d.first_seen)}</b></div><div><small>Last wrote</small><b>${dtt(d.last_in_at)}</b></div><div><small>We wrote</small><b>${dtt(d.last_out_at)}</b></div><div><small>Queries</small><b>${(d.queries || []).length}</b></div></div>
          ${(d.queries || []).length ? (d.queries || []).slice(0, 6).map((q) => `<div class="ln"><span class="d">${dtt(q.at)}</span><span class="t">${esc(QT[q.type] || q.type)} query${q.answered ? '' : ' · <span class="bad">not answered</span>'}</span>${q.orders ? `<span class="ok">🛒 ${q.orders} · ${inr(q.revenue)}</span>` : ''}</div>`).join('') : ''}
          <h4>Campaigns received <span style="text-transform:none;letter-spacing:0;font-weight:400">${C.length ? C.length + ' · ' + cs.dl + ' delivered · ' + cs.rd + ' read · ' + cs.r + ' replied' + (cs.f ? ' · ' + cs.f + ' failed' : '') : ''}</span></h4>
          ${C.length ? C.slice(0, lim).map((c) => `<div class="ln"><span class="d">${dtt(c.at)}</span><span class="t" title="${esc(c.name)}">${esc(String(c.name).replace(/^house_aisensy_/, '').replace(/_/g, ' '))}${c.type ? ' · ' + esc(c.type) : ''}</span><span class="${c.status === 'failed' ? 'bad' : 'ok'}">${c.status === 'read' ? '✓✓ read' : c.status === 'delivered' ? '✓ delivered' : c.status === 'failed' ? '✗ failed' : '• sent'}</span>${c.replied ? '<span class="ok">💬</span>' : ''}</div>`).join('') + (C.length > lim ? `<div style="text-align:center;margin-top:5px"><button type="button" class="wp-btn s" data-cc="more">Load more (${C.length - lim})</button></div>` : '') : '<div class="wcs-note">No campaign message yet.</div>'}
          <div class="wcs-note" style="margin-top:8px">A person who got a message is never sent the same message again, whichever list they are in.</div></div>`;
        m.body.querySelectorAll('[data-tk]').forEach((x) => { x.onclick = () => { const t = T.find((y) => y.k === x.dataset.tk); m.body.querySelectorAll('[data-tk]').forEach((z) => z.classList.toggle('on', z === x && !x.classList.contains('on'))); $(m.body, '#ccWhy').innerHTML = x.classList.contains('on') && t ? `<div class="why"><b>${esc(t.t)}</b> — ${esc(t.why || 'No reason recorded.')}${t.until ? ' · until ' + esc(dtt(t.until)) : ''}</div>` : ''; }; });
        const chat = m.body.querySelector('[data-cc="chat"]'); if (chat) chat.onclick = () => { m.close(); ctx.openChat(d.phone); };
        const more = m.body.querySelector('[data-cc="more"]'); if (more) more.onclick = () => { lim += 15; paint(); };
        const camp = m.body.querySelector('[data-cc="camp"]'); if (camp) camp.onclick = async () => { camp.disabled = true; try { const r = await api.post('wa.cs_pick', { sel: { phones: [d.phone] } }); window.WaCampGo = { mode: 'normal', seg: r.segment }; m.close(); ctx.go('campaigns'); } catch (e) { toast(e.message, true); camp.disabled = false; } };
      };
      paint();
    }
    // ---------------------------------------------------------------------------------------------------------- action bar
    function drawB() {
      const B = $(el, '#cxB'), n = nSel(); if (!n) { B.innerHTML = ''; return; }
      B.innerHTML = `<div class="wcs-pad"></div><div class="wcs-bar"><b>✔ ${fmt(n)} selected</b><span class="sp"></span><button type="button" class="p" data-act="camp">📣 Campaign</button><button type="button" data-act="tag">🏷 Tag</button><button type="button" data-act="seg">📁 Segment</button><button type="button" data-act="csv">⬇ Excel</button><button type="button" class="x" data-act="x" aria-label="Clear selection">✕</button></div>`;
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
          <div class="wp-hint" style="margin-bottom:12px">A segment works as a tag: use it in Campaigns, Tags &amp; audience and in these filters.</div><div id="sgE"></div>
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
    async function loadMeta() { try { const d = await api.get('wa.cs_meta'); S.meta = { tags: d.tags || [], numbers: d.numbers || [], people: d.people || [], segments: d.segments || [], funnel: d.funnel || {} }; } catch (e) { } if (el.isConnected) { drawF(); drawV(); drawC(); } }
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
    api.get('wa.cs_meta').then((d) => { S.meta = { tags: d.tags || [], numbers: d.numbers || [], people: d.people || [], segments: d.segments || [], funnel: d.funnel || {} }; if (host.isConnected) K.drawF(host); }).catch(() => { });
    return { ui: () => S.cf, out: () => K.cfOut(), count: () => K.nOn(), set(cf) { S.cf = Object.assign(BLANK(), cf || {}); K.drawF(host); } };
  }
  // 📊 THE customer funnel (wa_cfun.php): one set of numbers for the Customers → Contacts strip and the popup of the Campaigns card. Compact, phone-friendly, every number downloads.
  //    GROUPS = each customer in ONE, by the furthest step reached · COUNTS = a customer can be in several. Counted by mobile number, refreshed every hour.
  const FN = (x) => Number(x || 0).toLocaleString('en-IN'), FP = (a, b) => (b > 0 ? Math.round(1000 * a / b) / 10 + '%' : '—'), FE = (v) => String(v == null ? '' : v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const FGROUPS = [['g_never', '📭 Never messaged', 'We never sent anything', '#f1f5f9', '#475569'], ['g_sent', '⏳ Sent, not delivered yet', 'Sent, none confirmed', '#ffedd5', '#9a3412'], ['g_failed', '✖ Failed', 'Never replied; last 3 messages failed', '#fee2e2', '#991b1b'],
    ['g_deliv', '📩 Delivered, no reply', 'Reached the phone', '#dbeafe', '#1e40af'], ['g_replied', '💬 Replied, no order', 'Wrote to us (STOP too)', '#dcfce7', '#166534'], ['g_ord1', '🛒 Ordered once', 'Any channel, ever', '#ede9fe', '#5b21b6'], ['g_ord2', '🛍 Ordered 2+ times', 'Repeat buyers', '#fef3c7', '#92400e']];
  // [data key, download key, label, hint]
  const FTOP = [['total', 'all', '👥 Customers', 'Unique mobile numbers'], ['messaged', 'messaged', '📤 Messaged', 'At least one message from us'], ['reached', 'reached', '✅ Delivered', 'At least one message reached the phone'], ['not_delivered', 'not_delivered', '📭 Not delivered', 'Messaged, nothing reached the phone'],
    ['failed', 'failed', '❌ Failed', 'Never replied; last 3 messages failed'], ['replied', 'replied', '💬 Replied', 'Wrote to us at least once'], ['ordered', 'ordered', '🛍 Ordered', 'Any order ever'], ['repeat_n', 'repeat_n', '🔁 2+ orders', 'Repeat buyers']];
  const FMORE = [['never_replied', 'never_replied', '🔇 Never replied'], ['wrote_first', 'wrote_first', '🆕 Wrote first'], ['any_failed', 'any_failed', '⚠ Had a failed msg'], ['all_failed', 'all_failed', '❌ Every msg failed'], ['optout', 'optout', '🚫 Opted out'], ['dl_no', 'dl_no', '🆕 Not downloaded yet'], ['dl_yes', 'dl_yes', '⬇ Downloaded before']];
  const FPEOPLE = [['named', 'named', '🏷 Named'], ['unnamed', 'unnamed', '❔ No name'], ['assigned', 'assigned', '🧑‍💼 Assigned to staff'], ['unassigned', 'unassigned', '📥 Not assigned'], ['q_premium', 'q_premium', '💎 Premium buyer'], ['q_std', 'q_std', '🛒 Standard buyer'], ['q_budget', 'q_budget', '🪙 Budget buyer'],
    ['s_a', 's_a', '🏆 Score A (70+)'], ['s_b', 's_b', '🥈 Score B (45–69)'], ['s_c', 's_c', '🥉 Score C (20–44)'], ['s_d', 's_d', 'Score D (1–19)'],
    ['v_new', 'v_new', '🆕 New buyer'], ['v_one', 'v_one', '1️⃣ One-time buyer'], ['v_repeat', 'v_repeat', '🔁 Repeat buyer'], ['v_loyal', 'v_loyal', '⭐ Loyal (3+)'], ['v_atrisk', 'v_atrisk', '⚠ At risk'], ['v_lapsed', 'v_lapsed', '💤 Lapsed'], ['v_past', 'v_past', '📜 Past buyer (date unknown)'],
    ['h_ok', 'h_ok', '✅ Can receive'], ['h_held', 'h_held', '⏸ Held by Meta'], ['h_notwa', 'h_notwa', '📵 Not on WhatsApp'], ['h_stopped', 'h_stopped', '🛑 Stopped marketing'], ['h_dead', 'h_dead', '💤 Last 3 failed'],
    ['i_hot', 'i_hot', '🔥 Ready to pay'], ['i_interested', 'i_interested', '👀 Interested'], ['i_considering', 'i_considering', '🤔 Thinking'], ['i_not', 'i_not', '🙅 Not interested']];
  const FCSS = '.cf{--b:#e5e7eb}.cf-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:4px}.cf-t{border:1px solid var(--b);background:#fff;border-radius:8px;padding:3px 6px;text-align:left;font:inherit;color:inherit;cursor:pointer;min-width:0}.cf-t small{display:block;font-size:9.5px;opacity:.72;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cf-t b{font-size:13px}.cf-t i{display:block;font-size:9px;opacity:.6;font-style:normal}.cf-t.k{border-color:#cbd5e1;background:#f8fafc}'
    + '.cf details{margin-top:6px;border:1px solid var(--b);border-radius:9px;background:#fff}.cf summary{cursor:pointer;padding:5px 9px;font-size:12px;font-weight:700;list-style:none}.cf summary::-webkit-details-marker{display:none}.cf summary:before{content:"▸ ";opacity:.6}.cf details[open]>summary:before{content:"▾ "}.cf .bd{padding:0 8px 8px}'
    + '.cf table{width:100%;border-collapse:collapse;font-size:12px}.cf td{padding:4px 3px;border-top:1px solid #f1f5f9;vertical-align:middle}.cf td.r{text-align:right;white-space:nowrap}.cf .pill{border-radius:6px;padding:1px 6px;font-weight:700;font-size:11.5px}.cf .ghost{border:1px solid #cbd5e1;background:#fff;border-radius:7px;padding:1px 7px;font:inherit;font-size:11px;cursor:pointer}'
    + '.cf .hd{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:5px}.cf .seg{display:inline-flex;border:1px solid var(--b);border-radius:8px;overflow:hidden}.cf .seg button{border:0;padding:3px 8px;font:inherit;font-size:11.5px;font-weight:600;cursor:pointer;background:#fff;color:#334155}.cf .seg button.on{background:#0f172a;color:#fff}'
    + '@media(max-width:560px){.cf-g{grid-template-columns:repeat(2,minmax(0,1fr))}.cf .hd .sp{width:100%}}html.wxdk .cf-t,html.wxdk .cf details,html.wxdk .cf .seg button,html.wxdk .cf .ghost{background:#111b21;color:#e9edef;border-color:#25343e}';
  function funnelCss() { if (!document.getElementById('cfCss')) { const s = document.createElement('style'); s.id = 'cfCss'; s.textContent = FCSS; document.head.appendChild(s); } }
  function funnelBox(box, full) {
    if (!box) return; funnelCss(); let src = 'all', D = null, err = '', busy = false; const openS = {};
    const draw = () => { if (!box.isConnected) return; const g = (k) => (D ? D[k] : 0); const T = (k, dk, l, tip, sub) => `<button type="button" class="cf-t" data-fk="${dk}" title="${FE(tip || l)} — tap to download"><small>${l}</small><b>${FN(g(k))}</b> <span style="float:right;font-size:10px;opacity:.45">⬇</span><i>${sub != null ? sub : (k === 'total' ? '' : FP(g(k), g('total')))}</i></button>`;
      let h = `<div class="wp-cd cf" style="padding:8px 9px;margin:0 0 8px"><div class="hd"><b style="font-size:${full ? 17 : 13}px">📊 Customer funnel</b><span class="seg">${[['all', 'All'], ['query', 'Query'], ['campaign', 'Campaign']].map(([k, l]) => `<button type="button" data-s="${k}" class="${src === k ? 'on' : ''}">${l}</button>`).join('')}</span>
        <span style="margin-left:auto;display:inline-flex;gap:4px;align-items:center"><button type="button" class="ghost" id="cfRun" title="Count again now" ${busy ? 'disabled' : ''}>${busy ? '…' : '↻'}</button><button type="button" class="ghost" id="cfDl">⬇ Excel</button></span><span class="sp" style="font-size:10px;opacity:.65;flex-basis:100%">${D && D.walking ? 'still counting — ' + FN(D.total) + ' numbers so far · ' : ''}${D && D.at ? 'updated ' + FE(String(D.at).slice(5, 16)) + ' · every hour · counted by mobile number' : ''}</span></div>`;
      if (err) h += `<div style="background:#fee2e2;color:#991b1b;border-radius:8px;padding:5px 9px;font-size:12px;margin-bottom:5px">${FE(err)}</div>`;
      if (!D) h += '<span class="wp-hint">Counting customers…</span>';
      else {
        h += `<div class="cf-g">${FTOP.map(([k, dk, l, tip]) => T(k, dk, l, tip, k === 'total' ? 'Query ' + FN(g('from_query')) + ' · Camp. ' + FN(g('from_campaign')) : null)).join('')}</div>`;
        const det = (id, title, body) => `<details data-d="${id}" ${openS[id] || full && id === 'grp' ? 'open' : ''}><summary>${title}</summary><div class="bd">${body}</div></details>`;
        h += det('grp', 'Groups — each customer in one &amp; why they failed', `<table>${FGROUPS.map(([k, l, sub, bg, fg]) => `<tr><td><span class="pill" style="background:${bg};color:${fg}">${l}</span><div style="font-size:10.5px;opacity:.65">${sub}</div></td><td class="r"><b>${FN(g(k))}</b><div style="font-size:10.5px;opacity:.65">${FP(g(k), g('total'))}</div></td><td class="r"><button type="button" class="ghost" data-fk="${k}">⬇</button></td></tr>`).join('')}</table>
          <div style="font-weight:700;font-size:12px;margin:8px 0 2px">Why they failed (${FN(g('failed'))})</div><table>${(D.reasons || []).length ? D.reasons.map((r) => `<tr><td>${FE(r.text)}</td><td class="r"><b>${FN(r.n)}</b></td></tr>`).join('') : '<tr><td style="opacity:.65">Nobody failed.</td></tr>'}</table>`);
        h += det('more', 'More counts', `<div class="cf-g">${FMORE.map(([k, dk, l]) => T(k, dk, l)).join('')}</div>`);
        h += det('imp', `Excel imports <span style="font-weight:500;opacity:.7">· ${FN(g('imp'))} numbers · ${FN(g('imp_nodel'))} not delivered</span>`, `<div class="cf-g">${T('imp', 'imp', '📥 Imported', 'Numbers saved from an Excel import')}${T('imp_never', 'imp_never', '📭 Never messaged')}${T('imp_deliv', 'imp_deliv', '✅ Delivered')}${T('imp_nodel', 'imp_nodel', '🚫 Not delivered', 'Messages sent, not one delivered')}</div>
          <table style="margin-top:6px"><tr style="font-size:10.5px;opacity:.65"><td>Batch</td><td class="r">Numbers</td><td class="r">Delivered</td><td class="r">Not deliv.</td><td></td></tr>${(D.batches || []).map((b) => `<tr><td>${FE(b.b.replace(/^import_/, ''))}</td><td class="r">${FN(b.n)}</td><td class="r">${FP(b.dl, b.n)}</td><td class="r">${FN(b.nd)}</td><td class="r"><button type="button" class="ghost" data-fb="${FE(b.b)}">⬇</button></td></tr>`).join('') || '<tr><td colspan="5" style="opacity:.65">No import batches counted yet.</td></tr>'}</table>`);
        h += det('ppl', 'People &amp; quality', `<div class="cf-g">${FPEOPLE.map(([k, dk, l]) => T(k, dk, l)).join('')}</div><div class="wp-hint" style="margin-top:4px">The quality score (0–100) adds up replies, orders, spend, whether messages reach them and their interest — open a customer card to see why. Customer value comes from how recently and how often they ordered; “can receive” from why their last messages failed. Quality and interest come from the automatic tags (premium / budget buyer, ready to pay, interested…).</div>`);
        h += det('num', 'Messages &amp; WhatsApp numbers', `<div class="cf-g">${[['📨 Sent', 'm_total'], ['✅ Delivered / read', 'm_deliv'], ['⏳ Not confirmed', 'm_sent'], ['❌ Failed', 'm_fail'], ['📥 Received', 'm_in']].map(([l, k]) => `<div class="cf-t" style="cursor:default"><small>${l}</small><b>${FN(g(k))}</b><i>${k === 'm_total' || k === 'm_in' ? 'messages' : FP(g(k), g('m_total')) + ' of sent'}</i></div>`).join('')}</div>
          <table style="margin-top:6px"><tr style="font-size:10.5px;opacity:.65"><td>Number</td><td class="r">Customers</td><td class="r">Sent</td><td class="r">Delivered</td><td class="r">Failed</td><td></td></tr>${(D.numbers || []).map((n) => `<tr><td>${FE(n.name)}</td><td class="r">${FN(n.cust)}</td><td class="r">${FN(n.sent)}</td><td class="r">${FP(n.dl, n.sent)}</td><td class="r">${FP(n.f, n.sent)}</td><td class="r"><button type="button" class="ghost" data-fn="${n.id}">⬇</button></td></tr>`).join('') || '<tr><td colspan="6" style="opacity:.65">No numbers counted yet.</td></tr>'}</table><div class="wp-hint" style="margin-top:4px">Message counts per number are refreshed every 6 hours.</div>`);
        const H = D.hist || [], A = H.length ? H[H.length - 1] : null, B = H.length > 1 ? H[0] : null, dl = (k) => { const v = (A ? A[k] : 0) - (B ? B[k] : 0); return (v > 0 ? '+' : '') + FN(v); };
        h += det('trend', 'Trend — since ' + (B ? FE(B.d.slice(5)) : '…'), B ? `<table>${[['Customers', 'total'], ['Delivered', 'reached'], ['Not delivered', 'not_delivered'], ['Failed', 'failed'], ['Replied', 'replied'], ['Ordered', 'ordered'], ['2+ orders', 'repeat_n']].map(([l, k]) => `<tr><td>${l}</td><td class="r"><b>${FN(A[k])}</b></td><td class="r" style="opacity:.75">${dl(k)}</td></tr>`).join('')}</table><div class="wp-hint">One saved copy per day (${H.length} day${H.length === 1 ? '' : 's'} so far).</div>` : '<span class="wp-hint">The trend starts after the second daily copy.</span>');
      }
      box.innerHTML = h + '</div>';
      box.querySelectorAll('[data-s]').forEach((b) => { b.onclick = () => { src = b.dataset.s; load(); }; });
      box.querySelectorAll('details[data-d]').forEach((d) => { d.addEventListener('toggle', () => { openS[d.dataset.d] = d.open; }); });
      box.querySelectorAll('[data-fk]').forEach((b) => { b.onclick = () => funnelDownload(b.dataset.fk, D, src, {}); });
      box.querySelectorAll('[data-fb]').forEach((b) => { b.onclick = () => funnelDownload('all', D, src, { b: b.dataset.fb }); });
      box.querySelectorAll('[data-fn]').forEach((b) => { b.onclick = () => funnelDownload('all', D, src, { n: b.dataset.fn }); });
      const dl2 = box.querySelector('#cfDl'); if (dl2) dl2.onclick = () => funnelDownload('all', D, src, {});
      const rn = box.querySelector('#cfRun'); if (rn) rn.onclick = async () => { busy = true; draw(); try { await api.post('wa.cf_run', {}); } catch (e) { err = e.message; } busy = false; load(); };
    };
    async function load() { err = ''; try { D = await api.get('wa.cf_stats', { src }); } catch (e) { err = e.message; } draw(); }
    draw(); load();
  }
  function funnelPopup() {
    const ov = document.createElement('div'); ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:400;display:flex;align-items:flex-start;justify-content:center;padding:8px;overflow:auto';
    ov.innerHTML = '<div style="background:#f3f5f9;border-radius:16px;max-width:900px;width:100%;padding:10px 10px 14px;color:#0f172a"><div style="text-align:right"><button type="button" class="wp-btn s" id="cfX">✕ Close</button></div><div id="cfB"></div></div>'; document.body.appendChild(ov);
    ov.addEventListener('click', (e) => { if (e.target === ov) ov.remove(); }); ov.querySelector('#cfX').onclick = () => ov.remove(); funnelBox(ov.querySelector('#cfB'), true);
  }
  function funnelDownload(k0, D, src, ex) {
    const ov = document.createElement('div'); ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:410;display:flex;align-items:center;justify-content:center;padding:10px';
    const cnt = (k) => (D ? (k === 'all' ? D.total : (D[k] != null ? D[k] : null)) : null), opt = (k, l) => `<option value="${k}" ${k === k0 ? 'selected' : ''}>${FE(l.replace(/^\S+\s/, ''))}${cnt(k) != null ? ' (' + FN(cnt(k)) + ')' : ''}</option>`;
    const nm = ex.n && D ? ((D.numbers || []).find((n) => String(n.id) === String(ex.n)) || {}).name : '';
    ov.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:440px;width:100%;max-height:92vh;overflow:auto;padding:14px;color:#0f172a"><b style="font-size:15px">⬇ Download customers</b>${ex.b ? `<div class="wp-hint">Import batch ${FE(ex.b.replace(/^import_/, ''))}</div>` : ''}${ex.n ? `<div class="wp-hint">WhatsApp number ${FE(nm || ex.n)}</div>` : ''}
      <label class="wp-fl">Which customers</label><select class="wp-in" id="dlK"><optgroup label="Groups (one each)">${FGROUPS.map((x) => opt(x[0], x[1])).join('')}</optgroup><optgroup label="Counts">${FTOP.concat(FMORE).map((x) => opt(x[1], x[2])).join('')}</optgroup><optgroup label="Imports">${[['imp', '📥 Imported from Excel'], ['imp_nodel', '🚫 Imported, none delivered'], ['imp_never', '📭 Imported, never messaged'], ['imp_deliv', '✅ Imported, delivered']].map((x) => opt(x[0], x[1])).join('')}</optgroup><optgroup label="People &amp; quality">${FPEOPLE.map((x) => opt(x[1], x[2])).join('')}</optgroup></select>
      <label class="wp-fl">Rows in each file</label><select class="wp-in" id="dlN"><option value="1000">1,000</option><option value="2000">2,000</option><option value="3000">3,000</option><option value="5000">5,000</option><option value="10000">10,000</option><option value="0">Biggest file allowed (Excel 50,000 · CSV 200,000)</option></select>
      <label class="wp-fl">File type</label><select class="wp-in" id="dlT"><option value="x">Excel (.xlsx)</option><option value="c">CSV (lighter, opens in Excel)</option></select>
      <label class="wp-fl">How many customers to take <small>(empty = everybody)</small></label><input class="wp-in" id="dlTake" type="number" min="1" placeholder="e.g. 5000" inputmode="numeric">
      <label style="display:flex;gap:8px;align-items:flex-start;margin:8px 0 2px;font-size:13px"><input type="checkbox" id="dlSkip" checked style="margin-top:3px"><span>Skip customers I already downloaded before${D && D.dl_yes ? ' <b>(' + FN(D.dl_yes) + ')</b>' : ''}</span></label>
      <label style="display:flex;gap:8px;align-items:flex-start;margin:2px 0 6px;font-size:13px"><input type="checkbox" id="dlRem" checked style="margin-top:3px"><span>Remember these as downloaded, so the next download gives different customers</span></label>${D && D.dl_yes ? '<div style="margin-bottom:6px"><button type="button" class="wp-btn s" id="dlForget">Forget all earlier downloads</button></div>' : ''}
      <label style="display:flex;gap:8px;align-items:center;margin:8px 0;font-size:13px"><input type="checkbox" id="dlS"> Only Name + Mobile</label>
      <div class="wp-hint" style="margin-bottom:6px">${src === 'all' ? 'Query + Campaign.' : 'Only “' + (src === 'query' ? 'Query' : 'Campaign') + '”.'} Bigger lists are split into several files automatically; your browser may ask to allow multiple downloads.</div><div id="dlE" style="color:#991b1b;font-size:12.5px"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="wp-btn" id="dlC">Cancel</button><button type="button" class="wp-btn p" id="dlGo">Download</button></div></div>`;
    document.body.appendChild(ov); const Q = (s) => ov.querySelector(s); Q('#dlC').onclick = () => ov.remove(); ov.addEventListener('click', (e) => { if (e.target === ov) ov.remove(); });
    const HEAD = ['Name', 'Mobile', 'Group', 'Reason', 'From', 'Last campaign', 'Replied', 'Opted out', 'Messages sent', 'Delivered / read', 'Sent, not confirmed', 'Failed', 'Messages received', 'Orders', 'Quality', 'Interest', 'Import batch', 'WhatsApp number', 'Quality score', 'Customer value', 'Can receive?', 'Has name', 'Assigned', 'First message from us', 'Last message from us', 'Last message from them'];
    const LAB = { never: 'Never messaged', sent: 'Sent, not delivered yet', failed: 'Failed', deliv: 'Delivered, no reply', replied: 'Replied, no order', ord1: 'Ordered once', ord2: 'Ordered 2+ times' }, QL = { premium: 'Premium buyer', budget: 'Budget buyer', std: 'Standard buyer' }, IL = { hot: 'Ready to pay', interested: 'Interested', considering: 'Thinking', not_interested: 'Not interested' };
    const VL = { new: 'New buyer', one: 'One-time buyer', repeat: 'Repeat buyer', loyal: 'Loyal (3+)', atrisk: 'At risk', lapsed: 'Lapsed', past: 'Past buyer (date unknown)' }, HL = { ok: 'Can receive', held: 'Held by Meta', notwa: 'Not on WhatsApp', stopped: 'Stopped marketing', dead: 'Last 3 failed', optout: 'Opted out' };
    const NM = {}; ((D && D.numbers) || []).forEach((n) => { NM[n.id] = n.name; });
    const safe = (v) => (typeof v === 'string' && /^[=+\-@\t\r]/.test(v) ? "'" + v : v);   // a name that starts with = + - @ must not run as an Excel formula
    const row = (r, mini) => (mini ? [safe(r.name), r.phone] : [safe(r.name), r.phone, LAB[r.grp] || r.grp, r.reason, r.src === 'query' ? 'Query' : 'Campaign', safe(r.camp), +r.replied ? 'yes' : '', +r.optout ? 'yes' : '', +r.out_n, +r.deliv_n, +r.sent_n, +r.fail_n, +r.in_n, +r.orders_n, QL[r.qual] || '', IL[r.intent] || '', String(r.batch || '').replace(/^import_/, ''), NM[r.num] || (+r.num ? '#' + r.num : ''), +r.score || 0, VL[r.val] || '', HL[r.health] || '', +r.named ? 'yes' : 'no', +r.asg ? 'yes' : 'no', r.first_out || '', r.last_out || '', r.last_in || '']);
    const dl = (blob, name) => { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 8000); };
    const save = async (rows, name, kind, mini) => {
      const head = mini ? HEAD.slice(0, 2) : HEAD;
      if (kind === 'x') { try { if (!window.ExcelJS) await new Promise((ok, no) => { const s = document.createElement('script'); s.src = 'assets/js/exceljs.min.js'; s.onload = ok; s.onerror = no; document.head.appendChild(s); });
        const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Customers'); ws.addRow(head).font = { bold: true }; rows.forEach((r) => ws.addRow(row(r, mini))); ws.views = [{ state: 'frozen', ySplit: 1 }]; ws.columns.forEach((c, i) => { c.width = [24, 14, 22][i] || 16; });
        dl(new Blob([await wb.xlsx.writeBuffer()], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), name + '.xlsx'); return; } catch (e) { /* no Excel library: a CSV is saved instead */ } }
      const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; dl(new Blob(['﻿' + [head].concat(rows.map((r) => row(r, mini))).map((r) => r.map(q).join(',')).join('\r\n')], { type: 'text/csv' }), name + '.csv');
    };
    { const fg = Q('#dlForget'); if (fg) fg.onclick = async () => { if (!confirm('Forget every earlier download? The next download can then include those customers again.')) return; try { await api.post('wa.cf_dl_reset', {}); if (D) { D.dl_no = (D.dl_no || 0) + (D.dl_yes || 0); D.dl_yes = 0; } fg.textContent = 'Forgotten ✓'; fg.disabled = true; } catch (e) { alert(e.message); } }; }
    let busy = false;
    Q('#dlGo').onclick = async function () {
      if (busy) return; busy = true; const btn = this, k = Q('#dlK').value, kind = Q('#dlT').value, mini = Q('#dlS').checked, cap = kind === 'x' ? 50000 : 200000; let per = +Q('#dlN').value; per = per > 0 ? Math.min(per, cap) : cap; let after = '', buf = [], files = 0, got = 0, batch = 0; const skip = Q('#dlSkip').checked, rem = Q('#dlRem').checked, take = Math.max(0, +Q('#dlTake').value || 0);
      const base = 'customers-' + k.replace(/^g_/, '') + (ex.b ? '-' + ex.b.replace(/^import_/, '') : '') + (ex.n ? '-number' + ex.n : '') + (src !== 'all' ? '-' + src : '') + '-' + ymd(new Date());
      try {
        const flush = async (force) => { while (buf.length && (force || buf.length >= per)) { const part = buf.splice(0, per); files++; btn.textContent = 'Saving file ' + files + '…'; await save(part, base + '-part' + files, kind, mini); if (rem) { try { const mk = await api.post('wa.cf_dl_mark', { phones: part.map((r) => r.phone), k, src, batch }); batch = mk.batch || batch; } catch (e) { /* the file is saved; only the memory failed */ } } await new Promise((r) => setTimeout(r, 500)); } };
        for (let i = 0; i < 400; i++) { btn.textContent = 'Preparing… ' + FN(got); const r = await api.get('wa.cf_list', { k, src, after, lim: take ? Math.min(5000, take - got) : 5000, b: ex.b || '', n: ex.n || '', skipdl: skip ? 1 : 0 }); buf.push(...r.rows); got += r.rows.length; after = r.last; await flush(false); if (!r.more || (take && got >= take)) break; }
        await flush(true); ov.remove();
        if (!got) alert(skip ? 'Nobody new in this group — everybody here was downloaded before.' : 'Nobody in this group yet.');
      } catch (e) { Q('#dlE').textContent = e.message; busy = false; btn.textContent = 'Download'; }
    };
  }
  // 👥 the small numbers card (wa.cust_summary): how many customers, how many got our messages, failed, replied, ordered, new. Used on Customers and on Campaigns.
  let SUMD = null, SUMT = 0;
  function summary(box) {
    if (!box) return; const fmt = (n) => (n == null ? '…' : Number(n).toLocaleString('en-IN')), pc = (a, b) => (a == null || !b ? '' : Math.round(100 * a / b) + '%');
    const paint = (d) => { if (!box.isConnected || !d) return; const T = (l, v, sub) => `<div style="background:rgba(128,128,128,.1);border-radius:9px;padding:4px 6px;min-width:0"><small style="display:block;font-size:9.5px;opacity:.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${l}</small><b style="font-size:13px">${v}</b>${sub ? `<i style="display:block;font-size:9.5px;opacity:.65;font-style:normal">${sub}</i>` : ''}</div>`;
      box.innerHTML = `<div class="wp-cd" style="padding:8px 9px;margin:0 0 8px"><div style="display:flex;justify-content:space-between;align-items:baseline;gap:6px;margin-bottom:5px"><b style="font-size:12.5px">👥 Customers at a glance</b><span style="font-size:10px;opacity:.6;display:flex;gap:6px;align-items:center">${String(d.at || '').slice(5, 16)}<button type="button" data-fun="1" style="border:1px solid rgba(128,128,128,.4);background:transparent;border-radius:7px;padding:2px 8px;font-size:11px;cursor:pointer;color:inherit">📊 Full funnel</button></span></div>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px">${T('Customers', fmt(d.total), fmt(d.reach) + ' can be messaged')}${T('Reached', fmt(d.got), pc(d.got, d.reach) + ' of reachable')}${T('Failed', fmt(d.failed), d.got != null && d.failed != null ? pc(d.failed, d.got + d.failed) + ' of tries' : '')}${T('Replied', fmt(d.replied), pc(d.replied, d.got) + ' of reached')}
        ${T('Ordered', fmt(d.ordered), pc(d.ordered, d.total) + ' of all')}${T('2+ orders', fmt(d.twice), pc(d.twice, d.ordered) + ' of buyers')}${T('Via campaign', fmt(d.camp_orders), 'ordered after a campaign')}${T('Opted out', fmt(d.optout), '')}
        ${T('New 7 days', fmt(d.new7), '')}${T('New 30 days', fmt(d.new30), '')}${T('Wrote in 30 d', fmt(d.wrote30), pc(d.wrote30, d.total))}${T('3+ orders', fmt(d.thrice), 'loyal')}</div></div>`; const fb = box.querySelector('[data-fun]'); if (fb) fb.onclick = () => funnelPopup(); };
    if (SUMD) paint(SUMD); else box.innerHTML = '<div class="wp-cd" style="padding:8px 9px;margin:0 0 8px"><span class="wp-hint">Counting customers…</span></div>';
    if (SUMD && Date.now() - SUMT < 300000) return;
    api.get('wa.cust_summary').then((d) => { SUMD = d; SUMT = Date.now(); paint(d); }).catch(() => { if (!SUMD && box.isConnected) box.innerHTML = ''; });
  }
  window.WaCust = { mount, panel, summary, funnel: funnelPopup, blank: BLANK };
})();
