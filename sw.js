/**
 * HRMS service worker — ALWAYS-FRESH + phone notifications. (v260930.4)
 *
 * It stores NOTHING. Its jobs:
 *   - every HRMS page / script / style is fetched straight from the server,
 *     skipping the browser's own HTTP cache — so whatever you upload is what
 *     every device loads, with no version stamps and no hard refresh;
 *   - remove any cache an older worker left behind;
 *   - show the punch-in reminder the server pushes;
 *   - open the punch page when the notification is tapped.
 */
self.addEventListener('install', () => self.skipWaiting());

// Pages, scripts and styles from this site: always from the server, never
// from the browser cache. Anything else (API calls, images, other sites)
// is left alone.
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  let url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) return;
  const fresh = req.mode === 'navigate' || /\.(html|js|css)$/i.test(url.pathname) || url.pathname.endsWith('/');
  if (!fresh) return;
  event.respondWith(
    // A one-off query value makes every request a brand-new URL, so no
    // cache anywhere (browser, hosting CDN, proxy) can answer it.
    fetch(req.url + (req.url.indexOf('?') === -1 ? '?' : '&') + '_fresh=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
      .then((r) => {
        // Hand the page a copy marked "do not keep", so neither the HTTP
        // cache nor the tab's memory cache reuses it on the next page.
        const h = new Headers(r.headers);
        h.set('Cache-Control', 'no-store'); h.delete('Expires'); h.delete('ETag'); h.delete('Last-Modified');
        return new Response(r.body, { status: r.status, statusText: r.statusText, headers: h });
      })
      .catch(() => fetch(req))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    let hadOldCache = false;
    try { const keys = await caches.keys(); hadOldCache = keys.length > 0; await Promise.all(keys.map((k) => caches.delete(k))); } catch (e) {}
    await self.clients.claim();
    // Replacing an old caching worker: reload every open HRMS tab once so
    // it stops showing the cached copies and loads the real files.
    if (hadOldCache) {
      try { const list = await self.clients.matchAll({ type: 'window' }); for (const c of list) { try { if ('navigate' in c) await c.navigate(c.url); } catch (e) {} } } catch (e) {}
    }
  })());
});

self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = { body: event.data ? event.data.text() : '' }; }
  const title = d.title || "You haven't punched in yet";
  const opts = {
    body: d.body || 'Please open the HRMS and punch in.',
    tag: d.tag || 'hrms-punch',
    renotify: true,
    requireInteraction: true,
    vibrate: [200, 100, 200],
    data: { url: d.url || 'attendance.html' },
    // The punch reminder has its Punch In / Later buttons; any other notification can send its own (or none: actions: []).
    actions: Array.isArray(d.actions) ? d.actions : [{ action: 'punch', title: 'Punch In' }, { action: 'later', title: 'Later' }],
  };
  event.waitUntil((async () => {
    let shown = false, err = '';
    try {
      await self.registration.showNotification(title, opts);
      shown = true;
    } catch (e1) {
      // Some phones refuse an option (action buttons, icon...). Show the plain notification instead.
      try { await self.registration.showNotification(title, { body: opts.body, tag: opts.tag, data: opts.data }); shown = true; }
      catch (e2) { err = String((e2 && e2.message) || e2 || e1); }
    }
    // Tell any open HRMS page that this phone got the push (the page's Test button waits for this).
    try {
      const list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const c of list) c.postMessage({ type: 'hrms-push', tid: d.tid || '', shown, error: err });
    } catch (e) {}
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  if (event.action === 'later') return;
  const url = new URL((event.notification.data && event.notification.data.url) || 'attendance.html', self.registration.scope).href;
  event.waitUntil((async () => {
    const list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of list) {
      if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
        try { if ('navigate' in c) await c.navigate(url); } catch (e) {}
        return c.focus();
      }
    }
    return self.clients.openWindow(url);
  })());
});