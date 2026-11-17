/* store_comm_report.js — Store → Store Commission: "Commission for a date range" (for example 1 Nov to today).
 *   StoreCommReport.mount(el)   draws the card into el.   Needs api.js, store_common.js (esc, inr).
 * It only READS: budget.store_comm (per person / per day) and budget.store_bills (every bill with the rule that paid it).
 * The money is worked out by the commission engine: a matching Offer first (on the day it ran), otherwise the price chart. No order-code rule in the store. */
window.StoreCommReport = (function () {
  'use strict';
  const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const f2 = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const dm = (x) => new Date(x + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  let R = null;
  function ruleTag(b) {
    if (b.rule === 'offer') return '<span class="sx-pill good" style="background:#fff4e0;color:#b45309">🎁 Offer ' + f2(b.pct) + '%</span>';
    if (b.rule === 'flash offer') return '<span class="sx-pill good" style="background:#fff4e0;color:#b45309">⚡ Flash ' + f2(b.pct) + '%</span>';
    if (b.rule === 'chart') return '<span class="sx-pill" style="background:#eef2ff;color:#3730a3">💰 Chart ' + f2(b.pct) + '%</span>';
    return '<span class="sx-pill bad">' + esc(b.rule === 'ML not priced' ? 'ML not in the chart' : (b.rule || '—')) + '</span>';
  }
  function draw(el) {
    const P = R.comm.people || [], B = R.bills.bills || [];
    const tot = P.reduce((a, p) => ({ s: a.s + p.sales, c: a.c + p.commission, n: a.n + p.bills }), { s: 0, c: 0, n: 0 });
    const offerBills = B.filter((b) => b.rule === 'offer' || b.rule === 'flash offer'), offerCom = offerBills.reduce((a, b) => a + (b.com || 0), 0);
    const warn = [];
    if (R.comm.unpriced_bills) warn.push(R.comm.unpriced_bills + ' bill(s) have a size that is not in the price chart: they pay 0');
    if (R.comm.no_ml_bills) warn.push(R.comm.no_ml_bills + ' bill(s) have no ml: they pay 0');
    if (R.comm.old_pos && R.comm.old_pos.bills) warn.push(R.comm.old_pos.bills + ' bill(s) come from the old POS (days before the new POS started)');
    el.querySelector('#scrOut').innerHTML = `
      <div class="sx-kpis" style="margin:10px 0">
        <div class="sx-kpi blue"><div class="tx"><div class="lb">Store sales</div><b>${inr(tot.s)}</b><small>${tot.n} bills · ${dm(R.from)} – ${dm(R.to)}</small></div></div>
        <div class="sx-kpi orange"><div class="tx"><div class="lb">Commission (total)</div><b>${inr(tot.c)}</b><small>${tot.s > 0 ? f2(tot.c / tot.s * 100) + '% of sales' : ''}</small></div></div>
        <div class="sx-kpi violet"><div class="tx"><div class="lb">Paid by an Offer</div><b>${inr(offerCom)}</b><small>${offerBills.length} bill${offerBills.length === 1 ? '' : 's'} (the rest by the price chart)</small></div></div>
      </div>
      ${warn.length ? '<div class="sx-msg bad" style="margin-bottom:8px"><span>' + warn.map(esc).join(' · ') + '</span></div>' : ''}
      <div style="overflow:auto"><table style="border-collapse:collapse;font-size:13px;width:100%;min-width:560px"><thead><tr style="text-align:left;color:var(--sx-mut);font-size:11.5px;text-transform:uppercase"><th style="padding:6px 8px">Person (Billed by)</th><th style="padding:6px 8px;text-align:right">Bills</th><th style="padding:6px 8px;text-align:right">Sales</th><th style="padding:6px 8px;text-align:right">Commission</th><th style="padding:6px 8px;text-align:right">Avg %</th></tr></thead><tbody>
        ${P.length ? P.slice().sort((a, b) => b.commission - a.commission).map((p, i) => `<tr style="border-top:1px solid var(--sx-line);cursor:pointer" data-p="${i}"><td style="padding:7px 8px"><b>${esc(p.name || '—')}</b>${p.pooled ? ' <small class="sx-mut">one team</small>' : ''}</td><td style="padding:7px 8px;text-align:right">${p.bills}</td><td style="padding:7px 8px;text-align:right">${inr(p.sales)}</td><td style="padding:7px 8px;text-align:right"><b>${inr(p.commission)}</b></td><td style="padding:7px 8px;text-align:right">${f2(p.pct)}%</td></tr>
          <tr class="scr-d" data-d="${i}" hidden><td colspan="5" style="padding:0 8px 8px 24px;background:var(--sx-bg2,#f8fafc)">${(p.perday || []).slice().sort((a, b) => a.day < b.day ? -1 : 1).map((d) => `<div style="display:flex;gap:14px;font-size:12.5px;padding:2px 0"><span style="min-width:60px">${dm(d.day)}</span><span class="sx-mut" style="min-width:60px">${d.bills} bill${d.bills === 1 ? '' : 's'}</span><span style="min-width:90px">${inr(d.sales)}</span><b>${inr(d.commission)}</b></div>`).join('')}</td></tr>`).join('') : '<tr><td colspan="5" style="padding:12px 8px" class="sx-mut">No store bills in these days.</td></tr>'}
        <tr style="border-top:2px solid var(--sx-line)"><td style="padding:7px 8px"><b>Total</b></td><td style="padding:7px 8px;text-align:right"><b>${tot.n}</b></td><td style="padding:7px 8px;text-align:right"><b>${inr(tot.s)}</b></td><td style="padding:7px 8px;text-align:right"><b>${inr(tot.c)}</b></td><td style="padding:7px 8px;text-align:right">${tot.s > 0 ? f2(tot.c / tot.s * 100) : 0}%</td></tr></tbody></table></div>
      <details class="sx-d" style="margin-top:10px"><summary>🧾 Every bill and the rule that paid it (${B.length})</summary><div style="overflow:auto;margin-top:6px"><table style="border-collapse:collapse;font-size:12.5px;width:100%;min-width:640px"><thead><tr style="text-align:left;color:var(--sx-mut);font-size:11px;text-transform:uppercase"><th style="padding:4px 8px">Bill</th><th style="padding:4px 8px">Day</th><th style="padding:4px 8px;text-align:right">ml</th><th style="padding:4px 8px;text-align:right">Amount</th><th style="padding:4px 8px">Paid by</th><th style="padding:4px 8px;text-align:right">Commission</th><th style="padding:4px 8px">Billed by</th></tr></thead><tbody>
        ${B.slice().sort((a, b) => (a.day + a.time) < (b.day + b.time) ? -1 : 1).map((b) => `<tr style="border-top:1px solid var(--sx-line)"><td style="padding:4px 8px">${esc(b.bill_no)}</td><td style="padding:4px 8px">${dm(b.day)} ${esc(b.time || '')}</td><td style="padding:4px 8px;text-align:right">${b.ml || '—'}</td><td style="padding:4px 8px;text-align:right">${inr(b.total)}</td><td style="padding:4px 8px">${ruleTag(b)}</td><td style="padding:4px 8px;text-align:right"><b>${inr(b.com)}</b></td><td style="padding:4px 8px">${esc(b.name || '')}</td></tr>`).join('')}</tbody></table></div></details>
      <div class="sx-mut" style="font-size:11.5px;margin-top:8px">An Offer counts on the days it ran (a stopped offer keeps its days). The price chart is the one saved NOW — if a price changed during these days the old bills are counted with the new chart. The store has no order-code rule and no over-target extra.</div>`;
    el.querySelector('#scrCsv').disabled = !B.length;
  }
  function csv() {
    const B = (R && R.bills.bills) || [], q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
    const rows = [['Bill', 'Day', 'Time', 'ml', 'Amount', 'Rule', 'Percent', 'Commission', 'Billed by']].concat(B.slice().sort((a, b) => (a.day + a.time) < (b.day + b.time) ? -1 : 1).map((b) => [b.bill_no, b.day, b.time, b.ml, b.total, b.rule, b.pct, b.com, b.name]));
    const blob = new Blob(['﻿' + rows.map((r) => r.map(q).join(',')).join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'store-commission-' + R.from + '-to-' + R.to + '.csv'; document.body.appendChild(a); a.click(); a.remove();
  }
  function mount(el) {
    const now = new Date(), first = new Date(now.getFullYear(), now.getMonth(), 1);
    el.innerHTML = `<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <label class="sx-f" style="margin:0">From<input class="sx-in" type="date" id="scrFrom" value="${iso(first)}"></label>
        <label class="sx-f" style="margin:0">To<input class="sx-in" type="date" id="scrTo" value="${iso(now)}"></label>
        <button class="sx-btn" id="scrGo" type="button" style="padding:9px 16px">Show commission</button>
        <button class="lnk" id="scrCsv" type="button" disabled>⬇ Download bills (CSV)</button></div>
      <div id="scrOut" class="sx-mut" style="margin-top:8px">Pick the days and press “Show commission”.</div>`;
    const go = async () => {
      const from = el.querySelector('#scrFrom').value, to = el.querySelector('#scrTo').value, out = el.querySelector('#scrOut');
      if (!from || !to || to < from) { out.innerHTML = '<span style="color:var(--sx-bad)">Choose the From and To days (To after From).</span>'; return; }
      out.textContent = 'Counting…'; el.querySelector('#scrGo').disabled = true;
      try { const [comm, bills] = await Promise.all([api.get('budget.store_comm', { date_from: from, date_to: to }), api.get('budget.store_bills', { date_from: from, date_to: to })]); R = { from, to, comm, bills }; draw(el); }
      catch (e) { out.innerHTML = '<span style="color:var(--sx-bad)">' + esc((e && e.message) || 'Could not count.') + '</span>'; }
      el.querySelector('#scrGo').disabled = false;
    };
    el.querySelector('#scrGo').addEventListener('click', go);
    el.querySelector('#scrCsv').addEventListener('click', csv);
    el.addEventListener('click', (e) => { const r = e.target.closest('tr[data-p]'); if (!r) return; const d = el.querySelector('tr[data-d="' + r.dataset.p + '"]'); if (d) d.hidden = !d.hidden; });
  }
  return { mount };
})();
