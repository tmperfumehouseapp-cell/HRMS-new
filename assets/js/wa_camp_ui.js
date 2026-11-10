/**
 * wa_camp_ui.js — the Campaigns screen of the WhatsApp console: list, the 3-step campaign wizard (Who · What · When & retries),
 * the "who got what" list of one campaign (with CSV) and the message pieces library. Loaded after wa_panels.js / wa_growth.js and
 * REPLACES the screen 'campaigns' (WaPanels.add) — wa_panels.js itself is not touched. Phone first: every dialog is a bottom sheet at 390 px.
 * Server side: wa_camp.php (wa.camp_*), wa_pieces.php (wa.piece_*). Styles: the "wa_camp_ui" block at the end of wa_ui.css.
 */
(function () {
  if (!window.WaPanels || !WaPanels.util || typeof api === 'undefined') return;
  // The page's styles live inside whatsapp.html, so this file brings its own (same text as the wa_camp_ui block of wa_ui.css).
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
  if (!document.getElementById('cuCss')) { const st = document.createElement('style'); st.id = 'cuCss'; st.textContent = `
/* wa_camp_ui */
body.waui .wp-mb.cu-x, body.waui .cu-wide .wp-mb{max-width:980px;}
body.waui .cu-wide .wp-mb{width:100%;} body.waui .cu-wide.cu-xl .wp-mb{max-width:98vw;width:98vw;max-height:96vh;max-height:96dvh;height:96vh;height:96dvh;} body.waui .cu-wide.cu-xl .wp-mb .wp-mc{flex:1;min-height:0;overflow:auto}
body.waui .cu-rp{display:grid;grid-template-columns:minmax(220px,30%) 1fr;gap:12px;height:calc(96vh - 150px);min-height:520px} body.waui .cu-rp .l{overflow:auto;border:1px solid #e3e8ef;border-radius:12px} body.waui .cu-rp .r{overflow:hidden;border:1px solid #e3e8ef;border-radius:12px;background:#efeae2;padding:0;display:flex;flex-direction:column}
body.waui .cu-rp .it{padding:9px 11px;border-bottom:1px solid #eef1f4;cursor:pointer} body.waui .cu-rp .it:hover{background:#f6f8fa} body.waui .cu-rp .it.on{background:#e3f5ec;box-shadow:inset 3px 0 #1a8f5a} body.waui .cu-rp .it small{display:block;color:#64748b;font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.waui .cu-rp .bb{max-width:82%;padding:7px 10px;border-radius:10px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word;background:#fff;align-self:flex-start} body.waui .cu-rp .bb.o{background:#d9fdd3;align-self:flex-end} body.waui .cu-rp .bb.c{outline:2px solid #1a8f5a33} body.waui .cu-rp .bb em{display:block;font-style:normal;font-size:11px;color:#667781;margin-top:3px;text-align:right}
@media (max-width:700px){body.waui .cu-rp{grid-template-columns:1fr;grid-template-rows:200px 1fr;height:80vh}}
body.waui .cu-pcts{display:flex;gap:6px;flex-wrap:wrap} body.waui .cu-pcts button{border:1px solid #d6dde6;background:#fff;border-radius:99px;padding:8px 14px;font-weight:700;font-size:14px;min-height:38px;cursor:pointer} body.waui .cu-pcts button.on{background:#128c7e;border-color:#128c7e;color:#fff}
body.waui .cu-guard{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:8px 10px;font-size:12.5px;line-height:1.45}
body.waui .cu-uv{border:1px solid #e3e8ef;border-radius:12px;padding:10px;margin-top:8px;background:#fff} body.waui .cu-uv.off{opacity:.6} body.waui .cu-ub{font-size:13.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word} body.waui .cu-v{background:#ede9fe;color:#5b21b6;border-radius:5px;padding:0 3px}
body.waui .cu-vars{display:flex;flex-wrap:wrap;gap:4px 10px;margin-top:6px;font-size:12px;color:#475569} body.waui .cu-vars b{color:#5b21b6}
body.waui .cu-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px} body.waui .cu-chips span{font-size:11.5px;font-weight:700;border-radius:99px;padding:2px 8px;background:#f1f5f9;color:#475569} body.waui .cu-chips .ok{background:#dcfce7;color:#166534} body.waui .cu-chips .r{background:#fee2e2;color:#991b1b} body.waui .cu-chips .w{background:#fef3c7;color:#92400e}
body.waui .cu-sec{font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;color:#94a3b8;margin:16px 0 6px;} body.waui .cu-sec small{text-transform:none;letter-spacing:0;font-weight:500;color:#94a3b8;}
body.waui .cu-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;} body.waui #cuTg{max-height:210px;overflow-y:auto;padding:2px;}
body.waui .cu-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;} body.waui .cu-2>div{min-width:0;}
body.waui .cu-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start;} body.waui .cu-main{min-width:0;}
body.waui .cu-side{position:sticky;top:0;}
body.waui .cu-ai{background:linear-gradient(135deg,#f4f0ff,#eef6ff);border:1px solid #ddd6fe;border-radius:14px;padding:11px 14px;margin:10px 0;font-size:13px;line-height:1.5;color:#2f2a55;}
body.waui .cu-ai .also{margin-top:6px;font-size:12.5px;} body.waui .cu-ai .also a{color:#5b3fd6;text-decoration:none;font-weight:600;white-space:nowrap;}
body.waui .cu-ai .wp-btn{margin-left:6px;vertical-align:middle;}
body.waui .cu-more{margin-top:12px;} body.waui .cu-more summary{cursor:pointer;font-weight:700;font-size:13px;min-height:32px;display:flex;align-items:center;}
body.waui .cu-ths{display:flex;gap:8px;overflow-x:auto;padding:8px 2px 4px;-webkit-overflow-scrolling:touch;}
body.waui .cu-th{flex:none;width:84px;border:2px solid transparent;border-radius:12px;background:#fff;padding:0;cursor:pointer;text-align:left;overflow:hidden;box-shadow:0 0 0 1px #e3e8e5;}
body.waui .cu-th.on{border-color:#16a34a;box-shadow:0 0 0 1px #16a34a;}
body.waui .cu-th .im{height:84px;background:#eef1f6 center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-size:26px;}
body.waui .cu-th span{display:block;font-size:11px;padding:3px 5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569;}
body.waui .cu-tp{display:block;width:100%;text-align:left;background:#fff;border:1.5px solid #e3e8e5;border-radius:14px;padding:10px 12px;margin:6px 0;cursor:pointer;font:inherit;color:inherit;}
body.waui .cu-tp.on{border-color:#16a34a;background:#f3fcf6;} body.waui .cu-tp .wp-pill{margin-left:4px;}
body.waui .cu-vr{display:grid;grid-template-columns:150px minmax(0,1fr);gap:8px 10px;align-items:start;padding:10px 0;border-top:1px solid #edf1ee;}
body.waui .cu-vk{display:flex;flex-direction:column;gap:2px;} body.waui .cu-vs{display:flex;gap:6px;align-items:center;flex-wrap:wrap;} body.waui .cu-vs select{flex:1;min-width:140px;}
body.waui .cu-vv{grid-column:1 / -1;} body.waui .cu-vv:empty{display:none;}
body.waui .cu-vr{grid-template-columns:minmax(0,1fr) minmax(0,2fr);} body.waui .cu-vr .cu-vv{grid-column:2;}
body.waui .cu-pvbox{background:#e5ddd5;border-radius:18px;padding:10px;} body.waui .cu-pvh{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#667781;margin:0 0 8px 4px;}
body.waui .cu-pvb{background:#fff;border-radius:4px 14px 14px 14px;padding:6px;box-shadow:0 1px 1px rgba(0,0,0,.1);max-width:280px;}
body.waui .cu-pvt{padding:6px 8px;font-size:13.5px;line-height:1.4;white-space:pre-wrap;word-break:break-word;} body.waui .cu-pvf{padding:0 8px 6px;font-size:11.5px;color:#8696a0;}
body.waui .cu-pvx{border-top:1px solid #eef1f6;text-align:center;color:#027eb5;font-weight:700;font-size:13px;padding:8px 4px;}
body.waui .cu-pim{height:120px;border-radius:10px;background:#cfd8dc center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-size:30px;} body.waui .cu-pim.doc{height:54px;font-size:14px;font-weight:800;color:#475569;} body.waui .cu-pim.loc{background:#d9ead3;}
body.waui .cu-hist{display:grid;grid-template-columns:repeat(24,1fr);gap:2px;height:84px;align-items:end;margin-top:10px;} body.waui .cu-hist>div{height:100%;display:flex;align-items:flex-end;} body.waui .cu-hist i{display:block;width:100%;background:#dfe5ec;border-radius:3px 3px 0 0;} body.waui .cu-hist i.o{background:#c5d3ea;} body.waui .cu-hist i.m{background:#4ade80;} body.waui .cu-hist i.b{background:#16a34a;}
body.waui .cu-hl{display:grid;grid-template-columns:repeat(24,1fr);gap:2px;font-size:11px;color:#94a3b8;text-align:center;}
body.waui .cu-rt{border:1.5px solid #bbf7d0;background:#f3fcf6;border-radius:14px;padding:11px 14px;margin:14px 0 4px;} body.waui .cu-rh{display:flex;justify-content:space-between;align-items:center;gap:10px;}
body.waui .cu-tr{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:8px 0;} body.waui .cu-tr>div{background:#fff;border:1px solid #d9efe1;border-radius:10px;padding:7px 9px;font-size:12px;} body.waui .cu-tr span{display:block;font-weight:800;color:#16a34a;font-size:11px;text-transform:uppercase;}
body.waui .cu-cons{display:flex;gap:9px;align-items:flex-start;margin:12px 0 0;font-size:13px;line-height:1.4;} body.waui .cu-cons input{margin-top:3px;width:18px;height:18px;flex:none;}
body.waui .cu-kp{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:10px;} body.waui .cu-kp .wp-kpi{padding:8px 10px;} body.waui .cu-kp .wp-kpi b{font-size:20px;}
body.waui .cu-fl{display:flex;gap:8px;justify-content:space-between;align-items:center;flex-wrap:wrap;}
body.waui .cu-pr{display:flex;gap:10px;align-items:flex-start;padding:10px 2px;border-top:1px solid #edf1ee;} body.waui .cu-av{flex:none;width:36px;height:36px;font-size:14px;} body.waui .cu-pm{flex:1;min-width:0;} body.waui .cu-ph{color:#94a3b8;font-size:12px;}
body.waui .cu-ps{display:flex;flex-wrap:wrap;justify-content:flex-end;align-items:center;gap:6px;flex:none;max-width:46%;} body.waui .cu-ps .wp-btn{min-height:30px;padding:3px 11px;font-size:12px;} body.waui .cu-w{font-size:11px;color:#94a3b8;white-space:nowrap;}
body.waui .cu-tx{margin-top:6px;} body.waui .cu-tx .wp-bub{font-size:12.5px;white-space:pre-wrap;}
body.waui .cu-pc{display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid #edf1ee;} body.waui .cu-pt{flex:1;min-width:0;word-break:break-word;font-size:13.5px;}
@media (max-width:760px){
  body.waui .cu-grid{grid-template-columns:1fr;} body.waui .cu-side{position:static;} body.waui .cu-2{grid-template-columns:1fr;}
  body.waui .cu-vr{grid-template-columns:1fr;} body.waui .cu-vr .cu-vv{grid-column:1;}
  body.waui .cu-kp{grid-template-columns:repeat(3,minmax(0,1fr));}
  body.waui .cu-pr{flex-wrap:wrap;} body.waui .cu-ps{justify-content:flex-start;max-width:none;width:100%;padding-left:46px;}
  body.waui .cu-tr{grid-template-columns:1fr;} body.waui .cu-pvb{max-width:none;}
  body.waui .cu-th{width:76px;} body.waui .cu-th .im{height:76px;}
}
`; document.head.appendChild(st); }
  const { esc, $, fmt, money, pretty, when, toast, modal, sw, sel, errBox, loading } = WaPanels.util;
  const fill = (t, v) => String(t || '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => (v[+n - 1] != null && v[+n - 1] !== '' ? v[+n - 1] : m));
  const bold = (s) => esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>');
  const clone = (o) => JSON.parse(JSON.stringify(o));
  const H = (h) => ((+h % 12) || 12) + ' ' + (+h >= 12 ? 'pm' : 'am');
  function hourText(best) {   // [11, 19, 20] → "11 am and 7–8 pm"
    const s = [...best].sort((a, b) => a - b), g = []; s.forEach((h) => { const l = g[g.length - 1]; if (l && h === l[l.length - 1] + 1) l.push(h); else g.push([h]); });
    const t = g.map((x) => (x.length > 1 ? ((x[0] >= 12) === (x[x.length - 1] >= 12) ? ((x[0] % 12) || 12) + '–' + H(x[x.length - 1]) : H(x[0]) + '–' + H(x[x.length - 1])) : H(x[0])));
    return t.length > 1 ? t.slice(0, -1).join(', ') + ' and ' + t[t.length - 1] : (t[0] || '');
  }
  const SRC = [['name', 'Customer’s first name'], ['asked_perfume', 'Perfume they asked about'], ['last_ordered', 'Perfume they ordered last'], ['city', 'Their city'], ['days_since', 'Days since they wrote'], ['fixed', 'Fixed text (same for everyone)'], ['piece', 'A line from my message pieces'], ['coupon', 'Coupon code (one, or a list)']];
  const SLOTS = [['offer', 'Offer line'], ['opening', 'Opening'], ['closing', 'Closing'], ['emoji', 'Emoji'], ['date', 'Date or time words']];
  const PUSH = [['careful', 'Careful', 5, 1], ['normal', 'Normal', 3, 1], ['aggressive', 'A little aggressive', 2, 2], ['very', 'Very', 1, 3]];
  const ERRS = { f: { all: 'Everyone', delivered: 'Delivered', read: 'Read', replied: 'Replied', failed: 'Failed', retry: 'Trying again', skipped: 'Skipped', held: 'Held by Meta', queued: 'Waiting' } };
  // ---- Marketing + Utility mix (wmx-)
  const UST = { approved: ['Utility approved', 'ok'], waiting: ['Utility waiting for Meta', 'warn'], rejected: ['Utility rejected', 'bad'], paused: ['Utility paused by Meta', 'bad'], relabelled: ['Utility re-labelled by Meta', 'pu'], stopped: ['Needs a human to rewrite', 'bad'], off: ['Utility mix is off', ''], none: ['No utility version yet', ''], missing: ['Utility not sent yet', ''] };
  const CHIP_TXT = { ok: 'approved', waiting: 'waiting for Meta', rejected: 'rejected by Meta', paused: 'paused by Meta', missing: 'not sent to this account yet', relabelled: 're-labelled as marketing', error: 'will be tried again', none: 'none' };
  const CHIP_MARK = { ok: '✓', waiting: '…', rejected: '✕', paused: '⏸', missing: '–', relabelled: 'M', error: '!' };
  const shortLabel = (l) => { l = String(l || '').replace(/\s*[-–—]\s*.*/, '').trim() || String(l || ''); return l.length > 16 ? l.slice(0, 15) + '…' : l; };
  const chipsHtml = (matrix) => (matrix && matrix.length ? `<div class="wmx-chips">${matrix.map((c) => `<span class="wmx-chip ${esc(c.state)}" title="${esc(c.label + ': ' + (CHIP_TXT[c.state] || c.state) + (c.reason ? ' - ' + c.reason : ''))}"><i></i>${esc(shortLabel(c.label))} ${CHIP_MARK[c.state] || ''}</span>`).join('')}</div>` : '');
  const pct = (a, b) => (b ? Math.round(a * 100 / b) + '%' : '-');
  function relabelNotes(mx) {
    return (mx && mx.relabel || []).map((r) => `<div class="wmx-notice"><b>Meta re-labelled the utility message as marketing</b>“${esc(String(r.name).replace(/_/g, ' '))}” (v${r.version}) was counted as marketing by Meta${r.at ? ' on ' + esc(when(r.at)) : ''}. It is no longer used as utility - those customers get the marketing message now.${r.new_name ? ` A stricter version (“${esc(String(r.new_name).replace(/_/g, ' '))}”, ${esc((UST[r.new_status === 'approved' ? 'approved' : (r.new_status === 'relabelled' ? 'relabelled' : (r.new_status === 'rejected' ? 'rejected' : 'waiting'))] || UST.waiting)[0].toLowerCase())}) has been sent for approval.` : ' A stricter version is being written.'}</div>`).join('') + (mx && mx.stopped ? `<div class="wmx-notice bad"><b>${esc(mx.stopped[0].toUpperCase() + mx.stopped.slice(1))}</b>Meta keeps re-labelling the utility version, so it is switched off for this message. Everyone gets the marketing message.</div>` : '');
  }
  function mixPanel(mx, res) {
    if (!mx || (!mx.tpl_u && !(mx.per_number || []).length)) return '';
    const st = UST[mx.u_status] || UST.none, v = mx.variants, c = mx.cost;
    const nr = (mx.per_number || []).map((n) => {
      const tot = n.sent_u + n.sent_m, us = n.u_ok ? 'ok' : (n.u_state === 'waiting' ? 'waiting' : (n.u_state === 'relabelled' || n.u_state === 'stopped' ? 'relabelled' : (n.u_state === 'rejected' ? 'rejected' : (n.u_state === 'paused' ? 'paused' : 'missing'))));
      return `<div class="wmx-nr ${n.m_ok || n.u_ok ? '' : 'off'}"><div class="h"><b>${esc(n.label)}</b>${n.quality ? `<span class="wmx-pill ${n.quality === 'GREEN' ? 'ok' : (n.quality === 'RED' ? 'bad' : 'warn')}">${n.quality === 'GREEN' ? 'Quality high' : (n.quality === 'RED' ? 'Quality low' : 'Quality medium')}</span>` : ''}<span class="sp"></span><span class="wmx-pill ${n.u_ok && n.share > 0 ? 'ok' : ''}">Utility now: ${n.u_ok ? n.share + '%' : '0%'}</span></div>
        <div class="wmx-av"><span class="wmx-chip ${n.m_ok ? 'ok' : 'missing'}" title="${esc(n.m_why || '')}"><i></i>Marketing ${n.m_ok ? '✓' : '–'}</span><span class="wmx-chip ${us}" title="${esc(n.u_why || '')}"><i></i>Utility ${CHIP_MARK[us] || ''}${n.u_ok ? '' : ' ' + esc(n.u_why || '')}</span></div>
        ${n.u_ok ? `<div class="wmx-bar"><i style="width:${Math.max(2, n.share)}%"></i><em style="left:calc(${Math.min(100, n.cap)}% - 1px)" title="safe limit ${n.cap}%"></em></div><div class="wmx-barl"><span>0%</span><span>safe limit ${n.cap}%</span><span>100%</span></div>` : ''}
        <div class="why">${esc(n.why || '')}</div><div class="wp-hint" style="margin:3px 0 0">Sent from here: ${fmt(tot)}${n.sent_u || n.sent_m ? ` (marketing ${fmt(n.sent_m)} · utility ${fmt(n.sent_u)})` : ''}</div></div>`;
    }).join('');
    const row = (lab, x) => `<tr><td>${lab}</td><td>${fmt(x.sent)}</td><td>${fmt(x.delivered)} <small>${pct(x.delivered, x.sent)}</small></td><td>${fmt(x.read)}</td><td>${fmt(x.replied)}</td><td style="${x.failed ? 'color:#b42318' : ''}">${fmt(x.failed)}</td></tr>`;
    return `<div class="wmx-mix"><h4>Marketing + Utility mix <span class="wmx-pill ${st[1]}">${st[0]}</span></h4>
      <div class="wp-hint" style="margin:0 0 4px">${mx.on ? 'The utility version goes only to customers who asked about a perfume or ordered in the last ' + (mx.days || 30) + ' days, never to anyone who opted out. Every number keeps sending; each number’s utility share grows step by step while its sends stay clean.' : 'The mix is switched off: everyone gets the marketing message.'}</div>
      ${relabelNotes(mx)}${mx.shortfall ? `<div class="wmx-short">${esc(mx.shortfall)}</div>` : ''}
      ${mx.on && mx.target ? `<div class="wp-hint" style="margin:0 0 4px">Goal for the whole campaign: <b>${mx.target}%</b> utility · planned now: <b>${mx.achieved}%</b></div>` : ''}${nr}
      ${v ? `<table class="wmx-seg"><thead><tr><th>Version</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Replied</th><th>Failed</th></tr></thead><tbody>${row('Marketing', v.M || { sent: 0, delivered: 0, read: 0, replied: 0, failed: 0 })}${row('Utility', v.U || { sent: 0, delivered: 0, read: 0, replied: 0, failed: 0 })}</tbody></table>` : ''}
      ${c ? `<div class="wmx-cost"><span>Marketing sends ${fmt(c.marketing_sends)} × ${money(c.rate_marketing)}</span><span>Utility sends ${fmt(c.utility_sends)} × ${money(c.rate_utility)}</span>${c.relabelled_as_marketing ? `<span>${fmt(c.relabelled_as_marketing)} utility sends counted as marketing</span>` : ''}<span>Cost ${money(c.total)}</span>${c.saved > 0 ? `<span class="sv">Saved ${money(c.saved)}</span>` : ''}</div>` : ''}</div>`;
  }
  const STATUS = { replied: ['Replied', 'ok'], read: ['Read', 'ok'], delivered: ['Delivered', ''], sent: ['Sent', ''], failed: ['Failed', 'bad'], retry: ['Trying again', 'warn'], skipped: ['Skipped', ''], held: ['Held by Meta', 'warn'], queued: ['Waiting', 'b'] };
  const FIL = {
    ord: [['', 'Any number of orders'], ['0', 'Never ordered'], ['1', 'Ordered 1×'], ['2', 'Ordered 2×'], ['3', 'Ordered 3× or more']],
    dsl: [['', 'Any time since last order'], ['30', 'Last order within 30 days'], ['60', 'Last order 31–60 days ago'], ['90', 'Last order 61–90 days ago'], ['91', 'Last order over 90 days ago']],
    ign: [['0', 'Any'], ['2', 'Ignored 2 or more of our messages'], ['3', 'Ignored 3 or more']],
    src: [['', 'Any source'], ['query', 'Query (Meta ad)'], ['campaign', 'Campaign reply'], ['community', 'Community'], ['none', 'No source']],
    st: [['', 'Everyone'], ['waiting', 'Waiting for our reply'], ['noreply', 'Never wrote to us'], ['optout', 'Opted out']],
    wrote: [['', 'Any'], ['7', 'Wrote in the last 7 days'], ['30', 'Wrote in the last 30 days'], ['old', 'Wrote more than 30 days ago']],
    ords: [['', 'Any'], ['no', 'Has not ordered since they wrote'], ['yes', 'Has ordered since they wrote']],
  };
  // ---------------------------------------------------------------------------------------------------------------- thumbnails of the photo gallery
  const THUMB = {}; let tq = 0;
  async function thumb(id, node) {
    if (THUMB[id]) { node.style.backgroundImage = 'url(' + THUMB[id] + ')'; node.textContent = ''; return; }
    while (tq >= 4) await new Promise((r) => setTimeout(r, 80)); tq++;
    try { const r = await fetch(api.buildUrl('wa.g_thumb', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (r.ok) { THUMB[id] = URL.createObjectURL(await r.blob()); if (node.isConnected) { node.style.backgroundImage = 'url(' + THUMB[id] + ')'; node.textContent = ''; } } } catch (e) { }
    tq--;
  }
  const KIND_ICON = { image: '🖼', video: '🎬', document: '📄' };

  // ---------------------------------------------------------------------------------------------------------------- variables
  /** One setting per variable {{1}}…{{n}}. Old campaigns stored plain texts for {{2}}… ({{1}} was the first name). */
  function normVars(vars, n) {
    vars = Array.isArray(vars) ? vars : []; const isNew = vars.some((x) => x && typeof x === 'object'); let out = [];
    if (!isNew) { out.push({ src: 'name', val: '', slot: '' }); vars.forEach((x) => out.push({ src: 'fixed', val: String(x || ''), slot: '' })); } else out = vars.map((x) => ({ src: x.src || 'fixed', val: x.val || '', slot: x.slot || '' }));
    while (out.length < n) out.push({ src: out.length ? 'fixed' : 'name', val: '', slot: '' }); return out.slice(0, n);
  }
  /** "✨ suggested": the best source for variable n of this message, from the words around it and its example. */
  function suggest(t, n, pcs) {
    const ex = String((t.examples || [])[n - 1] || ''), b = String(t.body || ''), i = b.indexOf('{{' + n + '}}'), before = b.slice(Math.max(0, i - 30), Math.max(0, i)).toLowerCase(), after = b.slice(i + 4 + String(n).length, i + 34).toLowerCase();
    const has = (s) => (pcs && pcs[s]) > 0;
    if (n === 1) return { src: 'name', val: '', slot: '' };
    if (/code|coupon/.test(before) || (/^[A-Z][A-Z0-9]{3,13}$/.test(ex) && /\d/.test(ex))) return { src: 'coupon', val: ex, slot: '' };
    if (/perfume|asked|about|enquir|poocha|baare|regarding|interested in|dekha/.test(before + ' ' + after)) return { src: 'asked_perfume', val: '', slot: '' };
    if (/ days|\bdin\b|\bago\b/.test(after)) return { src: 'days_since', val: '', slot: '' };
    if (/\bcity\b|shehar|\bin$/.test(before.trim())) return { src: 'city', val: '', slot: '' };
    if (/ordered|last order|purchase|bought|refill/.test(before + ' ' + after)) return { src: 'last_ordered', val: '', slot: '' };
    if (ex && !/[a-z0-9]/i.test(ex) && has('emoji')) return { src: 'piece', val: '', slot: 'emoji' };
    if (/\b(today|aaj|kal|tomorrow|weekend|tak|hafte)\b/i.test(ex) && has('date')) return { src: 'piece', val: '', slot: 'date' };
    if (/offer|discount|deal|off\b/.test(before) && has('offer')) return { src: 'piece', val: '', slot: 'offer' };
    return { src: 'fixed', val: ex, slot: '' };
  }
  function sample(sp, t, n, pcs, lines) {
    const ex = String((t.examples || [])[n - 1] || '');
    switch (sp.src) {
      case 'name': return 'Rakesh'; case 'asked_perfume': return sp.val || 'Best Sellers'; case 'last_ordered': return sp.val || 'your favourite perfume'; case 'city': return sp.val || 'Surat'; case 'days_since': return '7';
      case 'coupon': return (sp.val || '').split(/[\r\n,;]+/).map((x) => x.trim()).filter(Boolean)[0] || ex || 'CODE';
      case 'piece': { const l = (lines || []).filter((x) => x.slot === sp.slot && x.active); return l.length ? l[0].text : (ex || '…'); }
      default: return sp.val || ex || '…';
    }
  }

  // ================================================================================================================ the screen
  if (!document.getElementById('cuCss2')) { const st = document.createElement('style'); st.id = 'cuCss2'; st.textContent = '.cu-grp{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:space-between;background:#f5f3ff;border:1px solid #ddd6fe;color:#3b2a8a;border-radius:10px;padding:8px 10px;margin-top:10px;font-size:13px}.cu-fp{border:1px solid #e3e8ef;border-radius:12px;padding:12px;margin-top:6px;background:#fff}.cu-fp .wcs-fh{display:none!important}.cu-fp.shut .wcs-fb{display:block!important}'; document.head.appendChild(st); }
  // 🔎 click "See how much room each number has" under a "no room" note: today's limit and this hour's speed, per number, live from the last load
  function roomBox(nums, pf) {
    const au = (pf && pf.auto && pf.auto.nums) || {};
    const rows = (nums || []).map((n) => { const a = au[n.id]; return `<div class="wp-kv" style="padding:3px 0"><span>${esc(n.label)}${n.why ? ` <span class="wp-hint" style="margin:0">(${esc(n.why)})</span>` : ''}</span><b>Today: ${fmt(n.left)} left of ${fmt(n.cap)} (${fmt(n.used)} used)${a ? ` · This hour: ${fmt(a.left_hour != null ? a.left_hour : Math.max(0, a.cap - a.used_hour))} left of ${fmt(a.cap)}` : ''}</b></div>`; }).join('');
    const all = pf ? `<div class="wp-kv" style="padding:3px 0"><span>All numbers together (Meta's 24 h limit)</span><b>${fmt(Math.max(0, Math.floor((pf.tier || 0) * 0.9) - (pf.used || 0)))} left of ${fmt(Math.floor((pf.tier || 0) * 0.9))} (${fmt(pf.used || 0)} used)</b></div>` : '';
    return `<details style="margin-top:6px"><summary style="cursor:pointer;font-weight:600">🔎 See how much room is left</summary>${all}${rows}<div class="wp-hint">Room is counted over the last 24 hours, so it frees up by itself as old sends pass 24 hours. Press 🔄 Refresh for the latest numbers.</div></details>`;
  }
  function screen(el, ctx) {
    let D = null, tick = null, smart = null, TPLX = null; el.innerHTML = loading;
    const ST = { draft: ['Draft', ''], scheduled: ['Scheduled', 'b'], running: ['Sending', 'ok'], paused: ['Paused', 'warn'], done: ['Finished', 'p'], cancelled: ['Stopped', 'bad'] };
    async function load(quiet, fresh) {
      if (smart) return;   // the Smart campaign is open in this area: do not draw the list over it
      try { D = await api.get('wa.camp_list', fresh ? { fresh: 1, lite: 1 } : { lite: 1 }); if (TPLX) { D.templates = TPLX.templates; D.pieces = TPLX.pieces; D.can_media = TPLX.can_media; } } catch (e) { if (!quiet) el.innerHTML = errBox(e.message); return; }
      if (!el.isConnected) return; if (!quiet || !document.getElementById('cpKeep')) draw();
      if (window.WaCampGo) { const g = window.WaCampGo; window.WaCampGo = null; if (g.mode === 'smart' && window.WaSmart) openSmart(0, g.seg); else wizard(null, g.seg); return; }   // 👥 Customers → people picked there
      clearTimeout(tick); if (D.campaigns.some((c) => c.status === 'running' || c.status === 'scheduled')) tick = setTimeout(async () => { if (!el.isConnected || smart) return; if (!D.engine.cron_ok) { try { await api.post('wa.camp_tick', {}); } catch (e) { } } load(true); }, 20000);
    }
    function draw() {
      const nums = D.numbers, pf = D.portfolio, win = D.window || [0, 24];
      let h = `<div class="wp" id="cpKeep"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Campaigns</h2><p>Send an approved message to many customers. The sender picks the best number for each customer and the best time for each person.</p></div><button type="button" class="wp-btn" id="cRef" title="Reload every campaign and number now, and run one sending round">🔄 Refresh</button><button type="button" class="wp-btn" id="cPc">Message pieces</button><button type="button" class="wp-btn" id="cSum" title="Which campaign works best, and what to change">📊 Summary</button><button type="button" class="wp-btn" id="cResAll" title="Start every paused campaign again">▶ Resume all</button><button type="button" class="wp-btn r" id="cStopAll" title="Stop every running, scheduled and paused campaign">⏹ Stop all</button><button type="button" class="wp-btn o" id="cNew">＋ New campaign</button><button type="button" class="wp-btn" id="cPlan" title="Type how much you want to spend today — see how many messages to send, the hour-by-hour plan and the expected replies">🎯 Daily budget</button><button type="button" class="wp-btn" id="cDrip" title="A different pre-made message every day for 7 days, sent by itself to new queries and campaign repliers">🔁 7-Day Drip</button><button type="button" class="wp-btn" id="cUtil" title="Cheap utility follow-ups only for people who asked or ordered — the offer goes free when they reply">💸 Utility follow-up</button></div>
        <div id="cpPlans"></div>
        ${D.engine.cron_ok ? '' : '<div class="wp-note"><b>The engine is not set up yet.</b> While this page is open it sends by itself every 20 seconds, but to send with your phone off set up the one-line cron in <b>Team &amp; routing → Engine</b>.</div>'}
        ${(() => { const T = D.campaigns.reduce((a, c) => { const k = c.counts; a.sent += k.sent; a.del += k.delivered; a.read += k.read; a.rep += k.replied || 0; a.fail += k.failed; a.held += k.held || 0; a.retry += (k.retry || 0) - (k.held_r || 0); return a; }, { sent: 0, del: 0, read: 0, rep: 0, fail: 0, held: 0, retry: 0 }); const run = D.campaigns.filter((c) => c.status === 'running').length;
          const pc = (a, b) => (b > 0 ? Math.round(a * 100 / b) + '%' : '—'), tried = T.sent + T.fail + T.held;   // delivered / read / replied = % of sent · failed / held = % of everyone tried
          return `<div class="ck"><div class="wp-kpi"><small>Sent</small><b>${fmt(T.sent)}</b><span>all campaigns</span></div><div class="wp-kpi"><small>Delivered</small><b>${fmt(T.del)}</b><span>${pc(T.del, T.sent)} of sent</span></div><div class="wp-kpi"><small>Read</small><b>${fmt(T.read)}</b><span>${pc(T.read, T.sent)} of sent</span></div><div class="wp-kpi"><small>💬 Replied</small><b style="color:#15803d">${fmt(T.rep)}</b><span>${pc(T.rep, T.sent)} of sent</span></div><div class="wp-kpi"><small>Failed</small><b style="${T.fail ? 'color:#c2262b' : ''}">${fmt(T.fail)}</b><span>${pc(T.fail, tried)} of tried${T.retry > 0 ? ' · ' + fmt(T.retry) + ' trying again' : ''}</span></div><div class="wp-kpi"><small>Held by Meta</small><b>${fmt(T.held)}</b><span>${pc(T.held, tried)} of tried · one more try in 1 day</span></div><div class="wp-kpi"><small>Sending now</small><b>${run}</b><span>${D.campaigns.length} campaigns</span></div></div>`; })()}
        <div class="wp-cd"><h3>Room to send today</h3>${pf ? `<div class="wp-kv"><span>Meta daily limit (all numbers together)</span><b>${pf.assumed ? 'not told — 250 assumed' : fmt(pf.tier)}</b></div><div class="wp-kv"><span>Started in the last 24 h</span><b>${fmt(pf.used)}</b></div>` : ''}${pf && pf.auto ? `<div class="wp-kv"><span>📈 Speed per number this hour <div class="wp-hint" style="margin:0">each number sends up to its speed an hour (campaigns on it share it) · starts at 5,000 · doubles after an hour that goes well · halves if quality drops · Meta’s own refusals don’t count · max ${fmt(pf.auto.max)}</div></span><b>${fmt(pf.auto.cap)} an hour in all</b></div>${Object.values(pf.auto.nums || {}).map((n) => `<div class="wp-kv" style="padding-left:14px"><span>${esc(n.label)}</span><b>${fmt(n.used_hour)} of ${fmt(n.cap)} this hour</b></div>`).join('')}${(pf.auto.hist || []).length ? `<details style="margin-top:4px"><summary class="wp-hint" style="cursor:pointer">How each number’s speed changed</summary>${pf.auto.hist.slice(-12).reverse().map((x) => `<div class="wp-hint" style="margin:2px 0">${esc(String(x.hour).slice(11))}:00 · ${esc(((pf.auto.nums || {})[x.nid] || {}).label || (x.nid ? 'Number ' + x.nid : 'All numbers'))} — ${fmt(x.from)} → <b>${fmt(x.to)}</b> · ${esc(x.why)} (${fmt(x.sent)} sent, ${fmt(x.delivered)} delivered, ${fmt(x.failed)} failed)</div>`).join('')}</details>` : ''}` : ''}${nums.map((n) => `<div class="wp-kv"><span>${esc(n.label)} <span class="wp-pill ${n.quality === 'GREEN' ? 'ok' : (n.quality === 'RED' ? 'bad' : (n.quality === 'YELLOW' ? 'warn' : ''))}">${n.quality === 'GREEN' ? 'High' : (n.quality === 'RED' ? 'Low' : (n.quality === 'YELLOW' ? 'Medium' : 'New'))}</span><div class="wp-hint" style="margin:0">${esc(n.why || '')}</div></span><b>${fmt(n.left)} of ${fmt(n.cap)} left</b></div>`).join('')}
          <div class="wp-hint">${win[0] === 0 && win[1] >= 24 ? 'Campaigns can send at any hour, day or night.' : 'Campaigns never send between ' + H(win[1]) + ' and ' + H(win[0]) + '.'}</div></div>`;
      h += '<div class="wp-cd" id="cDripCard" style="display:none"></div>';
      h += D.campaigns.length ? D.campaigns.map((c, i) => {
        const pc = (a, b) => (b > 0 && a > 0 ? ` <span style="opacity:.75">(${Math.round(a * 100 / b)}%)</span>` : '');   // delivered / read / replied = % of sent · failed / held by Meta = % of everyone we tried
        const s = ST[c.status] || [c.status, ''], k = c.counts, tried = (k.sent || 0) + (k.failed || 0) + (k.held || 0), done = k.sent + k.failed + k.skipped + (k.held_r || 0), pct = k.total ? Math.round(done * 100 / k.total) : 0, started = c.status !== 'draft' && k.total > 0;
        return `<div class="wp-cd"><h3>${esc(c.name)}<span class="sp"></span><span class="wp-pill ${s[1]}">${s[0]}</span></h3><div class="wp-hint" style="margin:0 0 4px">${esc(c.tpl_name.replace(/_/g, ' '))}${c.media_title ? ' · 🖼 ' + esc(c.media_title) : ''}${c.started_at ? ' · started ' + esc(when(c.started_at)) : (c.start_at ? ' · starts ' + esc(when(c.start_at)) : '')}</div>
          ${k.total ? `<div class="wp-bar"><i style="width:${Math.max(pct, 1)}%"></i></div><div class="wp-hint" style="margin:0">${fmt(done)} of ${fmt(k.total)} · sent to Meta ${fmt(k.sent + (k.held || 0))}${k.sent_free ? ' (free ' + fmt(k.sent_free) + ' · paid ' + fmt(k.sent_paid) + ')' : ''} · delivered ${fmt(k.delivered)}${pc(k.delivered, k.sent + (k.held || 0))} · read ${fmt(k.read)}${pc(k.read, k.sent + (k.held || 0))}${k.replied != null ? ` · <b style="color:#15803d">💬 replied ${fmt(k.replied)}</b>${k.sent ? ` <span style="color:#15803d">(${Math.round(k.replied * 100 / (k.sent + (k.held || 0)))}%)</span>` : ''}` : ''} · failed ${fmt(k.failed)}${pc(k.failed, tried)}${(k.retry - (k.held_r || 0)) > 0 ? ' · trying again ' + fmt(k.retry - (k.held_r || 0)) : ''} · skipped ${fmt(k.skipped - ((k.held || 0) - (k.held_r || 0)))}${k.held ? ` · <span title="Meta did not deliver: the customer got many marketing messages from businesses. Not charged. One more try 1 day later.">not delivered by Meta ${fmt(k.held)}${pc(k.held, tried)}${k.held_r ? ' (' + fmt(k.held_r) + ' get one more try in 1 day)' : ''}</span>` : ''}</div>` : '<div class="wp-hint">Not started — nothing is sent until you press Start.</div>'}${c.note ? `<div class="${c.status === 'paused' ? 'wp-err' : 'wp-note'}">${esc(c.note)}${/room|speed|hour/i.test(c.note) ? roomBox(nums, pf) : ''}</div>` : ''}
          ${c.why ? `<div class="wp-note" style="margin:6px 0 0">⚠️ <b>Nothing has gone out yet:</b> ${esc(c.why)}${/room|speed|hour/i.test(c.why) ? roomBox(nums, pf) : ''}</div>` : ''}
          ${c.mix && c.mix.tpl_u ? `<div class="wp-hint" style="margin:4px 0 0"><span class="wmx-pill ${(UST[c.mix.on ? c.mix.u_status : 'off'] || UST.none)[1]}">${(UST[c.mix.on ? c.mix.u_status : 'off'] || UST.none)[0]}</span>${c.mix.on && k.sent ? ' · ' + fmt(c.counts.sent_u || 0) + ' sent as utility' : ''}</div>` : ''}${relabelNotes(c.mix)}
          <div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" data-d="${i}">Details</button>${started ? `<button type="button" class="wp-btn s" data-w="${i}">Who got what</button>` : ''}${started && window.WaCampReport ? `<button type="button" class="wp-btn s" data-rep="${i}">📊 Report card</button>` : ''}${c.status === 'running' || c.status === 'scheduled' ? `<button type="button" class="wp-btn s" data-a="pause" data-i="${i}">Pause</button>` : ''}${c.status === 'paused' ? `<button type="button" class="wp-btn p s" data-a="resume" data-i="${i}">Resume</button>` : ''}${c.status === 'draft' ? `<button type="button" class="wp-btn p s" data-e="${i}">Edit &amp; start</button>` : ''}${['running', 'scheduled', 'paused'].includes(c.status) ? `<button type="button" class="wp-btn r s" data-a="cancel" data-i="${i}">Stop for good</button>` : ''}${['draft', 'done', 'cancelled', 'paused'].includes(c.status) ? `<button type="button" class="wp-btn r s" data-a="del" data-i="${i}">Delete</button>` : ''}</div></div>`;
      }).join('') : '<div class="wp-cd"><div class="wp-empty">No campaign yet.<br>Press “New campaign”.</div></div>';
      el.innerHTML = h + '</div>';
      (async () => { try { const d = await api.get('wa.drip_stats'); const box = $(el, '#cDripCard'); if (!box || (!d.days_set && !d.total.sent && !d.total.skipped)) return; const k = d.total, pc = (a, b) => (b > 0 && a > 0 ? ` <span style="opacity:.75">(${Math.round(a * 100 / b)}%)</span>` : '');
          box.style.display = ''; box.innerHTML = `<h3>🔁 7-Day Drip<span class="sp"></span><span class="wp-pill ${d.on ? 'ok' : ''}">${d.on ? 'On' : 'Off'}</span></h3><div class="wp-hint" style="margin:0 0 4px">A pre-made message each day for new queries and campaign repliers · ${d.days_set} day${d.days_set === 1 ? '' : 's'} set${d.first ? ' · since ' + esc(when(d.first)) : ''}</div>
            <div class="wp-hint" style="margin:0">sent to Meta ${fmt(k.sent)} · delivered ${fmt(k.delivered)}${pc(k.delivered, k.sent)} · read ${fmt(k.read)}${pc(k.read, k.sent)} · <b style="color:#15803d">💬 replied ${fmt(k.replied)}</b>${pc(k.replied, k.sent)} · failed ${fmt(k.failed)} · left out ${fmt(k.skipped)} (ordered / opted out / complaint…)</div>
            <div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" id="cdDet">Details &amp; setup</button><button type="button" class="wp-btn s" id="cdRep">📊 Report card</button></div>`;
          $(box, '#cdDet').onclick = () => $(el, '#cDrip').click();
          $(box, '#cdRep').onclick = () => { const m = modal('📊 7-Day Drip report'); m.el.classList.add('cu-wide'); m.body.innerHTML = `<div class="wp-hint">Replied = wrote back within 2 days of that message. Last 30 days.</div><div style="display:grid;grid-template-columns:70px 1fr repeat(6,64px);gap:6px;font-size:12.5px;align-items:center"><b>Day</b><b>Message</b><b>Sent</b><b>Deliv.</b><b>Read</b><b>Replied</b><b>Failed</b><b>Left out</b>${d.days.map((x) => `<div><b>Day ${x.day}</b></div><div>${esc(x.tpl || '—')}</div><div>${fmt(x.sent)}</div><div>${fmt(x.delivered)}</div><div>${fmt(x.read)}</div><div style="color:#15803d"><b>${fmt(x.replied)}</b></div><div>${fmt(x.failed)}</div><div>${fmt(x.skipped)}</div>`).join('')}<div style="border-top:1px solid #e2e8f0"><b>Total</b></div><div style="border-top:1px solid #e2e8f0"></div><div style="border-top:1px solid #e2e8f0"><b>${fmt(k.sent)}</b></div><div style="border-top:1px solid #e2e8f0"><b>${fmt(k.delivered)}</b></div><div style="border-top:1px solid #e2e8f0"><b>${fmt(k.read)}</b></div><div style="border-top:1px solid #e2e8f0;color:#15803d"><b>${fmt(k.replied)}</b></div><div style="border-top:1px solid #e2e8f0"><b>${fmt(k.failed)}</b></div><div style="border-top:1px solid #e2e8f0"><b>${fmt(k.skipped)}</b></div></div>`; };
        } catch (e) { } })();
      $(el, '#cResAll').onclick = async function () { this.disabled = true; try { const r = await api.post('wa.camp_resume_all', {}); toast(r.resumed ? 'Started ' + r.resumed + ' paused campaign' + (r.resumed === 1 ? '' : 's') + ' again ✓' : 'No paused campaigns'); load(false, true); } catch (e) { toast(e.message, true); this.disabled = false; } };
      $(el, '#cStopAll').onclick = async function () { if (!confirm('Stop ALL campaigns now? Every running, scheduled and paused campaign stops and its waiting people are dropped. Already-sent messages are not affected.')) return; this.disabled = true; try { const r = await api.post('wa.camp_stop_all', {}); toast('Stopped ' + r.stopped + ' campaign' + (r.stopped === 1 ? '' : 's') + ' · ' + fmt(r.dropped) + ' waiting messages dropped'); load(); } catch (e) { toast(e.message, true); this.disabled = false; } };
      $(el, '#cSum').onclick = summary;
      $(el, '#cRef').onclick = async function () { this.disabled = true; this.textContent = '🔄 Refreshing…'; try { await api.post('wa.camp_tick', {}); } catch (e) { } await load(false, true); };   // reload the list, the speeds and the room, and give the sender one round now
      $(el, '#cPlan').onclick = () => { const go = () => window.WaPlan && WaPlan.open(); if (window.WaPlan) return go(); const sc = document.createElement('script'); sc.src = 'assets/js/wa_plan_ui.js?v=p3'; sc.onload = go; sc.onerror = () => toast('Could not open the planner. Refresh the page.', true); document.head.appendChild(sc); }; $(el, '#cDrip').onclick = () => { const go = () => window.WaDrip && WaDrip.open(); if (window.WaDrip) return go(); const sc = document.createElement('script'); sc.src = 'assets/js/wa_drip_ui.js?v=d5'; sc.onload = go; sc.onerror = () => toast('Could not open the 7-Day Drip. Refresh the page.', true); document.head.appendChild(sc); }; $(el, '#cUtil').onclick = () => wizard(null, null, true); $(el, '#cNew').onclick = () => (window.WaSmart ? WaSmart.chooser({ onNormal: () => wizard(null), onSmart: () => openSmart(0) }) : wizard(null)); $(el, '#cPc').onclick = () => pieces();
      if (window.WaSmart && $(el, '#cpPlans')) WaSmart.plansCard($(el, '#cpPlans'), { onOpen: (id) => openSmart(id) });
      el.querySelectorAll('[data-d]').forEach((b) => { b.onclick = () => details(D.campaigns[+b.dataset.d]); });
      el.querySelectorAll('[data-w]').forEach((b) => { b.onclick = () => people(D.campaigns[+b.dataset.w]); });
      el.querySelectorAll('[data-rep]').forEach((b) => { b.onclick = () => WaCampReport.open(D.campaigns[+b.dataset.rep]); });
      el.querySelectorAll('[data-e]').forEach((b) => { b.onclick = () => wizard(D.campaigns[+b.dataset.e]); });
      el.querySelectorAll('[data-a]').forEach((b) => { b.onclick = async () => { const c = D.campaigns[+b.dataset.i], a = b.dataset.a; if (a === 'cancel' && !confirm('Stop “' + c.name + '” for good? Messages not sent yet are dropped.')) return; if (a === 'del' && !confirm('Delete “' + c.name + '”?')) return; try { await api.post('wa.camp_' + (a === 'del' ? 'del' : a), { id: c.id }); load(); } catch (e) { toast(e.message, true); } }; });
    }

    function openSmart(id, seg) {   // the Smart campaign (wa_smart_ui.js) takes over this area; its back button brings the list back
      clearTimeout(tick); if (smart) { try { smart.destroy(); } catch (e) { } }
      smart = WaSmart.open(el, { id: id || 0, seg: seg || null, onBack: () => { smart = null; load(false); } });
    }

    // ------------------------------------------------------------------------------------------------------------ 📊 summary of ALL campaigns: who is running, who wins, what to change
    function summary() {
      const all = (D.campaigns || []).filter((c) => c.status !== 'draft' && c.counts && (c.counts.total || 0) > 0);
      const R = (c) => { const k = c.counts, sm = (k.sent || 0) + (k.held || 0), p = (a, b) => (b > 0 ? a * 100 / b : null); return { c, name: c.name, status: c.status, started: c.started_at || c.start_at || '', total: k.total || 0, sm, del: k.delivered || 0, read: k.read || 0, rep: k.replied || 0, fail: k.failed || 0, held: k.held || 0, skip: Math.max(0, (k.skipped || 0) - Math.max(0, (k.held || 0) - (k.held_r || 0))),
        delP: p(k.delivered || 0, sm), readP: p(k.read || 0, sm), readOfDel: p(k.read || 0, k.delivered || 0), repP: p(k.replied || 0, sm), heldP: p(k.held || 0, sm), failP: p(k.failed || 0, sm), tpl: c.tpl_name || '' }; };
      const rows = all.map(R).sort((a, b) => String(b.started).localeCompare(String(a.started))).slice(0, 40), big = rows.filter((r) => r.sm >= 200);
      const pct = (v) => (v == null ? '—' : (Math.round(v * 10) / 10) + '%'), best = (key, hi) => { const a = big.filter((r) => r[key] != null); if (!a.length) return null; return a.reduce((x, y) => ((hi ? y[key] > x[key] : y[key] < x[key]) ? y : x)); };
      const bd = best('delP', true), wd = best('delP', false), br = best('readP', true), wr = best('readP', false), bp = best('repP', true), wp2 = best('repP', false);
      const run = rows.filter((r) => ['running', 'scheduled', 'paused'].includes(r.status));
      const sum = big.reduce((a, r) => ({ sm: a.sm + r.sm, del: a.del + r.del, read: a.read + r.read, rep: a.rep + r.rep, held: a.held + r.held, fail: a.fail + r.fail }), { sm: 0, del: 0, read: 0, rep: 0, held: 0, fail: 0 });
      const A = { delP: sum.sm ? sum.del * 100 / sum.sm : null, readP: sum.sm ? sum.read * 100 / sum.sm : null, repP: sum.sm ? sum.rep * 100 / sum.sm : null, heldP: sum.sm ? sum.held * 100 / sum.sm : null, failP: sum.sm ? sum.fail * 100 / sum.sm : null, readOfDel: sum.del ? sum.read * 100 / sum.del : null };
      // what to change — each tip names the numbers it comes from
      const tips = [];
      if (A.heldP != null && A.heldP >= 25) tips.push(['🛑', 'Meta is holding ' + pct(A.heldP) + ' of what you send', 'Meta does not deliver a marketing message to a customer who got many from businesses that day. To reach more people: send first to customers who wrote to you in the last 30 days, keep 3 or more days between two campaigns to the same customers, and send at each customer’s own active hour (the sender already does this for the retry).']);
      if (bd && wd && bd.c !== wd.c && bd.delP - wd.delP >= 3) tips.push(['📬', 'Delivery: copy ' + bd.name, bd.name + ' delivered ' + pct(bd.delP) + ' but ' + wd.name + ' only ' + pct(wd.delP) + '. Use the audience and the send time of the better one (open its Report card → "Who got what" to see who it reached).']);
      if (A.readOfDel != null && A.readOfDel < 30) tips.push(['👀', 'Only ' + pct(A.readOfDel) + ' of the delivered messages are read', 'Make the first line a clear benefit or a name ("Hi {name}, your 4×50 ml offer ends tonight"), add a photo on top, and send in the evening hours when your customers reply most.']);
      if (br && wr && br.c !== wr.c && br.readP - wr.readP >= 2) tips.push(['📖', 'Reading: ' + br.name + ' is read most (' + pct(br.readP) + ')', wr.name + ' is read least (' + pct(wr.readP) + '). Compare their first lines and their photos; make the weaker one look like the stronger one.']);
      if (A.repP != null && A.repP < 2) tips.push(['💬', 'Replies are ' + pct(A.repP) + ' of what you send', 'End the message with ONE easy question or a button ("Reply 1 for 4×50 ml, 2 for 5×50 ml") instead of a long offer. A customer who can answer in one tap replies much more.']);
      if (bp && wp2 && bp.c !== wp2.c && bp.repP - wp2.repP >= 0.5) tips.push(['🏆', 'Replies: ' + bp.name + ' wins (' + pct(bp.repP) + ')', wp2.name + ' gets ' + pct(wp2.repP) + '. Send the winner’s message wording to the weaker audience next time.']);
      if (A.failP != null && A.failP >= 3) tips.push(['⚠️', pct(A.failP) + ' fail outright', 'Clean the list before sending: remove numbers that are not on WhatsApp and customers who opted out.']);
      const nSkip = big.reduce((a, r) => a + r.skip, 0); if (sum.sm && nSkip * 100 / (sum.sm + nSkip) >= 15) tips.push(['🧹', 'Many people are skipped before sending', 'About ' + fmt(nSkip) + ' were skipped (opted out, wrong number, already got a campaign lately). Build the audience from active customers to use your daily limit better.']);
      if (!tips.length) tips.push(['✅', 'No big problem in the numbers', 'Keep the same audience and wording; test one change at a time (the first line, or the send time) and compare in this summary.']);
      const m = modal('📊 Campaign summary'); m.el.classList.add('cu-wide');
      const stat = (l, v, sub) => `<div class="wp-kpi"><small>${l}</small><b>${v}</b><span>${sub || ''}</span></div>`;
      const win = (t, r, key, goodHi) => (r ? `<div class="wp-kpi"><small>${t}</small><b style="font-size:15px">${esc(r.name)}</b><span style="color:${goodHi ? '#15803d' : '#dc2626'}">${pct(r[key])}</span></div>` : '');
      let h = `<div class="ck">${stat('Running now', run.length, run.length ? run.map((r) => esc(r.name)).join(', ').slice(0, 90) : 'nothing is sending')}${stat('Delivered', pct(A.delP), 'of sent to Meta · last ' + big.length + ' campaigns')}${stat('Read', pct(A.readP), pct(A.readOfDel) + ' of the delivered')}${stat('💬 Replied', pct(A.repP), fmt(sum.rep) + ' customers')}${stat('Held by Meta', pct(A.heldP), 'not delivered')}</div>`;
      h += `<div class="ck">${win('Best delivered', bd, 'delP', 1)}${win('Best read', br, 'readP', 1)}${win('Most replies', bp, 'repP', 1)}${win('Weakest delivered', wd, 'delP', 0)}${win('Weakest read', wr, 'readP', 0)}${win('Fewest replies', wp2, 'repP', 0)}</div>`;
      h += '<h3 style="margin:14px 0 6px">What to change</h3>' + tips.map((t) => `<div class="wp-note" style="margin:6px 0"><b>${t[0]} ${esc(t[1])}</b><div style="margin-top:3px">${esc(t[2])}</div></div>`).join('');
      h += '<div style="margin:12px 0"><button type="button" class="wp-btn o" id="sumAi">✨ Ask Claude for a deeper review</button></div><div id="sumAiOut"></div>';
      const cell = (v, bestR, worstR, r, key) => `<td style="${bestR && bestR.c === r.c ? 'background:#dcfce7;font-weight:800' : worstR && worstR.c === r.c ? 'background:#fee2e2' : ''}">${pct(v)}</td>`;
      h += `<h3 style="margin:14px 0 6px">All campaigns</h3><div style="overflow:auto"><table class="wp-tbl" style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr><th style="text-align:left">Campaign</th><th>Status</th><th>Sent to Meta</th><th>Delivered</th><th>Read</th><th>Replied</th><th>Held by Meta</th><th>Failed</th></tr></thead><tbody>${rows.map((r) => `<tr><td style="text-align:left;padding:6px 4px"><b>${esc(r.name)}</b><small style="display:block;color:#64748b">${esc(r.started ? when(r.started) : '')}</small></td><td>${esc(({ running: 'Sending', scheduled: 'Scheduled', paused: 'Paused', done: 'Finished', cancelled: 'Stopped' })[r.status] || r.status)}</td><td>${fmt(r.sm)}</td>${cell(r.delP, bd, wd, r)}${cell(r.readP, br, wr, r)}${cell(r.repP, bp, wp2, r)}<td>${pct(r.heldP)}</td><td>${pct(r.failP)}</td></tr>`).join('')}</tbody></table></div><div class="wp-hint">Green = best, red = weakest (only campaigns that sent 200+ are compared). Delivered, read and replied are shown against everyone Meta accepted.</div>`;
      m.body.innerHTML = h;
      m.body.querySelectorAll('th,td').forEach((x) => { if (x.tagName === 'TH' || x.cellIndex > 0) x.style.textAlign = x.cellIndex === 0 ? 'left' : 'center'; });
      $(m.body, '#sumAi').onclick = async function () {
        this.disabled = true; this.textContent = 'Claude is reading your campaigns…'; const out = $(m.body, '#sumAiOut');
        try {
          const r = await api.post('wa.camp_summary_ai', { rows: big.slice(0, 14).map((r) => ({ name: r.name, tpl_id: r.c.tpl_id || 0, started: r.started, sent_to_meta: r.sm, delivered: r.del, read: r.read, replied: r.rep, held: r.held, failed: r.fail })) });
          const a = r.advice || {}, ul = (x) => (x && x.length ? '<ul style="margin:4px 0 8px 18px">' + x.map((i) => '<li>' + esc(i) + '</li>').join('') + '</ul>' : '');
          out.innerHTML = `<div class="wp-note" style="background:#eef2ff"><b>✨ ${esc(a.headline || 'Claude’s review')}</b>${a.did_well && a.did_well.length ? '<div style="margin-top:6px"><b>What worked</b></div>' + ul(a.did_well) : ''}${a.do_better && a.do_better.length ? '<div><b>Change these</b></div>' + ul(a.do_better) : ''}${a.next_time ? '<div><b>Next campaign</b></div>' + ul(Object.keys(a.next_time).map((k) => k.replace(/_/g, ' ') + ': ' + a.next_time[k])) : ''}</div>`;
        } catch (e) { out.innerHTML = `<div class="wp-err">${esc(e.message)}</div>`; }
        this.disabled = false; this.textContent = '✨ Ask Claude for a deeper review';
      };
    }
    // ------------------------------------------------------------------------------------------------------------ details of one campaign
    async function details(c) {
      const m = modal(c.name); m.body.innerHTML = loading; let d;
      try { d = (await api.get('wa.camp_get', { id: c.id })).campaign; } catch (e) { m.body.innerHTML = errBox(e.message); return; } const r = d.result, k = d.counts;
      m.body.innerHTML = `<div class="wp-bub">${esc(d.tpl_body)}</div><div class="wp-kpis"><div class="wp-kpi"><small>Sent</small><b>${fmt(k.sent)}</b><span>of ${fmt(k.total)}${k.sent_free ? ' · ' + fmt(k.sent_free) + ' free' : ''}</span></div><div class="wp-kpi"><small>Delivered</small><b>${fmt(k.delivered)}</b></div><div class="wp-kpi"><small>Read</small><b>${fmt(k.read)}</b></div><div class="wp-kpi" id="dRep" style="${r.replied ? 'cursor:pointer;outline:2px solid #1a8f5a33' : ''}" title="See who replied and what they wrote"><small>Replied ${r.replied ? '›' : ''}</small><b>${fmt(r.replied)}</b></div><div class="wp-kpi"><small>Orders</small><b>${r.orders}</b><span>${money(r.revenue)}</span></div><div class="wp-kpi"><small>Cost ≈</small><b>${money(r.cost)}</b></div></div>
        <div class="wp-kv"><span>Failed</span><b>${fmt(k.failed)}</b></div><div class="wp-kv"><span>Trying again</span><b>${fmt((k.retry || 0) - (k.held_r || 0))}</b></div><div class="wp-kv"><span>Skipped by our rules (opted out, bought in the last 10 days, ignored 2 campaigns…)</span><b>${fmt(k.skipped - ((k.held || 0) - (k.held_r || 0)))}</b></div><div class="wp-kv"><span>Held by Meta (customer got too many marketing messages — not charged; one more try 1 day later)</span><b>${fmt(k.held || 0)}</b></div><div class="wp-kv"><span>Still waiting to be sent</span><b>${fmt(k.queued)}</b></div>
        <div class="wp-kv"><span>How pushy</span><b>${esc((PUSH.find((p) => p[0] === d.push) || PUSH[1])[1])}</b></div><div class="wp-kv"><span>Send time</span><b>${d.own_hour ? 'each person’s own usual hour' : 'everyone together'}</b></div><div class="wp-kv"><span>Auto retry</span><b>${d.retry ? 'on (3 tries)' : 'off'}</b></div>
        ${d.mix && (d.mix.tpl_u || (d.mix.per_number || []).length) ? `<div class="wmx-sw ${d.mix.on ? '' : 'off'}"><label><input type="checkbox" id="dMix" ${d.mix.on ? 'checked' : ''} ${['done', 'cancelled'].includes(d.status) ? 'disabled' : ''}><span>Mix in the utility version<small>${d.mix.on ? 'On - it takes effect on the next batch, nothing restarts.' : 'Off - everyone gets the marketing message.'}</small></span></label></div>` : ''}<div id="dMixBox">${mixPanel(d.mix, r)}</div>
        ${(d.by_number || []).length ? '<h3 style="font-size:14px;margin:14px 0 4px">Sent from</h3>' + d.by_number.map((x) => `<div class="wp-kv"><span>${esc(x.number)}</span><b>${fmt(x.sent)}</b></div>`).join('') : ''}
        ${(d.errors || []).length ? '<h3 style="font-size:14px;margin:14px 0 4px">Why some were not sent</h3>' + d.errors.map((x) => `<div class="wp-kv"><span>${esc(x.err)}</span><b>${fmt(x.n)}</b></div>`).join('') : ''}
        ${k.total ? '<div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn p" id="dWho">Who got what</button></div>' : ''}<div class="wp-hint">Replies: within 2 days of each message. Orders: Shopify orders within 7 days after the message.</div>`;
      const b = $(m.body, '#dWho'); if (b) b.onclick = () => { m.close(); people(c); };
      const rp = $(m.body, '#dRep'); if (rp && r.replied) rp.onclick = () => { m.close(); replies(c); };
      const mxs = $(m.body, '#dMix'); if (mxs) mxs.onchange = async () => { mxs.disabled = true; try { const x = await api.post('wa.camp_mix_set', { id: c.id, on: mxs.checked }); d.mix = x.mix; $(m.body, '#dMixBox').innerHTML = mixPanel(d.mix, r); mxs.closest('.wmx-sw').classList.toggle('off', !x.mix.on); mxs.closest('.wmx-sw').querySelector('small').textContent = x.mix.on ? 'On - it takes effect on the next batch, nothing restarts.' : 'Off - everyone gets the marketing message.'; toast(x.mix.on ? 'Utility mix is on ✓' : 'Utility mix is off'); } catch (e) { mxs.checked = !mxs.checked; toast(e.message, true); } mxs.disabled = false; };
    }

    // ------------------------------------------------------------------------------------------------------------ who got what
    // 🩺 why did it fail — facts from the database + Meta live (wa.camp_diagnose)
    async function diagnose(c) {
      const m = modal('🩺 Diagnose — ' + c.name); m.el.classList.add('cu-wide');
      m.body.innerHTML = '<div class="wp-hint">Checking the message, every number and every failure with Meta… (up to a minute)</div>' + loading;
      let d; try { d = await api.get('wa.camp_diagnose', { id: c.id }); } catch (e) { m.body.innerHTML = errBox(e.message); return; }
      if (d.error) { m.body.innerHTML = errBox(d.error); return; }
      const col = { bad: ['#fdecec', '#b42318', '❌'], warn: ['#fff7e6', '#9a6700', '⚠️'], ok: ['#ecfdf3', '#067647', '✅'] };
      const O = d.facts.outcome || {};
      m.body.innerHTML = `<div class="wp-hint" style="margin-top:0">Checked just now — from your HRMS data and asked live from Meta.</div>
        <div class="cu-kp" style="margin:8px 0">${[['Total', O.total], ['Waiting', O.waiting], ['Delivered + read', (O.delivered || 0) + (O.read || 0)], ['Failed', O.failed], ['Accepted then failed', O.accepted_then_failed], ['Refused at send', O.rejected_at_send]].map(([l, v]) => `<div class="wp-kpi"><small>${l}</small><b>${fmt(v || 0)}</b></div>`).join('')}</div>
        <h3 style="margin:10px 0 6px">Verdict</h3>${d.verdict.map((v, i) => `<div style="background:${col[v.level][0]};border-radius:10px;padding:10px 12px;margin-bottom:8px"><b style="color:${col[v.level][1]}">${col[v.level][2]} ${i + 1}. ${esc(v.title)}</b>${v.detail ? `<div style="margin-top:4px;font-size:13px">${esc(v.detail)}</div>` : ''}${v.fix ? `<div style="margin-top:4px;font-size:13px"><b>What to do:</b> ${esc(v.fix)}</div>` : ''}</div>`).join('')}
        <details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700">All the facts (copy this to send)</summary><pre id="cuDgR" style="white-space:pre-wrap;font-size:12px;background:#f6f7f9;border-radius:8px;padding:10px;margin-top:6px;max-height:340px;overflow:auto">${esc(d.report)}</pre></details>
        <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn p" id="cuDgC">📋 Copy the report</button></div>`;
      $(m.body, '#cuDgC').onclick = async () => { try { await navigator.clipboard.writeText(d.report); toast('Copied ✓'); } catch (e) { const r = document.createRange(); r.selectNodeContents($(m.body, '#cuDgR')); const sl = getSelection(); sl.removeAllRanges(); sl.addRange(r); toast('Selected — press Ctrl + C'); } };
    }
    // 💬 everybody who replied: the list on the left, the picked customer's chat (campaign message + their reply) on the right — it stays open until the window is closed
    async function replies(c) {
      const m = modal('💬 Replies — ' + c.name); m.el.classList.add('cu-wide', 'cu-xl'); m.body.innerHTML = loading; let rows = [], sel = '', page = 1, more = false, busy = false;
      const load = async (reset) => { if (busy) return; busy = true; if (reset) { page = 1; rows = []; } try { const d = await api.get('wa.camp_people', { id: c.id, f: 'replied', page }); rows = rows.concat(d.rows); more = d.more; } catch (e) { busy = false; m.body.innerHTML = errBox(e.message); return false; } busy = false; return true; };
      if (!(await load(true))) return;
      m.body.innerHTML = `<div class="wp-hint" style="margin:0 0 8px"><b>${fmt(rows.length)}${more ? '+' : ''}</b> customers replied (within 2 days of the message). Tap a customer: their full chat opens on the right — reply, take over or assign right there. It stays until you close this window.</div><div class="cu-rp"><div class="l" id="rpL"></div><div class="r" id="rpR"><div class="wp-empty" style="margin:auto">Tap a customer on the left.</div></div></div><div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" id="rpBack">‹ Back to details</button><button type="button" class="wp-btn s" id="rpRef" title="Load the newest repliers">🔄 Refresh</button><span class="wp-hint" id="rpNew" style="margin:0 0 0 6px"></span></div>`;
      const L = $(m.body, '#rpL'), R = $(m.body, '#rpR');
      const drawL = () => { L.innerHTML = rows.length ? rows.map((r) => `<div class="it ${sel === r.phone ? 'on' : ''}" data-p="${esc(r.phone)}"><b>${esc(r.name || pretty(r.phone))}</b> <span class="cu-ph">${esc(pretty(r.phone))}</span><small>${esc((r.why || '').replace(/^Wrote back: /, ''))}</small></div>`).join('') + (more ? '<div class="wp-row" style="justify-content:center;padding:8px"><button type="button" class="wp-btn s" id="rpMore">Show more</button></div>' : '') : '<div class="wp-empty">Nobody replied yet.</div>';
        L.querySelectorAll('[data-p]').forEach((el) => { el.onclick = () => open(el.dataset.p); }); const mb = $(L, '#rpMore'); if (mb) mb.onclick = async () => { page++; if (await load(false)) drawL(); }; };
      // the right side is the REAL PC chat screen (wa_one.html): reply, voice, photo, Take over, Assign — exactly like the chat window. It stays until the window is closed.
      let fr = null;
      function open(ph) {
        sel = ph; drawL(); const key = String(ph).replace(/\D/g, '').slice(-10);
        R.innerHTML = '<iframe title="WhatsApp chat" style="width:100%;height:100%;min-height:560px;border:0;display:block;background:#efeae2" allow="microphone; clipboard-write"></iframe>'; fr = R.querySelector('iframe');
        fr.src = 'wa_one.html?phone=' + encodeURIComponent(key);   // the PC chat screen (same as the Chats screen: Take over, Assign, tags, replies)
      }
      // 🔄 new repliers: Refresh reloads the list (the open chat stays); it also reloads by itself every 30 seconds while this window is open
      const refresh = async (btn) => { const before = new Set(rows.map((r) => r.phone)); if (btn) { btn.disabled = true; btn.textContent = '🔄 Refreshing…'; } const ok = await load(true); if (btn) { btn.disabled = false; btn.textContent = '🔄 Refresh'; } if (!ok) return; drawL(); const fresh = rows.filter((r) => !before.has(r.phone)).length; const nb = $(m.body, '#rpNew'); if (nb) nb.textContent = fresh ? '+' + fresh + ' new repl' + (fresh === 1 ? 'y' : 'ies') : 'Up to date · ' + new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }); };
      $(m.body, '#rpRef').onclick = function () { refresh(this); };
      const rpT = setInterval(() => { if (!m.el.isConnected) { clearInterval(rpT); return; } if (document.hidden) return; refresh(null); }, 30000);
      drawL(); $(m.body, '#rpBack').onclick = () => { m.close(); details(c); };
    }
    async function people(c) {
      const m = modal(c.name); m.el.classList.add('cu-wide'); let f = 'all', q = '', page = 1, rows = [], more = false, counts = null, reasons = [], res = null, busy = false, qt = null, wait = '';
      m.body.innerHTML = loading;
      try { res = (await api.get('wa.camp_get', { id: c.id })).campaign.result; } catch (e) { m.body.innerHTML = errBox(e.message); return; }
      async function fetchRows(reset) {
        if (busy) return; busy = true; if (reset) { page = 1; rows = []; }
        try { const d = await api.get('wa.camp_people', { id: c.id, f, q, page }); rows = rows.concat(d.rows); more = d.more; if (d.counts) { counts = d.counts; reasons = d.reasons || []; wait = d.wait || ''; } } catch (e) { busy = false; $(m.body, '#cuL').innerHTML = errBox(e.message); return; }
        busy = false; list(); chipsDraw();
      }
      function shell() {
        const k = counts || { all: 0 };
        m.body.innerHTML = `<div class="cu-kp"><div class="wp-kpi"><small>Sent</small><b>${fmt(res.sent)}</b></div><div class="wp-kpi"><small>Delivered</small><b>${fmt(res.delivered)}</b></div><div class="wp-kpi"><small>Read</small><b>${fmt(res.read)}</b></div><div class="wp-kpi" id="cuRep" style="${res.replied ? 'cursor:pointer;outline:2px solid #1a8f5a33' : ''}"><small>Replied ${res.replied ? '›' : ''}</small><b>${fmt(res.replied)}</b></div><div class="wp-kpi"><small>Orders</small><b>${fmt(res.orders)}</b></div><div class="wp-kpi"><small>Failed</small><b style="${res.failed ? 'color:#b42318' : ''}">${fmt(res.failed)}</b></div></div>
          <div class="cu-fl"><div class="cu-chips" id="cuCh"></div><button type="button" class="wp-btn p s" id="cuNowAll" hidden>⚡ Send all waiting now</button><button type="button" class="wp-btn s" id="cuDiag" title="Find out why messages failed — checks the message, the numbers, every Meta error code and the webhook">🩺 Diagnose</button><button type="button" class="wp-btn s" id="cuCsv">⬇ Download list (CSV)</button></div><div id="cuNowN"></div>
          <input class="wp-in" id="cuQ" type="search" placeholder="Search name or number…" style="margin:8px 0" value="${esc(q)}"><div id="cuL"></div><div id="cuR"></div>`;
        $(m.body, '#cuQ').oninput = function () { clearTimeout(qt); q = this.value.trim(); qt = setTimeout(() => fetchRows(true), 350); };
        $(m.body, '#cuCsv').onclick = csv;
        const rq = $(m.body, '#cuRep'); if (rq && res.replied) rq.onclick = () => { m.close(); replies(c); };
        $(m.body, '#cuNowAll').onclick = () => sendNow('');
        $(m.body, '#cuDiag').onclick = () => diagnose(c);
      }
      async function sendNow(phone) {
        const k = counts || {}; if (!phone && !confirm('Send to all ' + fmt(k.queued || 0) + ' waiting customers now, instead of at their best hour?')) return;
        try { const d = await api.post('wa.camp_send_now', { id: c.id, phone }); const wn = $(m.body, '#cuNowN'); wn.dataset.sent = '1'; wn.innerHTML = d.wait ? `<div class="wp-note" style="margin:6px 0">⏳ ${d.moved ? fmt(d.moved) + ' moved to now, ' : ''}${fmt(d.due)} are due — <b>but not sending yet:</b> ${esc(d.wait)}</div>` : `<div class="wp-ok">⚡ ${d.moved ? fmt(d.moved) + ' moved to now. ' : ''}${esc(d.note || '')}</div>`; fetchRows(true); }
        catch (e) { toast(e.message, true); }
      }
      function chipsDraw() {
        const wn = $(m.body, '#cuNowN'); if (wn && !wn.dataset.sent) wn.innerHTML = wait ? `<div class="wp-note" style="margin:6px 0">⏳ <b>Why they are still waiting:</b> ${esc(wait)}</div>` : '';
        const k = counts || {}; const na = $(m.body, '#cuNowAll'); if (na) { na.hidden = !(k.queued > 0); na.textContent = '⚡ Send all ' + fmt(k.queued || 0) + ' waiting now'; } $(m.body, '#cuCh').innerHTML = Object.keys(ERRS.f).filter((x) => x === 'all' || x === f || (k[x] || 0) > 0).map((x) => `<button type="button" class="wp-chip ${f === x ? 'on' : ''}" data-f="${x}">${ERRS.f[x]} ${fmt(k[x] || 0)}</button>`).join('');
        $(m.body, '#cuCh').querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { f = b.dataset.f; fetchRows(true); }; });
        $(m.body, '#cuR').innerHTML = reasons.length ? `<div class="wp-hint" style="margin-top:10px">Why they failed: ${reasons.map((x) => esc(x.text) + ' ' + fmt(x.n)).join(' · ')}. Replies: within 2 days. Orders: within 7 days.</div>` : '<div class="wp-hint" style="margin-top:10px">Replies: within 2 days of the message. Orders: within 7 days.</div>';
      }
      function list() {
        const box = $(m.body, '#cuL');
        box.innerHTML = rows.length ? rows.map((r, i) => {
          const s = STATUS[r.status] || [r.status, ''], nm = r.name || pretty(r.phone), when2 = r.status === 'retry' && r.next_try ? 'next try ' + when(r.next_try) : (r.status === 'queued' && r.send_at ? 'goes out ' + when(r.send_at) : (r.sent_at ? when(r.sent_at) : ''));
          return `<div class="cu-pr" data-i="${i}"><div class="wp-av cu-av">${esc((nm.replace(/[^\p{L}\p{N}]/gu, '')[0] || '?').toUpperCase())}</div><div class="cu-pm"><b>${esc(nm)}</b> <span class="cu-ph">${esc(pretty(r.phone))}</span> <span class="wmx-ver ${r.variant === 'U' ? 'u' : ''}">${esc(r.version || 'Marketing')}</span><div class="wp-hint" style="margin:2px 0 0">${esc(r.why || '')}</div>${r.raw ? `<details style="margin-top:2px"><summary class="wp-hint" style="cursor:pointer;margin:0">🔍 Meta’s exact answer</summary><pre style="white-space:pre-wrap;font-size:11px;background:#f6f7f9;border-radius:6px;padding:6px;margin:4px 0 0;max-height:160px;overflow:auto">${esc(r.raw)}</pre></details>` : ''}${r.text ? `<div class="cu-tx" hidden><div class="wp-bub">${esc(r.text)}</div></div>` : ''}</div>
            <div class="cu-ps"><span class="wp-pill ${s[1]}">${s[0]}</span><span class="cu-w">${esc(when2)}</span><span class="cu-w">${r.tries} of ${r.max_tries}</span>${r.text ? '<button type="button" class="wp-btn s" data-t="1">Message</button>' : ''}${r.status === 'queued' ? '<button type="button" class="wp-btn s" data-now="1" title="Send to this person now">⚡ Now</button>' : ''}<button type="button" class="wp-btn s" data-c="1">Chat</button></div></div>`;
        }).join('') + (more ? '<div class="wp-row" style="justify-content:center;margin-top:10px"><button type="button" class="wp-btn" id="cuMore">Show more</button></div>' : '') : '<div class="wp-empty">Nobody in this list.</div>';
        box.querySelectorAll('[data-c]').forEach((b) => { b.onclick = () => { const p = rows[+b.closest('.cu-pr').dataset.i].phone; m.close(); if (ctx && ctx.openChat) ctx.openChat(p); }; });
        box.querySelectorAll('[data-now]').forEach((b) => { b.onclick = () => { b.disabled = true; sendNow(rows[+b.closest('.cu-pr').dataset.i].phone); }; });
        box.querySelectorAll('[data-t]').forEach((b) => { b.onclick = () => { const t = b.closest('.cu-pr').querySelector('.cu-tx'); t.hidden = !t.hidden; }; });
        const mb = $(box, '#cuMore'); if (mb) mb.onclick = () => { page++; fetchRows(false); };
      }
      async function csv() {
        const b = $(m.body, '#cuCsv'); b.disabled = true;
        try {
          const r = await fetch(api.buildUrl('wa.camp_people_csv', { id: c.id, f }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('Could not make the file (' + r.status + ').');
          const blob = await r.blob(), a = document.createElement('a'), cd = r.headers.get('Content-Disposition') || '', mm = /filename="?([^";]+)"?/.exec(cd);
          a.href = URL.createObjectURL(blob); a.download = mm ? mm[1] : 'campaign_' + c.id + '.csv'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
        } catch (e) { toast(e.message, true); } b.disabled = false;
      }
      shell(); await fetchRows(true);
    }

    // ------------------------------------------------------------------------------------------------------------ message pieces
    async function pieces(after) {
      const m = modal('Message pieces'); let L = [], slot = 'offer', meta = null; m.body.innerHTML = loading;
      async function load() { try { const d = await api.get('wa.piece_list'); L = d.pieces; meta = d; } catch (e) { m.body.innerHTML = errBox(e.message); return; } draw(); }
      function draw() {
        const rows = L.filter((x) => x.slot === slot), tags = meta.tags, cnt = (s) => (meta.counts[s] ? meta.counts[s].on + '/' + meta.counts[s].n : '0');
        m.body.innerHTML = `<div class="wp-hint" style="margin:0 0 8px">A piece is a ready line for one place in a message — an offer line, an opening, a closing, an emoji, a date phrase. A campaign variable can take “a line from my pieces”: every customer gets one, picked at random or to fit them. Only lines that are <b>switched on</b> are ever used.</div>
          <div class="cu-chips">${SLOTS.map(([k, l]) => `<button type="button" class="wp-chip ${slot === k ? 'on' : ''}" data-s="${k}">${l} ${cnt(k)}</button>`).join('')}</div>
          ${slot === 'offer' ? '<div class="wp-note">Offer lines promise a price, a discount or a gift. They start <b>switched off</b> — switch on only the offers your shop really runs right now. Nothing is made up for you.</div>' : ''}
          <div id="cuPl" style="margin-top:8px">${rows.length ? rows.map((x) => `<div class="cu-pc" data-id="${x.id}"><div class="cu-pt"><span>${esc(x.text)}</span><div class="wp-hint" style="margin:0">${esc(tags[x.tag] || x.tag)} · used ${fmt(x.uses)}×${x.discount ? ' · <b style="color:#b7791f">price / offer</b>' : ''}</div></div>${sw('cuA' + x.id, x.active)}<button type="button" class="wp-btn s r" data-x="${x.id}">Delete</button></div>`).join('') : '<div class="wp-empty">No line here yet.</div>'}</div>
          <label class="wp-fl">Add a line to “${esc(meta.slots[slot])}”</label><input class="wp-in" id="cuNt" maxlength="200" placeholder="${slot === 'offer' ? 'e.g. Free shipping on every order today' : (slot === 'emoji' ? '✨' : (slot === 'date' ? 'e.g. is weekend' : 'Write the line'))}">
          <div class="wp-2"><div>${sel('cuNg', Object.keys(tags).map((k) => [k, tags[k]]), 'any')}</div><div><div class="wp-row" style="min-height:44px"><span class="wp-hint" style="margin:0">Switched on</span>${sw('cuNa', slot !== 'offer')}</div></div></div>
          <div id="cuE"></div><div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn p" id="cuAdd">Add</button><button type="button" class="wp-btn" id="cuSt">Add the ready-made starter lines</button></div><div class="wp-hint">The starter set has about 60 Hinglish lines. Offer lines in it are added switched off.</div>`;
        m.body.querySelectorAll('[data-s]').forEach((b) => { b.onclick = () => { slot = b.dataset.s; draw(); }; });
        m.body.querySelectorAll('.cu-pc input[type=checkbox]').forEach((b) => { b.onchange = async () => { const id = +b.id.slice(3); try { await api.post('wa.piece_save', { id, active: b.checked }); const p = L.find((x) => x.id === id); if (p) p.active = b.checked; const c = meta.counts[slot]; if (c) c.on += b.checked ? 1 : -1; draw(); } catch (e) { toast(e.message, true); b.checked = !b.checked; } }; });
        m.body.querySelectorAll('[data-x]').forEach((b) => { b.onclick = async () => { if (!confirm('Delete this line?')) return; try { await api.post('wa.piece_del', { id: +b.dataset.x }); load(); } catch (e) { toast(e.message, true); } }; });
        $(m.body, '#cuAdd').onclick = async function () {
          const t = $(m.body, '#cuNt').value.trim(); if (!t) { $(m.body, '#cuE').innerHTML = errBox('Write the line first.'); return; } this.disabled = true;
          try { const r = await api.post('wa.piece_save', { slot, text: t, tag: $(m.body, '#cuNg').value, active: $(m.body, '#cuNa').checked }); if (r.discount && r.active) toast('Saved — this line is ON, so it can go to customers. Check that the offer is real.'); else toast('Added' + (r.discount && !r.active ? ' (switched off — it talks about a price)' : '')); load(); } catch (e) { $(m.body, '#cuE').innerHTML = errBox(e.message); this.disabled = false; }
        };
        $(m.body, '#cuSt').onclick = async function () { this.disabled = true; try { const r = await api.post('wa.piece_starter', {}); toast(r.added ? 'Added ' + r.added + ' lines — offer lines are switched off until you turn them on' : 'You already have all the starter lines'); load(); } catch (e) { toast(e.message, true); this.disabled = false; } };
      }
      if (after) { const mo = new MutationObserver(() => { if (!m.el.isConnected) { mo.disconnect(); after(); } }); mo.observe(document.body, { childList: true }); } load();
    }

    // ------------------------------------------------------------------------------------------------------------ the wizard
    // the list loads without the message library (slow); the wizard fetches it once, the first time it is opened
    async function wizard(c, seg, util) {
      if (!TPLX) { try { TPLX = await api.get('wa.camp_templates'); } catch (e) { toast(e.message, true); return; } }
      D.templates = TPLX.templates; D.pieces = TPLX.pieces; D.can_media = TPLX.can_media; wizardGo(c, seg, util);
    }
    function wizardGo(c, seg, util) {   // util: the 💸 utility follow-up campaign (only people who qualify, the standard follow-ups, the offer goes free after a reply)   // seg: people picked on the Customers screen ({tag, name, n}) — the audience is exactly their segment tag
      const A = c ? clone(c.audience) : (seg ? { src: 'contacts', f: { gtags: [seg.tag] }, fName: seg.name, paste: '' } : {}); A.src = A.src || 'contacts'; A.f = A.f || {}; A.paste = A.paste || '';
      if (A.f.gtag && !(A.f.gtags || []).length) { A.f.gtags = [A.f.gtag]; delete A.f.gtag; } A.f.gtags = A.f.gtags || [];
      const t0 = c ? D.templates.find((x) => x.id === c.tpl_id) : null;
      const W = { id: c ? c.id : 0, name: c ? c.name : (seg ? seg.name.replace(/^Picked /, 'Customers ') : ''), A, media: c && c.media_lib_id ? { id: c.media_lib_id, kind: c.media_kind || 'image', title: c.media_title || '' } : null, mfolder: c ? (c.media_folder || '') : '', tfolder: '', tpl_id: c ? c.tpl_id : 0,
        specs: c && t0 ? normVars(c.vars, t0.vars).map((x) => ({ ...x, sug: false })) : [], extra: c ? clone(c.extra || {}) : {}, push: c ? c.push : 'aggressive', own: c ? !!c.own_hour : false, retry: c ? c.retry !== false : false,
        start: c && c.start_at ? 'custom' : 'now', start_at: c && c.start_at ? c.start_at.replace(' ', 'T').slice(0, 16) : '', mix: c && c.extra && c.extra.mix !== undefined ? !!c.extra.mix : true, rate: c ? c.rate : 60, win_end: c && c.win_end ? c.win_end.replace(' ', 'T').slice(0, 16) : '', sw: c ? c.switch_after : 2, consent: false };
      if (c && c.push === 'normal' && !c.own_hour && !c.retry) { /* an old campaign keeps its old way */ }
      const isU = !!util || !!(c && c.extra && c.extra.kind === 'util'); const m = modal(c ? 'Edit campaign' : (isU ? '💸 New utility follow-up' : 'New campaign')); m.el.classList.add('cu-wide'); if (!c && window.WaCampReport) { const lb = document.createElement('div'); lb.style.padding = '0 16px'; m.body.parentNode.insertBefore(lb, m.body); WaCampReport.learnBox(lb); }   // 💡 what worked in the last campaigns, before you make the next
      let PN = null, pvT = 0; const grpOn = () => Object.keys(W.A.f || {}).some((k) => (Array.isArray(W.A.f[k]) ? W.A.f[k].length : W.A.f[k] !== '' && W.A.f[k] != null));
      let step = 1, pv = null; const X = { tags: null, sugg: null, hours: null, gal: null, pcs: null, lines: [] };
      const typedBar = () => (step === 2 && !W.util ? '<div class="wp-note" style="margin:0 0 10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between"><span><b>✍️ Manual message</b> — type your own message instead of a template (goes only to people who wrote in the last 24 hours).</span><button type="button" class="wp-btn" id="cuTyped">✍️ Write a manual message</button></div>' : '');
      m.body.addEventListener('click', (ev) => { const b = ev.target.closest && ev.target.closest('#cuTyped'); if (b) typedSend(); });
      async function typedSend() {
        const tm = modal('✍️ Manual message'); tm.body.innerHTML = loading;
        let r; try { r = await api.post('wa.camp_typed_phones', { audience: W.A }); } catch (e) { tm.body.innerHTML = errBox(e.message); return; }
        const ppl = r.people || [], inw = ppl.filter((x) => x.win);
        if (!ppl.length) { tm.body.innerHTML = '<div class="wp-note">Nobody is picked yet. Choose who gets it in step 1 first.</div>'; return; }
        tm.body.innerHTML = `<div class="wp-note"><b>${fmt(ppl.length)}</b> customers picked · <b>${fmt(inw.length)}</b> wrote to you in the last 24 hours and can get a typed message · ${fmt(ppl.length - inw.length)} are outside 24 hours (they need a template, so they are left out here).</div>
          <textarea class="wp-in" id="tyTx" rows="7" placeholder="Type your message… use {name} for the customer's first name" style="width:100%;box-sizing:border-box;margin:10px 0"></textarea><div id="tyMsg"></div>
          <button type="button" class="wp-btn p" id="tyGo" ${inw.length ? '' : 'disabled'}>📨 Send to ${fmt(inw.length)} customers</button>`;
        $(tm.body, '#tyGo').onclick = async () => {
          const txt = $(tm.body, '#tyTx').value.trim(); if (!txt) { $(tm.body, '#tyMsg').innerHTML = errBox('Write a message first.'); return; }
          if (!confirm('Send this typed message to ' + fmt(inw.length) + ' customers now?\n\n' + txt.slice(0, 200))) return;
          let i = 0, ok = 0, bad = 0, stop = false; const tot = inw.length; $(tm.body, '#tyGo').disabled = true;
          const paint = () => { $(tm.body, '#tyMsg').innerHTML = `<div class="wp-ok">Sending… ${fmt(ok + bad)} of ${fmt(tot)} · ${fmt(ok)} sent · ${fmt(bad)} not sent <button type="button" class="wp-btn" id="tyStop" style="margin-left:8px">Stop</button></div>`; const sb = $(tm.body, '#tyStop'); if (sb) sb.onclick = () => { stop = true; }; };
          const one = async () => { while (!stop && i < tot) { const p = inw[i++]; const nm = String(p.name || '').trim().split(/\s+/)[0] || 'ji';
            try { await api.post('wa.send', { phone: p.phone, text: txt.replace(/\{name\}/gi, nm), cid: 'cm' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8) }); ok++; } catch (e) { bad++; } paint(); } };
          paint(); await Promise.all([one(), one(), one(), one()]);
          $(tm.body, '#tyMsg').innerHTML = `<div class="wp-ok"><b>Done.</b> ${fmt(ok)} sent · ${fmt(bad)} not sent${stop ? ' (stopped)' : ''}.</div>`;
        };
      }
      const head = () => typedBar() + `<div class="wp-seg">${['Who', 'What', 'When & retries'].map((l, i) => `<button type="button" class="${step === i + 1 ? 'on' : ''}" ${i + 1 > step ? 'disabled' : ''} data-s="${i + 1}">${i + 1} ${l}</button>`).join('')}</div>`;
      const bind = () => m.body.querySelectorAll('[data-s]').forEach((b) => { b.onclick = () => { step = +b.dataset.s; go(); }; });
      const go = () => { if (step === 1) draw1(); else if (step === 2) draw2(); else draw3(); m.body.scrollTop = 0; };
      W.util = isU; if (isU) { W.extra.ord_days = W.extra.ord_days || 60; W.mix = false; }
      const tpl = () => D.templates.find((x) => x.id === W.tpl_id);
      const pushOf = () => PUSH.find((p) => p[0] === W.push) || PUSH[2];
      function auditPayload() { return { kind: W.util ? 'util' : '', ord_days: W.extra.ord_days || 60, mix: W.mix, audience: W.A, tpl_id: W.tpl_id, media_lib_id: W.media ? W.media.id : 0, push: W.push, own_hour: W.own, rate: W.rate, start_at: typeof startAt === 'function' ? startAt() : '' }; }

      // ---------------------------------------------------------------- step 1: who
      async function preview() {
        const box = $(m.body, '#cuPv'); if (!box) return; box.innerHTML = '<div class="wp-hint">Counting…</div>';
        try { pv = await api.post('wa.camp_preview', auditPayload()); const s = pv.stats, fr = pushOf()[2];
          box.innerHTML = `<div class="wp-ok"><b>${fmt(s.send)} customers</b> will get the message.<div class="wp-hint" style="color:inherit;opacity:.85">${fmt(s.matched)} match your filters${s.optout ? ' · ' + fmt(s.optout) + ' opted out (left out)' : ''}${s.recent ? ' · ' + fmt(s.recent) + ' got a campaign message in the last ' + fr + ' days (left out)' : ''}</div>${pv.sample.length ? '<div class="wp-hint" style="color:inherit;opacity:.85">For example: ' + pv.sample.map((x) => esc(x.name || pretty(x.phone))).join(' · ') + ' …</div>' : ''}</div>`
          + (!s.matched && W.A.cfu && W.A.cfu.dk === 'in' && (W.A.cfu.df || W.A.cfu.dt) ? '<div class="wp-note">Nobody matched. Imported customers have never written to you — for them choose the date type <b>“Added / imported / first message”</b> instead of “Last wrote to us”.</div>' : ''); }
        catch (e) { pv = null; box.innerHTML = errBox(e.message); }
      }
      function readF() {
        const g = (id) => { const n = $(m.body, id); return n ? n.value : null; }; const nm = g('#cuN'); if (nm !== null) W.name = nm; const p = g('#cuPaste'); if (p !== null) W.A.paste = p;
        if (PN) { W.A.cfu = PN.ui(); W.A.cf = PN.out(); }
        if (!PN && $(m.body, '#cuOrd')) { const f = { gtags: W.A.f.gtags || [] }; const put = (k, v) => { if (v !== null && v !== '' && v !== '0' && v !== undefined) f[k] = v; }; put('ord', g('#cuOrd')); put('dsl', g('#cuDsl')); put('ign', g('#cuIgn')); put('src', g('#cuSrc')); put('st', g('#cuSt')); put('wrote', g('#cuWr')); put('ords', g('#cuOs'));
          [['in_from', '#cuIf'], ['in_to', '#cuIt'], ['added_from', '#cuAf'], ['added_to', '#cuAt']].forEach(([k, id]) => { const v = g(id); if (v) f[k] = v; });
          const a = g('#cuWf'), b = g('#cuWt'); if (a !== '' && a !== null) f.wrote_from = Math.max(0, +a || 0); if (b !== '' && b !== null) f.wrote_to = Math.max(0, +b || 0); if (!f.gtags.length) delete f.gtags; W.A.f = f; }
      }
      function draw1() {
        const f = W.A.f, tags = X.tags || [], on = new Set(f.gtags || []), fr = pushOf()[2];
        m.body.innerHTML = head() + `<label class="wp-fl" style="margin-top:0">Campaign name</label><input class="wp-in" id="cuN" maxlength="80" value="${esc(W.name)}" placeholder="e.g. Diwali offer — asked price last week">
          <label class="wp-fl">Who gets it</label><div class="wp-seg">${[['contacts', 'Our contacts (chatted + imported)'], ['all', '+ all Shopify customers'], ['paste', 'Paste numbers']].map(([v, l]) => `<button type="button" data-src="${v}" class="${W.A.src === v ? 'on' : ''}">${l}</button>`).join('')}</div>
          ${W.A.src === 'paste' ? `<textarea class="wp-in" id="cuPaste" rows="6" placeholder="One number per line">${esc(W.A.paste)}</textarea>` : `<div id="cuAi"></div>
          ${grpOn() ? `<div class="cu-grp"><span>✨ Starting group: <b>${esc(W.A.fName || 'filters saved with this campaign')}</b></span><button type="button" class="wp-btn s" id="cuGx">✕ Remove</button></div>` : ''}
          <div class="cu-sec" style="margin-top:12px;font-size:15px">🔎 Who exactly <small>(empty = everyone in “${W.A.src === 'all' ? 'contacts + Shopify' : 'our contacts'}”)</small></div>
          <div id="cuFp" class="cu-fp"></div>
          <div class="wp-hint" style="margin-top:8px">Left out automatically: opted out · got a campaign in the last ${fr} day${fr === 1 ? '' : 's'} · asked us to stop.</div>`}
          <div id="cuPv" style="margin-top:10px"></div><div id="cuE"></div><div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn" id="cuCnt">🔄 Count again</button><button type="button" class="wp-btn p" id="cuNext">Next ›</button></div>`;
        PN = null; const fp = $(m.body, '#cuFp');
        if (fp && window.WaCust && WaCust.panel) { PN = WaCust.panel(fp, { cf: W.A.cfu || {}, onChange: (ui, out) => { W.A.cfu = ui; W.A.cf = out; pv = null; clearTimeout(pvT); pvT = setTimeout(preview, 500); } }); }
        const gx = $(m.body, '#cuGx'); if (gx) gx.onclick = () => { readF(); W.A.f = {}; W.A.fName = ''; pv = null; draw1(); };
        m.body.querySelectorAll('[data-src]').forEach((b) => { b.onclick = () => { readF(); W.A.src = b.dataset.src; pv = null; draw1(); }; }); bind();
        m.body.querySelectorAll('#cuTg [data-k]').forEach((b) => { b.onclick = () => { const s = new Set(W.A.f.gtags || []); if (s.has(b.dataset.k)) s.delete(b.dataset.k); else s.add(b.dataset.k); W.A.f.gtags = [...s]; b.classList.toggle('on'); }; });
        m.body.querySelectorAll('[data-q]').forEach((a) => { a.onclick = (e) => { e.preventDefault(); const [x, y] = a.dataset.q.split(','); $(m.body, '#cuWf').value = x; $(m.body, '#cuWt').value = y; wh(); }; });
        const wh = () => { const a = $(m.body, '#cuWf'), b = $(m.body, '#cuWt'); if (!a) return; const x = a.value, y = b.value; $(m.body, '#cuWh').textContent = x !== '' && y !== '' ? 'between ' + x + ' and ' + y + ' days ago' : (x !== '' ? 'more than ' + x + ' days ago' : (y !== '' ? 'in the last ' + (+y + 1) + ' days' : 'any time')); };
        if ($(m.body, '#cuWf')) { $(m.body, '#cuWf').oninput = wh; $(m.body, '#cuWt').oninput = wh; wh(); }
        $(m.body, '#cuCnt').onclick = () => { readF(); preview(); };
        $(m.body, '#cuNext').onclick = () => { readF(); if (!W.name.trim()) { $(m.body, '#cuE').innerHTML = errBox('Give the campaign a name.'); return; } step = 2; go(); };
        clearTimeout(pvT); pvT = setTimeout(preview, pv ? 0 : 300);
        if (W.A.src !== 'paste') {
          if (!PN && !X.tags) api.get('wa.g_tags').then((d) => { X.tags = (d.tags || []).filter((t) => t.all > 0); if (step === 1 && m.el.isConnected) { readF(); draw1(); } }).catch(() => { X.tags = []; });
          if (!X.sugg) api.get('wa.camp_suggest').then((d) => { X.sugg = d.items || []; if (step === 1 && m.el.isConnected) suggDraw(); }).catch(() => { X.sugg = []; }); else suggDraw();
        }
      }
      function suggDraw() {
        const box = $(m.body, '#cuAi'); if (!box || !X.sugg || !X.sugg.length) return; const best = X.sugg.find((x) => x.kind === 'best') || X.sugg.find((x) => x.kind === 'also'), rest = X.sugg.filter((x) => x !== best);
        box.innerHTML = `<div class="cu-ai"><b>✨ Claude suggests</b><br>${best ? `<b>${fmt(best.count)} people</b> ${esc(best.text)} <button type="button" class="wp-btn s" data-u="${esc(best.id)}">Use this</button>` : 'Nothing special to suggest right now.'}${rest.length ? `<div class="also">Also: ${rest.map((x) => `<a href="#" data-u="${esc(x.id)}"><b>${fmt(x.count)}</b> ${esc(x.kind === 'skip' ? x.text : x.title.toLowerCase())}</a>`).join(' · ')}</div>` : ''}<div class="wp-hint" style="color:inherit;opacity:.7">These groups come from simple rules on tags, orders and when people last wrote — counts are people you can message now.</div></div>`;
        box.querySelectorAll('[data-u]').forEach((b) => { b.onclick = (e) => { e.preventDefault(); const s = X.sugg.find((x) => x.id === b.dataset.u); if (!s) return; readF(); W.A = { src: s.audience.src || 'contacts', paste: '', f: clone(s.audience.f || {}), fName: s.title, cf: W.A.cf || {}, cfu: W.A.cfu || {} }; if (!W.name.trim()) W.name = s.title; pv = {}; draw1(); }; });
      }

      // ---------------------------------------------------------------- step 2: what
      const wantHeader = () => (W.media ? { image: 'IMAGE', video: 'VIDEO', document: 'DOCUMENT' }[W.media.kind] : '');
      const fits = (t) => (W.media ? t.header_format === wantHeader() : ['', 'LOCATION'].includes(t.header_format));
      async function ensure2() {
        const jobs = [];
        if (!X.gal) jobs.push(api.get('wa.g_gallery').then((d) => { X.gal = d.gallery || { folders: [], items: [] }; }).catch(() => { X.gal = { folders: [], items: [] }; }));
        if (!X.pcs) jobs.push(api.get('wa.piece_list').then((d) => { X.pcs = d.counts; X.lines = d.pieces; }).catch(() => { X.pcs = {}; X.lines = []; }));
        await Promise.all(jobs); X.pcsOn = {}; Object.keys(X.pcs || {}).forEach((k) => { X.pcsOn[k] = X.pcs[k].on; });
      }
      function draw2() {
        if (W.util) return drawU2();
        m.body.innerHTML = head() + loading; bind();
        ensure2().then(() => {
          if (step !== 2 || !m.el.isConnected) return;
          m.body.innerHTML = head() + `<div class="cu-grid"><div class="cu-main">
            <div class="cu-sec" style="margin-top:0">1 Photo or video <small>(from your gallery folders — or send text only)</small></div><div class="cu-chips" id="cuFo"></div><div class="cu-ths" id="cuTh"></div>
            <div class="cu-sec">2 Meta-approved message <small id="cuTn"></small></div><input type="search" class="wp-in" id="cuTq" placeholder="🔍 Search approved messages — name or words in the message" autocomplete="off" style="margin:0 0 8px;min-height:40px"><div class="cu-chips" id="cuTf"></div><div id="cuTl"></div>
            <div id="cuVw"></div><div id="cuEx"></div><div id="cuMx"></div><div id="cuUt"></div><div id="cuSgst"></div>
            <div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" id="cuPc">Message pieces</button><span class="wp-hint" style="margin:0">Add your own offer lines, openings and closings.</span></div>
          </div><div class="cu-side"><div class="cu-pvbox" id="cuPh"></div></div></div><div id="cuE"></div><div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn" id="cuBack">‹ Back</button><button type="button" class="wp-btn p" id="cuNext">Next ›</button></div>`;
          bind(); drawMedia(); drawTpls(); drawVars(); drawPh();
          if (window.WaSmart && $(m.body, '#cuSgst')) X.nsH = WaSmart.normalSuggest($(m.body, '#cuSgst'), { state: (X.nsS = X.nsS || {}), lang: () => 'en', onSaved: () => { D.stale = true; } });
          $(m.body, '#cuBack').onclick = () => { step = 1; go(); };
          $(m.body, '#cuPc').onclick = () => pieces(() => { X.pcs = null; if (step === 2) draw2(); });
          $(m.body, '#cuNext').onclick = () => {
            const t = tpl(); const e = $(m.body, '#cuE'); if (!t) { e.innerHTML = errBox('Choose an approved message first.'); return; }
            if (t.header_format && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(t.header_format) && !W.media) { e.innerHTML = errBox('This message has a photo, video or PDF on top. Choose the file in part 1.'); return; }
            const bad = W.specs.findIndex((s) => (s.src === 'fixed' && !s.val.trim()) || (s.src === 'coupon' && !s.val.trim()) || (s.src === 'piece' && !s.slot)); if (bad >= 0) { e.innerHTML = errBox('Fill the value for {{' + (bad + 1) + '}}.'); return; }
            const np = W.specs.find((s) => s.src === 'piece' && !(X.pcsOn[s.slot] > 0)); if (np) { e.innerHTML = errBox('No line is switched on for “' + (SLOTS.find((x) => x[0] === np.slot) || [0, np.slot])[1] + '”. Open Message pieces and switch some on.'); return; }
            if (X.nsH) X.nsH.settle().then((o) => { if (o && o.length) toast(o.length + ' suggested message' + (o.length === 1 ? '' : 's') + ' sent to Meta for approval.'); }).catch(() => { });
            step = 3; go();
          };
        });
      }
      function drawMedia() {
        const g = X.gal, items = g.items.filter((x) => KIND_ICON[x.kind]), fol = [['', 'All']].concat(g.folders.filter((f) => items.some((x) => x.folder === f.k)).map((f) => [f.k, f.t]));
        $(m.body, '#cuFo').innerHTML = `<button type="button" class="wp-chip ${!W.media ? 'on' : ''}" data-none="1">No photo — text only</button>` + fol.map(([k, l]) => `<button type="button" class="wp-chip ${W.mfolder === k && W.media ? 'on' : ''}" data-f="${esc(k)}">${esc(l)}</button>`).join('');
        const shown = items.filter((x) => !W.mfolder || x.folder === W.mfolder).slice(0, 80);
        $(m.body, '#cuTh').innerHTML = shown.length ? shown.map((x) => `<button type="button" class="cu-th ${W.media && W.media.id === x.id ? 'on' : ''}" data-id="${x.id}"><div class="im" data-im="${x.kind === 'image' ? x.id : ''}">${x.kind === 'image' ? '' : KIND_ICON[x.kind]}</div><span>${esc(x.title)}</span></button>`).join('') : '<div class="wp-hint">No photos in this folder yet. Add them in Media library.</div>';
        $(m.body, '#cuTh').querySelectorAll('.im[data-im]').forEach((n) => { if (n.dataset.im) thumb(n.dataset.im, n); });
        $(m.body, '#cuFo').querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { W.mfolder = b.dataset.f; drawMedia(); }; });
        $(m.body, '#cuFo').querySelector('[data-none]').onclick = () => { W.media = null; W.mfolder = ''; afterMedia(); };
        $(m.body, '#cuTh').querySelectorAll('[data-id]').forEach((b) => { b.onclick = () => { const x = items.find((i) => i.id === +b.dataset.id); W.media = { id: x.id, kind: x.kind, title: x.title }; if (W.mfolder === '') W.mfolder = ''; afterMedia(); }; });
      }
      function afterMedia() { const t = tpl(); if (t && !fits(t)) { W.tpl_id = 0; W.specs = []; } drawMedia(); drawTpls(); drawVars(); drawPh(); }
      function drawTpls() {
        // 💸 Type chips: All · Marketing · Utility. "Utility" lists the utility messages (category Utility, including the utility versions of your marketing messages) so one can be picked and sent directly.
        const ty = W.ttype || '', fitsAll = D.templates.filter((t) => fits(t)), nU = fitsAll.filter((t) => t.category === 'UTILITY').length, nM = fitsAll.filter((t) => !t.is_twin && t.category !== 'UTILITY').length;
        const all = (ty === 'U' ? fitsAll.filter((t) => t.category === 'UTILITY') : ty === 'M' ? fitsAll.filter((t) => !t.is_twin && t.category !== 'UTILITY') : fitsAll.filter((t) => !t.is_twin)).sort((a, b) => (b.utility_state === 'approved') - (a.utility_state === 'approved')), fol = [...new Set(all.map((t) => t.folder).filter(Boolean))]; if (W.tfolder && !fol.includes(W.tfolder)) W.tfolder = '';
        $(m.body, '#cuTn').textContent = W.media ? '(only messages that fit a ' + ({ image: 'photo', video: 'video', document: 'PDF' }[W.media.kind]) + ' are shown)' : '(text-only messages)';
        $(m.body, '#cuTf').innerHTML = `<div style="display:flex;gap:6px;flex-wrap:wrap;width:100%;margin-bottom:4px"><button type="button" class="wp-chip ${!ty ? 'on' : ''}" data-ty="">All messages</button><button type="button" class="wp-chip ${ty === 'M' ? 'on' : ''}" data-ty="M">📣 Marketing ${nM}</button><button type="button" class="wp-chip ${ty === 'U' ? 'on' : ''}" data-ty="U">💸 Utility ${nU}</button></div>` + (ty === 'U' ? '<div class="wp-note" style="width:100%;margin:2px 0 6px">💸 Utility messages are meant for customers who asked about a perfume or ordered. Meta may re-label or block a utility message used as a plain promotion — for those people use <b>Utility follow-up</b> instead.</div>' : '') + (fol.length ? `<button type="button" class="wp-chip ${!W.tfolder ? 'on' : ''}" data-tf="">All</button>` + fol.map((f) => `<button type="button" class="wp-chip ${W.tfolder === f ? 'on' : ''}" data-tf="${esc(f)}">${esc(f)}</button>`).join('') : '');
        const qq = String(W.tq || '').toLowerCase().trim().split(/\s+/).filter(Boolean);   // 🔍 every word must be in the name or the message
        const list = all.filter((t) => (!W.tfolder || t.folder === W.tfolder) && qq.every((w) => (t.name.replace(/_/g, ' ') + ' ' + (t.body || '') + ' ' + (t.folder || '')).toLowerCase().includes(w)));
        if (qq.length) $(m.body, '#cuTn').textContent = list.length + ' of ' + all.length + ' match “' + W.tq.trim() + '”';
        const qi = $(m.body, '#cuTq'); if (qi && !qi.dataset.on) { qi.dataset.on = '1'; qi.value = W.tq || ''; qi.oninput = () => { W.tq = qi.value; drawTpls(); }; }
        $(m.body, '#cuTl').innerHTML = list.length ? list.map((t) => `<button type="button" class="cu-tp ${t.id === W.tpl_id ? 'on' : ''}" data-t="${t.id}"><b>${esc(t.name.replace(/_/g, ' '))}</b> <span class="wp-pill ok">${t.status === 'partial' ? 'Approved (some numbers)' : 'Approved'}</span>${t.header_format ? `<span class="wp-pill">${{ IMAGE: 'Photo', VIDEO: 'Video', DOCUMENT: 'PDF', LOCATION: 'Location' }[t.header_format] || esc(t.header_format)}</span>` : ''}${t.category === 'UTILITY' ? '<span class="wp-pill b">Utility</span>' : ''}${t.utility_state === 'approved' ? '<span class="wmx-pill ok">Utility version approved</span>' : (t.utility_state === 'waiting' ? '<span class="wmx-pill warn">Utility waiting</span>' : (t.utility_state === 'relabelled' ? '<span class="wmx-pill pu">Utility re-labelled</span>' : ''))}<div class="wp-hint" style="margin:3px 0 0">${esc(t.body.length > 120 ? t.body.slice(0, 120) + '…' : t.body)}</div></button>`).join('')
          : qq.length ? `<div class="wp-note">No approved message matches “${esc(W.tq.trim())}”. Try another word${W.tfolder ? ' or the All folder' : ''}.</div>` : `<div class="wp-note">${W.media ? 'No approved message fits this kind of file yet. Make one in <b>Pre-made messages</b> (choose its Type) — or choose “No photo — text only”.' : 'No approved text-only message yet. Make one in <b>Pre-made messages</b>.'}${!D.can_media && W.media ? ' Messages with a photo on top need the new pre-made message module on the server.' : ''}</div>`;
        $(m.body, '#cuTf').querySelectorAll('[data-tf]').forEach((b) => { b.onclick = () => { W.tfolder = b.dataset.tf; drawTpls(); }; });
        $(m.body, '#cuTf').querySelectorAll('[data-ty]').forEach((b) => { b.onclick = () => { W.ttype = b.dataset.ty; W.tfolder = ''; drawTpls(); }; });
        $(m.body, '#cuTl').querySelectorAll('[data-t]').forEach((b) => { b.onclick = () => { const id = +b.dataset.t; if (id !== W.tpl_id) { W.tpl_id = id; const t = tpl(); W.specs = Array.from({ length: t.vars }, (_, i) => ({ ...suggest(t, i + 1, X.pcsOn), sug: true })); W.extra = {}; } drawTpls(); drawVars(); drawPh(); }; });
      }
      function drawVars() {
        const t = tpl(), box = $(m.body, '#cuVw'); if (!t) { box.innerHTML = ''; $(m.body, '#cuEx').innerHTML = ''; drawMix(); return; }
        box.innerHTML = `<div class="cu-sec">3 Fill the variables <small>(${t.vars ? 'Claude set the best choice — change if you like' : 'this message has none'})</small></div>` + W.specs.map((s, i) => {
          const body = String(t.body), at = body.indexOf('{{' + (i + 1) + '}}'), ctxt = at >= 0 ? body.slice(Math.max(0, at - 18), at).replace(/\{\{\d+\}\}/g, '…').replace(/\s+/g, ' ') : '';
          return `<div class="cu-vr" data-i="${i}"><div class="cu-vk"><b>{{${i + 1}}}</b><span class="wp-hint" style="margin:0">${i === 0 ? 'in the text' : esc(ctxt ? '…' + ctxt : '')}</span></div><div class="cu-vs">${sel('cuS' + i, SRC, s.src)}${s.sug ? '<span class="wp-pill b">✨ suggested</span>' : ''}</div>
            <div class="cu-vv">${s.src === 'fixed' ? `<input class="wp-in" data-v="val" maxlength="300" value="${esc(s.val)}" placeholder="Text that goes in {{${i + 1}}}">` : ''}${s.src === 'coupon' ? `<textarea class="wp-in" rows="2" data-v="val" placeholder="One code, or one code per line — each customer gets one">${esc(s.val)}</textarea>` : ''}${s.src === 'piece' ? `${sel('cuP' + i, [['', 'Choose the kind of line…']].concat(SLOTS.map(([k, l]) => [k, l + ' (' + (X.pcsOn[k] || 0) + ' on)'])), s.slot, '')}` : ''}${['asked_perfume', 'last_ordered', 'city', 'days_since'].includes(s.src) ? `<input class="wp-in" data-v="val" maxlength="60" value="${esc(s.val)}" placeholder="If we do not know: ${s.src === 'asked_perfume' ? 'Best Sellers' : (s.src === 'last_ordered' ? 'your favourite perfume' : (s.src === 'city' ? 'your city' : 'a few'))}">` : ''}</div></div>`;
        }).join('') + (t.vars ? '<div class="wp-hint">Other choices: any fixed text · one coupon per person from a list · a random line from your message pieces. Every customer’s exact words are saved.</div>' : '');
        box.querySelectorAll('.cu-vr').forEach((r) => {
          const i = +r.dataset.i, s = W.specs[i];
          r.querySelector('select[id^=cuS]').onchange = function () { s.src = this.value; s.sug = false; if (s.src === 'piece' && !s.slot) s.slot = ''; drawVars(); drawPh(); };
          const p = r.querySelector('select[id^=cuP]'); if (p) p.onchange = () => { s.slot = p.value; s.sug = false; drawPh(); };
          const v = r.querySelector('[data-v]'); if (v) v.oninput = () => { s.val = v.value; s.sug = false; drawPh(); };
        });
        drawExtra(); drawMix(); drawUt();
      }
      // ✨ the owner's utility version + split for THIS campaign (wa.tpl_utility_list / wa.tpl_make_utility); HRMS fills name / perfume / date itself
      function drawUt() {   // 💸 the owner's utility share: 3 utility versions are made by themselves for every marketing message; the owner picks the % (start 20, raise when it goes well)
        const box = $(m.body, '#cuUt'); if (!box) return; const t = tpl();
        if (!t || t.category !== 'MARKETING') { box.innerHTML = ''; return; }
        X.ut = X.ut || {}; const L = X.ut[t.id];
        if (L === undefined) { X.ut[t.id] = null; box.innerHTML = '<div class="wp-hint">Looking for utility versions…</div>'; api.get('wa.tpl_utility_list', { tpl_id: t.id }).then((d) => { X.ut[t.id] = d.items || []; drawUt(); }).catch(() => { X.ut[t.id] = []; drawUt(); }); return; }
        if (L === null) return;
        const E = W.extra; if (!E.u_off) { if (!E.u_pct) E.u_pct = 20; E.u_auto = 1; } else { E.u_pct = 0; delete E.u_auto; }   // the owner chooses: OFF or 10–100 %
        const mine = L.filter((u) => u.mine), ok = mine.filter((u) => (u.status === 'approved' || u.status === 'partial') && !u.relabelled), pct = +E.u_pct || 0;
        const ST = { approved: ['✅ Approved', 'ok'], pending: ['⏳ Waiting for Meta', 'w'], in_appeal: ['⏳ Waiting', 'w'], rejected: ['❌ Rejected', 'r'], paused: ['⏸ Paused', 'r'], disabled: ['⏸ Off', 'r'], error: ['⚠ Will retry', 'w'], 'not sent': ['· not sent yet', 'w'] };
        const hl = (b) => esc(b).replace(/\{\{(\d)\}\}/g, (x, n) => `<b class="cu-v">{{${n}}}</b>`);
        box.innerHTML = `<div class="cu-sec">💸 Utility share <small>(cheaper than marketing)</small></div>
          <div class="cu-pcts"><button type="button" data-up="0" class="${E.u_off ? 'on' : ''}">OFF</button>${[10, 20, 30, 40, 50, 60, 70, 80, 90, 100].map((p) => `<button type="button" data-up="${p}" class="${!E.u_off && pct === p ? 'on' : ''}">${p}%</button>`).join('')}</div>
          <div class="wp-hint" style="margin:6px 0 8px">${E.u_off ? '<b>Utility OFF — everyone gets the marketing message.</b>' : `<b>${pct}% utility · ${100 - pct}% marketing.</b> Meta allows utility only for customers who wrote to you or ordered in the last 30 days; everyone else gets the marketing message (the preview below shows how many).`}</div>
          ${E.u_off ? '' : '<div class="cu-guard">🛡 Safety: it switches to 100% marketing by itself if <b>4%+ utility messages fail</b>, Meta moves a version to marketing, Meta blocks some, or a number drops to Medium / Low quality.</div>'}
          <div class="cu-sec" style="margin-top:10px">Utility versions of this message <small>(${ok.length} ready of ${mine.length}) — all ready ones take turns</small></div>
          ${mine.length ? mine.map((u) => `<div class="cu-uv ${u.relabelled ? 'off' : ''}"><div class="cu-ub">${hl(u.body)}</div>
            <div class="cu-vars">${(u.vars || []).map((v) => `<span><b>{{${v.n}}}</b> = ${esc(v.what || '?')}</span>`).join('')}</div>
            <div class="cu-chips">${u.relabelled ? '<span class="r">🔁 Meta moved it to marketing — not used (not changed)</span>' : u.draft ? '<span class="w">📝 Draft — check the tone, then send it to Meta</span>' : (u.per || []).map((p) => { const z = ST[p.status] || [p.status, 'w']; return `<span class="${z[1]}">${esc(p.label)}: ${z[0]}</span>`; }).join('')}</div>
            <div class="wp-row" style="margin-top:6px;gap:6px">${u.draft ? `<button type="button" class="wp-btn s" data-us="${u.id}">✅ Send to Meta</button>` : ''}<button type="button" class="wp-btn s gh" data-ue="${u.id}">✏️ Edit</button><button type="button" class="wp-btn s gh" data-ud="${u.id}" style="color:#b42318">🗑 Delete</button></div><div data-uf="${u.id}"></div></div>`).join('')
            : '<div class="wp-note">No utility versions yet. Claude writes them from YOUR message (same greeting and style, only offer / price words taken out). You check them and send the good ones to Meta.</div>'}
          <div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" id="cuUtM">✨ Write 3 utility versions from my message</button></div><div id="cuUtE"></div>
          ${pct && !ok.length ? '<div class="wp-hint">You can start now: everyone gets marketing until a utility version is approved, then it joins by itself.</div>' : ''}`;
        box.querySelectorAll('[data-up]').forEach((b) => { b.onclick = () => { const v = +b.dataset.up; if (v === 0) { E.u_off = 1; E.u_pct = 0; delete E.u_auto; } else { delete E.u_off; E.u_pct = v; E.u_auto = 1; } drawUt(); }; });
        $(box, '#cuUtM').onclick = async (e) => { const b = e.currentTarget; b.disabled = true; b.textContent = '✨ Claude is writing 3 utility versions…';
          try { const r = await api.post('wa.tpl_make_utility', { tpl_id: t.id, n: 3 }); $(box, '#cuUtE').innerHTML = `<div class="wp-ok">${r.made.length} written as drafts — read them, edit if the tone is not right, then press ✅ Send to Meta${r.skipped.length ? ' · ' + r.skipped.length + ' left out (' + esc(r.skipped.map((x) => x.why).join('; ')) + ')' : ''}.</div>`; delete X.ut[t.id]; setTimeout(drawUt, 800); }
          catch (er) { $(box, '#cuUtE').innerHTML = errBox(er.message); b.disabled = false; b.textContent = '✨ Write 3 utility versions from my message'; } };
        box.querySelectorAll('[data-us]').forEach((b) => { b.onclick = async () => { b.disabled = true; b.textContent = 'Sending to Meta…'; try { await api.post('wa.util_send', { id: +b.dataset.us }); toast('Sent to Meta for approval'); delete X.ut[t.id]; drawUt(); } catch (er) { b.disabled = false; b.textContent = '✅ Send to Meta'; toast(er.message, true); } }; });
        box.querySelectorAll('[data-ud]').forEach((b) => { b.onclick = async () => { if (!confirm('Delete this utility version?')) return; try { await api.post('wa.tpl_delete', { id: +b.dataset.ud }); delete X.ut[t.id]; drawUt(); } catch (er) { toast(er.message, true); } }; });
        box.querySelectorAll('[data-ue]').forEach((b) => { b.onclick = () => { const id = +b.dataset.ue, u = mine.find((x) => x.id === id), f = box.querySelector('[data-uf="' + id + '"]'); if (!u || !f) return;
          f.innerHTML = `<textarea class="wp-in" rows="5" style="width:100%;margin-top:6px">${esc(u.body)}</textarea><div class="wp-hint" style="margin:4px 0">Keep {{1}} (name) and {{2}} (perfume). No offer, price or discount words. Claude learns from your change.</div><div class="wp-row" style="gap:6px"><button type="button" class="wp-btn s" data-k="save">Save</button><button type="button" class="wp-btn s" data-k="send">Save & send to Meta</button><button type="button" class="wp-btn s gh" data-k="no">Cancel</button></div><div class="ue-e"></div>`;
          f.querySelectorAll('[data-k]').forEach((k) => { k.onclick = async () => { if (k.dataset.k === 'no') { f.innerHTML = ''; return; } try { await api.post('wa.util_edit', { id, body: f.querySelector('textarea').value, send: k.dataset.k === 'send' ? 1 : 0 }); toast(k.dataset.k === 'send' ? 'Saved and sent to Meta' : 'Saved — Claude will write like this next time'); delete X.ut[t.id]; drawUt(); } catch (er) { f.querySelector('.ue-e').innerHTML = errBox(er.message); } }; }); }; });
      }
      function drawMix() {   // the utility twin of the chosen message, linked automatically, and the switch
        const box = $(m.body, '#cuMx'); if (!box) return; const t = tpl();
        box.innerHTML = ''; W.mix = false; return;   // replaced by the owner's utility share (drawUt)
        if (!t || t.is_twin || t.category !== 'MARKETING') { box.innerHTML = ''; return; }
        const tw = t.twins || [], L = tw[tw.length - 1], us = t.utility_state || 'none', st = UST[us] || UST.none;
        const live = tw.slice().reverse().find((x) => x.status === 'approved' || x.status === 'partial') || L;
        box.innerHTML = `<div class="cu-sec">4 Utility version <small>(cheaper, and Meta likes it - goes only to people who asked or ordered lately)</small></div>
          <div class="wmx-sw ${W.mix ? '' : 'off'}"><label><input type="checkbox" id="wmxOn" ${W.mix ? 'checked' : ''}><span>Mix in the utility version when approved<small>${W.mix ? 'Marketing goes to everyone now. The moment the utility version is approved on a number, that number starts mixing it in - no restart. It goes only to customers who asked about a perfume or ordered in the last 30 days, never to anyone who opted out.' : 'Off - everyone gets the marketing message above.'}</small></span></label></div>
          ${L ? `<div class="wmx-twin"><b>Linked utility version:</b> ${esc(String(live.name).replace(/_/g, ' '))} <span class="wmx-pill ${st[1]}">${st[0]}</span>${chipsHtml(live.matrix)}${us === 'approved' ? '<div class="wp-hint" style="margin:3px 0 0">Approved - it starts mixing in as soon as you press Start.</div>' : (us === 'waiting' ? '<div class="wp-hint" style="margin:3px 0 0">Meta has not answered yet. The campaign can start now with the marketing message; the utility version joins by itself when it is approved.</div>' : (us === 'relabelled' ? '<div class="wp-hint" style="margin:3px 0 0">Meta re-labelled the first utility version as marketing, so a stricter one was sent for approval. Until it is approved everyone gets the marketing message.</div>' : ''))}</div>`
            : '<div class="wmx-twin"><b>No utility version yet.</b> Claude makes one automatically for every marketing message. You can start now - everyone gets the marketing message until the utility version is approved.</div>'}`;
        const sw2 = $(box, '#wmxOn'); sw2.onchange = () => { W.mix = sw2.checked; box.querySelector('.wmx-sw').classList.toggle('off', !W.mix); box.querySelector('.wmx-sw small').textContent = W.mix ? 'On - the utility version joins by itself when it is approved.' : 'Off - everyone gets the marketing message above.'; };
      }
      function drawExtra() {
        const t = tpl(), box = $(m.body, '#cuEx'); if (!t) { box.innerHTML = ''; return; } const E = W.extra, bt = t.buttons || [], need = [];
        if (bt.some((b) => b.type === 'COPY_CODE') && !W.specs.some((s) => s.src === 'coupon')) need.push(`<label class="wp-fl">Coupon code on the “copy code” button</label><input class="wp-in" data-e="coupon" maxlength="40" value="${esc(E.coupon || '')}" placeholder="e.g. DIWALI20">`);
        if (bt.some((b) => b.type === 'URL' && /\{\{/.test(b.url || ''))) need.push(`<label class="wp-fl">Link ending <small>(the part that goes after the fixed link)</small></label><input class="wp-in" data-e="url_suffix" maxlength="120" value="${esc(E.url_suffix || '')}" placeholder="e.g. diwali-offer">`);
        if (t.header_format === 'LOCATION') need.push(`<label class="wp-fl">Place shown on top <small>(leave empty to use the one saved in the message)</small></label><div class="wp-2"><input class="wp-in" data-e="lat" inputmode="decimal" placeholder="Latitude" value="${esc(E.lat != null ? E.lat : '')}"><input class="wp-in" data-e="lng" inputmode="decimal" placeholder="Longitude" value="${esc(E.lng != null ? E.lng : '')}"></div><input class="wp-in" data-e="name" style="margin-top:8px" maxlength="100" placeholder="Name of the place" value="${esc(E.name || '')}"><input class="wp-in" data-e="address" style="margin-top:8px" maxlength="200" placeholder="Address" value="${esc(E.address || '')}">`);
        if (t.type === 'LTO') need.push(`<label class="wp-fl">Offer ends after <small>(hours from the moment each message is sent)</small></label><input class="wp-in" data-e="expires_hours" type="number" min="1" max="720" value="${esc(E.expires_hours || 48)}">`);
        box.innerHTML = need.join('');
        box.querySelectorAll('[data-e]').forEach((n) => { n.oninput = () => { const k = n.dataset.e; if (n.value.trim() === '') delete E[k]; else E[k] = n.value; drawPh(); }; });
      }
      function drawPh() {
        const t = tpl(), box = $(m.body, '#cuPh'); if (!box) return; if (!t) { box.innerHTML = '<div class="cu-pvh">Preview · as the customer sees it</div><div class="cu-pvb"><div class="wp-hint">Choose a message to see it here.</div></div>'; return; }
        const vals = W.specs.map((s, i) => sample(s, t, i + 1, X.pcsOn, X.lines)), body = fill(t.body, vals), hf = W.media ? { image: 'IMAGE', video: 'VIDEO', document: 'DOCUMENT' }[W.media.kind] : t.header_format;
        const head = hf === 'IMAGE' ? '<div class="cu-pim" id="cuPim">🖼</div>' : (hf === 'VIDEO' ? '<div class="cu-pim">🎬</div>' : (hf === 'DOCUMENT' ? '<div class="cu-pim doc">📄 PDF</div>' : (hf === 'LOCATION' ? '<div class="cu-pim loc">📍</div>' : '')));
        box.innerHTML = `<div class="cu-pvh">Preview · as the customer sees it</div><div class="cu-pvb">${head}<div class="cu-pvt">${bold(body)}</div>${t.footer ? `<div class="cu-pvf">${esc(t.footer)}</div>` : ''}${(t.buttons || []).map((b) => `<div class="cu-pvx">${{ URL: '🌐', PHONE_NUMBER: '📞', COPY_CODE: '📋', QUICK_REPLY: '💬' }[b.type] || ''} ${esc(b.text || '')}</div>`).join('')}</div>`;
        if (hf === 'IMAGE' && W.media) { const n = $(box, '#cuPim'); n.textContent = ''; thumb(W.media.id, n); }
      }

      // ---------------------------------------------------------------- step 3: when & retries
      // 💸 step 2 of the utility follow-up: the 6 standard messages (status on each number), how far back orders count, the offer that goes free after a reply
      function drawU2() {
        const E = W.extra; m.body.innerHTML = head() + loading; bind();
        const ST = { approved: ['✅ Approved', 'ok'], pending: ['⏳ Waiting for Meta', 'w'], in_appeal: ['⏳ Waiting', 'w'], rejected: ['❌ Rejected', 'r'], paused: ['⏸ Paused', 'r'], error: ['⚠ Will retry', 'w'], 'not sent': ['· not sent yet', 'w'] };
        const show = (L) => {
          if (step !== 2 || !m.el.isConnected) return; const ok = L.filter((u) => u.exists && !u.relabelled && (u.per || []).some((p) => p.status === 'approved')), miss = L.filter((u) => !u.exists);
          const hl = (b) => esc(b).replace(/\{\{(\d)\}\}/g, (x, n) => `<b class="cu-v">{{${n}}}</b>`);
          m.body.innerHTML = head() + `<div class="cu-guard" style="background:#ecfdf5;border-color:#a7f3d0;color:#065f46">💸 Only people who <b>asked about a perfume in the last 30 days</b> or <b>ordered in the window below</b> get a message — each with <b>their own</b> perfume / order and date. Everyone else on your list is left out (no money spent). When someone replies, the 24-hour chat opens and your offer goes <b>free</b>.</div>
            <div class="cu-sec">Orders from the last</div><div class="cu-pcts">${[30, 60, 90, 120].map((d) => `<button type="button" data-od="${d}" class="${+E.ord_days === d ? 'on' : ''}">${d} days</button>`).join('')}</div>
            <div class="wp-hint">Start with 60. Older orders = more risk that Meta calls it marketing (the safety rule then pauses the campaign).</div>
            <div class="cu-sec">🎁 Offer sent free when they reply <small>(optional — a normal chat message, once per person)</small></div>
            <textarea class="wp-in" id="cuOf" rows="3" maxlength="900" placeholder="e.g. 🎉 Mega Diwali offer: any 3 × 50 ml for ₹1500 — only till Sunday. Shall I add {name}'s favourite + 2 more?">${esc(E.offer || '')}</textarea><div class="wp-hint">{name} = the customer's first name.</div>
            <div class="cu-sec">The utility follow-up messages <small>(${ok.length} of ${L.length} ready — they take turns)</small></div>
            ${L.map((u) => `<div class="cu-uv ${u.relabelled ? 'off' : ''}"><div style="font-size:11px;font-weight:800;color:#64748b;margin-bottom:4px">${u.kind === 'order' ? '📦 ORDER FOLLOW-UP' : '💬 ENQUIRY FOLLOW-UP'}</div><div class="cu-ub">${hl(u.body)}</div>
              ${(u.buttons || []).length ? `<div class="cu-chips">${u.buttons.map((b) => `<span>↩ ${esc(b)}</span>`).join('')}</div>` : ''}
              <div class="cu-vars">${(u.vars || []).map((v) => `<span><b>{{${v.n}}}</b> = ${esc(v.what)}</span>`).join('')}</div>
              <div class="cu-chips">${!u.exists ? '<span class="w">not created yet</span>' : u.relabelled ? '<span class="r">🔁 Meta moved it to marketing — not used</span>' : (u.per || []).map((p) => { const z = ST[p.status] || [esc(p.status), 'w']; return `<span class="${z[1]}">${esc(p.label)}: ${z[0]}</span>`; }).join('')}</div></div>`).join('')}
            ${miss.length ? `<div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn p" id="cuPm">✨ Create the ${miss.length} message${miss.length === 1 ? '' : 's'} and send for approval</button></div>` : ''}
            ${!ok.length ? '<div class="wp-note" style="margin-top:8px">None is approved by Meta yet — Meta usually answers within minutes to a few hours. Come back then to set up and start this campaign.</div>' : ''}
            <div id="cuE"></div><div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn" id="cuBack">‹ Back</button><button type="button" class="wp-btn p" id="cuNext">Next ›</button></div>`;
          bind();
          m.body.querySelectorAll('[data-od]').forEach((b) => { b.onclick = () => { E.ord_days = +b.dataset.od; E.offer = $(m.body, '#cuOf').value; show(L); }; });
          $(m.body, '#cuOf').oninput = function () { E.offer = this.value; };
          const pm = $(m.body, '#cuPm'); if (pm) pm.onclick = async () => { pm.disabled = true; pm.textContent = 'Creating and sending to Meta…'; try { const d = await api.post('wa.util_pool_make', {}); X.pool = d.items || []; toast('Sent to Meta for approval ✓'); show(X.pool); } catch (e) { toast(e.message, true); pm.disabled = false; } };
          $(m.body, '#cuBack').onclick = () => { E.offer = $(m.body, '#cuOf').value; step = 1; go(); };
          $(m.body, '#cuNext').onclick = () => { E.offer = $(m.body, '#cuOf').value; if (!ok.length) { $(m.body, '#cuE').innerHTML = errBox(L.some((u) => u.exists) ? 'Wait until Meta approves at least one of these messages (minutes to a few hours).' : 'Create the utility follow-up messages first.'); return; } step = 3; go(); };
        };
        if (X.pool) show(X.pool); else api.get('wa.util_pool').then((d) => { X.pool = d.items || []; show(X.pool); }).catch((e) => { m.body.innerHTML = head() + errBox(e.message); });
      }
      function startAt() {
        const now = new Date(), pad = (n) => String(n).padStart(2, '0'), iso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        if (W.start === 'today19') { const d = new Date(); d.setHours(19, 0, 0, 0); return iso(d); } if (W.start === 'tom11') { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(11, 0, 0, 0); return iso(d); } if (W.start === 'custom') return W.start_at; return '';
      }
      function histDraw() {
        const h = X.hours, box = $(m.body, '#cuAih'); if (!box) return; if (!h) { box.innerHTML = '<div class="wp-hint">Looking at when your customers write…</div>'; return; }
        const win = h.window || [0, 24], mx = Math.max(1, ...h.hist), best = new Set(h.best || []), top = (h.best || [])[0];
        box.innerHTML = `<div class="cu-ai"><b>✨ Claude suggests</b><br>${h.total ? `Your customers write to you most at <b>${esc(hourText(h.best))}</b>. ` : 'There are not enough chats yet to see a pattern. '}${W.own ? 'I will send to each person at <b>their own usual hour</b> (people with fewer than 5 messages get ' + H(h.default_hour) + ').' : 'Everyone gets it at the same time.'} Nothing goes out between <b>${H(win[1])} and ${H(win[0])}</b>.</div>
          <div class="cu-hist">${h.hist.map((n, i) => `<div title="${H(i)}: ${fmt(n)} messages"><i class="${best.has(i) ? (i === top ? 'b' : 'm') : (i < win[0] || i >= win[1] ? '' : 'o')}" style="height:${Math.max(3, Math.round(n * 100 / mx))}%"></i></div>`).join('')}</div><div class="cu-hl">${h.hist.map((n, i) => `<span>${i % 3 === 0 || window.innerWidth > 640 ? i : ''}</span>`).join('')}</div><div class="wp-hint">When customers wrote to you, by hour (last ${h.days || 30} days).</div>`;
      }
      function draw3() {
        const p = pushOf(), E = ['Try 1|at the send time', 'Try 2|+ 3 hours', 'Try 3|next day, their hour'].slice(0, Math.max(1, Math.min(3, +W.extra.retries || 3)));
        m.body.innerHTML = head() + `<div id="cuAih"></div>
          <div class="cu-2"><div><div class="cu-sec">How pushy</div><div class="cu-chips">${PUSH.map(([k, l]) => `<button type="button" class="wp-chip ${W.push === k ? 'on' : ''}" data-p="${k}">${l}</button>`).join('')}</div><div class="wp-hint" id="cuPh2">${esc(p[1] === 'A little aggressive' ? 'A little aggressive' : p[1])}: people who got a campaign are free again after ${p[2]} day${p[2] === 1 ? '' : 's'} (Careful: 5, Normal: 3, Very: 1). Speed up to ${Math.min(1500, W.rate * p[3])} a minute.</div></div>
          <div><div class="cu-sec">Start</div><div class="cu-chips">${[['now', 'Now'], ['today19', 'Today 7 pm'], ['tom11', 'Tomorrow 11 am'], ['custom', 'Pick a time']].filter(([k]) => k !== 'today19' || new Date().getHours() < 19).map(([k, l]) => `<button type="button" class="wp-chip ${W.start === k ? 'on' : ''}" data-st="${k}">${l}</button>`).join('')}</div>${W.start === 'custom' ? `<input class="wp-in" id="cuSa" type="datetime-local" style="margin-top:8px" value="${esc(W.start_at)}">` : ''}</div></div>
          ${(() => { const NS = (D.numbers || []), can = (n) => n.status === 'CONNECTED' && n.camp_ok, sel0 = (W.extra.numbers && W.extra.numbers.length) ? W.extra.numbers : NS.filter(can).map((n) => n.id);
            W.extra.numbers = sel0.filter((id) => NS.some((n) => n.id === id && can(n)));
            return `<div class="cu-sec">Send from <small>(only connected numbers can be chosen)</small></div><div class="cu-chips" id="cuNums">${NS.map((n) => { const ok = can(n), on = ok && W.extra.numbers.includes(n.id), why = n.status && n.status !== 'CONNECTED' ? (n.status === 'PENDING' ? 'Pending at Meta' : 'Not connected (' + n.status.toLowerCase() + ')') : (!n.camp_ok ? 'Switched off for campaigns' : '');
              return `<button type="button" class="wp-chip ${on ? 'on' : ''}" data-num="${n.id}" ${ok ? '' : 'disabled title="' + esc(why) + '" style="opacity:.5;cursor:not-allowed"'}>${on ? '✓ ' : ''}${esc(n.label)}${n.display ? ' · ' + esc(n.display) : ''}${ok ? ` <small>${n.quality === 'GREEN' ? 'High' : n.quality === 'YELLOW' ? 'Medium' : n.quality === 'RED' ? 'Low' : ''} · ${fmt(n.left)} left</small>` : ` <small>— ${esc(why)}</small>`}</button>`; }).join('')}</div>${W.extra.numbers.length ? '' : '<div class="wp-note">No connected number is chosen — the campaign cannot send.</div>'}
            ${W.extra.numbers.length >= 2 ? `<div class="wp-hint" style="margin:6px 0 2px">Share per number <small>(how the messages are split between them)</small></div><div class="wp-row" id="cuNw" style="gap:8px">${W.extra.numbers.map((id) => { const n = NS.find((x) => x.id === id) || { label: '#' + id }; const v = (W.extra.num_w || {})[id] != null ? W.extra.num_w[id] : Math.round(100 / W.extra.numbers.length); W.extra.num_w = W.extra.num_w || {}; W.extra.num_w[id] = v; return `<label style="display:flex;align-items:center;gap:4px;font-size:13px">${esc(n.label)} <input class="wp-in" type="number" min="0" max="100" step="5" data-nw="${id}" value="${v}" style="width:70px;padding:4px 6px"> %</label>`; }).join('')}</div>` : ''}`; })()}
          <div class="cu-sec">Send time</div><div class="cu-chips"><button type="button" class="wp-chip ${!W.own ? 'on' : ''}" data-ow="0">⚡ Everyone ${W.start === 'now' ? 'right now' : 'at the start time'}</button><button type="button" class="wp-chip ${W.own ? 'on' : ''}" data-ow="1">🕐 Each person at their best hour</button></div>
          <div class="wp-hint">${W.own ? 'Each customer gets it at the hour they usually write to you — the plan below shows when. Later you can still press ⚡ Send now on the campaign.' : 'Everyone gets it together, starting ' + (W.start === 'now' ? 'now' : 'at the start time') + '.'}</div><div id="cuOwnPlan"></div>
          <div class="cu-rt"><div class="cu-rh"><b>🔁 Leave out customers who got a follow-up in the last day</b>${sw('cuLfu', W.extra.leave_fu !== 0)}</div><div class="wp-hint">A customer the Follow-ups (Autopilot or Journey) messaged in the last 24 hours is skipped in this campaign, so nobody gets two messages in a day. Also, a campaign pauses that customer’s follow-ups for 2 days.</div></div>
          <div class="cu-rt"><div class="cu-rh"><b>🔁 Auto retry if it fails to deliver</b>${sw('cuRt', W.retry)}</div><div class="wp-hint">Same message again, up to <b>${Math.max(1, Math.min(3, +W.extra.retries || 3))} ${(+W.extra.retries || 3) === 1 ? 'try' : 'tries'}</b> in all. Meta charges only when a message is delivered, so a failed try costs nothing.</div>
            <div class="cu-tr">${E.map((x) => { const [a, b] = x.split('|'); return `<div><span>${a}</span><b>${b}</b></div>`; }).join('')}</div><div class="wp-hint">Not retried: opted out · number not on WhatsApp (after the 2nd try) · Meta spam block (the campaign pauses).</div></div>
          <details class="cu-more"><summary>More settings</summary><div class="wp-2" style="margin-top:8px"><div><label class="wp-fl" style="margin-top:0">Base speed (messages a minute)</label><input class="wp-in" id="cuRate" type="number" min="1" max="1500" value="${W.rate}"></div><div><label class="wp-fl" style="margin-top:0">Finish by <small>(optional)</small></label><input class="wp-in" id="cuWin" type="datetime-local" value="${esc(W.win_end)}"></div></div>
            <label class="wp-fl">Switch number after this many ignored messages</label><input class="wp-in" id="cuSw" type="number" min="1" max="5" value="${W.sw}"><div class="wp-hint">If a customer wrote to number X, we keep using X. If a number’s last messages were ignored, another number is used. Someone who ignored every number is skipped.</div></details>
          <div id="cuSpd"></div><div id="cuPv" style="margin-top:10px"></div>${W.A.src !== 'contacts' ? '<label class="cu-cons"><input type="checkbox" id="cuCons"> These customers agreed to receive WhatsApp messages from us.</label>' : ''}
          <div id="cuE"></div><div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn" id="cuBack">‹ Back</button><button type="button" class="wp-btn" id="cuDraft">Save as draft</button><button type="button" class="wp-btn p" id="cuGo">Start campaign</button></div>`;
        if (window.WaSmart && $(m.body, '#cuSpd')) {   // speed estimate and retries (wa.camp_speed)
          const SP = { careful: 'gentle', normal: 'normal', aggressive: 'fast', very: 'fast' }, BACK = { gentle: 'careful', normal: 'normal', fast: 'aggressive' };
          WaSmart.speedBox($(m.body, '#cuSpd'), { speed: SP[W.push] || 'normal', retries: +W.extra.retries || 3, size: () => (pv && pv.stats ? pv.stats.send : (c && c.counts ? c.counts.total : 0)), id: () => (pv && pv.stats ? 0 : (W.id || 0)),
            onChange: (v) => { read3(); if (SP[W.push] !== v.speed) W.push = BACK[v.speed]; W.extra.retries = v.retries; draw3(); } });
        }
        bind(); histDraw(); if (!X.hours) api.get('wa.camp_best_time').then((d) => { X.hours = d; if (step === 3 && m.el.isConnected) histDraw(); }).catch(() => { X.hours = { hist: new Array(24).fill(0), best: [], total: 0, window: [0, 24], default_hour: 19 }; histDraw(); });
        m.body.querySelectorAll('[data-p]').forEach((b) => { b.onclick = () => { read3(); W.push = b.dataset.p; draw3(); }; });
        m.body.querySelectorAll('[data-st]').forEach((b) => { b.onclick = () => { read3(); W.start = b.dataset.st; if (W.start === 'custom' && !W.start_at) W.start_at = startAt(); draw3(); }; });
        m.body.querySelectorAll('#cuNw [data-nw]').forEach((x) => { x.oninput = () => { W.extra.num_w = W.extra.num_w || {}; W.extra.num_w[x.dataset.nw] = Math.max(0, Math.min(100, +x.value || 0)); }; });
        m.body.querySelectorAll('#cuNums [data-num]:not([disabled])').forEach((b) => { b.onclick = () => { read3(); const id = +b.dataset.num, s0 = new Set(W.extra.numbers || []); if (s0.has(id)) s0.delete(id); else s0.add(id); W.extra.numbers = [...s0]; draw3(); }; });
        m.body.querySelectorAll('[data-ow]').forEach((b) => { b.onclick = () => { read3(); W.own = b.dataset.ow === '1'; draw3(); }; });
        $(m.body, '#cuBack').onclick = () => { read3(); step = 2; go(); };
        (async () => { await preview3(); })();
        const save = async (start) => {
          read3(); const b = $(m.body, start ? '#cuGo' : '#cuDraft'); b.disabled = true; const t = tpl();
          try {
            const r = await api.post('wa.camp_save', { kind: W.util ? 'util' : '', id: W.id, name: W.name, tpl_id: W.tpl_id, vars: W.specs.map((s) => ({ src: s.src, val: s.val, slot: s.slot })), audience: W.A, rate: W.rate, win_end: W.win_end, start_at: startAt(), push: W.push, own_hour: W.own, def_hour: 19, retry: W.retry, media_lib_id: W.media ? W.media.id : 0, media_folder: W.mfolder, extra: W.extra, switch_after: W.sw, mix: W.mix, tpl_u: (tpl() && (tpl().twins || []).length) ? tpl().twins[tpl().twins.length - 1].id : 0 }); W.id = r.id;
            if (start) { if (W.A.src !== 'contacts' && !W.consent) throw new Error('Tick the box to confirm these customers agreed to receive WhatsApp messages from you.'); const s = await api.post('wa.camp_start', { id: W.id, consent: W.consent }); toast(s.status === 'scheduled' ? 'Scheduled ✓' : 'Started — ' + fmt(s.queued) + ' customers queued ✓'); } else toast('Draft saved ✓');
            m.close(); load();
          } catch (e) { $(m.body, '#cuE').innerHTML = errBox(e.message); b.disabled = false; if (start && W.id) load(true); }
        };
        $(m.body, '#cuDraft').onclick = () => save(false);
        $(m.body, '#cuGo').onclick = () => { if (!(W.extra.numbers || []).length) { $(m.body, '#cuE').innerHTML = errBox('Choose at least one connected number in “Send from”.'); return; } const w = W.util && pv && pv.util_split ? fmt(pv.util_split.ask + pv.util_split.order) + ' customers who qualify (the rest are left out)' : (pv && pv.stats ? fmt(pv.stats.send) + ' customers' : 'real customers'); if (!confirm('Start this campaign? Messages go out to ' + w + (W.own ? ' — each at their best hour (see the plan).' : (W.start === 'now' ? ' — starting right now.' : ' — together at the start time.')))) return; save(true); };
      }
      function read3() {
        const r = $(m.body, '#cuRate'); if (r) W.rate = Math.max(1, Math.min(1500, +r.value || 60)); const w = $(m.body, '#cuWin'); if (w) W.win_end = w.value; const s = $(m.body, '#cuSa'); if (s) W.start_at = s.value; const x = $(m.body, '#cuSw'); if (x) W.sw = Math.max(1, Math.min(5, +x.value || 2));
        const rt = $(m.body, '#cuRt'); if (rt) W.retry = rt.checked; const lf = $(m.body, '#cuLfu'); if (lf) W.extra.leave_fu = lf.checked ? 1 : 0; const cs = $(m.body, '#cuCons'); if (cs) W.consent = cs.checked;
      }
      async function preview3() {
        await preview(); const box = $(m.body, '#cuPv'); if (!box || !pv || !pv.stats) return; const s = pv.stats; if (!s) return;
        const when3 = W.own ? 'sent over the next day at each person’s own hour' : (pv.minutes ? 'all sent in about ' + (pv.minutes >= 90 ? Math.round(pv.minutes / 60) + ' hours' : pv.minutes + ' minutes') + ' (' + fmt(pv.per_minute) + ' a minute)' : 'sent right away');
        const op = pv.own_plan, ob = $(m.body, '#cuOwnPlan');
        if (ob) ob.innerHTML = W.own && op ? `<div class="cu-ai"><b>🕐 Best-hour plan</b><br>${[['soon', 'in the next hour'], ['today', 'later today'], ['tomorrow', 'tomorrow'], ['later', 'after tomorrow']].filter(([k]) => op[k]).map(([k, l]) => '<b>' + fmt(op[k]) + '</b> ' + l).join(' · ') || 'Nobody'}${op.first ? '<br>First at ' + esc(when(op.first)) + (op.last && op.last !== op.first ? ', last at ' + esc(when(op.last)) : '') : ''}<div class="wp-row" style="margin-top:6px"><button type="button" class="wp-btn s" id="cuAllNow">⚡ Send to everyone now instead</button></div></div>` : '';
        const an = $(m.body, '#cuAllNow'); if (an) an.onclick = () => { read3(); W.own = false; draw3(); };
        const old = box.querySelector('.wp-ok'); if (old) old.innerHTML = `<b>${fmt(s.send)} customers</b> · estimated cost <b>${money(pv.cost)}</b> · room today <b>${fmt(pv.room_today)}</b> · ${esc(when3)}`;
        const mx = pv.mix; if (mx && W.mix && tpl() && tpl().category === 'MARKETING') box.insertAdjacentHTML('beforeend', mx.has_twin ? `<div class="wmx-est"><b>Utility mix:</b> about ${fmt(mx.eligible)} of these customers asked about a perfume or ordered in the last ${mx.days} days and can get the utility version. It starts at about ${mx.share_now}% per number (roughly ${fmt(mx.est_utility)} messages, saving about ${money(mx.est_saving)}) and rises step by step while the sends stay clean.${pv.cost_with_mix != null ? ' Estimated cost with the mix: <b>' + money(pv.cost_with_mix) + '</b>.' : ''}</div>` : '<div class="wmx-est" style="background:#f1f5f9;color:#475569"><b>Utility mix:</b> no utility version yet. Everyone gets the marketing message; the utility version joins by itself once Meta approves it.</div>');
        const us = pv.util_split; if (W.util && us) { const q = us.ask + us.order; box.innerHTML = `<div class="wp-ok"><b>${fmt(q)} of ${fmt(us.ask + us.order + us.none)}</b> can get a utility follow-up<div class="wp-hint" style="color:inherit;opacity:.85">💬 ${fmt(us.ask)} asked in the last 30 days · 📦 ${fmt(us.order)} ordered in the last ${W.extra.ord_days || 60} days · ⛔ ${fmt(us.none)} left out (no money spent)</div><div class="wp-hint" style="color:inherit;opacity:.85">About ${money(q * (pv.rate_u || 0))} as utility · the same people as marketing ≈ ${money(q * (pv.rate || 0))}. A few may still be left out when sending (no perfume name found).</div></div>`; }
        const uo = pv.util_ok; if (uo && !W.util && tpl() && tpl().category === 'MARKETING') { const E2 = W.extra || {}, can = uo.ask + uo.order, tot = can + uo.none, p2 = E2.u_off ? 0 : (+E2.u_pct || 0), want = Math.round(s.send * p2 / 100), got = Math.min(want, can);
          box.insertAdjacentHTML('beforeend', `<div class="cu-uv" style="margin-top:8px"><b>💸 Utility / marketing</b><div class="wp-hint" style="margin:2px 0 0">${p2 ? `You chose <b>${p2}% utility</b>. <b>${fmt(can)} of ${fmt(tot)}</b> wrote or ordered in the last 30 days and can get utility → about <b>${fmt(got)} utility · ${fmt(Math.max(0, s.send - got))} marketing</b>.${got < want ? ' (Meta allows utility only for them, so the rest go as marketing.)' : ''}` : '<b>Utility OFF</b> — all marketing.'}</div></div>`); }
        const bn = pv.by_num; if (bn && bn.numbers && bn.numbers.length) box.insertAdjacentHTML('beforeend', `<div class="cu-uv" style="margin-top:8px"><b>📱 Which number sends</b>${bn.numbers.map((n) => `<div class="wp-kv"><span>${esc(n.label)} <small>(they wrote to it)</small></span><b>${fmt(n.n)}</b></div>`).join('')}${bn.switched ? `<div class="wp-kv"><span>Switched to the other number <small>(ignored 2 messages)</small></span><b>${fmt(bn.switched)}</b></div>` : ''}${bn.new ? `<div class="wp-kv"><span>Never wrote to us <small>(shared by room)</small></span><b>${fmt(bn.new)}</b></div>` : ''}</div>`);
        box.insertAdjacentHTML('beforeend', pv.warnings.map((x) => `<div class="wp-note">${esc(x)}</div>`).join(''));
      }
      draw1();
    }
    load(false); return () => load(false);
  }
  WaPanels.add('campaigns', screen);
})();
