/* play_card.js — the "Streak · Wins · Break" card on the salesperson home. Mount: PlayCard.mount(element).
   Streak and Wins come from the server (play.me / play.feed / play.clap in play.php). If those are not available the card still shows the snake game. */
(function () {
  if (window.PlayCard) return;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ago = function (iso) {
    var t = new Date(String(iso).replace(' ', 'T')).getTime(); if (!t) return '';
    var m = Math.max(0, Math.round((Date.now() - t) / 60000));
    return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' d ago';
  };
  function paint(host, me, wins) {
    var st = me && me.linked ? (me.streak > 0
      ? '<div class="pc-streak"><span class="pc-flame">🔥</span><div><b>' + me.streak + ' on-time day' + (me.streak === 1 ? '' : 's') + ' in a row</b><small>Best ' + Math.max(me.best, me.streak) + (me.today_in ? ' · today counted' : ' · punch in on time to keep it') + '</small></div></div>'
      : '<div class="pc-streak off"><span class="pc-flame">🔥</span><div><b>Start a streak</b><small>Punch in on time and the days stack up' + (me.best ? ' · your best was ' + me.best : '') + '</small></div></div>') : '';
    var feed = (wins || []).length
      ? wins.slice(0, 6).map(function (w) {
        return '<div class="pc-win"><div class="pc-t"><b>' + esc(w.mine_win ? 'You' : w.who) + '</b> ' + esc(w.text) + '<small>' + esc(ago(w.at)) + '</small></div>'
          + (w.mine_win ? '<span class="pc-claps">👏 ' + w.claps + '</span>' : '<button type="button" class="pc-clap' + (w.mine ? ' on' : '') + '" data-id="' + w.id + '" aria-pressed="' + (w.mine ? 'true' : 'false') + '">👏 <span>' + w.claps + '</span></button>') + '</div>';
      }).join('')
      : '<div class="pc-empty">No wins yet this week. The first allocated order lands here.</div>';
    host.innerHTML = '<h2>Team wins</h2>' + st + '<div class="pc-feed">' + feed + '</div>'
      + '<div class="pc-break"><div><b>Need a break?</b><small>Two minutes of Snake. Your best stays on this phone.</small></div><button type="button" class="btn secondary" id="pcSnake">🐍 Play</button></div>';
    var sn = host.querySelector('#pcSnake'); if (sn) sn.onclick = function () { if (window.TMSnake) TMSnake.open(); };
    host.querySelectorAll('.pc-clap').forEach(function (b) {
      b.onclick = function () {
        b.disabled = true;
        api.post('play.clap', { id: Number(b.dataset.id) }).then(function (r) {
          b.classList.toggle('on', !!r.mine); b.setAttribute('aria-pressed', r.mine ? 'true' : 'false'); b.querySelector('span').textContent = r.claps;
        }).catch(function () {}).then(function () { b.disabled = false; });
      };
    });
  }
  function mount(host) {
    if (!host) return;
    if (!document.getElementById('pcCss')) {
      var s = document.createElement('style'); s.id = 'pcCss';
      s.textContent = '.pc-streak{display:flex;gap:12px;align-items:center;background:var(--sx-warn-bg,#fef3c7);border-radius:14px;padding:10px 12px;margin:8px 0}.pc-streak.off{background:#f3f4f6;filter:grayscale(.6)}.pc-flame{font-size:26px}.pc-streak small,.pc-win small,.pc-break small{display:block;color:var(--sx-muted,#6b7280);font-size:11.5px}'
        + '.pc-win{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:9px 2px;border-top:1px solid var(--sx-line,#e7e9ee);font-size:13px}.pc-t{min-width:0}'
        + '.pc-clap,.pc-claps{border:1px solid var(--sx-line,#e7e9ee);background:#fff;border-radius:999px;padding:5px 11px;font:700 12px inherit;cursor:pointer;flex:none;min-height:34px}.pc-claps{cursor:default;border-color:transparent;background:#f3f4f6}.pc-clap.on{background:var(--sx-warn-bg,#fef3c7);border-color:#fcd34d}'
        + '.pc-empty{color:var(--sx-muted,#6b7280);font-size:13px;padding:10px 2px}.pc-break{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px;padding-top:10px;border-top:1px dashed var(--sx-line,#e7e9ee)}';
      document.head.appendChild(s);
    }
    host.style.display = '';
    paint(host, null, []);
    Promise.all([api.get('play.me').catch(function () { return null; }), api.get('play.feed').catch(function () { return null; })])
      .then(function (r) { paint(host, r[0], r[1] && r[1].wins); });
  }
  window.PlayCard = { mount: mount };
})();
