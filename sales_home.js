/**
 * Sales Home — the top of My Sales (the salesperson's landing page).
 *
 *   hero      one cheering headline for the moment (positive; "ting ting" nudge when behind; a 🎁 bonus hook when close to the target)
 *   missions  🎯 daily target (orders to go, +bonus % on every order)   🔑 code goal (70% of the day's orders with a code → ALL orders pay full)
 *   offer     the single orange ⚡ Limited Time Offer card (flash_offer.js mounts itself into #shOffer, see window.FO_MOUNT)
 *   price     💲 My price chart — shower gel / attar / perfume combos and the % each price earns (api: combo_price.get)
 *   allocate  a big bottom button (＋ Allocate order · N waiting) that opens Order Allocation as a bottom-sheet pop-up
 *             (order_allocation.html?embed=1 in an iframe — same page, same congratulations pop-up, no sidebar)
 *
 * The page (my_sales.html) feeds it: SalesHome.setCoach(my.coach response), SalesHome.setRating(score), and gives it
 * SalesHome.onRefresh = () => reload the coach / target after orders were allocated.
 * Needs: #shHero, #shQuests, #shOffer, #shPrice in the page, plus api.js. No other dependency.
 */
(function () {
  if (window.SalesHome) return;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (v) => '₹' + Math.round(Number(v) || 0).toLocaleString('en-IN');
  const plural = (n, w) => n + ' ' + w + (Number(n) === 1 ? '' : 's');
  const reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };

  const css = `
.sh-hero{position:relative; overflow:hidden; border-radius:18px; padding:12px 14px 11px; margin-bottom:8px; color:#fff; background:linear-gradient(135deg,#111827 0%,#1f2937 60%,#312e81 130%); box-shadow:0 12px 30px rgba(17,24,39,.22);}
.sh-hero.tone-win{background:linear-gradient(135deg,#065f46 0%,#047857 55%,#0f766e 130%);}
.sh-hero.tone-go{background:linear-gradient(135deg,#7c2d12 0%,#c2410c 55%,#db2777 140%);}
.sh-hero.tone-ting{background:linear-gradient(135deg,#78350f 0%,#b45309 60%,#9a3412 140%);}
.sh-hero::after{content:""; position:absolute; right:-40px; top:-50px; width:170px; height:170px; border-radius:50%; background:rgba(255,255,255,.07);}
.sh-top{display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; position:relative; z-index:1;}
.sh-hi{font-size:13px; font-weight:700; opacity:.85;}
.sh-chip{display:inline-flex; align-items:center; gap:6px; border-radius:99px; padding:5px 11px; font-size:12px; font-weight:800; text-decoration:none; color:#fff; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.25); white-space:nowrap;}
.sh-chip.warn{background:#f59e0b; border-color:#f59e0b; color:#451a03;} .sh-chip.ok{background:rgba(34,197,94,.22); border-color:rgba(134,239,172,.5);}
.sh-snd{border:0; background:rgba(255,255,255,.14); color:#fff; border-radius:99px; width:30px; height:30px; padding:0; min-height:0; cursor:pointer; font-size:14px; line-height:1;}
.sh-row{display:flex; align-items:center; gap:8px;}
.sh-emoji{font-size:26px; line-height:1; margin:5px 0 0; display:inline-block; position:relative; z-index:1;}
.sh-head{font-size:17px; line-height:1.2; font-weight:900; margin:2px 0 3px; letter-spacing:-.01em; position:relative; z-index:1;}
.sh-sub{font-size:12px; line-height:1.4; opacity:.92; margin:0; position:relative; z-index:1;}
.sh-sub b{color:#fde68a;}
.sh-bar{position:relative; height:10px; border-radius:7px; background:rgba(255,255,255,.2); margin:13px 0 3px; z-index:1;}
.sh-bar i{position:absolute; left:0; top:0; bottom:0; border-radius:9px; background:linear-gradient(90deg,#fde68a,#fbbf24); transition:width .9s cubic-bezier(.2,.8,.2,1);}
.sh-bar u{position:absolute; top:-5px; bottom:-5px; width:3px; margin-left:-1px; background:#fff; border-radius:2px; text-decoration:none; opacity:.9;}
.sh-bar s{position:absolute; right:-2px; top:-22px; font-size:11px; font-weight:900; text-decoration:none; background:#fde68a; color:#78350f; border-radius:99px; padding:1px 7px;}
.sh-bl{display:flex; justify-content:space-between; gap:8px; font-size:11.5px; opacity:.9; position:relative; z-index:1;}
.sh-quests{display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;}
.sh-q{background:#fff; border:1px solid var(--line,#e5e7eb); border-radius:14px; padding:9px 11px 9px; min-width:0; position:relative; overflow:hidden;}
.sh-q.done{border-color:#86efac; background:linear-gradient(180deg,#f0fdf4,#fff);}
.sh-q.near{border-color:#fdba74; background:linear-gradient(180deg,#fff7ed,#fff); box-shadow:0 0 0 3px rgba(251,146,60,.14);}
.sh-qh{display:flex; align-items:center; gap:6px; font-size:11.5px; font-weight:800; color:#334155;}
.sh-qh em{margin-left:auto; font-style:normal; font-size:11px; font-weight:900; border-radius:99px; padding:2px 9px; background:#f1f5f9; color:#475569; white-space:nowrap;}
.sh-q.done .sh-qh em{background:#dcfce7; color:#166534;} .sh-q.near .sh-qh em{background:#ffedd5; color:#9a3412;}
.sh-qbig{font-size:15px; line-height:1.2; font-weight:900; margin:5px 0 6px; color:#0f172a;}
.sh-qbar{height:7px; border-radius:6px; background:#e2e8f0; overflow:hidden;} .sh-qbar i{display:block; height:100%; border-radius:6px; background:linear-gradient(90deg,#fb923c,#f97316); transition:width .9s cubic-bezier(.2,.8,.2,1);}
.sh-q.done .sh-qbar i{background:linear-gradient(90deg,#4ade80,#16a34a);}
.sh-segs{display:flex; gap:4px;} .sh-segs i{flex:1; height:7px; border-radius:5px; background:#e2e8f0; min-width:6px; transition:background .4s;} .sh-segs i.on{background:linear-gradient(90deg,#4ade80,#16a34a);}
.sh-qs{font-size:11px; color:#64748b; line-height:1.4; margin-top:5px;} .sh-qs b{color:#0f172a;}
.sh-qw{margin-top:8px; font-size:11.5px; line-height:1.4; color:#9a3412; background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:6px 9px;}
.sh-q.done .sh-qw{color:#166534; background:#f0fdf4; border-color:#bbf7d0;}
#shOffer{margin-bottom:0;}
#shOffer #foBanner{margin-bottom:14px;}
.sh-price{padding:14px 16px 12px; margin-bottom:14px; border-radius:18px;}
.sh-ph{display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;} .sh-ph h2{margin:0; font-size:15px;} .sh-ph span{font-size:11.5px; color:var(--muted,#64748b);}
.sh-more{display:block; width:100%; margin-top:6px; border:0; background:#f1f5f9; color:#334155; border-radius:11px; padding:8px; font-weight:800; font-size:12.5px; cursor:pointer; min-height:0;}
.sh-fn{font-size:11.5px; color:#64748b; line-height:1.5; margin-top:8px;} .sh-fn a{font-weight:800;}
.sh-calc{margin-top:12px; border-top:1px dashed #cbd5e1; padding-top:10px;}
.sh-calc h3{margin:0 0 8px; font-size:14px;}
.sh-md{display:flex; gap:6px; margin-bottom:8px;} .sh-md button{flex:1; border:1px solid #e2e8f0; background:#f8fafc; color:#334155; border-radius:10px; padding:7px 4px; font-weight:800; font-size:12.5px; min-height:0; cursor:pointer;} .sh-md button.on{background:#0f172a; color:#fff; border-color:#0f172a;}
.sh-in{display:flex; gap:8px;} .sh-in label{flex:1; font-size:11px; font-weight:800; color:#64748b; display:block;} .sh-in input,.sh-in select{width:100%; margin-top:3px; padding:8px 9px; border:1px solid #cbd5e1; border-radius:10px; font-size:15px; font-weight:800; box-sizing:border-box; background:#fff;}
.sh-sz{display:flex; flex-wrap:wrap; gap:5px; margin:8px 0 0;} .sh-sz button{border:1px solid #e2e8f0; background:#f8fafc; border-radius:99px; padding:4px 10px; font-size:12px; font-weight:800; color:#334155; min-height:0; cursor:pointer;} .sh-sz button.on{background:#dbeafe; border-color:#93c5fd; color:#1e40af;}
.sh-ch{display:flex; flex-wrap:wrap; gap:5px; margin:8px 0;} .sh-ch label{display:inline-flex; align-items:center; gap:4px; border:1px solid #e2e8f0; background:#f8fafc; border-radius:99px; padding:4px 10px; font-size:12px; font-weight:800; color:#334155; cursor:pointer;} .sh-ch label.on{background:#dcfce7; border-color:#86efac; color:#166534;} .sh-ch input{margin:0; width:auto;}
.sh-res{border-radius:12px; padding:10px 12px; background:#f0fdf4; border:1px solid #bbf7d0; margin-top:6px;} .sh-res.lo{background:#fffbeb; border-color:#fde68a;} .sh-res.bad{background:#fef2f2; border-color:#fecaca; color:#991b1b;} .sh-res.na{background:#f1f5f9; border-color:#e2e8f0; color:#475569;}
.sh-res .big{font-size:20px; font-weight:900; line-height:1.2;} .sh-res .why{font-size:11.5px; color:#475569; margin-top:4px; line-height:1.45;} .sh-res .up{margin-top:6px; font-size:12.5px; font-weight:700; color:#166534; background:#fff; border-radius:8px; padding:5px 8px;}
.sh-nt{font-size:12px; line-height:1.45; border-radius:10px; padding:6px 9px; margin-top:6px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;} .sh-nt.ok{background:#f0fdf4; border-color:#bbf7d0; color:#166534;}
.sh-calc{margin-top:8px; padding-top:7px;} .sh-calc h3{font-size:13px; margin:0 0 5px;}
.sh-md{gap:5px; margin-bottom:5px;} .sh-md button{padding:5px 3px; font-size:11.5px; border-radius:9px;}
.sh-in{gap:6px;} .sh-in label{font-size:11px;} .sh-in input,.sh-in select{margin-top:2px; padding:5px 8px; font-size:14px; border-radius:9px;}
.sh-sz{gap:4px; margin-top:5px;} .sh-sz button{padding:2px 8px; font-size:11px;}
.sh-ch{gap:4px; margin:5px 0;} .sh-ch label{padding:2px 8px; font-size:11px;}
.sh-res{padding:6px 9px; border-radius:10px; margin-top:4px;} .sh-res .big{font-size:16px;} .sh-res .why{font-size:11px; margin-top:2px;} .sh-res .up{font-size:11.5px; margin-top:4px; padding:3px 7px;}
.sh-nt{font-size:11px; padding:4px 8px; margin-top:4px; border-radius:9px;}
.sh-dt{margin-top:5px;} .sh-dt summary{cursor:pointer; font-size:11.5px; font-weight:800; color:#334155; padding:4px 2px; list-style:none;} .sh-dt summary::-webkit-details-marker{display:none;} .sh-dt summary::after{content:' ▾'; color:#64748b;} .sh-dt[open] summary::after{content:' ▴';}
.sh-calc .sh-ct{margin-top:4px !important;} .sh-calc .sh-ct th,.sh-calc .sh-ct td{padding:3px 7px !important; font-size:11.5px !important;}
/* 📸 story strip: 5 bubbles at the very top, tap one → the page scrolls straight to that section */
.sh-story{display:flex; gap:6px; justify-content:space-between; margin:0 0 8px; max-width:560px; overflow-x:auto; scrollbar-width:none;} .sh-story::-webkit-scrollbar{display:none;}
.sh-sb{flex:1 0 54px; text-align:center; background:none; border:0; padding:0; cursor:pointer; min-height:0; font-family:inherit; color:#334155;}
.sh-sb .ring{width:54px; height:54px; border-radius:50%; margin:0 auto 3px; padding:3px; background:conic-gradient(#f97316,#ec4899,#f59e0b,#f97316); position:relative; transition:transform .15s;}
.sh-sb:active .ring{transform:scale(.92);}
.sh-sb.done .ring{background:linear-gradient(135deg,#4ade80,#15803d);} .sh-sb.seen .ring{background:#cbd5e1;}
.sh-sb .in{width:100%; height:100%; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-size:22px; line-height:1; border:2px solid #fff;}
.sh-sb .bd{position:absolute; right:-5px; top:-4px; min-width:20px; height:20px; border-radius:10px; background:#111827; color:#fff; font-size:11px; font-weight:900; display:flex; align-items:center; justify-content:center; padding:0 5px; border:2px solid #fff; white-space:nowrap;}
.sh-sb.done .bd{background:#16a34a;}
.sh-sb .lb{display:block; font-size:11px; font-weight:800;}
.sh-flash{animation:shFlash 1.3s ease-out;} @keyframes shFlash{0%{box-shadow:0 0 0 0 rgba(249,115,22,.65);}100%{box-shadow:0 0 0 16px rgba(249,115,22,0);}}
#shNow,#shHero,#shCode,#chatCard,#rateCard,#shLead,#shOffer,#shPrice{scroll-margin-top:10px;}
.sh-now{position:relative; background:linear-gradient(160deg,#14532d,#15803d); color:#fff; border-radius:18px; padding:11px 10px 11px; margin-bottom:10px; overflow:hidden; box-shadow:0 6px 18px rgba(20,83,45,.25);}
.sh-now .nn{display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:4px; text-align:center;}
.sh-now .nn > div{background:rgba(255,255,255,.13); border-radius:10px; padding:6px 1px 5px; min-width:0;}
.sh-now .nn b{display:block; font-size:13.5px; line-height:1.15; font-weight:900; font-variant-numeric:tabular-nums; letter-spacing:-.02em; white-space:nowrap;}
.sh-now .nn small{display:block; margin-top:2px; font-size:11px; line-height:1.15; opacity:.88; font-weight:800; text-transform:uppercase; letter-spacing:.02em;}
.sh-now .nn .cm b{color:#bbf7d0;} .sh-now .nn .cv b{color:#fde68a;}
.sh-now .qg{margin-top:7px; background:rgba(255,255,255,.13); border-radius:10px; padding:6px 8px; text-align:center; font-size:12px; font-weight:800; line-height:1.35;} .sh-now .qg b{color:#fde68a;} .sh-now .qg.ok{background:rgba(187,247,208,.22);} .sh-now .qg.ok b{color:#bbf7d0;}
.sh-now hr{border:0; border-top:1px solid rgba(255,255,255,.2); margin:10px 0 0;}
.sh-now .tt{margin-top:10px; background:rgba(255,255,255,.1); border-radius:12px; padding:8px 10px;} .sh-now .tt .th{font-size:12.5px; font-weight:800;} .sh-now .tt .th b{font-size:16px;} .sh-now .tt .th span{font-weight:700; opacity:.85; font-size:11px;}
.sh-now .tt .pp{display:flex; gap:6px; flex-wrap:wrap; margin-top:5px;} .sh-now .tt .pp i{font-style:normal; font-size:11px; font-weight:800; background:rgba(255,255,255,.16); border-radius:99px; padding:2px 9px;}
.sh-now .tb{position:relative; height:16px; border-radius:9px; background:rgba(255,255,255,.22); margin-top:10px; overflow:hidden;} .sh-now .tb i{position:absolute; left:0; top:0; bottom:0; border-radius:9px; background:linear-gradient(90deg,#86efac,#fde047); transition:width .8s ease;}
.sh-now .tbl{display:flex; justify-content:space-between; gap:6px; font-size:11px; font-weight:800; margin-top:3px; font-variant-numeric:tabular-nums;} .sh-now .tbl span:nth-child(2){opacity:.95;} .sh-now .tbl span:first-child,.sh-now .tbl span:last-child{opacity:.85;}
.sh-now .lf{text-align:center; margin-top:8px;} .sh-now .lf > b{display:block; font-size:16px; font-weight:900; font-variant-numeric:tabular-nums;} .sh-now .lf span{font-size:11.5px; font-weight:700; opacity:.92;}
.sh-now .lk{display:grid; gap:5px; margin-top:8px;} .sh-now .lk div{background:rgba(0,0,0,.16); border-radius:10px; text-align:center; padding:6px 2px 5px; font-size:11.5px; font-weight:900; line-height:1.3;} .sh-now .lk small{display:block; font-size:11px; font-weight:800; opacity:.9;}
.sh-now .lk div.on{background:rgba(187,247,208,.28);} .sh-now .lk div.next{background:rgba(255,255,255,.2); box-shadow:0 0 0 1.5px #fde68a inset;}
.sh-now .tk{position:relative; height:26px; margin:8px 4px 0;} .sh-now .rail{position:absolute; left:0; right:0; top:15px; height:6px; border-radius:9px; background:rgba(255,255,255,.22);} .sh-now .fill{position:absolute; left:0; top:15px; height:6px; border-radius:9px; background:linear-gradient(90deg,#bbf7d0,#4ade80); transition:width .8s ease;}
.sh-now .dot{position:absolute; top:11px; width:14px; height:14px; border-radius:50%; background:#14532d; border:2px solid #86efac; transform:translateX(-50%);} .sh-now .dot.on{background:#4ade80; border-color:#fff;} .sh-now .dot.next{background:#fff; border-color:#fb923c;}
.sh-now .nb{position:absolute; top:-2px; transform:translateX(-50%); background:#fff; color:#14532d; font-size:11px; font-weight:900; border-radius:99px; padding:0 7px; line-height:15px; white-space:nowrap; z-index:2;}
.sh-now .st{display:grid; gap:2px; text-align:center; margin-top:6px;} .sh-now .st div{font-size:11px; font-weight:800; line-height:1.35; padding:3px 0; border-radius:9px; font-variant-numeric:tabular-nums;} .sh-now .st b{display:block; font-size:11px;} .sh-now .st small{display:block; opacity:.85; font-weight:700;}
.sh-now .st .cp{display:block; font-style:normal; font-size:11px; font-weight:900; letter-spacing:.04em; opacity:.85; text-transform:uppercase;} .sh-now .st div:first-child .cp{color:#fde68a; opacity:1;}
.sh-now .spd{display:grid; grid-auto-flow:column; grid-auto-columns:1fr; gap:6px; margin-top:8px;}
.sh-now .spd > div{background:rgba(255,255,255,.1); border-radius:12px; padding:7px 9px; min-width:0; border-left:4px solid rgba(255,255,255,.3);}
.sh-now .spd > div.g{border-left-color:#4ade80;} .sh-now .spd > div.a{border-left-color:#fbbf24;} .sh-now .spd > div.r{border-left-color:#f87171;}
.sh-now .spd b{display:block; font-size:17px; font-weight:900; line-height:1.1; font-variant-numeric:tabular-nums;} .sh-now .spd b i{font-style:normal; font-size:11px; opacity:.8; font-weight:800; margin-left:2px;}
.sh-now .spd > div.g b{color:#bbf7d0;} .sh-now .spd > div.a b{color:#fde68a;} .sh-now .spd > div.r b{color:#fecaca;}
.sh-now .spd span{display:block; font-size:11px; font-weight:900; letter-spacing:.05em; text-transform:uppercase; opacity:.85; margin-top:2px;}
.sh-now .spd small{display:block; font-size:11px; font-weight:700; opacity:.92; line-height:1.3; margin-top:1px;}
.sh-now .tb{overflow:visible;} .sh-now .tb s{position:absolute; top:-3px; bottom:-3px; width:2px; margin-left:-1px; background:#fff; border-radius:2px; box-shadow:0 0 0 1px rgba(0,0,0,.25); z-index:2;}
.sh-now .tcap{position:relative; height:13px; margin-top:2px;} .sh-now .tcap span{position:absolute; transform:translateX(-50%); font-size:11px; font-weight:800; white-space:nowrap; opacity:.95;}
.sh-now .st .on{color:#bbf7d0;} .sh-now .st .next{color:#fff; background:rgba(255,255,255,.16);} .sh-now .st div:not(.on):not(.next){opacity:.8;}
.sh-now .ms{margin-top:8px; font-size:12.5px; font-weight:700; text-align:center;} .sh-now .ms b{color:#bbf7d0;}
.sh-now .rl{margin-top:7px; font-size:11px; line-height:1.45; opacity:.82; text-align:center;} .sh-now .rl b{color:#dcfce7;}
@media (min-width:700px){.sh-now{padding:14px 14px 13px;} .sh-now .nn b{font-size:19px;} .sh-now .nn small{font-size:11px;} .sh-now .qg{font-size:13.5px;} .sh-now .lf b{font-size:19px;}}
.sh-rmk{border-radius:16px; padding:11px 13px; margin-bottom:10px; border:1.5px solid #c7d2fe; background:linear-gradient(180deg,#eef2ff,#fff); color:#312e81;} .sh-rmk h4{margin:0 0 6px; font-size:13.5px; font-weight:900; display:flex; align-items:center; gap:6px;} .sh-rmk h4 em{font-style:normal; background:#4f46e5; color:#fff; border-radius:99px; font-size:11px; padding:1px 8px;}
.sh-rmk .r{background:#fff; border:1px solid #e0e7ff; border-radius:12px; padding:8px 10px; margin-top:6px; font-size:13px; line-height:1.4; color:#1e1b4b;} .sh-rmk .r.new{border-color:#818cf8; box-shadow:0 0 0 2px #e0e7ff;} .sh-rmk .r small{display:block; margin-top:3px; font-size:11px; color:#6366f1; font-weight:700;} .sh-rmk .more{border:0; background:none; color:#4338ca; font-weight:800; font-size:12px; margin-top:6px; cursor:pointer; padding:4px 0; min-height:0;}
.sh-lost{border-radius:16px; padding:11px 13px; margin-bottom:10px; display:flex; align-items:center; gap:10px; border:1.5px solid #fdba74; background:linear-gradient(180deg,#fff7ed,#fff); color:#7c2d12;} .sh-lost.ok{border-color:#86efac; background:linear-gradient(180deg,#f0fdf4,#fff); color:#14532d;}
.sh-lost .ic{font-size:24px; line-height:1;} .sh-lost .tx{flex:1; min-width:0; font-size:12px; line-height:1.4;} .sh-lost .tx b{display:block; font-size:13.5px; font-weight:900;}
.sh-lost button{border:0; border-radius:99px; background:#ea580c; color:#fff; font-weight:900; font-size:12.5px; padding:9px 14px; cursor:pointer; white-space:nowrap; min-height:0;}
.sh-rem h3{margin:0 0 4px; font-size:16px;} .sh-rem .rb{background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:9px 11px; margin:8px 0; font-size:13.5px; line-height:1.5; color:#431407; white-space:pre-wrap; word-break:break-word;} .sh-rem .rb small{display:block; margin-top:4px; font-size:11px; color:#9a3412; font-weight:800;}
@media (prefers-reduced-motion:reduce){.sh-now .fill{transition:none;}}
/* 💥 BOOM — the full-screen celebration when the target (and each higher step) is reached */
.sh-boom{position:fixed; inset:0; z-index:3000; display:flex; align-items:center; justify-content:center; padding:18px; background:radial-gradient(circle at 50% 45%,rgba(20,83,45,.86),rgba(2,12,6,.94) 70%); animation:bmIn .35s ease-out both; overflow:hidden; cursor:pointer;}
.sh-boom.out{animation:bmOut .35s ease-in forwards;} @keyframes bmIn{from{opacity:0}to{opacity:1}} @keyframes bmOut{to{opacity:0; transform:scale(1.04)}}
.bm-rays{position:absolute; left:50%; top:46%; width:150vmax; height:150vmax; margin:-75vmax 0 0 -75vmax; background:repeating-conic-gradient(from 0deg,rgba(253,224,71,.26) 0 7deg,transparent 7deg 18deg); -webkit-mask-image:radial-gradient(circle,#000 0,transparent 55%); mask-image:radial-gradient(circle,#000 0,transparent 55%); animation:bmSpin 14s linear infinite, bmFade 1s ease-out both;}
@keyframes bmSpin{to{transform:rotate(360deg)}} @keyframes bmFade{from{opacity:0}to{opacity:1}}
.bm-ring{position:absolute; left:50%; top:46%; width:120px; height:120px; margin:-60px 0 0 -60px; border-radius:50%; border:6px solid rgba(253,224,71,.9); opacity:0; animation:bmRing 1.6s ease-out .1s both;} .bm-ring.r2{animation-delay:.35s; border-color:rgba(74,222,128,.9);} .bm-ring.r3{animation-delay:.6s; border-color:rgba(255,255,255,.85);}
@keyframes bmRing{0%{transform:scale(.2); opacity:.95}100%{transform:scale(7); opacity:0}}
.bm-card{position:relative; width:min(380px,100%); text-align:center; color:#fff; padding:26px 18px 20px; border-radius:26px; background:linear-gradient(160deg,#15803d,#14532d 70%); box-shadow:0 0 0 3px #fde047,0 0 60px 8px rgba(253,224,71,.55),0 24px 70px rgba(0,0,0,.55); animation:bmPop .75s cubic-bezier(.2,1.5,.35,1) .12s both;}
@keyframes bmPop{0%{transform:scale(.15) rotate(-8deg); opacity:0}60%{transform:scale(1.14) rotate(2deg); opacity:1}100%{transform:scale(1) rotate(0)}}
.bm-e{font-size:92px; line-height:1; filter:drop-shadow(0 6px 14px rgba(0,0,0,.4)); animation:bmBounce 1.1s ease-in-out .7s infinite;} @keyframes bmBounce{0%,100%{transform:translateY(0) rotate(-6deg) scale(1)}50%{transform:translateY(-12px) rotate(6deg) scale(1.1)}}
.bm-h{font-size:clamp(26px,8.4vw,34px); line-height:1.05; font-weight:1000; letter-spacing:.01em; margin:8px 0 4px; text-shadow:0 3px 0 rgba(0,0,0,.28); color:#fde047;} .bm-h .w{display:inline-block; white-space:nowrap; font-weight:inherit;} .bm-h span{display:inline-block; animation:bmLet .5s cubic-bezier(.2,1.6,.4,1) both;}
@keyframes bmLet{0%{transform:translateY(46px) scale(0); opacity:0}100%{transform:translateY(0) scale(1); opacity:1}}
.bm-s{font-size:15px; font-weight:800; opacity:.98; margin:2px 0 12px; animation:bmUp .5s ease-out 1s both;} @keyframes bmUp{from{transform:translateY(14px); opacity:0}to{transform:none; opacity:1}}
.bm-chips{display:grid; grid-template-columns:1fr 1fr; gap:8px; animation:bmUp .5s ease-out 1.15s both;} .bm-chips div{background:rgba(255,255,255,.14); border-radius:14px; padding:8px 6px;} .bm-chips small{display:block; font-size:11px; font-weight:800; opacity:.85; text-transform:uppercase; letter-spacing:.04em;} .bm-chips b{display:block; font-size:24px; font-weight:1000; color:#bbf7d0; font-variant-numeric:tabular-nums;}
.bm-n{margin-top:10px; font-size:12.5px; font-weight:700; opacity:.92; animation:bmUp .5s ease-out 1.3s both;} .bm-n b{color:#fde047;}
.bm-b{margin-top:14px; width:100%; min-height:0; border:0; border-radius:99px; padding:12px 16px; font-size:16px; font-weight:900; color:#14532d; background:linear-gradient(180deg,#fef08a,#facc15); box-shadow:0 5px 0 #ca8a04; cursor:pointer; animation:bmUp .5s ease-out 1.45s both, bmBtn 1.4s ease-in-out 2s infinite;} @keyframes bmBtn{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.bm-cv{position:absolute; inset:0; width:100%; height:100%; pointer-events:none;}
html.sh-shake body{animation:shShake .55s cubic-bezier(.36,.07,.19,.97) both;} @keyframes shShake{10%,90%{transform:translate3d(-2px,0,0)}20%,80%{transform:translate3d(5px,1px,0)}30%,50%,70%{transform:translate3d(-9px,-2px,0)}40%,60%{transform:translate3d(9px,2px,0)}}
@media (prefers-reduced-motion:reduce){.bm-rays,.bm-ring,.bm-e,.bm-b{animation:none !important;} .bm-rays,.bm-ring{display:none;} .bm-card,.bm-h span,.bm-s,.bm-chips,.bm-n,.bm-b{animation-duration:.01s !important; animation-delay:0s !important;}}
/* the big bottom Allocate button */
.sh-ab{position:fixed; left:0; right:0; bottom:0; z-index:60; padding:10px 14px calc(10px + env(safe-area-inset-bottom)); background:linear-gradient(180deg,rgba(255,255,255,0),rgba(255,255,255,.92) 40%); pointer-events:none;}
.sh-ab button{pointer-events:auto; width:100%; max-width:720px; margin:0 auto; display:flex; align-items:center; justify-content:center; gap:10px; border:0; border-radius:18px; padding:15px 18px; min-height:56px; font-size:16px; font-weight:900; color:#fff; background:linear-gradient(135deg,#f97316,#ea580c); box-shadow:0 12px 28px rgba(234,88,12,.4); cursor:pointer;}
.sh-ab button .pl{font-size:22px; line-height:1;} .sh-ab button .ct{background:#fff; color:#c2410c; border-radius:99px; padding:3px 11px; font-size:13px; font-weight:900;}
.sh-ab button.zero{background:linear-gradient(135deg,#1f2937,#111827); box-shadow:0 10px 24px rgba(17,24,39,.3);} .sh-ab button.zero .ct{background:rgba(255,255,255,.16); color:#fff;}
@media (prefers-reduced-motion:no-preference){ .sh-ab button.hasw{animation:shPulse 2.4s ease-in-out infinite;} }
@keyframes shPulse{0%,100%{box-shadow:0 12px 28px rgba(234,88,12,.4);}50%{box-shadow:0 12px 36px rgba(234,88,12,.7), 0 0 0 7px rgba(249,115,22,.18);}}
body.sh-pad .content{padding-bottom:96px;}
/* the allocation pop-up */
.sh-sheet{position:fixed; inset:0; z-index:1000; display:none;} .sh-sheet.on{display:block;}
.sh-sheet .bd{position:absolute; inset:0; background:rgba(15,23,42,.55); opacity:0; transition:opacity .25s;} .sh-sheet.on .bd{opacity:1;}
.sh-sheet .pn{position:absolute; left:0; right:0; bottom:0; height:94vh; height:94dvh; background:#fff; border-radius:22px 22px 0 0; box-shadow:0 -20px 50px rgba(0,0,0,.3); display:flex; flex-direction:column; overflow:hidden; transform:translateY(100%); transition:transform .32s cubic-bezier(.2,.8,.2,1);}
.sh-sheet.on .pn{transform:translateY(0);}
.sh-sheet .hd{display:flex; align-items:center; gap:10px; padding:10px 14px 8px; border-bottom:1px solid #eef0f4; flex:none;}
.sh-sheet .hd::before{content:""; position:absolute; left:50%; top:6px; width:38px; height:4px; margin-left:-19px; border-radius:3px; background:#d1d5db;}
.sh-sheet .hd b{font-size:15px; margin-top:6px;} .sh-sheet .hd small{color:#64748b; font-size:12px; margin-top:6px;}
.sh-sheet .hd button{margin-left:auto; margin-top:6px; width:auto; min-height:0; border:0; background:#f1f5f9; color:#0f172a; border-radius:99px; padding:7px 14px; font-weight:800; font-size:13px; cursor:pointer;}
.sh-sheet iframe{flex:1; width:100%; border:0; background:#fff;}
@media (min-width:900px){
  .sh-sheet .pn{left:50%; right:auto; bottom:auto; top:50%; width:min(1180px,94vw); height:88vh; border-radius:22px; transform:translate(-50%,-46%); opacity:0; transition:transform .28s cubic-bezier(.2,.8,.2,1), opacity .2s;}
  .sh-sheet.on .pn{transform:translate(-50%,-50%); opacity:1;}
  .sh-ab{left:auto; right:26px; bottom:22px; padding:0; background:none;}
  .sh-ab button{width:auto; min-width:250px; max-width:none;}
  body.sh-pad .content{padding-bottom:40px;}
}
body.sh-lock{overflow:hidden;} body.sh-lock #menuToggle{display:none !important;}
/* ting-ting toast */
.sh-toast{position:fixed; left:50%; top:14px; z-index:1100; width:min(440px,calc(100vw - 24px)); transform:translate(-50%,-140%); transition:transform .45s cubic-bezier(.2,1.2,.3,1); border-radius:16px; padding:11px 14px; display:flex; gap:11px; align-items:flex-start; color:#fff; background:linear-gradient(135deg,#f59e0b,#ea580c); box-shadow:0 16px 36px rgba(0,0,0,.28); font-size:13px; line-height:1.4; cursor:pointer;}
.sh-toast.on{transform:translate(-50%,0);} .sh-toast b{display:block; font-size:14px;} .sh-toast .e{font-size:26px; line-height:1; display:inline-block;}
.sh-toast.good{background:linear-gradient(135deg,#16a34a,#0f766e);}
@media (prefers-reduced-motion:no-preference){ .sh-toast.on .e{animation:shRing .9s ease-in-out 2;} .sh-hero.tone-ting .sh-emoji{animation:shRing 1.6s ease-in-out infinite; transform-origin:50% 8%;} .sh-hero.tone-go .sh-emoji,.sh-hero.tone-win .sh-emoji{animation:shBounce 1.8s ease-in-out infinite;} }
@keyframes shRing{0%,100%{transform:rotate(0);}15%{transform:rotate(18deg);}30%{transform:rotate(-16deg);}45%{transform:rotate(12deg);}60%{transform:rotate(-8deg);}75%{transform:rotate(4deg);}}
@keyframes shBounce{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-6px) scale(1.08);}}
.sh-cf{position:fixed; inset:0; z-index:1150; pointer-events:none;}
@media (max-width:520px){
  .sh-quests{grid-template-columns:1fr;}
  .sh-head{font-size:16px;} .sh-hero{padding:11px 12px 10px; border-radius:16px;}
}

.sh-mis{display:grid; gap:8px; margin-bottom:8px;}
.sh-meet{background:linear-gradient(135deg,#7f1d1d,#b91c1c); color:#fff; border-radius:18px; padding:14px 16px; box-shadow:0 6px 18px rgba(185,28,28,.25);}
.sh-meet b{display:block; font-size:15px; margin-bottom:3px;} .sh-meet p{margin:0; font-size:13px; line-height:1.45; opacity:.95;}
.sh-ting{background:#fff7ed; border:1px solid #fed7aa; border-radius:16px; padding:11px 14px; display:flex; gap:10px; align-items:flex-start; font-size:13px; line-height:1.45; color:#7c2d12;}
.sh-ting .e{font-size:20px; line-height:1;} .sh-ting b{display:block; color:#431407; font-size:13.5px;}
.sh-ting.good{background:#f0fdf4; border-color:#bbf7d0; color:#14532d;} .sh-ting.good b{color:#052e16;}
.sh-mgrid{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px;}
.sh-steps{display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;} .sh-steps span{font-size:11.5px; font-weight:800; padding:3px 10px; border-radius:99px; background:#f1f5f9; color:#64748b;} .sh-steps span.on{background:#dcfce7; color:#166534;} .sh-steps span.nx{background:#ffedd5; color:#9a3412;}
.sh-mixb{display:flex; height:12px; border-radius:7px; overflow:hidden; background:#e2e8f0; margin-top:8px;} .sh-mixb i{display:block; height:100%;}
.sh-mixl{display:flex; gap:10px; flex-wrap:wrap; font-size:11px; color:#475569; margin-top:5px;} .sh-mixl u{display:inline-block; width:9px; height:9px; border-radius:3px; margin-right:4px; text-decoration:none;}
.sh-idea{background:linear-gradient(135deg,#eef2ff,#faf5ff); border:1px solid #c7d2fe; border-radius:18px; padding:14px 16px;}
.sh-idea h3{margin:0 0 4px; font-size:15.5px; color:#312e81;} .sh-idea p{margin:0 0 6px; font-size:13px; line-height:1.5; color:#1e1b4b;} .sh-idea small{display:block; color:#4338ca; font-size:11.5px; margin-bottom:8px;}
.sh-idea .bt{display:flex; gap:8px; flex-wrap:wrap;} .sh-idea button, .sh-pop button{border:0; border-radius:99px; padding:8px 14px; font-weight:800; font-size:13px; cursor:pointer; min-height:0; width:auto;}
.sh-idea .a{background:#4f46e5; color:#fff;} .sh-idea .b{background:#fff; color:#312e81; border:1px solid #c7d2fe;}
.sh-out{display:flex; gap:12px; align-items:center;} .sh-out .big{font-size:30px; font-weight:900; line-height:1; color:#0f172a;} .sh-out .big small{font-size:13px; color:#64748b; font-weight:700;}
.sh-ob{display:flex; align-items:center; gap:8px; font-size:11.5px; color:#475569; margin-top:5px;} .sh-ob span{width:78px; flex:none;} .sh-ob div{flex:1; height:7px; background:#e2e8f0; border-radius:5px; overflow:hidden;} .sh-ob i{display:block; height:100%; border-radius:5px; background:linear-gradient(90deg,#fbbf24,#22c55e);}
.sh-popbd{position:fixed; inset:0; z-index:10040; background:rgba(15,23,42,.55); display:flex; align-items:center; justify-content:center; padding:16px;}
.sh-pop{background:#fff; border-radius:22px; max-width:440px; width:100%; padding:20px 18px 16px; box-shadow:0 24px 60px rgba(0,0,0,.35);}
.sh-pop h3{margin:0 0 4px; font-size:18px;} .sh-pop p{margin:4px 0 10px; font-size:13.5px; line-height:1.5; color:#334155;}
.sh-pop .row{display:flex; justify-content:space-between; align-items:center; gap:8px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:12px; margin-top:6px; font-size:13px;}
.sh-pop .row button{background:#16a34a; color:#fff; padding:6px 12px;}
.sh-pop .main{display:block; width:100%; background:#16a34a; color:#fff; margin-top:12px; padding:12px; border-radius:12px; font-size:15px;}
.sh-pop .later{display:block; width:100%; background:transparent; color:#64748b; margin-top:6px; border:1px solid #e2e8f0; border-radius:12px; padding:10px;}
.sh-cbig{display:flex; align-items:center; gap:10px; margin:4px 0 6px;} .sh-cbig b{font-size:34px; line-height:1; font-weight:900; color:#ea580c; min-width:44px; text-align:center; font-variant-numeric:tabular-nums;}
.sh-cbig span{font-size:14px; line-height:1.15; font-weight:900; color:#0f172a;} .sh-cbig span small{display:block; font-size:12px; font-weight:700; color:#64748b; margin-top:2px;}
.sh-q.near .sh-cbig b{color:#dc2626;} .sh-q.done .sh-cbig b{color:#16a34a;}
.sh-sr input{width:100%; border:1.5px solid #cbd5e1; border-radius:11px; padding:8px 11px; font-size:13px; background:#fff; margin-top:9px; min-height:0;}
.sh-sr input:focus{outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15);}
.sh-fc{display:flex; gap:5px; margin:8px 0 4px; overflow-x:auto; scrollbar-width:none;} .sh-fc::-webkit-scrollbar{display:none;}
.sh-fc span{flex:0 0 auto; font-size:11px; font-weight:800; border-radius:99px; padding:4px 11px; background:#f1f5f9; color:#475569; cursor:pointer;} .sh-fc span.on{background:#0f172a; color:#fff;}
.sh-slides{display:flex; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; margin:2px -2px 0;} .sh-slides::-webkit-scrollbar{display:none;}
.sh-sl{flex:0 0 100%; scroll-snap-align:center; position:relative; padding:0 2px; min-width:0;} .sh-sb{max-height:44vh; overflow-y:auto; border:1px solid #e2e8f0; border-radius:12px; background:#fff; -webkit-overflow-scrolling:touch;}
.sh-zm{position:absolute; right:10px; bottom:10px; z-index:3; width:28px; height:28px; min-height:0; border:0; border-radius:50%; background:rgba(15,23,42,.82); color:#fff; font-size:13px; cursor:pointer; padding:0;}
.sh-ct, .card .sh-ct.no-stack{display:table !important; min-width:0 !important; width:100%;} .sh-ct{border-collapse:collapse; table-layout:fixed; font-size:11px; font-variant-numeric:tabular-nums;} .sh-ct th{position:sticky; top:0; z-index:2; color:#fff; background:#334155; font-size:11px; font-weight:900; padding:5px 1px; text-align:center;}
.sh-ct th:first-child{text-align:left; padding-left:7px; width:23%;} .sh-ct td{text-align:center; padding:4px 1px; border-top:1px solid #f1f5f9; white-space:nowrap;} .sh-ct td:first-child{text-align:left; font-weight:800; padding-left:7px; white-space:normal; color:#0f172a;}
.sh-ct tbody tr:nth-child(even) td{background:#f8fafc;}
.sh-ct.big{font-size:13px;} .sh-ct.big th{font-size:12px; padding:8px 2px;} .sh-ct.big td{padding:8px 2px;}
.sh-dots{display:flex; justify-content:center; gap:6px; margin-top:7px;} .sh-dots i{width:7px; height:7px; border-radius:50%; background:#cbd5e1;} .sh-dots i.on{background:#0f172a; width:18px; border-radius:6px;}
.sh-zoom{position:fixed; inset:0; z-index:10050; background:rgba(15,23,42,.92); display:flex; flex-direction:column; padding:12px;} .sh-zoom .zb{display:flex; justify-content:space-between; align-items:center; color:#fff; font-size:14px; margin-bottom:8px;}
.sh-zoom .zx{width:34px; height:34px; border:0; border-radius:50%; background:#fff; color:#0f172a; font-size:15px; font-weight:900; min-height:0; padding:0; cursor:pointer;} .sh-zoom .zs{flex:1; overflow:auto; background:#fff; border-radius:14px; -webkit-overflow-scrolling:touch;}
.sh-lead{padding:9px 11px 7px; margin-bottom:8px; border-radius:14px;}
.sh-lt2{display:flex; gap:4px;} .sh-lt2 span{font-size:11px; font-weight:800; border-radius:99px; padding:3px 10px; background:#f1f5f9; color:#475569; cursor:pointer;} .sh-lt2 span.on{background:#0f172a; color:#fff;}
.sh-lr{display:grid; grid-template-columns:26px 1fr auto; gap:8px; align-items:center; padding:6px 6px; border-radius:11px; font-size:12.5px;} .sh-lr.me{background:#eef2ff; box-shadow:0 0 0 1.5px #a5b4fc inset;}
.sh-lr .rk{text-align:center; font-weight:900; color:#64748b;} .sh-lr .nm b{font-weight:800; color:#0f172a;} .sh-lr .nm em{font-style:normal; font-size:11px; font-weight:800; color:#b45309; background:#fef3c7; border-radius:99px; padding:1px 7px;}
.sh-lr .bar{height:5px; border-radius:4px; background:#e2e8f0; margin-top:3px; overflow:hidden;} .sh-lr .bar i{display:block; height:100%; background:linear-gradient(90deg,#4ade80,#16a34a);}
.sh-lr .cv{text-align:right;} .sh-lr .cv b{display:block; font-size:14px; font-weight:900; color:#0f172a;} .sh-lr .cv small{font-size:11px; color:#64748b;}
/* phones: everything on My Sales Home about 20% smaller (text, cards, notifications) */
@media (max-width:640px){
  #shNow, #shLost, #shRemarks, #shHero, #shQuests, #shMis > *, #shOffer, #shPrice, #shLead, #shStory, .sh-pop, .sh-ab button{zoom:.8;}
  .sh-toast{zoom:.8; width:min(440px,calc((100vw - 24px) / .8));}
  .sh-zoom .zb, .sh-zoom .zs{zoom:.9;}
}
@media (min-width:1100px){ .sh-quests{grid-template-columns:1fr 1fr;} }
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  const S = {
    coach: null, rating: null, punch: null, combo: null, canAlloc: false, waiting: null,
    open: false, dirty: false, frame: null, lastKey: '', lastNudge: 0, sound: store.get('sh_sound') !== '0', first: true, interacted: false,
  };
  window.addEventListener('pointerdown', () => { S.interacted = true; }, { once: true, passive: true });

  // ------------------------------------------------------------------ the moment: one cheer for right now
  // Never red, never "you failed": ahead = green, close = a 🎁 hook, behind = a friendly ⏰ "ting ting" with the exact next step.
  function mood(d) {
    const m = d.me || {}, G = d.goal || { mode: 'none' }, T = Number(m.target) || 0, sales = Number(m.sales) || 0, bonus = (T > 0 && sales >= T && Number(m.bonus_now) > 0) ? Number(m.bonus_now) : (Number(d.bonus) || 0);
    const tk = Number(m.ticket) > 0 ? Number(m.ticket) : (Number((d.team || {}).avg_ticket) > 0 ? Number(d.team.avg_ticket) : 1500);
    const left = T > 0 ? Math.max(0, T - sales) : 0, away = T > 0 && left > 0 ? Math.max(1, Math.ceil(left / tk)) : 0;
    const first = String(m.name || '').trim().split(/\s+/)[0] || '';
    const bn = bonus > 0 ? '+' + bonus + '% extra' : 'the target bonus';
    const o = { T, left, away, bonus, sales, tk, first, key: '' };
    if (T > 0 && sales >= T) return Object.assign(o, { tone: 'win', emoji: '🏆', head: 'Target smashed! Every order is now worth more', sub: bonus > 0 ? `<b>${bn}</b> on every order you add today 🎁 — keep stacking!` : 'Keep stacking — every order counts 💪', key: 'win' });
    if (T > 0 && away > 0 && away <= 3 && G.mode !== 'newgoal')
      return Object.assign(o, { tone: 'go', emoji: '🎯', head: `Only ${plural(away, 'order')} to go!`, sub: `Add <b>${rs(left)}</b> more and unlock <b>${bn}</b> on EVERY order today 🎁 — you're so close!`, key: 'go' + away });
    if (G.mode === 'newgoal') return Object.assign(o, { tone: 'go', emoji: '🚀', head: `New goal today: ${rs(G.goal)}`, sub: `You're at <b>${rs(sales)}</b> — just <b>${rs(G.left)}</b> more and today beats your normal day 👍`, key: 'ng' });
    if (G.mode === 'catchup' || G.mode === 'near') {
      const n = G.orders || 1;
      return Object.assign(o, { tone: 'ting', emoji: '⏰', head: `Ting ting! ~${plural(n, 'order')} puts you back on track`, sub: `${rs(G.behind)} to catch up${m.best_hour >= 2 ? ` — your best hour today was <b>${m.best_hour} orders</b>, repeat it` : ''}. You've got this 💪`, key: 'ting' + n });
    }
    if (G.mode === 'ahead' && (Number(m.orders) || 0) > 0) {
      const tm = d.team || {};
      return Object.assign(o, { tone: 'win', emoji: '🔥', head: "You're ahead of time!", sub: tm.people > 1 ? `Only <b>${tm.ahead} of ${tm.people}</b> are ahead right now — and you're one of them` : 'Keep this pace and the bonus is yours 🎁', key: 'ahead' });
    }
    if ((Number(m.orders) || 0) === 0) return Object.assign(o, { tone: 'start', emoji: '🚀', head: 'Fresh day, fresh ₹!', sub: T > 0 ? `Your first order is the start of <b>${rs(T)}</b> today. Let's go!` : 'Your first order is waiting — let\'s go!', key: 'start' });
    return Object.assign(o, { tone: 'start', emoji: '💥', head: `${plural(m.orders, 'order')} in — keep stacking!`, sub: T > 0 ? `<b>${rs(left)}</b> to the target${bonus > 0 ? ' and <b>' + bn + '</b> on every order' : ''}` : 'Every order adds to your commission.', key: 'n' + m.orders });
  }

  // ------------------------------------------------------------------ hero + missions
  function punchChip() {
    const p = S.punch;
    if (!p) return '';
    const t = (x) => x ? new Date(String(x).replace(' ', 'T')).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '';
    if (!p.punch_in) return '<a class="sh-chip warn" href="attendance.html">⏰ Not punched in · Punch in →</a>';
    if (p.punch_out) return '<a class="sh-chip" href="attendance.html">✓ Day done · ' + esc(t(p.punch_out)) + '</a>';
    return '<a class="sh-chip ok" href="attendance.html">🟢 In since ' + esc(t(p.punch_in)) + '</a>';
  }
  function countUp(el, to, fmt) {
    if (reduce || !el) { if (el) el.textContent = fmt(to); return; }
    const t0 = performance.now(), from = Number(el.dataset.v) || 0; el.dataset.v = to;
    const step = (tm) => { const k = Math.min(1, (tm - t0) / 800); el.textContent = fmt(from + (to - from) * k * (2 - k)); if (k < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  }

  // ------------------------------------------------------------------ 💚 "My day" card (above the rating): orders · ticket · sales · commission + the commission step track
  // Commission = the normal slab commission of every order  +  the extra % of that total once the sales reach a step
  // (target → +20%, then 30 / 40 / 60 / 100% over target). The track shows every step with what the day pays there.
  const kfmt = (v) => { v = Number(v) || 0; return v >= 1000 ? (v % 1000 === 0 ? v / 1000 : (v / 1000).toFixed(1).replace(/\.0$/, '')) + 'k' : String(Math.round(v)); };
  function paintNow() {
    const box = $('shNow'); if (!box) return;
    const d = S.coach; if (!d || !d.me) { box.style.display = 'none'; return; }
    const m = d.me, qi = d.qinfo || null, T = Number(m.target) || 0, sales = Number(m.sales) || 0;
    let L = Array.isArray(m.ladder) ? m.ladder.filter((x) => Number(x.sales) > 0) : [];
    if (!L.length && T > 0) L = [{ sales: T, bonus: Number(m.bonus) || 0, hit: sales >= T }];      // slab chart off: just the target
    const hasRs = L.length > 0 && L.every((x) => x.total != null);
    const q = qi ? Number(qi.queries) || 0 : 0, qo = qi ? Number(qi.query_orders) || 0 : (Number(m.orders) || 0), co = qi ? Number(qi.community_orders) || 0 : 0;
    const conv = qi && qi.conv != null ? qi.conv + '%' : (q > 0 ? Math.round(qo / q * 100) + '%' : '—');
    // 1) ONE line of 7 numbers: total queries · query orders · community orders · query→order % · ticket · total sales · total commission
    const nums = `<div class="nn">
      <div><b data-cu="${q}">${q}</b><small>Total queries</small></div>
      <div><b data-cu="${qo}">${qo}</b><small>Query orders${qi && q > 0 && Number(qi.goal_orders) > 0 ? ' · goal ' + Number(qi.goal_orders) : ''}</small></div>
      <div><b data-cu="${co}">${co}</b><small>Campaign orders</small></div>
      <div class="cv"><b>${conv}</b><small>Query → order</small></div>
      <div><b data-cu="${Math.round(Number(m.ticket) || 0)}" data-rs="1">${rs(m.ticket)}</b><small>Ticket</small></div>
      <div><b data-cu="${Math.round(sales)}" data-rs="1">${rs(sales)}</b><small>Total sales</small></div>
      <div class="cm"><b data-cu="${Math.round(Number(m.com_today) || 0)}" data-rs="1">${rs(m.com_today)}</b><small>Total commission</small></div></div>`;
    // 2) query goal line
    let qline = '';
    if (qi) {
      const gp = Number(qi.goal_pct) || 23, go = Number(qi.goal_orders) || 0, more = Number(qi.need_more) || 0;
      if (q <= 0) qline = `<div class="qg">No queries given yet — they appear here the moment they are assigned</div>`;
      else if (more <= 0) qline = `<div class="qg ok">✅ Query goal reached — <b>${qo}</b> orders from <b>${q}</b> queries (${gp}% goal)</div>`;
      else qline = `<div class="qg">Need <b>${go}</b> query orders from <b>${q}</b> queries (${gp}%) — <b>${more} more</b></div>`;
    }
    // ⚡ Reply speed (how fast you answer customers — score /10 + minutes to the first reply, from today's reviewed chats; it updates as soon as a chat you uploaded is reviewed) and 🏃 Pace (% of target done ÷ % you should have done by now)
    const rsv = qi && qi.rs != null ? Number(qi.rs) : null, rmv = qi && qi.rm != null ? Number(qi.rm) : null, rnv = qi ? Number(qi.rn) || 0 : 0, exA = qi && qi.exp_arr != null ? Number(qi.exp_arr) : null;
    const pace = (T > 0 && exA != null && exA >= 5 && ((qi && qi.arrive != null) || sales > 0)) ? (sales / T * 100) / exA : null;
    const rsC = rsv == null ? 'n' : rsv >= 7 ? 'g' : rsv >= 5 ? 'a' : 'r', pcC = pace == null ? 'n' : pace >= 0.95 ? 'g' : pace >= 0.7 ? 'a' : 'r';
    let proj = '';
    if (pace != null && T > 0 && sales >= T && exA >= 25) {
      const pj = sales / (exA / 100), top = L.filter((x) => pj >= x.sales && Number(x.bonus) > 0).pop();
      proj = `at this pace ≈ ${rs(Math.round(pj / 100) * 100)}${top ? ' → +' + Number(top.bonus) + '% step' : ''}`;
    }
    const spd = qi ? `<div class="spd"><div class="${rsC}"><b>⚡ ${rsv != null ? rsv.toFixed(1) + '<i>/10</i>' : '—'}</b><span>Reply speed</span><small>${rsv != null ? (rmv != null ? Math.round(rmv) + ' min to first reply · ' : '') + rnv + ' chat' + (rnv === 1 ? '' : 's') + ' today' : 'No WhatsApp replies yet today'}</small></div>${pace != null ? `<div class="${pcC}"><b>🏃 ${pace.toFixed(2)}×</b><span>Pace</span><small>${proj || (Math.round(sales / T * 100) + '% done · should be ' + Math.round(exA) + '%')}</small></div>` : ''}</div>` : '';
    // 3) ONE total (mixed) target
    let track = '';
    if (T > 0) {
      const P = m.target_parts || {}, parts = [['perfume', 'Perfume'], ['gel', 'Shower gel'], ['tm4u', 'TM4U']].filter((k) => Number(P[k[0]]) > 0);
      const chips = parts.length > 1 ? `<div class="pp">${parts.map((k) => `<i>${k[1]} ${rs(P[k[0]])}</i>`).join('')}</div>` : '';
      const done = sales >= T, first = L[0] || null, rest = L.slice(1);
      const head = `<div class="tt"><div class="th">🎯 Total target <b>${rs(T)}</b> <span>(one target, all products)</span></div>${chips}</div>`;
      if (!done) {
        // stage 1: the bar runs only to the target; the higher steps stay locked until it is reached
        const f = Math.max(0, Math.min(100, sales / T * 100)), unlock = first && Number(first.bonus) > 0 ? `commission <b>+${Number(first.bonus)}%</b> unlocks` : 'your target bonus unlocks';
        const lk = rest.length ? `<div class="lk" style="grid-template-columns:repeat(${rest.length},1fr)">${rest.map((x) => `<div>🔒 ${Number(x.bonus) > 0 ? '+' + Number(x.bonus) + '%' : ''}<small>${kfmt(x.sales)}</small></div>`).join('')}</div>` : '';
        const tx = pace != null ? Math.max(0, Math.min(100, exA)) : null, tcx = tx == null ? 0 : Math.max(14, Math.min(86, tx));
        track = `<hr>${head}${spd}<div class="tb"><i style="width:${f}%"></i>${tx != null ? `<s style="left:${tx}%"></s>` : ''}</div>${tx != null ? `<div class="tcap"><span style="left:${tcx}%">▲ should be here now</span></div>` : ''}<div class="tbl"><span>₹0</span><span>${rs(sales)} done</span><span>🎯 ${rs(T)}</span></div>
          <div class="lf"><b>${rs(Math.max(0, T - sales))} left for your target</b><span>Reach it → ${unlock} 🎁</span></div>${lk}`;
      } else {
        // stage 2: target done — the ladder opens (target + 20 / 30 / 40 / 60 / 100 %)
        const n = L.length, pos = (i) => (i + 0.5) / n * 100;
        let f;
        if (sales <= L[0].sales) f = sales / L[0].sales * pos(0);
        else if (sales >= L[n - 1].sales) f = pos(n - 1) + Math.min(1, (sales - L[n - 1].sales) / L[n - 1].sales) * (100 - pos(n - 1));
        else { f = 100; for (let i = 1; i < n; i++) if (sales <= L[i].sales) { f = pos(i - 1) + (sales - L[i - 1].sales) / (L[i].sales - L[i - 1].sales) * (pos(i) - pos(i - 1)); break; } }
        f = Math.max(0, Math.min(100, f));
        const nx = L.findIndex((x) => !x.hit);
        const dots = L.map((x, i) => `<i class="dot ${x.hit ? 'on' : i === nx ? 'next' : ''}" style="left:${pos(i)}%"></i>`).join('');
        const labs = L.map((x, i) => `<div class="${x.hit ? 'on' : i === nx ? 'next' : ''}"><em class="cp">${i === 0 ? '🎯 TARGET' : '+' + Math.round((x.sales / T - 1) * 100) + '% over'}</em><b>${x.hit ? '✅' : i === nx ? '👉' : '🔒'} ${kfmt(x.sales)}</b>${hasRs ? rs(x.total) : (Number(x.bonus) > 0 ? '+' + Number(x.bonus) + '%' : 'Target')}${hasRs && Number(x.bonus) > 0 ? `<small>+${Number(x.bonus)}%</small>` : ''}</div>`).join('');
        let msg;
        if (nx < 0) msg = '🏆 <b>Every step unlocked</b> — you are at the top level. Keep stacking!';
        else { const x = L[nx], more = Math.max(0, x.sales - sales); msg = `🏆 Target done! Only <b>${rs(more)}</b> more → ${hasRs ? `commission jumps to <b>${rs(x.total)}</b>` : `<b>+${Number(x.bonus)}% extra</b> on every order`} 🎁`; }
        let rule = '';
        if (L.some((x) => Number(x.bonus) > 0)) {
          const eg = hasRs ? (L[nx >= 0 ? nx : n - 1]) : null, rate = Number(m.avg_pct) || 0;
          rule = `<div class="rl">How it's counted: your normal commission on every order <b>+ ${L.filter((x) => Number(x.bonus) > 0).map((x) => Number(x.bonus) + '% at ' + kfmt(x.sales)).join(' · + ')}</b> of it.${eg && rate > 0 ? ` e.g. ${rs(eg.sales)} × ${rate}% = ${rs(eg.base)} → +${Number(eg.bonus)}% = <b>${rs(eg.total)}</b>` : ''}</div>`;
        }
        track = `<hr>${head}${spd}<div class="tk"><span class="nb" style="left:${Math.max(7, Math.min(93, f))}%">${rs(sales)}</span><div class="rail"></div><div class="fill" style="width:${f}%"></div>${dots}</div><div class="st" style="grid-template-columns:repeat(${n},1fr)">${labs}</div><div class="ms">${msg}</div>${rule}`;
      }
    }
    box.className = 'sh-now'; box.style.display = '';
    box.innerHTML = nums + qline + (T > 0 ? '' : spd) + track;
    box.querySelectorAll('[data-cu]').forEach((el) => countUp(el, Number(el.dataset.cu), el.dataset.rs ? rs : (v) => String(Math.round(v))));
    paintLost(qi);
  }

  // ------------------------------------------------------------------ 📎 lost chats: queries that did NOT become an order need a chat screenshot + a call recording (Rudra follows up)
  function paintLost(qi) {
    let box = $('shLost');
    if (!qi || !(Number(qi.lost_total) > 0)) { if (box) box.style.display = 'none'; return; }
    if (!box) { box = document.createElement('section'); box.id = 'shLost'; const now = $('shNow'); if (!now || !now.parentNode) return; now.parentNode.insertBefore(box, now.nextSibling); }
    const ns = Number(qi.lost_noshot) || 0, nc = Number(qi.lost_nocall) || 0, tot = Number(qi.lost_total) || 0;
    if (ns + nc === 0) { box.className = 'sh-lost ok'; box.innerHTML = `<span class="ic">✅</span><div class="tx"><b>All lost chats uploaded</b>${tot} queries without an order — screenshots and recordings are in. Thank you!</div>`; box.style.display = ''; return; }
    box.className = 'sh-lost';
    box.innerHTML = `<span class="ic">📎</span><div class="tx"><b>Upload your lost chats</b>${tot} queries ended without an order · <b style="display:inline">${ns}</b> need a chat screenshot · <b style="display:inline">${nc}</b> need a call recording</div><button type="button" id="shLostGo">Upload</button>`;
    box.style.display = '';
    const go = $('shLostGo'); if (go) go.onclick = () => { if (window.Convo) Convo.open({ kind: 'lost', onChange: () => { loadMissions(); if (typeof window.SalesHome.onRefresh === 'function') window.SalesHome.onRefresh(); } }); };
  }

  // ------------------------------------------------------------------ 💬 remark from the sales head: pops up until "Got it"
  function remarkPopup(list) {
    if (!list || !list.length || document.querySelector('.sh-rem')) return;
    const bd = document.createElement('div'); bd.className = 'sh-popbd';
    const ids = list.map((r) => r.id);
    bd.innerHTML = `<div class="sh-pop sh-rem" role="dialog" aria-modal="true"><h3>💬 ${list.length > 1 ? 'Remarks' : 'Remark'} from ${esc(list[0].from || 'your sales head')}</h3>${list.map((r) => `<div class="rb">${esc(r.body)}<small>${esc(r.from || '')}${r.at ? ' · ' + esc(r.at) : ''}</small></div>`).join('')}<button type="button" class="main" id="shRemOk">Got it 👍</button></div>`;
    document.body.appendChild(bd);
    $('shRemOk').onclick = () => { bd.remove(); api.post('ct.remark_seen', { ids }).then(loadRemarkList).catch(() => {}); if (S.ct) S.ct.remarks = []; RMK.list.forEach((r) => { if (ids.indexOf(r.id) > -1) r.seen = true; }); paintRemarks(); };
  }
  // ------------------------------------------------------------------ 💬 My remarks: every remark from the sales head stays here (14 days), even after "Got it"
  let RMK = { list: [], all: false };
  async function loadRemarkList() {
    try { const r = await api.get('ct.remark_all'); RMK.list = (r && r.remarks) || []; } catch (e) { return; }
    paintRemarks();
  }
  function paintRemarks() {
    let box = $('shRemarks');
    if (!RMK.list.length) { if (box) box.style.display = 'none'; return; }
    if (!box) { box = document.createElement('section'); box.id = 'shRemarks'; const a = $('shLost') || $('shNow'); if (!a || !a.parentNode) return; a.parentNode.insertBefore(box, a.nextSibling); }
    const fmt = (t) => { const d = String(t || ''); return d.slice(8, 10) + '/' + d.slice(5, 7) + ' ' + d.slice(11, 16); };
    const show = RMK.all ? RMK.list : RMK.list.slice(0, 3), nw = RMK.list.filter((r) => !r.seen).length;
    box.className = 'sh-rmk'; box.style.display = '';
    box.innerHTML = `<h4>💬 My remarks ${nw ? '<em>' + nw + ' new</em>' : ''}</h4>` + show.map((r) => `<div class="r${r.seen ? '' : ' new'}">${esc(r.body)}<small>${esc(r.from || 'Sales head')} · ${esc(fmt(r.at))}${r.seen ? '' : ' · tap Got it'}</small></div>`).join('') +
      (RMK.list.length > 3 ? `<button type="button" class="more" id="shRmkMore">${RMK.all ? 'Show less' : 'Show all (' + RMK.list.length + ')'}</button>` : '');
    const mo = $('shRmkMore'); if (mo) mo.onclick = () => { RMK.all = !RMK.all; paintRemarks(); };
  }
  async function checkRemarks() {
    if (document.hidden || document.querySelector('.sh-popbd')) return;
    try { const r = await api.get('ct.remark_mine'); if (r && r.remarks && r.remarks.length) { chime(); remarkPopup(r.remarks); loadRemarkList(); } } catch (e) { /* offline */ }
  }

  function paint() {
    const d = S.coach; if (!d || !d.me) return;
    const m = d.me, md = mood(d);
    const hero = $('shHero');
    const sound = '<button type="button" class="sh-snd" id="shSnd" title="Ting sound on / off" aria-label="Ting sound">' + (S.sound ? '🔔' : '🔕') + '</button>';
    const should = m.should_pct != null ? Math.min(100, Number(m.should_pct)) : null;
    const pct = md.T > 0 ? Math.min(100, md.sales / md.T * 100) : 0;
    hero.className = 'sh-hero tone-' + md.tone; hero.style.display = '';
    hero.innerHTML = `
      <div class="sh-top"><div class="sh-hi">Hi ${esc(md.first || 'there')} 👋</div><div class="sh-row">${punchChip()}${sound}</div></div>
      <div class="sh-emoji">${md.emoji}</div>
      <h2 class="sh-head">${esc(md.head)}</h2>
      <p class="sh-sub">${md.sub}</p>
      ${md.T > 0 ? `<div class="sh-bar"><i style="width:${pct}%"></i>${should != null && md.tone !== 'win' ? `<u style="left:${should}%" title="Where you should be by now"></u>` : ''}${md.bonus > 0 ? `<s>🎁 +${md.bonus}%</s>` : ''}</div>
      <div class="sh-bl"><span><b>${rs(md.sales)}</b> of ${rs(md.T)}</span>${should != null && md.tone !== 'win' ? `<span>pace marker ▏= where you should be now</span>` : '<span></span>'}</div>` : ''}`;
    paintNow();
    const sb = $('shSnd'); if (sb) sb.onclick = () => { S.sound = !S.sound; store.set('sh_sound', S.sound ? '1' : '0'); sb.textContent = S.sound ? '🔔' : '🔕'; if (S.sound) chime(); };

    // missions
    const q = [];
    if (md.T > 0) {
      const done = md.sales >= md.T, near = !done && md.away > 0 && md.away <= 3;
      q.push(`<div class="sh-q ${done ? 'done' : near ? 'near' : ''}"><div class="sh-qh"><span>🎯</span>Daily target<em>${done ? 'DONE ✅' : Math.round(pct) + '%'}</em></div>
        <div class="sh-qbig">${done ? 'Target done — bonus unlocked!' : plural(md.away, 'order') + ' to go'}</div>
        <div class="sh-qbar"><i style="width:${pct}%"></i></div>
        <div class="sh-qs">${rs(md.sales)} of <b>${rs(md.T)}</b>${done ? '' : ' · ' + rs(md.left) + ' left'}</div></div>`);
    }
    const pctC = Number(m.code_pct) || 0;
    if (pctC > 0) {
      const n = Number(m.orders) || 0, need = Number(m.code_need) || 0, have = Number(m.code) || 0, left = Number(m.code_left) || 0, ok = !!m.code_ok;
      const segs = need > 0 && need <= 16 ? `<div class="sh-segs">${Array.from({ length: need }, (_, i) => `<i class="${i < have ? 'on' : ''}"></i>`).join('')}</div>` : (need > 0 ? `<div class="sh-qbar"><i style="width:${Math.min(100, have / need * 100)}%"></i></div>` : `<div class="sh-segs"><i></i><i></i><i></i><i></i></div>`);
      const big = n === 0 ? 'Put a code on most orders' : ok ? 'Full commission on ALL orders!' : (left === 1 ? 'Just 1 code away!' : `Just ${left} codes away!`);
      const note = n === 0 ? `Add a code on <b>${Math.round(pctC)}%</b> of today's orders — then <b>every</b> order pays full, even the ones without a code.`
        : ok ? `<b>${have}</b> of ${plural(n, 'order')} have a code — the code goal is done 🎉`
        : `<b>${have}</b> of <b>${need}</b> codes done (${Math.round(pctC)}% of ${plural(n, 'order')}). Add a code to any <b>${plural(left, 'order')}</b>.`;
      const warn = !ok && n > 0 && left > 0 ? `<div class="sh-qw">${Number(m.code_lost) > 0 ? `💰 About <b>${rs(m.code_lost)}</b> more commission is yours once you get there` : '💰 Then every order pays full commission'}${Number(m.nocode) > 0 ? ` · ⚠ ${plural(m.nocode, 'order')} without a code` : ''}</div>` : (ok ? '<div class="sh-qw">✅ Nothing more to do here — new orders still count with or without a code today</div>' : '');
      q.push(`<div id="shCode" class="sh-q ${ok ? 'done' : left > 0 && left <= 2 && n > 0 ? 'near' : ''}"><div class="sh-qh"><span>🔑</span>Code goal<em>${n === 0 ? Math.round(pctC) + '% rule' : ok ? 'DONE ✅' : have + ' / ' + need}</em></div>
        ${n > 0 && !ok ? `<div class="sh-cbig"><b>${left}</b><span>${left === 1 ? 'code' : 'codes'} left<small>${have} of ${need} codes done</small></span></div>` : `<div class="sh-qbig">${big}</div>`}${segs}<div class="sh-qs">${note}</div>${warn}</div>`);
    }
    $('shQuests').innerHTML = q.join('');
    $('shQuests').style.display = q.length ? '' : 'none';

    // celebrate / nudge when the moment changes (once per change)
    const code = pctC > 0 ? (m.code_ok ? 'ok' : 'l' + (m.code_left || 0)) : '';
    paintStory();
    checkBoom(md, m);
    const key = md.key + '|' + code;
    if (key !== S.lastKey) {
      const wasFirst = S.first; S.first = false;
      const prevKey = S.lastKey; S.lastKey = key;
      moment(md, m, wasFirst, prevKey);
    }
  }

  // ------------------------------------------------------------------ ting ting + confetti
  let AC = null;
  function chime() {
    if (!S.sound || !S.interacted) return;       // browsers only allow sound after a tap on the page
    try {
      AC = AC || new (window.AudioContext || window.webkitAudioContext)();
      [[880, 0], [1320, .16]].forEach(([f, t]) => {
        const o = AC.createOscillator(), g = AC.createGain(); o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(AC.destination);
        const t0 = AC.currentTime + t; g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.18, t0 + .02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + .5);
        o.start(t0); o.stop(t0 + .55);
      });
    } catch (e) { /* sound is a bonus */ }
  }
  function toast(emoji, head, text, good) {
    const old = document.querySelector('.sh-toast'); if (old) old.remove();
    const t = document.createElement('div'); t.className = 'sh-toast' + (good ? ' good' : '');
    t.innerHTML = `<span class="e">${emoji}</span><div><b>${esc(head)}</b>${esc(text)}</div>`;
    document.body.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('on')));
    const gone = () => { t.classList.remove('on'); setTimeout(() => t.remove(), 500); };
    t.onclick = gone; setTimeout(gone, 7000);
  }
  function confetti() {
    if (reduce) return;
    const cv = document.createElement('canvas'); cv.className = 'sh-cf'; document.body.appendChild(cv);
    const dpr = window.devicePixelRatio || 1, W = cv.width = innerWidth * dpr, H = cv.height = innerHeight * dpr, ctx = cv.getContext('2d');
    const COL = ['#f97316', '#22c55e', '#3b82f6', '#ec4899', '#facc15', '#a855f7'];
    const P = Array.from({ length: 110 }, () => ({ x: Math.random() * W, y: -20 * dpr - Math.random() * H * .3, vx: (Math.random() - .5) * 4 * dpr, vy: (2 + Math.random() * 4) * dpr, s: (5 + Math.random() * 6) * dpr, r: Math.random() * 6, vr: (Math.random() - .5) * .3, c: COL[Math.floor(Math.random() * COL.length)] }));
    let f = 0;
    (function loop() {
      ctx.clearRect(0, 0, W, H); f++;
      P.forEach((p) => { p.x += p.vx; p.y += p.vy; p.vy += .05 * dpr; p.r += p.vr; ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r); ctx.fillStyle = p.c; ctx.globalAlpha = Math.max(0, 1 - f / 170); ctx.fillRect(-p.s / 2, -p.s / 3, p.s, p.s * .66); ctx.restore(); });
      if (f < 170) requestAnimationFrame(loop); else cv.remove();
    })();
  }
  // ------------------------------------------------------------------ 💥 BOOM: the big celebration (target + every higher step)
  const BOOM = [['🏆', 'TARGET SMASHED!'], ['🔥', 'LEVEL UP!'], ['⚡', 'UNSTOPPABLE!'], ['👑', 'LEGEND MODE!'], ['🚀', 'ABSOLUTE BEAST!']];
  function fanfare(level) {
    if (!S.sound || !S.interacted) return;
    try {
      AC = AC || new (window.AudioContext || window.webkitAudioContext)();
      const seq = [[523, 0], [659, .11], [784, .22], [1047, .36], [1319, .5]].slice(0, 3 + Math.min(2, level));
      seq.forEach(([f, t]) => { const o = AC.createOscillator(), g = AC.createGain(); o.type = 'triangle'; o.frequency.value = f; o.connect(g); g.connect(AC.destination);
        const t0 = AC.currentTime + t; g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.22, t0 + .03); g.gain.exponentialRampToValueAtTime(0.0001, t0 + .55); o.start(t0); o.stop(t0 + .6); });
    } catch (e) { /* sound is a bonus */ }
  }
  function cannons(cv, level) {
    const dpr = window.devicePixelRatio || 1, W = cv.width = innerWidth * dpr, H = cv.height = innerHeight * dpr, ctx = cv.getContext('2d');
    const COL = ['#f97316', '#22c55e', '#3b82f6', '#ec4899', '#facc15', '#a855f7', '#ffffff'], EM = ['💰', '🎉', '✨', '🪙', '🔥', '💸'];
    const per = 70 + level * 35, P = [];
    const shoot = (fromLeft, delay) => { for (let i = 0; i < per; i++) {
      const a = (fromLeft ? -62 : -118) * Math.PI / 180 + (Math.random() - .5) * .9, sp = (13 + Math.random() * 13) * dpr, k = Math.random();
      P.push({ x: fromLeft ? 0 : W, y: H, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, s: (6 + Math.random() * 8) * dpr, r: Math.random() * 6, vr: (Math.random() - .5) * .45, c: COL[(Math.random() * COL.length) | 0], t: k < .12 ? 'e' : k < .3 ? 'o' : 'r', e: EM[(Math.random() * EM.length) | 0], d: delay + Math.random() * 6 });
    } };
    shoot(true, 0); shoot(false, 0); setTimeout(() => { shoot(true, 0); shoot(false, 0); }, 700); if (level > 0) setTimeout(() => { shoot(true, 0); shoot(false, 0); }, 1500);
    let f = 0;
    (function loop() {
      if (!cv.isConnected) return;
      ctx.clearRect(0, 0, W, H); f++;
      P.forEach((p) => {
        if (p.d > 0) { p.d--; return; }
        p.x += p.vx; p.y += p.vy; p.vy += .42 * dpr; p.vx *= .992; p.r += p.vr;
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r); ctx.globalAlpha = Math.max(0, Math.min(1, 1 - (f - 160) / 150)); ctx.fillStyle = p.c;
        if (p.t === 'e') { ctx.font = (p.s * 2.4) + 'px serif'; ctx.textAlign = 'center'; ctx.fillText(p.e, 0, 0); }
        else if (p.t === 'o') { ctx.beginPath(); ctx.fillStyle = '#facc15'; ctx.arc(0, 0, p.s / 2, 0, 6.283); ctx.fill(); ctx.strokeStyle = '#ca8a04'; ctx.lineWidth = 2 * dpr; ctx.stroke(); }
        else ctx.fillRect(-p.s / 2, -p.s / 3, p.s, p.s * .66);
        ctx.restore();
      });
      if (f < 330) requestAnimationFrame(loop);
    })();
  }
  // level = 0 target, 1.. = each higher step. info: {bonus, total, com, next:{sales,total,bonus}|null}
  function boom(level, info) {
    if (document.querySelector('.sh-boom')) return;
    const [emo, head] = BOOM[Math.min(level, BOOM.length - 1)];
    let li = 0;
    const letters = head.split(' ').map((w) => '<b class="w">' + w.split('').map((c) => `<span style="animation-delay:${(0.45 + (li++) * 0.035).toFixed(2)}s">${esc(c)}</span>`).join('') + '</b>').join(' ');
    const o = document.createElement('div'); o.className = 'sh-boom'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-label', 'Congratulations');
    const nx = info.next ? `Next: <b>${rs(info.next.sales)}</b> sales → ${info.next.total != null ? 'commission <b>' + rs(info.next.total) + '</b>' : '<b>+' + Number(info.next.bonus) + '%</b>'} 🎁` : '🏆 You reached the TOP level — nothing can stop you!';
    o.innerHTML = `${reduce ? '' : '<div class="bm-rays"></div><i class="bm-ring"></i><i class="bm-ring r2"></i><i class="bm-ring r3"></i><canvas class="bm-cv"></canvas>'}
      <div class="bm-card"><div class="bm-e">${emo}</div><div class="bm-h">${letters}</div>
        <div class="bm-s">${Number(info.bonus) > 0 ? `+${Number(info.bonus)}% extra on EVERY order today 🎁` : 'Amazing work today!'}</div>
        <div class="bm-chips"><div><small>Commission now</small><b id="bmCom" data-cu="${Math.round(info.com)}">${reduce ? rs(info.com) : '₹0'}</b></div><div><small>Extra unlocked</small><b>${Number(info.bonus) > 0 ? '+' + Number(info.bonus) + '%' : '✓'}</b></div></div>
        <div class="bm-n">${nx}</div><button type="button" class="bm-b">Keep going 🚀</button></div>`;
    document.body.appendChild(o);
    let closed = false;
    const close = () => { if (closed) return; closed = true; o.classList.add('out'); setTimeout(() => o.remove(), 380); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape' || e.key === 'Enter') close(); };
    o.addEventListener('click', close); document.addEventListener('keydown', onKey); setTimeout(close, 11000);
    if (!reduce) {
      const cv = o.querySelector('.bm-cv'); if (cv) cannons(cv, level);
      document.documentElement.classList.add('sh-shake'); setTimeout(() => document.documentElement.classList.remove('sh-shake'), 650);
      setTimeout(() => countUp($('bmCom'), Math.round(info.com), rs), 900);
    }
    try { if (navigator.vibrate) navigator.vibrate([90, 50, 90, 50, 220]); } catch (e) { /* phones only */ }
    fanfare(level);
  }
  // called on every paint: the highest step reached that has not been celebrated on this phone today
  function checkBoom(md, m) {
    const day = (S.coach && S.coach.business_day) || ''; if (!day || !(md.T > 0)) return;
    let L = Array.isArray(m.ladder) ? m.ladder.filter((x) => Number(x.sales) > 0) : [];
    if (!L.length) L = [{ sales: md.T, bonus: Number(md.bonus) || 0, hit: md.sales >= md.T }];
    let hi = -1; L.forEach((x, i) => { if (x.hit) hi = i; });
    if (hi < 0) return;
    const kk = (i) => 'sh_c_' + day + '_' + (i === 0 ? 'target' : 'step' + i);
    if (store.get(kk(hi))) return;
    for (let i = 0; i <= hi; i++) store.set(kk(i), '1');          // lower steps are celebrated by this one
    const x = L[hi], nx = L[hi + 1] || null;
    const info = { bonus: x.bonus, total: x.total, com: Number(m.com_today) || 0, next: nx };
    // the app's opening splash plays first (about 3 s) — wait for it, so the BOOM is seen from its very first frame
    let tries = 0;
    (function go() { if ((window.__hrmsSplashActive || document.getElementById('hrmsSplash')) && tries++ < 60) return setTimeout(go, 150); boom(hi, info); })();
  }

  function moment(md, m, wasFirst, prevKey) {
    const day = (S.coach && S.coach.business_day) || '';
    const now = Date.now();
    const celebrated = (k) => { const kk = 'sh_c_' + day + '_' + k; if (store.get(kk)) return true; store.set(kk, '1'); return false; };
    if (md.tone === 'win' && md.key === 'win') return;          // the target celebration is the BOOM card (checkBoom)
    if (m.code_ok && Number(m.orders) > 0 && !celebrated('code')) { confetti(); toast('🔑', 'Code goal done!', 'Full commission on ALL your orders today 🎉', true); chime(); return; }
    if (now - S.lastNudge < 20 * 60 * 1000 && !wasFirst) return;          // a nudge is a nudge, not a nag: at most once every 20 min
    if (md.tone === 'ting') { S.lastNudge = now; setTimeout(() => { toast('⏰', 'Ting ting!', md.head.replace(/^Ting ting!\s*/, '') + ' — you can do it!'); chime(); }, wasFirst ? 1400 : 200); return; }
    if (md.tone === 'go') { S.lastNudge = now; setTimeout(() => { toast('🎁', md.head, `Finish the target and every order today earns ${md.bonus > 0 ? '+' + md.bonus + '% extra' : 'a bonus'}!`, true); chime(); }, wasFirst ? 1400 : 200); return; }
    const cl = Number(m.code_left) || 0;
    if (!m.code_ok && cl > 0 && cl <= 2 && Number(m.orders) > 0) { S.lastNudge = now; setTimeout(() => { toast('🔑', cl === 1 ? 'Just 1 code away!' : 'Just ' + cl + ' codes away!', 'Add a code to ' + (cl === 1 ? 'any 1 order' : 'any ' + cl + ' orders') + ' → FULL commission on all orders', true); chime(); }, wasFirst ? 1400 : 200); }
  }

  // ------------------------------------------------------------------ 💲 my price chart
  const TIERC = ['#15803d', '#16a34a', '#65a30d', '#d97706', '#b45309', '#9a3412'];
  // 💲 Price charts as two "photos" you swipe one by one: 1 = ML size prices (10…1000 ml), 2 = shower gel / attar / perfume combo prices.
  // Shrunk to fit the phone (no sideways scrolling); the search box filters both; tap 🔍 to open one big.
  const PQ = { q: '', slide: 0 };
  const plabel = (r) => r.name.replace(/shower gel/g, 'gel').replace(/\bgel\b/g, 'shower gel').replace(/\bor attar\b/, '/ attar').replace(/\bshower gel or\b/, 'shower gel /');
  const nfmt = (v) => Math.round(Number(v) || 0).toLocaleString('en-IN');
  function chartRows(kind) {
    const qs = PQ.q.toLowerCase().split(/[\s,+]+/).filter(Boolean);
    if (kind === 'ml') {
      const c = S.mlChart; if (!c || !c.rows) return null;
      const L = qs.length ? c.rows.filter((r) => qs.every((t) => /^\d+$/.test(t) ? String(r.ml).indexOf(t) === 0 : (t === 'ml' || t === 'perfume' || t === 'size'))) : c.rows;
      return { head: ['ML'].concat((c.cols || []).map((x) => x + '%')), rows: L.map((r) => [r.ml + ' ml'].concat(r.p.slice(0, (c.cols || []).length).map(nfmt))), n: (c.cols || []).length };
    }
    const c = S.combo; if (!c || !c.rows) return null;
    const L = qs.length ? c.rows.filter((r) => {
      const hay = (plabel(r) + ' ' + (r.group || '') + (r.gel ? ' gel shower' : '') + (r.attar ? ' attar' : '') + (r.perf ? ' perfume' : '') + ' combo').toLowerCase();
      return qs.every((t) => hay.indexOf(t) >= 0 || (/^\d+$/.test(t) && (String(r.gel) === t || String(r.attar) === t || String(r.perf) === t)));
    }) : c.rows;
    return { head: ['Combo', '7%', '6.5%', '6%', '5.5%', '2.8%'], rows: L.map((r) => [plabel(r)].concat(r.p.map(nfmt))), n: 5 };
  }
  function chartHtml(kind, big) {
    const d = chartRows(kind); if (!d) return '<div class="sh-fn" style="padding:14px 4px">Chart not available.</div>';
    if (!d.rows.length) return '<div class="sh-fn" style="padding:14px 4px;text-align:center">Nothing like that here — try the other photo.</div>';
    return `<table class="sh-ct no-stack ${big ? 'big' : ''}"><thead><tr>${d.head.map((h, i) => `<th ${i ? `style="background:${TIERC[Math.min(i - 1, 5)]}"` : ''}>${esc(h)}</th>`).join('')}</tr></thead><tbody>${d.rows.map((r) => `<tr>${r.map((x, i) => `<td>${esc(x)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  }
  const PH = [['📷 1 · ML sizes', 'ml', 'Price for each size — sell at or above → earn that %'], ['📷 2 · Shower gel & attar', 'combo', '1 / 2 / 3 shower gel, attar, perfume combos']];
  function paintPriceList() {
    const box = $('shPrice'); if (!box || !box.dataset.init) return;
    PH.forEach((p, i) => { const el = $('shSl' + i); if (el) el.querySelector('.sh-sb').innerHTML = chartHtml(p[1], false); });
    const sl = $('shSlides'); if (sl) sl.scrollTo({ left: PQ.slide * sl.clientWidth, behavior: 'auto' });
    document.querySelectorAll('#shTabs [data-sl]').forEach((t) => t.classList.toggle('on', Number(t.dataset.sl) === PQ.slide));
    const cap = $('shCap'); if (cap) cap.textContent = PH[PQ.slide][2];
  }
  function goSlide(i, smooth) {
    PQ.slide = Math.max(0, Math.min(PH.length - 1, i));
    const sl = $('shSlides'); if (sl) sl.scrollTo({ left: PQ.slide * sl.clientWidth, behavior: smooth ? 'smooth' : 'auto' });
    document.querySelectorAll('#shTabs [data-sl]').forEach((t) => t.classList.toggle('on', Number(t.dataset.sl) === PQ.slide));
    const cap = $('shCap'); if (cap) cap.textContent = PH[PQ.slide][2];
  }
  function zoomChart(i) {
    const o = document.createElement('div'); o.className = 'sh-zoom';
    o.innerHTML = `<div class="zb"><b>${esc(PH[i][0])}</b><button type="button" class="zx" aria-label="Close">✕</button></div><div class="zs">${chartHtml(PH[i][1], true)}</div>`;
    o.onclick = (e) => { if (e.target.closest('.zx') || e.target === o) o.remove(); };
    document.body.appendChild(o);
  }

  // ------------------------------------------------------------------ 🧮 check a price (ML sizes + shower gel / combo), inside the price card
  const CQ = { mode: 'ml', ml: 250, price: '', free: false, code: true, pre: false, hit: false, over: 0, combo: 0 };
  try { Object.assign(CQ, JSON.parse(localStorage.getItem('hrms_mlprice') || '{}')); } catch (e) { /* defaults */ }
  const cKeep = () => { try { localStorage.setItem('hrms_mlprice', JSON.stringify(CQ)); } catch (e) { /* not kept */ } };
  const inr = (v) => '₹' + Math.round(Number(v) || 0).toLocaleString('en-IN');
  const pc = (v) => String(Math.round(Number(v) * 100) / 100) + '%';
  const r1 = (v) => Math.round(v * 10) / 10;
  const comboMatches = () => {
    const c = S.combo; if (!c || !c.rows) return [];
    const L = chartRows('combo'); const names = L ? L.rows.map((r) => r[0]) : [];
    const sel = c.rows.map((r, i) => ({ r, i })).filter((x) => names.indexOf(plabel(x.r)) >= 0);
    return sel.length ? sel : c.rows.map((r, i) => ({ r, i }));
  };
  // Today's target, automatic: this order is added to today's sales so far; reaching the target gives the extra %, and each higher step (+30/40/60/100% over) gives more.
  function autoDay(price) {
    const m = (S.coach && S.coach.me) || {}, T = Number(m.target) || 0, sales = Number(m.sales) || 0;
    if (!(T > 0)) return { T: 0, sales, after: sales, hit: false, over: null };
    const after = sales + (Number(price) > 0 ? Number(price) : 0), hit = after + 1e-6 >= T;
    return { T, sales, after, hit, over: hit ? (after / T - 1) * 100 : null };
  }
  function dayLine(price) {
    const a = autoDay(price); if (!(a.T > 0)) return '';
    const b = a.hit ? MLSLAB.bonusFor(S.mlChart, a.over) : 0;
    return `<div class="sh-nt ${a.hit ? 'ok' : ''}">🎯 Today ${inr(a.sales)} of ${inr(a.T)}${price > 0 ? ` · with this order ${inr(a.after)}` : ''} → ${a.hit ? `<b>+${b}% extra on every order today</b>` : `${inr(Math.max(0, a.T - a.after))} more to reach the target`}</div>`;
  }
  function calcMl() {
    const CH = S.mlChart, base = Number(CQ.ml) || 0, ml = base + (CQ.free ? 10 : 0), price = Number(CQ.price) || 0;
    const row = MLSLAB.rowFor(CH, ml);
    if (!(base > 0)) return '<div class="sh-res na"><div class="big">Type the total ML</div></div>';
    if (!row) return `<div class="sh-res na"><div class="big">${ml} ml is not in the chart</div><div class="why">The chart covers ${CH.rows[0].ml}–${CH.rows[CH.rows.length - 1].ml} ml. Other sizes earn the normal commission.</div></div>`;
    const C = MLSLAB.preview(CH), com = (m, p) => MLSLAB.orderCom(C, { ml: m, amt: p, pre: CQ.pre, code: CQ.code ? 'X' : '' }, autoDay(p).hit, autoDay(p).over);
    if (!(price > 0)) return dayLine(0) + '<div class="sh-res na"><div class="big">Type the customer price</div><div class="why">Or tap a price in the list below.</div></div>' + mlLines(CH, row, ml, null, com);
    const r = com(ml, price); if (!r) return '<div class="sh-res na"><div class="big">No slab for this</div></div>';
    let up = '';
    for (let j = row.p.length - 1; j >= 0; j--) {          // next higher column above this price
      const p = Math.ceil(row.p[j] * (CQ.pre ? 1 - Number(CH.prepaid) / 100 : 1) / 10) * 10;
      if (row.p[j] > r.look + 0.5) { const x = com(ml, p); if (x && x.v > r.v + 0.5) up = `Sell at <b>${inr(p)}</b> → ${pc(x.pct)} = <b>${inr(x.v)}</b> (+${inr(x.v - r.v)})`; break; }
    }
    let h = dayLine(price) + `<div class="sh-res ${r.overMax ? 'bad' : r.pct < 2 ? 'lo' : ''}"><div class="big">${pc(r.pct)} = ${inr(r.v)}</div><div class="why">${r.steps.map(esc).join('<br>')}</div>${up ? `<div class="up">${up}</div>` : ''}</div>`;
    const x = MLSLAB.extraByPrice(CH, ml, price, CQ.pre);
    if (x) h += x.extra > 0.05
      ? `<div class="sh-nt">🎁 <b>${r1(x.extra)} ml extra</b> (${inr(x.value)}) — ${inr(x.look)} pays for ${r1(x.paid)} ml at ₹${x.per.toFixed(2)}/ml.</div>`
      : `<div class="sh-nt ok">0 ml extra — ${inr(x.look)} is the chart price (${inr(x.chart)}) or more.</div>`;
    const maxd = Number(CH.maxdisc) || 0, maxAll = CH.maxdisc_for === 'all';
    if (maxd > 0 && CQ.code && !maxAll) h += `<div class="sh-nt ok">With a code there is no discount limit (${r.disc}% off). Without a code, more than ${maxd}% off pays nothing.</div>`;
    else if (maxd > 0) {
      const minPay = Math.ceil(row.p[0] * (1 - maxd / 100));
      h += r.overMax ? `<div class="sh-nt" style="background:#fef2f2;border-color:#fecaca;color:#991b1b">⛔ ${inr(price)} is <b>${r.disc}% off</b> (chart ${inr(row.p[0])}) — more than ${maxd}% max → <b>no commission</b>. Lowest price that still earns: <b>${inr(minPay)}</b>.</div>`
        : `<div class="sh-nt ok">${r.disc}% off · max ${maxd}% (lowest price ${inr(minPay)})</div>`;
    }
    if (!CQ.free) { const rf = com(base + 10, price); if (rf && rf.v < r.v - 0.5) h += `<div class="sh-nt">⚠ Giving 10 ml free at ${inr(price)} counts as ${base + 10} ml → <b>${pc(rf.pct)} = ${inr(rf.v)}</b> instead of ${inr(r.v)} (−${inr(r.v - rf.v)}).</div>`; }
    return h + mlLines(CH, row, ml, r.look, com);
  }
  // Every price of this size with what it earns (tap a row to try that price).
  function mlLines(CH, row, ml, look, com) {
    const rows = row.p.map((p, j) => { const pay = Math.round(p * (CQ.pre ? 1 - Number(CH.prepaid) / 100 : 1)), r = com(ml, pay); return `<tr data-p="${pay}"${look != null && Math.abs(look - p) <= 0.5 ? ' class="me"' : ''}><td>${inr(pay)}</td><td>${r ? pc(r.pct) : '—'}</td><td><b>${r ? inr(r.v) : '—'}</b></td></tr>`; });
    const lp = Math.round(row.p[row.p.length - 1] * (CQ.pre ? 1 - Number(CH.prepaid) / 100 : 1));
    const lr = Number(CH.floor) > 0 ? com(ml, lp - 1) : null;
    rows.push(lr && lr.pct > 0 ? `<tr><td>below ${inr(lp)}</td><td>${pc(lr.pct)}</td><td>${pc(lr.pct)} of price</td></tr>` : `<tr><td>below ${inr(lp)}</td><td>0%</td><td>₹0</td></tr>`);
    return `<details class="sh-dt"><summary>All prices for ${ml} ml</summary><table class="sh-ct no-stack"><thead><tr><th>${ml} ml price</th><th>%</th><th>You earn</th></tr></thead><tbody>${rows.join('')}</tbody></table></details>`;
  }
  function calcCombo() {
    const c = S.combo, r = c.rows[Number(CQ.combo)] || c.rows[0], price = Number(CQ.price) || 0, cols = [7, 6.5, 6, 5.5, 2.8];
    const nc = S.mlChart && Number(S.mlChart.nocode) > 0 ? Number(S.mlChart.nocode) : 100;
    const rows = r.p.map((p, j) => `<tr data-p="${p}"${price && j === r.p.findIndex((q) => price >= q - 0.5) ? ' class="me"' : ''}><td>${inr(p)}</td><td>${cols[j]}%</td><td><b>${inr(p * cols[j] / 100)}</b></td></tr>`).join('');
    const tbl = `<details class="sh-dt"><summary>All prices for this combo</summary><table class="sh-ct no-stack"><thead><tr><th>${esc(plabel(r))}</th><th>%</th><th>You earn</th></tr></thead><tbody>${rows}</tbody></table></details>`;
    if (!(price > 0)) return '<div class="sh-res na"><div class="big">Type the customer price</div><div class="why">Or tap a price in the list below.</div></div>' + tbl;
    let j = r.p.findIndex((q) => price >= q - 0.5); if (j < 0) j = r.p.length - 1;
    const pct = cols[j], full = price * pct / 100, code = CQ.code ? full : full * nc / 100;
    let up = ''; if (j > 0) up = `Sell at <b>${inr(r.p[j - 1])}</b> → ${cols[j - 1]}% = <b>${inr((CQ.code ? 1 : nc / 100) * r.p[j - 1] * cols[j - 1] / 100)}</b> (+${inr((CQ.code ? 1 : nc / 100) * r.p[j - 1] * cols[j - 1] / 100 - code)})`;
    const lowest = price < r.p[r.p.length - 1] - 0.5;
    return `<div class="sh-res ${pct < 5 ? 'lo' : ''}"><div class="big">${pct}% = ${inr(code)}</div><div class="why">${esc(plabel(r))} · ${inr(price)}${lowest ? ' is below the last price → lowest ' + pct + '%' : ' → ' + pct + '%'}${CQ.code ? '' : ' · no code → ' + nc + '% of it'}</div>${up ? `<div class="up">${up}</div>` : ''}</div>` + tbl;
  }
  function paintCalc() {
    const box = $('shCalc'); if (!box) return;
    const hasMl = S.mlChart && S.mlChart.rows && S.mlChart.rows.length, hasCombo = S.combo && S.combo.rows && S.combo.rows.length;
    if (!hasMl && !hasCombo) { box.innerHTML = ''; return; }
    if (CQ.mode === 'combo' && !hasCombo) CQ.mode = 'ml'; if (CQ.mode === 'ml' && !hasMl) CQ.mode = 'combo';
    const ml = CQ.mode === 'ml';
    const sizes = ml ? S.mlChart.rows.map((r) => r.ml).filter((m) => m % 50 === 0) : [];
    const opts = ml ? '' : comboMatches().map((x) => `<option value="${x.i}" ${Number(CQ.combo) === x.i ? 'selected' : ''}>${esc(plabel(x.r))}</option>`).join('');
    box.innerHTML = `<h3>🧮 Check a price</h3>
      <div class="sh-md">${hasMl ? `<button type="button" data-md="ml" class="${ml ? 'on' : ''}">ML size</button>` : ''}${hasCombo ? `<button type="button" data-md="combo" class="${ml ? '' : 'on'}">Shower gel / attar</button>` : ''}</div>
      <div class="sh-in">${ml ? `<label>Total ML<input id="cMl" type="number" inputmode="numeric" min="1" value="${esc(CQ.ml)}"></label>` : `<label>Combo<select id="cCb">${opts}</select></label>`}
        <label>Customer price ₹<input id="cPr" type="number" inputmode="numeric" min="0" placeholder="e.g. 2700" value="${esc(CQ.price)}"></label></div>
      ${ml ? `<div class="sh-sz">${sizes.map((m) => `<button type="button" data-ml="${m}" class="${Number(CQ.ml) === m ? 'on' : ''}">${m}</button>`).join('')}</div>` : ''}
      <div class="sh-ch">${ml ? `<label class="${CQ.free ? 'on' : ''}"><input type="checkbox" data-k="free" ${CQ.free ? 'checked' : ''}>+10 ml free</label>` : ''}<label class="${CQ.code ? 'on' : ''}"><input type="checkbox" data-k="code" ${CQ.code ? 'checked' : ''}>Code</label>${ml ? `<label class="${CQ.pre ? 'on' : ''}"><input type="checkbox" data-k="pre" ${CQ.pre ? 'checked' : ''}>Prepaid</label>` : ''}</div>
      <div id="shCo"></div>`;
    paintCalcOut();
  }
  function paintCalcOut() {
    const o = $('shCo'); if (!o) return;
    try { o.innerHTML = CQ.mode === 'ml' ? calcMl() : calcCombo(); } catch (e) { o.innerHTML = '<div class="sh-res na"><div class="big">Could not work this out</div></div>'; }
  }
  function initCalc() {
    const box = $('shCalc'); if (!box || box.dataset.init) return; box.dataset.init = '1';
    box.addEventListener('click', (e) => {
      const m = e.target.closest('[data-md]'); if (m) { CQ.mode = m.dataset.md; cKeep(); paintCalc(); return; }
      const z = e.target.closest('[data-ml]'); if (z) { CQ.ml = Number(z.dataset.ml); cKeep(); paintCalc(); return; }
      const t = e.target.closest('tr[data-p]'); if (t) { CQ.price = t.dataset.p; cKeep(); const i = $('cPr'); if (i) i.value = CQ.price; paintCalcOut(); }
    });
    box.addEventListener('input', (e) => {
      if (e.target.id === 'cMl') { CQ.ml = e.target.value; box.querySelectorAll('[data-ml]').forEach((b) => b.classList.toggle('on', Number(b.dataset.ml) === Number(CQ.ml))); }
      else if (e.target.id === 'cPr') CQ.price = e.target.value; else return;
      cKeep(); paintCalcOut();
    });
    box.addEventListener('change', (e) => {
      if (e.target.id === 'cCb') { CQ.combo = Number(e.target.value); cKeep(); paintCalcOut(); return; }
      const k = e.target.dataset && e.target.dataset.k; if (!k) return;
      CQ[k] = e.target.checked; e.target.closest('label').classList.toggle('on', e.target.checked); cKeep(); paintCalcOut();
    });
  }
  function paintPrice() {
    const box = $('shPrice');
    const hasMl = S.mlChart && S.mlChart.rows && S.mlChart.rows.length, hasCombo = S.combo && S.combo.rows && S.combo.rows.length;
    if (!hasMl && !hasCombo) { box.style.display = 'none'; return; }
    if (!box.dataset.init) {
      box.dataset.init = '1';
      box.innerHTML = `<div class="sh-ph"><h2>💲 Price charts</h2><span id="shCap"></span></div>
        <div class="sh-sr"><input type="search" id="shPq" placeholder="Search: 150, 100 ml, shower gel, attar…" autocomplete="off" aria-label="Search price"></div>
        <div class="sh-fc" id="shTabs">${PH.map((p, i) => `<span data-sl="${i}" class="${i ? '' : 'on'}">${p[0]}</span>`).join('')}</div>
        <div class="sh-slides" id="shSlides">${PH.map((p, i) => `<div class="sh-sl" id="shSl${i}"><button type="button" class="sh-zm" data-z="${i}" title="Open big" aria-label="Open big">🔍</button><div class="sh-sb"></div></div>`).join('')}</div>
        <div class="sh-dots" id="shDots">${PH.map((p, i) => `<i data-sl="${i}" class="${i ? '' : 'on'}"></i>`).join('')}</div>
        <div class="sh-calc" id="shCalc"></div>
        <div class="sh-fn">Swipe ◀ ▶ for the next photo. Below the last price earns no commission.</div>`;
      initCalc();
      $('shPq').oninput = (e) => {
        PQ.q = e.target.value;
        const t = PQ.q.toLowerCase();
        if (/gel|attar|perfume|combo|mix/.test(t)) PQ.slide = 1; else if (/\d/.test(t)) PQ.slide = 0;      // jump to the photo that has it
        paintPriceList();
        if (CQ.mode === 'combo') { const cb = comboMatches(); if (cb.length && !cb.some((x) => x.i === Number(CQ.combo))) CQ.combo = cb[0].i; paintCalc(); }
      };
      $('shTabs').onclick = (e) => { const t = e.target.closest('[data-sl]'); if (t) goSlide(Number(t.dataset.sl), true); };
      $('shPrice').addEventListener('click', (e) => { const z = e.target.closest('[data-z]'); if (z) zoomChart(Number(z.dataset.z)); });
      let tm = null;
      $('shSlides').addEventListener('scroll', () => {            // swipe → keep tabs / dots in step
        clearTimeout(tm); tm = setTimeout(() => {
          const sl = $('shSlides'), i = Math.round(sl.scrollLeft / Math.max(1, sl.clientWidth));
          if (i !== PQ.slide) { PQ.slide = i; document.querySelectorAll('#shTabs [data-sl], #shDots [data-sl]').forEach((t) => t.classList.toggle('on', Number(t.dataset.sl) === i)); const cap = $('shCap'); if (cap) cap.textContent = PH[i][2]; }
        }, 60);
      }, { passive: true });
    }
    box.style.display = '';
    paintPriceList();
    paintCalc();
    document.querySelectorAll('#shDots [data-sl]').forEach((t) => t.classList.toggle('on', Number(t.dataset.sl) === PQ.slide));
  }


  // ------------------------------------------------------------------ 📸 story strip (jump to a section)
  const vis = (e) => (e && e.style.display !== 'none' ? e : null);
  const STORY = [
    { k: 'target', e: '🎯', l: 'Target', el: () => vis($('shNow')) || vis($('shHero')) || vis($('shQuests')) },
    { k: 'code', e: '🔑', l: 'Code', el: () => $('shCode') || vis($('shQuests')) },
    { k: 'chat', e: '💬', l: 'Chat', el: () => vis($('chatCard')) || document.querySelector('#shMis .sh-mgrid') },
    { k: 'rating', e: '⭐', l: 'Rating', el: () => vis($('rateCard')) },
    { k: 'top', e: '🏆', l: 'Top 5', el: () => vis($('shLead')) },
  ];
  const seenKey = () => 'sh_story_' + ((S.coach && S.coach.business_day) || 'x');
  const seenSet = () => { try { return JSON.parse(store.get(seenKey()) || '{}'); } catch (e) { return {}; } };
  function storyInfo(k) {
    const m = (S.coach && S.coach.me) || {}, ct = (S.ct && S.ct.me) || {}, pr = ct.proof || {};
    if (k === 'target') { const T = Number(m.target) || 0, sa = Number(m.sales) || 0; return T > 0 ? (sa >= T ? { bd: '✓', done: 1 } : { bd: Math.round(sa / T * 100) + '%' }) : { bd: '' }; }
    if (k === 'code') { if (!(Number(m.code_pct) > 0)) return { bd: '' }; return m.code_ok ? { bd: '✓', done: 1 } : { bd: (Number(m.code_left) || 0) + ' left' }; }
    if (k === 'chat') { const n = (Number(pr.orders_no_chat) || 0) + (Number(pr.pending_queries) || 0); return n > 0 ? { bd: String(n) } : { bd: '✓', done: 1 }; }
    if (k === 'rating') return { bd: S.rating != null ? Number(S.rating).toFixed(1) : '' };
    if (k === 'top') return { bd: S.myRank ? '#' + S.myRank : '' };
    return { bd: '' };
  }
  let storyHtml = '';
  function paintStory() {
    let box = $('shStory');
    if (!box) { const rc = $('rateCard'); if (!rc || !rc.parentNode) return; box = document.createElement('div'); box.id = 'shStory'; rc.parentNode.insertBefore(box, rc); }
    if (!S.coach || !S.coach.me) return;
    const seen = seenSet();
    const html = '<div class="sh-story">' + STORY.map((x) => { const i = storyInfo(x.k); return `<button type="button" class="sh-sb ${i.done ? 'done' : ''} ${seen[x.k] ? 'seen' : ''}" data-st="${x.k}" aria-label="${x.l}"><div class="ring"><div class="in">${x.e}</div>${i.bd ? `<span class="bd">${esc(i.bd)}</span>` : ''}</div><span class="lb">${x.l}</span></button>`; }).join('') + '</div>';
    if (html === storyHtml) return; storyHtml = html; box.innerHTML = html;
    if (!box.dataset.init) {
      box.dataset.init = '1';
      box.addEventListener('click', (e) => {
        const b = e.target.closest('[data-st]'); if (!b) return;
        const x = STORY.find((y) => y.k === b.dataset.st), el = x && x.el(); if (!el) return;
        const sn = seenSet(); sn[x.k] = 1; store.set(seenKey(), JSON.stringify(sn)); storyHtml = ''; paintStory();
        el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        el.classList.remove('sh-flash'); void el.offsetWidth; el.classList.add('sh-flash'); setTimeout(() => el.classList.remove('sh-flash'), 1400);
      });
    }
  }

  // ------------------------------------------------------------------ allocation pop-up
  function initAlloc() {
    if (!S.canAlloc || $('shAb')) return;
    document.body.classList.add('sh-pad');
    const ab = document.createElement('div'); ab.className = 'sh-ab'; ab.id = 'shAb';
    ab.innerHTML = '<button type="button" id="shAbBtn"><span class="pl">＋</span><span>Allocate order</span><span class="ct" id="shCt"></span></button>';
    document.body.appendChild(ab);
    const sheet = document.createElement('div'); sheet.className = 'sh-sheet'; sheet.id = 'shSheet';
    sheet.innerHTML = '<div class="bd"></div><div class="pn" role="dialog" aria-modal="true" aria-label="Allocate order"><div class="hd"><b>＋ Allocate order</b><small id="shSub"></small><button type="button" id="shX">✕ Close</button></div></div>';
    document.body.appendChild(sheet);
    const mkFrame = () => {
      if (S.frame) return S.frame;
      const f = document.createElement('iframe'); f.title = 'Order Allocation'; f.src = 'order_allocation.html?embed=1'; f.setAttribute('allow', 'camera; microphone; geolocation');
      sheet.querySelector('.pn').appendChild(f); S.frame = f; return f;
    };
    const doClose = () => {
      if (!S.open) return;
      S.open = false; sheet.classList.remove('on'); document.body.classList.remove('sh-lock');
      if (S.dirty) { S.dirty = false; if (window.SalesHome.onRefresh) window.SalesHome.onRefresh(); }
    };
    const open = () => {
      if (S.open) return;
      mkFrame(); S.open = true; sheet.classList.add('on'); document.body.classList.add('sh-lock');
      try { history.pushState({ shSheet: 1 }, '', location.href); } catch (e) { /* no history */ }
    };
    const close = () => { if (!S.open) return; if (history.state && history.state.shSheet) history.back(); else doClose(); };
    $('shAbBtn').onclick = open; $('shX').onclick = close; sheet.querySelector('.bd').onclick = close;
    window.addEventListener('popstate', () => { if (S.open) doClose(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && S.open) close(); });
    S.openSheet = open; S.closeSheet = close;
    paintCount();
    // Load the allocation page quietly in the background so the pop-up opens instantly and the "N waiting" count is live.
    setTimeout(mkFrame, 1800);
    setInterval(() => { if (!document.hidden && !S.open && S.frame && S.frame.contentWindow) S.frame.contentWindow.postMessage({ hrms: 'alloc-reload' }, location.origin); }, 120000);
    window.addEventListener('message', (e) => {
      if (e.origin !== location.origin || !S.frame || e.source !== S.frame.contentWindow || !e.data || e.data.hrms !== 'alloc') return;
      if (e.data.t === 'count') { S.waiting = Number(e.data.n) || 0; paintCount(); }
      else if (e.data.t === 'allocated') { S.dirty = true; if (S.waiting > 0) { S.waiting--; paintCount(); } }
      else if (e.data.t === 'home') close();
    });
  }
  function paintCount() {
    const btn = $('shAbBtn'), ct = $('shCt'), sub = $('shSub'); if (!btn) return;
    const n = S.waiting;
    btn.classList.toggle('hasw', n > 0); btn.classList.toggle('zero', n === 0);
    ct.textContent = n == null ? '' : n > 0 ? n + ' waiting' : 'all caught up ✓';
    ct.style.display = n == null ? 'none' : '';
    if (sub) sub.textContent = n > 0 ? n + ' waiting for you' : '';
  }


  // ------------------------------------------------------------------ Control Tower mission feed (ct.my)
  // Same rules the admin sees in the Control Tower, said in a friendly voice: proof, ticket ladder, DG/LG/WH mix, community, learning idea,
  // outcome rating vs the top 3, and — only when really needed — "meet admin".
  const BUS = { dg: '#15803d', lg: '#86efac', wh: '#e2e8f0' };
  function ensureMis() {
    let box = $('shMis');
    if (!box) {
      box = document.createElement('div'); box.id = 'shMis'; box.className = 'sh-mis'; box.style.display = 'none';
      const q = $('shQuests'); if (q && q.parentNode) q.parentNode.insertBefore(box, q.nextSibling); else return null;
    }
    return box;
  }
  function paintMissions() {
    const d = S.ct, box = ensureMis(); if (!box) return;
    if (!d || !d.me) { box.style.display = 'none'; return; }
    const m = d.me, cfg = d.cfg || {}, tm = d.team || {}, tv = m.tv || {}, mix = m.mix || {}, pr = m.proof || {};
    const out = [];
    // 1) meet admin (stays until Admin / HR / a named person presses Resolved)
    (d.alerts || []).filter((a) => a.meet).forEach((a) => out.push(`<div class="sh-meet"><b>🤝 Please meet Admin</b><p>${esc(a.gu || a.title)}</p></div>`));
    // 2) friendly warning ("ting ting") — ONE at most (the most important); the learning nudge only when there is no warning
    const tings = (d.alerts || []).filter((a) => !a.meet && a.kind !== 'idea_rigid').slice(0, 1);
    if (!tings.length && d.nudge) out.push(`<div class="sh-ting"><span class="e">🌱</span><div><b>${esc(d.nudge.title)}</b>${esc(d.nudge.gu)}</div></div>`);
    tings.forEach((a) => out.push(`<div class="sh-ting ${a.sev === 'info' ? 'good' : ''}"><span class="e">${a.sev === 'info' ? '👍' : '⏰'}</span><div><b>${esc(a.title)}</b>${esc(a.gu || a.body || '')}</div></div>`));
    // 3) missions
    const tiles = [];
    const steps = cfg.steps || [2600, 2700];
    if (m.orders > 0 || m.ticket) {
      const tk = Number(m.ticket) || 0, ct = Number(m.clean_ticket) || 0;
      const hint = tv.state === 'early' ? 'Ticket is judged after a few orders'
        : tv.carried ? `Big orders are carrying your average — normal orders average <b>${rs(ct)}</b>. Aim for ${rs(tv.next || steps[0])} on regular orders.`
        : tv.need_next ? `Your <b>next order of ${rs(tv.need_next)}</b> lifts the average to <b>${rs(tv.next)}</b> 🎯` : (tv.state === 'raise' || tv.state === 'top' ? 'Top step reached — push it even higher 🚀' : '');
      tiles.push(`<div class="sh-q ${tv.state === 'top' || tv.state === 'raise' ? 'done' : tv.state === 'near' ? 'near' : ''}"><div class="sh-qh"><span>🎟️</span>Average ticket<em>${rs(tk)}</em></div>
        <div class="sh-qbig">${tv.next ? rs(tv.next) + ' is the next step' : 'Ladder complete!'}</div>
        <div class="sh-steps">${steps.map((x) => `<span class="${tk >= x ? 'on' : x === tv.next ? 'nx' : ''}">${rs(x)}</span>`).join('')}</div>
        <div class="sh-qs">${hint}</div></div>`);
    }
    if (mix.pieces > 0) {
      const tot = Math.max(1, mix.dg + mix.lg + mix.wh + (mix.oth || 0)), goal = cfg.mix_goal || 85, ok = mix.pct >= goal;
      tiles.push(`<div class="sh-q ${ok ? 'done' : mix.pct >= (cfg.mix_amber || 80) ? 'near' : ''}"><div class="sh-qh"><span>🟢</span>Dark green · light green · white<em>${Math.round(mix.pct)}%</em></div>
        <div class="sh-qbig">${ok ? 'Colour mix on target!' : 'Goal ' + goal + '% of pieces'}</div>
        <div class="sh-mixb"><i style="width:${mix.dg / tot * 100}%;background:${BUS.dg}"></i><i style="width:${mix.lg / tot * 100}%;background:${BUS.lg}"></i><i style="width:${mix.wh / tot * 100}%;background:${BUS.wh}"></i></div>
        <div class="sh-mixl"><span><u style="background:${BUS.dg}"></u>DG ${mix.dg}</span><span><u style="background:${BUS.lg}"></u>LG ${mix.lg}</span><span><u style="background:${BUS.wh}"></u>WH ${mix.wh}</span></div></div>`);
    }
    const noProof = (pr.orders_no_chat || 0) + (pr.pending_queries || 0);
    tiles.push(`<div class="sh-q ${noProof ? 'near' : 'done'}"><div class="sh-qh"><span>📎</span>Chat proof<em>${noProof ? noProof + ' pending' : 'DONE ✅'}</em></div>
      <div class="sh-qbig">${noProof ? 'Add your chat screenshots' : 'Every chat has its proof'}</div>
      <div class="sh-qs">${noProof ? `${pr.orders_no_chat ? '<b>' + plural(pr.orders_no_chat, 'order') + '</b> without a chat screenshot' : ''}${pr.orders_no_chat && pr.pending_queries ? ' · ' : ''}${pr.pending_queries ? '<b>' + plural(pr.pending_queries, 'query') + '</b> waiting for proof' : ''}. The AI learns from them and gives you tips.` : 'Great habit — the AI can now coach you on every conversation.'}</div>
      ${noProof ? '<button type="button" class="sh-more" id="shProofGo" style="margin-top:8px">📎 Add proof now</button>' : ''}</div>`);
    if (tm.comm_target) {
      const nx = (tm.comm_checks || []).find((c) => c.state === 'upcoming');
      tiles.push(`<div class="sh-q"><div class="sh-qh"><span>👥</span>Team community orders<em>${tm.community_orders || 0} / ${tm.comm_target}</em></div>
        <div class="sh-qbig">${nx ? 'Next check ' + esc(nx.at) + ': ' + nx.need : 'Day checkpoints done'}</div>
        <div class="sh-qbar"><i style="width:${Math.min(100, (tm.community_orders || 0) / tm.comm_target * 100)}%"></i></div>
        <div class="sh-qs">Team query orders: <b>${tm.query_orders || 0}</b>${tm.q_expected_now ? ' (pace says ~' + Math.round(tm.q_expected_now) + ' by now)' : ''}</div></div>`);
    }
    if (tiles.length) out.push(`<div class="sh-mgrid">${tiles.join('')}</div>`);
    // 4) learning idea of the day
    if (d.idea) {
      const i = d.idea;
      out.push(`<div class="sh-idea" id="shIdea"><h3>${esc(i.title)}</h3><p>${esc(i.body)}</p>${i.why ? `<small>${esc(i.why)}</small>` : ''}
        <div class="bt"><button type="button" class="a" data-idea="try">🚀 I'll try it today</button><button type="button" class="b" data-idea="open">👍 Got it</button></div></div>`);
    }
    // 5) outcome rating vs the top 3
    const oc = d.outcome;
    if (oc && oc.score != null) {
      const L = { conv: 'Conversion', ticket: 'Clean ticket', speed: 'Speed', volume: 'Volume' };
      out.push(`<div class="sh-q"><div class="sh-qh"><span>⭐</span>Outcome rating<em>vs top 3</em></div>
        <div class="sh-out"><div class="big">${Number(oc.score).toFixed(1)}<small> / 10</small></div><div class="sh-qs" style="margin:0;flex:1">Rated on results — conversion, clean ticket, speed and volume — against the best three people, not a fixed checklist.</div></div>
        ${Object.keys(L).filter((k) => oc.scores && oc.scores[k] != null).map((k) => `<div class="sh-ob"><span>${L[k]}</span><div><i style="width:${Math.max(4, Math.min(100, oc.scores[k] * 10))}%"></i></div><b>${Number(oc.scores[k]).toFixed(1)}</b></div>`).join('')}</div>`);
    }
    box.innerHTML = out.join(''); box.style.display = out.length ? '' : 'none';
    const go = $('shProofGo'); if (go) go.onclick = () => proofPopup({ list: pr.list || [], orders: pr.orders_no_chat, queries: pr.pending_queries, later_left: 0, manual: true });
    box.querySelectorAll('[data-idea]').forEach((b) => { b.onclick = async () => {
      const act = b.dataset.idea; try { await api.post('ct.idea_open', { id: d.idea.id, action: act }); } catch (e) {}
      const el = $('shIdea'); if (el) el.innerHTML = `<h3>${act === 'try' ? '🚀 Great — we will watch your next chats' : '👍 Noted'}</h3><p>${act === 'try' ? 'Try it in your next 3 chats. Your result will show here in a few days.' : 'Open it again tomorrow if you want — the next idea comes tomorrow.'}</p>`;
    }; });
  }
  function proofPopup(p) {
    if (document.querySelector('.sh-popbd')) return;
    const bd = document.createElement('div'); bd.className = 'sh-popbd';
    const rows = (p.list || []).slice(0, 5).map((o) => `<div class="row"><span>${esc(o.name || o.key)}</span><button type="button" data-k="${esc(o.key)}" data-n="${esc(o.name || '')}">📎 Add</button></div>`).join('');
    bd.innerHTML = `<div class="sh-pop" role="dialog" aria-modal="true"><h3>📎 ${p.manual ? 'Chat proof' : 'Proof ગુમ છે'}</h3>
      <p>${p.orders ? `<b>${plural(p.orders, 'order')}</b> ની chat screenshot બાકી છે` : ''}${p.orders && p.queries ? ' · ' : ''}${p.queries ? `<b>${plural(p.queries, 'query')}</b> નો proof બાકી છે` : ''}. 
      Screenshots/recordings થી AI તમને coaching આપે છે — અને ટીમને best messages મળે છે. 🙏</p>
      ${rows}
      ${p.queries ? '<button type="button" class="main" id="shPopLost">💔 Lost-chat proof add કરો</button>' : ''}
      ${p.manual || p.later_left <= 0 ? '<button type="button" class="later" id="shPopX">Close</button>' : `<button type="button" class="later" id="shPopLater">હમણાં નહીં (${p.later_left} વાર બાકી)</button>`}</div>`;
    document.body.appendChild(bd);
    const done = (later) => { bd.remove(); if (later && !p.manual) api.post('ct.popup_ack', { action: 'later' }).catch(() => {}); };
    bd.onclick = (e) => { if (e.target === bd) done(true); };
    const lb = bd.querySelector('#shPopLater'); if (lb) lb.onclick = () => done(true);
    const xb = bd.querySelector('#shPopX'); if (xb) xb.onclick = () => done(false);
    bd.querySelectorAll('.row button').forEach((b) => { b.onclick = () => { const [src, oid] = String(b.dataset.k).split('|'); bd.remove(); if (window.Convo) Convo.open({ kind: 'order', source: src, order_id: oid, order_name: b.dataset.n, onChange: () => loadMissions() }); }; });
    const pl = bd.querySelector('#shPopLost'); if (pl) pl.onclick = () => { bd.remove(); if (window.Convo) Convo.open({ kind: 'lost', onChange: () => loadMissions() }); };
  }
  // ------------------------------------------------------------------ 🏁 query → order ranking (everyone in Sales sees the same list)
  const LD = { view: 'today' };
  function paintLeaders() {
    const L = S.ct && S.ct.leaders; if (!L) return;
    let box = $('shLead');
    if (!box) { box = document.createElement('section'); box.id = 'shLead'; box.className = 'card sh-lead'; const pr = $('shPrice'); if (!pr || !pr.parentNode) return; pr.parentNode.insertBefore(box, pr); box.addEventListener('click', (e) => { const t = e.target.closest('[data-lv]'); if (t) { LD.view = t.dataset.lv; paintLeaders(); } }); }
    const V = L[LD.view] || L.today; if (!V || !V.rows || !V.rows.length) { box.style.display = 'none'; return; }
    const me = Number(L.me) || 0, min = Number(L.min) || 5, tc = V.team_conv;
    const medal = (r) => r === 1 ? '🥇' : r === 2 ? '🥈' : r === 3 ? '🥉' : r;
    // Top 5 only (nobody else's full list) + my own row when I am outside the top 5
    const act = V.rows.filter((r) => r.queries > 0 || r.orders > 0);
    const top = act.filter((r) => r.rank != null && r.rank <= 5);
    const mine = act.find((r) => r.id === me);
    const show = top.slice(); if (mine && show.indexOf(mine) < 0) show.push(mine);
    const rows = show.map((r) => {
      const ok = r.rank != null, w = ok && r.conv != null ? Math.min(100, r.conv * 2) : 0;
      return `<div class="sh-lr ${r.id === me ? 'me' : ''}"><span class="rk">${ok ? medal(r.rank) : '·'}</span><div class="nm"><b>${esc(r.name)}${r.id === me ? ' (you)' : ''}</b>${r.first ? ' <em>⚡ first order ' + esc(r.first_order || '') + '</em>' : ''}<div class="bar"><i style="width:${w}%"></i></div></div><div class="cv"><b>${r.conv != null ? Math.round(r.conv) + '%' : '—'}</b><small>${ok ? r.orders + ' / ' + r.queries : 'not ranked yet'}</small></div></div>`;
    }).join('');
    const myRank = mine && mine.rank != null ? mine.rank : null;
    S.myRank = myRank;
    box.innerHTML = `<div class="sh-ph"><h2>🏆 Top 5 ${LD.view === 'week' ? 'this week' : 'today'}</h2><div class="sh-lt2"><span data-lv="today" class="${LD.view === 'today' ? 'on' : ''}">Today</span><span data-lv="week" class="${LD.view === 'week' ? 'on' : ''}">7 days</span></div></div>
      <div class="sh-fn" style="margin:2px 0 4px">Query orders ÷ queries · team <b>${tc != null ? tc + '%' : '—'}</b>${myRank && myRank > 5 ? ' · you are #' + myRank + ' — climb! 🚀' : ''}</div>${rows}`;
    box.style.display = '';
  }

  async function loadMissions() {
    try { S.ct = await api.get('ct.my'); } catch (e) { return; }
    paintMissions(); paintLeaders(); paintStory(); loadRemarkList();
    const rm = S.ct && S.ct.remarks;
    if (rm && rm.length && !S.open && !document.querySelector('.cvx')) { chime(); remarkPopup(rm); return; }      // a remark first — the proof pop-up comes at the next check
    const pu = S.ct && S.ct.popup;
    if (pu && pu.kind === 'proof' && !S.open && !document.querySelector('.cvx')) { chime(); proofPopup(pu); }
  }

  // ------------------------------------------------------------------ data
  async function loadExtras() {
    api.get('attendance.today').then((d) => { S.punch = d && d.attendance ? d.attendance : {}; paint(); }).catch(() => {});
    Promise.all([api.get('combo_price.get').catch(() => null), api.get('ml_slab.get').catch(() => null)]).then(([c, m]) => {
      S.combo = c && c.chart; S.mlChart = m && m.chart; paintPrice();
    }).catch(() => { $('shPrice').style.display = 'none'; });
    setTimeout(loadMissions, 600);
    setInterval(() => { if (!document.hidden) loadMissions(); }, 5 * 60 * 1000);
    setInterval(checkRemarks, 60 * 1000);                                    // a new remark shows up within a minute
    setInterval(() => { if (!document.hidden) paintStory(); }, 4000);      // the chat card arrives from another script — keep the bubbles in step
    api.get('me.flags').then((d) => { S.canAlloc = (d.permissions || []).indexOf('allocation') > -1; initAlloc(); }).catch(() => {});
  }

  window.SalesHome = {
    setCoach(d) { S.coach = d; paint(); },
    setRating(v) { S.rating = v; paint(); paintStory(); },
    onRefresh: null,
    refreshMissions: () => loadMissions(),
    open: () => S.openSheet && S.openSheet(),
    fx: { chime, confetti, toast },
    _s: S,
  };
  loadExtras();
})();