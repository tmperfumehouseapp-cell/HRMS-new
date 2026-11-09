/**
 * wj_ui.js — 🗓 THE 60-DAY JOURNEY (WhatsApp → Automation → 60-day Journey). Admin only. Server: wa_journey.php (fu.wj_*).
 *   Tabs: Journey (two 60-day tracks, edit every day) · Claude (the general brain: talk → questions + exact changes → ▶ Start / ⏹ Stop) · Topics (learned every night)
 *         · Messages & offers (the ONE central registry, shared with Follow-ups) · Festivals (watcher + festival box) · Results (numbers + who gets what now).
 */
(function () {
  'use strict';
  if (window.WaJourney) return;
  if (!window.WaPanels || !WaPanels.util || typeof api === 'undefined') return;
  const { esc, toast } = WaPanels.util;
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const CSS = `
.wj{--ln:#e6e8ef;--mut:#64748b;--ink:#0f172a;--g:#059669;--gb:#ecfdf5;--o:#c2410c;--ob:#fff7ed;--p:#5b3fd6;--pb:#f4f1ff;--y:#b45309;--yb:#fffbeb;font-size:14px;color:var(--ink);display:grid;gap:12px;min-width:0}
.wj *{box-sizing:border-box}.wj-card{background:#fff;border:1px solid var(--ln);border-radius:14px;padding:14px 16px;min-width:0}
.wj-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 8px;font-size:15px;font-weight:800}.wj-h .sp{flex:1}.wj-sub{color:var(--mut);font-size:12.5px;line-height:1.45}
.wj-seg{display:flex;gap:6px;flex-wrap:wrap}.wj-seg button,.wj-b{border:1px solid var(--ln);background:#fff;border-radius:999px;padding:6px 13px;font-weight:700;font-size:13px;cursor:pointer;color:var(--ink)}
.wj-seg button.on{background:var(--ink);color:#fff;border-color:var(--ink)}.wj-seg button.on.m-off{background:#64748b;border-color:#64748b}.wj-seg button.on.m-suggest{background:var(--y);border-color:var(--y)}.wj-seg button.on.m-auto{background:var(--g);border-color:var(--g)}
.wj-b.p{background:var(--g);border-color:var(--g);color:#fff}.wj-b.s{padding:4px 10px;font-size:12px}.wj-b.r{color:#b42318;border-color:#f3c0bb}.wj-b.v{background:var(--p);border-color:var(--p);color:#fff}.wj-b:disabled{opacity:.55;cursor:wait}
.wj-in,.wj-ta,.wj-sel{border:1px solid var(--ln);border-radius:10px;padding:7px 9px;font:inherit;background:#fff;min-width:0;max-width:100%}.wj-ta{width:100%;min-height:68px;resize:vertical}.wj-in.f{width:100%}
.wj-kpi{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}.wj-k{border:1px solid var(--ln);border-radius:12px;padding:8px 10px}.wj-k small{display:block;font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--mut)}.wj-k b{font-size:19px}
.wj-ban{border-radius:12px;padding:10px 12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.wj-ban.v{background:var(--pb);border:1px solid #d9d0ff}.wj-ban.y{background:var(--yb);border:1px solid #fde3a7}.wj-ban.g{background:var(--gb);border:1px solid #bfe5cd}.wj-ban .sp{flex:1;min-width:140px}
.wj-pill{font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 8px;background:#eef0f3;color:#334155;white-space:nowrap}.wj-pill.g{background:var(--gb);color:#065f46}.wj-pill.o{background:var(--ob);color:var(--o)}.wj-pill.r{background:#fff1f0;color:#b42318}
.wj-strip{display:grid;grid-template-columns:repeat(60,minmax(0,1fr));gap:2px;margin:4px 0}.wj-strip i{height:14px;border-radius:3px;background:#eef0f3;display:block}.wj-strip i.a{background:#c4b5fd}.wj-strip i.m{background:#34d399}.wj-strip i.f{background:#fb923c}
.wj-strip i.t{background:#7c3aed;box-shadow:0 0 0 1px #5b3fd6 inset}.wj-lane{border:1px solid var(--ln);border-radius:14px;padding:12px 14px;display:grid;gap:6px;background:#fff}.wj-lane.all{background:var(--pb);border-color:#d9d0ff}
.wj-lg{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--mut)}.wj-lg i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-2px}
.wj-ch{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--ln);border-radius:999px;padding:2px 9px;font-size:12px;background:#fff;margin:2px 4px 2px 0}.wj-ch.t{background:#f3e8ff;border-color:#d8b4fe;font-weight:700}
.wj-scale{display:flex;justify-content:space-between;font-size:10.5px;color:var(--mut)}
.wj-row{display:grid;grid-template-columns:92px minmax(150px,1.1fr) minmax(110px,.8fr) minmax(140px,1.4fr) auto auto auto;gap:6px;align-items:center;border-top:1px solid var(--ln);padding:7px 0}.wj-row.off{opacity:.5}
.wj-row input[type=number]{width:62px}.wj-row .wj-sel,.wj-row .wj-in{width:100%}.wj-auto{background:var(--pb);border:1px dashed #d9d0ff;border-radius:10px;padding:8px 10px;margin:6px 0}
.wj-prop{border:1px solid #d9d0ff;background:var(--pb);border-radius:12px;padding:12px 14px}.wj-prop li{margin:4px 0}.wj-q{background:var(--yb);border:1px solid #fde3a7;border-radius:10px;padding:8px 12px;margin:8px 0}.wj-q li{margin:3px 0}
.wj-turn{font-size:12.5px;padding:4px 0;border-top:1px dashed var(--ln)}.wj-turn b{color:var(--p)}
.wj-bar{height:8px;border-radius:99px;background:#eef0f3;overflow:hidden}.wj-bar i{display:block;height:100%;background:var(--p)}
.wj-tp{border-top:1px solid var(--ln);padding:9px 0;display:grid;gap:4px}.wj-ex{font-size:12px;color:var(--mut);font-style:italic}
.wj-msg{border:1px solid var(--ln);border-radius:12px;padding:10px 12px;display:grid;gap:6px}
.wj-tbl{width:100%;border-collapse:collapse;font-size:13px}.wj-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);text-align:left;padding:5px 6px}.wj-tbl td{padding:6px;border-top:1px solid var(--ln)}.wj-tbl .n{text-align:right}
.wj-row2{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wj-err{background:#fff1f0;border:1px solid #f3c0bb;color:#a12;border-radius:10px;padding:8px 10px}
@media (max-width:760px){.wj-kpi{grid-template-columns:repeat(2,minmax(0,1fr))}.wj-row{grid-template-columns:1fr 1fr}.wj-card{padding:12px}}`;
  function css() { if (document.getElementById('wjCss')) return; const s = document.createElement('style'); s.id = 'wjCss'; s.textContent = CSS; document.head.appendChild(s); }
  async function call(fn) { try { const r = await fn(); if (r && r.message) toast(r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } }
  const TPL = { approved: ['🟢 Approved', 'g'], approved_old: ['🟡 Older words approved', ''], pending: ['🟡 Waiting for Meta', ''], rejected: ['🔴 Meta said no', 'r'], none: ['Not at Meta yet', 'o'] };
  const tplPill = (s) => { const t = TPL[s] || ['…', '']; return '<span class="wj-pill ' + t[1] + '">' + t[0] + '</span>'; };
  const TRACK = { lead: ["Didn't order", 'Day counted from the customer’s last message. When they write again, the count starts again.'], buyer: ['Ordered', 'Day counted from the customer’s last order. Order updates and After-delivery messages keep running as they are.'] };

  function mic(ta) {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition; const w = document.createElement('div'); w.className = 'wj-row2'; w.style.marginTop = '6px';
    if (!SR) { w.innerHTML = '<span class="wj-sub">🎤 Voice typing works in Google Chrome — you can still write here.</span>'; return w; }
    w.innerHTML = '<select class="wj-sel" data-lg><option value="hi-IN">हिन्दी</option><option value="gu-IN">ગુજરાતી</option><option value="en-IN">English</option></select><button type="button" class="wj-b s" data-mic>🎤 Speak</button>';
    const btn = w.querySelector('[data-mic]'), lg = w.querySelector('[data-lg]'); let rec = null, on = false, base = '';
    try { lg.value = localStorage.getItem('wjLang') || 'hi-IN'; } catch (e) { /* ok */ }
    const stop = () => { on = false; if (rec) { try { rec.stop(); } catch (e) { /* ok */ } } btn.textContent = '🎤 Speak'; };
    btn.onclick = () => {
      if (on) { stop(); return; } try { localStorage.setItem('wjLang', lg.value); } catch (e) { /* ok */ }
      rec = new SR(); rec.lang = lg.value; rec.continuous = true; rec.interimResults = true; base = ta.value ? ta.value.trimEnd() + ' ' : ''; on = true; btn.textContent = '⏹ Stop';
      rec.onresult = (ev) => { let t = ''; for (let i = 0; i < ev.results.length; i++) t += ev.results[i][0].transcript; ta.value = base + t; };
      rec.onerror = stop; rec.onend = () => { if (on) stop(); }; try { rec.start(); } catch (e) { stop(); }
    };
    return w;
  }

  function mount(el, solo) {
    css(); let D = null, tab = solo === 'msgs' ? 'msgs' : 'lanes', R = null, Q = null, rdays = 30, TR = { lead: [], buyer: [] }, dirty = { lead: false, buyer: false }, busy = false;
    el.innerHTML = '<div class="wj"><div class="wj-card">Loading…</div></div>';
    const take = (d) => { D = d; if (!dirty.lead) TR.lead = JSON.parse(JSON.stringify(d.steps.lead)); if (!dirty.buyer) TR.buyer = JSON.parse(JSON.stringify(d.steps.buyer)); };
    async function load() { try { take(await api.get('fu.wj_get')); } catch (e) { el.innerHTML = '<div class="wj"><div class="wj-err">' + esc(e.message) + '</div></div>'; return; } draw(); }
    const act = async (action, body, after) => { if (busy) return null; busy = true; const r = await call(() => api.post(action, body || {})); busy = false; if (r && r.steps) { if (after !== 'keep') { dirty.lead = dirty.buyer = false; } take(r); if (r.popup) tab = 'claude'; draw(); } else if (r) { await load(); } return r; };

    function head() {
      const s = D.stats || {}, m = D.mode;
      const kp = [['In the journey now', (s.lead || 0) + (s.buyer || 0)], ['Joined in 24 h', s.joined || 0], ['Leaving (day 60)', s.leaving || 0], ['Sent today', s.sent_today || 0], ['Sent in 7 days', s.sent_7 || 0]];
      const tabs = [['lanes', '🗺 By topic'], ['journey', '🗓 Journey'], ['claude', '🧠 Claude'], ['topics', '💡 Topics'], ['msgs', '🎁 Messages & offers'], ['fest', '🪔 Festivals'], ['results', '📊 Results']];
      let h = '<div class="wj-card"><div class="wj-h">🗓 60-day Journey<span class="sp"></span><span class="wj-seg">' + [['off', 'Off'], ['suggest', 'Preview'], ['auto', 'Auto']].map(([k, l]) => '<button type="button" data-mode="' + k + '" class="m-' + k + (m === k ? ' on' : '') + '">' + l + '</button>').join('') + '</span>'
        + (D.hist.length ? '<button class="wj-b s" type="button" data-undo title="' + esc(D.hist[0].why) + '">↩ Undo</button>' : '') + '</div>'
        + '<div class="wj-sub">One plan for every customer for 60 days. Every day new people enter (their day arrives) and people past day 60 simply leave. '
        + (m === 'auto' ? '<b>Auto</b>: messages go by themselves, between ' + esc(hm(D.cfg.from)) + ' and ' + esc(hm(D.cfg.to)) + ', never in quiet hours.' : m === 'suggest' ? '<b>Preview</b>: nothing is sent — see who would get what in Results → “Who gets what now”. Switch to <b>Auto</b> when it looks right.' : '<b>Off</b>: nothing is sent (Claude still learns the topics at night).') + '</div>'
        + '<div class="wj-kpi" style="margin-top:10px">' + kp.map(([a, b]) => '<div class="wj-k"><small>' + a + '</small><b>' + Number(b).toLocaleString('en-IN') + '</b></div>').join('') + '</div></div>';
      const bans = [];
      if (D.proposal && (D.proposal.ops || []).length) bans.push('<div class="wj-ban v"><span class="sp">🧠 <b>Claude has ' + D.proposal.ops.length + ' change(s) waiting</b>' + (D.proposal.source === 'night' ? ' — from last night’s review' : D.proposal.source === 'festival' ? ' — for the festival' : '') + '. Nothing changes before you press Start.</span><button class="wj-b v" type="button" data-go="claude">Review</button></div>');
      if (D.fest_sug && D.fest_sug.name) bans.push('<div class="wj-ban y"><span class="sp">🪔 <b>' + esc(D.fest_sug.name) + '</b> is ' + (D.fest_sug.days_left <= 0 ? 'today' : 'in ' + D.fest_sug.days_left + ' days') + '. Switch the festival theme?</span><button class="wj-b" type="button" data-go="fest">Look</button></div>');
      const wait = D.messages.filter((x) => x.uses && x.status === 'none').length; if (wait) bans.push('<div class="wj-ban y"><span class="sp">📨 <b>' + wait + ' message(s) are not at Meta yet</b> — they go by themselves in a few minutes (or press the button).</span><button class="wj-b" type="button" data-tpl>Send them now</button></div>');
      if (!D.ready) bans.push('<div class="wj-err">The Follow-up Autopilot module is missing on this server, so the journey cannot run.</div>');
      return h + bans.join('') + '<div class="wj-card" style="padding:10px 12px"><div class="wj-seg">' + tabs.map(([k, l]) => '<button type="button" data-tab="' + k + '" class="' + (tab === k ? 'on' : '') + '">' + l + '</button>').join('') + '</div></div>';
    }
    const hm = (m) => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    function strip(tk) {
      const cells = []; for (let d = 1; d <= 60; d++) cells.push({ c: '', t: 'Day ' + d });
      if (tk === 'lead') for (const a of D.auto10) { const c = cells[a.day - 1]; if (c) { c.c = 'a'; c.t = 'Day ' + a.day + ' · Follow-up Autopilot (' + (a.msg ? a.msg + ' message' : '') + (a.msg && a.offer ? ', ' : '') + (a.offer ? a.offer + ' offer' : '') + ')'; } }
      for (const s of TR[tk]) { const c = cells[s.day - 1]; if (c && s.on) { c.c = s.icon === '🎁' ? 'f' : 'm'; c.t = 'Day ' + s.day + ' · ' + s.label; } }
      return '<div class="wj-strip">' + cells.map((c) => '<i class="' + c.c + '" title="' + esc(c.t) + '"></i>').join('') + '</div><div class="wj-scale"><span>day 1</span><span>15</span><span>30</span><span>45</span><span>60</span></div>';
    }
    const stMark = (s) => s === 'approved' || s === 'partial' ? '✅ ' : s === 'pending' ? '⏳ ' : s === 'rejected' ? '❌ ' : '📨 ';   // ✅ approved · ⏳ waiting for Meta · ❌ rejected · 📨 not sent yet (a day with a message that is not approved sends nothing)
    const refOpts = (sel) => '<optgroup label="💬 Messages">' + D.messages.filter((x) => x.icon === '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + stMark(x.status) + esc(x.label) + '</option>').join('') + '</optgroup><optgroup label="🎁 Offers">' + D.messages.filter((x) => x.icon !== '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + stMark(x.status) + esc(x.label) + '</option>').join('') + '</optgroup><optgroup label="➕ Make a new one"><option value="__new">➕ New message or offer…</option></optgroup>';
    const topicOpts = (sel) => '<option value="">Everyone</option>' + D.topics.rows.map((t) => '<option value="' + esc(t.key) + '"' + (t.key === sel ? ' selected' : '') + '>Only: ' + esc(t.title) + '</option>').join('') + (sel && !D.topics.rows.some((t) => t.key === sel) ? '<option value="' + esc(sel) + '" selected>Only: ' + esc(sel) + '</option>' : '');
    function trackCard(tk) {
      const T = TRACK[tk], rows = TR[tk];
      let h = '<div class="wj-card"><div class="wj-h">' + (tk === 'lead' ? '🙋' : '🛍') + ' ' + T[0] + '<span class="wj-pill">' + rows.filter((x) => x.on).length + ' messages</span><span class="sp"></span>' + (dirty[tk] ? '<span class="wj-pill o">not saved</span>' : '') + '</div><div class="wj-sub">' + T[1] + '</div>' + strip(tk);
      if (tk === 'lead') h += '<div class="wj-auto"><b>Days 1–10 · Follow-up Autopilot.</b> ' + (D.auto10.length ? D.auto10.map((a) => 'day ' + a.day + ': ' + (a.msg ? a.msg + ' msg' : '') + (a.msg && a.offer ? ' + ' : '') + (a.offer ? a.offer + ' offer' : '')).join(' · ') : 'not set up yet') + ' <a href="whatsapp.html#followups" target="_top">Edit in Follow-ups →</a></div>';
      h += rows.map((x, i) => '<div class="wj-row' + (x.on ? '' : ' off') + '" data-t="' + tk + '" data-i="' + i + '"><span>Day <input class="wj-in" type="number" min="1" max="60" data-f="day" value="' + x.day + '"></span><select class="wj-sel" data-f="ref">' + refOpts(x.ref) + '</select><select class="wj-sel" data-f="topic">' + topicOpts(x.topic) + '</select><input class="wj-in" data-f="idea" maxlength="300" placeholder="Extra idea for Claude (optional)" value="' + esc(x.idea) + '"><label style="white-space:nowrap"><input type="checkbox" data-f="on"' + (x.on ? ' checked' : '') + '> On</label>' + tplPill(x.tpl) + '<button class="wj-b s r" type="button" data-rm="' + tk + ':' + i + '" title="Remove this day">✕</button></div>').join('')
        || '<div class="wj-sub" style="padding:8px 0">No days yet.</div>';
      return h + '<div class="wj-row2" style="margin-top:10px"><button class="wj-b" type="button" data-add="' + tk + '">＋ Add a day</button><button class="wj-b p" type="button" data-save="' + tk + '"' + (dirty[tk] ? '' : ' disabled') + '>Save this track</button><button class="wj-b s" type="button" data-ask="' + tk + '">🧠 Ask Claude to improve it</button></div></div>';
    }
    function cfgCard() {
      const c = D.cfg; return '<div class="wj-card"><div class="wj-h">⚙️ Sending rules</div><div class="wj-row2"><label class="wj-sub">At least <input class="wj-in" style="width:56px" type="number" min="1" max="14" id="cfGap" value="' + c.gap_days + '"> days between two journey messages</label>'
        + '<label class="wj-sub">Max <input class="wj-in" style="width:76px" type="number" min="10" id="cfDay" value="' + c.day_cap + '"> a day</label><label class="wj-sub">Between <input class="wj-in" type="time" id="cfFrom" value="' + hm(c.from) + '"> and <input class="wj-in" type="time" id="cfTo" value="' + hm(c.to) + '"></label>'
        + '<label class="wj-sub">Festival reminder <input class="wj-in" style="width:56px" type="number" min="7" max="45" id="cfLead" value="' + c.lead_days + '"> days before</label><button class="wj-b s" type="button" id="cfSave">Save</button></div>'
        + '<div class="wj-sub" style="margin-top:6px">Also always respected: opt-outs, quiet hours and the daily limits of Teach, an open complaint, an order message in the last 2 days, a follow-up in the last 36 hours, and the sender’s own safety brake. A day that came before Auto was switched on is never caught up.</div></div>';
    }
    const journeyTab = () => trackCard('lead') + trackCard('buyer') + cfgCard();

    // 🗺 One picture of the whole plan: a lane per topic. Every lane shows days 1–60: the Autopilot days (1–10), the steps for everyone, and the steps only for that topic.
    function laneStrip(key) {
      const cells = []; for (let d = 1; d <= 60; d++) cells.push({ c: '', t: 'Day ' + d });
      for (const a of D.auto10) { const c = cells[a.day - 1]; if (c) { c.c = 'a'; c.t = 'Day ' + a.day + ' · Follow-up Autopilot (' + (a.msg ? a.msg + ' message' : '') + (a.msg && a.offer ? ', ' : '') + (a.offer ? a.offer + ' offer' : '') + ')'; } }
      for (const s of TR.lead) { if (!s.on || (s.topic && s.topic !== key)) continue; const c = cells[s.day - 1]; if (!c) continue; c.c = s.topic ? 't' : (s.icon === '🎁' ? 'f' : 'm'); c.t = 'Day ' + s.day + ' · ' + s.label + (s.topic ? ' (only this topic)' : ''); }
      return '<div class="wj-strip">' + cells.map((c) => '<i class="' + c.c + '" title="' + esc(c.t) + '"></i>').join('') + '</div><div class="wj-scale"><span>day 1</span><span>15</span><span>30</span><span>45</span><span>60</span></div>';
    }
    function lane(key, title, sub, all) {
      const mine = TR.lead.filter((s) => s.on && (!s.topic || s.topic === key)).sort((a, b) => a.day - b.day), own = mine.filter((s) => s.topic === key).length;
      return '<div class="wj-lane' + (all ? ' all' : '') + '"><div class="wj-row2"><b style="font-size:15px">' + (all ? '👥 ' : '💡 ') + esc(title) + '</b>' + sub + '<span style="flex:1"></span>'
        + (all ? '' : '<button class="wj-b s" type="button" data-lane-add="' + esc(key) + '">＋ A step only for this topic</button><button class="wj-b s v" type="button" data-lane-ask="' + esc(key) + '" data-title="' + esc(title) + '">🧠 Ask Claude to plan this topic</button>') + '</div>'
        + laneStrip(key) + '<div>' + (mine.length ? mine.map((s) => '<span class="wj-ch' + (s.topic ? ' t' : '') + '" title="' + (s.topic ? 'Only for this topic' : 'Everyone gets this') + '">Day ' + s.day + ' · ' + s.icon + ' ' + esc(s.label) + (s.topic ? ' ★' : '') + '</span>').join('') : '<span class="wj-sub">No steps yet.</span>') + '</div>'
        + (!all ? '<div class="wj-sub">' + (own ? '★ ' + own + ' step' + (own === 1 ? '' : 's') + ' made only for this topic, plus the steps for everyone.' : 'No step is made only for this topic yet — these customers get the steps for everyone. Add one, or ask Claude.') + '</div>' : '') + '</div>';
    }
    function lanesTab() {
      const rows = (D.topics.rows || []).slice().sort((a, b) => (b.n30 || 0) - (a.n30 || 0)).slice(0, 12);
      let h = '<div class="wj-card"><div class="wj-h">🗺 The whole plan, topic by topic</div><div class="wj-sub">Each customer who did not order follows <b>one lane</b>: the topic Claude found in their chats (it reads new chats every night). '
        + 'Days 1–10 belong to the Follow-up Autopilot; from day 11 the Journey carries on. Anything marked ★ is made only for that topic. A customer who is ready to pay is not left to the bot — people step in through 🛟 Rescue, and while a person has taken a chat no automatic message goes out.'
        + (D.season_now ? '<br>🪔 Festival theme right now: <b>' + esc(D.season_now) + '</b> · <button class="wj-b s" type="button" data-go="fest">change it</button> or tell Claude in the 🧠 Claude tab.' : '<br>🪔 No festival theme is on. Tell Claude when a festival or season starts and it will change the plan for you (nothing changes before you press Start).')
        + '</div><div class="wj-lg" style="margin-top:8px"><span><i style="background:#c4b5fd"></i>Follow-up Autopilot</span><span><i style="background:#86efac"></i>Message</span><span><i style="background:#fdba74"></i>Offer</span><span><i style="background:#7c3aed"></i>Only this topic</span></div></div>';
      h += lane('', 'Everyone (every topic)', '<span class="wj-pill">' + TR.lead.filter((x) => x.on && !x.topic).length + ' steps</span>', true);
      h += rows.length ? rows.map((t) => lane(t.key, t.title, '<span class="wj-pill">' + (t.n30 || 0) + ' chats · 30 days</span><span class="wj-pill">' + (t.n7 || 0) + ' this week</span>', false)).join('') : '<div class="wj-card"><div class="wj-sub">No topics learned yet. Press “Read chats now” in the 💡 Topics tab, or wait for tonight.</div></div>';
      return h;
    }


    function propBlock() {
      const P = D.proposal || {}; if (!P.understood) return '';
      let h = '<div class="wj-prop"><div class="wj-h" style="margin-bottom:4px">🧠 What Claude understood</div><div>' + esc(P.understood) + '</div>';
      if ((P.questions || []).length) h += '<div class="wj-q"><b>Claude asks:</b><ol style="margin:4px 0 0 18px">' + P.questions.map((q) => '<li>' + esc(q) + '</li>').join('') + '</ol><div class="wj-sub" style="margin-top:4px">Answer below in your own words.</div></div>';
      if ((P.lines || []).length) h += '<div style="margin-top:8px"><b>What would change</b></div><ul style="margin:4px 0 0 18px;padding:0">' + P.lines.map((l) => '<li>' + esc(l) + '</li>').join('') + '</ul>';
      else if (!(P.questions || []).length) h += '<div class="wj-sub" style="margin-top:6px">Nothing needs to change.</div>';
      if ((P.ops || []).length) h += '<div class="wj-row2" style="margin-top:10px"><button class="wj-b p" type="button" data-start>▶ Start these changes</button><button class="wj-b r" type="button" data-stop>⏹ Stop (change nothing)</button></div>';
      else h += '<div class="wj-row2" style="margin-top:10px"><button class="wj-b r" type="button" data-stop>Close</button></div>';
      return h + ((P.turns || []).length > 2 ? '<details style="margin-top:8px"><summary class="wj-sub">Conversation so far</summary>' + P.turns.map((t) => '<div class="wj-turn"><b>' + (t.who === 'you' ? 'You' : 'Claude') + ':</b> ' + esc(t.text) + '</div>').join('') + '</details>' : '') + '</div>';
    }
    function claudeTab() {
      const has = D.proposal && D.proposal.understood;
      return '<div class="wj-card"><div class="wj-h">🧠 Talk to Claude about the whole journey</div><div class="wj-sub">Say it in your own words (Hindi / Gujarati / English) — e.g. “Diwali ke baad New Year ka theme lagao”, “add a day 14 message for gift customers”, “make Offer 2 a buy-2-get-1”, “remove the last-note on day 60”. '
        + 'Claude reads everything the Teach bot knows, the topics, the results and the festival calendar. It asks you questions first when it is unsure, shows every change, and nothing happens before you press ▶ Start.</div>'
        + '<textarea class="wj-ta" id="wjSum" style="margin-top:8px" placeholder="' + (has && (D.proposal.questions || []).length ? 'Answer Claude’s questions…' : 'Write what to change…') + '"></textarea><div id="wjMic"></div><div class="wj-row2" style="margin-top:6px"><button class="wj-b v" type="button" id="wjSumGo">' + (has ? 'Send to Claude' : 'Ask Claude') + '</button>'
        + (D.ai ? '' : '<span class="wj-sub">Claude is not set up on this server.</span>') + '</div></div>' + (has ? propBlock() : '');
    }
    function topicsTab() {
      const T = D.topics, N = D.night || {};
      let h = '<div class="wj-card"><div class="wj-h">💡 What your customers talk about<span class="sp"></span><button class="wj-b s" type="button" data-night>Read chats now</button></div><div class="wj-sub">Every night Claude reads the day’s chats and groups them into topics, notes the perfume each customer liked and the one angle that would win them back — then reviews the journey and leaves a suggestion for you (🧠 Claude). '
        + (T.updated ? 'Last learned: ' + esc(T.updated) + '.' : 'Not learned yet — it starts tonight, or press “Read chats now”.') + (N.stage && N.stage !== 'done' && N.day ? ' <b>Working: ' + esc(N.stage === 'topics' ? 'reading chats (' + ((N.total || 0) - (N.queue || []).length) + '/' + (N.total || 0) + ')' : N.stage) + '…</b>' : '') + (N.note ? ' ' + esc(N.note) : '') + '</div></div>';
      h += '<div class="wj-card">' + (T.rows.length ? T.rows.map((t) => '<div class="wj-tp"><div class="wj-row2"><b>' + esc(t.title) + '</b><span class="wj-pill">' + t.n30 + ' chats · 30 days</span><span class="wj-pill">' + t.n7 + ' this week</span><span class="sp" style="flex:1"></span><button class="wj-b s" type="button" data-topic="' + esc(t.key) + '" data-title="' + esc(t.title) + '">🧠 Make a message for this</button></div><div class="wj-bar"><i style="width:' + Math.max(2, t.share) + '%"></i></div>'
        + (t.ex || []).map((e) => '<div class="wj-ex">“' + esc(e.q) + '”' + (e.h ? ' → <span style="font-style:normal;color:var(--p)">' + esc(e.h) + '</span>' : '') + '</div>').join('') + '</div>').join('') : '<div class="wj-sub">No topics yet.</div>') + '</div>';
      return h;
    }
    function msgsTab() {
      let h = '<div class="wj-card"><div class="wj-h">🎁 Messages & offers — one registry<span class="sp"></span><button class="wj-b s" type="button" data-tpl>Send all waiting to Meta</button></div><div class="wj-sub">Every journey day (and every Follow-up offer day) points to one entry here. Change an entry and every day that uses it changes. Each entry is ONE message Meta approves once; '
        + '<b>{{1}}</b> = the customer’s name, <b>{{2}}</b> = their perfume, <b>{{3}}</b> = the line Claude writes for each customer from your rule — it never goes below your price chart. Changing the words sends a new version to Meta.</div></div>';
      h += D.messages.map((m) => '<div class="wj-msg" data-mk="' + esc(m.k) + '"><div class="wj-row2"><b>' + m.icon + '</b><input class="wj-in" data-mf="label" style="flex:1;min-width:140px" value="' + esc(m.label) + '" maxlength="40">' + tplPill(m.status) + '<span class="wj-pill">' + m.uses + ' day(s) use it</span>' + (m.uses || ['o1', 'o2', 'o3', 'mega'].includes(m.k) ? '' : '<button class="wj-b s r" type="button" data-mdel="' + esc(m.k) + '">Delete</button>') + '</div>'
        + '<label class="wj-sub">Rule — what Claude puts in {{3}} for each customer</label><textarea class="wj-ta" data-mf="rule" style="min-height:52px" maxlength="300">' + esc(m.rule) + '</textarea><label class="wj-sub">Approved message words</label><textarea class="wj-ta" data-mf="body" style="min-height:60px" maxlength="900">' + esc(m.body) + '</textarea></div>').join('');
      return h + '<div class="wj-card"><div class="wj-row2"><button class="wj-b p" type="button" id="mSave">Save all messages</button><button class="wj-b" type="button" id="mNew">＋ New offer or message</button></div><div id="mNewBox"></div></div>';
    }
    function festTab() {
      const S = D.season || {}, F = D.fest_sug || {}; let h = '';
      if (F.name) h += '<div class="wj-card" style="border-color:#fde3a7;background:var(--yb)"><div class="wj-h">🪔 ' + esc(F.name) + ' is ' + (F.days_left <= 0 ? 'today' : 'in ' + F.days_left + ' days') + '</div><div class="wj-sub">Switch the festival theme? Every message and offer will use it, and Claude prepares the word changes for you to check (nothing changes there before ▶ Start). It switches back by itself after the end date.</div>'
        + '<label class="wj-sub">Theme</label><input class="wj-in f" id="fsText" value="' + esc(F.text) + '"><div class="wj-row2" style="margin-top:6px"><label class="wj-sub">From <input class="wj-in" type="date" id="fsFrom" value="' + esc(F.from) + '"></label><label class="wj-sub">To <input class="wj-in" type="date" id="fsTo" value="' + esc(F.to) + '"></label></div>'
        + '<div class="wj-row2" style="margin-top:8px"><button class="wj-b p" type="button" data-fest="yes">Yes — switch to ' + esc(F.name) + '</button><button class="wj-b" type="button" data-fest="later">Ask me in 3 days</button><button class="wj-b r" type="button" data-fest="skip">Skip this one</button></div></div>';
      h += '<div class="wj-card"><div class="wj-h">🎉 Festival box<span class="wj-pill ' + (D.season_now ? 'g' : '') + '">' + (D.season_now ? 'ON now' : 'off') + '</span></div><div class="wj-sub">Every message and offer uses this wording while it is on and today is inside the dates. The Follow-ups tab uses the same box.</div>'
        + '<label class="wj-sub">Theme</label><input class="wj-in f" id="seText" value="' + esc(S.text || '') + '" maxlength="300"><div class="wj-row2" style="margin-top:6px"><label class="wj-sub">From <input class="wj-in" type="date" id="seFrom" value="' + esc(S.from || '') + '"></label><label class="wj-sub">To <input class="wj-in" type="date" id="seTo" value="' + esc(S.to || '') + '"></label><label class="wj-sub"><input type="checkbox" id="seOn"' + (S.on ? ' checked' : '') + '> On</label><button class="wj-b p s" type="button" id="seSave">Save</button></div></div>';
      h += '<div class="wj-card"><div class="wj-h">📅 Coming festivals</div><div class="wj-sub">Dates are the usual ones — check the exact date of moving festivals and fix by adding your own below. ' + D.cfg.lead_days + ' days before one, Claude asks if you want to switch the theme.</div>'
        + D.festivals.map((f) => '<div class="wj-tp"><div class="wj-row2"><b>' + esc(f.name) + '</b><span class="wj-pill">' + esc(f.date) + '</span><span class="wj-pill ' + (f.days_left <= D.cfg.lead_days ? 'o' : '') + '">' + (f.days_left <= 0 ? 'today' : 'in ' + f.days_left + ' days') + '</span>' + (f.custom ? '<button class="wj-b s r" type="button" data-fdel="' + esc(f.name) + '|' + esc(f.date) + '">Remove</button>' : '') + '</div><div class="wj-sub">' + esc(f.theme) + '</div></div>').join('')
        + '<div class="wj-row2" style="margin-top:10px"><input class="wj-in" id="faName" placeholder="Festival or sale name" style="min-width:160px"><input class="wj-in" type="date" id="faDate"><input class="wj-in" id="faTheme" placeholder="Theme words (optional)" style="min-width:200px"><button class="wj-b s" type="button" id="faAdd">＋ Add</button></div></div>';
      return h;
    }
    function resultsTab() {
      let h = '<div class="wj-card"><div class="wj-h">📊 Results<span class="sp"></span><span class="wj-seg">' + [7, 30, 90].map((d) => '<button type="button" data-rd="' + d + '" class="' + (rdays === d ? 'on' : '') + '">' + d + ' days</button>').join('') + '</span></div>';
      if (!R) h += '<div class="wj-sub">Loading…</div>';
      else { const t = R.total, lab = {}; for (const tk of ['lead', 'buyer']) for (const s of D.steps[tk]) lab[tk + '|' + s.id] = [tk === 'lead' ? "Didn't order" : 'Ordered', s.day, s.label];
        h += '<div class="wj-kpi" style="grid-template-columns:repeat(4,minmax(0,1fr))">' + [['Sent', t.sent], ['Replied', t.replied + (t.sent ? ' (' + Math.round(t.replied * 100 / t.sent) + '%)' : '')], ['Ordered after', t.ordered], ['Sales after', inr(t.sales)]].map(([a, b]) => '<div class="wj-k"><small>' + a + '</small><b>' + b + '</b></div>').join('') + '</div>'
          + '<div class="wj-sub" style="margin:6px 0">Replied = wrote within 2 days. Ordered = an order within 7 days after the last journey message.</div>'
          + (R.steps.length ? '<table class="wj-tbl"><tr><th>Track</th><th class="n">Day</th><th>Message</th><th class="n">Sent</th><th class="n">Replied</th><th class="n">Ordered</th><th class="n">₹</th></tr>' + R.steps.sort((a, b) => ((lab[a.track + '|' + a.step] || [0, 0])[1] - (lab[b.track + '|' + b.step] || [0, 0])[1])).map((s) => { const l = lab[s.track + '|' + s.step] || ['?', '?', s.step]; return '<tr><td>' + l[0] + '</td><td class="n">' + l[1] + '</td><td>' + esc(l[2]) + '</td><td class="n">' + s.sent + '</td><td class="n">' + s.replied + (s.sent ? ' · ' + Math.round(s.replied * 100 / s.sent) + '%' : '') + '</td><td class="n">' + s.ordered + '</td><td class="n">' + inr(s.sales) + '</td></tr>'; }).join('') + '</table>' : '<div class="wj-sub">Nothing sent yet in this period.</div>'); }
      h += '</div><div class="wj-card"><div class="wj-h">👀 Who gets what now<span class="sp"></span><button class="wj-b s" type="button" data-queue>Show</button></div><div class="wj-sub">Everyone whose day has come right now (nothing is sent from here).</div>';
      if (Q) { h += '<div style="margin:8px 0"><b>' + Q.total + '</b> customer(s) are due' + (Q.blocked ? ' · ' + Q.blocked + ' held back (complaint, recent message…)' : '') + (Q.waiting_meta ? ' · ' + Q.waiting_meta + ' wait for Meta to approve their message' : '') + '</div>'
        + (Q.rows.length ? '<table class="wj-tbl"><tr><th>Customer</th><th>Track</th><th class="n">Day</th><th>Message</th><th>Topic</th></tr>' + Q.rows.map((r) => '<tr><td>' + esc(r.name) + ' …' + esc(r.tail) + '</td><td>' + (r.track === 'lead' ? "Didn't order" : 'Ordered') + '</td><td class="n">' + r.day + '</td><td>' + esc(r.label) + ' ' + tplPill(r.tpl) + '</td><td>' + esc(r.topic) + '</td></tr>').join('') + '</table>' : '<div class="wj-sub">Nobody is due right now.</div>'); }
      return h + '</div>';
    }
    function draw() {
      if (!D) return; const scroll = el.scrollTop;
      el.innerHTML = '<div class="wj">' + (solo ? '' : head()) + (tab === 'lanes' ? lanesTab() : tab === 'journey' ? journeyTab() : tab === 'claude' ? claudeTab() : tab === 'topics' ? topicsTab() : tab === 'msgs' ? msgsTab() : tab === 'fest' ? festTab() : resultsTab()) + '</div>'; bind(); el.scrollTop = scroll;
    }
    async function loadResults() { R = null; draw(); try { R = await api.get('fu.wj_results', { days: rdays }); } catch (e) { R = { total: { sent: 0, replied: 0, ordered: 0, sales: 0 }, steps: [] }; } draw(); }
    async function ask(text, more) { const r = await act('fu.wj_brain', { text, more: !!more }, 'keep'); if (r && r.steps) { tab = 'claude'; draw(); } return r; }
    // ➕ make a new message / offer without leaving the day: it is saved in the message library and chosen for that day
    function newMsg(done) {
      const o = document.createElement('div'); o.style.cssText = 'position:fixed;inset:0;z-index:300;background:#0008;display:flex;align-items:flex-end;justify-content:center';
      o.innerHTML = '<div class="wj" style="background:#fff;width:100%;max-width:560px;border-radius:18px 18px 0 0;padding:16px;max-height:92vh;overflow:auto"><div class="wj-h">➕ New message or offer</div><div class="wj-sub">It is saved in <b>Messages → My messages</b> with this name, so you can pick it on any day, in any topic.</div>'
        + '<label class="wj-sub">Name (title)</label><input class="wj-in f" id="nmN" maxlength="40" placeholder="e.g. Diwali buy 2 get 1">'
        + '<div class="wj-row2" style="margin-top:6px"><label><input type="radio" name="nmT" value="msg" checked> 💬 Message (no discount)</label><label><input type="radio" name="nmT" value="offer"> 🎁 Offer</label></div>'
        + '<label class="wj-sub">Rule — what Claude writes in {{3}} for each customer</label><textarea class="wj-ta" id="nmR" style="min-height:60px" maxlength="300" placeholder="e.g. Buy two perfumes and get a 10 ml free"></textarea>'
        + '<label class="wj-sub">Message words (optional — if empty a friendly default is used). Keep {{1}} name, {{2}} perfume, {{3}} the line, once each in this order.</label><textarea class="wj-ta" id="nmB" style="min-height:70px" maxlength="900"></textarea>'
        + '<div class="wj-row2" style="margin-top:10px"><button class="wj-b p" type="button" id="nmGo">Save in library &amp; use here</button><button class="wj-b" type="button" id="nmX">Cancel</button></div></div>';
      document.body.appendChild(o); const q = (s) => o.querySelector(s); const close = (k) => { o.remove(); done(k || ''); };
      q('#nmX').onclick = () => close(''); o.onclick = (e) => { if (e.target === o) close(''); };
      q('#nmGo').onclick = async () => { const label = q('#nmN').value.trim(), rule = q('#nmR').value.trim(); if (!label || !rule) { toast('Give it a name and a rule.', true); return; }
        q('#nmGo').disabled = true; const r = await act('fu.wj_msg_new', { label, rule, body: q('#nmB').value.trim(), offer: q('input[name=nmT]:checked').value === 'offer' }, 'keep'); if (r && r.new_key) close(r.new_key); else q('#nmGo').disabled = false; };
    }
    function bind() {
      const $ = (s) => el.querySelector(s), $$ = (s) => [...el.querySelectorAll(s)];
      $$('[data-tab]').forEach((b) => b.onclick = () => { tab = b.dataset.tab; draw(); if (tab === 'results') loadResults(); });
      $$('[data-go]').forEach((b) => b.onclick = () => { tab = b.dataset.go; draw(); });
      $$('[data-mode]').forEach((b) => b.onclick = async () => { const m = b.dataset.mode; if (m === D.mode) return; if (m === 'auto' && !confirm('Auto: journey messages go to customers by themselves, from today (earlier days are not caught up). Did you check “Who gets what now” and the message words?')) return; await act('fu.wj_mode', { mode: m }, 'keep'); });
      const un = $('[data-undo]'); if (un) un.onclick = async () => { if (confirm('Undo the last change (' + D.hist[0].why + ')?')) await act('fu.wj_undo'); };
      $$('[data-tpl]').forEach((b) => b.onclick = () => act('fu.wj_tpl', {}, 'keep'));
      // journey rows
      $$('.wj-row').forEach((row) => { const tk = row.dataset.t, i = +row.dataset.i;
        row.querySelectorAll('[data-f]').forEach((f) => {
          const apply = () => { const k = f.dataset.f, x = TR[tk][i]; x[k] = k === 'day' ? Math.max(1, Math.min(60, +f.value || 1)) : (k === 'on' ? f.checked : f.value);
            if (k === 'ref') { const m = D.messages.find((z) => z.k === f.value); x.label = m ? m.label : f.value; x.icon = m ? m.icon : '🎁'; x.tpl = m ? m.status : 'none'; }
            dirty[tk] = true; const sb = el.querySelector('[data-save="' + tk + '"]'); if (sb) sb.disabled = false; };
          f.oninput = apply; f.onchange = () => { if (f.dataset.f === 'ref' && f.value === '__new') { newMsg((key) => { if (key) { const x = TR[tk][i]; x.ref = key; const m = D.messages.find((z) => z.k === key); if (m) { x.label = m.label; x.icon = m.icon; x.tpl = m.status; } dirty[tk] = true; } draw(); }); return; } apply(); if (f.dataset.f !== 'idea') setTimeout(draw, 0); }; }); });
      $$('[data-rm]').forEach((b) => b.onclick = () => { const [tk, i] = b.dataset.rm.split(':'); TR[tk].splice(+i, 1); dirty[tk] = true; draw(); });
      $$('[data-add]').forEach((b) => b.onclick = () => { const tk = b.dataset.add, last = TR[tk].reduce((a, x) => Math.max(a, x.day), tk === 'lead' ? 10 : 0), m = D.messages[0]; TR[tk].push({ id: '', day: Math.min(60, last + 3), ref: m.k, idea: '', topic: '', on: true, label: m.label, icon: m.icon, tpl: m.status }); dirty[tk] = true; draw(); });
      $$('[data-lane-add]').forEach((b) => b.onclick = () => { const key = b.dataset.laneAdd, last = TR.lead.reduce((a, x) => Math.max(a, x.day), 10), m = D.messages[0]; TR.lead.push({ id: '', day: Math.min(60, last + 3), ref: m.k, idea: '', topic: key, on: true, label: m.label, icon: m.icon, tpl: m.status }); dirty.lead = true; tab = 'journey'; draw(); toast('A new day was added at the bottom of the Didn\'t order track, for this topic only. Pick the day and the message, then press Save.'); });
      $$('[data-lane-ask]').forEach((b) => b.onclick = () => ask('Many customers are in the topic "' + b.dataset.title + '" (' + b.dataset.laneAsk + '). Make a careful plan for them up to day 60: a few steps made ONLY for this topic (send each only to this topic) on good days of the lead track, using existing registry messages or a new one (key starting w_) with a short approved wording. Ask me first if you need to know an offer.', false));
      $$('[data-save]').forEach((b) => b.onclick = async () => { const tk = b.dataset.save; const r = await act('fu.wj_steps_save', { track: tk, steps: TR[tk].map((x) => ({ id: x.id, day: x.day, ref: x.ref, idea: x.idea, topic: x.topic, on: x.on })) }); if (r) { dirty[tk] = false; } });
      $$('[data-ask]').forEach((b) => b.onclick = () => ask('Look at the "' + TRACK[b.dataset.ask][0] + '" track of the journey. Using the topics, the results and the festival calendar, suggest careful improvements (gaps, days with no answers, a message for a growing topic).', false));
      const cs = $('#cfSave'); if (cs) cs.onclick = () => act('fu.wj_cfg', { gap_days: +$('#cfGap').value, day_cap: +$('#cfDay').value, from: $('#cfFrom').value, to: $('#cfTo').value, lead_days: +$('#cfLead').value }, 'keep');
      // claude
      const sm = $('#wjSum'); if (sm) { $('#wjMic').appendChild(mic(sm)); const go = $('#wjSumGo'); go.onclick = async () => { const t = sm.value.trim(); if (!t) return; go.disabled = true; go.textContent = 'Claude is reading…'; const r = await ask(t, !!(D.proposal && D.proposal.understood)); if (!r) { go.disabled = false; go.textContent = 'Ask Claude'; } }; }
      const st = $('[data-start]'); if (st) st.onclick = async () => { st.disabled = true; await act('fu.wj_apply', {}); };
      const sp = $('[data-stop]'); if (sp) sp.onclick = async () => { await act('fu.wj_apply', { drop: 1 }, 'keep'); };
      // topics
      $$('[data-night]').forEach((b) => b.onclick = async () => { b.disabled = true; await act('fu.wj_night_run', {}, 'keep'); });
      $$('[data-topic]').forEach((b) => b.onclick = () => ask('Many customers are in the topic "' + b.dataset.title + '" (' + b.dataset.topic + '). Suggest a message for them: a new registry message (key starting w_) with a short approved wording, and a step for this topic on a good day of the lead track (send it only to this topic). Ask me first if you need to know an offer.', false));
      // messages
      const ms = $('#mSave'); if (ms) ms.onclick = async () => { const items = $$('.wj-msg').map((c) => ({ k: c.dataset.mk, label: c.querySelector('[data-mf=label]').value, rule: c.querySelector('[data-mf=rule]').value, body: c.querySelector('[data-mf=body]').value })); await act('fu.ap_offers_save', { offers: items }, 'keep'); };
      $$('[data-mdel]').forEach((b) => b.onclick = async () => { if (!confirm('Delete this message from the registry?')) return; const items = $$('.wj-msg').filter((c) => c.dataset.mk !== b.dataset.mdel).map((c) => ({ k: c.dataset.mk, label: c.querySelector('[data-mf=label]').value, rule: c.querySelector('[data-mf=rule]').value, body: c.querySelector('[data-mf=body]').value })); await act('fu.ap_offers_save', { offers: items }, 'keep'); });
      const mn = $('#mNew'); if (mn) mn.onclick = () => { $('#mNewBox').innerHTML = '<div class="wj-msg" style="margin-top:10px"><input class="wj-in f" id="nNm" placeholder="Name, e.g. Buy 2 get 1" maxlength="40"><textarea class="wj-ta" id="nRule" style="min-height:52px" placeholder="Rule — what Claude puts in {{3}} for each customer, e.g. Buy two perfumes and get a third 10 ml free"></textarea><div class="wj-row2"><button class="wj-b p s" type="button" id="nGo">Create</button><span class="wj-sub">Claude writes the approved wording and sends it to Meta.</span></div></div>';
        $('#nGo').onclick = async () => { const nm = $('#nNm').value.trim(), rule = $('#nRule').value.trim(); if (!nm || !rule) { toast('Give it a name and a rule.', true); return; } await ask('Create a new registry entry named "' + nm + '" with this rule: ' + rule + '. Write the approved message words for it (key starting o_ if it is an offer, w_ if it is only a message). Do not add it to the journey yet unless I ask.', false); }; };
      // festivals
      $$('[data-fest]').forEach((b) => b.onclick = async () => { const a = b.dataset.fest; const body = { act: a }; if (a === 'yes') { body.text = $('#fsText').value; body.from = $('#fsFrom').value; body.to = $('#fsTo').value; } await act('fu.wj_fest', body, 'keep'); });
      const ss = $('#seSave'); if (ss) ss.onclick = async () => { const r = await call(() => api.post('fu.ap_season_save', { on: $('#seOn').checked, text: $('#seText').value, from: $('#seFrom').value, to: $('#seTo').value })); if (r) load(); };
      const fa = $('#faAdd'); if (fa) fa.onclick = () => act('fu.wj_fest', { act: 'add', name: $('#faName').value, date: $('#faDate').value, theme: $('#faTheme').value }, 'keep');
      $$('[data-fdel]').forEach((b) => b.onclick = () => { const [n, d] = b.dataset.fdel.split('|'); act('fu.wj_fest', { act: 'del', name: n, date: d }, 'keep'); });
      // results
      $$('[data-rd]').forEach((b) => b.onclick = () => { rdays = +b.dataset.rd; loadResults(); });
      $$('[data-queue]').forEach((b) => b.onclick = async () => { b.disabled = true; try { Q = await api.get('fu.wj_queue'); } catch (e) { toast(e.message, true); } draw(); });
    }
    load(); return load;
  }
  WaPanels.add('journey', (el) => mount(el));
  WaPanels.add('mymsgs', (el) => mount(el, 'msgs'));   // Messages → My messages: the same registry, on its own
  window.WaJourney = { mount };
})();
