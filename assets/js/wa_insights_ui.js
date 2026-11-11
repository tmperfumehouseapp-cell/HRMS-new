/* wa_insights_ui.js — 📊 Campaign insights: one page to compare days, weekdays, customer types, messages and hours.
 *   Registers the screen 'insights' (WaPanels.add). Opens from the menu (SEND → Campaign insights) and from the "Campaign days" card on Campaigns.
 *   It only READS answers that are already cached on the server (wa.camp_days, wa.camp_snap, wa.msg_rank, wa.camp_hours, wa.camp_best_time):
 *   nothing heavy is computed when the page opens, and each block draws as soon as its own answer arrives. Phone first, small type.
 */
(function () {
  if (!window.WaPanels || !WaPanels.util || typeof api === 'undefined') return;
  const { esc, fmt } = WaPanels.util;
  if (!document.getElementById('wiCss')) {
    const s = document.createElement('style'); s.id = 'wiCss';
    s.textContent = `.wi h2{font-size:15px;margin:0}.wi .wp-hint{font-size:11px}.wi .wp-cd{padding:9px 10px;margin:0 0 8px}.wi h3{font-size:12.5px;margin:0 0 5px;font-weight:800}
    .wi-t{width:100%;border-collapse:collapse;font-size:11px}.wi-t th{color:#7a8499;font-weight:600;text-align:right;padding:2px 4px;white-space:nowrap}.wi-t td{text-align:right;padding:3px 4px;border-top:1px solid rgba(128,128,128,.22);white-space:nowrap}
    .wi-t th:first-child,.wi-t td:first-child{text-align:left;white-space:normal;max-width:150px;overflow:hidden;text-overflow:ellipsis}.wi-t tr.best td{background:rgba(34,197,94,.12)}.wi-t tr.weak td{background:rgba(239,68,68,.08)}
    .wi-up{color:#15803d;font-weight:700}.wi-dn{color:#c2262b;font-weight:700}.wi-g{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px}.wi-g div{background:rgba(128,128,128,.1);border-radius:9px;padding:4px 6px}.wi-g small{display:block;font-size:9.5px;opacity:.7}.wi-g b{font-size:12.5px}
    .wi-bars{display:flex;gap:2px;align-items:flex-end;height:46px}.wi-bars i{flex:1;border-radius:3px 3px 0 0;background:#c7d2fe;min-height:2px}.wi-bars i.top{background:#16a34a}.wi-bl{display:flex;gap:2px;font-size:8.5px;opacity:.65}.wi-bl span{flex:1;text-align:center}
    .wi-pills{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.wi-pills .wp-btn{min-height:24px;padding:1px 9px;font-size:11px}`;
    document.head.appendChild(s);
  }
  const inr = (v) => '₹' + fmt(Math.round(v || 0)), pc = (a, b) => (b ? Math.round(100 * a / b) + '%' : '—'), p1 = (v) => (v == null ? '—' : (Math.round(v * 1000) / 10) + '%');
  const dt = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }), wk = (d) => new Date(d + 'T00:00:00').getDay();
  const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], hl = (h) => (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? 'a' : 'p');
  const sum = (a, f) => a.reduce((x, r) => x + (+f(r) || 0), 0);

  WaPanels.add('insights', (el, ctx) => {
    const S = { days: 14, d30: null, snap: null, rank: null, hrs: null, bt: null, err: {}, lim: { type: 6, msg: 8, seg: 6 } };
    let dead = false; const live = () => !dead && el.isConnected;
    function card(title, hint, body) { return `<div class="wp-cd"><h3>${title}${hint ? ` <span class="wp-hint" style="margin:0;font-weight:400">${hint}</span>` : ''}</h3>${body}</div>`; }
    const wait = (k) => (S.err[k] ? `<div class="wp-hint" style="color:#c2262b">${esc(S.err[k])}</div>` : '<div class="wp-hint">Loading…</div>');

    // ---------------------------------------------------------------- 1 · compare the days
    function cmp() {
      const D = S.d30; if (!D) return card('📅 Compare days', '', wait('d30'));
      const R = D.rows || []; if (!R.length) return card('📅 Compare days', '', '<div class="wp-hint">No campaign sends in the last 30 days.</div>');
      const cur = R[0], prev = R.slice(1, 8), n7 = prev.length || 1, r30 = R.slice(0, 30);
      const fr = (v) => fmt(Math.round(v)); const M = [['Spent', (r) => r.spend, inr, 0], ['Delivered', (r) => r.delivered, fr, 1], ['Queries', (r) => r.queries, fr, 1], ['Orders', (r) => r.orders, (v) => fmt(Math.round(v * 10) / 10), 1], ['Sales', (r) => r.revenue, inr, 1], ['₹ / query', (r) => (r.queries ? r.spend / r.queries : null), inr, -1]];
      const avg = (rows, f) => { const v = rows.map(f).filter((x) => x != null); return v.length ? sum(v, (x) => x) / v.length : null; };
      const tr = M.map(([l, f, fm, good]) => { const a = f(cur), b = avg(prev, f), c = avg(r30, f), dl = a != null && b ? Math.round(100 * (a - b) / b) : null;
        const cl = dl == null || !good ? '' : ((dl > 0) === (good > 0) ? 'wi-up' : 'wi-dn');
        return `<tr><td>${l}</td><td><b>${a == null ? '—' : fm(a)}</b></td><td>${b == null ? '—' : fm(b)}</td><td class="${cl}">${dl == null ? '' : (dl > 0 ? '▲' : dl < 0 ? '▼' : '') + Math.abs(dl) + '%'}</td><td>${c == null ? '—' : fm(c)}</td></tr>`; }).join('');
      const t7 = R.slice(0, 7), T = (rows) => ({ sp: sum(rows, (r) => r.spend), q: sum(rows, (r) => r.queries), o: sum(rows, (r) => r.orders), rv: sum(rows, (r) => r.revenue) });
      const a7 = T(t7), a30 = T(r30);
      return card('📅 Compare days', 'latest day vs the 7 days before it', `<table class="wi-t"><thead><tr><th></th><th>${dt(cur.day)}</th><th>7-day avg</th><th>change</th><th>30-day avg</th></tr></thead><tbody>${tr}</tbody></table>
        <div class="wi-g" style="margin-top:6px;grid-template-columns:repeat(2,minmax(0,1fr))"><div><small>Last 7 days</small><b>${inr(a7.sp)} → ${fmt(a7.q)} queries · ${fmt(a7.o)} orders · ${inr(a7.rv)}</b></div><div><small>Last 30 days</small><b>${inr(a30.sp)} → ${fmt(a30.q)} queries · ${fmt(a30.o)} orders · ${inr(a30.rv)}</b></div></div>
        <div class="wp-hint" style="margin:5px 0 0">Queries, orders and sales are the live board’s “campaign” queries, by the day they started. Updated ${esc(String(D.at || '').slice(11, 16))}.</div>`);
    }
    // ---------------------------------------------------------------- 2 · which weekday is best, and the best single days
    function days() {
      const D = S.d30; if (!D) return card('🏆 Best days', '', wait('d30')); const R = D.rows || []; if (!R.length) return '';
      const g = {}; R.forEach((r) => { const k = wk(r.day); (g[k] = g[k] || []).push(r); });
      const W = Object.keys(g).map((k) => { const a = g[k], sp = sum(a, (r) => r.spend), q = sum(a, (r) => r.queries); return { k: +k, n: a.length, q: q / a.length, o: sum(a, (r) => r.orders) / a.length, rv: sum(a, (r) => r.revenue) / a.length, sp: sp / a.length, cpq: q ? sp / q : null }; });
      const bestK = (W.slice().sort((a, b) => b.rv - a.rv)[0] || {}).k, enough = W.every((w) => w.n >= 3);
      const top = R.slice().sort((a, b) => b.revenue - a.revenue).slice(0, 4);
      return card('🏆 Best days', 'average per weekday, last 30 days', `<table class="wi-t"><thead><tr><th>Day</th><th>Days</th><th>Spent</th><th>Queries</th><th>Orders</th><th>Sales</th><th>₹/query</th></tr></thead><tbody>${W.sort((a, b) => ((a.k + 6) % 7) - ((b.k + 6) % 7)).map((w) => `<tr class="${w.k === bestK ? 'best' : ''}"><td>${WD[w.k]}${w.k === bestK ? ' 🏆' : ''}</td><td>${w.n}</td><td>${inr(w.sp)}</td><td>${fmt(Math.round(w.q))}</td><td>${(Math.round(w.o * 10) / 10)}</td><td>${inr(w.rv)}</td><td>${w.cpq != null ? inr(w.cpq) : '—'}</td></tr>`).join('')}</tbody></table>
        <div class="wp-hint" style="margin:5px 0 0">Top days by sales: ${top.map((r) => `<b>${dt(r.day)}</b> (${WD[wk(r.day)]}) ${inr(r.revenue)}`).join(' · ')}.${enough ? '' : ' A weekday with fewer than 3 days is only a hint.'}</div>`);
    }
    // ---------------------------------------------------------------- 3 · customer types (the type chosen in each campaign)
    function types() {
      const C = S.snap && S.snap.campaigns; if (!C) return card('👥 Customer types', '', wait('snap'));
      const g = {}; C.forEach((c) => { const k = c.counts || {}, sent = (k.sent || 0) + (k.held || 0); if (!sent || c.status === 'draft') return; const t = c.aud_type || 'Not typed'; const x = g[t] = g[t] || { t, c: 0, s: 0, d: 0, r: 0 }; x.c++; x.s += sent; x.d += k.delivered || 0; x.r += k.replied || 0; });
      const A = Object.values(g).sort((a, b) => (b.s >= 300 ? b.r / Math.max(1, b.d) : -1) - (a.s >= 300 ? a.r / Math.max(1, a.d) : -1)); if (!A.length) return card('👥 Customer types', '', '<div class="wp-hint">No sent campaigns yet.</div>');
      const best = A.find((x) => x.s >= 300), L = S.lim.type;
      return card('👥 Customer types', 'the type chosen in each campaign · best reply rate first', `<table class="wi-t"><thead><tr><th>Type</th><th>Camps</th><th>Sent</th><th>Deliv</th><th>Replied</th></tr></thead><tbody>${A.slice(0, L).map((x) => `<tr class="${best && x === best ? 'best' : ''}"><td>${esc(x.t)}</td><td>${x.c}</td><td>${fmt(x.s)}</td><td>${pc(x.d, x.s)}</td><td>${pc(x.r, x.d)}</td></tr>`).join('')}</tbody></table>${A.length > L ? `<div style="text-align:center;margin-top:5px"><button type="button" class="wp-btn s" data-more="type">Load more (${A.length - L})</button></div>` : ''}
        <div class="wp-hint" style="margin:5px 0 0">Reply rate = replies ÷ delivered. Types with under 300 sent are listed last. Pick a type when you make a campaign and this table fills itself.</div>`);
    }
    // ---------------------------------------------------------------- 4 · messages: what works, what does not (+ by customer group)
    function msgs() {
      const K = S.rank; if (!K) return card('✉️ Messages', '', wait('rank')); if (K.pending) return card('✉️ Messages', '', '<div class="wp-hint">The ranking is being prepared in the background — open this page again in a few minutes.</div>');
      const rows = (K.rows || []).filter((r) => r.n >= 300); if (!rows.length) return card('✉️ Messages', '', '<div class="wp-hint">Not enough sends yet (a message needs about 300).</div>');
      const sc = (r) => (r.d || 0) * (r.rep || 0), A = rows.slice().sort((a, b) => sc(b) - sc(a)), L = S.lim.msg, nm = (r) => esc(String(r.name || '').replace(/^house_aisensy_/, '').replace(/_/g, ' '));
      const best = A.slice(0, 3).map((r) => r.id), weak = A.length > 6 ? A.slice(-3).map((r) => r.id) : [];
      const tips = (K.tips || []).slice(0, 4).map((t) => '• ' + esc(typeof t === 'string' ? t : (t.text || t.t || t.tip || ''))).filter((x) => x.length > 3);
      let h = `<table class="wi-t"><thead><tr><th>Message</th><th>Sent</th><th>Deliv</th><th>Reply</th><th>Orders</th><th>₹/order</th><th>ROI</th></tr></thead><tbody>${A.slice(0, L).map((r) => `<tr class="${best.includes(r.id) ? 'best' : weak.includes(r.id) ? 'weak' : ''}"><td title="${esc(r.name)}">${nm(r)}</td><td>${fmt(r.n)}</td><td>${p1(r.d)}</td><td>${p1(r.rep)}</td><td>${r.ord == null ? '—' : fmt(r.ord)}</td><td>${r.cpo != null ? inr(r.cpo) : '—'}</td><td>${r.roi != null ? '×' + r.roi : '—'}</td></tr>`).join('')}</tbody></table>`;
      if (A.length > L) h += `<div style="text-align:center;margin-top:5px"><button type="button" class="wp-btn s" data-more="msg">Load more (${A.length - L})</button></div>`;
      h += `<div class="wp-hint" style="margin:5px 0 0">🟩 best 3 (delivered × reply) · ${weak.length ? '🟥 weakest 3 · ' : ''}Reply = replies ÷ delivered. Orders and ROI are counted ${K.win || 3} days after the send.${tips.length ? '<br>' + tips.join('<br>') : ''}</div>`;
      // the same messages by customer group
      const sg = {}; (K.rows || []).forEach((r) => (r.by_seg || []).forEach((s) => { const x = sg[s.seg] = sg[s.seg] || { seg: s.seg, n: 0, dn: 0, r: 0 }; x.n += s.n; x.dn += s.dn; x.r += s.rep_n; }));
      const G = Object.values(sg).filter((x) => x.n >= 300).sort((a, b) => b.r / Math.max(1, b.dn) - a.r / Math.max(1, a.dn));
      if (G.length) { const LG = S.lim.seg; h += `<h3 style="margin-top:9px">By customer group <span class="wp-hint" style="margin:0;font-weight:400">all these messages together</span></h3><table class="wi-t"><thead><tr><th>Group</th><th>Sent</th><th>Deliv</th><th>Reply</th></tr></thead><tbody>${G.slice(0, LG).map((x, i) => `<tr class="${i === 0 ? 'best' : ''}"><td>${esc(x.seg)}</td><td>${fmt(x.n)}</td><td>${pc(x.dn, x.n)}</td><td>${pc(x.r, x.dn)}</td></tr>`).join('')}</tbody></table>${G.length > LG ? `<div style="text-align:center;margin-top:5px"><button type="button" class="wp-btn s" data-more="seg">Load more (${G.length - LG})</button></div>` : ''}`; }
      return card('✉️ Messages — what works', S.days + ' days', h);
    }
    // ---------------------------------------------------------------- 5 · which hours work
    function hours() {
      const H = S.hrs, B = S.bt; if (!H && !B) return card('🕒 Hours', '', wait('hrs'));
      let h = '';
      if (H) { const M = H.week || {}, order = []; for (let i = 0; i < 24; i++) order.push(((H.start_hour || 0) + i) % 24); const rate = (x) => (M[x] && M[x][0] >= 50 ? M[x][1] / M[x][0] : null), top = order.filter((x) => rate(x) !== null).sort((a, b) => rate(b) - rate(a)).slice(0, 3);
        h += `<div class="wp-hint" style="margin:0 0 3px">Delivered ÷ pushed, last 7 days${top.length ? ' · best: <b>' + top.map(hl).join(', ') + '</b>' : ''}</div><div class="wi-bars">${order.map((x) => { const r = rate(x); return `<i class="${top.includes(x) ? 'top' : ''}" style="height:${r === null ? 2 : Math.max(4, Math.round(r * 100 * 0.44))}px" title="${hl(x)} · ${r === null ? 'few sends' : Math.round(r * 100) + '% delivered'}"></i>`; }).join('')}</div><div class="wi-bl">${order.map((x) => `<span>${x % 3 === 0 ? hl(x) : ''}</span>`).join('')}</div>`; }
      if (B && B.hist) { const mx = Math.max(1, ...B.hist), best = (B.best || []).slice(0, 3);
        h += `<div class="wp-hint" style="margin:8px 0 3px">When customers write to us, last 30 days${best.length ? ' · busiest: <b>' + best.map(hl).join(', ') + '</b>' : ''}</div><div class="wi-bars">${B.hist.map((v, x) => `<i style="height:${Math.max(2, Math.round(v * 44 / mx))}px;${best.includes(x) ? 'background:#16a34a' : ''}" title="${hl(x)} · ${fmt(v)}"></i>`).join('')}</div><div class="wi-bl">${B.hist.map((v, x) => `<span>${x % 3 === 0 ? hl(x) : ''}</span>`).join('')}</div>`; }
      return card('🕒 Which hours work', 'send when it delivers and when they write', h);
    }
    function draw() {
      if (!live()) return;
      el.innerHTML = `<div class="wp wi"><div class="wp-top"><div style="flex:1;min-width:150px"><h2>📊 Campaign insights</h2><p class="wp-hint" style="margin:2px 0 0">Compare days, weekdays, customer types, messages and hours. Read-only — nothing here sends anything.</p></div>
        <div class="wi-pills">${[7, 14, 30].map((n) => `<button type="button" class="wp-btn s${S.days === n ? ' p' : ''}" data-d="${n}">${n} days</button>`).join('')}<button type="button" class="wp-btn s" id="wiRf">🔄</button><button type="button" class="wp-btn s" id="wiBk">‹ Campaigns</button></div></div>
        ${cmp()}${days()}${types()}${msgs()}${hours()}</div>`;
      el.querySelectorAll('[data-d]').forEach((b) => { b.onclick = () => { S.days = +b.dataset.d; S.rank = null; draw(); loadRank(); }; });
      el.querySelectorAll('[data-more]').forEach((b) => { b.onclick = () => { S.lim[b.dataset.more] += 10; draw(); }; });
      el.querySelector('#wiRf').onclick = () => { S.d30 = S.snap = S.rank = S.hrs = S.bt = null; S.err = {}; draw(); loadAll(true); };
      el.querySelector('#wiBk').onclick = () => { if (ctx && ctx.go) ctx.go('campaigns'); };
    }
    const take = (k, p, set) => p.then((d) => { if (!live()) return; set(d); delete S.err[k]; draw(); }).catch((e) => { if (!live()) return; S.err[k] = e.message || 'Could not load'; draw(); });
    const loadRank = () => take('rank', api.get('wa.msg_rank', { days: S.days }), (d) => { S.rank = d; });
    function loadAll(fresh) {
      take('d30', api.get('wa.camp_days', { days: 30 }), (d) => { S.d30 = d; });
      take('snap', api.get('wa.camp_snap'), (d) => { S.snap = d && d.snap ? d.snap : null; if (!S.snap) throw new Error('The campaign list is not ready yet.'); });
      loadRank();
      take('hrs', api.get('wa.camp_hours'), (d) => { S.hrs = d; });
      take('bt', api.get('wa.camp_best_time'), (d) => { S.bt = d; });
    }
    draw(); loadAll();
    return () => { S.d30 = S.snap = S.rank = S.hrs = S.bt = null; loadAll(true); };
  });
})();
