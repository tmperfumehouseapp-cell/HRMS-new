/* mobile_nav.js — phone navigation for the whole HRMS (layout only: no data, no permissions, no API of its own).
 *  Below 768 px a bottom tab bar (Home · Sales · WhatsApp · Boss · More) and a "More" bottom sheet with EVERY menu section, the user, the theme switch and Log out.
 *  The list is read from the menu the page already drew (.sidebar nav a), so the role permissions and badges are exactly the desktop ones; it is remembered
 *  (localStorage hrms_menu_v1) for pages that have no menu of their own (sales.html). Loaded by layout.js on every page; it does nothing inside a section frame
 *  (the shell page carries the bar), on WhatsApp (own bottom bar) and on desktop.
 */
(function () {
  if (window.__mnav) return; window.__mnav = 1;
  try { if (window.top !== window.self) return; } catch (e) { return; }
  if (/\/(whatsapp|login|index|reset)\.html$/i.test(location.pathname) || /\/$/.test(location.pathname)) return;
  var KEY = 'hrms_menu_v1', mq = window.matchMedia('(max-width:767px)');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };
  var ICON = {
    home: '<svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8M5 10v10h14V10"/></svg>',
    sales: '<svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5h6v2M3 13h18"/></svg>',
    whatsapp: '<svg viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-12 7l-5 1 1.5-4.6A8 8 0 1 1 21 12z"/></svg>',
    boss: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg>',
    more: '<svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>',
    dot: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/></svg>'
  };
  var TABS = [['home', 'Home', /[?&]w=home(&|$)|dashboard\.html/i], ['sales', 'Sales', /sales\.html|[?&]w=sales(&|$)/i], ['whatsapp', 'WhatsApp', /[?&]w=whatsapp(&|$)|whatsapp\.html/i], ['boss', 'Boss', /[?&]w=boss(&|$)/i]];
  var links = [], bar = null, sheet = null, back = null, timer = 0;

  function css() {
    if (document.getElementById('mnavCss')) return;
    var st = document.createElement('style'); st.id = 'mnavCss';
    st.textContent = [
      '#mnav,#mnSheet,#mnBack{display:none}',
      '@media (max-width:767px){',
      ' body.mnav-on{padding-bottom:calc(72px + env(safe-area-inset-bottom)) !important;}',
      ' body.mnav-on.ws-focus #mnav{display:none !important;}',
      ' #mnav{position:fixed;left:0;right:0;bottom:0;z-index:950;display:flex;background:var(--surface,#fff);border-top:1px solid var(--line,#e6e8ec);box-shadow:0 -6px 24px rgba(20,22,26,.10);padding:6px 6px calc(6px + env(safe-area-inset-bottom));font-family:inherit;}',
      ' #mnav .mn-t{flex:1 1 0;min-width:0;min-height:52px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;position:relative;border:0;background:none;color:var(--muted,#6f7480);font:inherit;font-size:11px;font-weight:700;text-decoration:none;border-radius:14px;cursor:pointer;-webkit-tap-highlight-color:transparent;}',
      ' #mnav .mn-t svg{width:23px;height:23px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}',
      ' #mnav .mn-t.on{color:var(--accent,#ff7a2f);background:rgba(255,122,47,.12);}',
      ' #mnav .mn-l{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      ' #mnav .mn-b,#mnSheet .mn-b{background:#e02424;color:#fff;border-radius:9px;font-size:10px;line-height:15px;padding:0 5px;font-style:normal;font-weight:800;}',
      ' #mnav .mn-b{position:absolute;top:3px;left:calc(50% + 6px);}',
      ' #mnBack{position:fixed;inset:0;z-index:960;background:rgba(10,12,16,.45);opacity:0;pointer-events:none;transition:opacity .2s;display:block;}',
      ' #mnBack.on{opacity:1;pointer-events:auto;}',
      ' #mnSheet{position:fixed;left:0;right:0;bottom:0;z-index:970;display:block;background:var(--surface,#fff);color:var(--ink,#14161a);border-radius:22px 22px 0 0;max-height:82vh;overflow:auto;-webkit-overflow-scrolling:touch;padding:8px 16px calc(18px + env(safe-area-inset-bottom));transform:translateY(105%);transition:transform .24s ease;box-shadow:0 -12px 40px rgba(20,22,26,.25);overscroll-behavior:contain;}',
      ' #mnSheet.on{transform:translateY(0);}',
      ' #mnSheet .mn-handle{width:42px;height:5px;border-radius:5px;background:var(--line,#d9dce2);margin:2px auto 10px;}',
      ' #mnSheet .mn-user{display:flex;align-items:center;gap:10px;padding:2px 2px 12px;}',
      ' #mnSheet .mn-av{width:40px;height:40px;border-radius:50%;background:var(--accent,#ff7a2f);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;flex:none;}',
      ' #mnSheet .mn-un{font-weight:800;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;} #mnSheet .mn-ur{font-size:12px;color:var(--muted,#6f7480);}',
      ' #mnSheet .mn-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;}',
      ' #mnSheet .mn-i{position:relative;min-height:84px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:10px 4px;border:1px solid var(--line,#e6e8ec);border-radius:16px;background:var(--surface,#fff);color:inherit;text-decoration:none;font-size:12.5px;font-weight:700;text-align:center;line-height:1.2;}',
      ' #mnSheet .mn-i.on{border-color:var(--accent,#ff7a2f);background:rgba(255,122,47,.10);}',
      ' #mnSheet .mn-i svg{width:24px;height:24px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}',
      ' #mnSheet .mn-i span.t{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      ' #mnSheet .mn-i .mn-b{position:absolute;top:6px;right:8px;}',
      ' #mnSheet .mn-act{display:flex;gap:8px;margin-top:14px;}',
      ' #mnSheet .mn-act button{flex:1;min-height:48px;border-radius:14px;border:1px solid var(--line,#e6e8ec);background:var(--surface,#fff);color:inherit;font:inherit;font-size:14px;font-weight:800;cursor:pointer;}',
      ' #mnSheet .mn-act .out{background:#e02424;border-color:#e02424;color:#fff;}',
      ' body.mnav-on #menuToggle{display:none !important;}',
      ' body.mnav-on #hrmsDkBtn{display:none !important;}',      /* the moon button floated over the header tools (Pace, bell): the theme switch is in the More sheet now */
      ' body.mnav-on .topbar{padding-left:2px !important;}',      /* the 58 px reserved for the hidden menu button is not needed any more */
      ' body.mnav-on .tmph-fab{right:14px !important;bottom:calc(76px + env(safe-area-inset-bottom)) !important;width:48px !important;height:48px !important;padding:0 !important;gap:0 !important;justify-content:center !important;font-size:0 !important;transition:transform .2s,opacity .2s !important;}',
      ' body.mnav-on.mn-down .tmph-fab{transform:translateY(120px) !important;opacity:0 !important;pointer-events:none;}',
      ' body.mnav-on .toast,body.mnav-on #toast,body.mnav-on .bd-toast,body.mnav-on .ws-toast{bottom:calc(84px + env(safe-area-inset-bottom)) !important;}',
      '}',
      '/* dark mode needs nothing here: the page is inverted as a whole by the existing dark mode, so the light bar and sheet turn dark with it */'
    ].join('\n');
    document.head.appendChild(st);
  }

  /* what the page's own menu holds: the same links, the same permissions, the same badges */
  function collect() {
    var out = [], as = document.querySelectorAll('.sidebar nav a[href]');
    for (var i = 0; i < as.length; i++) {
      var a = as[i], href = a.getAttribute('href') || '';
      if (!href || href === '#' || /^javascript:/i.test(href)) continue;
      var lbl = a.querySelector('.lbl') || a, c = lbl.cloneNode(true), b = '';
      var bd = c.querySelectorAll('.ws-badge,#ctBadge,#approvalBadge,#leaveBadge,#stockBadge,#chatBadge,[id$="Badge"]');
      for (var k = 0; k < bd.length; k++) { var o = a.querySelector('#' + (bd[k].id || '_x')) || bd[k]; if (!b && o && o.textContent.trim() && o.style.display !== 'none') b = o.textContent.trim(); bd[k].remove(); }
      var ab = a.querySelector('.ws-badge,#ctBadge,#approvalBadge,#leaveBadge,#stockBadge,#chatBadge'); if (!b && ab && ab.style.display !== 'none' && ab.textContent.trim()) b = ab.textContent.trim();
      var ico = a.querySelector('.ico');
      out.push({ href: href, label: c.textContent.replace(/\s+/g, ' ').trim(), ico: ico ? ico.innerHTML : '', badge: b, on: a.classList.contains('active') });
    }
    return out;
  }
  function load() {
    var cur = collect();
    if (cur.length) { links = cur; try { localStorage.setItem(KEY, JSON.stringify(cur)); } catch (e) { } return; }
    try { var c = JSON.parse(localStorage.getItem(KEY) || '[]'); if (c && c.length) links = c; } catch (e) { }
  }
  function isOn(l, key) {
    if (l.on && !key) return true;
    var here = location.pathname.split('/').pop() + location.search;
    return !!(l.href && (here === l.href || (key && here.indexOf(l.href) === 0)));
  }

  function draw() {
    if (!mq.matches) { if (bar) { bar.remove(); bar = null; } if (sheet) { sheet.remove(); sheet = null; } if (back) { back.remove(); back = null; } document.body.classList.remove('mnav-on'); return; }
    css(); load();
    var tabs = '', used = {};
    TABS.forEach(function (t) {
      var l = null; for (var i = 0; i < links.length; i++) if (t[2].test(links[i].href)) { l = links[i]; break; }
      if (!l) return; used[l.href] = 1;
      var on = l.on || (t[2].test(location.pathname.split('/').pop() + location.search));
      tabs += '<a class="mn-t' + (on ? ' on' : '') + '" href="' + esc(l.href) + '">' + (l.ico || ICON[t[0]]) + '<span class="mn-l">' + t[1] + '</span>' + (l.badge ? '<i class="mn-b">' + esc(l.badge) + '</i>' : '') + '</a>';
    });
    var moreBadge = ''; links.forEach(function (l) { if (!used[l.href] && l.badge && !moreBadge) moreBadge = '●'; });
    tabs += '<button type="button" class="mn-t" id="mnMore" aria-haspopup="dialog">' + ICON.more + '<span class="mn-l">More</span>' + (moreBadge ? '<i class="mn-b" style="min-width:8px;height:8px;padding:0;border-radius:50%;left:calc(50% + 8px)"></i>' : '') + '</button>';
    if (!bar) { bar = document.createElement('nav'); bar.id = 'mnav'; bar.setAttribute('aria-label', 'Main menu'); document.body.appendChild(bar); }
    bar.innerHTML = tabs; document.body.classList.add('mnav-on');
    document.getElementById('mnMore').onclick = openSheet;
    if (sheet && sheet.classList.contains('on')) fillSheet();
  }

  function who() { try { return (window.api && api.currentUser && api.currentUser()) || {}; } catch (e) { return {}; } }
  function fillSheet() {
    var u = who(), nm = String(u.full_name || u.name || u.username || 'You'), ini = nm.replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase() || 'U', dark = document.documentElement.classList.contains('hrms-dk');
    sheet.innerHTML = '<div class="mn-handle" aria-hidden="true"></div><div class="mn-user"><div class="mn-av">' + esc(ini) + '</div><div style="min-width:0"><div class="mn-un">' + esc(nm) + '</div><div class="mn-ur">' + esc(u.role || '') + '</div></div></div>'
      + '<div class="mn-grid">' + links.map(function (l) { return '<a class="mn-i' + (isOn(l) ? ' on' : '') + '" href="' + esc(l.href) + '">' + (l.ico || ICON.dot) + '<span class="t">' + esc(l.label) + '</span>' + (l.badge ? '<i class="mn-b">' + esc(l.badge) + '</i>' : '') + '</a>'; }).join('') + '</div>'
      + '<div class="mn-act"><button type="button" id="mnTheme">' + (dark ? '☀ Light mode' : '🌙 Dark mode') + '</button><button type="button" class="out" id="mnOut">Log out</button></div>';
    document.getElementById('mnTheme').onclick = function () { var v = document.documentElement.classList.toggle('hrms-dk'); try { localStorage.setItem('hrms_dark', v ? '1' : '0'); } catch (e) { } this.textContent = v ? '☀ Light mode' : '🌙 Dark mode'; };
    document.getElementById('mnOut').onclick = function () { if (typeof logout === 'function') logout(); };
  }
  function openSheet() {
    if (!sheet) {
      back = document.createElement('div'); back.id = 'mnBack'; back.onclick = closeSheet; document.body.appendChild(back);
      sheet = document.createElement('div'); sheet.id = 'mnSheet'; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-label', 'All sections'); document.body.appendChild(sheet);
      var y0 = null, dy = 0;
      sheet.addEventListener('touchstart', function (e) { if (sheet.scrollTop <= 0 && e.touches.length === 1) { y0 = e.touches[0].clientY; dy = 0; sheet.style.transition = 'none'; } }, { passive: true });
      sheet.addEventListener('touchmove', function (e) { if (y0 === null) return; dy = e.touches[0].clientY - y0; if (dy > 0) sheet.style.transform = 'translateY(' + dy + 'px)'; }, { passive: true });
      sheet.addEventListener('touchend', function () { if (y0 === null) return; sheet.style.transition = ''; sheet.style.transform = ''; if (dy > 90) closeSheet(); y0 = null; });
    }
    load(); fillSheet();
    requestAnimationFrame(function () { sheet.classList.add('on'); back.classList.add('on'); });
    document.body.style.overflow = 'hidden';
  }
  function closeSheet() { if (!sheet) return; sheet.classList.remove('on'); if (back) back.classList.remove('on'); document.body.style.overflow = ''; }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });

  /* rows of tabs / chips that scroll sideways: a fade shows there is more, and the chosen tab is brought into view */
  function rows() {
    if (!mq.matches) return;
    var els = document.querySelectorAll('.tabs,.tabbar,.ws-tabs,#wsTabs,.chips,.ws-needs');
    for (var i = 0; i < els.length; i++) {
      var e = els[i]; if (!e.__mnr) { e.__mnr = 1; e.addEventListener('scroll', (function (x) { return function () { x.classList.toggle('mn-end', x.scrollLeft + x.clientWidth >= x.scrollWidth - 4); }; })(e), { passive: true }); }
      var more = e.scrollWidth > e.clientWidth + 6; e.classList.toggle('mn-more', more); e.classList.toggle('mn-end', !more || e.scrollLeft + e.clientWidth >= e.scrollWidth - 4);
      var on = e.querySelector('.on,.active,[aria-selected="true"]'); if (more && on && !e.__mnd) { e.__mnd = 1; try { var cr = e.getBoundingClientRect(), orr = on.getBoundingClientRect(); if (orr.right > cr.right - 8) e.scrollLeft += orr.right - cr.right + 24; else if (orr.left < cr.left + 8) e.scrollLeft -= cr.left - orr.left + 24; } catch (x) { } }      /* only when the chosen tab is not fully in view, and only by the needed amount */
    }
  }
  setInterval(rows, 1200); window.addEventListener('load', rows);

  /* the assistant button hides while scrolling down, shows when scrolling up */
  var lastY = window.pageYOffset || 0;
  window.addEventListener('scroll', function () { var y = window.pageYOffset || 0; if (Math.abs(y - lastY) < 8) return; document.body.classList.toggle('mn-down', y > lastY && y > 120); lastY = y; }, { passive: true });

  function soon() { clearTimeout(timer); timer = setTimeout(draw, 250); }
  function start() {
    draw();
    var sb = document.getElementById('sidebar');      // the menu is drawn (and its badges fill in) after the page loads: redraw the bar then; pages without a menu use the remembered list
    if (sb) try { new MutationObserver(soon).observe(sb, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'style'] }); } catch (e) { }
    (mq.addEventListener ? mq.addEventListener('change', draw) : mq.addListener(draw));
    window.addEventListener('orientationchange', soon);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
