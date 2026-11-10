/* ⏱ Journey — ONE timeline for every customer, from 2 minutes to day 60, and an "After an order" lane.
 * One engine runs it (the follow-up engine, wa_follow.php): a plan per situation (lane), read again at every new customer message.
 * The clock runs from OUR last message (bot or person). The timeline alone decides how often a customer is messaged.
 * Saves: a lane → fu.ap_path_save · the after-order steps → fu.wj_steps_save.   Needs api.js and wa_panels.js (WaPanels.util). */
(function () {
  'use strict';
  if (!window.WaPanels || !WaPanels.add) return;
  const { esc, toast, modal, errBox, loading } = WaPanels.util;
  const MAXMIN = 1440 * 60;   // the plan runs to day 60
  const TPL = { approved: ['✅', 'approved'], approved_old: ['✅⏳', 'old one approved, new waiting'], partial: ['✅', 'approved on some numbers'], pending: ['⏳', 'waiting for Meta'], rejected: ['❌', 'rejected by Meta'], none: ['📨', 'not sent to Meta yet'] };
  let CSS = false;
  function css() {
    if (CSS) return; CSS = true; const s = document.createElement('style');
    s.textContent = '.jt{display:grid;gap:10px;font-size:14px}.jt-card{background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:12px 14px}.jt-h{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-weight:800;font-size:15px}'
      + '.jt-sub{color:#64748b;font-size:12.5px;margin-top:2px}.jt-sp{flex:1}.jt-b{border:1px solid #d5d9e4;background:#fff;border-radius:10px;padding:8px 12px;min-height:38px;cursor:pointer;font-weight:700;font-size:13px}.jt-b.p{background:#5b3fd6;color:#fff;border-color:#5b3fd6}.jt-b.s{padding:5px 9px;min-height:32px;font-size:12px}.jt-b.r{color:#b91c1c}'
      + '.jt-seg{display:inline-flex;border:1px solid #d5d9e4;border-radius:10px;overflow:hidden}.jt-seg button{border:0;background:#fff;padding:7px 12px;font-weight:700;cursor:pointer}.jt-seg button.on{background:#5b3fd6;color:#fff}'
      + '.jt-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:7px 0;border-top:1px solid #f0f2f7}.jt-row.off{opacity:.5}.jt-in{border:1px solid #d5d9e4;border-radius:8px;padding:6px 8px;min-height:34px;font-size:14px;min-width:0}.jt-t{width:64px}.jt-msg{flex:1;min-width:150px}'
      + '.jt-tag{font-size:11px;font-weight:800;border-radius:99px;padding:2px 8px;background:#eef2ff;color:#3730a3}.jt-tag.w{background:#f3e8ff;color:#6b21a8}.jt-tag.a{background:#dcfce7;color:#166534}'
      + '.jt-strip{display:flex;gap:2px;margin:8px 0 2px;overflow-x:auto}.jt-strip i{flex:0 0 5px;height:14px;border-radius:2px;background:#eef0f5}.jt-strip i.a{background:#c4b5fd}.jt-strip i.w{background:#86efac}.jt-strip i.o{background:#fdba74}'
      + '.jt-pv{flex-basis:100%;font-size:13px}.jt-pv summary{cursor:pointer;color:#5b3fd6;font-weight:700;list-style:none}.jt-pv>div{margin-top:4px;padding:8px 10px;border-radius:10px;background:#f8fafc;border:1px solid #e6e8ef;white-space:pre-wrap;word-break:break-word}.jt-card.off{opacity:.6}.jt-seg button.offb.on{background:#b91c1c}.jt-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:8px;margin-top:8px}.jt-tile{text-align:left;border:1px solid #e6e8ef;border-left-width:4px;background:#fff;border-radius:12px;padding:7px 9px;min-height:84px;display:flex;flex-direction:column;gap:2px;cursor:pointer;min-width:0;font:inherit;color:inherit}.jt-tile:active{background:#f5f3ff}.jt-tile.a{border-left-color:#86efac}.jt-tile.o{border-left-color:#fdba74}.jt-tile.w{border-left-color:#c4b5fd}.jt-tile.x{opacity:.5}.jt-tile b{font-size:15px;line-height:1.1}.jt-tile .jt-tt{font-size:11px;font-weight:800;color:#3730a3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.jt-tile .jt-ts{font-size:12px;color:#475569;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}.jt-ban{border-radius:12px;padding:10px 12px;background:#f5f3ff;border:1px solid #ddd6fe}.jt-ban ul{margin:6px 0 0 18px;padding:0}@media (max-width:700px){.jt-card{padding:10px}.jt-t{width:56px}.jt-msg{flex-basis:100%}}';
    document.head.appendChild(s);
  }
  const fmtT = (min) => min < 60 ? [Math.round(min), 'min'] : min < 1440 ? [Math.round(min / 6) / 10, 'h'] : [Math.round(min / 144) / 10, 'd'];
  const toMin = (v, u) => Math.max(1, Math.round((+v || 0) * (u === 'd' ? 1440 : u === 'h' ? 60 : 1)));

  function mount(el) {
    css(); let AP = null, WJ = null, busy = false, RES = null; const dirty = { ap: new Set(), buyer: false }; let BUYER = [], PATHS = [];
    el.innerHTML = '<div class="jt"><div class="jt-card">' + loading + '</div></div>';
    const call = async (fn) => { try { const r = await fn(); if (r && r.message) toast(r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } };
    async function load(keep) {
      try { const [a, w] = await Promise.all([api.get('fu.ap_get'), api.get('fu.wj_get')]); AP = a; WJ = w; } catch (e) { el.innerHTML = '<div class="jt">' + errBox(e.message) + '</div>'; return; }
      if (!keep || !dirty.buyer) BUYER = JSON.parse(JSON.stringify(WJ.steps.buyer));
      if (!keep) { PATHS = JSON.parse(JSON.stringify(AP.paths || [])); dirty.ap.clear(); dirty.buyer = false; }
      draw(); if (!RES) { try { RES = await api.get('fu.jn_results', { days: 30 }); draw(); } catch (e) { RES = { lanes: [], buyer: [], total: {} }; } }
    }
    const stat = (o) => o && o.sent ? '<span class="jt-sub" style="white-space:nowrap" title="last 30 days">📊 sent ' + o.sent + ' · replied ' + o.replied + ' · ordered ' + o.ordered + (o.sales ? ' · ₹' + Math.round(o.sales).toLocaleString('en-IN') : '') + '</span>' : '';
    const laneStat = (sid, i) => RES && (RES.lanes || []).find((x) => x.sid === sid && x.step === i);
    const buyStat = (id) => RES && (RES.buyer || []).find((x) => x.step === id);
    const msgOpts = (sel) => '<optgroup label="💬 Messages">' + WJ.messages.filter((x) => x.icon === '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + (TPL[x.status] || TPL.none)[0] + ' ' + esc(x.label) + '</option>').join('') + '</optgroup><optgroup label="🎁 Offers">' + WJ.messages.filter((x) => x.icon !== '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + (TPL[x.status] || TPL.none)[0] + ' ' + esc(x.label) + '</option>').join('') + '</optgroup><option value="__new">➕ New message or offer…</option>';
    const modeOf = () => { const a = AP.mode, w = WJ.mode; if (a === 'off' && w === 'off') return 'off'; if (a === 'suggest' && w === 'suggest') return 'suggest'; if (['autotpl', 'auto24'].includes(a) && w === 'auto') return 'auto'; return 'mixed'; };
    const lanes = () => PATHS.filter((p) => p.kind !== 'stop').map((p) => ({ id: 'p:' + p.id, title: p.title, desc: p.desc, path: p }));
    const rowsOf = (L) => L.path.steps.map((s, i) => ({ src: 'ap', i, min: Math.round(s.after_h * 60), kind: s.kind, offer: s.offer, text: s.text, on: true, stat: stat(laneStat(L.path.id, i)) })).sort((a, b) => a.min - b.min);
    // ▫ a step is a small tile (time, what it is, the first words); tapping it opens a pop-up with everything to change
    const tileHtml = (r, lid) => { const [v, u] = fmtT(r.min); const key = lid + '|' + r.src + '|' + r.i;
      const mk = r.src === 'ap' ? (r.kind === 'offer' ? r.offer : '') : r.ref; const m = mk ? (WJ.messages || []).find((x) => x.k === mk) : null; const t = m ? (TPL[m.status] || TPL.none)[0] + ' ' : '';
      const title = r.src === 'ap' && r.kind !== 'offer' ? '💬 text' : (m ? t + m.label : 'message');
      const snip = r.src === 'ap' && r.kind !== 'offer' ? (r.text || 'Claude writes it') : (m ? (m.body || m.rule || '') : '') + (r.idea ? ' · ' + r.idea : '');
      return '<button type="button" class="jt-tile ' + (r.src === 'ap' ? (r.kind === 'offer' ? 'o' : 'a') : 'w') + (r.src === 'wj' && !r.on ? ' x' : '') + '" data-tile="' + esc(key) + '"><b>' + v + ' ' + u + '</b><span class="jt-tt">' + esc(title) + '</span><span class="jt-ts">' + esc(snip) + '</span></button>'; };
    const tiles = (rows, lid) => rows.length ? '<div class="jt-tiles">' + rows.map((r) => tileHtml(r, lid)).join('') + '</div>' : '<div class="jt-sub" style="padding:8px 0">No step yet.</div>';
    const rowOf = (key) => { const [lid, src, i] = key.split('|'); if (src === 'ap') { const L = lanes().find((l) => l.id === lid); return L ? rowsOf(L).find((r) => r.i === +i) : null; } return buyRows(lid.slice(2)).find((r) => r.i === +i) || null; };
    function openStep(key) {
      const o = sheet('<div class="jt" style="background:#fff;width:100%;max-width:640px;border-radius:18px 18px 0 0;padding:14px;max-height:92vh;overflow:auto"></div>'); const box = o.firstChild; const close = () => o.remove(); o.onclick = (e) => { if (e.target === o) close(); };
      const lid = key.split('|')[0];
      const renderPop = () => { const r = rowOf(key); if (!r) { close(); return; } const [v, u] = fmtT(r.min);
        box.innerHTML = '<div class="jt-h">Step · ' + v + ' ' + u + '<span class="jt-sp"></span><button class="jt-b s p" type="button" data-px>Done</button></div><div class="jt-sub">Change anything here, then press <b>Save changes</b> on the page.</div>' + rowHtml(r, lid, true);
        box.querySelector('[data-px]').onclick = close; bindRow(box.querySelector('.jt-row'), (why) => { draw(); if (why === 'rm') close(); else renderPop(); }); };
      renderPop();
    }
    // 👁 what a "message" step really sends: the owner's rule for the offer and the approved text (blanks like {name} are filled per customer)
    const pvHtml = (k, open) => { const m = (WJ.messages || []).find((x) => x.k === k); if (!m) return ''; const t = TPL[m.status] || TPL.none;
      return '<details class="jt-pv"' + (open ? ' open' : '') + '><summary>👁 See what this sends</summary><div><b>' + esc(m.label) + '</b>' + (m.rule ? '\nRule: ' + esc(m.rule) : '') + '\n\n' + (m.body ? esc(m.body) : 'Claude writes the words for each customer from this rule.') + '\n\nMeta: ' + esc(t[0] + ' ' + t[1]) + '</div><button class="jt-b s" type="button" data-edit="' + esc(k) + '" style="margin-top:6px">✏ Edit name / rule / text</button></details>'; };
    function rowHtml(r, lid, open) {
      const [v, u] = fmtT(r.min); const key = lid + '|' + r.src + '|' + r.i;
      let h = '<div class="jt-row" data-k="' + esc(key) + '"><span class="jt-tag ' + (r.src === 'ap' ? 'a' : 'w') + '">' + (r.src === 'ap' ? (r.kind === 'offer' ? 'message' : 'text') : 'after order') + '</span>'
        + '<input class="jt-in jt-t" type="number" min="1" step="any" data-f="v" value="' + v + '"><select class="jt-in" data-f="u"><option value="min"' + (u === 'min' ? ' selected' : '') + '>minutes</option><option value="h"' + (u === 'h' ? ' selected' : '') + '>hours</option><option value="d"' + (u === 'd' ? ' selected' : '') + '>days</option></select>';
      if (r.src === 'ap') h += '<select class="jt-in" data-f="kind"><option value="nudge"' + (r.kind !== 'offer' ? ' selected' : '') + '>💬 text</option><option value="offer"' + (r.kind === 'offer' ? ' selected' : '') + '>📨 message</option></select>';
      if (r.src === 'ap' && r.kind !== 'offer') h += '<input class="jt-in jt-msg" data-f="text" maxlength="700" placeholder="What Claude should say (idea)" value="' + esc(r.text) + '">';
      else h += '<select class="jt-in jt-msg" data-f="ref">' + msgOpts(r.src === 'ap' ? r.offer : r.ref) + '</select>' + (r.src === 'wj' ? '<input class="jt-in" data-f="idea" maxlength="300" placeholder="Extra idea (optional)" value="' + esc(r.idea) + '" style="flex:1;min-width:120px">' : '');
      if (r.src === 'wj') h += '<label style="white-space:nowrap"><input type="checkbox" data-f="on"' + (r.on ? ' checked' : '') + '> On</label>';
      const pk = r.src === 'ap' ? (r.kind === 'offer' ? r.offer : '') : r.ref;
      return h + '<button class="jt-b s r" type="button" data-rm title="Remove this step">✕</button>' + (pk ? pvHtml(pk, open) : '') + (r.stat ? '<div style="flex-basis:100%">' + r.stat + '</div>' : '') + '</div>';
    }
    function strip(rows, max) {
      max = max || 60; const per = max / 60, cells = []; for (let d = 1; d <= 60; d++) cells.push('');
      rows.forEach((r) => { const d = Math.min(60, Math.max(1, Math.ceil(r.min / 1440 / per))); cells[d - 1] = r.src === 'ap' ? (r.kind === 'offer' ? 'o' : 'a') : 'w'; });
      return '<div class="jt-strip">' + cells.map((c, i) => '<i class="' + c + '" title="day ' + Math.round((i + 1) * per) + '"></i>').join('') + '</div><div class="jt-sub" style="display:flex;justify-content:space-between"><span>day 1</span><span>' + Math.round(max / 4) + '</span><span>' + Math.round(max / 2) + '</span><span>' + Math.round(max * 3 / 4) + '</span><span>' + max + '</span></div>';
    }
    function laneHtml(L) {
      const rows = rowsOf(L);
      const on = L.path.on !== false;
      return '<div class="jt-card' + (on ? '' : ' off') + '" data-lane="' + esc(L.id) + '"><div class="jt-h">💡 ' + esc(L.title) + '<span class="jt-sp"></span><span class="jt-seg" title="Turn only this flow on or off (the Off / Preview / Auto switch at the top is for everything)"><button type="button" data-lon="1" class="' + (on ? 'on' : '') + '">On</button><button type="button" data-lon="0" class="offb ' + (on ? '' : 'on') + '">Off</button></span><button class="jt-b s" type="button" data-add>＋ Add a step</button></div><div class="jt-sub">' + esc(L.desc || '') + '</div>'
        + strip(rows) + tiles(rows, L.id) + '</div>';
    }
    function buyRows(topic) { return BUYER.map((s, i) => ({ src: 'wj', i, min: s.day * 1440, ref: s.ref, idea: s.idea, on: s.on, topic: s.topic || '', stat: stat(buyStat(s.id)) })).filter((r) => r.topic === topic).sort((a, b) => a.min - b.min); }
    function buyLane(lid, title, sub, topic, open) {
      const rows = buyRows(topic);
      return '<details class="jt-card" data-lane="' + esc(lid) + '"' + (open ? ' open' : '') + '><summary class="jt-h" style="cursor:pointer">' + title + '<span class="jt-tag">' + rows.length + ' steps</span><span class="jt-sp"></span><button class="jt-b s" type="button" data-add>＋ Add a step</button></summary><div class="jt-sub">' + sub + '</div>'
        + strip(rows, 240) + tiles(rows, lid) + '</details>';
    }
    function orderHtml() {
      const segs = (WJ.segments && WJ.segments.rows) || [];
      let h = '<div class="jt-card"><div class="jt-h">🛍 After an order — 12 customer types</div><div class="jt-sub">The moment someone orders they leave every lane above. Each customer is one of 12 types: <b>how many times they ordered</b> (1st, 2nd, 3rd, 4th or more) × <b>what they spend per order</b> (premium = top quarter, budget = bottom quarter' + (WJ.segments ? ': above ₹' + Math.round(WJ.segments.hi).toLocaleString('en-IN') + ' premium, below ₹' + Math.round(WJ.segments.lo).toLocaleString('en-IN') + ' budget' : '') + '). Days are counted from the <b>last order</b> and can go to day 365. '
        + 'A customer who orders again moves to the next type and the clock starts again. Claude set up a starting timeline for each type — change anything, or tell Claude in the 🧠 tab. Order updates, ratings, reviews and birthdays run by themselves from the <a href="#" data-sub="orderupd">order tabs</a>.</div></div>';
      h += buyLane('b:', '👥 Everyone after an order', 'These go to every customer after an order, on top of their own type’s steps.', '', false);
      segs.forEach((g) => { h += buyLane('b:' + g.k, '💎 ' + esc(g.title) + '<span class="jt-tag">' + g.n + ' customers</span>', 'Customers of this type, days from their last order.', g.k, false); });
      return h;
    }
    // 📨 Meta status of every message the steps use (only steps after 24 hours need Meta; a short text inside 24 hours does not)
    function metaCard() {
      const use = {}; const add = (k, where) => { if (!k) return; (use[k] = use[k] || []).push(where); };
      PATHS.filter((p) => p.kind !== 'stop').forEach((p) => p.steps.forEach((s) => { if (s.kind === 'offer') add(s.offer, p.title); }));
      BUYER.forEach((s) => { if (s.on) add(s.ref, 'After an order' + (s.topic ? ' · ' + s.topic : '')); });
      const by = { approved: 0, waiting: 0, rejected: 0, none: 0 }; const bad = [];
      Object.keys(use).forEach((k) => { const m = (WJ.messages || []).find((x) => x.k === k); if (!m) return; const st = m.status;
        if (st === 'approved' || st === 'approved_old' || st === 'partial') by.approved++; else if (st === 'pending') { by.waiting++; bad.push([m, use[k]]); } else if (st === 'rejected') { by.rejected++; bad.push([m, use[k]]); } else { by.none++; bad.push([m, use[k]]); } });
      const total = by.approved + by.waiting + by.rejected + by.none; if (!total) return '';
      const lab = { pending: '⏳ waiting for Meta', rejected: '❌ Meta said no', none: '📨 not sent to Meta yet' };
      let h = '<div class="jt-card"><div class="jt-h">📨 Meta status of the messages used in the steps<span class="jt-sp"></span>' + (by.none || by.waiting ? '<button class="jt-b s" type="button" data-metasend>Send waiting ones to Meta</button>' : '') + '</div>'
        + '<div class="jt-row" style="border:0"><span class="jt-tag a">✅ ' + by.approved + ' approved</span><span class="jt-tag">⏳ ' + by.waiting + ' waiting</span><span class="jt-tag" style="background:#fee2e2;color:#991b1b">❌ ' + by.rejected + ' rejected</span><span class="jt-tag">📨 ' + by.none + ' not sent yet</span></div>'
        + '<div class="jt-sub">A step whose message is not approved yet sends nothing; the rest of the plan goes on. (Steps inside 24 hours are short texts and need no approval.)</div>';
      if (bad.length) h += '<details style="margin-top:6px"' + (by.rejected ? ' open' : '') + '><summary style="cursor:pointer;font-weight:700">Not approved yet (' + bad.length + ')</summary>' + bad.map(([m, w]) => '<div class="jt-row"><b>' + esc(m.label) + '</b><span class="jt-tag">' + (lab[m.status] || m.status) + '</span><span class="jt-sub">used in: ' + esc([...new Set(w)].slice(0, 4).join(', ')) + (new Set(w).size > 4 ? '…' : '') + '</span>' + (m.reason ? '<div style="flex-basis:100%" class="jt-sub">Why: ' + esc(m.reason) + '</div>' : '') + (m.status === 'rejected' ? '<div style="flex-basis:100%"><button class="jt-b s" type="button" data-sub="msgs">✏️ Edit the words (a new version goes to Meta)</button></div>' : '') + '</div>').join('') + '</details>';
      return h + '</div>';
    }
    function head() {
      const m = modeOf(), s = WJ.stats || {}, D = WJ.daily || {};
      let h = '<div class="jt-card"><div class="jt-h">⏱ Journey<span class="jt-sp"></span><span class="jt-seg">' + [['off', 'Off'], ['suggest', 'Preview'], ['auto', 'Auto']].map(([k, l]) => '<button type="button" data-mode="' + k + '" class="' + (m === k ? 'on' : '') + '">' + l + '</button>').join('') + '</span></div>'
        + '<div class="jt-sub">One plan for every customer, from the first minutes to day 60. The clock runs from our last message (bot or person); the timeline alone decides how often a message goes. ' + (m === 'mixed' ? '<b>Two parts are in different modes</b> — press one button above to put both the same. ' : '')
        + (m === 'suggest' ? '<b>Preview</b>: nothing is sent — press “Who gets what now”. ' : m === 'auto' ? '<b>Auto</b>: messages go by themselves, never in quiet hours. ' : '<b>Off</b>: nothing is sent. ') + 'After day 60 only your campaigns go.</div>'
        + '<div class="jt-row" style="border:0">' + (RES && RES.total ? '<span class="jt-tag">30 days: sent ' + (RES.total.sent || 0) + '</span><span class="jt-tag">replied ' + (RES.total.replied || 0) + '</span><span class="jt-tag a">orders ' + (RES.total.ordered || 0) + ' · ₹' + Math.round(RES.total.sales || 0).toLocaleString('en-IN') + '</span>' : '<span class="jt-tag">sent today ' + (s.sent_today || 0) + '</span>') + '<span class="jt-sp"></span><button class="jt-b s" type="button" data-queue>👀 Who gets what now</button></div></div>';
      const W = WJ.window || { from: '10:00', to: '02:00' }, C = WJ.cfg || {};
      h += '<div class="jt-card"><div class="jt-row" style="border:0;padding:0"><b>⏰ Messages go between</b><input class="jt-in" type="time" id="jtFrom" value="' + esc(W.from) + '"><span>and</span><input class="jt-in" type="time" id="jtTo" value="' + esc(W.to) + '"><span class="jt-sub">(the next morning is quiet)</span><span class="jt-sp"></span><span class="jt-sub" title="A customer who ignored this many follow-ups in a row stops getting the plan and goes to the Cold group (a tag you can use for campaigns). 0 = never.">❄ cold after</span><input class="jt-in jt-t" type="number" min="0" max="40" id="jtCold" value="' + (W.cold_after == null ? 8 : W.cold_after) + '"><span class="jt-sub">ignored</span><span class="jt-sp"></span><span class="jt-sub">after-order: at most</span><input class="jt-in jt-t" type="number" min="10" id="jtCap" value="' + (C.day_cap || 300) + '"><span class="jt-sub">a day</span><button class="jt-b s" type="button" id="jtWin">Save</button></div></div>';
      h += metaCard();
      if (!WJ.unified) h += '<div class="jt-ban">⏳ <b>Setting up the one plan…</b> It is being prepared on the server. <button class="jt-b s" type="button" data-reload>Check again</button></div>';
      else h += '<div class="jt-ban">✅ <b>One engine.</b> Every customer who did not order follows ONE plan for their situation, from the first minutes to day 60; the situation is read again at every new message.</div>';
      if (!AP.installed) h += '<div class="jt-ban"><b>Start the paths</b> — the ready-made lanes are not set up yet. <button class="jt-b p s" type="button" data-install>Set them up</button></div>';
      if (D && D.at) h += '<div class="jt-ban">🌙 <b>Claude’s update</b> <span class="jt-sub">(' + esc(String(D.at).slice(0, 16)) + ')</span><div class="jt-sub">' + esc(D.understood || D.note || '') + '</div>' + ((D.lines || []).length ? '<ul>' + D.lines.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul><div class="jt-sub">These were added by itself — change or remove anything you do not like. ↩ Undo is in the 🧠 Claude tab.</div>' : '') + (D.kept ? '<div class="jt-sub">' + D.kept + ' bigger suggestion(s) are waiting in the 🧠 Claude tab because they change something that already exists.</div>' : '') + '</div>';
      const n = dirty.ap.size + (dirty.buyer ? 1 : 0);
      return h + '<div class="jt-card" style="position:sticky;top:0;z-index:5;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button class="jt-b p" type="button" id="jtSave"' + (n ? '' : ' disabled') + '>Save changes</button><span class="jt-sub">' + (n ? 'You have unsaved changes.' : 'Everything is saved.') + '</span><span class="jt-sp"></span>'
        + '<button class="jt-b s" type="button" data-sub="msgs">📚 Message library</button><button class="jt-b s" type="button" data-sub="orderupd">📦 Order messages</button></div>';
    }
    function draw() {
      if (!AP || !WJ) return; const sc = el.scrollTop;
      el.innerHTML = '<div class="jt">' + head() + (WJ.unified ? lanes().map(laneHtml).join('') : '') + orderHtml() + '</div>'; bind(); el.scrollTop = sc;
    }
    function find(key) {
      const [lid, src, i] = key.split('|'); const idx = +i;
      if (src === 'ap') { const p = PATHS.find((x) => 'p:' + x.id === lid); return p ? { src, p, idx, st: p.steps[idx] } : null; }
      return { src, idx, st: BUYER[idx], arr: BUYER };
    }
    const markDirty = (f) => { if (f.src === 'ap') dirty.ap.add(f.p.id); else dirty.buyer = true; const b = el.querySelector('#jtSave'); if (b) b.disabled = false; };
    function setTime(f, min) {
      if (f.src === 'ap') { if (min > MAXMIN) { toast('The plan runs to day 60.', true); return false; } f.st.after_h = Math.max(1 / 60, min / 60); }
      else f.st.day = Math.max(1, Math.min(365, Math.round(min / 1440)));
      return true;
    }
    const sheet = (html) => { const o = document.createElement('div'); o.style.cssText = 'position:fixed;inset:0;z-index:300;background:#0008;display:flex;align-items:flex-end;justify-content:center'; o.innerHTML = html; document.body.appendChild(o);
      if (window.innerWidth > 700) { o.style.alignItems = 'center'; o.style.padding = '16px'; if (o.firstElementChild) { o.firstElementChild.style.borderRadius = '18px'; o.firstElementChild.style.maxHeight = '88vh'; } }   // big screen: in the middle, not stuck to the bottom edge
      return o; };
    function newMsg(done) {
      const o = sheet('<div class="jt" style="background:#fff;width:100%;max-width:560px;border-radius:18px 18px 0 0;padding:16px;max-height:92vh;overflow:auto"><div class="jt-h">➕ New message or offer</div><div class="jt-sub">It is saved in the message library with this name, so you can pick it on any step.</div>'
        + '<input class="jt-in" id="nmN" maxlength="40" placeholder="Name, e.g. Diwali buy 2 get 1"><div style="margin:6px 0"><label><input type="radio" name="nmT" value="msg" checked> 💬 Message (no discount)</label> &nbsp; <label><input type="radio" name="nmT" value="offer"> 🎁 Offer</label></div>'
        + '<textarea class="jt-in" id="nmR" style="width:100%;min-height:60px" maxlength="300" placeholder="Rule — what Claude writes for each customer, e.g. Buy two and get a 10 ml free"></textarea>'
        + '<textarea class="jt-in" id="nmB" style="width:100%;min-height:64px;margin-top:6px" maxlength="900" placeholder="Message words (optional). Keep {{1}} name, {{2}} perfume, {{3}} line once each, in this order."></textarea>'
        + '<div class="jt-row" style="border:0"><button class="jt-b p" type="button" id="nmGo">Save &amp; use here</button><button class="jt-b" type="button" id="nmX">Cancel</button></div></div>');
      const q = (s) => o.querySelector(s); const close = (k) => { o.remove(); done(k || ''); };
      q('#nmX').onclick = () => close(''); o.onclick = (e) => { if (e.target === o) close(''); };
      q('#nmGo').onclick = async () => { const label = q('#nmN').value.trim(), rule = q('#nmR').value.trim(); if (!label || !rule) { toast('Give it a name and a rule.', true); return; } q('#nmGo').disabled = true;
        const r = await call(() => api.post('fu.wj_msg_new', { label, rule, body: q('#nmB').value.trim(), offer: q('input[name=nmT]:checked').value === 'offer' })); if (r && r.new_key) { WJ = r; close(r.new_key); } else q('#nmGo').disabled = false; };
    }
    // ✏ change a message: its name, its rule and its words — for every step that uses it, or as a NEW message that only this step uses
    function editMsg(k, f, after) {
      const m = (WJ.messages || []).find((x) => x.k === k); if (!m) return;
      const uses = PATHS.reduce((n, p) => n + p.steps.filter((x) => x.kind === 'offer' && x.offer === k).length, 0) + BUYER.filter((x) => x.ref === k).length;
      const o = sheet('<div class="jt" style="background:#fff;width:100%;max-width:560px;border-radius:18px 18px 0 0;padding:16px;max-height:92vh;overflow:auto"><div class="jt-h">✏ ' + esc(m.label) + '</div>'
        + '<div class="jt-sub">Used in ' + uses + ' step' + (uses === 1 ? '' : 's') + '. Change it for all of them, or save it under a new name for this step only (e.g. “Address given — no reply · Offer 1”).</div>'
        + '<label class="jt-sub">Name</label><input class="jt-in" id="emN" maxlength="40" style="width:100%" value="' + esc(m.label) + '">'
        + '<label class="jt-sub">Rule — what Claude writes for each customer</label><textarea class="jt-in" id="emR" style="width:100%;min-height:60px" maxlength="300">' + esc(m.rule || '') + '</textarea>'
        + '<label class="jt-sub">Message words — keep {{1}} name, {{2}} perfume, {{3}} the offer line once each, in this order (not at the very start or end)</label><textarea class="jt-in" id="emB" style="width:100%;min-height:90px" maxlength="900">' + esc(m.body || '') + '</textarea>'
        + '<div class="jt-sub">⚠ Changed words go to Meta again; until they are approved this step sends nothing (the rest of the plan goes on).</div>'
        + '<div class="jt-row" style="border:0"><button class="jt-b p" type="button" id="emAll">Save for all ' + uses + ' step' + (uses === 1 ? '' : 's') + '</button><button class="jt-b" type="button" id="emNew">Save as a new message for this step</button><button class="jt-b" type="button" id="emX">Cancel</button></div></div>');
      const q = (x) => o.querySelector(x); const close = () => o.remove(); q('#emX').onclick = close; o.onclick = (e) => { if (e.target === o) close(); };
      const val = () => ({ label: q('#emN').value.trim(), rule: q('#emR').value.trim(), body: q('#emB').value.trim() });
      q('#emAll').onclick = async () => { const v = val(); if (!v.label || !v.rule) { toast('Give it a name and a rule.', true); return; }
        if ((WJ.messages || []).some((x) => x.k !== k && x.label.toLowerCase() === v.label.toLowerCase())) { toast('Another message is already called “' + v.label + '” — use another name.', true); return; }
        q('#emAll').disabled = true; const offers = WJ.messages.map((x) => x.k === k ? { k, label: v.label, rule: v.rule, body: v.body } : { k: x.k, label: x.label, rule: x.rule });
        const r = await call(() => api.post('fu.ap_offers_save', { offers })); if (r) { close(); await load(true); if (after) after(); } else q('#emAll').disabled = false; };
      q('#emNew').onclick = async () => { const v = val(); if (!v.label || !v.rule) { toast('Give it a name and a rule.', true); return; }
        q('#emNew').disabled = true; const r = await call(() => api.post('fu.wj_msg_new', { label: v.label, rule: v.rule, body: v.body, offer: m.icon !== '💬' }));
        if (r && r.new_key) { WJ = r; if (f && f.st) { if (f.src === 'ap') { f.st.kind = 'offer'; f.st.offer = r.new_key; } else f.st.ref = r.new_key; markDirty(f); } close(); draw(); if (after) after(); } else q('#emNew').disabled = false; };
    }
    function askTime(done, noAll) {
      const o = sheet('<div class="jt" style="background:#fff;width:100%;max-width:420px;border-radius:18px 18px 0 0;padding:16px"><div class="jt-h">＋ New step — when?</div><div class="jt-sub">' + (noAll ? 'Days counted from the last order (up to 365).' : 'After our last message to this customer. Within 24 hours it is a short text Claude writes; later it is an approved message (you choose which).') + '</div><div class="jt-row" style="border:0"><input class="jt-in jt-t" id="atV" type="number" min="1" value="2"><select class="jt-in" id="atU"><option value="min">minutes</option><option value="h">hours</option><option value="d">days</option></select></div>'
        + (noAll ? '' : '<label class="jt-sub" style="display:block;margin:4px 0"><input type="checkbox" id="atAll"> Add it to <b>every lane</b></label>')
        + '<div class="jt-row" style="border:0"><button class="jt-b p" type="button" id="atGo">Add</button><button class="jt-b" type="button" id="atX">Cancel</button></div></div>');
      o.querySelector('#atX').onclick = () => { o.remove(); }; o.onclick = (e) => { if (e.target === o) o.remove(); };
      o.querySelector('#atGo').onclick = () => { const m = toMin(o.querySelector('#atV').value, o.querySelector('#atU').value); const all = !!(o.querySelector('#atAll') && o.querySelector('#atAll').checked); o.remove(); done(m, all); };
    }
    function addStep(lid) {
      askTime((min, all) => {
        const defRef = (WJ.messages.find((m) => m.k === 'w_miss') || WJ.messages[0] || {}).k || '';
        if (lid.indexOf('b:') === 0) { BUYER.push({ id: '', day: Math.max(1, Math.min(365, Math.round(min / 1440))), ref: defRef, idea: '', topic: lid.slice(2), on: true, label: '', icon: '💬', tpl: 'none' }); dirty.buyer = true; return draw(); }
        const targets = all ? PATHS.filter((p) => p.kind !== 'stop') : PATHS.filter((p) => 'p:' + p.id === lid);
        targets.forEach((p) => { p.steps.push(min < 1410 ? { after_h: min / 60, kind: 'nudge', offer: '', text: '', remark: '', ch: 'free' } : { after_h: min / 60, kind: 'offer', offer: defRef, text: '', remark: '', ch: 'tpl' }); dirty.ap.add(p.id); });
        draw();
      }, lid.indexOf('b:') === 0);
    }
    async function saveAll() {
      if (busy) return; busy = true; let ok = true;
      for (const sid of [...dirty.ap]) { const p = PATHS.find((x) => x.id === sid); if (!p) continue;
        const r = await call(() => api.post('fu.ap_path_save', { sid, steps: p.steps.map((s) => ({ after_h: s.after_h, kind: s.kind, offer: s.offer || '', text: s.text || '', remark: s.remark || '' })) })); if (!r) { ok = false; break; } }
      if (ok && dirty.buyer) { const r = await call(() => api.post('fu.wj_steps_save', { track: 'buyer', steps: BUYER.map((x) => ({ id: x.id, day: x.day, ref: x.ref, idea: x.idea, topic: x.topic || '', on: x.on })) })); if (!r) ok = false; }
      busy = false; if (ok) { dirty.ap.clear(); dirty.buyer = false; await load(false); }
    }
    function bind() {
      const $ = (s) => el.querySelector(s), $$ = (s) => [...el.querySelectorAll(s)];
      $$('[data-mode]').forEach((b) => b.onclick = async () => { if (busy) return; const m = b.dataset.mode; if (m === 'auto' && !confirm('Auto: messages will go to real customers from now on (earlier missed steps are not caught up). Continue?')) return; busy = true;
        const a = await call(() => api.post('fu.ap_mode', { mode: m === 'auto' ? 'autotpl' : m })); const w = a ? await call(() => api.post('fu.wj_mode', { mode: m })) : null; busy = false; if (a && w) await load(true); });
      const sv = $('#jtSave'); if (sv) sv.onclick = saveAll;
      const wb = $('#jtWin'); if (wb) wb.onclick = async () => { const a = await call(() => api.post('fu.jn_window', { from: $('#jtFrom').value, to: $('#jtTo').value, cold_after: +$('#jtCold').value })); if (!a) return; if ((+$('#jtCap').value || 0) !== ((WJ.cfg || {}).day_cap || 300)) await call(() => api.post('fu.wj_cfg', { day_cap: +$('#jtCap').value })); await load(true); };
      const rl = $('[data-reload]'); if (rl) rl.onclick = () => load(true);
      const ms = $('[data-metasend]'); if (ms) ms.onclick = async () => { ms.disabled = true; await call(() => api.post('fu.wj_tpl', {})); await load(true); };
      const ins = $('[data-install]'); if (ins) ins.onclick = async () => { ins.disabled = true; await call(() => api.post('fu.ap_install', {})); await load(false); };
      const qb = $('[data-queue]'); if (qb) qb.onclick = async () => { const m = modal('👀 Who gets what now'); m.body.innerHTML = loading;
        try { const q = await api.get('fu.wj_queue'); m.body.innerHTML = '<div class="jt-sub">After an order — who is due now' + (WJ.mode === 'auto' ? '.' : ' (nothing is sent in Preview).') + ' Total ' + q.total + ' · paused ' + q.blocked + ' · waiting for Meta ' + q.waiting_meta + '</div>'
          + (q.rows.length ? q.rows.map((r) => '<div class="jt-row"><b>' + esc(r.name) + ' …' + esc(r.tail) + '</b><span class="jt-tag">day ' + r.day + '</span><span class="jt-msg">' + esc(r.label) + '</span></div>').join('') : '<div class="jt-sub" style="padding:8px 0">Nobody is due right now.</div>') + '<div class="jt-sub" style="margin-top:8px">The lanes above run in the follow-up engine; its drafts and sends are in the Chats screen.</div>'; } catch (e) { m.body.innerHTML = errBox(e.message); } };
      $$('[data-sub]').forEach((a) => a.onclick = (e) => { e.preventDefault(); try { if (a.dataset.sub === 'msgs') location.hash = '#mymsgs'; else { localStorage.setItem('wa_fu_sub2', a.dataset.sub); location.hash = '#followups'; location.reload(); } } catch (x) { /* ignore */ } });
      $$('[data-lon]').forEach((b) => b.onclick = async (e) => { e.preventDefault(); e.stopPropagation(); if (busy) return; const lid = b.closest('[data-lane]').dataset.lane, p = PATHS.find((x) => 'p:' + x.id === lid), want = b.dataset.lon === '1'; if (!p || (p.on !== false) === want) return;
        busy = true; const r = await call(() => api.post('fu.sit_toggle', { sid: p.id, on: want })); busy = false; if (r) { p.on = want; toast(want ? 'This flow is ON.' : 'This flow is OFF — nothing from it is sent.'); draw(); } });
      $$('[data-add]').forEach((b) => b.onclick = (e) => { e.preventDefault(); e.stopPropagation(); addStep(b.closest('[data-lane]').dataset.lane); });
      $$('[data-tile]').forEach((b) => b.onclick = () => openStep(b.dataset.tile));
    }
    function bindRow(row, after) { const f = find(row.dataset.k); if (!f || !f.st) return;
        row.querySelectorAll('[data-edit]').forEach((b) => b.onclick = (e) => { e.preventDefault(); e.stopPropagation(); editMsg(b.dataset.edit, f, after); });
        row.querySelectorAll('[data-f]').forEach((inp) => {
          const upd = () => { const k = inp.dataset.f;
            if (k === 'v' || k === 'u') { const m = toMin(row.querySelector('[data-f=v]').value, row.querySelector('[data-f=u]').value); if (!setTime(f, m)) { after(); return; } }
            else if (k === 'text') f.st.text = inp.value;
            else if (k === 'kind') { f.st.kind = inp.value; if (inp.value === 'offer' && !f.st.offer) f.st.offer = (WJ.messages.find((m) => m.k === 'w_miss') || WJ.messages[0] || {}).k || ''; }
            else if (k === 'idea') f.st.idea = inp.value;
            else if (k === 'on') f.st.on = inp.checked;
            else if (k === 'ref') { if (inp.value === '__new') { newMsg((key) => { if (key) { if (f.src === 'ap') { f.st.kind = 'offer'; f.st.offer = key; } else f.st.ref = key; markDirty(f); } after(); }); return; } if (f.src === 'ap') { f.st.kind = 'offer'; f.st.offer = inp.value; } else f.st.ref = inp.value; }
            markDirty(f); };
          const sel = inp.tagName === 'SELECT' || inp.type === 'checkbox'; inp.oninput = sel ? null : upd; inp.onchange = () => { upd(); if (['u', 'ref', 'on', 'kind'].includes(inp.dataset.f)) setTimeout(after, 0); }; });
        row.querySelector('[data-rm]').onclick = () => {
          if (f.src === 'ap') { if (f.p.steps.length <= 1) { toast('Keep at least one step in this lane.', true); return; } f.p.steps.splice(f.idx, 1); dirty.ap.add(f.p.id); } else { f.arr.splice(f.idx, 1); dirty.buyer = true; }
          after('rm'); }; }
    load(false);
    return () => { };
  }
  WaPanels.add('jtimeline', (el) => mount(el));
  // ---- 📊 Results: every step of every lane and every after-order step — sent, replied, ordered, ₹ (last 7 / 30 / 90 days)
  function results(el) {
    css(); let days = 30; el.innerHTML = '<div class="jt"><div class="jt-card">' + loading + '</div></div>';
    const money = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); const pc = (a, b) => (b ? Math.round(a * 100 / b) + '%' : '—');
    const cell = (o) => o ? '<td>' + o.sent + '</td><td>' + o.replied + ' <small>' + pc(o.replied, o.sent) + '</small></td><td>' + o.ordered + '</td><td>' + (o.sales ? money(o.sales) : '—') + '</td>' : '<td colspan="4" class="jt-sub">no message yet</td>';
    const hint = (o) => o && o.sent >= 20 && o.replied === 0 ? ' <span class="jt-tag" style="background:#fee2e2;color:#991b1b" title="20 or more sent, nobody replied">nobody answers</span>' : (o && o.sent >= 20 && o.ordered >= 2 ? ' <span class="jt-tag a">works</span>' : '');
    async function load() {
      let R, A, W; try { [R, A, W] = await Promise.all([api.get('fu.jn_results', { days }), api.get('fu.ap_get'), api.get('fu.wj_get')]); } catch (e) { el.innerHTML = '<div class="jt">' + errBox(e.message) + '</div>'; return; }
      const lab = (k) => ((W.messages || []).find((m) => m.k === k) || {}).label || k; const T = R.total || {};
      const tile = (n, v, s) => '<div class="jt-card" style="flex:1 1 130px;padding:10px 12px"><div class="jt-sub">' + n + '</div><div style="font-size:22px;font-weight:800">' + v + '</div><div class="jt-sub">' + s + '</div></div>';
      let h = '<div class="jt"><div class="jt-card"><div class="jt-h">📊 Results<span class="jt-sp"></span><span class="jt-seg">' + [7, 30, 90].map((d) => '<button type="button" data-d="' + d + '" class="' + (days === d ? 'on' : '') + '">' + d + ' days</button>').join('') + '</span></div><div class="jt-sub">An order counts for the last follow-up sent in the 7 days before it. A reply = the customer wrote within 2 days.</div></div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">' + tile('Messages sent', T.sent || 0, 'before + after an order') + tile('Replied', T.replied || 0, pc(T.replied, T.sent)) + tile('Orders', T.ordered || 0, 'after a follow-up') + tile('Sales', money(T.sales), 'from those orders') + '</div>';
      h += '<div class="jt-card"><div class="jt-h">Before an order — by lane and step</div>';
      (A.paths || []).filter((p) => p.kind !== 'stop').forEach((p) => { const rows = p.steps.map((s, i) => { const o = (R.lanes || []).find((x) => x.sid === p.id && x.step === i); const t = s.after_h < 1 ? Math.round(s.after_h * 60) + ' min' : s.after_h < 24 ? Math.round(s.after_h * 10) / 10 + ' h' : 'day ' + Math.round(s.after_h / 2.4) / 10;
          return '<tr><td>' + t + '</td><td>' + esc(s.kind === 'offer' ? '📨 ' + lab(s.offer) : '💬 ' + (s.text || 'text').slice(0, 40)) + hint(o) + '</td>' + cell(o) + '</tr>'; }).join('');
        h += '<details style="margin-top:6px"><summary style="cursor:pointer;font-weight:700">' + esc(p.title) + '</summary><div style="overflow-x:auto"><table class="jt-tbl" style="width:100%;border-collapse:collapse;font-size:13px"><tr><th align="left">When</th><th align="left">Step</th><th>Sent</th><th>Replied</th><th>Orders</th><th>Sales</th></tr>' + rows + '</table></div></details>'; });
      h += '</div><div class="jt-card"><div class="jt-h">After an order — by customer type</div>';
      const byT = {}; (W.steps.buyer || []).forEach((s) => { (byT[s.topic || ''] = byT[s.topic || ''] || []).push(s); });
      const tn = (k) => k === '' ? 'Everyone' : ((W.segments.rows || []).find((g) => g.k === k) || {}).title || k;
      Object.keys(byT).sort().forEach((k) => { const rows = byT[k].map((s) => { const o = (R.buyer || []).find((x) => x.step === s.id); return '<tr><td>day ' + s.day + '</td><td>' + esc(lab(s.ref)) + hint(o) + '</td>' + cell(o) + '</tr>'; }).join('');
        h += '<details style="margin-top:6px"><summary style="cursor:pointer;font-weight:700">' + esc(tn(k)) + '</summary><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px"><tr><th align="left">When</th><th align="left">Step</th><th>Sent</th><th>Replied</th><th>Orders</th><th>Sales</th></tr>' + rows + '</table></div></details>'; });
      el.innerHTML = h + '</div></div>'; el.querySelectorAll('[data-d]').forEach((b) => b.onclick = () => { days = +b.dataset.d; el.innerHTML = '<div class="jt"><div class="jt-card">' + loading + '</div></div>'; load(); });
    }
    load(); return () => { };
  }
  WaPanels.add('jresults', (el) => results(el));

})();
