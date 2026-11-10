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
        <select data-d="${n}" data-k="tpl_id" class="wp-in"><option value="0">— no message this day —</option>${T.map((t) => `<option value="${t.id}" ${s && s.tpl_id === t.id ? 'selected' : ''}>${esc(t.name.replace(/_/g, ' '))}${t.category ? ' · ' + esc(String(t.category).toLowerCase()) : ''}</option>`).join('')}</select>
        <input data-d="${n}" data-k="time" type="time" class="wp-in" value="${s ? s.time.padStart(5, '0') : '11:00'}" ${s ? '' : 'disabled'}>
        <select data-d="${n}" data-k="number_id" class="wp-in" ${s ? '' : 'disabled'}><option value="0">Number they wrote to</option>${nums.map((x) => `<option value="${x.id}" ${s && s.number_id === x.id ? 'selected' : ''}>${esc(x.label)}</option>`).join('')}</select>
        <div style="font-size:12px;color:#475569">${s ? '<b>' + fmt(st.sent || 0) + '</b> sent' + (st.skipped ? ' · ' + fmt(st.skipped) + ' left out' : '') + (st.failed ? ' · ' + fmt(st.failed) + ' failed' : '') : ''}</div></div>`; };
    const L = D.left || {}, lf = (k) => fmt(L[k] || 0);
    m.body.innerHTML = `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px"><div style="flex:1;min-width:220px;color:#475569;font-size:13px">A new customer gets a different pre-made message each day, at the time you set. <b>Day 0</b> = the day they came in (the normal chat / bot reply). The next day is <b>Day 1</b>, and so on. A customer who orders leaves the drip.</div>
        <label style="font-weight:800;display:flex;gap:6px;align-items:center"><input type="checkbox" id="wdOn" ${C.on ? 'checked' : ''} style="width:18px;height:18px"> Drip is ON</label><button type="button" class="wp-btn o" id="wdSave">Save</button></div>
      <div class="wp-note" style="margin:0 0 10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><span style="flex:1;min-width:220px"><b>Existing customers:</b> add everybody who wrote in the last 7 days. Each one joins on the day they came in, so they get the message of their own day. Then press <b>Send now</b> to send today's messages at once (no waiting for the set time).</span><button type="button" class="wp-btn" id="wdBack">➕ Add last 7 days</button><button type="button" class="wp-btn o" id="wdNow">⚡ Send now</button></div>
      <div class="wp-note" style="margin:0 0 10px"><b>Who enters:</b>
        <label style="margin-left:8px"><input type="checkbox" id="wdQ" ${C.enter_query ? 'checked' : ''}> New query (customer writes to us)</label>
        <label style="margin-left:10px"><input type="checkbox" id="wdC" ${C.enter_campaign ? 'checked' : ''}> Customer replies to a campaign</label>
        <div style="margin-top:6px"><b>Leaves automatically when:</b> placed an order · opted out · complaint opened
        <label style="margin-left:10px"><input type="checkbox" id="wdP" ${C.pause_person ? 'checked' : ''}> a salesperson replied in the last 12 hours (skip that day)</label>
        <label style="margin-left:10px"><input type="checkbox" id="wdR" ${C.stop_on_reply ? 'checked' : ''}> customer replied to a drip message (stop)</label></div></div>
      <div style="display:grid;grid-template-columns:70px 1fr 100px 150px 90px;gap:8px;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase"><div>Day</div><div>Pre-made message</div><div>Send at</div><div>From number</div><div>Sent (7 days)</div></div>
      <div id="wdRows">${days.map(row).join('')}</div><div id="wdMsg"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px">
        <div class="wp-note"><b>Results (last 7 days)</b><br>Entered the drip: ${fmt(D.entered)} · today (Day 0): ${fmt(D.in_today)}<br>Left: ordered ${lf('ordered')} · opted out ${lf('opted out')} · complaint ${lf('complaint')} · replied ${lf('replied')}</div>
        <div class="wp-note"><b>Today's log</b>${(D.log || []).length ? (D.log || []).slice(0, 12).map((x) => `<div style="font-size:12px">${esc(x.at)} · Day ${x.day} · ${esc(x.name)} — ${x.status === 'sent' ? '✓ sent' : x.status === 'skipped' ? 'left out (' + esc(x.why) + ')' : '⚠ ' + esc(x.why)}</div>`).join('') : '<div style="font-size:12px;color:#64748b">Nothing has gone today yet.</div>'}</div></div>
      <div class="wp-hint" style="margin-top:8px">Only approved messages with at most one blank (the name) are listed. A customer is enrolled once in 30 days. Messages go out by themselves while the engine runs (about every minute); at most ${C.cap} per round.</div>`;
    const read = () => { C.on = $(m.body, '#wdOn').checked ? 1 : 0; C.enter_query = $(m.body, '#wdQ').checked ? 1 : 0; C.enter_campaign = $(m.body, '#wdC').checked ? 1 : 0; C.pause_person = $(m.body, '#wdP').checked ? 1 : 0; C.stop_on_reply = $(m.body, '#wdR').checked ? 1 : 0; };
    m.body.querySelectorAll('[data-d]').forEach((el) => { el.onchange = () => { const n = +el.dataset.d, k = el.dataset.k; C.days[n] = C.days[n] || { tpl_id: 0, time: '11:00', number_id: 0 };
      if (k === 'tpl_id') { C.days[n].tpl_id = +el.value; if (!C.days[n].tpl_id) delete C.days[n]; read(); $(m.body, '#wdRows').innerHTML = days.map(row).join(''); bindRows(); } else if (k === 'time') C.days[n].time = el.value || '11:00'; else C.days[n].number_id = +el.value; }; });
    function bindRows() { m.body.querySelectorAll('[data-d]').forEach((el) => { el.onchange = () => { const n = +el.dataset.d, k = el.dataset.k; C.days[n] = C.days[n] || { tpl_id: 0, time: '11:00', number_id: 0 }; if (k === 'tpl_id') { C.days[n].tpl_id = +el.value; if (!C.days[n].tpl_id) delete C.days[n]; read(); $(m.body, '#wdRows').innerHTML = days.map(row).join(''); bindRows(); } else if (k === 'time') C.days[n].time = el.value || '11:00'; else C.days[n].number_id = +el.value; }; }); }
    $(m.body, '#wdBack').onclick = async () => { const b = $(m.body, '#wdBack'); b.disabled = true; try { const r = await api.post('wa.drip_backfill', {}); const bd = r.by_day || {}; $(m.body, '#wdMsg').innerHTML = `<div class="wp-ok">${fmt(r.enrolled)} customers added. In the drip now by day: ${Object.keys(bd).sort((x, y) => x - y).map((k) => 'Day ' + k + ': ' + fmt(bd[k])).join(' · ') || 'nobody'}. Press <b>Send now</b> to send today's messages.</div>`; } catch (e) { $(m.body, '#wdMsg').innerHTML = errBox(e.message); } finally { b.disabled = false; } };
    $(m.body, '#wdNow').onclick = async () => { read(); const b = $(m.body, '#wdNow'); if (!C.on) { $(m.body, '#wdMsg').innerHTML = errBox('Switch "Drip is ON" and press Save first.'); return; } if (!confirm('Send today\'s drip messages to everybody who is due, right now?')) return; b.disabled = true; let sent = 0, skipped = 0, failed = 0;
      try { await api.post('wa.drip_save', { cfg: C }); for (let i = 0; i < 60; i++) { const r = await api.post('wa.drip_run', {}); sent += r.sent || 0; skipped += r.skipped || 0; failed += r.failed || 0; $(m.body, '#wdMsg').innerHTML = `<div class="wp-ok">Sending… ${fmt(sent)} sent · ${fmt(skipped)} left out (ordered / opted out / complaint) · ${fmt(failed)} failed</div>`; if (!r.more) break; } $(m.body, '#wdMsg').innerHTML = `<div class="wp-ok"><b>Done.</b> ${fmt(sent)} sent · ${fmt(skipped)} left out · ${fmt(failed)} failed. If a number had no room, the rest goes by itself in the next rounds.</div>`; }
      catch (e) { $(m.body, '#wdMsg').innerHTML = errBox(e.message); } finally { b.disabled = false; } };
    $(m.body, '#wdSave').onclick = async () => { read(); const b = $(m.body, '#wdSave'); b.disabled = true; try { await api.post('wa.drip_save', { cfg: C }); toast('7-Day Drip saved' + (C.on ? ' — it is ON' : ' — it is OFF')); } catch (e) { $(m.body, '#wdMsg').innerHTML = errBox(e.message); } finally { b.disabled = false; } };
  }
  window.WaDrip = { open };
})();
