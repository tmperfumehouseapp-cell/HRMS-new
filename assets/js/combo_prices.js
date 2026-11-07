/**
 * Combo price chart — the editor card on the Commission Rules page (layout.js loads this file there, after ml_slab_rules.js).
 *
 * Shower gel / attar / perfume combos, each priced at the five commission tiers: 7% chart price, 6.5% offer, 6% campaign, 5.5% and the
 * 2.8% floor. Every cell is the LOWEST price that still earns that %. The prices come from the "Base prices" boxes (first / extra piece of
 * each item, the ₹ off for a 3-kind mix, the tier drops in % below the chart price) with "Rebuild", and every cell can also be typed by hand.
 * Saved through combo_price.save, read through combo_price.get, rebuilt through combo_price.rebuild (api_alloc.php, combo_price_*()).
 */
(function () {
  if (window.__comboPricesLoaded) return;
  window.__comboPricesLoaded = true;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (v) => (v === '' || v == null || isNaN(Number(v)) ? '' : Number(v));
  const fmt = (v) => Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

  const css = `
#cmb{margin-top:20px; background:#fff; border:1px solid var(--line); border-radius:var(--radius, 18px); padding:18px 22px 20px;}
#cmb h3{margin:0 0 4px; font-size:17px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
#cmb .rsub{font-size:12.5px; color:var(--muted); line-height:1.5; margin:0 0 14px; max-width:860px;}
#cmb .msg{margin:0 0 12px;}
#cmb .base{border:1px solid #e6e9ee; background:#fafbfc; border-radius:14px; padding:12px 14px; margin-bottom:14px;}
#cmb .base b.t{display:block; font-size:13px; margin-bottom:8px;}
#cmb .bgrid{display:flex; flex-wrap:wrap; gap:10px 18px; align-items:center; font-size:13px;}
#cmb .bgrid .bi{display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;}
#cmb .bgrid input{width:78px; padding:6px 8px; min-height:0; border:1px solid var(--line); border-radius:8px; font-size:13px;}
#cmb .bgrid input.dp{width:62px;}
#cmb .base small{display:block; margin-top:8px; font-size:11.5px; color:var(--muted); line-height:1.45;}
#cmb .wrap{overflow-x:auto; border:1px solid var(--line); border-radius:14px; -webkit-overflow-scrolling:touch;}
#cmb table.ct{width:100%; min-width:760px; border-collapse:collapse; font-size:13px;}
#cmb table.ct th{padding:9px 6px; font-size:11px; text-align:center; color:#fff; white-space:nowrap;}
#cmb table.ct th.nm{background:#fff; color:#64748b; text-align:left; padding-left:12px; text-transform:uppercase; letter-spacing:.04em;}
#cmb table.ct th.cn{background:#fff; color:#64748b;}
#cmb table.ct th small{display:block; font-weight:500; font-size:11px; opacity:.9;}
#cmb table.ct th.h0{background:#14161a;} #cmb table.ct th.h1{background:#2a2e36;} #cmb table.ct th.h2{background:#ff7a2f;} #cmb table.ct th.h3{background:#e8651a;} #cmb table.ct th.h4{background:#8a3b12;}
#cmb table.ct td{padding:6px 5px; border-top:1px solid #eef0f3; text-align:center;}
#cmb table.ct td.nm{text-align:left; padding-left:10px;}
#cmb table.ct tr.g td{background:#fff3ea; color:#e8651a; font-weight:800; font-size:11.5px; letter-spacing:.06em; text-align:left; padding:7px 12px; border-top:1px solid #fde0cc;}
#cmb table.ct input{padding:6px 6px; min-height:0; border:1px solid var(--line); border-radius:8px; font-size:13px; text-align:center; width:74px; font-variant-numeric:tabular-nums;}
#cmb table.ct input.cnt{width:46px;}
#cmb table.ct input.nmi{width:100%; min-width:170px; text-align:left;}
#cmb table.ct input.p0{font-weight:800; background:#f4f5f7;}
#cmb table.ct input.bad{border-color:#e11d48; background:#fff1f2;}
#cmb table.ct .x{border:1px solid var(--line); background:#fff; border-radius:999px; width:26px; height:26px; cursor:pointer; padding:0; min-height:0; line-height:1;}
#cmb table.ct .x:hover{color:#b91c1c; border-color:#f1b4b4;}
#cmb .foot{display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:12px;}
#cmb .foot .btn{width:auto; padding:9px 20px; min-height:0;}
#cmb .foot .who{font-size:12px; color:var(--muted);}
#cmb .dirty{font-size:11px; font-weight:800; background:#fef3c7; color:#92400e; border-radius:999px; padding:3px 10px;}
@media (max-width:640px){ #cmb{padding:14px 12px;} #cmb .bgrid{gap:10px 12px;} #cmb .foot .btn{flex:1 1 140px;} }
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const mount = document.querySelector('.content') || document.querySelector('.main') || document.body;
  const root = document.createElement('div'); root.id = 'cmb';
  root.innerHTML = '<div style="font-size:13px; color:var(--muted);">Loading the combo prices…</div>';
  mount.appendChild(root);

  let C = null, DEF = null, dirty = false, busy = false;
  const $ = (id) => root.querySelector('#' + id);
  const flash = (html, kind) => { const m = $('cmbMsg'); if (m) m.innerHTML = html ? `<div class="alert ${kind || 'success'}">${html}</div>` : ''; };

  async function load() {
    try {
      const d = await api.get('combo_price.get');
      if (d.error) throw new Error(d.error);
      C = JSON.parse(JSON.stringify(d.chart)); DEF = d.defaults || null; dirty = false;
      render();
    } catch (e) { root.innerHTML = `<div class="alert error">The combo prices could not be loaded (${esc(e.message)}). Is the new api.php uploaded?</div>`; }
  }

  function baseHtml() {
    const b = C.base, d = b.drops;
    const pair = (k, label) => `<span class="bi"><b>${label}</b> first ₹<input type="number" min="0" step="1" data-b="${k}" data-i="0" value="${esc(b[k][0])}"> each extra ₹<input type="number" min="0" step="1" data-b="${k}" data-i="1" value="${esc(b[k][1])}"></span>`;
    return `<div class="base"><b class="t">Base prices (₹) and tier steps</b>
      <div class="bgrid">${pair('gel', 'Shower gel')}${pair('attar', 'Attar')}${pair('perf', 'Perfume 50 ml')}
        <span class="bi"><b>3 kinds in one combo</b> ₹<input type="number" min="0" step="1" id="cmbMix" value="${esc(b.mix_off)}"> off</span></div>
      <div class="bgrid" style="margin-top:10px;"><span class="bi"><b>Price steps below the 7% price:</b></span>
        ${['6.5%', '6%', '5.5%', '2.8% floor'].map((l, i) => `<span class="bi">${l} <input class="dp" type="number" min="0" max="90" step="0.5" data-d="${i + 1}" value="${esc(d[i + 1])}"> % less</span>`).join('')}
        <button type="button" class="btn secondary" id="cmbRebuild" style="width:auto; min-height:0; padding:7px 16px;">Rebuild prices from these</button></div>
      <small>Each extra piece costs the "each extra" price, so 2 + 2 = first gel + first perfume + extra gel + extra perfume. "Rebuild" re-writes every price below (nothing is saved until you press Save). You can still type any price by hand afterwards.</small></div>`;
  }

  function tableHtml() {
    let h = '', last = null;
    C.rows.forEach((r, i) => {
      if (r.group !== last) { h += `<tr class="g"><td colspan="10">${esc((r.group || 'Other').toUpperCase())}</td></tr>`; last = r.group; }
      h += `<tr data-i="${i}"><td class="nm"><input class="nmi" data-f="name" value="${esc(r.name)}"></td>
        ${['gel', 'attar', 'perf'].map((k) => `<td><input class="cnt" type="number" min="0" max="50" step="1" data-f="${k}" value="${esc(r[k])}"></td>`).join('')}
        ${r.p.map((v, j) => `<td><input class="${j === 0 ? 'p0' : ''}" type="number" min="0" step="1" data-p="${j}" value="${esc(v)}"></td>`).join('')}
        <td><button type="button" class="x" data-del="${i}" title="Delete this combo">×</button></td></tr>`;
    });
    return `<div class="wrap"><table class="ct"><thead><tr>
      <th class="nm">Combo</th><th class="cn">Gel</th><th class="cn">Attar</th><th class="cn">Perfume</th>
      <th class="h0">7%<small>Chart price</small></th><th class="h1">6.5%<small>Offer price</small></th><th class="h2">6%<small>Campaign price</small></th><th class="h3">5.5%<small>Lower</small></th><th class="h4">2.8%<small>Floor (lowest)</small></th><th></th></tr></thead>
      <tbody>${h}</tbody></table></div>`;
  }

  function render() {
    root.innerHTML = `
      <h3>🧴 Combo price chart ${dirty ? '<span class="dirty">unsaved changes</span>' : ''}</h3>
      <p class="rsub">Shower gel, attar and perfume combos at each commission tier. Every price is the <b>lowest price that still earns that %</b>: sell at the chart price for 7%, at the offer price for 6.5%, at the campaign price for 6%, and so on down to the floor. Below the floor price a combo earns nothing and needs approval. Without an order code the commission is halved, as everywhere else.</p>
      <div id="cmbMsg" class="msg"></div>
      ${baseHtml()}
      ${tableHtml()}
      <div class="foot">
        <button type="button" class="btn secondary" id="cmbAdd">+ Add a combo</button>
        <button type="button" class="btn" id="cmbSave">Save combo prices</button>
        <button type="button" class="btn secondary" id="cmbReset">Reset to the standard chart</button>
        <span class="who">${C.updated_at ? 'Last saved ' + esc(C.updated_at) + (C.updated_by ? ' by ' + esc(C.updated_by) : '') : 'Standard chart (not changed yet)'}</span>
      </div>`;
    wire();
  }

  function markDirty() { if (dirty) return; dirty = true; const h = root.querySelector('h3'); if (h && !h.querySelector('.dirty')) h.insertAdjacentHTML('beforeend', '<span class="dirty">unsaved changes</span>'); }

  function checkRow(tr) {
    const ins = [...tr.querySelectorAll('input[data-p]')];
    ins.forEach((el, j) => {
      const prev = j ? Number(ins[j - 1].value) : Infinity;
      el.classList.toggle('bad', j > 0 && Number(el.value) > prev);
    });
  }

  function wire() {
    root.querySelectorAll('input[data-b]').forEach((el) => el.addEventListener('input', () => { C.base[el.dataset.b][Number(el.dataset.i)] = num(el.value); markDirty(); }));
    root.querySelectorAll('input[data-d]').forEach((el) => el.addEventListener('input', () => { C.base.drops[Number(el.dataset.d)] = num(el.value); markDirty(); }));
    const mix = $('cmbMix'); if (mix) mix.addEventListener('input', () => { C.base.mix_off = num(mix.value); markDirty(); });
    root.querySelectorAll('tbody tr[data-i]').forEach((tr) => {
      const i = Number(tr.dataset.i);
      tr.querySelectorAll('input').forEach((el) => el.addEventListener('input', () => {
        if (el.dataset.f === 'name') C.rows[i].name = el.value;
        else if (el.dataset.f) C.rows[i][el.dataset.f] = num(el.value) === '' ? 0 : Number(el.value);
        else if (el.dataset.p != null) { C.rows[i].p[Number(el.dataset.p)] = num(el.value); checkRow(tr); }
        markDirty();
      }));
      checkRow(tr);
    });
    root.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => {
      const r = C.rows[Number(b.dataset.del)];
      if (!confirm('Delete "' + r.name + '"?')) return;
      C.rows.splice(Number(b.dataset.del), 1); dirty = true; render();
    }));
    $('cmbAdd').addEventListener('click', () => {
      C.rows.push({ id: '', group: 'Custom', name: '', gel: 0, attar: 0, perf: 0, p: ['', '', '', '', ''] });
      dirty = true; render();
      const t = root.querySelectorAll('tbody tr[data-i]'); const l = t[t.length - 1]; if (l) { l.scrollIntoView({ block: 'center', behavior: 'smooth' }); const f = l.querySelector('input'); if (f) f.focus(); }
    });
    $('cmbRebuild').addEventListener('click', async () => {
      if (busy) return;
      if (!confirm('Re-write every price in the chart from the base prices above? Prices you typed by hand will be replaced (not saved until you press Save).')) return;
      busy = true;
      try {
        const d = await api.post('combo_price.rebuild', { base: C.base });
        if (d.error) throw new Error(d.error);
        C.base = d.base; C.rows = d.rows; dirty = true; render(); flash('Prices rebuilt from the base prices. Press <b>Save combo prices</b> to keep them.', 'success');
      } catch (e) { flash(esc(e.message || 'Could not rebuild.'), 'error'); }
      busy = false;
    });
    $('cmbReset').addEventListener('click', () => {
      if (!DEF) return;
      if (!confirm('Go back to the standard combo chart? Your changes in this card are lost (not saved until you press Save).')) return;
      C.base = JSON.parse(JSON.stringify(DEF.base)); C.rows = JSON.parse(JSON.stringify(DEF.rows)); dirty = true; render();
    });
    $('cmbSave').addEventListener('click', async () => {
      if (busy) return;
      for (const r of C.rows) {
        if (!(Number(r.gel) + Number(r.attar) + Number(r.perf) > 0)) { flash('"' + esc(r.name || 'A combo') + '" has no items — put at least one gel, attar or perfume.', 'error'); return; }
        if (r.p.some((v) => v === '' || isNaN(Number(v)))) { flash('"' + esc(r.name || 'A combo') + '": fill all five prices.', 'error'); return; }
      }
      busy = true; const btn = $('cmbSave'); btn.disabled = true;
      try {
        const d = await api.post('combo_price.save', { chart: { base: C.base, rows: C.rows } });
        if (d.error) throw new Error(d.error);
        C = JSON.parse(JSON.stringify(d.chart)); dirty = false; render(); flash(esc(d.message || 'Saved.'), 'success');
      } catch (e) { flash(esc(e.message || 'Could not save.'), 'error'); btn.disabled = false; }
      busy = false;
    });
  }

  load();
})();
