/* dino.js — Dino Run, the break game on the Punch in / out pages (My Day + Attendance). TMDino.mount(element).
   Two looks, picked with the small ☀️/🌙 button (saved on this phone): Candy Day (bright cartoon) and Neon Night (arcade).
   One control: tap the game, press Space / Up, the JUMP button on the game screen, or the big round JUMP button below it. Jump the cacti, grab the coins (+10 each).
   After every run: your rank, who is first today, the top of the month and who is last (company board in play.php:
   play.dino_score / play.dino_board). Pauses when the tab is hidden. Honours "reduce motion" (no shake). */
(function () {
  if (window.TMDino) return;
  var W = 640, H = 296, GROUND = 240, rm = false;
  try { rm = window.matchMedia('(prefers-reduced-motion:reduce)').matches; } catch (e) {}
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  var CSS = '.tmd{--gbg:#fff;--gfg:#111827;--gmut:#6b7280;--gline:#e7e9ee;--gacc:#f97316;--gchip:#f3f4f6;border-radius:20px;overflow:hidden;background:var(--gbg);color:var(--gfg);border:1px solid var(--gline);box-shadow:0 8px 24px rgba(17,24,39,.10);display:flex;flex-direction:column;max-width:620px;font-family:Manrope,system-ui,sans-serif}'
    + '.tmd[data-w="neon"]{--gbg:#0b0620;--gfg:#f5f3ff;--gmut:#a78bfa;--gline:#3b1d6e;--gacc:#22d3ee;--gchip:#1b1040;box-shadow:0 0 0 1px #3b1d6e,0 12px 30px rgba(124,58,237,.3)}'
    + '.tmd-gh{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:9px 11px 7px}.tmd-t{display:flex;flex-direction:column;min-width:0}.tmd-t b{font-size:14px;font-weight:800;letter-spacing:-.01em}.tmd-t small{font-size:10.5px;color:var(--gmut);font-weight:700}'
    + '.tmd-sc{display:flex;gap:6px;align-items:center}.tmd-sc div{background:var(--gchip);border-radius:11px;padding:3px 9px;text-align:center;min-width:48px}.tmd-sc small{display:block;font-size:8.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--gmut);font-weight:800}.tmd-sc b{font-size:15px;font-variant-numeric:tabular-nums;font-weight:800}'
    + '.tmd-wb{border:0;background:var(--gchip);color:inherit;border-radius:50%;width:32px;height:32px;font-size:15px;cursor:pointer;flex:none}'
    + '.tmd-stage{position:relative;margin:0 9px;border-radius:16px;overflow:hidden;aspect-ratio:640/296;background:#000;touch-action:manipulation;-webkit-tap-highlight-color:transparent;user-select:none}'
    + '.tmd-stage canvas{width:100%;height:100%;display:block;cursor:pointer}'
    + '.tmd-ov{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;pointer-events:none;text-align:center;padding:8px}'
    + '.tmd-start{background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.28))}.tmd-ov .big{font-weight:800;font-size:clamp(17px,4.6vw,24px);color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.5)}.tmd-ov small{color:#fff;font-weight:700;font-size:11.5px;text-shadow:0 1px 6px rgba(0,0,0,.5)}'
    + '.tmd-goov{background:rgba(8,10,22,.66)}.tmd-pulse{animation:tmdPu 1.4s ease-in-out infinite}@keyframes tmdPu{50%{transform:scale(1.06)}}'
    + '.tmd-rep{width:100%;max-width:520px;display:flex;flex-direction:column;gap:5px;color:#fff;text-align:left;padding:2px 8px;animation:tmdIn .35s cubic-bezier(.2,1.2,.4,1)}@keyframes tmdIn{from{opacity:0;transform:translateY(8px)}}'
    + '.tmd-rh{display:flex;justify-content:space-between;align-items:baseline;gap:8px}.tmd-rh b{font-size:clamp(15px,4.4vw,19px);font-weight:800}.tmd-rh span{font-size:11.5px;font-weight:800;color:#fdba74}'
    + '.tmd-rs{display:flex;gap:8px;align-items:center}.tmd-rs b{font-size:clamp(20px,6vw,28px);font-variant-numeric:tabular-nums;font-weight:800;line-height:1}.tmd-rs small{font-size:11px;font-weight:700;opacity:.85}'
    + '.tmd-tag{display:inline-block;background:var(--gacc);color:#fff;font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:999px;letter-spacing:.05em}.tmd[data-w="neon"] .tmd-tag{color:#04121a}'
    + '.tmd-rp{display:grid;grid-template-columns:repeat(3,1fr);gap:5px}.tmd-rp div{background:rgba(255,255,255,.14);border-radius:10px;padding:4px 7px;min-width:0}.tmd-rp small{display:block;font-size:9.5px;font-weight:800;opacity:.85;white-space:nowrap}.tmd-rp b{display:block;font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tmd-rp i{font-style:normal;font-size:12px;font-weight:800;color:#fde68a;font-variant-numeric:tabular-nums}.tmd-rp .me{outline:2px solid var(--gacc)}'
    + '.tmd-rb{font-size:10.5px;font-weight:700;opacity:.85;text-align:center}'
    + '.tmd-sj{position:absolute;right:9px;bottom:9px;width:54px;height:54px;border-radius:50%;border:0;cursor:pointer;color:#fff;font:800 9.5px Manrope,system-ui,sans-serif;letter-spacing:.06em;display:flex;flex-direction:column;align-items:center;justify-content:center;touch-action:manipulation;z-index:3;opacity:.92;transition:transform .08s}.tmd-sj span{font-size:18px;line-height:1}.tmd-sj:active,.tmd-sj.on{transform:scale(.92)}'
    + '.tmd[data-w="candy"] .tmd-sj{background:radial-gradient(circle at 35% 28%,#fdba74,#f97316 60%,#c2410c);box-shadow:0 4px 0 #9a3412,0 8px 14px rgba(0,0,0,.3)}.tmd[data-w="neon"] .tmd-sj{background:radial-gradient(circle at 35% 28%,#67e8f9,#0891b2 60%,#164e63);box-shadow:0 0 0 2px #22d3ee,0 0 18px rgba(34,211,238,.7)}'
    + '.tmd-ctl{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 11px 10px}.tmd-champ{display:flex;flex-direction:column;gap:5px;min-width:0}.tmd-chip{font-size:11px;font-weight:800;padding:4px 9px;border-radius:999px;background:var(--gchip);white-space:nowrap}'
    + '.tmd-jump{flex:none;width:62px;height:62px;border-radius:50%;border:0;cursor:pointer;color:#fff;font:800 10.5px Manrope,system-ui,sans-serif;letter-spacing:.08em;display:flex;flex-direction:column;align-items:center;justify-content:center;touch-action:manipulation;transition:transform .08s,box-shadow .08s}.tmd-jump span{font-size:20px;line-height:1}'
    + '.tmd[data-w="candy"] .tmd-jump{background:radial-gradient(circle at 35% 28%,#fdba74,#f97316 55%,#c2410c);box-shadow:0 6px 0 #9a3412,0 12px 18px rgba(154,52,18,.3)}'
    + '.tmd[data-w="neon"] .tmd-jump{background:radial-gradient(circle at 35% 28%,#67e8f9,#0891b2 60%,#164e63);color:#ecfeff;box-shadow:0 0 0 2px #22d3ee,0 0 22px rgba(34,211,238,.7),0 6px 0 #0e3a4a}'
    + '.tmd-jump:active,.tmd-jump.on{transform:translateY(5px)}.tmd[data-w="candy"] .tmd-jump:active,.tmd[data-w="candy"] .tmd-jump.on{box-shadow:0 1px 0 #9a3412,0 4px 8px rgba(154,52,18,.3)}.tmd[data-w="neon"] .tmd-jump:active,.tmd[data-w="neon"] .tmd-jump.on{box-shadow:0 0 0 2px #22d3ee,0 0 14px rgba(34,211,238,.6),0 1px 0 #0e3a4a}'
    + '@media (prefers-reduced-motion:reduce){.tmd-pulse{animation:none}.tmd-rep{animation:none}}';

  var P = {
    candy: { skyA: '#8fd3ff', skyB: '#fff1d6', hill1: '#bfe3b4', hill2: '#9fd49a', ground: '#e8d3a8', line: '#c7a86b', cactus: '#2f9e5a', cactus2: '#1f7a43', dino: '#f97316', belly: '#ffe3c2' },
    neon: { skyA: '#12002e', skyB: '#6b21a8', grid: '#d946ef', ground: '#12002e', line: '#22d3ee', cactus: '#f0abfc', dino: '#22d3ee' }
  };

  function mount(host) {
    if (!host || host.dataset.tmd) return; host.dataset.tmd = '1';
    if (!document.getElementById('tmdCss')) { var st = document.createElement('style'); st.id = 'tmdCss'; st.textContent = CSS; document.head.appendChild(st); }
    var world = 'candy'; try { if (localStorage.getItem('tm_dino_world') === 'neon') world = 'neon'; } catch (e) {}
    host.innerHTML = '<div class="tmd" data-w="' + world + '"><div class="tmd-gh"><div class="tmd-t"><b>🦖 Dino Run</b><small>Collect coins · jump the cacti</small></div>'
      + '<div class="tmd-sc"><div><small>Score</small><b class="sc">0</b></div><div><small>Best</small><b class="best">0</b></div><button type="button" class="tmd-wb" aria-label="Switch look"></button></div></div>'
      + '<div class="tmd-stage"><canvas aria-label="Dino game: tap to jump"></canvas><div class="tmd-ov tmd-start"><div class="big tmd-pulse">Tap to run ▶</div><small>Jump the cacti · grab the coins</small></div>'
      + '<div class="tmd-ov tmd-goov" style="display:none"><div class="tmd-rep"><div class="tmd-rh"><b>Game over</b><span class="gr-r"></span></div><div class="tmd-rs"></div><div class="tmd-rp"></div><div class="tmd-rb">Tap the game or JUMP to play again</div></div></div>'
      + '<button type="button" class="tmd-sj" aria-label="Jump"><span>⬆</span>JUMP</button></div>'
      + '<div class="tmd-ctl"><div class="tmd-champ"><span class="tmd-chip">Loading the company board…</span></div><button type="button" class="tmd-jump" aria-label="Jump"><span>⬆</span>JUMP</button></div></div>';
    var root = host.querySelector('.tmd'), q = function (c) { return host.querySelector(c); };
    var cv = q('canvas'), ctx = cv.getContext('2d'), dpr = Math.min(2, window.devicePixelRatio || 1);
    cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var wb = q('.tmd-wb'), start = q('.tmd-start'), goov = q('.tmd-goov'), sj = q('.tmd-sj'), jb = q('.tmd-jump');
    var best = 0; try { best = +localStorage.getItem('tm_dino_best') || 0; } catch (e) {} q('.best').textContent = best;
    function setWorld(w) { world = w; root.dataset.w = w; wb.textContent = w === 'candy' ? '🌙' : '☀️'; wb.title = w === 'candy' ? 'Night look' : 'Day look'; try { localStorage.setItem('tm_dino_world', w); } catch (e) {} if (state !== 'run') paint(); }

    /* ---------- rules ---------- */
    var s, state = 'idle', last = 0, raf = 0, jumpQ = false, parts = [], tShake = 0, startAt = 0;
    function fresh() { return { y: 0, vy: 0, dist: 0, coins: 0, speed: 6.2, obs: [], gold: [], gap: 300, f: 0, dead: false }; }
    function step(f) {
      if (jumpQ && s.y === 0) { s.vy = 9.4; for (var i = 0; i < 5; i++) parts.push({ x: 92, y: GROUND, vx: -1 - Math.random(), vy: -.6 * Math.random(), a: .6, r: 2, k: 'dust' }); }
      jumpQ = false;
      s.vy -= .58 * f; s.y += s.vy * f;
      if (s.y <= 0) { if (s.vy < -3) for (var j = 0; j < 4; j++) parts.push({ x: 90 + Math.random() * 20, y: GROUND, vx: -1.2, vy: -.5, a: .5, r: 2, k: 'dust' }); s.y = 0; s.vy = 0; }
      s.speed = Math.min(13.5, 6.2 + s.dist / 2400); s.dist += s.speed * f; s.f += f; s.gap -= s.speed * f;
      if (s.gap <= 0) {
        var w = 22 + Math.floor(Math.random() * 3) * 10, h = 40 + Math.floor(Math.random() * 3) * 10;
        s.obs.push({ x: W + 20, w: w, h: h });
        if (Math.random() < .7) for (var c = 0; c < 3; c++) s.gold.push({ x: W + 20 + w + 90 + c * 34, y: 70 + Math.sin(c / 2 * Math.PI) * 38, got: false });
        s.gap = 250 + s.speed * 18 + Math.random() * 190;
      }
      s.obs.forEach(function (o) { o.x -= s.speed * f; }); s.gold.forEach(function (g) { g.x -= s.speed * f; });
      s.obs = s.obs.filter(function (o) { return o.x + o.w > -20; }); s.gold = s.gold.filter(function (g) { return g.x > -20 && !g.got; });
      var dx = 70, dw = 40;
      s.obs.forEach(function (o) { if (dx + dw - 6 > o.x + 4 && dx + 6 < o.x + o.w - 4 && s.y < o.h - 6) s.dead = true; });
      s.gold.forEach(function (g) { if (!g.got && g.x > dx - 6 && g.x < dx + dw + 10 && Math.abs((GROUND - g.y) - (s.y + 24)) < 34) { g.got = true; s.coins++; for (var i = 0; i < 6; i++) parts.push({ x: g.x, y: g.y, vx: (Math.random() - .5) * 3, vy: (Math.random() - .5) * 3, a: 1, r: 2.5, k: 'spark' }); } });
    }
    var score = function () { return Math.floor(s.dist / 10) + s.coins * 10; };

    /* ---------- drawing ---------- */
    function rr(x, y, w, h, r) { ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); }
    function dinoPath(x, y) {
      ctx.beginPath();
      ctx.moveTo(x - 10, y - 30); ctx.quadraticCurveTo(x - 30, y - 28, x - 36, y - 18); ctx.quadraticCurveTo(x - 20, y - 22, x - 6, y - 16);
      ctx.quadraticCurveTo(x - 2, y - 4, x + 6, y - 6); ctx.lineTo(x + 28, y - 6); ctx.quadraticCurveTo(x + 36, y - 12, x + 34, y - 28);
      ctx.quadraticCurveTo(x + 34, y - 40, x + 40, y - 46); ctx.quadraticCurveTo(x + 46, y - 62, x + 62, y - 60);
      ctx.quadraticCurveTo(x + 76, y - 58, x + 76, y - 46); ctx.quadraticCurveTo(x + 76, y - 38, x + 62, y - 38); ctx.lineTo(x + 54, y - 38);
      ctx.quadraticCurveTo(x + 52, y - 30, x + 44, y - 26); ctx.quadraticCurveTo(x + 26, y - 18, x + 4, y - 32); ctx.quadraticCurveTo(x - 4, y - 38, x - 10, y - 30);
      ctx.closePath();
    }
    function legs(x, y, ph, col) { ctx.fillStyle = col; var a = Math.sin(ph) * 7, b = -a; rr(x + 4 + a * .3, y - 10, 11, 12 + Math.max(0, -a * .4), 5); ctx.fill(); rr(x + 20 + b * .3, y - 10, 11, 12 + Math.max(0, -b * .4), 5); ctx.fill(); }
    function drawWorld() {
      if (world === 'candy') {
        var c = P.candy, g = ctx.createLinearGradient(0, 0, 0, GROUND); g.addColorStop(0, c.skyA); g.addColorStop(1, c.skyB); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
        var sg = ctx.createRadialGradient(540, 66, 6, 540, 66, 60); sg.addColorStop(0, 'rgba(255,225,120,.95)'); sg.addColorStop(1, 'rgba(255,225,120,0)'); ctx.fillStyle = sg; ctx.fillRect(470, 0, 140, 140);
        ctx.fillStyle = '#ffd24a'; ctx.beginPath(); ctx.arc(540, 66, 24, 0, 7); ctx.fill();
        ctx.fillStyle = 'rgba(255,255,255,.9)';
        [[80, 46, 1], [260, 84, .8], [430, 36, 1.2]].forEach(function (cl) { var x = ((cl[0] - s.dist * .07 * cl[2]) % (W + 160) + W + 160) % (W + 160) - 80; ctx.beginPath(); ctx.ellipse(x, cl[1], 36 * cl[2], 12 * cl[2], 0, 0, 7); ctx.ellipse(x + 22 * cl[2], cl[1] - 8 * cl[2], 22 * cl[2], 12 * cl[2], 0, 0, 7); ctx.fill(); });
        [[c.hill1, .16, 90, 70], [c.hill2, .34, 70, 46]].forEach(function (L) { ctx.fillStyle = L[0]; ctx.beginPath(); ctx.moveTo(0, GROUND); for (var x = 0; x <= W; x += 16) ctx.lineTo(x, GROUND - L[3] - Math.sin((x + s.dist * L[1]) / L[2]) * L[3] * .5 - Math.sin((x + s.dist * L[1]) / (L[2] * 2.2)) * L[3] * .3); ctx.lineTo(W, GROUND); ctx.fill(); });
        ctx.fillStyle = c.ground; ctx.fillRect(0, GROUND, W, H - GROUND); ctx.fillStyle = c.line; ctx.fillRect(0, GROUND, W, 3);
        ctx.fillStyle = 'rgba(160,120,60,.35)'; for (var i = 0; i < 24; i++) ctx.fillRect(((i * 61 - s.dist) % W + W) % W, GROUND + 12 + (i % 4) * 8, 8 + (i % 3) * 6, 2);
      } else {
        var n = P.neon, g2 = ctx.createLinearGradient(0, 0, 0, GROUND); g2.addColorStop(0, n.skyA); g2.addColorStop(1, n.skyB); ctx.fillStyle = g2; ctx.fillRect(0, 0, W, H);
        ctx.fillStyle = 'rgba(255,255,255,.85)'; for (var q2 = 0; q2 < 26; q2++) ctx.fillRect((q2 * 97 + 23) % W, (q2 * 53) % 130 + 8, 1.6, 1.6);
        var sy = 130, sgr = ctx.createLinearGradient(0, sy - 50, 0, sy + 50); sgr.addColorStop(0, '#fde047'); sgr.addColorStop(1, '#ec4899');
        ctx.save(); ctx.beginPath(); ctx.arc(470, sy, 50, 0, 7); ctx.clip(); ctx.fillStyle = sgr; ctx.fillRect(400, sy - 60, 140, 120); ctx.fillStyle = n.skyB; for (var b = 0; b < 6; b++) ctx.fillRect(400, sy + b * 9 - 6, 140, 2 + b * 1.2); ctx.restore();
        ctx.shadowColor = '#d946ef'; ctx.shadowBlur = 14; ctx.strokeStyle = '#a855f7'; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(0, GROUND - 30); for (var m = 0; m <= W; m += 40) ctx.lineTo(m, GROUND - 30 - ((m * 7 + 13) % 5) * 8 - 10); ctx.lineTo(W, GROUND - 30); ctx.stroke(); ctx.shadowBlur = 0;
        ctx.fillStyle = n.ground; ctx.fillRect(0, GROUND, W, H - GROUND);
        ctx.strokeStyle = n.grid; ctx.globalAlpha = .75; ctx.lineWidth = 1.4;
        for (var v = -10; v <= 24; v++) { var bx = v * 60 - ((s.dist * 1.2) % 60); ctx.beginPath(); ctx.moveTo(320 + (bx - 320) * .2, GROUND); ctx.lineTo(bx * 2.2 - 340, H); ctx.stroke(); }
        for (var h = 0; h < 5; h++) { var yy = GROUND + Math.pow((h + ((s.dist * .03) % 1)) / 5, 2) * (H - GROUND); ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(W, yy); ctx.stroke(); }
        ctx.globalAlpha = 1; ctx.shadowColor = n.line; ctx.shadowBlur = 12; ctx.fillStyle = n.line; ctx.fillRect(0, GROUND, W, 2.5); ctx.shadowBlur = 0;
      }
    }
    function drawCactus(o) {
      var x = o.x, base = GROUND, h = o.h, w = o.w, st = Math.max(11, w * .42), cx = x + w / 2 - st / 2;
      function body() { rr(cx, base - h, st, h, st / 2); ctx.fill(); rr(x, base - h * .62, cx - x + 4, 10, 5); ctx.fill(); rr(x, base - h * .62 - 14, 10, 24, 5); ctx.fill(); rr(cx + st - 4, base - h * .45, x + w - cx - st + 4, 10, 5); ctx.fill(); rr(x + w - 10, base - h * .45 - 16, 10, 28, 5); ctx.fill(); }
      if (world === 'candy') { ctx.fillStyle = P.candy.cactus; body(); ctx.fillStyle = P.candy.cactus2; rr(cx + st * .62, base - h + 6, 3, h - 10, 2); ctx.fill(); ctx.fillStyle = '#fff'; for (var i = 0; i < 3; i++) ctx.fillRect(cx + st / 2 - 1, base - h + 14 + i * 14, 2, 4); }
      else { ctx.shadowColor = '#e879f9'; ctx.shadowBlur = 16; ctx.fillStyle = '#2a0a3d'; body(); ctx.strokeStyle = P.neon.cactus; ctx.lineWidth = 2.2; ctx.beginPath(); ctx.moveTo(cx, base); ctx.lineTo(cx, base - h + st / 2); ctx.arc(cx + st / 2, base - h + st / 2, st / 2, Math.PI, 0); ctx.lineTo(cx + st, base); ctx.stroke(); ctx.shadowBlur = 0; }
    }
    function drawCoin(g, t) {
      var sq = Math.abs(Math.cos(t / 14 + g.x / 30)); ctx.save(); if (world === 'neon') { ctx.shadowColor = '#fde047'; ctx.shadowBlur = 14; }
      var y = GROUND - g.y; ctx.fillStyle = '#fbbf24'; ctx.beginPath(); ctx.ellipse(g.x, y, 9 * (.35 + .65 * sq), 9, 0, 0, 7); ctx.fill();
      ctx.fillStyle = '#fde68a'; ctx.beginPath(); ctx.ellipse(g.x, y, 5 * (.35 + .65 * sq), 5, 0, 0, 7); ctx.fill(); ctx.restore();
    }
    function drawDino() {
      var x = 60, y = GROUND - s.y, run = state === 'run' && s.y === 0, ph = s.f / 2.4, dead = s.dead, bob = run ? Math.abs(Math.sin(ph)) * 2 : 0;
      ctx.save();
      ctx.fillStyle = world === 'candy' ? 'rgba(0,0,0,' + (.22 - Math.min(.14, s.y / 400)) + ')' : 'rgba(34,211,238,' + (.25 - Math.min(.18, s.y / 300)) + ')'; ctx.beginPath(); ctx.ellipse(x + 30, GROUND + 4, 30 - Math.min(14, s.y / 5), 5, 0, 0, 7); ctx.fill();
      var ty = y - bob; if (dead) { ctx.translate(x + 20, ty - 20); ctx.rotate(-.18); ctx.translate(-(x + 20), -(ty - 20)); }
      if (world === 'candy') {
        var col = dead ? '#9ca3af' : P.candy.dino;
        ctx.fillStyle = dead ? '#6b7280' : '#c2410c'; [[-2, -34], [8, -37], [18, -36]].forEach(function (p) { ctx.beginPath(); ctx.moveTo(x + p[0], ty + p[1] + 4); ctx.lineTo(x + p[0] + 5, ty + p[1] - 6); ctx.lineTo(x + p[0] + 10, ty + p[1] + 4); ctx.fill(); });
        legs(x, ty, ph, dead ? '#6b7280' : '#c2410c');
        dinoPath(x, ty); ctx.fillStyle = col; ctx.fill();
        ctx.save(); dinoPath(x, ty); ctx.clip(); ctx.fillStyle = dead ? '#d1d5db' : P.candy.belly; ctx.beginPath(); ctx.ellipse(x + 24, ty - 8, 24, 13, 0, 0, 7); ctx.fill(); ctx.restore();
        ctx.fillStyle = dead ? '#6b7280' : '#c2410c'; ctx.beginPath(); ctx.arc(x + 36, ty - 26, 4, 0, 7); ctx.fill();
        ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(x + 62, ty - 50, 7.5, 0, 7); ctx.fill();
        if (dead) { ctx.strokeStyle = '#dc2626'; ctx.lineWidth = 2.4; ctx.beginPath(); ctx.moveTo(x + 58, ty - 54); ctx.lineTo(x + 66, ty - 46); ctx.moveTo(x + 66, ty - 54); ctx.lineTo(x + 58, ty - 46); ctx.stroke(); }
        else { ctx.fillStyle = '#111827'; ctx.beginPath(); ctx.arc(x + 64, ty - 50, 3.6, 0, 7); ctx.fill(); ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(x + 65, ty - 51.5, 1.2, 0, 7); ctx.fill(); }
        ctx.fillStyle = 'rgba(244,63,94,.35)'; ctx.beginPath(); ctx.ellipse(x + 56, ty - 41, 5, 3, 0, 0, 7); ctx.fill();
        ctx.strokeStyle = '#7c2d12'; ctx.lineWidth = 1.8; ctx.beginPath(); if (dead) ctx.arc(x + 66, ty - 41, 4, Math.PI, 0); else ctx.arc(x + 65, ty - 44, 5, .15 * Math.PI, .85 * Math.PI); ctx.stroke();
      } else {
        var c2 = dead ? '#94a3b8' : P.neon.dino; ctx.shadowColor = c2; ctx.shadowBlur = 18; ctx.strokeStyle = c2; ctx.lineWidth = 2.6; ctx.fillStyle = 'rgba(34,211,238,.14)';
        dinoPath(x, ty); ctx.fill(); ctx.stroke();
        var a = Math.sin(ph) * 7; ctx.beginPath(); ctx.moveTo(x + 8 + a * .3, ty - 8); ctx.lineTo(x + 8 + a * .3, ty + 2); ctx.moveTo(x + 26 - a * .3, ty - 8); ctx.lineTo(x + 26 - a * .3, ty + 2); ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.stroke(); ctx.lineWidth = 2.6;
        ctx.shadowBlur = 8; ctx.fillStyle = dead ? '#f87171' : '#fff'; ctx.beginPath(); ctx.arc(x + 63, ty - 50, 4, 0, 7); ctx.fill();
      }
      ctx.restore();
    }
    function paint() {
      if (!s) s = fresh();
      ctx.clearRect(0, 0, W, H); var sh = tShake > 0 ? (Math.random() - .5) * tShake : 0; ctx.save(); ctx.translate(sh, 0);
      drawWorld(); s.obs.forEach(drawCactus); s.gold.forEach(function (g) { drawCoin(g, s.f); }); drawDino();
      parts.forEach(function (p) { ctx.globalAlpha = Math.max(0, p.a); ctx.fillStyle = p.k === 'spark' ? '#fde047' : (world === 'candy' ? '#b9975b' : '#22d3ee'); ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 7); ctx.fill(); }); ctx.globalAlpha = 1;
      ctx.restore(); q('.sc').textContent = score();
    }
    function fx(f) { parts.forEach(function (p) { p.x += p.vx * f; p.y += p.vy * f; p.a -= .04 * f; }); parts = parts.filter(function (p) { return p.a > 0; }); if (tShake > 0) tShake = Math.max(0, tShake - 1.4 * f); }

    /* ---------- flow + company board ---------- */
    function loop(now) { if (state !== 'run') return; var f = Math.min(3, (now - last) / 16.67); last = now; step(f); fx(f); paint(); if (s.dead) return over(); raf = requestAnimationFrame(loop); }
    function begin() { s = fresh(); parts = []; state = 'run'; start.style.display = 'none'; goov.style.display = 'none'; startAt = performance.now(); last = startAt; jumpQ = true; cancelAnimationFrame(raf); raf = requestAnimationFrame(loop); }
    function press() { if (state === 'run') { jumpQ = true; return; } begin(); }
    function cell(ic, lbl, r) { return '<div' + (r.mine ? ' class="me"' : '') + '><small>' + ic + ' ' + lbl + '</small><b>' + esc(r.who) + (r.mine ? ' (you)' : '') + '</b><i>' + r.best + '</i></div>'; }
    function board(showResult, sc, ms) {
      if (!window.api || !api.get) { q('.tmd-champ').innerHTML = ''; if (showResult) showReport(sc, null); return; }
      api.get('play.dino_board').then(function (d) {
        var l = [];
        l.push(d.champion ? '<span class="tmd-chip">🏆 Today <b>' + esc(d.champion.who) + '</b> ' + d.champion.best + (d.champion.mine ? ' (you!)' : '') + '</span>' : '<span class="tmd-chip">🏆 Be today\'s first champion</span>');
        if (d.month_top) l.push('<span class="tmd-chip">👑 Month <b>' + esc(d.month_top.who) + '</b> ' + d.month_top.best + (d.month_top.mine ? ' (you!)' : '') + '</span>');
        q('.tmd-champ').innerHTML = l.join('');
        if (showResult) showReport(sc, d);
      }).catch(function () { q('.tmd-champ').innerHTML = ''; if (showResult) showReport(sc, null); });
    }
    function showReport(sc, d) {   // the report lives on the game screen itself
      q('.gr-r').textContent = sc < 20 ? 'Score 20+ joins the board' : (d && d.mine_rank ? '#' + d.mine_rank + ' of ' + d.players + ' today' : '');
      var h = '';
      if (d && d.top && d.top[0]) h += cell('🥇', 'First today', d.top[0]);
      if (d && d.month_top) h += cell('👑', 'Top of month', d.month_top);
      if (d && d.last) h += cell('🐢', 'Last today', d.last);
      q('.tmd-rp').innerHTML = h;
    }
    function over() {
      state = 'dead'; if (!rm) { tShake = 10; (function sh() { if (tShake > 0 && state === 'dead') { tShake = Math.max(0, tShake - 1.2); paint(); requestAnimationFrame(sh); } else { tShake = 0; if (state === 'dead') paint(); } })(); }
      try { navigator.vibrate && navigator.vibrate(40); } catch (e) {}
      var sc = score(), ms = Math.round(performance.now() - startAt), nb = sc > best;
      q('.best').dataset.nb = nb ? '1' : '0';
      if (nb) { best = sc; q('.best').textContent = best; try { localStorage.setItem('tm_dino_best', String(best)); } catch (e) {} if (window.TMWin && sc >= 50) TMWin.win({ title: 'New best: ' + sc, ms: 2200, from: cv }); }
      q('.tmd-rs').innerHTML = '<b>' + sc + '</b>' + (nb ? '<span class="tmd-tag">NEW BEST</span>' : '') + '<small>' + s.coins + ' coins</small>'; q('.tmd-rp').innerHTML = ''; q('.gr-r').textContent = ''; goov.style.display = 'flex';
      var after = function () { board(true, sc, ms); };
      if (sc >= 20 && window.api && api.post) api.post('play.dino_score', { score: sc, ms: ms }).then(after).catch(after); else after();
    }
    wb.addEventListener('click', function () { setWorld(world === 'candy' ? 'neon' : 'candy'); });
    q('.tmd-stage').addEventListener('pointerdown', function (e) { e.preventDefault(); press(); });
    jb.addEventListener('pointerdown', function (e) { e.preventDefault(); jb.classList.add('on'); press(); });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (n) { jb.addEventListener(n, function () { jb.classList.remove('on'); }); });
    sj.addEventListener('pointerdown', function (e) { e.preventDefault(); e.stopPropagation(); sj.classList.add('on'); press(); });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (n) { sj.addEventListener(n, function () { sj.classList.remove('on'); }); });
    document.addEventListener('keydown', function (e) {
      if (!host.isConnected || (e.key !== ' ' && e.key !== 'ArrowUp')) return;
      var t = e.target && e.target.tagName; if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || t === 'BUTTON') return;
      var r = cv.getBoundingClientRect(); if (r.bottom < 0 || r.top > innerHeight) return;
      e.preventDefault(); press();
    });
    document.addEventListener('visibilitychange', function () { if (document.hidden && state === 'run') { state = 'idle'; start.style.display = ''; cancelAnimationFrame(raf); } });
    setWorld(world); s = fresh(); paint(); board(false);
    return { state: function () { return { s: s, st: state, score: score() }; }, press: press };
  }
  window.TMDino = { mount: mount };
})();
