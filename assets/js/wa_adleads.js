/**
 * wa_adleads.js — WhatsApp → Queries & reports → 📣 Ad leads (whatsapp.html, Admin / HR).
 * One day, one line per customer who came from a Meta click-to-WhatsApp ad: time, the number they wrote to, the ad,
 * new or returning, and the first message they wrote that day. Pick a day and a number; nothing has to be opened to read it.
 * Needs api.js and wa_panels.js (loaded first). Reads wa.g_adleads (wa_adleads.php); it loads only when opened or refreshed.
 */
(function () {
  if (!window.WaPanels) return;
  const { esc, $, when, errBox, loading } = WaPanels.util;
  const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const shift = (day, n) => { const d = new Date(day + 'T12:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const time = (s) => { const w = when(s); const i = w.indexOf(', '); return i >= 0 ? w.slice(i + 2) : w; };
  const CSS = `
  .al-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 10px}
  .al-bar input[type=date]{min-height:36px;padding:4px 8px;border:1px solid #d7ded9;border-radius:10px;font:inherit;width:auto}
  .al-btn{border:1px solid #d7ded9;background:#fff;border-radius:10px;min-height:36px;padding:0 12px;font:700 12.5px inherit;cursor:pointer;color:inherit}
  .al-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
  .al-chip{border:1px solid #d7ded9;background:#fff;border-radius:999px;padding:6px 12px;font:700 12.5px inherit;cursor:pointer;color:#33423a}
  .al-chip.on{background:#1b2430;border-color:#1b2430;color:#fff}.al-chip i{font-style:normal;opacity:.75;margin-left:6px}
  .al-sum{display:flex;gap:18px;flex-wrap:wrap;margin:0 0 10px;font-size:13px;color:#4a5a52}.al-sum b{font-size:20px;color:#1b2430;margin-right:5px}
  .al-tb td{vertical-align:top}.al-tb .who b{display:block}.al-tb .who span{font-size:12px;color:#6b7a73}
  .al-msg{max-width:46ch;white-space:pre-wrap;word-break:break-word}.al-ad{max-width:28ch;color:#4a5a52;font-size:12.5px}
  .al-new{display:inline-block;font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px;background:#dcf7e6;color:#12794a}.al-new.r{background:#eef1ef;color:#4a5a52}
  .al-row{cursor:pointer}.al-row:hover{background:#f6faf7}
  @media (max-width:820px){.al-tb,.al-tb tbody{display:block;width:100%}.al-tb thead{display:none}.al-tb tr{display:block;padding:8px 0;border-top:1px solid #edf1ee}.al-tb td{display:block;border:0;padding:2px 0}.al-msg,.al-ad{max-width:none}}
  `;
  WaPanels.add('adleads', (el, ctx) => {
    if (!document.getElementById('alCss')) { const s = document.createElement('style'); s.id = 'alCss'; s.textContent = CSS; document.head.appendChild(s); }
    let day = today(), number = 0;
    el.innerHTML = loading;
    async function load() {
      let D; try { D = await api.get('wa.g_adleads', { date: day, number }); } catch (e) { el.innerHTML = errBox(e.message); return; }
      const allN = (D.numbers || []).reduce((s, n) => s + (n.n || 0), 0);
      const chips = [['0', 'All numbers', D.all != null ? D.all : allN]].concat((D.numbers || []).filter((n) => n.n > 0 || n.id === number).map((n) => [String(n.id), n.label || 'Number', n.n]));
      el.innerHTML = `<div class="wp"><div class="wp-top"><div style="flex:1;min-width:200px"><h2>📣 Ad leads</h2><p>Customers who came from a Meta click-to-WhatsApp ad on the chosen day, with the first message they wrote. One line per customer.</p></div></div>
      <div class="al-bar"><button type="button" class="al-btn" id="alPrev">‹</button><input type="date" id="alDay" value="${esc(D.date)}" max="${today()}"><button type="button" class="al-btn" id="alNext" ${D.date >= today() ? 'disabled' : ''}>›</button><button type="button" class="al-btn" id="alToday">Today</button><button type="button" class="al-btn" id="alRef">Refresh</button></div>
      <div class="al-chips">${chips.map(([k, l, n]) => `<button type="button" class="al-chip ${String(number) === k ? 'on' : ''}" data-n="${k}">📱 ${esc(l)}<i>${n}</i></button>`).join('')}</div>
      <div class="al-sum"><span><b>${D.total}</b>ad leads</span><span><b>${D.new}</b>new customers</span><span><b>${D.total - D.new}</b>came before</span></div>
      <div class="wp-cd">${D.rows.length ? `<table class="wp-tb al-tb"><thead><tr><th>Time</th><th>Customer</th><th>First message that day</th><th>Ad</th><th>Number</th></tr></thead><tbody>${D.rows.map((r, i) => `<tr class="al-row" data-i="${i}" title="Open the chat">
        <td style="white-space:nowrap">${esc(time(r.at))}</td>
        <td class="who"><b>${esc(r.name || r.phone)}</b><span>${esc(r.phone)}</span> <span class="al-new ${r.new ? '' : 'r'}">${r.new ? 'New' : 'Came before'}</span></td>
        <td><div class="al-msg">${esc(r.first || '—')}</div></td>
        <td><div class="al-ad">${esc(r.ad || 'Meta ad')}</div></td>
        <td style="white-space:nowrap">${esc(r.number || '—')}</td></tr>`).join('')}</tbody></table>` : '<div class="wp-empty">No ad leads on this day' + (number ? ' for this number' : '') + '.</div>'}
      <div class="wp-hint" style="margin-top:8px">${D.more ? '<b>Only the newest 1,500 queries of the day are shown.</b> ' : ''}An ad lead is a query the HRMS opened from a Meta ad: the message carried the ad, or the customer first came from an ad. “New” = no earlier query or order. The ad shown is the first ad that brought the customer. The day runs midnight to midnight.</div></div></div>`;
      $(el, '#alDay').onchange = (e) => { if (e.target.value) { day = e.target.value; load(); } };
      $(el, '#alPrev').onclick = () => { day = shift(day, -1); load(); };
      $(el, '#alNext').onclick = () => { if (day < today()) { day = shift(day, 1); load(); } };
      $(el, '#alToday').onclick = () => { day = today(); load(); };
      $(el, '#alRef').onclick = load;
      el.querySelectorAll('.al-chip').forEach((b) => { b.onclick = () => { number = +b.dataset.n; load(); }; });
      el.querySelectorAll('.al-row').forEach((tr) => { tr.onclick = () => { const r = D.rows[+tr.dataset.i]; if (r && ctx && ctx.openChat) ctx.openChat(r.phone); }; });
    }
    load(); return load;
  });
})();
