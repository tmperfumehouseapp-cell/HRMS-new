/* my_app.js — the salesperson's own app: Home · Chats (opens the existing WhatsApp chat app, untouched) · Earn · Prices · Me.
 * Home: my.coach (target ring, earnings, next step, quests, badges), wa.g_me (leaderboard), team.me (reply speed), play.gifts (mystery gift), ct.remark_mine (remarks), flash_offer.get.
 * Earn: my.coach (today / month), salary.detail, my_penalties.list.  Prices: pc.get.  Every call is the salesperson's OWN data. */
(function () {
  'use strict';
  guardPage();
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } } };
  const rs = (n) => '₹' + Math.round(+n || 0).toLocaleString('en-IN');

  // ---------------------------------------------------------------- tabs
  const TABS = ['home', 'earn', 'prices', 'me'];
  function go() {
    const h = (location.hash || '#home').slice(1); const t = TABS.indexOf(h) > -1 ? h : 'home';
    document.querySelectorAll('.s').forEach((s) => s.classList.toggle('on', s.id === t));
    document.querySelectorAll('nav a[data-s]').forEach((a) => a.classList.toggle('on', a.dataset.s === t));
    if (t === 'prices') loadPrices(); if (t === 'home') loadHome(); if (t === 'earn') loadEarn();
    $('bk').hidden = !(t === 'prices' && basket.length);
    window.scrollTo(0, 0);
  }
  addEventListener('hashchange', go);

  // ---------------------------------------------------------------- helpers
  const toast = (m) => { const t = document.createElement('div'); t.className = 'toast'; t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 2600); };
  const ok = (p) => (p && p.status === 'fulfilled') ? p.value : null;
  const on = (k, d) => (store.get(k) == null ? d : store.get(k) === '1');
  let actx = null;
  function beep() { if (!on('wa_sound', true)) return; try { actx = actx || new (window.AudioContext || window.webkitAudioContext)(); [660, 880, 1100].forEach((f, i) => { const o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = f; o.connect(g); g.connect(actx.destination); const t = actx.currentTime + i * 0.11; g.gain.setValueAtTime(0.15, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.18); o.start(t); o.stop(t + 0.2); }); } catch (e) { /* no audio */ } }
  function celebrate(msg) {
    if (on('ma_vibe', true)) { try { navigator.vibrate && navigator.vibrate([40, 40, 80]); } catch (e) { /* no vibration */ } }
    beep(); const c = document.createElement('div'); c.className = 'conf';
    c.innerHTML = Array.from({ length: 22 }, (_, i) => '<i style="left:' + Math.round(Math.random() * 96) + '%;animation-delay:' + (Math.random() * 0.5).toFixed(2) + 's">' + ['🎉', '✨', '💚', '🟢', '⭐'][i % 5] + '</i>').join(''); document.body.appendChild(c); setTimeout(() => c.remove(), 2600); if (msg) toast(msg);
  }

  // ---------------------------------------------------------------- Home
  let H = { orders: null, com: null, busy: false, t: 0 };
  async function loadHome(force) {
    if (H.busy || (!force && Date.now() - H.t < 25000)) return; H.busy = true;
    const [c, g, t, pg, rm, fo, st] = await Promise.allSettled([api.get('my.coach'), api.get('wa.g_me'), api.get('team.me'), api.get('play.gifts'), api.get('ct.remark_mine'), api.get('flash_offer.get'), api.get('st_my.today')]);
    H.busy = false; H.t = Date.now(); const C = ok(c), G = ok(g), T = ok(t), P = ok(pg), R = ok(rm), F = ok(fo), ST = ok(st);
    if (!C && ST && ST.is_store) { drawStoreOnly(ST); return; }
    if (!C) { $('homeBody').innerHTML = '<div class="card"><div class="err">' + esc((c.reason && c.reason.message) || 'Could not load your day.') + '</div><button type="button" class="btn" id="homeRetry">Try again</button></div>'; return; }
    drawHome(C, G, T, P, R, F, ST);
  }
  let HD = {};   // what the tiles open in a sheet
  function sheet(title, html) {
    const sh = document.createElement('div'); sh.className = 'sheet sm'; sh.innerHTML = '<div class="hd"><span>' + esc(title) + '</span><button type="button" class="btn" data-shut style="min-height:40px">Close</button></div><div class="bd">' + html + '</div>'; document.body.appendChild(sh);
    sh.addEventListener('click', (e) => { if (e.target.closest('[data-shut]') || e.target === sh) sh.remove(); });
  }
  // 🏬 store staff: today's store sales, commission (≈), pay and bills (st_my.today) — moved from the old My Sales page
  function storeHtml(d) {
    const p = d.punch, pay = d.pay;
    return '<div class="card" style="padding:12px 14px"><div class="h3">🏬 Store sales today <span class="' + (p ? 'ok' : 'todo') + '" style="font-size:12px">' + (p ? 'In ' + esc(p.in) + (p.out ? ' · out ' + esc(p.out) : '') : 'Not punched in') + '</span></div><div class="mut" style="margin-bottom:8px">Store day ' + esc(d.day) + ' · a new day starts at ' + esc(d.day_start) + ' AM</div>'
      + '<div class="tiles c2" style="margin-bottom:8px"><div class="tl t-g"><div class="b">' + rs(d.sales) + '</div><div class="l">Sales</div><div class="s2">' + esc(d.bills_n) + ' bill' + (d.bills_n === 1 ? '' : 's') + ' · ' + esc(d.ml) + ' ml</div></div><div class="tl t-b"><div class="b">' + rs(d.commission) + '</div><div class="l">My commission (≈)</div><div class="s2">from the price chart</div></div>'
      + (pay ? '<div class="tl"><div class="b">' + rs(pay.today ? pay.today.amount : 0) + '</div><div class="l">Today\'s pay</div><div class="s2">' + (pay.today ? esc(pay.today.status) : 'no punch yet') + '</div></div><div class="tl"><div class="b">' + rs(pay.net_pay) + '</div><div class="l">Salary this month</div><div class="s2">' + (pay.penalty_total ? '− ' + rs(pay.penalty_total) + ' penalties' : 'no penalties') + '</div></div>' : '') + '</div>'
      + ((d.bills || []).length ? d.bills.slice(0, 8).map((b) => '<div class="erow"><span><b>' + esc(b.bill_no) + '</b> · ' + esc(b.time) + ' · ' + esc(b.ml) + ' ml' + (b.pct != null ? ' · ' + esc(b.pct) + '%' : '') + '</span><span><b>' + rs(b.total) + '</b> <span class="mut">+' + rs(b.commission) + '</span></span></div>').join('') : '<div class="mut">No store bills yet today.</div>') + '</div>';
  }
  function drawStoreOnly(d) { $('hiSub').textContent = 'Your store day'; $('hiStreak').hidden = true; $('homeBody').innerHTML = storeHtml(d); }
  function drawHome(C, G, T, P, R, F, ST) {
    const m = C.me; const body = $('homeBody');
    if (!m) { body.innerHTML = '<div class="card"><div class="h3">🏠 Home</div><p class="mut" style="margin:0">Your sales numbers will show here as soon as you are set up as a salesperson.</p></div>'; return; }
    const pct = m.pct == null ? 0 : Math.max(0, Math.min(100, +m.pct)), R0 = 44, CIRC = 2 * Math.PI * R0, off = CIRC * (1 - pct / 100);
    const should = m.should_pct, ahead = should != null && m.pct != null && m.pct >= should, y = +m.yest_same || 0, bet = y > 0 && m.sales > y;
    const q = C.qinfo, tdy = T && T.periods && T.periods.today, spd = tdy && tdy.speed_median_min_30d, avgT = C.team && C.team.avg_ticket;
    // a new order since the last look: sound, vibration and confetti
    if (H.orders != null && m.orders > H.orders) celebrate('New order! ' + m.orders + ' today 🎉'); H.orders = m.orders;
    const comNow = +m.com_today || 0, comUp = (H.com != null && comNow > H.com) ? comNow - H.com : 0; H.com = comNow;
    $('hiSub').textContent = m.target > 0 ? (m.sales >= m.target ? 'Target done! Every extra order is a bonus 🏆' : ahead ? 'You are ahead of pace today' : 'Keep going, you can catch up') : 'No target set for today';
    if (m.streak > 0) { $('hiStreak').hidden = false; $('hiStreak').textContent = '🔥 ' + m.streak; } else $('hiStreak').hidden = true;
    const next = (C.next && String(C.next)) || (C.goal && C.goal.mode === 'done' ? 'Target done — every order from here is extra' : (C.tips && C.tips[0] && C.tips[0].title) || 'Take the next chat 💬');
    const Q = [];
    if (m.target > 0) Q.push({ ic: '🎯', t: 'Today\'s target', ok: m.pct >= 100, v: (m.pct == null ? 0 : m.pct) + '%', h: m.pct >= 100 ? 'Done' : (should != null ? 'should be ' + should + '%' : '') });
    if (m.code_pct != null) Q.push({ ic: '🔑', t: 'Order codes', ok: !!m.code_ok, v: m.code_pct + '%', h: m.code_ok ? 'Goal reached' : (m.code_left > 0 ? m.code_left + ' more with a code' : '') });
    if (q && q.goal_orders != null) Q.push({ ic: '📞', t: 'Query orders', ok: q.need_more === 0, v: q.query_orders + ' / ' + q.goal_orders, h: q.need_more > 0 ? q.need_more + ' more to reach the goal' : 'Goal reached' });
    if (spd != null) Q.push({ ic: '⚡', t: 'Reply within 5 min', ok: spd <= 5, v: spd + ' min', h: 'median today' });
    if (m.ticket > 0 && avgT > 0) Q.push({ ic: '💎', t: 'Ticket above the team average', ok: m.ticket >= avgT, v: rs(m.ticket), h: 'team ' + rs(avgT) });
    const done = Q.filter((x) => x.ok).length, todo = Q.filter((x) => !x.ok);
    const lb = (G && G.board && G.board.today) || [], top = lb.slice(0, 5), mei = lb.findIndex((r) => r.me), myRank = mei > -1 ? mei + 1 : (G && G.stats && G.stats.rank) || null;
    const gifts = P && P.gifts || [], rem = (R && R.remarks) || [], fl = ((F && F.running) || [])[0];
    const medals = ((tdy || (T && T.periods && T.periods.month) || {}).medals || []).concat((C.won || []).map((w) => w[0] + ' ' + w[1]));
    HD.q = '<div>' + Q.map((x) => '<div class="q"><div class="ic">' + x.ic + '</div><div class="m"><b>' + esc(x.t) + '</b>' + (x.h ? '<span>' + esc(x.h) + '</span>' : '') + '</div><span class="' + (x.ok ? 'ok' : 'todo') + '">' + esc(x.v) + (x.ok ? ' ✔' : '') + '</span></div>').join('') + '</div>';
    const lbRow = (r, i) => '<div class="lb' + (r.me ? ' me' : '') + '"><div class="n">' + (['🥇', '🥈', '🥉'][i] || (i + 1)) + '</div><div class="av">' + esc(String(r.name || '?').charAt(0).toUpperCase()) + '</div><div class="nm">' + esc(r.name) + (r.me ? ' (you)' : '') + '</div><div class="v">' + esc(r.n) + '</div></div>';
    HD.l = '<div>' + (top.length ? top.map(lbRow).join('') : '<div class="mut">No orders yet today. Be the first! 🚀</div>') + (mei >= 5 ? lbRow(lb[mei], mei) : '') + (mei < 0 && myRank ? '<div class="lb me"><div class="n">' + esc(myRank) + '</div><div class="av">Y</div><div class="nm">Your rank</div><div class="v">' + esc(m.orders) + '</div></div>' : '') + '</div>';
    HD.m = '<div class="med">' + (medals.length ? medals.map((x) => '<span>' + esc(x) + '</span>').join('') : '<span>Medals appear here as you earn them</span>') + '</div>';
    const T1 = (cls, tap, ic, big, lab, sub) => '<div class="tl ' + cls + '"' + (tap ? ' data-tile="' + tap + '" role="button" tabindex="0"' : '') + '><div class="ic">' + ic + '</div><div class="b">' + big + '</div><div class="l">' + lab + '</div>' + (sub ? '<div class="s2">' + sub + '</div>' : '') + '</div>';
    body.innerHTML =
      (rem.length ? rem.map((r) => '<div class="rmk"><small>💬 Remark from ' + esc(r.from_name || 'your sales head') + '</small>' + esc(r.body || '') + '<div><button type="button" class="btn" data-seen="' + esc(r.id) + '">Got it</button></div></div>').join('') : '')
      + (fl ? '<div class="fl">⚡ Flash offer' + (fl.name ? ': ' + esc(fl.name) : '') + ' · ends ' + esc(String(fl.ends_at || '').slice(11, 16)) + '</div>' : '')
      + '<div class="card" style="padding:12px 14px"><div class="ring"><svg width="68" height="68" viewBox="0 0 104 104" aria-hidden="true"><circle cx="52" cy="52" r="' + R0 + '" fill="none" stroke="#e6e9ee" stroke-width="11"/><circle cx="52" cy="52" r="' + R0 + '" fill="none" stroke="#16a34a" stroke-width="11" stroke-linecap="round" stroke-dasharray="' + CIRC.toFixed(1) + '" stroke-dashoffset="' + off.toFixed(1) + '" transform="rotate(-90 52 52)"/><text x="52" y="60" text-anchor="middle" font-size="26" font-weight="800" fill="currentColor">' + (m.target > 0 ? Math.round(pct) + '%' : '—') + '</text></svg>'
      + '<div class="t"><b>' + rs(m.sales) + (bet ? ' <span class="up">▲ vs yesterday</span>' : '') + '</b><span class="mut">' + (m.target > 0 ? 'Target ' + rs(m.target) : 'No target today') + '</span><div class="bar"><i style="width:' + pct + '%"></i></div></div></div></div>'
      + '<div class="card next" style="padding:12px 14px"><div style="flex:1;min-width:0"><small>YOUR NEXT STEP</small><b>' + esc(next) + '</b></div><a class="btn w" href="my_chats.html">Chats ▸</a></div>'
      + '<div class="tiles">'
      + T1('t-g', '', '💰', rs(comNow), 'Earned today', comUp ? '<span class="up">+' + rs(comUp) + ' just now</span>' : 'commission')
      + T1('t-b', '', '🧾', esc(m.orders), 'Orders today', (m.ticket > 0 ? rs(m.ticket) + ' ticket' : '') + (q && q.conv != null ? ' · ' + q.conv + '% q→o' : ''))
      + T1('t-o', 'q', '🎯', done + '/' + Q.length, 'Quests', todo.length ? esc(todo[0].t) + ' · ' + esc(todo[0].v) : 'All done! 🎉')
      + T1('t-p', 'l', '🏆', myRank ? '#' + esc(myRank) : '—', 'Leaderboard', top.slice(0, 3).map((r, i) => ['🥇', '🥈', '🥉'][i] + ' ' + esc(String(r.name).split(' ')[0]) + ' ' + esc(r.n)).join('<br>') || 'No orders yet')
      + (gifts.length ? T1('t-y', '', '🎁', gifts.length, 'Mystery gift' + (gifts.length > 1 ? 's' : ''), '<button type="button" class="btn dk sm" data-gift="' + esc(gifts[0].id) + '">Open</button>') : T1('t-y', '', '🎮', '—', 'Mystery gift', esc((P && P.earn) || 'Earn one with a good order')))
      + T1('t-r', 'm', '🔥', esc(m.streak || 0), 'Day streak', medals.length ? esc(medals.length) + ' medals · tap' : 'keep it going')
      + '</div>'
      + (ST && ST.is_store ? storeHtml(ST) : '')
      + (C.tips && C.tips[0] ? '<div class="say">🧭 ' + esc(C.tips[0].title || '') + '</div>' : '')
      + '<details class="card" id="playBox" style="padding:12px 14px"><summary style="font-weight:800;font-size:15px;cursor:pointer;min-height:32px">🎮 Level · wins · games</summary><div id="playHost" style="margin-top:8px"></div></details>';
    const pb = $('playBox'); if (pb) pb.addEventListener('toggle', () => { if (pb.open) mountPlay(); });
  }
  // the level / gift / week / team-wins / break-game card (play_card.js, moved over unchanged from the old page)
  let playLoaded = false;
  function mountPlay() {
    const host = $('playHost'); if (!host) return;
    const go = () => { try { window.PlayCard && PlayCard.mount(host); } catch (e) { host.remove(); } };
    if (window.PlayCard) { go(); return; } if (playLoaded) return; playLoaded = true;
    const sc = document.createElement('script'); sc.src = 'assets/js/play_card.js?v=3'; sc.onload = go; sc.onerror = () => { const h = $('playHost'); if (h) h.remove(); }; document.head.appendChild(sc);
  }
  document.addEventListener('click', async (e) => {
    if (e.target.closest('#homeRetry')) { loadHome(true); return; }
    const tl = e.target.closest('[data-tile]'); if (tl && !e.target.closest('button')) { const k = tl.dataset.tile; sheet(k === 'q' ? '🎯 Today\'s quests' : k === 'l' ? '🏆 Leaderboard · today' : '🏅 My medals', HD[k] || ''); return; }
    const sn = e.target.closest('[data-seen]'); if (sn) { sn.disabled = true; try { await api.post('ct.remark_seen', { id: +sn.dataset.seen }); loadHome(true); } catch (er) { toast(er.message); sn.disabled = false; } return; }
    const gf = e.target.closest('[data-gift]'); if (gf) { gf.disabled = true; try { const r = await api.post('play.gift_open', { id: +gf.dataset.gift }); celebrate(String((r && (r.prize_text || r.text || r.prize)) || 'Gift opened! 🎁')); loadHome(true); } catch (er) { toast(er.message); gf.disabled = false; } return; }
  });
  setInterval(() => { if (!document.hidden && (location.hash || '#home') === '#home') loadHome(true); }, 60000);

  // ---------------------------------------------------------------- Earn
  let E = { busy: false, t: 0, arc: {}, cur: '' };
  const pctOf = (v, t) => (t > 0 ? Math.round((v / t) * 100) : null);
  const ST_TXT = (window.MyCommission && MyCommission.ST_TXT) || {};
  function arcHtml(rows) {
    const shown = (rows || []).filter((r) => r.n > 0 || r.tg > 0 || r.st);
    if (!shown.length) return '<div class="mut" style="padding:8px 0">Nothing recorded for this month.</div>';
    let on = 0, os = 0, ot = 0, hit = 0, days = 0;
    const tr = shown.map((r) => { on += r.n; os += r.ach; ot += r.tg; const p = pctOf(r.ach, r.tg); if (r.tg > 0) { days++; if (r.ach >= r.tg) hit++; }
      const lbl = new Date(r.day + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: '2-digit', month: 'short' });
      return '<div class="ar"><div>' + esc(lbl) + (ST_TXT[r.st] ? ' <span class="mut">· ' + esc(ST_TXT[r.st]) + '</span>' : '') + '</div><div class="num">' + r.n + '</div><div class="num">' + rs(r.ach) + '</div><div class="num">' + (r.tg > 0 ? rs(r.tg) : '—') + '</div><div class="num ' + (p == null ? '' : (p >= 100 ? 'ok' : 'todo')) + '">' + (p == null ? '' : p + '%') + '</div></div>'; }).join('');
    return '<div class="ar hd"><div>Day</div><div class="num">Orders</div><div class="num">Sales</div><div class="num">Target</div><div></div></div>' + tr + '<div class="ar tot"><div>Total</div><div class="num">' + on + '</div><div class="num">' + rs(os) + '</div><div class="num">' + (ot > 0 ? rs(ot) : '—') + '</div><div></div></div>' + (days ? '<div class="mut" style="margin-top:8px">Target hit on ' + hit + ' of ' + days + ' days.</div>' : '');
  }
  async function loadEarn() {
    if (E.busy || Date.now() - E.t < 20000) return; E.busy = true; const mo = new Date().toISOString().slice(0, 7);
    const [c, s, p] = await Promise.allSettled([api.get('my.coach'), api.get('salary.detail', { month: mo }), api.get('my_penalties.list', { month: mo })]);
    const C = ok(c), S = ok(s) && ok(s).detail, P = ok(p), m = C && C.me; let CMn = null, cmErr = '';
    if (C && C.business_day && window.MyCommission) { try { CMn = await MyCommission.month(C.business_day); } catch (e) { cmErr = e.message || 'Could not work out the commission.'; } }
    E.busy = false; E.t = Date.now();
    if (!m && !S && !P && !CMn) { $('earnBody').innerHTML = '<div class="card"><div class="err">' + esc((c.reason && c.reason.message) || 'Could not load your earnings.') + '</div></div>'; return; }
    const live = ((P && P.penalties) || []).filter((x) => x.status === 'applied'); const cur = C && C.business_day ? C.business_day.slice(0, 7) : mo; E.cur = cur;
    const opts = []; if (C && C.business_day) { const [y, mm] = C.business_day.split('-').map(Number); for (let i = 0; i < 6; i++) { const dt = new Date(y, mm - 1 - i, 1), v = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0'); opts.push('<option value="' + v + '">' + esc(dt.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })) + '</option>'); } }
    E.arc[cur] = CMn && CMn.rows;
    $('earnBody').innerHTML =
      '<div class="tiles c2"><div class="tl t-g"><div class="ic">💰</div><div class="b">' + rs(m ? m.com_today : (CMn && CMn.today && CMn.today.amount)) + '</div><div class="l">Commission today</div><div class="s2">' + esc((CMn && CMn.todayRate) || 'from today\'s orders') + '</div></div>'
      + '<div class="tl t-b"><div class="ic">📅</div><div class="b">' + (CMn && CMn.month ? rs(CMn.month.amount) : '—') + '</div><div class="l">Commission this month</div><div class="s2">' + esc((CMn && CMn.noRules) ? 'Commission rules not set' : ((CMn && CMn.monthRate) || (cmErr ? 'Could not load' : ''))) + '</div></div>'
      + '<div class="tl"><div class="ic">🧾</div><div class="b">' + (CMn && CMn.month ? rs(CMn.month.ach) : rs(m && m.month && m.month.sales)) + '</div><div class="l">Sales this month</div><div class="s2">' + esc((CMn && CMn.month ? CMn.month.n : (m && m.month && m.month.orders) || 0)) + ' orders</div></div>'
      + '<div class="tl"><div class="ic">⚠️</div><div class="b" style="color:' + (live.length ? '#dc2626' : '#15803d') + '">' + (live.length ? '− ' + rs(live.reduce((a, x) => a + (+x.amount || 0), 0)) : '₹0') + '</div><div class="l">Penalties</div><div class="s2">' + (live.length ? live.length + ' this month' : 'none, keep it up 💚') + '</div></div></div>'
      + (CMn && CMn.note ? '<div class="rmk"><small>Note</small>' + esc(CMn.note) + '</div>' : '') + (cmErr ? '<div class="rmk"><small>Commission</small>' + esc(cmErr) + '</div>' : '') + (CMn && CMn.applicable === false && CMn.why ? '<div class="rmk"><small>Commission</small>' + esc(CMn.why) + '</div>' : '')
      + (S ? '<div class="card"><div class="h3">Salary so far <span class="mut">' + esc(mo) + '</span></div><div class="erow"><span>Earned</span><b class="big" style="font-size:24px">' + rs(S.earned) + '</b></div><div class="erow"><span class="mut">Monthly salary</span><b>' + rs(S.monthly_salary) + '</b></div><div class="erow"><span class="mut">Net pay after penalties</span><b>' + rs(S.net_pay) + '</b></div></div>' : '')
      + (opts.length ? '<div class="card"><div class="h3">Day by day <select id="arMonth" class="mo">' + opts.join('') + '</select></div><div id="arBody">' + arcHtml(CMn && CMn.rows) + '</div></div>' : '')
      + (((P && P.penalties) || []).length ? '<div class="card"><div class="h3">Penalties this month</div>' + P.penalties.slice(0, 6).map((x) => '<div class="erow"><span>' + esc(x.title || 'Penalty') + '<div class="mut">' + esc(String(x.penalty_date || '').slice(5)) + (x.status !== 'applied' ? ' · ' + esc(x.status) : '') + '</div></span><b>' + rs(x.amount) + '</b></div>').join('') + '</div>' : '');
  }
  document.addEventListener('change', async (e) => {
    if (e.target.id !== 'arMonth') return; const mo = e.target.value, box = $('arBody'); if (E.arc[mo]) { box.innerHTML = arcHtml(E.arc[mo]); return; }
    box.innerHTML = '<div class="sk"></div><div class="sk"></div>'; try { E.arc[mo] = await MyCommission.archive(mo); if ($('arMonth') && $('arMonth').value === mo) box.innerHTML = arcHtml(E.arc[mo]); } catch (er) { box.innerHTML = '<div class="err">' + esc(er.message) + '</div>'; }
  });

  // ---------------------------------------------------------------- Prices (pc.get: every chart, the salesperson may read, only Admin / HR change)
  let PC = null, seg = 'perfume', open = -1, basket = [], col = 0, loading = false;
  const SEGN = { perfume: 'Perfume', gel: 'Shower gel', attar: 'Attar', other: 'Others', mix: 'Combos' };
  function rowInfo(sg, r) {   // → { label, prices[] }  (rows are [ml, p1, p2…] for perfume, objects for combos, either for the rest)
    if (Array.isArray(r)) return { label: r[0] + (sg === 'perfume' ? ' ml' : (+r[0] === 1 ? ' piece' : ' pieces')), key: String(r[0]), prices: r.slice(1).map(Number) };
    if (sg === 'mix') { const p = []; if (r.perf) p.push(r.perf + ' ml perfume'); if (r.gel) p.push(r.gel + ' × shower gel'); if (r.attar) p.push(r.attar + ' × attar'); if (r.other) p.push(r.other + ' × other'); return { label: p.join(' + ') || 'Combo', key: p.join('|'), prices: (r.p || []).map(Number) }; }
    const n = r.ml != null ? r.ml : (r.pcs != null ? r.pcs : (r.n != null ? r.n : '')); return { label: n + (sg === 'perfume' ? ' ml' : (+n === 1 ? ' piece' : ' pieces')), key: String(n), prices: (r.p || r.prices || []).map(Number) };
  }
  async function loadPrices() {
    if (PC || loading) { drawPrices(); return; } loading = true;
    try { PC = await api.get('pc.get'); col = Math.min(Math.max(0, +PC.cust_col || 0), 9); drawSeg(); drawPrices(); } catch (e) { $('prBody').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
    loading = false;
  }
  function chart() { return (PC && PC.charts && PC.charts[seg]) || { cols: [], names: [], rows: [] }; }
  function colLabel(c, i) { const nm = String((c.names || [])[i] || ''); const pc = (c.cols || [])[i]; if (nm && nm.indexOf('%') > -1) return nm; return (nm ? nm + ' · ' : '') + (pc != null ? pc + '%' : '#' + (i + 1)); }
  function drawSeg() {
    const keys = ['perfume', 'gel', 'attar', 'other', 'mix'].filter((k) => PC && PC.charts && PC.charts[k] && (PC.charts[k].rows || []).length);
    if (keys.indexOf(seg) < 0 && keys.length) seg = keys[0];
    $('prSeg').innerHTML = keys.map((k) => '<button type="button" data-seg="' + k + '" class="' + (k === seg ? 'on' : '') + '">' + SEGN[k] + '</button>').join('');
  }
  function drawPrices() {
    const c = chart(), q = ($('prQ').value || '').trim().toLowerCase(); const cols = c.cols || [];
    const rows = (c.rows || []).map((r, i) => Object.assign({ i }, rowInfo(seg, r))).filter((x) => !q || x.label.toLowerCase().indexOf(q) > -1);
    if (!rows.length) { $('prBody').innerHTML = '<div class="card"><div class="empty">' + ((PC && !(c.rows || []).length) ? 'No prices in this chart yet.' : 'Nothing matches.') + '</div></div>'; return; }
    const ci = Math.min(col, Math.max(0, cols.length - 1));
    $('prBody').innerHTML = '<div class="card"><div class="h3">' + esc(SEGN[seg] || seg) + '<span class="mut">Quote column: <b>' + esc(colLabel(c, ci)) + '</b></span></div>' + rows.map((x) => {
      const main = x.prices[ci], op = open === x.i;
      return '<div class="pr"><div class="r"><div class="l" data-row="' + x.i + '" role="button" tabindex="0"><b>' + esc(x.label) + '</b><span>' + (cols[ci] != null ? 'you earn ' + esc(cols[ci]) + '%' : '') + ' · tap for all prices</span></div><div class="v">' + (main == null ? '—' : rs(main)) + '</div><button type="button" class="add" data-add="' + x.i + '" aria-label="Add to the basket">+</button></div>'
        + (op ? '<div class="lad">' + x.prices.map((p, j) => '<div class="' + (j === ci ? 'me' : '') + '" data-col="' + j + '"><b>' + rs(p) + '</b><span>' + esc(colLabel(c, j)) + '</span></div>').join('') + '</div>' : '') + '</div>';
    }).join('') + '</div>';
  }
  function drawBasket() {
    const bk = $('bk'); const c = chart();
    if (!basket.length) { bk.hidden = true; document.body.classList.remove('hasbk'); return; }
    const cols = c.cols || []; let total = 0; basket.forEach((b) => { const ch = (PC.charts[b.seg] || {}); const r = (ch.rows || [])[b.i]; const x = r ? rowInfo(b.seg, r) : null; if (x) total += +(x.prices[Math.min(col, x.prices.length - 1)] || 0); });
    bk.hidden = false; document.body.classList.add('hasbk');
    bk.innerHTML = '<div class="in"><div class="t"><b>' + rs(total) + '</b><span>' + basket.length + ' item' + (basket.length > 1 ? 's' : '') + ' · at ' + esc(colLabel(c, Math.min(col, Math.max(0, cols.length - 1)))) + '</span></div><select id="bkCol" aria-label="Quote column">' + cols.map((_, j) => '<option value="' + j + '"' + (j === col ? ' selected' : '') + '>' + esc(colLabel(c, j)) + '</option>').join('') + '</select><button type="button" id="bkClr">Clear</button></div>';
  }
  document.addEventListener('click', (e) => {
    const sg = e.target.closest('[data-seg]'); if (sg) { seg = sg.dataset.seg; open = -1; drawSeg(); drawPrices(); return; }
    const row = e.target.closest('[data-row]'); if (row) { const i = +row.dataset.row; open = open === i ? -1 : i; drawPrices(); return; }
    const cl = e.target.closest('.lad [data-col]'); if (cl) { col = +cl.dataset.col; drawPrices(); drawBasket(); return; }
    const ad = e.target.closest('[data-add]'); if (ad) { basket.push({ seg, i: +ad.dataset.add }); try { if (navigator.vibrate && store.get('ma_vibe') !== '0') navigator.vibrate(15); } catch (er) { } drawBasket(); return; }
    if (e.target.closest('#bkClr')) { basket = []; drawBasket(); return; }
  });
  document.addEventListener('change', (e) => { if (e.target.id === 'bkCol') { col = +e.target.value; drawPrices(); drawBasket(); } });
  document.addEventListener('input', (e) => { if (e.target.id === 'prQ') { open = -1; drawPrices(); } });

  // ---------------------------------------------------------------- Me (the same switches the chat app uses: wa_sound; dark mode follows the saved choice)
  function drawMe() {
    const on = (k, d) => (store.get(k) == null ? d : store.get(k) === '1');
    const row = (k, label, d) => '<div class="set"><span>' + label + '</span><button type="button" class="tg ' + (on(k, d) ? '' : 'off') + '" data-tg="' + k + '" aria-label="' + esc(label) + '"></button></div>';
    $('meSet').innerHTML = row('wa_sound', '🔊 Sound on a new order', true) + row('ma_vibe', '📳 Vibration', true) + row('ma_dark', '🌙 Dark mode', false);
  }
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-tg]'); if (!t) return; const k = t.dataset.tg; const now = store.get(k) == null ? (k !== 'ma_dark') : store.get(k) === '1'; store.set(k, now ? '0' : '1'); drawMe(); applyDark();
  });
  function applyDark() { document.documentElement.classList.toggle('dk', store.get('ma_dark') === '1'); }

  // ---------------------------------------------------------------- Allocate (the same Order Allocation page the old My Sales opened in a sheet)
  function openAlloc() {
    const sh = document.createElement('div'); sh.className = 'sheet'; sh.innerHTML = '<div class="hd"><span>➕ Allocate an order</span><button type="button" class="btn" id="alClose" style="min-height:40px">Close</button></div><iframe title="Order Allocation" src="order_allocation.html?embed=1" allow="camera; microphone; geolocation"></iframe>'; document.body.appendChild(sh);
    sh.querySelector('#alClose').onclick = () => { sh.remove(); H.t = 0; if ((location.hash || '#home') === '#home') loadHome(true); };
  }
  document.addEventListener('click', (e) => { if (e.target.closest('#fabAlloc')) openAlloc(); });
  api.get('me.flags').then((f) => { if (f && (f.permissions || []).indexOf('allocation') > -1) $('fabAlloc').hidden = false; }).catch(() => { });

  // ---------------------------------------------------------------- start
  applyDark(); drawMe(); go();
  try { const u = api.currentUser && api.currentUser(); const n = u && (u.full_name || u.name || u.username); if (n) $('hiName').textContent = 'Hi, ' + String(n).split(' ')[0]; } catch (e) { /* optional */ }
})();
