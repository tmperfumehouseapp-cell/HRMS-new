/* sx_app.js — the Inbox hub screens: Inbox (list + chat + Claude), Summary, Connect, Settings.  Page: social_inbox.html  (SX.start(el)).
   Needs api.js.  Talks to bd.sx_* (social.php).  Phone first: list → full-screen chat, like WhatsApp.  Nothing is ever sent without a tap on Send. */
window.SX = (function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const br = (s) => esc(s).replace(/\n/g, '<br>');
  // turn web addresses in an (already escaped) message into tappable links that open in a new tab
  const linkify = (h) => String(h).replace(/\b(?:https?:\/\/|www\.)[^\s<]+/gi, (u) => { let t = ''; const m = /(?:[.,!?;:)\]]|&quot;|&#39;)+$/.exec(u); if (m) { t = m[0]; u = u.slice(0, -t.length); } const href = /^www\./i.test(u) ? 'https://' + u : u; return `<a href="${href}" target="_blank" rel="noopener noreferrer" style="color:#027eb5;text-decoration:underline;word-break:break-all">${u}</a>` + t; });
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const ini = (n) => String(n || '?').replace(/^@/, '').trim().split(/\s+/).slice(0, 2).map((x) => x[0]).join('').toUpperCase() || '?';
  const CH = { ig_dm: ['#e1306c', '◎'], ig_comment: ['#e1306c', '◎'], fb_dm: ['#1877f2', 'f'], fb_comment: ['#1877f2', 'f'], email: ['#ea4335', 'M'], review_product: ['#f59e0b', '★'], review_google: ['#4285f4', 'G'], review_play: ['#01875f', '▶'] };
  const KIND = { meta: ['#1877f2', 'f'], email: ['#ea4335', 'M'], judgeme: ['#f59e0b', '★'], google: ['#4285f4', 'G'], play: ['#01875f', '▶'] };
  const FILTERS = [['new', 'Needs reply'], ['mine', 'Mine'], ['neg', 'Unhappy'], ['unowned', 'Unassigned'], ['dm', 'DMs'], ['comment', 'Comments'], ['review', 'Reviews'], ['email', 'Email'], ['cx', 'Complaints'], ['replied', 'Replied'], ['done', 'Done'], ['spam', 'Spam'], ['gone', 'Deleted']];
  let H, B = null, S = { view: 'inbox', f: 'new', q: '', sel: 0, tab: 'chat', days: 7 }, L = { items: [], counts: {}, per: {}, more: false }, D = null, mode = '', timer = null, planBox = null;

  function css() {
    if (document.getElementById('sxCss')) return;
    const s = document.createElement('style'); s.id = 'sxCss';
    s.textContent = `
.sx{--g:#008069;--a:#00a884;--ln:#e9edef;--mut:#667781;--red:#d92d20;font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;color:#111b21;font-size:14px;line-height:1.4;background:#f0f2f5;min-height:100vh}
.sx *,.sx-ov *{box-sizing:border-box}.sx button,.sx-ov button{font-family:inherit}
.sx-top{background:#fff;border-bottom:1px solid var(--ln);padding:8px 12px 0 62px;position:sticky;top:0;z-index:6}
.sx-t1{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-height:42px}.sx-t1 h1{font-size:19px;margin:0;font-weight:800}.sx-t1 .sp{flex:1}
.sx-tabs{display:flex;gap:2px;overflow-x:auto;scrollbar-width:none}.sx-tabs::-webkit-scrollbar{display:none}
.sx-tab{border:0;background:none;padding:9px 13px;font-size:14px;font-weight:700;color:var(--mut);cursor:pointer;border-bottom:3px solid transparent;white-space:nowrap}.sx-tab.on{color:var(--g);border-color:var(--g)}
.sx-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:999px;padding:8px 15px;font-weight:800;font-size:13px;background:var(--a);color:#fff;cursor:pointer;white-space:nowrap;min-height:36px}
.sx-btn.o{background:#fff;color:#111b21;border:1px solid var(--ln)}.sx-btn.r{background:#fde4e4;color:#b3261e}.sx-btn.s{padding:5px 11px;min-height:30px;font-size:12.5px}.sx-btn:disabled{opacity:.5}
.sx-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:11.5px;font-weight:700;background:#f0f2f5;color:#54656f;white-space:nowrap}
.sx-pill.g{background:#d9fdd3;color:#008069}.sx-pill.r{background:#fde4e4;color:#b3261e}.sx-pill.o{background:#fff1d6;color:#9a6200}.sx-pill.b{background:#e0ecff;color:#1d4ed8}
.sx-av{width:42px;height:42px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;flex:none}
.sx-wrap{display:grid;grid-template-columns:minmax(320px,380px) minmax(0,1fr);height:calc(100vh - 92px)}
.sx-l{background:#fff;border-right:1px solid var(--ln);display:flex;flex-direction:column;min-height:0}
.sx-fl{display:flex;gap:6px;padding:10px;overflow-x:auto;scrollbar-width:none;flex:none}.sx-fl::-webkit-scrollbar{display:none}
.sx-ch{flex:none;display:inline-flex;gap:4px;align-items:center;background:#fff;border:1px solid var(--ln);border-radius:999px;padding:6px 12px;font-size:13px;font-weight:700;color:#54656f;cursor:pointer;white-space:nowrap;min-height:34px}.sx-ch.on{background:#d9fdd3;border-color:#b7ecb0;color:#008069}.sx-ch b{font-size:11.5px;background:#e9edef;border-radius:99px;padding:0 6px}.sx-ch.on b{background:#fff}
.sx-q{margin:0 10px 8px;border:1px solid var(--ln);border-radius:999px;padding:8px 14px;font:inherit;font-size:14px;outline:0;background:#f7f8f9;flex:none}
.sx-ls{overflow-y:auto;flex:1;min-height:0}
.sx-row{display:flex;gap:11px;padding:11px 12px;border-bottom:1px solid #f0f2f5;cursor:pointer;align-items:flex-start}.sx-row:hover,.sx-row.on{background:#f0f9f6}
.sx-rb{flex:1;min-width:0}.sx-rn{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-weight:800}.sx-rn span.t{margin-left:auto;font-weight:600;font-size:11.5px;color:var(--mut)}
.sx-rs{color:var(--mut);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.sx-r{display:flex;flex-direction:column;min-width:0;min-height:0;background:#efeae2}
.sx-hd{background:#fff;border-bottom:1px solid var(--ln);padding:8px 12px;display:flex;gap:10px;align-items:center;flex:none;flex-wrap:wrap}.sx-hd .nm{font-weight:800;font-size:15px}.sx-hd small{display:block;color:var(--mut);font-size:12px}
.sx-back{display:none;border:0;background:none;font-size:24px;padding:0 6px;color:var(--g);cursor:pointer;min-width:34px;min-height:40px}
.sx-acts{background:#fff;border-bottom:1px solid var(--ln);padding:7px 12px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex:none}
.sx-sel{border:1px solid var(--ln);border-radius:999px;padding:5px 10px;font:inherit;font-size:12.5px;background:#fff;min-height:30px}
.sx-body{flex:1;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) 290px}
.sx-mid{display:flex;flex-direction:column;min-height:0}
.sx-msgs{flex:1;overflow-y:auto;padding:14px 14px 8px;display:flex;flex-direction:column;gap:6px}
.sx-m{max-width:82%;padding:7px 11px;border-radius:10px;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.08);word-wrap:break-word;overflow-wrap:anywhere}.sx-m small{display:block;font-size:11px;color:var(--mut);margin-top:2px;text-align:right}
.sx-m.out{align-self:flex-end;background:#d9fdd3}.sx-m.note{align-self:center;background:#fff6c9;font-size:12.5px;max-width:92%}
.sx-post{align-self:center;background:#e4e7ea;color:#54656f;font-size:12px;border-radius:8px;padding:5px 10px;max-width:92%;text-align:center}
.sx-comp{background:#fff;border-top:1px solid var(--ln);padding:8px 10px;flex:none}
.sx-sg{display:flex;gap:6px;align-items:center;font-size:12px;color:#54656f;font-weight:700;margin-bottom:5px;flex-wrap:wrap}
.sx-ta{width:100%;border:1px solid var(--ln);border-radius:14px;padding:9px 12px;font:inherit;font-size:14px;resize:vertical;min-height:66px;max-height:200px;background:#f0fbf6;outline:0}.sx-ta:focus{border-color:var(--a)}
.sx-cb{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center}
.sx-mode{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px}.sx-mode .sx-ch{padding:4px 11px;min-height:30px}.sx-mode .no{opacity:.45}
.sx-note{font-size:12px;color:var(--mut);margin-top:4px}
.sx-side{background:#fff;border-left:1px solid var(--ln);overflow-y:auto;padding:12px;min-height:0}
.sx-side h4{margin:14px 0 6px;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--mut)}.sx-side h4:first-child{margin-top:0}
.sx-box{background:#f7f8f9;border-radius:12px;padding:10px 12px;font-size:13px}.sx-box.cl{background:linear-gradient(135deg,#eef6ff,#f4efff);border:1px solid #e2e6ff}
.sx-plan{display:flex;flex-direction:column;gap:6px;margin-top:8px}.sx-plan .it{background:#fff;border:1px solid var(--ln);border-radius:10px;padding:8px 10px}.sx-plan .it small{display:block;color:var(--mut);margin:2px 0 6px}
.sx-tabs2{display:none}@media (min-width:900px){.sx-sync-t{display:inline!important}}
.sx-pg{padding:14px 14px 60px;max-width:1100px;margin:0 auto}
.sx-card{background:#fff;border:1px solid var(--ln);border-radius:14px;padding:14px;margin-bottom:12px}
.sx-card h3{margin:0 0 6px;font-size:15px}
.sx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.sx-steps{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.sx-steps div{background:#fff;border:1px solid var(--ln);border-radius:12px;padding:9px 13px;font-weight:700;font-size:13px;display:flex;gap:8px;align-items:center;flex:1;min-width:200px}.sx-steps i{font-style:normal;background:var(--a);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:none}
.sx-tbl{width:100%;border-collapse:collapse;font-size:13.5px}.sx-tbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);padding:6px 8px;border-bottom:1px solid var(--ln)}.sx-tbl td{padding:8px;border-bottom:1px solid #f0f2f5}
.sx-tw{overflow-x:auto}
.sx-in{width:100%;border:1px solid var(--ln);border-radius:10px;padding:9px 12px;font:inherit;font-size:14px;background:#fff;outline:0;min-height:40px}.sx-in:focus{border-color:var(--a)}
.sx-lab{display:block;font-weight:700;font-size:12.5px;margin:10px 0 3px}.sx-help{font-size:12px;color:var(--mut);margin-top:2px}
.sx-ov{--g:#008069;--a:#00a884;--ln:#e9edef;--mut:#667781;font-family:Inter,"Segoe UI",system-ui,-apple-system,Arial,sans-serif;color:#111b21;font-size:14px;line-height:1.4;position:fixed;inset:0;background:rgba(17,27,33,.5);z-index:300;display:flex;align-items:center;justify-content:center;padding:14px}
.sx-md{background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:92vh;overflow-y:auto;padding:16px 16px 18px}.sx-md h3{margin:0 0 4px;font-size:17px}
.sx-empty{padding:34px 18px;text-align:center;color:var(--mut)}
.sx-toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#111b21;color:#fff;padding:10px 18px;border-radius:999px;font-size:14px;font-weight:600;z-index:400;max-width:90vw;white-space:pre-line;text-align:center}.sx-toast.bad{background:#b3261e;border-radius:14px}
@media (max-width:899px){
 .sx-wrap{display:block;height:auto}
 .sx-l{border-right:0;height:calc(100dvh - 96px)}.sx-l.hide{display:none}
 .sx-r{display:none;height:100dvh}.sx-r.show{display:flex}.sx-hd{padding-left:60px}
 body:has(#wsEmpNav) .sx-l{height:calc(100dvh - 96px - 78px)}body:has(#wsEmpNav) .sx-r{height:calc(100dvh - 78px)}
 .sx-sync-t{display:none}
 .sx-back{display:block}.sx-top.in-thread{display:none}
 .sx-body{display:block;overflow:hidden;position:relative}.sx-mid{height:100%}.sx-side{display:none;height:100%;border-left:0}
 .sx-tabs2{display:flex;background:#fff;border-bottom:1px solid var(--ln);flex:none}.sx-tabs2 button{flex:1;border:0;background:none;padding:10px;font-weight:700;color:var(--mut);border-bottom:3px solid transparent;font-size:14px}.sx-tabs2 button.on{color:var(--g);border-color:var(--g)}
 .sx-r.t-side .sx-mid{display:none}.sx-r.t-side .sx-side{display:block}
 .sx-m{max-width:90%}.sx-md{max-height:96vh}
 .sx-pg{padding:12px 12px 60px}
}`;
    document.head.appendChild(s);
  }
  function toast(t, bad) { const e = document.createElement('div'); e.className = 'sx-toast' + (bad ? ' bad' : ''); e.textContent = t; document.body.appendChild(e); setTimeout(() => e.remove(), bad ? 6000 : 2800); }
  let lastErr = '';
  async function call(fn, ok) { lastErr = ''; try { const r = await fn(); if (ok !== false && (ok || (r && r.message))) toast(ok || r.message); return r; } catch (e) { lastErr = String(e.message || ''); toast(e.message || 'Something went wrong', true); return null; } }
  function modal(html, wide) { const o = document.createElement('div'); o.className = 'sx-ov'; o.innerHTML = '<div class="sx-md" style="' + (wide ? 'max-width:680px' : '') + '">' + html + '</div>'; document.body.appendChild(o); const close = () => o.remove(); o.addEventListener('mousedown', (e) => { if (e.target === o) close(); }); return { el: o, close }; }
  const av = (ch, name) => { const c = CH[ch] || ['#667781', '•']; return '<div class="sx-av" style="background:' + c[0] + '">' + (ch.indexOf('dm') > -1 || ch.indexOf('comment') > -1 || ch === 'email' ? esc(c[1]) : esc(ini(name))) + '</div>'; };
  const stars = (n) => n == null ? '' : '<span class="sx-pill o">' + n + '★</span>';

  /* ------------------------------ start ------------------------------ */
  async function start(host) {
    H = host; css(); H.className = 'sx'; H.innerHTML = '<div class="sx-empty">Loading…</div>';
    try { B = await api.get('bd.sx_boot'); } catch (e) { H.innerHTML = '<div class="sx-empty">Could not load: ' + esc(e.message) + '</div>'; return; }
    S.view = (B.conns.length === 0) ? 'connect' : 'inbox';
    const qs = new URLSearchParams(location.search); if (qs.get('item')) { S.view = 'inbox'; S.sel = Number(qs.get('item')) || 0; S.f = 'all'; }
    shell(); await go(S.view);
    if (S.sel) openItem(S.sel);
    clearInterval(timer); timer = setInterval(poll, 45000);
  }
  function shell() {
    H.innerHTML = '<div class="sx-top" id="sxTop"><div class="sx-t1"><h1>Inbox hub</h1><span id="sxPills"></span><span class="sp"></span><button class="sx-btn o s" id="sxSync">⟳<span class="sx-sync-t"> Sync now</span></button></div>'
      + '<div class="sx-tabs">' + [['inbox', 'Inbox'], ['summary', 'Summary'], ['connect', 'Connect'], ['settings', 'Settings']].filter((t) => t[0] !== 'settings' || B.mgr).map((t) => '<button class="sx-tab" data-v="' + t[0] + '">' + t[1] + '</button>').join('') + '</div></div><div id="sxBody"></div>';
    $$('.sx-tab', H).forEach((b) => b.onclick = () => go(b.dataset.v));
    $('#sxSync', H).onclick = async () => { const b = $('#sxSync', H); b.disabled = true; b.textContent = '…'; await call(() => api.post('bd.sx_sync', {})); b.disabled = false; b.innerHTML = '⟳<span class="sx-sync-t"> Sync now</span>'; if (S.view === 'inbox') loadList(); if (S.view === 'connect') refreshBoot().then(() => go('connect')); };
  }
  async function refreshBoot() { try { B = await api.get('bd.sx_boot'); } catch (e) { /* keep the old one */ } }
  async function go(v) {
    S.view = v; $$('.sx-tab', H).forEach((b) => b.classList.toggle('on', b.dataset.v === v)); $('#sxTop', H).classList.remove('in-thread');
    if (v === 'inbox') return inbox(); if (v === 'summary') return summary(); if (v === 'connect') return connect(); if (v === 'settings') return settings();
  }
  async function poll() {
    if (document.hidden || S.view !== 'inbox') return;
    try { await api.get('bd.sx_sync', { soft: 1 }); } catch (e) { /* the tick is best effort */ }
    if (!document.activeElement || document.activeElement.tagName !== 'TEXTAREA') loadList(true);
  }
  function pills(c) { $('#sxPills', H).innerHTML = (c && c.new ? '<span class="sx-pill g">' + c.new + ' waiting</span> ' : '') + (c && c.neg ? '<span class="sx-pill r">' + c.neg + ' unhappy</span>' : ''); }

  /* ------------------------------ inbox ------------------------------ */
  function inbox() {
    $('#sxBody', H).innerHTML = '<div class="sx-wrap"><div class="sx-l" id="sxL"><div class="sx-fl" id="sxFl"></div><input class="sx-q" id="sxQ" placeholder="Search name or words…" value="' + esc(S.q) + '"><div class="sx-ls" id="sxLs"></div></div><div class="sx-r" id="sxR"><div class="sx-empty" style="margin:auto">Choose a message on the left</div></div></div>';
    let t; $('#sxQ', H).oninput = (e) => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value; loadList(); }, 300); };
    loadList();
  }
  async function loadList(quiet) {
    const f = S.f === 'mine' ? 'new' : S.f; const params = { f: f === 'email' ? 'all' : f, q: S.q }; if (S.f === 'mine') params.mine = 1; if (['dm', 'comment', 'review'].indexOf(S.f) > -1) { params.ch = S.f; params.f = 'new'; } if (S.f === 'email') { params.ch = 'email'; params.f = 'new'; }
    const d = await call(() => api.get('bd.sx_list', params), false); if (!d) return; L = d; pills(d.counts);
    const cnt = { new: d.counts.new, neg: d.counts.neg, unowned: d.counts.unowned, cx: d.counts.cx, gone: d.counts.gone, dm: (d.per.ig_dm || 0) + (d.per.fb_dm || 0), comment: (d.per.ig_comment || 0) + (d.per.fb_comment || 0), review: (d.per.review_product || 0) + (d.per.review_google || 0) + (d.per.review_play || 0), email: d.per.email || 0 };
    $('#sxFl', H).innerHTML = FILTERS.filter((x) => (x[0] !== 'unowned' || B.mgr)).map((x) => '<button class="sx-ch' + (S.f === x[0] ? ' on' : '') + '" data-f="' + x[0] + '">' + x[1] + (cnt[x[0]] ? ' <b>' + cnt[x[0]] + '</b>' : '') + '</button>').join('');
    $$('.sx-ch', $('#sxFl', H)).forEach((b) => b.onclick = () => { S.f = b.dataset.f; loadList(); });
    const ls = $('#sxLs', H); if (!d.items.length) { ls.innerHTML = '<div class="sx-empty">' + (B.conns.length ? 'Nothing here 🎉' : 'Nothing is connected yet.<br><br><button class="sx-btn" id="sxGoC">Connect your first channel</button>') + '</div>'; const g = $('#sxGoC', H); if (g) g.onclick = () => go('connect'); return; }
    ls.innerHTML = d.items.map(row).join('') + (d.more ? '<div class="sx-empty"><button class="sx-btn o s" id="sxMore">Show more</button></div>' : '');
    $$('.sx-row', ls).forEach((r) => r.onclick = () => openItem(Number(r.dataset.id)));
    const mb = $('#sxMore', H); if (mb) mb.onclick = async () => { const d2 = await call(() => api.get('bd.sx_list', Object.assign({}, params, { off: L.items.length })), false); if (!d2) return; L.items = L.items.concat(d2.items); L.more = d2.more; mb.parentNode.remove(); ls.insertAdjacentHTML('beforeend', d2.items.map(row).join('')); $$('.sx-row', ls).forEach((r) => r.onclick = () => openItem(Number(r.dataset.id))); };
  }
  function row(v) {
    return '<div class="sx-row' + (v.id === S.sel ? ' on' : '') + '" data-id="' + v.id + '">' + av(v.channel, v.who) + '<div class="sx-rb"><div class="sx-rn"><span>' + esc(v.who) + '</span>' + stars(v.rating)
      + (v.gone ? '<span class="sx-pill">🗑 deleted</span>' : '') + (v.late ? '<span class="sx-pill r">overdue</span>' : '') + (v.is_ad ? '<span class="sx-pill b">Ad comment</span>' : '') + (v.sent === 'neg' && v.status === 'new' ? '<span class="sx-pill r">unhappy</span>' : '') + (v.cx_code ? '<span class="sx-pill o">⚠ ' + esc(v.cx_code) + '</span>' : '') + '<span class="t">' + esc(v.ago) + '</span></div>'
      + '<div class="sx-rs">' + esc(v.ch_label) + (v.subject ? ' · ' + esc(v.subject) : '') + ' — ' + esc(v.text) + '</div>' + (v.owner_name ? '<div class="sx-rs" style="font-size:12px">👤 ' + esc(v.owner_name) + '</div>' : (v.status === 'new' ? '<div class="sx-rs" style="font-size:12px;color:#9a6200">nobody has it</div>' : '')) + '</div></div>';
  }

  /* ------------------------------ one message ------------------------------ */
  async function openItem(id, keepTab) {
    S.sel = id; if (!keepTab) S.tab = 'chat'; planBox = null;
    $$('.sx-row', H).forEach((r) => r.classList.toggle('on', Number(r.dataset.id) === id));
    const R = $('#sxR', H); R.classList.add('show'); $('#sxL', H).classList.add('hide'); $('#sxTop', H).classList.add('in-thread');
    R.innerHTML = '<div class="sx-empty" style="margin:auto">Opening… Claude is preparing a reply</div>';
    const d = await call(() => api.get('bd.sx_item', { id }), false); if (!d) { R.innerHTML = '<div class="sx-empty" style="margin:auto">Could not open this message.</div>'; return; }
    D = d; const ok = d.modes.filter((m) => m.ok); mode = (ok[0] || d.modes[0] || {}).k || ''; drawItem();
  }
  function closeItem() { S.sel = 0; D = null; const R = $('#sxR', H); R.classList.remove('show'); R.innerHTML = '<div class="sx-empty" style="margin:auto">Choose a message on the left</div>'; $('#sxL', H).classList.remove('hide'); $('#sxTop', H).classList.remove('in-thread'); $$('.sx-row', H).forEach((r) => r.classList.remove('on')); }
  function drawItem() {
    const v = D.item, R = $('#sxR', H); const isMeta = v.channel === 'fb_comment' || v.channel === 'ig_comment';
    R.className = 'sx-r show' + (S.tab === 'side' ? ' t-side' : '');
    const team = (B.team || []).slice(); if (v.owner && !team.some((t) => t.id === v.owner)) team.push({ id: v.owner, name: v.owner_name });
    R.innerHTML = '<div class="sx-hd"><button class="sx-back" id="sxBk">‹</button>' + av(v.channel, v.who) + '<div style="flex:1;min-width:0"><div class="nm">' + esc(v.who) + ' ' + stars(v.rating) + (v.late ? ' <span class="sx-pill r">overdue</span>' : '') + '</div><small>' + esc(v.ch_label) + (v.email ? ' · ' + esc(v.email) : '') + (v.post ? ' · on “' + esc(v.post.slice(0, 50)) + '”' : '') + (v.is_ad ? ' · AD' : '') + '</small></div>'
      + (v.url ? '<a class="sx-btn o s" target="_blank" rel="noopener" href="' + esc(v.url) + '">Open ↗</a>' : '') + '</div>'
      + '<div class="sx-acts"><button class="sx-btn s" id="sxPlan">✨ Claude: what should we do?</button>'
      + (D.cx_ok ? (v.cx_code ? '<span class="sx-pill o">⚠ Complaint ' + esc(v.cx_code) + '</span>' : '<button class="sx-btn o s" id="sxCx">⚠ Raise complaint</button>') : '')
      + (v.status !== 'done' ? '<button class="sx-btn o s" data-st="done">✓ Done</button>' : '<button class="sx-btn o s" data-st="new">↺ Reopen</button>') + (v.status !== 'spam' ? '<button class="sx-btn o s" data-st="spam">🚫 Spam</button>' : '<button class="sx-btn o s" data-st="new">Not spam</button>')
      + (isMeta ? '<button class="sx-btn o s" id="sxGone">' + (v.gone ? '↺ Not deleted' : '🗑 Mark deleted') + '</button>' : '') + (isMeta ? '<button class="sx-btn o s" id="sxHide">' + (v.hidden ? '👁 Unhide' : '🙈 Hide on post') + '</button>' : '')
      + '<select class="sx-sel" id="sxOwn"><option value="0">👤 Nobody</option>' + team.map((t) => '<option value="' + t.id + '"' + (t.id === v.owner ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select></div>'
      + '<div class="sx-tabs2"><button data-t="chat" class="' + (S.tab === 'chat' ? 'on' : '') + '">Chat</button><button data-t="side" class="' + (S.tab === 'side' ? 'on' : '') + '">Claude &amp; tools</button></div>'
      + '<div class="sx-body"><div class="sx-mid"><div class="sx-msgs" id="sxMsgs">' + msgsHtml() + '</div>' + composer() + '</div><div class="sx-side" id="sxSide">' + sideHtml() + '</div></div>';
    const bm = $('#sxMsgs', H); bm.scrollTop = bm.scrollHeight; wire();
  }
  function msgsHtml() {
    const v = D.item; let h = v.gone ? '<div class="sx-post" style="color:#b3261e"><b>🗑 This comment was deleted on the post.</b> It cannot be answered any more.</div>' : ''; h += (v.post && (v.family === 'comment')) ? '<div class="sx-post">On: ' + esc(v.post) + '</div>' : '';
    if (v.subject && (v.family === 'mail' || v.family === 'review')) h += '<div class="sx-post"><b>' + esc(v.subject) + '</b></div>';
    return h + D.msgs.map((m) => '<div class="sx-m ' + m.dir + '">' + linkify(br(m.text)) + '<small>' + (m.dir === 'out' && m.by ? esc(m.by) + ' · ' : '') + esc(String(m.at).slice(5, 16)) + '</small></div>').join('');
  }
  function composer() {
    const v = D.item, m = D.modes.find((x) => x.k === mode) || D.modes[0]; if (!m) return '';
    const src = { bot: 'from the WhatsApp bot’s knowledge', claude: 'written by Claude', tmpl: 'ready-made template' }[D.sugg.source] || '';
    return '<div class="sx-comp">' + (D.modes.length > 1 ? '<div class="sx-mode">' + D.modes.map((x) => '<button class="sx-ch' + (x.k === mode ? ' on' : '') + (x.ok ? '' : ' no') + '" data-m="' + x.k + '">' + esc(x.label) + '</button>').join('') + '</div>' : '')
      + '<div class="sx-sg">✨ Claude suggests' + (src ? ' <span class="sx-pill">' + esc(src) + '</span>' : '') + '<span style="flex:1"></span><a href="#" id="sxAnother" style="color:var(--g);font-weight:800">Another</a></div>'
      + '<textarea class="sx-ta" id="sxTa" placeholder="Write a reply…">' + esc(D.sugg.text || '') + '</textarea>'
      + '<div class="sx-cb"><button class="sx-btn" id="sxSend"' + (m.ok ? '' : ' disabled') + '>' + (m.k === 'manual' ? '✓ ' : '➤ ') + esc(m.label) + '</button><button class="sx-btn o s" id="sxCopy">Copy</button><span class="sx-note" style="flex:1">' + esc(m.note || '') + '</span></div></div>';
  }
  function sideHtml() {
    const v = D.item;
    return '<h4>Claude</h4><div class="sx-box cl" id="sxPlanBox">' + (planBox || 'Tap <b>✨ Claude: what should we do?</b> — Claude reads this message and proposes the next steps (reply, owner, complaint, penalty). Nothing happens until you tap.') + '</div>'
      + '<h4>Notes</h4><div style="display:flex;gap:6px"><input class="sx-in" id="sxNt" placeholder="Add a note for the team"><button class="sx-btn o s" id="sxNtB">Add</button></div>'
      + '<h4>This customer</h4>' + (D.history.length ? D.history.map((h) => '<div class="sx-box" style="margin-bottom:6px;cursor:pointer" data-h="' + h.id + '"><b>' + esc(h.ch_label) + '</b> · ' + esc(h.ago) + ' ago ' + stars(h.rating) + '<br><span style="color:var(--mut)">' + esc(h.text) + '</span></div>').join('') : '<div class="sx-box" style="color:var(--mut)">No earlier messages from this person.</div>')
      + '<h4>Find their orders</h4><div style="display:flex;gap:6px"><input class="sx-in" id="sxOq" placeholder="Name, phone or order no." value="' + esc(v.who.replace(/^@/, '')) + '"><button class="sx-btn o s" id="sxOqB">Find</button></div><div id="sxOr" style="margin-top:6px"></div>'
      + (D.can_pen ? '<h4>Boss</h4><div class="sx-box"><button class="sx-btn o s" id="sxPen">₹ Ask a penalty…</button><div class="sx-note">Only asks the boss. Nothing is charged until the boss decides in Boss → Penalties.</div></div>' : '');
  }
  function wire() {
    const R = $('#sxR', H);
    $('#sxBk', R).onclick = closeItem;
    $$('.sx-tabs2 button', R).forEach((b) => b.onclick = () => { S.tab = b.dataset.t; drawItem(); });
    $$('[data-m]', R).forEach((b) => b.onclick = () => { mode = b.dataset.m; const t = $('#sxTa', R).value; D.sugg.text = t; drawItem(); });
    const ta = $('#sxTa', R); const fit = () => { ta.style.height = 'auto'; ta.style.height = Math.min(Math.max(ta.scrollHeight + 2, 66), window.innerHeight * 0.32) + 'px'; }; fit(); ta.addEventListener('input', fit);
    $('#sxAnother', R).onclick = async (e) => { e.preventDefault(); const a = e.target; a.textContent = '…'; const r = await call(() => api.post('bd.sx_suggest', { id: D.item.id }), false); if (r) { D.sugg = r.sugg; drawItem(); } else a.textContent = 'Another'; };
    $('#sxCopy', R).onclick = async () => { try { await navigator.clipboard.writeText(ta.value); toast('Copied'); } catch (e) { ta.select(); document.execCommand('copy'); toast('Copied'); } };
    $('#sxSend', R).onclick = async () => {
      const t = ta.value.trim(); if (!t) { toast('Write the reply first', true); return; } const m = D.modes.find((x) => x.k === mode);
      if (m && m.k !== 'manual' && !confirm('Send this reply now?\n\n' + t.slice(0, 300))) return;
      const b = $('#sxSend', R); b.disabled = true; const r = await call(() => api.post('bd.sx_reply', { id: D.item.id, mode, text: t })); if (!r) { b.disabled = false; if (lastErr.indexOf('deleted') > -1) { await openItem(D.item.id, true); loadList(true); } return; }
      await openItem(D.item.id, true); loadList(true);
    };
    $$('[data-st]', R).forEach((b) => b.onclick = async () => { const r = await call(() => api.post('bd.sx_status', { id: D.item.id, status: b.dataset.st })); if (r) { await openItem(D.item.id, true); loadList(true); } });
    $('#sxOwn', R).onchange = async (e) => { const r = await call(() => api.post('bd.sx_assign', { id: D.item.id, emp: Number(e.target.value) })); if (r) { D.item = r.item; loadList(true); } else openItem(D.item.id, true); };
    const cx = $('#sxCx', R); if (cx) cx.onclick = () => raiseCx();
    const gn = $('#sxGone', R); if (gn) gn.onclick = async () => { const r = await call(() => api.post('bd.sx_gone', { id: D.item.id, gone: D.item.gone ? 0 : 1 })); if (r) { await openItem(D.item.id, true); loadList(true); } };
    const hd = $('#sxHide', R); if (hd) hd.onclick = async () => { const r = await call(() => api.post('bd.sx_hide', { id: D.item.id, hide: !D.item.hidden })); if (r) { D.item = r.item; drawItem(); loadList(true); } };
    $('#sxPlan', R).onclick = () => plan();
    $('#sxNtB', R).onclick = async () => { const i = $('#sxNt', R); if (!i.value.trim()) return; const r = await call(() => api.post('bd.sx_note', { id: D.item.id, text: i.value })); if (r) openItem(D.item.id, true); };
    $$('[data-h]', R).forEach((b) => b.onclick = () => openItem(Number(b.dataset.h)));
    $('#sxOqB', R).onclick = async () => { const q = $('#sxOq', R).value; const r = await call(() => api.get('bd.sx_orders', { q }), false); if (!r) return; $('#sxOr', R).innerHTML = r.orders.length ? r.orders.map((o) => '<div class="sx-box" style="margin-bottom:5px"><b>' + esc(o.name) + '</b> · ₹' + o.total + ' · ' + esc(o.at) + '<br><span style="color:var(--mut)">' + esc(o.customer) + ' · ' + esc(o.pay || '') + ' · ' + esc(o.ship || 'not shipped') + '</span></div>').join('') : '<div class="sx-note">No orders found.</div>'; };
    const pn = $('#sxPen', R); if (pn) pn.onclick = () => penalty();
  }
  async function plan() {
    S.tab = 'side'; const b = $('#sxPlanBox', H); if (!b) { drawItem(); }
    $('#sxPlanBox', H).innerHTML = 'Claude is reading this message…'; if (window.matchMedia('(max-width:899px)').matches) { $('#sxR', H).classList.add('t-side'); $$('.sx-tabs2 button', H).forEach((x) => x.classList.toggle('on', x.dataset.t === 'side')); }
    const r = await call(() => api.get('bd.sx_plan', { id: D.item.id }), false); if (!r) { $('#sxPlanBox', H).textContent = 'Could not ask Claude.'; return; }
    const html = (r.note ? '<div>' + br(r.note) + '</div>' : '') + (r.actions.length ? '<div class="sx-plan">' + r.actions.map((a, i) => '<div class="it"><b>' + esc(a.label) + '</b><small>' + esc(a.why) + '</small><button class="sx-btn s" data-pa="' + i + '">Do it</button></div>').join('') + '</div>' : '<div>Nothing more to do here.</div>');
    planBox = html; $('#sxPlanBox', H).innerHTML = html;
    $$('[data-pa]', H).forEach((b2) => b2.onclick = async () => {
      const a = r.actions[Number(b2.dataset.pa)];
      if (a.k === 'reply') { S.tab = 'chat'; drawItem(); const t = $('#sxTa', H); if (t) t.focus(); return; }
      if (a.k === 'assign') { const x = await call(() => api.post('bd.sx_assign', { id: D.item.id, emp: a.emp })); if (x) { await openItem(D.item.id, true); loadList(true); } return; }
      if (a.k === 'complaint') { const x = await call(() => api.post('bd.sx_complaint', { id: D.item.id, problem: a.problem })); if (x) { await openItem(D.item.id, true); loadList(true); } return; }
      if (a.k === 'done') { const x = await call(() => api.post('bd.sx_status', { id: D.item.id, status: 'done' })); if (x) { await openItem(D.item.id, true); loadList(true); } return; }
      if (a.k === 'pen') return penalty(a.emp, a.amount);
    });
  }
  async function raiseCx() {
    const v = D.item; const pr = [['leak', 'Leak / damaged'], ['wrong', 'Wrong item'], ['notdelivered', 'Not delivered'], ['quality', 'Smell / quality'], ['refund', 'Refund'], ['staff', 'Staff behaviour'], ['other', 'Other']];
    const m = modal('<h3>⚠ Raise a complaint</h3><div class="sx-note">Creates a case for the Complaints team from this ' + esc(v.ch_label.toLowerCase()) + '.</div><label class="sx-lab">What is the problem?</label><select class="sx-in" id="cxP">' + pr.map((p) => '<option value="' + p[0] + '">' + p[1] + '</option>').join('') + '</select><label class="sx-lab">How urgent?</label><select class="sx-in" id="cxU"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select><div style="display:flex;gap:8px;margin-top:14px"><button class="sx-btn" id="cxOk">Raise it</button><button class="sx-btn o" id="cxNo">Cancel</button></div>');
    $('#cxNo', m.el).onclick = m.close; $('#cxOk', m.el).onclick = async () => { const r = await call(() => api.post('bd.sx_complaint', { id: v.id, problem: $('#cxP', m.el).value, urgency: $('#cxU', m.el).value })); if (r) { m.close(); await openItem(v.id, true); loadList(true); } };
  }
  function penalty(emp, amt) {
    const v = D.item, team = (B.team || []).slice(); if (v.owner && !team.some((t) => t.id === v.owner)) team.push({ id: v.owner, name: v.owner_name }); const pre = emp || v.owner || 0;
    const m = modal('<h3>₹ Ask a penalty</h3><div class="sx-note">This only <b>asks the boss</b>. The boss decides in Boss → Penalties; nothing is charged here.</div><label class="sx-lab">For whom?</label><select class="sx-in" id="pnE"><option value="">Choose…</option>' + team.map((t) => '<option value="' + t.id + '"' + (t.id === pre ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select><label class="sx-lab">Amount (₹)</label><input class="sx-in" id="pnA" type="number" min="1" max="5000" value="' + (amt || D.pen_amount || 100) + '"><label class="sx-lab">Reason</label><input class="sx-in" id="pnR" placeholder="e.g. unhappy customer waited a day" value="' + (v.late ? 'Not answered in time (' + esc(v.ago) + ')' : '') + '"><div style="display:flex;gap:8px;margin-top:14px"><button class="sx-btn" id="pnOk">Ask the boss</button><button class="sx-btn o" id="pnNo">Cancel</button></div>');
    $('#pnNo', m.el).onclick = m.close; $('#pnOk', m.el).onclick = async () => { const r = await call(() => api.post('bd.sx_pen', { id: v.id, emp: Number($('#pnE', m.el).value), amount: Number($('#pnA', m.el).value), reason: $('#pnR', m.el).value })); if (r) m.close(); };
  }

  /* ------------------------------ summary ------------------------------ */
  async function summary() {
    const body = $('#sxBody', H); body.innerHTML = '<div class="sx-pg"><div class="sx-empty">Claude is writing the summary…</div></div>';
    const d = await call(() => api.get('bd.sx_summary', { days: S.days }), false); if (!d) { body.innerHTML = '<div class="sx-pg"><div class="sx-empty">Could not load the summary.</div></div>'; return; }
    const s = d.stats, t = s.total, mins = (m) => m == null ? '—' : (m < 60 ? m + ' min' : m < 1440 ? (m / 60).toFixed(1) + ' h' : (m / 1440).toFixed(1) + ' d');
    body.innerHTML = '<div class="sx-pg"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px"><b style="font-size:17px">Summary</b><select class="sx-sel" id="sxDays">' + [7, 14, 30, 90].map((n) => '<option value="' + n + '"' + (n === S.days ? ' selected' : '') + '>Last ' + n + ' days</option>').join('') + '</select></div>'
      + '<div class="sx-card" style="background:linear-gradient(135deg,#eef6ff,#f4efff)"><h3>✨ ' + (d.source === 'claude' ? 'Claude’s report' : 'Report') + '</h3><div style="white-space:pre-wrap">' + esc(d.text) + '</div>' + (d.source !== 'claude' ? '<div class="sx-note">Claude’s written report appears here when Claude (AI) is turned on in Boss Engine settings.</div>' : '') + '</div>'
      + '<div class="sx-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">' + [['Came in', t.n], ['Waiting', t.waiting], ['Overdue', t.late], ['Unhappy', t.neg], ['No owner', t.unowned], ['Avg first reply', mins(t.reply_min)], ['Complaints raised', t.complaints]].map((k) => '<div class="sx-card" style="margin:0"><div class="sx-note">' + k[0] + '</div><b style="font-size:22px">' + k[1] + '</b></div>').join('') + '</div>'
      + '<div class="sx-card" style="margin-top:12px"><h3>By channel</h3><div class="sx-tw"><table class="sx-tbl"><tr><th>Channel</th><th>In</th><th>Waiting</th><th>Overdue</th><th>Unhappy</th><th>Rating</th><th>First reply</th></tr>' + s.channels.filter((c) => c.n || c.waiting).map((c) => '<tr><td>' + c.icon + ' ' + esc(c.label) + '</td><td>' + c.n + '</td><td>' + c.waiting + '</td><td>' + (c.late ? '<span class="sx-pill r">' + c.late + '</span>' : 0) + '</td><td>' + c.neg + '</td><td>' + (c.rating == null ? '—' : c.rating + '★') + '</td><td>' + mins(c.reply_min) + '</td></tr>').join('') + '</table></div></div>'
      + '<div class="sx-card"><h3>By person</h3>' + (s.people.length ? '<div class="sx-tw"><table class="sx-tbl"><tr><th>Person</th><th>Got</th><th>Answered</th><th>Waiting</th><th>Late</th><th>Avg reply</th></tr>' + s.people.map((p) => '<tr><td>' + esc(p.name) + '</td><td>' + p.n + '</td><td>' + p.answered + '</td><td>' + p.waiting + '</td><td>' + (p.late ? '<span class="sx-pill r">' + p.late + '</span>' : 0) + '</td><td>' + mins(p.reply_min) + '</td></tr>').join('') + '</table></div>' : '<div class="sx-note">Nobody owns a message yet. Add people to the Inbox team in Settings so messages are shared out automatically.</div>') + '</div>'
      + (s.worst.length ? '<div class="sx-card"><h3>Unhappy and still waiting</h3>' + s.worst.map((w) => '<div class="sx-row" data-w="' + w.id + '" style="border-radius:10px">' + av(w.channel, w.who) + '<div class="sx-rb"><div class="sx-rn"><span>' + esc(w.who) + '</span>' + stars(w.rating) + '<span class="t">' + esc(w.ago) + '</span></div><div class="sx-rs">' + esc(w.text) + '</div></div></div>').join('') + '</div>' : '')
      + (B.mgr ? '<div class="sx-card"><h3>Ask Claude</h3><div class="sx-note" style="margin-bottom:6px">For example: “What are customers unhappy about this week?” or “Who is slow?”</div><div style="display:flex;gap:6px"><input class="sx-in" id="sxAq" placeholder="Your question"><button class="sx-btn" id="sxAb">Ask</button></div><div id="sxAa" class="sx-box" style="margin-top:8px;display:none;white-space:pre-wrap"></div></div>' : '') + '</div>';
    $('#sxDays', H).onchange = (e) => { S.days = Number(e.target.value); summary(); };
    $$('[data-w]', H).forEach((r) => r.onclick = () => { S.f = 'all'; go('inbox').then(() => openItem(Number(r.dataset.w))); });
    const ab = $('#sxAb', H); if (ab) ab.onclick = async () => { const q = $('#sxAq', H).value.trim(); if (!q) return; ab.disabled = true; const a = $('#sxAa', H); a.style.display = 'block'; a.textContent = 'Claude is thinking…'; const r = await call(() => api.post('bd.sx_ask', { q }), false); a.textContent = r ? r.answer : 'No answer.'; ab.disabled = false; };
  }

  /* ------------------------------ connect ------------------------------ */
  async function connect() {
    await refreshBoot(); const body = $('#sxBody', H); const forms = B.forms || {}; const kinds = ['meta', 'email', 'judgeme', 'google', 'play'];
    const names = { meta: ['Instagram + Facebook', 'DMs · comments · Messenger · page and ad comments'], email: ['Email addresses', 'Read and answer your mailboxes'], judgeme: ['Judge.me', 'Shopify product reviews (you reply in Judge.me)'], google: ['Google reviews', 'Business Profile · every store location'], play: ['Google Play', 'App reviews, with replies'] };
    const nOn = B.conns.filter((c) => c.status === 'ok').length;
    body.innerHTML = '<div class="sx-pg"><div class="sx-steps"><div><i>1</i>Connect</div><div><i>2</i>Claude syncs everything</div><div><i>3</i>Claude suggests a reply on every item</div><div><i>4</i>You tap Send — or assign</div></div>'
      + '<div class="sx-note" style="margin:0 0 10px"><b>' + nOn + '</b> connected. Nothing is ever sent by itself — only a person tapping Send sends.</div><div class="sx-grid">'
      + kinds.map((k) => { const cs = B.conns.filter((c) => c.kind === k); const f = forms[k]; const kc = KIND[k];
        return '<div class="sx-card" style="margin:0"><div style="display:flex;gap:10px;align-items:center"><div class="sx-av" style="background:' + kc[0] + '">' + kc[1] + '</div><div style="flex:1;min-width:0"><b>' + names[k][0] + '</b><div class="sx-note" style="margin:0">' + names[k][1] + '</div></div><span class="sx-pill ' + (cs.length ? 'g' : 'o') + '">' + (cs.length ? 'Connected' : 'Not connected') + '</span></div>'
          + cs.map((c) => '<div class="sx-box" style="margin-top:8px"><b>' + esc(c.label) + '</b><div class="sx-note">' + (c.status === 'error' ? '<span style="color:#b3261e">⚠ ' + esc(c.err || 'Problem') + '</span><br>' : '') + 'Last sync ' + (c.last_sync ? esc(c.ago) + ' ago' : 'never') + ' · ' + c.synced_n + ' new so far' + (c.status === 'ok' && c.err ? '<br>Note: ' + esc(c.err) : '') + '</div>'
            + (B.mgr ? '<div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap"><button class="sx-btn o s" data-sync="' + c.id + '">Sync now</button><button class="sx-btn o s" data-edit="' + c.id + '">Edit keys</button><button class="sx-btn r s" data-off="' + c.id + '">Disconnect</button></div>' : '') + '</div>').join('')
          + (B.mgr ? '<div style="margin-top:10px"><button class="sx-btn' + (cs.length ? ' o' : '') + ' s" data-add="' + k + '">' + (cs.length ? (k === 'email' ? '+ Add another address' : '+ Add another') : 'Connect') + '</button></div>' : (cs.length ? '' : '<div class="sx-note" style="margin-top:8px">Ask the boss to connect this.</div>')) + '</div>'; }).join('')
      + '</div><div class="sx-card" style="margin-top:12px"><h3>Coming next</h3><div class="sx-note">YouTube comments · Threads · WhatsApp Channels · Justdial / IndiaMART leads · Amazon / Flipkart seller messages · Shopify contact-form and Inbox messages. Tell us which one matters most.</div></div></div>';
    $$('[data-add]', body).forEach((b) => b.onclick = () => connectForm(b.dataset.add, null));
    $$('[data-edit]', body).forEach((b) => b.onclick = () => connectForm(B.conns.find((c) => c.id === Number(b.dataset.edit)).kind, B.conns.find((c) => c.id === Number(b.dataset.edit))));
    $$('[data-sync]', body).forEach((b) => b.onclick = async () => { b.disabled = true; b.textContent = 'Syncing…'; await call(() => api.post('bd.sx_sync', { id: Number(b.dataset.sync) })); connect(); });
    $$('[data-off]', body).forEach((b) => b.onclick = async () => { if (!confirm('Disconnect this channel? The saved key is deleted; the messages stay.')) return; const r = await call(() => api.post('bd.sx_conn_off', { id: Number(b.dataset.off) })); if (r) connect(); });
  }
  function connectForm(kind, conn) {
    const f = B.forms[kind]; const h = B.hook; const vals = conn ? conn.fields || {} : {};
    const m = modal('<h3>' + f.icon + ' ' + esc(f.title) + '</h3><div class="sx-note">' + esc(f.about) + '</div><details style="margin:8px 0"><summary style="cursor:pointer;font-weight:700;font-size:13px">How to get these (step by step)</summary><ol style="margin:6px 0 0 18px;padding:0;font-size:13px">' + f.steps.map((x) => '<li style="margin-bottom:4px">' + esc(x) + '</li>').join('') + '</ol></details>'
      + (kind === 'email' && !B.imap ? '<div class="sx-box" style="background:#fff1d6;margin-bottom:6px">⚠ The PHP <b>imap</b> extension is off on this hosting, so mailboxes cannot be read yet. hPanel → Advanced → PHP Configuration → Extensions → tick “imap”.</div>' : '')
      + f.fields.map((x) => '<label class="sx-lab">' + esc(x.label) + '</label>' + (x.type === 'area' ? '<textarea class="sx-in" data-k="' + x.k + '" rows="5" placeholder="' + (conn && conn.set.indexOf(x.k) > -1 ? '(saved — leave empty to keep)' : esc(x.ph || '')) + '"></textarea>' : '<input class="sx-in" data-k="' + x.k + '" type="' + (x.secret ? 'password' : 'text') + '" autocomplete="off" placeholder="' + (x.secret && conn && conn.set.indexOf(x.k) > -1 ? '(saved — leave empty to keep)' : esc(x.ph || '')) + '" value="' + esc(x.secret ? '' : (vals[x.k] != null ? vals[x.k] : (x.def || ''))) + '">') + (x.help ? '<div class="sx-help">' + esc(x.help) + '</div>' : '')).join('')
      + (kind === 'meta' && h ? '<div class="sx-box" style="margin-top:12px"><b>Instant updates (optional)</b><div class="sx-help">In your Meta app → Webhooks, paste:</div><div class="sx-help">Callback URL: <code style="word-break:break-all">' + esc(h.url) + '</code></div><div class="sx-help">Verify token: <code>' + esc(h.verify_token) + '</code></div>' + (h.last ? '<div class="sx-help">Last call from Meta: ' + esc(h.last) + '</div>' : '') + '</div>' : '')
      + '<div style="display:flex;gap:8px;margin-top:14px"><button class="sx-btn" id="cnOk">Test &amp; connect</button><button class="sx-btn o" id="cnNo">Cancel</button></div><div class="sx-note" id="cnMsg"></div>', true);
    $('#cnNo', m.el).onclick = m.close;
    $('#cnOk', m.el).onclick = async () => { const values = {}; $$('[data-k]', m.el).forEach((i) => { values[i.dataset.k] = i.value; }); const b = $('#cnOk', m.el); b.disabled = true; b.textContent = 'Testing…'; $('#cnMsg', m.el).textContent = '';
      try { const r = await api.post('bd.sx_conn_save', { kind, id: conn ? conn.id : 0, values }); toast(r.message); m.close(); connect(); } catch (e) { $('#cnMsg', m.el).innerHTML = '<span style="color:#b3261e">' + esc(e.message) + '</span>'; b.disabled = false; b.textContent = 'Test & connect'; } };
  }

  /* ------------------------------ settings ------------------------------ */
  async function settings() {
    await refreshBoot(); const body = $('#sxBody', H); const c = B.cfg; const emps = B.employees || []; const nm = (id) => (emps.find((e) => e.id === id) || {}).name || ('#' + id);
    const seat = (k, ids, label, about) => '<div class="sx-card"><h3>' + label + '</h3><div class="sx-note">' + about + '</div><div style="margin:8px 0">' + (ids.length ? ids.map((i) => '<span class="sx-pill g" style="margin:0 6px 6px 0;padding:5px 10px">' + esc(nm(i)) + ' <a href="#" data-rm="' + k + ':' + i + '" style="color:#b3261e;text-decoration:none;margin-left:4px">✕</a></span>').join('') : '<span class="sx-note">Nobody yet</span>') + '</div><select class="sx-sel" data-add="' + k + '"><option value="">+ Add a person…</option>' + emps.filter((e) => ids.indexOf(e.id) < 0).map((e) => '<option value="' + e.id + '">' + esc(e.name) + '</option>').join('') + '</select></div>';
    body.innerHTML = '<div class="sx-pg"><div class="sx-card"><h3>Time limits (minutes)</h3><div class="sx-note">A message not answered within its limit becomes “overdue”, the person and the boss are told, and it shows in Boss → Needs you.</div><div class="sx-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">'
      + [['sla_dm', 'DMs (Instagram / Messenger)'], ['sla_comment', 'Comments'], ['sla_review', 'Reviews'], ['sla_mail', 'Email'], ['pen_amount', 'Default penalty ask (₹)']].map((x) => '<div><label class="sx-lab">' + x[1] + '</label><input class="sx-in" type="number" min="1" data-c="' + x[0] + '" value="' + esc(c[x[0]]) + '"></div>').join('') + '</div>'
      + '<div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">' + [['auto_assign', 'Share new messages out to the Inbox team automatically (the least busy present person)'], ['suggest', 'Claude prepares a suggested reply for every new message'], ['push', 'Send phone notifications (new unhappy message, overdue, 24-hour window closing)']].map((x) => '<label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" data-c="' + x[0] + '" ' + (c[x[0]] === '1' ? 'checked' : '') + ' style="margin-top:3px"> <span>' + x[1] + '</span></label>').join('') + '</div><div style="margin-top:12px"><button class="sx-btn" id="stSave">Save</button></div></div>'
      + seat('worker', B.team_ids, 'Inbox team', 'These people answer messages and get them shared out. They only see this Inbox, not the settings.') + seat('boss', B.boss_ids, 'Inbox boss', 'Sees everything, assigns messages, connects channels and asks for penalties. Admin and HR already can.') + '</div>';
    $('#stSave', body).onclick = async () => { const b = {}; $$('[data-c]', body).forEach((i) => { b[i.dataset.c] = i.type === 'checkbox' ? i.checked : Number(i.value); }); const r = await call(() => api.post('bd.sx_cfg_save', b)); if (r) refreshBoot(); };
    const save = async (k, ids) => { const r = await call(() => api.post('bd.sx_team_save', { who: k, emps: ids })); if (r) settings(); };
    $$('[data-add]', body).forEach((s) => s.onchange = () => { if (s.value) save(s.dataset.add, (s.dataset.add === 'boss' ? B.boss_ids : B.team_ids).concat([Number(s.value)])); });
    $$('[data-rm]', body).forEach((a) => a.onclick = (e) => { e.preventDefault(); const [k, id] = a.dataset.rm.split(':'); save(k, (k === 'boss' ? B.boss_ids : B.team_ids).filter((x) => x !== Number(id))); });
  }
  return { start };
})();