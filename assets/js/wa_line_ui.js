/* wa_line_ui.js — 🧵 Lineup screen (Campaigns → Lineup). Talks to wa.line_get / wa.line_start / wa.line_save / wa.line_run / wa.line_report / wa.line_customer (empmanagment/wa_line.php). Needs WaPanels.util + api.js. */
(function () {
  'use strict';
  if (window.WaLine) return;
  const { esc, $, fmt, toast, modal, errBox, loading } = WaPanels.util;
  const TOP = { pending: '📍 Address / payment', price: '💰 Price', trust: '🛡 Trust', size: '📏 Size / ml', reorder: '🔁 Bought before', general: '💬 No clear topic' };
  const pill = (t, c) => `<span class="wp-pill ${c || ''}">${t}</span>`;
  function open() {
    const m = modal('🧵 Lineup — 7-day follow-up by topic'); m.el.classList.add('cu-wide'); m.body.innerHTML = loading; load(m);
  }
  function load(m) {
    Promise.all([api.get('wa.line_get'), api.get('wa.line_report', { days: 30 })]).then(([d, r]) => draw(m, d, r)).catch((e) => { m.body.innerHTML = errBox(e.message); });
  }
  function draw(m, D, R) {
    const C = D.cfg, started = !!C.started, tc = D.tpl_counts || {}, tot = Object.keys(D.tpl || {}).length, appr = tc.approved || 0, ready = tot > 0 && appr === tot;
    const topics = Object.keys(D.topics);
    const board = `<table style="width:100%;border-collapse:collapse;font-size:12.5px"><tr style="color:#64748b"><th align="left">Topic</th><th>Customers</th>${[1, 2, 3, 4, 5, 6, 7].map((s) => `<th>Step ${s}</th>`).join('')}<th>Orders</th><th>Conv.</th></tr>${topics.map((t) => { const bt = D.by_topic[t] || {}, cust = Object.values(bt).reduce((a, b) => a + b, 0), rp = (R.topics || {})[t] || {}; return `<tr style="border-top:1px solid #eef0f3"><td>${esc(TOP[t] || t)}</td><td align="center"><b>${fmt(cust)}</b></td>${[1, 2, 3, 4, 5, 6, 7].map((s) => `<td align="center">${fmt(((D.board || {})[t] || {})[s] || 0)}</td>`).join('')}<td align="center">${fmt(rp.buyers || 0)}</td><td align="center">${rp.conv == null ? '<span style="color:#94a3b8">—</span>' : '<b>' + rp.conv + '%</b>'}</td></tr>`; }).join('')}</table><div class="wp-hint">Step columns = customers still waiting to get that step. Conv. = customers who ordered after a lineup message ÷ customers (shown from ${R.min} customers).</div>`;
    const hours = Object.entries((D.tomorrow || {}).by_hour || {}).map(([h, n]) => `<span class="wp-pill" title="${h}:00 IST">${h}:00 · ${n}</span>`).join(' ');
    const bad = Object.entries(D.tpl || {}).filter(([, s]) => s !== 'approved');
    const best = (R.best_steps || []).map((x) => `${esc(TOP[x.topic] || x.topic)} step ${x.step}: <b>${x.per100}</b> orders / 100 delivered (${fmt(x.orders)} orders)`).join('<br>') || '<span style="color:#94a3b8">not enough delivered messages yet</span>';
    const weak = (R.weak_steps || []).map((x) => `${esc(TOP[x.topic] || x.topic)} step ${x.step} (${fmt(x.delivered)} delivered, 0 orders)`).join('<br>');
    m.body.innerHTML = `
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
        ${pill(started ? '⏰ Always on' + (ready ? '' : ' — waiting for Meta') : '⏳ Starting…', started ? 'ok' : '')}
        ${pill('old 7-Day Drip: ' + esc(D.old_drip), D.old_drip === 'off' ? 'ok' : '')}
        ${pill('messages approved ' + appr + '/' + tot, ready ? 'ok' : '')}
        <span style="flex:1"></span>
        ${started ? '<button type="button" class="wp-btn" id="lnRun" title="Send every message that is due now, in batches of 60 (the engine does the same by itself between 10:00 and 20:00 IST)">▶ Send what is due now</button>' : '<button type="button" class="wp-btn p" id="lnStart" title="The engine does this by itself within a minute of the upload">🚀 Start now</button>'}
      </div>
      <div id="lnMsg"></div>
      ${started ? '' : '<div class="wp-note">The Lineup starts by itself within a minute. It does this, in order: <b>switches the old 7-Day Drip off</b> · makes the ' + tot + ' lineup messages and <b>sends them to Meta</b> · sets the button answers · reads every customer who wrote since <b>yesterday 10:00 IST</b> and writes his 7-step lineup. <b>Nothing is sent to a customer</b> until Meta has approved all the messages.</div>'}
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:8px 0">
        <div class="wp-note"><b>📅 Tomorrow</b><br><span style="font-size:22px;font-weight:800">${fmt((D.tomorrow || {}).total || 0)}</span> messages planned<div style="margin-top:4px;font-size:12px">${Object.entries((D.tomorrow || {}).by_topic || {}).map(([t, n]) => esc(TOP[t] || t) + ' ' + n).join(' · ') || '—'}</div><div style="margin-top:6px">${hours || ''}</div></div>
        <div class="wp-note"><b>📤 Today</b><br><span style="font-size:22px;font-weight:800">${fmt((D.today || {}).sent || 0)}</span> sent of ${fmt((D.today || {}).total || 0)} planned<div style="margin-top:4px;font-size:12px">Left the lineup: ${Object.entries(D.left || {}).map(([k, n]) => esc(k) + ' ' + n).join(' · ') || '—'}</div></div>
      </div>
      ${bad.length ? `<div class="wp-note" style="border-color:#f59e0b"><b>⏳ Messages not approved yet (${bad.length})</b><div style="font-size:12px">${bad.map(([k, s]) => esc(k) + ' — ' + esc(s)).join(' · ')}</div><div class="wp-hint">Sending waits until all ${tot} are approved. Meta usually answers in minutes to a few hours; a rejected one must be edited in Pre-made messages.</div></div>` : ''}
      <h3 style="margin:12px 0 4px">Lineup board</h3>${board}
      <h3 style="margin:12px 0 4px">🏆 What brings orders <small style="font-weight:400;color:#64748b">(last ${R.days} days)</small></h3>
      <div class="wp-note"><b>Best steps</b><br>${best}${weak ? '<br><br><b style="color:#b45309">Not working (change the words)</b><br>' + weak : ''}<div class="wp-hint">${esc(R.note)}</div></div>
      <details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700">Step-by-step numbers</summary><table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px"><tr style="color:#64748b"><th align="left">Topic · step</th><th>Sent</th><th>Delivered</th><th>Button taps</th><th>Orders</th><th>Revenue</th><th>Orders /100</th></tr>${(R.steps || []).sort((a, b) => a.topic === b.topic ? a.step - b.step : a.topic < b.topic ? -1 : 1).map((x) => `<tr style="border-top:1px solid #eef0f3"><td>${esc(TOP[x.topic] || x.topic)} · ${x.step}</td><td align="center">${fmt(x.sent)}</td><td align="center">${fmt(x.delivered)}</td><td align="center">${fmt(x.taps)}${x.tap_pct != null ? ' (' + x.tap_pct + '%)' : ''}</td><td align="center">${fmt(x.orders)}</td><td align="center">₹${fmt(x.revenue)}</td><td align="center">${x.per100 == null ? '—' : x.per100}</td></tr>`).join('')}</table></details>
      <details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700">📖 Guidebook ${D.guide && D.guide.live ? '(live)' : '(none approved yet)'}</summary><div class="wp-hint" style="white-space:pre-wrap;max-height:220px;overflow:auto">${esc((D.guide || {}).excerpt || 'No approved guidebook. Read the lineup messages against your own rules before you start.')}</div></details>
      <div style="margin-top:10px"><b>🔎 One customer's lineup</b> <input id="lnPh" class="wp-in" placeholder="mobile number" style="width:170px;display:inline-block"> <button type="button" class="wp-btn s" id="lnFind">Show</button><div id="lnCu" style="margin-top:6px"></div></div>
      <div class="wp-hint" style="margin-top:8px">Every message is Utility, with one button and no offer words. The reviews / video / offer go FREE as the answer to the button (edit them in ⚡ Button replies, cards “Lineup · …”). One message per customer in 24 hours; order, opt-out, complaint, a reply, a person chatting or a campaign today = stop / wait.</div>`;
    const msg = (h) => { $(m.body, '#lnMsg').innerHTML = h; };
    const st = $(m.body, '#lnStart'); if (st) st.onclick = () => { st.disabled = true; msg(loading);
      api.post('wa.line_start', {}).then((r) => { toast('Lineup started: ' + r.enrolled + ' customers planned'); load(m); }).catch((e) => { st.disabled = false; msg(errBox(e.message)); }); };
    const rn = $(m.body, '#lnRun'); if (rn) rn.onclick = () => { if (!confirm('Send every message that is due now? Customers with an order, a reply or a chat in progress are left out.')) return; rn.disabled = true; let n = 0;
      const go = () => api.post('wa.line_run', {}).then((r) => { n += r.sent || 0; msg(`<div class="wp-note">Sent ${fmt(n)} · left out ${fmt(r.skipped || 0)} · waiting ${fmt(r.waiting || 0)}${r.why ? ' · ' + esc(r.why) : ''}</div>`); if ((r.sent || 0) > 0 && (r.waiting || 0) > 0) setTimeout(go, 1500); else { rn.disabled = false; load(m); } }).catch((e) => { rn.disabled = false; msg(errBox(e.message)); }); go(); };
    $(m.body, '#lnFind').onclick = () => { const ph = $(m.body, '#lnPh').value.replace(/\D/g, ''); if (!ph) return; api.get('wa.line_customer', { phone: ph }).then((r) => { $(m.body, '#lnCu').innerHTML = !r.in ? '<div class="wp-hint">This customer is not in the Lineup.</div>' : `<div class="wp-note"><b>${esc(TOP[r.customer.topic] || r.customer.topic)}</b> · ${esc(r.customer.status)}${r.customer.left_why ? ' (' + esc(r.customer.left_why) + ')' : ''} · came in ${esc(r.customer.anchor_at)}${r.plan.map((p) => `<div style="font-size:12.5px">Step ${p.step} · ${esc(p.due_at)} · ${p.status === 'sent' ? '✅ sent ' + esc(p.sent_at || '') : p.status === 'planned' ? '⏳ planned' : esc(p.status) + (p.why ? ' (' + esc(p.why) + ')' : '')}</div>`).join('')}${r.log.map((l) => `<div style="font-size:12px;color:#64748b">${esc(l.at)} · ${esc(l.what)}</div>`).join('')}</div>`; }).catch((e) => { $(m.body, '#lnCu').innerHTML = errBox(e.message); }); };
  }
  window.WaLine = { open };
})();
