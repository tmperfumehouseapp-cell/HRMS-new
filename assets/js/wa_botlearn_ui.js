/* 🧠 "The bot learned" strip (top of the WhatsApp sales panel): the answers people replaced with 👎 — the owner applies them to the LIVE guidebook with one tap;
   the nightly guidebook keeps them (owner_corrections). Admin / managers only; nothing shows when there is nothing to apply. */
(function () {
  'use strict';
  var host = document.getElementById('blStrip'); if (!host || typeof api === 'undefined') return;
  var open = false, data = null, busy = false;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function css() {
    if (document.getElementById('blCss')) return; var st = document.createElement('style'); st.id = 'blCss';
    st.textContent = '#blStrip{margin:0}.bl{margin:8px 12px;border:1px solid #cfe3d6;background:#f3faf6;border-radius:12px;font-size:13px}.bl-h{display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer}.bl-h b{flex:1}.bl-b{padding:0 12px 10px}.bl-i{border-top:1px solid #dfece4;padding:9px 0}.bl-i q{display:block;color:#445;margin:2px 0}.bl-bad{color:#a33}.bl-good{color:#116b3a;font-weight:600}.bl-r{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}.bl button{border:0;border-radius:9px;padding:7px 11px;font:inherit;font-weight:600;cursor:pointer}.bl .ap{background:#128c4a;color:#fff}.bl .sk{background:#e6e9ee;color:#445}.bl textarea{width:100%;box-sizing:border-box;font:inherit;padding:6px;border:1px solid #cfd6dd;border-radius:8px;margin-top:4px}@media (prefers-color-scheme:dark){.bl{background:#16251d;border-color:#294636}.bl-i{border-color:#294636}.bl-i q{color:#c9d4ce}.bl .sk{background:#2b323a;color:#cdd}}';
    document.head.appendChild(st);
  }
  function draw() {
    css(); if (!data || !data.can && !(data.items || []).length) { host.innerHTML = ''; return; }
    var items = data.items || []; if (!items.length && !data.applied_today) { host.innerHTML = ''; return; }
    var h = '<div class="bl"><div class="bl-h" id="blH"><span>🧠</span><b>The bot learned ' + items.length + ' new thing' + (items.length === 1 ? '' : 's') + (data.applied_today ? ' · ' + data.applied_today + ' applied today' : '') + '</b><span>' + (open ? '▲' : '▼') + '</span></div>';
    if (open) {
      h += '<div class="bl-b">' + (!items.length ? '<div class="bl-i">Nothing waiting. Everything is in the guidebook.</div>' : '');
      if (items.length > 1 && data.can) h += '<div class="bl-r"><button class="ap" id="blAll">✅ Apply all ' + items.length + ' to the guidebook</button></div>';
      items.forEach(function (x) {
        h += '<div class="bl-i" data-id="' + x.id + '"><div style="color:#667">' + esc(x.by) + ' · ' + esc(String(x.at).slice(5, 16)) + (x.reason ? ' · ' + esc(x.reason) : '') + '</div>'
          + '<div>Customer: <q>' + esc(x.customer) + '</q></div><div class="bl-bad">Bot said: <q>' + esc(x.bot) + '</q></div>'
          + (x.pending_tpl ? '<div style="color:#a60">A pre-made message is being chosen…</div>' : '<div class="bl-good">Better: <textarea rows="3" data-t="' + x.id + '">' + esc(x.better) + '</textarea></div>')
          + (data.can && !x.pending_tpl ? '<div class="bl-r"><button class="ap" data-ap="' + x.id + '">✅ Apply to guidebook</button><button class="sk" data-sk="' + x.id + '">Ignore</button></div>' : '') + '</div>';
      });
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
  function load() { return api.get('wa.g_botlearn').then(function (d) { data = d; draw(); }).catch(function () { }); }
  load(); setInterval(function () { if (!document.hidden && !busy) load(); }, 60000);
})();
