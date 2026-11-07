/* Boss Engine 2 — shared pieces for the Boss Engine page, the section page, the Claude chip and My Day.
   Needs api.js and boss_common.js (BD). Everything the screens draw comes from api.php?action=bd.b2_* . */
const B2 = (() => {
  const { esc, $, toast, inr, t12 } = BD;
  const KINDS = {
    daily: ['Daily', 'Every working day. Done or not done.'],
    number: ['Number', 'A number typed every day, with a screenshot as proof.'],
    short: ['Short', 'One job with one deadline.'],
    temp: ['Temporary', 'Like Daily, but only for some days (a festival, a rush).'],
    followup: ['Follow-up', 'Chase something until it comes.'],
    fix: ['To fix', 'Something to repair or correct.'],
    buy: ['To buy', 'Something to purchase.'],
    improve: ['Improve', 'Help a person or a task get better. Closes by itself after good days.'],
  };
  const ONCE = ['short', 'followup', 'fix', 'buy', 'improve'];
  const PROOFS = { tick: 'Tap done', photo: 'Photo', text: 'A few words', number: 'Number + screenshot', screenshot: 'Screenshot', auto: 'The system checks it' };
  const STEP = ['asking', 'decide', 'work', 'watch', 'closed'];
  const get = (a, p) => api.get(a, p || {});
  const post = (a, b) => api.post(a, b || {});
  const fail = (e) => toast(e && e.message ? e.message : 'Something went wrong. Try again.', true);

  /* ---------- small pieces ---------- */
  function strip(s, big) {
    return '<span class="b2-strip' + (big ? ' big' : '') + '" title="Last 7 days">' + (s || []).map((x) => '<i class="' + esc(x.s) + '" title="' + esc(x.d + (x.v != null ? ' · ' + x.v : '')) + '"></i>').join('') + '</span>';
  }
  const st = (state, label) => '<span class="b2-st ' + esc(state) + '">' + esc(label || state) + '</span>';
  function spark(series, target) {
    const v = (series || []).map((x) => x.v); const nums = v.filter((x) => x != null);
    if (!nums.length) return '<svg viewBox="0 0 100 34"><text x="2" y="20" font-size="9" fill="#9aa0ab">No numbers yet</text></svg>';
    let mx = Math.max.apply(null, nums.concat(target ? [target] : [])), mn = Math.min.apply(null, nums.concat(target ? [target] : [0]));
    if (mx === mn) mx = mn + 1;
    const X = (i) => (i / Math.max(1, v.length - 1)) * 96 + 2, Y = (n) => 30 - ((n - mn) / (mx - mn)) * 26;
    let d = '', pen = false;
    v.forEach((n, i) => { if (n == null) { pen = false; return; } d += (pen ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(n).toFixed(1) + ' '; pen = true; });
    let h = '<svg viewBox="0 0 100 34" preserveAspectRatio="none">';
    if (target) h += '<line x1="0" x2="100" y1="' + Y(target).toFixed(1) + '" y2="' + Y(target).toFixed(1) + '" stroke="#b9bfca" stroke-dasharray="3 3" stroke-width="1"/>';
    h += '<path d="' + d + '" fill="none" stroke="#14161a" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
    const last = v.map((n, i) => [n, i]).filter((p) => p[0] != null).pop();
    if (last) h += '<circle cx="' + X(last[1]).toFixed(1) + '" cy="' + Y(last[0]).toFixed(1) + '" r="2.4" fill="#14161a"/>';
    return h + '</svg>';
  }
  function numTile(n, link) {
    const tg = n.target_state === 'approved' && n.target != null ? n.target : null;
    const bad = tg != null && n.today != null && (n.cmp === 'max' ? n.today > tg : n.today < tg);
    return '<div class="b2-num"' + (link ? ' data-open="' + n.id + '" style="cursor:pointer"' : '') + '><div class="n" title="' + esc(n.title) + '">' + esc(n.title) + '</div>'
      + '<div class="v">' + (n.today != null ? esc(Number(n.today).toLocaleString('en-IN')) : '<span style="color:#9aa0ab">—</span>') + '<small>' + esc(n.unit || '') + '</small></div>'
      + spark(n.series, tg)
      + '<div class="g">' + (tg != null ? (n.cmp === 'max' ? 'At most ' : 'Target ') + esc(Number(tg).toLocaleString('en-IN')) + (bad ? ' · <b style="color:#d9423b">below</b>' : '') : (n.avg14 != null ? 'Average ' + esc(n.avg14) + ' · no target yet' : 'Waiting for numbers')) + '</div></div>';
  }
  function itemRow(it, o) {
    o = o || {};
    const who = it.owner_now || (it.people && it.people[0] && it.people[0].name) || it.seat || 'nobody';
    return '<div class="b2-item" data-open="' + it.id + '"><div class="l"><div class="t">' + esc(it.title) + '</div><div class="s"><span class="b2-kind">' + esc(it.kind_label) + '</span> · ' + esc(who) + (it.cover ? ' <span class="bd-chip warn">' + esc(it.cover) + '</span>' : '') + ' · ' + esc(it.due_label || '')
      + (o.section && it.section ? ' · ' + esc(it.section) : '') + (it.pen_amount > 0 ? ' · ' + (it.pen_mode === 'unit' ? inr(it.pen_unit) + ' per item' : inr(it.pen_amount)) + ' if missed' : '') + '</div></div>'
      + '<div class="r">' + (it.block !== 'once' ? strip(it.strip) : (it.kind === 'improve' ? '<span class="bd-sub">' + (it.good_days || 0) + '/' + (it.improve_days || 7) + ' good days</span>' : '')) + st(it.state, it.state_label) + '</div></div>';
  }
  const IC = { miss: '✗', overdue: '⏰', empty: '○', dept: '▤', cover: '⇄', again: '↻', person: '☺', nocover: '∅', ok: '✓' };

  /* ---------- people, seats, sections (loaded once) ---------- */
  let PEOPLE = null, SECS = null;
  const people = () => PEOPLE || (PEOPLE = get('bd.b2_people').catch((e) => { PEOPLE = null; throw e; }));
  const sections = () => SECS || (SECS = get('bd.b2_sections').then((d) => d.sections).catch((e) => { SECS = null; throw e; }));
  const reset = () => { PEOPLE = null; SECS = null; };

  /* ---------- item detail ---------- */
  async function openItem(id, onChange) {
    let it; try { it = await get('bd.b2_item', { id }); } catch (e) { return fail(e); }
    const tl = (it.timeline || []).slice(0, 12).map((x) => '<div class="b2-ev"><i>•</i><div>' + esc(x.text || x.kind) + '<div class="bd-sub">' + esc(x.at || '') + (x.who ? ' · ' + esc(x.who) : '') + '</div></div></div>').join('');
    const runs = (it.runs || []).slice(0, 10).map((r) => '<div class="b2-ev"><i>' + ({ done: '✓', missed: '✗', open: '·', review: '?', excused: '–' }[r.status] || '·') + '</div><div style="flex:1">' + esc(r.date) + ' · ' + esc(r.status) + (r.value != null ? ' · ' + esc(r.value) : '') + (r.late ? ' · late' : '') + (r.owner ? ' · ' + esc(r.owner) : '') + (r.proof_state === 'flag' ? ' · <b style="color:#9a6200">check the picture</b>' : '') + '</div>').join('');
    const m = BD.modal('<h3>' + esc(it.title) + '</h3>'
      + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px"><span class="bd-chip dark">' + esc(it.kind_label) + '</span>' + st(it.state, it.state_label) + (it.status !== 'active' ? '<span class="bd-chip">' + esc(it.status) + '</span>' : '') + (it.section ? '<span class="bd-chip info">' + esc(it.section) + '</span>' : '') + '</div>'
      + (it.why ? '<p class="bd-sub" style="font-size:13.5px;line-height:1.5;margin:0 0 8px">' + esc(it.why) + '</p>' : '')
      + (it.block !== 'once' ? '<div style="margin:8px 0 12px">' + strip(it.strip, true) + '<div class="bd-sub" style="margin-top:4px">Last 7 days</div></div>' : '')
      + '<div class="bd-2" style="gap:6px 14px;font-size:13px">'
      + '<div><span class="bd-lb">Owner</span><div>' + esc(it.owner_now || it.seat || 'nobody') + (it.cover ? ' <span class="bd-chip warn">' + esc(it.cover) + '</span>' : '') + '</div></div>'
      + '<div><span class="bd-lb">When</span><div>' + esc(it.due_label || '') + '</div></div>'
      + '<div><span class="bd-lb">Backup</span><div>' + esc(it.backup || 'none') + '</div></div>'
      + '<div><span class="bd-lb">Checked by</span><div>' + esc(it.checker || 'nobody') + '</div></div>'
      + '<div><span class="bd-lb">Proof</span><div>' + esc(it.proof_label || '') + '</div></div>'
      + '<div><span class="bd-lb">If missed</span><div>' + (it.pen_amount > 0 ? (it.pen_mode === 'unit' ? inr(it.pen_unit) + ' for each item not done' + (it.pen_min > 0 ? ', at least ' + inr(it.pen_min) : '') + ', at most ' + inr(it.pen_max) + ' a day' : inr(it.pen_amount)) + ' (you are asked first)' : 'No penalty') + (it.fair ? ' · fair start, free for now' : '') + '</div></div>'
      + (it.block === 'number' ? '<div><span class="bd-lb">Target</span><div>' + (it.target != null && it.target_state === 'approved' ? esc(it.target) + ' ' + esc(it.unit || '') : 'Not set yet') + '</div></div>' : '') + '</div>'
      + (it.block === 'number' && it.series ? '' : '')
      + (tl ? '<div class="bd-lb" style="margin-top:14px">What happened</div>' + tl : '')
      + (runs ? '<div class="bd-lb" style="margin-top:14px">Recent days</div>' + runs : '')
      + '<div class="acts"><button class="bd-btn" id="ixClose">Close</button>'
      + (it.status === 'active' ? '<button class="bd-btn" id="ixPause">Pause</button>' : (it.status === 'paused' ? '<button class="bd-btn go" id="ixResume">Resume</button>' : ''))
      + (it.status === 'active' || it.status === 'paused' ? '<button class="bd-btn bad" id="ixStop">' + (it.block === 'once' ? 'Close it' : 'Stop it') + '</button>' : '')
      + (it.block === 'number' ? '<button class="bd-btn" id="ixPin">' + (it.pin ? 'Unpin from Numbers' : 'Pin to Numbers') + '</button>' : '')
      + '<button class="bd-btn pri" id="ixEdit">Edit</button></div>');
    const done = (msg) => { BD.close(); if (msg) toast(msg); reset(); if (onChange) onChange(); };
    const stat = async (s, msg) => { try { await post('bd.b2_item_status', { id, status: s }); done(msg); } catch (e) { fail(e); } };
    m.querySelector('#ixClose').onclick = BD.close;
    const q = (s) => m.querySelector(s);
    if (q('#ixPause')) q('#ixPause').onclick = () => stat('paused', 'Paused.');
    if (q('#ixResume')) q('#ixResume').onclick = () => stat('active', 'Running again.');
    if (q('#ixStop')) q('#ixStop').onclick = async () => { if (await BD.confirm('Close this task?', 'It stops now. The history stays.', 'Close it', true)) stat('archived', 'Closed.'); };
    if (q('#ixPin')) q('#ixPin').onclick = async () => { try { await post('bd.b2_item_pin', { id, pin: it.pin ? 0 : 1 }); done('Saved.'); } catch (e) { fail(e); } };
    q('#ixEdit').onclick = () => { BD.close(); editItem({ item: it, onSaved: onChange }); };
  }

  /* ---------- the add / edit form (with "tell Claude" on top) ---------- */
  async function editItem(o) {
    o = o || {};
    let P, S; try { [P, S] = await Promise.all([people(), sections()]); } catch (e) { return fail(e); }
    const it = o.item || {}; const isNew = !it.id;
    const f = Object.assign({ kind: 'daily', title: '', why: '', section_code: o.section || '', due_time: '19:00', proof: 'tick', pen_amount: 0, pen_mode: 'flat', pen_unit: '', pen_min: 0, pen_max: 500, dow: '1,2,3,4,5,6', cmp: 'min' }, o.prefill || {}, it.id ? {
      kind: it.kind, title: it.title, why: it.why, section_code: it.section_code, due_time: it.due_time, proof: it.proof, pen_amount: it.pen_amount, pen_mode: it.pen_mode || 'flat', pen_unit: it.pen_unit == null ? '' : it.pen_unit, pen_min: it.pen_min || 0, pen_max: it.pen_max || 500, dow: it.dow || '1,2,3,4,5,6', cmp: it.cmp, unit: it.unit, target: it.target_state === 'approved' ? it.target : '', due_date: it.due_date, end_date: it.end_date,
      seat_id: it.seat_id, backup_seat_id: it.backup_seat_id, checker_seat_id: it.checker_seat_id, progress_every: it.progress_every, buy_amount: it.buy_amount, vendor: it.vendor, waiting_on: it.waiting_on, link_emp: it.link_emp, dom: it.dom } : {});
    const seats = P.seats.filter((s) => !/^p\./.test(s.code) && s.kind !== 'section_boss');
    const ownerOpts = (sel, none) => '<option value="">' + none + '</option><optgroup label="People">' + P.people.map((p) => '<option value="e:' + p.id + '"' + (sel === 'e:' + p.id ? ' selected' : '') + '>' + esc(p.name) + '</option>').join('') + '</optgroup>'
      + '<optgroup label="Team seats (the first free person does it)">' + seats.map((s) => '<option value="s:' + s.id + '"' + (sel === 's:' + s.id ? ' selected' : '') + '>' + esc(s.title) + (s.holders.length ? ' — ' + s.holders.map((h) => h.name).join(', ') : ' — empty') + '</option>').join('') + '</optgroup>';
    const selOf = (seatId, empId) => { if (empId) return 'e:' + empId; if (!seatId) return ''; const s = P.seats.find((x) => x.id === seatId); if (s && /^p\./.test(s.code)) { const id = Number(s.code.slice(2)); return 'e:' + id; } return 's:' + seatId; };
    const DOWS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const dowSet = new Set(String(f.dow || '').split(',').filter(Boolean));
    const m = BD.modal('<h3>' + (isNew ? 'Add a task' : 'Edit task') + '</h3>'
      + (isNew ? '<div class="b2-claude"><div id="cdSug"></div><div class="bd-lb">Tell Claude in your own words</div><textarea class="bd-in" id="cdText" placeholder="Example: If a tracking ID is not given within 48 hours of the order, Hemil gets a penalty. Every day.">' + esc(o.text || '') + '</textarea>'
        + '<div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap"><button type="button" class="bd-btn pri sm" id="cdGo">Let Claude read it</button><span class="bd-sub" id="cdMsg">Claude fills the form and tells you what it understood. You say yes, then press Add.</span></div><div id="cdSum"></div><div id="cdChecks"></div></div>' : '')
      + '<div class="bd-f"><label>What kind of task is this?</label><select class="bd-in" id="fKind">' + Object.keys(KINDS).map((k) => '<option value="' + k + '"' + (f.kind === k ? ' selected' : '') + '>' + KINDS[k][0] + ' — ' + KINDS[k][1] + '</option>').join('') + '</select></div>'
      + '<div class="bd-f"><label>Name of the task</label><input class="bd-in" id="fTitle" maxlength="200" value="' + esc(f.title) + '" placeholder="What must be done, in a few words"></div>'
      + '<div class="bd-f"><label>Why it matters <span class="bd-sub">(optional, the person sees this)</span></label><input class="bd-in" id="fWhy" maxlength="400" value="' + esc(f.why || '') + '"></div>'
      + '<div class="bd-2"><div class="bd-f"><label>Section</label><select class="bd-in" id="fSec"><option value="">General</option>' + S.map((s) => '<option value="' + esc(s.code) + '"' + (f.section_code === s.code ? ' selected' : '') + '>' + esc(s.name) + '</option>').join('') + '</select></div>'
      + '<div class="bd-f"><label>Who owns it?</label><select class="bd-in" id="fOwner">' + ownerOpts(selOf(f.seat_id, f.emp_id), 'Choose…') + '</select></div></div>'
      + '<div id="fWhen"></div>'
      + '<div class="bd-2"><div class="bd-f"><label>Proof</label><select class="bd-in" id="fProof">' + ['tick', 'photo', 'text', 'number', 'screenshot'].concat(f.proof === 'auto' ? ['auto'] : []).map((p) => '<option value="' + p + '"' + (f.proof === p ? ' selected' : '') + '>' + PROOFS[p] + '</option>').join('') + '</select></div>'
      + '<div class="bd-f"><label>Backup <span class="bd-sub">(covers when the owner is away)</span></label><select class="bd-in" id="fBackup">' + ownerOpts(selOf(f.backup_seat_id, f.backup_emp_id), 'No backup') + '</select></div></div>'
      + '<div class="bd-2"><div class="bd-f"><label>Checked by <span class="bd-sub">(optional)</span></label><select class="bd-in" id="fChecker">' + ownerOpts(selOf(f.checker_seat_id, f.checker_emp_id), 'Nobody') + '</select></div>'
      + '<div class="bd-f"><label>If missed, ask me about a penalty</label>'
      + '<div class="b2-pseg" id="fPenSeg" hidden><button type="button" data-pm="flat">One amount</button><button type="button" data-pm="unit">Per item</button></div>'
      + '<div id="fPenFlat"><label class="bd-sub" for="fPen">₹</label><input class="bd-in" id="fPen" type="number" min="0" max="500" step="10" value="' + esc(f.pen_amount || 0) + '"></div>'
      + '<div id="fPenUnit" hidden><div class="b2-pu"><div><label class="bd-sub" for="fPenU">₹ for each item not done</label><input class="bd-in" id="fPenU" type="number" min="1" max="500" step="1" value="' + esc(f.pen_mode === 'unit' ? f.pen_unit : '') + '"></div>'
      + '<div><label class="bd-sub" for="fPenMin">Least ₹ if missed</label><input class="bd-in" id="fPenMin" type="number" min="0" max="2000" step="10" value="' + esc(f.pen_min || 0) + '"></div>'
      + '<div><label class="bd-sub" for="fPenMax">Never more than ₹ a day</label><input class="bd-in" id="fPenMax" type="number" min="1" max="2000" step="10" value="' + esc(f.pen_max || 500) + '"></div></div></div>'
      + '<div class="hint" id="fPenHint">Never automatic. You are asked first. Free for the first 2 weeks.</div><div class="b2-pex" id="fPenEx" hidden></div></div></div>'
      + '<div class="acts"><button class="bd-btn" id="fNo">Cancel</button>' + (isNew ? '<button class="bd-btn" id="fDraft">Save as draft</button>' : '') + '<button class="bd-btn go" id="fOk">' + (isNew ? 'Add' : 'Save') + '</button></div>');
    const q = (s) => m.querySelector(s);
    const whenHtml = () => {
      const k = q('#fKind').value; let h = '';
      if (['daily', 'number', 'temp'].indexOf(k) >= 0) {
        h += '<div class="bd-f"><label>Which days?</label><div class="bd-days">' + DOWS.map((d, i) => '<label><input type="checkbox" class="fDow" value="' + (i + 1) + '"' + (dowSet.has(String(i + 1)) ? ' checked' : '') + '><span>' + d + '</span></label>').join('') + '</div><div class="hint">Sunday is off unless you tick it.</div></div>';
        const isAuto = (q('#fProof') ? q('#fProof').value : f.proof) === 'auto';
        h += '<div class="bd-2"><div class="bd-f"><label>' + (isAuto ? 'Checked at' : 'Must be done by') + '</label><input class="bd-in" id="fTime" type="time" value="' + esc(f.due_time || '19:00') + '">' + (isAuto ? '<div class="hint">The system looks at the number at this time. The owner has nothing to tick.</div>' : '') + '</div>';
        if (k === 'temp') h += '<div class="bd-f"><label>For how many days?</label><input class="bd-in" id="fDays" type="number" min="1" max="60" value="' + esc(f.days || (f.end_date ? '' : 10)) + '"></div>'; else h += '<div></div>';
        h += '</div>';
      } else {
        h += '<div class="bd-2"><div class="bd-f"><label>Deadline</label><input class="bd-in" id="fDate" type="date" value="' + esc(f.due_date || '') + '"></div><div class="bd-f"><label>Time</label><input class="bd-in" id="fTime" type="time" value="' + esc(f.due_time || '19:00') + '"></div></div>';
      }
      if (k === 'number') h += '<div class="bd-2"><div class="bd-f"><label>Unit</label><input class="bd-in" id="fUnit" maxlength="20" value="' + esc(f.unit || '') + '" placeholder="parcels, followers, calls"></div><div class="bd-f"><label>Target <span class="bd-sub">(leave empty: Claude proposes it after 14 days)</span></label><div style="display:flex;gap:6px"><select class="bd-in" id="fCmp" style="max-width:120px"><option value="min"' + (f.cmp !== 'max' ? ' selected' : '') + '>At least</option><option value="max"' + (f.cmp === 'max' ? ' selected' : '') + '>At most</option></select><input class="bd-in" id="fTarget" type="number" min="0" value="' + esc(f.target != null ? f.target : '') + '"></div></div></div>';
      if (k === 'buy') h += '<div class="bd-2"><div class="bd-f"><label>Budget ₹</label><input class="bd-in" id="fBuy" type="number" min="0" value="' + esc(f.buy_amount || '') + '"></div><div class="bd-f"><label>From whom</label><input class="bd-in" id="fVendor" maxlength="120" value="' + esc(f.vendor || '') + '"></div></div>';
      if (k === 'followup') h += '<div class="bd-f"><label>Waiting on <span class="bd-sub">(who or what)</span></label><input class="bd-in" id="fWait" maxlength="160" value="' + esc(f.waiting_on || '') + '"></div>';
      if (k === 'improve') h += '<div class="bd-f"><label>Person to help</label><select class="bd-in" id="fLink"><option value="">Choose…</option>' + P.people.map((p) => '<option value="' + p.id + '"' + (Number(f.link_emp) === p.id ? ' selected' : '') + '>' + esc(p.name) + '</option>').join('') + '</select><div class="hint">It watches their days and closes by itself after 7 good days in a row.</div></div>';
      if (['fix', 'followup', 'improve'].indexOf(k) >= 0) h += '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:10px"><input type="checkbox" id="fProg"' + (f.progress_every ? ' checked' : '') + '> Ask for a one-line progress note every day</label>';
      q('#fWhen').innerHTML = h;
    };
    whenHtml(); q('#fKind').onchange = () => { f.dow = Array.from(m.querySelectorAll('.fDow:checked')).map((x) => x.value).join(',') || f.dow; whenHtml(); };
    const val = (s) => { const e = q(s); return e ? e.value : ''; };
    /* penalty: one amount, or per item (only for a number task) */
    const penMode = { v: f.pen_mode === 'unit' ? 'unit' : 'flat' };
    const penUi = () => {
      const k = q('#fKind') ? q('#fKind').value : f.kind, p = q('#fProof') ? q('#fProof').value : f.proof, ok = k === 'number' || p === 'auto';
      if (!ok) penMode.v = 'flat';
      q('#fPenSeg').hidden = false;
      q('#fPenSeg').querySelectorAll('button').forEach((b) => { b.classList.toggle('on', b.dataset.pm === penMode.v); b.classList.toggle('dim', b.dataset.pm === 'unit' && !ok); });
      q('#fPenFlat').hidden = penMode.v === 'unit'; q('#fPenUnit').hidden = penMode.v !== 'unit';
      const ex = q('#fPenEx');
      if (penMode.v === 'unit') {
        const r = Number(val('#fPenU') || 0), mn = Number(val('#fPenMin') || 0), mx = Number(val('#fPenMax') || 500) || 500;
        const calc = (n) => { const raw = n * r, a = Math.max(mn, Math.min(mx, raw)); return inr(a) + (raw > mx ? ' (capped at ' + inr(mx) + ')' : (raw < mn ? ' (the least)' : '')); };
        ex.hidden = false; ex.innerHTML = r > 0 ? '<b>Example:</b> 4 items not done × ' + inr(r) + ' = ' + calc(4) + '. 20 not done = ' + calc(20) + '.' : 'Enter the rupees for each item not done.';
        q('#fPenHint').textContent = 'Counted from the number this task judges. Only items above the starting backlog are counted. Never automatic. You are asked first. Free for the first 2 weeks.';
      } else { ex.hidden = true; q('#fPenHint').textContent = ok ? 'Never automatic. You are asked first. Free for the first 2 weeks. Up to ₹500.' : 'Never automatic. You are asked first. Free for the first 2 weeks. Up to ₹500. A penalty per item needs a number to count: tap “Per item” and this becomes a Number task (for example 20 reviews a day).'; }
    };
    q('#fPenSeg').querySelectorAll('button').forEach((b) => { b.onclick = () => { if (b.dataset.pm === 'unit') { const k = q('#fKind'), p = q('#fProof'); if (k && k.value !== 'number' && !(p && p.value === 'auto') && [].some.call(k.options, (o) => o.value === 'number')) { k.value = 'number'; k.dispatchEvent(new Event('change', { bubbles: true })); if (p && p.value === 'tick' && [].some.call(p.options, (o) => o.value === 'number')) { p.value = 'number'; p.dispatchEvent(new Event('change', { bubbles: true })); } } } penMode.v = b.dataset.pm; penUi(); }; });
    ['#fPenU', '#fPenMin', '#fPenMax'].forEach((s) => q(s).addEventListener('input', penUi));
    ['#fKind', '#fProof'].forEach((s) => { if (q(s)) q(s).addEventListener('change', penUi); });
    penUi();
    const fromSel = (v, seatKey, empKey, out) => { if (!v) return; if (v[0] === 'e') out[empKey] = Number(v.slice(2)); else out[seatKey] = Number(v.slice(2)); };
    const collect = () => {
      const out = { kind: val('#fKind'), title: val('#fTitle').trim(), why: val('#fWhy').trim(), section_code: val('#fSec'), proof: val('#fProof'), pen_amount: Number(val('#fPen') || 0), due_time: val('#fTime') || '19:00' };
      fromSel(val('#fOwner'), 'seat_id', 'emp_id', out); fromSel(val('#fBackup'), 'backup_seat_id', 'backup_emp_id', out); fromSel(val('#fChecker'), 'checker_seat_id', 'checker_emp_id', out);
      if (penMode.v === 'unit') { out.pen_mode = 'unit'; out.pen_unit = Number(val('#fPenU') || 0); out.pen_min = Number(val('#fPenMin') || 0); out.pen_max = Number(val('#fPenMax') || 500); out.pen_amount = out.pen_unit; } else out.pen_mode = 'flat';
      if (q('.fDow')) out.dow = Array.from(m.querySelectorAll('.fDow:checked')).map((x) => x.value).join(',');
      if (q('#fDate')) out.due_date = val('#fDate');
      if (q('#fDays')) out.days = Number(val('#fDays') || 0);
      if (q('#fUnit')) { out.unit = val('#fUnit'); out.cmp = val('#fCmp'); out.target = val('#fTarget') === '' ? null : Number(val('#fTarget')); if (out.target === null) delete out.target; out.proof = 'number'; }
      if (q('#fBuy')) { out.buy_amount = Number(val('#fBuy') || 0); out.vendor = val('#fVendor'); }
      if (q('#fWait')) out.waiting_on = val('#fWait');
      if (q('#fLink')) out.link_emp = Number(val('#fLink') || 0);
      if (q('#fProg')) out.progress_every = q('#fProg').checked ? 1 : 0;
      if (val('#fProof') === 'auto' && f.adapter) { out.adapter = f.adapter; out.cmp = f.cmp || 'max'; out.target = f.target == null ? 0 : f.target; out.unit = f.unit || ''; }
      else if (isNew && val('#fProof') === 'auto') out.proof = 'tick';                     // "system checks it" only exists together with a system number
      else if (f.no_auto) out.no_auto = 1;
      if (f.pen_set) out.pen_set = 1;
      if (!isNew) out.id = it.id; else if (o.prefill && o.prefill.problem_id) out.problem_id = o.prefill.problem_id;
      if (o.via_claude) out.via_claude = 1;
      if (isNew && o.chain) out.chain = o.chain;                     // the Boss Engine asked about this step of a section chain
      return out;
    };
    q('#fNo').onclick = BD.close;
    let guard = () => true;
    const save = async (draft) => {
      if (!draft && !guard()) return;
      const b = collect(); if (draft) b.draft = 1;
      const btn = q('#fOk'); btn.disabled = true;
      try { const r = await post('bd.b2_item_save', b); BD.close(); toast(r.message || 'Saved.'); reset(); if (o.onSaved) o.onSaved(r); }
      catch (e) { fail(e); btn.disabled = false; }
    };
    q('#fOk').onclick = () => save(false); if (q('#fDraft')) q('#fDraft').onclick = () => save(true);
    if (isNew && q('#cdGo')) {
      /* Claude reads, says what it understood, the manager says "yes" or "not quite - I meant…" until both agree; only then can the task be added */
      const S8 = { original: '', last: null, round: 0, confirmed: false, dirty: false, r: null, hint: '' };
      const pageKey = o.page || '';
      const sayMsg = (t) => { q('#cdMsg').textContent = t; };
      const ensureAuto = () => { const ps = q('#fProof'); if (ps && !ps.querySelector('option[value="auto"]')) { const op = document.createElement('option'); op.value = 'auto'; op.textContent = PROOFS.auto; ps.appendChild(op); } };
      const setForm = (d) => {
        if (d.kind) q('#fKind').value = d.kind;
        if (d.dow) { dowSet.clear(); String(d.dow).split(',').forEach((x) => dowSet.add(x)); }
        Object.assign(f, d); ensureAuto();
        if (d.proof) q('#fProof').value = d.proof; whenHtml();
        if (d.title) q('#fTitle').value = d.title; if (d.why !== undefined) q('#fWhy').value = d.why || ''; if (d.section_code !== undefined) q('#fSec').value = d.section_code || '';
        if (d.emp_id) q('#fOwner').value = 'e:' + d.emp_id;
        else if (d.seat_id) { const v = selOf(d.seat_id, 0); const so = q('#fOwner'); if (v) { if (!Array.from(so.options).some((x) => x.value === v)) { const sr = P.seats.find((x) => x.id === d.seat_id); const op = document.createElement('option'); op.value = v; op.textContent = sr ? sr.title : 'Team seat'; so.appendChild(op); } so.value = v; } }
        if (d.pen_amount != null) q('#fPen').value = d.pen_amount;
        if (d.pen_mode) { penMode.v = d.pen_mode === 'unit' ? 'unit' : 'flat'; if (d.pen_unit != null) q('#fPenU').value = d.pen_unit; if (d.pen_min != null) q('#fPenMin').value = d.pen_min; if (d.pen_max != null) q('#fPenMax').value = d.pen_max; }
        if (q('#fTime') && d.due_time) q('#fTime').value = d.due_time; if (q('#fDate') && d.due_date) q('#fDate').value = d.due_date;
        if (q('#fUnit') && d.unit) q('#fUnit').value = d.unit; if (q('#fTarget') && d.target) q('#fTarget').value = d.target; if (q('#fDays') && d.days) q('#fDays').value = d.days;
        penUi();
      };
      const prevFor = () => {
        const c = collect(), base = Object.assign({}, S8.last || {});
        if (c.emp_id || c.seat_id) { delete base.emp_id; delete base.seat_id; }
        if (c.proof !== 'auto') { delete base.adapter; if (S8.last && S8.last.adapter) base.no_auto = 1; }
        return Object.assign(base, c);
      };
      const renderSum = () => {
        const r = S8.r, host = q('#cdSum'); if (!r || !r.summary) { host.innerHTML = ''; return; }
        const s = r.summary; let h = '';
        const state = S8.dirty ? 'dirty' : (S8.confirmed ? 'ok' : '');
        h += '<div class="b2-sum ' + state + '"><div class="hd">' + (S8.dirty ? 'You changed the form after Claude read it' : (S8.confirmed ? '✓ We agree. Check the form and press Add.' : 'Claude understood this' + (s.round > 1 ? ' · reading ' + s.round : ''))) + '</div>';
        const st = r.ai_state || {}; h += '<div class="ai ' + (st.used ? 'on' : 'off') + '">' + (st.used ? '✦ Claude read this' : '⚠ ' + esc(st.why || 'Built-in word reader used')) + '</div>';
        if (s.understood && s.understood.length) h += '<div class="und"><b>What I understood</b><ul>' + s.understood.map((u) => '<li>' + esc(u) + '</li>').join('') + '</ul></div>';
        h += '<div class="say">' + esc(s.say) + '</div>';
        h += '<div class="rows">' + s.rows.map((x) => '<div><span>' + esc(x.k) + '</span><em>' + esc(x.v) + '</em></div>').join('') + '</div>';
        if (s.impact && s.impact.length) h += '<div class="imp"><b>What saving this changes</b><ul>' + s.impact.map((n) => '<li>' + esc(n) + '</li>').join('') + '</ul></div>';
        const nn = (s.notes || []).filter((n) => !(s.impact || []).includes(n)); if (nn.length) h += '<ul class="notes">' + nn.map((n) => '<li>' + esc(n) + '</li>').join('') + '</ul>';
        if (r.needs_system) h += '<div class="bd-call warn"><b>This number is not in the system yet.</b> ' + esc(r.needs_system.why) + '<div class="acts"><button type="button" class="bd-btn sm" id="nsManual">Tick by hand for now</button><button type="button" class="bd-btn sm pri" id="nsLater">Build this system later</button></div><div class="hint">“Build later” puts it on the Build system list. The task waits and starts when that system is live.</div></div>';
        if (S8.dirty) h += '<div class="acts"><button type="button" class="bd-btn pri sm" id="sumRe">Read my form again</button></div>';
        else if (!S8.confirmed) h += '<div class="acts" id="sumActs"><button type="button" class="bd-btn go sm" id="sumYes">Yes, that is what I mean</button><button type="button" class="bd-btn sm" id="sumNo">Not quite…</button></div>'
          + '<div id="sumFix" hidden><textarea class="bd-in" id="fixText" placeholder="What is different? Example: “No, make it Hemil, 6 PM, and add ₹100 penalty.”"></textarea><div class="acts"><button type="button" class="bd-btn pri sm" id="fixGo">Read it again</button></div></div>';
        else h += '<div class="acts"><button type="button" class="bd-btn sm" id="sumNo">Change something</button></div><div id="sumFix" hidden><textarea class="bd-in" id="fixText" placeholder="What is different?"></textarea><div class="acts"><button type="button" class="bd-btn pri sm" id="fixGo">Read it again</button></div></div>';
        h += '</div>';
        host.innerHTML = h;
        const on = (id, fn) => { const e = q(id); if (e) e.onclick = fn; };
        on('#sumYes', () => { S8.confirmed = true; renderSum(); sayMsg('Understood. Check the form and press Add.'); });
        on('#sumNo', () => { const b = q('#sumFix'); b.hidden = false; q('#fixText').focus(); });
        on('#fixGo', () => { const t = q('#fixText').value.trim(); if (t.length < 3) { sayMsg('Write what is different first.'); return; } run({ original: S8.original, correction: t, prev: prevFor(), round: S8.round }); });
        on('#sumRe', () => run({ original: S8.original, correction: 'Same as the form I filled. Say it back to me.', prev: prevFor(), round: S8.round }));
        on('#nsManual', () => run({ original: S8.original, correction: 'I will tick it myself, not from the system', prev: prevFor(), round: S8.round }));
        on('#nsLater', async () => {
          const b = collect(); b.original = S8.original; b.need = (r.needs_system && r.needs_system.why) || '';
          try { const x = await post('bd.b2_draft_later', b); BD.close(); toast(x.message || 'Added to the Build system list.'); reset(); if (o.onSaved) o.onSaved(x); } catch (e) { fail(e); }
        });
      };
      const renderChecks = (r) => {
        q('#cdChecks').innerHTML = '<details class="b2-more"><summary>The 7 checks</summary><div class="b2-checks">' + (r.checks || []).map((c) => '<div><b class="' + (c.ok ? '' : 'no') + '">' + (c.ok ? '✓' : '!') + '</b><span><em>' + esc(c.name) + ':</em> ' + esc(c.text) + '</span></div>').join('') + '</div></details>'
          + ((r.overlaps || []).length ? '<div class="bd-call warn">Something similar already exists: ' + r.overlaps.map((x) => '“' + esc(x.title) + '”').join(', ') + '</div>' : '');
      };
      const renderAsk = (r) => {
        const host = q('#cdSum'); const Q = r.questions || [];
        host.innerHTML = '<div class="b2-q"><div class="hd">Claude has ' + (Q.length === 1 ? 'a quick question' : Q.length + ' quick questions') + ' first</div>'
          + Q.map((x) => '<div class="qq" data-id="' + esc(x.id) + '"><div class="qt">' + esc(x.text) + '</div><div class="opts">' + x.options.map((op) => '<button type="button" class="opt" data-v="' + esc(op.v) + '">' + esc(op.l) + '</button>').join('') + '</div></div>').join('')
          + '<div class="acts"><button type="button" class="bd-btn pri sm" id="qGo">Continue</button><button type="button" class="bd-btn sm" id="qSkip">Skip, just show what you understood</button></div><div class="hint">Anything you do not answer stays as Claude guessed it. You can still say “Not quite” afterwards.</div></div>';
        host.querySelectorAll('.opt').forEach((b) => { b.onclick = () => { b.parentNode.querySelectorAll('.opt').forEach((x) => x.classList.remove('on')); b.classList.add('on'); }; });
        q('#qGo').onclick = () => { const a = {}; host.querySelectorAll('.qq').forEach((qq) => { const on = qq.querySelector('.opt.on'); if (on) a[qq.dataset.id] = on.dataset.v; }); run({ original: S8.original, prev: S8.last, answers: a, round: 1 }); };
        q('#qSkip').onclick = () => { S8.asking = false; S8.r = Object.assign({}, r, { questions: [] }); renderSum(); renderChecks(r); sayMsg('Read it. Is this what you mean?'); };
      };
      let busy = false;
      const run = async (extra) => {
        if (busy) return; busy = true; const btn = q('#cdGo'); btn.disabled = true; sayMsg('Claude is reading…');
        try {
          const r = await post('bd.b2_draft', Object.assign({ text: S8.original, section_code: val('#fSec') || o.section || '', page: pageKey, title_hint: S8.hint }, extra));
          S8.r = r; S8.last = r.draft || {}; S8.round = r.round || 1; S8.confirmed = false; S8.dirty = false; S8.asking = false; o.via_claude = true;
          setForm(r.draft || {});
          if (!extra.answers && !extra.prev && (r.questions || []).length) { S8.asking = true; renderAsk(r); q('#cdChecks').innerHTML = ''; sayMsg('Answer Claude’s questions, or skip them.'); }
          else { renderSum(); renderChecks(r); sayMsg(r.summary ? 'Read it. Is this what you mean?' : (r.checks && r.checks[0] ? r.checks[0].text : 'Could not read that.')); }
          const sm = q('#cdSum'); if (sm && sm.scrollIntoView) sm.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e) { sayMsg(e.message || 'Could not read that.'); }
        busy = false; btn.disabled = false;
      };
      const first = (hint) => {
        const text = q('#cdText').value.trim(); if (text.length < 6) { sayMsg('Write a little more first.'); return; }
        S8.original = text; S8.hint = hint || ''; S8.last = null; S8.round = 0; run({ original: text, round: 1, ask: 1 });
      };
      q('#cdGo').onclick = () => first('');
      guard = () => {
        if (!o.via_claude) return true;
        if (S8.asking) { sayMsg('Answer Claude’s questions first, or press “Skip”.'); return false; }
        if (S8.dirty) { sayMsg('You changed the form after Claude read it. Press “Read my form again”.'); return false; }
        if (!S8.confirmed) { sayMsg('Press “Yes, that is what I mean” first, so Claude and you agree.'); const sm = q('#cdSum'); if (sm) sm.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); return false; }
        return true;
      };
      const touch = (ev) => { const id = ev.target && ev.target.id; if (id === 'cdText' || id === 'fixText' || !S8.r || S8.asking || S8.dirty || !o.via_claude) return; S8.dirty = true; S8.confirmed = false; renderSum(); };
      m.addEventListener('input', touch); m.addEventListener('change', touch);
      /* "Claude read this page": things that should become tasks */
      (async () => {
        const host = q('#cdSug'); if (!host) return;
        try {
          const r = await get('bd.b2_suggest', { section_code: o.section || '', page: pageKey }); const L = r.suggestions || []; if (!L.length) return;
          host.innerHTML = '<div class="b2-sug"><div class="bd-lb">Claude read ' + (r.section ? esc(r.section) : 'this page') + ' — these could be tasks</div>'
            + L.map((x, i) => '<button type="button" class="sg" data-i="' + i + '"><span class="tg ' + esc(x.tag) + '">' + esc(x.tag_label) + '</span><span class="tt">' + esc(x.title) + '</span><span class="rs">' + esc(x.reason) + '</span><span class="go">' + esc(x.kind_label) + ' · Use this</span></button>').join('')
            + '</div><div class="bd-sub" style="margin:6px 0 2px">Or write your own below.</div>';
          host.querySelectorAll('.sg').forEach((b) => { b.onclick = () => { const x = L[Number(b.dataset.i)]; q('#cdText').value = x.text; if (x.section_code && !val('#fSec')) q('#fSec').value = x.section_code; S8.original = x.text; S8.hint = x.title_hint || ''; S8.last = null; S8.round = 0; run({ original: x.text, round: 1, ask: 1 }); }; });
        } catch (e) { /* suggestions are a bonus; the box below always works */ }
      })();
    }
  }

  /* ---------- a decision card ("Needs you") ---------- */
  function needHtml(n) {
    const tone = { penalty: 'warn', proof: 'info', problem: 'warn', draft: 'info', target: 'info', alert: 'bad', build: 'info', doc: 'warn' }[n.type] || '';
    const label = { penalty: 'Penalty', proof: 'Look at the proof', problem: 'Problem', draft: 'Approve', target: 'Target', alert: 'Hole found', build: 'Build', doc: 'Document' }[n.type] || n.type;
    return '<div class="b2-need" data-key="' + esc(n.key) + '"><div class="top"><div style="min-width:0;flex:1 1 260px"><div class="t">' + esc(n.title) + '</div><div class="s">' + esc(n.sub || '') + (n.section ? ' · ' + esc(n.section) : '') + (n.ago ? ' · ' + esc(n.ago) + ' ago' : '') + '</div></div><span class="bd-chip ' + tone + '">' + label + '</span></div>'
      + '<div class="acts">' + (n.actions || []).map((a) => '<button type="button" class="bd-btn sm ' + (a.style === 'bad' ? 'bad' : (a.style === 'go' ? 'go' : (a.style === 'pri' ? 'pri' : ''))) + '" data-act="' + esc(a.k) + '">' + esc(a.label) + '</button>').join('') + (n.type === 'problem' ? '<button type="button" class="bd-btn sm" data-act="_open">Open</button>' : '') + (n.type === 'draft' || n.type === 'target' ? '<button type="button" class="bd-btn sm" data-act="_item">See task</button>' : '') + '</div></div>';
  }
  async function needAct(n, act, after) {
    if (act === '_open' || (act === 'open' && n.type === 'problem')) return openProblem(n.id, after);
    if (act === 'open' && n.type === 'draft') return openItem(n.id, after);
    if (act === 'open' && n.type === 'doc') { location.hash = '#docs'; return; }
    if (act === 'fixseat') { location.hash = '#people'; return; }
    if (act === '_item') return openItem(n.item_id || n.id, after);
    const body = { type: n.type, id: n.id, act };
    if (n.type === 'penalty' && act !== 'charge') {
      const note = await ask('Note (optional)', act === 'waive' ? 'Why is it waived? The person can see this.' : act === 'call' ? 'What will you talk about?' : act === 'fix' ? 'What is the cause you will fix?' : 'Who should take over the work?', true);
      if (note === null) return; body.note = note;
    }
    if (n.type === 'penalty' && act === 'charge' && !(await BD.confirm('Charge ' + inr(n.amount) + '?', n.title + '. This becomes a real penalty. The person can see it and the reason.', 'Charge', true))) return;
    if (n.type === 'proof' && act === 'reject') { const note = await ask('Why is it not accepted?', 'The person sees this and does it again.', true); if (note === null) return; body.note = note; }
    if (n.type === 'target' && act === 'change') { const v = await ask('Target', 'Type the target number per day.', false, 'number'); if (v === null || v === '') return; body.value = Number(v); }
    try { const r = await post('bd.b2_need_act', body); toast(r.message || 'Done.'); reset(); if (after) after(); } catch (e) { fail(e); }
  }
  function ask(title, hint, optional, type) {
    return new Promise((res) => {
      const m = BD.modal('<h3>' + esc(title) + '</h3><div class="bd-f"><input class="bd-in" id="aqIn" type="' + (type || 'text') + '" placeholder="' + esc(hint || '') + '"><div class="hint">' + esc(hint || '') + '</div></div><div class="acts"><button class="bd-btn" id="aqNo">Cancel</button><button class="bd-btn pri" id="aqOk">OK</button></div>', { sticky: true });
      const i = m.querySelector('#aqIn'); i.focus();
      m.querySelector('#aqNo').onclick = () => { BD.close(); res(null); };
      const ok = () => { const v = i.value.trim(); if (!v && !optional) { i.focus(); return; } BD.close(); res(v); };
      m.querySelector('#aqOk').onclick = ok; i.onkeydown = (e) => { if (e.key === 'Enter') ok(); };
    });
  }

  /* ---------- problems ---------- */
  function stepBar(step) { const i = STEP.indexOf(step); return '<div class="b2-steps">' + STEP.map((s, k) => '<span class="' + (k <= i ? 'on' : '') + '"></span>').join('') + '</div>'; }
  async function openProblem(id, onChange) {
    let p; try { p = await get('bd.b2_problem', { id }); } catch (e) { return fail(e); }
    const c = p.cause || {};
    const m = BD.modal('<h3>' + esc(p.title) + '</h3><div style="display:flex;gap:6px;flex-wrap:wrap"><span class="bd-chip dark">' + esc(p.step_label) + '</span>' + (p.section ? '<span class="bd-chip info">' + esc(p.section) + '</span>' : '') + (p.again_n ? '<span class="bd-chip bad">Came back ' + p.again_n + '×</span>' : '') + '<span class="bd-chip">' + esc(p.by || '') + ' · ' + esc(p.ago || '') + ' ago</span></div>' + stepBar(p.step)
      + (p.body && p.body !== p.title ? '<p style="font-size:13.5px;line-height:1.5;white-space:pre-wrap;margin:8px 0">' + esc(p.body) + '</p>' : '')
      + (c.text ? '<div class="b2-cause ' + esc(c.level) + '"><b>' + esc(c.label || c.level) + ' cause</b>' + esc(c.text) + '</div>' : '')
      + ((p.evidence || []).length ? '<div class="bd-lb" style="margin-top:8px">What the records show</div>' + p.evidence.map((e) => '<div class="b2-ev"><i>' + (IC[e.t] || '•') + '</i><div>' + esc(e.text) + '</div></div>').join('') : '')
      + (p.step === 'decide' ? '<div class="bd-lb" style="margin-top:14px">Choose what to do</div>' + (p.options || []).map((o) => '<div class="b2-opt"><div style="flex:1 1 220px"><div class="t">' + esc(o.label) + '</div><div class="s">' + esc(o.why || '') + (o.draft && o.draft.seat ? ' · owner: ' + esc(o.draft.seat) : '') + '</div></div><button class="bd-btn sm pri" data-pick="' + esc(o.id) + '">' + (o.route === 'rule' ? 'Make the rule' : o.route === 'build' ? 'Add to build list' : o.route === 'closed' ? 'Close it' : 'Do this') + '</button></div>').join('') : '')
      + (p.step === 'watch' || p.step === 'work' ? '<div class="bd-call info">' + (p.step === 'watch' ? 'Being watched. It closes by itself after ' + p.watch_days + ' days without the problem' + (p.days_clean ? ' (' + p.days_clean + ' clean so far)' : '') + '.' : 'Work is in progress.') + (p.route_label ? ' Route: ' + esc(p.route_label) + '.' : '') + '</div>' : '')
      + (p.closed_reason ? '<div class="bd-call ok">' + esc(p.closed_reason) + '</div>' : '')
      + '<div class="acts"><button class="bd-btn" id="pbClose">Close window</button>' + (p.step !== 'closed' ? '<button class="bd-btn bad" id="pbDrop">Not a problem</button>' : '') + '</div>');
    const done = (msg) => { BD.close(); if (msg) toast(msg); reset(); if (onChange) onChange(); };
    m.querySelector('#pbClose').onclick = BD.close;
    const drop = m.querySelector('#pbDrop'); if (drop) drop.onclick = async () => { const why = await ask('Why close it?', 'The person who reported it sees this.', true); if (why === null) return; try { await post('bd.b2_problem_close', { id, reason: why || 'Closed by the boss.' }); done('Closed.'); } catch (e) { fail(e); } };
    m.querySelectorAll('[data-pick]').forEach((b) => b.onclick = async () => {
      const opt = (p.options || []).find((x) => x.id === b.dataset.pick); if (!opt) return;
      if (opt.draft && ['daily', 'number', 'short', 'temp', 'followup', 'fix', 'buy', 'improve'].indexOf(opt.route) >= 0) {
        BD.close(); return editItem({ prefill: Object.assign({}, opt.draft, { problem_id: id }), section: p.section_code, onSaved: async (r) => { try { await post('bd.b2_problem_pick', { id, option: opt.id, over: { item_id: r && r.id } }); } catch (e) { /* the task itself is saved */ } if (onChange) onChange(); } });
      }
      try { const r = await post('bd.b2_problem_pick', { id, option: opt.id }); done(r.message || 'Done.'); } catch (e) { fail(e); }
    });
  }

  /* ---------- "report a problem" (everyone) ---------- */
  function report(o) {
    o = o || {};
    const m = BD.modal('<h3>Report a problem</h3><p class="bd-sub" style="font-size:13.5px;line-height:1.5;margin:0 0 10px">Say what is wrong in your own words. Claude may ask one or two short questions, then your boss decides what to do.' + (o.sectionName ? ' (' + esc(o.sectionName) + ')' : '') + '</p>'
      + '<div class="bd-f"><textarea class="bd-in" id="rpText" placeholder="Example: The courier comes late every day and parcels leave after 6 PM."></textarea></div>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px"><input type="checkbox" id="rpUrg"> It is urgent</label>'
      + '<div id="rpQ"></div><div class="acts"><button class="bd-btn" id="rpNo">Cancel</button><button class="bd-btn go" id="rpOk">Send</button></div>');
    const q = (s) => m.querySelector(s); q('#rpNo').onclick = BD.close; q('#rpText').focus();
    q('#rpOk').onclick = async () => {
      const body = q('#rpText').value.trim(); if (body.length < 6) { toast('Please write a little more.', true); return; }
      const b = q('#rpOk'); b.disabled = true;
      try {
        const r = await post('bd.b2_report', { body, section_code: o.section || '', page: o.page || '', urgent: q('#rpUrg').checked ? 1 : 0 });
        if (r.questions && r.questions.length) {
          m.querySelector('h3').textContent = 'One quick question';
          q('#rpText').parentNode.style.display = 'none'; q('#rpUrg').parentNode.style.display = 'none';
          q('#rpQ').innerHTML = r.questions.map((x) => '<div class="bd-f"><label>' + esc(x.q) + '</label><input class="bd-in" data-q="' + esc(x.k) + '" placeholder="' + esc(x.why || '') + '">' + (x.chips ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">' + x.chips.map((c) => '<button type="button" class="bd-btn sm" data-chip="' + esc(c) + '">' + esc(c) + '</button>').join('') + '</div>' : '') + '</div>').join('');
          q('#rpQ').querySelectorAll('[data-chip]').forEach((c) => c.onclick = () => { const i = c.closest('.bd-f').querySelector('input'); i.value = c.dataset.chip; });
          b.textContent = 'Send answers'; b.disabled = false;
          b.onclick = async () => {
            const answers = {}; q('#rpQ').querySelectorAll('[data-q]').forEach((i) => { answers[i.dataset.q] = i.value.trim(); });
            b.disabled = true; try { const r2 = await post('bd.b2_problem_answer', { id: r.id, answers }); BD.close(); toast(r2.message || 'Thank you.'); if (o.onSent) o.onSent(); } catch (e) { fail(e); b.disabled = false; }
          };
        } else { BD.close(); toast(r.message || 'Thank you. Your boss will decide.'); if (o.onSent) o.onSent(); }
      } catch (e) { fail(e); b.disabled = false; }
    };
  }

  /* ---------- "ask Claude" ---------- */
  function askClaude(o) {
    o = o || {};
    const m = BD.modal('<h3>Ask Claude' + (o.sectionName ? ' · ' + esc(o.sectionName) : '') + '</h3><div class="bd-f"><textarea class="bd-in" id="acQ" placeholder="Who is slipping this week? What is pending in dispatch? Is anything expiring?"></textarea></div><div id="acA"></div><div class="acts"><button class="bd-btn" id="acNo">Close</button><button class="bd-btn pri" id="acGo">Ask</button></div>');
    const q = (s) => m.querySelector(s); q('#acNo').onclick = BD.close; q('#acQ').focus();
    const go = async () => {
      const t = q('#acQ').value.trim(); if (t.length < 3) return; const b = q('#acGo'); b.disabled = true; q('#acA').innerHTML = '<div class="bd-sub">Claude is checking the records…</div>';
      try { const r = await post('bd.b2_ask', { q: t, section_code: o.section || '', page_text: o.pageText || '' }); q('#acA').innerHTML = '<div class="bd-call info" style="white-space:pre-wrap">' + esc(r.answer) + '</div>' + (r.ai ? '' : '<div class="bd-sub">Short answer from the records. Claude’s full answers need the API key in config.php.</div>'); }
      catch (e) { q('#acA').innerHTML = '<div class="bd-call bad">' + esc(e.message) + '</div>'; }
      b.disabled = false;
    };
    q('#acGo').onclick = go; q('#acQ').onkeydown = (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) go(); };
  }

  return { KINDS, ONCE, PROOFS, get, post, fail, strip, st, spark, numTile, itemRow, needHtml, needAct, ask, openItem, editItem, openProblem, report, askClaude, people, sections, reset, stepBar, IC };
})();
