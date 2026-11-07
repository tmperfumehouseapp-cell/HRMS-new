/* cx_raise.js — "Mark as complaint": the bottom sheet used by the phone chat app (wa_app.js), the admin chat console (wa_inbox.js) and the Complaints board.
   Needs api.js. Talks to bd.cx_chat_state and bd.cx_raise (complaints.php). Nothing here raises a case by itself: a person always taps "Raise complaint". */
window.CXR = (function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const PROB = [['missing', '🧩', 'Missing item'], ['leak', '💧', 'Leak / damaged'], ['notdispatched', '🚚', 'Not dispatched'], ['notdelivered', '📦', 'Not delivered'], ['tracking', '📍', 'Tracking ID'], ['ndr', '⚠️', 'NDR'], ['rto', '↩️', 'RTO'], ['wrong', '🔁', 'Wrong item'], ['notliked', '🙁', 'Not liked / return'], ['quality', '👃', 'Smell / quality'], ['refund', '💰', 'Refund'], ['staff', '🙍', 'Staff'], ['other', '…', 'Other']];
  const SRC = [['whatsapp', 'WhatsApp'], ['call', 'Call'], ['store', 'Store'], ['order', 'Order'], ['social', 'Instagram'], ['email', 'Email'], ['other', 'Other']];
  let cssDone = false;
  function css() {
    if (cssDone) return; cssDone = true;
    const s = document.createElement('style'); s.id = 'cxrCss';
    s.textContent = `
.cxr-ov{position:fixed;inset:0;z-index:2147483000;background:rgba(17,27,33,.55);display:flex;align-items:flex-end;justify-content:center;font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;-webkit-tap-highlight-color:transparent}
.cxr-sh{background:#fff;width:100%;max-width:520px;max-height:92vh;overflow:auto;border-radius:22px 22px 0 0;padding:16px 16px calc(16px + env(safe-area-inset-bottom));animation:cxrUp .22s ease-out;color:#111b21}
@keyframes cxrUp{from{transform:translateY(40px);opacity:.4}to{transform:none;opacity:1}}
@media (min-width:700px){.cxr-ov{align-items:center}.cxr-sh{border-radius:22px}}
.cxr-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.cxr-h b{font-size:18px;font-weight:800}
.cxr-x{border:0;background:#f0f2f5;width:34px;height:34px;border-radius:50%;font-size:16px;color:#54656f;cursor:pointer}
.cxr-l{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#667781;margin:12px 0 6px}
.cxr-chips{display:flex;flex-wrap:wrap;gap:8px}
.cxr-c{border:1.5px solid #e9edef;background:#fff;border-radius:999px;padding:9px 14px;font:inherit;font-size:14px;font-weight:700;color:#111b21;cursor:pointer}
.cxr-c.on{border-color:#00a884;background:#e7fbf3;color:#007a62}
.cxr-u{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.cxr-u .cxr-c{text-align:center;border-radius:14px}.cxr-u .cxr-c.on.urgent{border-color:#fb923c;background:#fff3e8;color:#c2410c}.cxr-u .cxr-c.on.high{border-color:#f59e0b;background:#fff1d6;color:#9a6200}
.cxr-o{background:#f0f2f5;border-radius:14px;padding:11px 13px;font-size:13.5px;line-height:1.45}.cxr-o b{font-weight:800}.cxr-o small{color:#667781;display:block}
.cxr-in{box-sizing:border-box;width:100%;border:1.5px solid #e9edef;border-radius:14px;padding:11px 13px;font:inherit;font-size:15px;outline:0;background:#fff;color:#111b21}.cxr-in:focus{border-color:#00a884}
textarea.cxr-in{min-height:72px;resize:vertical}
.cxr-go{width:100%;margin-top:14px;border:0;border-radius:999px;background:#00a884;color:#fff;font:inherit;font-size:16px;font-weight:800;padding:14px;cursor:pointer}.cxr-go[disabled]{opacity:.55}
.cxr-hint{background:#fff1d6;border-radius:14px;padding:10px 12px;font-size:13px;color:#7a4b00;margin-bottom:6px;display:flex;gap:8px;align-items:center;justify-content:space-between}
.cxr-hint button{border:0;background:#9a6200;color:#fff;border-radius:999px;padding:6px 12px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}
.cxr-open{background:#f3efff;border-radius:14px;padding:12px 14px;font-size:14px;line-height:1.5;color:#5b3fa8}.cxr-open a{color:#5b3fa8;font-weight:800}
.cxr-toast{position:fixed;left:50%;bottom:calc(24px + env(safe-area-inset-bottom));transform:translateX(-50%);background:#111b21;color:#fff;padding:11px 18px;border-radius:999px;font:600 14px Inter,system-ui,sans-serif;z-index:2147483200;max-width:88vw;text-align:center}
@media (max-width:820px){.cxr-sh .cxr-c{font-size:14px !important;padding:9px 14px !important;min-height:42px}.cxr-sh .cxr-go{font-size:16px !important;padding:14px !important;min-height:48px}.cxr-sh .cxr-hint button{font-size:12px !important;padding:8px 12px !important;min-height:40px}.cxr-sh .cxr-x{width:40px;height:40px;padding:0 !important;font-size:16px !important}.cxr-sh input.cxr-in{height:auto !important;min-height:44px;padding:11px 13px !important}.cxr-sh textarea.cxr-in{padding:11px 13px !important}}
.cxr-ai{background:#f5f1ff;border:1px solid #e4dcfb;border-radius:14px;padding:10px 12px;font-size:13px;line-height:1.45;color:#3f2a7a;margin-bottom:4px}.cxr-ai b{font-weight:800}.cxr-ai small{display:block;color:#6b5aa6;margin-top:3px}
.cxr-c.ai{border-style:dashed;border-color:#a78bfa}.cxr-c.ai.on{border-style:solid}
.cxr-sev{margin-top:8px;font-size:13px;font-weight:700;border-radius:12px;padding:8px 11px;background:#f0f2f5;color:#3b4a54}.cxr-sev.s{background:#fff3e8;color:#c2410c}
.cxr-ban{display:flex;align-items:center;gap:8px;background:#f3efff;color:#5b3fa8;padding:8px 12px;font:700 13px Inter,system-ui,sans-serif;border-bottom:1px solid #e4dcfb}.cxr-ban span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cxr-ban a{color:#5b3fa8}`;
    document.head.appendChild(s);
  }
  function toast(t) { css(); const e = document.createElement('div'); e.className = 'cxr-toast'; e.textContent = t; document.body.appendChild(e); setTimeout(() => e.remove(), 3200); }
  async function state(phone) { try { return await api.get('bd.cx_chat_state', { phone }); } catch (e) { return null; } }
  /** a red strip for a chat that is with the Complaint team: "⚠ Complaint C-0012 · with Neha". */
  function bannerHtml(st) {
    const c = st && st.case; if (!c) return '';
    return '<div class="cxr-ban"><span>🎫 Complaint ' + esc(c.code) + ' · ' + esc(c.problem_label) + ' · complaint team: ' + esc(c.owner_name || c.desk_label) + (c.late ? ' · late' : '') + ' · the chat stays with you' + '</span></div>';
  }
  function open(o) {
    css(); o = o || {}; document.querySelectorAll('.cxr-ov').forEach((n) => n.remove());
    const ov = document.createElement('div'); ov.className = 'cxr-ov'; ov.innerHTML = '<div class="cxr-sh"><div class="cxr-h"><b>⚠️ ' + (o && o.askCustomer ? 'Raise a complaint' : 'Mark as complaint') + '</b><button class="cxr-x" type="button">✕</button></div><div id="cxrBody" style="color:#667781;padding:18px 0">Loading…</div></div>';
    document.body.appendChild(ov);
    const close = () => ov.remove();
    ov.addEventListener('mousedown', (e) => { if (e.target === ov) close(); }); ov.querySelector('.cxr-x').onclick = close;
    const body = ov.querySelector('#cxrBody');
    const run = async () => {
      let st = o.phone ? await state(o.phone) : { can_raise: true, case: null, order: null, hint: null };
      if (!st) { body.innerHTML = '<div class="cxr-open">Could not load. Check the internet and try again.</div>'; return; }
      if (st.case) {
        const link = (st.team ? 'complaints.html' : 'my_complaints.html') + '?case=' + st.case.id;
        body.style.color = '#111b21'; body.innerHTML = '<div class="cxr-open">This customer already has an open complaint: <b>' + esc(st.case.code) + '</b> (' + esc(st.case.problem_label) + ').<br>It is with <b>' + esc(st.case.owner_name || st.case.desk_label) + '</b>' + (st.case.late ? ' — late' : '') + '.<br><a href="' + link + '">Open it</a></div>'; return;
      }
      if (!st.can_raise) { body.innerHTML = '<div class="cxr-open">This chat is not yours, so you cannot raise a complaint from it.</div>'; return; }
      // the problem is never pre-chosen: Claude reads the chat and suggests one (a new type when nothing fits); the person confirms it
      const LIST = (st.problems && st.problems.length ? st.problems.map((p) => [p.k, p.icon, p.label, !!p.serious]) : PROB.map((p) => [p[0], p[1], p[2], ['missing', 'leak', 'wrong', 'notdispatched', 'notdelivered', 'rto', 'refund'].indexOf(p[0]) >= 0]));
      let prob = o.problem || '', src = o.source || 'whatsapp', guess = null, reading = !!o.phone, ordSel = st.order ? String(st.order.name) : '';
      const pInfo = (k) => { if (k === 'new' && guess) return [guess.label, guess.icon, !!guess.serious]; const p = LIST.find((x) => x[0] === k); return p ? [p[2], p[1], p[3]] : null; };
      const draw = () => {
        body.style.color = '#111b21'; body.style.padding = '0';
        const chips = LIST.map((p) => '<button type="button" class="cxr-c' + (prob === p[0] ? ' on' : '') + (guess && guess.problem === p[0] ? ' ai' : '') + '" data-p="' + esc(p[0]) + '">' + p[1] + ' ' + esc(p[2]) + '</button>');
        if (guess && guess.new) chips.unshift('<button type="button" class="cxr-c ai' + (prob === 'new' ? ' on' : '') + '" data-p="new">🆕 ' + esc(guess.icon) + ' ' + esc(guess.label) + '</button>');
        const orders = (st.orders || []).slice(0, 4), gO = {}; ((guess && guess.orders) || []).forEach((x) => { gO[String(x.name)] = x; });
        const oLine = (x) => { const g = gO[String(x.name)] || {}; return '#' + esc(String(x.name).replace(/^#/, '')) + ' · ₹' + Number(x.total || 0).toLocaleString('en-IN') + '<small>' + esc(x.at || '') + ' · ' + esc(x.pay || '') + (g.awb ? ' · AWB ' + esc(g.awb) + (g.status ? ' · ' + esc(g.status) : '') : ' · no AWB yet') + '</small>'; };
        const pi = prob ? pInfo(prob) : null;
        body.innerHTML =
          (reading ? '<div class="cxr-ai">🤖 Claude is reading the chat…</div>' : guess ? '<div class="cxr-ai">🤖 Claude: <b>' + esc(guess.icon) + ' ' + esc(guess.label) + '</b>' + (guess.new ? ' (new type)' : '') + (guess.order ? ' · order <b>' + esc(guess.order) + '</b>' : '') + (guess.why ? '<small>' + esc(guess.why) + '</small>' : '') + '<small>Not right? Tap the right problem below.</small></div>' : '') +
          '<div class="cxr-l">What is the problem?</div><div class="cxr-chips">' + chips.join('') + '</div>' +
          (pi ? '<div class="cxr-sev' + (pi[2] ? ' s' : '') + '">' + (pi[2] ? '🔴 Serious — reply in 15 min' : 'Basic — reply in 60 min') + '</div>' : '') +
          (o.askCustomer ? '<div class="cxr-l">Customer</div><input class="cxr-in" id="cxrName" placeholder="Name" value="' + esc(o.name || '') + '" style="margin-bottom:8px"><input class="cxr-in" id="cxrPhone" inputmode="tel" placeholder="Phone number (WhatsApp)" value="' + esc(o.phone || '') + '">' + (dup ? '<div class="cxr-open" style="margin-top:8px">This customer already has an open ticket: <b>' + esc(dup.code) + '</b> (' + esc(dup.problem_label) + '), with <b>' + esc(dup.owner_name || dup.desk_label) + '</b>. <a href="' + (st.team ? 'complaints.html' : 'my_complaints.html') + '?case=' + dup.id + '" target="_top">Open it</a><br><small>Raising it again only adds your note to that ticket — never a second ticket.</small></div>' : '') +
            '<div class="cxr-l">Where did it come from?</div><div class="cxr-chips">' + SRC.map((p) => '<button type="button" class="cxr-c' + (src === p[0] ? ' on' : '') + '" data-s="' + p[0] + '">' + p[1] + '</button>').join('') + '</div>' : '') +
          '<div class="cxr-l">Which order?</div>' + (orders.length > 1 ? '<div class="cxr-chips" style="flex-direction:column;align-items:stretch">' + orders.map((x) => '<button type="button" class="cxr-c cxr-o' + (ordSel === String(x.name) ? ' on' : '') + '" data-o="' + esc(x.name) + '" style="text-align:left;border-radius:14px">' + oLine(x) + '</button>').join('') + '</div>'
            : orders.length ? '<div class="cxr-o">' + oLine(orders[0]) + '</div>' : '<input class="cxr-in" id="cxrOrd" placeholder="Order number (if you know it)">') +
          '<div class="cxr-l">Note for the team</div><textarea class="cxr-in" id="cxrNote" placeholder="What happened, in a few words"></textarea>' +
          '<button class="cxr-go" type="button" id="cxrGo"' + (prob ? '' : ' disabled') + '>' + (prob ? (dup ? 'Add my note to ' + esc(dup.code) : 'Raise complaint') : 'Choose the problem first') + '</button>';
        body.querySelectorAll('[data-p]').forEach((b) => b.onclick = () => { prob = b.dataset.p; keep(); draw(); });
        body.querySelectorAll('[data-s]').forEach((b) => b.onclick = () => { src = b.dataset.s; keep(); draw(); });
        body.querySelectorAll('[data-o]').forEach((b) => b.onclick = () => { ordSel = b.dataset.o; keep(); draw(); });
        body.querySelector('#cxrGo').onclick = go;
        const pIn = body.querySelector('#cxrPhone'); if (pIn) pIn.oninput = () => { clearTimeout(lt); const d = pIn.value.replace(/\D+/g, '').slice(-10); if (d.length === 10 && d !== looked) lt = setTimeout(() => lookup(d), 450); };   // 🔎 the number is complete: find the customer's open ticket and orders
        const n = body.querySelector('#cxrNote'); if (n) n.value = kept.note || ''; const nm = body.querySelector('#cxrName'); if (nm && kept.name != null) nm.value = kept.name; const ph = body.querySelector('#cxrPhone'); if (ph && kept.phone != null) ph.value = kept.phone; const od = body.querySelector('#cxrOrd'); if (od) od.value = kept.ord || '';
      };
      let dup = null, looked = '', lt = 0;
      const lookup = async (d) => { looked = d; keep(); const s2 = await state(d); if (!s2 || !document.body.contains(body)) return; dup = s2.case || null; st.orders = s2.orders || []; ordSel = s2.order ? String(s2.order.name) : ''; if (s2.team != null) st.team = s2.team; draw(); if (!dup) { const nx = body.querySelector('#cxrNote'); if (nx) nx.focus(); } };
      const kept = {}; const keep = () => { const g = (id) => { const e = body.querySelector(id); return e ? e.value : null; }; kept.note = g('#cxrNote'); kept.name = g('#cxrName'); kept.phone = g('#cxrPhone'); kept.ord = g('#cxrOrd'); };
      const go = async () => {
        keep(); if (!prob) return; const b = body.querySelector('#cxrGo'); b.disabled = true; b.textContent = 'Raising…';
        const extra = prob === 'new' && guess ? { new_label: guess.label, new_icon: guess.icon, new_dept: guess.dept, new_serious: guess.serious ? 1 : 0 } : {};
        const ai = guess ? { ai_problem: guess.problem, ai_label: guess.label, ai_icon: guess.icon, ai_why: guess.why || '', ai_dept: guess.dept, ai_serious: guess.serious ? 1 : 0 } : {};
        try {
          const r = await api.post('bd.cx_raise', Object.assign({ phone: o.askCustomer ? (kept.phone || '') : (o.phone || ''), name: o.askCustomer ? (kept.name || '') : (o.name || ''), source: src, problem: prob, urgency: 'normal', summary: kept.note || '', order_name: kept.ord || ordSel || '' }, extra, ai));
          close(); toast(r.message || 'Complaint raised'); if (o.onDone) o.onDone(r);
        } catch (e) { b.disabled = false; b.textContent = 'Raise complaint'; toast(e.message || 'Could not raise it'); }
      };
      if (o.phone) api.get('bd.cx_guess', { phone: o.phone }).then((r) => { guess = r && r.guess; }).catch(() => { guess = null; }).then(() => {
        reading = false; if (guess && !prob) prob = guess.problem; if (guess && guess.order && (st.orders || []).some((x) => String(x.name) === String(guess.order))) ordSel = String(guess.order);
        if (document.body.contains(body)) { keep(); draw(); }
      });
      draw();
    };
    run();
  }
  return { open, state, bannerHtml, toast, css };
})();
