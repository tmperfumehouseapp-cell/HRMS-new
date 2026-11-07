/* order_hub.js — THE one big popup of Dispatch: ORDER card + CUSTOMER card + the WhatsApp chat, and the Customers list.
 *
 *   OrderHub.open({ id })            a parcel (Parcels table row)
 *   OrderHub.open({ case })          a Needs attention case
 *   OrderHub.open({ phone })         a customer (any number format) -> their latest order
 *   OrderHub.open({ order, store })  an order by number         OrderHub.open({ bill })  a store bill
 *   OrderHub.open({ ..., tab:'chat' })  opens on the WhatsApp chat
 *   OrderHub.customers(el)           the Customers list (customers.html)
 *
 * Reads dp.dsp_hub / dp.dsp_cust_list (empmanagment/order_hub.php). The chat inside is wa_one.html, the same chat screen as everywhere.
 * Needs api.js. One person = the last 10 digits of the phone, so online, store and old-POS orders sit on the same customer card. */
window.OrderHub = (function () {
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fdt = (s) => { if (!s) return ''; const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s); return m ? (+m[3]) + ' ' + MON[+m[2] - 1] + ' ' + m[4] + ':' + m[5] : s; };
  const fd = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? (+m[3]) + ' ' + MON[+m[2] - 1] + ' ' + String(m[1]).slice(2) : (s || ''); };
  const rs = (n) => n == null || n === '' ? '—' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const tel = (p) => { p = String(p || '').replace(/\D/g, '').slice(-10); return p.length === 10 ? p.slice(0, 5) + ' ' + p.slice(5) : p; };
  const titleCase = (t) => String(t || '').toLowerCase().replace(/(^|[\s\-\/])([a-z])/g, (m, a, b) => a + b.toUpperCase());
  const dur = (mins) => mins < 1 ? 'just now' : mins < 60 ? mins + ' min' : mins < 1440 ? Math.floor(mins / 60) + ' h ' + (mins % 60) + ' min' : Math.floor(mins / 1440) + ' d ' + Math.floor((mins % 1440) / 60) + ' h';
  const kv = (l, v, cls) => '<div><small>' + l + '</small><b' + (cls ? ' class="' + cls + '"' : '') + '>' + v + '</b></div>';
  const errMsg = (e) => (e && (e.message || e.error)) || 'Something went wrong.';
  const toast = (t) => { try { if (window.BD && BD.toast) return BD.toast(t); } catch (e) { } const d = document.createElement('div'); d.className = 'oh-toast'; d.textContent = t; document.body.appendChild(d); setTimeout(() => d.remove(), 2400); };
  const copy = async (t) => { try { await navigator.clipboard.writeText(t); toast('Copied'); } catch (e) { toast('Select it and copy by hand.'); } };

  const CSS = `
.oh-ov{position:fixed;inset:0;z-index:9500;background:rgba(17,24,39,.5);display:flex;justify-content:center;align-items:center;padding:2vh 1.5vw;animation:ohf .15s ease-out;font-family:inherit}
@keyframes ohf{from{opacity:0}to{opacity:1}} @keyframes ohu{from{transform:translateY(30px);opacity:.5}to{transform:none;opacity:1}}
.oh-pn{width:min(1240px,100%);height:min(94vh,900px);background:#f6f7f9;display:flex;flex-direction:column;border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.3);animation:ohu .2s ease-out;color:#14161a;overflow:hidden}
.oh-hd{background:#fff;padding:14px 18px 10px;border-bottom:1px solid #eceef2}
.oh-r1{display:flex;align-items:center;gap:10px}.oh-r1 h2{margin:0;font-size:18px;line-height:1.25;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.oh-r1 h2 small{font-weight:600;color:#7a8499;font-size:13px;margin-left:6px}
.oh-ib{border:1px solid #e3e6ec;background:#fff;border-radius:12px;min-width:40px;height:40px;font:700 16px inherit;cursor:pointer;color:#14161a;display:inline-flex;align-items:center;justify-content:center;padding:0 12px;gap:6px;text-decoration:none}.oh-ib:hover{background:#f3f4f7}.oh-ib.pri{background:#14161a;color:#fff;border-color:#14161a;font-size:14px}.oh-ib.sm{height:34px;min-width:34px;font-size:13px;padding:0 10px}
.oh-bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}
.oh-bn{display:flex;gap:8px;align-items:center;border-radius:12px;padding:8px 12px;font-size:13px;font-weight:700;border:1px solid}.oh-bn.bad{background:#fdecea;border-color:#f5c2bd;color:#b42318}.oh-bn.amber{background:#fff4e0;border-color:#f6d9a7;color:#a15c07}.oh-bn.ok{background:#e8f7ee;border-color:#bfe6cd;color:#157a3c}
.oh-bn b{font-weight:800}
.oh-tabs{display:flex;gap:6px;padding:10px 18px 0;background:#fff}.oh-tabs button{border:0;background:transparent;font:700 14px inherit;color:#6b7280;padding:9px 14px;border-radius:12px 12px 0 0;cursor:pointer;border-bottom:3px solid transparent}.oh-tabs button.on{color:#14161a;border-bottom-color:#ea580c}.oh-tabs .n,.oh-tabs .nrw{display:none}@media (max-width:900px){.oh-tabs .w{display:none}.oh-tabs .n{display:inline}.oh-tabs .nrw{display:inline-block}}
.oh-bd{flex:1;min-height:0;overflow:auto;padding:14px 18px;-webkit-overflow-scrolling:touch}
.oh-cols{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:16px;align-items:start}
.oh-card{background:#fff;border:1px solid #eceef2;border-radius:18px;padding:14px 15px}
.oh-ct{font-size:11.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#7a8499;margin:0 0 10px;display:flex;align-items:center;gap:8px}.oh-ct i{font-style:normal;margin-left:auto;text-transform:none;letter-spacing:0;font-weight:700;color:#9aa3b5}
.oh-sec{border:1px solid #eef0f3;border-radius:14px;padding:10px 12px;margin-bottom:10px}.oh-sec .t{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#7a8499;margin-bottom:7px;display:flex;gap:8px;align-items:center}.oh-sec .t span{margin-left:auto;text-transform:none;letter-spacing:0;font-weight:700}
.oh-g{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px 12px}.oh-g div{min-width:0}.oh-g small{display:block;font-size:11px;color:#7a8499;line-height:1.3}.oh-g b{display:block;font-size:13.5px;line-height:1.35;overflow-wrap:anywhere}.oh-g .ok{color:#16794a}.oh-g .bad{color:#b42318}.oh-g .am{color:#a15c07}
.oh-chip{display:inline-block;border-radius:99px;padding:2px 9px;font-size:11.5px;font-weight:800;background:#eef0f3;color:#4b5563;margin:0 5px 5px 0}.oh-chip.ok{background:#dcf5e8;color:#157a3c}.oh-chip.warn{background:#fff4e0;color:#a15c07}.oh-chip.bad{background:#fdecea;color:#b42318}.oh-chip.info{background:#e8f0fe;color:#1d4ed8}.oh-chip.pu{background:#efe7fd;color:#6d28d9}
.oh-st{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}.oh-st b{font-size:17px}.oh-eta{font-size:12.5px;font-weight:700;color:#4a505c}.oh-eta.bad{color:#b42318}.oh-eta.ok{color:#157a3c}
.oh-steps{display:flex;gap:0;margin:2px 0 12px}.oh-s{flex:1;text-align:center;font-size:10.5px;font-weight:700;color:#9aa3b5;position:relative;padding-top:16px}.oh-s i{position:absolute;top:2px;left:50%;width:11px;height:11px;margin-left:-6px;border-radius:50%;background:#dfe3ea;z-index:1}.oh-s:before{content:"";position:absolute;top:7px;left:-50%;width:100%;height:3px;background:#dfe3ea}.oh-s:first-child:before{display:none}.oh-s.done{color:#14161a}.oh-s.done i,.oh-s.done:before{background:#16945a}.oh-s.now i{box-shadow:0 0 0 4px rgba(22,148,90,.2)}.oh-s.bad i,.oh-s.bad:before{background:#c2410c}
.oh-li{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:3px 0}.oh-li em{color:#7a8499;font-style:normal}
.oh-sub{font-size:12.5px;color:#6b7280;line-height:1.5}
.oh-tl{position:relative;margin:0;padding:0 0 0 18px;list-style:none}.oh-tl:before{content:"";position:absolute;left:5px;top:6px;bottom:6px;width:2px;background:#e5e8ee}
.oh-tl li{position:relative;padding:0 0 11px 8px;font-size:13px;line-height:1.35}.oh-tl li:before{content:"";position:absolute;left:-17px;top:4px;width:10px;height:10px;border-radius:50%;background:#aab2c3;border:2px solid #fff}
.oh-tl li.order:before{background:#2563eb}.oh-tl li.pay:before,.oh-tl li.com:before{background:#16945a}.oh-tl li.ship:before{background:#14161a}.oh-tl li.scan:before{background:#aab2c3}.oh-tl li.cx:before,.oh-tl li.case:before{background:#d9423b}.oh-tl li.ok b{color:#157a3c}.oh-tl li.bad b{color:#b42318}
.oh-tl b{font-weight:800}.oh-tl span{display:block;color:#7a8499;font-size:12px}
.oh-who{display:flex;gap:12px;align-items:center;margin-bottom:12px}.oh-av{width:50px;height:50px;border-radius:50%;background:#14161a;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex:none}
.oh-who h3{margin:0;font-size:17px;line-height:1.2}.oh-who .ph{font-size:14px;font-weight:700;margin-top:2px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}.oh-who a{color:#14161a}
.oh-big{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:2px 0 12px}.oh-big div{background:#f6f7f9;border-radius:12px;padding:9px 10px}.oh-big small{display:block;font-size:11px;color:#7a8499}.oh-big b{font-size:17px;line-height:1.2;display:block}
.oh-ol{display:flex;gap:10px;align-items:center;border:1px solid #eef0f3;border-radius:12px;padding:8px 10px;margin-bottom:6px;font-size:13px;background:#fff;width:100%;text-align:left;font-family:inherit;color:inherit}.oh-ol.go{cursor:pointer}.oh-ol.go:hover{background:#f6f7f9}.oh-ol.cur{border-color:#ea580c;background:#fff7f0}.oh-ol .n{font-weight:800;min-width:78px}.oh-ol .d{color:#7a8499;font-size:12px;flex:1;min-width:0}.oh-ol .a{font-weight:800}
.oh-ol .tag{font-size:10.5px;font-weight:800;border-radius:6px;padding:1px 6px;background:#eef0f3;color:#4b5563;text-transform:uppercase}.oh-ol .tag.store{background:#e8f0fe;color:#1d4ed8}.oh-ol .tag.pos{background:#efe7fd;color:#6d28d9}.oh-ol .tag.can{background:#fdecea;color:#b42318}
.oh-chat{height:100%;display:flex;flex-direction:column}.oh-chat iframe{flex:1;border:0;width:100%;min-height:420px;background:#fff;border-radius:16px}
.oh-ft{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:10px 18px calc(10px + env(safe-area-inset-bottom));background:#fff;border-top:1px solid #eceef2}.oh-ft .sp{flex:1;font-size:12px;color:#7a8499}
.oh-empty{text-align:center;color:#7a8499;padding:26px 10px;font-size:13.5px}.oh-empty b{display:block;color:#14161a;font-size:15px;margin-bottom:4px}
.oh-sk{height:14px;border-radius:7px;background:linear-gradient(90deg,#eceef2,#f6f7f9,#eceef2);margin:10px 0;animation:ohs 1.1s infinite linear;background-size:200% 100%}@keyframes ohs{to{background-position:-200% 0}}
.oh-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);background:#14161a;color:#fff;padding:11px 18px;border-radius:14px;z-index:99999;font-size:14px}
.oh-img{max-width:100%;border-radius:12px;margin-top:6px}
.oh-ids{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin-top:10px}.oh-ids div{background:#f6f7f9;border-radius:10px;padding:7px 10px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:0 8px;align-items:center;min-width:0}.oh-ids small{grid-column:1/-1;font-size:11px;color:#7a8499}.oh-ids b{font-size:13px;overflow-wrap:anywhere}
.oh-tx{border:1px solid #eef0f3;border-radius:10px;padding:7px 10px;margin-top:6px;font-size:13px}.oh-tx em{color:#7a8499;font-style:normal;font-size:12px}.oh-tx small{display:block;color:#6b7280}.oh-tx small.bad{color:#b42318}.oh-rc{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px;margin-top:6px;word-break:break-all}.oh-rc em{color:#7a8499;font-style:normal}
@media (max-width:900px){.oh-cols{grid-template-columns:1fr}.oh-big{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:700px){.oh-bn{padding:6px 10px;font-size:12px}.oh-bar{gap:6px;margin-top:7px}.oh-ov{padding:0;align-items:stretch}.oh-pn{width:100%;height:100%;border-radius:0}.oh-g{grid-template-columns:repeat(2,minmax(0,1fr))}.oh-hd{padding:10px 12px 8px}.oh-bd{padding:10px 12px}.oh-tabs{padding:8px 12px 0}.oh-ft{padding:8px 12px calc(8px + env(safe-area-inset-bottom))}.oh-ft .sp{display:none}}
/* Customers strip + list */
.ohs-w{margin:0 0 14px}.ohs-w .ohs{margin:0 0 8px}.ohs{display:flex;gap:10px;flex-wrap:wrap;align-items:stretch;text-decoration:none;color:inherit}.ohs>div{background:#fff;border:1px solid #eceef2;border-radius:14px;padding:9px 14px;min-width:120px}.ohs .ohs-m{background:#14161a;color:#fff;border-color:#14161a}.ohs small{display:block;font-size:11.5px;opacity:.75}.ohs b{font-size:20px;line-height:1.2}.ohs-sy{flex-basis:100%;display:flex;gap:6px;flex-wrap:wrap;margin-top:-4px}.ohs-sy .oh-chip{margin:0;font-weight:700;font-size:12px}.ohs-go{align-self:center;margin-left:auto;font-weight:700;font-size:13px;color:#ea580c}
.ohc-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}.ohc-tiles div{background:#fff;border:1px solid #eceef2;border-radius:16px;padding:12px 14px}.ohc-tiles small{display:block;font-size:12px;color:#7a8499}.ohc-tiles b{font-size:22px}
.ohc-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center}.ohc-bar input,.ohc-bar select{border:1px solid #e3e6ec;border-radius:12px;padding:10px 12px;font:inherit;background:#fff;min-height:42px}.ohc-bar input{flex:1;min-width:200px}
.ohc-f{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.ohc-f button{border:1px solid #e3e6ec;background:#fff;border-radius:99px;padding:8px 14px;font:700 13px inherit;cursor:pointer}.ohc-f button.on{background:#14161a;color:#fff;border-color:#14161a}
.ohc-t{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid #eceef2;border-radius:16px;overflow:hidden;font-size:13.5px}.ohc-t th{text-align:left;font-size:11.5px;color:#7a8499;text-transform:uppercase;letter-spacing:.05em;padding:10px 12px;background:#fafbfc;border-bottom:1px solid #eceef2}.ohc-t td{padding:11px 12px;border-bottom:1px solid #f1f3f6;vertical-align:middle}.ohc-t tr.r{cursor:pointer}.ohc-t tr.r:hover td{background:#f9fafb}.ohc-t td.n{text-align:right;white-space:nowrap}.ohc-t th.n{text-align:right}
.ohc-nm b{display:block}.ohc-nm small{color:#7a8499}
.ohc-pg{display:flex;gap:8px;justify-content:center;align-items:center;margin:14px 0;font-size:13px;color:#6b7280}
@media (max-width:760px){.ohc-t thead{display:none}.ohc-t,.ohc-t tbody,.ohc-t tr,.ohc-t td{display:block;width:100%}.ohc-t tr{border-bottom:1px solid #eceef2;padding:8px 4px}.ohc-t td{border:0;padding:3px 10px}.ohc-t td.n{text-align:left}.ohc-t td.n:before{content:attr(data-l) ": ";color:#7a8499;font-size:12px}}
`;
  function css() { if (document.getElementById('ohCss')) return; const s = document.createElement('style'); s.id = 'ohCss'; s.textContent = CSS; document.head.appendChild(s); }

  /* ===================================================== the popup ===================================================== */
  const STEPS = ['Ordered', 'Tracking ID', 'Picked up', 'In transit', 'Out for delivery', 'Delivered'];
  function stage(p, events) {
    const last = events && events.length ? events[events.length - 1] : null, txt = ((p.sr_status || '') + ' ' + (last ? last.label + ' ' + last.activity : '')).toLowerCase();
    if (p.status === 'delivered') return 5; if (p.status === 'placed') return 0; if (p.status === 'tracked') return 1;
    if (/out for delivery/.test(txt)) return 4; if (/pick/.test(txt) && !/transit|hub|reach|arriv|destination/.test(txt)) return 2; return 3;
  }

  let CUR = null;   // the popup that is open: { ov, req, d, tab, stack }

  function open(req) {
    css(); close(true); req = req || {};
    const ov = document.createElement('div'); ov.className = 'oh-ov';
    ov.innerHTML = '<div class="oh-pn" role="dialog" aria-modal="true" aria-label="Order and customer"><div class="oh-hd"><div class="oh-r1"><h2>Loading…</h2><button type="button" class="oh-ib" data-x aria-label="Close">✕</button></div></div>'
      + '<div class="oh-tabs"><button type="button" class="on" data-tab="main"><span class="w">Order &amp; customer</span><span class="n">🧾 Order</span></button><button type="button" class="nrw" data-tab="cust">👤 Customer</button><button type="button" data-tab="chat">💬 WhatsApp chat</button></div><div class="oh-bd" data-bd><div class="oh-sk"></div><div class="oh-sk" style="width:70%"></div><div class="oh-sk" style="width:85%"></div></div><div class="oh-ft" data-ft></div></div>';
    document.body.appendChild(ov); document.documentElement.style.overflow = 'hidden';
    const S = { ov, req, d: null, tab: req.tab === 'chat' ? 'chat' : req.tab === 'cust' ? 'cust' : 'main', stack: [], items: null, chatPhone: '' };
    CUR = S;
    S.key = (e) => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', S.key);
    ov.addEventListener('click', (e) => onClick(e, S));
    load(S, req);
  }
  function close(silent) {
    if (!CUR) return; const S = CUR; CUR = null; document.removeEventListener('keydown', S.key); S.ov.remove(); document.documentElement.style.overflow = '';
    if (!silent && S.dirty && window.OrderHub && typeof OrderHub.onClose === 'function') { try { OrderHub.onClose(S.d); } catch (e) { } }
  }
  async function load(S, req, quiet) {
    S.req = req; S.items = null; S.pay = null;
    if (!quiet) { const b = S.ov.querySelector('[data-bd]'); if (b && !S.d) b.innerHTML = '<div class="oh-sk"></div><div class="oh-sk" style="width:70%"></div>'; }
    try {
      const q = {}; ['id', 'case', 'phone', 'order', 'store', 'sid', 'bill'].forEach((k) => { if (req[k]) q[k] = req[k]; });
      S.d = await api.get('dp.dsp_hub', q);
    } catch (e) { if (CUR !== S) return; S.ov.querySelector('[data-bd]').innerHTML = '<div class="oh-empty"><b>Cannot open this</b>' + esc(errMsg(e)) + '</div>'; S.ov.querySelector('.oh-r1 h2').textContent = 'Not found'; return; }
    if (CUR !== S) return; draw(S);
    if (S.d.order && !S.d.order.manual) loadItems(S);
    if (S.d.order && S.d.order.store && S.d.order.shopify_id && !S.d.order.manual) loadPay(S);
  }
  async function loadItems(S) {
    const o = S.d && S.d.order; if (!o || S.items) return; S.items = { line_items: null };
    try { const r = await api.get('shopify.order_detail', { store: o.store, id: o.shopify_id }); if (CUR !== S) return; S.items = r.order || {}; }
    catch (e) { if (CUR !== S) return; S.items = { err: errMsg(e) }; }
    const x = S.ov.querySelector('[data-items]'); if (x) x.innerHTML = itemsHtml(S);
  }

  async function loadPay(S) {
    const o = S.d && S.d.order; if (!o || S.pay) return; S.pay = { loading: true };
    try { const r = await api.get('dp.dsp_pay', { store: o.store, sid: o.shopify_id }); if (CUR !== S) return; S.pay = r; }
    catch (e) { if (CUR !== S) return; S.pay = { err: errMsg(e) }; }
    const x = S.ov.querySelector('[data-pay]'); if (x) x.innerHTML = payHtml(S); const g = S.ov.querySelector('[data-gw]'); if (g) g.textContent = gatewayOf(S) || '—';
  }
  const GW_RE = /gokwik|kwik|razorpay|cashfree|paytm|phonepe|payu|snapmint|simpl|cashe|zestmoney|upi|cod/i;
  function gatewayOf(S) {
    const o = S.d.order, pay = (o && o.pay) || {}, p = S.pay || {};
    const names = (p.gateways && p.gateways.length ? p.gateways.join(', ') : '') || pay.gateway || '';
    if (names) return names;
    const tg = (p.tags && p.tags.length ? p.tags : String((o && o.tags) || '').split(',').map((x) => x.trim())).filter((x) => /gokwik|kwik|razorpay|cashfree|paytm|phonepe|payu|snapmint|simpl|ppcod|upi/i.test(x));
    return tg.join(', ') || (o && o.manual ? 'UPI / QR' : '');
  }
  function payHtml(S) {
    const p = S.pay; if (!p) return ''; if (p.loading) return '<div class="oh-sub" style="margin-top:8px">Reading the payment from the gateway…</div>';
    if (p.err) return '<div class="oh-sub" style="margin-top:8px">Could not read the gateway details: ' + esc(p.err) + ' <button type="button" class="oh-ib sm" data-payretry>Try again</button></div>';
    let h = '';
    const ids = []; if (p.kwik) p.kwik.split(',').forEach((x) => { x = x.trim(); if (x) ids.push(['KWIK ID', x]); });
    (p.tx || []).forEach((t) => { if (t.payment_id) ids.push([(t.gateway || 'Payment') + ' payment ID', t.payment_id]); if (t.auth) ids.push(['Authorization', t.auth]); if (t.id) ids.push(['Shopify transaction', t.id]); });
    (p.attrs || []).forEach((a) => { if (/utr|txn|transaction|payment|rrn|ref|upi|kwik/i.test(a.k) && a.v) ids.push([a.k, a.v]); });
    if (p.reference) ids.push(['Reference', p.reference]);
    const seen = {}; const uniq = ids.filter((x) => { const k = x[1]; if (seen[k]) return false; seen[k] = 1; return true; });
    if (uniq.length) h += '<div class="oh-ids">' + uniq.map((x) => '<div><small>' + esc(x[0]) + '</small><b>' + esc(x[1]) + '</b><button type="button" class="oh-ib sm" data-copy="' + esc(x[1]) + '">Copy</button></div>').join('') + '</div>';
    if ((p.tx || []).length) h += '<div class="oh-sub" style="margin-top:8px"><b>Gateway transactions</b></div>' + p.tx.map((t) => '<div class="oh-tx"><div><b>' + esc([t.gateway, t.kind, t.status].filter(Boolean).join(' · ')) + '</b> <span>' + (t.amount ? rs(t.amount) : '') + '</span> <em>' + esc(fdt(t.at)) + '</em></div>'
      + (t.message ? '<small>' + esc(t.message) + '</small>' : '') + (t.error ? '<small class="bad">Error: ' + esc(t.error) + '</small>' : '') + (t.test ? '<small>TEST payment</small>' : '')
      + (Object.keys(t.receipt || {}).length ? '<details><summary>Everything the gateway sent</summary><div class="oh-rc">' + Object.keys(t.receipt).map((k) => '<span><em>' + esc(k) + '</em> ' + esc(t.receipt[k]) + '</span>').join('') + '</div></details>' : '') + '</div>').join('');
    else if (p.tx_ok) h += '<div class="oh-sub" style="margin-top:8px">The gateway has no transaction on this order (COD, or not paid yet).</div>';
    const other = (p.attrs || []).filter((a) => !/^_/.test(a.k)).slice(0, 12);
    if (other.length) h += '<details style="margin-top:8px"><summary class="oh-sub" style="cursor:pointer">Order notes from checkout (' + other.length + ')</summary><div class="oh-rc">' + other.map((a) => '<span><em>' + esc(a.k) + '</em> ' + esc(a.v) + '</span>').join('') + '</div></details>';
    return h;
  }
  function draw(S) {
    const d = S.d, p = d.parcel, c = d.customer, o = d.order, bill = d.bill;
    const title = bill ? 'Store bill ' + esc(bill.bill_no) : p ? esc(p.order) : 'Customer';
    const nm = (c && c.name) || (p && p.customer) || (bill && bill.name) || '';
    const hd = S.ov.querySelector('.oh-hd');
    hd.innerHTML = '<div class="oh-r1">' + (S.stack.length ? '<button type="button" class="oh-ib" data-back title="Back">←</button>' : '') + '<h2>' + title + '<small>' + esc(nm) + (d.phone ? ' · ' + esc(tel(d.phone)) : '') + '</small></h2>'
      + (d.phone ? '<a class="oh-ib sm" href="tel:+91' + esc(d.phone) + '" title="Call">📞 Call</a>' : '') + '<button type="button" class="oh-ib" data-x aria-label="Close">✕</button></div>' + banners(d);
    S.ov.querySelectorAll('.oh-tabs button').forEach((b) => b.classList.toggle('on', b.getAttribute('data-tab') === S.tab));
    drawBody(S); drawFoot(S);
  }
  function banners(d) {
    const w = d.wait || {}, B = []; const ch = w.chat;
    if (ch && ch.waiting) B.push('<div class="oh-bn ' + (ch.mins >= 120 ? 'bad' : 'amber') + '">⏳ <span>Customer wrote <b>' + esc(dur(Math.max(0, ch.mins))) + '</b> ago and nobody has replied · chat with <b>' + esc(ch.holder || 'nobody') + '</b></span></div>');
    if (w.case) B.push('<div class="oh-bn bad">⚠ <span>Needs attention case open for <b>' + esc(dur(Math.max(0, w.case.mins))) + '</b> · owner <b>' + esc(w.case.owner || 'nobody yet') + '</b></span></div>');
    if (w.cx) B.push('<div class="oh-bn bad">🚩 <span>Complaint <b>' + esc(w.cx.code) + '</b> open for <b>' + esc(dur(Math.max(0, w.cx.mins))) + '</b>' + (w.cx.owner ? ' · owner <b>' + esc(w.cx.owner) + '</b>' : '') + '</span></div>');
    if (ch && !ch.waiting && !w.case && !w.cx && d.customer && d.customer.flags) {}
    return B.length ? '<div class="oh-bar">' + B.join('') + '</div>' : '';
  }
  function drawBody(S) {
    const d = S.d, bd = S.ov.querySelector('[data-bd]');
    if (S.tab === 'chat') {
      const ph = d.chat_phone || d.phone; if (!ph) { bd.innerHTML = '<div class="oh-empty"><b>No phone number</b>This order has no customer number, so there is no chat.</div>'; return; }
      if (S.chatPhone !== ph) { bd.innerHTML = '<div class="oh-chat"><iframe title="Customer chat" allow="microphone" src="wa_one.html?phone=' + encodeURIComponent(ph) + '"></iframe></div>'; S.chatPhone = ph; }
      return;
    }
    S.chatPhone = '';
    const narrow = window.matchMedia && window.matchMedia('(max-width:900px)').matches;   // phone: Order and Customer are two tabs; PC: side by side
    if (narrow) bd.innerHTML = S.tab === 'cust' ? '<div class="oh-card" data-cust>' + customerCard(S) + '</div>' : '<div class="oh-card" data-order>' + orderCard(S) + '</div>';
    else bd.innerHTML = '<div class="oh-cols"><div class="oh-card" data-order>' + orderCard(S) + '</div><div class="oh-card" data-cust>' + customerCard(S) + '</div></div>';
  }
  function drawFoot(S) {
    const d = S.d, p = d.parcel, f = S.ov.querySelector('[data-ft]'); let h = '';
    if (d.phone) h += '<button type="button" class="oh-ib sm pri" data-tab="chat">💬 Open chat</button>';
    if (p && p.tracking_no) h += '<button type="button" class="oh-ib sm" data-copy="' + esc(p.tracking_no) + '">📋 Copy AWB</button>';
    if (p && p.link) h += '<a class="oh-ib sm" href="' + esc(p.link) + '" target="_blank" rel="noopener">Courier page ↗</a>';
    if (p && p.id && p.tracking_no) h += '<button type="button" class="oh-ib sm" data-refresh>↻ Ask Shiprocket now</button>';
    if (d.case && d.case.id) h += '<span class="oh-chip warn" style="margin:0">Case #' + d.case.id + ' · ' + esc(d.case.status) + '</span>';
    h += '<span class="sp">' + (p && p.live_at ? 'Courier news last read ' + esc(fdt(p.live_at)) : '') + '</span><button type="button" class="oh-ib sm" data-x>Close</button>';
    f.innerHTML = h;
  }

  /* ---------- ORDER card ---------- */
  function orderCard(S) {
    const d = S.d, o = d.order, p = d.parcel, bill = d.bill; let h = '<div class="oh-ct">🧾 Order' + (o && o.manual ? ' <span class="oh-chip pu">MANUAL</span>' : '') + '<i>' + (p ? esc(p.order) : bill ? esc(bill.bill_no) : '') + '</i></div>';
    if (bill) return h + billCard(bill) + timelineHtml(d);
    if (!p) return h + '<div class="oh-empty"><b>No order opened</b>Pick one of this customer\'s orders on the right.</div>';
    const rto = p.status === 'rto', canc = p.status === 'cancelled', stg = stage(p, d.events);
    const head = rto ? 'Returning to sender' : canc ? 'Cancelled' : p.status === 'delivered' ? 'Delivered' : (p.sr_status ? titleCase(p.sr_status) : (p.status === 'placed' ? 'No tracking ID yet' : 'Tracking ID given'));
    const col = rto ? '#c2410c' : canc ? '#6b7280' : p.status === 'delivered' ? '#16945a' : '#14161a';
    let eta = ''; if (p.status === 'delivered') eta = '<span class="oh-eta ok">Delivered ' + esc(fdt(p.delivered_at)) + '</span>';
    else if (rto) eta = '<span class="oh-eta bad">Coming back to you</span>';
    else if (p.etd) { const late = new Date(p.etd + 'T23:59:00').getTime() < Date.now(); eta = '<span class="oh-eta' + (late ? ' bad' : '') + '">' + (late ? 'Was expected ' : 'Expected by ') + esc(fd(p.etd)) + '</span>'; }
    h += '<div class="oh-st"><b style="color:' + col + '">' + esc(head) + '</b>' + eta + '</div>';
    if (!canc) h += '<div class="oh-steps">' + STEPS.map((n, i) => '<div class="oh-s' + (i <= stg ? ' done' : '') + (i === stg ? ' now' : '') + (rto && i === stg ? ' bad' : '') + '"><i></i>' + n + '</div>').join('') + '</div>';
    h += '<div class="oh-sec"><div class="t">🚚 Shipping</div><div class="oh-g">' + kv('Courier', esc(p.courier || '—')) + kv('AWB', p.tracking_no ? (p.link ? '<a href="' + esc(p.link) + '" target="_blank" rel="noopener">' + esc(p.tracking_no) + ' ↗</a>' : esc(p.tracking_no)) : 'not given yet')
      + kv('Shiprocket says', esc(p.sr_status ? titleCase(p.sr_status) : '—')) + '</div></div>';
    if (!o) return h + '<div class="oh-sub" style="margin:8px 0">Payment and salesperson are not copied for this order yet (the background job fills them in).</div>' + timelineHtml(d);
    const pay = o.pay || {}, wm = o.wm, com = o.com, st = String(pay.status || '').replace(/_/g, ' ');
    const cod = pay.outstanding > 0 ? rs(pay.outstanding) + ' COD' : (wm && wm.cod > 0 ? rs(wm.cod) + ' COD' : 'Prepaid');
    h += '<div class="oh-sec"><div class="t">💳 Payment &amp; gateway</div><div class="oh-g">' + kv('Total', rs(pay.total != null ? pay.total : (wm && wm.total))) + kv('To collect', esc(cod), pay.outstanding > 0 || (wm && wm.cod > 0) ? 'bad' : 'ok')
      + kv('Payment', esc(st || (wm ? (wm.pay === 'cod' ? 'COD' : wm.pay === 'partial' ? 'Part paid' : 'Paid') : '—'))) + kv('Gateway', '<span data-gw>' + esc(gatewayOf(S) || '—') + '</span>')
      + kv(o.manual ? 'UTR' : 'Transaction ID', esc((wm && wm.utr) || (pay.ref || '').split(',')[0] || '—')) + kv('Finance', pay.paid_amt != null ? rs(pay.paid_amt) + ' in' + (pay.paid_on ? ' · ' + esc(fd(pay.paid_on)) : '') : (pay.closed ? esc(pay.closed) : 'not received yet'), pay.paid_amt != null ? 'ok' : 'am')
      + kv('Order code', esc(o.code || '—')) + kv('ML', o.ml != null ? o.ml + ' ml' : '—') + (pay.refund ? kv('Refund', esc(pay.refund), 'bad') : '') + '</div>';
    if (wm) { const rd = wm.read || {}; h += '<div class="oh-sub" style="margin-top:8px">' + (wm.paid ? 'Paid ' + rs(wm.paid) + (wm.cod ? ' + ' + rs(wm.cod) + ' on delivery' : '') : 'Full COD') + (rd.amount ? ' · Claude read ' + rs(rd.amount) + (rd.date ? ' on ' + esc(rd.date) : '') + (rd.payer ? ' from ' + esc(rd.payer) : '') : '') + '</div>' + (wm.shot ? '<div data-shot><button type="button" class="oh-ib sm" data-shotbtn style="margin-top:6px">🖼 Show payment screenshot</button></div>' : ''); }
    if (!o.manual) h += '<div data-pay>' + payHtml(S) + (S.pay ? '' : ((pay.tx || []).length ? '<div class="oh-sub" style="margin-top:8px">' + pay.tx.map((t) => esc([t.gateway, t.kind, t.status, t.amount !== '' && t.amount != null ? rs(t.amount) : '', t.payment_id, fdt(t.at)].filter(Boolean).join(' · '))).join('<br>') + '</div>' : '')) + '</div>';
    h += '</div>';
    h += '<div class="oh-sec"><div class="t">👤 Salesperson &amp; commission</div><div class="oh-g">' + kv('Took the order', esc(o.sp || 'not allocated')) + kv('Type', o.sp_type ? (o.sp_type === 'campaign' ? 'Campaign' : 'Query') : '—')
      + kv('Commission', com ? rs(com.amt) + (com.pct ? ' · ' + com.pct + '%' : '') : 'not counted yet', com ? 'ok' : 'am') + (wm ? kv('Manual order by', esc(wm.by || '—')) : '') + (o.wa ? kv('Chat with', esc(o.wa.holder || '—')) : '') + '</div>'
      + (o.tags ? '<div style="margin-top:7px">' + o.tags.split(',').map((x) => x.trim()).filter(Boolean).map((x) => '<span class="oh-chip">' + esc(x) + '</span>').join('') + '</div>' : '') + '</div>';
    h += '<div class="oh-sec"><div class="t">📦 Items &amp; address</div><div data-items>' + itemsHtml(S) + '</div></div>';
    h += '<div class="oh-sec"><div class="t">⚠ Complaints<span>' + (o.cx.length ? o.cx.length : 'none') + '</span></div>' + (o.cx.length ? o.cx.map((x) => { const op = x.status === 'open' || x.status === 'reopened';
      return '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:13px;margin-bottom:5px"><a href="complaints.html?case=' + x.id + '" target="_top"><b>' + esc(x.code) + '</b></a><span>' + esc(x.label) + '</span><span class="oh-chip ' + (op ? 'bad' : 'ok') + '" style="margin:0">' + (op ? 'open' : esc(x.status)) + '</span>' + (x.owner ? '<span class="oh-sub">' + esc(x.owner) + '</span>' : '') + '<span class="oh-sub">' + esc(fdt(x.raised)) + (x.this_order ? '' : ' · other order') + '</span></div>'; }).join('') : '<div class="oh-sub">No complaint for this order or customer.</div>') + '</div>';
    return h + timelineHtml(d);
  }
  function itemsHtml(S) {
    const o = S.d.order; if (!o) return ''; const wm = o.wm, it = S.items;
    const lines = wm ? wm.lines : (it ? it.line_items : null), cu = wm ? wm.cust : it;
    let h = lines ? lines.map((l) => '<div class="oh-li"><span>' + esc(l.name || ((l.title || '') + (l.variant ? ' · ' + l.variant : ''))) + (l.chain ? ' <em>chain</em>' : '') + ' <em>× ' + (l.qty || l.quantity || 1) + '</em></span><span>' + rs(l.total) + '</span></div>').join('')
      : '<div class="oh-sub">' + (it && it.err ? 'Could not read the items from Shopify: ' + esc(it.err) : (o.manual ? '—' : 'Reading the order from Shopify…')) + '</div>';
    if (cu && !cu.err) { const ad = wm ? [cu.addr1, cu.addr2, cu.landmark, [cu.city, cu.pin].filter(Boolean).join(' '), cu.state].filter(Boolean).join(', ') : (cu.address || []).join(', ');
      h += '<div class="oh-sub" style="margin-top:8px">' + (ad ? '📍 ' + esc(ad) : '') + (wm && (cu.phone || cu.phone2) ? '<br>📞 ' + esc([cu.phone, cu.phone2].filter(Boolean).join(' / ')) : '') + (cu.discount_codes && cu.discount_codes.length ? '<br>🏷 ' + cu.discount_codes.map((x) => esc(x.code)).join(', ') : '') + '</div>'; }
    return h;
  }
  function billCard(b) {
    return '<div class="oh-sec"><div class="t">🏬 Store bill</div><div class="oh-g">' + kv('Bill', esc(b.bill_no || '—')) + kv('Date', esc(fdt(b.at))) + kv('Billed by', esc(b.by || '—')) + kv('Total', rs(b.total), 'ok') + kv('List price', rs(b.list)) + kv('Discount', rs(b.discount), b.discount > 0 ? 'am' : '')
      + kv('Paid by', esc(b.pay || '—')) + kv('Cash / online', rs(b.cash) + ' / ' + rs(b.online)) + kv('ML · bottles', (b.ml || 0) + ' ml · ' + (b.bottles || 0)) + kv('Order code', esc(b.code || '—')) + '</div></div>';
  }
  function timelineHtml(d) {
    const T = d.timeline || []; let h = '<div class="oh-sec"><div class="t">⏱ Full timeline<span>' + T.length + ' step' + (T.length === 1 ? '' : 's') + '</span></div>';
    if (!T.length) return h + '<div class="oh-sub">Nothing recorded yet.</div></div>';
    return h + '<ul class="oh-tl">' + T.map((e) => '<li class="' + esc(e.kind) + ' ' + esc(e.tone || '') + '"><b>' + esc(e.title) + '</b><span>' + esc(fdt(e.at)) + (e.text ? ' · ' + esc(e.text) : '') + '</span></li>').join('') + '</ul></div>';
  }

  /* ---------- CUSTOMER card ---------- */
  function customerCard(S) {
    const d = S.d, c = d.customer; let h = '<div class="oh-ct">👤 Customer<i>' + (c ? 'one person = last 10 digits of the number' : '') + '</i></div>';
    if (!c) return h + '<div class="oh-empty"><b>No phone number on this order</b>Without a number we cannot tell who the customer is.</div>';
    const ini = (c.name || '?').trim().split(/\s+/).slice(0, 2).map((x) => x[0]).join('').toUpperCase() || '?';
    const chn = c.channel === 'both' ? 'Store + Online' : c.channel === 'store' ? 'Store' : c.channel === 'online' ? 'Online' : '';
    h += '<div class="oh-who"><div class="oh-av">' + esc(ini) + '</div><div style="min-width:0"><h3>' + esc(c.name || 'Customer') + '</h3><div class="ph"><a href="tel:+91' + esc(c.phone) + '">' + esc(tel(c.phone)) + '</a><button type="button" class="oh-ib sm" data-copy="' + esc(c.phone) + '">Copy</button>' + (chn ? '<span class="oh-chip ' + (c.channel === 'both' ? 'pu' : 'info') + '" style="margin:0">' + chn + '</span>' : '') + '</div></div></div>';
    if ((c.phones || []).length > 1) h += '<div class="oh-sub" style="margin:-4px 0 8px">Also uses: ' + c.phones.slice(1).map((x) => esc(tel(x))).join(', ') + '</div>';
    if ((c.names_list || []).length > 1) h += '<div class="oh-sub" style="margin:-4px 0 10px">Also saved as: ' + c.names_list.slice(1).map(esc).join(', ') + '</div>';
    if ((c.flags || []).length) h += '<div style="margin-bottom:10px">' + c.flags.map((f) => '<span class="oh-chip ' + esc(f[0]) + '">' + (f[0] === 'bad' ? '🚩 ' : f[0] === 'warn' ? '⚠ ' : f[0] === 'ok' ? '⭐ ' : '') + esc(f[1]) + '</span>').join('') + '</div>';
    h += '<div class="oh-big"><div><small>Total orders</small><b>' + c.orders_n + '</b></div><div><small>Total spent</small><b>' + rs(c.spent) + '</b></div><div><small>Average order</small><b>' + rs(c.aov) + '</b></div><div><small>Last order</small><b>' + (c.days_since == null ? '—' : c.days_since === 0 ? 'today' : c.days_since + ' d ago') + '</b></div></div>';
    h += '<div class="oh-sec"><div class="t">Where they buy</div><div class="oh-g">' + kv('Online', c.online_n + ' · ' + rs(c.online_amt)) + kv('In the store', c.store_n + ' · ' + rs(c.store_amt)) + kv('Old POS', c.pos_n + ' · ' + rs(c.pos_amt))
      + kv('First order', esc(fd(c.first_at) || '—')) + kv('Parcels returned', c.rto_n + '', c.rto_n ? 'bad' : '') + kv('Complaints', c.cx_n + (c.cx_open ? ' (' + c.cx_open + ' open)' : ''), c.cx_open ? 'bad' : '') + '</div>'
      + (c.online_since ? '<div class="oh-sub" style="margin-top:7px">Online orders are counted from ' + esc(fd(c.online_since)) + ' (how far back HRMS has them).</div>' : '') + '</div>';
    const cur = d.parcel ? d.parcel.order : (d.bill ? d.bill.bill_no : '');
    h += '<div class="oh-sec"><div class="t">All orders<span>' + (d.orders || []).length + (d.orders && d.orders.length >= 60 ? '+' : '') + '</span></div>' + ((d.orders || []).length ? d.orders.map((x, i) => {
      const go = x.src === 'online' || x.src === 'manual' || x.src === 'store', can = x.status === 'cancelled';
      return '<' + (go ? 'button type="button"' : 'div') + ' class="oh-ol' + (go ? ' go' : '') + (x.name === cur ? ' cur' : '') + '"' + (go ? ' data-ord="' + i + '"' : '') + '><span class="n">' + esc(x.name) + '</span><span class="d"><span class="tag ' + (x.src === 'store' ? 'store' : x.src === 'pos' ? 'pos' : '') + '">' + (x.src === 'online' ? 'online' : x.src === 'manual' ? 'manual' : x.src === 'store' ? 'store' : 'old pos') + '</span> '
        + (can ? '<span class="tag can">cancelled</span> ' : '') + esc(fdt(x.at)) + (x.who && x.src === 'store' ? ' · ' + esc(x.who) : '') + '</span><span class="a">' + rs(x.total) + '</span></' + (go ? 'button' : 'div') + '>'; }).join('') : '<div class="oh-sub">No order found for this number.</div>') + '</div>';
    return h;
  }

  /* ---------- clicks ---------- */
  async function onClick(e, S) {
    if (CUR !== S) return; const t = e.target;
    if (t === S.ov || t.closest('[data-x]')) return close();
    const tab = t.closest('[data-tab]'); if (tab) { S.tab = tab.getAttribute('data-tab'); if (S.tab === 'cust' && !(window.matchMedia && window.matchMedia('(max-width:900px)').matches)) S.tab = 'main'; S.ov.querySelectorAll('.oh-tabs button').forEach((b) => b.classList.toggle('on', b.getAttribute('data-tab') === S.tab)); drawBody(S); return; }
    if (t.closest('[data-back]')) { const prev = S.stack.pop(); if (prev) { S.tab = 'main'; load(S, prev); } return; }
    const cp = t.closest('[data-copy]'); if (cp) return copy(cp.getAttribute('data-copy'));
    const od = t.closest('[data-ord]');
    if (od) { const x = S.d.orders[+od.getAttribute('data-ord')]; if (!x) return; S.stack.push(S.req); S.tab = 'main';
      return load(S, x.src === 'store' ? { bill: x.bill, phone: S.d.phone } : { order: x.name, store: x.store, sid: x.sid, phone: S.d.phone }); }
    if (t.closest('[data-refresh]')) {
      const b = t.closest('[data-refresh]'); const p = S.d.parcel; if (!p || !p.id) return; b.disabled = true; b.textContent = 'Asking Shiprocket…';
      try { await api.get('dp.dsp_live', { id: p.id, force: 1 }); S.dirty = true; await load(S, S.req, true); toast('Courier news updated'); } catch (er) { toast(errMsg(er)); b.disabled = false; b.textContent = '↻ Ask Shiprocket now'; }
      return;
    }
    if (t.closest('[data-payretry]')) { S.pay = null; loadPay(S); const x = S.ov.querySelector('[data-pay]'); if (x) x.innerHTML = payHtml(S); return; }
    const sb = t.closest('[data-shotbtn]');
    if (sb && S.d.parcel) { sb.disabled = true; sb.textContent = 'Loading…';
      try { const r = await api.get('dp.dsp_card_shot', { id: S.d.parcel.id }); sb.parentNode.innerHTML = '<img class="oh-img" alt="Payment screenshot" src="data:' + esc(r.mime) + ';base64,' + String(r.b64).replace(/[^A-Za-z0-9+/=]/g, '') + '">'; } catch (er) { sb.textContent = errMsg(er); }
    }
  }

  /* ===================================================== the Customers list ===================================================== */
  function customers(el) {
    css(); const S = { q: '', flt: 'all', sort: 'last', page: 1, d: null, t: 0 };
    const FL = [['all', 'Everyone'], ['vip', '⭐ High value'], ['repeat', 'Repeat'], ['new', 'First-time'], ['both', 'Store + Online'], ['store', 'Store'], ['online', 'Online'], ['risk', '🚩 Risk'], ['lapsed', 'Not ordered for 120 d']];
    async function load(quiet) {
      if (!quiet) el.querySelector('[data-rows]').innerHTML = '<tr><td colspan="8"><div class="oh-empty">Loading…</div></td></tr>';
      try { S.d = await api.get('dp.dsp_cust_list', { q: S.q, flt: S.flt, sort: S.sort, page: S.page }); } catch (e) { el.querySelector('[data-rows]').innerHTML = '<tr><td colspan="8"><div class="oh-empty"><b>Cannot open the customers</b>' + esc(errMsg(e)) + '</div></td></tr>'; return; }
      draw();
    }
    function draw() {
      const d = S.d, T = d.totals || {};
      el.querySelector('[data-tiles]').innerHTML = '<div><small>People</small><b>' + (T.people || 0) + '</b></div><div><small>Repeat customers</small><b>' + (T.repeat || 0) + '</b></div><div><small>Store + Online</small><b>' + (T.both || 0) + '</b></div><div><small>With a risk flag</small><b>' + (T.risk || 0) + '</b></div><div><small>In this list</small><b>' + d.count + '</b></div><div><small>Their total spend</small><b>' + rs(d.spent) + '</b></div>';
      el.querySelector('[data-note]').textContent = T.at ? 'Numbers read from every order ' + fdt(T.at) + ' · refreshed by itself every 6 hours' : '';
      el.querySelector('[data-rows]').innerHTML = d.rows.length ? d.rows.map((r, i) => '<tr class="r" data-i="' + i + '"><td class="ohc-nm"><b>' + esc(r.name || 'No name') + '</b><small>' + esc(tel(r.p10)) + '</small></td>'
        + '<td>' + (r.channel === 'both' ? '<span class="oh-chip pu">Store + Online</span>' : r.channel === 'store' ? '<span class="oh-chip info">Store</span>' : r.channel === 'online' ? '<span class="oh-chip info">Online</span>' : '') + '</td>'
        + '<td class="n" data-l="Orders">' + r.orders_n + '</td><td class="n" data-l="Spent"><b>' + rs(r.spent) + '</b></td><td class="n" data-l="Average">' + rs(r.aov) + '</td><td class="n" data-l="Last order">' + esc(fd(r.last_at) || '—') + '</td>'
        + '<td>' + (r.flags || []).filter((f) => f[0] === 'bad' || f[0] === 'warn' || f[1] === 'High-value customer').map((f) => '<span class="oh-chip ' + esc(f[0]) + '">' + esc(f[1]) + '</span>').join('') + '</td></tr>').join('') : '<tr><td colspan="8"><div class="oh-empty"><b>Nobody matches</b>Try another search or filter.</div></td></tr>';
      const pages = Math.max(1, Math.ceil(d.count / d.per)); el.querySelector('[data-pg]').innerHTML = pages > 1 ? '<button type="button" class="oh-ib sm" data-pp ' + (S.page <= 1 ? 'disabled' : '') + '>←</button> Page ' + S.page + ' of ' + pages + ' <button type="button" class="oh-ib sm" data-pn ' + (S.page >= pages ? 'disabled' : '') + '>→</button>' : '';
    }
    el.innerHTML = '<div class="ohc-tiles" data-tiles></div><div class="ohc-bar"><input data-q placeholder="Search a name or a phone number" inputmode="search" autocomplete="off"><select data-sort><option value="last">Latest order first</option><option value="spent">Biggest spender</option><option value="orders">Most orders</option><option value="first">Newest customer</option><option value="rto">Most problems</option><option value="name">Name A–Z</option></select><button type="button" class="oh-ib sm" data-rb>↻ Rebuild now</button><button type="button" class="oh-ib sm" data-imp title="Read every customer from Shopify (all years): their orders and spend">⬇ Import from Shopify</button></div>'
      + '<div class="ohc-f" data-f>' + FL.map((f) => '<button type="button" data-flt="' + f[0] + '"' + (f[0] === 'all' ? ' class="on"' : '') + '>' + f[1] + '</button>').join('') + '</div><div style="overflow-x:auto"><table class="ohc-t"><thead><tr><th>Customer</th><th>Buys</th><th class="n">Orders</th><th class="n">Spent</th><th class="n">Average</th><th class="n">Last order</th><th>Flags</th></tr></thead><tbody data-rows></tbody></table></div><div class="ohc-pg" data-pg></div><div class="oh-sub" style="text-align:center" data-note></div>';
    el.querySelector('[data-q]').oninput = function () { clearTimeout(S.t); const v = this.value.trim(); S.t = setTimeout(() => { S.q = v; S.page = 1; load(true); }, 350); };
    el.querySelector('[data-sort]').onchange = function () { S.sort = this.value; S.page = 1; load(true); };
    el.addEventListener('click', async (e) => {
      const f = e.target.closest('[data-flt]'); if (f) { S.flt = f.getAttribute('data-flt'); S.page = 1; el.querySelectorAll('[data-flt]').forEach((b) => b.classList.toggle('on', b === f)); return load(true); }
      if (e.target.closest('[data-pp]')) { S.page = Math.max(1, S.page - 1); return load(true); } if (e.target.closest('[data-pn]')) { S.page++; return load(true); }
      const rb = e.target.closest('[data-rb]'); if (rb) { rb.disabled = true; rb.textContent = 'Reading every order…'; try { const r = await api.post('dp.dsp_cust_rebuild', {}); toast(r.people + ' customers'); await load(true); } catch (er) { toast(errMsg(er)); } rb.disabled = false; rb.textContent = '↻ Rebuild now'; return; }
      const ib = e.target.closest('[data-imp]');
      if (ib) { ib.disabled = true; let got = 0;
        try { for (let i = 0; i < 400; i++) { const r = await api.post('dp.dsp_cust_import', {}); got += r.read || 0; ib.textContent = 'Reading Shopify… ' + got.toLocaleString('en-IN') + (r.total ? ' of ' + r.total.toLocaleString('en-IN') : ''); if (!r.ok) { toast(r.error || 'Shopify did not answer.'); break; } if (r.done) { toast('All customers imported (' + (r.total || got).toLocaleString('en-IN') + ')'); break; } } await load(true); } catch (er) { toast(errMsg(er)); }
        ib.disabled = false; ib.textContent = '⬇ Import from Shopify'; return; }
      const tr = e.target.closest('tr[data-i]'); if (tr && S.d) { const r = S.d.rows[+tr.getAttribute('data-i')]; if (r) open({ phone: r.p10 }); }
    });
    load(); return { refresh: () => load(true) };
  }

  /* the strip at the top of the Dispatch page: how many customers we have in all */
  function summary(el) {
    css(); el.classList.add('ohs-w'); let busy = false;
    async function load() {
      if (busy) return; busy = true;
      try {
        const d = await api.get('dp.dsp_cust_list', { totals: 1 }); const n = (x) => Number(x || 0).toLocaleString('en-IN');
        el.innerHTML = '<a class="ohs" href="customers.html" title="Open the Customers list"><div class="ohs-m"><small>👥 Total customers</small><b>' + n(d.people) + '</b></div><div><small>Repeat (2+ orders)</small><b>' + n(d.repeat) + '</b></div><div><small>New in last 30 days</small><b>' + n(d.new30) + '</b></div><div><small>Store + Online</small><b>' + n(d.both) + '</b></div><div><small>With a risk flag</small><b>' + n(d.risk) + '</b></div><div><small>Their total spend</small><b>' + rs(d.spent) + '</b></div><span class="ohs-go">All customers →</span></a>'
          + ((d.sync || []).length ? '<div class="ohs-sy">' + d.sync.map((x) => '<span class="oh-chip ' + (x.state === 'ok' ? 'ok' : x.state === 'bad' ? 'bad' : x.state === 'warn' ? 'warn' : 'info') + '" title="' + esc(x.text) + '"><b>' + (x.state === 'ok' ? '●' : x.state === 'bad' ? '✕' : '▲') + ' ' + esc(x.label) + '</b>' + (x.state === 'ok' ? '' : ' · ' + esc(x.text)) + '</span>').join('') + '</div>' : '');
      } catch (e) { el.innerHTML = ''; }
      busy = false;
    }
    load(); return { refresh: load };
  }

  return { open, close, customers, summary };
})();
