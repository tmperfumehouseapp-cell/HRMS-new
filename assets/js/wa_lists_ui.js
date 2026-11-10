/* wa_lists_ui.js — 📋 Lists (empmanagment/wa_lists.php, wl.* actions).
 *   WaLists.manage(el)               the Lists screen (WhatsApp → Lists): ✨ main Claude box (summary → groups + lists + tags, "Suggest more"),
 *                                    every list as a card with its own ✨ Claude box, edit form, pins / drops, fetch now, sent & orders counts.
 *   WaLists.sheet(body, {phone, onSent, close})   the chat's 📋 Lists sheet: lists matching the customer's last message first (✨), preview (editable), Send.
 * Self-contained (needs api.js only), so it works in whatsapp.html, wa_one.html, wa_chats.html and my_chats.html.
 */
(function () {
  if (window.WaLists) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (n) => '₹' + Number(n || 0).toLocaleString('en-IN');
  const ago = (s) => { if (!s) return 'not yet'; const d = new Date(String(s).replace(' ', 'T')); const h = (Date.now() - d) / 36e5; return h < 1 ? 'just now' : h < 24 ? Math.round(h) + ' h ago' : Math.round(h / 24) + ' d ago'; };
  function toast(m, bad) { const t = document.createElement('div'); t.className = 'wl-toast' + (bad ? ' bad' : ''); t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), bad ? 5000 : 2400); }
  const CSS = `
  .wl{font-family:inherit;color:#1c2430} .wl *{box-sizing:border-box}
  .wl-ai{background:linear-gradient(135deg,#f5f3ff,#eef6ff);border:1px solid #ddd6fe;border-radius:16px;padding:14px;margin-bottom:14px}
  .wl-ai h3{margin:0 0 4px;font-size:15px} .wl-ai p{margin:0 0 8px;color:#5b6472;font-size:12.5px;line-height:1.45}
  .wl-ai textarea,.wl-in,.wl-f input,.wl-f select,.wl-f textarea{width:100%;border:1px solid #d0d5dd;border-radius:10px;padding:9px 11px;font:inherit;font-size:14px;background:#fff;margin:0}
  .wl-ai textarea{min-height:74px;resize:vertical}
  .wl-conv{display:grid;gap:6px;margin:10px 0}.wl-me{justify-self:end;max-width:86%;background:#dcfce7;border-radius:12px 12px 4px 12px;padding:8px 11px;white-space:pre-wrap;font-size:13.5px}
  .wl-fix{border:1px dashed #c4b5fd;background:#faf8ff;border-radius:12px;padding:10px 12px;margin-top:12px;font-size:13px}.wl-fix textarea{width:100%;min-height:64px;margin-top:6px;border:1px solid #d0d5dd;border-radius:10px;padding:9px 11px;font:inherit;font-size:14px;resize:vertical;box-sizing:border-box}
  .wl-mic{border:0;background:#f58220;color:#fff;min-width:36px;height:36px;padding:0 12px;border-radius:99px;font-weight:700;white-space:nowrap;cursor:pointer;font-size:14px;flex:none}.wl-mic.on{background:#dc2626;animation:wlp 1s infinite}@keyframes wlp{50%{opacity:.55}}.wl-mic.s{height:32px;font-size:12.5px}
  .wl-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
  .wl-btn{border:1px solid #d0d5dd;background:#fff;color:#1c2430;border-radius:999px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;min-height:0;width:auto;line-height:1.2}
  .wl-btn.p{background:#7c3aed;border-color:#7c3aed;color:#fff} .wl-btn.g{background:#16a34a;border-color:#16a34a;color:#fff} .wl-btn.s{padding:5px 10px;font-size:12px} .wl-btn:disabled{opacity:.5;cursor:default}
  .wl-grp{margin:16px 0 6px;font-size:12px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#6b7684}
  .wl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px}
  .wl-card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:12px}
  .wl-card h4{margin:0;font-size:15px;display:flex;gap:6px;align-items:center} .wl-card h4 span{flex:1}
  .wl-meta{font-size:11.5px;color:#6b7684;margin:3px 0 8px}
  .wl-it{display:flex;gap:6px;align-items:center;font-size:13px;padding:3px 0;border-bottom:1px dashed #eef0f3} .wl-it:last-child{border-bottom:0}
  .wl-it .nm{flex:1;min-width:0;overflow-wrap:anywhere} .wl-lang{border:1px solid #d8dfdb;background:#fff;border-radius:99px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer;color:#4a5565} .wl-orig{margin-top:10px;font-size:12.5px;color:#4a5565;line-height:1.6} .wl-orig code{background:#f1f3f6;border-radius:5px;padding:1px 5px} .wl-orig label{display:block;margin-top:4px} .wl-orig select{max-width:100%;margin-left:6px;padding:4px 6px;border-radius:8px;border:1px solid #d8dfdb} .wl-it .pr{color:#6b7684;font-size:12px}
  .wl-it button{border:0;background:#f3f4f6;border-radius:8px;width:26px;height:24px;cursor:pointer;font-size:12px;padding:0;min-height:0}
  .wl-it.pin .nm::before{content:'📌 ';}
  .wl-say{display:flex;gap:6px;margin-top:8px} .wl-say input{flex:1}
  .wl-und{background:#f5f3ff;border-radius:10px;padding:8px 10px;font-size:12.5px;margin-top:8px;line-height:1.45}
  .wl-ba{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;font-size:12.5px} .wl-ba b{display:block;margin-bottom:2px;font-size:11.5px;color:#6b7684} .wl-ba ol{margin:0;padding-left:18px}
  .wl-chip{display:inline-block;background:#eef2ff;color:#3730a3;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;margin:2px 4px 0 0}
  .wl-f{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px} .wl-f label{font-size:11.5px;color:#6b7684;font-weight:700;display:block;margin-bottom:2px} .wl-f .full{grid-column:1/-1}
  .wl-err{background:#fde8e8;color:#9a2620;border-radius:10px;padding:8px 12px;font-size:12.5px;margin-top:8px}
  .wl-empty{color:#6b7684;text-align:center;padding:24px;font-size:13px}
  .wl-toast{position:fixed;left:50%;bottom:90px;transform:translateX(-50%);background:#1b2430;color:#fff;padding:10px 16px;border-radius:99px;font-weight:700;font-size:13px;z-index:2000;max-width:90vw}
  .wl-toast.bad{background:#b42318}
  .wl-sh .wl-pick{display:flex;gap:10px;align-items:center;width:100%;text-align:left;border:1px solid #e4e7ec;background:#fff;border-radius:12px;padding:10px 12px;margin-bottom:8px;cursor:pointer;font:inherit;color:inherit}
  .wl-sh .wl-pick b{flex:1;font-size:14.5px} .wl-sh .wl-pick small{color:#6b7684;font-size:12px} .wl-sh .wl-pick.m{border-color:#a78bfa;background:#faf5ff}
  .wl-sh textarea{width:100%;min-height:220px;border:1px solid #d0d5dd;border-radius:12px;padding:10px;font:inherit;font-size:14px;line-height:1.45}
  @media (max-width:700px){.wl-ba{grid-template-columns:1fr}.wl-f{grid-template-columns:1fr}}
  html.wxdk .wl-sh .wl-pick{background:#202c33;border-color:#2a3942;color:#e9edef} html.wxdk .wl-sh textarea{background:#202c33;color:#e9edef;border-color:#2a3942}`;
  function css() { if (document.getElementById('wlCss')) return; const s = document.createElement('style'); s.id = 'wlCss'; s.textContent = CSS; document.head.appendChild(s); }
  const itemsHtml = (v, l, edit) => (v || []).map((x, i) => `<div class="wl-it ${l && (l.pins || []).includes(x.id) ? 'pin' : ''}" data-id="${x.id}"><span class="nm" title="${esc((x.title || x.name) + (x.staff ? ' · ours: ' + x.staff : ''))}">${i + 1}. ${esc(x.name)}${x.renamed ? ' <small style="color:#7c3aed">✏️</small>' : ''}</span><span class="pr">${esc(x.extra != null ? x.extra : (x.price ? rs(x.price) : ''))}</span>${edit ? `<button type="button" data-ren="${x.id}" title="Rename this perfume in this list">✏️</button><button type="button" data-pin="${x.id}" title="Pin: always in this list">📌</button><button type="button" data-up="${x.id}" title="Move up">↑</button><button type="button" data-drop="${x.id}" title="Remove (never in this list)">✕</button>` : ''}</div>`).join('') || '<div class="wl-empty" style="padding:8px">No perfume fits yet.</div>';

  // ------------------------------------------------------------------ the Lists screen
  // 🎤 the same recorder as the Teach board: keeps listening until you press Stop (Chrome stops after a pause → it starts again by itself),
  // the words appear in the box as you speak, the language is Indian English (en-IN) unless wl_lang is set. The box is found again by its selector after a redraw.
  // where the REAL original names come from: the owner's Shopify field (picked here), the internet, Claude's guess
  function origLine(o) {
    if (!o || !o.perfumes) return '';
    const key = Object.keys(o.keys || {})[0] || '', parts = [];
    if (o.shopify) parts.push(`<b>${o.shopify}</b> from your Shopify field${key ? ' <code>' + esc(key) + '</code>' : ''}`); if (o.web) parts.push(`<b>${o.web}</b> found online`); if (o.claude) parts.push(`<b>${o.claude}</b> Claude’s guess`); if (o.unknown) parts.push(`<b>${o.unknown}</b> still unknown`);
    const opts = ['<option value="">Find it by its name (original / popup / real…)</option>'].concat((o.cands || []).map((c) => `<option value="${esc(c.key)}" ${o.chosen === c.key ? 'selected' : ''}>${esc(c.key)} — e.g. “${esc(c.sample)}” (${c.n})</option>`));
    return `<div class="wl-orig">🏷 Real original names: <b>${o.perfumes - o.unknown}</b> of ${o.perfumes} perfumes — ${parts.join(' · ')}${o.filled ? ` · long lasting / notes filled from the original for <b>${o.filled}</b>` : ''}
      <label>Shopify field with the original name <select id="wlOk">${opts.join('')}</select></label>${!o.shopify ? `<div style="margin-top:4px">${(o.cands || []).length ? 'Your Shopify field is not picked up by its name — choose it in the list above.' : 'Shopify fields are not read yet.'} <button type="button" class="wl-btn s" id="wlSync">↻ Read Shopify fields now</button></div>` : ''}</div>`;
  }
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition; let MIC = null, REC = null;
  const DRAFT = {};   // what is typed / spoken in the Claude boxes, kept when the page redraws (the Learning page redraws often)
  const keepDraft = (sel) => { const t = document.querySelector(sel); if (t) DRAFT[sel] = t.value; };
  document.addEventListener('input', (e) => { const t = e.target; if (!t || !t.matches) return; if (t.matches('#wlSum')) DRAFT['#wlSum'] = t.value; else if (t.matches('[data-say]')) DRAFT['[data-say="' + t.dataset.say + '"]'] = t.value; });
  const micLang = () => { try { return localStorage.getItem('wl_lang') || 'en-IN'; } catch (e) { return 'en-IN'; } };   // Lists: Indian English by default — understands the accent and Hinglish but writes in English letters
  function micBtn(sel, small) { return SR ? `<button type="button" class="wl-mic${small ? ' s' : ''}" data-mic="${esc(sel)}" title="Press to start speaking, press again to stop">🎤 Start</button><button type="button" class="wl-lang" data-lang title="The language you SPEAK in — English letters (Indian English) or Hindi (best for Hinglish). Claude always answers in English.">${micLang() === 'hi-IN' ? 'हिं Hindi' : 'EN English'}</button>` : ''; }
  function micMark() { document.querySelectorAll('.wl-mic').forEach((x) => { const on = !!(MIC && MIC.want && x.dataset.mic === MIC.sel); x.classList.toggle('on', on); const t = on ? (MIC.on ? '⏹ Stop · listening' : '⏹ Stop · starting…') : '🎤 Start'; if (x.textContent !== t) x.textContent = t; }); }
  function micStop() { if (MIC) { MIC.want = false; clearInterval(MIC.timer); } try { if (REC) REC.stop(); } catch (e) { } REC = null; MIC = null; micMark(); }
  function micStart() {
    if (!MIC || !MIC.want) return; const r = new SR(); r.lang = micLang(); r.interimResults = true; r.continuous = true; REC = r;
    { const t0 = document.querySelector(MIC.sel); MIC.base = t0 ? t0.value : ''; MIC.wrote = MIC.base; MIC.skip = 0; }
    // the box = what was there (typed or spoken before) + everything heard in this listening session, shown word by word as you speak.
    // If you type while it listens, your text is kept: the box becomes the new start and only NEW speech is added after it.
    r.onresult = (ev) => {
      if (!MIC) return; const ta = document.querySelector(MIC.sel); if (!ta) return;
      if (ta.value !== MIC.wrote) { MIC.base = ta.value; MIC.skip = ev.resultIndex; }   // you typed / the page redrew: keep it
      let fin = '', live = ''; for (let i = MIC.skip; i < ev.results.length; i++) { const t = ev.results[i][0].transcript; if (ev.results[i].isFinal) fin += t + ' '; else live += t; }
      const heard = (fin + live).replace(/\s+/g, ' ').trim(); if (!heard) return;   // nothing new: never touch the box
      const v = (MIC.base ? MIC.base.replace(/\s+$/, '') + ' ' : '') + heard; if (v !== ta.value) { ta.value = v; ta.scrollTop = ta.scrollHeight; } MIC.wrote = ta.value; DRAFT[MIC.sel] = ta.value; MIC.heard = Date.now();
    };
    r.onstart = () => { if (MIC) { MIC.on = true; micMark(); } };
    r.onerror = (ev) => {
      const e = ev && ev.error; if (e === 'no-speech' || e === 'aborted') return;
      if (e === 'not-allowed' || e === 'service-not-allowed' || e === 'audio-capture') { alert('The microphone is blocked or missing — allow it in the browser (🔒 in the address bar) and check the mic in Windows sound settings.'); micStop(); return; }
      if (e === 'network') { toast('Voice needs the internet (Chrome sends speech to Google) — check the connection and press Start again.', true); micStop(); return; }
      toast('Voice stopped: ' + (e || 'unknown error') + ' — press Start again.', true);
    };
    r.onend = () => { REC = null; if (MIC) { MIC.on = false; const t = document.querySelector(MIC.sel); if (t) MIC.base = t.value; } if (MIC && MIC.want) setTimeout(micStart, 250); else micMark(); };   // Chrome stops every minute or so: start again, keeping the text
    try { r.start(); } catch (e) { setTimeout(micStart, 400); }
  }
  function doMic(btn) {
    if (MIC && MIC.want) { const same = MIC.sel === btn.dataset.mic; micStop(); if (same) return; }
    if (!document.querySelector(btn.dataset.mic)) return; MIC = { sel: btn.dataset.mic, want: true, timer: setInterval(micMark, 600) }; micMark(); micStart();
  }
  document.addEventListener('click', (e) => { const b = e.target.closest && e.target.closest('.wl-mic'); if (b) { e.preventDefault(); doMic(b); }
    const lg = e.target.closest && e.target.closest('.wl-lang'); if (lg) { e.preventDefault(); const nx = micLang() === 'hi-IN' ? 'en-IN' : 'hi-IN'; try { localStorage.setItem('wl_lang', nx); } catch (x) { }
      document.querySelectorAll('.wl-lang').forEach((x) => { x.textContent = nx === 'hi-IN' ? 'हिं Hindi' : 'EN English'; }); if (MIC && MIC.want) { const sel = MIC.sel; micStop(); const btn = document.querySelector(`.wl-mic[data-mic="${sel}"]`); if (btn) doMic(btn); } } });

  function manage(el) {
    css(); let D = null, plan = null, conv = [], busy = false, keepOff = {};
    el.innerHTML = '<div class="wl"><div class="wl-empty">Loading lists…</div></div>';
    async function load() { try { D = await api.get('wl.list'); } catch (e) { el.innerHTML = `<div class="wl"><div class="wl-err">${esc(e.message)}</div></div>`; return; } draw(); }
    function draw() {
      const W = el.firstElementChild; const ls = D.lists || [], adm = D.admin;
      let h = adm ? `<div class="wl-ai"><h3>✨ Ask Claude to make your lists</h3><p>Write in plain words what you sell and which lists you want — Claude reads your live Shopify sales and makes the groups, the lists and the customer tags. Example: <i>“We sell to gift buyers and wholesalers. Winter and party sell most. Make top 5 and top 10 for each season and occasion, best sellers, and lists under ₹999 and ₹1499.”</i> Claude can also change or delete lists: <i>“remove the price from all lists and add long lasting”, “show the full original names”, “call Jpg Layman Elixir ‘Le Male Elixir’”, “delete the top 20 lists”.</i></p>
        <textarea id="wlSum" placeholder="What lists do you want? (summary) — type or speak"></textarea><div class="wl-row">${micBtn('#wlSum')}<button type="button" class="wl-btn p" id="wlPlan">✨ Create lists</button><button type="button" class="wl-btn" id="wlSug">💡 Suggest more</button><button type="button" class="wl-btn" id="wlNew">＋ Make one by hand</button><button type="button" class="wl-btn" id="wlAll" title="Rebuild every automatic list from live sales now">↻ Fetch all now</button><button type="button" class="wl-btn" id="wlOrig" title="Look up the real original perfume on the internet for the ones Shopify does not name">🌐 Find originals online</button></div><div id="wlPlanOut"></div>${origLine(D.orig)}</div>` : '';
      if (!ls.length) h += `<div class="wl-empty">No lists yet.${adm ? ' Ask Claude above, or make one by hand.' : ' The admin makes them.'}</div>`;
      const groups = {}; ls.forEach((l) => { (groups[l.grp] = groups[l.grp] || []).push(l); });
      Object.keys(groups).forEach((g) => { h += `<div class="wl-grp">${esc(g)}</div><div class="wl-grid">` + groups[g].map((l) => card(l, adm)).join('') + '</div>'; });
      W.innerHTML = h; wire(); renderPlan();   // the proposal and the conversation survive a redraw
      Object.keys(DRAFT).forEach((k) => { const t = W.querySelector(k); if (t && DRAFT[k] && !t.value) t.value = DRAFT[k]; });   // typed / spoken text survives a redraw
    }
    function card(l, adm) {
      const o = l.opts || { show: ['longevity'], name: 'short' }; const SH = { longevity: 'long lasting', price: 'price', notes: 'notes', gender: 'for', link: 'link' }, NM = { short: 'short names', full: 'full website names', original: 'real original names', inspired: '“inspired by” names', ours: 'our names' };
      return `<div class="wl-card" data-l="${l.id}"><h4><span>${esc(l.name)}</span>${adm ? `<span style="display:flex;gap:4px"><button type="button" class="wl-btn s" data-edit="${l.id}">Edit</button><button type="button" class="wl-btn s" data-del="${l.id}" title="Delete this list" style="color:#b42318">🗑</button></span>` : ''}</h4>
        <div class="wl-meta" style="margin-top:-2px">Shows: ${o.show && o.show.length ? o.show.map((k) => SH[k] || k).join(' · ') : 'names only'} · ${NM[o.name] || 'short names'}${o.rename && Object.keys(o.rename).length ? ' · ' + Object.keys(o.rename).length + ' renamed' : ''}</div>
        <div class="wl-meta">${l.manual ? 'Fixed by hand' : (l.words ? esc(l.words) : 'best sellers') + (l.who ? ' · ' + esc(l.who) : '') + (l.pmax ? ' · up to ' + rs(l.pmax) : '') + (l.pmin ? ' · from ' + rs(l.pmin) : '') + ' · top ' + l.n} · updated ${esc(ago(l.refreshed_at))} · sent ${l.sent} · <b>${l.orders} ordered</b></div>
        <div class="wl-items">${itemsHtml(l.view, l, adm)}</div>
        ${adm ? `<div class="wl-say"><input class="wl-in" data-say="${l.id}" placeholder="✨ Tell Claude: fetch now · remove X · add Y · make it 10 · only under 1000">${micBtn('[data-say="' + l.id + '"]', true)}<button type="button" class="wl-btn p s" data-go="${l.id}">Go</button></div><div data-out="${l.id}"></div>` : ''}</div>`;
    }
    function wire() {
      const W = el.firstElementChild; const q = (s) => W.querySelector(s);
      if (q('#wlPlan')) q('#wlPlan').onclick = () => runPlan('wl.plan');
      if (q('#wlSug')) q('#wlSug').onclick = () => runPlan('wl.suggest');
      if (q('#wlNew')) q('#wlNew').onclick = () => editForm(null);
      if (q('#wlAll')) q('#wlAll').onclick = async function () { this.disabled = true; try { const r = await api.post('wl.refresh', {}); toast(r.rebuilt + ' lists rebuilt from live sales'); await load(); } catch (e) { toast(e.message, true); this.disabled = false; } };
      if (q('#wlOrig')) q('#wlOrig').onclick = async function () { const b = this, t = b.textContent; b.disabled = true; b.textContent = '🌐 Looking up… (up to 3 min)';
        try { const r = await api.post('wl.originals', {}); toast(r.asked ? `Found ${r.found} of ${r.asked} real originals online${r.left ? ' · ' + r.left + ' still unknown — press again' : ''}` : (r.filled ? `Every original is known · filled long lasting / notes for ${r.filled} perfumes from their original${r.fill_left ? ' · ' + r.fill_left + ' more — press again' : ''}` : 'Every perfume has its original, long lasting and notes')); await load(); } catch (e) { toast(e.message, true); } finally { b.disabled = false; b.textContent = t; } };
      if (q('#wlSync')) q('#wlSync').onclick = async function () { const b = this; b.disabled = true; b.textContent = '↻ Reading every product from Shopify… (1-2 min)';
        try { const r = await api.post('wa.prod_sync', {}); if (r && r.error) throw new Error(r.error); toast('Shopify read again ✓ — check the line below'); await load(); } catch (e) { toast(e.message, true); b.disabled = false; b.textContent = '↻ Read Shopify fields now'; } };
      if (q('#wlOk')) q('#wlOk').onchange = async function () { this.disabled = true; try { const r = await api.post('wl.orig_key', { key: this.value }); toast(`${r.found} perfumes now take their original name from ${this.value || 'the field found by its name'}`); await load(); } catch (e) { toast(e.message, true); this.disabled = false; } };
      W.querySelectorAll('[data-edit]').forEach((b) => { b.onclick = () => editForm(D.lists.find((x) => x.id === +b.dataset.edit)); });
      W.querySelectorAll('[data-del]').forEach((b) => { b.onclick = async () => { const l = D.lists.find((x) => x.id === +b.dataset.del); if (!l || !confirm('Delete the list “' + l.name + '”?')) return; try { await api.post('wl.delete', { id: l.id }); toast('Deleted'); await load(); } catch (e) { toast(e.message, true); } }; });
      W.querySelectorAll('[data-go]').forEach((b) => { b.onclick = () => aiEdit(+b.dataset.go); });
      W.querySelectorAll('[data-say]').forEach((i) => { i.onkeydown = (e) => { if (e.key === 'Enter') aiEdit(+i.dataset.say); }; });
      W.querySelectorAll('.wl-card .wl-items').forEach((box) => { box.onclick = (e) => { const b = e.target.closest('button'); if (!b) return; const l = D.lists.find((x) => x.id === +box.closest('[data-l]').dataset.l); quickChange(l, b); }; });
    }
    async function quickChange(l, b) {   // 📌 pin · ↑ move up (pins it in that place) · ✕ never in this list
      const x = Object.assign({}, l); x.pins = (l.pins || []).slice(); x.drops = (l.drops || []).slice();
      if (b.dataset.ren) { const id = +b.dataset.ren, it = (l.view || []).find((v) => v.id === id); const nm = prompt('Name for this perfume in “' + l.name + '” (empty = back to automatic):', it ? it.name : ''); if (nm === null) return;
        x.opts = JSON.parse(JSON.stringify(l.opts || {})); x.opts.rename = x.opts.rename || {}; if (nm.trim()) x.opts.rename[id] = nm.trim(); else delete x.opts.rename[id]; }
      else if (b.dataset.drop) { const id = +b.dataset.drop; x.drops.push(id); x.pins = x.pins.filter((p) => p !== id); if (x.manual) x.items = l.items.filter((p) => p !== id); }
      else if (b.dataset.pin) { const id = +b.dataset.pin; x.pins = x.pins.includes(id) ? x.pins.filter((p) => p !== id) : x.pins.concat(id); }
      else if (b.dataset.up) { const id = +b.dataset.up, order = l.items.slice(), i = order.indexOf(id); if (i > 0) { order.splice(i, 1); order.splice(i - 1, 0, id); } if (x.manual) x.items = order; else x.pins = order.slice(0, Math.max(i, x.pins.length) + 1).filter((p) => p); }
      try { const r = await api.post('wl.save', { list: x }); Object.assign(l, r.list); draw(); } catch (e) { toast(e.message, true); }
    }
    // ✨ the main Claude box is a conversation: you write → Claude says what it understood (+ questions) and proposes → you correct (type or 🎤) → … → Save the ticked changes
    async function runPlan(act, fix) {
      const out = el.querySelector('#wlPlanOut'); if (!out) return;
      if (fix === undefined) { const sum = ((el.querySelector('#wlSum') || {}).value || '').trim(); if (act === 'wl.plan' && sum.length < 8) { toast('Write a few words about what you want first.', true); return; } conv = act === 'wl.plan' ? [sum] : []; plan = null; }
      else { if (!fix.trim()) { toast('Tell Claude what to change.', true); return; } conv.push(fix.trim()); delete DRAFT['#wlFix']; }
      const prev = plan ? { understood: plan.understood, groups: plan.groups.map((g) => ({ name: g.name, lists: g.lists.map((l) => ({ name: l.name, words: l.words, n: l.n, who: l.who, pmin: l.pmin, pmax: l.pmax, intro: l.intro, outro: l.outro, opts: l.opts, pins: l.pins, drops: l.drops })) })), update: (plan.update || []).map((l) => ({ id: l.id, name: l.name, grp: l.grp, words: l.words, n: l.n, who: l.who, opts: l.opts, intro: l.intro, outro: l.outro, pins: l.pins, drops: l.drops, manual: l.manual, items: l.items })), delete: (plan.delete || []).map((l) => l.id) } : null;
      busy = true; renderPlan();
      try { plan = await api.post(act, { summary: conv[0] || '', turns: conv, prev }); } catch (e) { busy = false; if (fix !== undefined) conv.pop(); renderPlan(); toast(e.message, true); return; }
      busy = false; renderPlan();
    }
    function renderPlan() {
      const out = el.querySelector('#wlPlanOut'); if (!out) return;
      if (!plan && !busy) { out.innerHTML = ''; return; }
      let h = conv.length > 1 || (conv.length && plan) ? '<div class="wl-conv">' + conv.map((t) => `<div class="wl-me">${esc(t)}</div>`).join('') + '</div>' : '';
      if (busy) { out.innerHTML = h + '<div class="wl-und">✨ Claude is reading your lists and sales…</div>'; return; }
      h += `<div class="wl-und"><b>✨ Claude understood:</b> ${esc(plan.understood || '')}${plan.tags && plan.tags.length ? '<br><b>Customer tags it makes:</b> ' + plan.tags.map((t) => `<span class="wl-chip">${esc(t)}</span>`).join('') : ''}${(plan.questions || []).length ? '<div style="margin-top:6px"><b>❓ Not sure — please tell me:</b>' + plan.questions.map((q) => `<div style="display:flex;gap:6px;align-items:center;margin-top:4px"><span style="flex:1">${esc(q)}</span><button type="button" class="wl-btn s" data-ans="${esc(q)}">Answer</button></div>`).join('') + '</div>' : ''}</div>`;
      plan.groups.forEach((g, gi) => { h += `<div class="wl-grp">${esc(g.name)} · new</div><div class="wl-grid">` + g.lists.map((l, li) => `<div class="wl-card"><h4><label style="display:flex;gap:8px;align-items:center;flex:1"><input type="checkbox" data-keep="${gi}:${li}" ${keepOff['k' + gi + ':' + li] ? '' : 'checked'} style="width:18px;height:18px"><span>${esc(l.name)}</span></label></h4><div class="wl-meta">${esc(l.words || 'best sellers')}${l.who ? ' · ' + esc(l.who) : ''}${l.pmax ? ' · up to ' + rs(l.pmax) : ''} · top ${l.n}</div>${itemsHtml(l.preview, l, false)}<div class="wl-meta" style="margin-top:6px">${esc(l.intro || '')}</div></div>`).join('') + '</div>'; });
      if ((plan.update || []).length) h += `<div class="wl-grp">Changes to your lists</div><div class="wl-grid">` + plan.update.map((l, i) => `<div class="wl-card"><h4><label style="display:flex;gap:8px;align-items:center;flex:1"><input type="checkbox" data-upd="${i}" ${keepOff['u' + i] ? '' : 'checked'} style="width:18px;height:18px"><span>${esc(l.name)}</span></label></h4><div class="wl-meta">${esc(l.what || 'changed')}</div>${itemsHtml(l.preview, l, false)}</div>`).join('') + '</div>';
      if ((plan.products || []).length) h += `<div class="wl-grp">Perfume corrections (every list and chat)</div><div class="wl-card">` + plan.products.map((f, i) => `<label style="display:flex;gap:8px;align-items:center;padding:4px 0"><input type="checkbox" data-fx="${i}" ${keepOff['f' + i] ? '' : 'checked'} style="width:18px;height:18px"><span><b>${esc(f.name)}</b> — ${esc(f.what)}</span></label>`).join('') + '</div>';
      if ((plan.delete || []).length) h += `<div class="wl-grp">Delete</div><div class="wl-card">` + plan.delete.map((l, i) => `<label style="display:flex;gap:8px;align-items:center;padding:4px 0"><input type="checkbox" data-dl="${i}" ${keepOff['d' + i] ? '' : 'checked'} style="width:18px;height:18px"> 🗑 ${esc(l.name)} <span class="wl-meta" style="margin:0">(${esc(l.grp)})</span></label>`).join('') + '</div>';
      if (!plan.groups.length && !(plan.update || []).length && !(plan.delete || []).length && !(plan.products || []).length) h += '<div class="wl-empty">Nothing to change yet.</div>';
      h += `<div class="wl-fix"><b>Not right? Tell Claude what to change</b> — it rebuilds the proposal; nothing is saved until you press Save.<textarea id="wlFix" placeholder="e.g. keep the price only in the budget lists · call #12 Le Male Elixir · don't delete the top 20 lists — type or speak"></textarea><div class="wl-row">${micBtn('#wlFix')}<button type="button" class="wl-btn p" id="wlFixGo">✨ Send to Claude</button></div></div>`;
      h += '<div class="wl-row"><button type="button" class="wl-btn g" id="wlKeep">Save the ticked changes</button><button type="button" class="wl-btn" id="wlDrop">Discard</button></div>';
      out.innerHTML = h;
      const fx = out.querySelector('#wlFix'); if (fx && DRAFT['#wlFix']) fx.value = DRAFT['#wlFix']; if (fx) fx.oninput = () => { DRAFT['#wlFix'] = fx.value; };
      out.querySelectorAll('[data-keep],[data-upd],[data-dl],[data-fx]').forEach((c) => { c.onchange = () => { const k = c.dataset.keep !== undefined ? 'k' + c.dataset.keep : c.dataset.upd !== undefined ? 'u' + c.dataset.upd : c.dataset.fx !== undefined ? 'f' + c.dataset.fx : 'd' + c.dataset.dl; keepOff[k] = !c.checked; }; });
      out.querySelectorAll('[data-ans]').forEach((b) => { b.onclick = () => { const t = out.querySelector('#wlFix'); t.value = (t.value ? t.value + '\n' : '') + 'About “' + b.dataset.ans + '”: '; DRAFT['#wlFix'] = t.value; t.focus(); t.setSelectionRange(t.value.length, t.value.length); }; });
      out.querySelector('#wlFixGo').onclick = () => { keepOff = {}; runPlan('wl.plan', (out.querySelector('#wlFix') || {}).value || ''); };
      out.querySelector('#wlDrop').onclick = () => { plan = null; conv = []; keepOff = {}; delete DRAFT['#wlFix']; renderPlan(); };
      out.querySelector('#wlKeep').onclick = async function () {
        const groups = plan.groups.map((g, gi) => ({ name: g.name, lists: g.lists.filter((l, li) => !keepOff['k' + gi + ':' + li]) })).filter((g) => g.lists.length);
        const update = (plan.update || []).filter((l, i) => !keepOff['u' + i]), del = (plan.delete || []).filter((l, i) => !keepOff['d' + i]).map((l) => l.id), products = (plan.products || []).filter((f, i) => !keepOff['f' + i]);
        if (del.length && !confirm('Delete ' + del.length + ' list' + (del.length === 1 ? '' : 's') + '?')) return;
        this.disabled = true; try { const r = await api.post('wl.plan_save', { groups, update, delete: del, products }); toast(([r.saved ? r.saved + ' new' : '', r.updated ? r.updated + ' changed' : '', r.fixed ? r.fixed + ' perfume' + (r.fixed === 1 ? '' : 's') + ' corrected' : '', r.deleted ? r.deleted + ' deleted' : ''].filter(Boolean).join(' · ') || 'Nothing') + ' ✓'); plan = null; conv = []; keepOff = {}; ['#wlSum', '#wlFix'].forEach((k) => { delete DRAFT[k]; }); const t = el.querySelector('#wlSum'); if (t) t.value = ''; await load(); } catch (e) { toast(e.message, true); this.disabled = false; }
      };
    }
    async function aiEdit(id) {
      const inp = el.querySelector(`[data-say="${id}"]`), out = el.querySelector(`[data-out="${id}"]`), say = (inp.value || '').trim(); if (!say) { inp.focus(); return; }
      out.innerHTML = '<div class="wl-und">✨ Claude is working on it…</div>'; let r;
      try { r = await api.post('wl.ai_edit', { id, say }); } catch (e) { out.innerHTML = `<div class="wl-err">${esc(e.message)}</div>`; return; }
      delete DRAFT[`[data-say="${id}"]`];   // used: a redraw starts empty again
      const li = (v) => '<ol>' + (v || []).map((x) => `<li>${esc(x.name)}${x.extra ? ' · ' + esc(x.extra) : ''}</li>`).join('') + '</ol>';
      if (r.delete) { out.innerHTML = `<div class="wl-und"><b>Claude understood:</b> ${esc(r.understood || '')}</div><div class="wl-row"><button type="button" class="wl-btn s" data-okdel style="color:#b42318">🗑 Delete this list</button><button type="button" class="wl-btn s" data-no>Keep it</button></div>`;
        out.querySelector('[data-no]').onclick = () => { out.innerHTML = ''; }; out.querySelector('[data-okdel]').onclick = async () => { try { await api.post('wl.delete', { id }); toast('Deleted'); await load(); } catch (e) { toast(e.message, true); } }; return; }
      out.innerHTML = `<div class="wl-und"><b>Claude understood:</b> ${esc(r.understood || '')}${(r.products || []).length ? '<br><b>Perfume corrections (every list and chat):</b> ' + r.products.map((f) => esc(f.name + ' — ' + f.what)).join(' · ') : ''}</div><div class="wl-ba"><div><b>BEFORE</b>${li(r.before)}</div><div><b>AFTER</b>${li(r.after)}</div></div><div class="wl-row"><button type="button" class="wl-btn g s" data-ok>Save</button><button type="button" class="wl-btn s" data-no>Discard</button></div>`;
      out.querySelector('[data-no]').onclick = () => { out.innerHTML = ''; };
      out.querySelector('[data-ok]').onclick = async function () { this.disabled = true; try { await api.post('wl.save', { list: r.list, products: r.products || [] }); toast('List saved ✓'); await load(); } catch (e) { toast(e.message, true); this.disabled = false; } };
    }
    function editForm(l) {
      const W = el.firstElementChild; const x = l || { grp: 'Lists', name: '', words: '', n: 5, who: '', pmin: 0, pmax: 0, intro: '', outro: '', links: false, auto: true, opts: { show: ['longevity'], name: 'short', hide_samples: true } };
      const host = l ? W.querySelector(`[data-l="${l.id}"]`) : W.querySelector('#wlPlanOut');
      const box = document.createElement('div'); box.className = 'wl-f';
      const words = (D.words || []).filter((w) => w.perfumes > 0).map((w) => `<option value="${esc(w.word)}">${esc(w.label)} (${w.perfumes})</option>`).join('');
      box.innerHTML = `<div><label>List name</label><input data-k="name" value="${esc(x.name)}" placeholder="Top 5 winter"></div><div><label>Group</label><input data-k="grp" value="${esc(x.grp)}" placeholder="Season"></div>
        <div class="full"><label>Words (what kind of perfumes) — add from the list or type</label><input data-k="words" value="${esc(x.words)}" placeholder="winter party"><select data-addw style="margin-top:4px"><option value="">＋ add a word…</option>${words}<option value="best-sellers">Best sellers</option></select></div>
        <div><label>How many</label><input data-k="n" type="number" min="1" max="100" value="${x.n}"></div><div><label>For</label><select data-k="who"><option value="">Everyone</option><option value="men" ${x.who === 'men' ? 'selected' : ''}>Men</option><option value="women" ${x.who === 'women' ? 'selected' : ''}>Women</option><option value="unisex" ${x.who === 'unisex' ? 'selected' : ''}>Unisex</option></select></div>
        <div><label>Price from ₹ (0 = any)</label><input data-k="pmin" type="number" min="0" value="${x.pmin || 0}"></div><div><label>Price up to ₹ (0 = any)</label><input data-k="pmax" type="number" min="0" value="${x.pmax || 0}"></div>
        <div class="full"><label>First line of the message ({name} = customer's name)</label><input data-k="intro" value="${esc(x.intro)}" placeholder="Hi {name}! Our top winter picks ❄️"></div>
        <div class="full"><label>Last line</label><input data-k="outro" value="${esc(x.outro)}" placeholder="Reply with the number you like 😊"></div>
        <div class="full"><label>After each perfume name</label><div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:4px">${[['longevity', '⏳ Long lasting'], ['price', '₹ Price'], ['notes', 'Notes'], ['gender', 'For (men / women)'], ['link', 'Shop link']].map(([k, t]) => `<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-show="${k}" ${((x.opts || {}).show || ['longevity']).includes(k) ? 'checked' : ''} style="width:18px;height:18px">${t}</label>`).join('')}</div></div>
        <div><label>Perfume names</label><select data-nm>${[['short', 'Short (website name, no shop tail)'], ['original', 'Real original perfume name'], ['full', 'Full website title'], ['inspired', 'Short + “inspired by …”'], ['ours', 'Our own name']].map(([k, t]) => `<option value="${k}" ${((x.opts || {}).name || 'short') === k ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
        <label style="display:flex;gap:8px;align-items:center;align-self:end"><input type="checkbox" data-hs ${(x.opts || {}).hide_samples === false ? '' : 'checked'} style="width:18px;height:18px"> Never list samples / complimentary</label>
        ${(x.opts || {}).rename && Object.keys(x.opts.rename).length ? `<div class="full wl-meta">${Object.keys(x.opts.rename).length} perfume(s) renamed by hand — <a href="#" data-clrren>clear</a></div>` : ''}
        <label class="full" style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-k="auto" ${x.auto ? 'checked' : ''} style="width:18px;height:18px"> Refresh by itself every day from live sales</label>
        <div class="full wl-row"><button type="button" class="wl-btn g" data-save>Save</button><button type="button" class="wl-btn" data-cancel>Cancel</button>${l ? '<button type="button" class="wl-btn" data-del style="margin-left:auto;color:#b42318">Delete list</button>' : ''}</div><div class="full" data-err></div>`;
      host.appendChild(box); box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      box.querySelector('[data-addw]').onchange = function () { const i = box.querySelector('[data-k="words"]'); if (this.value && !(' ' + i.value + ' ').includes(' ' + this.value + ' ')) i.value = (i.value + ' ' + this.value).trim(); this.value = ''; };
      box.querySelector('[data-cancel]').onclick = () => box.remove();
      const clr = box.querySelector('[data-clrren]'); if (clr) clr.onclick = (e) => { e.preventDefault(); box.dataset.clr = '1'; clr.parentNode.textContent = 'Hand-made names will be cleared when you save.'; };
      if (box.querySelector('[data-del]')) box.querySelector('[data-del]').onclick = async () => { if (!confirm('Delete the list “' + l.name + '”?')) return; try { await api.post('wl.delete', { id: l.id }); await load(); } catch (e) { toast(e.message, true); } };
      box.querySelector('[data-save]').onclick = async function () {
        const v = {}; box.querySelectorAll('[data-k]').forEach((i) => { v[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.value; });
        const opts = Object.assign({}, (l && l.opts) || {}, { show: [...box.querySelectorAll('[data-show]')].filter((c) => c.checked).map((c) => c.dataset.show), name: box.querySelector('[data-nm]').value, hide_samples: box.querySelector('[data-hs]').checked });
        if (box.dataset.clr) opts.rename = {};
        const list = Object.assign({}, l || {}, v, { n: +v.n || 5, pmin: +v.pmin || 0, pmax: +v.pmax || 0, opts }); this.disabled = true;
        try { await api.post('wl.save', { list }); toast('List saved ✓'); await load(); } catch (e) { box.querySelector('[data-err]').innerHTML = `<div class="wl-err">${esc(e.message)}</div>`; this.disabled = false; }
      };
    }
    load();
    return { reload: load };
  }

  // ------------------------------------------------------------------ the chat's 📋 Lists sheet
  async function sheet(body, o) {
    css(); body.classList.add('wl', 'wl-sh'); body.innerHTML = '<div class="wl-empty">Loading lists…</div>'; let d;
    try { d = await api.get('wl.for_chat', { phone: o.phone }); } catch (e) { body.innerHTML = `<div class="wl-err">${esc(e.message)}</div>`; return; }
    const ls = d.lists || [];
    function list() {
      body.innerHTML = (ls.length ? ls.map((l) => `<button type="button" class="wl-pick ${l.match ? 'm' : ''}" data-id="${l.id}"><span style="font-size:20px">${l.match ? '✨' : '📋'}</span><b>${esc(l.name)}<br><small>${l.count} perfumes · ${esc(l.grp)}${l.orders ? ' · ' + l.orders + ' ordered after it' : ''}</small></b><span>›</span></button>`).join('')
        : '<div class="wl-empty">No lists yet. The admin makes them in WhatsApp → Lists.</div>');
      body.onclick = (e) => { const b = e.target.closest('[data-id]'); if (b) one(+b.dataset.id); };
    }
    async function one(id) {
      body.onclick = null; body.innerHTML = '<div class="wl-empty">Loading…</div>'; let p;
      try { p = await api.get('wl.preview', { id, phone: o.phone }); } catch (e) { body.innerHTML = `<div class="wl-err">${esc(e.message)}</div>`; return; }
      const cid = 'l' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
      body.innerHTML = `<div style="font-weight:800;margin-bottom:6px">${esc(p.list.name)}</div><textarea id="wlTx">${esc(p.text)}</textarea><div style="font-size:12px;color:#6b7684;margin:4px 0 8px">You can change the words before sending.</div><label style="display:flex;gap:8px;align-items:center;font-size:13px;font-weight:700;margin:0 0 8px"><input type="checkbox" id="wlPh" style="width:18px;height:18px"> 📸 Also send the perfume photos (one picture per 6 perfumes)</label><div id="wlE"></div>
        <div class="wl-row"><button type="button" class="wl-btn" id="wlB">‹ Lists</button><button type="button" class="wl-btn g" id="wlS" style="flex:1;min-height:44px">Send to customer</button></div>`;
      body.querySelector('#wlB').onclick = list;
      body.querySelector('#wlS').onclick = async function () {
        this.disabled = true; this.textContent = 'Sending…';
        try { await api.post('wl.send', { phone: o.phone, id, text: body.querySelector('#wlTx').value, cid, photos: body.querySelector('#wlPh').checked ? 1 : 0 }); toast('List sent ✓'); if (o.close) o.close(); if (o.onSent) o.onSent(); }
        catch (e) { body.querySelector('#wlE').innerHTML = `<div class="wl-err">${esc(e.message)}</div>`; this.disabled = false; this.textContent = 'Send to customer'; }
      };
    }
    list();
  }

  window.WaLists = { manage, sheet };
  if (window.WaPanels && WaPanels.add) WaPanels.add('lists', (el) => manage(el));
})();
