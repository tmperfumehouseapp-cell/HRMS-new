/* 🔁 Make an Exchange / RTO resend order FROM a complaint (Complaints → a ticket → "Make order").
   The ticket already knows the customer, so nothing is typed again: name, mobile, old order and the last address come in; the person picks the reason, the new perfume and presses Create.
   It uses the same calls as Dispatch → RTO resend & Exchange (wmo.start / wmo.create / wmo.confirm), then writes the new order on the ticket (bd.cx_xo_link). Needs api.js. */
(function () {
  'use strict';
  const WHY = { exchange: ['Did not like the fragrance', 'Fragrance does not last', 'Damaged / broken bottle', 'Leakage', 'Sprayer / cap not working', 'Wrong perfume sent', 'Wanted a different size', 'Item missing / short', 'Changed his mind', 'Other'], rto: ['Courier could not deliver', 'Customer not reachable', 'Customer refused the parcel', 'Address wrong / corrected', 'Pincode not serviceable', 'Resend asked by the customer', 'Parcel stuck / delayed', 'Other'] };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const CSS = '.cxo-sc{position:fixed;inset:0;background:rgba(17,17,17,.4);z-index:99990}.cxo{position:fixed;top:0;right:0;bottom:0;width:min(540px,100%);background:#fff;color:#1f1a17;z-index:99991;display:flex;flex-direction:column;box-shadow:-20px 0 50px rgba(0,0,0,.28);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
    + '.cxo header{padding:14px 18px;border-bottom:1px solid #eadfd6;display:flex;justify-content:space-between;align-items:center}.cxo header b{font-size:16px}.cxo .x{border:0;background:#f1ebe5;border-radius:50%;width:32px;height:32px;font-size:16px;cursor:pointer}'
    + '.cxo .bd{padding:14px 18px;overflow:auto;flex:1}.cxo footer{padding:12px 18px;border-top:1px solid #eadfd6}.cxo label.l{display:block;font-weight:800;margin:14px 0 5px;font-size:13px}.cxo label.l small{font-weight:500;color:#7a6e66}'
    + '.cxo input{width:100%;font:inherit;border:1.5px solid #eadfd6;border-radius:11px;padding:9px 11px;background:#fff;color:#1f1a17}.cxo input.pre{background:#f7f3ee}.cxo .row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px}'
    + '.cxo .chips{display:flex;flex-wrap:wrap;gap:7px}.cxo .chip{border:1.5px solid #eadfd6;background:#fff;border-radius:99px;padding:6px 12px;font:inherit;font-weight:700;cursor:pointer;color:#1f1a17}.cxo .chip.on{background:#d97706;color:#fff;border-color:#d97706}'
    + '.cxo .free{display:flex;gap:12px;align-items:center;border:2px dashed #7ac79a;background:#f0fbf4;border-radius:13px;padding:10px 12px;margin-top:10px}.cxo .free b{font-size:22px;color:#0f7a4d}.cxo .free small{display:block;color:#3b6b54}'
    + '.cxo .info{border:1.5px solid #e8d8c6;background:#fffaf4;border-radius:13px;padding:9px 12px}.cxo .info b{color:#9a3412}.cxo .res{border:1px solid #eadfd6;border-radius:11px;margin-top:6px}.cxo .res div{padding:8px 10px;border-bottom:1px solid #f4ede6;display:flex;justify-content:space-between;gap:8px;align-items:center}.cxo .res div:last-child{border:0}.cxo .res button{border:1.5px solid #eadfd6;background:#fff;border-radius:99px;padding:3px 10px;font:inherit;font-weight:700;cursor:pointer}'
    + '.cxo .pk{display:flex;justify-content:space-between;align-items:center;border:1px solid #eadfd6;border-radius:11px;padding:7px 10px;margin-top:6px}.cxo .pk button{border:1.5px solid #eadfd6;background:#fff;border-radius:8px;width:28px;height:28px;cursor:pointer;font-weight:800}'
    + '.cxo .go{width:100%;border:0;border-radius:13px;padding:13px;font:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,#f59e0b,#d97706);cursor:pointer}.cxo .go:disabled{opacity:.55;cursor:default}.cxo .err{background:#fde8e7;color:#b42318;border-radius:11px;padding:9px 12px;margin-bottom:8px;font-weight:700}'
    + '.cxo *{box-sizing:border-box}.cxo .seg{display:flex;gap:8px;margin-bottom:6px}';
  let seq = 0;
  async function open(o) {
    if (document.getElementById('cxoDr')) return;
    let f; try { f = await api.get('bd.cx_xo_form', { id: o.id }); } catch (e) { alert(e.message); return; }
    if (!document.getElementById('cxoCss')) { const s = document.createElement('style'); s.id = 'cxoCss'; s.textContent = CSS; document.head.appendChild(s); }
    const S = { kind: o.kind || f.kind || 'exchange', why: f.why ? [f.why] : [], whyTxt: '', name: f.name || '', phone: f.phone || '', old: f.old_order || '', a: Object.assign({ addr1: '', landmark: '', pin: '', city: '', state: '' }, f.address || {}), cat: 'perfume', items: new Map(), msg: '', busy: false };
    const sc = document.createElement('div'); sc.className = 'cxo-sc'; const dr = document.createElement('aside'); dr.className = 'cxo'; dr.id = 'cxoDr';
    document.body.appendChild(sc); document.body.appendChild(dr); const close = () => { sc.remove(); dr.remove(); }; sc.onclick = close;
    const $ = (s) => dr.querySelector(s);
    const keep = () => { [['cName', 'name'], ['cPhone', 'phone'], ['cOld', 'old'], ['cWT', 'whyTxt']].forEach(([i, k]) => { const x = $('#' + i); if (x) S[k] = x.value; }); [['cA1', 'addr1'], ['cLm', 'landmark'], ['cPin', 'pin'], ['cCity', 'city'], ['cSt', 'state']].forEach(([i, k]) => { const x = $('#' + i); if (x) S.a[k] = x.value; }); };
    function draw() {
      const v = (x) => esc(x || '');
      dr.innerHTML = '<header><b>' + (S.kind === 'rto' ? '↩ Make the RTO resend order' : '🔁 Make the exchange order') + '</b><button class="x" id="cX" type="button">✕</button></header><div class="bd">' + (S.msg || '')
        + '<div class="info"><b>From complaint ' + esc(f.code || '') + '</b> · the customer and the old order come by themselves. Only the reason, the address (if new) and the new perfume are needed.</div>'
        + '<div class="seg" style="margin-top:12px"><button type="button" class="chip ' + (S.kind === 'exchange' ? 'on' : '') + '" data-k="exchange">🔁 Exchange</button><button type="button" class="chip ' + (S.kind === 'rto' ? 'on' : '') + '" data-k="rto">↩ RTO resend</button></div>'
        + '<label class="l">Customer</label><div class="row" style="margin-top:0"><input id="cPhone" class="pre" inputmode="numeric" maxlength="14" value="' + v(S.phone) + '"><input id="cName" class="pre" value="' + v(S.name) + '"></div>'
        + '<label class="l">Old order number <small>(the parcel that came back, or the order being exchanged)</small></label><input id="cOld" class="pre" maxlength="40" placeholder="e.g. #67567" value="' + v(S.old) + '">'
        + '<label class="l">Why? <small>(tick one or more, or type)</small></label><div class="chips" id="cWhy">' + WHY[S.kind].map((x) => '<button type="button" class="chip ' + (S.why.indexOf(x) > -1 ? 'on' : '') + '" data-w="' + esc(x) + '">' + (S.why.indexOf(x) > -1 ? '✓ ' : '') + esc(x) + '</button>').join('') + '</div><input id="cWT" style="margin-top:8px" maxlength="120" placeholder="' + (S.why.indexOf('Other') > -1 ? 'Type the reason *' : 'Or type the reason') + '" value="' + v(S.whyTxt) + '">'
        + '<label class="l">Address <small>(the last address of this customer, change if it is new)</small></label><input id="cA1" placeholder="House, street, area" value="' + v(S.a.addr1) + '"><div class="row"><input id="cLm" placeholder="Landmark (optional)" value="' + v(S.a.landmark) + '"><input id="cPin" inputmode="numeric" maxlength="6" placeholder="Pincode" value="' + v(S.a.pin) + '"></div><div class="row"><input id="cCity" placeholder="City" value="' + v(S.a.city) + '"><input id="cSt" placeholder="State" value="' + v(S.a.state) + '"></div>'
        + '<label class="l">New perfume to send *</label><div class="seg">' + [['perfume', 'Perfume'], ['chain', 'Chain'], ['other', 'Other product']].map(([k, l]) => '<button type="button" class="chip ' + (S.cat === k ? 'on' : '') + '" data-c="' + k + '">' + l + '</button>').join('') + '</div><input id="cQ" type="search" placeholder="Search the name…" autocomplete="off"><div class="res" id="cRes" style="display:none"></div><div id="cItems"></div>'
        + '<div class="free"><b>₹1</b><div><strong>Free order — the customer pays nothing</strong><small>Not counted as a sale, in the collection or in commission. It is your own work (My Day).</small></div></div></div><footer><button class="go" id="cGo" type="button">✅ Create the order ID</button></footer>';
      items();
      $('#cX').onclick = close; $('#cGo').onclick = create;
      let deb = 0; $('#cQ').oninput = () => { clearTimeout(deb); deb = setTimeout(search, 280); };
      $('#cPin').oninput = async () => { const p = $('#cPin').value.replace(/\D+/g, ''); if (p.length !== 6) return; try { const g = await api.get('wmo.pin', { pin: p }); if ($('#cPin') && $('#cPin').value.replace(/\D+/g, '') === p) { if (g.city && !$('#cCity').value) $('#cCity').value = g.city; if (g.state && !$('#cSt').value) $('#cSt').value = g.state; } } catch (e) { /* typed by hand */ } };
    }
    dr.onclick = (e) => {
      const k = e.target.closest('[data-k]'); if (k) { keep(); if (S.kind !== k.dataset.k) { S.kind = k.dataset.k; S.why = []; } draw(); return; }
      const w = e.target.closest('[data-w]'); if (w) { keep(); const i = S.why.indexOf(w.dataset.w); if (i > -1) S.why.splice(i, 1); else S.why.push(w.dataset.w); draw(); return; }
      const c = e.target.closest('[data-c]'); if (c) { keep(); S.cat = c.dataset.c; draw(); return; }
    };
    function items() { const el = $('#cItems'); if (!el) return; el.innerHTML = Array.from(S.items.values()).map((x) => '<div class="pk" data-vid="' + x.vid + '"><span>' + esc(x.title) + (x.label ? ' · ' + esc(x.label) : '') + '</span><span><button type="button" data-d="-1">−</button> <b>' + x.qty + '</b> <button type="button" data-d="1">+</button></span></div>').join(''); el.onclick = (e) => { const b = e.target.closest('[data-d]'); if (!b) return; const vid = +b.closest('.pk').dataset.vid, x = S.items.get(vid); if (!x) return; x.qty += +b.dataset.d; if (x.qty < 1) S.items.delete(vid); else if (x.qty > x.max) x.qty = x.max; items(); }; }
    async function search() {
      const q = $('#cQ').value.trim(), res = $('#cRes'), my = ++seq, cat = S.cat; if (q.length < 2) { res.style.display = 'none'; return; } res.style.display = ''; res.innerHTML = '<div>Searching…</div>';
      let d; try { d = await api.get('wch.search', { q, cat, limit: 12 }); } catch (e) { if (my === seq) res.innerHTML = '<div>' + esc(e.message) + '</div>'; return; }
      if (my !== seq || !$('#cRes')) return; const its = d.items || []; if (!its.length) { res.innerHTML = '<div>Nothing matches “' + esc(q) + '”.</div>'; return; }
      res.innerHTML = its.map((it, i) => '<div data-i="' + i + '"><b>' + esc(it.short || it.title) + '</b><span>' + (it.sizes || []).map((s, j) => '<button type="button" data-j="' + j + '"' + (s.in_stock ? '' : ' disabled') + '>' + esc(/^default title$/i.test(s.label || '') ? 'Add' : s.label) + '</button>').join(' ') + '</span></div>').join('');
      res.onclick = (e) => { const b = e.target.closest('button[data-j]'); if (!b || b.disabled) return; const it = its[+b.closest('[data-i]').dataset.i], s = it && it.sizes[+b.dataset.j]; if (!s) return; const ex = S.items.get(s.vid), max = cat === 'chain' ? 5 : (cat === 'other' ? 50 : 20); if (ex) { if (ex.qty < max) ex.qty++; } else S.items.set(s.vid, { vid: s.vid, cat, title: it.short || it.title, label: /^default title$/i.test(s.label || '') ? '' : (s.label || ''), qty: 1, max }); items(); };
    }
    const fail = (m) => { S.msg = '<div class="err">' + esc(m) + '</div>'; keep(); draw(); const b = dr.querySelector('.bd'); if (b) b.scrollTop = 0; };
    async function create() {
      if (S.busy) return; keep(); S.msg = '';
      const phone = S.phone.replace(/\D+/g, '').slice(-10), pin = S.a.pin.replace(/\D+/g, ''), whyAll = S.why.filter((x) => x !== 'Other').concat(S.whyTxt.trim() ? [S.whyTxt.trim()] : []);
      if (!/^[6-9]\d{9}$/.test(phone)) return fail('The customer\'s 10-digit mobile number is needed.');
      if (S.name.trim().length < 2) return fail('Type the customer\'s full name.');
      if (S.old.replace(/^#/, '').trim().length < 3) return fail('Type the old order number.');
      if (!whyAll.length) return fail(S.why.indexOf('Other') > -1 ? 'Type the reason in the box.' : 'Choose or type why.');
      if (S.a.addr1.trim().length < 5) return fail('Type the house / flat and street.');
      if (!/^[1-9]\d{5}$/.test(pin)) return fail('Type the 6-digit pincode.'); if (!S.a.city.trim() || !S.a.state.trim()) return fail('Type the city and the state.');
      if (!S.items.size) return fail('Add at least one perfume or product.');
      const its = Array.from(S.items.values()), pk = (c) => its.filter((x) => x.cat === c).map((x) => ({ vid: x.vid, qty: x.qty }));
      S.busy = true; const b = $('#cGo'); b.disabled = true; b.textContent = 'Creating…';
      try {
        await api.post('wmo.start', { phone, name: S.name.trim(), rx: 1 });
        const cust = { name: S.name.trim(), phone, addr1: S.a.addr1.trim(), landmark: S.a.landmark.trim(), pin, city: S.a.city.trim(), state: S.a.state.trim() };
        const c = await api.post('wmo.create', Object.assign({ phone, otype: S.kind, old_order: S.old.trim(), reason: whyAll.join(' · ').slice(0, 190), pay: 'prepaid', paid: 1, cod: 0, total: 1, lines: pk('perfume'), chains: pk('chain'), singles: pk('other'), cust }, f.me ? { handlers: [f.me] } : {}));
        const done = await api.post('wmo.confirm', { id: c.order.id, cust }); const no = done.order_no || c.order.order_no;
        try { await api.post('bd.cx_xo_link', { id: o.id, order_no: no }); } catch (e) { /* the order is made; the link can be written by hand */ }
        close(); if (window.toast) window.toast(no + ' is made from this complaint.'); if (typeof o.onDone === 'function') o.onDone(no);
      } catch (e) { S.busy = false; fail(e.message); }
    }
    draw();
  }
  window.CXO = { open };
})();
