/* dino.js — the "no internet" runner, as a small horizontal strip. TMDino.mount(element).
   ONE control: tap the strip, press Space / ArrowUp, or tap the Jump button. Hit a cactus and it is game over; tap again to retry.
   Scores go to the whole company board (play.php: play.dino_score / play.dino_board): today's champion, and the all-time best. Best on this phone is kept too.
   Pauses when the tab is hidden. The rules (TMDino.core) have no screen code, so they can be tested alone. */
(function () {
  if (window.TMDino) return;
  var W = 600, H = 150, GROUND = 124, DW = 42, DH = 45;

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
  var SPR = ['......XXXXXX..', '.....XXXXXXXX.', '.....XXXXXXXX.', '.....XXXXXXXXX', '.....XXXX.....', '.X...XXXXXXX..', '.XX.XXXXXX....', '.XXXXXXXXXXX..', '..XXXXXXXXX...', '...XXXXXXX....', '....XXXXX.....'];
  var LEG = [['.....X.X......', '.....X.X......', '.....XX.XX....'], ['.....XX.X.....', '.....X.XX.....', '.....XX.XX....']];
  function mix(a, b, t) { return a.map(function (v, i) { return Math.round(v + (b[i] - v) * t); }); }
  function rgb(c) { return 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')'; }
  var DAY = { top: [147, 207, 255], bot: [255, 240, 214], hill: [214, 233, 214], hill2: [190, 220, 196], ground: [226, 214, 190], ink: [67, 56, 40] };
  var NIGHT = { top: [20, 24, 54], bot: [78, 60, 110], hill: [50, 54, 92], hill2: [38, 42, 78], ground: [64, 58, 92], ink: [226, 232, 255] };
  function pal(k) { var o = {}; for (var n in DAY) o[n] = mix(DAY[n], NIGHT[n], k); return o; }
  function rr(ctx, x, y, w, h, r) { ctx.beginPath(); if (ctx.roundRect) ctx.roundRect(x, y, w, h, r); else ctx.rect(x, y, w, h); ctx.fill(); }

  function drawDino(ctx, x, y, frame, dead, col, dark) {
    var px = 3, rows = SPR.concat(LEG[frame]);
    ctx.fillStyle = col;
    rows.forEach(function (r, j) { for (var i = 0; i < r.length; i++) if (r[i] === 'X') ctx.fillRect(x + i * px, y + j * px, px + 0.4, px + 0.4); });
    ctx.fillStyle = dark; ctx.fillRect(x + 5 * px, y + 4 * px, px * 4, px * 0.8);   // mouth line
    ctx.fillStyle = dead ? '#ef4444' : '#fff'; ctx.fillRect(x + 7 * px, y + 1 * px, px * 1.4, px * 1.4);   // the eye
    if (!dead) { ctx.fillStyle = '#111827'; ctx.fillRect(x + 7.7 * px, y + 1.4 * px, px * 0.7, px * 0.8); }
  }
  function drawCactus(ctx, o, k) {
    var base = GROUND, x = o.x, h = o.h, w = o.w, st = Math.max(7, Math.floor(w / 3));
    var g1 = mix([34, 160, 84], [60, 180, 120], k), g2 = mix([21, 128, 61], [38, 140, 95], k);
    ctx.fillStyle = rgb(g1); rr(ctx, x + w / 2 - st / 2, base - h, st, h, st / 2);
    rr(ctx, x, base - h * 0.62, st, 8, 4); rr(ctx, x, base - h * 0.62 - 11, 6, 15, 3);
    rr(ctx, x + w - st, base - h * 0.45, st, 8, 4); rr(ctx, x + w - 6, base - h * 0.45 - 13, 6, 17, 3);
    ctx.fillStyle = rgb(g2); ctx.fillRect(x + w / 2 + 1, base - h + 4, 2, h - 6);
  }

  // ---------- the strip ----------
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var CSS = '.tmd{background:var(--card,#fff);border:1px solid var(--line,#e7e9ee);border-radius:22px;overflow:hidden;display:flex;flex-direction:column;max-width:560px;font-family:Manrope,system-ui,sans-serif;color:var(--ink,#111827);box-shadow:0 8px 24px rgba(17,24,39,.08)}'
    + '.tmd-h{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:11px 14px;background:linear-gradient(120deg,#7c2d12,#c2410c 55%,#f97316);color:#fff;font-weight:800;font-size:14px}.tmd-h small{font-weight:700;font-size:12px;opacity:.92}.tmd-h b{font-variant-numeric:tabular-nums}'
    + '.tmd canvas{width:100%;aspect-ratio:4/1;display:block;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent;user-select:none}'
    + '.tmd-f{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 14px 12px}.tmd-chips{display:flex;gap:6px;flex-wrap:wrap;min-width:0}'
    + '.tmd-chip{font-size:11.5px;font-weight:700;padding:5px 10px;border-radius:999px;background:#f3f4f6;color:#374151;white-space:nowrap}.tmd-chip b{color:#111827}.tmd-chip.gold{background:#fef3c7}'
    + '.tmd-b{border:0;border-radius:999px;background:#f97316;color:#fff;font:800 14px inherit;padding:0 22px;min-height:44px;cursor:pointer;box-shadow:0 4px 12px rgba(249,115,22,.35);transition:transform .12s cubic-bezier(.3,1.6,.5,1)}.tmd-b:active{transform:scale(.94)}';
  function mount(host) {
    if (!host || host.dataset.tmd) return; host.dataset.tmd = '1';
    if (!document.getElementById('tmdCss')) { var st = document.createElement('style'); st.id = 'tmdCss'; st.textContent = CSS; document.head.appendChild(st); }
    host.innerHTML = '<div class="tmd"><div class="tmd-h"><span>🦖 Dino Run</span><small>Score <b id="tmdS">0</b> · Your best <b id="tmdB">0</b></small></div>'
      + '<canvas aria-label="Dino game: tap to jump"></canvas>'
      + '<div class="tmd-f"><div class="tmd-chips" id="tmdBoard"><span class="tmd-chip">Loading the company board…</span></div><button type="button" class="tmd-b" id="tmdJ">Jump ⬆</button></div></div>';
    var cv = host.querySelector('canvas'), ctx = cv.getContext('2d');
    var dpr = Math.min(2, window.devicePixelRatio || 1); cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var s = newState(), state = 'idle', jumpQ = false, last = 0, raf = 0, startAt = 0, best = 0, parts = [], flash = 0, pop = null, shake = 0, tIdle = 0;
    try { best = Number(localStorage.getItem('tm_dino_best')) || 0; } catch (e) {}
    var elS = host.querySelector('#tmdS'), elB = host.querySelector('#tmdB'), elBoard = host.querySelector('#tmdBoard');
    elB.textContent = best;
    var clouds = [{ x: 90, y: 26, s: 1 }, { x: 300, y: 42, s: .8 }, { x: 500, y: 20, s: 1.2 }];

    function paint() {
      var sc = score(s), k = (1 - Math.cos(2 * Math.PI * sc / 600)) / 2, P = pal(k);   // a day lasts about 600 points
      var g = ctx.createLinearGradient(0, 0, 0, GROUND); g.addColorStop(0, rgb(P.top)); g.addColorStop(1, rgb(P.bot));
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
      // sun / moon
      var cx = 470 - ((sc % 600) / 600) * 40, cy = 40 + k * 8;
      if (k < .55) { var sg = ctx.createRadialGradient(cx, cy, 4, cx, cy, 34); sg.addColorStop(0, 'rgba(255,214,102,.95)'); sg.addColorStop(1, 'rgba(255,214,102,0)'); ctx.fillStyle = sg; ctx.fillRect(cx - 40, cy - 40, 80, 80); ctx.fillStyle = '#fbbf24'; ctx.beginPath(); ctx.arc(cx, cy, 13, 0, 7); ctx.fill(); }
      else { ctx.fillStyle = '#f1f5f9'; ctx.beginPath(); ctx.arc(cx, cy, 12, 0, 7); ctx.fill(); ctx.fillStyle = rgb(P.top); ctx.beginPath(); ctx.arc(cx + 6, cy - 3, 11, 0, 7); ctx.fill();
        ctx.fillStyle = 'rgba(255,255,255,.8)'; for (var q = 0; q < 14; q++) ctx.fillRect((q * 83 + 17) % W, (q * 37) % 70 + 6, 1.6, 1.6); }
      // clouds (slow)
      ctx.fillStyle = k < .5 ? 'rgba(255,255,255,.85)' : 'rgba(148,163,184,.35)';
      clouds.forEach(function (c) { var x = ((c.x - s.dist * 0.08 * c.s) % (W + 80) + W + 80) % (W + 80) - 40; rr(ctx, x, c.y, 54 * c.s, 12 * c.s, 8); rr(ctx, x + 10 * c.s, c.y - 7 * c.s, 28 * c.s, 14 * c.s, 8); });
      // hills (two layers, parallax)
      [[P.hill2, .15, 62, 38], [P.hill, .35, 44, 26]].forEach(function (L) {
        ctx.fillStyle = rgb(L[0]); ctx.beginPath(); ctx.moveTo(0, GROUND);
        for (var x = 0; x <= W; x += 20) ctx.lineTo(x, GROUND - L[3] - Math.sin((x + s.dist * L[1]) / L[2]) * L[3] * .45 - Math.sin((x + s.dist * L[1]) / (L[2] * 2.3)) * L[3] * .3);
        ctx.lineTo(W, GROUND); ctx.closePath(); ctx.fill();
      });
      // ground
      ctx.fillStyle = rgb(P.ground); ctx.fillRect(0, GROUND, W, H - GROUND);
      ctx.fillStyle = rgb(mix(P.ground, P.ink, .25)); ctx.fillRect(0, GROUND, W, 2);
      for (var i = 0; i < 22; i++) { var gx = ((i * 71 - s.dist * 1.0) % W + W) % W; ctx.fillRect(gx, GROUND + 8 + (i % 4) * 5, 6 + (i % 3) * 4, 1.5); }
      // obstacles
      s.obs.forEach(function (o) { ctx.fillStyle = 'rgba(0,0,0,.12)'; ctx.fillRect(o.x - 2, GROUND, o.w + 4, 3); drawCactus(ctx, o, k); });
      // dino shadow + dino
      var air = Math.min(1, s.y / 60); ctx.fillStyle = 'rgba(0,0,0,' + (.2 - air * .12) + ')'; ctx.beginPath(); ctx.ellipse(40 + 21, GROUND + 2, 20 - air * 8, 3.2, 0, 0, 7); ctx.fill();
      var ox = shake ? (Math.random() - .5) * shake : 0;
      drawDino(ctx, 40 + ox, GROUND - DH - s.y, state === 'run' && s.y === 0 ? (Math.floor(s.frames / 5) % 2) : 0, state === 'dead', state === 'dead' ? '#9ca3af' : '#f97316', state === 'dead' ? '#6b7280' : '#9a3412');
      // dust
      parts.forEach(function (p) { ctx.fillStyle = 'rgba(120,100,70,' + p.a + ')'; ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 7); ctx.fill(); });
      // score
      ctx.textAlign = 'right'; ctx.fillStyle = rgb(P.ink); ctx.font = '800 15px Manrope,system-ui,sans-serif';
      ctx.fillText(String(sc).padStart(5, '0'), W - 14, 24);
      if (best) { ctx.globalAlpha = .6; ctx.font = '700 11px Manrope,system-ui,sans-serif'; ctx.fillText('BEST ' + String(best).padStart(5, '0'), W - 14, 40); ctx.globalAlpha = 1; }
      if (pop) { ctx.globalAlpha = pop.a; ctx.textAlign = 'center'; ctx.fillStyle = '#f97316'; ctx.font = '800 18px Manrope,system-ui,sans-serif'; ctx.fillText(pop.t, 70, GROUND - 74 - pop.y); ctx.globalAlpha = 1; }
      if (flash > 0) { ctx.fillStyle = 'rgba(255,255,255,' + flash * .5 + ')'; ctx.fillRect(0, 0, W, H); }
      // overlays
      ctx.textAlign = 'center';
      if (state === 'idle') { ctx.fillStyle = 'rgba(17,24,39,.55)'; rr(ctx, W / 2 - 92, 44, 184, 38, 19); ctx.fillStyle = '#fff'; ctx.font = '800 15px Manrope,system-ui,sans-serif'; ctx.fillText('Tap to run  ▶', W / 2, 68); }
      if (state === 'dead') { ctx.fillStyle = 'rgba(17,24,39,.62)'; rr(ctx, W / 2 - 128, 30, 256, 66, 18); ctx.fillStyle = '#fff'; ctx.font = '800 18px Manrope,system-ui,sans-serif'; ctx.fillText('Game over · ' + sc, W / 2, 58); ctx.font = '700 13px Manrope,system-ui,sans-serif'; ctx.fillText('Tap to play again', W / 2, 80); }
      elS.textContent = sc;
    }
    function fx(f) {
      parts.forEach(function (p) { p.x += p.vx * f; p.y += p.vy * f; p.a -= .035 * f; p.r += .05 * f; }); parts = parts.filter(function (p) { return p.a > 0; });
      if (pop) { pop.y += .8 * f; pop.a -= .018 * f; if (pop.a <= 0) pop = null; }
      if (flash > 0) flash -= .06 * f; if (shake > 0) shake = Math.max(0, shake - 1.2 * f);
    }
    function loop(now) {
      if (state !== 'run') return;
      var f = Math.min(3, (now - last) / 16.67); last = now;
      var wasAir = s.y > 0, before = Math.floor(score(s) / 100);
      step(s, f, jumpQ); jumpQ = false;
      if (s.y === 0 && wasAir) for (var i = 0; i < 5; i++) parts.push({ x: 60 + Math.random() * 14, y: GROUND - 1, vx: -.6 - Math.random() * .8, vy: -.2 - Math.random() * .4, a: .55, r: 1.6 });
      else if (s.y === 0 && Math.floor(s.frames) % 6 === 0) parts.push({ x: 50, y: GROUND - 1, vx: -.5, vy: -.15, a: .3, r: 1.2 });
      var after = Math.floor(score(s) / 100); if (after > before && after > 0) { flash = 1; pop = { t: after * 100 + '!', y: 0, a: 1 }; if (window.TMWin) try { navigator.vibrate && navigator.vibrate(12); } catch (e) {} }
      fx(f); paint();
      if (s.dead) return over();
      raf = requestAnimationFrame(loop);
    }
    function begin() { s = newState(); state = 'run'; parts = []; pop = null; flash = 0; shake = 0; startAt = performance.now(); last = startAt; jumpQ = true; cancelAnimationFrame(raf); raf = requestAnimationFrame(loop); }
    function pressed() {
      if (state === 'run') { jumpQ = true; return; }
      if (state === 'idle' || state === 'dead') begin();
    }
    function over() {
      state = 'dead'; shake = 10;
      (function shakeOut() { if (shake > 0 && state === 'dead') { paint(); shake -= 1.5; requestAnimationFrame(shakeOut); } else { shake = 0; paint(); } })();
      try { navigator.vibrate && navigator.vibrate(40); } catch (e) {}
      var sc = score(s), ms = Math.round(performance.now() - startAt);
      if (sc > best) { best = sc; elB.textContent = best; try { localStorage.setItem('tm_dino_best', String(best)); } catch (e) {} if (window.TMWin && sc >= 50) TMWin.win({ title: 'New best: ' + sc, ms: 2200, from: cv }); }
      if (sc >= 20 && window.api && api.post) api.post('play.dino_score', { score: sc, ms: ms }).then(loadBoard).catch(function () {});
    }
    function loadBoard() {
      if (!window.api || !api.get) { elBoard.innerHTML = ''; return; }
      api.get('play.dino_board').then(function (d) {
        var l = [];
        l.push(d.champion ? '<span class="tmd-chip gold">🏆 <b>' + esc(d.champion.who) + '</b> ' + d.champion.best + (d.champion.mine ? ' (you!)' : '') + '</span>' : '<span class="tmd-chip">🏆 Be today\'s first champion</span>');
        if (d.mine_today) l.push('<span class="tmd-chip">You <b>' + d.mine_today + '</b>' + (d.mine_rank ? ' · #' + d.mine_rank + ' of ' + d.players : '') + '</span>');
        if (d.all_time) l.push('<span class="tmd-chip">⭐ <b>' + esc(d.all_time.who) + '</b> ' + d.all_time.best + '</span>');
        elBoard.innerHTML = l.join('');
      }).catch(function () { elBoard.innerHTML = ''; });
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
