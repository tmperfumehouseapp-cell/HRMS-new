/* wmo_ui.js — 🧾 "Manual order": a bottom sheet in the chat for an order that is NOT made on the website (the customer paid by QR / UPI, or pays on delivery).
 *
 *   WaManual.open({ phone, name, api, canSend, onDone })      WaManual.orders({ phone, name, api })  (the list of this chat's manual orders)
 *
 * 1. Payment: Full prepaid / Part prepaid + COD / Full COD. Upload the payment screenshot: Claude reads the amount and the UTR (you can correct them).
 * 2. ML and combo (6 x 20 ml ...), then pick the perfumes (the sizes of the combo) and, if wanted, a chain. Chains are billed at their FULL price, the rest of the total is the perfumes.
 * 3. "Create order and send link": the customer gets a link, fills the address and presses Done, and the order is final (Orders, Dispatch, Finance — see wmo.php).
 * Server: wmo.* (wmo.php) and the catalogue search wch.search (the same one the chain cart uses). Needs the global `api` (api.js).
 */
(function () {
  'use strict';
  if (window.WaManual) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (p) => '₹' + (Math.round(p) / 100).toLocaleString('en-IN', { minimumFractionDigits: p % 100 ? 2 : 0, maximumFractionDigits: 2 });
  const paise = (r) => Math.round(Number(r) * 100);
  const num = (v) => { const n = parseFloat(String(v == null ? '' : v).replace(/[^\d.]/g, '')); return isFinite(n) ? n : 0; };
  const errMsg = (e) => (e && e.message) || 'Something went wrong. Try again.';
  const SIZES = [10, 20, 30, 50, 100], MLS = [60, 100, 120, 150, 180, 200, 240, 300];

  /** The ways to make `ml` from the common sizes: one size (6 x 20) first, then two sizes. */
  function combos(ml) {
    const one = [], two = [];
    SIZES.forEach((a) => { if (ml % a === 0 && ml / a <= 20) one.push([{ ml: a, n: ml / a }]); });
    one.sort((x, y) => Math.abs(x[0].n - 6) - Math.abs(y[0].n - 6));
    for (let i = 0; i < SIZES.length; i++) for (let j = i + 1; j < SIZES.length; j++) {
      const a = SIZES[i], b = SIZES[j];
      for (let nb = 1; nb * b < ml; nb++) { const rest = ml - nb * b; if (rest % a === 0) { const na = rest / a; if (na + nb <= 14 && na >= 1) two.push([{ ml: a, n: na }, { ml: b, n: nb }]); } }
    }
    two.sort((x, y) => (x[0].n + x[1].n) - (y[0].n + y[1].n));
    return one.concat(two.slice(0, 6));
  }
  const comboLabel = (c) => c.map((x) => x.n + ' × ' + x.ml + ' ml').join(' + ');

  const CSS = `
  .mo-ov{position:fixed; inset:0; z-index:10060; background:#0008; display:flex; align-items:flex-end; justify-content:center; font-family:inherit;}
  .mo-sh{background:#fff; color:#111b21; width:100%; max-width:660px; height:95vh; height:95dvh; border-radius:18px 18px 0 0; display:flex; flex-direction:column; box-shadow:0 -8px 30px #0004; overflow:hidden; font-size:14px;}
  @media (min-width:761px){ .mo-ov{align-items:center;} .mo-sh{border-radius:18px; height:min(90vh,860px);} }
  .mo-sh *{box-sizing:border-box;} .mo-sh button,.mo-sh input,.mo-sh select{font:inherit;} .mo-sh [hidden]{display:none !important;}
  .mo-hd{display:flex; align-items:center; gap:8px; padding:12px 14px 8px; border-bottom:1px solid #eef0f3;} .mo-hd b{font-size:17px;} .mo-hd small{display:block; font-size:12.5px; color:#667781; margin-top:1px;} .mo-hd .sp{flex:1;}
  .mo-x{flex:none; width:40px; height:40px; border:0; border-radius:50%; background:#f0f2f5; color:#54656f; font-size:18px; cursor:pointer;} .mo-lk{border:0; background:#eef6ff; color:#0b5cad; border-radius:16px; padding:7px 12px; font-weight:700; cursor:pointer;}
  .mo-bd{flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; padding:10px 14px 16px;}
  .mo-h{margin:16px 0 6px; font-size:11.5px; font-weight:800; color:#667781; text-transform:uppercase; letter-spacing:.4px; display:flex; justify-content:space-between; gap:8px;} .mo-h:first-child{margin-top:2px;} .mo-h em{font-style:normal; font-weight:600; text-transform:none; letter-spacing:0;}
  .mo-seg{display:grid; grid-template-columns:repeat(3,1fr); gap:6px;} .mo-seg button{min-height:48px; border:1.5px solid #d1d7db; border-radius:12px; background:#fff; font-weight:800; cursor:pointer; line-height:1.2; padding:4px;} .mo-seg button.on{border-color:#00a884; background:#e8f8f2; color:#005c4b;}
  .mo-in{display:flex; align-items:center; gap:8px; border:2px solid #d1d7db; border-radius:14px; padding:0 14px; background:#fff;} .mo-in:focus-within{border-color:#00a884;} .mo-in span{font-size:20px; font-weight:800; color:#54656f;} .mo-in input{flex:1; min-width:0; border:0; outline:0; height:50px; font-size:22px; font-weight:800; background:transparent;}
  .mo-r2{display:grid; grid-template-columns:1fr 1fr; gap:8px;} .mo-lab{font-size:12px; color:#667781; margin:0 0 3px; font-weight:700;} .mo-tx{width:100%; height:42px; border:1px solid #d1d7db; border-radius:10px; padding:0 10px; background:#f7f8f9;}
  .mo-shot{display:flex; gap:10px; align-items:center; border:2px dashed #b8c2c9; border-radius:14px; padding:10px; background:#fafbfb;} .mo-shot img{width:64px; height:64px; object-fit:cover; border-radius:10px; flex:none;} .mo-shot .t{flex:1; min-width:0;}
  .mo-btn{min-height:42px; padding:0 14px; border:1.5px solid #00a884; border-radius:21px; background:#fff; color:#005c4b; font-weight:800; cursor:pointer;} .mo-btn.d{background:#1c2430; border-color:#1c2430; color:#fff;}
  .mo-chips{display:flex; flex-wrap:wrap; gap:6px;} .mo-chips button{min-height:38px; padding:0 13px; border:1.5px solid #d1d7db; border-radius:19px; background:#fff; font-weight:700; cursor:pointer;} .mo-chips button.on{border-color:#00a884; background:#e8f8f2; color:#005c4b;}
  .mo-note{margin:8px 0; padding:9px 12px; border-radius:10px; font-size:13px; line-height:1.4; background:#fff6d6; color:#7a5200;} .mo-note.bad{background:#fde8e8; color:#9a2620;} .mo-note.ok{background:#e3f6ec; color:#12794a;} .mo-note b{font-weight:800;}
  .mo-sb input{width:100%; height:44px; border:1px solid #d1d7db; border-radius:22px; padding:0 16px; font-size:16px; background:#f0f2f5; outline:none;} .mo-sb input:focus{border-color:#00a884; background:#fff;}
  .mo-res{margin-top:6px; max-height:230px; overflow-y:auto; border:1px solid #eef0f3; border-radius:12px;} .mo-res:empty{display:none;}
  .mo-it{display:flex; gap:10px; align-items:center; padding:8px 10px; border-bottom:1px solid #f0f2f5;} .mo-it:last-child{border-bottom:0;} .mo-it img{width:46px; height:46px; border-radius:9px; object-fit:cover; background:#e9edef; flex:none;} .mo-it .mid{flex:1; min-width:0;} .mo-it .nm{font-weight:700; line-height:1.25;}
  .mo-sz{display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;} .mo-sz button{min-height:36px; padding:0 12px; border:1px solid #00a884; border-radius:18px; background:#fff; color:#005c4b; font-size:13px; font-weight:700; cursor:pointer;} .mo-sz button:disabled{opacity:.35; cursor:default; border-color:#c5ccd1; color:#667781;}
  .mo-empty{padding:14px; text-align:center; color:#667781; font-size:13px; line-height:1.5;}
  .mo-ln{display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px dashed #e4e7ec;} .mo-ln:last-child{border-bottom:0;} .mo-ln .mid{flex:1; min-width:0;} .mo-ln .nm{font-weight:700;} .mo-ln small{color:#667781;}
  .mo-q{display:flex; align-items:center; gap:2px; flex:none;} .mo-q button{width:34px; height:34px; border:0; border-radius:50%; background:#f0f2f5; font-size:18px; font-weight:700; cursor:pointer;} .mo-q b{min-width:22px; text-align:center;} .mo-amt{flex:none; min-width:70px; text-align:right; font-weight:800;}
  .mo-sum{margin:0 0 8px; border-radius:12px; background:#f0fbf7; border:1px solid #c8eedc; padding:8px 12px;} .mo-sum.bad{background:#fff4f2; border-color:#f6c9c3;} .mo-sum .r{display:flex; justify-content:space-between; gap:10px; padding:2px 0;} .mo-sum .r.big{font-size:16px; font-weight:800;}
  .mo-ft{border-top:1px solid #e9edef; padding:10px 14px calc(10px + env(safe-area-inset-bottom)); background:#fff;} .mo-go{width:100%; height:50px; border:0; border-radius:25px; background:#008069; color:#fff; font-size:16px; font-weight:800; cursor:pointer;} .mo-go:disabled{opacity:.5; cursor:default;} .mo-go.alt{background:#f0f2f5; color:#111b21; margin-top:8px;}
  .mo-card{border:1px solid #e4e7ec; border-radius:14px; padding:10px 12px; margin:0 0 10px;} .mo-card .top{display:flex; justify-content:space-between; gap:8px; align-items:center;} .mo-pill{display:inline-block; padding:2px 9px; border-radius:11px; font-size:12px; font-weight:800; background:#eef0f3;} .mo-pill.g{background:#e3f6ec; color:#12794a;} .mo-pill.o{background:#fff1d6; color:#8a5a00;} .mo-pill.r{background:#fde8e8; color:#9a2620;}
  .mo-card pre{white-space:pre-wrap; font:13px/1.4 inherit; background:#f7f8f9; border-radius:10px; padding:8px 10px; margin:8px 0 0;} .mo-acts{display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;}
  `;
  function css() { if (document.getElementById('moCss')) return; const s = document.createElement('style'); s.id = 'moCss'; s.textContent = CSS; document.head.appendChild(s); }
  let cur = null;
  function close() { if (!cur) return; document.removeEventListener('keydown', cur.key); cur.ov.remove(); document.body.style.overflow = cur.bodyOv; cur = null; }
  function shell(title, sub) {
    if (cur) close(); css(); const ov = document.createElement('div'); ov.className = 'mo-ov';
    ov.innerHTML = '<div class="mo-sh" role="dialog" aria-modal="true"><div class="mo-hd"><div><b>' + esc(title) + '</b><small>' + esc(sub || '') + '</small></div><span class="sp"></span><span id="moTop"></span><button type="button" class="mo-x" aria-label="Close">✕</button></div><div class="mo-bd" id="moBd"></div><div class="mo-ft" id="moFt"></div></div>';
    document.body.appendChild(ov); const bodyOv = document.body.style.overflow; document.body.style.overflow = 'hidden';
    const key = (e) => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', key); cur = { ov, key, bodyOv };
    ov.addEventListener('click', (e) => { if (e.target === ov) close(); }); ov.querySelector('.mo-x').onclick = close;
    return { bd: ov.querySelector('#moBd'), ft: ov.querySelector('#moFt'), top: ov.querySelector('#moTop') };
  }
  const copy = async (t) => { try { await navigator.clipboard.writeText(t); return true; } catch (e) { const a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); } catch (er) { } a.remove(); return true; } };
  /** a picture from the phone, shrunk to 1280 px and sent as JPEG (a 5 MB photo becomes about 250 KB) */
  function shrink(file) {
    return new Promise((res, rej) => {
      const fr = new FileReader(); fr.onerror = () => rej(new Error('Could not read the picture.'));
      fr.onload = () => { const im = new Image(); im.onerror = () => rej(new Error('This is not a picture.')); im.onload = () => {
        const k = Math.min(1, 1280 / Math.max(im.width, im.height)), w = Math.max(1, Math.round(im.width * k)), h = Math.max(1, Math.round(im.height * k)), cv = document.createElement('canvas'); cv.width = w; cv.height = h; cv.getContext('2d').drawImage(im, 0, 0, w, h);
        const url = cv.toDataURL('image/jpeg', 0.82); res({ url, b64: url.split(',')[1], mime: 'image/jpeg' }); }; im.src = fr.result; };
      fr.readAsDataURL(file);
    });
  }

  // ------------------------------------------------------------------------------------------------------------------ the list of this chat's manual orders
  async function orders(o) {
    o = o || {}; const API = o.api || (typeof api !== 'undefined' ? api : null); if (!API) { alert('Not logged in.'); return; }
    const { bd, ft } = shell('Manual orders', o.name || ''); ft.innerHTML = '<button type="button" class="mo-go" id="moNew">🧾 New manual order</button>'; ft.querySelector('#moNew').onclick = () => open(o);
    async function load() {
      bd.innerHTML = '<div class="mo-empty">Loading…</div>'; let d; try { d = await API.get('wmo.list', { phone: o.phone }); } catch (e) { bd.innerHTML = '<div class="mo-note bad">' + esc(errMsg(e)) + '</div>'; return; }
      const L = d.orders || []; if (!L.length) { bd.innerHTML = '<div class="mo-empty">No manual order for this customer yet.</div>'; return; }
      const st = { draft: ['Link not sent', 'o'], sent: ['Waiting for the customer', 'o'], confirmed: ['Confirmed', 'g'], cancelled: ['Cancelled', 'r'] };
      bd.innerHTML = L.map((x) => '<div class="mo-card" data-id="' + x.id + '"><div class="top"><b>' + esc(x.order_no) + ' · ' + rs(paise(x.total)) + '</b><span class="mo-pill ' + (st[x.status] || ['', ''])[1] + '">' + esc((st[x.status] || [x.status])[0]) + '</span></div>'
        + '<div style="color:#667781;margin-top:3px">' + esc(x.pay === 'cod' ? 'Full COD' : x.pay === 'partial' ? 'Part prepaid ' + rs(paise(x.paid)) + ' + COD ' + rs(paise(x.cod)) : 'Fully prepaid') + (x.utr ? ' · UTR ' + esc(x.utr) : '') + '</div>'
        + '<div style="margin-top:4px">' + x.lines.map((l) => esc(l.qty + ' × ' + l.short + (l.chain ? '' : ' ' + l.label))).join(', ') + '</div>'
        + (x.status === 'confirmed' ? (x.phone_match ? '' : '<div class="mo-note bad" style="margin:6px 0 0">⚠ The mobile number the customer wrote is different from this chat.</div>') + '<pre>' + esc(x.ship || '') + '</pre><div class="mo-acts"><button type="button" class="mo-btn" data-a="ship">Copy shipping details</button><button type="button" class="mo-btn" data-a="inv">' + (x.inv ? (x.inv.sent ? '🧾 GST invoice ' + esc(x.inv.no) + ' ✓ · send again' : '🧾 Send GST invoice ' + esc(x.inv.no)) : '🧾 Make and send the GST invoice') + '</button><button type="button" class="mo-btn" data-a="sr"' + (x.sr && x.sr.id ? ' disabled' : '') + '>' + (x.sr && x.sr.id ? '🚚 In Shiprocket · ' + esc(x.sr.id) : '🚚 Send to Shiprocket') + '</button></div>' + (x.sr && !x.sr.id && x.sr.note ? '<div class="mo-note bad" style="margin:6px 0 0">' + esc(x.sr.note) + '</div>' : '')
          : x.status === 'cancelled' ? '' : '<div class="mo-acts"><button type="button" class="mo-btn" data-a="copy">Copy link</button><button type="button" class="mo-btn" data-a="send">' + (x.status === 'sent' ? 'Send again' : 'Send link') + '</button><button type="button" class="mo-btn" data-a="cancel">Cancel</button></div>') + '</div>').join('');
      bd.onclick = async (e) => {
        const b = e.target.closest('[data-a]'); if (!b) return; const card = b.closest('.mo-card'), x = L.find((q) => q.id === +card.dataset.id); if (!x) return;
        if (b.dataset.a === 'ship') { await copy(x.ship); b.textContent = 'Copied ✓'; return; }
        if (b.dataset.a === 'inv') { b.disabled = true; b.textContent = 'Working…'; try { const r = await API.post('wmo.invoice', { id: x.id }); alert(r.note || 'Done'); } catch (er) { alert(errMsg(er)); } load(); return; }
        if (b.dataset.a === 'sr') { b.disabled = true; b.textContent = 'Working…'; try { const r = await API.post('wmo.shiprocket', { id: x.id }); alert(r.note || 'Done'); } catch (er) { alert(errMsg(er)); } load(); return; }
        if (b.dataset.a === 'copy') { await copy(x.link); b.textContent = 'Copied ✓'; return; }
        if (b.dataset.a === 'cancel') { if (!confirm('Cancel ' + x.order_no + '? The customer\'s link stops working.')) return; try { await API.post('wmo.cancel', { id: x.id }); load(); } catch (er) { alert(errMsg(er)); } return; }
        if (b.dataset.a === 'send') { b.disabled = true; b.textContent = 'Sending…'; try { await API.post('wmo.send', { id: x.id }); b.textContent = 'Sent ✓'; setTimeout(load, 700); } catch (er) { b.disabled = false; b.textContent = 'Send link'; alert(errMsg(er) + ((er.data && er.data.link) ? '\n\nThe link (copy it into the chat):\n' + er.data.link : '')); } }
      };
    }
    load();
  }

  // ------------------------------------------------------------------------------------------------------------------ the new order
  function open(o) {
    o = o || {}; const API = o.api || (typeof api !== 'undefined' ? api : null); if (!API) { alert('Not logged in.'); return; }
    const S = { pay: 'prepaid', total: '', paid: '', utr: '', shot: null, read: null, reading: false, shot2: null, proof: null, proofing: false, ml: 0, mlIn: '', combo: null, perf: new Map(), chain: new Map(), single: new Map(), otype: o.outside ? 'query' : '', cust: { phone: o.phone || '', name: o.name || '', addr1: '', landmark: '', pin: '', city: '', state: '' }, busy: false, order: null, seq: { perfume: 0, chain: 0, other: 0 }, cache: {}, msg: '' };
    const { bd, ft, top } = shell('Manual order', (o.name || '') + ' · QR / UPI / COD'); top.innerHTML = '<button type="button" class="mo-lk" id="moList">Orders</button>'; top.querySelector('#moList').onclick = () => orders(o);

    const needShot = () => S.pay !== 'cod';
    const paidP = () => (S.pay === 'cod' ? 0 : paise(num(S.paid)));
    const totalP = () => (S.pay === 'prepaid' ? paidP() : paise(num(S.total)));
    const sum = (m) => { let t = 0; m.forEach((x) => { t += x.price * x.qty; }); return t; };
    const hasItems = () => S.perf.size > 0 || S.single.size > 0;
    const addrOk = () => !!o.outside && S.cust.name.trim().length >= 2 && S.cust.addr1.trim().length >= 5 && /^[1-9]\d{5}$/.test(S.cust.pin) && S.cust.city.trim() !== '' && S.cust.state.trim() !== '';   // the salesperson typed the whole address: the order is final at once, no link
    const mlOf = () => { let t = 0; S.perf.forEach((x) => { t += (x.ml || 0) * x.qty; }); return t; };
    const filled = (ml) => { let n = 0; S.perf.forEach((x) => { if (x.ml === ml) n += x.qty; }); return n; };
    function calc() {
      const T = totalP(), C = sum(S.chain), SG = sum(S.single), L = sum(S.perf), P = T - C - SG, pd = paidP(), cod = Math.max(0, T - pd);
      let pieces = 0; S.perf.forEach((x) => { pieces += x.qty; });
      const comboOk = !S.perf.size || !S.combo || S.combo.every((c) => filled(c.ml) === c.n) && Array.from(S.perf.values()).every((x) => S.combo.some((c) => c.ml === x.ml));
      const proofOk = !needShot() || (S.shot && S.shot2 && S.proof && S.proof.ok && Math.abs(pd - Math.round((S.proof.amount || 0) * 100)) <= 100);
      const why = !needShot() ? '' : !S.shot ? "Upload the customer's paid screen." : !S.shot2 ? "Upload the receiver's screen." : S.proofing ? 'Checking the two screenshots…' : !S.proof ? '' : !S.proof.ok ? S.proof.reason : (Math.abs(pd - Math.round((S.proof.amount || 0) * 100)) > 100 ? 'The amount received must equal the screenshots (' + rs(Math.round(S.proof.amount * 100)) + ').' : '');
      const custOk = !o.outside || (S.cust.phone.replace(/\D+/g, '').slice(-10).length === 10); const singlesOk = Array.from(S.single.values()).every((x) => x.price >= 100);
      const ok = custOk && singlesOk && T >= 10000 && hasItems() && (S.perf.size ? P >= pieces * 1000 : P === 0) && (!needShot() || (proofOk && pd >= 100)) && !S.proofing && (S.pay !== 'partial' || (pd >= 100 && pd < T)) && comboOk && !S.reading && !S.busy;
      return { T, C, SG, L, P, pd, cod, comboOk, ok, why, pieces };
    }

    // ---- sections
    function drawAll() {
      const k = calc();
      bd.innerHTML = (o.outside ? custHtml() : '') + '<div class="mo-h">1 · How did the customer pay?</div><div class="mo-seg" id="moPay">'
        + [['prepaid', 'Full prepaid<br><small>QR / UPI</small>'], ['partial', 'Part prepaid<br><small>+ COD balance</small>'], ['cod', 'Full COD<br><small>pay on delivery</small>']].map((x) => '<button type="button" data-p="' + x[0] + '" class="' + (S.pay === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>'
        + (needShot() ? '<div class="mo-h">Payment screenshot <em>Claude reads the amount</em></div><div class="mo-shot"><label style="cursor:pointer;flex:none"><input type="file" id="moFile" accept="image/*" hidden>' + (S.shot ? '<img alt="" src="' + esc(S.shot.url) + '">' : '<div class="mo-btn" style="display:flex;align-items:center">📷 Upload</div>') + '</label><div class="t" id="moRead">' + readHtml() + '</div></div>'
          + '<div class="mo-h">Receiver screenshot <em>where the money arrived: business app, bank, Razorpay or Quick</em></div><div class="mo-shot"><label style="cursor:pointer;flex:none"><input type="file" id="moFile2" accept="image/*" hidden>' + (S.shot2 ? '<img alt="" src="' + esc(S.shot2.url) + '">' : '<div class="mo-btn" style="display:flex;align-items:center">📷 Upload</div>') + '</label><div class="t" id="moProof">' + proofHtml() + '</div></div>' : '')
        + '<div class="mo-h">Amounts</div><div class="mo-r2">'
        + (needShot() ? '<div><div class="mo-lab">Amount received ₹</div><div class="mo-in"><span>₹</span><input id="moPaid" inputmode="decimal" autocomplete="off" placeholder="0" value="' + esc(S.paid) + '"></div></div>' : '')
        + (S.pay === 'prepaid' ? '<div><div class="mo-lab">Order total</div><div class="mo-in"><span>₹</span><input disabled value="' + (k.T ? esc(String(k.T / 100)) : '') + '" style="color:#54656f"></div></div>' : '<div><div class="mo-lab">Agreed order total ₹</div><div class="mo-in"><span>₹</span><input id="moTot" inputmode="decimal" autocomplete="off" placeholder="0" value="' + esc(S.total) + '"></div></div>') + '</div>'
        + (S.pay === 'prepaid' && S.shot ? '<div class="mo-lab" style="margin-top:6px">A full prepaid order is exactly what the screenshot shows. Change the amount above if Claude read it wrong.</div>' : '')
        + (S.pay === 'partial' && k.T && k.pd ? '<div class="mo-note" style="margin-top:8px">Customer pays <b>' + rs(k.cod) + '</b> on delivery.</div>' : '') + (S.pay === 'cod' && k.T ? '<div class="mo-note" style="margin-top:8px">The customer pays the whole <b>' + rs(k.T) + '</b> on delivery.</div>' : '')
        + '<div class="mo-h">2 · How many ML?</div><div class="mo-chips" id="moMl">' + MLS.map((m) => '<button type="button" data-m="' + m + '" class="' + (S.ml === m ? 'on' : '') + '">' + m + ' ml</button>').join('') + '<input class="mo-tx" id="moMlIn" style="width:92px" inputmode="numeric" placeholder="other ml" value="' + esc(S.mlIn) + '"></div>'
        + (S.ml ? '<div class="mo-h">Combo <em>' + S.ml + ' ml</em></div><div class="mo-chips" id="moCombo">' + combos(S.ml).map((c, i) => '<button type="button" data-c="' + i + '" class="' + (S.combo && comboLabel(S.combo) === comboLabel(c) ? 'on' : '') + '">' + esc(comboLabel(c)) + '</button>').join('') + '<button type="button" data-c="any" class="' + (S.combo === null ? 'on' : '') + '">Any mix</button></div>' : '')
        + '<div id="moLines"></div><div class="mo-h">3 · Add perfumes</div><div class="mo-sb"><input id="moQp" type="search" placeholder="Search a perfume…" autocomplete="off"></div><div class="mo-res" id="moRp"></div>'
        + '<div class="mo-h">Other products <em>shower gel, beard balm, attar … each at its own price</em></div><div id="moSg"></div><div class="mo-sb"><input id="moQo" type="search" placeholder="Search shower gel, beard balm, wax …" autocomplete="off"></div><div class="mo-res" id="moRo"></div>'
        + '<div class="mo-h">Chain <em>optional · billed at full price</em></div><div class="mo-sb"><input id="moQc" type="search" placeholder="Search a chain…" autocomplete="off"></div><div class="mo-res" id="moRc"></div><div id="moMsg" style="margin-top:12px"></div>';
      bindAll(); drawLines(); drawSingles(); drawFoot();
    }
    function custHtml() {
      const c = S.cust, v = (x) => esc(x || '');
      return '<div class="mo-h">Customer</div><div class="mo-r2"><div><div class="mo-lab">Mobile number *</div><div class="mo-in"><input id="cuPh" inputmode="numeric" maxlength="14" placeholder="10-digit number" value="' + v(c.phone) + '"></div></div><div><div class="mo-lab">Name</div><div class="mo-in"><input id="cuNm" placeholder="Customer name" value="' + v(c.name) + '"></div></div></div>'
        + '<div class="mo-lab" style="margin-top:8px">Address <em>optional — the customer checks it on the link</em></div><div class="mo-in"><input id="cuAd" placeholder="House, street, area" value="' + v(c.addr1) + '"></div>'
        + '<div class="mo-r2" style="margin-top:6px"><div class="mo-in"><input id="cuLm" placeholder="Landmark" value="' + v(c.landmark) + '"></div><div class="mo-in"><input id="cuPin" inputmode="numeric" maxlength="6" placeholder="Pincode" value="' + v(c.pin) + '"></div></div>'
        + '<div class="mo-r2" style="margin-top:6px"><div class="mo-in"><input id="cuCi" placeholder="City (fills from pincode)" value="' + v(c.city) + '"></div><div class="mo-in"><input id="cuSt" placeholder="State" value="' + v(c.state) + '"></div></div>'
        + '<div class="mo-h">This order came from</div><div class="mo-seg" id="moOt"><button type="button" data-o="query" class="' + (S.otype === 'query' ? 'on' : '') + '">💬 Query</button><button type="button" data-o="campaign" class="' + (S.otype === 'campaign' ? 'on' : '') + '">📣 Campaign</button></div><div class="mo-lab">It is counted for you automatically.</div>';
    }
    function drawSingles() {
      const el = bd.querySelector('#moSg'); if (!el) return;
      el.innerHTML = Array.from(S.single.values()).map((x) => '<div class="mo-ln" data-vid="' + x.vid + '"><div class="mid"><div class="nm">' + esc(x.title) + ' <span style="font-size:10px;font-weight:800;background:#fff3d6;color:#8a5a00;border-radius:8px;padding:2px 6px">SINGLE</span></div><small>' + (x.label ? esc(x.label) + ' · ' : '') + 'shop price ' + rs(x.mrp) + ' · line ' + rs(x.price * x.qty) + '</small></div><div class="mo-q"><button type="button" data-sq="-1">−</button><b>' + x.qty + '</b><button type="button" data-sq="1">+</button></div><div class="mo-in" style="width:92px;flex:none"><span>₹</span><input data-sp="1" inputmode="decimal" value="' + esc(String(x.price / 100)) + '"></div></div>').join('');
    }
    function readHtml() {
      if (S.reading) return '<b>🤖 Claude is reading the screenshot…</b>';
      if (!S.shot) return '<div style="color:#667781">Upload the customer\'s payment screenshot (PhonePe, GPay, Paytm…). The amount and the UTR are read for you.</div>';
      const r = S.read; if (!r) return '<div style="color:#667781">Type the amount below.</div>'; if (r.error) return '<div class="mo-note bad" style="margin:0">' + esc(r.error) + '</div>';
      return '<div><b>' + (r.status === 'success' ? '✅ Payment successful' : r.status === 'failed' ? '❌ Payment failed' : r.status === 'pending' ? '⏳ Payment pending' : '❔ Status not clear') + '</b>' + (r.amount ? ' · ' + rs(paise(r.amount)) : '') + '</div>'
        + '<div style="color:#667781;font-size:12.5px">' + (r.utr ? 'UTR ' + esc(r.utr) + ' · ' : '') + esc([r.app, r.date, r.payer].filter(Boolean).join(' · ')) + '</div>'
        + (r.dup ? '<div class="mo-note bad" style="margin:6px 0 0">⚠ This UTR is already used on order <b>' + esc(r.dup) + '</b>. One payment cannot pay two orders.</div>' : '') + (!r.ok ? '<div class="mo-note bad" style="margin:6px 0 0">This does not look like a payment screenshot.</div>' : '') + (r.note ? '<div class="mo-note" style="margin:6px 0 0">' + esc(r.note) + '</div>' : '');
    }
    function proofHtml() {
      if (S.proofing) return '<b>🤖 Claude is comparing the two screenshots…</b>';
      if (!S.shot2) return '<div style="color:#667781">Upload the screen from the side that RECEIVED the money. Both screenshots must show the same amount and the same UTR.</div>';
      const v = S.proof; if (!v) return '<div style="color:#667781">Upload the customer\'s paid screen as well, then the two are compared.</div>';
      if (v.error) return '<div class="mo-note bad" style="margin:0">' + esc(v.error) + '</div>';
      return v.ok ? '<div><b>✅ Verified</b> · ' + rs(Math.round((v.amount || 0) * 100)) + ' · UTR ' + esc(v.utr || '') + '</div><div style="color:#667781;font-size:12.5px">Gateway: ' + esc(v.gateway || '') + ' · both screenshots agree</div>'
        : '<div class="mo-note bad" style="margin:0"><b>❌ The order cannot be made.</b><br>' + esc(v.reason || '') + '</div>';
    }
    async function runProof() {
      if (!S.shot || !S.shot2) return; S.proofing = true; S.proof = null; drawAll();
      try { const d = await API.post('wmo.proof_check', { image: S.shot.b64, mime: S.shot.mime, image2: S.shot2.b64, mime2: S.shot2.mime }); S.proof = d.verdict || null; if (S.proof && S.proof.ok) { S.paid = String(S.proof.amount); S.utr = S.proof.utr || S.utr; } }
      catch (e) { S.proof = { ok: false, reason: errMsg(e), error: errMsg(e) }; }
      S.proofing = false; drawAll();
    }
    function lineHtml(x, cat) {
      return '<div class="mo-ln" data-vid="' + x.vid + '" data-cat="' + cat + '"><div class="mid"><div class="nm">' + esc(x.title) + '</div><small>' + (x.label ? esc(x.label) + ' · ' : '') + rs(x.price) + (x.qty > 1 ? ' × ' + x.qty : '') + '</small></div><div class="mo-q"><button type="button" data-q="-1">−</button><b>' + x.qty + '</b><button type="button" data-q="1">+</button></div></div>';
    }
    function drawLines() {
      const el = bd.querySelector('#moLines'); if (!el) return; const need = S.combo ? '<span>' + S.combo.map((c) => '<b style="color:' + (filled(c.ml) === c.n ? '#12794a' : '#9a5b00') + '">' + filled(c.ml) + '/' + c.n + ' × ' + c.ml + ' ml</b>').join(' · ') + '</span>' : '';
      el.innerHTML = '<div class="mo-h">In this order<em>' + (S.perf.size ? mlOf() + ' ml' : '') + ' ' + need + '</em></div>' + (S.perf.size ? Array.from(S.perf.values()).map((x) => lineHtml(x, 'perfume')).join('') : '<div style="color:#8696a0;font-size:13px;padding:4px 0">No perfume yet — search below.</div>')
        + (S.chain.size ? Array.from(S.chain.values()).map((x) => lineHtml(x, 'chain')).join('') : '');
    }
    function drawFoot() {
      const k = calc(); let sm = '';
      if (k.T && hasItems()) { const bad = S.perf.size ? k.P < k.pieces * 1000 : k.P !== 0; sm = '<div class="mo-sum' + (bad ? ' bad' : '') + '"><div class="r big"><span>' + (S.perf.size ? 'Perfumes get' : 'Products + chain = total?') + '</span><b>' + (S.perf.size ? (k.P > 0 ? rs(k.P) : '—') : (k.P === 0 ? '✓' : 'differs by ' + rs(Math.abs(k.P)))) + '</b></div><div class="r"><span>Total ' + rs(k.T) + (S.chain.size ? ' − chain ' + rs(k.C) + ' (full price)' : '') + (S.single.size ? ' − other products ' + rs(k.SG) : '') + '</span><span>' + (k.pd ? 'paid ' + rs(k.pd) : '') + (k.cod ? ' · COD ' + rs(k.cod) : '') + '</span></div></div>'; }
      const miss = S.combo && !k.comboOk ? '<div class="mo-note">The perfumes must match the combo (' + esc(comboLabel(S.combo)) + ') or choose “Any mix”.</div>' : '';
      ft.innerHTML = sm + miss + '<div id="moFm">' + S.msg + '</div><button type="button" class="mo-go" id="moGo"' + (k.ok ? '' : ' disabled') + '>' + (S.busy ? 'Working…' : addrOk() ? 'Create the final order' + (k.ok ? ' · ' + rs(k.T) : '') : 'Create order' + (k.ok ? ' · ' + rs(k.T) : '') + ' and send the link') + '</button>';
      ft.querySelector('#moGo').onclick = make;
    }
    const fm = (h, cls) => { S.msg = h ? '<div class="mo-note ' + (cls || '') + '">' + h + '</div>' : ''; const el = ft.querySelector('#moFm'); if (el) el.innerHTML = S.msg; };

    function bindAll() {
      bd.querySelector('#moPay').onclick = (e) => { const b = e.target.closest('[data-p]'); if (!b) return; S.pay = b.dataset.p; if (S.pay === 'cod') { S.shot = null; S.read = null; S.shot2 = null; S.proof = null; S.paid = ''; } fm(''); drawAll(); };
      const f = bd.querySelector('#moFile'); if (f) f.onchange = async () => { const file = f.files && f.files[0]; if (!file) return; S.reading = true; S.read = null; S.shot = null; drawAll();
        try { S.shot = await shrink(file); } catch (e) { S.reading = false; fm(esc(errMsg(e)), 'bad'); drawAll(); return; }
        drawAll(); try { S.read = await API.post('wmo.shot_read', { image: S.shot.b64, mime: S.shot.mime }); if (S.read && S.read.amount && !S.paid) S.paid = String(S.read.amount); if (S.read && S.read.utr) S.utr = S.read.utr; } catch (e) { S.read = { error: errMsg(e) }; }
        S.reading = false; S.proof = null; drawAll(); if (S.shot2) runProof(); };
      const f2 = bd.querySelector('#moFile2'); if (f2) f2.onchange = async () => { const file = f2.files && f2.files[0]; if (!file) return; S.proof = null;
        try { S.shot2 = await shrink(file); } catch (e) { fm(esc(errMsg(e)), 'bad'); drawAll(); return; }
        drawAll(); if (S.shot) runProof(); };
      const pd = bd.querySelector('#moPaid'); if (pd) pd.oninput = () => { const v = pd.value.replace(/[^\d.]/g, ''); if (v !== pd.value) pd.value = v; S.paid = v; refresh(); };
      const tt = bd.querySelector('#moTot'); if (tt) tt.oninput = () => { const v = tt.value.replace(/[^\d.]/g, ''); if (v !== tt.value) tt.value = v; S.total = v; refresh(); };
      bd.querySelector('#moMl').onclick = (e) => { const b = e.target.closest('[data-m]'); if (!b) return; S.ml = +b.dataset.m; S.mlIn = ''; S.combo = combos(S.ml)[0] || null; drawAll(); };
      bd.querySelector('#moMlIn').onchange = (e) => { const v = Math.round(num(e.target.value)); S.mlIn = e.target.value; S.ml = v >= 10 && v <= 2000 ? v : 0; S.combo = S.ml ? (combos(S.ml)[0] || null) : null; drawAll(); };
      const cb = bd.querySelector('#moCombo'); if (cb) cb.onclick = (e) => { const b = e.target.closest('[data-c]'); if (!b) return; S.combo = b.dataset.c === 'any' ? null : combos(S.ml)[+b.dataset.c]; drawAll(); };
      bd.querySelector('#moLines').onclick = (e) => { const b = e.target.closest('[data-q]'); if (!b) return; const row = b.closest('.mo-ln'), m = row.dataset.cat === 'chain' ? S.chain : S.perf, x = m.get(+row.dataset.vid); if (!x) return; x.qty += +b.dataset.q; if (x.qty < 1) m.delete(x.vid); else if (x.qty > (row.dataset.cat === 'chain' ? 5 : 20)) x.qty = (row.dataset.cat === 'chain' ? 5 : 20); drawLines(); drawFoot(); drawComboHint(); };
      search('perfume', bd.querySelector('#moQp'), bd.querySelector('#moRp')); search('chain', bd.querySelector('#moQc'), bd.querySelector('#moRc')); search('other', bd.querySelector('#moQo'), bd.querySelector('#moRo'));
      const sg = bd.querySelector('#moSg'); if (sg) { sg.onclick = (e) => { const b = e.target.closest('[data-sq]'); if (!b) return; const x = S.single.get(+b.closest('.mo-ln').dataset.vid); if (!x) return; x.qty += +b.dataset.sq; if (x.qty < 1) S.single.delete(x.vid); else if (x.qty > 50) x.qty = 50; drawSingles(); drawFoot(); };
        sg.oninput = (e) => { const i = e.target.closest('[data-sp]'); if (!i) return; const x = S.single.get(+i.closest('.mo-ln').dataset.vid); if (!x) return; const v = i.value.replace(/[^\d.]/g, ''); if (v !== i.value) i.value = v; x.price = paise(num(v)); drawFoot(); const sm = i.closest('.mo-ln').querySelector('small'); if (sm) sm.textContent = (x.label ? x.label + ' · ' : '') + 'shop price ' + rs(x.mrp) + ' · line ' + rs(x.price * x.qty); }; }
      if (o.outside) {
        const g = (id) => bd.querySelector('#' + id), bind = (id, k, f) => { const el = g(id); if (el) el.oninput = () => { S.cust[k] = f ? f(el.value) : el.value; drawFoot(); }; };
        bind('cuPh', 'phone', (v) => v.replace(/[^\d+ ]/g, '')); bind('cuNm', 'name'); bind('cuAd', 'addr1'); bind('cuLm', 'landmark'); bind('cuCi', 'city'); bind('cuSt', 'state');
        const pn = g('cuPin'); if (pn) pn.oninput = async () => { const v = pn.value.replace(/\D+/g, '').slice(0, 6); if (v !== pn.value) pn.value = v; S.cust.pin = v; if (v.length === 6) { try { const r = await API.get('wmo.pin', { pin: v }); if (r && (r.city || r.state) && S.cust.pin === v) { S.cust.city = r.city || S.cust.city; S.cust.state = r.state || S.cust.state; if (g('cuCi')) g('cuCi').value = S.cust.city; if (g('cuSt')) g('cuSt').value = S.cust.state; } } catch (e) { } } };
        const ot = g('moOt'); if (ot) ot.onclick = (e) => { const b = e.target.closest('[data-o]'); if (!b) return; S.otype = b.dataset.o; ot.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b)); };
      }
    }
    const refresh = () => { drawFoot(); };
    const drawComboHint = () => { /* the line header already shows x/y; the search buttons re-check on the next tap */ };
    function search(cat, inp, res) {
      let deb = 0;
      const show = async (q) => {
        const my = ++S.seq[cat], ck = cat + '|' + q; let d = S.cache[ck];
        if (!d) { res.innerHTML = '<div class="mo-empty">Loading…</div>'; try { d = await API.get('wch.search', { q, cat, limit: 12 }); S.cache[ck] = d; } catch (e) { if (my === S.seq[cat]) res.innerHTML = '<div class="mo-empty">' + esc(errMsg(e)) + '</div>'; return; } }
        if (my !== S.seq[cat]) return; const items = d.items || [];
        if (!items.length) { res.innerHTML = '<div class="mo-empty">' + (q ? 'Nothing matches “' + esc(q) + '”.' : 'No ' + cat + 's in the catalogue yet. Ask the admin to refresh it (Sales → WhatsApp → Chain cart).') + '</div>'; return; }
        res.innerHTML = items.map((it, i) => '<div class="mo-it" data-i="' + i + '">' + (it.photo ? '<img loading="lazy" alt="" src="' + esc(it.photo) + '">' : '<img alt="">') + '<div class="mid"><div class="nm">' + esc(it.short || it.title) + '</div><div class="mo-sz">'
          + (it.sizes || []).map((s, j) => { const dis = !s.in_stock || (cat === 'perfume' && S.combo && !S.combo.some((c) => c.ml === s.ml)); return '<button type="button" data-j="' + j + '"' + (dis ? ' disabled' : '') + '>' + esc(s.label) + ' · ' + rs(paise(s.price)) + '</button>'; }).join('') + '</div></div></div>').join('');
        res.onclick = (e) => {
          const b = e.target.closest('button[data-j]'); if (!b || b.disabled) return; const it = items[+b.closest('.mo-it').dataset.i], s = it && it.sizes[+b.dataset.j]; if (!s) return;
          if (cat === 'other') { const ex2 = S.single.get(s.vid); if (ex2) { if (ex2.qty < 50) ex2.qty++; } else S.single.set(s.vid, { vid: s.vid, title: it.short || it.title, label: /^default title$/i.test(s.label || '') ? '' : s.label, mrp: paise(s.price), price: paise(s.price), qty: 1 }); fm(''); drawSingles(); drawFoot(); return; }
          const m = cat === 'chain' ? S.chain : S.perf, ex = m.get(s.vid), max = cat === 'chain' ? 5 : 20;
          if (cat === 'perfume' && S.combo) { const c = S.combo.find((q) => q.ml === s.ml); if (c && filled(s.ml) >= c.n) { fm('The combo has only ' + c.n + ' × ' + c.ml + ' ml. Remove one or choose “Any mix”.', 'bad'); drawFoot(); return; } }
          if (ex) { if (ex.qty < max) ex.qty++; } else m.set(s.vid, { vid: s.vid, title: it.short || it.title, label: s.label, price: paise(s.price), ml: s.ml, qty: 1 });
          fm(''); drawLines(); drawFoot();
        };
      };
      inp.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => show(inp.value.trim()), 220); }); inp.addEventListener('focus', () => { if (!res.innerHTML) show(inp.value.trim()); });
    }

    // ---- create + send
    async function make() {
      const k = calc(); if (!k.ok || S.busy) return; S.busy = true; drawFoot(); fm('');
      try {
        if (!S.order) {
          if (o.outside) { const st = await API.post('wmo.start', { phone: S.cust.phone, name: S.cust.name }); o.phone = st.phone; o.name = S.cust.name || st.name || ''; }
          const r = await API.post('wmo.create', { otype: o.outside ? S.otype : '', cust: o.outside ? S.cust : null, singles: Array.from(S.single.values()).map((x) => ({ vid: x.vid, qty: x.qty, price: x.price / 100 })), phone: o.phone, pay: S.pay, total: k.T / 100, paid: k.pd / 100, utr: S.utr, ml: mlOf(), lines: Array.from(S.perf.values()).map((x) => ({ vid: x.vid, qty: x.qty })), chains: Array.from(S.chain.values()).map((x) => ({ vid: x.vid, qty: x.qty })), shot: S.shot ? S.shot.b64 : '', shot_mime: S.shot ? S.shot.mime : '', shot2: S.shot2 ? S.shot2.b64 : '', shot2_mime: S.shot2 ? S.shot2.mime : '', shot_read: S.read && !S.read.error ? S.read : null });
          S.order = r.order;
        }
        if (addrOk()) { const r2 = await API.post('wmo.confirm', { id: S.order.id, cust: S.cust }); S.busy = false; S.order.order_no = r2.order_no || S.order.order_no; doneFinal(); if (o.onDone) { try { o.onDone({ stage: 'confirmed', order: S.order }); } catch (e) { } } return; }
        let sent = false, sendErr = '';
        try { await API.post('wmo.send', { id: S.order.id }); sent = true; } catch (e) { sendErr = errMsg(e); }
        S.busy = false; done(sent, sendErr);
        if (o.onDone) { try { o.onDone({ stage: sent ? 'sent' : 'built', order: S.order }); } catch (e) { } }
      } catch (e) { S.busy = false; drawFoot(); const d = (e && e.data) || {}; fm(esc(errMsg(e)), 'bad'); }
    }
    function doneFinal() {
      const x = S.order;
      bd.innerHTML = '<div style="text-align:center;font-size:36px">✅</div><h3 style="text-align:center;margin:4px 0 10px">' + esc(x.order_no) + ' · ' + rs(paise(x.total)) + '</h3><div class="mo-note ok">The order is <b>final</b>: it is in Orders, goes to Allocation under your name, and the parcel is in Dispatch. The customer gets the confirmation on WhatsApp.</div>'
        + x.lines.map((l) => '<div class="mo-ln"><div class="mid"><div class="nm">' + esc(l.short) + '</div><small>' + (l.chain ? 'Chain · full price' : esc(l.label)) + ' × ' + l.qty + '</small></div><div class="mo-amt">' + rs(paise(l.total)) + '</div></div>').join('')
        + '<div class="mo-sum" style="margin-top:10px"><div class="r"><span>Total</span><b>' + rs(paise(x.total)) + '</b></div>' + (x.paid ? '<div class="r"><span>Paid</span><b>' + rs(paise(x.paid)) + '</b></div>' : '') + (x.cod ? '<div class="r"><span>Pay on delivery</span><b>' + rs(paise(x.cod)) + '</b></div>' : '') + '</div>';
      ft.innerHTML = '<button type="button" class="mo-go alt" id="moDn">Done</button>'; ft.querySelector('#moDn').onclick = close;
    }
    function done(sent, sendErr) {
      const x = S.order;
      bd.innerHTML = '<div style="text-align:center;font-size:36px">' + (sent ? '✅' : '⚠️') + '</div><h3 style="text-align:center;margin:4px 0 10px">' + esc(x.order_no) + ' · ' + rs(paise(x.total)) + '</h3>'
        + (sent ? '<div class="mo-note ok">The link is in the chat. When the customer adds the address and presses <b>Done</b>, the order becomes final: it appears in Orders and Dispatch and a confirmation goes to the customer.</div>'
          : '<div class="mo-note bad"><b>The order is saved but the link was not sent:</b> ' + esc(sendErr) + '<br>Copy the link and paste it into the chat yourself.</div>')
        + x.lines.map((l) => '<div class="mo-ln"><div class="mid"><div class="nm">' + esc(l.short) + '</div><small>' + (l.chain ? 'Chain · full price' : esc(l.label)) + ' × ' + l.qty + '</small></div><div class="mo-amt">' + rs(paise(l.total)) + '</div></div>').join('')
        + '<div class="mo-sum" style="margin-top:10px"><div class="r"><span>Total</span><b>' + rs(paise(x.total)) + '</b></div>' + (x.paid ? '<div class="r"><span>Paid</span><b>' + rs(paise(x.paid)) + '</b></div>' : '') + (x.cod ? '<div class="r"><span>Pay on delivery</span><b>' + rs(paise(x.cod)) + '</b></div>' : '') + '</div>';
      ft.innerHTML = '<button type="button" class="mo-go" id="moCp">Copy the customer link</button>' + (sent ? '' : '<button type="button" class="mo-go alt" id="moRs">Try sending again</button>') + '<button type="button" class="mo-go alt" id="moDn">Done</button>';
      ft.querySelector('#moCp').onclick = async (e) => { await copy(x.link); e.target.textContent = 'Copied ✓'; }; ft.querySelector('#moDn').onclick = close;
      const rb = ft.querySelector('#moRs'); if (rb) rb.onclick = async () => { rb.disabled = true; rb.textContent = 'Sending…'; try { await API.post('wmo.send', { id: x.id }); done(true, ''); } catch (e) { rb.disabled = false; rb.textContent = 'Try sending again'; alert(errMsg(e)); } };
    }

    drawAll();
    return { close };
  }

  window.WaManual = { open, orders, close, combos };
})();
