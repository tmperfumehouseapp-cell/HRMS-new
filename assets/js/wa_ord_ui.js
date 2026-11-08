/* wa_ord_ui.js — live orders in the WhatsApp chat, "Needs attention" for Dispatch, order-update settings and the Store info form.
 * Shared by the manager chat screen (wa_inbox.js), the salesperson phone app (wa_app.js), the Dispatch page and Learning. Needs the global `api`.
 * Talks to wa_orders.php (wa.ord_*, wa.store_*). Nothing here sends a customer message; a person always taps a button.
 *
 *   WaOrd.card(container, { phone, name, onChange })     the orders of this chat's customer (live status, scans, "Link an order", "Needs attention").  -> { refresh(), destroy() }
 *   WaOrd.open({ phone, name, onChange })                the same card in a bottom sheet (for a "📦 Orders" button in the chat header)
 *   WaOrd.urgent({ phone, name, order, onDone })         the "Needs attention" form (Delivery / order problem -> Dispatch, Complaint -> Complaints)
 *   WaOrd.link({ phone, onDone })                        find an order by number or phone and link it to this chat
 *   WaOrd.attention(container, { onCount })              the Dispatch "Needs attention" tab (cases, Take it / Done / note) + the order-update settings for Admin
 *   WaOrd.count(cb)                                      cb(openCases) for the tab badge (silent on errors)
 *   WaOrd.storeForm(container)                           Learning: "Store info" (timings, address, delivery times); Admin edits, everyone else reads
 *   WaOrd.settings(container)                            the automatic order-update messages card (Admin)
 */
window.WaOrd = (function () {
  'use strict';
  if (window.WaOrd) return window.WaOrd;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const errMsg = (e) => (e && e.message) || 'Something went wrong. Try again.';
  const parse = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)/.exec(String(s || '')); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : null; };
  const fmt = (s) => { const d = parse(s); if (!d) return ''; const h = d.getHours(), ap = h >= 12 ? 'PM' : 'AM'; return d.getDate() + ' ' + MON[d.getMonth()] + ', ' + ((h % 12) || 12) + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + ap; };
  const day = (s) => { const d = parse(s) || (/^\d{4}-\d\d-\d\d$/.test(s || '') ? new Date(s + 'T00:00') : null); return d ? d.getDate() + ' ' + MON[d.getMonth()] : ''; };
  const inr = (n) => (n == null ? '' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 }));
  const tel = (p) => (p ? p.replace(/^(\d{5})(\d{5})$/, '$1 $2') : '');

  const CSS = `
  .wor{font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;color:#111b21;-webkit-tap-highlight-color:transparent} .wor *{box-sizing:border-box}
  .wor-card{background:#fff;border:1px solid #e9edef;border-radius:16px;padding:12px;margin:8px 0}
  .wor-hd{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px} .wor-hd b{font-size:15px;font-weight:800} .wor-hd small{color:#667781;font-weight:600;margin-left:4px}
  .wor-ic{width:34px;height:34px;border:0;border-radius:50%;background:#f0f2f5;color:#54656f;font-size:15px;cursor:pointer}
  .wor-ord{border:1px solid #e9edef;border-radius:14px;padding:10px 11px;margin-top:8px;background:#fff}
  .wor-ord.bad{border-color:#f3c5c2;background:#fffafa} .wor-ord.ok{border-color:#cfeadf}
  .wor-top{display:flex;align-items:center;justify-content:space-between;gap:8px} .wor-nm{font-size:15px;font-weight:800;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap} .wor-tot{font-size:13px;font-weight:700;color:#3b4a54;white-space:nowrap}
  .wor-pill{display:inline-block;border-radius:999px;padding:3px 10px;font-size:11.5px;font-weight:800;background:#eef0f2;color:#3b4a54;white-space:nowrap}
  .wor-pill.shipped,.wor-pill.packed{background:#e3efff;color:#0b5394} .wor-pill.ofd{background:#fff1d6;color:#8a5a00} .wor-pill.delivered{background:#d9fdd3;color:#005c4b}
  .wor-pill.failed,.wor-pill.rto,.wor-pill.lost,.wor-pill.damaged,.wor-pill.delayed{background:#fde4e4;color:#b3261e} .wor-pill.cancelled{background:#eef0f2;color:#667781}
  .wor-tx{font-size:14px;line-height:1.35;margin-top:6px} .wor-mt{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
  .wor-ch{font-size:12px;background:#f0f2f5;color:#3b4a54;border-radius:8px;padding:3px 8px;white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis} .wor-ch.link{background:#e7f5ff;color:#0b5394;text-decoration:none} .wor-ch.sent{background:#e7fbf3;color:#007a62;font-weight:600} .wor-ch.muted{color:#667781}
  .wor-sc{font-size:12.5px;color:#54656f;margin-top:6px} .wor-sc b{color:#111b21}
  .wor-tl{margin-top:6px} .wor-tl summary{font-size:12.5px;font-weight:700;color:#008069;cursor:pointer;padding:4px 0;list-style:none} .wor-tl summary::-webkit-details-marker{display:none}
  .wor-tl ol{list-style:none;margin:4px 0 0;padding:0 0 0 12px;border-left:2px solid #e9edef} .wor-tl li{font-size:12.5px;margin:0 0 7px;padding-left:8px;position:relative} .wor-tl li:before{content:"";position:absolute;left:-17px;top:5px;width:8px;height:8px;border-radius:50%;background:#00a884} .wor-tl li small{display:block;color:#667781}
  .wor-lk{font-size:12px;color:#667781;margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap} .wor-lk button{border:0;background:none;color:#b3261e;font:inherit;font-weight:700;cursor:pointer;padding:6px 0}
  .wor-none{text-align:center;padding:14px 8px 6px} .wor-none b{display:block;font-size:15px;margin-bottom:4px} .wor-none p{margin:0 0 10px;font-size:13px;color:#667781;line-height:1.4}
  .wor-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:0 18px;border:1.5px solid #008069;border-radius:999px;background:#fff;color:#008069;font:inherit;font-size:14.5px;font-weight:800;cursor:pointer}
  .wor-btn.pri{background:#008069;color:#fff;border-color:#008069} .wor-btn.urg{background:#fff4f2;color:#b3261e;border-color:#e7b4b0;width:100%;margin-top:8px} .wor-btn.sm{min-height:38px;padding:0 14px;font-size:13px} .wor-btn.bad{border-color:#d92d20;color:#b3261e} .wor-btn[disabled]{opacity:.55;cursor:default}
  .wor-warn{background:#fff1d6;border-radius:12px;padding:8px 11px;font-size:13px;color:#7a4b00;margin:6px 0;font-weight:600}
  .wor-err{background:#fde4e4;border-radius:12px;padding:10px 12px;font-size:13px;color:#7a1d17;margin:8px 0}
  .wor-ov{position:fixed;inset:0;z-index:2147483000;background:rgba(17,27,33,.55);display:flex;align-items:flex-end;justify-content:center;font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif}
  .wor-sh{background:#fff;width:100%;max-width:520px;max-height:92vh;overflow:auto;border-radius:22px 22px 0 0;padding:16px 16px calc(16px + env(safe-area-inset-bottom));color:#111b21;animation:worUp .2s ease-out} @keyframes worUp{from{transform:translateY(40px);opacity:.4}to{transform:none;opacity:1}}
  @media (min-width:700px){.wor-ov{align-items:center}.wor-sh{border-radius:22px}}
  .wor-sh h3{margin:0;font-size:18px;font-weight:800} .wor-shh{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
  .wor-l{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#667781;margin:12px 0 6px}
  .wor-seg{display:grid;grid-template-columns:1fr 1fr;gap:8px} .wor-chips{display:flex;flex-wrap:wrap;gap:8px}
  .wor-c{border:1.5px solid #e9edef;background:#fff;border-radius:999px;padding:9px 14px;font:inherit;font-size:14px;font-weight:700;color:#111b21;cursor:pointer;text-align:center} .wor-seg .wor-c{border-radius:14px;padding:11px 8px;line-height:1.25}
  .wor-c.on{border-color:#00a884;background:#e7fbf3;color:#007a62} .wor-c.on.cx{border-color:#f59e0b;background:#fff1d6;color:#8a5a00}
  .wor-in{width:100%;border:1.5px solid #e9edef;border-radius:14px;padding:11px 13px;font:inherit;font-size:16px;outline:0;background:#fff;color:#111b21;-webkit-appearance:none;appearance:none} .wor-in:focus{border-color:#00a884} textarea.wor-in{min-height:76px;resize:vertical}
  .wor-go{width:100%;margin-top:14px;border:0;border-radius:999px;background:#008069;color:#fff;font:inherit;font-size:16px;font-weight:800;padding:14px;cursor:pointer} .wor-go[disabled]{opacity:.55}
  .wor-res{display:flex;align-items:center;gap:10px;padding:10px 4px;border-bottom:1px solid #f0f2f5} .wor-res>div{flex:1;min-width:0} .wor-res b{font-size:14.5px} .wor-res small{display:block;color:#667781;font-size:12.5px;line-height:1.3}
  .wor-toast{position:fixed;left:50%;bottom:calc(24px + env(safe-area-inset-bottom));transform:translateX(-50%);background:#111b21;color:#fff;padding:11px 18px;border-radius:999px;font:600 14px Inter,system-ui,sans-serif;z-index:2147483200;max-width:88vw;text-align:center} .wor-toast.bad{background:#b3261e}
  /* the Dispatch tab and the settings / store forms: the HRMS look */
  .wor-pg{max-width:980px;margin:0 auto} .wor-tile{background:var(--surface,#fff);border-radius:var(--radius,20px);padding:16px 18px;margin-bottom:14px;box-shadow:0 1px 2px rgba(20,22,26,.04),0 6px 18px -10px rgba(20,22,26,.12)}
  .wor-tile h2{margin:0 0 4px;font-size:17px;font-weight:800;color:var(--ink,#14161a)} .wor-sub{font-size:13px;color:var(--muted,#6f7480);margin:0 0 10px;line-height:1.4}
  .wor-stats{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px} .wor-st{background:var(--surface,#fff);border-radius:16px;padding:9px 14px;box-shadow:0 1px 2px rgba(20,22,26,.04);min-width:84px} .wor-st b{display:block;font-size:20px;font-weight:800;color:var(--ink,#14161a)} .wor-st span{font-size:12px;color:var(--muted,#6f7480);font-weight:600}
  .wor-tabs{display:flex;gap:6px;margin:0 0 12px;overflow-x:auto} .wor-tabs button{flex:none;border:1px solid var(--line,#e6e8ec);background:#fff;border-radius:99px;padding:8px 15px;font:inherit;font-weight:700;font-size:13px;cursor:pointer;color:#4a505c} .wor-tabs button.on{background:var(--ink,#14161a);color:#fff;border-color:var(--ink,#14161a)}
  .wor-case{background:var(--surface,#fff);border-radius:var(--radius,20px);padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 2px rgba(20,22,26,.04),0 6px 18px -10px rgba(20,22,26,.12);border-left:5px solid #d1d5db}
  .wor-case.amber{border-left-color:#f59e0b} .wor-case.red{border-left-color:#d9423b} .wor-case.done{opacity:.75}
  .wor-ct{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap} .wor-rs{font-size:12px;font-weight:800;border-radius:999px;padding:4px 11px;background:#fde4e4;color:#b3261e} .wor-rs.auto{background:#e8f0fe;color:#2456b3}
  .wor-tm{font-size:13px;font-weight:800;color:#3a3f4a;white-space:nowrap} .wor-case.amber .wor-tm{color:#9a6200} .wor-case.red .wor-tm{color:#b3261e}
  .wor-who{font-size:15.5px;font-weight:800;margin:8px 0 2px;color:var(--ink,#14161a)} .wor-who a{color:inherit;text-decoration:none} .wor-meta{font-size:13px;color:#3a3f4a;line-height:1.45} .wor-note{background:#f4f5f7;border-radius:12px;padding:8px 11px;margin:8px 0;font-size:13.5px;line-height:1.4}
  .wor-sm{font-size:12px;color:var(--muted,#6f7480);margin-top:2px} .wor-act{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
  .wor-b{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:8px 16px;border-radius:99px;border:1px solid var(--ink,#14161a);background:#fff;color:var(--ink,#14161a);font:inherit;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;line-height:1.1}
  .wor-b.pri{background:var(--ink,#14161a);color:#fff} .wor-b.go{background:#16945a;border-color:#16945a;color:#fff} .wor-b[disabled]{opacity:.5;cursor:not-allowed}
  .wor-hist summary{font-size:12.5px;font-weight:700;color:#2456b3;cursor:pointer;margin-top:8px} .wor-hist div{font-size:12.5px;color:#3a3f4a;margin:5px 0 0;padding-left:8px;border-left:2px solid #e6e8ec}
  .wor-empty{text-align:center;color:var(--muted,#6f7480);padding:34px 14px;font-size:14px} .wor-empty b{display:block;color:var(--ink,#14161a);font-size:16px;margin-bottom:4px}
  .wor-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid var(--line,#e6e8ec)} .wor-row:last-child{border-bottom:0} .wor-row b{font-size:14px} .wor-row small{display:block;color:var(--muted,#6f7480);font-size:12px}
  .wor-sw{position:relative;width:46px;height:28px;flex:none} .wor-sw input{opacity:0;width:100%;height:100%;position:absolute;inset:0;margin:0;cursor:pointer;z-index:1} .wor-sw i{position:absolute;inset:0;border-radius:99px;background:#cfd3d9;transition:.15s} .wor-sw i:after{content:"";position:absolute;left:3px;top:3px;width:22px;height:22px;border-radius:50%;background:#fff;transition:.15s;box-shadow:0 1px 2px #0003} .wor-sw input:checked+i{background:#16945a} .wor-sw input:checked+i:after{left:21px}
  .wor-ts{font-size:11.5px;font-weight:700;border-radius:99px;padding:2px 9px;background:#eef0f2;color:#3a3f4a} .wor-ts.approved{background:#d9fdd3;color:#005c4b} .wor-ts.rejected{background:#fde4e4;color:#b3261e} .wor-ts.pending,.wor-ts.partial{background:#fff3dc;color:#9a6200}
  .wor-f{display:grid;grid-template-columns:1fr;gap:10px} .wor-f label{font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted,#6f7480);display:block;margin-bottom:4px} @media (min-width:700px){.wor-f.two{grid-template-columns:1fr 1fr}}
  .wor-hrs{display:grid;grid-template-columns:44px minmax(0,1fr) minmax(0,1fr);gap:4px 8px;align-items:center} .wor-hrs span{font-size:13.5px;font-weight:700} .wor-hrs .wor-cl{grid-column:2/4;margin:0 0 8px} .wor-hrs .wor-in{padding:8px 8px;font-size:15px;border-radius:12px} .wor-hrs label{font-size:12.5px;font-weight:600;text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:5px;margin:0;color:#3a3f4a}
  .wor-now{display:inline-block;border-radius:99px;padding:5px 12px;font-size:12.5px;font-weight:800;background:#eef0f2;color:#3a3f4a} .wor-now.open{background:#d9fdd3;color:#005c4b} .wor-now.shut{background:#fde4e4;color:#b3261e}
  .wor-say{background:#f4f5f7;border-radius:14px;padding:10px 13px;font-size:13.5px;line-height:1.5;white-space:pre-wrap;color:#3a3f4a}
  `;
  function css() { if (document.getElementById('worCss')) return; const s = document.createElement('style'); s.id = 'worCss'; s.textContent = CSS; document.head.appendChild(s); }
  function toast(t, bad) { css(); const e = document.createElement('div'); e.className = 'wor-toast' + (bad ? ' bad' : ''); e.textContent = t; document.body.appendChild(e); setTimeout(() => e.remove(), 3400); }
  function sheet(title, html, mount) {
    css(); const ov = document.createElement('div'); ov.className = 'wor-ov wor';
    ov.innerHTML = '<div class="wor-sh"><div class="wor-shh"><h3>' + esc(title) + '</h3><button class="wor-ic" data-x aria-label="Close">✕</button></div><div data-body>' + html + '</div></div>';
    const close = () => { ov.remove(); };
    ov.addEventListener('click', (e) => { if (e.target === ov || e.target.closest('[data-x]')) close(); });
    document.body.appendChild(ov); if (mount) mount(ov.querySelector('[data-body]'), close); return { el: ov, close };
  }

  // ====================================================================================================== the full order popup (same card as Dispatch's Track popup)
  let hubLd = null;
  function hubReady(cb) {
    if (window.OrderHub) return cb(true); if (hubLd) { hubLd.push(cb); return; } hubLd = [cb];
    const sc = document.createElement('script'); sc.src = 'assets/js/order_hub.js?v=6';
    sc.onload = () => { const q = hubLd || []; hubLd = null; q.forEach((f) => f(!!window.OrderHub)); }; sc.onerror = () => { const q = hubLd || []; hubLd = null; q.forEach((f) => f(false)); }; document.head.appendChild(sc);
  }
  /** Opens payment, prepaid / COD, who sold it, commission, where the parcel is, every scan, complaints and this customer's other orders. */
  function details(phone, order, store, sid) {
    hubReady((ok) => {
      if (!ok) { toast('Could not open the full details. Refresh the page.', true); return; }
      const req = { wa: true, phone: String(phone || '').replace(/\D/g, '').slice(-10) }; if (order) { req.order = order; if (store) req.store = store; if (sid) req.sid = sid; }
      OrderHub.open(req); const ov = document.querySelector('.oh-ov'); if (ov) ov.style.zIndex = '2147483001';   // above the Orders sheet
    });
  }

  // ====================================================================================================== the order card in a chat
  function orderHtml(o) {
    const bad = ['failed', 'rto', 'lost', 'damaged', 'delayed'].includes(o.status), good = o.status === 'delivered';
    let h = '<div class="wor-ord' + (bad ? ' bad' : good ? ' ok' : '') + '"><div class="wor-top"><span class="wor-nm">' + esc(o.order_name) + '</span><span class="wor-pill ' + esc(o.status) + '">' + esc(o.status_label) + '</span></div>';
    h += '<div class="wor-tx">' + esc(o.status_text) + '</div><div class="wor-mt">';
    if (o.courier) h += '<span class="wor-ch">🚚 ' + esc(o.courier) + '</span>';
    if (o.tracking_no) h += (o.track_link ? '<a class="wor-ch link" target="_blank" rel="noopener" href="' + esc(o.track_link) + '">AWB ' + esc(o.tracking_no) + ' ↗</a>' : '<span class="wor-ch" data-copy="' + esc(o.tracking_no) + '">AWB ' + esc(o.tracking_no) + '</span>');
    if (o.total != null) h += '<span class="wor-ch">' + esc(inr(o.total)) + (o.payment ? ' · ' + esc(o.payment) : '') + '</span>';
    if (o.placed_at) h += '<span class="wor-ch muted">Placed ' + esc(day(o.placed_at)) + '</span>';
    h += '</div>';
    if (o.etd_text) h += '<div class="wor-sc">📅 ' + (o.etd ? esc(o.etd_text) : '<i>' + esc(o.etd_text) + '</i>') + '</div>';
    if (o.last_scan) h += '<div class="wor-sc">Last scan: <b>' + esc(o.last_scan.label) + '</b>' + (o.last_scan.place ? ' · ' + esc(o.last_scan.place) : '') + ' · ' + esc(fmt(o.last_scan.at)) + '</div>';
    (o.update_sent || []).forEach((u) => { h += '<div class="wor-mt"><span class="wor-ch sent">' + (u.state === 'sent' ? '✔ ' : '⏳ ') + esc(u.text) + '</span></div>'; });
    if ((o.timeline || []).length > 1) h += '<details class="wor-tl"><summary>Scan history (' + o.timeline.length + ')</summary><ol>' + o.timeline.map((e) => '<li><b>' + esc(e.label || e.activity) + '</b>' + (e.place ? ' · ' + esc(e.place) : '') + '<small>' + esc(fmt(e.at)) + (e.activity && e.label ? ' · ' + esc(e.activity) : '') + '</small></li>').join('') + '</ol></details>';
    h += '<div style="margin-top:8px"><button class="wor-btn sm" data-full="' + esc(o.order_name) + '" data-store="' + esc(o.store) + '">📋 Full details</button></div>';
    if (o.match === 'linked') h += '<div class="wor-lk">🔗 Linked by hand (not under this number)<button data-unlink="' + esc(o.order_name) + '" data-store="' + esc(o.store) + '">Unlink</button></div>';
    return h + '</div>';
  }
  function card(el, o) {
    css(); o = o || {}; const S = { phone: String(o.phone || '').replace(/\D/g, '').slice(-10), d: null, dead: false };
    async function load() {
      if (S.dead) return; el.classList.add('wor'); if (!S.d) el.innerHTML = '<div class="wor-card"><div class="wor-sc">Loading orders…</div></div>';
      try { S.d = await api.get('wa.ord_for', { phone: S.phone }); } catch (e) { el.innerHTML = '<div class="wor-card"><div class="wor-err">Could not load the orders. ' + esc(errMsg(e)) + ' <button class="wor-btn sm" data-re>Try again</button></div></div>'; return; }
      draw();
    }
    function draw() {
      const d = S.d; let h = '<div class="wor-card"><div class="wor-hd"><b>Orders<small>' + (d.orders.length ? d.orders.length : '') + '</small></b><button class="wor-ic" data-re title="Refresh" aria-label="Refresh">↻</button></div>';
      if (d.open_cases) h += '<div class="wor-warn">⚠ ' + d.open_cases + ' open case' + (d.open_cases === 1 ? '' : 's') + ' in Dispatch for this customer.</div>';
      if (d.none) h += '<div class="wor-none"><b>No order found for this number</b><p>The order may have been placed from another number. Link it, or tell Dispatch.</p><button class="wor-btn" data-link>🔗 Link an order</button></div>';
      else { h += d.orders.map(orderHtml).join(''); h += '<div style="text-align:center;margin-top:8px"><button class="wor-btn sm" data-link>🔗 Link another order</button></div>'; }
      h += '<button class="wor-btn urg" data-urgent>⚠ Needs attention (Urgent)</button></div>'; el.innerHTML = h;
    }
    const onClick = (e) => {
      if (e.target.closest('[data-re]')) { S.d = null; load(); return; }
      if (e.target.closest('[data-link]')) { link({ phone: S.phone, onDone: () => { load(); if (o.onChange) o.onChange(); } }); return; }
      if (e.target.closest('[data-urgent]')) { urgent({ phone: S.phone, name: o.name, orders: S.d ? S.d.orders : [], onDone: () => { load(); if (o.onChange) o.onChange(); } }); return; }
      const fu = e.target.closest('[data-full]'); if (fu) { details(S.phone, fu.getAttribute('data-full'), fu.getAttribute('data-store')); return; }
      const c = e.target.closest('[data-copy]'); if (c) { try { navigator.clipboard.writeText(c.getAttribute('data-copy')); toast('Tracking number copied'); } catch (x) { } return; }
      const u = e.target.closest('[data-unlink]'); if (u) { if (!confirm('Remove the link to order ' + u.getAttribute('data-unlink') + ' from this chat?')) return; api.post('wa.ord_unlink', { phone: S.phone, order_name: u.getAttribute('data-unlink'), store: u.getAttribute('data-store') }).then(() => { toast('Link removed'); load(); }).catch((x) => toast(errMsg(x), true)); }
    };
    el.addEventListener('click', onClick); load();
    return { refresh: () => { S.d = null; return load(); }, destroy: () => { S.dead = true; el.removeEventListener('click', onClick); el.innerHTML = ''; } };
  }

  function open(o) {
    o = o || {}; let c = null;
    sheet('Orders · ' + (o.name || ('+91 ' + tel(String(o.phone || '').replace(/\D/g, '').slice(-10)))), '<div data-slot></div>', (el) => { c = card(el.querySelector('[data-slot]'), o); });
  }

  // ====================================================================================================== Link an order
  function link(o) {
    o = o || {}; const phone = String(o.phone || '').replace(/\D/g, '').slice(-10);
    sheet('Link an order', '<div class="wor-sc" style="margin-bottom:8px">Search by order number (like #1042) or by the customer\'s phone number. The link stays on this chat.</div><input class="wor-in" data-q placeholder="Order number or phone" inputmode="search" autocomplete="off"><div data-out style="margin-top:8px"></div>', (el, close) => {
      const q = el.querySelector('[data-q]'), out = el.querySelector('[data-out]'); let t = null, seq = 0;
      const run = async () => {
        const v = q.value.trim(); const my = ++seq; if (v.length < 3) { out.innerHTML = '<div class="wor-sc">Type at least 3 characters.</div>'; return; }
        out.innerHTML = '<div class="wor-sc">Searching…</div>';
        try {
          const r = await api.get('wa.ord_search', { phone, q: v }); if (my !== seq) return;
          out.innerHTML = (r.results || []).length ? r.results.map((x, i) => '<div class="wor-res"><div><b>' + esc(x.order_name) + '</b> <small style="display:inline">' + esc(x.store) + '</small><small>' + esc(x.customer || 'Customer') + (x.phone_hint ? ' · ' + esc(x.phone_hint) : '') + ' · ' + esc(day(x.placed_at)) + (x.total != null ? ' · ' + esc(inr(x.total)) : '') + '</small><small>' + esc(x.status_text) + '</small></div><button class="wor-btn sm pri" data-pick="' + i + '">Link</button></div>').join('') : '<div class="wor-sc">No order found. Check the number and try again.</div>';
          out.onclick = async (e) => {
            const b = e.target.closest('[data-pick]'); if (!b) return; const x = r.results[+b.getAttribute('data-pick')]; b.disabled = true;
            try { await api.post('wa.ord_link', { phone, order_name: x.order_name, shopify_id: x.shopify_id, store: x.store }); toast('Linked ' + x.order_name); close(); if (o.onDone) o.onDone(); } catch (err) { toast(errMsg(err), true); b.disabled = false; }
          };
        } catch (e) { out.innerHTML = '<div class="wor-err">' + esc(errMsg(e)) + '</div>'; }
      };
      q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 350); }); setTimeout(() => q.focus(), 60);
    });
  }

  // ====================================================================================================== Needs attention (the form)
  const DELIV = [['order_missing', 'Order missing'], ['delivery_problem', 'Delivery problem'], ['wrong_item', 'Wrong item'], ['change_cancel', 'Change / cancel before pickup'], ['other', 'Other']];
  const CMPL = [['staff', 'Rude staff'], ['quality', 'Product quality'], ['refund', 'Refund'], ['other', 'Other']];
  function urgent(o) {
    o = o || {}; const phone = String(o.phone || '').replace(/\D/g, '').slice(-10); const S = { kind: 'delivery', reason: '', order: o.order || '' };
    const ords = (o.orders || []).map((x) => x.order_name);
    sheet('Needs attention', '<div class="wor-sc" style="margin-bottom:6px">' + esc(o.name || '') + ' ' + esc(tel(phone)) + '</div><div class="wor-l">What is it?</div><div class="wor-seg" data-kind><button class="wor-c on" data-k="delivery">📦 Delivery / order problem<br><small style="font-weight:500">goes to Dispatch</small></button><button class="wor-c" data-k="complaint">🙁 Complaint<br><small style="font-weight:500">goes to Complaints</small></button></div><div class="wor-l">Reason</div><div class="wor-chips" data-reasons></div><div data-ord></div><div class="wor-l">One line for the team</div><textarea class="wor-in" data-note maxlength="300" placeholder="What happened? (one line)"></textarea><div class="wor-err" data-err style="display:none"></div><button class="wor-go" data-go>Send</button>', (el, close) => {
      const rs = el.querySelector('[data-reasons]'), od = el.querySelector('[data-ord]'), note = el.querySelector('[data-note]'), err = el.querySelector('[data-err]'), go = el.querySelector('[data-go]');
      const draw = () => {
        rs.innerHTML = (S.kind === 'delivery' ? DELIV : CMPL).map((r) => '<button class="wor-c' + (S.reason === r[0] ? ' on' + (S.kind === 'complaint' ? ' cx' : '') : '') + '" data-r="' + r[0] + '">' + esc(r[1]) + '</button>').join('');
        el.querySelectorAll('[data-k]').forEach((b) => b.classList.toggle('on', b.getAttribute('data-k') === S.kind));
        od.innerHTML = ords.length ? '<div class="wor-l">Which order?</div><select class="wor-in" data-order><option value="">Not about one order</option>' + ords.map((n) => '<option' + (n === S.order ? ' selected' : '') + '>' + esc(n) + '</option>').join('') + '</select>' : '<div class="wor-l">Order number (if you know it)</div><input class="wor-in" data-order placeholder="#1042" value="' + esc(S.order) + '">';
      };
      el.addEventListener('click', (e) => { const k = e.target.closest('[data-k]'); if (k) { S.kind = k.getAttribute('data-k'); S.reason = ''; draw(); } const r = e.target.closest('[data-r]'); if (r) { S.reason = r.getAttribute('data-r'); draw(); } });
      el.addEventListener('change', (e) => { if (e.target.matches('[data-order]')) S.order = e.target.value; }); el.addEventListener('input', (e) => { if (e.target.matches('[data-order]')) S.order = e.target.value; });
      draw();
      go.addEventListener('click', async () => {
        err.style.display = 'none'; const text = note.value.trim();
        if (!S.reason) { err.textContent = 'Choose a reason.'; err.style.display = 'block'; return; }
        if (text.length < 3) { err.textContent = 'Write one line about the problem.'; err.style.display = 'block'; return; }
        go.disabled = true; go.textContent = 'Sending…';
        try { const r = await api.post('wa.ord_urgent', { phone, kind: S.kind, reason: S.reason, note: text, order_name: S.order }); toast(r.message || 'Sent'); close(); if (o.onDone) o.onDone(r); }
        catch (x) { err.textContent = errMsg(x); err.style.display = 'block'; go.disabled = false; go.textContent = 'Send'; }
      });
    });
  }

  // ====================================================================================================== the Dispatch tab
  function count(cb) { try { api.get('wa.ord_cases', { count_only: 1 }).then((r) => cb && cb(r.counts ? r.counts.open : 0)).catch(() => { }); } catch (e) { } }
  function ageClass(c) { return c.status === 'done' ? 'done' : c.age_sec >= 86400 ? 'red' : c.age_sec >= 7200 ? 'amber' : ''; }
  function caseHtml(c, admin) {
    const chat = c.phone ? (admin ? c.chat_url : c.my_chat_url) : '';
    let h = '<div class="wor-case ' + ageClass(c) + '" data-id="' + c.id + '"><div class="wor-ct"><span class="wor-rs ' + (c.kind === 'auto' ? 'auto' : '') + '">' + esc(c.reason_label) + '</span><span class="wor-tm">' + (c.status === 'done' ? '✔ Done · ' : '⏱ ') + esc(c.age_text) + '</span></div>';
    h += '<div class="wor-who">' + esc(c.customer || (c.phone ? '+91 ' + tel(c.phone) : 'Customer')) + (c.phone ? ' · <a href="tel:+91' + esc(c.phone) + '">' + esc(tel(c.phone)) + '</a>' : '') + '</div>';
    h += '<div class="wor-meta">' + (c.order_name ? 'Order <b>' + esc(c.order_name) + '</b>' : 'No order given') + (c.parcel ? ' · ' + esc(c.parcel.courier || 'no courier yet') + (c.parcel.tracking_no ? ' · AWB ' + esc(c.parcel.tracking_no) : '') + '<br>' + esc(c.parcel.status_text) : '') + '</div>';
    if (c.note) h += '<div class="wor-note">' + esc(c.note) + '</div>';
    h += '<div class="wor-sm">Raised by ' + esc(c.raised_by || 'someone') + ' · ' + esc(fmt(c.created_at)) + ' · ' + (c.owner ? 'Owner: <b>' + esc(c.owner) + '</b>' : '<b>Nobody has it yet</b>') + (c.status === 'done' && c.done_note ? '<br>Result: ' + esc(c.done_note) : '') + '</div>';
    if ((c.notes || []).length > 1) h += '<details class="wor-hist"><summary>History (' + c.notes.length + ')</summary>' + c.notes.map((n) => '<div><b>' + esc(n.who || 'System') + '</b> · ' + esc(fmt(n.at)) + '<br>' + esc(n.text) + '</div>').join('') + '</details>';
    const hubBtn = window.OrderHub ? '<button class="wor-b" data-hub="' + c.id + '">🔎 Full details</button>' : '';   // the one big popup: order + customer + chat (order_hub.js)
    if (c.status === 'open') h += '<div class="wor-act">' + hubBtn + '<button class="wor-b pri" data-take="' + c.id + '">' + (c.owner ? 'Take over' : 'Take it') + '</button><button class="wor-b" data-note="' + c.id + '">Add note</button><button class="wor-b go" data-done="' + c.id + '">Done</button>' + (chat ? '<a class="wor-b" href="' + esc(chat) + '">Open chat</a>' : '') + '</div>';
    else if (chat) h += '<div class="wor-act">' + hubBtn + '<a class="wor-b" href="' + esc(chat) + '">Open chat</a></div>';
    return h + '</div>';
  }
  function attention(el, o) {
    css(); o = o || {}; el.classList.add('wor'); const S = { tab: 'open', d: null, t: null };
    async function load(quiet) {
      if (!quiet) el.innerHTML = '<div class="wor-pg"><div class="wor-empty">Loading…</div></div>';
      try { S.d = await api.get('wa.ord_cases', { status: S.tab, limit: 120 }); } catch (e) { el.innerHTML = '<div class="wor-pg"><div class="wor-tile"><div class="wor-err">' + esc(errMsg(e)) + '</div></div></div>'; return; }
      draw(); if (o.onCount) o.onCount(S.d.counts.open);
    }
    function draw() {
      const d = S.d, c = d.counts; let h = '<div class="wor-pg"><div class="wor-stats"><div class="wor-st"><b>' + c.open + '</b><span>Open</span></div><div class="wor-st"><b>' + c.unowned + '</b><span>Nobody has it</span></div><div class="wor-st"><b>' + c.mine + '</b><span>Mine</span></div><div class="wor-st"><b>' + c.auto + '</b><span>From the courier</span></div><div class="wor-st"><b>' + c.done_today + '</b><span>Done today</span></div></div>';
      h += '<div class="wor-tabs"><button data-tab="open" class="' + (S.tab === 'open' ? 'on' : '') + '">Open</button><button data-tab="done" class="' + (S.tab === 'done' ? 'on' : '') + '">Done</button></div>';
      h += d.cases.length ? d.cases.map((x) => caseHtml(x, d.is_admin)).join('') : '<div class="wor-tile"><div class="wor-empty"><b>' + (S.tab === 'open' ? 'Nothing needs attention' : 'Nothing finished yet') + '</b>' + (S.tab === 'open' ? 'Urgent cases from WhatsApp chats and delivery problems from the courier show up here.' : '') + '</div></div>';
      h += '<div data-settings></div></div>'; el.innerHTML = h;
      if (d.is_admin) settings(el.querySelector('[data-settings]'));
    }
    el.addEventListener('click', async (e) => {
      const hb = e.target.closest('[data-hub]'); if (hb && window.OrderHub) { OrderHub.onClose = () => load(true); OrderHub.open({ case: +hb.getAttribute('data-hub') }); return; }
      const tb = e.target.closest('[data-tab]'); if (tb) { S.tab = tb.getAttribute('data-tab'); load(); return; }
      const tk = e.target.closest('[data-take]'), dn = e.target.closest('[data-done]'), nt = e.target.closest('[data-note]');
      const act = async (action, body) => { try { await api.post(action, body); await load(true); } catch (x) { toast(errMsg(x), true); } };
      if (tk) { tk.disabled = true; act('wa.ord_case_take', { id: +tk.getAttribute('data-take') }); }
      if (nt) sheet('Add a note', '<textarea class="wor-in" data-t maxlength="500" placeholder="What did you do or find out?"></textarea><button class="wor-go" data-s>Save note</button>', (b, close) => { b.querySelector('[data-s]').onclick = async () => { const t = b.querySelector('[data-t]').value.trim(); if (t.length < 2) return toast('Write the note.', true); close(); act('wa.ord_case_note', { id: +nt.getAttribute('data-note'), note: t }); }; });
      if (dn) sheet('Finish this case', '<div class="wor-sc" style="margin-bottom:6px">What was done? (optional, one line)</div><textarea class="wor-in" data-t maxlength="300" placeholder="For example: courier re-attempted, parcel delivered"></textarea><button class="wor-go" data-s>Mark as done</button>', (b, close) => { b.querySelector('[data-s]').onclick = () => { const t = b.querySelector('[data-t]').value.trim(); close(); act('wa.ord_case_done', { id: +dn.getAttribute('data-done'), note: t }); }; });
    });
    load(); S.t = setInterval(() => { if (document.hidden || !el.isConnected) return; load(true); }, 60000);
    return { refresh: () => load(true), destroy: () => clearInterval(S.t) };
  }

  // ====================================================================================================== automatic order-update messages (Admin)
  function settings(el) {
    css(); el.classList.add('wor');
    async function load() {
      let s; try { s = await api.get('wa.ord_settings'); } catch (e) { el.innerHTML = '<div class="wor-tile"><div class="wor-err">' + esc(errMsg(e)) + '</div></div>'; return; }
      let h = '<div class="wor-tile"><h2>Order-update messages</h2><p class="wor-sub">WhatsApp messages sent by themselves when the courier status of an order changes. Each order gets each message once. Nothing goes out between 9 PM and 8 AM (it waits and goes at 8 AM), or to a customer who opted out. A message is sent only when Meta has approved its template.</p>';
      h += '<div class="wor-row"><div><b>Send order updates automatically</b><small>Master switch</small></div><label class="wor-sw"><input type="checkbox" data-master ' + (s.on ? 'checked' : '') + '><i></i></label></div>';
      CUR = s.statuses; s.statuses.forEach((x) => { h += '<div class="wor-row" style="align-items:flex-start"><div style="flex:1;min-width:0"><b>' + esc(x.label) + '</b>' + (x.when ? '<small style="display:block;color:#667781">Sent ' + esc(x.when) + '</small>' : '') + (x.body ? '<div style="background:#d9fdd3;border-radius:10px;padding:8px 10px;margin:6px 0;font-size:13px;line-height:1.45;white-space:pre-wrap">' + esc(x.body).replace(/\{\{(\d)\}\}/g, '<b style="color:#5b21b6">{{$1}}</b>') + '</div>' : '') + '<small><span class="wor-ts ' + esc(x.template_status) + '">' + esc(x.template_status === 'approved' ? 'Approved by Meta' : x.template_status === 'missing' ? 'Not created yet' : x.template_status.charAt(0).toUpperCase() + x.template_status.slice(1)) + '</span> · today ' + x.sent_today + ' · this month ' + x.sent_month + (x.queued ? ' · waiting ' + x.queued : '') + (x.template_reason ? '<br>' + esc(x.template_reason) : '') + '</small><div style="margin-top:6px"><button class="wor-b" style="min-height:32px;padding:4px 14px;font-size:12px" data-edit="' + esc(x.key) + '">✏️ Edit</button>' + badge(x) + '</div></div><label class="wor-sw"><input type="checkbox" data-st="' + esc(x.key) + '" ' + (x.on ? 'checked' : '') + '><i></i></label></div>'; });
      h += '<p class="wor-sub" style="margin-top:10px"><b>' + s.sent_today + '</b> sent today · <b>' + s.sent_month + '</b> this month. ' + esc(s.cost_text) + '</p>';
      h += '<button class="wor-b" data-seed>' + (s.statuses.every((x) => x.template_status !== 'missing') ? 'Check the templates at Meta' : 'Create the missing messages and send them to Meta') + '</button> <span class="wor-sub" data-sm></span></div>'; el.innerHTML = h;
    }
    let CUR = [];
    function badge(x) {
      const p = x.edit && x.edit.pending; if (!p) return '';
      const m = { approved: ['🟢', 'New version approved'], partial: ['🟢', 'New version approved'], rejected: ['🔴', 'Meta rejected the new version'] }[p.status] || ['🟡', 'New version waiting for Meta'];
      return ' <button class="wor-b" style="min-height:28px;padding:2px 10px;font-size:12px" data-edit="' + esc(x.key) + '">' + m[0] + ' ' + m[1] + '</button>';
    }
    // ✨ the happy versions of the order messages (same {{…}} as the original: name, order, courier, tracking)
    const HAPPY = {
      received: "Hello {{1}} 🎉 Thank you for shopping with TM Perfume House! Your order {{2}} is confirmed 💐 We are packing your perfumes with love and will send your tracking details as soon as it ships ✨",
      shipped: "Yay {{1}}! 🚚✨ Your order {{2}} is on its way with {{3}}. Your tracking number is {{4}} — follow your parcel anytime 📦💖",
      out_for_delivery: "Hello {{1}} 🛵💨 Your order {{2}} is out for delivery today with {{3}}! Please keep your phone close so our delivery partner can reach you 📞😊",
      delivered: "Hello {{1}} 🥳 Your order {{2}} has been delivered! We hope you love your new fragrance 💖 How do you like it? Just reply here, and if anything is not right, we are here to help 🙏",
      failed: "Hello {{1}} 🤔 We tried to deliver your order {{2}} today with {{3}}, but could not reach you. No worries 😊 They will try again. Tap a button below if you would like a call or need to change the address 📞",
      delayed: "Hello {{1}} 😅 Your order {{2}} with {{3}} is taking a little longer than planned, and we are really sorry for the wait 🙏 We are following up with the courier and will keep you posted. Tap below if you need help."
    };
    function photoOf(lib, title) { return lib ? '📷 ' + esc(title || 'photo') : 'no photo'; }
    function openEdit(key) {
      const x = CUR.find((i) => i.key === key); if (!x) return; const e = x.edit || {}, live = e.live || {}, pd = e.pending;
      let lib = pd ? pd.lib : live.lib, libTitle = pd ? pd.photo : live.photo, h = '';
      h += '<p class="wor-sub">Change the words or the photo. A new version is sent to Meta. <b>The message that is live now keeps going out until Meta approves the new one</b>, then the new one takes over by itself.</p>';
      if (pd) {
        const st = pd.status, txt = st === 'rejected' ? '🔴 Meta rejected it' : (st === 'approved' || st === 'partial') ? '🟢 Approved' : '🟡 Waiting for Meta';
        h += '<div style="background:#fff8e6;border-radius:10px;padding:8px 10px;margin:8px 0;font-size:13px"><b>' + txt + '</b> · sent ' + esc(pd.at || '') + (pd.reason ? '<br>Meta says: ' + esc(pd.reason) : '') + '<br><span style="color:#667781">' + esc(pd.body) + '</span><br><small>' + photoOf(pd.lib, pd.photo) + '</small> <button class="wor-b" data-cancel style="min-height:28px;padding:2px 10px;font-size:12px">Cancel the new version</button></div>';
      }
      h += '<div style="margin:8px 0"><b style="font-size:13px">Live now</b>' + (live.edited ? ' <small>(edited version, since ' + esc(live.since || '') + ')</small>' : ' <small>(the original)</small>') + '<div style="background:#d9fdd3;border-radius:10px;padding:8px 10px;margin-top:4px;font-size:13px;white-space:pre-wrap">' + esc(live.body || x.body) + '</div><small>' + photoOf(live.lib, live.photo) + '</small></div>';
      h += '<b style="font-size:13px">' + (pd ? 'Edit again' : 'New version') + '</b><textarea data-ebody rows="5" style="width:100%;box-sizing:border-box;border:1px solid #cfd4d9;border-radius:10px;padding:8px;font:inherit;font-size:13px;margin:4px 0"></textarea>'
        + '<small style="color:#667781">Keep the {{1}} {{2}}… as they are: they are filled in with the customer\'s name, order number, courier and tracking. Not at the very start or end of the text.</small>'
        + '<div style="margin:10px 0"><b style="font-size:13px">Photo on top</b> <span data-ephoto></span> <button class="wor-b" data-epick style="min-height:32px;padding:4px 12px;font-size:12px">Choose / upload</button> <button class="wor-b" data-enone style="min-height:32px;padding:4px 12px;font-size:12px">No photo</button><div data-epp></div></div>'
        + '<div><button class="wor-b pri" data-esave>Save and send to Meta</button> <small data-emsg></small></div>';
      if (live.edited) h += '<div style="margin-top:10px"><button class="wor-b" data-revert style="min-height:32px;font-size:12px">Go back to the original message</button></div>';
      if (e.history && e.history.length) h += '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:13px">Older versions (' + e.history.length + ')</summary>' + e.history.map((i) => '<div style="font-size:12px;color:#667781;margin:6px 0;white-space:pre-wrap">' + esc(i.body) + '<br>' + photoOf(!!i.photo, i.photo) + ' · until ' + esc(i.until) + '</div>').join('') + '</details>';
      sheet(x.label, h, (box, close) => {
        const ta = box.querySelector('[data-ebody]'), ph = box.querySelector('[data-ephoto]'), msg = box.querySelector('[data-emsg]'), pp = box.querySelector('[data-epp]');
        ta.value = pd ? pd.body : (live.edited ? live.body : (HAPPY[key] || live.body || x.body));   // ✨ the happy version is ready in the box; remove emojis if you like const drawPh = () => { ph.innerHTML = photoOf(lib, libTitle); }; drawPh();
        const done = async (fn, ok) => { try { const r = await fn(); toast(r.message || ok); close(); await load(); } catch (err) { msg.textContent = ''; toast(errMsg(err), true); } };
        box.addEventListener('click', async (ev) => {
          if (ev.target.closest('[data-enone]')) { lib = 0; libTitle = ''; drawPh(); return; }
          if (ev.target.closest('[data-epick]')) {
            pp.innerHTML = '<small>Loading…</small>'; let items = [];
            try { items = ((await api.get('wa.lib_list')).items || []).filter((i) => i.kind === 'image'); } catch (err) { pp.innerHTML = '<div class="wor-err">' + esc(errMsg(err)) + '</div>'; return; }
            pp.innerHTML = '<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">' + (items.length ? '<select data-esel style="min-height:40px;border-radius:10px;border:1px solid #cfd4d9;padding:0 8px;max-width:100%">' + items.map((i) => '<option value="' + i.id + '" data-t="' + esc(i.title) + '"' + (i.id === lib ? ' selected' : '') + '>' + esc(i.title) + '</option>').join('') + '</select> <button class="wor-b" data-euse>Use this photo</button>' : '<small>No photos in the media library yet.</small>') + '<label class="wor-b" style="cursor:pointer">Upload a new photo<input type="file" accept="image/jpeg,image/png" data-eup hidden></label></div>';
            return;
          }
          if (ev.target.closest('[data-euse]')) { const o = pp.querySelector('[data-esel]').selectedOptions[0]; lib = parseInt(o.value, 10) || 0; libTitle = o.getAttribute('data-t') || ''; drawPh(); pp.innerHTML = ''; return; }
          if (ev.target.closest('[data-esave]')) { msg.textContent = 'Sending…'; ev.target.closest('[data-esave]').disabled = true; done(() => api.post('wa.ord_edit', { status: key, body: ta.value, lib_id: lib }), 'Saved'); return; }
          if (ev.target.closest('[data-cancel]')) { if (confirm('Cancel the new version? The live message is not affected.')) done(() => api.post('wa.ord_edit_cancel', { status: key }), 'Cancelled'); return; }
          if (ev.target.closest('[data-revert]')) { if (confirm('Go back to the original message?')) done(() => api.post('wa.ord_edit_revert', { status: key }), 'Done'); }
        });
        box.addEventListener('change', async (ev) => {
          const up = ev.target.closest('[data-eup]'); if (!up || !up.files || !up.files[0]) return; const f = up.files[0]; pp.insertAdjacentHTML('beforeend', '<small> Uploading…</small>');
          try { const U = window.WaPanels && WaPanels.util && WaPanels.util.upload; if (!U) throw new Error('Upload is not available on this page.'); const fd = new FormData(); fd.append('file', f); fd.append('title', f.name.replace(/\.[^.]+$/, '').slice(0, 80)); const r = await U('wa.lib_add', fd); lib = r.id; libTitle = f.name; drawPh(); pp.innerHTML = '<small>Saved in the media library ✓</small>'; } catch (err) { toast(errMsg(err), true); }
        });
      });
    }
    el.addEventListener('change', async (e) => {
      const m = e.target.closest('[data-master]'), st = e.target.closest('[data-st]');
      try { if (m) await api.post('wa.ord_settings', { on: m.checked }); else if (st) { const b = { statuses: {} }; b.statuses[st.getAttribute('data-st')] = st.checked; await api.post('wa.ord_settings', b); } if (m || st) toast('Saved'); } catch (x) { toast(errMsg(x), true); load(); }
    });
    el.addEventListener('click', async (e) => {
      const ed = e.target.closest('[data-edit]'); if (ed) { openEdit(ed.getAttribute('data-edit')); return; }
      const b = e.target.closest('[data-seed]'); if (!b) return; b.disabled = true; const sm = el.querySelector('[data-sm]'); if (sm) sm.textContent = 'Working…';
      try { const r = await api.post('wa.ord_seed', { retry: 1 }); toast(r.ok ? 'Done: ' + (r.created.length + r.submitted.length) + ' template(s) sent to Meta' : 'Some templates could not be sent', !r.ok); await load(); } catch (x) { toast(errMsg(x), true); b.disabled = false; if (sm) sm.textContent = ''; }
    });
    load();
    return { refresh: load };
  }

  // ====================================================================================================== Store info (Learning)
  const DAYS = [['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'], ['fri', 'Friday'], ['sat', 'Saturday'], ['sun', 'Sunday']];
  function storeForm(el) {
    css(); el.classList.add('wor'); const S = { d: null };
    async function load() {
      try { S.d = await api.get('wa.store_get'); } catch (e) { el.innerHTML = '<div class="wor-tile"><div class="wor-err">' + esc(errMsg(e)) + '</div></div>'; return; } draw();
    }
    function say(d) {
      const i = d.info, l = [];
      l.push('Timings: ' + (d.text.hours || 'a team member will confirm.')); l.push('Address: ' + (i.address || 'a team member will confirm.')); l.push('Map: ' + (i.map_link || 'a team member will confirm.')); l.push('Delivery: ' + (d.text.delivery || 'a team member will confirm.'));
      return l.join('\n');
    }
    function draw() {
      const d = S.d, i = d.info, ro = d.can_edit ? '' : ' disabled'; const f = (k, label, ph, extra) => '<div><label>' + label + '</label><input class="wor-in" data-f="' + k + '" value="' + esc(i[k]) + '" placeholder="' + esc(ph || '') + '"' + ro + (extra || '') + '></div>';
      let h = '<div class="wor-pg"><div class="wor-tile"><h2>Store info</h2><p class="wor-sub">The bot and the pre-made messages read this. If something is empty the bot says a team member will confirm it, it never guesses.' + (d.can_edit ? '' : ' Only Admin and HR can change it.') + '</p>';
      h += d.open_now ? '<span class="wor-now ' + (d.open_now.open ? 'open' : 'shut') + '">' + (d.open_now.open ? '● ' : '○ ') + esc(d.open_now.text) + '</span>' : '<span class="wor-now">Add the timings to see if the shop is open now</span>';
      h += '<div class="wor-f two" style="margin-top:12px">' + f('name', 'Store name', 'TM Perfume House') + f('phone', 'Phone', '+91 …') + '</div><div class="wor-f" style="margin-top:10px">' + f('address', 'Address', 'Shop number, street, city') + f('map_link', 'Google Maps link', 'https://maps.google.com/…', ' inputmode="url"') + '</div></div>';
      h += '<div class="wor-tile"><h2>Timings</h2><p class="wor-sub">Opening and closing time for each day. Tick "Closed" for a day off.</p><div class="wor-hrs">';
      DAYS.forEach((x) => { const t = i.hours[x[0]]; h += '<span>' + x[1].slice(0, 3) + '</span><input class="wor-in" type="time" data-h="' + x[0] + '.open" value="' + esc(t.open) + '"' + ro + '><input class="wor-in" type="time" data-h="' + x[0] + '.close" value="' + esc(t.close) + '"' + ro + '><label class="wor-cl"><input type="checkbox" data-h="' + x[0] + '.closed" ' + (t.closed ? 'checked' : '') + ro + '> Closed</label>'; });
      h += '</div><div class="wor-f" style="margin-top:10px">' + f('holiday_note', 'Sunday / holiday note', 'Closed on Diwali and national holidays') + '</div></div>';
      h += '<div class="wor-tile"><h2>Delivery times</h2><p class="wor-sub">What the bot may tell a customer who asks "kab aayega".</p><div class="wor-f two">' + f('delivery_surat', 'Surat', '1-2 days') + f('delivery_gujarat', 'Gujarat', '2-3 days') + f('delivery_india', 'Rest of India', '4-7 days') + '<div><label>Courier estimate</label><label style="text-transform:none;letter-spacing:0;font-size:14px;font-weight:600;display:flex;gap:8px;align-items:center;color:#14161a"><input type="checkbox" data-f="delivery_use_shiprocket" ' + (i.delivery_use_shiprocket ? 'checked' : '') + ro + '> Use the courier\'s date once shipped</label></div></div><div style="margin-top:10px">' + f('pickup_info', 'Pick-up from the shop', 'Show the order number at the counter') + '</div></div>';
      h += '<div class="wor-tile"><h2>What the bot will say</h2><div class="wor-say">' + esc(say(d)) + '</div></div>';
      if (d.can_edit) h += '<button class="wor-b pri" data-save style="margin-bottom:24px">Save store info</button> <span class="wor-sub" data-msg></span>';
      el.innerHTML = h + '</div>';
    }
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-save]'); if (!b) return; const info = { hours: {} };
      el.querySelectorAll('[data-f]').forEach((x) => { info[x.getAttribute('data-f')] = x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; });
      DAYS.forEach((x) => { info.hours[x[0]] = { open: el.querySelector('[data-h="' + x[0] + '.open"]').value, close: el.querySelector('[data-h="' + x[0] + '.close"]').value, closed: el.querySelector('[data-h="' + x[0] + '.closed"]').checked ? 1 : 0 }; });
      b.disabled = true; try { await api.post('wa.store_save', { info }); toast('Store info saved'); await load(); } catch (x) { toast(errMsg(x), true); b.disabled = false; }
    });
    load(); return { refresh: load };
  }

  return { card, open, urgent, link, attention, count, storeForm, settings, _fmt: fmt };
})();
// 📦 the WhatsApp section's "Order updates" page (Automation → Order updates)
if (window.WaPanels && WaPanels.add) WaPanels.add('orderupd', (el) => { const s = WaOrd.settings(el); return () => s.refresh(); });

