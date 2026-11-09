/**
 * Injects the sidebar shell into any page with a <div id="sidebar"></div>.
 * Call renderSidebar('dashboard' | 'departments' | 'employees' | 'documents'
 *                     | 'history' | 'approvals' | 'users' | 'ex_employees') after guardPage().
 */
const LAYOUT_BUILD = '261102.2';   // shown under your name in the sidebar

// Opening splash (TM·PH comet chain + name). Loaded on every page; assets/js/splash.js decides whether to
// play (new tab / app launch, or back after 10+ min away). Add ?splash=1 to any page address to force it.
(function () {
  try {
    if (window.__hrmsSplashInit) return;
    var cs = document.currentScript;
    var src = (cs && cs.src) ? cs.src.replace(/layout\.js.*$/, 'splash.js?v=4') : 'assets/js/splash.js?v=4';
    var s = document.createElement('script');
    s.src = src;
    (document.head || document.documentElement).appendChild(s);
  } catch (e) { /* splash is cosmetic */ }
})();

// TMPH, the assistant button at the bottom right: loaded on every logged-in page (not inside a hub iframe: the hub page itself carries it). tmph.js asks the server (ai.status)
// and only shows the button to the people allowed to use it (Admin by default), so loading it for everyone is safe.
(function () {
  try {
    if (window.top !== window.self || window.__tmphLoad) return;
    window.__tmphLoad = 1;
    var cs = document.currentScript;
    var src = (cs && cs.src) ? cs.src.replace(/layout\.js.*$/, 'tmph.js?v=261105b') : 'assets/js/tmph.js?v=261105b';
    var s = document.createElement('script'); s.src = src; s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  } catch (e) { /* the assistant is optional */ }
})();

// Voice: a start / stop microphone next to every Claude box (Boss chat, Claude questions, Brain teach boxes). Loaded in hub frames too.
(function () {
  try {
    if (window.__voiceLoad) return; window.__voiceLoad = 1;
    var cs = document.currentScript;
    var src = (cs && cs.src) ? cs.src.replace(/layout\.js.*$/, 'voice.js?v=1') : 'assets/js/voice.js?v=1';
    var s = document.createElement('script'); s.src = src; s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  } catch (e) { /* voice is optional */ }
})();

// One look for every panel: shared theme (cards, tables, buttons, inputs), font, and a per-role accent colour (data-role on <html>).
(function () {
  try {
    const h = document.documentElement;
    if (h.classList.contains('no-theme')) return;
    let role = '';
    try { role = String((JSON.parse(localStorage.getItem('hrms_user') || '{}') || {}).role || '').toLowerCase().replace(/[^a-z]/g, ''); } catch (e) {}
    if (role) h.setAttribute('data-role', role);
    h.classList.add('tm');
    if (!document.getElementById('tmFont')) {
      const pc = document.createElement('link'); pc.rel = 'preconnect'; pc.href = 'https://fonts.gstatic.com'; pc.crossOrigin = ''; document.head.appendChild(pc);
      const f = document.createElement('link'); f.id = 'tmFont'; f.rel = 'stylesheet'; f.href = 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap'; document.head.appendChild(f);
    }
    if (!window.TMWin && !document.getElementById('tmWinJs')) { const w = document.createElement('script'); w.id = 'tmWinJs'; w.src = 'assets/js/win.js?v=1'; w.defer = true; (document.head || document.documentElement).appendChild(w); }
    if (!document.getElementById('sxTheme')) {
      const t = document.createElement('link'); t.id = 'sxTheme'; t.rel = 'stylesheet'; t.href = 'assets/css/sales_theme.css?v=4'; document.head.appendChild(t);
    }
  } catch (e) { /* the page keeps its own look */ }
})();

// 🌙 Dark mode for the whole HRMS (button at the top right of every page; the choice is remembered per browser in localStorage 'hrms_dark').
// The page is turned dark in one go (colours inverted, photos / videos / charts' canvases turned back so they keep their real colours). WhatsApp pages have their own night mode.
(function () {
  try {
    if (/whatsapp|\/wa_|wa_chats|wa_one|my_chats/i.test(location.pathname + location.search)) return;
    var de = document.documentElement, on = false;
    try { on = localStorage.getItem('hrms_dark') === '1'; } catch (e) { }
    if (on) de.classList.add('hrms-dk');
    if (!document.getElementById('hrmsDkCss')) { var st = document.createElement('style'); st.id = 'hrmsDkCss';
      st.textContent = 'html.hrms-dk{filter:invert(.92) hue-rotate(180deg);background:#111;}html.hrms-dk img,html.hrms-dk video,html.hrms-dk canvas,html.hrms-dk iframe,html.hrms-dk [style*="background-image"]{filter:invert(1) hue-rotate(180deg);}'
        + '#hrmsDkBtn{position:fixed;top:10px;right:14px;z-index:2147483000;width:38px;height:38px;border-radius:50%;border:1px solid rgba(0,0,0,.12);background:#fff;color:#14161a;font-size:18px;line-height:1;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.12);padding:0;display:flex;align-items:center;justify-content:center}'
        + '@media (max-width:820px){#hrmsDkBtn{top:9px;right:62px;width:34px;height:34px;font-size:16px}}@media print{#hrmsDkBtn{display:none}}';
      (document.head || de).appendChild(st); }
    window.addEventListener('storage', function (e) { if (e.key === 'hrms_dark') de.classList.toggle('hrms-dk', e.newValue === '1'); });
    if (window.self !== window.top) return;      // the button lives in the top page only; frames follow the saved choice
    var add = function () {
      if (document.getElementById('hrmsDkBtn') || !document.body) return;
      var b = document.createElement('button'); b.type = 'button'; b.id = 'hrmsDkBtn'; b.title = 'Dark mode'; b.setAttribute('aria-label', 'Dark mode');
      var draw = function () { b.textContent = de.classList.contains('hrms-dk') ? '☀️' : '🌙'; }; draw();
      b.onclick = function () { var v = de.classList.toggle('hrms-dk'); try { localStorage.setItem('hrms_dark', v ? '1' : '0'); } catch (e) { } draw(); };
      document.body.appendChild(b);
    };
    if (document.body) add(); else document.addEventListener('DOMContentLoaded', add);
  } catch (e) { }
})();

// Smaller on phones: the page is laid out at 90% size (one viewport setting, so every screen shrinks together). WhatsApp pages and frames are left alone.
(function () {
  try {
    if (/whatsapp|\/wa_|wa_chats|wa_one|my_chats/i.test(location.pathname + location.search)) { document.documentElement.classList.add('wa-skip'); return; }   // WhatsApp: not touched (no "ph" class, so phone.css never applies)
    var mqp = window.matchMedia('(max-width:820px)'), setPh = function () { document.documentElement.classList.toggle('ph', mqp.matches); };
    setPh(); if (mqp.addEventListener) mqp.addEventListener('change', setPh);                  // html.ph = the phone layout (phone.css)
    if (window.self !== window.top) return;                                  // a frame follows its top page
    if (!window.matchMedia('(max-width:820px)').matches) return;
    var m = document.querySelector('meta[name="viewport"]'); if (!m) return;
    var c = m.getAttribute('content') || 'width=device-width';
    if (/initial-scale=0\.9/.test(c)) return;
    c = c.replace(/,?\s*(initial-scale|minimum-scale|maximum-scale|user-scalable)=[^,]*/g, '');
    m.setAttribute('content', c + ', initial-scale=0.9, minimum-scale=0.5');
    document.documentElement.classList.add('tm-compact');
  } catch (e) { /* the page keeps its normal size */ }
})();

// Compact phone layout: one shared stylesheet for every page, and every table gets its own sideways-scroll box on small screens.
(function () {
  try {
    const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'assets/css/mobile.css?v=9';
    document.head.appendChild(l);
    const ph = document.createElement('link'); ph.rel = 'stylesheet'; ph.href = 'assets/css/phone.css?v=11'; document.head.appendChild(ph);   // the one compact phone style (html.ph)
    const mq = window.matchMedia('(max-width:820px)');
    const wrap = () => {
      if (!mq.matches) return;
      document.querySelectorAll('table').forEach((t) => {
        const p = t.parentElement;
        if (!p || t.dataset.mobWrapped || p.classList.contains('mob-scroll') || p.classList.contains('tablewrap') || p.classList.contains('table-wrap')) return;
        if (getComputedStyle(p).overflowX === 'auto' || getComputedStyle(p).overflowX === 'scroll') return;
        t.dataset.mobWrapped = '1';
        const w = document.createElement('div'); w.className = 'mob-scroll'; p.insertBefore(w, t); w.appendChild(t);
      });
    };
    let busy = false;
    const run = () => { if (busy) return; busy = true; setTimeout(() => { busy = false; try { wrap(); } catch (e) {} }, 250); };
    document.addEventListener('DOMContentLoaded', () => { run(); new MutationObserver(run).observe(document.body, { childList: true, subtree: true }); });
  } catch (e) { /* cosmetic */ }
})();

// Site icon: the TMPH logo as the browser-tab icon and the home-screen icon, on every page that loads this file.
(function () {
  try {
    document.querySelectorAll('link[rel~="icon"], link[rel="shortcut icon"], link[rel="apple-touch-icon"]').forEach((l) => l.remove());
    const add = (rel, href, attrs) => {
      const l = document.createElement('link');
      l.rel = rel; l.href = href;
      if (attrs) Object.keys(attrs).forEach((k) => l.setAttribute(k, attrs[k]));
      document.head.appendChild(l);
    };
    add('icon', 'icons/favicon-32.png', { type: 'image/png', sizes: '32x32' });
    add('icon', 'icons/favicon-16.png', { type: 'image/png', sizes: '16x16' });
    add('shortcut icon', 'icons/favicon.ico');
    add('apple-touch-icon', 'icons/apple-touch-icon.png');
  } catch (e) { /* icons are cosmetic */ }
})();


/**
 * Punch-in reminder pop-up (employee logins, every page).
 *
 * About once a minute the page asks the server whether a "you haven't
 * punched in" reminder is due (at the office N minutes without punching in,
 * or N minutes after the day started). When it is, a pop-up appears on top
 * of whatever page is open, with a Punch In button. If the employee allowed
 * notifications, the phone also shows one while the app is in the
 * background. When the HRMS is closed, the server pushes the same reminder
 * to the phone (see sw.js) — that only needs the one-time "Allow
 * notifications" tap. Nothing is sent by WhatsApp / SMS; no keys involved.
 */
async function hrmsPushSubscribe(interactive) {
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window) || !window.isSecureContext) return false;
  const reg = await navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' });
  await navigator.serviceWorker.ready;
  if (Notification.permission !== 'granted') {
    if (!interactive) return false;
    if ((await Notification.requestPermission()) !== 'granted') return false;
  }
  const k = await api.get('push.key');
  const toKey = (b64) => { const pad = '='.repeat((4 - b64.length % 4) % 4); const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')); const out = new Uint8Array(raw.length); for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i); return out; };
  let sub = await reg.pushManager.getSubscription();
  if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(k.key) });
  const j = sub.toJSON();
  await api.post('push.subscribe', { endpoint: j.endpoint, keys: j.keys, device: navigator.userAgent.slice(0, 160) });
  try { localStorage.setItem('hrms_push_day', new Date().toDateString()); } catch (e) {}
  return true;
}

function startPunchReminder() {
  if (window.__punchReminderOn) return;
  window.__punchReminderOn = true;
  const onPunchPage = /(^|\/)(attendance|my_day)\.html$/.test(location.pathname);   // My Day is where punching starts: the steps first, then the punch
  let box = null;
  // Phone already allowed notifications → keep its subscription fresh (once a day, silently).
  try {
    if ('Notification' in window && Notification.permission === 'granted' && localStorage.getItem('hrms_push_day') !== new Date().toDateString()) hrmsPushSubscribe(false).catch(() => {});
  } catch (e) {}
  function show(d) {
    if (!box) {
      box = document.createElement('div');
      box.id = 'punchReminder';
      box.style.cssText = 'position:fixed; inset:0; background:rgba(17,24,39,.55); z-index:9999; display:flex; align-items:center; justify-content:center; padding:20px;';
      document.body.appendChild(box);
    }
    const since = d.since ? new Date(String(d.since).replace(' ', 'T')).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '';
    const canAsk = ('Notification' in window) && Notification.permission === 'default';
    box.innerHTML = `<div style="background:#fff; border-radius:16px; max-width:420px; width:100%; padding:22px 20px; box-shadow:0 24px 60px rgba(0,0,0,.35); font-family:inherit;">
        <div style="font-size:34px; line-height:1; margin-bottom:8px;">${d.seq >= d.max ? '🚨' : '⏰'}</div>
        <div style="font-size:17px; font-weight:700; color:#111827; margin-bottom:6px;">${d.title || "You haven't punched in yet"}</div>
        <div style="font-size:13.5px; color:#374151; line-height:1.5;">${d.message || ''}</div>
        <div style="font-size:11.5px; color:#6b7280; margin-top:8px;">Reminder ${d.seq} of ${d.max}${since ? ' · since ' + since : ''}</div>
        <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap;">
          ${onPunchPage ? '<button type="button" class="btn" id="prPunch" style="flex:1; min-height:44px;">OK, punching in</button>' : '<a class="btn" href="my_day.html" style="flex:1; min-height:44px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;">Punch In</a>'}
          <button type="button" class="btn secondary" id="prLater" style="flex:1; min-height:44px;">Later</button>
        </div>
        ${canAsk ? '<button type="button" id="prAllow" style="margin-top:12px; background:none; border:none; color:#2563eb; font-size:12.5px; cursor:pointer; padding:0; min-height:0; text-decoration:underline;">🔔 Also remind me on this phone when HRMS is closed</button>' : ''}
      </div>`;
    box.style.display = 'flex';
    const close = () => { box.style.display = 'none'; };
    const later = document.getElementById('prLater'); if (later) later.onclick = close;
    const punch = document.getElementById('prPunch'); if (punch) punch.onclick = close;
    const allow = document.getElementById('prAllow'); if (allow) allow.onclick = () => { allow.disabled = true; hrmsPushSubscribe(true).then(() => { allow.remove(); }).catch(() => { allow.remove(); }); };
    try { if (navigator.vibrate) navigator.vibrate([200, 100, 200]); } catch (e) {}
    // Phone notification too, when allowed and the app is not in front.
    try {
      if ('Notification' in window && Notification.permission === 'granted' && document.visibilityState !== 'visible') {
        const n = new Notification(d.title || "You haven't punched in yet", { body: d.message || '', tag: 'hrms-punch', renotify: true });
        n.onclick = () => { window.focus(); if (!onPunchPage) location.href = 'my_day.html'; };
      }
    } catch (e) {}
  }
  async function poll() {
    if (document.hidden) return;   // a page nobody is looking at does not ask the database (it asks again when it becomes visible)
    try {
      const d = await api.get('me.reminder_status');
      if (d && d.show) show(d);
      else if (d && d.punched_in && box) box.style.display = 'none';
    } catch (e) {}
  }
  poll();
  setInterval(() => { if (!document.hidden) poll(); }, 60000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
}

// A penalty (or its removal / waiver) the employee has not seen yet: shown as a pop-up on whatever page they have open.
// The same text also goes to their phone as a notification. "OK" marks them seen.
function startPenaltyNotice() {
  if (window.__penaltyNoticeOn) return;
  window.__penaltyNoticeOn = true;
  let box = null;
  const esc = (t) => String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  function show(list) {
    if (!box) {
      box = document.createElement('div');
      box.id = 'penaltyNotice';
      box.style.cssText = 'position:fixed; inset:0; background:rgba(17,24,39,.55); z-index:9998; display:flex; align-items:center; justify-content:center; padding:20px;';
      document.body.appendChild(box);
    }
    const items = list.map((n) => `<div style="padding:10px 12px; border-radius:10px; margin-top:8px; border-left:4px solid ${n.kind === 'applied' ? '#dc2626' : '#16a34a'}; background:${n.kind === 'applied' ? '#fef2f2' : '#f0fdf4'};">
        <div style="font-size:13px; font-weight:700; color:#111827;">${esc(n.title)}</div>
        <div style="font-size:13px; color:#374151; line-height:1.45; margin-top:2px;">${esc(n.body)}</div></div>`).join('');
    box.innerHTML = `<div style="background:#fff; border-radius:16px; max-width:420px; width:100%; max-height:85vh; overflow:auto; padding:20px; box-shadow:0 24px 60px rgba(0,0,0,.35); font-family:inherit;">
        <div style="font-size:17px; font-weight:700; color:#111827;">Penalty update</div>
        ${items}
        <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap;">
          <a class="btn secondary" href="my_penalties.html" style="flex:1; min-height:44px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;">See details</a>
          <button type="button" class="btn" id="pnOk" style="flex:1; min-height:44px;">OK</button>
        </div></div>`;
    box.style.display = 'flex';
    document.getElementById('pnOk').onclick = async () => {
      box.style.display = 'none';
      try { await api.post('my_penalties.read', {}); } catch (e) {}
    };
    try { if (navigator.vibrate) navigator.vibrate([200, 100, 200]); } catch (e) {}
  }
  async function poll() {
    if (document.hidden) return;
    if (box && box.style.display !== 'none') return;
    try {
      const t = new Date();
      const d = await api.get('my_penalties.list', { month: t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') });
      if (d && d.unread && d.unread.length) show(d.unread.slice().reverse());
    } catch (e) {}
  }
  poll();
  setInterval(() => { if (!document.hidden) poll(); }, 90000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
}

// "My Chats" menu badge: how many customer messages are waiting for this salesperson (needs.counts). Quiet when WhatsApp is not set up yet.
function startChatBadge() {
  if (window.__chatBadgeOn) return;
  window.__chatBadgeOn = true;
  const tick = async () => {
    if (document.hidden) return;
    try {
      const d = await api.get('needs.counts'), n = Number(d && d.wa_mine) || 0, b = document.getElementById('chatBadge');
      if (b) { b.textContent = n > 99 ? '99+' : String(n); b.style.display = n > 0 ? 'inline-block' : 'none'; }
      const em = document.querySelector('#wsEmpNav a[href="my_chats.html"] em'), a = document.querySelector('#wsEmpNav a[href="my_chats.html"]');
      if (a && !em && n > 0) a.insertAdjacentHTML('beforeend', '<em>' + (n > 99 ? '99+' : n) + '</em>'); else if (em) { em.textContent = n > 99 ? '99+' : String(n); em.style.display = n > 0 ? '' : 'none'; }
    } catch (e) { /* the badge is optional */ }
  };
  tick(); setInterval(() => { if (!document.hidden) tick(); }, 45000);
}

/*BD-START bossMenu: adds the Boss Engine links to a menu. Managers see Board, Inbox, Duties and Setup; staff with duties see My Day. */
function bossMenu(active, nav) {
  if (!nav || typeof api === 'undefined') return;
  api.get('bd.badge').then((d) => {
    if (!d || !d.show) return;
    if (!d.manager && !d.has_tasks) return;
    const add = (href, label, key, badge) => {
      if (nav.querySelector('a[href="' + href + '"]')) return;
      const a = document.createElement('a'); a.href = href; a.innerHTML = label + (badge ? ' <span style="background:#e02424;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:6px;">' + badge + '</span>' : '');
      if (active === key) a.className = 'active';
      nav.appendChild(a);
    };
    const lab = document.createElement('div'); lab.className = 'nav-label'; lab.textContent = 'Boss Engine'; nav.appendChild(lab);
    if (d.has_tasks) add('my_day.html', 'My Day', 'my_day', d.my_open);
    if (d.manager) { add('boss_engine.html', 'Boss Engine', 'boss_engine', (Number(d.be) || 0) + (Number(d.inbox) || 0)); }
  }).catch(() => { /* the Boss Engine links are optional */ });
}/*BD-END*/

/*WS-START workspaces v2: the menu is 8 icons, each opening hub.html with the pages of that area as tabs */
/* Workspaces v2: the whole HRMS menu is 9 icons (Home, Sales, WhatsApp, Boss, Store, Finance, People, Offices, Admin). Each icon opens ONE page (hub.html) with the pages of that area as tabs.
   This block is pasted into assets/js/layout.js by ws_install.php (between the WS-START and WS-END marks) - it is not loaded as a separate file. */
window.WS = (function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // tab = [key, label, page, need]   need: '' = anyone whose role allows the page | admin | boss | fl | flm | emp
  const W = [
    { key: 'home', label: 'Home', icon: 'dashboard', sub: 'What needs you today', tabs: [['dashboard', 'Dashboard', 'dashboard.html', '']] },
    { key: 'sales', label: 'Sales', icon: 'sales', direct: 'sales.html', sub: '', tabs: [] },
    { key: 'budget', label: 'Budgeting', icon: 'commission', sub: 'Commission, leaders, salaries, fixed expenses and penalties, day by day', tabs: [['bt_total', 'Total Budgeting', 'total_budgeting.html', ''], ['bt_commission', 'Commission', 'commission_new.html', ''], ['bt_leaders', 'Leaders', 'leaders.html', ''], ['bt_helpers', 'Leaders Helper', 'leaders_helper.html', '']] },   // own menu item; the same pages are no longer listed inside Sales
    { key: 'whatsapp', label: 'WhatsApp', icon: 'whatsapp', sub: 'Chats, campaigns and groups', tabs: [['whatsapp', 'WhatsApp', 'whatsapp.html', 'wa'], ['whatsapp_groups', 'Groups', 'whatsapp_groups.html', '']] },
    { key: 'wasales', label: 'WhatsApp Sales', icon: 'wasales', sub: 'Every customer query: who holds it, who is behind, what works', tabs: [['wasales', 'WhatsApp Sales', 'wa_sales_live.html', 'wa']] },
    { key: 'complaints', label: 'Complaints', icon: 'complaints', sub: 'Every customer problem is a ticket — Tracking and Complaint desks', tabs: [['complaints', 'Tickets', 'complaints.html', 'cx'], ['my_complaints', 'My complaints', 'my_complaints.html', 'cxm']] },
    { key: 'inbox', label: 'Inbox', icon: 'inbox', sub: 'Instagram, Facebook, email and reviews — answered in one place', tabs: [['social_inbox', 'Inbox hub', 'social_inbox.html', 'sx']] },
    { key: 'boss', label: 'Boss', icon: 'approvals', sub: 'Tasks, numbers, problems and penalties (people, pay and commission live in Budgeting)', tabs: [['boss_engine', 'Today', 'boss_engine.html', 'boss', 'today'], ['be_sections', 'Sections', 'boss_engine.html', 'boss', 'sections'], ['be_work', 'All work', 'boss_engine.html', 'boss', 'work'], ['staff_progress', 'Staff progress', 'staff_progress.html', 'cxr'], ['be_pens', 'Penalties', 'boss_engine.html', 'boss', 'pens'], ['be_problems', 'Problems', 'boss_engine.html', 'boss', 'problems'], ['boss_questions', 'Questions', 'boss_questions.html', 'boss'], ['dept_hub', 'Workbenches', 'dept_hub.html', 'boss'], ['daily_summary', 'Summary', 'daily_summary.html', 'boss'], ['tracker', 'Daily tracker', 'tracker.html', 'boss'], ['brain', 'Brain', 'brain.html', 'boss'], ['be_setup', 'Setup & health', 'boss_engine.html', 'boss', 'setup'], ['boss_rules', 'Rules in sync', 'boss_rules.html', 'admin']] },
    { key: 'dispatch', label: 'Dispatch', icon: 'dispatch', sub: 'Parcels, tracking numbers and Shiprocket', tabs: [['dept_dispatch', 'Parcels and tracking', 'dept_dispatch.html', 'boss'], ['customers', 'Customers', 'customers.html', 'boss'], ['dept_restock', 'Store orders', 'dept_restock.html', 'rsd'], ['dispatch_logins', 'Dispatch logins', 'dispatch_logins.html', 'admin']] },
    { key: 'store', label: 'Store', icon: 'retail_store', sub: 'Everything about the Surat store', tabs: [['store_dashboard', 'Dashboard', 'store_dashboard.html', 'rsm'], ['store_billing', 'Billing', 'store_billing.html', 'rs'], ['store_messages', 'Messages', 'store_messages.html', 'rs'], ['store_team', 'Team', 'store_team.html', 'rsm'], ['store_commission', 'Store Commission', 'store_commission.html', 'rsm'], ['store_inventory', 'Inventory', 'store_inventory.html', 'rs'], ['store_cleaning', 'Cleaning & Pantry', 'store_cleaning.html', 'boss'], ['store_contacts', 'Customer follow-up', 'store_contacts.html', 'rsm'], ['flyers', 'Flyers', 'flyers.html', 'fl'], ['flyer_map', 'Flyer Map', 'flyer_map.html', 'flm'], ['store_logins', 'Store logins', 'store_logins.html', 'admin']] },
    { key: 'inventory', label: 'Inventory', icon: 'inventory', sub: 'Oils, perfumes, orders and dealers: what you have, what you need, what will be late', tabs: [['iv_dash', 'Dashboard', 'inventory.html', '', 'dashboard'], ['iv_oils', 'Oil stock', 'inventory.html', '', 'oils'], ['iv_perf', 'Perfumes', 'inventory.html', '', 'perfumes'], ['iv_calc', 'Oil calculator', 'inventory.html', '', 'calc'], ['iv_orders', 'Orders', 'inventory.html', '', 'orders'], ['iv_sup', 'Dealers', 'inventory.html', '', 'dealers'], ['iv_alerts', 'Alerts', 'inventory.html', '', 'alerts'], ['iv_rep', 'Reports', 'inventory.html', '', 'reports']] },
    { key: 'finance', label: 'Finance', icon: 'finance', sub: 'Money in, money out, bank checks', tabs: [['finance_sum', 'Summary', 'finance_sum.html', ''], ['finance_in', 'Incoming', 'finance_in.html', ''], ['finance_parcels', 'Refunds & closed', 'finance_parcels.html', '']] },
    { key: 'analytics', label: 'Analytics', icon: 'analytics', sub: 'Store analytics report', tabs: [['analytics', 'Analytics', 'analytics.html', 'an']] },
    { key: 'people', label: 'People', icon: 'employees', hidden: true, sub: 'Old People pages (opened from Boss → People)', tabs: [['employees', 'Employees', 'employees.html', ''], ['people_admin', 'Admin team', 'people_admin.html', 'admin'], ['attendance_admin', 'Attendance', 'attendance_admin.html', ''], ['my_day', 'My Day', 'my_day.html', 'punch'], ['leave_requests', 'Leave', 'leave_requests.html', ''], ['salary', 'Salary', 'salary.html', ''], ['penalties', 'Penalty list', 'penalties.html', ''], ['approvals', 'Approvals', 'approvals.html', ''], ['documents', 'Documents', 'documents.html', ''], ['doc_check', 'Doc Check', 'doc_check.html', ''], ['agreements', 'Agreements', 'agreements.html', ''], ['handover', 'Handover', 'handover.html', ''], ['ex_employees', 'Ex-employees', 'ex_employees.html', ''], ['history', 'Decline history', 'history.html', ''], ['leave', 'My Leave', 'leave.html', 'emp']] },
    { key: 'offices', label: 'Offices', icon: 'departments', sub: 'Cleaning, buying and repairs for every office, checked by Claude first', tabs: [['office_care', 'Offices', 'office_care.html', 'boss'], ['dept_work', 'To buy', 'dept_work.html', 'boss'], ['dept_fix', 'To fix', 'dept_fix.html', 'boss']] },
    { key: 'admin', label: 'Admin', icon: 'commission_rules', sub: 'Set-up you touch rarely', tabs: [['roles', 'Roles', 'roles.html', ''], ['offices', 'Office Locations', 'offices.html', ''], ['excel_files', 'Excel Files', 'excel_files.html', ''], ['system_health', 'System health', 'system_health.html', 'admin'], ['changes', 'Changes', 'changes.html', 'admin']] },
    { key: 'help', label: 'Help', icon: 'help', sub: 'Live health of the system: database, pages, POS, security', tabs: [['help', 'Help', 'help.html', 'admin']] },
  ];
  // The permission (ticked on the Roles page) that opens each page, for roles that are not Admin.
  const PERM = {
    'dashboard.html': 'dashboard', 'whatsapp_groups.html': 'whatsapp_groups', 'penalties.html': 'penalties', 'store_dashboard.html': 'rs_manage', 'store_inventory.html': 'rs_manage', 'store_billing.html': 'rs_counter', 'store_messages.html': 'rs_counter', 'store_team.html': 'rs_manage', 'store_commission.html': 'rs_manage', 'store_contacts.html': 'rs_manage', 'dept_restock.html': 'dispatch_restock',
    'people.html': 'employees', 'employees.html': 'employees', 'ex_employees.html': 'employees', 'attendance_admin.html': 'attendance', 'leave_requests.html': 'leave_requests', 'salary.html': 'salary',
    'approvals.html': 'approvals', 'documents.html': 'documents', 'agreements.html': 'agreements', 'history.html': 'decline_history', 'users.html': 'users', 'roles.html': 'users', 'changes.html': 'users',
    'inventory.html': 'inventory', 'finance_in.html': 'finance', 'finance_sum.html': 'finance', 'finance_parcels.html': 'finance', 'analytics.html': 'analytics', 'departments.html': 'departments', 'offices.html': 'offices', 'excel_files.html': 'excel_files', 'doc_check.html': 'documents', 'handover.html': 'employees',
    'total_budgeting.html': 'commission', 'commission_new.html': 'commission', 'commission.html': 'commission', 'leaders.html': 'commission',
  };
  const ICON_OF = {};  W.forEach((w) => { ICON_OF[w.key] = w.icon; });
  const byKey = (k) => W.find((w) => w.key === k);
  const pageOf = (u) => String(u || '').split('#')[0].split('?')[0].split('/').pop();
  function tabByPage(file) {
    for (const w of W) { const t = w.tabs.find((x) => x[2] === file); if (t) return { w, t }; }
    return null;
  }
  // Which workspace does a page key (the word each page passes to renderSidebar) belong to?
  function wsOf(active, SPK) {
    if (!active) return '';
    if (String(active).indexOf('ws:') === 0) return String(active).slice(3);
    if (active === 'whatsapp' || active === 'wa_chats' || active === 'wa_setup') return 'whatsapp';
    if (active === 'sales' || (SPK && SPK.indexOf(active) > -1)) return 'sales';
    for (const w of W) if (w.tabs.some((t) => t[0] === active)) return w.key;
    if (active === 'whatsapp') return 'whatsapp';
    return '';
  }

  /* ---- who is looking, and what may they open ---- */
  async function loadCtx() {
    const user = (typeof api !== 'undefined' && api.currentUser()) || {};
    const get = (a) => api.get(a).catch(() => null);
    // ONE request for all five menu answers (bd.menu_ctx). If the server does not know it (older copy) or it fails, the five separate calls run as before.
    let flags, bd, fl, cxb, sxb, got = false;
    try {
      const m = await api.get('bd.menu_ctx');
      if (m && typeof m === 'object' && 'flags' in m && m.flags) {
        flags = m.flags; bd = m.bd; fl = m.fl; cxb = m.cx; sxb = m.sx; got = true;
        // the rest of the page asks these by their own names: answer those from the same memory
        api.seed('me.flags', m.flags); api.seed('bd.badge', m.bd); api.seed('fl.badge', m.fl); api.seed('bd.cx_badge', m.cx); api.seed('bd.sx_badge', m.sx);
      }
    } catch (e) { got = false; }
    if (!got) [flags, bd, fl, cxb, sxb] = await Promise.all([get('me.flags'), get('bd.badge'), get('fl.badge'), get('bd.cx_badge'), get('bd.sx_badge')]);
    const f = flags || {};
    return {
      cx: cxb || null,
      sx: sxb || null,
      isAdmin: (f.role || user.role) === 'Admin',
      role: f.role || user.role || '',
      perms: f.permissions || [],
      hasEmp: !!f.has_employee, hasSalary: !!f.has_salary, isSales: !!f.is_sales,
      boss: !!(bd && bd.manager), bd: bd || null,
      fl: fl && fl.show ? { show: true, manager: !!fl.manager } : null,
    };
  }
  function tabOk(t, ctx) {
    const need = t[3];
    if (need === 'admin') return ctx.isAdmin;
    if (need === 'srp') return ctx.isAdmin || ctx.perms.indexOf('store_pos') > -1;   // Store → POS: Admin and roles ticked 'Store · POS only'
    if (need === 'rsd') return ctx.isAdmin || ctx.perms.indexOf('dispatch_restock') > -1;   // Dispatch → Store orders: Admin and the Dispatch role
    if (need === 'wa') return ctx.isAdmin || ctx.role === 'HR' || ctx.perms.indexOf('control_tower') > -1;   // the WhatsApp console: the same people wa.php lets in
    if (need === 'boss') return ctx.boss;
    if (need === 'pp') return !!(ctx.isAdmin || ctx.role === 'HR');   // People (Boss → People): Admin and HR
    if (need === 'cx') return !!(ctx.isAdmin || (ctx.cx && ctx.cx.team));   // the Complaint team (people on a desk seat), Admin and the complaints boss
    if (need === 'cxr') return !!(ctx.isAdmin || (ctx.cx && ctx.cx.mgr));   // Staff progress: Admin, the complaints boss, the top boss / deputy (the same people bd.staff_report lets in)
    if (need === 'cxm') return !!(ctx.isAdmin || (ctx.cx && (ctx.cx.team || ctx.cx.has_mine)) || ctx.isSales);   // My complaints: the ones I raised
    if (need === 'sx') return !!(ctx.isAdmin || (ctx.sx && ctx.sx.team));   // the Inbox team (seat inbox.worker), Admin and the Inbox boss
    if (need === 'fl') return !!ctx.fl;
    if (need === 'flm') return !!(ctx.fl && ctx.fl.manager);
    if (need === 'emp') return (ctx.isAdmin && ctx.hasEmp) || (!ctx.isAdmin && ctx.perms.indexOf('leave') > -1);
    if (need === 'an') return ctx.isAdmin || ctx.perms.indexOf('analytics') > -1;   // Analytics: Admin, or any role Admin ticked it for on the Roles page
    if (need === 'punch') return ctx.isAdmin ? ctx.hasEmp : ctx.perms.indexOf('punch') > -1;
    if (need === 'rsm') return ctx.isAdmin || ctx.perms.indexOf('rs_manage') > -1;   // Store dashboard: Admin and the store manager
    if (need === 'rs') return ctx.isAdmin || ctx.perms.some((p) => /^(retail_store|rs_counter|rs_stock|rs_cash|rs_manage|rs_tasks)$/.test(p));
    return ctx.isAdmin || ctx.perms.indexOf(PERM[t[2]]) > -1;
  }
  const visibleTabs = (w, ctx) => w.tabs.filter((t) => tabOk(t, ctx));

  /* ---- the menu (rail) ---- */
  function css() {
    if (document.getElementById('wsCss')) return;
    const st = document.createElement('style'); st.id = 'wsCss';
    st.textContent = 'html.ws-rail .sidebar:not(.wide) nav a{width:52px;height:58px;flex-direction:column;justify-content:center;gap:3px;border-radius:18px;margin:0 auto 6px;}'
      + 'html.ws-rail .sidebar:not(.wide) nav a .lbl{display:block;font-size:11px;line-height:1;font-weight:600;}'
      + '.sidebar a .ws-badge{position:absolute;top:3px;right:3px;background:#e02424;color:#fff;border-radius:9px;padding:0 5px;font-size:11px;line-height:16px;font-weight:700;margin:0;}'
      + '.sidebar.wide a .ws-badge{position:static;margin-left:auto;}'
      + 'html.ws-rail .sidebar .nav-cat-toggle{display:none !important;}';
    document.head.appendChild(st);
  }
  const bdg = (id) => ' <span class="ws-badge" id="' + id + '" style="display:none"></span>';
  function linkFor(w, active, SPK) {
    const cur = wsOf(active, SPK);
    const href = w.direct || ('hub.html?w=' + w.key);
    let label = esc(w.label);
    if (w.key === 'sales') label += ' <span id="ctBadge" style="display:none; background:#e02424; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:6px;"></span>';
    if (w.key === 'boss') label += bdg('wsb_boss');
    if (w.key === 'complaints') label += bdg('wsb_complaints');
    if (w.key === 'inbox') label += bdg('wsb_inbox');
    if (w.key === 'people') label += bdg('wsb_people');
    if (w.key === 'offices') label += bdg('wsb_offices');
    return [href, label, cur === w.key ? (w.key === 'sales' ? 'sales' : active) : 'ws_' + w.key];
  }
  function setBadge(id, n) { const b = document.getElementById(id); if (b && n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.style.display = 'inline-block'; } }
  function badges() {
    if (typeof api === 'undefined') return;
    api.get('bd.sx_badge').then((d) => { if (d && d.show) setBadge('wsb_inbox', (Number(d.late) || 0) || (Number(d.mine) || 0)); }).catch(() => {});
    api.get('bd.cx_badge').then((d) => { if (d && d.show) setBadge('wsb_complaints', (Number(d.late) || 0) || (Number(d.my_open) || 0)); }).catch(() => {});
    Promise.all([api.get('bd.badge').catch(() => null), api.get('dp.badge').catch(() => null)]).then(([d, p]) => { if (d && d.manager) setBadge('wsb_boss', (Number(d.inbox) || 0) + (Number(p && p.tasks) || 0)); }).catch(() => {});
    api.get('chk.badge').then((d) => { if (d) setBadge('wsb_offices', Number(d.review) || 0); }).catch(() => {});
    Promise.all([api.get('leave_admin.count').catch(() => null), api.get('approvals.count').catch(() => null)])
      .then(([a, b]) => setBadge('wsb_people', (Number(a && a.count) || 0) + (Number(b && b.count) || 0))).catch(() => {});
  }
  // Admin: the menu is the workspace list (replaces the old groups).
  function adminCats(active, SPK) {
    css(); document.documentElement.classList.add('ws-rail');
    setTimeout(badges, 60);
    return [{ key: 'ws', label: 'Menu', defaultOpen: true, links: W.filter((w) => !w.hidden).map((w) => linkFor(w, active, SPK)) }];
  }
  // Roles that are not Admin / Employee (HR and custom roles): same menu, only the workspaces they may open.
  function customNav(nav, perms, d, active, env) {
    css();
    const SPK = env.SALES_PAGE_KEYS, SP = env.SALES_PERMS, PFP = env.PAGES_FOR_PERM;
    loadCtx().then((ctx) => {
      ctx.perms = perms; ctx.isAdmin = false;
      const links = [];
      W.forEach((w) => {
        if (w.hidden) return;
        if (w.key === 'sales') { if (perms.some((p) => SP.indexOf(p) > -1)) links.push(linkFor(w, active, SPK)); return; }
        if (visibleTabs(w, ctx).length) links.push(linkFor(w, active, SPK));
      });
      // pages a role has that no workspace holds: keep them as plain links, so nothing is lost
      const extra = [];
      perms.forEach((p) => {
        if (SP.indexOf(p) > -1) return;
        (PFP[p] || []).forEach((def) => { const held = tabByPage(def[0]); if ((!held || (held.w && held.w.hidden)) && !extra.some((x) => x[0] === def[0])) extra.push(def); });   // a page that only lives in a HIDDEN workspace (My Day) is not in the menu: show it as its own link
      });
      const a = (href, label, key) => '<a href="' + href + '" class="' + (active === key ? 'active' : '') + '">' + label + '</a>';
      let html = links.map((l) => a(l[0], l[1], l[2])).join('') + extra.map((x) => a(x[0], x[1], x[2])).join('');
      if (!html) html = '<span style="display:block; padding:8px 20px; font-size:12.5px; color:#8a93a6;">No access granted yet — ask Admin.</span>';
      document.documentElement.classList.add('ws-rail');
      nav.insertAdjacentHTML('beforeend', html);
      badges();
    });
  }
  // Which icon a menu link wears (the rail looks icons up by the page name; workspaces borrow a fitting one).
  function iconKey(a) {
    const m = /hub\.html\?w=([a-z]+)/.exec(a.getAttribute('href') || '');
    return m ? (ICON_OF[m[1]] || 'dot') : '';
  }
  // Someone opens an old address (penalties.html, salary.html...) on its own: show it inside its workspace instead.
  function redirect(active) {
    try {
      if (window.parent !== window) return false;
      const user = api.currentUser() || {};
      if (user.role === 'Employee' || /[?&]nohub=1/.test(location.search)) return false;
      const file = pageOf(location.pathname);
      if (!file || file === 'hub.html') return false;
      const hit = tabByPage(file); if (!hit) return false;
      const q = location.search.replace(/^\?/, '').split('&').filter((x) => x && !/^(in|nohub)=/.test(x)).join('&');
      document.documentElement.style.visibility = 'hidden';
      location.replace('hub.html?w=' + hit.w.key + '&t=' + hit.t[0] + (q ? '&q=' + encodeURIComponent(q) : ''));
      return true;
    } catch (e) { return false; }
  }

  /* ---- hub.html: the tab strip and the page inside ---- */
  async function hub() {
    const $ = (id) => document.getElementById(id);
    const P = new URLSearchParams(location.search);
    const w = byKey(P.get('w')) || W[0];
    const LAST = 'hrms_ws_' + w.key;
    $('wsTitle').textContent = w.label; $('wsSub').textContent = w.sub || '';
    document.title = w.label + ' — HRMS';
    let ctx;
    try { ctx = await loadCtx(); } catch (e) { $('wsHost').innerHTML = '<div class="ws-empty">Could not load your access (' + esc(e.message) + ').</div>'; return; }
    const tabs = visibleTabs(w, ctx);
    if (!tabs.length) { $('wsLoad').hidden = true; $('wsHost').innerHTML = '<div class="ws-empty">Nothing is open for your login here yet — ask Admin.</div>'; return; }
    const frames = {}; let cur = null;
    const urlFor = (t, q) => { const s = t[2] + (q ? (t[2].indexOf('?') < 0 ? '?' : '&') + q.replace(/^\?/, '') : ''); return s + (s.indexOf('?') < 0 ? '?' : '&') + 'in=sales' + (t[4] ? '#' + t[4] : ''); };
    const bar = () => {
      $('wsTabs').innerHTML = tabs.length < 2 ? '' : tabs.map((t) => '<button type="button" class="ws-pill' + (cur === t ? ' on' : '') + '" data-t="' + t[0] + '">' + esc(t[1]) + (t[0] === 'boss_engine' && ctx.bd && ((Number(ctx.bd.inbox) || 0) + (Number(ctx.bd.be) || 0)) > 0 ? '<em>' + ((Number(ctx.bd.inbox) || 0) + (Number(ctx.bd.be) || 0)) + '</em>' : '') + '</button>').join('');
    };
    function guard(f, t) {
      let win; try { win = f.contentWindow; void win.location.pathname; } catch (e) { return; }
      const here = tabs.find((x) => x[2] === pageOf(win.location.href));
      if (here && here !== t && here[2] !== t[2]) { if (frames[t[0]] === f) delete frames[t[0]]; f.remove(); if (cur === t) show(here, win.location.search.replace(/^\?/, '').split('&').filter((x) => x && !/^in=/.test(x)).join('&')); else bar(); return; }
      try {
        win.document.addEventListener('click', (e) => {
          const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
          if (!a || a.target === '_blank' || e.defaultPrevented) return;
          const href = a.getAttribute('href') || '';
          const to = tabs.find((x) => x[2] === pageOf(href));
          if (to && to !== t && to[2] !== t[2]) { e.preventDefault(); show(to, href.indexOf('?') > -1 ? href.split('?')[1].split('#')[0] : ''); }
        }, true);
      } catch (e) { /* the check above still covers it */ }
    }
    let hubStarted = false;   // the first tab replaces the entry; each tab opened after it is one Back step (popstate below brings it back)
    function show(t, q, noPush) {
      if (!t) return; const changed = !cur || cur[0] !== t[0]; cur = t;
      try { localStorage.setItem(LAST, t[0]); } catch (e) { /* private mode */ }
      const url = 'hub.html?w=' + w.key + '&t=' + t[0];
      if (!hubStarted || noPush || !changed) history.replaceState(history.state, '', url); else history.pushState({ hubTab: t[0] }, '', url); hubStarted = true;
      const fk = t[4] ? t[2] : t[0];                                   // the Boss Engine views share one page: one frame, the view is its #hash
      Object.keys(frames).forEach((k) => { frames[k].hidden = k !== fk; });
      let f = frames[fk];
      if (f && t[4]) {
        try { if (f.contentWindow.location.hash.slice(1) !== t[4]) f.contentWindow.location.hash = '#' + t[4]; } catch (e) { /* the frame reloads below */ }
        $('wsLoad').hidden = true; bar(); document.title = t[1] + ' · ' + w.label + ' — HRMS'; return;
      }
      if (f && q) { f.remove(); delete frames[fk]; f = null; }
      if (!f) {
        f = document.createElement('iframe'); f.setAttribute('allow', 'camera; microphone; geolocation; clipboard-read; clipboard-write; fullscreen'); f.className = 'ws-frame'; f.title = t[1];
        f.setAttribute('allow', 'camera; microphone; geolocation; clipboard-write');
        f.addEventListener('load', () => { if (frames[fk] === f && cur && (t[4] ? cur[2] === t[2] : cur === t)) $('wsLoad').hidden = true; guard(f, t); });
        frames[fk] = f; $('wsHost').appendChild(f); $('wsLoad').hidden = false; f.src = urlFor(t, q);
      } else $('wsLoad').hidden = true;
      bar(); document.title = t[1] + ' · ' + w.label + ' — HRMS';
    }
    window.addEventListener('message', (e) => {
      if (e.origin !== location.origin || !e.data || !e.data.b2view) return;
      const nt = tabs.find((x) => x[2] === 'boss_engine.html' && x[4] === e.data.b2view);
      if (nt && nt !== cur) { cur = nt; try { localStorage.setItem(LAST, nt[0]); history.replaceState(history.state, '', 'hub.html?w=' + w.key + '&t=' + nt[0]); } catch (x) { /* ignore */ } bar(); }
    });
    $('wsTabs').addEventListener('click', (e) => { const b = e.target.closest('.ws-pill'); if (b) show(tabs.find((t) => t[0] === b.dataset.t)); });
    window.addEventListener('popstate', () => { const tk = new URLSearchParams(location.search).get('t'); const nt = tabs.find((x) => x[0] === tk); if (nt && (!cur || nt[0] !== cur[0])) show(nt, '', true); });
    let want = P.get('t'); if (!want) { try { want = localStorage.getItem(LAST) || ''; } catch (e) { want = ''; } }
    show(tabs.find((t) => t[0] === want) || tabs[0], P.get('q') || '');
    ctx.isHome = w.key === 'home';
    tools(ctx);
    if (w.key === 'home') needs(ctx);
  }
  /* ---- everything waiting for this person, in one list (the bell, the chip strip on Home) ---- */
  async function waiting(ctx) {
    const get = (a, q) => api.get(a, q).catch(() => null);
    const canCt = ctx.isAdmin || ctx.perms.indexOf('control_tower') > -1, canShop = ctx.isAdmin || ctx.role === 'HR';
    const [lv, ap, ck, ct, st, nc] = await Promise.all([ctx.isAdmin || ctx.perms.indexOf('leave_requests') > -1 ? get('leave_admin.count') : null, ctx.isAdmin || ctx.perms.indexOf('approvals') > -1 ? get('approvals.count') : null, ctx.boss ? get('chk.badge') : null,
      canCt ? get('ct.pulse') : null, canShop ? get('retail.stock_alerts', { badge: 1 }) : null, get('needs.counts')]);
    const items = [];
    if (ctx.bd && ctx.boss && ctx.bd.inbox > 0) items.push(['hub.html?w=boss&t=boss_engine', ctx.bd.inbox, ctx.bd.inbox === 1 ? 'decision for the Boss' : 'decisions for the Boss']);
    const ctN = ct && ct.admin ? (Number(ct.red) || 0) + (Number(ct.meet) || 0) : 0;
    if (ctN > 0) items.push(['sales.html#live/blocks', ctN, ctN === 1 ? 'sales alert (red or meet admin)' : 'sales alerts (red or meet admin)']);
    if (nc && nc.visits_waiting > 0) items.push(['sales.html#retail/shops', nc.visits_waiting, nc.visits_waiting === 1 ? 'shop visit to approve' : 'shop visits to approve']);
    if (st && st.unread > 0) items.push(['sales.html#retail/stock', st.unread, st.unread === 1 ? 'stock alert (low or out)' : 'stock alerts (low or out)']);
    if (nc && nc.wa_unassigned > 0) items.push(['hub.html?w=whatsapp&t=whatsapp', nc.wa_unassigned, nc.wa_unassigned === 1 ? 'WhatsApp message nobody has taken' : 'WhatsApp messages nobody has taken']);
    if (nc && nc.wa_mine > 0) items.push(['my_chats.html', nc.wa_mine, nc.wa_mine === 1 ? 'customer message waiting for you' : 'customer messages waiting for you']);
    if (ck && ck.loops > 0) items.push(['hub.html?w=boss&t=daily_summary', ck.loops, ck.loops === 1 ? 'problem keeps coming back' : 'problems keep coming back']);
    if (ck && ck.leavers > 0) items.push(['hub.html?w=people&t=handover', ck.leavers, ck.leavers === 1 ? 'leaver needs a handover' : 'leavers need a handover']);
    if (ck && ck.docs > 0) items.push(['hub.html?w=people&t=doc_check', ck.docs, ck.docs === 1 ? 'document problem' : 'document problems']);
    if (ck && ck.review > 0) items.push(['hub.html?w=offices&t=office_care', ck.review, ck.review === 1 ? 'cleaning to check' : 'cleanings to check']);
    if (lv && lv.count > 0) items.push(['hub.html?w=people&t=leave_requests', lv.count, lv.count === 1 ? 'leave request' : 'leave requests']);
    if (ap && ap.count > 0) items.push(['hub.html?w=people&t=approvals', ap.count, ap.count === 1 ? 'approval' : 'approvals']);
    if (ck && ck.other > 0) items.push(['hub.html?w=boss&t=daily_summary', ck.other, ck.other === 1 ? 'other thing Claude found' : 'other things Claude found']);
    return items;
  }
  // Home: the things waiting for you, as chips that open the right tab.
  async function needs(ctx, items) {
    const box = document.getElementById('wsNeeds'); if (!box) return;
    items = items || await waiting(ctx);
    if (!items.length) { box.innerHTML = '<span class="ws-ok">All clear — Claude checked and nothing is waiting for you.</span>'; return; }
    box.innerHTML = '<span class="ws-lbl">Needs you</span>' + items.map((i) => '<a class="ws-chip" href="' + i[0] + '"><b>' + i[1] + '</b> ' + esc(i[2]) + '</a>').join('');
  }

  /* ---- top bar: search (Ctrl+K) and one bell ---- */
  const KW = { brain: 'brain shelves company customer internal teach claude knows rules facts notes leak test trial bot replies old notes', tracker: 'daily tracker topics fields who fills verify admin penalty deadline steps proof screenshot video priority', wasales: 'whatsapp sales queries attention behind meta campaign community regenerated bot types analytics cut over', finance: 'money bank statement upload cod prepaid settlement remittance bill memo gst tds profit loss ledger accounts card reconcile', dept_work: 'shopping list buy milk ice supplies assign buyer', dept_fix: 'fix repair maintenance report problem broken workman', boss_questions: 'questions ask claude learn understand live', dept_hub: 'departments seats dispatch parcels money retail supplies track record', boss_penalties: 'penalty rules amount fine charged', people_admin: 'admin team founders who can approve tasks', my_day: 'my day punch in out attendance selfie jobs tasks', dept_dispatch: 'dispatch parcels tracking shiprocket courier awb rto delivered', dept_restock: 'restock reorder memo stock empty dispatch pending barcode scan delay order every 2 days', daily_summary: 'summary report checked loop repeating claude', boss_inbox: 'inbox decisions approve penalty photo', penalties: 'fine deduction penalty', documents: 'licence license passport aadhaar pan id upload', doc_check: 'documents missing expiry licence passport id claude read', agreements: 'contract agreement', handover: 'leaver left resign replace takeover', office_care: 'office cleaning cleaner zones checkpoints maintenance ticket', leave_requests: 'leave holiday', attendance_admin: 'punch attendance', salary: 'pay payroll payslip pay slip wages salary slip advance deduction earned paid month all employees', employees: 'staff people', approvals: 'approve pending' };
  // every Sales tab and page, so Ctrl+K opens it directly: [name, group, #section/tab/page, permission, words people type]
  const SALES_FIND = [
    ['Blocks (today)', 'Live', 'live/blocks', 'control_tower', 'today strip control tower query orders goal community checkpoints'],
    ['Queries (give queries, move, campaign)', 'Live', 'live/queries', 'control_tower', 'query boost assign give move waive campaign community hourly'],
    ['Focus', 'Live', 'live/focus', 'control_tower', 'morning focus call now'],
    ['Orders', 'Orders', 'orders/orders', 'orders', 'shopify manual mysales'],
    ['Order allocation', 'Orders', 'orders/allocation', 'allocation', 'allocate flags chain address community proof'],
    ['Order codes', 'Orders', 'orders/codes', 'order_codes', 'excel upload lock'],
    ['30-day sheet', 'Orders', 'orders/sheet', 'orders', 'employee sheet panels target achieved'],
    ['Ranking', 'Team', 'people/ranking', 'control_tower', 'rating ratings leaderboard'],
    ['Person', 'Team', 'people/person', 'control_tower', 'one salesperson days month'],
    ['Targets (rupees)', 'Team', 'people/targets/rupees', 'targets', 'target rules ladder sync diagnose new executive'],
    ['Order targets (order count)', 'Team', 'people/targets/orders', 'order_targets', 'hourly grid week planner tracked staff'],
    ['Performance', 'Team', 'people/performance/month', 'performance', 'monthly daily ratings add-ons sales rating'],
    ['Day-by-day sheet (detailed report)', 'Team', 'people/performance/sheet', 'detailed_report', 'detailed report roas ad spend collection combined commission'],
    ['Commission result & rules (new)', 'Team', 'people/commission/result', 'commission', 'pricing offers boost slab ml code over target recount'],
    ['Commission old result', 'Team', 'people/commission/old', 'commission', 'day month order-wise overrides penalties'],
    ['Leaders', 'Team', 'people/leaders', 'commission', 'leader pay roas'],
    ['Shops (wholesale)', 'Shops', 'retail/shops', 'retail', 'party ledger visit plan payments dues map import'],
    ['Shop stock', 'Shops', 'retail/stock', 'retail', 'inventory city alerts display'],
    ['Learning & Improvement (Teach the bot)', 'Learn & Setup', 'learn/learning', 'control_tower', 'teach bot facts price limits discount practice follow-up guidebook'],
    ['Message Doctor', 'Sales tools', 'tools/messages/doctor', 'control_tower', 'messages words pre-messages'],
    ['Benchmarks & ideas', 'Sales tools', 'tools/messages/bench', 'control_tower', 'ideas learning'],
    ['Chat library', 'Learn & Setup', 'learn/library', 'control_tower', 'chat proof screenshots'],
    ['Conversations', 'Sales tools', 'tools/conv', 'control_tower', 'chat proof call recordings search'],
    ['Photos', 'Learn & Setup', 'learn/botdata/photos', 'control_tower', 'product photos whatsapp media'],
    ['Products (find a perfume)', 'Learn & Setup', 'learn/botdata/products', 'control_tower', 'perfume products shopify sync notes longevity occasions request types'],
    ['Backups', 'Sales tools', 'tools/backups', 'control_tower', 'learning backup restore'],
    ['Sales settings', 'Sales tools', 'tools/settings', 'control_tower', 'control tower settings'],
  ];
  function pagesFor(ctx) {
    const out = [];
    W.forEach((w) => {
      if (w.key === 'sales') { out.push({ label: 'Sales', where: 'Sales', href: 'sales.html', kw: 'orders targets commission' }); SALES_FIND.forEach((x) => { if (ctx.perms.indexOf(x[3]) > -1) out.push({ label: x[0], where: 'Sales · ' + x[1], href: 'sales.html#' + x[2], kw: x[4] || '' }); }); return; }
      visibleTabs(w, ctx).forEach((t) => out.push({ label: t[1], where: w.label, href: 'hub.html?w=' + w.key + '&t=' + t[0], kw: KW[t[0]] || '' }));
    });
    if (!ctx.isAdmin) out.unshift({ label: 'My Day', where: 'Home', href: 'my_day.html', kw: 'punch in out jobs tasks today duties' });
    if (ctx.isSales) [['My Sales', 'my_sales.html', 'target commission orders rating'], ['My Orders', 'my_orders.html', 'orders allocate'], ['My Chats', 'my_chats.html', 'chat whatsapp customers'], ['My Queries', 'my_chats.html?tab=queries', 'queries whatsapp waiting type meta campaign']].forEach((x) => out.push({ label: x[0], where: 'Sales', href: x[1], kw: x[2] }));
    // My own records (My Day > My records): salary, attendance, leave, penalties, agreements - for anybody who has an employee profile.
    if (ctx.hasEmp) {
      if (ctx.hasSalary) out.push({ label: 'My salary', where: 'My Day · My records', href: 'my_day.html#records/salary', kw: 'salary payslip pay slip wages my pay earned day-wise final pay month' });
      out.push({ label: 'My attendance', where: 'My Day · My records', href: 'my_day.html#records/attendance', kw: 'punch in out hours today history' });
      out.push({ label: 'My leave', where: 'My Day · My records', href: 'my_day.html#records/leave', kw: 'apply leave holiday off sick' });
      out.push({ label: 'My penalties', where: 'My Day · My records', href: 'my_day.html#records/penalties', kw: 'fine penalty charged not fair' });
      out.push({ label: 'My agreements', where: 'My Day · My records', href: 'my_day.html#records/agreements', kw: 'agreement contract sign document' });
    }
    return out;
  }
  function toolsCss() {
    if (document.getElementById('wsToolsCss')) return;
    const st = document.createElement('style'); st.id = 'wsToolsCss';
    st.textContent = '.topbar > .ws-top > .ws-tools{display:flex !important; align-items:center; gap:8px; margin-left:auto; width:auto !important;}'
      + '.ws-srch{display:flex; align-items:center; gap:8px; border:0; cursor:pointer; font:inherit; font-size:13px; color:#6b7280; background:#fff; padding:8px 14px; border-radius:99px; box-shadow:0 1px 3px rgba(20,22,26,.08);}'
      + '.ws-srch kbd{font:inherit; font-size:11px; background:#f1f2f4; border-radius:6px; padding:1px 6px; color:#6b7280;}'
      + '.ws-bell{position:relative; border:0; cursor:pointer; font:inherit; font-size:13px; font-weight:700; color:#111; background:#fff; padding:8px 14px; border-radius:99px; box-shadow:0 1px 3px rgba(20,22,26,.08);}'
      + '.ws-bell.hot{background:var(--accent,#ff7a2f); color:#fff; box-shadow:0 2px 8px rgba(255,122,47,.28);}'
      + '.ws-pop{position:absolute; z-index:60; right:0; top:46px; width:min(340px,92vw); background:#fff; border-radius:20px; box-shadow:0 12px 40px rgba(20,22,26,.18); padding:10px; display:none;}'
      + '.ws-pop.on{display:block;} .ws-pop h4{margin:4px 8px 8px; font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#6b7280;}'
      + '.ws-pop a{display:flex; gap:10px; align-items:center; padding:9px 10px; border-radius:14px; color:#111; text-decoration:none; font-size:13.5px;} .ws-pop a:hover{background:#f6f7f9;}'
      + '.ws-pop a b{background:var(--accent,#ff7a2f); color:#fff; border-radius:99px; min-width:24px; text-align:center; padding:1px 7px; font-size:12px;}'
      + '.ws-pop .none{padding:10px; color:#6b7280; font-size:13px;}'
      + '#wsPal{position:fixed; inset:0; z-index:200; background:rgba(20,22,26,.35); display:none; align-items:flex-start; justify-content:center; padding-top:12vh;} #wsPal.on{display:flex;}'
      + '#wsPal .box{width:min(560px,94vw); background:#fff; border-radius:24px; box-shadow:0 20px 60px rgba(20,22,26,.3); padding:12px;}'
      + '#wsPal input{width:100%; box-sizing:border-box; border:0; outline:0; font:inherit; font-size:16px; padding:12px 14px; background:#f6f7f9; border-radius:16px;}'
      + '#wsPal .res{max-height:50vh; overflow:auto; margin-top:8px;} #wsPal .res a{display:flex; justify-content:space-between; gap:10px; padding:10px 12px; border-radius:14px; color:#111; text-decoration:none; font-size:14px;}'
      + '#wsPal .res a.sel, #wsPal .res a:hover{background:#111; color:#fff;} #wsPal .res a span{opacity:.6; font-size:12px;}'
      + '@media (max-width:820px){.ws-srch .t, .ws-srch kbd{display:none;} .topbar > .ws-top > .ws-tools{margin-left:0;}}';
    document.head.appendChild(st);
  }
  function tools(ctx, only) {
    const host = document.getElementById('wsTools'); if (!host) return;
    toolsCss();
    host.innerHTML = '<button type="button" class="ws-srch" id="wsSrch" aria-label="Search pages">&#128269;<span class="t">Search</span><kbd>Ctrl K</kbd></button>'
      + (only ? '' : '<span style="position:relative"><button type="button" class="ws-bell" id="wsBell" aria-label="What needs you">&#128276; <span id="wsBellN">0</span></button><div class="ws-pop" id="wsPop"></div></span>');
    // palette
    const pages = pagesFor(ctx);
    let pal = document.getElementById('wsPal');
    if (!pal) { pal = document.createElement('div'); pal.id = 'wsPal'; pal.innerHTML = '<div class="box"><input id="wsPalIn" placeholder="Go to…  (try: licence, cleaning, penalty, leaver)" autocomplete="off"><div class="res" id="wsPalRes"></div></div>'; document.body.appendChild(pal); }
    const inp = document.getElementById('wsPalIn'), res = document.getElementById('wsPalRes'); let sel = 0, list = [];
    const draw = () => {
      const q = inp.value.trim().toLowerCase();
      // a page whose NAME matches comes first (typing "salary" shows Salary and My salary before anything that only mentions it)
      const hit = (p) => (p.label + ' ' + p.where + ' ' + p.kw).toLowerCase().indexOf(q) > -1, nm = (p) => p.label.toLowerCase().indexOf(q) > -1 ? 0 : 1;
      list = pages.filter((p) => !q || hit(p)).sort((a, b) => (q ? nm(a) - nm(b) : 0)).slice(0, 12);
      sel = Math.min(sel, Math.max(0, list.length - 1));
      res.innerHTML = list.length ? list.map((p, i) => '<a href="' + p.href + '" class="' + (i === sel ? 'sel' : '') + '">' + esc(p.label) + '<span>' + esc(p.where) + '</span></a>').join('') : '<div class="none" style="padding:12px;color:#6b7280">Nothing matches. Try another word.</div>';
    };
    const open = () => { pal.classList.add('on'); inp.value = ''; sel = 0; draw(); setTimeout(() => inp.focus(), 20); };
    const close = () => pal.classList.remove('on');
    document.getElementById('wsSrch').onclick = open;
    pal.addEventListener('click', (e) => { if (e.target === pal) close(); });
    inp.addEventListener('input', () => { sel = 0; draw(); });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { sel = Math.min(list.length - 1, sel + 1); draw(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { sel = Math.max(0, sel - 1); draw(); e.preventDefault(); }
      else if (e.key === 'Enter' && list[sel]) { location.href = list[sel].href; }
      else if (e.key === 'Escape') close();
    });
    document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); open(); } else if (e.key === 'Escape') { close(); document.getElementById('wsPop').classList.remove('on'); } });
    if (only) { document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); }); return; }   // employees: search only, no bell
    // bell
    const pop = document.getElementById('wsPop'), bell = document.getElementById('wsBell');
    bell.onclick = (e) => { e.stopPropagation(); pop.classList.toggle('on'); };
    document.addEventListener('click', (e) => { if (!pop.contains(e.target)) pop.classList.remove('on'); });
    const refresh = async () => {
      try {
        const items = await waiting(ctx); const n = items.reduce((a, i) => a + Number(i[1]), 0);
        document.getElementById('wsBellN').textContent = n; bell.classList.toggle('hot', n > 0);
        bell.firstChild.textContent = n > 0 ? '\u{1F514} ' : '\u{1F514} ';
        pop.innerHTML = '<h4>Needs you</h4>' + (items.length ? items.map((i) => '<a href="' + i[0] + '"><b>' + i[1] + '</b>' + esc(i[2]) + '</a>').join('') : '<div class="none">Nothing. Claude checked and all is clear.</div>');
        if (document.getElementById('wsNeeds') && ctx.isHome) needs(ctx, items);
      } catch (e) { /* the bell is a convenience */ }
    };
    refresh(); setInterval(() => { if (!document.hidden) refresh(); }, 90000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  }

  /* ---- Employee on a phone: four big buttons at the bottom ---- */
  async function employeeNav(active) {
    try {
      if (window.parent !== window) return;
      const user = (typeof api !== 'undefined' && api.currentUser()) || {};
      if (user.role !== 'Employee' || document.getElementById('wsEmpNav')) return;
      const f = await api.get('me.flags').catch(() => null); if (!f) return;
      const bd = await api.get('bd.badge').catch(() => null);
      const file = pageOf(location.pathname);
      if (file === 'flyers.html') return;   // the flyer camera page has its own round shutter button at the bottom: the bar would cover it
      // Search (Ctrl+K) for employees too: their own Salary, Leave, Penalties..., and any page their login may open.
      try {
        const tb = document.querySelector('.topbar');
        if (tb && !document.getElementById('wsTools')) {
          const h = document.createElement('span'); h.id = 'wsTools'; h.style.cssText = 'margin-left:auto;display:flex;align-items:center;'; tb.appendChild(h);
          tools({ isAdmin: false, role: 'Employee', perms: f.permissions || [], hasEmp: !!f.has_employee, hasSalary: !!f.has_salary, isSales: !!f.is_sales, boss: !!(bd && bd.manager), fl: null }, true);
        }
      } catch (e) { /* search is a convenience */ }
      const btn = [];
      btn.push(['my_day.html', 'My Day', '✅', (bd && bd.my_open) || 0]);   // everybody: the day starts here (punch-in steps, duties, sales)
      if (f.is_sales) { btn.push(['my_sales.html', 'My Sales', '\u{1F4C8}', 0]); btn.push(['my_orders.html', 'Orders', '\u{1F6CD}', 0]); btn.push(['my_chats.html', 'Chats', '\u{1F4AC}', 0]); btn.push(['my_chats.html?tab=queries', 'Queries', '\u{1F4CB}', 0]); }
      const cxb = await api.get('bd.cx_badge').catch(() => null);   // Complaints: the team opens the board, a salesperson who raised one sees the status
      if (cxb && cxb.team) btn.push(['complaints.html', 'Complaints', '\u26A0\uFE0F', Number(cxb.my_open) || Number(cxb.late) || 0]); else if (cxb && cxb.has_mine) btn.push(['my_complaints.html', 'Complaints', '\u26A0\uFE0F', 0]);
      if (cxb && cxb.mgr) btn.push(['staff_progress.html', 'Staff', '\u{1F4C8}', 0]);   // 📈 Staff progress (complaints boss)
      const sxb = await api.get('bd.sx_badge').catch(() => null);   // Inbox hub: the people who answer Instagram / Facebook / email / reviews
      if (sxb && sxb.team) btn.push(['social_inbox.html', 'Inbox', '\u{1F4E5}', Number(sxb.late) || Number(sxb.mine) || 0]);
      if (btn.length < 2) return;
      const on = (h) => file === h || (h === 'my_day.html' && /^(dept_[a-z]+)\.html$/.test(file)) || (h === 'my_day.html' && /^(attendance|leave|my_salary|my_penalties|my_agreements|my_space)\.html$/.test(file)) || (h === 'my_orders.html' && /^(orders|order_allocation)\.html$/.test(file));
      const st = document.createElement('style'); st.id = 'wsEmpCss';
      st.textContent = '#wsEmpNav{display:none;}@media (max-width:820px){#wsEmpNav{display:flex; position:fixed; left:0; right:0; bottom:0; z-index:90; background:#fffdf8; border-top:1px solid #ece7dc; padding:6px 8px calc(6px + env(safe-area-inset-bottom)); gap:4px;}'
        + '#wsEmpNav a{flex:1; display:flex; flex-direction:column; align-items:center; gap:2px; padding:7px 2px; border-radius:16px; text-decoration:none; color:#4b5563; font-size:11px; font-weight:600; position:relative;}'
        + '#wsEmpNav a .i{font-size:20px; line-height:1;} #wsEmpNav a.on{background:#111; color:#fff;} #wsEmpNav a em{position:absolute; top:2px; right:18%; background:#e02424; color:#fff; border-radius:9px; font-style:normal; font-size:11px; padding:0 5px; line-height:15px;}'
        + 'html.tm-compact #wsEmpNav{zoom:1.111;}body{padding-bottom:78px;}}';   /* zoom 1/0.9: the page is drawn at 90%, the bar keeps its normal size */
      document.head.appendChild(st);
      const nav = document.createElement('nav'); nav.id = 'wsEmpNav';
      const link = (b) => '<a href="' + b[0] + '" class="' + (on(b[0]) ? 'on' : '') + '"><span class="i">' + b[2] + '</span>' + esc(b[1]) + (b[3] > 0 ? '<em>' + b[3] + '</em>' : '') + '</a>';
      // at most 5 buttons fit a phone: the rest go under "⋯ More" (6-7 buttons squeezed the labels onto two lines and the badges onto the icons)
      const main = btn.length > 5 ? btn.slice(0, 4) : btn, more = btn.length > 5 ? btn.slice(4) : [];
      const moreOn = more.some((b) => on(b[0])), moreN = more.reduce((a, b) => a + (b[3] || 0), 0);
      nav.innerHTML = main.map(link).join('') + (more.length ? '<a href="#" id="wsEmpMore" class="' + (moreOn ? 'on' : '') + '"><span class="i">⋯</span>More' + (moreN > 0 ? '<em>' + moreN + '</em>' : '') + '</a><div id="wsEmpMoreBox" hidden>' + more.map(link).join('') + '</div>' : '');
      document.body.appendChild(nav);
      if (more.length) {
        st.textContent += '#wsEmpMoreBox{position:fixed; right:8px; bottom:calc(74px + env(safe-area-inset-bottom)); background:#fff; border:1px solid #ece7dc; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.18); padding:6px; display:flex; flex-direction:column; gap:2px; min-width:170px; z-index:91;}#wsEmpMoreBox[hidden]{display:none !important;}#wsEmpMoreBox a{flex-direction:row !important; justify-content:flex-start; gap:10px !important; padding:10px 12px !important; font-size:14px !important;}';
        const mb = document.getElementById('wsEmpMoreBox'); document.getElementById('wsEmpMore').onclick = (e) => { e.preventDefault(); mb.hidden = !mb.hidden; };
        document.addEventListener('click', (e) => { if (!mb.hidden && !e.target.closest('#wsEmpMore') && !e.target.closest('#wsEmpMoreBox')) mb.hidden = true; });
      }
      if (f.is_sales) startChatBadge();   // red number on Chats: customer messages waiting
    } catch (e) { /* the old menu still works */ }
  }

  // Department colour: every page (and every page inside a frame) gets <html data-dept="..."> so the soft department tint in sales_theme.css follows it.
  // A page knows its department from the workspace that lists it; hub.html uses ?w=; the employee pages (My Day, My Sales ...) are "home".
  function deptKey() {
    try {
      const q = new URLSearchParams(location.search), f = pageOf(location.pathname);
      if (f === 'hub.html' && q.get('w')) return q.get('w');
      const t = tabByPage(f); if (t) return t.w.key;
      if (/^(my_|leave|attendance\.html|tracker|office_care|my-)/.test(f)) return 'home';
    } catch (e) { /* no colour: the page keeps the plain look */ }
    return '';
  }
  try { const d = deptKey(); if (d) document.documentElement.setAttribute('data-dept', d); } catch (e) { /* ignore */ }

  return { on: true, v: 2, W, adminCats, customNav, iconKey, redirect, hub, wsOf, loadCtx, employeeNav, deptKey };
})();
/*WS-END*/

/* flyerMenu: adds the Flyers links to a menu. Employees see Flyers only when they have flyer work; managers also see Flyer Map. */
function flyerMenu(active, nav) {
  if (!nav || typeof api === 'undefined') return;
  api.get('fl.badge').then((d) => {
    if (!d || !d.show) return;
    const add = (href, label, key) => {
      if (nav.querySelector('a[href="' + href + '"]')) return;
      const a = document.createElement('a'); a.href = href; a.textContent = label; if (active === key) a.className = 'active';
      nav.appendChild(a);
    };
    if (d.manager) add('flyer_map.html', 'Flyer Map', 'flyer_map');
    add('flyers.html', 'Flyers', 'flyers');
  }).catch(() => { /* the Flyers link is optional */ });
}

function renderSidebarCore(active) {
  const user = api.currentUser() || { username: '', role: '' };
  const linkClass = (key) => (active === key ? 'active' : '');
  const isAdmin = user.role === 'Admin';

  // The role saved at login can go stale when Admin changes it on the Users
  // page. me.flags returns the live role: store it and rebuild the page so
  // the new access applies without logging out and in.
  const syncRole = (d) => {
    const tag = document.getElementById('buildTag');
    if (tag && d && d.build) tag.textContent = 'layout ' + LAYOUT_BUILD + ' · api ' + d.build;
    // The server knows which layout.js has every current page in its menu.
    // An older one still loaded here means a stale upload or cache — say so
    // instead of silently missing links.
    if (tag && d && d.layout_min && LAYOUT_BUILD < d.layout_min) {
      tag.insertAdjacentHTML('afterend', `<span style="display:block; margin-top:6px; font-size:11px; color:#fca5a5; line-height:1.4;">Old menu file loaded (layout ${LAYOUT_BUILD}, server needs ${d.layout_min}). Upload the latest assets/js/layout.js and reload.</span>`);
    }
    if (d && d.role && d.role !== user.role) {
      api.setUser(Object.assign({}, user, { role: d.role }));
      window.location.reload();
      return true;
    }
    return false;
  };

  // The permissions whose pages now live inside the single Sales item (sales.html).
  const SALES_PERMS = ['control_tower', 'orders', 'allocation', 'order_codes', 'retail', 'targets', 'order_targets', 'performance', 'detailed_report', 'commission', 'commission_rules'];
  const SALES_PAGE_KEYS = ['control_tower', 'orders', 'order_allocation', 'order_codes', 'retail_shops', 'retail_inventory', 'targets', 'order_targets', 'performance', 'detailed_report', 'commission', 'commission_rules', 'price_charts', 'leaders', 'sales_sheet', 'my_chats'];
  // Each permission can unlock one or more pages.
  const PAGES_FOR_PERM = {
    inventory: [['inventory.html', 'Inventory', 'iv_dash']],
    dispatch_restock: [['dept_restock.html', 'Store orders', 'dept_restock']],
    dashboard: [['dashboard.html', 'Dashboard', 'dashboard']],
    analytics: [['analytics.html', 'Analytics', 'analytics']],
    control_tower: [['control_tower.html', 'Sales Control Tower <span id="ctBadge" style="display:none; background:#e02424; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:6px;"></span>', 'control_tower']],
    orders: [['orders.html', 'Shopify Orders', 'orders']],
    allocation: [['order_allocation.html', 'Order Allocation', 'order_allocation']],
    order_codes: [['order_codes.html', 'Order Codes', 'order_codes']],
    retail: [['retail_shops.html', 'Retail Shops', 'retail_shops'], ['retail_inventory.html', 'Retail Inventory <span id="stockBadge" style="display:none; background:#e02424; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:6px;"></span>', 'retail_inventory']],
    attendance: [['attendance_admin.html', 'Attendance', 'attendance_admin']],
    leave_requests: [['leave_requests.html', 'Leave Requests', 'leave_requests']],
    offices: [['offices.html', 'Office Locations', 'offices']],
    punch: [['my_day.html', 'My Day', 'my_day']],
    leave: [['leave.html', 'My Leave', 'leave']],
    targets: [['targets.html', 'Targets', 'targets']],
    order_targets: [['order_targets.html', 'Order Targets', 'order_targets']],
    performance: [['performance.html', 'Performance', 'performance']],
    detailed_report: [['detailed_report.html', 'Detailed Report', 'detailed_report']],
    departments: [['departments.html', 'Departments', 'departments']],
    employees: [
      ['employees.html', 'Employees', 'employees'],
      ['ex_employees.html', 'Ex-Employees', 'ex_employees'],
    ],
    documents: [['documents.html', 'Documents', 'documents']],
    whatsapp_groups: [['whatsapp_groups.html', 'WhatsApp Groups', 'whatsapp']],
    excel_files: [['excel_files.html', 'Excel Files', 'excel']],
    assign_queries: [],        // Assign Queries now lives only inside Control Tower > Query Boost
    salary: [['salary.html', 'Salary', 'salary']],
    commission: [['commission.html', 'Commission', 'commission']],
    commission_rules: [['commission_rules.html', 'Commission Rules', 'commission_rules']],   // prices: Learn & Setup → Pricing (price_charts.html)
    penalties: [['penalties.html', 'Penalties', 'penalties']],
    rs_manage: [['store_dashboard.html', 'Store Dashboard', 'store_dashboard'], ['store_inventory.html', 'Store Inventory', 'store_inventory'], ['store_contacts.html', 'Customer follow-up', 'store_contacts']],
    rs_counter: [['store_billing.html', 'Store Billing', 'store_billing'], ['store_messages.html', 'Store Messages', 'store_messages']],
    agreements: [['agreements.html', 'Agreements', 'agreements']],
    decline_history: [['history.html', 'Decline History', 'history']],
    approvals: [['approvals.html', 'Approvals', 'approvals']],
    users: [
      ['users.html', 'Users', 'users'],
      ['roles.html', 'Roles', 'roles'],
    ],
  };

  if (user.role !== 'Admin' && user.role !== 'Employee') {
    // Custom role: sidebar built from its granted permissions. The page
    // stays hidden until me.flags confirms this role may open it, so a
    // refused page never flashes its "no permission" errors.
    // A page this person was already allowed to open in the last 12 hours shows AT ONCE (the check still runs in the background and sends them away
    // if the permission was taken back). Only a page never confirmed before waits hidden — and never longer than 2.5 s.
    const okKey = 'hrms_pok:' + user.username + ':' + user.role + ':' + active;
    const seenOk = (() => { try { return Date.now() - Number(localStorage.getItem(okKey) || 0) < 43200000; } catch (e) { return false; } })();
    if (!seenOk) {
      document.documentElement.style.visibility = 'hidden';
      // Safety net: a slow or stuck server reply must never leave the page invisible.
      setTimeout(() => { document.documentElement.style.visibility = ''; }, 2500);
    }
    document.getElementById('sidebar').outerHTML = `
    <aside class="sidebar">
      <div class="brand">HR<span>MS</span></div>
      <nav id="customNav"><div class="nav-label">Menu</div></nav>
      <div class="user-box">
        <span class="name">${user.username}</span>
        <span class="role">${user.role}</span>
        <button onclick="logout()">Log out</button>
        <span class="build" id="buildTag" style="display:block; margin-top:6px; font-size:11px; color:#64748b; cursor:pointer;" title="layout.js build · api.php build — click for an access check" onclick="window.open(api.buildUrl('diag.access', { token: api.token() }), '_blank')">layout ${LAYOUT_BUILD}</span>
      </div>
    </aside>
  `;
    addMobileMenu();
    api.get('me.flags').then((d) => {
      if (syncRole(d)) return;
      const perms = d.permissions || [];
      // If the current page isn't allowed for this role, say so in the menu
      // instead of silently jumping away (the page's own data calls are
      // refused by the server anyway, with the same explanation).
      const activePerm = Object.keys(PAGES_FOR_PERM).find(p => PAGES_FOR_PERM[p].some(d => d[2] === active));
      const nav = document.getElementById('customNav');
      if (!nav) return;
      if (activePerm && !perms.includes(activePerm)) {
        try { localStorage.removeItem(okKey); } catch (e) { /* ignore */ }
        // Not allowed here (e.g. login sent them to the Dashboard): go to
        // the first page this role IS allowed, instead of showing an error.
        const home = perms.map(p => (PAGES_FOR_PERM[p] || [])[0]).find(Boolean);
        if (home) { window.location.replace(home[0]); return; }
        const label = (PAGES_FOR_PERM[activePerm].find(d => d[2] === active) || PAGES_FOR_PERM[activePerm][0])[1];
        nav.insertAdjacentHTML('beforeend', `<span style="display:block; padding:8px 20px; font-size:12px; color:#fca5a5; line-height:1.5;">Your role "${user.role}" doesn't include <b>${label}</b>. Ask an Admin to tick it on the Roles page.</span>`);
      }
      document.documentElement.style.visibility = '';
      try { localStorage.setItem(okKey, String(Date.now())); } catch (e) { /* ignore */ }   // remembered: next time this page opens with no wait
      if (window.WS) { WS.customNav(nav, perms, d, active, { PAGES_FOR_PERM, SALES_PERMS, SALES_PAGE_KEYS }); return; } /*ws*/
      // The sales pages (Control Tower, Orders, Allocation, Codes, Targets, Performance, Commission, Retail ...) live inside ONE Sales item.
      const inSales = perms.some(p => SALES_PERMS.includes(p));
      if (inSales) nav.insertAdjacentHTML('beforeend', `<a href="sales.html" class="${active === 'sales' || SALES_PAGE_KEYS.includes(active) ? 'active' : ''}">Sales</a>`);
      perms.forEach((p) => {
        if (SALES_PERMS.includes(p)) return;
        (PAGES_FOR_PERM[p] || []).forEach((def) => {
          nav.insertAdjacentHTML('beforeend', `<a href="${def[0]}" class="${active === def[2] ? 'active' : ''}">${def[1]}</a>`);
        });
      });
      bossMenu(active, nav); /*bd*/
      flyerMenu(active, nav);
      if (perms.length === 0) {
        nav.insertAdjacentHTML('beforeend', '<span style="display:block; padding:8px 20px; font-size:12.5px; color:#8a93a6;">No access granted yet — ask Admin.</span>');
      }
    }).catch((err) => {
      document.documentElement.style.visibility = '';
      const nav = document.getElementById('customNav');
      if (nav) nav.insertAdjacentHTML('beforeend', `<span style="display:block; padding:8px 20px; font-size:12px; color:#e08585;">Menu failed to load: ${err.message}</span>`);
    });
    return;
  }

  if (user.role === 'Employee') {
    // Attendance, Leave, Salary, Penalties and Agreements live as pills inside ONE page (My Space). Opened on their own
    // (first page after login, a bookmark, a push tap) they showed without the pills until My Space was clicked — go to My Space instead.
    // 29 Oct 2026: My Space is merged into My Day ("My records" at the bottom of My Day) - old links and bookmarks land there.
    const HUB_TABS = { attendance: 'attendance', leave: 'leave', my_salary: 'salary', my_penalties: 'penalties', my_agreements: 'agreements', my_space: '' };
    if (HUB_TABS[active] != null && window.self === window.top) { if (active === 'attendance') { window.location.replace('my_day.html'); return; }   // the punch page itself: the day starts on My Day
      const t = HUB_TABS[active] || String(location.hash || '').replace('#', ''); window.location.replace('my_day.html#records' + (t ? '/' + t : '')); return; }
    // Pages every employee may open. Anything else is hidden immediately —
    // before its own script can show admin data — until me.flags confirms
    // the Employee role was granted that module on the Roles page.
    const EMPLOYEE_PAGES = ['social_inbox', 'attendance', 'leave', 'my_salary', 'my_penalties', 'my_agreements', 'my_sales', 'orders', 'my_chats', 'wasales', 'my_space', 'my_orders', 'my_day', 'boss_engine', 'boss_inbox', 'daily_summary', 'tracker', 'brain', 'office_care', 'boss_questions', 'boss_penalties', 'dept_hub', 'dept_dispatch', 'customers', 'dept_restock', 'dept_shops', 'dept_supplies', 'dept_work', 'dept_fix', 'people_admin' /*bd-arr*/, 'flyers', 'complaints', 'my_complaints'];
    const needsCheck = !EMPLOYEE_PAGES.includes(active);
    const empOkKey = 'hrms_pok:' + user.username + ':Employee:' + active;
    const empSeenOk = (() => { try { return Date.now() - Number(localStorage.getItem(empOkKey) || 0) < 43200000; } catch (e) { return false; } })();   // already confirmed in the last 12 h: no waiting
    if (needsCheck && !empSeenOk) { document.documentElement.style.visibility = 'hidden'; setTimeout(() => { document.documentElement.style.visibility = ''; }, 2500); }

    document.getElementById('sidebar').outerHTML = `
    <aside class="sidebar">
      <div class="brand">HR<span>MS</span></div>
      <nav>
        <span id="empSalesNav"><a href="my_day.html" class="active">My Day</a></span>
      </nav>
      <div class="user-box">
        <span class="name">${user.username}</span>
        <span class="role">${user.role}</span>
        <button onclick="logout()">Log out</button>
        <span class="build" id="buildTag" style="display:block; margin-top:6px; font-size:11px; color:#64748b; cursor:pointer;" title="layout.js build · api.php build — click for an access check" onclick="window.open(api.buildUrl('diag.access', { token: api.token() }), '_blank')">layout ${LAYOUT_BUILD}</span>
      </div>
    </aside>
  `;
    addMobileMenu();

    startPunchReminder();
    startPenaltyNotice();
    api.get('me.flags').then((d) => {
      if (syncRole(d)) return;
      const perms = d.permissions || [];
      const grantedActives = perms.flatMap(p => (PAGES_FOR_PERM[p] || []).map(def => def[2]));
      const allowed = ['attendance', 'leave', 'my_salary', 'my_penalties', 'my_agreements', 'my_space'].concat(grantedActives);
      allowed.push('my_day', 'boss_engine', 'boss_inbox', 'daily_summary', 'tracker', 'brain', 'office_care', 'boss_questions', 'boss_penalties', 'dept_hub', 'dept_dispatch', 'dept_restock', 'dept_shops', 'dept_supplies', 'dept_work', 'dept_fix', 'people_admin'); /*bd*/
      allowed.push('flyers', 'complaints', 'my_complaints', 'social_inbox', 'staff_progress');   // staff_progress: the server (bd.staff_report) lets in only the complaints boss / top boss / deputy
      if (d.is_sales) allowed.push('my_sales', 'orders', 'my_chats', 'wasales', 'my_orders');
      // Home page after login: sales people open My Sales (their numbers, rating, offer); everyone else opens Attendance. Remembered for index.html.
      try { localStorage.setItem('hrms_home', 'my_day.html'); } catch (e) { /* private mode */ }
      // replace(): the refused page never enters the history, so the back
      // button goes to the previous employee page, not back to this one.
      if (!allowed.includes(active)) { try { localStorage.removeItem(empOkKey); } catch (e) { /* ignore */ } window.location.replace('my_day.html'); return; }
      if (!d.has_salary && active === 'my_salary') { window.location.replace('my_day.html'); return; }
      if (needsCheck) { document.documentElement.style.visibility = ''; try { localStorage.setItem(empOkKey, String(Date.now())); } catch (e) { /* ignore */ } }

      const holder = document.getElementById('empSalesNav');
      if (holder) {
        // Three sections at most: My Sales (home + Allocate order), Orders (allocate + my orders), My Day (punch, duties, and "My records": attendance, leave, salary, penalties, agreements).
        const on = (keys) => (keys.includes(active) ? 'active' : '');
        let links = `
        <a href="my_day.html" class="${on(['my_day', 'attendance', 'leave', 'my_salary', 'my_penalties', 'my_agreements', 'my_space'])}">My Day</a>`;
        if (d.is_sales) {
          links += `
        <a href="my_sales.html" class="${on(['my_sales'])}">My Sales</a>
        <a href="my_chats.html" class="${on(['my_chats'])}">My Chats <span id="chatBadge" style="display:none; background:#e02424; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:6px;"></span></a>
        <a href="my_chats.html?tab=queries" class="${on(['wasales'])}">My Queries</a>
        <a href="wa_rescue.html" class="${on(['wa_rescue'])}">🛟 Rescue Queries</a>
        <a href="my_orders.html" class="${on(['orders', 'order_allocation', 'my_orders'])}">Orders</a>`;
        }
        // Admin-side pages granted to the Employee role via Roles page (Orders / Allocation / Punch / Leave already live in the sections above).
        const morePerms = perms.filter(p => !['punch', 'leave'].includes(p) && !(d.is_sales && ['orders', 'allocation'].includes(p)));
        if (morePerms.length) {
          links += `
        <div class="nav-label">More</div>`;
          morePerms.forEach((p) => {
            (PAGES_FOR_PERM[p] || []).forEach((def) => {
              links += `
        <a href="${def[0]}" class="${active === def[2] ? 'active' : ''}">${def[1]}</a>`;
            });
          });
        }
        holder.outerHTML = links;
        if (d.is_sales) startChatBadge();   // the number of customer messages waiting, next to My Chats
        flyerMenu(active, document.querySelector('.sidebar nav'));
        bossMenu(active, document.querySelector('.sidebar nav')); /*bd*/
        api.get('bd.sx_badge').then((c) => {   // Inbox hub: the Inbox team and the boss
          const nav = document.querySelector('.sidebar nav'); if (!nav || !c || !c.team || document.getElementById('sxNavLink')) return;
          const a3 = document.createElement('a'); a3.id = 'sxNavLink'; a3.href = 'social_inbox.html'; a3.className = active === 'social_inbox' ? 'active' : '';
          const n = (Number(c.late) || 0) || (Number(c.mine) || 0);
          a3.innerHTML = 'Inbox' + (n ? ' <span style="background:#e02424;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:6px;">' + n + '</span>' : '');
          nav.appendChild(a3);
        }).catch(() => {});
        api.get('bd.cx_badge').then((c) => {   // Complaints: the team gets the board, a salesperson who raised one gets the status page
          const nav = document.querySelector('.sidebar nav'); if (!nav || !c || document.getElementById('cxNavLink')) return;
          if (!(c.team || c.has_mine)) return;
          const a2 = document.createElement('a'); a2.id = 'cxNavLink'; a2.href = c.team ? 'complaints.html' : 'my_complaints.html'; a2.className = (active === 'complaints' || active === 'my_complaints') ? 'active' : '';
          const n = c.team ? ((Number(c.my_open) || 0) || (Number(c.late) || 0)) : 0;
          a2.innerHTML = 'Complaints' + (n ? ' <span style="background:#e02424;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:6px;">' + n + '</span>' : '');
          nav.appendChild(a2);
          if (c.mgr && !document.getElementById('spNavLink')) { const a4 = document.createElement('a'); a4.id = 'spNavLink'; a4.href = 'staff_progress.html'; a4.className = active === 'staff_progress' ? 'active' : ''; a4.textContent = 'Staff progress'; nav.appendChild(a4); }   // 📈 the complaints boss: the team's punch, leave and ticket work
        }).catch(() => {});
      }
    }).catch(() => { if (needsCheck) window.location.replace('my_day.html'); });
    return;
  }

  // Sidebar links grouped into collapsible categories. "Main" holds the
  // day-to-day pages and opens by default; the rest start collapsed unless
  // they contain the current page, so navigating straight to e.g.
  // Departments always lands with "Admin" already open.
  let categories = [
    { key: 'main', label: 'Main', defaultOpen: true, links: [
      ['dashboard.html', 'Dashboard', 'dashboard'],
      // ONE Sales item: Live (Control Tower), Orders, People, Retail, Learn & Setup — it replaces the old separate sales pages
      ['sales.html', 'Sales <span id="ctBadge" style="display:none; background:#e02424; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:6px;"></span>', 'sales'],
            ['whatsapp.html', 'WhatsApp', 'whatsapp'],
    ]},
    { key: 'hr', label: 'HR', defaultOpen: true, links: [
      ['attendance_admin.html', 'Attendance', 'attendance_admin'],
      ['leave_requests.html', 'Leave Requests <span id="leaveBadge" style="display:none; background:#e02424; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:6px;"></span>', 'leave_requests'],
      ['offices.html', 'Office Locations', 'offices'],
      ['salary.html', 'Salary', 'salary'],
      ['penalties.html', 'Penalties', 'penalties'],
      ['agreements.html', 'Agreements', 'agreements'],
      ...(isAdmin ? [['approvals.html', 'Approvals <span id="approvalBadge" style="display:none; background:#e02424; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; margin-left:6px;"></span>', 'approvals']] : []),
    ]},
    { key: 'store', label: 'Retail Store', defaultOpen: false, links: [
      ['store_dashboard.html', 'Store Dashboard', 'store_dashboard'],
      ['store_billing.html', 'Store Billing', 'store_billing'],
      ['store_messages.html', 'Store Messages', 'store_messages'],
      ['store_inventory.html', 'Store Inventory', 'store_inventory'],
      ['store_contacts.html', 'Customer follow-up', 'store_contacts'],
      ...(isAdmin ? [['flyer_map.html', 'Flyer Map', 'flyer_map'], ['flyers.html', 'Flyers', 'flyers']] : []),
    ]},
    { key: 'people', label: 'People', defaultOpen: false, links: [
      ['employees.html', 'Employees', 'employees'],
      ['documents.html', 'Documents', 'documents'],
      ['ex_employees.html', 'Ex-Employees', 'ex_employees'],
      ['history.html', 'Decline History', 'history'],
      ['whatsapp_groups.html', 'WhatsApp Groups', 'whatsapp_groups'],
      ['excel_files.html', 'Excel Files', 'excel_files'],
    ]},
    { key: 'admin', label: 'Admin', defaultOpen: false, links: [
      ...(isAdmin ? [['users.html', 'Users', 'users'], ['roles.html', 'Roles', 'roles']] : []),
      ['departments.html', 'Departments', 'departments'],
    ]},
  ].filter(cat => cat.links.length);
  if (window.WS) categories = WS.adminCats(active, SALES_PAGE_KEYS); /*ws*/

  const navHtml = categories.map(cat => {
    const linksHtml = cat.links.map(([href, label, key]) =>
      `<a href="${href}" class="${(key === 'sales' && SALES_PAGE_KEYS.includes(active)) ? 'active' : linkClass(key)}">${label}</a>`
    ).join('');
    return `
        <div class="nav-cat" data-cat="${cat.key}">
          <button type="button" class="nav-label nav-cat-toggle" data-cat-toggle="${cat.key}" style="display:flex; align-items:center; justify-content:space-between; width:100%; background:none; border:none; cursor:pointer; font:inherit; text-align:left;">
            <span>${cat.label}</span>
            <span class="nav-cat-chevron" data-chevron="${cat.key}" style="display:inline-block; transition:transform 0.15s;">&#9662;</span>
          </button>
          <div class="nav-cat-body" data-cat-body="${cat.key}">${linksHtml}</div>
        </div>`;
  }).join('');

  document.getElementById('sidebar').outerHTML = `
    <aside class="sidebar">
      <div class="brand">HR<span>MS</span></div>
      <nav>${navHtml}</nav>
      <div class="user-box">
        <span class="name">${user.username}</span>
        <span class="role">${user.role}</span>
        <button onclick="logout()">Log out</button>
        <span class="build" id="buildTag" style="display:block; margin-top:6px; font-size:11px; color:#64748b; cursor:pointer;" title="layout.js build · api.php build — click for an access check" onclick="window.open(api.buildUrl('diag.access', { token: api.token() }), '_blank')">layout ${LAYOUT_BUILD}</span>
      </div>
    </aside>
  `;

  addMobileMenu();
  initNavCategories(categories, active);
  if (!isAdmin) flyerMenu(active, document.querySelector('[data-cat-body="store"]'));
  if (!window.WS) bossMenu(active, document.querySelector('.sidebar nav')); /*bd*/

  if (isAdmin) {
    api.get('me.flags').then((d) => {
      if (syncRole(d)) return;
      // Admin can apply for leave too, when the login is linked to an employee record.
      if (d && d.has_employee) {
        const body = document.querySelector('[data-cat-body="hr"]');
        if (body && !body.querySelector('a[href="leave.html"]')) {
          const a = document.createElement('a');
          a.href = 'leave.html'; a.textContent = 'My Leave';
          if (active === 'leave') a.className = 'active';
          const lr = body.querySelector('a[href="leave_requests.html"]');
          if (lr && lr.nextSibling) body.insertBefore(a, lr.nextSibling); else body.appendChild(a);
        }
      }
    }).catch(() => {});
    api.get('leave_admin.count').then((d) => {
      const badge = document.getElementById('leaveBadge');
      if (badge && d.count > 0) { badge.textContent = d.count; badge.style.display = 'inline-block'; }
    }).catch(() => {});
    if (document.getElementById('ctBadge')) api.get('ct.pulse').then((d) => {
      const badge = document.getElementById('ctBadge');
      const n = (d && (d.red || 0)) + (d && (d.meet || 0));
      if (badge && n > 0) { badge.textContent = n; badge.style.display = 'inline-block'; }
    }).catch(() => {});
    if (document.getElementById('stockBadge')) api.get('retail.stock_alerts', { badge: 1 }).then((d) => {
      const badge = document.getElementById('stockBadge');
      if (badge && d.unread > 0) { badge.textContent = d.unread; badge.style.display = 'inline-block'; }
    }).catch(() => {});
    api.get('approvals.count').then((d) => {
      const badge = document.getElementById('approvalBadge');
      if (badge && d.count > 0) {
        badge.textContent = d.count;
        badge.style.display = 'inline-block';
      }
    }).catch(() => {});
  }
}

/* ===== Icon rail (look & feel only) =====
   renderSidebarCore() above builds the menu exactly as before (permissions,
   Employee / custom-role variants, groups). renderSidebar() below runs it and
   then dresses the result: logo on top, an icon + label for every link,
   avatar and log-out at the bottom, and a wide/narrow toggle. Links that
   arrive later (custom roles and employees get theirs after me.flags) are
   dressed by a MutationObserver. */
const RAIL_ICONS = {
  drop: '<path d="M12 3c3 4 6.5 7 6.5 11a6.5 6.5 0 01-13 0C5.5 10 9 7 12 3z"/><path d="M9 14a3 3 0 003 3"/>',
  home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
  bag: '<path d="M6 7h12l1 13H5L6 7z"/><path d="M9 7a3 3 0 016 0"/>',
  split: '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="19" r="2.5"/><path d="M6 8.5c0 4 6 3 6 8M18 8.5c0 4-6 3-6 8"/>',
  tag: '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
  shop: '<path d="M4 9l1.5-5h13L20 9"/><path d="M4 9a2.7 2.7 0 005.3 0 2.7 2.7 0 005.4 0A2.7 2.7 0 0020 9"/><path d="M5 12v8h14v-8"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  pin: '<path d="M12 21s7-6.2 7-11.5A7 7 0 005 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
  target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/>',
  cart: '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.4 12h11l2-8H6"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
  query: '<circle cx="12" cy="8" r="3.5"/><path d="M5 21c0-4 3-6.5 7-6.5s7 2.5 7 6.5"/><path d="M19 3v4M17 5h4"/>',
  wallet: '<path d="M3 7a2 2 0 012-2h13v4"/><path d="M3 7v11a2 2 0 002 2h15V9H5a2 2 0 01-2-2z"/><circle cx="16.5" cy="14.5" r="1.2"/>',
  percent: '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
  sliders: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
  alert: '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h0"/>',
  inbox: '<path d="M3 13l3-8h12l3 8v6H3z"/><path d="M3 13h5l1.5 2.5h5L16 13h5"/>',
  file: '<path d="M7 3h7l5 5v13H7V3z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
  building: '<path d="M4 21V5l8-2v18M12 8h8v13M4 21h16"/><path d="M8 9h0M8 13h0M8 17h0M16 12h0M16 16h0"/>',
  users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.8-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14c2.2.6 3.5 2.4 3.5 6"/>',
  folder: '<path d="M3 6a2 2 0 012-2h4l2 2h8a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V6z"/>',
  chat: '<path d="M4 5h16v11H9l-5 4V5z"/>',
  sheet: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M4 15h16M10 3v18"/>',
  userx: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.8-6 6.5-6s6.5 2.4 6.5 6"/><path d="M17 9l4 4M21 9l-4 4"/>',
  history: '<path d="M3 12a9 9 0 109-9 9 9 0 00-7 3.4L3 9"/><path d="M3 4v5h5M12 8v4l3 2"/>',
  check: '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
  calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M9 14.5l2 2 4-4"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.2 3.6-7 8-7s8 2.8 8 7"/>',
  lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>',
  dot: '<circle cx="12" cy="12" r="3"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  out: '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
  chev: '<path d="M9 6l6 6-6 6"/>',
  truck: '<path d="M3 6.5h11v9H3zM14 9.5h4l3 3v3h-7z"/><circle cx="7.5" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
  tower: '<path d="M12 3v3M8 21l1.5-11h5L16 21M7 21h10M9.5 10h5M10 6h4l.5 4h-5z"/>',
  briefcase: '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M9 7V5a1.5 1.5 0 011.5-1.5h3A1.5 1.5 0 0115 5v2M3 13h18"/>',
  pulse: '<path d="M3 12h4l2.5-6 5 12 2.5-6H21"/>',
};
const RAIL_ICON_FOR = {
  inventory: 'drop',
  complaints: 'alert', my_complaints: 'alert', social_inbox: 'inbox', dashboard: 'home', dispatch: 'truck', finance: 'wallet', analytics: 'chart', sales: 'briefcase', control_tower: 'tower', orders: 'bag', order_allocation: 'split', order_codes: 'tag', retail_shops: 'shop', retail_inventory: 'folder', retail_store: 'shop', flyers: 'file', flyer_map: 'pin', my_day: 'file', boss_engine: 'file', boss_inbox: 'file', daily_summary: 'file', office_care: 'file', boss_questions: 'file', boss_penalties: 'file', dept_hub: 'file', dept_dispatch: 'file', customers: 'user', dept_restock: 'file', dept_shops: 'file', dept_supplies: 'file', dept_work: 'file', dept_fix: 'file', people_admin: 'file',
  attendance_admin: 'clock', attendance: 'clock', my_space: 'user', my_orders: 'bag', offices: 'pin', targets: 'target', order_targets: 'cart',
  performance: 'chart', detailed_report: 'sheet', salary: 'wallet', my_salary: 'wallet', commission: 'percent',
  commission_rules: 'sliders', help: 'pulse', my_chats: 'chat', wasales: 'chart', penalties: 'alert', my_penalties: 'alert', agreements: 'file', my_agreements: 'file',
  departments: 'building', employees: 'users', documents: 'folder', whatsapp_groups: 'chat', whatsapp: 'chat', excel_files: 'sheet',
  ex_employees: 'userx', history: 'history', approvals: 'check', users: 'user', roles: 'lock', my_sales: 'cart', leave: 'calendar', leave_requests: 'calendar',
};
function railSvg(name) {
  return '<svg viewBox="0 0 24 24" aria-hidden="true">' + (RAIL_ICONS[name] || RAIL_ICONS.dot) + '</svg>';
}

function setupRail() {
  const side = document.querySelector('aside.sidebar');
  if (!side || side.dataset.rail) return;
  side.dataset.rail = '1';
  const user = api.currentUser() || { username: '', role: '' };
  const mq = window.matchMedia('(max-width: 820px)');
  const KEY = 'hrms_rail_wide';
  let wide = false;
  try { wide = localStorage.getItem(KEY) === '1'; } catch (e) {}

  // Brand: logo tile (falls back to "HR" if the icon file is missing) + name when wide.
  const brand = side.querySelector('.brand');
  if (brand) {
    brand.innerHTML = '<img src="icons/apple-touch-icon.png" alt="" onerror="this.outerHTML=\'<span class=&quot;logo-fb&quot;>HR</span>\'">'
      + '<span class="brand-txt">HR<span>MS</span></span>';
  }

  // User box: avatar + (wide) name/role + log-out icon. The original buttons stay in the DOM, hidden.
  const box = side.querySelector('.user-box');
  if (box) {
    const nm = String(user.username || '?').trim();
    const ini = nm.split(/[\s._-]+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
    box.insertAdjacentHTML('afterbegin', '<span class="avatar" title="' + nm.replace(/"/g, '') + ' · ' + String(user.role || '') + '">' + ini + '</span>');
    const nameEl = box.querySelector('.name'), roleEl = box.querySelector('.role');
    const who = document.createElement('span');
    who.className = 'who';
    if (nameEl) who.appendChild(nameEl);
    if (roleEl) who.appendChild(roleEl);
    box.querySelector('.avatar').after(who);
    const out = document.createElement('button');
    out.type = 'button'; out.className = 'rail-out'; out.title = 'Log out'; out.setAttribute('aria-label', 'Log out');
    out.innerHTML = railSvg('out');
    out.addEventListener('click', () => { if (typeof logout === 'function') logout(); });
    box.appendChild(out);
  }

  // Wide / narrow toggle (desktop).
  const tools = document.createElement('div');
  tools.className = 'rail-tools';
  tools.innerHTML = '<button type="button" id="railToggle" title="Show / hide page names" aria-label="Show or hide page names">' + railSvg('chev') + '</button>';
  const nav = side.querySelector('nav');
  if (nav) nav.after(tools);
  tools.querySelector('button').addEventListener('click', () => {
    wide = !wide;
    try { localStorage.setItem(KEY, wide ? '1' : '0'); } catch (e) {}
    apply();
  });

  function apply() {
    const phone = mq.matches;
    side.classList.toggle('wide', phone || wide);
    document.body.classList.toggle('rail-wide', !phone && wide);
    tools.style.display = phone ? 'none' : '';
  }
  apply();
  if (mq.addEventListener) mq.addEventListener('change', apply); else if (mq.addListener) mq.addListener(apply);

  // Dress every link: icon + label + tooltip. Idempotent, so it can re-run.
  function dress() {
    side.querySelectorAll('nav a').forEach((a) => {
      if (a.querySelector(':scope > .ico')) return;
      const file = (a.getAttribute('href') || '').split('?')[0].replace(/\.html$/, '');
      const key = (window.WS && WS.iconKey(a)) || (file === 'history' ? 'history' : file); /*ws*/
      const label = a.textContent.replace(/\s+/g, ' ').trim();
      const lbl = document.createElement('span');
      lbl.className = 'lbl';
      while (a.firstChild) lbl.appendChild(a.firstChild);
      const ico = document.createElement('span');
      ico.className = 'ico';
      ico.innerHTML = railSvg(RAIL_ICON_FOR[key] || 'dot');
      a.appendChild(ico);
      a.appendChild(lbl);
      const bd = lbl.querySelector('#approvalBadge, #leaveBadge, #stockBadge, #ctBadge, #chatBadge, .ws-badge');
      if (bd) a.appendChild(bd);   // keep the count visible on the narrow rail
      a.setAttribute('data-tip', label);
      a.setAttribute('aria-label', label);
    });
    // Phone: tapping a link closes the drawer.
  }
  dress();

  // Keep the menu where it was: remember how far it is scrolled and restore that
  // on the next page, so opening a page never throws the rail back to the top.
  // If the current page's icon would still be out of sight, bring it into view.
  const SKEY = 'hrms_rail_scroll';
  let userScrolled = false;
  function restoreScroll() {
    if (!nav) return;
    let y = 0;
    try { y = parseInt(localStorage.getItem(SKEY) || '0', 10) || 0; } catch (e) {}
    nav.scrollTop = y;
    const act = nav.querySelector('a.active');
    if (act) {
      const n = nav.getBoundingClientRect(), r = act.getBoundingClientRect();
      if (r.top < n.top || r.bottom > n.bottom) act.scrollIntoView({ block: 'center' });
    }
  }
  if (nav) {
    restoreScroll();
    requestAnimationFrame(restoreScroll);
    nav.addEventListener('scroll', () => {
      userScrolled = true;
      try { localStorage.setItem(SKEY, String(Math.round(nav.scrollTop))); } catch (e) {}
    }, { passive: true });
    window.addEventListener('pagehide', () => {
      try { localStorage.setItem(SKEY, String(Math.round(nav.scrollTop))); } catch (e) {}
    });
  }
  // Tooltip: one floating label, placed from the hovered icon's real position,
  // so it always sits beside that icon however far the menu is scrolled.
  // The tooltip's own styles live here too (and the old CSS-only tooltip is switched off),
  // so this file alone is enough to fix a tooltip that drifts away from its icon.
  if (!document.getElementById('railTipCss')) {
    const st = document.createElement('style'); st.id = 'railTipCss';
    st.textContent = '.sidebar a[data-tip]:hover::after{display:none !important;content:none !important}'
      + '.rail-tip{position:fixed;z-index:2000;background:var(--ink,#111827);color:#fff;font-size:12px;font-weight:600;white-space:nowrap;padding:6px 11px;border-radius:10px;pointer-events:none;transform:translateY(-50%);opacity:0;transition:opacity .12s}'
      + '.rail-tip.show{opacity:1}@media (max-width:820px){.rail-tip{display:none !important}}';
    document.head.appendChild(st);
  }
  let tip = document.getElementById('railTip');
  if (!tip) { tip = document.createElement('div'); tip.id = 'railTip'; tip.className = 'rail-tip'; document.body.appendChild(tip); }
  const hideTip = () => tip.classList.remove('show');
  side.addEventListener('mouseover', (e) => {
    const a = e.target.closest && e.target.closest('nav a[data-tip]');
    if (!a || mq.matches || side.classList.contains('wide')) { hideTip(); return; }
    const r = a.getBoundingClientRect(), sr = side.getBoundingClientRect();
    tip.textContent = a.getAttribute('data-tip');
    tip.style.left = Math.round(sr.right + 8) + 'px';
    tip.style.top = Math.round(r.top + r.height / 2) + 'px';
    tip.classList.add('show');
  });
  side.addEventListener('mouseleave', hideTip);
  if (nav) nav.addEventListener('scroll', hideTip, { passive: true });
  window.addEventListener('blur', hideTip);

  // Links added later (custom roles / employees) or a width change: re-apply, unless the person has scrolled meanwhile.
  new MutationObserver(() => { dress(); if (!userScrolled) restoreScroll(); }).observe(side, { childList: true, subtree: true });
}

// Opened inside the Sales page (sales.html loads each sales page in a frame with ?in=sales): no sidebar / menu of its own —
// the Sales page around it has them. Everything else on the page (its data, filters, pop-ups) works exactly as before.
const IN_SALES = (function () { try { return window.parent !== window && (/[?&]in=sales(&|$)/.test(location.search) || !!window.parent.EMP_HUB || /\/(hub|sales)\.html$/.test(window.parent.location.pathname)); } catch (e) { return false; } })();   // also true on a page opened by a link inside a workspace frame (e.g. Departments > Parcels): that link has no ?in=sales
if (IN_SALES) {
  document.documentElement.classList.add('in-sales');
  const st = document.createElement('style');
  st.textContent = 'html.in-sales .sidebar, html.in-sales #sidebar, html.in-sales #menuToggle, html.in-sales #sidebarBackdrop, html.in-sales .rail-tools, html.in-sales .topbar > h1, html.in-sales .topbar > div > h1{display:none !important;}'
    + 'html.in-sales .main{margin-left:0 !important; width:100%; max-width:100%;}'
    + 'html.in-sales body, html.in-sales .app{background:transparent;}'
    + 'html.in-sales .topbar{padding-top:8px; min-height:0;}'
    + 'html.in-sales .content{padding-top:8px;}';
  (document.head || document.documentElement).appendChild(st);
}

function loadCommissionRuleScripts() {
  if (document.getElementById('codeRulesJs')) return;
  const add = (id, src) => { const sc = document.createElement('script'); sc.id = id; sc.src = src; sc.async = false; document.body.appendChild(sc); };
  // The "Order code rules" section lives in its own file and adds itself to the Commission Rules page.
  add('codeRulesJs', 'assets/js/code_rules.js');
  // The manual rules (order-rating rules, order-code rules, fixed commission) — they used to be on the Commission page.
  add('manualRulesJs', 'assets/js/manual_rules.js?v=261013a');
  // Target bonus rules (commission + commission × a step %, once the target of chosen dates is reached): its own card, under the manual rules.
  add('bonusRulesJs', 'assets/js/bonus_rules.js?v=1');
  // The ML price slab chart (order size + price → commission %): its own card, under the other rules.
  add('mlSlabJs', 'assets/js/ml_slab.js?v=261103y');
  add('mlSlabRulesJs', 'assets/js/ml_slab_rules.js?v=261103y');
  // Prices (perfume rows, attar / shower gel / others, combos) are edited in Learn & Setup → Pricing. The old combo price chart card is gone: nothing used it
  // and it clashed with the Combos of the Price chart. The ML price slab card stays here for its commission settings (on / off, cap, bonus, no-code %, special days).
}

function renderSidebar(active) {
  if (IN_SALES) {
    const sb = document.getElementById('sidebar'); if (sb) sb.remove();
    if (active === 'commission_rules') loadCommissionRuleScripts();
    return;
  }
  if (window.WS && WS.redirect(active)) return; /*ws*/
  renderSidebarCore(active);
  if (window.WS && WS.employeeNav) WS.employeeNav(active); /*ws*/
  try { setupRail(); } catch (e) { /* styling only — never block the menu */ }
  if (active === 'commission_rules') loadCommissionRuleScripts();
}

function initNavCategories(categories, active) {
  const STORAGE_KEY = 'hrms_nav_open';
  let stored = {};
  try { stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { stored = {}; }

  const activeCat = categories.find(cat => cat.links.some(link => link[2] === active));

  categories.forEach(cat => {
    const isOpen = activeCat && cat.key === activeCat.key
      ? true
      : (stored[cat.key] !== undefined ? stored[cat.key] : cat.defaultOpen);
    applyNavCatState(cat.key, isOpen);
  });

  document.querySelectorAll('[data-cat-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.catToggle;
      const body = document.querySelector(`[data-cat-body="${key}"]`);
      const nowOpen = body.style.display === 'none';
      applyNavCatState(key, nowOpen);
      stored[key] = nowOpen;
      localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
    });
  });
}

function applyNavCatState(key, isOpen) {
  const body = document.querySelector(`[data-cat-body="${key}"]`);
  const chevron = document.querySelector(`[data-chevron="${key}"]`);
  if (body) body.style.display = isOpen ? 'block' : 'none';
  if (chevron) chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
}

function addMobileMenu() {
  if (!document.getElementById('menuToggle')) {
    document.body.insertAdjacentHTML('beforeend', `
      <button id="menuToggle" aria-label="Open menu">&#9776;</button>
      <div id="sidebarBackdrop"></div>
    `);
    document.getElementById('menuToggle').addEventListener('click', () => {
      document.body.classList.toggle('sidebar-open');
    });
    document.getElementById('sidebarBackdrop').addEventListener('click', () => {
      document.body.classList.remove('sidebar-open');
    });
  }
}
/**
 * Generic responsive tables.
 *
 * Stamps every <td> with a data-label taken from its column's <th>, so CSS
 * can render each row as a labelled card on narrow screens without every
 * page needing its own hand-built card markup. Rows are usually injected by
 * each page's own JS long after load, so a MutationObserver re-stamps
 * whenever a tbody changes.
 *
 * Tables that already ship a purpose-built mobile card view (Orders,
 * Attendance, Targets, Employees, Performance) hide their <table> entirely
 * at the same breakpoint, so this never competes with them.
 */
(function responsiveTables() {
  function stamp(table) {
    const heads = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
    if (!heads.length) return;
    table.querySelectorAll('tbody tr').forEach(tr => {
      // Skip grouping/spanning rows (day headers, "no results" messages):
      // their single cell spans the table and has no one column to name.
      const cells = tr.children;
      if (cells.length !== heads.length) return;
      [...cells].forEach((td, i) => {
        if (heads[i]) td.setAttribute('data-label', heads[i]);
      });
    });
  }

  function stampAll() {
    document.querySelectorAll('table').forEach(stamp);
  }

  function watch() {
    stampAll();
    const obs = new MutationObserver((records) => {
      const touched = new Set();
      records.forEach(r => {
        const t = r.target.closest && r.target.closest('table');
        if (t) touched.add(t);
      });
      touched.forEach(stamp);
    });
    document.querySelectorAll('tbody').forEach(tb => {
      obs.observe(tb, { childList: true, subtree: true });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watch);
  } else {
    watch();
  }
})();

/**
 * Uploaded files open through api.php (files.view), app-wide.
 *
 * Direct links like …/empmanagment/uploads/doc_158_….png return 404 on this
 * hosting, and files uploaded before the domain move sit in the old domain's
 * folder. Every page builds those direct links (Approvals "View", employee
 * documents, photos…), so rather than edit each page this intercepts them:
 *  - clicking a link into /uploads/ fetches the file with the login token.
 *    Photos open in a viewer on the same page (never a new tab: inside the
 *    installed phone app a new tab is outside the app, and Back there closes
 *    the whole app). Other files open in a new tab in the browser, and are
 *    saved to the phone when the page runs as the installed app;
 *  - <img> tags pointing into /uploads/ are loaded the same way.
 */
(function uploadsViaApi() {
  if (typeof api === 'undefined' || typeof API_BASE_URL === 'undefined') return;

  const relOf = (url) => {
    const i = String(url || '').indexOf('/uploads/');
    return i === -1 ? null : decodeURIComponent(String(url).slice(i + 1).split('?')[0].split('#')[0]);
  };
  const fetchBlob = async (rel, w) => {
    const res = await fetch(api.buildUrl('files.view', w ? { path: rel, w } : { path: rel }), {
      headers: api.token() ? { Authorization: 'Bearer ' + api.token() } : {},
    });
    if (!res.ok) {
      let msg = `Could not open the file (HTTP ${res.status}).`;
      try { const j = await res.json(); if (j.error) msg = j.error; } catch (e) {}
      throw new Error(msg);
    }
    return res.blob();
  };

  // ---- links ----
  const IMG_RE = /\.(jpe?g|png|gif|webp|bmp|avif|heic|heif)$/i;
  const installedApp = () => {
    try { return ['standalone', 'fullscreen', 'minimal-ui'].some((m) => window.matchMedia('(display-mode: ' + m + ')').matches) || navigator.standalone === true; }
    catch (e) { return false; }
  };
  const baseName = (rel) => decodeURIComponent(String(rel).split('/').pop() || 'file');
  const saveBlob = (blob, name) => {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name; a.style.display = 'none';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  };

  // Photo viewer: a full-screen layer on the same page. Close button, a tap outside the photo, or the
  // phone's Back button closes it (layout.js's Back handling treats it like any other pop-up).
  let viewer = null;
  function closeViewer() {
    if (!viewer) return;
    const v = viewer; viewer = null;
    if (v.url) URL.revokeObjectURL(v.url);
    v.el.remove();
  }
  function openViewer(rel) {
    closeViewer();
    const el = document.createElement('div');
    el.className = 'modal-overlay open';
    el.id = 'hrmsViewer';
    el.style.cssText = 'background:rgba(0,0,0,.94); padding:0; z-index:3000; flex-direction:column; align-items:stretch; justify-content:flex-start;';
    const btn = 'border:0; border-radius:8px; padding:0 16px; min-height:40px; font:600 14px system-ui; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; background:#fff; color:#111;';
    el.innerHTML = `<div style="display:flex; gap:10px; align-items:center; justify-content:space-between; padding:10px 12px;">
        <span style="color:#fff; font:600 14px system-ui; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></span>
        <span style="display:flex; gap:8px; flex:none;"><a data-v="dl" style="${btn} display:none;">Download</a><button type="button" data-v="x" style="${btn}">Close</button></span>
      </div>
      <div data-v="body" style="flex:1; min-height:0; overflow:auto; display:flex; align-items:center; justify-content:center; padding:0 8px 12px; color:#fff; font:14px system-ui; text-align:center;">Opening photo…</div>`;
    el.firstElementChild.firstElementChild.textContent = baseName(rel);
    const body = el.querySelector('[data-v="body"]');
    el.querySelector('[data-v="x"]').addEventListener('click', closeViewer);
    el.addEventListener('click', (e) => { if (e.target === el || e.target === body) closeViewer(); });
    document.body.appendChild(el);
    const v = viewer = { el, url: null };
    return {
      show(blob) {
        if (viewer !== v) return;                       // closed while it was loading
        v.url = URL.createObjectURL(blob);
        const img = document.createElement('img');
        const fit = 'max-width:100%; max-height:100%; object-fit:contain; border-radius:6px; cursor:zoom-in;';
        img.style.cssText = fit; img.alt = baseName(rel); img.src = v.url;
        let zoom = false;
        img.addEventListener('click', () => {               // tap = full size (scroll to look around), tap again = fit
          zoom = !zoom;
          img.style.cssText = zoom ? 'max-width:none; max-height:none; border-radius:6px; cursor:zoom-out; margin:auto;' : fit;
        });
        body.textContent = ''; body.appendChild(img);
        const dl = el.querySelector('[data-v="dl"]');
        dl.href = v.url; dl.download = baseName(rel); dl.style.display = 'inline-flex';
      },
      fail(msg) { if (viewer === v) body.textContent = msg; },
    };
  }

  document.addEventListener('click', async (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    const rel = relOf(a.getAttribute('href'));
    if (!rel) return;
    e.preventDefault();

    // Photos: shown on this page.
    if (IMG_RE.test(rel)) {
      const view = openViewer(rel);
      try { view.show(await fetchBlob(rel)); } catch (err) { view.fail(err.message); }
      return;
    }
    // Other files in the installed app: save to the phone — a new tab would be outside the app.
    if (installedApp()) {
      try { saveBlob(await fetchBlob(rel), baseName(rel)); } catch (err) { alert(err.message); }
      return;
    }

    // Browser: open the tab now, inside the click, so popup blockers allow it.
    const win = window.open('', '_blank');
    if (win) win.document.write('<p style="font:14px system-ui; padding:24px; color:#555;">Opening file…</p>');
    try {
      const url = URL.createObjectURL(await fetchBlob(rel));
      if (win) win.location.href = url; else window.location.href = url;
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (err) {
      if (win) {
        win.document.body.innerHTML = '';
        const p = win.document.createElement('p');
        p.style.cssText = 'font:14px system-ui; padding:24px; color:#b42318;';
        p.textContent = err.message;
        win.document.body.appendChild(p);
      } else {
        alert(err.message);
      }
    }
  }, true);

  // ---- images ----
  const cache = new Map(); // rel -> Promise<objectURL>
  // Pictures load only when they come into view (a list of 50 people no longer asks for 50 photos at once), and an
  // avatar-sized picture is fetched as a small thumbnail (?w=) instead of the full-size photo.
  const lazy = ('IntersectionObserver' in window) ? new IntersectionObserver((es) => {
    es.forEach((e) => { if (e.isIntersecting) { lazy.unobserve(e.target); loadImg(e.target); } });
  }, { rootMargin: '300px' }) : null;
  const thumbW = (img) => {
    const m = /(?:^|;|\s)(?:max-)?width:\s*(\d+)px/.exec(img.getAttribute('style') || '');
    const w = m ? +m[1] : (+img.getAttribute('width') || 0);
    return w && w <= 120 ? Math.min(256, w * 3) : 0;
  };
  const loadImg = (img) => {
    const rel = img.dataset.upRel, w = +img.dataset.upW || 0, key = rel + '|' + w;
    if (!cache.has(key)) cache.set(key, fetchBlob(rel, w).then(b => URL.createObjectURL(b)));
    cache.get(key).then(u => { img.src = u; }).catch(() => { img.style.visibility = 'hidden'; });
  };
  const fixImg = (img) => {
    if (img.dataset.upFixed) return;
    const rel = relOf(img.getAttribute('src'));
    if (!rel) return;
    img.dataset.upFixed = '1'; img.dataset.upRel = rel; img.dataset.upW = String(thumbW(img));
    img.removeAttribute('src');   // stop the browser asking for the direct link (it 404s on this hosting)
    if (lazy) lazy.observe(img); else loadImg(img);
  };
  const scan = (root) => {
    if (root.tagName === 'IMG') fixImg(root);
    root.querySelectorAll && root.querySelectorAll('img[src*="/uploads/"]').forEach(fixImg);
  };
  const start = () => {
    scan(document);
    new MutationObserver(recs => recs.forEach(r => {
      r.addedNodes.forEach(n => n.nodeType === 1 && scan(n));
      if (r.type === 'attributes' && r.target.tagName === 'IMG') { delete r.target.dataset.upFixed; fixImg(r.target); }
    })).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

/**
 * Back button (installed app / phone).
 *
 * A pop-up (month view, photo, checklist, ...), a day popover or the phone
 * menu is only a layer on the SAME page, so the browser has no history entry
 * for it. Pressing Back then leaves the page — and from the app's first page
 * that closes the whole app. Here every layer that opens adds one history
 * entry, and Back closes the top-most layer instead. Closing a layer with its
 * own Close / Cancel button (or by tapping outside it) removes its entry again,
 * so the history never fills up with leftovers.
 */
(function () {
  const LAYERS = '.modal-overlay, .daypop';
  const MENU = 'menu';                       // the phone menu (body.sidebar-open)
  const MARK = { hrmsLayer: 1 };
  const stack = [];                          // open layers, oldest first — each owns one history entry
  let ignore = 0;                            // history moves made by this code itself (not a Back press)
  let pendingNav = null, navTimer = null;

  const isOn = (l) => (l === MENU ? document.body.classList.contains('sidebar-open') : !!(l.isConnected && getComputedStyle(l).display !== 'none'));
  function liveLayers() {
    const on = [];
    document.querySelectorAll(LAYERS).forEach((el) => { if (isOn(el)) on.push(el); });
    if (isOn(MENU)) on.push(MENU);
    return on;
  }
  function push() { try { history.pushState(MARK, ''); } catch (e) {} }

  // Brings the history in line with the layers that are open right now.
  function sync() {
    const on = liveLayers();
    const closed = stack.filter((l) => !on.includes(l));
    const opened = on.filter((l) => !stack.includes(l));
    // One layer handing over to another in the same moment keeps its entry.
    while (closed.length && opened.length) stack[stack.indexOf(closed.shift())] = opened.shift();
    if (closed.length) {
      closed.forEach((l) => stack.splice(stack.indexOf(l), 1));
      ignore++;
      try { history.go(-closed.length); } catch (e) { ignore--; }
    }
    opened.forEach((l) => { stack.push(l); push(); });
  }

  // Closes a layer the way the page itself would: its outside-tap handler, then its Close / Cancel button.
  function close(l) {
    if (l === MENU) { document.body.classList.remove('sidebar-open'); return; }
    if (l.classList.contains('modal-overlay')) l.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    if (!isOn(l)) return;
    const b = [...l.querySelectorAll('button, a.btn')].find((x) => /^(close|cancel|done|not now|no|×|✕|✖|x)$/i.test((x.textContent || '').trim()) || (x.dataset && x.dataset.dp === 'x'));
    if (b) b.click();
    if (!isOn(l)) return;
    if (l.classList.contains('modal-overlay')) l.classList.remove('open'); else l.remove();
  }

  function go() {
    clearTimeout(navTimer);
    const h = pendingNav; pendingNav = null;
    if (h) location.href = h;
  }

  window.addEventListener('popstate', () => {
    if (ignore > 0) { ignore--; if (!ignore && pendingNav) go(); return; }
    // Back on the FIRST screen of the app (nothing in HRMS to go back to): open the ☰ menu instead of leaving the app — Back again closes it
    if (history.state && history.state.hrmsRoot && !stack.length) {
      if (document.querySelector('.sidebar, #sidebar')) { try { history.pushState({ hrmsTop: 1 }, ''); } catch (e) {} document.body.classList.add('sidebar-open'); return; }
      try { history.back(); } catch (e) {}   // this page has no menu: leave as usual
      return;
    }
    const top = stack.pop();
    if (top === undefined) return;           // nothing of ours is open: an ordinary Back between pages
    close(top);
    if (isOn(top)) { stack.push(top); push(); }   // it would not close — keep its entry
    sync();                                  // layers that closed along with it
  });

  // Opening a page from the phone menu: drop the menu's entry first, so the new page sits right after this one.
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('.sidebar a[href], #sidebar a[href]');
    if (!a || !stack.includes(MENU) || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || (a.target && a.target !== '_self')) return;
    const h = a.href || '';
    if (!/^https?:/i.test(h) || (a.hash && a.pathname === location.pathname && a.search === location.search)) return;
    e.preventDefault();
    pendingNav = h;
    document.body.classList.remove('sidebar-open');
    sync();
    clearTimeout(navTimer); navTimer = setTimeout(go, 500);   // safety: never get stuck on this page
  }, true);

  const relevant = (r) => {
    const t = r.target;
    if (t === document.body) return true;    // sidebar-open
    if (r.type === 'attributes') return !!(t.closest && t.closest(LAYERS));
    for (const n of [...r.addedNodes, ...r.removedNodes]) if (n.nodeType === 1 && (n.matches(LAYERS) || n.querySelector(LAYERS))) return true;
    return false;
  };
  const start = () => {
    // Landed on an entry this code added earlier (page reloaded, or came back from another page): step off it.
    if (history.state && history.state.hrmsLayer && !stack.length) { ignore++; try { history.back(); } catch (e) { ignore--; } }
    // Phone: when this page is the first HRMS screen (opened from the app icon, a notification or the login), keep one entry under it so Back opens the menu
    try {
      const phone = window.matchMedia('(max-width: 900px)').matches || window.matchMedia('(display-mode: standalone)').matches;
      let fromApp = false; try { const r = new URL(document.referrer); fromApp = r.origin === location.origin && !/\/(index\.html)?$/i.test(r.pathname); } catch (e) { fromApp = false; }
      if (window.top === window && phone && !fromApp && !(history.state && (history.state.hrmsRoot || history.state.hrmsTop || history.state.hrmsLayer))) {
        history.replaceState({ hrmsRoot: 1 }, ''); history.pushState({ hrmsTop: 1 }, '');
      }
    } catch (e) { /* Back simply works as before */ }
    new MutationObserver((recs) => { if (recs.some(relevant)) sync(); })
      .observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style'] });
    sync();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

/* Boss Engine 2: the Claude button on every page (top window only; managers get Ask / Add task / Report / This section, staff get Report a problem) */
(function () {
  try { if (window.self !== window.top) return; } catch (e) { return; }
  // The floating Claude button is OFF for everyone; the Admin switches it on per person (WhatsApp → Team & routing → "Claude button"). The answer is remembered for 10 minutes.
  const put = () => { const s = document.createElement('script'); s.src = 'assets/js/b2_chip.js?v=t6'; s.defer = true; (document.head || document.documentElement).appendChild(s); };
  let on = null, at = 0; try { on = localStorage.getItem('hrms_hq_on'); at = +localStorage.getItem('hrms_hq_at') || 0; } catch (e) { }
  if (on !== null && Date.now() - at < 600000) { if (on === '1') put(); return; }
  if (typeof api === 'undefined' || !api.token || !api.token()) return;
  api.get('wa.g_hq_me').then((d) => { try { localStorage.setItem('hrms_hq_on', d && d.on ? '1' : '0'); localStorage.setItem('hrms_hq_at', String(Date.now())); } catch (e) { } if (d && d.on) put(); }).catch(() => { if (on === '1') put(); });
})();