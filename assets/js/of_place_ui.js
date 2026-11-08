/* OfPlace - "Register a place" screens (Pantry first) for the HRMS.
   Places list + Add place wizard (office_care.html), the owner's daily check (my_day.html), the owner / Claude-asks cards and the Boss view lines.
   Talks only to the of.pl_* actions of empmanagment/office_place.php (see /work/wa/P_api.md). Needs api.js (and boss_common.js for BD.toast / BD.confirm / BD.modal / BD.photo).
   Global: window.OfPlace.  CSS prefix: ofp-.  Nothing here polls. */
(function () {
  'use strict';
  if (window.OfPlace) return;
  var OP = window.OfPlace = { version: 'p2-1' };
  var CSS = "/* P0 mockups: new components for \"Register a place\". Prefix ofp- (same prefix the real UI will use). Everything else comes from the real HRMS CSS. */\n.ofp *{box-sizing:border-box}\n.ofp h2,.ofp h3,.ofp h4{margin:0}\n.ofp .bd-card{margin-bottom:12px}\n.ofp-ic{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}\n.ofp-ic.sm{width:16px;height:16px}\n\n/* ---------- place cards ---------- */\n.ofp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px;margin-top:12px}\n.ofp-pl{border:1px solid var(--line);border-radius:20px;padding:14px 15px;background:#fff;display:flex;flex-direction:column;gap:9px;min-width:0}\n.ofp-pl.draft{border-style:dashed;background:#fafbfd}\n.ofp-pl.off{background:#f7f8fa}.ofp-pl.off h4{color:#6f7480}\n.ofp-pl .hd{display:flex;gap:8px;align-items:flex-start;justify-content:space-between}\n.ofp-pl h4{font-size:16px;font-weight:800;letter-spacing:-.01em;line-height:1.25}\n.ofp-pl h4 small{display:block;font-size:12.5px;font-weight:600;color:var(--muted);margin-top:1px}\n.ofp-own{display:inline-flex;align-items:center;gap:6px;background:#f1f3f6;border-radius:99px;padding:3px 11px 3px 3px;font-size:12.5px;font-weight:700;color:#3a3f4a}\n.ofp-own.none{background:#fff3dc;color:#7a4e00;padding-left:11px}\n.ofp-own.off{background:#eef0f3;color:#5a6270;padding-left:11px}\n.ofp-av{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex:none;background:#3b6fd4}\n.ofp-av.b{background:#16945a}.ofp-av.c{background:#e8863a}.ofp-av.lg{width:38px;height:38px;font-size:14px}\n.ofp-td{font-size:13px;color:#4a505c;line-height:1.5}\n.ofp-td div{display:flex;gap:7px;align-items:flex-start}\n.ofp-td i{width:8px;height:8px;border-radius:50%;margin-top:6px;flex:none;background:#16945a;display:block}\n.ofp-td i.w{background:#f2b33d}.ofp-td i.r{background:#d9423b}.ofp-td i.g{background:#c5cad3}\n.ofp-td b{color:var(--ink)}\n.ofp-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:2px}\n.ofp-acts .bd-btn{flex:1 1 auto}\n.ofp-mini{display:flex;gap:3px;align-items:center}\n.ofp-mini i{width:15px;height:8px;border-radius:4px;background:#e1e4ea;display:block}.ofp-mini i.d{background:#16945a}.ofp-mini i.n{background:var(--ink)}\n.ofp-sec-note{display:flex;gap:10px;align-items:flex-start;background:#fff8ea;border-radius:16px;padding:12px 14px;font-size:13.5px;line-height:1.5;color:#6b4a00;margin-top:10px}\n.ofp-sec-note b{color:#4d3500}.ofp-sec-note ul{margin:5px 0 0;padding-left:18px}\n.ofp-sec-ok{display:flex;gap:8px;align-items:center;font-size:13px;color:#0f6b43;margin-top:8px}\n.ofp-sec-ok i{width:20px;height:20px;border-radius:50%;background:#16945a;color:#fff;display:flex;align-items:center;justify-content:center;flex:none}\n\n/* ---------- wizard ---------- */\n.ofp-dots{display:grid;grid-template-columns:repeat(9,minmax(0,1fr));margin:0 -8px 2px}\n.ofp-dot{height:40px;display:flex;align-items:center;justify-content:center;border:0;background:none;padding:0;font-family:inherit;cursor:pointer}\n.ofp-dot i{width:27px;height:27px;border-radius:50%;font-size:12px;font-style:normal;font-weight:800;display:flex;align-items:center;justify-content:center;background:#e6e8ec;color:#7d8492}\n.ofp-dot.done i{background:#16945a;color:#fff}\n.ofp-dot.now i{background:var(--ink);color:#fff;box-shadow:0 0 0 3px rgba(20,22,26,.16)}\n.ofp-dot.skip i{background:#fff;border:2px dashed #c5cad3;color:#7d8492}\n.ofp-stepname{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin:4px 0 12px}\n.ofp-stepname h2{font-size:19px;font-weight:800;letter-spacing:-.015em}\n.ofp-stepname .n{font-size:12px;font-weight:700;color:var(--muted)}\n.ofp-foot{display:flex;flex-direction:column;gap:8px;margin-top:16px}\n.ofp-foot .bd-btn{width:100%;min-height:48px;font-size:14px}\n.ofp-foot .row{display:flex;gap:8px;flex-direction:column}.ofp-foot .row .bd-btn{flex:1}\n.ofp-later{font-size:12.5px;color:var(--muted);text-align:center;line-height:1.4}\n.ofp-plan{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}\n.ofp-plan span{display:inline-flex;align-items:center;gap:6px;border-radius:99px;background:#f1f3f6;padding:6px 12px;font-size:12.5px;font-weight:700;color:#3a3f4a}\n.ofp-plan span b{width:20px;height:20px;border-radius:50%;background:#dfe3e8;display:flex;align-items:center;justify-content:center;font-size:11px;color:#3a3f4a}\n.ofp-plan span.req{background:var(--ink);color:#fff}.ofp-plan span.req b{background:#fff;color:var(--ink)}\n.ofp-plan span.here{outline:2px solid var(--ink);outline-offset:-2px}\n\n/* photo rows */\n.ofp-ph{border:1px solid var(--line);border-radius:20px;padding:12px;margin-top:10px;background:#fff}\n.ofp-ph-top{display:flex;gap:12px;align-items:flex-start}\n.ofp-ref{position:relative;width:92px;height:122px;border-radius:16px;overflow:hidden;flex:none;background:#e6e8ec}\n.ofp-ref img{width:100%;height:100%;object-fit:cover;display:block}\n.ofp-ref span{position:absolute;left:5px;right:5px;bottom:5px;background:rgba(20,22,26,.72);color:#fff;font-size:11px;font-weight:700;border-radius:8px;text-align:center;padding:2px 0}\n.ofp-ref.empty{border:2px dashed #c5cad3;background:#fafbfd;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#4a505c;font-size:12px;font-weight:700;text-align:center;padding:6px}\n.ofp-ph .f{flex:1;min-width:0}\n.ofp-ph label,.ofp-fl{display:block;font-size:12px;font-weight:700;color:#4a505c;margin:0 0 5px}\n.ofp-ph .bd-in{min-height:44px;padding:9px 12px;font-size:15px}\n.ofp-ph .hi{margin-top:6px;font-size:14px}\n.ofp-must{margin-top:11px}\n.ofp-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;min-height:30px;align-items:center}\n.ofp-chips .c{display:inline-flex;align-items:center;gap:5px;border-radius:99px;background:#eef3ff;color:#2456b3;padding:5px 12px;font-size:13px;font-weight:700}\n.ofp-chips .c b{background:#2456b3;color:#fff;border-radius:99px;min-width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:0 5px}\n.ofp-chips .c.nn{background:#eef0f3;color:#3a3f4a}\n.ofp-chips .lbl{font-size:11.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em;width:100%}\n.ofp-ph .bt{display:flex;gap:8px;margin-top:11px;flex-wrap:wrap}\n.ofp-add{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:52px;border:2px dashed #c5cad3;border-radius:18px;background:#fafbfd;font-family:inherit;font-weight:800;font-size:14px;color:var(--ink);margin-top:10px;cursor:pointer}\n.ofp-claudeline{display:flex;gap:10px;align-items:flex-start;background:#f3f1ff;border-radius:16px;padding:11px 13px;font-size:13px;line-height:1.5;color:#3d33a0;margin-top:12px}\n.ofp-claudeline .sp{width:26px;height:26px;border-radius:9px;background:#6d5dfc;color:#fff;display:flex;align-items:center;justify-content:center;flex:none;font-size:14px}\n\n/* steppers */\n.ofp-st{display:inline-flex;align-items:center;border:1px solid #cfd3da;border-radius:99px;background:#fff;overflow:hidden;flex:none}\n.ofp-st button{width:44px;height:44px;border:0;background:#f1f3f6;font-size:22px;font-weight:700;font-family:inherit;color:var(--ink);display:flex;align-items:center;justify-content:center;padding:0;cursor:pointer}\n.ofp-st output{min-width:46px;text-align:center;font-size:18px;font-weight:800}\n.ofp-st.sm button{width:40px;height:40px;font-size:20px}.ofp-st.sm output{min-width:38px;font-size:16px}\n.ofp-st.red{border-color:#d9423b}.ofp-st.red output{color:#d9423b}.ofp-st.red button{background:#fdecea}\n.ofp-st.none output{color:#9aa0ab;font-weight:600}\n\n/* counting items */\n.ofp-grp{margin-top:14px}\n.ofp-grp > h3{font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;justify-content:space-between}\n.ofp-grp > .hint{font-size:12.5px;color:var(--muted);line-height:1.45;margin-top:3px}\n.ofp-it{border:1px solid var(--line);border-radius:18px;padding:11px 12px 12px;margin-top:9px;background:#fff}\n.ofp-it .h{display:flex;align-items:center;gap:8px;justify-content:space-between}\n.ofp-it .h .nm{font-weight:800;font-size:15px;line-height:1.25;min-width:0}\n.ofp-it .h .nm small{display:block;font-weight:600;font-size:12.5px;color:var(--muted)}\n.ofp-it .h .r{display:flex;gap:6px;align-items:center;flex:none}\n.ofp-x{width:40px;height:40px;border-radius:50%;border:0;background:#f1f3f6;color:#6f7480;display:flex;align-items:center;justify-content:center;cursor:pointer;flex:none;padding:0}\n.ofp-it .b{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:9px}\n.ofp-it .b label{display:block;font-size:12px;font-weight:700;color:#4a505c;margin-bottom:5px}\n.ofp-it .b small{display:block;font-size:11.5px;color:var(--muted);margin-top:4px}\n.ofp-mode{font-size:12px;color:var(--muted);margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}\n.ofp-mode a{color:#2456b3;font-weight:700;min-height:32px;display:inline-flex;align-items:center}\n.ofp-seg3{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid var(--line);border-radius:99px;overflow:hidden;background:#fff}\n.ofp-seg3 button{border:0;background:transparent;min-height:42px;font-family:inherit;font-weight:700;font-size:13px;color:#4a505c;cursor:pointer;padding:0 4px}\n.ofp-seg3 button.on.ok{background:#16945a;color:#fff}.ofp-seg3 button.on.miss{background:#f2b33d;color:#3b2a00}.ofp-seg3 button.on.brk{background:#d9423b;color:#fff}\n.ofp-eq{border:1px solid var(--line);border-radius:18px;padding:11px 12px;margin-top:9px;background:#fff}\n.ofp-eq .h{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:9px}\n.ofp-eq .nm{font-weight:800;font-size:15px}.ofp-eq .nm small{display:block;font-weight:600;font-size:12.5px;color:var(--muted)}\n.ofp-claude{border:1px solid #d9d4ff;border-left:4px solid #6d5dfc;border-radius:16px;background:#fbfaff;padding:13px 14px;margin-bottom:12px}\n.ofp-claude .t{display:flex;gap:9px;align-items:center;font-weight:800;font-size:15px}\n.ofp-claude .t .sp{width:28px;height:28px;border-radius:9px;background:#6d5dfc;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;flex:none}\n.ofp-claude p{margin:6px 0 0;font-size:13px;line-height:1.5;color:#464d5c}\n.ofp-sg{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}\n.ofp-sg button{min-height:42px;border-radius:99px;border:1px solid #c9c2ff;background:#fff;font-family:inherit;font-weight:700;font-size:13px;color:#3d33a0;padding:0 14px;display:inline-flex;align-items:center;gap:7px;cursor:pointer}\n.ofp-sg button.on{background:#16945a;border-color:#16945a;color:#fff}\n.ofp-sg button i{font-style:normal;font-weight:800}\n.ofp-sg button small{font-size:11.5px;font-weight:600;opacity:.85}\n\n/* steps 4-7 stacked */\n.ofp-s{border-top:1px solid var(--line);padding:14px 0 4px;margin-top:6px}\n.ofp-s:first-of-type{border-top:0;margin-top:0;padding-top:4px}\n.ofp-s .sh{display:flex;gap:10px;align-items:center}\n.ofp-s .sh b.n{width:30px;height:30px;border-radius:50%;background:var(--ink);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;flex:none}\n.ofp-s .sh h3{font-size:16px;font-weight:800;flex:1;letter-spacing:-.01em}\n.ofp-s .sub{font-size:12.5px;color:var(--muted);line-height:1.45;margin:6px 0 4px}\n.ofp-li{display:flex;gap:10px;align-items:center;padding:6px 0;border-top:1px solid #eef0f3;min-height:56px}\n.ofp-li:first-of-type{border-top:0}\n.ofp-li .t{flex:1;min-width:0;font-weight:700;font-size:14.5px;line-height:1.3}\n.ofp-li .t small{display:block;font-weight:500;font-size:12.5px;color:var(--muted);margin-top:1px}\n.ofp-li img{width:56px;height:56px;border-radius:12px;object-fit:cover;flex:none}\n.ofp-li .ph{width:56px;height:56px;border-radius:12px;border:2px dashed #c5cad3;display:flex;align-items:center;justify-content:center;color:#6f7480;flex:none;background:#fafbfd}\n.ofp-addrow{display:flex;gap:8px;margin-top:8px}\n.ofp-addrow .bd-in{flex:1;min-width:0;min-height:44px}\n.ofp-addrow .bd-btn{flex:none}\n.ofp-goes{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;color:#2456b3;background:#e8f0fe;border-radius:99px;padding:2px 9px;margin-top:3px}\n\n/* owner (p7) */\n.ofp-switch{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:56px}\n.ofp-switch b{font-size:16px;font-weight:800}.ofp-switch small{display:block;font-size:12.5px;color:var(--muted);font-weight:500;margin-top:1px}\n.ofp-tog{width:56px;height:34px;border-radius:99px;background:#c5cad3;position:relative;flex:none}\n.ofp-tog:after{content:\"\";position:absolute;left:3px;top:3px;width:28px;height:28px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.15s}\n.ofp-tog.on{background:#16945a}.ofp-tog.on:after{left:25px}\n.ofp-sel{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:52px;border:1px solid var(--ink);border-radius:16px;padding:0 14px;font-weight:700;font-size:15px;background:#fff;box-shadow:0 0 0 3px rgba(20,22,26,.08)}\n.ofp-sel.ph{color:#6f7480;font-weight:600}\n.ofp-lb{border:1px solid var(--line);border-radius:16px;margin-top:6px;overflow:hidden;background:#fff;box-shadow:0 10px 24px -12px rgba(20,22,26,.3)}\n.ofp-opt{display:flex;gap:11px;align-items:center;min-height:62px;padding:8px 13px;border-top:1px solid #eef0f3}\n.ofp-opt:first-child{border-top:0}\n.ofp-opt .m{flex:1;min-width:0}.ofp-opt .m b{font-size:15px;font-weight:800;display:flex;gap:7px;align-items:center;flex-wrap:wrap}\n.ofp-opt .m small{display:block;font-size:12.5px;color:var(--muted);margin-top:1px}\n.ofp-opt .ld{width:60px;flex:none;text-align:right}\n.ofp-opt .ld b{font-size:20px;font-weight:800;display:block;line-height:1}.ofp-opt .ld small{font-size:11.5px;color:var(--muted)}\n.ofp-opt.sg{background:#f6f4ff}\n.ofp-bar{height:6px;border-radius:9px;background:#eceef1;overflow:hidden;margin-top:5px}.ofp-bar i{display:block;height:100%;border-radius:9px;background:#16945a}\n.ofp-chip-rm{display:inline-flex;align-items:center;gap:7px;background:#f1f3f6;border-radius:99px;padding:0 0 0 4px;font-size:13px;font-weight:700}\n.ofp-chip-rm button{width:40px;height:40px;border:0;border-radius:50%;background:#dfe3e8;font-size:13px;display:flex;align-items:center;justify-content:center;padding:0}\n.ofp-does{font-size:13px;line-height:1.55;color:#464d5c}\n.ofp-does li{margin:4px 0}\n.ofp-ask{border:1px solid var(--cq-line,#e4e7ee);border-left:4px solid #6d5dfc;border-radius:16px;padding:14px 16px;background:#fff}\n.ofp-ask .tag{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#6d5dfc;display:flex;gap:8px;align-items:center;flex-wrap:wrap}\n.ofp-ask .tag span{color:var(--muted);font-weight:600;text-transform:none;letter-spacing:0;font-size:12px}\n.ofp-ask h3{font-size:17px;font-weight:800;line-height:1.3;margin:5px 0 4px;letter-spacing:-.01em}\n.ofp-ask p{font-size:13.5px;line-height:1.5;color:#464d5c;margin:0 0 10px}\n.ofp-ask .ans{display:flex;flex-direction:column;gap:8px}\n.ofp-ask .ans button{display:flex;align-items:center;gap:10px;min-height:52px;border:1px solid #cfd4de;border-radius:16px;background:#fff;font-family:inherit;font-size:14.5px;font-weight:700;padding:6px 14px;color:var(--ink);text-align:left;cursor:pointer}\n.ofp-ask .ans button small{display:block;font-weight:500;font-size:12.5px;color:var(--muted)}\n.ofp-ask .ans button.go{background:#171a21;border-color:#171a21;color:#fff}.ofp-ask .ans button.go small{color:#c9ced8}\n.ofp-ask .ans button .sp{margin-left:auto;font-size:11.5px;font-weight:800;background:#ece9ff;color:#3d33a0;border-radius:99px;padding:3px 9px;flex:none}\n.ofp-ask .ans button.go .sp{background:#6d5dfc;color:#fff}\n\n/* ---------- My Day place check (p6) ---------- */\n.ofp-chk .md-card{margin-top:12px}\n.ofp-chk .bar{display:flex;gap:10px;align-items:center;padding:12px 4px 2px}\n.ofp-chk .bar h1{font-size:19px;font-weight:800;letter-spacing:-.015em;margin:0;flex:1;min-width:0}\n.ofp-chk .bar h1 small{display:block;font-size:12.5px;color:var(--muted);font-weight:600;letter-spacing:0}\n.ofp-back{width:42px;height:42px;border-radius:14px;border:0;background:#fff;box-shadow:0 2px 10px rgba(20,22,26,.12);display:flex;align-items:center;justify-content:center;color:var(--ink);flex:none}\n.ofp-slots{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}\n.ofp-slot{position:relative;aspect-ratio:3/4;border-radius:16px;overflow:hidden;background:#e6e8ec}\n.ofp-slot img{width:100%;height:100%;object-fit:cover;display:block}\n.ofp-slot .ok{position:absolute;top:6px;right:6px;width:26px;height:26px;border-radius:50%;background:#16945a;color:#fff;display:flex;align-items:center;justify-content:center}\n.ofp-slot.empty{border:2px dashed #c5cad3;background:#fafbfd;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;color:#14161a;font-weight:800;font-size:12.5px;text-align:center}\n.ofp-slot.empty .cam{width:48px;height:48px;border-radius:50%;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center}\n.ofp-slotn{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:5px}\n.ofp-slotn div{font-size:12px;font-weight:700;line-height:1.3;color:#3a3f4a}\n.ofp-slotn small{display:block;font-size:11.5px;color:var(--muted);font-weight:500;margin-top:1px}\n.ofp-look{margin-top:10px;font-size:12.5px;line-height:1.5;color:#464d5c;background:#f3f1ff;border-radius:14px;padding:9px 12px}\n.ofp-look b{color:#3d33a0}\n.ofp-cnt{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid #eef0f3}\n.ofp-cnt:first-of-type{border-top:0}\n.ofp-cnt .l{min-width:0;flex:1}\n.ofp-cnt .l b{font-size:15.5px;font-weight:800;display:block;line-height:1.25}\n.ofp-cnt .l small{display:block;font-size:12.5px;color:var(--muted);margin-top:2px;font-weight:600}\n.ofp-cnt.low{background:#fff4f3;margin:0 -10px;padding:10px 10px;border-radius:16px;border-top-color:transparent}\n.ofp-cnt.low .l small{color:#d9423b;font-weight:800}\n.ofp-cnt.low + .ofp-cnt{border-top-color:transparent}\n.ofp-tk{display:flex;gap:12px;align-items:center;min-height:56px;padding:6px 0;border-top:1px solid #eef0f3}\n.ofp-tk:first-of-type{border-top:0}\n.ofp-tk .bx{width:38px;height:38px;border-radius:12px;border:2px solid #cfd3da;display:flex;align-items:center;justify-content:center;color:#fff;flex:none;background:#fff}\n.ofp-tk.on .bx{background:#16945a;border-color:#16945a}\n.ofp-tk .t{flex:1;font-weight:700;font-size:15px;line-height:1.3}.ofp-tk .t small{display:block;font-weight:500;font-size:12.5px;color:var(--muted)}\n.ofp-eqr{padding:10px 0;border-top:1px solid #eef0f3}.ofp-eqr:first-of-type{border-top:0}\n.ofp-eqr .nm{font-weight:800;font-size:15.5px;margin-bottom:8px;display:flex;justify-content:space-between;gap:8px;align-items:center}\n.ofp-eqr .nm small{font-weight:600;font-size:12.5px;color:var(--muted)}\n.ofp-photo-brk{display:flex;gap:10px;align-items:center;margin-top:9px;font-size:12.5px;color:#a02b25;background:#fdeceb;border-radius:14px;padding:8px 10px}\n.ofp-photo-brk img{width:48px;height:48px;object-fit:cover;border-radius:10px}\n.ofp-sum{background:#fff6e5;border-radius:16px;padding:12px 14px;font-size:13.5px;line-height:1.55;color:#7a4c00;margin-top:12px}\n.ofp-sum b{color:#4d3500}\n.ofp-sum ul{margin:5px 0 0;padding-left:18px}\n.ofp-h3{display:flex;align-items:center;gap:9px;font-size:17px;font-weight:800;letter-spacing:-.01em}\n.ofp-h3 b.n{width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;flex:none}\n.ofp-h3 span{margin-left:auto}\n.ofp-res .big{width:64px;height:64px;border-radius:50%;background:#16945a;color:#fff;display:flex;align-items:center;justify-content:center;margin:4px auto 10px}\n.ofp-sent{border:1px solid #eceef1;border-radius:18px;padding:12px 14px;margin-top:10px;background:#fff}\n.ofp-sent .h{display:flex;justify-content:space-between;gap:8px;align-items:center}\n.ofp-sent .h b{font-size:15px;font-weight:800}\n.ofp-sent ul{margin:7px 0 0;padding:0;list-style:none;font-size:14px;line-height:1.45}\n.ofp-sent li{display:flex;gap:8px;align-items:center;padding:6px 0;border-top:1px solid #f1f2f4;min-height:40px}\n.ofp-sent li:first-child{border-top:0}\n.ofp-sent li span.q{margin-left:auto;font-weight:800;white-space:nowrap}\n.ofp-sent li img{width:40px;height:40px;object-fit:cover;border-radius:9px}\n\n/* ---------- boss view (p9) ---------- */\n.ofp-duty{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:13px 0;border-top:1px solid var(--line);flex-wrap:wrap}\n.ofp-duty:first-of-type{border-top:0}\n.ofp-duty .l{flex:1 1 230px;min-width:0}\n.ofp-duty .t{font-weight:800;font-size:14.5px;line-height:1.35;display:flex;gap:7px;align-items:center;flex-wrap:wrap}\n.ofp-duty .s{font-size:12.5px;color:var(--muted);margin-top:3px;line-height:1.5}\n.ofp-duty .r{display:flex;flex-direction:column;gap:5px;align-items:flex-end;flex:none}\n.ofp-duty.new{background:#f9fbff;margin:0 -10px;padding:13px 10px;border-radius:16px;border-top-color:transparent}\n.ofp-from{display:flex;gap:10px;align-items:center;padding:9px 0;border-top:1px solid #eef0f3;min-height:52px}\n.ofp-from:first-of-type{border-top:0}\n.ofp-from .t{flex:1;min-width:0;font-weight:700;font-size:14px;line-height:1.35}.ofp-from .t small{display:block;font-weight:500;font-size:12.5px;color:var(--muted)}\n.ofp-from img{width:44px;height:44px;border-radius:10px;object-fit:cover;flex:none}\n.hc-m.a .hc-act b{font-size:13px}\n.hc-act .hc-sub{font-size:12.5px;color:var(--muted);margin-top:4px;line-height:1.45}\n.hc-m.a .lk{margin-top:10px}\n\n/* ---------- dark (only p6 dark mock) ---------- */\nhtml.dk{--bg:#0e1116;--surface:#171b22;--ink:#eef0f3;--muted:#9aa2b0;--line:#2a303a;--ok-bg:#0f3a2a;--ok-fg:#6ee7b0;--bad-bg:#47191c;--bad-fg:#ff9a9d}\nhtml.dk body{background:#0e1116;color:#eef0f3}\nhtml.dk .ofp-back{background:#171b22;color:#eef0f3;box-shadow:0 2px 10px rgba(0,0,0,.5)}\nhtml.dk .md-card,html.dk .ofp-sent,html.dk .ofp-pl{background:#171b22;border-color:#2a303a;box-shadow:0 1px 2px rgba(0,0,0,.4)}\nhtml.dk .md-card{box-shadow:0 1px 2px rgba(0,0,0,.4),0 8px 22px -14px rgba(0,0,0,.6)}\nhtml.dk .md-item{background:#171b22;border-color:#2a303a}html.dk .md-item.late{background:#2a2312;border-color:#7a5a12}\nhtml.dk .md-lb,html.dk .ofp-slotn small,html.dk .ofp-cnt .l small,html.dk .ofp-tk .t small,html.dk .ofp-eqr .nm small{color:#9aa2b0}\nhtml.dk .ofp-slotn div{color:#d3d8e0}\nhtml.dk .ofp-slot.empty{background:#10141a;border-color:#3a414d;color:#eef0f3}html.dk .ofp-slot.empty .cam{background:#eef0f3;color:#14161a}\nhtml.dk .ofp-slot{background:#222832}\nhtml.dk .ofp-look{background:#232048;color:#cfc9ff}html.dk .ofp-look b{color:#e1ddff}\nhtml.dk .ofp-st{background:#10141a;border-color:#3a414d}html.dk .ofp-st button{background:#232a34;color:#eef0f3}\nhtml.dk .ofp-st.red{border-color:#ff7a7f}html.dk .ofp-st.red output{color:#ff9a9d}html.dk .ofp-st.red button{background:#47191c;color:#ffd0d2}\nhtml.dk .ofp-cnt,html.dk .ofp-tk,html.dk .ofp-eqr,html.dk .ofp-sent li,html.dk .md-step{border-top-color:#262c36}\nhtml.dk .ofp-cnt.low{background:#3a1a1d}html.dk .ofp-cnt.low .l small{color:#ff9a9d}\nhtml.dk .ofp-tk .bx{background:#10141a;border-color:#3a414d}html.dk .ofp-tk.on .bx{background:#16945a;border-color:#16945a}\nhtml.dk .ofp-seg3{background:#10141a;border-color:#3a414d}html.dk .ofp-seg3 button{color:#c9ced8}\nhtml.dk .ofp-seg3 button.on.miss{background:#f2b33d;color:#3b2a00}\nhtml.dk .ofp-sum{background:#3b3110;color:#f3d36b}html.dk .ofp-sum b{color:#ffe9a0}\nhtml.dk .ofp-photo-brk{background:#47191c;color:#ffb4b6}\nhtml.dk .md-note{background:#15294a;color:#8db6ff}\nhtml.dk .md-bad{background:#47191c;color:#ffb4b6}\nhtml.dk .md-go{background:#16945a;color:#fff}html.dk .md-go.green{background:#16945a}\nhtml.dk .bd-btn{background:#171b22;color:#eef0f3;border-color:#4a5260}html.dk .bd-btn.pri{background:#eef0f3;color:#14161a;border-color:#eef0f3}\nhtml.dk .bd-chip{background:#262c36;color:#d3d8e0}html.dk .bd-chip.ok{background:#0f3a2a;color:#6ee7b0}html.dk .bd-chip.bad{background:#47191c;color:#ff9a9d}html.dk .bd-chip.warn{background:#3b3110;color:#f3d36b}html.dk .bd-chip.info{background:#15294a;color:#8db6ff}\nhtml.dk .topbar h1,html.dk .ofp-chk .bar h1{color:#eef0f3}\nhtml.dk .bd-sub{color:#9aa2b0}\nhtml.dk .sidebar{background:#171b22}html.dk .sidebar a{color:#8b909c}html.dk .sidebar a.active{background:#eef0f3;color:#14161a}\nhtml.dk .ofp-h3 b.n{background:#eef0f3;color:#14161a}\nhtml.dk .md-lb{color:#9aa2b0}\nhtml.dk .ofp-cnt .l b,html.dk .ofp-eqr .nm,html.dk .ofp-tk .t,html.dk .ofp-h3{color:#eef0f3}\n\n.ofp-togb{border:0;background:none;padding:5px 0 5px 5px;min-width:56px;min-height:44px;display:flex;align-items:center;cursor:pointer}\n.ofp-ph .bt .bd-btn,.ofp-mode .bd-btn{min-height:40px}\n\n\n@media (max-width:820px){.ofp-duty .r{align-items:flex-start;flex-direction:row;flex-wrap:wrap;align-items:center}}\n\n/* ===== P2 additions (real data, wizard, overlay) ===== */\n.ofp{max-width:620px;margin:0 auto}\n.ofp-wide{max-width:1180px;margin:0 auto}\n.ofp *{box-sizing:border-box}\n.ofp-wide.two,.ofp.two{display:block}\n@media (min-width:980px){.ofp.two{max-width:1180px;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:18px;align-items:start}.ofp.two>.col2{position:sticky;top:12px}}\nbody.ofp-nomenu #menuToggle{display:none !important}\n.ofp-nm{overflow-wrap:anywhere;word-break:break-word;min-width:0}\n.ofp-hi{font-weight:600;color:#7d8492;font-size:13px;margin-left:4px}\n.ofp-st input{width:52px;min-width:44px;height:44px;border:0;text-align:center;font:inherit;font-size:18px;font-weight:800;background:transparent;color:inherit;padding:0;margin:0;-moz-appearance:textfield;appearance:textfield;border-radius:0;outline:none}\n.ofp-st input::-webkit-outer-spin-button,.ofp-st input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}\n.ofp-st.sm input{width:44px;min-width:38px;height:40px;font-size:16px}\n.ofp-st.none input{color:#9aa0ab;font-weight:600}\n.ofp-st.red input{color:#d9423b}\n.ofp-st:focus-within{box-shadow:0 0 0 3px rgba(20,22,26,.16)}\n.ofp-st button:disabled{opacity:.4}\n.ofp-err{background:#fdeceb;color:#a02b25;border-radius:14px;padding:10px 13px;font-size:13.5px;line-height:1.5;margin-top:12px}\n.ofp-err button{margin-top:6px}\n.ofp-load{text-align:center;color:var(--muted);padding:26px 10px;font-size:14px}\n.ofp-sk{height:92px;border-radius:20px;background:linear-gradient(90deg,#eef0f3,#f6f7f9,#eef0f3);background-size:200% 100%;animation:ofpsk 1.2s infinite;margin-top:10px}\n@keyframes ofpsk{0%{background-position:200% 0}100%{background-position:-200% 0}}\n.ofp-it .h .nm input.bd-in,.ofp-eq .h .nm input.bd-in{min-height:42px;padding:7px 10px;font-size:15px;margin-bottom:5px}\n.ofp-it .h .nm{flex:1}\n.ofp-it .h .r .ofp-unit{width:74px;min-height:40px;padding:6px 8px;font-size:14px}\n.ofp-ph input.bd-in.hi{min-height:44px}\n.ofp-pend{font-size:12px;font-weight:700;color:#9a6200;background:#fff3dc;border-radius:99px;padding:2px 9px;display:inline-block;margin-top:6px}\n.ofp-ref{cursor:pointer;border:0;padding:0;font-family:inherit}\n.ofp-ref.empty{width:92px;height:122px}\n.ofp-sg button.on{background:#16945a;border-color:#16945a;color:#fff}\n.ofp-sec-note.blue{background:#eef3ff;color:#2456b3}.ofp-sec-note.blue b{color:#1d3f8c}\n.ofp-sec-note.ok{background:#e9f7ef;color:#0f6b43}.ofp-sec-note.ok b{color:#0b5535}\n.ofp-sec-note button{margin-top:8px}\n.ofp-seg3.ro{pointer-events:none;opacity:.85}\n.ofp-seg3 button.on.ok{background:#16945a;color:#fff}\n.ofp-li .chipbtn{border:0;font-family:inherit;cursor:pointer;min-height:40px;padding:0 12px}\n.ofp-li.blk{flex-wrap:wrap}\n.ofp-li .sub2{flex-basis:100%;display:flex;align-items:center;gap:10px;padding:2px 0 6px 0;font-size:12.5px;color:var(--muted)}\n.ofp-li .t.ro small{color:var(--muted)}\n.ofp-addrow{flex-wrap:wrap}\n.ofp-addrow .bd-in.hi2{flex:1 1 120px}\n.ofp-lb .ofp-opt{width:100%;border:0;background:#fff;text-align:left;font-family:inherit;cursor:pointer}\n.ofp-opt.sel{background:#f1faf5}\n.ofp-sel{width:100%;font-family:inherit;cursor:pointer;color:var(--ink)}\n.ofp-sel.ph{color:#6f7480}\n.ofp-lang{display:flex;gap:6px;align-items:center;margin:8px 0 0;flex-wrap:wrap;font-size:12.5px;color:var(--muted);font-weight:700}\n.ofp-lang button{min-height:40px;padding:0 14px;border-radius:99px;border:1px solid var(--line,#e6e8ec);background:#fff;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;color:#4a505c}\n.ofp-lang button.on{background:var(--ink);color:#fff;border-color:var(--ink)}\n.ofp-ov{position:fixed;left:0;right:0;top:0;bottom:0;z-index:150;background:var(--bg,#eef0f3);overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch}\n.ofp-ov .md{max-width:620px;margin:0 auto;padding:6px 10px 90px}\n.ofp-ov .md *{box-sizing:border-box}\n.ofp-chk .ofp-slot.empty{cursor:pointer;font-family:inherit;border:2px dashed #c5cad3;width:100%}\n.ofp-slot .cap{position:absolute;left:0;right:0;bottom:0;background:rgba(20,22,26,.6);color:#fff;font-size:11px;font-weight:700;padding:3px 4px;text-align:center}\n.ofp-slot.tile{display:flex;align-items:center;justify-content:center;background:#e4f6ec;color:#16945a;font-weight:800;font-size:12px}\n.ofp-slot.busy{opacity:.55}\n.ofp-cnt .ofp-st{flex:none}\n.ofp-tk{cursor:pointer;width:100%;background:none;border-left:0;border-right:0;border-bottom:0;text-align:left;font-family:inherit;color:inherit}\n.ofp-tkw{display:flex;align-items:center;gap:8px;border-top:1px solid #eef0f3}\n.ofp-tkw:first-of-type{border-top:0}\n.ofp-tkw .ofp-tk{border-top:0;flex:1;min-width:0}\n.ofp-tkw .none{flex:none;min-height:40px;padding:0 12px;border-radius:99px;border:1px solid #cfd3da;background:#fff;font-family:inherit;font-weight:700;font-size:12.5px;color:#5b6270;cursor:pointer}\n.ofp-tkw .none.on{background:#d9423b;border-color:#d9423b;color:#fff}\n.ofp-tkw.isnone .t small{color:#d9423b;font-weight:700}\n.ofp-photo-brk{align-items:center}\n.ofp-photo-brk .bd-btn{margin-left:auto}\n.ofp-need{display:flex;gap:8px;align-items:center}\n.ofp-go{display:block;width:100%;margin-top:12px;border:0;border-radius:22px;padding:17px;font-size:17px;font-weight:800;font-family:inherit;cursor:pointer;background:#16945a;color:#fff;min-height:56px}\n.ofp-go.dark{background:#111;color:#fff}\n.ofp-go[disabled]{background:#c8ccd4;cursor:not-allowed}\n.ofp-sheet{width:100%}\n.ofp-sheet h3{margin:0 0 4px;font-size:18px;font-weight:800}\n.ofp-kv{display:flex;gap:8px;justify-content:space-between;padding:8px 0;border-top:1px solid #eef0f3;font-size:14px;align-items:center}\n.ofp-kv:first-of-type{border-top:0}\n.ofp-kv .q{font-weight:800;white-space:nowrap}\n.ofp-kv.red .q,.ofp-kv.red b{color:#d9423b}\n.ofp-hdr{display:flex;align-items:center;gap:8px;flex-wrap:wrap}\n.ofp-ob{display:flex;gap:10px;flex-wrap:wrap}\n.ofp-ob>*{flex:1 1 140px}\n.ofp-times{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}\n.ofp-times input{min-height:46px}\n.ofp-small{font-size:12.5px;color:var(--muted);line-height:1.45}\n.ofp-more{margin-top:8px}\n.ofp-other{margin-top:12px}\n.ofp-mini i{flex:none}\n/* dark (only when the page puts class dk on <html>) */\nhtml.dk .ofp-ov{background:#0e1116;color:#eef0f3}\nhtml.dk .ofp-st input{color:#eef0f3}\nhtml.dk .ofp-tkw{border-top-color:#262c36}\nhtml.dk .ofp-tkw .none{background:#10141a;border-color:#3a414d;color:#c9ced8}\nhtml.dk .ofp-lang button{background:#171b22;border-color:#2a303a;color:#c9ced8}html.dk .ofp-lang button.on{background:#eef0f3;color:#14161a}\nhtml.dk .ofp-sent,html.dk .ofp-go.dark{background:#171b22}\nhtml.dk .ofp-tk{color:#eef0f3}\nhtml.dk .ofp-hi{color:#9aa2b0}\nhtml.dk .md-card{background:#171b22}\nhtml.dk .ofp-slot.tile{background:#0f3a2a;color:#6ee7b0}\nhtml.dk .ofp-err{background:#47191c;color:#ffb4b6}\n@media (max-width:420px){.ofp-it .b{gap:8px}.ofp-st button{width:42px}}\n.ofp.two .col2{display:flex;flex-direction:column}\n.ofp-ask{margin-top:0}\n@media (min-width:980px){.ofp.two .col2 .ofp-ask{order:-1;margin-bottom:12px}}\n.ofp-wide h2,.ofp-wide h3,.ofp-wide h4{margin:0}\n.ofp-wide .bd-card{margin-bottom:12px}\n.ofp-wide *{box-sizing:border-box}\n.ofp-addrow [data-addn]{flex:1 1 0;min-width:0}\n.ofp-addrow .hi2{order:3;flex:1 1 100%}\nhtml.dk .ofp-st.red input{color:#ff9a9d}\nhtml.dk .ofp-st.none input{color:#7d8492}\n";
  var st = document.createElement('style'); st.id = 'ofpCss'; st.textContent = CSS; document.head.appendChild(st);

  /* ---------------- helpers ---------------- */
  var HAS_BD = typeof BD !== 'undefined';   // api and BD are plain const globals of the page, not window properties
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var IC = { check: 'M5 12l5 5 9-10', cam: 'M4 8h3l2-3h6l2 3h3v11H4zM12 17a4 4 0 100-8 4 4 0 000 8z', plus: 'M12 5v14M5 12h14', x: 'M6 6l12 12M18 6L6 18', back: 'M15 5l-7 7 7 7', chev: 'M6 9l6 6 6-6', arrow: 'M5 12h14M13 6l6 6-6 6', pen: 'M4 20h4L19 9l-4-4L4 16zM13 7l4 4' };
  function ic(n, sm) { return '<svg class="ofp-ic' + (sm ? ' sm' : '') + '" viewBox="0 0 24 24" aria-hidden="true"><path d="' + IC[n] + '"/></svg>'; }
  function num(v) { var n = Number(v); return isFinite(n) ? n : 0; }
  function fmtN(v) { return String(Math.round(Number(v) * 100) / 100); }
  function t12(hhmm) { var m = /^(\d\d):(\d\d)/.exec(hhmm || ''); if (!m) return hhmm || ''; var h = Number(m[1]); return ((h + 11) % 12 + 1) + ':' + m[2] + ' ' + (h >= 12 ? 'PM' : 'AM'); }
  function timeOf(dt) { var m = /(\d\d:\d\d)/.exec(String(dt || '').slice(10)); return m ? t12(m[1]) : ''; }
  function initial(n) { return String(n || '?').trim().charAt(0).toUpperCase() || '?'; }
  function avc(i) { return ['', 'b', 'c'][Math.abs(i | 0) % 3]; }
  function plural(n, a, b) { return n + ' ' + (n === 1 ? a : (b || a + 's')); }
  function say(t, bad) { if (HAS_BD && BD.toast) BD.toast(t, !!bad); }
  function ask(title, msg, ok, danger) { if (HAS_BD && BD.confirm) return BD.confirm(title, msg, ok, danger); return Promise.resolve(window.confirm(msg)); }
  function errText(e) {
    var m = e && e.message ? String(e.message) : '';
    if (!m || e instanceof TypeError || /failed to fetch|networkerror|load failed/i.test(m)) return 'Cannot reach the server. Check the internet and try again.';
    return m;
  }
  function GET(a, p) { return api.get(a, p || {}); }
  function POST(a, b) { return api.post(a, b || {}); }
  function UP(a, fd) { return api.upload(a, fd); }
  function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }
  /* names by the person's language: both = English with Hindi under it */
  function nm(name, hi, lang) {
    lang = lang || 'both'; name = name || ''; hi = hi || '';
    if (lang === 'hi' && hi) return { main: hi, sub: '' };
    if (lang === 'en' || !hi) return { main: name, sub: '' };
    return { main: name, sub: hi };
  }
  function nmHtml(name, hi, lang) { var n = nm(name, hi, lang); return esc(n.main) + (n.sub ? '<small>' + esc(n.sub) + '</small>' : ''); }
  function nmInline(name, hi, lang) { var n = nm(name, hi, lang); return esc(n.main) + (n.sub ? ' <span class="ofp-hi">' + esc(n.sub) + '</span>' : ''); }
  /* an image URL that carries the login token, like every other image of the HRMS */
  function imgUrl(u) {
    if (!u) return '';
    if (/^(data:|blob:)/.test(u)) return u;
    var q = String(u).split('?')[1] || '', p = new URLSearchParams(q), a = p.get('action') || ''; p.delete('action'); p.delete('token');
    var o = {}; p.forEach(function (v, k) { o[k] = v; }); o.token = api.token();
    return a ? api.buildUrl(a, o) : u;
  }
  /* OfPlace photos need the login header (the token in the URL is only read by some actions), so each image is fetched with it and shown from a blob URL */
  var BLOBS = {};
  function imgTag(url, alt) { return url ? '<img data-ofimg="' + esc(url) + '" alt="' + esc(alt || '') + '" style="visibility:hidden">' : ''; }
  function blobFor(url) {
    if (/^(data:|blob:)/.test(url)) return Promise.resolve(url);
    if (BLOBS[url]) return BLOBS[url];
    var q = String(url).split('?')[1] || '', p = new URLSearchParams(q), a = p.get('action') || ''; p.delete('action'); p.delete('token'); var o = {}; p.forEach(function (v, k) { o[k] = v; });
    return (BLOBS[url] = fetch(a ? api.buildUrl(a, o) : url, { headers: { Authorization: 'Bearer ' + api.token() } }).then(function (r) { if (!r.ok) throw new Error('image'); return r.blob(); }).then(function (b) { return URL.createObjectURL(b); }).catch(function (e) { delete BLOBS[url]; throw e; }));
  }
  function hydrateImgs(root) {
    root.querySelectorAll('img[data-ofimg]').forEach(function (im) {
      var u = im.getAttribute('data-ofimg'); im.removeAttribute('data-ofimg');
      blobFor(u).then(function (b) { im.src = b; im.style.visibility = ''; }).catch(function () { im.classList.add('ofp-noimg'); im.style.visibility = ''; im.alt = 'photo'; im.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=='; });
    });
  }
  function shrinkFile(file, max) {
    return new Promise(function (res) {
      try {
        var im = new Image(), u = URL.createObjectURL(file);
        im.onload = function () {
          var k = Math.min(1, max / Math.max(im.naturalWidth, im.naturalHeight));
          if (k >= 1 && file.size < 1500000) { URL.revokeObjectURL(u); return res(file); }
          var c = document.createElement('canvas'); c.width = Math.max(1, Math.round(im.naturalWidth * k)); c.height = Math.max(1, Math.round(im.naturalHeight * k));
          c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
          c.toBlob(function (b) { URL.revokeObjectURL(u); res(b || file); }, 'image/jpeg', 0.85);
        };
        im.onerror = function () { URL.revokeObjectURL(u); res(file); };
        im.src = u;
      } catch (e) { res(file); }
    });
  }
  /* a tiny local copy of the must-have parser, so the chips show at once (the server one replaces it when it answers) */
  var WORDNUM = { one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9, ten: 10, ek: 1, do: 2, teen: 3, char: 4, paanch: 5, panch: 5, chhe: 6, saat: 7, aath: 8, nau: 9, das: 10, 'एक': 1, 'दो': 2, 'तीन': 3, 'चार': 4, 'पांच': 5, 'पाँच': 5, 'छह': 6, 'सात': 7, 'आठ': 8, 'नौ': 9, 'दस': 10 };
  function dev2ascii(s) { return String(s).replace(/[\u0966-\u096F]/g, function (c) { return String(c.charCodeAt(0) - 0x0966); }); }
  function parseMust(text) {
    var out = [];
    String(text || '').split(/[,;\n|\u060C]|\s&\s|\sand\s/i).forEach(function (raw) {
      var s = dev2ascii(raw).replace(/\s+/g, ' ').trim(); if (!s) return;
      var m = /^(\d+)\s*x?\s*(.+)$/i.exec(s) || /^x\s*(\d+)\s+(.+)$/i.exec(s), q = 0, name = s;
      if (m) { q = parseInt(m[1], 10); name = m[2]; }
      else if ((m = /^(.+?)\s*x\s*(\d+)$/i.exec(s))) { q = parseInt(m[2], 10); name = m[1]; }
      else { var w = s.split(' '); var f = (WORDNUM[w[0].toLowerCase()] || WORDNUM[w[0]]); if (f && w.length > 1) { q = f; name = w.slice(1).join(' '); } }
      name = name.replace(/^[\s\-:]+|[\s\-:]+$/g, ''); if (!name) return;
      out.push({ name: name, qty: q > 0 ? q : 1, given: q > 0 });
    });
    return out;
  }
  function chipsHtml(list, label) {
    if (!list || !list.length) return '<div class="ofp-chips"><span class="lbl">' + esc(label || 'Claude will look for') + '</span><span class="ofp-small">Nothing listed. Claude will judge the photo as a whole.</span></div>';
    return '<div class="ofp-chips"><span class="lbl">' + esc(label || 'Claude will look for') + '</span>' + list.map(function (c) { return c.given ? '<span class="c"><b>' + esc(c.qty) + '</b>' + esc(c.name) + '</span>' : '<span class="c nn">' + esc(c.name) + '</span>'; }).join('') + '</div>';
  }
  function mustLine(list) { return (list || []).map(function (c) { return (c.given ? c.qty + ' ' : '') + c.name; }).join(', '); }
  /* stepper: all keys are strings; value null = empty */
  function stepper(key, v, o) {
    o = o || {}; var none = v === null || v === undefined || v === '';
    return '<div class="ofp-st' + (o.sm ? ' sm' : '') + (none ? ' none' : '') + (o.red ? ' red' : '') + '" data-st="' + esc(key) + '"><button type="button" data-d="-1" aria-label="less"' + (o.dis ? ' disabled' : '') + '>\u2212</button><input type="text" inputmode="decimal" autocomplete="off" aria-label="' + esc(o.label || 'number') + '" value="' + (none ? '' : esc(fmtN(v))) + '" placeholder="\u2013" data-in="' + esc(key) + '"' + (o.dis ? ' disabled' : '') + '><button type="button" data-d="1" aria-label="more"' + (o.dis ? ' disabled' : '') + '>+</button></div>';
  }
  function parseNum(s) { s = String(s == null ? '' : s).replace(',', '.').trim(); if (s === '') return null; var n = Number(s); return isFinite(n) && n >= 0 && n <= 100000 ? Math.round(n * 100) / 100 : NaN; }
  /* one place to read field paths like "photos.2.label" */
  function pget(o, path) { var p = path.split('.'), c = o; for (var i = 0; i < p.length && c != null; i++) c = c[p[i]]; return c; }
  function pset(o, path, v) { var p = path.split('.'), c = o; for (var i = 0; i < p.length - 1; i++) { if (c[p[i]] == null) return false; c = c[p[i]]; } c[p[p.length - 1]] = v; return true; }
  OP._h = { imgTag: imgTag, hydrateImgs: hydrateImgs, esc: esc, ic: ic, num: num, t12: t12, timeOf: timeOf, parseMust: parseMust, chipsHtml: chipsHtml, nm: nm, imgUrl: imgUrl, stepper: stepper };

  /* ======================================================================= WIZARD ======================================================================= */
  var SCREENS = [
    { n: 1, steps: [1], title: '1 · Name the place', tag: '' },
    { n: 2, steps: [2], title: '2 · Cleaning photos', tag: 'Needed to switch on' },
    { n: 3, steps: [3, 8], title: '3 · Counting and equipment', tag: 'Steps 3 and 8' },
    { n: 4, steps: [4, 5, 6, 7], title: '4 to 7 · Refills, fixes, buying', tag: 'Four short lists' },
    { n: 5, steps: [9], title: '9 · Switch on and owner', tag: 'Last step' }
  ];
  function screenOf(step) { for (var i = 0; i < SCREENS.length; i++) if (SCREENS[i].steps.indexOf(step) >= 0) return SCREENS[i].n; return 1; }
  var PLAN = [[1, 'Name'], [2, 'Cleaning photos'], [3, 'Counting'], [4, 'Daily refill'], [5, 'One-time refill'], [6, 'To fix now'], [7, 'To buy new'], [9, 'Switch on and owner']];

  function itemModel(i) {
    return { id: i.id || 0, name: i.name || '', name_hi: i.name_hi || '', unit: i.unit || '', min: i.min == null ? null : num(i.min), fill_to: i.fill_to == null ? null : num(i.fill_to), qty: i.qty == null ? 1 : num(i.qty) || 1, mode: i.mode === 'tap' ? 'tap' : 'count', legacy: !!i.linked_legacy, _new: false, _edit: false };
  }
  function photoModel(p) {
    return { point_id: p.point_id || 0, label: p.label || '', label_hi: p.label_hi || '', must_text: p.must_text || '', parsed: p.must || [], has_ref: !!p.has_ref, ref_url: p.ref_url || '', file: null, preview: '', dirty: false, busy: false };
  }

  OP.openWizard = function (ctx) {
    var root = ctx.root, orig = { h1: ctx.h1 ? ctx.h1.textContent : '', sub: ctx.sub ? ctx.sub.textContent : '', btns: ctx.btns ? ctx.btns.innerHTML : '' };
    var W = { zone_id: ctx.zoneId || 0, office_id: ctx.officeId || 0, office: ctx.officeName || '', has_section: ctx.hasSection !== false, supply: ctx.supplyCode || '', supplyName: ctx.supplyName || '', place: null, name: '', name_hi: '',
      photos: [], counts: [], equipment: [], daily: [], once: [], fixes: [], pfix: [], buys: [], pbuy: [], employees: [], suggest: [], proposals: null, propPick: {}, propHide: false, blockers: [], owner_ids: [], ownerTouched: false,
      check_by: '12:00', photo_time: '11:00', screen: ctx.screen || 1, loading: !!ctx.zoneId, loadErr: '', busy: false, err: '', wantOn: false, on: false, dd: '', result: null, claudeMsg: '', cleaners: [], notes: [], askBusy: false, steps_done: [], pendingFile: null, sugBusy: false };
    var dead = false, changed = false;
    var fileIn = document.createElement('input'); fileIn.type = 'file'; fileIn.accept = 'image/*'; fileIn.hidden = true; fileIn.setAttribute('data-ofp-file', '1');

    function top() {
      if (ctx.h1) ctx.h1.textContent = W.result ? 'Place saved' : 'Add place';
      if (ctx.sub) ctx.sub.textContent = (W.office || W.supplyName || 'New place') + ' · ' + (W.zone_id ? (W.name || 'place') + ' · ' + (W.place ? W.place.state : 'draft') : 'new place');
      if (ctx.btns) { ctx.btns.innerHTML = '<button class="bd-btn" type="button" id="ofpTopBack">← Places</button>'; var b = ctx.btns.querySelector('#ofpTopBack'); if (b) b.onclick = function () { leave(); }; }
    }
    function leave() {
      dead = true; if (ctx.h1) ctx.h1.textContent = orig.h1; if (ctx.sub) ctx.sub.textContent = orig.sub; if (ctx.btns) ctx.btns.innerHTML = orig.btns;
      root.removeEventListener('click', onClick); root.removeEventListener('input', onInput); root.removeEventListener('change', onChange); root.removeEventListener('keydown', onKey);
      if (ctx.onExit) ctx.onExit(changed);
    }
    function setErr(m) { W.err = m || ''; var e = root.querySelector('[data-err]'); if (e) { e.innerHTML = m ? esc(m) : ''; e.style.display = m ? '' : 'none'; } }

    /* ---------- load ---------- */
    function hydrate(r) {
      var p = r.place || {}; W.place = p; W.zone_id = p.zone_id || W.zone_id; W.office_id = p.office_id || W.office_id; W.name = p.name || W.name; W.name_hi = p.name_hi || '';
      W.office = p.office || W.office; W.steps_done = p.steps_done || [];
      W.photos = (r.photos || []).map(photoModel); W.counts = (r.counts || []).map(itemModel); W.equipment = (r.equipment || []).map(itemModel); W.daily = (r.daily || []).map(itemModel); W.once = (r.once || []).map(itemModel);
      W.fixes = r.fixes || []; W.buys = r.buys || []; W.employees = r.employees || []; W.blockers = r.switch_blockers || [];
      var pr = r.claude && r.claude.proposals; if (pr && !W.proposals) W.proposals = pr;
      W.check_by = p.check_by || W.check_by; W.photo_time = p.photo_time || W.photo_time;
      W.on = p.state === 'on'; if (!W.resultShown) W.wantOn = W.on || W.wantOn;
      var ow = p.owner || {}; W.cleaners = ow.office_cleaners || [];
      if (!W.ownerTouched) W.owner_ids = ow.using === 'place' && ow.emp_ids ? ow.emp_ids.slice() : [];
      W.ownerNames = {}; (ow.emp_ids || []).forEach(function (id, i) { W.ownerNames[id] = (ow.names || [])[i] || ('#' + id); });
      if (r.can_switch_on === false && !W.blockers.length) W.blockers = ['Add at least one cleaning photo.'];
    }
    function load() {
      if (!W.zone_id) { W.loading = false; return Promise.resolve(); }
      W.loading = true; W.loadErr = ''; draw();
      return GET('of.pl_get', { zone_id: W.zone_id }).then(function (r) { hydrate(r); W.loading = false; }).catch(function (e) { W.loading = false; W.loadErr = errText(e); });
    }
    function refresh() { return GET('of.pl_get', { zone_id: W.zone_id }).then(function (r) { hydrate(r); }); }

    /* ---------- header ---------- */
    function dots() {
      var sc = SCREENS[W.screen - 1], h = '<div class="ofp-dots">';
      for (var n = 1; n <= 9; n++) {
        var now = sc.steps.indexOf(n) >= 0, done = W.steps_done.indexOf(n) >= 0, skip = !now && !done && n < sc.steps[0] && W.zone_id && !(n === 8 && screenOf(8) === W.screen);
        h += '<button class="ofp-dot ' + (now ? 'now' : (done ? 'done' : (skip ? 'skip' : ''))) + '" type="button" data-a="dot" data-n="' + n + '" aria-label="Step ' + n + '"><i>' + (done && !now ? '✓' : n) + '</i></button>';
      }
      return h + '</div>';
    }
    function head() {
      var sc = SCREENS[W.screen - 1];
      return dots() + '<div class="ofp-stepname"><h2>' + esc(sc.title) + '</h2>' + (W.screen === 2 ? '<span class="bd-chip dark">' + esc(sc.tag) + '</span>' : (sc.tag ? '<span class="n">' + esc(sc.tag) + '</span>' : '<span class="n">Step 1 of 9</span>')) + '</div>';
    }
    function footer(o) {
      o = o || {};
      return '<div class="ofp-foot">' + (o.primary ? '<button class="bd-btn pri" type="button" data-a="next"' + (W.busy ? ' disabled' : '') + '>' + esc(o.primary) + '</button>' : '')
        + '<div class="row">' + (o.back ? '<button class="bd-btn" type="button" data-a="back"' + (W.busy ? ' disabled' : '') + '>← ' + esc(o.back) + '</button>' : '') + '<button class="bd-btn" type="button" data-a="later"' + (W.busy ? ' disabled' : '') + '>Save and continue later</button></div>'
        + '<div class="ofp-later">Saved as a draft. Nothing goes live until step 9.</div></div>';
    }
    var errBox = function () { return '<div class="ofp-err" data-err role="alert" style="' + (W.err ? '' : 'display:none') + '">' + esc(W.err) + '</div>'; };

    /* ---------- screen 1 ---------- */
    function s1() {
      var h = '';
      if (W.supply && !W.zone_id) h += '<div class="ofp-sec-note blue"><div><b>' + esc(W.supplyName || W.supply) + '</b> has no office yet. When you register its first place, Claude also makes the office and its Boss Engine section (Worker, Checker and Section boss). It never makes a second one.</div></div>';
      h += '<div class="bd-f" style="margin-top:' + (h ? '12' : '0') + 'px"><label for="ofpN1">Place name (English)</label><input class="bd-in" id="ofpN1" data-f="name" value="' + esc(W.name) + '" style="min-height:48px" maxlength="60" autocomplete="off"><div class="hint">Staff see this name in My Day, with the office: "' + esc((W.name || 'Pantry') + ' · ' + (W.office || 'Oberon 1')) + '".</div></div>'
        + '<div class="bd-f"><label for="ofpN2">Name in Hindi (optional)</label><input class="bd-in" id="ofpN2" data-f="name_hi" value="' + esc(W.name_hi) + '" style="min-height:48px" maxlength="60" autocomplete="off"><div class="hint">Shown beside the English name to staff who use Hindi.</div></div>'
        + '<div class="ofp-sec-note blue"><div><b>Only one thing is needed to switch a place on:</b> at least one cleaning photo (step 2). Everything else is optional and can be added later.</div></div>';
      return h + errBox() + footer({ primary: 'Next: cleaning photos →' });
    }
    function planCard() {
      return '<div class="bd-card"><div class="bd-h" style="margin-bottom:2px"><h2>What we will ask</h2><span class="bd-chip dark">1 needed</span></div><div class="bd-sub">Skip any step, come back any time. Tap a step number above to jump.</div><div class="ofp-plan">'
        + PLAN.map(function (p) { return '<span class="' + (p[0] === 2 ? 'req ' : '') + (W.screen === screenOf(p[0]) ? 'here' : '') + '"><b>' + p[0] + '</b>' + esc(p[1]) + '</span>'; }).join('') + '</div><div class="bd-sub" style="margin-top:8px">Equipment (step 8) sits on the Counting screen.</div></div>';
    }

    /* ---------- screen 2: photos ---------- */
    function photoCard(p, i) {
      var ref = p.preview || (p.has_ref ? p.ref_url : '');
      var refH = ref ? '<button type="button" class="ofp-ref" data-a="pickref" data-i="' + i + '" aria-label="Change the reference photo">' + (p.preview ? '<img src="' + esc(p.preview) + '" alt="">' : imgTag(p.ref_url, '')) + '<span>Reference</span></button>'
        : '<button type="button" class="ofp-ref empty" data-a="pickref" data-i="' + i + '">' + ic('cam') + 'Add the reference photo</button>';
      return '<div class="ofp-ph" data-card="photos.' + i + '"><div class="ofp-ph-top">' + refH + '<div class="f"><label>Photo name</label><input class="bd-in" data-f="photos.' + i + '.label" value="' + esc(p.label) + '" placeholder="e.g. Coffee machine shelf" maxlength="80"><input class="bd-in hi" data-f="photos.' + i + '.label_hi" value="' + esc(p.label_hi) + '" aria-label="Hindi name" placeholder="Hindi name (optional)" maxlength="80">'
        + (p.point_id ? '' : '<span class="ofp-pend">Not saved yet</span>') + (p.dirty && p.point_id ? '<span class="ofp-pend">Changes not saved</span>' : '') + '</div></div>'
        + '<div class="ofp-must"><label class="ofp-fl">What must be visible (comma list)</label><input class="bd-in" data-f="photos.' + i + '.must_text" value="' + esc(p.must_text) + '" placeholder="2 handlers, 2 pressers, 1 beaker" maxlength="300">' + chipsHtml(p.parsed) + '</div>'
        + '<div class="bt"><button class="bd-btn sm" type="button" data-a="pickref" data-i="' + i + '">' + ic('cam', 1) + ' ' + (ref ? 'Retake reference' : 'Take reference') + '</button>'
        + ((!p.point_id || p.dirty) ? '<button class="bd-btn sm go" type="button" data-a="savephoto" data-i="' + i + '"' + (p.busy ? ' disabled' : '') + '>' + (p.busy ? 'Saving…' : 'Save photo') + '</button>' : '')
        + '<button class="bd-btn sm bad" type="button" data-a="rmphoto" data-i="' + i + '"' + (p.busy ? ' disabled' : '') + '>Remove</button></div></div>';
    }
    function s2() {
      var h = '<div class="bd-sub">Each photo is one daily photo the owner takes. The reference shows how it should look. The list says what must be in it.</div>';
      h += W.photos.map(photoCard).join('');
      h += '<button class="ofp-add" type="button" data-a="addphoto">' + ic('plus') + ' Add photo</button>';
      var prop = (W.proposals && W.proposals.must) || [];
      if (prop.length && W.photos.length) h += '<div class="ofp-claude" style="margin-top:12px"><div class="t"><span class="sp">✦</span>Claude suggests what each photo must show</div><p>Tap one to put it on a photo. Nothing changes until you save.</p><div class="ofp-sg">'
        + prop.slice(0, 8).map(function (m, k) { return '<button type="button" data-a="usemust" data-k="' + k + '">' + esc(m.label || 'Photo') + ': ' + esc(m.text || '') + '</button>'; }).join('') + '</div></div>';
      h += '<div class="ofp-claudeline"><span class="sp">✦</span><div><b>How Claude uses this.</b> Every day it checks each photo against its list and counts: "2 handlers seen, beaker missing". If it is unsure, the checker looks.</div></div>';
      return h + errBox() + footer({ primary: 'Next: counting →', back: 'Name' });
    }

    /* ---------- screen 3: counting + equipment ---------- */
    function nameBlock(list, i, it, ph) {
      var edit = it._new || it._edit;
      if (edit) return '<input class="bd-in" data-f="' + list + '.' + i + '.name" value="' + esc(it.name) + '" placeholder="' + esc(ph) + '" maxlength="80" aria-label="Name"><input class="bd-in hi" data-f="' + list + '.' + i + '.name_hi" value="' + esc(it.name_hi) + '" placeholder="Hindi (optional)" maxlength="80" aria-label="Hindi name">';
      return '<span class="ofp-nm">' + esc(it.name) + (it.name_hi ? '<small>' + esc(it.name_hi) + '</small>' : '') + '</span>';
    }
    function countCard(it, i) {
      var edit = it._new || it._edit, tap = it.mode === 'tap', h = '<div class="ofp-it" data-card="counts.' + i + '"><div class="h"><div class="nm">' + nameBlock('counts', i, it, 'Item, e.g. Milk packets') + '</div><div class="r">'
        + (edit ? '<input class="bd-in ofp-unit" data-f="counts.' + i + '.unit" value="' + esc(it.unit) + '" placeholder="unit" aria-label="Unit" maxlength="20">' : '<span class="bd-chip">' + esc(it.unit || 'pc') + '</span>')
        + (!edit ? '<button class="ofp-x" type="button" data-a="edit" data-k="counts" data-i="' + i + '" aria-label="Edit">' + ic('pen', 1) + '</button>' : '')
        + '<button class="ofp-x" type="button" data-a="rm" data-k="counts" data-i="' + i + '" aria-label="Remove">' + ic('x', 1) + '</button></div></div>';
      if (tap) h += '<div class="ofp-mode">Old-style item: the owner taps Ok, Low or Empty. <button class="bd-btn sm" type="button" data-a="mode" data-k="counts" data-i="' + i + '" data-m="count">Count it instead</button></div>';
      else {
        h += '<div class="b"><div><label>Minimum</label>' + stepper('counts.' + i + '.min', it.min, { sm: 1, label: 'Minimum' }) + '<small>Buy when below this</small></div>'
          + '<div><label>Fill to (optional)</label>' + stepper('counts.' + i + '.fill_to', it.fill_to, { sm: 1, label: 'Fill to' }) + '<small>' + (it.fill_to == null ? 'Empty: buy up to the minimum' : 'Buy up to this number') + '</small></div></div>'
          + '<div class="ofp-mode">New items use counting. Old-style item? <button class="bd-btn sm" type="button" style="margin-left:2px" data-a="mode" data-k="counts" data-i="' + i + '" data-m="tap">Use Ok / Low / Empty instead</button></div>';
      }
      return h + '</div>';
    }
    function equipCard(it, i) {
      return '<div class="ofp-eq" data-card="equipment.' + i + '"><div class="h"><div class="nm">' + nameBlock('equipment', i, it, 'Equipment, e.g. Coffee machine') + '</div>'
        + (!(it._new || it._edit) ? '<button class="ofp-x" type="button" data-a="edit" data-k="equipment" data-i="' + i + '" aria-label="Edit">' + ic('pen', 1) + '</button>' : '') + '<button class="ofp-x" type="button" data-a="rm" data-k="equipment" data-i="' + i + '" aria-label="Remove">' + ic('x', 1) + '</button></div>'
        + '<div class="ofp-seg3 ro" aria-hidden="true"><button type="button" class="on ok" tabindex="-1">Present</button><button type="button" tabindex="-1">Missing</button><button type="button" tabindex="-1">Broken</button></div></div>';
    }
    function claudeBox() {
      var P = W.proposals || {}, cs = (P.counts || []), eq = (P.equipment || []);
      var h = '';
      if ((cs.length || eq.length) && !W.propHide) {
        var all = cs.map(function (x, k) { return ['c', k, x]; }).concat(eq.map(function (x, k) { return ['e', k, x]; }));
        h += '<div class="ofp-claude"><div class="t"><span class="sp">✦</span>Claude looked at your ' + plural(W.photos.length, 'photo') + '</div><p>I can see these things. Tap the ones that are right. <b>Nothing is added until you tap.</b></p><div class="ofp-sg">'
          + all.map(function (a) { var key = a[0] + a[1], on = !!W.propPick[key], x = a[2]; return '<button type="button" class="' + (on ? 'on' : '') + '" data-a="sug" data-key="' + key + '"><i>' + (on ? '✓' : '+') + '</i> ' + esc(x.name) + ' <small>' + (a[0] === 'e' ? 'equipment' : 'min ' + esc(fmtN(x.min == null ? 1 : x.min))) + '</small></button>'; }).join('')
          + '</div><div class="ofp-acts" style="margin-top:10px"><button class="bd-btn sm" type="button" data-a="sugall">Add all ' + all.length + '</button><button class="bd-btn sm" type="button" data-a="sugno">Not now</button></div></div>';
      } else if (W.photos.length) {
        h += '<div class="ofp-claudeline" style="margin:0 0 12px"><span class="sp">✦</span><div>' + (W.claudeMsg ? esc(W.claudeMsg) : 'Claude can look at your cleaning photos and suggest what to count.') + ' <button class="bd-btn sm" type="button" data-a="suggest"' + (W.sugBusy ? ' disabled' : '') + ' style="margin-top:6px">' + (W.sugBusy ? 'Claude is looking…' : 'Ask Claude to suggest') + '</button></div></div>';
      }
      return h;
    }
    function s3() {
      var h = claudeBox();
      h += '<div class="ofp-grp"><h3>Things to count <span class="bd-chip info">' + plural(W.counts.length, 'item') + '</span></h3><div class="hint">Each morning the owner types how many there are. Below the minimum, it goes to the shopping list by itself.</div>' + W.counts.map(countCard).join('')
        + '<button class="ofp-add" type="button" data-a="additem" data-k="counts">' + ic('plus') + ' Add item</button></div>';
      h += '<div class="ofp-grp" style="margin-top:20px"><h3>Must-have equipment <span class="bd-chip info">' + plural(W.equipment.length, 'item') + '</span></h3><div class="hint">Each morning the owner marks it Present, Missing or Broken. Missing goes to To buy. Broken goes to To fix.</div>' + W.equipment.map(equipCard).join('')
        + '<button class="ofp-add" type="button" data-a="additem" data-k="equipment">' + ic('plus') + ' Add equipment</button></div>';
      return h + errBox() + footer({ primary: 'Next: refills →', back: 'Cleaning photos' });
    }

    /* ---------- screen 4: refills, fix, buy ---------- */
    function secHead(n, t, cnt) { return '<div class="sh"><b class="n">' + n + '</b><h3>' + t + '</h3><span class="bd-chip">' + cnt + '</span></div>'; }
    function addRow(k, ph) {
      return '<div class="ofp-addrow"><input class="bd-in" data-addn="' + k + '" placeholder="' + esc(ph) + '" maxlength="100" aria-label="' + esc(ph) + '">' + (k === 'fix' || k === 'buy' ? '' : '<input class="bd-in hi2" data-addh="' + k + '" placeholder="Hindi (optional)" maxlength="100" aria-label="Hindi">') + '<button class="bd-btn" type="button" data-a="addrow" data-k="' + k + '">Add</button></div>';
    }
    function liName(it) { return '<div class="t ofp-nm">' + esc(it.name) + (it.name_hi ? '<small>' + esc(it.name_hi) + '</small>' : '') + '</div>'; }
    function s4() {
      var h = '<div class="ofp-s">' + secHead(4, 'Daily refill', W.daily.length) + '<div class="sub">Ticked every morning in the daily check. If it is not ticked by the deadline, the Boss Engine records it as missed.</div>'
        + W.daily.map(function (it, i) { return '<div class="ofp-li">' + liName(it) + '<span class="bd-chip ok">daily</span><button class="ofp-x" type="button" data-a="rm" data-k="daily" data-i="' + i + '" aria-label="Remove ' + esc(it.name) + '">' + ic('x', 1) + '</button></div>'; }).join('')
        + addRow('daily', 'Add a daily refill, e.g. Wipe the tray') + '</div>';
      h += '<div class="ofp-s">' + secHead(5, 'One-time refill', W.once.length) + '<div class="sub">Not every day. Bought only when low or empty. Items with a minimum use the count; the others the owner marks Low or Empty.</div>'
        + W.once.map(function (it, i) {
          var tap = it.mode === 'tap';
          return '<div class="ofp-li blk">' + liName(it) + '<button type="button" class="bd-chip chipbtn ' + (tap ? 'warn' : 'info') + '" data-a="oncemode" data-i="' + i + '" aria-label="Change how it is checked">' + (tap ? 'tap Low / Empty' : 'by count') + '</button><button class="ofp-x" type="button" data-a="rm" data-k="once" data-i="' + i + '" aria-label="Remove ' + esc(it.name) + '">' + ic('x', 1) + '</button>'
            + (tap ? '' : '<div class="sub2"><span>Minimum</span>' + stepper('once.' + i + '.min', it.min, { sm: 1, label: 'Minimum' }) + '</div>') + '</div>';
        }).join('') + addRow('once', 'Add an item, e.g. Stirrers') + '</div>';
      var nf = W.fixes.length + W.pfix.length;
      h += '<div class="ofp-s">' + secHead(6, 'To fix now', nf) + '<div class="sub">Already broken today. These go to the To fix list as "reported". A photo is needed.</div>'
        + W.fixes.map(function (f) { return '<div class="ofp-li">' + (f.has_photo && f.photo_url ? imgTag(f.photo_url, '') : '<div class="ph">' + ic('cam') + '</div>') + '<div class="t ofp-nm">' + esc(f.title) + '<small><span class="ofp-goes">→ To fix list</span> · ' + esc(f.status || 'reported') + '</small></div></div>'; }).join('')
        + W.pfix.map(function (f, i) { return '<div class="ofp-li">' + (f.preview ? '<img src="' + esc(f.preview) + '" alt="">' : '<div class="ph">' + ic('cam') + '</div>') + '<div class="t ofp-nm">' + esc(f.title) + '<small><span class="ofp-goes">→ To fix list</span> · no photo yet</small></div><button class="bd-btn sm" type="button" data-a="fixphoto" data-i="' + i + '"' + (f.busy ? ' disabled' : '') + '>' + ic('cam', 1) + ' ' + (f.busy ? 'Saving…' : 'Photo') + '</button><button class="ofp-x" type="button" data-a="rmfix" data-i="' + i + '" aria-label="Remove ' + esc(f.title) + '">' + ic('x', 1) + '</button></div>'; }).join('')
        + addRow('fix', 'What is broken?') + '</div>';
      var nb = W.buys.length + W.pbuy.length;
      h += '<div class="ofp-s">' + secHead(7, 'To buy new', nb) + '<div class="sub">Things the pantry needs but does not have yet. These go to the shopping list as a "new" need.</div>'
        + W.buys.map(function (b) { return '<div class="ofp-li"><div class="t ofp-nm">' + esc(b.title) + (b.title_hi ? '<small>' + esc(b.title_hi) + '</small>' : '') + '<small><span class="ofp-goes">→ Shopping list</span> · ' + esc(b.status || 'open') + '</small></div><span class="bd-chip">× ' + esc(fmtN(b.qty)) + '</span></div>'; }).join('')
        + W.pbuy.map(function (b, i) { return '<div class="ofp-li"><div class="t ofp-nm">' + esc(b.title) + '<small><span class="ofp-goes">→ Shopping list</span></small></div>' + stepper('pbuy.' + i + '.qty', b.qty, { sm: 1, label: 'Quantity' }) + '<button class="ofp-x" type="button" data-a="rm" data-k="pbuy" data-i="' + i + '" aria-label="Remove ' + esc(b.title) + '">' + ic('x', 1) + '</button></div>'; }).join('')
        + addRow('buy', 'What is needed?') + '</div>';
      return h + errBox() + footer({ primary: 'Next: switch on and owner →', back: 'Counting' });
    }

    /* ---------- screen 5: switch on + owner ---------- */
    function empRows() {
      var sug = {}; W.suggest.forEach(function (s, k) { sug[s.id] = { why: s.why, k: k }; });
      var rows = W.employees.map(function (e) { var s = sug[e.id], cl = W.cleaners.indexOf(e.name) >= 0; return { id: e.id, name: e.name, load: e.load || 0, present: e.present !== false, why: s ? s.why : '', sug: !!s, k: s ? s.k : 99, cleaner: cl }; });
      W.suggest.forEach(function (s) { if (!rows.some(function (r) { return r.id === s.id; })) rows.push({ id: s.id, name: s.name, load: 0, present: true, why: s.why, sug: true, k: 0, cleaner: false }); });
      rows.sort(function (a, b) { return (a.sug === b.sug ? 0 : (a.sug ? -1 : 1)) || a.k - b.k || a.load - b.load; });
      return rows;
    }
    function nameOf(id) { var e = W.employees.filter(function (x) { return x.id === id; })[0]; return e ? e.name : (W.ownerNames && W.ownerNames[id]) || ('#' + id); }
    function ddRows(mode) {
      var q = (W.ddq || '').trim().toLowerCase(), rows = empRows().filter(function (r) { return W.owner_ids.indexOf(r.id) < 0 || (mode === 'owner' && W.owner_ids[0] === r.id); });
      if (q) return rows.filter(function (r) { return r.name.toLowerCase().indexOf(q) >= 0; }).slice(0, 30);
      return rows.slice(0, 6);   // the suggestions first; the rest only when you search (the office can have 160 people)
    }
    function ddRowsHtml(mode) {
      var rows = ddRows(mode);
      if (!rows.length) return '<div class="ofp-opt"><div class="m"><b>No one found</b><small>' + (W.employees.length ? 'Try another name.' : 'No employees came back. Check People.') + '</small></div></div>';
      return rows.map(function (r, i) {
        var sub = r.sug && r.why ? r.why : ((r.present ? 'In today' : 'Not in today') + (r.cleaner ? ' \u00b7 office cleaner of ' + (W.office || 'this office') : ''));
        return '<button type="button" class="ofp-opt' + (r.sug && i === 0 && !W.ddq ? ' sg' : '') + (W.owner_ids[0] === r.id && mode === 'owner' ? ' sel' : '') + '" role="option" data-a="pickowner" data-id="' + r.id + '" data-m="' + mode + '"><span class="ofp-av lg ' + avc(i) + '">' + esc(initial(r.name)) + '</span><div class="m"><b class="ofp-nm">' + esc(r.name) + (r.sug && i === 0 && !W.ddq ? ' <span class="bd-chip" style="background:#ece9ff;color:#3d33a0">Claude suggests</span>' : '') + '</b><small>' + esc(sub) + '</small><div class="ofp-bar"><i style="width:' + Math.min(100, r.load * 20) + '%;' + (r.load >= 5 ? 'background:#f2b33d' : '') + '"></i></div></div><div class="ld"><b>' + r.load + '</b><small>' + (r.load === 1 ? 'task' : 'tasks') + '</small></div></button>';
      }).join('');
    }
    function ddList(mode) {
      return '<div class="ofp-lb" role="listbox">' + (W.employees.length > 6 ? '<div style="padding:8px 10px 2px"><input class="bd-in" type="search" data-ddq placeholder="Search a name\u2026" value="' + esc(W.ddq || '') + '" aria-label="Search a name" autocomplete="off" style="min-height:44px"></div>' : '') + '<div data-ddrows>' + ddRowsHtml(mode) + '</div></div>';
    }
    function ownerBox() {
      var o = W.owner_ids[0], h = '<label class="ofp-fl" style="font-size:13px">Who does the daily check?</label>';
      h += '<button type="button" class="ofp-sel' + (o ? '' : ' ph') + '" data-a="dd" data-m="owner" aria-expanded="' + (W.dd === 'owner') + '">' + (o ? '<span style="display:flex;align-items:center;gap:9px;min-width:0"><span class="ofp-av">' + esc(initial(nameOf(o))) + '</span><span class="ofp-nm">' + esc(nameOf(o)) + '</span></span>' : '<span>Choose a person…</span>') + ic('chev') + '</button>';
      if (W.dd === 'owner') h += ddList('owner');
      h += '<div class="bd-sub" style="margin-top:7px">' + (o ? 'This person gets the daily photo and count tasks of this place.' : 'Until you choose, ' + (W.cleaners.length ? esc(W.cleaners.join(', ')) + ' (the office cleaner' + (W.cleaners.length > 1 ? 's' : '') + ') ' + (W.cleaners.length > 1 ? 'get' : 'gets') : 'the office cleaners get') + ' this job.') + '</div>';
      if (o) {
        h += '<label class="ofp-fl" style="font-size:13px;margin-top:16px">Backup, if the owner is away</label><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
          + W.owner_ids.slice(1).map(function (id, i) { return '<span class="ofp-chip-rm"><b style="padding-left:8px">' + (i + 1) + '.</b> ' + esc(nameOf(id)) + ' <button type="button" data-a="rmowner" data-i="' + (i + 1) + '" aria-label="Remove ' + esc(nameOf(id)) + '">' + ic('x', 1) + '</button></span>'; }).join('')
          + '<button class="bd-btn sm" type="button" data-a="dd" data-m="backup" style="min-height:40px">+ Add backup</button></div>';
        if (W.dd === 'backup') h += ddList('backup');
        h += '<div class="bd-sub" style="margin-top:6px">First free person in this order gets the job.</div>';
      }
      return h;
    }
    function does() {
      var np = W.photos.length, nm_ = (W.name || 'Place') + ' · ' + (W.office || 'office');
      var li = '<li>Makes ' + (np > 1 ? np + ' daily photo tasks' : 'the daily photo task') + ' <b>Clean: ' + esc(nm_) + '</b> and one <b>Count and refill: ' + esc(nm_) + '</b> task. The owner sees them as one job in My Day.</li>';
      li += W.owner_ids.length ? '<li>Gives ' + esc(W.name || 'the place') + ' its own seat inside the ' + esc(W.office || 'office') + ' section, so its owner differs from the office cleaners.</li>' : '<li>No owner chosen: the office cleaners get these tasks.</li>';
      li += W.has_section ? '<li>' + esc(W.office || 'This office') + ' already has a Boss Engine section. Nothing else is created.</li>' : '<li>' + esc(W.office || W.supplyName || 'This location') + ' has no Boss Engine section yet. Claude makes one called "' + esc(W.office || W.supplyName || 'office') + '" with 3 seats: Worker, Checker and Section boss. Pressing it twice never makes a second one.</li>';
      li += '<li>No penalties are created. Missed checks are only proposed in Boss Engine · Questions.</li>';
      return '<div class="bd-card"><div class="bd-h" style="margin-bottom:6px"><h2>What switching on does</h2></div><ul class="ofp-does" style="margin:0;padding-left:18px">' + li + '</ul></div>';
    }
    function askCard() {
      if (!W.on || !W.zone_id || (W.place && W.place.owner && W.place.owner.using === 'place')) return '';
      var rows = empRows().slice(0, 3), label = (W.name || 'Place') + ' · ' + (W.office || 'office'), s0 = W.suggest[0];
      return '<div class="ofp-ask"><div class="tag">✦ Claude asks <span>Boss Engine · Questions</span></div><h3>Who does the daily check for ' + esc(label) + '?</h3><p>' + esc(W.name || 'The place') + ' is on but nobody owns it.' + (s0 ? ' ' + esc(s0.name) + ': ' + esc(s0.why) + '.' : '') + ' Tap one to answer. You can change it later.</p><div class="ans">'
        + rows.map(function (r, i) { return '<button type="button" class="' + (i === 0 && r.sug ? 'go' : '') + '" data-a="askown" data-id="' + r.id + '"' + (W.askBusy ? ' disabled' : '') + '><span class="ofp-nm">' + esc(r.name) + ' <small>' + r.load + ' ' + (r.load === 1 ? 'task' : 'tasks') + ' today · ' + (r.cleaner ? 'office cleaner' : (r.present ? 'present' : 'not in today')) + '</small></span>' + (i === 0 && r.sug ? '<span class="sp">Suggested</span>' : '') + '</button>'; }).join('')
        + '<button type="button" data-a="askown" data-id="other"' + (W.askBusy ? ' disabled' : '') + '>Someone else…</button><button type="button" data-a="askown" data-id="office"' + (W.askBusy ? ' disabled' : '') + '>Leave it with the office cleaners</button></div></div>';
    }
    function s5() {
      var np = W.photos.length, ni = W.counts.length + W.daily.length + W.once.length, ne = W.equipment.length, blocked = np === 0;
      var sw = '<div class="ofp-switch"><div><b>Switch ' + esc(W.name || 'the place') + ' on</b><small>' + (blocked ? 'No cleaning photo yet · add one in step 2' : plural(np, 'cleaning photo') + ' ready ✓') + ' · ' + plural(ni, 'item') + ' · ' + plural(ne, 'equipment', 'equipment') + '</small></div><button class="ofp-togb" type="button" role="switch" aria-checked="' + !!W.wantOn + '" aria-label="Switch on" data-a="toggle"><span class="ofp-tog' + (W.wantOn ? ' on' : '') + '"></span></button></div>';
      if (blocked) sw += '<div class="ofp-sec-note"><div><b>One cleaning photo is needed first.</b> Add it in step 2, then come back here.<br><button class="bd-btn sm" type="button" data-a="dot" data-n="2">Go to the photos</button></div></div>';
      var col1 = '<div class="bd-card">' + head() + sw + '<div style="height:1px;background:var(--line);margin:12px 0 14px"></div>' + ownerBox()
        + '<div class="ofp-times"><div><label class="ofp-fl" for="ofpPT">Photos due by</label><input class="bd-in" id="ofpPT" type="time" data-f="photo_time" value="' + esc(W.photo_time) + '"></div><div><label class="ofp-fl" for="ofpCB">Count and refill due by</label><input class="bd-in" id="ofpCB" type="time" data-f="check_by" value="' + esc(W.check_by) + '"></div></div></div>';
      var go = W.wantOn && !W.on ? 'Switch on and finish' : (!W.wantOn && W.on ? 'Switch off and finish' : 'Save and finish');
      var col2 = does() + '<div class="ofp-foot" style="margin-top:0">' + errBox() + '<button class="bd-btn ' + (W.wantOn && !W.on ? 'go' : 'pri') + '" type="button" data-a="finish" style="min-height:52px"' + (W.busy ? ' disabled' : '') + '>' + (W.busy ? 'Saving…' : go) + '</button><div class="row"><button class="bd-btn" type="button" data-a="back"' + (W.busy ? ' disabled' : '') + '>← Back</button><button class="bd-btn" type="button" data-a="later"' + (W.busy ? ' disabled' : '') + '>Save and continue later</button></div></div><div style="height:6px"></div>' + askCard();
      return { col1: col1, col2: col2 };
    }
    function resultView() {
      var r = W.result, h = '<div class="bd-card ofp-res" style="text-align:center"><div class="big">' + ic('check') + '</div><div style="font-size:22px;font-weight:800;letter-spacing:-.02em">' + esc(r.title || 'Saved') + '</div><div style="font-size:14px;color:#5b6270;margin-top:6px;line-height:1.5">' + esc(r.message || '') + '</div></div>';
      var c = r.created || {};
      if (c.section || c.seat || c.photo_duties != null) h += '<div class="ofp-sent"><div class="h"><b>What was made</b></div><ul>' + (c.section ? '<li>Boss Engine section ' + (c.section.created ? 'made' : 'already there') + (c.section.seats && c.section.seats.length ? ' (' + plural(c.section.seats.length, 'seat') + ')' : '') + '</li>' : '') + (c.seat ? '<li>Owner seat for this place</li>' : '') + (c.photo_duties != null ? '<li>' + plural(c.photo_duties, 'photo duty', 'photo duties') + ', ' + plural(c.count_duty || 0, 'count and refill duty', 'count and refill duties') + '</li>' : '') + '</ul></div>';
      (r.warnings || []).forEach(function (w) { h += '<div class="ofp-sec-note"><div>' + esc(w) + '</div></div>'; });
      return h + '<div class="ofp-foot"><button class="bd-btn pri" type="button" data-a="exit">Back to places</button></div>';
    }

    /* ---------- draw ---------- */
    function draw() {
      if (dead) return;
      top();
      var keep = window.pageYOffset, h = '';
      if (W.loading) h = '<div class="ofp"><div class="bd-card"><div class="ofp-sk"></div><div class="ofp-sk"></div><div class="ofp-load">Opening the place…</div></div></div>';
      else if (W.loadErr) h = '<div class="ofp"><div class="bd-card"><div class="bd-empty"><b>Cannot open this place</b>' + esc(W.loadErr) + '<div style="margin-top:10px"><button class="bd-btn pri" type="button" data-a="retry">Try again</button> <button class="bd-btn" type="button" data-a="exit">Back to places</button></div></div></div></div>';
      else if (W.result) h = '<div class="ofp">' + resultView() + '</div>';
      else if (W.screen === 5) { var s = s5(); h = '<div class="ofp two"><div class="col1">' + s.col1 + '</div><div class="col2" style="display:flex;flex-direction:column">' + s.col2 + '</div></div>'; }
      else {
        var body = W.screen === 1 ? s1() : W.screen === 2 ? s2() : W.screen === 3 ? s3() : s4();
        h = '<div class="ofp"><div class="bd-card">' + head() + body + '</div>' + (W.screen === 1 ? planCard() : '') + '</div>';
      }
      var focusId = document.activeElement && root.contains(document.activeElement) ? document.activeElement.getAttribute('data-f') || document.activeElement.getAttribute('data-addn') : '';
      root.innerHTML = h; root.appendChild(fileIn); hydrateImgs(root);
      if (focusId && W._refocus) { var el = root.querySelector('[data-f="' + focusId + '"],[data-addn="' + focusId + '"]'); if (el) el.focus(); }
      W._refocus = false; window.scrollTo(0, keep);
    }
    function focusNew(sel) { var e = root.querySelector(sel); if (e) e.focus(); }

    /* ---------- save a screen ---------- */
    function stepCall(n, data) {
      var body = { office_id: W.office_id, zone_id: W.zone_id, step: n, data: data || {} };
      if (n === 1 && !W.zone_id && W.supply) { body.office_id = 0; body.data.supply_code = W.supply; }
      return POST('of.pl_step', body).then(function (r) {
        if (r.zone_id) W.zone_id = r.zone_id; if (r.place) { W.place = r.place; W.office_id = r.place.office_id || W.office_id; if (r.place.office) W.office = r.place.office; }
        (r.linked || []).forEach(function (l) { W.notes.push(l.name + ' is an old item, so it keeps ' + (l.mode === 'daily' ? 'its daily need' : 'Ok / Low / Empty') + '.'); });
        (r.converted || []).forEach(function (l) { W.notes.push((l.name || l) + ' now uses counting.'); });
        if (n === 1 && r.created && r.created.section && r.created.section.created && r.message) W.notes.push(r.message);   // "I made its Boss Engine section ..." is said once
        changed = true; return r;
      });
    }
    function itemsOut(list, kind) {
      return list.filter(function (it) { return String(it.name || '').trim(); }).map(function (it) {
        var o = { id: it.id || 0, name: it.name.trim(), name_hi: String(it.name_hi || '').trim() };
        if (kind === 'equip') return o;
        o.unit = String(it.unit || '').trim() || 'pc'; o.qty = it.qty || 1;
        if (kind === 'count' || kind === 'once') { o.mode = it.mode === 'tap' ? 'tap' : 'count'; if (o.mode === 'count') { o.min = it.min == null ? 1 : it.min; if (it.fill_to != null && it.fill_to >= o.min) o.fill_to = it.fill_to; } }
        return o;
      });
    }
    function savePhotos() {
      var chain = Promise.resolve(true);
      W.photos.slice().forEach(function (p) {
        chain = chain.then(function (ok) {
          if (!ok) return false;
          if (!p.point_id && !p.file && !p.label.trim() && !p.must_text.trim()) { W.photos.splice(W.photos.indexOf(p), 1); return true; }
          if (p.point_id && !p.dirty) return true;
          return savePhoto(p, true);
        });
      });
      return chain;
    }
    function savePhoto(p, quiet) {
      if (!p.point_id && !p.file) { setErr('Add the reference picture for "' + (p.label || 'the new photo') + '" first (tap the dashed box).'); return Promise.resolve(false); }
      var i = W.photos.indexOf(p); if (!p.label.trim()) p.label = 'Photo ' + (i + 1);
      var fd = new FormData(); fd.append('zone_id', W.zone_id); fd.append('point_id', p.point_id || 0); fd.append('label', p.label.trim()); fd.append('label_hi', p.label_hi.trim()); fd.append('must_text', p.must_text); fd.append('sort', i + 1);
      p.busy = true; if (!quiet) draw();
      return (p.file ? shrinkFile(p.file, 1600) : Promise.resolve(null)).then(function (blob) {
        if (blob) fd.append('photo', blob, 'reference.jpg');
        return UP('of.pl_photo', fd);
      }).then(function (r) {
        var np = photoModel(r.photo || {}); if (r.parsed) np.parsed = r.parsed; if (p.preview && !np.has_ref) { np.preview = p.preview; }
        W.photos[W.photos.indexOf(p)] = np; changed = true; if (!quiet) { say('Photo saved.'); draw(); } return true;
      }).catch(function (e) { p.busy = false; setErr(errText(e)); if (!quiet) draw(); setErr(errText(e)); return false; });
    }
    function saveScreen(n) {
      setErr(''); var seq;
      if (n === 1) {
        if (!W.name.trim()) { setErr('Give the place a name first.'); return Promise.resolve(false); }
        return stepCall(1, { name: W.name.trim(), name_hi: W.name_hi.trim() }).then(function () { return true; });
      }
      if (!W.zone_id) { setErr('Name the place first (step 1).'); return Promise.resolve(false); }
      if (n === 2) return savePhotos().then(function (ok) { if (!ok) return false; return stepCall(2, { photo_time: W.photo_time }).then(function () { return true; }); });
      if (n === 3) {
        var cs = itemsOut(W.counts, 'count'), eq = itemsOut(W.equipment, 'equip');
        return stepCall(3, { counts: cs, equipment: eq }).then(function () { return (eq.length || W.steps_done.indexOf(8) >= 0) ? stepCall(8, { equipment: eq }) : null; }).then(function () { return true; });
      }
      if (n === 4) {
        seq = Promise.resolve();
        var d = itemsOut(W.daily, 'daily'), o = itemsOut(W.once, 'once'), b = W.pbuy.filter(function (x) { return x.title.trim(); }).map(function (x) { return { title: x.title.trim(), title_hi: '', qty: x.qty || 1, unit: 'pc' }; });
        if (d.length || W.steps_done.indexOf(4) >= 0) seq = seq.then(function () { return stepCall(4, { daily: d }); });
        if (o.length || W.steps_done.indexOf(5) >= 0) seq = seq.then(function () { return stepCall(5, { once: o }); });
        if (W.fixes.length || W.steps_done.indexOf(6) >= 0) seq = seq.then(function () { return stepCall(6, {}); });
        if (b.length) seq = seq.then(function () { return stepCall(7, { buy: b }).then(function () { W.pbuy = []; }); });
        return seq.then(function () { return true; });
      }
      return Promise.resolve(true);
    }
    function guardPending() {
      if (W.screen !== 4 || !W.pfix.length) return Promise.resolve(true);
      return ask('Some fixes have no photo', W.pfix.map(function (f) { return f.title; }).join(', ') + ' will not be saved without a photo. Go on anyway?', 'Go on', false).then(function (ok) { if (ok) W.pfix = []; return ok; });
    }
    function go(toScreen, quiet) {
      if (W.busy) return Promise.resolve();
      W.busy = true; setErr('');
      return guardPending().then(function (ok) { if (!ok) { W.busy = false; return; }
        return saveScreen(W.screen).then(function (saved) {
          if (!saved) { W.busy = false; draw(); return; }
          return (W.zone_id ? refresh() : Promise.resolve()).catch(function () {}).then(function () { W.busy = false; if (toScreen === 'exit') { leave(); return; } W.screen = toScreen; W.dd = ''; if (toScreen === 5) loadOwnerInfo(); draw(); window.scrollTo(0, 0); if (W.notes.length) { say(W.notes.join(' ')); W.notes = []; } });
        }); }).catch(function (e) { W.busy = false; setErr(errText(e)); draw(); });
    }
    function loadOwnerInfo() { if (!W.zone_id) return; GET('of.pl_owner_info', { zone_id: W.zone_id }).then(function (r) { W.suggest = r.suggest || []; if (W.screen === 5 && !W.result) draw(); }).catch(function () {}); }

    /* ---------- finish (step 9) ---------- */
    function finish() {
      if (W.busy) return; W.busy = true; setErr(''); draw();
      var ids = W.owner_ids.slice(), res = null;
      stepCall(9, { owner_emp_ids: ids, check_by: W.check_by, photo_time: W.photo_time }).then(function () {
        if (W.wantOn && !W.on) return POST('of.pl_on', { zone_id: W.zone_id, owner_emp_ids: ids }).then(function (r) { res = { title: W.name + ' is on', message: r.message, created: r.created, warnings: r.warnings }; });
        if (!W.wantOn && W.on) return POST('of.pl_off', { zone_id: W.zone_id }).then(function (r) { res = { title: W.name + ' is off', message: r.message || 'Switched off. No tasks are made while it is off. Nothing is deleted.' }; });
        if (W.on && W.ownerTouched) return POST('of.pl_owner', { zone_id: W.zone_id, emp_ids: ids }).then(function (r) { res = { title: 'Saved', message: r.message || 'Owner saved.' }; });
        res = { title: W.on ? 'Saved' : 'Saved as a draft', message: W.on ? 'Everything is saved.' : 'Saved. ' + W.name + ' stays off until you switch it on.' };
      }).then(function () { W.busy = false; changed = true; W.result = res; W.resultShown = true; draw(); window.scrollTo(0, 0); })
        .catch(function (e) { W.busy = false; W.err = errText(e); draw(); });
    }

    /* ---------- events ---------- */
    function listOf(k) { return k === 'pbuy' ? W.pbuy : W[k]; }
    function onClick(e) {
      var b = e.target.closest('[data-a]'); if (!b || !root.contains(b)) { var st_ = e.target.closest('[data-st] button[data-d]'); if (st_) return stepClick(st_); return; }
      var a = b.dataset.a, i = Number(b.dataset.i), k = b.dataset.k;
      switch (a) {
        case 'dot': { var n = Number(b.dataset.n); if (n > 1 && !W.zone_id && !W.name.trim()) { setErr('Name the place first (step 1).'); return; } go(screenOf(n)); break; }
        case 'next': go(Math.min(5, W.screen + 1)); break;
        case 'back': go(Math.max(1, W.screen - 1)); break;
        case 'later': go('exit'); break;
        case 'exit': leave(); break;
        case 'retry': load().then(draw); break;
        case 'addphoto': W.photos.push(photoModel({})); W.photos[W.photos.length - 1].dirty = true; draw(); break;
        case 'pickref': W.pickFor = { kind: 'ref', i: i }; fileIn.value = ''; fileIn.click(); break;
        case 'savephoto': savePhoto(W.photos[i]); break;
        case 'rmphoto': { var p = W.photos[i]; if (!p.point_id) { W.photos.splice(i, 1); draw(); break; } ask('Remove this photo?', 'The daily photo "' + (p.label || 'photo') + '" is removed from ' + W.name + '.', 'Remove', true).then(function (ok) { if (!ok) return; p.busy = true; POST('of.pl_photo_del', { point_id: p.point_id }).then(function () { W.photos.splice(W.photos.indexOf(p), 1); changed = true; draw(); say('Photo removed.'); }).catch(function (er) { p.busy = false; draw(); say(errText(er), true); }); }); break; }
        case 'usemust': { var m = (W.proposals.must || [])[Number(b.dataset.k)]; if (!m) break; var t = W.photos.filter(function (x) { return (x.label || '').toLowerCase() === String(m.label || '').toLowerCase(); })[0] || W.photos.filter(function (x) { return !x.must_text; })[0] || W.photos[0]; t.must_text = m.text || ''; t.parsed = parseMust(t.must_text); t.dirty = true; draw(); say('Added to "' + (t.label || 'photo') + '". Save the photo to keep it.'); break; }
        case 'additem': listOf(k).push({ id: 0, name: '', name_hi: '', unit: '', min: k === 'counts' ? 1 : null, fill_to: null, qty: 1, mode: 'count', _new: true, _edit: true }); W._refocus = false; draw(); focusNew('[data-f="' + k + '.' + (listOf(k).length - 1) + '.name"]'); break;
        case 'edit': listOf(k)[i]._edit = true; draw(); focusNew('[data-f="' + k + '.' + i + '.name"]'); break;
        case 'rm': { var itx = listOf(k)[i]; if (itx && itx.id && /^(counts|equipment|daily|once)$/.test(k)) { ask('Stop using ' + (itx.name || 'this item') + '?', 'It is no longer counted or asked for in this place. Its history stays in Supplies.', 'Remove', true).then(function (ok) { if (ok) { listOf(k).splice(listOf(k).indexOf(itx), 1); draw(); } }); } else { listOf(k).splice(i, 1); draw(); } break; }
        case 'mode': { var it = W.counts[i]; it.mode = b.dataset.m; if (it.mode === 'count' && it.min == null) it.min = 1; draw(); break; }
        case 'oncemode': { var o = W.once[i]; o.mode = o.mode === 'tap' ? 'count' : 'tap'; if (o.mode === 'count' && o.min == null) o.min = 1; draw(); break; }
        case 'sug': { var key = b.dataset.key; W.propPick[key] = !W.propPick[key]; applyProposals([key]); draw(); break; }
        case 'sugall': { var ks = []; (W.proposals.counts || []).forEach(function (x, q) { ks.push('c' + q); }); (W.proposals.equipment || []).forEach(function (x, q) { ks.push('e' + q); }); ks.forEach(function (q) { W.propPick[q] = true; }); applyProposals(ks); W.propHide = true; draw(); break; }
        case 'sugno': W.propHide = true; draw(); break;
        case 'suggest': suggest(); break;
        case 'addrow': addRowAct(k); break;
        case 'fixphoto': W.pickFor = { kind: 'fix', i: i }; fileIn.value = ''; fileIn.click(); break;
        case 'rmfix': W.pfix.splice(i, 1); draw(); break;
        case 'toggle': if (!W.wantOn && !W.photos.length) { say('Add at least one cleaning photo first (step 2).', true); break; } W.wantOn = !W.wantOn; draw(); break;
        case 'dd': W.dd = W.dd === b.dataset.m ? '' : b.dataset.m; W.ddq = ''; draw(); break;
        case 'pickowner': { var id = Number(b.dataset.id); if (b.dataset.m === 'owner') { W.owner_ids = [id].concat(W.owner_ids.slice(1).filter(function (x) { return x !== id; })); } else if (W.owner_ids.indexOf(id) < 0) W.owner_ids.push(id); W.ownerTouched = true; W.dd = ''; W.ddq = ''; draw(); break; }
        case 'rmowner': W.owner_ids.splice(i, 1); W.ownerTouched = true; draw(); break;
        case 'askown': askOwner(b.dataset.id); break;
        case 'finish': finish(); break;
      }
    }
    function applyProposals(keys) {
      keys.forEach(function (key) {
        var kind = key.charAt(0), idx = Number(key.slice(1)), P = W.proposals || {}, x = (kind === 'c' ? P.counts : P.equipment || [])[idx]; if (!x) return;
        var list = kind === 'c' ? W.counts : W.equipment, has = list.filter(function (it) { return it._fromSug === key; })[0];
        if (W.propPick[key] && !has) list.push({ id: 0, name: x.name || '', name_hi: x.name_hi || '', unit: x.unit || '', min: kind === 'c' ? num(x.min == null ? 1 : x.min) : null, fill_to: kind === 'c' && x.fill_to != null ? num(x.fill_to) : null, qty: 1, mode: 'count', _new: false, _edit: false, _fromSug: key });
        if (!W.propPick[key] && has) list.splice(list.indexOf(has), 1);
      });
    }
    function suggest() {
      if (W.sugBusy) return; W.sugBusy = true; draw();
      POST('of.pl_suggest', { zone_id: W.zone_id, frames: [], notes: W.name }).then(function (r) { W.proposals = r.proposals || {}; W.claudeMsg = r.message || (r.ai === 'rules' ? 'I used simple rules (Claude is not available right now).' : ''); if (!((W.proposals.counts || []).length || (W.proposals.equipment || []).length)) W.claudeMsg = r.message || 'I did not find anything to suggest.'; W.propHide = false; })
        .catch(function (e) { W.claudeMsg = 'Claude could not look right now: ' + errText(e); }).then(function () { W.sugBusy = false; draw(); });
    }
    function addRowAct(k) {
      var inp = root.querySelector('[data-addn="' + k + '"]'), hin = root.querySelector('[data-addh="' + k + '"]'), v = inp ? inp.value.trim() : '';
      if (!v) { setErr('Type what to add first.'); if (inp) inp.focus(); return; } setErr('');
      if (k === 'daily' || k === 'once') listOf(k).push({ id: 0, name: v, name_hi: hin ? hin.value.trim() : '', unit: 'pc', min: null, fill_to: null, qty: 1, mode: k === 'once' ? 'tap' : 'count' });
      else if (k === 'fix') W.pfix.push({ title: v, preview: '', file: null, busy: false });
      else if (k === 'buy') W.pbuy.push({ title: v, qty: 1 });
      draw(); focusNew('[data-addn="' + k + '"]');
    }
    function askOwner(id) {
      if (id === 'other') { W.dd = 'owner'; draw(); var e = root.querySelector('.ofp-sel'); if (e && e.scrollIntoView) e.scrollIntoView({ block: 'center' }); return; }
      W.askBusy = true; draw();
      POST('of.pl_owner', { zone_id: W.zone_id, emp_ids: id === 'office' ? [] : [Number(id)] }).then(function (r) { W.askBusy = false; W.ownerTouched = false; changed = true; say(r.message || 'Saved.'); return refresh(); }).then(function () { draw(); loadOwnerInfo(); })
        .catch(function (e) { W.askBusy = false; draw(); say(errText(e), true); });
    }
    function stepClick(b) {
      var wrap = b.closest('[data-st]'), key = wrap.dataset.st, d = Number(b.dataset.d), cur = pget(W, key), isFill = /\.fill_to$/.test(key), minv = 0;
      var item = pget(W, key.replace(/\.[^.]+$/, '')); var v;
      if (isFill) { var mn = item.min == null ? 0 : item.min; if (cur == null) v = d > 0 ? mn + 1 : null; else { v = cur + d; if (v <= mn) v = null; } }
      else { v = (cur == null ? (d > 0 ? 1 : 0) : cur + d); if (v < 0) v = 0; if (/\.qty$/.test(key) && v < 1) v = 1; }
      pset(W, key, v); var inp = wrap.querySelector('input'); inp.value = v == null ? '' : fmtN(v); wrap.classList.toggle('none', v == null);
      if (isFill) { var sm = wrap.parentNode.querySelector('small'); if (sm) sm.textContent = v == null ? 'Empty: buy up to the minimum' : 'Buy up to this number'; }
    }
    function onInput(e) {
      var t = e.target;
      if (t.dataset.in != null) { var v = parseNum(t.value); if (Number.isNaN(v)) { t.setCustomValidity && t.setCustomValidity('Enter a number'); return; } pset(W, t.dataset.in, v); t.closest('[data-st]').classList.toggle('none', v == null); return; }
      if (t.dataset.ddq != null) { W.ddq = t.value; var rw = root.querySelector('[data-ddrows]'); if (rw) rw.innerHTML = ddRowsHtml(W.dd); return; }
      if (t.dataset.f) {
        var path = t.dataset.f; pset(W, path, t.value);
        if (/^photos\./.test(path)) { var pi = Number(path.split('.')[1]), p = W.photos[pi]; if (p) { p.dirty = true; if (/must_text$/.test(path)) { p.parsed = parseMust(t.value); var c = t.closest('.ofp-must').querySelector('.ofp-chips'); if (c) c.outerHTML = chipsHtml(p.parsed); parseLater(p, t.value); } } }
        if (path === 'name' || path === 'name_hi') { var hn = root.querySelector('.hint'); if (path === 'name' && hn) hn.textContent = 'Staff see this name in My Day, with the office: "' + (W.name || 'Pantry') + ' · ' + (W.office || 'Oberon 1') + '".'; }
      }
    }
    var parseLater = debounce(function (p, text) {
      POST('of.pl_parse', { text: text }).then(function (r) { if (p.must_text !== text || !r || !r.must) return; p.parsed = r.must; var card = root.querySelector('[data-card="photos.' + W.photos.indexOf(p) + '"] .ofp-chips'); if (card) card.outerHTML = chipsHtml(p.parsed); }).catch(function () {});
    }, 350);
    function onChange(e) {
      var t = e.target;
      if (t === fileIn && t.files && t.files[0]) {
        var f = t.files[0], pf = W.pickFor; t.value = ''; if (!pf) return;
        if (pf.kind === 'ref') { var p = W.photos[pf.i]; if (!p) return; if (p.preview) try { URL.revokeObjectURL(p.preview); } catch (x) {} p.file = f; p.preview = URL.createObjectURL(f); p.dirty = true; if (!p.label) p.label = 'Photo ' + (pf.i + 1); draw(); if (p.point_id) savePhoto(p); }
        else if (pf.kind === 'fix') { var q = W.pfix[pf.i]; if (!q) return; q.file = f; q.preview = URL.createObjectURL(f); q.busy = true; draw(); sendFix(q, f); }
      }
    }
    function sendFix(q, f) {
      shrinkFile(f, 1600).then(function (blob) { var fd = new FormData(); fd.append('zone_id', W.zone_id); fd.append('title', q.title); fd.append('detail', ''); fd.append('photo', blob, 'fix.jpg'); return UP('of.pl_fix_add', fd); })
        .then(function () { W.pfix.splice(W.pfix.indexOf(q), 1); changed = true; say('Sent to the To fix list.'); return GET('of.pl_get', { zone_id: W.zone_id }).then(function (r) { W.fixes = r.fixes || W.fixes; if (r.place) { W.place = r.place; W.steps_done = r.place.steps_done || W.steps_done; } }); }).then(function () { draw(); })   // only the fix list is refreshed: the other lists on this screen may hold unsaved edits
        .catch(function (e) { q.busy = false; draw(); say(errText(e), true); });
    }
    function onKey(e) {
      if (e.key !== 'Enter') return; var t = e.target; if (t.dataset && (t.dataset.addn || t.dataset.addh)) { e.preventDefault(); addRowAct(t.dataset.addn || t.dataset.addh); }
      else if (t.dataset && t.dataset.in != null) { e.preventDefault(); t.blur(); }
    }
    root.addEventListener('click', onClick); root.addEventListener('input', onInput); root.addEventListener('change', onChange); root.addEventListener('keydown', onKey);

    /* ---------- start ---------- */
    (function start() {
      if (W.zone_id) {
        load().then(function () {
          if (W.screen === 'auto') {}
          if (ctx.screen === 'resume') { var st = W.place ? Number(W.place.step || 1) : 1; W.screen = Math.min(5, st >= 9 ? 5 : (st <= 1 ? 2 : screenOf(Math.min(9, st + 1) === 8 ? 9 : st + 1))); if (st === 3) W.screen = 4; }
          if (W.screen === 5) loadOwnerInfo(); draw();
        });
      } else draw();
    })();
    return { leave: leave, state: W };
  };

  /* ======================================================================= PLACES LIST (Offices page) ======================================================================= */
  function ownChip(pl) {
    var o = pl.owner || {};
    if (pl.state === 'off') return '<span class="ofp-own off">Switched off</span>';
    if (o.using === 'place' && (o.names || []).length) return '<span class="ofp-own"><span class="ofp-av">' + esc(initial(o.names[0])) + '</span><span class="ofp-nm">' + esc(o.names[0]) + '</span></span>' + (o.names[1] ? ' <span class="ofp-own off">backup ' + esc(o.names[1]) + '</span>' : '');
    if (o.using === 'office' && (o.office_cleaners || []).length) return '<span class="ofp-own"><span class="ofp-av c">' + esc(initial(o.office_cleaners[0])) + '</span><span class="ofp-nm">' + esc(o.office_cleaners[0]) + '</span> <small style="font-size:11.5px;font-weight:600;color:#6f7480">office cleaner</small></span>';
    return '<span class="ofp-own none">No owner yet</span>';
  }
  function todayLines(pl) {
    var t = pl.today || {}, c = pl.counts || {}, L = [], pts = t.photo_tasks || [];
    if (pts.length) {
      var open = pts.filter(function (x) { return x.status !== 'done'; }).length;
      if (!open) L.push(['', '<b>Photo' + (pts.length > 1 ? 's' : '') + '</b> done']);
      else if (pts.length === 1) L.push(['g', '<b>Photo</b> due ' + esc(t12(pl.photo_time || ''))]);
      else L.push(['w', '<b>Photos</b> ' + open + ' of ' + pts.length + ' still open, due ' + esc(t12(pl.photo_time || ''))]);
    } else if (c.photos) L.push(['g', '<b>Photo</b> due ' + esc(t12(pl.photo_time || ''))]);
    else L.push(['w', 'No cleaning photo yet']);
    var all = (c.counting || 0) + (c.daily || 0) + (c.once || 0) + (c.equipment || 0);
    if (!all) L.push(['g', 'No counting set up']);
    else if (t.submitted) {
      var bm = (t.below_min || []).length, eb = (t.equipment_bad || []).length;
      L.push([bm || eb ? 'r' : '', '<b>Count</b> done' + (bm ? ', ' + plural(bm, 'item') + ' below minimum' : '') + (eb ? ', ' + plural(eb, 'equipment line') + ' to look at' : '')]);
    } else if (t.partial) L.push(['w', '<b>Count and refill</b> started, not finished' + (pl.check_by ? ', due ' + esc(t12(pl.check_by)) : '')]);
    else L.push(['g', '<b>Count and refill</b> due ' + esc(t12((t.check_task && t.check_task.due_at ? String(t.check_task.due_at).slice(11, 16) : pl.check_by) || ''))]);
    if (t.open_needs) L.push(['w', plural(t.open_needs, 'item') + ' to buy']);
    return L;
  }
  function cardHtml(pl, admin) {
    var st = pl.state, h = '<div class="ofp-pl ' + st + '" data-zone="' + pl.zone_id + '"><div class="hd"><h4 class="ofp-nm">' + esc(pl.name) + '<small>' + esc(pl.name_hi || '') + '</small></h4><span class="bd-chip ' + (st === 'on' ? 'ok' : (st === 'draft' ? 'info' : '')) + '">' + (st === 'on' ? 'On' : (st === 'draft' ? 'Draft' : 'Off')) + '</span></div>';
    h += '<div>' + (st === 'draft' && !(pl.owner && pl.owner.using === 'place') ? '<span class="ofp-own none">No owner yet</span>' : ownChip(pl)) + '</div>';
    if (st === 'draft') {
      var sd = pl.steps_done || [], bars = '';
      for (var n = 1; n <= 9; n++) bars += '<i class="' + (sd.indexOf(n) >= 0 ? 'd' : (n === Number(pl.step) + 1 ? 'n' : '')) + '"></i>';
      h += '<div class="ofp-mini" aria-label="progress">' + bars + '</div><div class="ofp-td"><div><i class="w"></i><span><b>Saved at step ' + esc(pl.step || 1) + ' of 9.</b> ' + ((pl.counts || {}).photos ? 'Ready to switch on at step 9.' : 'Add 1 cleaning photo to switch it on.') + '</span></div></div>';
    } else if (st === 'off') h += '<div class="ofp-td"><div><i class="g"></i><span>No tasks are made while it is off. Nothing is deleted.</span></div></div>';
    else h += '<div class="ofp-td">' + todayLines(pl).map(function (l) { return '<div><i class="' + l[0] + '"></i><span>' + l[1] + '</span></div>'; }).join('') + (pl.warnings || []).filter(function (w) { return !/^Nobody does/.test(w); }).slice(0, 2).map(function (w) { return '<div><i class="w"></i><span>' + esc(w) + '</span></div>'; }).join('') + '</div>';
    h += '<div class="ofp-acts">';
    if (st === 'draft') h += '<button class="bd-btn sm pri" type="button" data-a="cont">Continue</button>' + (admin ? '<button class="bd-btn sm bad" type="button" data-a="del">Remove</button>' : '');
    else if (st === 'on') h += '<button class="bd-btn sm pri" type="button" data-a="open">Open</button>' + (admin ? '<button class="bd-btn sm" type="button" data-a="edit">Edit steps</button><button class="bd-btn sm" type="button" data-a="off">Switch off</button>' : '');
    else h += (admin ? '<button class="bd-btn sm go" type="button" data-a="on">Switch on</button><button class="bd-btn sm" type="button" data-a="edit">Edit steps</button>' : '<button class="bd-btn sm" type="button" data-a="open">Open</button>');
    return h + '</div></div>';
  }
  OP.mountOffice = function (host, o) {
    var L = null, loading = true, err = '', admin = !!o.admin, wiz = null;
    host.classList.add('ofp-wide');
    function head() {
      var P = L.places || [], on = P.filter(function (p) { return p.state === 'on'; }), dr = P.filter(function (p) { return p.state === 'draft'; }).length, off = P.filter(function (p) { return p.state === 'off'; }).length;
      var tasks = 0, done = 0, buy = 0, fix = 0;
      on.forEach(function (p) { var t = p.today || {}; (t.photo_tasks || []).forEach(function (x) { tasks++; if (x.status === 'done') done++; }); if (t.check_task) { tasks++; if (t.check_task.status === 'done') done++; } buy += t.open_needs || 0; (t.equipment_bad || []).forEach(function (x) { if (x.state === 'broken') fix++; }); });
      var sec = L.office && L.office.section_id;
      var h = '<div class="bd-card"><div class="bd-h"><h2 class="ofp-nm">' + esc((L.office && L.office.name) || o.officeName || 'Office') + '</h2><span>' + (on.length ? '<span class="bd-chip ok">' + plural(on.length, 'place') + ' on</span> ' : '') + (dr ? '<span class="bd-chip info">' + dr + ' draft</span> ' : '') + (off ? '<span class="bd-chip">' + off + ' off</span>' : '') + (!P.length ? '<span class="bd-chip">No places yet</span>' : '') + '</span></div>';
      if (on.length) h += '<div class="bd-sub">Today: ' + done + ' of ' + tasks + ' tasks done · ' + plural(buy, 'item') + ' to buy · ' + fix + ' to fix</div>';
      if (sec) h += '<div class="ofp-sec-ok"><i>' + ic('check', 1) + '</i>Boss Engine section "' + esc((L.office && L.office.name) || '') + '" is ready (Worker, Checker, Section boss)</div>';
      else h += '<div class="ofp-sec-note"><div><b>No Boss Engine section yet: created automatically.</b> The first time you switch a place on, Claude makes one section called "' + esc((L.office && L.office.name) || o.officeName || '') + '" with 3 seats: Worker, Checker and Section boss. Nothing is made now, and pressing it twice never makes a second one.</div></div>';
      return h + '</div>';
    }
    function draw() {
      var h = '';
      if (loading) h = '<div class="bd-card"><div class="bd-h"><h2>Places</h2></div><div class="ofp-sk"></div><div class="ofp-load">Loading the places…</div></div>';
      else if (err) h = '<div class="bd-card"><div class="bd-h"><h2>Places</h2></div><div class="bd-empty"><b>Cannot load the places</b>' + esc(err) + '<div style="margin-top:10px"><button class="bd-btn pri" type="button" data-a="reload">Try again</button></div></div></div>';
      else {
        var P = L.places || [];
        h = head() + '<div class="bd-card"><div class="bd-h"><h2>Places</h2>' + (admin ? '<button class="bd-btn pri" type="button" data-a="new">+ Add place</button>' : '') + '</div><div class="bd-sub">A place is one zone, like Pantry or Washroom. Switch a place on and the Boss Engine makes its daily tasks. Every step can be saved and finished later.</div>'
          + (P.length ? '<div class="ofp-grid">' + P.map(function (p) { return cardHtml(p, admin); }).join('') + '</div>' : '<div class="bd-empty" style="padding:22px 6px"><b>No places yet</b>' + (admin ? 'Press "+ Add place" to register the Pantry. It takes a few minutes and you can stop at any step.' : 'Admin or HR can add a place here.') + '</div>') + '</div>';
      }
      host.innerHTML = h;
    }
    function load() { loading = !L; err = ''; draw(); return GET('of.pl_list', { office_id: o.officeId }).then(function (r) { L = r; loading = false; }).catch(function (e) { loading = false; err = errText(e); }).then(draw); }
    function openWiz(zone, screen) {
      wiz = OP.openWizard({ root: o.wizard.root, h1: o.wizard.h1, sub: o.wizard.sub, btns: o.wizard.btns, officeId: o.officeId, officeName: (L && L.office && L.office.name) || o.officeName, hasSection: !!(L && L.office && L.office.section_id), zoneId: zone || 0, screen: screen || 1, onExit: function (ch) { wiz = null; if (o.onWizardExit) o.onWizardExit(ch); } });
    }
    host.addEventListener('click', function (e) {
      var b = e.target.closest('[data-a]'); if (!b || !host.contains(b)) return; var a = b.dataset.a, card = b.closest('[data-zone]'), zid = card ? Number(card.dataset.zone) : 0, pl = (L && L.places || []).filter(function (p) { return p.zone_id === zid; })[0];
      if (a === 'reload') return load();
      if (a === 'new') return openWiz(0, 1);
      if (a === 'cont') return openWiz(zid, 'resume');
      if (a === 'edit') return openWiz(zid, 1);
      if (a === 'open') return OP.openPlace(zid, { admin: admin, onChange: load, office: L && L.office });
      if (a === 'del') { ask('Remove ' + (pl ? pl.name : 'this place') + '?', 'The draft and its photos are deleted. Items and needs already in Supplies stay.', 'Remove', true).then(function (ok) { if (!ok) return; b.disabled = true; POST('of.pl_delete', { zone_id: zid }).then(function () { say('Removed.'); load(); }).catch(function (er) { b.disabled = false; say(errText(er), true); }); }); return; }
      if (a === 'off') { ask('Switch ' + (pl ? pl.name : 'it') + ' off?', 'No tasks are made while it is off. Nothing is deleted. You can switch it on again.', 'Switch off', false).then(function (ok) { if (!ok) return; b.disabled = true; POST('of.pl_off', { zone_id: zid }).then(function (r) { say(r.message || 'Switched off.'); load(); }).catch(function (er) { b.disabled = false; say(errText(er), true); }); }); return; }
      if (a === 'on') { b.disabled = true; POST('of.pl_on', { zone_id: zid }).then(function (r) { say(r.message || 'Switched on.'); (r.warnings || []).forEach(function (w) { setTimeout(function () { say(w); }, 1800); }); load(); }).catch(function (er) { b.disabled = false; say(errText(er), true); }); }
    });
    load().then(function () { if (o.openZone != null && admin !== undefined) { var z = o.openZone; o.openZone = null; if (z === 'new') openWiz(0, 1); else if (Number(z)) openWiz(Number(z), 1); } });
    return { reload: load };
  };
  /* places that are not an office yet (store, RJD Hub ...): the card on the all-offices page */
  OP.mountOther = function (host, o) {
    GET('of.pl_offices').then(function (r) {
      var sp = r.supply_places || []; if (!sp.length || !o.admin) { host.innerHTML = ''; return; }
      host.innerHTML = '<div class="bd-card ofp-other"><div class="bd-h"><h2>Other locations</h2></div><div class="bd-sub">The Surat store, Oberon, Nishal office, RJD Hub and other places of Supplies have no office yet. Register a place there and Claude makes the office and its Boss Engine section for you (once).</div>'
        + sp.map(function (p) { return '<div class="bd-row"><div class="l"><div class="t">' + esc(p.name) + '</div></div><div class="r"><button class="bd-btn sm pri" type="button" data-sup="' + esc(p.code) + '" data-nm="' + esc(p.name) + '">Register a place</button></div></div>'; }).join('') + '</div>';
      host.onclick = function (e) { var b = e.target.closest('[data-sup]'); if (!b) return; o.start({ supplyCode: b.dataset.sup, supplyName: b.dataset.nm }); };
    }).catch(function () { host.innerHTML = ''; });
  };
  OP.startWizard = function (ctx) { return OP.openWizard(ctx); };

  /* ---------- Open: today's check + owner ---------- */
  OP.openPlace = function (zoneId, o) {
    if (!HAS_BD) return; o = o || {};
    var m = BD.modal('<div class="ofp-sheet"><div class="ofp-load">Opening…</div></div>'), box = m.querySelector('.ofp-sheet'), D = null, T = null, I = null, pick = false;
    function close() { BD.close(); }
    function ownerBlock(pl) {
      var ow = pl.owner || {}, h = '<div class="ofp-kv"><span>Owner</span><span>' + ownChip(pl) + '</span></div>';
      if (o.admin) h += '<div style="margin:6px 0 2px"><button class="bd-btn sm" type="button" data-a="chg">' + (pick ? 'Close the list' : 'Change owner') + '</button></div>';
      if (pick && D) {
        var sug = {}; ((I && I.suggest) || []).forEach(function (s, k) { sug[s.id] = { why: s.why, k: k }; });
        var all = (D.employees || []).map(function (e) { var s = sug[e.id]; return { id: e.id, name: e.name, load: e.load || 0, present: e.present !== false, why: s ? s.why : '', sug: !!s, k: s ? s.k : 99 }; }).sort(function (a, b) { return (a.sug === b.sug ? 0 : (a.sug ? -1 : 1)) || a.k - b.k || a.load - b.load; });
        var qq = (o._q || '').trim().toLowerCase(), rows = qq ? all.filter(function (r) { return r.name.toLowerCase().indexOf(qq) >= 0; }).slice(0, 30) : all.slice(0, 6);
        h += '<div class="ofp-lb">' + (all.length > 6 ? '<div style="padding:8px 10px 2px"><input class="bd-in" type="search" data-q placeholder="Search a name\u2026" value="' + esc(o._q || '') + '" aria-label="Search a name" autocomplete="off" style="min-height:44px"></div>' : '') + '<div data-qrows>' + rows.map(function (r, i) { return '<button type="button" class="ofp-opt' + (r.sug && i === 0 ? ' sg' : '') + '" data-a="setown" data-id="' + r.id + '"><span class="ofp-av lg ' + avc(i) + '">' + esc(initial(r.name)) + '</span><div class="m"><b class="ofp-nm">' + esc(r.name) + (r.sug && i === 0 ? ' <span class="bd-chip" style="background:#ece9ff;color:#3d33a0">Claude suggests</span>' : '') + '</b><small>' + esc(r.sug && r.why ? r.why : (r.present ? 'In today' : 'Not in today')) + '</small><div class="ofp-bar"><i style="width:' + Math.min(100, r.load * 20) + '%"></i></div></div><div class="ld"><b>' + r.load + '</b><small>' + (r.load === 1 ? 'task' : 'tasks') + '</small></div></button>'; }).join('') + '</div></div>'
          + '<div style="margin-top:8px"><button class="bd-btn sm" type="button" data-a="setown" data-id="office">Use the office cleaners</button></div>';
      }
      return h;
    }
    function todayBlock() {
      if (!T) return '<div class="ofp-small" style="margin-top:8px">Today\'s check could not be loaded.</div>';
      var h = '<div class="bd-lb" style="margin:12px 0 4px">Today</div>';
      if (!T.submitted) return h + '<div class="ofp-small">Nobody has sent today\'s count and refill check yet.</div>';
      h += '<div class="ofp-small" style="margin-bottom:4px">Sent by ' + esc(T.by || 'someone') + (T.at ? ' at ' + esc(timeOf(T.at)) : '') + '</div>';
      (T.rows || []).forEach(function (r) { h += r.group === 'equip' ? '<div class="ofp-kv ' + (r.state === 'present' ? '' : 'red') + '"><span>' + esc(r.name) + '</span><span class="q">' + esc(r.state || '') + '</span></div>' : '<div class="ofp-kv ' + (r.below ? 'red' : '') + '"><span>' + esc(r.name) + (r.min != null ? ' <span class="ofp-hi">min ' + esc(fmtN(r.min)) + '</span>' : '') + '</span><span class="q">' + (r.value == null ? (r.state || '') : esc(fmtN(r.value))) + (r.below && r.need_qty ? ' · buy ' + esc(fmtN(r.need_qty)) : '') + '</span></div>'; });
      if ((T.needs || []).length) h += '<div class="bd-lb" style="margin:10px 0 2px">To buy</div>' + T.needs.map(function (n) { return '<div class="ofp-kv"><span>' + esc(n.item) + '</span><span class="q">' + esc(fmtN(n.qty)) + ' · ' + esc(n.status) + '</span></div>'; }).join('');
      if ((T.fixes || []).length) h += '<div class="bd-lb" style="margin:10px 0 2px">To fix</div>' + T.fixes.map(function (n) { return '<div class="ofp-kv"><span>' + esc(n.title) + '</span><span class="q">' + esc(n.status) + '</span></div>'; }).join('');
      return h;
    }
    function draw() {
      var pl = (D && D.place) || (T && T.place) || { name: 'Place', owner: {} };
      var pts = ((pl.today || {}).photo_tasks) || [];
      box.innerHTML = '<h3 class="ofp-nm">' + nmInline(pl.name, pl.name_hi, 'both') + '</h3><div class="ofp-small">' + esc(pl.office || (o.office && o.office.name) || '') + (pl.state ? ' · ' + esc(pl.state) : '') + '</div>'
        + ownerBlock(pl) + (pts.length ? '<div class="bd-lb" style="margin:12px 0 4px">Photos today</div>' + pts.map(function (x) { return '<div class="ofp-kv"><span>' + esc(x.label || 'Photo') + '</span><span class="q">' + esc(x.status) + (x.owner ? ' · ' + esc(x.owner) : '') + '</span></div>'; }).join('') : '') + todayBlock()
        + '<div class="acts"><button class="bd-btn" type="button" data-a="close">Close</button></div>';
    }
    Promise.all([GET('of.pl_get', { zone_id: zoneId }).catch(function () { return null; }), GET('of.pl_today', { zone_id: zoneId }).catch(function () { return null; }), GET('of.pl_owner_info', { zone_id: zoneId }).catch(function () { return null; })]).then(function (r) { D = r[0]; T = r[1]; I = r[2]; if (!D && !T) { box.innerHTML = '<h3>Cannot open this place</h3><div class="ofp-small">Try again in a moment.</div><div class="acts"><button class="bd-btn" type="button" data-a="close">Close</button></div>'; return; } draw(); });
    m.addEventListener('input', function (e) { var t = e.target; if (t.dataset.q == null) return; o._q = t.value; draw(); var n = box.querySelector('[data-q]'); if (n) { n.focus(); n.setSelectionRange(n.value.length, n.value.length); } });
    m.addEventListener('click', function (e) {
      var b = e.target.closest('[data-a]'); if (!b) return; var a = b.dataset.a;
      if (a === 'close') return close();
      if (a === 'chg') { pick = !pick; o._q = ''; return draw(); }
      if (a === 'setown') {
        var id = b.dataset.id, cur = (D && D.place && D.place.owner && D.place.owner.emp_ids) || [], ids = id === 'office' ? [] : [Number(id)].concat(cur.filter(function (x) { return x !== Number(id); }));
        b.disabled = true; POST('of.pl_owner', { zone_id: zoneId, emp_ids: ids }).then(function (r) { say(r.message || 'Saved.'); if (o.onChange) o.onChange(); return GET('of.pl_get', { zone_id: zoneId }); }).then(function (d) { D = d; pick = false; draw(); }).catch(function (er) { b.disabled = false; say(errText(er), true); });
      }
    });
  };

  /* ======================================================================= MY DAY: the owner's daily check ======================================================================= */
  OP.dayHide = function (PL) { var s = {}; ((PL && PL.places) || []).forEach(function (p) { (p.hide_task_ids || []).forEach(function (id) { s[id] = 1; }); }); return s; };
  /* the punch-out lock lists "what is left" by task title: the photo + count tasks of a place become one line */
  OP.dayLeft = function (left, PL) {
    left = (left || []).slice(); var out = left;
    ((PL && PL.places) || []).forEach(function (p) {
      if (p.state === 'done') return;
      var a = 'Clean: ' + p.name + ' \u00b7 ' + p.office, b = 'Count and refill: ' + p.name + ' \u00b7 ' + p.office, hit = false;
      out = out.filter(function (x) { var t = String((x && x.title) || ''); if (t.indexOf(a) === 0 || t.indexOf(b) === 0) { hit = true; return false; } return true; });
      if (hit) out.push({ title: p.name + ' \u00b7 ' + p.office + ' check (photos and count)', due: p.due_at ? timeOf(p.due_at) : '' });
    });
    return out;
  };
  OP.dayCount = function (PL) { var d = 0, t = 0; ((PL && PL.places) || []).forEach(function (p) { t++; if (p.state === 'done') d++; }); return { done: d, total: t }; };
  OP.dayCards = function (PL) {
    var lang = (PL && PL.lang) || 'both';
    return ((PL && PL.places) || []).map(function (p) {
      var s = p.summary || {}, pts = p.photo_tasks || [], taken = pts.filter(function (x) { return x.status === 'done'; }).length, done = p.state === 'done', late = p.state === 'late';
      var chips = [];
      if (pts.length) chips.push('<span class="bd-chip info">' + (taken && taken < pts.length ? taken + ' of ' + pts.length + ' photos' : plural(pts.length, 'photo')) + '</span>');
      if (s.counts) chips.push('<span class="bd-chip info">Count ' + plural(s.counts, 'item') + '</span>');
      if (s.daily) chips.push('<span class="bd-chip info">Refill ' + s.daily + '</span>');
      if (s.once) chips.push('<span class="bd-chip info">Low stock ' + s.once + '</span>');
      if (s.equipment) chips.push('<span class="bd-chip info">Equipment ' + s.equipment + '</span>');
      var due = p.due_at ? 'Due by ' + timeOf(p.due_at) : '';
      var title = nmInline(p.name, p.name_hi, lang) + ' · ' + esc(p.office || '');
      if (done) return '<div class="md-item done" data-ofp="' + p.zone_id + '" role="button" tabindex="0"><div style="min-width:0"><div class="t ofp-nm">' + title + '</div><div class="s">Photos and count done</div></div><div class="md-tick">✓</div></div>';
      return '<div class="md-item ' + (late ? 'late' : '') + '" data-ofp="' + p.zone_id + '" role="button" tabindex="0" style="border-width:2px;border-color:#111"><div style="min-width:0"><div class="t ofp-nm">' + title + '</div><div class="s">' + chips.join('') + (late ? '<span class="bd-chip warn">Late</span>' : '') + (p.state === 'partial' ? '<span class="bd-chip warn">Started</span>' : '') + '</div><div class="s">One job, done in one go. ' + esc(due) + '</div></div><span class="md-open">' + (p.state === 'partial' ? 'Continue' : 'Start') + '</span></div>';
    }).join('');
  };

  OP.openCheck = function (opt) {
    opt = opt || {};
    if (document.getElementById('ofpOv')) return;
    var ov = document.createElement('div'); ov.id = 'ofpOv'; ov.className = 'ofp-ov'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-label', 'Daily check');
    document.body.appendChild(ov); document.body.classList.add('ofp-nomenu'); var oldOv = document.body.style.overflow; document.body.style.overflow = 'hidden';
    var C = { form: null, loading: true, err: '', lang: opt.lang || 'both', v: { count: {}, daily: {}, once: {}, eq: {} }, eqPhoto: {}, eqPrev: {}, busy: false, shotBusy: 0, result: null, submitErr: '' }, changed = false;
    var q = { task_id: opt.taskId || 0, zone_id: opt.zoneId || 0 };
    function close() { ov.removeEventListener('click', onClick); ov.removeEventListener('input', onInput); ov.removeEventListener('change', onChange); ov.remove(); document.body.classList.remove('ofp-nomenu'); document.body.style.overflow = oldOv || ''; Object.keys(C.eqPrev).forEach(function (k) { try { URL.revokeObjectURL(C.eqPrev[k]); } catch (e) {} }); if (opt.onClose) opt.onClose(changed); }
    function name(x) { return nmInline(x.name, x.name_hi, C.lang); }
    function nameOnly(x) { var n = nm(x.name, x.name_hi, C.lang); return n.main; }
    function subName(x) { var n = nm(x.name, x.name_hi, C.lang); return n.sub; }

    function seed(f) {
      (f.count || []).forEach(function (r) { if (C.v.count[r.item_id] === undefined) C.v.count[r.item_id] = r.value == null ? null : num(r.value); });
      (f.daily || []).forEach(function (r) { if (C.v.daily[r.item_id] === undefined) C.v.daily[r.item_id] = r.state || null; });
      (f.once || []).forEach(function (r) { if (C.v.once[r.item_id] === undefined) C.v.once[r.item_id] = r.mode === 'tap' ? (r.state || null) : (r.value == null ? null : num(r.value)); });
      (f.equipment || []).forEach(function (r) { if (C.v.eq[r.item_id] === undefined) C.v.eq[r.item_id] = r.state || null; });
    }
    function load(keep) {
      if (!keep) { C.loading = true; C.err = ''; draw(); }
      return GET('of.pl_form', q).then(function (f) { C.form = f; C.lang = opt.lang && !C.langSet ? (f.lang || opt.lang) : (C.langSet ? C.lang : (f.lang || 'both')); q.task_id = (f.task && f.task.id) || q.task_id; seed(f); C.loading = false; C.err = ''; })
        .catch(function (e) { C.loading = false; C.err = errText(e); }).then(draw);
    }
    /* ---------- answers ---------- */
    function below(r) { var v = C.v.count[r.item_id]; return v != null && r.min != null && v < r.min; }
    function belowOnce(r) { var v = C.v.once[r.item_id]; return r.mode !== 'tap' && typeof v === 'number' && r.min != null && v < r.min; }
    function needQty(r, v) { var t = r.fill_to != null && r.fill_to > r.min ? r.fill_to : r.min; return Math.max(0, Math.round((t - v) * 100) / 100); }
    function parts() {
      var f = C.form, P = [];
      if ((f.photos || []).length) { var tk = f.photos.filter(function (x) { return x.status === 'done'; }).length; P.push({ k: 'photos', done: tk === f.photos.length, a: tk, n: f.photos.length }); }
      if ((f.count || []).length) { var a = f.count.filter(function (r) { return C.v.count[r.item_id] != null; }).length; P.push({ k: 'count', done: a === f.count.length, a: a, n: f.count.length }); }
      if ((f.daily || []).length) { var a2 = f.daily.filter(function (r) { return C.v.daily[r.item_id]; }).length; P.push({ k: 'daily', done: a2 === f.daily.length, a: a2, n: f.daily.length }); }
      if ((f.once || []).length) { var a3 = f.once.filter(function (r) { return C.v.once[r.item_id] != null && C.v.once[r.item_id] !== ''; }).length; P.push({ k: 'once', done: a3 === f.once.length, a: a3, n: f.once.length }); }
      if ((f.equipment || []).length) { var a4 = f.equipment.filter(function (r) { return C.v.eq[r.item_id]; }).length; P.push({ k: 'eq', done: a4 === f.equipment.length, a: a4, n: f.equipment.length }); }
      return P;
    }
    function lines() { var f = C.form; return (f.count || []).length + (f.daily || []).length + (f.once || []).length + (f.equipment || []).length; }
    function answered() { var f = C.form, n = 0; (f.count || []).forEach(function (r) { if (C.v.count[r.item_id] != null) n++; }); (f.daily || []).forEach(function (r) { if (C.v.daily[r.item_id]) n++; }); (f.once || []).forEach(function (r) { var v = C.v.once[r.item_id]; if (v != null && v !== '') n++; }); (f.equipment || []).forEach(function (r) { if (C.v.eq[r.item_id]) n++; }); return n; }

    /* ---------- pieces ---------- */
    function langBar() {
      return '<div class="ofp-lang" role="group" aria-label="Language"><span>Language</span>' + [['en', 'English'], ['hi', 'हिन्दी'], ['both', 'Both']].map(function (l) { return '<button type="button" class="' + (C.lang === l[0] ? 'on' : '') + '" data-a="lang" data-l="' + l[0] + '">' + l[1] + '</button>'; }).join('') + '</div>';
    }
    function photosCard() {
      var f = C.form, ph = f.photos || []; if (!ph.length) return '';
      var tk = ph.filter(function (x) { return x.status === 'done'; }).length, firstOpen = ph.filter(function (x) { return x.status !== 'done' && x.task_id; })[0];
      var h = '<div class="md-card"><div class="ofp-h3"><b class="n">1</b>Photos<span class="bd-chip ' + (tk === ph.length ? 'ok' : 'warn') + '">' + tk + ' of ' + ph.length + ' taken</span></div><div class="ofp-slots">';
      ph.forEach(function (p, i) {
        if (p.status === 'done') h += '<div class="ofp-slot"><img data-bdphoto="' + p.task_id + '" alt="" style="visibility:hidden"><span class="ok">' + ic('check', 1) + '</span></div>';
        else if (!p.task_id) h += '<div class="ofp-slot empty" style="cursor:default;font-weight:600;color:#6f7480">No photo task today</div>';
        else h += '<button type="button" class="ofp-slot empty' + (C.shotBusy === p.task_id ? ' busy' : '') + '" data-a="shot" data-i="' + i + '"' + (C.shotBusy ? ' disabled' : '') + '><span class="cam">' + ic('cam') + '</span>' + (C.shotBusy === p.task_id ? 'Sending…' : 'Take photo') + '</button>';
      });
      h += '</div><div class="ofp-slotn">' + ph.map(function (p) { return '<div class="ofp-nm">' + esc(nameOnly({ name: p.label, name_hi: p.label_hi })) + (subName({ name: p.label, name_hi: p.label_hi }) ? '<small>' + esc(subName({ name: p.label, name_hi: p.label_hi })) + '</small>' : '') + '</div>'; }).join('') + '</div>';
      if (firstOpen) {
        var n = ph.indexOf(firstOpen) + 1;
        h += '<div class="ofp-look">Photo ' + n + ' must show: ' + ((firstOpen.must || []).length ? '<b>' + esc(mustLine(firstOpen.must)) + '</b>' : '<b>the whole area, clean and tidy</b>') + (firstOpen.ref_url ? ' <button type="button" class="bd-btn sm" data-a="ref" data-i="' + ph.indexOf(firstOpen) + '" style="margin-left:6px">How it should look</button>' : '') + '</div>';
      }
      return h + '</div>';
    }
    var secN = 1;
    function stepRow(r) {
      var v = C.v.count[r.item_id], lowN = below(r);
      var small = v == null ? 'min ' + fmtN(r.min) + (r.unit ? ' ' + esc(r.unit) : '') + (r.last_count != null ? ' · last count ' + fmtN(r.last_count) : '') : fmtN(v) + ' of min ' + fmtN(r.min) + ' · ' + (lowN ? 'short by ' + fmtN(Math.round((r.min - v) * 100) / 100) : 'OK');
      return '<div class="ofp-cnt' + (lowN ? ' low' : '') + '" data-row="c' + r.item_id + '"><div class="l"><b class="ofp-nm">' + name(r) + '</b><small>' + small + '</small></div>' + stepper('c:' + r.item_id, v, { red: lowN, label: nameOnly(r) }) + '</div>';
    }
    function countCard() {
      var f = C.form; if (!(f.count || []).length) return '';
      var nb = f.count.filter(below).length; secN++;
      return '<div class="md-card"><div class="ofp-h3"><b class="n">' + (secN) + '</b>Count<span class="bd-chip bad" data-nb style="' + (nb ? '' : 'display:none') + '">' + nb + ' below minimum</span></div><div class="md-note" style="margin-top:8px">Type how many there are now. Red means below the minimum.</div>' + f.count.map(stepRow).join('') + '</div>';
    }
    function dailyCard() {
      var f = C.form; if (!(f.daily || []).length) return '';
      var n = f.daily.filter(function (r) { return C.v.daily[r.item_id] === 'done'; }).length; secN++;
      return '<div class="md-card"><div class="ofp-h3"><b class="n">' + secN + '</b>Daily refill<span class="bd-chip ' + (n === f.daily.length ? 'ok' : 'warn') + '">' + n + ' of ' + f.daily.length + ' ticked</span></div>'
        + f.daily.map(function (r) { var s = C.v.daily[r.item_id]; return '<div class="ofp-tkw' + (s === 'none' ? ' isnone' : '') + '" data-row="d' + r.item_id + '"><button type="button" class="ofp-tk' + (s === 'done' ? ' on' : '') + '" data-a="tick" data-id="' + r.item_id + '" role="checkbox" aria-checked="' + (s === 'done') + '"><span class="bx">' + (s === 'done' ? ic('check') : '') + '</span><span class="t ofp-nm">' + name(r) + (s === 'none' ? '<small>Nothing left. It goes to the buying list.</small>' : '') + '</span></button><button type="button" class="none' + (s === 'none' ? ' on' : '') + '" data-a="nolft" data-id="' + r.item_id + '">Nothing left</button></div>'; }).join('') + '</div>';
    }
    function onceCard() {
      var f = C.form; if (!(f.once || []).length) return '';
      secN++;
      return '<div class="md-card"><div class="ofp-h3"><b class="n">' + secN + '</b>Low stock</div><div class="md-note" style="margin-top:8px">Not every day. Say how much is left, or Ok / Low / Empty.</div>'
        + f.once.map(function (r) {
          if (r.mode === 'tap') { var s = C.v.once[r.item_id]; return '<div class="ofp-eqr" data-row="o' + r.item_id + '"><div class="nm"><span class="ofp-nm">' + name(r) + '</span></div><div class="ofp-seg3">' + [['ok', 'Ok', 'ok'], ['low', 'Low', 'miss'], ['empty', 'Empty', 'brk']].map(function (b) { return '<button type="button" data-a="once" data-id="' + r.item_id + '" data-s="' + b[0] + '" class="' + (s === b[0] ? 'on ' + b[2] : '') + '">' + b[1] + '</button>'; }).join('') + '</div></div>'; }
          var v = C.v.once[r.item_id], lw = belowOnce(r);
          return '<div class="ofp-cnt' + (lw ? ' low' : '') + '" data-row="o' + r.item_id + '"><div class="l"><b class="ofp-nm">' + name(r) + '</b><small>' + (v == null ? 'min ' + fmtN(r.min) : fmtN(v) + ' of min ' + fmtN(r.min) + ' · ' + (lw ? 'short by ' + fmtN(r.min - v) : 'OK')) + '</small></div>' + stepper('o:' + r.item_id, typeof v === 'number' ? v : null, { red: lw, label: nameOnly(r) }) + '</div>';
        }).join('') + '</div>';
    }
    function equipCard() {
      var f = C.form; if (!(f.equipment || []).length) return '';
      secN++;
      return '<div class="md-card"><div class="ofp-h3"><b class="n">' + secN + '</b>Equipment</div>' + f.equipment.map(function (r) {
        var s = C.v.eq[r.item_id];
        return '<div class="ofp-eqr" data-row="e' + r.item_id + '"><div class="nm"><span class="ofp-nm">' + name(r) + '</span>' + (r.last_state && r.last_state !== 'present' ? '<small>last time: ' + esc(r.last_state) + '</small>' : '') + '</div><div class="ofp-seg3">' + [['present', 'Present', 'ok'], ['missing', 'Missing', 'miss'], ['broken', 'Broken', 'brk']].map(function (b) { return '<button type="button" data-a="eq" data-id="' + r.item_id + '" data-s="' + b[0] + '" class="' + (s === b[0] ? 'on ' + b[2] : '') + '">' + b[1] + '</button>'; }).join('') + '</div>'
          + (s === 'broken' ? '<div class="ofp-photo-brk">' + (C.eqPrev[r.item_id] ? '<img src="' + esc(C.eqPrev[r.item_id]) + '" alt=""><span>Photo added. It goes to To fix.</span>' : '<span>Add a photo if you can. It goes to To fix.</span>') + '<button type="button" class="bd-btn sm" data-a="eqphoto" data-id="' + r.item_id + '">' + ic('cam', 1) + ' ' + (C.eqPrev[r.item_id] ? 'Retake' : 'Photo') + '</button></div>' : '') + '</div>';
      }).join('') + '</div>';
    }
    function summary() {
      var f = C.form, buy = [], fix = [], open = [];
      (f.count || []).forEach(function (r) { if (below(r)) buy.push(fmtN(needQty(r, C.v.count[r.item_id])) + ' ' + nameOnly(r)); });
      (f.once || []).forEach(function (r) { if (belowOnce(r)) buy.push(fmtN(needQty(r, C.v.once[r.item_id])) + ' ' + nameOnly(r)); else if (r.mode === 'tap' && (C.v.once[r.item_id] === 'low' || C.v.once[r.item_id] === 'empty')) buy.push(nameOnly(r)); });
      (f.daily || []).forEach(function (r) { var s = C.v.daily[r.item_id]; if (s === 'none') buy.push(nameOnly(r)); else if (!s) open.push(nameOnly(r) + ' refill stays open until ' + (f.task && f.task.due_at ? timeOf(f.task.due_at) : 'the deadline')); });
      (f.equipment || []).forEach(function (r) { var s = C.v.eq[r.item_id]; if (s === 'missing') buy.push('1 ' + nameOnly(r)); if (s === 'broken') fix.push(nameOnly(r) + ' (broken)'); });
      if (!buy.length && !fix.length && !open.length) return '';
      return '<div class="ofp-sum"><b>When you press Submit</b><ul>' + (buy.length ? '<li>To buy: ' + esc(buy.join(', ')) + '</li>' : '') + (fix.length ? '<li>To fix: ' + esc(fix.join(', ')) + '</li>' : '') + open.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
    }
    function formView() {
      var f = C.form, P = parts(), pd = P.filter(function (x) { return x.done; }).length, pn = nm(f.place.name, f.place.name_hi, C.lang);
      secN = (f.photos || []).length ? 1 : 0;
      var h = '<div class="md ofp-chk"><div class="bar"><button class="ofp-back" type="button" data-a="close" aria-label="Back">' + ic('back') + '</button><h1 class="ofp-nm">' + esc(pn.main) + ' · ' + esc(f.place.office || '') + '<small>' + (pn.sub ? esc(pn.sub) + ' · ' : '') + (f.task && f.task.due_at ? 'Due by ' + esc(timeOf(f.task.due_at)) : '') + (f.task && f.task.late ? ' · late' : '') + '</small></h1><span class="bd-chip ' + (pd === P.length && P.length ? 'ok' : 'warn') + '">' + pd + ' of ' + P.length + ' parts done</span></div>' + langBar();
      h += photosCard() + countCard() + dailyCard() + onceCard() + equipCard();
      if (!lines() && !(f.photos || []).length) h += '<div class="md-card"><div class="bd-empty"><b>Nothing to do here</b>This place has no photos or counting set up yet.</div></div>';
      else if (!lines()) h += '<div class="md-card"><div class="bd-sub">There is nothing to count here today. Only the photos are needed.</div></div>';
      if (f.submitted) h += '<div class="md-note">This check was already sent today. You can still change an answer and send it again.</div>';
      var a = answered(), n = lines();
      if (lines()) {
        h += summary() + '<div class="ofp-err" data-err role="alert" style="' + (C.submitErr ? '' : 'display:none') + '">' + esc(C.submitErr) + '</div>';
        h += '<button class="ofp-go' + (a === n ? '' : ' dark') + '" type="button" data-a="submit"' + ((!a || C.busy) ? ' disabled' : '') + '>' + (C.busy ? 'Sending…' : (a === n ? 'Submit ' + esc(pn.main) + ' check' : 'Save what I have (' + a + ' of ' + n + ')')) + '</button>' + (a !== n && a ? '<div class="bd-sub" style="text-align:center;margin-top:6px">You can save now and finish later. The job is done when every line has an answer.</div>' : '');
      }
      return h + '</div>';
    }
    function resultView() {
      var r = C.result, f = C.form, pn = nm(f.place.name, f.place.name_hi, C.lang), h = '<div class="md ofp-chk"><div class="bar"><button class="ofp-back" type="button" data-a="close" aria-label="Back">' + ic('back') + '</button><h1 class="ofp-nm">' + esc(pn.main) + ' · ' + esc(f.place.office || '') + '<small>' + (pn.sub ? esc(pn.sub) + ' · ' : '') + (r.done ? 'Submitted' : 'Saved') + ' ' + esc(new Date().toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' })) + '</small></h1></div>';
      h += '<div class="md-card ofp-res" style="text-align:center"><div class="big" style="' + (r.done ? '' : 'background:#f2b33d') + '">' + ic('check') + '</div><div style="font-size:22px;font-weight:800;letter-spacing:-.02em">' + (r.done ? esc(pn.main) + ' check sent' : 'Saved. Not finished yet') + '</div><div style="font-size:14px;color:#5b6270;margin-top:4px;line-height:1.45">' + esc(r.message || 'Thank you.') + '</div></div>';
      if ((r.needs || []).length) h += '<div class="ofp-sent"><div class="h"><b>Sent to To buy</b><span class="bd-chip info">' + plural(r.needs.length, 'item') + '</span></div><ul>' + r.needs.map(function (n) { return '<li>' + esc(n.name) + '<span class="q">buy ' + esc(fmtN(n.qty)) + '</span></li>'; }).join('') + '</ul></div>';
      if ((r.fixes || []).length) h += '<div class="ofp-sent"><div class="h"><b>Sent to To fix</b><span class="bd-chip bad">' + plural(r.fixes.length, 'job') + '</span></div><ul>' + r.fixes.map(function (n) { return '<li>' + esc(n.title) + '</li>'; }).join('') + '</ul></div>';
      var ph = f.photos || [], miss = ph.filter(function (x) { return x.status !== 'done' && x.task_id; });
      if (ph.length) h += '<div class="ofp-sent"><div class="h"><b>Photos</b><span class="bd-chip ' + (miss.length ? 'warn' : 'ok') + '">' + (miss.length ? miss.length + ' still to take' : 'All taken') + '</span></div>' + (miss.length ? '<div class="bd-sub" style="margin-top:6px">' + miss.map(function (x) { return esc(x.label); }).join(', ') + ' still missing. Take ' + (miss.length === 1 ? 'it' : 'them') + ' before ' + esc(f.task && f.task.due_at ? timeOf(f.task.due_at) : 'the deadline') + '.</div>' : '<div class="bd-sub" style="margin-top:6px">Claude checks them.</div>') + '</div>';
      var mi = r.missing || [];
      if (mi.length) h += '<div class="ofp-sent"><div class="h"><b>Still to answer</b><span class="bd-chip warn">' + mi.length + ' open</span></div><div class="bd-sub" style="margin-top:6px">' + mi.map(function (x) { return esc(x.name); }).join(', ') + '</div></div>';
      return h + (r.done ? '<button class="md-go" type="button" data-a="close" style="min-height:56px">Back to My Day</button>' : '<button class="md-go green" type="button" data-a="cont" style="min-height:56px">Continue the check</button><button class="md-go" type="button" data-a="close" style="min-height:56px;background:#fff;color:#111;border:1px solid #cfd3da">Back to My Day</button>') + '</div>';
    }
    function draw() {
      var y = ov.scrollTop, h;
      if (C.loading) h = '<div class="md"><div class="bar" style="display:flex;gap:10px;align-items:center;padding:12px 4px"><button class="ofp-back" type="button" data-a="close" aria-label="Back">' + ic('back') + '</button><b>Opening the check…</b></div><div class="ofp-sk"></div><div class="ofp-sk"></div></div>';
      else if (C.err) h = '<div class="md"><div class="bar" style="display:flex;gap:10px;align-items:center;padding:12px 4px"><button class="ofp-back" type="button" data-a="close" aria-label="Back">' + ic('back') + '</button><b>Daily check</b></div><div class="md-card"><div class="bd-empty"><b>Cannot open this check</b>' + esc(C.err) + '<div style="margin-top:10px"><button class="bd-btn pri" type="button" data-a="retry">Try again</button></div></div></div></div>';
      else if (C.result) h = resultView();
      else h = formView();
      ov.innerHTML = h; ov.scrollTop = y;
      ov.querySelectorAll('img[data-bdphoto]').forEach(function (im) { if (HAS_BD && BD.photo) BD.photo(im.dataset.bdphoto).then(function (u) { im.src = u; im.style.visibility = ''; }).catch(function () { im.parentNode.classList.add('tile'); im.remove(); }); else { im.parentNode.classList.add('tile'); im.remove(); } });
    }
    /* patch one counting row without redrawing (typing keeps its focus) */
    function patchRow(kind, id) {
      var f = C.form, r = (kind === 'c' ? f.count : f.once).filter(function (x) { return x.item_id === id; })[0]; if (!r) return;
      var row = ov.querySelector('[data-row="' + kind + id + '"]'); if (!row) return;
      var v = kind === 'c' ? C.v.count[id] : C.v.once[id], lw = kind === 'c' ? below(r) : belowOnce(r), sm = row.querySelector('small'), stp = row.querySelector('[data-st]');
      row.classList.toggle('low', lw); stp.classList.toggle('red', lw); stp.classList.toggle('none', v == null);
      sm.textContent = v == null ? 'min ' + fmtN(r.min) + (kind === 'c' && r.unit ? ' ' + r.unit : '') : fmtN(v) + ' of min ' + fmtN(r.min) + ' · ' + (lw ? 'short by ' + fmtN(Math.round((r.min - v) * 100) / 100) : 'OK');
      var nb = f.count.filter(below).length, chip = ov.querySelector('[data-nb]'); if (chip && kind === 'c') { chip.textContent = nb + ' below minimum'; chip.style.display = nb ? '' : 'none'; }
      refreshTail();
    }
    function refreshTail() { /* summary + submit button need the new answers */
      var a = answered(), n = lines(), btn = ov.querySelector('[data-a="submit"]'), pn = nm(C.form.place.name, C.form.place.name_hi, C.lang);
      if (btn) { btn.disabled = !a || C.busy; btn.classList.toggle('dark', a !== n); btn.textContent = a === n ? 'Submit ' + pn.main + ' check' : 'Save what I have (' + a + ' of ' + n + ')'; }
      var sm = ov.querySelector('.ofp-sum'), html = summary();
      if (sm && html) sm.outerHTML = html; else if (sm && !html) sm.remove(); else if (!sm && html && btn) btn.insertAdjacentHTML('beforebegin', html);
    }
    /* ---------- events ---------- */
    function takePhoto(p) {
      var hint = (p.must || []).length ? 'The photo must show: ' + mustLine(p.must) : '';
      var send = function (blob) {
        C.shotBusy = p.task_id; draw();
        var fd = new FormData(); fd.append('task_id', p.task_id); fd.append('photo', blob, 'proof.jpg');
        return UP('bd.task_submit', fd).then(function (r) {
          C.shotBusy = 0;
          if (r && r.retake) { say(r.reason || 'Please take the photo again.', true); draw(); return false; }
          changed = true; say((r && r.message) || 'Photo saved.'); return load(true).then(function () { return true; });
        }).catch(function (e) { C.shotBusy = 0; say(errText(e), true); draw(); return false; });
      };
      if (opt.camera) return opt.camera(nameOnly({ name: p.label, name_hi: p.label_hi }) || 'Photo', hint, send);
      var fi = document.createElement('input'); fi.type = 'file'; fi.accept = 'image/*'; fi.setAttribute('capture', 'environment');
      fi.onchange = function () { if (fi.files[0]) shrinkFile(fi.files[0], 1600).then(send); }; fi.click();
    }
    function submit() {
      if (C.busy) return; var f = C.form, body = { task_id: f.task.id, counts: {}, refills: {}, once: {}, equipment: {}, note: '' }, any = false;
      (f.count || []).forEach(function (r) { var v = C.v.count[r.item_id]; if (v != null) body.counts[r.item_id] = v; });
      (f.daily || []).forEach(function (r) { var v = C.v.daily[r.item_id]; if (v) body.refills[r.item_id] = v; });
      (f.once || []).forEach(function (r) { var v = C.v.once[r.item_id]; if (v != null && v !== '') body.once[r.item_id] = v; });
      (f.equipment || []).forEach(function (r) { var v = C.v.eq[r.item_id]; if (v) body.equipment[r.item_id] = v; });
      var pics = Object.keys(C.eqPhoto).filter(function (id) { return C.v.eq[id] === 'broken'; });
      C.busy = true; C.submitErr = ''; draw();
      var p;
      if (pics.length) {
        var fd = new FormData(); fd.append('task_id', body.task_id); fd.append('note', '');
        Object.keys(body.counts).forEach(function (k) { fd.append('counts[' + k + ']', body.counts[k]); }); Object.keys(body.refills).forEach(function (k) { fd.append('refills[' + k + ']', body.refills[k]); });
        Object.keys(body.once).forEach(function (k) { fd.append('once[' + k + ']', body.once[k]); }); Object.keys(body.equipment).forEach(function (k) { fd.append('equipment[' + k + ']', body.equipment[k]); });
        p = Promise.all(pics.map(function (id) { return shrinkFile(C.eqPhoto[id], 1600).then(function (b) { fd.append('equip_photo_' + id, b, 'broken.jpg'); }); })).then(function () { return UP('of.pl_check_submit', fd); });
      } else p = POST('of.pl_check_submit', body);
      p.then(function (r) { C.busy = false; changed = true; C.result = r; C.eqPhoto = {}; return load(true); })
        .catch(function (e) { C.busy = false; C.submitErr = errText(e); draw(); var er = ov.querySelector('[data-err]'); if (er && er.scrollIntoView) er.scrollIntoView({ block: 'center' }); });
    }
    function onClick(e) {
      var sb = e.target.closest('[data-st] button[data-d]');
      if (sb && ov.contains(sb)) { stepClick(sb); return; }
      var b = e.target.closest('[data-a]'); if (!b || !ov.contains(b)) return; var a = b.dataset.a, id = Number(b.dataset.id), f = C.form;
      switch (a) {
        case 'close': close(); break;
        case 'retry': load(); break;
        case 'cont': C.result = null; draw(); break;
        case 'lang': { var l = b.dataset.l; C.lang = l; C.langSet = true; draw(); POST('of.pl_lang', { lang: l }).catch(function () {}); break; }
        case 'shot': takePhoto(f.photos[Number(b.dataset.i)]); break;
        case 'ref': { var p = f.photos[Number(b.dataset.i)]; if (HAS_BD) { var m = BD.modal('<h3>' + esc(p.label) + '</h3><div class="ofp-small" style="margin-bottom:8px">How it should look</div>' + imgTag(p.ref_url, '').replace('style="visibility:hidden"', 'style="width:100%;border-radius:16px;display:block;visibility:hidden"') + '<div class="acts"><button class="bd-btn" type="button" id="ofpRefX">Close</button></div>'); m.querySelector('#ofpRefX').onclick = BD.close; hydrateImgs(m); } break; }
        case 'tick': C.v.daily[id] = C.v.daily[id] === 'done' ? null : 'done'; draw(); break;
        case 'nolft': C.v.daily[id] = C.v.daily[id] === 'none' ? null : 'none'; draw(); break;
        case 'once': C.v.once[id] = C.v.once[id] === b.dataset.s ? null : b.dataset.s; draw(); break;
        case 'eq': C.v.eq[id] = b.dataset.s; if (b.dataset.s !== 'broken') { delete C.eqPhoto[id]; if (C.eqPrev[id]) { try { URL.revokeObjectURL(C.eqPrev[id]); } catch (x) {} delete C.eqPrev[id]; } } draw(); break;
        case 'eqphoto': { var fi = document.createElement('input'); fi.type = 'file'; fi.accept = 'image/*'; fi.setAttribute('capture', 'environment'); fi.onchange = function () { if (fi.files[0]) { C.eqPhoto[id] = fi.files[0]; if (C.eqPrev[id]) try { URL.revokeObjectURL(C.eqPrev[id]); } catch (x) {} C.eqPrev[id] = URL.createObjectURL(fi.files[0]); draw(); } }; fi.click(); break; }
        case 'submit': submit(); break;
      }
    }
    function stepClick(b) {
      var wrap = b.closest('[data-st]'), k = wrap.dataset.st.split(':'), kind = k[0], id = Number(k[1]), d = Number(b.dataset.d), store = kind === 'c' ? C.v.count : C.v.once;
      var cur = store[id], v = (typeof cur === 'number') ? cur + d : (d > 0 ? 1 : 0); if (v < 0) v = 0; store[id] = Math.round(v * 100) / 100;
      wrap.querySelector('input').value = fmtN(store[id]); patchRow(kind, id);
    }
    function onInput(e) {
      var t = e.target; if (t.dataset.in == null) return; var k = t.dataset.in.split(':'), kind = k[0], id = Number(k[1]), store = kind === 'c' ? C.v.count : C.v.once, v = parseNum(t.value);
      if (Number.isNaN(v)) return; store[id] = v; patchRow(kind, id);
    }
    function onChange() {}
    ov.addEventListener('click', onClick); ov.addEventListener('input', onInput); ov.addEventListener('change', onChange);
    ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    load();
    return { close: close };
  };

  /* ======================================================================= BOSS VIEW: places of a section ======================================================================= */
  OP.mountBoss = function (host, o) {
    o = o || {}; var nameWant = String(o.sectionName || '').trim().toLowerCase(); if (!nameWant) { host.innerHTML = ''; return; }
    function ownerLine(pl) { var ow = pl.owner || {}; if (ow.using === 'place' && (ow.names || []).length) return esc(ow.names[0]) + (ow.names[1] ? ' · backup ' + esc(ow.names[1]) : ''); if (ow.using === 'office' && (ow.office_cleaners || []).length) return esc(ow.office_cleaners.join(', ')) + ' (office cleaners)'; return '<b style="color:#9a6200">nobody yet</b>'; }
    GET('of.pl_offices').then(function (r) {
      var off = (r.offices || []).filter(function (x) { return String(x.name).trim().toLowerCase() === nameWant; })[0]; if (!off || !off.places) { host.innerHTML = ''; return; }
      return GET('of.pl_list', { office_id: off.office_id }).then(function (L) {
        var P = L.places || [], on = P.filter(function (p) { return p.state === 'on'; }), dr = P.filter(function (p) { return p.state === 'draft'; });
        return Promise.all(on.filter(function (p) { return p.today && p.today.submitted; }).map(function (p) { return GET('of.pl_today', { zone_id: p.zone_id }).then(function (t) { return [p, t]; }).catch(function () { return null; }); })).then(function (T) {
          T = T.filter(Boolean); var h = '';
          if (on.length) {
            h += '<div class="bd-card"><div class="bd-h"><h2>Places</h2><span class="bd-chip ok">' + plural(on.length, 'place') + ' on</span></div><div class="bd-sub">Made automatically from each place. They pause when the place is off and go when it is removed.</div>';
            on.forEach(function (p) {
              var t = p.today || {}, pts = t.photo_tasks || [], open = pts.filter(function (x) { return x.status !== 'done'; }).length, label = p.name + ' · ' + (L.office ? L.office.name : off.name), c = p.counts || {}, bm = (t.below_min || []).length;
              h += '<div class="ofp-duty new" style="margin-top:8px"><div class="l"><div class="t ofp-nm">Clean: ' + esc(label) + ' <span class="bd-chip info">Place</span></div><div class="s">' + plural(c.photos || 0, 'photo') + ' · every day · by ' + esc(t12(p.photo_time || '')) + '<br>' + ownerLine(p) + '</div></div><div class="r"><span class="b2-st ' + (pts.length && !open ? 'ontrack' : 'waiting') + '">' + (pts.length && !open ? 'Done' : 'Due ' + esc(t12(p.photo_time || ''))) + '</span></div></div>';
              h += '<div class="ofp-duty new"><div class="l"><div class="t ofp-nm">Count and refill: ' + esc(label) + ' <span class="bd-chip info">Place</span></div><div class="s">Count ' + plural(c.counting || 0, 'item') + ', ' + plural(c.daily || 0, 'refill') + ', ' + plural(c.equipment || 0, 'equipment', 'equipment') + ' · every day · by ' + esc(t12(p.check_by || '')) + '<br>' + ownerLine(p) + '</div></div><div class="r"><span class="b2-st ' + (t.submitted ? (bm ? 'late' : 'ontrack') : 'waiting') + '">' + (t.submitted ? (bm ? plural(bm, 'item') + ' below min' : 'Counted') : 'Due ' + esc(t12(p.check_by || ''))) + '</span></div></div>';
            });
            h += '</div>';
          }
          T.forEach(function (x) {
            var p = x[0], t = x[1], needs = t.needs || [], fixes = t.fixes || [], n = needs.length + fixes.length; if (!n) return;
            h += '<div class="bd-card"><div class="bd-h"><h2>From today\'s ' + esc(p.name) + ' check</h2><span class="bd-chip warn">' + n + ' open</span></div><div class="bd-sub">Sent by ' + esc(t.by || 'someone') + (t.at ? ' at ' + esc(timeOf(t.at)) : '') + '. Nobody is fined: the penalty topic is not live, so missed ones are only proposed.</div>';
            if (needs.length) h += '<div style="margin-top:8px"><div class="bd-lb" style="margin-bottom:2px">To buy · ' + needs.length + '</div>' + needs.map(function (q) { return '<div class="ofp-from"><div class="t ofp-nm">' + esc(fmtN(q.qty)) + ' ' + esc(q.item) + '<small>' + esc(p.name) + ' · ' + esc(q.reason || '') + '</small></div><span class="bd-chip info">To buy</span></div>'; }).join('') + '</div>';
            if (fixes.length) h += '<div style="margin-top:10px"><div class="bd-lb" style="margin-bottom:2px">To fix · ' + fixes.length + '</div>' + fixes.map(function (q) { return '<div class="ofp-from"><div class="t ofp-nm">' + esc(q.title) + '<small>' + esc(p.name) + ' · ' + esc(q.status || 'reported') + '</small></div><span class="bd-chip bad">' + esc(q.status || 'Reported') + '</span></div>'; }).join('') + '</div>';
            h += '<div class="ofp-acts" style="margin-top:12px">' + (needs.length ? '<a class="bd-btn" href="dept_work.html">Open To buy ' + ic('arrow', 1) + '</a>' : '') + (fixes.length ? '<a class="bd-btn" href="dept_fix.html">Open To fix ' + ic('arrow', 1) + '</a>' : '') + '</div></div>';
          });
          dr.forEach(function (p) { h += '<div class="bd-card" style="border-left:4px solid #6d5dfc;border-radius:16px;padding:13px 15px"><div style="font-size:13.5px;line-height:1.5"><b>' + esc(p.name) + ' · ' + esc(off.name) + '</b> is a draft' + (p.owner && p.owner.using === 'place' ? '' : ' with no owner') + ', so no tasks are made yet. <a class="bd-btn sm" style="margin-left:4px" href="office_care.html?id=' + off.office_id + '&zone=' + p.zone_id + '">Open the place</a></div></div>'; });
          host.innerHTML = h;
        });
      });
    }).catch(function () { host.innerHTML = ''; });
  };

  OP.imgUrl = imgUrl; OP.parseMust = parseMust; OP.ownerLine = null;
})();
