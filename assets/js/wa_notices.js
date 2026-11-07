/* 🔔 A small bar at the top of the WhatsApp screens: "Rahul took over Priya's chat". Only the person who LOST the chat sees it, until they close it (✕).
   Loaded by wa_inbox.js and wa_app.js. Needs api.js. */
(function () {
  if (window.WaNotices) return; window.WaNotices = { on: true };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const st = document.createElement('style'); st.textContent = '#wnBox{position:fixed;left:50%;top:8px;transform:translateX(-50%);z-index:9400;display:flex;flex-direction:column;gap:6px;width:min(96vw,560px);pointer-events:none}#wnBox .wn{pointer-events:auto;display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#7c2d12,#b45309);color:#fff;border-radius:14px;padding:9px 12px;box-shadow:0 10px 30px rgba(124,45,18,.35);font:600 13px system-ui,sans-serif}#wnBox .wn b{font-weight:800}#wnBox .wn span.t{flex:1;line-height:1.35}#wnBox .wn small{display:block;font-weight:500;opacity:.8;font-size:11.5px}#wnBox .wn button{border:0;background:rgba(255,255,255,.2);color:#fff;border-radius:99px;width:26px;height:26px;font-size:14px;cursor:pointer;flex:0 0 auto}';
  document.head.appendChild(st);
  let box = null, shown = new Set(), busy = false;
  const ago = (t) => { const m = Math.max(0, Math.round((Date.now() - new Date(String(t).replace(' ', 'T')).getTime()) / 60000)); return m < 1 ? 'just now' : m < 60 ? m + ' min ago' : Math.round(m / 60) + ' h ago'; };
  const pretty = (p) => '+91 ' + String(p).replace(/^(\d{5})(\d{5})$/, '$1 $2');
  function draw(list) {
    if (!box) { box = document.createElement('div'); box.id = 'wnBox'; document.body.appendChild(box); }
    box.innerHTML = list.map((n) => `<div class="wn" data-p="${esc(n.phone)}"><span class="t">🔄 <b>${esc(n.by)}</b> took over ${n.name ? '<b>' + esc(n.name) + '</b>’s' : 'your'} chat${n.name ? '' : ' ' + esc(pretty(n.phone))}<small>${esc(ago(n.at))} · it is no longer in your list</small></span><button type="button" data-x="${n.id}" aria-label="Close">✕</button></div>`).join('');
    box.querySelectorAll('[data-x]').forEach((b) => { b.onclick = async (e) => { e.stopPropagation(); const id = +b.dataset.x; b.closest('.wn').remove(); try { await api.post('wa.notice_seen', { ids: [id] }); } catch (er) { } }; });
  }
  async function poll() {
    if (busy || document.hidden || !window.api) return; busy = true;
    try { const d = await api.get('wa.notices'); const list = d.notices || []; draw(list); if (list.some((n) => !shown.has(n.id))) { try { navigator.vibrate && navigator.vibrate(60); } catch (e) { } list.forEach((n) => shown.add(n.id)); } }
    catch (e) { } finally { busy = false; }
  }
  poll(); setInterval(poll, 15000); document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
