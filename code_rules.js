/**
 * Order code rules — the "Order code rules" section on the Commission Rules page.
 *
 * layout.js loads this file on commission_rules.html only, and it adds its own
 * card at the end of the page's content area, so the page itself needs no edit.
 *
 * What the rules do: an order's commission is the normal commission (from the
 * slabs above) times a share. A rule says "orders with no code / with a code /
 * with a code starting with X pay N% of the normal commission (or a flat ₹)".
 * A rule can also pay a fixed rate % instead (e.g. "no code → 3%"), and can be
 * limited to employees who hit / missed their target and / or whose average
 * ticket is below / above an amount ("only when").
 * Rules are checked top to bottom, the first switched-on match wins, and a
 * master switch turns the whole thing off. The Commission page applies them.
 */
(function () {
  if (window.__codeRulesLoaded) return;
  window.__codeRulesLoaded = true;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const num = (v) => String(Number(v)).replace(/\.0+$/, '');

  const css = `
#crc .card-head > div:first-child{flex:1 1 420px; min-width:0;}
#crc .sub2{margin:3px 0 0; color:var(--muted); font-size:12.5px; line-height:1.45;}
#crc .chip{display:inline-block; font-size:11px; font-weight:800; letter-spacing:.04em; padding:2px 8px; border-radius:20px; background:#fff1e8; color:#e8651a; vertical-align:middle; margin-left:6px; text-transform:uppercase;}
#crc .sw{display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-weight:700; font-size:13px; user-select:none; position:relative;}
#crc .sw input, .crc-modal .sw input{position:absolute; opacity:0; width:0; height:0;}
#crc .sw i, .crc-modal .sw i{width:44px; height:26px; border-radius:20px; background:#d6d9df; position:relative; transition:.2s; flex:none; display:inline-block;}
#crc .sw i::after, .crc-modal .sw i::after{content:""; position:absolute; top:3px; left:3px; width:20px; height:20px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.25); transition:.2s;}
#crc .sw input:checked + i, .crc-modal .sw input:checked + i{background:var(--accent);}
#crc .sw input:checked + i::after, .crc-modal .sw input:checked + i::after{left:21px;}
#crc .sw input:focus-visible + i, .crc-modal .sw input:focus-visible + i{outline:2px solid rgba(255,122,47,.5); outline-offset:2px;}
#crc .sw.master{background:#f6f7f9; padding:8px 14px; border-radius:999px;}
#crc .off-note{margin:0 22px 8px; padding:9px 14px; border-radius:14px; background:#fff8ec; color:#8a5a00; font-size:12.5px;}
#crc .rules{padding:4px 22px 6px;}
#crc .rule{display:grid; grid-template-columns:34px minmax(0,1fr) 170px 130px 60px 130px; gap:14px; align-items:center; padding:16px 0; border-top:1px solid var(--line);}
#crc .rule.off .r-main, #crc .rule.off .r-pay, #crc .rule.off .r-scope{opacity:.45;}
#crc .ord{display:flex; flex-direction:column; gap:2px;}
#crc .ord button{border:none; background:#f1f3f6; border-radius:6px; font-size:11px; height:16px; color:#6f7480; cursor:pointer; padding:0; min-height:0;}
#crc .ord button:disabled{opacity:.35; cursor:default;}
#crc .r-name{font-weight:600; font-size:14.5px;}
#crc .r-name b{background:#fff1e8; color:#c2500f; padding:1px 8px; border-radius:8px;}
#crc .r-sub{color:var(--muted); font-size:12px; margin-top:2px;}
#crc .pct{font-size:26px; font-weight:800; letter-spacing:-.02em; line-height:1.1;}
#crc .r-pay small{display:block; color:var(--muted); font-size:11.5px;}
#crc .pill{display:inline-block; background:#f1f3f6; color:#3a3f4a; font-weight:700; font-size:12px; padding:5px 12px; border-radius:999px;}
#crc .r-act{display:flex; gap:6px; justify-content:flex-end;}
#crc .mini{background:#fff; border:1px solid var(--line); border-radius:999px; padding:5px 12px; font-size:12px; font-weight:700; cursor:pointer; min-height:0; color:var(--ink);}
#crc .mini.del{color:#d9423b; border-color:#f6cfcb;}
#crc .addrow{display:flex; align-items:center; gap:16px; padding:14px 22px 20px; border-top:1px solid var(--line); flex-wrap:wrap;}
#crc .empty{padding:26px; text-align:center; color:var(--muted);}
#crc .pv{display:grid; grid-template-columns:1fr; gap:0; background:#14161a; color:#fff; border-radius:20px; margin:0 22px 20px; padding:16px 20px;}
#crc .pv h4{margin:0 0 8px; font-size:12.5px; color:#a7acb8; font-weight:700;}
#crc .pv .in{display:flex; flex-wrap:wrap; gap:14px; align-items:center; font-size:13px; color:#cfd3dc;}
#crc .pv .in input{width:96px; padding:6px 10px; border-radius:12px; border:1px solid #3a3f4a; background:#1d2026; color:#fff; font:inherit; text-align:right;}
#crc .pv .out{display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px;}
#crc .pv .out small{color:#a7acb8;}
#crc .pv .big{font-size:28px; font-weight:800; letter-spacing:-.02em;}
#crc .pv .big.org{color:#ff9a5c;}
.crc-modal .seg{display:inline-flex; background:#f1f3f6; border-radius:999px; padding:4px; gap:2px; flex-wrap:wrap;}
.crc-modal .seg button{border:none; background:none; border-radius:999px; padding:8px 14px; font-weight:700; font-size:13px; cursor:pointer; color:#6f7480; min-height:0;}
.crc-modal .seg button.on{background:#fff; color:var(--ink); box-shadow:0 1px 3px rgba(0,0,0,.12);}
.crc-modal .seg.sm button{padding:6px 12px; font-size:12px;}
.crc-modal .payrow{display:flex; align-items:center; gap:8px; margin-top:10px;}
.crc-modal .payrow input{width:110px; text-align:center; font-size:20px; font-weight:800; padding:6px; border-radius:14px;}
.crc-modal .payrow span{font-size:20px; font-weight:800;}
.crc-modal .hint{margin-top:8px; font-size:12.5px; color:var(--muted); background:#f6f7f9; border-radius:12px; padding:8px 12px;}
.crc-modal .chips{display:flex; gap:6px; flex-wrap:wrap;}
.crc-modal .chips button{border:none; background:#f1f3f6; color:#3a3f4a; font-weight:700; font-size:12px; padding:6px 12px; border-radius:999px; cursor:pointer; min-height:0;}
.crc-modal .chips button.on{background:#14161a; color:#fff;}
.crc-modal .sw{display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-weight:700; font-size:13px; margin-top:6px; position:relative;}
@media (max-width:820px){
  #crc .card-head > div:first-child{flex:0 0 auto;}
  #crc .rule{grid-template-columns:30px minmax(0,1fr) auto; gap:8px 12px;}
  #crc .r-pay{grid-column:2; grid-row:2;}
  #crc .r-scope{grid-column:2; grid-row:3;}
  #crc .rule > .sw{grid-column:3; grid-row:1; justify-self:end;}
  #crc .r-act{grid-column:2 / 4; grid-row:4; justify-content:flex-start;}
  #crc .rules, #crc .addrow{padding-left:14px; padding-right:14px;}
  #crc .pv{margin-left:14px; margin-right:14px;}
}`;

  let ON = false, RULES = [], HOUSES = {}, EDIT = null;

  const mount = document.querySelector('.content') || document.querySelector('.main') || document.body;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const card = document.createElement('div');
  card.id = 'crc'; card.className = 'card';
  card.innerHTML = '<div class="loading">Loading order code rules…</div>';
  mount.appendChild(card);

  const modal = document.createElement('div');
  modal.className = 'modal-overlay crc-modal';
  modal.innerHTML = `<div class="modal">
    <h3 id="crcMTitle">Add order code rule</h3>
    <div id="crcMAlert"></div>
    <div class="field"><label for="crcName">Rule name <span style="text-transform:none; font-weight:400;">(optional)</span></label><input id="crcName" maxlength="120" placeholder="e.g. Orders without a code"></div>
    <div class="field"><label>When the order…</label>
      <div class="seg" id="crcWhen"><button type="button" data-v="no_code">Has no code</button><button type="button" data-v="has_code">Has a code</button><button type="button" data-v="prefix">Code starts with…</button></div>
      <input id="crcPrefix" maxlength="20" placeholder="e.g. AG" style="margin-top:10px; text-transform:uppercase; display:none;">
    </div>
    <div class="field"><label>Only when <span style="text-transform:none; font-weight:400;">(optional — about the employee's target and ticket size)</span></label>
      <div style="font-size:12px; color:var(--muted); margin:2px 0 6px;">Target</div>
      <div class="seg sm" id="crcTarget"><button type="button" data-v="any">Any</button><button type="button" data-v="hit">Target achieved</button><button type="button" data-v="miss">Target not achieved</button></div>
      <div style="font-size:12px; color:var(--muted); margin:10px 0 6px;">Ticket size (average order value)</div>
      <div class="seg sm" id="crcTicket"><button type="button" data-v="any">Any</button><button type="button" data-v="lt">Below ₹…</button><button type="button" data-v="gte">₹… or more</button></div>
      <input id="crcTicketVal" type="number" min="0" step="1" inputmode="numeric" placeholder="e.g. 2300" style="margin-top:10px; width:140px; display:none;">
    </div>
    <div class="field"><label>Pays</label>
      <div class="seg sm" id="crcMode"><button type="button" data-v="pct">% of normal commission</button><button type="button" data-v="rate">Fixed rate %</button><button type="button" data-v="flat">Flat ₹ per order</button></div>
      <div class="payrow"><input id="crcVal" type="number" min="0" step="0.01" inputmode="decimal"><span id="crcUnit">%</span></div>
      <div class="hint" id="crcHint"></div>
    </div>
    <div class="field"><label>Applies to <span style="text-transform:none; font-weight:400;">(none selected = all stores)</span></label><div class="chips" id="crcHouses"></div></div>
    <label class="sw"><input type="checkbox" id="crcEn" checked><i></i><span>Rule is on</span></label>
    <div class="actions"><button type="button" class="btn secondary" id="crcCancel">Cancel</button><button type="button" class="btn" id="crcSave">Save rule</button></div>
  </div>`;
  document.body.appendChild(modal);
  const $m = (id) => modal.querySelector('#' + id);

  const describe = (r) => (r.match_type === 'no_code' ? 'Order has <b>no code</b>'
    : r.match_type === 'has_code' ? 'Order has <b>a code</b>'
    : 'Code starts with <b>“' + esc(r.prefix) + '”</b>') + condText(r);
  const isCond = (r) => (r.when_target || 'any') !== 'any' || (r.when_ticket || 'any') !== 'any';
  function condText(r) {
    const p = [];
    if (r.when_target === 'hit') p.push('target <b>achieved</b>'); else if (r.when_target === 'miss') p.push('target <b>not achieved</b>');
    if (r.when_ticket === 'lt') p.push('ticket <b>below ' + inr(r.when_ticket_val) + '</b>'); else if (r.when_ticket === 'gte') p.push('ticket <b>' + inr(r.when_ticket_val) + ' or more</b>');
    return p.length ? ' · employee: ' + p.join(', ') : '';
  }
  const housePills = (r) => {
    const ids = String(r.houses || '').split(',').filter(Boolean);
    return ids.length ? ids.map((h) => `<span class="pill">${esc(HOUSES[h] || h)}</span>`).join(' ') : '<span class="pill">All stores</span>';
  };

  // The normal-commission share a sample order would get, using only rules that apply to every store.
  function sample(hasCode) {
    for (const r of RULES) {
      if (!Number(r.enabled) || r.houses || isCond(r)) continue;
      if (r.match_type === 'prefix') continue;
      if (r.match_type === 'no_code' && hasCode) continue;
      if (r.match_type === 'has_code' && !hasCode) continue;
      return r;
    }
    return null;
  }
  function pvOut(r, order, rate) {
    const full = order * rate / 100;
    if (!r) return full;
    return r.pay_mode === 'flat' ? Number(r.pay_value) : r.pay_mode === 'rate' ? order * Number(r.pay_value) / 100 : full * Number(r.pay_value) / 100;
  }

  function render() {
    const list = RULES.map((r, i) => `
      <div class="rule ${Number(r.enabled) ? '' : 'off'}" data-id="${r.rule_id}">
        <div class="ord"><button type="button" data-mv="up" ${i === 0 ? 'disabled' : ''} title="Move up">▲</button><button type="button" data-mv="down" ${i === RULES.length - 1 ? 'disabled' : ''} title="Move down">▼</button></div>
        <div class="r-main"><div class="r-name">${esc(r.label) || describe(r)}</div><div class="r-sub">${r.label ? describe(r) : (r.match_type === 'no_code' ? 'Orders where nobody has set an order code' : r.match_type === 'has_code' ? 'Any order that has an order code' : 'Orders whose code begins with this') + condText(r)}</div></div>
        <div class="r-pay">${r.pay_mode === 'flat' ? `<span class="pct">${inr(r.pay_value)}</span><small>flat per order</small>` : r.pay_mode === 'rate' ? `<span class="pct">${num(r.pay_value)}%</span><small>fixed rate (replaces slab rate)</small>` : `<span class="pct">${num(r.pay_value)}%</span><small>of normal commission</small>`}</div>
        <div class="r-scope">${housePills(r)}</div>
        <label class="sw" title="Turn this rule on or off"><input type="checkbox" data-en ${Number(r.enabled) ? 'checked' : ''}><i></i></label>
        <div class="r-act"><button type="button" class="mini" data-edit>Edit</button><button type="button" class="mini del" data-del>Delete</button></div>
      </div>`).join('');
    const wc = sample(true), nc = sample(false);
    const ord = Number(card.dataset.pvOrder || 10000), rate = Number(card.dataset.pvRate || 6.8);
    card.innerHTML = `
      <div class="card-head">
        <div><h2>Order code rules <span class="chip">New</span></h2>
          <p class="sub2">Pay a different share of the commission depending on whether the order has an order code. Rules run top to bottom — the first one that is switched on and matches is used. Orders no rule matches earn the normal commission. A rule can also be limited to employees who hit / missed their target or whose ticket size is below / above an amount.</p></div>
        <label class="sw master"><span>${ON ? 'Order code rules ON' : 'Order code rules OFF'}</span><input type="checkbox" id="crcMaster" ${ON ? 'checked' : ''}><i></i></label>
      </div>
      ${ON ? '' : '<div class="off-note">The rules below are saved but <b>not used</b> — every order earns the normal commission. Switch “Order code rules” on to start using them.</div>'}
      <div class="rules" style="${ON ? '' : 'opacity:.55;'}">${list || '<div class="empty">No rules yet. Add one — for example “no code → 50%”.</div>'}</div>
      <div class="addrow"><button type="button" class="btn" id="crcAdd">+ Add rule</button><span class="sub2" style="margin:0;">Turn a rule off to stop using it — it stays saved, so you can switch it back on any time.</span></div>
      <div class="pv">
        <h4>Live preview <span style="font-weight:400;">(rules that apply to every store and have no “only when” condition)</span></h4>
        <div class="in"><span>Sample order ₹</span><input id="pvOrder" type="number" min="0" value="${ord}"><span>Slab rate %</span><input id="pvRate" type="number" min="0" step="0.1" value="${rate}"></div>
        <div class="out"><div><small>With a code</small><div class="big">${inr(pvOut(ON ? wc : null, ord, rate))}</div></div><div><small>No code</small><div class="big org">${inr(pvOut(ON ? nc : null, ord, rate))}</div></div></div>
      </div>`;
  }

  async function load() {
    try {
      const d = await api.get('commission_rules.code_list');
      ON = !!d.on; RULES = d.rules || []; HOUSES = d.houses || {};
      render();
    } catch (err) {
      card.innerHTML = `<div class="card-head"><h2>Order code rules</h2></div><div class="alert error" style="margin:0 22px 18px;">${esc(err.message)}</div>`;
    }
  }
  const flash = (msg, type) => {
    let box = card.querySelector('.crc-flash');
    if (!box) { box = document.createElement('div'); box.className = 'crc-flash'; box.style.margin = '0 22px 10px'; card.querySelector('.card-head').after(box); }
    box.innerHTML = `<div class="alert ${type || 'error'}" style="margin:0;">${esc(msg)}</div>`;
    if (type === 'success') setTimeout(() => { if (box.parentNode) box.remove(); }, 2500);
  };

  card.addEventListener('change', async (e) => {
    if (e.target.id === 'crcMaster') {
      const on = e.target.checked;
      try { await api.post('commission_rules.code_master', { on }); ON = on; render(); }
      catch (err) { e.target.checked = !on; flash(err.message); }
      return;
    }
    if (e.target.matches('[data-en]')) {
      const row = e.target.closest('.rule'), id = Number(row.dataset.id), en = e.target.checked;
      try { await api.post('commission_rules.code_toggle', { rule_id: id, enabled: en }); const r = RULES.find((x) => x.rule_id === id); if (r) r.enabled = en ? 1 : 0; render(); }
      catch (err) { e.target.checked = !en; flash(err.message); }
    }
  });
  card.addEventListener('input', (e) => {
    if (e.target.id === 'pvOrder' || e.target.id === 'pvRate') {
      card.dataset.pvOrder = card.querySelector('#pvOrder').value || 0;
      card.dataset.pvRate = card.querySelector('#pvRate').value || 0;
      const ord = Number(card.dataset.pvOrder), rate = Number(card.dataset.pvRate);
      const outs = card.querySelectorAll('.pv .big');
      outs[0].textContent = inr(pvOut(ON ? sample(true) : null, ord, rate));
      outs[1].textContent = inr(pvOut(ON ? sample(false) : null, ord, rate));
    }
  });
  card.addEventListener('click', async (e) => {
    if (e.target.id === 'crcAdd') return openModal(null);
    const row = e.target.closest('.rule'); if (!row) return;
    const id = Number(row.dataset.id), r = RULES.find((x) => x.rule_id === id); if (!r) return;
    if (e.target.closest('[data-edit]')) return openModal(r);
    if (e.target.closest('[data-del]')) {
      if (!confirm('Delete this rule? You can also just switch it off to keep it saved.')) return;
      try { await api.post('commission_rules.code_delete', { rule_id: id }); await load(); } catch (err) { flash(err.message); }
      return;
    }
    const mv = e.target.closest('[data-mv]');
    if (mv && !mv.disabled) {
      try { await api.post('commission_rules.code_move', { rule_id: id, dir: mv.dataset.mv }); await load(); } catch (err) { flash(err.message); }
    }
  });

  // ---- Add / edit popup ----
  function setSeg(box, v) { [...box.children].forEach((b) => b.classList.toggle('on', b.dataset.v === v)); }
  const segVal = (box) => { const b = box.querySelector('.on'); return b ? b.dataset.v : ''; };
  function hint() {
    const mode = segVal($m('crcMode')), v = Number($m('crcVal').value || 0);
    $m('crcUnit').textContent = mode === 'flat' ? '₹' : '%';
    $m('crcHint').innerHTML = mode === 'flat'
      ? `Every matching order pays a fixed <b>${inr(v)}</b>, whatever its amount.`
      : mode === 'rate'
        ? `Matching orders pay <b>${num(v)}%</b> of the order amount, whatever the employee's slab rate is. Example: ₹10,000 order → <b>${inr(10000 * v / 100)}</b>. Use 0 to pay nothing.`
        : `Example: ₹10,000 order at a 6.8% slab → pays <b>${inr(10000 * 6.8 / 100 * v / 100)}</b> instead of ${inr(680)}`;
  }
  function openModal(r) {
    EDIT = r;
    $m('crcMTitle').textContent = r ? 'Edit order code rule' : 'Add order code rule';
    $m('crcMAlert').innerHTML = '';
    $m('crcName').value = r ? r.label : '';
    setSeg($m('crcWhen'), r ? r.match_type : 'no_code');
    $m('crcPrefix').value = r ? r.prefix : '';
    $m('crcPrefix').style.display = (r && r.match_type === 'prefix') ? '' : 'none';
    setSeg($m('crcTarget'), r ? (r.when_target || 'any') : 'any');
    setSeg($m('crcTicket'), r ? (r.when_ticket || 'any') : 'any');
    $m('crcTicketVal').value = r && Number(r.when_ticket_val) > 0 ? num(r.when_ticket_val) : '';
    $m('crcTicketVal').style.display = (r && (r.when_ticket || 'any') !== 'any') ? '' : 'none';
    setSeg($m('crcMode'), r ? r.pay_mode : 'pct');
    $m('crcVal').value = r ? num(r.pay_value) : 50;
    const sel = r ? String(r.houses || '').split(',').filter(Boolean) : [];
    $m('crcHouses').innerHTML = Object.keys(HOUSES).map((h) => `<button type="button" data-h="${esc(h)}" class="${sel.includes(h) ? 'on' : ''}">${esc(HOUSES[h])}</button>`).join('');
    $m('crcEn').checked = r ? !!Number(r.enabled) : true;
    hint();
    modal.classList.add('open');
  }
  const closeModal = () => modal.classList.remove('open');
  $m('crcCancel').onclick = closeModal;
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  $m('crcWhen').onclick = (e) => { const b = e.target.closest('button'); if (!b) return; setSeg($m('crcWhen'), b.dataset.v); $m('crcPrefix').style.display = b.dataset.v === 'prefix' ? '' : 'none'; };
  $m('crcTarget').onclick = (e) => { const b = e.target.closest('button'); if (b) setSeg($m('crcTarget'), b.dataset.v); };
  $m('crcTicket').onclick = (e) => { const b = e.target.closest('button'); if (!b) return; setSeg($m('crcTicket'), b.dataset.v); $m('crcTicketVal').style.display = b.dataset.v === 'any' ? 'none' : ''; if (b.dataset.v !== 'any') $m('crcTicketVal').focus(); };
  $m('crcMode').onclick = (e) => { const b = e.target.closest('button'); if (!b) return; setSeg($m('crcMode'), b.dataset.v); hint(); };
  $m('crcVal').oninput = hint;
  $m('crcHouses').onclick = (e) => { const b = e.target.closest('button'); if (b) b.classList.toggle('on'); };
  $m('crcSave').onclick = async () => {
    const btn = $m('crcSave'); btn.disabled = true; $m('crcMAlert').innerHTML = '';
    try {
      await api.post('commission_rules.code_save', {
        rule_id: EDIT ? EDIT.rule_id : 0,
        label: $m('crcName').value.trim(),
        match_type: segVal($m('crcWhen')),
        prefix: $m('crcPrefix').value.trim(),
        pay_mode: segVal($m('crcMode')),
        when_target: segVal($m('crcTarget')) || 'any',
        when_ticket: segVal($m('crcTicket')) || 'any',
        when_ticket_val: $m('crcTicketVal').value,
        pay_value: $m('crcVal').value,
        houses: [...$m('crcHouses').querySelectorAll('.on')].map((b) => b.dataset.h),
        enabled: $m('crcEn').checked,
      });
      closeModal(); await load(); flash('Rule saved.', 'success');
    } catch (err) {
      $m('crcMAlert').innerHTML = `<div class="alert error">${esc(err.message)}</div>`;
    } finally { btn.disabled = false; }
  };

  load();
})();
