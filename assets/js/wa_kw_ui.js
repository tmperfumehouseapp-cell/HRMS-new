/* wa_kw_ui.js — ⚡ Button replies (Campaigns): one card per campaign button — the words a customer taps, the message and an optional photo. Automatic switch, "Send to waiting". */
(function () {
  'use strict';
  if (window.WaKw) return;
  const P = () => window.WaPanels && WaPanels.util;
  function open() {
    const U = P(); if (!U) { alert('Please refresh the page.'); return; }
    const { esc, $, toast, modal, errBox } = U; const m = modal('⚡ Button replies'); m.el.classList.add('cu-wide');
    let C = { auto: true, rules: [] }, ST = {}, TW = 0, G = { items: [] }, dirty = false; const THUMB = {};
    const thumb = async (id, node) => { if (THUMB[id]) { node.style.backgroundImage = 'url(' + THUMB[id] + ')'; node.textContent = ''; return; } try { const r = await fetch(api.buildUrl('wa.g_thumb', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (r.ok) { THUMB[id] = URL.createObjectURL(await r.blob()); node.style.backgroundImage = 'url(' + THUMB[id] + ')'; node.textContent = ''; } } catch (e) { } };
    const gi = (id) => (G.items || []).find((x) => x.id === +id);
    async function load() {
      m.body.innerHTML = '<div class="wp-hint">Loading…</div>';
      try { const [d, g] = await Promise.all([api.get('wa.kw_get'), api.get('wa.g_gallery').catch(() => ({}))]); C = d.cfg; C.rules.forEach((r) => { r.fu = (r.fu || []).slice(); while (r.fu.length < 2) r.fu.push({ after_min: r.fu.length ? 360 : 60, text: '', lib_id: 0 }); }); ST = d.stats || {}; TW = d.total_waiting || 0; G = (g && g.gallery) || G; dirty = false; paint(); } catch (e) { m.body.innerHTML = errBox(e.message); }
    }
    function fuRow(r, j) {
      const f = r.fu[j], ph = f.lib_id ? gi(f.lib_id) : null, hrs = f.after_min % 60 === 0, v = hrs ? f.after_min / 60 : f.after_min;
      return `<div data-j="${j}" style="border:1px dashed #cbd5e1;border-radius:12px;padding:8px;margin-top:6px;background:rgba(255,255,255,.5)">
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><b style="font-size:12.5px">↪ Follow-up ${j + 1}</b><span class="wp-hint" style="margin:0">if no reply, after</span><input class="wp-in" data-fu="n" inputmode="numeric" value="${v}" style="width:64px"><select class="wp-in" data-fu="u" style="width:96px"><option value="m" ${hrs ? '' : 'selected'}>minutes</option><option value="h" ${hrs ? 'selected' : ''}>hours</option></select><span class="wp-hint" style="margin:0">(since the previous message)</span><span style="flex:1"></span><button type="button" class="wp-btn" data-a="fdel" style="color:#b42318">✕</button></div>
        <textarea class="wp-in" data-fu="text" rows="3" placeholder="Message for this follow-up…" style="width:100%;box-sizing:border-box;margin-top:6px;resize:vertical">${esc(f.text)}</textarea>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px"><span class="wp-hint" style="margin:0">📎 Photo</span>${ph ? `<span data-im="${ph.id}" style="width:38px;height:38px;border-radius:8px;background:#e2e8f0 center/cover;display:inline-block"></span><button type="button" class="wp-btn" data-a="fph">Change</button><button type="button" class="wp-btn" data-a="fphx">Remove</button>` : '<button type="button" class="wp-btn" data-a="fph">Add a photo</button>'}</div></div>`;
    }
    function card(r, i) {
      const s = ST[r.id] || { sent: 0, waiting: 0 }, ph = r.lib_id ? gi(r.lib_id) : null;
      return `<div class="wp-cd" data-i="${i}" style="margin-bottom:10px">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><label class="wp-sw"><input type="checkbox" data-f="on" ${r.on ? 'checked' : ''}><span></span></label><input class="wp-in" data-f="name" value="${esc(r.name)}" placeholder="Button name, e.g. 50 ml combo" style="flex:1;min-width:160px;font-weight:800"><button type="button" class="wp-btn" data-a="del" style="color:#b42318">Delete</button></div>
        <div class="wp-hint" style="margin:8px 0 3px">Customer taps / writes (separate with commas)</div><input class="wp-in" data-f="words" value="${esc((r.words || []).join(', '))}" placeholder="50 ml, 50ml combo, share 50ml combo" style="width:100%;box-sizing:border-box">
        <div class="wp-hint" style="margin:8px 0 3px">Message we send <span style="color:#667085">— {name} = customer's first name</span></div><textarea class="wp-in" data-f="text" rows="4" style="width:100%;box-sizing:border-box;resize:vertical">${esc(r.text)}</textarea>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px"><span class="wp-hint" style="margin:0">📎 Photo</span>${ph ? `<span data-im="${ph.id}" style="width:46px;height:46px;border-radius:8px;background:#e2e8f0 center/cover;display:inline-block"></span><span style="font-weight:700;font-size:12.5px">${esc(ph.title || '')}</span><button type="button" class="wp-btn" data-a="ph">Change</button><button type="button" class="wp-btn" data-a="phx">Remove</button>` : '<button type="button" class="wp-btn" data-a="ph">Add a photo</button><span class="wp-hint" style="margin:0">(the message becomes its caption)</span>'}
          <span style="flex:1"></span><span class="wp-hint" style="margin:0">Sent today <b>${s.sent}</b> · Waiting now <b>${s.waiting}</b></span>${s.waiting ? '<button type="button" class="wp-btn p" data-a="send">Send to waiting</button>' : ''}</div>
        <div class="wp-hint" style="margin:10px 0 0"><b>If the customer does not answer</b> — automatic follow-ups (free messages only work inside 24 hours after the customer's last message; they stop when the customer replies or a salesperson takes the chat)${s.fu ? ' · sent today <b>' + s.fu + '</b>' : ''}</div>${(r.fu || []).map((f, j) => fuRow(r, j)).join('')}<button type="button" class="wp-btn" data-a="fadd" style="margin-top:6px">＋ Add another follow-up</button></div>`;
    }
    function paint() {
      m.body.innerHTML = `<div class="wp-hint" style="margin:0 0 8px">When a customer taps / types one of your campaign buttons, the message below goes out at once — automatically while <b>Automatic</b> is on. Only chats that wrote in the last 24 hours, not given to a salesperson, not complaints / opted-out; each message is answered once.</div>
        <div class="wp-cd" style="display:flex;align-items:center;gap:10px;margin-bottom:10px"><label class="wp-sw"><input type="checkbox" id="kwAuto" ${C.auto ? 'checked' : ''}><span></span></label><b>Automatic</b><span class="wp-hint" style="margin:0">${C.auto ? 'ON — new taps are answered by themselves' : 'OFF — nothing is sent by itself'}</span></div>
        ${C.rules.map(card).join('') || '<div class="wp-hint">No buttons yet.</div>'}
        <button type="button" class="wp-btn" id="kwAdd" style="width:100%;border-style:dashed;margin:4px 0 10px">＋ Add a button (e.g. “100 ml combo”, “Gift set”, “Price list”)</button>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="wp-btn p" id="kwSave" style="flex:1">Save</button><button type="button" class="wp-btn" id="kwAll" style="flex:1;background:#4f46e5;color:#fff">⚡ Answer ALL waiting now (${TW})</button></div><div id="kwMsg" class="wp-hint" style="margin-top:8px"></div>`;
      m.body.querySelectorAll('[data-im]').forEach((n) => thumb(n.dataset.im, n));
      $(m.body, '#kwAuto').onchange = (e) => { C.auto = e.target.checked; dirty = true; paint(); };
      m.body.oninput = (e) => { const c = e.target.closest('[data-i]'); if (!c) return; const fj = e.target.closest('[data-j]'), ff = e.target.dataset.fu; if (fj && ff) { const fu = C.rules[+c.dataset.i].fu[+fj.dataset.j]; if (ff === 'text') fu.text = e.target.value; else { const nv = Math.max(1, Math.round(+(fj.querySelector('[data-fu="n"]').value.replace(/\D+/g, '')) || 1)), h = fj.querySelector('[data-fu="u"]').value === 'h'; fu.after_min = Math.min(1380, h ? nv * 60 : nv); } dirty = true; return; } const f = e.target.dataset.f; if (!f) return; const r = C.rules[+c.dataset.i]; if (f === 'on') r.on = e.target.checked; else if (f === 'words') r.words = e.target.value.split(',').map((x) => x.trim()).filter(Boolean); else r[f] = e.target.value; dirty = true; };
      m.body.onchange = (e) => { if (e.target.dataset.fu === 'u') { const c = e.target.closest('[data-i]'), fj = e.target.closest('[data-j]'); if (c && fj) { const nv = Math.max(1, Math.round(+(fj.querySelector('[data-fu="n"]').value.replace(/\D+/g, '')) || 1)); C.rules[+c.dataset.i].fu[+fj.dataset.j].after_min = Math.min(1380, e.target.value === 'h' ? nv * 60 : nv); dirty = true; } return; } if (e.target.dataset.f === 'on') { const c = e.target.closest('[data-i]'); C.rules[+c.dataset.i].on = e.target.checked; dirty = true; } };
      m.body.onclick = async (e) => {
        const b = e.target.closest('[data-a]'); if (!b) return; const c = b.closest('[data-i]'), i = c ? +c.dataset.i : -1, r = C.rules[i], a = b.dataset.a;
        if (a === 'del') { if (confirm('Delete the button “' + r.name + '”?')) { C.rules.splice(i, 1); dirty = true; paint(); } }
        else if (a === 'phx') { r.lib_id = 0; dirty = true; paint(); }
        else if (a === 'ph') pick(r);
        else if (a === 'fadd') { r.fu.push({ after_min: 1440 > 720 ? 720 : 60, text: '', lib_id: 0 }); dirty = true; paint(); }
        else if (a === 'fdel' || a === 'fph' || a === 'fphx') { const j = +b.closest('[data-j]').dataset.j; if (a === 'fdel') { r.fu.splice(j, 1); dirty = true; paint(); } else if (a === 'fphx') { r.fu[j].lib_id = 0; dirty = true; paint(); } else pick(r.fu[j]); }
        else if (a === 'send') { await save(true); await run(r.id, b); }
      };
      $(m.body, '#kwAdd').onclick = () => { C.rules.push({ id: '', name: 'New button', on: true, words: [], text: '', lib_id: 0 }); dirty = true; paint(); };
      $(m.body, '#kwSave').onclick = () => save(false);
      $(m.body, '#kwAll').onclick = async function () { await save(true); await run('', this); };
    }
    function pick(r) {
      const p = modal('Pick a photo'); const items = (G.items || []).filter((x) => x.kind === 'image').slice(0, 120);
      p.body.innerHTML = items.length ? `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px">${items.map((x) => `<button type="button" data-p="${x.id}" title="${esc(x.title || '')}" style="border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:3px;cursor:pointer"><div data-im="${x.id}" style="height:84px;border-radius:8px;background:#e2e8f0 center/cover"></div><div style="font-size:11px;margin-top:2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">${esc(x.title || '')}</div></button>`).join('')}</div>` : '<div class="wp-hint">No photos in the library yet — add them in the Photos tab first.</div>';
      p.body.querySelectorAll('[data-im]').forEach((n) => thumb(n.dataset.im, n)); p.body.onclick = (e) => { const b = e.target.closest('[data-p]'); if (!b) return; r.lib_id = +b.dataset.p; dirty = true; p.close(); paint(); };
    }
    async function save(quiet) {
      const bad = C.rules.find((r) => r.on && !(r.words || []).length); if (bad) { toast('“' + bad.name + '” has no words — add what the customer taps.', true); throw new Error('x'); }
      try { const d = await api.post('wa.kw_save', C); C = d.cfg; dirty = false; if (!quiet) { toast('Saved ✓ — it runs by itself'); await load(); } } catch (e) { toast(e.message, true); throw e; }
    }
    async function run(rule, btn) {
      const msg = $(m.body, '#kwMsg'); btn.disabled = true;
      try {
        const d = await api.get('wa.kw_waiting'); const n = rule ? (ST[rule] || {}).waiting || 0 : d.total; if (!n) { toast('Nobody is waiting.'); btn.disabled = false; return; }
        if (!confirm('Send the saved reply now to ' + n + ' waiting customer' + (n === 1 ? '' : 's') + '?')) { btn.disabled = false; return; }
        let sent = 0, left = n, g = 0; while (left > 0 && g++ < 80) { if (msg) msg.textContent = 'Sending… ' + sent + ' sent'; const r = await api.post('wa.kw_waiting', { rule }); sent += r.sent; if (!r.sent && !r.failed) break; left = r.left; }
        toast('Done — ' + sent + ' customers answered.'); await load();
      } catch (e) { toast(e.message, true); btn.disabled = false; }
    }
    load();
  }
  window.WaKw = { open };
})();
