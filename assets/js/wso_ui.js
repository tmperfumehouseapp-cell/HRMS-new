/* wso_ui.js — 🛍️ "Order": a CHECKOUT link from the WhatsApp chat (wso.php). A compact bottom sheet:
 *   search a perfume → tap a size (adds 1) → qty −/+ → optional discount → the total → "Send checkout link".
 *   The website keeps a ready cart; the customer opens it, adds the address, chooses prepaid or COD, sees the gift perfume offer and places the order.
 *   No order exists until then. The link message is sent into this chat at once.
 *   WaWebOrder.open({ phone, name, api, onDone })        Needs the global `api` (api.js).
 */
(function () {
  'use strict';
  if (window.WaWebOrder) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (n) => '₹' + (Math.round(Number(n || 0) * 100) / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const CSS = `
  .wso-ov{position:fixed;inset:0;z-index:10060;background:#0008;display:flex;align-items:flex-end;justify-content:center;font-family:inherit}
  .wso-sh{background:#fff;color:#111b21;width:100%;max-width:560px;height:92vh;height:92dvh;border-radius:18px 18px 0 0;display:flex;flex-direction:column;box-shadow:0 -8px 30px #0004;overflow:hidden;font-size:14px}
  @media (min-width:761px){.wso-ov{align-items:center}.wso-sh{border-radius:18px;height:min(88vh,820px)}}
  .wso-sh *{box-sizing:border-box}.wso-sh button,.wso-sh input{font:inherit}.wso-sh [hidden]{display:none!important}
  .wso-hd{display:flex;align-items:center;gap:8px;padding:12px 14px 8px;border-bottom:1px solid #eef0f3}.wso-hd b{font-size:17px}.wso-hd small{display:block;font-size:12.5px;color:#667781}.wso-hd .sp{flex:1;min-width:0}
  .wso-x{flex:none;width:40px;height:40px;border:0;border-radius:50%;background:#f0f2f5;color:#54656f;font-size:18px;cursor:pointer}
  .wso-bd{flex:1;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:10px 14px 16px}
  .wso-h{margin:14px 0 6px;font-size:11.5px;font-weight:800;color:#667781;text-transform:uppercase;letter-spacing:.4px}.wso-h:first-child{margin-top:2px}
  .wso-sb input{width:100%;height:44px;border:1px solid #d1d7db;border-radius:22px;padding:0 16px;font-size:16px;background:#f0f2f5;outline:none}.wso-sb input:focus{border-color:#00a884;background:#fff}
  .wso-res{margin-top:6px;max-height:260px;overflow-y:auto;border:1px solid #eef0f3;border-radius:12px}.wso-res:empty{display:none}
  .wso-it{display:flex;gap:10px;align-items:center;padding:8px 10px;border-bottom:1px solid #f0f2f5}.wso-it:last-child{border-bottom:0}
  .wso-it img,.wso-it .ph{width:44px;height:55px;border-radius:8px;object-fit:cover;background:#e9edef;flex:none}.wso-it .t{flex:1;min-width:0}.wso-it .t b{display:block;font-size:13.5px;line-height:1.25}
  .wso-sz{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}.wso-sz button{min-height:32px;padding:0 10px;border:1.5px solid #00a884;border-radius:16px;background:#fff;color:#005c4b;font-weight:800;font-size:12.5px;cursor:pointer}
  .wso-sz button:disabled{border-color:#d1d7db;color:#aab3b9;cursor:not-allowed}
  .wso-ln{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed #e3e7ea}.wso-ln .t{flex:1;min-width:0}.wso-ln .t b{display:block;font-size:13.5px}.wso-ln .t small{color:#667781}
  .wso-q{display:flex;align-items:center;border:1.5px solid #d1d7db;border-radius:18px;overflow:hidden;flex:none}.wso-q button{width:32px;height:32px;border:0;background:#f0f2f5;font-weight:800;font-size:16px;cursor:pointer}.wso-q span{min-width:26px;text-align:center;font-weight:800}
  .wso-pr{font-weight:800;white-space:nowrap;flex:none;min-width:58px;text-align:right}
  .wso-seg{display:grid;grid-template-columns:1fr 1fr;gap:6px}.wso-seg button{min-height:46px;border:1.5px solid #d1d7db;border-radius:12px;background:#fff;font-weight:800;cursor:pointer;line-height:1.2;padding:4px}
  .wso-seg button small{display:block;font-weight:600;font-size:11.5px;color:#667781}.wso-seg button.on{border-color:#00a884;background:#e7f8f1;color:#005c4b}
  .wso-dc{display:flex;gap:6px;align-items:center}.wso-dc input{flex:1;min-width:0;height:42px;border:1.5px solid #d1d7db;border-radius:12px;padding:0 12px;font-size:16px}
  .wso-dc button{height:42px;min-width:46px;border:1.5px solid #d1d7db;border-radius:12px;background:#fff;font-weight:800;cursor:pointer}.wso-dc button.on{border-color:#00a884;background:#e7f8f1;color:#005c4b}
  .wso-tot{margin-top:12px;border-radius:14px;background:#f7f8fa;padding:10px 12px}.wso-tot .r{display:flex;justify-content:space-between;padding:2px 0}.wso-tot .r.g{color:#12794a;font-weight:700}
  .wso-tot .r.t{font-weight:800;font-size:16px;border-top:1px dashed #d1d7db;margin-top:4px;padding-top:6px}.wso-tot .r.p{color:#005c4b;font-weight:800}
  .wso-note{margin:8px 0;padding:9px 12px;border-radius:10px;font-size:13px;line-height:1.4;background:#fff6d6;color:#7a5200}.wso-note.bad{background:#fde8e8;color:#9a2620}.wso-note.ok{background:#e3f6ec;color:#12794a}
  .wso-ft{padding:10px 14px calc(10px + env(safe-area-inset-bottom));border-top:1px solid #eef0f3;display:flex;gap:8px}
  .wso-go{flex:1;min-height:48px;border:0;border-radius:24px;background:#00a884;color:#fff;font-weight:800;font-size:16px;cursor:pointer}.wso-go:disabled{background:#b8c2c9;cursor:not-allowed}
  .wso-sec{min-height:48px;padding:0 16px;border:1.5px solid #d1d7db;border-radius:24px;background:#fff;font-weight:800;cursor:pointer}
  .wso-empty{color:#667781;font-size:13px;padding:8px 0}
  .wso-done{text-align:center;padding:18px 6px}.wso-done .ic{font-size:42px}.wso-done b{display:block;font-size:18px;margin:6px 0}.wso-done a{word-break:break-all;color:#0b5cad}
  `;
  function css() { if (document.getElementById('wso-css')) return; const s = document.createElement('style'); s.id = 'wso-css'; s.textContent = CSS; document.head.appendChild(s); }

  function open(o) {
    css();
    const api = o.api || window.api, phone = String(o.phone || ''), name = String(o.name || '');
    const S = { lines: [], dType: 'rs', dVal: '', prev: null, perr: '', busy: false, nonce: Math.random().toString(36).slice(2) + Date.now().toString(36), done: null, res: [], q: '' };
    const ov = document.createElement('div'); ov.className = 'wso-ov';
    ov.innerHTML = `<div class="wso-sh" role="dialog" aria-modal="true" aria-label="Website order"><div class="wso-hd"><div class="sp"><b>🛍️ Order · checkout link</b><small>${esc(name || 'Customer')} · ${esc(phone.slice(-10))}</small></div><button type="button" class="wso-x" aria-label="Close">✕</button></div>
      <div class="wso-bd" id="wsoBd"></div><div class="wso-ft" id="wsoFt"></div></div>`;
    document.body.appendChild(ov);
    const $ = (s) => ov.querySelector(s), bd = $('#wsoBd'), ft = $('#wsoFt');
    const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', onKey);
    $('.wso-x').onclick = close; ov.addEventListener('click', (e) => { if (e.target === ov) close(); });

    let pT = 0, sT = 0;
    const body = () => ({ phone, name, lines: S.lines.map((l) => ({ variant_id: l.v, qty: l.q })),
      discount: Number(S.dVal) > 0 ? (S.dType === 'rs' ? { amount: Number(S.dVal) } : { percent: Number(S.dVal) }) : {} });
    function preview() {
      clearTimeout(pT); S.prev = null; S.perr = ''; drawTot(); if (!S.lines.length) return;
      pT = setTimeout(() => { const mine = JSON.stringify(body()); api.post('wso.preview', body()).then((r) => { if (JSON.stringify(body()) !== mine) return; S.prev = r.preview; S.perr = ''; drawTot(); }, (e) => { S.prev = null; S.perr = (e && e.message) || 'Could not work out the total.'; drawTot(); }); }, 350);
    }
    function search(q) {
      S.q = q; clearTimeout(sT);
      if (q.trim().length < 2) { S.res = []; drawRes(); return; }
      sT = setTimeout(() => { api.get('wso.products', { phone, q: q.trim() }).then((r) => { if (S.q !== q) return; S.res = r.items || []; S.resErr = ''; drawRes(); }, (e) => { S.res = []; S.resErr = (e && e.message) || 'Search failed.'; drawRes(); }); }, 300);
    }
    function drawRes() {
      const el = $('#wsoRes'); if (!el) return;
      if (S.resErr) { el.innerHTML = `<div class="wso-note bad" style="margin:6px">${esc(S.resErr)}</div>`; return; }
      el.innerHTML = S.q.trim().length >= 2 && !S.res.length ? '<div class="wso-empty" style="padding:10px">No perfume found.</div>' : S.res.map((p) => `<div class="wso-it">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : '<span class="ph"></span>'}<div class="t"><b>${esc(p.title)}</b><div class="wso-sz">${p.variants.map((v) => `<button type="button" data-add="${v.id}" data-p="${p.id}"${v.out ? ' disabled title="Out of stock"' : ''}>${esc(v.title)} · ${rs(v.price)}</button>`).join('')}</div></div></div>`).join('');
    }
    function add(pid, vid) {
      const p = S.res.find((x) => x.id === pid), v = p && p.variants.find((x) => x.id === vid); if (!v) return;
      const l = S.lines.find((x) => x.v === vid); if (l) l.q = Math.min(99, l.q + 1); else S.lines.push({ v: vid, q: 1, n: p.title, s: v.title, pr: v.price });
      draw(); preview();
    }
    function totals() {
      const p = S.prev;
      if (!S.lines.length) return '';
      if (S.perr) return `<div class="wso-note bad">${esc(S.perr)}</div>`;
      if (!p) return '<div class="wso-tot"><div class="r"><span>Working out the total…</span></div></div>';
      return `<div class="wso-tot"><div class="r"><span>Subtotal</span><span>${rs(p.subtotal)}</span></div>${Number(p.discount) > 0 ? `<div class="r g"><span>Discount</span><span>− ${rs(p.discount)}</span></div>` : ''}<div class="r t"><span>Total</span><span>${rs(p.total)}</span></div></div>${(p.problems || []).length ? `<div class="wso-note bad">${p.problems.map(esc).join('<br>')}</div>` : ''}<div class="wso-note">🔗 The customer gets a <b>checkout link</b>: adds the address, chooses <b>prepaid or COD</b>, sees the 🎁 gift offer and places the order. No order is made before that. Works 72 hours, one order.</div>`;
    }
    function draw() {
      if (S.done) {
        const d = S.done;
        bd.innerHTML = `<div class="wso-done"><div class="ic">✅</div><b>Checkout link ${d.again ? 'already made' : 'made'}</b><div>Total ${rs(d.total)}${d.discount > 0 ? ' (after ' + rs(d.discount) + ' off)' : ''} · works 72 hours</div>
          ${d.sent ? '<div class="wso-note ok">💬 The checkout link was sent in this chat. The order is made when the customer places it.</div>' : `<div class="wso-note bad">The link was not sent in the chat${d.send_error ? ': ' + esc(d.send_error) : ''}. Copy it and send it yourself.</div>`}
          <p><a href="${esc(d.link)}" target="_blank" rel="noopener">${esc(d.link)}</a></p></div>`;
        ft.innerHTML = `<button type="button" class="wso-sec" id="wsoCopy">Copy message</button><button type="button" class="wso-go" id="wsoClose">Done</button>`;
        $('#wsoClose').onclick = close;
        $('#wsoCopy').onclick = function () { const b = this; (navigator.clipboard ? navigator.clipboard.writeText(d.text || d.link) : Promise.reject()).then(() => { b.textContent = 'Copied ✓'; }, () => { prompt('Copy this message:', d.text || d.link); }); };
        return;
      }
      const lines = S.lines.length ? S.lines.map((l, i) => `<div class="wso-ln"><div class="t"><b>${esc(l.n)}</b><small>${esc(l.s)} · ${rs(l.pr)} each</small></div><div class="wso-q"><button type="button" data-dq="${i}" aria-label="One less">−</button><span>${l.q}</span><button type="button" data-iq="${i}" aria-label="One more">+</button></div><div class="wso-pr">${rs(l.pr * l.q)}</div></div>`).join('') : '<div class="wso-empty">No perfume yet. Search above and tap a size.</div>';
      const keepQ = $('#wsoQ') ? $('#wsoQ').value : S.q;
      bd.innerHTML = `<div class="wso-h">Perfumes</div><div class="wso-sb"><input id="wsoQ" type="search" placeholder="Search a perfume (2+ letters)" autocomplete="off" value="${esc(keepQ)}"></div><div class="wso-res" id="wsoRes"></div>
        <div class="wso-h">In the order</div>${lines}
        <div class="wso-h">Discount (optional)</div><div class="wso-dc"><input id="wsoD" inputmode="decimal" placeholder="${S.dType === 'rs' ? 'Rupees off' : 'Percent off'}" value="${esc(S.dVal)}"><button type="button" data-dt="rs" class="${S.dType === 'rs' ? 'on' : ''}">₹</button><button type="button" data-dt="pc" class="${S.dType === 'pc' ? 'on' : ''}">%</button></div>
        <div id="wsoTot"></div>`;
      drawRes(); drawTot();
      const qi = $('#wsoQ'); qi.oninput = () => search(qi.value);
      const di = $('#wsoD'); di.oninput = () => { S.dVal = di.value.replace(/[^\d.]/g, ''); preview(); };
    }
    function drawTot() {
      if (S.done) return; const t = $('#wsoTot'); if (t) t.innerHTML = totals();
      const can = S.lines.length && S.prev && !(S.prev.problems || []).length && !S.busy;
      ft.innerHTML = `<button type="button" class="wso-go" id="wsoGo"${can ? '' : ' disabled'}>${S.busy ? 'Making the link…' : '🔗 Send checkout link' + (S.prev ? ' · ' + rs(S.prev.total) : '')}</button>`;
      $('#wsoGo').onclick = create;
    }
    function create() {
      if (S.busy) return; S.busy = true; drawTot();
      api.post('wso.create', Object.assign(body(), { send: 1, nonce: S.nonce })).then((r) => { S.busy = false; S.done = r; S.nonce = Math.random().toString(36).slice(2) + Date.now().toString(36); draw(); try { o.onDone && o.onDone(r); } catch (e) { } },
        (e) => { S.busy = false; S.perr = (e && e.message) || 'The link could not be made.'; drawTot(); });
    }
    bd.addEventListener('click', (e) => {
      const a = e.target.closest('[data-add]'); if (a) { add(+a.dataset.p, +a.dataset.add); return; }
      const dq = e.target.closest('[data-dq]'); if (dq) { const l = S.lines[+dq.dataset.dq]; if (l) { l.q -= 1; if (l.q < 1) S.lines.splice(+dq.dataset.dq, 1); } draw(); preview(); return; }
      const iq = e.target.closest('[data-iq]'); if (iq) { const l = S.lines[+iq.dataset.iq]; if (l) l.q = Math.min(99, l.q + 1); draw(); preview(); return; }
      const dt = e.target.closest('[data-dt]'); if (dt) { S.dType = dt.dataset.dt; draw(); preview(); }
    });
    draw(); setTimeout(() => { const q = $('#wsoQ'); if (q) q.focus(); }, 50);
  }
  window.WaWebOrder = { open };
})();
