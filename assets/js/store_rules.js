/* store_rules.js — Store: the commission rules, the price chart and the offers running now, READ-ONLY (they are changed by Admin in Commission Rules / Learning → Pricing).
 *   StoreRules.mount(el)   draws the card into el.   Needs api.js, store_common.js (esc, inr). Talks to st_price.card. */
window.StoreRules = (function () {
  'use strict';
  const f = (n) => Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const dd = (x) => x ? new Date(x + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '';
  function draw(d) {
    const R = d.rules || {}, win = d.window || 20, team = (d.team || []);
    const offers = (d.offers || []).map((o) => `<tr style="border-top:1px solid var(--sx-line)"><td style="padding:5px 8px"><b>${o.ml} ml</b></td><td style="padding:5px 8px">${inr(o.price)} (±₹${f(win)})</td><td style="padding:5px 8px"><b>${f(o.pct)}%</b></td><td style="padding:5px 8px">${dd(o.from)}${o.to ? ' – ' + dd(o.to) : ' → keeps going'}</td><td style="padding:5px 8px">${esc(o.note || '')}</td></tr>`).join('');
    const flash = (d.flash || []).map((x) => `<div class="sx-mut" style="font-size:12.5px">⚡ Flash offer: ${(x.sizes || []).join(' / ')} ml → <b>${f(x.pct)}%</b> till ${esc(String(x.ends_at).slice(11, 16))}</div>`).join('');
    const cols = d.cols || [], rows = d.rows || [];
    const chart = rows.length ? `<div style="overflow:auto"><table style="border-collapse:collapse;font-size:12.5px;min-width:420px"><thead><tr><th style="text-align:left;padding:4px 8px">ML</th>${cols.map((c) => `<th style="padding:4px 8px;text-align:right">pays ${f(c)}%</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr style="border-top:1px solid var(--sx-line)"><td style="padding:4px 8px"><b>${r.ml}</b></td>${cols.map((c, i) => `<td style="padding:4px 8px;text-align:right">${r.p[i] > 0 ? inr(r.p[i]) : '–'}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : '<div class="sx-mut">The price chart is not available.</div>';
    return `<div class="sx-mut" style="font-size:12.5px;margin-bottom:8px">You can only look at these here. ${d.can_edit ? 'To change them: Commission Rules and Learning → Pricing.' : 'An Admin changes them in Commission Rules and Learning → Pricing.'}</div>
      <div style="display:grid;gap:8px;font-size:13px;line-height:1.5">
        <div><b>📋 Order code</b> — on a day, a person needs an order code on at least <b>${f(R.codepct || 0)}%</b> of their bills${team.length > 1 ? ` (<b>${esc(team.join(', '))}</b> are counted as one team)` : ''}. Reached: every bill pays its full %. Not reached: a bill with no code pays <b>${f(R.nocode || 0)}%</b> of its normal commission.</div>
        <div><b>💰 Price → %</b> — the price the customer paid goes to the nearest price column of that size (table below); below the last column it pays <b>${f(d.floor || 0)}%</b>.</div>
        <div><b>🎁 Offers</b> — a bill with exactly the offer’s total size and a price within ±₹${f(win)} of the offer price pays the <b>offer’s %</b> instead of the chart. The same offers the online sales get, on the day they ran.</div>
        <div><b>🏬 Store</b> — same rules as online, but no over-target extra and no boost.</div></div>
      <h4 style="margin:12px 0 4px">Offers running now</h4>
      ${offers ? `<div style="overflow:auto"><table style="border-collapse:collapse;font-size:12.5px"><thead><tr><th style="text-align:left;padding:4px 8px">Size</th><th style="text-align:left;padding:4px 8px">Price</th><th style="text-align:left;padding:4px 8px">Pays</th><th style="text-align:left;padding:4px 8px">Runs</th><th style="text-align:left;padding:4px 8px">Note</th></tr></thead><tbody>${offers}</tbody></table></div>` : '<div class="sx-mut" style="font-size:12.5px">No offer is running now — the normal price chart applies.</div>'}${flash}
      <details class="sx-d" style="margin-top:10px"><summary>💰 The price chart (what each size pays)</summary><div style="margin-top:8px">${chart}</div></details>`;
  }
  async function mount(el) {
    el.innerHTML = '<div class="sx-mut">Loading…</div>';
    try { el.innerHTML = draw(await api.get('st_price.card')); } catch (e) { el.innerHTML = '<div class="sx-mut" style="color:var(--sx-bad)">' + esc((e && e.message) || 'Could not read the rules.') + '</div>'; }
  }
  return { mount };
})();
