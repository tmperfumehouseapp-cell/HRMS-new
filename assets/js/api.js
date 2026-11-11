/**
 * Fetch wrapper for the single-file backend (api.php?action=...).
 * Attaches the bearer token, parses JSON, redirects to login on 401.
 *
 * Auth is kept in localStorage: you stay logged in across tabs, browser
 * restarts, and days — until you press Log out or the token expires.
 */
const api = {
  token() { return localStorage.getItem('hrms_token'); },
  setToken(token) { localStorage.setItem('hrms_token', token); },
  clearToken() { localStorage.removeItem('hrms_token'); },
  currentUser() {
    const raw = localStorage.getItem('hrms_user');
    return raw ? JSON.parse(raw) : null;
  },
  setUser(user) { localStorage.setItem('hrms_user', JSON.stringify(user)); },

  buildUrl(action, params = {}) {
    const qs = new URLSearchParams(Object.assign({ action }, params));
    return API_BASE_URL + '?' + qs.toString();
  },

  // Fewer trips to the database: the same read asked twice at once shares ONE call, and the small menu numbers
  // (badges, counts, pulse) are remembered for 30 s in this browser tab, so moving between pages does not ask again.
  _inflight: {}, _t0: Date.now(), _laneP: null,
  // Menu numbers used to wait 1.2 s and then run one at a time. That made Attendance, Dashboard and the other
  // busy pages take 4–8 seconds to "open" even though each database call took under half a second.
  // The live server is the VPS, which answers many calls together. Badges now run with the page's own reads.
  _bgRun(fn) { return fn(); },
  // Remember an answer in the same 30 s memory as a normal read (used when one call brought several answers at once).
  seed(action, d, params = {}) {
    if (d === null || d === undefined) return;
    try { sessionStorage.setItem('apic:' + action + '?' + JSON.stringify(params) + '|' + String(this.token() || '').slice(-12), JSON.stringify({ t: Date.now(), d })); } catch (e) { /* storage may be blocked */ }
  },
  // The five sidebar questions (flags, Boss, Flyers, Complaints, Inbox) are answered by ONE server call (bd.menu_ctx): whichever of them
  // a page asks first starts it, the others wait for the same answer. If the server cannot (older copy, error) each is asked on its own as before.
  _CTX: { 'me.flags': 'flags', 'bd.badge': 'bd', 'fl.badge': 'fl', 'bd.cx_badge': 'cx', 'bd.sx_badge': 'sx' },
  _ctxP: null, _ctxT: 0, _noCtx: false,
  _ctx() {
    if (this._ctxP && Date.now() - this._ctxT < 30000) return this._ctxP;
    this._ctxT = Date.now();
    this._ctxP = this._get('bd.menu_ctx', {}, {}).then((m) => (m && typeof m === 'object' && m.flags ? m : null), () => { this._noCtx = true; return null; });
    return this._ctxP;
  },
  request(action, options = {}, params = {}) {
    const k = this._CTX[action];
    if (k && !this._noCtx && (options.method || 'GET') === 'GET' && !Object.keys(params).length && this.token()) {
      return this._ctx().then((m) => (m && m[k] !== undefined && m[k] !== null) ? m[k] : this._get(action, options, params));
    }
    return this._get(action, options, params);
  },
  _get(action, options = {}, params = {}) {
    if ((options.method || 'GET') !== 'GET') return this._request(action, options, params);
    const key = action + '?' + JSON.stringify(params) + '|' + String(this.token() || '').slice(-12);
    const cacheable = /(badge|count|pulse|flags|ctx)$/.test(action);   // me.flags is asked by every page: reuse it for 30 s (the server still checks the role on every real action)
    if (cacheable) {
      try { const c = JSON.parse(sessionStorage.getItem('apic:' + key) || 'null'); if (c && Date.now() - c.t < 30000) return Promise.resolve(c.d); } catch (e) { /* storage may be blocked */ }
    }
    if (this._inflight[key]) return this._inflight[key];
    const bg = /^(leave_admin\.count|approvals\.count|ct\.pulse|dp\.badge|chk\.badge|wa\.g_hq_me)$/.test(action);
    const p = (bg ? this._bgRun(() => this._request(action, options, params)) : this._request(action, options, params)).then((d) => {
      if (cacheable) { try { sessionStorage.setItem('apic:' + key, JSON.stringify({ t: Date.now(), d })); } catch (e) { /* ignore */ } }
      return d;
    });
    this._inflight[key] = p;
    const done = () => { delete this._inflight[key]; };
    p.then(done, done);
    return p;
  },

  async _request(action, options = {}, params = {}) {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, options.headers || {});
    const token = this.token();
    if (token) headers['Authorization'] = 'Bearer ' + token;

    // GET calls carry a one-off value and skip every cache, so a change that was
    // just saved is what the next read returns (no stale copy from the browser / CDN).
    const isGet = (options.method || 'GET') === 'GET';
    // At most 8 reads in flight from this browser. A retry is safe when the server says
    // "Database connection failed" before any work ran. Up to 2 retries.
    let res, data;
    for (let attempt = 0; ; attempt++) {
      await api._slot(!isGet);   // a Send / Save goes ahead of the background refreshes
      try {
        res = await fetch(this.buildUrl(action, isGet ? Object.assign({}, params, { _t: Date.now() }) : params), {
          method: options.method || 'GET',
          headers,
          body: options.body ? JSON.stringify(options.body) : undefined,
          cache: 'no-store',
        });
        data = {};
        try { data = await res.json(); } catch (e) { /* non-JSON response */ }
      } finally { api._free(); }
      const dbBusy = res.status === 500 && data && typeof data.error === 'string' && data.error.indexOf('Database connection failed') === 0;
      if (!dbBusy || attempt >= 2) break;
      await new Promise((r) => setTimeout(r, 700 * (attempt + 1) + Math.random() * 400));
    }

    if (res.status === 401) {
      this.clearToken();
      window.location.href = 'index.html';
      throw new Error('Session expired');
    }
    if (!res.ok) {
      const err = new Error((data && data.error) || 'Server error (' + res.status + ') — if this persists, re-upload api.php.');
      err.data = data; // full server response for callers that need details
      throw err;
    }
    return data;
  },

  _active: 0, _waiting: [],
  _slot(prio) { if (this._active < 8) { this._active++; return Promise.resolve(); } return new Promise((r) => (prio ? this._waiting.unshift(r) : this._waiting.push(r))); },   // a Save goes to the front of the queue
  _free() { const n = this._waiting.shift(); if (n) n(); else this._active--; },

  get(action, params = {}) {
    return this.request(action, { method: 'GET' }, params);
  },
  async upload(action, formData) {
    const res = await fetch(`${API_BASE_URL}?action=${action}`, {
      method: 'POST',
      headers: this.token() ? { Authorization: 'Bearer ' + this.token() } : {},
      body: formData,
    });
    let data = {};
    try { data = await res.json(); } catch (e) {}
    if (!res.ok) {
      const err = new Error(data.error || 'Something went wrong (' + res.status + ')');
      err.data = data;
      throw err;
    }
    return data;
  },

  post(action, body) {
    return this.request(action, { method: 'POST', body });
  },
};

function guardPage() {
  if (!api.token()) window.location.href = 'index.html';
}

function logout() {
  api.clearToken();
  localStorage.removeItem('hrms_user');
  localStorage.removeItem('hrms_home');
  window.location.href = 'index.html';
}