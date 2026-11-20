global.window = {}; require(__dirname + '/../assets/js/ml_slab.js'); const M = window.MLSLAB; let bad = 0;
const eq = (n, a, b) => { if (JSON.stringify(a) !== JSON.stringify(b)) { bad++; console.log('FAIL', n, JSON.stringify(a), JSON.stringify(b)); } };
const offers = [{ ml: 400, price: 3299, pct: 6, from: '2026-11-01', to: '', active: 1, stopped: '', addons: [{ ml: 80, amount: 750 }] }];
const chart = { nocode: 50, prepaid: 0 };
const r = M.offerCom(offers, 110, chart, { ml: 480, extra: 0, day: '2026-11-20', amt: 3968, pre: false, code: 'X' });
eq('add-on order matches', r && r.pct, 6); eq('commission is 6% of the whole amount', r && Math.round(r.v), Math.round(3968 * 0.06));
eq('offer alone', M.offerCom(offers, 110, chart, { ml: 400, extra: 0, day: '2026-11-20', amt: 3299, pre: false, code: 'X' }).pct, 6);
eq('far price: no match', M.offerCom(offers, 110, chart, { ml: 480, extra: 0, day: '2026-11-20', amt: 3400, pre: false, code: 'X' }), null);
eq('no code: half', M.offerCom(offers, 110, chart, { ml: 480, extra: 0, day: '2026-11-20', amt: 3968, pre: false, code: '' }).pct, 3);
// global add-ons: chart.addons
const ch2 = { on: 1, cols: [6, 4], rows: [{ ml: 400, p: [3299, 2799] }, { ml: 500, p: [6999, 5999] }], prepaid: 0, nocode: 100, floor: 0, addons: [{ ml: 80, amount: 750 }] };
const o1 = { ml: 480, amt: 3968, pre: false, code: 'X' };
const plainOffers = [{ ml: 400, price: 3299, pct: 6, from: '2026-11-01', to: '', active: 1, stopped: '', addons: [] }];
eq('global add-on on an offer', M.offerCom(plainOffers, 110, ch2, Object.assign({ day: '2026-11-20', extra: 0 }, o1)).pct, 6);
const rc = M.orderCom(ch2, o1, true, 0); eq('chart add-on: priced as 400 ml', rc && rc.addon, true); eq('chart add-on: % on the whole amount', rc && Math.round(rc.v), Math.round(3968 * rc.pct / 100));
const ch3 = Object.assign({}, ch2, { addons: [] }); const rn = M.orderCom(ch3, o1, true, 0); eq('no add-ons: not an add-on order', !!(rn && rn.addon), false);
console.log(bad ? 'offer addon js: ' + bad + ' problem(s)' : 'offer addon js: ok'); process.exit(bad ? 1 : 0);
