/**
 * wq_admin.js — the admin screens of WhatsApp Sales (window.WaQAdmin). Page: wa_sales_live.html. Needs wq_ui.js (window.WaQ) and api.js.
 *   WaQAdmin.mount(el, { topEl, subEl, tab })   tabs: Live | Attention | Types | Bot vs person | Analytics | Settings
 * Calls only wq.* (see Q_J_api.md): board, attention, assign, on_duty, types_state, types_next, types_answer, rules, compare, whatwon, whatwon_act,
 * analytics, settings, cutover. Every fetch has its own error state with "Try again"; nothing blocks on a failed endpoint.
 * Live auto-refreshes every 60 s only while it is the open tab and the page is visible. No polling in the other tabs.
 */
(function () {
  'use strict';
  if (window.WaQAdmin || !window.WaQ) return;
  const Q = window.WaQ, U = Q.u, esc = U.esc, num = U.num, has = U.has, fmtMin = U.fmtMin, money = U.money, ico = U.ico, pill = U.pill;
  const TYPES = U.TYPES;
  const ANCH = { meta: 'meta', campaign: 'camp', community: 'comm', regenerated: 'regen', new: 'new' };
  const MORE_CSS = `
.wq-svg .f-meta{fill:var(--q-mf)}.wq-svg .f-campaign{fill:var(--q-cf)}.wq-svg .f-community{fill:#e0791a}.wq-svg .f-regenerated{fill:#16a34a}.wq-svg .f-new{fill:var(--q-nf)}.wq-svg .f-other{fill:#94a3b8}.wq-svg .f-unclear{fill:#cbd5e1}
.wq-svg .f-b0{fill:#16a34a}.wq-svg .f-b1{fill:#84cc16}.wq-svg .f-b2{fill:#f2b33d}.wq-svg .f-b3{fill:#f97316}.wq-svg .f-b4{fill:#d9423b}
.wq-svg .f-acc{fill:var(--q-acc)}.wq-svg .f-bad{fill:#d9423b}.wq-svg .f-ink{fill:var(--q-btn)}
.wq-svg text.v{fill:var(--q-ink);font-weight:700}
.wq-narrow{max-width:760px}
.wq-lgi{width:9px;height:9px;border-radius:3px;display:inline-block}
.wq-btn.sm.on{background:var(--q-btn);color:var(--q-btnfg)}
.wq-big{font-size:34px;font-weight:800;letter-spacing:-.02em;line-height:1.1}
.wq-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wq-two>.wq-card{margin-bottom:0}
@media (max-width:820px){.wq-two{grid-template-columns:1fr}}
.wq-wrap{padding-bottom:8px}
`;
  const REASON = { price: 'Price', no_interest: 'Not interested', no_reply: 'Stopped replying', other: 'Other' };
  const BUCKET_CLS = ['f-b0', 'f-b1', 'f-b2', 'f-b3', 'f-b4'];
  const BUCKET_COL = ['#16a34a', '#84cc16', '#f2b33d', '#f97316', '#d9423b'];
  const TYPE_COL = { meta: '#2563eb', campaign: '#5b3fd6', community: '#e0791a', regenerated: '#16a34a', new: '#0f6e7e', other: '#94a3b8', unclear: '#cbd5e1' };
  const LINE_COL = ['#2563eb', '#16a34a', '#e11d48', '#d97706', '#8b5cf6', '#0d9488', '#be185d', '#64748b'];
  const dayShort = (s) => { const d = U.parse(String(s) + ' 00:00:00'); return d ? d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : String(s); };
  const niceMax = (m) => { if (m <= 4) return 4; const p = Math.pow(10, Math.floor(Math.log10(m))); const f = m / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; };

  function mount(el, opt) {
    opt = opt || {}; Q.css(); U.root(el); el.classList.add('wq-wrap');
    if (!document.getElementById('wqCss2')) { const s = document.createElement('style'); s.id = 'wqCss2'; s.textContent = MORE_CSS; document.head.appendChild(s); }
    const S = { tab: 'live', days: 1, anDays: 30, board: null, boardErr: null, attn: null, attnErr: null, duty: null, types: null, typesErr: null, typesTab: null, an: null, anErr: null, anMetric: 'queries', cmpType: 'all', cmpDays: 7, won: 'new', skipped: [], confirmedUi: false };
    let seq = 0, liveTimer = 0, rsz = 0;
    el.innerHTML = '<div id="wqTabs" class="wq-tabs" role="tablist"></div><div id="wqBody"></div>';
    const tabsEl = el.querySelector('#wqTabs'), body = el.querySelector('#wqBody');
    const TABS = [['live', 'Live'], ['attention', 'Attention'], ['types', 'Types'], ['compare', 'Bot vs person'], ['analytics', 'Analytics'], ['settings', 'Settings']];
    const get = (a, p) => U.call(opt, a, p); const post = (a, b) => U.post(opt, a, b);
    const attnCount = () => S.attn ? num(S.attn.count, (S.attn.items || []).length) : 0;
    const when = (...a) => U.when(...a);

    function drawTabs() {
      tabsEl.innerHTML = TABS.map((t) => '<button type="button" role="tab" aria-selected="' + (S.tab === t[0]) + '" data-t="' + t[0] + '" class="' + (S.tab === t[0] ? 'on' : '') + '">' + t[1] + (t[0] === 'attention' && attnCount() > 0 ? '<b>' + attnCount() + '</b>' : '') + (t[0] === 'types' && S.types && num(S.types.state && S.types.state.pending) > 0 ? '<b style="background:#d97706">' + num(S.types.state.pending) + '</b>' : '') + '</button>').join('');
      drawTop();
      if (opt.onBadge) try { opt.onBadge(attnCount()); } catch (e) { /* optional */ }
    }
    function drawTop() {
      const top = opt.topEl; if (!top) return; const list = S.tab === 'analytics' ? [7, 30, 90] : (S.tab === 'live' || S.tab === 'compare') ? (S.tab === 'live' ? [1, 7, 30] : [1, 7, 30]) : null;
      if (!list) { top.innerHTML = ''; return; }
      const cur = S.tab === 'analytics' ? S.anDays : (S.tab === 'compare' ? S.cmpDays : S.days);
      top.innerHTML = '<div class="wq wq-seg" role="group" aria-label="Period">' + list.map((d) => '<button type="button" data-d="' + d + '" class="' + (cur === d ? 'on' : '') + '">' + (d === 1 ? 'Today' : d + ' days') + '</button>').join('') + '</div>';
    }
    if (opt.topEl) opt.topEl.addEventListener('click', (e) => {
      const b = e.target.closest('[data-d]'); if (!b) return; const d = +b.dataset.d;
      if (S.tab === 'analytics') { S.anDays = d; S.an = null; } else if (S.tab === 'compare') { S.cmpDays = d; } else { S.days = d; S.board = null; }
      drawTop(); render(false);
    });
    tabsEl.addEventListener('click', (e) => { const b = e.target.closest('[data-t]'); if (b) goto(b.dataset.t); });
    function goto(t) {
      if (!TABS.some((x) => x[0] === t)) t = 'live'; S.tab = t; try { history.replaceState(null, '', location.pathname + (t === 'live' ? '' : '?tab=' + t) + (/[?&]in=sales/.test(location.search) ? (t === 'live' ? '?' : '&') + 'in=sales' : '')); } catch (e) { /* iframe */ }
      drawTabs(); render(false);
    }
    body.addEventListener('click', (e) => { if (e.target.closest('[data-wq-retry]')) render(false); });

    // ---------------------------------------------------------------- shared loads
    function loadAttn() { return get('wq.attention').then((d) => { S.attn = d; S.attnErr = null; }, (e) => { S.attnErr = e; }); }
    function loadBoard() { return get('wq.board', { days: S.days }).then((d) => { S.board = d; S.boardErr = null; }, (e) => { S.boardErr = e; }); }
    const holderNote = (it) => {
      const h = it.holder || {}; if (!h.emp_id) return 'Nobody holds it';
      const p = S.board && (S.board.people || []).find((x) => Number(x.emp_id) === Number(h.emp_id)); const st = p && p.duty ? p.duty.state : '';
      const w = st && st !== 'on' ? U.dutyWords(st) : 'not replying';
      return 'Held by <b>' + esc(h.name || 'someone') + '</b> (' + esc(w) + ')';
    };
    function aqCard(it) {
      return '<div class="wq-aq" data-q="' + esc(it.phone) + '"><div class="h"><span class="wq-av wq-a' + U.hue(it.phone) + '">' + esc(U.initials(it.name, it.phone)) + '</span><span class="nm"><b>' + esc(it.name || U.pretty(it.phone)) + '</b>' + pill(it.type, it.type_label) + '</span><span class="wait">' + Math.round(num(it.waiting_min)) + ' min<small>waiting</small></span></div>'
        + '<div class="msg">' + esc(it.last_in || 'No message text') + '</div><div class="meta">' + holderNote(it) + ' · ' + num(it.auto_msgs) + ' auto ' + (num(it.auto_msgs) === 1 ? 'message' : 'messages') + ' sent</div>'
        + '<button type="button" class="wq-pick" data-assign="' + esc(it.phone) + '"><span>Assign to...</span>' + ico('down', 's') + '</button></div>';
    }
    function attnStrip(compact) {
      if (S.attnErr) return '<div class="wq-card wq-attn">' + U.errBox('Attention needed', S.attnErr) + '</div>';
      const items = (S.attn && S.attn.items) || [];
      if (!items.length) return compact ? '' : '<div class="wq-card"><div class="wq-empty"><b>Nothing needs you right now</b>Every chat has been answered or is held by someone who is on duty.</div></div>';
      return '<div class="wq-card wq-attn"><div class="wq-ch"><h2 style="color:var(--q-badfg)">' + ico('warn') + 'Attention needed <span class="wq-chip bad">' + items.length + '</span></h2><span class="wq-sub">Nobody answered, even after two automatic messages. Pick a person.</span></div><div class="wq-attn-grid">' + items.map(aqCard).join('') + '</div></div>';
    }
    body.addEventListener('click', (e) => { const b = e.target.closest('[data-assign]'); if (b) assignSheet(b.dataset.assign); });

    // ---------------------------------------------------------------- assign sheet
    async function assignSheet(phone) {
      const it = ((S.attn && S.attn.items) || []).find((x) => x.phone === phone); if (!it) return;
      const sh = U.sheet({ title: 'Assign ' + (it.name || U.pretty(it.phone)), sub: (U.tinfo(it.type).label) + ' query · waiting ' + Math.round(num(it.waiting_min)) + ' min · held by ' + ((it.holder || {}).name || 'nobody') });
      sh.body.innerHTML = U.skel(3); let people = [], sel = 0;
      const sug = it.suggestion || it.suggest || null; const sugId = sug ? Number(sug.emp_id) : 0;
      try {
        const [d, b] = await Promise.all([get('wq.on_duty'), S.board ? Promise.resolve(S.board) : get('wq.board', { days: 1 }).catch(() => null)]);
        const bm = {}; ((b && b.people) || []).forEach((p) => { bm[p.emp_id] = p; });
        people = (d.people || []).map((p) => Object.assign({}, p, { b: bm[p.emp_id] || null }));
      } catch (e) { sh.body.innerHTML = U.errBox('who is on duty', e); sh.body.querySelector('[data-wq-retry]').onclick = () => { sh.close(); assignSheet(phone); }; return; }
      const maxW = Math.max(3, ...people.map((p) => p.b ? num(p.b.unanswered) : 0));
      if (sugId && people.some((p) => Number(p.emp_id) === sugId && p.on_duty)) sel = sugId;
      const hold = it.holder || {};
      people.sort((a, b) => (Number(b.emp_id) === sugId ? 1 : 0) - (Number(a.emp_id) === sugId ? 1 : 0) || (b.on_duty ? 1 : 0) - (a.on_duty ? 1 : 0) || (a.b ? num(a.b.unanswered) : 0) - (b.b ? num(b.b.unanswered) : 0));
      const draw = () => {
        let h = '';
        if (sug) h += '<div class="wq-claude"><div class="hd2"><span class="spark">' + ico('spark') + '</span>Claude suggests ' + esc(sug.name || ((people.find((p) => Number(p.emp_id) === sugId) || {}).name) || 'this person') + '</div><p>' + esc(sug.why || sug.reason || '') + '</p></div>';
        h += people.map((p) => {
          const on = !!p.on_duty, b = p.b, w = b ? num(b.unanswered) : 0, ratio = Math.min(1, w / maxW);
          const line = b ? num(b.queries) + ' today, ' + w + ' waiting' + (has(b.first_reply_min) ? ' · 1st reply ' + fmtMin(b.first_reply_min) : '') : (p.why || '');
          return '<button type="button" class="wq-prow' + (sel === Number(p.emp_id) ? ' sel' : '') + (on ? '' : ' off') + '" data-e="' + p.emp_id + '"' + (on ? '' : ' disabled') + '><span class="wq-av wq-a' + U.hue(p.name) + '">' + esc(U.initials(p.name)) + '</span><span class="nm"><b>' + esc(p.name) + U.dutyChip(p.state, on) + (Number(p.emp_id) === sugId ? '<span class="wq-chip vio">Suggested</span>' : '') + '</b><small>' + esc(on ? line : 'cannot take new chats' + (p.why ? ' · ' + p.why : '')) + '</small>' + (on && b ? '<span class="wq-load"><i class="' + (ratio > .66 ? 'r' : ratio > .33 ? 'w' : '') + '" style="width:' + Math.max(8, Math.round(ratio * 100)) + '%"></i></span>' : '') + '</span><span class="wq-radio"></span></button>';
        }).join('') || '<div class="wq-empty"><b>No salespeople found</b>The on-duty list is empty.</div>';
        sh.body.innerHTML = h;
        const sp = people.find((p) => Number(p.emp_id) === sel);
        sh.foot.innerHTML = '<div class="wq-note" style="margin:0 0 10px">' + (sp && hold.emp_id ? 'Counts move: ' + esc(hold.name) + ' -1, ' + esc(sp.name) + ' +1. The history stays on the chat and it is counted once.' : 'The history stays on the chat and it is counted once.') + '</div><div id="wqAsErr" class="wq-note" style="color:var(--q-badfg)" hidden></div><button type="button" class="wq-btn go block" id="wqAsGo"' + (sel ? '' : ' disabled') + '>' + (sp ? 'Assign to ' + esc(sp.name) : 'Pick a person') + '</button>';
        sh.foot.querySelector('#wqAsGo').onclick = async function () {
          const btn = this, er = sh.foot.querySelector('#wqAsErr'); btn.disabled = true; btn.textContent = 'Assigning...'; er.hidden = true;
          try {
            await post('wq.assign', { phone, emp_id: sel });
            sh.close(); U.toast('Assigned to ' + (sp ? sp.name : 'the person'));
            if (S.attn) { S.attn.items = (S.attn.items || []).filter((x) => x.phone !== phone); S.attn.count = S.attn.items.length; }
            drawTabs(); render(true); loadAttn().then(() => { drawTabs(); if (alive()) render(true); });
          } catch (e) { er.textContent = e.message || 'Could not assign.'; er.hidden = false; btn.disabled = false; btn.textContent = 'Try again'; }
        };
      };
      sh.body.onclick = (e) => { const b = e.target.closest('[data-e]'); if (b && !b.disabled) { sel = Number(b.dataset.e); const top = sh.body.scrollTop; draw(); sh.body.scrollTop = top; } };
      draw();
    }
    const alive = () => U.alive(el);

    // ---------------------------------------------------------------- LIVE
    const statTile = (label, val, sub, cls) => '<div class="wq-stat ' + (cls || '') + '"><small>' + label + '</small><b>' + val + '</b><em>' + sub + '</em></div>';
    function cnts(p) { const bt = p.by_type || {}; return ['meta', 'campaign', 'community', 'regenerated', 'new'].map((k) => '<div class="wq-cnt ' + ANCH[k] + '"><b>' + num(bt[k]) + '</b><small>' + ({ meta: 'Meta', campaign: 'Camp.', community: 'Comm.', regenerated: 'Regen.', new: 'New' }[k]) + '</small></div>').join('') + '<div class="wq-cnt tot"><b>' + num(p.queries) + '</b><small>Total</small></div>'; }
    function statusCell(p) {
      if (p.behind && p.behind.is) { const r = (p.behind.reasons || []).join(' '); const slow = /reply|slow|minutes|min\b/i.test(r) && !/unanswered|queue/i.test(r); return '<span class="wq-chip ' + (slow ? 'warn' : 'bad') + '"><i class="dot"></i>Behind</span><div class="wq-why">' + esc((p.behind.reasons || []).join('. ')) + '</div>'; }
      return num(p.queries) > 0 ? '<span class="wq-chip ok"><i class="dot"></i>On track</span>' : '<span class="wq-chip mute">No queries</span>';
    }
    function dutyLine(p) { const st = p.duty ? p.duty.state : (p.on_duty ? 'on' : 'off'); return U.dutyChip(st, p.on_duty) + (st !== 'on' && p.duty && p.duty.why ? '' : ''); }
    function botRow(b) { return b; }
    function liveBoard(d) {
      const t = d.totals || {}, tb = t.by_type || {}, people = d.people || [], bot = d.bot || null, unas = d.unassigned && num(d.unassigned.queries) > 0 ? d.unassigned : null;
      const unclear = num(tb.unclear), typedTotal = Math.max(0, num(t.queries) - unclear);
      let h = '<div class="wq-card"><div class="wq-ch"><h2>Team board <span class="wq-sub" style="font-weight:600">' + (S.days === 1 ? 'Today' : 'Last ' + S.days + ' days') + '</span></h2><span class="wq-sub">Each query is counted once. A transfer moves it: -1 from the old holder, +1 to the new one.</span></div>';
      h += '<div class="wq-legend">' + ['meta', 'campaign', 'community', 'regenerated', 'new'].map((k) => '<span class="wq-tp ' + ANCH[k] + '">' + U.tinfo(k).label + '</span>').join('') + '</div>';
      // desktop table
      h += '<div class="wq-only-d"><table class="wq-board"><thead><tr><th>Person</th>' + ['meta', 'campaign', 'community', 'regenerated', 'new'].map((k) => '<th class="c"><i class="wq-i-' + ANCH[k] + '"></i>' + U.tinfo(k).label + '</th>').join('') + '<th class="c">Total</th><th class="c">Orders</th><th class="c">Conv.</th><th class="c">First reply</th><th class="c">Hours</th><th>Status</th></tr></thead><tbody>';
      people.forEach((p) => { const bt = p.by_type || {};
        h += '<tr><td><div class="who"><span class="wq-av s wq-a' + U.hue(p.name) + '">' + esc(U.initials(p.name)) + '</span><div><b>' + esc(p.name || 'Unknown') + '</b>' + dutyLine(p) + '</div></div></td>' + ['meta', 'campaign', 'community', 'regenerated', 'new'].map((k) => '<td class="c num">' + num(bt[k]) + '</td>').join('') + '<td class="c num"><b>' + num(p.queries) + '</b>' + (num(p.transferred_in) || num(p.transferred_out) ? '<div class="wq-sub" style="font-size:11px">+' + num(p.transferred_in) + ' / -' + num(p.transferred_out) + '</div>' : '') + '</td><td class="c num">' + num(p.orders) + '</td><td class="c num">' + U.pct(p.conversion, 0) + '</td><td class="c num">' + esc(fmtMin(p.first_reply_min)) + '</td><td class="c num">' + esc(U.fmtHrs(p.hours_worked)) + '</td><td>' + statusCell(p) + '</td></tr>'; });
      if (bot) { const bt = bot.by_type || {}; h += '<tr class="bot"><td><div class="who"><span class="wq-av s wq-a8">' + ico('bot', 's') + '</span><div><b>Bot only</b><span class="wq-sub">no person joined</span></div></div></td>' + ['meta', 'campaign', 'community', 'regenerated', 'new'].map((k) => '<td class="c num">' + num(bt[k]) + '</td>').join('') + '<td class="c num"><b>' + num(bot.queries) + '</b></td><td class="c num">' + num(bot.orders) + '</td><td class="c num">' + U.pct(bot.conversion, 0) + '</td><td class="c num">' + esc(fmtMin(bot.first_reply_min)) + '</td><td class="c num">-</td><td></td></tr>'; }
      if (unas) h += '<tr class="bot"><td><div class="who"><span class="wq-av s wq-a8">?</span><div><b>Not held by a person</b><span class="wq-sub">bot or nobody yet</span></div></div></td><td class="c" colspan="5"></td><td class="c num"><b>' + num(unas.queries) + '</b></td><td class="c">-</td><td class="c">-</td><td class="c">-</td><td class="c">-</td><td></td></tr>';
      if (!people.length && !bot && !unas) h += '<tr><td colspan="12"><div class="wq-empty"><b>No queries in this period</b>New customer chats will appear here as they come in.</div></td></tr>';
      h += '<tr class="tot"><td>All typed queries</td>' + ['meta', 'campaign', 'community', 'regenerated', 'new'].map((k) => '<td class="c num">' + num(tb[k]) + '</td>').join('') + '<td class="c num">' + typedTotal + '</td><td class="c num">' + num(t.orders) + '</td><td class="c num">' + U.pct(t.conversion, 0) + '</td><td class="c num">' + esc(fmtMin(t.first_reply_min)) + '</td><td class="c">-</td><td></td></tr></tbody></table></div>';
      // phone cards
      h += '<div class="wq-only-m">';
      if (!people.length && !bot && !unas) h += '<div class="wq-empty"><b>No queries in this period</b>New customer chats will appear here as they come in.</div>';
      people.forEach((p) => { const bh = p.behind && p.behind.is, slow = bh && /reply|slow|minutes/i.test((p.behind.reasons || []).join(' ')) && !/unanswered|queue/i.test((p.behind.reasons || []).join(' '));
        h += '<div class="wq-pc' + (bh ? (slow ? ' slow' : ' behind') : '') + '"><div class="top"><span class="wq-av wq-a' + U.hue(p.name) + '">' + esc(U.initials(p.name)) + '</span><span class="nm"><b>' + esc(p.name || 'Unknown') + '</b>' + dutyLine(p) + '</span>' + (bh ? '<span class="wq-chip ' + (slow ? 'warn' : 'bad') + '"><i class="dot"></i>Behind</span>' : (num(p.queries) > 0 ? '<span class="wq-chip ok"><i class="dot"></i>On track</span>' : '')) + '</div><div class="wq-cnts">' + cnts(p) + '</div><div class="wq-kv4"><div><b>' + num(p.orders) + '</b><small>Orders</small></div><div><b>' + U.pct(p.conversion, 0) + '</b><small>Conv.</small></div><div><b>' + esc(fmtMin(p.first_reply_min)) + '</b><small>1st reply</small></div><div><b>' + esc(U.fmtHrs(p.hours_worked)) + '</b><small>Hours</small></div></div>'
          + (num(p.transferred_in) || num(p.transferred_out) ? '<div class="wq-sub" style="margin-top:6px">Transfers +' + num(p.transferred_in) + ' in / -' + num(p.transferred_out) + ' out</div>' : '') + (bh ? '<div class="wq-why" style="max-width:none;display:flex;gap:6px">' + ico('warn', 's') + '<span>' + esc((p.behind.reasons || []).join('. ')) + '</span></div>' : '') + '</div>'; });
      if (bot) { h += '<div class="wq-pc"><div class="top"><span class="wq-av wq-a8">' + ico('bot') + '</span><span class="nm"><b>Bot only</b><span class="wq-sub">no person joined</span></span></div><div class="wq-cnts">' + cnts(bot) + '</div><div class="wq-kv4"><div><b>' + num(bot.orders) + '</b><small>Orders</small></div><div><b>' + U.pct(bot.conversion, 0) + '</b><small>Conv.</small></div><div><b>' + esc(fmtMin(bot.first_reply_min)) + '</b><small>1st reply</small></div><div><b>-</b><small>Hours</small></div></div></div>'; }
      if (unas) h += '<div class="wq-pc"><div class="top"><span class="wq-av wq-a8">?</span><span class="nm"><b>Not held by a person</b><span class="wq-sub">bot or nobody yet</span></span><span class="wq-chip">' + num(unas.queries) + ' queries</span></div></div>';
      h += '</div>';
      if (unclear > 0) h += '<div class="wq-note" style="display:flex;gap:8px;align-items:flex-start">' + ico('warn', 's') + '<span>' + unclear + ' ' + (unclear === 1 ? 'query is' : 'queries are') + ' not typed yet. ' + (unclear === 1 ? 'It waits' : 'They wait') + ' on the <a href="#" data-goto="types" style="font-weight:700;color:inherit">Types</a> page for your answer, so the typed total is ' + typedTotal + ' of ' + num(t.queries) + '.</span></div>';
      if (d.team_median && has(d.team_median.first_reply_min)) h += '<div class="wq-note">Team median first reply ' + esc(fmtMin(d.team_median.first_reply_min)) + (has(d.team_median.conversion) ? ', conversion ' + U.pct(d.team_median.conversion, 0) : '') + '.</div>';
      return h + '</div>';
    }
    body.addEventListener('click', (e) => { const g = e.target.closest('[data-goto]'); if (g) { e.preventDefault(); goto(g.dataset.goto); } });
    function drawLive() {
      let h = '';
      if (S.board && S.board.on === false) h += '<div class="wq-banner">WhatsApp Sales is switched off on the server (setting wq_on). The numbers below may be old.</div>';
      h += attnStrip(true);
      if (S.boardErr && !S.board) h += '<div class="wq-card">' + U.errBox('the team board', S.boardErr) + '</div>';
      else if (!S.board) h += '<div class="wq-card">' + U.skel(4) + '</div>';
      else {
        const t = S.board.totals || {}, q = num(t.queries);
        h += '<div class="wq-stats">' + statTile('Queries', q, S.days === 1 ? 'Today' : 'Last ' + S.days + ' days')
          + statTile('Replied', num(t.replied), q > 0 ? Math.round(100 * num(t.replied) / q) + '% of queries' : 'No queries yet', 'ok')
          + statTile('Unanswered', num(t.unanswered), attnCount() > 0 ? attnCount() + ' need you' : 'None need you', num(t.unanswered) > 0 ? 'bad' : '')
          + statTile('Orders', num(t.orders), has(t.conversion) ? U.pct(t.conversion, 0) + ' conversion' : 'No conversion yet')
          + statTile('Revenue', money(t.revenue), num(t.orders) > 0 ? money(num(t.revenue) / num(t.orders)) + ' per order' : 'No orders yet') + '</div>';
        h += liveBoard(S.board);
      }
      body.innerHTML = h;
    }
    async function renderLive(quiet) {
      const my = ++seq; if (!quiet && !S.board) drawLive();
      // the board first (it is what the page is for), then the 'needs attention' strip: two requests one after the other, not together
      await loadBoard();
      if (my !== seq || !alive()) return; drawTabs(); drawLive(); setSub();
      await loadAttn();
      if (my !== seq || !alive()) return; drawTabs(); drawLive();
    }
    function setSub() {
      if (!opt.subEl) return; const b = S.board; const d = new Date();
      opt.subEl.textContent = 'Live view of every customer query. ' + d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + ', ' + U.hhmm(d) + '. ' + (S.tab === 'live' ? 'Updates every minute.' : '');
    }

    // ---------------------------------------------------------------- ATTENTION
    async function renderAttention() {
      const my = ++seq; body.innerHTML = U.skel(3); await loadAttn(); if (my !== seq || !alive()) return; drawTabs();
      body.innerHTML = '<div class="wq-sub" style="margin:0 2px 12px">Nobody answered after two automatic messages. Assign each one to a person who is on duty.</div>' + attnStrip(false);
    }

    // ---------------------------------------------------------------- TYPES
    function ruleTypeOf(t) { const m = /counts as (\w+)|always count as (\w+)/i.exec(t || ''); const k = m ? String(m[1] || m[2]).toLowerCase() : ''; return TYPES.some((x) => x[0] === k) ? k : 'other'; }
    async function loadTypes() {
      const [st, nx, ru] = await Promise.all([get('wq.types_state').then((x) => ({ ok: x }), (e) => ({ e })), get('wq.types_next', { skip: S.skipped.join(',') }).then((x) => ({ ok: x }), (e) => ({ e })), get('wq.rules').then((x) => ({ ok: x }), (e) => ({ e }))]);
      S.types = { state: st.ok || null, stErr: st.e || null, next: nx.ok || null, nxErr: nx.e || null, rules: ru.ok ? (ru.ok.rules || []) : null, ruErr: ru.e || null };
    }
    function qCard() {
      const T = S.types; if (T.nxErr) return '<div class="wq-asks">' + U.errBox('the questions', T.nxErr) + '</div>';
      const it = T.next && T.next.item; const pend = T.next ? num(T.next.pending) : 0;
      if (!it) return '<div class="wq-asks"><div class="wq-empty"><b>No questions waiting</b>Every query is typed. When I am unsure about a new one, I ask here.</div></div>';
      const Z = S.q || (S.q = { type: '', always: true, ruleIdx: 0, qid: 0 }); if (Z.qid !== it.query_id) { Z.qid = it.query_id; Z.type = ''; Z.always = true; Z.ruleIdx = 0; }
      const ctx = it.context || []; const first = ctx.find((c) => /^First message/i.test(c)); const rest = ctx.filter((c) => c !== first);
      const fm = first ? first.replace(/^First message:\s*/i, '').replace(/^"|"$/g, '') : '';
      const ro = it.rule_options || [];
      let h = '<div class="wq-asks"><div class="wq-ch" style="margin-bottom:0"><h2 style="color:var(--q-clb)">' + ico('spark') + 'Claude asks</h2><span class="wq-chip vio">Question 1 of ' + pend + '</span></div><h3>' + esc(it.question || 'How should I count this query?') + '</h3>';
      if (fm) h += '<div class="wq-chat"><div class="wq-bub">' + esc(fm) + '<small>' + esc(when(it.opened_at)) + '</small></div></div>';
      h += '<div class="wq-sub" style="margin-bottom:8px">' + esc(it.name || U.pretty(it.phone)) + (rest.length ? '' : '') + '</div>';
      if (rest.length) h += '<ul class="wq-ctx">' + rest.map((c) => '<li>' + esc(c) + '</li>').join('') + '</ul>';
      if (it.suggestion && it.suggestion.type) h += '<div class="wq-note" style="margin:0 0 8px">Claude thinks: <b>' + esc(U.tinfo(it.suggestion.type).label) + '</b>' + (it.suggestion.why ? ' (' + esc(it.suggestion.why) + ')' : '') + '. You decide.</div>';
      h += '<div class="wq-qch" role="group" aria-label="Query type">' + (it.chips || TYPES.map((t) => ({ type: t[0], label: t[1] }))).map((c) => '<button type="button" data-ty="' + c.type + '" class="' + U.tinfo(c.type).cls + (Z.type === c.type ? ' sel' : '') + '" aria-pressed="' + (Z.type === c.type) + '">' + esc(c.label) + '</button>').join('') + '</div>';
      if (ro.length) {
        const cur = ro[Math.min(Z.ruleIdx, ro.length - 1)];
        h += '<div class="wq-sw"><div class="t">Always do this for similar queries<small>' + (Z.always ? 'Similar: ' + esc(cur.label) : 'Only this one query is typed') + '</small></div><button type="button" class="wq-tg" data-always aria-pressed="' + Z.always + '" aria-label="Always do this for similar queries"></button></div>';
        if (Z.always && ro.length > 1) h += '<select class="wq-sel" data-ruleopt aria-label="What counts as similar">' + ro.map((r, i) => '<option value="' + i + '"' + (i === Z.ruleIdx ? ' selected' : '') + '>' + esc(r.label) + '</option>').join('') + '</select>';
      }
      h += '<div id="wqAnsErr" class="wq-note" style="color:var(--q-badfg)" hidden></div><div class="wq-btnrow" style="margin-top:12px"><button type="button" class="wq-btn pri" data-save' + (Z.type ? '' : ' disabled') + '>Save answer</button><button type="button" class="wq-btn" data-skipq>Skip for now</button></div></div>';
      return h;
    }
    function typedCard() {
      const st = S.types.state; if (!st) return '';
      const tt = st.typed_today || {}, bt = tt.by_type || {}, pend = num(st.pending);
      const rows = ['meta', 'campaign', 'community', 'regenerated', 'new', 'other'].map((k) => [k, num(bt[k])]).concat([['ask', pend]]); const mx = Math.max(1, ...rows.map((r) => r[1]));
      return '<div class="wq-card"><div class="wq-ch"><h2>Typed automatically today</h2><span class="wq-sub">' + esc(new Date().toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })) + '</span></div>' + rows.map((r) => '<div class="wq-abar"><span class="nm">' + (r[0] === 'ask' ? '<span class="wq-chip gold">Asked you</span>' : pill(r[0])) + '</span><span class="bar"><i class="wq-b-' + (r[0] === 'ask' ? 'ask' : ANCH[r[0]] || 'oth') + '" style="width:' + Math.round(100 * r[1] / mx) + '%"></i></span><b class="num">' + r[1] + '</b></div>').join('') + '<div class="wq-note">' + num(tt.auto) + ' typed by rules, ' + pend + ' asked you. Nothing is typed by guessing.</div></div>';
    }
    function rulesCard() {
      const T = S.types; if (T.ruErr) return '<div class="wq-card" id="wqRules"><div class="wq-ch"><h2>Your rules</h2></div>' + U.errBox('your rules', T.ruErr) + '</div>';
      const R = T.rules || [];
      return '<div class="wq-card" id="wqRules"><div class="wq-ch"><h2>Your rules</h2><span class="wq-sub">Deleting a rule does not retype past queries.</span></div>' + (R.length ? R.map((r) => '<div class="wq-rrow"><div class="t">' + esc(r.label || (r.kind + ': ' + r.val)) + '<small>' + num(r.hits) + ' ' + (num(r.hits) === 1 ? 'query' : 'queries') + ' typed' + (r.last_hit ? ' · last ' + esc(when(r.last_hit)) : '') + (r.active === false ? ' · switched off' : '') + '</small></div><button type="button" class="wq-tg" data-rtoggle="' + r.id + '" aria-pressed="' + (r.active !== false) + '" aria-label="Rule on or off"></button><button type="button" class="wq-btn bad sm" data-rdel="' + r.id + '">Delete</button></div>').join('') : '<div class="wq-empty"><b>No rules of your own yet</b>When you answer a question with "Always do this", the rule appears here.</div>') + '<div id="wqRuErr" class="wq-note" style="color:var(--q-badfg)" hidden></div></div>';
    }
    function understoodCard() {
      const st = S.types.state; if (S.types.stErr) return '<div class="wq-qcard">' + U.errBox('what I understood', S.types.stErr) + '</div>';
      const un = st.understood || [];
      return '<div class="wq-qcard"><h2>' + ico('spark') + 'What I understood</h2><div style="font-size:14px">Every new customer message becomes one query. I type it with these rules, in this order:</div><ul class="wq-rules">' + un.map((t) => { const k = ruleTypeOf(t); return '<li>' + pill(k) + '<span>' + esc(t) + '</span></li>'; }).join('') + '</ul><div style="font-size:14px">If none of these fit, I ask you. If two fit, the first one in the list wins.</div>'
        + (st.confirmed ? '<div class="wq-btnrow" style="margin-top:12px;align-items:center"><span class="wq-chip ok" style="min-height:36px;font-size:13px">' + ico('check', 's') + 'You said this is right' + (st.confirmed_at ? ' · ' + esc(when(st.confirmed_at)) : '') + '</span><button type="button" class="wq-btn" data-notquite>Not quite, change it</button></div>' : '<div class="wq-btnrow" style="margin-top:12px"><button type="button" class="wq-btn go" data-right>' + ico('check', 's') + 'Right</button><button type="button" class="wq-btn" data-notquite>Not quite, change it</button></div>')
        + '<div id="wqNQ" class="wq-note" hidden>To change it: delete a rule of yours below, answer the next question differently, or change how many days count in <a href="#" data-goto="settings" style="font-weight:700;color:inherit">Settings</a> (campaign window ' + num(st.settings && st.settings.wq_camp_days, 3) + ' days, silent for ' + num(st.settings && st.settings.wake_days, 60) + ' days).</div></div>';
    }
    function drawTypes() {
      const T = S.types; let h = '<div class="wq-narrow">';
      const pend = T.state ? num(T.state.pending) : (T.next ? num(T.next.pending) : 0);
      if (pend > 0) h += '<div style="margin:0 0 12px"><span class="wq-chip warn" style="font-size:12.5px">' + pend + ' ' + (pend === 1 ? 'question waiting' : 'questions waiting') + '</span></div>';
      h += (T.state ? understoodCard() : '<div class="wq-qcard">' + U.errBox('what I understood', T.stErr) + '</div>') + qCard() + typedCard() + rulesCard() + '</div>';
      body.innerHTML = h; drawTabs();
    }
    async function renderTypes() { const my = ++seq; body.innerHTML = '<div class="wq-narrow">' + U.skel(4) + '</div>'; await loadTypes(); if (my !== seq || !alive()) return; drawTypes(); }
    async function afterAnswer(r) {
      if (S.types) { S.types.next = { pending: num(r.pending), item: r.next || null }; if (S.types.state) S.types.state.pending = num(r.pending); }
      try { const [ru, st] = await Promise.all([get('wq.rules'), get('wq.types_state')]); S.types.rules = ru.rules || []; S.types.state = st; S.types.ruErr = null; } catch (e) { /* the old numbers stay */ }
      if (S.tab === 'types') drawTypes();
    }
    body.addEventListener('click', async (e) => {
      if (S.tab !== 'types' || !S.types) return;
      const Z = S.q;
      const ty = e.target.closest('[data-ty]'); if (ty && Z) { Z.type = ty.dataset.ty; drawTypes(); return; }
      if (e.target.closest('[data-always]') && Z) { Z.always = !Z.always; drawTypes(); return; }
      if (e.target.closest('[data-notquite]')) { const n = body.querySelector('#wqNQ'); if (n) n.hidden = false; const r = body.querySelector('#wqRules'); if (r) r.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
      const right = e.target.closest('[data-right]'); if (right) { right.disabled = true; try { const st = await post('wq.types_state', { confirm: true }); S.types.state = st; U.toast('Thank you. Saved.'); drawTypes(); } catch (er) { right.disabled = false; U.toast(er.message || 'Could not save.', true); } return; }
      const sv = e.target.closest('[data-save]'); if (sv && Z && Z.type) {
        const it = S.types.next.item, ro = it.rule_options || []; const b = { query_id: it.query_id, type: Z.type, always: !!(Z.always && ro.length) }; if (b.always) b.rule = { kind: ro[Math.min(Z.ruleIdx, ro.length - 1)].kind, val: ro[Math.min(Z.ruleIdx, ro.length - 1)].val };
        sv.disabled = true; sv.textContent = 'Saving...';
        try { const r = await post('wq.types_answer', b); U.toast(num(r.typed, 1) > 1 ? 'Saved. ' + r.typed + ' queries typed.' : 'Saved.'); await afterAnswer(r); }
        catch (er) { sv.disabled = false; sv.textContent = 'Save answer'; const x = body.querySelector('#wqAnsErr'); if (x) { x.textContent = er.message || 'Could not save.'; x.hidden = false; } }
        return;
      }
      const sk = e.target.closest('[data-skipq]'); if (sk) { const it = S.types.next.item; sk.disabled = true; try { S.skipped.push(it.query_id); const r = await post('wq.types_answer', { query_id: it.query_id, skip: true }); await afterAnswer(r); } catch (er) { sk.disabled = false; U.toast(er.message || 'Could not skip.', true); } return; }
      const dl = e.target.closest('[data-rdel]'); if (dl) {
        if (dl.dataset.sure !== '1') { dl.dataset.sure = '1'; dl.textContent = 'Sure? Delete'; setTimeout(() => { if (dl.isConnected) { dl.dataset.sure = ''; dl.textContent = 'Delete'; } }, 4000); return; }
        dl.disabled = true; try { const r = await post('wq.rules', { op: 'delete', id: +dl.dataset.rdel }); S.types.rules = r.rules || []; U.toast('Rule deleted'); drawTypes(); } catch (er) { dl.disabled = false; const x = body.querySelector('#wqRuErr'); if (x) { x.textContent = er.message; x.hidden = false; } }
        return;
      }
      const tg = e.target.closest('[data-rtoggle]'); if (tg) { const on = tg.getAttribute('aria-pressed') !== 'true'; tg.disabled = true; try { const r = await post('wq.rules', { op: 'toggle', id: +tg.dataset.rtoggle, active: on }); S.types.rules = r.rules || []; drawTypes(); } catch (er) { tg.disabled = false; const x = body.querySelector('#wqRuErr'); if (x) { x.textContent = er.message; x.hidden = false; } } }
    });
    body.addEventListener('change', (e) => { const s = e.target.closest('[data-ruleopt]'); if (s && S.q) { S.q.ruleIdx = +s.value; drawTypes(); } });

    // ---------------------------------------------------------------- BOT VS PERSON
    const GROUPS = [['bot_only', 'Bot only', 'bot', 'bot kept the whole chat'], ['person_only', 'Person only', 'users', 'a person answered first'], ['bot_then_person', 'Bot then person', 'swap', 'bot first, person took over']];
    function rank(vals, key, lowerBetter) { const v = GROUPS.map((g) => [g[0], vals[g[0]] ? vals[g[0]][key] : null]).filter((x) => has(x[1])); if (v.length < 2) return {}; const sorted = v.slice().sort((a, b) => lowerBetter ? a[1] - b[1] : b[1] - a[1]); const o = {}; o[sorted[0][0]] = 'best'; if (sorted[sorted.length - 1][1] !== sorted[0][1]) o[sorted[sorted.length - 1][0]] = 'worst'; return o; }
    function compareHtml(c) {
      const g = c.groups || {}; const en = !!c.enough; const R = { fr: rank(g, 'first_reply_min', true), cv: rank(g, 'conversion', false), rv: rank(g, 'revenue_per_query', false), st: rank(g, 'stopped_replying', true) };
      let bestKey = ''; if (en) { let bv = -1; GROUPS.forEach((x) => { const gg = g[x[0]]; if (gg && num(gg.n) > 0 && has(gg.conversion) && gg.conversion > bv) { bv = gg.conversion; bestKey = x[0]; } }); }
      const total = GROUPS.reduce((a, x) => a + num((g[x[0]] || {}).n), 0); const tl = c.type && c.type !== 'all' ? U.tinfo(c.type).label.toUpperCase() + ' QUERIES' : 'ALL QUERIES';
      let h = '<div class="wq-card"><div class="wq-verdict"><span class="ic' + (en ? '' : ' no') + '">' + ico('trophy') + '</span><div><div class="wq-lb">Verdict · ' + tl + ', LAST ' + num(c.days, S.cmpDays) + ' DAYS, ' + total + ' QUERIES</div><p style="margin-top:4px">' + esc(c.verdict || 'No verdict yet.') + '</p>' + (en ? '' : '<div class="wq-note">The numbers below are shown, but they are too small to decide from.</div>') + '</div></div></div>';
      h += '<div class="wq-grp">' + GROUPS.map((x) => {
        const d = g[x[0]] || {}, n = num(d.n); const cls = (k, key) => (en && R[k][x[0]]) ? ' ' + R[k][x[0]] : '';
        return '<div class="wq-gc' + (bestKey === x[0] ? ' win' : '') + '">' + (bestKey === x[0] ? '<span class="ribbon">Best result</span>' : '') + '<h3>' + ico(x[2]) + x[1] + '</h3><div class="n">' + n + ' ' + (n === 1 ? 'query' : 'queries') + ' · ' + x[3] + '</div>'
          + '<div class="wq-mrow"><span>First reply time</span><div><b class="' + cls('fr') + '">' + esc(fmtMin(d.first_reply_min)) + '</b>' + (en && R.fr[x[0]] ? '<small>' + (R.fr[x[0]] === 'best' ? 'fastest' : 'slowest') + '</small>' : '') + '</div></div>'
          + '<div class="wq-mrow" style="display:block"><div style="display:flex;justify-content:space-between;align-items:baseline"><span>Conversion to order</span><b class="' + cls('cv') + '">' + U.pct(d.conversion, 0) + '</b></div><div class="wq-mbar"><i style="width:' + Math.min(100, num(d.conversion)) + '%"></i></div></div>'
          + '<div class="wq-mrow"><span>Revenue per query</span><b class="' + cls('rv') + '">' + (has(d.revenue_per_query) ? money(d.revenue_per_query) : '-') + '</b></div>'
          + '<div class="wq-mrow"><span>Handover rate</span><div><b>' + (has(d.handover_rate) ? U.pct(d.handover_rate, 0) : 'n/a') + '</b><small>' + (!has(d.handover_rate) ? 'bot never started' : (num(d.handover_rate) === 0 ? 'bot kept it' : 'of bot chats a person joined')) + '</small></div></div>'
          + '<div class="wq-mrow"><span>Stopped replying</span><div><b class="' + cls('st') + '">' + U.pct(d.stopped_replying, 0) + '</b>' + (has(d.stopped_replying) && n ? '<small>' + Math.round(n * num(d.stopped_replying) / 100) + ' of ' + n + '</small>' : '') + '</div></div></div>';
      }).join('') + '</div>';
      return h;
    }
    function wonHtml(W) {
      const cnt = W.counts || {}; const st = S.won;
      let h = '<div class="wq-card"><div class="wq-ch"><h2>' + ico('trophy') + 'What won</h2><div class="wq-seg" role="group" aria-label="Status">' + ['new', 'taught', 'ignored'].map((k) => '<button type="button" data-ws="' + k + '" class="' + (st === k ? 'on' : '') + '">' + k.charAt(0).toUpperCase() + k.slice(1) + ' ' + num(cnt[k]) + '</button>').join('') + '</div></div><div class="wq-sub" style="margin-bottom:12px">Salesperson replies that led to an order. Teach the bot only what you like. Nothing goes live without your approval.</div>';
      const items = W.items || [];
      if (!items.length) h += '<div class="wq-empty"><b>' + (st === 'new' ? 'Nothing new to review' : 'Nothing here') + '</b>' + (st === 'new' ? 'When a person\'s reply leads to an order, it shows up here.' : '') + '</div>';
      else h += '<div class="wq-won-grid">' + items.map((it) => {
        const a = U.parse(it.at), o = it.order && U.parse(it.order.at); const mins = a && o ? Math.round((o - a) / 60000) : null;
        return '<div class="wq-won" data-wid="' + it.id + '"><div class="h"><span class="wq-av s wq-a' + U.hue((it.emp || {}).name) + '">' + esc(U.initials((it.emp || {}).name)) + '</span><b>' + esc((it.emp || {}).name || 'A person') + '</b><span class="wq-sub">to ' + esc(it.name || U.pretty(it.phone)) + '</span></div><div class="h">' + pill(it.type, it.type_label) + (it.order ? '<span class="wq-chip ok">Order ' + money(it.order.amount) + (mins != null && mins >= 0 ? ' in ' + esc(fmtMin(mins)) : '') + '</span>' : '') + '</div>'
          + '<div class="wq-chat">' + (it.customer_text ? '<div class="wq-bub">' + esc(it.customer_text) + '</div>' : '') + '<div class="wq-bub out">' + esc(it.reply_text || '') + '<small>' + esc(U.clock(it.at)) + '</small></div></div>'
          + (st === 'new' ? '<button type="button" class="wq-btn go block" data-wact="teach" data-id="' + it.id + '">' + ico('bot', 's') + 'Teach the bot this</button><button type="button" class="wq-btn block" data-wact="ignore" data-id="' + it.id + '">Ignore</button>' : '') + '<div class="wq-note" data-wnote hidden style="margin:0"></div></div>';
      }).join('') + '</div>';
      return h + '</div>';
    }
    async function renderCompare() {
      const my = ++seq; body.innerHTML = U.skel(4);
      const [c, w] = await Promise.all([get('wq.compare', { days: S.cmpDays, type: S.cmpType }).then((x) => ({ ok: x }), (e) => ({ e })), get('wq.whatwon', { status: S.won, per: 20 }).then((x) => ({ ok: x }), (e) => ({ e }))]);
      if (my !== seq || !alive()) return;
      let h = '<div class="wq-sub wq-lb" style="margin:0 2px 6px">Query type</div><div class="wq-tfil" style="padding:0 0 12px" role="group" aria-label="Query type"><button type="button" data-ct="all" class="' + (S.cmpType === 'all' ? 'on' : '') + '">All types</button>' + TYPES.map((t) => '<button type="button" data-ct="' + t[0] + '" class="' + t[2] + (S.cmpType === t[0] ? ' on' : '') + '">' + t[1] + '</button>').join('') + '</div>';
      h += c.ok ? (c.ok.on === false ? '<div class="wq-banner">WhatsApp Sales is switched off on the server.</div>' : '') + compareHtml(c.ok) : '<div class="wq-card">' + U.errBox('the comparison', c.e) + '</div>';
      h += w.ok ? wonHtml(w.ok) : '<div class="wq-card"><div class="wq-ch"><h2>What won</h2></div>' + U.errBox('what won', w.e) + '</div>';
      body.innerHTML = h;
    }
    body.addEventListener('click', async (e) => {
      if (S.tab !== 'compare') return;
      const ct = e.target.closest('[data-ct]'); if (ct) { S.cmpType = ct.dataset.ct; renderCompare(); return; }
      const ws = e.target.closest('[data-ws]'); if (ws) { S.won = ws.dataset.ws; renderCompare(); return; }
      const a = e.target.closest('[data-wact]'); if (a) {
        const card = a.closest('.wq-won'), note = card.querySelector('[data-wnote]'); card.querySelectorAll('[data-wact]').forEach((b) => { b.disabled = true; }); a.textContent = a.dataset.wact === 'teach' ? 'Saving...' : 'Ignoring...';
        try { const r = await post('wq.whatwon_act', { id: +a.dataset.id, act: a.dataset.wact }); card.querySelectorAll('[data-wact]').forEach((b) => b.remove()); note.hidden = false; note.style.color = 'var(--q-okfg)'; note.textContent = r.note || (r.status === 'taught' ? 'Added as a draft. Nothing is live until you approve it.' : 'Ignored.'); setTimeout(() => { if (card.isConnected) card.style.opacity = '.6'; }, 50); }
        catch (er) { card.querySelectorAll('[data-wact]').forEach((b) => { b.disabled = false; }); a.textContent = a.dataset.wact === 'teach' ? 'Teach the bot this' : 'Ignore'; note.hidden = false; note.style.color = 'var(--q-badfg)'; note.textContent = er.message || 'Could not save.'; }
      }
    });

    // ---------------------------------------------------------------- ANALYTICS (small inline SVG, no chart library)
    const W0 = () => Math.max(280, Math.min(900, Math.floor((body.clientWidth || 360) - 40)));
    function stacked(days, W) {
      const H = 200, L = 30, B = 22, T = 8, pw = W - L - 4, ph = H - B - T; const n = days.length; if (!n) return '';
      const max = niceMax(Math.max(1, ...days.map((d) => num(d.total)))); const bw = pw / n, w = Math.max(2, bw * .72); const step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 52))));
      let s = '<svg class="wq-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Queries per day by type">';
      [0, .5, 1].forEach((f) => { const y = T + ph - ph * f; s += '<line class="ax" x1="' + L + '" x2="' + (W - 2) + '" y1="' + y + '" y2="' + y + '"/><text x="' + (L - 5) + '" y="' + (y + 3) + '" text-anchor="end">' + Math.round(max * f) + '</text>'; });
      days.forEach((d, i) => {
        const x = L + i * bw + (bw - w) / 2; let y = T + ph; const bt = d.by_type || {};
        ['meta', 'campaign', 'community', 'regenerated', 'new', 'other', 'unclear'].forEach((k) => { const v = num(bt[k]); if (v <= 0) return; const h = ph * v / max; y -= h; s += '<rect class="f-' + k + '" x="' + x.toFixed(1) + '" y="' + y.toFixed(1) + '" width="' + w.toFixed(1) + '" height="' + h.toFixed(1) + '"/>'; });
        s += '<rect x="' + (L + i * bw).toFixed(1) + '" y="' + T + '" width="' + bw.toFixed(1) + '" height="' + ph + '" fill="transparent"><title>' + esc(dayShort(d.day) + ': ' + num(d.total) + ' queries (' + TYPES.filter((t) => num(bt[t[0]]) > 0).map((t) => t[1] + ' ' + num(bt[t[0]])).join(', ') + ')') + '</title></rect>';
        if (i % step === 0) s += '<text x="' + (L + i * bw + bw / 2).toFixed(1) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(dayShort(d.day)) + '</text>';
      });
      return s + '</svg>';
    }
    function vbars(rows, W, color, fmtLabel) {
      const H = 150, L = 26, B = 20, T = 14, pw = W - L - 2, ph = H - B - T, n = rows.length; if (!n) return '';
      const max = Math.max(1, ...rows.map((r) => r.n)), bw = pw / n, w = bw * .66;
      let s = '<svg class="wq-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img">';
      s += '<line class="ax" x1="' + L + '" x2="' + (W - 2) + '" y1="' + (T + ph) + '" y2="' + (T + ph) + '"/>';
      rows.forEach((r, i) => { const h = ph * r.n / max, x = L + i * bw + (bw - w) / 2; s += '<rect class="' + (r.cls || 'f-acc') + '" x="' + x.toFixed(1) + '" y="' + (T + ph - h).toFixed(1) + '" width="' + w.toFixed(1) + '" height="' + Math.max(0, h).toFixed(1) + '" rx="3"><title>' + esc(r.label + ': ' + r.n) + '</title></rect>'; if (r.show) s += '<text x="' + (x + w / 2).toFixed(1) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(r.show) + '</text>'; if (r.n === max && max > 0) s += '<text class="v" x="' + (x + w / 2).toFixed(1) + '" y="' + (T + ph - h - 3).toFixed(1) + '" text-anchor="middle">' + r.n + '</text>'; });
      return s + '</svg>';
    }
    function lines(series, days, W, metric) {
      const H = 190, L = 32, B = 22, T = 8, pw = W - L - 6, ph = H - B - T; const n = days.length; if (n < 1) return '';
      const vals = series.map((s) => s.pts.map((p) => p[metric])); const all = [].concat(...vals).filter((v) => has(v)); const max = niceMax(Math.max(1, ...(all.length ? all : [1]))); const stepX = n > 1 ? pw / (n - 1) : 0; const step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 52))));
      let s = '<svg class="wq-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Trend per person">';
      [0, .5, 1].forEach((f) => { const y = T + ph - ph * f; s += '<line class="ax" x1="' + L + '" x2="' + (W - 2) + '" y1="' + y + '" y2="' + y + '"/><text x="' + (L - 5) + '" y="' + (y + 3) + '" text-anchor="end">' + (metric === 'first_reply_min' ? Math.round(max * f * 10) / 10 : Math.round(max * f)) + '</text>'; });
      days.forEach((d, i) => { if (i % step === 0) s += '<text x="' + (L + i * stepX).toFixed(1) + '" y="' + (H - 6) + '" text-anchor="' + (i === 0 ? 'start' : 'middle') + '">' + esc(dayShort(d)) + '</text>'; });
      series.forEach((sr, si) => { let path = '', pen = false; const col = LINE_COL[si % LINE_COL.length];
        sr.pts.forEach((p, i) => { const v = p[metric]; if (!has(v)) { pen = false; return; } const x = L + i * stepX, y = T + ph - ph * Math.min(1, Number(v) / max); path += (pen ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1); pen = true; s += '<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="2.6" fill="' + col + '"><title>' + esc(sr.name + ', ' + dayShort(days[i]) + ': ' + (metric === 'first_reply_min' ? fmtMin(v) : v)) + '</title></circle>'; });
        s += '<path d="' + path + '" fill="none" stroke="' + col + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'; });
      return s + '</svg>';
    }
    function pairBars(days, W) {
      const H = 150, L = 28, B = 22, T = 8, pw = W - L - 2, ph = H - B - T, n = days.length; if (!n) return '';
      const max = niceMax(Math.max(1, ...days.map((d) => Math.max(num(d.unanswered_end), num(d.escalated))))); const bw = pw / n, w = Math.max(2, bw * .36), step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 52))));
      let s = '<svg class="wq-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Unanswered and escalations per day">';
      [0, .5, 1].forEach((f) => { const y = T + ph - ph * f; s += '<line class="ax" x1="' + L + '" x2="' + (W - 2) + '" y1="' + y + '" y2="' + y + '"/><text x="' + (L - 5) + '" y="' + (y + 3) + '" text-anchor="end">' + Math.round(max * f) + '</text>'; });
      days.forEach((d, i) => { const x = L + i * bw + bw / 2; const a = ph * num(d.unanswered_end) / max, b = ph * num(d.escalated) / max;
        s += '<rect class="f-bad" x="' + (x - w - 0.5).toFixed(1) + '" y="' + (T + ph - a).toFixed(1) + '" width="' + w.toFixed(1) + '" height="' + a.toFixed(1) + '"/><rect class="f-ink" x="' + (x + 0.5).toFixed(1) + '" y="' + (T + ph - b).toFixed(1) + '" width="' + w.toFixed(1) + '" height="' + b.toFixed(1) + '"/><rect x="' + (L + i * bw).toFixed(1) + '" y="' + T + '" width="' + bw.toFixed(1) + '" height="' + ph + '" fill="transparent"><title>' + esc(dayShort(d.day) + ': ' + num(d.unanswered_end) + ' unanswered, ' + num(d.escalated) + ' escalated') + '</title></rect>';
        if (i % step === 0) s += '<text x="' + x.toFixed(1) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(dayShort(d.day)) + '</text>'; });
      return s + '</svg>';
    }
    const legendTypes = () => '<div class="wq-lg">' + ['meta', 'campaign', 'community', 'regenerated', 'new', 'other'].map((k) => '<span><i style="background:' + TYPE_COL[k] + '"></i>' + U.tinfo(k).label + '</span>').join('') + '<span><i style="background:' + TYPE_COL.unclear + '"></i>To decide</span></div>';
    function listOrTable(rows, cols, name) {
      // rows: [{title, vals:[..]}], cols: header labels for vals
      if (!rows.length) return '<div class="wq-empty"><b>Nothing in this period</b>' + name + ' will appear here once there are queries.</div>';
      return '<div class="wq-only-d"><table class="wq-tbl"><thead><tr><th>' + name + '</th>' + cols.map((c) => '<th>' + c + '</th>').join('') + '</tr></thead><tbody>' + rows.map((r) => '<tr><td><b>' + esc(r.title) + '</b></td>' + r.vals.map((v) => '<td class="num">' + v + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>'
        + '<div class="wq-only-m">' + rows.map((r) => '<div class="wq-li"><div class="t">' + esc(r.title) + '</div><div class="g">' + r.vals.slice(0, 4).map((v, i) => '<div><b>' + v + '</b><small>' + cols[i] + '</small></div>').join('') + '</div>' + (r.vals.length > 4 ? '<div class="wq-sub" style="margin-top:6px">' + cols.slice(4).map((c, i) => c + ' ' + r.vals[4 + i]).join(' · ') + '</div>' : '') + '</div>').join('') + '</div>';
    }
    function replyBars(name, o, buckets, target, cls) {
      if (!o || !num(o.n)) return '<div class="wq-hb"><span class="nm">' + esc(name) + '</span><span class="wq-sub">no replies yet</span></div>';
      const c = o.counts || [], tot = Math.max(1, c.reduce((a, b) => a + num(b), 0));
      return '<div class="wq-hb" title="' + esc(name) + '"><span class="nm">' + esc(name) + '</span><span class="bar" role="img" aria-label="' + esc(name + ': ' + c.map((v, i) => (buckets[i] || '') + ' ' + num(v)).join(', ')) + '">' + c.map((v, i) => '<i style="width:' + (100 * num(v) / tot).toFixed(1) + '%;background:' + BUCKET_COL[i % 5] + '"></i>').join('') + '</span><b class="num" title="Answered within ' + target + ' min">' + (has(o.within_target_pct) ? Math.round(o.within_target_pct) + '%' : '-') + '</b></div>';
    }
    function analyticsHtml(a) {
      const W = W0(); const per = (a.per_day || []).slice().sort((x, y) => String(x.day).localeCompare(String(y.day)));
      let h = '';
      if (a.on === false) h += '<div class="wq-banner">WhatsApp Sales is switched off on the server.</div>';
      h += '<div class="wq-sub" style="margin:0 2px 12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><span>' + esc(dayShort(String(a.from || '').slice(0, 10))) + ' to ' + esc(dayShort(String(a.to || '').slice(0, 10))) + '. ' + (a.cached_at ? 'Updated ' + esc(U.clock(a.cached_at)) + '. It refreshes every 5 minutes.' : '') + '</span><button type="button" class="wq-btn sm" data-anfresh>Refresh now</button></div>';
      const totalQ = per.reduce((s, d) => s + num(d.total), 0);
      h += '<div class="wq-an-grid">';
      h += '<div class="wq-card full"><div class="wq-ch"><h2>Queries per day, by type</h2><span class="wq-sub">' + totalQ + ' in this period</span></div>' + (per.length && totalQ ? stacked(per, W) + legendTypes() : '<div class="wq-empty"><b>No queries in this period</b>The chart fills in as queries come in.</div>') + '</div>';
      const hrs = []; for (let i = 0; i < 24; i++) { const f = (a.by_hour || []).find((x) => Number(x.hour) === i); hrs.push({ label: (i % 12 || 12) + (i < 12 ? ' am' : ' pm'), n: f ? num(f.n) : 0, show: i % 3 === 0 ? String(i % 12 || 12) + (i < 12 ? 'a' : 'p') : '' }); }
      const hmax = Math.max(...hrs.map((x) => x.n)), peak = hmax > 0 ? hrs.find((x) => x.n === hmax) : null;
      h += '<div class="wq-card"><div class="wq-ch"><h2>When customers write</h2><span class="wq-sub">Hour of day' + (peak ? ', busiest ' + esc(peak.label) : '') + '</span></div>' + (hmax > 0 ? vbars(hrs, Math.max(280, Math.floor(W / (W > 600 ? 2 : 1) - (W > 600 ? 10 : 0)))) : '<div class="wq-empty"><b>No data yet</b></div>') + '</div>';
      const wd = (a.by_weekday || []).slice().sort((x, y) => num(x.wd) - num(y.wd)).map((x) => ({ label: x.label || ('Day ' + x.wd), n: num(x.n), show: x.label || '' })); const wmax = Math.max(0, ...wd.map((x) => x.n));
      h += '<div class="wq-card"><div class="wq-ch"><h2>Which days</h2><span class="wq-sub">Day of week</span></div>' + (wmax > 0 ? vbars(wd, Math.max(280, Math.floor(W / (W > 600 ? 2 : 1) - (W > 600 ? 10 : 0)))) : '<div class="wq-empty"><b>No data yet</b></div>') + '</div>';
      const ads = a.ads || [];
      h += '<div class="wq-card full"><div class="wq-ch"><h2>Top Meta ads</h2><span class="wq-sub">By queries</span></div>' + listOrTable(ads.map((r) => ({ title: r.title || r.key || 'Unknown ad', vals: [num(r.queries), num(r.replied), num(r.orders), money(r.revenue), U.pct(r.conversion, 1)] })), ['Queries', 'Replied', 'Orders', 'Revenue', 'Conv.'], 'Ad') + '</div>';
      const cps = a.campaigns || [];
      h += '<div class="wq-card full"><div class="wq-ch"><h2>Top campaigns</h2><span class="wq-sub">Customers who replied to a campaign</span></div>' + listOrTable(cps.map((r) => ({ title: r.name || ('Campaign ' + r.camp_id), vals: [num(r.queries), num(r.orders), money(r.revenue), U.pct(r.conversion, 1)] })), ['Queries', 'Orders', 'Revenue', 'Conv.'], 'Campaign') + '</div>';
      const cm = a.community || {}, cd = (cm.per_day || []).slice().sort((x, y) => String(x.day).localeCompare(String(y.day)));
      h += '<div class="wq-card"><div class="wq-ch"><h2>Community</h2></div><div style="display:flex;gap:22px;flex-wrap:wrap;margin-bottom:8px"><div><div class="wq-big">' + num(cm.queries) + '</div><div class="wq-sub">community queries</div></div><div><div class="wq-big">' + num(cm.customers_with_tag).toLocaleString('en-IN') + '</div><div class="wq-sub">customers with the tag</div></div></div>' + (cd.length && cd.some((x) => num(x.n) > 0) ? vbars(cd.map((x, i) => ({ label: dayShort(x.day), n: num(x.n), cls: 'f-community', show: i % Math.ceil(cd.length / 5) === 0 ? dayShort(x.day) : '' })), Math.max(280, Math.floor(W / (W > 600 ? 2 : 1) - (W > 600 ? 10 : 0)))) : '<div class="wq-sub">No community queries per day to draw yet.</div>') + '</div>';
      const rp = a.reply || {}, bk = rp.buckets || ['<1m', '1-5m', '5-15m', '15-60m', '>60m'], tg = num(a.target_min, 3);
      h += '<div class="wq-card"><div class="wq-ch"><h2>How fast we reply</h2><span class="wq-sub">Share answered within ' + tg + ' min on the right</span></div>' + replyBars('Team', rp.team, bk, tg) + replyBars('Bot', rp.bot, bk, tg) + (rp.people || []).map((p) => replyBars(p.name || 'Unknown', p, bk, tg)).join('') + '<div class="wq-lg">' + bk.map((b, i) => '<span><i style="background:' + BUCKET_COL[i % 5] + '"></i>' + esc(b) + '</span>').join('') + '</div>' + (!(rp.people || []).length && !num((rp.team || {}).n) && !num((rp.bot || {}).n) ? '<div class="wq-empty"><b>No replies measured yet</b></div>' : '') + '</div>';
      const tr = a.trend || [];
      const daysSet = {}; tr.forEach((p) => (p.days || []).forEach((d) => { daysSet[d.day] = 1; })); const dl = Object.keys(daysSet).sort();
      const series = tr.map((p) => { const m = {}; (p.days || []).forEach((d) => { m[d.day] = d; }); return { name: p.name || 'Unknown', pts: dl.map((d) => ({ queries: m[d] ? num(m[d].queries) : null, orders: m[d] ? num(m[d].orders) : null, first_reply_min: m[d] && has(m[d].first_reply_min) ? Number(m[d].first_reply_min) : null })) }; });
      h += '<div class="wq-card full"><div class="wq-ch"><h2>Each person over time</h2><div class="wq-seg" role="group" aria-label="Metric">' + [['queries', 'Queries'], ['orders', 'Orders'], ['first_reply_min', 'First reply']].map((m) => '<button type="button" data-anm="' + m[0] + '" class="' + (S.anMetric === m[0] ? 'on' : '') + '">' + m[1] + '</button>').join('') + '</div></div>' + (series.length && dl.length ? lines(series, dl, W, S.anMetric) + '<div class="wq-lg">' + series.map((s, i) => '<span><i style="background:' + LINE_COL[i % LINE_COL.length] + '"></i>' + esc(s.name) + '</span>').join('') + '</div>' : '<div class="wq-empty"><b>No person has queries in this period</b></div>') + '</div>';
      const ls = a.loss || {}, lt = ls.by_type || {}, reasons = ls.reasons || ['price', 'no_interest', 'no_reply', 'other'];
      const lrows = TYPES.map((t) => [t, lt[t[0]] || null]).filter((r) => r[1] && reasons.some((k) => num(r[1][k]) > 0));
      h += '<div class="wq-card"><div class="wq-ch"><h2>Why queries were lost</h2><span class="wq-sub">' + num(ls.total) + ' in total</span></div>' + (lrows.length ? lrows.map((r) => { const tot = reasons.reduce((s, k) => s + num(r[1][k]), 0); return '<div class="wq-hb"><span class="nm">' + pill(r[0][0]) + '</span><span class="bar" role="img" aria-label="' + esc(r[0][1] + ': ' + reasons.map((k) => (REASON[k] || k) + ' ' + num(r[1][k])).join(', ')) + '">' + reasons.map((k, i) => '<i style="width:' + (100 * num(r[1][k]) / Math.max(1, tot)).toFixed(1) + '%;background:' + BUCKET_COL[[0, 2, 4, 3][i % 4]] + '"></i>').join('') + '</span><b class="num">' + tot + '</b></div>'; }).join('') + '<div class="wq-lg">' + reasons.map((k, i) => '<span><i style="background:' + BUCKET_COL[[0, 2, 4, 3][i % 4]] + '"></i>' + esc(REASON[k] || k) + '</span>').join('') + '</div><div class="wq-note">Taken from the result a person tapped when the chat ended.</div>' : '<div class="wq-empty"><b>No lost queries recorded</b>When a person ends a chat with a result like Price or Not interested, it shows here.</div>') + '</div>';
      const unt = per.reduce((s, d) => s + num(d.unanswered_end), 0), esct = per.reduce((s, d) => s + num(d.escalated), 0);
      h += '<div class="wq-card"><div class="wq-ch"><h2>Unanswered and escalations</h2><span class="wq-sub">' + unt + ' unanswered, ' + esct + ' escalated</span></div>' + (per.length ? pairBars(per, Math.max(280, Math.floor(W / (W > 600 ? 2 : 1) - (W > 600 ? 10 : 0)))) + '<div class="wq-lg"><span><i style="background:#d9423b"></i>Unanswered at day end</span><span><i style="background:var(--q-btn)"></i>Reached Attention needed</span></div>' : '<div class="wq-empty"><b>No data yet</b></div>') + '</div>';
      return h + '</div>';
    }
    async function renderAnalytics(fresh) {
      const my = ++seq; if (!S.an) body.innerHTML = U.skel(4);
      try { S.an = await get('wq.analytics', Object.assign({ days: S.anDays }, fresh ? { fresh: 1 } : {})); S.anErr = null; } catch (e) { S.anErr = e; S.an = null; }
      if (my !== seq || !alive()) return;
      body.innerHTML = S.an ? analyticsHtml(S.an) : '<div class="wq-card">' + U.errBox('the analytics', S.anErr) + '</div>';
    }
    body.addEventListener('click', (e) => {
      if (S.tab !== 'analytics') return;
      if (e.target.closest('[data-anfresh]')) { S.an = null; renderAnalytics(true); return; }
      const m = e.target.closest('[data-anm]'); if (m) { S.anMetric = m.dataset.anm; if (S.an) body.innerHTML = analyticsHtml(S.an); }
    });
    window.addEventListener('resize', () => { clearTimeout(rsz); rsz = setTimeout(() => { if (alive() && S.tab === 'analytics' && S.an) body.innerHTML = analyticsHtml(S.an); }, 250); });

    // ---------------------------------------------------------------- SETTINGS
    const GOALS = [['wq_goal_queries', 'Queries per person per day', 'The goal shown on each salesperson\'s My WhatsApp card.'], ['wq_goal_orders', 'Orders per person per day', 'Leave empty to hide the orders bar.'], ['wq_goal_reply_min', 'First reply target (minutes)', 'How fast the first reply should be.']];
    const FIELDS = [['wq_hold_sec', 'Wait before the holding message (seconds)', 'If the holder does not reply in this time, one automatic message goes to the customer.'], ['wq_esc_min', 'Time before escalation (minutes)', 'After the holding message, wait this long, then send a second message and show it in Attention needed.'], ['wq_attr_days', 'Order attribution (days)', 'An order counts for a query when it comes within this many days.'], ['wq_camp_days', 'Campaign reply window (days)', 'A customer who replies within this many days after a campaign counts as Campaign.'], ['wq_min_n', 'Smallest sample for Bot vs person', 'Below this many queries in a group, no verdict is given.']];
    async function renderSettings() {
      const my = ++seq; body.innerHTML = '<div class="wq-narrow">' + U.skel(3) + '</div>';
      const [st, cu] = await Promise.all([get('wq.settings').then((x) => ({ ok: x }), (e) => ({ e })), get('wq.cutover').then((x) => ({ ok: x }), (e) => ({ e }))]);
      if (my !== seq || !alive()) return;
      S.set = st.ok || null; S.cut = cu.ok || null; drawSettings(st.e, cu.e);
    }
    function cutCard(cu, err) {
      if (err) return '<div class="wq-card">' + U.errBox('the cut-over', err) + '</div>';
      const state = cu.state || 'off', date = cu.cutover || ''; const today = U.parse((cu.today || '') + ' 00:00:00') || new Date(); const dt = date ? U.parse(date + ' 00:00:00') : null;
      const chip = state === 'before' ? '<span class="wq-chip info"><i class="dot"></i>Planned</span>' : state === 'on' ? '<span class="wq-chip ok"><i class="dot"></i>Live</span>' : '<span class="wq-chip mute"><i class="dot"></i>Not set</span>';
      let line = ''; if (dt) { const span = Math.max(1, (dt - today) / 864e5), total = 30; const pos = state === 'before' ? Math.max(8, Math.min(60, 100 - 100 * Math.min(span, 30) / 45)) : 50; const nowPos = state === 'before' ? Math.max(2, pos - Math.min(span, 30) / 30 * (pos - 2)) : 50; line = '<div class="wq-lb" style="display:flex;justify-content:space-between;margin-top:14px"><span style="color:#16945a">Old Sales</span><span style="color:#4f46e5">New records</span></div><div class="wq-cut"><div class="a" style="width:' + pos + '%"></div><div class="b" style="left:' + pos + '%"></div><div class="mk" style="left:' + pos + '%"></div><div class="now" style="left:' + nowPos.toFixed(1) + '%"></div><label style="left:' + nowPos.toFixed(1) + '%;color:var(--q-acc);transform:translateX(-30%)">Today, ' + esc(dayShort(cu.today)) + '</label><label style="left:' + pos + '%;transform:translateX(-50%);color:var(--q-ink);top:70px">' + esc(dayShort(date)) + '</label></div>'; }
      const prev = dt ? new Date(dt.getTime() - 864e5) : null; const fmtD = (d) => d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
      return '<div class="wq-card"><div class="wq-ch"><h2>' + ico('swap') + 'Old Sales to new</h2>' + chip + '</div><div class="wq-sub">' + (dt ? 'Old counts are read until ' + fmtD(prev) + ', new records after. They are never added together.' : 'No date is set, so the old system is untouched. Pick a date when you want the new counts to take over.') + '</div><div class="wq-lb" style="margin-top:14px">Cut-over date</div><div class="wq-field" style="margin-top:6px"><span style="display:flex;gap:10px;align-items:center">' + ico('cal') + '<input type="date" id="wqCutDate" value="' + esc(date) + '" aria-label="Cut-over date"></span>' + (dt && has(cu.days_left) && state === 'before' ? '<span class="wq-chip">in ' + num(cu.days_left) + ' days</span>' : '') + '</div>' + line
        + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0">' + (dt ? '<span class="wq-chip ok"><i class="dot"></i>Old Sales: live until ' + esc(fmtD(prev)) + '</span><span class="wq-chip info"><i class="dot"></i>New records: written quietly, for checking only</span><span class="wq-chip ok"><i class="dot"></i>Double counting: 0 days</span>' : '') + (cu.old_screens_read_only ? '<span class="wq-chip warn"><i class="dot"></i>Old typed screens are read-only</span>' : '') + '</div>'
        + '<div id="wqCutErr" class="wq-note" style="color:var(--q-badfg)" hidden></div><button type="button" class="wq-btn pri block" data-cutsave>Save date</button><div class="wq-btnrow" style="margin-top:8px"><button type="button" class="wq-btn block" data-cutprev>Preview both side by side</button>' + (date ? '<button type="button" class="wq-btn bad block" data-cutclear>Clear the date</button>' : '') + '</div>'
        + '<div class="wq-note">Reports that cross the date show two clearly labelled parts' + (dt ? ': "Old Sales, to ' + esc(fmtD(prev)) + '" and "New, from ' + esc(fmtD(dt)) + '"' : '') + '.' + (cu.note ? ' ' + esc(cu.note) : '') + '</div></div>';
    }
    function setCard(set, lim, err) {
      if (err) return '<div class="wq-card">' + U.errBox('the settings', err) + '</div>';
      const s = set.settings || {}, L = lim || set.limits || {}; const ed = set.editable !== false;
      const sw = (k, t, sub) => '<div class="wq-set"><div class="t">' + t + '<small>' + sub + '</small></div><button type="button" class="wq-tg" data-sw="' + k + '" aria-pressed="' + (String(s[k]) === '1') + '" aria-label="' + esc(t) + '"' + (ed ? '' : ' disabled') + '></button></div>';
      const nf = (k, t, sub) => { const r = L[k] || []; return '<div class="wq-set"><div class="t">' + t + '<small>' + sub + (r.length ? ' (' + r[0] + ' to ' + r[1] + ')' : '') + '</small></div><div class="wq-field" style="min-height:44px"><input type="number" inputmode="numeric" data-f="' + k + '" value="' + (s[k] == null ? '' : esc(s[k])) + '" ' + (r.length ? 'min="' + r[0] + '" max="' + r[1] + '"' : '') + ' aria-label="' + esc(t) + '"' + (ed ? '' : ' disabled') + '></div></div>'; };
      const goalKeys = GOALS.filter((g) => Object.prototype.hasOwnProperty.call(s, g[0]));
      return '<div class="wq-card"><div class="wq-ch"><h2>Reply flow</h2></div>' + sw('wq_on', 'WhatsApp Sales is on', 'Off = nothing is counted or sent. Screens still open.') + sw('wq_auto_on', 'Automatic holding and second messages', 'While off, nothing is ever sent automatically; queries still appear in Attention needed. Read the two texts below before you switch it on.') + FIELDS.map((f) => nf(f[0], f[1], f[2])).join('') + '</div>'
        + '<div class="wq-card"><div class="wq-ch"><h2>Messages to the customer</h2></div><div class="wq-lb">Holding message</div><textarea class="wq-in" data-f="wq_hold_text" maxlength="400" aria-label="Holding message"' + (ed ? '' : ' disabled') + '>' + esc(s.wq_hold_text || '') + '</textarea><div class="wq-note" style="margin-top:2px">First automatic message. No promises. Up to 400 characters.</div><div class="wq-lb" style="margin-top:12px">Second message (escalation)</div><textarea class="wq-in" data-f="wq_esc_text" maxlength="400" aria-label="Second message"' + (ed ? '' : ' disabled') + '>' + esc(s.wq_esc_text || '') + '</textarea></div>'
        + '<div class="wq-card"><div class="wq-ch"><h2>Goals</h2><span class="wq-sub">Shown as progress bars on each salesperson\'s My WhatsApp card</span></div>' + (goalKeys.length ? goalKeys.map((g) => nf(g[0], g[1], g[2])).join('') : '<div class="wq-note" style="margin:0">Goals are not available on the server yet.</div>') + '</div>'
        + '<div id="wqSetMsg" class="wq-note" hidden></div>' + (ed ? '<button type="button" class="wq-btn pri block" data-setsave style="margin-bottom:14px">Save settings</button>' : '<div class="wq-note">Only an admin can change these.</div>');
    }
    function drawSettings(stErr, cuErr) {
      body.innerHTML = '<div class="wq-narrow">' + (S.cut || cuErr ? cutCard(S.cut || {}, cuErr) : '') + (S.set || stErr ? setCard(S.set || {}, null, stErr) : '') + '</div>';
      body.querySelectorAll('[data-retry-x]').forEach(() => {});
    }
    body.addEventListener('click', async (e) => {
      if (S.tab !== 'settings') return;
      const sw = e.target.closest('[data-sw]'); if (sw) { sw.setAttribute('aria-pressed', sw.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); return; }
      const prev = e.target.closest('[data-cutprev]'); if (prev) {
        const cu = S.cut || {}, d = (body.querySelector('#wqCutDate') || {}).value || cu.cutover || ''; const dt = d ? U.parse(d + ' 00:00:00') : null; const f = (x) => x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
        const sh = U.sheet({ title: 'Reports across the date', sub: dt ? 'Cut-over ' + f(dt) : 'No date picked yet' }); sh.body.innerHTML = dt ? '<div class="wq-pc"><b>Old Sales, to ' + f(new Date(dt.getTime() - 864e5)) + '</b><div class="wq-sub">Counts typed by people on the old screens. Read as they are.</div></div><div class="wq-pc"><b>New, from ' + f(dt) + '</b><div class="wq-sub">Counts from WhatsApp itself: one query per new customer chat, counted by who holds it.</div></div><div class="wq-note">Each day is read from one side only, so nothing is counted twice.</div>' : '<div class="wq-empty"><b>Pick a date first</b>Then this shows how a report that crosses the date will read.</div>'; return;
      }
      const sv = e.target.closest('[data-cutsave]'); if (sv) { const d = (body.querySelector('#wqCutDate') || {}).value || ''; sv.disabled = true; sv.textContent = 'Saving...'; const er = body.querySelector('#wqCutErr'); er.hidden = true; try { S.cut = await post('wq.cutover', { date: d }); U.toast(d ? 'Cut-over date saved' : 'Cut-over date cleared'); drawSettings(null, null); } catch (x) { sv.disabled = false; sv.textContent = 'Save date'; er.textContent = x.message || 'Could not save.'; er.hidden = false; } return; }
      const cl = e.target.closest('[data-cutclear]'); if (cl) { cl.disabled = true; try { S.cut = await post('wq.cutover', { date: '' }); U.toast('Cut-over date cleared'); drawSettings(null, null); } catch (x) { cl.disabled = false; const er = body.querySelector('#wqCutErr'); er.textContent = x.message || 'Could not clear.'; er.hidden = false; } return; }
      const ss = e.target.closest('[data-setsave]'); if (ss) {
        const b = {}; body.querySelectorAll('[data-f]').forEach((i) => { const k = i.dataset.f; if (i.tagName === 'TEXTAREA') b[k] = i.value; else { const v = i.value.trim(); b[k] = v === '' ? (k === 'wq_goal_orders' ? null : '') : Number(v); } }); body.querySelectorAll('[data-sw]').forEach((i) => { b[i.dataset.sw] = i.getAttribute('aria-pressed') === 'true' ? 1 : 0; });
        const msg = body.querySelector('#wqSetMsg'); ss.disabled = true; ss.textContent = 'Saving...'; msg.hidden = true;
        try { S.set = await post('wq.settings', b); U.toast('Settings saved'); drawSettings(null, null); }
        catch (x) { ss.disabled = false; ss.textContent = 'Save settings'; msg.style.color = 'var(--q-badfg)'; msg.textContent = x.message || 'Could not save.'; msg.hidden = false; msg.scrollIntoView({ block: 'center' }); }
      }
    });

    // ---------------------------------------------------------------- router + timers
    function render(quiet) {
      clearInterval(liveTimer); seq++;
      if (S.tab === 'live') { renderLive(quiet); liveTimer = setInterval(() => { if (!alive()) { clearInterval(liveTimer); return; } if (U.visible() && S.tab === 'live' && !document.querySelector('.wq-ov')) renderLive(true); }, 60000); }
      else if (S.tab === 'attention') renderAttention();
      else if (S.tab === 'types') renderTypes();
      else if (S.tab === 'compare') renderCompare();
      else if (S.tab === 'analytics') renderAnalytics(false);
      else renderSettings();
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden && alive() && S.tab === 'live') renderLive(true); });
    S.tab = TABS.some((t) => t[0] === opt.tab) ? opt.tab : 'live'; drawTabs(); render(false); setSub();
    setTimeout(() => { if (!alive()) return; get('wq.types_state').then((d) => { if (!alive()) return; S.types = S.types || { state: null, next: null, rules: null }; if (!S.types.state) S.types.state = d; drawTabs(); }, () => {}); }, 2000);   // only a tab badge: after the board is on screen
    // the Types badge and the Attention badge need their numbers before those tabs are opened
    if (S.tab !== 'live') loadAttn().then(() => { if (alive()) drawTabs(); });
    return { goto, refresh: () => render(true), state: S };
  }
  window.WaQAdmin = { mount };
})();
