/* 🧠 "The bot learned" strip (top of the WhatsApp sales panel): the answers people replaced with 👎 — the owner applies them to the LIVE guidebook with one tap;
   the nightly guidebook keeps them (owner_corrections). Admin / managers only; nothing shows when there is nothing to apply. */
(function () {
  'use strict';
  var host = document.getElementById('blStrip'); if (!host || typeof api === 'undefined') return;
  var open = false, data = null, busy = false;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function css() {
    if (document.getElementById('blCss')) return; var st = document.createElement('style'); st.id = 'blCss';
    st.textContent = '#blStrip{margin:0}.bl{margin:8px 12px;border:1px solid #cfe3d6;background:#f3faf6;border-radius:12px;font-size:13px;color:#1f2a37}.bl-h{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;padding:9px 12px;cursor:pointer}.bl-h b{flex:1 1 0;min-width:140px;line-height:1.3}.bl-s{flex:1 1 100%;order:5;font-size:12px;line-height:1.35;padding-left:26px}.bl-h .bl-ar{margin-left:auto}.bl-b{padding:0 12px 10px}.bl-i{border-top:1px solid #dfece4;padding:9px 0}.bl-i q{display:block;color:#445;margin:2px 0}.bl-bad{color:#a33}.bl-good{color:#116b3a;font-weight:600}.bl-r{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}.bl button{border:0;border-radius:9px;padding:7px 11px;font:inherit;font-weight:600;cursor:pointer}.bl .ap{background:#128c4a;color:#fff}.bl .sk{background:#e6e9ee;color:#445}.bl textarea{width:100%;box-sizing:border-box;font:inherit;padding:6px;border:1px solid #cfd6dd;border-radius:8px;margin-top:4px}.bl table{font-size:12px}.bl td,.bl th{padding:3px 6px;white-space:nowrap}@media (max-width:520px){.bl{margin:6px 8px;font-size:12.5px}.bl-h{padding:8px 10px}}';
    document.head.appendChild(st);
  }
  function draw() {
    css(); if (!data || !data.can && !(data.items || []).length) { host.innerHTML = ''; return; }
    var items = data.items || []; if (!items.length && !data.applied_today) { host.innerHTML = ''; return; }
    var h = '<div class="bl"><div class="bl-h" id="blH"><span>🧠</span><b>The bot learned ' + items.length + ' new thing' + (items.length === 1 ? '' : 's') + (data.applied_today ? ' · ' + data.applied_today + ' applied today' : '') + '</b><span class="bl-ar">' + (open ? '▲' : '▼') + '</span></div>';
    if (open) {
      h += '<div class="bl-b">' + (!items.length ? '<div class="bl-i">Nothing waiting. Everything is in the guidebook.</div>' : '');
      if (items.length > 1 && data.can) h += '<div class="bl-r"><button class="ap" id="blAll">✅ Apply all ' + items.length + ' to the guidebook</button></div>';
      items.forEach(function (x) {
        h += '<div class="bl-i" data-id="' + x.id + '"><div style="color:#667">' + esc(x.by) + ' · ' + esc(String(x.at).slice(5, 16)) + (x.reason ? ' · ' + esc(x.reason) : '') + '</div>'
          + '<div>Customer: <q>' + esc(x.customer) + '</q></div><div class="bl-bad">Bot said: <q>' + esc(x.bot) + '</q></div>'
          + (x.pending_tpl ? '<div style="color:#a60">A pre-made message is being chosen…</div>' : '<div class="bl-good">Better: <textarea rows="3" data-t="' + x.id + '">' + esc(x.better) + '</textarea></div>')
          + (data.can && !x.pending_tpl ? '<div class="bl-r"><button class="ap" data-ap="' + x.id + '">✅ Apply to guidebook</button><button class="sk" data-sk="' + x.id + '">Ignore</button></div>' : '') + '</div>';
      });
      if ((data.applied || []).length) { h += '<div class="bl-i"><b>✅ Already in the guidebook (latest ' + data.applied.length + ')</b>'; data.applied.forEach(function (x) { h += '<div style="margin-top:6px;color:#445"><span style="color:#667">' + esc(x.by) + ' · ' + esc(String(x.at).slice(5, 16)) + '</span><br>Customer: <q>' + esc(x.customer) + '</q><span class="bl-bad">Bot said: ' + esc(x.bot) + '</span><br><span class="bl-good">Now it says: ' + esc(x.better) + '</span></div>'; }); h += '</div>'; }
      h += '</div>';
    }
    host.innerHTML = h + '</div>';
    var hd = document.getElementById('blH'); if (hd) hd.onclick = function () { open = !open; draw(); };
    var all = document.getElementById('blAll'); if (all) all.onclick = function () { act({ all: 1 }); };
    host.querySelectorAll('[data-ap]').forEach(function (b) { b.onclick = function () { var id = +b.dataset.ap, t = host.querySelector('[data-t="' + id + '"]'); act({ id: id, text: t ? t.value : undefined }); }; });
    host.querySelectorAll('[data-sk]').forEach(function (b) { b.onclick = function () { act({ id: +b.dataset.sk, ignore: 1 }); }; });
  }
  function say(msg, bad) { var t = document.createElement('div'); t.className = 'wp-toast' + (bad ? ' bad' : ''); t.textContent = msg; document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3500); }
  function act(body) { if (busy) return; busy = true; api.post('wa.g_botlearn_apply', body).then(function (r) { say(body.ignore ? 'Ignored' : '✅ Applied to the live guidebook (' + (r.applied || 0) + '). The bot uses it now.'); return load(); }).catch(function (e) { say(e.message, true); }).then(function () { busy = false; }); }
  var pend = null, popen = false;
  function drawPend() {
    var host2 = document.getElementById('blPend'); if (!host2) { host2 = document.createElement('div'); host2.id = 'blPend'; host.parentNode.insertBefore(host2, host); }
    if (!pend || !pend.can || !pend.total) { host2.innerHTML = ''; return; } css();
    var by = {}; pend.items.forEach(function (x) { var w = x.missed_by || x.holder || 'nobody'; by[w] = (by[w] || 0) + 1; }); var sum = Object.keys(by).sort(function (a, b) { return by[b] - by[a]; }).slice(0, 6).map(function (k) { return esc(k) + ' ' + by[k]; }).join(' · ');
    var h = '<div class="bl" style="border-color:#f0c9a8;background:#fff6ee"><div class="bl-h" id="bpH"><span>🙋</span><b>Hot or bot-handed chats nobody answered: ' + pend.total + ' chat' + (pend.total === 1 ? '' : 's') + '</b><span class="bl-s" style="color:#a60">' + sum + '</span><span class="bl-ar">' + (popen ? '▲' : '▼') + '</span></div>';
    if (popen) {
      h += '<div class="bl-b">';
      pend.items.forEach(function (x) {
        h += '<div class="bl-i"><div><b>' + esc(x.name || ('+91 ' + x.phone)) + '</b> · waiting <span style="color:' + (x.wait_min >= 10 ? '#b00' : '#a60') + '">' + x.wait_min + ' min</span> · handed over ' + x.handed_min + ' min ago' + (x.hot ? ' · 🔥 ' + esc(x.hot) : '') + '</div>'
          + '<div style="color:#667">Holder: ' + (x.holder ? esc(x.holder) : 'nobody (in OPEN)') + (x.missed_by ? ' · did not answer: <b>' + esc(x.missed_by) + '</b>' : '') + '</div><q>' + esc(x.last) + '</q>'
          + '<div class="bl-r"><a class="sk" style="text-decoration:none;border-radius:9px;padding:7px 11px;font-weight:600" target="_blank" href="my_chats.html?phone=' + encodeURIComponent(x.phone) + '">Open chat</a><button class="ap" data-nf="' + esc(x.phone) + '">Give to the next free person</button></div></div>';
      });
      h += '</div>';
    }
    host2.innerHTML = h + '</div>';
    var hd = document.getElementById('bpH'); if (hd) hd.onclick = function () { popen = !popen; drawPend(); };
    host2.querySelectorAll('[data-nf]').forEach(function (b) { b.onclick = function () { b.disabled = true; api.post('wa.g_reassign', { phone: b.dataset.nf, emp_id: 0 }).then(function (r) { say('Given to ' + (r.to || 'the next free person')); loadPend(); }).catch(function (e) { say(e.message, true); b.disabled = false; }); }; });
  }
  var mkt = null, mopen = false;
  function mm(sec) { return sec == null ? '–' : (sec < 90 ? Math.round(sec) + ' s' : Math.round(sec / 60) + ' min'); }
  function drawMkt() {
    var h3 = document.getElementById('blMkt'); if (!h3) { h3 = document.createElement('div'); h3.id = 'blMkt'; host.parentNode.insertBefore(h3, host); }
    if (!mkt || !mkt.can) { h3.innerHTML = ''; return; } css();
    var w = mkt.week || [], t = mkt.today || [], tt = {}; t.forEach(function (x) { tt[x.emp] = x; });
    var rate = mkt.offered_today ? Math.round(100 * mkt.taken_in_4min / mkt.offered_today) : null;
    var h = '<div class="bl" style="border-color:#c7d7f0;background:#f2f6fd"><div class="bl-h" id="bmH"><span>🏪</span><b>Open market — who takes the hot chats and closes orders</b><span class="bl-s" style="color:#357">today: offered ' + (mkt.offered_today || 0) + ' · taken ' + (mkt.taken_today || 0) + (rate === null ? '' : ' · within 4 min ' + rate + '%') + '</span><span class="bl-ar">' + (mopen ? '▲' : '▼') + '</span></div>';
    if (mopen) {
      h += '<div class="bl-b"><div style="overflow-x:auto"><table style="border-collapse:collapse;width:100%;font-size:12.5px"><tr style="text-align:left;color:#567"><th>#</th><th>Person</th><th>Score</th><th>Orders</th><th>Avg order</th><th>Taken</th><th>🔴 Left</th><th>🟥 Cherry</th><th>Take after</th><th>1st reply</th><th>Today</th></tr>';
      w.forEach(function (x, i) { var d = tt[x.emp] || {}; h += '<tr style="border-top:1px solid #dbe4f2"><td>' + (i + 1) + '</td><td>' + esc(x.name) + (x.present ? ' 🟢' : '') + '</td><td><b>' + x.score + '</b></td><td>' + x.orders + '</td><td>' + (x.avg_ticket ? '₹' + x.avg_ticket : '–') + '</td><td>' + x.taken + '</td><td style="color:' + ((x.dropped + x.released) ? '#b00' : '#567') + '">' + (x.dropped + x.released) + '</td><td style="color:' + (x.cherry ? '#b00' : '#567') + ';font-weight:' + (x.flag ? 800 : 400) + '">' + (x.cherry || 0) + (x.flag ? ' ⚠' : '') + '</td><td>' + mm(x.wait_med_s) + '</td><td>' + mm(x.reply_med_s) + '</td><td>' + (d.taken || 0) + ' taken · ' + (d.orders || 0) + ' orders' + ((d.dropped || d.released) ? ' · <span style="color:#b00">' + (d.dropped + d.released) + ' left</span>' : '') + '</td></tr>'; });
      if (mkt.team) h += '<tr style="border-top:2px solid #9fb6dc;background:#e6eefb"><td></td><td><b>Team average</b></td><td></td><td>' + mkt.team.orders + '</td><td>' + (mkt.team.avg_ticket ? '₹' + mkt.team.avg_ticket : '–') + '</td><td>' + mkt.team.taken + '</td><td></td><td>' + (mkt.team.cherry || 0) + '</td><td></td><td>' + mm(mkt.team.reply_med_s) + '</td><td>' + (mkt.team.ratio === null ? '–' : mkt.team.ratio + '% of taken became orders') + '</td></tr>';
      var hh = mkt.hours || {}, hrs = []; for (var k = 8; k <= 22; k++) hrs.push(k);
      h += '</table></div><div style="margin-top:10px;font-weight:700;color:#357">Hour by hour today — taken / 🔴 left</div><div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:12px"><tr style="color:#567"><th style="text-align:left;padding:2px 6px">Person</th>' + hrs.map(function (k) { return '<th style="padding:2px 6px">' + k + '</th>'; }).join('') + '<th style="padding:2px 6px">Total</th></tr>';
      w.forEach(function (x) { var r = hh[x.emp]; if (!r) return; var tk = 0, dr = 0; h += '<tr style="border-top:1px solid #dbe4f2"><td style="padding:2px 6px;white-space:nowrap">' + esc(x.name) + '</td>' + hrs.map(function (k) { var c = r[k]; if (!c) return '<td style="padding:2px 6px;color:#9ab">·</td>'; tk += c[0]; dr += c[1]; return '<td style="padding:2px 6px;text-align:center">' + c[0] + (c[1] ? '/<b style="color:#b00">' + c[1] + '</b>' : '') + '</td>'; }).join('') + '<td style="padding:2px 6px"><b>' + tk + '</b>' + (dr ? '/<b style="color:#b00">' + dr + '</b>' : '') + '</td></tr>'; });
      h += '</table></div><div style="margin-top:8px;color:#567;font-size:12px">Last 7 days. Score 0-100 = orders 40 (4 points each) + average order near ₹2,750 (add-ons) 20 + first reply within 1 minute of taking 20 + effort 20 (taken ÷ (taken + left)). 🔴 Left = took a chat or was holding a hot chat and did not answer in time (it went back to OPEN). 🟥 Cherry = left + gave back to OPEN + took and never wrote to the customer (⚠ = 3+ and 40%+ of what he took: cherry-picking, the admins get a push once a day). The best present people are shown above the OPEN list to everybody.</div></div>';
    }
    h3.innerHTML = h + '</div>';
    var hd = document.getElementById('bmH'); if (hd) hd.onclick = function () { mopen = !mopen; drawMkt(); };
  }
  var hrl = null, hopen = false, hday = 0;
  function drawHourly() {
    var el = document.getElementById('blHr'); if (!el) { el = document.createElement('div'); el.id = 'blHr'; host.parentNode.insertBefore(el, host); }
    if (!hrl || !hrl.can) { el.innerHTML = ''; return; } css();
    var H = hrl.hours || [], tq = 0, tw = 0, ts = 0; H.forEach(function (x) { tq += x.q; tw += x.wrote; ts += x.sent; });
    var h = '<div class="bl" style="border-color:#cfe3d6;background:#f3faf5"><div class="bl-h" id="bhH"><span>📊</span><b>Queries hour by hour — campaign sent → customers wrote → new queries</b><span class="bl-s" style="color:#357">' + esc(hrl.date) + ' · new queries ' + tq + ' · customers wrote ' + tw + ' · campaign msgs ' + ts + '</span><span class="bl-ar">' + (hopen ? '▲' : '▼') + '</span></div>';
    if (hopen) {
      h += '<div class="bl-b"><div style="margin-bottom:6px">' + [0, 1, 2, 3].map(function (d) { return '<button type="button" data-hd="' + d + '" style="margin-right:6px;border:1px solid #9ab;border-radius:8px;padding:3px 10px;background:' + (hday === d ? '#2c7' : '#fff') + ';color:' + (hday === d ? '#fff' : '#234') + '">' + (d === 0 ? 'Today' : d === 1 ? 'Yesterday' : d + ' days ago') + '</button>'; }).join('') + '</div>';
      h += '<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:12.5px;min-width:520px"><tr style="text-align:left;color:#567"><th style="padding:2px 6px">Hour</th><th>Campaign msgs sent</th><th>Customers wrote</th><th>New queries</th><th>Campaign</th><th>Meta ad</th><th>Other</th><th>Back again</th></tr>';
      H.forEach(function (x, i) { if (!x.q && !x.wrote && !x.sent) return; h += '<tr style="border-top:1px solid #dbe9de"><td style="padding:2px 6px">' + i + ':00–' + (i + 1) + ':00</td><td>' + x.sent + '</td><td>' + x.wrote + '</td><td><b>' + x.q + '</b></td><td>' + x.camp + '</td><td>' + x.meta + '</td><td>' + x.other + '</td><td>' + x.again + '</td></tr>'; });
      h += '</table></div><div style="margin-top:8px;color:#567;font-size:12px">A query = a customer who wrote and had no query in the last 24 hours (writing again inside 24 h is the same query). Complaints, order-status / tracking / refund chats are not counted. "Customers wrote" counts every customer who sent a message in that hour.</div></div>';
    }
    el.innerHTML = h + '</div>';
    var hd = document.getElementById('bhH'); if (hd) hd.onclick = function () { hopen = !hopen; drawHourly(); };
    el.querySelectorAll('[data-hd]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); hday = +b.getAttribute('data-hd'); loadHourly(); }; });
  }
  function loadHourly() { return api.get('wa.g_hourly', { day: hday }).then(function (d) { hrl = d; drawHourly(); }).catch(function () { }); }
  function loadMkt() { return api.get('wa.g_mkt').then(function (d) { mkt = d; drawMkt(); }).catch(function () { }); }
  function loadPend() { return api.get('wa.g_botpending').then(function (d) { pend = d; drawPend(); }).catch(function () { }); }
  function load() { return api.get('wa.g_botlearn').then(function (d) { data = d; draw(); }).catch(function () { }); }
  load(); loadPend(); loadMkt(); loadHourly(); setInterval(function () { if (!document.hidden && !busy) { load(); loadPend(); loadMkt(); loadHourly(); } }, 60000);
})();
