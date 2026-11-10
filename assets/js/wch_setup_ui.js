/**
 * wch_setup_ui.js — owner screens of the chain + perfume cart ("wch" module). Needs the global `api`.
 *   WaCartSetup.billing(el, { api, onState })    the Billing tab of Learn & Setup: required-fields counter, grouped fields, switches, LIVE bill preview, Claude "What I understood".
 *                                                onState({filled,total,complete,red_dot,missing}) runs after the counter is read or saved (the page shows the red dot on the tab).
 *   WaCartSetup.billingState({ api })            Promise of that same small object (for the red dot before the tab is opened); never rejects (null on error).
 *   WaCartSetup.catalog(el, { api })             "Catalog sync & mapping": last sync, Refresh now, Shopify permission check, what is a chain / a perfume, sizes, problems,
 *                                                chain price coverage with gap suggestions, Claude questions + understanding.
 *   WaCartSetup.messages(el, { api })            the cart + order customer messages: status, edit text, photo, submit to Meta, and the on/off switch of the whole flow.
 * Server side: empmanagment/wch.php (actions wch.*, contract Q_M2_api.md). Styles carry the prefix wchx- and are injected here, so any page can mount them.
 * Every fetch has a loading, an error (with Try again) and an empty state in plain English; a failed endpoint never blocks the rest of the page.
 */
(function () {
  'use strict';
  if (window.WaCartSetup && window.WaCartSetup.v) return;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (n, d) => { n = Number(n); return isFinite(n) ? n : (d == null ? 0 : d); };
  const fm = (n) => '₹' + Math.round(num(n)).toLocaleString('en-IN');
  const parse = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null; };
  const agoMin = (m) => m == null ? '' : (m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' days ago');
  function apiOf(o) { if (o && o.api) return o.api; try { return typeof api !== 'undefined' ? api : null; } catch (e) { return null; } }
  const E = (e) => (e && e.message) || 'Something went wrong. Try again.';
  const codeOf = (e) => (e && e.data && e.data.code) || '';
  const sleepless = (fn, ms) => { let t = null; const f = (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; f.cancel = () => clearTimeout(t); return f; };
  const CSS = `.wchx{max-width:1180px;margin:0 auto;padding:0 4px 40px;font-size:14px;line-height:1.45}
.wchx *{box-sizing:border-box} .wchx button,.wchx input,.wchx select,.wchx textarea{font:inherit;color:inherit}
.wchx .sub{color:#6b7684;font-size:12.5px;line-height:1.5}
.wchx .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap} .wchx .sp{flex:1}
.wchx-hd{background:#fff;border:1px solid #e3e8ef;border-radius:18px;overflow:hidden;margin-bottom:14px}
.wchx-top{display:flex;gap:12px;align-items:center;padding:14px 18px;flex-wrap:wrap} .wchx-top h2{margin:0;font-size:20px;font-weight:800}
.wchx-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:99px;font-size:12px;font-weight:700;background:#f1f3f6;color:#4a5565;white-space:nowrap}
.wchx-pill.g{background:#e6f7ee;color:#0f766e}.wchx-pill.o{background:#fff1e3;color:#c2570c}.wchx-pill.b{background:#e8f0ff;color:#2457c5}.wchx-pill.r{background:#fde8e8;color:#b42318}.wchx-pill.p{background:#f3efff;color:#6d3fd1}.wchx-pill.y{background:#fff3dc;color:#8a5a00}
.wchx-tabs{display:flex;gap:4px;padding:8px 14px 0;border-top:1px solid #e3e8ef;overflow-x:auto;scrollbar-width:none}
.wchx-tabs::-webkit-scrollbar{display:none}
.wchx-tab{border:1px solid transparent;border-bottom:0;background:none;padding:11px 16px;min-height:44px;border-radius:10px 10px 0 0;font-weight:700;font-size:14px;color:#6b7684;white-space:nowrap;display:flex;gap:7px;align-items:center;position:relative}
.wchx-tab.on{background:#fff7ef;color:#c2570c;border-color:#f5b27a;box-shadow:inset 0 -3px 0 #f58220}
.wchx-tab .rd{width:9px;height:9px;border-radius:50%;background:#e5322d;box-shadow:0 0 0 2px #fff}
.wchx-tab em{font-style:normal;background:#f58220;color:#fff;border-radius:99px;font-size:11px;padding:1px 7px}
.wchx-card{background:#fff;border:1px solid #e3e8ef;border-radius:16px;padding:14px 16px;margin-bottom:12px}
.wchx-card.ok{border-left:5px solid #16a34a}.wchx-card.ask{border-left:5px solid #f59e0b}.wchx-card.bad{border-left:5px solid #d9423b}
.wchx-card h3{margin:0 0 4px;font-size:15.5px;font-weight:800;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.wchx-sec{font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#6b7684;margin:16px 0 8px}
.wchx-btn{border:1px solid #d5dbe4;background:#fff;border-radius:10px;padding:9px 14px;font-weight:700;font-size:13px;min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap}
.wchx-btn.p{background:#f58220;border-color:#f58220;color:#fff}.wchx-btn.r{color:#b42318}.wchx-btn.s{min-height:40px;padding:5px 12px;font-size:12.5px}.wchx-btn.g{background:#16a34a;border-color:#16a34a;color:#fff}
.wchx-in{width:100%;border:1px solid #cfd6e0;border-radius:10px;padding:0 12px;min-height:44px;background:#fff;line-height:1.4;display:flex;align-items:center;font-size:14px;gap:8px;overflow:hidden;white-space:nowrap}
.wchx-in.err{border-color:#d9423b;background:#fff8f7} .wchx-in.ok{border-color:#bfe3cc} .wchx-in.dis{background:#f4f6f8;color:#6b7684}
.wchx-in i{font-style:normal;color:#9aa4b2} .wchx-in .u{margin-left:auto;font-size:12px;color:#6b7684;font-weight:700}
.wchx-f{display:flex;flex-direction:column;gap:4px;min-width:0}
.wchx-f>label{font-size:12.5px;font-weight:800;color:#3b4552;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.wchx-f>label i{font-style:normal;font-weight:600;color:#9aa4b2;font-size:12px}
.wchx-f>label .rq{color:#d9423b}
.wchx-f small{font-size:12px;color:#6b7684;line-height:1.35}.wchx-f small.er{color:#b42318;font-weight:700}
.wchx-g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.wchx-g3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.wchx-sum{background:linear-gradient(180deg,#f0fdf4,#fff);border:1px solid #bbf7d0;border-radius:12px;padding:11px 14px;margin:8px 0}
.wchx-sum b.t{display:block;font-size:12px;letter-spacing:.06em;color:#166534;margin-bottom:4px}
.wchx-sum ul{list-style:none;margin:0;padding:0}.wchx-sum li{position:relative;padding:5px 0 5px 22px;font-size:13.5px;line-height:1.5;border-top:1px dashed #d5efdc}.wchx-sum li:first-child{border-top:0}
.wchx-sum li:before{content:"✓";position:absolute;left:2px;color:#16a34a;font-weight:800}
.wchx-q{border:1px solid #e3e8ef;border-radius:12px;padding:10px 12px;margin:8px 0;background:#fcfcfd}.wchx-q small{display:block;font-weight:700;font-size:13px;margin-bottom:6px;color:#1b2430}
.wchx-chips{display:flex;gap:7px;flex-wrap:wrap}
.wchx-ch{min-height:42px;border:1.5px solid #e3e8ef;border-radius:99px;padding:0 14px;font-weight:700;font-size:13px;background:#fff;display:inline-flex;align-items:center;gap:6px;text-align:left;line-height:1.25}
.wchx-ch.on{border-color:#f58220;background:#fff7ef;color:#c2570c}
.wchx-hl{background:#fff7ef;border:1px dashed #f5b27a;border-radius:10px;padding:9px 12px;font-size:13px;color:#8a4a12;margin-top:10px}
.wchx-note{background:#fff7e6;color:#7a5200;border:1px solid #f6dfaa;border-radius:12px;padding:9px 13px;margin:8px 0;font-size:13px;line-height:1.5}
.wchx-err{background:#fde8e8;color:#9a2620;border-radius:12px;padding:9px 13px;margin:8px 0;font-size:13px;line-height:1.5}
.wchx-kv{padding:9px 0;border-top:1px solid #f1f3f6;font-size:13.5px;display:flex;gap:8px;align-items:center}.wchx-kv:first-of-type{border-top:0}
.wchx-kv .mid{flex:1;min-width:0}.wchx-kv .mid b{display:block}
.wchx-sw{display:flex;gap:12px;align-items:center;padding:9px 0;border-top:1px solid #f1f3f6;min-height:52px}.wchx-sw:first-of-type{border-top:0}
.wchx-sw .t{flex:1;min-width:0;font-weight:700;font-size:13.5px;line-height:1.3}.wchx-sw .t small{display:block;font-weight:500;color:#6b7684;font-size:12px;margin-top:1px}
.wchx-tg{width:48px;height:28px;border-radius:99px;background:#16a34a;position:relative;flex:none}.wchx-tg:after{content:"";position:absolute;left:23px;top:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.wchx-tg.off{background:#cbd5e1}.wchx-tg.off:after{left:3px}
.wchx-seg{display:inline-flex;border:1px solid #d5dbe4;border-radius:10px;overflow:hidden;background:#fff}
.wchx-seg span{min-height:40px;padding:0 15px;display:flex;align-items:center;font-weight:700;font-size:13px;color:#4a5565}
.wchx-seg span.on{background:#1b2430;color:#fff}
.wchx-prog{height:10px;border-radius:9px;background:#e9edf2;overflow:hidden;margin:8px 0}.wchx-prog i{display:block;height:100%;border-radius:9px;background:#f58220}
.wchx-chk{display:flex;flex-wrap:wrap;gap:6px}
.wchx-chk span{font-size:12px;font-weight:700;border-radius:99px;padding:4px 10px;display:inline-flex;gap:5px;align-items:center}
.wchx-chk .y{background:#e6f7ee;color:#0f766e}.wchx-chk .n{background:#fde8e8;color:#b42318}
.wchx-acc{border:1px solid #e3e8ef;border-radius:16px;background:#fff;margin-bottom:10px;overflow:hidden}
.wchx-acc>.h{display:flex;gap:10px;align-items:center;padding:0 14px;min-height:54px;font-weight:800;font-size:15px}
.wchx-acc>.h .sp{flex:1}
.wchx-acc>.b{padding:2px 14px 14px;display:flex;flex-direction:column;gap:11px}
.wchx-acc.closed>.h{border-bottom:0}
.wchx-acc>.h .cv{width:26px;height:26px;display:grid;place-items:center;color:#9aa4b2}
.wchx-slab{display:grid;grid-template-columns:96px 1fr 44px;gap:10px;align-items:center;min-height:42px}
.wchx-slab .rg{font-weight:700;font-size:13px;white-space:nowrap}
.wchx-slab .bar{height:20px;border-radius:7px;background:#f1f3f6;overflow:hidden;position:relative}
.wchx-slab .bar i{display:block;height:100%;border-radius:7px;background:#f58220}
.wchx-slab .ct{font-weight:800;text-align:right;font-size:14px}
.wchx-slab.empty .bar{background:repeating-linear-gradient(135deg,#fde8e8 0 6px,#fff3f3 6px 12px);border:1.5px dashed #e5322d}
.wchx-slab.empty .rg,.wchx-slab.empty .ct{color:#b42318}
.wchx-slab.empty .bar:after{content:"empty";position:absolute;left:8px;top:0;line-height:17px;font-size:11px;font-weight:800;color:#b42318}
.wchx-prob{display:flex;gap:10px;align-items:center;padding:9px 0;border-top:1px solid #f1f3f6;min-height:52px}.wchx-prob:first-of-type{border-top:0}
.wchx-prob .n{min-width:34px;height:30px;border-radius:99px;background:#fde8e8;color:#b42318;font-weight:800;display:grid;place-items:center;font-size:13px;padding:0 6px}
.wchx-prob .n.y{background:#fff3dc;color:#8a5a00}
.wchx-prob .mid{flex:1;min-width:0;font-size:13.5px;font-weight:700;line-height:1.3}.wchx-prob .mid small{display:block;font-weight:500;color:#6b7684;font-size:12px}
.wchx-cl{background:linear-gradient(135deg,#f4f0ff,#eef6ff);border:1px solid #ddd6fe;border-radius:14px;padding:12px 14px;color:#2f2a55}
.wchx-cl .hd{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:#3b2a8a;margin-bottom:5px}
.wchx-cl .sp2{width:26px;height:26px;border-radius:9px;background:#3b2a8a;color:#fff;display:grid;place-items:center;flex:none}
.wchx-cl p{margin:0;font-size:13.5px;line-height:1.5}
.wchx-thumb{width:48px;height:48px;border-radius:10px;background-size:cover;flex:none}
.wchx-lock{font-size:12px;color:#6b7684;display:flex;gap:6px;align-items:center}
.wchx-cols{display:grid;grid-template-columns:minmax(0,1fr) 460px;gap:16px;align-items:start}
.wchx-cols .stick{position:sticky;top:12px}
.wchx-sizes{display:flex;gap:7px;flex-wrap:wrap}
.wchx-sizes span{border-radius:12px;background:#f1f3f6;padding:6px 11px;font-size:12.5px;font-weight:700;display:flex;gap:6px;align-items:baseline}
.wchx-sizes span b{font-size:14px}
.wchx-sizes span.w{background:#fff3dc;color:#8a5a00}
.wchx-tbl{width:100%;border-collapse:collapse;font-size:13px}
.wchx-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7684;text-align:left;padding:6px;border-bottom:1px solid #eef0f3}
.wchx-tbl td{padding:8px 6px;border-bottom:1px solid #f4f5f7;vertical-align:middle}
@media (max-width:760px){.wchx-g2,.wchx-g3{grid-template-columns:1fr}.wchx-cols{grid-template-columns:1fr}.wchx-top h2{font-size:17px}}
@media (max-width:560px){.wchx-g2.keep{grid-template-columns:1fr 1fr}}


.wchx .wchx-in{width:100%}.wchx input.wchx-in,.wchx select.wchx-in,.wchx textarea.wchx-in{font-size:14px;outline:0;color:#1b2430;font-family:inherit;display:block}
.wchx textarea.wchx-in{padding:10px 12px;min-height:84px;line-height:1.4;white-space:pre-wrap;display:block;resize:vertical;overflow:auto}
.wchx input.wchx-in:focus,.wchx select.wchx-in:focus,.wchx textarea.wchx-in:focus{border-color:#f58220;box-shadow:0 0 0 3px rgba(245,130,32,.15)}
.wchx .wchx-in.err{border-color:#d9423b;background:#fff8f7}
.wchx .wchx-in.dis,.wchx .wchx-in:disabled{background:#f4f6f8;color:#6b7684}
.wchx .wchx-unit{position:relative}.wchx .wchx-unit input{padding-right:64px}.wchx .wchx-unit span{position:absolute;right:12px;top:0;bottom:0;display:flex;align-items:center;font-size:12px;color:#6b7684;font-weight:700;pointer-events:none}
.wchx .wchx-tg{border:0;cursor:pointer;padding:0;width:56px;height:44px;background:none;border-radius:0;position:relative;flex:none}
.wchx .wchx-tg.off{background:none}
.wchx .wchx-tg:before{content:"";position:absolute;left:4px;top:8px;width:48px;height:28px;border-radius:99px;background:#16a34a}
.wchx .wchx-tg.off:before{background:#cbd5e1}
.wchx .wchx-tg:after{left:27px;top:11px}.wchx .wchx-tg.off:after{left:7px}.wchx .wchx-tg:focus-visible{outline:3px solid rgba(245,130,32,.4)}
.wchx .wchx-seg{border:1px solid #d5dbe4;border-radius:10px;overflow:hidden;background:#fff;display:inline-flex}
.wchx .wchx-seg button{border:0;background:none;min-height:40px;padding:0 15px;font-weight:700;font-size:13px;color:#4a5565;cursor:pointer}
.wchx .wchx-seg button.on{background:#1b2430;color:#fff}
.wchx .wchx-acc>.h{cursor:pointer;width:100%;background:none;border:0;text-align:left}
.wchx .wchx-acc.closed>.b{display:none}
.wchx .wchx-acc>.h .cv{transition:transform .15s}.wchx .wchx-acc.closed>.h .cv{transform:rotate(-90deg)}
.wchx .wchx-savebar{position:sticky;bottom:0;z-index:5;background:#fff;border:1px solid #f5b27a;border-radius:14px;padding:10px 12px;margin:10px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 -4px 16px rgba(0,0,0,.08)}
.wchx .wchx-pvw{background:#e9ebee;border-radius:12px;padding:10px;overflow:hidden;position:relative}
.wchx .wchx-pvw iframe{border:0;background:#fff;display:block;transform-origin:0 0;box-shadow:0 2px 10px rgba(0,0,0,.12)}
.wchx .wchx-btn:disabled{opacity:.55;cursor:default}
.wchx .wchx-btn{cursor:pointer}
.wchx .wchx-ch{cursor:pointer;font-family:inherit}
.wchx .wchx-ch.on{border-color:#f58220;background:#fff7ef;color:#c2570c}
.wchx .wchx-msg{background:#e8f7e4;border:1px solid #cdeac6;border-radius:4px 14px 14px 14px;padding:9px 12px;margin-top:9px;font-size:13.5px;line-height:1.45;white-space:pre-line;max-width:100%;overflow-wrap:anywhere}
.wchx .wchx-var{font-family:ui-monospace,Menlo,monospace;padding:2px 8px}
.wchx .wchx-sk{height:54px;border-radius:12px;background:linear-gradient(90deg,#f1f3f6,#e6e9ee,#f1f3f6);background-size:200% 100%;animation:wchxsk 1.2s linear infinite;margin:8px 0}
@keyframes wchxsk{to{background-position:-200% 0}}
.wchx .wchx-ovr{display:flex;gap:8px;align-items:center;padding:7px 0;border-top:1px solid #f1f3f6;font-size:13px;flex-wrap:wrap}
.wchx [hidden]{display:none !important}
.wchx .wchx-name{overflow-wrap:anywhere}
.wchx .wchx-cols>*{min-width:0}
.wchx .wchx-card,.wchx .wchx-acc{max-width:100%}
.wchx .wchx-rd{display:inline-block;width:9px;height:9px;border-radius:50%;background:#e5322d;margin-left:6px}
@media (max-width:760px){.wchx .wchx-cols{grid-template-columns:minmax(0,1fr)}.wchx .wchx-btn{min-height:44px}.wchx .wchx-g2:not(.keep){grid-template-columns:1fr}}
@media (min-width:761px){.wchx .wchx-cols .stick{max-height:calc(100vh - 24px);overflow:auto}}
`;
  function injectCss() { if (document.getElementById('wchxCss')) return; const s = document.createElement('style'); s.id = 'wchxCss'; s.textContent = CSS; document.head.appendChild(s); }
  function toast(msg, bad) {
    document.querySelectorAll('.wchx-toast').forEach((x) => x.remove());
    const t = document.createElement('div'); t.className = 'wchx-toast'; t.setAttribute('role', 'status'); t.textContent = msg;
    t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:' + (bad ? '#b42318' : '#14532d') + ';color:#fff;padding:10px 18px;border-radius:12px;font-weight:700;font-size:13.5px;z-index:99999;max-width:90vw;text-align:center';
    document.body.appendChild(t); setTimeout(() => t.remove(), bad ? 5200 : 2600);
  }
  async function soft(fn) { try { return { ok: true, d: await fn() }; } catch (e) { return { ok: false, err: E(e), e }; } }
  const alive = (el) => el && el.isConnected;
  const skel = (n) => '<div class="wchx-sk"></div>'.repeat(n || 3);
  const errCard = (msg, btn) => '<div class="wchx-card bad"><div class="wchx-err" style="margin:0">' + esc(msg) + '</div>' + (btn ? '<div class="row" style="margin-top:8px"><button type="button" class="wchx-btn" data-a="' + btn + '">Try again</button></div>' : '') + '</div>';

  // ================================================================== BILLING
  const FIELDS = {
    legal_name: { l: 'Legal name', ph: 'e.g. FOURPIRATES VENTURES LLP', ac: 'organization' }, trade_name: { l: 'Trade name', opt: 1, ph: 'e.g. TM Perfume House' }, pan: { l: 'PAN', opt: 1, ph: 'ABCDE1234F', up: 1, max: 10 }, email: { l: 'Email', opt: 1, ph: 'accounts@yourshop.com', type: 'email' },
    address: { l: 'Registered address', ph: 'Shop / building, area, city', area: 1, hint: 'Printed under the logo.' }, state: { l: 'State', sel: 1 }, state_code: { l: 'State code', ro: 1, hint: 'From the state' }, pincode: { l: 'Pincode', ph: '395009', inputmode: 'numeric', max: 6 }, phone: { l: 'Phone', ph: '98xxxxxxxx', inputmode: 'tel' },
    gstin: { l: 'GSTIN', ph: '24ABCDE1234F1Z5', up: 1, max: 15, hint: '15 characters, e.g. 24ABCDE1234F1Z5. The first two digits must match the state code.' },
    hsn_perfume: { l: 'Perfume HSN', ph: '3303', inputmode: 'numeric', max: 8 }, rate_perfume: { l: 'Perfume GST', unit: '%', type: 'number', opt: 1 }, hsn_chain: { l: 'Chain HSN', ph: '7117', inputmode: 'numeric', max: 8 }, rate_chain: { l: 'Chain GST', unit: '%', type: 'number', opt: 1 },
    split_pct: { l: 'Chain share of every cart', unit: '% of the total', type: 'number', opt: 1, hint: 'Perfumes get the rest. Rates and HSN are settings, never fixed in code.' },
    inv_prefix: { l: 'Prefix', ph: 'TM', up: 1, max: 4 }, inv_next: { l: 'Next number', inputmode: 'numeric', type: 'number' },
    bank_name: { l: 'Bank name', opt: 1 }, bank_account: { l: 'Account number', opt: 1, inputmode: 'numeric' }, bank_ifsc: { l: 'IFSC', opt: 1, up: 1, max: 11 }, footer: { l: 'Footer text', opt: 1, area: 1, ph: 'Computer generated invoice, no signature needed.' }
  };
  const GROUPS = [
    { k: 'business', t: 'Business', f: ['legal_name', 'trade_name', 'pan', 'email'] },
    { k: 'address', t: 'Address', f: ['address', ['state', 'state_code'], ['pincode', 'phone']] },
    { k: 'tax', t: 'Tax', f: ['gstin', ['hsn_perfume', 'rate_perfume'], ['hsn_chain', 'rate_chain'], 'split_pct'] },
    { k: 'series', t: 'Series', f: [['inv_prefix', 'inv_next']], series: 1 },
    { k: 'bank', t: 'Bank details', f: ['bank_name', ['bank_account', 'bank_ifsc']] },
    { k: 'look', t: 'Look', look: 1 }
  ];
  const SWITCH = [['show_short_name', 'Show short product name', 'Small grey name under each SKU'], ['show_hsn', 'HSN column in the table', 'Off: HSN shows once in each section heading'], ['show_bank', 'Bank details', 'Add account and IFSC at the bottom'], ['show_qr', 'QR / order number', 'Small code that opens the online copy'], ['show_logo', 'Logo', 'Top left of the bill']];
  const NONEMPTY = (v) => v !== null && v !== undefined && String(v).trim() !== '';

  function billing(el, o) {
    injectCss(); o = o || {}; const A = apiOf(o); if (!el) return null; el.classList.add('wchx');
    const B = { data: null, vals: {}, base: {}, errs: {}, open: { address: true, tax: true }, claude: null, cnext: null, cmode: false, cbusy: false, cerr: '', cans: '', saving: false, logoData: null, rmLogo: false, pstate: 'Gujarat', pv: { html: '', size: 'phone', busy: false, err: '', seq: 0, sample: true }, loadErr: '' };
    let dead = false;
    const $ = (s) => el.querySelector(s);
    const dirtyKeys = () => Object.keys(B.vals).filter((k) => String(B.vals[k] == null ? '' : B.vals[k]) !== String(B.base[k] == null ? '' : B.base[k])).concat(B.logoData ? ['logo'] : []).concat(B.rmLogo ? ['logo'] : []);
    const reqKeys = () => (B.data && B.data.required ? B.data.required.map((r) => r.key) : []);
    const filledLocal = () => reqKeys().filter((k) => NONEMPTY(B.vals[k])).length;
    const missingLocal = () => (B.data ? B.data.required.filter((r) => !NONEMPTY(B.vals[r.key])).map((r) => r.label) : []);
    const notify = (d) => { if (typeof o.onState === 'function' && d) { try { o.onState({ filled: d.filled, total: d.total, complete: d.complete, red_dot: d.red_dot, missing: d.missing || [] }); } catch (e) { } } };

    async function load(keepDirty) {
      if (!A) { el.innerHTML = errCard('You are not logged in. Reload the page.'); return; }
      if (!B.data) el.innerHTML = skel(4);
      const r = await soft(() => A.get('wch.billing', {})); if (dead || !alive(el)) return;
      if (!r.ok) { B.loadErr = r.err; if (!B.data) { el.innerHTML = errCard(r.err, 'reload'); bind(); return; } toast(r.err, true); return; }
      B.loadErr = ''; const old = B.vals; B.data = r.d; B.base = Object.assign({}, r.d.values); B.vals = Object.assign({}, r.d.values);
      if (keepDirty) Object.keys(old).forEach((k) => { if (String(old[k]) !== String(B.base[k]) && B.dirtyKeep && B.dirtyKeep.has(k)) B.vals[k] = old[k]; });
      B.pv.size = B.vals.size || 'phone'; notify(r.d);
      if (!B.openInit) { B.openInit = true; B.open = {}; GROUPS.forEach((g) => { const ks = [].concat.apply([], (g.f || []).map((x) => Array.isArray(x) ? x : [x])); if (ks.some((k) => reqKeys().includes(k) && !NONEMPTY(B.vals[k]))) B.open[g.k] = true; }); }
      paint(); loadClaude(); preview(true);
    }
    // ---------------- field widgets
    function fieldHtml(k) {
      const F = FIELDS[k] || { l: k }, d = B.data, req = reqKeys().includes(k), v = B.vals[k] == null ? '' : B.vals[k], er = B.errs[k], lockd = d.locks && d.locks[k];
      const dflt = (d.required.find((r) => r.key === k) || {}).default;
      const lab = '<label for="wchx_' + k + '">' + esc(F.l) + (req ? ' <span class="rq">*</span>' : (F.opt ? ' <i>optional</i>' : '')) + (dflt && !B.dirtyOf(k) ? ' <i>default</i>' : '') + '</label>';
      const hint = er ? '<small class="er" data-er="' + k + '">' + esc(er) + '</small>' : (req && !NONEMPTY(v) ? '<small class="er" data-er="' + k + '">Required.' + (F.hint ? ' ' + esc(F.hint) : '') + '</small>' : (F.hint ? '<small data-er="' + k + '">' + esc(F.hint) + '</small>' : '<small data-er="' + k + '" hidden></small>'));
      let w;
      const cls = 'wchx-in' + (er || (req && !NONEMPTY(v)) ? ' err' : '') + (F.ro || lockd ? ' dis' : '');
      if (F.sel) w = '<select class="' + cls + '" id="wchx_' + k + '" data-k="' + k + '">' + (d.states || []).map((s) => '<option value="' + esc(s.name) + '" ' + (s.name === v ? 'selected' : '') + '>' + esc(s.name) + '</option>').join('') + (v && !(d.states || []).some((s) => s.name === v) ? '<option selected>' + esc(v) + '</option>' : '') + '<option value="" ' + (v ? '' : 'selected') + '>Choose the state</option></select>';
      else if (F.area) w = '<textarea class="' + cls + '" id="wchx_' + k + '" data-k="' + k + '" placeholder="' + esc(F.ph || '') + '" maxlength="600">' + esc(v) + '</textarea>';
      else { const inp = '<input class="' + cls + '" id="wchx_' + k + '" data-k="' + k + '" type="' + (F.type || 'text') + '" ' + (F.type === 'number' ? 'step="any" ' : '') + (F.inputmode ? 'inputmode="' + F.inputmode + '" ' : '') + (F.max && F.type !== 'number' ? 'maxlength="' + F.max + '" ' : '') + 'value="' + esc(v) + '" placeholder="' + esc(F.ph || '') + '" ' + (F.ro || lockd ? 'disabled ' : '') + 'autocomplete="off" ' + (F.up ? 'style="text-transform:uppercase" ' : '') + '>'; w = F.unit ? '<div class="wchx-unit">' + inp + '<span>' + esc(F.unit) + '</span></div>' : inp; }
      return '<div class="wchx-f">' + lab + w + hint + '</div>';
    }
    B.dirtyOf = (k) => String(B.vals[k] == null ? '' : B.vals[k]) !== String(B.base[k] == null ? '' : B.base[k]);
    function groupHtml(g) {
      const d = B.data, req = reqKeys(); let h = '';
      const keys = [].concat.apply([], (g.f || []).map((x) => Array.isArray(x) ? x : [x]));
      const rk = keys.filter((k) => req.includes(k)), rf = rk.filter((k) => NONEMPTY(B.vals[k])).length;
      const pill = g.look ? '<span class="wchx-pill">switches</span>' : rk.length ? '<span class="wchx-pill ' + (rf === rk.length ? 'g' : 'r') + '" data-gp="' + g.k + '">' + rf + ' of ' + rk.length + '</span>' : '<span class="wchx-pill" data-gp="' + g.k + '">optional</span>';
      let b = '';
      if (g.look) {
        b += SWITCH.map((s) => '<div class="wchx-sw"><div class="t">' + esc(s[1]) + '<small>' + esc(s[2]) + '</small></div><button type="button" class="wchx-tg ' + (num(B.vals[s[0]]) ? '' : 'off') + '" role="switch" aria-checked="' + (num(B.vals[s[0]]) ? 'true' : 'false') + '" aria-label="' + esc(s[1]) + '" data-sw="' + s[0] + '"></button></div>').join('');
        b += '<div class="wchx-sw"><div class="t">Bill size<small>The PDF sent on WhatsApp. The web link is always phone friendly.</small></div><div class="wchx-seg" data-size>' + [['a5', 'A5'], ['phone', 'Phone']].map((s) => '<button type="button" class="' + (B.vals.size === s[0] ? 'on' : '') + '" data-sz="' + s[0] + '">' + s[1] + '</button>').join('') + '</div></div>';
        b += '<div class="wchx-sw"><div class="t">Footer text<small>“Computer generated invoice…” and your terms</small></div><button type="button" class="wchx-tg ' + (num(B.vals.show_footer) ? '' : 'off') + '" role="switch" aria-checked="' + (num(B.vals.show_footer) ? 'true' : 'false') + '" aria-label="Footer text" data-sw="show_footer"></button></div>' + fieldHtml('footer');
      } else {
        b += (g.f || []).map((x) => Array.isArray(x) ? '<div class="wchx-g2 keep">' + x.map(fieldHtml).join('') + '</div>' : fieldHtml(x)).join('');
        if (g.k === 'business') b += logoHtml();
        if (g.series) { const ex = d.series && d.series.example || ''; b += '<div class="wchx-hl" style="margin-top:0"><b>First bill: <span data-ex>' + esc(seriesEx()) + '</span></b> · <span data-exl>' + seriesEx().length + '</span> of ' + num(d.series && d.series.max_len, 16) + ' characters<br><span class="sub">Financial year ' + esc((d.series && d.series.fy) || '') + ' is added by itself and restarts at 00001 each April. Numbers are never skipped or reused.</span></div><div class="wchx-lock">🔒 ' + (d.locks && d.locks.locked ? esc(d.locks.why || 'Locked: bills already exist.') : 'Prefix and next number lock after the first bill is made.') + '</div>'; }
      }
      return '<div class="wchx-acc' + (B.open[g.k] ? '' : ' closed') + '" data-g="' + g.k + '"><button type="button" class="h" data-tg="' + g.k + '" aria-expanded="' + (B.open[g.k] ? 'true' : 'false') + '">' + esc(g.t) + pill + '<span class="sp"></span><span class="cv">▾</span></button><div class="b">' + b + '</div></div>';
    }
    const seriesEx = () => { const p = String(B.vals.inv_prefix || 'TM').toUpperCase(), n = Math.max(1, parseInt(B.vals.inv_next, 10) || 1), fy = (B.data.series && B.data.series.fy) || '26-27'; return p + '/' + fy + '/' + String(n).padStart(5, '0'); };
    function logoHtml() {
      const has = (B.data.values.logo && !B.rmLogo) || B.logoData;
      return '<div class="wchx-f"><label>Logo <i>optional, PNG or JPG up to 600 KB</i></label><div class="row">' + (B.logoData ? '<img alt="New logo" src="' + esc(B.logoData) + '" style="width:48px;height:48px;border-radius:10px;object-fit:contain;border:1px solid #e3e8ef;background:#fff">' : '') + '<label class="wchx-btn s" style="cursor:pointer">' + (has ? 'Change logo' : 'Upload logo') + '<input type="file" accept="image/png,image/jpeg" data-logo style="display:none"></label>' + (has ? '<button type="button" class="wchx-btn s r" data-a="rmlogo">Remove</button>' : '') + (B.data.values.logo && !B.logoData && !B.rmLogo ? '<span class="wchx-pill g">logo saved</span>' : '') + '</div><small data-er="logo" hidden></small></div>';
    }
    function headHtml() {
      const d = B.data, f = filledLocal(), t = d.total, miss = missingLocal(), pct = Math.round(f * 100 / t);
      return '<div class="wchx-card ' + (f === t ? 'ok' : 'bad') + '" id="wchxHead"><h3>Billing details <span class="wchx-pill ' + (f === t ? 'g' : 'r') + '" data-cnt>' + f + ' of ' + t + ' required fields</span>' + (dirtyKeys().length ? '<span class="wchx-pill y">not saved yet</span>' : '') + '</h3><div class="wchx-prog"><i style="width:' + pct + '%"></i></div>'
        + '<div class="wchx-chk">' + d.required.map((r) => '<span class="' + (NONEMPTY(B.vals[r.key]) ? 'y' : 'n') + '">' + (NONEMPTY(B.vals[r.key]) ? '✓' : '✕') + ' ' + esc(r.label) + '</span>').join('') + '</div>'
        + (f === t ? '<div class="wchx-hl" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">All required details are filled. Salespeople can send carts' + (dirtyKeys().length ? ' once you press Save.' : '.') + '</div>' : '<div class="wchx-err" style="margin:10px 0 0">Salespeople cannot send a cart until all ' + t + ' are filled. Missing: <b>' + esc(miss.join(', ')) + '</b>. The red dot on the Billing tab and a notice stay until then.</div>') + '</div>';
    }
    function paint() {
      if (!B.data) return; const y = window.pageYOffset;
      el.innerHTML = '<div class="wchx-cols"><div id="wchxForm"><div id="wchxHeadBox">' + headHtml() + '</div><div class="wchx-sec" style="margin-top:4px">Fill in</div>' + GROUPS.map(groupHtml).join('') + '<div id="wchxSave"></div><div id="wchxClaude"></div></div>'
        + '<div class="stick"><div class="wchx-card"><h3>Live preview <span class="wchx-pill b" id="wchxPvTag">sample order</span></h3><div class="sub" style="margin-bottom:8px">Changes in the form show here at once. Nothing is saved until you press Save.</div><div class="row" style="margin-bottom:10px"><div class="wchx-seg" data-ps>' + [['Gujarat', 'Gujarat'], ['Maharashtra', 'Other state']].map((s) => '<button type="button" class="' + (B.pstate === s[0] ? 'on' : '') + '" data-pstate="' + s[0] + '">' + s[1] + '</button>').join('') + '</div><div class="wchx-seg" data-pz>' + [['phone', 'Phone'], ['a5', 'A5']].map((s) => '<button type="button" class="' + (B.vals.size === s[0] ? 'on' : '') + '" data-sz="' + s[0] + '">' + s[1] + '</button>').join('') + '</div></div><div id="wchxPv"></div></div></div></div>';
      bind(); paintSave(); paintClaude(); paintPv(); window.scrollTo(0, y);
    }
    function updLive() {
      const hb = $('#wchxHeadBox'); if (hb) hb.innerHTML = headHtml();
      GROUPS.forEach((g) => { const p = el.querySelector('[data-gp="' + g.k + '"]'); if (!p) return; const keys = [].concat.apply([], (g.f || []).map((x) => Array.isArray(x) ? x : [x])), rk = keys.filter((k) => reqKeys().includes(k)); if (!rk.length) return; const rf = rk.filter((k) => NONEMPTY(B.vals[k])).length; p.textContent = rf + ' of ' + rk.length; p.className = 'wchx-pill ' + (rf === rk.length ? 'g' : 'r'); });
      const ex = el.querySelector('[data-ex]'); if (ex) { ex.textContent = seriesEx(); const l = el.querySelector('[data-exl]'); if (l) l.textContent = seriesEx().length; }
      paintSave();
    }
    function paintSave() {
      const b = $('#wchxSave'); if (!b) return; const n = [...new Set(dirtyKeys())].length;
      b.innerHTML = n ? '<div class="wchx-savebar"><span style="flex:1;min-width:140px;font-weight:700">' + n + ' change' + (n === 1 ? '' : 's') + ' not saved</span><button type="button" class="wchx-btn" data-a="discard" ' + (B.saving ? 'disabled' : '') + '>Discard</button><button type="button" class="wchx-btn p" data-a="save" ' + (B.saving ? 'disabled' : '') + '>' + (B.saving ? 'Saving…' : 'Save changes') + '</button></div>' : '';
    }
    // ---------------- events
    function setVal(k, v) {
      B.vals[k] = v; if (B.errs[k]) { delete B.errs[k]; const s = el.querySelector('[data-er="' + k + '"]'); if (s) { s.hidden = true; s.className = ''; } const i = el.querySelector('#wchx_' + k); if (i) i.classList.remove('err'); }
      const req = reqKeys().includes(k), i = el.querySelector('#wchx_' + k); if (i && req) i.classList.toggle('err', !NONEMPTY(v));
      updLive(); prevSoon();
    }
    function bind() {
      el.onclick = async (e) => {
        const t = e.target.closest('[data-tg],[data-sw],[data-sz],[data-pstate],[data-a]'); if (!t) return;
        if (t.dataset.tg) { const k = t.dataset.tg, a = el.querySelector('[data-g="' + k + '"]'); B.open[k] = a.classList.contains('closed'); a.classList.toggle('closed', !B.open[k]); t.setAttribute('aria-expanded', B.open[k] ? 'true' : 'false'); return; }
        if (t.dataset.sw) { const k = t.dataset.sw, on = !num(B.vals[k]); B.vals[k] = on ? 1 : 0; t.classList.toggle('off', !on); t.setAttribute('aria-checked', on ? 'true' : 'false'); updLive(); prevSoon(true); return; }
        if (t.dataset.sz) { B.vals.size = t.dataset.sz; B.pv.size = t.dataset.sz; el.querySelectorAll('[data-sz]').forEach((x) => x.classList.toggle('on', x.dataset.sz === B.vals.size)); updLive(); prevSoon(true); return; }
        if (t.dataset.pstate) { B.pstate = t.dataset.pstate; el.querySelectorAll('[data-pstate]').forEach((x) => x.classList.toggle('on', x.dataset.pstate === B.pstate)); prevSoon(true); return; }
        const a = t.dataset.a;
        if (a === 'reload') return load();
        if (a === 'save') return save();
        if (a === 'discard') { B.vals = Object.assign({}, B.base); B.logoData = null; B.rmLogo = false; B.errs = {}; paint(); preview(true); return; }
        if (a === 'rmlogo') { B.logoData = null; B.rmLogo = true; paint(); prevSoon(true); return; }
        if (a === 'pvretry') return preview(true);
        claudeAct(t);
      };
      el.oninput = (e) => { const t = e.target; if (t.dataset && t.dataset.k) { let v = t.value; const F = FIELDS[t.dataset.k] || {}; if (F.up) { const p = t.selectionStart; v = v.toUpperCase(); if (v !== t.value) { t.value = v; try { t.setSelectionRange(p, p); } catch (er) { } } } setVal(t.dataset.k, v); } };
      el.onchange = (e) => {
        const t = e.target;
        if (t.dataset && t.dataset.k === 'state') { const s = ((B.data.states || []).find((x) => x.name === t.value) || {}); setVal('state', t.value); if (s.code) { B.vals.state_code = s.code; const c = el.querySelector('#wchx_state_code'); if (c) c.value = s.code; updLive(); } return; }
        if (t.dataset && t.dataset.logo !== undefined && t.files && t.files[0]) {
          const f = t.files[0]; if (f.size > 600 * 1024) { toast('The logo is bigger than 600 KB. Use a smaller image.', true); t.value = ''; return; }
          const r = new FileReader(); r.onload = () => { B.logoData = String(r.result); B.rmLogo = false; paint(); prevSoon(true); }; r.onerror = () => toast('Could not read that file.', true); r.readAsDataURL(f);
        }
        if (t.dataset && t.dataset.cq) { }
      };
    }
    // ---------------- save
    async function save() {
      if (B.saving) return; const ks = [...new Set(dirtyKeys())].filter((k) => k !== 'logo'); const body = {}; ks.forEach((k) => { body[k] = B.vals[k]; }); if (B.logoData) body.logo_data = B.logoData; if (B.rmLogo) body.remove_logo = true;
      B.saving = true; paintSave(); const r = await soft(() => A.post('wch.billing', body)); B.saving = false; if (dead || !alive(el)) return;
      if (!r.ok) {
        const er = r.e && r.e.data && r.e.data.errors; B.errs = er && typeof er === 'object' ? er : {};
        if (codeOf(r.e) === 'locked') toast(r.err, true); else if (Object.keys(B.errs).length) toast('Some fields need a fix. They are marked in red.', true); else toast(r.err, true);
        paint(); Object.keys(B.errs).forEach((k) => { const g = GROUPS.find((x) => [].concat.apply([], (x.f || []).map((y) => Array.isArray(y) ? y : [y])).includes(k)); if (g) { B.open[g.k] = true; } }); paint(); const first = el.querySelector('.wchx-in.err'); if (first) { try { first.scrollIntoView({ block: 'center' }); } catch (e) { } } return;
      }
      B.errs = {}; B.data = r.d; B.base = Object.assign({}, r.d.values); B.vals = Object.assign({}, r.d.values); B.logoData = null; B.rmLogo = false; B.pv.size = B.vals.size || 'phone'; notify(r.d); toast(r.d.complete ? 'Saved. Billing is complete.' : 'Saved. ' + r.d.filled + ' of ' + r.d.total + ' required fields are filled.'); paint(); loadClaude(); preview(true);
    }
    // ---------------- live preview
    const prevSoon = sleepless((now) => preview(true), 450);
    async function preview(force) {
      if (!A || !B.data) return; const seq = ++B.pv.seq; B.pv.busy = true; paintPvTag();
      const vals = Object.assign({}, B.vals); const r = await soft(() => A.post('wch.bill_preview', { sample: true, values: vals, state: B.pstate }));
      if (dead || !alive(el) || seq !== B.pv.seq) return; B.pv.busy = false;
      if (!r.ok) { B.pv.err = r.err; paintPv(); return; } B.pv.err = ''; B.pv.html = r.d.html || ''; B.pv.size = r.d.size || B.vals.size || 'phone'; B.pv.warn = r.d.warnings || []; paintPv();
    }
    function paintPvTag() { const t = $('#wchxPvTag'); if (t) { t.textContent = B.pv.busy ? 'updating…' : 'sample order'; } }
    function paintPv() {
      const box = $('#wchxPv'); if (!box) return; paintPvTag();
      if (!B.pv.html && !B.pv.err) { box.innerHTML = skel(3); return; }
      let h = ''; if (B.pv.err) h += '<div class="wchx-err">Could not update the preview: ' + esc(B.pv.err) + ' <button type="button" class="wchx-btn s" data-a="pvretry">Try again</button></div>';
      if (B.pv.html) h += '<div class="wchx-pvw" id="wchxPvw"><iframe sandbox="allow-same-origin" title="Bill preview" id="wchxFr" scrolling="no"></iframe></div>';
      box.innerHTML = h; const fr = $('#wchxFr'); if (!fr) return; const nat = B.pv.size === 'a5' ? 560 : 390; fr.style.width = nat + 'px'; fr.style.height = '600px';
      const fit = () => { const w = $('#wchxPvw'); if (!w || !alive(fr)) return; let hh = 600; try { fr.style.height = '10px'; const d = fr.contentDocument; hh = Math.max(120, Math.ceil(d.documentElement.scrollHeight)); } catch (e) { } fr.style.height = hh + 'px'; const avail = w.clientWidth - 20, sc = Math.min(1, avail / nat); fr.style.transform = 'scale(' + sc + ')'; w.style.height = Math.ceil(hh * sc + 20) + 'px'; };
      fr.onload = fit; fr.srcdoc = B.pv.html; if (window.ResizeObserver) { const ro = new ResizeObserver(() => fit()); ro.observe($('#wchxPvw')); }
    }
    // ---------------- Claude
    async function loadClaude() {
      const r = await soft(() => A.get('wch.claude_state', { area: 'billing' })); if (dead || !alive(el)) return; B.claude = r.ok ? r.d : null; B.cerr = r.ok ? '' : r.err; paintClaude();
    }
    function paintClaude() {
      const box = $('#wchxClaude'); if (!box) return; const c = B.claude;
      if (!c) { box.innerHTML = '<div class="wchx-sec">Claude</div>' + (B.cerr ? errCard(B.cerr, 'cretry') : skel(2)); return; }
      let h = '<div class="wchx-sec">Claude</div><div class="wchx-card ' + (c.confirmed ? 'ok' : 'ask') + '"><div class="wchx-sum"><b class="t">WHAT I UNDERSTOOD</b>' + ((c.understood || []).length ? '<ul>' + c.understood.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '<div class="sub">Nothing to summarise yet. Fill in the fields above.</div>') + '</div>';
      h += '<div class="row" style="margin:6px 0 4px">' + (c.confirmed ? '<span class="wchx-pill g">✓ You said this is right</span>' : '<button type="button" class="wchx-btn g" data-a="cright">✓ Right</button>') + '<button type="button" class="wchx-btn" data-a="cnot">Not quite</button><button type="button" class="wchx-btn" data-a="cchange">✎ Change it</button></div>';
      if (B.cmode) {
        const it = B.cnext; if (B.cbusy) h += skel(1);
        else if (!it) h += '<div class="wchx-hl">Nothing else to ask. Use <b>Change it</b> to fix a field above yourself.</div>';
        else h += '<div class="wchx-q"><small>' + esc(it.question) + (c.pending ? ' <span class="wchx-pill y">' + c.pending + ' left</span>' : '') + '</small>' + (it.why ? '<div class="sub" style="margin-bottom:6px">' + esc(it.why) + '</div>' : '')
          + (it.kind === 'choice' || (it.chips && it.chips.length) ? '<div class="wchx-chips">' + it.chips.map((ch) => '<button type="button" class="wchx-ch" data-a="cans" data-v="' + esc(ch.key) + '">' + esc(ch.label) + '</button>').join('') + '</div>' : '<div class="row" style="flex-wrap:nowrap"><input class="wchx-in" id="wchxCa" value="' + esc(B.cans) + '" placeholder="Type the answer" style="flex:1"><button type="button" class="wchx-btn p" data-a="cans">Save answer</button></div>') + '<div class="row" style="margin-top:6px"><button type="button" class="wchx-btn s" data-a="cskip">Ask me later</button></div></div>';
        if (B.cqerr) h += '<div class="wchx-err">' + esc(B.cqerr) + '</div>';
      }
      h += '<div class="sub" style="margin-top:6px">Claude is optional. Without it you still get this summary from plain rules; only the questions are less clever.</div></div>';
      box.innerHTML = h;
    }
    async function claudeAct(t) {
      const a = t.dataset.a; if (a === 'cretry') return loadClaude();
      if (a === 'cright') { const r = await soft(() => A.post('wch.claude_state', { area: 'billing', confirm: true })); if (r.ok) { B.claude = r.d; toast('Thanks. Saved as confirmed.'); } else toast(r.err, true); return paintClaude(); }
      if (a === 'cchange') { const f = el.querySelector('.wchx-acc'); if (f) f.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
      if (a === 'cnot') { B.cmode = true; return nextQ(); }
      if (a === 'cskip') { if (B.cnext) await soft(() => A.post('wch.claude_answer', { id: B.cnext.id, skip: true })); return nextQ(); }
      if (a === 'cans') {
        const it = B.cnext; if (!it) return; const v = t.dataset.v != null ? t.dataset.v : (($('#wchxCa') || {}).value || '').trim(); if (!v) { B.cqerr = 'Type the answer first.'; return paintClaude(); }
        t.disabled = true; B.cqerr = ''; const r = await soft(() => A.post('wch.claude_answer', { id: it.id, answer: v })); if (dead || !alive(el)) return;
        if (!r.ok) { B.cqerr = r.err; B.cans = v; return paintClaude(); }
        B.cans = ''; toast('Saved.'); B.dirtyKeep = new Set(dirtyKeys()); B.dirtyKeep.delete(it.field); await load(true); B.cmode = true; return nextQ();
      }
    }
    async function nextQ() { B.cbusy = true; B.cqerr = ''; paintClaude(); const r = await soft(() => A.get('wch.claude_next', { area: 'billing' })); if (dead || !alive(el)) return; B.cbusy = false; B.cnext = r.ok ? r.d.item : null; if (!r.ok) B.cqerr = r.err; if (r.ok && B.claude) B.claude.pending = r.d.pending; paintClaude(); }

    load();
    return { reload: () => load(), destroy: () => { dead = true; } };
  }
  async function billingState(o) { const A = apiOf(o); if (!A) return null; try { const d = await A.get('wch.billing', {}); return { filled: d.filled, total: d.total, complete: d.complete, red_dot: d.red_dot, missing: d.missing || [] }; } catch (e) { return null; } }

  // ================================================================== CATALOG SYNC & MAPPING
  const PROB = { no_size: ['Perfumes without a size', 'Cannot go in a cart until a size (ml) is added in Shopify'], no_price: ['Products without a price', 'Hidden from the cart until fixed'], no_stock: ['Out of stock in every size', 'Greyed out in search'], chain_no_photo: ['Chains without a photo', 'Cannot go in the options (the customer needs a photo)'], unclear_kind: ['Products I cannot place', 'Neither a perfume nor a chain. Answer the questions below.'] };
  const ruleText = (r) => { r = r || {}; const p = []; if (r.collection) p.push('collection “' + r.collection + '”'); if (r.type) p.push('product type “' + r.type + '”'); if (r.tag) p.push('tag “' + r.tag + '”'); return p.length ? p.join(' + ') : 'not set yet'; };

  function catalog(el, o) {
    injectCss(); o = o || {}; const A = apiOf(o); if (!el) return null; el.classList.add('wchx');
    const C = { st: null, stErr: '', map: null, mapErr: '', cov: null, covErr: '', cl: null, clErr: '', next: null, nextBusy: false, nextErr: '', sync: { busy: false, msg: '', bad: false, last: null }, scope: null, scopeBusy: false, change: false, pick: {}, show: {}, cfg: null, loaded: false, ans: '' };
    let dead = false; const $ = (s) => el.querySelector(s);

    async function loadAll() {
      if (!A) { el.innerHTML = errCard('You are not logged in. Reload the page.'); return; }
      if (!C.loaded) el.innerHTML = '<div class="wchx-sec" style="margin-top:0">Catalog sync and mapping</div>' + skel(4);
      const [st, mp, cv, cl, nx, cfg] = await Promise.all([soft(() => A.get('wch.sync_status', {})), soft(() => A.get('wch.mapping', {})), soft(() => A.get('wch.coverage', {})), soft(() => A.get('wch.claude_state', { area: 'catalog' })), soft(() => A.get('wch.claude_next', { area: 'catalog' })), soft(() => A.get('wch.settings', {}))]);
      if (dead || !alive(el)) return; C.loaded = true;
      C.st = st.ok ? st.d : null; C.stErr = st.ok ? '' : st.err; C.map = mp.ok ? mp.d : null; C.mapErr = mp.ok ? '' : mp.err; C.cov = cv.ok ? cv.d : null; C.covErr = cv.ok ? '' : cv.err; C.cl = cl.ok ? cl.d : null; C.clErr = cl.ok ? '' : cl.err; C.next = nx.ok ? nx.d : null; C.nextErr = nx.ok ? '' : nx.err; C.cfg = cfg.ok ? cfg.d.settings : null;
      paint();
    }
    function pill(cls, t) { return '<span class="wchx-pill ' + cls + '">' + esc(t) + '</span>'; }
    function syncCard() {
      const s = C.st; if (!s) return '<div class="wchx-card bad"><h3>Catalog sync</h3>' + errBox(C.stErr, 'reload') + refreshBtn(false) + '</div>';
      const never = !s.last_sync_at, fresh = !never && s.last_ok; const every = C.cfg && C.cfg.wch_sync_every_min ? Math.max(1, Math.round(C.cfg.wch_sync_every_min / 60)) : null;
      let h = '<div class="wchx-card' + (s.last_ok === false ? ' bad' : '') + '"><h3>Catalog sync ' + (never ? pill('r', 'Never synced') : s.last_ok === false ? pill('r', 'Last sync failed') : pill('g', 'Synced ' + agoMin(s.synced_ago_min))) + '</h3>';
      h += '<div class="sub">' + (never ? 'The catalog has not been read from Shopify yet. Press Refresh now.' : num(s.counts.products).toLocaleString('en-IN') + ' products and ' + num(s.counts.variants).toLocaleString('en-IN') + ' variants (size, price, stock, photo) from your store.') + (every ? ' It also refreshes by itself every ' + every + (every === 1 ? ' hour.' : ' hours.') : '') + '</div>';
      if (s.error) h += '<div class="wchx-err" style="margin-top:8px">' + esc(s.error) + '</div>' + (s.error_help ? '<div class="wchx-note" style="margin:6px 0 0"><b>What to do:</b> ' + esc(s.error_help) + '</div>' : '');
      if (s.in_progress) h += '<div class="wchx-hl">A refresh is running right now. This page shows the result when you press Try again or reload.</div>';
      h += refreshBtn(true);
      const sc = s.scope || {}, dr = C.scope ? C.scope.scope.draft_orders : sc.draft_orders;
      if (dr === false) h += '<div class="wchx-note" style="margin-bottom:0"><b>Checkout links need one more permission.</b> ' + esc((C.scope && C.scope.how) || 'In Shopify go to Settings > Apps > Develop apps, open the HRMS app, add the scopes write_draft_orders and read_draft_orders, save and re-install the app.') + (C.scope && C.scope.missing && C.scope.missing.length ? ' Missing: <b>' + esc(C.scope.missing.join(', ')) + '</b>.' : '') + ' Until then, carts cannot be sent.</div>';
      else if (C.scope && C.scope.ok) h += '<div class="wchx-hl" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">Shopify permissions are fine for products, draft orders and orders.</div>';
      h += '<div class="row" style="margin-top:8px"><button type="button" class="wchx-btn s" data-a="scope" ' + (C.scopeBusy ? 'disabled' : '') + '>' + (C.scopeBusy ? 'Checking…' : 'Check Shopify permissions') + '</button></div></div>';
      return h;
    }
    const errBox = (m, a) => '<div class="wchx-err" style="margin-top:8px">' + esc(m) + (a ? ' <button type="button" class="wchx-btn s" data-a="' + a + '">Try again</button>' : '') + '</div>';
    function refreshBtn(withMsg) { return '<div class="row" style="margin-top:10px"><button type="button" class="wchx-btn p" data-a="refresh" ' + (C.sync.busy ? 'disabled' : '') + '>' + (C.sync.busy ? '↻ Reading Shopify…' : '↻ Refresh now') + '</button>' + (C.sync.last && Number(C.sync.last.ms) > 0 ? '<span class="sub">Last read took ' + Math.max(1, Math.round(C.sync.last.ms / 1000)) + ' seconds</span>' : '') + '</div>' + (C.sync.msg ? '<div class="' + (C.sync.bad ? 'wchx-err' : 'wchx-hl') + '" style="margin-top:8px">' + esc(C.sync.msg) + '</div>' : ''); }
    function typesCard() {
      const s = C.st; if (!s || !s.segs) return ''; const g = s.segs, L = [['perfume', '🧴 Perfume'], ['attar', '🫙 Attar'], ['gel', '🚿 Shower gel'], ['other', '➕ Others'], ['chain', '⛓ Chain']];
      return '<div class="wchx-card"><h3>Product types</h3><div class="sub" style="margin-bottom:8px">How the cart and the Price chart see your Shopify products (from the product name, type and tags).</div><div class="row" style="gap:6px;flex-wrap:wrap">'
        + L.map(([k, l]) => '<span class="wchx-pill ' + (g[k] ? 'g' : '') + '" style="font-size:13px;padding:4px 10px">' + l + ' <b>' + num(g[k]).toLocaleString('en-IN') + '</b></span>').join('') + '</div></div>';
    }
    function mapCard() {
      const m = C.map; if (!m) return '<div class="wchx-card bad"><h3>What is a chain, what is a perfume</h3>' + errBox(C.mapErr, 'reload') + '</div>';
      const cur = m.current || {}, cn = m.counts || {};
      let h = '<div class="wchx-card ' + (m.confirmed ? 'ok' : 'ask') + '"><h3>What is a chain, what is a perfume ' + (m.confirmed ? pill('g', 'confirmed') : pill('y', 'please confirm once')) + '</h3>';
      h += '<div class="wchx-kv" style="align-items:flex-start"><div class="mid"><b>Chains = ' + esc(ruleText(cur.chain)) + '</b><span class="sub">' + (m.confirmed ? 'you confirmed it' : 'auto-detected') + ' · ' + num(cn.chains) + ' products</span></div>' + pill(m.confirmed ? 'g' : 'o', m.confirmed ? 'set' : 'auto') + '</div>';
      h += '<div class="wchx-kv" style="align-items:flex-start"><div class="mid"><b>Perfumes = ' + esc(ruleText(cur.perfume)) + '</b><span class="sub">' + (m.confirmed ? 'you confirmed it' : 'auto-detected') + ' · ' + num(cn.perfumes) + ' products</span></div>' + pill(m.confirmed ? 'g' : 'o', m.confirmed ? 'set' : 'auto') + '</div>';
      if (C.change) {
        const cand = m.candidates || {};
        const grp = (kind, lab) => '<div class="wchx-q"><small>' + lab + '</small>' + ((cand[kind] || []).length ? '<div class="wchx-chips">' + cand[kind].map((c) => { const on = C.pick[kind] ? C.pick[kind] === c.kind + ':' + c.key : (cur[kind] && cur[kind][c.kind] === c.key); return '<button type="button" class="wchx-ch ' + (on ? 'on' : '') + '" data-a="pickc" data-kind="' + kind + '" data-ck="' + esc(c.kind + ':' + c.key) + '">' + esc(c.label) + ' · ' + num(c.products) + '</button>'; }).join('') + '</div>' : '<div class="sub">No other candidates found. The sync found nothing to pick from.</div>') + '</div>';
        h += grp('chain', 'Which one means CHAINS?') + grp('perfume', 'Which one means PERFUMES?') + '<div class="row"><button type="button" class="wchx-btn g" data-a="mapsave">✓ Save this</button><button type="button" class="wchx-btn" data-a="mapcancel">Cancel</button></div>';
      } else h += '<div class="row" style="margin-top:8px"><button type="button" class="wchx-btn g" data-a="mapok">✓ ' + (m.confirmed ? 'Confirm again' : 'Both are right') + '</button><button type="button" class="wchx-btn" data-a="mapchg">Change</button></div>';
      if (C.mapMsg) h += '<div class="' + (C.mapBad ? 'wchx-err' : 'wchx-hl') + '" style="margin-top:8px">' + esc(C.mapMsg) + '</div>';
      h += '<div class="sub" style="margin-top:8px">' + num(cn.other) + ' products match neither (candles, gift boxes). They never show in the cart search.</div>';
      if ((m.overrides || []).length) h += '<div class="wchx-sec" style="margin-top:12px">Products you placed by hand</div>' + m.overrides.map((x) => '<div class="wchx-ovr"><b class="wchx-name" style="flex:1;min-width:140px">' + esc(x.title) + '</b>' + pill('b', x.kind) + '<button type="button" class="wchx-btn s" data-a="ovr" data-pid="' + x.pid + '">Back to automatic</button></div>').join('');
      return h + '</div>';
    }
    function sizesCard() {
      const s = C.st; if (!s) return ''; const noSize = (s.problems || []).filter((p) => p.kind === 'no_size').length;
      return '<div class="wchx-card"><h3>Sizes I recognised</h3>' + ((s.sizes || []).length || noSize ? '<div class="wchx-sizes">' + (s.sizes || []).map((z) => '<span><b>' + esc(z.ml) + ' ml</b>' + num(z.variants).toLocaleString('en-IN') + '</span>').join('') + (noSize ? '<span class="w"><b>no size</b>' + noSize + (s.problems_total > s.problems.length ? '+' : '') + '</span>' : '') + '</div>' : '<div class="sub">No sizes yet. Press Refresh now.</div>') + '<div class="sub" style="margin-top:8px">The cart buttons are <b>20 / 50 / 100</b>. Other sizes are listed here but are not offered as buttons.</div></div>';
    }
    function probCard() {
      const s = C.st; if (!s) return ''; const g = {}; (s.problems || []).forEach((p) => { (g[p.kind] = g[p.kind] || []).push(p); }); const kinds = Object.keys(g);
      let h = '<div class="wchx-card"><h3>Problems to fix ' + (kinds.length ? pill('r', String(s.problems_total)) : pill('g', 'none')) + '</h3>';
      if (!kinds.length) return h + '<div class="sub">Nothing to fix. Every product has a size, a price, stock and (for chains) a photo.</div></div>';
      kinds.forEach((k) => { const L = PROB[k] || [k, '']; h += '<div class="wchx-prob"><span class="n ' + (k === 'no_stock' ? 'y' : '') + '">' + g[k].length + '</span><div class="mid">' + esc(L[0]) + '<small>' + esc(g[k].slice(0, 2).map((x) => x.title).join(', ') + (g[k].length > 2 ? ' …' : '')) + ' · ' + esc(L[1]) + '</small></div><button type="button" class="wchx-btn s" data-a="showp" data-k="' + k + '">' + (C.show[k] ? 'Hide' : 'Show') + '</button></div>' + (C.show[k] ? '<div style="padding:0 0 8px 44px">' + g[k].map((x) => '<div class="wchx-ovr"><span class="wchx-name" style="flex:1;min-width:140px">' + esc(x.title) + '<span class="sub"> · ' + esc(x.text) + '</span></span>' + (k === 'no_size' || k === 'unclear_kind' ? '<button type="button" class="wchx-btn s" data-a="place" data-pid="' + x.pid + '" data-kind="perfume">It is a perfume</button><button type="button" class="wchx-btn s" data-a="place" data-pid="' + x.pid + '" data-kind="chain">A chain</button><button type="button" class="wchx-btn s" data-a="place" data-pid="' + x.pid + '" data-kind="other">Hide it</button>' : '') + '</span></div>').join('') + '</div>' : ''); });
      if (s.problems_total > s.problems.length) h += '<div class="sub">Showing the first ' + s.problems.length + ' of ' + s.problems_total + '.</div>';
      return h + '</div>';
    }
    function covCard() {
      const c = C.cov; if (!c) return '<div class="wchx-card bad"><h3>Chain price coverage</h3>' + errBox(C.covErr, 'reload') + '</div>';
      const max = Math.max(1, ...(c.slabs || []).map((s) => s.count)); const empty = (c.slabs || []).filter((s) => s.empty);
      let h = '<div class="wchx-card"><h3>Chain price coverage ' + (empty.length ? pill('r', empty.length + ' empty slab' + (empty.length === 1 ? '' : 's')) : pill('g', 'every slab covered')) + '</h3><div class="sub" style="margin-bottom:6px">Chains by MRP. Slab step ' + fm(c.step) + ' (setting). ' + num(c.in_stock_chains) + ' of ' + num(c.total_chains) + ' chains are in stock.</div>';
      if (!c.slabs.length) h += '<div class="sub">No slabs to show.</div>';
      h += c.slabs.map((s) => '<div class="wchx-slab ' + (s.empty ? 'empty' : '') + '"><span class="rg">' + fm(s.from) + ' to ' + num(s.to).toLocaleString('en-IN') + '</span><span class="bar"><i style="width:' + Math.round(s.count * 100 / max) + '%"></i></span><span class="ct">' + s.count + '</span></div>').join('');
      if (empty.length) h += '<div class="wchx-cl" style="margin-top:10px"><div class="hd"><span class="sp2">✦</span>Claude suggests</div><p>' + esc(c.text || '') + '</p>' + empty.slice(0, 4).map((s) => '<p style="margin-top:6px">' + esc(s.suggestion) + '</p>').join('') + '</div>'; else h += '<div class="wchx-hl" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">' + esc(c.text || 'Every slab has chains. Nothing to upload.') + '</div>';
      return h + '</div>';
    }
    function qCard() {
      const n = C.next; if (!n) return C.nextErr ? '<div class="wchx-card bad"><h3>Claude has a question</h3>' + errBox(C.nextErr, 'reload') + '</div>' : '';
      const it = n.item; if (!it) return '<div class="wchx-card ok"><h3>Claude has no questions ' + pill('g', 'all clear') + '</h3><div class="sub">Every product is placed. If something looks wrong, change it in Problems to fix.</div></div>';
      return '<div class="wchx-card ask"><h3>Claude has a question ' + pill('y', n.pending + ' left') + '</h3><div class="wchx-q" style="margin-top:6px"><small>' + esc(it.question) + '</small>' + (it.why ? '<div class="sub" style="margin-bottom:8px">' + esc(it.why) + '</div>' : '') + '<div class="wchx-chips">' + (it.chips || []).map((c) => '<button type="button" class="wchx-ch" data-a="qans" data-v="' + esc(c.key) + '" ' + (C.nextBusy ? 'disabled' : '') + '>' + esc(c.label) + '</button>').join('') + '<button type="button" class="wchx-ch" data-a="qskip" ' + (C.nextBusy ? 'disabled' : '') + '>Ask me later</button></div></div>' + (C.qerr ? '<div class="wchx-err">' + esc(C.qerr) + '</div>' : '') + '</div>';
    }
    function chainsRead(c) {
      const L = c.chains || []; if (!L.length) return '';
      return '<div class="wchx-sec" style="margin-top:12px">How I read your chains</div>' + L.slice(0, 8).map((x) => '<div class="wchx-kv"><div class="mid"><b class="wchx-name">' + esc(x.title) + '</b><span class="sub">' + esc([x.style, x.metal, x.price != null ? fm(x.price) : '', x.slab ? 'slab ' + x.slab : ''].filter(Boolean).join(' \u00b7 ')) + '</span></div></div>').join('') + (L.length > 8 ? '<div class="sub" style="margin-top:6px">' + (L.length - 8) + ' more read. Correct me on anything that looks wrong.</div>' : '<div class="sub" style="margin-top:6px">Correct me on anything that looks wrong.</div>');
    }
    function readCard() {
      const c = C.cl; if (!c) return C.clErr ? '<div class="wchx-card bad"><h3>How I read your catalog</h3>' + errBox(C.clErr, 'reload') + '</div>' : '';
      return '<div class="wchx-card ' + (c.confirmed ? 'ok' : '') + '"><h3>How I read your catalog ' + pill('p', 'used for suggestions') + '</h3>' + ((c.understood || []).length ? '<div class="wchx-sum"><b class="t">WHAT I UNDERSTOOD</b><ul>' + c.understood.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul></div>' : '<div class="sub">Nothing yet. Press Refresh now so I can read your products.</div>') + '<div class="row" style="margin-top:6px">' + (c.confirmed ? pill('g', '✓ You said this is right') : '<button type="button" class="wchx-btn g" data-a="clright">✓ Right</button>') + '<span class="sub">Correct me on anything that looks wrong: answer my questions above or change a product under Problems.</span></div>' + (!c.ai ? '<div class="sub" style="margin-top:6px">Claude is optional. This summary comes from plain rules.</div>' : '') + chainsRead(c) + '</div>';
    }
    function paint() {
      const y = window.pageYOffset;
      el.innerHTML = '<div class="wchx-sec" style="margin-top:0">Products</div>' + syncCard() + typesCard() + qCard() + probCard()
        + '<details class="wchx-more" style="margin-top:12px"><summary class="wchx-sec" style="cursor:pointer">More details (chain / perfume mapping, sizes, chain price coverage)</summary>' + mapCard() + sizesCard() + covCard() + readCard() + '</details>';
      el.onclick = onClick; window.scrollTo(0, y);
    }
    async function refresh() {
      if (C.sync.busy) return; C.sync.busy = true; C.sync.msg = ''; C.sync.bad = false; paint(); let r = null, n = 0;
      try { do { r = await A.post('wch.sync', n ? { continue: 1 } : {}); n++; if (r && r.result && r.result.incomplete && !dead) { C.sync.msg = 'Reading Shopify… ' + num((r.result.pages || 0) * 20) + ' products so far — keep this page open.'; paint(); } } while (r && r.result && r.result.incomplete && n < 40 && !dead);   // every next round CONTINUES where the last stopped (it used to start again from the first page)
        C.sync.last = r.result; C.sync.msg = r.result.incomplete ? 'Still reading — press Refresh now again; it goes on from where it stopped.' : 'Done: ' + num(r.result.added) + ' new, ' + num(r.result.updated) + ' changed, ' + num(r.result.deactivated) + ' removed.'; }
      catch (e) { C.sync.bad = true; C.sync.msg = codeOf(e) === 'busy' ? 'A refresh is already running. Wait a minute and look again.' : E(e); }
      C.sync.busy = false; if (dead || !alive(el)) return; await loadAll();
    }
    async function onClick(e) {
      const t = e.target.closest('[data-a]'); if (!t || t.disabled) return; const a = t.dataset.a;
      if (a === 'reload') return loadAll();
      if (a === 'refresh') return refresh();
      if (a === 'scope') { C.scopeBusy = true; paint(); const r = await soft(() => A.post('wch.scope_check', {})); C.scopeBusy = false; if (r.ok) C.scope = r.d; else toast(r.err, true); return paint(); }
      if (a === 'mapchg') { C.change = true; C.pick = {}; return paint(); }
      if (a === 'mapcancel') { C.change = false; C.mapMsg = ''; return paint(); }
      if (a === 'pickc') { C.pick[t.dataset.kind] = t.dataset.ck; return paint(); }
      if (a === 'mapok' || a === 'mapsave') {
        const m = C.map; const rule = (kind) => { const base = Object.assign({ collection: '', type: '', tag: '' }, {}); const pk = C.pick[kind]; if (pk) { const [k, ...r] = pk.split(':'); base[k] = r.join(':'); return base; } return Object.assign(base, m.current[kind] || {}); };
        const r = await soft(() => A.post('wch.mapping', { chain: rule('chain'), perfume: rule('perfume'), confirm: true })); if (dead || !alive(el)) return;
        if (r.ok) { C.map = r.d; C.change = false; C.mapMsg = 'Saved. ' + num(r.d.reclassified || 0) + ' products were re-sorted.'; C.mapBad = false; toast('Mapping confirmed.'); const [st, cv] = await Promise.all([soft(() => A.get('wch.sync_status', {})), soft(() => A.get('wch.coverage', {}))]); if (st.ok) C.st = st.d; if (cv.ok) C.cov = cv.d; } else { C.mapMsg = r.err; C.mapBad = true; } return paint();
      }
      if (a === 'ovr' || a === 'place') {
        t.disabled = true; const r = await soft(() => A.post('wch.mapping', { overrides: [{ pid: +t.dataset.pid, kind: a === 'ovr' ? 'auto' : t.dataset.kind }] })); if (r.ok) { C.map = r.d; toast('Done.'); const [st, cv] = await Promise.all([soft(() => A.get('wch.sync_status', {})), soft(() => A.get('wch.coverage', {}))]); if (st.ok) C.st = st.d; if (cv.ok) C.cov = cv.d; } else toast(r.err, true); return paint();
      }
      if (a === 'showp') { C.show[t.dataset.k] = !C.show[t.dataset.k]; return paint(); }
      if (a === 'qans' || a === 'qskip') {
        const it = C.next && C.next.item; if (!it) return; C.nextBusy = true; C.qerr = ''; paint();
        const r = await soft(() => A.post('wch.claude_answer', a === 'qskip' ? { id: it.id, skip: true } : { id: it.id, answer: t.dataset.v, always: true })); C.nextBusy = false; if (dead || !alive(el)) return;
        if (!r.ok) { C.qerr = r.err; return paint(); }
        await loadAll(); return;
      }
      if (a === 'clright') { const r = await soft(() => A.post('wch.claude_state', { area: 'catalog', confirm: true })); if (r.ok) { C.cl = r.d; toast('Thanks. Saved as confirmed.'); } else toast(r.err, true); return paint(); }
    }
    loadAll();
    return { reload: loadAll, destroy: () => { dead = true; } };
  }

  // ================================================================== MESSAGES (cart + order messages)
  const MGROUPS = [['Chain and cart', ['chain_options_intro', 'chain_option_photo', 'chain_options_ask', 'chain_reminder', 'chain_chosen', 'no_chain', 'chain_replacement', 'cart_ready', 'payment_pending']], ['After payment', ['order_confirmed', 'bill_sent']]];
  const MSTAT = { written: ['g', 'Written, ready'], submitted: ['y', 'Submitted to Meta'], approved: ['g', 'Approved'], rejected: ['r', 'Rejected by Meta'], changed: ['o', 'Text changed, submit again'], existing: ['b', 'Existing, reused'] };
  const MODE = { window: ['g', 'Free text inside 24 h'], template: ['y', 'Template: Meta must approve'], existing: ['b', 'Reused from Order updates'] };

  function messages(el, o) {
    injectCss(); o = o || {}; const A = apiOf(o); if (!el) return null; el.classList.add('wchx');
    const M = { d: null, err: '', on: null, onErr: '', filt: 'all', edit: null, draft: {}, photo: {}, busy: '', msg: {}, confirmOn: false, loaded: false };
    let dead = false;
    async function load() {
      if (!A) { el.innerHTML = errCard('You are not logged in. Reload the page.'); return; }
      if (!M.loaded) el.innerHTML = '<div class="wchx-sec" style="margin-top:0">Customer messages for the cart flow</div>' + skel(4);
      const [m, s] = await Promise.all([soft(() => A.get('wch.messages', {})), soft(() => A.get('wch.settings', {}))]); if (dead || !alive(el)) return; M.loaded = true;
      M.d = m.ok ? m.d : null; M.err = m.ok ? '' : m.err; M.on = s.ok ? (num(s.d.settings.wch_on) === 1) : null; M.onErr = s.ok ? '' : s.err; paint();
    }
    const pill = (c, t) => '<span class="wchx-pill ' + c + '">' + esc(t) + '</span>';
    const st = (it) => { const s = it.status === 'written' && it.mode === 'template' ? ['y', 'Written, needs a template'] : (MSTAT[it.status] || ['', it.status_label || it.status]); return s; };
    function topCard() {
      const d = M.d.summary, items = M.d.items, ready = items.filter((x) => ['written', 'approved', 'existing'].includes(x.status) && !(x.mode === 'template' && x.status === 'written')).length, waiting = items.filter((x) => ['submitted', 'changed'].includes(x.status) || (x.mode === 'template' && x.status === 'written')).length, bad = items.filter((x) => x.status === 'rejected').length;
      return '<div class="wchx-card ' + (M.on ? 'ok' : 'ask') + '"><div class="row" style="flex-wrap:nowrap;gap:12px"><div style="flex:1;min-width:0"><b style="font-size:15.5px">Cart and order flow</b><div class="sub">' + (M.on === null ? 'Could not read the switch: ' + esc(M.onErr) : M.on ? 'Switched <b>ON</b>. Salespeople can send chain options and carts to customers.' : 'Switched <b>OFF</b>. Nothing goes to any customer until you switch it on.') + '</div></div>'
        + (M.on === null ? '<button type="button" class="wchx-btn s" data-a="reload">Try again</button>' : '<button type="button" class="wchx-tg ' + (M.on ? '' : 'off') + '" role="switch" aria-checked="' + (M.on ? 'true' : 'false') + '" aria-label="Cart and order flow on or off" data-a="flow"></button>') + '</div>'
        + (M.confirmOn ? '<div class="wchx-hl" style="margin-top:10px"><b>Switch the flow ON?</b> From now on salespeople can send chain photos and cart links to real customers. Make sure Billing is complete and the messages below read well.<div class="row" style="margin-top:8px"><button type="button" class="wchx-btn g" data-a="flowyes">Yes, switch on</button><button type="button" class="wchx-btn" data-a="flowno">Not yet</button></div></div>' : '')
        + '<div class="wchx-chk" style="margin-top:10px"><span class="y">' + ready + ' ready or reused</span>' + (waiting ? '<span class="n" style="background:#fff3dc;color:#8a5a00">' + waiting + ' waiting for Meta or submit</span>' : '') + (bad ? '<span class="n">' + bad + ' rejected</span>' : '') + '</div></div>';
    }
    function filtChips() {
      const items = M.d.items, c = (f) => items.filter(f).length; const F = [['all', 'All ' + items.length, () => true], ['written', 'Written ' + c((x) => ['written', 'changed'].includes(x.status)), (x) => ['written', 'changed'].includes(x.status)], ['submitted', 'Submitted ' + c((x) => x.status === 'submitted'), (x) => x.status === 'submitted'], ['approved', 'Approved ' + c((x) => x.status === 'approved'), (x) => x.status === 'approved'], ['rejected', 'Rejected ' + c((x) => x.status === 'rejected'), (x) => x.status === 'rejected'], ['existing', 'Existing ' + c((x) => x.status === 'existing'), (x) => x.status === 'existing']];
      M.F = F; return '<div class="wchx-chips" style="margin:2px 0 4px">' + F.map((f) => '<button type="button" class="wchx-ch ' + (M.filt === f[0] ? 'on' : '') + '" data-a="filt" data-f="' + f[0] + '">' + f[1] + '</button>').join('') + '</div>';
    }
    function card(it, n) {
      const s = st(it), m = MODE[it.mode] || ['', it.mode], cls = it.status === 'rejected' ? 'bad' : (['submitted', 'changed'].includes(it.status) || (it.mode === 'template' && it.status === 'written')) ? 'ask' : 'ok';
      const ed = M.edit === it.key, ro = it.source === 'existing' || it.mode === 'existing';
      let h = '<div class="wchx-card ' + cls + '" style="padding:13px 14px" data-key="' + esc(it.key) + '"><div class="row" style="gap:6px;align-items:flex-start"><div style="flex:1;min-width:150px"><b style="font-size:15px">' + n + '. ' + esc(it.title) + '</b><div class="sub">' + esc(it.when) + '</div></div>' + pill(s[0], s[1]) + '</div>';
      h += '<div class="row" style="gap:6px;margin-top:7px">' + pill(m[0], m[1]) + (it.edited ? pill('p', 'your text') : '') + '</div>';
      if (it.template && (it.template.per_waba || []).length) h += '<div class="row" style="gap:5px;margin-top:8px">' + it.template.per_waba.map((w) => { const ok = /approved/i.test(w.status), no = /reject|disabled|paused/i.test(w.status); return '<span class="wchx-pill ' + (ok ? 'g' : no ? 'r' : 'y') + '" style="padding:3px 9px" title="' + esc(w.status) + '">…' + esc(String(w.waba).slice(-4)) + ' ' + (ok ? '✓' : no ? '✕' : '…') + '</span>'; }).join('') + '</div>';
      if (ed) {
        const dv = M.draft[it.key] != null ? M.draft[it.key] : it.text;
        h += '<textarea class="wchx-in" id="wchxT_' + esc(it.key) + '" data-k="' + esc(it.key) + '" maxlength="1000" style="margin-top:9px">' + esc(dv) + '</textarea><div class="row" style="gap:5px;margin-top:6px">' + (it.vars || []).map((v) => '<button type="button" class="wchx-pill wchx-var" data-a="ins" data-v="' + esc(v) + '" style="border:0;cursor:pointer;min-height:40px" aria-label="Insert ' + esc(v) + '">{' + esc(v) + '}</button>').join('') + '</div>'
          + (M.msg[it.key] ? '<div class="wchx-err" style="margin-top:8px">' + esc(M.msg[it.key]) + '</div>' : '') + '<div class="row" style="gap:7px;margin-top:10px"><button type="button" class="wchx-btn s p" data-a="msave" data-k="' + esc(it.key) + '" ' + (M.busy === it.key ? 'disabled' : '') + '>' + (M.busy === it.key ? 'Saving…' : 'Save this text') + '</button><button type="button" class="wchx-btn s" data-a="mcancel">Cancel</button>' + (it.edited ? '<button type="button" class="wchx-btn s r" data-a="mreset" data-k="' + esc(it.key) + '">Use the original text</button>' : '') + '</div>';
      } else {
        h += '<div class="wchx-msg">' + esc(it.text) + '</div><div class="row" style="gap:5px;margin-top:8px">' + (it.vars || []).map((v) => '<span class="wchx-pill wchx-var">{' + esc(v) + '}</span>').join('') + '</div>';
        if (it.photo_url) h += '<div class="row" style="gap:8px;margin-top:9px"><span class="wchx-thumb" style="background-image:url(\'' + esc(it.photo_url.replace(/'/g, '%27')) + '\');width:44px;height:44px"></span><span class="sub">Photo goes with this message</span></div>';
        if (M.photo[it.key] !== undefined) h += '<div class="wchx-q"><small>Photo link (https://…)</small><div class="row" style="flex-wrap:nowrap"><input class="wchx-in" id="wchxP_' + esc(it.key) + '" value="' + esc(M.photo[it.key]) + '" placeholder="https://…" style="flex:1"><button type="button" class="wchx-btn s p" data-a="psave" data-k="' + esc(it.key) + '">Save</button><button type="button" class="wchx-btn s" data-a="pcancel">Cancel</button></div></div>';
        if (M.msg[it.key]) h += '<div class="' + (M.msg[it.key + '!'] ? 'wchx-hl' : 'wchx-err') + '" style="margin-top:8px">' + esc(M.msg[it.key]) + '</div>';
        h += '<div class="row" style="gap:7px;margin-top:11px">' + (ro ? '<span class="sub">Edit this one in Order updates.</span>' : '<button type="button" class="wchx-btn s" data-a="medit" data-k="' + esc(it.key) + '">Edit text</button>' + (it.photo_supported === false ? '' : '<button type="button" class="wchx-btn s" data-a="photo" data-k="' + esc(it.key) + '">' + (it.photo_url ? 'Change photo' : 'Add photo') + '</button>'));
        if (it.mode === 'template' && ['written', 'changed', 'rejected'].includes(it.status)) h += '<button type="button" class="wchx-btn s p" data-a="msub" data-k="' + esc(it.key) + '" ' + (M.busy === 's' + it.key ? 'disabled' : '') + '>' + (M.busy === 's' + it.key ? 'Submitting…' : it.status === 'written' ? 'Submit to all numbers' : 'Submit again') + '</button>';
        h += '</div>';
      }
      if (it.automatic === false) h += '<div class="wchx-note" style="margin:8px 0 0">Nothing sends this by itself yet. A salesperson sends it by hand from the cart.</div>';
      if (it.note) h += '<div class="sub" style="margin-top:8px">' + esc(it.note) + '</div>';
      return h + '</div>';
    }
    function paint() {
      const y = window.pageYOffset;
      if (!M.d) { el.innerHTML = '<div class="wchx-sec" style="margin-top:0">Customer messages for the cart flow</div>' + errCard(M.err || 'Could not load the messages.', 'reload'); el.onclick = onClick; return; }
      const items = M.d.items; const F = (filtChips(), M.F.find((f) => f[0] === M.filt) || M.F[0]); const vis = items.filter(F[2]); let n = 0; const num_ = {}; MGROUPS.forEach((g) => { items.filter((x) => g[1].includes(x.key)).forEach((x) => { num_[x.key] = ++n; }); }); items.filter((x) => !MGROUPS.some((g) => g[1].includes(x.key))).forEach((x) => { num_[x.key] = ++n; });
      let h = '<div class="wchx-sec" style="margin-top:0">Customer messages for the cart flow</div>' + topCard() + filtChips() + '<div class="sub" style="margin:6px 2px 0">A template must be approved on each WhatsApp number that will send it. Submit goes to all numbers at once. Texts have no designer brand names and make no promises.</div>';
      if (!items.length) h += '<div class="wchx-card"><div class="sub">No messages found.</div></div>';
      const used = new Set(); const sec = (title, keys) => { const L = vis.filter((x) => (keys ? keys.includes(x.key) : !MGROUPS.some((g) => g[1].includes(x.key)))); L.forEach((x) => used.add(x.key)); return L.length ? '<div class="wchx-sec">' + esc(title) + ' (' + L.length + ')</div>' + L.map((x) => card(x, num_[x.key])).join('') : ''; };
      MGROUPS.forEach((g) => { h += sec(g[0], g[1]); }); h += sec('Order updates', null);
      if (!vis.length && items.length) h += '<div class="wchx-card"><div class="sub">No messages in this filter.</div></div>';
      el.innerHTML = h; el.onclick = onClick; window.scrollTo(0, y);
    }
    async function onClick(e) {
      const t = e.target.closest('[data-a]'); if (!t || t.disabled) return; const a = t.dataset.a, k = t.dataset.k;
      if (a === 'reload') return load();
      if (a === 'filt') { M.filt = t.dataset.f; return paint(); }
      if (a === 'flow') { if (M.on) { const r = await soft(() => A.post('wch.settings', { wch_on: 0 })); if (r.ok) { M.on = false; toast('Switched off. Nothing goes to customers now.'); } else toast(r.err, true); return paint(); } M.confirmOn = true; return paint(); }
      if (a === 'flowno') { M.confirmOn = false; return paint(); }
      if (a === 'flowyes') { const r = await soft(() => A.post('wch.settings', { wch_on: 1 })); M.confirmOn = false; if (r.ok) { M.on = num(r.d.settings.wch_on) === 1; toast('Switched on.'); } else toast(r.err, true); return paint(); }
      if (a === 'medit') { M.edit = k; M.msg = {}; return paint(); }
      if (a === 'mcancel') { M.edit = null; M.msg = {}; return paint(); }
      if (a === 'ins') { const ta = el.querySelector('#wchxT_' + (M.edit || '')); if (ta) { const p = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0, p) + '{' + t.dataset.v + '}' + ta.value.slice(ta.selectionEnd || p); ta.focus(); } return; }
      if (a === 'msave' || a === 'mreset') {
        const ta = el.querySelector('#wchxT_' + k); M.busy = k; M.msg = {}; paint(); const r = await soft(() => A.post('wch.message_save', a === 'mreset' ? { key: k, reset: true } : { key: k, text: ta ? ta.value : '' })); M.busy = '';
        if (r.ok) { swap(r.d.item || r.d); M.edit = null; toast(a === 'mreset' ? 'Original text restored.' : 'Saved.'); } else M.msg[k] = r.err; return paint();
      }
      if (a === 'photo') { const it = M.d.items.find((x) => x.key === k); M.photo[k] = (it && it.photo_url) || ''; return paint(); }
      if (a === 'pcancel') { M.photo = {}; return paint(); }
      if (a === 'psave') { const v = ((el.querySelector('#wchxP_' + k) || {}).value || '').trim(); if (v && !/^https:\/\//i.test(v)) { M.msg[k] = 'The photo link must start with https://'; return paint(); } const it = M.d.items.find((x) => x.key === k); const r = await soft(() => A.post('wch.message_save', { key: k, text: it.text, photo_url: v })); if (r.ok) { swap(r.d.item || r.d); M.photo = {}; M.msg = {}; toast(v ? 'Photo saved.' : 'Photo removed.'); } else M.msg[k] = r.err; return paint(); }
      if (a === 'msub') { M.busy = 's' + k; M.msg = {}; paint(); const r = await soft(() => A.post('wch.messages_submit', { keys: [k] })); M.busy = ''; if (r.ok) { const f = r.d.failed && r.d.failed[k], sk = r.d.skipped && r.d.skipped[k]; if (f) M.msg[k] = 'Meta did not accept it: ' + f; else if (sk) M.msg[k] = String(sk); else { M.msg[k] = 'Sent to Meta for approval. This can take a few minutes to a day.'; M.msg[k + '!'] = 1; toast('Submitted to every WhatsApp number.'); } const m = await soft(() => A.get('wch.messages', {})); if (m.ok) M.d = m.d; } else M.msg[k] = r.err; return paint(); }
    }
    function swap(it) { const i = M.d.items.findIndex((x) => x.key === it.key); if (i >= 0) M.d.items[i] = it; }
    load();
    return { reload: load, destroy: () => { dead = true; } };
  }


  window.WaCartSetup = { v: 'ch1', billing, billingState, catalog, messages, css: injectCss };
})();
