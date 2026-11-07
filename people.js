/* People (Boss Engine section) — screens for people.html. Talks to people.php (bd.pp_*) and, for the personal record, the existing employees / documents actions.
   Replaces the old People menu: department lanes or rows, role filter, photo cards, salary / penalties / documents, seats and logins, all in one place. */
(function () {
  'use strict';
  var PHONE = function () { return window.matchMedia('(max-width: 820px)').matches; };
  var S = { B: null, O: null, month: new Date().toISOString().slice(0, 7), view: 'cols', role: '', flt: '', q: '', ex: false, show: { sal: 1, pen: 1, doc: 1, task: 1, seat: 0 }, open: {}, dr: null, page: '' };
  try { var sv = JSON.parse(localStorage.getItem('pp_prefs') || '{}'); if (sv.view) S.view = sv.view; if (sv.show) S.show = Object.assign(S.show, sv.show); } catch (e) { /* storage may be blocked */ }
  function savePrefs() { try { localStorage.setItem('pp_prefs', JSON.stringify({ view: S.view, show: S.show })); } catch (e) { /* ignore */ } }

  /* ---------------------------------------------------------------- helpers */
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function inr(n) { n = Number(n || 0); return '₹' + Math.round(n).toLocaleString('en-IN'); }
  function k(n) { n = Number(n || 0); if (Math.abs(n) >= 10000000) return '₹' + (n / 10000000).toFixed(2) + ' Cr'; if (Math.abs(n) >= 100000) return '₹' + (n / 100000).toFixed(2) + ' L'; return inr(n); }
  function d8(s) { if (!s) return ''; var d = new Date(String(s).slice(0, 10) + 'T00:00:00'); return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' }); }
  function toast(m, err) { var t = $('#ppToast'); if (!t) return; t.textContent = m; t.className = 'on' + (err ? ' err' : ''); clearTimeout(toast._t); toast._t = setTimeout(function () { t.className = ''; }, err ? 5200 : 2800); }
  function fail(e) { toast((e && e.message) || String(e), true); }
  function call(a, o) { o = o || {}; var act = 'bd.pp_' + a; if (o.body) return api.post(act, o.body); return api.get(act, o.params || {}); }
  function opt(v, t, sel) { return '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(t) + '</option>'; }
  var COLORS = ['#6d5dfc', '#e4572e', '#17a2b8', '#2a9d8f', '#f4a261', '#264653', '#8338ec', '#e63946', '#457b9d', '#b5651d', '#3a7d44', '#a23b72'];
  function colorOf(s) { var h = 0; s = String(s || ''); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return COLORS[h % COLORS.length]; }
  function initials(n) { var p = String(n || '?').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); }
  function photoUrl(p) { return p ? API_BASE_URL.replace('/api.php', '') + '/' + String(p).replace(/^\/+/, '') : ''; }
  function avatar(p, big) {
    var u = photoUrl(p.photo); var st = u ? 'background-image:url(\'' + esc(u) + '\')' : 'background:' + colorOf(p.name);
    return '<span class="pp-av" style="' + st + '"' + (big ? ' id="ppAvBig" title="Change photo"' : '') + '>' + (u ? '' : esc(initials(p.name))) + '</span>';
  }
  function busy(b, on, t) { if (!b) return; if (on) { b._t = b.textContent; b.disabled = true; if (t) b.textContent = t; } else { b.disabled = false; if (b._t) b.textContent = b._t; } }
  /* the old People pages, opened INSIDE People (same pages, same data; they sit in a frame under the People bar) */
  var PAGES = [['salary', 'Salary sheet'], ['attendance_admin', 'Attendance'], ['leave_requests', 'Leave'], ['approvals', 'Approvals'], ['documents', 'Documents'], ['doc_check', 'Doc check'], ['agreements', 'Agreements'], ['handover', 'Handover'], ['penalties', 'Penalty list'], ['history', 'Decline history'], ['people_admin', 'Admin team']];
  function pageOk(k) { return PAGES.some(function (x) { return x[0] === k; }); }
  function go(tab) {
    if (tab === 'ex_employees') { if (S.dr) S.dr.close(); S.page = ''; S.ex = true; S.role = ''; S.flt = ''; return loadAll(false).then(render).catch(fail); }
    if (!pageOk(tab)) { var t = window.top || window; t.location.href = 'hub.html?w=people&t=' + tab; return; }
    if (S.dr) S.dr.close(); S.page = tab; try { history.replaceState(null, '', '#' + tab); } catch (e) { /* ignore */ } render(); try { window.scrollTo(0, 0); } catch (e2) { /* ignore */ }
  }
  function boss(tab) { var t = window.top || window; t.location.href = 'hub.html?w=boss&t=' + tab; }
  function person(id) { return (S.O.people || []).filter(function (p) { return p.id === id; })[0]; }
  function dept(id) { return (S.B.departments || []).filter(function (d) { return d.id === id; })[0]; }
  function genPw() { var a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', o = ''; try { var u = new Uint32Array(9); crypto.getRandomValues(u); for (var i = 0; i < 9; i++) o += a[u[i] % a.length]; } catch (e) { for (var j = 0; j < 9; j++) o += a[Math.floor(Math.random() * a.length)]; } return o; }
  function overlay(html, cls) {
    var o = document.createElement('div'); o.className = 'pp-ov'; o.innerHTML = html;
    o.close = function () { if (o.parentNode) o.parentNode.removeChild(o); document.removeEventListener('keydown', o._k); if (S.dr === o) S.dr = null; };
    o._k = function (e) { if (e.key === 'Escape') o.close(); }; document.addEventListener('keydown', o._k);
    o.addEventListener('mousedown', function (e) { if (e.target === o) o.close(); });
    document.body.appendChild(o); return o;
  }

  /* ---------------------------------------------------------------- data */
  function loadAll(fresh) {
    return Promise.all([call('boot', { params: { month: S.month } }), call('overview', { params: { month: S.month, refresh: fresh ? 1 : '', status: S.ex ? 'ex' : '' } })]).then(function (r) { S.B = r[0]; S.O = r[1]; });
  }
  function reload(fresh) { return call('overview', { params: { month: S.month, refresh: fresh ? 1 : '', status: S.ex ? 'ex' : '' } }).then(function (o) { S.O = o; return call('boot', { params: { month: S.month } }); }).then(function (b) { S.B = b; render(); }); }

  /* ---------------------------------------------------------------- filters + groups */
  function match(p) {
    if (S.role === '__none') { if (p.login) return false; } else if (S.role && (!p.login || p.login.role !== S.role)) return false;
    if (S.flt === 'docs' && !(p.docs_missing > 0)) return false;
    if (S.flt === 'nologin' && p.login) return false;
    if (S.flt === 'pen' && !(p.pen > 0)) return false;
    if (S.flt === 'seatless' && p.seats.length) return false;
    if (S.flt === 'late' && !(p.t && (p.t.late > 0 || p.t.missed_today > 0))) return false;
    if (S.flt === 'ask' && !(p.t && p.t.ask > 0)) return false;
    if (S.q) { var q = S.q.toLowerCase(); var hay = (p.name + ' ' + p.code + ' ' + p.designation + ' ' + p.mobile + ' ' + (p.login ? p.login.username : '')).toLowerCase(); if (hay.indexOf(q) < 0) return false; }
    return true;
  }
  function groups() {
    var list = (S.O.people || []).filter(match), by = {}, out = [];
    (S.B.departments || []).forEach(function (d) { by[d.id] = { id: d.id, name: d.name, section: d.section, section_id: d.section_id, people: [] }; out.push(by[d.id]); });
    var none = { id: 0, name: 'No department', section: '', section_id: 0, people: [] };
    list.forEach(function (p) { (by[p.dept_id] || none).people.push(p); });
    if (none.people.length) out.push(none);
    out.forEach(function (g) {
      g.monthly = 0; g.est = 0; g.pen = 0; g.docs = 0; g.tasks = 0; g.done = 0; g.late = 0; g.fu = 0; g.ask = 0; g.people.forEach(function (p) { g.monthly += p.monthly; g.est += (p.est || 0); g.pen += p.pen; g.docs += p.docs_missing; var t = p.t || {}; g.tasks += t.today || 0; g.done += t.done || 0; g.late += t.late || 0; g.fu += t.fu || 0; g.ask += t.ask || 0; });
      g.boss = []; (S.B.seats || []).forEach(function (s) { if (s.section_id && s.section_id === g.section_id && (s.kind === 'section_boss' || s.kind === 'boss')) s.holders.forEach(function (h) { var q = person(h); if (q) g.boss.push(q.name.split(' ')[0]); }); });
    });
    var active = !!(S.q || S.role || S.flt);
    return out.filter(function (g) { return !active || g.people.length || (g.id && !S.q && !S.role && !S.flt); });
  }

  /* ---------------------------------------------------------------- screen */
  function kpiHtml() {
    var T = S.O.totals || {}; var ex = S.ex;
    function kk(cls, label, val, sub, flt) { return '<button class="pp-k ' + cls + (S.flt === flt && flt ? ' on' : '') + '" data-act="flt" data-v="' + (flt || '') + '"><small>' + label + '</small><b>' + val + '</b>' + (sub ? '<i>' + sub + '</i>' : '') + '</button>'; }
    if (ex) return kk('', 'Ex-employees', T.people || 0, '', '');
    return kk('', 'People', T.people || 0, '', '') + kk('acc', 'Est. salary ' + esc(S.month), k(T.est), 'of ' + k(T.monthly), '') + kk('bad', 'Est. penalties', k(T.pen), '', 'pen')
      + kk('bad', 'Documents pending', T.docs_people || 0, 'people', 'docs') + kk('', 'Tasks today', (T.done || 0) + ' / ' + (T.tasks || 0), (T.late ? T.late + ' late' : 'none late') + (T.fu ? ' · ' + T.fu + ' follow-ups' : ''), 'late')
      + kk(T.ask ? 'bad' : '', 'Penalties waiting', T.ask || 0, T.ask ? k(T.ask_amt) + ' for you to decide' : 'nothing to decide', 'ask') + kk('', 'Without login', T.no_login || 0, '', 'nologin') + kk('', 'Empty seats', T.empty_seats || 0, '', '');
  }
  function railHtml() {
    var T = S.O.totals || {}; var h = '<h4>Role</h4><button data-act="role" data-v="" class="' + (!S.role ? 'on' : '') + '">All <span>' + (T.people || 0) + '</span></button>';
    (S.B.roles || []).forEach(function (r) { h += '<button data-act="role" data-v="' + esc(r.name) + '" class="' + (S.role === r.name ? 'on' : '') + '">' + esc(r.name) + ' <span>' + r.count + '</span></button>'; });
    h += '<button data-act="role" data-v="__none" class="' + (S.role === '__none' ? 'on' : '') + '">No login <span>' + (T.no_login || 0) + '</span></button>';
    if (S.B.me.admin) h += '<button data-act="roles-page">＋ Roles and permissions</button>';
    h += '<div class="opts"><div class="sep"></div><h4>Show on cards</h4>';
    [['sal', 'Salary'], ['pen', 'Penalties'], ['doc', 'Documents'], ['task', 'Tasks'], ['seat', 'Seats']].forEach(function (x) { h += '<label><input type="checkbox" data-act="show" data-v="' + x[0] + '"' + (S.show[x[0]] ? ' checked' : '') + '> ' + x[1] + '</label>'; });
    h += '<div class="sep"></div><button data-act="ex" class="' + (S.ex ? 'on' : '') + '">' + (S.ex ? '← Back to people' : 'Ex-employees') + '</button></div>';
    return h;
  }
  function cardHtml(p) {
    var tg = '';
    if (S.show.sal && !S.ex && p.monthly > 0) tg += '<span class="num">' + inr(p.monthly) + '</span>';
    if (S.show.pen && p.pen > 0) tg += '<span class="pp-t warn num">pen ' + inr(p.pen) + '</span>';
    if (S.show.doc && !S.ex) tg += p.docs_missing > 0 ? '<span class="pp-t bad">' + p.docs_missing + ' doc' + (p.docs_missing > 1 ? 's' : '') + '</span>' : '<span class="pp-t ok">docs ✓</span>';
    var t = p.t || {};
    if (S.show.task && !S.ex && t.today > 0) tg += '<span class="pp-t ' + (t.late || t.missed_today ? 'bad' : (t.done >= t.today ? 'ok' : 'blue')) + ' num" title="Tasks done today / total">' + t.done + '/' + t.today + ' tasks' + (t.late ? ' · ' + t.late + ' late' : '') + '</span>';
    if (S.show.task && !S.ex && t.fu > 0) tg += '<span class="pp-t blue" title="Follow-ups still open">' + t.fu + ' follow-up' + (t.fu > 1 ? 's' : '') + '</span>';
    if (S.show.pen && !S.ex && t.ask > 0) tg += '<span class="pp-t warn num" title="Penalty waiting for your decision">ask ' + inr(t.ask_amt) + '</span>';
    var sub = esc(p.designation || p.code) + (p.login ? ' · ' + esc(p.login.role) : '');
    if (S.show.seat && p.seats.length) sub = p.seats.map(function (s) { return esc(s.title); }).join(', ');
    return '<div class="pp-c' + (S.dr && S.dr.pid === p.id ? ' sel' : '') + '" data-act="open" data-id="' + p.id + '" draggable="' + (S.ex || PHONE() ? 'false' : 'true') + '" title="' + esc(p.name) + '">' + avatar(p) + '<span class="nm"><b>' + esc(p.name) + '</b><i>' + sub + '</i></span><span class="tg">' + tg + '</span><span class="nl' + (p.login ? ' on' : '') + '" title="' + (p.login ? 'Has a login' : 'No login') + '"></span></div>';
  }
  function headHtml(g) {
    var open = S.open[g.id] != null ? S.open[g.id] : !PHONE();
    var l2 = '';
    if (S.show.sal) l2 += '<span class="pp-t blue num">' + k(g.est || g.monthly) + '</span>';
    if (S.show.pen && g.pen > 0) l2 += '<span class="pp-t warn num">pen ' + k(g.pen) + '</span>';
    if (S.show.doc && g.docs > 0) l2 += '<span class="pp-t bad">' + g.docs + ' docs</span>';
    if (S.show.task && g.tasks > 0) l2 += '<span class="pp-t ' + (g.late ? 'bad' : 'blue') + ' num" title="Tasks done today / total">' + g.done + '/' + g.tasks + ' tasks' + (g.late ? ' · ' + g.late + ' late' : '') + '</span>';
    if (S.show.task && g.fu > 0) l2 += '<span class="pp-t blue">' + g.fu + ' follow-ups</span>';
    if (S.show.pen && g.ask > 0) l2 += '<span class="pp-t warn">' + g.ask + ' to decide</span>';
    var gd = g.id ? dept(g.id) : null, qc = gd && gd.section_code && window.CQ ? window.CQ.countFor(gd.section_code, gd.name) : 0, qn = qc && S.B.me.admin && !S.ex ? '<button class="pp-t warn" data-act="ask-go" data-id="' + g.id + '" title="Claude has a question about this department" style="border:0;cursor:pointer">Claude asks · ' + qc + '</button>' : '';
    if (qn) l2 += qn;
    return '<div class="pp-lh"><div class="l1" data-act="fold" data-id="' + g.id + '"><b>' + esc(g.name) + '</b><span class="cnt">' + g.people.length + '</span>' + (g.id && S.B.me.admin ? '<button class="dots" data-act="dmenu" data-id="' + g.id + '" title="Rename or delete">⋯</button>' : '') + '</div>'
      + (S.ex ? '' : '<div class="l2">' + l2 + '</div>') + (g.boss.length ? '<div class="boss">Boss: ' + esc(g.boss.join(', ')) + '</div>' : (g.id && g.section_id ? '<div class="boss">No boss seat holder</div>' : '')) + '</div>' + (open ? '' : '');
  }
  function mainHtml() {
    var gs = groups(); var h = '';
    var shut = function (g) { var o = S.open[g.id]; return (o != null ? !o : PHONE()) ? ' shut' : ''; };
    if (S.view === 'cols') {
      h += '<div class="pp-cols">';
      gs.forEach(function (g) { h += '<div class="pp-lane' + shut(g) + '" data-dept="' + g.id + '">' + headHtml(g) + '<div class="pp-list">' + (g.people.length ? g.people.map(cardHtml).join('') : '<div class="pp-empty">' + (g.id ? 'Drag people here' : '') + '</div>') + '</div></div>'; });
      h += '</div>';
    } else {
      h += '<div class="pp-rows">';
      gs.forEach(function (g) { h += '<div class="pp-row' + shut(g) + '" data-dept="' + g.id + '">' + headHtml(g) + '<div class="pp-list">' + (g.people.length ? g.people.map(cardHtml).join('') : '<div class="pp-empty">' + (g.id ? 'Drag people here' : '') + '</div>') + '</div></div>'; });
      h += '</div>';
    }
    if (!gs.length) h = '<div class="pp-note">Nobody matches this filter.</div>';
    return h;
  }
  function pagesHtml() {
    return '<div class="pp-pages"><button data-act="page" data-v="" class="' + (!S.page ? 'on' : '') + '">People</button>' + PAGES.filter(function (x) { return x[0] !== 'people_admin' || S.B.me.admin; }).map(function (x) { return '<button data-act="page" data-v="' + x[0] + '" class="' + (S.page === x[0] ? 'on' : '') + '">' + x[1] + '</button>'; }).join('') + '</div>';
  }
  function render() {
    var el = $('#pp'); if (!el) return; var keepQ = document.activeElement && document.activeElement.id === 'ppQ';
    var h = (S.page ? '' : '') + '<div class="pp-top"><input class="pp-in grow" id="ppQ" placeholder="Search name, code, mobile, username" value="' + esc(S.q) + '"><input type="month" id="ppMonth" value="' + esc(S.month) + '" title="Salary month">'
      + '<span class="pp-seg"><button data-act="view" data-v="cols" class="' + (S.view === 'cols' ? 'on' : '') + '" title="Departments side by side">▥ Columns</button><button data-act="view" data-v="rows" class="' + (S.view === 'rows' ? 'on' : '') + '" title="Departments one under another">☰ Rows</button></span>'
      + '<button class="pp-btn" data-act="refresh" title="Reload salary, penalty and task figures now">↻</button><span class="mut sm" id="ppUp" title="People re-reads the Boss Engine every minute">Live · updated ' + new Date().toTimeString().slice(0, 5) + '</span>' + (S.ex ? '' : '<button class="pp-btn" data-act="titles" title="CEO, CFO ... with pay and working time">★ Titles</button><button class="pp-btn" data-act="docwatch" title="Licences, insurance and other documents ending soon">📄 Doc dates</button><button class="pp-btn acc" data-act="add">＋ Add person</button>') + '</div>';
    if (S.page) h = '';
    h += pagesHtml();
    if (S.page) h += '<div class="pp-frame"><iframe allow="camera; microphone; geolocation; clipboard-read; clipboard-write; fullscreen" title="' + esc(S.page) + '" src="' + esc(S.page) + '.html?in=sales"></iframe></div>';
    else h += '<div class="pp-kpi">' + kpiHtml() + '</div><div id="ppAsk"></div><div class="pp-body"><div class="pp-rail">' + railHtml() + '</div><div class="pp-main">' + mainHtml() + '</div></div>';
    el.innerHTML = h; if (window.CQ && $('#ppAsk')) window.CQ.mount($('#ppAsk'), { max: 1, people: true });
    if (keepQ) { var q = $('#ppQ'); q.focus(); try { q.setSelectionRange(q.value.length, q.value.length); } catch (e) { /* ignore */ } }
  }

  /* ---------------------------------------------------------------- drawer */
  /* ---------------------------------------------------------------- Titles (CEO, CFO ...): pay + working time per title, people under each */
  function titlesDialog() {
    if (S.dr) S.dr.close();
    var o = overlay('<div class="dr"><div class="pp-load mut">Loading…</div></div>'); S.dr = o; var admin = S.B.me.admin, TL = { titles: [], no_title: [] };
    function modeSel(v) { return ['fixed:Fixed times', 'hours:Hours a day, any time', 'free:No punch rule'].map(function (x) { var a = x.split(':'); return opt(a[0], a[1], v); }).join(''); }
    function draw() {
      var h = '<div class="hd"><b>Titles</b> <span class="mut sm">Tap a person to open them. Pay and timing here are the defaults; "Apply" copies them to the people under the title.</span> <button class="pp-btn" id="tlX">Close</button></div><div style="padding:8px 12px;overflow:auto;max-height:78vh">';
      TL.titles.forEach(function (t, i) {
        h += '<div class="pp-card" data-i="' + i + '" style="border:1px solid #e2e5ea;border-radius:10px;padding:10px;margin-bottom:10px">'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><input class="pp-in" data-f="name" value="' + esc(t.name) + '" style="width:150px;font-weight:700">'
          + (admin ? '<input class="pp-in" data-f="salary" type="number" min="0" placeholder="Monthly salary" value="' + (t.salary || '') + '" style="width:120px">' : '')
          + '<select class="pp-in" data-f="mode">' + modeSel(t.mode) + '</select>'
          + '<span data-w="fixed"' + (t.mode === 'fixed' ? '' : ' hidden') + '><input class="pp-in" data-f="shift_start" type="time" value="' + esc(t.shift_start) + '"> to <input class="pp-in" data-f="shift_end" type="time" value="' + esc(t.shift_end) + '"> late after <input class="pp-in" data-f="late_after" type="number" min="0" value="' + t.late_after + '" style="width:60px"> min</span>'
          + '<span data-w="hours"' + (t.mode === 'hours' ? '' : ' hidden') + '><input class="pp-in" data-f="hours" type="number" step="0.5" min="1" max="16" value="' + t.hours + '" style="width:70px"> hours a day</span>'
          + (admin ? '<button class="pp-btn acc" data-a="save">' + (t.defined ? 'Save' : 'Set up') + '</button>' + (t.id ? '<button class="pp-btn" data-a="del">Remove</button>' : '') + (t.people.length ? '<button class="pp-btn" data-a="apply">Apply to all ' + t.people.length + '</button>' : '') : '')
          + '</div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">'
          + (t.people.map(function (p) { return '<button class="pp-btn" data-a="open" data-id="' + p.id + '" style="display:flex;gap:6px;align-items:center">' + avatar({ photo: p.photo, name: p.name }) + esc(p.name) + (p.salary ? ' <span class="mut sm">₹' + Math.round(p.salary).toLocaleString('en-IN') + '</span>' : '') + '</button>'; }).join('') || '<span class="mut sm">Nobody yet</span>') + '</div></div>';
      });
      if (admin) h += '<div style="border:1px dashed #b8bec8;border-radius:10px;padding:10px;margin-bottom:10px"><b>New title</b> <input class="pp-in" id="tlNew" placeholder="e.g. CFO" style="width:160px"> <button class="pp-btn acc" data-a="new">Add</button></div>';
      if (TL.no_title.length) h += '<div class="mut sm" style="margin:6px 0">Without a title: ' + TL.no_title.map(function (p) { return esc(p.name); }).join(', ') + '. Tell Claude on the Boss page: "make Ravi the CFO, salary 80000, 8 hours a day".</div>';
      h += '</div>';
      $('.dr', o).innerHTML = h;
    }
    function load() { return call('titles').then(function (d) { TL = d; draw(); }).catch(function (e) { o.close(); fail(e); }); }
    o.addEventListener('change', function (e) { var t = e.target; if (t.dataset && t.dataset.f === 'mode') { var c = t.closest('.pp-card'); $$('[data-w]', c).forEach(function (w) { w.hidden = w.dataset.w !== t.value; }); } });
    o.addEventListener('click', function (e) {
      var b = e.target.closest('[data-a],#tlX'); if (!b) return; e.stopPropagation();
      if (b.id === 'tlX') return o.close();
      var a = b.dataset.a, card = b.closest('.pp-card'), t = card ? TL.titles[Number(card.dataset.i)] : null;
      function body() { var x = {}; $$('[data-f]', card).forEach(function (f) { x[f.dataset.f] = f.value; }); x.id = t.id || 0; return x; }
      if (a === 'open') { var id = Number(b.dataset.id); o.close(); return openPerson(id); }
      if (a === 'new') { var nm = ($('#tlNew', o).value || '').trim(); if (!nm) return; return call('title_save', { body: { name: nm } }).then(function () { toast('Added.'); return load(); }).catch(fail); }
      if (a === 'save') return call('title_save', { body: body() }).then(function () { toast('Saved.'); return load(); }).catch(fail);
      if (a === 'del') { if (!confirm('Remove this title\'s pay and timing defaults? People keep the name.')) return; return call('title_delete', { body: { id: t.id } }).then(load).catch(fail); }
      if (a === 'apply') { if (!confirm('Copy salary and working time to all ' + t.people.length + ' people titled ' + t.name + '?')) return; var nb = body(); return call('title_save', { body: nb }).then(function () { return call('title_assign', { body: { emp_ids: t.people.map(function (p) { return p.id; }), title: nb.name, apply: 1 } }); }).then(function () { toast('Done.'); return loadAll(true).then(function () { render(); return load(); }); }).catch(fail); }
    });
    load();
  }

  /* ---------------------------------------------------------------- Document end dates (licence, insurance ...) */
  function docWatchDialog() {
    if (S.dr) S.dr.close();
    var o = overlay('<div class="dr"><div class="pp-load mut">Loading…</div></div>'); S.dr = o;
    function draw(d) {
      var h = '<div class="hd"><b>Document end dates</b> <span class="mut sm">Ending in the next ' + d.days + ' days, already ended, or with no end date yet.</span> <button class="pp-btn" id="dwX">Close</button></div><div style="padding:8px 12px;overflow:auto;max-height:78vh">';
      h += '<h4>Ending soon / ended (' + d.expiring.length + ')</h4>' + (d.expiring.length ? d.expiring.map(function (r) { var late = Number(r.left_days) < 0; return '<div style="display:flex;gap:8px;align-items:center;padding:4px 0">' + avatar({ photo: r.photo, name: r.name }) + '<button class="pp-btn" data-id="' + r.emp + '">' + esc(r.name) + '</button> ' + esc(r.doc) + ' <span class="pp-t ' + (late ? 'bad' : 'warn') + '">' + (late ? 'ended ' + Math.abs(r.left_days) + ' days ago' : r.left_days + ' days left') + ' · ' + esc(r.exp) + '</span></div>'; }).join('') : '<div class="mut sm">Nothing is ending soon.</div>');
      h += '<h4 style="margin-top:14px">Licence / insurance / permit with no end date (' + d.no_end_date.length + ')</h4>' + (d.no_end_date.length ? d.no_end_date.map(function (r) { return '<div style="padding:3px 0"><button class="pp-btn" data-id="' + r.emp + '">' + esc(r.name) + '</button> ' + esc(r.doc) + '</div>'; }).join('') : '<div class="mut sm">None.</div>');
      h += '<div style="border:1px dashed #b8bec8;border-radius:10px;padding:10px;margin-top:14px"><b>Set one end date for a whole document type</b><div class="mut sm">Fills only the documents that have no end date yet.</div><select class="pp-in" id="dwT">' + d.types.map(function (t) { return opt(t.id, t.name, ''); }).join('') + '</select> <input class="pp-in" type="date" id="dwD"> <button class="pp-btn acc" id="dwGo">Set</button></div></div>';
      $('.dr', o).innerHTML = h;
    }
    function load() { return call('docs_watch', { params: { days: 30 } }).then(draw).catch(function (e) { o.close(); fail(e); }); }
    o.addEventListener('click', function (e) {
      var t = e.target; if (t.id === 'dwX') return o.close();
      if (t.dataset && t.dataset.id) { var id = Number(t.dataset.id); o.close(); return openPerson(id, 'docs'); }
      if (t.id === 'dwGo') { var ty = $('#dwT', o).value, dt = $('#dwD', o).value; if (!ty || !dt) return toast('Choose the document type and the date.', true); if (!confirm('Set this end date on every document of this type that has none?')) return; call('doc_expiry', { body: { type_id: Number(ty), expiry_date: dt } }).then(function (r) { toast(r.message); return load(); }).catch(fail); }
    });
    load();
  }

  function openPerson(id, tab) {
    if (S.dr) S.dr.close();
    var o = overlay('<div class="dr"><div class="pp-load mut">Loading…</div></div>'); S.dr = o; o.pid = id; o.tab = tab || 'overview'; o.data = null;
    $$('.pp-c').forEach(function (c) { c.classList.toggle('sel', Number(c.dataset.id) === id); });
    return call('person', { params: { id: id, month: S.month } }).then(function (d) { o.data = d; drawer(o); }).catch(function (e) { o.close(); fail(e); });
  }
  function refreshDrawer(o, keepTab) { return call('person', { params: { id: o.pid, month: S.month } }).then(function (d) { o.data = d; if (!keepTab) o.tab = o.tab; drawer(o); }); }
  var TABS = [['overview', 'Overview'], ['login', 'Login'], ['seats', 'Seats'], ['tasks', 'Tasks'], ['salary', 'Salary'], ['docs', 'Documents'], ['records', 'Records']];
  function drawer(o) {
    var d = o.data, p = d.person, row = person(o.pid) || {}; var admin = S.B.me.admin;
    var dp = dept(Number(p.department_id));
    var chips = (d.login ? '<span class="pp-t ok">login: ' + esc(d.login.username) + '</span> ' : '<span class="pp-t mut">no login</span> ') + (d.seat_ids.length ? '<span class="pp-t blue">' + d.seat_ids.length + ' seat' + (d.seat_ids.length > 1 ? 's' : '') + '</span>' : '');
    var h = '<div class="dr"><div class="hd">' + avatar({ photo: p.photo, name: p.full_name }, true) + '<div><h2>' + esc(p.full_name) + '</h2><div class="mut sm">' + esc(p.employee_code) + ' · ' + esc((dp && dp.name) || p.department_name || 'No department') + ' · ' + esc(p.designation || '') + '</div><div style="margin-top:3px">' + chips + '</div></div><button class="x" data-act="dr-close">✕</button></div>'
      + '<div class="pp-tabs">' + TABS.map(function (t) { return '<button data-act="tab" data-v="' + t[0] + '" class="' + (o.tab === t[0] ? 'on' : '') + '">' + t[1] + (t[0] === 'docs' && row.docs_missing ? ' (' + row.docs_missing + ')' : '') + (t[0] === 'tasks' && row.t && row.t.late ? ' (' + row.t.late + ' late)' : '') + '</button>'; }).join('') + '</div><div id="ppTab"></div></div>';
    o.innerHTML = h; var tabEl = $('#ppTab', o); tabEl.innerHTML = tabHtml(o); bindTab(o, tabEl);
    $('#ppAvBig', o).addEventListener('click', function () { var f = document.createElement('input'); f.type = 'file'; f.accept = 'image/jpeg,image/png,image/webp'; f.onchange = function () { if (f.files[0]) savePerson(o, null, f.files[0]); }; f.click(); });
  }
  function kv(a, b) { return '<div class="row"><span>' + a + '</span><b>' + b + '</b></div>'; }
  function tabHtml(o) {
    var d = o.data, p = d.person, row = person(o.pid) || {}, admin = S.B.me.admin, t = o.tab, h = '';
    if (t === 'overview') {
      if (o.edit) return editForm(o, p);
      var dopts = (S.B.departments || []).map(function (x) { return opt(x.id, x.name, p.department_id); }).join('');
      h += '<div class="pp-tiles"><div class="pp-tile"><small>Salary / month</small><b>' + inr(p.monthly_salary) + '</b></div><div class="pp-tile"><small>Est. ' + esc(S.month) + '</small><b>' + (d.salary ? inr(d.salary.net_pay) : '—') + '</b></div><div class="pp-tile"><small>Penalties</small><b style="color:' + (row.pen > 0 ? 'var(--bad)' : 'inherit') + '">' + inr(row.pen || 0) + '</b></div></div>';
      var ts = d.task_stat || row.t || {};
      h += '<div class="pp-tiles"><div class="pp-tile"><small>Tasks today</small><b>' + (ts.done || 0) + ' / ' + (ts.today || 0) + '</b></div><div class="pp-tile"><small>Late now</small><b style="color:' + (ts.late > 0 ? 'var(--bad)' : 'inherit') + '">' + (ts.late || 0) + '</b></div><div class="pp-tile"><small>Follow-ups open</small><b>' + (ts.fu || 0) + '</b></div></div>';
      h += '<h3>Department</h3><div class="pp-pw"><select id="ppDept">' + dopts + '</select><button class="pp-btn dark" data-act="move">Move</button></div>';
      h += '<h3>Details</h3>' + kv('Designation', esc(p.designation || '—')) + kv('Mobile', esc(p.mobile || '—')) + kv('Email', esc(p.email || '—')) + kv('Joined', esc(d8(p.joining_date) || '—')) + kv('Employment', esc(p.employment_type || '—')) + kv('Reports to', esc(p.manager_name || '—')) + kv('Status', esc(p.status || '—'));
      h += '<div class="acts"><button class="pp-btn acc" data-act="edit">Edit details</button>' + (p.status !== 'ex' ? '' : '') + '</div>';
    } else if (t === 'login') {
      var u = d.login, roles = S.B.all_roles || [];
      h += u ? '<div class="msg">Login is active. Username <b>' + esc(u.username) + '</b>, role <b>' + esc(u.role) + '</b>.</div>' : '<div class="pp-note" style="background:#f0f1f4;color:var(--mut)">This person has no login yet. Create one so they can open the HRMS.</div>';
      if (!admin) return h + '<div class="mut sm">Only Admin creates or changes logins.</div>';
      var sug = (p.full_name || '').toLowerCase().replace(/[^a-z ]/g, '').trim().split(/\s+/); sug = sug.length > 1 ? sug[0] + '.' + sug[sug.length - 1][0] : (sug[0] || '');
      h += '<div class="fr"><div class="full"><label class="fl">Username</label><input type="text" id="lgUser" autocomplete="off" value="' + esc(u ? u.username : sug) + '"></div>'
        + '<div class="full"><label class="fl">Role (what they may open)</label><select id="lgRole">' + roles.map(function (r) { return opt(r, r, u ? u.role : 'Employee'); }).join('') + '</select></div>'
        + '<div class="full"><label class="fl">' + (u ? 'New password (leave empty to keep the old one)' : 'Password (6 or more characters)') + '</label><div class="pp-pw"><input type="text" id="lgPw" autocomplete="off" placeholder="' + (u ? 'unchanged' : 'type or generate') + '"><button class="pp-btn" data-act="genpw">Generate</button></div></div></div>'
        + '<div class="acts"><button class="pp-btn acc" data-act="login-save">' + (u ? 'Save login' : 'Create login') + '</button>' + (u ? '<button class="pp-btn red" data-act="login-del">Remove login</button>' : '') + '</div><div id="lgOut"></div>'
        + '<p class="mut sm" style="margin-top:10px">Role decides the menu: Employee sees My Day plus the seats you give here. Roles and their permissions are managed under “Roles and permissions”.</p>';
    } else if (t === 'seats') {
      var bySec = {}; (S.B.seats || []).forEach(function (s) { (bySec[s.section] = bySec[s.section] || []).push(s); });
      h += '<p class="mut sm">A seat is a responsibility in the Boss Engine (Inbox team, Complaints desks, Dispatch boss…). Tick the seats this person holds. The Inbox, Complaints and Boss Engine use them at once.</p>';
      Object.keys(bySec).forEach(function (sec) { h += '<div class="pp-seatsec"><h4>' + esc(sec) + '</h4>' + bySec[sec].map(function (s) { var on = d.seat_ids.indexOf(s.id) > -1, auto = /\.pos_/.test(s.code || ''); var others = s.holders.filter(function (x) { return x !== o.pid; }).length; return '<label class="pp-seat"><input type="checkbox" data-seat="' + s.id + '"' + (on ? ' checked' : '') + (admin && !auto ? '' : ' disabled') + '> ' + esc(s.title) + (auto ? ' <small class="mut">(position — follows the designation)</small>' : '') + '<i>' + (others ? others + ' other' + (others > 1 ? 's' : '') : 'nobody else') + '</i></label>'; }).join('') + '</div>'; });
      h += '<div class="acts"><button class="pp-btn acc" data-act="seats-save"' + (admin ? '' : ' disabled') + '>Save seats</button></div>';
    } else if (t === 'tasks') {
      var ts2 = d.task_stat || {}, KL = { daily: 'Daily', number: 'Number', short: 'Short', temp: 'Temporary', followup: 'Follow-up', fix: 'To fix', buy: 'To buy', improve: 'Improve' }, SL = { open: 'Open', done: 'Done', missed: 'Missed', review: 'Checking' };
      h += '<div class="pp-tiles"><div class="pp-tile"><small>Done today</small><b>' + (ts2.done || 0) + ' / ' + (ts2.today || 0) + '</b></div><div class="pp-tile"><small>Late now</small><b style="color:' + (ts2.late > 0 ? 'var(--bad)' : 'inherit') + '">' + (ts2.late || 0) + '</b></div><div class="pp-tile"><small>Missed, 7 days</small><b>' + (ts2.missed7 || 0) + '</b></div></div>';
      h += '<h3>Tasks for this person</h3>';
      h += (d.tasks || []).length ? d.tasks.map(function (x) { var cls = x.status === 'done' ? 'ok' : (x.status === 'missed' || x.late ? 'bad' : (x.status === 'review' ? 'warn' : 'blue')); return '<div class="pp-doc"><div class="n"><b>' + esc(x.title) + '</b><span class="mut sm">' + esc(KL[x.kind] || x.kind) + (x.date ? ' · ' + esc(d8(x.date)) : '') + (x.due ? ' · by ' + esc(String(x.due).slice(11, 16)) : '') + (x.pen > 0 ? ' · penalty ' + inr(x.pen) : '') + '</span></div><span class="pp-t ' + cls + '">' + esc(x.late && x.status === 'open' ? 'Late' : (SL[x.status] || x.status)) + '</span></div>'; }).join('') : '<div class="pp-empty">No tasks for this person today. Give the person a seat, or add a task for their department.</div>';
      h += '<h3>Penalties from the Boss Engine</h3>';
      h += (d.task_pens || []).length ? d.task_pens.map(function (x) { return kv(esc(x.reason || 'Missed task') + ' <span class="sm">' + esc(d8(x.at)) + ' · ' + esc({ ask: 'waiting for you', charged: 'charged', waived: 'waived', shadow: 'test mode', capped: 'limit reached' }[x.state] || x.state) + '</span>', inr(x.amount)); }).join('') : '<div class="pp-empty">None.</div>';
      h += '<div class="acts"><button class="pp-btn" data-act="boss" data-v="be_work">All work</button><button class="pp-btn" data-act="boss" data-v="be_pens">Penalties</button><button class="pp-btn" data-act="boss" data-v="be_problems">Problems</button></div>';
    } else if (t === 'salary') {
      var s = d.salary;
      if (!s) return '<div class="pp-note" style="background:#f0f1f4;color:var(--mut)">No monthly salary is set. Set it under Overview → Edit details.</div>';
      h += '<div class="pp-tiles"><div class="pp-tile"><small>Monthly</small><b>' + inr(s.monthly_salary) + '</b></div><div class="pp-tile"><small>Earned so far</small><b>' + inr(s.earned) + '</b></div><div class="pp-tile"><small>Net, ' + esc(S.month) + '</small><b>' + inr(s.net_pay) + '</b></div></div>';
      var c = s.counts || {};
      h += kv('Days present', c.present || 0) + kv('Absent', c.absent || 0) + kv('Leave', c.leave_units || 0) + kv('Holiday / week off', (c.holiday || 0) + (c.sunday_off || 0)) + kv('Penalties', '<span style="color:var(--bad)">' + inr(s.penalty_total) + '</span>');
      if ((s.penalties || []).length) { h += '<h3>Penalties this month</h3>'; s.penalties.forEach(function (x) { h += kv(esc(x.title) + ' <span class="sm">' + esc(d8(x.date)) + '</span>', inr(x.amount)); }); }
      h += '<div class="acts"><button class="pp-btn" data-act="go" data-v="salary">Open the full salary sheet</button><button class="pp-btn" data-act="go" data-v="penalties">Penalty list</button></div>';
    } else if (t === 'docs') {
      h += '<p class="mut sm">Missing documents are in red. Upload for the person, or approve what is waiting.</p>';
      (d.documents || []).forEach(function (x) {
        var st = !x.doc_id ? '<span class="pp-t bad">Missing</span>' : (x.status === 'approved' ? '<span class="pp-t ok">Approved</span>' : '<span class="pp-t warn">' + esc(x.status === 'pending' ? 'Waiting' : x.status || 'Waiting') + '</span>');
        h += '<div class="pp-doc"><div class="n"><b>' + esc(x.name) + (x.required ? '' : ' <span class="mut sm">(optional)</span>') + '</b><span class="mut sm">' + (x.number ? esc(x.number) + ' ' : '') + (x.expiry ? 'expires ' + esc(d8(x.expiry)) : '') + '</span></div>' + st
          + (x.doc_id ? '<button class="pp-btn sm" data-act="doc-view" data-id="' + x.doc_id + '">View</button>' : '')
          + (x.doc_id && x.status !== 'approved' && admin ? '<button class="pp-btn sm acc" data-act="doc-ok" data-id="' + x.doc_id + '">Approve</button>' : '')
          + '<button class="pp-btn sm" data-act="doc-up" data-type="' + x.type_id + '">' + (x.doc_id ? 'Replace' : 'Upload') + '</button><input type="file" accept=".pdf,.jpg,.jpeg,.png" data-file="' + x.type_id + '"></div>';
      });
      if (!(d.documents || []).length) h += '<div class="pp-empty">No document types are set up yet.</div>';
      h += '<div class="acts"><button class="pp-btn" data-act="go" data-v="approvals">Approvals</button><button class="pp-btn" data-act="go" data-v="doc_check">Doc check</button></div>';
    } else if (t === 'records') {
      h += '<h3>This month</h3>' + kv('Days present', d.attendance.present) + '<h3>Leave</h3>';
      h += (d.leave || []).length ? d.leave.map(function (l) { return kv(esc(l.leave_type) + ' <span class="sm">' + esc(d8(l.date_from)) + (l.date_to && l.date_to !== l.date_from ? ' → ' + esc(d8(l.date_to)) : '') + '</span>', esc(l.status)); }).join('') : '<div class="pp-empty">No leave records.</div>';
      h += '<h3>Agreements</h3>' + ((d.agreements || []).length ? d.agreements.map(function (a) { return kv(esc(a.name) + ' <span class="sm">' + esc(d8(a.at)) + '</span>', esc(a.status)); }).join('') : '<div class="pp-empty">No agreements sent.</div>');
      h += '<div class="acts"><button class="pp-btn" data-act="go" data-v="attendance_admin">Attendance</button><button class="pp-btn" data-act="go" data-v="leave_requests">Leave</button><button class="pp-btn" data-act="go" data-v="agreements">Agreements</button><button class="pp-btn" data-act="go" data-v="handover">Handover</button></div>';
    }
    return h;
  }
  function editForm(o, p) {
    var isNew = !p; p = p || { department_id: '', joining_date: new Date().toISOString().slice(0, 10) };
    var dopts = opt('', '— choose —', p.department_id) + (S.B.departments || []).map(function (x) { return opt(x.id, x.name, p.department_id); }).join('');
    var mopts = opt('', '— none —', p.reporting_manager_id) + (S.B.managers || []).filter(function (m) { return m.id !== p.employee_id; }).map(function (m) { return opt(m.id, m.name, p.reporting_manager_id); }).join('');
    function f(id, lab, val, type, full) { return '<div' + (full ? ' class="full"' : '') + '><label class="fl">' + lab + '</label><input type="' + (type || 'text') + '" id="' + id + '" value="' + esc(val == null ? '' : val) + '"></div>'; }
    return '<form id="ppForm" class="fr">' + f('fName', 'Full name *', p.full_name, 'text', true) + f('fDes', 'Designation', p.designation) + f('fMob', 'Mobile', p.mobile, 'tel') + f('fEml', 'Email', p.email, 'email', true)
      + (isNew ? '<div class="full"><div class="pp-note sm" style="margin:0">Department: Claude decides from the designation (and makes a new department if none fits). You can still drag the person elsewhere.</div></div>' : '<div><label class="fl">Department</label><select id="fDept">' + dopts + '</select></div>') + '<div><label class="fl">Reports to</label><select id="fMgr">' + mopts + '</select></div>'
      + f('fSal', 'Monthly salary (₹)', p.monthly_salary, 'number') + f('fBas', 'Basic salary (₹)', p.basic_salary, 'number') + f('fJoin', 'Joining date *', String(p.joining_date || '').slice(0, 10), 'date') + f('fEmp', 'Employment type', p.employment_type)
      + f('fDob', 'Date of birth', String(p.date_of_birth || '').slice(0, 10), 'date') + f('fAdr', 'Address', p.address, 'text', true)
      + f('fEcN', 'Emergency contact', p.emergency_contact_name) + f('fEcM', 'Emergency mobile', p.emergency_contact_mobile, 'tel') + f('fEcR', 'Relation', p.emergency_contact_relation, 'text', true)
      + (isNew ? '<div class="full"><label class="fl">Photo (optional)</label><input type="file" id="fPhoto" accept="image/jpeg,image/png,image/webp"></div>' + (S.B.me.admin ? '<div class="full"><h3>Login (optional)</h3></div>' + f('fUser', 'Username', '') + '<div><label class="fl">Password</label><div class="pp-pw"><input type="text" id="fPw" autocomplete="off"><button type="button" class="pp-btn" data-act="genpw2">Make</button></div></div><div class="full"><label class="fl">Role</label><select id="fRole">' + (S.B.all_roles || []).map(function (r) { return opt(r, r, 'Employee'); }).join('') + '</select></div>' : '') : '')
      + '<div class="full acts"><button type="submit" class="pp-btn acc">' + (isNew ? 'Add person' : 'Save') + '</button><button type="button" class="pp-btn" data-act="' + (isNew ? 'dr-close' : 'edit-cancel') + '">Cancel</button></div></form>';
  }
  function formBody(base) {
    var g = function (id) { var e = $('#' + id); return e ? e.value.trim() : ''; };
    var b = Object.assign({}, base || {});
    Object.assign(b, { full_name: g('fName'), designation: g('fDes'), mobile: g('fMob'), email: g('fEml'), department_id: g('fDept') || null, reporting_manager_id: g('fMgr') || null, monthly_salary: g('fSal') || 0, basic_salary: g('fBas') || 0, joining_date: g('fJoin'), employment_type: g('fEmp'), date_of_birth: g('fDob') || null, address: g('fAdr'), emergency_contact_name: g('fEcN'), emergency_contact_mobile: g('fEcM'), emergency_contact_relation: g('fEcR') });
    return b;
  }
  function savePerson(o, form, photoFile) {
    var p = o.data.person; var body = formBody(form ? null : {}); if (!form) { body = { full_name: p.full_name, designation: p.designation, mobile: p.mobile, email: p.email, department_id: p.department_id, reporting_manager_id: p.reporting_manager_id, monthly_salary: p.monthly_salary, basic_salary: p.basic_salary, joining_date: String(p.joining_date || '').slice(0, 10), employment_type: p.employment_type, date_of_birth: p.date_of_birth, address: p.address, emergency_contact_name: p.emergency_contact_name, emergency_contact_mobile: p.emergency_contact_mobile, emergency_contact_relation: p.emergency_contact_relation }; }
    body.employee_id = p.employee_id; body.sales_tag = p.sales_tag || '';   // saved back as it was, or Sales would lose it
    if (!body.full_name || !body.joining_date) { toast('Name and joining date are needed.', true); return Promise.resolve(); }
    var req;
    if (photoFile) { var fd = new FormData(); Object.keys(body).forEach(function (k2) { if (body[k2] != null) fd.append(k2, body[k2]); }); fd.append('photo', photoFile); req = api.upload('employees.save', fd); }
    else req = api.post('employees.save', body);
    return req.then(function () { toast('Saved.'); o.edit = false; return reload(false).then(function () { return refreshDrawer(o); }); }).catch(fail);
  }

  /* ---------------------------------------------------------------- events */
  function bindTab(o, el) {
    var form = $('#ppForm', el);
    if (form) form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (o.adding) return addPerson(form);
      savePerson(o, true);
    });
  }
  function addPerson(form) {
    var body = formBody({}); if (!body.full_name || !body.joining_date) { toast('Name and joining date are needed.', true); return; }
    var photo = $('#fPhoto') && $('#fPhoto').files[0]; var btn = $('button[type=submit]', form); busy(btn, true, 'Adding…');
    var req; if (photo) { var fd = new FormData(); Object.keys(body).forEach(function (k2) { if (body[k2] != null) fd.append(k2, body[k2]); }); fd.append('photo', photo); req = api.upload('employees.save', fd); } else req = api.post('employees.save', body);
    var user = ($('#fUser') && $('#fUser').value.trim()) || '', pw = ($('#fPw') && $('#fPw').value) || '', role = ($('#fRole') && $('#fRole').value) || 'Employee';
    req.then(function (r) {
      var id = r.employee_id; var next = api.post('bd.cq_place', { emp_id: id }).then(function (pr) { if (pr && pr.message) toast(pr.message); if (window.CQ) window.CQ.refresh(); }).catch(function () { /* the next minute's scan places them */ });
      if (user && pw) next = next.then(function () { return call('login_save', { body: { emp_id: id, username: user, password: pw, role: role } }).then(function () { toast('Added, and the login is ready: ' + user); }).catch(function (e) { toast('Person added, but the login failed: ' + e.message, true); }); });
      else toast('Person added.');
      return next.then(function () { return reload(false); }).then(function () { openPerson(id, user && pw ? 'login' : 'overview'); });
    }).catch(function (e) { busy(btn, false); fail(e); });
  }
  function addDialog() {
    if (S.dr) S.dr.close();
    var o = overlay('<div class="dr"><div class="hd"><div><h2>Add a person</h2><div class="mut sm">Everything can be changed later from their panel.</div></div><button class="x" data-act="dr-close">✕</button></div><div id="ppTab"></div></div>'); S.dr = o; o.adding = true; o.pid = 0;
    $('#ppTab', o).innerHTML = editForm(o, null); bindTab(o, $('#ppTab', o));
  }
  function rolesPage() { var o = overlay('<div class="box frame"><div style="display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-bottom:1px solid #eee"><b>Roles and permissions</b><button class="pp-btn sm" data-act="ov-close">Close</button></div><iframe src="roles.html?in=sales"></iframe></div>'); o.addEventListener('click', function (e) { if (e.target.closest('[data-act=ov-close]')) o.close(); }); }
  function menu(anchor, items) {
    $$('.pp-menu').forEach(function (m) { m.remove(); });
    var m = document.createElement('div'); m.className = 'pp-menu'; m.innerHTML = items.map(function (i) { return '<button data-go="' + i[0] + '">' + i[1] + '</button>'; }).join('');
    var r = anchor.getBoundingClientRect(); m.style.top = (window.scrollY + r.bottom + 4) + 'px'; m.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.innerWidth - 210)) + 'px'; document.body.appendChild(m);
    m.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; m.remove(); items.forEach(function (i) { if (i[0] === b.dataset.go) i[2](); }); });
    setTimeout(function () { document.addEventListener('click', function off() { m.remove(); document.removeEventListener('click', off); }, { once: true }); }, 0);
  }

  /* delete a department: its people move to another department you choose, then it is removed */
  function deleteDept(id) {
    var dd = dept(id); if (!dd) return; var n = (S.O.people || []).filter(function (p) { return p.dept_id === id; }).length, others = (S.B.departments || []).filter(function (x) { return x.id !== id; });
    var o = overlay('<div class="box" style="max-width:440px;padding:18px"><h2 style="margin:0 0 6px;font-size:18px">Delete ' + esc(dd.name) + '</h2><p class="mut sm" style="margin:0 0 12px">' + (n ? n + ' ' + (n === 1 ? 'person is' : 'people are') + ' in this department. Choose where they go; nobody is deleted.' : 'Nobody is in this department.') + ' Its Boss Engine section is closed too when it has no tasks and nobody in its seats; if it still has work it stays, and you are told.</p>'
      + (n ? '<label class="fl">Move them to</label><select id="dlTo" style="width:100%;margin-bottom:12px">' + others.map(function (x) { return opt(x.id, x.name, ''); }).join('') + '</select>' : '') + '<div class="acts"><button class="pp-btn red" data-act="dl-go">' + (n ? 'Move people and delete' : 'Delete department') + '</button><button class="pp-btn" data-act="dl-no">Cancel</button></div></div>');
    o.addEventListener('click', function (e) { var b = e.target.closest('[data-act]'); if (!b) return; if (b.dataset.act === 'dl-no') o.close(); if (b.dataset.act === 'dl-go') { busy(b, true, 'Deleting…'); call('dept_delete', { body: { id: id, move_to: n ? Number($('#dlTo', o).value) : 0 } }).then(function (r) { o.close(); toast(r.message); return reload(false); }).catch(function (er) { busy(b, false); fail(er); }); } });
  }

  function move(id, deptId) {
    var p = person(id); if (!p || p.dept_id === deptId || !deptId) return;
    var old = p.dept_id; p.dept_id = deptId; render();
    call('move', { body: { emp_id: id, dept_id: deptId } }).then(function (r) { toast(r.message); return reload(false); }).catch(function (e) { p.dept_id = old; render(); fail(e); });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-act]'); if (!t) return; var act = t.dataset.act, v = t.dataset.v, o = S.dr;
    if (t.tagName === 'LABEL' || t.type === 'checkbox') { if (act !== 'show') return; }
    if (act === 'open') { openPerson(Number(t.dataset.id)); }
    else if (act === 'view') { S.view = v; savePrefs(); render(); }
    else if (act === 'role') { S.role = v; render(); }
    else if (act === 'flt') { S.flt = S.flt === v ? '' : v; render(); }
    else if (act === 'ex') { S.ex = !S.ex; S.role = ''; S.flt = ''; loadAll(false).then(render).catch(fail); }
    else if (act === 'fold') { if (PHONE()) { if (e.target.closest('.dots')) return; var id = Number(t.dataset.id); S.open[id] = S.open[id] != null ? !S.open[id] : !!PHONE(); render(); } }
    else if (act === 'add') addDialog();
    else if (act === 'titles') titlesDialog();
    else if (act === 'docwatch') docWatchDialog();
    else if (act === 'roles-page') rolesPage();
    else if (act === 'page') { S.page = v || ''; try { history.replaceState(null, '', v ? '#' + v : location.pathname + location.search); } catch (e3) { /* ignore */ } if (S.dr) S.dr.close(); render(); }
    else if (act === 'refresh') { toast('Refreshing…'); loadAll(true).then(function () { render(); toast('Figures are up to date.'); }).catch(fail); }
    else if (act === 'boss') boss(v);
    else if (act === 'ask-go') { var ak = $('#ppAsk'); if (ak) ak.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    else if (act === 'dmenu') { e.stopPropagation(); var did = Number(t.dataset.id), dd = dept(did); menu(t, [['r', 'Rename', function () { var nn = prompt('New name', dd.name); if (nn && nn.trim()) call('dept_save', { body: { id: did, name: nn.trim() } }).then(function (r) { toast(r.message); return reload(false); }).catch(fail); }], ['d', 'Delete department…', function () { deleteDept(did); }]]); }
    else if (o) {
      if (act === 'dr-close') o.close();
      else if (act === 'tab') { o.tab = v; o.edit = false; drawer(o); }
      else if (act === 'edit') { o.edit = true; $('#ppTab', o).innerHTML = editForm(o, o.data.person); bindTab(o, $('#ppTab', o)); }
      else if (act === 'edit-cancel') { o.edit = false; $('#ppTab', o).innerHTML = tabHtml(o); bindTab(o, $('#ppTab', o)); }
      else if (act === 'move') { var dv = Number($('#ppDept', o).value); call('move', { body: { emp_id: o.pid, dept_id: dv } }).then(function (r) { toast(r.message); return reload(false).then(function () { return refreshDrawer(o); }); }).catch(fail); }
      else if (act === 'go') go(v);
      else if (act === 'genpw') { $('#lgPw', o).value = genPw(); }
      else if (act === 'genpw2') { $('#fPw', o).value = genPw(); }
      else if (act === 'login-save') {
        var u = $('#lgUser', o).value.trim(), pw = $('#lgPw', o).value, role = $('#lgRole', o).value; busy(t, true, 'Saving…');
        call('login_save', { body: { emp_id: o.pid, username: u, password: pw, role: role } }).then(function (r) {
          busy(t, false); toast(r.message); var out = $('#lgOut', o); if (pw && out) out.innerHTML = '<div class="msg">Give this to the person:<br><b>Username:</b> ' + esc(u) + '<br><b>Password:</b> ' + esc(pw) + '<br><span class="sm">The password cannot be shown again after you leave this panel.</span></div>';
          return reload(false).then(function () { return call('person', { params: { id: o.pid, month: S.month } }); }).then(function (d) { o.data = d; var tabs = $('.pp-tabs', o); var chipHost = $('.hd .sm', o); if (chipHost) chipHost.nextElementSibling.innerHTML = d.login ? '<span class="pp-t ok">login: ' + esc(d.login.username) + '</span>' : ''; });
        }).catch(function (er) { busy(t, false); fail(er); });
      }
      else if (act === 'login-del') { if (confirm('Remove this login? The person stays in People but cannot sign in.')) call('login_remove', { body: { emp_id: o.pid } }).then(function (r) { toast(r.message); return reload(false).then(function () { return refreshDrawer(o); }); }).catch(fail); }
      else if (act === 'seats-save') { var ids = $$('[data-seat]:checked', o).map(function (c) { return Number(c.dataset.seat); }); busy(t, true, 'Saving…'); call('seats_set', { body: { emp_id: o.pid, seat_ids: ids } }).then(function (r) { busy(t, false); toast(r.message); return reload(false).then(function () { return refreshDrawer(o); }); }).catch(function (er) { busy(t, false); fail(er); }); }
      else if (act === 'doc-view') { fetch(api.buildUrl('bd.pp_doc_file', { id: t.dataset.id }), { headers: { Authorization: 'Bearer ' + api.token() } }).then(function (r) { if (!r.ok) throw new Error('The file could not be opened.'); return r.blob(); }).then(function (b) { window.open(URL.createObjectURL(b), '_blank'); }).catch(fail); }
      else if (act === 'doc-ok') { api.post('employee_documents.approve', { employee_document_id: Number(t.dataset.id) }).then(function () { toast('Approved.'); return reload(false).then(function () { return refreshDrawer(o); }); }).catch(fail); }
      else if (act === 'doc-up') { var inp = $('[data-file="' + t.dataset.type + '"]', o); inp.onchange = function () { if (!inp.files[0]) return; var fd = new FormData(); fd.append('employee_id', o.pid); fd.append('document_type_id', t.dataset.type); fd.append('document_file', inp.files[0]); busy(t, true, 'Uploading…'); api.upload('employee_documents.upload', fd).then(function (r) { toast(r.message || 'Uploaded.'); return reload(false).then(function () { return refreshDrawer(o); }); }).catch(function (er) { busy(t, false); fail(er); }); }; inp.click(); }
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'ppMonth') { S.month = t.value || S.month; loadAll(false).then(render).catch(fail); }
    else if (t.dataset && t.dataset.act === 'show') { S.show[t.dataset.v] = t.checked ? 1 : 0; savePrefs(); render(); }
  });
  document.addEventListener('input', function (e) { if (e.target.id === 'ppQ') { S.q = e.target.value; clearTimeout(S._qt); S._qt = setTimeout(render, 140); } });

  /* drag a card to another department (desktop) */
  document.addEventListener('dragstart', function (e) { var c = e.target.closest && e.target.closest('.pp-c'); if (!c) return; S.drag = Number(c.dataset.id); c.classList.add('drag'); try { e.dataTransfer.setData('text/plain', String(S.drag)); e.dataTransfer.effectAllowed = 'move'; } catch (x) { /* ignore */ } });
  document.addEventListener('dragend', function () { S.drag = 0; $$('.pp-c.drag,.drop').forEach(function (n) { n.classList.remove('drag', 'drop'); }); });
  document.addEventListener('dragover', function (e) { var l = e.target.closest && e.target.closest('[data-dept]'); if (!l || !S.drag || l.dataset.dept === '0' || S.ex) return; e.preventDefault(); $$('.drop').forEach(function (n) { if (n !== l) n.classList.remove('drop'); }); l.classList.add('drop'); });
  document.addEventListener('drop', function (e) { var l = e.target.closest && e.target.closest('[data-dept]'); if (!l || !S.drag || S.ex) return; e.preventDefault(); var id = S.drag; S.drag = 0; l.classList.remove('drop'); move(id, Number(l.dataset.dept)); });

  /* keeps People in step with the Boss Engine: every minute (while this tab is open and you are not in the middle of something) the lists are read again.
     Anything changed in Boss (tasks, penalties, seats, departments) or by someone else shows here without a reload. */
  var LAST = Date.now();
  function idle() { var ae = document.activeElement; return document.visibilityState === 'visible' && !S.dr && !S.page && !S.drag && !document.querySelector('.pp-ov') && !(ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName) && ae.id !== 'ppQ' && ae.id !== 'ppMonth'); }
  function liveRefresh() { if (!S.B || !idle()) return; var y = window.scrollY; call('overview', { params: { month: S.month, status: S.ex ? 'ex' : '' } }).then(function (o) { S.O = o; return call('boot', { params: { month: S.month } }); }).then(function (b) { if (!idle()) return; S.B = b; LAST = Date.now(); render(); window.scrollTo(0, y); var u = $('#ppUp'); if (u) u.textContent = 'Live · updated ' + new Date().toTimeString().slice(0, 5); }).catch(function () { /* try again next minute */ }); }
  setInterval(liveRefresh, 60000);
  window.addEventListener('cq:changed', function () { if (S.B) reload(false).then(function () { render(); }).catch(fail); });   // an answer to Claude may have made a department, moved people or added tasks
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && Date.now() - LAST > 60000) liveRefresh(); });

  /* ---------------------------------------------------------------- start */
  function start() {
    var hsh = (location.hash || '').replace(/^#/, ''); if (pageOk(hsh)) S.page = hsh;
    loadAll(false).then(render).catch(function (e) { var el = $('#pp'); if (el) el.innerHTML = '<div class="pp-note">Could not load: ' + esc(e.message || e) + '</div>'; });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
