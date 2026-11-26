/* 🏷 Barcode generator of the SKU sheet page (Sales → Orders → SKU sheet, to the right of the chain report). Owner, 25 Nov.
 * For the bottles and chains of ONLINE orders — not Store stock (their own table sku_bc and their own 8-digit numbers).
 *   ① From the day's orders  one label for every bottle of the SKU sheet of the day picked at the top (10 / 20 / 50 / 100 ml columns). Pressing again makes only the missing ones.
 *   ② Manual                 any perfume of the SKU list, a size, how many.
 *   ③ Chains                 one label for every chain piece of the chain report.
 * Label: name · size · MRP · batch (one a day: B + ddmmyy) · barcode + number · MFG · EXP (4 years). Size 2 × 4 inch by default (kept in this browser).
 * Reads the page's own D (the sheet) and CH (the chain report); the page calls SkuBC.refresh() after each of them is drawn. Needs assets/js/jsbarcode.code128.min.js. */
(function () {
  const box = document.getElementById('bcBox'); if (!box || typeof api === 'undefined') return;      // api.js declares `const api`: it is a global name but NOT window.api
  const $ = (id) => document.getElementById(id);
  const E = (t) => String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SIZES = ['10 ml', '20 ml', '50 ml', '100 ml'];                      // the first four columns of the sheet (50/60 ml is one column)
  const SKIP = { FREE: 1, NONAME: 1, NOTFOUND: 1 };                          // rows without a perfume name get no label
  const DIM = { in24: [101.6, 50.8, '2 × 4 inch'], cm24: [40, 20, '2 × 4 cm'], st: [31, 21, '31 × 21 mm (Store label)'] };
  let O = { size: 'in24', mfg: 1, exp: 1, bat: 1, mrp: 1 }; try { O = Object.assign(O, JSON.parse(localStorage.getItem('sku_bc_opts') || '{}')); } catch (e) { } if (!DIM[O.size]) O.size = 'in24';
  const keep = () => { try { localStorage.setItem('sku_bc_opts', JSON.stringify(O)); } catch (e) { } };
  let ST = null, tab = 'auto', sheet = [], sheetTitle = '', skuList = null, busy = false, seq = 0;
  const day = () => ($('dDay') && $('dDay').value) || '';
  const sheetD = () => { try { return D; } catch (e) { return null; } };     // the page's own data (top-level let of the page script)
  const chainD = () => { try { return CH; } catch (e) { return null; } };
  const say = (t, bad) => { const m = $('bcMsg'); if (!m) return; m.textContent = t; m.style.display = t ? '' : 'none'; m.style.background = bad ? '#fef2f2' : '#f0fdf4'; m.style.color = bad ? '#991b1b' : '#166534'; m.style.borderColor = bad ? '#fecaca' : '#bbf7d0'; };

  function needBottles() {      // [{sku, name, size, n}] of the day, from the sheet on the screen
    const d = sheetD(), o = []; if (!d || !d.rows) return o;
    d.rows.forEach((r) => { if (SKIP[r.sku]) return; SIZES.forEach((s, i) => { const n = Number((r.q || [])[i] || 0); if (n > 0) o.push({ sku: String(r.sku), name: String(r.name || ''), size: s, n }); }); });
    return o;
  }
  /** why "Perfume bottles" is smaller than the TOTAL of the sheet: every piece that gets no label is named and counted, so the two numbers always add up */
  function gapNote(labels) {
    const d = sheetD(); if (!d || !d.rows) return ''; let free = 0, nn = 0, nf = 0, other = 0, total = 0;
    d.rows.forEach((r) => { const q = r.q || []; let b = 0, o = 0; q.forEach((v, i) => { v = Number(v || 0); total += v; if (i < 4) b += v; else o += v; }); other += o; if (r.sku === 'FREE') free += b; else if (r.sku === 'NONAME') nn += b; else if (r.sku === 'NOTFOUND') nf += b; });
    const parts = [[free, 'free gift'], [nn, 'No Name'], [nf, 'not in the SKU list'], [other, 'other products (shower gel, solid perfume, balm, attar, soap, car perfume)']].filter((x) => x[0] > 0), gap = total - labels;
    return '<div style="margin-top:8px;font-size:12px;border:1px solid var(--line);border-radius:9px;padding:7px 10px;line-height:1.5"><b>' + total.toLocaleString('en-IN') + '</b> pieces in the sheet'
      + (gap > 0 ? ' − <b>' + gap.toLocaleString('en-IN') + '</b> without a label (' + parts.map((x) => x[0] + ' ' + x[1]).join(' · ') + ')' : '') + ' = <b>' + labels.toLocaleString('en-IN') + '</b> perfume bottles with a label.</div>';
  }
  function needChains() { const c = chainD(), o = []; if (!c || !c.rows || c.from !== day()) return o; c.rows.forEach((r) => { const n = Number(r.qty || 0); if (n > 0) o.push({ sku: String(r.sku || ''), name: String(r.name || ''), size: 'Chain', n, mrp: n ? Math.round(Number(r.amount || 0) / n) : 0 }); }); return o; }
  const madeOf = (it) => Math.min(it.n, Number(((ST || {}).made || {})[it.sku + '|' + it.size] || 0));
  const madeCh = (it) => Math.min(it.n, Number(((ST || {}).made_chain || {})[it.name.toLowerCase()] || 0));

  function shell() {
    box.innerHTML = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><b>🏷 Barcode generator</b><span style="font-size:12px;color:var(--muted)">online orders · the day picked at the top</span></div>'
      + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0" id="bcTabs"></div><div id="bcPane"></div>'
      + '<div id="bcMsg" style="display:none;margin-top:8px;border:1px solid;border-radius:9px;padding:7px 10px;font-size:12.5px"></div>'
      + '<details style="margin-top:10px" id="bcDes"><summary style="cursor:pointer;font-weight:700;font-size:13px">Label design</summary><div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:8px;font-size:13px">'
      + '<label>Sticker size <select id="bcSz">' + Object.keys(DIM).map((k) => '<option value="' + k + '"' + (O.size === k ? ' selected' : '') + '>' + DIM[k][2] + '</option>').join('') + '</select></label>'
      + [['mrp', 'MRP'], ['bat', 'Batch code'], ['mfg', 'MFG date'], ['exp', 'EXP date (4 years)']].map((x) => '<label><input type="checkbox" data-o="' + x[0] + '"' + (O[x[0]] ? ' checked' : '') + '> ' + x[1] + '</label>').join('') + '</div></details>'
      + '<div id="bcSheet" style="margin-top:10px"></div>';
    $('bcSz').onchange = (e) => { O.size = e.target.value; keep(); drawSheet(); };
    box.querySelectorAll('[data-o]').forEach((c) => { c.onchange = () => { O[c.dataset.o] = c.checked ? 1 : 0; keep(); drawSheet(); }; });
    $('bcTabs').onclick = (e) => { const b = e.target.closest('[data-t]'); if (!b) return; tab = b.dataset.t; pane(); };
  }
  const btn = (id, txt, bg, dis) => '<button type="button" class="btn" id="' + id + '"' + (dis ? ' disabled' : '') + ' style="' + (bg ? 'background:' + bg + ';border-color:' + bg : 'background:#fff;color:#111;border:1px solid var(--line)') + '">' + txt + '</button>';
  function pane() {
    $('bcTabs').innerHTML = [['auto', '① From the day\'s orders'], ['man', '② Manual'], ['ch', '③ Chains']].map((t) => '<button type="button" data-t="' + t[0] + '" class="btn" style="padding:6px 12px;' + (tab === t[0] ? 'background:#0f766e;border-color:#0f766e' : 'background:#fff;color:#111;border:1px solid var(--line)') + '">' + t[1] + '</button>').join('');
    const p = $('bcPane');
    if (tab === 'auto' || tab === 'ch') {
      const ch = tab === 'ch', items = ch ? needChains() : needBottles(), all = items.reduce((a, x) => a + x.n, 0), done = items.reduce((a, x) => a + (ch ? madeCh(x) : madeOf(x)), 0), left = all - done;
      const ready = ch ? !!(chainD() && chainD().from === day()) : !!sheetD();
      p.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:6px;font-size:11.5px;color:var(--muted)">'
        + [[ch ? 'Chain pieces' : 'Perfume bottles', all], [ch ? 'Different chains' : 'Perfume + size lines', items.length], ['Already made', done], ['New to make', left]].map((k) => '<div style="border:1px solid var(--line);border-radius:9px;padding:6px 9px">' + k[0] + '<b style="display:block;font-size:18px;color:#111">' + k[1] + '</b></div>').join('') + '</div>'
        + (ready ? '' : '<div style="margin-top:8px;font-size:12.5px;color:var(--muted)">Waiting for the ' + (ch ? 'chain report' : 'SKU sheet') + ' of this day…</div>')
        + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">' + btn('bcGo', left ? '🏷 Make ' + left + ' new barcodes' : (all ? '✓ All barcodes of this day are made' : 'Nothing to make'), '#0f766e', !left || busy || !ST) + btn('bcAll', 'Show all labels of the day', '', !done || busy) + '</div>'
        + (ch ? '' : gapNote(all));
      $('bcGo').onclick = () => make(ch ? 'chain' : 'bottle', items);
      $('bcAll').onclick = () => reprint(ch ? 'chain' : 'bottle');
    } else {
      const mrp = (ST || {}).mrp || {};
      p.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;align-items:end;font-size:12px">'
        + '<label style="grid-column:1/-1">Perfume<input id="bcMq" list="bcMl" placeholder="Type a name or SKU…" autocomplete="off" style="width:100%;box-sizing:border-box"><datalist id="bcMl"></datalist></label>'
        + '<label>Size<select id="bcMs" style="width:100%">' + SIZES.map((s) => '<option' + (s === '20 ml' ? ' selected' : '') + '>' + s + '</option>').join('') + '</select></label>'
        + '<label>How many<input id="bcMn" type="number" min="1" max="500" value="1" style="width:100%;box-sizing:border-box"></label>' + '<div>' + btn('bcMg', '🏷 Make barcodes', '#0f766e', busy) + '</div></div>'
        + '<div style="margin-top:6px;font-size:11.5px;color:var(--muted)">MRP by size: ' + SIZES.map((s) => s + ' ₹' + Number(mrp[s] || 0).toLocaleString('en-IN')).join(' · ') + '. Manual labels are dated today.</div>'
        + '<div style="margin-top:8px">' + btn('bcMa', 'Show today\'s manual labels', '', busy) + '</div>';
      const fill = () => { $('bcMl').innerHTML = (skuList || []).map((r) => '<option value="' + E(r[1]) + '">SKU ' + E(r[0]) + '</option>').join(''); };
      if (skuList) fill(); else api.get('sku.list').then((r) => { skuList = (r.rows || []).filter((x) => x[1]); if ($('bcMl')) fill(); }).catch(() => { });
      $('bcMg').onclick = manual; $('bcMa').onclick = () => reprint('manual');
    }
  }
  async function make(kind, items) {
    if (busy || !items.length) return; busy = true; pane(); say('Making the barcodes…');
    try {
      const r = await api.post('sku.bc_make', { day: day(), kind, items });
      sheet = r.labels || []; sheetTitle = sheet.length + ' new label' + (sheet.length === 1 ? '' : 's') + ' · ' + day().split('-').reverse().join('-'); say(r.made ? r.made + ' new barcodes made. Download the PDF below.' : 'Nothing new: every barcode of this day was made already.');
      await state(); drawSheet();
    } catch (e) { say(e.message, true); }
    busy = false; pane();
  }
  async function manual() {
    const nm = $('bcMq').value.trim(), n = Math.max(1, Math.min(500, parseInt($('bcMn').value, 10) || 0)), hit = (skuList || []).find((r) => String(r[1]).toLowerCase() === nm.toLowerCase() || String(r[0]).toLowerCase() === nm.toLowerCase());
    if (!hit) { say('Pick a perfume from the list (type a few letters, then choose it).', true); return; }
    busy = true; say('Making the barcodes…');
    try { const r = await api.post('sku.bc_manual', { items: [{ sku: String(hit[0]), name: String(hit[1]), size: $('bcMs').value, n }] }); sheet = r.labels || []; sheetTitle = sheet.length + ' manual label' + (sheet.length === 1 ? '' : 's'); say(r.made + ' barcodes made. Download the PDF below.'); drawSheet(); }
    catch (e) { say(e.message, true); }
    busy = false; pane();
  }
  async function reprint(kind) {
    busy = true; say('Reading the labels…');
    try { const r = await api.get('sku.bc_labels', { day: kind === 'manual' ? '' : day(), kind }); sheet = r.labels || []; sheetTitle = sheet.length + ' label' + (sheet.length === 1 ? '' : 's') + ' (same numbers as before)'; say(sheet.length ? '' : 'No label of that kind for this day yet.'); drawSheet(); }
    catch (e) { say(e.message, true); }
    busy = false; pane();
  }
  const dShort = (s) => { const p = String(s || '').split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0].slice(2) : ''; };
  /** one label as an element sized in mm (the same node is used on the screen and in the PDF) */
  // type sizes (pt) and the bar height (mm) of each sticker size, chosen so every line is readable when printed — a small sticker is NOT a shrunk big one
  const SPEC = { in24: { px: 3.5, py: 2, name: 20, l2: 16, bar: 17, num: 12, bot: 11, wide: 1, zoom: 1 }, cm24: { px: 1.5, py: 0.8, name: 7.6, l2: 6.6, bar: 6.4, num: 5.6, bot: 5.8, zoom: 2.3 }, st: { px: 1.4, py: 0.7, name: 6.6, l2: 6, bar: 7.6, num: 5.8, bot: 5.3, zoom: 2.5 } };
  /** one label as an element sized in mm (the same node is used on the screen and in the PDF) */
  function labelNode(l) {
    const W = DIM[O.size][0], H = DIM[O.size][1], S = SPEC[O.size], d = document.createElement('div');
    d.style.cssText = 'width:' + W + 'mm;height:' + H + 'mm;box-sizing:border-box;padding:' + S.py + 'mm ' + S.px + 'mm;background:#fff;color:#000;font-family:Arial,Helvetica,sans-serif;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden';
    const nm = String(l.name || ''), fs = Math.max(S.name * 0.6, Math.min(S.name, (W - 2 * S.px) / (Math.max(1, nm.length) * 0.2)));      // the name is as big as fits on ONE line
    const mrp = O.mrp && l.mrp ? 'MRP ₹' + Number(l.mrp).toLocaleString('en-IN') : '', bat = O.bat ? String(l.batch || '') : '';
    const mfg = O.mfg ? (l.kind === 'chain' ? 'PKD ' : 'MFG ') + dShort(l.mfg) : '', exp = O.exp && l.kind !== 'chain' ? 'EXP ' + dShort(l.exp) : '';
    const row = (cells, pt) => '<div style="display:flex;justify-content:space-between;gap:1.5mm;font-weight:800;font-size:' + pt + 'pt;line-height:1.15;white-space:nowrap">' + cells.map((c) => '<span>' + E(c) + '</span>').join('') + '</div>';
    const top = document.createElement('div');
    // big sticker: size and MRP large under the name, then batch · MFG · EXP in one clear line at the bottom. Small stickers: the batch sits beside the size, the dates have the bottom line to themselves.
    top.innerHTML = '<div style="font-weight:800;font-size:' + fs + 'pt;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + E(nm) + '</div>' + (S.wide ? row([l.size, mrp], S.l2) : row([[l.size, mrp].filter(Boolean).join(' · '), bat], S.l2));
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    try { JsBarcode(svg, String(l.code), { format: 'CODE128C', width: 3, height: 80, margin: 0, displayValue: false }); svg.setAttribute('viewBox', '0 0 ' + String(svg.getAttribute('width')).replace('px', '') + ' 80'); svg.removeAttribute('width'); svg.removeAttribute('height'); } catch (e) { }
    svg.setAttribute('preserveAspectRatio', 'none'); svg.style.cssText = 'width:100%;height:' + S.bar + 'mm;display:block;flex:none';
    const num = document.createElement('div'); num.style.cssText = 'text-align:center;font:700 ' + S.num + 'pt monospace;letter-spacing:' + (S.wide ? 1 : 0.4) + 'mm;line-height:1.1'; num.textContent = l.code;
    const bot = document.createElement('div'); const cells = (S.wide ? [bat ? 'BATCH ' + bat : '', mfg, exp] : [mfg, exp]).filter(Boolean);
    bot.innerHTML = cells.length === 1 ? '<div style="text-align:center;font-weight:800;font-size:' + S.bot + 'pt;line-height:1.15">' + E(cells[0]) + '</div>' : row(cells, S.bot);
    d.append(top, svg, num, bot); return d;
  }
  function drawSheet() {
    const s = $('bcSheet'); if (!s) return; if (!sheet.length) { s.innerHTML = ''; return; }
    s.innerHTML = '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:8px"><b>' + E(sheetTitle) + '</b><span style="flex:1"></span>' + btn('bcPdf', '⬇ Download PDF / Print', '#b91c1c') + btn('bcClr', 'Clear', '') + '</div>'
      + '<div id="bcPrev" style="display:flex;flex-wrap:wrap;gap:6px;max-height:420px;overflow:auto;background:#f1f5f9;border-radius:10px;padding:8px"></div>' + (sheet.length > 12 ? '<div style="font-size:11.5px;color:var(--muted);margin-top:4px">The first 12 are shown here' + (SPEC[O.size].zoom > 1 ? ', enlarged to read them' : '') + '. The PDF has all ' + sheet.length + ' in the true size.</div>' : '');
    const pv = $('bcPrev'); sheet.slice(0, 12).forEach((l) => { const n = labelNode(l); n.style.border = '1px dashed #94a3b8'; n.style.borderRadius = '4px'; n.className = 'bc-keep'; n.style.zoom = SPEC[O.size].zoom; pv.appendChild(n); });      // background-image: the dark mode of the HRMS (a colour flip of the whole page) leaves such an element as it is, so the label stays white paper with black bars
    $('bcPdf').onclick = pdf; $('bcClr').onclick = () => { sheet = []; drawSheet(); };
  }
  /** ⬇ the PDF: one label on each page of exactly the sticker size — the browser's own "Save as PDF" (the same way as Chain PDF) */
  function pdf() {
    if (!sheet.length) return; const W = DIM[O.size][0], H = DIM[O.size][1], w = window.open('', '_blank'); if (!w) { say('The browser blocked the new tab. Allow pop-ups for this page, then press again.', true); return; }
    const tmp = document.createElement('div'); sheet.forEach((l) => { const n = labelNode(l); n.style.pageBreakAfter = 'always'; n.style.breakAfter = 'page'; tmp.appendChild(n); });
    w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>barcodes_' + E(day()) + '</title><style>@page{size:' + W + 'mm ' + H + 'mm;margin:0}html,body{margin:0;padding:0}@media screen{body{background:#e5e7eb;padding:10px}body>div>div{margin:0 auto 8px;box-shadow:0 1px 4px rgba(0,0,0,.25)}.tip{font:13px Arial;margin:0 auto 10px;max-width:520px;background:#fff;border-radius:8px;padding:10px}}@media print{.tip{display:none}}</style></head><body><div class="tip"><b>' + sheet.length + ' labels.</b> In the print window choose <b>Save as PDF</b> (or your label printer), paper = ' + E(DIM[O.size][2]) + ', margins <b>None</b>, scale <b>100%</b>.</div><div>' + tmp.innerHTML + '</div></body></html>');
    w.document.close(); setTimeout(() => { try { w.focus(); w.print(); } catch (e) { } }, 400);
  }
  async function state() { const d = day(); if (!d) return; const my = ++seq; try { const r = await api.get('sku.bc_state', { day: d }); if (my !== seq) return; ST = r; } catch (e) { if (my === seq) { ST = null; say(e.message, true); } } }
  // the dark mode of the HRMS flips the colours of the whole page: a label is turned back, so it always shows as white paper with black bars (small stickers are shown enlarged on the screen, the PDF has the true size)
  { const st = document.createElement('style'); st.textContent = 'html.hrms-dk .bc-keep,html.hrms-dkf .bc-keep{filter:invert(1) hue-rotate(180deg)}'; document.head.appendChild(st); }
  shell(); pane();
  window.SkuBC = { refresh: async function () { if (!$('bcPane')) return; await state(); pane(); } };
  window.SkuBC.refresh();
})();
