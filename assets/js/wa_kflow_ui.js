/**
 * wa_kflow_ui.js — 🔑 Key flows (WhatsApp → Automation → Key flows). Admin only. Server: wa_kflow.php (wa.kf_*).
 *   List: every key flow (priority = order; drag or ⋯ to move), on/off, start texts, numbers (7 days), 🧪 test a message, overlaps, website link + QR.
 *   Canvas (full screen): drag boxes in from the left, connect a button / row / "then" dot to the next box, edit the selected box on the right,
 *   red dot = a problem that keeps the flow off, 💡 = quiet suggestions (count only, never pops up), ▶ test in a phone, On/Off, Save (Ctrl+S).
 * Phone: the list works fully; the canvas can be viewed and edited, best on PC.
 */
(function () {
  'use strict';
  if (window.WaKflow) return;
  if (!window.WaPanels || !WaPanels.util || typeof api === 'undefined') return;
  const { esc, fmt, toast, modal } = WaPanels.util;
  const I = {
    key: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>', msg: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    img: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>', doc: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5M9 13h6M9 17h6"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>', globe: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
    mega: '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>', bag: '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
    head: '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>', goto: '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    tag: '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><circle cx="7.5" cy="7.5" r="1"/>', play: '<polygon points="6 3 20 12 6 21 6 3"/>',
    zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>', bulb: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5A6 6 0 1 0 6 8c0 1.3.5 2.6 1.5 3.5.8.8 1.3 1.5 1.5 2.5M9 18h6M10 22h4"/>',
    flask: '<path d="M10 2v7.5L4.2 19A2 2 0 0 0 6 22h12a2 2 0 0 0 1.8-3L14 9.5V2M8.5 2h7M7 16h10"/>', link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    alert: '<path d="m21.7 18-8-14a2 2 0 0 0-3.5 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3z"/><path d="M12 9v4M12 17h.01"/>', check: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    plus: '<path d="M12 5v14M5 12h14"/>', minus: '<path d="M5 12h14"/>', more: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    grip: '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
    left: '<path d="m15 18-6-6 6-6"/>', arrow: '<path d="M5 12h14M12 5l7 7-7 7"/>', ext: '<path d="M7 17 17 7M7 7h10v10"/>', reply: '<path d="m9 17-5-5 5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
    flow: '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M6 9v3a3 3 0 0 0 3 3h6"/>', max: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>', copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>', up: '<path d="m18 15-6-6-6 6"/>', down: '<path d="m6 9 6 6 6-6"/>', qr: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4M20 17v.01"/>',
    send: '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>', chart: '<path d="M3 3v18h18M7 16l4-4 4 4 5-6"/>', video: '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="m22 8-6 4 6 4V8z"/>',
  };
  const ic = (k, c) => `<svg class="kf-i ${c || ''}" viewBox="0 0 24 24" aria-hidden="true">${I[k] || ''}</svg>`;
  const CSS = `
.kf{--ln:#eceef2;--ln2:#e5e7eb;--mut:#6b7280;--ink:#0b1220;--g:#059669;--gb:#ecfdf5;font-family:Inter,Manrope,system-ui,sans-serif;color:var(--ink)}
.kf-i{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none;vertical-align:middle}.kf-i.s{width:14px;height:14px}.kf-i.l{width:18px;height:18px}
.kf-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--ln2);background:#fff;border-radius:8px;padding:7px 11px;font:inherit;font-weight:600;font-size:12.5px;white-space:nowrap;color:#1f2937;cursor:pointer;box-shadow:0 1px 1px rgba(0,0,0,.03)}
.kf-btn:hover{background:#f9fafb}.kf-btn.p{background:var(--g);color:#fff;border-color:var(--g)}.kf-btn.d{background:#0b1220;color:#fff;border-color:#0b1220}.kf-btn.r{color:#b91c1c}.kf-btn.ic{padding:7px}.kf-btn:disabled{opacity:.5;cursor:default}
.kf-pill{display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:2px 8px;font-size:11.5px;font-weight:600;background:#f3f4f6;color:#374151}.kf-pill.g{background:var(--gb);color:#047857}.kf-pill.r{background:#fef2f2;color:#b91c1c}.kf-pill.w{background:#fffbeb;color:#92400e}
.kf-sw{width:34px;height:20px;border-radius:999px;background:#d1d5db;position:relative;display:inline-block;vertical-align:middle;cursor:pointer;border:0;padding:0;flex:none}.kf-sw:after{content:'';position:absolute;left:3px;top:3px;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .15s}.kf-sw.on{background:#10b981}.kf-sw.on:after{left:17px}
.kf-trig{display:inline-flex;align-items:center;gap:6px;font-size:12px;background:#f9fafb;border:1px solid var(--ln);border-radius:7px;padding:3px 8px 3px 4px;margin:3px 5px 3px 0;max-width:100%}
.kf-trig i{font-style:normal;font-family:ui-monospace,Menlo,monospace;font-size:11px;font-weight:600;border-radius:4px;padding:1px 5px;flex:none}
.kf-trig code{font-family:ui-monospace,Menlo,monospace;font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.m-exact i{background:#d1fae5;color:#047857}.m-starts i{background:#dbeafe;color:#1d4ed8}.m-contains i{background:#fef3c7;color:#92400e}.m-regex i{background:#ede9fe;color:#6d28d9}.m-button i{background:#fce7f3;color:#be185d}
.kf-wrap{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
@media(max-width:1000px){.kf-wrap{grid-template-columns:minmax(0,1fr)}}
.kf-card{background:#fff;border:1px solid var(--ln);border-radius:12px;padding:14px 16px;margin-bottom:10px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.kf-card.drag{opacity:.4}.kf-card.over{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.kf-card h3{margin:0 0 8px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.kf-prio{width:24px;height:24px;border-radius:7px;background:#f3f4f6;display:grid;place-items:center;font-weight:600;color:#4b5563;font-size:12px;flex:none}
.kf-grip{color:#c4c9d2;cursor:grab}
.kf-stat{display:flex;flex-wrap:wrap;gap:18px;margin-top:10px;color:var(--mut);font-size:11.5px;align-items:flex-end}.kf-stat b{color:var(--ink);font-size:15px;display:block;font-weight:600}
.kf-side h4{margin:0 0 10px;font-size:12px;font-weight:600;color:#374151;display:flex;align-items:center;gap:7px}
.kf-in{border:1px solid var(--ln2);border-radius:8px;padding:8px 10px;background:#fff;width:100%;font:inherit;font-size:13px;color:#111827;box-sizing:border-box}
textarea.kf-in{resize:vertical;min-height:70px;line-height:1.4}
.kf-res{margin-top:8px;border-radius:8px;padding:10px;font-size:12.5px;display:flex;gap:8px;line-height:1.4}.kf-res.ok{background:var(--gb);color:#065f46}.kf-res.no{background:#f3f4f6;color:#374151}.kf-res.w{background:#fffbeb;color:#92400e}
.kf-order div{display:flex;gap:8px;align-items:flex-start;padding:4px 0;font-size:12px;line-height:1.45;color:#374151}.kf-order .n{width:18px;height:18px;border-radius:5px;background:#f3f4f6;display:grid;place-items:center;font-size:11px;font-weight:600;flex:none}
.kf-empty{text-align:center;padding:40px 16px;color:var(--mut)}.kf-empty b{display:block;color:var(--ink);font-size:15px;margin:10px 0 4px}
/* overlay canvas */
.kf-ov{position:fixed;inset:0;z-index:9000;background:#fafbfc;display:flex;flex-direction:column}
.kf-top{background:#fff;border-bottom:1px solid var(--ln);padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.kf-top .nm{font-size:15px;font-weight:700;border:1px solid transparent;border-radius:7px;padding:4px 6px;min-width:120px;max-width:360px;font-family:inherit;color:var(--ink);background:transparent}.kf-top .nm:hover,.kf-top .nm:focus{border-color:var(--ln2);background:#fff;outline:0}
.kf-top .sp{flex:1}.kf-bulb{position:relative}.kf-bulb em{position:absolute;top:-6px;right:-6px;background:#f59e0b;color:#fff;font-style:normal;border-radius:999px;font-size:11px;font-weight:700;padding:1px 5px;border:2px solid #fff}
.kf-cv{position:relative;flex:1;overflow:hidden;background:#fafbfc;background-image:radial-gradient(#dfe3e9 1px,transparent 1px);background-size:20px 20px;touch-action:none;cursor:grab}.kf-cv.pan{cursor:grabbing}
.kf-layer{position:absolute;left:0;top:0;transform-origin:0 0}
.kf-lines{position:absolute;left:0;top:0;width:1px;height:1px;overflow:visible;pointer-events:none}
.kf-pal{position:absolute;left:14px;top:14px;width:200px;background:#fff;border:1px solid var(--ln);border-radius:12px;padding:10px;box-shadow:0 8px 24px rgba(16,24,40,.06);z-index:5;max-height:calc(100% - 28px);overflow:auto}
.kf-pal h4{margin:2px 4px 8px;font-size:11px;color:var(--mut);font-weight:600}
.kf-pi{display:flex;align-items:center;gap:9px;padding:6px;border-radius:8px;font-weight:500;font-size:12.5px;color:#1f2937;cursor:grab;user-select:none;touch-action:none}.kf-pi:hover{background:#f3f4f6}
.kf-pal hr{border:0;border-top:1px solid #f1f2f4;margin:6px 4px}
.kf-tile{width:26px;height:26px;border-radius:7px;display:grid;place-items:center;flex:none}
.t-msg{background:#eff6ff;color:#2563eb}.t-img{background:#f5f3ff;color:#7c3aed}.t-doc{background:#fef2f2;color:#dc2626}.t-list{background:#ecfeff;color:#0891b2}.t-url{background:#f0fdfa;color:#0d9488}.t-tpl{background:#fff7ed;color:#ea580c}.t-shop{background:#fdf2f8;color:#db2777}.t-hand{background:#fef2f2;color:#e11d48}.t-goto{background:#f3f4f6;color:#4b5563}.t-tag{background:#fefce8;color:#a16207}.t-start{background:#ecfdf5;color:#059669}
.kf-node{position:absolute;width:250px;background:#fff;border:1px solid var(--ln2);border-radius:12px;box-shadow:0 8px 20px rgba(16,24,40,.06);user-select:none}
.kf-node.sel{border-color:#3b82f6;box-shadow:0 0 0 4px rgba(59,130,246,.15),0 8px 20px rgba(16,24,40,.08)}
.kf-node.start{border-color:#a7f3d0;box-shadow:0 0 0 4px rgba(16,185,129,.08),0 8px 20px rgba(16,24,40,.06)}.kf-node.start.sel{border-color:#10b981}
.kf-node.hot{box-shadow:0 0 0 4px rgba(59,130,246,.35)}
.kf-nh{padding:8px 10px;font-weight:600;font-size:12.5px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f1f2f4;cursor:move;touch-action:none}
.kf-nh .kf-tile{width:24px;height:24px}.kf-nh small{margin-left:auto;color:#9ca3af;font-weight:500;font-size:11px;white-space:nowrap}
.kf-nh .dot{width:9px;height:9px;border-radius:50%;background:#ef4444;flex:none;box-shadow:0 0 0 3px #fee2e2}
.kf-nb{padding:9px 10px;font-size:12px;color:#374151;line-height:1.45;word-break:break-word}
.kf-nb .tx{white-space:pre-wrap;max-height:110px;overflow:hidden}.kf-nb .ph{color:#9ca3af;font-style:italic}
.kf-thumb{height:80px;border-radius:8px;background:linear-gradient(135deg,#1e1b4b,#6366f1 60%,#a5b4fc);margin-bottom:8px;display:grid;place-items:center;color:#fff;font-weight:600;font-size:12px;overflow:hidden;background-size:cover;background-position:center}
.kf-thumb.doc{background:#fef2f2;color:#b91c1c;height:46px;display:flex;gap:8px;justify-content:flex-start;padding:0 10px}
.kf-out{position:relative;display:flex;align-items:center;gap:7px;border:1px solid var(--ln2);background:#fff;color:#1d4ed8;border-radius:8px;padding:6px 9px;margin-top:6px;font-weight:600;font-size:12px}
.kf-out .kf-i{color:#93a3b8}.kf-out.then{color:#047857}.kf-out.url{color:#0f766e}.kf-out.row{color:#0e7490;font-weight:500}.kf-out.none{color:#b91c1c;border-style:dashed}
.kf-port{position:absolute;right:-19px;top:50%;margin-top:-7px;width:14px;height:14px;border-radius:50%;background:#fff;border:2px solid #3b82f6;cursor:crosshair;touch-action:none;z-index:2}
.kf-port.g{border-color:#10b981}.kf-port.on{background:#3b82f6}.kf-port.g.on{background:#10b981}
.kf-port:hover{transform:scale(1.25)}
.kf-inp{position:absolute;left:-7px;top:16px;width:12px;height:12px;border-radius:50%;background:#fff;border:2px solid #cbd5e1}
.kf-pills{margin-top:6px;display:flex;flex-wrap:wrap;gap:4px}
.kf-ed{position:absolute;right:14px;top:14px;bottom:14px;width:320px;background:#fff;border:1px solid var(--ln);border-radius:12px;box-shadow:0 8px 24px rgba(16,24,40,.08);z-index:5;display:flex;flex-direction:column}
.kf-ed .hd{padding:12px 14px;border-bottom:1px solid #f1f2f4;display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px}
.kf-ed .bd{padding:4px 14px 14px;overflow:auto;flex:1}
.kf-ed label.l{display:block;font-size:11.5px;font-weight:600;color:var(--mut);margin:12px 0 5px}.kf-ed label.l span{font-weight:500;color:#9ca3af}
.kf-seg{display:flex;gap:2px;background:#f3f4f6;border-radius:8px;padding:2px}.kf-seg button{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;border:0;background:transparent;border-radius:6px;padding:6px 4px;font:inherit;font-size:12px;font-weight:500;color:#4b5563;cursor:pointer}.kf-seg button.on{background:#fff;color:var(--ink);font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.08)}
.kf-brow{display:grid;grid-template-columns:minmax(0,1fr) 118px 28px;gap:5px;margin-bottom:6px;align-items:center}
.kf-brow select{padding:7px 6px;font-size:12px}
.kf-x{border:0;background:none;color:#9ca3af;cursor:pointer;padding:4px;border-radius:6px}.kf-x:hover{background:#f3f4f6;color:#b91c1c}
.kf-errs{background:#fef2f2;color:#991b1b;border-radius:8px;padding:8px 10px;font-size:12px;margin-top:10px;line-height:1.45}
.kf-cnt{font-size:11px;color:#9ca3af;text-align:right;margin-top:2px}.kf-cnt.bad{color:#dc2626}
.kf-hint{font-size:11.5px;color:var(--mut);margin-top:6px;line-height:1.45}
.kf-zoom{position:absolute;left:14px;bottom:14px;z-index:5;display:flex;gap:2px;background:#fff;border:1px solid var(--ln);border-radius:9px;padding:3px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.kf-zoom button{border:0;background:none;padding:5px 8px;border-radius:6px;font:inherit;font-weight:600;font-size:12px;display:flex;align-items:center;color:#374151;cursor:pointer}.kf-zoom button:hover{background:#f3f4f6}
.kf-menu{position:absolute;z-index:20;background:#fff;border:1px solid var(--ln);border-radius:10px;box-shadow:0 12px 30px rgba(16,24,40,.16);padding:6px;width:210px}
.kf-menu button{display:flex;width:100%;align-items:center;gap:9px;border:0;background:none;padding:6px;border-radius:7px;font:inherit;font-size:12.5px;cursor:pointer;text-align:left}.kf-menu button:hover{background:#f3f4f6}
.kf-tips{position:absolute;right:14px;top:14px;width:320px;max-height:calc(100% - 28px);overflow:auto;background:#fff;border:1px solid var(--ln);border-radius:12px;box-shadow:0 12px 30px rgba(16,24,40,.14);z-index:7;padding:12px}
.kf-tip{border:1px solid #f1f2f4;border-radius:9px;padding:9px 10px;margin-top:8px;font-size:12.5px;line-height:1.45}.kf-tip .a{display:flex;gap:6px;margin-top:6px}
.kf-phone{width:340px;max-width:100%;height:600px;max-height:75vh;margin:0 auto;border-radius:28px;border:8px solid #0b1220;background:#efe7dd;display:flex;flex-direction:column;overflow:hidden}
.kf-phone .ms{flex:1;overflow:auto;padding:10px;display:flex;flex-direction:column;gap:6px}
.kf-b{max-width:82%;padding:7px 9px;border-radius:9px;font-size:13px;line-height:1.4;white-space:pre-wrap;word-break:break-word;box-shadow:0 1px 1px rgba(0,0,0,.08)}
.kf-b.in{align-self:flex-end;background:#d9fdd3}.kf-b.out{align-self:flex-start;background:#fff}.kf-b.sys{align-self:center;background:#fff8c5;font-size:11.5px;color:#5c4a00}
.kf-b .bt{display:block;width:100%;margin-top:6px;border:0;border-top:1px solid #eef0f2;background:none;color:#027eb5;font:inherit;font-weight:600;padding:6px 0 0;cursor:pointer;text-align:center}
.kf-b .md{height:90px;border-radius:6px;margin:-2px -2px 6px;background:linear-gradient(135deg,#1e1b4b,#6366f1);display:grid;place-items:center;color:#fff;font-size:12px;background-size:cover;background-position:center}
.kf-phone .bar{display:flex;gap:6px;padding:8px;background:#f0f2f5}.kf-phone .bar input{flex:1;border:0;border-radius:18px;padding:9px 12px;font:inherit;font-size:13px}
.kf-phone .bar button{width:38px;height:38px;border-radius:50%;border:0;background:#00a884;color:#fff;display:grid;place-items:center;cursor:pointer}
@media(max-width:899px){
  .kf-pal{display:none}.kf-addfab{display:flex!important}
  .kf-ed{left:0;right:0;top:auto;bottom:0;width:auto;height:58%;border-radius:14px 14px 0 0}
  .kf-tips{left:8px;right:8px;width:auto}
  .kf-top .nm{max-width:150px}.kf-top .lbl{display:none}
  .kf-cv.edopen .kf-addfab,.kf-cv.edopen .kf-zoom{bottom:calc(58% + 12px)}
}
.wp.wp-md{z-index:9600!important}.wp-toast{z-index:9700!important}
.kf-addfab{display:none;position:absolute;right:14px;bottom:14px;z-index:5;border:0;border-radius:999px;background:#059669;color:#fff;padding:11px 15px;font:inherit;font-weight:700;gap:6px;align-items:center;box-shadow:0 8px 20px rgba(5,150,105,.35)}
`;
  if (!document.getElementById('kfCss')) { const s = document.createElement('style'); s.id = 'kfCss'; s.textContent = CSS; document.head.appendChild(s); }

  // ------------------------------------------------------------------------------------------------------------ shared rules (same as the server)
  const loose = (s) => String(s || '').toLowerCase().replace(/[‘’]/g, "'").replace(/[​-‍﻿]/g, '').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().replace(/\s+/g, ' ');
  const rxOk = (p) => { if (!p || p.length > 200) return false; try { new RegExp(p, 'iu'); return true; } catch (e) { return false; } };
  function trigHit(t, text, btn) {
    const v = String(t.v || ''); if (!v) return false; if (t.m === 'button') return !!btn && loose(btn) === loose(v);
    const raw = btn || text; if (!String(raw).trim()) return false;
    if (t.m === 'regex') { try { return new RegExp(v, 'iu').test(String(raw).trim()); } catch (e) { return false; } }
    const a = loose(raw), b = loose(v); if (!b) return false;
    if (t.m === 'exact') return a === b; if (t.m === 'starts') return a === b || a.indexOf(b + ' ') === 0;
    if (t.m === 'contains') return (' ' + a + ' ').indexOf(' ' + b + ' ') >= 0; return false;
  }
  const MT = { exact: 'EXACT', starts: 'STARTS', contains: 'CONTAINS', regex: 'REGEX', button: 'BUTTON' };
  const MHELP = { exact: 'the whole message is this text (capitals, spaces and punctuation ignored) — best for website pre-filled texts', starts: 'the message starts with these words', contains: 'these words appear anywhere in the message (whole words)', regex: 'a pattern for advanced matching, e.g. ^best\\s?sellers?$', button: 'the customer tapped a button with this text on a campaign / template' };
  const trigChip = (t) => `<span class="kf-trig m-${esc(t.m)}"><i>${MT[t.m] || t.m}</i><code>${esc(t.v)}</code></span>`;
  // box types: palette entry → node defaults
  const TY = {
    start: { t: 'Start', ic: 'play', c: 't-start' }, text: { t: 'Text', ic: 'msg', c: 't-msg' }, media: { t: 'Media', ic: 'img', c: 't-img' }, list: { t: 'List', ic: 'list', c: 't-list' },
    products: { t: 'Live products', ic: 'bag', c: 't-shop' }, template: { t: 'Template', ic: 'mega', c: 't-tpl' }, handoff: { t: 'Hand to salesperson', ic: 'head', c: 't-hand' }, goto: { t: 'Go to another flow', ic: 'goto', c: 't-goto' }, tag: { t: 'Add tag', ic: 'tag', c: 't-tag' },
  };
  const PAL = [['text', 'msg', 't-msg', 'Text + buttons', { mode: 'buttons' }], ['media', 'img', 't-img', 'Image / video + buttons', { kind: 'image', mode: 'buttons' }], ['media', 'doc', 't-doc', 'Document (PDF)', { kind: 'document', mode: 'none' }],
    ['list', 'list', 't-list', 'List · up to 10', {}], ['text', 'globe', 't-url', 'Text + website button', { mode: 'url' }], '-', ['template', 'mega', 't-tpl', 'Template · after 24 h', {}], ['products', 'bag', 't-shop', 'Live products (Lists)', {}], '-',
    ['handoff', 'head', 't-hand', 'Hand to salesperson', {}], ['goto', 'goto', 't-goto', 'Go to another flow', {}], ['tag', 'tag', 't-tag', 'Add tag', {}]];
  function fresh(type, extra) {
    const b = { text: { text: '', mode: 'buttons', buttons: [{ t: '', to: '' }], url: { t: '', u: 'https://' }, next: '' }, media: { kind: 'image', lib_id: 0, text: '', mode: 'buttons', buttons: [{ t: '', to: '' }], url: { t: '', u: 'https://' }, next: '' },
      list: { header: '', text: '', button: 'Choose', rows: [{ t: '', d: '', to: '' }, { t: '', d: '', to: '' }] }, products: { list_id: 0, text: '', mode: 'none', buttons: [], url: { t: '', u: 'https://' }, next: '' },
      template: { tpl_id: 0, next: '' }, handoff: { text: 'Our team will reply in a few minutes 🙏', alert: true }, goto: { flow_id: 0 }, tag: { tag: '', next: '' } }[type] || {};
    const o = JSON.parse(JSON.stringify(b)); Object.assign(o, extra || {}); if (o.mode === 'url') o.buttons = []; if (o.mode === 'none') o.buttons = []; return o;
  }
  const nid = () => 'n' + Date.now().toString(36).slice(-4) + Math.random().toString(36).slice(2, 5);
  const hasMode = (n) => n.type === 'text' || n.type === 'media' || n.type === 'products';
  /** the dots that leave a box: [{k, label, cls}] */
  function outs(n) {
    if (n.type === 'start') return [{ k: 'next', label: 'then', cls: 'then' }];
    if (hasMode(n)) { if (n.mode === 'buttons') return (n.buttons || []).map((b, i) => ({ k: 'b' + i, label: b.t || 'Button ' + (i + 1), cls: '' })); return [{ k: 'next', label: 'then', cls: 'then' }]; }
    if (n.type === 'list') return (n.rows || []).map((r, i) => ({ k: 'r' + i, label: r.t || 'Row ' + (i + 1), cls: 'row' }));
    if (n.type === 'template' || n.type === 'tag') return [{ k: 'next', label: 'then', cls: 'then' }];
    return [];
  }
  const getTo = (n, k) => (k === 'next' ? n.next || '' : k[0] === 'b' ? ((n.buttons || [])[+k.slice(1)] || {}).to || '' : ((n.rows || [])[+k.slice(1)] || {}).to || '');
  function setTo(n, k, v) { if (k === 'next') n.next = v; else if (k[0] === 'b') { if (n.buttons[+k.slice(1)]) n.buttons[+k.slice(1)].to = v; } else if (n.rows[+k.slice(1)]) n.rows[+k.slice(1)].to = v; }
  /** problems that keep the flow off (same rules as wkf_validate on the server) */
  function validate(G, A, selfId) {
    const E = {}, ids = new Set(G.nodes.map((n) => n.id)), bad = (id, m) => { (E[id] = E[id] || []).push(m); };
    const to = (n, v, w) => { if (!v) bad(n.id, w + ' goes nowhere — connect it to a box.'); else if (!ids.has(v)) bad(n.id, w + ' points to a deleted box.'); };
    const mode = (n) => {
      if (n.mode === 'buttons') { const b = n.buttons || []; if (!b.length) bad(n.id, 'Add at least one button, or choose “None”.'); const seen = {}; b.forEach((x, i) => { const t = (x.t || '').trim(); if (!t) bad(n.id, 'Button ' + (i + 1) + ' has no text.'); else if (t.length > 20) bad(n.id, '“' + t + '” is longer than 20 letters.'); if (seen[t.toLowerCase()]) bad(n.id, 'Two buttons have the same text.'); seen[t.toLowerCase()] = 1; to(n, x.to, 'Button “' + (t || i + 1) + '”'); }); if (!(n.text || '').trim()) bad(n.id, 'A message with buttons needs text.'); }
      else if (n.mode === 'url') { const u = n.url || {}; const t = (u.t || '').trim(); if (!t || t.length > 20) bad(n.id, 'The website button needs a text of 1–20 letters.'); if (!/^https:\/\/\S+\.\S+$/i.test(u.u || '')) bad(n.id, 'The website link must start with https://'); if (!(n.text || '').trim()) bad(n.id, 'A message with a website button needs text.'); }
    };
    G.nodes.forEach((n) => {
      if (n.type === 'start') { const tr = (n.triggers || []).filter((t) => (t.v || '').trim()); if (!tr.length) bad(n.id, 'Add at least one start text.'); tr.forEach((t) => { if (t.m === 'regex' && !rxOk(t.v)) bad(n.id, 'The regex “' + t.v.slice(0, 40) + '” is not valid.'); else if (t.m !== 'regex' && !loose(t.v)) bad(n.id, '“' + t.v + '” has no letters or numbers.'); }); to(n, n.next, 'Start'); }
      else if (n.type === 'text') { if (!(n.text || '').trim()) bad(n.id, 'Type the message.'); if ((n.text || '').length > 1024) bad(n.id, 'The text is longer than 1,024 letters.'); mode(n); }
      else if (n.type === 'media') { const m = (A.media || []).find((x) => x.id === +n.lib_id); if (!m) bad(n.id, 'Choose a photo, video or PDF from the Media library.'); if ((n.text || '').length > 1024) bad(n.id, 'The text is longer than 1,024 letters.'); mode(n); }
      else if (n.type === 'products') { if (!(+n.list_id > 0)) bad(n.id, 'Choose a list from Lists.'); mode(n); }
      else if (n.type === 'list') { const r = n.rows || []; if (!r.length) bad(n.id, 'Add at least one row.'); if (r.length > 10) bad(n.id, 'A list has at most 10 rows.'); if (!(n.text || '').trim()) bad(n.id, 'Type the message above the list.'); const bt = (n.button || '').trim(); if (!bt || bt.length > 20) bad(n.id, 'The list button needs a text of 1–20 letters.');
        r.forEach((x, i) => { const t = (x.t || '').trim(); if (!t) bad(n.id, 'Row ' + (i + 1) + ' has no title.'); else if (t.length > 24) bad(n.id, 'Row “' + t + '” is longer than 24 letters.'); if ((x.d || '').length > 72) bad(n.id, 'Row ' + (i + 1) + ' description is longer than 72 letters.'); to(n, x.to, 'Row “' + (t || i + 1) + '”'); }); }
      else if (n.type === 'template') { if (!(+n.tpl_id > 0)) bad(n.id, 'Choose an approved pre-made message.'); }
      else if (n.type === 'goto') { if (!(+n.flow_id > 0)) bad(n.id, 'Choose the flow to go to.'); else if (+n.flow_id === selfId) bad(n.id, 'A flow cannot go to itself.'); }
      else if (n.type === 'tag') { if (!(n.tag || '').trim()) bad(n.id, 'Type the tag.'); }
    });
    return E;
  }
  let ASSETS = null; const assets = async (fresh0) => { if (!ASSETS || fresh0) { try { ASSETS = await api.get('wa.kf_assets'); } catch (e) { ASSETS = ASSETS || { media: [], templates: [], lists: [], flows: [] }; } } return ASSETS; };
  const BLOBS = {}; async function thumb(id) { if (BLOBS[id]) return BLOBS[id]; BLOBS[id] = (async () => { const r = await fetch(api.buildUrl('wa.lib_file', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error(''); return URL.createObjectURL(await r.blob()); })().catch(() => ''); return BLOBS[id]; }
  function copy(t) { try { navigator.clipboard.writeText(t); toast('Copied'); } catch (e) { prompt('Copy:', t); } }
  let QRP = null; const qrLib = () => QRP || (QRP = new Promise((ok, no) => { if (window.QRCode) return ok(); const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'; s.onload = ok; s.onerror = () => { QRP = null; no(new Error('Could not load the QR maker.')); }; document.head.appendChild(s); }));

  // ============================================================================================================ LIST
  function screen(el, ctx) {
    let D = null, testT = 0;
    async function load() { try { D = await api.get('wa.kf_list'); } catch (e) { el.innerHTML = `<div class="wp"><div class="wp-err">${esc(e.message)}</div></div>`; return; } draw(); }
    function draw() {
      const F = D.flows, on = F.filter((f) => f.active).length;
      el.innerHTML = `<div class="wp kf"><div class="wp-top"><div style="flex:1;min-width:200px"><h2 style="display:flex;align-items:center;gap:8px">${ic('key', 'l')}Key flows</h2><p>Fixed answers for fixed messages — website pre-filled texts, ads and campaign buttons. When a start text matches, the flow answers by itself. Build on PC.</p></div><button type="button" class="kf-btn p" id="kfNew">${ic('plus', 's')}New key flow</button></div>
        <div class="kf-wrap"><div>
          ${F.length ? `<div style="display:flex;align-items:center;gap:8px;margin:0 2px 10px"><b style="font-size:14px;font-weight:600">${F.length} key flow${F.length === 1 ? '' : 's'}</b><span class="kf-pill g">${on} on</span><span style="flex:1"></span><span style="color:#6b7280;font-size:12px">Higher in the list = wins when two flows match · drag to reorder</span></div>` : ''}
          <div id="kfL">${F.length ? F.map(card).join('') : `<div class="kf-card kf-empty">${ic('key', 'l')}<b>No key flows yet</b>Make your first one: type the pre-filled text from your website in the Start box, then add the messages and buttons.<div style="margin-top:14px"><button type="button" class="kf-btn p" data-new>${ic('plus', 's')}New key flow</button></div></div>`}</div>
        </div><div class="kf-side">
          <div class="kf-card"><h4>${ic('flask', 's')}Test a message</h4><input class="kf-in" id="kfT" placeholder="Type what a customer would send…"><div id="kfTr"></div></div>
          <div class="kf-card"><h4>${ic('alert', 's')}Overlaps</h4>${D.overlaps.length ? D.overlaps.slice(0, 6).map((o) => `<div class="kf-res w">${ic('alert')}<div><b>“${esc(o.text)}”</b> is caught by ${esc(o.a)} and ${esc(o.b)}. <b>${esc(o.winner)}</b> wins — it is higher.</div></div>`).join('') : `<div class="kf-res ok">${ic('check')}<div>No two ON flows catch the same message.</div></div>`}<div class="kf-hint">Two ON flows with the same exact text can’t exist — switching one on is blocked until you change it.</div></div>
          <div class="kf-card"><h4>${ic('link', 's')}Website link</h4><select class="kf-in" id="kfLk"><option value="">Choose a flow…</option>${F.map((f) => `<option value="${f.id}">${esc(f.name)}</option>`).join('')}</select><div id="kfLr"></div></div>
          <div class="kf-card"><h4>${ic('zap', 's')}Who answers first</h4><div class="kf-order">
            <div><span class="n">1</span>Complaint chat or opted out → nothing automatic</div><div><span class="n">2</span>Tap on a key-flow button or list row (even an old message) → its next box</div>
            <div><span class="n">3</span>A salesperson wrote to them in the last 30 min → nothing automatic</div><div><span class="n">4</span>Start text matches → that flow (top of the list first)</div><div><span class="n">5</span>Nothing matched → old flows, greetings and Claude, as today</div></div></div>
        </div></div></div>`;
      el.querySelectorAll('#kfNew,[data-new]').forEach((b) => { b.onclick = newFlow; });
      el.querySelectorAll('[data-open]').forEach((b) => { b.onclick = () => openCanvas(+b.dataset.open, load); });
      el.querySelectorAll('[data-sw]').forEach((b) => { b.onclick = async () => { const f = F.find((x) => x.id === +b.dataset.sw); b.disabled = true; try { await api.post('wa.kf_toggle', { id: f.id, active: !f.active }); toast(f.active ? 'Switched off' : 'Switched on ✓'); load(); } catch (e) { toast(e.message, true); b.disabled = false; } }; });
      el.querySelectorAll('[data-more]').forEach((b) => { b.onclick = () => more(F.find((x) => x.id === +b.dataset.more)); });
      const T = $('#kfT'); T.oninput = () => { clearTimeout(testT); const v = T.value.trim(); if (!v) { $('#kfTr').innerHTML = ''; return; } testT = setTimeout(async () => { try { const r = await api.get('wa.kf_test', { text: v }); const w = r.winner; const off = r.all.filter((x) => !x.active);
        $('#kfTr').innerHTML = (w ? `<div class="kf-res ok">${ic('check')}<div><b>${esc(w.name)}</b> answers<br>${MT[w.m]} “${esc(w.v)}”</div></div>` : `<div class="kf-res no">${ic('x')}<div>No key flow answers this — the old flows, greetings and Claude handle it.</div></div>`) + (off.length ? `<div class="kf-hint">Also matches (switched off): ${off.map((x) => esc(x.name)).join(', ')}</div>` : ''); } catch (e) { } }, 300); };
      $('#kfLk').onchange = async function () { const box = $('#kfLr'); if (!this.value) { box.innerHTML = ''; return; } box.innerHTML = '<div class="kf-hint">Loading…</div>';
        try { const r = await api.get('wa.kf_link', { id: this.value }); box.innerHTML = !r.text ? '<div class="kf-res w">' + ic('alert') + '<div>This flow has no EXACT or STARTS text to pre-fill.</div></div>' : r.links.map((l, i) => `<div style="margin-top:8px"><div class="kf-hint" style="margin:0 0 3px">${esc(l.label)}</div><div style="display:flex;gap:6px"><input class="kf-in" readonly value="${esc(l.url)}" style="font-size:12px"><button type="button" class="kf-btn ic" data-cp="${i}" title="Copy">${ic('copy', 's')}</button><button type="button" class="kf-btn ic" data-qr="${i}" title="QR code">${ic('qr', 's')}</button></div></div>`).join('');
          box.querySelectorAll('[data-cp]').forEach((b) => { b.onclick = () => copy(r.links[+b.dataset.cp].url); });
          box.querySelectorAll('[data-qr]').forEach((b) => { b.onclick = async () => { const l = r.links[+b.dataset.qr]; const m = modal('QR code · ' + l.label); m.body.innerHTML = '<div style="display:grid;place-items:center;padding:10px"><div id="kfQr"></div><div class="kf-hint" style="text-align:center">Scanning opens WhatsApp with “' + esc(r.text) + '” typed in.</div></div>'; try { await qrLib(); new window.QRCode(m.body.querySelector('#kfQr'), { text: l.url, width: 220, height: 220 }); } catch (e) { m.body.innerHTML = '<div class="wp-err">' + esc(e.message) + '</div>'; } }; });
        } catch (e) { box.innerHTML = '<div class="wp-err">' + esc(e.message) + '</div>'; } };
      // drag to reorder (PC)
      let dragId = 0; el.querySelectorAll('.kf-card[data-id]').forEach((c) => {
        c.ondragstart = (e) => { dragId = +c.dataset.id; c.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; }; c.ondragend = () => { c.classList.remove('drag'); el.querySelectorAll('.over').forEach((x) => x.classList.remove('over')); };
        c.ondragover = (e) => { e.preventDefault(); c.classList.add('over'); }; c.ondragleave = () => c.classList.remove('over');
        c.ondrop = (e) => { e.preventDefault(); const to = +c.dataset.id; if (!dragId || dragId === to) return; const ids = F.map((f) => f.id).filter((x) => x !== dragId); ids.splice(ids.indexOf(to), 0, dragId); reorder(ids); };
      });
    }
    const $ = (q) => el.querySelector(q);
    function card(f, i) {
      const s = f.stats;
      return `<div class="kf-card" data-id="${f.id}" draggable="true"><h3><span class="kf-grip">${ic('grip')}</span><span class="kf-prio">${i + 1}</span>${esc(f.name)}${f.errors ? `<span class="kf-pill r"><span style="width:7px;height:7px;border-radius:50%;background:#ef4444;display:inline-block"></span>${f.errors} problem${f.errors === 1 ? '' : 's'}</span>` : ''}<span style="flex:1"></span><button type="button" class="kf-sw ${f.active ? 'on' : ''}" data-sw="${f.id}" aria-label="On / off" title="${f.active ? 'On' : 'Off'}"></button></h3>
        <div>${f.triggers.length ? f.triggers.slice(0, 6).map(trigChip).join('') + (f.triggers.length > 6 ? `<span class="kf-pill">+${f.triggers.length - 6}</span>` : '') : '<span class="kf-pill w">No start text yet</span>'}</div>
        <div class="kf-stat"><div><b>${fmt(s.started)}</b>started · 7 d</div><div><b>${fmt(s.taps)}</b>button taps</div><div><b>${s.tapped_pct}%</b>tapped</div><div><b>${fmt(s.orders)}</b>ordered after</div><div><b>${f.boxes}</b>boxes</div>${s.errors ? `<div style="color:#b91c1c"><b style="color:#b91c1c">${s.errors}</b>not sent</div>` : ''}<span style="flex:1"></span><button type="button" class="kf-btn" data-open="${f.id}">${ic('flow', 's')}Open canvas</button><button type="button" class="kf-btn ic" data-more="${f.id}" aria-label="More">${ic('more', 's')}</button></div></div>`;
    }
    async function reorder(ids) { try { await api.post('wa.kf_order', { ids }); load(); } catch (e) { toast(e.message, true); } }
    function more(f) {
      const m = modal(f.name); const i = D.flows.indexOf(f);
      m.body.innerHTML = `<div class="kf" style="display:grid;gap:6px">${i > 0 ? `<button type="button" class="kf-btn" data-a="up">${ic('up', 's')}Move up (higher priority)</button>` : ''}${i < D.flows.length - 1 ? `<button type="button" class="kf-btn" data-a="down">${ic('down', 's')}Move down</button>` : ''}<button type="button" class="kf-btn" data-a="copy">${ic('copy', 's')}Make a copy</button><button type="button" class="kf-btn r" data-a="del">${ic('trash', 's')}Delete</button></div>`;
      m.body.querySelectorAll('[data-a]').forEach((b) => { b.onclick = async () => { const a = b.dataset.a; const ids = D.flows.map((x) => x.id);
        if (a === 'up' || a === 'down') { ids.splice(i, 1); ids.splice(a === 'up' ? i - 1 : i + 1, 0, f.id); m.close(); reorder(ids); return; }
        if (a === 'copy') { try { await api.post('wa.kf_copy', { id: f.id }); m.close(); toast('Copied — the copy is off'); load(); } catch (e) { toast(e.message, true); } return; }
        if (a === 'del' && confirm('Delete “' + f.name + '”? Customers who write its start text will no longer get it.')) { try { await api.post('wa.kf_delete', { id: f.id }); m.close(); toast('Deleted'); load(); } catch (e) { toast(e.message, true); } } }; });
    }
    function newFlow() {
      const m = modal('New key flow'); m.body.innerHTML = `<div class="kf"><label class="wp-fl" style="margin-top:0">Name</label><input class="kf-in" id="kfN" maxlength="80" placeholder="e.g. Best sellers — website button"><div class="kf-hint">Only you see the name. Next you type the customer’s start text and build the messages.</div><div id="kfE"></div><div style="margin-top:12px"><button type="button" class="kf-btn p" id="kfGo">Create and open canvas</button></div></div>`;
      const go = async () => { const name = m.body.querySelector('#kfN').value.trim(); if (!name) return; try { const r = await api.post('wa.kf_save', { name, graph: { nodes: [{ id: 'start', type: 'start', x: 120, y: 140, triggers: [{ m: 'exact', v: '' }], next: '' }] } }); m.close(); openCanvas(r.id, load); } catch (e) { m.body.querySelector('#kfE').innerHTML = '<div class="wp-err">' + esc(e.message) + '</div>'; } };
      m.body.querySelector('#kfGo').onclick = go; m.body.querySelector('#kfN').onkeydown = (e) => { if (e.key === 'Enter') go(); }; setTimeout(() => m.body.querySelector('#kfN').focus(), 50);
    }
    load(); return load;
  }

  // ============================================================================================================ CANVAS
  async function openCanvas(id, onClose) {
    const ov = document.createElement('div'); ov.className = 'kf kf-ov'; ov.innerHTML = '<div class="kf-empty" style="margin:auto">Loading…</div>'; document.body.appendChild(ov);
    let R, A; try { [R, A] = await Promise.all([api.get('wa.kf_get', { id }), assets(true)]); } catch (e) { ov.remove(); toast(e.message, true); return; }
    const F = R.flow; const G = { nodes: F.graph.nodes }; const NS = R.node_stats || {};
    const S = { sel: '', view: { x: 0, y: 0, z: 1 }, dirty: false, want: F.active, active: F.active, name: F.name, srv: F.problems || {}, hard: R.hard || [], soft: R.soft || [], tips: false };
    const DIS = (() => { try { return new Set(JSON.parse(localStorage.getItem('kf_dis_' + id) || '[]')); } catch (e) { return new Set(); } })();
    ov.innerHTML = `<div class="kf-top"><button type="button" class="kf-btn" data-a="back">${ic('left', 's')}<span class="lbl">Key flows</span></button><input class="nm" id="kfNm" maxlength="80" value="${esc(S.name)}" aria-label="Flow name"><span id="kfSt"></span><span class="sp"></span>
        <button type="button" class="kf-btn ic kf-bulb" data-a="tips" title="Suggestions">${ic('bulb')}<em id="kfBn" hidden></em></button><button type="button" class="kf-btn" data-a="test">${ic('play', 's')}<span class="lbl">Test in a phone</span></button>
        <span style="font-weight:600;font-size:12px;color:#374151" class="lbl" id="kfOnL">Off</span><button type="button" class="kf-sw" id="kfOn" aria-label="On / off"></button><button type="button" class="kf-btn d" data-a="save">Save</button></div>
      <div class="kf-cv" id="kfCv"><div class="kf-layer" id="kfLy"><svg class="kf-lines" id="kfLn"><defs><marker id="kfAr" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 10 5 0 10z" fill="#94a3b8"/></marker></defs><g id="kfLg"></g><path id="kfTmp" fill="none" stroke="#3b82f6" stroke-width="2" stroke-dasharray="5 4"/></svg><div id="kfNodes"></div></div>
        <div class="kf-pal"><h4>Drag a box in — or click</h4>${PAL.map((p, i) => (p === '-' ? '<hr>' : `<div class="kf-pi" data-pi="${i}"><span class="kf-tile ${p[2]}">${ic(p[1], 's')}</span>${esc(p[3])}</div>`)).join('')}</div>
        <div class="kf-zoom"><button type="button" data-z="-">${ic('minus', 's')}</button><button type="button" data-z="0" id="kfZl">100%</button><button type="button" data-z="+">${ic('plus', 's')}</button><button type="button" data-z="fit" title="Fit">${ic('max', 's')}</button></div>
        <button type="button" class="kf-addfab" data-a="add">${ic('plus', 's')}Add box</button>
        <div id="kfEd"></div><div id="kfTips"></div></div>`;
    const $ = (q) => ov.querySelector(q), cv = $('#kfCv'), ly = $('#kfLy');
    const node = (nid0) => G.nodes.find((n) => n.id === nid0);
    const start = () => G.nodes.find((n) => n.type === 'start');
    const problems = () => { const E = validate(G, A, id); return E; };
    const mark = () => { S.dirty = true; status(); };
    function status() {
      const E = problems(), n = Object.values(E).reduce((a, b) => a + b.length, 0);
      $('#kfSt').innerHTML = (S.dirty ? '<span class="kf-pill w">Not saved</span>' : `<span class="kf-pill g">${ic('check', 's')}Saved</span>`) + (n ? ` <span class="kf-pill r">${n} problem${n === 1 ? '' : 's'}</span>` : '');
      $('#kfOn').classList.toggle('on', S.want); $('#kfOnL').textContent = S.want ? 'On' : 'Off';
      const t = tips().length; const bn = $('#kfBn'); bn.hidden = !t; bn.textContent = t;
    }
    // ---------------------------------------------------------------------------------------------------------- view
    const applyView = () => { ly.style.transform = `translate(${S.view.x}px,${S.view.y}px) scale(${S.view.z})`; $('#kfZl').textContent = Math.round(S.view.z * 100) + '%'; };
    const toWorld = (cx, cy) => { const r = cv.getBoundingClientRect(); return { x: (cx - r.left - S.view.x) / S.view.z, y: (cy - r.top - S.view.y) / S.view.z }; };
    function fit() {
      const r = cv.getBoundingClientRect(); if (!G.nodes.length) return; const xs = G.nodes.map((n) => n.x), ys = G.nodes.map((n) => n.y);
      const minX = Math.min(...xs) - 30, minY = Math.min(...ys) - 30, maxX = Math.max(...xs) + 290, maxY = Math.max(...ys) + 260;
      const padL = r.width >= 900 ? 230 : 10, padR = r.width >= 900 ? 350 : 10, w = Math.max(200, r.width - padL - padR), h = r.height - 20;
      S.view.z = Math.max(0.35, Math.min(1, Math.min(w / (maxX - minX), h / (maxY - minY)))); S.view.x = padL + (w - (maxX - minX) * S.view.z) / 2 - minX * S.view.z; S.view.y = 10 + Math.max(0, (h - (maxY - minY) * S.view.z) / 2) - minY * S.view.z; applyView();
    }
    function zoomAt(f, cx, cy) { const r = cv.getBoundingClientRect(); const px = cx - r.left, py = cy - r.top; const z = Math.max(0.3, Math.min(1.6, S.view.z * f)); S.view.x = px - (px - S.view.x) * z / S.view.z; S.view.y = py - (py - S.view.y) * z / S.view.z; S.view.z = z; applyView(); }
    // ---------------------------------------------------------------------------------------------------------- boxes
    const targetLabel = (t) => { const n = node(t); if (!n) return ''; return TY[n.type].t + (n.text ? ' · ' + String(n.text).slice(0, 18) : n.header ? ' · ' + n.header.slice(0, 18) : ''); };
    function body(n) {
      const st = NS[n.id] || {}; const tx = (t, ph) => (t && String(t).trim() ? `<div class="tx">${esc(t)}</div>` : `<div class="ph">${ph}</div>`);
      const outsH = () => outs(n).map((o) => { const t = getTo(n, o.k); const tap = st.taps && st.taps[o.k.replace('b', '').replace('r', 'r')] ; return `<div class="kf-out ${o.cls} ${t ? '' : 'none'}">${ic(o.cls === 'then' ? 'arrow' : o.cls === 'row' ? 'list' : 'reply', 's')}<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(o.label)}</span>${tap ? `<span class="kf-pill" style="margin-left:auto">${fmt(tap)}</span>` : ''}<span class="kf-port ${o.cls === 'then' ? 'g' : ''} ${t ? 'on' : ''}" data-port="${n.id}|${o.k}"></span></div>`; }).join('');
      if (n.type === 'start') { const tr = (n.triggers || []).filter((t) => (t.v || '').trim()); return (tr.length ? tr.map(trigChip).join('') : '<div class="ph">Click to type the start text</div>') + (S.hard.length ? `<div style="margin-top:6px;color:#b91c1c;font-weight:600;display:flex;gap:5px;align-items:center">${ic('alert', 's')}Same start text as “${esc(S.hard[0].b)}”</div>` : tr.length ? `<div style="margin-top:6px;color:#047857;font-weight:600;display:flex;gap:5px;align-items:center">${ic('check', 's')}${S.soft.length ? S.soft.length + ' overlap' + (S.soft.length === 1 ? '' : 's') + ' — see 💡' : 'No conflict with other flows'}</div>` : '') + outsH(); }
      if (n.type === 'text') return tx(n.text, 'Type the message…') + (n.mode === 'url' ? `<div class="kf-out url">${ic('globe', 's')}${esc((n.url || {}).t || 'Website button')}${ic('ext', 's')}</div>` : '') + outsH();
      if (n.type === 'media') { const m = (A.media || []).find((x) => x.id === +n.lib_id); const k = m ? m.kind : n.kind; return `<div class="kf-thumb ${k === 'document' ? 'doc' : ''}" data-th="${m && m.kind === 'image' ? m.id : ''}">${k === 'document' ? ic('doc') + esc(m ? m.title : 'Choose a PDF') : k === 'video' ? ic('video', 'l') + ' ' + esc(m ? m.title : 'Choose a video') : (m ? '' : 'Choose a photo')}</div>` + tx(n.text, n.mode === 'buttons' || n.mode === 'url' ? 'Type the text…' : 'Caption (optional)') + (n.mode === 'url' ? `<div class="kf-out url">${ic('globe', 's')}${esc((n.url || {}).t || 'Website button')}${ic('ext', 's')}</div>` : '') + outsH(); }
      if (n.type === 'list') return (n.header ? `<b>${esc(n.header)}</b>` : '') + tx(n.text, 'Type the message above the list…') + `<div class="kf-pills"><span class="kf-pill">${ic('list', 's')}${esc(n.button || 'Choose')}</span></div>` + outsH();
      if (n.type === 'products') { const l = (A.lists || []).find((x) => x.id === +n.list_id); return (n.text ? tx(n.text, '') : '') + `<div class="kf-pills"><span class="kf-pill" style="background:#fdf2f8;color:#be185d">${ic('bag', 's')}${esc(l ? l.name : 'Choose a list')}</span></div><div class="kf-hint" style="margin-top:4px">Always the list’s current products from Shopify.</div>` + (n.mode === 'url' ? `<div class="kf-out url">${ic('globe', 's')}${esc((n.url || {}).t || 'Website button')}</div>` : '') + outsH(); }
      if (n.type === 'template') { const t = (A.templates || []).find((x) => x.id === +n.tpl_id); return (t ? `<b>${esc(t.name)}</b><div class="tx" style="color:#6b7280">${esc(t.body)}</div>` : '<div class="ph">Choose an approved pre-made message</div>') + '<div class="kf-hint">Works even after 24 hours.</div>' + outsH(); }
      if (n.type === 'handoff') return tx(n.text, 'No message — just give the chat to a person') + `<div class="kf-pills"><span class="kf-pill">Assign · least busy</span>${n.alert ? '<span class="kf-pill r">Call-now alert</span>' : ''}</div>`;
      if (n.type === 'goto') { const f = (A.flows || []).find((x) => x.id === +n.flow_id); return f ? `<div style="display:flex;gap:6px;align-items:center">${ic('goto', 's')}<b>${esc(f.name)}</b></div>${f.active ? '' : '<div class="kf-hint" style="color:#b45309">That flow is off — nothing will be sent.</div>'}` : '<div class="ph">Choose the flow</div>'; }
      if (n.type === 'tag') return `<div class="kf-pills"><span class="kf-pill" style="background:#fefce8;color:#a16207">${ic('tag', 's')}${esc(n.tag || 'type a tag')}</span></div>` + outsH();
      return '';
    }
    function sub(n) { const st = NS[n.id]; if (n.type === 'start') return st && st.start ? fmt(st.start) + ' started' : 'when the customer writes'; if (n.type === 'media') return ({ image: 'Image', video: 'Video', document: 'PDF' })[((A.media || []).find((x) => x.id === +n.lib_id) || {}).kind || n.kind] || ''; if (st && st.sent) return fmt(st.sent) + ' sent'; if (hasMode(n) && n.mode === 'buttons') return (n.buttons || []).length + ' / 3'; if (n.type === 'list') return (n.rows || []).length + ' / 10'; return ''; }
    function title(n) { if (n.type === 'text') return n.mode === 'url' ? 'Text + website' : n.mode === 'buttons' ? 'Text + buttons' : 'Text'; if (n.type === 'media') return (n.mode === 'buttons' ? 'Media + buttons' : n.mode === 'url' ? 'Media + website' : 'Media'); return TY[n.type].t; }
    function drawNodes() {
      const E = problems(); Object.keys(S.srv || {}).forEach((k) => { if (!E[k] && !S.dirty) E[k] = S.srv[k]; });
      $('#kfNodes').innerHTML = G.nodes.map((n) => { const ty = n.type === 'media' && n.kind === 'document' ? { c: 't-doc', ic: 'doc' } : n.type === 'text' && n.mode === 'url' ? { c: 't-url', ic: 'globe' } : TY[n.type];
        return `<div class="kf-node ${n.type === 'start' ? 'start' : ''} ${S.sel === n.id ? 'sel' : ''}" data-id="${n.id}" style="left:${n.x}px;top:${n.y}px">${n.type !== 'start' ? '<span class="kf-inp"></span>' : ''}<div class="kf-nh" data-drag="${n.id}"><span class="kf-tile ${ty.c}">${ic(ty.ic, 's')}</span>${esc(title(n))}${E[n.id] ? '<span class="dot" title="' + esc(E[n.id].join(' · ')) + '"></span>' : ''}<small>${esc(sub(n))}</small></div><div class="kf-nb">${body(n)}</div></div>`; }).join('');
      $('#kfNodes').querySelectorAll('[data-th]').forEach((d) => { const i = +d.dataset.th; if (i) thumb(i).then((u) => { if (u) d.style.backgroundImage = 'url(' + u + ')'; }); });
      requestAnimationFrame(drawLines); status();
    }
    function portXY(p) { const r = p.getBoundingClientRect(), c = cv.getBoundingClientRect(); return { x: (r.left + r.width / 2 - c.left - S.view.x) / S.view.z, y: (r.top + r.height / 2 - c.top - S.view.y) / S.view.z }; }
    const curve = (a, b) => { const dx = Math.max(40, Math.abs(b.x - a.x) / 2); return `M${a.x} ${a.y} C${a.x + dx} ${a.y},${b.x - dx} ${b.y},${b.x} ${b.y}`; };
    function drawLines() {
      let h = ''; $('#kfNodes').querySelectorAll('[data-port]').forEach((p) => { const [fid, k] = p.dataset.port.split('|'); const n = node(fid); if (!n) return; const t = node(getTo(n, k)); if (!t) return;
        const a = portXY(p); const b = { x: t.x - 2, y: t.y + 22 }; h += `<path d="${curve(a, b)}" fill="none" stroke="${S.sel === fid || S.sel === t.id ? '#3b82f6' : '#a5b4c8'}" stroke-width="1.8" marker-end="url(#kfAr)"/>`; });
      $('#kfLg').innerHTML = h;
    }
    function moveNodeEl(n) { const d = $('#kfNodes').querySelector('[data-id="' + n.id + '"]'); if (d) { d.style.left = n.x + 'px'; d.style.top = n.y + 'px'; } }
    function free(at) {   // a new box never lands on top of another one: try below, then the next column
      const hit = (x, y) => G.nodes.some((n) => Math.abs(n.x - x) < 262 && Math.abs(n.y - y) < 190); let x = at.x, y = at.y;
      for (let col = 0; col < 6; col++) { for (let row = 0; row < 5; row++) { if (!hit(x, y + row * 200)) return { x, y: y + row * 200 }; } x += 290; } return at; }
    function add(type, extra, at) { at = free(at); const n = Object.assign({ id: nid(), type, x: Math.round(at.x), y: Math.round(at.y) }, fresh(type, extra)); G.nodes.push(n); S.sel = n.id; mark(); drawNodes(); drawEd(); return n; }
    function visibleCenter() { const r = cv.getBoundingClientRect(); const w = toWorld(r.left + r.width / 2 - 120, r.top + r.height / 3); return { x: w.x + (Math.random() * 40 - 20), y: w.y + (Math.random() * 40 - 20) }; }
    function del(id0) { const n = node(id0); if (!n || n.type === 'start') return; G.nodes = G.nodes.filter((x) => x.id !== id0); G.nodes.forEach((x) => { outs(x).forEach((o) => { if (getTo(x, o.k) === id0) setTo(x, o.k, ''); }); }); if (S.sel === id0) S.sel = ''; mark(); drawNodes(); drawEd(); }
    function dup(id0) { const n = node(id0); if (!n || n.type === 'start') return; const c = JSON.parse(JSON.stringify(n)); c.id = nid(); c.x += 30; c.y += 30; outs(c).forEach((o) => setTo(c, o.k, '')); G.nodes.push(c); S.sel = c.id; mark(); drawNodes(); drawEd(); }
    // ---------------------------------------------------------------------------------------------------------- pointer: pan, drag a box, connect a dot, drag from the palette
    let P = null;
    cv.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 && e.pointerType === 'mouse') return; const t = e.target;
      if (t.closest('.kf-pal,.kf-zoom,.kf-ed,.kf-tips,.kf-menu,.kf-addfab')) { const pi = t.closest('[data-pi]'); if (pi) { P = { k: 'pal', i: +pi.dataset.pi, sx: e.clientX, sy: e.clientY, moved: false }; cv.setPointerCapture(e.pointerId); } return; }
      closeMenu();
      const port = t.closest('[data-port]'); if (port) { const [fid, k] = port.dataset.port.split('|'); P = { k: 'link', fid, key: k, a: portXY(port) }; cv.setPointerCapture(e.pointerId); e.preventDefault(); return; }
      const h = t.closest('[data-drag]'), nd = t.closest('.kf-node');
      if (nd) { const n = node(nd.dataset.id); P = { k: 'node', n, sx: e.clientX, sy: e.clientY, ox: n.x, oy: n.y, moved: false, viaHead: !!h }; cv.setPointerCapture(e.pointerId); return; }
      P = { k: 'pan', sx: e.clientX, sy: e.clientY, ox: S.view.x, oy: S.view.y, moved: false }; cv.classList.add('pan'); cv.setPointerCapture(e.pointerId);
    });
    cv.addEventListener('pointermove', (e) => {
      if (!P) return; const dx = e.clientX - P.sx, dy = e.clientY - P.sy;
      if (P.k === 'pan') { if (Math.abs(dx) + Math.abs(dy) > 3) P.moved = true; S.view.x = P.ox + dx; S.view.y = P.oy + dy; applyView(); }
      else if (P.k === 'node') { if (!P.moved && Math.abs(dx) + Math.abs(dy) < 4) return; P.moved = true; P.n.x = Math.round(P.ox + dx / S.view.z); P.n.y = Math.round(P.oy + dy / S.view.z); moveNodeEl(P.n); drawLines(); }
      else if (P.k === 'link') { const b = toWorld(e.clientX, e.clientY); $('#kfTmp').setAttribute('d', curve(P.a, b)); ov.querySelectorAll('.kf-node.hot').forEach((x) => x.classList.remove('hot')); const over = document.elementFromPoint(e.clientX, e.clientY); const nd = over && over.closest('.kf-node'); if (nd && nd.dataset.id !== P.fid && !nd.classList.contains('start')) nd.classList.add('hot'); }
      else if (P.k === 'pal') { if (Math.abs(dx) + Math.abs(dy) > 6) { P.moved = true; if (!P.ghost) { const p = PAL[P.i]; P.ghost = document.createElement('div'); P.ghost.className = 'kf-node'; P.ghost.style.cssText = 'position:fixed;width:200px;opacity:.85;pointer-events:none;z-index:9999'; P.ghost.innerHTML = `<div class="kf-nh"><span class="kf-tile ${p[2]}">${ic(p[1], 's')}</span>${esc(p[3])}</div>`; document.body.appendChild(P.ghost); } P.ghost.style.left = e.clientX - 20 + 'px'; P.ghost.style.top = e.clientY - 16 + 'px'; } }
    });
    const endP = (e) => {
      if (!P) return; const p = P; P = null; cv.classList.remove('pan');
      if (p.k === 'pan' && !p.moved) { if (S.sel) { S.sel = ''; drawNodes(); drawEd(); } }
      else if (p.k === 'node') { if (p.moved) mark(); else if (S.sel !== p.n.id) { S.sel = p.n.id; drawNodes(); drawEd(); } else drawLines(); }
      else if (p.k === 'link') { $('#kfTmp').setAttribute('d', ''); ov.querySelectorAll('.kf-node.hot').forEach((x) => x.classList.remove('hot')); const over = document.elementFromPoint(e.clientX, e.clientY); const nd = over && over.closest('.kf-node'); const from = node(p.fid);
        if (nd && nd.dataset.id !== p.fid && !nd.classList.contains('start')) { setTo(from, p.key, nd.dataset.id); mark(); drawNodes(); drawEd(); }
        else if (!nd && over && over.closest('#kfCv') && !over.closest('.kf-ed,.kf-pal,.kf-tips,.kf-zoom')) { const w = toWorld(e.clientX, e.clientY); quickAdd(e.clientX, e.clientY, (type, extra) => { const n = add(type, extra, { x: Math.max(w.x + 20, from.x + 300), y: w.y - 20 }); setTo(from, p.key, n.id); mark(); drawNodes(); drawEd(); }); } }
      else if (p.k === 'pal') { const pp = PAL[p.i]; if (p.ghost) p.ghost.remove(); if (!p.moved) { add(pp[0], pp[4], visibleCenter()); return; } const over = document.elementFromPoint(e.clientX, e.clientY); if (over && over.closest('#kfCv') && !over.closest('.kf-pal,.kf-ed')) { const w = toWorld(e.clientX, e.clientY); add(pp[0], pp[4], { x: w.x - 20, y: w.y - 16 }); } }
    };
    cv.addEventListener('pointerup', endP); cv.addEventListener('pointercancel', endP);
    cv.addEventListener('wheel', (e) => { if (e.target.closest('.kf-pal,.kf-ed,.kf-tips,.kf-menu')) return; e.preventDefault(); if (e.ctrlKey || e.metaKey || Math.abs(e.deltaY) > 40 && !e.shiftKey && Math.abs(e.deltaX) < 2 && e.deltaMode === 0 && Number.isInteger(e.deltaY)) zoomAt(e.deltaY < 0 ? 1.1 : 1 / 1.1, e.clientX, e.clientY); else { S.view.x -= e.deltaX; S.view.y -= e.deltaY; applyView(); } }, { passive: false });
    ov.querySelectorAll('[data-z]').forEach((b) => { b.onclick = () => { const z = b.dataset.z, r = cv.getBoundingClientRect(); if (z === 'fit') fit(); else if (z === '0') { S.view.z = 1; applyView(); } else zoomAt(z === '+' ? 1.2 : 1 / 1.2, r.left + r.width / 2, r.top + r.height / 2); }; });
    let MENU = null; function closeMenu() { if (MENU) { MENU.remove(); MENU = null; } }
    function quickAdd(cx, cy, done) {
      closeMenu(); const r = cv.getBoundingClientRect(); MENU = document.createElement('div'); MENU.className = 'kf-menu'; MENU.style.left = Math.min(cx - r.left, r.width - 220) + 'px'; MENU.style.top = Math.min(cy - r.top, r.height - 330) + 'px';
      MENU.innerHTML = '<div class="kf-hint" style="margin:2px 6px 6px">Add the next box</div>' + PAL.filter((p) => p !== '-').map((p) => `<button type="button" data-q="${PAL.indexOf(p)}"><span class="kf-tile ${p[2]}" style="width:22px;height:22px">${ic(p[1], 's')}</span>${esc(p[3])}</button>`).join('');
      cv.appendChild(MENU); MENU.querySelectorAll('[data-q]').forEach((b) => { b.onclick = () => { const p = PAL[+b.dataset.q]; closeMenu(); done(p[0], p[4]); }; });
    }
    // ---------------------------------------------------------------------------------------------------------- editor (right)
    const opts = (L, v, none) => (none ? `<option value="">${none}</option>` : '') + L.map(([k, l]) => `<option value="${esc(k)}" ${String(k) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('');
    const targets = (self) => G.nodes.filter((x) => x.type !== 'start' && x.id !== self).map((x) => [x.id, targetLabel(x.id)]);
    function drawEd() {
      const box = $('#kfEd'); const n = node(S.sel); cv.classList.toggle('edopen', !!n); if (!n) { box.innerHTML = ''; return; }
      const E = problems()[n.id] || []; const ty = TY[n.type]; const cnt = (v, max) => `<div class="kf-cnt ${String(v || '').length > max ? 'bad' : ''}">${String(v || '').length} / ${max}</div>`;
      const st = NS[n.id] || null;
      let h = `<div class="kf-ed"><div class="hd"><span class="kf-tile ${ty.c}">${ic(ty.ic, 's')}</span>${esc(title(n))}<span style="flex:1"></span>${n.type !== 'start' ? `<button type="button" class="kf-x" data-e="dup" title="Duplicate">${ic('copy', 's')}</button><button type="button" class="kf-x" data-e="del" title="Delete box">${ic('trash', 's')}</button>` : ''}<button type="button" class="kf-x" data-e="close" title="Close">${ic('x', 's')}</button></div><div class="bd">`;
      if (E.length) h += `<div class="kf-errs">${E.map((x) => '• ' + esc(x)).join('<br>')}</div>`;
      if (n.type === 'start') {
        h += `<label class="l">The customer’s message <span>· one per line, any can match</span></label>` + (n.triggers || []).map((t, i) => `<div class="kf-brow" style="grid-template-columns:96px minmax(0,1fr) 28px"><select class="kf-in" data-tm="${i}">${opts(Object.keys(MT).map((k) => [k, MT[k][0] + MT[k].slice(1).toLowerCase()]), t.m)}</select><input class="kf-in" data-tv="${i}" value="${esc(t.v)}" placeholder="${t.m === 'regex' ? '^best\\s?sellers?$' : 'Hi, I want to know about…'}"><button type="button" class="kf-x" data-tx="${i}">${ic('x', 's')}</button></div>`).join('') +
          `<button type="button" class="kf-btn" data-e="addt">${ic('plus', 's')}Add another text</button><div class="kf-hint">${Object.keys(MHELP).map((k) => `<b>${MT[k]}</b> — ${MHELP[k]}`).join('<br>')}</div>
          <label class="l">Try it</label><input class="kf-in" id="kfTry" placeholder="Type a customer message…"><div id="kfTryR"></div>`;
        if (S.hard.length || S.soft.length) h += `<label class="l">Other flows</label>` + S.hard.map((x) => `<div class="kf-res" style="background:#fef2f2;color:#991b1b">${ic('alert')}<div>“${esc(x.text)}” is also the start of <b>${esc(x.b)}</b> (on). This flow can’t be switched on until one changes.</div></div>`).join('') + S.soft.map((x) => `<div class="kf-res w">${ic('alert')}<div>“${esc(x.text)}” is also caught by <b>${esc(x.a === S.name ? x.b : x.a)}</b>. <b>${esc(x.winner)}</b> wins.</div></div>`).join('');
        h += `<label class="l">Then</label><select class="kf-in" data-to="next">${opts(targets(n.id), n.next, '— connect a box —')}</select>`;
      }
      if (n.type === 'media') { const L = (A.media || []).filter((m) => m.kind === (n.kind || 'image')); h += `<label class="l">Media</label><div class="kf-seg">${[['image', 'img', 'Image'], ['video', 'video', 'Video'], ['document', 'doc', 'PDF']].map(([k, i2, l]) => `<button type="button" data-kind="${k}" class="${n.kind === k ? 'on' : ''}">${ic(i2, 's')}${l}</button>`).join('')}</div>
        <select class="kf-in" data-f="lib_id" style="margin-top:6px">${opts(L.map((m) => [m.id, m.title]), n.lib_id, L.length ? '— choose from the Media library —' : '— no ' + (n.kind === 'document' ? 'PDFs' : n.kind + 's') + ' in the Media library —')}</select><div class="kf-hint">Upload new files in Media → Library, then pick them here.</div>`; }
      if (n.type === 'products') h += `<label class="l">List <span>· from Replies → Lists (live Shopify data)</span></label><select class="kf-in" data-f="list_id">${opts((A.lists || []).map((l) => [l.id, (l.grp ? l.grp + ' · ' : '') + l.name]), n.list_id, '— choose a list —')}</select>`;
      if (n.type === 'list') h += `<label class="l">Title <span>· optional</span></label><input class="kf-in" data-f="header" value="${esc(n.header || '')}" maxlength="60">`;
      if (['text', 'media', 'list', 'products', 'handoff'].includes(n.type)) h += `<label class="l">${n.type === 'products' ? 'Text above the list <span>· optional</span>' : n.type === 'media' && n.mode === 'none' ? 'Caption <span>· optional</span>' : n.type === 'handoff' ? 'Message to the customer <span>· optional</span>' : 'Text'} <span>· {name} = first name</span></label><textarea class="kf-in" data-f="text" rows="4">${esc(n.text || '')}</textarea>${cnt(n.text, 1024)}`;
      if (hasMode(n)) {
        h += `<label class="l">After the message</label><div class="kf-seg">${[['buttons', 'reply', 'Quick replies'], ['url', 'globe', 'Website'], ['none', 'arrow', 'None → next']].map(([k, i2, l]) => `<button type="button" data-mode="${k}" class="${n.mode === k ? 'on' : ''}">${ic(i2, 's')}${l}</button>`).join('')}</div>`;
        if (n.mode === 'buttons') { h += `<label class="l">Buttons <span>· up to 3, 20 letters each</span></label>` + (n.buttons || []).map((b, i) => `<div class="kf-brow"><input class="kf-in" data-bt="${i}" value="${esc(b.t)}" maxlength="20" placeholder="Button ${i + 1}"><select class="kf-in" data-to="b${i}">${opts(targets(n.id), b.to, '→ connect…')}</select><button type="button" class="kf-x" data-bx="${i}">${ic('x', 's')}</button></div>${st && st.taps && st.taps[String(i)] ? `<div class="kf-hint" style="margin:-3px 0 6px">${fmt(st.taps[String(i)])} taps</div>` : ''}`).join('') + ((n.buttons || []).length < 3 ? `<button type="button" class="kf-btn" data-e="addb">${ic('plus', 's')}Add button</button>` : '<div class="kf-hint">3 is WhatsApp’s limit — use a List box for more choices.</div>'); }
        else if (n.mode === 'url') h += `<label class="l">Button text <span>· 20 letters</span></label><input class="kf-in" data-u="t" value="${esc((n.url || {}).t || '')}" maxlength="20" placeholder="View on website"><label class="l">Link</label><input class="kf-in" data-u="u" value="${esc((n.url || {}).u || 'https://')}" placeholder="https://"><label class="l">Then <span>· optional</span></label><select class="kf-in" data-to="next">${opts(targets(n.id), n.next, '— end here —')}</select>`;
        else h += `<label class="l">Then <span>· sent right after, optional</span></label><select class="kf-in" data-to="next">${opts(targets(n.id), n.next, '— end here —')}</select>`;
      }
      if (n.type === 'list') { h += `<label class="l">List button <span>· 20 letters</span></label><input class="kf-in" data-f="button" value="${esc(n.button || '')}" maxlength="20"><label class="l">Rows <span>· up to 10 · title 24 letters</span></label>` + (n.rows || []).map((r, i) => `<div style="border:1px solid #f1f2f4;border-radius:8px;padding:6px;margin-bottom:6px"><div class="kf-brow"><input class="kf-in" data-rt="${i}" value="${esc(r.t)}" maxlength="24" placeholder="Row ${i + 1}"><select class="kf-in" data-to="r${i}">${opts(targets(n.id), r.to, '→ connect…')}</select><button type="button" class="kf-x" data-rx="${i}">${ic('x', 's')}</button></div><input class="kf-in" data-rd="${i}" value="${esc(r.d || '')}" maxlength="72" placeholder="Description (optional)" style="font-size:12px;padding:6px 8px"></div>`).join('') + ((n.rows || []).length < 10 ? `<button type="button" class="kf-btn" data-e="addr">${ic('plus', 's')}Add row</button>` : ''); }
      if (n.type === 'template') { const t = (A.templates || []).find((x) => x.id === +n.tpl_id); h += `<label class="l">Approved pre-made message</label><select class="kf-in" data-f="tpl_id">${opts((A.templates || []).map((x) => [x.id, x.name + ' · ' + x.category.toLowerCase()]), n.tpl_id, (A.templates || []).length ? '— choose —' : '— none approved yet —')}</select>${t ? `<div class="kf-hint" style="white-space:pre-wrap">${esc(t.body)}</div>` : ''}<div class="kf-hint">A template is the only message that can be sent after the 24-hour window. Meta charges for it.</div><label class="l">Then <span>· optional</span></label><select class="kf-in" data-to="next">${opts(targets(n.id), n.next, '— end here —')}</select>`; }
      if (n.type === 'handoff') h += `<label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px"><input type="checkbox" data-f="alert" ${n.alert ? 'checked' : ''}> Call-now alert to the salesperson</label><div class="kf-hint">The chat goes to the least busy salesperson who is present (if nobody has it yet). The flow ends here — a person takes over.</div>`;
      if (n.type === 'goto') h += `<label class="l">Flow</label><select class="kf-in" data-f="flow_id">${opts((A.flows || []).filter((f) => f.id !== id).map((f) => [f.id, f.name + (f.active ? '' : ' (off)')]), n.flow_id, '— choose —')}</select><div class="kf-hint">The customer continues from that flow’s Start. At most 2 jumps in a row.</div>`;
      if (n.type === 'tag') h += `<label class="l">Tag</label><input class="kf-in" data-f="tag" value="${esc(n.tag || '')}" maxlength="40" placeholder="e.g. wants_bestsellers"><div class="kf-hint">Shows in Customers filters and Campaigns.</div><label class="l">Then <span>· optional</span></label><select class="kf-in" data-to="next">${opts(targets(n.id), n.next, '— end here —')}</select>`;
      if (st && (st.sent || st.err)) h += `<div class="kf-hint" style="margin-top:14px;display:flex;gap:6px;align-items:center">${ic('chart', 's')}Last 30 days: ${fmt(st.sent || 0)} sent${st.err ? ` · <b style="color:#b91c1c">${fmt(st.err)} not sent</b> (${esc(st.last_err)})` : ''}</div>`;
      box.innerHTML = h + '</div></div>';
      wireEd(n);
    }
    let edT = 0; const soon = () => { clearTimeout(edT); edT = setTimeout(() => { drawNodes(); const E = problems()[S.sel] || []; const eb = $('#kfEd .kf-errs'); if (eb) eb.innerHTML = E.map((x) => '• ' + esc(x)).join('<br>'); if (eb && !E.length) eb.remove(); }, 160); };
    function wireEd(n) {
      const box = $('#kfEd'); const q = (s) => box.querySelectorAll(s); const chg = () => { mark(); soon(); };
      q('[data-e]').forEach((b) => { b.onclick = () => { const a = b.dataset.e; if (a === 'close') { S.sel = ''; drawNodes(); drawEd(); } else if (a === 'del') del(n.id); else if (a === 'dup') dup(n.id);
        else if (a === 'addt') { n.triggers.push({ m: 'exact', v: '' }); mark(); drawEd(); drawNodes(); } else if (a === 'addb') { n.buttons.push({ t: '', to: '' }); mark(); drawEd(); drawNodes(); } else if (a === 'addr') { n.rows.push({ t: '', d: '', to: '' }); mark(); drawEd(); drawNodes(); } }; });
      q('[data-f]').forEach((x) => { const f = x.dataset.f; const ev = x.tagName === 'SELECT' || x.type === 'checkbox' ? 'onchange' : 'oninput'; x[ev] = () => { n[f] = x.type === 'checkbox' ? x.checked : (['lib_id', 'list_id', 'tpl_id', 'flow_id'].includes(f) ? +x.value || 0 : x.value); chg(); if (x.tagName === 'SELECT') drawEd(); if (f === 'text') { const c = x.nextElementSibling; if (c && c.classList.contains('kf-cnt')) { c.textContent = x.value.length + ' / 1024'; c.classList.toggle('bad', x.value.length > 1024); } } }; });
      q('[data-to]').forEach((x) => { x.onchange = () => { setTo(n, x.dataset.to, x.value); mark(); drawNodes(); drawEd(); }; });
      q('[data-mode]').forEach((b) => { b.onclick = () => { n.mode = b.dataset.mode; if (n.mode === 'buttons' && !(n.buttons || []).length) n.buttons = [{ t: '', to: '' }]; if (n.mode !== 'buttons') n.buttons = n.mode === 'buttons' ? n.buttons : []; if (!n.url) n.url = { t: '', u: 'https://' }; mark(); drawEd(); drawNodes(); }; });
      q('[data-kind]').forEach((b) => { b.onclick = () => { n.kind = b.dataset.kind; n.lib_id = 0; mark(); drawEd(); drawNodes(); }; });
      q('[data-bt]').forEach((x) => { x.oninput = () => { n.buttons[+x.dataset.bt].t = x.value; chg(); }; });
      q('[data-bx]').forEach((b) => { b.onclick = () => { n.buttons.splice(+b.dataset.bx, 1); mark(); drawEd(); drawNodes(); }; });
      q('[data-rt]').forEach((x) => { x.oninput = () => { n.rows[+x.dataset.rt].t = x.value; chg(); }; });
      q('[data-rd]').forEach((x) => { x.oninput = () => { n.rows[+x.dataset.rd].d = x.value; chg(); }; });
      q('[data-rx]').forEach((b) => { b.onclick = () => { n.rows.splice(+b.dataset.rx, 1); mark(); drawEd(); drawNodes(); }; });
      q('[data-u]').forEach((x) => { x.oninput = () => { n.url = n.url || {}; n.url[x.dataset.u] = x.value; chg(); }; });
      q('[data-tm]').forEach((x) => { x.onchange = () => { n.triggers[+x.dataset.tm].m = x.value; mark(); drawEd(); drawNodes(); }; });
      q('[data-tv]').forEach((x) => { x.oninput = () => { n.triggers[+x.dataset.tv].v = x.value; chg(); }; });
      q('[data-tx]').forEach((b) => { b.onclick = () => { n.triggers.splice(+b.dataset.tx, 1); mark(); drawEd(); drawNodes(); }; });
      const tr = box.querySelector('#kfTry'); if (tr) tr.oninput = () => { const v = tr.value; const hit = v.trim() && (n.triggers || []).find((t) => trigHit(t, v, '') || (t.m === 'button' && trigHit(t, '', v))); box.querySelector('#kfTryR').innerHTML = !v.trim() ? '' : hit ? `<div class="kf-res ok">${ic('check')}<div>Matches ${MT[hit.m]} “${esc(hit.v)}”</div></div>` : `<div class="kf-res no">${ic('x')}<div>No match in this flow.</div></div>`; };
    }
    // ---------------------------------------------------------------------------------------------------------- 💡 suggestions (count only; open on tap)
    function reach() { const seen = new Set(); const go = (i) => { if (!i || seen.has(i)) return; const n = node(i); if (!n) return; seen.add(i); outs(n).forEach((o) => go(getTo(n, o.k))); }; const s = start(); if (s) { seen.add(s.id); go(s.next); } return seen; }
    function tips() {
      const T = [], R = reach();
      G.nodes.forEach((n) => { if (n.type !== 'start' && !R.has(n.id)) T.push({ k: 'orphan_' + n.id, n: n.id, t: `“${title(n)}” is not connected — customers never get it.`, fix: 'Connect a button or “then” dot to it, or delete it.' }); });
      const s = start(); (s && s.triggers || []).forEach((t) => { if (t.m === 'contains' && loose(t.v).length > 0 && loose(t.v).length < 4) T.push({ k: 'short_' + t.v, n: s.id, t: `CONTAINS “${t.v}” is very short — it will catch many messages.`, fix: 'Use EXACT, or a longer phrase.' }); });
      if (G.nodes.length > 2 && !G.nodes.some((n) => n.type === 'handoff')) T.push({ k: 'nohand', n: '', t: 'Nobody can reach a person from this flow.', fix: 'Add a “Talk to us” button that goes to “Hand to salesperson”.' });
      G.nodes.forEach((n) => { if (n.type === 'list' && (n.rows || []).length > 0 && (n.rows || []).length <= 3) T.push({ k: 'list3_' + n.id, n: n.id, t: 'This list has 3 rows or fewer.', fix: 'Quick-reply buttons are one tap less than a list.' }); if ((n.text || '').length > 600) T.push({ k: 'long_' + n.id, n: n.id, t: `“${title(n)}” text is ${n.text.length} letters.`, fix: 'Shorter messages get more taps — try under 600.' }); if (n.type === 'media' && n.mode === 'none' && !n.next && !(n.text || '').trim()) T.push({ k: 'cap_' + n.id, n: n.id, t: 'A photo with no text and no next step.', fix: 'Add a caption or buttons so the customer knows what to do.' }); });
      S.soft.forEach((x) => T.push({ k: 'ov_' + x.text, n: s ? s.id : '', t: `“${x.text}” is also caught by ${x.a === S.name ? x.b : x.a}. ${x.winner} wins.`, fix: 'Move this flow higher in the list, or make the text EXACT.' }));
      Object.keys(NS).forEach((k) => { const st = NS[k]; const n = node(k); if (n && st.err >= 3) T.push({ k: 'err_' + k, n: k, t: `“${title(n)}” failed ${st.err} times: ${st.last_err}`, fix: 'Check the box (media, template, link).' }); });
      return T.filter((x) => !DIS.has(x.k) && !DIS.has(x.k.split('_')[0] + '_'));
    }
    function drawTips() {
      const b = $('#kfTips'); if (!S.tips) { b.innerHTML = ''; return; } const T = tips();
      b.innerHTML = `<div class="kf-tips"><div style="display:flex;align-items:center;gap:8px;font-weight:600">${ic('bulb')}Suggestions<span style="flex:1"></span><button type="button" class="kf-x" data-t="x">${ic('x', 's')}</button></div>${T.length ? T.map((x, i) => `<div class="kf-tip"><b>${esc(x.t)}</b><div class="kf-hint" style="margin-top:2px">${esc(x.fix)}</div><div class="a">${x.n ? `<button type="button" class="kf-btn" data-ts="${i}">Show</button>` : ''}<button type="button" class="kf-btn" data-td="${i}">Not now</button><button type="button" class="kf-btn" data-tn="${i}">Never this kind</button></div></div>`).join('') : '<div class="kf-hint" style="margin-top:10px">Nothing to suggest — looks good.</div>'}</div>`;
      b.querySelector('[data-t]').onclick = () => { S.tips = false; drawTips(); };
      const keepD = () => { try { localStorage.setItem('kf_dis_' + id, JSON.stringify([...DIS])); } catch (e) { } };
      b.querySelectorAll('[data-ts]').forEach((x) => { x.onclick = () => { const t = T[+x.dataset.ts]; S.sel = t.n; S.tips = false; drawTips(); drawNodes(); drawEd(); const n = node(t.n); if (n) { const r = cv.getBoundingClientRect(); S.view.x = r.width / 2 - 140 - n.x * S.view.z; S.view.y = r.height / 3 - n.y * S.view.z; applyView(); requestAnimationFrame(drawLines); } }; });
      b.querySelectorAll('[data-td]').forEach((x) => { x.onclick = () => { DIS.add(T[+x.dataset.td].k); keepD(); drawTips(); status(); }; });
      b.querySelectorAll('[data-tn]').forEach((x) => { x.onclick = () => { DIS.add(T[+x.dataset.tn].k.split('_')[0] + '_'); keepD(); drawTips(); status(); }; });
    }
    // ---------------------------------------------------------------------------------------------------------- ▶ test in a phone
    function phone() {
      const m = modal('Test in a phone — nothing is sent'); m.body.innerHTML = `<div class="kf"><div class="kf-phone"><div class="ms" id="kpM"><div class="kf-b sys">Type what a customer would send. Tap the buttons to follow the flow.</div></div><div class="bar"><input id="kpI" placeholder="Message"><button type="button" id="kpS" aria-label="Send">${ic('send', 's')}</button></div></div></div>`;
      const M = m.body.querySelector('#kpM'), inp = m.body.querySelector('#kpI'); const say = (h, cls) => { const d = document.createElement('div'); d.className = 'kf-b ' + cls; d.innerHTML = h; M.appendChild(d); M.scrollTop = M.scrollHeight; return d; };
      const nameIt = (t) => String(t || '').replace(/\{name\}|\{\{1\}\}/g, 'Rahul');
      function run(i, hop) {
        for (let k = 0; k < 8 && i; k++) {
          const n = node(i); if (!n) return; let d;
          if (n.type === 'text' || n.type === 'media' || n.type === 'products') {
            const md = n.type === 'media' ? (A.media || []).find((x) => x.id === +n.lib_id) : null; const l = n.type === 'products' ? (A.lists || []).find((x) => x.id === +n.list_id) : null;
            d = say((md ? `<div class="md" data-th="${md.kind === 'image' ? md.id : ''}">${md.kind === 'document' ? '📄 ' + esc(md.title) : md.kind === 'video' ? '🎬 ' + esc(md.title) : ''}</div>` : '') + esc(nameIt(n.text)) + (l ? `${n.text ? '\n\n' : ''}🛍 <i>${esc(l.name)} — the list’s current products are filled in when sent</i>` : ''), 'out');
            if (n.mode === 'buttons') { (n.buttons || []).forEach((b) => { const x = document.createElement('button'); x.className = 'bt'; x.textContent = b.t || '(empty)'; x.onclick = () => { say(esc(b.t), 'in'); run(b.to); }; d.appendChild(x); }); return; }
            if (n.mode === 'url') { const x = document.createElement('button'); x.className = 'bt'; x.textContent = '🌐 ' + ((n.url || {}).t || 'Website'); x.onclick = () => window.open((n.url || {}).u, '_blank'); d.appendChild(x); }
            const th = d.querySelector('[data-th]'); if (th && +th.dataset.th) thumb(+th.dataset.th).then((u) => { if (u) th.style.backgroundImage = 'url(' + u + ')'; });
          } else if (n.type === 'list') { d = say((n.header ? '<b>' + esc(nameIt(n.header)) + '</b>\n' : '') + esc(nameIt(n.text)), 'out'); const x = document.createElement('button'); x.className = 'bt'; x.textContent = '☰ ' + (n.button || 'Choose'); x.onclick = () => { x.remove(); (n.rows || []).forEach((r) => { const y = document.createElement('button'); y.className = 'bt'; y.textContent = r.t + (r.d ? ' — ' + r.d : ''); y.onclick = () => { say(esc(r.t), 'in'); run(r.to); }; d.appendChild(y); }); }; d.appendChild(x); return; }
          else if (n.type === 'template') { const t = (A.templates || []).find((x) => x.id === +n.tpl_id); say('📣 ' + esc(t ? t.body : 'Template not chosen'), 'out'); }
          else if (n.type === 'handoff') { if ((n.text || '').trim()) say(esc(nameIt(n.text)), 'out'); say('👤 The chat goes to a salesperson' + (n.alert ? ' (call-now alert)' : ''), 'sys'); return; }
          else if (n.type === 'tag') say('🏷 Tag “' + esc(n.tag) + '” added', 'sys');
          else if (n.type === 'goto') { const f = (A.flows || []).find((x) => x.id === +n.flow_id); say('↪ Continues in “' + esc(f ? f.name : '?') + '” (not shown in this test)', 'sys'); return; }
          i = n.next || '';
        }
      }
      const send = async () => { const v = inp.value.trim(); if (!v) return; inp.value = ''; say(esc(v), 'in'); const s = start(); const hit = (s.triggers || []).find((t) => trigHit(t, v, '') || (t.m === 'button' && trigHit(t, '', v)));
        if (hit) { say(`Matched ${MT[hit.m]} “${esc(hit.v)}”`, 'sys'); run(s.next); }
        else { const d = say('No match in this flow…', 'sys'); try { const r = await api.get('wa.kf_test', { text: v }); if (r.winner && r.winner.id !== id) d.textContent = 'No match here — “' + r.winner.name + '” would answer it.'; else d.textContent = 'No key flow answers this — greetings / Claude would.'; } catch (e) { } } };
      m.body.querySelector('#kpS').onclick = send; inp.onkeydown = (e) => { if (e.key === 'Enter') send(); }; setTimeout(() => inp.focus(), 50);
    }
    // ---------------------------------------------------------------------------------------------------------- save, top bar, keys
    async function save(want) {
      if (want !== undefined) S.want = want; const name = $('#kfNm').value.trim() || S.name; const btn = ov.querySelector('[data-a="save"]'); btn.disabled = true; btn.textContent = 'Saving…';
      try { const r = await api.post('wa.kf_save', { id, name, graph: { nodes: G.nodes }, active: S.want }); S.name = name; S.dirty = false; S.active = r.active; S.want = r.active; S.srv = r.problems || {}; S.hard = r.hard || []; S.soft = r.soft || [];
        toast(r.message + (r.active ? ' · ON' : ''), r.message !== 'Saved'); drawNodes(); drawEd(); assets(true).then((a) => { A = a; }); }
      catch (e) { toast(e.message, true); }
      btn.disabled = false; btn.textContent = 'Save'; status();
    }
    function close() { if (S.dirty && !confirm('Leave without saving your changes?')) return; ov.remove(); document.removeEventListener('keydown', keys); if (onClose) onClose(); }
    ov.querySelectorAll('[data-a]').forEach((b) => { b.onclick = () => { const a = b.dataset.a; if (a === 'back') close(); else if (a === 'save') save(); else if (a === 'test') phone(); else if (a === 'tips') { S.tips = !S.tips; drawTips(); } else if (a === 'add') { const r = cv.getBoundingClientRect(); quickAdd(r.left + 20, r.top + 20, (t, x) => add(t, x, visibleCenter())); } }; });
    $('#kfOn').onclick = () => { const want = !S.want; if (want) { const n = Object.values(problems()).reduce((a, b) => a + b.length, 0); if (n) { toast('Fix the ' + n + ' problem' + (n === 1 ? '' : 's') + ' (red dots) first.', true); return; } } save(want); };
    $('#kfNm').oninput = () => mark();
    const keys = (e) => { if (!document.body.contains(ov)) return; const inField = /INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || ''); if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); } else if (!inField && (e.key === 'Delete' || e.key === 'Backspace') && S.sel) { e.preventDefault(); del(S.sel); } else if (e.key === 'Escape') { closeMenu(); if (S.tips) { S.tips = false; drawTips(); } } };
    document.addEventListener('keydown', keys);
    window.addEventListener('resize', () => { if (document.body.contains(ov)) drawLines(); });
    if (!start().triggers || !start().triggers.some((t) => (t.v || '').trim())) S.sel = start().id;
    drawNodes(); drawEd(); requestAnimationFrame(() => { fit(); drawLines(); });
  }

  WaPanels.add('kflows', screen);
  window.WaKflow = { openCanvas };
})();
