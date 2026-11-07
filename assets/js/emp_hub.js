/* Employee hubs: ONE page per group, pages inside as pills (each page opens inside, with no menu of its own).
   window.EMP_HUB = { key: 'space' | 'orders', title, tabs: [[id, label, page, permission|'', needsFlag|'']] } — set by the page before this file loads. */
(function () {
  'use strict';
  const H = window.EMP_HUB; if (!H) return;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // The page's own CSS gives .eh-frame display:block and .eh-load display:flex, which beats the browser's rule for [hidden] —
  // so a "hidden" page stayed on screen and the page opened by a pill appeared below it, out of sight. This puts [hidden] back in charge.
  const hs = document.createElement('style');
  hs.textContent = '.eh-frame[hidden], .eh-load[hidden]{display:none !important;}';
  document.head.appendChild(hs);
  const frames = {}; let VIS = [], cur = null;
  const url = (t) => t[2] + (t[2].indexOf('?') < 0 ? '?' : '&') + (t[2].indexOf('order_allocation') === 0 ? 'embed=1' : 'in=sales');
  const lastKey = 'hrms_hub_' + H.key;
  function pick(id) {
    let want = id || (location.hash || '').replace(/^#/, '');
    if (!want) { try { want = localStorage.getItem(lastKey) || ''; } catch (e) { /* private mode */ } }
    return VIS.find((t) => t[0] === want) || VIS[0];
  }
  function bar() {
    $('ehPills').innerHTML = VIS.map((t) => `<button type="button" class="eh-pill${cur === t ? ' on' : ''}" data-t="${t[0]}" role="tab">${esc(t[1])}</button>`).join('');
  }
  // A page inside a pill can send itself to another page of this hub (the penalty pop-up's "See details", a "Punch In" button, a push tap...).
  // That used to show e.g. Penalties under the Attendance pill. Now the click is caught and the matching pill is opened instead;
  // if a page still wanders off by itself, its pill is switched to match and the wandered frame is dropped (it reloads clean next time).
  const pageOf = (u) => { try { return new URL(u, location.href).pathname.split('/').pop(); } catch (e) { return ''; } };
  const tabOfPage = (p) => VIS.find((x) => pageOf(x[2]) === p);
  function guard(f, t) {
    let w; try { w = f.contentWindow; void w.location.pathname; } catch (e) { return; }
    const here = tabOfPage(pageOf(w.location.href));
    if (here && here !== t) {
      if (frames[t[0]] === f) { delete frames[t[0]]; }
      f.remove();
      if (cur === t) show(here); else bar();
      return;
    }
    try {
      w.document.addEventListener('click', (e) => {
        const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || e.defaultPrevented) return;
        const to = tabOfPage(a.getAttribute('href'));
        if (to && to !== t) { e.preventDefault(); show(to); }
      }, true);
    } catch (e) { /* not reachable — the check above still covers it */ }
  }
  function show(t) {
    if (!t) return; cur = t;
    try { localStorage.setItem(lastKey, t[0]); } catch (e) { /* private mode */ }
    if (location.hash !== '#' + t[0]) history.replaceState(null, '', '#' + t[0]);
    Object.keys(frames).forEach((k) => { frames[k].hidden = k !== t[0]; });
    let f = frames[t[0]];
    if (!f) {
      f = document.createElement('iframe'); f.setAttribute('allow', 'camera; microphone; geolocation; clipboard-read; clipboard-write; fullscreen'); f.className = 'eh-frame'; f.title = t[1];
      f.setAttribute('allow', 'camera; microphone; geolocation');
      f.addEventListener('load', () => { if (frames[t[0]] === f && cur === t) $('ehLoad').hidden = true; guard(f, t); });
      frames[t[0]] = f; $('ehHost').appendChild(f); $('ehLoad').hidden = false; f.src = url(t);
    } else $('ehLoad').hidden = true;
    bar(); document.title = t[1] + ' · ' + H.title + ' — HRMS';
  }
  $('ehPills').addEventListener('click', (e) => { const b = e.target.closest('.eh-pill'); if (b) show(VIS.find((t) => t[0] === b.dataset.t)); });
  window.addEventListener('hashchange', () => { const t = pick(); if (t && t !== cur) show(t); });
  (async function init() {
    let d;
    try { d = await api.get('me.flags'); } catch (e) { $('ehHost').innerHTML = `<div class="eh-empty">Could not load your access (${esc(e.message)}).</div>`; return; }
    const perms = d.permissions || [];
    VIS = H.tabs.filter((t) => (!t[3] || perms.indexOf(t[3]) > -1) && (!t[4] || d[t[4]]));
    if (!VIS.length) { $('ehHost').innerHTML = '<div class="eh-empty">Nothing is open for your login here yet — ask Admin.</div>'; return; }
    show(pick());
  })();
})();