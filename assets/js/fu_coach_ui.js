/**
 * fu_coach_ui.js — 🔁 Learning & Improvement → Follow-ups. window.FuCoach.mount(el, {onMode})
 *   1. Tell Claude: one general summary (or a correction) → Claude builds the WHOLE plan and answers "what I understood" + questions. Repeat until it is right.
 *   2. The proposal: every situation as a timeline (5 min ● 30 min ● 4 h ┃24 h┃ 📣 26 h …), pick which to keep, "Save the plan".
 *   3. The live plan: the same timelines with numbers per step (sent / replied, 30 days), edit a step, tell Claude about one step or one situation,
 *      template status after 24 h (send to Meta), pause / delete a situation, "simulate" (the messages a quiet customer would get).
 *   Mode (Off / Suggest only / Auto inside 24 h / Auto + templates) and the follow-up limits sit on top.
 * Server: wa_fu_coach.php (fu.coach_*) + wa_follow.php (fu.mode, fu.sit_save, fu.step_remark, fu.sit_remark, fu.sit_toggle, fu.sit_del, fu.sim).
 */
(function () {
  'use strict';
  if (window.FuCoach) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => Number(n || 0).toLocaleString('en-IN');
  const CSS = `
.fc{--ln:#e6e8ef;--mut:#64748b;--ink:#0f172a;--g:#059669;--gb:#ecfdf5;--p:#5b3fd6;--pb:#f4f1ff;display:grid;grid-template-columns:minmax(0,1fr);gap:14px;max-width:100%;font-size:14px;color:var(--ink)}
.fc>*,.fc-sit,.fc-chat{min-width:0}.fc-tl{max-width:100%}.fc-me,.fc-cl{overflow-wrap:anywhere}
.fc-card{background:#fff;border:1px solid var(--ln);border-radius:14px;padding:14px 16px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.fc-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 8px;font-size:15px;font-weight:700}.fc-h .sp{flex:1}
.fc-sub{color:var(--mut);font-size:12.5px;line-height:1.45}
.fc-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--ln);background:#fff;border-radius:9px;padding:7px 12px;font:inherit;font-size:13px;font-weight:600;color:#1f2937;cursor:pointer}
.fc-btn:hover{background:#f8fafc}.fc-btn.p{background:var(--g);border-color:var(--g);color:#fff}.fc-btn.v{background:var(--p);border-color:var(--p);color:#fff}.fc-btn.rec{background:#dc2626;border-color:#dc2626;color:#fff;animation:fcpulse 1.3s infinite}@keyframes fcpulse{50%{opacity:.72}}.fc-btn.r{color:#b91c1c}.fc-btn.s{padding:5px 9px;font-size:12px}.fc-btn:disabled{opacity:.55;cursor:default}
.fc-modes{display:flex;gap:4px;flex-wrap:wrap;background:#f1f5f9;border-radius:10px;padding:3px}.fc-modes button{border:0;background:none;border-radius:8px;padding:7px 11px;font:inherit;font-size:12.5px;font-weight:600;color:#475569;cursor:pointer}.fc-modes button.on{background:#fff;color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.08)}
.fc-lim{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.fc-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;background:#f1f5f9;color:#334155}
.fc-pill.g{background:var(--gb);color:#047857}.fc-pill.w{background:#fffbeb;color:#92400e}.fc-pill.r{background:#fef2f2;color:#b91c1c}.fc-pill.p{background:var(--pb);color:var(--p)}.fc-pill.b{background:#eff6ff;color:#1d4ed8}
.fc-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:9px 12px;font-size:12.5px;line-height:1.5}
.fc-chat{display:grid;gap:10px;max-height:520px;overflow:auto;padding:2px}
.fc-me{justify-self:end;max-width:86%;background:#dcfce7;border-radius:12px 12px 4px 12px;padding:9px 12px;white-space:pre-wrap;line-height:1.45}
.fc-cl{max-width:94%;background:var(--pb);border:1px solid #e4dcff;border-radius:12px 12px 12px 4px;padding:10px 13px;line-height:1.5}
.fc-cl b.t{display:block;color:var(--p);margin-bottom:4px}.fc-cl ul{margin:4px 0 0;padding-left:18px}.fc-cl li{margin:2px 0}
.fc-q{display:flex;gap:6px;align-items:flex-start;margin-top:4px}.fc-q button{flex:none}
.fc-sys{justify-self:center;font-size:12px;color:var(--mut);background:#f8fafc;border-radius:999px;padding:3px 10px}
.fc-in{border:1px solid var(--ln);border-radius:10px;padding:10px 12px;width:100%;box-sizing:border-box;font:inherit;font-size:14px;line-height:1.45;resize:vertical;min-height:84px;background:#fff}
.fc-in.s{min-height:0;padding:7px 9px;font-size:13px}
.fc-ex{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}.fc-ex button{border:1px dashed #c4b5fd;background:#fff;color:var(--p);border-radius:999px;padding:5px 10px;font:inherit;font-size:12px;cursor:pointer}
.fc-sit{border:1px solid var(--ln);border-radius:12px;padding:12px;margin-top:10px;background:#fff}.fc-sit.off{opacity:.6}.fc-sit.stop{background:#fef2f2;border-color:#fecaca}
.fc-sit-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.fc-sit-h b{font-size:14px}.fc-sit-h .sp{flex:1}
.fc-tl{display:flex;gap:8px;overflow-x:auto;padding:10px 2px 4px;scroll-snap-type:x proximity}
.fc-st{flex:0 0 230px;scroll-snap-align:start;border:1px solid var(--ln);border-radius:11px;padding:9px 10px;background:#fbfcfe;cursor:pointer;position:relative}
.fc-st:hover{border-color:#a5b4fc}.fc-st.tpl{background:#fffaf3;border-color:#fde3c3}.fc-st.ed{flex-basis:300px;cursor:default;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12);background:#fff}
.fc-st .tm{display:flex;align-items:center;gap:6px;font-weight:700;font-size:12.5px}.fc-st .tm .sp{flex:1}
.fc-st .tx{font-size:12.5px;color:#334155;line-height:1.45;margin-top:5px;max-height:92px;overflow:hidden;white-space:pre-wrap}
.fc-st .nm{font-size:11.5px;color:var(--mut);margin-top:6px}
.fc-24{flex:0 0 auto;display:flex;align-items:center;writing-mode:vertical-rl;transform:rotate(180deg);font-size:11px;font-weight:700;color:#b45309;border-left:2px dashed #f59e0b;padding:0 4px;letter-spacing:.04em}
.fc-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px}
.fc-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 16px;border-radius:999px;font-size:13px;z-index:9999;max-width:90vw}
.fc-toast.bad{background:#b91c1c}
.fc-busy{display:flex;align-items:center;gap:8px;color:var(--p);font-weight:600;font-size:13px;padding:6px 0}.fc-dot{width:8px;height:8px;border-radius:50%;background:var(--p);animation:fcb 1s infinite alternate}
@keyframes fcb{from{opacity:.25}to{opacity:1}}
.fc-mod{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9000;display:flex;align-items:center;justify-content:center;padding:14px}.fc-mod>div{background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:86vh;overflow:auto;padding:16px}
`;
  const ICON = { key: '🔑' };
  let S = { d: null, err: '', busy: '', text: '', ed: null, keep: null, el: null, opts: {}, sim: null };
  function toast(m, bad) { const t = document.createElement('div'); t.className = 'fc-toast' + (bad ? ' bad' : ''); t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), bad ? 5000 : 2600); }
  const span = (min) => { min = Math.round(min); if (min < 60) return min + ' min'; if (min < 1440) { const h = Math.floor(min / 60), m = min % 60; return h + ' h' + (m ? ' ' + m + ' min' : ''); } const d = Math.floor(min / 1440), h = Math.round((min % 1440) / 60); return d + ' day' + (d > 1 ? 's' : '') + (h ? ' ' + h + ' h' : ''); };
  const MODES = [['off', 'Off', 'Nothing is sent.'], ['suggest', 'Suggest only', 'The message is prepared in the chat; a salesperson taps to send.'], ['auto24', 'Auto inside 24 h', 'Sends free messages by itself inside 24 hours. After that it only suggests.'], ['autotpl', 'Auto + templates', 'Also sends the approved templates after 24 hours.']];
  const EXAMPLES = ['If they ask the price and go quiet: nudge after 5 min, then send best sellers list after 2 hours, a last reminder next day with a template.', 'Price agreed but no payment: payment link reminder at 30 min and 4 hours, then a template the next day and a last call on day 3.', 'If they say they will ask family: check back after 6 hours, then a soft template after 2 days. Max 2 messages a day.'];
  async function call(fn) { try { return await fn(); } catch (e) { toast(e.message || 'Something went wrong', true); return null; } }
  async function load() { const r = await call(() => api.get('fu.coach_get')); if (r) { S.d = r; S.err = ''; } else S.err = 'Could not load the follow-ups.'; draw(); }
  function mount(el, opts) {
    if (!document.getElementById('fcCss')) { const s = document.createElement('style'); s.id = 'fcCss'; s.textContent = CSS; document.head.appendChild(s); }
    S.el = el; S.opts = opts || {}; draw(); if (!S.d) load();
  }
  // ---------------------------------------------------------------------------------------------------------- drawing
  function stepCard(sit, x, i, live) {
    const min = Math.round(x.after_h * 60), tpl = x.ch === 'tpl'; const ed = S.ed && S.ed.sid === sit.id && S.ed.n === i;
    if (ed) return edCard(sit, x, i);
    const st = !live ? '' : tpl ? (x.tpl_status === 'APPROVED' ? '<span class="fc-pill g">Approved</span>' : x.tpl_status === 'REJECTED' ? '<span class="fc-pill r">Rejected</span>' : +x.tpl_id ? '<span class="fc-pill w">At Meta</span>' : '<span class="fc-pill">Not sent to Meta</span>') : '';
    const words = tpl ? (x.tpl_body || x.template || x.text) : x.text;
    return `<div class="fc-st ${tpl ? 'tpl' : ''}" ${live ? `data-ed="${esc(sit.id)}|${i}"` : ''}><div class="tm">${tpl ? '📣' : '💬'} ${span(min)}<span class="sp"></span>${tpl ? `<span class="fc-pill" style="font-size:11px">${esc((x.tpl_cat || x.category || 'MARKETING').toLowerCase())}</span>` : ''}</div>
      <div class="tx">${esc(words)}</div>${x.why ? `<div class="nm">💡 ${esc(x.why)}</div>` : ''}${(x.warn || []).length ? `<div class="nm" style="color:#b91c1c">⚠ ${esc(x.warn.join(' · '))}</div>` : ''}
      ${live ? `<div class="nm">${st} ${x.sent ? fmt(x.sent) + ' sent · ' + (x.sent ? Math.round((x.replied || 0) * 100 / x.sent) : 0) + '% replied' : 'not sent yet'}</div>` : ''}</div>`;
  }
  function edCard(sit, x, i) {
    const e = S.ed; const tpl = (e.min >= 1410);
    return `<div class="fc-st ed ${tpl ? 'tpl' : ''}"><div class="tm">✏️ Step ${i + 1}<span class="sp"></span><button type="button" class="fc-btn s" data-a="ed_x">✕</button></div>
      <div class="fc-row"><input class="fc-in s" type="number" min="1" id="fcEv" value="${e.v}" style="width:80px"><select class="fc-in s" id="fcEu" style="width:auto">${[['min', 'minutes'], ['h', 'hours'], ['d', 'days']].map(([k, l]) => `<option value="${k}" ${e.u === k ? 'selected' : ''}>${l}</option>`).join('')}</select><span class="fc-sub">after our last message</span></div>
      ${tpl ? '<div class="fc-sub" style="margin-top:6px">After 24 h only a <b>template</b> can be sent. Changing the words means a new approval from Meta.</div>' : ''}
      <textarea class="fc-in s" id="fcEt" rows="4" style="margin-top:6px">${esc(tpl ? (e.tpl || e.text) : e.text)}</textarea>
      ${tpl ? `<div class="fc-row"><span class="fc-sub">Type</span><select class="fc-in s" id="fcEc" style="width:auto">${['MARKETING', 'UTILITY'].map((c) => `<option ${e.cat === c ? 'selected' : ''}>${c}</option>`).join('')}</select></div>` : ''}
      <div class="fc-sub" style="margin-top:6px">{name} = first name${tpl ? ' ({{1}} in the template)' : ''}</div>
      <div class="fc-row"><input class="fc-in s" id="fcEr" placeholder="✨ Tell Claude: softer, add urgency, mention COD…" style="flex:1;min-width:150px"><button type="button" class="fc-btn s v" data-a="ed_ai">Rewrite</button></div>
      <div class="fc-row"><button type="button" class="fc-btn s p" data-a="ed_save">Save step</button><button type="button" class="fc-btn s r" data-a="ed_del">Delete step</button></div></div>`;
  }
  function timeline(sit, live) {
    if (sit.kind === 'stop' || sit.stop) return '<div class="fc-sub" style="margin-top:6px">🛑 No follow-ups at all — the engine stops by itself.</div>';
    const steps = sit.steps || []; let h = '', crossed = false;
    steps.forEach((x, i) => { const min = live ? Math.round(x.after_h * 60) : x.after_min; if (!live) x.after_h = min / 60; if (!crossed && min >= 1410) { crossed = true; h += '<div class="fc-24">24 h — templates only</div>'; } h += stepCard(sit, x, i, live); });
    if (!crossed) h += '<div class="fc-24" style="opacity:.5">24 h</div>';
    if (live) h += `<div class="fc-st" data-a="addstep" style="flex-basis:120px;display:grid;place-items:center;border-style:dashed;color:#64748b">＋ Step</div>`;
    return `<div class="fc-tl">${h}</div>`;
  }
  function draw() {
    const el = S.el; if (!el || !el.isConnected) return; const d = S.d;
    if (!d) { el.innerHTML = `<div class="fc"><div class="fc-card">${S.err ? esc(S.err) + ' <button type="button" class="fc-btn s" data-a="reload">Try again</button>' : 'Loading follow-ups…'}</div></div>`; wire(); return; }
    const L = d.limits, P = d.plan, live = (P.situations || []).filter((s) => s.stage === 'saved'), pr = d.proposal;
    let h = `<div class="fc">
      <div class="fc-card"><div class="fc-h">🔁 Follow-ups <span class="fc-sub" style="font-weight:500">— what happens when a customer goes quiet after our reply</span></div>
        <div class="fc-modes">${MODES.map(([k, l]) => `<button type="button" data-mode="${k}" class="${d.mode === k ? 'on' : ''}">${l}</button>`).join('')}</div>
        <div class="fc-sub" style="margin-top:6px">${esc((MODES.find((m) => m[0] === d.mode) || MODES[0])[2])}</div>
        <div class="fc-lim"><span class="fc-pill">Max ${L.max} per quiet customer</span><span class="fc-pill">Max ${L.day_max} a day</span><span class="fc-pill">At least ${L.gap_min} min apart*</span><span class="fc-pill">Quiet ${esc(L.quiet_from)} – ${esc(L.quiet_to)}</span><button type="button" class="fc-btn s" data-a="limits">Edit limits</button></div>
        <div class="fc-sub" style="margin-top:4px">* a plan's own shorter timing (e.g. 5 min → 10 min) is followed. The engine stops by itself when the customer orders, opts out, sounds angry or a salesperson takes over.</div>
        ${(d.others || []).length ? `<div class="fc-warn" style="margin-top:10px">Also messaging quiet customers: ${d.others.map((o) => `<b>${esc(o.what)}</b> (${o.n}, ${esc(o.state)})`).join(' · ')}. Keep one engine: switch those off in WhatsApp → Autobot.</div>` : ''}</div>
      <div class="fc-card"><div class="fc-h">✨ Tell Claude <span class="sp"></span>${(d.turns || []).length ? '<button type="button" class="fc-btn s" data-a="clear">Start again</button>' : ''}</div>
        <div class="fc-sub">Write in your own words what should happen when customers go quiet — timings, how many messages, the tone, when to stop. Claude reads everything you taught (selling rules, Price chart, Lists, limits), builds the whole plan, and tells you what it understood. Correct it until it is right, then save.</div>
        <div class="fc-chat" id="fcChat" style="margin-top:10px">${(d.turns || []).map(turn).join('')}${S.busy === 'ask' ? '<div class="fc-busy"><span class="fc-dot"></span>Claude is building your plan… (up to a minute)</div>' : ''}</div>
        ${!(d.turns || []).length ? `<div class="fc-ex">${EXAMPLES.map((x, i) => `<button type="button" data-ex="${i}">${esc(x.slice(0, 60))}…</button>`).join('')}</div>` : ''}
        <textarea class="fc-in" id="fcT" placeholder="${(d.turns || []).length ? 'Correct or add something — e.g. “payment reminder only twice”, “no templates for the first situation”…' : 'e.g. If they ask the price and go quiet, nudge after 5 min, then send the best sellers list after 2 hours…'}" style="margin-top:8px">${esc(S.text)}</textarea>
        <div class="fc-row"><button type="button" class="fc-btn v" data-a="ask" ${S.busy ? 'disabled' : ''}>✨ ${(d.turns || []).length ? 'Send to Claude' : 'Build my follow-up plan'}</button>${('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) ? '<button type="button" class="fc-btn' + (S.mic ? ' rec' : '') + '" data-a="mic">' + (S.mic ? '⏹ Stop recording' : '🎙 Start recording') + '</button>' : ''}</div></div>`;
    if (pr) {
      S.keep = S.keep || Object.fromEntries(pr.situations.map((s) => [s.key, true]));
      h += `<div class="fc-card" style="border-color:#c4b5fd"><div class="fc-h">📋 Claude's plan <span class="fc-pill p">not saved yet</span><span class="sp"></span><button type="button" class="fc-btn p" data-a="apply" ${S.busy ? 'disabled' : ''}>Save the plan</button></div>
        <div class="fc-sub">Untick a situation to keep your current version of it. Saving replaces the live plan (Undo is in History).</div>
        <div class="fc-lim"><span class="fc-pill p">Suggested limits: max ${pr.limits.max} · ${pr.limits.day_max} a day · ${pr.limits.gap_min} min apart · quiet ${esc(pr.limits.quiet_from)}–${esc(pr.limits.quiet_to)}</span>${pr.limits.why ? `<span class="fc-sub">${esc(pr.limits.why)}</span>` : ''}</div>
        ${pr.situations.map((s) => `<div class="fc-sit ${s.stop ? 'stop' : ''}"><div class="fc-sit-h"><input type="checkbox" data-keep="${esc(s.key)}" ${S.keep[s.key] !== false ? 'checked' : ''}><b>${esc(s.title)}</b>${s.stop ? '' : `<span class="fc-pill">${s.steps.length} step${s.steps.length === 1 ? '' : 's'}</span>`}<span class="sp"></span><span class="fc-pill">priority ${s.priority}</span></div><div class="fc-sub">${esc(s.when)}</div>${timeline(s, false)}</div>`).join('')}</div>`;
    }
    const needTpl = live.some((s) => s.kind === 'nudge' && (s.steps || []).some((x) => x.ch === 'tpl' && !+x.tpl_id));
    h += `<div class="fc-card"><div class="fc-h">🟢 Last 30 days automation <span class="fc-pill">${live.length} situation${live.length === 1 ? '' : 's'}</span><span class="sp"></span>${needTpl ? '<button type="button" class="fc-btn" data-a="tplall">📣 Send all templates to Meta</button>' : ''}</div>
      ${live.length ? '<div class="fc-sub">Tap a step to change its time or words. Numbers are the last 30 days.</div>' + live.map((s) => `<div class="fc-sit ${s.on ? '' : 'off'} ${s.kind === 'stop' ? 'stop' : ''}" data-sid="${esc(s.id)}"><div class="fc-sit-h"><b>${esc(s.title)}</b>${s.on ? '<span class="fc-pill g">On</span>' : '<span class="fc-pill">Paused</span>'}${s.kind === 'nudge' && s.stats ? `<span class="fc-pill">${fmt(s.stats.sent)} sent · ${s.stats.sent ? Math.round(s.stats.replied * 100 / s.stats.sent) : 0}% replied</span>` : ''}<span class="sp"></span>
          ${s.kind === 'nudge' ? `<button type="button" class="fc-btn s" data-a="sim">▶ Simulate</button><button type="button" class="fc-btn s" data-a="sitai">✨ Change</button>` : ''}<button type="button" class="fc-btn s" data-a="toggle">${s.on ? 'Pause' : 'Turn on'}</button><button type="button" class="fc-btn s r" data-a="del">Delete</button></div>
          <div class="fc-sub">${esc(s.desc)}</div>${timeline(s, true)}</div>`).join('') : '<div class="fc-sub">Nothing saved yet. Tell Claude above, check the plan, then press “Save the plan”.</div>'}</div>`;
    h += '</div>';
    el.innerHTML = h; wire();
    const ch = el.querySelector('#fcChat'); if (ch) ch.scrollTop = ch.scrollHeight;
  }
  function turn(t) {
    if (t.who === 'owner') return `<div class="fc-me">${esc(t.text)}</div>`;
    if (t.who === 'system') return `<div class="fc-sys">${esc(t.text)}</div>`;
    return `<div class="fc-cl"><b class="t">✨ What I understood</b>${t.changed ? `<div class="fc-sub" style="margin-bottom:4px">Changed: ${esc(t.changed)}</div>` : ''}<ul>${(t.understood || []).map((x) => `<li>${esc(x)}</li>`).join('')}</ul>
      ${(t.questions || []).length ? `<b class="t" style="margin-top:8px">❓ Not sure — please tell me</b>${t.questions.map((q) => `<div class="fc-q"><span style="flex:1">${esc(q)}</span><button type="button" class="fc-btn s" data-ans="${esc(q)}">Answer</button></div>`).join('')}` : ''}</div>`;
  }
  // ---------------------------------------------------------------------------------------------------------- actions
  const sitOf = (id) => ((S.d && S.d.plan.situations) || []).find((s) => s.id === id);
  const stepsPayload = (s) => (s.steps || []).map((x) => ({ after_h: x.after_h, text: x.text, remark: x.remark || '', ch: x.ch, tpl_body: x.tpl_body || '', tpl_cat: x.tpl_cat || 'MARKETING', tpl_id: +x.tpl_id || 0 }));
  async function saveSit(s) { const r = await call(() => api.post('fu.sit_save', { sid: s.id, steps: stepsPayload(s), remark_all: s.remark_all || '' })); if (r) { toast('Saved'); await load(); return true; } return false; }
  function wire() {
    const el = S.el; const q = (x) => el.querySelectorAll(x);
    const T = el.querySelector('#fcT'); if (T) T.oninput = () => { S.text = T.value; };
    q('[data-mode]').forEach((b) => { b.onclick = async () => { const m = b.dataset.mode; if (m === S.d.mode) return; if ((m === 'auto24' || m === 'autotpl') && !confirm('Send follow-ups by themselves from now on?')) return; let r = null;
        try { r = await api.post('fu.mode', { mode: m }); }
        catch (e) {      // "Save a plan first": if Claude's plan is only proposed, offer to save it and switch on in one go
          const prop = S.d && S.d.proposal && (S.d.proposal.situations || []).length;
          if (prop && /follow-up plan first/i.test((e && e.message) || '')) {
            if (!confirm('Claude’s plan is not saved yet. Save it now and switch this on?')) return;
            const ap = await call(() => api.post('fu.coach_apply', { keys: [] })); if (!ap) return; S.d = ap; S.keep = null;
            r = await call(() => api.post('fu.mode', { mode: m }));
          } else { toast((e && e.message) || 'Something went wrong', true); return; }
        }
        if (r) { S.d.mode = m; if (S.opts.onMode) S.opts.onMode(m); toast('Follow-ups: ' + (MODES.find((x) => x[0] === m) || [0, m])[1]); draw(); } }; });
    q('[data-ex]').forEach((b) => { b.onclick = () => { S.text = EXAMPLES[+b.dataset.ex]; draw(); const t = el.querySelector('#fcT'); if (t) t.focus(); }; });
    q('[data-ans]').forEach((b) => { b.onclick = () => { S.text = (S.text ? S.text + '\n' : '') + 'About “' + b.dataset.ans + '”: '; draw(); const t = el.querySelector('#fcT'); if (t) { t.focus(); t.setSelectionRange(t.value.length, t.value.length); } }; });
    q('[data-keep]').forEach((c) => { c.onchange = () => { S.keep[c.dataset.keep] = c.checked; }; });
    q('[data-ed]').forEach((c) => { c.onclick = () => { const [sid, n] = c.dataset.ed.split('|'); const s = sitOf(sid), x = s.steps[+n]; const min = Math.round(x.after_h * 60); const u = min % 1440 === 0 ? 'd' : min % 60 === 0 ? 'h' : 'min'; S.ed = { sid, n: +n, v: u === 'd' ? min / 1440 : u === 'h' ? min / 60 : min, u, min, text: x.text, tpl: x.tpl_body || '', cat: x.tpl_cat || 'MARKETING' }; draw(); }; });
    q('[data-a]').forEach((b) => { b.onclick = () => act(b.dataset.a, b); });
    const ev = el.querySelector('#fcEv'), eu = el.querySelector('#fcEu'); if (ev) { const upd = () => { const v = Math.max(1, +ev.value || 1); S.ed.v = v; S.ed.u = eu.value; const was = S.ed.min >= 1410; S.ed.min = eu.value === 'd' ? v * 1440 : eu.value === 'h' ? v * 60 : v; const et = el.querySelector('#fcEt'); if (et) { if (was) S.ed.tpl = et.value; else S.ed.text = et.value; } if ((S.ed.min >= 1410) !== was) draw(); }; ev.onchange = upd; eu.onchange = upd; }
    const et = el.querySelector('#fcEt'); if (et) et.oninput = () => { if (S.ed.min >= 1410) S.ed.tpl = et.value; else S.ed.text = et.value; };
  }
  async function act(a, b) {
    const d = S.d; const sitEl = b.closest('[data-sid]'); const sid = sitEl ? sitEl.dataset.sid : (S.ed ? S.ed.sid : ''); const s = sid ? sitOf(sid) : null;
    if (a === 'reload') return load();
    if (a === 'ask') { if (S.mic) { S.mic = false; try { if (S.rec) S.rec.stop(); } catch (e) { } S.rec = null; } const t = (S.text || '').trim(); if (!t) { toast('Write what you want first.', true); return; } S.busy = 'ask'; d.turns = (d.turns || []).concat([{ who: 'owner', text: t }]); S.text = ''; draw();
      const r = await call(() => api.post('fu.coach_ask', { text: t })); S.busy = ''; if (r) { S.d = r; S.keep = null; } else { S.text = t; d.turns.pop(); } draw(); return; }
    if (a === 'clear') { if (!confirm('Start the conversation again? Your saved plan stays.')) return; const r = await call(() => api.post('fu.coach_clear', {})); if (r) { S.d = r; S.keep = null; draw(); } return; }
    if (a === 'mic') {
      if (S.mic) { S.mic = false; try { if (S.rec) S.rec.stop(); } catch (e) { } S.rec = null; draw(); return; }
      const R = window.SpeechRecognition || window.webkitSpeechRecognition; if (!R) { toast('This browser cannot record. Type instead.', true); return; }
      const rec = new R(); S.rec = rec; S.mic = true; rec.lang = (function () { try { return localStorage.getItem('lx_lang') || 'hi-IN'; } catch (e) { return 'hi-IN'; } })(); rec.continuous = true; rec.interimResults = true;
      let base = S.text || '';
      rec.onresult = (ev) => { let fin = '', live = ''; for (let k = ev.resultIndex; k < ev.results.length; k++) { if (ev.results[k].isFinal) fin += ev.results[k][0].transcript + ' '; else live += ev.results[k][0].transcript; }
        if (fin.trim()) base = (base ? base.trimEnd() + ' ' : '') + fin.trim(); S.text = base + (live ? (base ? ' ' : '') + live : ''); const T = S.el.querySelector('#fcT'); if (T) { T.value = S.text; T.scrollTop = T.scrollHeight; } };
      rec.onerror = (ev) => { if (ev && (ev.error === 'not-allowed' || ev.error === 'service-not-allowed' || ev.error === 'audio-capture')) { toast('The microphone is blocked — allow it in the browser (🔒 in the address bar).', true); S.mic = false; S.rec = null; draw(); } };
      rec.onend = () => { if (S.mic && S.rec === rec) { try { rec.start(); } catch (e) { S.mic = false; S.rec = null; draw(); } } };      // a pause ended it: listen again until Stop is pressed
      try { rec.start(); draw(); } catch (e) { S.mic = false; S.rec = null; toast('Could not start recording.', true); draw(); } return; }
    if (a === 'apply') { const keys = Object.keys(S.keep || {}).filter((k) => S.keep[k]); if (!keys.length) { toast('Tick at least one situation.', true); return; } S.busy = 'apply'; b.disabled = true; b.textContent = 'Saving…';
      const r = await call(() => api.post('fu.coach_apply', { keys: keys.length === d.proposal.situations.length ? [] : keys })); S.busy = ''; if (r) { S.d = r; S.keep = null; toast('Saved ' + r.saved + ' situations as the live plan' + (r.mode === 'off' ? ' — follow-ups are Off: choose a mode on top' : ''));
        try { const t = await api.post('fu.coach_tpl', {}); if (t && t.sent) { S.d = t; toast('Saved · ' + t.sent + ' template' + (t.sent === 1 ? '' : 's') + ' sent to Meta for approval'); } } catch (e) { /* the button "Send templates to Meta" is still there */ } } draw(); return; }
    if (a === 'limits') return limitsBox();
    if (a === 'tplall') { b.disabled = true; b.textContent = 'Sending…'; const r = await call(() => api.post('fu.coach_tpl', {})); if (r) { S.d = r; toast(r.sent + ' template' + (r.sent === 1 ? '' : 's') + ' sent to Meta' + (r.errors.length ? ' · ' + r.errors[0] : ''), !!r.errors.length && !r.sent); } draw(); return; }
    if (!s) return;
    if (a === 'toggle') { const r = await call(() => api.post('fu.sit_toggle', { sid, on: !s.on })); if (r) load(); return; }
    if (a === 'del') { if (!confirm('Delete “' + s.title + '” and its steps?')) return; const r = await call(() => api.post('fu.sit_del', { sid })); if (r) load(); return; }
    if (a === 'addstep') { const last = s.steps[s.steps.length - 1]; s.steps.push({ after_h: last ? Math.min(720, last.after_h * 2 || 24) : 0.5, text: last ? last.text : 'Koi sawal ho to bataiye 😊', ch: '', tpl_body: '', tpl_cat: 'MARKETING', tpl_id: 0 }); s.steps.sort((x, y) => x.after_h - y.after_h); await saveSit(s); return; }
    if (a === 'sitai') { const t = prompt('Tell Claude what to change in “' + s.title + '” (all steps):', ''); if (!t) return; b.disabled = true; b.textContent = 'Thinking…'; const r = await call(() => api.post('fu.sit_remark', { sid, text: t, steps: stepsPayload(s) })); if (r && r.situation) { s.steps = r.situation.steps.map((x, i) => Object.assign({}, s.steps[i] || {}, { text: x.text, tpl_body: s.steps[i] && s.steps[i].ch === 'tpl' ? String(x.text).replace(/\{name\}/g, '{{1}}') : '', tpl_id: s.steps[i] && s.steps[i].ch === 'tpl' ? 0 : (s.steps[i] || {}).tpl_id })); s.remark_all = t; await saveSit(s); } else draw(); return; }
    if (a === 'sim') { const m = modalBox('▶ ' + s.title + ' — what a quiet customer would get', '<div class="fc-busy"><span class="fc-dot"></span>Writing the messages… (nothing is sent)</div>');
      const r = await call(() => api.post('fu.sim', { sid, all: 1 })); if (!r) { m.remove(); return; } m.querySelector('.bd').innerHTML = (r.steps || []).map((x) => `<div class="fc-st" style="cursor:default;margin-bottom:8px;flex:none"><div class="tm">${x.channel === 'template after 24 h' ? '📣' : '💬'} after ${span(x.at_h * 60)} <span class="sp"></span><span class="fc-sub">${esc(x.channel || x.note)}</span></div><div class="tx" style="max-height:none">${esc(x.text || x.note)}</div></div>`).join('') + (r.stopped ? `<div class="fc-sub">${esc(r.stopped)}</div>` : ''); return; }
    // step editor
    const e = S.ed; if (!e) return; const x = s.steps[e.n];
    if (a === 'ed_x') { S.ed = null; draw(); return; }
    if (a === 'ed_del') { if (!confirm('Delete step ' + (e.n + 1) + '?')) return; s.steps.splice(e.n, 1); S.ed = null; if (!s.steps.length) { toast('A situation needs at least one step — delete the situation instead.', true); load(); return; } await saveSit(s); return; }
    if (a === 'ed_save' || a === 'ed_ai') {
      const tplNow = e.min >= 1410; x.after_h = e.min / 60; x.ch = tplNow ? 'tpl' : 'free';
      if (tplNow) { const nb = (e.tpl || '').trim(); if (nb !== (x.tpl_body || '')) x.tpl_id = 0; x.tpl_body = nb; x.tpl_cat = (S.el.querySelector('#fcEc') || {}).value || e.cat; x.text = nb.replace(/\{\{1\}\}/g, '{name}') || x.text; } else x.text = (e.text || '').trim();
      if (a === 'ed_ai') { const rk = (S.el.querySelector('#fcEr') || {}).value || ''; if (!rk.trim()) { toast('Write what Claude should change.', true); return; } b.disabled = true; b.textContent = 'Thinking…';
        const r = await call(() => api.post('fu.step_remark', { sid, n: e.n + 1, remark: rk, steps: stepsPayload(s) })); if (!r || !r.situation) { draw(); return; }
        const nt = r.situation.steps[e.n] ? r.situation.steps[e.n].text : x.text; if (tplNow) { e.tpl = nt.replace(/\{name\}/g, '{{1}}'); } else e.text = nt; draw(); toast('Rewritten — check it, then Save step'); return; }
      if (!(x.text || '').trim()) { toast('The step needs words.', true); return; }
      s.steps.sort((p, q2) => p.after_h - q2.after_h); S.ed = null; await saveSit(s); return; }
  }
  function modalBox(title, html) { const m = document.createElement('div'); m.className = 'fc-mod'; m.innerHTML = `<div><div class="fc-h">${esc(title)}<span class="sp"></span><button type="button" class="fc-btn s">✕</button></div><div class="bd">${html}</div></div>`; m.querySelector('button').onclick = () => m.remove(); m.onclick = (e) => { if (e.target === m) m.remove(); }; document.body.appendChild(m); return m; }
  function limitsBox() {
    const L = S.d.limits; const m = modalBox('Follow-up limits', `<div class="fc-sub">These are for follow-ups only and win over the general limits on the Teach board.</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px"><label class="fc-sub">Max per quiet customer<input class="fc-in s" type="number" min="1" max="10" id="lmMax" value="${L.max}"></label><label class="fc-sub">Max a day<input class="fc-in s" type="number" min="1" max="10" id="lmDay" value="${L.day_max}"></label>
      <label class="fc-sub">At least … min apart<input class="fc-in s" type="number" min="1" max="1440" id="lmGap" value="${L.gap_min}"></label><span></span>
      <label class="fc-sub">Quiet from<input class="fc-in s" id="lmQf" value="${esc(L.quiet_from)}"></label><label class="fc-sub">Quiet until<input class="fc-in s" id="lmQt" value="${esc(L.quiet_to)}"></label></div>
      <div class="fc-row" style="margin-top:12px"><button type="button" class="fc-btn p" id="lmGo">Save limits</button></div>`);
    m.querySelector('#lmGo').onclick = async () => { const g = (id) => m.querySelector(id).value; const r = await call(() => api.post('fu.coach_limits', { max: +g('#lmMax'), day_max: +g('#lmDay'), gap_min: +g('#lmGap'), quiet_from: g('#lmQf'), quiet_to: g('#lmQt') })); if (r) { S.d = r; m.remove(); toast('Limits saved'); draw(); } };
  }
  window.FuCoach = { mount, reload: load };
})();
