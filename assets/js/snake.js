/* snake.js — a small Snake game for a break. TMSnake.open() shows it full-screen; swipe, arrow keys / WASD, or the on-screen arrows.
   Best score is kept on this device only. A new best fires the shared win moment (win.js). Pauses when the tab is hidden. */
(function () {
  if (window.TMSnake) return;
  var N = 18;   // the board is N x N cells

  // pure game rules (no screen): easy to test
  function newGame(rnd) {
    var s = { snake: [{ x: 8, y: 9 }, { x: 7, y: 9 }, { x: 6, y: 9 }], dir: { x: 1, y: 0 }, next: { x: 1, y: 0 }, food: null, score: 0, dead: false };
    s.food = place(s, rnd); return s;
  }
  function place(s, rnd) {
    var free = [];
    for (var x = 0; x < N; x++) for (var y = 0; y < N; y++) if (!s.snake.some(function (c) { return c.x === x && c.y === y; })) free.push({ x: x, y: y });
    return free.length ? free[Math.floor((rnd ? rnd() : Math.random()) * free.length)] : null;
  }
  function turn(s, dx, dy) { if (dx === -s.dir.x && dy === -s.dir.y) return; s.next = { x: dx, y: dy }; }
  function step(s, rnd) {
    if (s.dead) return s;
    s.dir = s.next;
    var h = { x: s.snake[0].x + s.dir.x, y: s.snake[0].y + s.dir.y };
    var eat = s.food && h.x === s.food.x && h.y === s.food.y;
    var body = eat ? s.snake : s.snake.slice(0, -1);
    if (h.x < 0 || h.y < 0 || h.x >= N || h.y >= N || body.some(function (c) { return c.x === h.x && c.y === h.y; })) { s.dead = true; return s; }
    s.snake.unshift(h);
    if (eat) { s.score++; s.food = place(s, rnd); } else s.snake.pop();
    return s;
  }
  window.TMSnake = { N: N, newGame: newGame, turn: turn, step: step };

  var css = '.tmsn{position:fixed;inset:0;z-index:100001;background:rgba(17,24,39,.92);display:flex;align-items:center;justify-content:center;padding:16px;font-family:Manrope,system-ui,sans-serif}'
    + '.tmsn-box{background:#fff;border-radius:22px;padding:14px;width:min(100%,380px);display:flex;flex-direction:column;gap:10px;color:#111827}'
    + '.tmsn-top{display:flex;justify-content:space-between;align-items:center;font-weight:800}.tmsn-top small{color:#6b7280;font-weight:700}'
    + '.tmsn canvas{width:100%;aspect-ratio:1;border-radius:14px;background:#f3f4f6;touch-action:none;display:block}'
    + '.tmsn-pad{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;max-width:190px;margin:0 auto;width:100%}'
    + '.tmsn-pad button,.tmsn-x{border:0;border-radius:14px;background:#f3f4f6;font-size:20px;min-height:44px;cursor:pointer}'
    + '.tmsn-pad button:active{background:#e5e7eb}.tmsn-x{font-size:13px;font-weight:800;padding:0 14px;min-height:34px;border-radius:999px}'
    + '.tmsn-msg{text-align:center;color:#6b7280;font-size:13px;min-height:18px}';

  var el, cv, ctx, g, timer, best = 0, started = false, cell;
  function getBest() { try { return Number(localStorage.getItem('tm_snake_best')) || 0; } catch (e) { return 0; } }
  function setBest(v) { try { localStorage.setItem('tm_snake_best', String(v)); } catch (e) {} }
  function accent() { try { return getComputedStyle(document.documentElement).getPropertyValue('--sx-accent').trim() || '#f97316'; } catch (e) { return '#f97316'; } }

  function draw() {
    var w = cv.width; cell = w / N; ctx.clearRect(0, 0, w, w);
    ctx.fillStyle = '#e5e7eb'; for (var i = 0; i < N; i++) for (var j = 0; j < N; j++) if ((i + j) % 2) ctx.fillRect(i * cell, j * cell, cell, cell);
    if (g.food) { ctx.fillStyle = '#16a34a'; ctx.beginPath(); ctx.arc((g.food.x + .5) * cell, (g.food.y + .5) * cell, cell * .36, 0, 7); ctx.fill(); }
    g.snake.forEach(function (c, k) { ctx.fillStyle = k ? accent() : '#c2410c'; ctx.beginPath(); ctx.roundRect ? ctx.roundRect(c.x * cell + 1, c.y * cell + 1, cell - 2, cell - 2, 5) : ctx.rect(c.x * cell + 1, c.y * cell + 1, cell - 2, cell - 2); ctx.fill(); });
    el.querySelector('#tmsnS').textContent = g.score; el.querySelector('#tmsnB').textContent = best;
  }
  function msg(t) { el.querySelector('.tmsn-msg').textContent = t; }
  function tick() {
    step(g); draw();
    if (g.dead) {
      clearInterval(timer); timer = null; started = false;
      if (g.score > best) { best = g.score; setBest(best); draw(); if (window.TMWin) TMWin.win({ title: 'New best: ' + best, ms: 2400, from: cv }); msg('New best! Press an arrow or swipe to play again.'); }
      else msg('Game over. Press an arrow or swipe to play again.');
      return;
    }
    var want = Math.max(70, 150 - g.score * 4);
    if (timer && timer._ms !== want) { clearInterval(timer); timer = setInterval(tick, want); timer._ms = want; }
  }
  function go(dx, dy) {
    if (!g || g.dead) { g = newGame(); }
    turn(g, dx, dy);
    if (!started) { started = true; msg(''); timer = setInterval(tick, 150); timer._ms = 150; }
  }
  var keys = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0], w: [0, -1], s: [0, 1], a: [-1, 0], d: [1, 0] };
  function onKey(e) { var k = keys[e.key]; if (k) { e.preventDefault(); go(k[0], k[1]); } else if (e.key === 'Escape') close(); }
  function onHide() { if (document.hidden && timer) { clearInterval(timer); timer = null; started = false; msg('Paused. Press an arrow to continue.'); } }
  function close() {
    if (timer) clearInterval(timer); timer = null; started = false;
    document.removeEventListener('keydown', onKey); document.removeEventListener('visibilitychange', onHide);
    if (el) el.remove(); el = null;
  }
  function open() {
    if (el) return;
    if (!document.getElementById('tmsnCss')) { var st = document.createElement('style'); st.id = 'tmsnCss'; st.textContent = css; document.head.appendChild(st); }
    best = getBest(); g = newGame();
    el = document.createElement('div'); el.className = 'tmsn'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Snake game');
    el.innerHTML = '<div class="tmsn-box"><div class="tmsn-top"><span>🐍 Snake</span><small>Score <b id="tmsnS">0</b> · Best <b id="tmsnB">0</b></small><button class="tmsn-x" type="button" id="tmsnX">Close</button></div>'
      + '<canvas width="360" height="360"></canvas><div class="tmsn-msg">Swipe on the board, or use the arrows.</div>'
      + '<div class="tmsn-pad"><span></span><button type="button" data-d="0,-1" aria-label="Up">▲</button><span></span><button type="button" data-d="-1,0" aria-label="Left">◀</button><button type="button" data-d="0,1" aria-label="Down">▼</button><button type="button" data-d="1,0" aria-label="Right">▶</button></div></div>';
    document.body.appendChild(el);
    cv = el.querySelector('canvas'); ctx = cv.getContext('2d'); draw();
    el.querySelector('#tmsnX').onclick = close;
    el.addEventListener('click', function (e) { if (e.target === el) close(); });
    el.querySelectorAll('[data-d]').forEach(function (b) { b.onclick = function () { var d = b.dataset.d.split(','); go(+d[0], +d[1]); }; });
    var sx = 0, sy = 0;
    cv.addEventListener('touchstart', function (e) { var t = e.touches[0]; sx = t.clientX; sy = t.clientY; }, { passive: true });
    cv.addEventListener('touchend', function (e) {
      var t = e.changedTouches[0], dx = t.clientX - sx, dy = t.clientY - sy;
      if (Math.max(Math.abs(dx), Math.abs(dy)) < 18) return;
      if (Math.abs(dx) > Math.abs(dy)) go(dx > 0 ? 1 : -1, 0); else go(0, dy > 0 ? 1 : -1);
    }, { passive: true });
    document.addEventListener('keydown', onKey); document.addEventListener('visibilitychange', onHide);
  }
  window.TMSnake.open = open; window.TMSnake.close = close;
})();
