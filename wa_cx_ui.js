/**
 * wa_cx_ui.js — 🗂 Complaints in WhatsApp (Inbox → Complaints). Server: complaints_more.php (bd.cx_wa_board, bd.cx_wa_make).
 *   Every ticket (C-0112): customer, problem, desk, owner, when the customer last wrote, our last reply (who / when), a red flag when nobody answered
 *   (30 min in working hours, 2 h at night), and Claude's one line: solved / in progress / not solved. Tap a ticket → the chat opens on the side.
 *   Complaint chats without a ticket are listed on top (one tap makes the tickets). The Complaint team and Admin also see each person's score card.
 *   A salesperson sees only the tickets of his own customers, read-only.
 */
(function () {
  'use strict';
  if (window.WaCx || !window.WaPanels || !WaPanels.util || typeof api === 'undefined') return;
  const { esc, toast } = WaPanels.util;
  const CSS = `.wcx-f{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}.wcx-f button{border:1px solid #d6dde6;background:#fff;border-radius:99px;padding:7px 13px;font-weight:700;font-size:13px;min-height:36px;cursor:pointer}.wcx-f button.on{background:#111b21;border-color:#111b21;color:#fff}
  .wcx-k{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px}.wcx-k div{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:10px}.wcx-k small{display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.wcx-k b{font-size:22px}
  .wcx-r{background:#fff;border:1px solid #e3e8ef;border-left:5px solid #22c55e;border-radius:14px;padding:10px 12px;margin-bottom:8px;cursor:pointer}.wcx-r.y{border-left-color:#f59e0b}.wcx-r.r{border-left-color:#fb923c;background:#fffaf5}.wcx-r.g{opacity:.85}
  .wcx-h{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}.wcx-h b{font-size:15px}.wcx-c{font-family:ui-monospace,Menlo,monospace;font-weight:800;background:#eef2ff;color:#3730a3;border-radius:6px;padding:1px 6px;font-size:12px}
  .wcx-m{font-size:12.5px;color:#475569;margin-top:3px;line-height:1.45}.wcx-m .bad{color:#c2410c;font-weight:800}.wcx-ai{margin-top:6px;font-size:13px;line-height:1.45;border-radius:10px;padding:6px 9px;background:#f1f5f9}.wcx-ai.ns{background:#f5f1ff;color:#4c2f91}.wcx-ai.ip{background:#fef3c7;color:#78350f}.wcx-ai.ok{background:#dcfce7;color:#14532d}
  .wcx-miss{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:10px 12px;margin-bottom:10px;color:#7c2d12}.wcx-t{width:100%;border-collapse:collapse;font-size:13px}.wcx-t th{text-align:left;font-size:11px;color:#64748b;padding:6px 6px;border-bottom:1px solid #e3e8ef}.wcx-t td{padding:7px 6px;border-bottom:1px solid #f1f5f9}
  @media (max-width:520px){.wcx-k b{font-size:18px}}`;
  const ago = (s) => { if (!s) return ''; const m = Math.max(0, Math.round((Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000)); return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : m < 1440 ? Math.floor(m / 60) + ' h ' + (m % 60) + ' min ago' : Math.floor(m / 1440) + ' day' + (m >= 2880 ? 's' : '') + ' ago'; };
  const AI = { not_solved: ['ns', '🔴 Not solved'], in_progress: ['ip', '🟡 In progress'], solved: ['ok', '🟢 Solved'] };
  function screen(el, ctx) {
    if (!document.getElementById('wcxCss')) { const s = document.createElement('style'); s.id = 'wcxCss'; s.textContent = CSS; document.head.appendChild(s); }
    const S = { f: 'open', d: null };
    async function load() {
      if (!S.d) el.innerHTML = '<div class="wp"><div class="wp-hint">Reading the complaints…</div></div>';
      try { S.d = await api.get('bd.cx_wa_board', { f: S.f }); } catch (e) { el.innerHTML = `<div class="wp"><div class="wp-note">${esc(e.message)}</div></div>`; return; }
      draw();
    }
    function draw() {
      const d = S.d, L = d.items || [], flagged = L.filter((x) => x.flag).length, ns = L.filter((x) => x.ai && x.ai.state === 'not_solved').length;
      let h = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>Complaints</h2><p>${d.full ? 'Every ticket, who answered last and when, and whether it is really solved. An orange ticket = the customer is waiting with no reply (30 min by day, 2 h at night).' : 'The complaints of your customers (read-only — the Complaint team handles them).'}</p></div><button type="button" class="wp-btn" id="wcxR">↻ Refresh</button></div>`;
      h += `<div class="wcx-k"><div><small>Open</small><b>${d.open}</b></div><div><small>❌ No reply</small><b style="color:${flagged ? '#c2410c' : 'inherit'}">${flagged}</b></div><div><small>🤖 Not solved</small><b>${ns}</b></div></div>`;
      h += `<div class="wcx-f">${[['open', 'Open'], ['noreply', '❌ Not replied'], ['ns', '🤖 Not solved'], ['solved', '✅ Solved (14 days)'], ['all', 'All']].map(([k, l]) => `<button type="button" data-f="${k}" class="${S.view === k || (!S.view && k === S.f) ? 'on' : ''}">${l}</button>`).join('')}</div>`;
      if (d.full && (d.missing || []).length) h += `<div class="wcx-miss">⚠ <b>${d.missing.length} complaint chat${d.missing.length === 1 ? '' : 's'} without a ticket</b>: ${d.missing.slice(0, 6).map((m) => esc(m.name || '+91 ' + m.phone)).join(', ')}${d.missing.length > 6 ? '…' : ''} <button type="button" class="wp-btn s" id="wcxMk" style="margin-left:6px">Make the tickets</button></div>`;
      const view = S.view || S.f; const rows = L.filter((x) => view === 'noreply' ? x.flag : view === 'ns' ? (x.ai && x.ai.state === 'not_solved') : true);
      h += rows.length ? rows.map((x) => {
        const done = x.status === 'resolved' || x.status === 'closed', cls = x.flag || x.repeat ? 'r' : done ? 'g' : (x.ai && x.ai.state === 'not_solved') ? 'y' : '';
        const ai = x.ai ? AI[x.ai.state] || AI.in_progress : null;
        return `<div class="wcx-r ${cls}" data-p="${esc(x.phone)}"><div class="wcx-h"><span class="wcx-c">${esc(x.code)}</span>${x.repeat ? `<span style="background:#8b5cf6;color:#fff;border-radius:99px;padding:1px 8px;font-size:11.5px;font-weight:800">🔁 Repeat ×${x.repeat.n}</span>` : ''}<b>${esc(x.name)}</b><span>${x.icon} ${esc(x.problem)}</span><span class="wp-hint" style="margin:0">· ${esc(x.desk)} · ${esc(x.owner)}${done ? ' · ✅ ' + esc(x.status) : x.status === 'reopened' ? ' · 🔁 reopened' : ''}${x.happy === 1 ? ' · 👍 happy' : x.happy === 0 ? ' · 👎 not happy' : ''}</span></div>
          <div class="wcx-m">${x.last_in ? 'Customer wrote ' + ago(x.last_in) : 'Raised ' + ago(x.raised_at)}${x.flag ? ` · <span class="bad">❌ NO REPLY for ${x.wait_min >= 60 ? Math.floor(x.wait_min / 60) + ' h ' + (x.wait_min % 60) + ' min' : x.wait_min + ' min'}</span>` : ''}${x.last_out ? ` · last reply: <b>${esc(x.last_who || 'us')}</b> ${ago(x.last_out)}` : ' · <b>no reply yet</b>'}</div>
          ${x.repeat ? `<div class="wcx-ai ns" style="border:1px solid #ddd6fe">🔁 <b>Repeat ×${x.repeat.n}</b> — ${esc(x.repeat.line)}</div>` : ''}${ai ? `<div class="wcx-ai ${ai[0]}">🤖 <b>${ai[1]}</b> — ${esc(x.ai.line)}</div>` : '<div class="wcx-ai">🤖 Claude reads this chat on the next refresh…</div>'}</div>`;
      }).join('') : '<div class="wp-cd"><div class="wp-hint" style="margin:0">Nothing here. 🎉</div></div>';
      if (d.full && (d.card || []).length) h += `<div class="wp-cd" style="margin-top:12px"><h3>How the Complaint team works <small style="font-weight:600;color:#64748b">(last 30 days)</small></h3><div style="overflow-x:auto"><table class="wcx-t"><thead><tr><th>Person</th><th>Tickets</th><th>First reply</th><th>No reply now</th><th>Solved</th><th>Reopened</th><th>Happy</th></tr></thead><tbody>${d.card.map((c) => `<tr><td><b>${esc(c.name)}</b></td><td>${c.tickets}</td><td>${c.first_reply_min == null ? '—' : c.first_reply_min < 60 ? c.first_reply_min + ' min' : Math.round(c.first_reply_min / 6) / 10 + ' h'}</td><td style="${c.unanswered ? 'color:#c2410c;font-weight:800' : ''}">${c.unanswered}</td><td>${c.solved_pct}%</td><td>${c.reopened}</td><td>${c.happy_pct == null ? '—' : c.happy_pct + '%'}</td></tr>`).join('')}</tbody></table></div></div>`;
      el.innerHTML = h + '</div>';
      el.querySelector('#wcxR').onclick = () => load();
      el.querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { const k = b.dataset.f; if (k === 'noreply' || k === 'ns') { S.view = k; if (S.f !== 'open') { S.f = 'open'; load(); return; } draw(); } else { S.view = ''; S.f = k; load(); } }; });
      el.querySelectorAll('.wcx-r[data-p]').forEach((r) => { r.onclick = () => { if (r.dataset.p && ctx && ctx.openChat) ctx.openChat(r.dataset.p); }; });
      const mk = el.querySelector('#wcxMk'); if (mk) mk.onclick = async () => { mk.disabled = true; try { const r = await api.post('bd.cx_wa_make', {}); toast((r.made || 0) + ' ticket(s) made'); load(); } catch (e) { toast(e.message, true); mk.disabled = false; } };
    }
    load(); const t = setInterval(() => { if (!document.hidden && el.isConnected) load(); else if (!el.isConnected) clearInterval(t); }, 60000);
    return () => load();
  }
  window.WaCx = { screen };
  WaPanels.add('cxboard', screen);
})();
