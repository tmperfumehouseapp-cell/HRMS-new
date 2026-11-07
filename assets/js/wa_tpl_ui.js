/**
 * wa_tpl_ui.js — Pre-made messages (templates) screen of the WhatsApp console: the list with folders, and the new
 * "New pre-made message" form (photo / video / PDF / location / offer timer on top, variables, up to 10 buttons,
 * live phone preview, "Tell Claude what it is for", Utility version suggestion).
 * Needs api.js, wa_panels.js and wa_growth.js loaded first (whatsapp.html). It replaces the 'premade' screen through WaPanels.add.
 *   window.WaTpl.openForm({ draft?, onDone? })   open the new-message form from anywhere (campaign screens can use it)
 */
(function () {
  if (!window.WaPanels || typeof api === "undefined") return;
  const { esc, $, toast, modal, sel, upload, errBox, loading } = WaPanels.util;
  const $$ = (r, q) => Array.prototype.slice.call(r.querySelectorAll(q));

  // ------------------------------------------------------------------------------------------------------------------ styles
  const CSS = `
  .wt-mb{max-width:1080px !important;width:100%;}
  @media (min-width:761px){.wt-mb{width:min(1080px,96vw);}}
  .wt-wrap{display:grid;grid-template-columns:minmax(0,1fr) 300px;grid-template-areas:"c p" "a p" "b p";gap:0 22px;align-items:start;}
  .wt-c{grid-area:c;} .wt-a{grid-area:a;min-width:0;} .wt-b{grid-area:b;min-width:0;} .wt-prev{grid-area:p;position:sticky;top:0;align-self:start;}
  .wt-ai{background:linear-gradient(90deg,#f1ecff,#f7f4ff);border:1px solid #ddd3fb;border-radius:14px;padding:10px 12px;margin-bottom:6px;}
  .wt-ai>b{font-size:13px;color:#3b2a8a;display:block;} .wt-ai .wp-row{flex-wrap:nowrap;margin-top:6px;} .wt-ai .wp-hint{margin-top:6px;}
  .wt-und{background:#eef6ff;border:1px solid #cfe3fb;border-radius:12px;padding:10px 13px;margin:8px 0;line-height:1.5;font-size:13px;} .wt-und small{display:block;font-weight:800;color:#1f5fc4;font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
  .wt-ph{background:#e7ded3;border-radius:26px;padding:14px 10px 16px;box-shadow:0 10px 30px rgba(15,23,42,.15);}
  .wt-pvh{font-size:11px;color:#667781;text-align:center;margin-bottom:8px;}
  .wt-msg{background:#fff;border-radius:10px;padding:6px 6px 0;box-shadow:0 1px 1px rgba(0,0,0,.12);position:relative;overflow:hidden;}
  .wt-hd{border-radius:8px;margin-bottom:6px;overflow:hidden;position:relative;background:linear-gradient(135deg,#f6d365,#fda085) center/cover no-repeat;height:130px;display:flex;align-items:center;justify-content:center;font-size:42px;color:#fff;}
  .wt-hd.vid{background-color:#2b3a42;background-image:none;} .wt-hd.vid:after{content:'▶';position:absolute;width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,.45);color:#fff;font-size:18px;display:flex;align-items:center;justify-content:center;padding-left:3px;}
  .wt-hd.pdf{background:#f0f2f5;color:#54656f;height:auto;padding:12px;justify-content:flex-start;gap:10px;font-size:13px;font-weight:600;} .wt-hd.pdf i{font-size:30px;font-style:normal;} .wt-hd.pdf small{display:block;font-weight:500;color:#8696a0;}
  .wt-hd.loc{background:#cfe6d8;height:96px;flex-direction:column;font-size:30px;color:#c2262b;} .wt-hd.loc small{font-size:11px;color:#3b4a43;font-weight:700;max-width:90%;text-align:center;line-height:1.2;}
  .wt-lto{position:absolute;left:6px;bottom:6px;background:rgba(17,27,33,.78);color:#fff;border-radius:99px;padding:3px 10px;font-size:11px;font-weight:700;}
  .wt-tm{background:#fff7e8;border-radius:6px;margin:0 0 6px;padding:5px 8px;font-size:11.5px;color:#8a5a00;font-weight:700;display:flex;gap:6px;align-items:center;}
  .wt-hh{font-weight:800;font-size:13.5px;padding:0 4px 3px;color:#111b21;}
  .wt-bd{font-size:13px;line-height:1.38;padding:0 4px 4px;white-space:pre-wrap;word-wrap:break-word;color:#111b21;} .wt-bd .wt-v{background:#fff3c4;border-radius:3px;padding:0 1px;}
  .wt-ft{font-size:11px;color:#8696a0;padding:0 4px 4px;} .wt-tt{font-size:11px;color:#8696a0;text-align:right;padding:0 4px 4px;}
  .wt-pb{border-top:1px solid #e9edef;text-align:center;color:#027eb5;font-weight:600;font-size:13px;padding:9px 4px;}
  .wt-th{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 8px;-webkit-overflow-scrolling:touch;}
  .wt-t{width:92px;flex:none;text-align:center;cursor:pointer;} .wt-t .im{height:70px;border-radius:12px;background:#eef1f6 center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-size:28px;color:#64748b;} .wt-t.on .im{outline:3px solid #16a34a;outline-offset:2px;}
  .wt-t span{display:block;font-size:11px;margin-top:4px;color:#334155;line-height:1.2;max-height:2.4em;overflow:hidden;word-break:break-word;}
  .wt-up{width:92px;height:70px;flex:none;border:2px dashed #cbd5e1;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#64748b;cursor:pointer;text-align:center;background:none;font-weight:700;}
  .wt-vr{display:grid;grid-template-columns:44px 130px minmax(0,1fr);gap:8px;align-items:center;margin-bottom:6px;} .wt-vr b{background:#eef1f6;border-radius:8px;text-align:center;padding:9px 0;font-size:12.5px;}
  .wt-ct{font-size:12px;color:#64748b;margin-left:auto;font-variant-numeric:tabular-nums;} .wt-ct.bad{color:#b42318;font-weight:800;}
  .wt-bt{border:1px solid #e3e8e5;border-radius:12px;padding:8px 10px;margin-bottom:8px;background:#fff;} .wt-bt .h{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:#475569;margin-bottom:6px;} .wt-bt .h .sp{flex:1;}
  .wt-bt .wp-in{margin-bottom:6px;} .wt-bt .wp-in:last-child{margin-bottom:0;}
  .wt-x{border:0;background:#eef1f6;border-radius:50%;width:30px;height:30px;cursor:pointer;font-size:13px;padding:0;min-height:0;flex:none;}
  .wt-chk{border-radius:12px;padding:10px 13px;margin:10px 0;font-size:12.5px;line-height:1.5;} .wt-chk.ok{background:#fff8e6;border:1px solid #fde8a8;color:#7a5200;} .wt-chk.bad{background:#fde8e8;color:#9a2620;} .wt-chk.warn{background:#fff7ed;border:1px solid #fed7aa;color:#8a4a0d;margin-top:6px;}
  .wt-chk ul{margin:4px 0 0;padding-left:18px;}
  .wt-ut{border:2px solid #cfe9d8;background:#f6fcf8;border-radius:16px;padding:12px 14px;margin:12px 0;} .wt-ut h4{margin:0 0 2px;font-size:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .wt-ut .bx{background:#fff;border:1px solid #e3e8e5;border-radius:12px;padding:10px 12px;white-space:pre-wrap;line-height:1.5;font-size:13px;margin:8px 0;} .wt-ut mark{background:#bff0d3;border-radius:3px;padding:0 2px;color:#0b4a28;}
  .wt-ut table{width:100%;border-collapse:collapse;font-size:12.5px;} .wt-ut td{padding:5px 6px;border-top:1px solid #e8efe9;vertical-align:top;} .wt-ut td:first-child{width:34%;text-decoration:line-through;color:#8a998f;} .wt-ut ul{margin:6px 0 0;padding-left:18px;font-size:12.5px;line-height:1.5;color:#3d4f46;}
  .wt-fol{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .wt-pvbtn{display:none;}
  .wt-card h3{flex-wrap:wrap;row-gap:6px} .wt-dg{margin-left:6px;min-height:30px !important;padding:4px 10px !important;font-size:12px !important;white-space:nowrap} .wt-dg.hot{background:#fff7e6;border-color:#f5c26b;color:#8a5a00}
  .wt-tp{display:inline-block;font-size:11px;font-weight:800;padding:2px 8px;border-radius:99px;background:#e8f0ff;color:#2563eb;margin:0 4px 0 0;}
  .wt-card .wt-ms{background:#fff;border-radius:10px;padding:5px 5px 0;box-shadow:0 1px 2px rgba(0,0,0,.14);margin:6px 0;background-color:#f0f5f1;}
  .wt-card .wt-hd{height:96px;font-size:30px;margin-bottom:5px;} .wt-card .wt-hd.pdf{height:auto;padding:8px 10px;} .wt-card .wt-hd.loc{height:70px;}
  .wt-card .wt-bd{font-size:12.5px;} .wt-card .wt-pb{padding:7px 4px;font-size:12.5px;}
  .wt-fi{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px;align-items:center;} .wt-fi .wp-chip{min-height:32px;padding:4px 12px;font-size:12px;}
  @media (max-width:860px){
    .wt-wrap{grid-template-columns:minmax(0,1fr);grid-template-areas:"c" "a" "b";}
    .wt-prev{display:none;position:static;} .wt-prev.show{display:block;position:fixed;left:14px;right:14px;bottom:76px;z-index:260;max-height:68vh;overflow:auto;border-radius:26px;box-shadow:0 20px 50px rgba(0,0,0,.4);max-width:340px;margin:0 auto;}
    .wt-pvbtn{display:block;position:sticky;bottom:6px;margin:8px 0 0 auto;z-index:5;background:#1b2430;color:#fff;border:0;border-radius:99px;padding:11px 18px;font-weight:800;font-size:13px;box-shadow:0 6px 18px rgba(0,0,0,.3);min-height:44px;}
    .wt-vr{grid-template-columns:40px minmax(0,1fr);} .wt-vr .wp-in:nth-of-type(2){grid-column:1 / -1;}
    .wt-ai .wp-row{flex-wrap:wrap;} .wt-ai .wp-row .wp-btn{width:100%;}
  }`;
  // ---- styles of the Marketing + Utility mix (same text as the wmx- block at the end of wa_ui.css; the page's styles live inside whatsapp.html)
  if (!document.getElementById('wmxCss')) { const s = document.createElement('style'); s.id = 'wmxCss'; s.textContent = `
.wmx-chips{display:flex;flex-wrap:wrap;gap:5px;margin:6px 0 2px;}
.wmx-chip{display:inline-flex;align-items:center;gap:5px;border-radius:99px;padding:2px 9px 2px 7px;font-size:11px;font-weight:700;background:#eef1ef;color:#4a5a52;line-height:1.5;white-space:nowrap;max-width:100%;}
.wmx-chip i{width:7px;height:7px;border-radius:50%;background:#94a3b8;display:inline-block;flex:none;}
.wmx-chip.ok{background:#dcf7e6;color:#12794a;} .wmx-chip.ok i{background:#16a34a;}
.wmx-chip.waiting{background:#fff3cd;color:#8a6a00;} .wmx-chip.waiting i{background:#f0a500;}
.wmx-chip.rejected,.wmx-chip.stopped{background:#fde6e6;color:#b42318;} .wmx-chip.rejected i,.wmx-chip.stopped i{background:#d92d20;}
.wmx-chip.paused,.wmx-chip.error{background:#ffedd5;color:#9a3412;} .wmx-chip.paused i,.wmx-chip.error i{background:#f97316;}
.wmx-chip.relabelled{background:#ede9fe;color:#5b34c9;} .wmx-chip.relabelled i{background:#7c5cfc;}
.wmx-chip.missing,.wmx-chip.none{background:#f1f5f9;color:#64748b;}
.wmx-cap{font-size:11px;font-weight:800;color:#6b7a73;text-transform:uppercase;letter-spacing:.05em;margin:8px 0 0;}
.wmx-u{border:1px solid #cfe9d8;background:#f6fcf8;border-radius:12px;padding:9px 11px;margin:8px 0;font-size:12.5px;line-height:1.45;}
.wmx-u.relabelled{border-color:#d9d0fb;background:#f7f4ff;} .wmx-u.rejected,.wmx-u.stopped{border-color:#f5c9c9;background:#fff6f6;} .wmx-u.waiting{border-color:#f3e2a6;background:#fffcf0;} .wmx-u.none,.wmx-u.paused{border-color:#e3e8e5;background:#f8faf9;}
.wmx-u>b{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12.5px;}
.wmx-u .wmx-l{margin:5px 0 0;color:#334155;} .wmx-u .wmx-l small{color:#64748b;display:block;}
.wmx-u .wp-btn{margin-top:6px;}
.wmx-learn{font-size:12px;color:#475569;margin:6px 0 0;padding-left:20px;position:relative;line-height:1.4;} .wmx-learn:before{content:'\\2728';position:absolute;left:0;top:0;font-size:12px;}
.wmx-pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;background:#eef1ef;color:#4a5a52;white-space:nowrap;}
.wmx-pill.ok{background:#dcf7e6;color:#12794a;} .wmx-pill.warn{background:#fff3cd;color:#8a6a00;} .wmx-pill.bad{background:#fde6e6;color:#b42318;} .wmx-pill.pu{background:#ede9fe;color:#5b34c9;} .wmx-pill.bl{background:#e0efff;color:#1f5fc4;}
.wmx-fl{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 10px;} .wmx-fl .wp-hint{margin:0 4px 0 0;} .wmx-fl .wp-chip{min-height:32px;padding:4px 12px;font-size:12px;}
.wmx-auto{background:linear-gradient(90deg,#f1fbf5,#f8fdfa);border:1px solid #cfe9d8;border-radius:16px;padding:12px 14px;margin:0 0 12px;}
.wmx-auto h3{margin:0 0 4px;font-size:14px;font-weight:800;display:flex;gap:8px;align-items:center;flex-wrap:wrap;} .wmx-auto h3 .sp{flex:1;}
.wmx-auto .wmx-nums{display:grid;grid-template-columns:repeat(auto-fit,minmax(86px,1fr));gap:8px;margin:8px 0;} .wmx-auto .wmx-nums div{background:#fff;border:1px solid #e3e8e5;border-radius:12px;padding:6px 10px;} .wmx-auto .wmx-nums small{display:block;font-size:11px;font-weight:800;color:#6b7a73;text-transform:uppercase;letter-spacing:.04em;} .wmx-auto .wmx-nums b{font-size:17px;}
.wmx-auto details{font-size:12.5px;color:#475569;margin-top:4px;} .wmx-auto summary{cursor:pointer;font-weight:700;padding:4px 0;min-height:32px;}
.wmx-lim{display:flex;gap:8px;align-items:center;font-size:12px;margin:3px 0;} .wmx-lim .wp-bar{flex:1;margin:0;max-width:160px;}
.wmx-hu{background:#fde8e8;color:#9a2620;border-radius:10px;padding:7px 10px;margin:6px 0;font-size:12.5px;}
.wmx-sw{display:flex;gap:10px;align-items:flex-start;background:#f6fcf8;border:1px solid #cfe9d8;border-radius:14px;padding:10px 12px;margin:10px 0;}
.wmx-sw.off{background:#f8faf9;border-color:#e3e8e5;} .wmx-sw label{display:flex;gap:10px;align-items:flex-start;cursor:pointer;flex:1;min-width:0;font-weight:700;font-size:13.5px;line-height:1.35;}
.wmx-sw input{width:22px;height:22px;flex:none;margin:1px 0 0;accent-color:#16a34a;} .wmx-sw small{display:block;font-weight:500;color:#64748b;font-size:12px;margin-top:3px;line-height:1.4;}
.wmx-twin{border:1px solid #cfe9d8;background:#fff;border-radius:12px;padding:8px 10px;margin-top:8px;font-size:12.5px;line-height:1.45;} .wmx-twin .wmx-bub{background:#f0f5f1;border-radius:9px;padding:7px 9px;margin-top:6px;white-space:pre-wrap;color:#111b21;font-size:12.5px;}
.wmx-notice{background:#f7f4ff;border:1px solid #d9d0fb;color:#3b2a8a;border-radius:12px;padding:9px 12px;margin:8px 0;font-size:12.5px;line-height:1.5;} .wmx-notice.bad{background:#fde8e8;border-color:#f5c9c9;color:#9a2620;} .wmx-notice b{display:block;margin-bottom:2px;}
.wmx-mix{margin:10px 0;} .wmx-mix>h4{margin:0 0 4px;font-size:13.5px;font-weight:800;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.wmx-short{font-size:12.5px;color:#7a5200;background:#fff8e6;border:1px solid #fde8a8;border-radius:10px;padding:6px 10px;margin:6px 0;line-height:1.45;}
.wmx-nr{border:1px solid #e3e8e5;background:#fff;border-radius:14px;padding:10px 12px;margin:8px 0;} .wmx-nr.off{background:#f8faf9;}
.wmx-nr .h{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:13.5px;} .wmx-nr .h b{font-weight:800;} .wmx-nr .h .sp{flex:1;}
.wmx-nr .wmx-av{display:flex;gap:5px;flex-wrap:wrap;margin:5px 0;} .wmx-nr .why{font-size:12px;color:#475569;line-height:1.4;margin-top:4px;}
.wmx-bar{position:relative;height:10px;background:#e9eeeb;border-radius:6px;margin:8px 0 3px;overflow:visible;} .wmx-bar i{display:block;height:100%;background:#25d366;border-radius:6px;min-width:2px;} .wmx-bar em{position:absolute;top:-3px;width:2px;height:16px;background:#1b2430;border-radius:1px;}
.wmx-bar+.wmx-barl{display:flex;justify-content:space-between;font-size:11px;color:#64748b;}
.wmx-seg{width:100%;border-collapse:collapse;font-size:12.5px;margin:6px 0;} .wmx-seg th{font-size:11px;font-weight:800;color:#6b7a73;text-transform:uppercase;letter-spacing:.04em;text-align:right;padding:4px 6px;border-bottom:1px solid #e8efe9;} .wmx-seg th:first-child,.wmx-seg td:first-child{text-align:left;} .wmx-seg td{padding:6px;text-align:right;border-top:1px solid #eef2ef;font-variant-numeric:tabular-nums;}
.wmx-cost{display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px;margin:6px 0;} .wmx-cost span{background:#f1f5f9;border-radius:99px;padding:3px 10px;font-weight:700;color:#334155;} .wmx-cost .sv{background:#dcf7e6;color:#12794a;}
.wmx-ver{display:inline-block;font-size:11px;font-weight:800;padding:1px 8px;border-radius:99px;background:#eef1ef;color:#4a5a52;} .wmx-ver.u{background:#dcf7e6;color:#12794a;}
.wmx-est{font-size:12.5px;color:#12794a;background:#e8f8ee;border-radius:10px;padding:6px 10px;margin:6px 0;line-height:1.45;}
.wmx-sug{background:#e8f8ee;border:1px solid #bfe8cf;border-radius:12px;padding:9px 12px;margin:8px 0;font-size:12.5px;line-height:1.45;color:#0b4a28;} .wmx-sug .wp-btn{margin-top:6px;}
@media (max-width:640px){.wmx-auto .wmx-nums{grid-template-columns:repeat(3,1fr);}.wmx-seg{font-size:12px;}.wmx-seg td,.wmx-seg th{padding:5px 3px;}.wmx-sw label{font-size:13px;}.wmx-chip{font-size:11px;}}
`; document.head.appendChild(s); }
  if (!document.getElementById('wtCss')) { const s = document.createElement('style'); s.id = 'wtCss'; s.textContent = CSS; document.head.appendChild(s); }

  // ------------------------------------------------------------------------------------------------------------------ helpers
  const vars = (s) => { const o = []; String(s || '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => { n = +n; if (o.indexOf(n) < 0) o.push(n); return m; }); return o.sort((a, b) => a - b); };
  const TYPES = [['text', 'Text', '📝'], ['photo', 'Photo', '🖼'], ['video', 'Video', '🎬'], ['pdf', 'PDF', '📄'], ['location', 'Location', '📍'], ['lto', 'Offer timer', '⏳'], ['carousel', 'Carousel', '🎠']];
  const TYPE_NAME = { TEXT: 'Text', IMAGE: 'Photo', VIDEO: 'Video', DOCUMENT: 'PDF', LOCATION: 'Location', LTO: 'Offer timer' };
  const KIND = { photo: 'image', video: 'video', pdf: 'document' };
  const NOUN = { photo: 'photo', video: 'video', pdf: 'PDF', image: 'photo', document: 'PDF' };
  const LABELS = [['Name', 'Rakesh'], ['Perfume', 'Royal Oud'], ['Coupon', 'DIWALI20'], ['Date', '12 Nov'], ['Other', 'Sample']];
  const DEFAULT_FOLDERS = ['Offers', 'Reminders', 'Order updates'];
  const BTN = { QUICK_REPLY: ['💬', 'Quick reply'], URL: ['🌐', 'Website link'], PHONE_NUMBER: ['📞', 'Call us'], COPY_CODE: ['📋', 'Copy code'] };
  const blank = () => ({ id: 0, name: '', nameAuto: true, category: 'MARKETING', language: 'en', type: 'text', ltoKind: 'photo', heading: '', headerEx: 'Rakesh', libId: 0, body: '', footer: '', buttons: [], examples: [], labels: [],
    lto: { text: 'Offer ends soon', hours: 48 }, loc: { lat: '', lng: '', name: 'TM Perfume House', address: '' }, folder: '', folderAuto: true, purpose: '', understood: '' });
  function renumber(D) {   // {{3}} before {{2}} → {{2}} {{3}}; examples and labels move with their variables
    const order = []; String(D.body).replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => { n = +n; if (order.indexOf(n) < 0) order.push(n); return m; });
    const map = {}; order.forEach((o, i) => { map[o] = i + 1; }); const ex = [], lb = [];
    order.forEach((o, i) => { ex[i] = D.examples[o - 1] || ''; lb[i] = D.labels[o - 1] || ''; });
    D.body = D.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => '{{' + map[+n] + '}}'); D.examples = ex; D.labels = lb;
  }
  const slug = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60);
  function autoName(D) { const w = String(D.body || '').replace(/\{\{\s*\d+\s*\}\}/g, ' ').split(/\s+/).filter((x) => /[a-z0-9]/i.test(x)).slice(0, 4).join(' '); return slug(w) || 'new_message'; }
  function guessFolder(D) {
    const t = (D.name + ' ' + D.body + ' ' + D.purpose).toLowerCase();
    if (D.type === 'lto') return 'Offers';
    if (D.category === 'UTILITY' || /\b(order|shipped|dispatch|delivery|delivered|tracking|invoice|payment|confirm)/.test(t)) return 'Order updates';
    if (/\b(remind|reminder|later|pending|waiting|still interested|follow|cart|reorder|refill|yaad)/.test(t)) return 'Reminders';
    if (/(offer|discount|sale|%|coupon|diwali|festival|deal|code|special|combo|gift)/.test(t)) return 'Offers';
    return '';
  }
  const fmtWA = (s) => esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|[^\w])_([^_\n]+)_(?=[^\w]|$)/g, '$1<i>$2</i>').replace(/~([^~\n]+)~/g, '<s>$1</s>');
  function fillEx(text, D, mark) { return esc(text).replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => { const v = (D.examples[+n - 1] || '').trim(); return v ? (mark ? '<span class="wt-v">' + v + '</span>' : v) : m; }); }
  const sortBtns = (b) => b.filter((x) => x.type === 'QUICK_REPLY').concat(b.filter((x) => x.type !== 'QUICK_REPLY'));

  // pictures from the media library (the browser fetches the file with the login token)
  const URLS = {}; let LIB = null, LIBP = null;
  async function libItems(force) {
    if (LIB && !force) return LIB;
    if (!LIBP || force) LIBP = api.get('wa.lib_list').then((d) => { LIB = d.items || []; return LIB; }).catch((e) => { LIBP = null; throw e; });
    return LIBP;
  }
  async function fileUrl(id) {
    if (URLS[id]) return URLS[id];
    const r = await fetch(api.buildUrl('wa.lib_file', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('Could not load the file.');
    return (URLS[id] = URL.createObjectURL(await r.blob()));
  }
  function hydrate(root) { $$(root, '[data-th]').forEach(async (e) => { if (e.dataset.done) return; e.dataset.done = '1'; try { e.style.backgroundImage = 'url(' + (await fileUrl(e.dataset.th)) + ')'; e.classList.add('has'); const f = e.querySelector('.fb'); if (f) f.remove(); } catch (x) { } }); }

  // the phone preview (also used for the cards in the list)
  function bubble(o) {   // o: {type, body(html), heading, footer, buttons, libId, libKind, lto, loc, fileName}
    let top = '';
    const th = o.libId ? ' data-th="' + o.libId + '"' : '';
    if (o.type === 'photo' || (o.type === 'lto' && o.ltoKind !== 'video')) top = `<div class="wt-hd"${th}>${o.libId ? '<span class="fb">🖼</span>' : '🖼'}${o.type === 'lto' ? `<span class="wt-lto">⏳ ${esc(o.lto.text || 'Offer ends soon')}</span>` : ''}</div>`;
    else if (o.type === 'video' || o.type === 'lto') top = `<div class="wt-hd vid">${o.type === 'lto' ? `<span class="wt-lto">⏳ ${esc(o.lto.text || 'Offer ends soon')}</span>` : ''}</div>`;
    else if (o.type === 'pdf') top = `<div class="wt-hd pdf"><i>📄</i><div>${esc(o.fileName || 'price-list.pdf')}<small>PDF</small></div></div>`;
    else if (o.type === 'location') top = `<div class="wt-hd loc">📍<small>${esc(o.loc.name || 'TM Perfume House')}${o.loc.address ? '<br>' + esc(o.loc.address) : ''}</small></div>`;
    const tm = o.type === 'lto' ? `<div class="wt-tm">⏳ Offer ends in ${esc(String(o.lto.hours || 48))} hours · timer shown to the customer</div>` : '';
    const bt = sortBtns(o.buttons || []).map((b) => `<div class="wt-pb">${BTN[b.type] ? BTN[b.type][0] : '•'} ${esc(b.type === 'COPY_CODE' ? 'Copy offer code' : (b.text || '…'))}</div>`).join('');
    return `<div class="wt-msg">${top}${tm}${o.heading ? `<div class="wt-hh">${o.heading}</div>` : ''}<div class="wt-bd">${o.body}</div>${o.footer ? `<div class="wt-ft">${esc(o.footer)}</div>` : ''}<div class="wt-tt">10:42</div>${bt}</div>`;
  }
  function wordDiff(a, b) {   // words of b that are not in a (simple LCS) → b as html with <mark>
    const A = String(a).split(/\s+/).filter(Boolean), B = String(b).split(/(\s+)/), W = B.filter((x) => x.trim() !== ''), n = A.length, m = W.length;
    const norm = (x) => x.toLowerCase().replace(/[^\p{L}\p{N}{}]/gu, ''); const L = []; for (let i = 0; i <= n; i++) L.push(new Array(m + 1).fill(0));
    for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) L[i][j] = norm(A[i]) === norm(W[j]) ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
    const keep = new Array(m).fill(false); let i = 0, j = 0; while (i < n && j < m) { if (norm(A[i]) === norm(W[j])) { keep[j] = true; i++; j++; } else if (L[i + 1][j] >= L[i][j + 1]) i++; else j++; }
    let k = -1; return B.map((x) => { if (x.trim() === '') return esc(x); k++; return keep[k] ? esc(x) : '<mark>' + esc(x) + '</mark>'; }).join('');
  }

  // ------------------------------------------------------------------------------------------------------------------ draft <-> server
  function fmtOf(D) { return D.type === 'lto' ? (D.ltoKind === 'video' ? 'VIDEO' : 'IMAGE') : ({ photo: 'IMAGE', video: 'VIDEO', pdf: 'DOCUMENT', location: 'LOCATION' }[D.type] || (D.heading.trim() ? 'TEXT' : '')); }
  function toDraft(D) {
    const f = fmtOf(D), nv = vars(D.body).length, media = ['IMAGE', 'VIDEO', 'DOCUMENT'].indexOf(f) >= 0;
    const d = { name: D.name || autoName(D), category: D.category, language: D.language, header_format: f, header_text: f === 'TEXT' ? D.heading.trim() : '', header_lib_id: media ? D.libId : 0, body: D.body, footer: D.type === 'lto' ? '' : D.footer,
      buttons: sortBtns(D.buttons).map((b) => { const o = { type: b.type, text: b.text || '' }; if (b.type === 'URL') { o.url = b.url || ''; o.example = b.example || ''; } if (b.type === 'PHONE_NUMBER') o.phone = b.phone || ''; if (b.type === 'COPY_CODE') o.example = b.example || ''; return o; }),
      folder: D.folder, purpose: D.purpose, understood: D.understood, examples: { body: D.examples.slice(0, nv).map((x) => (x || '').trim()), header: D.headerEx || 'Rakesh', labels: D.labels.slice(0, nv) } };
    if (D.type === 'lto') d.lto = { text: D.lto.text, hours: +D.lto.hours || 48 };
    if (D.type === 'location') d.extra = { location: D.loc };
    return d;
  }
  function fromDraft(dr, keep) {   // a draft / list item from the server → form state
    const D = blank(); const f = dr.header_format || ''; const isLto = dr.lto && dr.lto.text;
    D.type = isLto ? 'lto' : ({ IMAGE: 'photo', VIDEO: 'video', DOCUMENT: 'pdf', LOCATION: 'location' }[f] || 'text'); D.ltoKind = f === 'VIDEO' ? 'video' : 'photo';
    Object.assign(D, { name: dr.name || '', nameAuto: !dr.name, category: dr.category === 'UTILITY' ? 'UTILITY' : 'MARKETING', language: dr.language || 'en', heading: f === 'TEXT' ? (dr.header_text || '') : '', libId: +dr.header_lib_id || 0, body: dr.body || '', footer: dr.footer || '',
      buttons: (dr.buttons || []).filter((b) => BTN[b.type]).map((b) => Object.assign({}, b)), folder: dr.folder || '', folderAuto: !dr.folder, purpose: dr.purpose || '', understood: dr.understood || '' });
    const ex = dr.examples || {}; D.examples = (ex.body || dr.var_examples || []).map(String); D.labels = (ex.labels || dr.var_labels || []).map(String); D.headerEx = ex.header || dr.header_example || 'Rakesh';
    if (isLto) D.lto = { text: dr.lto.text, hours: dr.lto.hours || 48 };
    const loc = (dr.extra && dr.extra.location) || dr.location; if (loc) D.loc = Object.assign(D.loc, loc);
    if (keep && keep.libId && KIND[D.type === 'lto' ? D.ltoKind : D.type] && keep.kind === KIND[D.type === 'lto' ? D.ltoKind : D.type]) D.libId = keep.libId;
    return D;
  }

  // ================================================================================================================ THE FORM
  // ------------------------------------------------------------------------------------------------------------------ Marketing + Utility mix helpers (wmx-)
  const UST = { approved: ['Utility approved', 'ok'], waiting: ['Waiting for Meta', 'warn'], rejected: ['Rejected', 'bad'], paused: ['Paused by Meta', 'bad'], relabelled: ['Re-labelled as marketing', 'pu'], stopped: ['Needs a human to rewrite', 'bad'], none: ['Marketing only', ''], missing: ['Not sent yet', ''] };
  const CHIP_TXT = { ok: 'approved', waiting: 'waiting for Meta', rejected: 'rejected by Meta', paused: 'paused by Meta', missing: 'not sent to this account yet', relabelled: 're-labelled as marketing', error: 'could not be sent - will try again', none: 'none' };
  const CHIP_MARK = { ok: '✓', waiting: '…', rejected: '✕', paused: '⏸', missing: '–', relabelled: 'M', error: '!' };
  const dShort = (v) => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }); };
  const shortLabel = (l) => { l = String(l || '').replace(/\s*[-–—]\s*.*/, '').trim() || String(l || ''); return l.length > 16 ? l.slice(0, 15) + '…' : l; };
  function chipsHtml(matrix) {
    if (!matrix || !matrix.length) return '';
    return `<div class="wmx-chips">${matrix.map((c) => `<span class="wmx-chip ${esc(c.state)}" title="${esc(c.label + ': ' + (CHIP_TXT[c.state] || c.state) + (c.reason ? ' - ' + c.reason : ''))}"><i></i>${esc(shortLabel(c.label))} ${CHIP_MARK[c.state] || ''}</span>`).join('')}</div>`;
  }
  const words = (t) => { const o = {}; String(t || '').toLowerCase().replace(/\{\{\s*\d+\s*\}\}/g, ' ').split(/[^a-z0-9ऀ-෿]+/).forEach((w) => { if (w.length > 2) o[w] = 1; }); return o; };
  function similar(a, b) { const A = words(a), B = words(b), ka = Object.keys(A); if (ka.length < 4) return 0; let i = 0; ka.forEach((w) => { if (B[w]) i++; }); const u = ka.length + Object.keys(B).length - i; return u ? i / u : 0; }
  let LIST_CACHE = { at: 0, list: [] };
  async function approvedUtilities() { if (Date.now() - LIST_CACHE.at < 60000) return LIST_CACHE.list; try { const d = await api.get('wa.tpl_list'); LIST_CACHE = { at: Date.now(), list: (d.templates || []).filter((t) => t.category === 'UTILITY' && ['approved', 'partial'].indexOf(t.status) >= 0 && !t.relabelled_at && !t.auto) }; } catch (e) { LIST_CACHE = { at: Date.now(), list: [] }; } return LIST_CACHE.list; }

  function openForm(opts) {
    opts = opts || {}; const m = modal(opts.draft && opts.draft.id ? 'Edit pre-made message' : 'New pre-made message'); m.el.querySelector('.wp-mb').classList.add('wt-mb');
    let D = opts.draft ? fromDraft(opts.draft) : blank(); if (opts.draft && opts.draft.id) D.id = opts.draft.id;
    if (opts.draft && opts.draft.copy) { D.id = 0; D.nameAuto = false; }
    let CK = null, chkT = 0, chkSeq = 0, U = null, uEdit = false, folders = DEFAULT_FOLDERS.slice(), aiDone = false, appIdSet = true, lib = [];
    m.body.innerHTML = `<div class="wt-wrap">
      <div class="wt-c"><div class="wt-ai"><b>✨ Tell Claude what it is for — it fills everything below</b><div class="wp-row"><input class="wp-in wp-grow" id="wtAiIn" maxlength="600" placeholder="e.g. Diwali offer for people who asked price last week, with bottle photo and a coupon"><button type="button" class="wp-btn p" id="wtAiGo">Write it</button></div>
        <div class="wp-row" style="margin-top:6px">${['Diwali offer with a bottle photo and a coupon', 'Reminder for customers who said later', 'Order shipped update'].map((x) => `<button type="button" class="wp-chip" data-x="${esc(x)}">${esc(x)}</button>`).join('')}</div></div><div id="wtAiOut"></div></div>
      <div class="wt-a" id="wtA"></div>
      <aside class="wt-prev" id="wtPrev"><div class="wt-ph"><div class="wt-pvh">Preview · as the customer sees it</div><div id="wtPv"></div></div></aside>
      <div class="wt-b" id="wtB"></div></div><button type="button" class="wt-pvbtn" id="wtPvBtn">👁 Preview</button>`;
    const A = $(m.body, '#wtA'), B = $(m.body, '#wtB');
    const kindNow = () => KIND[D.type === 'lto' ? D.ltoKind : D.type] || '';
    const libItem = (id) => lib.find((x) => x.id === id);
    const curFile = () => { const it = libItem(D.libId); return it ? it.filename : ''; };

    // ---- the preview
    function renderPreview() {
      const f = fmtOf(D); const heading = f === 'TEXT' ? fillEx(D.heading.replace(/\{\{\s*1\s*\}\}/g, D.headerEx || 'Rakesh'), D, false) : '';
      $(m.body, '#wtPv').innerHTML = bubble({ type: D.type === 'carousel' ? 'text' : D.type, ltoKind: D.ltoKind, body: D.body.trim() ? fmtWA2(D.body) : '<span style="color:#8696a0">Your message appears here…</span>', heading, footer: D.type === 'lto' ? '' : D.footer, buttons: D.buttons, libId: kindNow() ? D.libId : 0, lto: D.lto, loc: D.loc, fileName: curFile() });
      hydrate($(m.body, '#wtPv'));
    }
    const fmtWA2 = (t) => { const parts = []; let h = esc(t); h = h.replace(/\{\{\s*(\d+)\s*\}\}/g, (mm, n) => { const v = (D.examples[+n - 1] || '').trim(); return v ? '\u0001' + parts.push(esc(v)) + '\u0002' : mm; });
      h = h.replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|[^\w])_([^_\n]+)_(?=[^\w]|$)/g, '$1<i>$2</i>').replace(/~([^~\n]+)~/g, '<s>$1</s>'); return h.replace(/\u0001(\d+)\u0002/g, (mm, i) => '<span class="wt-v">' + parts[+i - 1] + '</span>'); };

    // ---- the check strip
    function schedCheck() { clearTimeout(chkT); chkT = setTimeout(doCheck, 450); }
    async function doCheck() {
      if (!document.body.contains(m.el)) return; const seq = ++chkSeq; const e = $(m.body, '#wtChk'); if (!e) return;
      if (!D.body.trim()) { CK = null; renderCheck(); return; }
      try { const r = await api.post('wa.tpl_check', { draft: toDraft(D) }); if (seq !== chkSeq) return; CK = r; appIdSet = r.app_id_set !== false; } catch (er) { if (seq !== chkSeq) return; CK = { ok: false, problem: er.message, warnings: [] }; }
      renderCheck();
    }
    function renderCheck() {
      const e = $(m.body, '#wtChk'); if (!e) return; let h = '';
      const lim = D.type === 'lto' ? 600 : 1024;
      if (!CK) h = '<div class="wt-chk ok">Write the message (or tell Claude above) — I will check it against Meta’s rules as you type.</div>';
      else if (!CK.ok) h = `<div class="wt-chk bad"><b>Not ready yet:</b> ${esc(CK.problem)}</div>`;
      else h = `<div class="wt-chk ok">✓ Checked against Meta’s rules: variables in order, none at the start or end, examples filled, ≤ ${lim} letters.</div>`;
      if (CK && CK.warnings && CK.warnings.length) h += `<div class="wt-chk warn"><b>Worth a look</b> (Meta may still accept it):<ul>${CK.warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul></div>`;
      if (['photo', 'video', 'pdf', 'lto'].indexOf(D.type) >= 0 && !appIdSet) h += '<div class="wt-chk warn"><b>One thing to set up first:</b> for a message with a photo / video / PDF, Meta needs your app number. Add the line <code>define(\'WA_APP_ID\', \'your app id\');</code> to <b>config.php</b> (find the App ID at developers.facebook.com → My Apps → your app → App settings → Basic).</div>';
      e.innerHTML = h;
    }
    function renderCounter() { const c = $(m.body, '#wtCt'); if (!c) return; const lim = D.type === 'lto' ? 600 : 1024, n = D.body.length; c.textContent = n + ' / ' + lim; c.className = 'wt-ct' + (n > lim ? ' bad' : ''); }
    function syncUtil() { const box = $(m.body, '#wtUtil'); if (!box) return; const need = D.category === 'MARKETING' && D.body.trim(); if ((need && !box.firstChild) || (!need && box.firstChild && !U)) renderUtil(); }
    const touch = () => { renderPreview(); renderCounter(); schedCheck(); syncUtil(); };

    // ---- variables (examples)
    let varSig = '';
    function renderVars(force) {
      const n = vars(D.body); const sig = n.join(',') + '|' + D.labels.join(','); if (!force && sig === varSig) return; varSig = sig; const box = $(m.body, '#wtVars'); if (!box) return;
      box.innerHTML = n.length ? '<label class="wp-fl">An example for each variable <small>(Meta asks for this — the real value is filled when sending)</small></label>' + n.map((x, i) => `<div class="wt-vr"><b>{{${x}}}</b>${sel('wtLb' + i, [['', 'What is it?']].concat(LABELS.map((l) => [l[0], l[0]])), D.labels[i] || '')}<input class="wp-in wtEx" data-i="${i}" maxlength="60" placeholder="Example for {{${x}}}" value="${esc(D.examples[i] || '')}"></div>`).join('') : '';
      $$(box, '.wtEx').forEach((x) => { x.oninput = () => { D.examples[+x.dataset.i] = x.value; touch(); }; });
      n.forEach((x, i) => { const s = $(box, '#wtLb' + i); s.onchange = () => { D.labels[i] = s.value; const l = LABELS.find((q) => q[0] === s.value); const inp = $(box, '.wtEx[data-i="' + i + '"]'); if (l && !inp.value.trim()) { inp.value = l[1]; D.examples[i] = l[1]; } varSig = ''; touch(); }; });
    }
    function insertVar(label, dflt) {
      const ta = $(m.body, '#wtBody'); renumber(D); const v = ta.value = D.body; const n = Math.max(0, ...vars(v)) + 1; let s = ta.selectionStart == null ? v.length : ta.selectionStart; const e = ta.selectionEnd == null ? s : ta.selectionEnd;
      const pre = v.slice(0, s), post = v.slice(e); let ins = '{{' + n + '}}';
      if (label === 'Name' && !pre.trim()) ins = 'Hi {{' + n + '}},' + (post && !/^\s/.test(post) ? ' ' : '');
      else { if (pre && !/\s$/.test(pre)) ins = ' ' + ins; if (post && !/^[\s.,!?:;]/.test(post)) ins += ' '; }
      D.body = pre + ins + post; ta.value = D.body; D.examples[n - 1] = dflt; D.labels[n - 1] = label; renumber(D); ta.value = D.body;
      const pos = (pre + ins).length; ta.focus(); try { ta.setSelectionRange(pos, pos); } catch (x) { } varSig = ''; renderVars(true); autoNames(); touch();
    }
    function autoNames() { if (D.nameAuto && !D.aiName) { D.name = autoName(D); const i = $(m.body, '#wtName'); if (i) i.value = D.name; } if (D.folderAuto) { D.folder = guessFolder(D); renderFolder(); } }

    // ---- folders
    function renderFolder() {
      const box = $(m.body, '#wtFol'); if (!box) return; const all = folders.slice(); if (D.folder && all.indexOf(D.folder) < 0) all.push(D.folder);
      box.innerHTML = ['']. concat(all).map((f) => `<button type="button" class="wp-chip ${D.folder === f ? 'on' : ''}" data-f="${esc(f)}">${f ? esc(f) : 'No folder'}</button>`).join('') + `<input class="wp-in" id="wtFolIn" maxlength="30" placeholder="Other folder…" style="width:150px;min-height:36px;padding:5px 10px" value="">` + (D.folderAuto && D.folder ? '<span class="wt-tp">✨ suggested</span>' : '');
      $$(box, '[data-f]').forEach((b) => { b.onclick = () => { D.folder = b.dataset.f; D.folderAuto = false; renderFolder(); }; });
      const fi = $(box, '#wtFolIn'); fi.onchange = () => { const v = fi.value.trim().slice(0, 30); if (v) { D.folder = v; D.folderAuto = false; renderFolder(); } };
    }

    // ---- what goes on top
    function renderTop() {
      const box = $(m.body, '#wtTop'); if (!box) return; let h = '';
      if (D.type === 'text') h = `<label class="wp-fl">Heading <small>(optional, up to 60 letters — {{1}} puts the name in)</small></label><input class="wp-in" id="wtHead" maxlength="60" value="${esc(D.heading)}">` + (/\{\{\s*1\s*\}\}/.test(D.heading) ? `<label class="wp-fl">Example for the heading</label><input class="wp-in" id="wtHeadEx" maxlength="40" value="${esc(D.headerEx)}">` : '');
      else if (D.type === 'location') h = `<label class="wp-fl">Shop location <small>(shown as a map on top — you can change it for each send)</small></label><div class="wp-2"><div><input class="wp-in" id="wtLat" inputmode="decimal" placeholder="Latitude, e.g. 21.1702" value="${esc(D.loc.lat)}"></div><div><input class="wp-in" id="wtLng" inputmode="decimal" placeholder="Longitude, e.g. 72.8311" value="${esc(D.loc.lng)}"></div></div>
        <div class="wp-2" style="margin-top:6px"><div><input class="wp-in" id="wtLname" maxlength="100" placeholder="Place name" value="${esc(D.loc.name)}"></div><div><input class="wp-in" id="wtLaddr" maxlength="200" placeholder="Address" value="${esc(D.loc.address)}"></div></div><div class="wp-hint">Get the numbers from Google Maps: press and hold the shop on the map — the two numbers at the top are latitude and longitude.</div>`;
      else if (D.type === 'carousel') h = '<div class="wp-note">Carousel messages are coming next.</div>';
      else {
        const k = kindNow(); const isL = D.type === 'lto';
        if (isL) h = `<div class="wp-row" style="margin-top:8px"><span class="wp-hint" style="margin:0">The offer timer goes on a:</span><button type="button" class="wp-chip ${D.ltoKind === 'photo' ? 'on' : ''}" data-lk="photo">Photo</button><button type="button" class="wp-chip ${D.ltoKind === 'video' ? 'on' : ''}" data-lk="video">Video</button></div>
          <div class="wp-2"><div><label class="wp-fl">Timer text <small>(up to 16 letters)</small></label><input class="wp-in" id="wtLtoT" maxlength="16" value="${esc(D.lto.text)}"></div><div><label class="wp-fl">Offer ends after <small>(hours from sending)</small></label><input class="wp-in" id="wtLtoH" type="number" min="1" max="720" value="${esc(D.lto.hours)}"></div></div>
          <div class="wp-hint">An offer-timer message can have up to 600 letters, no footer, and only Website link and Copy code buttons (Meta’s rules).</div>`;
        h += `<label class="wp-fl">Top ${NOUN[k]} <small>(pick from your library — Meta gets a sample copy for you)</small></label><div class="wt-th" id="wtTh"></div><input type="file" id="wtFile" hidden accept="${k === 'image' ? 'image/jpeg,image/png' : k === 'video' ? 'video/mp4' : 'application/pdf,.pdf'}">`;
      }
      box.innerHTML = h;
      const bind = (id, fn) => { const e = $(box, id); if (e) e.oninput = () => { fn(e.value); touch(); }; };
      bind('#wtHead', (v) => { const had = /\{\{\s*1\s*\}\}/.test(D.heading); D.heading = v; if (had !== /\{\{\s*1\s*\}\}/.test(v)) renderTop(); }); bind('#wtHeadEx', (v) => { D.headerEx = v; });
      bind('#wtLat', (v) => { D.loc.lat = v.trim(); }); bind('#wtLng', (v) => { D.loc.lng = v.trim(); }); bind('#wtLname', (v) => { D.loc.name = v; }); bind('#wtLaddr', (v) => { D.loc.address = v; });
      bind('#wtLtoT', (v) => { D.lto.text = v; }); bind('#wtLtoH', (v) => { D.lto.hours = +v || 48; });
      $$(box, '[data-lk]').forEach((b) => { b.onclick = () => { D.ltoKind = b.dataset.lk; const it = libItem(D.libId); if (it && it.kind !== kindNow()) D.libId = 0; renderTop(); touch(); }; });
      if ($(box, '#wtTh')) drawThumbs();
    }
    function drawThumbs() {
      const th = $(m.body, '#wtTh'); if (!th) return; const k = kindNow();
      const items = lib.filter((x) => x.kind === k && (k !== 'video' || x.mime === 'video/mp4') && (k !== 'document' || x.mime === 'application/pdf'));
      th.innerHTML = items.map((x) => `<div class="wt-t ${x.id === D.libId ? 'on' : ''}" data-id="${x.id}"><div class="im" ${k === 'image' ? 'data-th="' + x.id + '"' : ''}>${k === 'image' ? '<span class="fb">🖼</span>' : k === 'video' ? '🎬' : '📄'}</div><span>${esc(x.title)}</span></div>`).join('') + '<button type="button" class="wt-up" id="wtUpB">＋ Upload</button>';
      hydrate(th);
      $$(th, '.wt-t').forEach((t) => { t.onclick = () => { D.libId = +t.dataset.id; drawThumbs(); touch(); }; });
      $(th, '#wtUpB').onclick = () => $(m.body, '#wtFile').click();
      const fi = $(m.body, '#wtFile'); fi.onchange = async () => {
        const f = fi.files[0]; fi.value = ''; if (!f) return; const b = $(th, '#wtUpB'); b.textContent = 'Uploading…'; b.disabled = true;
        try { const fd = new FormData(); fd.append('file', f); fd.append('title', f.name.replace(/\.[^.]+$/, '').slice(0, 80)); const r = await upload('wa.lib_add', fd); lib = await libItems(true); D.libId = r.id; drawThumbs(); touch(); toast('Saved in the media library ✓'); }
        catch (e) { toast(e.message, true); drawThumbs(); }
      };
    }

    // ---- buttons
    function renderButtons() {
      const box = $(m.body, '#wtBtns'); if (!box) return; const lto = D.type === 'lto', n = D.buttons.length, c = (t) => D.buttons.filter((b) => b.type === t).length;
      const can = { QUICK_REPLY: n < 10 && !lto, URL: n < 10 && c('URL') < 2, PHONE_NUMBER: n < 10 && c('PHONE_NUMBER') < 1 && !lto, COPY_CODE: n < 10 && c('COPY_CODE') < 1 };
      const pill = $(m.body, '#wtBc'); pill.textContent = n + ' of 10'; pill.className = 'wp-pill ' + (n >= 10 ? 'warn' : 'ok');
      $(m.body, '#wtBadd').innerHTML = Object.keys(BTN).map((t) => `<button type="button" class="wp-chip" data-add="${t}" ${can[t] ? '' : 'disabled style="opacity:.4"'} title="${can[t] ? '' : (lto && (t === 'QUICK_REPLY' || t === 'PHONE_NUMBER') ? 'Not allowed with an offer timer' : 'Meta’s limit reached')}">＋ ${t === 'QUICK_REPLY' ? 'Quick reply' : t === 'URL' ? 'Website link' : t === 'PHONE_NUMBER' ? 'Call us' : 'Copy code'}</button>`).join('');
      $$(m.body, '[data-add]').forEach((b) => { b.onclick = () => { const t = b.dataset.add; D.buttons.push(t === 'QUICK_REPLY' ? { type: t, text: '' } : t === 'URL' ? { type: t, text: 'Order now', url: 'https://tmperfumehouse.com', example: '' } : t === 'PHONE_NUMBER' ? { type: t, text: 'Call us', phone: '' } : { type: t, text: 'Copy code', example: 'DIWALI20' }); renderButtons(); touch(); const r = $$(box, '.wt-bt'); const last = r[r.length - 1]; const fi = last && last.querySelector('input'); if (fi && t === 'QUICK_REPLY') fi.focus(); }; });
      const list = sortBtns(D.buttons);
      box.innerHTML = list.map((b) => { const k = D.buttons.indexOf(b), ic = BTN[b.type]; let f = '';
        if (b.type === 'QUICK_REPLY') f = `<input class="wp-in" data-k="${k}" data-f="text" maxlength="25" placeholder="Button text, e.g. Yes, send me the list" value="${esc(b.text)}">`;
        else if (b.type === 'URL') { const v = /\{\{\s*1\s*\}\}$/.test(b.url || ''); f = `<input class="wp-in" data-k="${k}" data-f="text" maxlength="25" placeholder="Button text" value="${esc(b.text)}"><input class="wp-in" data-k="${k}" data-f="url" inputmode="url" placeholder="https://tmperfumehouse.com/…" value="${esc(b.url || '')}">` + (v ? `<input class="wp-in" data-k="${k}" data-f="example" maxlength="60" placeholder="Example for the changing ending, e.g. diwali" value="${esc(b.example || '')}"><button type="button" class="wp-btn s" data-k="${k}" data-ending="0">Remove changing ending</button>` : `<button type="button" class="wp-btn s" data-k="${k}" data-ending="1">＋ Add a changing ending {{1}}</button>`); }
        else if (b.type === 'PHONE_NUMBER') f = `<input class="wp-in" data-k="${k}" data-f="text" maxlength="25" placeholder="Button text" value="${esc(b.text)}"><input class="wp-in" data-k="${k}" data-f="phone" inputmode="tel" placeholder="Phone with country code, e.g. 919876543210" value="${esc(b.phone || '')}">`;
        else f = `<input class="wp-in" data-k="${k}" data-f="example" maxlength="15" placeholder="Example code, e.g. DIWALI20" value="${esc(b.example || '')}"><div class="wp-hint" style="margin:0">Customers see “Copy offer code”. The real code is chosen when the message is sent; this one is the sample for Meta.</div>`;
        return `<div class="wt-bt"><div class="h"><span>${ic[0]} ${ic[1]}</span><span class="sp"></span><button type="button" class="wt-x" data-rm="${k}" aria-label="Remove">✕</button></div>${f}</div>`; }).join('') + (n ? '<div class="wp-hint">Quick replies are shown first — Meta needs each kind of button together.</div>' : '');
      $$(box, 'input[data-f]').forEach((i) => { i.oninput = () => { D.buttons[+i.dataset.k][i.dataset.f] = i.value; touch(); }; });
      $$(box, '[data-rm]').forEach((b) => { b.onclick = () => { D.buttons.splice(+b.dataset.rm, 1); renderButtons(); touch(); }; });
      $$(box, '[data-ending]').forEach((b) => { b.onclick = () => { const x = D.buttons[+b.dataset.k]; x.url = (x.url || '').replace(/\{\{\s*1\s*\}\}$/, ''); if (b.dataset.ending === '1') { x.url += '{{1}}'; x.example = x.example || 'sample'; } renderButtons(); touch(); }; });
    }

    // ---- the Utility version
    let SUG = null, sugAsked = false;   // "Use the approved utility version": an already approved Utility message that says nearly the same
    function sugHtml() {
      if (!SUG || opts.noSuggest) return '';
      return `<div class="wmx-sug"><b>A similar Utility message is already approved: ${esc(SUG.name.replace(/_/g, ' '))}</b><div class="wp-hint" style="margin:3px 0 0">${esc(SUG.body.length > 140 ? SUG.body.slice(0, 140) + '…' : SUG.body)}</div><button type="button" class="wp-btn s p" id="wmxUse">Use the approved utility version</button></div>`;
    }
    function checkSimilar() {
      if (sugAsked || D.id || D.category !== 'MARKETING' || D.body.trim().length < 20) return; sugAsked = true;
      approvedUtilities().then((l) => { let best = null, bs = 0; l.forEach((t) => { const x = similar(D.body, t.body); if (x > bs) { bs = x; best = t; } }); if (best && bs >= 0.45) { SUG = best; renderUtil(); } });
    }
    function renderUtil() {
      renderUtil0(); const box = $(m.body, '#wtUtil'); if (!box) return; checkSimilar();
      if (box.innerHTML && SUG) { box.insertAdjacentHTML('afterbegin', sugHtml()); const u = $(box, '#wmxUse'); if (u) u.onclick = () => { const t = SUG; m.close(); if (opts.onUse) opts.onUse(t); else toast('Use “' + t.name + '” - it is approved and ready.'); }; }
    }
    function renderUtil0() {
      const box = $(m.body, '#wtUtil'); if (!box) return;
      if (D.category !== 'MARKETING' || !D.body.trim()) { box.innerHTML = ''; return; }
      if (!U) { box.innerHTML = `<div class="wt-ut"><h4>Utility version <span class="wp-pill b">better chance</span></h4><div class="wp-hint" style="margin:0 0 8px">Meta says yes more often to messages that answer something the customer did. A Utility version is made and sent automatically with every Marketing message and goes only to people who asked or ordered in the last 30 days. Want to see or change it first?</div><button type="button" class="wp-btn" id="wtUGo">See / edit the Utility version</button></div>`; $(box, '#wtUGo').onclick = loadUtil; return; }
      if (U.loading) { box.innerHTML = '<div class="wt-ut"><h4>Utility version</h4><div class="wp-hint">Claude is rewriting it…</div></div>'; return; }
      if (U.error) { box.innerHTML = `<div class="wt-ut"><h4>Utility version</h4>${errBox(U.error)}<button type="button" class="wp-btn" id="wtUGo">Try again</button></div>`; $(box, '#wtUGo').onclick = loadUtil; return; }
      const u = U.draft; const orig = D.body;
      box.innerHTML = `<div class="wt-ut"><h4>Utility version — better chance of Utility approval <span class="wp-pill ${U.source === 'claude' ? 'p' : ''}">${U.source === 'claude' ? '✨ written by Claude' : 'rewritten by rules'}</span></h4>
        <div class="wp-hint" style="margin:2px 0 0">Same message, but about something the customer asked for. Changed words are highlighted. Meta decides the category by itself and may still change it.</div>
        ${uEdit ? `<textarea class="wp-in" id="wtUBody" rows="5" style="margin-top:8px">${esc(u.body)}</textarea>` : `<div class="bx">${wordDiff(orig, u.body)}</div>`}
        ${(u.buttons || []).length ? `<div class="wp-hint">Buttons: ${u.buttons.map((b) => esc(b.type === 'COPY_CODE' ? 'Copy code' : b.text)).join(' · ')}</div>` : ''}
        ${U.changes.length ? `<table>${U.changes.slice(0, 6).map((c) => `<tr><td>${esc(c.from)}</td><td>→ <b>${esc(c.to)}</b><div class="wp-hint" style="margin:0">${esc(c.why)}</div></td></tr>`).join('')}</table>` : ''}
        ${U.tips.length ? `<ul>${U.tips.map((t) => `<li>${esc(t)}</li>`).join('')}</ul>` : ''}
        <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn p" id="wtUBoth">Send both</button><button type="button" class="wp-btn" id="wtUOne">Send only this</button><button type="button" class="wp-btn s" id="wtUEdit">${uEdit ? 'Done editing' : 'Edit this version'}</button><button type="button" class="wp-btn s" id="wtUAgain">Rewrite again</button></div></div>`;
      $(box, '#wtUBoth').onclick = () => send('both'); $(box, '#wtUOne').onclick = () => send('util'); $(box, '#wtUAgain').onclick = loadUtil;
      $(box, '#wtUEdit').onclick = () => { if (uEdit) { const t = $(box, '#wtUBody'); if (t) u.body = t.value; } uEdit = !uEdit; renderUtil(); };
    }
    async function loadUtil() {
      if (!D.body.trim()) return; renumber(D); U = { loading: true }; uEdit = false; renderUtil();
      try { const r = await api.post('wa.tpl_utility', { draft: toDraft(D) }); if (!r.utility_draft) { U = null; renderUtil(); return; } U = { draft: r.utility_draft, changes: r.changes || [], tips: r.tips || [], source: r.source || 'rules' }; }
      catch (e) { U = { error: e.message }; } renderUtil();
    }

    // ---- sending
    function readAll() { renumber(D); const t = $(m.body, '#wtBody'); if (t) t.value = D.body; if (uEdit && U && U.draft) { const x = $(m.body, '#wtUBody'); if (x) U.draft.body = x.value; } }
    function showResult(rs) {
      const one = (r, label) => r.errors && r.errors.length ? `<div class="${r.ok ? 'wp-note' : 'wp-err'}"><b>${esc(label)}: ${r.ok ? 'sent to one account, but the other said' : 'Meta did not accept it'}:</b><br>${r.errors.map(esc).join('<br>')}</div>` : `<div class="wp-ok"><b>${esc(label)}: sent to Meta ✓</b><br>Approval usually takes a few minutes. Press “Refresh from Meta” in the list to see it turn to “Approved”.</div>`;
      let h = `<div class="wp-seg"><button type="button" disabled>1 Write</button><button type="button" disabled>2 Check</button><button class="on" type="button">3 Meta</button></div>`;
      if (rs.main) h += one(rs.main, rs.main.name || 'Message'); if (rs.utility) h += one(rs.utility, rs.utility.name || 'Utility version');
      if (rs.warnings && rs.warnings.length) h += `<div class="wp-note"><b>Worth knowing:</b><ul style="margin:4px 0 0;padding-left:18px">${rs.warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul></div>`;
      h += '<div class="wp-note">Meta decides the category (Marketing or Utility) by itself and may change it after checking the message.</div><div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn p" id="wtDone">Done</button></div>';
      m.body.innerHTML = h; $(m.body, '#wtDone').onclick = () => { m.close(); if (opts.onDone) opts.onDone(); };
    }
    async function send(kind) {
      readAll(); const e = $(m.body, '#wtE'); e.innerHTML = ''; const btns = $$(m.body, '#wtSend,#wtSave,#wtUBoth,#wtUOne'); btns.forEach((b) => { b.disabled = true; });
      try {
        let rs;
        if (kind === 'util') { const d = await api.post('wa.tpl_submit', { draft: U.draft }); rs = { utility: d, warnings: d.warnings }; }
        else if (D.id) {   // a saved draft: save the changes, then send that very row
          await api.post('wa.tpl_save', { id: D.id, draft: toDraft(D) }); const p = await api.post('wa.tpl_push', { id: D.id }); rs = { main: { ok: p.ok, name: D.name, errors: p.errors || [] } };
          if (kind === 'both') { const d = await api.post('wa.tpl_submit', { draft: U.draft }); rs.utility = d; }
        } else { const d = await api.post('wa.tpl_submit', kind === 'both' ? { draft: toDraft(D), also_utility: true, utility_draft: U.draft } : { draft: toDraft(D) }); rs = { main: d, utility: d.utility, warnings: d.warnings }; if (rs.utility) rs.utility.name = rs.utility.name; }
        showResult(rs);
      } catch (er) { e.innerHTML = errBox(er.message); btns.forEach((b) => { b.disabled = false; }); }
    }
    async function save() {
      readAll(); const e = $(m.body, '#wtE'); e.innerHTML = ''; const b = $(m.body, '#wtSave'); b.disabled = true;
      try { const d = await api.post('wa.tpl_save', { id: D.id, draft: toDraft(D) }); D.id = d.id; toast('Draft saved ✓ — it is in the list as “Not sent to Meta”'); m.close(); if (opts.onDone) opts.onDone(); }
      catch (er) { e.innerHTML = errBox(er.message); b.disabled = false; }
    }

    // ---- the two big sections
    function drawA() {
      A.innerHTML = `<div class="wp-2"><div><label class="wp-fl">Category</label><div class="wp-row" id="wtCat">${[['MARKETING', 'Marketing'], ['UTILITY', 'Utility']].map(([v, l]) => `<button type="button" class="wp-chip ${D.category === v ? 'on' : ''}" data-c="${v}">${l}</button>`).join('')}</div><div class="wp-hint">${D.category === 'UTILITY' ? 'Utility = only about an order, delivery, payment or something the customer asked for. No offers.' : 'Marketing = offers, reminders, news. Meta decides the final category.'}</div></div>
        <div><label class="wp-fl">Language</label>${sel('wtLang', [['en', 'Hinglish (English letters)'], ['hi', 'Hindi (देवनागरी)'], ['gu', 'Gujarati (ગુજરાતી)']], D.language)}</div></div>
        <label class="wp-fl">Name <small>(small letters and _)</small></label><input class="wp-in" id="wtName" maxlength="60" value="${esc(D.name)}" placeholder="made from your message — you can change it">
        <label class="wp-fl">Type — what goes on top</label><div class="wp-row" id="wtType">${TYPES.map(([k, l, ic]) => k === 'carousel' ? `<button type="button" class="wp-chip" disabled style="opacity:.5" title="Coming next">${ic} ${l} <small>· coming next</small></button>` : `<button type="button" class="wp-chip ${D.type === k ? 'on' : ''}" data-t="${k}">${ic} ${l}</button>`).join('')}</div>
        <div id="wtTop"></div>
        <label class="wp-fl">Message <small>(use the buttons to add a variable)</small></label><textarea class="wp-in" id="wtBody" rows="5" maxlength="1100" placeholder="Hi {{1}}, …">${esc(D.body)}</textarea>
        <div class="wp-row" style="margin:6px 0">${[['Name', '＋ Name', 'Rakesh'], ['Perfume', '＋ Perfume they asked', 'Royal Oud'], ['Coupon', '＋ Coupon', 'DIWALI20'], ['Date', '＋ Date', '12 Nov'], ['Other', '＋ Other', 'Sample']].map((x) => `<button type="button" class="wp-chip" data-v="${x[0]}" data-d="${esc(x[2])}">${esc(x[1])}</button>`).join('')}<span class="wt-ct" id="wtCt"></span></div>
        <div id="wtVars"></div>
        <div id="wtFootBox"><label class="wp-fl">Footer <small>(optional, up to 60 letters${D.type === 'lto' ? ' — not allowed with an offer timer' : ''})</small></label><input class="wp-in" id="wtFoot" maxlength="60" value="${esc(D.footer)}" ${D.type === 'lto' ? 'disabled' : ''}></div>
        <label class="wp-fl">Folder</label><div class="wt-fol" id="wtFol"></div>`;
      $$(A, '[data-c]').forEach((b) => { b.onclick = () => { D.category = b.dataset.c; if (D.folderAuto) D.folder = guessFolder(D); U = D.category === 'MARKETING' ? U : null; drawA(); drawB(); }; });
      $(A, '#wtLang').onchange = function () { D.language = this.value; touch(); };
      const nm = $(A, '#wtName'); nm.oninput = () => { D.nameAuto = false; D.aiName = false; D.name = nm.value; schedCheck(); }; nm.onblur = () => { D.name = slug(nm.value); nm.value = D.name; if (!D.name) { D.nameAuto = true; autoNames(); } };
      $$(A, '[data-t]').forEach((b) => { b.onclick = () => { const t = b.dataset.t; if (t === D.type) return; if (t === 'lto' && D.type === 'video') D.ltoKind = 'video'; else if (t === 'lto') D.ltoKind = 'photo'; D.type = t; const k = kindNow(), it = libItem(D.libId); if (!k || (it && it.kind !== k)) D.libId = 0; if (t === 'lto') { D.footer = ''; D.buttons = D.buttons.filter((x) => x.type === 'URL' || x.type === 'COPY_CODE'); } drawA(); drawB(); }; });
      const ta = $(A, '#wtBody'); ta.oninput = () => { D.body = ta.value; renderVars(false); autoNames(); touch(); }; ta.onblur = () => { renumber(D); ta.value = D.body; renderVars(true); renderPreview(); schedCheck(); };
      $$(A, '[data-v]').forEach((b) => { b.onmousedown = (e) => e.preventDefault(); b.onclick = () => insertVar(b.dataset.v, b.dataset.d); });
      const ft = $(A, '#wtFoot'); ft.oninput = () => { D.footer = ft.value; touch(); };
      renderTop(); renderVars(true); renderFolder(); renderCounter();
    }
    function drawB() {
      B.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px"><label class="wp-fl" style="margin:0">Buttons <small>(up to 10)</small></label><span class="wp-pill ok" id="wtBc">0 of 10</span></div>
        <div class="wp-row" style="margin:6px 0 8px" id="wtBadd"></div><div id="wtBtns"></div><div id="wtChk"></div><div id="wtUtil"></div><div id="wtE"></div>
        <div class="wp-row" style="margin-top:14px"><button type="button" class="wp-btn p" id="wtSend">Send to Meta for approval</button><button type="button" class="wp-btn" id="wtSave">Save draft</button></div>`;
      $(B, '#wtSend').onclick = () => send('one'); $(B, '#wtSave').onclick = save;
      renderButtons(); renderCheck(); renderUtil(); renderPreview(); schedCheck();
    }

    // ---- Claude fills the form
    const aiIn = $(m.body, '#wtAiIn'), aiGo = $(m.body, '#wtAiGo');
    $$(m.body, '[data-x]').forEach((b) => { b.onclick = () => { aiIn.value = b.dataset.x; aiIn.focus(); }; });
    aiIn.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); aiGo.click(); } };
    aiGo.onclick = async function () {
      const text = aiIn.value.trim(); if (!text) { $(m.body, '#wtAiOut').innerHTML = errBox(aiDone ? 'Tell me what to change first.' : 'Write what it is for first.'); return; }
      this.disabled = true; const was = this.textContent; this.textContent = 'Thinking…'; $(m.body, '#wtAiOut').innerHTML = '';
      try {
        readAll(); const body = aiDone ? { purpose: D.purpose || text, fix: text, draft: toDraft(D) } : { purpose: text };
        const d = await api.post('wa.tpl_understand', body); const keep = { libId: D.libId, kind: (libItem(D.libId) || {}).kind };
        const nd = fromDraft(d.draft, keep); nd.nameAuto = false; nd.aiName = true; nd.folderAuto = !d.draft.folder; nd.id = D.id; if (!aiDone) nd.purpose = text; else nd.purpose = D.purpose || text; D = nd; U = null; uEdit = false; aiDone = true; varSig = '';
        $(m.body, '#wtAiOut').innerHTML = (D.understood ? `<div class="wt-und"><small>How I understood you</small>${esc(D.understood)}</div>` : '') + (d.problem ? `<div class="wp-note">${esc(d.problem)}</div>` : '');
        aiIn.value = ''; aiIn.placeholder = 'Not quite right? Tell me what to change, e.g. make it shorter, add a discount line'; this.textContent = 'Change it';
        drawA(); drawB(); if (D.category === 'MARKETING' && D.body.trim() && !d.problem) loadUtil();
      } catch (e) { $(m.body, '#wtAiOut').innerHTML = errBox(e.message); this.textContent = was; }
      this.disabled = false;
    };
    $(m.body, '#wtPvBtn').onclick = () => { const p = $(m.body, '#wtPrev'); p.classList.toggle('show'); $(m.body, '#wtPvBtn').textContent = p.classList.contains('show') ? '✕ Close preview' : '👁 Preview'; };

    // ---- start
    drawA(); drawB();
    Promise.all([libItems().catch(() => []), api.get('wa.tpl_list').catch(() => null)]).then(([l, d]) => {
      lib = l || []; if (d) { appIdSet = d.app_id_set !== false; (d.folders || []).forEach((f) => { if (folders.indexOf(f.name) < 0) folders.push(f.name); }); }
      if (!document.body.contains(m.el)) return; if (D.libId && !libItem(D.libId)) D.libId = 0; renderTop(); renderFolder(); renderPreview(); renderCheck();
    });
    if (!aiDone && !D.body) setTimeout(() => aiIn.focus(), 60);
    return m;
  }

  // ================================================================================================================ THE LIST
  const STAT = { approved: ['Approved', 'ok'], partial: ['Approved', 'ok'], pending: ['Waiting for Meta', 'warn'], rejected: ['Rejected', 'bad'], paused: ['Paused by Meta', 'bad'], missing: ['Not sent to Meta', ''] };
  WaPanels.add('premade', (el, ctx) => {
    const S = { list: [], filter: 'all', folder: '*', q: '', counts: {}, accounts: 1, can: false, folders: [], appId: true, uf: 'all', auto: null, hl: 0 };
    el.innerHTML = loading;
    async function load() {
      try { const d = await api.get('wa.tpl_list'); S.list = d.templates || []; S.counts = d.counts || {}; S.accounts = d.accounts || 1; S.can = !!d.can_edit; S.folders = (d.folders || []).map((f) => f.name); S.appId = d.app_id_set !== false; S.auto = d.auto || null; } catch (e) { el.innerHTML = errBox(e.message); return; }
      libItems(true).catch(() => { }); draw();
    }
    const okStatus = (t) => ['approved', 'partial'].indexOf(t.status) >= 0;
    function draw() {
      const c = S.counts, q = S.q.trim().toLowerCase(), fl = S.list.map((t) => t.folder).filter(Boolean); const folders = S.folders.slice(); fl.forEach((f) => { if (folders.indexOf(f) < 0) folders.push(f); });
      const kid = {}; S.list.forEach((p) => (p.twins || []).forEach((x) => { kid[x.id] = 1; }));   // a Utility version is shown inside its Marketing message, not as a card of its own
      const uMatch = (t) => S.uf === 'all' || (S.uf === 'uapproved' ? t.utility_state === 'approved' : (S.uf === 'monly' ? (!t.is_twin && t.category === 'MARKETING' && t.utility_state !== 'approved') : (t.utility_state === 'relabelled' || t.utility_state === 'stopped' || (t.twins || []).some((x) => x.status === 'relabelled' || x.relabelled_at))));
      const rows = S.list.filter((t) => !kid[t.id] && uMatch(t) && (S.filter === 'all' || (S.filter === 'approved' ? okStatus(t) : (S.filter === 'pending' ? t.status === 'pending' : ['rejected', 'paused', 'missing'].indexOf(t.status) >= 0)))
        && (S.folder === '*' || (S.folder === '' ? !t.folder : t.folder === S.folder)) && (!q || (t.name + ' ' + t.body + ' ' + t.folder).toLowerCase().indexOf(q) >= 0));
      const uc = (f) => S.list.filter((t) => !kid[t.id] && (f === 'uapproved' ? t.utility_state === 'approved' : (f === 'monly' ? (!t.is_twin && t.category === 'MARKETING' && t.utility_state !== 'approved') : (t.utility_state === 'relabelled' || t.utility_state === 'stopped' || (t.twins || []).some((x) => x.status === 'relabelled' || x.relabelled_at))))).length;
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Pre-made messages</h2><p>One message works on every number. Meta must approve each one once (usually a few minutes).</p></div><button type="button" class="wp-btn" id="tSync">Refresh from Meta</button>${S.can ? '<button type="button" class="wp-btn p" id="tNew">＋ New message</button>' : ''}</div>
        ${autoCard()}
        <div class="wp-kpis"><div class="wp-kpi"><small>Approved</small><b>${c.approved || 0}</b></div><div class="wp-kpi"><small>Waiting for Meta</small><b>${c.pending || 0}</b></div><div class="wp-kpi"><small>Rejected</small><b style="${c.rejected ? 'color:#b42318' : ''}">${c.rejected || 0}</b></div></div>
        <div class="wp-row" style="margin-bottom:8px">${[['all', 'All'], ['approved', 'Approved'], ['pending', 'Waiting'], ['bad', 'Rejected / other']].map(([v, l]) => `<button type="button" class="wp-chip ${S.filter === v ? 'on' : ''}" data-f="${v}">${l}</button>`).join('')}</div>
        <div class="wmx-fl"><span class="wp-hint">Utility version:</span>${[['all', 'All'], ['uapproved', 'Utility approved'], ['monly', 'Marketing only'], ['relabelled', 'Re-labelled']].map(([v, l]) => `<button type="button" class="wp-chip ${S.uf === v ? 'on' : ''}" data-uf="${v}">${l}${v === 'all' ? '' : ' <small>' + uc(v) + '</small>'}</button>`).join('')}</div>
        <div class="wt-fi"><span class="wp-hint" style="margin:0 4px 0 0">Folder:</span><button type="button" class="wp-chip ${S.folder === '*' ? 'on' : ''}" data-fo="*">All</button>${folders.map((f) => `<button type="button" class="wp-chip ${S.folder === f ? 'on' : ''}" data-fo="${esc(f)}">${esc(f)} <small>${S.list.filter((t) => t.folder === f).length}</small></button>`).join('')}<button type="button" class="wp-chip ${S.folder === '' ? 'on' : ''}" data-fo="">No folder</button></div>
        <input class="wp-in" id="tQ" type="search" placeholder="Search…" value="${esc(S.q)}" style="margin-bottom:12px"><div class="wp-grid" id="tL">
        ${rows.length ? rows.map(card).join('') : '<div class="wp-empty" style="grid-column:1/-1">No message here yet.</div>'}</div></div>`;
      el.querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { S.filter = b.dataset.f; draw(); }; });
      el.querySelectorAll('[data-fo]').forEach((b) => { b.onclick = () => { S.folder = b.dataset.fo; draw(); }; });
      el.querySelectorAll('[data-uf]').forEach((b) => { b.onclick = () => { S.uf = b.dataset.uf; draw(); }; });
      el.querySelectorAll('[data-mktwin]').forEach((b) => { b.onclick = async () => { b.disabled = true; b.textContent = 'Making it…'; try { await api.post('wa.tpl_twin_all', { id: +b.dataset.mktwin }); toast('Utility version sent for approval ✓'); } catch (e) { toast(e.message, true); } load(); }; });
      el.querySelectorAll('[data-auto]').forEach((b) => { b.onclick = async () => { b.disabled = true; try { const d = await api.post('wa.tpl_auto', b.dataset.auto === 'run' ? { run: true } : { auto: !(S.auto && S.auto.on) }); S.auto = d.auto; if (b.dataset.auto === 'run') toast('Done ✓'); } catch (e) { toast(e.message, true); } load(); }; });
      if (S.hl) { const h = el.querySelector('[data-card="' + S.hl + '"]'); if (h) { h.scrollIntoView({ block: 'center' }); h.style.boxShadow = '0 0 0 3px #25d366'; } S.hl = 0; }
      const q2 = $(el, '#tQ'); q2.oninput = function () { S.q = this.value; const p = this.selectionStart; draw(); const n = $(el, '#tQ'); n.focus(); try { n.setSelectionRange(p, p); } catch (e) { } };
      $(el, '#tSync').onclick = async function () { this.disabled = true; this.textContent = 'Asking Meta…'; try { const d = await api.post('wa.tpl_sync', {}); toast(d.errors && d.errors.length ? d.errors[0] : 'Updated ✓', !!(d.errors && d.errors.length)); } catch (e) { toast(e.message, true); } load(); };
      const nw = $(el, '#tNew'); if (nw) nw.onclick = () => openForm({ onDone: load, onUse: (t) => { S.hl = t.id; S.uf = 'all'; S.filter = 'all'; S.folder = '*'; S.q = ''; draw(); } });
      const T = (id) => S.list.find((x) => x.id === +id);
      el.querySelectorAll('[data-star]').forEach((b) => { b.onclick = async () => { try { await api.post('wa.tpl_star', { id: +b.dataset.star }); load(); } catch (e) { toast(e.message, true); } }; });
      el.querySelectorAll('[data-diag]').forEach((b) => { b.onclick = () => tplDiagnose(+b.dataset.diag, b.closest('[data-card]')); });
      el.querySelectorAll('[data-uv]').forEach((b) => { b.onclick = () => utilVersions(+b.dataset.uv); });
      el.querySelectorAll('[data-push]').forEach((b) => { b.onclick = async () => { b.disabled = true; try { const d = await api.post('wa.tpl_push', { id: +b.dataset.push }); toast(d.errors && d.errors.length ? d.errors[0] : 'Sent to Meta ✓', !!(d.errors && d.errors.length)); } catch (e) { toast(e.message, true); } load(); }; });
      el.querySelectorAll('[data-del]').forEach((b) => { b.onclick = async () => { if (!confirm('Delete this message everywhere (also at Meta)?')) return; try { await api.post('wa.tpl_delete', { id: +b.dataset.del }); load(); } catch (e) { toast(e.message, true); } }; });
      el.querySelectorAll('[data-copy]').forEach((b) => { b.onclick = () => { const t = T(b.dataset.copy); const d = Object.assign({}, t, { id: 0, copy: true, name: t.name + '_copy' }); openForm({ draft: d, onDone: load }); }; });
      el.querySelectorAll('[data-edit]').forEach((b) => { b.onclick = () => openForm({ draft: T(b.dataset.edit), onDone: load }); });
      el.querySelectorAll('[data-fsel]').forEach((s) => { s.onchange = async () => { let f = s.value; if (f === '__new') { f = (prompt('Folder name (up to 30 letters):') || '').trim().slice(0, 30); if (!f) { draw(); return; } } try { await api.post('wa.tpl_folder', { id: +s.dataset.fsel, folder: f === '__none' ? '' : f }); load(); } catch (e) { toast(e.message, true); draw(); } }; });
      el.querySelectorAll('[data-pick]').forEach((b) => { b.onclick = () => pickFile(T(b.dataset.pick)); });
      hydrate(el);
    }
    function autoCard() {
      const a = S.auto; if (!a) return '';
      const lim = a.limits || [], bar = (n, c) => `<div class="wp-bar"><i class="${n >= c ? 'b' : (n >= c * 0.8 ? 'w' : '')}" style="width:${Math.min(100, Math.round(n * 100 / Math.max(1, c)))}%"></i></div>`;
      return `<div class="wmx-auto"><h3>Auto-approval <span class="wmx-pill ${a.on ? 'ok' : 'warn'}">${a.on ? 'On' : 'Off'}</span><span class="sp"></span>${S.can ? `<button type="button" class="wp-btn s" data-auto="toggle">${a.on ? 'Turn off' : 'Turn on'}</button><button type="button" class="wp-btn s" data-auto="run">Run now</button>` : ''}</h3>
        <div class="wp-hint" style="margin:0">${a.on ? `Every message goes to all ${a.accounts} WhatsApp account${a.accounts === 1 ? '' : 's'} by itself, together with its Utility version - a few at a time, so Meta’s limits are never hit. A rejected or re-labelled message gets a new wording by itself (up to 3 versions).` : 'Messages are sent to Meta only when you press the button. Turn this on to let Claude send everything to every account and write new versions when Meta says no.'}</div>
        <div class="wmx-nums"><div><small>Sent today</small><b>${a.submitted_today || 0}</b></div><div><small>Waiting</small><b>${a.waiting || 0}</b></div><div><small>Approved</small><b>${a.approved || 0}</b></div><div><small>Rejected</small><b style="${a.rejected ? 'color:#b42318' : ''}">${a.rejected || 0}</b></div><div><small>New versions</small><b>${a.alternatives_made || 0}</b></div></div>
        ${a.missing_submissions ? `<div class="wp-hint" style="margin:0 0 4px">${a.missing_submissions} message${a.missing_submissions === 1 ? ' is' : 's are'} not on every account yet - they go out in the next rounds.</div>` : ''}
        ${(a.needs_human || []).map((h) => `<div class="wmx-hu"><b>${esc(String(h.name).replace(/_/g, ' '))}</b> - ${esc(h.why)}. Meta keeps saying no, so please write it in a different way.</div>`).join('')}
        ${lim.length ? `<details><summary>Meta’s limits per account</summary>${lim.map((l) => `<div class="wmx-lim"><span style="min-width:90px">Account …${esc(String(l.waba).slice(-4))}</span>${bar(l.day, l.day_cap)}<span>${l.day}/${l.day_cap} today · ${l.total}/${l.total_cap} in all</span></div>`).join('')}${a.last_run ? `<div class="wp-hint">Last round: ${esc(a.last_run)}</div>` : ''}</details>` : ''}</div>`;
    }
    function utilBlock(t) {
      if (t.is_twin || t.category !== 'MARKETING') return '';
      const tw = (t.twins || []), us = t.utility_state || 'none', st = UST[us] || UST.none;
      if (!tw.length) return `<div class="wmx-u none"><b>Utility version <span class="wmx-pill">None yet</span></b><div class="wmx-l">Claude makes one automatically. It goes only to people who asked or ordered in the last 30 days.</div>${S.can && okStatus(t) ? `<button type="button" class="wp-btn s" data-mktwin="${t.id}">Make it now</button>` : ''}</div>`;
      const lines = tw.slice().reverse().map((x) => {
        const s2 = UST[x.status] || UST.waiting, nx = tw.find((y) => y.version > x.version);
        let more = '';
        if (x.status === 'relabelled') more = `<small>Meta re-labelled it as marketing${x.relabelled_at ? ' on ' + esc(dShort(x.relabelled_at)) : ''}${nx ? ' → new utility sent for approval (v' + nx.version + ')' : (x.version >= 3 ? ' - needs a human to rewrite' : ' → a stricter version is being made')}</small>`;
        else if (x.status === 'rejected') more = '<small>Meta said no - a new wording will be tried automatically.</small>';
        return `<div class="wmx-l"><span class="wmx-pill ${s2[1]}">${s2[0]}</span> <b>v${x.version}</b> <small>${esc(String(x.name).replace(/_/g, ' '))}</small>${more}${chipsHtml(x.matrix)}</div>`;
      }).join('');
      return `<div class="wmx-u ${us}"><b>Utility version <span class="wmx-pill ${st[1]}">${st[0]}</span></b>${lines}${t.stop ? `<div class="wmx-hu">${esc(t.stop)}</div>` : ''}</div>`;
    }
    function famLine(t) {
      const f = (t.family || []).filter((x) => x.id !== t.id && !(x.line === 'U' && (t.twins || []).some((y) => y.id === x.id) && x.version === 1));
      return f.length ? `<div class="wp-hint">Other versions of this message: ${f.map((x) => `${x.line === 'U' ? 'Utility' : 'Marketing'} v${x.version} (${esc((UST[x.status] || ['', ''])[0].toLowerCase() || x.status)})`).join(' · ')}</div>` : '';
    }
    function card(t) {
      const s = t.status === 'partial' && t.accounts ? [`Approved on ${t.ok_n} of ${t.accounts} — ready to send`, 'ok'] : (STAT[t.status] || ['', '']), type = t.type === 'LTO' ? 'lto' : ({ IMAGE: 'photo', VIDEO: 'video', DOCUMENT: 'pdf', LOCATION: 'location' }[t.header_format] || 'text');
      const bd = fillEx(t.body, { examples: t.var_examples || [] }, true).replace(/\{\{\s*(\d+)\s*\}\}/g, '<span class="wt-v">{{$1}}</span>');
      const media = ['photo', 'video', 'pdf'].indexOf(type) >= 0 || (type === 'lto' && t.header_format !== 'TEXT');
      const heading = t.header_format === 'TEXT' && t.header_text ? esc(t.header_text) : '';
      const fol = S.folders.slice(); if (t.folder && fol.indexOf(t.folder) < 0) fol.push(t.folder);
      return `<div class="wp-cd wt-card" data-card="${t.id}"><h3>${t.star ? '⭐ ' : ''}${esc(t.name.replace(/_/g, ' '))}<span class="sp"></span><span class="wp-pill ${s[1]}">${s[0] || esc(t.status)}</span><button type="button" class="wp-btn s wt-dg ${t.status === 'approved' ? '' : 'hot'}" data-diag="${t.id}" title="Why is it waiting / rejected? Checks every WhatsApp account live with Meta and gives a report to copy">🩺 Diagnose</button></h3>
        <div><span class="wt-tp">${TYPE_NAME[t.type] || 'Text'}</span><span class="wp-pill ${t.category === 'UTILITY' ? 'ok' : ''}">${t.category === 'UTILITY' ? 'Utility' : 'Marketing'}</span>${t.folder ? `<span class="wp-pill o">${esc(t.folder)}</span>` : ''}${t.utility_id ? '<span class="wp-pill p">has a Utility version</span>' : ''}${t.utility_of ? '<span class="wp-pill p">Utility version</span>' : ''}</div>
        ${bubble({ type, ltoKind: t.header_format === 'VIDEO' ? 'video' : 'photo', body: bd, heading, footer: t.footer, buttons: t.buttons || [], libId: t.header_lib_id || 0, lto: t.lto || {}, loc: t.location || {}, fileName: '' })}
        <div class="wp-hint">${esc(t.language)} · used ${t.used} time${t.used === 1 ? '' : 's'}${media && !t.header_lib_id ? ' · <b style="color:#b45309">no file chosen for the top yet</b>' : ''}${t.unsupported ? ' · has a part that cannot be sent from here' : ''}${t.draft ? ' · saved draft' : ''}</div>
        ${t.matrix && t.matrix.length > 1 ? `<div class="wmx-cap">On each number</div>${chipsHtml(t.matrix)}` : ''}${utilBlock(t)}${t.learned ? `<div class="wmx-learn"><b>What Claude learned:</b> ${esc(t.learned)}</div>` : ''}${famLine(t)}
        ${t.status === 'rejected' && t.reason ? `<div class="wp-err">Meta’s reason: ${esc(t.reason)}</div>` : ''}${t.status === 'partial' ? `<div class="wp-note">Ready to send now from the numbers of the ${t.ok_n || 'approved'} approved WhatsApp account${t.ok_n === 1 ? '' : 's'}; ${t.missing ? 'not created yet in the other account. ' : 'the other account is still waiting for Meta (or rejected it) — those numbers join by themselves once Meta approves. '}${t.missing && S.can ? `<button type="button" class="wp-btn s" data-push="${t.id}">Copy to the other account</button>` : ''}</div>` : ''}
        ${t.category === 'MARKETING' && !t.draft ? `<div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" data-uv="${t.id}">💸 Utility versions</button><span class="wp-hint" style="margin:0">made by themselves · variables · status on each number</span></div>` : ''}
        <div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" data-star="${t.id}">${t.star ? 'Remove ⭐' : '⭐ Star'}</button>${S.can ? `${t.draft ? `<button type="button" class="wp-btn s" data-edit="${t.id}">Edit</button><button type="button" class="wp-btn s p" data-push="${t.id}">Send to Meta</button>` : ''}${media ? `<button type="button" class="wp-btn s" data-pick="${t.id}">${t.header_lib_id ? 'Change file' : 'Choose file'}</button>` : ''}<button type="button" class="wp-btn s" data-copy="${t.id}">Use as base</button><button type="button" class="wp-btn s r" data-del="${t.id}">Delete</button>` : ''}</div>
        ${S.can ? `<div class="wp-row" style="margin-top:8px"><span class="wp-hint" style="margin:0">Folder</span><select class="wp-in" data-fsel="${t.id}" style="width:auto;min-height:34px;padding:4px 8px"><option value="__none" ${t.folder ? '' : 'selected'}>No folder</option>${fol.map((f) => `<option ${f === t.folder ? 'selected' : ''}>${esc(f)}</option>`).join('')}<option value="__new">＋ New folder…</option></select></div>` : ''}</div>`;
    }
    // 💸 the utility versions of one marketing message (3 are made by themselves): text with variables, what each variable is, status on every number
    async function utilVersions(id) {
      const m = modal('💸 Utility versions'); const ST = { approved: ['✅ Approved', '#dcfce7', '#166534'], pending: ['⏳ Waiting for Meta', '#fef3c7', '#92400e'], rejected: ['❌ Rejected', '#fee2e2', '#991b1b'], paused: ['⏸ Paused', '#fee2e2', '#991b1b'], error: ['⚠ Will retry', '#fef3c7', '#92400e'], 'not sent': ['· not sent yet', '#f1f5f9', '#475569'] };
      const chip = (txt, bg, fg) => `<span style="display:inline-block;font-size:11.5px;font-weight:700;border-radius:99px;padding:2px 8px;margin:4px 4px 0 0;background:${bg};color:${fg}">${txt}</span>`;
      const draw = async () => {
        m.body.innerHTML = '<div class="wp-hint">Loading…</div>'; let L; try { L = ((await api.get('wa.tpl_utility_list', { tpl_id: id })).items || []).filter((u) => u.mine); } catch (e) { m.body.innerHTML = errBox(e.message); return; }
        m.body.innerHTML = `<div class="wp-hint" style="margin-top:0">Used by campaigns (your utility %), only for customers who wrote or ordered in the last 30 days. HRMS fills the variables by itself. A version Meta moves to marketing is not changed — it is just not used.</div>`
          + (L.length ? L.map((u) => `<div style="border:1px solid #e3e8ef;border-radius:12px;padding:10px;margin-top:8px;${u.relabelled ? 'opacity:.6' : ''}"><div style="font-size:13.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word">${esc(u.body).replace(/\{\{(\d)\}\}/g, '<b style="background:#ede9fe;color:#5b21b6;border-radius:5px;padding:0 3px">{{$1}}</b>')}</div>
            <div style="font-size:12px;color:#475569;margin-top:6px">${(u.vars || []).map((v) => `<b style="color:#5b21b6">{{${v.n}}}</b> = ${esc(v.what || '?')}`).join(' · ')}</div>
            <div>${u.relabelled ? chip('🔁 Meta moved it to marketing — not used', '#fee2e2', '#991b1b') : (u.per || []).map((p) => { const z = ST[p.status] || [esc(p.status), '#f1f5f9', '#475569']; return chip(esc(p.label) + ': ' + z[0], z[1], z[2]); }).join('')}</div></div>`).join('') : '<div class="wp-note">None yet — they are made by themselves within a few minutes. Or make them now:</div>')
          + (S.can ? '<div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn s p" id="uvMk">✨ Make 3 more</button></div>' : '');
        const mk = m.body.querySelector('#uvMk'); if (mk) mk.onclick = async () => { mk.disabled = true; mk.textContent = '✨ Claude is writing…'; try { const r = await api.post('wa.tpl_make_utility', { tpl_id: id, n: 3 }); toast(r.made.length + ' sent to Meta for approval ✓'); draw(); } catch (e) { toast(e.message, true); mk.disabled = false; mk.textContent = '✨ Make 3 more'; } };
      };
      draw();
    }
    // 🩺 why is this message waiting / rejected — every account, ours vs Meta live (wa.tpl_diagnose); a status Meta changed without telling us is corrected on the spot
    async function tplDiagnose(id) {
      const m = modal('🩺 Diagnose this message'); m.body.innerHTML = '<div class="wp-hint">Asking Meta about this message on every WhatsApp account… (up to a minute)</div>' + loading;
      let d; try { d = await api.get('wa.tpl_diagnose', { id }); } catch (e) { m.body.innerHTML = errBox(e.message); return; }
      if (d.error) { m.body.innerHTML = errBox(d.error); return; }
      const col = { bad: ['#fdecec', '#b42318', '❌'], warn: ['#fff7e6', '#9a6700', '⚠️'], ok: ['#ecfdf3', '#067647', '✅'] };
      m.body.innerHTML = `<div class="wp-hint" style="margin-top:0">Checked just now — your HRMS data and Meta live. Approved on <b>${d.ok_n} of ${d.accounts}</b> account${d.accounts === 1 ? '' : 's'}${d.fixed ? ' · <b>' + d.fixed + ' status corrected from Meta</b>' : ''}.</div>
        ${d.verdict.map((v, i) => `<div style="background:${col[v.level][0]};border-radius:10px;padding:10px 12px;margin:8px 0"><b style="color:${col[v.level][1]}">${col[v.level][2]} ${i + 1}. ${esc(v.title)}</b>${v.detail ? `<div style="margin-top:4px;font-size:13px">${esc(v.detail)}</div>` : ''}${v.fix ? `<div style="margin-top:4px;font-size:13px"><b>What to do:</b> ${esc(v.fix)}</div>` : ''}</div>`).join('')}
        <details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700">All the facts (copy this to send)</summary><pre id="tDgR" style="white-space:pre-wrap;font-size:12px;background:#f6f7f9;border-radius:8px;padding:10px;margin-top:6px;max-height:340px;overflow:auto">${esc(d.report)}</pre></details>
        <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn p" id="tDgC">📋 Copy the report</button></div>`;
      $(m.body, '#tDgC').onclick = async () => { try { await navigator.clipboard.writeText(d.report); toast('Copied ✓'); } catch (e) { const r = document.createRange(); r.selectNodeContents($(m.body, '#tDgR')); const sl = getSelection(); sl.removeAllRanges(); sl.addRange(r); toast('Selected — press Ctrl + C'); } };
      if (d.fixed) load();
    }
    async function pickFile(t) {
      const k = { IMAGE: 'image', VIDEO: 'video', DOCUMENT: 'document' }[t.header_format]; if (!k) return; const m = modal('Choose the ' + NOUN[k] + ' for the top'); m.body.innerHTML = loading;
      let items = []; try { items = (await libItems(true)).filter((x) => x.kind === k); } catch (e) { m.body.innerHTML = errBox(e.message); return; }
      m.body.innerHTML = `<div class="wp-hint" style="margin:0 0 8px">This ${NOUN[k]} goes on top every time this message is sent. Meta keeps its own sample from when the message was approved.</div>` + (items.length ? `<div class="wt-th" style="flex-wrap:wrap">${items.map((x) => `<div class="wt-t ${x.id === t.header_lib_id ? 'on' : ''}" data-id="${x.id}"><div class="im" ${k === 'image' ? 'data-th="' + x.id + '"' : ''}>${k === 'image' ? '<span class="fb">🖼</span>' : k === 'video' ? '🎬' : '📄'}</div><span>${esc(x.title)}</span></div>`).join('')}</div>` : '<div class="wp-empty">Nothing of that kind in the media library yet. Add it in <b>Media library</b> first.</div>');
      hydrate(m.body);
      $$(m.body, '.wt-t').forEach((x) => { x.onclick = async () => { try { await api.post('wa.tpl_attach', { id: t.id, lib_id: +x.dataset.id }); m.close(); toast('Saved ✓'); load(); } catch (e) { toast(e.message, true); } }; });
    }
    load(); return load;
  });

  window.WaTpl = { openForm, ver: 'wt1', toDraft: (d) => toDraft(d), blank };
})();
