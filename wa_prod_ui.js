/* wa_prod_ui.js — "Find a perfume": a bottom sheet to search the products from the website and send photos into a chat.
 * Shared by the manager chat screen (wa_inbox.js) and the salesperson phone app (wa_app.js). Needs the global `api`.
 *
 *   WaProd.open({ phone, name, canSend, whyNot, onSent })   open the sheet (canSend=false -> look only, whyNot = the reason shown)
 *   WaProd.attached(phone)                                  pictures the prepared reply carries   -> { items:[products + Photos-tab photos (kind 'lib', id 'lib:<n>')], products:[…], lib:[…], flag, skipped }
 *   WaProd.send(phone, ids, mode, force)                    send photos; ids = product ids or 'lib:<n>' (mode: photo | photo_notes | all | why) -> { sent, skipped, errors }
 *   WaProd.pendHtml(products)                               small cards for the "photos follow the text" row (data-rm = remove button)
 *   WaProd.imgFor(id)                                       Promise<url> of the photo behind a "prod:<id>" chat picture
 *   WaProd.zoom(urls)                                       full-screen photo
 */
(function () {
  'use strict';
  if (window.WaProd) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SEAS = { winter: 'Winter', summer: 'Summer', spring: 'Spring', fall: 'Fall' };
  const MODES = [['photo', 'Photo'], ['photo_notes', '+ Notes'], ['all', 'Everything']];
  const MAXSEL = 6;
  let mode = 'photo_notes'; try { const m = localStorage.getItem('wp_mode'); if (MODES.some((x) => x[0] === m)) mode = m; } catch (e) { }
  const sized = (u, w) => (/^https:\/\/cdn\.shopify\.com\//i.test(u || '') ? u + (u.indexOf('?') < 0 ? '?' : '&') + 'width=' + w : (u || ''));

  const CSS = `
  .wpr-nb{flex:none;border:1px solid #c7e9d6;background:#ecfdf3;color:#15803d;border-radius:999px;padding:0 12px;min-height:38px;font-weight:700;font-size:13px;cursor:pointer;margin-left:6px;white-space:nowrap}
  .wpr-nf[hidden]{display:none !important}.wpr-sb{display:flex !important;align-items:center}.wpr-sb input{flex:1 1 auto;min-width:0}
  .wpr-nf{padding:8px 12px 10px;border-bottom:1px solid #e9edef;background:#f8fafc;display:flex;flex-direction:column;gap:8px}
  .wpr-nf textarea,.wpr-nf input{width:100%;box-sizing:border-box;border:1px solid #d1d7db;border-radius:10px;padding:8px 10px;font:inherit;font-size:14px;background:#fff}
  .wpr-n3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.wpr-nf label{display:flex;flex-direction:column;gap:3px;font-size:11.5px;font-weight:700;color:#54656f}
  @media (max-width:560px){.wpr-n3{grid-template-columns:1fr}}
  .wpr-mx{font-size:12.5px;margin:3px 0;padding:4px 8px;border-radius:8px;line-height:1.35}.wpr-mx b{font-weight:800}.wpr-mx span{color:#8696a0}.wpr-mx.g{background:#e7f8ee;color:#13703a}.wpr-mx.y{background:#fff8e6;color:#8a5a00}.wpr-mx.o{background:#f1f5f9;color:#475569}

  .wpr-ov{position:fixed; inset:0; z-index:10050; background:#0008; display:flex; align-items:flex-end; justify-content:center; font-family:inherit;}
  .wpr-sh{background:#fff; color:#111b21; width:100%; max-width:600px; height:90vh; height:90dvh; border-radius:18px 18px 0 0; display:flex; flex-direction:column; box-shadow:0 -8px 30px #0004; overflow:hidden; position:relative;}
  @media (min-width:761px){ .wpr-ov{align-items:center;} .wpr-sh{border-radius:18px; height:min(82vh,780px);} }
  .wpr-hd{display:flex; align-items:center; justify-content:space-between; padding:12px 14px 6px;} .wpr-hd b{font-size:17px;}
  .wpr-x{width:40px; height:40px; border:0; border-radius:50%; background:#f0f2f5; color:#54656f; font-size:18px; cursor:pointer;}
  .wpr-sb{padding:4px 14px 6px; position:relative;} .wpr-sb input{width:100%; box-sizing:border-box; height:44px; border:1px solid #d1d7db; border-radius:22px; padding:0 40px 0 16px; font-size:16px; background:#f0f2f5; outline:none; -webkit-appearance:none; appearance:none;}
  .wpr-sb input:focus{border-color:#00a884; background:#fff;} .wpr-clr{position:absolute; right:20px; top:9px; width:30px; height:30px; border:0; background:none; color:#667781; font-size:16px; cursor:pointer;}
  .wpr-chips{display:flex; gap:8px; overflow-x:auto; padding:2px 14px 8px; -webkit-overflow-scrolling:touch; scrollbar-width:none;} .wpr-chips::-webkit-scrollbar{display:none;}
  .wpr-chip{flex:0 0 auto; height:34px; padding:0 14px; border:1px solid #d1d7db; border-radius:17px; background:#fff; color:#111b21; font-size:14px; cursor:pointer; white-space:nowrap;} .wpr-chip.on{background:#d9fdd3; border-color:#00a884; color:#005c4b; font-weight:600;}
  .wpr-note{padding:0 14px 6px; font-size:12.5px; color:#667781; min-height:16px;} .wpr-note.warn{color:#b3261e; background:#fff4f2; margin:0 14px 6px; padding:8px 10px; border-radius:10px;}
  .wpr-list{flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:0 8px 8px; overscroll-behavior:contain;}
  .wpr-row{display:grid; grid-template-columns:56px minmax(0,1fr) auto; gap:10px; align-items:start; padding:10px 6px; border-bottom:1px solid #f0f2f5;} .wpr-row.sel{background:#f0fbf7;}
  .wpr-th{width:56px; height:56px; padding:0; border:0; border-radius:10px; overflow:hidden; background:#e9edef; cursor:zoom-in;} .wpr-th img{width:100%; height:100%; object-fit:cover; display:block;}
  .wpr-mid{min-width:0;} .wpr-nm{font-size:15px; font-weight:600; line-height:1.25; word-break:break-word;} .wpr-rk{display:inline-block; margin-left:6px; font-size:11px; font-weight:600; color:#005c4b; background:#d9fdd3; border-radius:8px; padding:1px 7px; white-space:nowrap; vertical-align:1px;}
  .wpr-og{font-size:12px; color:#667781; margin-top:1px; word-break:break-word;} .wpr-nt{font-size:13px; color:#3b4a54; margin-top:3px; line-height:1.3;}
  .wpr-mt{display:flex; flex-wrap:wrap; gap:4px; margin-top:5px;} .wpr-ch{font-size:11.5px; background:#f0f2f5; color:#3b4a54; border-radius:8px; padding:2px 7px; white-space:nowrap;} .wpr-ch.lg{background:#e7f5ff; color:#0b5394;}
  .wpr-why{font-size:12px; color:#005c4b; margin-top:3px;}
  .wpr-act{display:flex; flex-direction:column; align-items:flex-end; gap:8px; min-width:66px;}
  .wpr-ck{display:flex; align-items:center; justify-content:center; width:44px; height:30px; cursor:pointer;} .wpr-ck input{width:22px; height:22px; accent-color:#008069; margin:0; cursor:pointer;}
  .wpr-snd{height:38px; min-width:66px; padding:0 14px; border:0; border-radius:19px; background:#008069; color:#fff; font-size:14px; font-weight:600; cursor:pointer;} .wpr-snd:disabled{opacity:.55; cursor:default;}
  .wpr-snd.done{background:#e7f5ee; color:#005c4b;} .wpr-snd.again{background:#f0f2f5; color:#3b4a54; font-weight:500;}
  .wpr-empty{text-align:center; color:#667781; padding:36px 22px; font-size:14px; line-height:1.5;} .wpr-empty b{display:block; color:#111b21; font-size:16px; margin-bottom:6px;}
  .wpr-btn{display:inline-block; margin-top:12px; height:42px; padding:0 20px; border:0; border-radius:21px; background:#008069; color:#fff; font-size:15px; font-weight:600; cursor:pointer;}
  .wpr-ft{border-top:1px solid #e9edef; padding:8px 12px calc(8px + env(safe-area-inset-bottom)); display:flex; align-items:center; gap:8px; background:#fff; flex-wrap:wrap;}
  .wpr-seg{display:flex; background:#f0f2f5; border-radius:18px; padding:2px;} .wpr-seg button{height:32px; padding:0 11px; border:0; border-radius:16px; background:none; color:#3b4a54; font-size:13px; cursor:pointer;} .wpr-seg button.on{background:#fff; color:#005c4b; font-weight:600; box-shadow:0 1px 2px #0002;}
  .wpr-go{margin-left:auto; height:42px; padding:0 20px; border:0; border-radius:21px; background:#008069; color:#fff; font-size:15px; font-weight:600; cursor:pointer;} .wpr-go:disabled{opacity:.55;}
  .wpr-toast{position:absolute; left:50%; bottom:76px; transform:translateX(-50%); max-width:90%; background:#111b21; color:#fff; font-size:13.5px; padding:9px 16px; border-radius:20px; opacity:0; pointer-events:none; transition:opacity .2s; text-align:center; z-index:3;} .wpr-toast.on{opacity:.96;} .wpr-toast.bad{background:#b3261e;}
  .wpr-lb{position:fixed; inset:0; z-index:10060; background:#000d; display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:zoom-out;} .wpr-lb img{max-width:94vw; max-height:84vh; border-radius:10px; background:#fff;} .wpr-lb small{color:#fff; margin-top:10px; font-size:13px;}
  .wpr-pend{display:flex; gap:8px; overflow-x:auto; padding:6px 10px; background:#f7f8f8; border-top:1px solid #e9edef; -webkit-overflow-scrolling:touch; scrollbar-width:none;} .wpr-pend::-webkit-scrollbar{display:none;}
  .wpr-pc{flex:0 0 auto; display:flex; align-items:center; gap:8px; width:210px; padding:5px 6px; background:#fff; border:1px solid #d9fdd3; border-radius:12px; position:relative;} .wpr-pc img{width:42px; height:42px; border-radius:8px; object-fit:cover; background:#e9edef;}
  .wpr-pc div{min-width:0; flex:1;} .wpr-pc b{display:block; font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;} .wpr-pc small{display:block; font-size:11px; color:#667781; line-height:1.25; max-height:2.5em; overflow:hidden;}
  .wpr-pcx{width:28px; height:28px; border:0; border-radius:50%; background:#f0f2f5; color:#54656f; font-size:13px; cursor:pointer; flex:0 0 auto;}
  .wpr-pt{font-size:11.5px; color:#667781; padding:6px 12px 0; background:#f7f8f8; border-top:1px solid #e9edef;} .wpr-pt.warn{color:#8a5a00; background:#fff8e1;}
  `;
  function css() { if (document.getElementById('wprCss')) return; const s = document.createElement('style'); s.id = 'wprCss'; s.textContent = CSS; document.head.appendChild(s); }
  const errMsg = (e) => (e && e.message) || 'Something went wrong. Try again.';

  // ---- data helpers -------------------------------------------------------------------------------------------------------------
  async function send(phone, ids, m, force) { return api.post('wa.prod_send', { phone, ids, mode: m || 'photo_notes', force: force ? 1 : 0 }); }
  async function attached(phone) { try { return await api.get('wa.prod_attached', { phone }); } catch (e) { return { products: [], lib: [], items: [], flag: '', skipped: '' }; } }
  const IMG = {};
  function imgFor(id) {
    id = String(id).replace(/\D/g, ''); if (!id) return Promise.resolve('');
    if (!IMG[id]) IMG[id] = api.get('wa.prod_search', { ids: id }).then((d) => { const it = (d.items || [])[0]; return it ? sized(it.photo, 600) : ''; }).catch(() => { delete IMG[id]; return ''; });
    return IMG[id];
  }
  function pendHtml(products) {
    css(); return (products || []).map((p, i) => '<div class="wpr-pc"><img src="' + esc(p.thumb || p.photo) + '" alt=""><div><b>' + esc(p.name) + '</b><small>' + esc(p.why || '') + '</small></div><button type="button" class="wpr-pcx" data-rm="' + i + '" aria-label="Do not send the photo of ' + esc(p.name) + '">✕</button></div>').join('');
  }
  function zoom(urls, i) {
    urls = (urls || []).filter(Boolean); if (!urls.length) return; i = i || 0; css();
    const lb = document.createElement('div'); lb.className = 'wpr-lb';
    const draw = () => { lb.innerHTML = '<img src="' + esc(sized(urls[i], 1000)) + '" alt="">' + (urls.length > 1 ? '<small>Photo ' + (i + 1) + ' of ' + urls.length + ' · tap for the next one</small>' : ''); };
    draw(); lb.onclick = (e) => { e.stopPropagation(); if (urls.length > 1 && i < urls.length - 1) { i++; draw(); } else lb.remove(); };
    document.body.appendChild(lb);
  }

  // ---- the sheet ----------------------------------------------------------------------------------------------------------------
  let cur = null;
  function close() { if (!cur) return; document.removeEventListener('keydown', cur.key); cur.ov.remove(); document.body.style.overflow = cur.bodyOv; cur = null; }
  function open(o) {
    o = o || {}; if (cur) close(); css();
    const S = { phone: o.phone || '', canSend: o.canSend !== false, whyNot: o.whyNot || '', q: '', seq: 0, items: [], sel: new Set(), sentIds: new Set(), chips: [], canEdit: false, busy: false, cache: {} };
    const ov = document.createElement('div'); ov.className = 'wpr-ov';
    ov.innerHTML = '<div class="wpr-sh" role="dialog" aria-modal="true" aria-label="Find a perfume">'
      + '<div class="wpr-hd"><b>Find a perfume' + (o.name ? ' <span style="font-weight:400;color:#667781;font-size:13px">for ' + esc(o.name) + '</span>' : '') + '</b><button type="button" class="wpr-x" aria-label="Close">✕</button></div>'
      + '<div class="wpr-sb"><input id="wpQ" type="search" inputmode="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Name, or: winter, office, gift…" aria-label="Search perfumes"><button type="button" class="wpr-clr" hidden aria-label="Clear">✕</button><button type="button" class="wpr-nb" id="wpNb" title="The customer sent notes (top / heart / base)? Find our closest perfumes">🌿 Notes</button></div><div class="wpr-nf" id="wpNf" hidden></div>'
      + '<div class="wpr-chips" id="wpChips"></div><div class="wpr-note" id="wpNote"></div><div class="wpr-list" id="wpList"><div class="wpr-empty">Loading…</div></div>'
      + '<div class="wpr-ft" id="wpFt" hidden></div><div class="wpr-toast" id="wpToast" role="status"></div></div>';
    document.body.appendChild(ov); const bodyOv = document.body.style.overflow; document.body.style.overflow = 'hidden';
    const $ = (id) => ov.querySelector('#' + id); const inp = $('wpQ'), list = $('wpList'), note = $('wpNote'), ft = $('wpFt'), chipsEl = $('wpChips'), clr = ov.querySelector('.wpr-clr');
    const key = (e) => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', key); cur = { ov, key, bodyOv };
    let toastT = 0; const toast = (m, bad) => { const t = $('wpToast'); t.textContent = m; t.className = 'wpr-toast on' + (bad ? ' bad' : ''); clearTimeout(toastT); toastT = setTimeout(() => { t.className = 'wpr-toast'; }, bad ? 4200 : 2200); };
    ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
    ov.querySelector('.wpr-x').onclick = close;
    // 🌿 find by notes: paste the customer's message (or type the notes) → our closest perfumes with a % match
    const nf = $('wpNf');
    $('wpNb').onclick = () => { if (!nf.hidden) { nf.hidden = true; return; }
      nf.innerHTML = '<textarea id="wpNp" rows="2" placeholder="Paste the customer’s message here — it fills the boxes below (or type)"></textarea>'
        + '<div class="wpr-n3"><label>Top notes<input id="wpNt" placeholder="e.g. Fresh citrus"></label><label>Heart notes<input id="wpNm" placeholder="e.g. Honeysuckle, magnolia"></label><label>Base notes<input id="wpNbs" placeholder="e.g. Amber, musk"></label></div>'
        + '<label class="wpr-n1">Perfume they named (optional)<input id="wpNn" placeholder="e.g. Armaf High Street"></label><button type="button" class="wpr-go" id="wpNgo">🔎 Find similar perfumes</button>';
      nf.hidden = false; const pa = $('wpNp');
      pa.oninput = () => { const t = pa.value; const grab = (re) => { const m = t.match(re); return m ? m[1].replace(/\s+/g, ' ').trim().replace(/[.,]$/, '') : ''; };
        const stop = '(?=\\b(?:top|heart|middle|mid|base)\\s*notes?\\b|$)';
        const tp = grab(new RegExp('top\\s*notes?\\s*:?\\s*([\\s\\S]*?)' + stop, 'i')), md = grab(new RegExp('(?:heart|middle|mid)\\s*notes?\\s*:?\\s*([\\s\\S]*?)' + stop, 'i')), bs = grab(new RegExp('base\\s*notes?\\s*:?\\s*([\\s\\S]*?)' + stop, 'i'));
        if (tp || md || bs) { $('wpNt').value = tp; $('wpNm').value = md; $('wpNbs').value = bs; } };
      $('wpNgo').onclick = async () => { const q = { top: $('wpNt').value, mid: $('wpNm').value, base: $('wpNbs').value, name: $('wpNn').value };
        if (!(q.top + q.mid + q.base + q.name).trim()) { toast('Type at least one note', true); return; } const my = ++S.seq; list.innerHTML = '<div class="wpr-empty">Matching…</div>';
        try { const d = await api.get('wa.prod_notes', q); if (my !== S.seq) return; S.items = d.items || []; S.q = ''; chipsEl.innerHTML = chipHtml();
          note.className = 'wpr-note'; note.textContent = '🌿 Closest to: ' + (d.asked || []).join(', ') + (d.no_notes ? ' · ' + d.no_notes + ' perfumes have no notes saved yet' : '');
          if (!S.items.length) list.innerHTML = '<div class="wpr-empty"><b>No close perfume</b>Try fewer or simpler notes (e.g. rose, oud, vanilla).</div>'; else { list.innerHTML = S.items.map(rowHtml).join(''); list.scrollTop = 0; } nf.hidden = true; }
        catch (e) { list.innerHTML = '<div class="wpr-empty"><b>Could not match</b>' + esc(errMsg(e)) + '</div>'; } };
      setTimeout(() => pa.focus(), 50); };
    if (!S.canSend) { note.className = 'wpr-note warn'; note.textContent = (S.whyNot || 'You can look at the perfumes, but photos cannot be sent in this chat now.'); }

    const chipHtml = () => S.chips.map((c) => '<button type="button" class="wpr-chip' + (S.q.toLowerCase() === c.q.toLowerCase() ? ' on' : '') + '" data-q="' + esc(c.q) + '">' + esc(c.label) + '</button>').join('');
    function drawFoot() {
      const n = S.sel.size; if (!S.canSend || !n) { ft.hidden = true; return; } ft.hidden = false;
      ft.innerHTML = '<div class="wpr-seg" role="group" aria-label="What to send">' + MODES.map((m) => '<button type="button" data-mode="' + m[0] + '" class="' + (mode === m[0] ? 'on' : '') + '">' + m[1] + '</button>').join('') + '</div><button type="button" class="wpr-go"' + (S.busy ? ' disabled' : '') + '>' + (S.busy ? 'Sending…' : 'Send ' + n) + '</button>';
    }
    function rowHtml(it) {
      const sel = S.sel.has(it.id), done = S.sentIds.has(it.id), again = !done && it.sent_recent;
      const og = it.original ? 'Original: ' + esc(it.original) + (it.original_site && it.original_site !== it.original ? ' <span title="Spelling on the website">(site: ' + esc(it.original_site) + ')</span>' : '') : (it.original_site ? 'Site spelling: ' + esc(it.original_site) : '');
      const seas = (it.seasons || []).map((s) => SEAS[s] || s).join(' · ');
      const chs = []; if (it.longevity) chs.push('<span class="wpr-ch lg">⏱ ' + esc(it.longevity) + '</span>'); if (seas) chs.push('<span class="wpr-ch">' + esc(seas) + '</span>'); (it.occasion_labels || []).filter((l) => !/^(winter|summer|spring|fall)$/i.test(l)).slice(0, 3).forEach((l) => chs.push('<span class="wpr-ch">' + esc(l) + '</span>'));
      if (it.gender) chs.push('<span class="wpr-ch">' + (it.gender === 'men' ? 'Men' : it.gender === 'women' ? 'Women' : 'Unisex') + '</span>');
      const act = S.canSend ? '<div class="wpr-act"><label class="wpr-ck" title="Select"><input type="checkbox" data-pick' + (sel ? ' checked' : '') + ' aria-label="Select ' + esc(it.name) + '"></label><button type="button" class="wpr-snd' + (done ? ' done' : again ? ' again' : '') + '" data-send' + (done || S.busy ? ' disabled' : '') + '>' + (done ? 'Sent ✓' : again ? 'Send again' : 'Send') + '</button></div>' : '<div></div>';
      return '<div class="wpr-row' + (sel ? ' sel' : '') + '" data-id="' + it.id + '"><button type="button" class="wpr-th" data-zoom aria-label="Show photo of ' + esc(it.name) + '"><img loading="lazy" src="' + esc(it.thumb || it.photo) + '" alt=""></button>'
        + '<div class="wpr-mid"><div class="wpr-nm">' + esc(it.name) + (it.rank ? '<span class="wpr-rk">' + esc(it.rank) + '</span>' : '') + '</div>' + (og ? '<div class="wpr-og">' + og + '</div>' : '')
        + (it.match != null ? '<div class="wpr-mx ' + (it.match >= 90 ? 'g' : it.match >= 70 ? 'y' : 'o') + '"><b>' + (it.inspired ? '🎯 Inspired by the perfume they named · ' : '') + it.match + '% match</b>' + ((it.matched || []).length ? ' · ✓ ' + (it.matched || []).map((m) => esc(m.replace(/^~/, '≈ '))).join(', ') : '') + ((it.missing || []).length ? '<span> · not in it: ' + it.missing.map(esc).join(', ') + '</span>' : '') + '</div>' : '')
        + (it.notes ? '<div class="wpr-nt">' + esc(it.notes) + '</div>' : '') + '<div class="wpr-mt">' + chs.join('') + '</div>' + (it.why ? '<div class="wpr-why">' + esc(it.why) + '</div>' : '') + (again ? '<div class="wpr-og">Sent to this customer in the last 24 hours</div>' : '') + '</div>' + act + '</div>';
    }
    function drawList(d) {
      const items = S.items;
      if (d && d.empty) {
        list.innerHTML = '<div class="wpr-empty"><b>No perfumes loaded yet</b>The list comes from your website. ' + (S.canEdit ? 'Press the button to load it now (takes about a minute).<br><button type="button" class="wpr-btn" data-refresh>Refresh now</button>' : 'Ask the admin to press “Refresh now” in Sales → Learn &amp; Setup → Products.') + '</div>'; return;
      }
      if (!items.length) { list.innerHTML = '<div class="wpr-empty"><b>No match</b>Try the perfume’s name, or words like winter, office, gift, date night.</div>'; return; }
      list.innerHTML = items.map(rowHtml).join('');
    }
    const parsedLine = (d) => { const p = d.parsed || {}; const a = (p.occ || []).slice(); if (p.who) a.push(p.who === 'men' ? 'for men' : p.who === 'women' ? 'for women' : 'unisex'); return a.length ? 'Showing: ' + a.join(', ') : (d.q ? '' : 'Best sellers'); };
    async function run(q) {
      S.q = q; clr.hidden = !q; chipsEl.innerHTML = chipHtml(); const my = ++S.seq;
      const hit = S.cache[q]; if (hit && Date.now() - hit.t < 60000) { S.items = hit.d.items || []; drawList(hit.d); if (S.canSend) note.textContent = parsedLine(hit.d); return; }
      try {
        const d = await api.get('wa.prod_search', { q, limit: 20, phone: S.phone }); if (my !== S.seq) return;
        S.cache[q] = { t: Date.now(), d }; S.lastD = d; S.items = d.items || []; drawList(d); if (S.canSend || !S.whyNot) note.textContent = parsedLine(d);
        list.scrollTop = 0;
      } catch (e) { if (my !== S.seq) return; list.innerHTML = '<div class="wpr-empty"><b>Could not load</b>' + esc(errMsg(e)) + '<br><button type="button" class="wpr-btn" data-retry>Try again</button></div>'; }
    }
    async function doSend(ids, force) {
      if (S.busy || !ids.length) return; S.busy = true; drawFoot(); list.querySelectorAll('[data-send]').forEach((b) => { b.disabled = true; });
      try {
        const r = await send(S.phone, ids, mode, force); const sent = r.sent || [], skip = r.skipped || [];
        sent.forEach((x) => { S.sentIds.add(x.id); S.sel.delete(x.id); });
        S.items.forEach((it) => { if (sent.some((x) => x.id === it.id)) it.sent_recent = true; });
        if (sent.length) { toast(sent.length === 1 ? 'Photo sent ✓' : sent.length + ' photos sent ✓'); if (o.onSent) { try { o.onSent(r); } catch (e) { } } }
        if (skip.length) { skip.forEach((x) => { const it = S.items.find((i) => i.id === x.id); if (it) it.sent_recent = true; }); if (!sent.length) toast(skip[0].why + ' Tap “Send again” to send it anyway.', true); }
        if ((r.errors || []).length) toast(r.errors[0].name + ': ' + r.errors[0].error, true);
      } catch (e) { toast(errMsg(e), true); }
      S.busy = false; drawFoot(); drawList({}); S.cache = {};
    }
    // events
    let deb = 0; inp.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => run(inp.value.trim()), 180); clr.hidden = !inp.value; });
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(deb); run(inp.value.trim()); inp.blur(); } });
    clr.onclick = () => { inp.value = ''; run(''); inp.focus(); };
    chipsEl.onclick = (e) => { const b = e.target.closest('[data-q]'); if (!b) return; inp.value = S.q.toLowerCase() === b.dataset.q.toLowerCase() ? '' : b.dataset.q; run(inp.value); };
    ft.onclick = (e) => {
      const m = e.target.closest('[data-mode]'); if (m) { mode = m.dataset.mode; try { localStorage.setItem('wp_mode', mode); } catch (x) { } drawFoot(); return; }
      if (e.target.closest('.wpr-go')) doSend(Array.from(S.sel), false);
    };
    list.onclick = async (e) => {
      if (e.target.closest('[data-refresh]')) {
        const b = e.target.closest('[data-refresh]'); b.disabled = true; b.textContent = 'Loading…';
        try { await api.post('wa.prod_sync', {}); S.cache = {}; await run(S.q); } catch (x) { toast(errMsg(x), true); b.disabled = false; b.textContent = 'Refresh now'; } return;
      }
      if (e.target.closest('[data-retry]')) { run(S.q); return; }
      const row = e.target.closest('.wpr-row'); if (!row) return; const id = +row.dataset.id; const it = S.items.find((i) => i.id === id); if (!it) return;
      if (e.target.closest('[data-zoom]')) { zoom(it.photos && it.photos.length ? it.photos : [it.photo]); return; }
      if (e.target.closest('[data-send]')) {
        const again = it.sent_recent && !S.sentIds.has(id);
        if (again && !confirm('This perfume was already sent to this customer in the last 24 hours. Send it again?')) return;
        doSend([id], again); return;
      }
      if (e.target.closest('[data-pick]')) {
        const cb = e.target.closest('[data-pick]'); if (cb.checked) { if (S.sel.size >= MAXSEL) { cb.checked = false; toast('Up to ' + MAXSEL + ' at a time.', true); return; } S.sel.add(id); } else S.sel.delete(id);
        row.classList.toggle('sel', cb.checked); drawFoot();
      }
    };
    // first load: chips + best sellers at once
    api.get('wa.prod_occ_list').then((d) => { S.chips = d.chips || []; S.canEdit = !!d.can_edit; chipsEl.innerHTML = chipHtml(); if (S.lastD && S.lastD.empty) drawList(S.lastD); }).catch(() => { });
    run('');
    if (window.innerWidth > 760) setTimeout(() => inp.focus(), 60);
    return { close };
  }

  window.WaProd = { open, close, send, attached, pendHtml, imgFor, zoom, sized };
})();
