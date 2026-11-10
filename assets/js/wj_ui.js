/**
 * wj_ui.js — 🗓 THE 60-DAY JOURNEY (WhatsApp → Autobot → 60-day Journey). Admin only. Server: wa_journey.php (fu.wj_*).
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
    css(); let D = null, CHG = null, tab = solo || 'claude', busy = false;
    el.innerHTML = '<div class="wj"><div class="wj-card">Loading…</div></div>';
    const take = (d) => { D = d; if (!CHG || !d.change || d.change.at !== CHG.at) CHG = d.change && (d.change.rows || []).length ? JSON.parse(JSON.stringify(d.change)) : null; };
    async function load() { try { take(await api.get('fu.wj_get')); } catch (e) { el.innerHTML = '<div class="wj"><div class="wj-err">' + esc(e.message) + '</div></div>'; return; } draw(); }
    const act = async (action, body, after) => { if (busy) return null; busy = true; const r = await call(() => api.post(action, body || {})); busy = false; if (r && r.steps) { take(r); if (r.popup) tab = 'claude'; draw(); } else if (r) { await load(); } return r; };

    const hm = (m) => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    const stMark = (s) => s === 'approved_old' ? '✅⏳ ' : s === 'approved' || s === 'partial' ? '✅ ' : s === 'pending' ? '⏳ ' : s === 'rejected' ? '❌ ' : '📨 ';   // ✅ approved · ⏳ waiting for Meta · ❌ rejected · 📨 not sent yet (a day with a message that is not approved sends nothing)
    const refOpts = (sel) => '<optgroup label="💬 Messages">' + D.messages.filter((x) => x.icon === '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + stMark(x.status) + esc(x.label) + '</option>').join('') + '</optgroup><optgroup label="🎁 Offers">' + D.messages.filter((x) => x.icon !== '💬').map((x) => '<option value="' + esc(x.k) + '"' + (x.k === sel ? ' selected' : '') + '>' + stMark(x.status) + esc(x.label) + '</option>').join('') + '</optgroup><optgroup label="➕ Make a new one"><option value="__new">➕ New message or offer…</option></optgroup>';
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
      return (D.hist && D.hist.length ? '<div class="wj-card"><button class="wj-b s" type="button" data-undo title="' + esc(D.hist[0].why) + '">↩ Undo the last change Claude made</button></div>' : '') + '<div class="wj-card"><div class="wj-h">🧠 Talk to Claude about the whole journey</div><div class="wj-sub">Say it in your own words (Hindi / Gujarati / English) — e.g. “Diwali ke baad New Year ka theme lagao”, “add a day 14 message for gift customers”, “make Offer 2 a buy-2-get-1”, “remove the last-note on day 60”. '
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
      let h = changeCard() + '<div class="wj-card"><div class="wj-h">🎁 Messages & offers — one registry<span class="sp"></span><button class="wj-b s" type="button" data-tpl>Send all waiting to Meta</button></div><div class="wj-sub">Every journey day (and every Follow-up offer day) points to one entry here. Change an entry and every day that uses it changes. Each entry is ONE message Meta approves once; '
        + '<b>{{1}}</b> = the customer’s name, <b>{{2}}</b> = their perfume, <b>{{3}}</b> = the line Claude writes for each customer from your rule — it never goes below your price chart. Changing the words sends a new version to Meta.</div></div>';
      h += D.messages.map((m) => '<div class="wj-msg" data-mk="' + esc(m.k) + '"><div class="wj-row2"><b>' + m.icon + '</b><input class="wj-in" data-mf="label" style="flex:1;min-width:140px" value="' + esc(m.label) + '" maxlength="40">' + tplPill(m.status) + '<span class="wj-pill">' + m.uses + ' day(s) use it</span>' + (m.uses || ['o1', 'o2', 'o3', 'mega'].includes(m.k) ? '' : '<button class="wj-b s r" type="button" data-mdel="' + esc(m.k) + '">Delete</button>') + '</div>'
        + '<label class="wj-sub">Rule — what Claude puts in {{3}} for each customer</label><textarea class="wj-ta" data-mf="rule" style="min-height:52px" maxlength="300">' + esc(m.rule) + '</textarea><label class="wj-sub">Approved message words</label><textarea class="wj-ta" data-mf="body" style="min-height:60px" maxlength="900">' + esc(m.body) + '</textarea></div>').join('');
      return h + '<div class="wj-card"><div class="wj-row2"><button class="wj-b p" type="button" id="mSave">Save all messages</button><button class="wj-b" type="button" id="mNew">＋ New offer or message</button></div><div id="mNewBox"></div></div>';
    }

    // ✏️ say anything → every related message with a suggestion beside it → edit / photo → Apply
    function changeCard() {
      let h = '<div class="wj-card"><div class="wj-h">✏️ Change many messages at once</div><div class="wj-sub">Tell Claude anything — a festival, “20% extra on every offer”, a new perfume, a softer tone. It finds <b>every related message</b> in this library and suggests the change beside each one. Edit it, add a photo if you like, then press Apply. <b>Nothing changes before you press Apply.</b></div>'
        + '<textarea class="wj-ta" id="chT" style="min-height:60px;margin-top:8px" maxlength="2000" placeholder="e.g. Diwali is coming — make all offers festive.   or:   give 20% extra discount in every offer"></textarea>'
        + '<div class="wj-row2" style="margin-top:6px"><button class="wj-b p" type="button" id="chGo">🔎 Find related messages</button>' + (D.can_undo_change ? '<button class="wj-b" type="button" id="chUndo">↩ Undo the last change</button>' : '') + '</div></div>';
      if (CHG && (CHG.rows || []).length) {
        h += '<div class="wj-card"><div class="wj-h">📝 ' + CHG.rows.length + ' related message(s)</div><div class="wj-sub">' + esc(CHG.understood || '') + '</div>';
        h += CHG.rows.map((r, i) => '<div class="wj-msg" data-ci="' + i + '"><div class="wj-row2"><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-cf="on"' + (r.on ? ' checked' : '') + (r.blocked ? ' disabled' : '') + '> <b>' + esc(r.label) + '</b></label>' + ((r.used || []).length ? '<span class="wj-pill">used: ' + esc(r.used.join(', ')) + '</span>' : '<span class="wj-pill">not used yet</span>') + '</div>'
          + '<div class="wj-sub">💡 ' + esc(r.why || '') + '</div>' + (r.blocked ? '<div class="wj-err">⛔ ' + esc(r.blocked) + '</div>' : '')
          + '<label class="wj-sub">Rule (was: ' + esc(r.old_rule) + ')</label><textarea class="wj-ta" data-cf="rule" style="min-height:48px" maxlength="300">' + esc(r.rule) + '</textarea>'
          + '<label class="wj-sub">Message words</label><textarea class="wj-ta" data-cf="body" style="min-height:64px" maxlength="900">' + esc(r.body) + '</textarea>'
          + '<div class="wj-row2"><button class="wj-b s" type="button" data-cphoto="' + i + '">📷 ' + (r.photo ? 'Change photo' : 'Add a photo') + '</button>' + (r.photo ? '<img data-cth="' + r.photo + '" alt="" style="height:44px;border-radius:6px"><button class="wj-b s r" type="button" data-cnophoto="' + i + '">Remove photo</button>' : '') + '</div></div>').join('');
        h += '<div class="wj-row2" style="margin-top:10px"><button class="wj-b p" type="button" id="chApply">✅ Apply ticked messages</button><button class="wj-b" type="button" id="chDrop">Drop all</button></div><div class="wj-sub">Changed words or a photo go to Meta as a new version; the old approved message keeps working until the new one is approved. Nothing is sent to customers by this.</div></div>';
      }
      return h;
    }
    const thumbCache = {};
    async function thumbInto(img, id) { try { if (!thumbCache[id]) { const r = await fetch(api.buildUrl('wa.lib_file', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) return; thumbCache[id] = URL.createObjectURL(await r.blob()); } img.src = thumbCache[id]; } catch (e) { /* no preview */ } }
    async function pickPhoto(done) {
      const o = document.createElement('div'); o.style.cssText = 'position:fixed;inset:0;z-index:300;background:#0008;display:flex;align-items:flex-end;justify-content:center';
      o.innerHTML = '<div class="wj" style="background:#fff;width:100%;max-width:640px;border-radius:18px 18px 0 0;padding:16px;max-height:90vh;overflow:auto"><div class="wj-h">📷 Choose a photo<span class="sp"></span><button class="wj-b s" type="button" id="phX">Close</button></div><div class="wj-sub">From your Media library. Upload new ones in Media → Library.</div><div id="phG" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px;margin-top:8px">Loading…</div></div>';
      document.body.appendChild(o); o.querySelector('#phX').onclick = () => { o.remove(); }; o.onclick = (e) => { if (e.target === o) o.remove(); };
      try { const items = ((await api.get('wa.lib_list')).items || []).filter((x) => x.kind === 'image'); const g = o.querySelector('#phG');
        g.innerHTML = items.length ? items.map((x) => '<button type="button" data-pid="' + x.id + '" style="border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:4px;cursor:pointer"><img data-th="' + x.id + '" alt="" style="width:100%;height:80px;object-fit:cover;border-radius:6px"><div style="font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(x.name || x.title || ('#' + x.id)) + '</div></button>').join('') : '<div class="wj-sub">No photos in the Media library yet.</div>';
        g.querySelectorAll('[data-th]').forEach((im) => thumbInto(im, im.dataset.th)); g.querySelectorAll('[data-pid]').forEach((b) => { b.onclick = () => { o.remove(); done(+b.dataset.pid); }; });
      } catch (e) { o.querySelector('#phG').textContent = e.message || 'Could not load the library.'; }
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
    function draw() {
      if (!D) return; const scroll = el.scrollTop;
      el.innerHTML = '<div class="wj">' + (tab === 'claude' ? claudeTab() : tab === 'topics' ? topicsTab() : tab === 'msgs' ? msgsTab() : tab === 'fest' ? festTab() : '') + '</div>'; bind(); el.scrollTop = scroll;
    }
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
      const un = $('[data-undo]'); if (un) un.onclick = async () => { if (confirm('Undo the last change (' + D.hist[0].why + ')?')) await act('fu.wj_undo'); };
      $$('[data-tpl]').forEach((b) => b.onclick = () => act('fu.wj_tpl', {}, 'keep'));
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

      const chT = $('#chT'); if (chT) { const go = $('#chGo'); go.onclick = async () => { const text = chT.value.trim(); if (!text) { toast('Write what you want to change.', true); return; } go.disabled = true; CHG = null; await act('fu.wj_change', { text }, 'keep'); };
        const un = $('#chUndo'); if (un) un.onclick = async () => { if (confirm('Undo the last applied change?')) await act('fu.wj_change_undo', {}, 'keep'); };
        const dr = $('#chDrop'); if (dr) dr.onclick = async () => { CHG = null; await act('fu.wj_change_apply', { discard: 1 }, 'keep'); };
        const ap = $('#chApply'); if (ap) ap.onclick = async () => { ap.disabled = true; const rows = CHG.rows.map((r) => ({ key: r.key, label: r.label, rule: r.rule, body: r.body, photo: r.photo || 0, on: r.on })); CHG = null; await act('fu.wj_change_apply', { rows }, 'keep'); };
        $$('[data-ci]').forEach((box) => { const r = CHG.rows[+box.dataset.ci]; box.querySelectorAll('[data-cf]').forEach((f) => { f.oninput = f.onchange = () => { const k = f.dataset.cf; r[k] = k === 'on' ? f.checked : f.value; }; }); });
        $$('[data-cphoto]').forEach((b) => b.onclick = () => pickPhoto((id) => { CHG.rows[+b.dataset.cphoto].photo = id; draw(); }));
        $$('[data-cnophoto]').forEach((b) => b.onclick = () => { CHG.rows[+b.dataset.cnophoto].photo = 0; draw(); });
        $$('[data-cth]').forEach((im) => thumbInto(im, im.dataset.cth)); }
      const mn = $('#mNew'); if (mn) mn.onclick = () => { $('#mNewBox').innerHTML = '<div class="wj-msg" style="margin-top:10px"><input class="wj-in f" id="nNm" placeholder="Name, e.g. Buy 2 get 1" maxlength="40"><textarea class="wj-ta" id="nRule" style="min-height:52px" placeholder="Rule — what Claude puts in {{3}} for each customer, e.g. Buy two perfumes and get a third 10 ml free"></textarea><div class="wj-row2"><button class="wj-b p s" type="button" id="nGo">Create</button><span class="wj-sub">Claude writes the approved wording and sends it to Meta.</span></div></div>';
        $('#nGo').onclick = async () => { const nm = $('#nNm').value.trim(), rule = $('#nRule').value.trim(); if (!nm || !rule) { toast('Give it a name and a rule.', true); return; } await ask('Create a new registry entry named "' + nm + '" with this rule: ' + rule + '. Write the approved message words for it (key starting o_ if it is an offer, w_ if it is only a message). Do not add it to the journey yet unless I ask.', false); }; };
      // festivals
      $$('[data-fest]').forEach((b) => b.onclick = async () => { const a = b.dataset.fest; const body = { act: a }; if (a === 'yes') { body.text = $('#fsText').value; body.from = $('#fsFrom').value; body.to = $('#fsTo').value; } await act('fu.wj_fest', body, 'keep'); });
      const ss = $('#seSave'); if (ss) ss.onclick = async () => { const r = await call(() => api.post('fu.ap_season_save', { on: $('#seOn').checked, text: $('#seText').value, from: $('#seFrom').value, to: $('#seTo').value })); if (r) load(); };
      const fa = $('#faAdd'); if (fa) fa.onclick = () => act('fu.wj_fest', { act: 'add', name: $('#faName').value, date: $('#faDate').value, theme: $('#faTheme').value }, 'keep');
      $$('[data-fdel]').forEach((b) => b.onclick = () => { const [n, d] = b.dataset.fdel.split('|'); act('fu.wj_fest', { act: 'del', name: n, date: d }, 'keep'); });
    }
    load(); return load;
  }
  WaPanels.add('mymsgs', (el) => mount(el, 'msgs'));
  WaPanels.add('wjclaude', (el) => mount(el, 'claude')); WaPanels.add('wjtopics', (el) => mount(el, 'topics')); WaPanels.add('wjfest', (el) => mount(el, 'fest'));   // the Journey's own screens, as tabs of the one Journey section   // Messages → My messages: the same registry, on its own
})();
