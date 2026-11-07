/* Diwali theme loader. Asks the server (theme.get) whether the theme is on; the answer is cached for 30 minutes
   so no page waits for it. Safe to load on any page: if anything fails the page simply looks normal. */
(function () {
  if (window.__dwLoaded) return; window.__dwLoaded = true;
  var KEY = 'hrms_theme_cache', TTL = 30 * 60 * 1000;
  function apply(on) {
    var root = document.documentElement;
    if (!on) { root.classList.remove('diwali'); ['dwGarland', 'dwDiya', 'dwHello'].forEach(function (id) { var e = document.getElementById(id); if (e) e.remove(); }); document.querySelectorAll('.dw-spark').forEach(function (e) { e.remove(); }); return; }
    if (root.classList.contains('diwali') && document.getElementById('dwGarland')) return;
    root.classList.add('diwali');
    if (!document.getElementById('dwCss')) {
      var l = document.createElement('link'); l.id = 'dwCss'; l.rel = 'stylesheet'; l.href = 'assets/css/diwali.css?v=261024c'; document.head.appendChild(l);
    }
    build();
  }
  function build() {
    if (document.getElementById('dwGarland') || !document.body) return;
    var cols = ['#f5b301', '#e8651a', '#c1121f', '#f59e0b', '#ff8f1f'];
    var n = 26, w = 1000, parts = '', i;
    parts += '<path d="M0 6 Q' + (w / 4) + ' 22 ' + (w / 2) + ' 6 T' + w + ' 6" fill="none" stroke="#b4410c" stroke-width="1.6"/>';
    for (i = 0; i <= n; i++) {
      var x = i * (w / n), t = i / n;
      var y = 6 + Math.abs(Math.sin(t * Math.PI * 2)) * 10 * (i % 2 ? 1 : .9);
      var c = cols[i % cols.length];
      parts += '<g class="b"><circle cx="' + x.toFixed(1) + '" cy="' + (y + 7).toFixed(1) + '" r="6" fill="' + c + '"/><circle cx="' + x.toFixed(1) + '" cy="' + (y + 7).toFixed(1) + '" r="2.4" fill="#fff3b0" opacity=".85"/><path d="M' + x.toFixed(1) + ' ' + y.toFixed(1) + ' l0 1" stroke="#7a1f0b"/></g>';
    }
    var g = document.createElement('div'); g.id = 'dwGarland'; g.setAttribute('aria-hidden', 'true');
    g.innerHTML = '<svg viewBox="0 0 ' + w + ' 40" preserveAspectRatio="none">' + parts + '</svg>';
    document.body.appendChild(g);
    var d = document.createElement('div'); d.id = 'dwDiya'; d.setAttribute('aria-hidden', 'true');
    d.innerHTML = '<svg viewBox="0 0 54 44"><ellipse class="gl" cx="27" cy="20" rx="22" ry="16" fill="rgba(245,179,1,.30)"/>' +
      '<path class="fl" d="M27 4 C21 12 20 17 27 22 C34 17 33 12 27 4Z" fill="#ffb703"/><path class="fl" d="M27 11 C24 15 24 18 27 21 C30 18 30 15 27 11Z" fill="#fff3b0"/>' +
      '<path d="M5 24 Q27 24 49 24 Q46 40 27 40 Q8 40 5 24Z" fill="#b4410c"/><path d="M5 24 Q27 29 49 24" fill="none" stroke="#f5b301" stroke-width="1.6"/><circle cx="27" cy="33" r="2" fill="#f5b301"/></svg>';
    document.body.appendChild(d);
    if (!window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      for (var k = 0; k < 7; k++) {
        var s = document.createElement('i'); s.className = 'dw-spark';
        s.style.left = (6 + Math.random() * 88) + 'vw'; s.style.top = (8 + Math.random() * 70) + 'vh';
        s.style.animationDelay = (Math.random() * 3.4).toFixed(2) + 's'; s.style.animationDuration = (2.6 + Math.random() * 2).toFixed(2) + 's';
        document.body.appendChild(s);
      }
    }
    hello();
  }
  function hello() {
    try {
      var today = new Date().toISOString().slice(0, 10);
      if (localStorage.getItem('hrms_dw_hello') === today) return;
      localStorage.setItem('hrms_dw_hello', today);
    } catch (e) { return; }
    var h = document.createElement('div'); h.id = 'dwHello'; h.textContent = '🪔 Happy Diwali! શુભ દિવાળી — sell bright today ✨';
    document.body.appendChild(h);
    setTimeout(function () { h.classList.add('on'); }, 600);
    setTimeout(function () { h.classList.remove('on'); }, 5200);
    setTimeout(function () { if (h.parentNode) h.remove(); }, 6000);
  }
  function run() {
    var cached = null;
    try { cached = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
    var fresh = cached && (Date.now() - cached.t) < TTL;
    if (cached) apply(!!cached.on);
    if (fresh) return;
    if (typeof api === 'undefined' || !api.token || !api.token()) return;
    api.get('theme.get').then(function (d) {
      try { localStorage.setItem(KEY, JSON.stringify({ t: Date.now(), on: !!(d && d.on) })); } catch (e) {}
      apply(!!(d && d.on));
    }).catch(function () {});
  }
  window.hrmsTheme = { refresh: function () { try { localStorage.removeItem(KEY); } catch (e) {} run(); } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();