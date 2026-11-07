/* My Sales — compact "dopamine" panel (employee side).
   Sits on top of sales_home.js: it only READS what that file already loads (SalesHome._s: coach, ct, punch) and moves the existing cards into tabs.
   Home: header (streak + level + punch) · "Today's race" · 6 quest rings · mystery box · today's lesson · biggest gap · next best move (₹-ranked) · rank strip.
   Tabs: 📊 Compare (my.compare) · 🎓 Learn (learn.today / learn.done + tips, chat coach, price charts) · ⭐ Rating · 📅 Month.
   Also: punch in / out in a pop-up, 25 / 50 / 75 % moments, yesterday / end-of-day summary (my.day). */
(function () {
  'use strict';
  if (window.SalesPanel || !window.SalesHome || !window.SalesHome._s) return;
  const SH = window.SalesHome, S = SH._s;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (v) => '₹' + Math.round(Number(v) || 0).toLocaleString('en-IN');
  const kf = (v) => { v = Number(v) || 0; return v >= 1000 ? (v % 1000 === 0 ? v / 1000 : (v / 1000).toFixed(1).replace(/\.0$/, '')) + 'k' : String(Math.round(v)); };
  const reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  const ls = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };

  const css = `
.sp-on #shStory,.sp-on #shNow,.sp-on #shHero,.sp-on #shQuests,.sp-on #shLead,.sp-on .sh-meet{display:none !important;}
.sp-on #spRoot{order:1;} .sp-on #shLost,.sp-on #shRemarks,.sp-on #shOffer{order:2;}
#spRoot{font-family:inherit;margin-bottom:6px;}
@media (max-width:820px){#spRoot{padding:0 10px;}}
.sp-top{display:flex;align-items:center;gap:8px;margin:0 2px 8px;flex-wrap:wrap;}
.sp-hi{font-size:17px;font-weight:900;flex:1;line-height:1.1;min-width:110px;} .sp-hi small{display:block;font-size:11px;font-weight:700;color:#6b7280;margin-top:2px;}
.sp-chip{display:inline-flex;align-items:center;gap:4px;border-radius:99px;padding:4px 9px;font-size:11.5px;font-weight:900;background:#fff;border:1px solid #e5e7eb;color:#374151;text-decoration:none;font-family:inherit;}
.sp-chip.fire{background:#fff7ed;border-color:#fed7aa;color:#c2410c;} .sp-chip.mute{color:#9ca3af;} .sp-chip.warn{background:#fef2f2;border-color:#fecaca;color:#991b1b;} .sp-chip.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534;}
button.sp-chip{cursor:pointer;}
.sp-lv{display:flex;flex-direction:column;gap:2px;min-width:78px;} .sp-lv b{font-size:11px;font-weight:900;color:#374151;white-space:nowrap;}
.sp-lv i{display:block;height:5px;border-radius:9px;background:#e5e7eb;overflow:hidden;} .sp-lv i u{display:block;height:100%;background:linear-gradient(90deg,#fb923c,#f97316);border-radius:9px;transition:width .8s;}
.sp-punch{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1px solid #fed7aa;border-radius:16px;padding:10px 12px;margin-bottom:8px;}
.sp-punch .ic{font-size:26px;} .sp-punch .tx{flex:1;font-size:12.5px;font-weight:800;color:#7c2d12;line-height:1.3;} .sp-punch .tx small{display:block;font-size:11px;color:#9a3412;font-weight:700;}
.sp-punch button{border:0;background:#f97316;color:#fff;border-radius:12px;padding:10px 16px;font-weight:900;font-size:13px;font-family:inherit;cursor:pointer;box-shadow:0 4px 12px rgba(249,115,22,.4);}
.sp-meet{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:7px 10px;font-size:12px;font-weight:800;margin-bottom:8px;cursor:pointer;}
.sp-meet .r{display:flex;align-items:center;gap:8px;} .sp-meet .r span{flex:1;} .sp-meet .r b{font-size:16px;}
.sp-meet .l{display:none;margin-top:6px;border-top:1px solid #fecaca;padding-top:6px;font-weight:700;line-height:1.45;} .sp-meet.open .l{display:block;} .sp-meet .l p{margin:3px 0;}
.sp-hero{background:linear-gradient(160deg,#14532d,#15803d);color:#fff;border-radius:20px;padding:12px;box-shadow:0 8px 22px rgba(20,83,45,.28);position:relative;overflow:hidden;}
.sp-hero.gold{background:linear-gradient(160deg,#92400e,#d97706 55%,#fbbf24);box-shadow:0 8px 26px rgba(217,119,6,.45);}
.sp-hero .row{display:flex;align-items:flex-end;gap:8px;}
.sp-hero .lab{font-size:11px;font-weight:900;letter-spacing:.08em;opacity:.85;text-transform:uppercase;}
.sp-hero .big{font-size:34px;font-weight:900;letter-spacing:-.03em;line-height:1;font-variant-numeric:tabular-nums;} .sp-hero .big small{font-size:13px;font-weight:800;opacity:.8;letter-spacing:0;margin-left:4px;}
.sp-hero .com{margin-left:auto;text-align:right;} .sp-hero .com .v{font-size:19px;font-weight:900;color:#bbf7d0;line-height:1.05;} .sp-hero.gold .com .v{color:#fff7ae;} .sp-hero .com .v em{font-style:normal;font-size:11px;font-weight:800;color:#fff;opacity:.9;}
.sp-bar{position:relative;height:18px;border-radius:10px;background:rgba(255,255,255,.2);margin-top:12px;}
.sp-bar .f{position:absolute;left:0;top:0;bottom:0;border-radius:10px;background:linear-gradient(90deg,#86efac,#fde047);box-shadow:0 0 14px rgba(253,224,71,.55);transition:width .9s cubic-bezier(.2,.8,.2,1);}
.sp-hero.gold .sp-bar .f{background:linear-gradient(90deg,#fde68a,#fff7ae);}
.sp-bar .f:after{content:"";position:absolute;right:0;top:0;bottom:0;width:26px;border-radius:10px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.55));}
.sp-bar .n{position:absolute;top:0;bottom:0;width:2px;background:rgba(255,255,255,.55);}
.sp-bar .t{position:absolute;top:-4px;bottom:-4px;width:3px;margin-left:-1.5px;background:#fff;border-radius:3px;box-shadow:0 0 0 1px rgba(0,0,0,.25);}
.sp-bar .g{position:absolute;top:-3px;bottom:-3px;margin-left:-1px;border-left:2px dashed #fde68a;}
.sp-cap{position:relative;height:14px;margin-top:3px;font-size:11px;font-weight:800;} .sp-cap span{position:absolute;transform:translateX(-50%);white-space:nowrap;opacity:.92;}
.sp-cap.two{height:27px;} .sp-cap.two .b{top:13px;color:#fde68a;}
.sp-mile{display:flex;justify-content:space-between;font-size:11px;font-weight:900;margin-top:1px;opacity:.9;} .sp-mile span.on{color:#fde68a;}
.sp-chips{display:flex;gap:6px;margin-top:9px;}
.sp-chips .c{flex:1;min-width:0;background:rgba(255,255,255,.12);border-radius:12px;padding:6px 8px;border-left:4px solid rgba(255,255,255,.3);}
.sp-chips .c.g{border-left-color:#4ade80;} .sp-chips .c.a{border-left-color:#fbbf24;} .sp-chips .c.r{border-left-color:#f87171;}
.sp-chips .c b{display:block;font-size:15px;font-weight:900;line-height:1.1;} .sp-chips .c b i{font-style:normal;font-size:11px;opacity:.8;}
.sp-chips .c span{display:block;font-size:11px;font-weight:800;opacity:.9;margin-top:1px;line-height:1.25;}
.sp-chips .c.a b{color:#fde68a;} .sp-chips .c.g b{color:#bbf7d0;} .sp-chips .c.r b{color:#fecaca;}
.sp-next{background:rgba(255,255,255,.14);border-radius:12px;padding:8px 10px;margin-top:9px;font-size:12px;font-weight:800;line-height:1.35;} .sp-next b{color:#fde68a;}
.sp-steps{display:flex;gap:4px;margin-top:8px;} .sp-steps div{flex:1;text-align:center;background:rgba(0,0,0,.16);border-radius:9px;padding:4px 0;font-size:11px;font-weight:900;line-height:1.25;}
.sp-steps small{display:block;font-size:11px;opacity:.85;font-weight:800;} .sp-steps .on{background:rgba(187,247,208,.3);} .sp-steps .nx{background:rgba(255,255,255,.28);box-shadow:0 0 0 1.5px rgba(255,255,255,.7);}
.sp-card{background:#fff;border-radius:16px;padding:10px 11px;margin-top:9px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.sp-start h4{font-size:12px;font-weight:900;margin:0 0 6px;display:flex;justify-content:space-between;} .sp-start h4 em{font-style:normal;color:#15803d;}
.sp-start .it{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;padding:4px 0;} .sp-start .it i{font-style:normal;width:20px;height:20px;border-radius:50%;display:grid;place-items:center;font-size:11px;background:#dcfce7;color:#166534;flex:none;}
.sp-start .it.no i{background:#f3f4f6;color:#9ca3af;} .sp-start .it.no{color:#6b7280;}
.sp-mys{display:flex;gap:10px;align-items:center;background:linear-gradient(135deg,#312e81,#6d28d9);color:#fff;border-radius:16px;padding:11px 12px;margin-top:9px;box-shadow:0 6px 18px rgba(109,40,217,.35);}
.sp-mys .bx{font-size:34px;filter:drop-shadow(0 0 8px #fde68a);} .sp-mys .tx{flex:1;font-size:12px;font-weight:800;line-height:1.35;} .sp-mys .tx small{display:block;font-size:11px;letter-spacing:.08em;color:#fde68a;text-transform:uppercase;margin-bottom:2px;}
.sp-mys button{border:0;background:#fde047;color:#422006;border-radius:10px;padding:9px 13px;font-weight:900;font-size:12px;font-family:inherit;cursor:pointer;}
.sp-mys.ready{animation:spPulse 1.6s ease-in-out infinite;} @keyframes spPulse{50%{box-shadow:0 0 0 6px rgba(253,224,71,.35);}}
.sp-mys.open{background:linear-gradient(135deg,#f5f3ff,#ede9fe);color:#4c1d95;box-shadow:none;border:1px solid #ddd6fe;} .sp-mys.open .tx small{color:#7c3aed;} .sp-mys.open .bx{filter:none;font-size:26px;}
.sp-lesson{display:flex;gap:10px;align-items:center;background:#fff;border-left:5px solid #3b82f6;border-radius:14px;padding:9px 11px;margin-top:9px;box-shadow:0 1px 3px rgba(0,0,0,.06);cursor:pointer;}
.sp-lesson .ic{font-size:22px;} .sp-lesson .tx{flex:1;font-size:12px;font-weight:800;line-height:1.3;min-width:0;} .sp-lesson .tx small{display:block;color:#6b7280;font-size:11px;font-weight:700;} .sp-lesson .go{font-size:11px;font-weight:900;color:#2563eb;white-space:nowrap;}
.sp-lesson.done{border-left-color:#f59e0b;background:linear-gradient(180deg,#fffbeb,#fff);} .sp-lesson.done .go{color:#b45309;}
.sp-gap{display:flex;align-items:center;gap:9px;background:#fff;border-left:5px solid #ef4444;border-radius:14px;padding:9px 11px;margin-top:9px;box-shadow:0 1px 3px rgba(0,0,0,.06);cursor:pointer;}
.sp-gap .tx{flex:1;font-size:12px;font-weight:800;line-height:1.35;} .sp-gap .tx small{display:block;color:#6b7280;font-size:11px;font-weight:700;} .sp-gap .tx b.w{color:#15803d;} .sp-gap .go{font-size:11px;font-weight:900;color:#2563eb;}
.sp-quests{display:grid;grid-template-columns:repeat(6,1fr);gap:4px;margin-top:9px;}
.sp-q{background:#fff;border-radius:14px;padding:7px 0 5px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06);cursor:pointer;min-width:0;}
.sp-ring{width:36px;height:36px;border-radius:50%;margin:0 auto;display:grid;place-items:center;position:relative;background:conic-gradient(var(--c) calc(var(--p)*1%),#e5e7eb 0);}
.sp-ring:before{content:"";position:absolute;inset:4px;background:#fff;border-radius:50%;} .sp-ring span{position:relative;font-size:14px;}
.sp-q b{display:block;font-size:11px;font-weight:900;margin-top:3px;color:#111827;white-space:nowrap;} .sp-q small{display:block;font-size:11px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.02em;}
.sp-q.done .sp-ring{background:conic-gradient(#f59e0b 100%,#f59e0b 0);} .sp-q.done{background:linear-gradient(180deg,#fffbeb,#fff);} .sp-q.done b{color:#b45309;}
.sp-move{display:flex;gap:9px;align-items:center;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1px solid #fed7aa;border-radius:16px;padding:10px 11px;margin-top:9px;}
.sp-move .ic{font-size:24px;} .sp-move .tx{flex:1;font-size:12.5px;font-weight:800;line-height:1.35;color:#7c2d12;}
.sp-move .tx small{display:block;font-size:11px;font-weight:900;letter-spacing:.06em;color:#ea580c;text-transform:uppercase;margin-bottom:1px;} .sp-move .tx .nx2{display:block;font-weight:700;font-size:11px;margin-top:2px;}
.sp-move button{border:0;background:#f97316;color:#fff;border-radius:10px;padding:8px 12px;font-weight:900;font-size:12px;font-family:inherit;cursor:pointer;}
.sp-worth{display:inline-block;background:#f97316;color:#fff;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:900;margin-left:4px;white-space:nowrap;}
.sp-rank{display:flex;align-items:center;gap:8px;background:#fff;border-radius:16px;padding:9px 11px;margin-top:9px;box-shadow:0 1px 3px rgba(0,0,0,.06);cursor:pointer;}
.sp-rank .me{font-size:20px;font-weight:900;color:#15803d;} .sp-rank .tx{flex:1;font-size:11.5px;font-weight:800;line-height:1.3;min-width:0;} .sp-rank .tx small{display:block;color:#6b7280;font-weight:700;font-size:11px;}
.sp-av{display:flex;} .sp-av i{width:26px;height:26px;border-radius:50%;border:2px solid #fff;margin-left:-7px;display:grid;place-items:center;font-style:normal;font-size:11px;font-weight:900;color:#fff;}
.sp-sum{background:linear-gradient(160deg,#1e293b,#334155);color:#fff;border-radius:20px;padding:13px;box-shadow:0 8px 22px rgba(15,23,42,.3);margin-bottom:8px;}
.sp-sum h3{font-size:15px;font-weight:900;margin:0;} .sp-sum h3 small{display:block;font-size:11px;opacity:.75;font-weight:700;margin-top:2px;}
.sp-sum .g3{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:10px;} .sp-sum .g3 div{background:rgba(255,255,255,.1);border-radius:12px;padding:8px 6px;text-align:center;} .sp-sum .g3 b{display:block;font-size:16px;font-weight:900;} .sp-sum .g3 span{font-size:11px;font-weight:800;opacity:.85;}
.sp-sum .rw{display:flex;gap:8px;margin-top:9px;font-size:12px;font-weight:800;background:rgba(255,255,255,.1);border-radius:12px;padding:8px 10px;line-height:1.35;} .sp-sum .rw b{color:#fde68a;} .sp-sum .rw.miss{background:rgba(239,68,68,.22);}
.sp-fr{margin-top:9px;background:#eff6ff;color:#1e40af;border-radius:12px;padding:7px 10px;font-size:11.5px;font-weight:800;}
.sp-tabs{display:flex;gap:6px;margin-top:10px;overflow-x:auto;scrollbar-width:none;padding-bottom:2px;} .sp-tabs::-webkit-scrollbar{display:none;}
.sp-tabs button{flex:1;white-space:nowrap;background:#fff;border:1px solid #e5e7eb;border-radius:99px;padding:8px 12px;font-size:12px;font-weight:900;color:#4b5563;font-family:inherit;cursor:pointer;}
.sp-tabs button.on{background:#111827;color:#fff;border-color:#111827;}
.sp-pane{display:none;margin-top:8px;} .sp-pane.on{display:block;}
.sp-pane > .card,.sp-pane > div > .card{max-width:none !important;}
.sp-toast{position:fixed;left:50%;top:70px;transform:translate(-50%,-30px);opacity:0;transition:all .45s cubic-bezier(.2,1.2,.3,1);background:#fff;color:#14532d;border-radius:16px;padding:12px 18px;box-shadow:0 14px 40px rgba(0,0,0,.35);text-align:center;z-index:1200;width:min(300px,86vw);pointer-events:none;}
.sp-toast.on{opacity:1;transform:translate(-50%,0);} .sp-toast b{display:block;font-size:18px;font-weight:900;} .sp-toast span{font-size:12px;font-weight:800;color:#4b5563;}
.sp-modal{position:fixed;inset:0;z-index:1250;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.6);padding:20px;} .sp-modal.on{display:flex;}
.sp-modal .bx{background:#fff;border-radius:22px;padding:20px 18px;text-align:center;max-width:330px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.45);animation:spPop .5s cubic-bezier(.2,1.4,.3,1);}
@keyframes spPop{from{transform:scale(.6);opacity:0;}to{transform:scale(1);opacity:1;}}
.sp-modal .e{font-size:46px;} .sp-modal h3{font-size:19px;font-weight:900;color:#4c1d95;margin:4px 0 8px;} .sp-modal p{font-size:13.5px;font-weight:700;color:#374151;line-height:1.5;margin:0;}
.sp-modal small{display:block;margin-top:10px;font-size:11px;color:#9ca3af;font-weight:700;} .sp-modal button{margin-top:14px;border:0;background:#111827;color:#fff;border-radius:12px;padding:11px 26px;font-weight:900;font-size:13px;font-family:inherit;cursor:pointer;}
.sp-sheet{position:fixed;inset:0;z-index:1300;display:none;} .sp-sheet.on{display:block;} .sp-sheet .bd{position:absolute;inset:0;background:rgba(15,23,42,.55);}
.sp-sheet .pn{position:absolute;left:0;right:0;bottom:0;height:92vh;background:#fff;border-radius:20px 20px 0 0;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 -10px 40px rgba(0,0,0,.35);}
@media (min-width:821px){.sp-sheet .pn{left:50%;right:auto;width:560px;transform:translateX(-50%);height:88vh;}}
.sp-sheet .hd{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #e5e7eb;font-weight:900;font-size:14px;} .sp-sheet .hd b{flex:1;}
.sp-sheet .hd button{border:0;background:#f3f4f6;border-radius:10px;padding:7px 12px;font-weight:900;font-size:12px;font-family:inherit;cursor:pointer;} .sp-sheet iframe{flex:1;border:0;width:100%;}
/* Compare */
.sp-seg{display:flex;background:#e5e7eb;border-radius:99px;padding:3px;margin:0 0 8px;} .sp-seg button{flex:1;text-align:center;font-size:11.5px;font-weight:900;padding:6px 0;border-radius:99px;color:#4b5563;border:0;background:none;font-family:inherit;cursor:pointer;}
.sp-seg button.on{background:#fff;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,.12);}
.sp-cmph{background:linear-gradient(160deg,#14532d,#15803d);color:#fff;border-radius:18px;padding:11px 12px;display:flex;align-items:center;gap:12px;}
.sp-cmph .rk{font-size:36px;font-weight:900;line-height:1;white-space:nowrap;} .sp-cmph .rk small{font-size:12px;opacity:.8;font-weight:800;} .sp-cmph .tx{flex:1;font-size:12px;font-weight:800;line-height:1.4;} .sp-cmph .tx b{color:#fde68a;}
.sp-lab{font-size:11px;font-weight:900;letter-spacing:.07em;color:#6b7280;text-transform:uppercase;margin:12px 2px 0;}
.sp-m{background:#fff;border-radius:16px;padding:10px 11px;margin-top:8px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.sp-m .h{display:flex;align-items:center;gap:6px;} .sp-m .h b{font-size:12.5px;font-weight:900;flex:1;} .sp-m .h .v{font-size:16px;font-weight:900;font-variant-numeric:tabular-nums;}
.sp-rkb{font-size:11px;font-weight:900;border-radius:99px;padding:2px 8px;white-space:nowrap;} .sp-rkb.g{background:#dcfce7;color:#166534;} .sp-rkb.a{background:#fef3c7;color:#92400e;} .sp-rkb.r{background:#fee2e2;color:#991b1b;} .sp-rkb.n{background:#f3f4f6;color:#6b7280;}
.sp-trk{position:relative;height:8px;border-radius:9px;background:#e5e7eb;margin:14px 0 5px;}
.sp-trk .y{position:absolute;left:0;top:0;bottom:0;border-radius:9px;} .sp-trk .avg{position:absolute;top:-4px;bottom:-4px;width:2px;background:#6b7280;}
.sp-trk .best{position:absolute;top:-17px;margin-left:-8px;font-size:12px;line-height:14px;} .sp-trk .me{position:absolute;top:-3px;width:14px;height:14px;border-radius:50%;margin-left:-7px;border:3px solid #fff;box-shadow:0 0 0 1.5px currentColor;}
.sp-lg{display:flex;justify-content:space-between;gap:6px;font-size:11px;font-weight:800;color:#6b7280;flex-wrap:wrap;}
.sp-do{font-size:11.5px;font-weight:800;line-height:1.35;margin-top:6px;border-radius:10px;padding:6px 8px;} .sp-do.y{background:#f0fdf4;color:#166534;} .sp-do.n{background:#fef2f2;color:#991b1b;}
.sp-all{margin-top:6px;} .sp-all > button{border:0;background:none;color:#2563eb;font-size:11px;font-weight:900;padding:2px 0;cursor:pointer;font-family:inherit;}
.sp-all .rows{display:none;margin-top:4px;} .sp-all.open .rows{display:block;}
.sp-all .rw{display:flex;align-items:center;gap:8px;padding:4px 6px;border-radius:8px;font-size:11.5px;font-weight:800;} .sp-all .rw.me{background:#ecfdf5;color:#065f46;}
.sp-all .rw i{font-style:normal;width:22px;color:#9ca3af;font-weight:900;} .sp-all .rw span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;} .sp-all .rw em{font-style:normal;font-variant-numeric:tabular-nums;}
.sp-rowc{display:flex;align-items:center;gap:8px;background:#fff;border-radius:12px;padding:8px 10px;margin-top:6px;box-shadow:0 1px 3px rgba(0,0,0,.06);font-size:11.5px;font-weight:800;cursor:pointer;}
.sp-rowc b{flex:1;} .sp-rowc .mb{width:64px;height:6px;border-radius:9px;background:#e5e7eb;overflow:hidden;} .sp-rowc .mb u{display:block;height:100%;border-radius:9px;} .sp-rowc em{font-style:normal;min-width:48px;text-align:right;}
.sp-det{display:none;} .sp-det.on{display:block;} .sp-det .sp-m{margin-top:4px;}
.sp-note{font-size:11px;font-weight:700;color:#6b7280;margin:6px 2px 0;} .sp-note a{color:#2563eb;font-weight:900;cursor:pointer;}
.sp-climb{background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:12px;padding:8px 10px;font-size:11.5px;font-weight:800;margin-top:8px;line-height:1.4;}
.sp-earn{background:#fff;border-radius:16px;padding:11px;margin-top:8px;box-shadow:0 1px 3px rgba(0,0,0,.06);} .sp-earn .t{font-size:13px;font-weight:900;margin-bottom:8px;}
.sp-eb{display:flex;align-items:center;gap:8px;margin-top:6px;font-size:11.5px;font-weight:800;} .sp-eb span{width:84px;flex:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sp-eb i{flex:1;height:14px;border-radius:7px;background:#f3f4f6;position:relative;overflow:hidden;} .sp-eb i u{position:absolute;left:0;top:0;bottom:0;border-radius:7px;} .sp-eb em{font-style:normal;width:62px;text-align:right;font-weight:900;font-variant-numeric:tabular-nums;}
.sp-lose{margin-top:9px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:8px 10px;font-size:11.5px;font-weight:800;color:#7c2d12;line-height:1.5;} .sp-lose p{margin:2px 0;} .sp-lose b{color:#c2410c;}
.sp-dd{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;} .sp-dd div{border-radius:14px;padding:9px 10px;font-size:11.5px;font-weight:800;line-height:1.4;}
.sp-dd .y{background:#ecfdf5;color:#065f46;} .sp-dd .n{background:#fef2f2;color:#991b1b;} .sp-dd h5{font-size:11px;letter-spacing:.06em;text-transform:uppercase;margin:0 0 3px;font-weight:900;}
.sp-msg{background:#fff;border-radius:16px;padding:14px;text-align:center;font-size:12.5px;font-weight:800;color:#6b7280;box-shadow:0 1px 3px rgba(0,0,0,.06);}
/* Learn */
.sp-ls{background:#fff;border-radius:18px;padding:13px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.sp-ls .tr{font-size:11px;font-weight:900;letter-spacing:.07em;color:#2563eb;text-transform:uppercase;} .sp-ls h3{font-size:16px;font-weight:900;margin:3px 0 2px;} .sp-ls .why{font-size:11px;font-weight:700;color:#6b7280;}
.sp-ls .rd p{font-size:13px;font-weight:600;line-height:1.55;margin:9px 0 0;color:#1f2937;}
.sp-ls .act{margin-top:11px;background:#eff6ff;border-radius:12px;padding:9px 11px;font-size:12.5px;font-weight:800;color:#1e3a8a;line-height:1.4;} .sp-ls .act small{display:block;font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:#2563eb;margin-bottom:2px;}
.sp-btn{display:block;width:100%;margin-top:12px;border:0;background:#111827;color:#fff;border-radius:12px;padding:12px;font-weight:900;font-size:13px;font-family:inherit;cursor:pointer;} .sp-btn.green{background:#16a34a;} .sp-btn.ghost{background:#f3f4f6;color:#111827;}
.sp-qz .q{font-size:14px;font-weight:900;margin:8px 0 10px;line-height:1.4;} .sp-qz .pg{font-size:11px;font-weight:900;color:#6b7280;letter-spacing:.06em;}
.sp-qz .o{display:block;width:100%;text-align:left;margin-top:7px;border:2px solid #e5e7eb;background:#fff;border-radius:12px;padding:11px 12px;font-size:13px;font-weight:800;font-family:inherit;cursor:pointer;color:#111827;}
.sp-qz .o.ok{border-color:#16a34a;background:#f0fdf4;color:#166534;} .sp-qz .o.bad{border-color:#dc2626;background:#fef2f2;color:#991b1b;}
.sp-res{text-align:center;padding:8px 0;} .sp-res .e{font-size:42px;} .sp-res h3{margin:4px 0;font-size:17px;font-weight:900;} .sp-res p{margin:0;font-size:12.5px;font-weight:700;color:#4b5563;}
.sp-chg{background:#fff;border-radius:16px;padding:10px 11px;margin-top:8px;box-shadow:0 1px 3px rgba(0,0,0,.06);} .sp-chg .t{font-size:12.5px;font-weight:900;margin-bottom:4px;}
.sp-chg .r{display:flex;gap:8px;align-items:center;font-size:12px;font-weight:800;padding:4px 0;} .sp-chg .r b{flex:1;font-weight:800;} .sp-chg .up{color:#16a34a;font-weight:900;} .sp-chg .dn{color:#dc2626;font-weight:900;}
.sp-lib .tk{font-size:11px;font-weight:900;letter-spacing:.07em;color:#6b7280;text-transform:uppercase;margin:11px 2px 0;}
.sp-lib .li{display:flex;align-items:center;gap:8px;background:#fff;border-radius:12px;padding:9px 10px;margin-top:6px;font-size:12px;font-weight:800;box-shadow:0 1px 3px rgba(0,0,0,.06);cursor:pointer;} .sp-lib .li b{flex:1;font-weight:800;} .sp-lib .li i{font-style:normal;width:20px;height:20px;border-radius:50%;display:grid;place-items:center;font-size:11px;background:#f3f4f6;color:#9ca3af;} .sp-lib .li.done i{background:#fef3c7;color:#b45309;} .sp-lib .li small{color:#9ca3af;font-weight:800;}
.sp-acc{margin-top:10px;background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden;} .sp-acc > button{display:flex;align-items:center;width:100%;border:0;background:none;padding:12px;font-size:13px;font-weight:900;font-family:inherit;cursor:pointer;text-align:left;} .sp-acc > button span{flex:1;} .sp-acc > button i{font-style:normal;color:#9ca3af;transition:transform .2s;}
.sp-acc.open > button i{transform:rotate(90deg);} .sp-acc .bd2{display:none;padding:0 6px 8px;} .sp-acc.open .bd2{display:block;} .sp-acc .bd2 > .card{box-shadow:none;margin:0;max-width:none !important;}
@media (max-width:360px){.sp-dd{grid-template-columns:1fr;} .sp-hero .big{font-size:30px;} .sp-q small{font-size:11px;}}
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  // ------------------------------------------------------------------ helpers
  const TIERS = [[0, '🌱', 'Rookie'], [4, '⚡', 'Rising'], [6, '🔥', 'Pro'], [7.5, '⭐', 'Star'], [9, '👑', 'Legend']];
  function level(score) {
    if (score == null) return null;
    let i = 0; TIERS.forEach((t, k) => { if (score >= t[0]) i = k; });
    const lo = TIERS[i][0], hi = TIERS[i + 1] ? TIERS[i + 1][0] : null;
    return { i, emoji: TIERS[i][1], name: TIERS[i][2], pct: hi == null ? 100 : Math.max(4, Math.round((score - lo) / (hi - lo) * 100)), next: hi, score };
  }
  const first = (n) => String(n || '').trim().split(/\s+/)[0] || 'there';
  const initials = (n) => String(n || '?').trim().charAt(0).toUpperCase();
  const COL = ['#f59e0b', '#3b82f6', '#a855f7', '#ec4899', '#14b8a6'];
  const clock = (v) => v ? new Date(String(v).replace(' ', 'T')).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '';
  function countUp(el, to, fmt) {
    if (!el) return;
    const from = Number(el.dataset.v);
    el.dataset.v = to;
    if (reduce || !isFinite(from) || from === to) { el.textContent = fmt(to); return; }
    const t0 = performance.now();
    const step = (tm) => { const k = Math.min(1, (tm - t0) / 800); el.textContent = fmt(from + (to - from) * k * (2 - k)); if (k < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  }

  // ------------------------------------------------------------------ state
  const P = { rating: null, cmp: {}, cmpAt: {}, per: 'today', tab: null, key: '', ready: false, day: null, learn: null, lz: null, prevOrders: null, lastSales: null };
  let root = null, panes = {};
  const TABS = [['cmp', '📊 Compare'], ['learn', '🎓 Learn'], ['rate', '⭐ Rating'], ['month', '📅 Month']];

  function build() {
    const flow = document.querySelector('.ms-flow'); if (!flow || root) return !!root;
    flow.classList.add('sp-on');
    root = document.createElement('div'); root.id = 'spRoot';
    root.innerHTML = '<div id="spTop"></div><div id="spPunch"></div><div id="spMeet"></div><div id="spSum"></div><div id="spHero"></div><div id="spStart"></div><div id="spQuests"></div><div id="spMys"></div><div id="spLesson"></div><div id="spGap"></div><div id="spMove"></div><div id="spRank"></div>' +
      '<div class="sp-tabs" id="spTabs">' + TABS.map((t) => `<button type="button" data-t="${t[0]}">${t[1]}</button>`).join('') + '</div>' +
      TABS.map((t) => `<div class="sp-pane" id="spP_${t[0]}"></div>`).join('');
    const box = $('alertBox');
    if (box && box.parentNode === flow) flow.insertBefore(root, box.nextSibling); else flow.insertBefore(root, flow.firstChild);
    TABS.forEach((t) => { panes[t[0]] = $('spP_' + t[0]); });
    // Learn tab frame: lesson on top, then the three old cards folded away
    panes.learn.innerHTML = '<div id="spLz"></div><div id="spLib" class="sp-lib"></div>' +
      '<div class="sp-acc" id="spA_tips"><button type="button"><span>💡 Today’s coach tips</span><i>›</i></button><div class="bd2"></div></div>' +
      '<div class="sp-acc" id="spA_chat"><button type="button"><span>💬 Chat coach — upload your chats</span><i>›</i></button><div class="bd2"></div></div>' +
      '<div class="sp-acc" id="spA_price"><button type="button"><span>💲 Price charts &amp; checker</span><i>›</i></button><div class="bd2"></div></div>';
    panes.learn.querySelectorAll('.sp-acc > button').forEach((b) => { b.onclick = () => b.parentNode.classList.toggle('open'); });
    $('spTabs').onclick = (e) => { const b = e.target.closest('button[data-t]'); if (b) openTab(b.dataset.t); };
    $('spRank').onclick = () => { openTab('cmp'); scrollTabs(); };
    $('spMeet').onclick = () => { const m = $('spMeet').firstElementChild; if (m) m.classList.toggle('open'); };
    buildSheet(); buildModal();
    return true;
  }
  const scrollTabs = () => { const t = $('spTabs'); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); };

  // the existing cards move into their tabs (they keep their ids, so the old scripts keep painting them)
  function relocate() {
    const mv = (id, host) => { const el = $(id), h = typeof host === 'string' ? (panes[host] || $(host)) : host; if (el && h && el.parentNode !== h) h.appendChild(el); };
    mv('rateCard', 'rate');
    const tips = $('spA_tips'), chat = $('spA_chat'), price = $('spA_price');
    if (tips) { mv('coachCard', tips.querySelector('.bd2')); mv('shMis', tips.querySelector('.bd2')); }
    if (chat) mv('chatCard', chat.querySelector('.bd2'));
    if (price) mv('shPrice', price.querySelector('.bd2'));
    ['otWrap', 'statGrid', 'qcCard', 'detailCard', 'arCard'].forEach((id) => mv(id, 'month'));
  }
  function openTab(t) {
    if (!panes[t]) t = 'cmp';
    P.tab = t; ls.set('sp_tab2', t);
    document.querySelectorAll('#spTabs button').forEach((b) => b.classList.toggle('on', b.dataset.t === t));
    Object.keys(panes).forEach((k) => panes[k].classList.toggle('on', k === t));
    if (t === 'cmp') loadCmp(P.per);
    if (t === 'learn') { loadLearn(); }
  }

  // ------------------------------------------------------------------ data pieces
  function mine() {
    const d = S.coach; if (!d || !d.me) return null;
    const m = d.me, qi = d.qinfo || null, T = Number(m.target) || 0, sales = Number(m.sales) || 0;
    let L = Array.isArray(m.ladder) ? m.ladder.filter((x) => Number(x.sales) > 0) : [];
    if (!L.length && T > 0) L = [{ sales: T, bonus: Number(m.bonus) || 0, hit: sales >= T }];
    const hasRs = L.length > 0 && L.every((x) => x.total != null);
    const exA = qi && qi.exp_arr != null ? Number(qi.exp_arr) : null;
    const pace = (T > 0 && exA != null && exA >= 5 && ((qi && qi.arrive != null) || sales > 0)) ? (sales / T * 100) / exA : null;
    return { d, m, qi, T, sales, L, hasRs, exA, pace, pct: T > 0 ? sales / T * 100 : 0, done: T > 0 && sales >= T, ct: S.ct && S.ct.me ? S.ct.me : null, tm: (S.ct && S.ct.team) || {},
      orders: Number(m.orders) || 0, yS: Number(m.yest_same) || 0, morning: (Number(m.orders) || 0) === 0 && sales === 0 };
  }
  const proofPending = (x) => { const pr = (x.ct && x.ct.proof) || {}; return (pr.orders_no_chat || 0) + (pr.pending_queries || 0); };
  function allDone(x) {
    if (!x.done) return false;
    const codeOk = !(Number(x.m.code_need) > 0) || x.m.code_ok;
    const qOk = !x.qi || !(Number(x.qi.queries) > 0) || Number(x.qi.need_more) <= 0;
    return codeOk && qOk && proofPending(x) === 0 && x.L.every((l) => l.hit);
  }
  const streakN = (x) => (P.day && P.day.streak ? Number(P.day.streak.n) || 0 : Number(x.m.streak) || 0);

  // ------------------------------------------------------------------ punch in / out (pop-up with the attendance page)
  let sheet = null, sheetTimer = null;
  function buildSheet() {
    sheet = document.createElement('div'); sheet.className = 'sp-sheet'; sheet.id = 'spSheet';
    sheet.innerHTML = '<div class="bd"></div><div class="pn" role="dialog" aria-modal="true" aria-label="Punch"><div class="hd"><b>⏱ Punch in / out</b><button type="button" id="spSheetX">✕ Close</button></div></div>';
    document.body.appendChild(sheet);
    sheet.querySelector('.bd').onclick = closeSheet; $('spSheetX').onclick = closeSheet;
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && sheet.classList.contains('on')) closeSheet(); });
  }
  function refreshPunch() { return api.get('attendance.today').then((d) => { S.punch = d && d.attendance ? d.attendance : {}; }).catch(() => {}); }
  function openSheet() {
    let f = sheet.querySelector('iframe');
    if (!f) { f = document.createElement('iframe'); f.title = 'Attendance'; f.setAttribute('allow', 'camera; microphone; geolocation'); sheet.querySelector('.pn').appendChild(f); }
    f.src = 'attendance.html?in=sales';
    sheet.classList.add('on'); document.body.style.overflow = 'hidden';
    clearInterval(sheetTimer); sheetTimer = setInterval(refreshPunch, 15000);
  }
  function closeSheet() {
    sheet.classList.remove('on'); document.body.style.overflow = ''; clearInterval(sheetTimer);
    const f = sheet.querySelector('iframe'); if (f) f.src = 'about:blank';
    refreshPunch();
  }

  // ------------------------------------------------------------------ paint: header, punch, strip, summary
  function paintTop(x) {
    const p = S.punch, h = new Date().getHours();
    const hello = x.morning && h < 12 ? 'Good morning' : h >= 17 ? 'Good evening' : 'Hi';
    let pc = '';
    if (p) pc = !p.punch_in ? '<button type="button" class="sp-chip warn" data-punch="1">⏰ Punch in →</button>' : p.punch_out ? `<button type="button" class="sp-chip" data-punch="1">✓ Day done · ${esc(clock(p.punch_out))}</button>` : `<button type="button" class="sp-chip ok" data-punch="1" title="Tap to punch out">🟢 In ${esc(clock(p.punch_in))}</button>`;
    const sk = P.day && P.day.streak, streak = streakN(x), lv = level(P.rating);
    const fz = sk && sk.freeze_left ? ' · 🧊 1 freeze left this month' : '';
    $('spTop').innerHTML = `<div class="sp-top"><div class="sp-hi">${hello} ${esc(first(x.m.name))} ${x.morning && h < 12 ? '☀️' : '👋'}<small>${p && p.punch_in ? 'Punched in ' + esc(clock(p.punch_in)) : 'Let’s make today count'}</small></div>${pc}
      <span class="sp-chip ${streak > 0 ? 'fire' : 'mute'}" title="Days in a row you hit your target${esc(fz)}">🔥 ${streak}</span>
      <button type="button" class="sp-chip" id="spSnd" title="Sound">${S.sound ? '🔔' : '🔕'}</button>
      ${lv ? `<div class="sp-lv" title="Level from your rating this month (${lv.score.toFixed(1)}/10)"><b>${lv.emoji} ${lv.name}${lv.next ? ' · ' + lv.score.toFixed(1) + '/' + lv.next : ''}</b><i><u style="width:${lv.pct}%"></u></i></div>` : ''}</div>`;
    const sb = $('spSnd'); if (sb) sb.onclick = () => { S.sound = !S.sound; ls.set('sh_sound', S.sound ? '1' : '0'); sb.textContent = S.sound ? '🔔' : '🔕'; if (S.sound && SH.fx) SH.fx.chime(); };
    $('spTop').querySelectorAll('[data-punch]').forEach((b) => { b.onclick = openSheet; });
    // big punch-in card until the person has punched in
    $('spPunch').innerHTML = p && !p.punch_in ? '<div class="sp-punch"><div class="ic">⏰</div><div class="tx">You have not punched in yet<small>Punch in to start your day and your target.</small></div><button type="button" id="spPunchGo">Punch in</button></div>' : '';
    const g = $('spPunchGo'); if (g) g.onclick = openSheet;
  }

  function paintMeet() {
    const al = ((S.ct && S.ct.alerts) || []).filter((a) => a.meet), box = $('spMeet');
    if (!al.length) { box.innerHTML = ''; return; }
    const open = box.firstElementChild && box.firstElementChild.classList.contains('open');
    box.innerHTML = `<div class="sp-meet ${open ? 'open' : ''}"><div class="r"><b>🤝</b><span>${al.length === 1 ? '1 thing' : al.length + ' things'} to meet Admin about</span><b>›</b></div><div class="l">${al.map((a) => `<p>• ${esc(a.gu || a.title)}</p>`).join('')}</div></div>`;
  }

  function sumCard(s, title, sub, tail) {
    if (!s) return '';
    const best = s.best_hour ? `<div class="rw">🏆 <span><b>Best moment:</b> ${esc(new Date(2000, 0, 1, s.best_hour.h).toLocaleTimeString('en-IN', { hour: 'numeric', hour12: true }))}, ${s.best_hour.n} orders in one hour</span></div>` : '';
    const miss = s.missed_code > 0 ? `<div class="rw miss">💸 <span><b>Missed:</b> ${rs(s.missed_code)} — orders without a code</span></div>` : '<div class="rw">✅ <span><b>Nothing slipped</b> — every rupee collected</span></div>';
    const mv = `<div class="rw">👉 <span><b>Tomorrow’s one move:</b> ${esc(s.move.text)}${s.move.worth ? ' — worth ' + rs(s.move.worth) : ''}</span></div>`;
    return `<div class="sp-sum"><h3>${title}<small>${sub}</small></h3><div class="g3"><div><b>${rs(s.sales)}</b><span>earned${s.pct != null ? ' · ' + s.pct + '%' + (s.hit ? ' 🎯' : '') : ''}</span></div><div><b>${rs(s.com)}</b><span>commission</span></div><div><b>${s.rank ? '#' + s.rank : '—'}</b><span>of ${s.of || '—'}</span></div></div>${best}${miss}${mv}${tail || ''}</div>`;
  }
  function paintSummary(x) {
    const box = $('spSum'), d = P.day; if (!d) { box.innerHTML = ''; return; }
    const sk = d.streak || {}, fz = sk.freeze_left ? `<div class="sp-fr">🧊 Streak freeze: <b>1 left this month</b> — a day that ends at 90%+ of target keeps your streak alive.</div>` : '<div class="sp-fr">🧊 Streak freeze used this month — hit your target to keep the streak.</div>';
    if (S.punch && S.punch.punch_out && d.now && d.now.sales > 0) { box.innerHTML = sumCard(d.now, '🌙 Your day in 3 lines', 'Today’s business day (ends 10 AM)', fz); return; }
    if (x.morning && d.yday && d.yday.sales > 0) { box.innerHTML = sumCard(d.yday, '🌅 Yesterday in 3 lines', 'Use it to beat today’s start', fz); return; }
    box.innerHTML = '';
  }

  // ------------------------------------------------------------------ hero, head start, rings, mystery box, lesson, gap, move, rank
  function nextLine(x) {
    const { T, sales, L, hasRs } = x, left = Math.max(0, T - sales);
    if (!(T > 0)) return 'Your target will appear here once it is set.';
    if (x.morning) {
      if (x.yS > 0) return `🎯 Beat yesterday: <b>${rs(x.yS)}</b> by now puts you ahead of your own pace`;
      const y = P.day && P.day.yday; if (y && y.sales > 0) return `🎯 Yesterday you made <b>${rs(y.sales)}</b> — beat it today`;
      return `🎁 <b>${rs(T)}</b> today — your first order starts the race`;
    }
    if (!x.done) {
      const fst = L[0], un = fst && Number(fst.bonus) > 0 ? `commission <b>+${Number(fst.bonus)}%</b> unlocks` : 'your target bonus unlocks';
      return `🎁 <b>${rs(left)}</b> to target → ${un}`;
    }
    const nx = L.findIndex((l) => !l.hit);
    if (nx < 0) return '🏆 <b>Every step unlocked</b> — you are at the top level. Keep stacking!';
    const l = L[nx], more = Math.max(0, l.sales - sales);
    return `🚀 <b>${rs(more)}</b> more reaches <b>${kf(l.sales)}</b> → ${hasRs ? `commission <b>${rs(x.m.com_today)} → ${rs(l.total)}</b>` : `<b>+${Number(l.bonus)}%</b> extra on every order`}`;
  }

  function paintHero(x) {
    const { m, T, sales, L, qi } = x, gold = allDone(x);
    const nxI = L.findIndex((l) => !l.hit), nxL = nxI >= 0 ? L[nxI] : null;
    const com = Number(m.com_today) || 0;
    const comTxt = nxL && x.hasRs && Number(nxL.total) > com ? `${rs(com)}<em> → ${rs(nxL.total)}</em>` : rs(com);
    const top = L.length ? L[L.length - 1].sales : T, scale = x.done && L.length > 1 ? top : T;
    const f = scale > 0 ? Math.max(0, Math.min(100, sales / scale * 100)) : 0;
    let marks = '', mile = '';
    if (!x.done || L.length < 2) {
      marks = [25, 50, 75].map((p) => `<span class="n" style="left:${p}%"></span>`).join('');
      mile = [25, 50, 75, 100].map((p) => { const on = x.pct >= p; return `<span class="${on ? 'on' : ''}">${p}%${on ? ' ✓' : p === 100 ? ' 🎁' : ''}</span>`; }).join('');
    } else {
      marks = L.map((l) => `<span class="n" style="left:${l.sales / scale * 100}%"></span>`).join('');
      mile = L.map((l, i) => `<span class="${l.hit ? 'on' : ''}">${i === 0 ? '🎯' : '+' + Math.round((l.sales / T - 1) * 100) + '%'}${l.hit ? ' ✓' : ''}</span>`).join('');
    }
    const tick = (!x.done && x.pace != null) ? Math.max(0, Math.min(100, x.exA)) : null;
    const ghost = (!x.done && x.yS > 0 && scale > 0) ? Math.max(1, Math.min(99, x.yS / scale * 100)) : null;
    const rsv = qi && qi.rs != null ? Number(qi.rs) : null, rmv = qi && qi.rm != null ? Number(qi.rm) : null;
    const rsC = rsv == null ? '' : rsv >= 7 ? 'g' : rsv >= 5 ? 'a' : 'r', pcC = x.pace == null ? '' : x.pace >= 0.95 ? 'g' : x.pace >= 0.7 ? 'a' : 'r';
    let pj = '';
    if (x.pace != null && x.done && x.exA >= 25) { const v = sales / (x.exA / 100); pj = '≈ ' + rs(Math.round(v / 100) * 100) + ' finish'; }
    const paceTxt = x.pace == null ? 'starts after your first sale' : pj || (x.pace >= 0.95 ? 'ahead of time' : x.pace >= 0.7 ? 'a little behind' : 'behind — push now');
    const steps = L.length > 1 || (L.length === 1 && x.done) ? `<div class="sp-steps">${L.map((l, i) => `<div class="${l.hit ? 'on' : i === nxI ? 'nx' : ''}">${l.hit ? '✅' : i === nxI ? '👉' : '🔒'} ${kf(l.sales)}<small>${Number(l.bonus) > 0 ? '+' + Number(l.bonus) + '%' : 'target'}</small></div>`).join('')}</div>` : '';
    const capCls = ghost != null && tick != null ? ' two' : '';
    const cap = (tick != null ? `<span style="left:${Math.max(14, Math.min(86, tick))}%">▲ should be here now</span>` : '') + (ghost != null ? `<span class="b" style="left:${Math.max(16, Math.min(84, ghost))}%">┆ yesterday by now</span>` : '');
    $('spHero').innerHTML = `<div class="sp-hero ${gold ? 'gold' : ''}">
      <div class="row"><div><div class="lab">${gold ? '🏆 Everything done today' : 'Today’s race'}</div><div class="big"><span id="spEarn" data-v="${Math.round(sales)}">${rs(sales)}</span>${T > 0 ? `<small>of ${rs(T)}</small>` : ''}</div></div>
        <div class="com"><div class="lab">Commission</div><div class="v">${comTxt}</div></div></div>
      ${T > 0 ? `<div class="sp-bar">${f > 0 ? `<div class="f" style="width:${f}%"></div>` : ''}${marks}${ghost != null ? `<span class="g" style="left:${ghost}%"></span>` : ''}${tick != null ? `<span class="t" style="left:${tick}%"></span>` : ''}</div>
        <div class="sp-cap${capCls}">${cap}</div><div class="sp-mile">${mile}</div>` : ''}
      <div class="sp-chips"><div class="c ${rsC}"><b>⚡ ${rsv != null ? rsv.toFixed(1) + '<i>/10</i>' : '—'}</b><span>Reply speed${rsv != null ? (rmv != null ? ' · ' + Math.round(rmv) + ' min' : '') : ' · no replies yet'}</span></div>
        <div class="c ${pcC}"><b>🏃 ${x.pace != null ? x.pace.toFixed(2) + '×' : '—'}</b><span>Pace · ${esc(paceTxt)}</span></div></div>
      <div class="sp-next">${nextLine(x)}</div>${steps}</div>`;
  }

  function paintStart(x) {
    const box = $('spStart'); if (!x.morning) { box.innerHTML = ''; return; }
    const p = S.punch || {}, sk = streakN(x);
    const it = [[!!p.punch_in, 'Punched in' + (p.punch_in ? ' at ' + clock(p.punch_in) : '')], [sk > 0, sk > 0 ? `Streak safe — ${sk} day${sk === 1 ? '' : 's'} 🔥` : 'Start a streak: hit today’s target'], [proofPending(x) === 0 && S.ct != null, 'Proofs up to date'], [false, 'First order → opens the mystery box']];
    box.innerHTML = `<div class="sp-card sp-start"><h4>Your head start <em>${it.filter((a) => a[0]).length} of 4 done ✓</em></h4>${it.map((a) => `<div class="it ${a[0] ? '' : 'no'}"><i>${a[0] ? '✓' : '○'}</i>${esc(a[1])}</div>`).join('')}</div>`;
  }

  function paintQuests(x) {
    if (!$('spQuests')) return;
    const { m, qi } = x, tm = x.tm, ct = x.ct || {};
    const n = x.orders;
    const ring = (ico, color, p, big, lab, done, id) => `<div class="sp-q ${done ? 'done' : ''}" data-q="${id}"><div class="sp-ring" style="--p:${Math.max(0, Math.min(100, p))};--c:${color}"><span>${ico}</span></div><b>${big}</b><small>${lab}</small></div>`;
    const out = [];
    out.push(x.T > 0 ? ring('🎯', '#22c55e', x.pct, x.done ? '✓' : Math.round(x.pct) + '%', 'Target', x.done, 'target') : ring('🎯', '#22c55e', 0, '—', 'Target', false, 'target'));
    const need = Number(m.code_need) || 0, have = Number(m.code) || 0;
    out.push(need > 0 ? ring('🔑', '#f59e0b', have / need * 100, have + '/' + need, 'Codes', !!m.code_ok, 'code') : ring('🔑', '#f59e0b', 0, '—', 'Codes', false, 'code'));
    const qo = qi ? Number(qi.query_orders) || 0 : 0, go = qi ? Number(qi.goal_orders) || 0 : 0, q = qi ? Number(qi.queries) || 0 : 0;
    out.push(go > 0 ? ring('🎧', '#3b82f6', qo / go * 100, qo + '/' + go, 'Queries', qo >= go, 'query') : ring('🎧', '#3b82f6', 0, q > 0 ? String(qo) : '—', 'Queries', false, 'query'));
    const co = qi ? Number(qi.community_orders) || 0 : 0, people = Number((S.coach && S.coach.team && S.coach.team.people) || 0), share = tm.comm_target && people > 0 ? Math.max(1, Math.ceil(tm.comm_target / people)) : 0;
    out.push(ring('📣', '#a855f7', share ? co / share * 100 : (co > 0 ? 100 : 0), String(co), 'Campaign', share > 0 && co >= share, 'camp'));
    const np = proofPending(x), tot = Math.max(1, n + ((ct.proof || {}).pending_queries || 0));
    out.push(ring('📎', '#ef4444', np ? (1 - np / tot) * 100 : 100, np ? String(np) : '✓', 'Proof', np === 0 && S.ct != null && n > 0, 'proof'));
    const ld = P.learn, dn = !!(ld && ld.done_today);
    out.push(ring('🎓', '#0ea5e9', dn ? 100 : 0, dn ? '✓' : '5 min', 'Learn', dn, 'learn'));
    $('spQuests').innerHTML = `<div class="sp-quests">${out.join('')}</div>`;
    $('spQuests').onclick = (e) => {
      const el = e.target.closest('[data-q]'); if (!el) return; const k = el.dataset.q;
      if (k === 'proof') { const g = $('shProofGo'); if (g) g.click(); else { openTab('learn'); const a = $('spA_tips'); if (a) a.classList.add('open'); scrollTabs(); } }
      else if (k === 'learn') { openTab('learn'); scrollTabs(); }
      else if (k === 'camp' || k === 'query') { openTab('cmp'); scrollTabs(); }
      else { openTab('learn'); const a = $('spA_tips'); if (a) a.classList.add('open'); scrollTabs(); }
    };
  }

  // 🎁 mystery box: locked until the first order, opens with one true surprise a day, stays as a small card afterwards
  const boxKey = () => 'sp_box_' + ((S.coach && S.coach.business_day) || 'x');
  function paintMys(x) {
    const box = $('spMys'), d = P.day && P.day.box; if (!d) { box.innerHTML = ''; return; }
    const opened = ls.get(boxKey());
    if (opened) { box.innerHTML = `<div class="sp-mys open"><div class="bx">${esc(d.icon)}</div><div class="tx"><small>🎁 Today’s surprise · ${esc(d.title)}</small>${esc(d.text)}</div></div>`; return; }
    if (x.morning) { box.innerHTML = '<div class="sp-mys"><div class="bx">🎁</div><div class="tx"><small>Mystery box · locked</small>Land your <b style="color:#fde047">first order</b> and open today’s surprise. Something new every day.</div></div>'; return; }
    box.innerHTML = '<div class="sp-mys ready"><div class="bx">🎁</div><div class="tx"><small>Mystery box · ready</small>Your first order unlocked today’s surprise!</div><button type="button" id="spOpenBox">Open</button></div>';
    const b = $('spOpenBox'); if (b) b.onclick = () => openBox();
  }
  let modal = null;
  function buildModal() {
    modal = document.createElement('div'); modal.className = 'sp-modal'; modal.id = 'spModal';
    modal.innerHTML = '<div class="bx"><div class="e" id="spMe"></div><h3 id="spMh"></h3><p id="spMp"></p><small>A new surprise every day</small><button type="button" id="spMx">Nice!</button></div>';
    document.body.appendChild(modal); $('spMx').onclick = () => { modal.classList.remove('on'); render(); };
  }
  function openBox() {
    const d = P.day && P.day.box; if (!d || ls.get(boxKey())) return;
    ls.set(boxKey(), '1');
    $('spMe').textContent = d.icon; $('spMh').textContent = '🎁 Mystery box opened!'; $('spMp').innerHTML = '<b>' + esc(d.title) + '</b><br>' + esc(d.text);
    modal.classList.add('on');
    try { if (navigator.vibrate) navigator.vibrate([80, 40, 80, 40, 160]); } catch (e) { /* phones only */ }
    if (SH.fx) { SH.fx.confetti(); SH.fx.chime(); }
    paintMys(mine() || { morning: false });
  }

  function paintLesson() {
    const box = $('spLesson'), ld = P.learn; if (!box) return; if (!ld || !ld.applicable) { box.innerHTML = ''; return; }
    if (ld.all_done) { box.innerHTML = '<div class="sp-lesson done"><div class="ic">🏅</div><div class="tx">You finished the whole library!<small>Revisit any lesson in the Learn tab</small></div><span class="go">Open ›</span></div>'; box.onclick = () => { openTab('learn'); scrollTabs(); }; return; }
    const l = ld.lesson, dn = ld.done_today;
    box.innerHTML = `<div class="sp-lesson ${dn ? 'done' : ''}"><div class="ic">${dn ? '✅' : '🎓'}</div><div class="tx">${dn ? 'Lesson done today' : 'Today’s ' + l.mins + '-min lesson'}: ${esc(l.title)}<small>${dn ? 'Learning streak ' + ld.streak + ' day' + (ld.streak === 1 ? '' : 's') + ' · optional: pick another' : (ld.why ? 'Chosen because ' + esc(ld.why) + ' is your biggest gap' : 'Read + 3 questions + one action')}</small></div><span class="go">${dn ? 'More ›' : 'Start ›'}</span></div>`;
    box.onclick = () => { openTab('learn'); scrollTabs(); };
  }

  function paintGap() {
    const box = $('spGap'), c = P.cmp.today, bg = c && c.overall ? c.overall.biggest : null;
    if (!box) return;
    if (!bg) { box.innerHTML = ''; return; }
    const pt = (c.points || []).find((p) => p.k === bg.k) || {};
    const wt = pt.worth > 0 ? `Fixing it is worth <b class="w">${rs(pt.worth)}</b> today` : esc(bg.text);
    box.innerHTML = `<div class="sp-gap"><div class="tx">⚠️ Biggest gap: <b>${esc(bg.label)}</b> — you are #${bg.rank} of ${bg.of}<small>${pt.you != null ? 'Team avg ' + fmtV(pt, pt.avg) + ' · you ' + fmtV(pt, pt.you) + '. ' : ''}${wt}</small></div><span class="go">See ›</span></div>`;
    box.onclick = () => { openTab('cmp'); scrollTabs(); };
  }

  // next best move = the action worth the most ₹ (ties: the order below)
  function moves(x) {
    const { m, qi, T, sales, L } = x, ct = x.ct || {}, tv = ct.tv || {}, com = Number(m.com_today) || 0;
    const tk = Number(m.ticket) > 0 ? Number(m.ticket) : 1500, left = Math.max(0, T - sales), away = left > 0 ? Math.ceil(left / tk) : 0;
    const nxI = L.findIndex((l) => !l.hit), nx = nxI >= 0 ? L[nxI] : null, jump = nx && x.hasRs && Number(nx.total) > com ? Math.round(Number(nx.total) - com) : null;
    const o = [];
    if (!m.code_ok && Number(m.code_left) > 0 && x.orders > 0) o.push({ ic: '🔑', w: Number(m.code_lost) > 0 ? Math.round(Number(m.code_lost)) : null, txt: `Add a code to any <b>${Number(m.code_left) === 1 ? 'order' : m.code_left + ' orders'}</b> — every order then pays the full commission` });
    if (T > 0 && !x.done && away > 0) o.push({ ic: away <= 3 ? '🎯' : '🎁', w: jump, act: 'alloc', txt: away <= 3 ? `Only <b>${away === 1 ? '1 order' : away + ' orders'}</b> (${rs(left)}) to reach the target → commission <b>+${Number((L[0] || {}).bonus) || 20}%</b> unlocks` : `<b>${rs(left)}</b> to the target → commission <b>+${Number((L[0] || {}).bonus) || 20}%</b> unlocks` });
    if (x.done && nx) o.push({ ic: '🚀', w: jump, act: 'alloc', txt: `<b>${rs(Math.max(0, nx.sales - sales))}</b> more reaches <b>${kf(nx.sales)}</b> → ${x.hasRs ? `commission <b>${rs(com)} → ${rs(nx.total)}</b>` : `<b>+${Number(nx.bonus)}%</b> on every order`}` });
    if (tv.need_next && tv.next) o.push({ ic: '🎟️', w: null, txt: `Your next order of <b>${rs(tv.need_next)}</b> lifts the average ticket to <b>${rs(tv.next)}</b>` });
    if (qi && Number(qi.need_more) > 0 && Number(qi.queries) > 0) o.push({ ic: '🎧', w: null, txt: `<b>${qi.need_more} more query order${Number(qi.need_more) === 1 ? '' : 's'}</b> reaches the ${qi.goal_pct}% goal — call the open queries first` });
    const np = proofPending(x); if (np > 0) o.push({ ic: '📎', w: null, act: 'proof', txt: `<b>${np}</b> chat proof${np === 1 ? '' : 's'} pending — add them to finish your day` });
    if (x.morning) o.unshift({ ic: '💬', w: null, txt: 'Reply to your first query now — <b>fast replies</b> win the sale' });
    o.forEach((a, i) => { a.i = i; });
    o.sort((a, b) => (b.w || 0) - (a.w || 0) || a.i - b.i);
    return o;
  }
  function paintMove(x) {
    const o = moves(x), b = o[0] || { ic: '🏆', w: null, txt: 'All done — every extra order now earns at the top level. Keep stacking!' }, nx = o[1];
    const canAlloc = b.act === 'alloc' && S.canAlloc && typeof SH.open === 'function';
    $('spMove').innerHTML = `<div class="sp-move"><div class="ic">${b.ic}</div><div class="tx"><small>Next best move${o.length > 1 ? ' · highest ₹ first' : ''}</small>${b.txt}${b.w ? `<span class="sp-worth">worth ${rs(b.w)}</span>` : ''}${nx ? `<span class="nx2">Then: ${nx.txt.replace(/<\/?b>/g, '')}${nx.w ? ' (' + rs(nx.w) + ')' : ''}</span>` : ''}</div>${canAlloc ? '<button type="button" id="spGo">Add order</button>' : b.act === 'proof' ? '<button type="button" id="spGo">Add proof</button>' : ''}</div>`;
    const g = $('spGo'); if (g) g.onclick = () => { if (b.act === 'proof') { const p = $('shProofGo'); if (p) p.click(); } else SH.open(); };
  }

  function paintRank(x) {
    const c = P.cmp.today, box = $('spRank'); if (!box) return;
    const pt = c && c.points ? c.points.find((p) => p.k === 'sales') : null;
    if (!pt || pt.rank == null || !pt.rows || pt.rows.length < 2) { box.innerHTML = ''; return; }
    const rows = pt.rows, me = rows.find((r) => r.me), other = rows.filter((r) => !r.me), tk = x && Number(x.m.ticket) > 0 ? Number(x.m.ticket) : 1500;
    const day = (S.coach && S.coach.business_day) || 'x', kk = 'sp_r_' + day;
    if (!ls.get(kk)) ls.set(kk, String(pt.rank));
    const was = Number(ls.get(kk)) || pt.rank, climbed = was - pt.rank;
    let head, sub;
    if (pt.rank === 1) { head = `You lead by ${rs(me.v - other[0].v)}`; sub = other.slice(0, 2).map((r, i) => `${esc(first(r.name))} #${i + 2}`).join(' · '); }
    else { const up = pt.up || { name: rows[0].name, gap: rows[0].v - me.v }, n = Math.max(1, Math.ceil(up.gap / tk)); head = `${rs(up.gap)} to pass ${esc(first(up.name))} — ${n} more order${n === 1 ? '' : 's'}`; sub = `${esc(first(rows[0].name))} #1 · you #${pt.rank}`; }
    const av = rows.slice(0, 3).map((r, i) => `<i style="background:${COL[i % COL.length]}${r.me ? ';box-shadow:0 0 0 2px #15803d' : ''}">${esc(initials(r.name))}</i>`).join('');
    box.innerHTML = `<div class="sp-rank"><div class="me">#${pt.rank}</div><div class="tx">${head}<small>${climbed > 0 ? `You moved up ${climbed} place${climbed === 1 ? '' : 's'} since morning 📈` : sub} · tap for all</small></div><div class="sp-av">${av}</div></div>`;
  }

  // ------------------------------------------------------------------ moments: 25 / 50 / 75 % of target (100 % + every commission step = the big BOOM in sales_home.js)
  function toast(emoji, head, text) {
    const old = document.querySelector('.sp-toast'); if (old) old.remove();
    const t = document.createElement('div'); t.className = 'sp-toast'; t.innerHTML = `<b>${emoji} ${esc(head)}</b><span>${esc(text)}</span>`;
    document.body.appendChild(t); requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('on')));
    setTimeout(() => { t.classList.remove('on'); setTimeout(() => t.remove(), 500); }, 3600);
  }
  function checkMoments(x) {
    const day = (S.coach && S.coach.business_day) || ''; if (!day || !(x.T > 0)) return;
    const key = (p) => 'sp_m_' + day + '_' + p, MS = [[25, '🔥', 'Quarter done!', '25% of target — great start'], [50, '🎉', 'Halfway there!', '50% of target done — keep going'], [75, '🚀', 'Almost there!', '75% of target — the bonus is close']];
    const passed = MS.filter((m) => x.pct >= m[0]);
    if (!P.ready) { passed.forEach((m) => ls.set(key(m[0]), '1')); return; }          // opening the page never replays an old moment
    const fresh = passed.filter((m) => !ls.get(key(m[0])));
    passed.forEach((m) => ls.set(key(m[0]), '1'));
    if (!fresh.length || x.pct >= 100) return;
    const m = fresh[fresh.length - 1];
    toast(m[1], m[2], m[3]);
    try { if (navigator.vibrate) navigator.vibrate([60, 40, 60]); } catch (e) { /* phones only */ }
    if (SH.fx) { SH.fx.confetti(); SH.fx.chime(); }
  }

  // ------------------------------------------------------------------ 📊 Compare
  async function loadCmp(per, force) {
    const age = Date.now() - (P.cmpAt[per] || 0);
    if (P.cmp[per] && age < (per === 'today' ? 60000 : 180000) && !force) { if (per === P.per && P.tab === 'cmp') paintCmp(); return; }
    if (!P.cmp[per] && P.tab === 'cmp' && per === P.per) panes.cmp.innerHTML = '<div class="sp-msg">Loading the comparison…</div>';
    try { const d = await api.get('my.compare', { p: per }); P.cmp[per] = d; P.cmpAt[per] = Date.now(); }
    catch (e) { if (!P.cmp[per] && P.tab === 'cmp' && per === P.per) panes.cmp.innerHTML = '<div class="sp-msg">Could not load the comparison. Pull to refresh and try again.</div>'; return; }
    if (per === 'today') { paintRank(mine()); paintGap(); }
    if (per === P.per && P.tab === 'cmp') paintCmp();
  }
  const fmtV = (p, v) => {
    if (v == null) return '—';
    if (p.unit === 'rs') return rs(v);
    if (p.unit === 'pct') return Math.round(v) + '%';
    if (p.unit === 'score') return Number(v).toFixed(1) + ' /10';
    return String(Math.round(v));
  };
  const pcOf = (p) => (p.rank != null && p.of > 1 ? (p.of - p.rank) / (p.of - 1) : 1);
  function rkCls(p) { if (p.rank == null) return 'n'; if (p.rank === 1 && p.state !== 'gap') return 'g'; const pc = pcOf(p); return pc >= 0.6 ? 'g' : pc >= 0.3 ? 'a' : 'r'; }
  const colOf = (p) => (p.state === 'win' || p.state === 'ok' ? '#16a34a' : rkCls(p) === 'a' ? '#d97706' : '#dc2626');
  function cardOf(p) {
    const mx = Math.max(Number(p.you) || 0, Number(p.best.v) || 0, Number(p.avg) || 0, p.unit === 'score' ? 10 : 0.0001);
    const pos = (v) => Math.max(2, Math.min(98, (Number(v) || 0) / mx * 100)), col = colOf(p);
    const mn = p.k === 'reply' && p.you_min != null ? ' · ' + Math.round(p.you_min) + ' min' : '';
    const bestMin = p.k === 'reply' ? (p.rows.find((r) => r.name === p.best.name) || {}).min : null;
    const ok = p.state === 'win' || p.state === 'ok';
    const lines = p.you == null ? `<div class="sp-do n">No ${esc(p.label.toLowerCase())} data for you yet in this period.</div>`
      : `<div class="sp-do y">✅ ${esc(p.do)}${p.worth > 0 ? ` <span class="sp-worth">worth ${rs(p.worth)}</span>` : ''}</div>${!ok && p.dont ? `<div class="sp-do n" style="margin-top:4px">⛔ ${esc(p.dont)}</div>` : ''}`;
    const rows = p.rows.map((r) => `<div class="rw ${r.me ? 'me' : ''}"><i>${r.rank}</i><span>${esc(r.name)}${r.me ? ' (you)' : ''}</span><em>${fmtV(p, r.v)}${r.min != null ? ' · ' + Math.round(r.min) + ' min' : ''}</em></div>`).join('');
    const note = p.k === 'reply' ? `<div class="sp-note">⚡ based on ${p.you_n || 0} reviewed chat${p.you_n === 1 ? '' : 's'} — upload more chats to sharpen it · <a data-act="chat">Upload now</a></div>` : '';
    return `<div class="sp-m"><div class="h"><b>${p.icon} ${esc(p.label)}</b><span class="v">${fmtV(p, p.you)}</span><span class="sp-rkb ${rkCls(p)}">${p.rank ? '#' + p.rank + ' of ' + p.of : '—'}</span></div>
      <div class="sp-trk"><div class="y" style="width:${pos(p.you)}%;background:${col}"></div><span class="avg" style="left:${pos(p.avg)}%"></span><span class="best" style="left:${pos(p.best.v)}%">👑</span><span class="me" style="left:${pos(p.you)}%;color:${col};background:${col}"></span></div>
      <div class="sp-lg"><span>you ${fmtV(p, p.you)}${mn}</span><span>team avg ${fmtV(p, p.avg)}</span><span>👑 ${p.best.me ? 'you' : esc(first(p.best.name))} ${fmtV(p, p.best.v)}${bestMin != null ? ' · ' + Math.round(bestMin) + ' min' : ''}</span></div>
      ${lines}${note}<div class="sp-all"><button type="button">See everyone ▾</button><div class="rows">${rows}</div></div></div>`;
  }
  function paintCmp() {
    const d = P.cmp[P.per], el = panes.cmp; if (!el) return;
    const seg = `<div class="sp-seg" id="spSeg">${[['today', 'Today'], ['week', 'This week'], ['month', 'This month']].map((s) => `<button type="button" data-p="${s[0]}" class="${P.per === s[0] ? 'on' : ''}">${s[1]}</button>`).join('')}</div>`;
    let h = `<div class="sp-top" style="margin-top:0"><div class="sp-hi" style="font-size:15px">📊 How am I doing?<small>You vs the team · people who sold in this period${d && d.from ? ' · ' + (P.per === 'today' ? 'today' : d.from.slice(5) + ' → ' + d.to.slice(5)) : ''}</small></div></div>${seg}`;
    if (!d) { el.innerHTML = h + '<div class="sp-msg">Loading the comparison…</div>'; wireCmp(); return; }
    if (d.applicable === false) { el.innerHTML = h + '<div class="sp-msg">The comparison is for Sales team members.</div>'; wireCmp(); return; }
    if (d.empty || !d.points.length) { el.innerHTML = h + '<div class="sp-msg">No sales activity in this period yet — your numbers appear here after your first order.</div>'; wireCmp(); return; }
    const o = d.overall, pts = d.points;
    const have = pts.filter((p) => p.you != null);
    const gaps = have.filter((p) => p.state === 'gap').sort((a, b) => pcOf(a) - pcOf(b));
    const focus = gaps.slice(0, 3);
    if (focus.length < 3) have.filter((p) => p.state !== 'win' && focus.indexOf(p) < 0).sort((a, b) => pcOf(a) - pcOf(b)).slice(0, 3 - focus.length).forEach((p) => focus.push(p));
    const worth = focus.reduce((s, p) => s + (p.worth > 0 ? p.worth : 0), 0);
    h += `<div class="sp-cmph"><div class="rk">${o.rank ? '#' + o.rank : '—'}<small> of ${o.of}</small></div><div class="tx">You are <b>ahead</b> on ${o.ahead} of ${o.points} points.<br>${focus.length ? (worth > 0 ? `Your top ${focus.length} moves below are worth about <b>${rs(worth)}</b>.` : `Your top ${focus.length} focus point${focus.length === 1 ? '' : 's'} are below.`) : 'No gaps — you are at or above the team average everywhere 🎉'}</div></div>`;
    if (focus.length) h += '<div class="sp-lab">Focus — biggest wins first</div>' + focus.map(cardOf).join('');
    h += '<div class="sp-lab">All ' + pts.length + ' points</div>' + pts.map((p, i) => {
      const mx = Math.max(Number(p.you) || 0, Number(p.best.v) || 0, Number(p.avg) || 0, p.unit === 'score' ? 10 : 0.0001), w = Math.max(2, Math.min(100, (Number(p.you) || 0) / mx * 100));
      return `<div class="sp-rowc" data-i="${i}"><span>${p.icon}</span><b>${esc(p.label)}</b><span class="mb"><u style="width:${w}%;background:${colOf(p)}"></u></span><em>${fmtV(p, p.you)}</em><span class="sp-rkb ${rkCls(p)}">${p.rank ? '#' + p.rank : '—'}</span></div><div class="sp-det" id="spDet${i}">${cardOf(p)}</div>`;
    }).join('');
    if (o.climb) { const c = o.climb, amt = c.unit === 'rs' ? rs(c.gap) : c.unit === 'pct' ? Math.round(c.gap) + ' points' : String(c.gap); h += `<div class="sp-climb">🧗 <b>Closest climb:</b> ${esc(c.label)} — ${amt} more passes ${esc(first(c.name))} and moves you from #${c.from} to #${c.to}. Tap any row for the full list with names.</div>`; }
    const e = d.earn, topv = Math.max(e.top.v, e.you, e.avg, 1);
    h += `<div class="sp-earn"><div class="t">💸 How much am I earning vs the team</div>
      <div class="sp-eb"><span>You</span><i><u style="width:${e.you / topv * 100}%;background:#22c55e"></u></i><em>${rs(e.you)}</em></div>
      <div class="sp-eb"><span>Team avg</span><i><u style="width:${e.avg / topv * 100}%;background:#9ca3af"></u></i><em>${rs(e.avg)}</em></div>
      <div class="sp-eb"><span>${e.top.me ? 'You are top' : '👑 ' + esc(first(e.top.name))}</span><i><u style="width:${e.top.v / topv * 100}%;background:#f59e0b"></u></i><em>${rs(e.top.v)}</em></div>
      ${e.why && e.why.length ? `<div class="sp-lose"><b>Where the money is slipping:</b>${e.why.map((w) => `<p>${w.icon} ${esc(w.text)}</p>`).join('')}</div>` : '<div class="sp-lose" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">Nothing is slipping — you are collecting everything you can 🎉</div>'}</div>`;
    if ((d.dos && d.dos.length) || (d.donts && d.donts.length)) h += `<div class="sp-dd"><div class="y"><h5>✅ Do</h5>${d.dos.map((t) => `• ${esc(t.text)}`).join('<br>')}</div><div class="n"><h5>⛔ Don’t</h5>${d.donts.map((t) => `• ${esc(t.text)}`).join('<br>')}</div></div>`;
    el.innerHTML = h; wireCmp();
  }
  function wireCmp() {
    const el = panes.cmp; if (!el) return;
    const seg = el.querySelector('#spSeg'); if (seg) seg.onclick = (e) => { const b = e.target.closest('button[data-p]'); if (!b || b.dataset.p === P.per) return; P.per = b.dataset.p; paintCmp(); loadCmp(P.per); };
    el.querySelectorAll('.sp-all > button').forEach((b) => { b.onclick = () => { const a = b.parentNode; a.classList.toggle('open'); b.textContent = a.classList.contains('open') ? 'Hide ▴' : 'See everyone ▾'; }; });
    el.querySelectorAll('.sp-rowc').forEach((r) => { r.onclick = () => { const dd = $('spDet' + r.dataset.i); if (dd) dd.classList.toggle('on'); }; });
    el.querySelectorAll('[data-act=chat]').forEach((a) => { a.onclick = () => { openTab('learn'); const c = $('spA_chat'); if (c) c.classList.add('open'); scrollTabs(); }; });
  }

  // ------------------------------------------------------------------ 🎓 Learn
  async function loadLearn(id, force) {
    if (P.learn && !id && !force && Date.now() - (P.learnAt || 0) < 60000) { paintLearn(); return; }
    try {
      const d = await api.get('learn.today', id ? { id } : {});
      if (id) { P.lz = { lesson: d.lesson, phase: 'read', qi: 0, score: 0, picked: null, done: false }; if (!P.learn) P.learn = d; }
      else { P.learn = d; P.learnAt = Date.now(); if (!P.lz || P.lz.lesson.id !== (d.lesson && d.lesson.id) || force) P.lz = d.lesson ? { lesson: d.lesson, phase: d.done_today ? 'done' : 'read', qi: 0, score: 0, picked: null, done: d.done_today } : null; }
    } catch (e) { if (P.tab === 'learn') $('spLz').innerHTML = '<div class="sp-msg">Could not load the lesson. Try again in a moment.</div>'; return; }
    paintLearn(); paintLesson(); const x = mine(); if (x) paintQuests(x);
  }
  function paintLearn() {
    const ld = P.learn, box = $('spLz'); if (!box) return;
    if (!ld || !ld.applicable) { box.innerHTML = ld ? '<div class="sp-msg">Lessons are for Sales team members.</div>' : '<div class="sp-msg">Loading today’s lesson…</div>'; $('spLib').innerHTML = ''; return; }
    const z = P.lz, l = z && z.lesson;
    let h = '';
    if (!l) h = '<div class="sp-ls"><div class="sp-res"><div class="e">🏅</div><h3>You finished the whole library!</h3><p>Pick any lesson below to read it again.</p></div></div>';
    else if (z.phase === 'read') {
      h = `<div class="sp-ls"><div class="tr">${esc(l.track)} · ${l.mins} min${ld.done_today ? ' · done today ✓' : ''}</div><h3>${esc(l.title)}</h3>${ld.why && !ld.done_today && ld.lesson && ld.lesson.id === l.id ? `<div class="why">Chosen because <b>${esc(ld.why)}</b> is your biggest gap</div>` : ''}
        <div class="rd">${l.read.map((t) => `<p>${esc(t)}</p>`).join('')}</div><div class="act"><small>Your action</small>${esc(l.act)}</div><button type="button" class="sp-btn" id="spQz">Take the 3-question quiz →</button></div>`;
    } else if (z.phase === 'quiz') {
      const q = l.quiz[z.qi], pk = z.picked;
      h = `<div class="sp-ls sp-qz"><div class="pg">QUESTION ${z.qi + 1} OF ${l.quiz.length}</div><div class="q">${esc(q.q)}</div>${q.o.map((t, i) => `<button type="button" class="o ${pk == null ? '' : i === q.a ? 'ok' : i === pk ? 'bad' : ''}" data-o="${i}" ${pk != null ? 'disabled' : ''}>${esc(t)}</button>`).join('')}
        ${pk != null ? `<button type="button" class="sp-btn" id="spNx">${z.qi + 1 < l.quiz.length ? 'Next question →' : 'Finish ✓'}</button>` : ''}</div>`;
    } else {
      const good = z.score >= 2;
      h = `<div class="sp-ls"><div class="sp-res"><div class="e">${z.done && z.phase === 'done' ? '✅' : good ? '🎉' : '💪'}</div><h3>${z.phase === 'done' && !z.fresh ? 'Lesson done today' : z.score + ' of ' + l.quiz.length + ' correct'}</h3><p>${z.fresh ? (good ? 'Great! Now do your action on the next chat.' : 'Good try — read it once more and do the action on your next chat.') : 'Learning streak: ' + ld.streak + ' day' + (ld.streak === 1 ? '' : 's') + '.'}</p></div><div class="act"><small>Your action</small>${esc(l.act)}</div>
        <button type="button" class="sp-btn ghost" id="spRe">Read it again</button></div>`;
    }
    box.innerHTML = h;
    const qz = $('spQz'); if (qz) qz.onclick = () => { z.phase = 'quiz'; z.qi = 0; z.score = 0; z.picked = null; paintLearn(); };
    box.querySelectorAll('.o').forEach((b) => { b.onclick = () => { if (z.picked != null) return; z.picked = Number(b.dataset.o); if (z.picked === l.quiz[z.qi].a) z.score++; paintLearn(); }; });
    const nx = $('spNx'); if (nx) nx.onclick = () => { if (z.qi + 1 < l.quiz.length) { z.qi++; z.picked = null; paintLearn(); } else finishLesson(); };
    const re = $('spRe'); if (re) re.onclick = () => { z.phase = 'read'; paintLearn(); };
    // what earlier lessons changed
    let extra = '';
    if (ld.results && ld.results.length) extra += `<div class="sp-chg"><div class="t">📈 What your lessons changed</div>${ld.results.map((r) => { const up = r.after >= r.before, f = (v) => r.unit === '₹' ? rs(v) : (r.unit === '%' ? Math.round(v) + '%' : (Math.round(v * 10) / 10) + r.unit); return `<div class="r"><b>${esc(r.title)}</b><span>${f(r.before)} → <span class="${up ? 'up' : 'dn'}">${f(r.after)} ${up ? '▲' : '▼'}</span></span></div>`; }).join('')}</div>`;
    // the library
    const tracks = {}; (ld.list || []).forEach((i) => { (tracks[i.track] = tracks[i.track] || []).push(i); });
    extra += `<div class="sp-chg" style="margin-top:8px"><div class="t">🎓 ${ld.done} of ${ld.total} lessons done${ld.streak ? ' · 🔥 ' + ld.streak + '-day learning streak' : ''}</div></div>`;
    extra += Object.keys(tracks).map((t) => `<div class="tk">${esc(t)}</div>` + tracks[t].map((i) => `<div class="li ${i.done ? 'done' : ''}" data-l="${esc(i.id)}"><i>${i.done ? '✓' : '○'}</i><b>${esc(i.title)}</b><small>${i.mins} min</small></div>`).join('')).join('');
    $('spLib').innerHTML = extra;
    $('spLib').querySelectorAll('.li').forEach((r) => { r.onclick = () => { loadLearn(r.dataset.l).then(() => { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); }); }; });
  }
  async function finishLesson() {
    const z = P.lz, l = z.lesson; z.phase = 'result'; z.fresh = true;
    let first = false;
    try { const r = await api.post('learn.done', { id: l.id, score: z.score }); first = !!(r && r.first_time); } catch (e) { /* the quiz still counts for the person */ }
    P.learnAt = 0; await loadLearn(null, false);
    z.phase = 'result'; P.lz = z; paintLearn();
    if (first) { if (SH.fx) { SH.fx.confetti(); SH.fx.chime(); } try { if (navigator.vibrate) navigator.vibrate([60, 40, 60]); } catch (e) { /* phones only */ } toast('🎓', 'Lesson done!', 'Now do the action on your next chat.'); }
  }

  // ------------------------------------------------------------------ run
  function render() {
    const x = mine(); if (!x || !build()) return;
    relocate();
    paintTop(x); paintMeet(); paintSummary(x); paintHero(x); paintStart(x); paintQuests(x); paintMys(x); paintLesson(); paintGap(); paintMove(x); paintRank(x);
    const e = $('spEarn'); if (e) { const to = Math.round(x.sales); e.dataset.v = P.lastSales != null ? P.lastSales : 0; countUp(e, to, rs); P.lastSales = to; }
    checkMoments(x);
    // the first order of the day opens the mystery box (never on page load: then it waits as a "ready" card)
    if (P.ready && P.prevOrders === 0 && x.orders > 0 && P.day && P.day.box && !ls.get(boxKey())) setTimeout(() => openBox(), 900);
    P.prevOrders = x.orders;
    if (!P.tab) { let t = ls.get('sp_tab2'); P.tab = null; openTab(TABS.some((a) => a[0] === t) ? t : 'cmp'); }
    P.ready = true;
  }
  let seq = 0;
  function tickKey() { return [S.coach, S.ct, S.punch, P.rating, S.canAlloc, S.sound, P.day, P.learn].map((v) => (v && typeof v === 'object' ? (v.__spid || (v.__spid = ++seq)) : String(v))).join('|'); }
  function poll() {
    const k = tickKey();
    if (k !== P.key) { P.key = k; try { render(); } catch (e) { if (window.console) console.error('sales_panel', e); } }
    else if (root) relocate();                // the lazily built cards (#shMis, #shLead …) arrive later
  }
  async function loadDay() { try { const d = await api.get('my.day'); if (d && d.applicable) P.day = d; } catch (e) { /* the extras are a bonus */ } }

  async function init() {
    try { const r = await api.get('rating.mine'); if (r && r.applicable) P.rating = r.month && r.month.score != null ? r.month.score : (r.today && r.today.score != null ? r.today.score : null); } catch (e) { /* level is a bonus */ }
    loadDay(); loadCmp('today'); loadLearn();
    setInterval(() => { if (!document.hidden) { loadCmp('today', true); loadDay(); } }, 3 * 60 * 1000);
  }

  window.SalesPanel = { open: openTab, _p: P };
  setInterval(poll, 700); poll();
  init();
})();