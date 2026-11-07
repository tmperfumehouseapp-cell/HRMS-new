/* Parcels and tracking (Dispatch) — the whole page body.
   1  "Claude: what needs fixing in Dispatch" (compact bar, details underneath)
   2  Last 25 days strip (small filter: days, store, own dates; tap a day = only that day)
   3  All parcels: 8 numbered groups in working order, ONE Excel-style table, add tracking IDs / record actions right in the rows
   4  Untraceable parcels (group 1): one owner, a remark every day, 7-day dots, a history under each row. Penalties are NOT managed here: one line points to Boss > Penalties.
   DSP.mount(element)   Needs api.js, boss_common.js (BD). hq_chat.js is optional (Claude chat inside Details).
   The page keeps #topBtns (Download Excel + Read new orders now) and the not-live / test-mode banners itself. */
const DSP = (() => {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const fd = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? (+m[3]) + ' ' + MON[+m[2] - 1] : (s || ''); };
  const fdt = (s) => { if (!s) return ''; const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s); return m ? (+m[3]) + ' ' + MON[+m[2] - 1] + ' ' + m[4] + ':' + m[5] : s; };
  const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const inr = (n) => '₹' + Math.round(+n || 0).toLocaleString('en-IN');
  const parseT = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]).getTime() : 0; };

  /* the 8 groups, in working order: [name, colour, background, tile line, band line] */
  const G = {
    1: ['Untraceable', '#b42318', '#fff1f1', 'courier cannot find them', 'held somewhere · a remark is needed on every one, every day'],
    2: ['No tracking ID', '#e5484d', '#fff4f3', '', 'needs a tracking ID now · add it right here'],
    3: ['Tracking ID given, not in transit', '#d97706', '#fff7e6', '', 'the courier has not scanned the parcel yet · a long wait is a problem'],
    4: ['In transit', '#2b6fd6', '#eef4ff', 'moving normally', 'moving normally'],
    5: ['Not delivered & RTO', '#c2410c', '#fff1e8', 'failed or returning', 'failed, returned or on hold'],
    6: ['Delivered', '#16945a', '#edf8f2', 'COD money not received', 'COD money not received yet'],
    7: ['Remittance arrived', '#0f766e', '#e4f6f3', 'money is in', 'money is in (or prepaid)'],
    8: ['Cancelled / case ended', '#6b7280', '#f3f4f6', '', ''],
    9: ['Waiting for Admin', '#7c5cf0', '#f6f2ff', '', 'tracking IDs added by hand · move to In transit after Admin approves'],
    10: ['Old, no tracking ID', '#9ca3af', '#f9fafb', '', 'ordered more than 10 days ago · not counted as a problem · you can still add the ID'],
  };
  const ORDER = [1, 2, 9, 3, 4, 5, 6, 7, 8, 10];   // 9 sits between 2 and 3, 10 is last; both are hidden when empty
  const DAYSCH = [7, 10, 15, 25, 30, 60];
  const STORE = { tmperfumehouse: ['TMPH', 'TM Perfume House'], tmfragrance: ['TMF', 'TM Fragrance'] };
  const sShort = (k) => (STORE[k] ? STORE[k][0] : String(k || '').slice(0, 6).toUpperCase());
  const sName = (k) => (STORE[k] ? STORE[k][1] : k);

  let cssDone = false;
  function css() {
    if (cssDone) return; cssDone = true;
    const s = document.createElement('style');
    s.textContent = [
      '.dr-card{background:var(--surface,#fff);border-radius:var(--radius,22px);padding:16px 18px;margin-bottom:12px;box-shadow:0 1px 2px rgba(20,22,26,.04),0 6px 18px -10px rgba(20,22,26,.12);}',
      '.dr-h{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;} .dr-h h2{margin:0;font-size:17px;font-weight:800;letter-spacing:-.01em;} .dr-h .sp{flex:1;}',
      '.dr-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;}',
      '.dr-ch{border:1px solid #dfe3e8;background:#fff;border-radius:99px;padding:6px 13px;font:inherit;font-size:13px;font-weight:600;cursor:pointer;color:#14161a;} .dr-ch:hover{background:#faf8f3;} .dr-ch.on{background:#111827;color:#fff;border-color:#111827;} .dr-ch.sm{padding:3px 11px;font-size:12px;}',
      '.dr-in{border:1px solid #dfe3e8;border-radius:12px;padding:6px 10px;font:inherit;font-size:13px;background:#fff;min-height:34px;} .dr-in.sm{height:28px;min-height:28px;font-size:12px;padding:0 8px;} input.dr-in[type=search]{min-width:200px;border-radius:99px;padding:6px 14px;}',
      '.dr-lbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#7a8499;}',
      '.dr-tiles{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:8px;} @media(max-width:1100px){.dr-tiles{grid-template-columns:repeat(4,minmax(0,1fr));}}',
      '.dr-tile{border:1px solid transparent;border-left:4px solid var(--c);background:var(--bg);border-radius:14px;padding:8px 10px;cursor:pointer;text-align:left;font:inherit;color:inherit;min-width:0;} .dr-tile:hover{filter:brightness(.97);} .dr-tile.on{outline:2px solid var(--c);}',
      '.dr-tile small{display:block;font-size:11px;line-height:1.25;min-height:25px;font-weight:700;color:var(--c);text-transform:uppercase;letter-spacing:.03em;} .dr-tile small span{opacity:.6;} .dr-tile b{display:block;font-size:24px;font-weight:800;letter-spacing:-.02em;line-height:1.1;} .dr-tile i{display:block;font-style:normal;font-size:11px;color:#6b7280;white-space:normal;min-height:14px;}',
      '.dr-tw{overflow-x:auto;border:1px solid #e3e6ea;border-radius:10px;margin-top:10px;}',
      '.dr-t{width:100%;border-collapse:collapse;font-size:12px;line-height:1.15;font-variant-numeric:tabular-nums;zoom:.93;}',
      '.dr-t th{background:#f3f4f6;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;padding:4px 8px;border-bottom:1px solid #d5d9df;border-right:1px solid #e6e8ec;white-space:nowrap;text-align:left;font-weight:700;}',
      '.dr-t td{padding:1px 6px;height:21px;border-bottom:1px solid #eceef1;border-right:1px solid #f1f2f5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px;}',
      '.dr-t tr td:first-child{border-left:3px solid var(--c,#ccc);color:#9aa1ad;text-align:right;} .dr-t tbody tr.rw:hover td{filter:brightness(.96);}',
      '.dr-t .st{font-weight:800;color:var(--c);max-width:none;} .dr-t .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;} .dr-t .late{color:#d9423b;font-weight:800;} .dr-t td.ac{max-width:none;overflow:visible;white-space:nowrap;}',
      '.dr-t a.lk{color:#1d4ed8;font-weight:700;text-decoration:underline;}',
      '.dr-dn{border:1px solid #cfd5dd;background:#fff;color:#14161a;border-radius:99px;padding:0 9px;height:17px;line-height:15px;font:inherit;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap;} .dr-dn:hover{background:#111827;color:#fff;border-color:#111827;} .dr-dn.on{background:#16945a;border-color:#16945a;color:#fff;} .dr-dn.on:hover{background:#0f7a49;} .dr-dn[disabled]{opacity:.6;cursor:default;} .dr-t tr.dn td{opacity:.62;} .dr-t tr.dn td:nth-child(2){opacity:1;}',
      '.dr-t tr.dr-band td{background:#111827;color:#fff;font-weight:800;font-size:11.5px;letter-spacing:.03em;padding:4px 10px;height:23px;border-left:0;max-width:none;text-align:left;white-space:normal;cursor:pointer;} .dr-t tbody tr.dr-band:hover td{filter:none;background:#1f2937;} .dr-t tr.dr-band td b{color:#ffd9a8;} .dr-t tr.dr-band.zero td{cursor:default;opacity:.75;} .dr-t tr.dr-band.flash td{animation:drbf 1.4s;} @keyframes drbf{from{background:#b45309}to{background:#111827}}',
      '.dr-t tr.dr-band .x{float:right;font-weight:700;opacity:.85;} .dr-t tr.dr-band .x button{margin-left:8px;}',
      '.dr-more td{text-align:center;color:#6b7280;font-weight:700;background:#fafbfc;max-width:none !important;} .dr-more button{border:1px solid #cfd5dd;background:#fff;border-radius:99px;font:inherit;font-size:11px;font-weight:800;padding:0 10px;height:19px;cursor:pointer;margin-left:6px;} .dr-more button:hover{background:#111827;color:#fff;}',
      '.dr-add{border:1px solid #b42318;color:#b42318;background:#fff;border-radius:99px;padding:0 10px;height:19px;font:inherit;font-size:11px;font-weight:800;cursor:pointer;} .dr-add:hover{background:#b42318;color:#fff;}',
      '.dr-go{border:0;background:#12a150;color:#fff;border-radius:99px;padding:0 11px;height:21px;font:inherit;font-size:11px;font-weight:800;cursor:pointer;} .dr-go:hover{background:#0d8a43;} .dr-go.dk{background:#111827;} .dr-go[disabled]{opacity:.55;cursor:default;} .dr-no{border:1px solid #cfd5dd;background:#fff;color:#14161a;border-radius:99px;padding:0 9px;height:20px;font:inherit;font-size:11px;font-weight:800;cursor:pointer;}',
      '.dr-sel{height:22px;padding:0 4px;font:inherit;font-size:11.5px;border:1px solid #cfd5dd;border-radius:7px;background:#fff;max-width:130px;} .dr-tx{height:22px;padding:0 7px;font:inherit;font-size:11.5px;border:1px solid #cfd5dd;border-radius:7px;background:#fff;}',
      '.dr-f{display:flex;flex-direction:column;gap:5px;padding:3px 0;} .dr-f .dr-sel{max-width:82px;} .dr-f .l{gap:4px;} .dr-f .l{display:inline-flex;gap:5px;align-items:center;} .dr-f .tn{border:1.5px solid #111827;width:78px;font-family:ui-monospace,Menlo,Consolas,monospace;} .dr-f .lk2{width:160px;font-size:11px;color:#1d4ed8;font-family:ui-monospace,Menlo,Consolas,monospace;}',
      '.dr-ord{border:0;background:none;padding:0;font:inherit;font-weight:800;color:#1d4ed8;cursor:pointer;text-decoration:underline;text-underline-offset:2px;} .dr-ord:hover{color:#0b3aa8;}',
      '.dr-sp{font-size:11.5px;color:#4a505c;margin-top:2px;white-space:nowrap;} td a.dr-pill{text-decoration:none;margin-top:3px;}',
      '#dsChat{display:none;} #dsChat.on{display:block;} #dsChat .dsc-bg{position:fixed;inset:0;background:rgba(20,22,26,.35);z-index:200;} #dsChat .dsc-p{position:fixed;top:0;right:0;bottom:0;width:min(520px,100%);background:#fff;z-index:201;display:flex;flex-direction:column;box-shadow:-8px 0 30px rgba(0,0,0,.15);}',
      '#dsChat .dsc-h{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #e6e8ec;font-size:13.5px;} #dsChat .dsc-h b{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;} #dsChat .dsc-x{border:0;background:#f1f3f6;border-radius:99px;width:32px;height:32px;cursor:pointer;font-size:14px;} #dsChat iframe{flex:1;border:0;width:100%;}',
      '.dr-pill{display:inline-flex;align-items:center;height:18px;padding:0 8px;border-radius:99px;font-size:11px;font-weight:800;background:#f1f3f6;color:#3a3f4a;} .dr-pill.ok{background:#dff5e8;color:#16794a;} .dr-pill.am{background:#fff3cd;color:#8a5a00;} .dr-pill.pu{background:#f0ebff;color:#5b3fd6;} .dr-pill.bad{background:#fdecea;color:#a02b25;}',
      '.dr-rm{border:1px solid #b7d9d4;background:#fff;color:#0f766e;border-radius:99px;padding:0 9px;height:17px;line-height:15px;font:inherit;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap;} .dr-rm:hover{background:#0f766e;color:#fff;} .dr-rm.on{background:#0f766e;color:#fff;border-color:#0f766e;} .dr-rm:disabled{opacity:.6;cursor:default;} .dr-cod{font-size:11px;color:#6b7280;margin-left:5px;}',
      '.dr-15{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:7px;margin-top:8px;} .dr-d{border:1px solid #dfe3e8;background:#fff;border-radius:14px;padding:7px 9px 8px;cursor:pointer;text-align:left;font:inherit;color:#14161a;} .dr-d:hover{background:#faf8f3;} .dr-d.on{background:#111827;color:#fff;border-color:#111827;} .dr-d.zero{opacity:.5;}',
      '.dr-d small{display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;opacity:.7;} .dr-d b{display:block;font-size:20px;font-weight:800;line-height:1.1;letter-spacing:-.02em;} .dr-d em{display:block;font-style:normal;font-size:11px;font-weight:700;color:#d9423b;min-height:14px;} .dr-d em.pu{color:#5b3fd6;} .dr-d.on em{color:#ffb4ae;} .dr-d .pr{display:block;height:4px;border-radius:3px;background:#e6e9ee;margin-top:4px;overflow:hidden;} .dr-d .pr i{display:block;height:100%;background:#16945a;}',
      '.dr-fx{display:grid;grid-template-columns:1fr 1fr;gap:0 26px;margin-top:6px;} @media(max-width:900px){.dr-fx{grid-template-columns:1fr;}} .dr-fx>div{display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0;border-top:1px solid #f0f1f4;} .dr-fx .d{width:9px;height:9px;border-radius:50%;flex:none;} .dr-fx span.t{flex:1;min-width:0;} .dr-fx a,.dr-fx button.a{font-weight:800;font-size:12px;color:#c2410c;white-space:nowrap;background:none;border:0;cursor:pointer;font-family:inherit;padding:0;text-decoration:none;}',
      '.dr-pb{display:flex;gap:10px;align-items:flex-start;padding:11px 0;border-top:1px solid #eef0f3;} .dr-pb .d{width:11px;height:11px;border-radius:50%;margin-top:5px;flex:none;} .dr-pb .t{font-weight:800;font-size:14.5px;} .dr-pb .w{font-size:13px;color:#4a505c;margin-top:2px;line-height:1.45;} .dr-pb .f{font-size:13px;margin-top:6px;background:#fff8ea;border-radius:12px;padding:7px 11px;color:#6b4a00;line-height:1.45;} .dr-pb .a{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}',
      '.dr-sr{border:1px solid #dfe3e8;border-radius:16px;padding:12px 14px;margin-top:12px;background:#fcfcfd;} .dr-sr.flash{animation:drfl 1.4s;} @keyframes drfl{from{background:#fff3d6}to{background:#fcfcfd}} .dr-sr .g{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;margin-top:8px;} .dr-sr ol{margin:8px 0 0 18px;padding:0;font-size:13px;line-height:1.55;color:#4a505c;} .dr-sr code{background:#f1f3f6;border-radius:6px;padding:1px 6px;font-size:12px;}',
      '.dr-cl{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px;} .dr-cl td,.dr-cl th{padding:4px 6px;border-bottom:1px solid #eef0f3;text-align:left;} .dr-cl th{font-size:11px;text-transform:uppercase;color:#7a8499;} .dr-cl input[type=text]{width:100%;}',
      '.dr-t tr.dr-pn td{background:#fff;white-space:normal;vertical-align:middle;max-width:none;} .dr-t tr.dr-pn td:first-child{text-align:left;color:#14161a;}',
      '.dr-sug{background:#edf8f2;color:#166534;border-radius:14px;padding:10px 13px;font-size:13px;line-height:1.5;margin:4px 0 12px;}',
      '.dr-t td.uc{white-space:normal;min-width:318px;max-width:318px;padding:3px 6px;overflow:visible;} .dr-uf{display:flex;flex-wrap:wrap;gap:4px 5px;align-items:center;} .dr-uf .dr-tx{box-sizing:border-box;} .dr-uf .dr-tx[data-un]{flex:1 1 190px;width:190px;min-width:0;} .dr-uf .dr-sel{max-width:84px;} .dr-uf input[type=date]{width:100px;flex:none;padding:0 3px;font-size:11px;}',
      '.dr-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-left:2px;vertical-align:middle;background:#d7dbe2;} .dr-dot.ok{background:#16945a;} .dr-dot.miss{background:#d9423b;} .dr-dot.todo{background:#fde7b0;box-shadow:0 0 0 2px #f59e0b inset;} .dr-dot.na{width:6px;height:6px;margin:0 1px 0 4px;}',
      '.dr-tdy{display:inline-flex;align-items:center;gap:3px;height:19px;padding:0 8px;border-radius:99px;background:#dff5e8;color:#16794a;font-size:11px;font-weight:800;white-space:nowrap;}',
      '.dr-t tr.dr-sub td{background:#fff;color:#6b7280;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;height:18px;padding:1px 6px;border-left:0;max-width:none;text-align:left;} .dr-t tr.dr-sub td:first-child{border-left:3px solid #b42318;}',
      '.dr-t tr.dr-hp td{background:#fffdf6;white-space:normal;max-width:none;padding:12px 16px 14px;text-align:left;color:#14161a;overflow:visible;border-left:0;height:auto;} .dr-t tr.dr-hp td:first-child{border-left:3px solid #d97706;}',
      '.dr-hg{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(260px,1fr);gap:0 26px;} @media(max-width:1000px){.dr-hg{grid-template-columns:1fr;}} .dr-hg .k{font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;margin-bottom:6px;}',
      '.dr-hg .e{display:flex;gap:12px;align-items:baseline;padding:4px 0;font-size:13px;line-height:1.35;} .dr-hg .e .d{width:88px;flex:none;font-weight:800;} .dr-hg .e .tm{flex:none;min-width:72px;text-align:center;font-size:11px;font-weight:800;border-radius:99px;padding:1px 8px;background:#dff5e8;color:#16794a;} .dr-hg .e .tm.bad{background:#fde3e1;color:#b42318;} .dr-hg .e .tm.end{background:#e4e8ff;color:#3b4bb5;} .dr-hg .e .bad{color:#b42318;}',
      '.dr-hg .side{border-left:1px solid #eadfc7;padding-left:22px;font-size:13px;line-height:1.7;} @media(max-width:1000px){.dr-hg .side{border-left:0;padding-left:0;margin-top:10px;}} .dr-hg .side .lb{display:inline-block;min-width:92px;font-size:11px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;} .dr-hg .side .hb{border:1px solid #cfd5dd;background:#fff;border-radius:99px;padding:0 11px;height:24px;font:inherit;font-size:11.5px;font-weight:800;cursor:pointer;margin-right:5px;} .dr-hg .side .hb:hover{background:#111827;color:#fff;}',
      '.dr-leg{display:flex;gap:16px;flex-wrap:wrap;align-items:center;font-size:12px;color:#6b7280;margin:8px 2px 0;} .dr-leg .dr-dot{margin:0 4px 0 0;} .dr-leg .sp{flex:1;}',
      '.dr-two{display:grid;grid-template-columns:1fr 1fr;gap:10px;} .dr-two .bd-f{margin:0 0 10px;}',
      '.dl-b{display:inline-flex;align-items:center;gap:5px;height:17px;padding:0 8px 0 6px;margin-left:6px;border-radius:99px;border:1px solid #d5d9df;background:#fff;font:inherit;font-size:11px;font-weight:800;color:#5b6270;cursor:pointer;vertical-align:middle;white-space:nowrap;line-height:15px;} .dl-b:hover{background:#111827;color:#fff;border-color:#111827;} .dl-b i{width:7px;height:7px;border-radius:50%;background:#c3c8d1;flex:none;} .dl-b.on{border-color:#bfe6cf;background:#effaf3;color:#16794a;} .dl-b.on i{background:#16a34a;animation:dlp 1.8s infinite;} .dl-b.mid{border-color:#f3dd9a;background:#fff8e6;color:#8a5a00;} .dl-b.mid i{background:#f59e0b;} .dl-b.old{color:#7a8499;} @keyframes dlp{0%{box-shadow:0 0 0 0 rgba(22,163,74,.5)}70%{box-shadow:0 0 0 6px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}} @media (pointer:coarse){.dl-b{height:28px;padding:0 11px 0 9px;font-size:11.5px}}',
      '.dl-ov{position:fixed;inset:0;z-index:9000;background:rgba(17,24,39,.46);display:flex;justify-content:center;align-items:center;padding:3vh 2vw;animation:dlf .15s ease-out;} @keyframes dlf{from{opacity:0}to{opacity:1}} @keyframes dls{from{transform:translateX(40px);opacity:.4}to{transform:none;opacity:1}} @keyframes dlu{from{transform:translateY(40px)}to{transform:none}}',
      '.dl-pn{width:min(980px,100%);max-height:94vh;background:#fff;display:flex;flex-direction:column;border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.28);animation:dlu .2s ease-out;color:#14161a;overflow:hidden;} @media (max-width:700px){.dl-ov{padding:0;align-items:stretch}.dl-pn{width:100%;max-height:none;height:100%;border-radius:0;}}',
      '.dl-cols{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:18px;} @media (max-width:820px){.dl-cols{grid-template-columns:1fr;}} .dl-jr{border-left:1px solid #eef0f3;padding-left:18px;} @media (max-width:820px){.dl-jr{border-left:0;padding-left:0;border-top:1px solid #eef0f3;padding-top:12px;}}',
      '.oc-sec{border:1px solid #eef0f3;border-radius:16px;padding:11px 13px;margin-bottom:10px;} .oc-sec .t{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#7a8499;margin-bottom:7px;display:flex;align-items:center;gap:8px;} .oc-sec .t span{margin-left:auto;text-transform:none;letter-spacing:0;font-weight:700;}',
      '.oc-g{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px 12px;} @media (max-width:520px){.oc-g{grid-template-columns:repeat(2,minmax(0,1fr));}} .oc-g div{min-width:0;} .oc-g small{display:block;font-size:11px;color:#7a8499;line-height:1.3;} .oc-g b{display:block;font-size:13.5px;line-height:1.35;overflow-wrap:anywhere;} .oc-g .ok{color:#16794a;} .oc-g .bad{color:#b42318;}',
      '.oc-li{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:3px 0;border-bottom:1px dashed #eef0f3;} .oc-li:last-child{border-bottom:0;} .oc-li em{font-style:normal;color:#7a8499;font-size:11.5px;} .oc-cx{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13px;padding:4px 0;} .oc-cx a{font-weight:800;color:#1d4ed8;text-decoration:none;} .oc-chip{display:inline-block;border-radius:99px;padding:1px 9px;font-size:11px;font-weight:800;background:#f1f3f6;color:#3a3f4a;} .oc-chip.bad{background:#fdecea;color:#a02b25;} .oc-chip.ok{background:#dff5e8;color:#16794a;} .oc-chip.pu{background:#ede7ff;color:#5b34c4;}',
      '.oc-shot img{max-width:100%;max-height:300px;border-radius:12px;border:1px solid #eef0f3;display:block;margin-top:8px;} .oc-btn{border:1px solid #cfd5dd;background:#fff;border-radius:99px;padding:0 12px;height:28px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;} .oc-btn:hover{background:#111827;color:#fff;} .oc-tl{font-size:12.5px;line-height:1.5;} .oc-tl div{display:flex;gap:10px;padding:2px 0;} .oc-tl b{flex:none;width:122px;color:#4a505c;font-weight:700;}',
      '.dl-tn{border:0;background:none;padding:0;font:inherit;font-weight:700;color:#1d4ed8;cursor:pointer;text-decoration:underline;text-underline-offset:2px;} .dl-tn:hover{color:#0b3aa8;} .dr-oc{border:1px solid #d5d9df;background:#fff;border-radius:99px;height:18px;padding:0 6px;font-size:11px;cursor:pointer;margin-left:4px;vertical-align:middle;line-height:16px;} .dr-oc:hover{background:#111827;color:#fff;}',
      '.dl-hd{padding:16px 64px 14px 18px;border-bottom:1px solid #eef0f3;position:relative;} .dl-x{position:absolute;top:6px;right:6px;width:44px;height:44px;border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280;border-radius:12px;} .dl-x:hover{background:#f1f3f6;}',
      '.dl-ord{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#7a8499;} .dl-st{font-size:23px;font-weight:800;letter-spacing:-.02em;margin:5px 0 3px;line-height:1.15;} .dl-sub{font-size:12.5px;color:#4a505c;line-height:1.5;} .dl-sub a{color:#1d4ed8;font-weight:700;} .dl-eta{display:inline-block;margin-top:8px;background:#eef4ff;color:#1d4ed8;border-radius:99px;font-size:12px;font-weight:800;padding:3px 11px;} .dl-eta.ok{background:#e3f7ec;color:#16794a;} .dl-eta.bad{background:#fdecea;color:#a02b25;}',
      '.dl-steps{display:flex;margin:16px 0 2px;} .dl-s{flex:1;text-align:center;font-size:11px;font-weight:700;color:#9aa1ad;position:relative;line-height:1.25;padding:0 2px;} .dl-s i{display:block;width:14px;height:14px;border-radius:50%;background:#e3e6ea;margin:0 auto 6px;position:relative;z-index:1;} .dl-s:before{content:"";position:absolute;top:6px;left:-50%;width:100%;height:3px;background:#e3e6ea;} .dl-s:first-child:before{display:none;} .dl-s.done i,.dl-s.done:before{background:#16a34a;} .dl-s.done{color:#14161a;} .dl-s.now i{box-shadow:0 0 0 4px rgba(22,163,74,.22);} .dl-s.bad i,.dl-s.bad:before{background:#e5484d;} .dl-s.bad{color:#a02b25;}',
      '.dl-bd{overflow-y:auto;padding:14px 18px 10px;flex:1;-webkit-overflow-scrolling:touch;} .dl-note{background:#fff8e6;color:#7a5200;border-radius:12px;padding:9px 12px;font-size:12.5px;line-height:1.45;margin-bottom:12px;} .dl-h{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#7a8499;margin:2px 0 10px;}',
      '.dl-ev{display:flex;gap:13px;padding:0 0 15px;position:relative;} .dl-ev:before{content:"";position:absolute;left:5px;top:16px;bottom:-1px;width:2px;background:#e8ebef;} .dl-ev:last-child:before{display:none;} .dl-ev i{width:12px;height:12px;border-radius:50%;background:#cfd5dd;margin-top:3px;flex:none;position:relative;z-index:1;} .dl-ev:first-child i{background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,.18);} .dl-ev b{display:block;font-size:13.5px;line-height:1.35;} .dl-ev span{display:block;font-size:12px;color:#6b7280;line-height:1.45;margin-top:1px;} .dl-ev em{font-style:normal;font-weight:700;color:#4a505c;}',
      '.dl-empty{text-align:center;color:#7a8499;font-size:13px;line-height:1.5;padding:26px 10px;} .dl-empty b{display:block;font-size:26px;margin-bottom:6px;}',
      '.dl-ft{padding:12px 18px calc(12px + env(safe-area-inset-bottom));border-top:1px solid #eef0f3;display:flex;gap:8px;align-items:center;flex-wrap:wrap;} .dl-ft .lv{flex:1;min-width:150px;font-size:12px;color:#6b7280;} .dl-ft .lv i{display:inline-block;width:8px;height:8px;border-radius:50%;background:#c3c8d1;margin-right:6px;} .dl-ft .lv.on i{background:#16a34a;animation:dlp 1.8s infinite;} .dl-btn{min-height:44px;border-radius:12px;padding:0 16px;font:inherit;font-weight:800;font-size:13px;border:1px solid #cfd5dd;background:#fff;color:#14161a;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;} .dl-btn:hover{background:#f1f3f6;} .dl-btn.p{background:#111827;color:#fff;border-color:#111827;} .dl-btn[disabled]{opacity:.55;cursor:default;}',
      '.dr-lv{border-top:1px solid #eef0f3;margin-top:10px;padding-top:10px;} .dr-lv .u{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0;} .dr-lv code{background:#f1f3f6;border-radius:6px;padding:3px 8px;font-size:12px;word-break:break-all;} .dr-lv ol{margin:8px 0 0 18px;padding:0;font-size:13px;line-height:1.6;color:#4a505c;}',
    ].join('\n');
    document.head.appendChild(s);
  }

  function mount(el) {
    css();
    let S = { sdays: 25, from: '', to: '', day: '', store: '', q: '', open: { 1: 1, 2: 1, 9: 1, 3: 1 }, lim: {}, fix: true, details: false, hist: {} };
    try { const x = JSON.parse(sessionStorage.getItem('dsp_v2') || 'null'); if (x && typeof x === 'object') { S = Object.assign(S, x); S.lim = S.lim || {}; S.open = S.open || {}; S.hist = S.hist || {}; } } catch (e) {}
    const keep = () => { try { sessionStorage.setItem('dsp_v2', JSON.stringify(S)); } catch (e) {} };
    let qt = 0, R = null, PB = null, CL = null, busy = false, again = false, chatOn = false, firstDone = false;
    /* ---------- Payment chip (Finance): a tiny second line under the order number. Read-only: it changes no Dispatch rule. ---------- */
    let PAY = {}, PKEEP = 20, PSHOW = false; try { PSHOW = localStorage.getItem('fi_show_all') === '1'; } catch (e) {}
    const okey = (x) => String(x == null ? '' : x).toLowerCase().replace(/[^a-z0-9]/g, '');
    const pinr = (n) => '₹' + Math.round(+n || 0).toLocaleString('en-IN');
    const pday = (d) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d || ''); return m ? (+m[3]) + ' ' + ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] : ''; };
    (function payCss() { if (document.getElementById('fpCss')) return; const st = document.createElement('style'); st.id = 'fpCss'; st.textContent = '.fp{display:inline-flex;gap:4px;align-items:center;flex-wrap:nowrap;white-space:nowrap;margin-left:8px;vertical-align:middle;font-size:11px;font-weight:800;line-height:1.2}.fp i{font-style:normal;padding:0 7px;border-radius:999px;background:#eef0f3;color:#4b5563}.fp i.ok{background:#dcf5e8;color:#157a3c}.fp i.w{background:#fff4e0;color:#b45309}.fp i.b{background:#fdecea;color:#b42318}.fp button{border:0;border-radius:999px;padding:0 8px;font:800 11px inherit;background:#b42318;color:#fff;cursor:pointer}.fpbar{display:flex;gap:8px;align-items:center;font-size:11.5px;color:#7a8499;margin:0 0 6px}.fpbar button{border:0;background:#eef0f3;border-radius:999px;padding:2px 10px;font:800 11px inherit;cursor:pointer}'; document.head.appendChild(st); })();
    async function payLoad() {
      try {
        const names = []; const seen = {};
        Object.keys(R.groups || {}).forEach((g) => ((R.groups[g] && R.groups[g].rows) || []).forEach((r) => { const k = okey(r.order); if (k && !seen[k]) { seen[k] = 1; names.push(r.order); } }));
        PAY = {}; for (let i = 0; i < names.length; i += 300) { const x = await api.get('fi.pay_info', { orders: names.slice(i, i + 300).join(',') }); PKEEP = x.keep_days || PKEEP; Object.assign(PAY, x.info || {}); }
      } catch (e) { PAY = {}; }      // Finance not there yet / no access: the Dispatch page simply has no chip
    }
    function payChip(r) {
      const p = PAY[okey(r.order)]; if (!p || p.st === 'untracked' || p.st === 'none' && r.status !== 'rto') return '';
      const rto = r.status === 'rto' || r.status === 'cancelled'; let h = '';
      if (rto && p.st !== 'closed' && p.st !== 'refund_due') {
        h = '<i class="b">' + (r.status === 'rto' ? 'RTO' : 'cancelled') + ' · not closed</i>' + (p.paid > 0 ? '<i class="b">' + pinr(p.paid) + ' was paid!</i>' : '');
        if (R.role && R.role.manager) h += '<button type="button" data-fpclose="' + esc(r.order) + '|' + esc((p.chs || []).join(',')) + '|' + r.status + '" title="Close this ' + (r.status === 'rto' ? 'RTO' : 'cancelled order') + (p.chs.indexOf('online_prepaid') > -1 ? ': a refund goes to Finance → Parcels' : '') + '">Close</button>';
      } else if (p.st === 'closed') h = '<i>closed · ' + ({ rto: 'RTO', not_sent: 'not sent', lost: 'lost' }[p.kind] || 'closed') + '</i>';
      else if (p.st === 'refund_due') h = '<i class="b">refund ' + pinr(p.refund) + ' due</i>';
      else if (p.st === 'paid') h = '<i class="ok">' + pinr(p.paid) + ' ✓ ' + esc(pday(p.paid_on)) + '</i>';
      else if (p.st === 'part') h = '<i class="w">' + pinr(p.paid) + ' of ' + pinr(p.exp) + '</i>';
      else if (p.st === 'unpaid' && r.status === 'delivered') h = '<i class="' + (p.age >= 10 ? 'b' : p.age >= 5 ? 'w' : '') + '">' + (p.chs.indexOf('online_cod') > -1 ? 'COD ' + pinr(p.cod) + ' due' : 'prepaid ' + pinr(p.pre) + ' · not settled') + '</i>';
      return h ? '<span class="fp">' + h + '</span>' : '';
    }
    const keepRow = (r) => PSHOW || !(PAY[okey(r.order)] && PAY[okey(r.order)].hide && ['delivered', 'rto', 'cancelled'].indexOf(r.status) > -1);
    const can = () => !!(R && R.role && (R.role.manager || R.role.tracking || R.role.lead));
    const canUntr = (r) => !!(R && R.role && (R.role.manager || R.role.untr_seat || (r && r.u && R.role.emp && r.u.owner_id === R.role.emp)));
    const canAssign = () => !!(R && R.role && (R.role.manager || R.role.untr_seat));
    const UI = { people: null };   // the list of people for "Responsible" (fetched when a history panel is opened)
    const isAdmin = () => !!(R && R.role && R.role.admin);
    const $ = (id) => el.querySelector('#' + id);

    el.innerHTML = '<div id="dsBanner"></div><div id="dsFix"></div><div id="dsStrip"></div>'
      + '<div class="dr-card" id="dsAll"><div class="dr-h"><h2>All parcels</h2><span class="bd-chip" id="dsTot">…</span><span class="sp"></span><span id="dsUnt"></span>'
      + '<input class="dr-in" type="search" id="dsQ" placeholder="Order, tracking ID, name, phone" autocomplete="off"><button type="button" class="bd-btn sm" data-all="1">Open all</button><button type="button" class="bd-btn sm" data-all="0">Close all</button></div><div class="bd-sub" style="margin:-2px 0 10px">Work from 1 down to 8 · the most urgent group is always on top · click a group to open or close it</div>'
      + '<div id="dsTiles"></div><div class="dr-tw" id="dsTw"><div class="bd-empty" style="padding:24px">Loading…</div></div><div id="dsLeg"></div></div><div id="dsPen"></div>';
    $('dsQ').value = S.q;

    /* ---------- what to ask the server ---------- */
    function win(extra) {
      const p = {};
      if (S.day) { p.from = S.day; p.to = S.day; } else if (S.from) { p.from = S.from; p.to = S.to || iso(new Date()); } else p.days = S.sdays;
      if (S.from) { p.sfrom = S.from; p.sto = S.to || iso(new Date()); } else p.sdays = S.sdays;
      if (S.store) p.store = S.store; if (S.q) p.q = S.q;
      return Object.assign(p, extra || {});
    }
    const openList = () => (S.q ? [1, 2, 9, 3, 4, 5, 6, 7, 8] : ORDER.filter((g) => S.open[g]));
    const limOf = (g) => S.lim[g] || 25;

    el.addEventListener('change', async (e) => {
      const t = e.target; if (!t || (t.id !== 'dsLimH' && t.id !== 'dsLimD' && t.id !== 'dsLimP')) return;
      const hs = el.querySelector('#dsLimH'), ds = el.querySelector('#dsLimD'), ps = el.querySelector('#dsLimP'); t.disabled = true;
      try { const r = await api.post('dp.dsp_limits', { hours: +hs.value, days: +ds.value, pick: ps ? +ps.value : undefined }); BD.toast(r.message || 'Saved.'); } catch (er) { BD.toast(er.message, true); }
      await load();
    });

    async function load() {
      if (busy) { again = true; return; }
      busy = true;
      try {
        const og = openList();
        R = await api.get('dp.dsp_report', win({ gset: og.length ? og.join(',') : 'none', gl: 25 }));
        // groups the user has widened ("show 100 more / show all") are read again with their own limit
        await Promise.all(og.filter((g) => limOf(g) > 25 && R.groups && R.groups[g] && R.counts['g' + g] > 25).map(async (g) => {
          try { const x = await api.get('dp.dsp_report', win({ gset: String(g), gl: Math.min(3000, limOf(g)) })); if (x.groups && x.groups[g]) R.groups[g] = x.groups[g]; } catch (e) {}
        }));
        if (R.role && R.role.manager) { try { PB = await api.get('dp.dsp_problems'); } catch (e) { PB = null; } } else PB = null;
        await payLoad();
        draw();
      } catch (e) { $('dsTw').innerHTML = '<div class="bd-empty"><b>Cannot load the parcels</b>' + esc(e.message) + '</div>'; }
      busy = false;
      if (again) { again = false; load(); }
    }

    /* ---------- the two limits: the SAME setting as the Boss task "A tracking ID within N hours" ---------- */
    function limitsBar() {
      const L = R.limits; if (!L) return '';
      const hrs = []; for (let h = 12; h <= 240; h += 6) hrs.push(h);
      const dys = [2, 3, 4, 5, 6, 7, 8, 9, 10];
      if (!R.role.manager) return '<div class="bd-sub" style="margin:0 0 8px">Rules: tracking ID within <b>' + L.hours + ' hours</b> · courier pickup wait <b>' + (L.pick || 48) + ' hours</b> · in transit by day <b>' + L.days + '</b>.</div>';
      const sel = (id, arr, cur, suf) => '<select id="' + id + '" class="bd-in" style="width:auto;padding:4px 8px;margin:0 4px">' + arr.map((v) => '<option value="' + v + '"' + (v === +cur ? ' selected' : '') + '>' + v + suf + '</option>').join('') + '</select>';
      return '<div class="bd-card" style="padding:9px 14px;margin:0 0 10px;display:flex;flex-wrap:wrap;align-items:center;gap:6px 14px"><b>Rules</b>'
        + '<span>A tracking ID within' + sel('dsLimH', hrs, L.hours, ' h') + '</span><span>Courier pickup wait' + sel('dsLimP', hrs, L.pick || 48, ' h') + '</span><span>In transit by day' + sel('dsLimD', dys, L.days, '') + '</span>'
        + '<span class="bd-sub" style="margin:0">One rule for the whole system: the same setting as the Boss task, penalties and warnings follow it. The newest change wins.</span></div>';
    }

    /* ---------- page top: buttons and banners ---------- */
    function top() {
      const tb = document.getElementById('topBtns');
      if (tb && !tb.dataset.dsp) {
        tb.dataset.dsp = '1';
        tb.innerHTML = '<button class="bd-btn" id="dsCsv" type="button">⬇ Download Excel (CSV)</button> <button class="bd-btn" id="dsSync" type="button" style="display:none">Read new orders now</button> <button class="bd-btn pri" id="dsRec" type="button" style="display:none">Fix everything now</button>';
        tb.addEventListener('click', async (e) => {
          const b = e.target.closest('button'); if (!b) return;
          if (b.id === 'dsCsv') return csv(b);
          if (b.id === 'dsRec') { S.details = true; keep(); fix(); const g = document.getElementById('recGo'); if (g) { g.scrollIntoView({ block: 'center' }); recAll(g); } return; }
          if (b.id === 'dsSync') { b.disabled = true; try { const r = await api.post('dp.dsp_sync', {}); BD.toast(r.message); await load(); } catch (er) { BD.toast(er.message, true); } b.disabled = false; }
        });
      }
      const sy = document.getElementById('dsSync'); if (sy) sy.style.display = R.role.manager ? '' : 'none';
      const sx = document.getElementById('dsRec'); if (sx) sx.style.display = R.role.manager ? '' : 'none';
      let h = '';
      if (!R.live) h += '<div class="bd-call warn"><b>Not live yet.</b> You can look and add tracking IDs, but no reminders go out and nobody is fined until this is made live. ' + (R.role.manager ? (R.questions_open ? 'Claude still has <b>' + R.questions_open + '</b> question' + (R.questions_open === 1 ? '' : 's') + ' about parcels: <a href="boss_questions.html">answer them in Boss &gt; Questions</a>.' : '<a href="boss_questions.html">Open Boss &gt; Questions</a> and press “This is right, make it live”.') : 'The boss will switch it on.') + '</div>';
      if (R.live && R.mode === 'shadow') h += '<div class="bd-call info">The Boss Engine is in <b>test mode</b>: penalties here are only recorded, nobody is told or charged.</div>';
      h += limitsBar();
      $('dsBanner').innerHTML = h;
      const sub = document.getElementById('sub'); if (sub && R.limits) sub.textContent = 'A tracking ID within ' + R.limits.hours + ' hours, courier pickup within ' + (R.limits.pick || 48) + ' hours, in transit by day ' + R.limits.days + ', a remark every day on untraceable parcels. Sundays are not counted.';
      const ft = document.getElementById('dsFoot');
      if (ft) ft.innerHTML = 'Tracking person: <b>' + esc((R.holders.tracking || []).join(', ') || 'nobody yet') + '</b> · Dispatch lead: <b>' + esc((R.holders.lead || []).join(', ') || 'nobody yet') + '</b> · Orders last read ' + esc(R.last_sync || '—') + (R.role.manager ? ' · <a href="dept_hub.html" style="color:#ea580c;font-weight:700">Departments</a>' : '');
    }

    /* ---------- 1  Claude: what needs fixing ---------- */
    const DOT = { bad: '#d9423b', warn: '#e08a00', info: '#2b6fd6' };
    function fixAction(p) {
      const lk = p.link || '';
      if (/boss|hub\.html/.test(lk) && /people/.test(lk)) return '<a href="' + esc(lk) + '">Open ›</a>';
      if (lk === '#shiprocket') return '<button type="button" class="a" data-go="shiprocket">Connect ›</button>';
      if (lk === '#table') { const m = /group (\d)/.exec(p.link_text || ''); return '<button type="button" class="a" data-grp="' + (m ? m[1] : 2) + '">Open ›</button>'; }
      if (/penalt/.test(lk)) return '<a href="' + esc(lk) + '">Set up ›</a>';
      if (lk && lk.charAt(0) !== '#') return '<a href="' + esc(lk) + '">Open ›</a>';
      if (p.prompt) return '<button type="button" class="a" data-go="details">Details ›</button>';
      return '';
    }
    function fix() {
      if (!R || !R.role || !R.role.manager) { $('dsFix').innerHTML = ''; return; }
      const P = PB ? PB.problems : [], sr = PB ? PB.shiprocket : { connected: true };
      const sev = P.some((p) => p.sev === 'bad') ? 'bad' : (P.length ? 'warn' : 'ok');
      let h = '<div class="dr-card" style="padding:10px 16px"><div class="dr-h" style="margin:0"><h2 style="font-size:15px">Claude: what needs fixing in Dispatch</h2><span class="bd-chip ' + sev + '">' + (P.length ? P.length + ' thing' + (P.length === 1 ? '' : 's') : 'all good') + '</span><span class="sp"></span>'
        + (P.length ? '<button type="button" class="dr-ch sm" data-fixtoggle="1">' + (S.fix ? 'Hide ▲' : 'Show ▼') + '</button>' : '') + '<button type="button" class="dr-ch sm" data-go="details">' + (S.details ? 'Details ▲' : 'Details ▼') + '</button></div>';
      if (S.fix && P.length) h += '<div class="dr-fx">' + P.map((p) => '<div><span class="d" style="background:' + (DOT[p.sev] || '#8b93a3') + '"></span><span class="t">' + esc(p.title) + '</span>' + fixAction(p) + '</div>').join('') + '</div>';
      else if (!P.length) h += '<div class="bd-sub" style="margin-top:4px">Claude found nothing wrong. Shiprocket is connected, orders are arriving and every parcel is on time.</div>';
      if (S.details) h += '<div id="dsDet" style="margin-top:12px">' + details() + '<div style="margin-top:14px" id="drChat"></div></div>';
      $('dsFix').innerHTML = h + '</div>';
      if (S.details) {
        const nd = $('drChat'); if (nd && typeof HQ_CHAT !== 'undefined') HQ_CHAT.mount(nd, { focus: 'dispatch', title: 'Ask Claude about Dispatch', about: 'Ask about parcels, tracking IDs, Shiprocket or who is late. I answer from the live numbers and give you a prompt if something must be built.', onAct: () => load() });
      }
    }
    function details() {
      const P = PB ? PB.problems : [], sr = PB ? PB.shiprocket : null, admin = isAdmin();
      let h = P.map((p, i) => '<div class="dr-pb"><span class="d" style="background:' + (DOT[p.sev] || '#8b93a3') + '"></span><div style="flex:1;min-width:0"><div class="t">' + esc(p.title) + '</div><div class="w">' + esc(p.why) + '</div><div class="f"><b>Fix:</b> ' + esc(p.fix) + '</div>'
        + (p.prompt ? '<div class="a"><button type="button" class="bd-btn sm pri" data-copy="' + i + '">Copy prompt</button></div>' : '') + '</div></div>').join('');
      if (sr) {
        h += '<div class="dr-sr" id="shiprocket"><div class="dr-h" style="margin:0"><h2 style="font-size:15px">Shiprocket</h2>'
          + (sr.connected ? '<span class="bd-chip ' + (sr.last_err ? 'bad' : 'ok') + '">' + (sr.last_err ? 'Not working' : 'Connected') + ' · ' + esc(sr.email) + (sr.source === 'config' ? ' (config.php)' : '') + '</span>' : '<span class="bd-chip bad">Not connected</span>') + '</div>'
          + syncBox(sr, admin)
          + '<div class="bd-sub" style="margin-top:4px">' + (sr.last_at ? 'Last read ' + esc(sr.last_at) + (sr.last_msg ? ' - ' + esc(sr.last_msg) : '') : 'Never read yet.') + (sr.last_err ? ' <b style="color:#d9423b">' + esc(sr.last_err) + '</b>' : '') + '</div>'
          + (admin && sr.source !== 'config' ? '<div class="g"><input class="dr-in" id="srEmail" type="email" placeholder="Shiprocket API user email" autocomplete="off"><input class="dr-in" id="srPass" type="password" placeholder="' + (sr.connected ? 'New password (leave empty to keep)' : 'Shiprocket API user password') + '" autocomplete="new-password"></div>' : '')
          + '<div class="dr-row"><button type="button" class="bd-btn sm pri" id="srTest">Test connection</button><button type="button" class="bd-btn sm" id="srSync">Read Shiprocket now</button>' + (admin && sr.source !== 'config' ? '<button type="button" class="bd-btn sm" id="srSave">Save login</button>' + (sr.connected ? '<button type="button" class="bd-btn sm" id="srDel">Remove login</button>' : '') : '') + '<span class="bd-sub" id="srMsg"></span></div>'
          + (!sr.connected ? '<ol><li>In Shiprocket open <b>Settings &rarr; API</b> (on older panels <b>Settings &rarr; Company</b>) and create an <b>API user</b>. Use a different email from your own login.</li><li>Shiprocket emails you the password. Paste the email and password here and press <b>Save login</b>' + (admin ? '' : ' (only Admin / HR can save it)') + '.</li><li>Press <b>Test connection</b>. After that the HRMS reads Shiprocket by itself every 15 minutes.</li></ol><div class="bd-sub" style="margin-top:6px">Or put it in <code>public_html/empmanagment/config.php</code>: <code>define(\'SHIPROCKET_EMAIL\', \'you@example.com\'); define(\'SHIPROCKET_PASSWORD\', \'the-password\');</code></div>' : '')
          + '</div>';
      }
      if (admin) h += courierBox();
      return h;
    }
    function courierBox() {
      const L = CL || R.couriers || [];
      return '<div class="dr-sr" id="couriers"><div class="dr-h" style="margin:0"><h2 style="font-size:15px">Courier list</h2><span class="bd-sub">What the Tracking person picks from. Put <code>{ID}</code> in the link where the tracking ID goes, so every tracking ID becomes a clickable link.</span></div>'
        + '<table class="dr-cl"><thead><tr><th style="width:150px">Courier</th><th>Tracking link, e.g. https://courier.example/track?no={ID}</th><th style="width:60px">In use</th><th style="width:130px"></th></tr></thead><tbody>'
        + L.concat([{ id: 0, name: '', link: '', active: 1 }]).map((c) => '<tr data-cid="' + c.id + '"><td><input class="dr-in sm" type="text" data-cn value="' + esc(c.name) + '" placeholder="' + (c.id ? '' : 'New courier') + '"></td><td><input class="dr-in sm" type="text" data-cl value="' + esc(c.link) + '"></td><td><input type="checkbox" data-ca ' + (c.active ? 'checked' : '') + '></td><td><button type="button" class="bd-btn sm pri" data-csave="' + c.id + '">' + (c.id ? 'Save' : 'Add') + '</button>' + (c.id ? ' <button type="button" class="bd-btn sm bad" data-cdel="' + c.id + '">Remove</button>' : '') + '</td></tr>').join('')
        + '</tbody></table></div>';
    }

    /* ---------- 2  Last 25 days ---------- */
    function strip() {
      const L = R.strip || [];
      const stores = R.stores || [];
      const custom = !!S.from;
      const chips = DAYSCH.map((n) => '<button type="button" class="dr-ch sm ' + (!custom && S.sdays === n ? 'on' : '') + '" data-sd="' + n + '">' + n + ' days</button>').join('');
      const days = L.map((d) => {
        const em = d.g2 ? '<em>' + d.g2 + ' no ID</em>' : (d.g1 ? '<em>' + d.g1 + ' untraceable</em>' : (d.g9 ? '<em class="pu">' + d.g9 + ' waiting</em>' : '<em>&nbsp;</em>'));
        const dd = new Date(d.date + 'T00:00:00');
        return '<button type="button" class="dr-d ' + (S.day === d.date ? 'on' : '') + (d.total ? '' : ' zero') + '" data-day="' + d.date + '" title="' + esc(d.dow + ' ' + fd(d.date) + ' · ' + d.total + ' parcels · ' + d.g2 + ' without tracking ID · ' + d.g1 + ' untraceable · ' + d.g9 + ' waiting for Admin · ' + d.done + ' done') + '"><small>' + esc(d.dow.slice(0, 3)) + ' ' + dd.getDate() + ' ' + MON[dd.getMonth()] + '</small><b>' + d.total + '</b>' + em + '<span class="pr"><i style="width:' + (d.total ? Math.round(100 * d.done / d.total) : 0) + '%"></i></span></button>';
      }).join('');
      const n = custom ? L.length : S.sdays;
      $('dsStrip').innerHTML = '<div class="dr-card"><div class="dr-h"><h2>Last ' + n + ' days</h2><span class="bd-sub">' + (S.day ? 'Showing only <b>' + esc(fd(S.day)) + '</b> · tap it again to see the whole window' : 'Tap a day to see only its parcels · green bar = ticked Done') + '</span><span class="sp"></span>'
        + '<span class="dr-lbl">Show</span>' + chips
        + (stores.length > 1 ? '<select class="dr-in sm" id="dsStore" style="width:130px"><option value="">All stores</option>' + stores.map((x) => '<option value="' + esc(x) + '" ' + (S.store === x ? 'selected' : '') + '>' + esc(sName(x)) + '</option>').join('') + '</select>' : '')
        + '<input class="dr-in sm" type="date" id="dsFrom" value="' + esc(S.from) + '" aria-label="From"><span class="bd-sub">to</span><input class="dr-in sm" type="date" id="dsTo" value="' + esc(S.to) + '" aria-label="To">'
        + (custom ? '<button type="button" class="dr-ch sm" data-sd="' + S.sdays + '">Clear dates</button>' : '') + '</div><div class="dr-15">' + days + '</div></div>';
    }

    /* ---------- 3  All parcels ---------- */
    function tiles() {
      const c = R.counts;
      const sub = {
        1: G[1][3], 2: c.g2 && c.g2_old_h != null ? 'oldest waiting ' + (c.g2_old_h >= 48 ? Math.floor(c.g2_old_h / 24) + ' days' : Math.round(c.g2_old_h) + ' h') : 'nothing waiting',
        3: c.g3_over ? c.g3_over + ' are over ' + ((R.limits && R.limits.pick) || 48) + ' h' : 'none over ' + ((R.limits && R.limits.pick) || 48) + ' h', 4: G[4][3], 5: G[5][3], 6: G[6][3], 7: G[7][3], 8: '',
      };
      $('dsTiles').innerHTML = '<div class="dr-tiles">' + [1, 2, 3, 4, 5, 6, 7, 8].map((g) => '<button type="button" class="dr-tile" data-grp="' + g + '" style="--c:' + G[g][1] + ';--bg:' + G[g][2] + '"><small><span>' + g + ' ·</span> ' + esc(G[g][0]) + '</small><b>' + c['g' + g] + '</b><i>' + esc(sub[g]) + '</i></button>').join('') + '</div>';
      $('dsTot').textContent = c.total + (S.day ? ' · ' + fd(S.day) : '') + (S.q ? ' matching' : '');
    }
    const ageTxt = (h) => (h == null ? '' : (h >= 48 ? Math.floor(h / 24) + ' d' : Math.round(h) + ' h'));
    function band(g, n) {
      const open = !!(S.open[g] || S.q) && n > 0, c = R.counts;
      let note = G[g][4], x = '';
      if (g === 1 && n) {
        const U = R.untr || {}, nm = (U.owner && U.owner.length ? U.owner.join(', ') : (U.default || ''));
        x = 'Owner: <b style="color:#fbbf24">' + (nm ? esc(nm) : 'nobody yet') + '</b> &nbsp;·&nbsp; today <b>' + (n - c.g1_noact) + ' of ' + n + '</b> remarked' + (isAdmin() ? '<button type="button" class="dr-go dk" style="background:#fff;color:#111827" data-chown="1">Change owner</button>' : '');
      }
      if (g === 3 && R.g3_sr && R.g3_sr.length) x = 'Shiprocket: ' + R.g3_sr.map((r) => String(r.s).toLowerCase() + ' ' + r.n).join(' · ');
      if (g === 9 && isAdmin() && n) x = '<button type="button" class="dr-go" data-apall="1">Approve all ' + n + '</button>';
      return '<tr class="dr-band' + (n ? '' : ' zero') + '" data-band="' + g + '"><td colspan="11" style="border-left:6px solid ' + G[g][1] + (g === 9 ? ';background:#2b1f5c' : '') + '"><span style="display:inline-block;width:14px">' + (n ? (open ? '▾' : '▸') : '') + '</span> ' + (g < 9 ? g + ' · ' : '') + esc(G[g][0]).toUpperCase() + ' &nbsp;<b>' + n + '</b> &nbsp;<span style="font-weight:600;opacity:.7">' + note + '</span>' + (x ? '<span class="x">' + x + '</span>' : '') + '</td></tr>';
    }
    /* live badge: green = news in the last 30 min, amber = within 6 h, grey = older, "Track" = never asked */
    const agoTxt = (t) => { const x = parseT(t); if (!x) return ''; const m = Math.max(0, Math.round((Date.now() - x) / 60000)); return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.floor(m / 60) + ' h ago' : Math.floor(m / 1440) + ' d ago'; };
    function liveBadge(r) {
      if (!r.tracking_no || r.grp === 9 || r.grp === 8) return '';
      const x = r.live_at ? parseT(r.live_at) : 0, m = x ? (Date.now() - x) / 60000 : -1, c = m < 0 ? 'off' : m <= 30 ? 'on' : m <= 360 ? 'mid' : 'old';
      return '<button type="button" class="dl-b ' + c + '" data-trk="' + r.id + '" title="' + (m < 0 ? 'Open the live scan history' : 'Live scan history · last news ' + esc(agoTxt(r.live_at))) + '"><i></i>' + (m < 0 ? 'Track' : esc(agoTxt(r.live_at))) + '</button>';
    }
    function tnCell(r) {
      const tn = r.grp === 9 && r.pend ? r.pend.track : r.tracking_no, lk = r.grp === 9 && r.pend ? r.pend.link : r.link;
      if (!tn) return '';
      return '<button type="button" class="dl-tn" data-trk="' + r.id + '" title="Open the Order card: payment, salesperson, commission, complaints, chat and the live journey">' + esc(tn) + '</button>' + (lk ? ' <a class="lk" href="' + esc(lk) + '" target="_blank" rel="noopener" title="Open the courier tracking page">↗</a>' : '') + liveBadge(r);
    }
    function actionCell(r) {
      const g = r.grp, ca = can();
      if (g === 2 || g === 10) return ca ? '<button type="button" class="dr-add" data-add="' + r.id + '">+ Add tracking ID</button>' : '<span class="bd-sub" style="font-size:11px">waiting for the Tracking person</span>';
      if (g === 9) return isAdmin() ? '<button type="button" class="dr-go" data-ap="' + r.id + '">Approve</button> <button type="button" class="dr-no" data-rj="' + r.id + '">Reject</button>' : '<span class="dr-pill pu">waiting for Admin</span>';
      if (g === 6) {
        const cod = r.cod ? '<span class="dr-cod">' + inr(r.cod) + '</span>' : '';
        return (ca ? '<button type="button" class="dr-rm" data-rm="' + r.id + '" data-v="1" title="COD money has arrived for this parcel">Money received</button>' : '<span class="bd-sub">pending</span>') + cod;
      }
      if (g === 7) return r.remit_at ? '<button type="button" class="dr-rm on" data-rm="' + r.id + '" data-v="0"' + (ca ? '' : ' disabled') + ' title="Money received · ' + esc(r.remit_by) + ' · ' + esc(fdt(r.remit_at)) + (ca ? ' — click to undo' : '') + '">✓ received</button>' : '<span class="bd-sub" style="font-size:11px">Prepaid</span>';
      if (g === 8) return r.case_end ? '<span class="bd-sub" style="font-size:11px">untraceable · ' + esc(r.case_end.by) + ' · ' + esc(fdt(r.case_end.at)) + '</span>' : '<span class="bd-sub">—</span>';
      return r.sr_status ? '<span class="bd-sub" style="font-size:11px">' + esc(r.sr_status.charAt(0) + r.sr_status.slice(1).toLowerCase()) + '</span>' : '';   // 3, 4, 5
    }
    const fdl = (d) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d || ''); if (!m) return d || ''; const dt = new Date(+m[1], +m[2] - 1, +m[3]); return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][dt.getDay()] + ' ' + (+m[3]) + ' ' + MON[+m[2] - 1]; };
    const yMissed = (r) => { const d = r.u && r.u.dots ? r.u.dots : []; return d.length > 1 && d[d.length - 2].s === 'miss' && !(r.u && r.u.today); };
    const dotsHtml = (r) => (r.u.dots || []).map((d) => '<span class="dr-dot ' + d.s + '" title="' + esc(d.dow + ' ' + fd(d.d) + ' · ' + ({ ok: 'remark written', miss: 'missed', todo: 'today, still to write', na: 'not counted' }[d.s])) + '"></span>').join('');
    function uForm(r) {
      return '<span class="dr-uf" data-uf="' + r.id + '"><input class="dr-tx" data-un placeholder="Remark for today: what did you do?" maxlength="300"><button type="button" class="dr-dn" data-hist="' + r.id + '">History ' + (S.hist[r.id] ? '▴' : '▾') + '</button><select class="dr-sel" data-ua><option value="">Action ▾</option>' + Object.keys((R.untr || {}).daily || {}).map((k) => '<option value="' + k + '">' + esc(R.untr.daily[k]) + '</option>').join('') + '</select>'
        + '<input class="dr-tx" type="date" data-ud min="' + iso(new Date()) + '" title="Check again on (optional)"><button type="button" class="dr-go" data-urec="' + r.id + '">Record</button></span>';
    }
    function todayCell(r) {
      const u = r.u, hb = '<button type="button" class="dr-dn" style="margin-left:5px" data-hist="' + r.id + '">History ' + (S.hist[r.id] ? '▴' : '▾') + '</button>';
      if (u.today) return '<span class="dr-tdy">✓ today ' + esc(u.today.time) + '</span> <span class="bd-sub" style="font-size:11px" title="' + esc(u.today.label + ' — ' + u.today.note) + '">' + esc(u.today.note.length > 15 ? u.today.note.slice(0, 14) + '…' : u.today.note) + '</span>' + hb;
      if (canUntr(r)) return uForm(r);
      return '<span class="dr-pill bad">waiting for ' + esc(u.owner || 'the owner') + '</span>' + hb;
    }
    function histPanel(r) {
      const u = r.u, since = u.since;
      const ev = (u.hist || []).map((h) => {
        if (h.k === 'miss') return '<div class="e"><span class="d">' + esc(fdl(h.date)) + '</span><span class="tm bad">✗ none</span><span class="bad"><b>No remark written.</b>' + (h.pen ? ' <span style="color:#b42318">' + esc(h.pen) + '.</span>' : '') + '</span></div>';
        const lab = h.k === 'end' ? 'Case ended: ' + h.label : h.label;
        return '<div class="e"><span class="d">' + esc(fdl(h.date)) + '</span><span class="tm' + (h.k === 'end' ? ' end' : '') + '">' + (h.k === 'end' ? '■ ' : '✓ ') + esc(h.time) + '</span><span><b>' + esc(lab) + '</b>' + (h.note ? ' — “' + esc(h.note) + '”' : '') + ' <span class="bd-sub">· ' + esc(h.by) + (h.check ? ' · check again ' + esc(fd(h.check)) : '') + '</span></span></div>';
      }).join('') || '<div class="bd-sub">No remark yet.</div>';
      const tn = r.tracking_no ? (r.link ? '<a class="lk" style="color:#1d4ed8;font-weight:700" href="' + esc(r.link) + '" target="_blank" rel="noopener">' + esc(r.tracking_no) + ' ↗</a>' : esc(r.tracking_no)) + (r.courier ? ' (' + esc(r.courier) + ')' : '') : 'none';
      let side = '<div class="k">This parcel</div><div>Customer <b>' + esc(r.customer) + '</b>' + (r.phone ? ' · ' + esc(r.phone) : '') + '</div><div>Shiprocket says <b>' + esc((r.sr_status || 'untraceable').toUpperCase()) + '</b>' + (r.sr_at ? ' since ' + esc(fd(r.sr_at)) : '') + '</div><div>Tracking ' + tn + '</div>';
      if (canAssign()) {
        const pe = UI.people || [{ id: u.owner_id, name: u.owner }];
        side += '<div><span class="lb">Responsible</span><select class="dr-sel" data-who="' + r.id + '" style="max-width:150px"><option value="0">' + (R.untr && R.untr.default ? 'Owner (' + esc(R.untr.default) + ')' : 'Owner seat') + '</option>' + pe.map((p) => '<option value="' + p.id + '"' + (u.assigned && p.id === u.owner_id ? ' selected' : '') + '>' + esc(p.name) + '</option>').join('') + '</select> <button type="button" class="hb" data-reas="' + r.id + '">Reassign</button></div>';
      } else side += '<div><span class="lb">Responsible</span><b>' + esc(u.owner || 'nobody yet') + '</b></div>';
      if (canUntr(r)) side += '<div><span class="lb">End the case</span>' + Object.keys((R.untr || {}).end || {}).map((k) => '<button type="button" class="hb" data-uend="' + r.id + '|' + k + '">' + esc(R.untr.end[k]) + '</button>').join('') + '</div>';
      return '<tr class="dr-hp" data-hp="' + r.id + '"><td colspan="11"><div class="dr-hg"><div><div class="k">Remark history · ' + esc(r.order) + ' · untraceable since ' + esc(fdl(since)) + ' (day ' + u.days + ')</div>' + ev + '</div><div class="side">' + side + '</div></div></td></tr>';
    }
    function rowG1(r, i) {
      const c = rowColors(r), u = r.u;
      const dn = r.done_at ? '<button type="button" class="dr-dn on" data-dn="' + r.id + '" data-v="0"' + (can() ? '' : ' disabled') + ' title="Done by ' + esc(r.done_by) + ' · ' + esc(fdt(r.done_at)) + (can() ? ' — click to undo' : '') + '">✓ Done</button>' : (can() ? '<button type="button" class="dr-dn" data-dn="' + r.id + '" data-v="1" title="Mark this parcel as dealt with">Done</button>' : '<span style="color:#c3c8d1">—</span>');
      const lab = u.today ? '<b style="color:#16945a">' + esc(u.today.label) + '</b>' : (yMissed(r) ? '<b style="color:#d9423b">Yesterday missed</b>' : '<b style="color:#c2410c">Untraceable</b>');
      return '<tr class="rw" data-id="' + r.id + '" style="--c:' + c[0] + ';background:' + c[1] + manEdge(r) + '" title="' + esc(r.customer + (r.phone ? ' · ' + r.phone : '')) + '"><td>' + (i + 1) + '</td><td>' + dn + '</td><td>' + ordCell(r) + '</td><td>' + esc(fdt(r.created)) + '</td><td>day ' + u.days + (u.assigned ? ' <span class="dr-pill am" title="This parcel is with ' + esc(u.owner) + '">' + esc(String(u.owner).split(' ')[0]) + '</span>' : '') + '</td><td>' + custCell(r) + '</td><td>' + esc(sShort(r.store)) + '</td><td class="mono" style="overflow:visible;max-width:none">' + tnCell(r) + '</td><td>' + esc(r.courier) + '</td><td class="st" style="overflow:visible">' + lab + ' ' + dotsHtml(r) + '</td><td class="ac uc">' + todayCell(r) + '</td></tr>' + (S.hist[r.id] ? histPanel(r) : '');
    }
    function statusCell(r) {
      const g = r.grp;
      if (g === 1) return 'Untraceable';
      if (g === 2) return 'No tracking' + (r.late === '48h' ? ' <span class="late">late ' + (R.limits ? R.limits.hours : 48) + ' h</span>' : '');
      if (g === 10) return 'No tracking · old';
      if (g === 9) return '<b style="color:#5b3fd6">Added by ' + esc(r.pend ? r.pend.by : r.added_by) + '</b>';
      if (g === 3) { const w = r.wait_h; return 'Tracking given' + (w != null ? (w >= 48 ? ' <span class="late">waiting ' + ageTxt(w) + '</span>' : ' · waiting ' + Math.round(w) + ' h') : ''); }
      if (g === 4) return 'In transit' + (r.late === 'day4' ? ' <span class="late">day ' + (R.limits ? R.limits.days : 4) + '</span>' : '');
      if (g === 5) return r.status === 'rto' ? 'RTO' : 'Not delivered';
      if (g === 6) return 'Delivered';
      if (g === 7) return 'Remittance arrived';
      return r.case_end ? 'Case ended: ' + esc(r.case_end.label) : 'Cancelled';
    }
    // orders made by hand in the WhatsApp chat (Manual order, name WM-00001) carry a purple edge and a MANUAL pill, so they are easy to tell from website orders
    const isMan = (r) => /^WM-\d+/i.test(String(r.order || '')), kwikTag = (r) => r.kwik ? '<div class="mono" style="font-size:11px;color:#64748b;font-weight:600" title="Payment gateway (KWIK) id: Finance matches the settlement file with it">' + esc(String(r.kwik).split(',')[0]) + '</div>' : '', manPill = (r) => isMan(r) ? ' <span class="dr-pill" style="background:#ede7ff;color:#5b34c4" title="Made by hand in the WhatsApp chat (QR / UPI / COD), not on the website">MANUAL</span>' : '', manEdge = (r) => isMan(r) ? ';box-shadow:inset 5px 0 0 #7c3aed' : '';
    // 💬 the order number opens the customer's WhatsApp chat right here; 👤 who sold it and ⚠ an open complaint sit under the customer (Shopify and Manual orders alike)
    const ordCell = (r) => (r.chat ? '<button type="button" class="dr-ord" data-chat="' + esc(r.chat) + '" data-pid="' + r.id + '" data-oname="' + esc(r.order) + '" data-cust="' + esc(r.customer) + '" title="Open everything about ' + esc(r.order) + ': order, customer and WhatsApp chat">' + esc(r.order) + '</button>' : '<b>' + esc(r.order) + '</b>') + '<button type="button" class="dr-oc" data-trk="' + r.id + '" title="Order card">🧾</button>' + manPill(r) + kwikTag(r);
    const custCell = (r) => esc(r.customer)
      + (r.sp ? '<div class="dr-sp" title="Salesperson of this order">👤 ' + esc(r.sp) + (r.sp_type ? ' · ' + (r.sp_type === 'campaign' ? 'Campaign' : 'Query') : '') + '</div>' : '')
      + (r.cx ? '<div><a class="dr-pill bad" href="complaints.html?case=' + (+r.cx.id) + '" target="_top" title="Open complaint ticket ' + esc(r.cx.code) + '">⚠ ' + esc(r.cx.code) + ' · ' + esc(r.cx.label) + '</a></div>' : '');
    function openChat(ph, oname, cust) {
      let d = document.getElementById('dsChat');
      if (!d) {
        d = document.createElement('div'); d.id = 'dsChat';
        d.innerHTML = '<div class="dsc-bg"></div><div class="dsc-p"><div class="dsc-h"><b id="dscT"></b><button type="button" class="dsc-x" aria-label="Close">✕</button></div><iframe id="dscI" title="Customer chat"></iframe></div>';
        document.body.appendChild(d);
        const close = () => { d.classList.remove('on'); try { document.getElementById('dscI').src = 'about:blank'; } catch (e) {} };
        d.querySelector('.dsc-bg').onclick = close; d.querySelector('.dsc-x').onclick = close;
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && d.classList.contains('on')) close(); });
      }
      document.getElementById('dscT').textContent = oname + (cust ? ' · ' + cust : '') + ' · ' + ph;
      document.getElementById('dscI').src = 'wa_one.html?phone=' + encodeURIComponent(ph);
      d.classList.add('on');
    }
    function rowColors(r) {
      const g = r.grp;
      if (g === 1 && r.u) return r.u.today ? ['#16945a', '#f1faf5'] : (yMissed(r) ? ['#d9423b', '#fff1f1'] : ['#d97706', '#fff7e6']);
      if (g === 3 && !(r.wait_h != null && r.wait_h >= 48)) return ['#e0a100', '#fffbea'];
      return [G[g][1], G[g][2]];
    }
    function rowHtml(r, i) {
      if (r.grp === 1 && r.u) return rowG1(r, i);
      const can_ = can(), c = rowColors(r), g = r.grp;
      const dn = r.done_at ? '<button type="button" class="dr-dn on" data-dn="' + r.id + '" data-v="0"' + (can_ ? '' : ' disabled') + ' title="Done by ' + esc(r.done_by) + ' · ' + esc(fdt(r.done_at)) + (can_ ? ' — click to undo' : '') + '">✓ Done</button>' : (can_ ? '<button type="button" class="dr-dn" data-dn="' + r.id + '" data-v="1" title="Mark this parcel as dealt with">Done</button>' : '<span style="color:#c3c8d1">—</span>');
      const courier = g === 9 && r.pend ? r.pend.courier : r.courier;
      return '<tr class="rw' + (r.done_at ? ' dn' : '') + '" data-id="' + r.id + '" style="--c:' + c[0] + ';background:' + c[1] + manEdge(r) + '" title="' + esc(r.customer + (r.phone ? ' · ' + r.phone : '')) + '"><td>' + (i + 1) + '</td><td>' + dn + '</td><td>' + ordCell(r) + '</td><td>' + esc(fdt(r.created)) + '</td><td>' + esc(ageTxt(r.age_h)) + '</td><td>' + custCell(r) + '</td><td>' + esc(sShort(r.store)) + '</td><td class="mono" style="overflow:visible;max-width:none">' + tnCell(r) + '</td><td>' + esc(courier) + '</td><td class="st">' + statusCell(r) + '</td><td class="ac">' + actionCell(r) + payChip(r) + '</td></tr>';
    }
    function table() {
      const head = ['#', 'Done', 'Order', 'Ordered', 'Waiting', 'Customer', 'Store', 'Tracking no.', 'Courier', 'Status', 'Action'];
      let b = '', hidN = 0;
      ORDER.forEach((g) => {
        const n = R.counts['g' + g]; if ((g === 9 || g === 10) && !n) return;
        b += band(g, n);
        const open = (S.open[g] || S.q) && n > 0;
        if (!open) return;
        if (g === 1) b += '<tr class="dr-sub"><td></td><td></td><td></td><td></td><td>Untraceable</td><td></td><td></td><td></td><td></td><td>Status · last 7 days</td><td>Today’s remark</td></tr>';
        const grp = R.groups && R.groups[g]; if (!grp) return;
        const lim = limOf(g), all = grp.rows.slice(0, lim), rows = all.filter(keepRow); hidN += all.length - rows.length;
        b += rows.map(rowHtml).join('');
        if (n > all.length) {
          b += '<tr class="dr-more"><td colspan="11" style="border-left:3px solid ' + G[g][1] + '">' + (n - all.length) + ' more · ' + (all.length < 3000 ? '<button type="button" data-more="' + g + '|100">show 100 more</button><button type="button" data-more="' + g + '|all">show all ' + n + '</button>' : 'showing the first 3000') + ' · sorted oldest first</td></tr>';
        }
      });
      const bar = Object.keys(PAY).length ? '<div class="fpbar">Payments from Finance · parcels that are paid or closed and older than ' + PKEEP + ' days are ' + (PSHOW ? 'shown' : 'tucked away' + (hidN ? ' (' + hidN + ' now)' : '')) + ' · open issues always stay <button type="button" data-fpshow="' + (PSHOW ? 0 : 1) + '">' + (PSHOW ? 'tuck them away' : 'show them') + '</button></div>' : '';
      $('dsTw').innerHTML = bar + '<table class="dr-t"><thead><tr>' + head.map((h) => '<th>' + h + '</th>').join('') + '</tr></thead><tbody>' + (b || '<tr><td colspan="11" style="padding:18px;text-align:center;color:#7a8499">No parcels in this window.</td></tr>') + '</tbody></table>';
    }

    function legend() {
      const c = R.counts, n = c.g1, left = c.g1_noact, ch = $('dsUnt');
      if (ch) ch.innerHTML = n ? (left ? '<span class="bd-chip bad">Untraceable: ' + left + ' remark' + (left === 1 ? '' : 's') + ' still to write today</span>' : '<span class="bd-chip ok">Untraceable: all remarked today</span>') : '';
      $('dsLeg').innerHTML = n ? '<div class="dr-leg"><span>Status dots, one per day:</span><span><i class="dr-dot ok"></i>remark written</span><span><i class="dr-dot miss"></i>day missed (penalty)</span><span><i class="dr-dot todo"></i>today, still to write</span><span class="sp"></span><span>Remarks are due every day by <b>' + esc((R.untr || {}).cut || '6 PM') + '</b>. Sundays are not counted.</span></div>' : '';
    }
    /* ---------- 4  Penalties: nothing to manage here, only a pointer to Boss ---------- */
    function pens() {
      if (!R || !R.role || !R.role.manager) { $('dsPen').innerHTML = ''; return; }
      $('dsPen').innerHTML = '<div class="dr-card" style="padding:14px 18px"><div class="dr-h" style="margin:0;flex-wrap:nowrap"><h2 style="font-size:16px">Penalties</h2><span class="bd-sub" style="font-size:13px;flex:1">Dispatch penalties (untraceable remarks, no tracking ID, not in transit) are set and charged in <b>Boss › Penalties</b>. Nothing to manage on this page.</span><a class="bd-btn" href="boss_penalties.html" style="white-space:nowrap">Open Boss › Penalties</a></div></div>';
    }
    /* ---- keeping in step with Shopify / Shiprocket: health lines + the "Fix everything now" button ---- */
    function hl(name, x, okTxt) {
      if (!x) return '';
      const bad = !!x.err, never = !x.ts;
      return '<div class="dr-hl"><span class="bd-chip ' + (bad ? 'bad' : (never ? '' : 'ok')) + '">' + name + '</span> <span class="bd-sub">' + (never ? 'Not read yet.' : 'Last ' + esc(x.at) + (x.msg ? ' - ' + esc(x.msg) : '')) + '</span>' + (bad ? ' <b style="color:#d9423b">' + esc(x.err) + '</b>' : '') + '</div>';
    }
    function recText(rec) {
      if (!rec) return '';
      const b = rec.before || {}, a = rec.after || {};
      return 'Last full fix ' + esc(rec.at) + ': parcels without a tracking ID ' + (b.no_tracking != null ? b.no_tracking : '?') + ' → <b>' + (a.no_tracking != null ? a.no_tracking : '?') + '</b>. '
        + 'Shiprocket read ' + (rec.sr_seen || 0) + ' orders, ' + (rec.sr_updated || 0) + ' parcels updated' + (rec.sr_unmatched ? ', ' + rec.sr_unmatched + ' not found here' : '') + (rec.sf && rec.sf.updated ? '; Shopify gave ' + rec.sf.updated + ' tracking numbers' : '') + '.'
        + (rec.err && rec.err.length ? ' <b style="color:#d9423b">' + rec.err.map(esc).join(' · ') + '</b>' : '');
    }
    const recNote = { t: '', bad: false };
    function syncBox(sr, admin) {
      let h = '<div class="dr-sync" style="margin:8px 0;padding:10px;border:1px solid var(--bd-line,#e3e7ee);border-radius:10px">'
        + '<div style="font-weight:600;margin-bottom:4px">Keep the HRMS in step with Shopify and Shiprocket</div>'
        + hl('Shopify orders', sr.shop_ok ? sr.shop : null) + hl('Shopify tracking', sr.shop_ok ? sr.sf : null) + hl('Shiprocket', { ts: sr.last_ts, at: sr.last_at, msg: sr.last_msg, err: sr.last_err })
        + (sr.roll_msg ? '<div class="bd-sub">Background pass: ' + esc(sr.roll_msg) + (sr.roll_err ? ' <b style="color:#d9423b">' + esc(sr.roll_err) + '</b>' : '') + '</div>' : '')
        + (sr.rec ? '<div class="bd-sub" style="margin-top:4px">' + recText(sr.rec) + '</div>' : '')
        + '<div class="dr-row" style="margin-top:8px"><button type="button" class="bd-btn sm pri" id="recGo">Fix everything now</button><span class="bd-sub" id="recMsg"' + (recNote.t ? ' style="color:' + (recNote.bad ? '#d9423b' : '#16945a') + '"' : '') + '>' + (recNote.t ? esc(recNote.t) : 'Reads Shopify and Shiprocket again, day by day, and fills every tracking ID and courier they already know. Safe to press any time; nothing is deleted.') + '</span></div>'
        + '<div id="recBar" style="display:none;height:8px;border-radius:6px;background:#e9edf3;margin-top:8px;overflow:hidden"><div id="recFill" style="height:100%;width:0;background:#16945a;transition:width .3s"></div></div>'
        + liveBox(sr, admin)
        + '</div>';
      return h;
    }
    let recRun = false;
    async function recAll(btn) {
      if (recRun) return; recRun = true; btn.disabled = true;
      const msg = document.getElementById('recMsg'), bar = document.getElementById('recBar'), fill = document.getElementById('recFill');
      if (bar) bar.style.display = '';
      let restart = 1, last = null, fails = 0;
      try {
        for (let i = 0; i < 400; i++) {
          let r;
          try { r = await api.post('dp.dsp_reconcile', { restart: restart }); fails = 0; }
          catch (er) { if (++fails > 3) throw er; await new Promise((ok) => setTimeout(ok, 2500)); continue; }   // a slow moment: ask again, it carries on where it stopped
          restart = 0; last = r;
          if (fill) fill.style.width = r.pct + '%'; if (msg) { msg.style.color = ''; msg.textContent = r.text + ' ' + r.pct + '%'; }
          if (r.done) break;
        }
        if (last && last.done) {
          const b = last.before || {}, a = last.after || {}, bad = last.errors && last.errors.length;
          const t = 'Done. Parcels without a tracking ID: ' + b.no_tracking + ' → ' + a.no_tracking + '.' + (bad ? ' Problem: ' + last.errors.join(' · ') : '');
          recNote.t = t; recNote.bad = !!bad; if (msg) { msg.textContent = t; msg.style.color = bad ? '#d9423b' : '#16945a'; } BD.toast(t, !!bad);
        } else { recNote.t = 'Stopped before the end. Press the button again, it carries on.'; recNote.bad = true; if (msg) { msg.textContent = recNote.t; msg.style.color = '#d9423b'; } }
      } catch (er) { if (msg) { msg.textContent = er.message; msg.style.color = '#d9423b'; } BD.toast(er.message, true); }
      recRun = false; btn.disabled = false;
      try { await load(); await fixRefresh(); } catch (e) {}
    }
    async function fixRefresh() { try { PB = await api.get('dp.dsp_problems'); fix(); } catch (e) {} }

    function draw() {
      top(); fix(); strip(); tiles(); table(); legend(); pens();
      if (!firstDone) {
        firstDone = true;
        if (/[?&]focus=noid/.test(location.search)) { S.open[2] = 1; keep(); jump(2); }
        if (/[?&]focus=untraceable/.test(location.search)) { S.open[1] = 1; keep(); jump(1); }
      }
    }
    function jump(g) {
      const go = () => { const t = el.querySelector('tr[data-band="' + g + '"]'); if (t) { t.scrollIntoView({ behavior: 'smooth', block: 'center' }); t.classList.add('flash'); setTimeout(() => t.classList.remove('flash'), 1500); } };
      if (!S.open[g] && R.counts['g' + g]) { S.open[g] = 1; keep(); load().then(go); } else go();
    }

    /* ---------- one row changes: redraw only that row ---------- */
    function findRow(id) { for (const g in (R.groups || {})) { const r = R.groups[g].rows.find((x) => String(x.id) === String(id)); if (r) return r; } return null; }
    function patch(id) {
      const r = findRow(id), tr = el.querySelector('tr.rw[data-id="' + id + '"]'); if (!r || !tr) return;
      const idx = Array.prototype.indexOf.call(tr.parentNode.querySelectorAll('tr.rw'), tr);
      const n = parseInt(tr.firstChild.textContent, 10) - 1;
      const nx = tr.nextElementSibling; if (nx && nx.matches('tr.dr-hp')) nx.remove();
      tr.outerHTML = rowHtml(r, isNaN(n) ? idx : n);
    }

    /* ---------- add a tracking ID right in the row ---------- */
    function addForm(id) {
      const r = findRow(id), tr = el.querySelector('tr.rw[data-id="' + id + '"]'); if (!r || !tr) return;
      const cells = tr.querySelectorAll('td'), ac = cells[10];
      const cs = (R.couriers || []).filter((c) => c.active);
      const pre = cs.find((c) => c.name.toLowerCase() === String(r.courier || '').toLowerCase());
      ac.innerHTML = '<div class="dr-f" data-form="' + id + '"><span class="l"><select class="dr-sel" data-fc><option value="">Courier ▾</option>' + cs.map((c) => '<option value="' + esc(c.name) + '" data-pat="' + esc(c.link || '') + '"' + (pre && pre.id === c.id ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select>'
        + '<input class="dr-tx tn" data-ft placeholder="Tracking ID" maxlength="40" autocomplete="off" autocapitalize="characters"><button type="button" class="dr-go' + (isAdmin() ? '' : ' dk') + '" data-fs="' + id + '">' + (isAdmin() ? 'Add and approve' : 'Send to Admin') + '</button><button type="button" class="dr-no" data-fx="' + id + '">✕</button></span>'
        + '<span class="l"><span class="bd-sub" style="font-size:11px;font-weight:700">Courier link</span><input class="dr-tx lk2" data-fl placeholder="Tracking page link (optional)" autocomplete="off"><span class="dr-pill ok" data-fa style="display:none">auto-filled</span></span></div>';
      fillLink(ac);
      const t = ac.querySelector('[data-ft]'); if (t) t.focus();
    }
    function fillLink(box) {
      const l = box.querySelector('[data-fl]'); if (!l || l.dataset.touched === '1') return;
      const o = box.querySelector('[data-fc]').selectedOptions[0], pat = o ? (o.dataset.pat || '') : '', tn = box.querySelector('[data-ft]').value.trim();
      const a = box.querySelector('[data-fa]');
      if (pat && tn) { l.value = pat.replace('{ID}', encodeURIComponent(tn)); a.style.display = ''; a.textContent = 'auto-filled'; }
      else if (pat) { l.value = ''; l.placeholder = 'Fills in when you type the tracking ID'; a.style.display = 'none'; }
      else { l.value = ''; l.placeholder = o && o.value ? 'No link set for ' + o.value + ' — paste the tracking page link (optional)' : 'Tracking page link (optional)'; a.style.display = 'none'; }
    }
    async function sendForm(id, btn) {
      const f = el.querySelector('[data-form="' + id + '"]'); if (!f) return;
      const tn = f.querySelector('[data-ft]').value.trim(), co = f.querySelector('[data-fc]').value, lk = f.querySelector('[data-fl]').value.trim();
      if (!co) { f.querySelector('[data-fc]').focus(); return BD.toast('Choose the courier first.', true); }
      if (!tn) { f.querySelector('[data-ft]').focus(); return BD.toast('Type the tracking ID.', true); }
      btn.disabled = true;
      try { const r = await api.post('dp.dsp_manual_add', { id: +id, tracking_no: tn, courier: co, link: lk }); BD.toast(r.message); await load(); }
      catch (e) { btn.disabled = false; BD.toast(e.message, true); }
    }
    async function recordU(id, btn) {
      const f = el.querySelector('[data-uf="' + id + '"]'); if (!f) return;
      const a = f.querySelector('[data-ua]').value, note = f.querySelector('[data-un]').value.trim();
      if (!a) { f.querySelector('[data-ua]').focus(); return BD.toast('Choose what was done today.', true); }
      if (note.length < 3) { f.querySelector('[data-un]').focus(); return BD.toast('Write the remark: what did you do or hear?', true); }
      btn.disabled = true;
      try { const r = await api.post('dp.dsp_untr_action', { id: +id, action: a, note, check_date: f.querySelector('[data-ud]').value }); BD.toast(r.message); await load(); }
      catch (e) { btn.disabled = false; BD.toast(e.message, true); }
    }
    async function toggleHist(id) {
      S.hist[id] = S.hist[id] ? 0 : 1; keep(); patch(id);
      if (S.hist[id] && canAssign() && !UI.people) { try { UI.people = (await api.get('dp.dsp_untr_people')).people; patch(id); } catch (e) {} }
    }
    async function reassign(id, btn) {
      const sel = el.querySelector('[data-who="' + id + '"]'); if (!sel) return; btn.disabled = true;
      try { const r = await api.post('dp.dsp_untr_assign', { id: +id, emp_id: +sel.value }); BD.toast(r.message); await load(); } catch (e) { BD.toast(e.message, true); btn.disabled = false; }
    }
    async function endCase(id, how, btn) {
      const r = findRow(id); if (!r) return; const L = R.untr.end[how];
      if (!(await BD.confirm(L + ': ' + r.order + '?', 'The daily remark stops for this parcel and it leaves group 1.', L))) return;
      btn.disabled = true;
      try { const x = await api.post('dp.dsp_untr_end', { id: +id, how }); delete S.hist[id]; keep(); BD.toast(x.message); await load(); } catch (e) { BD.toast(e.message, true); btn.disabled = false; }
    }
    async function ownerPopup() {
      let people = []; try { people = (await api.get('dp.dsp_untr_people')).people; } catch (e) { return BD.toast(e.message, true); }
      const U = R.untr || {}, opt = (cur) => '<option value="0">— nobody —</option>' + people.map((p) => '<option value="' + p.id + '"' + (cur && p.id === cur ? ' selected' : '') + '>' + esc(p.name) + '</option>').join('');
      const m = BD.modal('<h3>Untraceable owner</h3><div class="bd-sub" style="margin-bottom:10px">The owner writes a remark on every untraceable parcel, every day by ' + esc(U.cut || '6 PM') + '. The backup takes over when the owner is away. You can also hand one parcel to somebody else inside its History.</div>'
        + '<div class="bd-f"><label>Owner</label><select class="bd-in" id="ow_o">' + opt((U.owner_ids || [])[0]) + '</select></div><div class="bd-f"><label>Backup</label><select class="bd-in" id="ow_b">' + opt((U.backup_ids || [])[0]) + '</select></div>'
        + '<div class="acts"><button type="button" class="bd-btn" id="ow_x">Cancel</button><button type="button" class="bd-btn go" id="ow_ok">Save</button></div>');
      m.querySelector('#ow_x').onclick = BD.close;
      m.querySelector('#ow_ok').onclick = async (e) => {
        const b = e.target; b.disabled = true; const o = +m.querySelector('#ow_o').value, bk = +m.querySelector('#ow_b').value;
        try {
          await api.post('dp.seat_set', { code: 'dispatch.untraceable', emp_ids: o ? [o] : [] });
          await api.post('dp.seat_set', { code: 'dispatch.untraceable_backup', emp_ids: bk ? [bk] : [] });
          BD.close(); BD.toast('Saved.'); await load(); await fixRefresh();
        } catch (er) { b.disabled = false; BD.toast(er.message, true); }
      };
    }
    async function decide(ids, dec, reason) {
      try { const r = await api.post('dp.dsp_manual_decide', { ids, decision: dec, reason: reason || '' }); BD.toast(r.message); await load(); } catch (e) { BD.toast(e.message, true); }
    }
    function rejectPopup(id) {
      const r = findRow(id); if (!r) return;
      const m = BD.modal('<h3>Reject tracking ID</h3><div class="bd-sub" style="margin-bottom:10px">' + esc(r.order) + ' · ' + esc(r.pend ? r.pend.courier + ' ' + r.pend.track : '') + '. The Tracking person is told and adds the right one.</div><div class="bd-f"><label>Why? (optional, they see it)</label><input class="bd-in" id="rj_r" maxlength="200"></div><div class="acts"><button type="button" class="bd-btn" id="rj_x">Cancel</button><button type="button" class="bd-btn bad" id="rj_ok">Reject</button></div>');
      m.querySelector('#rj_x').onclick = BD.close;
      m.querySelector('#rj_ok').onclick = () => { const why = m.querySelector('#rj_r').value.trim(); BD.close(); decide([+id], 'reject', why); };
    }


    /* ---------- LIVE: the scan history of one parcel (opens from the badge next to the tracking number) ---------- */
    let LV = null;
    const STEPS = ['Booked', 'Picked up', 'In transit', 'Out for delivery', 'Delivered'];
    const titleCase = (t) => String(t || '').toLowerCase().replace(/(^|[\s\-\/])([a-z])/g, (m, a, b) => a + b.toUpperCase());
    function liveStage(p, ev) {
      const txt = ((p.sr_status || '') + ' ' + (ev[0] ? ev[0].label + ' ' + ev[0].activity : '')).toLowerCase();
      if (p.status === 'delivered') return 4;
      if (p.status === 'placed') return -1;
      if (p.status === 'tracked') return 0;
      if (/out for delivery/.test(txt)) return 3;
      if (/pick/.test(txt) && !/transit|hub|reach|arriv|destination/.test(txt)) return 1;
      return 2;
    }
    function drawLive(d) {
      if (!LV) return; const p = d.p, ev = d.events || [], rto = p.status === 'rto', cancelled = p.status === 'cancelled';
      LV.status = p.status; LV.live_at = p.live_at; const stg = liveStage(p, ev);
      const head = rto ? 'Returning to sender' : cancelled ? 'Cancelled' : p.status === 'delivered' ? 'Delivered' : (p.sr_status ? titleCase(p.sr_status) : (p.status === 'placed' ? 'No tracking ID yet' : 'Tracking ID given'));
      const col = rto ? '#c2410c' : cancelled ? '#6b7280' : p.status === 'delivered' ? '#16945a' : '#14161a';
      let eta = '';
      if (p.status === 'delivered') eta = '<span class="dl-eta ok">Delivered ' + esc(fdt(p.delivered_at || (ev[0] && ev[0].at) || '')) + '</span>';
      else if (rto) eta = '<span class="dl-eta bad">Coming back to you</span>';
      else if (p.etd) { const t = new Date(p.etd + 'T23:59:00').getTime(), late = t < Date.now(); eta = '<span class="dl-eta' + (late ? ' bad' : '') + '">' + (late ? 'Was expected ' : 'Expected by ') + esc(fd(p.etd)) + '</span>'; }
      const steps = '<div class="dl-steps">' + STEPS.map((n, i) => '<div class="dl-s' + (i <= stg ? ' done' : '') + (i === stg ? ' now' : '') + (rto && i === stg ? ' bad' : '') + '"><i></i>' + n + '</div>').join('') + '</div>';
      const tn = p.tracking_no ? (p.link ? '<a href="' + esc(p.link) + '" target="_blank" rel="noopener">' + esc(p.tracking_no) + ' ↗</a>' : esc(p.tracking_no)) : 'no tracking ID';
      const hd = LV.ov.querySelector('.dl-hd');
      hd.innerHTML = '<button type="button" class="dl-x" aria-label="Close">✕</button><div class="dl-ord">' + esc(p.order) + ' · ' + esc(sName(p.store)) + '</div><div class="dl-st" style="color:' + col + '">' + esc(head) + '</div>'
        + '<div class="dl-sub"><b>' + esc(p.customer || 'Customer') + '</b>' + (p.phone ? ' · ' + esc(p.phone) : '') + '<br>' + (p.courier ? esc(p.courier) + ' · ' : '') + tn + '</div>' + eta + (cancelled ? '' : steps);
      const body = LV.ov.querySelector('.dl-bd');
      const jr = (d.note ? '<div class="dl-note"><b>Shiprocket:</b> ' + esc(d.note) + (ev.length ? ' Showing what we already have.' : '') + '</div>' : '')
        + (ev.length ? '<div class="dl-h">Journey · ' + ev.length + ' scan' + (ev.length === 1 ? '' : 's') + '</div>' + ev.map((e) => '<div class="dl-ev"><i></i><div><b>' + esc(e.activity || e.label || 'Update') + '</b><span><em>' + esc(fdt(e.at)) + '</em>' + (e.label && e.activity && e.label !== e.activity ? ' · ' + esc(titleCase(e.label)) : '') + (e.location ? ' · ' + esc(e.location) : '') + '</span></div></div>').join('')
          : '<div class="dl-empty"><b>📦</b>' + (p.tracking_no ? 'No scans yet.<br>They appear here the moment the courier scans the parcel.' : 'This parcel has no tracking ID yet.') + '</div>');
      if (d.card) LV.card = d.card;
      body.innerHTML = LV.card ? '<div class="dl-cols"><div>' + cardHtml(LV.card, p) + '</div><div class="dl-jr">' + jr + '</div></div>' : jr;
      if (LV.card && !LV.card.manual && !LV.items) loadItems();
      const fresh = p.live_at && (Date.now() - parseT(p.live_at)) < 30 * 60000;
      LV.ov.querySelector('.dl-ft').innerHTML = '<div class="lv' + (fresh ? ' on' : '') + '"><i></i>' + (p.live_at ? 'Updated ' + esc(agoTxt(p.live_at)) + (p.live_src === 'hook' ? ' · pushed by Shiprocket' : ' · asked Shiprocket') : 'Not updated yet') + '</div>'
        + (LV.card && LV.card.chat ? '<button type="button" class="dl-btn" data-occhat="1">💬 Chat</button>' : '')
        + (p.tracking_no ? '<button type="button" class="dl-btn" data-lvr="1">Refresh now</button>' : '') + (p.link ? '<a class="dl-btn p" href="' + esc(p.link) + '" target="_blank" rel="noopener">Courier page ↗</a>' : '');
    }
    async function liveLoad(force) {
      if (!LV || LV.busy) return; LV.busy = true;
      const rb = LV.ov.querySelector('[data-lvr]'); if (rb) { rb.disabled = true; rb.textContent = 'Asking Shiprocket…'; }
      try { const d = await api.get('dp.dsp_live', force ? { id: LV.id, force: 1 } : { id: LV.id }); if (LV) drawLive(d); }
      catch (er) { if (LV) { const b = LV.ov.querySelector('.dl-bd'); if (b && !b.querySelector('.dl-ev')) b.innerHTML = '<div class="dl-note">' + esc(er.message) + '</div>'; } }
      if (LV) LV.busy = false;
    }
    function closeLive() {
      if (!LV) return; const L = LV; LV = null; clearInterval(L.timer); document.removeEventListener('keydown', L.key); L.ov.remove();
      const r = findRow(L.id); if (!r) return;
      if (L.status && L.status !== r.status) return load();   // the parcel moved on (for example delivered): put it in its new group
      if (L.live_at) { r.live_at = L.live_at; patch(L.id); }
    }
    /* 🧾 The Order card: everything about this parcel's order (payment, salesperson, commission, complaints, chat, times), left of the live journey */
    const rs = (n) => n == null || n === '' ? '—' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
    const kv = (l, v, cls) => '<div><small>' + l + '</small><b' + (cls ? ' class="' + cls + '"' : '') + '>' + v + '</b></div>';
    function cardHtml(c, p) {
      const pay = c.pay || {}, wm = c.wm, t = c.times || {};
      const st = String(pay.status || '').replace(/_/g, ' ');
      const cod = pay.outstanding > 0 ? rs(pay.outstanding) + ' COD' : (wm && wm.cod > 0 ? rs(wm.cod) + ' COD' : 'Prepaid');
      let h = '<div class="oc-sec"><div class="t">💳 Order &amp; payment' + (c.manual ? ' <span class="oc-chip pu">MANUAL</span>' : '') + '</div><div class="oc-g">'
        + kv('Ordered', esc(fdt(t.ordered) || '—')) + kv('Total', rs(pay.total != null ? pay.total : (wm && wm.total))) + kv('To collect', esc(cod), pay.outstanding > 0 || (wm && wm.cod > 0) ? 'bad' : 'ok')
        + kv('Payment', esc(st || (wm ? (wm.pay === 'cod' ? 'COD' : wm.pay === 'partial' ? 'Part paid' : 'Paid') : '—'))) + kv(c.manual ? 'UTR (payment ref.)' : 'Transaction ID', esc((wm && wm.utr) || (pay.ref || '').split(',')[0] || '—'))
        + kv('Gateway', esc(pay.gateway || (c.manual ? 'UPI / QR' : '—'))) + kv('ML', c.ml != null ? c.ml + ' ml' : '—') + kv('Order code', esc(c.code || '—'))
        + kv('Finance', pay.paid_amt != null ? rs(pay.paid_amt) + ' in' + (pay.paid_on ? ' · ' + esc(fd(pay.paid_on)) : '') : (pay.closed ? esc(pay.closed) : 'not received yet'), pay.paid_amt != null ? 'ok' : '') + '</div>';
      if (wm) {
        const rd = wm.read || {};
        h += '<div style="margin-top:8px;font-size:12.5px;color:#4a505c">' + (wm.paid ? 'Paid ' + rs(wm.paid) + (wm.cod ? ' + ' + rs(wm.cod) + ' on delivery' : '') : 'Full COD') + (rd.amount ? ' · Claude read ' + rs(rd.amount) + (rd.date ? ' on ' + esc(rd.date) : '') + (rd.payer ? ' from ' + esc(rd.payer) : '') : '') + '</div>'
          + (wm.shot ? '<div class="oc-shot" style="margin-top:6px"><button type="button" class="oc-btn" data-ocshot="1">🖼 Show payment screenshot</button></div>' : '');
      }
      h += '</div>';
      // 👤 who sold it + commission
      const com = c.com;
      h += '<div class="oc-sec"><div class="t">👤 Salesperson &amp; commission</div><div class="oc-g">' + kv('Took the order', esc(c.sp || 'not allocated')) + kv('Type', c.sp_type ? (c.sp_type === 'campaign' ? 'Campaign' : 'Query') : '—')
        + kv('Commission', com ? rs(com.amt) + (com.pct ? ' · ' + com.pct + '%' : '') : 'not counted yet', com ? 'ok' : '') + (wm ? kv('Manual order by', esc(wm.by || '—')) : '') + (c.wa ? kv('Chat with', esc(c.wa.holder || '—')) : '') + (c.wa ? kv('Customer wrote last', esc(fdt(c.wa.last_in) || '—')) : '') + '</div>'
        + (c.tags ? '<div style="margin-top:7px">' + c.tags.split(',').map((x) => x.trim()).filter(Boolean).map((x) => '<span class="oc-chip" style="margin:0 4px 4px 0">' + esc(x) + '</span>').join('') + '</div>' : '') + '</div>';
      // 📦 items + address
      const lines = wm ? wm.lines : (LV.items ? LV.items.line_items : null), cu = wm ? wm.cust : (LV.items || null);
      h += '<div class="oc-sec"><div class="t">📦 Items &amp; address</div>' + (lines ? lines.map((l) => '<div class="oc-li"><span>' + esc(l.name || ((l.title || '') + (l.variant ? ' · ' + l.variant : ''))) + (l.chain ? ' <em>chain</em>' : '') + ' <em>× ' + (l.qty || l.quantity || 1) + '</em></span><span>' + rs(l.total) + '</span></div>').join('') : '<div class="bd-sub" id="ocItems">' + (c.manual ? '—' : 'Reading the order from Shopify…') + '</div>');
      if (cu) { const ad = wm ? [cu.addr1, cu.addr2, cu.landmark, [cu.city, cu.pin].filter(Boolean).join(' '), cu.state].filter(Boolean).join(', ') : (cu.address || []).join(', ');
        h += '<div style="margin-top:8px;font-size:12.5px;line-height:1.5;color:#4a505c">' + (ad ? '📍 ' + esc(ad) : '') + (wm && (cu.phone || cu.phone2) ? '<br>📞 ' + esc([cu.phone, cu.phone2].filter(Boolean).join(' / ')) : '') + (cu.discount_codes && cu.discount_codes.length ? '<br>🏷 ' + cu.discount_codes.map((x) => esc(x.code)).join(', ') : '') + '</div>'; }
      h += '</div>';
      // ⚠ complaints
      h += '<div class="oc-sec"><div class="t">⚠ Complaints<span>' + (c.cx.length ? c.cx.length : 'none') + '</span></div>' + (c.cx.length ? c.cx.map((x) => { const open = x.status === 'open' || x.status === 'reopened';
        return '<div class="oc-cx"><a href="complaints.html?case=' + x.id + '" target="_top">' + esc(x.code) + '</a><span>' + esc(x.label) + '</span><span class="oc-chip ' + (open ? 'bad' : 'ok') + '">' + (open ? 'open' : esc(x.status)) + '</span>' + (x.owner ? '<span class="bd-sub">' + esc(x.owner) + '</span>' : '') + '<span class="bd-sub">' + esc(fdt(x.raised)) + (x.this_order ? '' : ' · other order') + '</span></div>'; }).join('') : '<div class="bd-sub">No complaint for this order or customer.</div>') + '</div>';
      // ⏱ times
      h += '<div class="oc-sec"><div class="t">⏱ Times</div><div class="oc-tl">' + (wm ? '<div><b>Manual order made</b><span>' + esc(fdt(wm.made)) + (wm.confirmed ? ' · customer confirmed ' + esc(fdt(wm.confirmed)) : '') + '</span></div>' : '')
        + '<div><b>Ordered</b><span>' + esc(fdt(t.ordered) || '—') + '</span></div><div><b>Tracking ID</b><span>' + (t.tracked ? esc(fdt(t.tracked)) + (t.h_track != null ? ' · ' + t.h_track + ' h after the order' : '') + (t.tracked_by ? ' · by ' + esc(t.tracked_by) : '') : '—') + '</span></div>'
        + '<div><b>In transit</b><span>' + esc(fdt(t.transit) || '—') + '</span></div><div><b>Delivered</b><span>' + (t.delivered ? esc(fdt(t.delivered)) + (t.h_deliver != null ? ' · ' + (Math.round(t.h_deliver / 2.4) / 10) + ' days' : '') : '—') + '</span></div><div><b>COD money in</b><span>' + esc(fdt(t.remit) || '—') + '</span></div>'
        + (wm && wm.sr ? '<div><b>Shiprocket</b><span>' + esc(wm.sr) + '</span></div>' : '') + '</div></div>';
      return h;
    }
    async function loadItems() {
      const L = LV; if (!L || !L.card) return; L.items = { line_items: null };
      try { const r = await api.get('shopify.order_detail', { store: L.card.store, id: L.card.shopify_id }); if (LV !== L) return; L.items = r.order || {}; }
      catch (er) { if (LV !== L) return; const x = document.getElementById('ocItems'); if (x) x.textContent = 'Could not read the items from Shopify: ' + er.message; return; }
      const b = L.ov.querySelector('.dl-cols > div'); if (b) b.innerHTML = cardHtml(L.card, {});
    }
    async function loadShot(btn) {
      const L = LV; if (!L) return; btn.disabled = true; btn.textContent = 'Loading…';
      try { const r = await api.get('dp.dsp_card_shot', { id: L.id }); const box = btn.parentNode; box.innerHTML = '<img alt="Payment screenshot" src="data:' + esc(r.mime) + ';base64,' + r.b64.replace(/[^A-Za-z0-9+/=]/g, '') + '">'; }
      catch (er) { btn.textContent = er.message; }
    }
    function openLive(id) {
      closeLive();
      const ov = document.createElement('div'); ov.className = 'dl-ov';
      ov.innerHTML = '<div class="dl-pn" role="dialog" aria-modal="true" aria-label="Live tracking"><div class="dl-hd"><button type="button" class="dl-x" aria-label="Close">✕</button><div class="dl-ord">Loading…</div></div><div class="dl-bd"><div class="dl-empty"><b>📡</b>Asking Shiprocket…</div></div><div class="dl-ft"></div></div>';
      document.body.appendChild(ov);
      const key = (e) => { if (e.key === 'Escape') closeLive(); };
      document.addEventListener('keydown', key);
      ov.addEventListener('click', (e) => { if (e.target === ov || e.target.closest('.dl-x')) return closeLive(); if (e.target.closest('[data-lvr]')) liveLoad(true);
        if (e.target.closest('[data-ocshot]')) loadShot(e.target.closest('[data-ocshot]'));
        if (e.target.closest('[data-occhat]') && LV && LV.card) { const c = LV.card; closeLive(); openChat(c.chat, c.order, (c.wa && c.wa.name) || ''); } });
      LV = { id: +id, ov, key, busy: false, timer: setInterval(() => { if (!document.hidden) liveLoad(false); }, 30000) };
      liveLoad(false);
    }
    /* Admin: the address + token to paste into Shiprocket → Settings → API → Webhooks */
    function liveBox(sr, admin) {
      const lv = sr.live; if (!lv) return '';
      const rec = lv.hook_ts ? '<span class="bd-chip ok">Receiving · last ' + esc(lv.hook_at) + ' · ' + lv.hook_today + ' today</span>' : '<span class="bd-chip bad">Not connected yet</span>';
      return '<div class="dr-lv"><div style="font-weight:700">⚡ Instant updates (Shiprocket webhook)</div><div class="dr-row" style="margin-top:6px">' + rec + '</div>'
        + '<div class="bd-sub" style="margin-top:4px">Safety net: ' + (lv.tick_at ? 'last ran ' + esc(lv.tick_at) + ' — ' + lv.tick_ok + ' parcels read, ' + lv.tick_bad + ' without scans' + (lv.tick_err ? ' (' + esc(lv.tick_err) + ')' : '') : 'has not run yet') + '. It asks Shiprocket again about parcels in transit that have gone quiet.</div>'
        + (admin ? '<div class="dr-row"><button type="button" class="bd-btn sm" data-lvinfo="1">Show webhook address &amp; token</button></div><div id="lvInfo"></div>' : '') + '</div>';
    }
    async function liveInfo(btn) {
      const box = document.getElementById('lvInfo'); if (!box) return; btn.disabled = true;
      try {
        const d = await api.get('dp.dsp_live_info');
        box.innerHTML = '<div class="u"><span class="dr-lbl">Callback URL</span><code>' + esc(d.url) + '</code><button type="button" class="bd-btn sm" data-cp="' + esc(d.url) + '">Copy</button>' + (d.url_ok ? '<span class="bd-chip ok">allowed</span>' : '<span class="bd-chip bad">Shiprocket refuses URLs containing "sr", "kr", "shiprocket" or "kartrocket"</span>') + '</div>'
          + '<div class="u"><span class="dr-lbl">Token</span><code>' + esc(d.token) + '</code><button type="button" class="bd-btn sm" data-cp="' + esc(d.token) + '">Copy</button></div>'
          + '<ol><li>In Shiprocket open <b>Settings → API → Webhooks</b> (on older panels <b>Settings → API → Configure</b>).</li><li>Paste the <b>Callback URL</b>. Paste the <b>Token</b> into the token / x-api-key box.</li><li>Tick the tracking updates, save, and press <b>Test</b> if it is offered. This box turns green on the first update.</li></ol>'
          + (d.unmatched && d.unmatched.length ? '<div class="bd-sub" style="margin-top:8px"><b>Not matched to a parcel (last ' + d.unmatched.length + '):</b> ' + d.unmatched.map((u) => esc((u.awb || '—') + ' / ' + (u.order || '—') + ' · ' + u.at)).join(' · ') + '</div>' : '');
      } catch (er) { box.innerHTML = '<div class="bd-sub" style="color:#d9423b">' + esc(er.message) + '</div>'; }
      btn.disabled = false;
    }

    /* ---------- clicks ---------- */
    el.addEventListener('click', async (e) => {
      const fb = e.target.closest && e.target.closest('[data-fpshow],[data-fpclose]'); if (!fb) return;
      e.stopPropagation();
      if (fb.dataset.fpshow !== undefined) { PSHOW = fb.dataset.fpshow === '1'; try { localStorage.setItem('fi_show_all', PSHOW ? '1' : '0'); } catch (er) {} table(); return; }
      const [order, chs, st] = fb.dataset.fpclose.split('|'), list = chs ? chs.split(',') : [];
      if (!confirm('Close ' + order + ' as ' + (st === 'rto' ? 'returned (RTO)' : 'not sent') + '?' + (list.indexOf('online_prepaid') > -1 ? '\nThe customer paid online, so a refund goes to Finance → Parcels → Refunds to give.' : '\nCOD: the customer never paid, so no refund is needed.'))) return;
      fb.disabled = true;
      try { for (const c of list) await api.post('fi.close', { channel: c, orders: [order], kind: st === 'rto' ? 'rto' : 'not_sent', resolution: c === 'online_prepaid' ? 'refund' : 'none' }); BD.toast('Closed.'); await load(); }
      catch (er) { BD.toast(er.message, true); fb.disabled = false; }
    }, true);
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('button,a,tr[data-band]'); if (!b || !el.contains(b)) return;
      if (b.tagName === 'A') return;
      const d = b.dataset;
      // the one big popup (order card + customer card + chat, order_hub.js); the old two popups stay only as a fallback when that file did not load
      if (d.chat) { if (window.OrderHub && d.pid) { OrderHub.onClose = () => { load(); }; return OrderHub.open({ id: d.pid }); } return openChat(d.chat, d.oname || '', d.cust || ''); }
      if (d.trk) { if (window.OrderHub) { OrderHub.onClose = () => { load(); }; return OrderHub.open({ id: d.trk }); } return openLive(d.trk); }
      if (d.lvinfo) return liveInfo(b);
      if (d.cp) { try { await navigator.clipboard.writeText(d.cp); BD.toast('Copied.'); } catch (er) { BD.toast('Select it and copy by hand.', true); } return; }
      if (d.band !== undefined) {   // click on a dark band: open / close the group
        const g = +d.band; if (!R.counts['g' + g]) return; if (e.target.closest('button')) return;
        S.open[g] = S.open[g] ? 0 : 1; keep(); return S.open[g] ? load() : (S.q ? load() : (table(), undefined));
      }
      if (d.all !== undefined) { ORDER.forEach((g) => { S.open[g] = d.all === '1' ? 1 : 0; }); keep(); return load(); }
      if (d.grp) { const g = +d.grp; return jump(g); }
      if (d.sd) { const n = +d.sd; S.sdays = n; S.from = ''; S.to = ''; S.day = ''; keep(); return load(); }
      if (d.day) { S.day = S.day === d.day ? '' : d.day; keep(); await load(); if (S.day) { const t = $('dsAll'); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); } return; }
      if (d.fixtoggle) { S.fix = !S.fix; keep(); return fix(); }
      if (d.go) {
        if (d.go === 'details') { S.details = !S.details; keep(); fix(); return; }
        if (!S.details) { S.details = true; keep(); fix(); }
        const t = document.getElementById(d.go); if (t) { t.scrollIntoView({ behavior: 'smooth', block: 'center' }); t.classList.add('flash'); setTimeout(() => t.classList.remove('flash'), 1500); } return;
      }
      if (d.copy) { try { await navigator.clipboard.writeText(PB.problems[+d.copy].prompt); BD.toast('Prompt copied. Paste it to your builder or Claude.'); } catch (er) { BD.toast('Select the text and copy it.', true); } return; }
      if (d.more) { const [g, how] = d.more.split('|'); S.lim[g] = how === 'all' ? Math.min(3000, R.counts['g' + g]) : (limOf(g) + 100); keep(); return load(); }
      if (d.dn) { const v = d.v === '1'; b.disabled = true; try { const r = await api.post('dp.dsp_done', { ids: [+d.dn], done: v }); const row = findRow(d.dn); if (row) { row.done_at = v ? r.at : ''; row.done_by = v ? r.by : ''; patch(d.dn); } } catch (er) { BD.toast(er.message, true); b.disabled = false; } return; }
      if (d.rm) { const v = d.v === '1'; b.disabled = true; try { await api.post('dp.dsp_remit', { ids: [+d.rm], remit: v }); await load(); } catch (er) { BD.toast(er.message, true); b.disabled = false; } return; }
      if (d.add) return addForm(d.add);
      if (d.fx) return patch(d.fx);
      if (d.fs) return sendForm(d.fs, b);
      if (d.urec) return recordU(d.urec, b);
      if (d.hist) return toggleHist(d.hist);
      if (d.reas) return reassign(d.reas, b);
      if (d.uend) { const [id, how] = d.uend.split('|'); return endCase(id, how, b); }
      if (d.chown) return ownerPopup();
      if (d.ap) return decide([+d.ap], 'approve');
      if (d.rj) return rejectPopup(d.rj);
      if (d.apall) { const ids = ((R.groups && R.groups[9]) ? R.groups[9].rows : []).map((r) => r.id); if (!ids.length) return; if (await BD.confirm('Approve ' + ids.length + ' tracking IDs?', 'They move to In transit.', 'Approve all')) decide(ids, 'approve'); return; }
      if (d.csave !== undefined) {
        const tr = b.closest('tr'); b.disabled = true;
        try { const r = await api.post('dp.dsp_courier_save', { id: +d.csave, name: tr.querySelector('[data-cn]').value.trim(), link: tr.querySelector('[data-cl]').value.trim(), active: tr.querySelector('[data-ca]').checked }); CL = r.couriers; BD.toast(r.message); await load(); }
        catch (er) { BD.toast(er.message, true); b.disabled = false; } return;
      }
      if (d.cdel) { if (!(await BD.confirm('Remove this courier?', 'Parcels that already used it keep the name.', 'Remove', true))) return; try { const r = await api.post('dp.dsp_courier_save', { id: +d.cdel, remove: 1 }); CL = r.couriers; BD.toast(r.message); await load(); } catch (er) { BD.toast(er.message, true); } return; }
      const msg = (t, bad) => { const m = $('srMsg'); if (m) { m.textContent = t; m.style.color = bad ? '#d9423b' : '#16945a'; } BD.toast(t, bad); };
      try {
        if (b.id === 'srSave') { b.disabled = true; const r = await api.post('dp.dsp_sr_save', { email: $('srEmail').value.trim(), password: $('srPass').value }); msg(r.message); b.disabled = false; await fixRefresh(); }
        else if (b.id === 'srDel') { if (!(await BD.confirm('Remove the Shiprocket login?', 'Statuses will stop updating by themselves.', 'Remove', true))) return; await api.post('dp.dsp_sr_save', { remove: 1 }); await fixRefresh(); }
        else if (b.id === 'srTest') { b.disabled = true; msg('Testing…'); const r = await api.post('dp.dsp_sr_test', {}); b.disabled = false; await fixRefresh(); msg(r.message, !r.ok); }
        else if (b.id === 'recGo') { await recAll(b); }
        else if (b.id === 'srSync') { b.disabled = true; msg('Reading Shiprocket…'); const r = await api.post('dp.dsp_sr_sync', { days: 30 }); b.disabled = false; await load(); msg(r.message, !r.ok); }
      } catch (er) { BD.toast(er.message, true); b.disabled = false; }
    });
    el.addEventListener('change', (e) => {
      const t = e.target;
      if (t.id === 'dsStore') { S.store = t.value; S.day = ''; keep(); return load(); }
      if (t.id === 'dsFrom' || t.id === 'dsTo') { const f = $('dsFrom').value, to = $('dsTo').value; if (!f && !to) { S.from = ''; S.to = ''; } else { S.from = f || to; S.to = to || f || iso(new Date()); } S.day = ''; keep(); return load(); }
      if (t.matches('[data-fc]')) { fillLink(t.closest('[data-form]')); return; }
    });
    el.addEventListener('input', (e) => {
      const t = e.target;
      if (t.matches('[data-ft]')) return fillLink(t.closest('[data-form]'));
      if (t.matches('[data-fl]')) { t.dataset.touched = '1'; const a = t.closest('[data-form]').querySelector('[data-fa]'); a.style.display = t.value ? '' : 'none'; a.textContent = 'edited'; return; }
      if (t.id === 'dsQ') { clearTimeout(qt); qt = setTimeout(() => { S.q = t.value.trim(); keep(); load(); }, 350); }
    });
    el.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      const f = e.target.closest && e.target.closest('[data-form]');
      if (f && e.target.matches('input')) { e.preventDefault(); const b = f.querySelector('[data-fs]'); if (b) sendForm(f.dataset.form, b); }
    });

    /* ---------- Download Excel (CSV): the same window, every parcel, all groups ---------- */
    async function csv(btn) {
      btn.disabled = true;
      try {
        const p = win(); delete p.sdays; delete p.sfrom; delete p.sto;
        const x = await api.get('dp.dsp_report', Object.assign(p, { export: 1, page: 1 }));
        const cols = [['Order', 'order'], ['Store', 'store'], ['Ordered', 'created'], ['Customer', 'customer'], ['Phone', 'phone'], ['Group', 'grp_name'], ['Tracking no.', 'tracking_no'], ['Courier', 'courier'], ['Status', 'status'], ['Shiprocket status', 'sr_status'], ['Tracking assigned at', 'tracked_at'], ['Assigned by', 'tracked_by'], ['Hours to assign', 'track_h'], ['Dispatched at', 'transit_at'], ['Delivered at', 'delivered_at'], ['Late', 'late'], ['Courier link', 'link'], ['Untraceable owner', 'u_owner'], ['Untraceable days', 'u_days'], ['Today\u2019s remark', 'u_label'], ['Remark text', 'u_note'], ['Action note', 'u_note'], ['Done by', 'done_by'], ['Done at', 'done_at'], ['COD to receive', 'cod'], ['Money received at', 'remit_at'], ['Money received by', 'remit_by']];
        const q = (v) => { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const SL = { placed: 'No tracking ID', tracked: 'Tracking ID given', transit: 'In transit', delivered: 'Delivered', rto: 'RTO', cancelled: 'Cancelled' };
        x.rows.forEach((r) => { r.status = SL[r.status] || r.status; r.grp_name = r.grp ? ((r.grp < 9 ? r.grp + ' · ' : '') + G[r.grp][0]) : ''; r.u_label = r.u && r.u.today ? r.u.today.label : ''; r.u_note = r.u && r.u.today ? r.u.today.note : ''; r.u_owner = r.u ? r.u.owner : ''; r.u_days = r.u ? r.u.days : ''; });
        const lines = [cols.map((c) => q(c[0])).join(',')].concat(x.rows.map((r) => cols.map((c) => q(r[c[1]])).join(',')));
        const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }); const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = 'dispatch_' + x.filters.from + '_to_' + x.filters.to + '.csv'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        BD.toast(x.rows.length + ' parcels saved' + (x.total > x.rows.length ? ' (first ' + x.rows.length + ' of ' + x.total + ')' : '') + '.');
      } catch (er) { BD.toast(er.message, true); }
      btn.disabled = false;
    }

    load();
    // new numbers every 90 s — but never while somebody is typing or has an add-ID / action form open
    setInterval(() => {
      if (document.hidden || busy) return;
      const a = document.activeElement; if (a && /INPUT|SELECT|TEXTAREA/.test(a.tagName)) return;
      if (el.querySelector('[data-form]')) return;
      if (Array.prototype.some.call(el.querySelectorAll('[data-ua],[data-un]'), (x) => x.value)) return;
      load();
    }, 90000);
    return { reload: load };
  }
  return { mount };
})();
