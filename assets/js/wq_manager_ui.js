/* wq_manager_ui.js — 🧭 Team manager (WhatsApp Sales → Team manager tab). Server: empmanagment/wq_manager.php (wq.mgr_*).
 *   WaManager.mount(el)   the admin view: Claude's summary on top, one row per salesperson (level, numbers, card line, reason), mode switch, run now, approve, manual level.
 * Needs api.js. Own styles (tm-*). */
(function () {
  if (window.WaManager) return;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const CSS = `.tm-sub{font-size:12.5px;color:#6b7684;margin:0 0 10px;max-width:80ch;line-height:1.5}
  .tm-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 12px}
  .tm-sum{border:1px solid #c7d2fe;border-radius:12px;padding:10px 12px;background:#eef2ff;margin-bottom:12px;font-size:13.5px;line-height:1.55;white-space:pre-wrap}
  .tm-row{border:1px solid #e4e7ec;border-radius:12px;padding:10px 12px;margin-bottom:8px;background:#fff}
  .tm-h{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.tm-n{font-weight:700}
  .tm-chip{font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;background:#e5e7eb;color:#374151}
  .tm-chip.first{background:#dcfce7;color:#166534}.tm-chip.late{background:#ffedd5;color:#9a3412}.tm-chip.hold{background:#fee2e2;color:#b91c1c}.tm-chip.off{background:#f3f4f6;color:#6b7280}.tm-chip.meet{background:#ede9fe;color:#5b21b6}
  .tm-m{font-size:12px;color:#6b7684;margin-top:4px;line-height:1.5}
  .tm-card{font-size:13px;margin-top:6px;padding:6px 8px;background:#f1f5f9;border-radius:8px}
  .tm-act{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:8px}
  .tm-empty{padding:26px;text-align:center;color:#6b7684}.tm-err{color:#d9423b;padding:12px}
  html.wqdk .tm-row{background:#111b21;border-color:#25343e}html.wqdk .tm-card{background:#1f2c34}html.wqdk .tm-sum{background:#1b2440;border-color:#35407a}`;
  function css() { if (document.getElementById('tmCss')) return; const s = document.createElement('style'); s.id = 'tmCss'; s.textContent = CSS; document.head.appendChild(s); }
  const sgn = (a, b) => (a == null || b == null) ? '' : (a >= b ? ' ▲' : ' ▼');

  function mount(host) {
    css(); let D = null, busy = false;
    const alive = () => document.body.contains(host);
    async function load() {
      try { D = await api.get('wq.mgr_get'); if (alive()) draw(); } catch (e) { if (alive()) host.innerHTML = '<div class="tm-err">' + esc(e.message) + '</div>'; }
    }
    function row(r) {
      const s = r.sig || {}, L = D.levels || {}, lv = r.level || 'normal';
      const sel = '<select data-set="' + r.emp_id + '"><option value="auto"' + (!r.manual ? ' selected' : '') + '>Auto (Claude)</option>' + Object.keys(L).map((k) => '<option value="' + k + '"' + (r.manual === k ? ' selected' : '') + '>' + esc(L[k]) + '</option>').join('') + '</select>';
      const hold = (r.auto_level === 'hold' || r.manual === 'hold');
      return '<div class="tm-row"><div class="tm-h"><span class="tm-n">' + esc(s.name) + '</span><span class="tm-chip ' + esc(lv) + '">' + esc(L[lv] || 'not decided yet') + (r.manual ? ' · by hand' : '') + '</span>'
        + (s.present ? '<span class="tm-chip first">At work</span>' : '<span class="tm-chip off">Not at work</span>') + (r.meet_admin ? '<span class="tm-chip meet">Meet admin</span>' : '') + (s.sure_behind ? '<span class="tm-chip late">Clearly behind the team</span>' : '') + '</div>'
        + '<div class="tm-m">Ratio ' + (s.ratio_pct == null ? '—' : s.ratio_pct + '%') + ' (team ' + esc(s.team_ratio_pct) + '%)' + sgn(s.ratio_pct, s.team_ratio_pct) + ' · ' + esc(s.orders_30d) + ' orders / ' + esc(s.queries_30d) + ' queries (30 days)'
        + ' · ticket ' + (s.ticket_30d == null ? '—' : '₹' + esc(s.ticket_30d) + ' (team ₹' + esc(s.team_ticket) + ')' + sgn(s.ticket_30d, s.team_ticket)) + ' · speed today ' + (s.speed_median_min_today == null ? '—' : esc(s.speed_median_min_today) + ' min')
        + (s.rating_30d == null ? '' : ' · daily rating ' + esc(s.rating_30d) + '/10') + (s.tone_score == null ? '' : ' · tone ' + esc(s.tone_score) + '/10') + (s.penalties_30d ? ' · penalties ' + esc(s.penalties_30d) : '') + (s.late_replies_today ? ' · late replies today ' + esc(s.late_replies_today) : '')
        + ' · overdue complaints ' + esc(s.overdue_complaints) + ' · pending answers ' + esc(s.pending_answers) + ' · open chats ' + esc(s.open_chats) + '</div>'
        + (r.card ? '<div class="tm-card">📣 ' + esc(r.card) + '</div>' : '') + (s.tone_note ? '<div class="tm-m">🗣 Tone: ' + esc(s.tone_note) + '</div>' : '') + (r.reason ? '<div class="tm-m">Why: ' + esc(r.reason) + '</div>' : '')
        + '<div class="tm-act">' + sel + (hold ? '<button type="button" class="wq-btn sm" data-appr="' + r.emp_id + '" data-v="' + (r.approved ? 0 : 1) + '">' + (r.approved ? 'Approved — undo' : 'Approve “no new chats”') + '</button>' : '') + '</div></div>';
    }
    function draw() {
      const rows = (D.rows || []).slice().sort((a, b) => (b.sig.present - a.sig.present) || String(a.sig.name).localeCompare(b.sig.name));
      host.innerHTML = '<p class="tm-sub">Claude looks at every salesperson together — order ratio (queries and campaigns), ticket size, speed, who is at work, complaints, load — and decides who sees new interested chats first. <b>Suggest</b> only shows the decisions here. <b>Apply</b> makes Needs-you-now use them and shows each person their card in the chat app. Nobody is blocked without your Approve.</p>'
        + '<div class="tm-bar"><label>Mode <select id="tmMode"><option value="off">Off</option><option value="suggest">Suggest (only show)</option><option value="apply">Apply</option></select></label><button type="button" class="wq-btn sm" id="tmRun">Ask Claude now</button><span class="tm-m">Last run: ' + esc(D.ran_at || 'never') + ' · runs by itself every 10 minutes</span></div>'
        + (D.summary ? '<div class="tm-sum">🧠 ' + esc(D.summary) + '</div>' : '') + (rows.length ? rows.map(row).join('') : '<div class="tm-empty">No salespeople found.</div>');
      const m = host.querySelector('#tmMode'); if (m) m.value = D.mode;
    }
    host.onchange = async (e) => {
      const t = e.target; if (busy) return; busy = true;
      try { if (t.id === 'tmMode') await api.post('wq.mgr_cfg', { mode: t.value }); else if (t.dataset.set) await api.post('wq.mgr_set', { emp_id: +t.dataset.set, level: t.value }); await load(); } catch (er) { alert(er.message); } busy = false;
    };
    host.onclick = async (e) => {
      const t = e.target.closest('button'); if (!t || busy) return; busy = true;
      try { if (t.id === 'tmRun') { t.disabled = true; t.textContent = 'Claude is reading the team…'; await api.post('wq.mgr_run', {}); } else if (t.dataset.appr) await api.post('wq.mgr_approve', { emp_id: +t.dataset.appr, approve: +t.dataset.v }); await load(); } catch (er) { alert(er.message); } busy = false;
    };
    host.innerHTML = '<div class="tm-empty">Loading…</div>'; load();
  }
  window.WaManager = { mount };
})();
