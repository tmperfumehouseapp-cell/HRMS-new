/* Boss chat: "Ask Claude" card on the Summary page. Answers from live data, takes the boss to the right page, says what is missing or colliding,
   writes a ready-to-paste prompt when something new must be built, and proposes changes that run ONLY after the boss presses "Yes, do it".
   HQ_CHAT.mount(element, { onAct() })   Needs api.js and boss_common.js (BD). */
const HQ_CHAT = (() => {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SUG0 = ['What needs me today?', 'Who missed their jobs?', 'Which seats are empty?', 'Where do I see parcels?', 'Is anything colliding or missing?', 'What is missing in the pantry today?', 'Which places have no owner?'];   // the last two: registered places (Pantry ...)
  const SUG_DSP = ['Which parcels have no tracking number?', 'What is wrong in Dispatch?', 'Who has not dispatched?', 'How do I connect Shiprocket?', 'Compare last 7 days with last 30 days'];
  let cssDone = false;
  function css() {
    if (cssDone) return; cssDone = true;
    const s = document.createElement('style');
    s.textContent = [
      '.hc{background:var(--surface,#fff);border-radius:var(--radius,22px);padding:18px 20px;margin-bottom:14px;box-shadow:0 1px 2px rgba(20,22,26,.04),0 6px 18px -10px rgba(20,22,26,.12);}',
      '.hc-top{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:6px;} .hc-top h2{margin:0;font-size:18px;font-weight:800;letter-spacing:-.01em;} .hc-spark{width:38px;height:38px;border-radius:12px;background:#111827;color:#f2b33d;display:flex;align-items:center;justify-content:center;font-size:19px;flex:none;}',
      '.hc-log{max-height:430px;overflow-y:auto;display:flex;flex-direction:column;gap:12px;padding:6px 2px 10px;} .hc-log:empty{display:none;}',
      '.hc-m{max-width:92%;font-size:14.5px;line-height:1.5;border-radius:18px;padding:10px 14px;word-wrap:break-word;} .hc-m.u{align-self:flex-end;background:#111827;color:#fff;border-bottom-right-radius:6px;} .hc-m.a{align-self:flex-start;background:#f4f2ec;border-bottom-left-radius:6px;} .hc-m.a.off{background:#fff3dc;}',
      '.hc-m .lk{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;} .hc-gap{margin-top:10px;background:#fff8ea;border-radius:12px;padding:8px 12px;font-size:13px;color:#6b4a00;} .hc-gap b{display:block;margin-bottom:2px;} .hc-gap li{margin:2px 0 2px 16px;padding:0;}',
      '.hc-pr{margin-top:10px;background:#fff;border:1px dashed #b9bfca;border-radius:12px;padding:9px 12px;font-size:13px;white-space:pre-wrap;} .hc-pr .h{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#7a8499;margin-bottom:3px;display:flex;justify-content:space-between;align-items:center;}',
      '.hc-sub{font-size:12.5px;color:var(--muted,#6f7480);margin-top:4px;line-height:1.45;} .hc-act.pl .row .bd-btn{min-height:44px;flex:1;} .hc-act{margin-top:10px;background:#fff;border:1px solid #111827;border-radius:14px;padding:10px 12px;font-size:13.5px;} .hc-act .row{display:flex;gap:8px;margin-top:8px;} .hc-act.done{border-color:#16945a;background:#f1f9f4;} .hc-act.no{opacity:.55;border-color:#cfd3da;}',
      '.hc-in{display:flex;gap:8px;margin-top:8px;} .hc-in input{flex:1;min-width:0;min-height:46px;border:1px solid var(--line,#dfe3e8);border-radius:99px;padding:8px 18px;font:inherit;font-size:15px;background:#fff;} .hc-in input:focus{outline:2px solid #111827;outline-offset:0;}',
      '.hc-sg{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px;} .hc-sg button{border:1px solid #dfe3e8;background:#fff;border-radius:99px;padding:6px 13px;font:inherit;font-size:13px;cursor:pointer;} .hc-sg button:hover{background:#faf8f3;}',
      '.hc-dots span{display:inline-block;width:7px;height:7px;border-radius:50%;background:#9aa1b0;margin-right:4px;animation:hcb 1s infinite;} .hc-dots span:nth-child(2){animation-delay:.15s} .hc-dots span:nth-child(3){animation-delay:.3s} @keyframes hcb{0%,80%,100%{opacity:.25}40%{opacity:1}}'
    ].join('\n');
    document.head.appendChild(s);
  }
  function mount(el, opts) {
    css(); opts = opts || {};
    const SUG = opts.suggestions || (opts.focus === 'dispatch' ? SUG_DSP : SUG0), KEY = 'hq_chat_v1' + (opts.focus ? '_' + opts.focus : '');
    let hist = [], busy = false;
    try { const h = JSON.parse(sessionStorage.getItem(KEY) || '[]'); if (Array.isArray(h)) hist = h.slice(-30); } catch (e) {}
    const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(hist.slice(-30))); } catch (e) {} };
    el.innerHTML = '<div class="hc"><div class="hc-top"><div class="hc-spark">✦</div><div style="flex:1;min-width:200px"><h2>' + esc(opts.title || 'Ask Claude') + '</h2><div class="bd-sub">' + esc(opts.about || 'Your boss assistant. Ask anything about your sections, people, jobs and penalties. I will take you to the right page, tell you what is missing, and change things only after you say yes.') + '</div></div>'
      + '<button type="button" class="bd-btn sm" id="hcClear" style="display:none">New chat</button></div>'
      + '<div class="hc-log" id="hcLog"></div><div class="hc-sg" id="hcSg">' + SUG.map((s) => '<button type="button">' + esc(s) + '</button>').join('') + '</div>'
      + '<form class="hc-in" id="hcForm"><input id="hcIn" autocomplete="off" placeholder="Ask or tell Claude anything…" maxlength="1500"><button class="bd-btn pri" id="hcSend" type="submit">Send</button></form></div>';
    const log = el.querySelector('#hcLog'), inp = el.querySelector('#hcIn'), send = el.querySelector('#hcSend');

    function actHtml(a, i) {
      const sub = a.detail || (a.type === 'place_owner' ? 'Gives this place its own owner seat. Its photo task and its count and refill task move to that person. The office cleaners stay as they are.' : '');
      return '<div class="hc-act' + (sub ? ' pl' : '') + '" data-i="' + i + '"><b>Do this?</b><div>' + esc(a.label) + '</div>' + (sub ? '<div class="hc-sub">' + esc(sub) + '</div>' : '') + '<div class="row"><button type="button" class="bd-btn sm pri" data-yes="' + i + '">Yes, do it</button><button type="button" class="bd-btn sm" data-no="' + i + '">No</button></div></div>';
    }
    function botHtml(m, mi) {
      let h = '<div class="hc-m a ' + (m.ai === 'off' || m.ai === 'error' ? 'off' : '') + '">' + esc(m.text).replace(/\n/g, '<br>');
      if (m.gaps && m.gaps.length) h += '<div class="hc-gap"><b>Missing or colliding</b><ul style="margin:0;padding:0">' + m.gaps.map((g) => '<li>' + esc(g) + '</li>').join('') + '</ul></div>';
      if (m.prompt) h += '<div class="hc-pr"><div class="h"><span>Prompt for your builder - copy and send</span><button type="button" class="bd-btn sm" data-copy="' + mi + '">Copy</button></div>' + esc(m.prompt) + '</div>';
      if (m.actions && m.actions.length) h += m.actions.map((a, i) => actHtml(a, mi + '.' + i)).join('');
      if (m.links && m.links.length) h += '<div class="lk">' + m.links.map((l) => '<a class="bd-btn sm pri" href="' + esc(l.page) + '">Open ' + esc(l.label) + ' →</a>').join('') + '</div>';
      return h + '</div>';
    }
    function draw() {
      log.innerHTML = hist.map((m, i) => m.role === 'user' ? '<div class="hc-m u">' + esc(m.text) + '</div>' : botHtml(m, i)).join('');
      // actions already answered stay marked
      hist.forEach((m, mi) => (m.actions || []).forEach((a, i) => { if (a.state) mark(mi + '.' + i, a.state, a.msg); }));
      el.querySelector('#hcSg').style.display = hist.length ? 'none' : '';
      el.querySelector('#hcClear').style.display = hist.length ? '' : 'none';
      log.scrollTop = log.scrollHeight;
    }
    function mark(key, state, msg) {
      const n = el.querySelector('.hc-act[data-i="' + key + '"]'); if (!n) return;
      n.classList.add(state === 'done' ? 'done' : 'no'); n.querySelector('.row').innerHTML = '<span class="bd-sub">' + (state === 'done' ? '✓ ' + esc(msg || 'Done.') : 'Not done.') + '</span>';
    }
    async function ask(text) {
      text = (text || '').trim(); if (!text || busy) return; busy = true; send.disabled = true; inp.value = '';
      hist.push({ role: 'user', text }); draw();
      const w = document.createElement('div'); w.className = 'hc-m a'; w.innerHTML = '<span class="hc-dots"><span></span><span></span><span></span></span>'; log.appendChild(w); log.scrollTop = log.scrollHeight;
      try {
        const r = await api.post('dp.hq_chat', { focus: opts.focus || '', message: text, history: hist.slice(0, -1).map((m) => ({ role: m.role, text: m.text })) });
        hist.push({ role: 'assistant', text: r.reply, ai: r.ai, links: r.links || [], actions: r.actions || [], gaps: r.gaps || [], prompt: r.prompt || '' });
      } catch (e) { hist.push({ role: 'assistant', text: 'That did not work: ' + e.message, ai: 'error', links: [], actions: [], gaps: [], prompt: '' }); }
      save(); busy = false; send.disabled = false; draw(); inp.focus();
    }
    el.querySelector('#hcForm').onsubmit = (e) => { e.preventDefault(); ask(inp.value); };
    el.querySelector('#hcSg').onclick = (e) => { const b = e.target.closest('button'); if (b) ask(b.textContent); };
    el.querySelector('#hcClear').onclick = () => { hist = []; save(); draw(); };
    log.onclick = async (e) => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.copy) { const m = hist[Number(b.dataset.copy)]; try { await navigator.clipboard.writeText(m.prompt); BD.toast('Copied.'); } catch (er) { BD.toast('Select the text and copy it.', true); } return; }
      const key = b.dataset.yes || b.dataset.no; if (key == null) return;
      const [mi, i] = key.split('.').map(Number); const a = hist[mi].actions[i];
      if (b.dataset.no) { a.state = 'no'; save(); mark(key, 'no'); return; }
      b.disabled = true;
      try { const r = await api.post('dp.hq_act', { type: a.type, args: a.args }); a.state = 'done'; a.msg = r.message; save(); mark(key, 'done', r.message); BD.toast(r.message || 'Done.'); if (opts.onAct) opts.onAct(); }
      catch (er) { b.disabled = false; BD.toast(er.message, true); }
    };
    draw();
    return { ask };
  }
  return { mount };
})();
