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
  WaPanels.add('speed', (el) => mount(el));
})();
