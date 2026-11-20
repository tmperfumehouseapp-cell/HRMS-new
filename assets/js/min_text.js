/**
 * Phone text floor + tap size: on narrow screens no text is smaller than 11 px and no visible button / link / field is smaller than 26 px
 * (the Help page's phone check flags anything below that).
 * Many screens build their rows with small inline sizes (10 px, .85em of a small parent ...) and small icon buttons / text links. This raises only the ones below the floor, once each,
 * on the pieces of the page as they are added. Wide screens are not touched. Add the line  <script src="assets/js/min_text.js"></script>  to a page to use it.
 * Every element is looked at ONCE (it is marked), so the later passes cost almost nothing even on a long page.
 */
(function () {
  if (window.__minText) return; window.__minText = true;
  var MIN = 11, TAP = 26, narrow = function () { return window.innerWidth <= 900; };
  function fix(root) {
    if (!root || root.nodeType !== 1 || !narrow()) return;
    var all = [root].concat(Array.prototype.slice.call(root.getElementsByTagName('*'))), taps = 0;
    for (var i = 0; i < all.length; i++) {
      var el = all[i], tg = el.tagName; if (tg === 'SCRIPT' || tg === 'STYLE' || tg === 'SVG' || tg === 'svg') continue;
      // 1. text floor
      if (!el.__mt) {
        var has = false, ch = el.childNodes;
        for (var k = 0; k < ch.length; k++) { if (ch[k].nodeType === 3 && ch[k].nodeValue.trim().length > 1) { has = true; break; } }
        if (has) {
          var fz = parseFloat(window.getComputedStyle(el).fontSize);
          if (fz > 0 && fz < MIN) el.style.setProperty('font-size', MIN + 'px', 'important');
          if (fz > 0) el.__mt = 1;   // looked at: not measured again (font-size:0 = a label hidden on purpose, left alone)
        }
      }
      // 2. tap size: a visible button / link / field smaller than 26 px gets 26 px (a tick-box inside a label is tapped through the label)
      if (!el.__tp && taps < 1500 && (tg === 'BUTTON' || tg === 'A' || tg === 'SELECT' || tg === 'INPUT') && el.offsetParent !== null) {
        taps++; var r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) {
          el.__tp = 1;
          if (!(tg === 'INPUT' && el.closest && el.closest('label')) && (r.height < TAP || r.width < TAP)) {
            var cs = window.getComputedStyle(el);
            if (cs.display === 'inline') el.style.setProperty('display', 'inline-block', 'important');
            if (r.height < TAP) el.style.setProperty('min-height', TAP + 'px', 'important');
            if (r.width < TAP) el.style.setProperty('min-width', TAP + 'px', 'important');
            if (el.type === 'checkbox' || el.type === 'radio') { el.style.setProperty('width', TAP + 'px', 'important'); el.style.setProperty('height', TAP + 'px', 'important'); }
          }
        }
      }
    }
  }
  var queue = [], timer = null;
  function flush() { timer = null; var q = queue; queue = []; q.forEach(fix); }
  var mo = new MutationObserver(function (muts) {
    muts.forEach(function (m) { if (m.type === 'characterData' && m.target.parentNode) { queue.push(m.target.parentNode); return; } Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) queue.push(n); else if (n.nodeType === 3 && n.parentNode) queue.push(n.parentNode); }); });
    if (queue.length && !timer) timer = setTimeout(flush, 30);
  });
  function start() { fix(document.body); mo.observe(document.body, { childList: true, subtree: true, characterData: true }); setInterval(function () { if (narrow() && !document.hidden) fix(document.body); }, 1500); }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  window.addEventListener('resize', function () { if (narrow()) fix(document.body); });
})();
