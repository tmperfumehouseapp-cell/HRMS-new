/* ⚡ Speed — the slowest WhatsApp calls, server health and the live switch.  Backend: wq_speed.php (wq.speed / wq.speed_cfg).  Needs api.js and wa_panels.js. */
(function () {
  'use strict';
  if (!window.WaPanels || !WaPanels.add) return;
  const { esc, toast, errBox, loading } = WaPanels.util;
  function mount(el) {
    el.innerHTML = '<div style="display:grid;gap:10px;font-size:14px"><div style="background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:12px">' + loading + '</div></div>';
    const card = 'background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:12px 14px';
    async function load() {
      let d; try { d = await api.get('wq.speed'); } catch (e) { el.innerHTML = errBox(e.message); return; }
      const col = (ms) => ms >= 1500 ? '#b91c1c' : ms >= 600 ? '#b45309' : '#166534';
      el.innerHTML = '<div style="display:grid;gap:10px;font-size:14px">'
        + '<div style="' + card + '"><b style="font-size:15px">⚡ Slowest WhatsApp calls — last 24 hours</b><div style="color:#64748b;font-size:12.5px;margin:3px 0 8px">Every 4th call is timed (' + d.samples + ' timed so far). Biggest total time first. Red = over 1.5 s.</div>'
        + (d.rows.length ? '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px"><tr style="text-align:left;color:#64748b"><th>Call</th><th>Times</th><th>Average</th><th>Worst</th><th>Over 1.5 s</th></tr>'
          + d.rows.map((r) => '<tr style="border-top:1px solid #f0f2f7"><td><code>' + esc(r.action) + '</code></td><td>' + r.n + '</td><td style="font-weight:800;color:' + col(r.avg) + '">' + r.avg + ' ms</td><td style="color:' + col(r.max) + '">' + r.max + ' ms</td><td>' + r.slow + '</td></tr>').join('') + '</table></div>' : '<div style="color:#64748b">Nothing timed yet — use WhatsApp for a few minutes and come back.</div>') + '</div>'
        + '<div style="' + card + '"><b style="font-size:15px">🩺 Server health</b>' + d.health.map((h) => '<div style="display:flex;gap:8px;padding:6px 0;border-top:1px solid #f0f2f7"><span>' + (h.ok ? '✅' : '⚠️') + '</span><div style="flex:1"><b>' + esc(h.k) + '</b>: ' + esc(h.v) + (h.tip && !h.ok ? '<div style="color:#92400e;font-size:12.5px">' + esc(h.tip) + '</div>' : '') + '</div></div>').join('') + '</div>'
        + (d.learn ? '<div style="' + card + '"><b style="font-size:15px">🧠 Nightly learning (guidebook)</b>'
          + '<div style="padding:6px 0;border-top:1px solid #f0f2f7;margin-top:6px">' + (d.learn.stale ? '⚠️' : '✅') + ' <b>Last night:</b> ' + esc(d.learn.last_note || 'it has not run yet') + (d.learn.last_day ? ' <span style="color:#64748b">(' + esc(d.learn.last_day) + ')</span>' : '') + '</div>'
          + '<div style="padding:6px 0;border-top:1px solid #f0f2f7">📖 <b>Live guidebook:</b> ' + (d.learn.live ? 'v' + d.learn.live.version + ' · ' + esc(d.learn.live.at || '') + ' · ' + esc(d.learn.live.by || '') : 'none yet') + '</div>'
          + '<div style="padding:6px 0;border-top:1px solid #f0f2f7">💬 <b>WhatsApp chats taken:</b> ' + d.learn.won + ' won · ' + d.learn.lost + ' lost' + (d.learn.import ? '<div style="color:#64748b;font-size:12.5px">' + esc(d.learn.import) + '</div>' : '') + '</div>'
          + '<div style="padding:6px 0;border-top:1px solid #f0f2f7">' + (d.learn.cron_ok ? '✅' : '⚠️') + ' <b>Engine (cron):</b> ' + (d.learn.cron_at ? 'last ran ' + esc(d.learn.cron_at) : 'has not run yet') + (d.learn.stuck_min ? '<div style="color:#b91c1c;font-size:12.5px;font-weight:700">⛔ A run started ' + d.learn.stuck_min + ' min ago and has not finished — it may be stuck (it is stopped automatically after 15 min).</div>' : '') + (d.learn.cron_ok ? '' : '<div style="color:#92400e;font-size:12.5px">The nightly learning only happens while the cron runs every minute.</div>') + '</div></div>' : '')
        + '<div style="' + card + '"><b style="font-size:15px">📡 Live mode</b><div style="color:#64748b;font-size:12.5px;margin:3px 0 8px">New messages show in about a second instead of up to 5. Each open chat list then keeps one server worker busy for a few seconds at a time, so switch it on only if the server has plenty of PHP workers.</div>'
        + '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="spLive" ' + (d.live ? 'checked' : '') + '> Live mode ' + (d.live ? '<b style="color:#166534">ON</b>' : 'off') + '</label></div></div>';
      el.querySelector('#spLive').onchange = async (e) => { try { await api.post('wq.speed_cfg', { live: e.target.checked ? 1 : 0 }); toast('Saved'); } catch (er) { toast(er.message, true); } load(); };
    }
    load(); return () => { };
  }
  function mountHandoffs(el) {
    el.innerHTML = '<div style="display:grid;gap:10px;font-size:14px"><div style="background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:12px">' + loading + '</div></div>';
    const card = 'background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:12px 14px', mm = (s) => s == null ? '—' : (s < 90 ? s + ' s' : Math.round(s / 60) + ' min');
    async function load() {
      let d; try { d = await api.get('wq.needs_board'); } catch (e) { el.innerHTML = errBox(e.message); return; }
      const sc = (k, t) => { const x = d.sum[k]; return '<div style="flex:1;min-width:150px;border:1px solid #eef0f5;border-radius:12px;padding:10px"><b>' + t + '</b><div style="color:#64748b;font-size:12.5px;margin-top:4px">' + x.n + ' came up · ' + x.taken + ' taken · ' + x.answered + ' answered by a person · <b>' + x.ordered + ' ordered</b>' + (k === 'orange' ? ' · ' + x.dismissed + ' marked not a lead' : '') + '</div><div style="font-size:12.5px;margin-top:4px">Average to take: <b>' + mm(x.take_s) + '</b> · to answer: <b>' + mm(x.reply_s) + '</b></div></div>'; };
      el.innerHTML = '<div style="display:grid;gap:10px;font-size:14px">'
        + '<div style="' + card + '"><b style="font-size:15px">🔴🟠 Waiting for a person right now</b>' + (d.live.length ? d.live.map((x) => '<div style="display:flex;gap:8px;padding:6px 0;border-top:1px solid #f0f2f7"><span>' + (x.level === 'red' ? '🔴' : '🟠') + '</span><div style="flex:1;min-width:0"><b>' + esc(x.name || x.phone) + '</b> <span style="color:#64748b">' + esc(x.txt || '') + '</span></div><b style="color:' + (x.sec >= 300 ? '#b91c1c' : '#334155') + '">' + mm(x.sec) + '</b></div>').join('') : '<div style="color:#64748b;margin-top:6px">Nobody is waiting. 🎉</div>') + '</div>'
        + '<div style="' + card + '"><b style="font-size:15px">Last 7 days</b><div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">' + sc('red', '🔴 Ready to pay') + sc('orange', '🟠 Interested') + '</div></div>'
        + '<div style="' + card + '"><b style="font-size:15px">Latest chats</b><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:6px"><tr style="text-align:left;color:#64748b"><th></th><th>Customer</th><th>Taken by</th><th>To take</th><th>To answer</th><th>Order</th></tr>'
        + d.rows.map((r) => '<tr style="border-top:1px solid #f0f2f7"><td>' + (r.level === 'red' ? '🔴' : '🟠') + '</td><td>' + esc(r.name || r.phone) + (r.dismissed ? ' <span style="color:#92400e">(not a lead)</span>' : '') + '</td><td>' + esc(r.taken_by || '—') + '</td><td>' + mm(r.take_s) + '</td><td style="font-weight:700;color:' + (r.reply_s == null ? '#b91c1c' : '#166534') + '">' + (r.reply_s == null ? 'nobody' : mm(r.reply_s)) + '</td><td>' + (r.ordered ? '✅' : '') + '</td></tr>').join('') + '</table></div></div></div>';
    }
    load(); const iv = setInterval(() => { if (el.isConnected) load(); else clearInterval(iv); }, 30000); return () => clearInterval(iv);
  }
  WaPanels.add('speed', (el) => mount(el));
  WaPanels.add('handoffs', (el) => mountHandoffs(el));
})();
