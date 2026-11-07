/**
 * wa_smart_ui.js — the SMART campaign of the WhatsApp console (window.WaSmart) and the Normal-campaign upgrade pieces.
 *   Smart campaign: the owner types ONE line → Claude asks (suggests) → plan → edit / test / approve → live status → report.
 *   WaSmart.open(el, { id, onBack })            open the Smart campaign inside `el` (new = ask screen, id = that plan)
 *   WaSmart.chooser({ onNormal, onSmart })      the "Normal | Smart" sheet that opens from "New campaign"
 *   WaSmart.plansCard(el, { onOpen })           the "Past plans" card of the Campaigns screen
 *   WaSmart.normalSuggest(el, ctx)              3 marketing + 3 utility suggestions for the Normal 'What' step
 *   WaSmart.speedBox(el, ctx)                   speed estimate + retries for the Normal 'When' step
 * Server side: wa_smart.php (wa.smart_*, wa.camp_suggest, wa.camp_suggest_save, wa.camp_speed). Customer audience: WaSeg.audience when
 * wa_seg_ui.js is loaded, otherwise the chips below. Styles carry the prefix wsm- (the same text is at the end of assets/css/wa_ui.css).
 * Phone first (390 px), two columns from 1100 px. English UI. Light and dark (html.wxdk).
 */
(function () {
  'use strict';
  if (window.WaSmart) return;
  if (!window.WaPanels || !WaPanels.util || typeof api === 'undefined') return;
  const { esc, fmt, money, toast, modal } = WaPanels.util;
  if (!document.getElementById('wsmCss')) { const s = document.createElement('style'); s.id = 'wsmCss'; s.textContent = `.wsm-root{--g:#16a34a; --g2:#22c55e; --gd:#0f7a3a; --ink:#0f172a; --mut:#64748b; --line:#e8ecf2; --bg:#f4f6fa; --card:#fff; --soft:#f8fafc; --line2:#f0f3f7;
  --chip:#eef1f6; --chipfg:#475569; --on:#0f172a; --onfg:#fff; --faint:#94a3b8;
  --okbg:#e7f9ee; --okfg:#0f7a3a; --okline:#c6efd5; --wbg:#fff6d6; --wfg:#8a6a00; --wline:#fde8a8; --badbg:#fde8e8; --badfg:#c2262b; --badline:#fbcaca;
  --bbg:#e8f0ff; --bfg:#2563eb; --pbg:#f0ebff; --pfg:#5b3fd6; --obg:#fff0e0; --ofg:#b45309;
  --cl1:#f4f0ff; --cl2:#eef6ff; --clline:#ddd6fe; --clfg:#2f2a55; --clb:#3b2a8a;
  --chat:#efe7dd; --bub:#fff; --bubout:#d9fdd3; --bubfg:#111b21; --bubmut:#8696a0; --btnfg:#027eb5; --var:#fff3c4;
  --rail:rgba(255,255,255,.78); --hl:#fff3c4; --track:#eef1f6; --shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px rgba(15,23,42,.04);
  --font:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;
  margin:0; font-family:var(--font); color:var(--ink); font-size:14px; line-height:1.45;
  background:radial-gradient(900px 500px at 85% -10%,#e3f8ea 0,transparent 60%),radial-gradient(700px 500px at -5% 105%,#ffeadb 0,transparent 55%),var(--bg);
  display:block;position:relative;}
html.wxdk .wsm-root{--ink:#e9edef; --mut:#8696a0; --line:#25343e; --bg:#0b141a; --card:#111b21; --soft:#162129; --line2:#1c2a33;
  --chip:#1f2c34; --chipfg:#c5ced4; --on:#00a884; --onfg:#04130e; --faint:#6b7c88;
  --okbg:#0f3a2a; --okfg:#6ee7b0; --okline:#1d5a41; --wbg:#3b3110; --wfg:#f3d36b; --wline:#5a4a18; --badbg:#47191c; --badfg:#ff9a9d; --badline:#6b2a2e;
  --bbg:#15294a; --bfg:#8db6ff; --pbg:#2a2150; --pfg:#c0adff; --obg:#412a10; --ofg:#fbbf6a;
  --cl1:#221d3f; --cl2:#16233a; --clline:#3b3566; --clfg:#d9d3ff; --clb:#cfc5ff;
  --chat:#0b141a; --bub:#202c33; --bubout:#005c4b; --bubfg:#e9edef; --bubmut:#8696a0; --btnfg:#53bdeb; --var:#6b5a14;
  --rail:rgba(17,27,33,.85); --hl:#6b5a14; --track:#25343e; --shadow:0 1px 2px rgba(0,0,0,.3);
  background:var(--bg);}
.wsm-root .wsm-hb{display:inline-flex;align-items:center;gap:3px;border:1px solid var(--line);background:var(--card);border-radius:999px;padding:0 13px 0 9px;height:40px;font-weight:700;font-size:13px;color:var(--ink);text-decoration:none;white-space:nowrap}
.wsm-root .wsm-live{display:inline-flex;align-items:center;gap:7px;background:var(--okbg);color:var(--okfg);padding:0 12px;height:30px;border-radius:99px;font-weight:700;font-size:12px;white-space:nowrap}
.wsm-root .wsm-live.wsm-draft{background:var(--wbg);color:var(--wfg)}
.wsm-root .wsm-pulse{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.22)}
.wsm-root .wsm-pg{flex:1;padding:4px 14px 22px;min-width:0}
.wsm-root .wsm-dock{position:sticky;bottom:0;z-index:40;margin-top:auto}
.wsm-root .wsm-bar{display:flex;gap:10px;align-items:center;background:var(--card);border-top:1px solid var(--line);padding:10px 14px;box-shadow:0 -8px 24px rgba(15,23,42,.08)}
.wsm-root .wsm-bar .wsm-sum{flex:1;min-width:0;font-size:12px;color:var(--mut);line-height:1.3}
.wsm-root .wsm-bar .wsm-sum b{display:block;color:var(--ink);font-size:14px}
/* ---------- buttons, chips, pills ---------- */.wsm-root .wsm-btn{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:12px;padding:0 16px;min-height:44px;font-weight:700;font-size:13.5px;display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap}
.wsm-root .wsm-btn.wsm-p{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border-color:#16a34a;box-shadow:0 4px 12px rgba(22,163,74,.28)}
.wsm-root .wsm-btn.wsm-d{background:var(--ink);color:var(--card);border-color:var(--ink)}
.wsm-root .wsm-btn.wsm-big{min-height:54px;font-size:15.5px;border-radius:14px;width:100%}
.wsm-root .wsm-btn.wsm-s{min-height:40px;padding:0 13px;font-size:12.5px;border-radius:11px}
.wsm-root .wsm-btn.wsm-o{background:linear-gradient(135deg,#fb923c,#f97316);color:#fff;border-color:#f97316}
.wsm-root .wsm-btn.wsm-pu{background:linear-gradient(135deg,#8b6cff,#6d4ee0);color:#fff;border-color:#6d4ee0;box-shadow:0 4px 12px rgba(109,78,224,.28)}
.wsm-root .wsm-btn.wsm-dis{opacity:.45}
.wsm-root .wsm-btn.wsm-r{color:var(--badfg);border-color:var(--badline)}
.wsm-root .wsm-ch{border:1px solid var(--line);background:var(--card);color:var(--chipfg);border-radius:99px;padding:0 14px;height:40px;line-height:1;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.wsm-root .wsm-ch small{font-weight:600;color:var(--faint);font-size:11.5px}
.wsm-root .wsm-ch.wsm-on{background:var(--on);border-color:var(--on);color:var(--onfg)}
.wsm-root .wsm-ch.wsm-on small{color:inherit;opacity:.75}
.wsm-root .wsm-ch.wsm-sug.wsm-on{background:var(--on);box-shadow:0 0 0 1.5px var(--card),0 0 0 3px #a78bfa}
.wsm-root .wsm-ch.wsm-off{opacity:.55;text-decoration:line-through}
.wsm-root .wsm-ch.wsm-lock{background:var(--chip);border-style:dashed}
.wsm-root .wsm-chips{display:flex;flex-wrap:wrap;gap:8px}
.wsm-root .wsm-pill{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;background:var(--chip);color:var(--chipfg);white-space:nowrap;line-height:1.4}
.wsm-root .wsm-pill.wsm-ok{background:var(--okbg);color:var(--okfg)}
.wsm-root .wsm-pill.wsm-warn{background:var(--wbg);color:var(--wfg)}
.wsm-root .wsm-pill.wsm-bad{background:var(--badbg);color:var(--badfg)}
.wsm-root .wsm-pill.wsm-b{background:var(--bbg);color:var(--bfg)}
.wsm-root .wsm-pill.wsm-p{background:var(--pbg);color:var(--pfg)}
.wsm-root .wsm-pill.wsm-o{background:var(--obg);color:var(--ofg)}
.wsm-root .wsm-cd{margin-left:auto;border:1px solid var(--clline);background:linear-gradient(90deg,var(--cl1),var(--cl2));color:var(--pfg);border-radius:99px;padding:0 13px;min-height:40px;font-weight:800;font-size:12.5px;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.wsm-root .wsm-cd.wsm-done{background:var(--pbg);border-color:transparent}
.wsm-root .wsm-dot{width:8px;height:8px;border-radius:50%;display:inline-block;background:#94a3b8;flex:none}
.wsm-root .wsm-dot.wsm-ok{background:#16a34a}
.wsm-root .wsm-dot.wsm-w{background:#f0a500}
.wsm-root .wsm-dot.wsm-bad{background:#d92d20}
.wsm-root .wsm-dot.wsm-p{background:#7c5cfc}
.wsm-root .wsm-dot.wsm-b{background:#3b82f6}
/* ---------- cards ---------- */.wsm-root .wsm-card{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);padding:14px;margin-bottom:12px}
.wsm-root .wsm-card h3{margin:0 0 10px;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.wsm-root .wsm-card h3 .wsm-sp,.wsm-root .wsm-sp{flex:1}
.wsm-root .wsm-cap{font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;color:var(--faint);margin:14px 2px 6px}
.wsm-root .wsm-cap small{text-transform:none;letter-spacing:0;font-weight:500}
.wsm-root .wsm-muted{color:var(--mut);font-size:12px}
.wsm-root .wsm-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.wsm-root .wsm-kv{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-top:1px solid var(--line2);font-size:13px}
.wsm-root .wsm-kv:first-child{border-top:0}
.wsm-root .wsm-kv span{color:var(--mut)}
.wsm-root .wsm-kv b{text-align:right}
/* Claude blocks */.wsm-root .wsm-cl{background:linear-gradient(135deg,var(--cl1),var(--cl2));border:1px solid var(--clline);border-radius:16px;padding:12px 14px;color:var(--clfg);font-size:13.5px;line-height:1.5}
.wsm-root .wsm-cl .wsm-who{display:flex;align-items:center;gap:8px;font-weight:800;color:var(--clb);margin-bottom:5px;font-size:13px}
.wsm-root .wsm-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#8b6cff,#5b3fd6);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;flex:none}
.wsm-root .wsm-sg{font-size:12px;color:var(--pfg);margin:8px 0 0;line-height:1.4;display:flex;gap:6px}
.wsm-root .wsm-sg b{font-weight:800}
.wsm-root .wsm-ck{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:800;letter-spacing:.02em;background:var(--pbg);color:var(--pfg);border-radius:99px;padding:2px 8px;white-space:nowrap}
/* ---------- screen 1 ---------- */.wsm-root .wsm-ask{padding:14px 14px 12px}
.wsm-root .wsm-ask h2{margin:0 0 10px;font-size:19px;font-weight:800;letter-spacing:-.3px}
.wsm-root .wsm-askbox{border:2px solid #86efac;background:var(--soft);border-radius:16px;padding:12px 14px 10px;font-size:17px;line-height:1.4;font-weight:600;min-height:92px;position:relative;box-shadow:0 0 0 4px rgba(34,197,94,.12)}
.wsm-root .wsm-askbox .wsm-caret{display:inline-block;width:2px;height:20px;background:var(--g);vertical-align:-4px;margin-left:2px}
.wsm-root .wsm-askrow{display:flex;gap:8px;align-items:center;margin-top:10px}
.wsm-root .wsm-mic{width:48px;height:48px;border-radius:14px;border:1px solid var(--line);background:var(--card);display:grid;place-items:center;flex:none}
.wsm-root .wsm-eg{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px;align-items:center}
.wsm-root .wsm-eg .wsm-muted{margin-right:2px}
.wsm-root .wsm-eg .wsm-ch{min-height:40px;font-size:12.5px;font-weight:600}
.wsm-root .wsm-prog{display:flex;align-items:center;gap:8px;margin:2px 2px 10px;font-size:12.5px;color:var(--mut);flex-wrap:wrap}
.wsm-root .wsm-prog b{color:var(--ink)}
.wsm-root details.wsm-grp{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);margin-bottom:12px;overflow:hidden}
.wsm-root details.wsm-grp>summary{list-style:none;display:flex;align-items:center;gap:11px;padding:12px 14px;min-height:60px;cursor:pointer}
.wsm-root details.wsm-grp>summary::-webkit-details-marker{display:none}
.wsm-root .wsm-gn{width:28px;height:28px;border-radius:50%;background:var(--chip);display:grid;place-items:center;font-weight:800;font-size:12.5px;flex:none;color:var(--chipfg)}
.wsm-root details[open] .wsm-gn{background:var(--g);color:#fff}
.wsm-root .wsm-gt{flex:1;min-width:0}
.wsm-root .wsm-gt b{display:block;font-size:15px;font-weight:800;letter-spacing:-.1px}
.wsm-root .wsm-gt span{display:block;color:var(--mut);font-size:12.5px;line-height:1.35;margin-top:1px}
.wsm-root details:not([open]) .wsm-gt span{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.wsm-root .wsm-chev{width:20px;height:20px;stroke:var(--faint);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
.wsm-root details[open] .wsm-chev{transform:rotate(180deg)}
.wsm-root .wsm-gb{padding:2px 14px 14px;border-top:1px solid var(--line2)}
.wsm-root .wsm-q{padding:12px 0;border-top:1px solid var(--line2)}
.wsm-root .wsm-q:first-child{border-top:0;padding-top:10px}
.wsm-root .wsm-qh{display:flex;align-items:center;gap:8px;min-height:40px}
.wsm-root .wsm-qh b{font-size:14.5px;font-weight:800}
.wsm-root .wsm-ql{font-size:11px;letter-spacing:.05em;text-transform:uppercase;font-weight:800;color:var(--faint);margin:6px 0 7px}
.wsm-root .wsm-ql:first-of-type{margin-top:2px}
.wsm-root .wsm-count{display:flex;align-items:center;gap:12px;background:linear-gradient(90deg,#e7f9ee,#f2fcf6);border:1px solid var(--okline);border-radius:14px;padding:12px 14px;margin:14px 0 8px}
html.wxdk .wsm-root .wsm-count{background:var(--okbg)}
.wsm-root .wsm-count .wsm-n{font-size:28px;font-weight:800;letter-spacing:-.8px;color:var(--okfg);line-height:1}
.wsm-root .wsm-count .wsm-t{font-size:13px;color:var(--okfg);font-weight:600;line-height:1.3}
.wsm-root .wsm-adds{font-size:12.5px;line-height:1.55;color:var(--mut);background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:10px 12px}
.wsm-root .wsm-adds b{color:var(--ink)}
.wsm-root .wsm-stack{display:flex;height:10px;border-radius:6px;overflow:hidden;margin:9px 0 6px;background:var(--track)}
.wsm-root .wsm-stack i{display:block;height:100%}
.wsm-root .wsm-lg{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:11.5px;color:var(--mut)}
.wsm-root .wsm-lg span{display:inline-flex;align-items:center;gap:5px}
.wsm-root .wsm-sw{display:flex;gap:12px;align-items:center;background:var(--soft);border:1px solid var(--line);border-radius:14px;padding:10px 12px;margin-top:8px}
.wsm-root .wsm-sw .wsm-t{flex:1;min-width:0;font-size:13.5px;font-weight:700;line-height:1.3}
.wsm-root .wsm-sw .wsm-t small{display:block;font-weight:500;color:var(--mut);font-size:12px;margin-top:2px}
.wsm-root .wsm-tg{width:64px;height:40px;border-radius:99px;background:#cbd5e1;position:relative;flex:none;border:0;padding:0}
.wsm-root .wsm-tg:after{content:'';position:absolute;left:4px;top:4px;width:32px;height:32px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.wsm-root .wsm-tg.wsm-on{background:#16a34a}
.wsm-root .wsm-tg.wsm-on:after{left:28px}
html.wxdk .wsm-root .wsm-tg{background:#3b4a54}
.wsm-root .wsm-sub{margin:8px 0 0;font-size:12px;color:var(--mut);line-height:1.4}
.wsm-root .wsm-inp{border:1px solid var(--line);background:var(--card);border-radius:12px;min-height:44px;padding:0 12px;display:flex;align-items:center;font-weight:600;font-size:14px;color:var(--ink)}
/* ---------- WhatsApp bubble ---------- */.wsm-root .wsm-chatbg{background:var(--chat);border-radius:16px;padding:12px}
.wsm-root .wsm-bub{max-width:340px;background:var(--bub);color:var(--bubfg);border-radius:4px 12px 12px 12px;padding:7px 9px 4px;box-shadow:0 1px 1px rgba(0,0,0,.12);font-size:13.5px;line-height:1.42;position:relative}
.wsm-root .wsm-bub .wsm-tx{white-space:pre-wrap;word-wrap:break-word;padding:0 2px}
.wsm-root .wsm-bub .wsm-v{background:var(--var);border-radius:3px;padding:0 2px}
.wsm-root .wsm-bub .wsm-tm{font-size:11px;color:var(--bubmut);text-align:right;padding:2px 2px 1px}
.wsm-root .wsm-bub .wsm-btn2{border-top:1px solid rgba(128,128,128,.25);text-align:center;color:var(--btnfg);font-weight:700;font-size:13px;padding:9px 4px;margin:4px -9px 0}
.wsm-root .wsm-bub .wsm-btn2:last-child{margin-bottom:-4px}
.wsm-root .wsm-bub .wsm-im{height:96px;border-radius:8px;margin-bottom:6px;background:linear-gradient(135deg,#f6d365,#fda085);display:flex;align-items:center;justify-content:center;font-size:38px}
.wsm-root .wsm-bub .wsm-ft{font-size:11px;color:var(--bubmut);padding:0 2px}
.wsm-root .wsm-bub.wsm-out{background:var(--bubout);border-radius:12px 4px 12px 12px;margin-left:auto}
.wsm-root .wsm-bub.wsm-in{border-radius:4px 12px 12px 12px}
/* ---------- screen 2 ---------- */.wsm-root .wsm-und .wsm-ln{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid var(--clline);font-size:13px;line-height:1.4}
.wsm-root .wsm-und .wsm-ln:first-of-type{border-top:0}
.wsm-root .wsm-und .wsm-ln>span{width:74px;flex:none;color:var(--pfg);font-weight:800;font-size:11px;letter-spacing:.04em;text-transform:uppercase;padding-top:2px}
.wsm-root .wsm-und .wsm-ln div{flex:1;min-width:0}
.wsm-root .wsm-gc{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);margin-bottom:10px;overflow:hidden}
.wsm-root .wsm-gc.wsm-open{border-color:#86efac;box-shadow:0 0 0 3px rgba(34,197,94,.12),var(--shadow)}
.wsm-root .wsm-gch{display:flex;align-items:center;gap:11px;padding:12px 14px;min-height:64px}
.wsm-root .wsm-gch .wsm-sd{width:12px;height:12px;border-radius:50%;flex:none}
.wsm-root .wsm-gch .wsm-t{flex:1;min-width:0}
.wsm-root .wsm-gch .wsm-t b{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:15px;font-weight:800}
.wsm-root .wsm-gch .wsm-t b em{font-style:normal;color:var(--mut);font-weight:700;font-size:13px}
.wsm-root .wsm-gch .wsm-t span{display:block;color:var(--mut);font-size:12.5px;margin-top:2px;line-height:1.35}
.wsm-root .wsm-gcb{padding:0 14px 14px}
.wsm-root .wsm-sec{font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;color:var(--faint);margin:14px 0 7px;display:flex;gap:8px;align-items:center}
.wsm-root .wsm-sec .wsm-sp{flex:1}
.wsm-root .wsm-abc{display:flex;gap:6px;margin-bottom:8px}
.wsm-root .wsm-abc button{flex:1;min-height:44px;border:1px solid var(--line);background:var(--chip);color:var(--chipfg);border-radius:12px;font-weight:800;font-size:14px}
.wsm-root .wsm-abc button.wsm-on{background:var(--on);color:var(--onfg);border-color:var(--on)}
.wsm-root .wsm-alt{display:flex;gap:10px;align-items:flex-start;padding:9px 0;font-size:12.5px;line-height:1.4;color:var(--mut);border-top:1px solid var(--line2)}
.wsm-root .wsm-alt b{flex:none;width:26px;height:26px;border-radius:8px;background:var(--chip);color:var(--chipfg);display:grid;place-items:center;font-size:12.5px}
.wsm-root .wsm-alt div{flex:1;min-width:0}
.wsm-root .wsm-ver{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.wsm-root .wsm-vb{border:1px solid var(--line);border-radius:14px;padding:10px 12px;background:var(--soft)}
.wsm-root .wsm-vb b{display:block;font-size:13px;margin-bottom:5px}
.wsm-root .wsm-vb .wsm-pill{margin:0}
.wsm-root .wsm-vb small{display:block;color:var(--mut);font-size:11.5px;margin-top:6px;line-height:1.35}
.wsm-root .wsm-info{border-radius:12px;padding:9px 12px;font-size:12.5px;line-height:1.45;background:var(--bbg);color:var(--bfg);margin-top:8px}
.wsm-root .wsm-info.wsm-ok{background:var(--okbg);color:var(--okfg)}
.wsm-root .wsm-info.wsm-w{background:var(--wbg);color:var(--wfg)}
.wsm-root .wsm-info.wsm-p{background:var(--pbg);color:var(--pfg)}
.wsm-root .wsm-info.wsm-bad{background:var(--badbg);color:var(--badfg)}
.wsm-root .wsm-mh{display:flex;align-items:center;gap:10px;border:1px dashed var(--line);background:var(--soft);border-radius:14px;padding:10px 12px;font-size:13px}
.wsm-root .wsm-mh .wsm-th{width:44px;height:44px;border-radius:10px;background:var(--chip);display:grid;place-items:center;font-size:20px;flex:none}
.wsm-root .wsm-nums{display:flex;flex-wrap:wrap;gap:8px}
.wsm-root .wsm-nm{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:var(--card);border-radius:12px;padding:0 12px;min-height:40px;font-size:12.5px;font-weight:700}
.wsm-root .wsm-nm small{color:var(--mut);font-weight:600}
.wsm-root .wsm-fu{border:1px solid var(--line);border-radius:14px;padding:10px 12px;background:var(--soft)}
.wsm-root .wsm-fu .wsm-h{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;margin-bottom:6px}
.wsm-root .wsm-acts{display:grid;grid-template-columns:1fr;gap:8px;margin-top:14px}
/* timeline strip */.wsm-root .wsm-tl{display:grid;grid-template-columns:repeat(10,minmax(0,1fr));gap:3px;margin-top:4px}
.wsm-root .wsm-tl .wsm-d{text-align:center;font-size:11px;color:var(--mut);font-weight:700;line-height:1.2;padding-bottom:3px}
.wsm-root .wsm-tl .wsm-d b{display:block;font-size:13px;color:var(--ink)}
.wsm-root .wsm-tl .wsm-d.wsm-we b{color:var(--faint)}
.wsm-root .wsm-tl .wsm-bx{height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;white-space:nowrap;overflow:hidden}
.wsm-root .wsm-tl .wsm-end{grid-column:1 / -1;text-align:center;font-size:12px;color:var(--mut);padding-top:6px;font-weight:600}
.wsm-root .wsm-w1{background:linear-gradient(90deg,#22c55e,#16a34a)}
.wsm-root .wsm-w2{background:linear-gradient(90deg,#3b82f6,#2563eb)}
.wsm-root .wsm-f1{background:#f59e0b}
.wsm-root .wsm-f2{background:#f97316}
.wsm-root .wsm-lg2{display:grid;gap:7px;margin-top:10px;font-size:12.5px;line-height:1.35}
.wsm-root .wsm-lg2 div{display:flex;gap:9px;align-items:flex-start}
.wsm-root .wsm-lg2 i{width:14px;height:14px;border-radius:5px;margin-top:2px;flex:none}
.wsm-root table.wsm-tb2{width:100%;border-collapse:collapse;font-size:12.5px}
.wsm-root table.wsm-tb2 th{font-size:11px;font-weight:800;color:var(--faint);text-transform:uppercase;letter-spacing:.05em;text-align:right;padding:4px 4px 6px;border-bottom:1px solid var(--line)}
.wsm-root table.wsm-tb2 td{padding:9px 4px;border-bottom:1px solid var(--line2);text-align:right;font-variant-numeric:tabular-nums}
.wsm-root table.wsm-tb2 th:first-child,.wsm-root table.wsm-tb2 td:first-child{text-align:left}
.wsm-root table.wsm-tb2 tr:last-child td{border-bottom:0}
.wsm-root table.wsm-tb2 td:first-child b{display:block;font-size:13px}
.wsm-root table.wsm-tb2 td:first-child small{color:var(--mut);font-size:11px}
.wsm-root table.wsm-tb2 tfoot td{font-weight:800;border-top:2px solid var(--line);border-bottom:0}
.wsm-root .wsm-hl{display:inline-flex;align-items:center;gap:5px;font-weight:700}
.wsm-root .wsm-safe{display:grid;gap:0}
.wsm-root .wsm-safe div{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-top:1px solid var(--line2);font-size:13px;line-height:1.4}
.wsm-root .wsm-safe div:first-child{border-top:0}
.wsm-root .wsm-safe svg{width:18px;height:18px;margin-top:1px;stroke:var(--g)}
.wsm-root .wsm-basedon{display:flex;gap:10px;align-items:flex-start;color:var(--mut);font-size:12.5px;line-height:1.45;padding:2px 4px 12px}
.wsm-root .wsm-basedon svg{margin-top:1px}
/* ---------- screen 3 ---------- */.wsm-root .wsm-mrow{padding:11px 0;border-top:1px solid var(--line2)}
.wsm-root .wsm-mrow:first-of-type{border-top:0}
.wsm-root .wsm-mrow .wsm-h{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.wsm-root .wsm-mrow .wsm-h b{font-size:13.5px;font-weight:800}
.wsm-root .wsm-mrow .wsm-h .wsm-sp{flex:1}
.wsm-root .wsm-mrow .wsm-pn{display:flex;gap:5px;flex-wrap:wrap;margin-top:7px}
.wsm-root .wsm-cx{display:inline-flex;align-items:center;gap:5px;border-radius:99px;padding:3px 10px 3px 8px;font-size:11.5px;font-weight:700;background:var(--chip);color:var(--chipfg);white-space:nowrap}
.wsm-root .wsm-cx i{width:7px;height:7px;border-radius:50%;background:#94a3b8}
.wsm-root .wsm-cx.wsm-ok{background:var(--okbg);color:var(--okfg)}
.wsm-root .wsm-cx.wsm-ok i{background:#16a34a}
.wsm-root .wsm-cx.wsm-w{background:var(--wbg);color:var(--wfg)}
.wsm-root .wsm-cx.wsm-w i{background:#f0a500}
.wsm-root .wsm-cx.wsm-x{background:var(--badbg);color:var(--badfg)}
.wsm-root .wsm-cx.wsm-x i{background:#d92d20}
.wsm-root .wsm-cx.wsm-pu{background:var(--pbg);color:var(--pfg)}
.wsm-root .wsm-cx.wsm-pu i{background:#7c5cfc}
.wsm-root .wsm-rep{margin-top:7px;border:1px solid var(--okline);background:var(--okbg);color:var(--okfg);border-radius:11px;padding:8px 11px;font-size:12.5px;line-height:1.4}
.wsm-root .wsm-rep.wsm-bad{border-color:var(--badline);background:var(--badbg);color:var(--badfg)}
.wsm-root .wsm-bigl{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}
.wsm-root .wsm-bigl .wsm-n{font-size:30px;font-weight:800;letter-spacing:-1px;line-height:1}
.wsm-root .wsm-bigl span{color:var(--mut);font-size:13px;padding-bottom:3px}
.wsm-root .wsm-sbar{display:flex;height:16px;border-radius:9px;overflow:hidden;background:var(--track);margin:11px 0 8px}
.wsm-root .wsm-sbar i{display:block;height:100%}
.wsm-root .wsm-tiles{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
.wsm-root .wsm-tile{border:1px solid var(--line);border-radius:13px;padding:9px 11px;background:var(--soft);min-width:0}
.wsm-root .wsm-tile small{display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:var(--mut);line-height:1.25}
.wsm-root .wsm-tile b{display:block;font-size:20px;font-weight:800;letter-spacing:-.4px;margin-top:2px}
.wsm-root .wsm-tile em{display:block;font-style:normal;font-size:11px;color:var(--faint);line-height:1.3;margin-top:1px}
.wsm-root .wsm-money{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}
.wsm-root .wsm-money div{background:var(--okbg);border:1px solid var(--okline);border-radius:13px;padding:9px 11px}
.wsm-root .wsm-money small{display:block;font-size:11px;font-weight:700;color:var(--okfg)}
.wsm-root .wsm-money b{font-size:18px;font-weight:800;color:var(--okfg);letter-spacing:-.4px}
.wsm-root .wsm-fr{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-top:1px solid var(--line2);font-size:13px;line-height:1.35}
.wsm-root .wsm-fr:first-of-type{border-top:0}
.wsm-root .wsm-fr .wsm-n{font-weight:800;min-width:40px;font-variant-numeric:tabular-nums}
.wsm-root .wsm-fr .wsm-t{flex:1;min-width:0}
.wsm-root .wsm-fr .wsm-t small{display:block;color:var(--mut);font-size:12px}
.wsm-root .wsm-seg{display:flex;background:var(--chip);border-radius:13px;padding:3px;gap:2px;margin-bottom:12px}
.wsm-root .wsm-seg button{flex:1;border:0;background:transparent;color:var(--mut);border-radius:10px;min-height:42px;font-weight:700;font-size:12.5px;padding:0 2px}
.wsm-root .wsm-seg button.wsm-on{background:var(--card);color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.14)}
.wsm-root .wsm-tab{display:none}
.wsm-root .wsm-tab.wsm-on{display:block}
.wsm-root .wsm-nrow{padding:12px 0;border-top:1px solid var(--line2)}
.wsm-root .wsm-nrow:first-child{border-top:0}
.wsm-root .wsm-nrow .wsm-h{display:flex;align-items:center;gap:10px}
.wsm-root .wsm-nb{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#25d366,#128c7e);color:#fff;display:grid;place-items:center;flex:none;font-weight:800;font-size:13px}
.wsm-root .wsm-nrow .wsm-h .wsm-t{flex:1;min-width:0}
.wsm-root .wsm-nrow .wsm-h .wsm-t b{display:block;font-size:14px}
.wsm-root .wsm-nrow .wsm-h .wsm-t span{color:var(--mut);font-size:12px}
.wsm-root .wsm-pbar{height:8px;background:var(--track);border-radius:9px;overflow:hidden;margin:9px 0 5px}
.wsm-root .wsm-pbar i{display:block;height:100%;border-radius:9px;background:linear-gradient(90deg,#22c55e,#16a34a)}
.wsm-root .wsm-pbar i.wsm-w{background:#f0a500}
.wsm-root .wsm-pmeta{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--mut);flex-wrap:wrap}
.wsm-root .wsm-prow{display:flex;gap:11px;align-items:flex-start;padding:11px 0;border-top:1px solid var(--line2)}
.wsm-root .wsm-prow:first-of-type{border-top:0}
.wsm-root .wsm-av2{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:13px;color:#fff;flex:none;background:linear-gradient(135deg,#60a5fa,#3b82f6)}
.wsm-root .wsm-av2.wsm-c2{background:linear-gradient(135deg,#f472b6,#e11d74)}
.wsm-root .wsm-av2.wsm-c3{background:linear-gradient(135deg,#a78bfa,#7c5cfc)}
.wsm-root .wsm-av2.wsm-c4{background:linear-gradient(135deg,#fbbf24,#f59e0b)}
.wsm-root .wsm-av2.wsm-c5{background:linear-gradient(135deg,#34d399,#059669)}
.wsm-root .wsm-av2.wsm-c6{background:linear-gradient(135deg,#fb923c,#ea580c)}
.wsm-root .wsm-prow .wsm-t{flex:1;min-width:0}
.wsm-root .wsm-prow .wsm-t b{font-size:13.5px}
.wsm-root .wsm-prow .wsm-t .wsm-m{color:var(--mut);font-size:12px;margin-top:1px}
.wsm-root .wsm-prow .wsm-q2{margin-top:6px;background:var(--bubout);color:var(--bubfg);border-radius:10px;padding:6px 10px;font-size:12.5px;line-height:1.35}
html.wxdk .wsm-root .wsm-prow .wsm-q2{color:#e9edef}
.wsm-root .wsm-find{display:flex;gap:8px;margin-bottom:10px}
.wsm-root .wsm-find .wsm-inp{flex:1;color:var(--mut);font-weight:500}
/* ---------- screen 4 ---------- */.wsm-root .wsm-sg2{display:flex;gap:6px;margin:2px 0 12px}
.wsm-root .wsm-sg2 div{flex:1;text-align:center;border-radius:12px;padding:0 4px;min-height:44px;display:flex;align-items:center;justify-content:center;gap:6px;font-weight:800;font-size:13px;background:var(--chip);color:var(--chipfg)}
.wsm-root .wsm-sg2 div.wsm-on{background:var(--on);color:var(--onfg)}
.wsm-root .wsm-sg2 div.wsm-dn{background:var(--okbg);color:var(--okfg)}
.wsm-root .wsm-sug{border:1px solid var(--line);border-radius:16px;padding:10px;margin-bottom:10px;background:var(--card)}
.wsm-root .wsm-sug.wsm-ok{border-color:#86efac;background:color-mix(in srgb,var(--okbg) 55%,var(--card))}
.wsm-root .wsm-sug .wsm-h{display:flex;align-items:center;gap:7px;margin-bottom:8px;flex-wrap:wrap}
.wsm-root .wsm-sug .wsm-h b{font-size:13px;font-weight:800}
.wsm-root .wsm-sug .wsm-chatbg{padding:9px;border-radius:12px}
.wsm-root .wsm-sug .wsm-ft2{display:flex;gap:8px;margin-top:9px;flex-wrap:wrap;align-items:center}
.wsm-root .wsm-twin{display:flex;flex-wrap:nowrap;align-items:flex-start;gap:7px;font-size:12px;color:var(--okfg);background:var(--okbg);border-radius:10px;padding:6px 10px;margin-top:8px}
.wsm-root .wsm-hd{display:flex;align-items:center;gap:10px;margin:16px 2px 8px;flex-wrap:wrap}
.wsm-root .wsm-hd h3{margin:0;font-size:15px;font-weight:800;flex:1;min-width:0}
.wsm-root .wsm-edit{border:1.5px solid #86efac;background:var(--card);border-radius:10px;padding:2px;position:relative}
.wsm-root .wsm-stp{display:flex;gap:6px;align-items:center}
.wsm-root .wsm-stp button{width:44px;height:44px;border-radius:12px;border:1px solid var(--line);background:var(--card);font-size:20px;font-weight:700;display:grid;place-items:center}
.wsm-root .wsm-stp .wsm-v2{min-width:70px;text-align:center;font-size:20px;font-weight:800}
.wsm-root .wsm-eta{background:linear-gradient(90deg,#e7f9ee,#f2fcf6);border:1.5px solid #bbf7d0;border-radius:16px;padding:12px 14px;margin:8px 0}
html.wxdk .wsm-root .wsm-eta{background:var(--okbg);border-color:var(--okline)}
.wsm-root .wsm-eta .wsm-big2{font-size:17px;font-weight:800;color:var(--okfg);line-height:1.3}
.wsm-root .wsm-eta .wsm-big2 small{display:block;font-size:12.5px;font-weight:600;margin-top:3px}
.wsm-root .wsm-three{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:10px 0 0}
.wsm-root .wsm-three div{background:var(--card);border:1px solid var(--okline);border-radius:12px;padding:7px 9px;font-size:12px}
.wsm-root .wsm-three span{display:block;font-weight:800;font-size:13.5px}
/* ---------- screen 5 ---------- */.wsm-root .wsm-hdp{display:flex;gap:12px;align-items:center;padding:4px 0 2px}
.wsm-root .wsm-hdp .wsm-av2{width:54px;height:54px;font-size:18px}
.wsm-root .wsm-hdp b{font-size:18px;font-weight:800;letter-spacing:-.3px;display:block}
.wsm-root .wsm-hdp>div span{color:var(--mut);font-size:12.5px}
.wsm-root .wsm-jstrip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0 4px}
.wsm-root .wsm-jc{border:1px solid var(--line);border-radius:14px;padding:9px 10px;background:var(--soft);min-width:0}
.wsm-root .wsm-jc small{display:block;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--faint)}
.wsm-root .wsm-jc b{display:flex;align-items:center;gap:5px;font-size:15px;font-weight:800;margin-top:3px;white-space:nowrap}
.wsm-root .wsm-jc.wsm-s{background:var(--okbg);border-color:var(--okline)}
.wsm-root .wsm-jc.wsm-s b{color:var(--okfg)}
.wsm-root .wsm-jc.wsm-v{background:var(--pbg);border-color:transparent}
.wsm-root .wsm-jc.wsm-v b{color:var(--pfg)}
.wsm-root .wsm-jc.wsm-t b{color:var(--okfg)}
.wsm-root .wsm-jc em{display:block;font-style:normal;font-size:11px;color:var(--mut);margin-top:2px;line-height:1.3}
.wsm-root .wsm-tline{position:relative;margin:6px 0 0 2px;padding-left:0}
.wsm-root .wsm-ev{position:relative;padding:0 0 16px 30px;min-height:46px}
.wsm-root .wsm-ev:before{content:'';position:absolute;left:8px;top:18px;bottom:-2px;border-left:2px dotted #cbd5e1}
html.wxdk .wsm-root .wsm-ev:before{border-color:#3b4a54}
.wsm-root .wsm-ev:last-child:before{display:none}
.wsm-root .wsm-ev .wsm-pt{position:absolute;left:0;top:3px;width:18px;height:18px;border-radius:50%;background:var(--card);border:3px solid #94a3b8}
.wsm-root .wsm-ev.wsm-g .wsm-pt{border-color:#16a34a}
.wsm-root .wsm-ev.wsm-b .wsm-pt{border-color:#3b82f6}
.wsm-root .wsm-ev.wsm-p .wsm-pt{border-color:#7c5cfc}
.wsm-root .wsm-ev.wsm-o .wsm-pt{border-color:#f59e0b}
.wsm-root .wsm-ev.wsm-hot .wsm-pt{background:#16a34a;border-color:#16a34a;box-shadow:0 0 0 4px rgba(34,197,94,.2)}
.wsm-root .wsm-ev .wsm-dt{font-size:11.5px;font-weight:800;color:var(--mut);letter-spacing:.02em}
.wsm-root .wsm-ev .wsm-tx2{font-size:13.5px;line-height:1.4;margin-top:1px;font-weight:600}
.wsm-root .wsm-ev .wsm-tx2 small{display:block;color:var(--mut);font-weight:500;font-size:12px;margin-top:1px}
.wsm-root .wsm-gap{margin:0 0 16px 30px;border:1.5px dashed var(--line);border-radius:12px;padding:8px 12px;font-size:12.5px;color:var(--mut);font-weight:700;background:var(--soft);display:flex;align-items:center;gap:8px;position:relative}
.wsm-root .wsm-gap:before{content:'';position:absolute;left:-22px;top:-8px;bottom:-16px;border-left:2px dotted #cbd5e1}
html.wxdk .wsm-root .wsm-gap:before{border-color:#3b4a54}
.wsm-root .wsm-hist{display:grid;grid-template-columns:repeat(24,1fr);gap:2px;height:92px;align-items:end;margin-top:8px}
.wsm-root .wsm-hist div{height:100%;display:flex;align-items:flex-end}
.wsm-root .wsm-hist i{display:block;width:100%;background:#dfe5ec;border-radius:3px 3px 0 0;min-height:2px}
html.wxdk .wsm-root .wsm-hist i{background:#2a3944}
.wsm-root .wsm-hist i.wsm-in{background:linear-gradient(180deg,#22c55e,#16a34a)}
.wsm-root .wsm-hl2{display:grid;grid-template-columns:repeat(24,1fr);gap:2px;font-size:11px;color:var(--faint);text-align:center;margin-top:3px}
.wsm-root .wsm-hl2 span{min-width:0;overflow:visible;white-space:nowrap;text-align:center}
.wsm-root .wsm-rng{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12.5px;margin-top:8px;flex-wrap:wrap}
.wsm-root .wsm-hint{font-size:12px;color:var(--mut);line-height:1.45}
/* ---------- desktop ---------- */@media (min-width:1100px){.wsm-root .wsm-grp{font-size:11px;letter-spacing:.09em;color:var(--faint);font-weight:800;padding:12px 10px 4px}
.wsm-root .wsm-pg{padding:6px 22px 28px}
.wsm-root .wsm-bar{border-radius:16px 16px 0 0;border:1px solid var(--line);border-bottom:0;margin:0 22px;padding:12px 18px}
.wsm-root .wsm-bar .wsm-btn{width:auto}
.wsm-root .wsm-cols{display:grid;grid-template-columns:minmax(0,1fr) 410px;gap:18px;align-items:start}
.wsm-root .wsm-cols>div{min-width:0}
.wsm-root .wsm-live3{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:0 18px;grid-template-areas:"p m" "b m";align-items:start}
.wsm-root .wsm-live3 .wsm-a-m{grid-area:m}
.wsm-root .wsm-live3 .wsm-a-p{grid-area:p}
.wsm-root .wsm-live3 .wsm-a-b{grid-area:b}
.wsm-root .wsm-acts{grid-template-columns:1fr 1fr}
.wsm-root .wsm-tiles{grid-template-columns:repeat(4,1fr)}}
.wsm-root .wsm-live3 .wsm-a-m,.wsm-root .wsm-live3 .wsm-a-p,.wsm-root .wsm-live3 .wsm-a-b{min-width:0}
.wsm-root .wsm-c2{margin-top:12px}
@media (min-width:1100px){.wsm-root .wsm-c2{margin-top:0}
.wsm-root .wsm-cols .wsm-cap:first-child{margin-top:0}}
html.wxdk .wsm-root .wsm-tg.wsm-on{background:#16a34a}
/* ---- additions of the real screens (not in the mockups) ---- */
.wsm-root,.wsm-root *{box-sizing:border-box}
.wsm-root{font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;color:var(--ink);font-size:14px;line-height:1.45;background:transparent;min-height:0;text-align:left}
.wsm-root button,.wsm-root input,.wsm-root select,.wsm-root textarea{font-family:inherit;color:inherit}
.wsm-root button{cursor:pointer}
.wsm-root svg.wsm-i{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:none}
.wsm-root b,.wsm-root strong{font-weight:700}
.wsm-root .wsm-pg{padding:4px 0 18px}
.wsm-root .wsm-hdr{display:flex;align-items:center;gap:10px;min-height:48px;margin:0 0 6px}
.wsm-root .wsm-hdr h1{font-size:18px;letter-spacing:-.2px;font-weight:800;margin:0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.wsm-root .wsm-hb{display:inline-flex;align-items:center;gap:3px;border:1px solid var(--line);background:var(--card);border-radius:999px;padding:0 13px 0 9px;height:40px;font-weight:700;font-size:13px;color:var(--ink);white-space:nowrap}
.wsm-root .wsm-dock{position:sticky;bottom:0;z-index:5;margin:12px -2px 0}
.wsm-root .wsm-bar{border-radius:16px;border:1px solid var(--line);padding:12px 14px}
.wsm-root .wsm-bar .wsm-btn{width:auto}
.wsm-root textarea.wsm-askbox{display:block;width:100%;resize:vertical;font:inherit;font-size:17px;line-height:1.4;font-weight:600;color:var(--ink);outline:0}
.wsm-root .wsm-fld{display:block;width:100%;border:1px solid var(--line);background:var(--card);border-radius:12px;min-height:44px;padding:10px 12px;font-size:14px;color:var(--ink);outline:0}
.wsm-root textarea.wsm-fld{min-height:84px;resize:vertical;line-height:1.4}
.wsm-root .wsm-fld:focus{border-color:#86efac;box-shadow:0 0 0 3px rgba(34,197,94,.14)}
.wsm-root .wsm-fl{font-size:11px;letter-spacing:.05em;text-transform:uppercase;font-weight:800;color:var(--faint);margin:12px 0 5px;display:block}
.wsm-root .wsm-sk{border-radius:14px;background:linear-gradient(90deg,var(--chip),var(--soft),var(--chip));background-size:200% 100%;animation:wsmsk 1.2s linear infinite;height:70px;margin-bottom:12px}
@keyframes wsmsk{0%{background-position:200% 0}100%{background-position:-200% 0}}
.wsm-root .wsm-err{background:var(--badbg);color:var(--badfg);border:1px solid var(--badline);border-radius:14px;padding:11px 13px;font-size:13px;line-height:1.45;margin:8px 0}
.wsm-root .wsm-err .wsm-btn{margin-top:8px}
.wsm-root .wsm-empty{text-align:center;color:var(--mut);padding:26px 10px;font-size:13px}
.wsm-root .wsm-pill.wsm-m{background:var(--obg);color:var(--ofg)}
.wsm-root .wsm-ch.wsm-pk{border-style:dashed}
.wsm-root .wsm-ch[disabled]{opacity:.5}
.wsm-root .wsm-ach{display:flex;align-items:center;gap:10px;width:100%;text-align:left;border:1px solid var(--line);background:var(--card);border-radius:16px;padding:14px;margin:0 0 10px;box-shadow:var(--shadow)}
.wsm-root .wsm-ach .wsm-em{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-size:21px;flex:none;background:var(--chip)}
.wsm-root .wsm-ach.wsm-sm .wsm-em{background:linear-gradient(135deg,#8b6cff,#5b3fd6);color:#fff}
.wsm-root .wsm-ach .wsm-t{flex:1;min-width:0}
.wsm-root .wsm-ach .wsm-t b{display:block;font-size:15px}
.wsm-root .wsm-ach .wsm-t span{display:block;font-size:12.5px;color:var(--mut);margin-top:2px}
.wsm-root .wsm-pr{display:flex;align-items:center;gap:10px;padding:11px 0;border-top:1px solid var(--line2);cursor:pointer;width:100%;text-align:left;background:none;border-left:0;border-right:0;border-bottom:0}
.wsm-root .wsm-pr:first-of-type{border-top:0}
.wsm-root .wsm-pr .wsm-t{flex:1;min-width:0}
.wsm-root .wsm-pr .wsm-t b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wsm-root .wsm-pr .wsm-t span{display:block;font-size:12px;color:var(--mut)}
.wsm-root .wsm-prop{border:1px solid var(--clline);background:linear-gradient(135deg,var(--cl1),var(--cl2));border-radius:14px;padding:11px 13px;margin:10px 0;color:var(--clfg);font-size:13px}
.wsm-root .wsm-prop b{color:var(--clb)}
.wsm-root .wsm-prop .wsm-row{margin-top:8px}
.wsm-root .wsm-diff{border:1px solid var(--line);border-radius:12px;padding:9px 11px;margin:8px 0;font-size:12.5px;background:var(--soft)}
.wsm-root .wsm-diff s{color:var(--badfg)} .wsm-root .wsm-diff ins{color:var(--okfg);text-decoration:none;font-weight:600}
.wsm-root .wsm-list{margin:6px 0 0;padding:0;list-style:none}
.wsm-root .wsm-list li{padding:7px 0 7px 22px;position:relative;font-size:13px;border-top:1px solid var(--line2)}
.wsm-root .wsm-list li:first-child{border-top:0}
.wsm-root .wsm-list li:before{content:'';position:absolute;left:4px;top:13px;width:8px;height:8px;border-radius:50%;background:#16a34a}
.wsm-root .wsm-list.wsm-no li:before{background:#f0a500}
.wsm-root .wsm-list.wsm-nx li:before{background:#7c5cfc}
.wsm-root .wsm-tabnav{display:flex;gap:4px;background:var(--chip);border-radius:12px;padding:3px;margin-bottom:12px}
.wsm-root .wsm-tabnav button{flex:1;border:0;background:none;border-radius:9px;min-height:36px;font-weight:700;font-size:12.5px;color:var(--chipfg);white-space:nowrap;padding:0 4px}
.wsm-root .wsm-tabnav button.wsm-on{background:var(--card);color:var(--ink);box-shadow:0 1px 2px rgba(15,23,42,.12)}
.wsm-root .wsm-more{width:100%;margin-top:8px}
.wsm-root .wsm-ctl{display:flex;gap:8px;flex-wrap:nowrap;margin-bottom:12px}
.wsm-root .wsm-ctl .wsm-btn{flex:1}
.wsm-root .wsm-sheeth{display:flex;gap:8px;margin-top:14px}
.wsm-root .wsm-sheeth .wsm-btn{flex:1}
.wsm-root .wsm-fine{font-size:11.5px;color:var(--mut);margin-top:6px;line-height:1.4}
.wsm-root .wsm-v{background:var(--var);border-radius:4px;padding:0 3px}
.wsm-root .wsm-pbar>i.wsm-w{background:#f0a500}
.wsm-root .wsm-nmini{display:inline-block;width:28px;height:28px;border-radius:9px;background:var(--chip);text-align:center;line-height:28px;font-weight:800;font-size:11px}
.wsm-root .wsm-seg{display:flex;gap:4px;background:var(--chip);border-radius:12px;padding:3px;margin-bottom:12px}
.wsm-wrap .wsm-aud{margin-top:6px}
.wsm-root .wsm-sel{width:100%;min-height:44px;border:1px solid var(--line);background:var(--card);border-radius:12px;padding:0 10px;font-size:14px}
.wsm-root details.wsm-grp .wsm-aud .wp,.wsm-root .wsm-aud .wsg{margin:0}
@media (max-width:1099px){.wsm-root .wsm-dock{margin:12px 0 0}}
@media (min-width:1100px){.wsm-root .wsm-dock{margin:12px 0 0}}
`; document.head.appendChild(s); }

  // ---------------------------------------------------------------------------------------------------------------- helpers
  // templates are written with plain class names; every class="a b" becomes class="wsm-a wsm-b" (the mockups' names, one prefix)
  const cx = (h) => String(h).replace(/class="([^"]*)"/g, (m, c) => 'class="' + c.split(/\s+/).filter(Boolean).map((x) => 'wsm-' + x).join(' ') + '"');
  const I = (p, st) => '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"' + (st ? ' style="' + st + '"' : '') + '>' + p + '</svg>';
  const IC = {
    back: '<path d="M15 6l-6 6 6 6"/>', mic: '<rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0014 0M12 18v3"/>', chev: '<path d="M6 9l6 6 6-6"/>',
    send: '<path d="M3 11l18-8-8 18-2-8-8-2z"/>', edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>', pause: '<path d="M8 5v14M16 5v14"/>', play: '<path d="M7 4l13 8-13 8z"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>', clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>', doc: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    bell: '<path d="M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 21a2 2 0 004 0"/>', search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>', img: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-8 8"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>', copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/>', down: '<path d="M12 4v11M7 11l5 5 5-5M5 20h14"/>',
  };
  const ic = (k, st) => I(IC[k] || '', st);
  const COLORS = ['#16a34a', '#7c5cfc', '#f59e0b', '#2563eb', '#e5484d', '#0ea5e9', '#db2777', '#65a30d'];
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const errText = (e) => (e && e.message) || 'Something went wrong. Nothing was changed.';
  const errBlock = (msg, retry) => '<div class="err">' + esc(msg) + (retry ? '<br><button type="button" class="btn s" data-a="retry">Try again</button>' : '') + '</div>';
  const skel = (n) => Array.from({ length: n || 3 }, () => '<div class="sk"></div>').join('');
  const dtext = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)/.exec(String(s || '')); if (!m) return String(s || ''); const d = new Date(+m[1], +m[2] - 1, +m[3]); return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); };
  const stamp = (ts) => { const d = new Date(ts * 1000); return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }); };
  const pctOf = (a, b) => (b ? Math.round(a * 100 / b) : 0);
  const SAMPLE = { name: 'Rahul', perfume: 'Oud Intense', days: '45', offer: '10% off', deadline: '14 Feb', code: 'CODE10' };
  function bubble(text, g, extra) {   // a WhatsApp bubble; the filled-in words are yellow
    const o = (g && g.offer) || {}; const smp = Object.assign({}, SAMPLE, { offer: o.text || SAMPLE.offer, deadline: o.deadline_text || SAMPLE.deadline, code: o.code || SAMPLE.code });
    const body = esc(text || '').replace(/\{\{\s*([a-z_0-9]+)\s*\}\}/gi, (m, k) => '<span class="v">' + esc(smp[k] || smp.name) + '</span>');
    return '<div class="chatbg"><div class="bub"><div class="tx">' + body + '</div>' + (extra || '') + '</div></div>';
  }
  const pillState = { approved: ['✓ Approved', 'ok'], waiting: ['⏳ Waiting for Meta', 'warn'], rejected: ['Rejected', 'bad'], paused: ['Paused by Meta', 'bad'], relabelled: ['Re-labelled as marketing', 'p'], missing: ['Not sent yet', ''], error: ['Will be tried again', 'warn'], none: ['—', ''] };
  const chipCls = { ok: 'ok', approved: 'ok', waiting: 'w', rejected: 'x', paused: 'x', error: 'w', missing: 'w', relabelled: 'pu' };

  // ---------------------------------------------------------------------------------------------------------------- the Smart campaign
  function open(el, opts) {
    opts = opts || {};
    let alive = true, poll = 0, seq = 0, askT = 0;
    const S = { seg: opts.seg || null, view: 'ask', text: '', answers: {}, resp: null, round: 0, busy: '', error: '', plan: null, status: null, tab: 'number', ppl: null, pf: 'all', pq: '', report: null, openG: { 1: true, 2: true }, openPlan: '', tested: false, props: [] };
    const live = () => alive && el.isConnected;
    const root = () => el.querySelector('.wsm-root');
    function stop() { alive = false; clearInterval(poll); clearTimeout(askT); }
    function back() { stop(); if (opts.onBack) opts.onBack(); }
    function shell(inner, title, pill, backLabel) {
      return cx('<div class="root"><div class="pgw"><div class="hdr"><button type="button" class="hb" data-a="back">' + ic('back') + esc(backLabel || 'Campaigns') + '</button><h1>' + esc(title || 'Smart campaign') + '</h1>' + (pill === undefined ? '<span class="live" style="background:var(--pbg);color:var(--pfg)">✨ Claude</span>' : pill || '') + '</div>' + inner + '</div></div>');
    }
    function paint(html, title, pill, backLabel) {
      if (!live()) return; const top = el.scrollTop; el.innerHTML = shell(html, title, pill, backLabel);
      el.querySelectorAll('[data-a="back"]').forEach((b) => { b.onclick = () => (S.view === 'report' ? go('live') : back()); });
      wire(); try { el.scrollTop = top; } catch (e) { }
    }
    function go(v) { S.view = v; clearInterval(poll); if (v === 'ask') drawAsk(); else if (v === 'plan') drawPlan(); else if (v === 'live') { drawLive(); startPoll(); } else if (v === 'report') drawReport(); }

    // ------------------------------------------------------------------------------------------------------------ 1. ask
    const optsOf = (q) => (q.options || []);
    const valOf = (q) => { const a = S.answers[q.id]; return a === undefined || a === 'claude' || a === '' ? q.suggest : a; };
    const mine = (q) => { const a = S.answers[q.id]; return !(a === undefined || a === 'claude' || a === ''); };
    function isOn(q, o) { const v = valOf(q); return Array.isArray(v) ? v.map(String).includes(String(o.v)) : String(v) === String(o.v); }
    function chipHtml(q, o) {
      const on = isOn(q, o), sug = on && !mine(q); const lock = !!o.locked;
      return '<button type="button" class="ch ' + (on ? 'on ' : '') + (sug ? 'sug ' : '') + (lock ? 'lock' : '') + '" data-q="' + esc(q.id) + '" data-v="' + esc(o.v) + '"' + (o.help ? ' title="' + esc(o.help) + '"' : '') + '>' + (sug ? '✨ ' : '') + esc(o.label) + (o.count != null && o.count !== '' ? ' <small>' + fmt(o.count) + '</small>' : '') + (lock ? ' <small>always</small>' : '') + '</button>';
    }
    function pickBox(q) {
      if (String(valOf(q)) !== 'pick') return '';
      if (q.id === 'dates') { const p = S.answers.dates_pick || {}; return '<div class="row" style="margin-top:8px"><input type="date" class="fld" style="flex:1" data-pick="from" value="' + esc(p.from || '') + '"><input type="date" class="fld" style="flex:1" data-pick="to" value="' + esc(p.to || '') + '"></div>'; }
      return '<div class="row" style="margin-top:8px"><input type="date" class="fld" style="flex:1" data-pickv="' + esc(q.id) + '" value=""></div>';
    }
    function qBlock(q, sub) {
      return (sub ? '<div class="ql">' + esc(q.section || q.label) + (q.kind === 'multi' ? ' <small style="font-weight:500;text-transform:none;letter-spacing:0">(tap more than one)</small>' : '') + '</div>' : '') + '<div class="chips">' + optsOf(q).map((o) => chipHtml(q, o)).join('') + '</div>' + pickBox(q);
    }
    function qWhy(q) { return q.why ? '<div class="sg">✨ <span><b>Claude suggests</b> · ' + esc(q.why) + '</span></div>' : ''; }
    function decideBtn(ids) { const done = ids.every((id) => !(S.answers[id] !== undefined && S.answers[id] !== 'claude' && S.answers[id] !== '')); return '<button type="button" class="cd ' + (done ? '' : '') + '" data-decide="' + esc(ids.join(',')) + '">✨ Claude decide</button>'; }
    function groupSummary(n, qs) {
      const R = S.resp || {}, a = R.audience || {}; const lab = (q) => { if (!q) return ''; const v = valOf(q); const L = optsOf(q); const ls = (Array.isArray(v) ? v : [v]).map((x) => (L.find((o) => String(o.v) === String(x)) || {}).label).filter(Boolean); return ls.join(', '); };
      const by = {}; qs.forEach((q) => { by[q.id] = q; });
      if (n === 1) return [lab(by.dates), lab(by.hours)].filter(Boolean).join(' · ') + (by.deadline ? ' · offer ends ' + lab(by.deadline) : '');
      if (n === 2) return fmt(a.sendable || 0) + ' people · ' + (lab(by.stage) || 'every stage') + ' · leaving out ' + (lab(by.leave_out) || 'nobody').toLowerCase();
      if (n === 3) return ['Goal: ' + lab(by.goal), by.offer ? 'Offer: ' + lab(by.offer) : '', by.media ? 'Media: ' + lab(by.media) : ''].filter((x) => x && !/: $/.test(x)).join(' · ');
      if (n === 4) return [lab(by.followup), by.reply_owner ? lab(by.reply_owner) + ' answers' : ''].filter(Boolean).join(' · ');
      return [lab(by.speed), by.budget ? 'cap ' + lab(by.budget) : '', by.test_first ? (String(valOf(by.test_first)) === 'no' ? 'no test' : 'test to my phone first') : ''].filter(Boolean).join(' · ');
    }
    const GTITLE = { 1: 'When', 2: 'Who and leave out', 3: 'What to say', 4: 'After sending', 5: 'Sending' };
    function groupHtml(n, qs) {
      const by = {}; qs.forEach((q) => { by[q.id] = q; }); let body = '';
      if (n === 1) {
        const ids = ['dates', 'deadline', 'hours'].filter((k) => by[k]);
        body = '<div class="q"><div class="qh"><b>' + (by.deadline ? 'Dates, last day and time of day' : 'Dates and time of day') + '</b>' + decideBtn(ids) + '</div>' + ids.map((k) => '<div class="ql">' + esc(by[k].label) + '</div><div class="chips">' + optsOf(by[k]).map((o) => chipHtml(by[k], o)).join('') + '</div>' + pickBox(by[k])).join('') + qWhy(by.dates || by.hours) + '</div>';
      } else if (n === 2) {
        const w = ['stage', 'value', 'numbers', 'tags'].filter((k) => by[k]); const a = (S.resp || {}).audience || {};
        body = '<div class="q"><div class="qh"><b>Who gets it</b>' + decideBtn(w) + '</div>' + w.map((k) => '<div class="ql">' + esc(k === 'stage' ? 'Stage' : by[k].label) + (by[k].kind === 'multi' ? ' <small style="font-weight:500;text-transform:none;letter-spacing:0">(tap more than one)</small>' : '') + '</div><div class="chips">' + optsOf(by[k]).map((o) => chipHtml(by[k], o)).join('') + '</div>').join('') + qWhy(by.stage) + '</div>'
          + (by.widen ? '<div class="q"><div class="qh"><b>' + esc(by.widen.label) + '</b></div>' + qBlock(by.widen) + qWhy(by.widen) + '</div>' : '')
          + countBox(a) + '<div id="wsmSeg"></div>'
          + (by.leave_out ? '<div class="q"><div class="qh"><b>Leave out</b>' + decideBtn(['leave_out']) + '</div><div class="chips">' + optsOf(by.leave_out).map((o) => chipHtml(by.leave_out, o)).join('') + '</div>' + qWhy(by.leave_out) + '</div>' : '');
      } else {
        const done = new Set(); body = qs.map((q) => {
          if (done.has(q.id)) return ''; done.add(q.id);
          if (q.id === 'test_first') { const on = String(valOf(q)) !== 'no'; return '<div class="q"><div class="qh"><b>' + esc(q.label) + '</b>' + decideBtn([q.id]) + '</div><div class="sw"><div class="t">Send me a test before everything<small>' + esc(q.why || 'Nothing goes out until you tap Approve.') + '</small></div><button type="button" class="tg ' + (on ? 'on' : '') + '" data-tf="1" aria-label="' + (on ? 'on' : 'off') + '"></button></div></div>'; }
          return '<div class="q"><div class="qh"><b>' + esc(q.label) + '</b>' + decideBtn([q.id]) + '</div>' + qBlock(q) + (q.id === 'speed' ? '<div class="sub">Gentle spreads it over several days and protects your numbers.</div>' : '') + (q.id === 'budget' ? '<div class="sub">Sending stops by itself at the cap. The estimate is shown on the plan.</div>' : '') + (q.id === 'reply_owner' ? '<div class="sub">Bot first = the bot prepares the answer and a salesperson can stop it within 20 seconds.</div>' : '') + qWhy(q) + '</div>';
        }).join('');
      }
      const open = S.openG[n] ? ' open' : '';
      return '<details class="grp" data-g="' + n + '"' + open + '><summary><span class="gn">' + n + '</span><span class="gt"><b>' + GTITLE[n] + '</b><span>' + esc(groupSummary(n, qs)) + '</span></span>' + I(IC.chev).replace('class="i"', 'class="chev"') + '</summary><div class="gb">' + body + '</div></details>';
    }
    function countBox(a) {
      const tot = a.total || 0, snd = a.sendable || 0, skip = Math.max(0, tot - snd);
      return '<div class="count"><div class="n">' + fmt(snd) + '</div><div class="t">people will get this<br><span style="font-weight:500;opacity:.85">updates live as you tap</span></div></div>'
        + '<div class="adds"><b>' + esc((a.adds_up && a.adds_up.text) || 'Total ' + fmt(tot) + ' = sending ' + fmt(snd) + ' + skipped ' + fmt(skip)) + '</b><div class="stack"><i style="width:' + (tot ? snd * 100 / tot : 0) + '%;background:#16a34a"></i><i style="width:' + (tot ? skip * 100 / tot : 0) + '%;background:#cbd5e1"></i></div>'
        + '<div class="lg"><span><span class="dot ok"></span>Sending ' + fmt(snd) + '</span><span><span class="dot"></span>Skipped ' + fmt(skip) + '</span></div></div>';
    }
    const EXAMPLES = ['Wake up old customers', 'Valentine offer', 'New oud launch'];
    function drawAsk() {
      S.view = 'ask'; const R = S.resp; let h = '';
      if (S.seg) h += '<div class="card" style="border-color:var(--okline);background:var(--okbg)"><b>👥 Sent only to the people you picked</b><div class="muted" style="margin-top:2px">' + esc(S.seg.name) + ' · ' + fmt(S.seg.n) + ' customers (segment tag ' + esc(S.seg.tag) + '). Stage and value choices below narrow them further only if you change them; opted-out and the leave-out rules still apply.</div></div>';
      h += '<div class="card ask"><h2>Tell Claude what you want to send</h2><textarea class="askbox" id="wsmT" rows="3" maxlength="600" placeholder="Example: hello sir, kaise ho. February mein bhejna hai">' + esc(S.text) + '</textarea>'
        + '<div class="askrow">' + (window.SpeechRecognition || window.webkitSpeechRecognition ? '<button type="button" class="mic" data-a="mic" aria-label="Speak">' + ic('mic', 'width:22px;height:22px') + '</button>' : '') + '<button type="button" class="btn p big" data-a="ask" style="flex:1;width:auto"' + (S.busy ? ' disabled' : '') + '>✨ ' + (R ? 'Ask again' : 'Ask Claude') + '</button></div>'
        + (R ? '' : '<div class="eg"><span class="muted">Try:</span>' + EXAMPLES.map((x) => '<button type="button" class="ch" data-ex="' + esc(x) + '">' + esc(x) + '</button>').join('') + '</div>') + '</div>';
      if (S.busy === 'ask') h += '<div class="cl" style="margin-top:12px"><div class="who"><span class="av">C</span>Claude</div>Claude is thinking… This takes a few seconds.</div>' + skel(3);
      if (S.error) h += errBlock(S.error, true);
      if (R && S.busy !== 'ask') {
        const u = R.understood || {}; const pills = ['goal', 'offer', 'when', 'words'].map((k) => (u[k] ? '<span class="pill p">' + esc(String(u[k].v).split(' · ')[0]) + '</span>' : '')).join('');
        h += '<div class="cl" style="margin-top:12px"><div class="who"><span class="av">C</span>Claude</div>I understood this as <b>' + esc((R.intent_label || '').toLowerCase()) + '</b>. ' + (R.source === 'ai' ? '' : '<span class="muted" style="color:inherit;opacity:.8">(Claude was not reachable, so plain rules were used.)</span> ') + 'A few things I need to know.<div class="row" style="margin-top:9px;gap:6px"><span class="pill p">' + esc(R.intent_label || '') + '</span>' + pills + '</div></div>';
        const c = R.counts || {}; const nQ = (R.questions || []).length, nMine = (R.questions || []).filter(mine).length;
        h += '<div class="prog" style="margin-top:12px"><span>✨ = Claude suggests (already picked, tap to change)</span></div><div class="prog" style="margin-top:-6px"><b>' + nQ + ' questions</b> · ' + nMine + ' answered by you · ' + (nQ - nMine) + ' Claude suggests</div>';
        const gs = {}; (R.questions || []).forEach((q) => { (gs[q.group] = gs[q.group] || []).push(q); });
        h += Object.keys(gs).sort().map((n) => groupHtml(+n, gs[n])).join('');
        if (window.WaSeg && gs[2]) h += ''; // the audience builder is mounted inside group 2 after painting
        if (S.props.length) h += propsHtml();
        h += '<div class="card" style="margin-top:4px"><h3>Anything Claude should remember?</h3><textarea class="fld" id="wsmNote" rows="2" maxlength="400" placeholder="Optional. For example: never send before 11 am, or keep the offer at 10%.">' + esc(S.answers.note || '') + '</textarea></div>';
      }
      const R2 = S.resp;
      h += '<div class="dock"><div class="bar" style="flex-direction:column;align-items:stretch;gap:8px">' + (R2 ? '<button type="button" class="btn p big" data-a="plan" data-all="1"' + (S.busy ? ' disabled' : '') + '>✨ Claude decide everything left</button>' : '')
        + '<div class="row" style="justify-content:space-between;flex-wrap:nowrap"><span class="muted" style="font-size:12px">' + (S.busy === 'plan' ? 'Claude is writing your plan…' : R2 ? 'Next you see the full plan. Nothing is sent yet.' : 'Type one line. Nothing is sent until you approve.') + '</span>' + (R2 ? '<button type="button" class="btn s" data-a="plan"' + (S.busy ? ' disabled' : '') + ' style="flex:none">Make plan with my picks</button>' : '') + '</div></div></div>';
      paint(h, 'Smart campaign');
      mountSeg();
    }
    let seg = null;
    function mountSeg() {
      const host = el.querySelector('#wsmSeg'); if (seg) { try { seg.destroy(); } catch (e) { } seg = null; } if (!host || !window.WaSeg || !WaSeg.audience || !S.resp) return;
      host.innerHTML = cx('<details class="grp" style="margin:12px 0 0;box-shadow:none"><summary style="min-height:48px"><span class="gt"><b style="font-size:13.5px">Fine-tune by dates, orders and source</b><span>Optional. Same builder as Contacts.</span></span>' + I(IC.chev).replace('class="i"', 'class="chev"') + '</summary><div class="gb" id="wsmSegBox"></div></details>');
      const d = host.querySelector('details'); let mounted = false;
      d.addEventListener('toggle', () => { if (!d.open || mounted) return; mounted = true; seg = WaSeg.audience(host.querySelector('#wsmSegBox'), { compact: true, filters: Object.assign({}, (S.resp.effective || {}).filters || {}, S.answers.filters || {}), onChange: (f) => { S.answers.filters = f; scheduleAsk(500, true); } }); });
    }
    function propsHtml() {
      return S.props.map((p, i) => '<div class="prop"><b>Remember this rule?</b><div style="margin-top:3px">“' + esc(p.text) + '”</div><div class="row"><button type="button" class="btn s p" data-prop="' + i + '" data-do="save">Yes, remember it</button><button type="button" class="btn s" data-prop="' + i + '" data-do="skip">Not now</button></div></div>').join('');
    }
    async function ask(first) {
      const my = ++seq; const text = (S.text || '').trim(); if (!text) { S.error = 'Type one line about the campaign you want.'; return drawAsk(); }
      S.error = ''; if (first) { S.busy = 'ask'; S.resp = null; S.answers = {}; S.props = []; drawAsk(); }
      try {
        const body = { text, answers: S.seg ? Object.assign({}, S.answers, { _seg: S.seg.tag }) : S.answers, round: first ? 0 : 1 }; if (!first && S.resp && S.resp.state) body.u = S.resp.state;
        const r = await api.post('wa.smart_ask', body); if (my !== seq || !live()) return;
        S.resp = r; S.round = r.round || 0; if (first && r.proposed_decisions && r.proposed_decisions.length) S.props = r.proposed_decisions; S.busy = '';
      } catch (e) { if (my !== seq || !live()) return; S.busy = ''; S.error = errText(e); }
      drawAsk();
    }
    function scheduleAsk(ms, quiet) { clearTimeout(askT); askT = setTimeout(() => ask(false), ms || 350); if (!quiet) drawAsk(); }
    async function makePlan(all) {
      const text = (S.text || '').trim(); if (!text) return; const my = ++seq; S.busy = 'plan'; S.error = ''; drawAsk();
      const a = Object.assign({}, S.answers); if (all) a._decide_all = 1; if (S.seg) a._seg = S.seg.tag;
      try {
        const body = { text, answers: a }; if (S.resp && S.resp.state) body.u = S.resp.state; if (S.planId) body.id = S.planId;
        const r = await api.post('wa.smart_plan', body); if (my !== seq || !live()) return;
        S.busy = ''; S.plan = r.plan; S.planId = r.plan.id; S.tested = false; S.openPlan = (r.plan.plan.groups[0] || {}).id || ''; go('plan');
      } catch (e) {
        if (my !== seq || !live()) return; S.busy = ''; S.error = errText(e); if (e.data && e.data.needs && e.data.needs.includes('deadline')) { S.openG[1] = true; }
        drawAsk();
      }
    }
    function setAnswer(id, v) {
      const q = ((S.resp || {}).questions || []).find((x) => x.id === id); if (!q) return;
      if (q.kind === 'multi') {
        let cur = valOf(q); cur = Array.isArray(cur) ? cur.slice().map(String) : []; v = String(v); const o = optsOf(q).find((x) => String(x.v) === v);
        if (o && o.locked) return;
        if (cur.includes(v)) cur = cur.filter((x) => x !== v); else cur.push(v);
        if (v === 'all' || v === 'any') cur = cur.includes(v) ? [v] : []; else cur = cur.filter((x) => x !== 'all' && x !== 'any');
        if (!cur.length && (id === 'numbers')) cur = ['all']; if (!cur.length && id === 'tags') cur = ['any'];
        S.answers[id] = cur;
      } else S.answers[id] = v;
    }

    // ------------------------------------------------------------------------------------------------------------ 2. plan
    const gColor = (i) => COLORS[i % COLORS.length];
    function understoodHtml(P) {
      const u = P.plan.understood || {}; const rows = [['Goal', 'goal'], ['When', 'when'], ['Who', 'who'], ['Offer', 'offer'], ['Words', 'words'], ['Speed', 'speed']];
      return '<div class="cl und"><div class="who"><span class="av">C</span>What I understood</div>' + rows.filter((r) => u[r[1]]).map((r) => '<div class="ln"><span>' + r[0] + '</span><div>' + esc(u[r[1]].v) + ' <span class="ck" style="margin-left:2px">' + (u[r[1]].src === 'you' ? 'from your words' : '✨ Claude chose') + '</span></div></div>').join('') + '</div>';
    }
    function numbersFor(P, g) {
      const rows = (P.plan.numbers || {}).rows || []; const ids = (g.numbers || []).map(Number);
      return rows.filter((n) => !ids.length || ids.includes(+n.id)).map((n) => '<span class="nm"><span class="dot ' + (n.quality === 'GREEN' ? 'ok' : n.quality === 'RED' ? 'bad' : 'w') + '"></span>' + esc(n.label) + (n.usable === false ? ' <small>' + esc(n.why || 'paused') + '</small>' : n.quality && n.quality !== 'GREEN' ? ' <small>' + esc(String(n.quality).toLowerCase()) + '</small>' : '') + '</span>').join('');
    }
    function groupCard(P, g, i) {
      const open = S.openPlan === g.id; const p = P.plan; const ch = g.options[g.chosen] || g.options.A || {}; const media = g.media || p.media || { kind: 'none' };
      const others = Object.keys(g.options).filter((k) => k !== g.chosen && g.options[k].text);
      const head = '<div class="gch" data-gc="' + esc(g.id) + '" style="cursor:pointer"><span class="sd" style="background:' + gColor(i) + '"></span><div class="t"><b>' + esc(g.title) + ' <em>~' + fmt(g.sendable) + ' people</em></b><span>' + esc(g.stage_mix || '') + ' · ' + esc(g.lang_label) + (g.send ? ' · ' + esc(g.send.days) : '') + '</span>'
        + (open ? '' : '<div class="row" style="gap:4px;margin-top:5px"><span class="pill ok">' + esc(g.lang_label) + '</span>' + (g.util && g.util.ok ? '<span class="pill b">Utility twin</span>' : '') + (g.rotate ? '<span class="pill">Rotate A/B/C</span>' : '<span class="pill">Uses ' + esc(g.chosen || 'A') + '</span>') + '</div>' + (g.learned ? '<div class="muted" style="font-size:12px;margin-top:4px">🧠 ' + esc(g.learned) + '</div>' : '')) + '</div>' + I(IC.chev, open ? 'transform:rotate(180deg)' : '').replace('class="i"', 'class="chev"') + '</div>';
      if (!open) return '<div class="gc">' + head + '</div>';
      let b = '<div class="sec">The message <span class="sp"></span><span class="pill ok">' + esc(g.lang_label) + '</span><span class="pill">' + (media.kind && media.kind !== 'none' ? esc(media.kind) : 'No media') + '</span></div>' + bubble(ch.text, g)
        + '<div class="muted" style="margin-top:7px;font-size:11.5px">Yellow = filled in for each person (name, last perfume). If we do not know a value, a safe default is used.</div>';
      b += '<div class="sec">Wording <span class="sp"></span><span class="muted" style="text-transform:none;letter-spacing:0;font-weight:600">' + Object.keys(g.options).length + ' options</span></div><div class="abc">' + Object.keys(g.options).map((k) => '<button type="button" class="' + (k === g.chosen ? 'on' : '') + '" data-swap="' + esc(g.id) + '|' + esc(k) + '">' + esc(k) + '</button>').join('') + '</div>'
        + others.map((k) => '<div class="alt"><b>' + esc(k) + '</b><div>' + esc(g.options[k].example || g.options[k].text) + '</div></div>').join('')
        + '<div class="sw"><div class="t">Rotate A / B / C<small>Each person gets one of the wordings. Spreads the words so numbers stay healthy.</small></div><button type="button" class="tg ' + (g.rotate ? 'on' : '') + '" data-rot="' + esc(g.id) + '" aria-label="' + (g.rotate ? 'on' : 'off') + '"></button></div>';
      const u = g.util || {};
      b += '<div class="sec">Meta approval</div><div class="ver"><div class="vb"><b>Marketing version</b><span class="pill o">Draft</span><small>Goes to Meta when you approve</small></div><div class="vb"><b>Utility version</b><span class="pill ' + (u.ok ? 'b' : '') + '">' + (u.ok ? 'Twin made' : 'Not used') + '</span><small>' + (u.ok ? 'Cheaper, for ' + fmt(u.eligible) + ' people' : 'Not for this group') + '</small></div></div><div class="info ' + (u.ok ? 'ok' : '') + '">' + esc(u.note || '') + (u.ok ? ' Marketing sends first; it switches to utility by itself when Meta approves it.' : '') + '</div>';
      if (g.offer && g.offer.type && g.offer.type !== 'none') b += '<div class="sec">Offer</div><div class="kv" style="border-top:0"><span>' + esc(g.offer.type.replace('_', ' ')) + '</span><b>' + esc(g.offer.text || '') + '</b></div>' + (g.offer.deadline_text ? '<div class="kv"><span>Ends</span><b>' + esc(g.offer.deadline_text) + '</b></div>' : '');
      b += '<div class="sec">Photo or video</div><div class="mh"><div class="th">' + ic('img', 'width:22px;height:22px') + '</div><div style="flex:1;min-width:0"><b>' + (media.kind && media.kind !== 'none' ? esc(media.kind) : 'None') + '</b><div class="muted">' + (media.kind && media.kind !== 'none' ? 'Sent with the message.' : 'Plain text feels personal.') + '</div></div><button type="button" class="btn s" data-a="answers">Change</button></div>';
      b += '<div class="sec">Sent from</div><div class="nums">' + numbersFor(P, g) + '</div>';
      b += '<div class="sec">When it goes</div><div class="kv" style="border-top:0"><span>Days</span><b>' + esc((g.send || {}).days || '') + '</b></div><div class="kv"><span>Hours</span><b>' + esc((g.send || {}).hours || '') + '</b></div>';
      if (g.followup && g.wave_msgs && g.wave_msgs.followup) b += '<div class="sec">If they do not reply</div><div class="fu"><div class="h">' + ic('bell', 'width:16px;height:16px') + ' After ' + g.followup.after_days + ' days · ' + (g.followup.n > 1 ? g.followup.n + ' times' : 'once') + ', then stop</div>' + bubble(g.wave_msgs.followup.text, g) + '<div class="muted" style="margin-top:6px">' + esc(g.followup.stop_rule || '') + '</div></div>';
      b += '<div class="acts"><button type="button" class="btn big d" style="min-height:48px;font-size:14px" data-test="' + esc(g.id) + '">' + ic('send', 'width:18px;height:18px') + ' Send me a test</button><button type="button" class="btn big" style="min-height:48px;font-size:14px" data-edit="' + esc(g.id) + '">' + ic('edit', 'width:18px;height:18px') + ' Edit / tell Claude to change it</button></div>';
      return '<div class="gc open">' + head + '<div class="gcb">' + b + '</div></div>';
    }
    function timelineHtml(P) {
      const p = P.plan; const W = p.waves || []; if (!W.length) return '';
      const day = 86400; const mid = (ts) => { const d = new Date(ts * 1000); d.setHours(0, 0, 0, 0); return d.getTime() / 1000; };
      const t0 = mid(Math.min.apply(null, W.map((w) => w.start_ts))); let t1 = Math.max.apply(null, W.map((w) => w.end_ts || (w.start_ts + 2 * day))); const last = mid(t1);
      let n = Math.round((last - t0) / day) + 1; n = Math.max(5, Math.min(14, n));
      let cells = ''; for (let i = 0; i < n; i++) { const d = new Date((t0 + i * day) * 1000 + 3600e3 * 12); const we = d.getDay() === 0 || d.getDay() === 6; cells += '<div class="d ' + (we ? 'we' : '') + '">' + d.toLocaleDateString('en-GB', { weekday: 'short' }) + '<b>' + d.getDate() + '</b></div>'; }
      let boxes = '', leg = ''; const kc = { drip: '#16a34a', launch: '#16a34a', followup: '#f59e0b', reminder: '#f59e0b', last: '#f97316' };
      W.forEach((w, i) => {
        const a = Math.max(0, Math.round((mid(w.start_ts) - t0) / day)); const e = Math.min(n - 1, Math.round((mid(w.end_ts || (w.start_ts + day)) - t0) / day)); const col = kc[w.kind] || COLORS[i % COLORS.length];
        boxes += '<div class="bx" style="grid-column:' + (a + 1) + ' / ' + (Math.max(a, e) + 2) + ';grid-row:' + (i + 2) + ';background:' + col + '">' + esc((w.title || w.kind).slice(0, 18)) + '</div>';
        leg += '<div><i style="background:' + col + '"></i><span><b>' + esc(w.title || w.kind) + ', ' + dtext(new Date(w.start_ts * 1000).toISOString().slice(0, 10)) + '</b> · ' + esc(w.note || '') + '</span></div>';
      });
      const endTxt = (p.dates || {}).deadline ? 'The offer ends ' + dtext(p.dates.deadline) + ': nothing is sent after that day' : 'After ' + dtext(new Date(t1 * 1000).toISOString().slice(0, 10)) + ': nothing more is sent';
      return '<div class="card"><h3>Timeline</h3><div class="tl" style="grid-template-columns:repeat(' + n + ',minmax(0,1fr))">' + cells + boxes + '<div class="end" style="grid-row:' + (W.length + 2) + '">' + esc(endTxt) + '</div></div><div class="lg2">' + leg + '</div></div>';
    }
    function numbersCard(P) {
      const nm = P.plan.numbers || {}; const rows = nm.rows || []; const sp = (P.plan.settings || {}).speed || 'gentle';
      return '<div class="card"><h3>Numbers and speed <span class="sp"></span><span class="pill b">' + esc(sp.charAt(0).toUpperCase() + sp.slice(1)) + '</span></h3><table class="tb2"><thead><tr><th>Number</th><th>Share</th><th>Health</th><th>Sends</th></tr></thead><tbody>'
        + rows.map((n) => '<tr><td><b>' + esc(n.label) + '</b></td><td>' + (n.share_pct || 0) + '%</td><td><span class="hl"><span class="dot ' + (n.quality === 'GREEN' ? 'ok' : n.quality === 'RED' ? 'bad' : 'w') + '"></span>' + esc(n.quality === 'GREEN' ? 'High' : n.quality === 'YELLOW' ? 'Medium' : n.quality === 'RED' ? 'Low' : n.quality || '—') + '</span></td><td>' + fmt(n.est_msgs) + '</td></tr>').join('')
        + '</tbody><tfoot><tr><td>All</td><td>100%</td><td></td><td>' + fmt(P.plan.audience.sendable) + '</td></tr></tfoot></table><div class="muted" style="margin-top:9px">' + esc(nm.text || '') + '. Numbers send at the same time; a weaker number gets a smaller share and goes slower.</div></div>';
    }
    function costCard(P) {
      const c = P.plan.cost || {}, p = P.plan;
      const safe = (p.safeguards || []).map((s, i) => '<div>' + ic(/hour|night|9 pm/i.test(s) ? 'clock' : 'shield') + '<span>' + esc(s) + '</span></div>').join('');
      return '<div class="card"><h3>Cost and safeguards</h3><div class="kv" style="border-top:0"><span>Estimated cost</span><b>about ' + money(Math.round(c.total || 0)) + (c.budget ? ' <span class="muted">(cap ' + money(c.budget) + ')</span>' : '') + '</b></div><div class="kv"><span>Messages</span><b>' + fmt(c.messages) + ' (' + fmt(c.marketing) + ' marketing, ' + fmt(c.utility) + ' utility)</b></div>'
        + (c.if_all_marketing && c.if_all_marketing > c.total ? '<div class="kv"><span>If all were marketing</span><b>' + money(Math.round(c.if_all_marketing)) + '</b></div>' : '') + '<div class="info ' + (c.over ? 'bad' : 'ok') + '">' + esc(c.verdict || '') + '</div><div class="safe" style="margin-top:6px">' + safe + '</div></div>'
        + (p.warnings && p.warnings.length ? '<div class="card"><h3>Good to know</h3>' + p.warnings.map((w) => '<div class="info w" style="margin-top:6px">' + esc(w) + '</div>').join('') + '</div>' : '')
        + '<div class="basedon">' + ic('doc') + '<span>Based on: ' + esc((p.based_on || []).join(', ') || 'your store information') + '.</span></div>';
    }
    function drawPlan() {
      S.view = 'plan'; const P = S.plan; if (!P) return drawAsk(); const p = P.plan; const draft = P.status === 'draft';
      const gl = p.groups.map((g, i) => groupCard(P, g, i)).join('');
      let h = '<div class="cols"><div>' + (draft ? '' : '<div class="info w" style="margin:0 0 10px">This plan is ' + esc(P.status) + '. It cannot be changed here.</div>') + understoodHtml(P)
        + '<div class="cap" style="margin-top:14px">' + p.groups.length + (p.groups.length === 1 ? ' group' : ' groups') + '</div>' + gl + '</div><div class="c2">' + timelineHtml(P) + numbersCard(P) + costCard(P) + '</div></div>';
      if (S.props.length) h += propsHtml();
      const c = p.cost || {}; const d = p.dates || {};
      h += '<div class="dock"><div class="bar"><div class="sum"><b>' + fmt(p.audience.sendable) + ' people</b>about ' + money(Math.round(c.total || 0)) + ' · ' + esc(dtext(d.from) + (d.to && d.to !== d.from ? '–' + dtext(d.to) : '')) + '</div>'
        + (draft ? '<button type="button" class="btn" data-a="answers" style="flex:none">Edit</button><button type="button" class="btn p" data-a="approve" style="flex:none;padding:0 22px"' + (S.busy ? ' disabled' : '') + '>Approve plan</button>' : '<button type="button" class="btn" data-a="clone" style="flex:none">Copy plan</button><button type="button" class="btn p" data-a="live" style="flex:none;padding:0 22px">See live status</button>') + '</div></div>';
      paint(h, P.title || 'Your plan', draft ? '<span class="live draft">Draft</span>' : '<span class="live">' + esc(P.status) + '</span>');
    }
    function loadFromServerPlan(r) { S.plan = r; S.planId = r.id; }

    // ------------------------------------------------------------------------------------------------------------ sheets (edit, test, approve, decisions)
    function sheet(title) { const m = modal(title); m.body.innerHTML = '<div class="wsm-root">' + '<div class="wsm-pgw"></div></div>'; const inner = m.body.querySelector('.wsm-pgw'); return { m, set: (h) => { inner.innerHTML = cx(h); }, q: (s) => inner.querySelector(s), qa: (s) => inner.querySelectorAll(s), close: m.close }; }
    function groupOf(id) { return (S.plan.plan.groups || []).find((g) => g.id === id); }
    function editSheet(gid, keep) {
      const g = groupOf(gid); if (!g) return; const keys = Object.keys(g.options).filter((k) => g.options[k].text).concat(g.wave_msgs && g.wave_msgs.followup ? ['followup'] : []);
      const cur = keep && keys.includes(keep.key) ? keep.key : g.chosen; const msg = cur === 'followup' ? g.wave_msgs.followup : g.options[cur];
      const sh = sheet('Edit · ' + g.title);
      sh.set('<div class="chips" style="margin-bottom:10px">' + keys.map((k) => '<button type="button" class="ch ' + (k === cur ? 'on' : '') + '" data-k="' + esc(k) + '">' + (k === 'followup' ? 'Reminder' : 'Message ' + esc(k)) + '</button>').join('') + '</div>' + bubble(msg.text, g)
        + (keep && keep.diff ? keep.diff : '') + (keep && keep.props ? keep.props : '')
        + '<label class="fl">Tell Claude what to change</label><textarea class="fld" id="eIns" rows="2" maxlength="500" placeholder="For example: make it shorter, or add the perfume name."></textarea><div class="sheeth"><button type="button" class="btn p" data-do="ai">✨ Rewrite</button></div>'
        + '<label class="fl">Or type the words yourself</label><textarea class="fld" id="eTxt" rows="4" maxlength="900">' + esc(msg.text) + '</textarea><div class="fine">Keep the {{name}} style words: they are filled in for each person.</div><div class="sheeth"><button type="button" class="btn" data-do="save">Save my wording</button></div>'
        + (p_count() > 1 ? '<div class="sheeth"><button type="button" class="btn r" data-do="drop">Drop this group from the plan</button></div>' : '') + '<div class="sheeth"><button type="button" class="btn d" data-do="done">Done</button></div>');
      sh.qa('[data-k]').forEach((b) => { b.onclick = () => { sh.close(); editSheet(gid, { key: b.dataset.k }); }; });
      const run = async (body, label) => {
        const btns = sh.qa('button'); btns.forEach((b) => { b.disabled = true; }); const first = sh.q('[data-do="ai"]'); if (label && first) first.textContent = label;
        try { const r = await api.post('wa.smart_edit', Object.assign({ id: S.planId, group: gid, option: cur === 'followup' ? 'followup' : cur }, body)); S.plan = r.plan; const dif = diffHtml(r.diff); const pr = (r.proposed_decisions || []).map((p) => ({ text: p.text, key: p.key, scope: p.scope })); sh.close(); drawPlan(); if (pr.length) { S.props = pr; drawPlan(); } editSheet(gid, { key: cur, diff: dif }); }
        catch (e) { btns.forEach((b) => { b.disabled = false; }); if (first) first.textContent = '✨ Rewrite'; toast(errText(e), true); }
      };
      sh.q('[data-do="ai"]').onclick = () => { const t = sh.q('#eIns').value.trim(); if (!t) return toast('Tell Claude what to change first.', true); run({ instruction: t }, 'Claude is rewriting…'); };
      sh.q('[data-do="save"]').onclick = () => { const t = sh.q('#eTxt').value.trim(); if (t.length < 8) return toast('The message is too short.', true); run({ text: t }); };
      const dr = sh.q('[data-do="drop"]'); if (dr) dr.onclick = () => { if (confirm('Drop “' + g.title + '” from this plan? Its people will not be messaged.')) { sh.close(); (async () => { try { const r = await api.post('wa.smart_edit', { id: S.planId, group: gid, drop: 1 }); S.plan = r.plan; drawPlan(); toast('Group dropped.'); } catch (e) { toast(errText(e), true); } })(); } };
      sh.q('[data-do="done"]').onclick = () => sh.close();
    }
    const p_count = () => (S.plan ? S.plan.plan.groups.length : 0);
    function diffHtml(diff) { return (diff || []).map((d) => '<div class="diff"><b>' + esc(d.what) + '</b><br>' + (d.before && d.before !== '—' ? '<s>' + esc(d.before) + '</s><br>' : '') + '<ins>' + esc(d.after) + '</ins></div>').join(''); }
    function testSheet(gid) {
      const g = groupOf(gid); if (!g) return; const keys = Object.keys(g.options).filter((k) => g.options[k].text).concat(g.wave_msgs && g.wave_msgs.followup ? ['followup'] : []); let pick = g.chosen;
      const sh = sheet('Send me a test');
      const draw = (note, needPhone) => {
        sh.set('<div class="chips" style="margin-bottom:10px">' + keys.map((k) => '<button type="button" class="ch ' + (k === pick ? 'on' : '') + '" data-k="' + esc(k) + '">' + (k === 'followup' ? 'Reminder' : 'Message ' + esc(k)) + '</button>').join('') + '</div>' + bubble(pick === 'followup' ? g.wave_msgs.followup.text : g.options[pick].text, g)
          + '<div class="fine">The test goes to your own WhatsApp number only, as plain text with the words filled in. Nothing goes to customers. You must have written to the business number in the last 24 hours (WhatsApp rule).</div>'
          + (needPhone ? '<label class="fl">Your own WhatsApp number (10 digits, saved once)</label><input class="fld" id="tPh" inputmode="numeric" maxlength="14" placeholder="98XXXXXXXX">' : '') + (note ? note : '') + '<div class="sheeth"><button type="button" class="btn p" data-do="go">' + ic('send', 'width:16px;height:16px') + ' Send test</button></div>');
        sh.qa('[data-k]').forEach((b) => { b.onclick = () => { pick = b.dataset.k; draw(); }; });
        sh.q('[data-do="go"]').onclick = async () => {
          const btn = sh.q('[data-do="go"]'); btn.disabled = true; btn.textContent = 'Sending…'; const body = { id: S.planId, group: gid, option: pick, who: 'person' }; const ph = sh.q('#tPh'); if (ph && ph.value.trim()) body.phone = ph.value.trim();
          try { const r = await api.post('wa.smart_test', body); S.tested = true; sh.set('<div class="info ok">Sent to <b>' + esc(r.to) + '</b>. Check your phone.</div>' + bubble(r.sent, g) + '<div class="sheeth"><button type="button" class="btn d" data-do="x">Done</button></div>'); sh.q('[data-do="x"]').onclick = () => sh.close(); }
          catch (e) { const m = errText(e); draw('<div class="err">' + esc(m) + '</div>', /not saved|Type your own/i.test(m)); }
        };
      };
      draw();
    }
    function approveSheet() {
      const P = S.plan, p = P.plan, c = p.cost || {}; const sh = sheet('Approve this plan');
      sh.set('<div class="kv" style="border-top:0"><span>People</span><b>' + fmt(p.audience.sendable) + ' in ' + p.groups.length + ' groups</b></div><div class="kv"><span>Estimated cost</span><b>about ' + money(Math.round(c.total || 0)) + '</b></div><div class="kv"><span>Starts</span><b>' + esc((p.dates || {}).start ? dtext(p.dates.start) : dtext(p.dates.from)) + '</b></div>'
        + (p.settings && p.settings.test_first && !S.tested ? '<div class="info w">You asked to test first, and no test was sent yet. Tap a group’s “Send me a test”, or approve anyway.</div>' : '')
        + (c.over ? '<div class="err">' + esc(c.verdict) + '</div><label class="row" style="margin-top:8px;gap:8px;flex-wrap:nowrap"><input type="checkbox" id="aOver" style="width:20px;height:20px"> <span>Send anyway, above my cap</span></label>' : '')
        + '<div class="info">Approving sends the messages to Meta for review and starts the schedule. The customer list is picked again on each start day. You can pause or stop at any time.</div>'
        + '<div class="sheeth"><button type="button" class="btn" data-do="no">Not yet</button><button type="button" class="btn p" data-do="go">Approve and start</button></div>');
      sh.q('[data-do="no"]').onclick = () => sh.close();
      sh.q('[data-do="go"]').onclick = async () => {
        const btn = sh.q('[data-do="go"]'); const over = sh.q('#aOver'); if (c.over && !(over && over.checked)) return toast('Tick “Send anyway” to go above your cap, or change the plan.', true);
        btn.disabled = true; btn.textContent = 'Approving…';
        try { const r = await api.post('wa.smart_approve', { id: S.planId, rev: P.rev, over_budget_ok: over && over.checked ? 1 : 0 }); sh.close(); toast('Approved. Claude sent your messages to Meta.'); const g = await api.get('wa.smart_get', { id: S.planId }); S.plan = g.plan; S.status = null; go('live'); }
        catch (e) {
          btn.disabled = false; btn.textContent = 'Approve and start'; const code = e.data && e.data.code;
          if (code === 'rev') { sh.close(); toast('The plan changed. Look at it again.', true); try { const g = await api.get('wa.smart_get', { id: S.planId }); S.plan = g.plan; drawPlan(); } catch (x) { } return; }
          if (code === 'already') { sh.close(); try { const g = await api.get('wa.smart_get', { id: S.planId }); S.plan = g.plan; go('live'); } catch (x) { } return; }
          sh.set('<div class="err">' + esc(errText(e)) + '</div><div class="sheeth"><button type="button" class="btn d" data-do="x">Close</button></div>'); sh.q('[data-do="x"]').onclick = () => sh.close();
        }
      };
    }

    // ------------------------------------------------------------------------------------------------------------ 3. live
    const WAVE_DOT = { running: 'Sending', scheduled: 'Scheduled', paused: 'Paused', done: 'Done', stopped: 'Stopped', none: 'Not started' };
    function messagesCard(st) {
      const M = st.messages || []; if (!M.length) return '';
      return '<div class="card a-m"><h3>Messages and Meta approval</h3>' + M.map((m) => {
        const ps = pillState[m.state] || [m.state_label || m.state, ''];
        return '<div class="mrow"><div class="h"><b>' + esc(m.label) + '</b><span class="pill ' + (m.line === 'U' ? 'b' : 'o') + '">' + (m.line === 'U' ? 'Utility' : 'Marketing') + '</span><span class="sp"></span><span class="pill ' + ps[1] + '">' + esc(m.state_label && !pillState[m.state] ? m.state_label : ps[0]) + '</span></div>'
          + '<div class="pn">' + (m.chips || []).map((c) => '<span class="cx ' + (chipCls[c.state] || 'w') + '" title="' + esc(c.name + ': ' + c.why) + '"><i></i>' + esc(c.label) + ' ' + esc(c.state === 'ok' ? 'ok' : c.state === 'waiting' ? 'waiting' : c.state === 'rejected' ? 'x' : c.why || c.state) + '</span>').join('') + '</div>'
          + (m.reason ? '<div class="rep bad">Meta said: “' + esc(m.reason) + '”.</div>' : '') + (m.replacement ? '<div class="rep" style="margin-top:6px">↳ <b>A new version was sent for approval</b> with softer words.</div>' : '')
          + (m.note ? '<div class="info ' + (m.state === 'relabelled' ? 'p' : m.state === 'rejected' ? 'w' : '') + '" style="margin-top:8px">' + esc(m.note) + '</div>' : '') + '</div>';
      }).join('') + '</div>';
    }
    function progressCard(st) {
      const g = st.progress || {}; const tot = g.people || 0; const ps = st.plan_status;
      const parts = [['Replied', g.replied, '#7c5cfc', 'wrote back', 'p'], ['Read', g.read, '#16a34a', 'opened, no reply yet', 'ok'], ['Delivered', g.delivered, '#22c55e', 'reached the phone', 'ok'], ['Sent', g.sent_only, '#86efac', 'left us, not yet delivered', 'ok'], ['Waiting for approval', g.waiting_approval, '#c7a24a', g.wait_text || 'not approved by Meta yet', ''], ['Waiting for its turn', g.waiting_turn, '#cbd5e1', g.turn_text || 'sends at its time', ''], ['Retrying', g.retrying, '#f59e0b', 'will try again', 'w'], ['Failed', g.failed, '#e5484d', 'gave up, not billed', 'bad']];
      const sum = parts.reduce((a, x) => a + (x[1] || 0), 0) || 1;
      const pill = ps === 'running' ? '<span class="pill ok"><span class="pulse" style="width:6px;height:6px;box-shadow:none"></span>Running</span>' : ps === 'paused' ? '<span class="pill warn">Paused</span>' : ps === 'done' ? '<span class="pill p">Finished</span>' : ps === 'approved' ? '<span class="pill b">Starting</span>' : '<span class="pill">' + esc(ps) + '</span>';
      const active = ps === 'running' || ps === 'approved' || ps === 'paused';
      return '<div class="card a-p"><h3>Sending progress <span class="sp"></span>' + pill + '</h3>'
        + (active ? '<div class="ctl"><button type="button" class="btn ' + (ps === 'paused' ? 'dis' : '') + '" data-ctl="pause">' + ic('pause', 'width:16px;height:16px') + ' Pause</button><button type="button" class="btn ' + (ps === 'paused' ? '' : 'dis') + '" data-ctl="resume">' + ic('play', 'width:16px;height:16px') + ' Resume</button><button type="button" class="btn r" data-ctl="stop" style="flex:none">' + ic('stop', 'width:15px;height:15px') + ' Stop</button></div>' : '')
        + '<div class="bigl"><div class="n">' + fmt(g.sent) + '</div><span>sent of ' + fmt(tot) + ' · ' + (g.pct || 0) + '%</span></div>'
        + '<div class="sbar">' + parts.map((x) => '<i style="width:' + (x[1] || 0) * 100 / sum + '%;background:' + x[2] + '" title="' + esc(x[0]) + '"></i>').join('') + '</div>'
        + '<div class="tiles">' + parts.map((x) => '<div class="tile"><small><span class="dot" style="background:' + x[2] + '"></span>' + esc(x[0]) + '</small><b>' + fmt(x[1]) + '</b><em>' + esc(x[3]) + '</em></div>').join('') + '</div>'
        + '<div class="money"><div><small>Orders</small><b>' + fmt(g.orders) + '</b></div><div><small>Revenue</small><b>' + money(g.revenue) + '</b></div><div><small>Cost so far</small><b>' + money(Math.round((g.cost || 0) * 100) / 100) + '</b></div></div>'
        + (g.attr_days ? '<div class="muted" style="margin-top:6px">Orders count when the customer orders within ' + g.attr_days + ' days of a message.</div>' : '')
        + ((st.reasons || []).length ? '<div class="cap" style="margin-top:16px">Why some did not go through</div>' + st.reasons.map((r) => '<div class="fr"><span class="n">' + fmt(r.n) + '</span><div class="t"><b>' + esc(r.text) + '</b><small>' + esc(r.note) + '</small></div><span class="pill ' + (r.kind === 'retry' ? 'warn' : 'bad') + '">' + (r.kind === 'retry' ? 'retrying' : 'failed') + '</span></div>').join('') : '')
        + ((st.alerts || []).length ? st.alerts.map((a) => '<div class="info w" style="margin-top:8px">' + esc(typeof a === 'string' ? a : a.text || '') + '</div>').join('') : '') + '</div>';
    }
    function breakdownCard(st) {
      const T = [['number', 'By number'], ['group', 'By group'], ['version', 'By version'], ['people', 'People']];
      let b = '';
      if (S.tab === 'number') {
        b = (st.by_number || []).length ? st.by_number.map((n) => '<div class="nrow"><div class="h"><span class="nb">' + esc(n.chip) + '</span><div class="t"><b>' + esc(n.label) + '</b><span>planned ' + fmt(n.planned) + '</span></div><span class="hl"><span class="dot ' + (n.quality === 'GREEN' ? 'ok' : n.quality === 'RED' ? 'bad' : 'w') + '"></span>' + (n.quality === 'GREEN' ? 'High' : n.quality === 'YELLOW' ? 'Medium' : n.quality === 'RED' ? 'Low' : esc(n.quality || '')) + '</span></div><div class="pbar"><i class="' + (n.quality === 'GREEN' ? '' : 'w') + '" style="width:' + Math.min(100, pctOf(n.sent, n.planned)) + '%"></i></div><div class="pmeta"><span><b style="color:var(--ink)">' + fmt(n.sent) + '</b> of ' + fmt(n.planned) + ' sent · ' + pctOf(n.sent, n.planned) + '%</span><span>failed ' + fmt(n.failed) + ' · replied ' + fmt(n.replied) + '</span></div></div>').join('') : '<div class="empty">Numbers appear here once sending starts.</div>';
      } else if (S.tab === 'group') {
        b = (st.by_group || []).map((g, i) => '<div class="nrow"><div class="h"><span class="sd" style="width:12px;height:12px;border-radius:50%;background:' + gColor(i) + ';flex:none"></span><div class="t"><b>' + esc(g.title) + '</b><span>' + fmt(g.people) + ' people · ' + esc(g.state_label) + '</span></div><span class="pill ' + (g.pct >= 100 ? 'ok' : 'b') + '">' + g.pct + '%</span>'
          + (g.state === 'running' || g.state === 'scheduled' ? '<button type="button" class="btn s" data-gctl="pause|' + esc(g.id) + '" style="padding:0 11px">' + ic('pause', 'width:14px;height:14px') + '</button>' : g.state === 'paused' ? '<button type="button" class="btn s" data-gctl="resume|' + esc(g.id) + '" style="padding:0 11px">' + ic('play', 'width:14px;height:14px') + '</button>' : '') + '</div>'
          + '<div class="pbar"><i style="width:' + Math.min(100, g.pct) + '%"></i></div><div class="pmeta"><span><b style="color:var(--ink)">' + fmt(g.sent) + '</b> sent · replied ' + fmt(g.replied) + '</span><span>orders ' + fmt(g.orders) + ' · ' + money(g.revenue) + '</span></div>'
          + (g.waves || []).filter((w) => w.status !== 'done' && w.note).map((w) => '<div class="muted" style="margin-top:6px">' + esc(w.kind === 'followup' ? 'Reminder' : 'Wave') + ': ' + esc(w.note) + '</div>').join('') + '</div>').join('') || '<div class="empty">No groups yet.</div>';
      } else if (S.tab === 'version') {
        const V = st.by_version || []; const sent = V.reduce((a, v) => a + v.sent, 0), rd = V.reduce((a, v) => a + v.read, 0), rp = V.reduce((a, v) => a + v.replied, 0), od = V.reduce((a, v) => a + v.orders, 0);
        const m = V.find((v) => v.line === 'M') || {}, u = V.find((v) => v.line === 'U') || {};
        b = '<table class="tb2"><thead><tr><th>Version</th><th>Sent</th><th>Read</th><th>Replied</th><th>Orders</th></tr></thead><tbody>' + V.map((v) => '<tr><td><b>' + esc(v.name) + '</b><small>' + money(v.rate) + ' each</small></td><td>' + fmt(v.sent) + '</td><td>' + v.read_pct + '%</td><td>' + fmt(v.replied) + '</td><td>' + fmt(v.orders) + '</td></tr>').join('') + '</tbody><tfoot><tr><td>Together</td><td>' + fmt(sent) + '</td><td>' + pctOf(rd, sent) + '%</td><td>' + fmt(rp) + '</td><td>' + fmt(od) + '</td></tr></tfoot></table>'
          + (u.sent > 0 && m.sent > 0 ? '<div class="info ok" style="margin-top:12px">Utility messages cost ' + Math.max(0, Math.round((1 - (u.rate || 0) / (m.rate || 1)) * 100)) + '% less. When Meta approves the utility version on a number, it takes over there by itself.</div>' : '<div class="info" style="margin-top:12px">Each marketing message has a cheaper utility twin. It starts sending as soon as Meta approves it.</div>')
          + '<div class="kv" style="margin-top:8px"><span>Marketing cost so far</span><b>' + money((st.cost_split || {}).marketing) + '</b></div><div class="kv"><span>Utility cost so far</span><b>' + money((st.cost_split || {}).utility) + '</b></div>';
      } else b = '<div id="wsmPpl">' + skel(3) + '</div>';
      return '<div class="card a-b"><h3>Breakdown</h3><div class="seg">' + T.map((t) => '<button type="button" class="' + (S.tab === t[0] ? 'on' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>').join('') + '</div>' + b + '</div>';
    }
    async function drawLive(quiet) {
      S.view = 'live'; const P = S.plan; if (!P) return;
      if (!S.status && !quiet) paint(skel(4), P.title, '<span class="live">…</span>');
      try { const my = ++seq; const st = await api.get('wa.smart_status', { id: S.planId, fresh: quiet ? '' : '1' }); if (my !== seq && quiet) return; if (!live() || S.view !== 'live') return; S.status = st; P.status = st.plan_status || P.status; }
      catch (e) { if (!live()) return; if (!S.status) { paint(errBlock(errText(e), true), P.title); return; } return; }
      renderLive();
    }
    function renderLive() {
      const P = S.plan, st = S.status; if (!P || !st) return; const active = ['running', 'approved', 'paused'].includes(st.plan_status);
      let h = '<div class="card" style="padding:12px 14px"><div class="row" style="flex-wrap:nowrap;gap:10px"><div style="flex:1;min-width:0"><b style="font-size:16px;letter-spacing:-.2px">' + esc(st.title) + '</b><div class="muted" style="margin-top:1px">Smart campaign · ' + fmt(st.people) + ' people' + (st.day ? ' · Day ' + st.day + ' of ' + st.days + (st.start_text ? ' (from ' + esc(st.start_text) + ')' : '') : '') + '</div></div>'
        + (st.plan_status === 'done' || P.has_report ? '<button type="button" class="btn s" data-a="report" style="flex:none">Report</button>' : '') + '<button type="button" class="btn s" data-a="clone" style="flex:none">' + ic('copy', 'width:14px;height:14px') + ' Copy</button></div>' + (st.paused_note ? '<div class="info w">' + esc(st.paused_note) + '</div>' : '') + '</div>';
      h += '<div class="live3">' + messagesCard(st) + progressCard(st) + breakdownCard(st) + '</div>';
      const pill = st.plan_status === 'running' ? '<span class="live"><span class="pulse"></span>Live</span>' : '<span class="live draft">' + esc(st.plan_status) + '</span>';
      paint(h, 'Smart campaign', pill, 'Campaigns');
      if (S.tab === 'people') loadPeople(true);
    }
    function startPoll() { clearInterval(poll); poll = setInterval(() => { if (!live()) return clearInterval(poll); if (S.view === 'live' && !document.hidden) drawLive(true); }, 20000); }
    async function loadPeople(reset) {
      const host = el.querySelector('#wsmPpl'); if (!host) return; if (reset) S.ppl = null;
      try { const r = await api.get('wa.smart_people', { id: S.planId, f: S.pf, q: S.pq, page: 1 }); S.ppl = r; } catch (e) { host.innerHTML = cx(errBlock(errText(e), true)); return; }
      const r = S.ppl; const c = r.counts || {}; const ST = { replied: ['Replied', 'p'], read: ['Read', 'ok'], delivered: ['Delivered', 'b'], sent: ['Sent', 'b'], failed: ['Failed', 'bad'], retry: ['Retrying', 'warn'], queued: ['Waiting', ''], skipped: ['Skipped', ''] };
      const F = [['all', 'All ' + fmt(c.all)], ['replied', 'Replied ' + fmt(c.replied)], ['failed', 'Failed ' + fmt(c.failed)], ['queued', 'Waiting ' + fmt(c.waiting)]];
      host.innerHTML = cx('<div class="find"><input class="fld" id="wsmQ" placeholder="Search name or number" value="' + esc(S.pq) + '"><button type="button" class="btn s" data-a="csv" style="flex:none">' + ic('down', 'width:15px;height:15px') + ' CSV</button></div><div class="chips" style="margin-bottom:6px">' + F.map((f) => '<button type="button" class="ch ' + (S.pf === f[0] ? 'on' : '') + '" data-pf="' + f[0] + '">' + f[1] + '</button>').join('') + '</div>'
        + (r.rows.length ? r.rows.map((p, i) => { const s = ST[p.status] || [p.status, '']; const ini = (p.name || p.phone || '?').replace(/[^A-Za-z0-9 ]/g, '').split(/\s+/).map((x) => x[0]).slice(0, 2).join('').toUpperCase() || '#'; const q = /^Wrote back: “(.*)”$/.exec(p.why || '');
          return '<div class="prow"><span class="av2 ' + ['', 'c2', 'c3', 'c4', 'c5', 'c6'][i % 6] + '">' + esc(ini) + '</span><div class="t"><b>' + esc(p.name || '+91 ' + p.phone) + '</b><div class="m">' + esc([p.number, p.version, p.group ? (groupTitle(p.group)) : ''].filter(Boolean).join(' · ')) + '</div>' + (q ? '<div class="q2">“' + esc(q[1]) + '”</div>' : p.why ? '<div class="m">' + esc(p.why) + '</div>' : '') + '</div><span class="pill ' + s[1] + '">' + s[0] + '</span></div>'; }).join('') + (r.more ? '<div class="muted" style="text-align:center;padding:10px">Showing the first ' + r.rows.length + '. Use search or the CSV file for everyone.</div>' : '') : '<div class="empty">Nobody here yet.</div>')
        + (r.scheduled_note ? '<div class="muted" style="margin-top:8px">' + esc(r.scheduled_note) + '</div>' : ''));
      const q = host.querySelector('#wsmQ'); let tm = 0; q.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { S.pq = q.value.trim(); loadPeople(); }, 400); };
      host.querySelectorAll('[data-pf]').forEach((b) => { b.onclick = () => { S.pf = b.dataset.pf; loadPeople(); }; });
      const cs = host.querySelector('[data-a="csv"]'); cs.onclick = csv;
    }
    const groupTitle = (id) => { const g = ((S.status || {}).by_group || []).find((x) => x.id === id); return g ? g.title : id; };
    async function csv() {
      try {
        const r = await fetch(api.buildUrl('wa.smart_people_csv', { id: S.planId, f: S.pf }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('Could not make the file (' + r.status + ').');
        const blob = await r.blob(); const a = document.createElement('a'); const mm = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || ''); a.href = URL.createObjectURL(blob); a.download = mm ? mm[1] : 'smart_plan_' + S.planId + '.csv'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
      } catch (e) { toast(errText(e), true); }
    }
    async function control(op, group) {
      if (op === 'stop' && !confirm('Stop this campaign for good? Messages not sent yet are dropped.')) return;
      try { const r = await api.post('wa.smart_' + op, { id: S.planId, group: group || '' }); toast(r.changed ? (op === 'pause' ? 'Paused.' : op === 'resume' ? 'Resumed.' : 'Stopped.') : 'Nothing to change.'); S.status = null; drawLive(); }
      catch (e) { toast(errText(e), true); }
    }

    // ------------------------------------------------------------------------------------------------------------ 4. report
    async function drawReport(refresh) {
      S.view = 'report'; clearInterval(poll); paint(skel(4), 'Report', '', 'Live status');
      try { S.report = await api.get('wa.smart_report', { id: S.planId, refresh: refresh ? 1 : '' }); } catch (e) { if (live()) paint(errBlock(errText(e), true), 'Report', '', 'Live status'); return; }
      if (!live()) return; const r = S.report, t = r.totals || {};
      const list = (cls, a) => (a && a.length ? '<ul class="list ' + cls + '">' + a.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '<div class="muted">Nothing to report.</div>');
      let h = '<div class="card" style="padding:12px 14px"><b style="font-size:16px">' + esc(r.title) + '</b><div class="muted">' + (r.final ? 'Final report' : 'Report so far (still sending)') + ' · ' + esc(r.at) + (r.source === 'ai' ? ' · written by Claude' : ' · numbers only') + '</div></div>'
        + '<div class="cols"><div><div class="card"><h3>Numbers</h3><div class="tiles"><div class="tile"><small>People</small><b>' + fmt(t.people) + '</b></div><div class="tile"><small>Sent</small><b>' + fmt(t.sent) + '</b></div><div class="tile"><small>Delivered</small><b>' + fmt(t.delivered) + '</b></div><div class="tile"><small>Read</small><b>' + fmt(t.read) + '</b></div><div class="tile"><small>Replied</small><b>' + fmt(t.replied) + '</b></div><div class="tile"><small>Failed</small><b>' + fmt(t.failed) + '</b></div></div>'
        + '<div class="money"><div><small>Orders</small><b>' + fmt(t.orders) + '</b></div><div><small>Revenue</small><b>' + money(t.revenue) + '</b></div><div><small>Cost</small><b>' + money(Math.round(t.cost || 0)) + '</b></div></div>'
        + (t.orders ? '<div class="kv" style="margin-top:8px"><span>Cost per order</span><b>' + money(Math.round(t.cost_per_order || 0)) + '</b></div><div class="kv"><span>Revenue for each ₹1 spent</span><b>₹' + (t.revenue_per_rupee || 0) + '</b></div>' : '') + '<div class="muted" style="margin-top:8px">Orders count when the customer orders within ' + (r.attr_days || 7) + ' days of a message (matched by phone number).</div></div>'
        + '<div class="card"><h3>By group</h3><table class="tb2"><thead><tr><th>Group</th><th>Sent</th><th>Replied</th><th>Orders</th></tr></thead><tbody>' + (r.by_group || []).map((g) => '<tr><td><b>' + esc(g.title) + '</b><small>' + fmt(g.people) + ' people</small></td><td>' + fmt(g.sent) + '</td><td>' + fmt(g.replied) + '</td><td>' + fmt(g.orders) + '</td></tr>').join('') + '</tbody></table></div></div>'
        + '<div class="c2"><div class="card"><h3>What worked</h3>' + list('', r.worked) + '</div><div class="card"><h3>What did not</h3>' + list('no', r.didnt) + '</div><div class="card"><h3>Next time</h3>' + list('nx', r.next) + '</div></div></div>'
        + '<div class="dock"><div class="bar"><div class="sum"><b>Do it again?</b>Copy this plan and change one thing.</div><button type="button" class="btn p" data-a="clone" style="flex:none">' + ic('copy', 'width:16px;height:16px') + ' Copy this plan</button></div></div>';
      paint(h, 'Report', '', 'Live status');
    }
    async function cloneNow() {
      if (S.busy) return; S.busy = 'clone';
      try { const r = await api.post('wa.smart_clone', { id: S.planId }); const g = await api.get('wa.smart_get', { id: r.plan.id }); S.busy = ''; loadFromServerPlan(g.plan); S.status = null; S.tested = false; S.openPlan = (g.plan.plan.groups[0] || {}).id || ''; toast('Copied. Look at the new plan, then approve it.'); go('plan'); }
      catch (e) { S.busy = ''; toast(errText(e), true); }
    }
    async function toAnswers() {
      const P = S.plan; S.text = (P.brief && P.brief.text) || S.text; S.answers = Object.assign({}, (P.brief && P.brief.answers) || {}); delete S.answers._decide_all; S.resp = null; S.view = 'ask'; S.busy = 'ask'; drawAsk(); await ask(true);
    }

    // ------------------------------------------------------------------------------------------------------------ events (one delegated handler per paint)
    function wire() {
      const r = el; r.querySelectorAll('[data-q]').forEach((b) => { b.onclick = () => { const q = b.dataset.q, v = b.dataset.v; setAnswer(q, v); if (v === 'pick') return drawAsk(); scheduleAsk(); }; });
      r.querySelectorAll('[data-decide]').forEach((b) => { b.onclick = () => { b.dataset.decide.split(',').forEach((id) => { delete S.answers[id]; if (id === 'dates') delete S.answers.dates_pick; }); scheduleAsk(); }; });
      r.querySelectorAll('[data-pick]').forEach((i) => { i.onchange = () => { const p = S.answers.dates_pick || {}; p[i.dataset.pick] = i.value; if (p.from && !p.to) p.to = p.from; S.answers.dates_pick = p; if (p.from) { S.answers.dates = 'pick'; scheduleAsk(0, true); } }; });
      r.querySelectorAll('[data-pickv]').forEach((i) => { i.onchange = () => { if (i.value) { S.answers[i.dataset.pickv] = i.value; scheduleAsk(0, true); } }; });
      r.querySelectorAll('[data-tf]').forEach((b) => { b.onclick = () => { const on = !b.classList.contains('wsm-on'); S.answers.test_first = on ? 'yes' : 'no'; scheduleAsk(); }; });
      r.querySelectorAll('[data-ex]').forEach((b) => { b.onclick = () => { S.text = b.dataset.ex; const t = r.querySelector('#wsmT'); if (t) t.value = S.text; ask(true); }; });
      r.querySelectorAll('details[data-g]').forEach((d) => { d.addEventListener('toggle', () => { S.openG[d.dataset.g] = d.open; }); });
      const t = r.querySelector('#wsmT'); if (t) { t.oninput = () => { S.text = t.value; }; t.onkeydown = (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') ask(true); }; }
      const nt = r.querySelector('#wsmNote'); if (nt) nt.oninput = () => { S.answers.note = nt.value; };
      r.querySelectorAll('[data-a]').forEach((b) => {
        const a = b.dataset.a; if (a === 'back') return;
        b.onclick = () => {
          if (a === 'ask') ask(true); else if (a === 'plan') makePlan(b.dataset.all === '1'); else if (a === 'retry') (S.view === 'live' ? drawLive() : S.view === 'report' ? drawReport() : ask(!S.resp)); else if (a === 'approve') approveSheet(); else if (a === 'answers') toAnswers(); else if (a === 'clone') cloneNow();
          else if (a === 'live') { S.status = null; go('live'); } else if (a === 'report') drawReport(); else if (a === 'csv') csv(); else if (a === 'mic') speak();
        };
      });
      r.querySelectorAll('[data-gc]').forEach((b) => { b.onclick = () => { S.openPlan = S.openPlan === b.dataset.gc ? '' : b.dataset.gc; drawPlan(); }; });
      r.querySelectorAll('[data-swap]').forEach((b) => { b.onclick = async () => { const [g, k] = b.dataset.swap.split('|'); try { const x = await api.post('wa.smart_edit', { id: S.planId, group: g, swap: k }); S.plan = x.plan; drawPlan(); } catch (e) { toast(errText(e), true); } }; });
      r.querySelectorAll('[data-rot]').forEach((b) => { b.onclick = async () => { const on = !b.classList.contains('wsm-on'); try { const x = await api.post('wa.smart_edit', { id: S.planId, group: b.dataset.rot, rotate: on ? 1 : 0 }); S.plan = x.plan; drawPlan(); } catch (e) { toast(errText(e), true); } }; });
      r.querySelectorAll('[data-edit]').forEach((b) => { b.onclick = () => editSheet(b.dataset.edit); });
      r.querySelectorAll('[data-test]').forEach((b) => { b.onclick = () => testSheet(b.dataset.test); });
      r.querySelectorAll('[data-tab]').forEach((b) => { b.onclick = () => { S.tab = b.dataset.tab; renderLive(); }; });
      r.querySelectorAll('[data-ctl]').forEach((b) => { b.onclick = () => { if (!b.classList.contains('wsm-dis')) control(b.dataset.ctl); }; });
      r.querySelectorAll('[data-gctl]').forEach((b) => { b.onclick = () => { const [op, g] = b.dataset.gctl.split('|'); control(op, g); }; });
      r.querySelectorAll('[data-prop]').forEach((b) => { b.onclick = async () => { const p = S.props[+b.dataset.prop]; if (b.dataset.do === 'save') { try { await api.post('wa.dec_save', { key: p.key, text: p.text, scope: p.scope || 'all' }); toast('Remembered. Claude will follow this rule next time.'); } catch (e) { toast(errText(e), true); return; } } S.props.splice(+b.dataset.prop, 1); S.view === 'plan' ? drawPlan() : drawAsk(); }; });
    }
    function speak() {
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition; if (!SR) return; const rec = new SR(); rec.lang = 'en-IN'; rec.interimResults = false;
      rec.onresult = (e) => { const x = e.results[0][0].transcript; S.text = (S.text ? S.text + ' ' : '') + x; const t = el.querySelector('#wsmT'); if (t) t.value = S.text; }; rec.onerror = () => toast('Could not hear you. Type it instead.', true); try { rec.start(); toast('Listening…'); } catch (e) { }
    }

    // ------------------------------------------------------------------------------------------------------------ start
    (async function start() {
      if (opts.id) {
        paint(skel(4), 'Smart campaign'); S.planId = +opts.id;
        try { const g = await api.get('wa.smart_get', { id: opts.id }); if (!live()) return; loadFromServerPlan(g.plan); S.text = (g.plan.brief || {}).text || ''; S.answers = Object.assign({}, (g.plan.brief || {}).answers || {}); S.openPlan = (g.plan.plan.groups[0] || {}).id || '';
          if (opts.view === 'report') return drawReport(); go(g.plan.status === 'draft' ? 'plan' : 'live'); }
        catch (e) { if (live()) paint(errBlock(errText(e), false), 'Smart campaign'); }
        return;
      }
      drawAsk();
    })();
    return { destroy: stop };
  }

  // ---------------------------------------------------------------------------------------------------------------- chooser (Normal | Smart)
  function chooser(o) {
    const m = modal('New campaign'); m.body.innerHTML = cx('<div class="root"><div class="pgw">'
      + '<button type="button" class="ach sm" data-c="smart"><span class="em">✨</span><span class="t"><b>Smart campaign</b><span>Type one line. Claude asks what it needs, writes the messages, plans the days and watches the result.</span></span></button>'
      + '<button type="button" class="ach" data-c="normal"><span class="em">📣</span><span class="t"><b>Normal campaign</b><span>Pick the people, the message and the time yourself. Now with message suggestions and a speed estimate.</span></span></button></div></div>');
    m.body.querySelector('[data-c="smart"]').onclick = () => { m.close(); o.onSmart && o.onSmart(); };
    m.body.querySelector('[data-c="normal"]').onclick = () => { m.close(); o.onNormal && o.onNormal(); };
  }

  // ---------------------------------------------------------------------------------------------------------------- past plans card (Campaigns screen)
  async function plansCard(host, o) {
    o = o || {}; host.innerHTML = '';
    let r; try { r = await api.get('wa.smart_list'); } catch (e) { return; } if (!host.isConnected) return; const L = (r.plans || []).slice(0, 12); if (!L.length) { host.innerHTML = ''; return; }
    const ST = { draft: ['Draft', 'warn'], approved: ['Starting', 'b'], running: ['Sending', 'ok'], paused: ['Paused', 'warn'], done: ['Finished', 'p'], cancelled: ['Stopped', 'bad'] };
    host.innerHTML = cx('<div class="root"><div class="card" style="margin:0 0 12px"><h3>✨ Smart campaigns <span class="sp"></span><span class="muted">' + L.length + ' recent</span></h3>' + L.map((p) => { const s = ST[p.status] || [p.status, '']; return '<button type="button" class="pr" data-p="' + p.id + '"><span class="t"><b>' + esc(p.title) + '</b><span>' + fmt(p.sendable) + ' people · ' + esc(p.start ? dtext(p.start) : '') + (p.cost ? ' · about ' + money(Math.round(p.cost)) : '') + '</span></span><span class="pill ' + s[1] + '">' + s[0] + '</span></button>'; }).join('') + '</div></div>');
    host.querySelectorAll('[data-p]').forEach((b) => { b.onclick = () => o.onOpen && o.onOpen(+b.dataset.p); });
  }

  // ---------------------------------------------------------------------------------------------------------------- Normal campaign: suggestions (What step)
  function normalSuggest(host, ctx) {
    ctx = ctx || {}; const S = ctx.state || {}; Object.assign(S, Object.assign({ items: null, auto: true, busy: false, err: '', ok: {}, edit: {}, saved: {}, text: '', editing: -1 }, S));
    const text = () => S.text || ''; const lang = () => (ctx.lang ? ctx.lang() : 'en');
    function draw() {
      const it = S.items || [];
      let h = '<div class="root"><div class="card" style="margin:10px 0"><h3>✨ Claude’s suggestions</h3>';
      if (!S.busy) h += '<div class="muted" style="margin-bottom:8px">Type your message here, then Claude writes 3 marketing and 3 utility versions. Utility versions are cheaper and Meta may approve them.</div><textarea class="fld" id="nsT" rows="3" maxlength="400" placeholder="Example: Valentine special, flat 20% off on couple perfumes">' + esc(S.text) + '</textarea><button type="button" class="btn p" data-n="go" style="width:100%;margin-top:8px">✨ ' + (S.items ? 'Suggest again' : 'Suggest messages') + '</button>';
      if (S.busy) h += '<div class="cl"><div class="who"><span class="av">C</span>Claude</div>Claude is writing… This takes a few seconds.</div>' + skel(2);
      if (S.err) h += errBlock(S.err, true);
      if (S.items) {
        h += '<div class="cl"><div class="who"><span class="av">C</span>Claude</div>I made ' + it.length + ' marketing and ' + it.filter((x) => x.u && x.u.ok).length + ' utility versions of your message. Each marketing message that has a utility version sends it first to the people it fits.</div>'
          + '<div class="sw"><div class="t">Auto-approve what I do not edit<small>' + (S.auto ? 'Everything you leave alone is sent to Meta for approval when you press Approve.' : 'Only the ones you approve one by one go to Meta.') + '</small></div><button type="button" class="tg ' + (S.auto ? 'on' : '') + '" data-n="auto" aria-label="auto-approve"></button></div>'
          + '<div class="hd"><h3>Marketing <span class="muted" style="font-weight:600">· ' + Object.keys(S.saved).length + ' approved</span></h3><button type="button" class="btn s" data-n="all">Approve all ' + it.length + '</button></div>';
        h += it.map((x, i) => {
          const done = S.saved[i]; const txt = S.edit[i] != null ? S.edit[i] : x.m.text;
          return '<div class="sug ' + (done ? 'ok' : '') + '"><div class="h"><b>M' + (i + 1) + '</b><span class="pill o">Marketing</span><span class="sp"></span><span class="pill ' + (done ? 'ok' : '') + '">' + (done ? '✓ Sent to Meta' : S.auto ? 'Will be approved' : 'Not approved yet') + '</span></div>'
            + (S.editing === i ? '<textarea class="fld" id="nsE' + i + '" rows="3">' + esc(txt) + '</textarea>' : bubble(txt, null))
            + (x.u && x.u.ok ? '<div class="twin">🔗 <span><b>Utility twin U' + (i + 1) + '</b> linked automatically. ' + esc(x.u.text) + '</span></div>' : '')
            + (x.m.why ? '<div class="muted" style="margin-top:6px">' + esc(x.m.why) + '</div>' : '')
            + '<div class="ft2">' + (S.editing === i ? '<button type="button" class="btn s p" style="flex:1" data-n="esave" data-i="' + i + '">Keep my wording</button>' : '<button type="button" class="btn s" style="flex:1" data-n="edit" data-i="' + i + '">' + ic('edit', 'width:15px;height:15px') + ' Edit</button>') + (done ? '' : '<button type="button" class="btn s p" style="flex:1" data-n="one" data-i="' + i + '">Approve</button>') + '</div></div>';
        }).join('');
      }
      host.innerHTML = cx(h + '</div></div>');
      host.querySelectorAll('[data-n]').forEach((b) => { b.onclick = () => act(b.dataset.n, +b.dataset.i); });
      const ta = host.querySelector('#nsT'); if (ta) ta.oninput = () => { S.text = ta.value; };
    }
    async function act(a, i) {
      if (a === 'go' || a === 'retry') { if (text().trim().length < 4) { toast('Type your message first.', true); return; } S.busy = true; S.err = ''; S.saved = {}; S.edit = {}; draw(); try { const r = await api.post('wa.camp_suggest', { text: text(), goal: ctx.goal ? ctx.goal() : '' }); S.items = (r.items || []).slice(0, 3); S.auto = r.auto_approve !== false; } catch (e) { S.err = errText(e); } S.busy = false; return draw(); }
      if (a === 'auto') { S.auto = !S.auto; return draw(); }
      if (a === 'edit') { S.editing = i; return draw(); }
      if (a === 'esave') { const t = host.querySelector('#nsE' + i); S.edit[i] = t ? t.value.trim() : S.items[i].m.text; S.items[i].edited = true; S.editing = -1; return draw(); }
      const send = async (idx) => {
        const x = S.items[idx]; const body = { items: [{ m_text: S.edit[idx] != null ? S.edit[idx] : x.m.text, u_text: x.u && x.u.ok ? x.u.text : '', u_on: !!(x.u && x.u.ok), approved: true }], lang: lang(), auto_approve: false };
        const r = await api.post('wa.camp_suggest_save', body); const s = (r.saved || [])[0]; if (!s || !s.ok) throw new Error((s && s.error) || 'Could not send it to Meta.'); S.saved[idx] = s; if (ctx.onSaved) ctx.onSaved(s, x);
      };
      try {
        if (a === 'one') { await send(i); toast('Sent to Meta for approval.'); }
        if (a === 'all') { for (let k = 0; k < S.items.length; k++) if (!S.saved[k]) await send(k); toast('All sent to Meta for approval.'); }
      } catch (e) { toast(errText(e), true); }
      draw();
    }
    draw();
    return { saved: () => S.saved, auto: () => S.auto, items: () => S.items, settle: async () => {   // called when the wizard finishes: auto-approve everything the owner did not touch
      if (!S.items || !S.auto) return []; const out = []; for (let k = 0; k < S.items.length; k++) { if (S.saved[k] || S.items[k].edited) continue; try { const x = S.items[k]; const r = await api.post('wa.camp_suggest_save', { items: [{ m_text: x.m.text, u_text: x.u && x.u.ok ? x.u.text : '', u_on: !!(x.u && x.u.ok), approved: true }], lang: lang(), auto_approve: true }); const s = (r.saved || [])[0]; if (s && s.ok) { S.saved[k] = s; out.push(s); } } catch (e) { } } draw(); return out; } };
  }

  // ---------------------------------------------------------------------------------------------------------------- Normal campaign: speed estimate + retries (When step)
  function speedBox(host, ctx) {
    ctx = ctx || {}; const S = { speed: ctx.speed || 'gentle', retries: Math.max(1, Math.min(3, +ctx.retries || 3)), r: null, err: '' }; let tm = 0, seq = 0;
    async function load() {
      const my = ++seq; const size = ctx.size ? ctx.size() : 0; const id = ctx.id ? ctx.id() : 0; if (!size && !id) { S.r = null; S.err = ''; return draw(); }
      try { const r = await api.post('wa.camp_speed', { size, id, speed: S.speed, retries: S.retries }); if (my !== seq) return; S.r = r; S.err = ''; } catch (e) { if (my !== seq) return; S.err = errText(e); }
      draw();
    }
    function draw() {
      const r = S.r; const A = (r && r.all) || {};
      let h = '<div class="root"><div class="card" style="margin:10px 0">';
      h += '<div class="eta">' + (r ? '<div class="big2">' + esc(r.text) + '<small>' + esc(S.speed) + ' speed · starts after you approve</small></div>' : S.err ? '<div class="big2" style="font-size:14px">' + esc(S.err) + '</div>' : '<div class="big2" style="font-size:14px">Pick the people first and the estimate shows here.</div>') + '</div>';
      h += '<h3>Speed <span class="muted" style="font-weight:600">· you can change it</span></h3><div class="chips">' + ['gentle', 'normal', 'fast'].map((k) => '<button type="button" class="ch ' + (S.speed === k ? 'on' : '') + '" data-s="' + k + '">' + k.charAt(0).toUpperCase() + k.slice(1) + (A[k] ? ' · ' + (A[k].minutes < 90 ? A[k].minutes + ' min' : A[k].days > 1 ? A[k].days + ' days' : Math.round(A[k].minutes / 60) + ' h') : '') + '</button>').join('') + '</div><div class="sub">Gentle is easiest on your numbers. Fast can lower a number’s health.</div>'
        + '<h3 style="margin-top:16px">Retries <span class="muted" style="font-weight:600">· for messages that fail</span></h3><div class="row" style="flex-wrap:nowrap;gap:12px"><div class="stp"><button type="button" data-r="-1" aria-label="less">−</button><span class="v2">' + S.retries + '</span><button type="button" data-r="1" aria-label="more">+</button></div><div class="muted" style="flex:1">' + esc(r ? r.retry_text : 'Up to ' + S.retries + ' tries in total. Meta bills only delivered messages.') + '</div></div></div></div>';
      host.innerHTML = cx(h);
      host.querySelectorAll('[data-s]').forEach((b) => { b.onclick = () => { S.speed = b.dataset.s; if (ctx.onChange) ctx.onChange(get()); load(); }; });
      host.querySelectorAll('[data-r]').forEach((b) => { b.onclick = () => { S.retries = Math.max(1, Math.min(3, S.retries + +b.dataset.r)); if (ctx.onChange) ctx.onChange(get()); load(); }; });
    }
    const get = () => ({ speed: S.speed, retries: S.retries });
    draw(); load();
    return { get, refresh: () => { clearTimeout(tm); tm = setTimeout(load, 150); } };
  }

  window.WaSmart = { open, chooser, plansCard, normalSuggest, speedBox, version: 1 };
}());
