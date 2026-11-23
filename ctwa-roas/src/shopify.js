import crypto from 'node:crypto';
import { last10 } from './phone.js';

/** X-Shopify-Hmac-SHA256 = base64(HMAC-SHA256(raw body, client secret)) */
export function verifyHmac(raw, header, secret) {
  if (!secret || !header) return false;
  const want = crypto.createHmac('sha256', secret).update(raw).digest('base64');
  const a = Buffer.from(String(header));
  const b = Buffer.from(want);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

const toSec = (s) => {
  const t = s ? Date.parse(s) : NaN;
  return Number.isNaN(t) ? null : Math.floor(t / 1000);
};

/** All distinct last-10-digit numbers found on the order (order, customer, shipping, billing). */
export function orderPhones(o) {
  const cands = [o.phone, o.customer?.phone, o.shipping_address?.phone, o.billing_address?.phone,
    o.customer?.default_address?.phone];
  return [...new Set(cands.map(last10).filter(Boolean))];
}

export const tagList = (tags) => String(tags || '').split(',').map((t) => t.trim()).filter(Boolean);

export function isConfirmed(o, cfg) {
  if (o.cancelled_at) return false;
  if (cfg.confirmTag) return tagList(o.tags).some((t) => t.toLowerCase() === cfg.confirmTag.toLowerCase());
  return cfg.confirmStatuses.includes(String(o.financial_status || '').toLowerCase());
}

/** Latest click from any of these numbers within the window before the order. */
export function findClick(db, phones, orderAt, windowDays) {
  if (!phones.length) return null;
  const q = phones.map(() => '?').join(',');
  return db.prepare(`SELECT * FROM clicks WHERE phone10 IN (${q}) AND clicked_at BETWEEN ? AND ?
    ORDER BY clicked_at DESC LIMIT 1`).get(...phones, orderAt - windowDays * 86400, orderAt + 600) ?? null;
}

export const CTWA_TAG = 'CTWA';
export const adTag = (adId) => `CTWA-ad-${adId}`;

/** Idempotent: safe for both orders/create and orders/updated, and for retries. */
export function processOrder(db, order, cfg, now = Math.floor(Date.now() / 1000)) {
  const id = String(order.id);
  const prev = db.prepare('SELECT * FROM orders WHERE shopify_order_id=?').get(id);
  const phones = orderPhones(order);
  const createdAt = toSec(order.created_at) ?? now;

  let click = prev?.click_id ? db.prepare('SELECT * FROM clicks WHERE id=?').get(prev.click_id) : null;
  if (!click) click = findClick(db, phones, createdAt, cfg.windowDays);

  const cancelled = order.cancelled_at ? 1 : 0;
  const confirmed = isConfirmed(order, cfg);
  const confirmedAt = prev?.confirmed_at ?? (confirmed ? (toSec(order.updated_at) ?? now) : null);
  const tags = tagList(order.tags);
  const tagged = click && tags.includes(CTWA_TAG) ? 1 : (prev?.tagged ?? 0);

  let capiStatus = prev?.capi_status ?? 'none';
  if (click && confirmedAt && !cancelled && (capiStatus === 'none')) capiStatus = 'pending';

  db.prepare(`INSERT INTO orders(shopify_order_id,name,phone10,total,currency,financial_status,cancelled,tags,
      order_created_at,confirmed_at,click_id,ctwa_clid,ad_id,tagged,capi_status,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON CONFLICT(shopify_order_id) DO UPDATE SET name=excluded.name, phone10=excluded.phone10, total=excluded.total,
      currency=excluded.currency, financial_status=excluded.financial_status, cancelled=excluded.cancelled,
      tags=excluded.tags, confirmed_at=excluded.confirmed_at, click_id=excluded.click_id,
      ctwa_clid=excluded.ctwa_clid, ad_id=excluded.ad_id, tagged=excluded.tagged,
      capi_status=excluded.capi_status, updated_at=excluded.updated_at`)
    .run(id, order.name ?? null, phones[0] ?? null, Number(order.total_price) || 0, order.currency ?? 'INR',
      order.financial_status ?? null, cancelled, order.tags ?? '', createdAt, confirmedAt,
      click?.id ?? null, click?.ctwa_clid ?? null, click?.source_id ?? null, tagged, capiStatus, now);

  return {
    id, matched: !!click, adId: click?.source_id ?? null,
    needsTag: !!click && !tags.includes(CTWA_TAG),
    sendCapi: capiStatus === 'pending' || capiStatus === 'failed',
  };
}

/** Adds CTWA + CTWA-ad-<id> tags via Admin GraphQL (optional: needs SHOPIFY_ADMIN_TOKEN). */
export async function tagOrder(cfg, orderId, adId, fetchImpl = fetch) {
  if (!cfg.shopifyAdminToken || !cfg.shopifyDomain) return { skipped: true };
  const tags = [CTWA_TAG, ...(adId ? [adTag(adId)] : [])];
  const res = await fetchImpl(`https://${cfg.shopifyDomain}/admin/api/${cfg.shopifyApiVersion}/graphql.json`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-shopify-access-token': cfg.shopifyAdminToken },
    body: JSON.stringify({
      query: 'mutation($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { userErrors { field message } } }',
      variables: { id: `gid://shopify/Order/${orderId}`, tags },
    }),
  });
  const j = await res.json().catch(() => ({}));
  const errs = j?.data?.tagsAdd?.userErrors?.length ? j.data.tagsAdd.userErrors : j?.errors;
  if (!res.ok || errs?.length) throw new Error(`tagsAdd failed: ${JSON.stringify(errs ?? res.status).slice(0, 300)}`);
  return { tags };
}
