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
      + '.pc [hidden],.pc-ov [hidden]{display:none !important}'
      + '@media(max-width:480px){.pc-tiles{grid-template-columns:repeat(2,1fr)}.pc-modes{grid-template-columns:1fr}}';
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
  function body(c, o) {
    const e = c.employee, t = c.timing, p = c.pay, st = c.stats;
    let h = '<div class="pc">';
    if (o.header) h += '<h2>' + esc(e.name) + '</h2><div class="sub">' + esc([e.code, e.designation, e.dept].filter(Boolean).join(' · ')) + '</div>';
    h += '<div class="pc-claude" id="pcSum"><b>Claude</b><span>Reading this month…</span></div>';

    h += '<h4>Pay</h4>';
    if (p.type === 'salary') h += '<div class="pc-row"><span>Fixed salary</span><b>' + (p.monthly != null ? inr(p.monthly) + ' a month · ' + inr(p.per_day) + ' a day (÷ 30)' : 'Fixed salary') + '</b></div>'
      + (c.salary ? '<div class="pc-row"><span>This month so far</span><b>' + inr(c.salary.earned) + ' earned − ' + inr(c.salary.penalty_total) + ' penalties = ' + inr(c.salary.net_pay) + '</b></div>' : '');
    else h += '<div class="pc-row"><span>Commission-based</span><b>The day\'s commission is the day\'s pay</b></div><div class="pc-note">Penalties are assigned by you and come out of the commission. Set timing below to see who was late.</div>';

    h += '<h4>Timing</h4><div class="pc-row"><span><span class="pc-chip ' + (t.penalised ? 'ok' : '') + '">' + (p.type === 'commission' && !t.own ? 'Not set' : MODES[t.mode][0]) + '</span> ' + esc(t.label) + '</span>'
      + (c.is_admin ? '<button class="pc-btn" data-a="timing" type="button">Change timing</button>' : '') + '</div>';

    h += '<h4>This month</h4><div class="pc-tiles"><div class="pc-tile"><b>' + st.present + '</b><small>days punched in</small></div><div class="pc-tile"><b' + (st.late ? ' style="color:#b3261e"' : '') + '>' + st.late + '</b><small>late</small></div><div class="pc-tile"><b' + (st.short ? ' style="color:#b3261e"' : '') + '>' + st.short + '</b><small>short on hours</small></div><div class="pc-tile"><b' + (st.penalties_month ? ' style="color:#b3261e"' : '') + '>' + inr(st.penalties_month) + '</b><small>penalties</small></div></div>';
    if (c.days.length) h += '<details style="margin-top:6px"><summary style="cursor:pointer;color:#6b7280;font-size:12.5px">Last punches</summary>' + c.days.map((d) => '<div class="pc-row"><span>' + when(d.date) + '</span><span>' + esc(d.in) + ' to ' + esc(d.out || '…') + (d.flag ? ' <span class="pc-chip bad">' + (d.flag === 'late' ? 'late' : 'short') + '</span>' : '') + '</span></div>').join('') + '</details>';

    h += '<h4>Penalties</h4><div id="pcPens">' + (c.penalties.length ? c.penalties.map((x) => {
      const chip = x.status === 'applied' ? (x.pending ? '<span class="pc-chip warn">cut ' + when(x.cut_on) + '</span>' : '<span class="pc-chip bad">applied</span>') : '<span class="pc-chip">' + esc(x.status) + '</span>';
      return '<div class="pc-pen" data-id="' + x.id + '"><div class="t"><span>' + esc(x.title) + '</span><span>' + (x.orig_amount != null && x.orig_amount !== x.amount ? '<s style="color:#9aa1ad;font-weight:500">' + inr(x.orig_amount) + '</s> ' : '') + inr(x.amount) + '</span></div>'
        + '<div class="n">' + when(x.date) + ' · ' + (x.source === 'auto' ? 'automatic' : 'by hand') + ' · from ' + esc(x.from) + ' ' + chip + (x.note ? '<br>' + esc(x.note) : '') + (x.resolve_note ? '<br>' + esc(x.resolve_note) : '') + '</div>'
        + (c.can_edit && x.status === 'applied' ? '<div class="a"><button class="pc-btn" data-a="pedit" type="button">Change amount</button><button class="pc-btn red" data-a="pwaive" type="button">Waive</button></div><div class="pc-f" data-slot hidden></div>' : '') + '</div>';
    }).join('') : '<div class="sub">No penalties in the last two months.</div>') + '</div>'
      + (c.can_edit ? '<div style="margin-top:8px"><button class="pc-btn go" data-a="padd" type="button">+ Add a penalty</button></div><div class="pc-f" id="pcAdd" hidden></div>' : '') + '<div id="pcMsg"></div></div>';
    return h;
  }

  async function render(el, id, opts) {
    css(); opts = opts || {}; el.innerHTML = '<div class="pc-load">Loading…</div>';
    let c; try { c = await api.get('person.card', { employee_id: id }); } catch (e) { el.innerHTML = '<div class="pc-err">' + esc(e.message || 'Could not open this person.') + '</div>'; return null; }
    const again = () => { render(el, id, opts); if (opts.onChange) opts.onChange(); };
    el.innerHTML = body(c, opts);
    api.get('person.summary', { employee_id: id }).then((r) => {
      const b = el.querySelector('#pcSum'); if (!b) return;
      b.innerHTML = '<b>Claude' + (r.by === 'claude' ? '' : ' (quick read)') + '</b>' + esc(r.summary) + ((r.fixes || []).length ? '<ul>' + r.fixes.map((x) => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '');
    }).catch(() => { const b = el.querySelector('#pcSum'); if (b) b.innerHTML = '<b>Claude</b>The summary is not available right now.'; });
    const msg = (t, bad) => { const m = el.querySelector('#pcMsg'); if (m) m.innerHTML = '<div class="' + (bad ? 'pc-err' : 'pc-msg') + '">' + esc(t) + '</div>'; };
    const t = c.timing;
    el.onclick = async (ev) => {
      const b = ev.target.closest('button[data-a]'); if (!b) return; const a = b.dataset.a, pen = b.closest('.pc-pen');
      if (a === 'timing') return timing([{ id: id, name: c.employee.name, mode: t.mode, fx_start: t.fx_start, fx_end: t.fx_end, fx_late: t.fx_late, hours_target: t.hours_target, hours_full: t.hours_full, win_from: t.win_from, win_to: t.win_to }], again);
      if (a === 'padd') {
        const box = el.querySelector('#pcAdd'); if (!box.hidden) { box.hidden = true; return; }
        let rules = []; try { rules = ((await api.get('penalties.rules')).rules || []).filter((r) => r.active); } catch (x) {}
        box.innerHTML = '<label>Pick a saved rule (or type your own below)</label><select id="paRule"><option value="">Write my own</option>' + rules.map((r) => '<option value="' + r.rule_id + '" data-t="' + esc(r.name) + '" data-a="' + r.amount + '">' + esc(r.name) + ' · ' + inr(r.amount) + '</option>').join('') + '</select>'
          + '<label>What is it for</label><input id="paTitle" placeholder="Late punch-in"><div class="two"><div><label>Amount ₹</label><input id="paAmt" type="number" min="1" inputmode="decimal"></div><div><label>Date</label><input id="paDate" type="date" value="' + c.today + '"></div></div>'
          + '<label>Note (optional)</label><input id="paNote"><div class="sub">It is cut from ' + (c.pay.type === 'commission' || c.pay.sales ? 'the commission' : 'the salary') + ' on the next day, and the person is told.</div><div style="display:flex;gap:8px;justify-content:flex-end"><button class="pc-btn go" data-a="padd-ok" type="button">Apply penalty</button></div>';
        box.hidden = false; const sel = box.querySelector('#paRule'); sel.onchange = () => { const o = sel.selectedOptions[0]; if (o && o.dataset.t) { box.querySelector('#paTitle').value = o.dataset.t; box.querySelector('#paAmt').value = o.dataset.a; } };
        return;
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
