/**
 * wq_pace_ui.js — ⚡ Reply pace screens (window.WaPace). Needs api.js (window.api). Backend: wq.pace (empmanagment/wq_pace.php).
 *   WaPace.mount(el, { days })        draws the whole pace report into el (admin: everyone; salesperson: only their own numbers)
 *   WaPace.open()                     the same report in a pop-up (the ⚡ Pace button next to the bell)
 *   WaPace.load(days) -> Promise      raw data (the Live board uses it for its Pace column)
 *   WaPace.chip(person)               small "3.2 min" chip with the grade colour
 */
(function () {
  'use strict';
  if (window.WaPace) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const COL = { fast: '#16a34a', good: '#65a30d', slow: '#d97706', veryslow: '#dc2626', none: '#94a3b8' };
  const GL = { fast: 'Fast', good: 'Good', slow: 'Slow', veryslow: 'Very slow', none: 'No data' };
  const has = (v) => v !== null && v !== undefined && v !== '';
  const fm = (m) => { if (!has(m)) return '-'; m = +m; if (m < 1) return Math.max(1, Math.round(m * 60)) + ' sec'; if (m < 90) return (m < 10 ? m.toFixed(1) : Math.round(m)) + ' min'; if (m < 1440) return (m / 60).toFixed(1) + ' h'; return (m / 1440).toFixed(1) + ' d'; };
  const CSS = `
.wp{font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--wp-ink,#111827)}
html.wqdk .wp,html.wxdk .wp{--wp-ink:#e9edef;--wp-card:#1f2c34;--wp-line:#2a3942;--wp-mut:#8696a0}
.wp{--wp-card:#fff;--wp-line:#e5e7eb;--wp-mut:#6b7280}
.wp-seg{display:inline-flex;border:1px solid var(--wp-line);border-radius:99px;overflow:hidden}.wp-seg button{border:0;background:transparent;color:inherit;font:600 13px inherit;padding:7px 14px;cursor:pointer;min-height:36px}.wp-seg button.on{background:#0f6e7e;color:#fff}
.wp-h{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}.wp-h h2{font-size:18px;margin:0}
.wp-card{background:var(--wp-card);border:1px solid var(--wp-line);border-radius:14px;padding:14px;margin-bottom:12px}.wp-card h3{font-size:15px;margin:0 0 4px}.wp-sub{color:var(--wp-mut);font-size:12.5px}
.wp-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}.wp-tile{background:var(--wp-card);border:1px solid var(--wp-line);border-radius:14px;padding:12px}.wp-tile small{display:block;color:var(--wp-mut);font-size:12px}.wp-tile b{font-size:24px;letter-spacing:-.02em}.wp-tile>span{display:block;font-size:12px;color:var(--wp-mut)}
.wp-chip{display:inline-block;border-radius:99px;padding:2px 9px;font-size:12px;font-weight:700;color:#fff;white-space:nowrap}
.wp table{width:100%;border-collapse:collapse;font-size:13px}.wp th{font-size:11.5px;color:var(--wp-mut);font-weight:700;text-align:right;padding:6px 6px;border-bottom:1px solid var(--wp-line);white-space:nowrap}.wp td{padding:8px 6px;border-bottom:1px solid var(--wp-line);text-align:right;white-space:nowrap}.wp th:first-child,.wp td:first-child{text-align:left}.wp tr.me td{background:rgba(15,110,126,.1)}
.wp-sc{overflow-x:auto}.wp .up{color:#dc2626;font-weight:700}.wp .dn{color:#16a34a;font-weight:700}
.wp-bar{height:8px;border-radius:99px;background:var(--wp-line);overflow:hidden;min-width:70px}.wp-bar i{display:block;height:100%;background:#0f6e7e}
.wp-verdict{border-radius:12px;padding:10px 12px;background:rgba(15,110,126,.12);font-weight:600;margin-top:10px}
.wp-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2147483000;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow:auto}.wp-ov>div{background:var(--wp-bg,#f6f7f9);border-radius:18px;width:min(1000px,100%);padding:16px;position:relative}html.wqdk .wp-ov>div,html.wxdk .wp-ov>div{--wp-bg:#111b21}.wp-x{position:absolute;right:12px;top:10px;border:0;background:rgba(0,0,0,.08);border-radius:99px;width:32px;height:32px;font-size:16px;cursor:pointer;color:inherit}
@media (max-width:640px){.wp-ov{padding:6px}.wp-tile b{font-size:20px}}
`;
  function css() { if (document.getElementById('wpCss')) return; const s = document.createElement('style'); s.id = 'wpCss'; s.textContent = CSS; document.head.appendChild(s); }
  const chip = (p) => { const pc = p && p.pace ? p.pace : p; if (!pc || !has(pc.median)) return '<span class="wp-sub">-</span>'; return '<span class="wp-chip" style="background:' + COL[pc.grade || 'none'] + '" title="' + esc(GL[pc.grade]) + ' · ' + pc.n + ' replies · average ' + fm(pc.avg) + '">' + fm(pc.median) + '</span>'; };
  const delta = (d) => !has(d) ? '<span class="wp-sub">-</span>' : (d === 0 ? '<span class="wp-sub">same</span>' : '<span class="' + (d < 0 ? 'dn' : 'up') + '">' + (d < 0 ? '▼ ' : '▲ ') + fm(Math.abs(d)) + '</span>');
  const LINK = { strong: ['Strong link', 'Faster replies clearly bring more orders.', '#16a34a'], some: ['Some link', 'Faster replies bring somewhat more orders.', '#65a30d'], none: ['No clear link', 'Reply speed does not show a difference in orders yet.', '#94a3b8'], reverse: ['Reverse', 'Slower replies are winning more orders - check the quality of the fast replies.', '#dc2626'] };
  const linkTxt = (l) => !l ? '<span class="wp-sub">too few</span>' : '<span style="color:' + LINK[l.label][2] + ';font-weight:700" title="' + esc(LINK[l.label][1]) + ' (' + l.n + ' queries)">' + (l.r > 0 ? '+' : '') + l.r.toFixed(2) + ' · ' + LINK[l.label][0] + '</span>';
  const lossTxt = (x) => !x || !has(x.orders) ? '<span class="wp-sub">-</span>' : (x.orders < 0.5 ? '<span class="wp-sub">none</span>' : '<b class="up" title="Estimated: ' + x.slow_queries + ' slow-answered queries x (what ' + (x.basis === 'own' ? 'their own' : 'the team\'s') + ' fast answers win - what the slow ones won). Last 30 days.">≈ ' + x.orders + ' orders' + (x.revenue ? '<div class="wp-sub">₹' + Number(x.revenue).toLocaleString('en-IN') + '</div>' : '') + '</b>');
  const VD = { star: '#16a34a', ok: '#64748b', quality: '#d97706', red: '#dc2626', few: '#94a3b8' };
  const vdChip = (v) => !v ? '' : '<span class="wp-chip" style="background:' + VD[v.key] + '" title="' + esc(v.why) + '">' + esc(v.label) + '</span>';
  function load(days) { return api.get('wq.pace', { days: days || 7 }); }

  function spark(series, w, h) {
    w = w || 90; h = h || 26; const v = (series || []).map((x) => (has(x.median) ? Math.min(60, +x.median) : null)); const ok = v.filter((x) => x !== null); if (ok.length < 2) return '<span class="wp-sub">-</span>';
    const mx = Math.max.apply(null, ok) || 1, st = w / Math.max(1, v.length - 1); let d = '', pen = false;
    v.forEach((x, i) => { if (x === null) { pen = false; return; } d += (pen ? 'L' : 'M') + (i * st).toFixed(1) + ' ' + (h - 2 - (x / mx) * (h - 4)).toFixed(1); pen = true; });
    return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" aria-label="Daily median pace"><path d="' + d + '" fill="none" stroke="#0f6e7e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }
  function graph(team, bot) {
    const W = 640, H = 190, L = 38, B = 26, T = 10, s = team || [], b = bot || [], n = s.length; if (n < 2) return '';
    const vals = s.concat(b).map((x) => x.median).filter(has).map(Number); const mx = Math.max(5, Math.ceil(Math.max.apply(null, vals.length ? vals : [5]) / 5) * 5);
    const X = (i) => L + (i * (W - L - 8)) / (n - 1), Y = (v) => T + (H - T - B) * (1 - Math.min(v, mx) / mx);
    const line = (a, c, dash) => { let d = '', pen = false; a.forEach((x, i) => { if (!has(x.median)) { pen = false; return; } d += (pen ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(+x.median).toFixed(1); pen = true; }); return d ? '<path d="' + d + '" fill="none" stroke="' + c + '" stroke-width="2.4" ' + (dash ? 'stroke-dasharray="5 4" ' : '') + 'stroke-linecap="round"/>' : ''; };
    let g = ''; for (let k = 0; k <= 4; k++) { const v = (mx * k) / 4; g += '<line x1="' + L + '" x2="' + (W - 8) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" stroke="currentColor" opacity=".12"/><text x="' + (L - 6) + '" y="' + (Y(v) + 4) + '" font-size="11" text-anchor="end" fill="currentColor" opacity=".6">' + Math.round(v) + 'm</text>'; }
    s.forEach((x, i) => { if (n <= 8 || i % Math.ceil(n / 8) === 0 || i === n - 1) g += '<text x="' + X(i) + '" y="' + (H - 8) + '" font-size="11" text-anchor="middle" fill="currentColor" opacity=".6">' + esc(String(x.day).slice(5).replace('-', '/')) + '</text>'; });
    const dots = s.map((x, i) => has(x.median) ? '<circle cx="' + X(i).toFixed(1) + '" cy="' + Y(+x.median).toFixed(1) + '" r="3.4" fill="#0f6e7e"><title>' + esc(x.day) + ': ' + fm(x.median) + ' (' + x.n + ' replies)</title></circle>' : '').join('');
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto" role="img" aria-label="Median reply pace per day">' + g + line(b, '#94a3b8', true) + line(s, '#0f6e7e') + dots + '</svg><div class="wp-sub"><span style="color:#0f6e7e">●</span> People (median per day) &nbsp; <span style="color:#94a3b8">╌</span> Auto-bot &nbsp; · lower is better</div>';
  }
  function takeBlock(d, admin, me) {
    const tt = d.after_take_team || []; let h = '<div class="wp-card"><h3>After taking a query</h3><div class="wp-sub">Minutes from the moment a chat became yours (late-reply pickup, Rescue, take over, given by a manager) to your first message.</div>';
    if (!tt.length) return h + '<div class="wp-sub" style="margin-top:8px">Nobody took a query in this period.</div></div>';
    h += '<div class="wp-sc"><table><thead><tr><th>How it was taken</th><th>Taken</th><th>Median to first message</th></tr></thead><tbody>' + tt.map((x) => '<tr><td>' + esc(x.label) + '</td><td>' + x.n + '</td><td>' + fm(x.median) + '</td></tr>').join('') + '</tbody></table></div>';
    if (admin) { const ps = (d.people || []).filter((p) => p.after_take.n > 0 || p.after_take.no_reply > 0); if (ps.length) h += '<div class="wp-sc" style="margin-top:8px"><table><thead><tr><th>Person</th><th>Taken</th><th>Median</th><th>Never answered</th>' + '</tr></thead><tbody>' + ps.map((p) => '<tr><td>' + esc(p.name) + '</td><td>' + p.after_take.n + '</td><td>' + fm(p.after_take.median) + '</td><td>' + (p.after_take.no_reply ? '<span class="up">' + p.after_take.no_reply + '</span>' : 0) + '</td></tr>').join('') + '</tbody></table></div>'; }
    else if (me && me.after_take.n) h += '<div class="wp-verdict">Your median after taking a query: ' + fm(me.after_take.median) + (me.after_take.no_reply ? ' · ' + me.after_take.no_reply + ' taken chats got no message from you' : '') + '</div>';
    return h + '</div>';
  }
  function orderBlock(d) {
    const o = d.orders || {}, b = o.buckets || []; if (!b.length) return '';
    const mx = Math.max.apply(null, b.map((x) => +x.conversion || 0).concat([1]));
    let h = '<div class="wp-card"><h3>Does a fast reply close the order faster?</h3><div class="wp-sub">Every query since ' + esc(o.since || '') + ', grouped by how fast a person first answered it.</div><div class="wp-sc"><table><thead><tr><th>First answer</th><th>Queries</th><th>Orders</th><th>Orders %</th><th></th><th>Time to order</th></tr></thead><tbody>';
    b.forEach((x) => { h += '<tr><td>' + esc(x.label) + '</td><td>' + x.queries + '</td><td>' + x.orders + '</td><td><b>' + (has(x.conversion) ? x.conversion + '%' : '-') + '</b></td><td style="min-width:90px"><div class="wp-bar"><i style="width:' + (has(x.conversion) ? Math.round(100 * x.conversion / mx) : 0) + '%"></i></div></td><td>' + (has(x.close_median_min) ? fm(x.close_median_min) : '-') + '</td></tr>'; });
    h += '</tbody></table></div>'; const v = o.verdict || {};
    if (v.enough && has(v.fast_vs_slow_conversion)) h += '<div class="wp-verdict">' + (v.fast_vs_slow_conversion >= 1.1 ? '✅ Yes. Queries answered within 5 minutes become orders <b>' + v.fast_vs_slow_conversion + '×</b> as often as those answered after 30 minutes' + (has(v.fast_vs_slow_close) && v.fast_vs_slow_close > 1 ? ', and the order comes <b>' + v.fast_vs_slow_close + '×</b> sooner.' : '.') : 'No clear gain yet: fast and slow answers convert about the same (' + v.fast_vs_slow_conversion + '×).') + '</div>';
    else h += '<div class="wp-verdict">Not enough queries yet to judge (needs 20 answered within 5 min and 20 answered after 30 min). The table fills as orders come in.</div>';
    return h + '</div>';
  }
  // one table that joins the two things: how fast each person answers AND how many orders those fast / slow answers brought
  function linkCard(d) {
    const ps = (d.people || []).filter((p) => p.orders && p.orders.buckets), o = d.orders || {}, b = o.buckets || []; if (!ps.length && !b.length) return '';
    const cell = (x) => !x || !x.queries ? '<span class="wp-sub">-</span>' : '<b>' + x.orders + '</b> <span class="wp-sub">of ' + x.queries + '</span><div class="wp-sub">' + (has(x.conversion) ? x.conversion + '%' : '-') + '</div>';
    const link = (bk) => { const f = bk.fast, s = bk.slow; if (!f || !s || f.queries < 5 || s.queries < 5) return '<span class="wp-sub">too few</span>'; const fc = f.conversion || 0, sc = s.conversion || 0; return fc > sc ? '<span class="dn">✅ Good: fast replies win more</span>' : fc < sc ? '<span class="up">⚠ Bad: slow replies win more</span>' : '<span class="wp-sub">Same</span>'; };
    const tb = {}; b.forEach((x) => { tb[x.key] = x; });
    let h = '<div class="wp-card"><h3>⚡ Reply speed × orders</h3><div class="wp-sub">Each person: how fast they answer, and how many orders came from their fast answers (first reply within 5 min) and from their slow answers (after 30 min). Queries from the last 30 days. "7 of 20" = 7 orders from 20 queries.</div><div class="wp-sc"><table><thead><tr><th>Person</th><th>Typical reply</th><th>Fast replies<br>(≤ 5 min)</th><th>Medium<br>(5 - 30 min)</th><th>Slow replies<br>(30+ min)</th><th>Not answered</th><th>Lost to slow replies<br>(30 days)</th><th>Speed ↔ orders link</th></tr></thead><tbody>';
    ps.forEach((p) => { const bk = p.orders.buckets; h += '<tr><td><b>' + esc(p.name) + '</b><div>' + vdChip(p.verdict) + '</div></td><td>' + chip(p) + '</td><td>' + cell(bk.fast) + '</td><td>' + cell(bk.mid) + '</td><td>' + cell(bk.slow) + '</td><td>' + cell(bk.none) + '</td><td>' + lossTxt(p.loss) + '</td><td style="text-align:left">' + link(bk) + '<div>' + linkTxt(p.link) + '</div></td></tr>'; });
    if (b.length) h += '<tr><td><b>Whole team</b></td><td>' + (d.team ? chip(d.team.pace) : '-') + '</td><td>' + cell(tb.fast) + '</td><td>' + cell(tb.mid) + '</td><td>' + cell(tb.slow) + '</td><td>' + cell(tb.none) + '</td><td>' + (() => { const t = (d.people || []).reduce((a, p) => { if (p.loss && has(p.loss.orders)) { a.o += p.loss.orders; a.r += p.loss.revenue || 0; } return a; }, { o: 0, r: 0 }); return t.o >= 0.5 ? '<b class="up">≈ ' + Math.round(t.o * 10) / 10 + ' orders<div class="wp-sub">₹' + Math.round(t.r).toLocaleString('en-IN') + '</div></b>' : '<span class="wp-sub">-</span>'; })() + '</td><td style="text-align:left">' + link(tb) + '<div>' + linkTxt(o.link) + '</div></td></tr>';
    h += '</tbody></table></div>';
    h += '<div class="wp-sub" style="margin-top:8px"><b>How to read the colour under each name:</b> <span style="color:#16a34a">⭐ fast + orders good</span> · <span style="color:#64748b">slow but orders fine (no problem)</span> · <span style="color:#d97706">🟠 fast but orders low (check what is said)</span> · <span style="color:#dc2626">🔴 slow AND orders low (coach this person)</span>. Slow = typical reply over 10 min. Orders low = below 85% of the team rate.</div>';
    if (o.link) h += '<div class="wp-verdict">📈 Speed ↔ orders link: <b>' + (o.link.r > 0 ? '+' : '') + o.link.r.toFixed(2) + '</b> (' + LINK[o.link.label][0] + '). ' + LINK[o.link.label][1] + ' <span class="wp-sub">Scale: +1 = every faster reply brings an order, 0 = no connection, below 0 = slower replies win. It shows that the two move together, not that one causes the other.</span></div>';
    return h + '</div>';
  }
  function cfgCard(d) {
    const c = d.cfg; if (!c || d.self) return '';
    return '<div class="wp-card"><h3>Reply target and alerts</h3><div class="wp-sub">When a customer waits longer than the warn time, the salesperson gets a push once. At the alert time the admin gets one. Minutes.</div><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px">'
      + [['target', 'Target reply', c.target], ['warn', 'Warn the person at', c.warn], ['alert', 'Tell admin at', c.alert]].map((x) => '<label class="wp-sub">' + x[1] + '<br><input type="number" min="1" max="480" data-cfg="' + x[0] + '" value="' + x[2] + '" style="width:80px;padding:6px;border:1px solid var(--wp-line);border-radius:8px;background:transparent;color:inherit"></label>').join('')
      + '<label class="wp-sub"><input type="checkbox" data-cfg="on"' + (c.on ? ' checked' : '') + '> Alerts on</label><button type="button" class="wp-save" style="border:0;background:#0f6e7e;color:#fff;border-radius:99px;padding:8px 16px;font-weight:700;cursor:pointer">Save</button><span class="wp-sub wp-saved"></span></div></div>';
  }
  function board(d) {
    const ps = d.people || []; if (!ps.length) return '';
    let h = '<div class="wp-card"><h3>Who is fast, who is slow</h3><div class="wp-sub">Sorted fastest first. Median = the typical reply. "Slow %" = replies later than 30 min. ▼ means faster than the period before. People with under 5 replies are listed last.</div><div class="wp-sc"><table><thead><tr><th>#</th><th>Person</th><th>Typical reply</th><th>Average</th><th>Within 5 min</th><th>Slow %</th><th>Replies</th><th>vs before</th><th>Trend</th><th>After taking</th><th>Orders %</th><th>Time to order</th></tr></thead><tbody>';
    ps.forEach((p) => { const o = p.orders || {}; h += '<tr><td>' + (p.rank || '-') + '</td><td><b>' + esc(p.name) + '</b></td><td>' + chip(p) + '</td><td>' + fm(p.pace.avg) + '</td><td>' + (has(p.pace.fast_pct) ? p.pace.fast_pct + '%' : '-') + '</td><td>' + (has(p.pace.slow_pct) ? (p.pace.slow_pct >= 30 ? '<span class="up">' + p.pace.slow_pct + '%</span>' : p.pace.slow_pct + '%') : '-') + '</td><td>' + p.pace.n + (p.pace.next_day ? '<div class="wp-sub">+' + p.pace.next_day + ' next day</div>' : '') + '</td><td>' + delta(p.delta) + '</td><td>' + spark(p.series) + '</td><td>' + fm(p.after_take.median) + '</td><td>' + (has(o.conversion) ? o.conversion + '%' : '-') + '</td><td>' + (has(o.close_median_min) ? fm(o.close_median_min) : '-') + '</td></tr>'; });
    return h + '</tbody></table></div></div>';
  }
  function html(d, days) {
    const admin = !d.self, tp = d.team.pace, pv = d.team.prev, me = d.mine; let h = '';
    h += '<div class="wp-h"><h2>⚡ Reply pace</h2><div class="wp-seg" role="group" aria-label="Period">' + [[1, 'Today'], [7, '7 days'], [30, '30 days']].map((x) => '<button type="button" data-d="' + x[0] + '" class="' + (days === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div></div>';
    if (!admin) {
      const mp = me ? me.pace : null;
      h += '<div class="wp-tiles"><div class="wp-tile"><small>Your typical reply</small><b>' + (mp && has(mp.median) ? fm(mp.median) : '-') + '</b><span>' + (mp && mp.n ? chip(mp) + ' · ' + mp.n + ' replies' : 'No replies in this period') + '</span></div>'
        + '<div class="wp-tile"><small>Team typical reply</small><b>' + fm(tp.median) + '</b><span>' + tp.n + ' replies</span></div>'
        + '<div class="wp-tile"><small>Your rank</small><b>' + (me && me.rank ? '#' + me.rank : '-') + '</b><span>of ' + d.ranked + ' people' + (me && !me.rank ? ' (needs 5 replies)' : '') + '</span></div>'
        + '<div class="wp-tile"><small>Compared with before</small><b>' + (me ? delta(me.delta) : '-') + '</b><span>previous ' + (days === 1 ? 'day' : days + ' days') + '</span></div>'
        + '<div class="wp-tile"><small>Within 5 minutes</small><b>' + (mp && has(mp.fast_pct) ? mp.fast_pct + '%' : '-') + '</b><span>team ' + (has(tp.fast_pct) ? tp.fast_pct + '%' : '-') + '</span></div>'
        + '<div class="wp-tile"><small>Speed ↔ orders link</small><b style="font-size:16px">' + linkTxt(me && me.link ? me.link : (d.orders || {}).link) + '</b><span>' + (me && me.loss && me.loss.orders >= 0.5 ? 'Slow replies cost you ≈ ' + me.loss.orders + ' orders' : 'last 30 days') + '</span></div></div>';
      if (me) h += '<div class="wp-card"><h3>Your last days</h3>' + graph(me.series, d.team.series) + '<div class="wp-sub" style="margin-top:4px">Dotted line = team.</div></div>';
      h += takeBlock(d, false, me) + orderBlock(d);
    } else {
      h += '<div class="wp-tiles"><div class="wp-tile"><small>Team typical reply</small><b>' + fm(tp.median) + '</b><span>' + chip(tp) + ' · ' + tp.n + ' replies</span></div>'
        + '<div class="wp-tile"><small>Team average</small><b>' + fm(tp.avg) + '</b><span>capped at 2 h per reply</span></div>'
        + '<div class="wp-tile"><small>Within 5 minutes</small><b>' + (has(tp.fast_pct) ? tp.fast_pct + '%' : '-') + '</b><span>slow (30+ min) ' + (has(tp.slow_pct) ? tp.slow_pct + '%' : '-') + '</span></div>'
        + '<div class="wp-tile"><small>Before</small><b>' + fm(pv.median) + '</b><span>' + delta(has(tp.median) && has(pv.median) ? Math.round((tp.median - pv.median) * 10) / 10 : null) + '</span></div>'
        + '<div class="wp-tile"><small>Auto-bot reply</small><b>' + fm(d.team.bot.median) + '</b><span>' + d.team.bot.n + ' auto replies</span></div></div>';
      h += linkCard(d) + cfgCard(d) + '<div class="wp-card"><h3>Daily pace (everyone together)</h3>' + graph(d.team.series, d.team.bot_series) + '</div>' + board(d) + takeBlock(d, true, null) + orderBlock(d);
    }
    h += '<div class="wp-sub">How it is measured: the clock starts at the first customer message nobody has answered, and stops at the next message a person sends (typed, quick-reply or template). All queries (Meta, Campaign, Community, Regenerated, New) are counted together. Auto-bot and holding messages are timed separately and never count for a person. Chats with an open complaint are left out: the complaint team answers those. A bare "ok" or "hmm" is not counted as an answer. Gaps of 8+ hours (night / next day) are left out; a night worker who answers within 8 hours is counted as usual. Grades: Fast up to 3 min · Good up to 10 · Slow up to 30 · Very slow above 30.</div>';
    return h;
  }
  function mount(el, o) {
    o = o || {}; css(); let days = [1, 7, 30].indexOf(o.days) >= 0 ? o.days : 7; let seq = 0;
    el.classList.add('wp');
    const run = () => {
      const my = ++seq; el.innerHTML = '<div class="wp-card wp-sub">Loading pace…</div>';
      return load(days).then((d) => { if (my !== seq) return; el.innerHTML = html(d, days); }, (e) => { if (my !== seq) return; el.innerHTML = '<div class="wp-card" style="color:#dc2626">Pace could not load. ' + esc(e && e.message ? e.message : '') + ' <button type="button" class="wp-retry">Try again</button></div>'; });
    };
    el.addEventListener('click', (e) => { const sv = e.target.closest('.wp-save'); if (sv) { const body = {}; el.querySelectorAll('[data-cfg]').forEach((i) => { body[i.dataset.cfg] = i.type === 'checkbox' ? i.checked : +i.value; }); const note = el.querySelector('.wp-saved'); api.post('wq.pace_cfg', body).then(() => { if (note) note.textContent = 'Saved ✓'; }, (er) => { if (note) note.textContent = (er && er.message) || 'Could not save'; }); return; } const b = e.target.closest('[data-d]'); if (b) { days = +b.dataset.d; run(); return; } if (e.target.closest('.wp-retry')) run(); });
    return run();
  }
  function open() {
    css(); if (document.getElementById('wpOv')) return;
    const ov = document.createElement('div'); ov.className = 'wp-ov'; ov.id = 'wpOv'; ov.innerHTML = '<div><button type="button" class="wp-x" aria-label="Close">✕</button><div id="wpBody"></div></div>';
    const close = () => { ov.remove(); document.removeEventListener('keydown', esck); }; const esck = (e) => { if (e.key === 'Escape') close(); };
    ov.addEventListener('click', (e) => { if (e.target === ov || e.target.closest('.wp-x')) close(); }); document.addEventListener('keydown', esck);
    document.body.appendChild(ov); mount(ov.querySelector('#wpBody'), { days: 7 });
  }
  window.WaPace = { mount, open, load, chip, fm, css, linkCard, cfgCard };
})();
