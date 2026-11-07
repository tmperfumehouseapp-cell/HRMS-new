/* person_card.js — the ONE person card. Used by Total Budgeting (a pop-up when you click a name) and by Boss → People (a tab),
   so both show the very same thing. It stores nothing itself: it reads and writes the places every other page already uses
   (employee timing = Boss Engine "Who punches how", penalties = the Penalty list, summary = Claude). A change made here is a change everywhere.

   PersonCard.open(employeeId, { onChange })      pop-up
   PersonCard.render(element, employeeId, opts)   same card inside any element (opts.header = true to show the name on top)
   PersonCard.timing(list, done)                  the timing editor (also used by Boss Engine for several people at once)
     list = [{ id, name, mode, fx_start, fx_end, fx_late, hours_target, hours_full, win_from, win_to }]                              */
window.PersonCard = (function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const MODES = { fixed: ['Strict timing', 'In and out at set times'], hours: ['Fixed hours', 'Come any time, complete the hours'], free: ['No rule', 'Punch is optional. Never late.'] };

  function css() {
    if (document.getElementById('pcCss')) return;
    const st = document.createElement('style'); st.id = 'pcCss';
    st.textContent = '.pc-ov{position:fixed;inset:0;z-index:9999;background:rgba(17,24,39,.45);display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:max(16px,env(safe-area-inset-top)) 12px 24px}'
      + '.pc-box{background:#fff;border-radius:20px;width:100%;max-width:600px;margin-top:4vh;padding:18px 18px 16px;box-shadow:0 20px 50px -20px rgba(0,0,0,.4);position:relative;color:#14161a;font-size:13.5px}'
      + '.pc-x{position:absolute;right:12px;top:10px;border:0;background:#f1f2f5;border-radius:50%;width:30px;height:30px;font-size:18px;cursor:pointer;line-height:1}'
      + '.pc h2{margin:0;font-size:19px}.pc .sub{color:#6b7280;font-size:12.5px;margin-top:2px}.pc h4{margin:16px 0 6px;font-size:11.5px;text-transform:uppercase;letter-spacing:.07em;color:#6b7280}'
      + '.pc-claude{background:#f3f0ff;border-radius:14px;padding:10px 12px;margin-top:12px;line-height:1.5}.pc-claude b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#6d4fd6;margin-bottom:2px}.pc-claude ul{margin:6px 0 0;padding-left:18px}.pc-claude li{margin:2px 0}'
      + '.pc-row{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #f0f1f4}.pc-row:last-child{border-bottom:0}'
      + '.pc-chip{display:inline-block;border-radius:99px;padding:2px 9px;font-size:11.5px;font-weight:700;background:#eef0f4;color:#4b5563}.pc-chip.ok{background:#e3f6ec;color:#16784a}.pc-chip.bad{background:#fde8e6;color:#b3261e}.pc-chip.warn{background:#fff3d6;color:#8a5a00}'
      + '.pc-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:6px}.pc-tile{background:#f6f7f9;border-radius:12px;padding:8px 6px;text-align:center}.pc-tile b{display:block;font-size:17px}.pc-tile small{color:#6b7280;font-size:11px}'
      + '.pc-btn{font:inherit;font-size:12.5px;font-weight:700;border:1px solid #d9dde3;background:#fff;border-radius:10px;padding:6px 12px;cursor:pointer}.pc-btn.go{background:#14161a;color:#fff;border-color:#14161a}.pc-btn.red{color:#b3261e}.pc-btn:disabled{opacity:.5;cursor:default}'
      + '.pc-pen{border:1px solid #eceef1;border-radius:12px;padding:8px 10px;margin-bottom:6px}.pc-pen .t{display:flex;justify-content:space-between;gap:8px;font-weight:700}.pc-pen .n{color:#6b7280;font-size:12px;margin-top:2px}.pc-pen .a{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}'
      + '.pc-f{display:grid;gap:6px;margin-top:6px}.pc-f input,.pc-f select{font:inherit;border:1px solid #d9dde3;border-radius:10px;padding:7px 10px;width:100%;background:#fff}.pc-f .two{display:grid;grid-template-columns:1fr 1fr;gap:8px}.pc-f label{font-size:11.5px;color:#6b7280;font-weight:700}'
      + '.pc-modes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:10px 0}.pc-mode{border:1.5px solid #e1e4ea;border-radius:14px;padding:10px;cursor:pointer}.pc-mode.on{border-color:#16945a;background:#f1faf5}.pc-mode b{display:block;font-size:13px}.pc-mode span{font-size:11.5px;color:#6b7280}'
      + '.pc-err{background:#fde8e6;color:#b3261e;border-radius:12px;padding:10px 12px}.pc-load{color:#6b7280;padding:14px 0}.pc-msg{font-size:12.5px;color:#16784a;margin-top:6px}.pc-note{background:#f6f7f9;border-radius:12px;padding:9px 11px;font-size:12.5px;color:#4a505c;line-height:1.5}'
      + '.pc-head{display:flex;gap:12px;align-items:center;margin-bottom:2px}.pc-av{width:56px;height:56px;border-radius:50%;background:#e5e7eb center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;color:#fff;flex:none}'
      + '.pc-info{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:2px 14px;margin-top:8px;font-size:12.5px}.pc-info div{display:flex;justify-content:space-between;gap:8px;border-bottom:1px solid #f0f1f4;padding:3px 0;min-width:0}.pc-info span{color:#6b7280}.pc-info b{text-align:right;overflow-wrap:anywhere}'
      + '.pc-att{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.pc-chip.big{font-size:12px;padding:3px 10px}'
      + '.pc-tbl{width:100%;border-collapse:collapse;font-size:12.5px}.pc-tbl td,.pc-tbl th{padding:4px 6px;border-bottom:1px solid #f0f1f4;text-align:right}.pc-tbl th{color:#6b7280;font-weight:700}.pc-tbl td:first-child,.pc-tbl th:first-child{text-align:left}'
      + '.pc [hidden],.pc-ov [hidden]{display:none !important}'
      + '@media(max-width:480px){.pc-info{grid-template-columns:1fr}.pc-tiles{grid-template-columns:repeat(2,1fr)}.pc-modes{grid-template-columns:1fr}}';
    document.head.appendChild(st);
  }
  function overlay(inner) {
    css(); const ov = document.createElement('div'); ov.className = 'pc-ov'; ov.innerHTML = '<div class="pc-box"><button class="pc-x" type="button" aria-label="Close">×</button><div class="pc-in">' + (inner || '') + '</div></div>';
    const close = () => { ov.remove(); document.removeEventListener('keydown', key); }; const key = (e) => { if (e.key === 'Escape') close(); };
    ov.addEventListener('mousedown', (e) => { if (e.target === ov) close(); }); ov.querySelector('.pc-x').onclick = close; document.addEventListener('keydown', key);
    document.body.appendChild(ov); ov.close = close; ov.inner = ov.querySelector('.pc-in'); return ov;
  }

  /* ---------- the timing editor: ONE copy, used here and by Boss Engine "Who punches how" ---------- */
  function timing(list, done) {
    list = (list || []).filter(Boolean); if (!list.length) return;
    const one = list.length === 1 ? list[0] : null; let mode = one ? one.mode : 'fixed';
    const v = (x, d) => esc(one ? (x === undefined || x === null || x === '' ? d : x) : d);
    const first = one ? esc(String(one.name || '').split(' ')[0] || 'this person') : '';
    const ov = overlay('<div class="pc"><h2>' + (one ? esc(one.name) + ': how does ' + first + ' punch?' : list.length + ' people: set one rule for all') + '</h2><div class="sub">Pick one rule. Lateness, "away" checks and reminders follow it. Penalties are never added by themselves: you assign them.</div>'
      + '<div class="pc-modes" id="pcModes"></div>'
      + '<div id="pcFix" class="pc-f"><div class="two"><div><label>Shift starts</label><input type="time" id="pxS" value="' + v(one && one.fx_start, '') + '"></div><div><label>Shift ends</label><input type="time" id="pxE" value="' + v(one && one.fx_end, '') + '"></div></div>'
      + '<div><label>Late after (min)</label><input type="number" id="pxL" min="0" max="240" value="' + v(one && one.fx_late, '15') + '"></div>' + (one ? '' : '<div class="sub">Leave the start empty to keep each person\'s own times.</div>') + '</div>'
      + '<div id="pcHrs" class="pc-f"><div class="two"><div><label>Hours to complete each day</label><input type="number" step="0.25" min="1" max="16" id="phT" value="' + v(one && one.hours_target, '8') + '"></div><div><label>Counts as a full day from (hours)</label><input type="number" step="0.25" min="0" max="16" id="phF" value="' + v(one && one.hours_full, '') + '"></div></div>'
      + '<div class="two"><div><label>Earliest punch in (empty = any time)</label><input type="time" id="phA" value="' + v(one && one.win_from, '') + '"></div><div><label>Latest punch in (empty = any time)</label><input type="time" id="phB" value="' + v(one && one.win_to, '') + '"></div></div></div>'
      + '<div class="pc-note" id="pcNote" style="margin-top:10px"></div><div id="pcOut"></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button class="pc-btn" id="pcNo" type="button">Cancel</button><button class="pc-btn go" id="pcOk" type="button">Save</button></div></div>');
    const notes = { fixed: '<b>What happens:</b> in at the set time, out at the set time. Late after the grace minutes.', hours: '<b>What happens:</b> they can come any time (inside the window, if you set one) but must complete the hours. A short day is marked short.', free: '<b>What happens:</b> punching is optional. Never late and never marked away.' };
    const paint = () => {
      ov.querySelector('#pcModes').innerHTML = Object.keys(MODES).map((k) => '<div class="pc-mode' + (mode === k ? ' on' : '') + '" data-m="' + k + '"><b>' + MODES[k][0] + '</b><span>' + MODES[k][1] + '</span></div>').join('');
      ov.querySelectorAll('.pc-mode').forEach((c) => { c.onclick = () => { mode = c.dataset.m; paint(); }; });
      ov.querySelector('#pcFix').style.display = mode === 'fixed' ? '' : 'none'; ov.querySelector('#pcHrs').style.display = mode === 'hours' ? '' : 'none'; ov.querySelector('#pcNote').innerHTML = notes[mode];
    };
    paint();
    ov.querySelector('#pcNo').onclick = ov.close;
    ov.querySelector('#pcOk').onclick = async () => {
      const g = (id) => ov.querySelector(id).value.trim(), body = { ids: list.map((r) => r.id), mode };
      if (mode === 'fixed') { body.shift_start = g('#pxS'); body.shift_end = g('#pxE'); if (body.shift_start) body.late_after = Number(g('#pxL') || 15); }
      if (mode === 'hours') { body.hours_target = Number(g('#phT') || 8); body.hours_full = Number(g('#phF') || 0); body.win_from = g('#phA'); body.win_to = g('#phB'); }
      try { const r = await api.post('bd.b2_punch_set', body); ov.close(); if (done) done(r && r.message || 'Saved.'); }
      catch (e) { ov.querySelector('#pcOut').innerHTML = '<div class="pc-err" style="margin-top:8px">' + esc(e.message || 'Could not save.') + '</div>'; }
    };
  }

  /* ---------- the card ---------- */
  const when = (d) => { const x = new Date(d + 'T12:00:00'); return isNaN(x) ? d : x.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); };
  const whenY = (d) => { const x = new Date(String(d).slice(0, 10) + 'T12:00:00'); return isNaN(x) ? '' : x.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); };
  const monthName = (m) => new Date(m + '-01T12:00:00').toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });
  const service = (d) => { const a = new Date(String(d).slice(0, 10) + 'T12:00:00'); if (isNaN(a)) return ''; const n = new Date(); let m = (n.getFullYear() - a.getFullYear()) * 12 + n.getMonth() - a.getMonth(); if (n.getDate() < a.getDate()) m--; if (m < 0) return ''; const y = Math.floor(m / 12); return (y ? y + ' yr ' : '') + (m % 12) + ' m'; };
  const colors = ['#f59e0b', '#10b981', '#3b82f6', '#ec4899', '#8b5cf6', '#f97316', '#14b8a6', '#ef4444'];
  const colorOf = (n) => colors[[...String(n)].reduce((a, ch) => a + ch.charCodeAt(0), 0) % colors.length];
  const photoUrl = (p) => { try { return p ? API_BASE_URL.replace('/api.php', '') + '/' + String(p).replace(/^\/+/, '') : ''; } catch (e) { return ''; } };
  const row = (a, b) => '<div class="pc-row"><span>' + a + '</span><b>' + b + '</b></div>';

  /* documents: what is missing, waiting for approval, expired or ending soon (licence, insurance, Aadhaar ...) */
  function docState(d, today) {
    if (!d.doc_id || !d.file) return d.required ? ['bad', 'Missing'] : null;
    if (d.expiry) { const left = Math.round((new Date(String(d.expiry).slice(0, 10) + 'T12:00:00') - new Date(today + 'T12:00:00')) / 864e5); if (!isNaN(left)) { if (left < 0) return ['bad', 'Expired ' + whenY(d.expiry)]; if (left <= 30) return ['warn', 'Ends in ' + left + ' days']; } }
    if (d.status && d.status !== 'approved') return ['warn', 'Waiting for approval'];
    return ['ok', 'OK' + (d.expiry ? ' · till ' + whenY(d.expiry) : '')];
  }

  function body(c, o, x, xerr) {
    const e = c.employee, t = c.timing, p = c.pay, st = c.stats, xp = (x && x.person) || {}, pr = Object.assign({}, c.profile || {});
    // anything the person card did not have, the Boss People data fills in (same employee record)
    if (!pr.photo) pr.photo = xp.photo || ''; if (!pr.joining_date) pr.joining_date = xp.joining_date || ''; if (!pr.mobile) pr.mobile = xp.mobile || ''; if (!pr.email) pr.email = xp.email || ''; if (!pr.role) pr.role = xp.designation || e.designation || '';
    const docs = x && x.documents ? x.documents.map((d) => ({ d, s: docState(d, c.today) })).filter((q) => q.s) : [];
    const missing = docs.filter((q) => q.s[1] === 'Missing').map((q) => q.d.name), ending = docs.filter((q) => /^(Expired|Ends)/.test(q.s[1])).map((q) => q.d.name + ' (' + q.s[1].toLowerCase() + ')'), waiting = docs.filter((q) => q.s[1] === 'Waiting for approval').map((q) => q.d.name);
    const agrOpen = x && x.agreements ? x.agreements.filter((a) => a.status && !/sign|accept|approved|done|complete/i.test(a.status)) : [];
    const att = [];
    if (missing.length) att.push(['bad', 'Missing: ' + missing.join(', ')]);
    if (ending.length) att.push(['warn', ending.join(', ')]);
    if (waiting.length) att.push(['warn', 'Waiting for approval: ' + waiting.join(', ')]);
    if (agrOpen.length) att.push(['warn', 'Agreement not signed: ' + agrOpen.map((a) => a.name).join(', ')]);
    if (p.type === 'commission' && !t.own) att.push(['', 'No timing set']);
    const pend = c.penalties.filter((q) => q.pending); if (pend.length) att.push(['warn', pend.length + ' penalty cut tomorrow']);
    if (!x) att.push(['bad', 'Documents could not be read' + (xerr ? ': ' + xerr : '')]);

    let h = '<div class="pc">';
    const initial = esc((e.name || '?').trim()[0] || '?'), ph = photoUrl(pr.photo);
    const chips = '<span class="pc-chip big ok">' + esc(pr.role || 'No role set') + '</span> ' + (pr.login_role ? '<span class="pc-chip big">login: ' + esc(pr.login_role) + '</span> ' : '<span class="pc-chip big">no login</span> ') + (e.status && e.status !== 'active' ? '<span class="pc-chip big warn">' + esc(e.status) + '</span>' : '');
    h += o.header ? '<div class="pc-head"><span class="pc-av" style="' + (ph ? 'background-image:url(\'' + esc(ph) + '\')' : 'background:' + colorOf(e.name)) + '">' + (ph ? '' : initial) + '</span><div style="min-width:0"><h2>' + esc(e.name) + '</h2><div style="margin-top:3px">' + chips + '</div>'
      + '<div class="sub">' + esc([e.code, e.dept].filter(Boolean).join(' · ')) + '</div></div></div>' : '<div style="margin-bottom:2px">' + chips + '</div>';
    h += '<div class="pc-info">' + (pr.joining_date ? '<div><span>Joined</span><b>' + whenY(pr.joining_date) + (service(pr.joining_date) ? ' · ' + service(pr.joining_date) : '') + '</b></div>' : '<div><span>Joined</span><b>not recorded</b></div>')
      + (pr.employment_type ? '<div><span>Employment</span><b>' + esc(pr.employment_type) + '</b></div>' : '') + (pr.manager ? '<div><span>Reports to</span><b>' + esc(pr.manager) + '</b></div>' : '')
      + (pr.mobile ? '<div><span>Mobile</span><b>' + esc(pr.mobile) + '</b></div>' : '') + (pr.email ? '<div><span>Email</span><b>' + esc(pr.email) + '</b></div>' : '') + '</div>';
    if (att.length) h += '<div class="pc-att">' + att.map((a) => '<span class="pc-chip big ' + a[0] + '">' + esc(a[1]) + '</span>').join('') + '</div>';

    h += '<div class="pc-claude" id="pcSum"><b>Claude</b><span>Reading this month…</span></div>';

    h += '<h4>Pay</h4>';
    if (p.type === 'salary') h += row('Fixed salary', p.monthly != null ? inr(p.monthly) + ' a month · ' + inr(p.per_day) + ' a day (÷ 30)' : 'Fixed salary')
      + (c.salary_history && c.salary_history.length ? '<table class="pc-tbl"><tr><th>Month</th><th>Earned</th><th>Penalties</th><th>You get</th></tr>' + c.salary_history.map((m, i) => '<tr><td>' + monthName(m.month) + (i === 0 ? ' (so far)' : '') + '</td><td>' + inr(m.earned) + '</td><td>' + (m.penalty_total ? '−' + inr(m.penalty_total) : '–') + '</td><td><b>' + inr(m.net_pay) + '</b></td></tr>').join('') + '</table>' : '');
    else h += row('Commission-based', 'The day\'s commission is the day\'s pay') + '<div class="pc-note">Penalties are assigned by you and come out of the commission. Set timing below to see who was late.</div>'
      + '<div id="pcComm" style="margin-top:6px">' + (c.can_edit ? '<button class="pc-btn" data-a="comm" type="button">Show commission of the last 2 months</button>' : '') + '</div>';

    h += '<h4>Timing</h4><div class="pc-row"><span><span class="pc-chip ' + (t.penalised ? 'ok' : '') + '">' + (p.type === 'commission' && !t.own ? 'Not set' : MODES[t.mode][0]) + '</span> ' + esc(t.label) + '</span>'
      + (c.is_admin ? '<button class="pc-btn" data-a="timing" type="button">Change timing</button>' : '') + '</div>';

    const ts = x && x.task_stat;
    h += '<h4>This month</h4><div class="pc-tiles"><div class="pc-tile"><b>' + st.present + '</b><small>days punched in</small></div><div class="pc-tile"><b' + (st.late ? ' style="color:#b3261e"' : '') + '>' + st.late + '</b><small>late</small></div><div class="pc-tile"><b' + (st.short ? ' style="color:#b3261e"' : '') + '>' + st.short + '</b><small>short on hours</small></div><div class="pc-tile"><b' + (st.penalties_month ? ' style="color:#b3261e"' : '') + '>' + inr(st.penalties_month) + '</b><small>penalties</small></div></div>';
    if (ts) h += row('Tasks today', (ts.done || 0) + ' of ' + (ts.today || 0) + ' done' + (ts.late ? ' · <span style="color:#b3261e">' + ts.late + ' late</span>' : ''));
    if (c.days.length) h += '<details style="margin-top:6px"><summary style="cursor:pointer;color:#6b7280;font-size:12.5px">Last punches</summary>' + c.days.map((d) => '<div class="pc-row"><span>' + when(d.date) + '</span><span>' + esc(d.in) + ' to ' + esc(d.out || '…') + (d.flag ? ' <span class="pc-chip bad">' + (d.flag === 'late' ? 'late' : 'short') + '</span>' : '') + '</span></div>').join('') + '</details>';

    if (!x) h += '<h4>Documents</h4><div class="pc-err">Documents, agreements and leave could not be loaded' + (xerr ? ' (' + esc(xerr) + ')' : '') + '. Refresh the page (Ctrl+Shift+R). If it stays, tell Claude this message.</div>';
    if (x) {
      const canDoc = c.can_docs, canOk = c.can_edit;
      h += '<h4>Documents</h4>' + (x.documents && x.documents.length ? x.documents.map((d) => { const s = docState(d, c.today);
        return '<div class="pc-row" style="flex-wrap:wrap;align-items:center"><span>' + esc(d.name) + (d.number ? ' <span style="color:#9aa1ad">' + esc(d.number) + '</span>' : '') + '</span><span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end">'
          + (s ? '<span class="pc-chip ' + s[0] + '">' + esc(s[1]) + '</span>' : '<span class="pc-chip">not needed</span>')
          + (d.doc_id && d.file ? '<button class="pc-btn" data-a="dview" data-id="' + d.doc_id + '" type="button">View</button>' : '')
          + (canOk && d.doc_id && d.file && d.status && d.status !== 'approved' ? '<button class="pc-btn go" data-a="dok" data-id="' + d.doc_id + '" type="button">Approve</button><button class="pc-btn red" data-a="dno" data-id="' + d.doc_id + '" type="button">Decline</button>' : '')
          + (canDoc ? '<button class="pc-btn" data-a="dup" data-type="' + d.type_id + '" type="button">' + (d.doc_id && d.file ? 'Replace' : 'Upload') + '</button><input type="file" accept=".pdf,.jpg,.jpeg,.png" data-file="' + d.type_id + '" hidden>' : '') + '</span></div>'; }).join('') : '<div class="sub">No documents are required for this person.</div>');
      if (x.agreements && x.agreements.length) h += '<h4>Agreements</h4>' + x.agreements.slice(0, 5).map((a) => row(esc(a.name), '<span class="pc-chip ' + (/sign|accept|approved|done|complete/i.test(a.status) ? 'ok' : 'warn') + '">' + esc(a.status || '') + '</span>')).join('');
      if (x.leave && x.leave.length) h += '<h4>Leave</h4>' + x.leave.slice(0, 4).map((l) => row(esc(l.leave_type || 'Leave') + ' · ' + when(String(l.date_from).slice(0, 10)) + (l.date_to && l.date_to !== l.date_from ? ' to ' + when(String(l.date_to).slice(0, 10)) : ''), esc(l.status || ''))).join('');
    }

    h += '<h4>Penalties</h4><div id="pcPens">' + (c.penalties.length ? c.penalties.map((q) => {
      const chip = q.status === 'applied' ? (q.pending ? '<span class="pc-chip warn">cut ' + when(q.cut_on) + '</span>' : '<span class="pc-chip bad">applied</span>') : '<span class="pc-chip">' + esc(q.status) + '</span>';
      return '<div class="pc-pen" data-id="' + q.id + '"><div class="t"><span>' + esc(q.title) + '</span><span>' + (q.orig_amount != null && q.orig_amount !== q.amount ? '<s style="color:#9aa1ad;font-weight:500">' + inr(q.orig_amount) + '</s> ' : '') + inr(q.amount) + '</span></div>'
        + '<div class="n">' + when(q.date) + ' · ' + (q.source === 'auto' ? 'automatic' : 'by hand') + ' · from ' + esc(q.from) + ' ' + chip + (q.note ? '<br>' + esc(q.note) : '') + (q.resolve_note ? '<br>' + esc(q.resolve_note) : '') + '</div>'
        + (c.can_edit && q.status === 'applied' ? '<div class="a"><button class="pc-btn" data-a="pedit" type="button">Change amount</button><button class="pc-btn red" data-a="pwaive" type="button">Waive</button></div><div class="pc-f" data-slot hidden></div>' : '') + '</div>';
    }).join('') : '<div class="sub">No penalties in the last two months.</div>') + '</div>'
      + (c.can_edit ? '<div style="margin-top:8px"><button class="pc-btn go" data-a="padd" type="button">+ Add a penalty</button></div><div class="pc-f" id="pcAdd" hidden></div>' : '') + '<div id="pcMsg"></div></div>';
    return h;
  }

  async function render(el, id, opts) {
    css(); opts = opts || {}; el.innerHTML = '<div class="pc-load">Loading…</div>';
    let c, x = null, xerr = ''; try { const r = await Promise.all([api.get('person.card', { employee_id: id }), api.get('bd.pp_person', { id: id, month: new Date().toISOString().slice(0, 7) }).then((v) => ({ v }), (er) => ({ er }))]); c = r[0]; x = r[1].v || null; xerr = r[1].er ? String(r[1].er.message || r[1].er) : ''; } catch (e) { el.innerHTML = '<div class="pc-err">' + esc(e.message || 'Could not open this person.') + '</div>'; return null; }
    const again = () => { render(el, id, opts); if (opts.onChange) opts.onChange(); };
    el.innerHTML = body(c, opts, x, xerr);
    api.get('person.summary', { employee_id: id }).then((r) => {
      const b = el.querySelector('#pcSum'); if (!b) return;
      b.innerHTML = '<b>Claude' + (r.by === 'claude' ? '' : ' (quick read)') + '</b>' + esc(r.summary) + ((r.fixes || []).length ? '<ul>' + r.fixes.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '');
    }).catch(() => { const b = el.querySelector('#pcSum'); if (b) b.innerHTML = '<b>Claude</b>The summary is not available right now.'; });
    const msg = (t, bad) => { const m = el.querySelector('#pcMsg'); if (m) m.innerHTML = '<div class="' + (bad ? 'pc-err' : 'pc-msg') + '">' + esc(t) + '</div>'; };
    const t = c.timing;
    el.onclick = async (ev) => {
      const b = ev.target.closest('button[data-a]'); if (!b) return; const a = b.dataset.a, pen = b.closest('.pc-pen');
      if (a === 'comm') {
        const box = el.querySelector('#pcComm'); box.innerHTML = '<div class="pc-load">Reading the commission…</div>'; let out = '<table class="pc-tbl"><tr><th>Month</th><th>Commission</th><th>Days</th></tr>';
        for (const k of [0, 1, 2]) {
          const d = new Date(c.today + 'T12:00:00'); d.setDate(1); d.setMonth(d.getMonth() - k); const ym = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'), dim = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
          const end = k === 0 ? c.today : ym + '-' + String(dim).padStart(2, '0'), days = k === 0 ? Number(c.today.slice(8)) : dim;
          try { const r = await api.get('bd.b2_cm_recount', { days, end }); const me = (r.people || []).find((q) => String(q.name).toLowerCase().replace(/\s+/g, ' ').trim() === String(c.employee.name).toLowerCase().replace(/\s+/g, ' ').trim());
            out += '<tr><td>' + monthName(ym) + (k === 0 ? ' (so far)' : '') + '</td><td>' + (me ? '<b>' + inr(me.new) + '</b>' : '–') + '</td><td>' + (me ? me.days : 0) + '</td></tr>'; } catch (x2) { out += '<tr><td>' + monthName(ym) + '</td><td colspan="2" style="text-align:left">could not read</td></tr>'; }
        }
        box.innerHTML = out + '</table><div class="sub">From Commission → Result &amp; rules (worked out again for those days).</div>'; return;
      }
      if (a === 'timing') return timing([{ id: id, name: c.employee.name, mode: t.mode, fx_start: t.fx_start, fx_end: t.fx_end, fx_late: t.fx_late, hours_target: t.hours_target, hours_full: t.hours_full, win_from: t.win_from, win_to: t.win_to }], again);
      if (a === 'padd') {
        const box = el.querySelector('#pcAdd'); if (!box.hidden) { box.hidden = true; return; }
        let rules = []; try { rules = ((await api.get('penalties.rules')).rules || []).filter((r) => r.active); } catch (x) {}
        box.innerHTML = '<div class="pc-note" id="paAsk"><div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><div style="flex:1;min-width:130px"><label>Day to look at</label><input id="paSugDate" type="date" value="' + c.today + '"></div><button class="pc-btn" data-a="psug" type="button">Ask Claude to suggest</button></div><div id="paSug" style="margin-top:6px">Claude reads that day\'s punches and suggests. Nothing is saved until you press Apply.</div></div>'
          + '<label>Pick a saved rule (or type your own below)</label><select id="paRule"><option value="">Write my own</option>' + rules.map((r) => '<option value="' + r.rule_id + '" data-t="' + esc(r.name) + '" data-a="' + r.amount + '">' + esc(r.name) + ' · ' + inr(r.amount) + '</option>').join('') + '</select>'
          + '<label>What is it for</label><input id="paTitle" placeholder="Late punch-in"><div class="two"><div><label>Amount ₹</label><input id="paAmt" type="number" min="1" inputmode="decimal"></div><div><label>Date</label><input id="paDate" type="date" value="' + c.today + '"></div></div>'
          + '<label>Note (optional)</label><input id="paNote"><div class="sub">It is cut from ' + (c.pay.type === 'commission' || c.pay.sales ? 'the commission' : 'the salary') + ' on the next day, and the person is told.</div><div style="display:flex;gap:8px;justify-content:flex-end"><button class="pc-btn go" data-a="padd-ok" type="button">Apply penalty</button></div>';
        box.hidden = false; const sel = box.querySelector('#paRule'); sel.onchange = () => { const o = sel.selectedOptions[0]; if (o && o.dataset.t) { box.querySelector('#paTitle').value = o.dataset.t; box.querySelector('#paAmt').value = o.dataset.a; } };
        return;
      }
      if (a === 'psug') {
        const box = el.querySelector('#paSug'), d = el.querySelector('#paSugDate').value; box.textContent = 'Reading the punches…'; b.disabled = true;
        try {
          const r = await api.get('person.suggest_penalty', { employee_id: id, date: d }); const sg = r.suggestion; let h2 = '<b>' + esc(r.reason) + '</b>';
          if (sg) { h2 += '<div style="margin-top:6px">Suggested: ' + esc(sg.title) + ' · ' + inr(sg.amount) + (sg.step ? ' (' + esc(sg.step) + ')' : '') + '</div><div style="margin-top:6px"><button class="pc-btn go" data-a="psug-use" type="button">Use this suggestion</button></div>'; } else h2 += '<div style="margin-top:4px;color:#6b7280">No penalty suggested.</div>';
          box.innerHTML = h2; box._s = sg; box._d = r.date;
        } catch (x) { box.innerHTML = '<span style="color:#b3261e">' + esc(x.message || 'Could not get a suggestion.') + '</span>'; }
        b.disabled = false; return;
      }
      if (a === 'psug-use') {
        const box = el.querySelector('#paSug'), sg = box._s; if (!sg) return; const f = el.querySelector('#pcAdd');
        f.querySelector('#paTitle').value = sg.title; f.querySelector('#paAmt').value = sg.amount; f.querySelector('#paDate').value = box._d || c.today;
        const sel = f.querySelector('#paRule'); if (sel) sel.value = String(sg.rule_id || ''); msg('Filled in. Check it, then press Apply penalty.'); return;
      }
      if (a === 'dview') {
        try { const r = await fetch(api.buildUrl('bd.pp_doc_file', { id: b.dataset.id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('The file could not be opened.'); window.open(URL.createObjectURL(await r.blob()), '_blank'); } catch (x) { msg(x.message, true); }
        return;
      }
      if (a === 'dok') { try { await api.post('employee_documents.approve', { employee_document_id: Number(b.dataset.id) }); again(); } catch (x) { msg(x.message, true); } return; }
      if (a === 'dno') { const why = window.prompt('Why is it declined? (the file is removed and the person is told)'); if (why === null) return; try { await api.post('employee_documents.decline', { employee_document_id: Number(b.dataset.id), reason: why }); again(); } catch (x) { msg(x.message, true); } return; }
      if (a === 'dup') {
        const inp = el.querySelector('input[data-file="' + b.dataset.type + '"]');
        inp.onchange = async () => { if (!inp.files[0]) return; const fd = new FormData(); fd.append('employee_id', id); fd.append('document_type_id', b.dataset.type); fd.append('document_file', inp.files[0]); b.disabled = true; b.textContent = 'Uploading…';
          try { await api.upload('employee_documents.upload', fd); again(); } catch (x) { b.disabled = false; b.textContent = 'Upload'; msg(x.message, true); } };
        inp.click(); return;
      }
      if (a === 'padd-ok') {
        try { const r = await api.post('penalties.apply', { employee_id: id, title: el.querySelector('#paTitle').value.trim(), amount: Number(el.querySelector('#paAmt').value), date: el.querySelector('#paDate').value, note: el.querySelector('#paNote').value.trim(), notify: true }); again(); } catch (x) { msg(x.message, true); }
        return;
      }
      if (a === 'pedit' || a === 'pwaive') {
        const slot = pen.querySelector('[data-slot]'); const pid = Number(pen.dataset.id), x = c.penalties.find((q) => q.id === pid);
        if (!slot.hidden && slot.dataset.k === a) { slot.hidden = true; return; } slot.dataset.k = a; slot.hidden = false;
        slot.innerHTML = a === 'pedit' ? '<div class="two"><div><label>New amount ₹</label><input data-f="amt" type="number" min="1" value="' + x.amount + '"></div><div><label>Note</label><input data-f="note" value="' + esc(x.note) + '"></div></div><div style="display:flex;justify-content:flex-end"><button class="pc-btn go" data-a="pedit-ok" type="button">Save</button></div>'
          : '<label>Why is it waived? (the person is told)</label><input data-f="why"><div style="display:flex;justify-content:flex-end"><button class="pc-btn go" data-a="pwaive-ok" type="button">Waive it</button></div>';
        return;
      }
      if (a === 'pedit-ok' || a === 'pwaive-ok') {
        const slot = b.closest('[data-slot]'), pid = Number(pen.dataset.id), f = (k) => slot.querySelector('[data-f="' + k + '"]').value.trim();
        try { if (a === 'pedit-ok') await api.post('person.penalty_edit', { penalty_id: pid, amount: Number(f('amt')), note: f('note') }); else await api.post('penalties.waive', { penalty_id: pid, reason: f('why') }); again(); } catch (x) { msg(x.message, true); }
      }
    };
    return c;
  }
  function open(id, opts) { const ov = overlay('<div class="pc-load">Loading…</div>'); return render(ov.inner, id, Object.assign({ header: true }, opts || {})); }
  return { open, render, timing };
})();
