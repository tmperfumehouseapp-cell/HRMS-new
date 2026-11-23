const dayExpr = (col, off) => `date(${col} + ${Math.trunc(off) * 60}, 'unixepoch')`;

/** Per-day, per-ad report. Day boundaries use cfg.tzOffsetMin (IST = 330). */
export function dailyReport(db, { from, to }, tzOffsetMin = 330) {
  const rows = new Map();
  const row = (day, ad) => {
    const k = `${day}|${ad}`;
    if (!rows.has(k)) rows.set(k, { date: day, ad_id: ad, ad_name: null, spend: 0, impressions: 0,
      meta_conversations: 0, queries: 0, orders: 0, confirmed_orders: 0, revenue: 0 });
    return rows.get(k);
  };

  for (const r of db.prepare(`SELECT day, ad_id, ad_name, spend, impressions, conversations FROM ad_spend
    WHERE day BETWEEN ? AND ?
      AND ad_id IN (SELECT source_id FROM clicks WHERE source_id IS NOT NULL)`).all(from, to)) {   // only click-to-WhatsApp ads (the account's website ads would show spend with no orders)
    Object.assign(row(r.day, r.ad_id), { ad_name: r.ad_name, spend: r.spend, impressions: r.impressions,
      meta_conversations: r.conversations });
  }
  for (const r of db.prepare(`SELECT ${dayExpr('clicked_at', tzOffsetMin)} AS day, COALESCE(source_id,'unknown') AS ad,
      COUNT(DISTINCT ctwa_clid) AS q, MAX(headline) AS headline FROM clicks
    WHERE ${dayExpr('clicked_at', tzOffsetMin)} BETWEEN ? AND ? GROUP BY day, ad`).all(from, to)) {
    const x = row(r.day, r.ad); x.queries = r.q; x.ad_name ??= r.headline;
  }
  for (const r of db.prepare(`SELECT ${dayExpr('order_created_at', tzOffsetMin)} AS day, ad_id AS ad,
      COUNT(*) AS orders,
      SUM(CASE WHEN confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS conf,
      SUM(CASE WHEN confirmed_at IS NOT NULL THEN total ELSE 0 END) AS rev
    FROM orders WHERE ad_id IS NOT NULL AND cancelled=0
      AND ${dayExpr('order_created_at', tzOffsetMin)} BETWEEN ? AND ? GROUP BY day, ad`).all(from, to)) {
    const x = row(r.day, r.ad); x.orders = r.orders; x.confirmed_orders = r.conf; x.revenue = r.rev;
  }

  const div = (a, b) => (b > 0 ? a / b : null);
  const list = [...rows.values()].map((x) => ({
    ...x, roas: div(x.revenue, x.spend), cost_per_query: div(x.spend, x.queries),
    cost_per_order: div(x.spend, x.confirmed_orders),
  })).sort((a, b) => a.date.localeCompare(b.date) || b.spend - a.spend);

  const sum = (k) => list.reduce((s, x) => s + x[k], 0);
  const total = {
    spend: sum('spend'), impressions: sum('impressions'), meta_conversations: sum('meta_conversations'),
    queries: sum('queries'), orders: sum('orders'), confirmed_orders: sum('confirmed_orders'), revenue: sum('revenue'),
  };
  total.roas = div(total.revenue, total.spend);
  total.cost_per_query = div(total.spend, total.queries);
  total.cost_per_order = div(total.spend, total.confirmed_orders);
  return { from, to, rows: list, total };
}

const COLS = ['date', 'ad_id', 'ad_name', 'spend', 'queries', 'meta_conversations', 'orders', 'confirmed_orders',
  'revenue', 'roas', 'cost_per_query', 'cost_per_order'];

const fmt = (v) => (v == null ? '-' : typeof v === 'number' ? (Number.isInteger(v) ? String(v) : v.toFixed(2)) : String(v));

export function toCsv(rep) {
  const esc = (v) => { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
  const lines = [COLS.join(',')];
  for (const r of rep.rows) lines.push(COLS.map((c) => esc(typeof r[c] === 'number' && !Number.isInteger(r[c]) ? r[c].toFixed(2) : r[c])).join(','));
  lines.push(COLS.map((c) => esc(c === 'date' ? 'TOTAL' : typeof rep.total[c] === 'number' && !Number.isInteger(rep.total[c]) ? rep.total[c].toFixed(2) : rep.total[c])).join(','));
  return lines.join('\n') + '\n';
}

export function toText(rep) {
  const head = ['Date', 'Ad', 'Spend', 'Queries', 'Orders', 'Conf.', 'Revenue', 'ROAS'];
  const body = rep.rows.map((r) => [r.date, `${r.ad_name ?? ''} (${r.ad_id})`.slice(0, 40), fmt(r.spend), fmt(r.queries),
    fmt(r.orders), fmt(r.confirmed_orders), fmt(r.revenue), fmt(r.roas)]);
  body.push(['TOTAL', '', fmt(rep.total.spend), fmt(rep.total.queries), fmt(rep.total.orders),
    fmt(rep.total.confirmed_orders), fmt(rep.total.revenue), fmt(rep.total.roas)]);
  const w = head.map((h, i) => Math.max(h.length, ...body.map((r) => String(r[i]).length)));
  const line = (r) => r.map((c, i) => String(c).padEnd(w[i])).join('  ');
  return [`CTWA report ${rep.from} → ${rep.to}`, line(head), ...body.map(line)].join('\n') + '\n';
}

/** Date helpers (in the report timezone). */
export function todayIn(tzOffsetMin, now = new Date()) {
  return new Date(now.getTime() + tzOffsetMin * 60000).toISOString().slice(0, 10);
}
export function shiftDay(day, delta) {
  return new Date(Date.parse(day + 'T00:00:00Z') + delta * 86400000).toISOString().slice(0, 10);
}
