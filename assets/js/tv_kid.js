/* The little kid on the store TV (store_display_tv.html): our own character (orange cap, blue t-shirt, a small perfume bottle).
   He runs back and forth, up and down over the card, jumps, sits, yawns, peeks and waves; when a new bill comes he runs to the big number and dances.
   Plain old JavaScript and CSS (transform / opacity only) so it runs on the old Chrome of cheap Android TV boxes. */
(function () {
  var stage = document.getElementById('stage'); if (!stage) return;
  var css = ''
    + '#kid{position:absolute;left:0;top:0;width:160px;height:215px;z-index:4;pointer-events:none;will-change:transform}'
    + '#kid .in{width:100%;height:100%;-webkit-transform-origin:50% 100%;transform-origin:50% 100%}'
    + '#kid.left .in{-webkit-transform:scaleX(-1);transform:scaleX(-1)}'
    + '#kid svg{width:100%;height:100%;overflow:visible}'
    + '#kid .lg1,#kid .lg2,#kid .ar1,#kid .ar2{-webkit-transform-box:fill-box;transform-box:fill-box;-webkit-transform-origin:50% 0;transform-origin:50% 0}'
    + '#kid.run .lg1{-webkit-animation:kl .32s ease-in-out infinite alternate;animation:kl .32s ease-in-out infinite alternate}'
    + '#kid.run .lg2{-webkit-animation:kl .32s ease-in-out infinite alternate-reverse;animation:kl .32s ease-in-out infinite alternate-reverse}'
    + '#kid.run .ar1{-webkit-animation:ka .32s ease-in-out infinite alternate-reverse;animation:ka .32s ease-in-out infinite alternate-reverse}'
    + '#kid.run .ar2{-webkit-animation:ka .32s ease-in-out infinite alternate;animation:ka .32s ease-in-out infinite alternate}'
    + '#kid.run .bd{-webkit-animation:kb .16s ease-in-out infinite alternate;animation:kb .16s ease-in-out infinite alternate}'
    + '@-webkit-keyframes kl{from{-webkit-transform:rotate(-32deg)}to{-webkit-transform:rotate(32deg)}}@keyframes kl{from{transform:rotate(-32deg)}to{transform:rotate(32deg)}}'
    + '@-webkit-keyframes ka{from{-webkit-transform:rotate(-40deg)}to{-webkit-transform:rotate(40deg)}}@keyframes ka{from{transform:rotate(-40deg)}to{transform:rotate(40deg)}}'
    + '@-webkit-keyframes kb{to{-webkit-transform:translateY(-5px)}}@keyframes kb{to{transform:translateY(-5px)}}'
    + '#kid.wave .ar2{-webkit-animation:kw .35s ease-in-out infinite alternate;animation:kw .35s ease-in-out infinite alternate}'
    + '@-webkit-keyframes kw{from{-webkit-transform:rotate(-150deg)}to{-webkit-transform:rotate(-110deg)}}@keyframes kw{from{transform:rotate(-150deg)}to{transform:rotate(-110deg)}}'
    + '#kid.dance .in{-webkit-animation:kd .5s ease-in-out infinite alternate;animation:kd .5s ease-in-out infinite alternate}'
    + '#kid.dance .ar1,#kid.dance .ar2{-webkit-transform:rotate(-160deg);transform:rotate(-160deg)}'
    + '#kid.dance .lg1{-webkit-animation:kl .25s infinite alternate;animation:kl .25s infinite alternate}#kid.dance .lg2{-webkit-animation:kl .25s infinite alternate-reverse;animation:kl .25s infinite alternate-reverse}'
    + '@-webkit-keyframes kd{from{-webkit-transform:rotate(-12deg) translateY(0)}to{-webkit-transform:rotate(12deg) translateY(-26px)}}@keyframes kd{from{transform:rotate(-12deg) translateY(0)}to{transform:rotate(12deg) translateY(-26px)}}'
    + '#kid.sit .lg1,#kid.sit .lg2{-webkit-transform:rotate(-80deg);transform:rotate(-80deg)}#kid.sit .bd{-webkit-transform:translateY(26px);transform:translateY(26px)}'
    + '#kid .eye{-webkit-transform-box:fill-box;transform-box:fill-box;-webkit-transform-origin:50% 50%;transform-origin:50% 50%;-webkit-animation:kblink 4s infinite;animation:kblink 4s infinite}'
    + '@-webkit-keyframes kblink{0%,92%,100%{-webkit-transform:scaleY(1)}95%{-webkit-transform:scaleY(.1)}}@keyframes kblink{0%,92%,100%{transform:scaleY(1)}95%{transform:scaleY(.1)}}'
    + '#kid .m-open,#kid.happy .m-smile,#kid.yawn .m-smile{display:none}#kid.happy .m-open,#kid.yawn .m-open{display:inline}'
    + '#kid.yawn .eye{-webkit-animation:none;animation:none;-webkit-transform:scaleY(.15);transform:scaleY(.15)}'
    + '#kid .bub{position:absolute;left:70px;top:-58px;background:#fff;color:#ff5a00;border:3px solid #ff5a00;border-radius:22px;padding:6px 16px;font:900 30px/1.1 Inter,Arial,sans-serif;white-space:nowrap;opacity:0;-webkit-transition:opacity .25s;transition:opacity .25s}'
    + '#kid .bub.on{opacity:1}#kid .bub.r{left:auto;right:70px}'
    + '#kid .mist{position:absolute;left:118px;top:86px;width:12px;height:12px;border-radius:50%;background:#bde7ff;opacity:0}'
    + '#kid.spray .mist{-webkit-animation:km 1s ease-out infinite;animation:km 1s ease-out infinite}#kid.spray .mist.b{-webkit-animation-delay:.33s;animation-delay:.33s}#kid.spray .mist.c{-webkit-animation-delay:.66s;animation-delay:.66s}'
    + '@-webkit-keyframes km{0%{opacity:.9;-webkit-transform:translate(0,0) scale(.5)}100%{opacity:0;-webkit-transform:translate(46px,-30px) scale(2.4)}}@keyframes km{0%{opacity:.9;transform:translate(0,0) scale(.5)}100%{opacity:0;transform:translate(46px,-30px) scale(2.4)}}';
  var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);

  var kid = document.createElement('div'); kid.id = 'kid';
  kid.innerHTML = '<div class="bub" id="kidBub">Yay!</div><div class="mist"></div><div class="mist b"></div><div class="mist c"></div><div class="in"><svg viewBox="0 0 120 160">'
    + '<ellipse cx="60" cy="156" rx="30" ry="5" fill="rgba(0,0,0,.18)"/>'
    + '<g class="bd">'
    + '<g class="lg1"><rect x="44" y="104" width="13" height="38" rx="6" fill="#ffd2ad"/><rect x="40" y="136" width="22" height="12" rx="6" fill="#ffffff" stroke="#334" stroke-width="2"/></g>'
    + '<g class="lg2"><rect x="63" y="104" width="13" height="38" rx="6" fill="#ffd2ad"/><rect x="60" y="136" width="22" height="12" rx="6" fill="#ffffff" stroke="#334" stroke-width="2"/></g>'
    + '<rect x="40" y="98" width="40" height="18" rx="7" fill="#16a34a"/>'
    + '<g class="ar1"><rect x="31" y="70" width="11" height="32" rx="5.5" fill="#ffd2ad"/></g>'
    + '<rect x="38" y="66" width="44" height="40" rx="14" fill="#2563eb"/><circle cx="60" cy="86" r="7" fill="#ffffff" opacity=".9"/><text x="60" y="90" font-size="9" font-weight="900" text-anchor="middle" fill="#ff5a00" font-family="Arial">TM</text>'
    + '<g class="ar2"><rect x="78" y="70" width="11" height="32" rx="5.5" fill="#ffd2ad"/><g transform="translate(76 98)"><rect x="2" y="2" width="12" height="16" rx="3" fill="#f9a8d4" stroke="#9d174d" stroke-width="1.5"/><rect x="5" y="-3" width="6" height="6" rx="1.5" fill="#d4af37"/></g></g>'
    + '<circle cx="60" cy="40" r="30" fill="#ffd9b8"/>'
    + '<path d="M30 36 Q32 8 60 8 Q88 8 90 36 Q75 26 60 27 Q45 26 30 36Z" fill="#ff5a00"/><rect x="58" y="4" width="20" height="7" rx="3.5" fill="#ff7a2e"/><path d="M84 33 Q100 34 104 40 Q92 40 84 38Z" fill="#e14d00"/>'
    + '<path d="M40 28 q4 -6 8 0" stroke="#5b3a1e" stroke-width="3" fill="none" stroke-linecap="round" opacity=".0"/>'
    + '<ellipse class="eye" cx="50" cy="42" rx="4" ry="5.5" fill="#1f2937"/><ellipse class="eye" cx="70" cy="42" rx="4" ry="5.5" fill="#1f2937"/>'
    + '<circle cx="51.5" cy="40" r="1.4" fill="#fff"/><circle cx="71.5" cy="40" r="1.4" fill="#fff"/>'
    + '<circle cx="42" cy="52" r="5" fill="#ff9eb5" opacity=".7"/><circle cx="78" cy="52" r="5" fill="#ff9eb5" opacity=".7"/>'
    + '<path class="m-smile" d="M52 54 Q60 61 68 54" stroke="#7c2d12" stroke-width="3" fill="none" stroke-linecap="round"/>'
    + '<ellipse class="m-open" cx="60" cy="56" rx="6" ry="5" fill="#7c2d12"/>'
    + '</g></svg></div>';
  stage.appendChild(kid);

  var x = 40, y = 1000, timer = null, party = false, moveId = 0;
  // where he likes to go: the floor, on the rows, beside the big number, up near the title, on the button
  var SPOTS = [[20, 1060], [540, 1060], [560, 820], [20, 820], [500, 520], [520, 200], [540, 10], [250, 1065], [560, 960], [380, 330]];
  function put() { var t = 'translate(' + Math.round(x) + 'px,' + Math.round(y) + 'px)'; kid.style.webkitTransform = t; kid.style.transform = t; }
  function mode(m) { kid.className = m + (kid.className.indexOf('left') > -1 ? ' left' : ''); }
  function face(left) { if (left && kid.className.indexOf('left') < 0) kid.className += ' left'; if (!left) kid.className = kid.className.replace(' left', '').replace('left', ''); }
  var raf = window.requestAnimationFrame || window.webkitRequestAnimationFrame || function (f) { return setTimeout(function () { f(new Date().getTime()); }, 16); };
  function now() { return (window.performance && performance.now) ? performance.now() : new Date().getTime(); }
  // run to (tx, ty); going up or down he jumps on the way (an arc)
  function runTo(tx, ty, speed, done) {
    var my = ++moveId, sx = x, sy = y, d = Math.sqrt((tx - sx) * (tx - sx) + (ty - sy) * (ty - sy)), dur = Math.max(400, d / (speed || 0.45)), t0 = now(), hop = Math.min(160, 40 + Math.abs(ty - sy) * 0.25);
    face(tx < sx); mode('run'); face(tx < sx);
    (function step() { if (my !== moveId) return; var k = Math.min(1, (now() - t0) / dur), e = k < .5 ? 2 * k * k : -1 + (4 - 2 * k) * k;
      x = sx + (tx - sx) * e; y = sy + (ty - sy) * e - Math.sin(Math.PI * k) * (Math.abs(ty - sy) > 30 ? hop : 10); put();
      if (k < 1) raf(step); else if (done) done(); })();
  }
  function bubble(t, ms) { var b = document.getElementById('kidBub'); b.textContent = t; b.className = 'bub on' + (x > 420 ? ' r' : ''); setTimeout(function () { b.className = 'bub'; }, ms || 1800); }
  var ACTS = [
    function (n) { mode('wave'); bubble('Hi!', 1600); setTimeout(n, 2000); },
    function (n) { mode('sit'); setTimeout(function () { mode('sit yawn'); setTimeout(function () { mode('sit'); setTimeout(n, 900); }, 1300); }, 1200); },
    function (n) { mode('spray'); bubble('Smells nice!', 2000); setTimeout(n, 2400); },
    function (n) { mode('happy'); var j = 0, my = ++moveId; (function hop() { var sy = y, t0 = now(); (function up() { if (my !== moveId) return; var k = Math.min(1, (now() - t0) / 420); y = sy - Math.sin(Math.PI * k) * 70; put(); if (k < 1) raf(up); else if (++j < 3) hop(); else { mode(''); n(); } })(); })(); },
    function (n) { mode(''); setTimeout(n, 900); }
  ];
  function loop() {
    if (party) return;
    var sp = SPOTS[Math.floor(Math.random() * SPOTS.length)];
    runTo(sp[0], sp[1], 0.4 + Math.random() * 0.25, function () {
      if (party) return; var a = ACTS[Math.floor(Math.random() * ACTS.length)];
      a(function () { if (!party) timer = setTimeout(loop, 300 + Math.random() * 700); });
    });
  }
  put(); mode(''); setTimeout(loop, 800);
  // a new bill: he runs to the big number and dances
  window.tvKid = { party: function (n) {
    party = true; clearTimeout(timer);
    runTo(440, 500, 0.9, function () { mode('dance happy'); bubble('Yay! +' + (n || 1), 2600);
      setTimeout(function () { mode(''); party = false; setTimeout(loop, 400); }, 3000); });
  } };
})();
