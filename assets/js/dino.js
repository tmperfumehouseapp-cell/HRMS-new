/* dino.js — the "no internet" runner, as a small horizontal strip. TMDino.mount(element).
   ONE control: tap the strip, press Space / ArrowUp, or tap the Jump button. Hit a cactus and it is game over; tap again to retry.
   Scores go to the whole company board (play.php: play.dino_score / play.dino_board): today's champion, and the all-time best. Best on this phone is kept too.
   Pauses when the tab is hidden. The rules (TMDino.core) have no screen code, so they can be tested alone. */
(function () {
  if (window.TMDino) return;
  var W = 600, H = 120, GROUND = 98, DW = 42, DH = 45;

  // ---------- rules (no screen) ----------
  function newState() { return { y: 0, vy: 0, dist: 0, speed: 6, obs: [], gap: 260, dead: false, frames: 0 }; }
  /** one step of f frames (1 = 1/60 s). jump: true when the player pressed jump this step. */
  function step(s, f, jump, rnd) {
    if (s.dead) return s;
    rnd = rnd || Math.random;
    if (jump && s.y === 0) s.vy = 8.8;
    s.vy -= 0.56 * f; s.y += s.vy * f; if (s.y <= 0) { s.y = 0; s.vy = 0; }
    s.speed = Math.min(13, 6 + s.dist / 2200);
    s.dist += s.speed * f; s.frames += f;
    s.gap -= s.speed * f;
    if (s.gap <= 0) {
      var w = 16 + Math.floor(rnd() * 3) * 10, h = 28 + Math.floor(rnd() * 3) * 8;
      s.obs.push({ x: W + 10, w: w, h: h });
      s.gap = 200 + s.speed * 16 + rnd() * 200;
    }
    s.obs.forEach(function (o) { o.x -= s.speed * f; });
    s.obs = s.obs.filter(function (o) { return o.x + o.w > -10; });
    var dx = 40, dy = s.y;   // the dino's box (a little smaller than the picture, so near-misses feel fair)
    for (var i = 0; i < s.obs.length; i++) {
      var o = s.obs[i];
      if (dx + DW - 8 > o.x + 3 && dx + 8 < o.x + o.w - 3 && dy < o.h - 4) { s.dead = true; break; }
    }
    return s;
  }
  function score(s) { return Math.floor(s.dist / 10); }
  window.TMDino = { core: { newState: newState, step: step, score: score } };

  // ---------- picture ----------
  var SPR = ['......XXXXXX..', '.....XXXXXXXX.', '.....XX.XXXXXX', '.....XXXXXXXXX', '.....XXXX.....', '.X...XXXXXXX..', '.XX.XXXXXX....', '.XXXXXXXXXXX..', '..XXXXXXXXX...', '...XXXXXXX....', '....XXXXX.....'];
  var LEG = [['.....X.X......', '.....X.X......', '.....XX.XX....'], ['.....XX.X.....', '.....X.XX.....', '.....XX.XX....']];
  function drawDino(ctx, x, y, frame, dead, col) {
    var px = 3, rows = SPR.concat(LEG[frame]);
    ctx.fillStyle = col;
    rows.forEach(function (r, j) { for (var i = 0; i < r.length; i++) if (r[i] === 'X') ctx.fillRect(x + i * px, y + j * px, px, px); });
    ctx.fillStyle = dead ? '#ef4444' : '#fff'; ctx.fillRect(x + 7 * px, y + 1 * px, px, px);   // the eye
  }
  function drawCactus(ctx, o, col) {
    var base = GROUND, x = o.x, h = o.h, w = o.w, st = Math.max(6, Math.floor(w / 3));
    ctx.fillStyle = col;
    ctx.fillRect(x + w / 2 - st / 2, base - h, st, h);                       // trunk
    ctx.fillRect(x, base - h * 0.62, st - 1, 8); ctx.fillRect(x, base - h * 0.62 - 10, 5, 12);          // left arm
    ctx.fillRect(x + w - st + 1, base - h * 0.45, st - 1, 8); ctx.fillRect(x + w - 5, base - h * 0.45 - 12, 5, 14);   // right arm
  }

  // ---------- the strip ----------
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var CSS = '.tmd{background:var(--card,#fff);border:1px solid var(--line,#e7e9ee);border-radius:18px;padding:12px;display:flex;flex-direction:column;gap:8px;max-width:560px;font-family:Manrope,system-ui,sans-serif;color:var(--ink,#111827)}'
    + '.tmd-h{display:flex;justify-content:space-between;align-items:center;gap:8px;font-weight:800;font-size:14px}.tmd-h small{color:#6b7280;font-weight:700;font-size:12px}'
    + '.tmd canvas{width:100%;aspect-ratio:5/1;border-radius:12px;background:#f7f7f7;display:block;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent}'
    + '.tmd-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.tmd-b{border:0;border-radius:999px;background:#111827;color:#fff;font:800 13px inherit;padding:0 18px;min-height:40px;cursor:pointer}'
    + '.tmd-board{font-size:12.5px;color:#6b7280;line-height:1.5;min-width:0}.tmd-board b{color:#111827}';
  function mount(host) {
    if (!host || host.dataset.tmd) return; host.dataset.tmd = '1';
    if (!document.getElementById('tmdCss')) { var st = document.createElement('style'); st.id = 'tmdCss'; st.textContent = CSS; document.head.appendChild(st); }
    host.innerHTML = '<div class="tmd"><div class="tmd-h"><span>🦖 Dino run</span><small>Score <b id="tmdS">0</b> · Your best <b id="tmdB">0</b></small></div>'
      + '<canvas width="' + W + '" height="' + H + '" aria-label="Dino game: tap to jump"></canvas>'
      + '<div class="tmd-row"><div class="tmd-board" id="tmdBoard">Loading the company board…</div><button type="button" class="tmd-b" id="tmdJ">Jump</button></div></div>';
    var cv = host.querySelector('canvas'), ctx = cv.getContext('2d');
    var s = newState(), state = 'idle', jumpQ = false, last = 0, raf = 0, startAt = 0, best = 0;
    try { best = Number(localStorage.getItem('tm_dino_best')) || 0; } catch (e) {}
    var elS = host.querySelector('#tmdS'), elB = host.querySelector('#tmdB'), elBoard = host.querySelector('#tmdBoard');
    elB.textContent = best;
    var ink = function () { try { return getComputedStyle(document.documentElement).getPropertyValue('--sx-ink').trim() || '#4b5563'; } catch (e) { return '#4b5563'; } };

    function paint() {
      ctx.clearRect(0, 0, W, H);
      ctx.fillStyle = '#d1d5db'; ctx.fillRect(0, GROUND + 1, W, 2);
      for (var i = 0; i < 14; i++) ctx.fillRect(((i * 97 - s.dist * 0.9) % W + W) % W, GROUND + 8 + (i % 3) * 5, 8 + (i % 4) * 3, 1.5);
      var col = state === 'dead' ? '#9ca3af' : '#4b5563';
      s.obs.forEach(function (o) { drawCactus(ctx, o, col); });
      drawDino(ctx, 40, GROUND - DH - s.y, state === 'run' && s.y === 0 ? (Math.floor(s.frames / 6) % 2) : 0, state === 'dead', col);
      ctx.fillStyle = '#6b7280'; ctx.font = '700 14px Manrope,system-ui,sans-serif'; ctx.textAlign = 'right'; ctx.fillText(String(score(s)).padStart(5, '0'), W - 12, 20);
      ctx.textAlign = 'center'; ctx.font = '800 15px Manrope,system-ui,sans-serif';
      if (state === 'idle') ctx.fillText('Tap to start', W / 2, 52);
      if (state === 'dead') { ctx.fillText('GAME OVER · tap to play again', W / 2, 52); }
      elS.textContent = score(s);
    }
    function loop(now) {
      if (state !== 'run') return;
      var f = Math.min(3, (now - last) / 16.67); last = now;
      step(s, f, jumpQ); jumpQ = false; paint();
      if (s.dead) return over();
      raf = requestAnimationFrame(loop);
    }
    function begin() { s = newState(); state = 'run'; startAt = performance.now(); last = startAt; jumpQ = true; cancelAnimationFrame(raf); raf = requestAnimationFrame(loop); }
    function pressed() {
      if (state === 'run') { jumpQ = true; return; }
      if (state === 'idle' || state === 'dead') begin();
    }
    function over() {
      state = 'dead'; paint();
      var sc = score(s), ms = Math.round(performance.now() - startAt);
      if (sc > best) { best = sc; elB.textContent = best; try { localStorage.setItem('tm_dino_best', String(best)); } catch (e) {} if (window.TMWin && sc >= 50) TMWin.win({ title: 'New best: ' + sc, ms: 2200, from: cv }); }
      if (sc >= 20 && window.api && api.post) api.post('play.dino_score', { score: sc, ms: ms }).then(loadBoard).catch(function () {});
    }
    function loadBoard() {
      if (!window.api || !api.get) { elBoard.textContent = ''; return; }
      api.get('play.dino_board').then(function (d) {
        var l = [];
        if (d.champion) l.push('🏆 Today: <b>' + esc(d.champion.who) + '</b> ' + d.champion.best + (d.champion.mine ? ' (you!)' : ''));
        else l.push('No score yet today. Be the first champion.');
        if (d.mine_today) l.push('You today: <b>' + d.mine_today + '</b>' + (d.mine_rank ? ' · #' + d.mine_rank + ' of ' + d.players : ''));
        if (d.all_time) l.push('All-time: <b>' + esc(d.all_time.who) + '</b> ' + d.all_time.best);
        elBoard.innerHTML = l.join('<br>');
      }).catch(function () { elBoard.textContent = ''; });
    }
    cv.addEventListener('pointerdown', function (e) { e.preventDefault(); pressed(); });
    host.querySelector('#tmdJ').addEventListener('click', pressed);
    document.addEventListener('keydown', function (e) {
      if (!host.isConnected || (e.key !== ' ' && e.key !== 'ArrowUp')) return;
      var t = e.target && e.target.tagName; if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || t === 'BUTTON') return;
      var r = cv.getBoundingClientRect(); if (r.bottom < 0 || r.top > innerHeight) return;   // only while the strip is on screen
      e.preventDefault(); pressed();
    });
    document.addEventListener('visibilitychange', function () { if (document.hidden && state === 'run') { state = 'idle'; cancelAnimationFrame(raf); paint(); } });
    paint(); loadBoard();
  }
  window.TMDino.mount = mount;
})();
