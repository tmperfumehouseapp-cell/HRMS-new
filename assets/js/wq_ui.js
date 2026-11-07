/**
 * wq_ui.js — WhatsApp Sales ("wq" module): the pieces shared by the phone app, My Sales, the chat details and the admin page.
 *   WaQ.mineCard(el, { api, onOpen })   the salesperson's "My WhatsApp" card for my_sales.html (type chips, progress bars toward goals, waiting, transfers).
 *                                       Hides itself (el stays empty and hidden) on any error or when the server says on:false.
 *   WaQ.myQueries(el, { api, onOpen })  full-screen "My queries" list, WhatsApp look (type pills, filter by type, search). Tap a row = open the chat.
 *   WaQ.tags(el, { phone, api })        the query type + tags + "Regenerated after N days" + holder + Transfer + history block of the chat details.
 *   WaQ.morning(el, { api })            the "WhatsApp Sales · yesterday" card for the Morning section.
 *   WaQ.u                               helpers the admin screens (wq_admin.js) reuse: esc, fmtMin, money, pill, sheet, toast, ...
 * Server side: empmanagment/wq.php (actions wq.*). Styles carry the prefix wq- and are injected here, so a page works without wa_ui.css.
 * Phone first (390 px), touch targets >= 40 px, light + dark (html.wxdk in the phone app, html.wqdk on the admin page). English UI.
 */
(function () {
  'use strict';
  if (window.WaQ && window.WaQ.v) return;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (n, d) => { n = Number(n); return isFinite(n) ? n : (d == null ? 0 : d); };
  const has = (v) => v !== null && v !== undefined && v !== '' && isFinite(Number(v));
  const money = (n) => '₹' + Math.round(num(n)).toLocaleString('en-IN');
  const pct = (n, dec) => has(n) ? (dec === 0 ? Math.round(Number(n)) : Number(Number(n).toFixed(dec == null ? 0 : dec))) + '%' : '-';
  const parse = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null; };
  const hhmm = (d) => d ? d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).replace(' ', ' ').toLowerCase().replace(' ', ' ') : '';
  const dayName = (d) => d ? d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }).replace(',', '') : '';
  const sameDay = (a, b) => a.toDateString() === b.toDateString();
  const when = (s) => { const d = parse(s); if (!d) return ''; const t = new Date(); return sameDay(d, t) ? 'today ' + hhmm(d) : dayName(d) + ', ' + hhmm(d); };
  const clock = (s) => { const d = parse(s); return d ? hhmm(d) : ''; };
  // minutes (may be a fraction) -> "6 s", "1m 20s", "9m 30s", "1h 05m"
  const fmtMin = (m) => {
    if (!has(m)) return '-'; m = Number(m); if (m < 0) m = 0; const sec = Math.round(m * 60);
    if (sec < 60) return sec + ' s'; if (sec < 3600) { const mm = Math.floor(sec / 60), ss = sec % 60; return ss ? mm + 'm ' + String(ss).padStart(2, '0') + 's' : mm + 'm'; }
    const h = Math.floor(sec / 3600), mm = Math.floor((sec % 3600) / 60); return h + 'h ' + String(mm).padStart(2, '0') + 'm';
  };
  const cut = (t, n) => { t = String(t || ''); if (t.length <= n) return t; const k = t.lastIndexOf(' ', n); return t.slice(0, k > n * .6 ? k : n).replace(/[\s,.;:]+$/, '') + '\u2026'; };
  const fmtHrs = (h) => { if (!has(h)) return '-'; const t = Math.round(Number(h) * 60); return t <= 0 ? '-' : Math.floor(t / 60) + 'h ' + String(t % 60).padStart(2, '0') + 'm'; };
  const initials = (n, p) => { const w = String(n || '').trim().split(/\s+/).filter(Boolean); return (w.length ? (w[0][0] + (w[1] ? w[1][0] : '')) : String(p || '').slice(-2)).toUpperCase(); };
  const hue = (s) => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) % 10; return h; };
  const pretty = (p) => { p = String(p || ''); return p.length === 10 ? '+91 ' + p.slice(0, 5) + ' ' + p.slice(5) : p; };
  const masked = (p) => { p = String(p || ''); return p.length === 10 ? '+91 ' + p.slice(0, 2) + '••• ' + p.slice(6) : p; };
  const TYPES = [['meta', 'Meta', 'meta'], ['campaign', 'Campaign', 'camp'], ['community', 'Community', 'comm'], ['regenerated', 'Regenerated', 'regen'], ['new', 'New', 'new'], ['other', 'Other', 'oth']];
  const TINFO = {}; TYPES.forEach((t) => { TINFO[t[0]] = { key: t[0], label: t[1], cls: t[2] }; }); TINFO.unclear = { key: 'unclear', label: 'To decide', cls: 'oth' };
  const tinfo = (k) => TINFO[k] || { key: k || 'other', label: k ? String(k).charAt(0).toUpperCase() + String(k).slice(1) : 'Other', cls: 'oth' };
  const pill = (k, label, big) => { const t = tinfo(k); return '<span class="wq-tp ' + t.cls + (big ? ' lg' : '') + '">' + esc(label || t.label) + '</span>'; };
  const DUTY = { on: ['On duty', 'ok'], not_in: ['Not in yet', 'warn'], leave: ['On leave', 'mute'], wfh: ['Working from home', 'info'], off_shift: ['Off shift', 'mute'], after_shift: ['Shift is over', 'mute'], holiday: ['Holiday', 'mute'] };
  const dutyChip = (state, onDuty) => { const d = DUTY[state] || (onDuty ? DUTY.on : ['Off duty', 'mute']); return '<span class="wq-chip ' + d[1] + '">' + (d[1] === 'ok' ? '<i class="dot"></i>' : '') + esc(d[0]) + '</span>'; };
  const dutyWords = (state) => ({ leave: 'on leave', not_in: 'not punched in', off_shift: 'off shift', after_shift: 'shift is over', wfh: 'working from home', holiday: 'holiday' }[state] || '');

  const IC = {
    star: '<path d="M12 3l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.8 6.6 19.7l1.1-6.1L3.2 9.4l6.1-.8z"/>',
    moon: '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    users: '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c.4-3.4 3-5.4 6-5.4s5.6 2 6 5.4"/><path d="M16 5a3 3 0 0 1 0 6M18 14.8c1.8.7 2.8 2.4 3 5.2"/>',
    swap: '<path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    back: '<path d="M15 5l-7 7 7 7"/>',
    down: '<path d="M6 9l6 6 6-6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    spark: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
    chat: '<path d="M4 5h16v11H9l-5 4z"/>',
    bot: '<rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01M9 16.5h6"/>',
    trophy: '<path d="M8 4h8v5a4 4 0 0 1-8 0zM8 6H4v1a3 3 0 0 0 4 2.8M16 6h4v1a3 3 0 0 1-4 2.8M12 13v4M8 20h8"/>',
    warn: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
    cal: '<rect x="4" y="5" width="16" height="15" rx="3"/><path d="M4 10h16M9 3v4M15 3v4"/>',
    list: '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
  };
  const ico = (n, cls) => '<svg class="wq-i ' + (cls || '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (IC[n] || '') + '</svg>';

  // ------------------------------------------------------------------ styles
  const CSS = `
.wq,.wq-ov{--q-ink:#14161a;--q-mut:#6f7480;--q-line:#e6e8ec;--q-bg:#eef0f3;--q-card:#fff;--q-soft:#f6f7f9;--q-acc:#ff7a2f;--q-faint:#94a3b8;--q-btn:#14161a;--q-btnfg:#fff;--q-track:#e2e8f0;
--q-okbg:#e4f6ec;--q-okfg:#16945a;--q-badbg:#fdecea;--q-badfg:#d9423b;--q-warnbg:#fff3dc;--q-warnfg:#9a6200;--q-infobg:#e8f0fe;--q-infofg:#2456b3;--q-mutebg:#f1f3f6;--q-mutefg:#3a3f4a;
--q-mb:#e8f0ff;--q-mf:#2563eb;--q-cb:#f0ebff;--q-cf:#5b3fd6;--q-ob:#fff0e0;--q-of:#b45309;--q-rb:#e7f9ee;--q-rf:#0f7a3a;--q-nb:#dff3f5;--q-nf:#0f6e7e;--q-xb:#eef1f6;--q-xf:#475569;
--q-cl1:#f4f0ff;--q-cl2:#eef6ff;--q-cll:#ddd6fe;--q-clfg:#2f2a55;--q-clb:#3b2a8a;--q-sel:#f1fbf5;--q-selline:#16a34a;--q-gold:#fff3c4;--q-goldfg:#7a5b00;--q-rowwait:#fff1f0;--q-aq:#fff8f7;--q-aqline:#f6d3d0;--q-chat:#efe7dd;--q-bubin:#fff;--q-bubout:#d9fdd3;--q-bubfg:#111b21;
font-family:"Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif;color:var(--q-ink);font-size:14px;line-height:1.45;box-sizing:border-box}
html.wxdk .wq,html.wxdk .wq-ov,html.wqdk .wq,html.wqdk .wq-ov{--q-ink:#e9edef;--q-mut:#8696a0;--q-line:#25343e;--q-bg:#0b141a;--q-card:#111b21;--q-soft:#162129;--q-faint:#6b7c88;--q-btn:#e9edef;--q-btnfg:#0b141a;--q-track:#2a3942;
--q-okbg:#0f3a2a;--q-okfg:#6ee7b0;--q-badbg:#47191c;--q-badfg:#ff9a9d;--q-warnbg:#3b3110;--q-warnfg:#f3d36b;--q-infobg:#15294a;--q-infofg:#8db6ff;--q-mutebg:#1f2c34;--q-mutefg:#c5ced4;
--q-mb:#15294a;--q-mf:#8db6ff;--q-cb:#2a2150;--q-cf:#c0adff;--q-ob:#412a10;--q-of:#fbbf6a;--q-rb:#0f3a2a;--q-rf:#6ee7b0;--q-nb:#0f3a40;--q-nf:#7fd6e2;--q-xb:#1f2c34;--q-xf:#c5ced4;
--q-cl1:#1b1736;--q-cl2:#14233a;--q-cll:#3a3068;--q-clfg:#dcd8f5;--q-clb:#b3a2ff;--q-sel:#0f2a20;--q-selline:#00a884;--q-gold:#3b3110;--q-goldfg:#f3d36b;--q-rowwait:#33181b;--q-aq:#2a1a1c;--q-aqline:#5b2a2d;--q-chat:#0b141a;--q-bubin:#202c33;--q-bubout:#005c4b;--q-bubfg:#e9edef}
.wq *,.wq-ov *{box-sizing:border-box}
.wq [hidden],.wq-ov [hidden]{display:none!important}
.wq button,.wq-ov button,.wq select,.wq input,.wq textarea{font-family:inherit;font-size:inherit;color:inherit}
.wq button,.wq-ov button{cursor:pointer;-webkit-tap-highlight-color:transparent}
.wq b,.wq strong{font-weight:700}
.wq-i{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:none;display:block}
.wq-i.s{width:14px;height:14px}
.wq .mute{color:var(--q-mut)} .wq .red{color:var(--q-badfg)} .wq .grn{color:var(--q-okfg)} .wq .num{font-variant-numeric:tabular-nums}
/* pills, chips, avatars */
.wq-tp{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;line-height:1.4;white-space:nowrap}
.wq-tp:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.85}
.wq-tp.meta{background:var(--q-mb);color:var(--q-mf)}.wq-tp.camp{background:var(--q-cb);color:var(--q-cf)}.wq-tp.comm{background:var(--q-ob);color:var(--q-of)}.wq-tp.regen{background:var(--q-rb);color:var(--q-rf)}.wq-tp.new{background:var(--q-nb);color:var(--q-nf)}.wq-tp.oth{background:var(--q-xb);color:var(--q-xf)}
.wq-tp.lg{font-size:13.5px;padding:6px 14px}
.wq-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700;background:var(--q-mutebg);color:var(--q-mutefg);white-space:nowrap;line-height:1.4;max-width:100%}
.wq-chip.ok{background:var(--q-okbg);color:var(--q-okfg)}.wq-chip.bad{background:var(--q-badbg);color:var(--q-badfg)}.wq-chip.warn{background:var(--q-warnbg);color:var(--q-warnfg)}.wq-chip.info{background:var(--q-infobg);color:var(--q-infofg)}.wq-chip.gold{background:var(--q-gold);color:var(--q-goldfg)}.wq-chip.mute{background:var(--q-mutebg);color:var(--q-mutefg)}.wq-chip.vio{background:var(--q-cb);color:var(--q-cf)}
.wq-chip .dot,.wq-chip i.dot{width:7px;height:7px;border-radius:50%;background:currentColor;flex:none;display:inline-block}
.wq-av{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:800;font-size:14px;flex:none}
.wq-av.s{width:32px;height:32px;font-size:12px}.wq-av.l{width:52px;height:52px;font-size:17px}
.wq-a0{background:#16a34a}.wq-a1{background:#0f6e7e}.wq-a2{background:#8b5cf6}.wq-a3{background:#e11d48}.wq-a4{background:#d97706}.wq-a5{background:#2563eb}.wq-a6{background:#0d9488}.wq-a7{background:#be185d}.wq-a8{background:#64748b}.wq-a9{background:#7c3aed}
/* buttons */
.wq .wq-btn,.wq-ov .wq-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 16px;min-height:44px;border-radius:99px;border:1px solid var(--q-btn);background:var(--q-card);color:var(--q-ink);font-weight:700;font-size:13.5px;line-height:1.1;white-space:nowrap;text-decoration:none}
.wq-btn.pri{background:var(--q-btn);color:var(--q-btnfg)}.wq-btn.go{background:#16945a;border-color:#16945a;color:#fff}.wq-btn.bad{border-color:var(--q-badfg);color:var(--q-badfg)}.wq-btn.soft{border-color:var(--q-line)}
.wq-btn.sm{min-height:40px;padding:6px 14px;font-size:13px}.wq-btn.block{width:100%}.wq-btn[disabled]{opacity:.45;cursor:default}
.wq-seg{display:inline-flex;border:1px solid var(--q-line);border-radius:99px;overflow:hidden;background:var(--q-card)}
.wq-seg button{border:0;background:transparent;min-height:40px;padding:0 16px;font-weight:700;font-size:13px;color:var(--q-mut)}
.wq-seg button.on{background:var(--q-btn);color:var(--q-btnfg)}
.wq-tabs{display:flex;gap:6px;margin:0 0 14px;flex-wrap:wrap}
.wq-tabs button{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--q-line);background:var(--q-card);border-radius:99px;padding:0 14px;min-height:44px;font-weight:700;font-size:13.5px;color:var(--q-mut)}
.wq-tabs button.on{background:var(--q-btn);color:var(--q-btnfg);border-color:var(--q-btn)}
.wq-tabs b{min-width:22px;height:22px;border-radius:99px;background:var(--q-badfg);color:#fff;font-size:12px;line-height:22px;text-align:center;padding:0 6px}
/* cards */
.wq-card{background:var(--q-card);border-radius:26px;padding:18px 20px;margin-bottom:14px;box-shadow:0 1px 2px rgba(20,22,26,.04),0 6px 18px -10px rgba(20,22,26,.12);min-width:0}
.wq-ch{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.wq-ch h2{font-size:16px;font-weight:800;margin:0;letter-spacing:-.01em;display:flex;align-items:center;gap:8px}
.wq-sub{font-size:12.5px;color:var(--q-mut)}
.wq-lb{font-size:11px;color:var(--q-mut);text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.wq-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}
.wq-stat{background:var(--q-card);border-radius:20px;padding:13px 15px;box-shadow:0 1px 2px rgba(20,22,26,.04);min-width:0}
.wq-stat small{font-size:12px;color:var(--q-mut);font-weight:600;display:block}
.wq-stat b{display:block;font-size:26px;font-weight:800;letter-spacing:-.02em;line-height:1.1;margin-top:3px;overflow-wrap:anywhere}
.wq-stat em{font-style:normal;font-size:11.5px;color:var(--q-mut);font-weight:600}
.wq-stat.bad b{color:var(--q-badfg)}.wq-stat.ok b{color:var(--q-okfg)}
.wq-empty{padding:26px 14px;text-align:center;color:var(--q-mut);line-height:1.6}.wq-empty b{display:block;color:var(--q-ink);font-size:15px;margin-bottom:3px}
.wq-err{border:1px solid var(--q-aqline);background:var(--q-aq);border-radius:16px;padding:12px 14px;color:var(--q-badfg);display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:6px 0;font-size:13px;overflow-wrap:anywhere}
.wq-err span{flex:1 1 200px;min-width:0}
.wq-banner{border-radius:16px;padding:10px 14px;margin:0 0 12px;font-size:13px;font-weight:600;background:var(--q-warnbg);color:var(--q-warnfg)}
.wq-sk{height:64px;border-radius:16px;background:linear-gradient(90deg,var(--q-soft) 25%,var(--q-line) 50%,var(--q-soft) 75%);background-size:200% 100%;animation:wqsk 1.1s infinite;margin:0 0 10px}
@keyframes wqsk{to{background-position:-200% 0}}
@media (prefers-reduced-motion:reduce){.wq-sk{animation:none}}
/* attention */
.wq-attn{border-left:6px solid var(--q-badfg)}
.wq-attn-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.wq-aq{border:1px solid var(--q-aqline);background:var(--q-aq);border-radius:20px;padding:13px 14px;display:flex;flex-direction:column;gap:9px;min-width:0}
.wq-aq .h{display:flex;gap:10px;align-items:center;min-width:0}
.wq-aq .nm{min-width:0;flex:1}.wq-aq .nm b{display:block;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wq-aq .wait{font-weight:800;font-size:18px;color:var(--q-badfg);text-align:right;line-height:1.1;white-space:nowrap}.wq-aq .wait small{display:block;font-size:11px;color:var(--q-mut);font-weight:600}
.wq-aq .msg{font-size:13px;background:var(--q-card);border:1px solid var(--q-aqline);border-radius:12px;padding:8px 11px;overflow-wrap:anywhere}
.wq-aq .meta{font-size:12px;color:var(--q-mut);overflow-wrap:anywhere}.wq-aq .meta b{color:var(--q-ink)}
.wq-pick{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:44px;padding:0 14px;border-radius:99px;border:1.5px solid var(--q-acc);background:var(--q-card);color:#b8470a;font-weight:700;font-size:13.5px;width:100%}
html.wxdk .wq-pick,html.wqdk .wq-pick{color:#ffb27d}
/* team board */
.wq-board{width:100%;border-collapse:collapse;font-size:13.5px}
.wq-board th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--q-mut);font-weight:700;padding:10px 8px;border-bottom:1px solid var(--q-line);white-space:nowrap}
.wq-board th.c,.wq-board td.c{text-align:center}
.wq-board td.num{white-space:nowrap}
.wq-board td{padding:12px 8px;border-bottom:1px solid var(--q-line);vertical-align:middle}
.wq-board tr.bot td{background:var(--q-soft)}.wq-board tr.tot td{font-weight:800;border-bottom:0;background:var(--q-soft)}
.wq-board .who{display:flex;align-items:center;gap:10px;min-width:170px}.wq-board .who b{display:block;font-size:14.5px;overflow-wrap:anywhere}
.wq-board th i{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}
.wq-i-meta{background:var(--q-mf)}.wq-i-camp{background:var(--q-cf)}.wq-i-comm{background:#e0791a}.wq-i-regen{background:#16a34a}.wq-i-new{background:var(--q-nf)}
.wq-why{font-size:12px;color:var(--q-mut);margin-top:4px;line-height:1.35;max-width:300px;overflow-wrap:anywhere}
.wq-only-m{display:none}
.wq-pc{border:1px solid var(--q-line);border-radius:20px;padding:13px;margin-bottom:10px;min-width:0}
.wq-pc.behind{border-color:var(--q-aqline);background:var(--q-aq)}.wq-pc.slow{border-color:#f5e0b3}
html.wxdk .wq-pc.slow,html.wqdk .wq-pc.slow{border-color:#5b4a1a}
.wq-pc .top{display:flex;align-items:center;gap:10px}.wq-pc .nm{flex:1;min-width:0}.wq-pc .nm b{font-size:15.5px;display:block;overflow-wrap:anywhere}
.wq-cnts{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:5px;margin:11px 0 9px}
.wq-cnt{border-radius:12px;padding:6px 2px 5px;text-align:center;line-height:1.15;min-width:0}
.wq-cnt b{display:block;font-size:17px;font-weight:800}.wq-cnt small{font-size:11px;font-weight:700}
.wq-cnt.meta{background:var(--q-mb);color:var(--q-mf)}.wq-cnt.camp{background:var(--q-cb);color:var(--q-cf)}.wq-cnt.comm{background:var(--q-ob);color:var(--q-of)}.wq-cnt.regen{background:var(--q-rb);color:var(--q-rf)}.wq-cnt.new{background:var(--q-nb);color:var(--q-nf)}.wq-cnt.tot{background:var(--q-btn);color:var(--q-btnfg)}
.wq-kv4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px;text-align:center}
.wq-kv4 b{display:block;font-size:14px;font-weight:800}.wq-kv4 small{font-size:11px;color:var(--q-mut);font-weight:600}
.wq-legend{display:flex;gap:6px 8px;flex-wrap:wrap;margin:0 0 10px}
/* sheet */
.wq-bk{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9990;display:flex;align-items:flex-end;justify-content:center}
.wq-sh{background:var(--q-card);color:var(--q-ink);width:100%;max-width:560px;border-radius:24px 24px 0 0;padding:8px 16px calc(16px + env(safe-area-inset-bottom));max-height:92vh;display:flex;flex-direction:column;box-shadow:0 -10px 40px rgba(0,0,0,.25);animation:wqup .2s ease-out}
@keyframes wqup{from{transform:translateY(40px);opacity:.4}to{transform:none;opacity:1}}
.wq-sh .grab{width:44px;height:5px;border-radius:9px;background:var(--q-line);margin:2px auto 10px;flex:none}
.wq-sh .hd{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;flex:none}
.wq-sh .hd h3{margin:0;font-size:19px;font-weight:800;overflow-wrap:anywhere}
.wq-sh .hd .wq-sub{margin-top:2px}
.wq-x{width:44px;height:44px;border-radius:50%;border:0;background:var(--q-mutebg);display:grid;place-items:center;flex:none}
.wq-sh .bd{overflow-y:auto;-webkit-overflow-scrolling:touch;margin:8px -4px 0;padding:0 4px;flex:1;min-height:0}
.wq-sh .ft{flex:none;padding-top:10px}
@media (min-width:821px){.wq-bk{align-items:center}.wq-sh{border-radius:24px;padding-bottom:16px;max-height:86vh}}
.wq-prow{display:flex;align-items:center;gap:11px;padding:10px 12px;border:1.5px solid var(--q-line);border-radius:16px;margin-bottom:8px;min-height:62px;background:var(--q-card);width:100%;text-align:left}
.wq-prow.sel{border-color:var(--q-selline);background:var(--q-sel)}.wq-prow.off{opacity:.55;background:var(--q-soft);cursor:default}
.wq-prow .nm{flex:1;min-width:0}.wq-prow .nm b{font-size:15px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;overflow-wrap:anywhere}.wq-prow .nm small{display:block;font-size:12px;color:var(--q-mut);margin-top:1px}
.wq-radio{width:24px;height:24px;border-radius:50%;border:2px solid var(--q-faint);flex:none;display:grid;place-items:center}
.wq-prow.sel .wq-radio{border-color:var(--q-selline);background:var(--q-selline)}.wq-prow.sel .wq-radio:after{content:"";width:8px;height:8px;border-radius:50%;background:#fff}
.wq-load{height:6px;border-radius:9px;background:var(--q-track);overflow:hidden;margin-top:5px}.wq-load i{display:block;height:100%;background:#16a34a;border-radius:9px}.wq-load i.w{background:#f2b33d}.wq-load i.r{background:#d9423b}
.wq-claude{background:linear-gradient(135deg,var(--q-cl1),var(--q-cl2));border:1px solid var(--q-cll);border-radius:18px;padding:12px 14px;color:var(--q-clfg);margin-bottom:12px}
.wq-claude .hd2{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--q-clb);margin-bottom:5px}
.wq-claude .spark{width:26px;height:26px;border-radius:9px;background:var(--q-clb);color:#fff;display:grid;place-items:center;flex:none}
html.wxdk .wq-claude .spark,html.wqdk .wq-claude .spark{color:#1b1736}
.wq-claude .spark .wq-i{width:15px;height:15px}
.wq-claude p{margin:0;font-size:13.5px;line-height:1.45}
.wq-note{font-size:12.5px;color:var(--q-mut);margin:6px 2px 0;line-height:1.45}
/* switch */
.wq-sw{display:flex;gap:12px;align-items:center;background:var(--q-soft);border:1px solid var(--q-line);border-radius:14px;padding:10px 12px;margin-top:12px}
.wq-sw .t{flex:1;min-width:0;font-size:13.5px;font-weight:700;line-height:1.3}.wq-sw .t small{display:block;font-weight:500;color:var(--q-mut);font-size:12px;margin-top:2px;overflow-wrap:anywhere}
.wq-tg{width:64px;height:40px;border-radius:99px;background:var(--q-faint);position:relative;flex:none;border:0;padding:0}
.wq-tg:after{content:"";position:absolute;left:4px;top:4px;width:32px;height:32px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:left .15s}
.wq-tg[aria-pressed=true]{background:#16a34a}.wq-tg[aria-pressed=true]:after{left:28px}
/* types page */
.wq-qcard{border:1px solid var(--q-cll);border-radius:22px;background:linear-gradient(135deg,var(--q-cl1),var(--q-cl2));padding:16px;margin-bottom:14px;color:var(--q-clfg)}
.wq-qcard h2{margin:0 0 6px;font-size:16px;font-weight:800;display:flex;gap:8px;align-items:center;color:var(--q-clb)}
.wq-rules{margin:10px 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px}
.wq-rules li{display:flex;gap:10px;align-items:flex-start;font-size:14px;line-height:1.4}
.wq-rules li .wq-tp{flex:none;margin-top:1px;width:118px;justify-content:flex-start}
.wq-btnrow{display:flex;gap:8px;flex-wrap:wrap}.wq-btnrow .wq-btn{flex:1 1 140px}
.wq-asks{background:var(--q-card);border-radius:22px;padding:16px;margin-bottom:14px;box-shadow:0 1px 2px rgba(20,22,26,.04),0 6px 18px -10px rgba(20,22,26,.12)}
.wq-asks h3{margin:8px 0 10px;font-size:17px;line-height:1.3;font-weight:800;overflow-wrap:anywhere}
.wq-chat{background:var(--q-chat);border-radius:16px;padding:10px;display:flex;flex-direction:column;gap:6px;margin-bottom:8px}
.wq-bub{background:var(--q-bubin);color:var(--q-bubfg);border-radius:4px 14px 14px 14px;padding:7px 11px;font-size:13.5px;max-width:92%;box-shadow:0 1px 1px rgba(0,0,0,.08);overflow-wrap:anywhere}
.wq-bub.out{background:var(--q-bubout);border-radius:14px 4px 14px 14px;margin-left:auto}.wq-bub small{display:block;text-align:right;font-size:11px;color:#8696a0;margin-top:2px}
.wq-ctx{margin:0 0 12px;padding:0;list-style:none;font-size:12.5px;color:var(--q-mut);display:flex;flex-direction:column;gap:3px}
.wq-qch{display:flex;flex-wrap:wrap;gap:8px}
.wq-qch button{min-height:44px;display:inline-flex;align-items:center;padding:0 16px;border-radius:99px;border:1.5px solid transparent;font-weight:700;font-size:14px}
.wq-qch button.meta{color:var(--q-mf);background:var(--q-mb)}.wq-qch button.camp{color:var(--q-cf);background:var(--q-cb)}.wq-qch button.comm{color:var(--q-of);background:var(--q-ob)}.wq-qch button.regen{color:var(--q-rf);background:var(--q-rb)}.wq-qch button.new{color:var(--q-nf);background:var(--q-nb)}.wq-qch button.oth{color:var(--q-xf);background:var(--q-card);border:1.5px dashed var(--q-faint)}
.wq-qch button.sel{border:1.5px solid currentColor;box-shadow:0 0 0 2px currentColor inset}
.wq-sel{width:100%;min-height:44px;border-radius:14px;border:1px solid var(--q-line);background:var(--q-card);color:var(--q-ink);padding:0 12px;margin-top:8px}
.wq-abar{display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid var(--q-line)}
.wq-abar:first-of-type{border-top:0}.wq-abar .nm{width:112px;flex:none}.wq-abar .bar{flex:1;height:10px;border-radius:9px;background:var(--q-track);overflow:hidden}.wq-abar .bar i{display:block;height:100%;border-radius:9px}.wq-abar b{width:40px;text-align:right;font-size:15px}
.wq-b-meta{background:var(--q-mf)}.wq-b-camp{background:var(--q-cf)}.wq-b-comm{background:#e0791a}.wq-b-regen{background:#16a34a}.wq-b-new{background:var(--q-nf)}.wq-b-ask{background:var(--q-faint)}.wq-b-oth{background:var(--q-faint)}
.wq-rrow{display:flex;gap:10px;align-items:center;padding:10px 0;border-top:1px solid var(--q-line)}.wq-rrow:first-child{border-top:0}.wq-rrow .t{flex:1;min-width:0;font-size:13.5px;font-weight:700;overflow-wrap:anywhere}.wq-rrow .t small{display:block;color:var(--q-mut);font-weight:500;font-size:12px}
/* compare */
.wq-grp{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}
.wq-gc{border:1px solid var(--q-line);border-radius:22px;padding:15px 16px;background:var(--q-card);position:relative;min-width:0}
.wq-gc.win{border:2px solid #16a34a;background:var(--q-sel)}.wq-gc .ribbon{position:absolute;top:-11px;right:14px;background:#16a34a;color:#fff;font-size:11.5px;font-weight:800;padding:3px 11px;border-radius:99px}
.wq-gc h3{margin:0;font-size:16px;font-weight:800;display:flex;align-items:center;gap:8px}.wq-gc .n{font-size:12px;color:var(--q-mut);font-weight:600;margin-bottom:8px}
.wq-mrow{display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:9px 0;border-top:1px solid var(--q-line)}
.wq-mrow span{font-size:13px;color:var(--q-mut)}.wq-mrow b{font-size:16px;font-weight:800;text-align:right}.wq-mrow small{display:block;font-size:11.5px;font-weight:600;color:var(--q-mut);text-align:right}
.wq-mrow b.best{color:var(--q-okfg)}.wq-mrow b.worst{color:var(--q-badfg)}
.wq-mbar{height:8px;border-radius:9px;background:var(--q-track);overflow:hidden;margin-top:5px}.wq-mbar i{display:block;height:100%;background:#16a34a;border-radius:9px}
.wq-verdict{display:flex;gap:12px;align-items:flex-start}.wq-verdict p{margin:0;font-size:15px;line-height:1.5;font-weight:600;overflow-wrap:anywhere}
.wq-verdict .ic{width:40px;height:40px;border-radius:50%;background:#16a34a;color:#fff;display:grid;place-items:center;flex:none}.wq-verdict .ic.no{background:var(--q-faint)}
.wq-won-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.wq-won{border:1px solid var(--q-line);border-radius:20px;padding:13px 14px;background:var(--q-card);display:flex;flex-direction:column;gap:9px;min-width:0}
.wq-won .h{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wq-won .h b{overflow-wrap:anywhere}
/* settings */
.wq-field{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--q-line);border-radius:16px;padding:0 14px;min-height:52px;background:var(--q-card);font-weight:700;flex-wrap:wrap}
.wq-field input[type=date],.wq-field input[type=number]{border:0;background:transparent;font-weight:700;min-height:44px;outline:none;color:var(--q-ink);max-width:100%;color-scheme:light dark}
.wq-field input[type=number]{width:96px;text-align:right}
.wq-in{width:100%;border:1px solid var(--q-line);border-radius:14px;padding:10px 12px;background:var(--q-card);color:var(--q-ink);min-height:44px;font-size:14px}
textarea.wq-in{min-height:84px;resize:vertical;line-height:1.4}
.wq-set{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid var(--q-line);flex-wrap:wrap}.wq-set:first-of-type{border-top:0}
.wq-set .t{flex:1 1 220px;min-width:0;font-weight:700;font-size:13.5px}.wq-set .t small{display:block;font-weight:500;color:var(--q-mut);font-size:12px}
.wq-cut{position:relative;height:84px;margin:14px 0 6px}
.wq-cut .a{position:absolute;left:0;top:30px;height:12px;border-radius:9px 0 0 9px;background:#16945a}.wq-cut .b{position:absolute;right:0;top:30px;height:12px;border-radius:0 9px 9px 0;background:repeating-linear-gradient(135deg,#c7d2fe 0 6px,#e0e7ff 6px 12px)}
html.wxdk .wq-cut .b,html.wqdk .wq-cut .b{background:repeating-linear-gradient(135deg,#34406b 0 6px,#232c4d 6px 12px)}
.wq-cut .mk{position:absolute;top:24px;width:3px;height:24px;background:var(--q-ink);border-radius:2px;margin-left:-1px}.wq-cut .now{position:absolute;top:24px;width:3px;height:24px;background:var(--q-acc);border-radius:2px;margin-left:-1px}
.wq-cut label{position:absolute;font-size:11.5px;font-weight:700;white-space:nowrap;top:52px}
.wq-cut .l1{position:absolute;left:0;top:4px;font-size:13px;font-weight:800;color:#16945a}.wq-cut .l2{position:absolute;right:0;top:4px;font-size:13px;font-weight:800;color:#4f46e5}
html.wxdk .wq-cut .l2,html.wqdk .wq-cut .l2{color:#a5b4fc}
/* analytics */
.wq-an-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.wq-an-grid>.wq-card{margin-bottom:0}.wq-an-grid .full{grid-column:1/-1}
.wq-svg{width:100%;height:auto;display:block;overflow:visible}
.wq-svg text{fill:var(--q-mut);font-size:11px;font-family:inherit}
.wq-svg .ax{stroke:var(--q-line);stroke-width:1}
.wq-lg{display:flex;gap:6px 12px;flex-wrap:wrap;font-size:12px;color:var(--q-mut);margin:6px 0 0}.wq-lg span{display:inline-flex;align-items:center;gap:5px}.wq-lg i{width:9px;height:9px;border-radius:3px;display:inline-block}
.wq-hb{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12.5px}.wq-hb .nm{width:106px;flex:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.wq-hb .bar{flex:1;height:10px;border-radius:9px;background:var(--q-track);overflow:hidden;display:flex}.wq-hb .bar i{display:block;height:100%}.wq-hb b{width:44px;text-align:right}
.wq-tbl{width:100%;border-collapse:collapse;font-size:13px}.wq-tbl th{text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--q-mut);font-weight:700;padding:8px 6px;border-bottom:1px solid var(--q-line);white-space:nowrap}.wq-tbl th:first-child,.wq-tbl td:first-child{text-align:left}
.wq-tbl td{padding:9px 6px;border-bottom:1px solid var(--q-line);text-align:right;vertical-align:middle}.wq-tbl td:first-child{max-width:260px;overflow-wrap:anywhere}
.wq-li{border:1px solid var(--q-line);border-radius:16px;padding:10px 12px;margin-bottom:8px}.wq-li .t{font-weight:700;overflow-wrap:anywhere;font-size:13.5px}.wq-li .g{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px;margin-top:6px;text-align:center}.wq-li .g b{display:block;font-size:14px}.wq-li .g small{font-size:11px;color:var(--q-mut)}
/* my WhatsApp card (My Sales) */
/* the page gives the card (my_sales.html: <div id="wqMine" class="card">): this block is flat so there is no card inside a card */
.wq-mine{max-width:640px;overflow:hidden}
html.wxdk .wq-mine,html.wqdk .wq-mine{background:var(--q-card);border-radius:26px}
.wq-mine .hd{padding:18px 22px 6px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.wq-mine .hd h2{font-size:16px;font-weight:800;margin:0;letter-spacing:-.01em}
.wq-mine .bdy{padding:6px 20px 18px}
.wq-chips{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 12px}
.wq-pg{margin:0 0 12px}
.wq-pg .r1{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:13px;font-weight:700}.wq-pg .r1 span{font-variant-numeric:tabular-nums;color:var(--q-mut);font-weight:700;white-space:nowrap}
.wq-pg .bar{height:8px;background:var(--q-track);border-radius:6px;overflow:hidden;margin:5px 0 3px}.wq-pg .bar i{display:block;height:100%;border-radius:6px;transition:width .5s}
.wq-pg .tx{font-size:12px;color:var(--q-mut);line-height:1.4}
.wq-minis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:6px 0 12px}
.wq-mini{background:var(--q-soft);border-radius:16px;padding:10px 12px;min-height:64px;min-width:0}.wq-mini small{display:block;font-size:12px;color:var(--q-mut);font-weight:600}.wq-mini b{display:block;font-size:19px;font-weight:800;line-height:1.2;margin-top:2px;overflow-wrap:anywhere}.wq-mini em{font-style:normal;font-size:11.5px;color:var(--q-mut);font-weight:600}
.wq-mini.red{background:var(--q-badbg)}.wq-mini.red b,.wq-mini.red small{color:var(--q-badfg)}.wq-mini.grn{background:var(--q-okbg)}.wq-mini.grn b{color:var(--q-okfg)}
/* my queries (WhatsApp look) */
.wq-mq{background:transparent;color:var(--q-ink);min-height:100%}
.wq-mq .bar1{display:flex;align-items:center;gap:8px;padding:10px 12px 4px;flex-wrap:wrap}.wq-mq .bar1 .sp{flex:1}
.wq-mq .srch{padding:4px 12px}.wq-mq .srch input{width:100%;min-height:44px;border-radius:99px;border:1px solid var(--q-line);background:var(--q-soft);padding:0 16px;color:var(--q-ink);font-size:16px}
.wq .wq-ibtn{width:44px;height:44px;border-radius:50%;border:0;background:var(--q-mutebg);display:grid;place-items:center;color:var(--q-ink)}
.wq-todo{margin:8px 12px;border-radius:18px;padding:13px 14px;background:linear-gradient(135deg,#e7f9ee,#f1fbf5);border:1px solid #c6efd5}
html.wxdk .wq-todo{background:linear-gradient(135deg,#0f3a2a,#10261e);border-color:#1d5a41}
.wq-todo h3{margin:0 0 6px;font-size:14.5px;font-weight:800;display:flex;align-items:center;gap:8px;color:#0f5132}html.wxdk .wq-todo h3{color:#6ee7b0}
.wq-todo ol{margin:0;padding:0;list-style:none;counter-reset:n}
.wq-todo li{counter-increment:n;display:flex;gap:10px;font-size:14px;line-height:1.4;padding:5px 0;color:#1f3b2c}html.wxdk .wq-todo li{color:#d3efe0}
.wq-todo li:before{content:counter(n);width:22px;height:22px;border-radius:50%;background:#16a34a;color:#fff;font-weight:800;font-size:12px;display:grid;place-items:center;flex:none;margin-top:1px}
.wq-hh{padding:12px 14px 4px;font-size:12px;font-weight:700;color:var(--q-mut);text-transform:uppercase;letter-spacing:.06em;display:flex;justify-content:space-between;gap:8px}
.wq-tfil{display:flex;gap:8px;padding:6px 12px 6px;flex-wrap:wrap}
.wq-tfil button{min-height:40px;border-radius:99px;border:1.5px solid transparent;padding:0 14px;font-weight:700;font-size:13.5px;display:inline-flex;align-items:center;gap:6px;background:var(--q-mutebg);color:var(--q-mutefg)}
.wq-tfil button.meta{background:var(--q-mb);color:var(--q-mf)}.wq-tfil button.camp{background:var(--q-cb);color:var(--q-cf)}.wq-tfil button.comm{background:var(--q-ob);color:var(--q-of)}.wq-tfil button.regen{background:var(--q-rb);color:var(--q-rf)}.wq-tfil button.new{background:var(--q-nb);color:var(--q-nf)}.wq-tfil button.oth{background:var(--q-xb);color:var(--q-xf)}
.wq-tfil button.on{border-color:currentColor;box-shadow:0 0 0 1px currentColor inset}
.wq-row{display:flex;align-items:center;gap:12px;padding:9px 14px;min-height:72px;background:transparent;width:100%;text-align:left;border:0}
.wq-row .mid{flex:1;min-width:0;border-bottom:1px solid var(--q-line);padding:2px 0 9px;align-self:stretch;display:flex;flex-direction:column;justify-content:center;gap:2px;margin-bottom:-9px}
.wq-row .r1{display:flex;justify-content:space-between;gap:8px;align-items:baseline}.wq-row .r1 b{font-size:16px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wq-row time{font-size:12px;color:var(--q-mut);white-space:nowrap}.wq-row time.red{color:var(--q-badfg);font-weight:700}
.wq-row .last{font-size:14px;color:var(--q-mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.wq-row .r2{display:flex;gap:8px;align-items:center;justify-content:space-between}.wq-row .r3{display:flex;gap:5px;align-items:center;flex-wrap:wrap;margin-top:3px}
.wq-row.wait{background:linear-gradient(90deg,var(--q-rowwait),transparent)}
.wq-unr{min-width:21px;height:21px;border-radius:99px;background:#d9423b;color:#fff;font-size:12px;font-weight:700;display:grid;place-items:center;padding:0 6px;flex:none}
/* chat details block */
.wq-tg-blk{display:flex;flex-direction:column;gap:10px}
.wq-tg-blk .sec{font-size:12px;font-weight:700;color:var(--q-mut);text-transform:uppercase;letter-spacing:.06em;margin:6px 0 0}
.wq-jr{border-radius:16px;padding:10px 14px;background:var(--q-rb);border:1px solid #c6efd5;color:var(--q-rf);font-size:13.5px;line-height:1.45}
html.wxdk .wq-jr{border-color:#1d5a41}
.wq-jr b{display:flex;gap:6px;align-items:center;margin-bottom:2px}
.wq-tgs{display:flex;gap:8px;flex-wrap:wrap}
.wq-tgs .wq-chip{font-size:13px;padding:6px 12px;gap:6px}
.wq-hold{display:flex;gap:11px;align-items:center;border:1px solid var(--q-line);border-radius:18px;padding:10px 12px;flex-wrap:wrap}
.wq-hold .nm{flex:1 1 140px;min-width:0}.wq-hold .nm b{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:15px;overflow-wrap:anywhere}.wq-hold .nm small{display:block;color:var(--q-mut);font-size:12.5px;margin-top:2px}
.wq-hist{display:flex;flex-direction:column}.wq-hist div{display:flex;gap:10px;font-size:12.5px;padding:7px 0;border-top:1px solid var(--q-line);align-items:flex-start}.wq-hist div:first-child{border-top:0}.wq-hist time{color:var(--q-mut);font-weight:600;width:62px;flex:none}.wq-hist span{min-width:0;overflow-wrap:anywhere}
/* morning */
.wq-morn .tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}
.wq-morn .tile{border-radius:18px;padding:11px 12px;min-width:0}.wq-morn .tile small{display:block;font-size:12.5px;font-weight:600}.wq-morn .tile b{display:block;font-size:22px;font-weight:800;line-height:1.15;overflow-wrap:anywhere}.wq-morn .tile em{font-style:normal;font-size:12px;font-weight:600;display:block;overflow-wrap:anywhere}
.wq-morn .tile.u{background:var(--q-badbg);color:var(--q-badfg)}.wq-morn .tile.f{background:var(--q-okbg);color:var(--q-okfg)}.wq-morn .tile.b{background:var(--q-warnbg);color:var(--q-warnfg)}
.wq-act{display:flex;gap:11px;align-items:flex-start;padding:11px 0;border-top:1px solid var(--q-line)}
.wq-act .n{width:26px;height:26px;border-radius:50%;background:var(--q-btn);color:var(--q-btnfg);display:grid;place-items:center;font-weight:800;font-size:13px;flex:none;margin-top:1px}
.wq-act p{margin:0 0 8px;font-size:14px;line-height:1.4;overflow-wrap:anywhere}.wq-act .x{flex:1;min-width:0}
.wq-toast{position:fixed;left:50%;bottom:calc(24px + env(safe-area-inset-bottom));transform:translateX(-50%);background:#0f172a;color:#fff;padding:11px 18px;border-radius:99px;font-weight:700;font-size:13.5px;z-index:99999;max-width:92vw;text-align:center;box-shadow:0 6px 24px rgba(0,0,0,.3)}
.wq-toast.bad{background:#b42318}
/* phone */
@media (max-width:820px){
  .wq-card{padding:15px}
  .wq-stats{grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.wq-stat{grid-column:span 2;padding:11px 12px}.wq-stat:nth-child(n+4){grid-column:span 3}.wq-stat b{font-size:22px}
  .wq-attn-grid{grid-template-columns:1fr}
  .wq-only-d{display:none!important}.wq-only-m{display:block}
  .wq-grp{grid-template-columns:1fr;gap:16px}.wq-won-grid{grid-template-columns:1fr}
  .wq-an-grid{grid-template-columns:1fr}
  .wq-tabs{gap:5px;flex-wrap:nowrap;overflow-x:auto;margin-left:-2px;padding:2px;-webkit-overflow-scrolling:touch;scrollbar-width:none}.wq-tabs::-webkit-scrollbar{display:none}.wq-tabs button{padding:0 12px;font-size:13px;flex:none}
  .wq-rules li .wq-tp{width:104px}
  .wq-minis{gap:6px}.wq-mini b{font-size:17px}
}
`;
  function injectCss() {
    if (document.getElementById('wqCss')) return;
    const s = document.createElement('style'); s.id = 'wqCss'; s.textContent = CSS; document.head.appendChild(s);
  }

  // ------------------------------------------------------------------ small UI helpers
  function toast(msg, bad) {
    injectCss(); const w = document.createElement('div'); w.className = 'wq'; w.innerHTML = '<div class="wq-toast' + (bad ? ' bad' : '') + '" role="status">' + esc(msg) + '</div>';
    document.body.appendChild(w); setTimeout(() => w.remove(), bad ? 5200 : 2400);
  }
  const errBox = (what, e) => '<div class="wq-err"><span>Could not load ' + esc(what) + '. ' + esc(e && e.message ? e.message : 'The server did not answer.') + '</span><button type="button" class="wq-btn sm" data-wq-retry>Try again</button></div>';
  const skel = (n) => '<div class="wq-sk"></div>'.repeat(n || 2);
  // bottom sheet (phone) / centred dialog (desktop)
  function sheet(o) {
    injectCss(); o = o || {};
    const w = document.createElement('div'); w.className = 'wq-ov';
    w.innerHTML = '<div class="wq-bk" role="dialog" aria-modal="true" aria-label="' + esc(o.title || '') + '"><div class="wq-sh"><div class="grab"></div><div class="hd"><div style="min-width:0"><h3>' + esc(o.title || '') + '</h3>' + (o.sub ? '<div class="wq-sub">' + esc(o.sub) + '</div>' : '') + '</div><button type="button" class="wq-x" aria-label="Close">' + ico('x') + '</button></div><div class="bd"></div><div class="ft"></div></div></div>';
    document.body.appendChild(w);
    const bk = w.querySelector('.wq-bk'), bd = w.querySelector('.bd'), ft = w.querySelector('.ft');
    const close = () => { document.removeEventListener('keydown', onKey); w.remove(); if (o.onClose) o.onClose(); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    bk.addEventListener('click', (e) => { if (e.target === bk) close(); });
    w.querySelector('.wq-x').onclick = close;
    return { el: w, body: bd, foot: ft, close, title: (t, s) => { w.querySelector('h3').textContent = t; const sb = w.querySelector('.hd .wq-sub'); if (sb && s != null) sb.textContent = s; } };
  }
  function apiOf(o) { if (o && o.api) return o.api; try { return typeof api !== 'undefined' ? api : null; } catch (e) { return null; } }   // api.js declares `const api`, which is not a window property
  const call = (o, action, params) => { const a = apiOf(o); if (!a) return Promise.reject(new Error('Not logged in.')); return a.get(action, params || {}); };
  const post = (o, action, body) => { const a = apiOf(o); if (!a) return Promise.reject(new Error('Not logged in.')); return a.post(action, body || {}); };
  const alive = (el) => el && el.isConnected;
  const visible = () => !document.hidden;
  function root(el, extra) { injectCss(); el.classList.add('wq'); if (extra) el.classList.add(extra); }

  // ------------------------------------------------------------------ transfer (existing path: wa.people + wa.transfer)
  async function transferSheet(o, ctx, done) {
    const sh = sheet({ title: 'Transfer ' + (ctx.name || pretty(ctx.phone)), sub: 'Pick a colleague who is in today. The count moves: -1 for you, +1 for them.' });
    sh.body.innerHTML = skel(3); let people = [], sel = 0;
    const draw = () => {
      const list = people.filter((p) => Number(p.emp_id) !== Number(ctx.holder_id));
      sh.body.innerHTML = (list.length ? list.map((p) => {
        const ok = p.present !== false && !p.full;
        return '<button type="button" class="wq-prow' + (sel === Number(p.emp_id) ? ' sel' : '') + (ok ? '' : ' off') + '" data-e="' + p.emp_id + '"' + (ok ? '' : ' disabled') + '><span class="wq-av wq-a' + hue(p.name) + '">' + esc(initials(p.name)) + '</span><span class="nm"><b>' + esc(p.name) + (p.present === false ? '<span class="wq-chip mute">Not in today</span>' : (p.full ? '<span class="wq-chip warn">Chat list full</span>' : '<span class="wq-chip ok"><i class="dot"></i>On duty</span>')) + '</b>' + (has(p.open) ? '<small>' + num(p.open) + ' chats open</small>' : '') + '</span><span class="wq-radio"></span></button>';
      }).join('') : '<div class="wq-empty"><b>Nobody else is available</b>No other salesperson is listed right now.</div>');
      sh.foot.innerHTML = '<div class="wq-note" id="wqTrErr" style="color:var(--q-badfg)" hidden></div><button type="button" class="wq-btn pri block" id="wqTrGo"' + (sel ? '' : ' disabled') + '>' + (sel ? 'Transfer to ' + esc((people.find((p) => Number(p.emp_id) === sel) || {}).name || '') : 'Pick a person') + '</button>';
      sh.foot.querySelector('#wqTrGo').onclick = async function () {
        const b = this, er = sh.foot.querySelector('#wqTrErr'); b.disabled = true; b.textContent = 'Transferring...'; er.hidden = true;
        try { await post(o, 'wa.transfer', { phone: ctx.phone, emp_id: sel }); sh.close(); toast('Transferred'); if (done) done(); }
        catch (e) { er.textContent = e.message || 'Could not transfer.'; er.hidden = false; b.disabled = false; b.textContent = 'Try again'; }
      };
    };
    sh.body.onclick = (e) => { const b = e.target.closest('[data-e]'); if (b && !b.disabled) { sel = Number(b.dataset.e); draw(); } };
    try { const d = await call(o, 'wa.people', { phone: ctx.phone }); people = d.people || []; draw(); }
    catch (e) { sh.body.innerHTML = errBox('the people list', e); sh.body.querySelector('[data-wq-retry]').onclick = () => { sh.close(); transferSheet(o, ctx, done); }; }
  }

  // ------------------------------------------------------------------ progress bars (My Sales look: 8px track, colour by share like My Sales)
  const pgColor = (p) => p >= 100 ? '#7C3AED' : (p >= 60 ? '#0f7a41' : (p >= 30 ? '#b45f06' : '#c81e1e'));
  function goalsOf(d) {
    const g = d.goals || d.goal || d.targets || {}; const out = [];
    const pick = (...ks) => { for (const k of ks) { if (has(g[k])) return Number(g[k]); } return null; };
    if (Array.isArray(d.progress) && d.progress.length) {
      d.progress.forEach((p) => { if (p && has(p.goal) && Number(p.goal) > 0) out.push({ key: p.key || p.label, label: p.label || p.key, value: p.value, goal: Number(p.goal), unit: p.unit || '', lower: !!p.lower_is_better, text: p.text || '' }); });
      if (out.length) return out;
    }
    const gq = pick('queries', 'query'), go = pick('orders'), gr = pick('reply_min', 'first_reply_min');
    if (gq != null && gq > 0) out.push({ key: 'queries', label: 'Queries', value: num(d.queries), goal: gq, unit: 'queries' });
    if (go != null && go > 0) out.push({ key: 'orders', label: 'Orders', value: num(d.orders), goal: go, unit: 'orders' });
    if (gr != null && gr > 0) out.push({ key: 'reply', label: 'First reply time', value: has(d.first_reply_min) ? Number(d.first_reply_min) : null, goal: gr, lower: true });
    return out;
  }
  function barHtml(b) {
    let p = 0, right = '', tx = '';
    if (b.lower) {
      if (!has(b.value)) { right = '- / ' + fmtMin(b.goal); tx = 'No reply measured yet. Target ' + fmtMin(b.goal) + '.'; }
      else { p = Math.round(100 * b.goal / Math.max(b.value, 0.0001)); right = fmtMin(b.value) + ' / ' + fmtMin(b.goal); tx = b.value <= b.goal ? 'First reply ' + fmtMin(b.value) + '. Target ' + fmtMin(b.goal) + '. On target.' : 'First reply ' + fmtMin(b.value) + '. Target ' + fmtMin(b.goal) + '. Aim to be faster.'; }
    } else {
      const v = num(b.value); p = Math.round(100 * v / b.goal); right = v + ' / ' + b.goal + ' · ' + p + '%';
      const unit = b.unit || String(b.label || '').toLowerCase();
      tx = b.text || (v >= b.goal ? (v > b.goal ? 'Goal beaten: ' + v + ' ' + unit + ' against a goal of ' + b.goal + '.' : 'Goal reached: ' + v + ' of ' + b.goal + ' ' + unit + '.') : 'Achieved ' + v + ' of ' + b.goal + ' ' + unit + '. ' + (b.goal - v) + ' to go.');
    }
    return '<div class="wq-pg" data-pg="' + esc(b.key) + '"><div class="r1"><span style="color:var(--q-ink)">' + esc(b.label) + '</span><span>' + esc(right) + '</span></div><div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.min(100, p) + '"><i style="width:' + Math.min(100, Math.max(0, p)) + '%;background:' + pgColor(p) + '"></i></div><div class="tx">' + esc(tx) + '</div></div>';
  }

  // ------------------------------------------------------------------ My WhatsApp card
  function mineCard(el, o) {
    o = o || {}; if (!el) return Promise.resolve({ shown: false });
    injectCss();
    const hide = () => { el.innerHTML = ''; el.style.display = 'none'; el.classList.remove('wq'); return { shown: false }; };
    const open = () => { if (o.onOpen) o.onOpen(); else location.href = 'my_chats.html?tab=queries'; };
    return call(o, 'wq.mine', { days: 1 }).then((d) => {
      if (!d || d.on === false) return hide();
      el.classList.add('wq'); el.style.display = '';
      const bt = d.by_type || {}; const chips = TYPES.filter((t) => num(bt[t[0]]) > 0).map((t) => '<span class="wq-tp ' + t[2] + '">' + t[1] + ' ' + num(bt[t[0]]) + '</span>').join('') || '<span class="wq-sub">No queries yet today.</span>';
      const bars = goalsOf(d).map(barHtml).join('');
      const w = d.waiting || [], nW = num(d.unanswered, w.length), oldest = w.length ? Math.max.apply(null, w.map((x) => num(x.since_min))) : 0;
      const tin = num(d.transferred_in), tout = num(d.transferred_out);
      el.innerHTML = '<div class="wq-mine"><div class="hd"><h2>My WhatsApp</h2><span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">' + (d.on_duty ? '<span class="wq-chip ok"><i class="dot"></i>On duty</span>' : '') + '<span class="wq-sub">Today, ' + num(d.queries) + ' ' + (num(d.queries) === 1 ? 'query' : 'queries') + '</span></span></div><div class="bdy">'
        + '<div class="wq-chips">' + chips + '</div>' + bars
        + '<div class="wq-minis"><div class="wq-mini' + (has(d.first_reply_min) ? ' grn' : '') + '"><small>My first reply</small><b>' + esc(fmtMin(d.first_reply_min)) + '</b>' + (has(d.team_first_reply_min) ? '<em>team ' + esc(fmtMin(d.team_first_reply_min)) + '</em>' : '') + '</div>'
        + '<div class="wq-mini' + (nW > 0 ? ' red' : '') + '"><small>Waiting</small><b>' + nW + '</b>' + (nW > 0 && w.length ? '<em>oldest ' + Math.round(oldest) + ' min</em>' : '') + '</div>'
        + '<div class="wq-mini"><small>Transfers</small><b><span class="grn">+' + tin + '</span> / <span class="' + (tout ? 'red' : '') + '">-' + tout + '</span></b><em>in / out</em></div></div>'
        + '<button type="button" class="wq-btn pri block" data-wq-open>Open my queries</button></div></div>';
      el.querySelector('[data-wq-open]').onclick = open;
      return { shown: true, data: d };
    }).catch(hide);
  }

  // ------------------------------------------------------------------ My queries (full screen list)
  const ARR = { morning: 'Morning', afternoon: 'Afternoon', evening: 'Evening', night: 'Night' };
  function myQueries(el, o) {
    o = o || {}; if (!el) return null; root(el);
    const S = { type: 'all', days: 1, q: '', mine: null, list: null, err: '', loading: true, search: false, fetched: Date.now(), mineErr: false };
    let timer = 0, seq = 0;
    const openChat = (phone) => { if (o.onOpen) o.onOpen(phone); else location.href = 'my_chats.html?phone=' + encodeURIComponent(phone); };
    el.innerHTML = '<div class="wq-mq"><div id="wqMqHead"></div><div id="wqMqList"></div></div>';
    const head = el.querySelector('#wqMqHead'), listEl = el.querySelector('#wqMqList');
    async function load(quiet) {
      const my = ++seq; if (!quiet) { S.loading = true; draw(); }
      const p = { type: S.type, days: S.days, per: 100 }; if (S.q.length >= 2) p.q = S.q;
      const [m, l] = await Promise.all([call(o, 'wq.mine', { days: S.days }).then((x) => ({ ok: x }), (e) => ({ e })), call(o, 'wq.queries', p).then((x) => ({ ok: x }), (e) => ({ e }))]);
      if (my !== seq || !alive(el)) return;
      S.loading = false; S.fetched = Date.now();
      if (m.ok) { S.mine = m.ok; S.mineErr = false; } else S.mineErr = true;
      if (l.ok) { S.list = l.ok; S.err = ''; } else if (!quiet || !S.list) S.err = (l.e && l.e.message) || 'The server did not answer.';
      draw();
    }
    function todo() {
      const d = S.mine; if (!d) return ''; const out = []; const nW = d.waiting ? d.waiting.length : num(d.unanswered);
      out.push(nW > 0 ? 'Answer the ' + nW + ' waiting ' + (nW === 1 ? 'chat' : 'chats') + ' first (red).' : 'No chat is waiting. Good work.');
      const goals = goalsOf(d).filter((g) => g.lower)[0];
      if (has(d.first_reply_min)) out.push(goals && d.first_reply_min > goals.goal ? 'Your first reply is ' + fmtMin(d.first_reply_min) + '. Aim for ' + fmtMin(goals.goal) + '.' : 'Your first reply is ' + fmtMin(d.first_reply_min) + '. Keep it there.');
      if (num(d.transferred_in) > 0) out.push('You received ' + num(d.transferred_in) + ' transferred ' + (num(d.transferred_in) === 1 ? 'chat' : 'chats') + '. Check them early.');
      else { const bt = d.by_type || {}; const top = TYPES.map((t) => [t, num(bt[t[0]])]).sort((a, b) => b[1] - a[1])[0]; if (top && top[1] > 0) out.push('Most of your queries are ' + top[0][1] + ' (' + top[1] + '). Open those chats first.'); }
      return '<div class="wq-todo"><h3>' + ico('list') + 'What to do today</h3><ol>' + out.slice(0, 3).map((x) => '<li><span>' + esc(x) + '</span></li>').join('') + '</ol></div>';
    }
    function draw() {
      const d = S.mine || {}, L = S.list, counts = (L && L.counts) || {};
      const w = d.waiting || [], nW = d.waiting ? w.length : num(d.unanswered), oldest = w.length ? Math.max.apply(null, w.map((x) => num(x.since_min))) : 0;
      const tin = num(d.transferred_in), tout = num(d.transferred_out);
      let h = '<div class="bar1">' + (S.mine ? dutyChip(d.on_duty ? 'on' : 'off', d.on_duty) : '') + '<span class="sp"></span><button type="button" class="wq-ibtn" data-wq-search aria-label="Search">' + ico('search') + '</button></div>';
      if (S.search) h += '<div class="srch"><input type="search" id="wqMqQ" placeholder="Search name or number" value="' + esc(S.q) + '" autocomplete="off"></div>';
      if (S.mine && d.on === false) h += '<div class="wq-banner" style="margin:8px 12px">WhatsApp Sales is switched off right now.</div>';
      h += todo();
      h += '<div class="wq-hh"><span>' + (S.days === 1 ? 'Today' : 'Last ' + S.days + ' days') + ', ' + num(counts.all, d.queries) + ' ' + (num(counts.all, d.queries) === 1 ? 'query' : 'queries') + '</span><span class="wq-seg" role="group" aria-label="Period"><button type="button" data-wq-days="1" class="' + (S.days === 1 ? 'on' : '') + '">Today</button><button type="button" data-wq-days="7" class="' + (S.days === 7 ? 'on' : '') + '">7 days</button></span></div>';
      h += '<div class="wq-tfil"><button type="button" data-wq-t="all" class="' + (S.type === 'all' ? 'on' : '') + '">All ' + num(counts.all) + '</button>' + TYPES.filter((t) => num(counts[t[0]]) > 0 || S.type === t[0]).map((t) => '<button type="button" data-wq-t="' + t[0] + '" class="' + t[2] + (S.type === t[0] ? ' on' : '') + '">' + t[1] + ' ' + num(counts[t[0]]) + '</button>').join('') + '</div>';
      h += '<div class="wq-minis" style="padding:6px 12px;margin:0"><div class="wq-mini' + (has(d.first_reply_min) ? ' grn' : '') + '"><small>My first reply</small><b>' + esc(fmtMin(d.first_reply_min)) + '</b></div><div class="wq-mini' + (nW > 0 ? ' red' : '') + '"><small>Waiting</small><b>' + nW + '</b>' + (nW > 0 && w.length ? '<em>oldest ' + Math.round(oldest) + ' min</em>' : '') + '</div><div class="wq-mini"><small>Transfers</small><b><span class="grn">+' + tin + '</span> / <span class="' + (tout ? 'red' : '') + '">-' + tout + '</span></b><em>in / out</em></div></div>';
      h += '<div class="wq-hh"><span>Chats</span><span>Waiting first</span></div>';
      head.innerHTML = h;
      const qi = head.querySelector('#wqMqQ'); if (qi) { qi.oninput = function () { const v = this.value; clearTimeout(qi._t); qi._t = setTimeout(() => { S.q = v.trim(); load(true); }, 350); }; if (S._focus) { qi.focus(); S._focus = false; } }
      // the list
      if (S.loading && !S.list) { listEl.innerHTML = '<div style="padding:0 12px">' + skel(4) + '</div>'; return; }
      if (S.err && !S.list) { listEl.innerHTML = '<div style="padding:0 12px">' + errBox('your queries', { message: S.err }) + '</div>'; listEl.querySelector('[data-wq-retry]').onclick = () => load(false); return; }
      const items = ((L && L.items) || []).slice(); const waitingSet = {}; (d.waiting || []).forEach((x) => { waitingSet[x.phone] = x; });
      const isW = (it) => it.state === 'waiting' || it.state === 'escalated' || !!waitingSet[it.phone];
      items.sort((a, b) => (isW(b) ? 1 : 0) - (isW(a) ? 1 : 0) || String(b.opened_at).localeCompare(String(a.opened_at)));
      if (!items.length) { listEl.innerHTML = '<div class="wq-empty"><b>' + (S.q || S.type !== 'all' ? 'Nothing matches' : 'No queries yet') + '</b>' + (S.q || S.type !== 'all' ? 'Try another type or clear the search.' : 'New customer chats given to you will show up here.') + '</div>'; return; }
      const el0 = (Date.now() - S.fetched) / 60000;
      listEl.innerHTML = (S.err ? '<div style="padding:0 12px">' + errBox('your queries', { message: S.err }) + '</div>' : '') + items.map((it) => {
        const ws = waitingSet[it.phone], wait = isW(it), mins = ws ? num(ws.since_min) + el0 : null;
        const tags = (it.tags || []); const prem = tags.some((t) => /^premium_/.test(t)); const arr = ARR[it.arrived] || '';
        const tin = it.transferred_in && it.first_holder && it.first_holder.name ? '<span class="wq-chip info">Transfer in: ' + esc(it.first_holder.name) + '</span>' : '';
        const when1 = wait ? '<time class="red">Waiting ' + (mins != null ? Math.max(1, Math.round(mins)) + ' min' : '') + '</time>' : '<time>' + esc(clock(it.opened_at)) + '</time>';
        const sub = it.last_in || it.last_text || (wait ? 'Customer is waiting for your reply' : (has(it.first_reply_min) ? 'First reply in ' + fmtMin(it.first_reply_min) : (it.state === 'closed' ? 'Closed' : 'Opened ' + when(it.opened_at))));
        return '<button type="button" class="wq-row' + (wait ? ' wait' : '') + '" data-wq-p="' + esc(it.phone) + '"><span class="wq-av wq-a' + hue(it.phone) + '">' + esc(initials(it.name, it.phone)) + '</span><span class="mid"><span class="r1"><b>' + esc(it.name || pretty(it.phone)) + '</b>' + when1 + '</span><span class="r2"><span class="last">' + esc(sub) + '</span>' + (wait ? '<span class="wq-unr" aria-label="Waiting">!</span>' : '') + '</span><span class="r3">' + pill(it.type, it.type_label) + (prem ? '<span class="wq-chip gold">Premium</span>' : '') + (arr ? '<span class="wq-chip mute">' + arr + '</span>' : '') + tin + '</span></span></button>';
      }).join('');
    }
    const onClick = (e) => {   // removed again by destroy(): leaving and re-opening the tab never adds a second listener (that opened a chat 2-3 times)
      const t = e.target.closest('[data-wq-t]'); if (t) { S.type = t.dataset.wqT; load(false); return; }
      const dd = e.target.closest('[data-wq-days]'); if (dd) { S.days = +dd.dataset.wqDays; load(false); return; }
      if (e.target.closest('[data-wq-search]')) { S.search = !S.search; S._focus = S.search; if (!S.search && S.q) { S.q = ''; load(true); } else draw(); return; }
      const r = e.target.closest('[data-wq-p]'); if (r) openChat(r.dataset.wqP);
    };
    el.addEventListener('click', onClick); let dead = false;
    draw(); load(false);
    timer = setInterval(() => { if (dead || !alive(el)) { clearInterval(timer); return; } if (visible() && !S.search) load(true); }, 30000);
    return { refresh: () => { if (!dead) load(true); }, destroy: () => { dead = true; clearInterval(timer); el.removeEventListener('click', onClick); } };
  }

  // ------------------------------------------------------------------ chat details block (type, tags, holder, transfer, history)
  function tags(el, o) {
    o = o || {}; if (!el) return; root(el); const phone = String(o.phone || '').replace(/\D/g, '').slice(-10);
    if (!phone) { el.innerHTML = ''; return; }
    el.innerHTML = '<div class="wq-tg-blk">' + skel(2) + '</div>';
    Promise.all([call(o, 'wq.tags_for', { phone }).then((x) => ({ ok: x }), (e) => ({ e })), call(o, 'wq.query', { phone }).then((x) => ({ ok: x }), (e) => ({ e }))]).then(([t, q]) => {
      if (!alive(el)) return;
      if (!t.ok && !q.ok) { el.innerHTML = '<div class="wq-tg-blk">' + errBox('the query type', t.e) + '</div>'; el.querySelector('[data-wq-retry]').onclick = () => tags(el, o); return; }
      const T = t.ok || {}; if (T.on === false) { el.innerHTML = ''; return; }
      const Q = q.ok || {}; const qi = Q.query || null, ty = T.type || (qi ? { query_id: qi.query_id, type: qi.type, label: qi.type_label, reason: qi.reason } : null);
      let h = '<div class="wq-tg-blk">';
      if (ty) {
        h += '<div class="sec">This query</div><div>' + pill(ty.type, ty.label || ty.pill, true) + '</div><div class="wq-sub">Query Q-' + esc(ty.query_id) + (qi && qi.opened_at ? ' · opened ' + esc(when(qi.opened_at)) : '') + '</div>';
        const reason = String(ty.reason || '').trim(); const dm = /(\d+)\s*days?/i.exec(reason);
        if (ty.type === 'regenerated') { const rest = reason.replace(/^silent\s+(for\s+)?\d+\s+days?\s*,?\s*/i, '').replace(/\.$/, ''); h += '<div class="wq-jr"><b>' + ico('clock') + 'Journey</b>' + (dm ? 'Regenerated after ' + dm[1] + ' days.' : 'Regenerated.') + (rest ? ' ' + esc(rest.charAt(0).toUpperCase() + rest.slice(1)) + '.' : '') + '</div>'; }
        else if (reason) { const tg = Q.typing || {}; h += '<div class="wq-note" style="margin:0">Why: ' + esc(reason) + (tg.auto === false && tg.set_by && tg.set_by.name ? ' (set by ' + esc(tg.set_by.name) + ')' : tg.auto ? ' (typed automatically)' : '') + '</div>'; }
      } else h += '<div class="sec">This query</div><div class="wq-sub">No query record for this chat yet.</div>';
      const tl = T.tags || []; const shown = tl.length ? tl : (Q.tags || []);
      if (shown.length) {
        h += '<div class="sec">Tags</div><div class="wq-tgs">' + shown.map((x) => {
          const g = x.group || (/^premium_/.test(x.tag) ? 'premium' : /^arrive_/.test(x.tag) ? 'time' : /_query$|^community$/.test(x.tag) ? 'type' : 'other');
          if (g === 'value' || g === 'premium') return '<span class="wq-chip gold">' + ico('star', 's') + esc(x.label || x.tag) + '</span>';
          if (g === 'time') return '<span class="wq-chip mute">' + ico(/morning|afternoon/.test(x.tag) ? 'sun' : 'moon', 's') + esc(x.label || x.tag) + '</span>';
          if (g === 'type') { const k = String(x.tag).replace(/_query$/, ''); return '<span class="wq-chip vio" style="background:var(--q-' + ({ meta: 'mb', campaign: 'cb', community: 'ob', regenerated: 'rb', new: 'nb', other: 'xb' }[k] || 'cb') + ');color:var(--q-' + ({ meta: 'mf', campaign: 'cf', community: 'of', regenerated: 'rf', new: 'nf', other: 'xf' }[k] || 'cf') + ')">' + esc(x.label || x.tag) + '</span>'; }
          return '<span class="wq-chip mute">' + esc(x.label || x.tag) + '</span>';
        }).join('') + '</div>';
        if (shown.some((x) => (x.group === 'time') || /^arrive_/.test(x.tag))) h += '<div class="wq-note" style="margin:0">The time-of-day tag is added automatically from the time the customer writes.</div>';
      }
      if (qi) {
        const hold = qi.holder || {};
        h += '<div class="sec">Who holds it now</div><div class="wq-hold">' + (hold.emp_id ? '<span class="wq-av wq-a' + hue(hold.name) + '">' + esc(initials(hold.name)) + '</span><span class="nm"><b>' + esc(hold.name || 'Someone') + '</b><small>' + (qi.state === 'waiting' || qi.state === 'escalated' ? 'Customer is waiting' : (has(qi.first_reply_min) ? 'First reply ' + esc(fmtMin(qi.first_reply_min)) : 'Opened ' + esc(when(qi.opened_at)))) + '</small></span>' : '<span class="nm"><b>Nobody yet</b><small>This chat is not held by a person.</small></span>') + (o.noTransfer ? '' : '<button type="button" class="wq-btn pri sm" data-wq-tr>' + ico('swap', 's') + 'Transfer</button>') + '</div>';   // noTransfer: the screen has its own one Assign / Hand over button
        const mv = (Q.moves || []).slice().reverse();
        const created = '<div><time>' + esc(clock(qi.opened_at)) + '</time><span>Created. ' + (ty ? 'Typed <b>' + esc(ty.label || ty.type) + '</b>' + (Q.typing && Q.typing.auto === false ? ' by ' + esc((Q.typing.set_by || {}).name || 'a person') : (Q.typing && Q.typing.rule ? ' by your rule' : ' automatically')) + '. ' : '') + (qi.first_holder && qi.first_holder.name ? 'Assigned to ' + esc(qi.first_holder.name) + '.' : '') + '</span></div>';
        if (!o.noHistory) h += '<div class="sec">Transfer history (' + mv.length + ')</div><div class="wq-hist">' + mv.map((m) => '<div><time>' + esc(clock(m.at)) + '</time><span><b>' + esc((m.from || {}).name || 'Nobody') + '</b> to <b>' + esc((m.to || {}).name || 'Nobody') + '</b>.' + (m.from && m.from.name && m.to && m.to.name ? ' ' + esc(m.from.name) + ' -1, ' + esc(m.to.name) + ' +1.' : '') + (m.by ? ' By ' + esc(m.by) + '.' : '') + (m.late ? ' (late)' : '') + '</span></div>').join('') + created + '</div>';
      }
      h += '</div>'; el.innerHTML = h;
      const tr = el.querySelector('[data-wq-tr]');
      if (tr) tr.onclick = () => transferSheet(o, { phone, name: (qi && qi.name) || o.name || '', holder_id: qi && qi.holder ? qi.holder.emp_id : 0 }, () => { tags(el, o); if (o.onTransferred) o.onTransferred(); });
    });
  }

  // ------------------------------------------------------------------ Morning card
  function morning(el, o) {
    o = o || {}; if (!el) return; root(el, 'wq-morn');
    const base = o.base || 'wa_sales_live.html';
    const go = (tab) => base + '?tab=' + tab;
    const load = (rebuild) => {
      el.innerHTML = '<div class="wq-card">' + skel(2) + '</div>';
      call(o, 'wq.summary', rebuild ? { rebuild: 1 } : {}).then((d) => {
        if (!alive(el)) return;
        if (!d || d.on === false) { el.innerHTML = ''; return; }
        const day = parse(d.day + ' 00:00:00');
        let h = '<div class="wq-card"><div class="wq-ch"><h2>' + ico('chat') + 'WhatsApp Sales · yesterday</h2>' + (day ? '<span class="wq-chip">' + esc(dayName(day)) + '</span>' : '') + '</div>';
        if (!d.built) {
          h += '<div class="wq-sub" style="margin-bottom:10px">Yesterday\'s summary is not built yet. It is built every morning by the tick.</div><button type="button" class="wq-btn sm" data-wq-build>Build it now</button></div>'; el.innerHTML = h;
          el.querySelector('[data-wq-build]').onclick = function () { this.disabled = true; this.textContent = 'Building...'; load(true); }; return;
        }
        const total = num(d.total), un = num(d.unanswered), bt = d.by_type || {};
        h += '<div class="wq-sub" style="font-size:14px">' + total + ' ' + (total === 1 ? 'query' : 'queries') + ' came in. ' + Math.max(0, total - un) + ' ' + (total - un === 1 ? 'was' : 'were') + ' answered.</div>';
        h += '<div class="wq-chips" style="margin-top:10px">' + TYPES.filter((t) => t[0] !== 'other' && num(bt[t[0]]) > 0).map((t) => '<span class="wq-tp lg ' + t[2] + '">' + t[1] + ' ' + num(bt[t[0]]) + '</span>').join('') + (num(bt.other) > 0 ? '<span class="wq-tp lg oth">Other ' + num(bt.other) + '</span>' : '') + '</div>';
        const f = d.fastest, b0 = (d.behind || [])[0];
        h += '<div class="tiles"><div class="tile u"><small>Unanswered</small><b>' + un + '</b></div><div class="tile f"><small>Fastest</small><b>' + esc(f && f.name ? f.name : '-') + '</b><em>' + esc(f ? fmtMin(f.first_reply_min) : '') + '</em></div><div class="tile b"><small>Behind</small><b>' + esc(b0 ? b0.name : 'No one') + '</b><em>' + esc(b0 && b0.reasons && b0.reasons[0] ? cut(b0.reasons[0], 60) : '') + '</em></div></div>';
        const acts = d.actions || [];
        if (acts.length) {
          h += '<div class="wq-lb" style="margin-top:6px">Do today</div>' + acts.slice(0, 3).map((a, i) => {
            const tab = /question|query types|types page/i.test(a) ? ['types', 'Open Query types'] : /unanswered|assign|nobody answered|still open/i.test(a) ? ['attention', 'Open Attention needed'] : /bot|miss you|silent|compare/i.test(a) ? ['compare', 'Open Bot vs person'] : ['live', /ask |why|slow/i.test(a) ? 'See the board' : 'Open WhatsApp Sales'];
            return '<div class="wq-act"><span class="n">' + (i + 1) + '</span><div class="x"><p>' + esc(a) + '</p><a class="wq-btn sm" href="' + go(tab[0]) + '">' + esc(tab[1]) + '</a></div></div>';
          }).join('');
        } else h += '<div class="wq-sub" style="margin-top:6px">Nothing special to do today.</div>';
        el.innerHTML = h + '</div>';
      }).catch((e) => { if (!alive(el)) return; el.innerHTML = '<div class="wq-card">' + errBox('the WhatsApp Sales summary', e) + '</div>'; el.querySelector('[data-wq-retry]').onclick = () => load(false); });
    };
    load(false);
  }

  window.WaQ = { v: 'q1', css: injectCss, mineCard, myQueries, tags, morning, transferSheet,
    u: { esc, num, has, money, pct, parse, hhmm, clock, when, dayName, fmtMin, fmtHrs, initials, hue, pretty, masked, TYPES, tinfo, pill, dutyChip, dutyWords, DUTY, ico, toast, errBox, skel, sheet, call, post, alive, visible, root, barHtml, goalsOf, pgColor } };
})();
