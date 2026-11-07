/**
 * Phone text floor: on narrow screens no text is smaller than 11 px (the Help page's phone check flags anything below that).
 * Many screens build their rows with small inline sizes (10 px, .85em of a small parent ...). This raises only the ones below 11 px, once, on the
 * pieces of the page as they are added. Wide screens are not touched. Add the line  <script src="assets/js/min_text.js"></script>  to a page to use it.
 */
(function () {
  if (window.__minText) return; window.__minText = true;
  var MIN = 11, narrow = function () { return window.innerWidth <= 900; };
  function fix(root) {
    if (!root || root.nodeType !== 1 || !narrow()) return;
    var all = [root].concat(Array.prototype.slice.call(root.getElementsByTagName('*')));
    for (var i = 0; i < all.length; i++) {
      var el = all[i], tg = el.tagName; if (tg === 'SCRIPT' || tg === 'STYLE' || tg === 'SVG' || tg === 'svg' || el.__mt) continue;
      var has = false, ch = el.childNodes;
      for (var k = 0; k < ch.length; k++) { if (ch[k].nodeType === 3 && ch[k].nodeValue.trim().length > 1) { has = true; break; } }
      if (!has) continue;
      var fz = parseFloat(window.getComputedStyle(el).fontSize);
      if (fz > 0 && fz < MIN) { el.style.setProperty('font-size', MIN + 'px', 'important'); }
      if (fz > 0 && fz < MIN) el.__mt = 1;
    }
  }
  var queue = [], timer = null;
  function flush() { timer = null; var q = queue; queue = []; q.forEach(fix); }
  var mo = new MutationObserver(function (muts) {
    muts.forEach(function (m) { if (m.type === 'characterData' && m.target.parentNode) { queue.push(m.target.parentNode); return; } Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) queue.push(n); else if (n.nodeType === 3 && n.parentNode) queue.push(n.parentNode); }); });
    if (queue.length && !timer) timer = setTimeout(flush, 30);
  });
  function start() { fix(document.body); mo.observe(document.body, { childList: true, subtree: true, characterData: true }); setInterval(function () { if (narrow()) fix(document.body); }, 400); }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  window.addEventListener('resize', function () { if (narrow()) fix(document.body); });
})();
