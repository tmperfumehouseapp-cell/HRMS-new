/* Boss HQ people board: who has what, today. Used by the Summary page (every section) and by the Dispatch / Money / Shops / Supplies pages (one section).
   HQ.mount(element, { section: 'dispatch' | '', title: 'People tracked here' }) -> { reload() }
   Needs: api.js (api.get / api.post), boss_common.js (BD). Manager-only: on a 403 the board hides itself. */
const HQ = (() => {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  let cssDone = false;
  function css() {
    if (cssDone) return; cssDone = true;
    const s = document.createElement('style');
    s.textContent = [
      '.hq-sec{background:var(--surface,#fff);border-radius:var(--radius,22px);padding:16px 18px;margin-bottom:12px;box-shadow:0 1px 2px rgba(20,22,26,.04),0 6px 18px -10px rgba(20,22,26,.12);overflow:hidden;}',
      '.hq-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}',
      '.hq-head h3{margin:0;font-size:17px;font-weight:800;letter-spacing:-.01em;} .hq-head .sp{flex:1;}',
      '.hq-prog{height:8px;border-radius:99px;background:#eceef1;overflow:hidden;margin:10px 0 4px;} .hq-prog i{display:block;height:100%;background:#16945a;border-radius:99px;transition:width .4s;} .hq-prog i.bad{background:#d9423b;} .hq-prog i.mid{background:#f2b33d;}',
      '.hq-strip{display:flex;align-items:center;gap:10px;background:#f1f9f4;border:1px solid #cfe9da;border-radius:99px;padding:9px 16px;margin-bottom:8px;cursor:pointer;font-size:13.5px;font-weight:700;color:#116b43;transition:all .35s;}',
      '.hq-strip .tick{width:22px;height:22px;border-radius:50%;background:#16945a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;flex:none;} .hq-strip .sp{flex:1;} .hq-strip small{font-weight:600;color:#4a7a63;}',
      '.hq-p{border-top:1px solid var(--line,#eceef1);padding:12px 0 6px;} .hq-sec .hq-p:first-of-type{border-top:0;}',
      '.hq-pn{display:flex;gap:10px;align-items:center;flex-wrap:wrap;} .hq-av{width:34px;height:34px;border-radius:50%;background:#111827;color:#fff;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center;flex:none;}',
      '.hq-pn b{font-size:15px;} .hq-roles{font-size:12px;color:var(--muted,#7a8499);}',
      '.hq-job{display:flex;gap:10px;align-items:center;padding:8px 0 8px 44px;font-size:14px;transition:all .35s;max-height:90px;overflow:hidden;} .hq-job .t{flex:1;min-width:0;line-height:1.35;} .hq-job .d{font-size:12px;color:var(--muted,#7a8499);}',
      '.hq-job.miss .t{color:#a8322d;font-weight:600;} .hq-job.shrink{max-height:0;padding-top:0;padding-bottom:0;opacity:0;transform:translateX(30px);}',
      '.hq-dn{margin:4px 0 4px 44px;font-size:12.5px;color:#116b43;font-weight:700;cursor:pointer;display:inline-flex;gap:6px;align-items:center;background:#f1f9f4;border-radius:99px;padding:4px 12px;} .hq-dnl{margin:2px 0 6px 44px;font-size:12.5px;color:#4a505c;line-height:1.7;}',
      '.hq-wrap{margin-top:12px;border-radius:16px;background:#faf8f3;padding:12px 14px;font-size:13.5px;line-height:1.5;} .hq-wrap.ok{background:#f1f9f4;} .hq-wrap .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:9px;} .hq-wrap input{flex:1;min-width:160px;min-height:40px;border:1px solid var(--line,#dfe3e8);border-radius:99px;padding:6px 14px;font:inherit;font-size:13.5px;background:#fff;}',
      '.hq-empty{font-size:13px;color:var(--muted,#7a8499);padding:8px 0;} .hq-warn{font-size:12.5px;color:#9a6200;background:#fff3dc;border-radius:12px;padding:6px 10px;margin-top:8px;}',
      '.hq-bar-btn{min-height:34px !important;padding:5px 14px !important;font-size:13px !important;}',
      '@media(max-width:640px){.hq-job{padding-left:0}.hq-dn,.hq-dnl{margin-left:0}}'
    ].join('\n');
    document.head.appendChild(s);
  }
  const trackChip = (t) => t.tasks ? '<span class="bd-chip ' + (t.on_time >= 90 ? 'ok' : (t.on_time >= 70 ? 'warn' : 'bad')) + '" title="Last 30 days">' + t.on_time + '% on time · ' + t.tasks + ' jobs</span>' : '<span class="bd-chip" title="Last 30 days">no record yet</span>';

  function mount(el, opts) {
    css(); opts = opts || {};
    const open = {}; // expanded cards / done lists: remembered between refreshes
    let data = null, timer = null, busy = false;

    function job(it, secCode) {
      const miss = it.state === 'missed';
      return '<div class="hq-job ' + (miss ? 'miss' : '') + '"><div class="t">' + esc(it.title) + '<div class="d">' + (miss ? 'Missed' : 'Open') + (it.due ? ' · due ' + esc(it.due) : '') + (it.pen ? ' · penalty ' + inr(it.pen) + ' waiting' : '') + '</div></div>'
        + '<button type="button" class="bd-btn sm go hq-bar-btn" data-done="' + esc(it.kind) + ':' + it.id + '">Done ✓</button></div>';
    }
    function person(p, sec) {
      const left = p.open.length + p.missed.length;
      const jobs = p.missed.map((i) => job(i, sec.code)).join('') + p.open.map((i) => job(i, sec.code)).join('');
      const dk = sec.code + ':' + p.id;
      const dn = p.done.length ? '<div class="hq-dn" data-dn="' + dk + '"><span>✓</span> ' + p.done.length + ' done' + (open['dn' + dk] ? ' ▴' : ' ▾') + '</div>' + (open['dn' + dk] ? '<div class="hq-dnl">' + p.done.map((i) => '✓ ' + esc(i.title) + (i.at ? ' <span class="bd-sub">' + esc(i.at) + '</span>' : '')).join('<br>') + '</div>' : '') : '';
      return '<div class="hq-p"><div class="hq-pn"><div class="hq-av">' + esc((p.name || '?').trim().charAt(0).toUpperCase()) + '</div><div style="flex:1;min-width:0"><b>' + esc(p.name) + '</b><div class="hq-roles">' + (p.roles.length ? esc(p.roles.slice(0, 3).join(' · ')) : 'no seat yet - has jobs only') + '</div></div>' + trackChip(p.track)
        + (left ? '<span class="bd-chip ' + (p.missed.length ? 'bad' : 'warn') + '">' + (p.missed.length ? p.missed.length + ' missed' + (p.open.length ? ' · ' : '') : '') + (p.open.length ? p.open.length + ' open' : '') + '</span>' : (p.done.length ? '<span class="bd-chip ok">all done</span>' : '<span class="bd-chip">no job today</span>')) + '</div>'
        + jobs + dn + '</div>';
    }
    function wrapBox(sec) {
      if (sec.no_ack) return '';
      if (sec.ack) return '<div class="hq-wrap ok"><b>✓ Closed for today</b> by ' + esc(sec.ack.by) + ' at ' + esc(sec.ack.at) + (sec.ack.note ? ' - "' + esc(sec.ack.note) + '"' : '') + '<div class="bd-sub" style="margin-top:3px">' + esc(sec.wrap) + '</div></div>';
      if (!sec.total) return '';
      return '<div class="hq-wrap ' + (sec.all_done ? 'ok' : '') + '"><b>Summary:</b> ' + esc(sec.wrap)
        + (sec.open || sec.missed ? '<div class="bd-sub" style="margin-top:3px">When every job is done or you have looked at the rest, press OK.</div>' : '')
        + '<div class="row"><input data-note="' + esc(sec.code) + '" placeholder="A line for the record (optional)"><button type="button" class="bd-btn pri hq-bar-btn" data-ack="' + esc(sec.code) + '">OK, fixed</button></div></div>';
    }
    function secCard(sec) {
      const k = 'sec' + sec.code;
      const done = sec.total > 0 && sec.open === 0 && sec.missed === 0;
      // a finished section shrinks to one green line
      if (done && !open[k]) return '<div class="hq-strip" data-open="' + k + '"><span class="tick">✓</span><span>' + esc(sec.name) + '</span><span class="sp"></span><small>' + sec.done + ' of ' + sec.total + ' done' + (sec.ack ? ' · closed by ' + esc(sec.ack.by) : ' · waiting for your OK') + '</small></div>';
      const pct = sec.total ? Math.round(100 * sec.done / sec.total) : 0;
      const empty = sec.empty_seats && sec.empty_seats.length ? '<div class="hq-warn">Nobody in: ' + sec.empty_seats.map((e) => esc(e.title)).join(', ') + '. <a href="boss_engine.html?tab=people">Choose people</a></div>' : '';
      const unowned = sec.unowned && sec.unowned.length ? '<div class="hq-warn">' + sec.unowned.length + ' job' + (sec.unowned.length === 1 ? ' has' : 's have') + ' nobody to do ' + (sec.unowned.length === 1 ? 'it' : 'them') + ': ' + sec.unowned.slice(0, 3).map((i) => esc(i.title)).join('; ') + '</div>' : '';
      return '<div class="hq-sec"><div class="hq-head"><h3>' + esc(opts.section ? (opts.title || 'People tracked here') : sec.name) + '</h3>'
        + (sec.total ? '<span class="bd-chip ' + (sec.missed ? 'bad' : (sec.open ? 'warn' : 'ok')) + '">' + sec.done + ' of ' + sec.total + ' done</span>' : '<span class="bd-chip">no jobs today</span>')
        + '<span class="sp"></span>' + (done ? '<button type="button" class="bd-btn sm hq-bar-btn" data-close="' + k + '">Shrink ▴</button>' : '') + '</div>'
        + (sec.total ? '<div class="hq-prog"><i class="' + (sec.missed ? 'bad' : (pct < 100 ? 'mid' : '')) + '" style="width:' + pct + '%"></i></div>' : '')
        + (sec.people.length ? sec.people.map((p) => person(p, sec)).join('') : '<div class="hq-empty">Nobody is in this section yet. <a href="boss_engine.html?tab=people">Choose people</a></div>')
        + unowned + empty + wrapBox(sec) + '</div>';
    }
    function render() {
      if (!data) return;
      const secs = data.sections;
      if (!secs.length) { el.innerHTML = opts.section ? '' : '<div class="hq-sec"><div class="hq-empty">No sections yet.</div></div>'; return; }
      // jobs still to do first (the server already sorts them), finished ones as green lines at the bottom
      if (opts.section) { el.innerHTML = secs.map(secCard).join(''); return; }
      const busy2 = secs.filter((x) => x.total > 0 || x.people.length), quiet = secs.filter((x) => !(x.total > 0 || x.people.length));
      el.innerHTML = '<div class="hq-head" style="margin:4px 2px 10px"><h3 style="font-size:19px;margin:0">Who has what today</h3><span class="bd-sub">Every person, every section. Tick a job and it shrinks away.</span></div>'
        + busy2.map(secCard).join('') + (quiet.length ? '<div class="bd-sub" style="margin:2px 4px 12px;line-height:1.7">Nobody chosen and no jobs yet: ' + quiet.map((x) => esc(x.name)).join(', ') + '. <a href="boss_engine.html?tab=people">Choose people</a></div>' : '');
    }
    async function load() {
      try { data = await api.get('dp.hq_track', opts.section ? { section: opts.section } : {}); render(); if (opts.onLoad) opts.onLoad(data); }
      catch (e) { if (/management pages|403|not allowed/i.test(String(e.message || ''))) { el.innerHTML = ''; return; } el.innerHTML = '<div class="hq-sec"><div class="hq-empty">Cannot load the people board: ' + esc(e.message) + '</div></div>'; }
    }
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-done],[data-ack],[data-dn],[data-open],[data-close]'); if (!b || busy) return;
      try {
        if (b.dataset.dn) { open['dn' + b.dataset.dn] = !open['dn' + b.dataset.dn]; render(); }
        else if (b.dataset.open) { open[b.dataset.open] = true; render(); }
        else if (b.dataset.close) { open[b.dataset.close] = false; render(); }
        else if (b.dataset.done) {
          busy = true; const [kind, id] = b.dataset.done.split(':'); b.disabled = true;
          const r = await api.post('dp.hq_done', { kind, id: Number(id) });
          const row = b.closest('.hq-job'); if (row) row.classList.add('shrink');
          BD.toast(r.message || 'Done.'); setTimeout(async () => { busy = false; await load(); }, 380);
        } else if (b.dataset.ack) {
          busy = true; const note = (el.querySelector('[data-note="' + b.dataset.ack + '"]') || {}).value || '';
          const r = await api.post('dp.hq_ack', { section: b.dataset.ack, note }); BD.toast(r.message || 'Closed.'); busy = false; await load();
        }
      } catch (er) { busy = false; BD.toast(er.message, true); if (b) b.disabled = false; }
    });
    load();
    timer = setInterval(() => { if (!document.hidden && !busy) load(); }, 45000);
    return { reload: load, stop: () => clearInterval(timer), data: () => data };
  }
  return { mount };
})();
