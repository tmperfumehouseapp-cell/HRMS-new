/* Shared by store_logins.html and dispatch_logins.html: a page sets window.RL = {role, perm, nav} then loads this file. */
(function () {
  const RL = window.RL, ROLE = RL.role, PERM = RL.perm;
  guardPage();
  renderSidebar(RL.nav);
  const $ = (id) => document.getElementById(id), esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const toast = (m) => { const t = $('toast'); t.textContent = m; t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 2800); };
  const run = async (f) => { try { return await f(); } catch (e) { toast(e.message || 'Something went wrong'); } };
  // the role is created once (only this one permission), then every login here uses it
  async function ensureRole() {
    const d = await api.get('roles.list'); const r = (d.roles || []).find((x) => x.role_name === ROLE);
    if (r) { if ((r.permissions || []).indexOf(PERM) < 0) await api.post('roles.save', { role_id: r.role_id, role_name: ROLE, permissions: (r.permissions || []).concat([PERM]) }); return; }
    await api.post('roles.save', { role_name: ROLE, permissions: [PERM] });
  }
  async function load() { await run(async () => {
    const d = await api.get('users.list'); const rows = (d.users || []).filter((u) => u.role === ROLE);
    $('list').innerHTML = rows.length ? rows.map((u) => '<div class="lg-u"><div class="nm"><b>' + esc(u.username) + '</b></div><button class="lg-b" data-pw="' + u.user_id + '" data-n="' + esc(u.username) + '">New password</button><button class="lg-b" data-del="' + u.user_id + '" data-n="' + esc(u.username) + '">Remove</button></div>').join('') : 'No logins yet.';
    document.querySelectorAll('[data-pw]').forEach((b) => b.onclick = () => { const p = prompt('New password for ' + b.dataset.n + ' (6+ characters):'); if (p) run(async () => { await api.post('users.update', { user_id: +b.dataset.pw, username: b.dataset.n, role: ROLE, password: p }); toast('Password changed'); }); });
    document.querySelectorAll('[data-del]').forEach((b) => b.onclick = () => { if (confirm('Remove the login ' + b.dataset.n + '? They can no longer sign in.')) run(async () => { await api.post('users.delete', { user_id: +b.dataset.del }); toast('Removed'); load(); }); });
  }); }
  $('go').onclick = () => run(async () => {
    const u = $('u').value.trim(), p = $('p').value;
    if (!u || p.length < 6) { toast('Enter an ID and a password of 6+ characters'); return; }
    await ensureRole(); await api.post('users.create', { username: u, password: p, role: ROLE, employee_id: null });
    $('u').value = ''; $('p').value = ''; toast('Login created — give them the ID and password'); load();
  });
  run(async () => { await ensureRole(); load(); });
})();
