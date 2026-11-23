/* must3_me.js — "✅ Must-send check" on My Sales (owner, 23 Nov): the salesperson's OWN orders only. The 3 messages every order needs (address re-check · chain selection · prepaid 2%) + ➕ was an add-on put on the order. Tap a tile → the orders where it is missing, with "Open chat". Data: ct.must3_me (range = today | yday | 7d). Hidden for people without sales orders. */
(function () {
  'use strict';
  const box = document.getElementById('m3Box'); if (!box || typeof api === 'undefined') return;
  const esc = (t) => String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (v) => '₹' + Math.round(Number(v) || 0).toLocaleString('en-IN');
  const K = [['a', '📍', 'Address re-check'], ['c', '⛓', 'Chain selection'], ['p', '💳', 'Prepaid 2%'], ['d', '➕', 'Add-on / bigger offered']];
  const R = [['today', 'Today'], ['yday', 'Yesterday'], ['7d', '7 days']];
  const S = { range: 'today', sel: '', d: null, busy: false };
  const col = (v, t) => !t ? '#9ca3af' : (v / t >= .8 ? '#16a34a' : v / t >= .5 ? '#d97706' : '#dc2626');
  const ok = (o, k) => k === 'd' ? o.d != null : !!o[k];
  function paint() {
    const slot = document.getElementById('m3Slot'), host = slot || box; if (slot) box.innerHTML = '';   // under "Your next step" when the home page has the slot, else in the box above it
    const d = S.d; if (!d || !d.sales) { host.innerHTML = ''; return; }
    const t = d.team || {}, n = t.n || 0, all = d.orders || [], orders = all.filter((o) => !o.nochat), NC = all.filter((o) => o.nochat);
    const pills = R.map((x) => `<span data-r="${x[0]}" style="cursor:pointer;font-size:12px;font-weight:800;border:1.5px solid ${S.range === x[0] ? '#111827' : '#e5e7eb'};border-radius:99px;padding:4px 11px;background:${S.range === x[0] ? '#111827' : '#fff'};color:${S.range === x[0] ? '#fff' : '#111827'}">${x[1]}</span>`).join('');
    const tiles = K.map((k) => { const v = t[k[0]] || 0, on = S.sel === k[0]; return `<div data-t="${k[0]}" style="cursor:pointer;border:${on ? '2.5px solid #111827' : '1.5px solid #e5e7eb'};border-radius:14px;padding:9px 10px;background:#fff"><div style="font-size:12px;color:#667781;min-height:30px;line-height:1.25">${k[1]} ${k[2]}</div><div style="font-size:24px;font-weight:900;color:${col(v, n)}">${v}<small style="font-size:12px;color:#667781;font-weight:500"> / ${n}</small>${n ? `<span style="float:right;font-size:16px">${Math.round(v / n * 100)}%</span>` : ''}</div><div style="height:6px;border-radius:4px;background:#eef0f4;margin-top:5px"><div style="height:6px;border-radius:4px;width:${n ? Math.round(v / n * 100) : 0}%;background:${col(v, n)}"></div></div></div>`; }).join('');
    const missing = (o) => K.filter((k) => !ok(o, k[0]));
    const list = S.sel === 'x' ? NC : S.sel ? orders.filter((o) => !ok(o, S.sel)) : orders.filter((o) => missing(o).length);
    const head = S.sel === 'x' ? `📱 Chat not found — ${NC.length} order${NC.length === 1 ? '' : 's'} (not counted above)` : S.sel ? `${K.find((k) => k[0] === S.sel)[1]} ${K.find((k) => k[0] === S.sel)[2]} — missing in ${list.length} of ${n} order${n === 1 ? '' : 's'}` : (list.length ? `⚠ Still to do — ${list.length} order${list.length === 1 ? '' : 's'}` : '');
    const rows = list.map((o) => {
      const nc = o.nochat ? `<div style="font-size:12px;color:#92400e;background:#fffbeb;border-radius:8px;padding:5px 8px;margin-top:4px">📱 Chat not found for this order's number. If the customer chats from another number, add it in Order Allocation → Extra mobile no. — then this check reads that chat.</div>` : '';
      const miss = o.nochat ? [] : missing(o), why = o.nochat ? '' : !ok(o, 'd') && o.dwhy ? `<div style="font-size:12px;color:#991b1b;margin-top:3px">➕ ${esc(o.dwhy)}</div>` : '';
      return `<div style="border-top:1px solid #eef0f4;padding:9px 0;display:flex;gap:8px;align-items:flex-start"><div style="flex:1;min-width:0"><div style="font-size:13.5px"><b>${esc(o.customer || 'Customer')}</b> <span style="color:#667781">· ${esc(o.order)} · ${inr(o.total)} · ${S.range === '7d' ? esc(String(o.day || '').slice(5)) + ' ' : ''}${esc(o.at)}</span></div><div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:4px">${miss.map((k) => `<span style="font-size:11.5px;font-weight:800;border-radius:99px;padding:2px 9px;background:#fee2e2;color:#991b1b">${k[1]} ${esc(k[2])}</span>`).join('')}</div>${why}${nc}</div>${o.phone && !o.nochat ? `<a href="my_chats.html?phone=${esc(o.phone)}" style="background:#00a884;color:#fff;border-radius:99px;padding:7px 12px;font-weight:800;font-size:12.5px;text-decoration:none;white-space:nowrap">Open chat</a>` : ''}</div>`;
    }).join('');
    const xt = NC.length ? `<div data-t="x" style="cursor:pointer;margin-top:8px;border:${S.sel === 'x' ? '2.5px solid #111827' : '1.5px solid #fcd34d'};border-radius:14px;padding:9px 10px;background:#fffbeb;display:flex;justify-content:space-between;align-items:center;gap:8px"><span style="font-size:12.5px;color:#92400e;font-weight:700">📱 Chat not found — not counted above. Tap to see</span><b style="font-size:20px;color:#b45309">${NC.length}</b></div>` : '';
    const body = n === 0 && !NC.length ? '<div style="padding:12px 4px;color:#667781;font-size:13px">No orders in this period yet.</div>'
      : (list.length ? `<div style="font-size:12.5px;font-weight:800;color:#92400e;margin:10px 0 2px">${head}</div>${rows}` : n === 0 ? '' : `<div style="text-align:center;padding:16px 6px"><div style="font-size:34px">🎉</div><b>${S.sel ? 'Done in every order' : 'All 3 messages sent and an add-on done in every order'}</b></div>`);
    host.innerHTML = `<div class="card" style="margin-bottom:12px;border:2.5px solid #f59e0b;box-shadow:0 2px 12px rgba(245,158,11,.3)"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><div style="font-weight:800;font-size:15px">✅ Must-send check</div><div style="display:flex;gap:6px">${pills}</div></div><div style="font-size:12px;color:#667781;margin:2px 0 8px">Your orders: the 3 messages every order needs, and the add-on. Tap a box to see which customers.</div><div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">${tiles}</div>${xt}${body}</div>`;
  }
  async function load() {
    if (S.busy) return; S.busy = true;
    try { const d = await api.get('ct.must3_me', { range: S.range }); S.d = d; } catch (e) { S.d = null; }
    S.busy = false; paint();
  }
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#m3Slot, #m3Box')) return;
    const r = e.target.closest('[data-r]'); if (r) { S.range = r.dataset.r; S.sel = ''; S.d = null; load(); return; }
    const t = e.target.closest('[data-t]'); if (t) { S.sel = S.sel === t.dataset.t ? '' : t.dataset.t; paint(); }
  });
  window.Must3Me = { repaint: paint };
  load(); setInterval(() => { if (!document.hidden) load(); }, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
})();
