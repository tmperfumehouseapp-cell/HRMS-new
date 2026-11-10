/* wa_plan_ui.js — 🎯 Daily budget planner (Campaigns → Daily budget). Reads wa.plan_get (empmanagment/wa_plan.php); changes nothing. */
(function () {
  'use strict';
  if (window.WaPlan) return;
  const { esc, $, fmt, modal, errBox, loading } = WaPanels.util;
  const rs = (v) => '₹' + Math.round(v).toLocaleString('en-IN');
  function open() {
    const m = modal('🎯 Daily budget planner'); m.el.classList.add('cu-wide'); let budget = 50000;
    const load = async () => {
      m.body.innerHTML = loading; let d;
      try { d = await api.get('wa.plan_get', { budget }); } catch (e) { m.body.innerHTML = errBox(e.message); return; }
      const ok = d.fits;
      m.body.innerHTML = `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px"><label style="font-weight:800">I want to spend today ₹ <input id="wpB" type="number" min="1000" step="1000" value="${budget}" class="wp-in" style="width:130px;display:inline-block"></label><button type="button" class="wp-btn o" id="wpGo">Plan it</button><span style="color:#64748b;font-size:12.5px">Spent today (estimate): <b>${rs(d.spent_today)}</b> · left to spend: <b>${rs(d.left)}</b></span></div>
        <div class="wp-note" style="background:${ok ? '#ecfdf3' : '#fff7ed'}"><b>${ok ? '✅ This budget can be spent today.' : '⚠️ This budget is hard to spend today.'}</b>
          <div style="margin-top:6px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px">
            <div><small>Delivered messages needed</small><br><b style="font-size:18px">${fmt(d.need_delivered)}</b></div>
            <div><small>Messages to send</small><br><b style="font-size:18px">${fmt(d.need_sent)}</b></div>
            <div><small>Expected replies</small><br><b style="font-size:18px">≈ ${fmt(d.expected_replies)}</b></div>
            <div><small>Hours it takes</small><br><b style="font-size:18px">${d.hours_needed}</b> <small>(${d.hours_left_today} left today)</small></div></div>
          <div style="font-size:12px;color:#64748b;margin-top:6px">Worked out from your last 3 days of campaigns (${fmt(d.based_on.sent)} sent, ${fmt(d.based_on.delivered)} delivered): ${Math.round(d.delivery_rate * 100)}% delivered · ${(d.reply_rate * 100).toFixed(1)}% of delivered reply · ₹${d.price} per delivered marketing message · Meta's room today ${fmt(d.room_today)} · speed ${fmt(d.speed_per_hour)} an hour.</div></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
          <div class="wp-note"><b>Hour-by-hour send plan</b>${(d.plan || []).length ? d.plan.map((x) => `<div style="font-size:12.5px;display:flex;justify-content:space-between"><span>${x.hour}</span><span>${fmt(x.sends)} sends</span><span>≈ ${rs(x.rs)}</span></div>`).join('') : '<div style="font-size:12.5px">Nothing left to plan for today.</div>'}
            <div style="font-size:11.5px;color:#64748b;margin-top:6px">Start the main campaign at the first hour and keep other campaigns paused. The sender follows each number's own hourly speed.</div></div>
          <div class="wp-note"><b>Who to send to (customers who wrote…)</b><div style="font-size:12.5px">last 7 days: <b>${fmt(d.audience.d7)}</b> — best delivery and replies<br>last 30 days: <b>${fmt(d.audience.d30)}</b><br>last 90 days: <b>${fmt(d.audience.d90)}</b></div>
            <div style="font-size:12.5px;margin-top:6px"><b>Numbers sending:</b> ${(d.numbers || []).map((n) => esc(n.label) + ' (' + fmt(n.speed) + '/h)').join(' · ') || '—'}</div>
            <div style="font-size:12.5px;margin-top:4px">Campaigns running now: <b>${d.running}</b></div></div></div>
        <div class="wp-note" style="margin-top:10px"><b>Which message to send, and to how many</b>
          ${(d.reco.marketing || []).length ? `<div style="font-size:13px;margin-top:6px"><b>📣 Marketing — best of the last 7 days (replies per delivered):</b>${d.reco.marketing.map((x, i) => `<div style="font-size:12.5px;padding:3px 0">${i === 0 ? '🥇' : i === 1 ? '🥈' : '🥉'} <b>${esc(x.name)}</b> — ${x.reply_pct}% reply · ${x.delivery_pct}% delivered · ${fmt(x.delivered)} delivered in ${x.camps} campaign${x.camps === 1 ? '' : 's'}</div>`).join('')}</div>` : '<div style="font-size:12.5px;margin-top:6px">No marketing message has enough results yet (needs ~150 delivered).</div>'}
          ${(d.reco.utility || []).length ? `<div style="font-size:13px;margin-top:6px"><b>💸 Utility — cheap, for people who asked or ordered lately:</b>${d.reco.utility.map((x) => `<div style="font-size:12.5px;padding:3px 0"><b>${esc(x.name)}</b> — ${x.reply_pct}% reply · ${x.delivery_pct}% delivered</div>`).join('')}</div>` : '<div style="font-size:12.5px;margin-top:6px">No utility message has been used in the last 7 days. Use <b>Utility follow-up</b> for customers who asked or ordered in the last 30 days — it costs far less.</div>'}
          <div style="font-size:13px;margin-top:8px"><b>👥 How many:</b> first <b>${fmt(d.reco.send_recent)}</b> customers who wrote in the last 30 days (best delivery) with the 🥇 message${d.reco.send_older ? `, then <b>${fmt(d.reco.send_older)}</b> from older contacts (lower delivery)` : ''}.${d.reco.short ? ` <span style="color:#b45309">Still ${fmt(d.reco.short)} short — you do not have that many contacts left, so today's budget cannot be fully spent; spend the rest tomorrow or add new contacts.</span>` : ''}</div>
          <div style="font-size:11.5px;color:#64748b;margin-top:6px">Reply % = customers who wrote back within 2 days ÷ delivered. A message with only a few campaigns is a weaker guide.</div></div>
        <div class="wp-note" style="margin-top:10px"><b>What to do</b>${d.advice.map((a) => `<div style="font-size:13px;margin-top:5px">• ${esc(a)}</div>`).join('')}</div>`;
      $(m.body, '#wpGo').onclick = () => { budget = Math.max(1000, +$(m.body, '#wpB').value || 50000); load(); };
    };
    load();
  }
  window.WaPlan = { open };
})();
