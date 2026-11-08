/* cx_rewards.js — Complaints: ⭐ Reviews tab, 🎁 free-perfume rewards (ml) inside Store credit, and "Give free perfume" on a ticket.
 * Loaded by complaints.html after cx_more.js (it uses CXM.kit()) and before cx_app.js. Talks to bd.cx_rw_* (empmanagment/complaints_rewards.php). */
window.CXRW = (function () {
  'use strict';
  const kit = () => (window.CXM && CXM.kit && CXM.kit()) || null;
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const tel = (p) => (p ? '+91 ' + String(p).replace(/^(\d{5})(\d+)$/, '$1 $2') : 'no number');
  const dd = (s) => { const m = /^(\d{4})-(\d\d)-(\d\d)/.exec(s || ''); return m ? (+m[3]) + ' ' + ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] : ''; };
  const rs = (n) => '₹' + Number(n || 0).toLocaleString('en-IN');
  const KIND = { review: '⭐ Review gift', birthday: '🎂 Birthday gift', goodwill: '🤝 Goodwill' };
  const OFFER = { '': ['No offer', ''], offered: ['Gift offered — waiting for the screenshot', 'o'], shot_wait: ['Screenshot received — reading it', 'b'], check: ['Needs your check', 'r'], paid: ['Gift given ✔', 'g'], no: ['Not given', ''] };
  let blobs = {};

  function css() {
    if (document.getElementById('rwCss')) return; const s = document.createElement('style'); s.id = 'rwCss';
    s.textContent = '.rw-k{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin-bottom:10px}.rw-row{display:flex;gap:10px;align-items:center;padding:9px 4px;border-bottom:1px solid var(--ln,#e9edef);flex-wrap:wrap}.rw-row:last-child{border:0}.rw-row .m{flex:1;min-width:200px}.rw-row .m small{display:block;color:var(--mut,#667781);font-size:12px;margin-top:2px}'
      + '.rw-st{color:#f5a623;letter-spacing:1px;font-size:14px}.rw-ml{font-weight:900;font-size:16px;color:#008069;white-space:nowrap}.rw-img{max-width:100%;max-height:60vh;border-radius:12px;display:block;margin:8px auto}.rw-box{background:#fff;border:1px solid var(--ln,#e9edef);border-radius:14px;padding:12px 14px;margin-bottom:12px}.rw-box h4{margin:0 0 8px}';
    document.head.appendChild(s);
  }
  const stars = (n) => '<span class="rw-st">' + '★'.repeat(n) + '<span style="color:#d5dade">' + '★'.repeat(5 - n) + '</span></span>';

  /* ------------------------------ ⭐ Reviews tab ------------------------------ */
  async function reviews() {
    const K = kit(); css(); K.body().innerHTML = '<div class="cx-empty">Loading…</div>';
    const d = await K.call(() => api.get('bd.cx_rw_reviews'), false); if (!d) return; const k = d.kpi;
    let h = '<div class="rw-k cx-kpis">' + [['Rated this month', k.rated], ['5 stars → Judge.me', k.five], ['Lower → complaint', k.ticket], ['Screenshots sent', k.shot], ['Gifts given', k.paid], ['Waiting for you', k.waiting]].map((x) => '<div class="cx-k"><small>' + x[0] + '</small><b>' + x[1] + '</b></div>').join('') + '</div>';
    h += '<div class="cm-info">After a delivery the customer is asked to rate 1–5. <b>5 stars</b> get the Judge.me link and the offer: post the review, send the screenshot, get <b>' + d.cfg.review_ml + ' ml free perfume</b> (order above ' + rs(d.cfg.review_min) + ', valid ' + d.cfg.review_days + ' days). A clear 5-star screenshot is accepted by itself; anything unclear waits here for you. Lower ratings open a complaint ticket.</div>';
    const need = d.rows.filter((r) => r.offer === 'check');
    if (need.length) h += '<div class="rw-box"><h4>👀 Needs your check (' + need.length + ')</h4>' + need.map(row).join('') + '</div>';
    h += '<div class="rw-box"><h4>All ratings</h4>' + (d.rows.length ? d.rows.map(row).join('') : '<div class="cx-empty">No ratings yet. They start coming 5 days after the first deliveries once the rating message is switched on (WhatsApp → Order-update messages).</div>') + '</div>';
    K.body().innerHTML = h; const B = K.body();
    B.querySelectorAll('[data-shot]').forEach((b) => b.onclick = () => showShot(b.dataset.shot));
    B.querySelectorAll('[data-ok]').forEach((b) => b.onclick = async () => { b.disabled = true; const r = await K.call(() => api.post('bd.cx_rw_review_ok', { parcel_id: Number(b.dataset.ok) })); if (r) reviews(); else b.disabled = false; });
    B.querySelectorAll('[data-no]').forEach((b) => b.onclick = async () => { const why = prompt('Why is it not valid? (optional)', 'not a 5-star review'); if (why === null) return; const r = await K.call(() => api.post('bd.cx_rw_review_ok', { parcel_id: Number(b.dataset.no), reject: 1, why })); if (r) reviews(); });
    B.querySelectorAll('[data-skip]').forEach((b) => b.onclick = async () => { const r = await K.call(() => api.post('bd.cx_rw_review_skip', { parcel_id: Number(b.dataset.skip) })); if (r) reviews(); });
  }
  function row(r) {
    const K = kit(), o = OFFER[r.offer] || OFFER['']; let st = '';
    if (r.action === 'ticket') st = K.pill('Complaint ' + (r.ticket || ''), 'r');
    else st = K.pill(o[0], o[1]);
    let acts = '';
    if (r.media_id) acts += '<button class="cx-btn gh s" type="button" data-shot="' + esc(r.media_id) + '">📷 See screenshot</button> ';
    if (r.offer === 'check') acts += '<button class="cx-btn s" type="button" data-ok="' + r.parcel_id + '">✔ Give gift</button> <button class="cx-btn red s" type="button" data-no="' + r.parcel_id + '">✖ Not valid</button>';
    else if (r.action === 'judgeme' && r.offer === 'offered') acts += '<button class="cx-btn gh s" type="button" data-skip="' + r.parcel_id + '">Leave out</button>';
    return '<div class="rw-row"><div style="min-width:74px">' + stars(r.rating) + '</div><div class="m"><b>' + esc(r.name || tel(r.phone)) + '</b> · ' + esc(r.order) + '<small>' + esc(tel(r.phone)) + ' · rated ' + esc(dd(r.at)) + (r.reminded ? ' · reminded ' + esc(dd(r.reminded)) : '') + (r.verdict ? ' · ' + esc(r.verdict) : '') + '</small></div><div>' + st + '</div><div>' + acts + '</div></div>';
  }
  async function showShot(id) {
    const K = kit(); try {
      if (!blobs[id]) { const r = await fetch(api.buildUrl('wa.media', { id }), { headers: { Authorization: 'Bearer ' + api.token() } }); if (!r.ok) throw new Error('Could not open the photo.'); blobs[id] = URL.createObjectURL(await r.blob()); }
      const m = K.modal('<h3>Review screenshot</h3><img class="rw-img" alt="Review screenshot" src="' + blobs[id] + '"><div class="ac"><button class="cx-btn gh" type="button" id="rwX">Close</button></div>'); m.el.querySelector('#rwX').onclick = m.close;
    } catch (e) { K.toast(e.message || 'Could not open the photo.', true); }
  }

  /* ------------------------------ 🎁 free-perfume block inside Store credit ------------------------------ */
  async function rewardsBlock() {
    const K = kit(); if (!K) return; css(); const host = K.body(); if (!host || host.querySelector('#rwBlock')) return;
    const d = await K.call(() => api.get('bd.cx_rw_tab'), false); if (!d || host.querySelector('#rwBlock')) return; const k = d.kpi;
    let h = '<div id="rwBlock" class="rw-box"><h4>🎁 Free perfume gifts (in ml)</h4><div class="rw-k cx-kpis">' + [['Given this month', k.given_ml + ' ml'], ['Used this month', k.used_ml + ' ml'], ['Customers still hold', k.open_ml + ' ml'], ['Waiting for the boss', k.waiting]].map((x) => '<div class="cx-k"><small>' + x[0] + '</small><b>' + x[1] + '</b></div>').join('') + '</div>';
    h += '<div class="cm-info">The customer chooses the perfume. Review gift: ' + d.cfg.review_ml + ' ml · birthday gift: ' + d.cfg.bday_ml + ' ml · online only on an order above ' + rs(d.cfg.review_min) + ' · valid ' + d.cfg.review_days + ' days. The team can give up to ' + d.cfg.team_max_ml + ' ml; more waits for the boss.' + (d.mgr ? ' <button class="cx-btn gh s" type="button" id="rwCfg">Change the rules</button>' : '') + '</div>';
    if (d.pending.length) h += '<h4 style="margin-top:10px">⏳ Waiting for the boss (' + d.pending.length + ')</h4>' + d.pending.map((g) => gRow(g, d.mgr)).join('');
    h += '<h4 style="margin-top:10px">Active gifts</h4>' + (d.active.length ? d.active.map((g) => gRow(g, d.mgr)).join('') : '<div class="cx-empty">No active gifts.</div>');
    if (d.history.length) h += '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700">Used and cancelled (' + d.history.length + ')</summary>' + d.history.map((g) => gRow(g, false)).join('') + '</details>';
    h += '</div>'; host.insertAdjacentHTML('beforeend', h);
    const act = (sel, fn) => host.querySelectorAll(sel).forEach((b) => b.onclick = async () => { b.disabled = true; const r = await fn(b); if (r) { host.querySelector('#rwBlock').remove(); rewardsBlock(); } else b.disabled = false; });
    act('[data-gok]', (b) => K.call(() => api.post('bd.cx_rw_ok', { id: Number(b.dataset.gok) }))); act('[data-gno]', (b) => K.call(() => api.post('bd.cx_rw_ok', { id: Number(b.dataset.gno), reject: 1 })));
    act('[data-gvoid]', (b) => (confirm('Cancel this gift?') ? K.call(() => api.post('bd.cx_rw_void', { id: Number(b.dataset.gvoid) })) : null));
    act('[data-guse]', (b) => { const ref = prompt('Used on which order or bill number?', ''); if (!ref) return null; const choice = prompt('Which perfume did the customer choose?', '') || ''; return K.call(() => api.post('bd.cx_rw_use', { id: Number(b.dataset.guse), ref, choice })); });
    const cfg = host.querySelector('#rwCfg'); if (cfg) cfg.onclick = () => cfgSheet(d.cfg);
  }
  function gRow(g, mgr) {
    const K = kit(); const stt = g.status === 'wait' ? K.pill('waiting for the boss', 'o') : g.status === 'used' ? K.pill('used' + (g.used_ref ? ' on ' + g.used_ref : ''), '') : g.status === 'void' ? K.pill('cancelled', '') : g.expired ? K.pill('expired', 'r') : K.pill('active', 'g');
    let a = '';
    if (g.status === 'wait' && mgr) a = '<button class="cx-btn s" type="button" data-gok="' + g.id + '">Approve</button> <button class="cx-btn red s" type="button" data-gno="' + g.id + '">Reject</button>';
    else if (g.status === 'ok' && !g.expired) a = '<button class="cx-btn s" type="button" data-guse="' + g.id + '">Mark used</button>' + (mgr ? ' <button class="cx-btn gh s" type="button" data-gvoid="' + g.id + '">Cancel</button>' : '');
    return '<div class="rw-row"><div class="rw-ml">' + g.ml + ' ml</div><div class="m"><b>' + esc(g.name || tel(g.phone)) + '</b> · ' + esc(KIND[g.kind] || g.kind) + '<small>Code <b>' + esc(g.code) + '</b> · ' + esc(tel(g.phone)) + (g.min_order ? ' · order above ' + rs(g.min_order) : '') + (g.expires ? ' · valid till ' + esc(dd(g.expires)) : '') + (g.choice ? ' · chose ' + esc(g.choice) : '') + (g.reason ? ' · ' + esc(g.reason) : '') + '</small></div><div>' + stt + '</div><div>' + a + '</div></div>';
  }
  function cfgSheet(c) {
    const K = kit(); const f = (id, lab, v) => '<label>' + lab + '</label><input class="cx-in" id="' + id + '" type="number" min="0" value="' + v + '">';
    const m = K.modal('<h3>🎁 Gift rules</h3>' + f('rwA', 'Review gift (ml)', c.review_ml) + f('rwB', 'Order must be above (₹)', c.review_min) + f('rwC', 'Valid for (days)', c.review_days) + f('rwD', 'Birthday gift (ml)', c.bday_ml) + f('rwE', 'Birthday: order must be above (₹)', c.bday_min) + f('rwF', 'Birthday gift valid for (days)', c.bday_days) + f('rwG', 'The team can give up to (ml)', c.team_max_ml)
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" id="rwH" ' + (c.auto_shot ? 'checked' : '') + '> Accept a clear 5-star screenshot by itself</label><div class="ac"><button class="cx-btn gh" type="button" id="rwNo">Cancel</button><button class="cx-btn" type="button" id="rwOk">Save</button></div>');
    m.el.querySelector('#rwNo').onclick = m.close; const v = (id) => Number(m.el.querySelector('#' + id).value) || 0;
    m.el.querySelector('#rwOk').onclick = async () => { const r = await K.call(() => api.post('bd.cx_rw_cfg', { review_ml: v('rwA'), review_min: v('rwB'), review_days: v('rwC'), bday_ml: v('rwD'), bday_min: v('rwE'), bday_days: v('rwF'), team_max_ml: v('rwG'), auto_shot: m.el.querySelector('#rwH').checked ? 1 : 0 }), 'Saved'); if (r) { m.close(); const h = K.body().querySelector('#rwBlock'); if (h) h.remove(); rewardsBlock(); } };
  }

  /* ------------------------------ "Give free perfume" on a ticket ------------------------------ */
  function give(o) {
    const K = kit(); css(); const f = (id, lab, v, t) => '<label>' + lab + '</label><input class="cx-in" id="' + id + '" type="' + (t || 'number') + '" ' + (t ? '' : 'min="0"') + ' value="' + v + '">';
    const m = K.modal('<h3>🎁 Give free perfume' + (o.code ? ' · ' + esc(o.code) : '') + '</h3><div class="cm-info">The customer chooses the perfume. It goes into <b>Store credit → Free perfume gifts</b>.</div>'
      + '<label>How much?</label><select class="cx-sel" id="rwM"><option value="20">20 ml free</option><option value="50">50 ml free</option><option value="30">30 ml free</option><option value="10">10 ml free</option></select>' + f('rwMin', 'Order must be above (₹) — 0 = any', 1500) + f('rwDays', 'Valid for (days)', 15)
      + '<label>Why</label><input class="cx-in" id="rwWhy" type="text" placeholder="e.g. sorry for the late parcel"><div class="ac"><button class="cx-btn gh" type="button" id="rwNo">Cancel</button><button class="cx-btn" type="button" id="rwOk">Give gift</button></div>');
    m.el.querySelector('#rwNo').onclick = m.close;
    m.el.querySelector('#rwOk').onclick = async () => { const b = m.el.querySelector('#rwOk'); b.disabled = true; const r = await K.call(() => api.post('bd.cx_rw_give', { case_id: o.caseId, phone: o.phone || '', ml: Number(m.el.querySelector('#rwM').value), min_order: Number(m.el.querySelector('#rwMin').value) || 0, days: Number(m.el.querySelector('#rwDays').value) || 15, reason: m.el.querySelector('#rwWhy').value.trim() })); if (r) { m.close(); if (o.done) o.done(); } else b.disabled = false; };
  }

  /* ------------------------------ plug into the Complaints screens ------------------------------ */
  const isCreditView = (host) => Array.from(host.querySelectorAll('h4')).some((h) => /Customers with credit/.test(h.textContent));   // only the Store credit screen has this heading
  function install() {
    if (!window.CXM || !CXM.tabs) return false;
    CXM.tabs.reviews = reviews;
    const orig = CXM.tabs.credit;
    CXM.tabs.credit = async function () {
      await orig(); rewardsBlock();
      const K = kit(); if (!K) return; const host = K.body(); if (!host || host._rwObs) return;
      host._rwObs = new MutationObserver(() => { if (!host.querySelector('#rwBlock') && isCreditView(host) && !host._rwBusy) { host._rwBusy = true; rewardsBlock().then(() => { host._rwBusy = false; }); } });   // the credit tab redraws itself after an action: put the gifts block back
      host._rwObs.observe(host, { childList: true });
    };
    return true;
  }
  install();
  return { give, reviews };
})();
