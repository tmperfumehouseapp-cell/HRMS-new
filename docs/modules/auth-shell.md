# Module: Auth & shell

Source: module #1 under **Shared / foundation** in `docs/hrms-map.md`.

`assets/js/config.js` was **not opened for secret values** (config / API base URL). Its existence and that it defines `API_BASE_URL` are evidenced by `api.js` and by a value-stripped scan of the file. Exact `API_BASE_URL` string: **unknown** (intentionally unread).

---

## Purpose

Login, session (JWT in `localStorage`), page guard, shared sidebar / workspace hub, PWA install surface, always-fresh service worker, and global shell behaviours (splash, icons, punch reminder / push subscribe hooks, role-based menu).

It is the entry and chrome around every other HRMS page — not a business feature module.

---

## Files and paths

### Listed in the map (this module)

| Path | Role |
|------|------|
| `index.html` | Login page; redirects if `hrms_token` already set; posts `login`; registers `sw.js` |
| `hub.html` | Workspace shell: sidebar + tab strip + iframe host; calls `guardPage()`, `renderSidebar('ws:…')`, `WS.hub()` |
| `assets/js/api.js` | `api` client (`get` / `post` / `upload`), token helpers, `guardPage()`, `logout()` |
| `assets/js/layout.js` | Sidebar, workspaces (`window.WS`), badges, punch/penalty notices, rail UI, mobile menu, splash loader, favicon injection (`LAYOUT_BUILD = '261102.2'`) |
| `assets/js/config.js` | Defines `API_BASE_URL` used by `api.js` (contents not documented here) |
| `manifest.json` | PWA manifest: name `TMPH HRMS`, `start_url` `index.html`, icons under `icons/` |
| `sw.js` | Service worker: no app cache; forces fresh `html`/`js`/`css`; Web Push display + notification click → punch URL |
| `icons/` | PWA / favicon assets (see below) |

### `icons/` contents (from directory listing)

| Path |
|------|
| `icons/apple-touch-icon.png` |
| `icons/favicon-16.png` |
| `icons/favicon-32.png` |
| `icons/favicon.ico` |
| `icons/icon-192.png` |
| `icons/icon-512.png` |
| `icons/icon-maskable-512.png` |
| `icons/splash-blank.png` |

### Loaded by this module’s pages (not in map “main files”, but required at runtime)

| Path | Evidence |
|------|----------|
| `assets/css/style.css` | Linked from `index.html` and `hub.html` |
| `assets/js/splash.js` | Loaded by `index.html`; also injected by `layout.js` on pages that load layout |

### Related but not loaded as a separate runtime file

| Path | Evidence |
|------|----------|
| `workspaces.js` | Comment says it is pasted into `layout.js` between `WS-START` / `WS-END` by `ws_install.php`; hub uses `window.WS` from `layout.js`, not a `<script src="workspaces.js">` |

### Duplicate / backup copies (unknown whether live)

| Path | Note |
|------|------|
| `public_html_hub.html` | Exists next to `hub.html` — **unknown** if referenced in production |
| `assets/js/public_html_assets_js_layout.js` | Exists next to `layout.js` — **unknown** if referenced |

Backend handlers used by this module live in `empmanagment/api.php` (Core API module). That file is **not** owned by Auth & shell; endpoints below are only those the shell actually calls.

---

## Database tables used

Tables confirmed from `empmanagment/api.php` handlers that this module’s front end calls:

| Table | How used |
|-------|----------|
| `users` | `login` SELECT/UPDATE; `setup_admin` INSERT (related bootstrap, not called from `index.html` UI) |
| `roles` | `me.flags` → `role_permissions($pdo, $role)` (permissions for non-Admin) |
| `employees` | `me.flags` (department / salary flags); `push.subscribe` requires `employee_id` |
| `departments` | `me.flags` JOIN for sales-department detection |
| `push_subscriptions` | Created in `ensure_push_table`; written by `push.subscribe` |
| `hrms_settings` | VAPID keys via `setting_get` / `push_vapid` for `push.key` |

Auth tokens are **not** stored in a DB table: `generate_token` / `verify_token` in `api.php`; browser keeps `hrms_token` and `hrms_user` in `localStorage` (`api.js`).

Other tables may be touched by shell-polled actions (`me.reminder_status`, `needs.counts`, `bd.badge`, etc.). Exact table sets for those actions: **unknown** without reading each handler fully for this doc.

---

## API endpoints

All requests go to `API_BASE_URL?action=<name>` (`api.js`). Methods as used by the shell:

### Core auth (login / session)

| Action | Method (as used) | Caller | Notes |
|--------|------------------|--------|-------|
| `login` | POST JSON `{username, password}` | `index.html` | Returns `{token, user}`; 401 on bad credentials |
| `me` | GET (auth) | **unknown** from shell files reviewed; exists in `api.php` | Returns `{user}` from token |
| `me.flags` | GET (auth) | `layout.js` (`renderSidebarCore`, employee/custom/admin paths, tools) | Live role, permissions, `is_sales`, `has_salary`, `has_employee`, `build`, `layout_min` |
| `diag.access` | GET with `token` query | Sidebar build tag click (`layout.js`) | Opens in new tab for access check |
| `setup_admin` | GET | **Not** called from `index.html` / shell JS reviewed | One-time admin bootstrap in `api.php` |

Client-only (no API): `logout()` clears `hrms_token`, `hrms_user`, `hrms_home` and goes to `index.html`.

### Shell chrome (layout.js / push)

| Action | Method | Caller |
|--------|--------|--------|
| `push.key` | GET | `hrmsPushSubscribe` |
| `push.subscribe` | POST | `hrmsPushSubscribe` |
| `me.reminder_status` | GET | `startPunchReminder` poll |
| `my_penalties.list` | GET | `startPenaltyNotice` |
| `my_penalties.read` | POST | penalty notice OK |
| `needs.counts` | GET | chat badge; home “Needs you” |
| `bd.badge` | GET | boss menu / badges / hub context |
| `bd.sx_badge` | GET | inbox badge / employee nav |
| `bd.cx_badge` | GET | complaints badge / employee nav |
| `dp.badge` | GET | boss badge combine |
| `chk.badge` | GET | offices badge; home needs |
| `fl.badge` | GET | flyer menu / hub context |
| `leave_admin.count` | GET | people badge; home needs |
| `approvals.count` | GET | people badge; home needs |
| `ct.pulse` | GET | Control Tower badge / home needs |
| `retail.stock_alerts` | GET `badge=1` | stock badge / home needs |
| `wa.g_hq_me` | GET | end of `layout.js` (HQ flag in `localStorage`) |

`sw.js` does not call the API; it shows push payloads from the server.

---

## How the pages connect

```
index.html
  ├─ if localStorage hrms_token → replace() to home
  │     Employee: localStorage hrms_home || my_day.html
  │     else: dashboard.html
  ├─ splash.js → style.css → config.js → api.js
  ├─ form → api.post('login') → setToken / setUser → replace(home)
  └─ register sw.js

Any guarded page (including hub.html)
  ├─ config.js → api.js → layout.js
  ├─ guardPage(): no token → index.html
  ├─ renderSidebar(active):
  │     may WS.redirect(active) → hub.html?w=&t=
  │     else build sidebar by role (Admin / Employee / custom)
  │     Admin (+ WS): links are hub.html?w=<workspace> (Sales → sales.html direct)
  └─ layout injects splash.js + favicon links from icons/

hub.html?w=<workspace>&t=<tab>
  ├─ guardPage(); renderSidebar('ws:' + w)
  ├─ WS.hub():
  │     loadCtx() via me.flags + badges
  │     builds tab pills; loads each tab page in an iframe
  │     iframe URL = <page>.html?...&in=sales  (+ optional #hash for Boss Engine views)
  │     remembers last tab in localStorage key hrms_ws_<workspace>
  └─ register sw.js

sw.js
  ├─ fetch: navigate + .html/.js/.css → network with _fresh= timestamp, Cache-Control no-store
  ├─ push → showNotification
  └─ notificationclick → attendance.html (or payload url), default punch actions
```

localStorage keys observed in these files: `hrms_token`, `hrms_user`, `hrms_home`, `hrms_push_day`, `hrms_ws_*`, `hrms_nav_open`, `hrms_hq_on`, `hrms_hq_at`; splash uses `hrms_splash_done` / `hrms_splash_seen` in `splash.js`.

---

## Known issues

1. **Stale comment on `index.html`**: head comment says employees land on “My Sales”; actual redirect uses `hrms_home` or `my_day.html`. `layout.js` later sets `hrms_home` to `my_day.html` for employees.
2. **`workspaces.js` vs live `layout.js`**: root `workspaces.js` header still says “8 icons” and differs from the embedded `window.WS` in `layout.js` (comment there says “9 icons” and includes more workspaces such as Finance, Complaints, Dispatch, Help). `workspaces.js` is not loaded by `hub.html` / `index.html`. Risk: editing only `workspaces.js` does nothing until pasted into `layout.js`.
3. **Conflicting comments inside `layout.js`**: `WS-START` line says “8 icons”; the next comment says “9 icons”.
4. **Duplicate files**: `public_html_hub.html` and `assets/js/public_html_assets_js_layout.js` — purpose/live use **unknown**.
5. **Custom-role flash hide**: for non-Admin/non-Employee, page is `visibility:hidden` until `me.flags` returns, with a 6s fail-safe — slow API leaves a blank screen briefly.
6. **No server-side session table**: logout is local only; a stolen JWT remains valid until expiry (`generate_token` default TTL in `api.php` is 30 days per function comment near token helpers).
7. **`setup_admin`**: creates a default Admin user with a **hardcoded password in source** if `admin` does not exist — security risk if left enabled/reachable.
8. **Service worker vs query cache-busting**: `sw.js` appends `_fresh=` on html/js/css; pages also use `?v=` on script tags — overlapping strategies; whether that causes double-load quirks on some hosts: **unknown**.
9. **Push requires secure context + employee link**: `hrmsPushSubscribe` no-ops without SW/Push/Notification/`isSecureContext`; `push.subscribe` errors if login has no `employee_id`.

---

## Caveats

- Only this module’s listed files (plus confirmed runtime deps above) were used for the connection story.
- Config / `.env` values were not opened.
- Full table lists for every badge/`needs` endpoint: **unknown**.
- Whether `manifest.json` / `sw.js` are served correctly on the live host: **unknown** (not tested here).
