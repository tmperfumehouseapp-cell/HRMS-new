/**
 * social_teach.js — Teach Claude, Quick reply, Needs you and Clean up for the Inbox hub.
 * Put it in  assets/js/  and load it AFTER the page's own script in social_inbox.html (one line before </body>).
 * It adds a thin bar above the Inbox hub (tabs + the black "Teach Claude" strip) and its own screens.
 * The existing Inbox screen is not changed: the "Inbox" tab simply shows it.
 * Server: social_learn.php (actions bd.sx_t_*).  Nothing is ever sent by itself: every send is a person's tap.
 */
(function () {
  'use strict';
  if (window.SXL || typeof api === 'undefined') return;

  /* ------------------------------------------------------------ tiny helpers ------------------------------------------------------------ */
  var A = 'bd.sx_t_';
  function get(a, p) { return api.get(A + a, p || {}); }
  function post(a, b) { return api.post(A + a, b || {}); }
  function el(tag, props) {
    var e = document.createElement(tag);
    if (props) for (var k in props) {
      var v = props[k];
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') e.className = v;
      else if (k === 'text') e.textContent = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
      else if (k === 'value') e.value = v;
      else e.setAttribute(k, v === true ? '' : v);
    }
    for (var i = 2; i < arguments.length; i++) add(e, arguments[i]);
    return e;
  }
  function add(e, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(e, x); }); return; }
    e.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
  }
  function clear(e) { while (e.firstChild) e.removeChild(e.firstChild); return e; }
  function firstName(w) {
    var n = String(w || '').replace(/^@/, '').trim().split(/\s+/)[0] || '';
    if (!n || /[\d_.]/.test(n)) return 'there';
    return n.charAt(0).toUpperCase() + n.slice(1);
  }
  function errText(e) { return (e && e.message) ? e.message : 'Something went wrong.'; }
  var toastTimer = null;
  function toast(msg, bad) {
    var t = document.getElementById('sxlToast');
    if (!t) { t = el('div', { id: 'sxlToast', class: 'sxl-toast' }); document.body.appendChild(t); }
    t.textContent = msg; t.className = 'sxl-toast show' + (bad ? ' bad' : '');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.className = 'sxl-toast' + (bad ? ' bad' : ''); }, bad ? 6000 : 3500);
  }
  function busy(btn, on, label) {
    if (!btn) return;
    if (on) { btn.dataset.l = btn.textContent; btn.disabled = true; if (label) btn.textContent = label; }
    else { btn.disabled = false; if (btn.dataset.l) btn.textContent = btn.dataset.l; }
  }
  function grow(ta) { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 2, 320) + 'px'; }
  function textarea(val, ph, rows) {
    var ta = el('textarea', { class: 'sxl-ta', rows: rows || 2, placeholder: ph || '' }); ta.value = val || '';
    ta.addEventListener('input', function () { grow(ta); }); setTimeout(function () { grow(ta); }, 0); return ta;
  }
  /* the microphone (speech to text) is only shown when the browser has it */
  function mic(ta) {
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition; if (!SR) return null;
    var lang = 'en-IN', rec = null, on = false;
    var lb = el('button', { type: 'button', class: 'sxl-mini', title: 'Language of your voice' }, 'EN');
    var mb = el('button', { type: 'button', class: 'sxl-mini mic', title: 'Speak instead of typing' }, '🎙');
    lb.addEventListener('click', function () { lang = lang === 'en-IN' ? 'hi-IN' : 'en-IN'; lb.textContent = lang === 'en-IN' ? 'EN' : 'हिं'; });
    mb.addEventListener('click', function () {
      if (on && rec) { rec.stop(); return; }
      rec = new SR(); rec.lang = lang; rec.interimResults = false; rec.continuous = true;
      rec.onresult = function (ev) { var t = ''; for (var i = ev.resultIndex; i < ev.results.length; i++) t += ev.results[i][0].transcript; ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + ' ' : '') + t.trim(); grow(ta); ta.dispatchEvent(new Event('input')); };
      rec.onend = function () { on = false; mb.classList.remove('rec'); }; rec.onerror = function () { on = false; mb.classList.remove('rec'); toast('The microphone did not work. Type it instead.', true); };
      try { rec.start(); on = true; mb.classList.add('rec'); } catch (e) { toast('The microphone did not start.', true); }
    });
    return el('span', { class: 'sxl-micbox' }, lb, mb);
  }

  /* ------------------------------------------------------------ state ------------------------------------------------------------ */
  var S = { ov: null, tab: 'inbox', denied: false, pend: { items: [], total: 0 }, lastBar: 0, facts: null, openTopic: 0,
    brain: null, brainLoading: false, rebuilding: false, rebuildErr: '', ask: null, askLoading: false, test: null, testLoading: false, report: null, practice: { text: '', replies: [], topic: '', seen: [] },
    q: { family: 'comments', n: 10, items: null, sel: {}, orig: {}, wording: '', loading: false, undo: null },
    nd: { items: null, sel: 0, filter: 'all', detail: false, loading: false },
    cl: { items: null, sel: {}, loading: false } };
  var wrap, bar, panel, inboxHost;

  /* ------------------------------------------------------------ styles ------------------------------------------------------------ */
  var CSS = [
    '.sxl-wrap{font-family:var(--font,Inter,system-ui,sans-serif);color:var(--ink,#14161a);padding:10px 12px 0;max-width:1400px;margin:0 auto}',
    '.sxl-tabs{display:flex;gap:8px;overflow-x:auto;padding:2px 0 8px;-webkit-overflow-scrolling:touch;scrollbar-width:none}.sxl-tabs::-webkit-scrollbar{display:none}',
    '.sxl-tab{flex:0 0 auto;border:1px solid var(--line,#e6e8ec);background:#fff;color:var(--ink,#14161a);border-radius:999px;padding:8px 15px;font:600 13px/1 inherit;font-family:inherit;cursor:pointer;min-height:36px;white-space:nowrap}',
    '.sxl-tab.on{background:#14161a;color:#fff;border-color:#14161a}.sxl-tab.warn{background:#fdecea;color:#b3261e;border-color:#f3c9c5}.sxl-tab.warn.on{background:#b3261e;color:#fff;border-color:#b3261e}',
    '.sxl-strip{display:flex;align-items:center;gap:6px 16px;flex-wrap:wrap;background:#14161a;color:#fff;border-radius:14px;padding:9px 14px;font-size:13px}',
    '.sxl-strip b{font-weight:700}.sxl-strip .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}',
    '.sxl-strip .grow{flex:1 1 auto}.sxl-strip button{background:#fff;color:#14161a;border:0;border-radius:9px;padding:7px 13px;font:700 13px/1 inherit;font-family:inherit;cursor:pointer;min-height:32px}',
    '.sxl-panel{background:#fff;border:1px solid var(--line,#e6e8ec);border-radius:18px;padding:14px;margin:10px 0 18px}',
    '.sxl-h{font-size:15px;font-weight:800;margin:0 0 4px}.sxl-sub{font-size:12.5px;color:var(--muted,#6f7480);margin:0 0 10px}',
    '.sxl-card{border:1px solid var(--line,#e6e8ec);border-radius:14px;padding:12px;margin:0 0 12px;background:#fff}',
    '.sxl-ta{width:100%;border:1px solid var(--line,#e6e8ec);border-radius:12px;padding:10px 12px;font:14px/1.45 inherit;font-family:inherit;resize:none;background:#fff;color:inherit;min-height:44px}.sxl-ta:focus,.sxl-in:focus{outline:2px solid rgba(255,122,47,.35)}',
    '.sxl-in{border:1px solid var(--line,#e6e8ec);border-radius:12px;padding:9px 12px;font:14px inherit;font-family:inherit;min-height:40px;background:#fff;color:inherit}',
    '.sxl-btn{background:#14161a;color:#fff;border:0;border-radius:11px;padding:10px 16px;font:700 13.5px/1 inherit;font-family:inherit;cursor:pointer;min-height:40px}.sxl-btn:disabled{opacity:.55;cursor:default}',
    '.sxl-btn.sec{background:#fff;color:#14161a;border:1px solid var(--line,#e6e8ec)}.sxl-btn.red{background:#b3261e}.sxl-btn.ok{background:#16945a}.sxl-btn.orange{background:var(--accent,#ff7a2f)}',
    '.sxl-mini{border:1px solid var(--line,#e6e8ec);background:#fff;border-radius:8px;padding:4px 8px;font:600 12px/1 inherit;font-family:inherit;cursor:pointer;min-height:30px}.sxl-mini.rec{background:#fdecea;border-color:#f3c9c5}',
    '.sxl-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.sxl-micbox{display:inline-flex;gap:4px;margin-left:6px}',
    '.sxl-chip{display:inline-block;border-radius:999px;padding:3px 9px;font-size:11.5px;font-weight:600;background:#f0f1f4;color:#4a4f5a;margin:0 4px 4px 0}',
    '.sxl-chip.g{background:#e4f6ec;color:#16945a}.sxl-chip.y{background:#fff4d6;color:#8a6100}.sxl-chip.r{background:#fdecea;color:#b3261e}.sxl-chip.b{background:#e6efff;color:#2455b8}',
    '.sxl-bul{margin:6px 0 10px;padding-left:18px;font-size:13.5px}.sxl-bul li{margin:2px 0}',
    '.sxl-q{background:#fff8e6;border:1px solid #f1e0a8;border-radius:12px;padding:9px 12px;font-size:13.5px;font-weight:600;margin-bottom:8px}',
    '.sxl-msg{background:#f1f2f4;border-radius:11px;padding:8px 11px;font-size:13.5px;margin:0 0 6px;overflow-wrap:anywhere}.sxl-msg.me{background:#e8f5ee;margin-left:28px}',
    '.sxl-opt{display:block;width:100%;text-align:left;border:1px solid var(--line,#e6e8ec);background:#fcfcfd;border-radius:12px;padding:10px 12px;font:13.5px/1.4 inherit;font-family:inherit;cursor:pointer;margin:0 0 8px;color:inherit}.sxl-opt:hover{border-color:#14161a}',
    '.sxl-grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}',
    '.sxl-sec{font-size:12px;font-weight:700;color:var(--muted,#6f7480);text-transform:uppercase;letter-spacing:.04em;margin:16px 0 8px}',
    '.sxl-seg{display:inline-flex;border:1px solid var(--line,#e6e8ec);border-radius:10px;overflow:hidden;background:#f4f5f7}.sxl-seg button{border:0;background:transparent;padding:7px 13px;font:600 13px/1 inherit;font-family:inherit;cursor:pointer;min-height:34px}.sxl-seg button.on{background:#fff;box-shadow:0 0 0 1px var(--line,#e6e8ec) inset;font-weight:800}',
    /* quick reply */
    '.sxl-wording{border:1.5px solid #2455b8;border-radius:14px;padding:10px 12px;margin:10px 0;background:#f7faff}.sxl-wording label{font-size:11.5px;font-weight:800;color:#2455b8;letter-spacing:.03em;display:block;margin-bottom:4px}',
    '.sxl-qrow{display:grid;grid-template-columns:28px minmax(0,1fr) minmax(0,1.1fr);gap:10px;align-items:start;border:1px solid var(--line,#e6e8ec);border-radius:14px;padding:10px;margin:0 0 8px;background:#fff}',
    '.sxl-qrow.off{opacity:.55}.sxl-qrow input[type=checkbox]{width:20px;height:20px;margin-top:2px;accent-color:#14161a}',
    '.sxl-who{font-size:13px;font-weight:700}.sxl-who small{font-weight:500;color:var(--muted,#6f7480)}.sxl-txt{font-size:13.5px;margin-top:2px;overflow-wrap:anywhere}',
    '.sxl-sendbar{position:sticky;bottom:0;background:#14161a;color:#fff;border-radius:14px;padding:11px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;z-index:5}.sxl-sendbar .grow{flex:1 1 200px;font-size:13px}.sxl-sendbar .sxl-btn{background:#fff;color:#14161a}',
    '.sxl-undo{background:#fff8e6;border:1px solid #f1e0a8;border-radius:12px;padding:10px 14px;display:flex;gap:10px;align-items:center;margin:8px 0}',
    /* needs you */
    '.sxl-nd{display:grid;grid-template-columns:minmax(260px,360px) minmax(0,1fr);gap:12px;align-items:start}',
    '.sxl-li{border:1px solid var(--line,#e6e8ec);border-radius:13px;padding:10px 12px;margin:0 0 8px;background:#fff;cursor:pointer}.sxl-li.on{border:1.5px solid #b3261e}.sxl-li .t{font-size:13px;color:#4a4f5a;margin-top:3px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
    '.sxl-back{display:none}.sxl-read{background:#fff8e6;border:1px solid #f1e0a8;border-radius:12px;padding:9px 12px;font-size:13.5px;margin:8px 0}',
    '.sxl-order{border:1px dashed #cfd3da;border-radius:11px;padding:8px 10px;font-size:13px;margin:6px 0}',
    '.sxl-empty{text-align:center;color:var(--muted,#6f7480);padding:30px 10px;font-size:14px}',
    '.sxl-load{color:var(--muted,#6f7480);padding:18px 4px;font-size:13.5px}',
    '.sxl-toast{position:fixed;left:50%;bottom:22px;transform:translate(-50%,30px);background:#14161a;color:#fff;border-radius:12px;padding:11px 16px;font-size:13.5px;max-width:92vw;opacity:0;pointer-events:none;transition:.2s;z-index:99999;text-align:center}.sxl-toast.show{opacity:1;transform:translate(-50%,0)}.sxl-toast.bad{background:#b3261e}',
    '.sxl-modal{position:fixed;inset:0;background:rgba(20,22,26,.5);z-index:9998;display:flex;align-items:center;justify-content:center;padding:12px}.sxl-sheet{background:#fff;border-radius:18px;max-width:680px;width:100%;max-height:92vh;overflow:auto;padding:16px;box-shadow:0 20px 60px rgba(0,0,0,.3)}',
    '.sxl-editbar{position:fixed;right:14px;bottom:14px;z-index:9997;background:#14161a;color:#fff;border-radius:12px;padding:6px 8px 6px 12px;display:flex;gap:8px;align-items:center;max-width:300px;box-shadow:0 6px 20px rgba(0,0,0,.28);font-size:12.5px}.sxl-editbar button{background:#fff;color:#14161a;border:0;border-radius:8px;padding:6px 10px;font:700 12.5px/1 inherit;font-family:inherit;cursor:pointer;min-height:30px}.sxl-editbar .x{background:transparent;color:#fff;padding:4px 8px}',
    '.sxl-was{border:1px solid var(--line,#e6e8ec);border-radius:12px;padding:9px 11px;margin:0 0 8px;font-size:13.5px;overflow-wrap:anywhere}.sxl-was small{display:block;font-weight:700;font-size:11.5px;color:var(--muted,#6f7480);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}.sxl-was.now{background:#e8f5ee;border-color:#bfe3cd}',
    '.sxl-bgrid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:14px;align-items:start}.sxl-sum{background:#fff8e6;border:1px solid #f1e0a8;border-radius:12px;padding:12px;font-size:14px;line-height:1.55}',
    '.sxl-learn{border-left:4px solid #16945a;background:#f7fbf8;border-radius:10px;padding:10px 12px;margin-bottom:10px}.sxl-learn.y{border-left-color:#f5b82e;background:#fffaf0}.sxl-learn small{display:block;color:#6f7480;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}',
    '.sxl-kpi{display:flex;gap:10px}.sxl-kpi div{flex:1;background:#f6f7f9;border-radius:12px;padding:10px;text-align:center}.sxl-kpi b{display:block;font-size:22px}.sxl-kpi span{font-size:11.5px;color:#6f7480}.sxl-meter{display:inline-block;width:90px;height:8px;background:#eef0f3;border-radius:6px;overflow:hidden}.sxl-meter i{display:block;height:100%}',
    '.sxl-qrow.hit{border-color:#16945a;background:#f3fbf6}',
    '.sxl-need{background:#fdecea;border:1px solid #f3c9c5;border-radius:12px;padding:10px 12px;margin:8px 0;font-size:13.5px}',
    '.sxl-frow{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;border-bottom:1px solid var(--line,#e6e8ec);padding:8px 0}.sxl-frow:last-child{border-bottom:0}.sxl-frow .tx{flex:1 1 240px;font-size:13.5px;overflow-wrap:anywhere}',
    '.sxl-thead{display:flex;gap:8px;align-items:center;flex-wrap:wrap;cursor:pointer}',
    '@media(max-width:900px){.sxl-bgrid{grid-template-columns:1fr}}',
    '@media(max-width:760px){.sxl-modal{align-items:flex-end;padding:0}.sxl-sheet{border-radius:18px 18px 0 0;max-height:94vh}.sxl-editbar{left:10px;right:10px;max-width:none;bottom:12px}',
    '.sxl-wrap{padding:8px 8px 0}.sxl-panel{padding:11px;border-radius:15px}.sxl-qrow{grid-template-columns:26px minmax(0,1fr)}.sxl-qrow .rep{grid-column:1 / -1}.sxl-grid2{grid-template-columns:1fr}',
    '.sxl-nd{grid-template-columns:1fr}.sxl-nd.det .list{display:none}.sxl-nd:not(.det) .dt{display:none}.sxl-back{display:inline-block;margin-bottom:8px}.sxl-strip{font-size:12.5px;padding:8px 11px}.sxl-strip .grow{display:none}.sxl-btn,.sxl-tab{min-height:42px}}'
  ].join('\n');

  /* ------------------------------------------------------------ mounting ------------------------------------------------------------ */
  function mount() {
    inboxHost = document.getElementById('sxHost'); if (!inboxHost) return false;
    var st = el('style', { id: 'sxlCss' }); st.textContent = CSS; document.head.appendChild(st);
    wrap = el('div', { class: 'sxl-wrap', id: 'sxlWrap' }); bar = el('div', { class: 'sxl-bar' }); panel = el('div', { class: 'sxl-panelhost' });
    wrap.appendChild(bar); wrap.appendChild(panel); inboxHost.parentNode.insertBefore(wrap, inboxHost);
    return true;
  }
  function setTab(t) {
    S.tab = t; inboxHost.style.display = t === 'inbox' ? '' : 'none'; renderBar(); renderPanel(); if (S.pend) showEditBar();
    if (t !== 'inbox') { try { window.scrollTo({ top: wrap.offsetTop - 8, behavior: 'smooth' }); } catch (e) {} }
  }
  function loadOv(cb) {
    return get('overview').then(function (o) { S.ov = o; S.denied = false; renderBar(); if (cb) cb(); }).catch(function (e) {
      if (!S.ov) { S.denied = true; wrap.style.display = 'none'; inboxHost.style.display = ''; }
    });
  }

  /* ------------------------------------------------------------ the bar: tabs + the thin black strip ------------------------------------------------------------ */
  function renderBar() {
    clear(bar); var o = S.ov; if (!o) return;
    var qn = (o.quick.comments || 0) + (o.quick.reviews || 0) + (o.quick.dms || 0) + (o.quick.mail || 0);
    function tab(k, label, n, warn) { return el('button', { type: 'button', class: 'sxl-tab' + (S.tab === k ? ' on' : '') + (warn && n ? ' warn' : ''), onclick: function () { setTab(k); } }, label + (n ? ' · ' + n : '')); }
    bar.appendChild(el('div', { class: 'sxl-tabs' }, tab('inbox', 'Inbox'), tab('quick', 'Quick reply', qn), tab('needs', '⚠ Needs you', o.needs, true), tab('clean', 'Clean up', o.cleanup)));
    var strip = el('div', { class: 'sxl-strip' }, el('b', {}, '🧠 Claude\'s brain' + (o.brain ? ' · v' + o.brain.version : '')));
    if (!o.voice) strip.appendChild(el('span', {}, 'It does not know how you talk yet'));
    else strip.appendChild(el('span', {}, el('span', { class: 'dot', style: 'background:#2ecc71' }), o.counts.understood + ' topic' + (o.counts.understood === 1 ? '' : 's') + ' clear'));
    if (o.voice) strip.appendChild(el('span', {}, el('span', { class: 'dot', style: 'background:#f5b82e' }), o.counts.learning + ' learning'));
    var nn = (o.counts.needs || 0) + (o.fact_needs || 0);
    if (o.voice && nn) strip.appendChild(el('span', {}, '🔴 ' + nn + ' needs your answer'));
    if (o.candidates) strip.appendChild(el('a', { href: '#', style: 'color:#ffd27a;font-weight:700;text-decoration:none', onclick: function (e) { e.preventDefault(); reviewFirst(); } }, '✎ ' + o.candidates + ' edit' + (o.candidates === 1 ? '' : 's') + ' to review'));
    if (!o.ai) strip.appendChild(el('span', { style: 'color:#ffb4ab' }, 'Claude is off (no key)'));
    strip.appendChild(el('span', { class: 'grow' }));
    strip.appendChild(el('button', { type: 'button', onclick: function () { setTab(S.tab === 'brain' ? 'inbox' : 'brain'); } }, S.tab === 'brain' ? 'Close ✕' : (o.voice ? 'Open ›' : 'Start ›')));
    bar.appendChild(strip);
  }
  function renderPanel() {
    clear(panel);
    if (S.tab === 'inbox') return;
    var box = el('div', { class: 'sxl-panel' }); panel.appendChild(box);
    ({ brain: teachView, quick: quickView, needs: needsView, clean: cleanView })[S.tab](box);
  }

  /* ============================================================ CLAUDE'S BRAIN: one page, one learning ============================================================ */
  function teachView(box) {
    var B = S.brain;
    box.appendChild(el('h3', { class: 'sxl-h' }, 'What I understand about TM Perfume House'));
    box.appendChild(el('p', { class: 'sxl-sub' }, 'One page. Everything I write is made from this. Correct it here and every reply changes.'));
    box.appendChild(el('p', { class: 'sxl-sub' }, 'To teach something new — a whole process, long instructions or screenshots — use the Teach screen (Learn & Setup → Teach). It shares this one brain.'));
    if (!B) { box.appendChild(el('div', { class: 'sxl-load' }, 'Loading…')); loadBrain(); return; }
    var g = el('div', { class: 'sxl-bgrid' }), L = el('div', {}), R = el('div', {}); g.appendChild(L); g.appendChild(R); box.appendChild(g);
    summaryCard(L); feedCard(L); askCard(L); testCard(L); practiceCard(L); reportCard(L); weekCard(R); topicList(R); factsCard(R); if (B.mgr) companyCard(L);
  }
  function loadBrain() {
    if (S.brainLoading) return; S.brainLoading = true;
    get('brain_get').then(function (r) { S.brainLoading = false; S.brain = r; if (S.tab === 'brain') renderPanel(); if ((r.dirty || !r.brain) && r.ai) rebuildBrain(); })
      .catch(function (e) { S.brainLoading = false; S.brain = { err: errText(e), brain: null, topics: [], events: [], pending: [], stats: {}, ai: false, mgr: false, dirty: false }; if (S.tab === 'brain') renderPanel(); });
  }
  function rebuildBrain() {
    if (S.rebuilding) return; S.rebuilding = true; fillSum();
    post('brain_rebuild').then(function (r) { S.rebuilding = false; S.rebuildErr = ''; S.brain.brain = r.brain; S.brain.dirty = false; fillSum(); loadOv(); }).catch(function (e) { S.rebuilding = false; S.rebuildErr = errText(e); fillSum(); });
  }
  /* everything that was learned goes through here: the log, the numbers and the summary are refreshed */
  function afterLearn() {
    S.facts = null; S.brainLoading = false; S.q.items = null; S.nd.items = null; loadOv(); pollPending();   // what Claude knows changed: Quick reply and Needs you are read again, so the new replies show
    if (S.tab !== 'brain') { S.brain = null; return; }
    var y = window.scrollY; get('brain_get').then(function (r) { S.brain = r; renderPanel(); window.scrollTo(0, y); if (r.dirty && r.ai) rebuildBrain(); }).catch(function () {});
  }
  var KIND = { edit: '✎ You changed a reply', fact: '📚 A new fact', answer: '💬 You answered me', practice: '🧪 From practice', fix: '🛠 You corrected me' };

  function summaryCard(box) { var c = el('div', { class: 'sxl-card', id: 'sxlSum' }); box.appendChild(c); fillSum(); }
  function fillSum() {
    var c = document.getElementById('sxlSum'); if (!c || !S.brain) return; var B = S.brain, b = B.brain; clear(c);
    c.appendChild(el('div', { class: 'sxl-h' }, '📝 In short ', b ? el('span', { class: 'sxl-chip b' }, 'version ' + b.version) : null, b && b.approved ? el('span', { class: 'sxl-chip g' }, 'confirmed ✓') : null));
    if (S.rebuilding) c.appendChild(el('div', { class: 'sxl-load' }, 'Updating my summary…'));
    if (b) c.appendChild(el('div', { class: 'sxl-sum' }, b.summary));
    else if (!S.rebuilding) c.appendChild(el('div', { class: 'sxl-sub' }, B.err || 'I have not written my summary yet.'));
    if (b && b.source === 'plain') c.appendChild(el('div', { class: 'sxl-sub', style: 'margin-top:6px' }, 'Claude could not write the summary just now, so this is the plain list of what you taught.'));
    if (S.rebuildErr && !S.rebuilding) c.appendChild(el('div', { class: 'sxl-need' }, S.rebuildErr + ' ', el('button', { type: 'button', class: 'sxl-mini', onclick: function () { S.rebuildErr = ''; rebuildBrain(); } }, 'Try again')));
    if (!B.has_voice) c.appendChild(el('div', { class: 'sxl-need' }, 'I do not know how you talk yet. Press “Fix something” and describe it in your own words.'));
    if (!B.mgr) { c.appendChild(el('div', { class: 'sxl-sub', style: 'margin-top:8px' }, 'Only the Inbox boss or Admin can correct me.')); return; }
    var fixBox = el('div', {}); var row = el('div', { class: 'sxl-row', style: 'margin-top:8px' });
    if (b && !b.approved) row.appendChild(el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () { post('brain_confirm', {}).then(function (r) { toast(r.message); b.approved = true; fillSum(); }).catch(function (e) { toast(errText(e), true); }); } }, '✓ Looks right'));
    row.appendChild(el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { openFix(fixBox, ''); } }, '✎ Fix something')); c.appendChild(row); c.appendChild(fixBox);
    if (!B.has_voice) openFix(fixBox, '');
  }
  function openFix(fixBox, prev) {
    clear(fixBox); var B = S.brain;
    fixBox.appendChild(el('div', { class: 'sxl-sub', style: 'margin:10px 0 4px' }, prev ? 'Tell me what I got wrong:' : (B.has_voice ? 'What is wrong or missing? For example: for short compliments reply in 3–6 words with 😂; always say sir.' : 'Tell me how we talk: friendly or formal, short or long, emojis, Hindi or English, how we address people…')));
    var ta = textarea('', '', 3); fixBox.appendChild(ta);
    var go = el('button', { type: 'button', class: 'sxl-btn', onclick: function () {
      if (!ta.value.trim()) { toast('Write something first.', true); return; } var note = (prev ? prev + '\nCORRECTION: ' : '') + ta.value; busy(go, true, 'Claude is reading…');
      post('brain_fix_read', { note: note }).then(function (r) { fixPreview(fixBox, r, note); }).catch(function (e) { busy(go, false); toast(errText(e), true); });
    } }, 'Show what you understood');
    fixBox.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, go, mic(ta), el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { clear(fixBox); } }, 'Cancel')));
  }
  function fixPreview(fixBox, r, note) {
    clear(fixBox); var picks = [];
    fixBox.appendChild(el('div', { class: 'sxl-read' }, el('b', {}, 'I understood this:'), el('ul', { class: 'sxl-bul' }, (r.understood || []).map(function (u) { return el('li', {}, u); }))));
    if (r.voice) fixBox.appendChild(el('div', { class: 'sxl-was now' }, el('small', {}, 'New voice'), r.voice));
    function line(kind, x) { var cb = el('input', { type: 'checkbox' }); cb.checked = true; var inp = el('input', { class: 'sxl-in', style: 'flex:1 1 240px', value: x.text }); picks.push({ kind: kind, cb: cb, inp: inp, topic_id: x.topic_id }); fixBox.appendChild(el('div', { class: 'sxl-row', style: 'margin-bottom:6px' }, cb, el('span', { class: 'sxl-chip' + (x.topic_id ? ' b' : '') }, (kind === 'rule' ? 'Rule · ' : 'Fact · ') + x.topic), inp)); }
    (r.rules || []).forEach(function (x) { line('rule', x); }); (r.facts || []).forEach(function (x) { line('fact', x); });
    var keep = el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () {
      busy(keep, true, 'Saving…'); var sel = function (k) { return picks.filter(function (p) { return p.kind === k && p.cb.checked && p.inp.value.trim(); }).map(function (p) { return { topic_id: p.topic_id, text: p.inp.value.trim() }; }); };
      post('brain_fix_save', { voice: r.voice || '', rules: sel('rule'), facts: sel('fact') }).then(function (x) { toast(x.message); afterLearn(); }).catch(function (e) { busy(keep, false); toast(errText(e), true); });
    } }, 'Keep — learn this');
    fixBox.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, keep, el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { openFix(fixBox, note); } }, 'Not quite…'), el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { clear(fixBox); } }, 'Cancel')));
  }

  function feedCard(box) {
    var B = S.brain, c = el('div', { class: 'sxl-card' }); box.appendChild(c); var n = (B.pending || []).length;
    c.appendChild(el('div', { class: 'sxl-h' }, '💡 What I learned ', n ? el('span', { class: 'sxl-chip y' }, n + ' to confirm') : null));
    if (!n && !(B.events || []).length) c.appendChild(el('div', { class: 'sxl-sub' }, 'Nothing yet. When someone edits a reply, answers my question or adds a fact, it shows here and I tell you what I now understand.'));
    (B.pending || []).forEach(function (x) {
      var d = el('div', { class: 'sxl-learn y' }, el('small', {}, '✎ You changed a reply · ' + x.label), el('div', { class: 'sxl-msg' }, '“' + (x.cust_text || '') + '”'), el('div', { class: 'sxl-was' }, el('small', {}, 'Before — I wrote'), x.draft || ''), el('div', { class: 'sxl-was now' }, el('small', {}, 'Now — you sent'), x.reply));
      d.appendChild(el('div', { class: 'sxl-row' }, el('button', { type: 'button', class: 'sxl-btn', onclick: function () { openRead(x.id); } }, 'See what I understood ›'), el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { post('edit_decide', { id: x.id, mode: 'once' }).then(function () { afterLearn(); }).catch(function (e) { toast(errText(e), true); }); } }, 'Only this time'))); c.appendChild(d);
    });
    (B.events || []).forEach(function (e) {
      var d = el('div', { class: 'sxl-learn' }, el('small', {}, (KIND[e.kind] || 'Learned') + (e.by_name ? ' · ' + e.by_name : '') + ' · ' + String(e.created_at).slice(5, 16)), el('b', {}, e.title || ''));
      if (e.was_text) d.appendChild(el('div', { class: 'sxl-was' }, el('small', {}, 'Before'), e.was_text)); if (e.now_text) d.appendChild(el('div', { class: 'sxl-was now' }, el('small', {}, 'Now I understand'), e.now_text));
      if (B.mgr) d.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { post('event_undo', { id: e.id }).then(function (r) { toast(r.message); afterLearn(); }).catch(function (x) { toast(errText(x), true); }); } }, 'Undo')); c.appendChild(d);
    });
  }

  function askCard(box) { var c = el('div', { class: 'sxl-card', id: 'sxlAsk' }); box.appendChild(c); fillAsk(); if (!S.ask && !S.askLoading) loadAsk(); }
  function loadAsk() { S.askLoading = true; fillAsk(); get('ask_next').then(function (r) { S.askLoading = false; S.ask = { q: r.q, msg: r.message }; fillAsk(); }).catch(function (e) { S.askLoading = false; S.ask = { err: errText(e) }; fillAsk(); }); }
  function fillAsk() {
    var c = document.getElementById('sxlAsk'); if (!c) return; clear(c); c.appendChild(el('div', { class: 'sxl-h' }, '💬 Claude asks'));
    if (S.askLoading || !S.ask) { c.appendChild(el('div', { class: 'sxl-load' }, 'Reading your chats to find my best question…')); return; }
    if (S.ask.err) { c.appendChild(el('div', { class: 'sxl-need' }, S.ask.err + ' ', el('button', { type: 'button', class: 'sxl-mini', onclick: function () { S.ask = null; loadAsk(); } }, 'Try again'))); return; }
    if (!S.ask.q) { c.appendChild(el('div', { class: 'sxl-sub' }, '✅ ' + (S.ask.msg || 'I have no new question right now.'))); c.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { S.ask = null; loadAsk(); } }, 'Check again')); return; }
    var q = S.ask.q; c.appendChild(el('span', { class: 'sxl-chip b' }, q.topic)); c.appendChild(el('div', { class: 'sxl-q' }, q.question)); if (q.why) c.appendChild(el('div', { class: 'sxl-sub' }, 'Why I ask: ' + q.why));
    function answer(text, skip, btn) { busy(btn, true, '…'); post('ask_answer', { id: q.id, answer: text, skip: skip ? 1 : 0 }).then(function (r) { toast(r.message); S.ask = null; S.q.items = null; S.nd.items = null; afterLearn(); loadAsk(); }).catch(function (e) { busy(btn, false); toast(errText(e), true); }); }
    if ((q.suggestions || []).length) { var g = el('div', { class: 'sxl-grid2' }); c.appendChild(g); q.suggestions.forEach(function (s2, i) { var b = el('button', { type: 'button', class: 'sxl-opt', onclick: function () { answer(s2, false, b); } }, (i + 1) + ' · ' + s2); g.appendChild(b); }); }
    var ta = textarea('', 'None of these — type it or say it', 2); var go = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { if (!ta.value.trim()) { toast('Type the answer first.', true); return; } answer(ta.value, false, go); } }, 'Use my answer'); var sk = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { answer('', true, sk); } }, 'Skip');
    c.appendChild(ta); c.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, go, mic(ta), sk));
  }

  function testCard(box) { var c = el('div', { class: 'sxl-card', id: 'sxlTest' }); box.appendChild(c); fillTest(); }
  function fillTest() {
    var c = document.getElementById('sxlTest'); if (!c) return; clear(c); c.appendChild(el('div', { class: 'sxl-h' }, '🧪 Test me'));
    c.appendChild(el('div', { class: 'sxl-sub' }, 'See how I would reply to real recent messages right now. If I am wrong, change it and I will learn.'));
    if (S.testLoading) { c.appendChild(el('div', { class: 'sxl-load' }, 'Writing 3 replies…')); return; }
    if (!S.test) { var b = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { S.testLoading = true; fillTest(); get('test_me').then(function (r) { S.testLoading = false; S.test = r.items || []; fillTest(); }).catch(function (e) { S.testLoading = false; S.test = null; fillTest(); toast(errText(e), true); }); } }, 'Test me on 3 real messages'); c.appendChild(b); return; }
    if (!S.test.length) c.appendChild(el('div', { class: 'sxl-sub' }, 'No recent messages to test on.'));
    S.test.forEach(function (it) {
      var d = el('div', { class: 'sxl-card', style: 'background:#fcfcfd' }); c.appendChild(d);
      d.appendChild(el('span', { class: 'sxl-chip' }, it.topic)); d.appendChild(el('div', { class: 'sxl-msg' }, '“' + it.text + '”')); d.appendChild(el('div', { class: 'sxl-was now' }, el('small', {}, 'How I would reply now'), it.now));
      if (it.sent) d.appendChild(el('div', { class: 'sxl-was' }, el('small', {}, 'What we really sent'), it.sent)); var rowb = el('div', { class: 'sxl-row' }); d.appendChild(rowb); var box2 = el('div', {}); d.appendChild(box2);
      rowb.appendChild(el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () { S.test = S.test.filter(function (x) { return x.id !== it.id; }); fillTest(); } }, '✓ Good'));
      rowb.appendChild(el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () {
        clear(box2); var ta = textarea(it.sent || it.now, '', 2); var sv = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { busy(sv, true, '…'); post('test_fix', { id: it.id, text: ta.value, now: it.now }).then(function (r) { S.test = S.test.filter(function (x) { return x.id !== it.id; }); fillTest(); openRead(r.example_id); }).catch(function (e) { busy(sv, false); toast(errText(e), true); }); } }, 'Save my version');
        box2.appendChild(ta); box2.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, sv, mic(ta)));
      } }, '✎ Change it'));
    });
  }

  /* Ask Claude + the report: the same brain answers, and the report can be assigned to the boss or a person (it also lands in the Boss Engine "Needs you" list) */
  function reportCard(box) {
    var B = S.brain; if (!B.mgr) return; var c = el('div', { class: 'sxl-card' }); box.appendChild(c); c.appendChild(el('div', { class: 'sxl-h' }, '🗣 Ask Claude · 📄 Report'));
    c.appendChild(el('div', { class: 'sxl-sub' }, 'Ask about your inbox in plain words (for example: which topic gets the most unhappy customers?). I answer from your real messages and from what you taught me.'));
    var q = el('input', { class: 'sxl-in', style: 'flex:1 1 260px', placeholder: 'Ask a question…' }); var out = el('div', {}); var ask = el('button', { type: 'button', class: 'sxl-btn', onclick: function () {
      if (!q.value.trim()) { toast('Write your question.', true); return; } busy(ask, true, 'Thinking…');
      api.post('bd.sx_ask', { q: q.value }).then(function (r) { clear(out); out.appendChild(el('div', { class: 'sxl-sum', style: 'margin-top:8px;white-space:pre-wrap' }, r.answer)); }).catch(function (e) { toast(errText(e), true); }).then(function () { busy(ask, false); });
    } }, 'Ask'); c.appendChild(el('div', { class: 'sxl-row' }, q, ask)); c.appendChild(out);
    var rep = el('div', {}); c.appendChild(rep);
    function fillRep() {
      clear(rep);
      if (!S.report) { var gb = el('button', { type: 'button', class: 'sxl-btn sec', style: 'margin-top:10px', onclick: function () { busy(gb, true, 'Writing the report…'); api.get('bd.sx_summary', { days: 7 }).then(function (r) { S.report = r.text; fillRep(); }).catch(function (e) { busy(gb, false); toast(errText(e), true); }); } }, '📄 Write this week’s report'); rep.appendChild(gb); return; }
      rep.appendChild(el('div', { class: 'sxl-sum', style: 'margin-top:10px;white-space:pre-wrap' }, S.report));
      var sel = el('select', { class: 'sxl-in' }, el('option', { value: 0 }, 'To the boss'), (B.team || []).map(function (t) { return el('option', { value: t.id }, 'To ' + t.name); }));
      var send = el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () { busy(send, true, 'Sending…'); post('report_send', { text: S.report, emps: parseInt(sel.value, 10) ? [parseInt(sel.value, 10)] : [] }).then(function (r) { toast(r.message); }).catch(function (e) { toast(errText(e), true); }).then(function () { busy(send, false); }); } }, 'Send report');
      rep.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, sel, send, el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { S.report = null; fillRep(); } }, 'Write it again')));
    }
    fillRep();
  }

  function weekCard(box) {
    var st = S.brain.stats || {}, c = el('div', { class: 'sxl-card' }); box.appendChild(c); c.appendChild(el('div', { class: 'sxl-h' }, '📊 This week'));
    c.appendChild(el('div', { class: 'sxl-kpi' }, el('div', {}, el('b', {}, st.same_pct === null || st.same_pct === undefined ? '—' : st.same_pct + '%'), el('span', {}, 'sent unchanged')), el('div', {}, el('b', {}, String(st.learned || 0)), el('span', {}, 'things learned')), el('div', {}, el('b', {}, String(st.unsure || 0)), el('span', {}, 'unsure'))));
    if (st.prev_pct !== null && st.prev_pct !== undefined) c.appendChild(el('div', { class: 'sxl-sub', style: 'margin:8px 0 0' }, 'Last week: ' + st.prev_pct + '% sent unchanged.'));
  }

  function practiceCard(box) {
    var P = S.practice, c = el('div', { class: 'sxl-card' }); box.appendChild(c);
    c.appendChild(el('div', { class: 'sxl-h' }, '✍ Try your own message'));
    c.appendChild(el('div', { class: 'sxl-sub' }, 'Type a message a customer might send. Claude shows replies in different tones; tap the one you like, or edit it.'));
    var ta = textarea(P.text, 'e.g. Bhai original hai kya? Kitne din chalega?', 2);
    var go = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { run(false); } }, 'Show replies');
    function run(more) {
      if (!ta.value.trim()) { toast('Type a customer message first.', true); return; }
      P.text = ta.value; busy(go, true, 'Claude is writing…');
      post('practice', { text: P.text, exclude: more ? P.seen : [] }).then(function (r) { P.topic = r.topic; P.replies = r.replies; r.replies.forEach(function (x) { P.seen.push(x.text); }); if (!more) P.seen = r.replies.map(function (x) { return x.text; }); renderPanel(); }).catch(function (e) { busy(go, false); toast(errText(e), true); });
    }
    c.appendChild(ta); c.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, go, mic(ta)));
    if (P.replies.length) {
      c.appendChild(el('div', { class: 'sxl-sec' }, 'Topic: ' + P.topic));
      P.replies.forEach(function (r) { c.appendChild(practiceRow(r)); });
      c.appendChild(el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { run(true); } }, 'Show 6 more'));
    }
  }
  function practiceRow(r) {
    var row = el('div', { class: 'sxl-card', style: 'background:#fcfcfd' }); var P = S.practice;
    row.appendChild(el('span', { class: 'sxl-chip b' }, r.tone));
    var body = el('div', { class: 'sxl-txt', style: 'margin:4px 0 8px' }, r.text); row.appendChild(body);
    function approve(text, edited, btn) { busy(btn, true, 'Saving…'); post('approve', { text: P.text, reply: text, draft: r.text, edited: edited ? 1 : 0 }).then(function (x) { toast(x.message); afterLearn(); }).catch(function (e) { toast(errText(e), true); }).then(function () { busy(btn, false); }); }
    var use = el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () { approve(r.text, false, use); } }, 'Use this');
    var ed = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () {
      clear(row); row.appendChild(el('span', { class: 'sxl-chip b' }, r.tone)); var ta = textarea(r.text, '', 3); row.appendChild(ta);
      var sv = el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () { approve(ta.value, ta.value.trim() !== r.text.trim(), sv); } }, 'Approve my version');
      row.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, sv, mic(ta)));
    } }, 'Edit');
    row.appendChild(el('div', { class: 'sxl-row' }, use, ed)); return row;
  }

  /* ------------ what Claude understood from an edited reply (the read-back card) ------------ */
  function closeRead() { var m = document.getElementById('sxlRead'); if (m) m.parentNode.removeChild(m); }
  function reviewFirst() { get('candidates').then(function (r) { if (r.items && r.items.length) openRead(r.items[0].id); else toast('Nothing to review.'); }).catch(function (e) { toast(errText(e), true); }); }
  function openRead(id, note) {
    closeRead(); var m = el('div', { class: 'sxl-modal', id: 'sxlRead' }), card = el('div', { class: 'sxl-sheet' }, el('div', { class: 'sxl-load' }, 'Claude is reading your edit…')); m.appendChild(card); document.body.appendChild(m);
    m.addEventListener('click', function (e) { if (e.target === m) closeRead(); });
    post('edit_read', { id: id, note: note || '' }).then(function (r) { renderRead(card, r, !!note); pollPending(); }).catch(function (e) { closeRead(); toast(errText(e), true); });
  }
  function renderRead(card, r, again) {
    clear(card);
    card.appendChild(el('div', { class: 'sxl-row', style: 'justify-content:space-between;flex-wrap:nowrap;align-items:flex-start' }, el('div', { class: 'sxl-h' }, again ? 'Now I understood this' : 'You changed a reply — here is what I understood'), el('button', { type: 'button', class: 'sxl-mini', onclick: closeRead }, '✕')));
    card.appendChild(el('span', { class: 'sxl-chip b' }, 'Topic: ' + r.topic));
    if (r.cust) card.appendChild(el('div', { class: 'sxl-msg' }, '“' + r.cust + '”'));
    card.appendChild(el('div', { class: 'sxl-was' }, el('small', {}, 'Before — Claude wrote'), r.was || '(nothing)'));
    card.appendChild(el('div', { class: 'sxl-was now' }, el('small', {}, 'Now — you sent'), r.now));
    card.appendChild(el('div', { class: 'sxl-read' }, el('b', {}, 'What I understood:'), el('ul', { class: 'sxl-bul' }, (r.understood || []).map(function (u) { return el('li', {}, u); }))));
    var ruleCb = null, ruleInp = null;
    if (r.rule) {
      ruleCb = el('input', { type: 'checkbox' }); ruleCb.checked = true; ruleInp = el('input', { class: 'sxl-in', style: 'flex:1 1 260px', value: r.rule });
      card.appendChild(el('div', { class: 'sxl-sec', style: 'margin-top:6px' }, 'The rule I will follow for this kind of message (edit it if wrong)')); card.appendChild(el('div', { class: 'sxl-row', style: 'margin-bottom:8px' }, ruleCb, ruleInp));
    }
    var facts = []; var answers = [];
    if ((r.facts || []).length) {
      card.appendChild(el('div', { class: 'sxl-sec', style: 'margin-top:6px' }, 'New facts I noticed (untick if wrong)'));
      r.facts.forEach(function (f) { var cb = el('input', { type: 'checkbox' }); cb.checked = true; var inp = el('input', { class: 'sxl-in', style: 'flex:1 1 220px', value: f }); facts.push({ cb: cb, inp: inp }); card.appendChild(el('div', { class: 'sxl-row', style: 'margin-bottom:6px' }, cb, inp)); });
    }
    if ((r.questions || []).length) {
      card.appendChild(el('div', { class: 'sxl-sec', style: 'margin-top:6px' }, 'Claude has ' + (r.questions.length === 1 ? 'a question' : 'questions')));
      r.questions.forEach(function (q) { var inp = el('input', { class: 'sxl-in', style: 'width:100%', placeholder: 'Your answer (or leave empty)' }); answers.push({ q: q, inp: inp }); card.appendChild(el('div', { style: 'margin-bottom:8px' }, el('div', { class: 'sxl-sub', style: 'margin:0 0 4px' }, 'If a customer asks: “' + q + '” — what should I say?'), inp)); });
    }
    var yes = el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () {
      busy(yes, true, 'Saving…');
      post('edit_decide', { id: r.id, mode: 'always', rule: ruleCb && ruleCb.checked ? ruleInp.value.trim() : '', facts: facts.filter(function (f) { return f.cb.checked && f.inp.value.trim(); }).map(function (f) { return f.inp.value.trim(); }), answers: answers.filter(function (a) { return a.inp.value.trim(); }).map(function (a) { return { q: a.q, a: a.inp.value.trim() }; }) })
        .then(function (x) { toast(x.message); closeRead(); afterLearn(); }).catch(function (e) { busy(yes, false); toast(errText(e), true); });
    } }, 'Yes, learn this for the topic');
    var once = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { post('edit_decide', { id: r.id, mode: 'once' }).then(function (x) { toast(x.message); closeRead(); afterLearn(); }).catch(function (e) { toast(errText(e), true); }); } }, 'Only this time');
    var box = el('div', {}); var not = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () {
      clear(box); var ta = textarea('', 'Tell me what I got wrong. For example: keep it even shorter, no emojis, always say sir.', 2); var go = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { if (!ta.value.trim()) { toast('Write what I got wrong.', true); return; } openRead(r.id, ta.value); } }, 'Read again');
      box.appendChild(ta); box.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, go, mic(ta))); ta.focus();
    } }, 'Not quite…');
    card.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:10px' }, yes, once, not)); card.appendChild(box);
  }
  /* the small bar that appears when someone edits a reply in the normal Inbox reply box */
  function pollPending() {
    if (S.denied || !S.ov) return;
    get('pending').then(function (r) { S.pend = r; showEditBar(); if (S.ov && S.ov.candidates !== r.total) { S.ov.candidates = r.total; renderBar(); } }).catch(function () {});
  }
  function showEditBar() {
    var b = document.getElementById('sxlEditBar'); var it = S.pend && S.pend.items ? S.pend.items[0] : null;
    if (!it || it.id <= S.lastBar || S.tab !== 'inbox') { if (b) b.parentNode.removeChild(b); return; }   // shown only on the normal Inbox tab, so it never covers the Send bar of Quick reply
    if (b) b.parentNode.removeChild(b);
    b = el('div', { class: 'sxl-editbar', id: 'sxlEditBar' }, el('span', {}, '✎ Reply edited · ' + it.topic), el('button', { type: 'button', title: 'See what Claude understood from your edit', onclick: function () { S.lastBar = it.id; showEditBar(); openRead(it.id); } }, 'Review'), el('button', { type: 'button', class: 'x', onclick: function () { S.lastBar = it.id; showEditBar(); } }, '✕'));
    document.body.appendChild(b);
  }

  /* ------------ the facts list ------------ */
  function factsCard(box) { var c = el('div', { class: 'sxl-card', id: 'sxlFacts' }); box.appendChild(c); if (S.facts) fillFacts(c); else { c.appendChild(el('div', { class: 'sxl-load' }, 'Loading facts…')); loadFacts(); } }
  function loadFacts() { get('facts_list').then(function (r) { S.facts = r; var c = document.getElementById('sxlFacts'); if (c && S.tab === 'brain') fillFacts(c); }).catch(function () {}); }
  function fillFacts(c) {
    var F = S.facts; clear(c); c.appendChild(el('div', { class: 'sxl-h' }, '📚 What I know'));
    c.appendChild(el('div', { class: 'sxl-sub' }, 'The only facts about your business Claude is allowed to say. If something is not here, Claude does not guess: the message waits in “Needs you”. Do not type prices or stock here.'));
    if (!F.facts.length) c.appendChild(el('div', { class: 'sxl-sub' }, 'No facts yet. Add the first one below, for example “We have one store: Vesu, Surat.”'));
    F.facts.filter(function (f) { return String(f.text).indexOf('Style rule:') !== 0; }).forEach(function (f) {
      var tx = el('div', { class: 'tx' }, f.text); var row = el('div', { class: 'sxl-frow' }, tx);
      row.appendChild(el('span', { class: 'sxl-chip' + (f.topic_id ? ' b' : '') }, f.topic)); if (f.scope === 'company') row.appendChild(el('span', { class: 'sxl-chip g' }, f.shareable ? '🏢 company · customers may be told' : '🏢 company · team only')); row.appendChild(el('span', { class: 'sxl-chip' + (f.stale ? ' y' : '') }, (f.by ? f.by + ' · ' : '') + f.at + (f.stale ? ' · confirm it is still true' : '')));
      if (F.mgr) {
        row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { clear(tx); var inp = el('input', { class: 'sxl-in', style: 'width:100%', value: f.text }); tx.appendChild(inp); var sv = el('button', { type: 'button', class: 'sxl-mini', onclick: function () { post('fact_save', { id: f.id, text: inp.value, topic_id: f.topic_id }).then(function (r) { toast(r.message); loadFacts(); }).catch(function (e) { toast(errText(e), true); }); } }, 'Save'); tx.appendChild(sv); inp.focus(); } }, 'Edit'));
        if (f.stale) row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { post('fact_confirm', { id: f.id }).then(function () { loadFacts(); }); } }, 'Still true'));
        row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { if (window.confirm('Remove this fact? Claude will stop saying it.')) post('fact_delete', { id: f.id }).then(function (r) { toast(r.message); loadFacts(); }).catch(function (e) { toast(errText(e), true); }); } }, 'Delete'));
      }
      c.appendChild(row);
    });
    var inp = el('input', { class: 'sxl-in', style: 'flex:1 1 260px', placeholder: 'A new fact, one short sentence' }); var sel = el('select', { class: 'sxl-in' }, el('option', { value: 0 }, 'General (all topics)'), F.topics.map(function (t) { return el('option', { value: t.id }, t.label); }));
    var add = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { busy(add, true, '…'); post('fact_save', { text: inp.value, topic_id: parseInt(sel.value, 10) || 0 }).then(function (r) { toast(r.message); loadFacts(); }).catch(function (e) { busy(add, false); toast(errText(e), true); }); } }, 'Add fact');
    c.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:10px' }, inp, sel, add));
    if ((F.bot || []).length) { c.appendChild(el('div', { class: 'sxl-sec' }, 'From the WhatsApp bot (Teach the bot) — read only')); F.bot.forEach(function (b) { c.appendChild(el('div', { class: 'sxl-frow' }, el('div', { class: 'tx' }, b.text), el('span', { class: 'sxl-chip' }, b.source))); }); c.appendChild(el('div', { class: 'sxl-sub', style: 'margin:6px 0 0' }, 'Change these in Teach the bot. I read them every time I write a reply.')); }
  }


  /* ------------ one brain for the whole company: who may know what, the older notes, the safety test ------------ */
  var BR = 'bd.br_';
  function bget(a, p) { return api.get(BR + a, p || {}); }
  function bpost(a, b) { return api.post(BR + a, b || {}); }
  /* ------------------------------------------------------------ duty / penalty drafting and the questions Claude asks (the same code is in social_teach.js and b2_chip.js) ------------------------------------------------------------ */
  function bkh(tag, p) {
    var e = document.createElement(tag);
    if (p) for (var k in p) { var v = p[k]; if (v === null || v === undefined || v === false) continue; if (k === 'class') e.className = v; else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v); else if (k === 'value') e.value = v; else e.setAttribute(k, v === true ? '' : v); }
    for (var i = 2; i < arguments.length; i++) bkAdd(e, arguments[i]);
    return e;
  }
  function bkAdd(e, c) { if (c === null || c === undefined || c === false) return; if (Array.isArray(c)) { c.forEach(function (x) { bkAdd(e, x); }); return; } e.appendChild(typeof c === 'object' ? c : document.createTextNode(String(c))); }
  function bkClear(e) { while (e.firstChild) e.removeChild(e.firstChild); return e; }
  function bkCss() {
    if (document.getElementById('bkCss')) return; var st = document.createElement('style'); st.id = 'bkCss';
    st.textContent = '.bkbox{border:1.5px solid #2455b8;border-radius:14px;padding:12px;margin:6px 0 8px;background:#f7faff;color:#14161a;font-size:13.5px;line-height:1.45}.bkbox h4{margin:0 0 8px;font-size:14px}'
      + '.bkrow{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0 0 8px}.bkf{display:flex;flex-direction:column;gap:3px;font-size:11.5px;font-weight:700;color:#5b6472;flex:1 1 150px;min-width:0}.bkf.w{flex:1 1 100%}'
      + '.bkin{font:inherit;font-size:13.5px;font-weight:500;color:#14161a;padding:8px 10px;border:1px solid #d9dde3;border-radius:12px;background:#fff;min-height:40px;box-sizing:border-box;width:100%;min-width:0}'
      + '.bkdays{display:flex;gap:4px;flex-wrap:wrap}.bkdays label{display:inline-flex;align-items:center;gap:3px;font-weight:600;font-size:12.5px;border:1px solid #d9dde3;border-radius:10px;padding:6px 8px;background:#fff;color:#14161a}'
      + '.bknote{background:#fff8e6;border:1px solid #f1e0a8;border-radius:10px;padding:7px 10px;margin:0 0 8px;font-size:12.5px;color:#7a5a00}.bkerr{color:#b3261e;font-weight:700;margin:0 0 8px;font-size:13px}'
      + '.bkq{border:1px solid #e6e8ec;border-radius:12px;padding:10px;margin:0 0 8px;background:#fff;color:#14161a}.bkq b{display:block;font-size:13.5px}.bkq small{display:block;color:#6a7280;margin:2px 0 6px;font-size:12px}'
      + '@media(max-width:560px){.bkf{flex:1 1 100%}}';
    document.head.appendChild(st);
  }
  /* "Draft it in the Boss Engine": Claude fills the form, the owner checks it and presses Create. Nothing is made before that. */
  function brainTaskStart(host, text, io) {
    bkCss(); bkClear(host); host.appendChild(bkh('div', { class: io.cls.sub }, 'Claude is drafting this for the Boss Engine…'));
    io.post('task_draft', { text: text }).then(function (r) { brainTaskForm(host, text, r, io); }).catch(function (e) {
      bkClear(host); host.appendChild(bkh('div', { class: 'bkerr' }, (e && e.message) || 'Claude could not draft this.'));
      host.appendChild(bkh('button', { type: 'button', class: io.cls.sec, onclick: function () { brainTaskStart(host, text, io); } }, 'Try again'));
    });
  }
  function brainTaskForm(host, text, res, io) {
    bkCss(); bkClear(host); var C = io.cls, d = res.draft, secs = res.sections || [], F = {};
    function opt(v, t) { return bkh('option', { value: v }, t); }
    function fld(label, node, w) { return bkh('label', { class: 'bkf' + (w ? ' w' : '') }, label, node); }
    function sel(list) { return bkh('select', { class: 'bkin' }, list.map(function (x) { return opt(x[0], x[1]); })); }
    F.kind = sel([['duty', 'A duty (a job on a schedule)'], ['penalty', 'A penalty only (a boss flags a miss)']]); F.kind.value = d.kind;
    F.sec = sel(secs.map(function (s) { return [s.id, s.name]; })); F.sec.value = String(d.section_id);
    F.seat = bkh('select', { class: 'bkin' });
    function fillSeats(keep) { bkClear(F.seat); var s = secs.filter(function (x) { return String(x.id) === F.sec.value; })[0]; ((s && s.seats) || []).forEach(function (x) { F.seat.appendChild(opt(x.id, x.title + ' — ' + (x.holders && x.holders.length ? x.holders.join(', ') : 'nobody yet'))); }); if (keep) F.seat.value = String(keep); }
    fillSeats(d.seat_id); F.sec.addEventListener('change', function () { fillSeats(0); });
    F.title = bkh('input', { class: 'bkin', value: d.title, maxlength: '160' });
    F.freq = sel([['daily', 'Every day'], ['weekly', 'Every week'], ['monthly', 'Every month'], ['once', 'Once']]); F.freq.value = d.freq;
    var dn = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], chosen = String(d.dow || '').split(',');
    F.dow = bkh('div', { class: 'bkdays' }, dn.map(function (n, i) { var cb = bkh('input', { type: 'checkbox', value: String(i + 1) }); cb.checked = chosen.indexOf(String(i + 1)) >= 0; return bkh('label', {}, cb, n); }));
    F.dom = bkh('input', { class: 'bkin', type: 'number', min: '1', max: '28', value: d.dom || 1 });
    F.time = bkh('input', { class: 'bkin', type: 'time', value: d.due_time || '18:00' });
    F.proof = sel([['tick', 'The person ticks it'], ['photo', 'The person sends a photo'], ['count', 'The person enters a number']]); F.proof.value = d.proof;
    F.photo = bkh('input', { class: 'bkin', value: d.photo_prompt || '', placeholder: 'What must the photo show?', maxlength: '480' });
    F.qty = bkh('input', { class: 'bkin', type: 'number', min: '0', value: d.target_qty || 0 }); F.unit = bkh('input', { class: 'bkin', value: d.unit || '', placeholder: 'pieces, parcels…', maxlength: '30' });
    F.amt = bkh('input', { class: 'bkin', type: 'number', min: '0', step: '1', value: d.pen_amount || 0 });
    F.per = sel([['miss', 'Each time it is missed'], ['day', 'Once a day']]); F.per.value = d.per;
    F.grace = bkh('input', { class: 'bkin', type: 'number', min: '0', max: '600', value: d.grace_min || 0 });
    F.mode = sel([['approve', 'The boss says yes first (safe)'], ['auto', 'Charged automatically']]); F.mode.value = d.pen_mode;
    var fDow = fld('Which days', F.dow, true), fDom = fld('Day of the month (1 to 28)', F.dom), fPhoto = fld('The photo must show', F.photo, true), fQty = fld('How many (at least)', F.qty), fUnit = fld('Of what', F.unit), fPer = fld('Charged', F.per);
    var rSched = bkh('div', { class: 'bkrow' }, fld('Repeats', F.freq), fDow, fDom, fld('Due by', F.time)), rProof = bkh('div', { class: 'bkrow' }, fld('Proof', F.proof), fPhoto, fQty, fUnit);
    function sync() {
      var pen = F.kind.value === 'penalty'; rSched.style.display = pen ? 'none' : ''; rProof.style.display = pen ? 'none' : ''; fPer.style.display = pen ? '' : 'none';
      fDow.style.display = !pen && F.freq.value === 'weekly' ? '' : 'none'; fDom.style.display = !pen && F.freq.value === 'monthly' ? '' : 'none';
      fPhoto.style.display = F.proof.value === 'photo' ? '' : 'none'; fQty.style.display = fUnit.style.display = F.proof.value === 'count' ? '' : 'none';
    }
    ['kind', 'freq', 'proof'].forEach(function (k) { F[k].addEventListener('change', sync); }); sync();
    function read() {
      return { kind: F.kind.value, section_id: +F.sec.value, seat_id: +F.seat.value || 0, title: F.title.value.trim(), why: d.why || '', freq: F.freq.value,
        dow: [].slice.call(F.dow.querySelectorAll('input:checked')).map(function (x) { return x.value; }).join(','), dom: +F.dom.value || 0, due_time: F.time.value || '18:00', proof: F.proof.value, photo_prompt: F.photo.value.trim(),
        target_qty: +F.qty.value || 0, cmp: d.cmp || 'min', unit: F.unit.value.trim(), pen_amount: +F.amt.value || 0, per: F.per.value, grace_min: +F.grace.value || 0, pen_mode: F.mode.value };
    }
    var err = bkh('div', { class: 'bkerr', style: 'display:none' }), extra = bkh('div', {});
    function create(force) {
      err.style.display = 'none'; bkClear(extra); mk.disabled = true; var lbl = mk.textContent; mk.textContent = 'Creating…';
      io.post('task_create', { draft: Object.assign(read(), force ? { force: 1 } : {}) }).then(function (x) {
        bkClear(host); host.appendChild(bkh('div', { class: C.ok }, '✅ ' + x.message)); io.say(x.message); if (io.onChange) io.onChange();
      }).catch(function (e) {
        mk.disabled = false; mk.textContent = lbl; var m = (e && e.message) || 'Could not create it.'; err.textContent = m; err.style.display = '';
        if (/similar duty/.test(m)) extra.appendChild(bkh('button', { type: 'button', class: C.sec, onclick: function () { create(true); } }, 'Create it anyway'));
      });
    }
    var mk = bkh('button', { type: 'button', class: C.btn, onclick: function () { create(false); } }, 'Create in the Boss Engine');
    var fix = bkh('input', { class: 'bkin', placeholder: 'Or tell Claude what to change, for example “make it 7 PM”' });
    var again = bkh('button', { type: 'button', class: C.sec, onclick: function () {
      if (fix.value.trim().length < 3) { err.textContent = 'Write what Claude should change.'; err.style.display = ''; return; } again.disabled = true; again.textContent = 'Changing…';
      io.post('task_draft', { text: text, fix: fix.value.trim(), draft: read() }).then(function (r) { brainTaskForm(host, text, r, io); }).catch(function (e) { again.disabled = false; again.textContent = 'Change it'; err.textContent = (e && e.message) || 'Claude could not change it.'; err.style.display = ''; });
    } }, 'Change it');
    var box = bkh('div', { class: 'bkbox' }, bkh('h4', {}, 'Claude’s draft for the Boss Engine'),
      (d.notes || []).map(function (n) { return bkh('div', { class: 'bknote' }, n); }),
      bkh('div', { class: 'bkrow' }, fld('What is it', F.kind, true), fld('Department', F.sec), fld('Who does it', F.seat)),
      bkh('div', { class: 'bkrow' }, fld('Name', F.title, true)), rSched, rProof,
      bkh('div', { class: 'bkrow' }, fld('Fine in rupees (0 = none)', F.amt), fPer, fld('Grace in minutes', F.grace), fld('Who decides the fine', F.mode)),
      res.mode === 'shadow' ? bkh('div', { class: 'bknote' }, 'The Boss Engine is in test mode: it records everything but nobody is charged yet.') : null,
      err, extra, bkh('div', { class: 'bkrow', style: 'margin:0' }, mk, bkh('button', { type: 'button', class: C.sec, onclick: function () { bkClear(host); } }, 'Cancel')),
      bkh('div', { class: 'bkrow', style: 'margin:8px 0 0' }, bkh('div', { style: 'flex:1 1 220px;min-width:0' }, fix), again));
    host.appendChild(box);
  }
  /* the questions Claude asks about the team side; each answer becomes a team-only fact */
  function brainQuestionsUI(host, io) {
    bkCss(); var C = io.cls;
    function paint(r, msg) {
      bkClear(host); var qs = r.questions || [];
      if (!qs.length) {
        host.appendChild(bkh('div', { class: C.sub }, msg || 'Claude has no questions for you right now.'));
        if (r.can_make) host.appendChild(bkh('button', { type: 'button', class: C.sec, onclick: function (ev) {
          var b = ev.currentTarget; b.disabled = true; b.textContent = 'Claude is thinking…';
          io.post('q_make', { manual: 1 }).then(function (m) { paint({ questions: m.questions, can_make: !!m.can_make && !!m.made }, m.made ? '' : (m.message || 'Claude has nothing new to ask right now.')); if (io.onChange) io.onChange(); }).catch(function (e) { paint(r, (e && e.message) || 'Could not ask Claude.'); });
        } }, 'Ask me something'));
        return;
      }
      qs.forEach(function (q) {
        var inp = bkh('input', { class: 'bkin', placeholder: 'Your answer, one sentence', maxlength: '500' }), err = bkh('div', { class: 'bkerr', style: 'display:none;margin:6px 0 0' });
        function done(r2) { paint(r2); if (io.onChange) io.onChange(); }
        var ans = bkh('button', { type: 'button', class: C.btn, onclick: function () {
          if (inp.value.trim().length < 2) { err.textContent = 'Type the answer first.'; err.style.display = ''; return; } ans.disabled = true;
          io.post('q_answer', { id: q.id, text: inp.value.trim() }).then(function (x) { io.say(x.message); done(x); }).catch(function (e) { ans.disabled = false; err.textContent = (e && e.message) || 'Could not save.'; err.style.display = ''; });
        } }, 'Answer');
        var skip = bkh('button', { type: 'button', class: C.sec, onclick: function () { skip.disabled = true; io.post('q_skip', { id: q.id }).then(done).catch(function () { skip.disabled = false; }); } }, 'Skip');
        inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ans.click(); });
        host.appendChild(bkh('div', { class: 'bkq' }, bkh('b', {}, q.q), bkh('small', {}, q.area_label + (q.why ? ' · ' + q.why : '')), bkh('div', { class: 'bkrow', style: 'margin:0' }, bkh('div', { style: 'flex:1 1 220px;min-width:0' }, inp), ans, skip), err));
      });
    }
    bkClear(host); host.appendChild(bkh('div', { class: C.sub }, 'Loading…'));
    io.get('q_list').then(function (r) {
      paint(r);
      if (!(r.questions || []).length && r.can_make && !io.autoDone) {   // nothing waits: Claude may ask (once a day, 3 at most; the server keeps that limit)
        io.autoDone = true;
        io.post('q_make', {}).then(function (m) {
          if (m.questions && m.questions.length) { paint({ questions: m.questions, can_make: !!m.can_make }); if (io.onChange) io.onChange(); }
          else paint({ questions: [], can_make: r.can_make }, m.message && m.message !== 'Asked recently.' ? m.message : '');
        }).catch(function () {});
      }
    }).catch(function (e) { bkClear(host); host.appendChild(bkh('div', { class: 'bkerr' }, (e && e.message) || 'Could not load.')); });
  }

  var BKIO = { cls: { btn: 'sxl-btn', sec: 'sxl-btn sec', sub: 'sxl-sub', ok: 'sxl-read' }, post: function (a, b) { return bpost(a, b); }, get: function (a, p) { return bget(a, p); }, say: function (m, bad) { toast(m, bad); }, onChange: function () {} };
  function companyCard(box) {
    var c = el('div', { class: 'sxl-card', id: 'sxlCo' }); box.appendChild(c);
    if (!S.co) S.co = { tab: 'company', ov: null, lists: {}, draft: null, loading: false, leak: null, err: '' };
    fillCo(c); if (!S.co.ov && !S.co.loading) loadCo();
  }
  function loadCo(tab) {
    var C = S.co; C.loading = true; var t = tab || C.tab; var sc = t === 'old' ? 'customer' : t === 'safe' ? '' : t;
    Promise.all([bget('overview'), sc ? bget('facts', { scope: sc }) : Promise.resolve(null)]).then(function (r) {
      C.loading = false; C.err = ''; C.ov = r[0]; if (r[1]) C.lists[t] = r[1]; if (r[0].leak && !C.leak) C.leak = r[0].leak; var c = document.getElementById('sxlCo'); if (c && S.tab === 'brain') fillCo(c);
    }).catch(function (e) { C.loading = false; C.err = errText(e); var c = document.getElementById('sxlCo'); if (c && S.tab === 'brain') fillCo(c); });
  }
  function fillCo(c) {
    var C = S.co, O = C.ov; clear(c);
    c.appendChild(el('div', { class: 'sxl-h' }, '🏢 One brain for the whole company'));
    c.appendChild(el('div', { class: 'sxl-sub' }, 'The customer Claude (Inbox, WhatsApp bot, Complaints) and the team Claude (Boss Engine, Finance, Dispatch, Offices, Ask Claude) learn from here. Everything is marked “customers may be told” or “team only”, so a team rule never reaches a customer. What you teach here wins over the older Teach-the-bot notes.'));
    if (C.err) c.appendChild(el('div', { class: 'sxl-need' }, C.err + ' ', el('button', { type: 'button', class: 'sxl-mini', onclick: function () { loadCo(); } }, 'Try again')));
    if (O && !O.ai) c.appendChild(el('div', { class: 'sxl-need' }, 'Claude is switched off (no Anthropic key), so it cannot read what you type. Your sentence is saved as written, as team-only.'));
    // teach box
    var ta = textarea('', 'Tell Claude something. For example: “A parcel without a tracking ID after 24 hours costs ₹300.” or “Our store is open 10 to 9 every day.”', 2); var prev = el('div', {});
    var go = el('button', { type: 'button', class: 'sxl-btn', onclick: function () {
      if (ta.value.trim().length < 4) { toast('Write what Claude should know.', true); return; } busy(go, true, 'Reading…');
      bpost('teach_read', { text: ta.value.trim(), scope: C.tab === 'internal' ? 'internal' : C.tab === 'company' ? 'company' : '' }).then(function (r) { busy(go, false); showDraft(prev, r, ta); }).catch(function (e) { busy(go, false); toast(errText(e), true); });
    } }, 'Read it');
    c.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:6px' }, go, mic(ta))); c.insertBefore(ta, c.lastChild); c.appendChild(prev);
    // what Claude asks the owner about the team side (answers are saved as team-only facts)
    var qh = el('div', {}); c.appendChild(el('div', { class: 'sxl-read', style: 'margin:12px 0 0' }, el('b', {}, '❓ Claude asks you'), el('div', { class: 'sxl-sub', style: 'margin:2px 0 8px' }, 'A few things Claude is missing about how the team side works. Your answers are saved as team-only: they never reach a customer.'), qh)); brainQuestionsUI(qh, BKIO);
    // tabs
    var tabs = el('div', { class: 'sxl-row', style: 'margin:12px 0 6px;gap:6px;flex-wrap:wrap' });
    [['company', '🏢 Company facts', O ? O.counts.company : 0], ['internal', '🔒 Team only', O ? O.counts.internal : 0], ['old', '📜 Older notes', O ? O.old.lines : 0], ['safe', '🛡 Safety', null]].forEach(function (t) {
      tabs.appendChild(el('button', { type: 'button', class: 'sxl-tab' + (C.tab === t[0] ? ' on' : ''), onclick: function () { C.tab = t[0]; if (!C.lists[t[0]] && t[0] !== 'safe') loadCo(t[0]); fillCo(c); } }, t[1] + (t[2] !== null && t[2] !== undefined ? ' (' + t[2] + ')' : '')));
    });
    c.appendChild(tabs);
    var body = el('div', {}); c.appendChild(body); var L = C.lists[C.tab];
    if (C.tab !== 'safe' && !L) { body.appendChild(el('div', { class: 'sxl-load' }, 'Loading…')); return; }
    if (C.tab === 'company' || C.tab === 'internal') {
      body.appendChild(el('div', { class: 'sxl-sub' }, C.tab === 'company' ? 'Facts about the business that every Claude may know. A fact marked “team only” stays out of every customer reply.' : 'Rules and facts only staff may know: penalties, duties, who handles what, finance and dispatch rules. They are never put into a customer reply.'));
      if (C.tab === 'internal' && L.summary) body.appendChild(el('div', { class: 'sxl-sum', style: 'margin:6px 0' }, L.summary.text));
      if (C.tab === 'internal') body.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function (ev) { var b = ev.currentTarget; busy(b, true, 'Writing…'); bpost('summary').then(function () { delete C.lists.internal; loadCo('internal'); }).catch(function (e) { busy(b, false); toast(errText(e), true); }); } }, '↻ Write the summary again'));
      if (!L.facts.length) body.appendChild(el('div', { class: 'sxl-sub' }, 'Nothing here yet. Type something above.'));
      L.facts.forEach(function (f) {
        var row = el('div', { class: 'sxl-frow' }, el('div', { class: 'tx' }, f.text));
        row.appendChild(el('span', { class: 'sxl-chip ' + (C.tab === 'company' ? (f.shareable ? 'g' : 'y') : 'b') }, C.tab === 'company' ? (f.shareable ? 'customers may be told' : 'team only') : (f.area && f.area !== 'any' ? (O.areas[f.area] || f.area) : 'everywhere')));
        if (C.tab === 'company') row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { bpost('fact_move', { id: f.id, scope: 'company', shareable: f.shareable ? 0 : 1 }).then(function () { delete C.lists.company; loadCo('company'); }).catch(function (e) { toast(errText(e), true); }); } }, f.shareable ? 'Make team-only' : 'Let customers know'));
        row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { if (window.confirm('Remove this? Claude will stop using it.')) bpost('fact_delete', { id: f.id }).then(function () { delete C.lists[C.tab]; loadCo(C.tab); }).catch(function (e) { toast(errText(e), true); }); } }, 'Delete'));
        body.appendChild(row);
      });
    } else if (C.tab === 'old') {
      body.appendChild(el('div', { class: 'sxl-sub' }, 'What was taught in “Teach the bot” before. It still works. When you teach something that disagrees with an old note, Claude marks the old note as replaced and stops using it. You can also replace or stop a note yourself.' + (O ? ' ' + O.old.lessons + ' lessons are used automatically when a customer message matches them.' : '')));
      if (!L.old.length) body.appendChild(el('div', { class: 'sxl-sub' }, 'No older notes found.'));
      L.old.forEach(function (n) {
        var rep = n.status === 'replaced'; var tx = el('div', { class: 'tx', style: rep ? 'text-decoration:line-through;opacity:.6' : '' }, n.line); var row = el('div', { class: 'sxl-frow' }, tx);
        row.appendChild(el('span', { class: 'sxl-chip' + (rep ? ' y' : '') }, rep ? 'replaced' + (n.why ? ' · ' + n.why : '') : (n.src === 'corr' ? 'owner correction' : 'Teach the bot · ' + n.ref)));
        if (rep) row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { bpost('old_restore', { id: n.id }).then(function () { delete C.lists.old; loadCo('old'); }); } }, 'Use it again'));
        else {
          row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () {
            clear(tx); var inp = el('input', { class: 'sxl-in', style: 'width:100%', placeholder: 'The new rule that replaces this note' }); tx.appendChild(inp);
            var sv = el('button', { type: 'button', class: 'sxl-mini', onclick: function () { if (inp.value.trim().length < 4) { toast('Write the new rule.', true); return; } bpost('old_replace', { id: n.id, text: inp.value.trim(), kind: 'rule' }).then(function () { toast('Replaced. Claude uses your new rule.'); delete C.lists.old; delete C.lists.company; loadCo('old'); }).catch(function (e) { toast(errText(e), true); }); } }, 'Save'); tx.appendChild(sv); inp.focus();
          } }, 'Replace'));
          row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { if (window.confirm('Claude will stop using this note. You can bring it back.')) bpost('old_replace', { id: n.id, text: '' }).then(function () { delete C.lists.old; loadCo('old'); }); } }, 'Stop using'));
        }
        body.appendChild(row);
      });
    } else {
      body.appendChild(el('div', { class: 'sxl-sub' }, 'The leak test plants a secret team-only rule, then checks that no customer-facing Claude (Inbox, WhatsApp bot, Complaints) would be told it, and that a customer reply repeating it would be held back. It removes the secret again.'));
      var out = el('div', {}); var show = function (r) { clear(out); out.appendChild(el('div', { class: 'sxl-read', style: 'margin-top:8px' }, el('b', {}, r.ok ? '✅ Safe: all ' + r.checks.length + ' checks passed' : '❌ ' + r.failed + ' check(s) failed — do not teach team rules until this is fixed'), el('ul', { class: 'sxl-bul' }, r.checks.map(function (k) { return el('li', {}, (k.ok ? '✓ ' : '✗ ') + k.name); })), el('div', { class: 'sxl-sub' }, 'Last run: ' + String(r.at || '').slice(0, 16)))); };
      var run = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { busy(run, true, 'Testing…'); bpost('leak_test').then(function (r) { busy(run, false); C.leak = r; show(r); }).catch(function (e) { busy(run, false); toast(errText(e), true); }); } }, 'Run the leak test');
      body.appendChild(el('div', { class: 'sxl-row' }, run)); body.appendChild(out); if (C.leak) show(C.leak);
    }
  }
  function showDraft(prev, r, ta) {
    var C = S.co; clear(prev); var areas = r.areas || {}; var picks = [];
    prev.appendChild(el('div', { class: 'sxl-read' }, el('b', {}, 'I understood this:'), el('ul', { class: 'sxl-bul' }, (r.understood || []).map(function (u) { return el('li', {}, u); }))));
    prev.appendChild(el('div', { class: 'sxl-sub', style: 'margin:8px 0 2px' }, 'Check who may know each thing. Team-only never reaches a customer.'));
    r.items.forEach(function (it) {
      var cb = el('input', { type: 'checkbox' }); cb.checked = true; var inp = el('input', { class: 'sxl-in', style: 'flex:1 1 240px', value: it.text });
      var sc = el('select', { class: 'sxl-in' }, el('option', { value: 'internal' }, '🔒 Team only'), el('option', { value: 'company' }, '🏢 Company fact'), el('option', { value: 'customer' }, '🧑 How we talk to customers'));
      sc.value = it.scope; var share = el('select', { class: 'sxl-in' }, el('option', { value: 1 }, 'Customers may be told'), el('option', { value: 0 }, 'Team only')); share.value = it.shareable ? '1' : '0';
      var ar = el('select', { class: 'sxl-in' }, Object.keys(areas).map(function (k) { return el('option', { value: k }, areas[k]); })); ar.value = it.area;
      var sync = function () { share.style.display = sc.value === 'company' ? '' : 'none'; ar.style.display = sc.value === 'internal' ? '' : 'none'; }; sc.addEventListener('change', sync); sync();
      prev.appendChild(el('div', { class: 'sxl-frow', style: 'flex-wrap:wrap;gap:6px' }, cb, inp, sc, share, ar));
      if (it.task) {
        var tkHost = el('div', { style: 'margin:0 0 6px 24px' });
        prev.appendChild(el('div', { class: 'sxl-sub', style: 'margin:0 0 4px 24px' }, 'This is a duty or penalty. Claude knows the rule once you press Keep. To make the Boss Engine run it, draft it here and press Create.'));
        prev.appendChild(el('div', { style: 'margin:0 0 6px 24px' }, el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { brainTaskStart(tkHost, inp.value.trim() || it.text, BKIO); } }, '⚙ Draft it in the Boss Engine')));
        prev.appendChild(tkHost);
      }
      picks.push({ cb: cb, inp: inp, sc: sc, share: share, ar: ar, kind: it.kind });
    });
    var keep = el('button', { type: 'button', class: 'sxl-btn ok', onclick: function () {
      var items = picks.filter(function (p) { return p.cb.checked && p.inp.value.trim(); }).map(function (p) { return { text: p.inp.value.trim(), scope: p.sc.value, shareable: p.sc.value === 'company' ? p.share.value === '1' : p.sc.value === 'customer', area: p.ar.value, kind: p.kind }; });
      if (!items.length) { toast('Nothing ticked.', true); return; } busy(keep, true, 'Saving…');
      bpost('teach_save', { items: items }).then(function (x) { toast(x.message); ta.value = ''; clear(prev); C.lists = {}; C.ov = null; S.facts = null; S.brain = null; S.q.items = null; S.nd.items = null; loadCo(); renderPanel(); }).catch(function (e) { busy(keep, false); toast(errText(e), true); });
    } }, 'Keep');
    prev.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, keep, el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { clear(prev); } }, 'Cancel')));
  }

  /* ------------ topic cards ------------ */
  function topicList(box) {
    var B = S.brain; if (!(B.topics || []).length) return; var wrapc = el('div', { class: 'sxl-card' }); box.appendChild(wrapc); wrapc.appendChild(el('div', { class: 'sxl-h' }, '🧩 By topic'));
    B.topics.forEach(function (t) {
      var lab = { clear: 'clear ✓', understood: 'understood', learning: 'learning', needs: 'answer me ›' }[t.state]; var pct = t.clear_pct !== null && t.clear_pct !== undefined && t.sent >= 5 ? t.clear_pct : Math.min(100, (t.approved || 0) * 40);
      var c = el('div', { style: 'border-bottom:1px solid #eef0f3;padding:8px 0' }); wrapc.appendChild(c); var body = el('div', {});
      c.appendChild(el('div', { class: 'sxl-thead', onclick: function () { S.openTopic = S.openTopic === t.id ? 0 : t.id; renderPanel(); } }, el('b', {}, (t.kind === 'complaint' ? '⚠ ' : '') + t.label), el('span', { style: 'flex:1' }), el('span', { class: 'sxl-meter' }, el('i', { style: 'width:' + pct + '%;background:' + (t.state === 'needs' ? '#d9423b' : t.state === 'learning' ? '#f5b82e' : '#16945a') })), el('span', { class: 'sxl-chip ' + (t.state === 'clear' || t.state === 'understood' ? 'g' : t.state === 'learning' ? 'y' : 'r') }, lab)));
      c.appendChild(body);
      if (S.openTopic === t.id) {
        (t.rules || []).forEach(function (r) { body.appendChild(el('div', { class: 'sxl-msg me', style: 'margin-left:0' }, 'Rule: ' + r)); });
        if (t.clear_pct !== null && t.clear_pct !== undefined) body.appendChild(el('div', { class: 'sxl-sub', style: 'margin:6px 0 0' }, t.clear_pct + '% of my last ' + t.sent + ' replies went out unchanged.'));
        body.appendChild(el('div', { class: 'sxl-load' }, 'Loading…'));
        get('topic_get', { id: t.id }).then(function (r) {
          var ld = body.querySelector('.sxl-load'); if (ld) body.removeChild(ld);
          if (!r.examples.length && !r.facts.length && !(t.rules || []).length) body.appendChild(el('div', { class: 'sxl-sub', style: 'margin-top:8px' }, 'Nothing learned for this topic yet. Edit a reply about it, or answer my questions.'));
          r.examples.forEach(function (x) { var row = el('div', { class: 'sxl-card', style: 'margin:8px 0 0;background:#fcfcfd' }); row.appendChild(el('div', { class: 'sxl-sub', style: 'margin:0 0 4px' }, 'Learned ' + x.at + (x.by ? ' · ' + x.by : ''))); if (x.was) row.appendChild(el('div', { class: 'sxl-was' }, el('small', {}, 'It used to reply'), x.was)); row.appendChild(el('div', { class: 'sxl-was now' }, el('small', {}, 'Now it replies like'), x.now)); if (B.mgr) row.appendChild(el('button', { type: 'button', class: 'sxl-mini', onclick: function () { post('example_undo', { id: x.id }).then(function (r2) { toast(r2.message); afterLearn(); }).catch(function (e) { toast(errText(e), true); }); } }, 'Undo this')); body.appendChild(row); });
          if (r.facts.length) { body.appendChild(el('div', { class: 'sxl-sec' }, 'Facts for this topic')); r.facts.filter(function (f) { return String(f.fact).indexOf('Style rule:') !== 0; }).forEach(function (f) { body.appendChild(el('div', { class: 'sxl-msg' }, f.fact)); }); }
        }).catch(function () { var ld = body.querySelector('.sxl-load'); if (ld) body.removeChild(ld); });
      }
    });
  }

  /* "I do not want to answer this": the message leaves Needs reply and the red numbers (a new message from the customer brings it back) */
  function markReadBtn(it, onDone, label) {
    var risky = !!(it.unhappy || it.ticket), armed = false, txt = label || '✓ Mark as read';
    var b = el('button', { type: 'button', class: 'sxl-mini', title: 'No reply needed: it leaves the Needs reply list and the red numbers', onclick: function () {
      if (risky && !armed) { armed = true; b.textContent = 'Sure? This customer is unhappy'; setTimeout(function () { armed = false; b.textContent = txt; }, 4000); return; }
      busy(b, true, '…'); post('mark_read', { ids: [it.id] }).then(function (r) { toast(r.message); onDone(); loadOv(); }).catch(function (e) { busy(b, false); toast(errText(e), true); });
    } }, txt);
    return b;
  }
  /* ============================================================ QUICK REPLY ============================================================ */
  var FAM = [['comments', '💬 Comments'], ['reviews', '⭐ Reviews'], ['dms', '📸 DMs'], ['mail', '✉️ Email']];
  function quickView(box) {
    var Q = S.q, o = S.ov; box.appendChild(el('h3', { class: 'sxl-h' }, 'Quick reply'));
    box.appendChild(el('p', { class: 'sxl-sub' }, 'Claude has written a reply for each message. Check, edit, untick what you want to answer yourself, then send them together. Unhappy customers are never here — they wait in “Needs you”.'));
    var chips = el('div', { class: 'sxl-row', style: 'margin-bottom:8px' }); box.appendChild(chips);
    FAM.forEach(function (f) { var n = (Q.counts || o.quick || {})[f[0]]; chips.appendChild(el('button', { type: 'button', class: 'sxl-tab' + (Q.family === f[0] ? ' on' : ''), onclick: function () { Q.family = f[0]; Q.items = null; renderPanel(); } }, f[1] + (n !== undefined ? ' · ' + n : ''))); });
    chips.appendChild(el('span', { style: 'flex:1' }));
    chips.appendChild(el('span', { class: 'sxl-sub', style: 'margin:0' }, 'At once'));
    chips.appendChild(el('div', { class: 'sxl-seg' }, [10, 20, 50, 100].map(function (n) { return el('button', { type: 'button', class: Q.n === n ? 'on' : '', onclick: function () { Q.n = n; Q.items = null; renderPanel(); } }, String(n)); })));
    if (Q.items === null) { if (!Q.loading) loadBatch(); box.appendChild(el('div', { class: 'sxl-load' }, o.ai ? 'Claude is writing the replies…' : 'Loading…')); return; }
    if (!Q.items.length) { box.appendChild(el('div', { class: 'sxl-empty' }, 'Nothing waiting in this group. 🎉')); return; }
    var selIds = function () { return Q.items.filter(function (i) { return Q.sel[i.id] && i.can_send; }).map(function (i) { return i.id; }); };
    function useLike(r) { var m = {}; (r.replies || []).forEach(function (x) { m[x.id] = x.text; }); Q.hit = {}; Q.items.forEach(function (i) { if (m[i.id]) { i.reply = m[i.id].replace(/\{name\}/gi, firstName(i.who)); Q.hit[i.id] = 1; } }); renderPanel(); }
    // the shared wording box
    var wb = el('div', { class: 'sxl-wording' }, el('label', {}, '✎ WORDING FOR SIMILAR MESSAGES (optional)'));
    var wta = textarea(Q.wording, 'e.g. Thank you {name}! 💛 Glad you like it. Tap the link in our bio to see the full range.', 2); wb.appendChild(wta);
    var apply = el('button', { type: 'button', class: 'sxl-btn orange', onclick: function () {
      Q.wording = wta.value; if (!Q.wording.trim()) { toast('Write the wording first.', true); return; } var ids = selIds(); if (!ids.length) { toast('Tick at least one message.', true); return; }
      busy(apply, true, 'Claude is checking…');
      postChunks('apply_like', ids, function (part) { return { sample: Q.wording, ids: part }; }).then(function (r) { useLike(r); toast('Used for ' + r.replies.length + ' that it fits. ' + r.kept + ' kept their own reply.'); }).catch(function (e) { busy(apply, false); toast(errText(e), true); });
    } }, 'Use it where it fits');
    wb.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:6px' }, apply, mic(wta), el('span', { class: 'sxl-sub', style: 'margin:0' }, 'Claude checks each ticked message and uses this only where it fits. {name} becomes the first name.')));
    box.appendChild(wb);
    var list = el('div', {}); box.appendChild(list); var cnt = el('span', {}); var sendBtn = el('button', { type: 'button', class: 'sxl-btn' });
    function upd() { var n = selIds().length; cnt.textContent = n + ' of ' + Q.items.length + ' selected'; sendBtn.textContent = 'Send ' + n + ' repl' + (n === 1 ? 'y' : 'ies'); sendBtn.disabled = !n || !!Q.undo; }
    Q.items.forEach(function (it) {
      var cb = el('input', { type: 'checkbox' }); cb.checked = !!Q.sel[it.id] && it.can_send; cb.disabled = !it.can_send; cb.addEventListener('change', function () { Q.sel[it.id] = cb.checked; upd(); });
      var ta = textarea(it.reply, '', 2); ta.disabled = !it.can_send; ta.addEventListener('input', function () { it.reply = ta.value; var rs = row.querySelector('.likebtn'); if (rs) rs.style.display = (ta.value.trim() !== (Q.orig[it.id] || '').trim() && selIds().length > 1) ? '' : 'none'; });
      var rs = el('button', { type: 'button', class: 'sxl-mini likebtn', style: 'display:none;margin-top:6px', onclick: function () {
        var others = selIds().filter(function (x) { return x !== it.id; }); busy(rs, true, 'Claude is checking…');
        postChunks('apply_like', others, function (part, k) { return k === 0 ? { sample: it.reply, cust: it.text, ids: part, item_id: it.id, topic: it.topic } : { sample: it.reply, cust: it.text, ids: part, topic: it.topic }; }).then(function (r) { useLike(r); if (r.learned) toast('Learned for “' + it.topic + '”: next replies about it are written like this.'); else toast(r.replies.length ? 'Used for ' + r.replies.length + ' similar message' + (r.replies.length === 1 ? '' : 's') + '. The other ' + r.kept + ' kept their own reply.' : 'No other ticked message is like this one. Nothing changed.'); }).catch(function (e) { busy(rs, false); toast(errText(e), true); });
      } }, '↻ Use this for similar messages');
      var rating = it.rating ? ' · ' + it.rating + '★' : '';
      var row = el('div', { class: 'sxl-qrow' + (it.can_send ? '' : ' off') + (Q.hit && Q.hit[it.id] ? ' hit' : '') }, cb,
        el('div', {}, el('div', { class: 'sxl-who' }, it.icon + ' ' + it.who + ' ', el('small', {}, '· ' + it.channel + rating + ' · ' + it.ago)), el('div', { class: 'sxl-txt' }, it.text), el('span', { class: 'sxl-chip', style: 'margin-top:4px' }, it.topic), it.can_send ? null : el('div', { class: 'sxl-sub', style: 'margin:4px 0 0;color:#b3261e' }, 'Cannot be sent from here (reply window closed or not connected).')),
        el('div', { class: 'rep' }, ta, rs, el('div', { style: 'margin-top:6px' }, markReadBtn(it, function () { Q.items = Q.items.filter(function (x) { return x.id !== it.id; }); delete Q.sel[it.id]; renderPanel(); }))));
      list.appendChild(row);
    });
    var undoBox = el('div', {}); box.appendChild(undoBox);
    sendBtn.addEventListener('click', function () { startUndo(undoBox, upd); });
    var readAll = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () {
      var ids = selIds(); if (!ids.length) { toast('Tick at least one message.', true); return; }
      if (!readAll.dataset.arm) { readAll.dataset.arm = '1'; readAll.textContent = 'Sure? Mark ' + ids.length + ' as read'; setTimeout(function () { delete readAll.dataset.arm; readAll.textContent = 'Mark ticked as read'; }, 4000); return; }
      busy(readAll, true, '…'); postChunks('mark_read', ids, function (part) { return { ids: part }; }, 50).then(function (r) { toast(r.message); var gone = {}; ids.forEach(function (i) { gone[i] = 1; }); Q.items = Q.items.filter(function (x) { return !gone[x.id]; }); renderPanel(); loadOv(); }).catch(function (e) { busy(readAll, false); toast(errText(e), true); });
    } }, 'Mark ticked as read');
    box.appendChild(el('div', { class: 'sxl-sendbar' }, el('span', { class: 'grow' }, el('b', {}, cnt), ' — untick any you want to answer yourself'), readAll, sendBtn));
    upd();
  }
  // 50 / 100 at once: the server takes 20 at a time — send in groups and add the answers together
  function postChunks(action, list, make, size) {
    size = size || 20; var parts = []; for (var i = 0; i < list.length; i += size) parts.push(list.slice(i, i + size));
    var acc = null; return parts.reduce(function (p, part) { return p.then(function () { return post(action, make(part, parts.indexOf(part))).then(function (r) {
      if (!acc) { acc = r; return; } Object.keys(r || {}).forEach(function (k) { if (Array.isArray(r[k])) acc[k] = (acc[k] || []).concat(r[k]); else if (typeof r[k] === 'number' && typeof acc[k] === 'number') acc[k] += r[k]; }); }); }); }, Promise.resolve()).then(function () { return acc || {}; });
  }
  function loadBatch() {
    var Q = S.q; Q.loading = true;
    post('batch', { n: Q.n, family: Q.family }).then(function (r) {
      Q.loading = false; Q.items = r.items || []; Q.counts = r.counts; Q.sel = {}; Q.orig = {}; Q.hit = {};
      Q.items.forEach(function (i) { Q.sel[i.id] = i.can_send; Q.orig[i.id] = i.reply; }); if (r.moved) toast(r.moved + ' message' + (r.moved === 1 ? '' : 's') + ' moved to Needs you: Claude does not have the fact to answer.');
      if (S.tab === 'quick') renderPanel(); loadOv();
    }).catch(function (e) { Q.loading = false; Q.items = []; toast(errText(e), true); if (S.tab === 'quick') renderPanel(); });
  }
  function startUndo(host, upd) {
    var Q = S.q, items = Q.items.filter(function (i) { return Q.sel[i.id] && i.can_send && i.reply.trim(); }); if (!items.length) { toast('Nothing to send.', true); return; }
    var left = 10, t;
    var msg = el('span', {}); var undo = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { clearInterval(t); Q.undo = null; clear(host); upd(); toast('Cancelled. Nothing was sent.'); } }, 'Undo');
    clear(host); host.appendChild(el('div', { class: 'sxl-undo' }, msg, el('span', { style: 'flex:1' }), undo)); Q.undo = true; upd();
    function tick() { msg.textContent = 'Sending ' + items.length + ' repl' + (items.length === 1 ? 'y' : 'ies') + ' in ' + left + ' s…'; }
    tick(); t = setInterval(function () { left--; if (left > 0) { tick(); return; } clearInterval(t); msg.textContent = 'Sending… please wait.'; undo.disabled = true;
      postChunks('batch_send', items.map(function (i) { return { id: i.id, text: i.reply }; }), function (part) { return { items: part }; }).then(function (r) {
        Q.undo = null; var bad = (r.results || []).filter(function (x) { return !x.ok; });
        toast(r.sent + ' sent' + (bad.length ? ' · ' + bad.length + ' not sent: ' + bad[0].message : '.'), bad.length > 0); Q.items = null; Q.loading = false; loadOv(function () { if (S.tab === 'quick') renderPanel(); });
      }).catch(function (e) { Q.undo = null; toast(errText(e), true); clear(host); upd(); });
    }, 1000);
  }

  /* ============================================================ NEEDS YOU ============================================================ */
  function needsView(box) {
    var N = S.nd; box.appendChild(el('h3', { class: 'sxl-h' }, 'Needs you'));
    box.appendChild(el('p', { class: 'sxl-sub' }, 'Unhappy customers and anything Claude is unsure about. Each one is answered one by one by a person — nothing here is sent in bulk or by itself.'));
    if (N.items === null) { if (!N.loading) loadNeeds(); box.appendChild(el('div', { class: 'sxl-load' }, 'Loading…')); return; }
    var f = el('div', { class: 'sxl-row', style: 'margin-bottom:10px' }); box.appendChild(f);
    var nw = N.items.filter(function (i) { return i.need_phone && i.unhappy; }).length, nt = N.items.filter(function (i) { return i.ticket; }).length;
    [['all', 'All · ' + N.items.length], ['phone', 'Waiting for number · ' + nw], ['ticket', 'With a ticket · ' + nt]].forEach(function (x) { f.appendChild(el('button', { type: 'button', class: 'sxl-tab' + (N.filter === x[0] ? ' on' : ''), onclick: function () { N.filter = x[0]; N.sel = 0; N.detail = false; renderPanel(); } }, x[1])); });
    var items = N.items.filter(function (i) { return N.filter === 'all' || (N.filter === 'phone' && i.need_phone && i.unhappy) || (N.filter === 'ticket' && i.ticket); });
    if (!items.length) { box.appendChild(el('div', { class: 'sxl-empty' }, 'No one is waiting here. 🎉')); return; }
    if (!items.some(function (i) { return i.id === N.sel; })) N.sel = items[0].id;
    var g = el('div', { class: 'sxl-nd' + (N.detail ? ' det' : '') }); box.appendChild(g);
    var list = el('div', { class: 'list' }), dt = el('div', { class: 'dt' }); g.appendChild(list); g.appendChild(dt);
    items.forEach(function (it) {
      var li = el('div', { class: 'sxl-li' + (it.id === N.sel ? ' on' : ''), onclick: function () { N.sel = it.id; N.detail = true; renderPanel(); } },
        el('div', { class: 'sxl-who' }, it.icon + ' ' + it.who + ' ', el('small', {}, '· ' + it.channel + ' · ' + it.ago)),
        el('div', {}, el('span', { class: 'sxl-chip r' }, it.problem_label), it.need_phone && !it.email && it.unhappy ? el('span', { class: 'sxl-chip y' }, 'waiting for number') : null,  it.ticket ? el('span', { class: 'sxl-chip b' }, it.ticket.code) : null, it.orders && it.orders.length ? el('span', { class: 'sxl-chip g' }, 'order ' + it.orders[0].name) : null),
        el('div', { class: 't' }, it.text));
      list.appendChild(li);
    });
    needsDetail(dt, items.filter(function (i) { return i.id === N.sel; })[0]);
  }
  function loadNeeds() {
    var N = S.nd; N.loading = true;
    get('needs').then(function (r) { N.loading = false; N.items = r.items || []; N.problems = r.problems || []; N.tk = r.tickets; if (S.tab === 'needs') renderPanel(); }).catch(function (e) { N.loading = false; N.items = []; toast(errText(e), true); if (S.tab === 'needs') renderPanel(); });
  }
  function dropNeed(id) { var N = S.nd; N.items = N.items.filter(function (i) { return i.id !== id; }); N.sel = 0; N.detail = false; loadOv(); renderPanel(); }
  function needsDetail(dt, it) {
    var N = S.nd;
    dt.appendChild(el('button', { type: 'button', class: 'sxl-btn sec sxl-back', onclick: function () { N.detail = false; renderPanel(); } }, '‹ Back to the list'));
    var card = el('div', { class: 'sxl-card' }); dt.appendChild(card);
    card.appendChild(el('div', { class: 'sxl-who', style: 'font-size:15px' }, it.icon + ' ' + it.who + ' ', el('small', {}, '· ' + it.channel + ' · ' + it.ago)));
    if (it.late) card.appendChild(el('span', { class: 'sxl-chip r' }, 'waiting too long · limit ' + it.sla_min + ' min'));
    (it.thread || []).forEach(function (m) { card.appendChild(el('div', { class: 'sxl-msg' + (m.dir === 'out' ? ' me' : '') }, (m.dir === 'out' ? 'We: ' : '') + m.text)); });
    var read = it.unhappy ? 'This sounds like: ' + it.problem_label + '.' : 'A customer asks about our business.';
    if (it.ticket) read = 'Ticket ' + it.ticket.code + ' is open with ' + (it.ticket.desk || 'the complaint team') + (it.ticket.owner ? ' (' + it.ticket.owner + ')' : '') + '. Answer here, the ticket keeps the whole story.';
    else if (!it.unhappy) read += it.need_fact ? ' I have no fact for this, so I did not guess. Tell me the answer once and I will remember it.' : '';
    else if (it.need_phone && !it.email) read += ' I do not have a phone number for this person yet, so I cannot find the order. Ask for the mobile number or order number first. Do not promise a refund.';
    else if (it.orders && it.orders.length) read += ' I found the order by the mobile number. Do not promise a refund.';
    card.appendChild(el('div', { class: 'sxl-read' }, el('b', {}, 'Claude’s read: '), read));
    if (it.orders && it.orders.length) it.orders.forEach(function (o) { card.appendChild(el('div', { class: 'sxl-order' }, el('b', {}, o.name), ' · ' + o.at + ' · ₹' + o.total + ' · ' + (o.pay || '') + ' / ' + (o.ship || 'not shipped'), o.parcel ? el('div', { class: 'sxl-sub', style: 'margin:2px 0 0' }, 'Parcel: ' + o.parcel + (o.awb ? ' · ' + o.awb : '')) : null)); });
    if (it.ticket) card.appendChild(el('a', { class: 'sxl-chip b', href: 'complaints.html?case=' + it.ticket.id, target: '_blank', rel: 'noopener' }, 'Open ticket ' + it.ticket.code + ' ›'));
    if (it.need_fact) {
      var nf = el('div', { class: 'sxl-need' }, el('b', {}, it.need_kind === 'check' ? 'Check the numbers: ' : 'I do not have this information: '), it.need_fact);
      if (it.need_kind === 'check') { card.appendChild(nf); } else {
      var ans = el('input', { class: 'sxl-in', style: 'width:100%;margin-top:8px', placeholder: 'Your answer, for example: Not yet, we have only our Vesu store' });
      var sv = el('button', { type: 'button', class: 'sxl-btn', onclick: function () { busy(sv, true, 'Saving…'); post('fact_answer', { item_id: it.id, answer: ans.value }).then(function (r) { toast(r.message); S.facts = null; N.items = null; N.loading = false; renderPanel(); }).catch(function (e) { busy(sv, false); toast(errText(e), true); }); } }, 'Save the answer');
      nf.appendChild(ans); nf.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, sv, mic(ans), el('span', { class: 'sxl-sub', style: 'margin:0' }, 'Saved once as a fact. Claude uses it for everyone who asks.'))); card.appendChild(nf); }
    }
    // the suggested reply
    card.appendChild(el('div', { class: 'sxl-sub', style: 'margin-top:8px' }, 'Suggested reply (edit it, then send; only you press Send):'));
    var ta = textarea(it.reply || '', it.reply ? '' : 'Claude is writing…', 3); card.appendChild(ta); ta.addEventListener('input', function () { it.reply = ta.value; });
    if (!it.reply && !it.asked) { it.asked = true; post('needs_suggest', { id: it.id }).then(function (r) { it.reply = r.text; if (S.tab === 'needs' && N.sel === it.id) { ta.value = r.text; grow(ta); } }).catch(function () {}); }
    var send = el('button', { type: 'button', class: 'sxl-btn', onclick: function () {
      if (!ta.value.trim()) { toast('Write the reply first.', true); return; } busy(send, true, 'Sending…');
      post('needs_send', { id: it.id, text: ta.value }).then(function (r) { toast(r.message || 'Sent.'); dropNeed(it.id); }).catch(function (e) { busy(send, false); toast(errText(e), true); });
    } }, 'Send this reply');
    var again = el('button', { type: 'button', class: 'sxl-mini', onclick: function () { busy(again, true, '…'); post('needs_suggest', { id: it.id }).then(function (r) { it.reply = r.text; ta.value = r.text; grow(ta); }).catch(function (e) { toast(errText(e), true); }).then(function () { busy(again, false); }); } }, '↻ Another suggestion');
    var row = el('div', { class: 'sxl-row', style: 'margin-top:8px' }, send, again); card.appendChild(row);
    var form = el('div', { class: 'sxl-card', style: 'margin-top:12px;background:#fcfcfd;display:none' }); card.appendChild(form);
    var raise = el('button', { type: 'button', class: 'sxl-btn red', onclick: function () { if (it.ticket) { toast('Already ticket ' + it.ticket.code + '.'); return; } if (it.need_phone && !it.email) { form.style.display = ''; ph.focus(); return; } doRaise(); } }, it.ticket ? 'Ticket ' + it.ticket.code : 'Raise complaint');
    var notc = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { busy(notc, true, '…'); post('not_complaint', { id: it.id }).then(function (r) { toast(r.message); dropNeed(it.id); }).catch(function (e) { busy(notc, false); toast(errText(e), true); }); } }, 'Not a complaint');
    if (it.unhappy || it.ticket) { row.appendChild(raise); row.appendChild(notc); }
    row.appendChild(markReadBtn(it, function () { dropNeed(it.id); }));
    // the number + problem form
    form.appendChild(el('div', { class: 'sxl-h', style: 'font-size:13.5px' }, 'Customer’s mobile number'));
    form.appendChild(el('div', { class: 'sxl-sub' }, 'One customer file and one ticket per mobile number. If this number already has an open ticket, this message joins it.'));
    var ph = el('input', { class: 'sxl-in', inputmode: 'tel', placeholder: '10-digit mobile number', style: 'width:220px;max-width:100%' });
    var pb = el('select', { class: 'sxl-in' }, (N.problems || []).map(function (p) { return el('option', { value: p.k }, p.label); })); pb.value = it.problem;
    var save = el('button', { type: 'button', class: 'sxl-btn sec', onclick: function () { busy(save, true, '…'); post('needs_phone', { id: it.id, phone: ph.value }).then(function (r) { it.phone = r.phone; it.need_phone = false; it.orders = r.orders || []; toast(r.message); renderPanel(); }).catch(function (e) { busy(save, false); toast(errText(e), true); }); } }, 'Save number');
    var open = el('button', { type: 'button', class: 'sxl-btn red', onclick: function () { doRaise(ph.value); } }, 'Save and open ticket');
    form.appendChild(el('div', { class: 'sxl-row' }, ph, pb)); form.appendChild(el('div', { class: 'sxl-row', style: 'margin-top:8px' }, open, save));
    if (it.email) form.appendChild(el('div', { class: 'sxl-sub', style: 'margin-top:6px' }, 'For an email, the number is optional.'));
    function doRaise(phone) {
      busy(open, true, 'Opening…'); busy(raise, true, 'Opening…');
      post('needs_raise', { id: it.id, phone: phone || '', problem: form.style.display === '' ? pb.value : it.problem }).then(function (r) { it.ticket = r.ticket; it.need_phone = false; it.orders = r.orders || it.orders; toast(r.message); renderPanel(); loadOv(); })
        .catch(function (e) { busy(open, false); busy(raise, false); if (e.data && e.data.need_phone) { form.style.display = ''; ph.focus(); } toast(errText(e), true); });
    }
    if (!it.ticket && it.need_phone && !it.email) { /* the form opens when Raise complaint is pressed */ }
  }

  /* ============================================================ CLEAN UP ============================================================ */
  function cleanView(box) {
    var C = S.cl; box.appendChild(el('h3', { class: 'sxl-h' }, 'Clean up'));
    box.appendChild(el('p', { class: 'sxl-sub' }, 'Claude found comments that look like scam links, false claims or competitor spam on your posts. Tick what to remove. Real unhappy customers are never listed here — they go to “Needs you”.'));
    if (C.items === null) { if (!C.loading) loadClean(); box.appendChild(el('div', { class: 'sxl-load' }, 'Loading…')); return; }
    if (!C.items.length) { box.appendChild(el('div', { class: 'sxl-empty' }, 'Your comments look clean. ✨')); return; }
    var cnt = el('span', {}), hide = el('button', { type: 'button', class: 'sxl-btn' }), del = el('button', { type: 'button', class: 'sxl-btn red' });
    function ids() { return C.items.filter(function (i) { return C.sel[i.id]; }).map(function (i) { return i.id; }); }
    function upd() { var n = ids().length; cnt.textContent = n + ' selected'; hide.textContent = 'Hide ' + n; del.textContent = 'Delete ' + n; hide.disabled = del.disabled = !n; }
    C.items.forEach(function (it) {
      var cb = el('input', { type: 'checkbox' }); cb.checked = !!C.sel[it.id]; cb.addEventListener('change', function () { C.sel[it.id] = cb.checked; upd(); });
      box.appendChild(el('div', { class: 'sxl-qrow', style: 'grid-template-columns:28px minmax(0,1fr)' }, cb, el('div', {}, el('div', { class: 'sxl-who' }, it.who + ' ', el('small', {}, '· ' + it.channel + ' · ' + it.ago)), el('div', { class: 'sxl-txt' }, it.text), el('div', {}, el('span', { class: 'sxl-chip r' }, it.why), it.post ? el('span', { class: 'sxl-chip' }, 'on: ' + it.post.slice(0, 40)) : null))));
    });
    function go(kind) {
      var list = ids(); if (!list.length) return;
      if (kind === 'delete' && !window.confirm('Delete ' + list.length + ' comment' + (list.length === 1 ? '' : 's') + ' for good? This cannot be undone. (Hide is safer: you can unhide it later.)')) return;
      var b = kind === 'delete' ? del : hide; busy(b, true, 'Working…');
      post(kind, { ids: list }).then(function (r) { var ok = (r.results || []).filter(function (x) { return x.ok; }).length, bad = (r.results || []).filter(function (x) { return !x.ok; }); toast(ok + (kind === 'delete' ? ' deleted' : ' hidden') + (bad.length ? ' · ' + bad.length + ' failed: ' + bad[0].message : '.'), bad.length > 0); C.items = null; C.loading = false; C.sel = {}; loadOv(function () { if (S.tab === 'clean') renderPanel(); }); }).catch(function (e) { busy(b, false); toast(errText(e), true); });
    }
    hide.addEventListener('click', function () { go('hide'); }); del.addEventListener('click', function () { go('delete'); });
    box.appendChild(el('div', { class: 'sxl-sendbar' }, el('span', { class: 'grow' }, el('b', {}, cnt), ' — Hide keeps the comment but nobody else sees it. Delete is permanent.'), hide, del)); upd();
  }
  function loadClean() { var C = S.cl; C.loading = true; get('cleanup').then(function (r) { C.loading = false; C.items = r.items || []; C.sel = {}; if (S.tab === 'clean') renderPanel(); }).catch(function (e) { C.loading = false; C.items = []; toast(errText(e), true); if (S.tab === 'clean') renderPanel(); }); }

  /* ------------------------------------------------------------ start ------------------------------------------------------------ */
  function start() {
    var tries = 0;
    (function wait() {
      if (mount()) { loadOv(function () { pollPending(); }); setInterval(function () { if (!S.denied && document.visibilityState === 'visible') loadOv(); }, 90000); setInterval(pollPending, 30000); window.addEventListener('focus', pollPending); return; }
      if (++tries < 40) setTimeout(wait, 250);
    })();
  }
  window.SXL = { setTab: function (t) { setTab(t); }, refresh: function () { loadOv(); } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
