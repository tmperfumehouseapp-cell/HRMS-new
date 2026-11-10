/* my_app.js — the salesperson's own app shell: Home · Chats (opens the existing WhatsApp chat app, untouched) · Earn · Prices · Me.
 * Step 1: the shell, Prices (the unified price chart, read only: pc.get) and Me (sound / vibration / dark). Home and Earn are filled in the next steps. */
(function () {
  'use strict';
  guardPage();
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } } };
  const rs = (n) => '₹' + Math.round(+n || 0).toLocaleString('en-IN');

  // ---------------------------------------------------------------- tabs
  const TABS = ['home', 'earn', 'prices', 'me'];
  function go() {
    const h = (location.hash || '#home').slice(1); const t = TABS.indexOf(h) > -1 ? h : 'home';
    document.querySelectorAll('.s').forEach((s) => s.classList.toggle('on', s.id === t));
    document.querySelectorAll('nav a[data-s]').forEach((a) => a.classList.toggle('on', a.dataset.s === t));
    if (t === 'prices') loadPrices();
    $('bk').hidden = !(t === 'prices' && basket.length);
    window.scrollTo(0, 0);
  }
  addEventListener('hashchange', go);

  // ---------------------------------------------------------------- Prices (pc.get: every chart, the salesperson may read, only Admin / HR change)
  let PC = null, seg = 'perfume', open = -1, basket = [], col = 0, loading = false;
  const SEGN = { perfume: 'Perfume', gel: 'Shower gel', attar: 'Attar', other: 'Others', mix: 'Combos' };
  function rowInfo(sg, r) {   // → { label, prices[] }  (rows are [ml, p1, p2…] for perfume, objects for combos, either for the rest)
    if (Array.isArray(r)) return { label: r[0] + (sg === 'perfume' ? ' ml' : (+r[0] === 1 ? ' piece' : ' pieces')), key: String(r[0]), prices: r.slice(1).map(Number) };
    if (sg === 'mix') { const p = []; if (r.perf) p.push(r.perf + ' ml perfume'); if (r.gel) p.push(r.gel + ' × shower gel'); if (r.attar) p.push(r.attar + ' × attar'); if (r.other) p.push(r.other + ' × other'); return { label: p.join(' + ') || 'Combo', key: p.join('|'), prices: (r.p || []).map(Number) }; }
    const n = r.ml != null ? r.ml : (r.pcs != null ? r.pcs : (r.n != null ? r.n : '')); return { label: n + (sg === 'perfume' ? ' ml' : (+n === 1 ? ' piece' : ' pieces')), key: String(n), prices: (r.p || r.prices || []).map(Number) };
  }
  async function loadPrices() {
    if (PC || loading) { drawPrices(); return; } loading = true;
    try { PC = await api.get('pc.get'); col = Math.min(Math.max(0, +PC.cust_col || 0), 9); drawSeg(); drawPrices(); } catch (e) { $('prBody').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
    loading = false;
  }
  function chart() { return (PC && PC.charts && PC.charts[seg]) || { cols: [], names: [], rows: [] }; }
  function colLabel(c, i) { const nm = String((c.names || [])[i] || ''); const pc = (c.cols || [])[i]; if (nm && nm.indexOf('%') > -1) return nm; return (nm ? nm + ' · ' : '') + (pc != null ? pc + '%' : '#' + (i + 1)); }
  function drawSeg() {
    const keys = ['perfume', 'gel', 'attar', 'other', 'mix'].filter((k) => PC && PC.charts && PC.charts[k] && (PC.charts[k].rows || []).length);
    if (keys.indexOf(seg) < 0 && keys.length) seg = keys[0];
    $('prSeg').innerHTML = keys.map((k) => '<button type="button" data-seg="' + k + '" class="' + (k === seg ? 'on' : '') + '">' + SEGN[k] + '</button>').join('');
  }
  function drawPrices() {
    const c = chart(), q = ($('prQ').value || '').trim().toLowerCase(); const cols = c.cols || [];
    const rows = (c.rows || []).map((r, i) => Object.assign({ i }, rowInfo(seg, r))).filter((x) => !q || x.label.toLowerCase().indexOf(q) > -1);
    if (!rows.length) { $('prBody').innerHTML = '<div class="card"><div class="empty">' + ((PC && !(c.rows || []).length) ? 'No prices in this chart yet.' : 'Nothing matches.') + '</div></div>'; return; }
    const ci = Math.min(col, Math.max(0, cols.length - 1));
    $('prBody').innerHTML = '<div class="card"><div class="h3">' + esc(SEGN[seg] || seg) + '<span class="mut">Quote column: <b>' + esc(colLabel(c, ci)) + '</b></span></div>' + rows.map((x) => {
      const main = x.prices[ci], op = open === x.i;
      return '<div class="pr"><div class="r"><div class="l" data-row="' + x.i + '" role="button" tabindex="0"><b>' + esc(x.label) + '</b><span>' + (cols[ci] != null ? 'you earn ' + esc(cols[ci]) + '%' : '') + ' · tap for all prices</span></div><div class="v">' + (main == null ? '—' : rs(main)) + '</div><button type="button" class="add" data-add="' + x.i + '" aria-label="Add to the basket">+</button></div>'
        + (op ? '<div class="lad">' + x.prices.map((p, j) => '<div class="' + (j === ci ? 'me' : '') + '" data-col="' + j + '"><b>' + rs(p) + '</b><span>' + esc(colLabel(c, j)) + '</span></div>').join('') + '</div>' : '') + '</div>';
    }).join('') + '</div>';
  }
  function drawBasket() {
    const bk = $('bk'); const c = chart();
    if (!basket.length) { bk.hidden = true; document.body.classList.remove('hasbk'); return; }
    const cols = c.cols || []; let total = 0; basket.forEach((b) => { const ch = (PC.charts[b.seg] || {}); const r = (ch.rows || [])[b.i]; const x = r ? rowInfo(b.seg, r) : null; if (x) total += +(x.prices[Math.min(col, x.prices.length - 1)] || 0); });
    bk.hidden = false; document.body.classList.add('hasbk');
    bk.innerHTML = '<div class="in"><div class="t"><b>' + rs(total) + '</b><span>' + basket.length + ' item' + (basket.length > 1 ? 's' : '') + ' · at ' + esc(colLabel(c, Math.min(col, Math.max(0, cols.length - 1)))) + '</span></div><select id="bkCol" aria-label="Quote column">' + cols.map((_, j) => '<option value="' + j + '"' + (j === col ? ' selected' : '') + '>' + esc(colLabel(c, j)) + '</option>').join('') + '</select><button type="button" id="bkClr">Clear</button></div>';
  }
  document.addEventListener('click', (e) => {
    const sg = e.target.closest('[data-seg]'); if (sg) { seg = sg.dataset.seg; open = -1; drawSeg(); drawPrices(); return; }
    const row = e.target.closest('[data-row]'); if (row) { const i = +row.dataset.row; open = open === i ? -1 : i; drawPrices(); return; }
    const cl = e.target.closest('.lad [data-col]'); if (cl) { col = +cl.dataset.col; drawPrices(); drawBasket(); return; }
    const ad = e.target.closest('[data-add]'); if (ad) { basket.push({ seg, i: +ad.dataset.add }); try { if (navigator.vibrate && store.get('ma_vibe') !== '0') navigator.vibrate(15); } catch (er) { } drawBasket(); return; }
    if (e.target.closest('#bkClr')) { basket = []; drawBasket(); return; }
  });
  document.addEventListener('change', (e) => { if (e.target.id === 'bkCol') { col = +e.target.value; drawPrices(); drawBasket(); } });
  document.addEventListener('input', (e) => { if (e.target.id === 'prQ') { open = -1; drawPrices(); } });

  // ---------------------------------------------------------------- Me (the same switches the chat app uses: wa_sound; dark mode follows the saved choice)
  function drawMe() {
    const on = (k, d) => (store.get(k) == null ? d : store.get(k) === '1');
    const row = (k, label, d) => '<div class="set"><span>' + label + '</span><button type="button" class="tg ' + (on(k, d) ? '' : 'off') + '" data-tg="' + k + '" aria-label="' + esc(label) + '"></button></div>';
    $('meSet').innerHTML = row('wa_sound', '🔊 Sound on a new order', true) + row('ma_vibe', '📳 Vibration', true) + row('ma_dark', '🌙 Dark mode', false);
  }
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-tg]'); if (!t) return; const k = t.dataset.tg; const now = store.get(k) == null ? (k !== 'ma_dark') : store.get(k) === '1'; store.set(k, now ? '0' : '1'); drawMe(); applyDark();
  });
  function applyDark() { document.documentElement.classList.toggle('dk', store.get('ma_dark') === '1'); }

  // ---------------------------------------------------------------- start
  applyDark(); drawMe(); go();
  try { const u = api.currentUser && api.currentUser(); const n = u && (u.full_name || u.name || u.username); if (n) $('hiName').textContent = 'Hi, ' + String(n).split(' ')[0]; } catch (e) { /* optional */ }
})();
