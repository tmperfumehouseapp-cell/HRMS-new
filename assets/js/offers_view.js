/* 🏷 Offers — a READ-ONLY list of the running commission offers for the sales team (no edit, no stop).
 * OffersView.load() → Promise of the HTML for the body of a sheet; OffersView.wire(el) makes its ML filter work.
 * Data: ml_slab.get (offers + offer_window + chart.prepaid / chart.nocode), the same data the Commission page uses. */
(function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = (n) => '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN');
  const pc = (n) => String(Math.round(Number(n) * 100) / 100);
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const dl = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || '')); return m ? (+m[3]) + ' ' + MON[+m[2] - 1] : ''; };
  const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  let CSS = false;
  function css() {
    if (CSS) return; CSS = true; const st = document.createElement('style');
    st.textContent = '.ofv-n{background:rgba(255,152,0,.14);color:#b25e00;border-radius:10px;padding:8px 10px;font-size:12px;margin:2px 0 8px;line-height:1.4}'
      + '.ofv-ch{display:flex;gap:6px;flex-wrap:wrap;padding:2px 0 8px}.ofv-ch button{border:1.5px solid rgba(128,128,128,.35);background:transparent;color:inherit;border-radius:16px;padding:5px 10px;font-size:12.5px;font-weight:700;cursor:pointer}.ofv-ch button.on{background:#00a884;color:#fff;border-color:#00a884}'
      + '.ofv-r{border:1.5px solid rgba(128,128,128,.3);border-radius:14px;padding:10px 12px;margin-bottom:8px;display:flex;align-items:center;gap:10px}.ofv-ml{font-weight:900;font-size:17px;min-width:64px}.ofv-m{flex:1;min-width:0}.ofv-p{font-weight:800}.ofv-g{font-size:12px;opacity:.7}'
      + '.ofv-pc{background:rgba(0,168,132,.15);color:#00a884;font-weight:900;border-radius:10px;padding:6px 9px;font-size:15px;text-align:center;flex:none}.ofv-pc small{display:block;font-size:9.5px;font-weight:700;opacity:.75}'
      + '.ofv-nw{background:rgba(255,152,0,.2);color:#b25e00;border-radius:7px;font-size:10px;font-weight:800;padding:1px 6px;margin-left:6px}.ofv-e{padding:24px 8px;text-align:center;opacity:.7}';
    document.head.appendChild(st);
  }
  async function load() {
    css(); let d;
    try { d = await api.get('ml_slab.get'); } catch (e) { return '<div class="ofv-e">Could not load the offers — ' + esc((e && e.message) || 'try again') + '</div>'; }
    const W = Number(d && d.offer_window) || 110, ch = (d && d.chart) || {}, pre = Number(ch.prepaid) || 0, nc = ch.nocode != null && ch.nocode !== '' ? Number(ch.nocode) : 50;
    const today = iso(new Date()), y = new Date(); y.setDate(y.getDate() - 2); const newFrom = iso(y);
    const list = ((d && d.offers) || []).filter((o) => Number(o.active) && (!o.from || today >= String(o.from).slice(0, 10)) && (!o.to || today <= String(o.to).slice(0, 10)))
      .sort((a, b) => String(b.from || '').localeCompare(String(a.from || '')) || Number(a.ml) - Number(b.ml));
    if (!list.length) return '<div class="ofv-e">No offer is running right now.</div>';
    const rows = list.map((o) => { const p = Number(o.price), isNew = o.from && String(o.from).slice(0, 10) >= newFrom;
      return '<div class="ofv-r" data-ml="' + Math.round(Number(o.ml)) + '"><div class="ofv-ml">' + Math.round(Number(o.ml)) + ' ml</div><div class="ofv-m"><div class="ofv-p">' + rs(p) + (isNew ? '<span class="ofv-nw">NEW</span>' : '') + '</div><div class="ofv-g">' + rs(p - W) + ' – ' + rs(p + W) + (o.from ? ' · since ' + dl(o.from) : '') + (o.to ? ' · till ' + dl(o.to) : '') + '</div></div><div class="ofv-pc">' + pc(o.pct) + '%<small>with code</small>' + (nc < 100 ? '<small>' + pc(Number(o.pct) * nc / 100) + '% no code</small>' : '') + '</div></div>'; }).join('');
    const bands = [['', 'All'], ['a', '≤ 100 ml'], ['b', '101–200'], ['c', '201–300'], ['d', '301+']];
    return '<div class="ofv-n">Sell inside the price range to get the offer %.' + (pre > 0 ? ' For a prepaid order the price BEFORE the ' + pc(pre) + '% prepaid discount counts.' : '') + '</div>'
      + '<div class="ofv-ch">' + bands.map((b, i) => '<button type="button" data-b="' + b[0] + '"' + (i === 0 ? ' class="on"' : '') + '>' + b[1] + '</button>').join('') + '</div><div class="ofv-l">' + rows + '</div>';
  }
  function wire(root) {
    const band = (ml) => (ml <= 100 ? 'a' : ml <= 200 ? 'b' : ml <= 300 ? 'c' : 'd');
    root.addEventListener('click', (e) => { const b = e.target.closest('.ofv-ch button'); if (!b) return; root.querySelectorAll('.ofv-ch button').forEach((x) => x.classList.toggle('on', x === b));
      root.querySelectorAll('.ofv-r').forEach((r) => { r.style.display = !b.dataset.b || band(+r.dataset.ml) === b.dataset.b ? '' : 'none'; }); });
  }
  window.OffersView = { load, wire };
})();
