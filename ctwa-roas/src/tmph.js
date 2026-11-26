/**
 * TM Perfume House store API (the new PHP site) → this service.
 *   GET {TMPH_API_BASE}/orders?since=YYYY-MM-DD HH:MM:SS&page=N&limit=100   Authorization: Bearer TMPH_API_KEY
 *   key scope needed: read_orders only. Answer: {ok:true, data:{items:[...], has_more:bool}}.
 * Each order is turned into the same shape the old Shopify webhook gave, so matching and the WhatsApp Purchase
 * (processOrder + sendPurchase in shopify.js / meta.js) work exactly as before. Orders get id "tmph-<number>" so they never clash
 * with old Shopify ids. The "since" cursor is kept in the kv table, so a restart never misses an order.
 */
const TZ = '+05:30';   // the site writes order times in India time
/** epoch ms → "YYYY-MM-DD HH:MM:SS" in India time (the store API's format). */
const istStr = (ms) => new Date(ms + 5.5 * 3600000).toISOString().slice(0, 19).replace('T', ' ');

/** Store order → the Shopify-shaped order the matcher expects. */
export function toOrder(o) {
  const placed = String(o.placed_at || '').replace(' ', 'T') + TZ;
  const updated = String(o.updated_at || o.placed_at || '').replace(' ', 'T') + TZ;
  const paid = Number(o.amount_paid) || 0;
  let fin = 'pending';
  if (o.payment_status === 'paid') fin = 'paid';
  else if (o.payment_status === 'partially_refunded' || o.payment_status === 'refunded') fin = o.payment_status;
  else if (paid > 0) fin = 'partially_paid';     // COD with an advance
  return {
    id: 'tmph-' + o.number,
    name: String(o.number),
    created_at: placed,
    updated_at: updated,
    total_price: String(Number(o.total) || 0),
    currency: o.currency || 'INR',
    financial_status: fin,
    cancelled_at: o.fulfillment_status === 'cancelled' ? updated : null,
    tags: '',
    phone: o.customer?.phone || '',
    customer: { phone: o.customer?.phone || '' },
    shipping_address: { phone: o.shipping_address?.phone || '' },
    billing_address: null,
    source: 'tmph',
  };
}

/** All orders placed or changed since `since` (a "YYYY-MM-DD HH:MM:SS" string, or null for the first run). */
export async function fetchOrders(cfg, since, fetchImpl = fetch, maxPages = 50) {
  if (!cfg.tmphKey || !cfg.tmphBase) throw new Error('TMPH_API_KEY / TMPH_API_BASE not set');
  const out = [];
  for (let page = 1; page <= maxPages; page++) {
    const qs = new URLSearchParams({ page: String(page), limit: '100' });
    if (since) qs.set('since', since);
    const res = await fetchImpl(`${cfg.tmphBase.replace(/\/+$/, '')}/orders?${qs}`, {
      headers: { authorization: `Bearer ${cfg.tmphKey}`, accept: 'application/json' },
    });
    const j = await res.json().catch(() => null);
    if (!res.ok || !j || j.ok !== true) throw new Error(`store API ${res.status}: ${j?.error || 'no answer'}`);
    out.push(...(j.data.items || []));
    if (!j.data.has_more) break;
  }
  return out;
}

/** Pull the new orders, match them and send the WhatsApp Purchase for the matched paid ones. Returns counts. */
export async function syncTmph(db, cfg, fetchImpl = fetch, now = Math.floor(Date.now() / 1000)) {
  const { processOrder } = await import('./shopify.js');
  const { sendPurchase } = await import('./meta.js');
  const get = (k) => db.prepare('SELECT v FROM kv WHERE k = ?').get(k)?.v ?? null;
  const set = (k, v) => db.prepare('INSERT INTO kv(k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v').run(k, v);
  // first run: the last 2 days, so nothing from before is pulled in twice; after that, from the last cursor minus 5 minutes
  const cursor = get('tmph_cursor');
  const since = cursor || istStr(now * 1000 - 2 * 86400000);
  const orders = await fetchOrders(cfg, since, fetchImpl);
  let matched = 0, sent = 0, failed = 0;
  let newest = since;
  for (const o of orders) {
    const r = processOrder(db, toOrder(o), cfg, now);
    if (r.matched) matched++;
    if (r.sendCapi) {
      const s = await sendPurchase(db, cfg, r.id, fetchImpl, now);
      if (s.sent) sent++; else if (s.failed) failed++;
    }
    if (o.updated_at && o.updated_at > newest) newest = o.updated_at;
  }
  // keep a small overlap so an order changed during this run is read again next time (processing is idempotent)
  const back = istStr(Date.parse(newest.replace(' ', 'T') + TZ) - 5 * 60000);
  set('tmph_cursor', back);
  return { read: orders.length, matched, sent, failed, cursor: back };
}
