/* ✏️ Update a manual order (WM-…): the salesperson removes / adds perfumes, chains or other products. The total, the payment and the address stay as they are.
 * Allowed until a tracking ID is given. Server: wmo.edit_get · wmo.update (wmo.php). Use: WmoEdit.open('WM-00041', { onSaved }) */
(function () {
  'use strict';
  if (window.WmoEdit) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (v) => '₹' + (Math.round(v * 100) % 100 === 0 ? Math.round(v).toLocaleString('en-IN') : v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  const CSS = `
  .we-ov{position:fixed; inset:0; z-index:100000; background:rgba(15,23,42,.55); display:flex; align-items:center; justify-content:center; padding:16px; font-family:inherit;}
  .we{background:#fff; color:#1f2937; width:min(600px,100%); max-height:92vh; border-radius:18px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 70px rgba(0,0,0,.3); font-size:14px;}
  .we-h{display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1px solid #e5e7eb;} .we-h b{flex:1; min-width:0; font-size:16px;} .we-h small{display:block; font-weight:500; color:#6b7280; font-size:12.5px; margin-top:1px;}
  .we-x{border:0; background:#f3f4f6; width:36px; height:36px; border-radius:50%; font-size:16px; cursor:pointer;}
  .we-b{padding:12px 16px; overflow-y:auto; flex:1; -webkit-overflow-scrolling:touch;}
  .we-info{background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; border-radius:12px; padding:9px 12px; font-size:13px; line-height:1.4;} .we-info.bad{background:#fef2f2; border-color:#fecaca; color:#991b1b;} .we-info.ok{background:#f0fdf4; border-color:#bbf7d0; color:#166534;}
  .we-t{font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; color:#6b7280; margin:14px 0 6px;}
  .we-ln{display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid #f1f5f9;} .we-ln .m{flex:1; min-width:0;} .we-ln .m b{display:block; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;} .we-ln .m small{color:#6b7280; font-size:12.5px;}
  .we-ln .tag{display:inline-block; font-size:10.5px; font-weight:800; border-radius:99px; padding:1px 7px; margin-left:6px; background:#fef3c7; color:#92400e; vertical-align:1px;}
  .we-q{display:flex; align-items:center; gap:2px; flex:0 0 auto;} .we-q button{width:36px; height:36px; border:1px solid #d1d5db; background:#fff; border-radius:10px; font-size:18px; font-weight:700; cursor:pointer; line-height:1;} .we-q b{min-width:26px; text-align:center;}
  .we-q .del{border-color:#fecaca; color:#b91c1c; font-size:15px; margin-left:4px;}
  .we-pr{width:84px; border:1px solid #d1d5db; border-radius:10px; padding:7px 8px; font:inherit; font-size:14px;}
  .we-tabs{display:flex; gap:6px; margin-bottom:8px;} .we-tabs button{flex:1; border:1px solid #d1d5db; background:#fff; border-radius:99px; padding:8px 6px; font:inherit; font-size:13px; font-weight:700; cursor:pointer;} .we-tabs button.on{background:#111827; border-color:#111827; color:#fff;}
  .we-s{width:100%; box-sizing:border-box; border:1.5px solid #d1d5db; border-radius:12px; padding:11px 12px; font:inherit; font-size:16px;}
  .we-it{display:flex; gap:10px; padding:9px 0; border-bottom:1px solid #f1f5f9;} .we-it img{width:42px; height:42px; border-radius:8px; object-fit:cover; background:#f3f4f6; flex:0 0 auto;} .we-it .nm{font-weight:700;}
  .we-sz{display:flex; flex-wrap:wrap; gap:6px; margin-top:5px;} .we-sz button{border:1px solid #16a34a; color:#166534; background:#f0fdf4; border-radius:99px; padding:6px 11px; font:inherit; font-size:12.5px; font-weight:700; cursor:pointer;} .we-sz button[disabled]{border-color:#e5e7eb; color:#9ca3af; background:#f9fafb; cursor:default;}
  .we-e{color:#6b7280; padding:12px 0; font-size:13px;}
  .we-f{border-top:1px solid #e5e7eb; padding:12px 16px calc(12px + env(safe-area-inset-bottom)); background:#fafafa;}
  .we-sum{display:flex; justify-content:space-between; gap:10px; font-size:13px; margin-bottom:8px;} .we-sum b{font-size:15px;} .we-sum.bad{color:#b91c1c;}
  .we-why{width:100%; box-sizing:border-box; border:1px solid #d1d5db; border-radius:10px; padding:9px 11px; font:inherit; font-size:14px; margin-bottom:8px;}
  .we-go{width:100%; border:0; background:#16a34a; color:#fff; border-radius:12px; padding:13px; font:inherit; font-size:15px; font-weight:800; cursor:pointer;} .we-go[disabled]{background:#d1d5db; cursor:default;}
  .we-hist{font-size:12.5px; color:#4b5563; line-height:1.45;} .we-hist div{padding:6px 0; border-bottom:1px dashed #e5e7eb;}
  @media (max-width:640px){ .we-ov{padding:0; align-items:flex-end;} .we{width:100%; max-height:96vh; border-radius:18px 18px 0 0;} }`;
  let cur = null;
  function close() { if (!cur) return; cur.ov.remove(); document.documentElement.style.overflow = cur.prev; cur = null; }
  async function open(orderNo, opt) {
    opt = opt || {}; const API = opt.api || (typeof api !== 'undefined' ? api : null); if (!API) { alert('Not logged in.'); return; }
    if (!document.getElementById('weCss')) { const st = document.createElement('style'); st.id = 'weCss'; st.textContent = CSS; document.head.appendChild(st); }
    close();
    const ov = document.createElement('div'); ov.className = 'we-ov'; ov.innerHTML = '<div class="we" role="dialog"><div class="we-h"><b>✏️ Update ' + esc(orderNo) + '<small id="weSub">Loading…</small></b><button type="button" class="we-x" aria-label="Close">✕</button></div><div class="we-b" id="weB"><div class="we-e">Loading the order…</div></div><div class="we-f" id="weF" hidden></div></div>';
    document.body.appendChild(ov); cur = { ov, prev: document.documentElement.style.overflow }; document.documentElement.style.overflow = 'hidden';
    ov.querySelector('.we-x').onclick = close; ov.addEventListener('mousedown', (e) => { if (e.target === ov) close(); });
    const B = ov.querySelector('#weB'), F = ov.querySelector('#weF'), q = (s) => ov.querySelector(s);
    let d; try { d = await API.get('wmo.edit_get', { order_no: orderNo }); } catch (e) { B.innerHTML = '<div class="we-info bad">' + esc(e.message || 'Could not open the order.') + '</div>'; return; }
    const S = { perf: new Map(), chain: new Map(), single: new Map(), cat: 'perfume', seq: 0, cache: {}, busy: false, why: '' };
    (d.lines || []).forEach((l) => { (l.kind === 'chain' ? S.chain : l.kind === 'single' ? S.single : S.perf).set(l.vid, { vid: l.vid, title: l.title, label: l.label, ml: l.ml, qty: l.qty, price: l.price }); });
    const start = JSON.stringify(snap());
    function snap() { const f = (m, p) => Array.from(m.values()).map((x) => (p ? [x.vid, x.qty, x.price] : [x.vid, x.qty])).sort((a, b) => a[0] - b[0]); return [f(S.perf), f(S.chain), f(S.single, true)]; }
    q('#weSub').textContent = (d.name ? d.name + ' · ' : '') + '+91 ' + d.phone;
    const sum = (m) => { let t = 0; m.forEach((x) => { t += x.price * x.qty; }); return t; };
    function calc() { const C = sum(S.chain), G = sum(S.single), P = d.total - C - G; let pcs = 0; S.perf.forEach((x) => { pcs += x.qty; }); let ml = 0; S.perf.forEach((x) => { ml += (x.ml || 0) * x.qty; });
      const ok = S.perf.size ? P >= pcs * 10 : (S.single.size > 0 && Math.abs(P) < 0.01); return { C, G, P, pcs, ml, ok, changed: JSON.stringify(snap()) !== start }; }
    function lineHtml(x, kind) {
      return '<div class="we-ln" data-vid="' + x.vid + '" data-kind="' + kind + '"><div class="m"><b>' + esc(x.title) + (kind === 'chain' ? '<span class="tag">Chain</span>' : kind === 'single' ? '<span class="tag">Other</span>' : '') + '</b><small>' + (x.label ? esc(x.label) : '') + (kind === 'perfume' ? '' : (x.label ? ' · ' : '') + rs(x.price) + ' each') + '</small></div>'
        + (kind === 'single' && !d.lock ? '<input class="we-pr" type="number" inputmode="decimal" min="1" value="' + x.price + '" title="Price of one piece" aria-label="Price">' : '')
        + (d.lock ? '<div class="we-q"><b>× ' + x.qty + '</b></div>' : '<div class="we-q"><button type="button" data-q="-1" aria-label="One less">−</button><b>' + x.qty + '</b><button type="button" data-q="1" aria-label="One more">+</button><button type="button" class="del" data-q="0" aria-label="Remove" title="Remove from the order">🗑</button></div>') + '</div>';
    }
    function drawLines() {
      const el = q('#weL'); if (!el) return; const k = calc();
      el.innerHTML = (S.perf.size + S.chain.size + S.single.size) ? Array.from(S.perf.values()).map((x) => lineHtml(x, 'perfume')).join('') + Array.from(S.chain.values()).map((x) => lineHtml(x, 'chain')).join('') + Array.from(S.single.values()).map((x) => lineHtml(x, 'single')).join('') : '<div class="we-e">Nothing in the order. Add a perfume below.</div>';
      q('#weLt').textContent = 'In this order' + (k.ml ? ' · ' + k.ml + ' ml' : '');
    }
    function drawFoot() {
      if (d.lock) { F.hidden = true; return; } F.hidden = false; const k = calc();
      const line = S.perf.size ? '<span>Total ' + rs(d.total) + ' (does not change)' + (k.C ? ' − chain ' + rs(k.C) : '') + (k.G ? ' − other ' + rs(k.G) : '') + '</span><b>Perfumes share ' + (k.P > 0 ? rs(k.P) : '—') + '</b>' : '<span>Total ' + rs(d.total) + ' (does not change)</span><b>' + (k.ok ? '✓ matches' : 'products = ' + rs(k.C + k.G)) + '</b>';
      const why = !k.ok ? (S.perf.size ? 'Too little is left for the perfumes after the chain / other products. Remove something.' : 'With no perfume, the products must add up to exactly ' + rs(d.total) + '.') : '';
      F.innerHTML = '<div class="we-sum' + (k.ok ? '' : ' bad') + '">' + line + '</div>' + (why ? '<div class="we-info bad" style="margin-bottom:8px">' + esc(why) + '</div>' : '') + '<input class="we-why" id="weWhy" maxlength="160" placeholder="Why is it changed? (optional — e.g. customer asked)" value="' + esc(S.why) + '"><div id="weMsg"></div><button type="button" class="we-go" id="weGo"' + (k.ok && k.changed && !S.busy ? '' : ' disabled') + '>' + (S.busy ? 'Saving…' : k.changed ? 'Save changes' : 'Nothing changed yet') + '</button>';
      q('#weWhy').oninput = (e) => { S.why = e.target.value; }; q('#weGo').onclick = save;
    }
    function draw() {
      B.innerHTML = (d.lock ? '<div class="we-info bad">🔒 ' + esc(d.lock) + '</div>' : '<div class="we-info">Remove or add perfumes. <b>The total ' + rs(d.total) + ' and the payment stay the same</b> (' + (d.pay === 'cod' ? 'Full COD' : d.pay === 'partial' ? 'paid ' + rs(d.paid) + ' + COD ' + rs(d.cod) : 'fully prepaid') + '). This can be changed until a tracking ID is given.</div>')
        + '<div class="we-t" id="weLt">In this order</div><div id="weL"></div>'
        + (d.lock ? '' : '<div class="we-t">Add to the order</div><div class="we-tabs"><button type="button" data-cat="perfume" class="on">Perfume</button><button type="button" data-cat="chain">Chain</button><button type="button" data-cat="other">Other product</button></div><input class="we-s" id="weS" type="search" placeholder="Search a perfume…" autocomplete="off"><div id="weR"></div>')
        + ((d.edits || []).length ? '<div class="we-t">Earlier changes</div><div class="we-hist">' + d.edits.slice().reverse().map((e) => '<div><b>' + esc(e.by) + '</b> · ' + esc(String(e.at).slice(0, 16)) + (e.why ? ' · ' + esc(e.why) : '') + '<br>was: ' + esc(e.from) + '<br>now: ' + esc(e.to) + '</div>').join('') + '</div>' : '');
      drawLines(); drawFoot();
      q('#weL').onclick = (e) => { const b = e.target.closest('button[data-q]'); if (!b) return; const ln = b.closest('.we-ln'), m = ln.dataset.kind === 'chain' ? S.chain : ln.dataset.kind === 'single' ? S.single : S.perf, x = m.get(+ln.dataset.vid); if (!x) return;
        const dq = +b.dataset.q, max = ln.dataset.kind === 'chain' ? 5 : ln.dataset.kind === 'single' ? 50 : 20; if (dq === 0 || (dq < 0 && x.qty <= 1)) m.delete(x.vid); else x.qty = Math.min(max, x.qty + dq); drawLines(); drawFoot(); };
      q('#weL').addEventListener('input', (e) => { const i = e.target.closest('.we-pr'); if (!i) return; const x = S.single.get(+i.closest('.we-ln').dataset.vid); if (x) { x.price = Math.max(0, parseFloat(i.value) || 0); drawFoot(); } });
      if (d.lock) return;
      const inp = q('#weS'), res = q('#weR'); let deb = 0;
      const show = async (txt) => {
        const my = ++S.seq, ck = S.cat + '|' + txt; let r = S.cache[ck];
        if (!r) { res.innerHTML = '<div class="we-e">Searching…</div>'; try { r = await API.get('wch.search', { q: txt, cat: S.cat, limit: 12 }); S.cache[ck] = r; } catch (er) { if (my === S.seq) res.innerHTML = '<div class="we-e">' + esc(er.message) + '</div>'; return; } }
        if (my !== S.seq) return; const items = r.items || [];
        if (!items.length) { res.innerHTML = '<div class="we-e">' + (txt ? 'Nothing matches “' + esc(txt) + '”.' : 'Type a name to search.') + '</div>'; return; }
        res.innerHTML = items.map((it, i) => '<div class="we-it" data-i="' + i + '">' + (it.photo ? '<img loading="lazy" alt="" src="' + esc(it.photo) + '">' : '<img alt="">') + '<div style="flex:1;min-width:0"><div class="nm">' + esc(it.short || it.title) + '</div><div class="we-sz">' + (it.sizes || []).map((s, j) => '<button type="button" data-j="' + j + '"' + (s.in_stock ? '' : ' disabled title="Out of stock"') + '>+ ' + esc(/^default title$/i.test(s.label || '') ? 'Add' : s.label) + ' · ' + rs(+s.price) + '</button>').join('') + '</div></div></div>').join('');
        res.onclick = (e) => { const b = e.target.closest('button[data-j]'); if (!b || b.disabled) return; const it = items[+b.closest('.we-it').dataset.i], s = it && it.sizes[+b.dataset.j]; if (!s) return;
          const m = S.cat === 'chain' ? S.chain : S.cat === 'other' ? S.single : S.perf, max = S.cat === 'chain' ? 5 : S.cat === 'other' ? 50 : 20, ex = m.get(s.vid);
          if (ex) ex.qty = Math.min(max, ex.qty + 1); else m.set(s.vid, { vid: s.vid, title: it.short || it.title, label: /^default title$/i.test(s.label || '') ? '' : s.label, ml: s.ml, qty: 1, price: +s.price });
          drawLines(); drawFoot(); b.textContent = '✓ Added'; setTimeout(() => { if (b.isConnected) b.textContent = '+ ' + (/^default title$/i.test(s.label || '') ? 'Add' : s.label) + ' · ' + rs(+s.price); }, 900); };
      };
      inp.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => show(inp.value.trim()), 220); }); inp.addEventListener('focus', () => { if (!res.innerHTML) show(inp.value.trim()); });
      ov.querySelectorAll('.we-tabs button').forEach((b) => { b.onclick = () => { S.cat = b.dataset.cat; ov.querySelectorAll('.we-tabs button').forEach((x) => x.classList.toggle('on', x === b)); inp.placeholder = S.cat === 'perfume' ? 'Search a perfume…' : S.cat === 'chain' ? 'Search a chain…' : 'Search shower gel, attar, balm…'; inp.value = ''; res.innerHTML = ''; show(''); }; });
    }
    async function save() {
      const k = calc(); if (!k.ok || !k.changed || S.busy) return; S.busy = true; drawFoot();
      try {
        const body = { id: d.id, why: S.why, lines: Array.from(S.perf.values()).map((x) => ({ vid: x.vid, qty: x.qty })), chains: Array.from(S.chain.values()).map((x) => ({ vid: x.vid, qty: x.qty })), singles: Array.from(S.single.values()).map((x) => ({ vid: x.vid, qty: x.qty, price: x.price })) };
        const r = await API.post('wmo.update', body); S.busy = false; F.hidden = true;
        B.innerHTML = '<div class="we-info ok"><b>✅ ' + esc(r.message || 'Saved.') + '</b>' + (r.same ? '' : '<br><br><b>Was:</b> ' + esc(r.from) + '<br><b>Now:</b> ' + esc(r.to)) + '</div>'
          + ((r.notes || []).length ? r.notes.map((n) => '<div class="we-info' + (/OLD|NOT updated|BEFORE|could not/i.test(n) ? ' bad' : '') + '" style="margin-top:8px">' + esc(n) + '</div>').join('') : '')
          + '<div class="we-info" style="margin-top:8px">Orders, ML, commission and the Dispatch packing list now show the new perfumes.</div><button type="button" class="we-go" style="margin-top:12px" id="weOk">Close</button>';
        q('#weOk').onclick = () => { close(); if (opt.onSaved) { try { opt.onSaved(r); } catch (e) { } } };
      } catch (e) { S.busy = false; drawFoot(); const m = q('#weMsg'); if (m) m.innerHTML = '<div class="we-info bad" style="margin-bottom:8px">' + esc(e.message || 'Could not save.') + '</div>'; }
    }
    draw();
  }
  window.WmoEdit = { open, close };
})();
