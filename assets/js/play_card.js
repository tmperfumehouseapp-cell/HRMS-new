/* play_card.js — "My level · gift · week · team wins · break" on the salesperson home (My Sales). Mount: PlayCard.mount(element).
   Everything comes from play.php (play.level / play.gifts / play.gift_open / play.week / play.me / play.feed / play.clap). A part that fails to load is simply left out.
   The Dino strip (dino.js) is mounted separately at the top of the page. */
(function () {
  if (window.PlayCard) return;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var inr = function (n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); };
  var ago = function (iso) {
    var t = new Date(String(iso).replace(' ', 'T')).getTime(); if (!t) return '';
    var m = Math.max(0, Math.round((Date.now() - t) / 60000));
    return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' d ago';
  };
  var LV = { bronze: ['Bronze', '#a16207', 'B'], silver: ['Silver', '#6b7280', 'S'], gold: ['Gold', '#ca8a04', 'G'] };
  var NEED = { sales: function (v) { return inr(v); }, days: function (v) { return Math.round(v) + ' days'; }, rating: function (v) { return Number(v).toFixed(1) + '/10'; } };

  function levelHtml(L) {
    if (!L || !L.linked) return '';
    var cur = LV[L.level], nxt = L.next ? LV[L.next] : null;
    var bars = (L.reqs || []).map(function (r) {
      return '<div class="pc-req"><span>' + esc(r.label) + '</span><div class="pc-bar"><i style="width:' + r.pct + '%"' + (r.met ? ' class="ok"' : '') + '></i></div><small>' + NEED[r.key](r.have) + ' / ' + NEED[r.key](r.need) + (r.met ? ' ✓' : '') + '</small></div>';
    }).join('');
    return '<div class="pc-sec"><div class="pc-lv"><span class="pc-badge" style="background:' + cur[1] + '">' + cur[2] + '</span><div><b>' + cur[0] + ' this month</b><small>' + (nxt ? 'Next: ' + nxt[0] + (L.perks && L.perks[L.next] ? ' · ' + esc(L.perks[L.next]) : '') : 'Top level. Keep it to the month end.') + '</small></div>'
      + '<span class="pc-pts" title="Bonus points from Mystery gifts">⭐ ' + (L.points || 0) + '</span></div>' + (nxt ? bars : '') + '</div>';
  }
  function giftHtml(G) {
    if (!G) return '';
    if (G.new > 0) return '<div class="pc-sec"><button type="button" class="pc-gift" id="pcGift" data-id="' + G.gifts[0].id + '"><span class="pc-box">🎁</span><span><b>You have ' + G.new + ' Mystery gift' + (G.new > 1 ? 's' : '') + '!</b><small>' + esc(G.gifts[0].why) + ' · tap to open</small></span></button></div>';
    return '<div class="pc-sec pc-giftoff"><span class="pc-box">🎁</span><div><b>Mystery gift</b><small>' + esc(G.earn || '') + '</small></div></div>';
  }
  function weekHtml(W) {
    if (!W || !W.linked) return '';
    if (!W.sales && !W.orders) return '<details class="pc-sec"><summary><b>Your week</b> <small>' + esc(W.from) + ' to ' + esc(W.to) + '</small></summary><div class="pc-empty">No allocated orders last week. This week is a fresh start.</div></details>';
    var diff = W.sales - W.last_week_sales, dtxt = W.last_week_sales ? (diff >= 0 ? inr(diff) + ' more than the week before' : inr(-diff) + ' less than the week before') : 'your first full week';
    return '<details class="pc-sec" open><summary><b>Your week</b> <small>' + esc(W.from) + ' to ' + esc(W.to) + '</small></summary><div class="pc-week"><div class="pc-big">' + inr(W.sales) + '</div><div class="pc-wsub">' + dtxt + '</div>'
      + '<div class="pc-mini"><div><b>' + W.orders + '</b><span>orders</span></div><div><b>' + W.on_time_days + '</b><span>on-time days</span></div><div><b>' + (W.rank ? '#' + W.rank : '—') + '</b><span>of ' + W.players + ' in the team</span></div></div>'
      + (W.best_day ? '<div class="pc-wsub">Best day: ' + esc(new Date(W.best_day.day + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long' })) + ', ' + inr(W.best_day.sales) + '</div>' : '') + '</div></details>';
  }
  function streakHtml(me) {
    if (!me || !me.linked) return '';
    return me.streak > 0
      ? '<div class="pc-streak"><span class="pc-flame">🔥</span><div><b>' + me.streak + ' on-time day' + (me.streak === 1 ? '' : 's') + ' in a row</b><small>Best ' + Math.max(me.best, me.streak) + (me.today_in ? ' · today counted' : ' · punch in on time to keep it') + '</small></div></div>'
      : '<div class="pc-streak off"><span class="pc-flame">🔥</span><div><b>Start a streak</b><small>Punch in on time and the days stack up' + (me.best ? ' · your best was ' + me.best : '') + '</small></div></div>';
  }
  function feedHtml(wins) {
    return '<div class="pc-sec"><b>Team wins</b><div class="pc-feed">' + ((wins || []).length ? wins.slice(0, 6).map(function (w) {
      return '<div class="pc-win"><div class="pc-t"><b>' + esc(w.mine_win ? 'You' : w.who) + '</b> ' + esc(w.text) + '<small>' + esc(ago(w.at)) + '</small></div>'
        + (w.mine_win ? '<span class="pc-claps">👏 ' + w.claps + '</span>' : '<button type="button" class="pc-clap' + (w.mine ? ' on' : '') + '" data-id="' + w.id + '" aria-pressed="' + (w.mine ? 'true' : 'false') + '">👏 <span>' + w.claps + '</span></button>') + '</div>';
    }).join('') : '<div class="pc-empty">No wins yet this week. The first allocated order lands here.</div>') + '</div></div>';
  }

  var D = {};
  function paint(host) {
    host.innerHTML = '<h2>My game</h2>' + levelHtml(D.level) + giftHtml(D.gifts) + streakHtml(D.me) + weekHtml(D.week) + feedHtml(D.wins);   // (the Dino strip sits at the top of the page, see my_sales.html)
    var g = host.querySelector('#pcGift');
    if (g) g.onclick = function () {
      g.disabled = true; g.querySelector('.pc-box').textContent = '🎉';
      api.post('play.gift_open', { id: Number(g.dataset.id) }).then(function (r) {
        if (window.TMWin) TMWin.win({ title: r.text, prefix: '', from: g, ms: 3800 });
        return Promise.all([api.get('play.gifts').catch(function () { return null; }), api.get('play.level').catch(function () { return null; })]).then(function (x) { D.gifts = x[0]; if (x[1]) D.level = x[1]; setTimeout(function () { paint(host); }, 1200); });
      }).catch(function (e) { g.disabled = false; g.querySelector('.pc-box').textContent = '🎁'; if (window.TMWin) TMWin.win({ title: (e && e.message) || 'Could not open it', ms: 2500 }); });
    };
    host.querySelectorAll('.pc-clap').forEach(function (b) {
      b.onclick = function () {
        b.disabled = true;
        api.post('play.clap', { id: Number(b.dataset.id) }).then(function (r) { b.classList.toggle('on', !!r.mine); b.setAttribute('aria-pressed', r.mine ? 'true' : 'false'); b.querySelector('span').textContent = r.claps; })
          .catch(function () {}).then(function () { b.disabled = false; });
      };
    });
  }
  function mount(host) {
    if (!host) return;
    if (!document.getElementById('pcCss')) {
      var s = document.createElement('style'); s.id = 'pcCss';
      s.textContent = '.pc-sec{padding:11px 0;border-top:1px solid var(--sx-line,#e7e9ee);display:block}.pc-sec:first-of-type{border-top:0}details.pc-sec summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;gap:8px}details.pc-sec summary::-webkit-details-marker{display:none}'
        + '.pc-sec small,.pc-streak small,.pc-win small{display:block;color:var(--sx-muted,#6b7280);font-size:11.5px}.pc-sec summary small{display:inline}'
        + '.pc-lv{display:flex;gap:12px;align-items:center}.pc-lv>div{flex:1;min-width:0}.pc-badge{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:800;flex:none}.pc-pts{font-weight:800;font-size:13px;background:var(--sx-warn-bg,#fef3c7);padding:5px 10px;border-radius:999px;flex:none}'
        + '.pc-req{display:grid;grid-template-columns:110px 1fr;gap:2px 10px;align-items:center;margin-top:8px;font-size:12.5px}.pc-req small{grid-column:2}.pc-bar{height:8px;border-radius:99px;background:#e5e7eb;overflow:hidden}.pc-bar i{display:block;height:100%;background:var(--sx-accent,#f97316);border-radius:99px;transition:width .6s}.pc-bar i.ok{background:var(--sx-ok,#16a34a)}'
        + '.pc-gift{display:flex;gap:12px;align-items:center;width:100%;text-align:left;border:0;border-radius:16px;padding:12px;background:linear-gradient(135deg,#fef3c7,#fed7aa);cursor:pointer;font:inherit;animation:pcWig 2.4s ease-in-out infinite}.pc-gift:disabled{animation:none}'
        + '.pc-box{font-size:34px;line-height:1}.pc-giftoff{display:flex;gap:12px;align-items:center;opacity:.85}.pc-giftoff .pc-box{filter:grayscale(.8);font-size:28px}'
        + '@keyframes pcWig{0%,70%,100%{transform:rotate(0)}75%{transform:rotate(-2deg) scale(1.01)}85%{transform:rotate(2deg) scale(1.01)}}@media (prefers-reduced-motion:reduce){.pc-gift{animation:none}}'
        + '.pc-week{margin-top:8px}.pc-big{font-size:30px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}.pc-wsub{color:var(--sx-muted,#6b7280);font-size:13px;margin-top:2px}.pc-mini{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:10px 0}.pc-mini div{background:#f3f4f6;border-radius:12px;padding:8px 10px}.pc-mini b{display:block;font-size:18px}.pc-mini span{font-size:11px;color:var(--sx-muted,#6b7280)}'
        + '.pc-streak{display:flex;gap:12px;align-items:center;background:var(--sx-warn-bg,#fef3c7);border-radius:14px;padding:10px 12px;margin:10px 0}.pc-streak.off{background:#f3f4f6;filter:grayscale(.6)}.pc-flame{font-size:26px}'
        + '.pc-win{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:8px 2px;border-top:1px solid var(--sx-line,#e7e9ee);font-size:13px}.pc-feed{margin-top:4px}.pc-t{min-width:0}'
        + '.pc-clap,.pc-claps{border:1px solid var(--sx-line,#e7e9ee);background:#fff;border-radius:999px;padding:5px 11px;font:700 12px inherit;cursor:pointer;flex:none;min-height:34px}.pc-claps{cursor:default;border-color:transparent;background:#f3f4f6}.pc-clap.on{background:var(--sx-warn-bg,#fef3c7);border-color:#fcd34d}'
        + '.pc-empty{color:var(--sx-muted,#6b7280);font-size:13px;padding:8px 2px}';
      document.head.appendChild(s);
    }
    host.style.display = ''; paint(host);
    var j = function (a) { return api.get(a).catch(function () { return null; }); };
    Promise.all([j('play.level'), j('play.gifts'), j('play.week'), j('play.me'), j('play.feed')]).then(function (r) {
      D = { level: r[0], gifts: r[1], week: r[2], me: r[3], wins: r[4] && r[4].wins };
      paint(host);
      if (D.level && D.level.levelled_up && window.TMWin) TMWin.win({ title: 'Level up: ' + LV[D.level.level][0] + '!', prefix: '', ms: 3500 });
    });
  }
  window.PlayCard = { mount: mount };
})();
