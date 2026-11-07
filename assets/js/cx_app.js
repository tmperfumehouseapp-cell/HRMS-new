/* cx_app.js — the Complaints department screens: Board, List, Case (with the customer chat), Reports, Settings, and "My complaints".
   Pages: complaints.html (CX.start(el, {mode:'team'})) and my_complaints.html (CX.start(el, {mode:'mine'})).  Needs api.js and cx_raise.js.  Talks to bd.cx_* (complaints.php). */
window.CX = (function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // turn web addresses in an (already escaped) message into tappable links that open in a new tab
  const linkify = (h) => String(h).replace(/\b(?:https?:\/\/|www\.)[^\s<]+/gi, (u) => { let t = ''; const m = /(?:[.,!?;:)\]]|&quot;|&#39;)+$/.exec(u); if (m) { t = m[0]; u = u.slice(0, -t.length); } const href = /^www\./i.test(u) ? 'https://' + u : u; return `<a href="${href}" target="_blank" rel="noopener noreferrer" style="color:#027eb5;text-decoration:underline;word-break:break-all">${u}</a>` + t; });
  const $ = (s, r) => (r || document).querySelector(s);
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const hm = (s) => (s ? String(s).slice(11, 16) : '');
  const dm = (s) => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); };
  const ini = (n) => String(n || '?').trim().split(/\s+/).slice(0, 2).map((x) => x[0]).join('').toUpperCase();
  const COL = ['#c2410c', '#7c3aed', '#0f766e', '#be185d', '#2563eb', '#475569', '#b45309'];
  const col = (id) => COL[Math.abs(Number(id) || 0) % COL.length];
  const URG = { urgent: 'Urgent', high: 'High', normal: 'Normal' };
  const STAT = { open: 'Open', reopened: 'Reopened', resolved: 'Solved', closed: 'Closed' };
  const LSTAT = { booked: 'Pickup booked', picked: 'Picked up', received: 'Received', qc_ok: 'QC passed', shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled', planned: 'Planned' };
  let H, MODE = 'team', B = {}, S = { tab: 'tickets', f: { who: 'all', late: false, src: '', prob: '', q: '' }, status: 'all', q: '' }, C = null, T = null, timer = null, thTimer = null, sugg = null, quick = null, blobs = {};
  const PLAYED = {};   // 🎬 video id → its file, so a refresh of the chat keeps the player

  /* ------------------------------ look ------------------------------ */
  function css() {
    if (document.getElementById('cxCss')) return;
    const s = document.createElement('style'); s.id = 'cxCss';
    s.textContent = `
.cx{--g:#008069;--a:#00a884;--ln:#e9edef;--mut:#667781;--red:#d92d20;font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;color:#111b21;font-size:13.5px;line-height:1.4;min-height:100%;background:#f0f2f5}
.cx-ov{--g:#008069;--a:#00a884;--ln:#e9edef;--mut:#667781;--red:#d92d20;font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;color:#111b21;font-size:13.5px;line-height:1.4}.cx-ov *{box-sizing:border-box}.cx-ov button{font-family:inherit}
.cx *{box-sizing:border-box}.cx button{font-family:inherit}
.cx-top{background:#fff;border-bottom:1px solid var(--ln);padding:10px 16px 0;position:sticky;top:0;z-index:5}
.cx-t1{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.cx-t1 h1{font-size:19px;margin:0;font-weight:800}.cx-t1 .sub{color:var(--mut);font-size:12.5px;flex:1;min-width:160px}
.cx-tabs{display:flex;gap:2px;margin-top:6px;overflow-x:auto;scrollbar-width:none}.cx-tabs::-webkit-scrollbar{display:none}
.cx-tab{flex:none;border:0;background:none;padding:9px 14px;font-size:14px;font-weight:700;color:var(--mut);cursor:pointer;border-bottom:3px solid transparent;white-space:nowrap}.cx-tab.on{color:var(--g);border-color:var(--g)}
.cx-body{padding:14px 16px 40px}
.cx-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:999px;padding:8px 15px;font-weight:800;font-size:13px;background:var(--a);color:#fff;cursor:pointer;white-space:nowrap}.cx-btn.gh{background:#fff;border:1px solid var(--ln);color:#111b21}.cx-btn.red{background:#fde4e4;color:#b3261e}.cx-btn.s{padding:5px 11px;font-size:12px}.cx-btn[disabled]{opacity:.5}
.cx-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:11.5px;font-weight:700;background:#f0f2f5;color:#54656f;white-space:nowrap}
.cx-pill.g{background:#d9fdd3;color:#008069}.cx-pill.r{background:#fde4e4;color:#b3261e}.cx-pill.o{background:#fff1d6;color:#9a6200}.cx-pill.b{background:#e0ecff;color:#1d4ed8}.cx-pill.p{background:#ede7ff;color:#5b34b5}
.cx-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:12px}
.cx-k{background:#fff;border-radius:14px;padding:10px 14px;border:1px solid var(--ln)}.cx-k small{display:block;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--mut)}.cx-k b{font-size:23px;font-weight:800;letter-spacing:-.02em}.cx-k.red b{color:var(--red)}
.cx-fl{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px;align-items:center}
.cx-ch{display:inline-flex;gap:4px;align-items:center;background:#fff;border:1px solid var(--ln);border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:700;color:#54656f;cursor:pointer}.cx-ch.on{background:#d9fdd3;border-color:#d9fdd3;color:var(--g)}
.cx-in,.cx-sel{border:1px solid var(--ln);border-radius:999px;padding:6px 12px;font:inherit;font-size:13px;background:#fff;color:#111b21;outline:0;min-height:32px}.cx-in:focus,.cx-sel:focus{border-color:var(--a)}
.cx-cols{display:grid;grid-template-columns:repeat(7,minmax(215px,1fr));gap:10px;overflow-x:auto;align-items:start;padding-bottom:10px}
.cx-col{background:#e8ebee;border-radius:16px;padding:10px;min-height:120px}
.cx-col h3{margin:0;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:space-between}.cx-col h3 em{font-style:normal;background:#fff;border-radius:999px;padding:0 9px;font-size:12px;color:#54656f}
.cx-col .ab{font-size:11.5px;color:var(--mut);margin:2px 0 8px}
.cx-card{background:#fff;border-radius:10px;padding:7px 10px;margin-bottom:6px;border-left:4px solid #cfd6db;cursor:pointer;box-shadow:0 1px 2px rgba(17,27,33,.08)}.cx-card:active{transform:scale(.99)}
.cx-card.urgent{border-left-color:var(--red)}.cx-card.high{border-left-color:#f59e0b}
.cx-card .r1{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-weight:800;font-size:12.5px}.cx-card .tm{margin-left:auto;font-size:12px;font-weight:800;color:#0a7a55}.cx-card .tm.late{color:var(--red)}
.cx-card .nm{font-weight:800;font-size:13.5px;margin-top:1px}.cx-card .tx{color:#54656f;font-size:12px;margin-top:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cx-card .r3{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-top:5px}.cx-av{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:11px;font-weight:800;margin-left:auto;flex:none}
.cx-empty{color:var(--mut);text-align:center;padding:26px 10px;font-size:13px}
.cx-warn{background:#fff1d6;color:#7a4b00;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13px}
.cx-box{background:#fff;border:1px solid var(--ln);border-radius:16px;padding:14px 16px;margin-bottom:12px}.cx-box h4{margin:0 0 8px;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--mut)}
.cx-row{display:flex;gap:10px;align-items:center;padding:10px 0;border-top:1px solid var(--ln);cursor:pointer}.cx-row:first-child{border-top:0}.cx-row .m{flex:1;min-width:0}.cx-row .m b{font-weight:800}.cx-row .m small{display:block;color:var(--mut)}
.cx-tbl{width:100%;border-collapse:collapse;font-size:13px}.cx-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);text-align:left;padding:5px 6px}.cx-tbl td{padding:7px 6px;border-top:1px solid var(--ln);vertical-align:middle}.cx-tbl td.n,.cx-tbl th.n{text-align:right}
.cx-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.cx-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.cx-bars{display:flex;gap:4px;align-items:flex-end;height:70px}.cx-bars i{flex:1;background:#00a884;border-radius:4px 4px 0 0;min-height:2px;position:relative}
/* the case */
.cx-case{display:flex;flex-direction:column;height:calc(100vh - 8px);min-height:520px;background:#f0f2f5}
.cx-chd{background:#fff;border-bottom:1px solid var(--ln);padding:9px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.cx-chd .bk{border:0;background:#f0f2f5;border-radius:50%;width:34px;height:34px;font-size:18px;cursor:pointer}.cx-chd h2{margin:0;font-size:16px;font-weight:800}
.cx-cb{flex:1;min-height:0;display:grid;grid-template-columns:minmax(330px,42%) minmax(0,1fr)}
.cx-l{overflow:auto;padding:12px 14px 30px;border-right:1px solid var(--ln)}
.cx-seg{display:none}
.cx-pb{display:flex;gap:10px;padding:6px 0;align-items:flex-start;cursor:pointer}.cx-pb i{flex:none;width:24px;height:24px;border-radius:50%;border:2px solid #cfd6db;display:grid;place-items:center;font-style:normal;font-size:12px;font-weight:800;color:#8696a0;background:#fff}.cx-pb.done i{background:#00a884;border-color:#00a884;color:#fff}.cx-pb.next i{border-color:#00a884;color:#00a884}.cx-pb b{font-weight:700}.cx-pb.done b{color:#54656f}
.cx-acts{display:grid;grid-template-columns:1fr 1fr;gap:8px}.cx-acts button{border:0;border-radius:12px;padding:12px 8px;font-weight:800;font-size:13.5px;cursor:pointer}
.cx-ord{background:#f0f2f5;border-radius:12px;padding:10px 12px;font-size:13px}.cx-ord b{font-weight:800}.cx-ord small{display:block;color:var(--mut)}
.cx-ev{font-size:12.5px;padding:5px 0;border-top:1px solid var(--ln);color:#3b4a54;overflow-wrap:anywhere}.cx-ev:first-child{border-top:0}.cx-ev small{color:var(--mut);margin-right:6px}
.cx-r{display:flex;flex-direction:column;min-height:0;min-width:0;background:#efeae2}
.cx-ph{background:#008069;color:#fff;padding:9px 14px;display:flex;align-items:center;gap:10px}.cx-ph .av{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:rgba(255,255,255,.22)}.cx-ph b{display:block;font-size:15px}.cx-ph small{opacity:.85}
.cx-msgs{flex:1;overflow:auto;padding:12px 6% 8px;display:flex;flex-direction:column;gap:4px;background:#efeae2 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40'%3E%3Ccircle cx='6' cy='6' r='1.2' fill='%23d7cfc3'/%3E%3Ccircle cx='26' cy='24' r='1.2' fill='%23d7cfc3'/%3E%3C/svg%3E")}
.cx-mv{display:inline-block;background:#e7f3ff;color:#0b5394;border:1px solid #b9d7f7;border-radius:10px;padding:10px 14px;cursor:pointer;font-size:14px;min-width:170px}.cx-mv:hover{background:#d6eaff}.cx-m{max-width:78%;padding:6px 9px 5px;border-radius:9px;font-size:14px;line-height:1.35;box-shadow:0 1px .5px rgba(11,20,26,.13);word-wrap:break-word;white-space:pre-wrap}.cx-m.in{background:#fff;align-self:flex-start;border-top-left-radius:2px}.cx-m.out{background:#d9fdd3;align-self:flex-end;border-top-right-radius:2px}
.cx-m .w{display:block;font-size:11.5px;font-weight:800;color:#008069;margin-bottom:1px}.cx-m .t{float:right;margin:4px 0 -2px 10px;font-size:11px;color:#667781}.cx-m .t.bl{color:#53bdeb}.cx-m img{max-width:240px;max-height:240px;border-radius:7px;display:block;cursor:zoom-in}.cx-m audio{max-width:230px}
.cx-s{align-self:center;background:#fff;border-radius:8px;padding:4px 10px;font-size:12px;color:#54656f;box-shadow:0 1px .5px rgba(11,20,26,.13);max-width:90%;text-align:center}.cx-s.n{background:#fff5c5;color:#6b5400;align-self:flex-end;text-align:left;max-width:80%}
.cx-qk{display:flex;gap:6px;overflow-x:auto;padding:6px 10px;background:#f0f2f5;scrollbar-width:none}.cx-qk::-webkit-scrollbar{display:none}.cx-qk button{flex:none;border:1px solid var(--ln);background:#fff;border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:700;cursor:pointer;color:#111b21}
.cx-sg{display:flex;align-items:center;gap:8px;background:#f0fff4;border-top:1px solid #cdeedd;padding:6px 10px;font-size:13px}.cx-sg span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-cmp{display:flex;gap:8px;align-items:flex-end;padding:8px 10px calc(8px + env(safe-area-inset-bottom));background:#f0f2f5}.cx-cmp textarea{flex:1;border:0;border-radius:20px;padding:10px 14px;font:inherit;font-size:15px;resize:none;max-height:120px;outline:0;background:#fff;min-height:42px}.cx-cmp .sd{width:44px;height:44px;border-radius:50%;border:0;background:#00a884;color:#fff;font-size:18px;cursor:pointer;flex:none}.cx-cmp .nt{width:40px;height:40px;border-radius:50%;border:0;background:#fff5c5;font-size:16px;cursor:pointer;flex:none}.cx-cmp.note textarea{background:#fff5c5}.cx-cmp.note .sd{background:#d4a800}
.cx-win{background:#fff1d6;color:#7a4b00;padding:8px 12px;font-size:13px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}.cx-win span{flex:1 1 180px;min-width:0}.cx-win .cx-btn{flex:none}
.cx-noc{flex:1;display:grid;place-items:center;color:var(--mut);text-align:center;padding:30px;background:#efeae2}
.cx-x{border:0;background:#eef1ef;border-radius:50%;width:38px;height:38px;cursor:pointer;font-size:15px;flex:none}.cx-mh{display:flex;align-items:center;gap:8px;margin-bottom:10px}.cx-mh b{flex:1;font-size:16px}.cx-md:has(.cx-trk){max-width:640px}.cx-trk-q{display:flex;gap:8px}.cx-trk-q .cx-in{flex:1;min-width:0}
.cx-pc{border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;margin-top:8px;background:#fff}.cx-pc .h{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.cx-pc .sp{flex:1}.cx-pc .m{font-size:12.5px;color:var(--mut);margin-top:4px;overflow-wrap:anywhere}
.cx-pc .ev{margin:6px 0 0;padding-left:18px;font-size:12.5px;line-height:1.5}.cx-pc .ev span{color:var(--mut)}.cx-lbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);margin:12px 0 4px}
.cx-note{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:8px 10px;font-size:13px}.cx-dyw{overflow-x:auto}.cx-dy tr.on td{background:#f0fdf4;font-weight:700}.cx-mv div{font-size:12.5px;padding:5px 0;border-bottom:1px solid #f1f5f9;overflow-wrap:anywhere}.cx-mv span{color:var(--mut)}
.cx-ov{position:fixed;inset:0;z-index:100;background:rgba(17,27,33,.5);display:flex;align-items:center;justify-content:center;padding:14px}.cx-md{background:#fff;border-radius:18px;padding:16px 18px;width:100%;max-width:470px;max-height:90vh;overflow:auto}.cx-md h3{margin:0 0 10px;font-size:17px;font-weight:800}.cx-md label{display:block;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--mut);margin:10px 0 4px}.cx-md .cx-in,.cx-md .cx-sel,.cx-md textarea{width:100%;border-radius:12px;padding:9px 12px;font-size:14px;border:1px solid var(--ln);font-family:inherit}.cx-md .ac{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
.cx-toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#111b21;color:#fff;padding:10px 18px;border-radius:999px;font-size:14px;font-weight:600;z-index:200;max-width:90vw}.cx-toast.bad{background:#b3261e}
@media (max-width:600px){.cx-lg{display:none}.cx-t1{flex-wrap:nowrap}.cx-t1 h1{flex:1}}
@media (max-width:900px){
 .cx-kpis{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.cx-k b{font-size:19px}
 .cx-cols{display:flex;scroll-snap-type:x mandatory;gap:10px}.cx-col{flex:0 0 84vw;min-width:0;scroll-snap-align:start}.cx-fl #cxQ{flex:1 1 180px}
 .cx-2,.cx-3{grid-template-columns:1fr}
 .cx-case{height:100vh}.cx-cb{display:block;position:relative;overflow:hidden}
 .cx-l,.cx-r{position:absolute;inset:0;border:0}.cx-case.v-l .cx-r{display:none}.cx-case.v-r .cx-l{display:none}
 .cx-seg{display:flex;background:#fff;border-bottom:1px solid var(--ln)}.cx-seg button{flex:1;border:0;background:none;padding:10px;font-weight:800;color:var(--mut);border-bottom:3px solid transparent;font-size:14px}.cx-seg button.on{color:var(--g);border-color:var(--g)}
 .cx-msgs{padding:10px 8px}.cx-m{max-width:86%}.cx-body{padding:12px 10px 40px}.cx-top{padding:8px 10px 0 62px;min-height:60px}.cx-chd{padding:8px 10px 8px 62px}.cx-t1 h1{font-size:18px}.cx-t1 .sub{display:none}.cx-chd h2{font-size:15px}.cx-case{height:100dvh}}
@media (max-width:900px){.cx-chd{flex-wrap:nowrap !important;overflow-x:auto;scrollbar-width:none;gap:6px !important;padding:6px 8px !important}.cx-chd::-webkit-scrollbar{display:none}.cx-chd>*{flex:none}.cx-chd>span[style*="flex:1"]{display:none}
 .cx-chd h2{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:56vw}.cx-ph{display:none}.cx-win{padding:6px 10px;font-size:12.5px;gap:6px}.cx-win span{flex-basis:100%}}
.cx-wt{font-size:12px;color:#7a4b00;opacity:.85}
@media (max-width:820px){body:has(#wsEmpNav) .cx-case{height:calc(100vh - 78px);height:calc(100dvh - 78px)}}
/* inside a workspace frame (hub) there is no floating menu button, so no room is kept for it */
@media (max-width:900px){html.in-sales .cx-top,html.in-sales .cx-chd{padding-left:10px}}
/* phones: the site-wide mobile.css shrinks every button to 30px / 12px — the Complaints screens keep finger-sized controls (40px+) */
@media (max-width:820px){
 .cx .cx-btn,.cx-ov .cx-btn{font-size:13px !important;padding:8px 15px !important;min-height:40px}
 .cx .cx-btn.s,.cx-ov .cx-btn.s{font-size:12.5px !important;padding:6px 12px !important}
 .cx .cx-tab{font-size:14px !important;padding:9px 12px !important;min-height:42px}
 .cx .cx-ch{font-size:12.5px !important;padding:6px 12px !important;min-height:40px}
 .cx input.cx-in,.cx select.cx-sel,.cx-ov input.cx-in,.cx-ov select.cx-sel{height:40px !important;min-height:40px}
 .cx .cx-seg button{font-size:14px !important;padding:10px !important;min-height:44px}
 .cx .cx-acts button{font-size:13.5px !important;padding:12px 8px !important}
 .cx .cx-qk button{font-size:12.5px !important;padding:6px 12px !important;min-height:40px}
 .cx .cx-chd .bk{width:40px;height:40px;padding:0 !important;font-size:20px !important}
 .cx .cx-cmp .sd,.cx .cx-cmp .nt{padding:0 !important;font-size:18px !important}.cx .cx-cmp .nt{width:44px;height:44px}
 .cx .cx-tbl th{white-space:normal !important}.cx .cx-tbl input.cx-in{width:100% !important;max-width:110px}
 .cx a[data-rm]{display:inline-block;padding:10px 6px !important;margin:-10px -4px -10px 0}
}`;
    document.head.appendChild(s);
  }
  function toast(t, bad) { const e = document.createElement('div'); e.className = 'cx-toast' + (bad ? ' bad' : ''); e.textContent = t; document.body.appendChild(e); setTimeout(() => e.remove(), bad ? 5200 : 2600); }
  async function call(fn, okMsg) { try { const r = await fn(); if (okMsg !== false && (okMsg || (r && r.message))) toast(okMsg || r.message); return r; } catch (e) { toast(e.message || 'Something went wrong', true); return null; } }
  function modal(html) {
    document.querySelectorAll('.cx-ov').forEach((n) => n.remove());
    const o = document.createElement('div'); o.className = 'cx-ov'; o.innerHTML = '<div class="cx-md">' + html + '</div>'; document.body.appendChild(o);
    o.addEventListener('mousedown', (e) => { if (e.target === o) o.remove(); }); return { el: o.firstChild, close: () => o.remove() };
  }
  const pill = (t, c) => '<span class="cx-pill ' + (c || '') + '">' + esc(t) + '</span>';
  const uPill = (u) => (u === 'normal' ? '' : pill(URG[u], u === 'urgent' ? 'r' : 'o'));
  const sPill = (v) => pill(v.source_label, v.source === 'whatsapp' ? 'g' : v.source === 'call' ? 'b' : 'p');

  /* ------------------------------ start ------------------------------ */
  async function start(host, opts) {
    css(); H = host; MODE = (opts && opts.mode) || 'team'; H.classList.add('cx');
    try { B.badge = await api.get('bd.cx_badge'); } catch (e) { H.innerHTML = '<div class="cx-empty">Could not load: ' + esc(e.message) + '</div>'; return; }
    if (MODE === 'team' && !B.badge.team) MODE = 'mine';
    const q = new URLSearchParams(location.search); const id = Number(q.get('case')) || 0;
    if (id) { await openCase(id); return; }
    { const t = q.get('tab'); if (t) S.tab = t; }   // complaints.html?tab=settings opens the team / desks screen (link from WhatsApp → Team & rules)
    shell();
  }
  function shell() {
    stopTimers(); C = null; document.body.classList.remove('cx-incase');
    if (MODE === 'mine') {
      H.innerHTML = '<div class="cx-top"><div class="cx-t1"><h1>My complaints</h1><div class="sub">The complaints you raised, and who is solving them</div>' + (B.badge.team ? '<a class="cx-btn gh s" href="complaints.html">Ticket board</a>' : '') + '<button class="cx-btn" id="cxRaiseM" type="button" style="margin-left:auto;flex:none" title="Any customer — just the phone number (also customers on our old phone)">⚠ Raise<span class="cx-lg"> a complaint</span></button></div></div><div class="cx-body" id="cxBody"></div>';
      $('#cxRaiseM', H).onclick = () => { if (!window.CXR) { toast('Refresh the page and try again.'); return; } CXR.open({ askCustomer: true, source: 'whatsapp', onDone: () => mine() }); };
      mine(); return;
    }
    const V2 = !!(window.CXM && CXM.ready); if (!V2 && !{ tickets: 1, board: 1, list: 1, report: 1, settings: 1, dash: 1 }[S.tab]) S.tab = 'tickets'; if (S.tab === 'today' || S.tab === 'board' || S.tab === 'list') S.tab = 'tickets';   // 🎫 the new ticket list replaced the old Today / Board / List screens (same data)
    const tabs = V2 ? [['tickets', '🎫 Tickets'], ['dash', '📊 Dashboard'], ['qc', 'Return check'], ['credit', 'Store credit'], ['report', 'Reports'], ['settings', 'Settings']] : [['tickets', '🎫 Tickets'], ['dash', '📊 Dashboard'], ['report', 'Reports'], ['settings', 'Settings']];
    H.innerHTML = '<div class="cx-top"><div class="cx-t1"><h1>🎫 Complaint tickets</h1><div class="sub">Every ticket line by line, day by day · 📍 Tracking and 🛠 Complaint</div><span id="cxLate"></span>' + (V2 ? '<button class="cx-btn gh" id="cxCall" type="button" aria-label="Log a call">☎<span class="cx-lg"> Log a call</span></button>' : '') + '<button class="cx-btn gh" id="cxTrk" type="button" aria-label="Track an order" title="Phone, order number or tracking number → live status from Shiprocket and the Shopify orders">🔎<span class="cx-lg"> Track</span></button><button class="cx-btn" id="cxNew" type="button" title="Any customer — just the phone number (also customers on our old phone)">⚠ Raise<span class="cx-lg"> a complaint</span></button></div><div class="cx-tabs">' + tabs.map((t) => '<button class="cx-tab" data-t="' + t[0] + '" type="button">' + t[1] + '</button>').join('') + '</div></div><div class="cx-body" id="cxBody"></div>';
    H.querySelectorAll('.cx-tab').forEach((b) => b.onclick = () => go(b.dataset.t));
    $('#cxNew', H).onclick = () => CXR.open({ askCustomer: true, source: 'call', onDone: (r) => { if (r && r.id) openCase(r.id); else go(S.tab); } });
    const cb = $('#cxCall', H); if (cb) cb.onclick = () => CXM.callSheet({});
    $('#cxTrk', H).onclick = () => trackSheet('');
    go(S.tab);
  }
  function stopTimers() { clearInterval(timer); clearInterval(thTimer); timer = thTimer = null; }
  function go(t) {
    S.tab = t; stopTimers(); H.querySelectorAll('.cx-tab').forEach((b) => b.classList.toggle('on', b.dataset.t === t));
    ({ tickets, board, list, report, settings, dash }[t] || (window.CXM && CXM.ready && CXM.tabs[t]) || tickets)();
    if (t === 'board') timer = setInterval(() => { if (!document.hidden && !document.querySelector('.cx-ov') && S.tab === 'board') board(true); }, 45000);
  }
  const body = () => $('#cxBody', H);

  /* ------------------------------ 🎫 tickets (the main screen) ------------------------------ */
  function tickets() {
    if (!window.CXT) { body().innerHTML = '<div class="cx-empty">Could not open the ticket list — refresh the page.</div>'; return; }
    const v = CXT.view(body(), { open: (id) => openCase(id) });
    timer = setInterval(() => { const a = document.activeElement; if (!document.hidden && !document.querySelector('.cx-ov') && S.tab === 'tickets' && !(a && a.closest && a.closest('#tkQ, select'))) v.reload(); }, 60000);
  }
  /* ------------------------------ board ------------------------------ */
  async function board(quiet) {
    if (!quiet) body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await call(() => api.get('bd.cx_board'), false); if (!d) return; B.b = d; drawBoard();
  }
  function drawBoard() {
    const d = B.b, f = S.f, k = d.kpi; const L = $('#cxLate', H); if (L) L.innerHTML = k.late ? pill(k.late + ' overdue', 'r') : '';
    const q = (f.q || '').toLowerCase();
    const pass = (c) => (f.who !== 'mine' || c.owner === d.me) && (!f.late || c.late) && (!f.src || c.source === f.src) && (!f.prob || c.problem === f.prob)
      && (!q || (c.name + ' ' + c.code + ' ' + c.phone + ' ' + c.order_name + ' ' + c.awb + ' ' + c.summary).toLowerCase().indexOf(q) > -1);
    const probs = [['', 'Problem ▾'], ['missing', 'Missing item'], ['leak', 'Leak / damaged'], ['notdispatched', 'Not dispatched'], ['notdelivered', 'Not delivered'], ['tracking', 'Tracking ID'], ['ndr', 'NDR'], ['rto', 'RTO'], ['wrong', 'Wrong item'], ['notliked', 'Not liked / return'], ['quality', 'Smell / quality'], ['refund', 'Refund'], ['staff', 'Staff'], ['other', 'Other']];
    const chip = (on, a, t) => '<button type="button" class="cx-ch' + (on ? ' on' : '') + '" data-a="' + a + '">' + t + '</button>';
    let h = '';
    if (d.no_team) h += '<div class="cx-warn">Nobody sits on the desks yet, so new complaints have no owner. ' + (d.mgr ? 'Open <b>Settings</b> and choose the people for each desk.' : 'Ask the boss to choose the people in Settings.') + '</div>';
    h += '<div class="cx-kpis">' + [['Open now', k.open, ''], ['Overdue', k.late, k.late ? 'red' : ''], ['New today', k.new_today, ''], ['Resolved today', k.resolved_today, ''], ['Avg time to fix', k.avg_fix, ''], ['Cost this month', inr(k.cost_month), '']].map((x) => '<div class="cx-k ' + x[2] + '"><small>' + x[0] + '</small><b>' + x[1] + '</b></div>').join('') + '</div>';
    h += '<div class="cx-fl">' + chip(f.who === 'all' && !f.late && !f.src, 'all', 'All') + chip(f.who === 'mine', 'mine', 'Mine') + chip(f.late, 'late', '⏰ Overdue') + chip(f.src === 'whatsapp', 's:whatsapp', '💬 WhatsApp') + chip(f.src === 'call', 's:call', '📞 Call') + chip(f.src === 'social', 's:social', '📸 Social') + chip(f.src === 'store', 's:store', '🏬 Store')
      + '<select class="cx-sel" id="cxProb">' + probs.map((p) => '<option value="' + p[0] + '"' + (f.prob === p[0] ? ' selected' : '') + '>' + p[1] + '</option>').join('') + '</select><input class="cx-in" id="cxQ" placeholder="🔎 Search name / phone / order / AWB" value="' + esc(f.q) + '" style="min-width:210px"></div>';
    h += '<div class="cx-cols">' + d.desks.map((dk) => {
      const cs = d.cases.filter((c) => c.desk === dk.k && pass(c));
      return '<div class="cx-col"><h3><span>' + dk.icon + ' ' + esc(dk.label) + '</span><em>' + cs.length + '</em></h3><div class="ab">' + esc(dk.about) + (dk.people.length ? ' · ' + esc(dk.people.join(', ')) : '') + '</div>' + (cs.length ? cs.map(card).join('') : '<div class="cx-empty">Nothing here</div>') + '</div>';
    }).join('') + '</div>';
    body().innerHTML = h;
    body().querySelectorAll('.cx-ch').forEach((b) => b.onclick = () => { const a = b.dataset.a; if (a === 'all') S.f = { who: 'all', late: false, src: '', prob: '', q: '' }; else if (a === 'mine') f.who = f.who === 'mine' ? 'all' : 'mine'; else if (a === 'late') f.late = !f.late; else if (a.indexOf('s:') === 0) f.src = f.src === a.slice(2) ? '' : a.slice(2); drawBoard(); });
    $('#cxProb', H).onchange = (e) => { f.prob = e.target.value; drawBoard(); };
    const qi = $('#cxQ', H); qi.oninput = () => { f.q = qi.value; const p = qi.selectionStart; clearTimeout(S.qt); S.qt = setTimeout(() => { drawBoard(); const n = $('#cxQ', H); n.focus(); try { n.setSelectionRange(p, p); } catch (e) { /* ok */ } }, 250); };
    body().querySelectorAll('.cx-card').forEach((c) => c.onclick = () => openCase(Number(c.dataset.id)));
  }
  function card(c) {
    const tag = [sPill(c)]; if (c.order_name) tag.push(pill('#' + String(c.order_name).replace(/^#/, '')));
    if (c.waiting) tag.push(pill('Waiting customer')); else if (c.status === 'open' && !c.first_reply) tag.push(pill('Not answered', 'r'));
    if (c.stage && c.stage !== 'new' && c.stage !== 'done' && c.stage_label) tag.push(pill(c.stage_icon + ' ' + c.stage_label, c.stage_late ? 'r' : 'b')); if (c.repeat) tag.push(pill('Repeat ×' + (c.repeat + 1), 'o')); if (c.status === 'reopened') tag.push(pill('Reopened', 'o')); if (c.refund_amt && c.desk === 'refund') tag.push(pill(inr(c.refund_amt) + (c.refund_ok ? '' : ' · needs OK'), c.refund_ok ? 'g' : 'o'));
    return '<div class="cx-card ' + c.urgency + '" data-id="' + c.id + '"><div class="r1"><span>' + c.code + '</span>' + uPill(c.urgency) + '<span class="tm' + (c.late ? ' late' : '') + '">' + (c.late ? '⏰ ' : '') + esc(c.time_label) + '</span></div><div class="nm">' + esc(c.name) + '</div><div class="tx">' + c.problem_icon + ' ' + esc(c.problem_label) + (c.summary && c.summary !== c.problem_label ? ' — ' + esc(c.summary.length > 60 ? c.summary.slice(0, 59) + '…' : c.summary) : '') + '</div><div class="r3">' + tag.join('') + (c.owner ? '<span class="cx-av" style="background:' + col(c.owner) + '" title="' + esc(c.owner_name) + '">' + ini(c.owner_name) + '</span>' : '<span class="cx-av" style="background:#aab4bb" title="Nobody yet">?</span>') + '</div></div>';
  }

  /* ------------------------------ list ------------------------------ */
  async function list() {
    body().innerHTML = '<div class="cx-fl"><select class="cx-sel" id="cxSt"><option value="all">All</option><option value="open">Open</option><option value="done">Solved</option></select><input class="cx-in" id="cxLq" placeholder="🔎 Search" style="min-width:240px"></div><div id="cxLs" class="cx-box"></div>';
    const st = $('#cxSt', H), q = $('#cxLq', H); st.value = S.status; q.value = S.q;
    const load = async () => { S.status = st.value; S.q = q.value; const d = await call(() => api.get('bd.cx_list', { status: S.status, q: S.q }), false); if (!d) return; $('#cxLs', H).innerHTML = d.cases.length ? d.cases.map(row).join('') : '<div class="cx-empty">No complaints found</div>'; $('#cxLs', H).querySelectorAll('.cx-row').forEach((r) => r.onclick = () => openCase(Number(r.dataset.id))); };
    st.onchange = load; q.oninput = () => { clearTimeout(S.lt); S.lt = setTimeout(load, 300); }; load();
  }
  function row(c) {
    return '<div class="cx-row" data-id="' + c.id + '"><div class="m"><b>' + c.code + ' · ' + esc(c.name) + '</b><small>' + c.problem_icon + ' ' + esc(c.problem_label) + ' · ' + esc(c.desk_label) + (c.owner_name ? ' · ' + esc(c.owner_name) : '') + ' · ' + esc(c.ago) + ' ago</small></div>' + uPill(c.urgency) + sPill(c) + pill(STAT[c.status] || c.status, c.status === 'open' || c.status === 'reopened' ? (c.late ? 'r' : 'b') : 'g') + '</div>';
  }

  /* ------------------------------ my complaints ------------------------------ */
  async function mine() {
    const d = await call(() => api.get('bd.cx_mine'), false); if (!d) return;
    body().innerHTML = d.cases.length ? d.cases.map((c) => {
      const step = c.status === 'resolved' || c.status === 'closed' ? 3 : c.first_reply ? 2 : 1;
      const lab = ['Raised', 'With ' + (c.owner_name || c.desk_label), 'Solved']; const sub = c.status === 'resolved' || c.status === 'closed' ? 'Solved' + (c.res_label ? ': ' + c.res_label : '') : 'On the ' + c.desk_label + ' desk' + (c.late ? ' — late' : '');
      return '<div class="cx-box cx-mine" data-id="' + c.id + '" style="cursor:pointer"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><b>' + c.code + ' · ' + esc(c.name) + '</b>' + uPill(c.urgency) + sPill(c) + '<span style="margin-left:auto;color:var(--mut);font-size:12px">' + esc(c.ago) + ' ago</span></div><div style="margin:4px 0 8px;color:#54656f">' + c.problem_icon + ' ' + esc(c.problem_label) + (c.summary && c.summary !== c.problem_label ? ' — ' + esc(c.summary) : '') + '</div><div style="display:flex;gap:6px">' + [1, 2, 3].map((i) => '<div style="flex:1"><div style="height:6px;border-radius:9px;background:' + (i <= step ? (step === 3 ? '#00a884' : '#f59e0b') : '#e9edef') + '"></div><small style="color:var(--mut)">' + esc(lab[i - 1]) + '</small></div>').join('') + '</div><div style="margin-top:6px;font-size:12.5px;color:#3b4a54">' + esc(sub) + '</div></div>';
    }).join('') : '<div class="cx-empty">You have not raised a complaint yet.<br>Tap <b>⚠ Raise</b> above — any customer, just the phone number (also customers on our old phone).</div>';
    body().querySelectorAll('.cx-mine').forEach((n) => n.onclick = () => openCase(Number(n.dataset.id)));
  }

  /* ------------------------------ the case ------------------------------ */
  let openSeq = 0;
  async function openCase(id) {
    stopTimers(); sugg = null; quick = null; T = null; const my = ++openSeq;
    const d = await call(() => api.get('bd.cx_case', { id }), false);
    if (my !== openSeq) return;   // another ticket was opened meanwhile — a slower answer must never replace it
    if (!d) { if (!H.innerHTML) shell(); return; }
    C = d; try { history.replaceState(null, '', location.pathname + '?case=' + id); } catch (e) { /* ok */ }
    full(true);
    drawCase(); if (C.can_work && C.case.phone) { await loadThread(true); if (my !== openSeq) return; if (C.case.status === 'open' || C.case.status === 'reopened') loadSugg(); }
    timer = setInterval(async () => { if (document.hidden || document.querySelector('.cx-ov')) return; const x = await api.get('bd.cx_case', { id }).catch(() => null); if (x && my === openSeq && C && C.case && C.case.id === id) { const keep = C.view; C = x; C.view = keep; drawLeft(); drawHead(); } }, 30000);
    thTimer = setInterval(() => { if (!document.hidden && C && C.can_work && C.case.phone) loadThread(false); }, 8000);
  }
  // 📱 a case on a phone takes the whole screen (the HRMS title and tabs step aside); the ‹ back button gives them back
  function full(on) { try { if (window.parent !== window) window.parent.postMessage({ wsFocus: !!on && window.innerWidth <= 900 }, location.origin); } catch (e) { /* not inside the hub */ } }
  function back() { full(false); stopTimers(); openSeq++; C = null; try { history.replaceState(null, '', location.pathname); } catch (e) { /* ok */ } if (!$('#cxBody', H)) shell(); else shell(); }
  function drawCase() {
    const v = C.case; C.view = C.view || 'l';
    H.innerHTML = '<div class="cx-case v-' + C.view + '" id="cxCase"><div class="cx-chd" id="cxHd"></div>' + (C.can_work && v.phone ? '<div class="cx-seg"><button type="button" data-v="l" class="' + (C.view === 'l' ? 'on' : '') + '">Details</button><button type="button" data-v="r" class="' + (C.view === 'r' ? 'on' : '') + '">Chat</button></div>' : '')
      + '<div class="cx-cb"><div class="cx-l" id="cxL"></div><div class="cx-r" id="cxR"></div></div></div>';
    H.querySelectorAll('.cx-seg button').forEach((b) => b.onclick = () => { C.view = b.dataset.v; $('#cxCase', H).className = 'cx-case v-' + C.view; H.querySelectorAll('.cx-seg button').forEach((x) => x.classList.toggle('on', x === b)); const m = $('#cxMsgs', H); if (m) m.scrollTop = m.scrollHeight; });
    drawHead(); drawLeft(); drawRight();
  }
  const isOpen = () => C && (C.case.status === 'open' || C.case.status === 'reopened');
  function drawHead() {
    const v = C.case; const hd = $('#cxHd', H); if (!hd) return;
    hd.innerHTML = '<button class="bk" type="button" id="cxBk">‹</button><h2>' + v.code + ' · ' + esc(v.name) + '</h2>' + uPill(v.urgency) + (isOpen() && v.time_label ? pill('⏰ ' + v.time_label, v.late ? 'r' : 'g') : pill(STAT[v.status] || v.status, 'g')) + sPill(v)
      + '<span style="flex:1"></span>' + (C.can_work ? (isOpen() ? ((!C.is_owner ? '<button class="cx-btn gh s" type="button" id="cxTake">' + (v.owner ? 'Take it' : 'Take it') + '</button>' : '') + '<button class="cx-btn gh s" type="button" id="cxHand">Hand to…</button><button class="cx-btn gh s" type="button" id="cxProm" title="The fix is known but happens on a later day">⏸ Promise a date</button><button class="cx-btn s" type="button" id="cxRes">✔ Mark resolved</button>') : '<button class="cx-btn gh s" type="button" id="cxReo">Reopen</button>') : '');
    $('#cxBk', H).onclick = back; const q = (id, fn) => { const e = $(id, H); if (e) e.onclick = fn; }; q('#cxTake', takeIt); q('#cxHand', handSheet); q('#cxProm', promiseSheet); q('#cxRes', resolveSheet); q('#cxReo', reopenSheet);
  }
  async function refresh() { if (!C) return; const id = C.case.id, my = openSeq; const x = await api.get('bd.cx_case', { id }).catch(() => null); if (x && my === openSeq && C && C.case.id === id) { const keep = C.view; C = x; C.view = keep; drawHead(); drawLeft(); drawRight(true); } }
  async function takeIt() { const r = await call(() => api.post('bd.cx_assign', { id: C.case.id, emp_id: C.me })); if (r) { await refresh(); loadThread(true); } }

  function drawLeft() {
    const v = C.case, w = C.can_work, el = $('#cxL', H); if (!el) return; const open = isOpen();
    const nextI = C.steps.findIndex((s) => !s.done);
    const ag = v.ai_guess; let h = (ag && w ? '<div class="cx-box" style="background:#f5f1ff;border-color:#e4dcfb"><div style="font-size:13.5px;line-height:1.45;color:#3f2a7a">🤖 Claude read the chat as <b>' + esc(ag.icon || '') + ' ' + esc(ag.label) + '</b>' + (ag.new ? ' (new type)' : '') + ', not <b>' + esc(v.problem_label) + '</b>.' + (ag.why ? '<div style="color:#6b5aa6;margin-top:2px">' + esc(ag.why) + '</div>' : '') + '</div><div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap"><button class="cx-btn s" type="button" id="cxAgYes">Change to ' + esc(ag.label) + '</button><button class="cx-btn gh s" type="button" id="cxAgNo">Keep ' + esc(v.problem_label) + '</button></div></div>' : '')
      + '<div class="cx-box"><h4 style="display:flex;align-items:center;justify-content:space-between">Problem' + (w ? '<button class="cx-btn gh s" type="button" id="cxProb" style="text-transform:none;letter-spacing:0">✏️ Change</button>' : '') + '</h4><div style="font-size:16px;font-weight:800">' + v.problem_icon + ' ' + esc(v.problem_label) + '</div><div style="color:#54656f;margin-top:2px">' + esc(v.summary || '') + '</div>' + (v.promise_at && isOpen() ? (v.promise_state === 'missed' ? '<div class="cx-warn" style="margin-top:8px">❌ Promise missed — “' + esc(v.promise_what) + '” was promised for ' + esc(dm(v.promise_at)) + '.' + (w ? ' <a href="#" id="cxPromE">Promise a new date</a>' : '') + '</div>' : v.promise_state === '' ? '<div style="margin-top:8px;background:#eef2ff;color:#3730a3;border-radius:10px;padding:8px 10px;font-weight:700">⏸ Pending until ' + esc(dm(v.promise_at)) + ': ' + esc(v.promise_what) + '<small style="display:block;font-weight:500;color:#4c4f8f">Not late before that day ends. On the day the system checks it by itself.' + (w ? ' <a href="#" id="cxPromE">Change</a> · <a href="#" id="cxPromX">Remove</a>' : '') + '</small></div>' : '') : '') + '<div style="margin-top:6px;color:var(--mut);font-size:12.5px">Raised by <b>' + esc(v.raised_name || 'Admin') + '</b> · ' + esc(v.ago) + ' ago' + (v.repeat ? ' · <b style="color:#9a6200">Repeat complainer (×' + (v.repeat + 1) + ' in 90 days)</b>' : '') + '</div><div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">' + pill('📍 ' + v.desk_label) + pill(v.owner_name ? '👤 ' + v.owner_name : 'No owner yet', v.owner_name ? '' : 'r') + (v.waiting ? pill('Waiting for customer') : '') + (v.quiet ? pill('📱 Old phone — no HRMS messages', 'r') : '') + (w ? '<button class="cx-btn gh s" type="button" id="cxEd">Edit</button>' : '') + '</div></div>';
    if (window.CXM && CXM.ready) h += CXM.top(C);
    if (C.progress && C.progress.length && v.phone) h += '<div class="cx-box"><h4>📨 Progress messages to the customer</h4><div style="color:var(--mut);font-size:12.5px;margin-bottom:6px">Short updates, each goes once. Many go by themselves (pickup / replacement saved, tracking found, store credit). Tap a step that is done to tell the customer now.</div><div style="display:flex;flex-wrap:wrap;gap:6px">'
      + C.progress.map((s) => s.state === 'sent' ? '<span class="cx-btn gh s" style="cursor:default;background:#e7f8ee;border-color:#bfe8cf;color:#13703a">✓ ' + esc(s.icon + ' ' + s.label) + '<small style="margin-left:6px;font-weight:600">' + esc(String(s.at).slice(5, 16)) + '</small></span>' + (s.again && w && open ? '<button type="button" class="cx-btn gh s" data-pstep="' + esc(s.k) + '" data-again="1" title="The customer did not get it? Send it again">↻ Send again</button>' : '')
        : s.state ? '<span class="cx-btn gh s" style="cursor:default;background:#fff8e6;color:#8a5a00">⏳ ' + esc(s.icon + ' ' + s.label) + '<small style="margin-left:6px">' + (s.state === 'wait:night' ? 'at 8 AM' : 'waits for Meta') + '</small></span>'
        : (w && open ? '<button type="button" class="cx-btn gh s" data-pstep="' + esc(s.k) + '">' + esc(s.icon + ' ' + s.label) + '</button>' : '<span class="cx-btn gh s" style="cursor:default;opacity:.6">' + esc(s.icon + ' ' + s.label) + '</span>')).join('') + '</div></div>';
    const ord = C.orders && C.orders[0], p = C.parcel;
    h += '<div class="cx-box"><h4>Order</h4>' + (v.order_name || ord ? '<div class="cx-ord"><b>#' + esc(String(v.order_name || ord.name).replace(/^#/, '')) + '</b>' + (ord && String(ord.name) === String(v.order_name) ? ' · ₹' + Number(ord.total).toLocaleString('en-IN') : '') + '<small>' + (ord && String(ord.name) === String(v.order_name) ? esc(ord.at) + ' · ' + esc(ord.pay) + ' · ' + esc(ord.ship) : '') + (v.product ? ' · ' + esc(v.product) : '') + (v.awb ? ' · AWB ' + esc(v.awb) : '') + (v.courier ? ' · ' + esc(v.courier) : '') + '</small></div>' : '<div class="cx-ord" style="color:var(--mut)">No order linked' + (w ? ' — press Edit to add the order number.' : '.') + '</div>')
      + (p ? '<div class="cx-ord" style="margin-top:6px">🚚 <b>' + esc(p.courier || 'Courier') + '</b> · ' + esc(p.status || '') + (p.last ? '<small>' + esc(p.last) + '</small>' : '') + '</div>' : '') + '</div>';
    h += '<div class="cx-box"><h4>Playbook · ' + esc(v.problem_label) + '</h4>' + C.steps.map((s, i) => '<div class="cx-pb' + (s.done ? ' done' : i === nextI ? ' next' : '') + '" data-i="' + s.i + '"><i>' + (s.done ? '✓' : (i + 1)) + '</i><div><b>' + esc(s.text) + '</b></div></div>').join('') + '</div>';
    if (w && open) {
      h += '<div class="cx-box"><h4>Move it to a desk by hand</h4><div class="cx-acts"><button type="button" style="background:#e0ecff;color:#1d4ed8" data-d="exchange">🔁 Exchange</button><button type="button" style="background:#fff1d6;color:#9a6200" data-d="resend">📦 Resend parcel</button><button type="button" style="background:#ede7ff;color:#5b34b5" data-d="pickup">🚚 Reverse pickup</button><button type="button" style="background:#fde4e4;color:#b3261e" data-d="refund">💰 Refund</button></div>' + (v.refund_amt ? '<div style="margin-top:8px">' + pill('Refund ' + inr(v.refund_amt) + (v.refund_ok ? ' · approved' : ' · waiting for the boss'), v.refund_ok ? 'g' : 'o') + (!v.refund_ok && C.mgr ? ' <button class="cx-btn s" type="button" id="cxRok">Approve</button> <button class="cx-btn red s" type="button" id="cxRno">Reject</button>' : '') + '</div>' : '') + '</div>';
    }
    if (C.links.length || (w && open)) {
      h += '<div class="cx-box"><h4>Parcels &amp; pickups</h4>' + (C.links.length ? C.links.map((l) => '<div class="cx-ord" style="margin-bottom:6px"><b>' + ({ replacement: '📦 Replacement', resend: '📦 Resend', pickup: '🚚 Reverse pickup' }[l.kind]) + '</b> · ' + esc(LSTAT[l.status] || l.status) + '<small>' + (l.awb ? 'AWB ' + esc(l.awb) + ' · ' : '') + esc(l.courier) + (l.plan_date ? ' · ' + dm(l.plan_date) : '') + (l.note ? ' · ' + esc(l.note) : '') + (l.live ? ' · ' + esc(l.live.status) : '') + '</small>' + (w ? '<select class="cx-sel" data-l="' + l.id + '" style="margin-top:5px;font-size:12px"><option value="">Change status…</option>' + (l.kind === 'pickup' ? ['booked', 'picked', 'received', 'qc_ok'] : ['shipped', 'delivered']).concat(['cancelled']).map((s) => '<option value="' + s + '">' + LSTAT[s] + '</option>').join('') + '</select>' : '') + '</div>').join('') : '<div style="color:var(--mut)">None yet.</div>') + '</div>';
    }
    if (!open && v.res_label) h += '<div class="cx-box"><h4>How it was solved</h4><b>' + esc(v.res_label) + '</b>' + (v.cost ? ' · cost ' + inr(v.cost) : '') + (v.happy === 1 ? ' · 😊 customer happy' : v.happy === 0 ? ' · 😞 customer not happy' : '') + (w && v.happy === null ? '<div style="margin-top:8px"><button class="cx-btn gh s" type="button" data-hp="1">😊 Happy</button> <button class="cx-btn gh s" type="button" data-hp="0">😞 Not happy</button></div>' : '') + '</div>';
    if (window.CXM && CXM.ready) h += CXM.mid(C) + CXM.timeline(C);
    else h += '<div class="cx-box"><h4>Timeline</h4>' + C.events.slice().reverse().map((e) => '<div class="cx-ev"><small>' + dm(e.at) + ' ' + hm(e.at) + '</small>' + esc(e.text) + '</div>').join('') + '</div>';
    el.innerHTML = h;
    el.querySelectorAll('[data-pstep]').forEach((b) => b.onclick = () => { const st = C.progress.find((x) => x.k === b.dataset.pstep); if (!st) return; const ag = b.dataset.again === '1';
      const m = modal('<h3>' + (ag ? '↻ Send again: ' : '') + esc(st.icon + ' ' + st.label) + '</h3><div style="color:var(--mut);margin-bottom:8px">' + (ag ? 'The same message goes to the customer again now (or at 8 AM if it is night) — use it when they did not get it or the tracking number changed.' : 'This short message goes to the customer on WhatsApp now (or at 8 AM if it is night). It is sent only once.') + '</div><label>' + esc(st.ask) + '</label><input class="cx-in" id="psD" placeholder="' + esc(st.ask) + '" value="' + esc(ag && st.k === 'tracking' && C.case.awb ? C.case.awb + (C.case.courier ? ' (' + C.case.courier + ')' : '') : '') + '"><div class="ac"><button class="cx-btn gh" type="button" id="psX">Cancel</button><button class="cx-btn" type="button" id="psGo">Send to customer</button></div>');
      $('#psX', m.el).onclick = m.close; $('#psGo', m.el).onclick = async () => { const r = await call(() => api.post('bd.cx_step', { id: C.case.id, step: st.k, detail: $('#psD', m.el).value, again: ag ? 1 : 0 })); if (r) { m.close(); if (r.progress) C.progress = r.progress; refresh(); } }; }); if (window.CXM && CXM.ready) CXM.bind(el);
    { const q = (id, fn) => { const e = $(id, el); if (e) e.onclick = fn; }; q('#cxPromE', (e) => { e.preventDefault(); promiseSheet(); }); q('#cxPromX', async (e) => { e.preventDefault(); if (!confirm('Remove the promised date? The ticket counts its time again.')) return; const r = await call(() => api.post('bd.cx_promise', { id: C.case.id, clear: 1 })); if (r) refresh(); }); q('#cxProb', probSheet); if (v.ai_guess) { q('#cxAgYes', () => setProblem(v.ai_guess.new ? { problem: 'new', new_label: v.ai_guess.label, new_icon: v.ai_guess.icon, new_dept: v.ai_guess.dept, new_serious: v.ai_guess.serious ? 1 : 0 } : { problem: v.ai_guess.problem })); q('#cxAgNo', () => setProblem({ problem: 'keep' })); } }
    const q = (s, fn) => el.querySelectorAll(s).forEach(fn);
    q('.cx-pb', (n) => n.onclick = async () => { if (!w) return; const i = Number(n.dataset.i); const s = C.steps.find((x) => x.i === i); const r = await call(() => api.post('bd.cx_step', { id: v.id, i, done: s.done ? 0 : 1 }), false); if (r) refresh(); });
    q('[data-d]', (n) => n.onclick = () => deskSheet(n.dataset.d)); q('[data-hp]', (n) => n.onclick = async () => { await call(() => api.post('bd.cx_happy', { id: v.id, happy: Number(n.dataset.hp) }), 'Saved.'); refresh(); });
    q('select[data-l]', (n) => n.onchange = async () => { if (!n.value) return; await call(() => api.post('bd.cx_link_status', { id: v.id, link_id: Number(n.dataset.l), status: n.value }), 'Updated.'); refresh(); });
    const ed = $('#cxEd', el); if (ed) ed.onclick = editSheet;
    const ro = $('#cxRok', el); if (ro) ro.onclick = async () => { await call(() => api.post('bd.cx_refund_ok', { id: v.id })); refresh(); }; const rn = $('#cxRno', el); if (rn) rn.onclick = async () => { await call(() => api.post('bd.cx_refund_ok', { id: v.id, reject: 1 })); refresh(); };
  }

  /* ------------------------------ sheets ------------------------------ */
  async function deskSheet(kind) {
    const v = C.case; const T2 = { exchange: ['🔁 Exchange', 'exchange', 'Replacement order / what is being swapped'], resend: ['📦 Resend parcel', 'resend', 'Why it is being sent again'], pickup: ['🚚 Reverse pickup', 'pickup', 'Pickup details'], refund: ['💰 Refund', 'refund', 'Why the refund'] }[kind];
    const m = modal('<h3>' + T2[0] + '</h3><div style="color:var(--mut);font-size:13px">It moves to the <b>' + esc(({ exchange: 'Exchange', resend: 'Resend parcel', pickup: 'Reverse pickup', refund: 'Refund' })[kind]) + '</b> desk and the person there is told.</div>'
      + (kind === 'refund' ? '<label>Refund amount (₹)</label><input class="cx-in" id="dsAmt" type="number" inputmode="numeric" min="1" placeholder="e.g. 1299">' + '<div style="font-size:12px;color:var(--mut);margin-top:4px">Above ' + inr(C.refund_limit) + ' the boss must approve it.</div>' : '')
      + (kind === 'pickup' ? '<label>Pickup date</label><input class="cx-in" id="dsDate" type="date"><label>Courier</label><input class="cx-in" id="dsCour" placeholder="Delhivery / Shiprocket…"><label>Tracking ID (AWB) if booked</label><input class="cx-in" id="dsAwb">' : '')
      + (kind === 'exchange' || kind === 'resend' ? '<label>Replacement / new parcel tracking ID (if already shipped)</label><input class="cx-in" id="dsAwb"><label>Courier</label><input class="cx-in" id="dsCour">' : '')
      + '<label>Note for the next person</label><textarea id="dsNote" rows="2" placeholder="' + esc(T2[2]) + '"></textarea><div class="ac"><button class="cx-btn gh" type="button" id="dsNo">Cancel</button><button class="cx-btn" type="button" id="dsOk">Do it</button></div>');
    $('#dsNo', m.el).onclick = m.close;
    $('#dsOk', m.el).onclick = async () => {
      const g = (id) => { const e = $(id, m.el); return e ? e.value.trim() : ''; }; const note = g('#dsNote'); $('#dsOk', m.el).disabled = true;
      if (kind === 'refund') { const amt = Number(g('#dsAmt')); if (!(amt > 0)) { toast('Write the refund amount', true); $('#dsOk', m.el).disabled = false; return; } const r = await call(() => api.post('bd.cx_refund', { id: v.id, amount: amt })); if (!r) { $('#dsOk', m.el).disabled = false; return; } }
      if (v.desk !== kind) { const r = await call(() => api.post('bd.cx_move', { id: v.id, desk: kind, note }), false); if (!r) { $('#dsOk', m.el).disabled = false; return; } } else if (note) await call(() => api.post('bd.cx_note', { id: v.id, text: note }), false);
      const awb = g('#dsAwb'), cour = g('#dsCour'), date = g('#dsDate');
      if (kind === 'pickup' || awb) await call(() => api.post('bd.cx_link_save', { id: v.id, kind: kind === 'pickup' ? 'pickup' : kind === 'resend' ? 'resend' : 'replacement', awb, courier: cour, plan_date: date, note }), false);
      m.close(); toast('Done — moved to the ' + kind + ' desk.'); await refresh();
    };
  }
  // ✏️ change the problem of the ticket (department, Serious / Basic and the steps follow); a new type when none fits
  async function setProblem(body) { const r = await call(() => api.post('bd.cx_set_problem', Object.assign({ id: C.case.id }, body))); if (r) { toast(r.message || 'Saved'); refresh(); } return r; }
  async function probSheet() {
    const d = await call(() => api.get('bd.cx_problems'), false); if (!d) return; const v = C.case;
    const m = modal('<h3>✏️ Change the problem</h3><label>Problem</label><select class="cx-sel" id="pbK">' + d.problems.map((p) => '<option value="' + esc(p.k) + '"' + (p.k === v.problem ? ' selected' : '') + '>' + esc(p.icon + ' ' + p.label) + (p.dept === 'tracking' ? ' · Tracking' : '') + (p.serious ? ' · Serious' : '') + '</option>').join('') + '<option value="new">🆕 New type…</option></select>'
      + '<div id="pbNew" style="display:none"><label>Name of the new type</label><input class="cx-sel" id="pbL" maxlength="34" placeholder="e.g. Missing gift"><label>Department</label><select class="cx-sel" id="pbD"><option value="complaint">🛠 Complaint</option><option value="tracking">📍 Tracking</option></select><label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" id="pbS"> Serious (reply in 15 min)</label></div>'
      + '<div style="color:var(--mut);font-size:12.5px;margin-top:8px">The department, Serious / Basic and the steps change with it. The note is kept in the timeline.</div><div class="ac"><button class="cx-btn gh" type="button" id="pbNo">Cancel</button><button class="cx-btn" type="button" id="pbOk">Save</button></div>');
    const sel = $('#pbK', m.el); sel.onchange = () => { $('#pbNew', m.el).style.display = sel.value === 'new' ? '' : 'none'; };
    $('#pbNo', m.el).onclick = m.close;
    $('#pbOk', m.el).onclick = async () => { const k = sel.value; const body = k === 'new' ? { problem: 'new', new_label: $('#pbL', m.el).value.trim(), new_dept: $('#pbD', m.el).value, new_serious: $('#pbS', m.el).checked ? 1 : 0 } : { problem: k };
      if (k === 'new' && !body.new_label) { toast('Write the name of the new type'); return; } if (await setProblem(body)) m.close(); };
  }
  // ⏸ "Promise a date": the fix is known but happens later (bd.cx_promise). The ticket waits (not late) until that day ends; the system checks it by itself.
  function promiseSheet() {
    const v = C.case, t = new Date(); t.setDate(t.getDate() + 1); const tm = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
    const sug = { notdispatched: 'your parcel will be dispatched', tracking: 'you will get your tracking number', notdelivered: 'your parcel will be delivered', ndr: 'the courier will deliver again', missing: 'the missing item will be sent', wrong: 'the pickup will be done', leak: 'the pickup will be done', refund: 'your refund will be done' }[v.problem] || '';
    const m = modal('<h3>⏸ Promise a date</h3><div style="color:var(--mut);margin-bottom:8px">The fix is decided but happens on a later day. The ticket waits in <b>Pending</b> (not late) until that day ends. On the day: tracking found / ticket solved → kept; nothing happened → <b>Promise missed</b>, red, and you and the boss are told.</div>'
      + '<label>Which day?</label><input class="cx-in" type="date" id="prD" value="' + esc(v.promise_state === '' && v.promise_at ? v.promise_at : tm) + '" min="' + esc(localDay()) + '"><label>What will happen?</label><input class="cx-in" id="prW" maxlength="200" placeholder="e.g. your parcel will be dispatched" value="' + esc(v.promise_what || sug) + '">'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" id="prT"' + (v.phone ? ' checked' : ' disabled') + '> Tell the customer on WhatsApp (“… on 8 Nov. We will update you here.”)</label>'
      + '<div class="ac"><button class="cx-btn gh" type="button" id="prX">Cancel</button><button class="cx-btn" type="button" id="prGo">Save promise</button></div>');
    $('#prX', m.el).onclick = m.close;
    $('#prGo', m.el).onclick = async () => { const r = await call(() => api.post('bd.cx_promise', { id: v.id, date: $('#prD', m.el).value, what: $('#prW', m.el).value, tell: $('#prT', m.el).checked ? 1 : 0 })); if (r) { m.close(); refresh(); } };
  }
  async function handSheet() {
    const s = await call(() => api.get('bd.cx_settings'), false); if (!s) return; const ids = new Set(); s.desks.forEach((d) => d.emps.forEach((e) => ids.add(e))); s.boss.forEach((e) => ids.add(e));
    const ppl = s.employees.filter((e) => ids.has(e.id));
    const m = modal('<h3>Hand to…</h3>' + (ppl.length ? '<label>Person</label><select class="cx-sel" id="hdP">' + ppl.map((e) => '<option value="' + e.id + '"' + (e.id === C.case.owner ? ' selected' : '') + '>' + esc(e.name) + '</option>').join('') + '</select>' : '<div class="cx-warn">Nobody is on the Complaint team yet. Choose people in Settings.</div>') + '<div class="ac"><button class="cx-btn gh" type="button" id="hdNo">Cancel</button>' + (ppl.length ? '<button class="cx-btn" type="button" id="hdOk">Give</button>' : '') + '</div>');
    $('#hdNo', m.el).onclick = m.close; const ok = $('#hdOk', m.el); if (ok) ok.onclick = async () => { const r = await call(() => api.post('bd.cx_assign', { id: C.case.id, emp_id: Number($('#hdP', m.el).value) })); if (r) { m.close(); refresh(); loadThread(true); } };
  }
  function resolveSheet() {
    const v = C.case; const canHappy = v.phone && T && T.window_open && !T.optout;
    const m = modal('<h3>✔ Mark resolved</h3><label>How was it solved?</label><select class="cx-sel" id="rsHow">' + Object.keys(C.resolutions).map((k) => '<option value="' + k + '">' + esc(C.resolutions[k]) + '</option>').join('') + '</select>'
      + (window.CXM && CXM.ready ? CXM.causeSelect(v) : '') + '<label>Cost to us (replacement parcel, courier…) ₹</label><input class="cx-in" id="rsCost" type="number" inputmode="numeric" min="0" placeholder="0"><label>Note</label><textarea id="rsNote" rows="2" placeholder="What was done"></textarea>'
      + (v.refund_amt ? '<div class="cx-warn" style="margin-top:10px">Refund ' + inr(v.refund_amt) + (v.refund_ok ? ' is approved and counted in the cost.' : ' is waiting for the boss.') + '</div>' : '')
      + '<label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0;font-size:14px;color:#111b21;font-weight:600"><input type="checkbox" id="rsHp"' + (canHappy ? ' checked' : ' disabled') + '> Ask the customer “Are you happy?”' + (canHappy ? '' : ' <small style="color:var(--mut)">(chat window closed)</small>') + '</label><div class="ac"><button class="cx-btn gh" type="button" id="rsNo">Cancel</button><button class="cx-btn" type="button" id="rsOk">Mark resolved</button></div>');
    if (v.refund_amt && v.refund_ok) $('#rsHow', m.el).value = 'refund'; $('#rsNo', m.el).onclick = m.close;
    $('#rsOk', m.el).onclick = async () => { const b = $('#rsOk', m.el); b.disabled = true; const r = await call(() => api.post('bd.cx_resolve', { id: v.id, resolution: $('#rsHow', m.el).value, cause: ($('#rsCause', m.el) || {}).value || '', cost: Number($('#rsCost', m.el).value) || 0, note: $('#rsNote', m.el).value.trim(), send_happy: $('#rsHp', m.el).checked ? 1 : 0 })); if (r) { m.close(); await refresh(); loadThread(true); } else b.disabled = false; };
  }
  function reopenSheet() {
    const m = modal('<h3>Reopen this complaint</h3><label>Why?</label><textarea id="roWhy" rows="2" placeholder="e.g. customer wrote again, problem is back"></textarea><div class="ac"><button class="cx-btn gh" type="button" id="roNo">Cancel</button><button class="cx-btn" type="button" id="roOk">Reopen</button></div>');
    $('#roNo', m.el).onclick = m.close; $('#roOk', m.el).onclick = async () => { const r = await call(() => api.post('bd.cx_reopen', { id: C.case.id, why: $('#roWhy', m.el).value.trim() })); if (r) { m.close(); await refresh(); loadThread(true); } };
  }
  function editSheet() {
    const v = C.case;
    const m = modal('<h3>Edit details</h3><label>Order number</label><input class="cx-in" id="edO" value="' + esc(v.order_name) + '"><label>Product</label><input class="cx-in" id="edP" value="' + esc(v.product) + '" placeholder="e.g. Oud Royale 100ml"><label>Tracking ID (AWB)</label><input class="cx-in" id="edA" value="' + esc(v.awb) + '"><label>Courier</label><input class="cx-in" id="edC" value="' + esc(v.courier) + '"><label>Urgency</label><select class="cx-sel" id="edU">' + Object.keys(URG).map((k) => '<option value="' + k + '"' + (v.urgency === k ? ' selected' : '') + '>' + URG[k] + '</option>').join('') + '</select><div class="ac"><button class="cx-btn gh" type="button" id="edNo">Cancel</button><button class="cx-btn" type="button" id="edOk">Save</button></div>');
    $('#edNo', m.el).onclick = m.close; $('#edOk', m.el).onclick = async () => { const r = await call(() => api.post('bd.cx_edit', { id: v.id, order_name: $('#edO', m.el).value, product: $('#edP', m.el).value, awb: $('#edA', m.el).value, courier: $('#edC', m.el).value, urgency: $('#edU', m.el).value })); if (r) { m.close(); refresh(); } };
  }

  /* ------------------------------ the chat ------------------------------ */
  function drawRight(soft) {
    const el = $('#cxR', H); if (!el) return; const v = C.case;
    if (!C.can_work) { el.innerHTML = '<div class="cx-noc">Only the Complaint team chats with the customer.<br>You can follow what is happening on the left.</div>'; return; }
    if (!v.phone) { el.innerHTML = '<div class="cx-noc">There is no WhatsApp chat for this complaint (it came from a ' + esc(v.source_label.toLowerCase()) + ').<br>Call the customer, then write what happened as a note on the left.<div style="margin-top:10px"><button class="cx-btn gh s" type="button" id="cxAddN">＋ Add a note</button></div></div>'; $('#cxAddN', el).onclick = noteSheet; return; }
    if (T && T.chat === false) { noChat(el); return; }
    // 💬 ONE chat box: the same as the sales chat (🎤 voice notes, 📎 photos & videos, "/" quick replies, ✨ pre-made messages) — the ticket only adds the private note
    if (soft && $('#cxFr', el)) return;
    let isAdm = false; try { isAdm = /admin/i.test(String((JSON.parse(localStorage.getItem('hrms_user') || '{}') || {}).role || '')); } catch (e) { }
    if (!window.CX_OLD_BOX && !C.embFail && isOpen() && (C.is_owner || isAdm)) {   // the full chat box only where it can send: an OPEN ticket, its owner (or Admin) — else the conversation below (read, notes, "Take it")
      const src = 'my_chats.html?embed=1&cx=' + v.id + '&phone=' + encodeURIComponent(String(v.phone).replace(/\D/g, '').slice(-10));
      el.innerHTML = '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 10px;border-bottom:1px solid var(--line,#e6e8ef);background:#fff"><button class="cx-btn gh s" type="button" id="cxNtB">🔒 Private note</button><span style="font-size:12px;color:var(--mut)">' + (C.is_owner || C.mgr ? '🎤 voice · 📎 photo / video · ✨ Replies (Claude + pre-made messages: pick, edit, send) · / quick replies. The ticket number goes with your first reply.' : 'Press <b>Take it</b> (top) to reply to the customer.') + '</span></div>'
        + '<iframe id="cxFr" title="WhatsApp chat" src="' + esc(src) + '" style="flex:1;width:100%;min-height:440px;border:0;display:block;background:#efeae2" allow="microphone; clipboard-write"></iframe>';
      el.style.display = 'flex'; el.style.flexDirection = 'column'; $('#cxNtB', el).onclick = noteSheet;
      // never a blank side: if the chat did not open inside (no permission, no chat, slow), the ticket's own conversation comes back
      const caseId = v.id; setTimeout(() => { const fr = $('#cxFr', H); if (!fr || !C || C.case.id !== caseId) return; let ok = false; try { ok = !!fr.contentDocument.querySelector('.wx-chat'); } catch (e) { ok = true; } if (!ok) { C.embFail = true; el.style.display = ''; drawRight(false); loadThread(true); } }, 7000);
      return;
    }
    el.style.display = '';
    if (soft && $('#cxMsgs', el)) return;
    el.innerHTML = '<div class="cx-ph"><div class="av">' + ini(v.name) + '</div><div style="flex:1;min-width:0"><b>' + esc(v.name) + '</b><small id="cxWith">+91 ' + esc(v.phone) + '</small></div></div><div id="cxWinBar"></div><div class="cx-msgs" id="cxMsgs"><div class="cx-s">Loading…</div></div><div id="cxSg"></div><div class="cx-qk" id="cxQk"></div><div class="cx-cmp" id="cxCmp"><button class="nt" type="button" id="cxNt" title="Private note (the customer never sees it)">🔒</button><textarea id="cxTx" rows="1" placeholder="Message"></textarea><button class="sd" type="button" id="cxSd">➤</button></div>';
    const tx = $('#cxTx', el), cmp = $('#cxCmp', el); let note = false;
    tx.oninput = () => { tx.style.height = 'auto'; tx.style.height = Math.min(120, tx.scrollHeight) + 'px'; };
    $('#cxNt', el).onclick = () => { note = !note; cmp.classList.toggle('note', note); tx.placeholder = note ? 'Private note — only the team sees it' : 'Message'; };
    const send = async () => {
      const t = tx.value.trim(); if (!t) return; const b = $('#cxSd', el); b.disabled = true;
      const r = note ? await call(() => api.post('bd.cx_note', { id: v.id, text: t }), false) : await call(() => api.post('bd.cx_send', { id: v.id, text: t }), false);
      b.disabled = false; if (r) { tx.value = ''; tx.style.height = 'auto'; sugg = null; drawSg(); if (note) { note = false; cmp.classList.remove('note'); tx.placeholder = 'Message'; } await refresh(); loadThread(true); }
    };
    $('#cxSd', el).onclick = send; tx.onkeydown = (e) => { if (e.key === 'Enter' && !e.shiftKey && window.innerWidth > 900) { e.preventDefault(); send(); } };
    loadQuick();
  }
  async function loadQuick() { if (!quick) { const d = await api.get('bd.cx_quick', { id: C.case.id }).catch(() => null); quick = d ? d.quick : []; } const q = $('#cxQk', H); if (!q) return; q.innerHTML = '<span style="flex:none;align-self:center">⚡</span>' + quick.map((x, i) => '<button type="button" data-i="' + i + '">' + esc(x.title) + '</button>').join(''); q.querySelectorAll('button').forEach((b) => b.onclick = () => { const t = $('#cxTx', H); t.value = quick[Number(b.dataset.i)].text; t.focus(); t.oninput(); }); }
  async function loadSugg() { const d = await api.get('bd.cx_suggest', { id: C.case.id }).catch(() => null); sugg = d && d.text ? d : null; drawSg(); }
  function drawSg() {
    const el = $('#cxSg', H); if (!el) return; if (!sugg || !isOpen() || (T && (!T.window_open || !T.can_chat))) { el.innerHTML = ''; return; }
    el.innerHTML = '<div class="cx-sg"><span>✨ ' + esc(sugg.text) + '</span><button class="cx-btn s" type="button" id="sgSd">Send</button><button class="cx-btn gh s" type="button" id="sgEd">Edit</button><button class="cx-btn gh s" type="button" id="sgRe" title="Another">↻</button></div>';
    $('#sgSd', el).onclick = async () => { const r = await call(() => api.post('bd.cx_send', { id: C.case.id, text: sugg.text }), false); if (r) { sugg = null; drawSg(); await refresh(); loadThread(true); } };
    $('#sgEd', el).onclick = () => { const t = $('#cxTx', H); t.value = sugg.text; t.focus(); t.oninput(); sugg = null; drawSg(); };
    $('#sgRe', el).onclick = () => { sugg = null; drawSg(); loadSugg(); };
  }
  async function loadThread(scroll) {
    if (!C || !C.can_work || !C.case.phone) return;
    const d = await api.get('bd.cx_thread', { id: C.case.id }).catch(() => null); if (!d) return; T = d;
    if (d.chat === false) { noChat($('#cxR', H)); return; }
    const box = $('#cxMsgs', H); if (!box) return;
    const near = box.scrollHeight - box.scrollTop - box.clientHeight < 90;
    const items = [];
    d.messages.forEach((m) => items.push({ t: String(m.ts), m }));
    C.events.forEach((e) => { if (e.kind === 'note' || e.kind === 'desk' || e.kind === 'owner' || e.kind === 'resolve' || e.kind === 'reopen' || e.kind === 'raised' || e.kind === 'link') items.push({ t: String(e.at), e }); });
    items.sort((a, b) => (a.t < b.t ? -1 : a.t > b.t ? 1 : 0));
    let last = '', h = '';
    items.forEach((it) => {
      const day = it.t.slice(0, 10); if (day !== last) { last = day; h += '<div class="cx-s">' + (day === new Date().toISOString().slice(0, 10) || day === localDay() ? 'Today' : dm(day)) + '</div>'; }
      if (it.e) { const e = it.e; if (e.kind === 'note') h += '<div class="cx-s n">🔒 ' + esc(e.by) + ': ' + esc(e.text) + '</div>'; else h += '<div class="cx-s">' + (e.kind === 'alert' ? '⏰ ' : '') + esc(e.text.length > 140 ? e.text.slice(0, 139) + '…' : e.text) + '</div>'; return; }
      const m = it.m; if (m.dir === 'sys') { h += '<div class="cx-s">' + esc(m.body) + '</div>'; return; }
      const mine = m.dir === 'out'; let inner = '';
      if (m.mtype === 'image') inner = '<img data-mid="' + esc(m.media_id) + '" alt="photo" style="min-width:120px;min-height:80px;background:#e9edef">' + (m.body ? '<div>' + linkify(esc(m.body)) + '</div>' : '');
      else if (m.mtype === 'audio') inner = '<audio controls data-mid="' + esc(m.media_id) + '"></audio>';
      else if (m.mtype === 'video' && m.media_id && PLAYED[m.media_id]) inner = '<video controls playsinline src="' + PLAYED[m.media_id] + '" style="max-width:100%;max-height:340px;border-radius:10px;display:block;background:#000"></video>';
      else if (m.mtype === 'video' && m.media_id) inner = '<div class="cx-mv" data-play="' + esc(m.media_id) + '" role="button" tabindex="0">▶ <b>Video</b> — tap to play' + '</div>' + (/:\s/.test(m.body || '') ? '<div>' + linkify(esc(String(m.body).replace(/^[^:]*:\s*/, ''))) + '</div>' : '');   // 🎬 unboxing videos: tap → plays here
      else if (m.mtype === 'sticker' && m.media_id) inner = '<img data-mid="' + esc(m.media_id) + '" alt="sticker" style="max-width:120px">';
      else if (m.mtype === 'document' && m.media_id) inner = '<div class="cx-mv" data-doc="' + esc(m.media_id) + '" data-n="' + esc(String(m.body || '').replace(/^[^:]*:\s*/, '') || 'Document') + '" role="button" tabindex="0">📄 <b>' + esc(String(m.body || '').replace(/^[^:]*:\s*/, '') || 'Document') + '</b> — tap to open</div>';
      else if (m.mtype === 'text' || m.mtype === 'button' || m.mtype === 'interactive' || m.mtype === 'template') inner = linkify(esc(m.body || ''));
      else inner = '<i>' + esc(m.mtype) + (m.body ? ': ' + esc(m.body) : '') + '</i>';
      h += '<div class="cx-m ' + (mine ? 'out' : 'in') + '">' + (mine && m.emp_name ? '<span class="w">' + esc(m.emp_name) + ' · Complaint team</span>' : '') + inner + '<span class="t' + (m.status === 'read' ? ' bl' : '') + '">' + hm(m.ts) + (mine ? (m.status === 'failed' ? ' ❗' : m.status === 'read' ? ' ✓✓' : m.status === 'delivered' ? ' ✓✓' : ' ✓') : '') + '</span></div>';
    });
    if (box.dataset.h !== h) { box.innerHTML = h || '<div class="cx-s">No messages yet</div>'; box.dataset.h = h; hydrate(box); if (scroll || near) box.scrollTop = box.scrollHeight; }
    const wb = $('#cxWinBar', H), cmp = $('#cxCmp', H); const w2 = $('#cxWith', H); if (w2) w2.textContent = '+91 ' + d.phone + ' · chat with ' + (d.chat_with || 'nobody');
    if (wb) {
      if (!d.window_open) winBar(wb);
      else if (!d.can_chat) wb.innerHTML = '<div class="cx-win"><span>This complaint is with ' + esc(C.case.owner_name || 'nobody') + '. Take it to reply.</span>' + (isOpen() ? '<button class="cx-btn s" type="button" id="cxTk2">Take it</button>' : '') + '</div>';
      else wb.innerHTML = '';
      const tp = $('#cxTp', wb); if (tp) tp.onclick = tplSheet; const tk = $('#cxTk2', wb); if (tk) tk.onclick = takeIt;
    }
    if (cmp) cmp.style.display = d.optout ? 'none' : '';
    drawSg();
  }
  function noChat(el) {
    if (!el || el.querySelector('#cxNcNote')) return; const v = C.case;
    el.innerHTML = '<div class="cx-noc"><div><div style="font-size:30px">📵</div><b>No WhatsApp chat with +91 ' + esc(v.phone) + ' yet.</b><br>Calls, notes and photos of this customer are in the timeline on the left' + (window.innerWidth <= 900 ? ' (Details tab)' : '') + '.<div style="margin-top:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">' + (window.CXM && CXM.ready ? '<button class="cx-btn" type="button" id="cxNcCall">☎ Log a call</button>' : '') + '<button class="cx-btn gh" type="button" id="cxNcNote">＋ Add a note</button></div></div></div>';
    const a = $('#cxNcCall', el); if (a) a.onclick = () => CXM.callSheet({ phone: v.phone, name: v.name, order: v.order_name }); $('#cxNcNote', el).onclick = noteSheet;
  }
  const localDay = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  async function streamUrl(id) { const d = await api.get('wa.media_link', { id }); return api.buildUrl('wa.mfile', { id: d.id, e: d.e, s: d.s }); }   // ⚡ a signed link the browser streams (videos start at once, seek works)
  async function blobOf(id) {
    if (blobs[id]) return blobs[id];
    const r = await fetch(api.buildUrl('wa.media', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) { let m = 'could not load'; try { m = (await r.json()).error || m; } catch (x) { } throw new Error(m); }
    return (blobs[id] = URL.createObjectURL(await r.blob()));
  }
  // ▶ a video / 📄 a document from the customer: loaded from our server (cached), then played here / opened in a new tab
  document.addEventListener('click', async (e) => {
    const v = e.target.closest && e.target.closest('.cx-mv[data-play], .cx-mv[data-doc]'); if (!v || v.dataset.busy) return; v.dataset.busy = '1'; const old = v.innerHTML; v.innerHTML = '⏳ Loading…';
    try {
      if (v.dataset.play) { const u = await streamUrl(v.dataset.play); PLAYED[v.dataset.play] = u; const vid = document.createElement('video'); vid.controls = true; vid.autoplay = true; vid.playsInline = true; vid.src = u; vid.style.cssText = 'max-width:100%;max-height:340px;border-radius:10px;display:block;background:#000'; v.replaceWith(vid); }
      else { const u = await streamUrl(v.dataset.doc); const w = window.open(u, '_blank'); if (!w) { const a = document.createElement('a'); a.href = u; a.download = v.dataset.n || 'file'; a.click(); } v.innerHTML = old; delete v.dataset.busy; }
    } catch (er) { v.innerHTML = '⚠ Could not load — ' + esc(er.message || 'try again') + ' (tap to retry)'; delete v.dataset.busy; }
  });
  function hydrate(box) { box.querySelectorAll('[data-mid]').forEach(async (n) => { try { n.src = await blobOf(n.dataset.mid); if (n.tagName === 'IMG') n.onclick = () => window.open(n.src, '_blank'); } catch (e) { n.alt = 'Photo could not be loaded'; n.style.opacity = .4; } }); }
  function noteSheet() { const m = modal('<h3>Add a note</h3><textarea id="ntT" rows="3" placeholder="What happened"></textarea><div class="ac"><button class="cx-btn gh" type="button" id="ntNo">Cancel</button><button class="cx-btn" type="button" id="ntOk">Save</button></div>'); $('#ntNo', m.el).onclick = m.close; $('#ntOk', m.el).onclick = async () => { const t = $('#ntT', m.el).value.trim(); if (!t) return; const r = await call(() => api.post('bd.cx_note', { id: C.case.id, text: t }), 'Saved.'); if (r) { m.close(); refresh(); } }; }
  // ⏰ after 24 hours WhatsApp only lets an APPROVED message through: the two complaint messages (Utility) — one tap; the customer's reply opens a new 24-hour window
  let TPLS = null;
  async function winBar(wb) {
    const draw = () => {
      const ok = (TPLS || []).filter((t) => t.status === 'approved' || t.status === 'partial'), wait = (TPLS || []).filter((t) => t.status === 'pending'), miss = (TPLS || []).filter((t) => t.status === 'missing' || t.status === 'rejected');
      const upd = ok.find((t) => t.name === 'tmph_complaint_update');
      wb.innerHTML = '<div class="cx-win"><span><b>⏰ 24 hours passed</b> — ' + (upd ? 'just type below (or tap a quick reply): it goes as the approved <b>Complaint update</b> message (utility). When the customer replies, the normal chat opens again.' : 'the approved complaint messages are with Meta for approval (sent by themselves, usually minutes). Then what you type goes as the approved Complaint update.') + '</span>'
        + ok.filter((t) => t.name === 'tmph_complaint_need_info').map((t) => '<button class="cx-btn s" type="button" data-ct="' + t.id + '">❓ Ask for info</button>').join('')
        + (wait.length ? '<small class="cx-wt">' + wait.length + ' waiting for Meta approval</small>' : '')
        + (miss.length && C.can_work ? '<button class="cx-btn gh s" type="button" id="cxTset">Send to Meta now</button>' : '')
        + '<button class="cx-btn gh s" type="button" id="cxTp">Other approved messages</button></div>';
      wb.querySelectorAll('[data-ct]').forEach((b) => { b.onclick = () => ctSend((TPLS || []).find((t) => t.id === +b.dataset.ct)); });
      const tp = $('#cxTp', wb); if (tp) tp.onclick = tplSheet;
      const ts = $('#cxTset', wb); if (ts) ts.onclick = async () => { ts.disabled = true; ts.textContent = 'Sending to Meta…'; const r = await call(() => api.post('bd.cx_tpls', {}), false); if (r) { TPLS = r.tpls; toast((r.result.failed || []).length ? 'Meta said: ' + r.result.failed[0] : 'Sent to Meta for approval — usually ready in minutes.'); } draw(); };
    };
    if (!TPLS) { wb.innerHTML = '<div class="cx-win"><span><b>⏰ 24 hours passed</b> — only an approved message can be sent.</span></div>'; const r = await api.get('bd.cx_tpls').catch(() => null); TPLS = r && r.tpls ? r.tpls : []; }
    draw();
  }
  function ctSend(t) {
    if (!t) return; const v = C.case, first = (v.name || '').split(' ')[0] || 'Sir';
    const upd = t.name === 'tmph_complaint_update', m = modal('<h3>' + (upd ? '📨 Complaint update' : '❓ Ask for information') + '</h3><div class="cx-ord" id="ctPv" style="margin:6px 0 10px;font-size:13px"></div><label>' + (upd ? 'The update (one or two sentences)' : 'What we need') + '</label><textarea id="ctT" rows="3" placeholder="' + (upd ? 'Your replacement has been shipped today. Tracking: …' : 'a photo of the bottle and the box') + '"></textarea><div class="ac"><button class="cx-btn gh" type="button" id="ctNo">Cancel</button><button class="cx-btn" type="button" id="ctGo">Send</button></div>');
    const pv = () => { const x = $('#ctT', m.el).value.trim(); $('#ctPv', m.el).innerHTML = esc(t.body.replace('{{1}}', first).replace('{{2}}', v.code)).replace('{{3}}', x ? '<b>' + esc(x) + '</b>' : '<i style="color:var(--mut)">(your words)</i>'); };
    pv(); $('#ctT', m.el).oninput = pv;   // the customer's message, exactly as it will look
    $('#ctNo', m.el).onclick = m.close;
    $('#ctGo', m.el).onclick = async function () { const x = $('#ctT', m.el).value.trim().replace(/\s+/g, ' '); if (x.length < 3) { $('#ctT', m.el).focus(); return; } this.disabled = true;
      const r = await call(() => api.post('bd.cx_tpl_send', { id: v.id, tpl_id: t.id, vars: [first, v.code, x] })); if (r) { m.close(); toast('Sent ✓ — when the customer replies, the normal chat opens again.'); loadThread(true); } else this.disabled = false; };
    setTimeout(() => { const ta = $('#ctT', m.el); if (ta) ta.focus(); }, 50);
  }
  async function tplSheet() {
    const d = await call(() => api.get('wa.tpl_list'), false); if (!d) return; const ok = (d.templates || []).filter((t) => (t.status === 'approved' || t.status === 'partial') && !t.media_header);
    const m = modal('<h3>Pre-made message</h3>' + (ok.length ? '<div id="tpL">' + ok.map((t, i) => '<div class="cx-row" data-i="' + i + '"><div class="m"><b>' + esc(t.name) + '</b><small>' + esc(t.body.length > 120 ? t.body.slice(0, 119) + '…' : t.body) + '</small></div></div>').join('') + '</div><div id="tpF"></div>' : '<div class="cx-warn">No approved pre-made message yet. Add one in WhatsApp → Pre-made messages.</div>') + '<div class="ac"><button class="cx-btn gh" type="button" id="tpNo">Close</button></div>');
    $('#tpNo', m.el).onclick = m.close;
    m.el.querySelectorAll('#tpL .cx-row').forEach((r) => r.onclick = () => {
      const t = ok[Number(r.dataset.i)], n = t.vars || 0; let f = '<div class="cx-ord" style="margin:10px 0">' + esc(t.body) + '</div>';
      for (let i = 1; i <= n; i++) f += '<label>Variable {{' + i + '}}</label><input class="cx-in tpv" value="' + (i === 1 ? esc((C.case.name || '').split(' ')[0]) : '') + '">';
      f += (t.header_var ? '<label>Heading variable</label><input class="cx-in" id="tpH">' : '') + '<div class="ac"><button class="cx-btn" type="button" id="tpGo">Send</button></div>';
      $('#tpF', m.el).innerHTML = f; $('#tpL', m.el).style.display = 'none';
      $('#tpGo', m.el).onclick = async () => { const vars = [...m.el.querySelectorAll('.tpv')].map((x) => x.value.trim()); const h = $('#tpH', m.el); const res = await call(() => api.post('bd.cx_tpl_send', { id: C.case.id, tpl_id: t.id, vars }), 'Sent.'); if (res) { m.close(); loadThread(true); } };
    });
  }

  /* ------------------------------ 🔎 track an order: phone / order / AWB → Shopify orders + LIVE Shiprocket status ------------------------------ */
  function trackSheet(q0) {
    const m = modal('<div class="cx-mh"><b>🔎 Track an order</b><button type="button" class="cx-x" aria-label="Close">✕</button></div><div class="cx-mb"><div class="cx-trk-q"><input class="cx-in" id="tkQ" type="search" inputmode="search" placeholder="Phone, order number (#67533) or tracking number" value="' + esc(q0 || '') + '"><button type="button" class="cx-btn" id="tkGo">Track</button></div><div id="tkOut" class="cx-trk"></div></div>');
    m.el.querySelector('.cx-x').onclick = m.close; const inp = m.el.querySelector('#tkQ'), out = m.el.querySelector('#tkOut');
    const run = async () => {
      const q = inp.value.trim(); if (q.length < 4) { out.innerHTML = '<div class="cx-empty">Type at least 4 characters.</div>'; return; }
      out.innerHTML = '<div class="cx-empty">Asking Shiprocket and Shopify…</div>';
      const d = await call(() => api.get('bd.cx_track', { q }), false); if (!d) { out.innerHTML = ''; return; }
      let h = d.open ? '<div class="cx-note">This customer has an open complaint <a href="#" data-cs="' + d.open.id + '">' + esc(d.open.code) + '</a>.</div>' : '';
      h += (d.parcels || []).map((p) => '<div class="cx-pc"><div class="h"><b>' + esc(p.order || '—') + '</b><span class="cx-pill ' + (/deliver/i.test(p.status) ? 'g' : /rto|return|fail|ndr|lost|damag/i.test(p.status) ? 'r' : 'b') + '">' + esc(p.status || 'no status') + '</span><span class="sp"></span>' + (p.link ? '<a href="' + esc(p.link) + '" target="_blank" rel="noopener">Tracking link ↗</a>' : '') + '</div>'
        + '<div class="m">' + esc([p.customer, p.phone ? '+91 ' + p.phone : '', p.courier, p.awb ? 'AWB ' + p.awb : 'no tracking number yet', p.etd ? 'expected ' + p.etd : '', p.delivered_at ? 'delivered ' + p.delivered_at.slice(0, 16) : ''].filter(Boolean).join(' · ')) + '</div>'
        + (p.events.length ? '<ol class="ev">' + p.events.map((e) => '<li><b>' + esc(e.at.slice(5)) + '</b> ' + esc(e.text) + (e.where ? ' <span>· ' + esc(e.where) + '</span>' : '') + '</li>').join('') + '</ol>' : '') + '<div class="m">' + (p.note ? '⚠ ' + esc(p.note) : (p.live_at ? 'Live from Shiprocket · ' + esc(p.live_at) : '')) + '</div></div>').join('');
      const shipped = new Set((d.parcels || []).map((p) => String(p.order).replace('#', '')));
      const rest = (d.orders || []).filter((o) => !shipped.has(String(o.name || '').replace('#', '')));
      if (rest.length) h += '<div class="cx-lbl">Shopify orders not shipped yet</div>' + rest.map((o) => '<div class="cx-pc"><div class="h"><b>' + esc(o.name || '') + '</b>' + (o.total != null ? '<span>' + inr(o.total) + '</span>' : '') + '<span class="sp"></span><span class="cx-pill o">' + esc(o.fulfillment || o.fulfillment_status || 'not shipped') + '</span></div><div class="m">' + esc([o.at || o.created_at || '', o.pay || o.financial_status || ''].filter(Boolean).join(' · ')) + '</div></div>').join('');
      if (!h) h = '<div class="cx-empty">Nothing found for “' + esc(q) + '”. Check the number, or the order may not be in Shopify / Shiprocket yet.</div>';
      out.innerHTML = h; out.querySelectorAll('[data-cs]').forEach((a) => { a.onclick = (e) => { e.preventDefault(); m.close(); openCase(+a.dataset.cs); }; });
    };
    m.el.querySelector('#tkGo').onclick = run; inp.onkeydown = (e) => { if (e.key === 'Enter') run(); }; setTimeout(() => inp.focus(), 50); if (q0) run();
  }
  /* ------------------------------ 📅 daily report: new · moved desk · solved · closed · reopened · still open — per day, per desk, per person ------------------------------ */
  async function daily(host) {
    const day = S.dday || new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
    host.innerHTML = '<div class="cx-empty">Loading the daily report…</div>';
    const d = await call(() => api.get('bd.cx_daily', { date: day, days: 14 }), false); if (!d) { host.innerHTML = ''; return; }
    const t = d.days[d.days.length - 1] || {};
    let h = '<div class="cx-box"><h4 style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">📅 Daily report <span class="sp" style="flex:1"></span><input type="date" class="cx-in" id="dyD" value="' + esc(d.date) + '" style="width:auto;min-height:38px"><button type="button" class="cx-btn gh" id="dyCsv">⬇ CSV</button></h4>'
      + '<div class="cx-kpis">' + [['New', t.new], ['Moved desk', t.moved], ['Solved', t.solved], ['Closed', t.closed], ['Reopened', t.reopened], ['Still open', t.open_end]].map((x) => '<div class="cx-k"><small>' + x[0] + '</small><b>' + (x[1] || 0) + '</b></div>').join('') + '</div>'
      + '<div class="cx-dyw"><table class="cx-tbl cx-dy"><thead><tr><th>Day</th><th class="n">New</th><th class="n">Moved desk</th><th class="n">Solved</th><th class="n">Closed</th><th class="n">Reopened</th><th class="n">Still open</th></tr></thead><tbody>'
      + d.days.slice().reverse().map((r) => '<tr' + (r.date === d.date ? ' class="on"' : '') + '><td>' + dm(r.date) + '</td><td class="n">' + r.new + '</td><td class="n">' + r.moved + '</td><td class="n"><b>' + r.solved + '</b></td><td class="n">' + r.closed + '</td><td class="n">' + r.reopened + '</td><td class="n">' + r.open_end + '</td></tr>').join('') + '</tbody></table></div>'
      + '<div class="cx-2" style="margin-top:10px"><div><div class="cx-lbl">Desks on ' + dm(d.date) + '</div><table class="cx-tbl"><thead><tr><th>Desk</th><th class="n">New</th><th class="n">Solved</th><th class="n">Open now</th><th class="n">Late</th></tr></thead><tbody>' + d.desks.map((x) => '<tr><td>' + esc(x.label) + '</td><td class="n">' + x.new + '</td><td class="n"><b>' + x.solved + '</b></td><td class="n">' + x.open + '</td><td class="n"' + (x.late ? ' style="color:#b42318;font-weight:700"' : '') + '>' + x.late + '</td></tr>').join('') + '</tbody></table></div>'
      + '<div><div class="cx-lbl">People on ' + dm(d.date) + '</div><table class="cx-tbl"><thead><tr><th>Person</th><th class="n">Solved</th><th class="n">Open now</th><th class="n">Late</th></tr></thead><tbody>' + (d.people.length ? d.people.map((x) => '<tr><td>' + esc(x.name) + '</td><td class="n"><b>' + x.solved + '</b></td><td class="n">' + x.open + '</td><td class="n"' + (x.late ? ' style="color:#b42318;font-weight:700"' : '') + '>' + x.late + '</td></tr>').join('') : '<tr><td colspan="4" style="color:var(--mut)">Nobody holds a case.</td></tr>') + '</tbody></table></div></div>'
      + (d.moves.length ? '<div class="cx-lbl" style="margin-top:10px">Moved between desks on ' + dm(d.date) + '</div><div class="cx-mv">' + d.moves.map((x) => '<div><a href="#" data-cs="' + x.id + '">' + esc(x.code) + '</a> <span>' + esc(x.at) + '</span> ' + esc(x.text) + '</div>').join('') + '</div>' : '') + '</div>';
    host.innerHTML = h;
    host.querySelector('#dyD').onchange = (e) => { S.dday = e.target.value; daily(host); };
    host.querySelectorAll('[data-cs]').forEach((a) => { a.onclick = (e) => { e.preventDefault(); openCase(+a.dataset.cs); }; });
    host.querySelector('#dyCsv').onclick = () => { const L = [['Day', 'New', 'Moved desk', 'Solved', 'Closed', 'Reopened', 'Still open']].concat(d.days.map((r) => [r.date, r.new, r.moved, r.solved, r.closed, r.reopened, r.open_end])); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + L.map((r) => r.join(',')).join('\n')], { type: 'text/csv' })); a.download = 'complaints-daily-' + d.date + '.csv'; a.click(); };
  }
  /* ------------------------------ reports ------------------------------ */
  // 📊 the Complaints dashboard (bd.cx_dash): registered / solved / open, departments, problems, team, messages, money, repeat complainers
  async function dash() {
    const P = S.dp || 'month'; body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await call(() => api.get('bd.cx_dash', Object.assign({ p: P }, P === 'custom' ? { from: S.dfrom || '', to: S.dto || '' } : {})), false); if (!d) return;
    const c = d.cards, pv = d.prev, n = (x) => Number(x || 0).toLocaleString('en-IN');
    const diff = (a, b, goodUp) => { if (!b) return ''; const pc = Math.round((a - b) / b * 100); if (!pc) return '<em style="color:var(--mut);font-style:normal">same as before</em>'; const up = pc > 0, good = goodUp ? up : !up; return '<em style="font-style:normal;color:' + (good ? '#13703a' : '#b42318') + '">' + (up ? '▲ ' : '▼ ') + Math.abs(pc) + '% vs before</em>'; };
    const card = (lab, val, sub, tone) => '<div class="cx-k" style="' + (tone === 'bad' ? 'border-color:#f3c0bb;background:#fff7f6' : tone === 'good' ? 'border-color:#bfe5cd;background:#f5fbf7' : '') + '"><small>' + lab + '</small><b>' + val + '</b>' + (sub ? '<div style="font-size:11.5px;margin-top:2px">' + sub + '</div>' : '') + '</div>';
    const PER = [['today', 'Today'], ['yesterday', 'Yesterday'], ['week', '7 days'], ['month', 'This month'], ['last_month', 'Last month'], ['90', '90 days'], ['custom', 'Pick dates']];
    let h = '<div class="cx-fl" style="flex-wrap:wrap;gap:6px">' + PER.map(([k, l]) => '<button class="cx-btn s' + (k === P ? '' : ' gh') + '" type="button" data-dp="' + k + '">' + l + '</button>').join('')
      + (P === 'custom' ? '<input type="date" class="cx-in" id="dpF" value="' + esc(d.from) + '" style="width:auto"> → <input type="date" class="cx-in" id="dpT" value="' + esc(d.to) + '" style="width:auto"><button class="cx-btn s" type="button" id="dpGo">Show</button>' : '') + '</div>';
    h += '<div style="color:var(--mut);margin:-4px 0 10px">📊 ' + esc(d.label) + ' · ' + esc(d.from) + (d.to !== d.from ? ' → ' + esc(d.to) : '') + '</div>';
    h += '<div class="cx-kpis">' + card('Registered', n(c.registered), diff(c.registered, pv.registered, false)) + card('Solved', n(c.solved), diff(c.solved, pv.solved, true), 'good')
      + card('Still open (now)', n(c.open_now), c.no_reply_now ? c.no_reply_now + ' not answered yet' : 'all answered', c.no_reply_now ? 'bad' : '') + card('Open > 2 days', n(c.open_2d), c.late_now ? c.late_now + ' past the deadline' : 'none late', c.open_2d ? 'bad' : 'good')
      + card('First reply in', c.first_reply, 'average') + card('Time to solve', c.fix, 'average') + '</div>';
    h += '<div class="cx-kpis">' + card('Happy customers', c.happy_pct === null ? '—' : c.happy_pct + '%', c.happy + ' “All good” · ' + c.unhappy + ' not happy', c.happy_pct !== null && c.happy_pct < 70 ? 'bad' : 'good')
      + card('Opened again', n(c.reopened), 'customer said “Still a problem”', c.reopened ? 'bad' : '') + card('Refunds paid', inr(d.money.refunds), d.money.refunds_n + (d.money.refunds_n === 1 ? ' refund' : ' refunds')) + card('Store credit given', inr(d.money.credit), d.money.credit_n + (d.money.credit_n === 1 ? ' credit' : ' credits'))
      + card('Replacements', n(d.money.replacements), d.money.replacements_sent + ' shipped · ' + d.money.pickups + ' pickups') + card('Total cost', inr(d.money.total), 'refunds + credit + other', d.money.total ? 'bad' : '') + '</div>';
    const mx = Math.max(1, ...d.days.map((x) => Math.max(x.reg, x.sol)));
    h += '<div class="cx-box"><h4>Per day — <span style="color:#e5484d">■ registered</span> · <span style="color:#00a884">■ solved</span></h4><div style="display:flex;gap:3px;align-items:flex-end;height:90px">' + d.days.map((x) => '<div style="flex:1;display:flex;gap:1px;align-items:flex-end;height:100%" title="' + esc(x.d) + ': ' + x.reg + ' registered, ' + x.sol + ' solved"><i style="flex:1;background:#e5484d;opacity:.85;border-radius:3px 3px 0 0;height:' + Math.max(2, Math.round(x.reg / mx * 100)) + '%"></i><i style="flex:1;background:#00a884;border-radius:3px 3px 0 0;height:' + Math.max(2, Math.round(x.sol / mx * 100)) + '%"></i></div>').join('') + '</div><div style="display:flex;justify-content:space-between;color:var(--mut);font-size:11px;margin-top:4px"><span>' + esc((d.days[0] || {}).d || '') + '</span><span>' + esc((d.days[d.days.length - 1] || {}).d || '') + '</span></div></div>';
    const tbl = (rows, cols) => '<div style="overflow-x:auto"><table class="cx-tbl"><thead><tr><th></th>' + cols.map((x) => '<th class="n">' + x[0] + '</th>').join('') + '</tr></thead><tbody>' + (rows.length ? rows.map((r) => '<tr><td><b>' + esc(r.label) + '</b></td>' + cols.map((x) => '<td class="n">' + x[1](r) + '</td>').join('') + '</tr>').join('') : '<tr><td colspan="' + (cols.length + 1) + '" style="color:var(--mut)">Nothing in this period.</td></tr>') + '</tbody></table></div>';
    const hp = (r) => r.happy + r.unhappy ? Math.round(100 * r.happy / (r.happy + r.unhappy)) + '%' : '—', red = (v) => v ? '<b style="color:#b42318">' + v + '</b>' : '0';
    h += '<div class="cx-box"><h4>By department</h4>' + tbl(d.dept, [['Registered', (r) => r.reg], ['Solved', (r) => r.sol], ['Open now', (r) => r.open], ['Open > 2 days', (r) => red(r.open2)], ['First reply', (r) => r.first_reply], ['Time to solve', (r) => r.fix], ['Happy', hp]]) + '</div>';
    h += '<div class="cx-box"><h4>By team member (who handles the ticket)</h4>' + tbl(d.team, [['Got', (r) => r.reg], ['Solved', (r) => r.sol], ['Open now', (r) => r.open], ['Open > 2 days', (r) => red(r.open2)], ['First reply', (r) => r.first_reply], ['Time to solve', (r) => r.fix], ['Happy', hp]]) + '</div>';
    h += '<div class="cx-box"><h4>By problem</h4>' + tbl(d.problems, [['Registered', (r) => r.reg], ['Solved', (r) => r.sol], ['Open now', (r) => r.open], ['Time to solve', (r) => r.fix], ['Cost', (r) => r.cost ? inr(r.cost) : '—']]) + '</div>';
    const m = d.messages;
    h += '<div class="cx-2"><div class="cx-box"><h4>Messages to customers</h4><table class="cx-tbl"><tbody>'
      + [['🎫 Ticket messages (created / resolved / open again)', m.ticket + (m.ticket ? ' · ' + m.ticket_photo + ' with photo' : '')], ['📨 Progress messages (pickup, shipped, refund…)', m.progress + (m.progress ? ' · ' + m.progress_photo + ' with photo' : '')], ['✍️ Pre-made messages sent by the team', m.manual], ['😊 Customer pressed “All good”', m.all_good], ['😟 Customer pressed “Still a problem”', red(m.still_problem)], ['⏳ Waiting for Meta to approve (now)', m.waiting ? '<b style="color:#8a5a00">' + m.waiting + '</b>' : '0']].map((x) => '<tr><td>' + x[0] + '</td><td class="n"><b>' + x[1] + '</b></td></tr>').join('') + '</tbody></table></div>';
    h += '<div class="cx-box"><h4>Repeat complainers (2+ tickets, 90 days)</h4>' + (d.repeat.length ? d.repeat.map((r) => '<div class="cx-ev"><b>' + esc(r.name) + '</b> · +91 ' + esc(r.phone) + ' · <b>' + r.n + '</b> tickets · last ' + esc(r.last) + '</div>').join('') : '<div style="color:var(--mut)">None 🎉</div>') + '</div></div>';
    body().innerHTML = h;
    body().querySelectorAll('[data-dp]').forEach((b) => b.onclick = () => { S.dp = b.dataset.dp; dash(); });
    const g = $('#dpGo', H); if (g) g.onclick = () => { S.dfrom = $('#dpF', H).value; S.dto = $('#dpT', H).value; dash(); };
  }

  async function report() {
    const days = S.days || 30; body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await call(() => api.get('bd.cx_report', { days }), false); if (!d) return; const t = d.total; const mx = Math.max(1, ...d.series.map((x) => x.n));
    const tbl = (rows, extra) => '<table class="cx-tbl"><tbody>' + (rows.length ? rows.map((r) => '<tr><td>' + esc(r.label) + '</td><td class="n"><b>' + r.n + '</b></td>' + (extra ? '<td class="n" style="color:var(--mut)">' + extra(r) + '</td>' : '') + '</tr>').join('') : '<tr><td style="color:var(--mut)">No data yet</td></tr>') + '</tbody></table>';
    let h = '<div id="cxDaily"></div><div class="cx-fl"><select class="cx-sel" id="rpD">' + [7, 30, 90, 365].map((x) => '<option value="' + x + '"' + (x === days ? ' selected' : '') + '>Last ' + x + ' days</option>').join('') + '</select></div>';
    h += '<div class="cx-kpis">' + [['Complaints', t.n], ['Still open', t.open], ['Late now', t.late], ['Avg time to fix', t.avg_fix], ['First reply in', t.avg_reply], ['Cost', inr(t.cost)]].map((x) => '<div class="cx-k"><small>' + x[0] + '</small><b>' + x[1] + '</b></div>').join('') + '</div>';
    h += '<div class="cx-2"><div class="cx-box"><h4>Per day (last 14 days)</h4><div class="cx-bars">' + d.series.map((x) => '<i style="height:' + Math.max(3, Math.round(x.n / mx * 100)) + '%" title="' + dm(x.d) + ': ' + x.n + '"></i>').join('') + '</div></div><div class="cx-box"><h4>Customers after the fix</h4><div style="font-size:22px;font-weight:800">😊 ' + t.happy + ' &nbsp; 😞 ' + t.unhappy + ' &nbsp; <span style="font-size:14px;color:var(--mut)">Reopened: ' + t.reopened + '</span></div></div></div>';
    h += '<div class="cx-2"><div class="cx-box"><h4>By problem</h4>' + tbl(d.by_problem, (r) => 'fix ' + r.avg + ' · ' + inr(r.cost)) + '</div><div class="cx-box"><h4>By product</h4>' + tbl(d.by_product) + '</div>';
    h += '<div class="cx-box"><h4>By courier</h4>' + tbl(d.by_courier) + '</div><div class="cx-box"><h4>By salesperson who raised it</h4>' + tbl(d.by_person) + '</div>';
    h += '<div class="cx-box"><h4>By source</h4>' + tbl(d.by_source) + '</div><div class="cx-box"><h4>How it was solved</h4>' + tbl(d.by_resolution, (r) => inr(r.cost)) + '</div></div>';
    h += '<div class="cx-box"><h4>Repeat complainers (90 days)</h4>' + (d.repeat.length ? d.repeat.map((r) => '<div class="cx-ev"><b>' + esc(r.name) + '</b> · +91 ' + esc(r.phone) + ' · ' + r.n + ' complaints</div>').join('') : '<div style="color:var(--mut)">None.</div>') + '</div>';
    h += '<div class="cx-box"><h4>Lessons for the bot (from solved complaints)</h4>' + (d.lessons.length ? d.lessons.map((l) => '<div class="cx-ev" data-l="' + l.id + '"><b>' + l.code + '</b> · customer: “' + esc(l.customer) + '”<br>Our reply: “' + esc(l.reply) + '”' + (d.admin ? '<div style="margin-top:6px"><button class="cx-btn s" type="button" data-t="' + l.id + '">Teach the bot</button> <button class="cx-btn gh s" type="button" data-k="' + l.id + '">Skip</button></div>' : '') + '</div>').join('') : '<div style="color:var(--mut)">No new lessons. A solved complaint with a good reply shows up here for you to approve — nothing reaches the bot by itself.</div>') + '</div>';
    body().innerHTML = h; { const dh = body().querySelector('#cxDaily'); if (dh) daily(dh); } if (window.CXM && CXM.ready) CXM.reportExtra(body(), days); $('#rpD', H).onchange = (e) => { S.days = Number(e.target.value); report(); };
    body().querySelectorAll('[data-t]').forEach((b) => b.onclick = async () => {   // one brain: a solved complaint becomes a topic on the Teach board
      const l = (d.lessons || []).find((x) => Number(x.id) === Number(b.dataset.t)); if (!l) return; b.disabled = true; b.textContent = 'Making a topic…';
      const text = 'From solved complaint ' + l.code + (l.problem ? ' (' + l.problem + ')' : '') + '. The customer said: "' + (l.customer || '') + '". The reply that solved it: "' + (l.reply || '') + '". Learn how to handle this kind of complaint and ask me only what is unclear.';
      const r = await call(async () => { await api.post('teach.board_add', { text }); return api.post('bd.cx_lesson', { id: Number(l.id), board: 1 }); }); if (r) report(); else { b.disabled = false; b.textContent = 'Teach the bot'; } });
    body().querySelectorAll('[data-k]').forEach((b) => b.onclick = async () => { const r = await call(() => api.post('bd.cx_lesson', { id: Number(b.dataset.k), skip: 1 })); if (r) report(); });
  }

  /* ------------------------------ settings ------------------------------ */
  async function settings() {
    body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await call(() => api.get('bd.cx_settings'), false); if (!d) return; const c = d.cfg, mg = d.mgr; const dis = mg ? '' : ' disabled';
    const nm = (id) => { const e = d.employees.find((x) => x.id === id); return e ? e.name : '#' + id; };
    let h = (mg ? '' : '<div class="cx-warn">Only the complaints boss, Admin or HR can change settings.</div>');
    h += '<div class="cx-box"><h4>Who sits on each desk</h4><div style="color:var(--mut);margin-bottom:8px">These are Boss Engine seats, so the Boss page shows them too. A new complaint goes to the least-busy present person of its desk.</div>'
      + d.desks.concat([{ k: 'boss', label: 'Complaints boss', icon: '⭐', about: 'Sees everything, approves refunds above the limit', emps: d.boss }]).map((dk) => '<div class="cx-row" style="cursor:default;align-items:flex-start"><div class="m"><b>' + dk.icon + ' ' + esc(dk.label) + '</b><small>' + esc(dk.about) + '</small><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">' + (dk.emps.length ? dk.emps.map((e) => '<span class="cx-pill g">' + esc(nm(e)) + (mg ? ' <a href="#" data-rm="' + dk.k + ':' + e + '" style="color:#b3261e;text-decoration:none">✕</a>' : '') + '</span>').join('') : '<span class="cx-pill r">Nobody yet</span>') + '</div></div>' + (mg ? '<select class="cx-sel" data-add="' + dk.k + '" style="max-width:170px"><option value="">＋ Add person</option>' + d.employees.filter((e) => dk.emps.indexOf(e.id) < 0).map((e) => '<option value="' + e.id + '">' + esc(e.name) + (e.dept ? ' · ' + esc(e.dept) : '') + '</option>').join('') + '</select>' : '') + '</div>').join('') + '</div>';
    h += '<div class="cx-box"><h4>Time limits and refund limit</h4><table class="cx-tbl"><thead><tr><th></th><th>First reply within (minutes)</th><th>Fixed within (hours)</th></tr></thead><tbody>' + ['urgent', 'high', 'normal'].map((u) => '<tr><td><b>' + URG[u] + '</b></td><td><input class="cx-in" type="number" min="1" id="st_reply_' + u + '" value="' + esc(c['reply_' + u]) + '" style="width:110px"' + dis + '></td><td><input class="cx-in" type="number" min="1" id="st_fix_' + u + '" value="' + esc(c['fix_' + u]) + '" style="width:110px"' + dis + '></td></tr>').join('') + '</tbody></table>'
      + '<div style="margin-top:10px">Refunds above <input class="cx-in" type="number" min="0" id="st_refund_limit" value="' + esc(c.refund_limit) + '" style="width:110px"' + dis + '> ₹ need the boss\'s OK.</div>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" id="st_push"' + (c.push === '1' ? ' checked' : '') + dis + '> Phone notifications for new, late and moved complaints</label><label style="display:flex;gap:8px;align-items:center;margin-top:6px"><input type="checkbox" id="st_teach"' + (c.teach_feed === '1' ? ' checked' : '') + dis + '> Turn solved complaints into lesson drafts for the bot (you approve each one in Reports)</label>' + '</div>';
    if (window.CXM && CXM.ready) h += CXM.settingsBox(c, mg, dis);
    if (mg) h += '<div style="margin-bottom:12px"><button class="cx-btn" type="button" id="stSave">Save settings</button></div>';
    h += '<div class="cx-box" id="cxMsgsBox"><h4>💬 Messages to the customer — photo &amp; words</h4><div id="stCM"><div class="cx-empty">Loading…</div></div></div>';
    h += '<div class="cx-box"><h4>Quick replies for complaints</h4><div style="color:var(--mut);margin-bottom:8px">Use {name}, {me}, {case}, {order}, {awb} — they are filled in for each customer.</div><div id="stQ"></div></div>';
    body().innerHTML = h;
    body().querySelectorAll('[data-add]').forEach((s) => s.onchange = async () => { if (!s.value) return; const k = s.dataset.add; const cur = (k === 'boss' ? d.boss : d.desks.find((x) => x.k === k).emps).concat([Number(s.value)]); const r = await call(() => api.post('bd.cx_team_save', { desk: k, emps: cur })); if (r) settings(); });
    body().querySelectorAll('[data-rm]').forEach((a) => a.onclick = async (e) => { e.preventDefault(); const [k, id] = a.dataset.rm.split(':'); const cur = (k === 'boss' ? d.boss : d.desks.find((x) => x.k === k).emps).filter((x) => x !== Number(id)); const r = await call(() => api.post('bd.cx_team_save', { desk: k, emps: cur })); if (r) settings(); });
    const sv = $('#stSave', H); if (sv) sv.onclick = async () => { const g = (id) => Number($(id, H).value) || 0; const r = await call(() => api.post('bd.cx_settings_save', Object.assign({ refund_limit: g('#st_refund_limit'), reply_urgent: g('#st_reply_urgent'), reply_high: g('#st_reply_high'), reply_normal: g('#st_reply_normal'), fix_urgent: g('#st_fix_urgent'), fix_high: g('#st_fix_high'), fix_normal: g('#st_fix_normal'), push: $('#st_push', H).checked ? 1 : 0, teach_feed: $('#st_teach', H).checked ? 1 : 0 }, (window.CXM && CXM.ready) ? CXM.settingsVals() : {}))); if (r) settings(); };
    quickEditor(mg); msgCenter(mg);
  }
  // 💬 ONE place for every automatic complaint message: the photo on top + the words + Meta's answer, card by card (complaints_photo.php · bd.cx_msgs · bd.cx_photos)
  async function msgCenter(mg) {
    const el = $('#stCM', H); if (!el) return; let M, P;
    try { [M, P] = await Promise.all([api.get('bd.cx_msgs'), api.get('bd.cx_photos')]); } catch (e) { el.innerHTML = '<div class="cx-warn">' + esc(e.message) + '</div>'; return; }
    const ST = { none: ['Words not sent to Meta yet', '#eef0f3', '#555'], missing: ['Words not sent to Meta yet', '#eef0f3', '#555'], pending: ['🟡 Words wait for Meta', '#fff8e6', '#8a5a00'], approved: ['🟢 Words approved', '#e7f8ee', '#13703a'], partial: ['🟢 Words approved', '#e7f8ee', '#13703a'], rejected: ['🔴 Meta said no to the words', '#fdecec', '#a12020'], paused: ['⏸ Paused by Meta', '#fdecec', '#a12020'] };
    const GO = { photo: ['📷 Goes with this photo', '#e7f8ee', '#13703a'], text: ['Goes without a photo', '#eef0f3', '#444'], text_until_photo: ['🟡 Photo version waits for Meta — goes without photo meanwhile', '#fff8e6', '#8a5a00'], wait: ['⏳ Waits for Meta', '#fff1f0', '#a12'], general: ['Uses the general “Ticket created” message', '#eef0f3', '#444'] };
    const GROUPS = [['first', '🎫 Ticket created — one per problem', null], ['journey', '🔁 Return & new goods — in this order', ['p_pickup', 'p_pickedup', 'p_transit', 'p_arrived', 'p_received', 'p_shipped', 'p_refund']], ['parcel', '📍 Parcel & delivery', ['p_tracking', 'p_reattempt']], ['general', '🙏 End of the ticket & other', null]];
    const thumbs = {}; const thumb = async (id) => { if (thumbs[id]) return thumbs[id]; const r = await fetch(api.buildUrl('wa.lib_file', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('no photo'); return (thumbs[id] = URL.createObjectURL(await r.blob())); };
    const pill = (x) => '<span style="background:' + x[1] + ';color:' + x[2] + ';border-radius:99px;padding:2px 9px;font-size:11.5px;font-weight:700;line-height:1.35">' + x[0] + '</span>';
    const draw = (openK) => {
      const ph = {}; P.items.forEach((x) => { ph[x.k] = x; }); const wd = {}; (M.msgs || []).forEach((m) => { wd[m.k] = m; });
      const lib = (P.lib || []).filter((x) => x.id !== P.care && x.id !== P.thanks);
      const sel = (x) => '<select class="cx-in" data-ph="' + x.k + '" style="flex:1 1 140px;max-width:260px;min-width:0"' + (mg ? '' : ' disabled') + '><option value="care"' + (x.choice === 'care' ? ' selected' : '') + '>Customer Care banner</option><option value="thanks"' + (x.choice === 'thanks' ? ' selected' : '') + '>Thank-you image</option><option value="none"' + (x.choice === 'none' ? ' selected' : '') + '>No photo</option>'
        + lib.map((l) => '<option value="' + l.id + '"' + (String(x.choice) === String(l.id) ? ' selected' : '') + '>📁 ' + esc(l.title) + '</option>').join('') + '</select>';
      const card = (k) => { const x = ph[k], m = wd[k]; if (!x) return ''; const src = x.choice === 'care' ? 'assets/img/cx_care.jpg' : (x.choice === 'thanks' ? 'assets/img/cx_thanks.jpg' : '');
        return '<div class="cx-row cm-card" data-mk="' + esc(k) + '" style="display:block;cursor:default"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
          + '<div class="cm-th" data-lib="' + (/^\d+$/.test(String(x.choice)) ? x.choice : '') + '" style="width:112px;height:58px;border-radius:8px;overflow:hidden;flex:none;background:#f2f3f5;display:grid;place-items:center;font-size:11px;color:#888">' + (src ? '<img src="' + src + '" alt="" style="width:100%;height:100%;object-fit:cover">' : (x.choice === 'none' ? 'no photo' : '…')) + '</div>'
          + '<div style="flex:1 1 200px;min-width:0"><b>' + esc(x.icon + ' ' + x.label) + '</b><div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">' + pill(GO[x.goes] || GO.wait) + (m ? pill(ST[m.status] || ST.none) : '') + (m && m.edited ? '<small style="color:var(--mut)">edited</small>' : '') + '</div></div>'
          + (mg ? '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;flex:1 1 230px;justify-content:flex-end">' + sel(x) + '<label class="cx-btn gh s" style="cursor:pointer" title="Upload a new photo for this message">＋ Photo<input type="file" accept="image/jpeg,image/png" data-up="' + esc(k) + '" hidden></label></div>' : '') + '</div>'
          + (m ? '<details style="margin-top:8px"' + (openK === k ? ' open' : '') + '><summary style="cursor:pointer;color:var(--mut);font-size:12.5px">✏️ The words</summary>' + (m.reason ? '<div class="cx-warn" style="margin:6px 0">Meta says: ' + esc(m.reason) + '</div>' : '') + (m.live && m.status !== 'approved' && m.status !== 'partial' ? '<small style="color:var(--mut)">The older approved words keep going out until these are approved.</small>' : '')
            + '<textarea class="cx-in" data-mb rows="4" style="width:100%;box-sizing:border-box;min-height:90px;margin-top:6px"' + (mg ? '' : ' disabled') + '>' + esc(m.body) + '</textarea>'
            + (mg ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"><button class="cx-btn gh s" type="button" data-ms>Save</button><button class="cx-btn s" type="button" data-mm>Save &amp; send to Meta</button>' + (m.edited ? '<button class="cx-btn gh s" type="button" data-mr>Back to our default</button>' : '') + '</div>' : '') + '</details>'
            : '<div style="margin-top:6px;color:var(--mut);font-size:12px">Fixed words (with the customer’s buttons) — only the photo changes here.</div>') + '</div>'; };
      el.innerHTML = '<div style="color:var(--mut);margin-bottom:8px">Every message here goes to the customer by itself, always as the version Meta approved (never a plain chat message). Choose the photo on top and the words, card by card. A new photo works at once; new words go to Meta first — until approved, the older approved words go. {{1}} name · {{2}} ticket · {{3}} order / detail (pickup date, tracking number…) · {{4}} who handles it.</div>'
        + GROUPS.map(([g, t, ks]) => { const L = ks ? ks.filter((k) => ph[k]) : P.items.filter((x) => x.group === g).map((x) => x.k); return L.length ? '<div style="font-weight:800;margin:14px 0 6px">' + t + '</div>' + L.map(card).join('') : ''; }).join('');
      el.querySelectorAll('.cm-th[data-lib]').forEach((t) => { const id = t.dataset.lib; if (!id) return; thumb(id).then((u) => { t.innerHTML = '<img src="' + u + '" alt="" style="width:100%;height:100%;object-fit:cover">'; }).catch(() => { t.textContent = 'photo missing'; }); });
    };
    const setPhoto = async (k, choice) => { const r = await call(() => api.post('bd.cx_photos', { k, choice })); if (r) { P = r; draw(); toast(r.made ? 'Saved ✓ — its photo version was sent to Meta' : 'Photo saved ✓'); } };
    el.onchange = async (ev) => {
      const s = ev.target.closest('[data-ph]'); if (s) { s.disabled = true; await setPhoto(s.dataset.ph, s.value); return; }
      const up = ev.target.closest('[data-up]'); if (up && up.files[0]) { const f = up.files[0], fd = new FormData(); fd.append('file', f); fd.append('title', 'Complaints · ' + f.name.replace(/\.[^.]+$/, ''));
        const r = await call(() => api.upload('wa.lib_add', fd)); if (r && r.id) await setPhoto(up.dataset.up, String(r.id)); }
    };
    el.onclick = async (ev) => {
      const b = ev.target.closest('[data-ms],[data-mm],[data-mr]'); if (!b) return; const box = b.closest('[data-mk]'), k = box.dataset.mk, m = (M.msgs || []).find((x) => x.k === k), ta = box.querySelector('[data-mb]');
      if (b.hasAttribute('data-mr')) { if (!confirm('Put back our default words? (Not sent to Meta until you press Save & send.)')) return; ta.value = m.def; }
      b.disabled = true; const r = await call(() => api.post('bd.cx_msgs', { key: k, body: ta.value, send: b.hasAttribute('data-mm') ? 1 : 0 })); b.disabled = false;
      if (r && r.msgs) { M.msgs = r.msgs; try { P = await api.get('bd.cx_photos'); } catch (e) { } draw(k); }
    };
    draw(); try { if (new URLSearchParams(location.search).get('go') === 'msgs') { const bx = $('#cxMsgsBox', H); if (bx) bx.scrollIntoView({ block: 'start' }); } } catch (e) { }   // link from WhatsApp → Replies → Automatic messages
  }
  async function quickEditor(mg) {
    const d = await api.get('bd.cx_quick').catch(() => null); const el = $('#stQ', H); if (!el || !d) return;
    el.innerHTML = d.quick.map((q) => '<div class="cx-row" style="cursor:default;align-items:flex-start"><div class="m"><b>' + esc(q.title) + '</b><small style="white-space:pre-wrap">' + esc(q.body) + '</small></div>' + (mg ? '<button class="cx-btn gh s" data-e="' + q.id + '" type="button">Edit</button>' : '') + '</div>').join('') + (mg ? '<div style="margin-top:10px"><button class="cx-btn gh s" type="button" id="qNew">＋ New quick reply</button></div>' : '');
    const edit = (q) => { const m = modal('<h3>' + (q ? 'Edit' : 'New') + ' quick reply</h3><label>Title</label><input class="cx-in" id="qT" value="' + esc(q ? q.title : '') + '"><label>Message</label><textarea id="qB" rows="4">' + esc(q ? q.body : '') + '</textarea><div class="ac">' + (q ? '<button class="cx-btn red" type="button" id="qDel">Delete</button>' : '') + '<button class="cx-btn gh" type="button" id="qNo">Cancel</button><button class="cx-btn" type="button" id="qOk">Save</button></div>'); $('#qNo', m.el).onclick = m.close; $('#qOk', m.el).onclick = async () => { const r = await call(() => api.post('bd.cx_quick_save', { id: q ? q.id : 0, title: $('#qT', m.el).value, body: $('#qB', m.el).value })); if (r) { m.close(); quickEditor(mg); } }; const dl = $('#qDel', m.el); if (dl) dl.onclick = async () => { const r = await call(() => api.post('bd.cx_quick_del', { id: q.id }), 'Deleted.'); if (r) { m.close(); quickEditor(mg); } }; };
    el.querySelectorAll('[data-e]').forEach((b) => b.onclick = () => edit(d.quick.find((x) => x.id === Number(b.dataset.e)))); const nw = $('#qNew', el); if (nw) nw.onclick = () => edit(null);
  }
  if (window.CXM) CXM.init({ esc, $, inr, hm, dm, ini, col, toast, call, modal, pill, uPill, sPill, STAT, URG, openCase, go, body, refresh, isOpen, deskSheet, resolveSheet, editSheet, noteSheet, loadThread,
    C: () => C, tab: () => S.tab, setTick: (fn, ms) => { clearInterval(timer); timer = setInterval(fn, ms); } });
  return { start, openCase };
})();
