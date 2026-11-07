/* Brain page (brain.html). Needs api.js and boss_common.js (BD). Talks to bd.br_* (brain.php).
   Tabs: Overview (the three shelves, who uses the brain, Claude's questions) · Shelves (every fact, move / delete) · Teach (tell Claude, it shows what it understood) ·
         Old notes (the older Teach-the-bot lines: replace / stop using) · Bot trial (customer bot: old reply next to the brain reply) · Safety (leak test). */
const BRN = (() => {
  'use strict';
  const { esc, toast, confirm, modal, close } = BD;
  const $ = (id) => document.getElementById(id);
  let ROOT = null, TAB = 'home', O = null, R = null, Q = null, SHELF = 'customer', TF = 'differ', TEACH = null, BUSY = false;

  const get = (a, p) => api.get('bd.br_' + a, p || {});
  const post = (a, b) => api.post('bd.br_' + a, b || {});
  const call = async (fn, okMsg) => { try { const r = await fn(); if (okMsg !== false && (okMsg || (r && r.message))) toast(okMsg || r.message); return r; } catch (e) { toast(e.message || 'Something went wrong.', true); return null; } };
  const SC = {
    company: { name: 'Company', who: 'Everyone may know', d: 'Plain facts about the business: address, timings, how we work. A fact can be marked team-only.' },
    customer: { name: 'Customer', who: 'Customers may be told', d: 'What customers may be told and how we talk to them. Used by every reply a customer reads.' },
    internal: { name: 'Internal', who: 'Staff only', d: 'Penalties, duties, who handles what, costs. Never put into anything a customer reads.' },
  };
  const when = (s) => { const m = /(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)/.exec(String(s || '')); if (!m) return ''; const M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return Number(m[3]) + ' ' + M[Number(m[2]) - 1] + ', ' + m[4] + ':' + m[5]; };
  const areaName = (a) => ((O && O.areas) || {})[a] || a || 'Everywhere';
  const scopeSel = (v, id) => '<select id="' + id + '">' + ['customer', 'company', 'internal'].map((s) => '<option value="' + s + '"' + (v === s ? ' selected' : '') + '>' + SC[s].name + ' — ' + SC[s].who + '</option>').join('') + '</select>';
  const areaSel = (v, id) => '<select id="' + id + '">' + Object.keys((O && O.areas) || { any: 'Everywhere' }).map((k) => '<option value="' + k + '"' + (v === k ? ' selected' : '') + '>' + esc(O.areas[k]) + '</option>').join('') + '</select>';

  /* ------------------------------------------------------------ shell */
  const TABS = [['home', 'Overview'], ['shelves', 'Shelves'], ['teach', 'Teach'], ['sug', 'Suggestions'], ['old', 'Old notes'], ['trial', 'Bot trial'], ['safety', 'Safety']];
  function shell() {
    ROOT.innerHTML = '<div class="bd-tabs" id="brTabs">' + TABS.map((t) => '<button data-t="' + t[0] + '" class="' + (TAB === t[0] ? 'on' : '') + '">' + t[1] + '</button>').join('') + '</div><div id="brBody"><div class="bd-empty">Loading…</div></div>';
    $('brTabs').onclick = (e) => { const b = e.target.closest('button[data-t]'); if (!b) return; TAB = b.dataset.t; shell(); draw(); };
  }
  async function mount(root) {
    ROOT = root; shell();
    try { O = await get('overview'); } catch (e) { $('brBody').innerHTML = '<div class="bd-empty"><b>Cannot open the Brain</b>' + esc(e.message) + '<br>Only the owner, Admin, HR and section bosses can open this page.</div>'; return; }
    await draw();
  }
  async function draw() {
    const b = $('brBody'); if (!b) return;
    try {
      if (TAB === 'home') await drawHome(b); else if (TAB === 'shelves') await drawShelves(b); else if (TAB === 'teach') drawTeach(b);
      else if (TAB === 'sug') await drawSug(b); else if (TAB === 'old') await drawOld(b); else if (TAB === 'trial') await drawTrial(b); else if (TAB === 'safety') drawSafety(b);
    } catch (e) { b.innerHTML = '<div class="bd-empty"><b>Something went wrong</b>' + esc(e.message || '') + '</div>'; }
  }
  const reloadOverview = async () => { try { O = await get('overview'); } catch (e) {} };

  /* ------------------------------------------------------------ suggestions from the nightly review (Claude Code reads the day's corrections; nothing changes until you approve) */
  const KIND = { note: 'New note', example: 'Example to learn from', bug: 'Something to fix in the HRMS' };
  async function drawSug(b) {
    const d = await api.get('ll.sug_list');
    let h = '<div class="bd-call"><b>Nightly review.</b> Every night Claude Code reads the day\'s answers and corrections (phone numbers hidden) and suggests what the bot should learn. Nothing changes until you tap Approve.' + (d.on ? '' : '<br><b>Switched off:</b> the secret key is not set up yet (LEARN_LOOP_KEY in config.php and the GitHub secrets).') + '</div>';
    if (!d.pending.length) h += '<div class="bd-empty"><b>No suggestions waiting</b>New ones appear here each morning.</div>';
    d.pending.forEach((s) => {
      h += '<div class="bd-card" data-id="' + s.id + '" style="margin:10px 0;padding:12px"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><b>' + esc(KIND[s.kind] || s.kind) + '</b>' + (s.kind === 'note' ? '<span class="bd-chip">' + esc(s.scope) + ' shelf</span>' : '') + '<span style="color:#6a7280;font-size:12px">' + esc(when(s.created_at)) + '</span></div>'
        + '<textarea class="br-ta" data-f="text" style="width:100%;margin-top:8px">' + esc(s.text) + '</textarea>'
        + (s.evidence ? '<div style="font-size:12.5px;color:#6a7280;margin-top:6px;white-space:pre-wrap">Why: ' + esc(s.evidence) + '</div>' : '')
        + '<div style="display:flex;gap:8px;margin-top:10px"><button class="bd-btn pri" data-a="approve">Approve</button><button class="bd-btn" data-a="reject">Reject</button></div></div>';
    });
    if (d.recent.length) h += '<h4 style="margin-top:18px">Decided recently</h4>' + d.recent.map((s) => '<div style="font-size:13px;padding:3px 0;color:#4b5563">' + (s.status === 'approved' ? '✓' : '✕') + ' ' + esc(String(s.text).slice(0, 110)) + '</div>').join('');
    b.innerHTML = h;
    b.onclick = async (e) => {
      const btn = e.target.closest('button[data-a]'); if (!btn || BUSY) return; const card = btn.closest('[data-id]');
      BUSY = true;
      try { const r = await call(() => api.post('ll.sug_act', { id: Number(card.dataset.id), act: btn.dataset.a, text: card.querySelector('[data-f=text]').value })); if (r) await drawSug(b); } finally { BUSY = false; }
    };
  }

  /* ------------------------------------------------------------ overview */
  async function drawHome(b) {
    [R, Q] = await Promise.all([get('route_get'), get('q_list').catch(() => null)]);
    const c = O.counts || {}, cfg = R.cfg, T = R.trial || {}, can = R.can_set;
    let h = '';
    if (!O.ai) h += '<div class="bd-call bad"><b>Claude is switched off.</b> There is no Anthropic key in config.php, so nothing below can run yet.</div>';
    h += '<div class="br-shelves">' + ['company', 'customer', 'internal'].map((s) => '<button class="br-shelf ' + s + '" data-shelf="' + s + '"><span class="who">' + SC[s].who + '</span><b class="n">' + (c[s] || 0) + '</b><span class="d"><b>' + SC[s].name + ' shelf.</b> ' + SC[s].d + '</span><span class="go">Open this shelf →</span></button>').join('') + '</div>';
    if (Q && Q.questions && Q.questions.length) {
      h += Q.questions.map((q) => '<div class="br-q" data-q="' + q.id + '"><h3>' + esc(q.q) + '</h3><p>' + esc(q.area_label) + (q.why ? ' · ' + esc(q.why) : '') + '</p><textarea placeholder="Your answer (Claude will remember it)"></textarea><div class="acts" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><button class="bd-btn pri sm" data-qa="' + q.id + '">Save answer</button><button class="bd-btn sm" data-qs="' + q.id + '">Skip</button></div></div>').join('');
    } else if (Q && Q.can_make) {
      h += '<div class="bd-card" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap"><div><b>Let Claude ask you something</b><div class="bd-sub">It asks at most 3 a day, and never what you already answered.</div></div><button class="bd-btn" id="brAsk">Ask me something</button></div>';
    }
    // switches
    const m = cfg.customer;
    h += '<div class="bd-card"><div class="bd-h"><h2>Who uses the brain</h2></div>'
      + '<div class="br-sw"><div class="l"><b>Staff screens</b><span>Boss Engine, Tracker, Offices, Store, WhatsApp tags, sales-call review and the rest of the staff-side Claude calls read the Internal shelf, so what you taught beats their old habits. Customers never see it.</span></div>'
      + '<div class="r"><div class="bd-seg" id="brStaff"><button data-v="1" class="' + (cfg.staff === '1' ? 'on' : '') + '">On</button><button data-v="0" class="' + (cfg.staff === '0' ? 'on' : '') + '">Off</button></div></div></div>'
      + '<div class="br-sw"><div class="l"><b>Customer replies (the WhatsApp bot)</b><span>The reply a customer reads. <b>Off</b> = the bot works exactly as before. <b>Trial</b> = customers still get the old reply; after it is sent, Claude also writes it the new way with the Customer shelf, and you compare both on the Bot trial tab. <b>Live</b> = customers get replies written with the brain.</span>'
      + '<div class="br-mode ' + m + '">' + (m === 'off' ? '<b>Off.</b> The bot does not use the brain.' : m === 'trial' ? '<b>Trial.</b> ' + (T.total || 0) + ' replies compared so far. Customers see no change.' : '<b>Live.</b> The bot writes with the Customer shelf. A reply that repeats a team-only rule is held back.') + '</div></div>'
      + '<div class="r"><div class="bd-seg" id="brCust"><button data-v="off" class="' + (m === 'off' ? 'on' : '') + '">Off</button><button data-v="trial" class="' + (m === 'trial' ? 'on' : '') + '">Trial</button><button data-v="live" class="' + (m === 'live' ? 'on' : '') + '">Live</button></div>'
      + (m === 'trial' ? '<label class="bd-sub" style="margin:0">Compare <select id="brSample" style="padding:6px 8px;border-radius:10px;border:1px solid var(--line)">' + [10, 25, 50, 100].map((n) => '<option value="' + n + '"' + (cfg.sample === n ? ' selected' : '') + '>' + n + '%</option>').join('') + '</select> of replies</label>' : '') + '</div></div>'
      + (can ? '' : '<div class="bd-sub" style="margin-top:8px">Only the owner or Admin can change these switches.</div>') + '</div>';
    // where it is used
    const grp = (cls, title) => { const L = R.sites.filter((x) => x.cls === cls); return '<div class="bd-lb" style="margin:10px 0 2px">' + title + '</div><div class="br-use">' + L.map((x) => '<div class="u"><div>' + esc(x.label) + '<small>' + esc(areaName(x.area)) + ' · ' + esc(x.file) + '</small></div><div class="cnt">' + x.today + ' <i>today · ' + x.week + ' this week</i></div></div>').join('') + '</div>'; };
    h += '<div class="bd-card"><div class="bd-h"><h2>Where Claude uses the brain</h2></div><div class="bd-sub">Every Claude call is placed here. A call that is not listed gets no private knowledge at all.</div>'
      + grp('internal', 'Staff side — reads the Internal shelf' + (cfg.staff === '1' ? '' : ' (switched off)')) + grp('customer', 'Customer side — reads only what customers may hear' + (cfg.customer === 'live' ? '' : ' (not live yet)'))
      + '<div class="bd-sub" style="margin-top:10px">' + (R.doors || []).map((d) => '<b>' + esc(d[0]) + ':</b> ' + esc(d[1])).join('<br>') + '</div></div>';
    b.innerHTML = h;
    b.querySelectorAll('[data-shelf]').forEach((x) => { x.onclick = () => { SHELF = x.dataset.shelf; TAB = 'shelves'; shell(); draw(); }; });
    const ask = $('brAsk'); if (ask) ask.onclick = async () => { ask.disabled = true; const r = await call(() => post('q_make', { manual: 1 })); if (r) draw(); else ask.disabled = false; };
    b.querySelectorAll('[data-qa]').forEach((x) => { x.onclick = async () => { const box = x.closest('[data-q]').querySelector('textarea'); if (!box.value.trim()) { toast('Write your answer first.', true); return; } x.disabled = true; const r = await call(() => post('q_answer', { id: x.dataset.qa, text: box.value })); if (r) { await reloadOverview(); draw(); } else x.disabled = false; }; });
    b.querySelectorAll('[data-qs]').forEach((x) => { x.onclick = async () => { const r = await call(() => post('q_skip', { id: x.dataset.qs }), 'Skipped.'); if (r) draw(); }; });
    $('brStaff').onclick = async (e) => { const v = e.target.closest('button'); if (!v) return; const r = await call(() => post('route_set', { staff: v.dataset.v })); if (r) draw(); };
    $('brCust').onclick = async (e) => {
      const v = e.target.closest('button'); if (!v || v.dataset.v === cfg.customer) return;
      if (v.dataset.v === 'live') {
        const warn = T.ready ? 'The trial looks clean. ' : 'The trial is not finished yet (' + (T.ok || 0) + ' replies compared; 30 are suggested) or it shows problems. ';
        if (!(await confirm('Customers get brain replies?', warn + 'From now on the WhatsApp bot writes with the Customer shelf. You can switch back to Trial or Off at any moment.', 'Go live', true))) return;
      }
      const r = await call(() => post('route_set', { customer: v.dataset.v }));
      if (r) draw(); else if (v.dataset.v === 'live') { TAB = 'safety'; shell(); draw(); }
    };
    const sm = $('brSample'); if (sm) sm.onchange = async () => { const r = await call(() => post('route_set', { sample: sm.value })); if (r) draw(); };
  }

  /* ------------------------------------------------------------ shelves */
  async function drawShelves(b) {
    b.innerHTML = '<div class="bd-seg" id="brSh" style="margin-top:0">' + ['company', 'customer', 'internal'].map((s) => '<button data-s="' + s + '" class="' + (SHELF === s ? 'on' : '') + '">' + SC[s].name + '</button>').join('') + '</div><div id="brShBody" style="margin-top:12px"><div class="bd-empty">Loading…</div></div>';
    $('brSh').onclick = (e) => { const x = e.target.closest('button'); if (!x) return; SHELF = x.dataset.s; drawShelves(b); };
    const r = await get('facts', { scope: SHELF }); const body = $('brShBody');
    let h = '<div class="bd-card"><div class="bd-h"><h2>' + SC[SHELF].name + ' shelf <span class="bd-chip ' + (SHELF === 'internal' ? 'warn' : SHELF === 'customer' ? 'ok' : 'info') + '">' + SC[SHELF].who + '</span></h2><button class="bd-btn sm" id="brTeachHere">+ Teach Claude</button></div><div class="bd-sub" style="margin:0 0 10px">' + SC[SHELF].d + '</div>';
    if (r.summary && r.summary.text) h += '<div class="br-sum">' + esc(r.summary.text) + '<small>Claude’s short version of this shelf · version ' + r.summary.version + ' · ' + esc(when(r.summary.at)) + '</small></div>';
    if (SHELF === 'internal') h += '<div style="margin:0 0 10px"><button class="bd-btn sm" id="brSum">Write the short version again</button></div>';
    h += r.facts.length ? r.facts.map((f) => '<div class="br-fact" data-f="' + f.id + '"><div class="t">' + esc(f.text) + '<div class="m"><span class="bd-chip">' + esc(areaName(f.area)) + '</span>' + (SHELF !== 'internal' ? (f.shareable ? '<span class="bd-chip ok">customers may be told</span>' : '<span class="bd-chip warn">team only</span>') : '') + (f.label ? '<span class="bd-chip info">' + esc(f.label) + '</span>' : '') + '<span>' + esc(f.by || '') + ' · ' + esc(when(f.at)) + '</span></div></div><div class="a"><button class="bd-btn sm" data-mv="' + f.id + '">Move</button><button class="bd-btn sm bad" data-rm="' + f.id + '">Delete</button></div></div>').join('') : '<div class="bd-empty"><b>Nothing on this shelf yet</b>Use “Teach Claude” to put the first rule here.</div>';
    body.innerHTML = h + '</div>';
    $('brTeachHere').onclick = () => { TAB = 'teach'; shell(); draw(); };
    const sum = $('brSum'); if (sum) sum.onclick = async () => { sum.disabled = true; const x = await call(() => post('summary'), 'Written again.'); if (x) drawShelves(b); else sum.disabled = false; };
    body.querySelectorAll('[data-rm]').forEach((x) => { x.onclick = async () => { const f = r.facts.find((q) => String(q.id) === x.dataset.rm); if (!(await confirm('Delete this?', f.text.slice(0, 160), 'Delete', true))) return; const y = await call(() => post('fact_delete', { id: f.id }), 'Deleted.'); if (y) { await reloadOverview(); drawShelves(b); } }; });
    body.querySelectorAll('[data-mv]').forEach((x) => { x.onclick = () => moveFact(r.facts.find((q) => String(q.id) === x.dataset.mv), () => { reloadOverview(); drawShelves(b); }); });
  }
  function moveFact(f, done) {
    const cur = SHELF; const m = modal('<h3>Who may know this?</h3><p style="font-size:14px;line-height:1.5;margin:0 0 10px">' + esc(f.text) + '</p><select id="mvS" class="bd-in">' + ['customer', 'company', 'internal'].map((s) => '<option value="' + s + '"' + (cur === s ? ' selected' : '') + '>' + SC[s].name + ' — ' + SC[s].who + '</option>').join('') + '</select><label class="ck" id="mvShW" style="display:flex;gap:7px;align-items:center;margin-top:10px;font-size:14px"><input type="checkbox" id="mvSh"' + (f.shareable ? ' checked' : '') + '> Customers may be told this</label><div class="acts"><button class="bd-btn" id="mvNo">Cancel</button><button class="bd-btn pri" id="mvOk">Move</button></div>');
    const sync = () => { m.querySelector('#mvShW').style.display = m.querySelector('#mvS').value === 'company' ? 'flex' : 'none'; }; sync(); m.querySelector('#mvS').onchange = sync;
    m.querySelector('#mvNo').onclick = close;
    m.querySelector('#mvOk').onclick = async () => { const sc = m.querySelector('#mvS').value; const r = await call(() => post('fact_move', { id: f.id, scope: sc, shareable: sc === 'company' ? m.querySelector('#mvSh').checked : sc === 'customer' }), 'Moved to the ' + SC[sc].name + ' shelf.'); if (r) { close(); done(); } };
  }

  /* ------------------------------------------------------------ teach */
  function drawTeach(b) {
    if (!TEACH) {
      b.innerHTML = '<div class="bd-card"><div class="bd-h"><h2>Tell Claude something</h2></div><div class="bd-sub" style="margin:0 0 10px">Write it the way you would say it. Claude splits it into separate points and decides who may know each one. <b>Nothing is saved until you press Save.</b></div>'
        + '<textarea class="br-ta" id="tcT" placeholder="Example: Late parcels at dispatch cost ₹100 each. Tell customers we ship in 24 hours. Always reply in the customer’s language."></textarea><div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><button class="bd-btn pri" id="tcGo">Let Claude read it</button></div><div id="tcOut"></div></div>';
      $('tcGo').onclick = async () => {
        const t = $('tcT').value.trim(); if (t.length < 4) { toast('Write what Claude should know.', true); return; }
        $('tcGo').disabled = true; $('tcGo').textContent = 'Reading…'; const r = await call(() => post('teach_read', { text: t }), false);
        if (!r) { $('tcGo').disabled = false; $('tcGo').textContent = 'Let Claude read it'; return; }
        TEACH = r; drawTeach(b);
      };
      return;
    }
    const T = TEACH;
    let h = '<div class="bd-card"><div class="bd-h"><h2>Is this what you meant?</h2></div>';
    if (!T.ai) h += '<div class="bd-call warn">Claude could not read it just now, so your sentence is kept as it is. Check who may know it below.</div>';
    if (T.understood && T.understood.length) h += '<div class="bd-call info">' + T.understood.map((x) => esc(x)).join('<br>') + '</div>';
    h += T.items.map((it, i) => '<div class="br-item" data-i="' + i + '"><textarea class="ta">' + esc(it.text) + '</textarea><div class="rw">' + scopeSel(it.scope, 'sc' + i) + areaSel(it.area, 'ar' + i) + '<label class="ck" id="shw' + i + '"><input type="checkbox" id="sh' + i + '"' + (it.shareable ? ' checked' : '') + '> customers may be told</label><button class="bd-btn sm bad" data-x="' + i + '">Remove</button></div>'
      + (it.task ? '<div class="bd-sub" style="margin-top:6px">This sounds like a duty or a penalty. It is saved as a rule Claude follows; to make it a daily duty open Boss → Today.</div>' : '') + '</div>').join('');
    h += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px"><button class="bd-btn go" id="tcSave">Save to the brain</button><button class="bd-btn" id="tcBack">Start again</button></div></div>';
    b.innerHTML = h;
    const sync = (i) => { const s = $('sc' + i).value; $('shw' + i).style.display = s === 'company' ? 'flex' : 'none'; }; T.items.forEach((_, i) => { sync(i); $('sc' + i).onchange = () => sync(i); });
    b.querySelectorAll('[data-x]').forEach((x) => { x.onclick = () => { collect(); T.items.splice(Number(x.dataset.x), 1); if (!T.items.length) TEACH = null; drawTeach(b); }; });
    $('tcBack').onclick = () => { TEACH = null; drawTeach(b); };
    function collect() { T.items.forEach((it, i) => { const el = b.querySelector('[data-i="' + i + '"]'); if (!el) return; it.text = el.querySelector('.ta').value.trim(); it.scope = $('sc' + i).value; it.area = $('ar' + i).value; it.shareable = it.scope === 'customer' ? true : it.scope === 'company' ? $('sh' + i).checked : false; }); }
    $('tcSave').onclick = async () => {
      collect(); const items = T.items.filter((x) => x.text); if (!items.length) { toast('Nothing to save.', true); return; }
      const cust = items.filter((x) => x.scope === 'customer' || (x.scope === 'company' && x.shareable)).length;
      if (cust && !(await confirm('Customers may hear ' + cust + ' of these', 'Anything on the Customer shelf (or a company fact marked “customers may be told”) can appear in what customers read. Save?', 'Save', false))) return;
      $('tcSave').disabled = true; const r = await call(() => post('teach_save', { items }));
      if (r) { TEACH = null; await reloadOverview(); drawTeach(b); } else $('tcSave').disabled = false;
    };
  }

  /* ------------------------------------------------------------ old notes */
  async function drawOld(b) {
    const r = await get('facts', { scope: 'customer' }); const old = r.old || []; const live = old.filter((x) => x.status !== 'replaced'), rep = old.filter((x) => x.status === 'replaced'); const od = O.old || {};
    let h = '<div class="bd-card"><div class="bd-h"><h2>Older Teach-the-bot notes</h2><button class="bd-btn sm" id="brImp">Read them again</button></div><div class="bd-sub" style="margin:0 0 8px">These are the notes taught before the Brain existed. They still work. <b>New rules win:</b> a note you replace or stop is left out of every prompt, but never deleted.</div>'
      + '<div class="br-tiles" style="grid-template-columns:repeat(3,minmax(0,1fr))"><div class="br-tile"><small>Still used</small><b>' + (od.lines || 0) + '</b></div><div class="br-tile"><small>Replaced / stopped</small><b>' + (od.replaced || 0) + '</b></div><div class="br-tile"><small>Lessons (automatic)</small><b>' + (od.lessons || 0) + '</b></div></div>';
    h += live.length ? live.map((x) => '<div class="br-old" data-o="' + x.id + '"><div class="t">' + esc(x.line) + '<div class="bd-sub" style="margin-top:3px">' + (x.src === 'corr' ? 'Owner correction' : 'Bot fact') + '</div></div><div class="a"><button class="bd-btn sm" data-rp="' + x.id + '">Replace</button><button class="bd-btn sm bad" data-st="' + x.id + '">Stop using</button></div></div>').join('') : '<div class="bd-empty"><b>No older notes in use</b>Everything the bot knows now lives on the shelves.</div>';
    if (rep.length) h += '<div class="bd-lb" style="margin:14px 0 4px">Replaced or stopped</div>' + rep.map((x) => '<div class="br-old rep" data-o="' + x.id + '"><div class="t">' + esc(x.line) + (x.why ? '<div class="bd-sub" style="text-decoration:none">' + esc(x.why) + '</div>' : '') + '</div><div class="a"><button class="bd-btn sm" data-rs="' + x.id + '">Use it again</button></div></div>').join('');
    b.innerHTML = h + '</div>';
    $('brImp').onclick = async () => { const x = await call(() => post('import')); if (x) { await reloadOverview(); drawOld(b); } };
    const act = async (id, text, kind) => { const x = await call(() => post('old_replace', { id, text: text || '', kind: kind || 'rule' }), text ? 'Replaced. Claude uses your new sentence.' : 'This note is not used any more.'); if (x) { await reloadOverview(); drawOld(b); } };
    b.querySelectorAll('[data-st]').forEach((x) => { x.onclick = async () => { if (await confirm('Stop using this note?', 'The bot will not read it any more. You can bring it back.', 'Stop using', true)) act(x.dataset.st, ''); }; });
    b.querySelectorAll('[data-rs]').forEach((x) => { x.onclick = async () => { const y = await call(() => post('old_restore', { id: x.dataset.rs }), 'It is used again.'); if (y) { await reloadOverview(); drawOld(b); } }; });
    b.querySelectorAll('[data-rp]').forEach((x) => { x.onclick = () => {
      const o = old.find((q) => String(q.id) === x.dataset.rp);
      const m = modal('<h3>Replace this note</h3><p style="font-size:13.5px;line-height:1.5;margin:0 0 10px;color:#6a7280">' + esc(o.line) + '</p><textarea class="br-ta" id="rpT" placeholder="Write the new sentence"></textarea><select id="rpK" class="bd-in" style="margin-top:8px"><option value="rule">It is a rule (how to reply)</option><option value="fact">It is a fact (something true)</option></select><div class="acts"><button class="bd-btn" id="rpNo">Cancel</button><button class="bd-btn pri" id="rpOk">Replace</button></div>');
      m.querySelector('#rpNo').onclick = close; m.querySelector('#rpOk').onclick = () => { const t = m.querySelector('#rpT').value.trim(); if (!t) { toast('Write the new sentence.', true); return; } close(); act(o.id, t, m.querySelector('#rpK').value); };
    }; });
  }

  /* ------------------------------------------------------------ bot trial */
  async function drawTrial(b) {
    const r = await get('trial_list', { f: TF }); const s = r.stats, rows = r.rows || []; R = R || (await get('route_get')); const mode = R.cfg.customer, can = R.can_set;
    const rated = s.better + s.worse + s.equal;
    let h = '<div class="br-mode ' + mode + '" style="margin:0 0 12px">' + (mode === 'trial' ? '<b>Trial is running.</b> Customers get the old reply. After it is sent, Claude writes it again with the Customer shelf and both are kept here.' : mode === 'live' ? '<b>Live.</b> Customers now get brain replies. This log only fills while the trial runs.' : '<b>Off.</b> Switch the customer replies to Trial on the Overview tab to start comparing.') + '</div>';
    h += '<div class="br-tiles"><div class="br-tile"><small>Compared</small><b>' + s.ok + '</b></div><div class="br-tile"><small>Different text</small><b>' + s.differ + '</b></div><div class="br-tile ' + (s.guard_fail + s.nums_fail ? 'bad' : 'ok') + '"><small>Held back by the safety guards</small><b>' + (s.guard_fail + s.nums_fail) + '</b></div><div class="br-tile"><small>Average wait (not seen by customers)</small><b>' + (s.avg_ms ? (s.avg_ms / 1000).toFixed(1) + ' s' : '–') + '</b></div></div>';
    h += '<div class="bd-card"><div class="bd-h"><h2>Is it ready?</h2></div>'
      + '<div class="bd-call ' + (s.ready ? 'ok' : 'info') + '">' + (s.ok < 30 ? 'Keep the trial running: ' + s.ok + ' of 30 replies compared.' : s.guard_fail || s.nums_fail ? 'Some brain replies were held back by a guard. Open “Flagged” below and look at them before going live.' : rated && s.worse / rated >= 0.1 ? 'You marked ' + s.worse + ' of ' + rated + ' replies as worse. Teach Claude what was wrong, then keep trying.' : 'Looks clean: no guard failures and ' + (rated ? s.better + ' better, ' + s.equal + ' same, ' + s.worse + ' worse.' : 'rate a few replies to be sure.')) + '</div>'
      + '<div class="bd-sub">Rated by you: <b>' + s.better + '</b> better · <b>' + s.equal + '</b> same · <b>' + s.worse + '</b> worse · ' + s.unrated + ' not rated yet.</div></div>';
    h += '<div class="bd-card"><div class="bd-h"><h2>Old reply next to the brain reply</h2>' + (can ? '<button class="bd-btn sm bad" id="trClr">Clear the log</button>' : '') + '</div><div class="bd-seg" id="trF" style="margin:0 0 6px">' + [['differ', 'Different'], ['unrated', 'Not rated'], ['flag', 'Flagged'], ['all', 'All']].map((x) => '<button data-f="' + x[0] + '" class="' + (TF === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>';
    h += rows.length ? rows.map((x) => '<div class="br-tr" data-t="' + x.id + '"><div class="hd"><span class="bd-chip">' + esc(x.channel) + '</span><span>' + esc(when(x.at)) + '</span>' + (x.same_text ? '<span class="bd-chip">same text</span>' : '') + (!x.ok ? '<span class="bd-chip bad">no new reply</span>' : '') + (x.ok && !x.guard_ok ? '<span class="bd-chip bad">held back: ' + esc(x.guard_why) + '</span>' : '') + (x.nums_bad ? '<span class="bd-chip bad">numbers not in your facts: ' + esc(x.nums_bad) + '</span>' : '') + '</div>'
      + '<div class="br-bub cus"><small>Customer wrote</small>' + esc(x.customer_msg) + '</div><div class="br-pair"><div class="br-bub old"><small>Old reply (what the customer got)</small>' + esc(x.old_reply) + '</div><div class="br-bub new"><small>Brain reply</small>' + (x.ok ? esc(x.new_reply) : '–') + '</div></div>'
      + (x.ok && !x.same_text ? '<div class="rate"><span class="bd-sub" style="margin:0">The brain reply is</span>' + [['better', 'Better'], ['same', 'Same'], ['worse', 'Worse']].map((v) => '<button class="bd-btn sm ' + (x.verdict === v[0] ? 'on' : '') + '" data-rate="' + v[0] + '">' + v[1] + '</button>').join('') + '</div>' : '') + '</div>').join('') : '<div class="bd-empty"><b>Nothing here yet</b>' + (mode === 'trial' ? 'It fills as customers write. Only ' + R.cfg.sample + '% of replies are compared.' : 'Start the trial on the Overview tab.') + '</div>';
    b.innerHTML = h + '</div>';
    $('trF').onclick = (e) => { const x = e.target.closest('button'); if (!x) return; TF = x.dataset.f; drawTrial(b); };
    const clr = $('trClr'); if (clr) clr.onclick = async () => { if (await confirm('Clear the trial log?', 'All compared replies are removed. Your ratings go with them.', 'Clear', true)) { const x = await call(() => post('trial_clear')); if (x) drawTrial(b); } };
    b.querySelectorAll('[data-rate]').forEach((x) => { x.onclick = async () => { const id = x.closest('[data-t]').dataset.t; const row = rows.find((q) => String(q.id) === id); const v = row.verdict === x.dataset.rate ? '' : x.dataset.rate; const y = await call(() => post('trial_rate', { id, verdict: v }), false); if (y) drawTrial(b); }; });
  }

  /* ------------------------------------------------------------ safety */
  function leakHtml(L) {
    if (!L || !L.checks) return '<div class="bd-empty"><b>Not run yet</b>Run it before customers get replies from the brain.</div>';
    return '<div class="bd-call ' + (L.ok ? 'ok' : 'bad') + '"><b>' + (L.ok ? 'Passed.' : L.failed + ' check' + (L.failed === 1 ? '' : 's') + ' failed.') + '</b> ' + esc(when(L.at)) + '</div>' + L.checks.map((c) => '<div class="br-leak"><i>' + (c.ok ? '✅' : '❌') + '</i><span>' + esc(c.name) + '</span></div>').join('');
  }
  function drawSafety(b) {
    b.innerHTML = '<div class="bd-card"><div class="bd-h"><h2>Leak test</h2></div><div class="bd-sub" style="margin:0 0 10px">Claude plants a secret team-only rule, builds everything a customer-facing Claude would be told, and checks the secret is nowhere in it. It also checks that a reply repeating a team-only rule is held back. The secret is deleted straight after. <b>Customer replies can only go Live if this passed today.</b></div><button class="bd-btn pri" id="lkGo">Run the leak test</button><div id="lkOut" style="margin-top:12px">' + leakHtml(O.leak) + '</div></div>';
    $('lkGo').onclick = async () => { $('lkGo').disabled = true; $('lkGo').textContent = 'Testing…'; const r = await call(() => post('leak_test'), false); $('lkGo').disabled = false; $('lkGo').textContent = 'Run the leak test'; if (r) { O.leak = r; $('lkOut').innerHTML = leakHtml(r); toast(r.ok ? 'Passed. Nothing private reaches a customer.' : 'A check failed. Do not go live.', !r.ok); } };
  }

  return { mount };
})();