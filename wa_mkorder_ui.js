/* wa_mkorder_ui.js — "Create order": a bottom sheet in the chat where the salesperson / admin builds the order directly.
 *
 *   WaMkOrder.open({ phone, name, api, canSend, onDone })
 *
 * The salesperson agrees ONE total with the customer, picks the perfumes and (optionally) the chain(s).
 * Rule (same as wch_manual_make in wch.php): every chain is billed at its FULL price, all of the discount lands on the perfumes.
 *   perfumes get  =  total  -  chains (full price)
 * Step 1 builds the order (wch.order_make), step 2 sends the payment link to the customer (wch.cart_link).
 * Needs the global `api` (api.js). Shared look with wa_prod_ui.js (green WhatsApp palette, bottom sheet on a phone).
 */
(function () {
  'use strict';
  if (window.WaMkOrder) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (p) => '₹' + (Math.round(p) / 100).toLocaleString('en-IN', { minimumFractionDigits: p % 100 ? 2 : 0, maximumFractionDigits: 2 });   // paise -> "₹1,100"
  const paise = (rupees) => Math.round(Number(rupees) * 100);
  const isDefault = (s) => !s || /^default title$/i.test(String(s).trim());
  const errMsg = (e) => (e && e.message) || 'Something went wrong. Try again.';

  const CSS = `
  .wmo-ov{position:fixed; inset:0; z-index:10050; background:#0008; display:flex; align-items:flex-end; justify-content:center; font-family:inherit;}
  .wmo-sh{background:#fff; color:#111b21; width:100%; max-width:640px; height:94vh; height:94dvh; border-radius:18px 18px 0 0; display:flex; flex-direction:column; box-shadow:0 -8px 30px #0004; overflow:hidden; position:relative; font-size:14px;}
  @media (min-width:761px){ .wmo-ov{align-items:center;} .wmo-sh{border-radius:18px; height:min(88vh,820px);} }
  .wmo-sh *{box-sizing:border-box;} .wmo-sh button,.wmo-sh input{font:inherit;} .wmo-sh [hidden]{display:none !important;}
  .wmo-hd{display:flex; align-items:center; justify-content:space-between; gap:8px; padding:12px 14px 8px; border-bottom:1px solid #eef0f3;} .wmo-hd b{font-size:17px;} .wmo-hd small{display:block; font-size:12.5px; color:#667781; font-weight:400; margin-top:1px;}
  .wmo-x{flex:none; width:40px; height:40px; border:0; border-radius:50%; background:#f0f2f5; color:#54656f; font-size:18px; cursor:pointer;}
  .wmo-bd{flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; padding:10px 14px 16px;}
  .wmo-h{margin:16px 0 6px; font-size:11.5px; font-weight:800; color:#667781; text-transform:uppercase; letter-spacing:.4px; display:flex; justify-content:space-between; gap:8px;} .wmo-h:first-child{margin-top:2px;} .wmo-h em{font-style:normal; font-weight:600; text-transform:none; letter-spacing:0;}
  .wmo-tot{display:flex; align-items:center; gap:8px; border:2px solid #d1d7db; border-radius:14px; padding:0 14px; background:#fff;} .wmo-tot:focus-within{border-color:#00a884;} .wmo-tot span{font-size:22px; font-weight:800; color:#54656f;}
  .wmo-tot input{flex:1; min-width:0; border:0; outline:0; height:54px; font-size:26px; font-weight:800; background:transparent; -webkit-appearance:none; appearance:none;}
  .wmo-sb{position:relative;} .wmo-sb input{width:100%; height:44px; border:1px solid #d1d7db; border-radius:22px; padding:0 16px; font-size:16px; background:#f0f2f5; outline:none; -webkit-appearance:none; appearance:none;} .wmo-sb input:focus{border-color:#00a884; background:#fff;}
  .wmo-res{margin-top:6px; max-height:210px; overflow-y:auto; -webkit-overflow-scrolling:touch; border:1px solid #eef0f3; border-radius:12px;} .wmo-res:empty{display:none;}
  .wmo-it{display:flex; gap:10px; align-items:center; padding:8px 10px; border-bottom:1px solid #f0f2f5;} .wmo-it:last-child{border-bottom:0;}
  .wmo-it img{width:46px; height:46px; border-radius:9px; object-fit:cover; background:#e9edef; flex:none;} .wmo-it .mid{flex:1; min-width:0;} .wmo-it .nm{font-weight:700; line-height:1.25; word-break:break-word;}
  .wmo-sz{display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;} .wmo-sz button{min-height:36px; padding:0 12px; border:1px solid #00a884; border-radius:18px; background:#fff; color:#005c4b; font-size:13px; font-weight:700; cursor:pointer;} .wmo-sz button:active{background:#d9fdd3;} .wmo-sz button:disabled{border-color:#d1d7db; color:#9aa5ad; background:#f7f8f8; cursor:default; text-decoration:line-through;}
  .wmo-empty{padding:14px; text-align:center; color:#667781; font-size:13px; line-height:1.5;}
  .wmo-ln{display:flex; gap:10px; align-items:center; padding:9px 0; border-bottom:1px dashed #e4e7ec;} .wmo-ln:last-child{border-bottom:0;} .wmo-ln .mid{flex:1; min-width:0;} .wmo-ln .nm{font-weight:700; line-height:1.25; word-break:break-word;} .wmo-ln small{display:block; color:#667781; font-size:12px; margin-top:1px;}
  .wmo-q{display:flex; align-items:center; gap:2px; flex:none;} .wmo-q button{width:36px; height:36px; border:0; border-radius:50%; background:#f0f2f5; color:#111b21; font-size:18px; font-weight:700; cursor:pointer;} .wmo-q b{min-width:24px; text-align:center; font-size:15px;}
  .wmo-amt{flex:none; min-width:74px; text-align:right; font-weight:800;}
  .wmo-none{color:#8696a0; font-size:13px; padding:6px 0;}
  .wmo-sum{margin:0 0 8px; border-radius:12px; background:#f0fbf7; border:1px solid #c8eedc; padding:8px 12px;} .wmo-sum.bad{background:#fff4f2; border-color:#f6c9c3;}
  .wmo-sum .r{display:flex; justify-content:space-between; gap:10px; padding:2px 0;} .wmo-sum .r.big{font-size:16px; font-weight:800;} .wmo-sum .r span{color:#3b4a54;} .wmo-sum .why{margin-top:2px; font-size:12px; color:#005c4b; line-height:1.4;} .wmo-sum.bad .why{color:#b3261e;}
  .wmo-ft{border-top:1px solid #e9edef; padding:10px 14px calc(10px + env(safe-area-inset-bottom)); background:#fff;}
  .wmo-go{width:100%; height:50px; border:0; border-radius:25px; background:#008069; color:#fff; font-size:16px; font-weight:800; cursor:pointer;} .wmo-go:disabled{opacity:.5; cursor:default;} .wmo-go.alt{background:#f0f2f5; color:#111b21; margin-top:8px; font-weight:700;}
  .wmo-note{margin:0 0 8px; padding:9px 12px; border-radius:10px; font-size:13px; line-height:1.4; background:#fff6d6; color:#7a5200;} .wmo-note.bad{background:#fde8e8; color:#9a2620;} .wmo-note.ok{background:#e3f6ec; color:#12794a;}
  .wmo-note button{margin-top:6px; min-height:36px; padding:0 14px; border:0; border-radius:18px; background:#1c2430; color:#fff; font-weight:700; cursor:pointer;}
  .wmo-done .ok{font-size:34px; text-align:center; margin:6px 0 2px;} .wmo-done h3{margin:0 0 10px; text-align:center; font-size:18px;}
  `;
  function css() { if (document.getElementById('wmoCss')) return; const s = document.createElement('style'); s.id = 'wmoCss'; s.textContent = CSS; document.head.appendChild(s); }

  let cur = null;
  function close() { if (!cur) return; document.removeEventListener('keydown', cur.key); cur.ov.remove(); document.body.style.overflow = cur.bodyOv; cur = null; }

  function open(o) {
    o = o || {}; if (cur) close(); css();
    const API = o.api || (typeof api !== 'undefined' ? api : null); if (!API) { alert('Not logged in.'); return; }
    // S.perf / S.chain: Map vid -> { vid, title, label, price (paise), ml, qty, photo }
    const S = { total: '', perf: new Map(), chain: new Map(), busy: false, replace: false, cart: null, bd: null, linked: false, seq: { perfume: 0, chain: 0 }, cache: {} };
    const ov = document.createElement('div'); ov.className = 'wmo-ov';
    ov.innerHTML = '<div class="wmo-sh" role="dialog" aria-modal="true" aria-label="Create order"><div class="wmo-hd"><div><b>Create order</b><small>' + esc(o.name || '') + '</small></div><button type="button" class="wmo-x" aria-label="Close">✕</button></div><div class="wmo-bd" id="wmoBd"></div><div class="wmo-ft" id="wmoFt"></div></div>';
    document.body.appendChild(ov); const bodyOv = document.body.style.overflow; document.body.style.overflow = 'hidden';
    const key = (e) => { if (e.key === 'Escape' && !S.busy) close(); }; document.addEventListener('keydown', key); cur = { ov, key, bodyOv };
    ov.addEventListener('click', (e) => { if (e.target === ov && !S.busy) close(); }); ov.querySelector('.wmo-x').onclick = () => { if (!S.busy) close(); };
    const bd = ov.querySelector('#wmoBd'), ft = ov.querySelector('#wmoFt');

    // ---- the numbers (all in paise, same rule as the server)
    const totalP = () => { const v = String(S.total).replace(/[^\d.]/g, ''); const n = parseFloat(v); return isFinite(n) ? paise(n) : 0; };
    const sumOf = (m) => { let t = 0; m.forEach((x) => { t += x.price * x.qty; }); return t; };
    const mlOf = () => { let t = 0; S.perf.forEach((x) => { t += (x.ml || 0) * x.qty; }); return t; };
    function calc() {
      const T = totalP(), C = sumOf(S.chain), L = sumOf(S.perf), P = T - C;
      return { T, C, L, P, disc: Math.max(0, L - P), ok: T >= 10000 && S.perf.size > 0 && P > 0 };
    }
    function summary() {
      const k = calc(); if (!k.T || !S.perf.size) return '';
      const bad = S.chain.size > 0 && k.P <= 0;
      let h = '<div class="wmo-sum' + (bad ? ' bad' : '') + '" id="wmoSum"><div class="r big"><span>Perfumes get</span><b>' + (k.P > 0 ? rs(k.P) : '—') + '</b></div>';
      if (bad) h += '<div class="why">The chains cost ' + rs(k.C) + ' at full price — not less than the total ' + rs(k.T) + '. Raise the total or pick cheaper chains.</div>';
      else h += '<div class="why">Total ' + rs(k.T) + (S.chain.size ? ' − chains ' + rs(k.C) + ' (full price)' : '') + '. ' + (k.disc > 0 ? 'Perfumes are listed at ' + rs(k.L) + ' → ' + rs(k.disc) + ' off, taken from the perfumes.' : (k.P > k.L ? 'The perfumes are priced above their list price of ' + rs(k.L) + '.' : 'No discount on the perfumes.')) + '</div>';
      return h + '</div>';
    }
    const refreshSummary = () => drawFoot();

    // ---- step 1: the form
    function lineHtml(x, cat) {
      return '<div class="wmo-ln" data-vid="' + x.vid + '" data-cat="' + cat + '"><div class="mid"><div class="nm">' + esc(x.title) + '</div><small>' + (isDefault(x.label) ? '' : esc(x.label) + ' · ') + rs(x.price) + (x.qty > 1 ? ' × ' + x.qty : '') + (cat === 'chain' ? ' · full price' : '') + '</small></div><div class="wmo-q"><button type="button" data-q="-1" aria-label="One less">−</button><b>' + x.qty + '</b><button type="button" data-q="1" aria-label="One more">+</button></div><div class="wmo-amt">' + rs(x.price * x.qty) + '</div></div>';
    }
    function drawLines() {
      const el = bd.querySelector('#wmoLines'); if (!el) return;
      el.innerHTML = '<div class="wmo-h">Perfumes in this order<em>' + (S.perf.size ? mlOf() + ' ml' : '') + '</em></div>' + (S.perf.size ? Array.from(S.perf.values()).map((x) => lineHtml(x, 'perfume')).join('') : '<div class="wmo-none">Search a perfume above and tap a size.</div>')
        + '<div class="wmo-h">Chains in this order<em>full price</em></div>' + (S.chain.size ? Array.from(S.chain.values()).map((x) => lineHtml(x, 'chain')).join('') : '<div class="wmo-none">No chain. Search one below if the customer wants it.</div>');
    }
    function drawForm() {
      bd.innerHTML = '<div class="wmo-h">Order total <em>what the customer pays, chain included</em></div><div class="wmo-tot"><span>₹</span><input id="wmoTot" type="text" inputmode="decimal" autocomplete="off" placeholder="0" value="' + esc(S.total) + '"></div>'
        + '<div id="wmoLines"></div><div class="wmo-h">Add perfumes</div><div class="wmo-sb"><input id="wmoQp" type="search" inputmode="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Search a perfume…"></div><div class="wmo-res" id="wmoRp"></div>'
        + '<div class="wmo-h">Add a chain <em>optional</em></div><div class="wmo-sb"><input id="wmoQc" type="search" inputmode="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Search a chain…"></div><div class="wmo-res" id="wmoRc"></div>'
        + '<div id="wmoMsg" style="margin-top:12px"></div>';
      drawLines();
      const tot = bd.querySelector('#wmoTot'); tot.oninput = () => { const v = tot.value.replace(/[^\d.]/g, ''); if (v !== tot.value) tot.value = v; S.total = v; refreshSummary(); };
      bindSearch('perfume', bd.querySelector('#wmoQp'), bd.querySelector('#wmoRp')); bindSearch('chain', bd.querySelector('#wmoQc'), bd.querySelector('#wmoRc'));
      bd.querySelector('#wmoLines').onclick = (e) => {
        const b = e.target.closest('[data-q]'); if (!b) return; const row = b.closest('.wmo-ln'), m = row.dataset.cat === 'chain' ? S.chain : S.perf, x = m.get(+row.dataset.vid); if (!x) return;
        x.qty += +b.dataset.q; const max = row.dataset.cat === 'chain' ? 5 : 20; if (x.qty < 1) m.delete(x.vid); else if (x.qty > max) x.qty = max;
        drawLines(); refreshSummary(); msg('');
      };
      drawFoot();
    }
    function msg(h) { const el = bd.querySelector('#wmoMsg'); if (el) el.innerHTML = h || ''; }

    // ---- search (perfumes and chains share the code)
    function bindSearch(cat, inp, res) {
      let deb = 0;
      const show = async (q) => {
        const my = ++S.seq[cat], ck = cat + '|' + q; let d = S.cache[ck];
        if (!d) { res.innerHTML = '<div class="wmo-empty">Loading…</div>'; try { d = await API.get('wch.search', { q, cat, limit: 12 }); S.cache[ck] = d; } catch (e) { if (my !== S.seq[cat]) return; res.innerHTML = '<div class="wmo-empty">' + esc(errMsg(e)) + '</div>'; return; } }
        if (my !== S.seq[cat]) return;
        const items = d.items || [];
        if (!items.length) { res.innerHTML = '<div class="wmo-empty">' + (q ? 'No ' + cat + ' matches “' + esc(q) + '”.' : 'No ' + cat + 's in the catalog yet. Ask the admin to refresh the catalog (Sales → WhatsApp → Chain cart).') + '</div>'; return; }
        res.innerHTML = items.map((it, i) => '<div class="wmo-it" data-i="' + i + '">' + (it.photo ? '<img loading="lazy" alt="" src="' + esc(it.photo) + '">' : '<img alt="">') + '<div class="mid"><div class="nm">' + esc(it.short || it.title) + '</div><div class="wmo-sz">' + (it.sizes || []).map((s, j) => '<button type="button" data-j="' + j + '"' + (s.in_stock ? '' : ' disabled') + '>' + (isDefault(s.label) ? '' : esc(s.label) + ' · ') + rs(paise(s.price)) + '</button>').join('') + '</div></div></div>').join('');
        res.onclick = (e) => {
          const b = e.target.closest('button[data-j]'); if (!b || b.disabled) return; const it = items[+b.closest('.wmo-it').dataset.i], s = it.sizes[+b.dataset.j]; if (!it || !s) return;
          const m = cat === 'chain' ? S.chain : S.perf, ex = m.get(s.vid), max = cat === 'chain' ? 5 : 20;
          if (ex) { if (ex.qty < max) ex.qty++; } else m.set(s.vid, { vid: s.vid, title: it.short || it.title, label: s.label, price: paise(s.price), ml: s.ml, qty: 1, photo: it.photo });
          drawLines(); refreshSummary(); msg('');
        };
      };
      inp.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => show(inp.value.trim()), 220); });
      inp.addEventListener('focus', () => { if (!res.innerHTML) show(inp.value.trim()); });
    }

    // ---- footer + the two steps
    function initFoot() { ft.innerHTML = '<div id="wmoFm"></div><div id="wmoSumW"></div><button type="button" class="wmo-go" id="wmoGo"></button>'; ft.querySelector('#wmoGo').onclick = make; }
    function drawFoot() {
      if (S.cart) return; if (!ft.querySelector('#wmoGo')) initFoot();
      const k = calc(); ft.querySelector('#wmoSumW').innerHTML = summary(); const g = ft.querySelector('#wmoGo'); g.disabled = !(k.ok && !S.busy); g.textContent = S.busy ? 'Creating…' : 'Create order' + (k.ok ? ' · ' + rs(k.T) : '');
    }
    function fm(h, cls) { const el = ft.querySelector('#wmoFm'); if (el) el.innerHTML = h ? '<div class="wmo-note ' + (cls || '') + '">' + h + '</div>' : ''; }
    async function make() {
      const k = calc(); if (!k.ok || S.busy) return; S.busy = true; drawFoot(); fm('');
      try {
        const r = await API.post('wch.order_make', { phone: o.phone, total: k.T / 100, lines: Array.from(S.perf.values()).map((x) => ({ vid: x.vid, qty: x.qty })), chains: Array.from(S.chain.values()).map((x) => ({ vid: x.vid, qty: x.qty })), replace: S.replace ? 1 : 0 });
        S.cart = r.cart; S.bd = r.breakdown; S.busy = false; drawDone();
        if (o.onDone) { try { o.onDone({ stage: 'built', cart: r.cart }); } catch (e) { } }
      } catch (e) {
        S.busy = false; drawFoot(); const d = (e && e.data) || {};
        if (d.code === 'cart_exists') { const id = d.cart_id; fm('This customer already has an open cart' + (id ? ' (#' + esc(id) + ')' : '') + '. Replace it with this new order?<br><button type="button" id="wmoRepl">Replace and create</button>', 'bad'); const b = ft.querySelector('#wmoRepl'); if (b) b.onclick = () => { S.replace = true; make(); }; }
        else fm(esc(errMsg(e)), 'bad');
      }
    }
    function drawDone() {
      const c = S.cart, b = S.bd || {}; const lines = ((c.plan && c.plan.lines) || []);
      const li = lines.map((l) => '<div class="wmo-ln"><div class="mid"><div class="nm">' + esc(l.title) + '</div><small>' + (l.kind === 'chain' ? 'Chain · full price' : 'Perfume' + (l.ml ? ' · ' + l.ml + ' ml' : '')) + '</small></div><div class="wmo-amt">' + rs(paise(l.amount)) + '</div></div>').join('');
      bd.innerHTML = '<div class="wmo-done"><div class="ok">✅</div><h3>Order ready · ' + rs(paise(b.total != null ? b.total : c.total)) + '</h3>' + li
        + '<div class="wmo-sum" style="margin-top:12px;padding:12px 14px"><div class="r"><span>Chains (full price)</span><b>' + rs(paise(b.chain || 0)) + '</b></div><div class="r"><span>Perfumes</span><b>' + rs(paise(b.perfume_part != null ? b.perfume_part : 0)) + '</b></div>' + (b.perfume_discount > 0 ? '<div class="why">Discount on the perfumes: ' + rs(paise(b.perfume_discount)) + ' (list price ' + rs(paise(b.perfume_list)) + ').</div>' : '') + '</div></div><div id="wmoMsg" style="margin-top:12px"></div>';
      drawLinkFoot();
    }
    function drawLinkFoot() {
      const sendNote = o.canSend === false ? '<div class="wmo-note">The 24-hour window is closed or the customer opted out, so the payment link cannot be sent now. The order is saved.</div>' : '';
      ft.innerHTML = sendNote + '<div id="wmoFm"></div>' + (S.linked ? '' : '<button type="button" class="wmo-go" id="wmoLink"' + (S.busy || o.canSend === false ? ' disabled' : '') + '>' + (S.busy ? 'Sending…' : '💳 Send payment link to customer') + '</button>') + '<button type="button" class="wmo-go alt" id="wmoDone">' + (S.linked ? 'Done' : 'Close without sending') + '</button>';
      const l = ft.querySelector('#wmoLink'); if (l) l.onclick = sendLink; ft.querySelector('#wmoDone').onclick = close;
    }
    async function sendLink() {
      if (S.busy) return; S.busy = true; drawLinkFoot(); fm('');
      try {
        const r = await API.post('wch.cart_link', { id: S.cart.id }); S.busy = false; S.linked = true; S.cart = r.cart || S.cart; drawLinkFoot();
        fm('Payment link sent' + (r.link && r.link.draft_name ? ' (' + esc(r.link.draft_name) + ')' : '') + '. The customer can pay from the chat.', 'ok');
        if (o.onDone) { try { o.onDone({ stage: 'sent', cart: S.cart }); } catch (e) { } }
      } catch (e) { S.busy = false; drawLinkFoot(); fm(esc(errMsg(e)) + ((e.data && e.data.code === 'window_closed') ? '' : ' <br>The order is saved — press the button again after fixing this.'), 'bad'); }
    }

    drawForm();
    if (window.innerWidth > 760) setTimeout(() => { const t = bd.querySelector('#wmoTot'); if (t) t.focus(); }, 60);
    return { close };
  }

  window.WaMkOrder = { open, close };
})();
