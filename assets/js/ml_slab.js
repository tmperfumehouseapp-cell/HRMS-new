/**
 * ML price slab — the calculation shared by the Commission page, My Sales and the Commission Rules editor.
 *
 * chart = { on, cap, nocode, prepaid, cols:[7, 6.5, …, 0.1], rows:[{ ml:100, p:[1810, 1760, …] }, …] }  (from api: ml_slab.get)
 *
 *  1. Row: the order's total ML (free ML included). A size between two rows (e.g. 165 ml) uses prices in between.
 *     A size outside the chart (below the first row / above the last) → no slab (the order keeps the normal commission).
 *  2. Price: prepaid orders are looked up at their price before the prepaid discount (chart.prepaid %, the company bears it).
 *  3. %: price at or above the first column → the first column's % (the most). Exactly a column's price → that %.
 *     Between two columns: chart.mode 'step' (the chart's rule) → the LOWER column's % (₹1,690 between ₹1,700 = 6% and ₹1,670 = 4% → 4%);
 *     'between' → a % in between, in proportion. Below the last column → chart.floor % (2.8%, never less; no code → half = 1.4%).
 *  4. Daily target: hit === true (achieved) → + chart.bonus % on top (20 → 100 becomes 120); hit === false (not achieved) → at most
 *     chart.cap % (0 / empty = no cut); hit === null (no target that day / not known) → nothing.   5. No order code → chart.nocode % of it (50 = half) — unless the day has enough codes (chart.codepct, 65 = 65% of the day's orders; see tagCodes) → full.
 *  0. More than chart.maxdisc % off the size's chart price → no commission at all (0 / empty = no limit).
 */
(function () {
  if (window.MLSLAB) return;
  const r2 = (v) => Math.round(v * 100) / 100;

  // Prices for one size: the row itself, or in between the two nearest rows. null = outside the chart.
  function rowFor(chart, ml) {
    const rows = (chart && chart.rows) || [];
    ml = Number(ml);
    if (!rows.length || !(ml > 0)) return null;
    if (ml < rows[0].ml || ml > rows[rows.length - 1].ml) return null;
    for (let i = 0; i < rows.length; i++) {
      const a = rows[i];
      if (a.ml === ml) return { p: a.p.map(Number), how: a.ml + ' ml row' };
      const b = rows[i + 1];
      if (b && ml > a.ml && ml < b.ml) {
        const f = (ml - a.ml) / (b.ml - a.ml);
        return { p: a.p.map((v, j) => (Number(v) > 0 && Number(b.p[j]) > 0) ? Number(v) + (Number(b.p[j]) - Number(v)) * f : 0), how: 'between ' + a.ml + ' and ' + b.ml + ' ml rows' };
      }
    }
    return null;
  }

  // The slab % for a size and a price, before the target / code rules. null = outside the chart.
  function pctOf(chart, ml, price) {
    const row = rowFor(chart, ml);
    if (!row) return null;
    // a blank price column (the 5% column nobody priced yet) is skipped, so it never changes who earns what; idx keeps each column's place in the chart
    const cols = [], p = [], idx = []; row.p.forEach((v, i) => { if (Number(v) > 0) { cols.push(Number(chart.cols[i])); p.push(Number(v)); idx.push(i); } });
    if (!p.length) return null;
    price = Number(price) || 0;
    if (price >= p[0]) return { pct: cols[0], col: idx[0], row, why: 'chart price or more' };
    for (let j = 0; j < p.length - 1; j++) {
      if (price <= p[j] && price >= p[j + 1]) {
        if (price === p[j + 1] || p[j] === p[j + 1]) return { pct: cols[j + 1], col: idx[j + 1], row, why: 'exactly a chart price' };
        if (chart.mode !== 'between') return { pct: cols[j + 1], col: idx[j + 1], row, why: 'below ₹' + Math.round(p[j]).toLocaleString('en-IN') + ' → the ₹' + Math.round(p[j + 1]).toLocaleString('en-IN') + ' column (' + cols[j + 1] + '%)' };
        const f = (price - p[j + 1]) / (p[j] - p[j + 1]);
        return { pct: cols[j + 1] + (cols[j] - cols[j + 1]) * f, col: idx[j + 1], row, between: [j, j + 1], why: 'between ₹' + Math.round(p[j + 1]).toLocaleString('en-IN') + ' (' + cols[j + 1] + '%) and ₹' + Math.round(p[j]).toLocaleString('en-IN') + ' (' + cols[j] + '%)' };
      }
    }
    const fl = Number(chart.floor) || 0;
    if (fl > 0) return { pct: fl, col: -1, row, floor: true, why: 'below ₹' + Math.round(p[p.length - 1]).toLocaleString('en-IN') + ' → lowest ' + fl + '%' };
    return { pct: 0, col: -1, row, why: 'below the last price — more than ' + (chart.names && chart.names[chart.names.length - 1] ? chart.names[chart.names.length - 1].replace(/^Up to /i, '') : 'the last column') + ' off' };
  }

  /**
   * One order's commission. o = { ml, amt, pre (prepaid), code }, hit = daily target complete (true when no target is set).
   * Returns null when the slab does not apply (switched off, no ML known, size outside the chart),
   * else { v: ₹, pct: final %, base: slab %, steps: [text], ml, look: price looked up }.
   */
  // The chart's dates (business days 'YYYY-MM-DD'): no From = from the start, no To = keeps going. No day given = applies.
  function activeOn(chart, day) {
    if (!chart || !day) return true;
    if (chart.from && day < chart.from) return false;
    if (chart.to && day > chart.to) return false;
    return true;
  }
  const datesTxt = (c) => !c || (!c.from && !c.to) ? 'all days' : (c.from ? 'from ' + c.from : 'until') + (c.to ? (c.from ? ' to ' : ' ') + c.to : ' onwards');

  // 📅 Special days (Commission Rules → "Specific date / person"): chart.special = [{ id, from, to, emps:[ids] (empty = everyone),
  // set:{ bonus, cap, nocode, codepct, maxdisc, maxdisc_for, floor, prepaid }, note }]. On those days these numbers replace the chart's own.
  // Person-specific entries win over "everyone"; among equals the later one wins. Same as api.php ml_slab_for().
  const SP_KEYS = ['bonus', 'cap', 'nocode', 'codepct', 'maxdisc', 'maxdisc_for', 'floor', 'prepaid'];
  function specialsOn(chart, day, emp) {
    const l = (chart && chart.special) || [];
    if (!l.length || !day) return [];
    emp = Number(emp) || 0;
    return l.map((x, i) => ({ x, i, who: (x.emps || []).length ? 1 : 0 }))
      .filter(({ x }) => x.from <= day && x.to >= day && (!(x.emps || []).length || x.emps.map(Number).indexOf(emp) >= 0))
      .sort((a, b) => a.who - b.who || a.i - b.i).map((h) => h.x);
  }
  function forDay(chart, day, emp) {
    const hits = specialsOn(chart, day, emp);
    if (!hits.length) return chart;
    const c = Object.assign({}, chart), lab = [];
    hits.forEach((x) => { SP_KEYS.forEach((k) => { if (x.set && x.set[k] != null && x.set[k] !== '') c[k] = x.set[k]; }); lab.push(x.note || 'special day'); });
    c.special_on = lab; c.special_day = day;
    return c;
  }
  const dShort = (d) => { const t = new Date(d + 'T00:00:00'); return isNaN(t) ? d : t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); };

  // Over-target steps: [[over %, bonus %], …] (default +30% → 30% extra, +40% → 40%, +60% → 60%, +100% → 100%). The bonus = the highest step reached,
  // never below the plain target bonus. over = how far the day's sales are above the target, in % (0 = just reached). Same as ml_bonus_for() in PHP.
  const TIERS = [[30, 30], [40, 40], [60, 60], [100, 100]];
  function tiersOf(chart) {
    const t = Array.isArray(chart && chart.tiers) ? chart.tiers : TIERS;
    return t.filter((x) => Array.isArray(x) && Number(x[0]) > 0).map((x) => [Number(x[0]), Number(x[1]) || 0]).sort((a, b) => a[0] - b[0]);
  }
  function bonusFor(chart, over) {
    let b = Number(chart && chart.bonus) || 0;
    if (over == null || !(over >= 0)) return b;
    tiersOf(chart).forEach(([o, v]) => { if (over + 1e-6 >= o && v > b) b = v; });
    return b;
  }
  // One price chart section: attar / shower gel / others in an order are priced on their own charts (pieces). Their money (o.x_amt) is taken
  // out of the order before the perfume part is looked up on this slab by perfume ml, and their own commission (o.x_com, worked out on the server) is added.
  function orderCom(chart, o, hit, over) {
    const xa = Number(o && o.x_amt) || 0, xc = Number(o && o.x_com) || 0;
    // a combo (2+ kinds, e.g. perfume 150 ml + 2 attar + 1 gel): the whole order is on its combo row (worked out on the server); no row yet = 0 with a note
    if (o && o.x_all) return chart && Number(chart.on) ? { v: xc, pct: o.x_pct == null ? 0 : Number(o.x_pct), base: o.x_pct == null ? 0 : Number(o.x_pct), steps: [o.x_note || 'Combo'], ml: Number(o.ml) || 0, look: Number(o.amt) || 0, disc: 0, overMax: false, combo: true, missing: o.x_pct == null } : null;
    if (!(xa > 0)) return orderCom0(chart, o, hit, over);
    const r = orderCom0(chart, Object.assign({}, o, { amt: Math.max(0, (Number(o.amt) || 0) - xa) }), hit, over);
    const note = 'attar / shower gel / others ₹' + Math.round(xa).toLocaleString('en-IN') + ' → ₹' + (Math.round(xc * 100) / 100) + (o.x_note ? ' (' + o.x_note + ')' : '');
    if (!r) return chart && Number(chart.on) ? { v: xc, pct: 0, base: 0, steps: [note], ml: Number(o.ml) || 0, look: 0, disc: 0, overMax: false } : null;
    r.v += xc; r.steps = (r.steps || []).concat([note]); return r;
  }
  function orderCom0(chart, o, hit, over) {
    if (!chart || !Number(chart.on) || o.ml == null || !(Number(o.ml) > 0)) return null;
    if (o.day && !activeOn(chart, o.day)) return null;     // order outside the chart's dates: normal commission
    if (o.day && chart.special && chart.special.length && !chart.special_on) chart = forDay(chart, o.day, o.emp);
    const spT = chart.special_on ? ' (📅 special day ' + dShort(chart.special_day) + ')' : '';
    const amt = Number(o.amt) || 0;
    const look = o.pre && Number(chart.prepaid) > 0 ? amt / (1 - Number(chart.prepaid) / 100) : amt;
    const s = pctOf(chart, o.ml, look);
    if (!s) return null;
    const steps = [Number(o.ml) + ' ml · ' + s.row.how + ' · ₹' + Math.round(look).toLocaleString('en-IN') + (look !== amt ? ' (prepaid, before ' + chart.prepaid + '% off)' : '') + ' → ' + r2(s.pct) + '% (' + s.why + ')'];
    let pct = s.pct;
    // Discount = how far the price the customer actually paid is below the size's chart price (first column); free ML is already
    // in the size. The prepaid 2% is NOT added back here: 100 ml chart ₹1,810, 10% max → anything below ₹1,629 pays nothing.
    const cp = Number(s.row.p[0]), disc = cp > 0 ? Math.max(0, (cp - amt) / cp * 100) : 0, maxd = Number(chart.maxdisc) || 0;
    // The limit is for orders WITHOUT a code (maxdisc_for = 'nocode', default); 'all' limits every order. With a code, any discount is allowed.
    const maxOn = maxd > 0 && (chart.maxdisc_for === 'all' || !o.code);
    if (maxOn && disc > maxd + 1e-9) {
      steps.push('discount ' + r2(disc) + '% is more than the ' + maxd + '% max' + (chart.maxdisc_for === 'all' ? '' : ' for orders without code') + spT + ' → no commission');
      return { v: 0, pct: 0, base: r2(s.pct), steps, ml: Number(o.ml), look, disc: r2(disc), overMax: true, special: chart.special_on || null };
    }
    if (hit === false && Number(chart.cap) > 0 && pct > Number(chart.cap)) { pct = Number(chart.cap); steps.push('daily target incomplete → max ' + chart.cap + '%' + spT); }
    // 70% code rule: o.codeOk (set by tagCodes) = enough of that day's orders have a code → the no-code cut is skipped for every order of the day.
    if (!o.code && o.codeOk) steps.push('no code, but the day\'s code goal is reached → full' + spT);
    else if (!o.code && Number(chart.nocode) !== 100) { pct = pct * Number(chart.nocode) / 100; steps.push('no code → ' + chart.nocode + '% of it' + spT); }
    // Below the lowest slab % (the last column, 0.1%) = nothing: e.g. 0.1% without code = 0.05% → 0%.
    // (Not with a lowest % set: 2.8% without code = 1.4% is paid.)
    const minPct = Number(chart.floor) > 0 ? 0 : Number(chart.cols[chart.cols.length - 1]) || 0;
    if (pct > 0 && pct < minPct - 1e-9) { steps.push(r2(pct) + '% is below the lowest slab (' + minPct + '%) → 0%'); pct = 0; }
    // Daily target achieved → bonus on top of the commission (20% → ₹100 becomes ₹120). Not achieved → no cut, no bonus.
    const bonus = hit === true ? bonusFor(chart, over) : (Number(chart.bonus) || 0);
    if (hit === true && bonus > 0 && pct > 0) { pct = pct * (1 + bonus / 100); steps.push('daily target achieved → +' + bonus + '% bonus' + spT); }
    return { v: amt * pct / 100, pct: r2(pct), base: r2(s.pct), steps, ml: Number(o.ml), look, disc: r2(disc), overMax: false, bonus: hit === true && bonus > 0, bonusPct: bonus, special: chart.special_on || null };
  }

  // An order's ML in parts: total (free ML included), extra = the free 10 ml pieces, base = what was bought. null = no ML known.
  function parts(o) {
    if (!o || o.ml == null || o.ml === '') return null;
    const total = Number(o.ml) || 0, extra = Math.max(0, Math.min(total, Number(o.ml_extra) || 0));
    return { total, extra, base: total - extra };
  }
  // A not-switched-on chart still shows what the order WOULD earn (Orders / Order Allocation pages show the slab before it is switched on).
  const preview = (c) => c ? Object.assign({}, c, { on: 1 }) : null;
  // Prepaid = not COD / pending (same as the Commission page): paid and partially paid orders are looked up before the 2% prepaid discount.
  const isPre = (fs) => { fs = String(fs || '').toLowerCase(); return fs !== '' && fs.indexOf('cod') === -1 && fs.indexOf('pending') === -1; };

  /**
   * Extra ML by price: the size's chart price (first column) ÷ its ML = price of 1 ml; the price paid ÷ that = ML the customer paid
   * for; ML in the box − ML paid for = extra ML given (0 when the customer paid the chart price or more). Prepaid orders are taken at
   * their price before the prepaid discount (the company bears it). null = size outside the chart.
   * Example: 100 ml chart ₹1,810 → ₹18.10 / ml; ₹1,700 pays for 93.9 ml → 6.1 ml extra (₹110).
   */
  function extraByPrice(chart, ml, amt, pre) {
    ml = Number(ml); amt = Number(amt) || 0;
    const row = rowFor(chart, ml);
    if (!row || !(ml > 0)) return null;
    const cp = Number(row.p[0]), per = cp / ml;
    const look = pre && Number(chart.prepaid) > 0 ? amt / (1 - Number(chart.prepaid) / 100) : amt;
    const paid = per > 0 ? look / per : ml;
    const extra = Math.max(0, ml - paid);
    return { chart: cp, per, look, paid, extra: Math.round(extra * 10) / 10, value: Math.max(0, cp - look) };
  }

  /**
   * ⚡ Flash Offer (same rule as api.php flash_offer_pct_for / coach_day): an order whose TOTAL ML is one of an offer's sizes and that was
   * placed inside the offer's hours pays the offer's own flat % — whatever the order's price is — instead of the slab. No code → the
   * offer's own no-code share of it (nocode 50 = half: 6% → 3%). No cap / daily bonus / floor.
   * offers = every offer { id, items:[{ml,price}], pct, nocode, started_at, ends_at, note } (api: flash_offer.get → all); created = the order's time.
   * flashFor() returns { pct, nocode, id, note } or null; flashCom() prices one order with it (same shape as orderCom()).
   */
  function flashFor(offers, ml, created) {
    ml = Math.round(Number(ml));
    if (!(ml > 0) || !created || !offers || !offers.length) return null;
    const cs = String(created), t = new Date(/^\d{4}-\d\d-\d\d \d/.test(cs) ? cs.replace(' ', 'T') : cs).getTime();
    if (isNaN(t)) return null;
    for (let i = 0; i < offers.length; i++) {
      const x = offers[i];
      const s = new Date(String(x.started_at || '').replace(' ', 'T')).getTime(), e = new Date(String(x.ends_at || '').replace(' ', 'T')).getTime();
      if (isNaN(s) || isNaN(e) || t < s || t > e) continue;
      if ((x.items || []).some((it) => Math.round(Number(it.ml)) === ml)) return { pct: Number(x.pct) || 0, nocode: x.nocode == null || x.nocode === '' ? 50 : Number(x.nocode), id: String(x.id || ''), note: x.note || '' };
    }
    return null;
  }
  function flashCom(f, o) {
    const full = !!(o.code || o.codeOk);      // a code on the order, or the day's 70% code goal reached
    const amt = Number(o.amt) || 0, pct = f.pct * (full ? 1 : f.nocode / 100);
    const steps = ['⚡ flash offer · ' + Number(o.ml) + ' ml → ' + r2(f.pct) + '%' + (full ? '' : ' → no code: ' + r2(pct) + '%')];
    return { v: amt * pct / 100, pct: r2(pct), base: r2(f.pct), steps, ml: Number(o.ml), look: amt, disc: 0, overMax: false, bonus: false, special: null, flash: true };
  }

  /**
   * 70% code rule (Commission Rules → "Order without code" → "Full commission once __% of the day's orders have a code").
   * codeNeed(6, 65) = 4, (10, 65) = 7: round(n × % / 100), at least 1. Same as api.php ml_code_need().
   * tagCodes(chart, orders, defEmp): orders = [{ emp, day, code }…] (all of a person's orders, any size). Sets o.codeOk = true on every
   * order of a person-day that has enough codes, and returns { 'emp|day': { n, have, need, left, ok, pct } }. orderCom() / flashCom() read o.codeOk.
   */
  function codeNeed(n, pct) { n = Number(n) || 0; pct = Number(pct) || 0; return n <= 0 || pct <= 0 ? 0 : Math.max(1, Math.round(n * pct / 100)); }
  function tagCodes(chart, orders, defEmp) {
    const map = {};
    (orders || []).forEach((o) => {
      const emp = o.emp != null ? o.emp : defEmp, k = emp + '|' + o.day, x = map[k] = map[k] || { n: 0, have: 0, emp, day: o.day };
      x.n++; if (o.code) x.have++;
    });
    Object.keys(map).forEach((k) => {
      const x = map[k], c = chart && chart.special && chart.special.length ? forDay(chart, x.day, x.emp) : chart;
      const pct = c && c.codepct != null && c.codepct !== '' ? Number(c.codepct) : 0;
      x.pct = pct; x.need = codeNeed(x.n, pct); x.ok = x.need > 0 && x.have >= x.need; x.left = Math.max(0, x.need - x.have);
    });
    (orders || []).forEach((o) => { const x = map[(o.emp != null ? o.emp : defEmp) + '|' + o.day]; o.codeOk = !!(x && x.ok); });
    return map;
  }

  window.MLSLAB = { bonusFor, tiersOf, codeNeed, tagCodes, rowFor, pctOf, orderCom, forDay, specialsOn, parts, preview, isPre, extraByPrice, activeOn, datesTxt, flashFor, flashCom, on: (c) => !!(c && Number(c.on)) };
})();
