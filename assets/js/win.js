/* win.js — the shared "win moment" for every HRMS page (loaded by layout.js).
   TMWin.win({title, amount, from})  a small celebration: coins from the button, a floating "✓ title +₹amount" pill that counts up, a short buzz (phones)
   TMWin.countUp(el, to, {prefix, ms})  numbers that tick up instead of jumping
   TMWin.clear(el, title, sub)  a friendly "All clear" card for empty queues
   TMWin.sound(true|false)  tones are OFF until someone turns them on (saved on this device); buzz can be turned off with TMWin.haptic(false)
   Every green "success" alert (.alert.success) that appears after the page has loaded also gets a small pop + buzz, so every save feels done.
   Honours "reduce motion": no flying coins or counting, only the text. */
(function () {
  if (window.TMWin) return;
  var RM = false; try { RM = window.matchMedia('(prefers-reduced-motion:reduce)').matches; } catch (e) {}
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  var AC;
  function tone(freqs) {
    if (get('tm_sound') !== '1') return;
    try {
      AC = AC || new (window.AudioContext || window.webkitAudioContext)();
      var t = AC.currentTime;
      freqs.forEach(function (f, i) {
        var o = AC.createOscillator(), g = AC.createGain(), s = t + i * 0.09;
        o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, s); g.gain.linearRampToValueAtTime(0.12, s + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, s + 0.35);
        o.connect(g); g.connect(AC.destination); o.start(s); o.stop(s + 0.4);
      });
    } catch (e) { /* sound is optional */ }
  }
  function buzz(p) { if (get('tm_haptic') === '0') return; try { if (navigator.vibrate) navigator.vibrate(p); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(n, prefix) { return (prefix || '') + Math.round(n).toLocaleString('en-IN'); }

  function countUp(el, to, o) {
    o = o || {}; to = Number(to) || 0;
    if (!el) return;
    if (RM) { el.textContent = fmt(to, o.prefix); return; }
    var from = Number(o.from) || 0, ms = o.ms || 700, t0 = performance.now();
    (function step(now) {
      var p = Math.min(1, (now - t0) / ms), e = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(from + (to - from) * e, o.prefix);
      if (p < 1) requestAnimationFrame(step);
    })(t0);
  }

  function burst(from) {
    if (RM || !from || !from.getBoundingClientRect) return;
    var r = from.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2;
    for (var i = 0; i < 12; i++) {
      var c = document.createElement('span'); c.className = 'tm-coin'; c.textContent = i % 3 ? '●' : '₹';
      var a = (Math.PI * 2 * i) / 12 + Math.random() * 0.4, d = 50 + Math.random() * 50;
      c.style.cssText = 'left:' + x + 'px;top:' + y + 'px;--dx:' + Math.cos(a) * d + 'px;--dy:' + (Math.sin(a) * d - 40) + 'px;animation-delay:' + (i * 12) + 'ms';
      document.body.appendChild(c); setTimeout(function (n) { return function () { n.remove(); }; }(c), 1100);
    }
  }

  var pill, pillT;
  function win(o) {
    o = o || {};
    burst(o.from);
    tone([660, 880, 1320]); buzz([30, 40, 30]);
    if (!pill) { pill = document.createElement('div'); pill.className = 'tm-win'; pill.setAttribute('role', 'status'); pill.setAttribute('aria-live', 'polite'); document.body.appendChild(pill); }
    pill.innerHTML = '<span class="tm-win-ic">✓</span><b>' + esc(o.title || 'Done') + '</b>' + (o.amount != null ? '<span class="tm-win-amt"></span>' : '');
    pill.classList.remove('on'); void pill.offsetWidth; pill.classList.add('on');
    if (o.amount != null) countUp(pill.querySelector('.tm-win-amt'), o.amount, { prefix: o.prefix == null ? '+₹' : o.prefix });
    clearTimeout(pillT); pillT = setTimeout(function () { pill.classList.remove('on'); }, o.ms || 2600);
  }

  function clear(el, title, sub) {
    if (!el) return;
    el.innerHTML = '<div class="tm-clear"><div class="tm-clear-ic">✓</div><b>' + esc(title || 'All clear') + '</b><span>' + esc(sub || 'Nothing is waiting for you.') + '</span></div>';
  }

  // every green success alert that appears after load gets a pop
  var ready = false; setTimeout(function () { ready = true; }, 1500);
  try {
    new MutationObserver(function (list) {
      if (!ready) return;
      list.forEach(function (m) {
        m.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          var el = n.matches && n.matches('.alert.success') ? n : (n.querySelector && n.querySelector('.alert.success'));
          if (el && !el.dataset.tmPop) { el.dataset.tmPop = '1'; el.classList.add('tm-pop'); buzz(15); tone([880]); }
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  } catch (e) {}

  window.TMWin = {
    win: win, countUp: countUp, clear: clear,
    sound: function (on) { put('tm_sound', on ? '1' : '0'); if (on) tone([660, 990]); },
    haptic: function (on) { put('tm_haptic', on ? '1' : '0'); }
  };
})();
