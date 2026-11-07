/**
 * wch_ui.js — chain + perfume WhatsApp cart ("wch" module): the cart builder the salesperson opens inside a chat.
 *   WaCart.open({ phone, name, api, total, ml, onChange })   full-screen sheet: Perfumes > Chains > Send, progress view, open carts.
 *                                                             onChange(cart) runs after the cart was created / options sent / chosen / link sent / cancelled.
 *   WaCart.status(el, { phone, api, name, onChange })         compact progress chip for the chat details (hidden when the chat has no open cart).
 *   WaCart.list(el, { api, onOpen })                          the salesperson's open carts of the last 7 days (tap = open the cart, or onOpen(phone)).
 * Server side: empmanagment/wch.php (actions wch.*, contract Q_M2_api.md). Styles carry the prefix wch- and are injected here.
 * Phone first (390 px), WhatsApp-app feel, touch targets >= 40 px, light + dark (html.wxdk in the phone app, html.wchdk / html.wqdk elsewhere).
 * Nothing here polls, except the progress view, which refreshes every 15 s while it is open and the tab is visible.
 * Customer-facing texts and the bill never carry designer brand names; this salesperson screen shows the '-type' names the catalog uses.
 */
(function () {
  'use strict';
  if (window.WaCart && window.WaCart.v) return;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (n, d) => { n = Number(n); return isFinite(n) ? n : (d == null ? 0 : d); };
  const fm = (n) => { n = num(n); const r = Math.round(n * 100) / 100; return '₹' + (Number.isInteger(r) ? r.toLocaleString('en-IN') : r.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })); };
  const parse = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)(?::(\d\d))?/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null; };
  const clock = (s) => { const d = parse(s); return d ? d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toLowerCase() : ''; };
  const ago = (s) => { const d = parse(s); if (!d) return ''; const m = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000)); return m < 1 ? 'just now' : m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; };
  const initials = (n) => { const w = String(n || '').trim().split(/\s+/).filter(Boolean); return (w.length ? w[0][0] + (w[1] ? w[1][0] : '') : '?').toUpperCase(); };
  const first = (n) => String(n || '').trim().split(/\s+/)[0] || 'The customer';
  const ML3 = [20, 50, 100];
  const SEGC = ['s1', 's2', 's3'];
  const SEGHEX = ['#0f6e7e', '#00a884', '#7c5cfc'];

  // ------------------------------------------------------------------ styles
  const CSS = `
.wch-ov,.wch{--wb:#fff;--wi:#111b21;--wm:#667781;--wl:#e9edef;--ws:#f0f2f5;--wc:#fff;--wg:#00a884;--wgd:#008069;--wgs:#d9fdd3;--wgsfg:#005c4b;--rbg:#fdecea;--rfg:#b3261e;--abg:#fff3dc;--afg:#8a5a00;--gbg:#e4f6ec;--gfg:#0f7a3a;--ibg:#e8f0fe;--ifg:#2456b3;--vbg:#f0ebff;--vfg:#5b3fd6;--shadow:rgba(0,0,0,.25);font-family:"Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif;color:var(--wi);font-size:14px;line-height:1.4}
html.wxdk .wch-ov,html.wchdk .wch-ov,html.wqdk .wch-ov,.wch-ov.wch-dk,html.wxdk .wch,html.wchdk .wch,html.wqdk .wch{--wb:#0b141a;--wi:#e9edef;--wm:#8696a0;--wl:#222d34;--ws:#1f2c34;--wc:#111b21;--wgs:#005c4b;--wgsfg:#d9fdd3;--rbg:#47191c;--rfg:#ff9a9d;--abg:#3b3110;--afg:#f3d36b;--gbg:#0f3a2a;--gfg:#6ee7b0;--ibg:#15294a;--ifg:#8db6ff;--vbg:#2a2150;--vfg:#c0adff;--shadow:rgba(0,0,0,.6)}
.wch-ov *,.wch *{box-sizing:border-box}
.wch-ov [hidden],.wch [hidden]{display:none !important}
.wch-ov{position:fixed;inset:0;z-index:10060;background:rgba(11,20,26,.55);display:flex;align-items:flex-end;justify-content:center}
.wch-sh{background:var(--wb);width:100%;max-width:520px;height:calc(100vh - 26px);height:calc(100dvh - 26px);border-radius:24px 24px 0 0;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 -10px 40px var(--shadow);position:relative}
@media (min-width:761px){.wch-ov{align-items:center}.wch-sh{border-radius:24px;height:min(92vh,880px)}}
.wch-grab{width:44px;height:5px;border-radius:9px;background:var(--wl);margin:8px auto 0;flex:none}
.wch-sh-hd{padding:10px 14px 6px;display:flex;align-items:center;gap:10px;flex:none}
.wch-sh-hd h2{margin:0;font-size:19px;font-weight:800;letter-spacing:-.01em;line-height:1.2;overflow-wrap:anywhere}
.wch-sh-hd small{display:block;font-size:12.5px;color:var(--wm);font-weight:500;margin-top:1px;overflow-wrap:anywhere}
.wch-x{width:44px;height:44px;border-radius:50%;background:var(--ws);display:grid;place-items:center;flex:none;color:var(--wm);border:0;cursor:pointer;padding:0}
.wch-steps{display:flex;gap:6px;padding:2px 14px 8px;flex:none}
.wch-steps button{flex:1;min-height:40px;border:0;border-radius:99px;background:var(--ws);font-size:12.5px;font-weight:700;color:var(--wm);display:flex;align-items:center;justify-content:center;gap:6px;padding:0 4px;white-space:nowrap;font-family:inherit;cursor:pointer}
.wch-steps button:disabled{cursor:default}
.wch-steps b{width:20px;height:20px;border-radius:50%;background:var(--wl);display:grid;place-items:center;font-size:11.5px;color:var(--wm)}
.wch-steps button.on{background:var(--wgs);color:var(--wgsfg)}.wch-steps button.on b{background:var(--wgd);color:#fff}
.wch-steps button.done{color:var(--gfg);background:var(--gbg)}.wch-steps button.done b{background:var(--gfg);color:#fff}
.wch-body{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding-bottom:6px}
.wch-pad{padding:0 14px}
.wch-lb{font-size:11.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--wm);margin:14px 0 6px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.wch-lb em{font-style:normal;text-transform:none;letter-spacing:0;font-weight:700;font-size:12.5px}
.wch-lk{background:none;border:0;color:var(--rfg);font-weight:700;font-size:12.5px;min-height:40px;padding:0 4px;font-family:inherit;cursor:pointer}
.wch-lk.n{color:var(--wgd)}
.wch-foot .wch-lk{flex:1 1 100%;text-align:left;margin-top:-4px}
.wch-two{display:grid;grid-template-columns:1.25fr 1fr;gap:10px}
.wch-fld{border:1.5px solid var(--wl);border-radius:16px;padding:7px 12px 6px;background:var(--wc);min-height:64px;display:block;min-width:0}
.wch-fld label{display:block;font-size:12px;color:var(--wm);font-weight:700}
.wch-fld .v{display:flex;align-items:baseline;gap:4px;font-size:26px;font-weight:800;letter-spacing:-.02em;line-height:1.2}
.wch-fld .v i{font-style:normal;font-size:16px;color:var(--wm);font-weight:700}
.wch-fld input{border:0;outline:0;background:none;width:100%;min-width:0;padding:0;margin:0;font:inherit;font-size:26px;font-weight:800;color:var(--wi);letter-spacing:-.02em;font-variant-numeric:tabular-nums;height:40px;-moz-appearance:textfield}
.wch-fld.on,.wch-fld:focus-within{border-color:var(--wg)}
.wch-fld.er{border-color:var(--rfg)}
.wch-fld.st .v{font-size:22px}
.wch-hint{font-size:12.5px;color:var(--wm);margin:6px 2px 0}
.wch-err{font-size:12.5px;color:var(--rfg);font-weight:700;margin:4px 2px 0}
.wch-prog{border:1px solid var(--wl);border-radius:12px;padding:7px 10px 8px;background:var(--wc)}
.wch-prog .r{display:flex;align-items:center;justify-content:space-between;gap:8px}
.wch-prog .r b{font-size:15px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}
.wch-prog .r b small{font-size:12px;color:var(--wm);font-weight:600}
.wch-prog .r span{font-size:11.5px;font-weight:700;color:var(--afg);background:var(--abg);border-radius:99px;padding:2px 8px;white-space:nowrap}
.wch-prog .r span.ok{color:var(--gfg);background:var(--gbg)}.wch-prog .r span.bad{color:var(--rfg);background:var(--rbg)}.wch-prog .r span.n{color:var(--wm);background:var(--ws)}
.wch-bar{height:7px;border-radius:6px;background:var(--wl);overflow:hidden;display:flex;gap:2px;margin-top:6px}
.wch-bar i{display:block;height:100%;transition:width .2s}
.wch-bar .s1{background:#0f6e7e}.wch-bar .s2{background:#00a884}.wch-bar .s3{background:#7c5cfc}
.wch-prog.over .wch-bar{outline:2px solid var(--rfg);outline-offset:-2px}
.wch-leg{display:flex;gap:10px;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin-top:5px;font-size:11px;color:var(--wm)}.wch-leg::-webkit-scrollbar{display:none}
.wch-leg i{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:4px}
.wch-srchbtn{width:100%;height:48px;border-radius:24px;background:var(--ws);display:flex;align-items:center;gap:10px;padding:0 16px;color:var(--wm);font-size:15px;border:1.5px solid transparent;font-family:inherit;text-align:left;cursor:pointer}
.wch-srch{position:relative}
.wch-srch .inp{height:48px;border-radius:24px;background:var(--wc);display:flex;align-items:center;gap:8px;padding:0 4px 0 16px;color:var(--wm);border:1.5px solid var(--wg)}
.wch-srch input{flex:1;min-width:0;border:0;outline:0;background:none;font:inherit;font-size:16px;color:var(--wi);height:44px;padding:0}
.wch-srch input::-webkit-search-cancel-button,.wch-srch input::-webkit-search-decoration{-webkit-appearance:none;display:none}
.wch-srch .cl{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;color:var(--wm);border:0;background:none;cursor:pointer;padding:0}
.wch-cats{display:flex;background:var(--ws);border-radius:99px;padding:3px;margin-top:8px}
.wch-cats button{flex:1;min-height:42px;border:0;background:none;border-radius:99px;font-weight:700;font-size:14px;color:var(--wm);font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;cursor:pointer}
.wch-cats button b{font-size:12px;background:var(--wl);border-radius:99px;padding:1px 8px;color:var(--wm)}
.wch-cats button.on{background:var(--wc);color:var(--wgsfg);box-shadow:0 1px 3px rgba(0,0,0,.18)}.wch-cats button.on b{background:var(--wgs);color:var(--wgsfg)}
html.wxdk .wch-cats button.on,html.wchdk .wch-cats button.on,.wch-dk .wch-cats button.on{background:#2a3942;color:#d9fdd3}
.wch-ph{display:block;width:52px;height:52px;border-radius:12px;background-size:cover;background-position:center;flex:none;background-color:var(--ws)}
.wch-ph.l{width:64px;height:64px;border-radius:14px}
.wch-ln{border:1.5px solid var(--wl);border-radius:18px;padding:10px;margin-bottom:8px;background:var(--wc)}
.wch-ln .t{display:flex;align-items:center;gap:10px}
.wch-ln .nm{flex:1;min-width:0}
.wch-ln .nm b{display:block;font-size:15px;font-weight:700;line-height:1.25;overflow-wrap:anywhere}
.wch-ln .nm small{display:block;font-size:12.5px;color:var(--wm);margin-top:1px;overflow-wrap:anywhere}
.wch-ln .ml{font-weight:800;font-size:15px;font-variant-numeric:tabular-nums;white-space:nowrap}
.wch-rm{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;color:var(--rfg);background:var(--rbg);flex:none;border:0;cursor:pointer;padding:0}
.wch-ln .c{display:flex;align-items:center;gap:8px;margin-top:9px;justify-content:space-between;flex-wrap:wrap}
.wch-sz{display:flex;gap:6px}
.wch-sz button{min-width:50px;height:42px;border-radius:99px;border:1.5px solid var(--wl);display:grid;place-items:center;font-weight:700;font-size:14px;color:var(--wi);background:var(--wc);padding:0 6px;font-family:inherit;cursor:pointer}
.wch-sz button.on{background:var(--wgd);border-color:var(--wgd);color:#fff}
.wch-sz button.no,.wch-sz button:disabled{opacity:.4;cursor:default}.wch-sz button.no{text-decoration:line-through}
.wch-qty{display:flex;align-items:center;background:var(--ws);border-radius:99px}
.wch-qty button{width:42px;height:42px;border:0;background:none;border-radius:50%;display:grid;place-items:center;color:var(--wi);cursor:pointer;padding:0}
.wch-qty b{min-width:26px;text-align:center;font-size:16px;font-weight:800}
.wch-split{border:1px solid var(--wl);border-radius:18px;padding:12px;background:var(--wc)}
.wch-split .g{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.wch-split .p{border-radius:14px;padding:9px 11px;min-height:70px;min-width:0}
.wch-split .p small{display:block;font-size:12px;font-weight:700}
.wch-split .p b{display:block;font-size:22px;font-weight:800;letter-spacing:-.02em;line-height:1.2;font-variant-numeric:tabular-nums}
.wch-split .p em{display:block;font-style:normal;font-size:12px;margin-top:1px;opacity:.85}
.wch-split .p.a{background:#e0f0f3;color:#0b4f5c}.wch-split .p.b{background:#ece7ff;color:#43309e}
html.wxdk .wch-split .p.a,html.wchdk .wch-split .p.a,.wch-dk .wch-split .p.a{background:#0f3a40;color:#9fe0ea}html.wxdk .wch-split .p.b,html.wchdk .wch-split .p.b,.wch-dk .wch-split .p.b{background:#2a2150;color:#c0adff}
.wch-split .sb{display:flex;height:10px;border-radius:9px;overflow:hidden;margin:10px 0 6px}
.wch-split .sb i{display:block;height:100%}
.wch-split .nt{font-size:12.5px;color:var(--wm);line-height:1.4}
.wch-com{display:flex;align-items:center;gap:12px;border-radius:16px;padding:11px 12px;background:var(--abg);color:var(--afg);margin-top:8px}
.wch-com .pc{font-size:22px;font-weight:800;letter-spacing:-.02em;white-space:nowrap}
.wch-com div{font-size:13px;line-height:1.35;flex:1;min-width:0}.wch-com div b{display:block;font-size:14px}
.wch-com .amt{font-weight:800;font-size:17px;white-space:nowrap}
.wch-b{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:48px;padding:0 20px;border-radius:24px;border:0;font-weight:700;font-size:15px;font-family:inherit;background:var(--wgd);color:#fff;white-space:nowrap;text-decoration:none;cursor:pointer}
.wch-b.soft{background:var(--ws);color:var(--wi)}.wch-b.line{background:transparent;border:1.5px solid var(--wl);color:var(--wi)}
.wch-b.red{background:var(--rbg);color:var(--rfg)}.wch-b.blk{width:100%}.wch-b.s{min-height:42px;padding:0 15px;font-size:14px}
.wch-b:disabled,.wch-b.dis{background:var(--wl);color:var(--wm);cursor:default}
.wch-b.red:disabled{opacity:.6}
.wch-b.vio{background:#5b3fd6}
.wch-foot{flex:none;background:var(--wb);border-top:1px solid var(--wl);padding:10px 14px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.wch-foot .why{flex:1 1 100%;font-size:12.5px;color:var(--afg);background:var(--abg);border-radius:12px;padding:7px 11px;font-weight:600;display:flex;gap:7px;align-items:center;overflow-wrap:anywhere}
.wch-foot .why.red{color:var(--rfg);background:var(--rbg)}.wch-foot .why.blu{color:var(--ifg);background:var(--ibg)}.wch-foot .why.grn{color:var(--gfg);background:var(--gbg)}
.wch-foot .wch-b{flex:1;min-width:0}
.wch-foot .wch-b.fx{flex:0 0 auto}
.wch-ban{display:flex;gap:10px;align-items:flex-start;border-radius:14px;padding:10px 12px;font-size:13px;line-height:1.4;margin:10px 0 0;background:var(--abg);color:var(--afg);overflow-wrap:anywhere}
.wch-ban svg{margin-top:1px}.wch-ban b{display:block}.wch-ban>div{min-width:0;flex:1}
.wch-ban.red{background:var(--rbg);color:var(--rfg)}.wch-ban.grn{background:var(--gbg);color:var(--gfg)}.wch-ban.blu{background:var(--ibg);color:var(--ifg)}
.wch-ban .wch-b{margin-top:8px}
.wch-cl{background:linear-gradient(135deg,#f4f0ff,#eef6ff);border:1px solid #ddd6fe;border-radius:18px;padding:12px 13px;color:#2f2a55;margin-top:10px}
html.wxdk .wch-cl,html.wchdk .wch-cl,.wch-dk .wch-cl{background:linear-gradient(135deg,#221b44,#142238);border-color:#3a3070;color:#dcd6ff}
.wch-cl .hd{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:#3b2a8a;margin-bottom:6px}
html.wxdk .wch-cl .hd,html.wchdk .wch-cl .hd,.wch-dk .wch-cl .hd{color:#c0adff}
.wch-cl .sp{width:26px;height:26px;border-radius:9px;background:#3b2a8a;color:#fff;display:grid;place-items:center;flex:none}
.wch-cl p{margin:0;font-size:13.5px;line-height:1.45;overflow-wrap:anywhere}
.wch-cl .opt{display:flex;flex-direction:column;gap:7px;margin-top:10px}
.wch-cl .opt button{min-height:46px;border-radius:14px;border:1.5px solid #cfc4fb;background:#fff;color:#2f2a55;font-weight:700;font-size:13.5px;font-family:inherit;text-align:left;padding:7px 12px;display:flex;gap:9px;align-items:center;line-height:1.3;cursor:pointer}
.wch-cl .opt button.on{border-color:#5b3fd6;background:#efeaff}
html.wxdk .wch-cl .opt button,html.wchdk .wch-cl .opt button,.wch-dk .wch-cl .opt button{background:#1b2540;border-color:#3a3070;color:#e3deff}
html.wxdk .wch-cl .opt button.on,html.wchdk .wch-cl .opt button.on,.wch-dk .wch-cl .opt button.on{background:#2a2150;border-color:#8b6cff}
.wch-cl .opt button small{display:block;font-weight:500;font-size:12px;color:#5b5686}
html.wxdk .wch-cl .opt button small,html.wchdk .wch-cl .opt button small,.wch-dk .wch-cl .opt button small{color:#a9a3d8}
.wch-ch{border:1.5px solid var(--wl);border-radius:18px;padding:10px;margin-bottom:9px;background:var(--wc);display:flex;gap:11px}
.wch-ch.pin{border-color:var(--wg);background:rgba(0,168,132,.05)}
.wch-ch.off{opacity:.62;border-style:dashed}
.wch-ch .ph{position:relative;flex:none;width:84px}
.wch-ch .ph .wch-ph{width:84px;height:84px;border-radius:14px}
.wch-ch .ph .two{display:grid;grid-template-columns:1fr 1fr;gap:2px;width:84px;height:84px;border-radius:14px;overflow:hidden}
.wch-ch .ph .two span{display:block;background-size:cover;background-position:center;background-color:var(--ws)}
.wch-ch .no{position:absolute;top:-6px;left:-6px;width:26px;height:26px;border-radius:50%;background:var(--wgd);color:#fff;font-weight:800;font-size:13px;display:grid;place-items:center;border:2px solid var(--wc)}
.wch-ch .bd{flex:1;min-width:0}
.wch-ch .bd b{display:block;font-size:15px;line-height:1.25;overflow-wrap:anywhere}
.wch-ch .bd small{display:block;font-size:12.5px;color:var(--wm);margin:1px 0 5px;overflow-wrap:anywhere}
.wch-ch .pr{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.wch-ch .pr s{color:var(--wm);font-size:13px}.wch-ch .pr strong{font-size:18px;font-weight:800;letter-spacing:-.01em}
.wch-df{display:inline-block;margin-top:5px;font-size:12px;font-weight:800;color:var(--gfg);background:var(--gbg);border-radius:99px;padding:3px 10px}
.wch-df.n{color:var(--wm);background:var(--ws)}
.wch-mrk{display:inline-block;font-size:11.5px;font-weight:800;border-radius:99px;padding:2px 9px;white-space:nowrap;margin:4px 6px 0 0}
.wch-mrk.ok{background:var(--gbg);color:var(--gfg)}.wch-mrk.low{background:var(--abg);color:var(--afg)}.wch-mrk.out{background:var(--rbg);color:var(--rfg)}.wch-mrk.n{background:var(--ws);color:var(--wm)}.wch-mrk.v{background:var(--vbg);color:var(--vfg)}
.wch-ch .bd .wch-b{margin-top:7px}
.wch-rs{display:flex;gap:11px;padding:11px 0;border-bottom:1px solid var(--wl);align-items:flex-start}
.wch-rs:last-child{border-bottom:0}
.wch-rs.dim{opacity:.55}
.wch-rs .bd{flex:1;min-width:0}
.wch-rs .bd b{display:block;font-size:15.5px;line-height:1.25;font-weight:700;overflow-wrap:anywhere}
.wch-rs .bd small{display:block;font-size:12.5px;color:var(--wm);margin:1px 0 4px;overflow-wrap:anywhere}
.wch-add{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
.wch-add button{position:relative;min-width:56px;height:44px;border-radius:99px;border:1.5px solid var(--wgd);background:var(--wc);color:var(--wgd);font-weight:800;font-size:14.5px;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:4px;padding:0 10px;cursor:pointer}
.wch-add button.on{background:var(--wgd);color:#fff}
.wch-add button .n{background:#fff;color:var(--wgd);border-radius:99px;font-size:11.5px;min-width:19px;height:19px;display:grid;place-items:center;padding:0 5px;font-weight:800}
.wch-add button.off,.wch-add button:disabled{border-color:var(--wl);color:var(--wm);background:var(--ws);text-decoration:line-through;font-weight:600;cursor:default}
.wch-add button.mn{border-color:var(--rfg);color:var(--rfg);min-width:44px;padding:0}
.wch-add button.pin{border-color:#5b3fd6;color:#5b3fd6;padding:0 16px;text-decoration:none}
.wch-add button.pin.on{background:#5b3fd6;color:#fff}
.wch-hlp{font-size:12.5px;color:var(--wm);margin:-2px 2px 4px}
.wch-emp{text-align:center;color:var(--wm);padding:26px 10px;font-size:14px;line-height:1.5}
.wch-emp b{display:block;color:var(--wi);font-size:15.5px;margin-bottom:3px}
.wch-sk{height:62px;border-radius:14px;background:linear-gradient(90deg,var(--ws),var(--wl),var(--ws));background-size:200% 100%;animation:wchsk 1.2s linear infinite;margin:10px 0}
@keyframes wchsk{to{background-position:-200% 0}}
.wch-tl{list-style:none;margin:0;padding:0}
.wch-tl li{position:relative;padding:0 0 14px 44px;min-height:48px}
.wch-tl li:before{content:"";position:absolute;left:15px;top:30px;bottom:-2px;width:2px;background:var(--wl)}
.wch-tl li:last-child:before{display:none}
.wch-tl li.done:before{background:var(--gfg)}
.wch-tl .dt{position:absolute;left:0;top:0;width:32px;height:32px;border-radius:50%;background:var(--ws);color:var(--wm);display:grid;place-items:center;font-weight:800;font-size:13px}
.wch-tl li.done .dt{background:var(--gfg);color:#fff}
.wch-tl li.cur .dt{background:#f59e0b;color:#fff;box-shadow:0 0 0 5px rgba(245,158,11,.25)}
.wch-tl li.failed .dt{background:var(--rfg);color:#fff}
.wch-tl .hd{display:flex;justify-content:space-between;gap:8px;min-height:32px;align-items:center}
.wch-tl .hd b{font-size:15.5px;font-weight:700}
.wch-tl li.todo .hd b{color:var(--wm);font-weight:600}
.wch-tl .hd time{font-size:12.5px;color:var(--wm);white-space:nowrap}
.wch-tl .sub{font-size:13px;color:var(--wm);line-height:1.4;margin-top:-2px;overflow-wrap:anywhere}
.wch-tl .sub b{color:var(--wi)}
.wch-segs{display:flex;gap:3px;height:9px;border-radius:9px;overflow:hidden;margin-bottom:16px}
.wch-segs i{flex:1;background:var(--wl)}.wch-segs i.d{background:var(--gfg)}.wch-segs i.c{background:#f59e0b}.wch-segs i.f{background:var(--rfg)}
.wch-rem{background:var(--abg);border-radius:16px;padding:11px 12px;margin-top:9px;color:var(--afg)}
.wch-rem .r{display:flex;justify-content:space-between;gap:8px;align-items:baseline;font-size:13px}
.wch-rem .r b{font-size:16px}
.wch-rem p{margin:6px 0 0;font-size:12.5px;line-height:1.4}
.wch-wait{font-size:13px;margin-top:-2px}.wch-wait b{color:#b45309;font-size:15px}
html.wxdk .wch-wait b,html.wchdk .wch-wait b,.wch-dk .wch-wait b{color:#f3d36b}
.wch-pickrow{display:flex;gap:6px;flex-wrap:wrap}
.wch-pickrow button{min-width:50px;flex:1;min-height:42px;border-radius:99px;border:1.5px solid var(--wl);background:var(--wc);color:var(--wi);font-weight:700;font-size:14px;font-family:inherit;cursor:pointer;padding:0 8px;overflow-wrap:anywhere}
.wch-pickrow button.on{background:var(--wgd);border-color:var(--wgd);color:#fff}
.wch-pickrow button:disabled{opacity:.5;cursor:default}
.wch-card{border:1px solid var(--wl);border-radius:16px;padding:11px 12px;background:var(--wc);margin-top:10px}
.wch-card .kv{display:flex;justify-content:space-between;gap:10px;font-size:13.5px;padding:3px 0}
.wch-card .kv b{text-align:right;overflow-wrap:anywhere}
.wch-lock{border:1.5px dashed var(--wl);border-radius:18px;padding:10px;background:var(--ws);margin-top:6px}
.wch-lock .t{display:flex;gap:9px;align-items:flex-start;color:var(--wm)}
.wch-lock .t b{display:block;color:var(--wi);font-size:14.5px}
.wch-lock .t small{display:block;font-size:12.5px;margin-top:1px}
.wch-lock .wch-pickrow{margin-top:9px}
.wch-hdl{padding:14px 14px 4px;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--wgd)}
html.wxdk .wch-hdl,html.wchdk .wch-hdl,.wch-dk .wch-hdl{color:#53bdeb}
.wch-mini{display:flex;align-items:center;gap:11px;padding:8px 14px;min-height:64px;border-top:1px solid var(--wl);width:100%;background:none;border-left:0;border-right:0;border-bottom:0;font-family:inherit;color:inherit;text-align:left;cursor:pointer}
.wch-mini .av{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:800;font-size:13px;flex:none;background:#0f6e7e}
.wch-mini .m{flex:1;min-width:0}
.wch-mini .m b{display:block;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wch-mini .m small{display:block;font-size:12.5px;color:var(--wm);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wch-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700;background:var(--ws);color:var(--wm);white-space:nowrap;line-height:1.4}
.wch-chip.ok{background:var(--gbg);color:var(--gfg)}.wch-chip.bad{background:var(--rbg);color:var(--rfg)}.wch-chip.warn{background:var(--abg);color:var(--afg)}.wch-chip.info{background:var(--ibg);color:var(--ifg)}
.wch-chip .dot{width:7px;height:7px;border-radius:50%;background:currentColor}
.wch-i{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:none;display:block}
.wch-i.s{width:14px;height:14px}
.wch-toast{position:fixed;left:50%;bottom:92px;transform:translateX(-50%);background:#14532d;color:#fff;padding:10px 18px;border-radius:12px;font-weight:700;font-size:13.5px;z-index:10080;max-width:90vw;text-align:center}
.wch-toast.bad{background:#b3261e}
.wch-chip-btn{display:flex;align-items:center;gap:9px;width:100%;min-height:44px;border:1px solid var(--wl);background:var(--wc);color:var(--wi);border-radius:14px;padding:6px 12px;font-family:inherit;font-size:13.5px;text-align:left;cursor:pointer}
.wch-chip-btn b{display:block;font-size:14px}.wch-chip-btn small{display:block;color:var(--wm);font-size:12px}
.wch-chip-btn .m{flex:1;min-width:0}
`;
  function injectCss() { if (document.getElementById('wchCss')) return; const s = document.createElement('style'); s.id = 'wchCss'; s.textContent = CSS; document.head.appendChild(s); }

  const IC = {
    back: 'M15 5l-7 7 7 7', x: 'M6 6l12 12M18 6L6 18', search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>', trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3', minus: 'M5 12h14', plus: 'M12 5v14M5 12h14', check: 'M5 12l5 5 9-10',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>', warn: 'M12 4l9 16H3zM12 10v4M12 17h.01', info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>', refresh: 'M20 11a8 8 0 10-2.3 5.7M20 5v6h-6',
    pin: 'M9 4h6l-1 6 3 3H7l3-3zM12 13v8', cart: 'M3 4h2l2.4 11h10l2-8H7M9 20h.01M17 20h.01', chev: 'M9 6l6 6-6 6', spark: 'M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z', send: 'M4 12l16-8-6 16-3-7z'
  };
  const ico = (n, c) => '<svg class="wch-i' + (c ? ' ' + c : '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (IC[n][0] === '<' ? IC[n] : '<path d="' + IC[n] + '"/>') + '</svg>';
  const bg = (u) => u ? ' style="background-image:url(\'' + esc(String(u).replace(/'/g, '%27')) + '\')"' : '';

  function apiOf(o) { if (o && o.api) return o.api; try { return typeof api !== 'undefined' ? api : null; } catch (e) { return null; } }
  const E = (e) => (e && e.message) || 'Something went wrong. Try again.';
  const code = (e) => (e && e.data && e.data.code) || '';
  function toast(msg, bad) {
    document.querySelectorAll('.wch-toast').forEach((x) => x.remove());
    const t = document.createElement('div'); t.className = 'wch wch-toast' + (bad ? ' bad' : ''); t.setAttribute('role', 'status'); t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), bad ? 5000 : 2600);
  }
  const STATUS_CHIP = { building: 'warn', options_sent: 'warn', chosen: 'info', link_sent: 'info', ordered: 'ok', billed: 'ok', cancelled: 'bad', expired: 'bad', failed: 'bad' };
  const OPEN = ['building', 'options_sent', 'chosen', 'link_sent'];

  // ------------------------------------------------------------------ the sheet
  let live = null;   // the one open sheet (a second press on an entry never stacks another)
  function open(o) {
    if (live && live.el && live.el.isConnected) return live;
    injectCss(); o = o || {};
    const A = apiOf(o);
    const S = {
      phone: String(o.phone || '').replace(/\D/g, '').slice(-10), name: o.name || '', view: 'load', cart: null, on: true, split: 50, lines: [], total: '', ml: '', psz: {}, pins: new Set(), q: '', cat: 'perfume', res: { perfume: { items: null }, other: { items: null }, chain: { items: null } }, qseq: 0,
      dirty: false, saving: false, saveTxt: '', fErr: {}, mlOk: false, sug: null, sel: new Set(), sugErr: '', sugBusy: false, confirmSend: false, sending: false, syncBusy: false, syncNote: '', syncAgo: null, st: null, others: [], pickSel: 0, banner: null, cancelOpen: false, busy: '', clearArm: false, lastAdd: {}
    };
    if (o.total) S.total = String(o.total); if (o.ml) S.ml = String(o.ml);
    const ov = document.createElement('div'); ov.className = 'wch-ov';
    if (o.dark) ov.classList.add('wch-dk');
    ov.innerHTML = '<div class="wch-sh" role="dialog" aria-modal="true" aria-label="Make cart"><div class="wch-grab"></div><div class="wch-sh-hd"><div style="flex:1;min-width:0"><h2 id="wchT">Make cart</h2><small id="wchS"></small></div><button type="button" class="wch-x" id="wchDg" title="🩺 Diagnose chains — why chains are missing (Shopify sync, chain / perfume sorting, prices, this cart)" aria-label="Diagnose chains" style="margin-right:6px;width:auto;padding:0 10px;border-radius:99px;font-size:13px;font-weight:700">🩺 Diagnose</button><button type="button" class="wch-x" id="wchX" aria-label="Close">' + ico('x') + '</button></div><div class="wch-steps" id="wchSt"></div><div class="wch-body" id="wchB"></div><div class="wch-foot" id="wchF"></div></div>';
    document.body.appendChild(ov);
    const $ = (id) => ov.querySelector('#' + id);
    let closed = false, pollT = null, timers = [];
    const later = (fn, ms) => { const t = setTimeout(() => { if (!closed) fn(); }, ms); timers.push(t); return t; };
    function close() { if (closed) return; closed = true; if (live && live.el === ov) live = null; clearInterval(pollT); timers.forEach(clearTimeout); document.removeEventListener('keydown', onKey); ov.remove(); }
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    ov.addEventListener('mousedown', (e) => { if (e.target === ov && !S.saving && !S.dirty) close(); });
    $('wchX').onclick = () => { flushNow().then(close); };
    $('wchDg').onclick = () => chainDiag();   // on every step of the cart
    const notify = () => { if (typeof o.onChange === 'function' && S.cart) { try { o.onChange(S.cart); } catch (e) { } } };

    // ---- helpers over state
    const isPerf = (l) => (l.seg || 'perfume') === 'perfume';
    const mlUsed = () => S.lines.reduce((n, l) => n + (isPerf(l) ? (l.ml || 0) * l.qty : 0), 0);   // perfume ml only: attar, shower gel and wax are counted apart
    const extrasTxt = () => { const g = {}; S.lines.forEach((l) => { if (isPerf(l)) return; const k = l.seg || 'other'; g[k] = g[k] || { ml: 0, n: 0 }; g[k].ml += (l.ml || 0) * l.qty; g[k].n += l.qty; }); return Object.keys(g).map((k) => '＋ ' + ({ attar: 'Attar', gel: 'Shower gel', wax: 'Wax', other: 'Other' }[k] || k) + ' ×' + g[k].n).join(' · '); };   // attar / shower gel / others are fixed sizes: counted in pieces
    const mlWant = () => parseInt(S.ml, 10) || 0;
    const totWant = () => parseInt(String(S.total).replace(/[^\d]/g, ''), 10) || 0;
    const nm = () => S.name || (S.cart && S.cart.customer) || 'the customer';
    const blockers = () => ((S.cart && S.cart.blockers) || []).filter((b) => b.code !== 'empty');
    const blockedBy = () => { const b = blockers(); return b.find((x) => x.code === 'billing_incomplete') || b.find((x) => x.code === 'off') || b.find((x) => x.code === 'no_combo_price') || (!S.on ? { code: 'off', text: 'Chain orders are switched off.' } : null); };
    function setHd(t, s) { $('wchT').textContent = t; $('wchS').textContent = s == null ? '' : s; }
    function setSteps(n) {
      const st = S.cart ? S.cart.status : 'building', building = !S.cart || st === 'building';
      const d = (i) => i < n ? 'done' : (i === n ? 'on' : '');
      const b = (i, l) => '<button type="button" class="' + d(i) + '" data-st="' + i + '" ' + (building && S.cart && i < 3 ? '' : 'disabled') + '><b>' + (i < n ? ico('check', 's') : i) + '</b>' + l + '</button>';
      $('wchSt').innerHTML = b(1, 'Perfumes') + b(2, 'Chains') + b(3, 'Send');
    }
    $('wchSt').onclick = (e) => { const b = e.target.closest('[data-st]'); if (!b || b.disabled) return; const i = +b.dataset.st; if (i === 1) go('step1'); else if (i === 2 && canNext()) go('chains'); };
    function body(h) { $('wchB').innerHTML = h; }
    function foot(h) { $('wchF').innerHTML = h; }
    const why = (txt, cls, icon) => '<div class="why ' + (cls || '') + '">' + ico(icon || 'warn', 's') + '<span>' + esc(txt) + '</span></div>';
    const banner = (cls, icon, title, text, extra) => '<div class="wch-ban ' + cls + '">' + ico(icon) + '<div>' + (title ? '<b>' + esc(title) + '</b>' : '') + esc(text || '') + (extra || '') + '</div></div>';
    const skeleton = () => '<div class="wch-pad"><div class="wch-sk"></div><div class="wch-sk"></div><div class="wch-sk" style="height:110px"></div></div>';
    function errView(msg, retry) { setHd('Make cart', nm()); setSteps(1); body('<div class="wch-pad">' + banner('red', 'warn', 'Could not open the cart', msg) + '<div class="wch-emp"><button type="button" class="wch-b" id="wchRetry">Try again</button></div></div>'); foot('<button type="button" class="wch-b soft blk" id="wchCls">Close</button>'); $('wchRetry').onclick = retry || load; $('wchCls').onclick = close; }

    // ---- banners that apply to the whole cart (switch off / billing / server errors)
    function topBanners() {
      let h = '';
      const bl = blockers(), off = bl.find((b) => b.code === 'off') || (!S.on ? { text: 'Nothing is sent to any customer.' } : null), bi = bl.find((b) => b.code === 'billing_incomplete');
      if (off) h += banner('red', 'lock', 'Chain orders are switched off', 'Nothing is sent to any customer. You can still build and save the cart. Ask the owner to switch the cart flow on in Learn & Setup > Cart messages.');
      if (bi) h += banner('red', 'lock', 'Carts are switched off until billing is complete', bi.text + ' Ask the owner to finish Learn & Setup > Billing. Nothing is lost: your draft is saved.');
      const nc = bl.find((b) => b.code === 'no_combo_price'); if (nc) h += banner('red', 'lock', 'Create this combo first', nc.text + ' Your cart is saved; it can be sent as soon as the owner adds the row.');
      bl.filter((b) => b.code !== 'off' && b.code !== 'billing_incomplete' && b.code !== 'no_combo_price').forEach((b) => { h += banner('', 'warn', '', b.text); });
      if (S.banner) h += banner(S.banner.cls || 'red', S.banner.icon || 'warn', S.banner.title || '', S.banner.text || '', S.banner.extra || '');
      return h;
    }

    // ================================================================== load
    async function load(keep) {
      S.view = 'load'; setHd('Make cart', nm()); setSteps(1); body(skeleton()); foot('');
      if (!A) { errView('You are not logged in. Reload the page.'); return; }
      try {
        const [g, st] = await Promise.all([A.get('wch.cart_get', { phone: S.phone }), A.get('wch.settings', {}).catch(() => null)]);
        if (closed) return;
        S.on = g.on !== false; if (st && st.settings) { S.split = num(st.settings.wch_split_pct, 50); S.remindMin = num(st.settings.wch_remind_min, 30); if (st.settings.wch_on === 0 || st.settings.wch_on === false) S.on = false; }
        if (g.cart) { setCart(g.cart); S.phone = g.cart.phone || S.phone; if (!S.name && g.cart.customer) S.name = g.cart.customer; }
        route();
      } catch (e) { if (!closed) errView(E(e), () => load()); }
    }
    function setCart(c, keepLines) {
      S.cart = c; if (c.status === 'building' || !S.lines.length) { if (!keepLines) S.lines = c.lines.map((l) => ({ seg: l.seg || 'perfume', vid: l.vid, pid: l.pid, ml: l.ml, qty: l.qty, title: l.title, short: l.short, sku: l.sku, photo: l.photo })); }
      if (!S.total || !(S.dirty || S.saving)) S.total = String(c.total); if (!S.ml || !(S.dirty || S.saving)) S.ml = String(c.ml);
      if (c.split && c.split.pct) S.split = num(c.split.pct, S.split);
    }
    function route() {
      if (!S.cart) return go('start');
      if (S.cart.status === 'building') return go(S.view === 'chains' ? 'chains' : 'step1');
      go('progress');
    }
    function go(v) {
      S.view = v; S.banner = null; S.confirmSend = false; clearInterval(pollT); $('wchB').onclick = null; $('wchF').onclick = null;
      ({ start: vStart, step1: vStep1, search: vSearch, chains: vChains, progress: vProgress }[v] || vStep1)();
      const bd = $('wchB'); if (bd) bd.scrollTop = 0;
    }
    const belowNoReason = () => !!(S.cart && S.cart.price && S.cart.price.below && !S.cart.price.below_reason && !S.dirty && !S.saving);
    function canNext() {
      if (!S.cart || S.cart.status !== 'building' || !S.lines.length || blockedBy() || S.fErr.total || S.fErr.ml || belowNoReason()) return false;
      return mlUsed() === mlWant() || S.mlOk;
    }

    // ================================================================== start (no cart yet)
    function vStart() {
      setHd('Make cart', nm()); setSteps(1);
      body('<div class="wch-pad">' + topBanners() + '<div class="wch-lb">The deal</div>' + fieldsHtml() + '<div class="wch-hint">This is what ' + esc(first(nm())) + ' pays in all, GST included. The customer only sees this total.</div>'
        + '<div class="wch-card"><div class="kv"><span>Chain share</span><b>' + esc(S.split) + '% of the total</b></div><div class="kv"><span>Perfumes</span><b>the rest, by ml</b></div></div>' + '</div>');
      foot('<div id="wchWhy" style="flex:1 1 100%"></div><button type="button" class="wch-b soft fx" id="wchCls">Close</button><button type="button" class="wch-b" id="wchGo">Start cart ' + ico('chev') + '</button>');
      bindFields(); $('wchCls').onclick = close; $('wchGo').onclick = startCart; chkStart();
    }
    function chkStart() { const ok = totWant() >= 100 && totWant() <= 500000 && mlWant() >= 1 && mlWant() <= 5000; const g = $('wchGo'); if (g) g.disabled = !ok; const w = $('wchWhy'); if (w) w.innerHTML = ok ? '' : why('Enter the agreed total (Rs.100 or more) and the total ml.', '', 'warn'); }
    async function startCart() {
      const b = $('wchGo'); b.disabled = true; b.textContent = 'Starting…';
      try {
        let r; try { r = await A.post('wch.cart_new', { phone: S.phone, total: totWant(), ml: mlWant() }); }
        catch (e) { if (code(e) === 'cart_exists') { const g = await A.get('wch.cart_get', { id: e.data.cart_id }); r = { cart: g.cart }; } else throw e; }
        if (closed) return; S.dirty = false; setCart(r.cart); notify(); go('step1');
      } catch (e) { toast(E(e), true); if ($('wchGo')) { $('wchGo').disabled = false; $('wchGo').innerHTML = 'Start cart ' + ico('chev'); } }
    }

    // ---- total + ml fields (inputs are built once per view; typing never re-renders them)
    function fieldsHtml() {
      return '<div class="wch-two"><label class="wch-fld ' + (S.fErr.total ? 'er' : 'on') + '" id="wchFt"><span style="display:block;font-size:12px;color:var(--wm);font-weight:700">Agreed total (GST incl.)</span><span class="v"><i>₹</i><input id="wchTot" inputmode="numeric" autocomplete="off" value="' + esc(S.total ? Number(S.total).toLocaleString('en-IN') : '') + '" aria-label="Agreed total in rupees"></span></label>'
        + '<label class="wch-fld ' + (S.fErr.ml ? 'er' : '') + '" id="wchFm"><span style="display:block;font-size:12px;color:var(--wm);font-weight:700">Total ml</span><span class="v"><input id="wchMl" inputmode="numeric" autocomplete="off" value="' + esc(S.ml) + '" aria-label="Total ml"><i>ml</i></span></label></div><div id="wchFe"></div>';
    }
    function bindFields() {
      const t = $('wchTot'), m = $('wchMl'); if (!t || !m) return; let tmr = null;
      const sync = () => {
        S.total = t.value.replace(/[^\d]/g, ''); S.ml = m.value.replace(/[^\d]/g, '');
        const tt = totWant(), mm = mlWant(); S.fErr = {};
        if (S.total && (tt < 100 || tt > 500000)) S.fErr.total = 'The total must be between ₹100 and ₹5,00,000.';
        if (S.ml && (mm < 1 || mm > 5000)) S.fErr.ml = 'Total ml must be between 1 and 5000.';
        $('wchFt').classList.toggle('er', !!S.fErr.total); $('wchFm').classList.toggle('er', !!S.fErr.ml); $('wchFe').innerHTML = (S.fErr.total ? '<div class="wch-err">' + esc(S.fErr.total) + '</div>' : '') + (S.fErr.ml ? '<div class="wch-err">' + esc(S.fErr.ml) + '</div>' : '');
        if (S.view === 'start') chkStart(); else { upd1(); clearTimeout(tmr); if (!S.fErr.total && !S.fErr.ml && S.total && S.ml) { S.saveTxt = 'Saving…'; tmr = setTimeout(() => queueSave(), 550); updFoot(); } }
      };
      t.oninput = () => { const p = t.value.replace(/[^\d]/g, ''); const pos = t.value.length - t.selectionStart; t.value = p ? Number(p).toLocaleString('en-IN') : ''; const np = Math.max(0, t.value.length - pos); try { t.setSelectionRange(np, np); } catch (e) { } sync(); };
      m.oninput = () => { m.value = m.value.replace(/[^\d]/g, ''); sync(); };
      t.onfocus = m.onfocus = function () { this.select(); };
    }

    // ================================================================== step 1: perfumes
    function vStep1() {
      setHd('Make cart', nm() + (S.cart && S.cart.emp_name ? ' · ' + S.cart.emp_name : '')); setSteps(1);
      body('<div class="wch-pad"><div id="wchBan">' + topBanners() + '</div><div class="wch-lb">The deal</div>' + fieldsHtml() + '<div class="wch-hint">This is what ' + esc(first(nm())) + ' pays in all. The customer only sees this total.</div>'
        + '<div id="wchProg" style="margin-top:10px"></div><div id="wchPrice"></div><div class="wch-lb">Find products</div><button type="button" class="wch-srchbtn" id="wchSb">' + ico('search') + '<span>Search perfumes</span></button>'
        + '<div class="wch-cats"><button type="button" class="on" data-c="perfume">Perfumes</button><button type="button" data-c="chain">Chains</button><button type="button" data-c="other">Gel &amp; more</button></div>'
        + '<div id="wchLines"></div><div class="wch-lb">Split</div><div id="wchSplit"></div><div class="wch-lb">Your commission</div><div id="wchCom"></div></div>');
      bindFields();
      $('wchSb').onclick = () => { S.cat = 'perfume'; go('search'); };
      ov.querySelector('.wch-cats').onclick = (e) => { const b = e.target.closest('[data-c]'); if (b) { S.cat = b.dataset.c; go('search'); } };
      $('wchB').onclick = onStep1Click;
      upd1(); fillSizes();
    }
    function onStep1Click(e) {
      const b = e.target.closest('[data-a]'); if (!b) return; const a = b.dataset.a, vid = +b.dataset.vid;
      if (a === 'rm') { S.lines = S.lines.filter((l) => l.vid !== vid); lineChanged(); }
      else if (a === 'qp') { const l = S.lines.find((x) => x.vid === vid); if (l && l.qty < 20) { l.qty++; lineChanged(); } }
      else if (a === 'qm') { const l = S.lines.find((x) => x.vid === vid); if (l) { if (l.qty > 1) l.qty--; else S.lines = S.lines.filter((x) => x.vid !== vid); lineChanged(); } }
      else if (a === 'sz') changeSize(vid, +b.dataset.nv);
      else if (a === 'clr') { if (!S.clearArm) { S.clearArm = true; b.textContent = 'Tap again to clear all'; later(() => { S.clearArm = false; const x = ov.querySelector('[data-a="clr"]'); if (x) x.textContent = 'Clear all'; }, 3500); } else { S.clearArm = false; S.lines = []; lineChanged(); } }
    }
    function changeSize(vid, nv) {
      const l = S.lines.find((x) => x.vid === vid); if (!l || nv === vid) return; const sz = ((S.psz[l.pid] || []).find((s) => s.vid === nv)); if (!sz) return;
      const ex = S.lines.find((x) => x.vid === nv); if (ex) { ex.qty = Math.min(20, ex.qty + l.qty); S.lines = S.lines.filter((x) => x.vid !== vid); } else { l.vid = nv; l.ml = sz.ml; l.sku = sz.sku || l.sku; }
      lineChanged();
    }
    function lineChanged() { S.mlOk = false; upd1(); updSearchHead(); S.saveTxt = 'Saving…'; updFoot(); queueSave(); }
    function progHtml() {
      const used = mlUsed(), want = mlWant(); let chip, cls = '';
      if (!want) chip = '<span class="n">Enter the total ml</span>';
      else if (!used) chip = '<span class="n">' + want + ' ml to add</span>';
      else if (used === want) chip = '<span class="ok">Exact: ' + want + ' ml</span>';
      else if (used < want) chip = '<span>' + (want - used) + ' ml to go</span>';
      else { chip = '<span class="bad">' + (used - want) + ' ml over</span>'; cls = ' over'; }
      const denom = Math.max(want, used, 1); let bars = '', leg = '';
      S.lines.filter(isPerf).forEach((l, i) => { const w = (l.ml || 0) * l.qty * 100 / denom; bars += '<i class="' + SEGC[i % 3] + '" style="width:' + w.toFixed(1) + '%"></i>'; leg += '<span><i style="background:' + SEGHEX[i % 3] + '"></i>' + esc(l.short || l.title) + ' ' + (l.ml || '?') + (l.qty > 1 ? ' ×' + l.qty : '') + '</span>'; });
      const ex = extrasTxt(); if (ex) leg += '<span style="color:#7c5cfc;font-weight:700">' + esc(ex) + '</span>';
      return '<div class="wch-prog' + cls + '" role="status"><div class="r"><b>' + used + ' <small>of ' + (want || '–') + ' ml perfume</small></b>' + chip + '</div><div class="wch-bar" aria-hidden="true">' + bars + '</div>' + (leg ? '<div class="wch-leg">' + leg + '</div>' : '') + '</div>';
    }
    function lineHtml(l) {
      const sizes = S.psz[l.pid], vis = sizes ? (ML3.some((m) => sizes.some((s) => s.ml === m)) ? ML3.map((m) => sizes.find((s) => s.ml === m) || { ml: m, none: true }) : sizes) : [{ vid: l.vid, ml: l.ml, in_stock: true }];
      return '<div class="wch-ln"><div class="t"><span class="wch-ph"' + bg(l.photo) + '></span><div class="nm"><b>' + esc(l.short || l.title) + '</b><small>' + esc(l.sku || '') + '</small></div><span class="ml">' + ((l.ml || 0) * l.qty) + ' ml</span><button type="button" class="wch-rm" data-a="rm" data-vid="' + l.vid + '" aria-label="Remove ' + esc(l.short || l.title) + '">' + ico('trash') + '</button></div>'
        + '<div class="c"><div class="wch-sz">' + vis.map((s) => s.none ? '<button type="button" class="no" disabled>' + s.ml + '</button>' : '<button type="button" class="' + (s.vid === l.vid ? 'on' : (s.in_stock === false ? 'no' : '')) + '" data-a="sz" data-vid="' + l.vid + '" data-nv="' + s.vid + '" ' + (s.in_stock === false && s.vid !== l.vid ? 'disabled' : '') + '>' + (s.ml || esc(s.label || '＋ Add')) + '</button>').join('') + '</div>'
        + '<div class="wch-qty"><button type="button" data-a="qm" data-vid="' + l.vid + '" aria-label="One less">' + ico('minus') + '</button><b>' + l.qty + '</b><button type="button" data-a="qp" data-vid="' + l.vid + '" aria-label="One more">' + ico('plus') + '</button></div></div></div>';
    }
    function priceHtml() {   // what the cart costs by the price chart (perfume) and the Teach prices (attar / shower gel / wax)
      const p = S.cart && S.cart.price; if (!p || !S.lines.length) return '';
      return '<div style="margin-top:6px;font-size:12px;color:#5b6472;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center"><span>💰 Price list <b>' + fm(p.list) + '</b></span><span>Lowest <b style="color:' + (p.below ? '#c0262d' : 'inherit') + '">' + fm(p.lowest) + '</b></span>'
        + (p.missing && p.missing.length ? '<span style="color:#9a5b00">No price yet: ' + esc(p.missing.join(', ')) + ' (asked on the Teach board)</span>' : '') + '</div>'
        + ladderHtml(p);
    }
    // the price ladder from the Price chart: one tap = that price as the agreed total (⭐ = the default column, the one the bot is taught to quote first)
    function ladderHtml(p) {
      const L = (p && p.ladder) || []; if (!L.length || !S.cart || S.cart.status !== 'building') return '';
      const cur = totWant(), dc = Number(p.default_col || 0);
      return '<div style="display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin-top:6px;padding-bottom:2px">' + L.map((x, i) => { const on = Math.round(cur) === Math.round(x.price);
        return '<button type="button" data-lad="' + x.price + '" style="flex:0 0 auto;border:1px solid ' + (on ? '#0f6e7e' : '#d9dde3') + ';background:' + (on ? '#e6f4f1' : '#fff') + ';border-radius:10px;padding:4px 9px;font:inherit;font-size:12px;cursor:pointer;line-height:1.25;text-align:left">'
          + '<b style="display:block;font-size:13px">' + (i === dc ? '⭐ ' : '') + fm(x.price) + '</b><small style="color:#6b7280">' + (Math.round(x.pct * 100) / 100) + '%</small></button>'; }).join('') + '</div>';
    }
    function upd1() {
      if (S.view !== 'step1' || !$('wchProg')) return;
      $('wchProg').innerHTML = progHtml(); const pe = $('wchPrice'); if (pe) { pe.innerHTML = priceHtml(); pe.querySelectorAll('[data-lad]').forEach((b) => { b.onclick = () => { const t = $('wchTot'); if (!t) return; t.value = Number(b.dataset.lad).toLocaleString('en-IN'); if (t.oninput) t.oninput(); }; }); }
      $('wchLines').innerHTML = '<div class="wch-lb">Chosen perfumes (' + S.lines.length + ')' + (S.lines.length ? '<button type="button" class="wch-lk" data-a="clr">Clear all</button>' : '') + '</div>' + (S.lines.length ? S.lines.map(lineHtml).join('') : '<div class="wch-emp"><b>No perfumes yet</b>Search above and tap 20, 50 or 100 ml to add one.</div>');
      const sp = (S.cart && S.cart.split) || { pct: S.split, chain_share: Math.round(totWant() * S.split / 100), perfume_part: totWant() - Math.round(totWant() * S.split / 100) }, mm = mlWant();
      $('wchSplit').innerHTML = '<div class="wch-split"><div class="g"><div class="p a"><small>Perfumes</small><b>' + fm(sp.perfume_part) + '</b><em>' + (mm ? mm + ' ml · ' + fm(sp.perfume_part / mm) + ' per ml' : 'by ml') + '</em></div><div class="p b"><small>Chain</small><b>' + fm(sp.chain_share) + '</b><em>' + esc(first(nm())) + ' picks it next</em></div></div>'
        + '<div class="sb"><i style="width:' + (100 - sp.pct) + '%;background:#0f6e7e"></i><i style="width:' + sp.pct + '%;background:#7c5cfc;margin-left:2px"></i></div><div class="nt">Split ' + (100 - sp.pct) + '% / ' + sp.pct + '% of ' + fm(totWant() || (S.cart && S.cart.total)) + ' (change it in Learn &amp; Setup &gt; Billing). The chain is its own GST supply. The customer sees only the total.</div></div>';
      const cm = S.cart && S.cart.commission;
      $('wchCom').innerHTML = cm ? '<div class="wch-com"><span class="pc">' + esc(cm.pct == null ? '–' : cm.pct) + '%</span><div><b>Commission at ' + fm(S.cart.total) + '</b>' + esc(cm.note || 'On the total, not per item.') + '</div><span class="amt">' + fm(cm.amount) + '</span></div>' : '<div class="wch-hint">No commission slab for this amount.</div>';
      $('wchBan').innerHTML = topBanners();
      updFoot();
    }
    function updFoot() {
      const f = $('wchF'); if (!f) return;
      if (S.view === 'step1') {
        const used = mlUsed(), want = mlWant(), bb = blockedBy(); let w = '', extra = '';
        if (bb) w = why(bb.code === 'billing_incomplete' ? 'Cart is blocked: ' + (/\((\d+ of \d+)/.exec(bb.text) ? 'Billing is ' + /\((\d+ of \d+)/.exec(bb.text)[1] : 'Billing is not complete') : (bb.code === 'no_combo_price' ? bb.text : 'Chain orders are switched off'), 'red', 'lock');
        else if (S.fErr.total || S.fErr.ml) w = why(S.fErr.total || S.fErr.ml);
        else if (!S.lines.length) w = why('Add at least one perfume.');
        else if (belowNoReason()) { w = why('The agreed total is below the lowest price (' + fm(S.cart.price.lowest) + '). Write why to go on — the owner sees it.', 'red'); extra = '<div style="display:flex;gap:6px;width:100%"><input id="wchBr" maxlength="280" placeholder="Reason (e.g. repeat customer, owner agreed)" style="flex:1;min-width:0;padding:8px;border:1px solid #d9dde3;border-radius:10px;font:inherit"><button type="button" class="wch-b s" id="wchBrGo" style="flex:0 0 auto">Save reason</button></div>'; }
        else if (used < want) { w = why((want - used) + ' ml still to add to reach ' + want + ' ml'); if (!S.mlOk) extra = '<button type="button" class="wch-lk n" data-a="ok">Go on with ' + used + ' ml anyway</button>'; }
        else if (used > want) { w = why((used - want) + ' ml over ' + want + ' ml. Remove some or change the total ml.'); if (!S.mlOk) extra = '<button type="button" class="wch-lk n" data-a="ok">Go on with ' + used + ' ml anyway</button>'; }
        else if (S.saveTxt) w = why(S.saveTxt, S.saveTxt === 'Saved' ? 'grn' : (/could not/i.test(S.saveTxt) ? 'red' : 'blu'), S.saveTxt === 'Saved' ? 'check' : 'info');
        if (S.mlOk && used !== want) w = why('Going on with ' + used + ' ml instead of ' + want + ' ml.', 'blu', 'info');
        f.innerHTML = w + extra + '<button type="button" class="wch-b soft fx" id="wchSd" style="flex:0 0 120px">Save draft</button><button type="button" class="wch-b" id="wchNx" ' + (canNext() ? '' : 'disabled') + '>Next: chains ' + ico('chev') + '</button>';
        $('wchSd').onclick = saveDraft; $('wchNx').onclick = () => { if (canNext()) go('chains'); };
        const ok = f.querySelector('[data-a="ok"]'); if (ok) ok.onclick = () => { S.mlOk = true; updFoot(); };
        const br = $('wchBrGo'); if (br) br.onclick = async () => { const r = (($('wchBr') || {}).value || '').trim(); if (r.length < 4) return toast('Write the reason (a few words).', true); br.disabled = true; try { const x = await A.post('wch.below_reason', { id: S.cart.id, reason: r }); S.cart = x.cart; toast('Reason saved'); upd1(); } catch (e) { toast(E(e), true); br.disabled = false; } };
      }
    }
    async function saveDraft() { await flushNow(); if (S.saveTxt && /could not/i.test(S.saveTxt)) return; toast('Draft saved'); notify(); close(); }

    // ---- saving: one request at a time, the last edit always wins
    function queueSave() { S.dirty = true; if (!S.saving) flush(); }
    async function flush() {
      if (S.saving) return; S.saving = true;
      try {
        while (S.dirty && !closed) {
          S.dirty = false; S.saveTxt = 'Saving…'; updFoot();
          const p = { id: S.cart.id, lines: S.lines.map((l) => ({ vid: l.vid, qty: l.qty })) };
          if (!S.fErr.total && totWant()) p.total = totWant(); if (!S.fErr.ml && mlWant()) p.ml = mlWant();
          try { const r = await A.post('wch.cart_set', p); S.cart = r.cart; if (r.on === false) S.on = false; if (r.cart.split && r.cart.split.pct) S.split = num(r.cart.split.pct, S.split); S.saveTxt = 'Saved'; upd1(); }
          catch (e) {
            S.dirty = false; S.saveTxt = 'Could not save';
            if (code(e) === 'locked' || /already sent/i.test(E(e))) { toast(E(e), true); try { const g = await A.get('wch.cart_get', { id: S.cart.id }); setCart(g.cart); S.dirty = false; if (!closed) route(); } catch (e2) { } }
            else { toast(E(e), true); try { const g = await A.get('wch.cart_get', { id: S.cart.id }); S.cart = g.cart; S.lines = g.cart.lines.map((l) => ({ seg: l.seg || 'perfume', vid: l.vid, pid: l.pid, ml: l.ml, qty: l.qty, title: l.title, short: l.short, sku: l.sku, photo: l.photo })); S.total = String(g.cart.total); S.ml = String(g.cart.ml); S.fErr = {}; if (!closed && (S.view === 'step1')) { vStep1(); } else if (S.view === 'search') { updSearchHead(); renderRes(); } } catch (e2) { } }
          }
        }
      } finally { S.saving = false; if (!closed) { if (S.view === 'step1') upd1(); updSearchHead(); } }
    }
    async function flushNow() { if (S.cart && S.cart.status === 'building' && (S.dirty || S.saving)) { if (!S.saving) await flush(); else await new Promise((r) => { const t = setInterval(() => { if (!S.saving) { clearInterval(t); r(); } }, 60); }); } }

    // sizes of the products already in the cart (a reopened cart only has the chosen size): one search per product, in the background
    async function fillSizes() {
      const need = [...new Set(S.lines.filter((l) => l.pid && !S.psz[l.pid]).map((l) => l.pid))].slice(0, 8);
      for (const pid of need) {
        if (closed || S.view !== 'step1') return; const l = S.lines.find((x) => x.pid === pid); if (!l) continue;
        try { const r = await A.get('wch.search', { q: l.title, cat: 'perfume', limit: 8, in_stock: 0 }); const it = (r.items || []).find((x) => x.pid === pid); S.psz[pid] = it ? it.sizes : []; if (!it) S.psz[pid] = [{ vid: l.vid, ml: l.ml, in_stock: true }]; } catch (e) { S.psz[pid] = [{ vid: l.vid, ml: l.ml, in_stock: true }]; }
        if (!closed && S.view === 'step1') upd1();
      }
    }

    // ================================================================== search view
    function vSearch() {
      setSteps(1); updSearchHead();
      body('<div class="wch-pad"><div class="wch-prog" id="wchProg2" style="margin-top:2px"></div><div class="wch-srch" style="margin-top:10px"><div class="inp">' + ico('search') + '<input id="wchQ" type="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" placeholder="Search by name or code" value="' + esc(S.q) + '" aria-label="Search products"><button type="button" class="cl" id="wchQc" aria-label="Clear the search" ' + (S.q ? '' : 'hidden') + '>' + ico('x') + '</button></div></div>'
        + '<div class="wch-cats" id="wchCats"></div><div id="wchRes"></div></div>');
      foot('<button type="button" class="wch-b blk" id="wchBk"></button>');
      const q = $('wchQ'); let tm = null;
      q.oninput = () => { S.q = q.value; $('wchQc').hidden = !S.q; clearTimeout(tm); tm = setTimeout(doSearch, 260); if (!S.q.trim()) { S.res = { perfume: { items: null }, chain: { items: null } }; renderRes(); } };
      q.onkeydown = (e) => { if (e.key === 'Enter') { clearTimeout(tm); doSearch(); q.blur(); } };
      $('wchQc').onclick = () => { q.value = ''; S.q = ''; $('wchQc').hidden = true; S.res = { perfume: { items: null }, chain: { items: null } }; renderRes(); q.focus(); };
      $('wchCats').onclick = (e) => { const b = e.target.closest('[data-c]'); if (b) { S.cat = b.dataset.c; renderRes(); } };
      $('wchRes').onclick = onResClick;
      $('wchBk').onclick = () => go('step1');
      updSearchHead(); renderRes(); if (S.q.trim() && S.res.perfume.items === null) doSearch(); else if (!S.q) later(() => { try { q.focus(); } catch (e) { } }, 120);
    }
    function updSearchHead() {
      if (S.view !== 'search') return; setHd('Add products', nm() + ' · ' + mlUsed() + ' of ' + (mlWant() || '–') + ' ml');
      const p = $('wchProg2'); if (p) { const used = mlUsed(), want = mlWant(), denom = Math.max(want, used, 1); p.innerHTML = '<div class="r"><b>' + used + ' <small>of ' + (want || '–') + ' ml</small></b>' + (used === want && want ? '<span class="ok">Exact</span>' : used > want ? '<span class="bad">' + (used - want) + ' ml over</span>' : '<span>' + (want - used) + ' ml to go</span>') + '</div><div class="wch-bar">' + S.lines.map((l, i) => '<i class="' + SEGC[i % 3] + '" style="width:' + ((l.ml || 0) * l.qty * 100 / denom).toFixed(1) + '%"></i>').join('') + '</div>'; p.className = 'wch-prog' + (used > want ? ' over' : ''); }
      const b = $('wchBk'); if (b) b.textContent = 'Back to cart · ' + S.lines.length + (S.lines.length === 1 ? ' line' : ' lines') + ' · ' + mlUsed() + ' of ' + (mlWant() || '–') + ' ml';
    }
    async function doSearch() {
      const q = S.q.trim(); if (!q) return; const seq = ++S.qseq;
      S.res.perfume = { items: [], loading: true }; S.res.other = { items: [], loading: true }; S.res.chain = { items: [], loading: true }; renderRes();
      const one = async (cat) => { try { const r = await A.get('wch.search', { q, cat, limit: 30, in_stock: 0 }); return { items: r.items || [], q }; } catch (e) { return { items: [], err: E(e), q }; } };
      const [p, c, x] = await Promise.all([one('perfume'), one('chain'), one('other')]); if (closed || seq !== S.qseq) return;
      S.res.perfume = p; S.res.chain = c; S.res.other = x;
      (p.items || []).concat(x.items || []).forEach((it) => { S.psz[it.pid] = it.sizes; }); renderRes();
    }
    const stockMark = () => '';   // stock is not shown or checked: everything is sold as in stock
    function renderRes() {
      const R = $('wchRes'), C = $('wchCats'); if (!R) return; const cur = S.res[S.cat], q = S.q.trim();
      const cn = (k) => S.res[k].items && !S.res[k].loading && q ? '<b>' + S.res[k].items.length + '</b>' : '';
      C.innerHTML = '<button type="button" class="' + (S.cat === 'perfume' ? 'on' : '') + '" data-c="perfume">Perfumes ' + cn('perfume') + '</button><button type="button" class="' + (S.cat === 'chain' ? 'on' : '') + '" data-c="chain">Chains ' + cn('chain') + '</button><button type="button" class="' + (S.cat === 'other' ? 'on' : '') + '" data-c="other">Gel &amp; more ' + cn('other') + '</button>';
      if (!q) { R.innerHTML = '<div class="wch-emp"><b>Type a perfume name or code</b>Then tap 20, 50 or 100 ml to add it. Tap again for one more.</div>'; return; }
      if (cur.loading) { R.innerHTML = '<div class="wch-sk"></div><div class="wch-sk"></div><div class="wch-sk"></div>'; return; }
      if (cur.err) { R.innerHTML = '<div class="wch-emp"><b>Search did not work</b>' + esc(cur.err) + '<br><br><button type="button" class="wch-b s" data-a="retry">Try again</button></div>'; return; }
      if (!cur.items.length) { R.innerHTML = '<div class="wch-emp"><b>No ' + (S.cat === 'chain' ? 'chains' : 'perfumes') + ' match “' + esc(q) + '”</b>Try fewer letters or the product code.' + (S.cat === 'perfume' && S.res.chain.items && S.res.chain.items.length ? '<br>' + S.res.chain.items.length + ' chains match. Tap Chains above.' : '') + '</div>'; return; }
      let h = '<div class="wch-lb">' + cur.items.length + ' ' + (S.cat === 'chain' ? (cur.items.length === 1 ? 'chain matches' : 'chains match') : (cur.items.length === 1 ? 'result for' : 'results for')) + ' “' + esc(q) + '”</div>'
        + '<div class="wch-hlp">' + (S.cat === 'chain' ? 'Chains are offered to the customer in step 2. Pin one to ask for it in the options.' : 'Tap 20 / 50 / 100 ml to add. Tap again for one more.') + '</div>';
      cur.items.forEach((it) => { h += S.cat === 'chain' ? chainRow(it) : perfRow(it); });
      if (S.cat === 'chain') h += '<div class="wch-ban blu">' + ico('info') + '<div>To sell a chain alone, send its normal product link. No cart needed.</div></div>';
      R.innerHTML = h;
    }
    function perfRow(it) {
      const sz = it.sizes || [], pick = ML3.some((m) => sz.some((s) => s.ml === m)) ? ML3.map((m) => sz.find((s) => s.ml === m) || { ml: m, none: true }) : sz;
      const any = it.in_stock !== false && sz.some((s) => s.in_stock), inCart = sz.some((s) => S.lines.some((l) => l.vid === s.vid));
      return '<div class="wch-rs' + (any ? '' : ' dim') + '"><span class="wch-ph l"' + bg(it.photo) + '></span><div class="bd"><b>' + esc(it.short || it.title) + '</b><small>' + esc((it.title && it.title !== it.short ? it.title + ' · ' : '') + ((sz[0] && sz[0].sku) ? String(sz[0].sku).replace(/-\d+$/, '') : '') + (it.brand ? ' · ' + it.brand : '')) + '</small><div>' + stockMark(sz) + '</div>'
        + '<div class="wch-add">' + pick.map((s) => { if (s.none || !s.in_stock) return '<button type="button" class="off" disabled>' + (s.ml || esc(s.label || '＋ Add')) + '</button>'; const l = S.lines.find((x) => x.vid === s.vid); return '<button type="button" class="' + (l ? 'on' : '') + '" data-a="add" data-pid="' + it.pid + '" data-vid="' + s.vid + '" aria-label="Add ' + esc(it.short || it.title) + ' ' + (s.ml || '') + ' ml">' + (s.ml || esc(s.label || '＋ Add')) + (l ? '<span class="n">×' + l.qty + '</span>' : '') + '</button>'; }).join('')
        + (inCart ? '<button type="button" class="mn" data-a="less" data-pid="' + it.pid + '" aria-label="One less of ' + esc(it.short || it.title) + '">' + ico('minus') + '</button>' : '') + '</div></div></div>';
    }
    function chainRow(it) {
      const s = (it.sizes || [])[0] || {}, pinned = S.pins.has(s.vid);
      return '<div class="wch-rs' + (it.in_stock === false ? ' dim' : '') + '"><span class="wch-ph l"' + bg(it.photo) + '></span><div class="bd"><b>' + esc(it.short || it.title) + '</b><small>' + esc((s.sku || '') + (it.desc ? ' · ' + it.desc : (s.label ? ' · ' + s.label : ''))) + '</small><div><span class="wch-mrk n">MRP ' + fm(it.min_price || s.price) + '</span>' + stockMark(it.sizes || []) + '</div>'
        + '<div class="wch-add"><button type="button" class="pin' + (pinned ? ' on' : '') + '" data-a="pin" data-vid="' + s.vid + '">' + ico('pin', 's') + (pinned ? ' Pinned to options' : ' Pin to chain options') + '</button></div></div></div>';
    }
    function onResClick(e) {
      const b = e.target.closest('[data-a]'); if (!b) return; const a = b.dataset.a;
      if (a === 'retry') return doSearch();
      if (a === 'pin') { const v = +b.dataset.vid; if (S.pins.has(v)) S.pins.delete(v); else S.pins.add(v); return renderRes(); }
      const pid = +b.dataset.pid, it = ((S.res.perfume.items) || []).find((x) => x.pid === pid); if (!it) return;
      if (a === 'add') {
        const s = it.sizes.find((x) => x.vid === +b.dataset.vid); if (!s || !s.in_stock) return; const l = S.lines.find((x) => x.vid === s.vid);
        if (l) { if (l.qty >= 20) return toast('Up to 20 of one size', true); if (s.stock != null && l.qty >= s.stock) return toast('Only ' + s.stock + ' in stock', true); l.qty++; } else S.lines.push({ vid: s.vid, pid: it.pid, ml: s.ml, qty: 1, title: it.title, short: it.short, sku: s.sku, photo: it.photo, seg: it.seg || 'perfume' });
        S.lastAdd[pid] = s.vid; S.mlOk = false; renderRes(); updSearchHead(); queueSave();
      } else if (a === 'less') {
        let l = S.lines.find((x) => x.vid === S.lastAdd[pid]) || S.lines.find((x) => x.pid === pid); if (!l) return;
        if (l.qty > 1) l.qty--; else S.lines = S.lines.filter((x) => x !== l);
        S.mlOk = false; renderRes(); updSearchHead(); queueSave();
      }
    }

    // ================================================================== step 2: chains
    function vChains() {
      setHd('Chain options', nm() + (S.cart.emp_name ? ' · ' + S.cart.emp_name : '')); setSteps(2);
      body('<div class="wch-pad">' + skeleton() + '</div>'); foot('');
      loadSug();
    }
    async function loadSug() {
      S.sugBusy = true; S.sugErr = '';
      try { const r = await A.get('wch.chain_suggest', { id: S.cart.id }); if (closed || S.view !== 'chains') return; S.sug = r; if (r.on === false) S.on = false; initSel(); } catch (e) { if (closed) return; S.sug = null; S.sugErr = E(e); }
      S.sugBusy = false; if (S.view === 'chains') paintChains();
    }
    function initSel() {
      S.sel = new Set(); const r = S.sug; if (!r || r.case === 'gap' || r.case === 'none') return;
      const rec = (r.options || []).filter((o) => o.recommended); const pinned = rec.filter((o) => o.chains.some((c) => S.pins.has(c.vid))); const rest = rec.filter((o) => !pinned.includes(o));
      pinned.concat(rest).slice(0, Math.max(1, (S.cart.chain && 3) || 3)).forEach((o) => S.sel.add(o.id));
    }
    function optCard(o, i) {
      const on = S.sel.has(o.id), ch = o.chains || [], c0 = ch[0] || {}; const share = S.sug.share;
      const ph = ch.length > 1 ? '<span class="two"><span' + bg(ch[0].photo) + '></span><span' + bg(ch[1].photo) + '></span></span>' : '<span class="wch-ph"' + bg(c0.photo) + '></span>';
      const title = ch.map((c) => c.title).join(' + '), subt = ch.map((c) => (c.sku || '') + (c.variant ? ' · ' + c.variant : '')).join(' | ');
      const below = o.shifts_split || o.pay < share, minStock = Math.min.apply(null, ch.map((c) => c.stock == null ? 999 : c.stock));
      const gapTxt = o.shifts_split ? '<div style="font-size:12.5px;color:var(--afg);margin-top:5px">' + fm(Math.max(0, share - o.pay)) + ' below the ' + fm(share) + ' share. The perfumes get the rest.</div>' : '';
      return '<div class="wch-ch ' + (on ? 'pin' : 'off') + '" data-oid="' + esc(o.id) + '"><div class="ph">' + ph + '<span class="no">' + (i + 1) + '</span></div><div class="bd"><b>' + esc(title) + '</b><small>' + esc(subt) + '</small><div class="pr"><s>MRP ' + fm(o.mrp_total) + '</s><strong>Pays ' + fm(o.pay) + '</strong></div>'
        + (o.discount > 0 ? '<span class="wch-df">Discount ' + fm(o.discount) + '</span>' : '<span class="wch-df n">Full price, no discount</span>') + (minStock < 999 ? '<span class="wch-mrk ' + (minStock <= 3 ? 'low' : 'ok') + '" style="margin-left:6px">' + (minStock <= 3 ? 'Low stock · ' : 'In stock · ') + minStock + '</span>' : '')
        + (o.kind === 'pair' ? '<span class="wch-mrk v">Two chains</span>' : '') + (below ? '<span class="wch-mrk v">Below the share</span>' : (o.recommended && i === 0 ? '<span class="wch-mrk v">Nearest above</span>' : '')) + gapTxt
        + '<div><button type="button" class="wch-b soft s" data-a="tog" data-oid="' + esc(o.id) + '" aria-pressed="' + on + '">' + (on ? 'Leave this one out' : 'Include this one') + '</button></div></div></div>';
    }
    function paintChains() {
      const r = S.sug; const bb = blockedBy();
      if (S.sugErr || !r) { body('<div class="wch-pad">' + topBanners() + banner('red', 'warn', 'Could not load the chain options', S.sugErr || 'Try again.') + '<div class="wch-emp"><button type="button" class="wch-b" data-a="again">Try again</button> <button type="button" class="wch-b line" data-a="cdiag">🩺 Diagnose chains</button></div></div>'); foot('<button type="button" class="wch-b soft blk" data-a="back">' + ico('back') + ' Back</button>'); bindChains(); return; }
      const c = S.cart, sp = c.split, mm = mlUsed();
      let h = '<div class="wch-pad"><div style="margin:2px 0 10px">' + progHtml() + '</div><div id="wchBan">' + topBanners() + (S.mlOk && mm !== mlWant() ? banner('blu', 'info', '', 'Perfumes add up to ' + mm + ' ml, the order says ' + mlWant() + ' ml. You chose to go on.') : '') + '</div>'
        + '<div class="wch-two" style="grid-template-columns:1fr 1fr;margin-top:12px"><div class="wch-fld st"><label>Agreed total</label><div class="v"><i>₹</i>' + num(c.total).toLocaleString('en-IN') + '</div></div><div class="wch-fld st"><label>Perfumes</label><div class="v">' + mm + '<i>ml' + (mm === mlWant() ? ' ✓' : '') + '</i></div></div></div>'
        + '<div class="wch-lb">Split</div><div class="wch-split"><div class="g"><div class="p a"><small>Perfumes</small><b>' + fm(sp.perfume_part) + '</b><em>' + mm + ' ml' + (mm ? ' · ' + fm(sp.perfume_part / mm) + ' per ml' : '') + '</em></div><div class="p b"><small>Chain</small><b>' + fm(sp.chain_share) + '</b><em>' + esc(first(nm())) + ' picks it next</em></div></div><div class="sb"><i style="width:' + (100 - sp.pct) + '%;background:#0f6e7e"></i><i style="width:' + sp.pct + '%;background:#7c5cfc;margin-left:2px"></i></div><div class="nt">Split ' + (100 - sp.pct) + '% / ' + sp.pct + '% of ' + fm(c.total) + '.</div></div>'
        + '<div class="wch-lb" style="margin-bottom:2px">Chains for ' + esc(first(nm())) + '</div><div class="wch-hint" style="margin:0 2px 8px">' + (r.options.length ? 'MRP window ' + fm(r.window.from) + ' to ' + fm(r.window.to) + ' (share to ' + (1 + num(r.window.pct) / 100).toFixed(2).replace(/0$/, '') + ' ×)' : 'MRP window ' + fm(r.window.from) + ' to ' + fm(r.window.to) + ': nothing found') + '</div>'
        + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:9px;flex-wrap:wrap">' + (S.syncAgo === null ? '' : '<span class="wch-chip ok" id="wchSyc"><span class="dot"></span><span>' + (S.syncAgo === 0 ? 'Refreshed just now' : 'Synced ' + S.syncAgo + ' min ago') + '</span></span>') + '<span style="flex:1"></span><button type="button" class="wch-b line s" data-a="cdiag" title="Why are chains missing? Checks the Shopify sync, how products are sorted, prices and this cart">🩺 Diagnose chains</button><button type="button" class="wch-b line s" data-a="sync" ' + (S.syncBusy ? 'disabled' : '') + '>' + ico('refresh') + (S.syncBusy ? 'Refreshing…' : 'Refresh from Shopify') + '</button></div>' + (S.syncNote ? '<div class="wch-ban">' + ico('info') + '<div>' + esc(S.syncNote) + '</div></div>' : '');
      if (r.case === 'none') h += banner('', 'warn', 'No chain is in stock', r.claude || 'Upload chains and refresh, or sell the perfumes without a chain.') + '<div class="wch-card"><div class="kv"><span>Perfumes only</span><b>' + fm(c.total) + '</b></div></div>';
      (r.options || []).forEach((o, i) => { h += optCard(o, i); });
      if (S.pins.size) { const missing = [...S.pins].filter((v) => !(r.options || []).some((o) => o.chains.some((cc) => cc.vid === v))); if (missing.length) h += banner('blu', 'pin', '', 'A chain you pinned is outside this price window, so it cannot be offered for this order.'); }
      if (r.gap) h += '<div class="wch-cl"><div class="hd"><span class="sp">' + ico('spark', 's') + '</span>Claude: no chain between ' + fm(r.gap.need_from) + ' and ' + fm(r.gap.need_to) + '. Upload one.</div><p>' + esc(r.gap.text) + (r.claude ? ' ' + esc(r.claude) : '') + ' Choose:</p><div class="opt">' + (r.gap.choices || []).map((g) => '<button type="button" data-a="gap" data-k="' + esc(g.key) + '" data-oid="' + esc(g.option_id || '') + '" class="' + (g.option_id && S.sel.has(g.option_id) && S.sel.size === 1 ? 'on' : '') + '">' + ico(g.key === 'wait_refresh' ? 'refresh' : 'chev', 's') + '<span>' + esc(g.label) + '</span></button>').join('') + '</div></div>';
      else if (r.claude && r.case !== 'none' && r.case !== 'normal') h += '<div class="wch-cl"><div class="hd"><span class="sp">' + ico('spark', 's') + '</span>Claude</div><p>' + esc(r.claude) + '</p></div>';
      const n = S.sel.size;
      h += banner('blu', 'info', 'No cart is made yet', first(nm()) + ' gets ' + (n || 'the') + ' photo' + (n === 1 ? '' : 's') + ' and replies 1, 2, 3 or NO. The cart is created only after the choice. One reminder goes after ' + (S.remindMin || 30) + ' min.');
      h += '<div class="wch-lb">After the photos are sent</div><div class="wch-lock"><div class="t">' + ico('lock') + '<div><b>Enter choice manually</b><small>' + (r.case === 'none' ? 'There is nothing to offer, so you can continue without a chain.' : 'Unlocks after the photos are sent. If ' + esc(first(nm())) + '’s reply is unclear you pick here.') + '</small></div></div><div class="wch-pickrow">'
        + (r.options || []).filter((o, i) => i < 3).map((o, i) => '<button type="button" disabled>' + (i + 1) + '</button>').join('') + (r.case === 'none' ? '<button type="button" data-a="none" style="flex:1.8">No chain</button>' : '<button type="button" disabled style="flex:1.8">No chain</button>') + '</div></div></div>';
      body(h);
      // foot
      let w = ''; const noSel = !S.sel.size && r.options.length;
      if (bb) w = why(bb.code === 'billing_incomplete' ? 'Cart is blocked: ' + (/\((\d+ of \d+)/.exec(bb.text) ? 'Billing is ' + /\((\d+ of \d+)/.exec(bb.text)[1] : 'Billing is not complete') : (bb.code === 'no_combo_price' ? bb.text : 'Chain orders are switched off'), 'red', 'lock');
      else if (r.case === 'gap' && noSel) w = why('Pick one of Claude’s options above to continue');
      else if (noSel) w = why('Include at least one chain to send.');
      else if (S.sendErr) w = why(S.sendErr, 'red');
      const canSend = !bb && n > 0 && !S.sending;
      foot(w + '<button type="button" class="wch-b soft fx" data-a="back" style="flex:0 0 96px">' + ico('back') + ' Back</button>' + (r.case === 'none' ? '<button type="button" class="wch-b" data-a="none" ' + (S.cart.can && S.cart.can.pick ? '' : 'disabled') + '>Continue without a chain</button>'
        : (S.confirmSend ? '<button type="button" class="wch-b" data-a="send2" ' + (S.sending ? 'disabled' : '') + '>' + (S.sending ? 'Sending…' : 'Yes, send ' + n + ' photo' + (n === 1 ? '' : 's') + ' to ' + esc(first(nm()))) + '</button>' : '<button type="button" class="wch-b" data-a="send" ' + (canSend ? '' : 'disabled') + '>' + ico('send') + ' Send chain options</button>')));
      bindChains();
    }
    // 🩺 why no chains: Shopify sync, chain / perfume sorting, prices, this cart's price window (wch.chain_diagnose) — a report to copy
    async function chainDiag() {
      const back = S.view || 'step1'; const bindBack = () => { const f = () => go(back); $('wchF').onclick = (e) => { if (e.target.closest('[data-a="dgback"]')) f(); }; $('wchB').onclick = null; };
      body('<div class="wch-pad"><div class="wch-hint">Checking the Shopify catalog, the chains and this cart…</div><div class="wch-sk"></div><div class="wch-sk"></div></div>'); foot('<button type="button" class="wch-b soft blk" data-a="dgback">' + ico('back') + ' Back</button>'); bindBack();
      let d; try { d = await A.get('wch.chain_diagnose', { id: S.cart && S.cart.id ? S.cart.id : 0 }); } catch (e) { body('<div class="wch-pad">' + banner('red', 'warn', 'Could not run the check', e.message) + '</div>'); bindBack(); return; }
      const col = { bad: ['#fdecec', '#b42318', '❌'], warn: ['#fff7e6', '#9a6700', '⚠️'], ok: ['#ecfdf3', '#067647', '✅'] };
      body('<div class="wch-pad"><div class="wch-lb" style="margin-top:0">🩺 Why chains are missing</div>' + d.verdict.map((v, i) => '<div style="background:' + col[v.level][0] + ';border-radius:10px;padding:10px 12px;margin:8px 0"><b style="color:' + col[v.level][1] + '">' + col[v.level][2] + ' ' + (i + 1) + '. ' + esc(v.title) + '</b>' + (v.detail ? '<div style="margin-top:4px;font-size:13px">' + esc(v.detail) + '</div>' : '') + (v.fix ? '<div style="margin-top:4px;font-size:13px"><b>What to do:</b> ' + esc(v.fix) + '</div>' : '') + '</div>').join('')
        + '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700">All the facts (copy this to send)</summary><pre id="wchDgR" style="white-space:pre-wrap;font-size:12px;background:#f6f7f9;border-radius:8px;padding:10px;margin-top:6px;max-height:320px;overflow:auto">' + esc(d.report) + '</pre></details>'
        + '<div style="margin-top:10px"><button type="button" class="wch-b" data-a="cdcopy">📋 Copy the report</button></div></div>');
      const cp = document.querySelector('[data-a="cdcopy"]'); if (cp) cp.onclick = async (e) => { e.stopPropagation(); try { await navigator.clipboard.writeText(d.report); cp.textContent = 'Copied ✓'; } catch (x) { const r = document.createRange(); r.selectNodeContents(document.getElementById('wchDgR')); const sl = getSelection(); sl.removeAllRanges(); sl.addRange(r); cp.textContent = 'Selected — press Ctrl + C'; } };
      bindBack();
    }
    function bindChains() {
      const h = (e) => {
        const b = e.target.closest('[data-a]'); if (!b || b.disabled) return; const a = b.dataset.a;
        if (a === 'back') { S.sendErr = ''; return go('step1'); }
        if (a === 'again') { go('chains'); return; }
        if (a === 'tog') { const id = b.dataset.oid; if (S.sel.has(id)) S.sel.delete(id); else S.sel.add(id); S.confirmSend = false; S.sendErr = ''; return paintKeep(); }
        if (a === 'gap') { const k = b.dataset.k; if (k === 'wait_refresh') { return doSync(true); } S.sel = new Set([b.dataset.oid]); S.confirmSend = false; return paintKeep(); }
        if (a === 'sync') return doSync(false);
        if (a === 'cdiag') return chainDiag();
        if (a === 'send') { S.confirmSend = true; S.sendErr = ''; return paintKeep(); }
        if (a === 'send2') return doSend();
        if (a === 'none') return doPickNone();
      };
      $('wchB').onclick = h; $('wchF').onclick = h;
    }
    function paintKeep() { const y = $('wchB').scrollTop; paintChains(); $('wchB').scrollTop = y; }
    async function doSync(wait) {
      if (S.syncBusy) return; S.syncBusy = true; S.syncNote = wait ? 'Refreshing. Your cart stays saved.' : ''; paintKeep();
      try {
        let r, n = 0; do { r = await A.post('wch.sync', n ? { continue: 1 } : {}); n++; } while (r && r.result && r.result.incomplete && n < 40 && !closed);   // go on from where the last round stopped
        S.syncAgo = 0; S.syncNote = r && r.result && r.result.incomplete ? 'Still reading the catalog. Press Refresh again in a moment.' : '';
        if (closed) return; await loadSugQuiet();
      } catch (e) { S.syncNote = code(e) === 'scope_missing' || /scope/i.test(E(e)) ? E(e) : (/admin|not allowed|only/i.test(E(e)) ? 'Only a manager can refresh the catalog from Shopify. Ask one to press Refresh now in Learn & Setup > Products.' : E(e)); }
      S.syncBusy = false; if (!closed && S.view === 'chains') paintKeep();
    }
    async function loadSugQuiet() { try { const r = await A.get('wch.chain_suggest', { id: S.cart.id }); S.sug = r; initSel(); } catch (e) { } }
    async function doSend() {
      if (S.sending) return; S.sending = true; S.sendErr = ''; paintKeep();
      try {
        const r = await A.post('wch.chain_send', { id: S.cart.id, option_ids: [...S.sel], confirm_ml: !!S.mlOk }); if (closed) return;
        S.cart = r.cart; S.sending = false; S.confirmSend = false; toast('Chain options sent to ' + first(nm())); notify(); go('progress');
      } catch (e) {
        S.sending = false; S.confirmSend = false; const c = code(e);
        if (c === 'already_sent') { try { const g = await A.get('wch.cart_get', { id: S.cart.id }); S.cart = g.cart; notify(); go('progress'); return; } catch (e2) { } }
        if (c === 'billing_incomplete' || c === 'off') { try { const g = await A.get('wch.cart_get', { id: S.cart.id }); S.cart = g.cart; if (g.on === false) S.on = false; } catch (e2) { } }
        if (c === 'ml_mismatch') { S.sendErr = E(e); S.mlOk = false; }
        else S.sendErr = c === 'window_closed' ? E(e) : E(e);
        if (!closed && S.view === 'chains') paintKeep();
      }
    }
    async function doPickNone() {
      try { const r = await A.post('wch.chain_pick', { id: S.cart.id, pick: 'none' }); S.cart = r.cart; notify(); toast('Perfumes only. Now send the cart link.'); go('progress'); } catch (e) { S.sendErr = E(e); paintKeep(); }
    }

    // ================================================================== progress view (step 3)
    async function vProgress() {
      const c = S.cart; setHd('Cart for ' + (S.name || c.customer || 'customer'), fm(c.total) + ' · ' + c.ml + ' ml' + (c.commission && c.status !== 'cancelled' ? ' · you earn ' + fm(c.commission.amount) + ' when paid' : '')); setSteps(3);
      body('<div class="wch-pad">' + skeleton() + '</div>'); foot('');
      await refreshProgress(true);
      if (closed || S.view !== 'progress') return;
      clearInterval(pollT); pollT = setInterval(() => { if (!closed && S.view === 'progress' && !document.hidden && !S.busy) refreshProgress(false); }, 15000);
    }
    async function refreshProgress(initial) {
      try {
        const [g, st, ls] = await Promise.all([A.get('wch.cart_get', { id: S.cart.id }), A.get('wch.cart_status', { id: S.cart.id }), initial ? A.get('wch.cart_list', { days: 7 }).catch(() => null) : Promise.resolve(null)]);
        if (closed || S.view !== 'progress') return; const was = S.cart.status; S.cart = g.cart; S.st = st; if (g.on === false) S.on = false; if (ls) S.others = (ls.carts || []).filter((x) => x.id !== S.cart.id && ['cancelled', 'expired', 'failed'].indexOf(x.status) < 0).slice(0, 6);
        if (was !== S.cart.status) notify(); S.progErr = ''; paintProgress();
      } catch (e) { if (closed || S.view !== 'progress') return; S.progErr = E(e); if (initial || !S.st) { body('<div class="wch-pad">' + banner('red', 'warn', 'Could not load the cart progress', E(e)) + '<div class="wch-emp"><button type="button" class="wch-b" data-a="reload">Try again</button></div></div>'); foot('<button type="button" class="wch-b soft blk" data-a="close">Close</button>'); $('wchB').onclick = $('wchF').onclick = (ev) => { const b = ev.target.closest('[data-a]'); if (!b) return; if (b.dataset.a === 'close') close(); else go('progress'); }; } else { paintProgress(); } }
    }
    function waitText(c) { const t = c.chain && c.chain.options_sent_at; return t ? ago(t) : ''; }
    function paintProgress() {
      const c = S.cart, st = S.st, steps = (st && st.steps) || []; const done = steps.filter((s) => s.state === 'done').length; const cur = steps.find((s) => s.state === 'current' || s.state === 'failed');
      const y = $('wchB').scrollTop; const fail = ['cancelled', 'expired', 'failed'].includes(c.status);
      let h = '<div class="wch-pad"><div id="wchBan">' + topBanners() + (S.progErr ? banner('', 'warn', '', 'Could not refresh just now: ' + S.progErr + ' Showing the last known state.') : '') + '</div>';
      if (c.chain && c.chain.needs_manual && c.chain.customer_reply) h += banner('', 'warn', first(nm()) + ' wrote something unclear', '“' + c.chain.customer_reply + '”. Pick the option yourself below.');
      h += '<div style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 6px;gap:8px"><span class="wch-chip ' + (STATUS_CHIP[c.status] || '') + '"><span class="dot"></span>' + esc('Step ' + Math.min(steps.length, done + 1) + ' of ' + steps.length + ': ' + (cur ? cur.label.toLowerCase() : c.status_label.toLowerCase())) + '</span><span style="font-size:12.5px;color:var(--wm);font-weight:700;white-space:nowrap">' + done + ' of ' + steps.length + ' done</span></div>';
      h += '<div class="wch-segs">' + steps.map((s) => '<i class="' + (s.state === 'done' ? 'd' : s.state === 'current' ? 'c' : s.state === 'failed' ? 'f' : '') + '"></i>').join('') + '</div>';
      h += '<ul class="wch-tl">' + steps.map((s, i) => {
        const cls = s.state === 'done' ? 'done' : s.state === 'current' ? 'cur' : s.state === 'failed' ? 'failed' : 'todo'; let ex = '';
        if (s.state === 'current' || s.state === 'failed') ex = curExtras(s, c);
        return '<li class="' + cls + '"><span class="dt">' + (s.state === 'done' ? ico('check', 's') : s.state === 'failed' ? ico('x', 's') : (i + 1)) + '</span><div class="hd"><b>' + esc(s.label) + '</b><time>' + (s.at ? esc(clock(s.at)) : (s.state === 'current' ? 'now' : '')) + '</time></div>' + (s.detail && !(s.state === 'current' && ex) ? '<div class="sub">' + esc(s.detail) + '</div>' : '') + ex + '</li>';
      }).join('') + '</ul>';
      if (st && st.next) h += '<div class="wch-card" style="margin-top:0"><b style="font-size:13px">What happens next</b><div class="sub" style="font-size:13px;color:var(--wm);margin-top:2px;overflow-wrap:anywhere">' + esc(st.next) + '</div></div>';
      if (c.plan) h += '<div class="wch-card"><b style="font-size:13px">What ' + esc(first(nm())) + ' pays for</b>' + (c.plan.chain_pay ? '<div class="kv"><span>Chain</span><b>' + fm(c.plan.chain_pay) + '</b></div>' : '<div class="kv"><span>Chain</span><b>None</b></div>') + '<div class="kv"><span>Perfumes</span><b>' + fm(c.plan.perfume_part) + '</b></div><div class="kv"><span>Total</span><b>' + fm(c.total) + '</b></div></div>';
      if (c.order && c.order.name) h += '<div class="wch-card"><div class="kv"><span>Order</span><b>' + esc(c.order.name) + (c.order.mode ? ' · ' + esc(c.order.mode.toUpperCase()) : '') + '</b></div>' + (c.invoice ? '<div class="kv"><span>Bill</span><b>' + esc(c.invoice.inv_no) + '</b></div><div style="display:flex;gap:8px;margin-top:8px"><a class="wch-b soft s" href="' + esc(c.invoice.view_url) + '" target="_blank" rel="noopener" style="flex:1">View bill</a><button type="button" class="wch-b soft s" data-a="resendbill" style="flex:1">Send bill again</button></div>' : '') + '</div>';
      if (c.can && c.can.cancel) h += (S.cancelOpen ? cancelPanel() : '<div style="margin:6px 0 10px"><button type="button" class="wch-b red s blk" data-a="cancel">' + ico('trash', 's') + ' Cancel cart</button></div>');
      h += '</div>';
      const oth = S.others || []; if (oth.length) h += '<div class="wch-hdl">My other open carts</div>' + oth.map((x) => '<button type="button" class="wch-mini" data-a="other" data-phone="' + esc(x.phone) + '" data-name="' + esc(x.customer) + '"><span class="av">' + esc(initials(x.customer)) + '</span><div class="m"><b>' + esc(x.customer || x.phone) + ' · ' + fm(x.total) + '</b><small>' + esc(x.status_label) + '</small></div><span class="wch-chip ' + (STATUS_CHIP[x.status] || '') + '">' + esc(shortStatus(x.status)) + '</span></button>').join('') + '<div style="height:12px"></div>';
      body(h); $('wchB').scrollTop = y;
      // foot: the main action of this stage
      let f = '', canLink = c.can && c.can.link, bb = blockedBy();
      if (c.status === 'chosen') f = (bb ? why(bb.code === 'billing_incomplete' ? 'Cart is blocked: ' + bb.text : (bb.code === 'no_combo_price' ? bb.text : 'Chain orders are switched off'), 'red', 'lock') : '') + '<button type="button" class="wch-b soft fx" data-a="close" style="flex:0 0 96px">Close</button><button type="button" class="wch-b" data-a="link" ' + (canLink && !S.busy ? '' : 'disabled') + '>' + ico('send') + ' ' + (S.busy === 'link' ? 'Sending…' : 'Send cart link') + '</button>';
      else if (c.status === 'link_sent') f = '<button type="button" class="wch-b soft fx" data-a="close" style="flex:0 0 96px">Close</button><button type="button" class="wch-b" data-a="link" ' + (c.can && c.can.resend_link && !S.busy ? '' : 'disabled') + '>' + ico('send') + ' ' + (S.busy === 'link' ? 'Sending…' : 'Send the link again') + '</button>';
      else f = '<button type="button" class="wch-b soft blk" data-a="close">Close</button>';
      foot(f);
      const hh = (e) => { const b = e.target.closest('[data-a]'); if (!b || b.disabled) return; progAct(b); }; $('wchB').onclick = hh; $('wchF').onclick = hh;
    }
    const shortStatus = (s) => ({ building: 'Building', options_sent: 'Waiting', chosen: 'Chosen', link_sent: 'Link sent', ordered: 'Paid', billed: 'Billed' }[s] || s);
    function curExtras(s, c) {
      let h = '';
      if (c.status === 'options_sent' && s.key === 'chosen') {
        const lock = c.chain.locked_manual || !(c.can && c.can.pick), sent = c.chain.sent_options || [];
        h += '<div class="wch-wait"><b>Waiting for ' + esc(first(nm())) + (waitText(c) ? ': ' + esc(waitText(c)) : '') + '</b><br><span style="color:var(--wm)">No clear answer yet. They reply 1, 2, 3 or NO.</span></div>';
        if (S.st && S.st.reminder_at) h += '<div class="wch-rem"><div class="r"><span>Reminder</span><b>' + (c.chain.reminder_sent_at ? 'sent ' + esc(clock(c.chain.reminder_sent_at)) : 'goes at ' + esc(clock(S.st.reminder_at))) + '</b></div><p>One reminder goes out by itself. If ' + esc(first(nm())) + ' is still silent after that, you are notified.</p></div>';
        h += '<div class="wch-lb" style="margin:12px 0 6px">Enter their choice yourself</div><div class="wch-pickrow" id="wchPk">' + sent.map((o) => '<button type="button" data-a="pk" data-n="' + o.n + '" class="' + (S.pickSel === o.n ? 'on' : '') + '" ' + (lock ? 'disabled' : '') + ' aria-label="Option ' + o.n + ': ' + esc((o.chains || []).map((x) => x.title).join(' + ')) + '">' + o.n + '</button>').join('') + '<button type="button" data-a="pk" data-n="none" style="flex:1.8" class="' + (S.pickSel === 'none' ? 'on' : '') + '" ' + (lock ? 'disabled' : '') + '>No chain</button></div>';
        if (S.pickSel) { const o = sent.find((x) => x.n === S.pickSel); h += '<div style="margin-top:8px"><button type="button" class="wch-b s blk" data-a="pkgo" ' + (S.busy ? 'disabled' : '') + '>' + (S.pickSel === 'none' ? 'Confirm: no chain' : 'Confirm option ' + S.pickSel + (o ? ': ' + esc(o.chains.map((x) => x.title).join(' + ')) : '')) + '</button></div>'; }
      } else if (c.status === 'chosen' && s.key === 'link') {
        const o = (c.chain.sent_options || []).find((x) => String(x.n) === String(c.chain.picked)); h += '<div class="wch-wait"><b style="color:var(--gfg)">' + (c.chain.picked === 'none' ? 'No chain' : 'Option ' + esc(c.chain.picked) + (o ? ': ' + esc(o.chains.map((x) => x.title).join(' + ')) : '')) + '</b><br><span style="color:var(--wm)">Chosen ' + (c.chain.picked_by === 'customer' ? 'by the customer' : 'by you') + '. Press Send cart link below.</span></div>';
      } else if (c.status === 'link_sent' && s.key === 'paid') {
        h += '<div class="wch-wait"><b>Waiting for ' + esc(first(nm())) + '</b><br><span style="color:var(--wm)">The link is open for them. This page updates by itself.</span></div>' + (c.draft && c.draft.name ? '<div class="sub" style="margin-top:4px">Draft order ' + esc(c.draft.name) + '</div>' : '');
      } else if (s.state === 'failed') h += '<div class="wch-wait"><b style="color:var(--rfg)">' + esc(c.status_label) + '</b></div>';
      return h;
    }
    function cancelPanel() { return '<div class="wch-card" style="border-color:var(--rfg)"><b>Cancel this cart?</b><div class="sub" style="font-size:13px;color:var(--wm);margin:2px 0 8px">Any open draft order is deleted. A paid order is never touched.</div><input id="wchCr" placeholder="Reason (optional)" style="width:100%;height:44px;border:1.5px solid var(--wl);border-radius:12px;padding:0 12px;font:inherit;font-size:16px;background:var(--wc);color:var(--wi)"><label style="display:flex;gap:8px;align-items:center;margin:8px 0;min-height:40px;font-size:13.5px"><input type="checkbox" id="wchCn" style="width:20px;height:20px"> Tell the customer it was cancelled</label><div style="display:flex;gap:8px"><button type="button" class="wch-b soft s" data-a="cancelno" style="flex:1">Keep it</button><button type="button" class="wch-b red s" data-a="cancelyes" style="flex:1">Yes, cancel</button></div></div>'; }
    async function progAct(b) {
      const a = b.dataset.a, c = S.cart;
      if (a === 'close') return close();
      if (a === 'reload') return go('progress');
      if (a === 'other') { S.phone = b.dataset.phone; S.name = b.dataset.name || ''; S.cart = null; S.lines = []; S.total = ''; S.ml = ''; S.others = []; S.st = null; S.cancelOpen = false; S.pickSel = 0; S.sel = new Set(); S.sug = null; return load(); }
      if (a === 'pk') { S.pickSel = b.dataset.n === 'none' ? 'none' : +b.dataset.n; return paintProgress(); }
      if (a === 'pkgo') { S.busy = 'pick'; paintProgress(); try { const r = await A.post('wch.chain_pick', { id: c.id, pick: S.pickSel }); S.cart = r.cart; S.busy = ''; S.pickSel = 0; toast('Choice saved. Now send the cart link.'); notify(); await refreshProgress(false); } catch (e) { S.busy = ''; toast(E(e), true); paintProgress(); const ban = $('wchBan'); if (ban) ban.insertAdjacentHTML('afterbegin', banner('red', 'warn', '', E(e))); } return; }
      if (a === 'cancel') { S.cancelOpen = true; return paintProgress(); }
      if (a === 'cancelno') { S.cancelOpen = false; return paintProgress(); }
      if (a === 'cancelyes') { b.disabled = true; try { const r = await A.post('wch.cart_cancel', { id: c.id, reason: ($('wchCr') || {}).value || '', notify: !!($('wchCn') || {}).checked }); S.cart = r.cart; S.cancelOpen = false; toast('Cart cancelled'); notify(); close(); } catch (e) { b.disabled = false; toast(E(e), true); } return; }
      if (a === 'link') {
        S.busy = 'link'; paintProgress();
        try { const r = await A.post('wch.cart_link', { id: c.id }); S.cart = r.cart; S.busy = ''; toast(r.resent ? 'Link sent again' : 'Cart link sent to ' + first(nm())); notify(); await refreshProgress(false); }
        catch (e) { S.busy = ''; const cd = code(e); let t = E(e), title = 'The cart link was not sent';
          if (cd === 'scope_missing') title = 'Shopify needs one more permission'; else if (cd === 'billing_incomplete') title = 'Billing is not complete'; else if (cd === 'window_closed') title = 'The 24-hour reply window is closed'; else if (cd === 'off') title = 'Chain orders are switched off'; else if (cd === 'no_choice') title = 'Wait for the choice first';
          await refreshProgress(false).catch(() => { }); const ban = $('wchBan'); if (ban && S.view === 'progress') ban.insertAdjacentHTML('afterbegin', banner('red', 'warn', title, t)); }
        return;
      }
      if (a === 'resendbill' && c.invoice) { b.disabled = true; try { await A.post('wch.bill_resend', { id: c.invoice.id }); toast('Bill sent again'); } catch (e) { toast(E(e), true); } b.disabled = false; }
    }

    // ---- go
    load();
    live = { close, el: ov };
    return live;
  }

  // ------------------------------------------------------------------ compact progress chip (chat details)
  async function status(el, o) {
    injectCss(); o = o || {}; const A = apiOf(o); if (!el) return null; const phone = String(o.phone || '').replace(/\D/g, '').slice(-10);
    el.classList.add('wch'); el.hidden = true; el.innerHTML = '';
    if (!A || !phone) return null;
    try {
      const g = await A.get('wch.cart_get', { phone }); if (!el.isConnected) return null; const c = g.cart;
      if (!c) { el.hidden = true; el.innerHTML = ''; return null; }
      el.hidden = false; const off = g.on === false && OPEN.indexOf(c.status) >= 0;
      el.innerHTML = '<button type="button" class="wch-chip-btn" aria-label="Open the cart">' + ico('cart') + '<span class="m"><b>Cart ' + fm(c.total) + ' · ' + esc(c.status_label) + '</b><small>' + c.ml_used + ' of ' + c.ml + ' ml' + (off ? ' · chain orders are switched off' : '') + '</small></span><span class="wch-chip ' + (STATUS_CHIP[c.status] || '') + '">' + esc(shortStatus2(c.status)) + '</span></button>';
      el.querySelector('button').onclick = () => open({ phone, name: o.name || c.customer, api: A, onChange: () => status(el, o) });
      return c;
    } catch (e) { el.hidden = true; el.innerHTML = ''; return null; }
  }
  const shortStatus2 = (s) => ({ building: 'Building', options_sent: 'Waiting', chosen: 'Chosen', link_sent: 'Link sent', ordered: 'Paid', billed: 'Billed' }[s] || s);

  // ------------------------------------------------------------------ list of open carts
  async function list(el, o) {
    injectCss(); o = o || {}; const A = apiOf(o); if (!el) return; el.classList.add('wch'); el.innerHTML = '<div class="wch-sk"></div><div class="wch-sk"></div>';
    try {
      const r = await A.get('wch.cart_list', { days: 7 }); if (!el.isConnected) return; const L = (r.carts || []).filter((x) => ['cancelled', 'expired', 'failed'].indexOf(x.status) < 0);
      if (!L.length) { el.innerHTML = '<div class="wch-emp"><b>No open carts</b>Carts you start in a chat show here.</div>'; return; }
      el.innerHTML = L.map((x) => '<button type="button" class="wch-mini" data-phone="' + esc(x.phone) + '" data-name="' + esc(x.customer) + '"><span class="av">' + esc(initials(x.customer)) + '</span><div class="m"><b>' + esc(x.customer || x.phone) + ' · ' + fm(x.total) + '</b><small>' + esc(x.status_label) + '</small></div><span class="wch-chip ' + (STATUS_CHIP[x.status] || '') + '">' + esc(shortStatus2(x.status)) + '</span></button>').join('');
      el.onclick = (e) => { const b = e.target.closest('.wch-mini'); if (!b) return; if (typeof o.onOpen === 'function') o.onOpen(b.dataset.phone); else open({ phone: b.dataset.phone, name: b.dataset.name, api: A, onChange: () => list(el, o) }); };
    } catch (e) { el.innerHTML = '<div class="wch-emp"><b>Could not load your carts</b>' + esc(E(e)) + '<br><br><button type="button" class="wch-b s" data-r="1">Try again</button></div>'; const b = el.querySelector('[data-r]'); if (b) b.onclick = () => list(el, o); }
  }

  window.WaCart = { v: 'ch1', open, status, list, css: injectCss };
})();
