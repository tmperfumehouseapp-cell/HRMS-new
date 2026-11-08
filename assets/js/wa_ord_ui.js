/* wa_ord_ui.js — live orders in the WhatsApp chat, "Needs attention" for Dispatch, order-update settings and the Store info form.
 * Shared by the manager chat screen (wa_inbox.js), the salesperson phone app (wa_app.js), the Dispatch page and Learning. Needs the global `api`.
 * Talks to wa_orders.php (wa.ord_*, wa.store_*). Nothing here sends a customer message; a person always taps a button.
 *
 *   WaOrd.card(container, { phone, name, onChange })     the orders of this chat's customer (live status, scans, "Link an order", "Needs attention").  -> { refresh(), destroy() }
 *   WaOrd.badge(el, { phone, name, light, onChange })   the little "📦 3 orders · ₹4,200" pill under the phone number; tap = the hero card (open)
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
  .wor-bdrow{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap} .wor .wor-pillb.wor-bdb,.wx .wor-pillb.wor-bdb,.wi .wor-pillb.wor-bdb{padding:3px 8px !important;font-size:12px !important;background:rgba(255,255,255,.3) !important} .wor .wor-pillb.wor-bdb.lt,.wi .wor-pillb.wor-bdb.lt{background:#fff2d6 !important;color:#8a5a00 !important} .wor-pillb.wor-bdb.set{background:#fff2d6 !important;color:#8a5a00 !important}
  .wor-bd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff8ec;border:1px solid #f3dfb7;border-radius:12px;padding:8px 10px} .wor-bd b{font-size:13.5px} .wor-bd span{font-weight:800;color:#8a5a00} .wor-bd select{border:1px solid #e3c98c;border-radius:8px;padding:5px 6px;font:inherit;font-size:13px;background:#fff;max-width:110px} .wor-bd button{border:1.5px solid #008069;background:#fff;color:#008069;border-radius:999px;padding:4px 12px;font:inherit;font-size:12.5px;font-weight:800;cursor:pointer} .wor-bd button.pri{background:#008069;color:#fff}
  .wor-pg{display:grid;grid-template-columns:repeat(5,1fr);margin:12px 0 4px;position:relative} .wor-pg>div{text-align:center;position:relative;padding:0 2px}
  .wor-pg>div:before{content:"";position:absolute;top:10px;left:-50%;width:100%;height:3px;background:#e3e7ea;z-index:0} .wor-pg>div:first-child:before{display:none}
  .wor-pg>div.done:before,.wor-pg>div.now:before{background:#00a884} .wor-pg>div.bad:before{background:#00a884}
  .wor-pg i{position:relative;z-index:1;width:22px;height:22px;border-radius:50%;background:#fff;border:3px solid #d5dade;display:grid;place-items:center;margin:0 auto;font-style:normal;font-size:11px;font-weight:900;color:#fff}
  .wor-pg .done i{background:#00a884;border-color:#00a884} .wor-pg .now i{border-color:#00a884;background:#fff;box-shadow:0 0 0 4px rgba(0,168,132,.18)} .wor-pg .now i:after{content:"";width:8px;height:8px;border-radius:50%;background:#00a884} .wor-pg .bad i{border-color:#e5484d;background:#e5484d;box-shadow:0 0 0 4px rgba(229,72,77,.18)}
  .wor-pg b{display:block;font-size:10.5px;font-weight:800;margin-top:5px;color:#8696a0;line-height:1.15} .wor-pg .done b,.wor-pg .now b{color:#111b21} .wor-pg .bad b{color:#b3261e}
  .wor-pg small{display:block;font-size:9.5px;color:#667781;line-height:1.2;margin-top:2px;min-height:22px}
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

  .wor .wor-pillb,.wx .wor-pillb,.wi .wor-pillb{display:inline-flex !important;align-items:center;gap:6px;width:auto !important;min-width:0 !important;min-height:0 !important;max-width:100%;border:0 !important;border-radius:999px !important;padding:3px 10px 3px 8px !important;margin:3px 0 0 !important;font:inherit;font-size:12px !important;font-weight:800 !important;line-height:1.3 !important;cursor:pointer;background:rgba(255,255,255,.22) !important;color:#fff !important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-shadow:none !important}
  .wor .wor-pillb.lt,.wi .wor-pillb.lt{background:#e7fbf3 !important;color:#007a62 !important} .wor-pillb .dot{width:8px;height:8px;border-radius:50%;background:#c3cbd1;flex:none} .wor-pillb .dot.on{background:#25d366;box-shadow:0 0 0 3px rgba(37,211,102,.35)} .wor-pillb .dot.bad{background:#ff5a4f;box-shadow:0 0 0 3px rgba(255,90,79,.35)} .wor-pillb .dot.new{background:#f5b400}
  .wor-hero{border-radius:20px;padding:16px 16px 14px;color:#fff;background:linear-gradient(135deg,#0b6e5f 0%,#00a884 55%,#25d366 120%);box-shadow:0 8px 22px rgba(0,128,105,.28);position:relative;overflow:hidden}
  .wor-hero:after{content:"";position:absolute;right:-40px;top:-40px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.09)}
  .wor-hero .who{display:flex;align-items:center;gap:10px;position:relative;z-index:1} .wor-hero .av{width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.22);display:grid;place-items:center;font-weight:800;font-size:16px;flex:none}
  .wor-hero .who b{display:block;font-size:16px;font-weight:800;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap} .wor-hero .who small{opacity:.85;font-size:12.5px}
  .wor-hero .big{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;margin-top:14px;position:relative;z-index:1} .wor-hero .big div{font-size:30px;font-weight:900;letter-spacing:-.5px;line-height:1} .wor-hero .big div small{display:block;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.85;margin-bottom:5px}
  .wor-hero .tag{border-radius:999px;background:rgba(255,255,255,.2);padding:4px 11px;font-size:12px;font-weight:800;white-space:nowrap}
  .wor-kp{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px;position:relative;z-index:1} .wor-kp div{background:rgba(255,255,255,.16);border-radius:13px;padding:8px 6px;text-align:center} .wor-kp b{display:block;font-size:17px;font-weight:900} .wor-kp small{font-size:10.5px;font-weight:700;opacity:.88;letter-spacing:.03em}
  .wor-mix{display:flex;height:8px;border-radius:99px;overflow:hidden;margin-top:12px;background:rgba(255,255,255,.2);position:relative;z-index:1} .wor-mix i{display:block;height:100%} .wor-mix .pp{background:#fff} .wor-mix .cd{background:#ffd166}
  .wor-mixl{display:flex;justify-content:space-between;font-size:11.5px;font-weight:700;margin-top:5px;opacity:.95;position:relative;z-index:1}
  .wor-alert{margin-top:10px;border-radius:12px;background:rgba(255,255,255,.95);color:#b3261e;font-size:12.5px;font-weight:800;padding:7px 11px;position:relative;z-index:1}
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


  // ====================================================================================================== the little order pill under the phone number + the hero card
  // ====================================================================================================== 🎂 the birthday box in the customer panel
  /** el = a div in the customer panel. Shows the saved birthday (day + month) or two small boxes to save it. opts: { phone, name } */
  function birthday(el, opts) {
    css(); opts = opts || {}; if (!el) return; el.classList.add('wor'); const phone = String(opts.phone || '').replace(/\D/g, '').slice(-10);
    const MN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const nice = (md) => { const m = /^(\d\d)-(\d\d)$/.exec(md || ''); return m ? (+m[2]) + ' ' + MN[+m[1] - 1] : ''; };
    let md = '', edit = false;
    const draw = () => {
      if (md && !edit) { el.innerHTML = '<div class="wor-bd"><b>🎂 Birthday</b><span>' + esc(nice(md)) + '</span><button type="button" data-bdedit>Change</button></div>'; return; }
      const sel = (id, a, b, lab, f) => '<select data-' + id + '><option value="">' + lab + '</option>' + Array.from({ length: b - a + 1 }, (_, i) => a + i).map((n) => '<option value="' + n + '">' + (f ? f(n) : n) + '</option>').join('') + '</select>';
      el.innerHTML = '<div class="wor-bd"><b>🎂 Birthday</b>' + sel('bdd', 1, 31, 'Day') + sel('bdm', 1, 12, 'Month', (n) => MN[n - 1]) + '<button type="button" class="pri" data-bdsave>Save</button></div><div class="wor-sc" style="margin-top:4px">Ask the customer once. They get a FREE 50 ml gift on their birthday.</div>';
      if (md) { const m = /^(\d\d)-(\d\d)$/.exec(md); el.querySelector('[data-bdd]').value = +m[2]; el.querySelector('[data-bdm]').value = +m[1]; }
    };
    el.onclick = async (e) => {
      if (e.target.closest('[data-bdedit]')) { edit = true; draw(); return; }
      const sv = e.target.closest('[data-bdsave]'); if (!sv) return; const d = +el.querySelector('[data-bdd]').value, m = +el.querySelector('[data-bdm]').value; if (!d || !m) { toast('Choose the day and the month.', true); return; }
      sv.disabled = true; try { const r = await api.post('wa.bday_set', { phone, name: opts.name || '', day: d, month: m }); md = r.bday; edit = false; toast('Birthday saved ✓'); draw(); if (opts.onSaved) opts.onSaved(md); } catch (x) { toast(errMsg(x), true); sv.disabled = false; }
    };
    el.innerHTML = '<div class="wor-bd"><span class="wor-sc">Reading the birthday…</span></div>';
    api.get('wa.bday_get', { phone }).then((r) => { md = r.md || ''; draw(); }).catch(() => { md = ''; draw(); });
  }
  const initials = (n) => { const w = String(n || '').replace(/[^\p{L}\p{N} ]/gu, '').trim().split(/\s+/).filter(Boolean); return ((w[0] || '?')[0] + (w[1] ? w[1][0] : '')).toUpperCase(); };
  function pillText(m) {
    if (!m.n && !m.open_cases) return { t: 'Orders & tracking · none yet', dot: 'new' };
    const dot = (m.problem || m.open_cases) ? 'bad' : m.active ? 'on' : '';
    return { t: 'Orders & tracking · ' + m.n, dot };
  }
  /** Draws the pill into `el` (under the phone number). Tapping it opens the hero card. Never throws. opts: { phone, name, light, onChange } */
  function badge(el, opts) {
    css(); opts = opts || {}; if (!el) return { destroy() { } };
    const phone = String(opts.phone || '').replace(/\D/g, '').slice(-10); let dead = false;
    const MN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const draw = (m) => {
      if (dead) return; const t = pillText(m);
      // 🎂 a very small birthday button, only once the customer has placed an order: tap, choose day + month, Save
      const hasOrder = (m.n || 0) + (m.cancelled || 0) > 0, bm = /^(\d\d)-(\d\d)$/.exec(m.bday || '');
      const bd = hasOrder ? '<button type="button" class="wor-pillb wor-bdb' + (opts.light ? ' lt' : '') + (bm ? ' set' : '') + '" data-bd title="' + (bm ? 'Birthday saved — tap to change' : 'Ask the customer for their birthday (free 50 ml gift)') + '">🎂' + (bm ? ' ' + (+bm[2]) + ' ' + MN[+bm[1] - 1] : '') + '</button>' : '';
      el.innerHTML = '<span class="wor-bdrow"><button type="button" class="wor-pillb' + (opts.light ? ' lt' : '') + '" data-ord title="This customer\'s orders — tap for the full card"><span class="dot ' + t.dot + '"></span>' + esc(t.t) + '</button>' + bd + '</span>';
      el.querySelector('[data-ord]').onclick = (e) => { e.stopPropagation(); open({ phone, name: opts.name, onChange: opts.onChange }); };
      const bb = el.querySelector('[data-bd]'); if (bb) bb.onclick = (e) => { e.stopPropagation(); sheet('🎂 Birthday', '<div data-slot></div>', (box, close) => { birthday(box.querySelector('[data-slot]'), { phone, name: opts.name, onSaved: (md) => { m.bday = md; close(); draw(m); } }); }); };
    };
    el.classList.add('wor'); el.innerHTML = '';
    api.get('wa.ord_sum', { phone }).then(draw).catch(() => { if (!dead) el.innerHTML = ''; });
    return { destroy() { dead = true; el.innerHTML = ''; } };
  }
  function heroHtml(m, o) {
    const nm = (o.name || '').trim(), ph = tel(String(o.phone || '').replace(/\D/g, '').slice(-10));
    const pp = m.prepaid, cd = m.cod, sum = pp + cd || 1;
    let h = '<div class="wor-hero"><div class="who"><div class="av">' + esc(initials(nm || ph)) + '</div><div style="min-width:0"><b>' + esc(nm || ('+91 ' + ph)) + '</b><small>+91 ' + esc(ph) + (m.first_at ? ' · customer since ' + esc(day(m.first_at)) : '') + '</small></div></div>';
    h += '<div class="big"><div><small>Total ordered</small>' + esc(inr(m.total || 0)) + '</div><span class="tag">' + m.n + (m.n === 1 ? ' order' : ' orders') + (m.more ? '+' : '') + '</span></div>';
    h += '<div class="wor-kp"><div><b>' + m.delivered + '</b><small>Delivered</small></div><div><b>' + m.active + '</b><small>On the way</small></div><div><b>' + m.problem + '</b><small>Problem</small></div><div><b>' + esc(inr(m.aov || 0)) + '</b><small>Average</small></div></div>';
    if (pp + cd) h += '<div class="wor-mix"><i class="pp" style="width:' + Math.round(pp * 100 / sum) + '%"></i><i class="cd" style="width:' + Math.round(cd * 100 / sum) + '%"></i></div><div class="wor-mixl"><span>⚪ Prepaid ' + pp + '</span><span>🟡 Pay on delivery ' + cd + '</span></div>';
    if (m.open_cases) h += '<div class="wor-alert">⚠ ' + m.open_cases + ' open case' + (m.open_cases === 1 ? '' : 's') + ' with Dispatch for this customer</div>';
    return h + '</div>';
  }


  // ====================================================================================================== the card for the CUSTOMER (a picture, sent with one tap)
  // Only what wa.ord_share returns is drawn: status, progress with times, courier, tracking number, expected date, amount / how to pay.
  // Never commission, salesperson, payment ids, complaints, other orders or the customer's address.
  const shortWhen = (t) => { const f = fmt(t); const i = f.indexOf(', '); return i < 0 ? [f, ''] : [f.slice(0, i), f.slice(i + 2)]; };
  function wrapText(ctx, text, maxW) { const out = []; let line = ''; String(text || '').split(/\s+/).forEach((w) => { const t = line ? line + ' ' + w : w; if (ctx.measureText(t).width > maxW && line) { out.push(line); line = w; } else line = t; }); if (line) out.push(line); return out; }
  function rr(ctx, x, y, w, h, r) { ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); }
  function drawCustomerCard(c) {
    const W = 1080, F = '"Inter","Segoe UI",system-ui,-apple-system,"Noto Sans",Arial,sans-serif', L = 40, CW = W - 80;
    const bad = ['failed', 'rto', 'lost', 'damaged', 'delayed'].includes(c.status), cancelled = c.status === 'cancelled', at = STAGE[c.status] != null ? STAGE[c.status] : 0;
    const accent = cancelled ? '#8696a0' : bad ? '#e8890c' : '#00a884';
    const rows = []; if (c.courier) rows.push(['Courier', c.courier]); if (c.tracking_no) rows.push(['Tracking ID', c.tracking_no]);
    if (c.status === 'delivered' && c.delivered_at) rows.push(['Delivered on', fmt(c.delivered_at)]); else if (!cancelled && c.etd) rows.push(['Expected by', day(c.etd) + '  (courier date)']);
    if (c.last_place && c.status !== 'delivered' && !cancelled) rows.push(['Last seen at', c.last_place]);
    if (c.amount != null) rows.push(['Order value', inr(c.amount)]); if (c.pay) rows.push(['Payment', c.pay]);
    const m = document.createElement('canvas').getContext('2d'); m.font = '500 31px ' + F; const lines = wrapText(m, c.text, CW - 112);
    const top = 40, hdr = 190, headY = top + hdr + 62;                                   // body starts under the header
    const pgY = headY + 62 + lines.length * 46 + 36, pgH = cancelled ? 0 : 226;         // progress block
    const rowsY = pgY + pgH + 10, rowsH = rows.length ? rows.length * 76 + 36 : 0, footY = rowsY + rowsH + 24, H = footY + 140 + 40;
    const cv = document.createElement('canvas'); cv.width = W; cv.height = H; const x = cv.getContext('2d');
    x.fillStyle = '#e6efec'; x.fillRect(0, 0, W, H);
    // white card
    x.save(); x.shadowColor = 'rgba(0,0,0,.10)'; x.shadowBlur = 30; x.shadowOffsetY = 8; x.fillStyle = '#fff'; rr(x, L, top, CW, H - top - 40, 44); x.fill(); x.restore();
    // header band
    x.save(); rr(x, L, top, CW, H - top - 40, 44); x.clip(); const g = x.createLinearGradient(L, top, L + CW, top + hdr); g.addColorStop(0, '#0b6e5f'); g.addColorStop(.6, '#00a884'); g.addColorStop(1, '#25d366'); x.fillStyle = g; x.fillRect(L, top, CW, hdr);
    x.fillStyle = 'rgba(255,255,255,.1)'; x.beginPath(); x.arc(L + CW - 30, top - 20, 160, 0, 7); x.fill(); x.restore();
    x.fillStyle = '#fff'; x.font = '800 48px ' + F; x.fillText(c.brand, L + 52, top + 84); x.font = '600 31px ' + F; x.globalAlpha = .92; x.fillText('Order update  ·  ' + c.order_name, L + 52, top + 138); x.globalAlpha = 1;
    // headline + text
    x.fillStyle = bad ? '#b45f06' : cancelled ? '#54656f' : '#0b3d35'; x.font = '800 56px ' + F; x.fillText(c.headline, L + 56, headY + 30);
    x.fillStyle = '#54656f'; x.font = '500 31px ' + F; lines.forEach((ln, i) => x.fillText(ln, L + 56, headY + 30 + 56 + i * 46));
    // progress line
    if (!cancelled) {
      const cols = 5, x0 = L + 56 + 60, x1 = L + CW - 56 - 60, step = (x1 - x0) / (cols - 1), cy = pgY + 40, r = 28; const when = [c.placed_at, c.tracked_at, c.transit_at, c.ofd_at, c.delivered_at];
      for (let i = 0; i < cols - 1; i++) { x.strokeStyle = i < at ? accent : '#dfe5e8'; x.lineWidth = 9; x.lineCap = 'round'; x.beginPath(); x.moveTo(x0 + i * step, cy); x.lineTo(x0 + (i + 1) * step, cy); x.stroke(); }
      STEPS.forEach((t, i) => {
        const cx = x0 + i * step, done = i < at || (i === at && c.status === 'delivered'), now = i === at && !done;
        if (now) { x.fillStyle = accent + '33'; x.beginPath(); x.arc(cx, cy, r + 12, 0, 7); x.fill(); }
        x.fillStyle = done ? accent : '#fff'; x.strokeStyle = done || now ? accent : '#d5dade'; x.lineWidth = 7; x.beginPath(); x.arc(cx, cy, r, 0, 7); x.fill(); x.stroke();
        if (done) { x.strokeStyle = '#fff'; x.lineWidth = 8; x.lineCap = 'round'; x.lineJoin = 'round'; x.beginPath(); x.moveTo(cx - 12, cy + 1); x.lineTo(cx - 3, cy + 11); x.lineTo(cx + 14, cy - 10); x.stroke(); } else if (now) { x.fillStyle = accent; x.beginPath(); x.arc(cx, cy, 11, 0, 7); x.fill(); }
        x.textAlign = 'center'; x.fillStyle = (done || now) ? '#111b21' : '#8696a0'; x.font = '800 25px ' + F; const lab = t === 'Out for delivery' ? ['Out for', 'delivery'] : [t]; lab.forEach((w, k) => x.fillText(w, cx, cy + 68 + k * 29));
        const w = when[i] && i <= at ? shortWhen(when[i]) : null; if (w) { x.fillStyle = '#667781'; x.font = '500 22px ' + F; const yy = cy + 68 + (lab.length - 1) * 29 + 30; x.fillText(w[0], cx, yy); if (w[1]) x.fillText(w[1], cx, yy + 26); }
        x.textAlign = 'left';
      });
    }
    // details panel
    if (rows.length) {
      x.fillStyle = '#f4f7f6'; rr(x, L + 40, rowsY, CW - 80, rowsH, 30); x.fill();
      rows.forEach((rw, i) => { const ry = rowsY + 18 + i * 76 + 50; x.fillStyle = '#667781'; x.font = '600 27px ' + F; x.fillText(rw[0], L + 76, ry); x.fillStyle = '#111b21'; x.font = '800 30px ' + F; x.textAlign = 'right'; x.fillText(rw[1], L + CW - 76, ry); x.textAlign = 'left'; if (i < rows.length - 1) { x.strokeStyle = '#e3e9e7'; x.lineWidth = 2; x.beginPath(); x.moveTo(L + 76, ry + 24); x.lineTo(L + CW - 76, ry + 24); x.stroke(); } });
    }
    // footer
    x.textAlign = 'center'; x.fillStyle = '#0b6e5f'; x.font = '800 31px ' + F; x.fillText('Questions? Just reply to this message 💬', W / 2, footY + 62); x.fillStyle = '#8696a0'; x.font = '500 25px ' + F; x.fillText(c.brand + (c.store_phone ? '  ·  ' + c.store_phone : ''), W / 2, footY + 106); x.textAlign = 'left';
    return cv;
  }
  function cardCaption(c, name) {
    const first = String(name || '').trim().split(/\s+/)[0]; const hi = first && !/^\+?\d/.test(first) ? 'Hi ' + first + ', ' : 'Hello, ';
    return hi + 'here is the latest on your order ' + c.order_name + ': ' + c.headline + '.' + (c.status !== 'cancelled' && (c.courier || c.tracking_no) ? '\n\n' + (c.courier ? '🚚 Courier: ' + c.courier : '') + (c.courier && c.tracking_no ? '\n' : '') + (c.tracking_no ? '🔢 Tracking ID: ' + c.tracking_no : '') : '') + (c.track_link && c.status !== 'delivered' && c.status !== 'cancelled' ? '\n\n📍 Track it live: ' + c.track_link : '') + '\n\nReply here if you need anything 🙏';
  }
  /** The preview sheet: the card exactly as the customer will see it + one "Send to customer" button. o: { phone, name, order, onDone } */
  function shareCard(o) {
    const phone = String(o.phone || '').replace(/\D/g, '').slice(-10);
    sheet('Send to customer', '<div class="wor-sc" style="margin-bottom:8px">This is exactly what the customer will receive on WhatsApp. Only customer-safe details: no commission, no staff names, no internal notes.</div><div data-pv><div class="wor-sc">Preparing the card…</div></div>', (el, close) => {
      const pv = el.querySelector('[data-pv]');
      api.get('wa.ord_share', { phone, order: o.order }).then((r) => {
        const c = r.card, cv = drawCustomerCard(c); const cap = cardCaption(c, o.name); const img = new Image(); img.src = cv.toDataURL('image/png'); img.alt = 'Order card'; img.style.cssText = 'width:100%;border-radius:16px;display:block';
        pv.innerHTML = ''; pv.appendChild(img);
        const t = document.createElement('textarea'); t.value = cap; t.rows = 6; t.className = 'wor-in'; t.style.cssText = 'width:100%;margin-top:10px;border:1px solid #e0e3e6;border-radius:12px;padding:10px;font:inherit;font-size:14px;box-sizing:border-box'; pv.appendChild(t);
        const b = document.createElement('button'); b.className = 'wor-btn pri'; b.style.cssText = 'width:100%;margin-top:10px'; b.textContent = '📤 Send to customer'; pv.appendChild(b);
        const msg = document.createElement('div'); msg.className = 'wor-sc'; msg.style.marginTop = '8px'; pv.appendChild(msg);
        b.onclick = () => {
          b.disabled = true; b.textContent = 'Sending…'; msg.textContent = '';
          cv.toBlob(async (blob) => {
            try {
              if (!blob) throw new Error('Could not make the picture. Try again.');
              const fd = new FormData(); fd.append('phone', phone); fd.append('caption', t.value.trim()); fd.append('file', blob, 'order-' + String(c.order_name).replace(/[^\w-]/g, '') + '.png');
              await api.upload('wa.send_media', fd); toast('Order card sent ✔'); close(); if (o.onDone) o.onDone();
            } catch (e) { b.disabled = false; b.textContent = '📤 Send to customer'; msg.innerHTML = '<div class="wor-err">' + esc(errMsg(e)) + '</div>'; }
          }, 'image/png');
        };
      }).catch((e) => { pv.innerHTML = '<div class="wor-err">' + esc(errMsg(e)) + '</div>'; });
    });
  }
  const send = (phone, name, order, onDone) => shareCard({ phone, name, order, onDone });
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
  const STEPS = ['Ordered', 'Packed', 'Shipped', 'Out for delivery', 'Delivered'];
  const STAGE = { placed: 0, packed: 1, fulfilled: 1, shipped: 2, delayed: 2, failed: 2, rto: 2, lost: 2, damaged: 2, ofd: 3, delivered: 4 };
  /** The progress line: when it was ordered, packed, shipped, out for delivery and delivered. */
  function progressHtml(o) {
    if (o.status === 'cancelled') return '';
    const at = STAGE[o.status] != null ? STAGE[o.status] : 0, bad = ['failed', 'rto', 'lost', 'damaged', 'delayed'].includes(o.status);
    const when = [o.placed_at, o.tracked_at, o.transit_at, o.ofd_at, o.delivered_at];
    const lbl = bad ? { failed: 'Delivery failed', rto: 'Returning', lost: 'Lost', damaged: 'Damaged', delayed: 'Delayed' }[o.status] : '';
    return '<div class="wor-pg">' + STEPS.map((t, i) => {
      const cls = i < at ? 'done' : i === at ? (o.status === 'delivered' ? 'done' : bad ? 'bad' : 'now') : '';
      const w = when[i] && (i <= at) ? fmt(when[i]) : '';
      return '<div class="' + cls + '"><i>' + (cls === 'done' ? '✓' : cls === 'bad' ? '!' : '') + '</i><b>' + esc(i === at && bad ? lbl : t) + '</b><small>' + esc(w) + '</small></div>';
    }).join('') + '</div>';
  }
  function orderHtml(o) {
    const bad = ['failed', 'rto', 'lost', 'damaged', 'delayed'].includes(o.status), good = o.status === 'delivered';
    let h = '<div class="wor-ord' + (bad ? ' bad' : good ? ' ok' : '') + '"><div class="wor-top"><span class="wor-nm">' + esc(o.order_name) + '</span><span class="wor-pill ' + esc(o.status) + '">' + esc(o.status_label) + '</span></div>';
    h += '<div class="wor-tx">' + esc(o.status_text) + '</div>' + progressHtml(o) + '<div class="wor-mt">';
    if (o.courier) h += '<span class="wor-ch">🚚 ' + esc(o.courier) + '</span>';
    if (o.tracking_no) h += (o.track_link ? '<a class="wor-ch link" target="_blank" rel="noopener" href="' + esc(o.track_link) + '">AWB ' + esc(o.tracking_no) + ' ↗</a>' : '<span class="wor-ch" data-copy="' + esc(o.tracking_no) + '">AWB ' + esc(o.tracking_no) + '</span>');
    if (o.total != null) h += '<span class="wor-ch">' + esc(inr(o.total)) + (o.payment ? ' · ' + esc(o.payment) : '') + '</span>';
    if (o.placed_at) h += '<span class="wor-ch muted">Placed ' + esc(day(o.placed_at)) + '</span>';
    h += '</div>';
    if (o.etd_text) h += '<div class="wor-sc">📅 ' + (o.etd ? esc(o.etd_text) : '<i>' + esc(o.etd_text) + '</i>') + '</div>';
    if (o.last_scan) h += '<div class="wor-sc">Last scan: <b>' + esc(o.last_scan.label) + '</b>' + (o.last_scan.place ? ' · ' + esc(o.last_scan.place) : '') + ' · ' + esc(fmt(o.last_scan.at)) + '</div>';
    (o.update_sent || []).forEach((u) => { h += '<div class="wor-mt"><span class="wor-ch sent">' + (u.state === 'sent' ? '✔ ' : '⏳ ') + esc(u.text) + '</span></div>'; });
    if ((o.timeline || []).length > 1) h += '<details class="wor-tl"><summary>Scan history (' + o.timeline.length + ')</summary><ol>' + o.timeline.map((e) => '<li><b>' + esc(e.label || e.activity) + '</b>' + (e.place ? ' · ' + esc(e.place) : '') + '<small>' + esc(fmt(e.at)) + (e.activity && e.label ? ' · ' + esc(e.activity) : '') + '</small></li>').join('') + '</ol></details>';
    h += '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap"><button class="wor-btn sm" data-full="' + esc(o.order_name) + '" data-store="' + esc(o.store) + '">📋 Full details</button>' + (o.status === 'cancelled' ? '' : '<button class="wor-btn sm pri" data-send="' + esc(o.order_name) + '">📤 Send to customer</button>') + '</div>';
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
      const sd = e.target.closest('[data-send]'); if (sd) { send(S.phone, o.name, sd.getAttribute('data-send'), o.onChange); return; }
      const fu = e.target.closest('[data-full]'); if (fu) { details(S.phone, fu.getAttribute('data-full'), fu.getAttribute('data-store')); return; }
      const c = e.target.closest('[data-copy]'); if (c) { try { navigator.clipboard.writeText(c.getAttribute('data-copy')); toast('Tracking number copied'); } catch (x) { } return; }
      const u = e.target.closest('[data-unlink]'); if (u) { if (!confirm('Remove the link to order ' + u.getAttribute('data-unlink') + ' from this chat?')) return; api.post('wa.ord_unlink', { phone: S.phone, order_name: u.getAttribute('data-unlink'), store: u.getAttribute('data-store') }).then(() => { toast('Link removed'); load(); }).catch((x) => toast(errMsg(x), true)); }
    };
    el.addEventListener('click', onClick); load();
    return { refresh: () => { S.d = null; return load(); }, destroy: () => { S.dead = true; el.removeEventListener('click', onClick); el.innerHTML = ''; } };
  }

  function open(o) {
    o = o || {}; let c = null;
    sheet('Orders & tracking', '<div data-hero></div><div data-slot></div>', (el) => {
      c = card(el.querySelector('[data-slot]'), o);
      api.get('wa.ord_sum', { phone: String(o.phone || '').replace(/\D/g, '').slice(-10) }).then((m) => { const hx = el.querySelector('[data-hero]'); if (hx && m) hx.innerHTML = heroHtml(m, o); }).catch(() => { });
    });
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
  function settings(el, opts) {
    opts = opts || {}; css(); el.classList.add('wor');
    async function load() {
      let s; try { s = await api.get('wa.ord_settings'); } catch (e) { el.innerHTML = '<div class="wor-tile"><div class="wor-err">' + esc(errMsg(e)) + '</div></div>'; return; }
      const ONLY = Array.isArray(opts.only) ? opts.only : null; const view = ONLY ? s.statuses.filter((x) => ONLY.includes(x.key)) : s.statuses;
      let h = '<div class="wor-tile"><h2>' + esc(opts.title || 'Order-update messages') + '</h2>' + (opts.intro ? '<p class="wor-sub">' + opts.intro + '</p>' : '') + (opts.intro ? '' : '<p class="wor-sub">WhatsApp messages sent by themselves when the courier status of an order changes. Each order gets each message once. Nothing goes out between 9 PM and 8 AM (it waits and goes at 8 AM), or to a customer who opted out. A message is sent only when Meta has approved its template.</p>');
      if (!ONLY) h += '<div class="wor-row"><div><b>Send order updates automatically</b><small>Master switch</small></div><label class="wor-sw"><input type="checkbox" data-master ' + (s.on ? 'checked' : '') + '><i></i></label></div>';
      CUR = s.statuses; view.forEach((x) => { h += '<div class="wor-row" style="align-items:flex-start"><div style="flex:1;min-width:0"><b>' + esc(x.label) + '</b>' + (x.when ? '<small style="display:block;color:#667781">Sent ' + esc(x.when) + '</small>' : '') + (x.body ? '<div style="background:#d9fdd3;border-radius:10px;padding:8px 10px;margin:6px 0;font-size:13px;line-height:1.45;white-space:pre-wrap">' + esc(x.body).replace(/\{\{(\d)\}\}/g, '<b style="color:#5b21b6">{{$1}}</b>') + '</div>' : '') + '<small><span class="wor-ts ' + esc(x.template_status) + '">' + esc(x.template_status === 'approved' ? 'Approved by Meta' : x.template_status === 'missing' ? 'Not created yet' : x.template_status.charAt(0).toUpperCase() + x.template_status.slice(1)) + '</span> · today ' + x.sent_today + ' · this month ' + x.sent_month + (x.queued ? ' · waiting ' + x.queued : '') + (x.template_reason ? '<br>' + esc(x.template_reason) : '') + '</small><div style="margin-top:6px"><button class="wor-b" style="min-height:32px;padding:4px 14px;font-size:12px" data-edit="' + esc(x.key) + '">✏️ Edit</button>' + badge(x) + '</div></div><label class="wor-sw"><input type="checkbox" data-st="' + esc(x.key) + '" ' + (x.on ? 'checked' : '') + '><i></i></label></div>'; });
      const rv = s.review || { link: '', good_from: 5 };
      if (!ONLY || ONLY.includes('review')) h += '<div class="wor-row" style="display:block;margin-top:12px"><b>⭐ Rating request: where each rating goes</b><small style="display:block;color:#667781;margin:2px 0 8px">The customer taps 5 to 1. A good rating gets your Judge.me review link; a lower one opens a Complaints ticket and our team contacts them. Switch the "Rating request" message above on to start. It only goes to orders delivered after you switch it on.</small>'
        + '<label class="wor-l">Judge.me review link (https://…)</label><input class="wor-in" data-rvlink value="' + esc(rv.link) + '" placeholder="https://judge.me/reviews/stores/your-store" style="width:100%;box-sizing:border-box">'
        + '<label class="wor-l" style="margin-top:8px">Send the Judge.me link when the rating is at least</label><select class="wor-in" data-rvgood>' + [5, 4, 3, 2].map((n) => '<option value="' + n + '"' + (+rv.good_from === n ? ' selected' : '') + '>' + n + ' of 5 (lower ratings → complaint)</option>').join('') + '</select>'
        + '<div style="margin-top:8px"><button class="wor-b" data-rvsave>Save</button></div></div>';
      if (opts.extra) h += opts.extra;
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
      const sv = e.target.closest('[data-rvsave]'); if (sv) { sv.disabled = true; try { await api.post('wa.ord_settings', { review_link: el.querySelector('[data-rvlink]').value.trim(), review_good: +el.querySelector('[data-rvgood]').value }); toast('Saved'); } catch (x) { toast(errMsg(x), true); } sv.disabled = false; return; }
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

  return { _esc: esc, _toast: toast, _err: errMsg, card, badge, birthday, open, send, urgent, link, attention, count, storeForm, settings, _fmt: fmt };
})();
// 🎁 the three extra Automation pages: After delivery, Birthdays, Complaints messages
const LINK = (href, t) => '<a class="wor-b" style="text-decoration:none;display:inline-block;margin:4px 6px 0 0" href="' + href + '" target="_top">' + t + '</a>';
if (window.WaPanels && WaPanels.add) {
  WaPanels.add('afterdel', (el) => { const s = WaOrd.settings(el, { only: ['review', 'review_nudge'], title: '⭐ After delivery', intro: 'What goes to the customer after the parcel is delivered: first a rating request (5 days later), then, only for 5-star customers who have not sent their review screenshot, one reminder. Ratings, screenshots and gifts are handled in Complaints → ⭐ Reviews.', extra: '<div style="margin-top:10px">' + LINK('complaints.html?tab=reviews', '⭐ Open Reviews (who rated, screenshots, gifts)') + LINK('complaints.html?tab=credit', '🎁 Free perfume gifts') + '</div>' }); return () => s.refresh(); });
  WaPanels.add('birthdays', (el) => { const s = WaOrd.settings(el, { only: ['bday_ask', 'birthday'], title: '🎂 Birthdays', intro: 'The question goes within an hour after an order (the customer replies like 14/12, or the team taps the small 🎂 button in the chat), then their gift message the day before the birthday (use it online above ₹1,500, or collect at the store). Store customers get a different message, from the cashier, one by one, in Store → Birthdays → “To send today” (its wording and photo are set there).', extra: '<div style="margin-top:10px">' + LINK('store_billing.html', '🏬 Store: birthdays to send today') + LINK('complaints.html?tab=credit', '🎁 Free perfume gifts used / waiting') + '</div>' }); return () => s.refresh(); });
  WaPanels.add('cxmsgs', (el) => {
    const { _esc: esc, _toast: toast, _err: errMsg } = WaOrd; el.classList.add('wor');
    const pill = (st) => '<span class="wor-pill ' + (st === 'APPROVED' ? 'delivered' : st === 'REJECTED' ? 'failed' : st === 'PENDING' ? 'ofd' : '') + '">' + esc({ APPROVED: 'Approved by Meta', PENDING: 'Waiting for Meta', REJECTED: 'Meta said no', MISSING: 'Not created yet' }[String(st || 'missing').toUpperCase()] || st) + '</span>';
    async function load() {
      let d; try { d = await api.get('bd.cx_tpls'); } catch (e) { el.innerHTML = '<div class="wor-tile"><div class="wor-err">' + esc(errMsg(e)) + '</div></div>'; return; }
      const T = d.tpls || [];
      el.innerHTML = '<div class="wor-tile"><h2>🎫 Complaint messages</h2><p class="wor-sub">Pre-made messages the Complaints desk sends after the 24-hour chat window is closed (a complaint update, a request for photos or details). The wording and photo of the normal complaint messages are set in Complaints → Settings.</p>'
        + (T.length ? T.map((t) => '<div class="wor-row" style="align-items:flex-start;display:block"><b>' + esc(t.label) + '</b> ' + pill(t.status) + (t.reason ? '<small style="display:block;color:#b3261e">' + esc(t.reason) + '</small>' : '') + '<div style="background:#d9fdd3;border-radius:10px;padding:8px 10px;margin:6px 0;font-size:13px;line-height:1.45;white-space:pre-wrap">' + esc(t.body).replace(/\{\{(\d)\}\}/g, '<b style="color:#4338ca">{{$1}}</b>') + '</div></div>').join('') : '<p class="wor-sub">No complaint messages are set up yet.</p>')
        + '<div style="margin-top:10px"><button class="wor-b" data-cxseed>' + (T.every((t) => String(t.status).toUpperCase() !== 'MISSING') ? 'Check the messages at Meta' : 'Create the missing messages and send them to Meta') + '</button> ' + LINK('complaints.html?tab=settings&go=msgs', '🎫 Change the words and photo') + '<span class="wor-sub" data-cxm></span></div></div>';
    }
    el.onclick = async (e) => { const b = e.target.closest('[data-cxseed]'); if (!b) return; b.disabled = true; const m = el.querySelector('[data-cxm]'); if (m) m.textContent = ' Working…'; try { await api.post('bd.cx_tpls', {}); toast('Done'); } catch (x) { toast(errMsg(x), true); } load(); };
    load(); return load;
  });
}
// 📦 the WhatsApp section's "Order updates" page (Automation → Order updates)
if (window.WaPanels && WaPanels.add) WaPanels.add('orderupd', (el) => { const s = WaOrd.settings(el); return () => s.refresh(); });

