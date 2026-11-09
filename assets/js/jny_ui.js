/* ⏱ Journey — ONE timeline for every customer, from 2 minutes to day 60.
 * It shows the Autopilot paths (minutes … 10 days) and the 60-day Journey steps (days 11 … 60) as one list per lane, and an "After an order" lane.
 * The screen is only a window: a step you edit is saved in the store it belongs to (fu.ap_path_save / fu.wj_steps_save), so the old engines keep running exactly as before.
 * Needs api.js and wa_panels.js (WaPanels.util). */
(function () {
  'use strict';
  if (!window.WaPanels || !WaPanels.add) return;
  const { esc, toast, modal, errBox, loading } = WaPanels.util;
  const MAP = { price: 'price', thinking: 'ask', buying: 'pay' };   // a Journey topic that sits in the same lane as an Autopilot path (the path key it belongs to)
  const MAXAP = 240 * 60;   // minutes: the Autopilot owns the first 10 days
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
      + '.jt-ban{border-radius:12px;padding:10px 12px;background:#f5f3ff;border:1px solid #ddd6fe}.jt-ban ul{margin:6px 0 0 18px;padding:0}@media (max-width:700px){.jt-card{padding:10px}.jt-t{width:56px}.jt-msg{flex-basis:100%}}';
    document.head.appendChild(s);
  }
  const fmtT = (min) => min < 60 ? [Math.round(min), 'min'] : min < 1440 ? [Math.round(min / 6) / 10, 'h'] : [Math.round(min / 144) / 10, 'd'];
  const toMin = (v, u) => Math.max(1, Math.round((+v || 0) * (u === 'd' ? 1440 : u === 'h' ? 60 : 1)));
  const when = (min) => { const [v, u] = fmtT(min); return (u === 'min' ? v + ' min' : u === 'h' ? v + ' h' : 'day ' + v); };

  function mount(el) {
    css(); let AP = null, WJ = null, busy = false; const dirty = { ap: new Set(), lead: false, buyer: false }; let LEAD = [], BUYER = [], PATHS = [];
    el.innerHTML = '<div class="jt"><div class="jt-card">' + loading + '</div></div>';
    const call = async (fn) => { try { const r = await fn(); if (r && r.message) toast(r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } };
    async function load(keep) {
      try { const [a, w] = await Promise.all([api.get('fu.ap_get'), api.get('fu.wj_get')]); AP = a; WJ = w; } catch (e) { el.innerHTML = '<div class="jt">' + errBox(e.message) + '</div>'; return; }
      if (!keep || !dirty.lead) LEAD = JSON.parse(JSON.stringify(WJ.steps.lead)); if (!keep || !dirty.buyer) BUYER = JSON.parse(JSON.stringify(WJ.steps.buyer));
      if (!keep) { PATHS = JSON.parse(JSON.stringify(AP.paths || [])); dirty.ap.clear(); dirty.lead = dirty.buyer = false; }
      draw();
    }
    const msgOpts = (sel, k) => '<optgroup label="💬 Messages">' + WJ.messages.filter((x) => x.icon === '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + (TPL[x.status] || TPL.none)[0] + ' ' + esc(x.label) + '</option>').join('') + '</optgroup><optgroup label="🎁 Offers">' + WJ.messages.filter((x) => x.icon !== '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + (TPL[x.status] || TPL.none)[0] + ' ' + esc(x.label) + '</option>').join('') + '</optgroup><option value="__new">➕ New message or offer…</option>';
    const modeOf = () => { const a = AP.mode, w = WJ.mode; if (a === 'off' && w === 'off') return 'off'; if (a === 'suggest' && w === 'suggest') return 'suggest'; if (['autotpl', 'auto24'].includes(a) && w === 'auto') return 'auto'; return 'mixed'; };
    // ---- lanes: one per Autopilot path, one per Journey topic that has no path, "Everyone", "After an order"
    function lanes() {
      const L = []; const topics = (WJ.topics && WJ.topics.rows) || []; const used = new Set();
      for (const p of PATHS) {
        const tp = Object.keys(MAP).find((t) => MAP[t] === p.key && topics.some((r) => r.key === t)) || ''; if (tp) used.add(tp);
        L.push({ id: 'p:' + p.id, title: p.title, desc: p.desc, path: p, topic: tp, n: tp ? ((topics.find((r) => r.key === tp) || {}).n30 || 0) : null });
      }
      for (const r of topics) if (!used.has(r.key)) L.push({ id: 't:' + r.key, title: r.title, desc: 'Customers whose chats are about this (found by Claude)', path: null, topic: r.key, n: r.n30 || 0 });
      return L;
    }
    function rowsOf(L) {   // [{src, ref idx, min, ...}]
      const out = [];
      if (L.path) L.path.steps.forEach((s, i) => out.push({ src: 'ap', i, min: Math.round(s.after_h * 60), kind: s.kind, offer: s.offer, text: s.text, on: true, step: s }));
      LEAD.forEach((s, i) => { if ((s.topic || '') === (L.topic || '') && (L.topic || L.every)) out.push({ src: 'wj', i, min: s.day * 1440, ref: s.ref, idea: s.idea, on: s.on, step: s, tk: 'lead' }); });
      return out.sort((a, b) => a.min - b.min);
    }
    function rowHtml(r, lid) {
      const [v, u] = fmtT(r.min); const key = lid + '|' + r.src + '|' + (r.tk || '') + '|' + r.i;
      let h = '<div class="jt-row' + (r.on ? '' : ' off') + '" data-k="' + esc(key) + '"><span class="jt-tag ' + (r.src === 'ap' ? 'a' : 'w') + '">' + (r.src === 'ap' ? (r.min < 1440 ? 'text' : 'early') : 'later') + '</span>'
        + '<input class="jt-in jt-t" type="number" min="1" step="any" data-f="v" value="' + v + '"><select class="jt-in" data-f="u"><option value="min"' + (u === 'min' ? ' selected' : '') + '>minutes</option><option value="h"' + (u === 'h' ? ' selected' : '') + '>hours</option><option value="d"' + (u === 'd' ? ' selected' : '') + '>days</option></select>';
      if (r.src === 'ap' && r.kind !== 'offer') h += '<input class="jt-in jt-msg" data-f="text" maxlength="700" placeholder="What Claude should say (idea)" value="' + esc(r.text) + '">';
      else h += '<select class="jt-in jt-msg" data-f="ref">' + msgOpts(r.src === 'ap' ? r.offer : r.ref) + '</select>' + (r.src === 'wj' ? '<input class="jt-in" data-f="idea" maxlength="300" placeholder="Extra idea (optional)" value="' + esc(r.idea) + '" style="flex:1;min-width:120px">' : '');
      if (r.src === 'wj') h += '<label style="white-space:nowrap"><input type="checkbox" data-f="on"' + (r.on ? ' checked' : '') + '> On</label>';
      return h + '<button class="jt-b s r" type="button" data-rm title="Remove this step">✕</button></div>';
    }
    function strip(rows) {
      const cells = []; for (let d = 1; d <= 60; d++) cells.push('');
      rows.forEach((r) => { const d = Math.min(60, Math.max(1, Math.ceil(r.min / 1440))); cells[d - 1] = r.src === 'ap' ? (r.kind === 'offer' ? 'o' : 'a') : 'w'; });
      return '<div class="jt-strip">' + cells.map((c, i) => '<i class="' + c + '" title="day ' + (i + 1) + '"></i>').join('') + '</div><div class="jt-sub" style="display:flex;justify-content:space-between"><span>day 1</span><span>15</span><span>30</span><span>45</span><span>60</span></div>';
    }
    function laneHtml(L) {
      const rows = rowsOf(L);
      return '<div class="jt-card" data-lane="' + esc(L.id) + '"><div class="jt-h">' + (L.path ? '🔁' : '💡') + ' ' + esc(L.title) + (L.n != null ? '<span class="jt-tag">' + L.n + ' chats · 30 d</span>' : '') + '<span class="jt-sp"></span><button class="jt-b s" type="button" data-add>＋ Add a step</button></div><div class="jt-sub">' + esc(L.desc || '') + '</div>'
        + strip(rows) + (rows.map((r) => rowHtml(r, L.id)).join('') || '<div class="jt-sub" style="padding:8px 0">No step of its own yet — this lane gets the steps for everyone.</div>') + '</div>';
    }
    function everyoneHtml() {
      const L = { id: 'e', every: true, topic: '', path: null, title: 'Everyone — steps for every lane', desc: 'These go to every customer who did not order, on top of the lane’s own steps.' };
      L.every = true; const rows = []; LEAD.forEach((s, i) => { if (!(s.topic || '')) rows.push({ src: 'wj', i, min: s.day * 1440, ref: s.ref, idea: s.idea, on: s.on, tk: 'lead', step: s }); }); rows.sort((a, b) => a.min - b.min);
      return '<div class="jt-card" data-lane="e"><div class="jt-h">👥 ' + esc(L.title) + '<span class="jt-sp"></span><button class="jt-b s" type="button" data-add>＋ Add a step</button></div><div class="jt-sub">' + esc(L.desc) + '</div>' + strip(rows) + (rows.map((r) => rowHtml(r, 'e')).join('') || '<div class="jt-sub" style="padding:8px 0">No step yet.</div>') + '</div>';
    }
    function orderHtml() {
      const rows = []; BUYER.forEach((s, i) => rows.push({ src: 'wj', i, min: s.day * 1440, ref: s.ref, idea: s.idea, on: s.on, tk: 'buyer', step: s })); rows.sort((a, b) => a.min - b.min);
      return '<div class="jt-card" data-lane="b"><div class="jt-h">🛍 After an order<span class="jt-sp"></span><button class="jt-b s" type="button" data-add>＋ Add a step</button></div><div class="jt-sub">The moment someone orders they leave every lane above and start here (days counted from the order). No selling message goes while the order is on its way. '
        + 'Order updates, ratings, reviews and birthdays run by themselves from the <a href="#" data-sub="orderupd">sub-tabs</a> and never restart the clock above.</div>' + strip(rows) + (rows.map((r) => rowHtml(r, 'b')).join('') || '<div class="jt-sub" style="padding:8px 0">No step yet.</div>') + '</div>';
    }
    function head() {
      const m = modeOf(), s = WJ.stats || {}; const D = WJ.daily || {};
      let h = '<div class="jt-card"><div class="jt-h">⏱ Journey<span class="jt-sp"></span><span class="jt-seg">' + [['off', 'Off'], ['suggest', 'Preview'], ['auto', 'Auto']].map(([k, l]) => '<button type="button" data-mode="' + k + '" class="' + (m === k ? 'on' : '') + '">' + l + '</button>').join('') + '</span></div>'
        + '<div class="jt-sub">One plan for every customer, from the first minutes to day 60. The clock runs from our last message (bot or person). ' + (m === 'mixed' ? '<b>Autopilot and Journey are in different modes</b> — press one button above to put both the same. ' : '')
        + (m === 'suggest' ? '<b>Preview</b>: nothing is sent — press “Who gets what now”. ' : m === 'auto' ? '<b>Auto</b>: messages go by themselves, never in quiet hours. ' : '<b>Off</b>: nothing is sent. ') + 'After day 60 only your campaigns go.</div>'
        + '<div class="jt-row" style="border:0"><span class="jt-tag">' + (s.lead || 0) + ' in the journey</span><span class="jt-tag">sent today ' + (s.sent_today || 0) + '</span><span class="jt-tag">sent in 7 days ' + (s.sent_7 || 0) + '</span><span class="jt-sp"></span><button class="jt-b s" type="button" data-queue>👀 Who gets what now</button></div></div>';
      if (!AP.installed) h += '<div class="jt-ban"><b>Start the Autopilot paths</b> — the six ready-made paths for the first 10 days are not set up yet. <button class="jt-b p s" type="button" data-install>Set them up</button></div>';
      if (D && D.at) h += '<div class="jt-ban">🌙 <b>Claude’s update</b> <span class="jt-sub">(' + esc(String(D.at).slice(0, 16)) + ')</span><div class="jt-sub">' + esc(D.understood || D.note || '') + '</div>' + ((D.lines || []).length ? '<ul>' + D.lines.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul><div class="jt-sub">These were added by itself — change or remove anything you do not like. ↩ Undo is in the Advanced Journey tab.</div>' : '') + (D.kept ? '<div class="jt-sub">' + D.kept + ' bigger suggestion(s) are waiting in the Claude tab (Advanced) because they change something that already exists.</div>' : '') + '</div>';
      const dirtyN = dirty.ap.size + (dirty.lead ? 1 : 0) + (dirty.buyer ? 1 : 0);
      h += '<div class="jt-card" style="position:sticky;top:0;z-index:5;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button class="jt-b p" type="button" id="jtSave"' + (dirtyN ? '' : ' disabled') + '>Save changes</button><span class="jt-sub">' + (dirtyN ? 'You have unsaved changes.' : 'Everything is saved.') + '</span><span class="jt-sp"></span>'
        + '<button class="jt-b s" type="button" data-sub="msgs">📚 Message library</button><button class="jt-b s" type="button" data-sub="orderupd">📦 Order messages</button></div>';
      return h;
    }
    function draw() {
      if (!AP || !WJ) return; const sc = el.scrollTop;
      el.innerHTML = '<div class="jt">' + head() + everyoneHtml() + lanes().map(laneHtml).join('') + orderHtml() + '</div>'; bind(); el.scrollTop = sc;
    }
    // ---- find the data of a row from its key
    function find(key) {
      const [lid, src, tk, i] = key.split('|'); const idx = +i;
      if (src === 'ap') { const p = PATHS.find((x) => 'p:' + x.id === lid); return p ? { src, p, idx, st: p.steps[idx] } : null; }
      const arr = tk === 'buyer' ? BUYER : LEAD; return { src, tk, idx, st: arr[idx], arr };
    }
    const markDirty = (f) => { if (f.src === 'ap') dirty.ap.add(f.p.id); else dirty[f.tk] = true; const b = el.querySelector('#jtSave'); if (b) b.disabled = false; };
    function setTime(f, min) {
      if (f.src === 'ap') { if (min > MAXAP) { toast('The first 10 days belong to the early steps. For a later day add a new step instead.', true); return false; } f.st.after_h = Math.max(1 / 60, min / 60); }
      else { f.st.day = Math.max(1, Math.min(60, Math.round(min / 1440))); }
      return true;
    }
    function newMsg(done) {
      const o = document.createElement('div'); o.style.cssText = 'position:fixed;inset:0;z-index:300;background:#0008;display:flex;align-items:flex-end;justify-content:center';
      o.innerHTML = '<div class="jt" style="background:#fff;width:100%;max-width:560px;border-radius:18px 18px 0 0;padding:16px;max-height:92vh;overflow:auto"><div class="jt-h">➕ New message or offer</div><div class="jt-sub">It is saved in the message library with this name, so you can pick it on any step.</div>'
        + '<input class="jt-in" id="nmN" maxlength="40" placeholder="Name, e.g. Diwali buy 2 get 1"><div style="margin:6px 0"><label><input type="radio" name="nmT" value="msg" checked> 💬 Message (no discount)</label> &nbsp; <label><input type="radio" name="nmT" value="offer"> 🎁 Offer</label></div>'
        + '<textarea class="jt-in" id="nmR" style="width:100%;min-height:60px" maxlength="300" placeholder="Rule — what Claude writes for each customer, e.g. Buy two and get a 10 ml free"></textarea>'
        + '<textarea class="jt-in" id="nmB" style="width:100%;min-height:64px;margin-top:6px" maxlength="900" placeholder="Message words (optional). Keep {{1}} name, {{2}} perfume, {{3}} line once each, in this order."></textarea>'
        + '<div class="jt-row" style="border:0"><button class="jt-b p" type="button" id="nmGo">Save &amp; use here</button><button class="jt-b" type="button" id="nmX">Cancel</button></div></div>';
      document.body.appendChild(o); const q = (s) => o.querySelector(s); const close = (k) => { o.remove(); done(k || ''); };
      q('#nmX').onclick = () => close(''); o.onclick = (e) => { if (e.target === o) close(''); };
      q('#nmGo').onclick = async () => { const label = q('#nmN').value.trim(), rule = q('#nmR').value.trim(); if (!label || !rule) { toast('Give it a name and a rule.', true); return; } q('#nmGo').disabled = true;
        const r = await call(() => api.post('fu.wj_msg_new', { label, rule, body: q('#nmB').value.trim(), offer: q('input[name=nmT]:checked').value === 'offer' })); if (r && r.new_key) { WJ = r; close(r.new_key); } else q('#nmGo').disabled = false; };
    }
    function askTime(done) {
      const o = document.createElement('div'); o.style.cssText = 'position:fixed;inset:0;z-index:300;background:#0008;display:flex;align-items:flex-end;justify-content:center';
      o.innerHTML = '<div class="jt" style="background:#fff;width:100%;max-width:420px;border-radius:18px 18px 0 0;padding:16px"><div class="jt-h">＋ New step — when?</div><div class="jt-sub">After our last message to this customer.</div><div class="jt-row" style="border:0"><input class="jt-in jt-t" id="atV" type="number" min="1" value="2"><select class="jt-in" id="atU"><option value="min">minutes</option><option value="h">hours</option><option value="d">days</option></select></div>'
        + '<div class="jt-row" style="border:0"><button class="jt-b p" type="button" id="atGo">Add</button><button class="jt-b" type="button" id="atX">Cancel</button></div></div>'; document.body.appendChild(o);
      o.querySelector('#atX').onclick = () => { o.remove(); }; o.onclick = (e) => { if (e.target === o) o.remove(); }; o.querySelector('#atGo').onclick = () => { const m = toMin(o.querySelector('#atV').value, o.querySelector('#atU').value); o.remove(); done(m); };
    }
    function addStep(lid) {
      askTime((min) => {
        const defRef = (WJ.messages.find((m) => m.k === 'w_miss') || WJ.messages[0] || {}).k || '';
        if (lid === 'b') { BUYER.push({ id: '', day: Math.max(1, Math.min(60, Math.round(min / 1440))), ref: defRef, idea: '', topic: '', on: true, label: '', icon: '💬', tpl: 'none' }); dirty.buyer = true; return draw(); }
        const lane = lid === 'e' ? null : lanes().find((x) => x.id === lid);
        if (lane && lane.path && min <= MAXAP) { lane.path.steps.push({ after_h: min / 60, kind: min < 1440 ? 'nudge' : 'nudge', offer: '', text: '', remark: '', ch: min >= 1410 ? 'tpl' : 'free' }); dirty.ap.add(lane.path.id); return draw(); }
        if (lane && lane.path && !lane.topic && min > MAXAP) { toast('This lane has no later steps of its own yet — add it under “Everyone”.', true); return; }
        if (min < 1440) { toast('A step under a minute-or-hour time needs one of the six early lanes.', true); return; }
        LEAD.push({ id: '', day: Math.max(1, Math.min(60, Math.round(min / 1440))), ref: defRef, idea: '', topic: lane ? lane.topic : '', on: true, label: '', icon: '💬', tpl: 'none' }); dirty.lead = true; draw();
      });
    }
    async function saveAll() {
      if (busy) return; busy = true; let ok = true;
      for (const sid of [...dirty.ap]) { const p = PATHS.find((x) => x.id === sid); if (!p) continue;
        const r = await call(() => api.post('fu.ap_path_save', { sid, steps: p.steps.map((s) => ({ after_h: s.after_h, kind: s.kind, offer: s.offer || '', text: s.text || '', remark: s.remark || '' })) })); if (!r) { ok = false; break; } }
      for (const tk of ['lead', 'buyer']) { if (!ok || !dirty[tk]) continue; const arr = tk === 'lead' ? LEAD : BUYER;
        const r = await call(() => api.post('fu.wj_steps_save', { track: tk, steps: arr.map((x) => ({ id: x.id, day: x.day, ref: x.ref, idea: x.idea, topic: x.topic || '', on: x.on })) })); if (!r) ok = false; }
      busy = false; if (ok) { dirty.ap.clear(); dirty.lead = dirty.buyer = false; await load(false); }
    }
    function bind() {
      const $ = (s) => el.querySelector(s), $$ = (s) => [...el.querySelectorAll(s)];
      $$('[data-mode]').forEach((b) => b.onclick = async () => { if (busy) return; const m = b.dataset.mode; if (m === 'auto' && !confirm('Auto: messages will go to real customers from now on (earlier missed days are not caught up). Continue?')) return; busy = true;
        const a = await call(() => api.post('fu.ap_mode', { mode: m === 'auto' ? 'autotpl' : m })); const w = a ? await call(() => api.post('fu.wj_mode', { mode: m })) : null; busy = false; if (a && w) await load(true); });
      const sv = $('#jtSave'); if (sv) sv.onclick = saveAll;
      const ins = $('[data-install]'); if (ins) ins.onclick = async () => { ins.disabled = true; await call(() => api.post('fu.ap_install', {})); await load(false); };
      const qb = $('[data-queue]'); if (qb) qb.onclick = async () => { const m = modal('👀 Who gets what now'); m.body.innerHTML = loading;
        try { const q = await api.get('fu.wj_queue'); m.body.innerHTML = '<div class="jt-sub">' + (WJ.mode === 'auto' ? 'These customers are due now.' : 'Nothing is sent in Preview. This is who WOULD get a later-day message now.') + ' Total ' + q.total + ' · paused ' + q.blocked + ' · waiting for Meta ' + q.waiting_meta + '</div>'
          + (q.rows.length ? q.rows.map((r) => '<div class="jt-row"><b>' + esc(r.name) + ' …' + esc(r.tail) + '</b><span class="jt-tag">day ' + r.day + '</span><span class="jt-msg">' + esc(r.label) + '</span><span class="jt-sub">' + esc(r.topic) + '</span></div>').join('') : '<div class="jt-sub" style="padding:8px 0">Nobody is due right now.</div>'); } catch (e) { m.body.innerHTML = errBox(e.message); } };
      $$('[data-sub]').forEach((a) => a.onclick = (e) => { e.preventDefault(); try { if (a.dataset.sub === 'msgs') location.hash = '#mymsgs'; else { localStorage.setItem('wa_fu_sub2', a.dataset.sub); location.hash = '#followups'; location.reload(); } } catch (x) { /* ignore */ } });
      $$('[data-add]').forEach((b) => b.onclick = () => addStep(b.closest('[data-lane]').dataset.lane));
      $$('.jt-row[data-k]').forEach((row) => { const f = find(row.dataset.k); if (!f || !f.st) return;
        row.querySelectorAll('[data-f]').forEach((inp) => {
          const upd = () => { const k = inp.dataset.f;
            if (k === 'v' || k === 'u') { const m = toMin(row.querySelector('[data-f=v]').value, row.querySelector('[data-f=u]').value); if (!setTime(f, m)) { draw(); return; } }
            else if (k === 'text') f.st.text = inp.value;
            else if (k === 'idea') f.st.idea = inp.value;
            else if (k === 'on') f.st.on = inp.checked;
            else if (k === 'ref') { if (inp.value === '__new') { newMsg((key) => { if (key) { if (f.src === 'ap') { f.st.kind = 'offer'; f.st.offer = key; } else f.st.ref = key; markDirty(f); } draw(); }); return; } if (f.src === 'ap') { f.st.kind = 'offer'; f.st.offer = inp.value; } else f.st.ref = inp.value; }
            markDirty(f); };
          inp.oninput = k0(inp) ? null : upd; inp.onchange = () => { upd(); if (['u', 'ref', 'on'].includes(inp.dataset.f)) setTimeout(draw, 0); }; });
        row.querySelector('[data-rm]').onclick = () => {
          if (f.src === 'ap') { if (f.p.steps.length <= 1) { toast('Keep at least one step in this lane.', true); return; } f.p.steps.splice(f.idx, 1); dirty.ap.add(f.p.id); } else { f.arr.splice(f.idx, 1); dirty[f.tk] = true; }
          draw(); }; });
    }
    function k0(inp) { return inp.tagName === 'SELECT' || inp.type === 'checkbox'; }
    load(false);
    return () => { };
  }
  WaPanels.add('jtimeline', (el) => mount(el));
})();
