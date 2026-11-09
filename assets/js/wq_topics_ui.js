/* 🧭 Follow-up topics + time ladder + "Ask Claude" for every message.   Backend: wq_topics.php (wq.topics*).   Needs api.js and wa_panels.js.
 * Nothing here is sent to a customer. A message is a DRAFT until Approve (= a new template version goes to Meta). */
(function () {
  'use strict';
  if (!window.WaPanels || !WaPanels.add) return;
  const { esc, toast, errBox, loading } = WaPanels.util;
  const ST = { approved: ['✅ Approved', '#dcfce7', '#166534'], approved_old: ['✅ Old version approved · new waiting', '#fef3c7', '#92400e'], waiting: ['⏳ Waiting for Meta', '#fef3c7', '#92400e'], rejected: ['❌ Rejected', '#fee2e2', '#991b1b'], none: ['📝 Draft — not sent', '#f1f5f9', '#334155'] };
  let CSS = false;
  function css() {
    if (CSS) return; CSS = true; const s = document.createElement('style');
    s.textContent = '.tp{display:grid;gap:10px;font-size:14px}.tp-c{background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:12px 14px}.tp-h{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-weight:800;font-size:15px}.tp-s{color:#64748b;font-size:12.5px;margin-top:3px}.tp-sp{flex:1}'
      + '.tp-b{border:1px solid #d5d9e4;background:#fff;border-radius:10px;padding:7px 12px;min-height:36px;cursor:pointer;font-weight:700;font-size:13px}.tp-b.p{background:#5b3fd6;color:#fff;border-color:#5b3fd6}.tp-b.s{padding:4px 9px;min-height:30px;font-size:12px}'
      + '.tp-in,.tp-ta{border:1px solid #d5d9e4;border-radius:8px;padding:6px 8px;font:inherit;width:100%;box-sizing:border-box;min-width:0}.tp-ta{min-height:64px;resize:vertical}.tp-pill{font-size:11px;font-weight:800;border-radius:99px;padding:2px 8px;white-space:nowrap}'
      + '.tp-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}.tp-chip{border:1px solid #d5d9e4;border-radius:99px;padding:3px 9px;font-size:12px;cursor:pointer;background:#fff}.tp-chip.on{background:#ede9fe;border-color:#5b3fd6;color:#4c1d95;font-weight:800}'
      + '.tp-msg{border:1px solid #eef0f5;border-radius:12px;padding:8px;margin-top:8px}.tp-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px}.tp-rg{display:grid;grid-template-columns:1fr 70px 70px auto;gap:6px;align-items:center;margin-top:6px}@media (max-width:520px){.tp-rg{grid-template-columns:1fr 60px 60px auto}}'
      + '.tp-pr{border:1px solid #e9d5ff;background:#faf5ff;border-radius:12px;padding:8px;margin-top:8px}.tp-old{color:#64748b;font-size:12.5px;white-space:pre-wrap;margin-bottom:4px}';
    document.head.appendChild(s);
  }
  function mount(el) {
    css(); let D = null, open = '', props = null, busy = false; el.innerHTML = '<div class="tp"><div class="tp-c">' + loading + '</div></div>';
    const call = async (fn) => { try { const r = await fn(); if (r && r.message) toast(r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } };
    async function load(keep) { try { D = await api.get('wq.topics'); } catch (e) { el.innerHTML = '<div class="tp">' + errBox(e.message) + '</div>'; return; } draw(); if (keep) { /* keep scroll */ } }
    const pill = (s) => { const x = ST[s] || ST.none; return '<span class="tp-pill" style="background:' + x[1] + ';color:' + x[2] + '">' + x[0] + '</span>'; };
    const rl = (id) => { const r = D.rungs.find((x) => x.id === id); return r ? r.label : id; };
    function propsHtml(scope) {
      if (!props || props.scope !== scope) return '';
      if (!props.rows.length) return '<div class="tp-s" style="margin-top:6px">' + esc(props.message || '') + '</div>';
      return '<div class="tp-s" style="margin-top:8px"><b>Claude proposes</b> — edit the words if you like, untick what you do not want, then Apply (the words go to Meta).</div>'
        + props.rows.map((r, i) => '<div class="tp-pr" data-pr="' + i + '"><label style="display:flex;gap:6px;align-items:center;font-weight:800"><input type="checkbox" data-pon ' + (r.on ? 'checked' : '') + '> ' + esc(r.title) + '</label><div class="tp-old">Now: ' + esc(r.old) + '</div><textarea class="tp-ta" data-pbody>' + esc(r.body) + '</textarea>'
          + (r.why ? '<div class="tp-s">' + esc(r.why) + '</div>' : '') + (r.warn ? '<div class="tp-s" style="color:#b45309">⚠ ' + esc(r.warn) + '</div>' : '') + '</div>').join('')
        + '<div class="tp-row"><button class="tp-b p" type="button" data-apply>Apply &amp; send to Meta</button><button class="tp-b" type="button" data-pcancel>Cancel</button></div>';
    }
    function ask(scope, ph) { return '<div class="tp-row"><input class="tp-in" data-ask="' + scope + '" placeholder="' + esc(ph) + '" style="flex:1"><button class="tp-b s" type="button" data-askgo="' + scope + '">✨ Ask Claude</button></div>' + propsHtml(scope); }
    function draw() {
      const rungs = D.rungs.map((r, i) => '<div class="tp-rg" data-rg="' + i + '"><input class="tp-in" data-f="label" value="' + esc(r.label) + '"><input class="tp-in" data-f="from_h" type="number" min="0" step="0.5" value="' + r.from_h + '" title="from (hours)"><input class="tp-in" data-f="to_h" type="number" min="0" step="0.5" value="' + r.to_h + '" title="to (hours)"><button class="tp-b s" data-rgdel="' + i + '" type="button">✕</button></div>').join('');
      let h = '<div class="tp"><div class="tp-c"><div class="tp-h">🧭 Follow-up topics<span class="tp-sp"></span></div><div class="tp-s">The 60 days are a ladder of time windows. Each topic is a kind of message and says which windows it suits. Every message is a <b>draft</b> until you press <b>Approve</b> — then its words go to Meta as a template. Nothing here is sent to a customer yet.</div>'
        + '<div style="margin-top:10px"><b>✨ Ask Claude about everything</b><div class="tp-s">For example: “Diwali is coming — change the festival and offer messages”, “make every message shorter”, “use the new 12 ml offer”.</div>' + ask('all', 'What should change?') + '</div></div>'
        + '<div class="tp-c"><div class="tp-h">🪜 Time ladder<span class="tp-sp"></span><button class="tp-b s" type="button" id="tpRgAdd">＋ Add a step</button><button class="tp-b s p" type="button" id="tpRgSave">Save ladder</button></div><div class="tp-s">Hours after our last reply (e.g. 3 to 5 = somewhere between 3 and 5 hours later). Change any time, add or remove a step.</div>' + rungs + '</div>';
      D.topics.forEach((t) => {
        const isOpen = open === t.id;
        h += '<div class="tp-c" data-t="' + t.id + '"><div class="tp-h" data-tg style="cursor:pointer"><span>' + esc(t.icon) + ' ' + esc(t.title) + '</span><span class="tp-sp"></span><span class="tp-s" style="margin:0">' + t.messages.map((m) => pill(m.status)).join(' ') + '</span><span>' + (isOpen ? '▾' : '▸') + '</span></div>'
          + '<div class="tp-s">' + t.rungs.map(rl).join(' · ') + (t.on ? '' : ' · <b>OFF</b>') + '</div>';
        if (isOpen) {
          h += '<div style="margin-top:8px"><label class="tp-s">General summary — what this topic says (also the rule for the {{3}} line)</label><textarea class="tp-ta" data-sum>' + esc(t.summary) + '</textarea>'
            + '<div class="tp-chips">' + D.rungs.map((r) => '<span class="tp-chip ' + (t.rungs.includes(r.id) ? 'on' : '') + '" data-rg-t="' + r.id + '">' + esc(r.label) + '</span>').join('') + '</div>'
            + '<div class="tp-row"><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-on ' + (t.on ? 'checked' : '') + '> Use this topic</label><span class="tp-sp"></span><button class="tp-b s p" type="button" data-tsave>Save summary &amp; windows</button></div></div>';
          t.messages.forEach((m) => { h += '<div class="tp-msg" data-k="' + m.key + '"><div class="tp-row" style="margin-top:0">' + pill(m.status) + (m.held && m.status === 'none' ? '' : '') + (m.reason ? '<span class="tp-s" style="color:#991b1b">' + esc(m.reason) + '</span>' : '') + '</div><textarea class="tp-ta" data-body>' + esc(m.body) + '</textarea><div class="tp-s">{{1}} name · {{2}} perfume · {{3}} one line written for each customer</div><div class="tp-row"><button class="tp-b s" type="button" data-save>Save draft</button><button class="tp-b s p" type="button" data-appr>Approve → Meta</button></div></div>'; });
          h += '<div class="tp-row"><button class="tp-b s" type="button" data-addv>＋ Another message for this topic</button></div><div style="margin-top:8px"><b>✨ Ask Claude about this topic</b>' + ask(t.id, 'e.g. make it shorter, more friendly, mention the festival') + '</div>';
        }
        h += '</div>';
      });
      el.innerHTML = h + '</div>'; bind();
    }
    function bind() {
      const $ = (s) => el.querySelector(s), $$ = (s) => [...el.querySelectorAll(s)];
      const rungsFromDom = () => $$('[data-rg]').map((r, i) => ({ id: D.rungs[i] ? D.rungs[i].id : 'x' + Date.now() + i, label: r.querySelector('[data-f=label]').value, from_h: +r.querySelector('[data-f=from_h]').value, to_h: +r.querySelector('[data-f=to_h]').value }));
      $('#tpRgAdd').onclick = () => { D.rungs = rungsFromDom(); D.rungs.push({ id: 'x' + Date.now().toString(36), label: 'New step', from_h: 24, to_h: 26 }); draw(); };
      $('#tpRgSave').onclick = async () => { D.rungs = rungsFromDom(); await call(() => api.post('wq.topics_save', { rungs: D.rungs })); await load(); };
      $$('[data-rgdel]').forEach((b) => { b.onclick = () => { D.rungs = rungsFromDom(); D.rungs.splice(+b.dataset.rgdel, 1); draw(); }; });
      $$('[data-tg]').forEach((h) => { h.onclick = () => { const id = h.closest('[data-t]').dataset.t; open = open === id ? '' : id; props = null; draw(); }; });
      $$('[data-rg-t]').forEach((c) => { c.onclick = () => c.classList.toggle('on'); });
      $$('[data-tsave]').forEach((b) => { b.onclick = async () => { const card = b.closest('[data-t]'); await call(() => api.post('wq.topics_save', { topics: [{ id: card.dataset.t, summary: card.querySelector('[data-sum]').value, on: card.querySelector('[data-on]').checked, rungs: [...card.querySelectorAll('[data-rg-t].on')].map((x) => x.dataset.rgT) }] })); await load(); }; });
      $$('[data-save]').forEach((b) => { b.onclick = async () => { const m = b.closest('[data-k]'); await call(() => api.post('wq.topics_msg', { key: m.dataset.k, body: m.querySelector('[data-body]').value })); await load(); }; });
      $$('[data-appr]').forEach((b) => { b.onclick = async () => { const m = b.closest('[data-k]'); if (!confirm('Send these words to Meta as a template?')) return; b.disabled = true; await call(async () => { await api.post('wq.topics_msg', { key: m.dataset.k, body: m.querySelector('[data-body]').value }); return api.post('wq.topics_approve', { key: m.dataset.k }); }); await load(); }; });
      $$('[data-addv]').forEach((b) => { b.onclick = async () => { await call(() => api.post('wq.topics_msg', { topic: b.closest('[data-t]').dataset.t, add: 1 })); await load(); }; });
      $$('[data-askgo]').forEach((b) => { b.onclick = async () => { if (busy) return; const scope = b.dataset.askgo, inp = el.querySelector('[data-ask="' + scope + '"]'), q = inp.value.trim(); if (!q) { toast('Tell Claude what to change.', true); return; } busy = true; b.disabled = true; b.textContent = 'Thinking…';
        try { const r = await api.post('wq.topics_ask', { scope, ask: q }); props = { scope, ask: q, message: r.message, rows: (r.rows || []).map((x) => Object.assign({ on: true }, x)) }; } catch (e) { props = { scope, ask: q, message: e.message, rows: [] }; toast(e.message, true); } busy = false; draw(); }; });
      $$('[data-pcancel]').forEach((b) => { b.onclick = () => { props = null; draw(); }; });
      $$('[data-apply]').forEach((b) => { b.onclick = async () => { if (!props) return; const rows = $$('[data-pr]').map((p, i) => ({ key: props.rows[i].key, summary: props.rows[i].summary, on: p.querySelector('[data-pon]').checked, body: p.querySelector('[data-pbody]').value })); if (!confirm('Send the selected messages to Meta as new template versions?')) return; b.disabled = true;
        await call(() => api.post('wq.topics_apply', { rows, ask: props.ask })); props = null; await load(); }; });
    }
    load(); return () => { };
  }
  WaPanels.add('topics', (el) => mount(el));
})();
