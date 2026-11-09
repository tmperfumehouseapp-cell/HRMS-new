/**
 * wa_growth.js — the Admin screens added in the growth round (whatsapp.html):
 * Queries, Signals, History, Tags & audience, Fixed messages, Message builder, Flows, Gallery & storage, Ads, Export, Claude button
 * plus the Claude bar shown on every Admin screen.   Needs api.js and wa_panels.js (loaded first).
 *   WaGrowth.tagOptions()            → promise of [['', 'Any tag'], [key, 'Label (count)'], …]   (used by Campaigns and Contacts)
 *   WaGrowth.claudeBar(el, screen, ctx)
 */
(function () {
  if (!window.WaPanels) return;
  const { esc, $, fmt, money, pretty, when, mins, toast, modal, sw, sel, upload, errBox, loading } = WaPanels.util;
  const P = (k, fn) => WaPanels.add(k, fn);
  const num = (n) => fmt(n);
  const CSS = `
  .cbchip{display:inline-flex;align-items:center;gap:4px;height:26px;padding:0 10px;border-radius:999px;border:0;background:#ede7ff;color:#5b34b5;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit}.cbchip b{font-weight:800}.cbchip.bad{background:#fde4e4;color:#b3261e}
  .cb-pop{position:fixed;top:52px;right:12px;width:min(540px,calc(100vw - 24px));max-height:min(72vh,640px);overflow:auto;background:#fff;border:1px solid #ddd0f7;border-radius:14px;box-shadow:0 12px 40px rgba(17,27,33,.25);z-index:960;padding:8px 14px 12px}.cb-pop .cb-h{display:flex;align-items:center;gap:8px;position:sticky;top:-8px;background:#fff;padding:8px 0 6px;z-index:1}.cb-pop .cb-h .sp{flex:1}
  @media (max-width:860px){.cb-pop{top:48px;right:8px;left:8px;width:auto}}
  .wg-cb{margin:0 0 12px}.wg-cb .wg-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:linear-gradient(90deg,#f3ecff,#eef6ff);border:1px solid #ddd0f7;border-radius:14px;padding:8px 12px;font-size:13px}
  .wg-cb .wg-bar b{color:#5b34b5}.wg-cb .wg-bar .n{background:#5b34b5;color:#fff;border-radius:99px;font-size:11px;font-weight:800;padding:1px 8px}.wg-cb .wg-bar .sp{flex:1}
  .wg-cb .box{background:#fff;border:1px solid #ddd0f7;border-radius:14px;padding:6px 14px 12px;margin-top:8px}
  .wg-it{display:flex;gap:10px;padding:10px 0;border-top:1px solid #f0edf8;font-size:13px;line-height:1.45}.wg-it:first-child{border-top:0}
  .wg-it .dot{width:9px;height:9px;border-radius:50%;margin-top:6px;flex:none;background:#8a998f}.wg-it.bad .dot{background:#d92d20}.wg-it.warn .dot{background:#f59e0b}.wg-it.idea .dot{background:#7c3aed}.wg-it.tip .dot{background:#12794a}
  .wg-it .tx{flex:1}.wg-ch{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;margin:4px 4px 0 0;background:#eef1ef;color:#4a5a52}
  .wg-ch.built{background:#dcf7e6;color:#12794a}.wg-ch.partial{background:#fff3cd;color:#8a6a00}.wg-ch.missing{background:#fde6e6;color:#b42318}
  .wg-seg{display:inline-flex;background:#eef1ef;border-radius:99px;padding:3px}.wg-seg button{border:0;background:none;padding:7px 14px;border-radius:99px;font-weight:700;font-size:12.5px;cursor:pointer;color:#4a5a52}.wg-seg button.on{background:#1b2430;color:#fff}
  .wg-n{font-variant-numeric:tabular-nums;text-align:right}.wg-rowk{cursor:pointer}.wg-rowk:hover{background:#f6faf7}
  .wg-ch2{display:flex;gap:10px;align-items:flex-start;padding:10px 2px;border-top:1px solid #edf1ee;cursor:pointer}.wg-ch2:first-child{border-top:0}.wg-ch2:hover{background:#f8fbf9}
  .wg-ch2 .t{flex:1;min-width:0}.wg-ch2 .t b{font-size:14px}.wg-ch2 .t div{font-size:12.5px;color:#5c6b64;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .wg-st{border:1px solid #e3e8e5;border-radius:14px;background:#fff;padding:10px 12px;margin:8px 0}.wg-st .h{display:flex;gap:8px;align-items:center;margin-bottom:6px}.wg-st .h b{flex:1;font-size:13px}
  .wg-st .no{width:24px;height:24px;border-radius:50%;background:#1b2430;color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:none}
  .wg-mini{font-size:11.5px;color:#6b7a73}.wg-jump{display:flex;gap:6px;align-items:center;margin-top:6px;font-size:12px;color:#46564e;flex-wrap:wrap}.wg-jump select{width:auto;min-height:34px;padding:4px 8px}
  .wg-path{background:#0e1a15;color:#d7f5e3;border-radius:12px;padding:10px 14px;font-size:12.5px;line-height:1.6;white-space:pre-wrap}
  .wg-th{width:100%;aspect-ratio:1;border-radius:10px;background:#eef1ef center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-size:26px}
  .wg-gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}.wg-gi{background:#fff;border:1px solid #e3e8e5;border-radius:12px;padding:6px;font-size:11.5px}.wg-gi b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:4px}
  @media (max-width:760px){
    body.waui .wp-btn,body.waui .wp-btn.s,body.waui .wp-chip,body.waui .lk,.wg-seg button{min-height:44px}
    body.waui .wp-in,body.waui select.wp-in,body.waui .wp-cd select,body.waui .wp-cd input:not([type=checkbox]),.wg-jump select{min-height:44px;font-size:16px}
    .wg-cb .wg-bar{padding:6px 8px 6px 12px;font-size:12.5px;border-radius:12px;flex-wrap:nowrap;gap:6px}.wg-cb .wg-bar>span:not(.n):not(.sp){min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.wg-cb .wg-bar b{white-space:nowrap}.wg-cb .wg-bar .wp-btn{flex:none;padding:0 12px}.wg-ch2{padding:12px 2px}
    .wg-gal{grid-template-columns:repeat(2,1fr)}
    .wg-ch2{flex-wrap:wrap}.wg-ch2 .t{flex:1 1 100%}.wg-ch2 select{flex:1;min-width:0}
    .wp-top{gap:8px}.wp-top .wp-btn{width:100%}
  }
  .wg-tb-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  /* flow editor (wa_flow_ui) */
  .wp-mb.wfx-mb{max-width:min(1060px,96vw)}
  .wfx{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;align-items:start}.wfx-main,.wfx-side{min-width:0}
  .wfx-ai{background:linear-gradient(90deg,#f1ecff,#f7f4ff);border:1px solid #ddd3fb;border-radius:14px;padding:10px 12px;font-size:13px;margin:8px 0;color:#3b2a8a;line-height:1.5}
  .wfx-st .h{cursor:pointer;flex-wrap:wrap;margin-bottom:0}.wfx-st.open .h{margin-bottom:8px}.wfx-st .wfx-ic{font-size:15px;flex:none}.wfx-st .h b{flex:none;font-weight:600;color:#46564e}
  .wfx-st .wfx-sum{flex:1;min-width:0;text-align:right;font-weight:800;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .wfx-st .h .wp-btn.s{min-height:30px;min-width:30px;padding:0 8px}.wfx-bd{display:none}.wfx-st.open .wfx-bd{display:block}
  .wfx-bt{border:1px solid #c9b8f7;background:#f4effe;color:#3b2a8a;border-radius:99px;padding:2px 10px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;margin:2px 2px 0 0}.wfx-bt.on{background:#3b2a8a;color:#fff;border-color:#3b2a8a}
  .wfx-days{width:56px;border:1px solid #d8dfdb;border-radius:8px;text-align:center;font-weight:800;padding:3px 4px;font-family:inherit;font-size:13px}
  .wfx-rw{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) auto;gap:6px;margin-top:6px}
  .wfx-rp{flex:1;min-width:180px}.wfx-more{width:auto;max-width:100%;border-radius:99px}
  body.waui .wfx-st{border-radius:16px;border-color:var(--line,#e3e8e5)}body.waui .wfx-ai{border-radius:16px}body.waui .wfx-bt{border-color:#d9ccf9;background:#f3eefe;color:#4b2fa8}body.waui .wfx-bt.on{background:#4b2fa8;border-color:#4b2fa8;color:#fff}
  @media (max-width:860px){.wfx{grid-template-columns:1fr}}
  @media (max-width:760px){
    .wfx-st .wfx-sum{order:9;flex:1 1 100%;text-align:left;white-space:normal}.wfx-st .h b{flex:1 1 0;min-width:0}.wfx-st .h .wp-btn.s{min-height:40px;min-width:40px;padding:0}.wfx-st .h{flex-wrap:wrap;gap:6px}
    .wfx-rw{grid-template-columns:minmax(0,1fr) auto}.wfx-rw .wp-in:nth-child(2){grid-column:1 / -1;order:3}.wfx-bt{min-height:36px;padding:2px 12px}.wfx-days{min-height:38px;font-size:16px}
  }
  `;
  if (!document.getElementById('wgCss')) { const s = document.createElement('style'); s.id = 'wgCss'; s.textContent = CSS; document.head.appendChild(s); }

  const ago = (s) => { if (!s) return ''; const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)/.exec(s); if (!m) return ''; const d = (Date.now() - new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]).getTime()) / 60000; return d < 60 ? Math.max(1, Math.round(d)) + ' min' : d < 1440 ? Math.round(d / 60) + ' h' : Math.round(d / 1440) + ' d'; };
  const head = (t, sub, extra) => `<div class="wp-top"><div style="flex:1;min-width:200px"><h2>${esc(t)}</h2><p>${esc(sub || '')}</p></div>${extra || ''}</div>`;
  const chips = (needs) => (needs || []).map((n) => `<span class="wg-ch ${esc(n.status)}" title="${esc(n.note || '')}">${n.status === 'built' ? '✓' : n.status === 'partial' ? '◐' : '✕'} ${esc(n.label)}${n.status === 'missing' ? ' — not built yet' : n.status === 'partial' ? ' — partly' : ''}</span>`).join('');
  let PEOPLE = null;
  async function people() { if (PEOPLE) return PEOPLE; try { PEOPLE = (await api.get('wa.g_hq_get')).people || []; } catch (e) { PEOPLE = []; } return PEOPLE; }

  // ============================================================================================================== the Claude bar
  function claudeBar(host, screen, ctx) {
    if (!host || host.querySelector(':scope > .wg-cb')) return;
    const slot = document.getElementById('cbSlot');
    const bar = document.createElement('div'); bar.className = 'wp wg-cb'; host.insertBefore(bar, host.firstChild);
    let open = false, D = null, ideas = null;
    const body = (items) => (items.length ? items.map((x) => `<div class="wg-it ${esc(x.level)}"><span class="dot"></span><div class="tx">${esc(x.text)}<div>${chips(x.needs)}</div></div>${x.go && ctx && ctx.go ? `<button type="button" class="wp-btn s" data-go="${esc(x.go)}">Open</button>` : ''}</div>`).join('') : '<div class="wp-hint" style="padding:8px 0">Nothing to flag on this screen right now.</div>')
      + (ideas ? '<div style="margin-top:8px;font-weight:800;font-size:12.5px">Claude’s next steps</div>' + (ideas.length ? ideas.map((x) => `<div class="wg-it idea"><span class="dot"></span><div class="tx"><b>${esc(x.title)}</b><br>${esc(x.text)}<div>${chips(x.needs)}</div></div></div>`).join('') : '<div class="wp-hint">No ideas came back.</div>') : '');
    const ask = async (btn) => { btn.disabled = true; btn.textContent = 'Thinking…'; open = true; try { const r = await api.post('wa.g_claude_ask', { screen }); ideas = r.ideas || []; } catch (er) { ideas = []; toast(er.message, true); } draw(); };
    const draw = () => {
      const items = (D && D.items) || [], bad = items.some((x) => x.level === 'bad');
      if (slot) {   // compact: one small chip in the top bar; the list opens as a pop-over and never pushes the page down
        if (bar.hidden !== true) { bar.hidden = true; bar.style.display = 'none'; }
        if (host.hidden) return; slot.innerHTML = `<button type="button" class="cbchip ${bad ? 'bad' : ''}" id="cbChip" title="What Claude sees on this screen" aria-expanded="${open}">✨${items.length ? ' <b>' + items.length + '</b>' : ''}</button>`;
        $(slot, '#cbChip').onclick = (e) => { e.stopPropagation(); open = !open; draw(); };
        let pop = document.getElementById('cbPop'); if (pop) pop.remove();
        if (open) {
          pop = document.createElement('div'); pop.id = 'cbPop'; pop.className = 'wp cb-pop';
          pop.innerHTML = `<div class="cb-h"><b>✨ Claude sees</b><span class="sp"></span><button type="button" class="wp-btn s p" id="cbA">Ask Claude</button><button type="button" class="wp-btn s" id="cbX" aria-label="Close">✕</button></div>` + body(items);
          document.body.appendChild(pop);
          $(pop, '#cbA').onclick = (e) => ask(e.currentTarget); $(pop, '#cbX').onclick = () => { open = false; draw(); };
          pop.querySelectorAll('[data-go]').forEach((b) => { b.onclick = () => { open = false; draw(); ctx.go(b.dataset.go); }; });
        }
        return;
      }
      let h = `<div class="wg-bar"><b>✨ Claude</b><span>${items.length ? 'sees ' : 'sees nothing urgent here'}</span>${items.length ? `<span class="n">${items.length}</span>` : ''}<span class="sp"></span><button type="button" class="wp-btn s" id="cbT">${open ? 'Close' : 'Show'}</button><button type="button" class="wp-btn s p" id="cbA">Ask Claude</button></div>`;
      if (open) h += '<div class="box">' + body(items) + '</div>';
      bar.innerHTML = h;
      $(bar, '#cbT').onclick = () => { open = !open; draw(); };
      $(bar, '#cbA').onclick = (e) => ask(e.currentTarget);
      bar.querySelectorAll('[data-go]').forEach((b) => { b.onclick = () => ctx.go(b.dataset.go); });
    };
    host._cb = { show: () => { open = false; draw(); } };
    draw();
    api.get('wa.g_claude', { screen }).then((d) => { D = d; if (!slot && d.items && d.items.some((x) => x.level === 'bad')) open = true; draw(); }).catch(() => { });
  }
  // the screen on show owns the chip in the top bar (called when you switch screens; null = this screen has none)
  function claudeShow(host) {
    const slot = document.getElementById('cbSlot'); if (!slot) return; const pop = document.getElementById('cbPop'); if (pop) pop.remove();
    if (host && host._cb) host._cb.show(); else slot.innerHTML = '';
  }
  document.addEventListener('click', (e) => { const pop = document.getElementById('cbPop'); if (pop && !e.target.closest('#cbPop') && !e.target.closest('#cbChip')) { const slot = document.getElementById('cbSlot'); pop.remove(); const c = slot && slot.querySelector('#cbChip'); if (c) c.setAttribute('aria-expanded', 'false'); } });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { const pop = document.getElementById('cbPop'); if (pop) pop.remove(); } });

  // ============================================================================================================== Queries (the one automatic section)
  P('queries', (el, ctx) => {
    let days = 1, D = null; el.innerHTML = loading;
    async function load() { try { D = await api.get('wa.g_qload', { days }); } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    function draw() {
      const t = D.team || {};
      let h = `<div class="wp">${head('Queries', 'Counted automatically from who holds each chat now. Give a chat to someone else and it comes off the first person and goes onto the second.', `<div class="wg-seg">${[[1, 'Today'], [7, '7 days'], [30, '30 days']].map(([v, l]) => `<button type="button" data-d="${v}" class="${days === v ? 'on' : ''}">${l}</button>`).join('')}</div>`)}
      <div class="wp-kpis">
        <div class="wp-kpi"><small>Meta ad</small><b>${num(t.query)}</b></div>
        <div class="wp-kpi"><small>Community</small><b>${num(t.community)}</b></div>
        <div class="wp-kpi"><small>Campaign replies</small><b>${num(t.campaign)}</b></div>
        <div class="wp-kpi"><small>All queries</small><b>${num(t.total)}</b></div>
        <div class="wp-kpi"><small>Became orders</small><b>${num(t.orders)} <span class="wg-mini">${t.rate || 0}%</span></b></div>
        <div class="wp-kpi" style="cursor:pointer" id="qMi"><small>Missed (taken away late)</small><b style="${t.missed ? 'color:#c2262b' : ''}">${num(t.missed)}</b></div>
        <div class="wp-kpi" style="cursor:pointer" id="qUn"><small>Nobody has taken yet</small><b style="${D.unassigned ? 'color:#c2262b' : ''}">${num(D.unassigned)}</b></div>
        <div class="wp-kpi" style="cursor:pointer" id="qLo"><small>Never answered (7 days)</small><b style="${D.lost ? 'color:#c2262b' : ''}">${num(D.lost)}</b></div>
      </div>
      <div class="wp-cd"><h3>Per person</h3>`;
      if (!(D.rows || []).length) h += '<div class="wp-empty">No query in this period.</div>';
      else h += `<div style="overflow-x:auto"><table class="wp-tb"><thead><tr><th>Person</th><th class="wg-n">Ad</th><th class="wg-n">Comm.</th><th class="wg-n">Camp.</th><th class="wg-n">Other</th><th class="wg-n">Total</th><th class="wg-n">Back again</th><th class="wg-n">Orders</th><th class="wg-n">Rate</th><th class="wg-n">In ↘</th><th class="wg-n">Out ↗</th><th class="wg-n">Missed</th></tr></thead><tbody>${D.rows.map((r) => `<tr class="wg-rowk" data-e="${r.emp_id}"><td><b>${esc(r.name)}</b></td><td class="wg-n">${r.query}</td><td class="wg-n">${r.community}</td><td class="wg-n">${r.campaign}</td><td class="wg-n">${r.other}</td><td class="wg-n"><b>${r.total}</b></td><td class="wg-n">${r.again || '–'}</td><td class="wg-n" style="color:#12794a">${r.orders || '–'}</td><td class="wg-n">${r.total ? r.rate + '%' : '–'}</td><td class="wg-n" style="color:#12794a">${r.moved_in ? '+' + r.moved_in : '–'}</td><td class="wg-n" style="color:#b42318">${r.moved_out ? '−' + r.moved_out : '–'}</td><td class="wg-n" style="${r.missed ? 'color:#b42318;font-weight:800' : ''}">${r.missed || '–'}</td></tr>`).join('')}</tbody></table></div><div class="wp-hint">Tap a person to see their open chats and move one to someone else.</div>`;
      h += `</div><div class="wp-cd"><h3>How the counting works</h3><div style="font-size:13px;line-height:1.6;color:#33423a">
        <b>1. One query = one conversation.</b> It opens when a customer writes and had no query in the last 24 hours. Writing again inside 24 hours is the same query. After 24 hours of silence the chat goes to History, and a new message starts a new query.<br>
        <b>2. Its type is decided once, when it opens:</b> came from a Meta ad, else a reply to our campaign, else from the community link, else other. It never changes later.<br>
        <b>3. The holder owns it.</b> Give it to someone else and it comes off the first person (Out) and goes to the second (In). It always counts in the period it <i>opened</i>, so moving it later never rewrites an old day.<br>
        <b>4. Back again.</b> A customer who had an earlier query or an order is counted in his type as usual and also shown in “Back again”, so nobody looks better by getting only old customers.<br>
        <b>5. Missed.</b> If a chat is taken away after the customer waited longer than the alert limit (Signals), it is written as a miss for the person it was taken from.<br>
        <b>6. Orders and rate.</b> A query counts as an order when its chat gets the result “Order placed” after it opened. Rate = orders ÷ queries.</div></div></div>`; el.innerHTML = h;
      el.querySelectorAll('[data-d]').forEach((b) => { b.onclick = () => { days = +b.dataset.d; load(); }; });
      el.querySelectorAll('[data-e]').forEach((r) => { r.onclick = () => person(+r.dataset.e); });
      $(el, '#qUn').onclick = () => ctx.go('sgive'); $(el, '#qLo').onclick = () => ctx.go('history'); $(el, '#qMi').onclick = () => ctx.go('signals');
    }
    async function person(emp) {
      const m = modal('Open chats'); m.body.innerHTML = loading; let rows = [], pp = await people();
      try { rows = (await api.get('wa.chats', { scope: 'emp', emp })).chats || []; } catch (e) { m.body.innerHTML = errBox(e.message); return; }
      const opts = [['', 'Move to…']].concat(pp.filter((x) => x.emp_id !== emp).map((x) => [x.emp_id, x.name]));
      m.body.innerHTML = rows.length ? rows.map((c) => `<div class="wg-ch2" style="cursor:default"><div class="t"><b>${esc(c.name || pretty(c.phone))}</b><div>${esc(c.preview || '')}</div></div>${c.waiting ? '<span class="wp-pill bad">waiting</span>' : ''}${sel('mv' + c.phone, opts, '', '')}</div>`).join('') : '<div class="wp-empty">No open chat.</div>';
      m.body.querySelectorAll('select').forEach((s) => { s.style.width = 'auto'; s.onchange = async () => { if (!s.value) return; const phone = s.id.slice(2); s.disabled = true; try { await api.post('wa.g_reassign', { phone, emp_id: +s.value }); toast('Moved ✓'); s.closest('.wg-ch2').remove(); load(); } catch (e) { toast(e.message, true); s.disabled = false; } }; });
    }
    load(); return load;
  });

  // ============================================================================================================== Signals
  P('signals', (el, ctx) => {
    let D = null, C = null, lim = 40; el.innerHTML = loading;
    async function load() {
      try { [D, C] = await Promise.all([api.get('wa.g_signals'), api.get('wa.g_cfg')]); } catch (e) { el.innerHTML = errBox(e.message); return; } draw();
    }
    function draw() {
      const c = C.cfg, late = D.late || [], pp = D.people || [];
      const opts = [['', 'Give to…']].concat(pp.map((x) => [x.emp_id, x.name + (x.present ? '' : ' (away)')]));
      const cron = C.cron_at ? ago(C.cron_at) : '';
      let h = `<div class="wp">${head('Signals', 'Customers who wrote and nobody answered within ' + c.unans_min + ' minutes. Give them to someone free — at once.', late.length ? '<button type="button" class="wp-btn p" id="sgAll">Give all to the next free person</button>' : '')}
      <div class="wp-cd"><h3>Waiting too long <span class="wp-pill ${late.length ? 'bad' : 'ok'}">${late.length}</span></h3>${late.length ? late.slice(0, lim).map((r) => `<div class="wg-ch2" style="cursor:default"><div class="t" data-chat="${esc(r.phone)}" style="cursor:pointer"><b>${esc(r.name || pretty(r.phone))}</b> <span class="wp-pill bad">${r.wait_min} min</span>${r.hot ? '<span class="wp-pill warn">hot</span>' : ''}<div>${esc(r.text || '')}</div><div class="wg-mini">With ${esc(r.emp_name || 'nobody')}${r.ad ? ' · ad: ' + esc(r.ad) : ''}</div></div>${sel('rs' + r.phone, opts, '', '')}<button type="button" class="wp-btn s" data-next="${esc(r.phone)}">Next free</button></div>`).join('') + (late.length > lim ? `<div style="padding:10px 0"><button type="button" class="wp-btn s" id="sgMore">Show 40 more (${late.length - lim} left)</button></div>` : '') : '<div class="wp-empty">Nobody is waiting too long. 🎉</div>'}</div>
      <div class="wp-cd"><h3>Team right now</h3>${pp.length ? `<table class="wp-tb"><thead><tr><th>Person</th><th class="wg-n">Open</th><th class="wg-n">Usual reply</th><th></th></tr></thead><tbody>${pp.map((x) => `<tr><td><b>${esc(x.name)}</b></td><td class="wg-n">${x.open}${x.full ? ' (full)' : ''}</td><td class="wg-n">${x.median == null ? '—' : mins(x.median)}</td><td>${x.present ? '<span class="wp-pill ok">here</span>' : '<span class="wp-pill">away</span>'}${x.best ? '<span class="wp-pill warn">fastest</span>' : ''}</td></tr>`).join('')}</tbody></table>` : '<div class="wp-empty">No salesperson set up yet (Team &amp; routing).</div>'}</div>
      <div class="wp-cd"><h3>Rules</h3>
        <div class="wp-row"><div class="wp-grow"><b>Alert after</b><div class="wp-hint">Minutes without an answer before a chat counts as late — everywhere: it goes to the top, the phone buzzes, it shows in Late replies (anyone can take it) and turns red in Not taken.</div></div><input class="wp-in" id="sgM" type="number" min="1" max="60" value="${c.unans_min}" style="width:90px"></div>
        <div class="wp-row" style="margin-top:10px"><div class="wp-grow"><b>Buzz the salesperson’s phone</b></div>${sw('sgP', c.unans_push)}</div>
        <div class="wp-row" style="margin-top:10px"><div class="wp-grow"><b>Sales target per person per day</b><div class="wp-hint">Shown as the target bar in the app (orders).</div></div><input class="wp-in" id="sgT" type="number" min="0" value="${c.target}" style="width:90px"></div>
        <div class="wp-row" style="margin-top:10px"><div class="wp-grow"><b>Query → order goal (%)</b><div class="wp-hint">The conversion goal on Overview and the team board (it had no screen of its own).</div></div><input class="wp-in" id="sgG" type="number" min="1" max="100" placeholder="23" style="width:90px"></div>
        <div class="wp-row" style="margin-top:10px"><div class="wp-grow"><b>Claude tags chats by itself</b></div>${sw('sgA', c.ai_tags)}</div>
        <div class="wp-row" style="margin-top:10px"><div class="wp-grow"><b>Sound &amp; celebration in the app</b></div>${sw('sgS', c.sound)}</div>
        <div class="wp-row" style="margin-top:14px"><button type="button" class="wp-btn p" id="sgSave">Save</button></div></div>
      <div class="wp-cd"><h3>Answering bot <span class="wp-pill ${c.bot === 'on' ? 'ok' : ''}">${c.bot === 'on' ? 'ON' : 'OFF'}</span></h3>
        <div class="wp-hint">Answers 24 hours a day, only from what you taught it in Learning &amp; Improvement. ${C.taught ? '' : '<b style="color:#b42318">Nothing is taught yet — it will stay silent.</b>'} Today it sent ${D.bot.today || 0} answers.</div>
        <div class="wp-row" style="margin-top:10px"><div class="wp-grow"><b>Bot on</b></div>${sw('sgB', c.bot === 'on')}</div>
        <div class="wp-2"><div><label class="wp-fl">Answer when nobody replied for (seconds, 5–600)</label><input class="wp-in" id="sgBa" type="number" min="5" max="600" value="${c.bot_after}"></div><div><label class="wp-fl">Max answers per customer per day</label><input class="wp-in" id="sgBm" type="number" min="1" value="${c.bot_max_day}"></div></div>
        <div class="wp-hint" style="margin-top:10px">The scheduled engine (cron): ${cron ? 'last ran ' + cron + ' ago' : '<b style="color:#b42318">has not run yet</b>'}. Without it the bot, follow-ups and flows with waits only work while a page is open.</div></div></div>`;
      el.innerHTML = h;
      api.get('wa.sales_cfg').then((g) => { const i = el.querySelector('#sgG'); if (i && g && g.goal) i.value = g.goal; }).catch(() => { });
      const mo = el.querySelector('#sgMore'); if (mo) mo.onclick = () => { lim += 40; draw(); };
      el.querySelectorAll('[data-chat]').forEach((b) => { b.onclick = () => ctx.openChat(b.dataset.chat); });
      el.querySelectorAll('select[id^="rs"]').forEach((s) => { s.style.width = 'auto'; s.onchange = () => mv(s.id.slice(2), +s.value, s); });
      el.querySelectorAll('[data-next]').forEach((b) => { b.onclick = () => mv(b.dataset.next, 0, b); });
      const all = $(el, '#sgAll'); if (all) all.onclick = async () => { all.disabled = true; try { const r = await api.post('wa.g_reassign_all', {}); toast(r.moved + ' chat' + (r.moved === 1 ? '' : 's') + ' moved ✓'); load(); } catch (e) { toast(e.message, true); all.disabled = false; } };
      $(el, '#sgSave').onclick = async (e) => {
        e.currentTarget.disabled = true;
        try { const r = await api.post('wa.g_cfg_save', { unans_min: +$(el, '#sgM').value, unans_push: $(el, '#sgP').checked, target: +$(el, '#sgT').value, ai_tags: $(el, '#sgA').checked, sound: $(el, '#sgS').checked, bot: $(el, '#sgB').checked ? 'on' : 'off', bot_after: +$(el, '#sgBa').value, bot_max_day: +$(el, '#sgBm').value }); const gv = +$(el, '#sgG').value; if (gv > 0) await api.post('wa.sales_cfg', { goal: gv }); toast('Saved ✓'); load(); } catch (er) { toast(er.message, true); e.currentTarget.disabled = false; }
      };
    }
    async function mv(phone, emp, node) { node.disabled = true; try { const r = await api.post('wa.g_reassign', { phone, emp_id: emp }); toast('Given to ' + r.to + ' ✓'); load(); } catch (e) { toast(e.message, true); node.disabled = false; } }
    load(); return load;
  });

  // ============================================================================================================== History
  P('history', (el, ctx) => {
    let F = { days: 30, page: 0 }, D = null, pp = []; el.innerHTML = loading;
    async function load(more) {
      if (!pp.length) pp = await people();
      try { const r = await api.get('wa.g_history', F); D = more && D ? Object.assign(r, { chats: D.chats.concat(r.chats) }) : r; } catch (e) { el.innerHTML = errBox(e.message); return; } draw();
    }
    function draw() {
      const res = [['', 'Any result']].concat((D.results || []).map((r) => [r.k, r.i + ' ' + r.t]));
      let h = `<div class="wp">${head('History', 'Chats move here 24 hours after the customer’s last message, or when they are closed. Open one to write a Utility follow-up.', `<a class="wp-btn" href="#" id="hsEx">Download CSV</a>`)}
      <div class="wp-cd"><div class="wp-2"><div><label class="wp-fl">Person</label>${sel('hsE', [['', 'Everyone'], ['-1', 'Nobody (not given)']].concat(pp.map((x) => [x.emp_id, x.name])), F.emp || '')}</div><div><label class="wp-fl">Result</label>${sel('hsR', res, F.result || '')}</div></div>
        <div class="wp-2"><div><label class="wp-fl">Period</label>${sel('hsD', [['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days'], ['365', 'Last year']], F.days || 30)}</div><div><label class="wp-fl">Search</label><input class="wp-in" id="hsQ" placeholder="Name or number" value="${esc(F.q || '')}"></div></div>
        <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-chip ${F.noreply ? 'on' : ''}" id="hsN">Never answered</button><button type="button" class="wp-chip ${F.noresult ? 'on' : ''}" id="hsX">No result noted</button><span class="sp wp-grow"></span><button type="button" class="wp-btn p s" id="hsGo">Show</button></div></div>
      <div class="wp-cd"><h3>${num(D.total)} chat${D.total === 1 ? '' : 's'}</h3>${D.chats.length ? D.chats.map((c) => `<div class="wg-ch2" data-chat="${esc(c.phone)}"><div class="t"><b>${esc(c.name || pretty(c.phone))}</b>${c.unanswered ? ' <span class="wp-pill bad">never answered</span>' : ''}${c.closed ? ' <span class="wp-pill">closed</span>' : ''}${c.result_t ? ` <span class="wp-pill ${c.result === 'order' ? 'ok' : ''}">${esc(c.result_i)} ${esc(c.result_t)}</span>` : ''}<div>${esc(c.preview)}</div><div class="wg-mini">${esc(c.emp_name || 'Nobody')} · ${c.ago_days} d ago${c.num ? ' · ' + esc(c.num) : ''}${(c.tags || []).map((t) => ' · ' + esc(t)).join('')}</div></div></div>`).join('') : '<div class="wp-empty">No chat matches.</div>'}${D.more ? '<div style="text-align:center;margin-top:10px"><button type="button" class="wp-btn" id="hsMore">Show more</button></div>' : ''}</div></div>`;
      el.innerHTML = h;
      el.querySelectorAll('[data-chat]').forEach((b) => { b.onclick = () => ctx.openChat(b.dataset.chat); });
      const rd = () => { F = { days: +$(el, '#hsD').value, page: 0, q: $(el, '#hsQ').value.trim(), emp: $(el, '#hsE').value, result: $(el, '#hsR').value, noreply: F.noreply, noresult: F.noresult }; Object.keys(F).forEach((k) => { if (F[k] === '' || F[k] === false || F[k] == null) delete F[k]; }); };
      $(el, '#hsGo').onclick = () => { rd(); load(); }; $(el, '#hsQ').onkeydown = (e) => { if (e.key === 'Enter') { rd(); load(); } };
      $(el, '#hsN').onclick = () => { rd(); F.noreply = !F.noreply; load(); }; $(el, '#hsX').onclick = () => { rd(); F.noresult = !F.noresult; load(); };
      const mo = $(el, '#hsMore'); if (mo) mo.onclick = () => { F.page = (F.page || 0) + 1; load(true); };
      $(el, '#hsEx').onclick = (e) => { e.preventDefault(); ctx.go('export'); };
    }
    load(); return () => load();
  });

  // ============================================================================================================== Tags & audience
  let TAGCACHE = null;
  async function tagOptions() {
    try { const t = await api.get('wa.g_tags'); TAGCACHE = t; return [['', 'Any tag']].concat((t.tags || []).map((x) => [x.k, x.t + ' (' + x.all + ')'])); } catch (e) { return [['', 'Any tag']]; }
  }
  P('tags', (el, ctx) => {
    let D = null, busy = false; el.innerHTML = loading;
    async function load() { try { D = await api.get('wa.g_tags'); } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    function draw() {
      el.innerHTML = `<div class="wp">${head('Tags & audience', 'Every customer is tagged automatically from what happened (ordered, price talk, silent…). Pick a tag and the period to build a campaign audience.', `<button type="button" class="wp-btn" id="tgCl">Let Claude tag untagged chats</button>`)}
      <div class="wp-hint" id="tgMsg" style="margin-bottom:10px"></div>
      <div class="wp-cd"><h3>${num(D.customers)} customers that can be messaged</h3>${(D.tags || []).length ? `<table class="wp-tb"><thead><tr><th>Tag</th><th class="wg-n">All</th><th class="wg-n">30 d</th><th class="wg-n">60 d</th><th class="wg-n">90 d</th></tr></thead><tbody>${D.tags.map((t) => `<tr><td><b>${esc(t.t)}</b> ${t.kind === 'claude' ? '<span class="wp-pill warn">Claude</span>' : ''}</td><td class="wg-n">${t.all}</td><td class="wg-n">${t.d30}</td><td class="wg-n">${t.d60}</td><td class="wg-n">${t.d90}</td></tr>`).join('')}</tbody></table><div class="wp-hint">“30 d” = customers with this tag who wrote or were messaged in the last 30 days. To send to one of these groups: Campaigns → New campaign → choose the tag.</div>` : '<div class="wp-empty">No tags yet — they appear as chats happen.</div>'}
      <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn p" id="tgCa">Open Campaigns</button></div></div></div>`;
      $(el, '#tgCa').onclick = () => ctx.go('campaigns');
      $(el, '#tgCl').onclick = async (e) => {
        if (busy) return; busy = true; const b = e.currentTarget; b.disabled = true; const msg = $(el, '#tgMsg'); let total = 0;
        try { for (let i = 0; i < 60; i++) { const r = await api.post('wa.g_tag_batch', {}); total += r.tagged; msg.textContent = 'Claude has tagged ' + total + ' chats… ' + r.left + ' left'; if (!r.left || (!r.tagged && i > 1)) break; } toast('Done ✓'); } catch (er) { toast(er.message, true); }
        busy = false; load();
      };
    }
    load(); return load;
  });

  // ============================================================================================================== Ads
  P('ads', (el) => {
    let days = 30; el.innerHTML = loading;
    async function load() {
      let D; try { D = await api.get('wa.g_ads', { days }); } catch (e) { el.innerHTML = errBox(e.message); return; }
      el.innerHTML = `<div class="wp">${head('Ads', 'Which Meta ads bring chats, and how many of those customers ordered afterwards.', `<div class="wg-seg">${[[7, '7 d'], [30, '30 d'], [90, '90 d']].map(([v, l]) => `<button type="button" data-d="${v}" class="${days === v ? 'on' : ''}">${l}</button>`).join('')}</div>`)}
      <div class="wp-cd">${D.ads.length ? `<table class="wp-tb"><thead><tr><th>Ad</th><th class="wg-n">Chats</th><th class="wg-n">Orders</th><th class="wg-n">Rate</th><th class="wg-n">Sales</th></tr></thead><tbody>${D.ads.map((a) => `<tr><td>${esc(a.title || a.key)}</td><td class="wg-n">${a.chats}</td><td class="wg-n">${a.orders}</td><td class="wg-n">${a.rate}%</td><td class="wg-n">${money(a.revenue)}</td></tr>`).join('')}</tbody></table>` : '<div class="wp-empty">No ad chats in this period.</div>'}
      <div class="wp-hint" style="margin-top:8px">Orders are matched by phone number from the Shopify order list, only orders after the first chat. Sending a “Purchase” back to Meta so it learns who buys: <b>not built yet</b>.</div></div></div>`;
      el.querySelectorAll('[data-d]').forEach((b) => { b.onclick = () => { days = +b.dataset.d; load(); }; });
    }
    load(); return load;
  });

  // ============================================================================================================== Export
  P('export', (el) => {
    el.innerHTML = loading;
    async function load() {
      let L = { log: [] }, pp = []; try { L = await api.get('wa.g_exports'); } catch (e) { } pp = await people();
      let R = []; try { R = (await api.get('wa.g_history', { days: 1 })).results || []; } catch (e) { }
      el.innerHTML = `<div class="wp">${head('Export', 'Download chats or messages as a CSV file. Every download is written in the log below.')}
      <div class="wp-cd"><label class="wp-fl" style="margin-top:0">What</label>${sel('exK', [['chats', 'One row per customer'], ['messages', 'Every message']], 'chats')}
        <div class="wp-2"><div><label class="wp-fl">From</label><input class="wp-in" id="exF" type="date"></div><div><label class="wp-fl">To</label><input class="wp-in" id="exT" type="date"></div></div>
        <div class="wp-2"><div><label class="wp-fl">Person</label>${sel('exE', [['', 'Everyone']].concat(pp.map((x) => [x.emp_id, x.name])), '')}</div><div><label class="wp-fl">Result</label>${sel('exR', [['', 'Any result']].concat(R.map((r) => [r.k, r.t])), '')}</div></div>
        <label class="wp-fl">Tag <small>(optional, e.g. ordered)</small></label><input class="wp-in" id="exG" placeholder="tag key">
        <div class="wp-row" style="margin-top:14px"><button type="button" class="wp-btn p" id="exGo">Download CSV</button></div><div class="wp-hint" id="exM"></div></div>
      <div class="wp-cd"><h3>Download log</h3>${L.log.length ? `<table class="wp-tb"><thead><tr><th>When</th><th>Who</th><th>What</th><th class="wg-n">Rows</th></tr></thead><tbody>${L.log.map((x) => `<tr><td>${esc(when(x.at))}</td><td>${esc(x.by)}</td><td>${esc(x.kind)}</td><td class="wg-n">${x.rows}</td></tr>`).join('')}</tbody></table>` : '<div class="wp-empty">Nothing downloaded yet.</div>'}</div></div>`;
      $(el, '#exGo').onclick = async (e) => {
        const b = e.currentTarget; b.disabled = true; $(el, '#exM').textContent = 'Preparing…';
        const q = { kind: $(el, '#exK').value, from: $(el, '#exF').value, to: $(el, '#exT').value, emp: $(el, '#exE').value, result: $(el, '#exR').value, tag: $(el, '#exG').value.trim() }; Object.keys(q).forEach((k) => { if (!q[k]) delete q[k]; });
        try {
          const r = await fetch(api.buildUrl('wa.g_export', q), { headers: { Authorization: 'Bearer ' + api.token() } });
          if (!r.ok) { let m = 'Download failed.'; try { m = (await r.json()).error || m; } catch (x) { } throw new Error(m); }
          const bl = await r.blob(), a = document.createElement('a'); a.href = URL.createObjectURL(bl); a.download = 'whatsapp_' + q.kind + '_' + new Date().toISOString().slice(0, 10) + '.csv'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000);
          $(el, '#exM').textContent = 'Downloaded ✓'; load();
        } catch (er) { $(el, '#exM').textContent = ''; toast(er.message, true); b.disabled = false; }
      };
    }
    load(); return load;
  });

  // ============================================================================================================== Claude button (who gets it)
  P('hq', (el) => {
    el.innerHTML = loading;
    async function load() {
      let D; try { D = await api.get('wa.g_hq_get'); } catch (e) { el.innerHTML = errBox(e.message); return; }
      el.innerHTML = `<div class="wp">${head('Claude button', 'The floating Claude button is OFF for everyone. Switch it on only for the people who should have it.')}
      <div class="wp-cd">${D.people.map((p) => `<div class="wp-row" style="padding:8px 0;border-top:1px solid #edf1ee"><div class="wp-grow"><b>${esc(p.name)}</b></div>${sw('hq' + p.emp_id, p.on)}</div>`).join('')}<div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn p" id="hqS">Save</button></div><div class="wp-hint">A person sees the change the next time they open or refresh any page (it is remembered for 10 minutes).</div></div></div>`;
      $(el, '#hqS').onclick = async (e) => { e.currentTarget.disabled = true; const ids = D.people.filter((p) => $(el, '#hq' + p.emp_id).checked).map((p) => p.emp_id); try { await api.post('wa.g_hq_set', { emp_ids: ids }); toast('Saved ✓ — ' + ids.length + ' person' + (ids.length === 1 ? '' : 's')); } catch (er) { toast(er.message, true); } e.currentTarget.disabled = false; };
    }
    load(); return load;
  });


  // ============================================================================================================== Go-live check
  P('golive', (el, ctx) => {
    let D = null; el.innerHTML = loading;
    const ST = { ok: ['#12794a', '#dcf7e6', '✓'], warn: ['#8a6a00', '#fff3cd', '!'], bad: ['#b42318', '#fde6e6', '✕'] };
    async function load() { try { D = await api.get('wa.g_golive'); } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    function draw() {
      const sc = D.score, col = D.ready ? '#12794a' : (D.bad > 3 ? '#b42318' : '#b7791f');
      const verdict = D.ready ? 'Ready to go live' : D.bad + ' thing' + (D.bad > 1 ? 's' : '') + ' to fix before going live';
      el.innerHTML = `<div class="wp">${head('Go-live check', 'Every line is measured live from your system — nothing is typed in. Fix the red ones, then press Refresh.', '<button type="button" class="wp-btn" id="glR">Refresh</button>')}
      <div class="wp-cd" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap"><div style="width:86px;height:86px;border-radius:50%;background:conic-gradient(${col} ${sc * 3.6}deg,#e8ecea 0);display:flex;align-items:center;justify-content:center;flex:none"><div style="width:66px;height:66px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;color:${col}">${sc}%</div></div>
        <div style="flex:1;min-width:180px"><div style="font-size:18px;font-weight:800;color:${col}">${esc(verdict)}</div><div class="wp-hint">${D.ok} fine · ${D.warn} to watch · ${D.bad} to fix. Checked ${esc(when(D.at) || 'just now')}.</div></div></div>
      ${D.checks.map((c) => { const t = ST[c.state]; return `<div class="wp-cd" style="border-left:5px solid ${t[0]}"><div style="display:flex;gap:10px;align-items:flex-start"><span style="width:26px;height:26px;border-radius:50%;background:${t[1]};color:${t[0]};font-weight:800;display:flex;align-items:center;justify-content:center;flex:none">${t[2]}</span><div style="flex:1;min-width:0"><b style="font-size:14.5px">${esc(c.title)}</b><div style="font-size:13px;margin-top:3px;color:#33423a">${esc(c.now)}</div>${c.state !== 'ok' && c.fix ? `<div class="wp-hint" style="margin-top:6px"><b>What to do:</b> ${esc(c.fix)}</div>` : ''}
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">${c.k === 'backup' ? '<button type="button" class="wp-btn s" id="glB">I took the backup</button>' : ''}${c.state !== 'ok' && c.go && c.go !== 'golive' ? `<button type="button" class="wp-btn s" data-go="${esc(c.go)}">Open ›</button>` : ''}</div></div></div></div>`; }).join('')}
      <div class="wp-cd"><h3>Staff phones</h3>${D.people.length ? D.people.map((p) => `<div class="wg-ch2" style="cursor:default"><div class="t"><b>${esc(p.name)}</b> ${p.present ? '<span class="wp-pill ok">here</span>' : '<span class="wp-pill">away</span>'}<div style="white-space:normal">${p.tested ? '✅ Phone check ' + p.pass + '/' + p.of + ' · ' + esc(p.device) + (p.standalone ? ' · installed' : '') + ' · ' + esc(ago(p.tested_at) || '') + ' ago' : '❌ Has not run “Check my phone”'}<br>${p.push ? '🔔 Alerts on' : '🔕 Alerts off'} · ${p.guide ? '📖 Read the guide' : '📕 Guide not opened'}</div></div><button type="button" class="wp-btn s" data-buzz="${p.emp_id}">Send test buzz</button></div>`).join('') : '<div class="wp-empty">No salespeople found.</div>'}
        <div class="wp-hint" style="margin-top:8px">Real-phone test: each person opens <b>My Chats</b> on their own phone → <b>Me</b> → <b>Check my phone</b>. It takes 2 minutes and shows here.</div></div></div>`;
      $(el, '#glR').onclick = (e) => { e.currentTarget.disabled = true; load(); };
      const b = $(el, '#glB'); if (b) b.onclick = async () => { b.disabled = true; try { await api.post('wa.g_backup_mark', {}); toast('Backup noted ✓'); load(); } catch (e) { toast(e.message, true); b.disabled = false; } };
      el.querySelectorAll('[data-go]').forEach((x) => { x.onclick = () => ctx.go(x.dataset.go); });
      el.querySelectorAll('[data-buzz]').forEach((x) => { x.onclick = async () => { x.disabled = true; try { const r = await api.post('wa.g_push_test', { emp_id: +x.dataset.buzz }); toast(r.note || 'Done', !r.ok); } catch (e) { toast(e.message, true); } x.disabled = false; }; });
    }
    load(); return load;
  });

  // ============================================================================================================== Fixed messages
  // ✉️ Fixed messages and the builder are one screen: build on top, the approved message of each situation below
  P('fixedb', (el, ctx) => { const a = document.createElement('div'), b = document.createElement('div'); b.style.marginTop = '14px'; el.appendChild(a); el.appendChild(b); WaPanels.mount('builder', a, ctx); return WaPanels.mount('fixed', b, ctx); });
  P('fixed', (el, ctx) => {
    let D = null; el.innerHTML = loading;
    async function load() { try { D = await api.get('wa.g_lib'); } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    const pick = (k) => { const s = (D.sets || []).filter((x) => x.purpose === k); return s.find((x) => x.status === 'approved') || s.find((x) => x.status === 'draft') || s[0] || null; };
    function draw() {
      el.innerHTML = `<div class="wp">${head('Fixed messages', 'One approved message for each situation. Salespeople send these with one tap; the words you approve here keep Meta’s category right and the cost low.')}
      ${D.purposes.map((p) => { const s = pick(p.k); return `<div class="wp-cd"><h3>${esc(p.t)} <span class="wp-pill ${p.cat === 'UTILITY' ? 'ok' : 'warn'}">${p.cat === 'UTILITY' ? 'Utility' : 'Marketing'}</span><span class="sp"></span>${s ? `<span class="wp-pill ${s.status === 'approved' ? 'ok' : s.status === 'retired' ? '' : 'warn'}">${esc(s.status)}</span>` : ''}</h3><div class="wp-hint" style="margin:0 0 6px">${esc(p.when)}</div>${s && s.body ? `<div class="wp-bub">${esc(s.body)}</div>` : '<div class="wp-hint">Not written yet.</div>'}<div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s ${s ? '' : 'p'}" data-p="${esc(p.k)}">${s ? 'Edit' : 'Write it'}</button></div></div>`; }).join('')}</div>`;
      el.querySelectorAll('[data-p]').forEach((b) => { b.onclick = () => edit(b.dataset.p); });
    }
    function edit(k) {
      const p = D.purposes.find((x) => x.k === k), s = pick(k) || { id: 0, purpose: k, title: p.t, body: '', use: [], avoid: [], category: p.cat, tpl_id: 0, status: 'draft' };
      const m = modal(p.t); const L = (a) => (a || []).join(', ');
      m.body.innerHTML = `<div class="wp"><div class="wp-hint" style="margin:0">${esc(p.when)}</div><label class="wp-fl">Message the salesperson sends</label><textarea class="wp-in" id="fxB" rows="6" maxlength="1024">${esc(s.body)}</textarea><div class="wp-hint">Use {name} for the customer’s first name.</div>
        <div class="wp-2"><div><label class="wp-fl">Category</label>${sel('fxC', [['UTILITY', 'Utility (cheap)'], ['MARKETING', 'Marketing']], s.category)}</div><div><label class="wp-fl">Title</label><input class="wp-in" id="fxT" value="${esc(s.title)}"></div></div>
        <label class="wp-fl">Words that should be in it <small>(comma separated)</small></label><input class="wp-in" id="fxU" value="${esc(L(s.use))}"><label class="wp-fl">Words to keep out <small>(they make Meta call it Marketing)</small></label><input class="wp-in" id="fxA" value="${esc(L(s.avoid))}">
        <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn" id="fxW">✨ Claude: suggest words</button><button type="button" class="wp-btn" id="fxK">Check my text</button></div><div id="fxN" class="wp-hint"></div>
        <div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn p" id="fxAp">Save &amp; approve</button><button type="button" class="wp-btn" id="fxS">Save as draft</button>${s.id && s.status === 'approved' ? '<button type="button" class="wp-btn r" id="fxR">Retire</button>' : ''}</div><div id="fxE"></div></div>`;
      const words = (v) => v.split(',').map((x) => x.trim()).filter(Boolean);
      const body = () => ({ id: s.id, purpose: k, title: $(m.body, '#fxT').value, body: $(m.body, '#fxB').value, category: $(m.body, '#fxC').value, use: words($(m.body, '#fxU').value), avoid: words($(m.body, '#fxA').value), tpl_id: s.tpl_id });
      $(m.body, '#fxW').onclick = async (e) => {
        const b = e.currentTarget; b.disabled = true; b.textContent = 'Thinking…';
        try { const r = await api.post('wa.g_lib_words', { purpose: k, note: $(m.body, '#fxB').value.slice(0, 300) }); $(m.body, '#fxU').value = L(r.use); $(m.body, '#fxA').value = L(r.avoid); $(m.body, '#fxN').innerHTML = esc(r.why || '') + (r.ai ? '' : ' (standard words — the AI is not connected)') + (r.example ? `<div class="wp-bub">${esc(r.example)}</div><button type="button" class="wp-btn s" id="fxEx">Use this example text</button>` : ''); const ex = $(m.body, '#fxEx'); if (ex) ex.onclick = () => { $(m.body, '#fxB').value = r.example; }; }
        catch (er) { toast(er.message, true); } b.disabled = false; b.textContent = '✨ Claude: suggest words';
      };
      $(m.body, '#fxK').onclick = async () => {
        try { await api.post('wa.g_lib_save', body()); } catch (er) { toast(er.message, true); return; }
        try { const r = await api.post('wa.g_lib_check', { purpose: k, text: $(m.body, '#fxB').value }); $(m.body, '#fxN').innerHTML = !r.approved ? 'Approve the words first (save &amp; approve), then the check works.' : (r.missing.length || r.bad.length ? (r.missing.length ? '<b>Missing:</b> ' + esc(r.missing.join(', ')) + '. ' : '') + (r.bad.length ? '<b style="color:#b42318">Remove:</b> ' + esc(r.bad.join(', ')) : '') : '<b style="color:#12794a">Looks right ✓</b>'); } catch (er) { toast(er.message, true); }
      };
      const save = async (approve) => {
        try { const r = await api.post('wa.g_lib_save', body()); if (approve) await api.post('wa.g_lib_status', { id: r.id, status: 'approved' }); toast(approve ? 'Approved ✓' : 'Saved ✓'); m.close(); load(); } catch (er) { $(m.body, '#fxE').innerHTML = errBox(er.message); }
      };
      $(m.body, '#fxAp').onclick = () => save(true); $(m.body, '#fxS').onclick = () => save(false);
      const rt = $(m.body, '#fxR'); if (rt) rt.onclick = async () => { await api.post('wa.g_lib_status', { id: s.id, status: 'retired' }).catch((e) => toast(e.message, true)); m.close(); load(); };
    }
    load(); return load;
  });

  // ============================================================================================================== Message builder
  P('builder', (el, ctx) => {
    el.innerHTML = loading; let pur = [];
    (async () => { try { pur = (await api.get('wa.g_lib')).purposes; } catch (e) { } draw(); })();
    function draw() {
      el.innerHTML = `<div class="wp">${head('Message builder', 'Say what you want to send. Claude writes versions — the cheap Utility one first — and tells you honestly if Meta will call it Marketing.')}
      <div class="wp-cd"><label class="wp-fl" style="margin-top:0">What do you want to send?</label><textarea class="wp-in" id="mbW" rows="4" placeholder="e.g. Tell customers whose COD order is not confirmed to confirm it today"></textarea>
      <label class="wp-fl">Kind of message <small>(optional — uses your approved words)</small></label>${sel('mbP', [['', 'Not sure']].concat(pur.map((p) => [p.k, p.t])), '')}
      <div class="wp-row" style="margin-top:12px"><button type="button" class="wp-btn p" id="mbGo">Write it</button></div></div><div id="mbR"></div></div>`;
      $(el, '#mbGo').onclick = async (e) => {
        const w = $(el, '#mbW').value.trim(); if (!w) return toast('Write what you want to send.', true);
        const b = e.currentTarget; b.disabled = true; b.textContent = 'Writing…'; const R = $(el, '#mbR'); R.innerHTML = loading;
        try { show(await api.post('wa.g_build', { want: w, purpose: $(el, '#mbP').value })); } catch (er) { R.innerHTML = errBox(er.message); } b.disabled = false; b.textContent = 'Write it';
      };
    }
    function show(r) {
      const R = $(el, '#mbR');
      R.innerHTML = `<div class="wp-cd"><div class="wp-und"><small>Claude understood</small>${esc(r.understood)}</div>${r.utility_possible ? '' : `<div class="wp-und" style="background:#fff7e6;border-color:#f3d9a0"><small style="color:#8a6a00">Honest note</small>${esc(r.utility_note || 'This goal is a Marketing message — Meta will not accept it as Utility.')}</div>`}</div>
      ${(r.variants || []).map((v, i) => `<div class="wp-cd"><h3>${esc(v.label)} <span class="wp-pill ${v.category === 'UTILITY' ? 'ok' : 'warn'}">${v.category === 'UTILITY' ? 'Utility' : 'Marketing'}</span><span class="sp"></span><span class="wg-mini">≈ ₹${Number(v.rate).toFixed(2)} per message</span></h3><div class="wg-mini">${esc(v.why)}</div>${v.draft.header_text ? `<div class="wp-bub"><b>${esc(v.draft.header_text)}</b></div>` : ''}<div class="wp-bub">${esc(v.draft.body)}</div>${v.draft.footer ? `<div class="wg-mini">${esc(v.draft.footer)}</div>` : ''}${(v.draft.buttons || []).length ? `<div class="wp-btns">${v.draft.buttons.map((b) => `<span>${esc(b.text || b)}</span>`).join('')}</div>` : ''}${v.check && v.check.approved && (v.check.missing.length || v.check.bad.length) ? `<div class="wp-hint" style="color:#b42318">Words check: ${v.check.missing.length ? 'missing ' + esc(v.check.missing.join(', ')) + '. ' : ''}${v.check.bad.length ? 'remove ' + esc(v.check.bad.join(', ')) : ''}</div>` : ''}${v.problem ? `<div class="wp-err">${esc(v.problem)}</div>` : `<div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn p s" data-v="${i}">Send to Meta for approval</button></div>`}</div>`).join('')}
      <div class="wp-cd"><h3>Cheaper ways</h3>${(r.routes || []).map((x) => `<div class="wg-it tip"><span class="dot"></span><div class="tx">${esc(x)}</div></div>`).join('')}</div>`;
      R.querySelectorAll('[data-v]').forEach((b) => {
        b.onclick = async () => {
          const v = r.variants[+b.dataset.v]; b.disabled = true; b.textContent = 'Sending…';
          try { await api.post('wa.tpl_submit', { draft: v.draft }); toast('Sent to Meta ✓ — see Pre-made messages'); b.textContent = 'Sent ✓'; } catch (e) { toast(e.message, true); b.disabled = false; b.textContent = 'Send to Meta for approval'; }
        };
      });
    }
    return () => { };
  });

  // ============================================================================================================== Gallery & storage
  const thumbs = {};
  function thumb(id, node) {
    if (thumbs[id]) { node.style.backgroundImage = 'url(' + thumbs[id] + ')'; node.textContent = ''; return; }
    fetch(api.buildUrl('wa.g_thumb', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }).then((r) => (r.ok ? r.blob() : Promise.reject())).then((b) => { thumbs[id] = URL.createObjectURL(b); node.style.backgroundImage = 'url(' + thumbs[id] + ')'; node.textContent = ''; }).catch(() => { });
  }
  P('gallery', (el, ctx) => {
    let G = null, folder = '', C = null, hide = {}; el.innerHTML = loading;
    async function load() { try { G = (await api.get('wa.g_gallery')).gallery; } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    const FL = { packaging: 'Packaging', bottles: 'Bottles', sizes: 'Sizes', attars: 'Attars', gel: 'Gel', offers: 'Offers', proof: 'Proof', reviews: 'Reviews', videos: 'Videos', prices: 'Price list', gifts: 'Gifts', store: 'Store', other: 'Other' };
    function draw() {
      const items = G.items.filter((x) => !folder || x.folder === folder);
      const cl = C ? C.suggest.filter((x) => !hide[x.id]) : null;
      el.innerHTML = `<div class="wp">${head('Gallery & storage', 'Photos and videos the salespeople send, in topic folders. Everything already uploaded is here — nothing is lost.', `<button type="button" class="wp-btn" id="gcC">✨ Claude: what can go?</button><button type="button" class="wp-btn p" id="gcU">Add a file</button>`)}
      ${cl ? `<div class="wp-cd"><h3>Claude suggests removing ${cl.length}</h3>${cl.length ? cl.map((x) => `<div class="wg-ch2" style="cursor:default"><div class="t"><b>${esc(x.title)}</b><div>${esc(x.why)}</div></div><button type="button" class="wp-btn s r" data-rm="${x.id}">Remove</button><button type="button" class="wp-btn s" data-keep="${x.id}">Keep</button></div>`).join('') : '<div class="wp-empty">Nothing to clean up. 👍</div>'}<div class="wp-hint">Only you can remove files. Claude only suggests.</div></div>` : ''}
      <div class="wp-row" style="margin-bottom:10px"><button type="button" class="wp-chip ${folder ? '' : 'on'}" data-f="">All (${G.items.length})</button>${G.folders.map((f) => `<button type="button" class="wp-chip ${folder === f.k ? 'on' : ''}" data-f="${esc(f.k)}">${esc(f.t)} (${f.n})</button>`).join('')}</div>
      <div class="wg-gal">${items.map((x) => `<div class="wg-gi"><div class="wg-th" data-th="${x.id}">${x.kind === 'image' ? '' : x.kind === 'video' ? '🎬' : '📄'}</div><b title="${esc(x.title)}">${esc(x.title)}</b><span class="wg-mini">sent ${x.sent}×</span>${sel('fo' + x.id, Object.keys(FL).map((k) => [k, FL[k]]), x.folder)}<button type="button" class="wp-btn s r" style="margin-top:4px;width:100%" data-del="${x.id}">Delete</button></div>`).join('') || '<div class="wp-empty">Nothing in this folder.</div>'}</div></div>`;
      el.querySelectorAll('[data-th]').forEach((n) => { const it = G.items.find((x) => x.id === +n.dataset.th); if (it && it.kind === 'image') thumb(it.id, n); });
      el.querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { folder = b.dataset.f; draw(); }; });
      el.querySelectorAll('select[id^="fo"]').forEach((s) => { s.style.minHeight = '34px'; s.onchange = async () => { try { await api.post('wa.g_gallery_folder', { id: +s.id.slice(2), folder: s.value }); toast('Moved ✓'); load(); } catch (e) { toast(e.message, true); } }; });
      el.querySelectorAll('[data-del]').forEach((b) => { b.onclick = () => del(+b.dataset.del); });
      el.querySelectorAll('[data-rm]').forEach((b) => { b.onclick = () => del(+b.dataset.rm); });
      el.querySelectorAll('[data-keep]').forEach((b) => { b.onclick = () => { hide[b.dataset.keep] = 1; draw(); }; });
      $(el, '#gcC').onclick = async (e) => { const b = e.currentTarget; b.disabled = true; b.textContent = 'Looking…'; try { C = await api.get('wa.g_gallery_clean'); hide = {}; } catch (er) { toast(er.message, true); } draw(); };
      $(el, '#gcU').onclick = add;
    }
    async function del(id) {
      const it = G.items.find((x) => x.id === id); if (!confirm('Delete “' + (it ? it.title : 'this file') + '” for good?')) return;
      try { await api.post('wa.lib_del', { id }); toast('Deleted ✓'); if (C) C.suggest = C.suggest.filter((x) => x.id !== id); load(); } catch (e) { toast(e.message, true); }
    }
    function add() {
      const m = modal('Add a file');
      m.body.innerHTML = `<div class="wp"><label class="wp-fl" style="margin-top:0">File <small>(photo, video or PDF)</small></label><input type="file" id="gaF" accept="image/*,video/*,application/pdf"><label class="wp-fl">Name</label><input class="wp-in" id="gaT" placeholder="e.g. 100ml bottle front"><label class="wp-fl">Folder</label>${sel('gaO', Object.keys(FL).map((k) => [k, FL[k]]), 'bottles')}<div class="wp-row" style="margin-top:14px"><button type="button" class="wp-btn p" id="gaGo">Upload</button></div><div id="gaE"></div></div>`;
      $(m.body, '#gaGo').onclick = async (e) => {
        const f = $(m.body, '#gaF').files[0]; if (!f) return toast('Choose a file.', true); const b = e.currentTarget; b.disabled = true; b.textContent = 'Uploading…';
        const fd = new FormData(); fd.append('file', f); fd.append('title', $(m.body, '#gaT').value.trim());
        try { const r = await upload('wa.lib_add', fd, (p) => { b.textContent = 'Uploading ' + Math.round(p * 100) + '%'; }); await api.post('wa.g_gallery_folder', { id: r.id, folder: $(m.body, '#gaO').value }); toast('Added ✓'); m.close(); load(); } catch (er) { $(m.body, '#gaE').innerHTML = errBox(er.message); b.disabled = false; b.textContent = 'Upload'; }
      };
    }
    load(); return load;
  });

  // ============================================================================================================== Flows
  P('flows', (el, ctx) => {
    let D = null; el.innerHTML = loading;
    const TR = () => D.triggers, TYPES = () => D.types;
    const ICON = { text: '✉️', media: '🖼', folder: '🖼', buttons: '💬', list: '📋', media_buttons: '🖼', ask_location: '📍', ask_address: '🏠', tpl: '📋', wait: '⏱', wait_reply: '⏳', branch: '🔀', tag: '🏷', assign: '👤', notify: '🔔', goto_flow: '➡️', stop: '⏹' };
    const OLD_TRIG = ['new_chat', 'ad_click', 'keyword', 'button', 'tag', 'no_reply'];
    const QUICK = [['text', '＋ Text'], ['media', '＋ Photo/PDF'], ['media_buttons', '＋ Photo + buttons (new)'], ['buttons', '＋ Buttons'], ['list', '＋ List (new)'], ['ask_address', '＋ Ask address (new)'], ['ask_location', '＋ Ask location (new)'], ['goto_flow', '＋ Go to another flow (new)']];
    const DEFAULT_STEP = { text: { text: '' }, list: { header: '', text: '', button: 'Choose', rows: [{ title: '', description: '' }, { title: '', description: '' }, { title: '', description: '' }] }, ask_location: { text: 'Please share your location 📍 so we can help you faster.', timeout: 1440 }, ask_address: { text: 'Please send your delivery address 🏠', timeout: 1440 }, media_buttons: { text: '', buttons: [] }, buttons: { text: '', buttons: [] } };
    async function load() { try { D = await api.get('wa.g_flows'); } catch (e) { el.innerHTML = errBox(e.message); return; } draw(); }
    const tsum = (f) => f.trig === 'camp_kw' ? 'Reply to the campaign “' + (f.camp_name || '?') + '” (got it in the last ' + f.camp_days + ' day' + (f.camp_days === 1 ? '' : 's') + ') — ' + f.trig_val : TR()[f.trig] + (f.trig_val ? ' — ' + f.trig_val + (f.trig === 'no_reply' ? ' min' : '') : '');
    function draw() {
      el.innerHTML = `<div class="wp">${head('Flows', 'A flow sends the first message; if the customer answers, the next; and so on. Switch a flow on only after you have tried it.', '<button type="button" class="wp-btn p" id="flN">New flow</button>')}
      ${D.flows.length ? D.flows.map((f) => { const r = f.runs || {}; const tot = Object.values(r).reduce((a, b) => a + b, 0); return `<div class="wp-cd"><h3>${esc(f.name)} <span class="sp"></span>${sw('fa' + f.id, f.active)}</h3><div class="wg-mini">${esc(tsum(f))} · ${f.steps.length} steps</div><div class="wp-row" style="margin-top:6px"><span class="wp-pill">${tot} started</span><span class="wp-pill ok">${r.done || 0} finished</span><span class="wp-pill warn">${(r.wait || 0) + (r.run || 0) + (r.ready || 0)} running</span>${r.blocked ? `<span class="wp-pill bad">${r.blocked} blocked</span>` : ''}<span class="wp-pill ok">${f.ordered} ordered after</span></div><div class="wp-row" style="margin-top:8px"><button type="button" class="wp-btn s" data-e="${f.id}">Open</button><button type="button" class="wp-btn s r" data-d="${f.id}">Delete</button></div></div>`; }).join('') : '<div class="wp-empty">No flow yet. Press “New flow”.</div>'}</div>`;
      $(el, '#flN').onclick = () => edit(null);
      el.querySelectorAll('[data-e]').forEach((b) => { b.onclick = () => edit(D.flows.find((f) => f.id === +b.dataset.e)); });
      el.querySelectorAll('[data-d]').forEach((b) => { b.onclick = async () => { if (!confirm('Delete this flow? Customers in the middle of it stop.')) return; try { await api.post('wa.g_flow_del', { id: +b.dataset.d }); load(); } catch (e) { toast(e.message, true); } }; });
      el.querySelectorAll('input[id^="fa"]').forEach((c) => { c.onchange = async () => { try { await api.post('wa.g_flow_toggle', { id: +c.id.slice(2), on: c.checked }); toast(c.checked ? 'Flow is ON' : 'Flow is off'); load(); } catch (e) { toast(e.message, true); c.checked = !c.checked; } }; });
    }
    function edit(f) {
      const m = modal(f ? 'Flow' : 'New flow'); m.el.querySelector('.wp-mb').classList.add('wfx-mb');
      let id = f ? f.id : 0, name = f ? f.name : '', trig = f ? f.trig : 'new_chat', tv = f ? f.trig_val : '', campId = f ? f.camp_id || 0 : 0, campDays = f ? f.camp_days || 3 : 3;
      let lastAny = f && f.trig !== 'camp_kw' ? f.trig : 'new_chat'; const active = !!(f && f.active);
      if (!f) { trig = 'camp_kw'; }   // the usual new flow answers a campaign
      let S = f ? JSON.parse(JSON.stringify(f.steps)) : [{ id: 's1', type: 'text', text: 'Hi {name}! Thanks for writing to us 🙏 What are you looking for?' }];
      const by = (f && f.by_step) || {}; const l7 = (f && f.last7) || null; const opened = new Set(f && S.length > 3 ? [] : S.map((s) => s.id));
      const nid = () => { let i = 1; while (S.some((s) => s.id === 's' + i)) i++; return 's' + i; };
      const lab = (s) => '#' + (S.indexOf(s) + 1) + ' ' + (TYPES()[s.type] || s.type);
      const jopts = (self) => [['', '→ the next step']].concat(S.filter((x) => x !== self).map((x) => [x.id, 'go to ' + lab(x)]));
      const cut = (t, n) => { t = String(t || '').replace(/\s+/g, ' ').trim(); return t.length > n ? t.slice(0, n - 1) + '…' : t; };
      const lib = (id2) => D.library.find((x) => +x.id === +id2);
      const sum = (s) => {
        switch (s.type) {
          case 'text': return cut(s.text, 60);
          case 'media': { const l = lib(s.lib_id); return l ? l.title : ''; }
          case 'folder': return 'Folder “' + (D.folders[s.folder] || s.folder || '') + '” · ' + (s.count || 3) + ' photo' + ((s.count || 3) === 1 ? '' : 's');
          case 'buttons': return '“' + cut(s.text, 30) + '” ' + (s.buttons || []).filter(Boolean).join(' · ');
          case 'list': return '“' + cut(s.text, 30) + '” · ' + (s.rows || []).filter((r) => r.title).length + ' items';
          case 'media_buttons': { const l = lib(s.lib_id); return (l ? l.title + ' · ' : '') + '“' + cut(s.text, 24) + '” ' + (s.buttons || []).filter(Boolean).join(' · '); }
          case 'ask_location': case 'ask_address': return cut(s.text, 60);
          case 'goto_flow': { const g = D.flows.find((x) => x.id === +s.flow_id); return g ? '“' + g.name + '”' : 'choose the flow'; }
          case 'tpl': { const t = D.templates.find((x) => +x.id === +s.tpl_id); return t ? t.name.replace(/_/g, ' ') : ''; }
          case 'wait': return (s.minutes || 60) + ' minutes';
          case 'wait_reply': return 'up to ' + (s.timeout || 60) + ' minutes';
          case 'branch': return { kw: 'word', tag: 'tag', ordered: 'ordered' }[s.cond || 'kw'] + (s.val ? ' “' + s.val + '”' : '');
          case 'tag': return s.tag ? '“' + s.tag + '”' : '';
          case 'assign': { const p = D.people.find((x) => +x.emp_id === +s.emp_id); return p ? p.name : 'the next free person'; }
          case 'notify': { const p = D.people.find((x) => +x.emp_id === +s.emp_id); return (p ? p.name + ': ' : '') + cut(s.text, 40); }
          default: return '';
        }
      };
      const tmo = (s, ph) => `<div class="wp-row" style="margin-top:6px"><span class="wg-mini">Wait up to</span><input class="wp-in" data-f="timeout" data-n="1" type="number" min="1" max="10080" placeholder="${ph || '10080'}" value="${s.timeout || ''}" style="width:100px"><span class="wg-mini">minutes for the answer (empty = 7 days)</span></div>`;
      const titles = (s) => s.type === 'list' ? (s.rows || []).map((r) => r.title).filter(Boolean) : (s.buttons || []).filter(Boolean);
      function jumpsHtml(s, i) {
        const jsel = (key) => sel('', jopts(s), (s.on || {})[key] || '', '').replace('<select ', '<select data-on="' + esc(key) + '" ');
        let j = '';
        if (['buttons', 'list', 'media_buttons'].includes(s.type)) j = titles(s).map((t) => `<div class="wg-jump">If they ${s.type === 'list' ? 'pick' : 'tap'} “${esc(t)}” ${jsel('btn:' + t)}</div>`).join('') + `<div class="wg-jump">If they write instead ${jsel('reply')}</div>` + (s.type !== 'buttons' ? `<div class="wg-jump">If they do not answer ${jsel('timeout')}</div>` : '');
        if (s.type === 'ask_location') j = `<div class="wg-jump">When they share the location ${jsel('loc')}</div><div class="wg-jump">If they write instead ${jsel('reply')}</div><div class="wg-jump">If they do not answer ${jsel('timeout')}</div>`;
        if (s.type === 'ask_address') j = `<div class="wg-jump">When they send the address ${jsel('addr')}</div><div class="wg-jump">If they write instead ${jsel('reply')}</div><div class="wg-jump">If they do not answer ${jsel('timeout')}</div>`;
        if (s.type === 'wait_reply') j = `<div class="wg-jump">When they answer ${jsel('reply')}</div><div class="wg-jump">If they do not answer ${jsel('timeout')}</div>`;
        if (!['stop', 'branch', 'buttons', 'list', 'media_buttons', 'ask_location', 'ask_address', 'wait_reply', 'goto_flow'].includes(s.type) && i < S.length - 1) j = `<div class="wg-jump">After this ${sel('', jopts(s), s.next || '', '').replace('<select ', '<select data-f="next" ')}</div>`;
        return j;
      }
      function stepBody(s, i) {
        const ta = (k, ph, max) => `<textarea class="wp-in" data-f="${k}" rows="3" ${max ? 'maxlength="' + max + '"' : ''} placeholder="${esc(ph || '')}">${esc(s[k] || '')}</textarea>`;
        const btnIn = () => [0, 1, 2].map((j) => `<input class="wp-in" data-bt="${j}" maxlength="20" placeholder="Button ${j + 1} (max 20 letters)" value="${esc((s.buttons || [])[j] || '')}" style="margin-top:6px">`).join('');
        let b = '';
        switch (s.type) {
          case 'text': b = ta('text', 'Message — {name} = first name'); break;
          case 'media': b = sel('', [['0', '— choose a file —']].concat(D.library.map((x) => [x.id, x.title + ' (' + x.kind + ')'])), s.lib_id || 0, '').replace('<select ', '<select data-f="lib_id" data-n="1" ') + `<input class="wp-in" data-f="caption" placeholder="Caption (optional)" value="${esc(s.caption || '')}" style="margin-top:6px">`; break;
          case 'folder': b = `<div class="wp-2">${sel('', Object.keys(D.folders).map((k) => [k, D.folders[k]]), s.folder || 'bottles', '').replace('<select ', '<select data-f="folder" ')}${sel('', [[1, '1 photo'], [2, '2 photos'], [3, '3 photos']], s.count || 3, '').replace('<select ', '<select data-f="count" data-n="1" ')}</div><input class="wp-in" data-f="caption" placeholder="Caption (optional)" value="${esc(s.caption || '')}" style="margin-top:6px">`; break;
          case 'buttons': b = ta('text', 'Question') + btnIn(); break;
          case 'media_buttons': b = sel('', [['0', '— choose a photo or video —']].concat(D.library.filter((x) => x.kind === 'image' || x.kind === 'video').map((x) => [x.id, x.title + ' (' + x.kind + ')'])), s.lib_id || 0, '').replace('<select ', '<select data-f="lib_id" data-n="1" ') + `<div style="margin-top:6px">${ta('text', 'Message under the photo (max 1024 letters)', 1024)}</div>` + btnIn() + tmo(s) + '<div class="wg-mini" style="margin-top:4px">One WhatsApp message: the photo / video on top, up to 3 buttons below.</div>'; break;
          case 'list': b = `<input class="wp-in" data-f="header" maxlength="60" placeholder="Title on top (optional, max 60 letters)" value="${esc(s.header || '')}">` + `<div style="margin-top:6px">${ta('text', 'Message (max 1024 letters)', 1024)}</div>`
            + `<div class="wp-row" style="margin-top:6px"><span class="wg-mini">Button that opens the list</span><input class="wp-in" data-f="button" maxlength="20" placeholder="Choose" value="${esc(s.button || '')}" style="width:150px"></div>`
            + (s.rows || []).map((r, j) => `<div class="wfx-rw"><input class="wp-in" data-rt="${j}" maxlength="24" placeholder="Item ${j + 1} (max 24 letters)" value="${esc(r.title || '')}"><input class="wp-in" data-rd="${j}" maxlength="72" placeholder="Small note (optional)" value="${esc(r.description || '')}"><button type="button" class="wp-btn s r" data-rr="${j}" aria-label="Remove item">✕</button></div>`).join('')
            + ((s.rows || []).length < 10 ? '<button type="button" class="wp-btn s" data-ra style="margin-top:6px">＋ Add an item</button>' : '') + tmo(s); break;
          case 'ask_location': b = ta('text', 'What you ask, e.g. Please share your location', 1024) + tmo(s, '1440') + '<div class="wg-mini" style="margin-top:4px">The customer sees a “Send location” button. Their map pin is saved in the chat.</div>'; break;
          case 'ask_address': b = ta('text', 'What you ask, e.g. Please send your delivery address', 1024) + tmo(s, '1440') + '<div class="wg-mini" style="margin-top:4px">WhatsApp address form (India). If WhatsApp does not accept it, the customer is asked in plain words and can type the address.</div>'; break;
          case 'goto_flow': { const others = D.flows.filter((x) => x.id !== id); b = others.length ? sel('', [['0', '— choose a flow —']].concat(others.map((x) => [x.id, x.name + (x.active ? '' : ' (switched off)')])), s.flow_id || 0, '').replace('<select ', '<select data-f="flow_id" data-n="1" ') + '<div class="wg-mini" style="margin-top:4px">This flow ends here and the other flow starts for the same customer (it must be switched on). At most 3 jumps per customer per hour.</div>' : '<div class="wg-mini">Save at least one more flow first.</div>'; break; }
          case 'tpl': b = sel('', [['0', '— choose an approved message —']].concat(D.templates.map((t) => [t.id, t.name.replace(/_/g, ' ') + (t.category === 'UTILITY' ? ' (utility)' : '')])), s.tpl_id || 0, '').replace('<select ', '<select data-f="tpl_id" data-n="1" '); break;
          case 'wait': b = `<div class="wp-row"><input class="wp-in" data-f="minutes" data-n="1" type="number" min="1" value="${s.minutes || 60}" style="width:110px"><span class="wg-mini">minutes (60 = 1 hour, 1440 = 1 day)</span></div>`; break;
          case 'wait_reply': b = `<div class="wp-row"><input class="wp-in" data-f="timeout" data-n="1" type="number" min="1" value="${s.timeout || 60}" style="width:110px"><span class="wg-mini">minutes to wait for an answer</span></div>`; break;
          case 'branch': b = `<div class="wp-2">${sel('', [['kw', 'Their last message has the word'], ['tag', 'They have the tag'], ['ordered', 'They have ordered before']], s.cond || 'kw', '').replace('<select ', '<select data-f="cond" ')}<input class="wp-in" data-f="val" placeholder="word / tag" value="${esc(s.val || '')}"></div><div class="wg-jump">If yes ${sel('', jopts(s), s.yes || '', '').replace('<select ', '<select data-f="yes" ')} If no ${sel('', jopts(s), s.no || '', '').replace('<select ', '<select data-f="no" ')}</div>`; break;
          case 'tag': b = `<input class="wp-in" data-f="tag" placeholder="tag, e.g. wants-gel" value="${esc(s.tag || '')}">`; break;
          case 'assign': b = sel('', [['0', 'The next free person']].concat(D.people.map((p) => [p.emp_id, p.name])), s.emp_id || 0, '').replace('<select ', '<select data-f="emp_id" data-n="1" '); break;
          case 'notify': b = sel('', [['0', 'The person who holds the chat']].concat(D.people.map((p) => [p.emp_id, p.name])), s.emp_id || 0, '').replace('<select ', '<select data-f="emp_id" data-n="1" ') + `<input class="wp-in" data-f="text" placeholder="What to tell them" value="${esc(s.text || '')}" style="margin-top:6px">`; break;
          default: b = '<div class="wg-mini">The flow ends here.</div>';
        }
        const j = jumpsHtml(s, i);
        const e = by[s.id] || {}; const st = e.sent || e.reply || e.reached || e.goto ? `<div class="wg-mini" style="margin-top:6px">${e.sent ? e.sent + ' sent' : ''}${e.reply ? ' · ' + e.reply + ' answered' : ''}${e.reached ? ' · ' + e.reached + ' reached' : ''}${e.goto ? ' · ' + e.goto + ' went on' : ''}</div>` : '';
        return `<div class="wfx-ed">${b}</div><div class="wfx-jp">${j}</div>${st}`;
      }
      function stepHtml(s, i) {
        const o = opened.has(s.id);
        return `<div class="wg-st wfx-st ${o ? 'open' : ''}" data-i="${i}"><div class="h" data-tg="${i}" role="button" tabindex="0" aria-expanded="${o}"><span class="no">${i + 1}</span><span class="wfx-ic">${ICON[s.type] || '•'}</span><b>${esc(TYPES()[s.type] || s.type)}</b><span class="wfx-sum">${esc(sum(s))}</span><button type="button" class="wp-btn s" data-up="${i}" ${i ? '' : 'disabled'} aria-label="Move up">↑</button><button type="button" class="wp-btn s" data-dn="${i}" ${i < S.length - 1 ? '' : 'disabled'} aria-label="Move down">↓</button><button type="button" class="wp-btn s r" data-rm="${i}" aria-label="Remove step">✕</button></div><div class="wfx-bd">${stepBody(s, i)}</div></div>`;
      }
      const camps = () => D.camps || [];
      const campBtns = () => { const c = camps().find((x) => +x.id === +campId); return c ? c.buttons || [] : []; };
      function campHtml() {
        const c = camps(); const sc = sel('feC', [['0', '— choose a campaign —']].concat(c.map((x) => [x.id, x.name + ' (' + x.status + ')'])), campId, '');
        const bt = campBtns(); const low = (tv || '').toLowerCase();
        const btHint = campId ? (bt.length ? 'Or they tap a button: ' + bt.map((t) => `<button type="button" class="wfx-bt ${low.includes(t.toLowerCase()) ? 'on' : ''}" data-bt2="${esc(t)}">${esc(t)}</button>`).join(' ') : 'This campaign message has no buttons — add the words people may write.') : 'Choose the campaign first.';
        return `<div class="wp-2"><div><label class="wp-fl">Campaign</label>${sc}<div class="wp-hint">Only people who got this campaign in the last <input class="wfx-days" id="feD" type="number" min="1" max="30" value="${campDays}"> day(s).</div></div>
        <div><label class="wp-fl">Their reply has <small>(any of these words)</small></label><input class="wp-in" id="feV" value="${esc(tv)}" placeholder="price, rate, kitna, offer, 100ml"><div class="wp-hint" id="feBt">${btHint}</div></div></div>`;
      }
      const HINT = { new_chat: ['', ''], ad_click: ['', ''], keyword: ['Words', 'Comma separated, e.g. price, rate, kimat'], button: ['Button title', 'Starts when someone taps a button with this title'], tag: ['Tag', 'Starts when this tag is added'], no_reply: ['Minutes of silence', 'e.g. 120 — starts when the customer has not written for that long (still inside 24 h)'] };
      function anyHtml() {
        const h = HINT[trig] || ['', ''];
        return `<div class="wp-2"><div><label class="wp-fl">Event</label>${sel('feT', OLD_TRIG.map((k) => [k, TR()[k]]), trig)}</div><div ${h[0] ? '' : 'hidden'}><label class="wp-fl" id="feVl">${esc(h[0])}</label><input class="wp-in" id="feV" value="${esc(tv)}"></div></div><div class="wp-hint" id="feH">${esc(h[1])}</div>`;
      }
      const camp = () => trig === 'camp_kw';
      function side() {
        const v = (k) => (l7 ? l7[k] : '–');
        return `<div class="wfx-ai"><b>✨ Claude suggests</b><br><b>Flow or chatbot?</b><br>• <b>Flow</b> for campaign replies and button taps — instant, free, always the same, never promises a wrong price.<br>• <b>Chatbot</b> for everything else — free questions, perfume advice, haggling. It only <b>suggests</b>; your salesperson sends.<br>• While a flow runs, the chatbot stays quiet for that chat. When the flow ends, the chat goes back to your team and the chatbot suggestions start again.</div>
        <div class="wp-cd" style="margin-top:8px"><b style="font-size:13px">Last 7 days</b><div class="wp-kv"><span>Started</span><b>${v('started')}</b></div><div class="wp-kv"><span>Answered a button</span><b>${v('answered')}</b></div><div class="wp-kv"><span>Ordered after</span><b>${v('ordered')}</b></div>${l7 ? '' : '<div class="wp-hint">The numbers appear once the flow has run.</div>'}</div>`;
      }
      function draw() {
        m.body.innerHTML = `<div class="wp"><div class="wfx"><div class="wfx-main"><label class="wp-fl" style="margin-top:0">Flow name</label><input class="wp-in" id="feN" value="${esc(name)}" placeholder="e.g. Diwali offer — price reply">
        <label class="wp-fl">Starts when</label><div class="wp-row"><button type="button" class="wp-chip ${camp() ? 'on' : ''}" data-mode="camp">Reply to a campaign</button><button type="button" class="wp-chip ${camp() ? '' : 'on'}" data-mode="any">Any chat</button></div>
        ${camp() ? campHtml() : anyHtml()}
        <label class="wp-fl">Then</label><div id="feS">${S.map(stepHtml).join('')}</div>
        <div class="wp-row" style="margin:8px 0">${QUICK.map(([k, l]) => `<button type="button" class="wp-chip" data-add="${k}">${l}</button>`).join('')}${sel('feA', [['', '＋ More steps…']].concat(Object.keys(TYPES()).filter((k) => !QUICK.some((q) => q[0] === k)).map((k) => [k, TYPES()[k]])), '', 'wfx-more')}</div>
        <div class="wp-row" style="margin-top:10px"><input class="wp-in wfx-rp" id="feRp" placeholder="Try it: what does the customer tap or write? (optional)"><button type="button" class="wp-btn" id="feTs">Try it (sends nothing)</button></div>
        <div class="wp-row" style="margin-top:10px"><button type="button" class="wp-btn ${active ? 'p' : ''}" id="feSv">Save</button>${active ? '' : '<button type="button" class="wp-btn p" id="feSo">Save &amp; switch on</button>'}</div><div id="feR"></div></div>
        <div class="wfx-side">${side()}</div></div></div>`;
        bind();
      }
      function trigUi() { if (camp()) return; const h = HINT[trig] || ['', '']; const v = $(m.body, '#feV'); v.parentNode.hidden = !h[0]; $(m.body, '#feVl').textContent = h[0]; $(m.body, '#feH').textContent = h[1]; }
      function readTop() {
        name = $(m.body, '#feN').value;
        if (camp()) { const c = $(m.body, '#feC'); if (c) campId = +c.value; const d = $(m.body, '#feD'); if (d) campDays = Math.max(1, Math.min(30, +d.value || 3)); }
        else { const t = $(m.body, '#feT'); if (t) { trig = t.value; lastAny = trig; } }
        const v = $(m.body, '#feV'); if (v) tv = v.value;
      }
      // remove jump keys of buttons / list items that no longer exist, so an old name never routes anywhere
      function tidy() { S.forEach((s) => { if (!s.on) return; if (['buttons', 'list', 'media_buttons'].includes(s.type)) { const t = titles(s); Object.keys(s.on).forEach((k) => { if (k.indexOf('btn:') === 0 && !t.includes(k.slice(4))) delete s.on[k]; }); } }); }
      function rename(s, from, to) { if (from && to && from !== to && s.on && s.on['btn:' + from]) { s.on['btn:' + to] = s.on['btn:' + from]; delete s.on['btn:' + from]; } }
      // the jump lists of one step follow its button / item names — redrawn in place so a tap on another control is never lost
      function bindOn(scope, s) { scope.querySelectorAll('[data-on]').forEach((x) => { x.onchange = () => { s.on = s.on || {}; if (x.value) s.on[x.dataset.on] = x.value; else delete s.on[x.dataset.on]; }; }); }
      function refreshCard(card, s) { card.querySelector('.wfx-sum').textContent = sum(s); clearTimeout(card._rf); card._rf = setTimeout(() => later(card, s), 350); }   // the jump list grows while names are typed: wait until the tap on another button has finished, or that tap would be lost
      function later(card, s) { if (!card.isConnected) return; const jp = card.querySelector('.wfx-jp'); if (jp) { jp.innerHTML = jumpsHtml(s, +card.dataset.i); bindOn(jp, s); } }
      function bind() {
        if (!camp()) trigUi();
        m.body.querySelectorAll('[data-mode]').forEach((b) => { b.onclick = () => { readTop(); trig = b.dataset.mode === 'camp' ? 'camp_kw' : lastAny; draw(); }; });
        const T = $(m.body, '#feT'); if (T) T.onchange = () => { readTop(); trigUi(); };
        const C = $(m.body, '#feC'); if (C) C.onchange = () => { readTop(); draw(); };
        
        m.body.querySelectorAll('[data-bt2]').forEach((b) => { b.onclick = () => { readTop(); const t = b.dataset.bt2; if (!tv.toLowerCase().includes(t.toLowerCase())) tv = (tv.trim() ? tv.trim().replace(/,\s*$/, '') + ', ' : '') + t; draw(); }; });
        m.body.querySelectorAll('.wfx-st').forEach((card) => {
          const s = S[+card.dataset.i];
          card.querySelector('.h').onclick = (e) => { if (e.target.closest('button')) return; card.classList.toggle('open'); if (card.classList.contains('open')) opened.add(s.id); else opened.delete(s.id); card.querySelector('.h').setAttribute('aria-expanded', card.classList.contains('open')); };
          card.querySelector('.h').onkeydown = (e) => { if ((e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget) { e.preventDefault(); e.currentTarget.click(); } };
          card.querySelectorAll('[data-f]').forEach((x) => { x.onchange = x.oninput = () => { s[x.dataset.f] = x.dataset.n ? +x.value : x.value; if (!x.value) delete s[x.dataset.f]; if (x.dataset.f === 'lib_id' || x.dataset.f === 'flow_id' || x.dataset.f === 'tpl_id' || x.dataset.f === 'emp_id') card.querySelector('.wfx-sum').textContent = sum(s); else if (x.dataset.f === 'text') card.querySelector('.wfx-sum').textContent = sum(s); }; });
          card.querySelectorAll('[data-bt]').forEach((x) => { x.onchange = () => { const old = (s.buttons || []).slice(); const j = +x.dataset.bt; const prev = old[j]; s.buttons = [...card.querySelectorAll('[data-bt]')].map((y) => y.value.trim()).filter(Boolean); rename(s, prev, x.value.trim()); refreshCard(card, s); }; });
          card.querySelectorAll('[data-rt]').forEach((x) => { x.onchange = () => { const j = +x.dataset.rt; const old = s.rows[j].title; s.rows[j].title = x.value.trim(); rename(s, old, x.value.trim()); refreshCard(card, s); }; });
          card.querySelectorAll('[data-rd]').forEach((x) => { x.onchange = x.oninput = () => { s.rows[+x.dataset.rd].description = x.value; }; });
          card.querySelectorAll('[data-rr]').forEach((x) => { x.onclick = () => { s.rows.splice(+x.dataset.rr, 1); readTop(); draw(); }; });
          card.querySelectorAll('[data-ra]').forEach((x) => { x.onclick = () => { s.rows.push({ title: '', description: '' }); readTop(); draw(); }; });
          bindOn(card, s);
        });
        m.body.querySelectorAll('[data-up]').forEach((b) => { b.onclick = () => { readTop(); const i = +b.dataset.up; [S[i - 1], S[i]] = [S[i], S[i - 1]]; draw(); }; });
        m.body.querySelectorAll('[data-dn]').forEach((b) => { b.onclick = () => { readTop(); const i = +b.dataset.dn; [S[i + 1], S[i]] = [S[i], S[i + 1]]; draw(); }; });
        m.body.querySelectorAll('[data-rm]').forEach((b) => { b.onclick = () => { readTop(); const g = S.splice(+b.dataset.rm, 1)[0]; S.forEach((s) => { if (s.next === g.id) delete s.next; ['yes', 'no'].forEach((k) => { if (s[k] === g.id) delete s[k]; }); Object.keys(s.on || {}).forEach((k) => { if (s.on[k] === g.id) delete s.on[k]; }); }); draw(); }; });
        const add = (t) => { if (!t) return; readTop(); const s = Object.assign({ id: nid(), type: t }, JSON.parse(JSON.stringify(DEFAULT_STEP[t] || {}))); S.push(s); opened.add(s.id); draw(); const last = m.body.querySelector('.wfx-st:last-child'); if (last && last.scrollIntoView) last.scrollIntoView({ block: 'nearest' }); };
        m.body.querySelectorAll('[data-add]').forEach((b) => { b.onclick = () => add(b.dataset.add); });
        $(m.body, '#feA').onchange = (e) => add(e.target.value);
        const persist = async (b, on) => {
          readTop(); tidy(); b.disabled = true;
          try {
            const r = await api.post('wa.g_flow_save', { id, name, trig, trig_val: tv, camp_id: camp() ? campId : 0, camp_days: campDays, steps: S }); id = r.id;
            if (on) await api.post('wa.g_flow_toggle', { id, on: true });
            toast(on ? 'Saved ✓ — the flow is ON' : 'Saved ✓' + (active ? '' : ' (switch it on from the list)')); m.close(); load();
          } catch (er) { $(m.body, '#feR').innerHTML = errBox(er.message); b.disabled = false; }
        };
        $(m.body, '#feSv').onclick = (e) => persist(e.currentTarget, false);
        const so = $(m.body, '#feSo'); if (so) so.onclick = (e) => persist(e.currentTarget, true);
        $(m.body, '#feTs').onclick = async () => {
          readTop(); tidy(); const rp = $(m.body, '#feRp').value.trim();
          try { const r = await api.post('wa.g_flow_test', { id, steps: S, name: 'Rahul Shah', reply: rp }); $(m.body, '#feR').innerHTML = (r.problems.length ? errBox(r.problems.join(' ')) : '') + '<div class="wg-path" style="margin-top:8px">' + r.path.map((p, n) => (n + 1) + '. ' + esc(p.t) + (p.v ? ' — ' + esc(p.v) : '')).join('\n') + '</div>'; } catch (er) { $(m.body, '#feR').innerHTML = errBox(er.message); }
        };
      }
      draw();
    }
    load(); return load;
  });

  window.WaGrowth = { tagOptions, claudeBar, claudeShow };
})();
