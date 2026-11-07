/**
 * ⚡ Flash Offer — a short, time-boxed price promotion. Admin picks a few size+price combos and how many hours it runs
 * (Commission Rules page, window.FO_ADMIN = true); any order of that size (matched by total ML, price not compared), sold inside those
 * hours, earns a flat commission % (api_alloc.php: flash_offer_pct_for(), used inside coach_day()). My Sales and Order
 * Allocation (window.FO_ADMIN unset) show a live-countdown banner instead — big by default, or window.FO_SIZE = 'slim' for a
 * one-line strip. Saved through flash_offer.start / flash_offer.stop, read through flash_offer.get.
 */
(function () {
  if (window.__flashOfferLoaded) return;
  window.__flashOfferLoaded = true;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const fmtN = (v) => String(Math.round(Number(v) * 100) / 100);
  const pad2 = (n) => String(Math.max(0, n)).padStart(2, '0');

  const css = `
#foBanner{display:flex; flex-direction:column; gap:12px; margin-bottom:20px;}
#foBanner.slim{gap:8px; margin-bottom:14px;}
.foCard{border-radius:16px; padding:16px 20px; background:linear-gradient(120deg,#f97316,#ea580c); color:#fff; box-shadow:0 10px 26px rgba(234,88,12,.25);}
.foCard.slim{padding:8px 14px; border-radius:12px; box-shadow:none; display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
.foCard .foTop{display:flex; align-items:center; gap:14px; justify-content:space-between; flex-wrap:wrap;}
.foCard.slim .foTop{gap:10px;}
.foCard .foTtl{font-size:17px; font-weight:900; display:flex; flex-direction:column; gap:6px;}
.foCard.slim .foTtl{font-size:13px; flex-direction:row; align-items:center; gap:10px; flex-wrap:wrap;}
.foCard .foRate{font-size:12.5px; font-weight:700; background:rgba(0,0,0,.18); display:inline-block; padding:4px 12px; border-radius:99px; white-space:nowrap;}
.foCard.slim .foRate{font-size:11px; padding:3px 10px; border-radius:8px;}
.foCard .foTimer{display:flex; gap:5px; align-items:baseline;}
.foCard .foTimer .u{background:rgba(0,0,0,.22); border-radius:10px; padding:5px 9px; text-align:center; min-width:34px;}
.foCard.slim .foTimer .u{padding:3px 7px; min-width:26px; border-radius:8px;}
.foCard .foTimer .u b{display:block; font-size:18px; font-weight:900; font-variant-numeric:tabular-nums;}
.foCard.slim .foTimer .u b{font-size:13px;}
.foCard .foTimer .u span{display:block; font-size:11px; font-weight:700; opacity:.85; letter-spacing:.04em;}
.foCard.slim .foTimer .u span{display:none;}
.foCard .foPills{display:flex; gap:9px; margin-top:12px; flex-wrap:wrap;}
.foCard.slim .foPills{margin-top:0; gap:6px; order:2;}
.foCard .foPill{background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.35); border-radius:11px; padding:7px 13px;}
.foCard.slim .foPill{padding:3px 9px; border-radius:8px; font-size:11.5px; font-weight:700;}
.foCard .foPill b{display:block; font-size:14.5px;}
.foCard.slim .foPill b{display:inline;}
.foCard .foPill span{font-size:11px; opacity:.9;}
.foCard.slim .foPill span{font-size:11.5px; opacity:1; margin-left:3px;}
.foCard .foBlk{margin-top:12px; padding-top:10px; border-top:1px dashed rgba(255,255,255,.4);}
.foCard .foBlk .foRate{font-size:12.5px; font-weight:700; background:rgba(0,0,0,.18); display:inline-block; padding:4px 12px; border-radius:99px;}
.foCard .foBlk .foPills{margin-top:8px;}
.foCard .foLeft{margin-top:6px; font-size:11.5px; font-weight:700; opacity:.9;}
@media (prefers-reduced-motion:no-preference){ .foCard .foTtl > span:first-child{animation:foPulse 2.2s ease-in-out infinite;} }
@keyframes foPulse{0%,100%{opacity:1;}50%{opacity:.78;}}
#fo{margin-bottom:20px; background:#fff; border:1px solid var(--line); border-radius:var(--radius,18px); padding:18px 22px 20px;}
#fo h3{margin:0 0 4px; font-size:17px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
#fo .rsub{font-size:12.5px; color:var(--muted); line-height:1.5; margin:0 0 14px; max-width:860px;}
#fo .st{font-size:11px; font-weight:800; border-radius:999px; padding:3px 10px;}
#fo .st.on{background:#ffedd5; color:#c2410c;} #fo .st.off{background:#f1f3f6; color:#6b7280;}
#fo .run{border:2px solid #fed7aa; background:#fff7ed; border-radius:14px; padding:14px 16px; margin-bottom:16px; display:flex; gap:16px; flex-wrap:wrap; align-items:center; justify-content:space-between;}
#fo .run .foPills{display:flex; gap:8px; flex-wrap:wrap;}
#fo .run .foPill{background:#fff; border:1px solid #fdba74; border-radius:10px; padding:5px 11px; font-size:12.5px; font-weight:700; color:#9a3412;}
#fo .run .foTimer{display:flex; gap:5px;}
#fo .run .foTimer .u{background:#1f2937; color:#fff; border-radius:9px; padding:5px 9px; text-align:center; min-width:34px;}
#fo .run .foTimer .u b{display:block; font-size:16px; font-weight:900; font-variant-numeric:tabular-nums;}
#fo .run .foTimer .u span{display:block; font-size:11px; font-weight:700; opacity:.8;}
#fo .row3{display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px;}
#fo .fld{flex:1 1 200px; background:#fafbfc; border:1px solid #e6e9ee; border-radius:12px; padding:10px 12px;}
#fo .fld label{display:block; font-size:12px; font-weight:700; margin-bottom:6px;}
#fo .fld .in{display:flex; align-items:center; gap:6px; font-size:13px;}
#fo .fld input{min-height:0;}
#fo .items{margin-bottom:12px;}
#fo .irow{display:flex; gap:8px; align-items:center; margin-bottom:7px; font-size:13px;}
#fo .irow input{padding:7px 10px; border:1px solid var(--line); border-radius:8px; font-size:13px; min-height:0;}
#fo .irow input.ml{width:90px;} #fo .irow input.price{width:120px;}
#fo .irow .x{border:1px solid var(--line); background:#fff; border-radius:999px; width:26px; height:26px; cursor:pointer; padding:0; min-height:0; width:26px; font-size:12px; color:#9ca3af;}
#fo .irow .x:hover{color:#b91c1c; border-color:#f1b4b4;}
#fo .foot{display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;}
#fo .foot .btn{width:auto; padding:9px 20px; min-height:0;}
#fo .msg{margin:0 0 12px;}
#fo .hist{overflow:auto; margin-top:14px;}
#fo table{width:100%; border-collapse:collapse; font-size:12.5px;}
#fo th{text-align:left; font-size:11px; color:#64748b; padding:7px 8px; border-bottom:1px solid #e2e8f0; white-space:nowrap;}
#fo td{padding:8px 8px; border-bottom:1px solid #f1f5f9; vertical-align:top;}
#fo .pill{display:inline-block; border-radius:99px; padding:2px 9px; font-size:11px; font-weight:800; margin:1px 3px 1px 0; white-space:nowrap;}
#fo .p-on{background:#ffedd5; color:#c2410c;} #fo .p-stop{background:#f1f5f9; color:#64748b;} #fo .p-done{background:#eef0f4; color:#6b7280;}
@media (max-width:640px){ #fo{padding:14px 12px;} #fo .fld{flex-basis:100%;} }
/* Phone-width banner: the big (non-slim) card uses the same desktop sizing by default, which reads oversized on a
   narrow screen — shrink padding, type and pill sizes so the whole offer fits compactly without losing any detail. */
@media (max-width:480px){
  #foBanner{gap:8px; margin-bottom:14px;}
  .foCard{padding:11px 13px; border-radius:13px;}
  .foCard .foTop{gap:8px;}
  .foCard .foTtl{font-size:13.5px; gap:4px;}
  .foCard .foRate{font-size:11px; padding:3px 9px;}
  .foCard .foTimer{gap:4px;}
  .foCard .foTimer .u{padding:4px 6px; min-width:26px; border-radius:8px;}
  .foCard .foTimer .u b{font-size:13.5px;}
  .foCard .foTimer .u span{font-size:11px;}
  .foCard .foPills{gap:6px; margin-top:8px;}
  .foCard .foPill{padding:5px 9px; border-radius:9px;}
  .foCard .foPill b{font-size:12px;}
  .foCard .foPill span{font-size:11px;}
}
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const mount = window.FO_MOUNT || document.querySelector('.content') || document.querySelector('.main') || document.body;      // My Sales gives its own slot (window.FO_MOUNT) so the offer sits under the missions

  const ADMIN = !!window.FO_ADMIN;
  const SIZE = window.FO_SIZE === 'slim' ? 'slim' : 'big';

  let DATA = null;          // last flash_offer.get response
  let F = newForm();        // the Start form, admin only

  function newForm() { return { items: [{ ml: '', price: '' }, { ml: '', price: '' }, { ml: '', price: '' }], hours: 48, pct: 6, nocode: 50, note: '' }; }

  // The % actually paid on a no-code order during a flash offer: the offer's own flat % × its own nocode% (default 50 = half).
  function flashNocodePct(r) { return (Number(r.pct) || 0) * (Number(r.nocode == null ? 50 : r.nocode)) / 100; }

  // ---------- Employee banner (My Sales, Order Allocation) ----------
  let banner = null;
  function ensureBanner() {
    if (banner) return banner;
    banner = document.createElement('div');
    banner.id = 'foBanner'; banner.hidden = true;
    if (SIZE === 'slim') banner.classList.add('slim');
    mount.insertBefore(banner, mount.firstChild);
    return banner;
  }
  function renderBanner() {
    const b = ensureBanner();
    const list = (DATA && DATA.running) || [];
    if (!list.length || window.FO_HIDE) { b.hidden = true; b.innerHTML = ''; return; }
    b.hidden = false;
    const slimCls = SIZE === 'slim' ? ' slim' : '';
    // ONE orange card, however many offers run at once (several used to stack as separate panels): the countdown is the one that ends first,
    // each offer adds its own sizes + rate inside the same card.
    const endMs = (r) => new Date(String(r.ends_at || '').replace(' ', 'T')).getTime();
    const soon = list.slice().sort((x, y) => endMs(x) - endMs(y))[0], multi = list.length > 1;
    const pillsOf = (r) => (r.items || []).map((it) => `<div class="foPill"><b>${esc(it.ml)} ml</b><span>${esc(inr(it.price))}</span></div>`).join('');
    // The rate line is folded into .foTtl (not a separate trailing flex item) so it can never be squeezed out by leftover-space flex layout.
    const rateOf = (r) => `Sell these now → <b>${fmtN(r.pct)}%</b> commission (<b>${fmtN(flashNocodePct(r))}%</b> without a code)`;
    const body = multi
      ? list.map((r) => `<div class="foBlk"><div class="foRate">${rateOf(r)}</div><div class="foPills">${pillsOf(r)}</div><div class="foLeft" id="foMini_${esc(r.id)}"></div></div>`).join('')
      : `<div class="foPills">${pillsOf(list[0])}</div>`;
    b.innerHTML = `
      <div class="foCard${slimCls}">
        <div class="foTop">
          <div class="foTtl"><span>⚡ Limited Time Offer</span>${multi ? '' : `<span class="foRate">${rateOf(list[0])}</span>`}</div>
          <div class="foTimer" id="foTimerBox_${esc(soon.id)}"></div>
        </div>
        ${body}
      </div>`;
  }

  // ---------- Countdown ----------
  function timerHtml(ms) {
    const s = Math.max(0, Math.round(ms / 1000));
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return `<div class="u"><b>${h}</b><span>HRS</span></div><div class="u"><b>${pad2(m)}</b><span>MIN</span></div><div class="u"><b>${pad2(sec)}</b><span>SEC</span></div>`;
  }
  function tick() {
    const list = (DATA && DATA.running) || [];
    if (!list.length) return;
    let anyEnded = false;
    for (const r of list) {
      const left = new Date(r.ends_at.replace(' ', 'T')).getTime() - Date.now();
      if (left <= 0) { anyEnded = true; continue; }        // this one just ended — refresh everything once, below
      const box1 = document.getElementById('foTimerBox_' + r.id); if (box1) box1.innerHTML = timerHtml(left);
      const box2 = document.getElementById('foRunTimer_' + r.id); if (box2) box2.innerHTML = timerHtml(left);
      const mini = document.getElementById('foMini_' + r.id);
      if (mini) { const hh = Math.floor(left / 3600000), mm = Math.floor((left % 3600000) / 60000); mini.textContent = '⏳ ends in ' + (hh ? hh + 'h ' : '') + mm + 'm'; }
    }
    if (anyEnded) load();
  }

  // ---------- Admin card (Commission Rules page) ----------
  let root = null, busy = false;
  function ensureRoot() {
    if (root) return root;
    root = document.createElement('div'); root.id = 'fo';
    mount.insertBefore(root, mount.firstChild);
    return root;
  }
  const $ = (id) => root.querySelector('#' + id);
  function flashMsg(html, cls) { const m = $('foMsg'); if (m) m.innerHTML = `<div class="alert ${cls || 'error'}">${html}</div>`; }

  function renderAdmin() {
    const r = ensureRoot();
    const runs = (DATA && DATA.running) || [];
    const runIds = runs.map((x) => String(x.id));
    const hist = ((DATA && DATA.history) || []).filter((x) => !runIds.includes(String(x.id))).slice(0, 8);
    r.innerHTML = `
      <h3>⚡ Flash Offer <span class="st ${runs.length ? 'on' : 'off'}">${runs.length ? 'Running' + (runs.length > 1 ? ' (' + runs.length + ')' : '') : 'Not running'}</span></h3>
      <p class="rsub">A short price promotion: pick a few size + price combos and how many hours it runs. Any order of that size (total ML — the order price is not compared), placed inside those hours, earns this flat % instead of the ML price slab (no cap, no daily bonus, no floor) — an order without a code pays the "WITHOUT A CODE" % of that flat rate below (50 = half, e.g. 6% → 3%), its own number, separate from the main ML slab chart's nocode%. Several offers can run at once — each on its own sizes, so a size already in a running offer can't be reused until that offer stops. Shown with a live countdown on My Sales and Order Allocation. Orders sold inside the hours keep their % for good, even after it ends.</p>
      <div id="foMsg" class="msg"></div>
      ${runs.map((run) => `
      <div class="run">
        <div><b style="font-size:13.5px;">Started ${esc(run.started_at)} by ${esc(run.by || '')}</b><div class="foPills" style="margin-top:7px;">${(run.items || []).map((it) => `<span class="foPill">${esc(it.ml)} ml → ${esc(inr(it.price))}</span>`).join('')}</div><div style="margin-top:7px; font-size:12.5px; color:#9a3412; font-weight:700;">${fmtN(run.pct)}% with a code · ${fmtN(flashNocodePct(run))}% without${run.note ? ' · ' + esc(run.note) : ''}</div></div>
        <div style="display:flex; align-items:center; gap:14px;"><div class="foTimer" id="foRunTimer_${esc(run.id)}"></div><button type="button" class="btn secondary" data-stop="${esc(run.id)}">■ Stop now</button></div>
      </div>`).join('')}
      <div class="items">
        <label style="display:block; font-size:12px; font-weight:700; margin-bottom:6px;">SIZES &amp; OFFER PRICE</label>
        ${F.items.map((it, i) => `<div class="irow"><input class="ml" type="number" min="1" placeholder="ml" data-fi="${i}" data-fk="ml" value="${esc(it.ml)}"> ml → ₹ <input class="price" type="number" min="1" placeholder="price" data-fi="${i}" data-fk="price" value="${esc(it.price)}"> <button type="button" class="x" data-fx="${i}" title="Remove">✕</button></div>`).join('')}
        <button type="button" class="btn secondary" id="foAddRow" style="width:auto; padding:6px 14px; min-height:0; font-size:12.5px;">+ Add size</button>
      </div>
      <div class="row3">
        <div class="fld"><label>RUNS FOR</label><div class="in"><input type="number" id="foHours" min="1" max="720" step="1" value="${esc(F.hours)}" style="width:70px;"> hours, from the moment you press Start</div></div>
        <div class="fld"><label>FLAT COMMISSION</label><div class="in"><input type="number" id="foPct" min="0.1" max="100" step="0.1" value="${esc(F.pct)}" style="width:70px;"> % on these orders (replaces the normal rules while it runs)</div></div>
        <div class="fld"><label>WITHOUT A CODE</label><div class="in"><input type="number" id="foNocode" min="0" max="100" step="1" value="${esc(F.nocode)}" style="width:70px;"> % of the flat rate above (50 = half, e.g. 6% → 3%)</div></div>
        <div class="fld"><label>NOTE <span style="font-weight:400; text-transform:none;">(optional)</span></label><div class="in"><input type="text" id="foNote" maxlength="120" placeholder="e.g. Diwali 48h push" value="${esc(F.note)}" style="width:100%;"></div></div>
      </div>
      <div class="foot"><button type="button" class="btn" id="foStart">▶ ${runs.length ? 'Start another offer' : 'Start the offer'}</button><span class="muted" style="font-size:12px;">New orders after the hours end go back to the normal commission by themselves — nothing to switch off.</span></div>
      ${hist.length ? `<div class="hist"><table><thead><tr><th>STARTED</th><th>ITEMS</th><th>% (code / no-code)</th><th>STATUS</th><th>BY</th></tr></thead><tbody>
        ${hist.map((x) => `<tr><td style="white-space:nowrap;">${esc(x.started_at)}</td><td>${(x.items || []).map((it) => `<span class="pill p-done">${esc(it.ml)}ml ₹${esc(it.price)}</span>`).join('')}</td><td>${fmtN(x.pct)}% / ${fmtN(flashNocodePct(x))}%</td><td><span class="pill ${x.status === 'stopped' ? 'p-stop' : 'p-done'}">${x.status === 'stopped' ? 'Stopped' : 'Ended'}</span></td><td>${esc(x.by || '')}</td></tr>`).join('')}
      </tbody></table></div>` : ''}`;
    wireAdmin();
    tick();
  }

  function wireAdmin() {
    if (!root.__wired) {
      root.__wired = true;
      root.addEventListener('input', (e) => {
        const t = e.target;
        if (t.dataset.fi != null) { F.items[Number(t.dataset.fi)][t.dataset.fk] = t.value; }
        else if (t.id === 'foHours') F.hours = t.value;
        else if (t.id === 'foPct') F.pct = t.value;
        else if (t.id === 'foNocode') F.nocode = t.value;
        else if (t.id === 'foNote') F.note = t.value;
      });
      // Delegated (not bound per-render) because there can now be several Stop buttons, one per running offer.
      root.addEventListener('click', (e) => {
        const stopBtn = e.target.closest('[data-stop]');
        if (stopBtn) doStop(stopBtn.dataset.stop);
      });
    }
    root.querySelectorAll('[data-fx]').forEach((b) => b.onclick = () => { F.items.splice(Number(b.dataset.fx), 1); renderAdmin(); });
    const addRow = $('foAddRow'); if (addRow) addRow.onclick = () => { F.items.push({ ml: '', price: '' }); renderAdmin(); };
    const startBtn = $('foStart'); if (startBtn) startBtn.onclick = doStart;
  }

  async function doStart() {
    if (busy) return;
    const items = F.items.filter((it) => it.ml !== '' && it.price !== '').map((it) => ({ ml: Number(it.ml), price: Number(it.price) }));
    if (!items.length) return flashMsg('Add at least one size and its offer price.');
    busy = true; const b = $('foStart'); if (b) { b.disabled = true; b.textContent = 'Starting…'; }
    try {
      const d = await api.post('flash_offer.start', { items, hours: Number(F.hours) || 0, pct: Number(F.pct) || 0, nocode: F.nocode === '' ? 50 : Number(F.nocode), note: F.note });
      if (d.error) throw new Error(d.error);
      F = newForm(); await load();
      flashMsg(esc(d.message || 'Started.'), 'success');
    } catch (e) { flashMsg(esc(e.message)); } finally { busy = false; }
  }
  async function doStop(id) {
    if (busy || !confirm('Stop the flash offer now? Orders already sold inside it keep their flat %.')) return;
    busy = true;
    try {
      const d = await api.post('flash_offer.stop', { id });
      if (d.error) throw new Error(d.error);
      await load();
      flashMsg(esc(d.message || 'Stopped.'), 'success');
    } catch (e) { flashMsg(esc(e.message)); } finally { busy = false; }
  }

  // ---------- Load + poll ----------
  async function load() {
    try { DATA = await api.get('flash_offer.get'); } catch (e) { DATA = DATA || null; }
    if (ADMIN) renderAdmin(); else renderBanner();
    tick();
  }
  load();
  setInterval(tick, 1000);
  // every 30 s only while the page is on screen, and only in the top page: a page shown inside another HRMS page (the allocation pop-up) asks once
  // instead of polling a second time (the parent already does)
  const inner = (() => { try { return window.top !== window && window.top.location.origin === location.origin; } catch (e) { return false; } })();
  if (!inner) setInterval(() => { if (!document.hidden) load(); }, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !inner) load(); });
})();
