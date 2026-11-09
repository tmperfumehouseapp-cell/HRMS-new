/**
 * wq_pace_page.js — the ⚡ Reply pace PAGE (pace.html), window.WaPacePage. Needs api.js and wq_pace_ui.js (window.WaPace).
 * One page, three views: Team (graphs per day, everyone compared, suggestions), Person (their own graphs against the team, their orders by speed,
 * what to do), and the settings card. Data: wq.pace days=30 (cached 10 min on the server), wq.pace_waiting (live).
 */
(function () {
  'use strict';
  if (window.WaPacePage) return;
  const W = window.WaPace; const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const has = (v) => v !== null && v !== undefined && v !== '';
  const VD = { star: '#16a34a', ok: '#64748b', quality: '#d97706', red: '#dc2626', few: '#94a3b8' };
  const CSS = `
.pp .wp-card{overflow:hidden}.pp-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.pp-two>.wp-card{margin-bottom:0}@media (max-width:900px){.pp-two{grid-template-columns:1fr}}
.pp-sug{list-style:none;margin:8px 0 0;padding:0}.pp-sug li{padding:9px 11px;border-radius:10px;margin-bottom:7px;background:rgba(15,110,126,.08);font-size:13.5px;line-height:1.45}.pp-sug li.r{background:rgba(220,38,38,.1)}.pp-sug li.a{background:rgba(217,119,6,.12)}.pp-sug li.g{background:rgba(22,163,74,.11)}
.pp .nm{cursor:pointer;font-weight:700;color:#0f6e7e;text-decoration:underline;text-decoration-style:dotted}
.pp select{padding:8px 12px;border:1px solid var(--wp-line);border-radius:10px;background:transparent;color:inherit;font:600 13px inherit;min-height:38px}
.pp-tip{font-size:12px}.pp-lg{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;margin-top:4px;color:var(--wp-mut)}
.pp-btn{border:0;background:#0f6e7e;color:#fff;border-radius:99px;padding:8px 16px;font-weight:700;cursor:pointer}.pp-btn.red{background:#dc2626}.pp-btn.gh{background:transparent;color:inherit;border:1px solid var(--wp-line)}
`;
  const css = () => { W.css(); if (!document.getElementById('ppCss')) { const s = document.createElement('style'); s.id = 'ppCss'; s.textContent = CSS; document.head.appendChild(s); } };

  // ---------------------------------------------------------------- a line chart with hover values: lines = [{ name, color, dash, pts: [{ day, v, n }] }]
  function chart(lines, title, sub, o) {
    const tg = o && has(o.target) ? +o.target : null;
    const Wd = 640, Hd = 210, L = 46, B = 26, T = 12, days = (lines[0] || { pts: [] }).pts.length; if (days < 2) return '';
    const vals = []; lines.forEach((l) => l.pts.forEach((p) => { if (has(p.v)) vals.push(+p.v); })); if (tg !== null && vals.length) vals.push(tg);
    if (!vals.length) return '<div class="wp-card"><h3>' + esc(title) + '</h3><div class="wp-sub">No data in this period yet.</div></div>';
    const raw = Math.max.apply(null, vals), step = raw <= 10 ? 2 : raw <= 30 ? 5 : raw <= 90 ? 15 : raw <= 300 ? 60 : raw <= 1500 ? 240 : 1440; const mx = Math.max(step, Math.ceil(raw / step) * step);
    const X = (i) => L + (i * (Wd - L - 10)) / (days - 1), Y = (v) => T + (Hd - T - B) * (1 - Math.min(v, mx) / mx);
    let g = ''; for (let k = 0; k <= 4; k++) { const v = (mx * k) / 4; g += '<line x1="' + L + '" x2="' + (Wd - 10) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" stroke="currentColor" opacity=".12"/><text x="' + (L - 6) + '" y="' + (Y(v) + 4) + '" font-size="11" text-anchor="end" fill="currentColor" opacity=".6">' + esc(W.fm(v)) + '</text>'; }
    const pts0 = lines[0].pts; pts0.forEach((p, i) => { if (days <= 8 || i % Math.ceil(days / 8) === 0 || i === days - 1) g += '<text x="' + X(i) + '" y="' + (Hd - 8) + '" font-size="11" text-anchor="middle" fill="currentColor" opacity=".6">' + esc(String(p.day).slice(5).replace('-', '/')) + '</text>'; });
    let body = tg !== null ? '<line x1="' + L + '" x2="' + (Wd - 10) + '" y1="' + Y(tg) + '" y2="' + Y(tg) + '" stroke="#16a34a" stroke-width="1.6" stroke-dasharray="2 4"/><text x="' + (Wd - 12) + '" y="' + (Y(tg) - 4) + '" font-size="11" text-anchor="end" fill="#16a34a">target ' + esc(W.fm(tg)) + '</text>' : '';
    lines.forEach((l) => {
      let d = '', pen = false; l.pts.forEach((p, i) => { if (!has(p.v)) { pen = false; return; } d += (pen ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(+p.v).toFixed(1); pen = true; });
      if (d) body += '<path d="' + d + '" fill="none" stroke="' + l.color + '" stroke-width="2.4" ' + (l.dash ? 'stroke-dasharray="5 4" ' : '') + 'stroke-linecap="round" stroke-linejoin="round"/>';
      l.pts.forEach((p, i) => { if (has(p.v)) body += '<circle cx="' + X(i).toFixed(1) + '" cy="' + Y(+p.v).toFixed(1) + '" r="' + (l.dash ? 2.6 : 3.6) + '" fill="' + l.color + '"><title>' + esc(l.name + ' · ' + p.day + ': ' + W.fm(p.v) + (p.n ? ' (' + p.n + ')' : '')) + '</title></circle>'; });
    });
    return '<div class="wp-card"><h3>' + esc(title) + '</h3>' + (sub ? '<div class="wp-sub">' + esc(sub) + '</div>' : '') + '<svg viewBox="0 0 ' + Wd + ' ' + Hd + '" style="width:100%;height:auto;margin-top:6px" role="img" aria-label="' + esc(title) + '">' + g + body + '</svg>'
      + '<div class="pp-lg">' + lines.map((l) => '<span><span style="color:' + l.color + '">' + (l.dash ? '╌' : '●') + '</span> ' + esc(l.name) + '</span>').join('') + (tg !== null ? '<span><span style="color:#16a34a">┈</span> target</span>' : '') + '<span>lower is better · tap a dot for the value</span></div></div>';
  }
  const smoothPts = (pts, on) => !on ? pts : pts.map((p, i) => { let s = 0, n = 0; for (let k = Math.max(0, i - 6); k <= i; k++) { const q = pts[k]; if (has(q.v) && q.n) { s += q.v * q.n; n += q.n; } } return { day: p.day, v: n ? s / n : null, n }; });
  const smoothCb = (S) => '<label class="wp-sub" style="display:inline-flex;gap:6px;align-items:center;cursor:pointer"><input type="checkbox" data-smooth="1"' + (S.smooth ? ' checked' : '') + '> 7-day average (smooths the order-closing line)</label>';
  const lastN = (a, n) => a.slice(Math.max(0, a.length - n));
  const wavg = (a, key) => { let n = 0, s = 0; a.forEach((x) => { if (has(x[key]) && x.n) { n += x.n; s += x.n * x[key]; } }); return n ? s / n : null; };
  const closeOf = (x) => (x && x.close ? { n: x.close.n, avg: x.close.avg } : null);   // per-day close entries are { n, avg }

  // ---------------------------------------------------------------- suggestions (plain rules, nothing is guessed from outside)
  function teamSuggestions(D, wait) {
    const out = [], P = D.people.filter((p) => p.pace.n >= 5), tgt = (D.cfg || {}).target || 5, tp = D.team.pace;
    const by = (k) => P.filter((p) => p.verdict && p.verdict.key === k);
    const names = (a) => a.map((p) => '<b>' + esc(p.name) + '</b>').join(', ');
    const red = by('red'), qual = by('quality'), star = by('star');
    if (red.length) { const lo = red.reduce((a, p) => a + (p.loss && p.loss.orders ? p.loss.orders : 0), 0), lr = red.reduce((a, p) => a + (p.loss && p.loss.revenue ? p.loss.revenue : 0), 0); out.push(['r', '🔴 <b>Coach first:</b> ' + names(red) + ' — slow replies AND low orders. Ask them to answer every new query within ' + tgt + ' minutes for a week and compare.' + (lo >= 0.5 ? ' Slow replies cost these people ≈ ' + Math.round(lo * 10) / 10 + ' orders' + (lr ? ' (₹' + Math.round(lr).toLocaleString('en-IN') + ')' : '') + ' in 30 days.' : '')]); }
    if (qual.length) out.push(['a', '🟠 <b>Fast, but orders are low:</b> ' + names(qual) + '. Their speed is fine — read a few of their chats: what do they say after the first reply? Share the best sentences of the ⭐ people with them.']);
    if (star.length) out.push(['g', '⭐ <b>Learn from:</b> ' + names(star) + ' — fast and winning orders. Ask them to share how they open a chat; use it as the example for the team.']);
    if (has(tp.fast_pct) && tp.fast_pct < 50) out.push(['a', 'Only <b>' + tp.fast_pct + '%</b> of replies are within 5 minutes. A realistic first goal: ' + Math.min(100, tp.fast_pct + 15) + '% this month.']);
    if (has(tp.slow_pct) && tp.slow_pct >= 25) out.push(['r', '<b>' + tp.slow_pct + '%</b> of replies take more than 30 minutes — that is where orders are lost. Look at which people and which time of day (use the daily graph).']);
    const imp = P.filter((p) => has(p.delta) && p.delta <= -3).sort((a, b) => a.delta - b.delta), wor = P.filter((p) => has(p.delta) && p.delta >= 5).sort((a, b) => b.delta - a.delta);
    if (imp.length) out.push(['g', '👏 <b>Most improved:</b> ' + imp.slice(0, 3).map((p) => '<b>' + esc(p.name) + '</b> (' + W.fm(Math.abs(p.delta)) + ' faster)').join(', ') + '. Tell them — it keeps the habit.']);
    if (wor.length) out.push(['a', '📉 <b>Got slower than the 30 days before:</b> ' + wor.slice(0, 3).map((p) => '<b>' + esc(p.name) + '</b> (+' + W.fm(p.delta) + ')').join(', ') + '. Ask what changed: leave, new product, too many chats.']);
    const tc = wavg(D.team.close_series || [], 'avg'); const slowClose = P.filter((p) => p.close && p.close.n >= 3 && tc && p.close.avg > 1.5 * tc).sort((a, b) => b.close.avg - a.close.avg);
    if (slowClose.length) out.push(['a', '🧾 <b>Orders take longest to close with:</b> ' + slowClose.slice(0, 3).map((p) => '<b>' + esc(p.name) + '</b> (' + W.fm(p.close.avg) + ' vs team ' + W.fm(tc) + ')').join(', ') + '. Check their follow-ups: a customer who is ready should get the payment link in the same chat.']);
    const lk = (D.orders || {}).link;
    if (lk) out.push([lk.label === 'strong' || lk.label === 'some' ? 'g' : 'a', '📈 <b>Speed ↔ orders link ' + (lk.r > 0 ? '+' : '') + lk.r.toFixed(2) + ' (' + lk.label + ').</b> ' + ({ strong: 'Faster first replies clearly bring more orders — reply speed is worth pushing for everyone.', some: 'Faster replies bring somewhat more orders. Keep pushing speed, but also watch quality.', none: 'Speed alone does not show a difference in orders yet. Look at the quality of the replies and the follow-ups.', reverse: 'Slower first replies are winning more orders — check that fast replies are not just quick one-liners.' })[lk.label]]);
    if (wait && wait.total >= 3) out.push(['r', '⏳ <b>' + wait.total + ' customers are waiting right now</b> (longest ' + wait.longest + ' min). Open the Sales Live cards and tap ⏳ to remind the person.']);
    const few = D.people.filter((p) => p.verdict && p.verdict.key === 'few').length; if (few) out.push(['', 'ℹ️ ' + few + ' people do not have enough data yet (needs 5 replies and 10 queries); they are left out of the colours.']);
    if ((D.complaint_replies_left_out || 0) > 0) out.push(['', 'ℹ️ ' + D.complaint_replies_left_out + ' complaint replies were left out of the pace (the complaint team answers those).']);
    return out;
  }
  function personSuggestions(D, p) {
    const out = [], tp = D.team.pace, tgt = (D.cfg || {}).target || 5, pc = p.pace;
    if (!pc.n) return [['', 'No replies from this person in the last 30 days.']];
    if (p.verdict && p.verdict.key !== 'few') out.push([({ star: 'g', ok: '', quality: 'a', red: 'r' })[p.verdict.key], '<b>' + esc(p.verdict.label) + '</b> — ' + esc(p.verdict.why)]);
    if (has(pc.median) && pc.median > tgt) out.push(['a', 'Typical reply is <b>' + W.fm(pc.median) + '</b> against a target of ' + tgt + ' min: ' + W.fm(pc.median - tgt) + ' to gain. ' + (has(tp.median) ? (pc.median > tp.median ? 'The team typical is ' + W.fm(tp.median) + '.' : 'Already faster than the team (' + W.fm(tp.median) + ').') : '')]);
    if (has(pc.slow_pct) && pc.slow_pct >= 25) out.push(['r', '<b>' + pc.slow_pct + '%</b> of this person\'s replies take over 30 minutes — these are the ones that lose orders.']);
    if (p.loss && p.loss.orders >= 0.5) out.push(['r', 'Estimated loss from slow replies: <b>≈ ' + p.loss.orders + ' orders' + (p.loss.revenue ? ' (₹' + Number(p.loss.revenue).toLocaleString('en-IN') + ')' : '') + '</b> in 30 days.']);
    if (has(p.delta)) out.push([p.delta <= -1 ? 'g' : p.delta >= 3 ? 'a' : '', p.delta <= -1 ? '👏 Faster than the 30 days before by ' + W.fm(Math.abs(p.delta)) + '.' : p.delta >= 3 ? '📉 Slower than the 30 days before by ' + W.fm(p.delta) + '.' : 'About the same as the 30 days before.']);
    const days = p.series.filter((x) => has(x.median) && x.n >= 3); if (days.length >= 4) { const best = days.reduce((a, b) => (b.median < a.median ? b : a)), worst = days.reduce((a, b) => (b.median > a.median ? b : a)); out.push(['', 'Best day: <b>' + esc(best.day) + '</b> (' + W.fm(best.median) + '). Worst day: <b>' + esc(worst.day) + '</b> (' + W.fm(worst.median) + ') — ask what was different.']); }
    if (p.close && p.close.n >= 3) { const tc = wavg(D.team.close_series || [], 'avg'); out.push([tc && p.close.avg > 1.5 * tc ? 'a' : '', '🧾 Orders close in <b>' + W.fm(p.close.avg) + '</b> on average (' + p.close.n + ' orders)' + (tc ? ' — team ' + W.fm(tc) + '.' : '.')]); }
    if (p.after_take && p.after_take.no_reply > 0) out.push(['r', '<b>' + p.after_take.no_reply + '</b> chats taken by this person got no message from them afterwards.']);
    if (p.link) out.push(['', '📈 Speed ↔ orders link for this person: <b>' + (p.link.r > 0 ? '+' : '') + p.link.r.toFixed(2) + '</b> (' + p.link.label + ').']);
    return out;
  }
  const sugHtml = (a, title) => '<div class="wp-card"><h3>' + esc(title) + '</h3><div class="wp-sub">Automatic suggestions from the numbers on this page.</div><ul class="pp-sug">' + (a.length ? a.map((x) => '<li class="' + x[0] + '">' + x[1] + '</li>').join('') : '<li>Not enough data yet.</li>') + '</ul></div>';

  // ---------------------------------------------------------------- compare people side by side + a person's actual replies
  const CMP = ['#0f6e7e', '#7c3aed', '#e11d48', '#d97706', '#16a34a', '#2563eb'];
  function defaultCmp(D) {
    const r = D.people.filter((p) => p.rank), ids = []; r.slice(0, 2).concat(r.slice(-2)).forEach((p) => { if (ids.indexOf(p.emp_id) < 0) ids.push(p.emp_id); });
    D.people.forEach((p) => { if (ids.length < 4 && ids.indexOf(p.emp_id) < 0) ids.push(p.emp_id); }); return ids;
  }
  function compareCard(D, S) {
    const sel = (S.cmp || []).map((id) => D.people.find((p) => p.emp_id === id)).filter(Boolean), range = S.days;
    let h = '<div class="wp-card"><h3>Compare people</h3><div class="wp-sub">Tick the people you want to compare. Their lines are drawn together, day by day. Up to 6.</div><div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">'
      + D.people.map((p) => { const i = (S.cmp || []).indexOf(p.emp_id), on = i >= 0; return '<label style="display:inline-flex;gap:6px;align-items:center;border:1px solid var(--wp-line);border-radius:99px;padding:5px 11px;font-size:13px;cursor:pointer' + (on ? ';border-color:' + CMP[i % 6] + ';font-weight:700' : '') + '"><input type="checkbox" data-cmp="' + p.emp_id + '"' + (on ? ' checked' : '') + '>' + (on ? '<span style="color:' + CMP[i % 6] + '">●</span>' : '') + esc(p.name) + '</label>'; }).join('') + '</div></div>';
    if (!sel.length) return h;
    const lines = sel.map((p, i) => ({ name: p.name, color: CMP[i % 6], pts: lastN(p.series, range).map((x) => ({ day: x.day, v: x.avg, n: x.n })) }));
    const clines = sel.map((p, i) => ({ name: p.name, color: CMP[i % 6], pts: smoothPts(lastN(p.series, range).map((x) => ({ day: x.day, v: x.close ? x.close.avg : null, n: x.close ? x.close.n : 0 })), S.smooth) }));
    return h + '<div class="pp-two">' + chart(lines, 'Average reply time — compared', 'Lower is better.', { target: (D.cfg || {}).target }) + chart(clines, 'Average order-closing time — compared', 'Orders closed that day.') + '</div><div style="height:12px"></div>';
  }
  // every person at a glance: one small card each, the worst group first
  function mini(vals, color, tgt, w, h) {
    const v = vals.map((x) => (has(x) ? +x : null)), ok = v.filter((x) => x !== null); if (ok.length < 2) return '<div class="wp-sub" style="height:' + h + 'px;display:flex;align-items:center">not enough days</div>';
    const mx = Math.max.apply(null, ok.concat(tgt !== null ? [tgt] : [])) || 1, st = (w - 4) / Math.max(1, v.length - 1), Y = (x) => 3 + (h - 6) * (1 - Math.min(x, mx) / mx); let d = '', pen = false;
    v.forEach((x, i) => { if (x === null) { pen = false; return; } d += (pen ? 'L' : 'M') + (2 + i * st).toFixed(1) + ' ' + Y(x).toFixed(1); pen = true; });
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" style="width:100%;height:auto" aria-hidden="true">' + (tgt !== null ? '<line x1="0" x2="' + w + '" y1="' + Y(tgt) + '" y2="' + Y(tgt) + '" stroke="#16a34a" stroke-width="1.2" stroke-dasharray="2 3"/>' : '') + '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }
  function gridCard(D, S) {
    const order = { red: 0, quality: 1, ok: 2, few: 3, star: 4 }, tgt = (D.cfg || {}).target; const ps = D.people.slice().sort((a, b) => (order[(a.verdict || {}).key] ?? 3) - (order[(b.verdict || {}).key] ?? 3) || (b.pace.median || 0) - (a.pace.median || 0));
    if (!ps.length) return '';
    return '<div class="wp-card"><h3>Everyone\'s graphs, last ' + S.days + ' days</h3><div class="wp-sub">One card per person, the ones to look at first on top (🔴 slow and low orders, then 🟠, then the rest, ⭐ last). Teal = reply time (green dotted line = target), purple = order-closing time. Tap a card to open the person.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px;margin-top:10px">' + ps.map((p) => {
        const sr = lastN(p.series, S.days), cl = smoothPts(sr.map((x) => ({ day: x.day, v: x.close ? x.close.avg : null, n: x.close ? x.close.n : 0 })), S.smooth).map((x) => x.v), v = p.verdict;
        return '<div data-emp="' + p.emp_id + '" role="button" tabindex="0" style="cursor:pointer;border:1px solid ' + (v ? VD[v.key] : 'var(--wp-line)') + ';border-radius:12px;padding:10px"><div style="display:flex;justify-content:space-between;gap:6px;align-items:center"><b class="nm" style="text-decoration:none">' + esc(p.name) + '</b>' + W.chip(p) + '</div>'
          + (v && v.key !== 'few' ? '<div class="wp-sub" style="margin:2px 0 4px">' + esc(v.label) + '</div>' : '<div class="wp-sub" style="margin:2px 0 4px">Not enough data yet</div>')
          + '<div class="wp-sub">⚡ reply · ' + (has(p.pace.avg) ? W.fm(p.pace.avg) + ' avg' : '-') + '</div>' + mini(sr.map((x) => x.avg), '#0f6e7e', tgt !== undefined ? tgt : null, 230, 44)
          + '<div class="wp-sub" style="margin-top:4px">🧾 closing · ' + (p.close ? W.fm(p.close.avg) + ' avg' : '-') + '</div>' + mini(cl, '#7c3aed', null, 230, 44) + '</div>'; }).join('') + '</div></div>';
  }
  const hm = (s) => { const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }); };
  const mcol = (m) => m > 30 ? '#dc2626' : m > 10 ? '#d97706' : '#16a34a';
  function repsCard(p, S) {
    const R = S.rep && S.rep.emp === p.emp_id ? S.rep : null, first = esc(p.name.split(' ')[0]);
    if (!R) return '<div class="wp-card"><h3>💬 ' + first + '\'s replies</h3><div class="wp-sub">What the customer wrote, what ' + first + ' answered and how many minutes it took.</div><button type="button" class="pp-btn" data-replies="' + p.emp_id + '" style="margin-top:8px">Show replies (' + S.days + ' days)</button></div>';
    if (R.err) return '<div class="wp-card" style="color:#dc2626">Could not load the replies. ' + esc(R.err) + ' <button type="button" class="pp-btn gh" data-replies="' + p.emp_id + '">Try again</button></div>';
    if (!R.data) return '<div class="wp-card wp-sub">Loading replies…</div>';
    const rows = R.tab === 'slow' ? R.data.slowest : R.data.recent;
    return '<div class="wp-card"><h3>💬 ' + first + '\'s replies <span class="wp-sub">(' + R.data.n + ' in ' + R.data.days + ' days)</span></h3><div class="pp-top" style="margin:8px 0"><div class="wp-seg">' + [['slow', 'Slowest 15'], ['recent', 'Latest 60']].map((x) => '<button type="button" data-reptab="' + x[0] + '" class="' + (R.tab === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div><span class="wp-sub">A bare "ok" is not counted as a reply. Complaint chats are not listed.</span></div>'
      + (rows.length ? '<div class="wp-sc"><table><thead><tr><th>Customer</th><th>Customer wrote</th><th>' + first + ' replied</th><th>Time taken</th></tr></thead><tbody>' + rows.map((x) => '<tr><td style="white-space:normal;min-width:110px">' + esc(x.name || ('…' + String(x.phone).slice(-4))) + '<div class="wp-sub">' + esc(hm(x.in_at)) + '</div></td><td style="white-space:normal;text-align:left;max-width:260px">' + esc(x.in_text) + '</td><td style="white-space:normal;text-align:left;max-width:260px">' + esc(x.out_text) + '<div class="wp-sub">' + esc(hm(x.out_at)) + '</div></td><td><b style="color:' + (x.night ? '#64748b' : mcol(x.mins)) + '">' + (x.night ? 'next day' : W.fm(x.mins)) + '</b></td></tr>').join('') + '</tbody></table></div>' : '<div class="wp-sub">No replies in this period.</div>') + '</div>';
  }

  // ---------------------------------------------------------------- views
  function tiles(arr) { return '<div class="wp-tiles">' + arr.map((t) => '<div class="wp-tile"><small>' + esc(t[0]) + '</small><b>' + t[1] + '</b><span>' + (t[2] || '') + '</span></div>').join('') + '</div>'; }
  const dl = (d) => !has(d) ? '' : (d === 0 ? 'same as before' : (d < 0 ? '<span style="color:#16a34a">▼ ' : '<span style="color:#dc2626">▲ ') + W.fm(Math.abs(d)) + '</span> vs before');

  function teamView(D, S, wait) {
    const T = D.team, tp = T.pace, range = S.days, ser = lastN(T.series, range), cser = lastN(T.close_series || [], range);
    const cAvg = wavg(cser, 'avg'), cN = cser.reduce((a, x) => a + (x.n || 0), 0), rAvg = wavg(ser, 'avg');
    let h = '<div class="pp-top" style="margin-bottom:12px"><div class="wp-seg" role="group" aria-label="Range">' + [[7, '7 days'], [30, '30 days']].map((x) => '<button type="button" data-range="' + x[0] + '" class="' + (range === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>'
      + smoothCb(S) + '<label class="wp-sub">Open a person: <select id="ppSel"><option value="0">— choose —</option>' + D.people.map((p) => '<option value="' + p.emp_id + '">' + esc(p.name) + '</option>').join('') + '</select></label></div>';
    h += tiles([['Typical reply (30 days)', W.fm(tp.median), W.chip(tp) + ' · ' + tp.n + ' replies'], ['Average reply (' + range + ' d)', W.fm(rAvg), dl(has(tp.median) && has(D.team.prev.median) ? Math.round((tp.median - D.team.prev.median) * 10) / 10 : null)],
      ['Within 5 minutes', has(tp.fast_pct) ? tp.fast_pct + '%' : '-', 'slow (30+ min) ' + (has(tp.slow_pct) ? tp.slow_pct + '%' : '-')], ['Average order closing (' + range + ' d)', W.fm(cAvg), cN + ' orders closed'],
      ['Waiting right now', wait ? wait.total : '-', wait && wait.total ? 'longest ' + wait.longest + ' min' : 'nobody waiting'], ['Auto-bot reply', W.fm(T.bot.median), T.bot.n + ' auto replies']]);
    h += '<div class="pp-two">' + chart([{ name: 'Average reply time (people)', color: '#0f6e7e', pts: ser.map((x) => ({ day: x.day, v: x.avg, n: x.n })) }, { name: 'Auto-bot', color: '#94a3b8', dash: true, pts: lastN(T.bot_series || [], range).map((x) => ({ day: x.day, v: x.median, n: x.n })) }], 'Average reply time, day by day', 'How long customers waited for a person, per day. Lower is better.', { target: (D.cfg || {}).target })
      + chart([{ name: 'Average time to close an order' + (S.smooth ? ' (7-day avg)' : ''), color: '#7c3aed', pts: smoothPts(cser.map((x) => ({ day: x.day, v: x.avg, n: x.n })), S.smooth) }], 'Average order-closing time, day by day', 'From the query arriving to its first order, for orders closed that day.') + '</div><div style="height:12px"></div>';
    h += compareCard(D, S) + gridCard(D, S);
    h += sugHtml(teamSuggestions(D, wait), '💡 Suggestions');
    h += peopleTable(D);
    h += W.linkCard ? '<div class="wp">' + W.linkCard(D) + '</div>' : '';
    h += '<div class="wp">' + W.cfgCard(D) + '</div>';
    h += '<div class="wp-sub" style="margin-top:6px">How it is measured: the clock starts at the first customer message nobody has answered and stops at the next real message a person sends (a bare "ok" / "hmm" does not count). Auto-bot messages are timed separately. Chats with an open complaint are left out. Gaps of 8+ hours are left out. Grades: Fast up to 3 min · Good up to 10 · Slow up to 30 · Very slow above 30.</div>';
    return h;
  }
  function peopleTable(D) {
    const ps = D.people; if (!ps.length) return '';
    let h = '<div class="wp-card"><h3>Everyone, last 30 days</h3><div class="wp-sub">Tap a name to open that person. Sorted fastest first; people with under 5 replies are last.</div><div class="wp-sc"><table><thead><tr><th>#</th><th>Person</th><th>Group</th><th>Typical reply</th><th>Average</th><th>Within 5 min</th><th>Slow %</th><th>Replies</th><th>vs before</th><th>30-day trend</th><th>Order closing</th><th>Orders %</th><th>Lost orders</th></tr></thead><tbody>';
    ps.forEach((p) => { const o = p.orders || {}; h += '<tr><td>' + (p.rank || '-') + '</td><td><span class="nm" data-emp="' + p.emp_id + '">' + esc(p.name) + '</span></td><td>' + (p.verdict ? '<span class="wp-chip" style="background:' + VD[p.verdict.key] + '" title="' + esc(p.verdict.why) + '">' + esc(p.verdict.label) + '</span>' : '') + '</td><td>' + W.chip(p) + '</td><td>' + W.fm(p.pace.avg) + '</td><td>' + (has(p.pace.fast_pct) ? p.pace.fast_pct + '%' : '-') + '</td><td>' + (has(p.pace.slow_pct) ? p.pace.slow_pct + '%' : '-') + '</td><td>' + p.pace.n + '</td><td>' + (has(p.delta) ? (p.delta < 0 ? '<span style="color:#16a34a">▼ ' : p.delta > 0 ? '<span style="color:#dc2626">▲ ' : '<span>') + W.fm(Math.abs(p.delta)) + '</span>' : '-') + '</td><td>' + sparkOf(p.series) + '</td><td>' + (p.close ? W.fm(p.close.avg) + '<div class="wp-sub">' + p.close.n + ' orders</div>' : '-') + '</td><td>' + (has(o.conversion) ? o.conversion + '%' : '-') + '</td><td>' + (p.loss && p.loss.orders >= 0.5 ? '<b style="color:#dc2626">≈ ' + p.loss.orders + '</b>' : '-') + '</td></tr>'; });
    return h + '</tbody></table></div></div>';
  }
  function sparkOf(series) {
    const v = (series || []).map((x) => (has(x.median) ? Math.min(60, +x.median) : null)), ok = v.filter((x) => x !== null); if (ok.length < 2) return '<span class="wp-sub">-</span>';
    const w = 110, h = 26, mx = Math.max.apply(null, ok) || 1, st = w / Math.max(1, v.length - 1); let d = '', pen = false;
    v.forEach((x, i) => { if (x === null) { pen = false; return; } d += (pen ? 'L' : 'M') + (i * st).toFixed(1) + ' ' + (h - 2 - (x / mx) * (h - 4)).toFixed(1); pen = true; });
    return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '"><path d="' + d + '" fill="none" stroke="#0f6e7e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }
  function personView(D, p, S, waitP) {
    const range = S.days, T = D.team, ser = lastN(p.series, range), tser = lastN(T.series, range), cser = lastN(p.series.map((x) => ({ day: x.day, n: x.close ? x.close.n : 0, avg: x.close ? x.close.avg : null })), range), tcs = lastN(T.close_series || [], range);
    const o = p.orders || {}, bk = o.buckets || {}; const vd = p.verdict;
    let h = '<div class="pp-top" style="margin-bottom:10px"><button type="button" class="pp-back" data-back="1">← Team</button><h2 style="margin:0;font-size:20px">' + esc(p.name) + '</h2>' + (vd ? '<span class="wp-chip" style="background:' + VD[vd.key] + '" title="' + esc(vd.why) + '">' + esc(vd.label) + '</span>' : '')
      + '<span style="flex:1"></span>' + smoothCb(S) + '<div class="wp-seg" role="group" aria-label="Range">' + [[7, '7 days'], [30, '30 days']].map((x) => '<button type="button" data-range="' + x[0] + '" class="' + (range === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div></div>';
    h += tiles([['Typical reply (30 d)', W.fm(p.pace.median), W.chip(p) + ' · ' + p.pace.n + ' replies'], ['Team typical', W.fm(T.pace.median), 'rank ' + (p.rank ? '#' + p.rank + ' of ' + D.people.filter((x) => x.rank).length : '—')], ['Within 5 minutes', has(p.pace.fast_pct) ? p.pace.fast_pct + '%' : '-', 'team ' + (has(T.pace.fast_pct) ? T.pace.fast_pct + '%' : '-')],
      ['Order closing (30 d)', p.close ? W.fm(p.close.avg) : '-', p.close ? p.close.n + ' orders' : 'no order closed'], ['Orders % (30 d)', has(o.conversion) ? o.conversion + '%' : '-', has(o.queries) ? o.queries + ' queries' : ''], ['Lost to slow replies', p.loss && p.loss.orders >= 0.5 ? '≈ ' + p.loss.orders : '-', p.loss && p.loss.revenue ? '₹' + Number(p.loss.revenue).toLocaleString('en-IN') : ''], ['Waiting now', waitP ? waitP.n : '-', waitP && waitP.n ? 'longest ' + waitP.longest + ' min' : 'nobody']]);
    h += '<div class="pp-two">' + chart([{ name: p.name, color: '#0f6e7e', pts: ser.map((x) => ({ day: x.day, v: x.avg, n: x.n })) }, { name: 'Team', color: '#94a3b8', dash: true, pts: tser.map((x) => ({ day: x.day, v: x.avg, n: x.n })) }], 'Average reply time, day by day', 'This person against the whole team.', { target: (D.cfg || {}).target })
      + chart([{ name: p.name, color: '#7c3aed', pts: smoothPts(cser.map((x) => ({ day: x.day, v: x.avg, n: x.n })), S.smooth) }, { name: 'Team', color: '#94a3b8', dash: true, pts: smoothPts(tcs.map((x) => ({ day: x.day, v: x.avg, n: x.n })), S.smooth) }], 'Average order-closing time, day by day', 'Orders closed that day, from the query arriving.') + '</div><div style="height:12px"></div>';
    h += sugHtml(personSuggestions(D, p), '💡 What to do with ' + p.name.split(' ')[0]);
    // orders by reply speed
    if (bk.fast) { const cell = (x) => !x || !x.queries ? '-' : '<b>' + x.orders + '</b> <span class="wp-sub">of ' + x.queries + ' · ' + (has(x.conversion) ? x.conversion + '%' : '-') + '</span>'; h += '<div class="wp-card"><h3>Orders by how fast ' + esc(p.name.split(' ')[0]) + ' answered (30 days)</h3><div class="wp-sc"><table><thead><tr><th>Within 5 min</th><th>5 – 30 min</th><th>Over 30 min</th><th>Not answered</th></tr></thead><tbody><tr><td>' + cell(bk.fast) + '</td><td>' + cell(bk.mid) + '</td><td>' + cell(bk.slow) + '</td><td>' + cell(bk.none) + '</td></tr></tbody></table></div></div>'; }
    if (p.after_take && p.after_take.by && p.after_take.by.length) h += '<div class="wp-card"><h3>After taking a query</h3><div class="wp-sc"><table><thead><tr><th>How it was taken</th><th>Taken</th><th>Median to first message</th></tr></thead><tbody>' + p.after_take.by.map((x) => '<tr><td>' + esc(x.label) + '</td><td>' + x.n + '</td><td>' + W.fm(x.median) + '</td></tr>').join('') + '</tbody></table></div></div>';
    if (waitP && waitP.n) h += '<div class="wp-card"><h3>⏳ Waiting for ' + esc(p.name.split(' ')[0]) + ' right now</h3><div class="wp-sc"><table><tbody>' + waitP.items.map((i) => '<tr><td>' + esc(i.name || ('…' + String(i.phone).slice(-4))) + '</td><td><b>' + i.mins + ' min</b></td></tr>').join('') + '</tbody></table></div><button type="button" class="pp-btn red" data-nudge="' + p.emp_id + '" style="margin-top:10px">🔔 Tell ' + esc(p.name.split(' ')[0]) + ' to reply now</button> <span class="wp-sub" id="ppNudgeMsg"></span></div>';
    h += repsCard(p, S);
    // the daily table
    h += '<div class="wp-card"><h3>Day by day</h3><div class="wp-sc"><table><thead><tr><th>Date</th><th>Replies</th><th>Typical</th><th>Average</th><th>Orders closed</th><th>Avg closing</th></tr></thead><tbody>' + p.series.slice().reverse().map((x) => '<tr><td>' + esc(x.day) + '</td><td>' + x.n + '</td><td>' + (has(x.median) ? W.fm(x.median) : '-') + '</td><td>' + (has(x.avg) ? W.fm(x.avg) : '-') + '</td><td>' + (x.close ? x.close.n : '-') + '</td><td>' + (x.close ? W.fm(x.close.avg) : '-') + '</td></tr>').join('') + '</tbody></table></div></div>';
    return h;
  }

  function mount(el, o) {
    css(); el.classList.add('wp', 'pp'); const S = { emp: o.emp || 0, days: o.days || 30 }; let D = null, WT = null; S.cmp = null; S.rep = null;
    const setUrl = () => { try { history.replaceState(null, '', 'pace.html' + (S.emp ? '?emp=' + S.emp : '') + (S.days === 7 ? (S.emp ? '&' : '?') + 'days=7' : '')); } catch (e) { /* ignore */ } };
    const draw = () => {
      if (!D) return; const p = S.emp ? D.people.find((x) => x.emp_id === S.emp) : null; if (S.emp && !p) S.emp = 0;
      let wait = null; if (WT) { const by = WT.by_emp || {}; let total = 0, longest = 0; Object.keys(by).forEach((k) => { total += by[k].n; longest = Math.max(longest, by[k].longest); }); wait = { total, longest }; }
      el.innerHTML = S.emp ? personView(D, p, S, WT && WT.by_emp ? WT.by_emp[S.emp] : null) : teamView(D, S, wait); setUrl();
    };
    const loadReps = (id) => { S.rep = { emp: id, days: S.days, data: null, tab: 'slow' }; draw(); api.get('wq.pace_replies', { emp_id: id, days: S.days }).then((d) => { if (S.rep && S.rep.emp === id) { S.rep.data = d; draw(); } }, (er) => { if (S.rep && S.rep.emp === id) { S.rep = { emp: id, err: (er && er.message) || '' }; draw(); } }); };
    const load = () => { el.innerHTML = '<div class="wp-card wp-sub">Loading pace…</div>'; return Promise.all([api.get('wq.pace', { days: 30 }), api.get('wq.pace_waiting').catch(() => null)]).then((r) => { D = r[0]; WT = r[1]; if (!S.cmp) S.cmp = defaultCmp(D); draw(); }, (e) => { el.innerHTML = '<div class="wp-card" style="color:#dc2626">Pace could not load. ' + esc(e && e.message ? e.message : '') + ' <button type="button" class="pp-btn gh" data-retry="1">Try again</button></div>'; }); };
    el.addEventListener('click', (e) => {
      const t = e.target;
      if (t.closest('[data-retry]')) { load(); return; }
      const r = t.closest('[data-range]'); if (r) { S.days = +r.dataset.range; draw(); return; }
      const rp = t.closest('[data-replies]'); if (rp) { loadReps(+rp.dataset.replies); return; }
      const rt = t.closest('[data-reptab]'); if (rt && S.rep) { S.rep.tab = rt.dataset.reptab; draw(); return; }
      const n = t.closest('[data-emp]'); if (n) { S.emp = +n.dataset.emp; draw(); window.scrollTo(0, 0); return; }
      if (t.closest('[data-back]')) { S.emp = 0; draw(); window.scrollTo(0, 0); return; }
      const nb = t.closest('[data-nudge]'); if (nb) { nb.disabled = true; api.post('wq.pace_nudge', { emp_id: +nb.dataset.nudge }).then((x) => { const m = el.querySelector('#ppNudgeMsg'); if (m) m.textContent = x.sent ? 'Reminder sent ✓' : 'Sent, but this phone has not allowed alerts.'; }, (er) => { const m = el.querySelector('#ppNudgeMsg'); if (m) m.textContent = er.message || 'Could not send'; nb.disabled = false; }); return; }
      const sv = t.closest('.wp-save'); if (sv) { const body = {}; el.querySelectorAll('[data-cfg]').forEach((i) => { body[i.dataset.cfg] = i.type === 'checkbox' ? i.checked : +i.value; }); const note = el.querySelector('.wp-saved'); api.post('wq.pace_cfg', body).then(() => { if (note) note.textContent = 'Saved ✓'; }, (er) => { if (note) note.textContent = (er && er.message) || 'Could not save'; }); }
    });
    el.addEventListener('change', (e) => { if (e.target.closest && e.target.closest('[data-smooth]')) { S.smooth = e.target.checked; draw(); return; } const c = e.target.closest && e.target.closest('[data-cmp]'); if (c) { const id = +c.dataset.cmp, i = S.cmp.indexOf(id); if (i >= 0) S.cmp.splice(i, 1); else if (S.cmp.length < 6) S.cmp.push(id); draw(); return; } if (e.target.id === 'ppSel' && +e.target.value) { S.emp = +e.target.value; draw(); window.scrollTo(0, 0); } });
    setInterval(() => { if (!document.hidden) api.get('wq.pace_waiting').then((w) => { WT = w; if (!el.querySelector('input:focus')) draw(); }, () => {}); }, 60000);   // who is waiting is live
    return load();
  }
  window.WaPacePage = { mount };
})();
