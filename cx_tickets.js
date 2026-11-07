/* cx_tickets.js — 🎫 the new main Complaints screen: every ticket line by line, day by day, with the dashboard on top.
   Two departments (📍 Tracking · 🛠 Complaint), 🔴 Serious / ⚪ Basic, status, owner, where it came from (salesperson / customer self / Instagram / call / Claude).
   Tap a line → the ticket (the full case screen with the customer chat, steps, refund, resolve / re-open).  Talks to bd.cx_tickets (complaints_more.php).
   Used by cx_app.js (tab "Tickets", the first screen of complaints.html). Needs api.js. */
window.CXT = (function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const CSS = `
  .tk{padding:14px 16px 40px;max-width:1500px} .tk *{box-sizing:border-box}
  .tk-k{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}
  .tk-k>div{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:11px 13px} .tk-k small{display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em} .tk-k b{font-size:24px;display:block;margin-top:2px} .tk-k span{font-size:12px;color:#64748b}
  .tk-k .r b{color:#b91c1c} .tk-k .t b{color:#1d4ed8} .tk-k .c b{color:#7c3aed} @media(max-width:520px){.tk-k{grid-template-columns:1fr 1fr}.tk-k b{font-size:20px}}
  .tk-row2{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px} @media(max-width:760px){.tk-row2{grid-template-columns:1fr}}
  .tk-box{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:10px 13px} .tk-box h4{margin:0 0 6px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
  .tk-bar{display:flex;align-items:center;gap:8px;font-size:13px;margin:3px 0} .tk-bar i{display:block;height:8px;border-radius:9px;background:#93c5fd;min-width:2px} .tk-bar span{min-width:150px;max-width:55%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis} .tk-bar b{margin-left:auto}
  .tk-f{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:4px 0 10px} .tk-f button{border:1px solid #d6dde6;background:#fff;border-radius:99px;padding:7px 12px;font-weight:700;font-size:13px;min-height:36px;cursor:pointer} .tk-f button.on{background:#111b21;color:#fff;border-color:#111b21}
  .tk-f input,.tk-f select{border:1px solid #d6dde6;border-radius:10px;padding:7px 10px;font-size:14px;min-height:36px;background:#fff} .tk-f input{flex:1;min-width:180px}
  .tk-day{margin:16px 0 6px;font-weight:800;font-size:14px;color:#0f172a;display:flex;gap:10px;align-items:baseline} .tk-day small{font-weight:600;color:#64748b}
  .tk-l{display:grid;grid-template-columns:86px 58px minmax(150px,1.2fr) minmax(170px,1.4fr) 112px 92px 104px minmax(110px,.8fr) minmax(130px,.9fr);gap:10px;align-items:center;background:#fff;border:1px solid #e3e8ef;border-left:5px solid #cbd5e1;border-radius:12px;padding:11px 12px;margin-bottom:6px;cursor:pointer;font-size:14px}
  .tk-l:hover{box-shadow:0 2px 10px rgba(15,23,42,.08)} .tk-l.s{border-left-color:#dc2626} .tk-l.d{opacity:.72} .tk-l.late{background:#fff7f7}
  .tk-l>span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap} .tk-l .cd{font-family:ui-monospace,Menlo,monospace;font-weight:800;color:#3730a3} .tk-l small{display:block;color:#64748b;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tk-h{display:grid;grid-template-columns:86px 58px minmax(150px,1.2fr) minmax(170px,1.4fr) 112px 92px 104px minmax(110px,.8fr) minmax(130px,.9fr);gap:10px;padding:0 12px 4px 17px;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
  .tk-p{display:inline-block;border-radius:99px;padding:2px 9px;font-size:12px;font-weight:800} .tk-p.trk{background:#dbeafe;color:#1e40af} .tk-p.cmp{background:#ede9fe;color:#5b21b6} .tk-p.ser{background:#fee2e2;color:#991b1b} .tk-p.bas{background:#f1f5f9;color:#475569}
  .tk-p.open{background:#fef3c7;color:#92400e} .tk-p.reopened{background:#fee2e2;color:#991b1b} .tk-p.resolved{background:#dcfce7;color:#166534} .tk-p.closed{background:#e2e8f0;color:#334155}
  .tk-tpl{background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:12px;padding:9px 12px;margin-bottom:10px;font-size:13.5px}
  @media(max-width:900px){.tk-h{display:none}.tk-l{grid-template-columns:1fr auto;gap:2px 10px}.tk-l>span{white-space:normal}.tk-l>span:nth-child(1){order:1}.tk-l>span:nth-child(6){order:2;text-align:right}.tk-l>span:nth-child(3){order:3}.tk-l>span:nth-child(7){order:4;text-align:right}.tk-l>span:nth-child(4){order:5;grid-column:1/-1}.tk-l>span:nth-child(5){order:6}.tk-l>span:nth-child(2){order:7;text-align:right}.tk-l>span:nth-child(8){order:8}.tk-l>span:nth-child(9){order:9;text-align:right}}`;
  // 👤 My tickets / 👥 All tickets, "My day", Not taken yet, new-ticket pop-up
  const CSS2 = `.tk-pr{font-style:normal;font-size:11.5px;font-weight:800;background:#eef2ff;color:#3730a3;border-radius:99px;padding:1px 8px;margin-left:4px;white-space:nowrap}.tk-pr.r{background:#fee2e2;color:#b91c1c}.tk-who{display:flex;gap:8px;margin:0 0 12px;flex-wrap:wrap}.tk-who button{flex:1;min-width:150px;border:2px solid #e3e8ef;background:#fff;border-radius:14px;padding:10px 14px;font-weight:800;font-size:15px;cursor:pointer;text-align:left}.tk-who button small{display:block;font-weight:600;color:#64748b;font-size:12px}
  .tk-who button.on{border-color:#0f172a;background:#0f172a;color:#fff}.tk-who button.on small{color:#cbd5e1}
  .tk-my{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:8px;margin-bottom:12px}.tk-my>button{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:9px 12px;text-align:left;cursor:pointer;font:inherit}.tk-my>button small{display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.tk-my>button b{font-size:22px;display:block}
  .tk-my>button.r{border-color:#fecaca;background:#fff7f7}.tk-my>button.r b{color:#b91c1c}.tk-my>button.g b{color:#15803d}.tk-my>button.on{outline:3px solid #0f172a;outline-offset:-1px}
  .tk-un{background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:10px 13px;margin-bottom:12px}.tk-un h4{margin:0 0 6px;font-size:13px}.tk-un .r{display:flex;gap:8px;align-items:center;padding:5px 0;border-top:1px dashed #fde68a}.tk-un .r span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tk-un button,.tk-pop button{border:0;background:#0f766e;color:#fff;border-radius:99px;padding:6px 12px;font-weight:800;cursor:pointer}
  .tk-ov{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}.tk-pop{background:#fff;border-radius:16px;max-width:460px;width:100%;padding:16px 18px;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:tkp .25s ease-out}
  @keyframes tkp{from{transform:scale(.92);opacity:0}to{transform:none;opacity:1}}.tk-pop h3{margin:0 0 8px}.tk-pop .r{display:flex;gap:8px;align-items:center;padding:7px 0;border-top:1px solid #eef0f3}.tk-pop .r span{flex:1}.tk-pop .x{background:#e2e8f0;color:#0f172a}`;
  const beep = () => { try { const a = new (window.AudioContext || window.webkitAudioContext)(); [0, 0.18].forEach((t) => { const o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; g.gain.value = 0.08; o.connect(g); g.connect(a.destination); o.start(a.currentTime + t); o.stop(a.currentTime + t + 0.12); }); } catch (e) { } };
  const STAT = { open: 'Open', reopened: 'Re-opened', resolved: 'Resolved', closed: 'Closed' };
  const pDay = (d) => { try { const t = new Date(String(d) + 'T12:00:00'); const td = new Date(); td.setHours(12, 0, 0, 0); const n = Math.round((t - td) / 86400000); return n === 0 ? 'today' : n === 1 ? 'tomorrow' : n < 0 ? t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ' (passed)' : t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); } catch (e) { return String(d); } };   // ⏸ the promised day
  const dayLabel = (d) => { const t = new Date(); const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); const y = new Date(t); y.setDate(t.getDate() - 1);
    const dd = new Date(d + 'T00:00:00'); const nice = dd.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }); return d === ymd(t) ? 'Today · ' + nice : d === ymd(y) ? 'Yesterday · ' + nice : nice; };
  const ago = (s) => { if (!s) return ''; const m = Math.max(0, Math.round((Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000)); return m < 60 ? m + ' min ago' : m < 1440 ? Math.floor(m / 60) + ' h ago' : Math.floor(m / 1440) + ' d ago'; };
  function view(el, ctx) {
    if (!document.getElementById('tkCss')) { const st = document.createElement('style'); st.id = 'tkCss'; st.textContent = CSS + CSS2; document.head.appendChild(st); }
    const S = { days: 14, st: 'all', dept: '', sev: '', src: '', q: '', d: null, who: '', mf: '' };
    const ls = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
    async function load() {
      if (!S.d) el.innerHTML = '<div class="tk"><div style="color:#667781;padding:20px">Loading tickets…</div></div>';
      try { S.d = await api.get('bd.cx_tickets', { days: S.days }); } catch (e) { el.innerHTML = '<div class="tk"><div style="color:#b91c1c;padding:20px">Could not load: ' + esc(e.message) + '</div></div>'; return; }
      if (!S.who) S.who = ls('cx_who_' + S.d.me) || (S.d.me && !S.d.mgr ? 'mine' : 'all');   // a team member opens on their own tickets; the boss on all
      draw(); newForMe();
    }
    // 🔔 a ticket that became mine since I last looked → pop-up + sound (+ phone notification when allowed)
    function newForMe() {
      const d = S.d; if (!d || !d.me) return; const key = 'cx_seen_' + d.me, mineOpen = d.items.filter((x) => x.owner_id === d.me && (x.status === 'open' || x.status === 'reopened'));
      let seen = null; try { seen = JSON.parse(ls(key) || 'null'); } catch (e) { seen = null; }
      const ids = mineOpen.map((x) => x.id); if (!Array.isArray(seen)) { ls(key, JSON.stringify(ids)); return; }   // the first time: nothing to tell
      const fresh = mineOpen.filter((x) => !seen.includes(x.id)); ls(key, JSON.stringify(Array.from(new Set(seen.concat(ids))).slice(-500)));
      if (!fresh.length || document.querySelector('.tk-ov')) return; beep();
      try { if (window.Notification && Notification.permission === 'granted') new Notification('🎫 New ticket for you', { body: fresh.map((x) => x.code + ' · ' + x.problem + ' · ' + x.name).join('\n') }); } catch (e) { }
      const ov = document.createElement('div'); ov.className = 'tk-ov';
      ov.innerHTML = '<div class="tk-pop"><h3>🎫 New ticket' + (fresh.length > 1 ? 's' : '') + ' for you</h3>' + fresh.slice(0, 6).map((x) => '<div class="r"><span><b>' + esc(x.code) + '</b> · ' + x.icon + ' ' + esc(x.problem) + '<br><small>' + esc(x.name) + (x.sev === 'serious' ? ' · <b style="color:#b91c1c">🔴 Serious — reply in 15 min</b>' : '') + '</small></span><button type="button" data-o="' + x.id + '">Open</button></div>').join('')
        + '<div style="text-align:right;margin-top:8px"><button type="button" class="x">Later</button></div></div>';
      document.body.appendChild(ov); ov.querySelector('.x').onclick = () => ov.remove(); ov.onclick = (e) => { if (e.target === ov) ov.remove(); };
      ov.querySelectorAll('[data-o]').forEach((b) => b.onclick = () => { ov.remove(); ctx.open(+b.dataset.o); });
    }
    function rows() {
      const q = S.q.trim().toLowerCase(); let qd = q.replace(/\D+/g, ''); if (qd.length > 10) qd = qd.slice(-10); const qn = qd.length >= 4 && !/[a-z]/i.test(q);   // 📞 +91 / 91 / spaces in a phone number are ignored
      const me = S.d.me, mf = S.mf, t0 = new Date(), today = t0.getFullYear() + '-' + String(t0.getMonth() + 1).padStart(2, '0') + '-' + String(t0.getDate()).padStart(2, '0');
      const isOpen = (x) => x.status === 'open' || x.status === 'reopened';
      const myF = (x) => S.who !== 'mine' || (x.owner_id === me && (!mf || (mf === 'new' ? x.day === today : mf === 'wait' ? x.waiting : mf === 'late' ? x.late : mf === 'serious' ? isOpen(x) && x.sev === 'serious' : mf === 'open' ? isOpen(x) : mf === 'open2d' ? x.open2d : mf === 'solved' ? !isOpen(x) && String(x.resolved_at).slice(0, 10) === today : mf === 'promised' ? !!x.promise || x.missed : true)));
      return S.d.items.filter((x) => myF(x) && (S.st === 'all' || (S.st === 'open' ? (x.status === 'open' || x.status === 'reopened') : S.st === 'late' ? x.late : S.st === 'pending' ? (!!x.promise || x.missed) : x.status === S.st)) && (!S.dept || x.dept === S.dept) && (!S.sev || x.sev === S.sev) && (!S.src || x.src === S.src)
        && (!q || (x.code + ' ' + x.name + ' ' + x.phone + ' ' + x.problem + ' ' + x.order + ' ' + x.awb + ' ' + x.summary).toLowerCase().includes(q) || (qn && (String(x.phone).replace(/\D+/g, '').includes(qd) || String(x.awb).includes(qd) || String(x.order).replace(/\D+/g, '') === qd))));
    }
    function draw() {
      const d = S.d, m = d.sum, L = rows(); const by = {}; L.forEach((x) => { (by[x.day] = by[x.day] || []).push(x); });
      const bars = (obj) => { const e = Object.entries(obj || {}).slice(0, 6), mx = Math.max(1, ...e.map((x) => x[1])); return e.length ? e.map(([k, v]) => `<div class="tk-bar"><span>${esc(k)}</span><i style="width:${Math.round(v / mx * 40)}%"></i><b>${v}</b></div>`).join('') : '<div style="color:#64748b;font-size:13px">No tickets yet.</div>'; };
      const missing = (d.templates || []).filter((t) => t.status !== 'approved' && t.status !== 'partial');
      let h = '<div class="tk">';
      if (missing.length) h += `<div class="tk-tpl">📨 The ticket messages for customers are ${missing.some((t) => t.status === 'missing') ? 'not created yet' : 'waiting for Meta'} (${missing.map((t) => esc(t.label)).join(', ')}). Inside 24 hours they go as normal chat messages already; after 24 hours they need Meta's approval. ${missing.some((t) => t.status === 'missing' || t.status === 'rejected') ? '<button type="button" class="cx-btn s" id="tkTpl" style="margin-left:6px">Create & send to Meta</button>' : ''}</div>`;
      const M = d.mine || {}, myOpen = d.items.filter((x) => x.owner_id === d.me && (x.status === 'open' || x.status === 'reopened')).length;
      if (d.me) h += `<div class="tk-who"><button type="button" data-who="mine" class="${S.who === 'mine' ? 'on' : ''}">👤 My tickets<small>${myOpen} open · ${esc(d.me_name || 'you')}</small></button><button type="button" data-who="all" class="${S.who === 'all' ? 'on' : ''}">👥 All tickets<small>${m.open} open · the whole team</small></button></div>`;
      if (S.who === 'mine') { const c = (k, l, v, sub, tone) => `<button type="button" data-mf="${k}" class="${tone || ''} ${S.mf === k ? 'on' : ''}"><small>${l}</small><b>${v}</b>${sub ? '<span style="font-size:12px;color:#64748b">' + sub + '</span>' : ''}</button>`;
        h += `<div class="tk-day" style="margin-top:0">☀️ My day<small>tap a box to see those tickets${S.mf ? ' · <a href="#" data-mf="">show all mine</a>' : ''}</small></div><div class="tk-my">` + c('new', '🆕 New today', M.new_today || 0) + c('wait', '⏰ Waiting my reply', M.wait_reply || 0, M.late ? M.late + ' late' : '', (M.wait_reply || 0) ? 'r' : '') + c('serious', '🔴 Serious open', M.serious || 0, '', (M.serious || 0) ? 'r' : '') + c('promised', '⏸ Pending (promised)', M.promised || 0, M.promise_today ? M.promise_today + ' due today' : 'waiting for a date', (M.promise_today || 0) ? 'r' : '')
          + c('open', '📂 Open now', M.open || 0) + c('open2d', '⌛ Open > 2 days', M.open_2d || 0, '', (M.open_2d || 0) ? 'r' : '') + c('solved', '✅ Solved today', M.solved_today || 0, (M.solved_week || 0) + ' this week', 'g')
          + `<button type="button" disabled><small>😊 Happy (7 days)</small><b>${M.happy_pct === null || M.happy_pct === undefined ? '—' : M.happy_pct + '%'}</b><span style="font-size:12px;color:#64748b">${M.happy || 0} all good · ${M.unhappy || 0} not</span></button><button type="button" disabled><small>⚡ My first reply</small><b>${esc(M.first_reply || '—')}</b><span style="font-size:12px;color:#64748b">average, 7 days</span></button></div>`; }
      const un = d.items.filter((x) => !x.owner_id && (x.status === 'open' || x.status === 'reopened'));
      if (un.length && d.me) h += `<div class="tk-un"><h4>🙋 Not taken yet · ${un.length}</h4>` + un.slice(0, 5).map((x) => `<div class="r"><span><b>${esc(x.code)}</b> · ${x.icon} ${esc(x.problem)} · ${esc(x.name)}${x.sev === 'serious' ? ' · 🔴' : ''} <small style="color:#92400e">${ago(x.at)}</small></span><button type="button" data-take="${x.id}">Take it</button></div>`).join('') + (un.length > 5 ? `<div style="font-size:12px;color:#92400e">+${un.length - 5} more</div>` : '') + '</div>';
      if (S.who !== 'mine') h += `<div class="tk-k"><div><small>New today</small><b>${m.new_today}</b><span>${m.new_week} this week</span></div><div class="r"><small>🔴 Serious open</small><b>${m.serious_open}</b><span>reply in 15 min</span></div><div><small>Open now</small><b>${m.open}</b><span>${m.open_old} from old days</span></div>
        <div class="t"><small>📍 Tracking open</small><b>${m.dept.tracking}</b></div><div class="c"><small>🛠 Complaint open</small><b>${m.dept.complaint}</b></div><div class="r"><small>⏰ Late reply</small><b>${m.late}</b><span>${m.reopened} re-opened</span></div><div><small>✅ Resolved today</small><b>${m.resolved_today}</b></div></div>`;
      h += `<div class="tk-row2"><div class="tk-box"><h4>Where tickets came from · last ${d.days} days</h4>${bars(m.src)}</div><div class="tk-box"><h4>What for · last ${d.days} days</h4>${bars(m.problem)}</div></div>`;
      const chip = (k, v, l) => `<button type="button" data-${k}="${v}" class="${S[k] === v ? 'on' : ''}">${l}</button>`;
      h += `<div class="tk-f">${chip('st', 'all', 'All')}${chip('st', 'open', 'Open')}${chip('st', 'late', '⏰ Late')}${chip('st', 'pending', '⏸ Pending')}${chip('st', 'reopened', '🔁 Re-opened')}${chip('st', 'resolved', '✅ Resolved')}${chip('st', 'closed', 'Closed')}</div>
        <div class="tk-f">${chip('dept', '', 'Both departments')}${chip('dept', 'tracking', '📍 Tracking')}${chip('dept', 'complaint', '🛠 Complaint')}<span style="width:8px"></span>${chip('sev', '', 'All')}${chip('sev', 'serious', '🔴 Serious')}${chip('sev', 'basic', '⚪ Basic')}</div>
        <div class="tk-f"><input type="search" id="tkQ" placeholder="🔍 Ticket, name, phone, order or tracking number" value="${esc(S.q)}"><select id="tkSrc"><option value="">Every source</option>${Object.keys(m.src || {}).map((s) => `<option ${S.src === s ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select><select id="tkDays">${[7, 14, 30, 60, 90].map((n) => `<option value="${n}" ${S.days === n ? 'selected' : ''}>Last ${n} days</option>`).join('')}</select><button type="button" id="tkCsv">⬇ Excel</button><button type="button" id="tkR">↻</button></div>`;
      h += `<div class="tk-h"><span>Ticket</span><span>Time</span><span>Customer</span><span>Problem</span><span>Department</span><span>Level</span><span>Status</span><span>Owner</span><span>From</span></div>`;
      const days = Object.keys(by).sort().reverse();
      h += days.length ? days.map((dy) => { const it = by[dy], op = it.filter((x) => x.status === 'open' || x.status === 'reopened').length;
        return `<div class="tk-day">${esc(dayLabel(dy))}<small>${it.length} ticket${it.length === 1 ? '' : 's'} · ${op} open</small></div>` + it.map((x) => { const done = x.status === 'resolved' || x.status === 'closed';
          return `<div class="tk-l ${x.sev === 'serious' && !done ? 's' : ''} ${done ? 'd' : ''} ${x.late ? 'late' : ''}" data-id="${x.id}" role="button" tabindex="0"><span class="cd">${esc(x.code)}${x.reopen ? ' <small style="color:#b91c1c">🔁 ×' + (x.reopen + 1) + '</small>' : ''}</span><span>${esc(String(x.at).slice(11, 16))}</span>
            <span><b>${esc(x.name)}</b><small>${x.phone ? '+91 ' + esc(x.phone) : ''}${x.order ? ' · ' + esc(x.order) : ''}</small></span><span>${x.icon} ${esc(x.problem)}${x.promise ? ' <em class="tk-pr">⏸ ' + esc(pDay(x.promise)) + '</em>' : ''}${x.missed ? ' <em class="tk-pr r">❌ promise missed</em>' : ''}<small>${esc(x.promise_what ? '⏸ ' + x.promise_what : x.summary)}</small></span>
            <span><span class="tk-p ${x.dept === 'tracking' ? 'trk' : 'cmp'}">${x.dept === 'tracking' ? '📍 Tracking' : '🛠 Complaint'}</span></span><span><span class="tk-p ${x.sev === 'serious' ? 'ser' : 'bas'}">${x.sev === 'serious' ? '🔴 Serious' : '⚪ Basic'}</span></span>
            <span><span class="tk-p ${esc(x.status)}">${STAT[x.status] || esc(x.status)}</span>${x.late ? '<small style="color:#b91c1c;font-weight:700">⏰ no reply yet</small>' : `<small>${done ? 'solved ' + ago(x.resolved_at || x.updated) : 'updated ' + ago(x.updated)}</small>`}</span>
            <span>${esc(x.owner)}<small>${esc(x.desk)}</small></span><span>${esc(x.src)}</span></div>`; }).join(''); }).join('') : '<div style="padding:30px;text-align:center;color:#64748b">No tickets match. 🎉</div>';
      el.innerHTML = h + '</div>';
      el.querySelectorAll('[data-st],[data-dept],[data-sev]').forEach((b) => { b.onclick = () => { const k = b.dataset.st !== undefined ? 'st' : b.dataset.dept !== undefined ? 'dept' : 'sev'; S[k] = b.dataset[k]; draw(); }; });
      el.querySelectorAll('[data-who]').forEach((b) => { b.onclick = () => { S.who = b.dataset.who; S.mf = ''; ls('cx_who_' + S.d.me, S.who); draw(); }; });
      el.querySelectorAll('[data-mf]').forEach((b) => { b.onclick = (e) => { e.preventDefault(); S.mf = S.mf === b.dataset.mf ? '' : b.dataset.mf; if (S.mf) S.st = 'all'; draw(); }; });
      el.querySelectorAll('[data-take]').forEach((b) => { b.onclick = async () => { b.disabled = true; b.textContent = 'Taking…'; try { await api.post('bd.cx_assign', { id: +b.dataset.take, emp_id: S.d.me }); try { const k = 'cx_seen_' + S.d.me; const sn = JSON.parse(ls(k) || '[]'); sn.push(+b.dataset.take); ls(k, JSON.stringify(sn)); } catch (e) { } await load(); } catch (e) { alert(e.message); b.disabled = false; b.textContent = 'Take it'; } }; });
      if (S.d.me && window.Notification && Notification.permission === 'default' && !ls('cx_npq')) { ls('cx_npq', '1'); try { Notification.requestPermission(); } catch (e) { } }   // once: allow phone / desktop alerts for new tickets
      const q = el.querySelector('#tkQ'); q.oninput = () => { S.q = q.value; const p = q.selectionStart; draw(); const n = el.querySelector('#tkQ'); n.focus(); try { n.setSelectionRange(p, p); } catch (e) { } };
      el.querySelector('#tkSrc').onchange = function () { S.src = this.value; draw(); };
      el.querySelector('#tkDays').onchange = function () { S.days = +this.value; load(); };
      el.querySelector('#tkR').onclick = () => load();
      el.querySelector('#tkCsv').onclick = () => { const cols = ['code', 'at', 'name', 'phone', 'order', 'awb', 'problem', 'summary', 'dept', 'sev', 'status', 'owner', 'desk', 'src', 'resolved_at']; const csv = [cols.join(',')].concat(rows().map((x) => cols.map((c) => '"' + String(x[c] == null ? '' : x[c]).replace(/"/g, '""') + '"').join(','))).join('\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = 'tickets.csv'; a.click(); };
      const tp = el.querySelector('#tkTpl'); if (tp) tp.onclick = async () => { tp.disabled = true; try { await api.post('bd.cx_tpls', {}); S.d = null; load(); } catch (e) { alert(e.message); tp.disabled = false; } };
      el.querySelectorAll('.tk-l[data-id]').forEach((r) => { r.onclick = () => ctx.open(+r.dataset.id); r.onkeydown = (e) => { if (e.key === 'Enter') ctx.open(+r.dataset.id); }; });
    }
    load(); return { reload: load };
  }
  return { view };
})();
