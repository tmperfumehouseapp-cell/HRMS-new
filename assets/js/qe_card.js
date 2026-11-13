/* qe_card.js — the quick-entry orders ("Add orders") card on the home page of the Sales Head who watches them (server decides who: manual_orders.quick_list). Shows today's numbers and the last 7 days' entries, each with a Remove button. */
(function () {
  'use strict';
  const box = document.getElementById('qeBox'); if (!box || typeof api === 'undefined') return;
  const esc = (t) => String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (v) => '₹' + Math.round(Number(v) || 0).toLocaleString('en-IN');
  const when = (s) => { const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  async function load() {
    let d; try { d = await api.get('manual_orders.quick_list', { card: 1 }); } catch (e) { return; }
    if (!d || !d.allowed) { box.innerHTML = ''; return; }
    const t = d.today || {}, L = d.entries || [];
    box.innerHTML = `<div class="card" style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><div style="font-weight:800;font-size:15px">🧾 Quick-entry orders <span style="font-weight:600;color:#64748b;font-size:12px">(added by Admin)</span></div></div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:10px 0">
        <div style="background:#eef6ff;border-radius:12px;padding:8px 10px"><div style="font-size:11px;color:#64748b;font-weight:700">TODAY</div><div style="font-size:20px;font-weight:900">${t.orders || 0}</div><div style="font-size:12px">orders · ${inr(t.amount)}</div></div>
        <div style="background:#fff3e0;border-radius:12px;padding:8px 10px"><div style="font-size:11px;color:#64748b;font-weight:700">QUERY / CAMPAIGN</div><div style="font-size:16px;font-weight:900">${t.query || 0} / ${t.campaign || 0}</div><div style="font-size:12px">${inr(t.query_amt)} / ${inr(t.campaign_amt)}</div></div>
        <div style="background:#e8f7ee;border-radius:12px;padding:8px 10px"><div style="font-size:11px;color:#64748b;font-weight:700">PREPAID / COD</div><div style="font-size:14px;font-weight:900">${inr(t.prepaid)}</div><div style="font-size:12px">COD ${inr(t.cod)}</div></div></div>
      <div style="font-size:12px;color:#64748b;font-weight:700;margin-bottom:4px">Last 7 days — ${L.length} ${L.length === 1 ? 'entry' : 'entries'}</div>
      ${L.length ? L.map((x) => `<div style="display:flex;gap:8px;align-items:center;border-top:1px solid #edf0f4;padding:7px 0;font-size:13px"><div style="flex:1;min-width:0"><b>${x.count} ${x.type === 'campaign' ? 'Campaign' : 'Query'} order${x.count === 1 ? '' : 's'}</b> · ${inr(x.total)}<br><span style="color:#64748b;font-size:12px">${esc(when(x.date))} · ${x.pay === 'paid' ? 'Prepaid' : x.pay === 'cod' ? 'COD' : 'Part prepaid'}${x.cod > 0 && x.pay !== 'cod' ? ' (COD ' + inr(x.cod) + ')' : ''} · ${esc(x.house)} · by ${esc(x.by)}${x.remarks && x.remarks !== 'Quick entry' ? ' · ' + esc(x.remarks) : ''}</span></div><button type="button" data-rm="${esc(x.batch)}" data-n="${x.count}" style="border:1px solid #f3c9c9;background:#fff;color:#b42318;border-radius:8px;padding:6px 10px;font-weight:700;cursor:pointer">Remove</button></div>`).join('') : '<div style="color:#8794a3;font-size:13px;padding:6px 0">No quick entries in the last 7 days.</div>'}</div>`;
  }
  box.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rm]'); if (!b) return;
    if (!confirm('Remove these ' + b.dataset.n + ' order' + (b.dataset.n === '1' ? '' : 's') + '? They disappear from Orders and from the boxes.')) return;
    b.disabled = true; b.textContent = 'Removing…';
    try { await api.post('manual_orders.quick_delete', { batch: b.dataset.rm }); } catch (er) { alert(er.message); }
    load();
  });
  load(); setInterval(() => { if (!document.hidden) load(); }, 120000);
})();
