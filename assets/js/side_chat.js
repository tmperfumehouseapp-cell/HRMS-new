/* side_chat.js — 💬 a customer's WhatsApp chat in a panel BESIDE the page you are on (Orders, Order Allocation …).
 *   SideChat.open('9876543210')  — opens (or switches) the chat; the page narrows so the list stays visible next to it on a wide screen; phone: full screen.
 *   SideChat.close()
 * The chat inside is wa_one.html (the same chat screen as the WhatsApp section): read, reply, assign. Needs the WhatsApp permission, like the WhatsApp section. */
(function () {
  'use strict';
  if (window.SideChat) return;
  const css = '#scPanel{position:fixed;top:0;right:0;bottom:0;width:min(520px,48vw);z-index:2000;background:#fff;box-shadow:-8px 0 30px rgba(15,23,42,.18);display:flex;flex-direction:column;border-left:1px solid #e2e8f0}'
    + '#scPanel .sch{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid #e2e8f0;background:#f8fafc;font-weight:700;font-size:14px}#scPanel .sch button{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:4px 10px;cursor:pointer;font:inherit;font-size:13px}#scPanel .sch .sp{flex:1}'
    + '#scPanel iframe{flex:1;border:0;width:100%;background:#fff}body.sc-on{transition:margin-right .15s}'
    + '@media (max-width:900px){#scPanel{width:100vw}}';
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const wide = () => window.innerWidth > 1000;
  function open(phone) {
    const ph = String(phone || '').replace(/\D/g, '').slice(-10); if (ph.length !== 10) return;
    let p = document.getElementById('scPanel');
    if (!p) {
      p = document.createElement('div'); p.id = 'scPanel';
      p.innerHTML = '<div class="sch"><b id="scT">Chat</b><span class="sp"></span><button type="button" id="scX" aria-label="Close chat">✕ Close</button></div><iframe id="scI" title="Chat" allow="microphone"></iframe>';
      document.body.appendChild(p); document.getElementById('scX').onclick = close;
    }
    document.getElementById('scI').src = 'wa_one.html?phone=' + ph; document.getElementById('scT').textContent = '💬 +91 ' + ph; p.dataset.p = ph;
    document.body.classList.add('sc-on'); if (wide()) document.body.style.marginRight = 'min(520px,48vw)';
  }
  function close() { const p = document.getElementById('scPanel'); if (p) p.remove(); document.body.classList.remove('sc-on'); document.body.style.marginRight = ''; }
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && document.getElementById('scPanel') && !document.querySelector('.modal-overlay.open, .modal.open')) close(); });
  window.addEventListener('message', (e) => { if (e.origin === location.origin && e.data && e.data.wa === 'close') close(); });   // the chat screen's own close button
  window.SideChat = { open, close };
})();
