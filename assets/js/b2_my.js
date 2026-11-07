/* Boss Engine 2 — "Your tasks" on My Day. Needs api.js, boss_common.js (BD) and b2_common.js (B2).
   One card per task. Tick / photo / a few words / number + screenshot. Several numbers can share ONE screenshot.
   Long jobs (to fix, follow-up, improve) get a daily progress note. Everything is sent to api.php?action=bd.b2_* */
const B2MY = (() => {
  const { esc, toast, inr } = BD;
  let DATA = null, BUSY = false, ROOT = null;

  function cardHtml(c) {
    const open = c.status === 'open' || c.status === 'missed';
    const cls = 'b2m-card' + (c.status === 'done' ? ' done' : '') + (c.status === 'missed' ? ' bad' : (c.overdue || c.late) && open ? ' late' : '');
    let h = '<div class="' + cls + '" data-run="' + c.run_id + '"><div class="b2m-top"><div style="min-width:0;flex:1 1 220px"><div class="b2-kind">' + esc(c.kind_label) + (c.section ? ' · ' + esc(c.section) : '') + '</div><div class="b2m-t">' + esc(c.title) + '</div>'
      + '<div class="b2m-s">' + esc(c.due_label) + (c.pen_amount > 0 && !c.fair ? ' · penalty ' + inr(c.pen_amount) + ' if missed' : '') + (c.fair ? ' · fair-start days, no charge' : '') + (c.cover ? ' · ' + esc(c.cover) : '') + '</div></div><div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
    if (c.streak >= 2 && c.block !== 'once') h += '<span class="b2m-streak">' + c.streak + ' days in a row</span>';
    h += B2.st(c.state, c.state_label) + '</div></div>';
    if (c.block !== 'once' && c.strip && c.strip.length) h += '<div style="margin-top:8px">' + B2.strip(c.strip) + '</div>';
    if (c.why && open) h += '<div class="b2m-s">' + esc(c.why) + '</div>';
    if (c.detail && open) h += '<div class="b2m-s" style="color:#3a3f4a">' + esc(c.detail) + '</div>';
    if (c.proof_state === 'flag' || c.proof_state === 'reject') h += '<div class="bd-call ' + (c.proof_state === 'reject' ? 'bad' : 'warn') + '" style="margin-top:8px">' + esc(c.proof_why || (c.proof_state === 'reject' ? 'The checker did not accept this. Please do it again.' : 'The checker will look at this.')) + '</div>';
    if (c.status === 'review') { h += '<div class="b2m-s" style="margin-top:8px"><b>Sent.</b> Waiting for ' + esc(c.checker || 'the checker') + ' to look.</div>'; return h + '</div>'; }
    if (c.status === 'done') { h += '<div class="b2m-s" style="margin-top:6px">Done' + (c.value != null ? ' · ' + esc(String(c.value)) + (c.unit ? ' ' + esc(c.unit) : '') : '') + (c.note ? ' · ' + esc(c.note) : '') + '</div>'; return h + '</div>'; }
    if (c.blocked) { h += '<div class="b2m-s" style="margin-top:8px">Waiting: ' + esc(c.waiting_on || 'a page that is still being built') + '</div>'; return h + '</div>'; }
    if (c.proof === 'auto') { h += '<div class="b2m-s" style="margin-top:8px">The system checks this by itself. Just do the work.</div>'; return h + '</div>'; }
    if (c.kind === 'number') return h + '</div>';          // numbers are handled together in one block
    h += '<div class="b2m-acts">';
    if (c.progress) h += '<div style="flex:1 1 100%"><div style="display:flex;gap:10px;align-items:center"><input type="range" min="0" max="100" step="5" value="' + c.pct + '" data-pc="' + c.run_id + '" style="flex:1"><b data-pcv="' + c.run_id + '" style="width:44px;text-align:right">' + c.pct + '%</b></div><div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><input class="bd-in" data-pn="' + c.run_id + '" placeholder="What did you do today? One line." style="flex:1 1 200px"><button type="button" class="bd-btn sm" data-prog="' + c.run_id + '">Save progress</button></div></div>';
    if (c.proof === 'photo' || c.proof === 'screenshot') h += '<label class="bd-btn pri" style="cursor:pointer">' + (c.proof === 'photo' ? 'Take / choose photo' : 'Choose screenshot') + '<input type="file" accept="image/*"' + (c.proof === 'photo' ? ' capture="environment"' : '') + ' class="b2m-file" data-photo="' + c.run_id + '"></label>' + (c.photo_prompt ? '<span class="b2m-s" style="margin:0">' + esc(c.photo_prompt) + '</span>' : '');
    else if (c.proof === 'text') h += '<input class="bd-in" data-note="' + c.run_id + '" placeholder="A few words about what you did" style="flex:1 1 200px"><button type="button" class="bd-btn go" data-text="' + c.run_id + '">Done ✓</button>';
    else h += '<button type="button" class="bd-btn go" data-tick="' + c.run_id + '">' + (c.block === 'once' ? 'Mark done ✓' : 'Done ✓') + '</button>';
    return h + '</div></div>';
  }
  function numbersHtml(list) {
    const todo = list.filter((c) => c.kind === 'number' && (c.status === 'open' || c.status === 'missed') && !c.blocked && c.block !== 'once');
    if (!todo.length) return '';
    return '<div class="b2m-card" id="b2Nums"><div class="b2-kind">Numbers for today</div><div class="b2m-s" style="margin-bottom:8px">Type each number, then add ONE screenshot that shows them. ' + (todo.length > 1 ? 'One screenshot can cover all of them.' : '') + '</div>'
      + todo.map((c) => '<div style="display:flex;gap:10px;align-items:center;justify-content:space-between;padding:8px 0;border-top:1px solid var(--line);flex-wrap:wrap"><div style="flex:1 1 200px;min-width:0"><div class="b2m-t" style="font-size:14px">' + esc(c.title) + '</div><div class="b2m-s">' + esc(c.due_label) + (c.target != null ? ' · target ' + esc(String(c.target)) + (c.unit ? ' ' + esc(c.unit) : '') : '') + (c.target_state === 'proposed' ? ' (waiting for the boss to approve)' : '') + '</div></div>'
        + '<input class="bd-in" inputmode="decimal" data-nv="' + c.run_id + '" placeholder="' + esc(c.unit || 'number') + '" style="width:130px"></div>').join('')
      + '<div class="b2m-acts"><label class="bd-btn" style="cursor:pointer" id="b2NumLbl">Choose screenshot<input type="file" accept="image/*" class="b2m-file" id="b2NumFile"></label><span class="b2m-s" id="b2NumName" style="margin:0"></span><button type="button" class="bd-btn go" id="b2NumGo">Save numbers</button></div></div>';
  }
  function html(d) {
    if (!d || !d.any && !(d.improve || []).length && !(d.problems || []).length) return '';
    const cards = d.cards || [];
    let h = '<div class="md-card" id="b2MyCard"><div class="md-h"><span>Your tasks today</span><span class="bd-sub">' + d.summary.done + ' done · ' + d.summary.open + ' to do' + (d.summary.missed ? ' · ' + d.summary.missed + ' missed' : '') + '</span></div>';
    h += numbersHtml(cards);
    h += cards.filter((c) => c.kind !== 'number' || c.status === 'done' || c.status === 'review' || c.blocked || c.block === 'once' || c.proof === 'auto').map(cardHtml).join('');
    if ((d.improve || []).length) h += '<div class="md-h" style="margin-top:16px"><span>Getting better</span></div>' + d.improve.map((i) => '<div class="b2m-card"><div class="b2m-t">' + esc(i.title) + '</div><div class="b2-bar" style="margin:8px 0 4px"><i style="width:' + Math.min(100, Math.round(100 * i.good_days / Math.max(1, i.need))) + '%"></i></div><div class="b2m-s">' + i.good_days + ' good day' + (i.good_days === 1 ? '' : 's') + ' of ' + i.need + '. After ' + i.need + ' good days this closes by itself.</div></div>').join('');
    if ((d.problems || []).length) h += '<div class="md-h" style="margin-top:16px"><span>What you reported</span></div>' + d.problems.map((p) => '<div style="padding:9px 0;border-top:1px solid var(--line)" data-prob="' + p.id + '"><b style="font-size:14px">' + esc(p.title) + '</b><div class="bd-sub">' + esc(p.step_label || '') + (p.result ? ' — ' + esc(p.result) : '') + '</div>' + '</div>').join('');
    return h + '</div>';
  }

  async function send(action, fd, okFn) {
    if (BUSY) return; BUSY = true;
    try { const r = await api.upload(action, fd); toast(r.message || 'Saved. Thank you.'); if (okFn) okFn(r); await refresh(); }
    catch (e) { toast(e.message || 'Could not save. Try again.', true); }
    BUSY = false;
  }
  async function post(action, body) {
    if (BUSY) return; BUSY = true;
    try { const r = await api.post(action, body); toast(r.message || 'Saved. Thank you.'); await refresh(); }
    catch (e) { toast(e.message || 'Could not save. Try again.', true); }
    BUSY = false;
  }
  function bind(el) {
    el.addEventListener('input', (e) => { const r = e.target.closest('input[data-pc]'); if (r) { const v = el.querySelector('[data-pcv="' + r.dataset.pc + '"]'); if (v) v.textContent = r.value + '%'; } });
    el.addEventListener('change', async (e) => {
      const f = e.target.closest('input[type=file]'); if (!f || !f.files || !f.files[0]) return;
      if (f.id === 'b2NumFile') { const n = el.querySelector('#b2NumName'); if (n) n.textContent = f.files[0].name; return; }
      if (f.dataset.photo) {
        const run = f.dataset.photo; const card = el.querySelector('[data-run="' + run + '"]'); const fd = new FormData();
        fd.append('run_id', run); fd.append('photo', f.files[0]);
        send('bd.b2_submit', fd);
      }
    });
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('button'); if (!b || BUSY) return;
      try {
        if (b.dataset.tick) { await post('bd.b2_submit', { run_id: Number(b.dataset.tick) }); }
        else if (b.dataset.text) {
          const id = b.dataset.text; const n = el.querySelector('[data-note="' + id + '"]');
          if (!n || n.value.trim().length < 3) { toast('Write a few words first.', true); return; }
          await post('bd.b2_submit', { run_id: Number(id), note: n.value.trim() });
        } else if (b.dataset.prog) {
          const id = b.dataset.prog; const n = el.querySelector('[data-pn="' + id + '"]'); const pc = el.querySelector('[data-pc="' + id + '"]');
          if (!n || n.value.trim().length < 3) { toast('Write one line about what you did.', true); return; }
          await post('bd.b2_progress', { run_id: Number(id), note: n.value.trim(), pct: Number(pc.value) });
        } else if (b.id === 'b2NumGo') {
          const ids = [], vals = [];
          el.querySelectorAll('[data-nv]').forEach((i) => { ids.push(Number(i.dataset.nv)); vals.push(i.value.trim()); });
          if (vals.some((v) => v === '')) { toast('Type every number first.', true); return; }
          const fl = el.querySelector('#b2NumFile'); if (!fl || !fl.files[0]) { toast('Add the screenshot first.', true); return; }
          const fd = new FormData(); fd.append('run_ids', JSON.stringify(ids)); fd.append('values', JSON.stringify(vals)); fd.append('photo', fl.files[0]);
          await send('bd.b2_submit_many', fd);
        }
      } catch (er) { toast(er.message || 'Something went wrong.', true); BUSY = false; }
    });
  }
  async function refresh() {
    try { DATA = await api.get('bd.b2_my'); } catch (e) { return; }
    if (ROOT && ROOT.isConnected) { const keep = {}; ROOT.querySelectorAll('[data-nv]').forEach((i) => { keep[i.dataset.nv] = i.value; }); ROOT.innerHTML = html(DATA); ROOT.querySelectorAll('[data-nv]').forEach((i) => { if (keep[i.dataset.nv] != null) i.value = keep[i.dataset.nv]; }); }
    document.dispatchEvent(new CustomEvent('b2my', { detail: DATA }));
  }
  async function mount(el) {
    if (!el) return; ROOT = el; if (!el.__b2) { bind(el); el.__b2 = 1; }
    try { DATA = await api.get('bd.b2_my'); el.innerHTML = html(DATA); document.dispatchEvent(new CustomEvent('b2my', { detail: DATA })); } catch (e) { /* optional card */ }
  }
  /* Open "Report a problem" from the page (button on My Day) */
  function report() { B2.report({ page: 'my_day.html', onSent: refresh }); }
  return { mount, refresh, report };
})();
