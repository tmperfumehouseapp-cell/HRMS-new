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
      + '.jt-ban{border-radius:12px;padding:10px 12px;background:#f5f3ff;border:1px solid #ddd6fe}.jt-ban ul{margin:6px 0 0 18px;padding:0}@media (max-width:700px){.jt-card{padding:10px}.jt-t{width:56px}.jt-msg{flex-basis:100%}}';
    document.head.appendChild(s);
  }
  const fmtT = (min) => min < 60 ? [Math.round(min), 'min'] : min < 1440 ? [Math.round(min / 6) / 10, 'h'] : [Math.round(min / 144) / 10, 'd'];
  const toMin = (v, u) => Math.max(1, Math.round((+v || 0) * (u === 'd' ? 1440 : u === 'h' ? 60 : 1)));

  function mount(el) {
    css(); let AP = null, WJ = null, busy = false; const dirty = { ap: new Set(), buyer: false }; let BUYER = [], PATHS = [];
    el.innerHTML = '<div class="jt"><div class="jt-card">' + loading + '</div></div>';
    const call = async (fn) => { try { const r = await fn(); if (r && r.message) toast(r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } };
    async function load(keep) {
      try { const [a, w] = await Promise.all([api.get('fu.ap_get'), api.get('fu.wj_get')]); AP = a; WJ = w; } catch (e) { el.innerHTML = '<div class="jt">' + errBox(e.message) + '</div>'; return; }
      if (!keep || !dirty.buyer) BUYER = JSON.parse(JSON.stringify(WJ.steps.buyer));
      if (!keep) { PATHS = JSON.parse(JSON.stringify(AP.paths || [])); dirty.ap.clear(); dirty.buyer = false; }
      draw();
    }
    const msgOpts = (sel) => '<optgroup label="💬 Messages">' + WJ.messages.filter((x) => x.icon === '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + (TPL[x.status] || TPL.none)[0] + ' ' + esc(x.label) + '</option>').join('') + '</optgroup><optgroup label="🎁 Offers">' + WJ.messages.filter((x) => x.icon !== '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + (TPL[x.status] || TPL.none)[0] + ' ' + esc(x.label) + '</option>').join('') + '</optgroup><option value="__new">➕ New message or offer…</option>';
    const modeOf = () => { const a = AP.mode, w = WJ.mode; if (a === 'off' && w === 'off') return 'off'; if (a === 'suggest' && w === 'suggest') return 'suggest'; if (['autotpl', 'auto24'].includes(a) && w === 'auto') return 'auto'; return 'mixed'; };
    const lanes = () => PATHS.filter((p) => p.kind !== 'stop').map((p) => ({ id: 'p:' + p.id, title: p.title, desc: p.desc, path: p }));
    const rowsOf = (L) => L.path.steps.map((s, i) => ({ src: 'ap', i, min: Math.round(s.after_h * 60), kind: s.kind, offer: s.offer, text: s.text, on: true })).sort((a, b) => a.min - b.min);
    function rowHtml(r, lid) {
      const [v, u] = fmtT(r.min); const key = lid + '|' + r.src + '|' + r.i;
      let h = '<div class="jt-row" data-k="' + esc(key) + '"><span class="jt-tag ' + (r.src === 'ap' ? 'a' : 'w') + '">' + (r.src === 'ap' ? (r.kind === 'offer' ? 'message' : 'text') : 'after order') + '</span>'
        + '<input class="jt-in jt-t" type="number" min="1" step="any" data-f="v" value="' + v + '"><select class="jt-in" data-f="u"><option value="min"' + (u === 'min' ? ' selected' : '') + '>minutes</option><option value="h"' + (u === 'h' ? ' selected' : '') + '>hours</option><option value="d"' + (u === 'd' ? ' selected' : '') + '>days</option></select>';
      if (r.src === 'ap') h += '<select class="jt-in" data-f="kind"><option value="nudge"' + (r.kind !== 'offer' ? ' selected' : '') + '>💬 text</option><option value="offer"' + (r.kind === 'offer' ? ' selected' : '') + '>📨 message</option></select>';
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
      return '<div class="jt-card" data-lane="' + esc(L.id) + '"><div class="jt-h">💡 ' + esc(L.title) + '<span class="jt-sp"></span><button class="jt-b s" type="button" data-add>＋ Add a step</button></div><div class="jt-sub">' + esc(L.desc || '') + '</div>'
        + strip(rows) + (rows.map((r) => rowHtml(r, L.id)).join('') || '<div class="jt-sub" style="padding:8px 0">No step yet.</div>') + '</div>';
    }
    function orderHtml() {
      const rows = BUYER.map((s, i) => ({ src: 'wj', i, min: s.day * 1440, ref: s.ref, idea: s.idea, on: s.on })).sort((a, b) => a.min - b.min);
      return '<div class="jt-card" data-lane="b"><div class="jt-h">🛍 After an order<span class="jt-sp"></span><button class="jt-b s" type="button" data-add>＋ Add a step</button></div><div class="jt-sub">The moment someone orders they leave every lane above and start here (days counted from the order). You decide what goes and when. '
        + 'Order updates, ratings, reviews and birthdays run by themselves from the <a href="#" data-sub="orderupd">order tabs</a>.</div>' + strip(rows) + (rows.map((r) => rowHtml(r, 'b')).join('') || '<div class="jt-sub" style="padding:8px 0">No step yet.</div>') + '</div>';
    }
    function head() {
      const m = modeOf(), s = WJ.stats || {}, D = WJ.daily || {};
      let h = '<div class="jt-card"><div class="jt-h">⏱ Journey<span class="jt-sp"></span><span class="jt-seg">' + [['off', 'Off'], ['suggest', 'Preview'], ['auto', 'Auto']].map(([k, l]) => '<button type="button" data-mode="' + k + '" class="' + (m === k ? 'on' : '') + '">' + l + '</button>').join('') + '</span></div>'
        + '<div class="jt-sub">One plan for every customer, from the first minutes to day 60. The clock runs from our last message (bot or person); the timeline alone decides how often a message goes. ' + (m === 'mixed' ? '<b>Two parts are in different modes</b> — press one button above to put both the same. ' : '')
        + (m === 'suggest' ? '<b>Preview</b>: nothing is sent — press “Who gets what now”. ' : m === 'auto' ? '<b>Auto</b>: messages go by themselves, never in quiet hours. ' : '<b>Off</b>: nothing is sent. ') + 'After day 60 only your campaigns go.</div>'
        + '<div class="jt-row" style="border:0"><span class="jt-tag">sent today ' + (s.sent_today || 0) + '</span><span class="jt-tag">sent in 7 days ' + (s.sent_7 || 0) + '</span><span class="jt-sp"></span><button class="jt-b s" type="button" data-queue>👀 Who gets what now</button></div></div>';
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
      else f.st.day = Math.max(1, Math.min(60, Math.round(min / 1440)));
      return true;
    }
    const sheet = (html) => { const o = document.createElement('div'); o.style.cssText = 'position:fixed;inset:0;z-index:300;background:#0008;display:flex;align-items:flex-end;justify-content:center'; o.innerHTML = html; document.body.appendChild(o); return o; };
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
    function askTime(done, noAll) {
      const o = sheet('<div class="jt" style="background:#fff;width:100%;max-width:420px;border-radius:18px 18px 0 0;padding:16px"><div class="jt-h">＋ New step — when?</div><div class="jt-sub">' + (noAll ? 'Days counted from the order.' : 'After our last message to this customer. Within 24 hours it is a short text Claude writes; later it is an approved message (you choose which).') + '</div><div class="jt-row" style="border:0"><input class="jt-in jt-t" id="atV" type="number" min="1" value="2"><select class="jt-in" id="atU"><option value="min">minutes</option><option value="h">hours</option><option value="d">days</option></select></div>'
        + (noAll ? '' : '<label class="jt-sub" style="display:block;margin:4px 0"><input type="checkbox" id="atAll"> Add it to <b>every lane</b></label>')
        + '<div class="jt-row" style="border:0"><button class="jt-b p" type="button" id="atGo">Add</button><button class="jt-b" type="button" id="atX">Cancel</button></div></div>');
      o.querySelector('#atX').onclick = () => { o.remove(); }; o.onclick = (e) => { if (e.target === o) o.remove(); };
      o.querySelector('#atGo').onclick = () => { const m = toMin(o.querySelector('#atV').value, o.querySelector('#atU').value); const all = !!(o.querySelector('#atAll') && o.querySelector('#atAll').checked); o.remove(); done(m, all); };
    }
    function addStep(lid) {
      askTime((min, all) => {
        const defRef = (WJ.messages.find((m) => m.k === 'w_miss') || WJ.messages[0] || {}).k || '';
        if (lid === 'b') { BUYER.push({ id: '', day: Math.max(1, Math.min(60, Math.round(min / 1440))), ref: defRef, idea: '', topic: '', on: true, label: '', icon: '💬', tpl: 'none' }); dirty.buyer = true; return draw(); }
        const targets = all ? PATHS.filter((p) => p.kind !== 'stop') : PATHS.filter((p) => 'p:' + p.id === lid);
        targets.forEach((p) => { p.steps.push(min < 1410 ? { after_h: min / 60, kind: 'nudge', offer: '', text: '', remark: '', ch: 'free' } : { after_h: min / 60, kind: 'offer', offer: defRef, text: '', remark: '', ch: 'tpl' }); dirty.ap.add(p.id); });
        draw();
      }, lid === 'b');
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
      const rl = $('[data-reload]'); if (rl) rl.onclick = () => load(true);
      const ins = $('[data-install]'); if (ins) ins.onclick = async () => { ins.disabled = true; await call(() => api.post('fu.ap_install', {})); await load(false); };
      const qb = $('[data-queue]'); if (qb) qb.onclick = async () => { const m = modal('👀 Who gets what now'); m.body.innerHTML = loading;
        try { const q = await api.get('fu.wj_queue'); m.body.innerHTML = '<div class="jt-sub">After an order — who is due now' + (WJ.mode === 'auto' ? '.' : ' (nothing is sent in Preview).') + ' Total ' + q.total + ' · paused ' + q.blocked + ' · waiting for Meta ' + q.waiting_meta + '</div>'
          + (q.rows.length ? q.rows.map((r) => '<div class="jt-row"><b>' + esc(r.name) + ' …' + esc(r.tail) + '</b><span class="jt-tag">day ' + r.day + '</span><span class="jt-msg">' + esc(r.label) + '</span></div>').join('') : '<div class="jt-sub" style="padding:8px 0">Nobody is due right now.</div>') + '<div class="jt-sub" style="margin-top:8px">The lanes above run in the follow-up engine; its drafts and sends are in the Chats screen.</div>'; } catch (e) { m.body.innerHTML = errBox(e.message); } };
      $$('[data-sub]').forEach((a) => a.onclick = (e) => { e.preventDefault(); try { if (a.dataset.sub === 'msgs') location.hash = '#mymsgs'; else { localStorage.setItem('wa_fu_sub2', a.dataset.sub); location.hash = '#followups'; location.reload(); } } catch (x) { /* ignore */ } });
      $$('[data-add]').forEach((b) => b.onclick = () => addStep(b.closest('[data-lane]').dataset.lane));
      $$('.jt-row[data-k]').forEach((row) => { const f = find(row.dataset.k); if (!f || !f.st) return;
        row.querySelectorAll('[data-f]').forEach((inp) => {
          const upd = () => { const k = inp.dataset.f;
            if (k === 'v' || k === 'u') { const m = toMin(row.querySelector('[data-f=v]').value, row.querySelector('[data-f=u]').value); if (!setTime(f, m)) { draw(); return; } }
            else if (k === 'text') f.st.text = inp.value;
            else if (k === 'kind') { f.st.kind = inp.value; if (inp.value === 'offer' && !f.st.offer) f.st.offer = (WJ.messages.find((m) => m.k === 'w_miss') || WJ.messages[0] || {}).k || ''; }
            else if (k === 'idea') f.st.idea = inp.value;
            else if (k === 'on') f.st.on = inp.checked;
            else if (k === 'ref') { if (inp.value === '__new') { newMsg((key) => { if (key) { if (f.src === 'ap') { f.st.kind = 'offer'; f.st.offer = key; } else f.st.ref = key; markDirty(f); } draw(); }); return; } if (f.src === 'ap') { f.st.kind = 'offer'; f.st.offer = inp.value; } else f.st.ref = inp.value; }
            markDirty(f); };
          const sel = inp.tagName === 'SELECT' || inp.type === 'checkbox'; inp.oninput = sel ? null : upd; inp.onchange = () => { upd(); if (['u', 'ref', 'on', 'kind'].includes(inp.dataset.f)) setTimeout(draw, 0); }; });
        row.querySelector('[data-rm]').onclick = () => {
          if (f.src === 'ap') { if (f.p.steps.length <= 1) { toast('Keep at least one step in this lane.', true); return; } f.p.steps.splice(f.idx, 1); dirty.ap.add(f.p.id); } else { f.arr.splice(f.idx, 1); dirty.buyer = true; }
          draw(); }; });
    }
    load(false);
    return () => { };
  }
  WaPanels.add('jtimeline', (el) => mount(el));
})();
