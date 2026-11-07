/* ============================================================================
   b2_inbox.js — the old "Boss Inbox" decisions, shown inside Needs you (Boss Engine > Today).
   One inbox for the boss. The old page (boss_inbox.html) now only keeps the history.
   Needs: boss_common.js (BD) and api.js. Used by boss_engine.html.
   ========================================================================== */
window.B2I = (function () {
  const { esc, toast, inr } = BD;
  const QUIET = ['digest', 'note', 'changed', 'collision'];   // information only: grouped as notes in Needs you (one line, "Read & clear all"), kept in the history page
  let EMPS = [];

  /** the open decisions of the old inbox that need a yes or a no (digests and notes stay in the history) */
  async function load() {
    const d = await api.get('bd.inbox', { status: 'open' });
    const all = (d.items || []).filter((i) => i.status === 'open');
    const items = all.filter((i) => !QUIET.includes(i.kind));
    if (items.some((i) => i.kind === 'uncovered') && !EMPS.length) { try { EMPS = (await api.get('bd.org')).employees || []; } catch (e) { EMPS = []; } }
    return { items, mode: d.mode, quiet: all.length - items.length };
  }

  const kindLabel = (k) => (BD.KIND[k] || [k, ''])[0];
  const kindTone = (k) => (BD.KIND[k] || [k, ''])[1] || '';

  /** one decision, in the same look as every other Needs you card */
  function card(it, mode) {
    const t = it.extra && it.extra.task, p = it.payload || {};
    const A = (act, label, cls, extra) => '<button type="button" class="bd-btn sm ' + (cls || '') + '" data-oact="' + act + '" ' + (extra || '') + '>' + label + '</button>';
    let body = '', acts = '';
    const live = mode === 'live';
    const warn = !live ? '<div class="bd-call warn" style="margin-top:8px">The Boss Engine is not in Live mode, so approving will not take money yet.</div>' : '';
    if (it.kind === 'dp_punchout') {
      body = '<div class="bd-call info" style="margin-top:8px"><b>Jobs not done:</b> ' + esc((p.left || []).slice(0, 8).join('; ')) + (p.note ? '<br><b>Says:</b> ' + esc(p.note) : '') + '</div>';
      acts = A('approve', 'Allow punch out', 'go') + A('reject', 'No, finish the jobs', 'bad');
    } else if (it.kind === 'dp_pen') {
      body = warn; acts = A('approve', 'Give the penalty', 'bad') + A('reject', 'No penalty', '');
    } else if (it.kind === 'dp_appeal') {
      body = '<div class="bd-call info" style="margin-top:8px"><b>Reason given:</b> ' + esc(it.body) + '</div>';
      acts = A('approve', 'Accept appeal (cancel penalty)', 'go') + A('reject', 'Decline', '');
    } else if (it.kind === 'penalty' && t) {
      body = '<div class="bd-sub" style="margin-top:6px"><b>' + esc(t.owner) + '</b> · ' + esc(t.section) + ' · due ' + esc(t.due_label) + ' · remark: ' + esc(t.pen_remark || '') + '</div>' + warn;
      acts = A('approve', 'Give ' + inr(t.pen_amount) + ' penalty', 'bad') + A('reject', 'No penalty', '');
    } else if (it.kind === 'appeal' && t) {
      body = '<div class="bd-call info" style="margin-top:8px"><b>' + esc(t.owner) + ' says:</b> ' + esc(it.body) + '<br><span class="bd-sub">Penalty ' + inr(t.pen_amount) + ' for "' + esc(t.title) + '" · ' + esc(t.pen_remark || '') + '</span></div>';
      acts = A('approve', 'Accept appeal (cancel penalty)', 'go') + A('reject', 'Decline', '');
    } else if (it.kind === 'ai_review' && t) {
      body = '<div class="bd-sub" style="margin-top:6px"><b>' + esc(t.owner) + '</b> · ' + esc(t.section) + ' · what it must show: ' + esc(t.photo_prompt || t.title) + '</div>'
        + (t.ai_reason ? '<div class="bd-call warn" style="margin-top:8px"><b>AI said:</b> ' + esc(t.ai_reason) + '</div>' : '') + (t.has_photo ? '<img class="bd-ph" data-photo="' + t.id + '" alt="photo">' : '');
      acts = A('approve', 'Photo is fine', 'go') + A('reject', 'Send back — retake', 'bad');
    } else if (it.kind === 'uncovered' && t) {
      body = '<div class="bd-f" style="margin-top:8px"><label>Give it to</label><select class="bd-in" data-emp><option value="">Choose a person…</option>' + EMPS.map((e) => '<option value="' + e.id + '">' + esc(e.name) + (e.dept ? ' — ' + esc(e.dept) : '') + '</option>').join('') + '</select></div>';
      acts = A('assign', 'Give duty', 'pri');
    } else if (it.kind === 'new_section') {
      acts = '<a class="bd-btn sm pri" href="#sections">Tell me what this team does</a>' + A('dismiss', 'Not now', '');
    } else if (it.kind === 'new_joiner') {
      const m = it.extra && it.extra.match;
      if (m) {
        body = '<div style="margin-top:8px;display:flex;flex-direction:column;gap:8px">' + m.options.map((o) => '<div class="bd-row" style="border:1px solid var(--line);border-radius:16px;padding:9px 12px"><div class="l"><div class="t">' + esc(o.title) + '</div><div class="s">' + esc(o.section) + ' · ' + esc(o.why) + '</div></div><div class="r"><button type="button" class="bd-btn sm pri" data-join="' + o.seat_id + '">Seat here</button></div></div>').join('') + '</div>';
        if (m.suggest_new) body += '<div class="bd-call info" style="margin-top:8px"><b>New role?</b> ' + esc(m.suggest_new.why) + '<div style="margin-top:8px"><button type="button" class="bd-btn sm" data-newrole="' + m.suggest_new.section_id + '" data-title="' + esc(m.suggest_new.title) + '">Create role "' + esc(m.suggest_new.title) + '" and seat ' + esc(m.emp.name) + '</button></div></div>';
      }
      acts = A('dismiss', 'Later', '');
    } else if (it.kind === 'season') {
      body = '<div class="bd-list" style="margin-top:8px">' + (p.plan || []).map((x) => '<div class="bd-row" style="padding:7px 0"><div class="l"><div class="t" style="font-size:13.5px">' + esc(x.title) + '</div></div><div class="r"><span class="bd-chip">' + esc(x.date) + '</span><span class="bd-chip">' + esc(x.section) + '</span></div></div>').join('') + '</div>';
      acts = A('approve', 'Create these duties', 'go') + A('dismiss', 'Skip this festival', '');
    } else if (it.kind === 'collision') {
      acts = '<a class="bd-btn sm" href="#work">See duties</a>' + A('dismiss', 'Got it', '');
    } else if (it.kind === 'changed') {
      acts = (it.section_id ? '<a class="bd-btn sm pri" href="#sections">Update duties for this change</a>' : '') + A('dismiss', 'Nothing changes', '');
    } else if (it.kind === 'problem' && t) {
      body = '<div class="bd-sub" style="margin-top:6px">' + esc(t.owner) + ' · ' + esc(t.title) + '</div>'; acts = A('dismiss', 'Seen', 'pri');
    } else if (p.link && (it.kind === 'doc' || it.kind === 'handover' || it.kind === 'loop')) {
      const open = { doc: 'Open Doc Check', handover: 'Choose who takes over', loop: 'See what Claude suggests' }[it.kind];
      acts = '<a class="bd-btn sm pri" href="' + esc(p.link) + '">' + open + '</a>' + A('dismiss', 'Later', '');
    } else { acts = A('dismiss', 'OK', ''); }
    const sub = [it.body && !/^(dp_punchout|dp_appeal|appeal)$/.test(it.kind) ? esc(it.body) : '', it.section ? esc(it.section) : '', esc(String(it.at).slice(5, 16))].filter(Boolean).join(' · ');
    return '<div class="b2-need" data-old="' + it.id + '"><div class="top"><div style="min-width:0;flex:1 1 260px"><div class="t">' + esc(it.title) + '</div><div class="s">' + sub + '</div></div><span class="bd-chip ' + kindTone(it.kind) + '">' + esc(kindLabel(it.kind)) + '</span></div>'
      + body + '<div class="acts">' + acts + '</div></div>';
  }

  /** the photo of an AI-review card */
  function photos(root) {
    root.querySelectorAll('img[data-photo]').forEach(async (im) => {
      try { im.src = await BD.photo(im.dataset.photo); } catch (e) { im.replaceWith(Object.assign(document.createElement('div'), { className: 'bd-sub', textContent: 'Photo not available' })); }
    });
  }

  /** wire the buttons of the old cards inside root. after() runs when something was decided. */
  function bind(root, data, after) {
    photos(root);
    root.querySelectorAll('.b2-need[data-old]').forEach((el) => {
      const id = Number(el.dataset.old), it = data.items.find((x) => x.id === id); if (!it) return;
      const done = (r) => { toast((r && r.message) || 'Done.'); if (after) after(); };
      const fail = (e) => toast((e && e.message) || 'Something went wrong.', true);
      el.querySelectorAll('[data-oact]').forEach((b) => {
        b.onclick = async () => {
          const a = b.dataset.oact; let extra = {};
          if (a === 'assign') { const v = el.querySelector('[data-emp]').value; if (!v) return toast('Choose a person.', true); extra = { emp_id: Number(v) }; }
          if ((a === 'approve') && b.classList.contains('bad') && !(await BD.confirm('Give this penalty?', 'The employee is told and it goes on their salary sheet.', 'Give penalty', true))) return;
          b.disabled = true;
          try { done(await api.post('bd.inbox_act', Object.assign({ id: id, act: a === 'assign' ? 'approve' : a }, extra))); } catch (e) { fail(e); b.disabled = false; }
        };
      });
      el.querySelectorAll('[data-join]').forEach((b) => b.onclick = async () => { b.disabled = true; try { done(await api.post('bd.match_apply', { emp_id: it.payload.emp_id, seat_id: Number(b.dataset.join) })); } catch (e) { fail(e); b.disabled = false; } });
      el.querySelectorAll('[data-newrole]').forEach((b) => b.onclick = async () => { b.disabled = true; try { done(await api.post('bd.match_apply', { emp_id: it.payload.emp_id, new: { section_id: Number(b.dataset.newrole), title: b.dataset.title } })); } catch (e) { fail(e); b.disabled = false; } });
    });
  }

  return { load, card, bind };
})();
