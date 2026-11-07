/* play_card.js — "My game" HUD on the salesperson home (My Sales). Mount: PlayCard.mount(element).
   A dark game panel: level shield + XP bar, quests (the three things that make a level), the treasure chest (Mystery gift), streak, medals,
   last week's stat tiles and the team's wins. Data from play.php (play.level / play.gifts / play.gift_open / play.week / play.me / play.feed / play.clap).
   A part that fails to load is left out. The Dino strip (dino.js) is mounted separately at the top of the page. */
(function () {
  if (window.PlayCard) return;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var inr = function (n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); };
  var ago = function (iso) {
    var t = new Date(String(iso).replace(' ', 'T')).getTime(); if (!t) return '';
    var m = Math.max(0, Math.round((Date.now() - t) / 60000));
    return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' d ago';
  };
  var LV = { bronze: { n: 'Bronze', a: '#fcd9a8', b: '#b45309', c: '#78350f' }, silver: { n: 'Silver', a: '#f8fafc', b: '#94a3b8', c: '#475569' }, gold: { n: 'Gold', a: '#fef08a', b: '#eab308', c: '#a16207' } };
  var QI = { sales: '💰', days: '⏰', rating: '⭐' };
  var NEED = { sales: function (v) { return inr(v); }, days: function (v) { return Math.round(v) + ''; }, rating: function (v) { return Number(v).toFixed(1); } };
  var QN = { sales: 'Sales', days: 'On-time days', rating: 'Rating' };

  function shield(k, size) {
    var c = LV[k] || LV.bronze, id = 'sg' + k + size;
    return '<svg class="pg-shield" width="' + size + '" height="' + size * 1.12 + '" viewBox="0 0 64 72" aria-hidden="true"><defs><linearGradient id="' + id + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' + c.a + '"/><stop offset=".55" stop-color="' + c.b + '"/><stop offset="1" stop-color="' + c.c + '"/></linearGradient></defs>'
      + '<path d="M32 3 59 13v22c0 17-11 28-27 34C16 63 5 52 5 35V13Z" fill="url(#' + id + ')" stroke="rgba(255,255,255,.55)" stroke-width="2"/><path d="M32 11 52 18v17c0 12-8 21-20 26C20 56 12 47 12 35V18Z" fill="rgba(255,255,255,.14)"/>'
      + '<text x="32" y="43" text-anchor="middle" font-size="26" font-weight="800" font-family="Manrope,system-ui,sans-serif" fill="#fff" stroke="rgba(0,0,0,.25)" stroke-width=".8">' + c.n[0] + '</text></svg>';
  }
  function xpPct(L) { var r = L.reqs || []; if (!r.length) return 100; return Math.round(r.reduce(function (a, q) { return a + q.pct; }, 0) / r.length); }

  function heroHtml(L, me) {
    if (!L || !L.linked) return '';
    var c = LV[L.level], nxt = L.next ? LV[L.next] : null, xp = L.next ? xpPct(L) : 100;
    return '<div class="pg-hero">' + shield(L.level, 68)
      + '<div class="pg-hmain"><div class="pg-kicker">Level this month</div><div class="pg-title">' + c.n.toUpperCase() + '</div>'
      + '<div class="pg-xp" role="progressbar" aria-valuenow="' + xp + '" aria-valuemin="0" aria-valuemax="100"><i style="width:' + xp + '%"></i></div>'
      + '<div class="pg-xpl">' + (nxt ? xp + '% to <b>' + nxt.n + '</b>' + (L.perks && L.perks[L.next] ? ' · ' + esc(L.perks[L.next]) : '') : 'Top level. Hold it to the month end.') + '</div></div>'
      + '<div class="pg-chips"><span class="pg-chip" title="Bonus points from Mystery gifts">⭐ ' + (L.points || 0) + '</span>' + (me && me.linked ? '<span class="pg-chip fire' + (me.streak > 0 ? '' : ' off') + '" title="On-time days in a row">🔥 ' + (me.streak || 0) + '</span>' : '') + '</div></div>';
  }
  function questsHtml(L) {
    if (!L || !L.linked || !L.next) return '';
    return '<div class="pg-sec"><div class="pg-h">Quests to reach ' + LV[L.next].n + '</div>' + (L.reqs || []).map(function (r) {
      return '<div class="pg-q' + (r.met ? ' done' : '') + '"><span class="pg-qi">' + (r.met ? '✅' : QI[r.key]) + '</span><div class="pg-qm"><div class="pg-qt"><b>' + QN[r.key] + '</b><span>' + NEED[r.key](r.have) + ' / ' + NEED[r.key](r.need) + '</span></div><div class="pg-bar"><i style="width:' + r.pct + '%"></i></div></div></div>';
    }).join('') + '</div>';
  }
  function chestHtml(G) {
    if (!G) return '';
    if (G.new > 0) return '<div class="pg-sec"><button type="button" class="pg-chest open" id="pcGift" data-id="' + G.gifts[0].id + '"><span class="pg-rays"></span><span class="pg-cico">🎁</span><span class="pg-ctx"><b>Mystery gift ready!</b><small>' + esc(G.gifts[0].why) + (G.new > 1 ? ' · +' + (G.new - 1) + ' more waiting' : '') + '</small></span><span class="pg-cta">OPEN</span></button></div>';
    return '<div class="pg-sec"><div class="pg-chest locked"><span class="pg-cico">🔒</span><span class="pg-ctx"><b>Mystery gift · locked</b><small>' + esc(G.earn || 'Win something big to earn one.') + '</small></span></div></div>';
  }
  function medalsHtml(L, me) {
    if (!L || !L.linked) return '';
    var rank = { bronze: 0, silver: 1, gold: 2 }[L.level] || 0, best = Math.max((me && me.best) || 0, (me && me.streak) || 0);
    var M = [['🥉', 'Bronze', rank >= 0], ['🥈', 'Silver', rank >= 1], ['🥇', 'Gold', rank >= 2], ['🔥', '3 days', best >= 3], ['🔥', '7 days', best >= 7], ['🔥', '14 days', best >= 14], ['🏆', '30 days', best >= 30]];
    return '<div class="pg-sec"><div class="pg-h">Medals</div><div class="pg-medals">' + M.map(function (m) { return '<div class="pg-medal' + (m[2] ? ' on' : '') + '"><span>' + m[0] + '</span><small>' + m[1] + '</small></div>'; }).join('') + '</div></div>';
  }
  function weekHtml(W) {
    if (!W || !W.linked) return '';
    var head = '<div class="pg-h">Last week · ' + esc(W.from.slice(5)) + ' to ' + esc(W.to.slice(5)) + '</div>';
    if (!W.sales && !W.orders) return '<div class="pg-sec">' + head + '<div class="pg-empty">No allocated orders last week. This week is a fresh start.</div></div>';
    var diff = W.sales - W.last_week_sales, dtxt = W.last_week_sales ? (diff >= 0 ? '▲ ' + inr(diff) + ' vs the week before' : '▼ ' + inr(-diff) + ' vs the week before') : 'First full week';
    var medal = W.rank === 1 ? '🥇' : W.rank === 2 ? '🥈' : W.rank === 3 ? '🥉' : '🎖️';
    return '<div class="pg-sec">' + head + '<div class="pg-big">' + inr(W.sales) + '</div><div class="pg-sub ' + (diff >= 0 ? 'up' : 'dn') + '">' + dtxt + '</div>'
      + '<div class="pg-tiles"><div><span>🛒</span><b>' + W.orders + '</b><small>orders</small></div><div><span>⏰</span><b>' + W.on_time_days + '</b><small>on-time days</small></div><div><span>' + medal + '</span><b>' + (W.rank ? '#' + W.rank : '—') + '</b><small>of ' + W.players + ' in the team</small></div></div>'
      + (W.best_day ? '<div class="pg-sub">⚡ Best day: ' + esc(new Date(W.best_day.day + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long' })) + ', ' + inr(W.best_day.sales) + '</div>' : '') + '</div>';
  }
  function feedHtml(wins) {
    return '<div class="pg-sec"><div class="pg-h">Team feed</div>' + ((wins || []).length ? wins.slice(0, 6).map(function (w) {
      var ic = w.kind === 'level' ? '🛡️' : w.kind === 'dino' ? '🦖' : w.kind === 'gift' ? '🎁' : '💰';
      return '<div class="pg-win"><span class="pg-wi">' + ic + '</span><div class="pg-wt"><b>' + esc(w.mine_win ? 'You' : w.who) + '</b> ' + esc(w.text) + '<small>' + esc(ago(w.at)) + '</small></div>'
        + (w.mine_win ? '<span class="pg-claps">👏 ' + w.claps + '</span>' : '<button type="button" class="pg-clap' + (w.mine ? ' on' : '') + '" data-id="' + w.id + '" aria-pressed="' + (w.mine ? 'true' : 'false') + '">👏 <span>' + w.claps + '</span></button>') + '</div>';
    }).join('') : '<div class="pg-empty">No wins yet this week. The first allocated order lands here.</div>') + '</div>';
  }

  var D = {};
  function paint(host) {
    host.innerHTML = '<div class="pg-top"><span class="pg-logo">🎮 MY GAME</span></div>' + heroHtml(D.level, D.me) + questsHtml(D.level) + chestHtml(D.gifts) + medalsHtml(D.level, D.me) + weekHtml(D.week) + feedHtml(D.wins);
    var g = host.querySelector('#pcGift');
    if (g) g.onclick = function () {
      g.disabled = true; g.classList.add('shake');
      api.post('play.gift_open', { id: Number(g.dataset.id) }).then(function (r) {
        g.classList.remove('shake'); g.querySelector('.pg-cico').textContent = '🎉';
        g.querySelector('.pg-ctx').innerHTML = '<b class="pg-prize">' + esc(r.text) + '</b><small>Added to your game</small>'; g.querySelector('.pg-cta').textContent = '✓';
        if (window.TMWin) TMWin.win({ title: r.text, prefix: '', from: g, ms: 3800 });
        return Promise.all([api.get('play.gifts').catch(function () { return null; }), api.get('play.level').catch(function () { return null; })]).then(function (x) { D.gifts = x[0]; if (x[1]) D.level = x[1]; setTimeout(function () { paint(host); }, 2600); });
      }).catch(function (e) { g.disabled = false; g.classList.remove('shake'); if (window.TMWin) TMWin.win({ title: (e && e.message) || 'Could not open it', ms: 2500 }); });
    };
    host.querySelectorAll('.pg-clap').forEach(function (b) {
      b.onclick = function () {
        b.disabled = true;
        api.post('play.clap', { id: Number(b.dataset.id) }).then(function (r) { b.classList.toggle('on', !!r.mine); b.setAttribute('aria-pressed', r.mine ? 'true' : 'false'); b.querySelector('span').textContent = r.claps; })
          .catch(function () {}).then(function () { b.disabled = false; });
      };
    });
  }
  var CSS = ''
    + '#shPlay.pg{background:radial-gradient(120% 80% at 0% 0%,#312e81 0%,#1e1b4b 38%,#0f172a 100%) !important;color:#e2e8f0;border:0 !important;border-radius:22px !important;padding:16px !important;box-shadow:0 14px 34px rgba(15,23,42,.35) !important;position:relative;overflow:hidden}'
    + '#shPlay.pg::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 85% 0%,rgba(249,115,22,.22),transparent 45%);pointer-events:none}'
    + '#shPlay.pg>*{position:relative}'
    + '.pg-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.pg-logo{font:800 12px Manrope,system-ui,sans-serif;letter-spacing:.18em;color:#fdba74}'
    + '.pg-hero{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.pg-shield{flex:none;filter:drop-shadow(0 6px 10px rgba(0,0,0,.45))}.pg-hmain{flex:1;min-width:150px}'
    + '.pg-kicker{font-size:10.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#a5b4fc}.pg-title{font:800 28px Manrope,system-ui,sans-serif;letter-spacing:.04em;color:#fff;line-height:1.1}'
    + '.pg-xp{height:12px;border-radius:99px;background:rgba(255,255,255,.12);overflow:hidden;margin:8px 0 4px;box-shadow:inset 0 1px 3px rgba(0,0,0,.4)}'
    + '.pg-xp i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#f97316,#fbbf24);position:relative;transition:width .8s cubic-bezier(.2,.8,.2,1);overflow:hidden}'
    + '.pg-xp i::after,.pg-bar i::after{content:"";position:absolute;inset:0;background:linear-gradient(100deg,transparent 30%,rgba(255,255,255,.55) 50%,transparent 70%);transform:translateX(-100%);animation:pgShine 2.6s ease-in-out infinite}'
    + '@keyframes pgShine{60%,100%{transform:translateX(100%)}}'
    + '.pg-xpl{font-size:12px;color:#cbd5e1}.pg-xpl b{color:#fff}'
    + '.pg-chips{display:flex;flex-direction:column;gap:6px}.pg-chip{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:5px 12px;font:800 14px Manrope,system-ui,sans-serif;color:#fde68a;text-align:center;font-variant-numeric:tabular-nums}.pg-chip.fire{color:#fdba74}.pg-chip.off{opacity:.55;filter:grayscale(.7)}'
    + '.pg-sec{margin-top:16px;padding-top:14px;border-top:1px solid rgba(255,255,255,.09)}.pg-h{font-size:10.5px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#a5b4fc;margin-bottom:8px}'
    + '.pg-q{display:flex;gap:10px;align-items:center;padding:8px 10px;margin-bottom:6px;border-radius:14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08)}.pg-q.done{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.35)}'
    + '.pg-qi{font-size:20px;width:26px;text-align:center;flex:none}.pg-qm{flex:1;min-width:0}.pg-qt{display:flex;justify-content:space-between;gap:8px;font-size:12.5px;margin-bottom:5px}.pg-qt b{color:#fff}.pg-qt span{color:#cbd5e1;font-variant-numeric:tabular-nums;white-space:nowrap}'
    + '.pg-bar{height:8px;border-radius:99px;background:rgba(255,255,255,.12);overflow:hidden}.pg-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#38bdf8,#818cf8);position:relative;overflow:hidden;transition:width .8s}.pg-q.done .pg-bar i{background:linear-gradient(90deg,#22c55e,#86efac)}'
    + '.pg-chest{display:flex;align-items:center;gap:12px;width:100%;text-align:left;border:0;border-radius:18px;padding:14px;font:inherit;color:inherit;position:relative;overflow:hidden}'
    + '.pg-chest.open{cursor:pointer;background:linear-gradient(135deg,#f59e0b,#ea580c);color:#fff;box-shadow:0 8px 22px rgba(234,88,12,.45)}.pg-chest.locked{background:rgba(255,255,255,.06);border:1px dashed rgba(255,255,255,.2);opacity:.85}'
    + '.pg-cico{font-size:38px;line-height:1;position:relative;z-index:1}.pg-ctx{flex:1;min-width:0;position:relative;z-index:1}.pg-ctx b{display:block;font-size:15px}.pg-ctx small{display:block;font-size:12px;opacity:.9}.pg-prize{font-size:18px !important}'
    + '.pg-cta{position:relative;z-index:1;background:#fff;color:#c2410c;font:800 12px Manrope,system-ui,sans-serif;letter-spacing:.1em;border-radius:999px;padding:8px 14px}'
    + '.pg-rays{position:absolute;left:-30px;top:50%;width:150px;height:150px;margin-top:-75px;background:repeating-conic-gradient(rgba(255,255,255,.22) 0 10deg,transparent 10deg 20deg);border-radius:50%;animation:pgSpin 9s linear infinite;-webkit-mask:radial-gradient(circle,#000 0,transparent 70%);mask:radial-gradient(circle,#000 0,transparent 70%)}'
    + '@keyframes pgSpin{to{transform:rotate(360deg)}}.pg-chest.open:not(:disabled) .pg-cico{animation:pgBob 1.8s ease-in-out infinite}@keyframes pgBob{50%{transform:translateY(-4px) rotate(-6deg)}}'
    + '.pg-chest.shake .pg-cico{animation:pgShake .4s linear infinite}@keyframes pgShake{25%{transform:rotate(-14deg)}75%{transform:rotate(14deg)}}'
    + '.pg-medals{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.pg-medal{text-align:center;filter:grayscale(1);opacity:.4}.pg-medal.on{filter:none;opacity:1}.pg-medal span{display:block;font-size:22px}.pg-medal small{font-size:9.5px;color:#cbd5e1;letter-spacing:.02em}'
    + '.pg-big{font:800 32px Manrope,system-ui,sans-serif;color:#fff;letter-spacing:-.02em;font-variant-numeric:tabular-nums}.pg-sub{font-size:12.5px;color:#cbd5e1;margin-top:2px}.pg-sub.up{color:#86efac}.pg-sub.dn{color:#fca5a5}'
    + '.pg-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0 8px}.pg-tiles div{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:10px 8px;text-align:center}.pg-tiles span{font-size:18px}.pg-tiles b{display:block;font-size:20px;color:#fff;font-variant-numeric:tabular-nums}.pg-tiles small{font-size:10.5px;color:#cbd5e1}'
    + '.pg-win{display:flex;gap:10px;align-items:center;padding:9px 0;border-top:1px solid rgba(255,255,255,.07);font-size:13px}.pg-wi{font-size:18px;flex:none}.pg-wt{flex:1;min-width:0;color:#e2e8f0}.pg-wt b{color:#fff}.pg-wt small{display:block;color:#94a3b8;font-size:11px}'
    + '.pg-clap,.pg-claps{border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.08);color:#fff;border-radius:999px;padding:5px 11px;font:700 12px inherit;cursor:pointer;flex:none;min-height:34px}.pg-claps{cursor:default;border-color:transparent}.pg-clap.on{background:#f59e0b;border-color:#fbbf24;color:#1f2937}'
    + '.pg-empty{color:#94a3b8;font-size:13px}'
    + '@media (prefers-reduced-motion:reduce){.pg-xp i::after,.pg-bar i::after,.pg-rays,.pg-chest .pg-cico{animation:none !important}}';
  function mount(host) {
    if (!host) return;
    if (!document.getElementById('pcCss')) { var s = document.createElement('style'); s.id = 'pcCss'; s.textContent = CSS; document.head.appendChild(s); }
    host.classList.add('pg'); host.style.display = ''; paint(host);
    var j = function (a) { return api.get(a).catch(function () { return null; }); };
    Promise.all([j('play.level'), j('play.gifts'), j('play.week'), j('play.me'), j('play.feed')]).then(function (r) {
      D = { level: r[0], gifts: r[1], week: r[2], me: r[3], wins: r[4] && r[4].wins };
      paint(host);
      if (D.level && D.level.levelled_up && window.TMWin) TMWin.win({ title: 'Level up: ' + LV[D.level.level].n + '!', prefix: '', ms: 3500 });
    });
  }
  window.PlayCard = { mount: mount };
})();
