/* store_rules.js — Store: the commission rules, the price chart and the offers running now, READ-ONLY (they are changed by Admin in Commission Rules / Learning → Pricing).
 *   StoreRules.mount(el)   draws the card into el.   Needs api.js, store_common.js (esc, inr). Talks to st_price.card. */
window.StoreRules = (function () {
  'use strict';
  const f = (n) => Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const dd = (x) => x ? new Date(x + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '';
  function fixRow(r, ed) { return `<div class="srf" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:3px 0"><input class="sx-in" data-f="text" placeholder="Perfume name (a part, e.g. Dress to impress)" value="${esc(r.text || '')}" style="flex:2;min-width:200px" ${ed ? '' : 'disabled'}><input class="sx-in" data-f="ml" type="number" min="1" placeholder="ml" value="${r.ml || ''}" style="width:80px" ${ed ? '' : 'disabled'}><span>ml = ₹</span><input class="sx-in" data-f="price" type="number" min="1" placeholder="price" value="${r.price || ''}" style="width:110px" ${ed ? '' : 'disabled'}><span style="margin-left:8px">🎁 Deal:</span><input class="sx-in" data-f="dq" type="number" min="2" placeholder="3" value="${r.deal_qty || ''}" style="width:64px" title="how many bottles" ${ed ? '' : 'disabled'}><span>for ₹</span><input class="sx-in" data-f="dp" type="number" min="1" placeholder="2700" value="${r.deal_price || ''}" style="width:100px" title="price for that many" ${ed ? '' : 'disabled'}>${ed ? '<button type="button" class="lnk" data-x="1">✕</button>' : ''}</div>`; }
  function fixHtml(d) {
    const ed = !!d.can_edit, rows = d.fixed || [];
    return (rows.length ? rows.map((r) => fixRow(r, ed)).join('') : '<div class="sx-mut" style="font-size:12.5px">None. Every perfume is priced from the price chart.</div>')
      + (ed ? '<div style="margin-top:6px"><button type="button" class="lnk" id="srAdd">＋ Add a fixed price</button> <button type="button" class="sx-btn" id="srSave" style="padding:7px 14px;margin-left:6px">Save</button> <span id="srMsg" class="sx-mut"></span></div><div class="sx-mut" style="font-size:11.5px;margin-top:4px">One scan of that perfume (that ml) shows its fixed price in Billing. A 🎁 Deal (for example 3 for ₹2,700) is charged for every full group of that many bottles; the rest are charged one by one. The commission % is the price chart’s % for that price (a price above the top column pays the top %).</div>' : '');
  }
  function draw(d) {
    const R = d.rules || {}, win = d.window || 20, team = (d.team || []);
    const offers = (d.offers || []).map((o) => `<tr style="border-top:1px solid var(--sx-line)"><td style="padding:5px 8px"><b>${o.ml} ml</b></td><td style="padding:5px 8px">${inr(o.price)} (±₹${f(win)})</td><td style="padding:5px 8px"><b>${f(o.pct)}%</b></td><td style="padding:5px 8px">${dd(o.from)}${o.to ? ' – ' + dd(o.to) : ' → keeps going'}</td><td style="padding:5px 8px">${esc(o.note || '')}</td></tr>`).join('');
    const flash = (d.flash || []).map((x) => `<div class="sx-mut" style="font-size:12.5px">⚡ Flash offer: ${(x.sizes || []).join(' / ')} ml → <b>${f(x.pct)}%</b> till ${esc(String(x.ends_at).slice(11, 16))}</div>`).join('');
    const cols = d.cols || [], rows = d.rows || [];
    const chart = rows.length ? `<div style="overflow:auto"><table style="border-collapse:collapse;font-size:12.5px;min-width:420px"><thead><tr><th style="text-align:left;padding:4px 8px">ML</th>${cols.map((c) => `<th style="padding:4px 8px;text-align:right">pays ${f(c)}%</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr style="border-top:1px solid var(--sx-line)"><td style="padding:4px 8px"><b>${r.ml}</b></td>${cols.map((c, i) => `<td style="padding:4px 8px;text-align:right">${r.p[i] > 0 ? inr(r.p[i]) : '–'}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : '<div class="sx-mut">The price chart is not available.</div>';
    return `<div class="sx-mut" style="font-size:12.5px;margin-bottom:8px">You can only look at these here. ${d.can_edit ? 'To change them: Commission Rules and Learning → Pricing.' : 'An Admin changes them in Commission Rules and Learning → Pricing.'}</div>
      <div style="display:grid;gap:8px;font-size:13px;line-height:1.5">
        <div><b>📋 Order code</b> — the store has <b>no order-code rule</b>: every bill pays its full % (no “no code = half”).</div>
        <div><b>💰 Price → %</b> — the price the customer paid goes to the nearest price column of that size (table below); below the last column it pays <b>${f(d.floor || 0)}%</b>.</div>
        <div><b>🎁 Offers</b> — a bill with exactly the offer’s total size and a price within ±₹${f(win)} of the offer price pays the <b>offer’s %</b> instead of the chart. The same offers the online sales get, on the day they ran.</div>
        <div><b>🏬 Store</b> — same rules as online, but no over-target extra and no boost.</div></div>
      <h4 style="margin:12px 0 4px">Offers running now</h4>
      ${offers ? `<div style="overflow:auto"><table style="border-collapse:collapse;font-size:12.5px"><thead><tr><th style="text-align:left;padding:4px 8px">Size</th><th style="text-align:left;padding:4px 8px">Price</th><th style="text-align:left;padding:4px 8px">Pays</th><th style="text-align:left;padding:4px 8px">Runs</th><th style="text-align:left;padding:4px 8px">Note</th></tr></thead><tbody>${offers}</tbody></table></div>` : '<div class="sx-mut" style="font-size:12.5px">No offer is running now — the normal price chart applies.</div>'}${flash}
      <h4 style="margin:12px 0 4px">Fixed-price perfumes <small class="sx-mut" style="font-weight:600">(for example the OG perfumes: this size always sells at this price)</small></h4>
      <div id="srFix">${fixHtml(d)}</div>
      <details class="sx-d" style="margin-top:10px"><summary>💰 The price chart (what each size pays)</summary><div style="margin-top:8px">${chart}</div></details>`;
  }
  async function mount(el) {
    el.innerHTML = '<div class="sx-mut">Loading…</div>';
    try {
      const d = await api.get('st_price.card'); el.innerHTML = draw(d);
      el.addEventListener('click', async (e) => {
        const box = el.querySelector('#srFix'); if (!box) return;
        if (e.target.id === 'srAdd') { const f0 = box.firstElementChild; if (f0 && !f0.classList.contains('srf') && f0.classList.contains('sx-mut')) f0.remove(); const row = document.createElement('div'); row.innerHTML = fixRow({}, true); box.insertBefore(row.firstChild, e.target.closest('div')); }
        else if (e.target.dataset && e.target.dataset.x) { e.target.closest('.srf').remove(); }
        else if (e.target.id === 'srSave') {
          const rules = [...box.querySelectorAll('.srf')].map((r) => ({ text: r.querySelector('[data-f="text"]').value.trim(), ml: Number(r.querySelector('[data-f="ml"]').value) || 0, price: Number(r.querySelector('[data-f="price"]').value) || 0, deal_qty: Number(r.querySelector('[data-f="dq"]').value) || 0, deal_price: Number(r.querySelector('[data-f="dp"]').value) || 0 }));
          const m = el.querySelector('#srMsg'); m.textContent = 'Saving…';
          try { const r = await api.post('st_fix.save', { rules }); d.fixed = r.rules; box.innerHTML = fixHtml(d); el.querySelector('#srMsg').textContent = 'Saved ✓'; } catch (er) { m.style.color = 'var(--sx-bad)'; m.textContent = (er && er.message) || 'Could not save.'; }
        }
      });
    } catch (e) { el.innerHTML = '<div class="sx-mut" style="color:var(--sx-bad)">' + esc((e && e.message) || 'Could not read the rules.') + '</div>'; }
  }
  return { mount };
})();
