/* 🚨 WhatsApp → Waiting → "Not replied": every customer who wrote last and got no answer — any age, also past the 24 hours.
 * A full page of its own (the Chats screen is already full): the day buttons, complaints / sales, can-reply-now / template-only, one count per salesperson (Admin),
 * then the list. A click on a customer opens the chat beside the list. A salesperson sees only their own chats. Server: wa.unans_stats · wa.unans_export (wa.php). */
(function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const CSS = `
  .un{max-width:1180px; margin:0 auto; font-size:13.5px; color:#1f2937;}
  .un-h{display:flex; align-items:flex-start; gap:12px; background:#fff7ed; border:1px solid #f3d9b8; border-radius:16px; padding:14px 16px;}
  .un-h .t{flex:1; min-width:0;} .un-h h2{margin:0; font-size:17px; font-weight:800; color:#9a3412;} .un-h p{margin:3px 0 0; font-size:12.5px; color:#7c5a3a; line-height:1.4;}
  .un-xl{border:0; background:#16945a; color:#fff; border-radius:99px; padding:8px 14px; font:inherit; font-size:12.5px; font-weight:800; cursor:pointer; white-space:nowrap; min-height:36px;}
  .un-f{background:#fff; border:1px solid #e4e7ec; border-radius:16px; padding:10px 14px 12px; margin-top:10px;}
  .un-f .lb{font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; color:#6b7684; margin:8px 0 5px;} .un-f .lb:first-child{margin-top:0;}
  .un-f .row{display:flex; gap:6px; flex-wrap:wrap;}
  .un-f button{border:1px solid #d9dee6; background:#fff; color:#1f2937; border-radius:99px; padding:7px 12px; font:inherit; font-size:12.5px; font-weight:700; cursor:pointer; white-space:nowrap; min-height:34px;}
  .un-f button i{font-style:normal; font-weight:800; margin-left:6px; color:#c2410c;} .un-f button.on{background:#9a3412; border-color:#9a3412; color:#fff;} .un-f button.on i{color:#fed7aa;}
  .un-f .g i{color:#15803d;} .un-f .r i{color:#b91c1c;}
  .un-f .dt{display:flex; gap:6px; align-items:center; margin-top:8px; flex-wrap:wrap;} .un-f .dt input{border:1px solid #d9dee6; border-radius:10px; padding:6px 9px; font:inherit; font-size:13px; min-height:34px;}
  .un-sum{margin:12px 2px 6px; font-size:12.5px; color:#6b7684; font-weight:700;}
  .un-l{background:#fff; border:1px solid #e4e7ec; border-radius:16px; overflow:hidden;}
  .un-th,.un-r{display:grid; grid-template-columns:minmax(150px,1.1fr) minmax(0,2fr) 96px minmax(90px,.8fr) 128px 104px; gap:10px; align-items:center; padding:9px 14px;}
  .un-th{font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; color:#6b7684; background:#f8fafc; border-bottom:1px solid #e4e7ec;}
  .un-r{border-bottom:1px solid #f0f2f5; cursor:pointer;} .un-r:last-child{border-bottom:0;} .un-r:hover{background:#fffaf3;}
  .un-r .nm b{display:block; font-weight:800; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;} .un-r .nm small{color:#6b7684; font-size:12px;}
  .un-r .ms{color:#374151; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; line-height:1.35;}
  .un-p{display:inline-block; border-radius:99px; padding:2px 9px; font-size:11.5px; font-weight:800; white-space:nowrap;}
  .un-p.w{background:#ffedd5; color:#9a3412;} .un-p.ok{background:#dcfce7; color:#166534;} .un-p.no{background:#fee2e2; color:#991b1b;} .un-p.cx{background:#f3e8ff; color:#6b21a8; margin-top:3px;} .un-p.nb{background:#fee2e2; color:#991b1b;}
  .un-r .who{font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
  .un-r .go{border:1px solid #9a3412; color:#9a3412; background:#fff; border-radius:99px; padding:6px 0; font:inherit; font-size:12px; font-weight:800; cursor:pointer; text-align:center;}
  .un-e{padding:34px 16px; text-align:center; color:#6b7684;} .un-more{padding:10px; text-align:center; font-size:12px; color:#6b7684; background:#f8fafc;}
  .un-r .k{display:none;}
  @media (max-width:820px){
    .un-h{flex-direction:column; padding:12px 14px;} .un-xl{align-self:stretch;}
    .un-f .row{flex-wrap:nowrap; overflow-x:auto; padding-bottom:3px; -webkit-overflow-scrolling:touch; scrollbar-width:none;} .un-f .row::-webkit-scrollbar{display:none;} .un-f button{flex:0 0 auto; min-height:38px;}
    .un-th{display:none;}
    .un-r{grid-template-columns:1fr auto; gap:4px 10px; padding:12px 14px;}
    .un-r .nm{grid-column:1;} .un-r .wt{grid-column:2; grid-row:1; text-align:right;} .un-r .ms{grid-column:1 / -1; -webkit-line-clamp:3;}
    .un-r .who{grid-column:1; font-size:12.5px; color:#475569;} .un-r .who .k{display:inline; font-weight:600; color:#6b7684;} .un-r .cr{grid-column:2; grid-row:3; text-align:right;} .un-r .go{grid-column:1 / -1; padding:9px 0; margin-top:4px; font-size:13px;}
  }`;
  function mount(el, opt) {
    opt = opt || {};
    if (!document.getElementById('unCss')) { const st = document.createElement('style'); st.id = 'unCss'; st.textContent = CSS; document.head.appendChild(st); }
    el.classList.add('pad');
    const U = { d: '7', k: '', w: '', e: 0, f: '', t: '', st: null, rows: [], busy: false, admin: false };
    el.innerHTML = '<div class="un"><div class="un-h"><div class="t"><h2 id="unT">🚨 Customers nobody replied to</h2><p>The customer wrote last and got no answer — from a person or from the bot. A campaign or a reminder does not count as an answer. Tap a customer to open the chat and reply.</p></div><button type="button" class="un-xl" id="unX">⬇ Download Excel</button></div><div class="un-f" id="unF"></div><div class="un-sum" id="unS"></div><div class="un-l" id="unL"><div class="un-e">Loading…</div></div></div>';
    const q = (id) => el.querySelector('#' + id);
    const params = (lim) => { const o = { ud: U.d }; if (U.d === 'dates' && U.f && U.t) { o.uf = U.f; o.ut = U.t; } if (U.k) o.uk = U.k; if (U.w) o.uw = U.w; if (U.e) o.ue = U.e; if (lim) o.limit = lim; return o; };
    const n = (v) => (v == null ? '' : '<i>' + v + '</i>');
    function drawF() {
      const st = U.st || { ranges: {}, kind: {}, by: [] }, rg = st.ranges || {}, kd = st.kind || {};
      const D = [['today', 'Today'], ['yest', 'Yesterday'], ['7', '7 days'], ['15', '15 days'], ['20', '20 days'], ['30', '30 days'], ['60', '60 days']];
      q('unT').textContent = U.admin ? '🚨 Customers nobody replied to' : '🚨 Customers you never replied to';
      q('unF').innerHTML = `<div class="lb">When did the customer write?</div><div class="row">${D.map(([k, l]) => `<button type="button" data-d="${k}" class="${U.d === k ? 'on' : ''}">${l}${n(rg[k])}</button>`).join('')}<button type="button" data-d="dates" class="${U.d === 'dates' ? 'on' : ''}">📅 Pick dates…</button></div>
        ${U.d === 'dates' ? `<div class="dt"><input type="date" id="unDf" value="${esc(U.f)}"> to <input type="date" id="unDt" value="${esc(U.t)}"><button type="button" id="unDg" class="on">Show</button></div>` : ''}
        <div class="lb">Which customers?</div><div class="row"><button type="button" data-k="" class="${!U.k ? 'on' : ''}">All${n(kd.all)}</button><button type="button" data-k="cx" class="${U.k === 'cx' ? 'on' : ''}">🟣 Complaints${n(kd.cx)}</button><button type="button" data-k="sales" class="${U.k === 'sales' ? 'on' : ''}">🛍 Sales${n(kd.sales)}</button></div>
        <div class="lb">Can we still reply?</div><div class="row"><button type="button" data-w="" class="${!U.w ? 'on' : ''}">All${n(st.total)}</button><button type="button" data-w="now" class="g ${U.w === 'now' ? 'on' : ''}">✅ Can reply now (inside 24 hours)${n(st.now)}</button><button type="button" data-w="past" class="r ${U.w === 'past' ? 'on' : ''}">⏰ Past 24 hours — template only${n(st.past)}</button></div>
        ${U.admin ? `<div class="lb">Who has the chat?</div><div class="row"><button type="button" data-e="0" class="${!U.e ? 'on' : ''}">Everyone${n(st.total)}</button>${(st.by || []).map((x) => `<button type="button" data-e="${x.emp}" class="${U.e === x.emp ? 'on' : ''}">${esc(x.name)}${n(x.n)}</button>`).join('')}</div>` : ''}`;
      const F = q('unF');
      F.querySelectorAll('[data-d]').forEach((b) => { b.onclick = () => { U.d = b.dataset.d; U.e = 0; if (U.d === 'dates' && !(U.f && U.t)) { drawF(); return; } load(); }; });
      F.querySelectorAll('[data-k]').forEach((b) => { b.onclick = () => { U.k = b.dataset.k; U.e = 0; load(); }; });
      F.querySelectorAll('[data-w]').forEach((b) => { b.onclick = () => { U.w = b.dataset.w; load(); }; });
      F.querySelectorAll('[data-e]').forEach((b) => { b.onclick = () => { U.e = +b.dataset.e; load(); }; });
      if (q('unDg')) q('unDg').onclick = () => { U.f = q('unDf').value; U.t = q('unDt').value; if (!U.f || !U.t) { alert('Pick both dates.'); return; } load(); };
    }
    function drawL() {
      const L = q('unL'), R = U.rows;
      q('unS').textContent = R.length ? (R.length >= 300 ? 'Showing the newest 300 — narrow the days or download Excel for all.' : R.length + ' customer' + (R.length === 1 ? '' : 's') + ' waiting for an answer') : '';
      if (!R.length) { L.innerHTML = '<div class="un-e">🎉 Nobody is waiting for an answer in these days.</div>'; return; }
      L.innerHTML = `<div class="un-th"><span>Customer</span><span>Their last message</span><span>Waiting</span><span>Chat is with</span><span>Can we reply?</span><span></span></div>` + R.map((r) => `<div class="un-r" data-p="${esc(r.phone)}">
        <div class="nm"><b>${esc(r.name || '+91 ' + r.phone)}</b><small>+91 ${esc(r.phone)}</small>${r.kind === 'Complaint' ? '<br><span class="un-p cx">🟣 Complaint</span>' : ''}</div>
        <div class="ms">${esc(r.last_message || '(photo / voice / file)')}</div>
        <div class="wt"><span class="un-p w">⏳ ${esc(r.waiting)}</span></div>
        <div class="who"><span class="k">Chat is with: </span>${r.with === 'Nobody' ? '<span class="un-p nb">Nobody</span>' : '👤 ' + esc(r.with)}</div>
        <div class="cr"><span class="un-p ${r.can_reply.indexOf('Yes') === 0 ? 'ok' : 'no'}">${r.can_reply.indexOf('Yes') === 0 ? '✅ Reply now' : '⏰ Template only'}</span></div>
        <button type="button" class="go">Open chat ›</button></div>`).join('');
      L.querySelectorAll('.un-r').forEach((x) => { x.onclick = () => { if (opt.openChat) opt.openChat(x.dataset.p); }; });
    }
    async function load() {
      if (U.busy) return; U.busy = true; drawF();
      try {
        const so = { ud: U.d }; if (U.d === 'dates' && U.f && U.t) { so.uf = U.f; so.ut = U.t; } if (U.k) so.uk = U.k;
        const [st, ls] = await Promise.all([api.get('wa.unans_stats', so), api.get('wa.unans_export', params(300))]);
        U.st = st; U.admin = !!st.admin; U.rows = ls.rows || []; drawF(); drawL();
      } catch (e) { q('unL').innerHTML = '<div class="un-e">' + esc(e.message) + '</div>'; }
      U.busy = false;
    }
    q('unX').onclick = async () => { const b = q('unX'); b.disabled = true; b.textContent = 'Making…';
      try { const d = await api.get('wa.unans_export', params(0)); const cols = [['Customer', 'name'], ['Number', 'phone'], ['Kind', 'kind'], ['Last message from the customer', 'last_message'], ['Wrote at', 'wrote_at'], ['Waiting', 'waiting'], ['Can reply now?', 'can_reply'], ['Chat is with', 'with'], ['Chat', 'chat']];
        const c = (v) => { v = v == null ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const txt = [cols.map((x) => c(x[0])).join(',')].concat((d.rows || []).map((r) => cols.map((x) => c(r[x[1]])).join(','))).join('\r\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + txt], { type: 'text/csv;charset=utf-8' })); a.download = 'not_replied_' + new Date().toISOString().slice(0, 10) + '.csv'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
      } catch (e) { alert(e.message); } b.disabled = false; b.textContent = '⬇ Download Excel'; };
    load();
    return load;   // called again each time the page is opened
  }
  window.WaUnans = { mount };
})();
