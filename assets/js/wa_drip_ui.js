/* wa_drip_ui.js — 🔁 7-Day Drip setup screen (Campaigns → 7-Day Drip). Talks to wa.drip_get / wa.drip_save / wa.drip_test (empmanagment/wa_drip.php). Needs WaPanels.util + api.js. */
(function () {
  'use strict';
  if (window.WaDrip) return;
  const { esc, $, fmt, toast, modal, errBox, loading } = WaPanels.util;
  function open() {
    const m = modal('🔁 7-Day Drip'); m.el.classList.add('cu-wide'); m.body.innerHTML = loading;
    Promise.all([api.get('wa.drip_get'), api.get('wa.tpl_list')]).then(([d, t]) => draw(m, d, ((t || {}).templates || []).filter((x) => (x.status === 'approved' || x.status === 'partial') && !x.auto && !x.media_header && (String(x.body || '').match(/\{\{\s*\d+\s*\}\}/g) || []).length <= 1)))
      .catch((e) => { m.body.innerHTML = errBox(e.message); });
  }
  function draw(m, D, T) {
    const C = JSON.parse(JSON.stringify(D.cfg)); const nums = D.numbers || []; const days = [1, 2, 3, 4, 5, 6, 7]; let extra = Object.keys(C.days).map(Number).filter((x) => x > 7); extra.forEach((x) => days.push(x)); days.sort((a, b) => a - b);
    const tname = (id) => { const t = T.find((x) => x.id === +id); return t ? t.name.replace(/_/g, ' ') : ''; };
    const row = (n) => { const s = C.days[n] || null, st = (D.per_day || {})[n] || {};
      return `<div style="display:grid;grid-template-columns:70px 1fr 100px 150px 90px;gap:8px;align-items:center;padding:8px 0;border-top:1px solid #eef0f3">
        <div><b>Day ${n}</b><br><small style="color:#64748b">${n === 1 ? 'next day' : n + ' days after'}</small></div>
        <div style="position:relative"><button type="button" class="wp-in wd-pk" data-n="${n}" style="text-align:left;width:100%;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${s ? '🔎 ' + esc(tname(s.tpl_id) || 'Message #' + s.tpl_id) : '🔎 Search &amp; pick a message…'}</button></div>
        <input data-d="${n}" data-k="time" type="time" class="wp-in" value="${s ? s.time.padStart(5, '0') : '11:00'}" ${s ? '' : 'disabled'}>
        <select data-d="${n}" data-k="number_id" class="wp-in" ${s ? '' : 'disabled'}><option value="0">Number they wrote to</option>${nums.map((x) => `<option value="${x.id}" ${s && s.number_id === x.id ? 'selected' : ''}>${esc(x.label)}</option>`).join('')}</select>
        <div style="font-size:12px;color:#475569">${s ? '<b>' + fmt(st.sent || 0) + '</b> sent' + (st.skipped ? ' · ' + fmt(st.skipped) + ' left out' : '') + (st.failed ? ' · ' + fmt(st.failed) + ' failed' : '') : ''}</div></div>`; };
    const L = D.left || {}, lf = (k) => fmt(L[k] || 0);
    m.body.innerHTML = `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px"><div style="flex:1;min-width:220px;color:#475569;font-size:13px">A new customer gets a different pre-made message each day, at the time you set. <b>Day 0</b> = the day they came in (the normal chat / bot reply). The next day is <b>Day 1</b>, and so on. A customer who orders leaves the drip.</div>
        <label style="font-weight:800;display:flex;gap:6px;align-items:center">Mode <select id="wdMode" class="wp-in" style="width:auto"><option value="manual" ${C.mode === 'manual' ? 'selected' : ''}>👆 Manual — I press Send</option><option value="auto" ${C.mode === 'auto' ? 'selected' : ''}>⏰ Automatic — day by day</option><option value="off" ${C.mode === 'off' ? 'selected' : ''}>⏸ Off</option></select></label><button type="button" class="wp-btn o" id="wdSave">Save</button></div>
      <div id="wdToday" class="wp-note" style="margin:0 0 10px;padding:0;overflow:hidden"><div style="padding:12px 14px;color:#64748b">Counting today's send…</div></div>
      <div class="wp-note" style="margin:0 0 10px"><b>Who enters:</b>
        <label style="margin-left:8px"><input type="checkbox" id="wdQ" ${C.enter_query ? 'checked' : ''}> New query (customer writes to us)</label>
        <label style="margin-left:10px"><input type="checkbox" id="wdC" ${C.enter_campaign ? 'checked' : ''}> Customer replies to a campaign</label>
        <label style="margin-left:10px"><input type="checkbox" id="wdCm" ${C.enter_community ? 'checked' : ''}> Community customers too</label>
        <div style="margin-top:6px"><b>Leaves automatically when:</b> placed an order · opted out · complaint opened
        <label style="margin-left:10px"><input type="checkbox" id="wdP" ${C.pause_person ? 'checked' : ''}> a salesperson replied in the last 12 hours (skip that day)</label>
        <label style="margin-left:10px"><input type="checkbox" id="wdR" ${C.stop_on_reply ? 'checked' : ''}> customer replied to a drip message (stop)</label></div></div>
      <div style="display:grid;grid-template-columns:70px 1fr 100px 150px 90px;gap:8px;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase"><div>Day</div><div>Pre-made message</div><div>Send at</div><div>From number</div><div>Sent (7 days)</div></div>
      <div id="wdRows">${days.map(row).join('')}</div><div style="padding:8px 0 2px"><button type="button" class="wp-btn" id="wdAdd" title="Add the next day (up to Day 30)">＋ Add Day ${days[days.length - 1] + 1}</button></div><div id="wdMsg"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px">
        <div class="wp-note"><b>Results (last 7 days)</b><br>Entered the drip: ${fmt(D.entered)} · today (Day 0): ${fmt(D.in_today)}<br>Left: ordered ${lf('ordered')} · opted out ${lf('opted out')} · complaint ${lf('complaint')} · replied ${lf('replied')}</div>
        <div class="wp-note"><b>Today's log</b>${(D.log || []).length ? (D.log || []).slice(0, 12).map((x) => `<div style="font-size:12px">${esc(x.at)} · Day ${x.day} · ${esc(x.name)} — ${x.status === 'sent' ? '✓ sent' : x.status === 'skipped' ? 'left out (' + esc(x.why) + ')' : '⚠ ' + esc(x.why)}</div>`).join('') : '<div style="font-size:12px;color:#64748b">Nothing has gone today yet.</div>'}</div></div>
      <div class="wp-hint" style="margin-top:8px">Only approved messages with at most one blank (the name) are listed. A customer is enrolled once in 30 days. Messages go out by themselves while the engine runs (about every minute); at most ${C.cap} per round.</div>`;
    const read = () => { C.mode = $(m.body, '#wdMode').value; C.on = C.mode === 'auto' ? 1 : 0; C.enter_query = $(m.body, '#wdQ').checked ? 1 : 0; C.enter_campaign = $(m.body, '#wdC').checked ? 1 : 0; C.enter_community = $(m.body, '#wdCm').checked ? 1 : 0; C.pause_person = $(m.body, '#wdP').checked ? 1 : 0; C.stop_on_reply = $(m.body, '#wdR').checked ? 1 : 0; };
    const addBtn = () => { const b = $(m.body, '#wdAdd'); if (!b) return; const nx = days[days.length - 1] + 1; if (nx > 30) { b.disabled = true; b.textContent = 'Day 30 is the last'; return; } b.textContent = '＋ Add Day ' + nx; };
    $(m.body, '#wdAdd').onclick = () => { const nx = days[days.length - 1] + 1; if (nx > 30) return; days.push(nx); redraw(); addBtn(); const pk = m.body.querySelector('.wd-pk[data-n="' + nx + '"]'); if (pk) { pk.scrollIntoView({ block: 'center' }); pk.click(); } };
    const redraw = () => { read(); $(m.body, '#wdRows').innerHTML = days.map(row).join(''); bindRows(); addBtn(); };
    function closePick() { const o = m.body.querySelector('.wd-pop'); if (o) o.remove(); }
    function pick(btn) {
      closePick(); const n = +btn.dataset.n; const pop = document.createElement('div'); pop.className = 'wd-pop';
      pop.style.cssText = 'position:absolute;z-index:50;left:0;right:0;top:100%;margin-top:4px;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:8px;min-width:320px';
      pop.innerHTML = '<input type="search" class="wp-in" id="wdQ2" placeholder="🔍 Search by name or words in the message…" autocomplete="off" style="width:100%;box-sizing:border-box;margin-bottom:6px"><div id="wdL" style="max-height:300px;overflow:auto"></div>';
      btn.parentNode.appendChild(pop); const inp = pop.querySelector('#wdQ2'), L = pop.querySelector('#wdL');
      const paint = () => { const q = inp.value.trim().toLowerCase(); const rows = T.filter((t) => !q || (t.name + ' ' + (t.body || '')).toLowerCase().includes(q)).slice(0, 80);
        L.innerHTML = `<div class="wd-op" data-id="0" style="padding:7px 8px;cursor:pointer;color:#64748b;border-radius:8px">— no message this day —</div>` + (rows.map((t) => `<div class="wd-op" data-id="${t.id}" style="padding:7px 8px;cursor:pointer;border-radius:8px;border-top:1px solid #f1f5f9"><b style="font-size:13px">${esc(t.name.replace(/_/g, ' '))}</b>${t.category ? ' <small style="color:#64748b">· ' + esc(String(t.category).toLowerCase()) + '</small>' : ''}<div style="font-size:11.5px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(String(t.body || '').replace(/\s+/g, ' ').slice(0, 110))}</div></div>`).join('') || '<div style="padding:8px;color:#64748b;font-size:12.5px">No approved message matches.</div>');
        L.querySelectorAll('.wd-op').forEach((o) => { o.onmouseenter = () => { o.style.background = '#eef6ff'; }; o.onmouseleave = () => { o.style.background = ''; }; o.onclick = () => { const id = +o.dataset.id; if (id) C.days[n] = Object.assign({ time: '11:00', number_id: 0 }, C.days[n] || {}, { tpl_id: id }); else delete C.days[n]; redraw(); }; }); };
      inp.oninput = paint; paint(); inp.focus();
    }
    function bindRows() {
      m.body.querySelectorAll('.wd-pk').forEach((b) => { b.onclick = (ev) => { ev.stopPropagation(); pick(b); }; });
      m.body.querySelectorAll('[data-d]').forEach((el) => { el.onchange = () => { const n = +el.dataset.d, k = el.dataset.k; if (!C.days[n]) return; if (k === 'time') C.days[n].time = el.value || '11:00'; else if (k === 'number_id') C.days[n].number_id = +el.value; }; });
    }
    m.body.addEventListener('click', (ev) => { if (!ev.target.closest('.wd-pop') && !ev.target.closest('.wd-pk')) closePick(); });
    bindRows();
    // ---- today's send: who gets which day's message now (counted by business day 10 AM → 10 AM); one button sends them all
    const rsn = (v) => '₹' + Math.round(v).toLocaleString('en-IN');
    async function today(refresh) {
      const box = $(m.body, '#wdToday'); if (!box) return; if (refresh !== false) box.innerHTML = '<div style="padding:12px 14px;color:#64748b">Counting today\'s send…</div>';
      let r; try { r = await api.post('wa.drip_preview', {}); } catch (e) { box.innerHTML = '<div style="padding:12px 14px">' + errBox(e.message) + '</div>'; return; }
      const t = r.total || {}, nm = new Date(r.today + 'T12:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' });
      const lf = (x) => Object.keys(x.left || {}).filter((k) => x.left[k]).map((k) => fmt(x.left[k]) + ' ' + k).join(' · ');
      box.innerHTML = `<div style="background:#0f172a;color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><b>${esc(nm)} · who gets what today</b><span style="font-size:12px;color:#cbd5e1">Days are counted 10 AM → 10 AM · Day 0 = the day of their first message</span></div>
        ${(r.rows || []).length ? `<div style="display:grid;grid-template-columns:120px minmax(120px,1.2fr) 2fr 90px 1.2fr 90px;gap:6px;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;padding:8px 14px;background:#f8fafc"><div>Customer came</div><div>Gets</div><div>Preview</div><div>Customers</div><div>Left out</div><div>Will send</div></div>
        ${r.rows.map((x) => `<div style="display:grid;grid-template-columns:120px minmax(120px,1.2fr) 2fr 90px 1.2fr 90px;gap:6px;align-items:center;padding:8px 14px;border-top:1px solid #eef0f3;font-size:12.5px"><div><b>${x.day === 1 ? 'Yesterday' : x.day + ' days ago'}</b><br><small style="color:#64748b">Day ${x.day} · ${esc(x.anchor.slice(8, 10) + '/' + x.anchor.slice(5, 7))}</small></div><div><span style="background:#eef2ff;border-radius:8px;padding:4px 8px;font-weight:700">${esc(x.tpl || '—')}</span></div><div style="color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(x.preview)}</div><div>${fmt(x.customers)}<br><small style="color:#64748b">${Object.keys(x.by_src || {}).map((k) => fmt(x.by_src[k]) + ' ' + esc(k)).join(' · ')}</small></div><div style="color:#b45309">${lf(x) || '—'}</div><div>${x.already ? `<span style="background:#ecfdf3;color:#166534;border-radius:8px;padding:2px 7px;font-weight:700">✓ sent ${fmt(x.already)}${x.sent_at ? ' at ' + esc(x.sent_at) : ''}</span>` : ''}${x.will ? `<b style="color:#15803d">${fmt(x.will)}</b>` : (x.already ? '' : '—')}</div></div>`).join('')}
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:12px 14px;background:#f8fafc;border-top:2px solid #e2e8f0"><div><b>Will send now: ${fmt(t.will)}</b><br><small style="color:#64748b">Already sent today ${fmt(t.already)} · left out ${fmt(t.left)}</small></div><div><b>≈ ${rsn(t.cost)}</b><br><small style="color:#64748b">if all delivered (₹${t.price} each)</small></div><span style="flex:1"></span><button type="button" class="wp-btn" id="wdRef">🔄 Count again</button><button type="button" class="wp-btn o" id="wdSend" style="font-size:15px;padding:10px 18px" ${t.will ? '' : 'disabled'}>🚀 Send today's drip now</button></div>` : '<div style="padding:12px 14px;color:#64748b">Pick a message for at least one day and press Save, then the numbers for today appear here.</div>'}`;
      const rf = $(box, '#wdRef'); if (rf) rf.onclick = () => today();
      const sd = $(box, '#wdSend'); if (sd) sd.onclick = async () => {
        if (!confirm('Send ' + fmt(t.will) + ' drip messages now (about ' + rsn(t.cost) + ' if all are delivered)?\n\nIt keeps sending in the background — you can close this window. Watch the 🔁 7-Day Drip card in Campaigns.')) return; sd.disabled = true;
        try { await api.post('wa.drip_save', { cfg: Object.assign({}, C, { mode: C.mode === 'off' ? 'manual' : C.mode }) }); await api.post('wa.drip_send', {}); toast('7-Day Drip is sending in the background ✓'); m.close(); window.dispatchEvent(new Event('wd-job')); }
        catch (e) { $(m.body, '#wdMsg').innerHTML = errBox(e.message); sd.disabled = false; } };
    }
    today();
    $(m.body, '#wdSave').onclick = async () => { read(); const b = $(m.body, '#wdSave'); b.disabled = true; try { await api.post('wa.drip_save', { cfg: C }); toast('7-Day Drip saved — ' + (C.mode === 'auto' ? 'automatic day by day' : C.mode === 'manual' ? 'manual (you press Send)' : 'off')); } catch (e) { $(m.body, '#wdMsg').innerHTML = errBox(e.message); } finally { b.disabled = false; } };
  }
  window.WaDrip = { open };
})();
