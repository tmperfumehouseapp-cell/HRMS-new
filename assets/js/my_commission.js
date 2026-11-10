/* my_commission.js — the salesperson's own commission, worked out in the browser from their own orders and the commission rules.
 * MOVED UNCHANGED from the old My Sales page (the same functions: calcCommission, shareFor, calcPrice, buildOrders, aggRow, period, archiveRows),
 * only the page / panel bookkeeping was left out. Needs api.js, ml_slab.js (window.MLSLAB) and flash_offer.js.
 *   MyCommission.month(bizDay)         → { month:{ach,n,target,amount,rate,rated,manual}, today:{…}, rows:[…archive rows of the month], note }
 *   MyCommission.archive('YYYY-MM')    → archive rows (day, n, ach, tg, st) of an earlier month, newest day first
 */
(function () {
  'use strict';
  const inr = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  const CM = { rules: null, code: { on: false, rules: [], shares: {} }, target: {}, status: {}, idx: {}, ord: [], manual: {}, slab: null, flash: [] };
  const STORE_TO_GROUP = { tmperfumehouse: 'tmph' };
  const isPre = (fs) => { fs = String(fs || '').toLowerCase(); return fs.indexOf('cod') === -1 && fs.indexOf('pending') === -1; };
  const DEF_START = ['10:00', '10:00', '10:00', '10:00', '10:00', '10:00', '10:00'];
  function calcCommission(achieved, target, ticket, prepaidPct) {
    const R = CM.rules;
    if (!R || !R.length || achieved <= 0) return null;
    const overPct = target > 0 ? ((achieved / target) - 1) * 100 : 0;
    const hit = target > 0 ? achieved >= target : false;
    const over = R.filter((r) => r.rule_type === 'over_target' && overPct >= Number(r.over_pct) && ticket >= Number(r.ticket_min));
    over.sort((a, b) => Number(b.over_pct) - Number(a.over_pct) || Number(b.ticket_min) - Number(a.ticket_min));
    let rate;
    if (over.length) rate = Number(over[0].rate);
    else {
      const base = R.filter((r) => r.rule_type === 'base' && Number(r.achieved_target) === (hit ? 1 : 0) && ticket >= Number(r.ticket_min));
      base.sort((a, b) => Number(b.ticket_min) - Number(a.ticket_min));
      rate = base.length ? Number(base[0].rate) : 0;
      const pm = R.filter((r) => r.rule_type === 'prepaid' && prepaidPct >= Number(r.prepaid_min));
      pm.sort((a, b) => Number(b.prepaid_min) - Number(a.prepaid_min));
      if (pm.length) rate += Number(pm[0].rate);
    }
    rate = Math.round(rate * 100) / 100;
    return { rate, hit, ticket };
  }

  // Order-code rules: a share set by hand wins, else the first switched-on rule that matches, else the full 100%.
  function shareFor(x, ctx) {
    const m = CM.code.shares[x.key];
    if (m) return { mode: m.mode || 'pct', v: Number(m.share_pct) };   // pct = share of the normal commission, rate = % of the order, flat = ₹
    if (!CM.code.on) return null;
    const has = !!x.code;
    for (const r of CM.code.rules) {
      if (!Number(r.enabled)) continue;
      if (r.houses && String(r.houses).split(',').indexOf(x.house) === -1) continue;
      const wt = r.when_target || 'any', wk = r.when_ticket || 'any';
      if (wt !== 'any' || wk !== 'any') {
        if (!ctx) continue;
        if (wt === 'hit' && !ctx.hit) continue;
        if (wt === 'miss' && ctx.hit) continue;
        if (wk === 'lt' && !(ctx.ticket < Number(r.when_ticket_val))) continue;
        if (wk === 'gte' && !(ctx.ticket >= Number(r.when_ticket_val))) continue;
      }
      if (r.match_type === 'no_code' && has) continue;
      if (r.match_type === 'has_code' && !has) continue;
      if (r.match_type === 'prefix' && !(has && String(x.code).toUpperCase().indexOf(String(r.prefix).toUpperCase()) === 0)) continue;
      return { mode: r.pay_mode, v: Number(r.pay_value) };
    }
    return null;
  }
  function calcPrice(x, ctx) {
    const sh = shareFor(x, ctx);
    if (!sh) return { eff: x.amt, flat: 0 };
    if (sh.mode === 'flat') return { eff: 0, flat: sh.v };
    if (sh.mode === 'rate') return { eff: 0, flat: x.amt * sh.v / 100 };
    return { eff: x.amt * sh.v / 100, flat: 0 };
  }
  const hasCond = () => CM.code.on && CM.code.rules.some((r) => Number(r.enabled) && ((r.when_target || 'any') !== 'any' || (r.when_ticket || 'any') !== 'any'));


  function bizDayOf(dt, dayStart) {
    const d = new Date(dt);
    const hm = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    if (hm < dayStart[d.getDay()]) d.setDate(d.getDate() - 1);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }


  function buildOrders(orders, from, to, dayStart) {
    CM.idx = {}; CM.ord = [];
    (orders || []).forEach((o) => {
      if (o.cancelled) return;                                                    // cancelled orders earn nothing
      if (!STORE_TO_GROUP[o.store_key] && !(o.allocated && o.house)) return;       // only the house stores (+ MySales orders moved to a panel)
      const day = o.biz_date || bizDayOf(o.created_at, dayStart);
      if (day < from || day > to) return;
      const rk = '';
      const amt = Number(o.total || 0), pre = isPre(o.financial_status);
      const x = { key: (o.store_key || o.source || '') + ':' + o.id, house: o.house || STORE_TO_GROUP[o.store_key] || '', code: o.code || '', amt, pre, ml: o.ml == null ? null : Number(o.ml), x_amt: Number(o.x_amt) || 0, x_com: Number(o.x_com) || 0, x_note: o.x_note || '', x_all: !!o.x_all, x_pct: o.x_pct == null ? null : Number(o.x_pct), day, rk, created: o.created_at };
      const p = calcPrice(x); x.eff = p.eff; x.flat = p.flat;
      CM.ord.push(x);
      const cell = ((CM.idx[rk] = CM.idx[rk] || {})[day] = CM.idx[rk][day] || { ach: 0, n: 0, pre: 0, eff: 0, flat: 0, orders: [] });
      cell.ach += amt; cell.n++; if (pre) cell.pre += amt; cell.eff += x.eff; cell.flat += x.flat; cell.orders.push(x);
    });
  }

  // 70% code rule: enough of my orders of a day have a code → every order of that day pays full (x.codeOk, used by MLSLAB.orderCom / flashCom).
  function tagMyCodes() { if (window.MLSLAB && CM.slab) CM.codeDays = MLSLAB.tagCodes(CM.slab, CM.ord, CM.slab.me); }
  // ML price slab: his daily target of that day (that row) is complete? No target set = not capped.
  function slabHit(o) {
    const t = Number(((CM.target || {})[o.rk] || {})[o.day] || 0);
    if (!(t > 0)) return null;      // no target that day: no bonus, no cut
    const cell = (CM.idx[o.rk] || {})[o.day];
    return !!cell && cell.ach >= t;
  }
  // How far above the day's target the sales are, in % (0 = just reached) — the over-target bonus steps (+30% → 30% extra …) use it.
  function slabOver(o) {
    const t = Number(((CM.target || {})[o.rk] || {})[o.day] || 0), cell = (CM.idx[o.rk] || {})[o.day];
    return t > 0 && cell ? (cell.ach / t - 1) * 100 : null;
  }
  // One row ('' = all panels, 'tm4u' = the TM4U row) over a set of days.
  function aggRow(rk, days, useManual) {
    let ach = 0, n = 0, pre = 0, eff = 0, flat = 0, target = 0;
    const orders = [];
    days.forEach((d) => {
      const x = (CM.idx[rk] || {})[d];
      if (x) { ach += x.ach; n += x.n; pre += x.pre; eff += x.eff; flat += x.flat; orders.push(...x.orders); }
      target += Number(((CM.target || {})[rk] || {})[d] || 0);
    });
    const ticket = n ? ach / n : 0, prePct = ach > 0 ? Math.min(100, pre / ach * 100) : 0;
    const c = calcCommission(ach, target, ticket, prePct);
    if (c && hasCond()) {                  // rules that depend on this employee's target / ticket size price each order in that context
      eff = 0; flat = 0;
      orders.forEach((o) => { const p = calcPrice(o, { hit: c.hit, ticket: c.ticket }); eff += p.eff; flat += p.flat; });
    }
    const man = useManual ? (CM.manual || {})[rk] : null;      // commission set by hand for him this month (Commission page) replaces the worked-out one
    if (man) return { ach, n, target, rate: man.rate, amount: man.amount, rated: true, manual: true };
    let amount = c ? c.rate * eff / 100 + flat : 0, slabN = 0;
    // ML price slab (same as the Commission page): an order with a known total ML pays its slab % instead of the normal rate.
    if (window.MLSLAB && (MLSLAB.on(CM.slab) || CM.flash.length)) {
      const ctx = c && hasCond() ? { hit: c.hit, ticket: c.ticket } : undefined;
      orders.forEach((o) => {
        // ⚡ Flash Offer: this size sold inside the offer's hours → the offer's flat % (price not compared); wins over the slab
        const f = CM.flash.length ? MLSLAB.flashFor(CM.flash, o.ml, o.created) : null;
        const s = f ? MLSLAB.flashCom(f, o) : (MLSLAB.on(CM.slab) ? MLSLAB.orderCom(CM.slab, Object.assign({ emp: CM.slab.me }, o), slabHit(o), slabOver(o)) : null);   // 📅 special days for me
        if (!s) return;
        const p = ctx ? calcPrice(o, ctx) : o;
        amount += s.v - (c ? c.rate * p.eff / 100 + p.flat : 0); slabN++;
      });
    }
    if (slabN && slabN === n) return { ach, n, target, rate: ach > 0 ? Math.round(amount / ach * 10000) / 100 : 0, amount, rated: true, slab: true };
    return { ach, n, target, rate: c ? c.rate : 0, amount, rated: !!c || slabN > 0 };
  }
  function period(days, useManual) {
    const rows = [''].map((rk) => aggRow(rk, days, useManual));
    const sum = (k) => rows.reduce((a, r) => a + r[k], 0);
    const live = rows.filter((r) => (r.ach > 0 || r.manual) && r.rated);
    return { ach: sum('ach'), n: sum('n'), target: sum('target'), amount: sum('amount'), rate: live.length === 1 ? live[0].rate : null, rated: live.length > 0, manual: rows.some((r) => r.manual) };
  }

  const dayTarget = (cm, d) => Number(((cm.target || {})[''] || {})[d] || 0) + Number(((cm.target || {}).tm4u || {})[d] || 0);
  function archiveRows(cm, orders, from, to, dayStart) {
    const by = {};
    (orders || []).forEach((o) => {
      if (o.cancelled) return;
      if (!STORE_TO_GROUP[o.store_key] && !(o.allocated && o.house)) return;
      const day = o.biz_date || bizDayOf(o.created_at, dayStart);
      if (day < from || day > to) return;
      const x = by[day] = by[day] || { n: 0, ach: 0 };
      x.n++; x.ach += Number(o.total || 0);
    });
    const rows = [];
    for (let x = from; x <= to; ) {
      const b = by[x] || { n: 0, ach: 0 };
      rows.push({ day: x, n: b.n, ach: b.ach, tg: dayTarget(cm, x), st: (cm.status || {})[x] || '' });
      const t = new Date(x + 'T00:00:00'); t.setDate(t.getDate() + 1);
      x = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
    }
    return rows.reverse();
  }
  const ST_TXT = { absent: 'Absent', leave: 'Leave', half_leave: 'Half leave', wfh: 'WFH', holiday: 'Holiday', week_off: 'Week off' };
  const rateTxt = (r, man) => r === null ? (man ? 'set by Admin' : '') : (man ? 'set by Admin · ' : '') + (Math.round(r * 100) / 100) + '% rate';

  const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const dayList = (from, to) => { const days = []; for (let x = from; x <= to; ) { days.push(x); const t = new Date(x + 'T00:00:00'); t.setDate(t.getDate() + 1); x = ymd(t); } return days; };
  let LAST = null;   // { cm, orders, dayStart, from, to } of the current month

  async function month(bizDay) {
    const to = bizDay, from = to.slice(0, 8) + '01';
    const [cm, od, tm, fo] = await Promise.all([api.get('my.commission'), api.get('shopify.orders', { date_from: from, date_to: to, lite: 1 }), api.get('day_timing.get').catch(() => null), api.get('flash_offer.get').catch(() => null)]);
    CM.flash = (fo && (fo.all || fo.history)) || [];
    if (cm.applicable === false) return { applicable: false, why: cm.why || '' };
    const dayStart = tm && Array.isArray(tm.start) && tm.start.length === 7 ? tm.start : DEF_START;
    CM.rules = cm.rules || [];
    CM.code = { on: !!(cm.code && cm.code.on), rules: (cm.code && cm.code.rules) || [], shares: (cm.shares && !Array.isArray(cm.shares)) ? cm.shares : {} };
    CM.target = cm.target || {}; CM.status = cm.status || {}; CM.manual = (cm.manual && !Array.isArray(cm.manual)) ? cm.manual : {}; CM.slab = cm.ml_slab || null;
    buildOrders(od.orders || [], from, to, dayStart); tagMyCodes();
    const days = dayList(from, to), m = period(days, true), t = period([to]);
    LAST = { cm, orders: od.orders || [], dayStart, from, to };
    const errs = (od.errors || []).filter(Boolean);
    return { applicable: true, month: m, today: t, monthRate: m.rated ? rateTxt(m.rate, m.manual) : '', todayRate: t.rated ? rateTxt(t.rate) : '', rows: archiveRows(cm, od.orders || [], from, to, dayStart), noRules: !CM.rules.length, note: errs.length ? 'Some orders could not be loaded, so month figures may be incomplete: ' + errs.join(' · ') : '' };
  }
  async function archive(mo) {
    const from = mo + '-01', last = new Date(Number(mo.slice(0, 4)), Number(mo.slice(5, 7)), 0), to = mo + '-' + String(last.getDate()).padStart(2, '0');
    const [cm, od] = await Promise.all([api.get('my.commission', { month: mo }), api.get('shopify.orders', { date_from: from, date_to: to })]);
    return archiveRows(cm, od.orders || [], from, to, LAST ? LAST.dayStart : DEF_START);
  }
  window.MyCommission = { month, archive, ST_TXT, inr };
})();
