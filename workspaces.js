/* Workspaces v2: the whole HRMS menu is 8 icons (Home, Sales, WhatsApp, Boss, Store, People, Offices, Admin). Each icon opens ONE page (hub.html) with the pages of that area as tabs.
   This block is pasted into assets/js/layout.js by ws_install.php (between the WS-START and WS-END marks) - it is not loaded as a separate file. */
window.WS = (function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // tab = [key, label, page, need]   need: '' = anyone whose role allows the page | admin | boss | fl | flm | emp
  const W = [
    { key: 'home', label: 'Home', icon: 'dashboard', sub: 'What needs you today', tabs: [['dashboard', 'Dashboard', 'dashboard.html', '']] },
    { key: 'sales', label: 'Sales', icon: 'sales', direct: 'sales.html', sub: '', tabs: [] },
    { key: 'whatsapp', label: 'WhatsApp', icon: 'whatsapp', sub: 'Chats, campaigns and groups', tabs: [['whatsapp', 'WhatsApp', 'whatsapp.html', 'admin'], ['whatsapp_groups', 'Groups', 'whatsapp_groups.html', '']] },
    { key: 'boss', label: 'Boss', icon: 'approvals', sub: 'Duties, checks and penalties', tabs: [['daily_summary', 'Summary', 'daily_summary.html', 'boss'], ['boss_board', 'Today', 'boss_board.html', 'boss'], ['boss_inbox', 'Inbox', 'boss_inbox.html', 'boss'], ['boss_questions', 'Questions', 'boss_questions.html', 'boss'], ['boss_duties', 'Duties', 'boss_duties.html', 'boss'], ['dept_hub', 'Departments', 'dept_hub.html', 'boss'], ['boss_penalties', 'Penalties', 'boss_penalties.html', 'boss'], ['boss_setup', 'Setup', 'boss_setup.html', 'boss']] },
    { key: 'store', label: 'Store', icon: 'retail_store', sub: 'Everything about the store', tabs: [['store_dashboard', 'Dashboard', 'store_dashboard.html', 'rsm'], ['store_billing', 'Billing', 'store_billing.html', 'rs'], ['store_messages', 'Messages', 'store_messages.html', 'rs'], ['store_team', 'Team', 'store_team.html', 'rsm'], ['store_commission', 'Store Commission', 'store_commission.html', 'rsm'], ['store_inventory', 'Inventory', 'store_inventory.html', 'rs'], ['store_cleaning', 'Cleaning & Pantry', 'store_cleaning.html', 'boss'], ['store_contacts', 'Customer follow-up', 'store_contacts.html', 'rsm'], ['flyers', 'Flyers', 'flyers.html', 'fl'], ['flyer_map', 'Flyer Map', 'flyer_map.html', 'flm'], ['store_logins', 'Store logins', 'store_logins.html', 'admin']] },
    { key: 'people', label: 'People', icon: 'employees', sub: 'Everyone who works here', tabs: [['employees', 'Employees', 'employees.html', ''], ['attendance_admin', 'Attendance', 'attendance_admin.html', ''], ['punch', 'Punch In / Out', 'attendance.html', 'punch'], ['leave_requests', 'Leave', 'leave_requests.html', ''], ['salary', 'Salary', 'salary.html', ''], ['penalties', 'Penalty list', 'penalties.html', ''], ['approvals', 'Approvals', 'approvals.html', ''], ['documents', 'Documents', 'documents.html', ''], ['doc_check', 'Doc Check', 'doc_check.html', ''], ['agreements', 'Agreements', 'agreements.html', ''], ['handover', 'Handover', 'handover.html', ''], ['ex_employees', 'Ex-employees', 'ex_employees.html', ''], ['history', 'Decline history', 'history.html', ''], ['leave', 'My Leave', 'leave.html', 'emp']] },
    { key: 'offices', label: 'Offices', icon: 'departments', sub: 'Cleaning and upkeep of each office, checked by Claude first', tabs: [['office_care', 'Offices', 'office_care.html', 'boss']] },
    { key: 'admin', label: 'Admin', icon: 'commission_rules', sub: 'Set-up you touch rarely', tabs: [['users', 'Users', 'users.html', ''], ['roles', 'Roles', 'roles.html', ''], ['departments', 'Departments', 'departments.html', ''], ['offices', 'Office Locations', 'offices.html', ''], ['excel_files', 'Excel Files', 'excel_files.html', '']] },
  ];
  // The permission (ticked on the Roles page) that opens each page, for roles that are not Admin.
  const PERM = {
    'dashboard.html': 'dashboard', 'whatsapp_groups.html': 'whatsapp_groups', 'penalties.html': 'penalties', 'store_dashboard.html': 'rs_manage', 'store_inventory.html': 'rs_manage', 'store_billing.html': 'rs_counter', 'store_messages.html': 'rs_counter', 'store_team.html': 'rs_manage', 'store_commission.html': 'rs_manage', 'store_contacts.html': 'rs_manage', 'dept_restock.html': 'dispatch_restock',
    'employees.html': 'employees', 'ex_employees.html': 'employees', 'attendance_admin.html': 'attendance', 'leave_requests.html': 'leave_requests', 'salary.html': 'salary',
    'approvals.html': 'approvals', 'documents.html': 'documents', 'agreements.html': 'agreements', 'history.html': 'decline_history', 'users.html': 'users', 'roles.html': 'users',
    'departments.html': 'departments', 'offices.html': 'offices', 'excel_files.html': 'excel_files', 'doc_check.html': 'documents', 'handover.html': 'employees',
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
    if (active === 'sales' || (SPK && SPK.indexOf(active) > -1)) return 'sales';
    for (const w of W) if (w.tabs.some((t) => t[0] === active)) return w.key;
    if (active === 'whatsapp') return 'whatsapp';
    return '';
  }

  /* ---- who is looking, and what may they open ---- */
  async function loadCtx() {
    const user = (typeof api !== 'undefined' && api.currentUser()) || {};
    const get = (a) => api.get(a).catch(() => null);
    const [flags, bd, fl] = await Promise.all([get('me.flags'), get('bd.badge'), get('fl.badge')]);
    const f = flags || {};
    return {
      isAdmin: (f.role || user.role) === 'Admin',
      perms: f.permissions || [],
      hasEmp: !!f.has_employee,
      boss: !!(bd && bd.manager), bd: bd || null,
      fl: fl && fl.show ? { show: true, manager: !!fl.manager } : null,
    };
  }
  function tabOk(t, ctx) {
    const need = t[3];
    if (need === 'admin') return ctx.isAdmin;
    if (need === 'srp') return ctx.isAdmin || ctx.perms.indexOf('store_pos') > -1;   // Store → POS: Admin and roles ticked 'Store · POS only'
    if (need === 'rsd') return ctx.isAdmin || ctx.perms.indexOf('dispatch_restock') > -1;   // Dispatch → Store orders: Admin and the Dispatch role
    if (need === 'boss') return ctx.boss;
    if (need === 'fl') return !!ctx.fl;
    if (need === 'flm') return !!(ctx.fl && ctx.fl.manager);
    if (need === 'emp') return (ctx.isAdmin && ctx.hasEmp) || (!ctx.isAdmin && ctx.perms.indexOf('leave') > -1);
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
    if (w.key === 'people') label += bdg('wsb_people');
    if (w.key === 'offices') label += bdg('wsb_offices');
    return [href, label, cur === w.key ? (w.key === 'sales' ? 'sales' : active) : 'ws_' + w.key];
  }
  function setBadge(id, n) { const b = document.getElementById(id); if (b && n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.style.display = 'inline-block'; } }
  function badges() {
    if (typeof api === 'undefined') return;
    api.get('bd.badge').then((d) => { if (d && d.manager) setBadge('wsb_boss', Number(d.inbox) || 0); }).catch(() => {});
    api.get('chk.badge').then((d) => { if (d) setBadge('wsb_offices', Number(d.review) || 0); }).catch(() => {});
    Promise.all([api.get('leave_admin.count').catch(() => null), api.get('approvals.count').catch(() => null)])
      .then(([a, b]) => setBadge('wsb_people', (Number(a && a.count) || 0) + (Number(b && b.count) || 0))).catch(() => {});
  }
  // Admin: the menu is the workspace list (replaces the old groups).
  function adminCats(active, SPK) {
    css(); document.documentElement.classList.add('ws-rail');
    setTimeout(badges, 60);
    return [{ key: 'ws', label: 'Menu', defaultOpen: true, links: W.map((w) => linkFor(w, active, SPK)) }];
  }
  // Roles that are not Admin / Employee (HR and custom roles): same menu, only the workspaces they may open.
  function customNav(nav, perms, d, active, env) {
    css();
    const SPK = env.SALES_PAGE_KEYS, SP = env.SALES_PERMS, PFP = env.PAGES_FOR_PERM;
    loadCtx().then((ctx) => {
      ctx.perms = perms; ctx.isAdmin = false;
      const links = [];
      W.forEach((w) => {
        if (w.key === 'sales') { if (perms.some((p) => SP.indexOf(p) > -1)) links.push(linkFor(w, active, SPK)); return; }
        if (visibleTabs(w, ctx).length) links.push(linkFor(w, active, SPK));
      });
      // pages a role has that no workspace holds: keep them as plain links, so nothing is lost
      const extra = [];
      perms.forEach((p) => {
        if (SP.indexOf(p) > -1) return;
        (PFP[p] || []).forEach((def) => { if (!tabByPage(def[0]) && !extra.some((x) => x[0] === def[0])) extra.push(def); });
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
    const urlFor = (t, q) => { const s = t[2] + (q ? (t[2].indexOf('?') < 0 ? '?' : '&') + q.replace(/^\?/, '') : ''); return s + (s.indexOf('?') < 0 ? '?' : '&') + 'in=sales'; };
    const bar = () => {
      $('wsTabs').innerHTML = tabs.length < 2 ? '' : tabs.map((t) => '<button type="button" class="ws-pill' + (cur === t ? ' on' : '') + '" data-t="' + t[0] + '">' + esc(t[1]) + (t[0] === 'boss_inbox' && ctx.bd && ctx.bd.inbox > 0 ? '<em>' + ctx.bd.inbox + '</em>' : '') + '</button>').join('');
    };
    function guard(f, t) {
      let win; try { win = f.contentWindow; void win.location.pathname; } catch (e) { return; }
      const here = tabs.find((x) => x[2] === pageOf(win.location.href));
      if (here && here !== t) { if (frames[t[0]] === f) delete frames[t[0]]; f.remove(); if (cur === t) show(here, win.location.search.replace(/^\?/, '').split('&').filter((x) => x && !/^in=/.test(x)).join('&')); else bar(); return; }
      try {
        win.document.addEventListener('click', (e) => {
          const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
          if (!a || a.target === '_blank' || e.defaultPrevented) return;
          const href = a.getAttribute('href') || '';
          const to = tabs.find((x) => x[2] === pageOf(href));
          if (to && to !== t) { e.preventDefault(); show(to, href.indexOf('?') > -1 ? href.split('?')[1].split('#')[0] : ''); }
        }, true);
      } catch (e) { /* the check above still covers it */ }
    }
    function show(t, q) {
      if (!t) return; cur = t;
      try { localStorage.setItem(LAST, t[0]); } catch (e) { /* private mode */ }
      history.replaceState(null, '', 'hub.html?w=' + w.key + '&t=' + t[0]);
      Object.keys(frames).forEach((k) => { frames[k].hidden = k !== t[0]; });
      let f = frames[t[0]];
      if (f && q) { f.remove(); delete frames[t[0]]; f = null; }
      if (!f) {
        f = document.createElement('iframe'); f.setAttribute('allow', 'camera; microphone; geolocation; clipboard-read; clipboard-write; fullscreen'); f.className = 'ws-frame'; f.title = t[1];
        f.setAttribute('allow', 'camera; microphone; geolocation; clipboard-write');
        f.addEventListener('load', () => { if (frames[t[0]] === f && cur === t) $('wsLoad').hidden = true; guard(f, t); });
        frames[t[0]] = f; $('wsHost').appendChild(f); $('wsLoad').hidden = false; f.src = urlFor(t, q);
      } else $('wsLoad').hidden = true;
      bar(); document.title = t[1] + ' · ' + w.label + ' — HRMS';
    }
    $('wsTabs').addEventListener('click', (e) => { const b = e.target.closest('.ws-pill'); if (b) show(tabs.find((t) => t[0] === b.dataset.t)); });
    let want = P.get('t'); if (!want) { try { want = localStorage.getItem(LAST) || ''; } catch (e) { want = ''; } }
    show(tabs.find((t) => t[0] === want) || tabs[0], P.get('q') || '');
    ctx.isHome = w.key === 'home';
    tools(ctx);
    if (w.key === 'home') needs(ctx);
  }
  /* ---- everything waiting for this person, in one list (the bell, the chip strip on Home) ---- */
  async function waiting(ctx) {
    const get = (a) => api.get(a).catch(() => null);
    const [lv, ap, ck] = await Promise.all([ctx.isAdmin || ctx.perms.indexOf('leave_requests') > -1 ? get('leave_admin.count') : null, ctx.isAdmin || ctx.perms.indexOf('approvals') > -1 ? get('approvals.count') : null, ctx.boss ? get('chk.badge') : null]);
    const items = [];
    if (ctx.bd && ctx.boss && ctx.bd.inbox > 0) items.push(['hub.html?w=boss&t=boss_inbox', ctx.bd.inbox, ctx.bd.inbox === 1 ? 'decision for the Boss' : 'decisions for the Boss']);
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
  const KW = { boss_questions: 'questions ask claude learn understand live', dept_hub: 'departments seats dispatch parcels money retail supplies track record', boss_penalties: 'penalty rules amount fine charged', punch: 'punch in out attendance selfie', daily_summary: 'summary report checked loop repeating claude', boss_board: 'today tasks duties board', boss_inbox: 'inbox decisions approve penalty photo', boss_duties: 'duties tasks', boss_setup: 'setup seats people chain backup', penalties: 'fine deduction penalty', documents: 'licence license passport aadhaar pan id upload', doc_check: 'documents missing expiry licence passport id claude read', agreements: 'contract agreement', handover: 'leaver left resign replace takeover', office_care: 'office cleaning cleaner zones checkpoints maintenance ticket', leave_requests: 'leave holiday', attendance_admin: 'punch attendance', salary: 'pay payroll', employees: 'staff people', approvals: 'approve pending' };
  function pagesFor(ctx) {
    const out = [];
    W.forEach((w) => {
      if (w.key === 'sales') { out.push({ label: 'Sales', where: 'Sales', href: 'sales.html', kw: 'orders targets commission' }); return; }
      visibleTabs(w, ctx).forEach((t) => out.push({ label: t[1], where: w.label, href: 'hub.html?w=' + w.key + '&t=' + t[0], kw: KW[t[0]] || '' }));
    });
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
  function tools(ctx) {
    const host = document.getElementById('wsTools'); if (!host) return;
    toolsCss();
    host.innerHTML = '<button type="button" class="ws-srch" id="wsSrch" aria-label="Search pages">&#128269;<span class="t">Search</span><kbd>Ctrl K</kbd></button>'
      + '<span style="position:relative"><button type="button" class="ws-bell" id="wsBell" aria-label="What needs you">&#128276; <span id="wsBellN">0</span></button><div class="ws-pop" id="wsPop"></div></span>';
    // palette
    const pages = pagesFor(ctx);
    let pal = document.getElementById('wsPal');
    if (!pal) { pal = document.createElement('div'); pal.id = 'wsPal'; pal.innerHTML = '<div class="box"><input id="wsPalIn" placeholder="Go to…  (try: licence, cleaning, penalty, leaver)" autocomplete="off"><div class="res" id="wsPalRes"></div></div>'; document.body.appendChild(pal); }
    const inp = document.getElementById('wsPalIn'), res = document.getElementById('wsPalRes'); let sel = 0, list = [];
    const draw = () => {
      const q = inp.value.trim().toLowerCase();
      list = pages.filter((p) => !q || (p.label + ' ' + p.where + ' ' + p.kw).toLowerCase().indexOf(q) > -1).slice(0, 12);
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
    refresh(); setInterval(refresh, 90000);
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
      const btn = [];
      btn.push(['my_day.html', 'My Day', '✅', (bd && bd.my_open) || 0]);   // everybody: the day starts here (punch-in steps, duties, sales)
      if (f.is_sales) { btn.push(['my_sales.html', 'My Sales', '\u{1F4C8}', 0]); btn.push(['my_orders.html', 'Orders', '\u{1F6CD}', 0]); btn.push(['my_chats.html', 'Chats', '\u{1F4AC}', 0]); }
      btn.push(['my_space.html', 'My Space', '\u{1F464}', 0]);
      if (btn.length < 2) return;
      const on = (h) => file === h || (h === 'my_day.html' && /^(dept_[a-z]+)\.html$/.test(file)) || (h === 'my_space.html' && /^(attendance|leave|my_salary|my_penalties|my_agreements)\.html$/.test(file)) || (h === 'my_orders.html' && /^(orders|order_allocation)\.html$/.test(file));
      const st = document.createElement('style'); st.id = 'wsEmpCss';
      st.textContent = '#wsEmpNav{display:none;}@media (max-width:820px){#wsEmpNav{display:flex; position:fixed; left:0; right:0; bottom:0; z-index:90; background:#fffdf8; border-top:1px solid #ece7dc; padding:6px 8px calc(6px + env(safe-area-inset-bottom)); gap:4px;}'
        + '#wsEmpNav a{flex:1; display:flex; flex-direction:column; align-items:center; gap:2px; padding:7px 2px; border-radius:16px; text-decoration:none; color:#4b5563; font-size:11px; font-weight:600; position:relative;}'
        + '#wsEmpNav a .i{font-size:20px; line-height:1;} #wsEmpNav a.on{background:#111; color:#fff;} #wsEmpNav a em{position:absolute; top:2px; right:18%; background:#e02424; color:#fff; border-radius:9px; font-style:normal; font-size:11px; padding:0 5px; line-height:15px;}'
        + 'body{padding-bottom:78px;}}';
      document.head.appendChild(st);
      const nav = document.createElement('nav'); nav.id = 'wsEmpNav';
      nav.innerHTML = btn.map((b) => '<a href="' + b[0] + '" class="' + (on(b[0]) ? 'on' : '') + '"><span class="i">' + b[2] + '</span>' + esc(b[1]) + (b[3] > 0 ? '<em>' + b[3] + '</em>' : '') + '</a>').join('');
      document.body.appendChild(nav);
    } catch (e) { /* the old menu still works */ }
  }

  return { on: true, v: 2, W, adminCats, customNav, iconKey, redirect, hub, wsOf, loadCtx, employeeNav };
})();