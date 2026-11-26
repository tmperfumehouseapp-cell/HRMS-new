/* Give queries → "🕐 By hour — queries given": tap a person's chip (Aditya 3) and the customers behind that number open in a window — the list on the left, the picked customer's REAL WhatsApp chat
   (wa_one.html: read, reply, take over, assign) on the right. On a phone the list comes first and a tap opens the chat over it. Read-only list from qb.hour_chats (the same rows the chips count).
   A chip only needs:  data-qbc  data-d="2026-11-26"  data-h="12"  data-e="57"  data-n="Aditya"   (no data-e = the whole team in that hour; no data-h = his whole day). Needs api.js. */
(function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const hLbl = (h) => { h = Number(h); return (h % 12 === 0 ? 12 : h % 12) + ' ' + (h < 12 ? 'AM' : 'PM'); };
  const tLbl = (dt) => { const m = /(\d{2}):(\d{2})/.exec(String(dt).slice(11)); if (!m) return ''; const h = Number(m[1]); return (h % 12 === 0 ? 12 : h % 12) + ':' + m[2] + ' ' + (h < 12 ? 'AM' : 'PM'); };
  const pretty = (p) => { const d = String(p).replace(/\D/g, '').slice(-10); return d.length === 10 ? '+91 ' + d.slice(0, 5) + ' ' + d.slice(5) : String(p); };
  const mins = (n) => (n < 60 ? n + ' min' : Math.floor(n / 60) + ' h ' + (n % 60) + ' min');
  const rupee = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  function css() {
    if (document.getElementById('qbcCss')) return; const st = document.createElement('style'); st.id = 'qbcCss'; st.textContent = `
[data-qbc]{cursor:pointer} [data-qbc]:hover{background:#dbeafe !important} [data-qbc]:focus-visible{outline:2px solid #2563eb;outline-offset:1px}
.qbc-all{font-style:normal;display:inline-block;background:#fff !important;border:1px dashed #9ca3af;border-radius:99px;padding:0 8px;margin:0 0 3px 6px;font-weight:700;font-size:11.5px;color:#374151}
.qbc-ov{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9000;display:flex;align-items:center;justify-content:center;padding:1.5vh 1vw}
.qbc-w{background:#fff;border-radius:14px;width:98vw;max-width:1500px;height:96vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);font-size:14px;color:#111827}
.qbc-h{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #e5e7eb;background:#f9fafb;flex-wrap:wrap}
.qbc-h b.t{font-size:15px;font-weight:900} .qbc-h .sub{color:#6b7280;font-size:12.5px}
.qbc-h .sw{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap} .qbc-h .sw button{font:inherit;font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px;background:#f1f5f9;border:0;cursor:pointer;color:#111827} .qbc-h .sw button.on{background:#111827;color:#fff}
.qbc-h .x,.qbc-h .rf{font:inherit;border:0;background:none;cursor:pointer;color:#6b7280;font-size:18px;padding:2px 6px;border-radius:8px} .qbc-h .rf{font-size:14px} .qbc-h .x:hover,.qbc-h .rf:hover{background:#e5e7eb}
.qbc-b{flex:1;min-height:0;display:grid;grid-template-columns:minmax(280px,340px) 1fr}
.qbc-l{border-right:1px solid #e5e7eb;overflow:auto;min-height:0} .qbc-r{min-height:0;background:#efeae2;display:flex;flex-direction:column;position:relative}
.qbc-r iframe{flex:1;width:100%;border:0;display:block;background:#efeae2} .qbc-r .bk{display:none}
.qbc-sum{display:flex;gap:6px;padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:11.5px;font-weight:700;flex-wrap:wrap;position:sticky;top:0;background:#fff;z-index:1}
.qbc-sum button{font:inherit;font-weight:700;border:0;cursor:pointer;padding:2px 8px;border-radius:99px;background:#f1f5f9;color:#111827} .qbc-sum button.on{box-shadow:0 0 0 2px #111827} .qbc-sum .g{background:#dcfce7;color:#166534} .qbc-sum .r{background:#fee2e2;color:#991b1b} .qbc-sum .o{background:#fef3c7;color:#92400e}
.qbc-it{padding:10px 12px;border-bottom:1px solid #eef1f4;cursor:pointer;display:grid;grid-template-columns:1fr auto;gap:2px 8px} .qbc-it:hover{background:#f9fafb} .qbc-it.on{background:#eff6ff;box-shadow:inset 3px 0 0 #2563eb}
.qbc-it .nm{font-weight:800;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap} .qbc-it .ph{color:#6b7280;font-size:12px} .qbc-it .tm{font-size:11.5px;color:#6b7280;text-align:right;white-space:nowrap}
.qbc-it .tg{grid-column:1/-1;display:flex;gap:5px;flex-wrap:wrap;margin-top:3px}
.qbc-c{font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:99px;background:#f1f5f9;color:#374151} .qbc-c.meta{background:#e0e7ff;color:#3730a3} .qbc-c.new{background:#cffafe;color:#155e75} .qbc-c.ok{background:#dcfce7;color:#166534} .qbc-c.wait{background:#fee2e2;color:#991b1b} .qbc-c.ord{background:#fef3c7;color:#92400e}
.qbc-e{padding:26px 14px;text-align:center;color:#6b7280;font-size:13px} .qbc-r .qbc-e{margin:auto}
@media (max-width:760px){.qbc-ov{padding:0}.qbc-w{width:100vw;height:100dvh;border-radius:0}.qbc-b{grid-template-columns:1fr;position:relative}.qbc-l{border-right:0}
  .qbc-r{display:none;position:absolute;inset:0;z-index:2}.qbc-b.chat .qbc-r{display:flex}.qbc-r .bk{display:block;font:inherit;font-weight:800;font-size:13px;border:0;border-bottom:1px solid #e5e7eb;background:#fff;padding:9px 12px;text-align:left;cursor:pointer;color:#2563eb}
  .qbc-h{position:relative} .qbc-h b.t{width:100%;padding-right:78px} .qbc-h .x{position:absolute;top:7px;right:8px} .qbc-h .rf{position:absolute;top:9px;right:44px}
  .qbc-h .sw{margin-left:0;width:100%;flex-wrap:nowrap;overflow-x:auto} .qbc-h .sw button{white-space:nowrap}}`; document.head.appendChild(st);
  }
  function open(o) {   // o: { date, hour (null = whole day), emp (0 = whole team), name }
    css(); const prev = document.querySelector('.qbc-ov'); if (prev) prev.remove();
    const S = { date: o.date || '', hour: o.hour == null || o.hour === '' ? null : Number(o.hour), emp: Number(o.emp) || 0, name: o.name || '', rows: [], hours: [], day: 0, sel: '', f: 'all', tok: 0 };
    const ov = document.createElement('div'); ov.className = 'qbc-ov'; ov.innerHTML = `<div class="qbc-w" role="dialog" aria-modal="true" aria-label="Queries by hour"><div class="qbc-h"><b class="t"></b><span class="sub"></span><div class="sw"></div><button type="button" class="rf" title="Refresh" aria-label="Refresh">🔄</button><button type="button" class="x" aria-label="Close">✕</button></div>
      <div class="qbc-b"><div class="qbc-l"><div class="qbc-e">Loading…</div></div><div class="qbc-r"><button type="button" class="bk">← Back to the list</button><div class="qbc-e">Tap a customer on the left.</div></div></div></div>`;
    document.body.appendChild(ov);
    const $ = (s) => ov.querySelector(s), L = $('.qbc-l'), R = $('.qbc-r'), B = $('.qbc-b');
    const close = () => { ov.remove(); document.removeEventListener('keydown', key); };
    const key = (e) => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', key);
    $('.x').onclick = close; ov.addEventListener('mousedown', (e) => { if (e.target === ov) close(); });
    $('.bk').onclick = () => { B.classList.remove('chat'); };
    const phone = () => window.matchMedia('(max-width:760px)').matches;
    function chat(ph, user) {
      S.sel = ph; L.querySelectorAll('.qbc-it').forEach((el) => el.classList.toggle('on', el.dataset.p === ph));
      const k = String(ph).replace(/\D/g, '').slice(-10), cur = R.querySelector('iframe');
      if (!cur || cur.dataset.k !== k) { R.querySelectorAll('iframe,.qbc-e').forEach((n) => n.remove()); const f = document.createElement('iframe'); f.title = 'WhatsApp chat'; f.allow = 'microphone; clipboard-write'; f.dataset.k = k; f.src = 'wa_one.html?phone=' + encodeURIComponent(k); R.appendChild(f); }
      if (user) B.classList.add('chat');
    }
    function draw() {
      const who = S.emp ? S.name || 'Salesperson' : 'Whole team', when = S.hour == null ? 'Whole day' : hLbl(S.hour), n = S.rows.length;
      $('.t').textContent = who + ' · ' + when; $('.sub').textContent = S.count + ' quer' + (S.count === 1 ? 'y' : 'ies') + ' · ' + S.date + (S.at ? ' · ' + S.at : '') + (S.more ? ' · first ' + n + ' shown' : '');
      $('.sw').innerHTML = S.hours.map((h) => `<button type="button" data-h="${h.hour}" class="${S.hour === h.hour ? 'on' : ''}">${hLbl(h.hour)} · ${h.n}</button>`).join('') + `<button type="button" data-h="" class="${S.hour == null ? 'on' : ''}">Whole day · ${S.day}</button>`;
      $('.sw').querySelectorAll('button').forEach((b) => { b.onclick = () => { S.hour = b.dataset.h === '' ? null : Number(b.dataset.h); S.sel = ''; load(); }; });
      const ans = S.rows.filter((r) => r.fm != null).length, wait = S.rows.filter((r) => r.wait).length, ord = S.rows.filter((r) => r.orders > 0).length;
      const list = S.rows.filter((r) => (S.f === 'wait' ? r.wait : S.f === 'none' ? r.fm == null : S.f === 'ord' ? r.orders > 0 : true));
      L.innerHTML = `<div class="qbc-sum"><button type="button" data-f="all" class="${S.f === 'all' ? 'on' : ''}">All ${n}</button><button type="button" data-f="wait" class="r ${S.f === 'wait' ? 'on' : ''}">⏳ Waiting ${wait}</button><button type="button" data-f="none" class="${S.f === 'none' ? 'on' : ''}">No person answered ${n - ans}</button><button type="button" data-f="ord" class="o ${S.f === 'ord' ? 'on' : ''}">🛒 Order ${ord}</button></div>`
        + (list.length ? list.map((r) => `<div class="qbc-it ${S.sel === r.phone ? 'on' : ''}" data-p="${esc(r.phone)}" tabindex="0"><div><div class="nm">${esc(r.name || '(no name)')}</div><div class="ph">${esc(pretty(r.phone))}</div></div><div class="tm">${esc(tLbl(r.at))}${!S.emp && r.holder ? '<br>' + esc(r.holder.split(' ')[0]) : ''}</div>
            <div class="tg"><span class="qbc-c ${r.type === 'meta' ? 'meta' : r.type === 'new' ? 'new' : ''}">${esc(r.type_label || r.type)}</span>${r.fm != null ? `<span class="qbc-c ok">✓ answered in ${mins(r.fm)}</span>` : '<span class="qbc-c wait">no person answered yet</span>'}${r.wait ? '<span class="qbc-c wait">⏳ customer is waiting</span>' : ''}${r.orders > 0 ? `<span class="qbc-c ord">🛒 order${r.amt > 0 ? ' ' + rupee(r.amt) : ''}</span>` : ''}${!r.emp ? '<span class="qbc-c">nobody holds it</span>' : ''}${r.closed ? '<span class="qbc-c">closed</span>' : ''}</div></div>`).join('')
          : `<div class="qbc-e">${n ? 'Nobody in this filter.' : 'No queries here.'}</div>`);
      L.querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { S.f = b.dataset.f; draw(); }; });
      L.querySelectorAll('.qbc-it').forEach((el) => { el.onclick = () => chat(el.dataset.p, true); el.onkeydown = (e) => { if (e.key === 'Enter') chat(el.dataset.p, true); }; });
    }
    async function load() {
      const tok = ++S.tok; if (!S.rows.length) L.innerHTML = '<div class="qbc-e">Loading…</div>';
      try {
        const q = { date: S.date }; if (S.hour != null) q.hour = S.hour; if (S.emp) q.emp = S.emp;
        const d = await api.get('qb.hour_chats', q); if (tok !== S.tok || !ov.isConnected) return;
        if (!d.live) { S.rows = []; L.innerHTML = '<div class="qbc-e">This day is before WhatsApp Sales: the counts were typed by hand, so there is no customer list behind them.</div>'; return; }
        S.rows = d.rows || []; S.hours = d.hours || []; S.day = d.day || 0; S.count = d.count || S.rows.length; S.more = !!d.more; S.at = d.at || ''; S.date = d.date || S.date;
        draw();
        if (!phone() && S.rows.length && !S.rows.some((r) => r.phone === S.sel)) chat(S.rows[0].phone, false);   // PC: the first customer's chat is open straight away
        if (!S.rows.length) { R.querySelectorAll('iframe,.qbc-e').forEach((n) => n.remove()); const e = document.createElement('div'); e.className = 'qbc-e'; e.textContent = 'No chat to show.'; R.appendChild(e); }
      } catch (err) { if (tok !== S.tok) return; L.innerHTML = `<div class="qbc-e" style="color:#b91c1c">${esc(err.message || 'Could not load.')}</div>`; }
    }
    $('.rf').onclick = load; load();
  }
  css();
  document.addEventListener('click', (e) => { const el = e.target.closest && e.target.closest('[data-qbc]'); if (!el) return; e.preventDefault(); open({ date: el.dataset.d || '', hour: el.dataset.h === undefined ? null : el.dataset.h, emp: el.dataset.e || 0, name: el.dataset.n || '' }); });
  document.addEventListener('keydown', (e) => { if (e.key !== 'Enter' && e.key !== ' ') return; const el = e.target.closest && e.target.closest('[data-qbc]'); if (!el) return; e.preventDefault(); el.click(); });
  window.QbChats = { open };
})();
