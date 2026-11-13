/* Boss Engine 2 — the Claude button on every page.
   Loaded once by layout.js (top window only). Managers get: Ask Claude · Add a task · Report a problem · This section.
   Everyone else gets: Report a problem. Claude knows which page you are on (the "section contract" lives on the server;
   a page without one is read as plain page text). Nothing here decides anything; it only opens the same forms as the Boss Engine page. */
(function () {
  'use strict';
  try { if (window.self !== window.top) return; } catch (e) { return; }
  if (window.__b2chip) return; window.__b2chip = 1;
  const SKIP = ['', 'index.html', 'login.html', 'boss_engine.html', 'boss_section.html', 'manifest.json'];
  let INFO = null, LASTKEY = null, BUSY = false;

  const base = (u) => { try { return (u || '').split('?')[0].split('#')[0].split('/').pop() || ''; } catch (e) { return ''; } };
  function activeFrame() { const fr = document.querySelectorAll('iframe.ws-frame'); for (let i = 0; i < fr.length; i++) if (!fr[i].hidden) return fr[i]; return null; }
  function pageKey() {
    const here = base(location.pathname);
    if (here === 'hub.html' || here === 'sales.html') {
      const f = activeFrame(); let k = '';
      try { k = base(f && f.contentWindow.location.pathname); } catch (e) { k = ''; }
      if (!k) { const t = new URLSearchParams(location.search).get('t'); k = t ? t + '.html' : ''; }
      return k;
    }
    return here;
  }
  function pageText() {
    let doc = document; const f = activeFrame();
    try { if (f && f.contentDocument) doc = f.contentDocument; } catch (e) { /* other origin */ }
    try { const m = doc.querySelector('main') || doc.body; return (m.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 3500); } catch (e) { return ''; }
  }
  function load(src, cb) { const s = document.createElement('script'); s.src = src; s.onload = cb; s.onerror = () => cb(new Error('load')); document.head.appendChild(s); }
  function css(href) { if (document.querySelector('link[href^="' + href + '"]')) return; const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l); }
  function deps() {
    return new Promise((res, rej) => {
      css('assets/css/boss.css?v=1'); css('assets/css/boss2.css?v=t7');
      const need2 = () => (typeof B2 === 'undefined') ? load('assets/js/b2_common.js?v=t7', (e) => (e instanceof Error ? rej(e) : res())) : res();
      if (typeof BD === 'undefined') load('assets/js/boss_common.js?v=4', (e) => (e instanceof Error ? rej(e) : need2())); else need2();
    });
  }

  function el() {
    let c = document.getElementById('b2Chip'); if (c) return c;
    css('assets/css/boss2.css?v=t7');
    c = document.createElement('div'); c.id = 'b2Chip'; c.hidden = true;
    c.innerHTML = '<div class="menu" id="b2Menu"></div><button type="button" class="fab" id="b2Fab" aria-haspopup="true"><i></i>Claude</button>';
    document.body.appendChild(c);
    c.querySelector('#b2Fab').onclick = (e) => { e.stopPropagation(); toggle(); };
    document.addEventListener('click', (e) => { if (!c.contains(e.target)) c.classList.remove('open'); });
    return c;
  }
  async function whoAmI() {
    if (INFO) return INFO;
    try { INFO = await api.get('bd.badge'); } catch (e) { INFO = {}; }
    return INFO;
  }
  async function pageInfo(key) {
    try { return await api.get('bd.b2_page', { page: key }); } catch (e) { return { page: key, mode: 'text', manager: false, can_add: false }; }
  }

  async function toggle() {
    const c = el(); const open = c.classList.contains('open');
    if (open) { c.classList.remove('open'); return; }
    const who = await whoAmI();
    if (!who.manager) { c.classList.remove('open'); return act('report'); }       // staff: one button, one thing
    await render(); c.classList.add('open');
  }
  async function render() {
    const key = pageKey(); const [info, br] = await Promise.all([pageInfo(key), brainInfo(key)]); LASTKEY = key; const c = el(); const m = c.querySelector('#b2Menu');
    const sec = info.section || ''; c.__br = br; brainBadge(br);
    m.innerHTML = '<div class="hd">' + (sec ? esc(sec) : 'This page') + '</div>' + brainCard(br)
      + '<button type="button" data-a="ask">Ask Claude<small>About ' + (sec ? esc(sec) : 'the business') + ', in plain words</small></button>'
      + (info.can_add ? '<button type="button" data-a="add">Add a task<small>Say it in one sentence</small></button>' : '')
      + (br && br.can_teach ? '<button type="button" data-a="teach">Teach Claude<small>Say it once; Claude uses it wherever it applies</small></button>' : '')
      + '<button type="button" data-a="report">Report a problem<small>Claude finds the cause, you decide</small></button>'
      + (sec || info.contract ? '<button type="button" data-a="section">This section<small>' + (info.mode === 'full' ? 'What this page counts and how it is judged' : 'Where it stands today') + '</small></button>' : '');
    m.onclick = (e) => { const b = e.target.closest('button[data-a]'); if (!b) return; el().classList.remove('open'); act(b.dataset.a, info); };
    c.__info = info;
  }
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (x) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[x]));

  async function act(a, info) {
    if (BUSY) return; BUSY = true;
    try {
      await deps();
      info = info || await pageInfo(pageKey());
      const sec = info.section_code || '', secName = info.section || '';
      if (a === 'ask') B2.askClaude({ section: sec, sectionName: secName, pageText: info.mode === 'text' ? pageText() : '' });
      else if (a === 'add') B2.editItem({ section: sec, page: pageKey(), onSaved: () => { try { const f = activeFrame(); if (f) f.contentWindow.location.reload(); else location.reload(); } catch (e) { /* ignore */ } } });
      else if (a === 'report') B2.report({ section: sec, sectionName: secName, page: pageKey() });
      else if (a === 'teach') teachModal(el().__br);
      else if (a === 'qa') questionsModal();
      else if (a === 'section') sectionCard(info);
    } catch (e) { alert('Could not open this. Please reload the page and try again.'); }
    BUSY = false;
  }
  function sectionCard(info) {
    const { esc: e2, inr } = BD; const c = info.contract; const s = info.stats;
    let h = '<h3>' + e2(info.section || (c && c.title) || 'This page') + '</h3>';
    if (s) h += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"><span class="bd-chip dark">Today ' + s.today.done + ' / ' + s.today.due + ' done</span>' + (s.today.missed ? '<span class="bd-chip bad">' + s.today.missed + ' missed</span>' : '') + '<span class="bd-chip">' + s.active + ' tasks</span>' + (s.slipping ? '<span class="bd-chip bad">' + s.slipping + ' slipping</span>' : '') + (s.late ? '<span class="bd-chip warn">' + s.late + ' late</span>' : '') + '</div>' + (s.boss ? '<div class="bd-sub" style="margin-bottom:8px">Section boss: ' + e2(s.boss) + '</div>' : '');
    if (c) {
      h += '<div class="bd-lb" style="margin-top:6px">What this page counts</div><p style="font-size:13.5px;line-height:1.5;margin:3px 0 8px">' + e2(c.counts) + '</p>'
        + '<div class="bd-lb">What “overdue” means here</div><p style="font-size:13.5px;line-height:1.5;margin:3px 0 8px">' + e2(c.overdue) + '</p>'
        + '<div class="bd-lb">Who owns the work</div><p style="font-size:13.5px;line-height:1.5;margin:3px 0 8px">' + e2(c.owners) + '</p>'
        + ((c.rules || []).length ? '<div class="bd-lb">Penalty rules on this page</div>' + c.rules.map((r) => '<div style="font-size:13px;line-height:1.5">• ' + e2(r) + '</div>').join('') : '');
    } else h += '<div class="bd-call info">Claude has no written contract for this page yet, so it reads only the words on the page. Ask it anyway; if the answer is not on the page it will say so.</div>';
    h += '<div class="acts"><button class="bd-btn" id="scNo">Close</button>' + (info.section_code ? '<a class="bd-btn pri" href="boss_section.html?code=' + encodeURIComponent(info.section_code) + '">Open the section page</a>' : '') + '</div>';
    const m = BD.modal(h); m.querySelector('#scNo').onclick = BD.close;
  }


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

  var BKIO = { cls: { btn: 'bd-btn pri', sec: 'bd-btn', sub: 'bd-sub', ok: 'bd-call ok' }, post: function (a, b) { return api.post('bd.br_' + a, b); }, get: function (a, p) { return api.get('bd.br_' + a, p || {}); }, say: function () {}, onChange: function () { brainInfo(pageKey()).then(brainBadge); } };

  /* ---- the company brain: what Claude understands on this page, what waits for you, and the Teach shortcut ----
     Server: bd.br_page (what Claude understands here; no AI call, cheap), bd.br_teach_read (Claude shows what it understood, nothing is saved yet), bd.br_teach_save. */
  async function brainInfo(key) { try { return await api.get('bd.br_page', { page: key }); } catch (e) { return null; } }
  function brainCss() {
    if (document.getElementById('b2BrainCss')) return; const st = document.createElement('style'); st.id = 'b2BrainCss';
    st.textContent = '#b2Chip .menu{max-width:min(340px,calc(100vw - 24px))}#b2Menu .brn{padding:8px 12px 10px;margin:0 0 4px;border-bottom:1px solid #eef0f3;font-size:12.5px;line-height:1.45;color:#2b303a}#b2Menu .brn b{display:block;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#6a7280;margin-bottom:4px}'
      + '#b2Menu .brn ul{margin:0;padding-left:16px}#b2Menu .brn li{margin:2px 0}#b2Menu .brn .o{margin-top:6px;color:#9a6200;font-weight:800}#b2Menu .brn a{display:inline-block;margin-top:6px;color:#d9480f;font-weight:700;text-decoration:none}'
      + '#b2Menu .brn .brq{margin:6px 0 0;border:1px solid #f0c36a;background:#fff8e6;border-radius:10px;padding:6px 10px;font:800 12.5px/1.2 inherit;color:#7a5a00;cursor:pointer;width:auto;display:inline-block}'
      + '#b2Fab .brn-n{display:inline-block;margin-left:2px;background:#d9423b;color:#fff;border-radius:99px;padding:0 7px;font-size:11px;font-weight:800;line-height:17px}'
      + '.brT select,.brT input.brTx{font:inherit;font-size:13.5px;padding:8px 10px;border:1px solid #d9dde3;border-radius:12px;background:#fff}.brT .brRow{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:8px 0}.brT .brTx{flex:1 1 240px;min-width:0}'
      + '.brT .brNote{font-size:12.5px;color:#9a6200;margin:-2px 0 6px 24px}';
    document.head.appendChild(st);
  }
  function brainOpenN(b) { const o = (b && b.open) || {}; return (o.questions || 0) + (o.edits || 0) + (o.needs || 0); }
  function brainBadge(b) {
    try { const f = document.getElementById('b2Fab'); if (!f) return; const n = brainOpenN(b); const old = f.querySelector('.brn-n'); if (old) old.remove();
      if (n > 0) { const sp = document.createElement('span'); sp.className = 'brn-n'; sp.textContent = n; f.appendChild(sp); } } catch (e) { /* a badge is only a hint */ }
  }
  function brainCard(b) {
    if (!b || !b.understands) return ''; brainCss(); const o = b.open || {}; const bits = [];
    if (o.questions) bits.push(o.questions + ' question' + (o.questions === 1 ? '' : 's') + ' for you'); if (o.edits) bits.push(o.edits + ' edited repl' + (o.edits === 1 ? 'y' : 'ies') + ' to confirm'); if (o.needs) bits.push(o.needs + ' message' + (o.needs === 1 ? '' : 's') + ' waiting for a fact');
    return '<div class="brn"><b>🧠 ' + esc(b.claude) + ' · ' + esc(b.area_label) + '</b><ul>' + b.understands.slice(0, 5).map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>'
      + (bits.length ? '<div class="o">' + esc(bits.join(' · ')) + '</div>' : '') + (b.can_teach && b.scope !== 'customer' ? '<button type="button" class="brq" data-a="qa">' + (o.questions ? 'Answer ' + o.questions + ' question' + (o.questions === 1 ? '' : 's') + ' from Claude' : 'Questions from Claude') + '</button><br>' : '') + (b.can_teach ? '<a href="social_inbox.html" target="_top">Open Claude’s brain</a>' : '') + '</div>';
  }
  function questionsModal() {
    brainCss(); bkCss(); const m = BD.modal('<div class="brT"><h3>Claude asks you</h3><p class="bd-sub" style="margin:0 0 8px">Claude is missing a few things about how the team side works. Your answers are saved as team-only: they never reach a customer.</p><div id="brqHost"></div><div class="acts"><button class="bd-btn" id="brqNo">Close</button></div></div>');
    m.querySelector('#brqNo').onclick = BD.close; brainQuestionsUI(m.querySelector('#brqHost'), BKIO);
  }
  function teachModal(br) {
    brainCss(); const hint = br && br.scope === 'customer' ? 'customer' : 'internal';
    const m = BD.modal('<div class="brT"><h3>Teach Claude</h3><p class="bd-sub" style="margin:0 0 8px">' + (hint === 'customer' ? 'This page is about customers: what you teach here can shape the replies they read.' : 'This page is for staff: rules here stay inside the team unless you say customers may be told.') + ' Claude reads it back first; nothing is saved before you press Keep.</p><p class="bd-sub" style="margin:0 0 8px">For a whole process, long instructions or <b>screenshots</b>, use the <a href="learning.html" style="font-weight:700">Teach screen</a>: it asks its questions and saves into the same brain.</p>'
      + '<textarea id="brT" rows="3" style="width:100%;box-sizing:border-box;font:inherit;padding:8px;border:1px solid #d9dde3;border-radius:8px" placeholder="For example: A parcel without a tracking ID after 24 hours costs the packer ₹300."></textarea><div id="brOut"></div>'
      + '<div class="acts"><button class="bd-btn" id="brNo">Cancel</button><button class="bd-btn pri" id="brGo">Read it</button></div></div>');
    const out = m.querySelector('#brOut'), go = m.querySelector('#brGo'), ta = m.querySelector('#brT'); m.querySelector('#brNo').onclick = BD.close;
    const SC = '<option value="internal">🔒 Team only</option><option value="company">🏢 Company fact</option><option value="customer">🧑 How we talk to customers</option>';
    const readIt = async () => {
      const t = ta.value.trim(); if (t.length < 4) { out.innerHTML = '<div class="bd-call bad">Write what Claude should know.</div>'; return; }
      go.disabled = true; go.textContent = 'Reading…';
      try {
        const r = await api.post('bd.br_teach_read', { text: t, scope: hint }); const areas = r.areas || {};
        out.innerHTML = '<div class="bd-call info"><b>I understood this:</b><ul style="margin:4px 0 0;padding-left:18px">' + (r.understood || []).map((u) => '<li>' + esc(u) + '</li>').join('') + '</ul></div><p class="bd-sub" style="margin:8px 0 0">Check who may know each thing. Team-only never reaches a customer.</p>'
          + r.items.map((it, i) => '<div class="brRow" data-i="' + i + '"><input type="checkbox" checked><input class="brTx" value="' + esc(it.text) + '"><select class="brSc">' + SC + '</select><select class="brSh"><option value="1">Customers may be told</option><option value="0">Team only</option></select><select class="brAr">' + Object.keys(areas).map((k) => '<option value="' + esc(k) + '">' + esc(areas[k]) + '</option>').join('') + '</select></div>'
            + (it.task ? '<div class="brNote" style="margin-left:0">This is a duty or penalty. Claude knows the rule once you press Keep. To make the Boss Engine run it: <button type="button" class="bd-btn brTkBtn" data-i="' + i + '">Draft it in the Boss Engine</button></div><div class="brTkHost" data-i="' + i + '"></div>' : '')).join('');
        out.querySelectorAll('.brTkBtn').forEach((b) => { b.onclick = () => { const i = +b.dataset.i, host = out.querySelector('.brTkHost[data-i="' + i + '"]'), row = out.querySelector('.brRow[data-i="' + i + '"]'); brainTaskStart(host, (row.querySelector('.brTx').value.trim() || r.items[i].text), BKIO); }; });
        out.querySelectorAll('.brRow').forEach((row) => { const it = r.items[+row.dataset.i]; const sc = row.querySelector('.brSc'), sh = row.querySelector('.brSh'), ar = row.querySelector('.brAr');
          sc.value = it.scope; sh.value = it.shareable ? '1' : '0'; ar.value = it.area; const sync = () => { sh.style.display = sc.value === 'company' ? '' : 'none'; ar.style.display = sc.value === 'internal' ? '' : 'none'; }; sc.onchange = sync; sync(); });
        go.disabled = false; go.textContent = 'Keep';
        go.onclick = async () => {
          const items = [...out.querySelectorAll('.brRow')].filter((row) => row.querySelector('input[type=checkbox]').checked && row.querySelector('.brTx').value.trim()).map((row) => { const it = r.items[+row.dataset.i], sc = row.querySelector('.brSc').value;
            return { text: row.querySelector('.brTx').value.trim(), scope: sc, shareable: sc === 'company' ? row.querySelector('.brSh').value === '1' : sc === 'customer', area: row.querySelector('.brAr').value, kind: it.kind }; });
          if (!items.length) { out.insertAdjacentHTML('beforeend', '<div class="bd-call bad">Nothing is ticked.</div>'); return; }
          go.disabled = true; go.textContent = 'Saving…';
          try { const x = await api.post('bd.br_teach_save', { items }); out.innerHTML = '<div class="bd-call ok">' + esc(x.message || 'Saved.') + '</div>'; go.style.display = 'none'; brainInfo(pageKey()).then(brainBadge); setTimeout(BD.close, 1400); }
          catch (e) { go.disabled = false; go.textContent = 'Keep'; out.insertAdjacentHTML('beforeend', '<div class="bd-call bad">' + esc((e && e.message) || 'Could not save.') + '</div>'); }
        };
      } catch (e) { go.disabled = false; go.textContent = 'Read it'; out.innerHTML = '<div class="bd-call bad">' + esc((e && e.message) || 'Claude could not read it. Try again.') + '</div>'; }
    };
    go.onclick = readIt;
  }
  /* ---- the strip: one line on every page that belongs to a section (open it for the tasks, numbers and load) ---- */
  const NOSTRIP = ['', 'index.html', 'login.html', 'boss_engine.html', 'boss_section.html', 'hub.html', 'daily_summary.html', 'my_day.html', 'manifest.json'];
  const STRIP = { doc: null, key: '', at: 0, busy: false, hidden: false };
  function stripDoc() {
    if (base(location.pathname) === 'hub.html') { const f = activeFrame(); if (!f) return null; try { return f.contentDocument || null; } catch (e) { return null; } }
    return document;
  }
  const stripOpen = () => { try { return localStorage.getItem('b2_strip_open') === '1'; } catch (e) { return false; } };
  const stripSave = (v) => { try { localStorage.setItem('b2_strip_open', v ? '1' : '0'); } catch (e) { /* private mode */ } };
  function stripDots(a) { return '<span class="b2-strip">' + (a || []).map((x) => '<i class="' + esc(x.s) + '" title="' + esc(x.d) + '"></i>').join('') + '</span>'; }
  function stripHtml(d, who) {
    const pill = (c, t) => '<span class="pill ' + c + '">' + t + '</span>';
    let one = '<b class="nm">Boss Engine</b>' + pill('dark', esc(d.section)) + pill(d.live ? 'ok' : 'warn', d.live ? 'Live' : 'Test mode');
    let more = '';
    if (d.manager) {
      const t = d.today || {}; one += pill('', 'Today ' + (t.done || 0) + ' of ' + (t.due || 0)) + (t.missed ? pill('bad', t.missed + ' missed') : '');
      (d.numbers || []).filter((n) => n.bad).slice(0, 2).forEach((n) => { one += pill('bad', n.value + ' ' + esc(n.unit)); });
      if (d.needs) one += pill('warn', d.needs + ' need' + (d.needs === 1 ? 's' : '') + ' you');
      if (d.chain) one += pill('', 'Chain ' + d.chain.covered + ' of ' + d.chain.total);
      more += '<table>' + (d.tasks || []).map((x) => '<tr><td><b>' + esc(x.title) + '</b></td><td>' + esc(x.owner || '') + '</td><td>' + stripDots(x.strip) + '</td><td>' + pill(x.state === 'slipping' || x.state === 'late' ? 'bad' : (x.state === 'ontrack' ? 'ok' : 'warn'), esc(x.state_label)) + '</td></tr>').join('') + '</table>';
      if ((d.numbers || []).length) more += '<div class="nums">' + d.numbers.map((n) => '<span class="' + (n.bad ? 'bad' : '') + '"><b>' + n.value + '</b> ' + esc(n.bad ? n.bad_text : n.label) + (n.watched_by ? ' · watched by ' + esc(n.watched_by) : ' · nobody watches it') + '</span>').join('') + '</div>';
      if ((d.loads || []).length) more += '<div class="ft">Load here: ' + d.loads.map((l) => esc(l.name) + ' <b>' + l.n + ' of ' + l.cap + '</b>').join(' · ') + '</div>';
      if (d.chain && d.chain.next) more += '<div class="ft">Claude would ask next: <b>' + esc(d.chain.next) + '</b></div>';
      more += '<div class="ft"><a href="hub.html?w=boss&t=boss_engine" target="_top">Open the full Boss Engine</a><span class="pill" style="cursor:pointer" data-sa="add">+ Add a task</span><span class="pill" style="cursor:pointer" data-sa="report">Report a problem</span></div>';
    } else {
      const done = (d.tasks || []).filter((x) => x.today === 'done' || x.today === 'review').length;
      one += pill('', 'Your tasks here: ' + (d.tasks || []).length + ' · ' + done + ' done today');
      more += '<table>' + (d.tasks || []).map((x) => '<tr><td><b>' + esc(x.title) + '</b></td><td>' + esc(x.due_label || '') + '</td><td>' + stripDots(x.strip) + '</td><td>' + pill(x.state === 'slipping' || x.state === 'late' ? 'bad' : (x.state === 'ontrack' ? 'ok' : 'warn'), esc(x.state_label)) + '</td></tr>').join('') + '</table>'
        + '<div class="ft"><a href="my_day.html" target="_top">Open My day</a><span class="pill" style="cursor:pointer" data-sa="report">Report a problem</span></div>';
    }
    return '<div class="one" data-tg="1">' + one + '<span class="sp"></span><button type="button" class="tg">' + (stripOpen() ? 'Hide ▲' : 'Details ▼') + '</button></div><div class="more">' + more + '</div>';
  }
  async function stripTick() {
    if (STRIP.busy) return;
    const key = pageKey(); const doc = stripDoc();
    if (!doc || !doc.body || doc.readyState === 'loading') return;
    const have = doc.getElementById('b2Strip');
    if (!key || NOSTRIP.indexOf(key) >= 0) { if (have) have.remove(); return; }
    if (STRIP.doc === doc && STRIP.key === key && Date.now() - STRIP.at < 60000 && (have || STRIP.hidden)) return;
    STRIP.busy = true;
    try {
      const d = await api.get('bd.b2_strip', { page: key });
      if (pageKey() !== key || stripDoc() !== doc) return;
      STRIP.doc = doc; STRIP.key = key; STRIP.at = Date.now(); STRIP.hidden = !d || !d.show;
      let el = doc.getElementById('b2Strip');
      if (STRIP.hidden) { if (el) el.remove(); return; }
      if (!doc.querySelector('link[href^="assets/css/boss2.css"]')) { const l = doc.createElement('link'); l.rel = 'stylesheet'; l.href = 'assets/css/boss2.css?v=t7'; doc.head.appendChild(l); }
      if (!el) {
        el = doc.createElement('div'); el.id = 'b2Strip';
        const host = doc.querySelector('.content') || doc.querySelector('main') || doc.body; host.insertBefore(el, host.firstChild);
      }
      el.dataset.key = key; el.className = stripOpen() ? '' : 'closed'; el.innerHTML = stripHtml(d);
      el.onclick = (e) => {
        const sa = e.target.closest && e.target.closest('[data-sa]'); if (sa) { e.stopPropagation(); act(sa.dataset.sa); return; }
        if (e.target.closest && e.target.closest('a')) return;
        if (e.target.closest && e.target.closest('[data-tg]')) { const open = el.classList.contains('closed'); el.classList.toggle('closed', !open); stripSave(open); const b = el.querySelector('.tg'); if (b) b.textContent = open ? 'Hide ▲' : 'Details ▼'; }
      };
    } catch (e) { try { console.warn('b2strip', e && e.message); } catch (x) { /* ignore */ } } finally { STRIP.busy = false; }
  }

  async function start() {
    if (typeof api === 'undefined' || !api.token || !api.token()) return;
    const key = pageKey(); if (SKIP.indexOf(key) >= 0 && base(location.pathname) !== 'hub.html') return;
    const who = await whoAmI(); if (!who || who.error) return;
    const c = el(); c.hidden = false;
    // follow the open tab when the page is a workspace (hub) with frames
    let last = key; setInterval(() => { const k = pageKey(); if (k !== last) { last = k; c.hidden = SKIP.indexOf(k) >= 0; c.classList.remove('open'); if (who.manager) brainInfo(k).then(brainBadge); } }, 1200);
    if (who.manager) { brainInfo(key).then(brainBadge); setInterval(() => { if (!c.hidden) brainInfo(pageKey()).then(brainBadge); }, 90000); }   // the red number on the Claude button: questions and edits that wait for you
    c.hidden = SKIP.indexOf(key) >= 0;
    stripTick(); setInterval(stripTick, 2000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(start, 600)); else setTimeout(start, 600);
})();
