import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import http from 'node:http';
import { openDb } from '../src/db.js';
import { getConfig } from '../src/config.js';
import { createHandler } from '../src/server.js';
import { last10 } from '../src/phone.js';
import { verifyChallenge, extractClicks } from '../src/whatsapp.js';
import { buildPurchaseEvent, syncSpend } from '../src/meta.js';
import { dailyReport, toCsv } from '../src/report.js';

const NOW = Math.floor(Date.now() / 1000);
const iso = (s) => new Date(s * 1000).toISOString();
const cfg = getConfig({
  WA_VERIFY_TOKEN: 'vt', WA_APP_SECRET: 'wasecret', SHOPIFY_WEBHOOK_SECRET: 'shsecret', SHOPIFY_SHOP_DOMAIN: 'tm.myshopify.com',
  META_ACCESS_TOKEN: 'TOK', META_DATASET_ID: '555', META_WABA_ID: '999', META_AD_ACCOUNT_ID: 'act_42',
  REPORT_TOKEN: 'rt', REPORT_TZ_OFFSET_MIN: '0',
});

function setup(fetchImpl) {
  const db = openDb(':memory:');
  const handler = createHandler({ db, cfg, fetchImpl, log: { error() {} } });
  const server = http.createServer(handler);
  return new Promise((r) => server.listen(0, () => r({ db, handler, server, base: `http://127.0.0.1:${server.address().port}` })));
}
const waBody = (from, clid, adId, ts, id = 'wamid.1') => JSON.stringify({ entry: [{ changes: [{ value: {
  metadata: { phone_number_id: 'PN1' },
  messages: [{ id, from, timestamp: String(ts), type: 'text', text: { body: 'hi' },
    referral: { source_url: 'https://fb.me/x', source_type: 'ad', source_id: adId, headline: 'Oud Sale', body: 'b', media_type: 'image', ctwa_clid: clid } }],
} }] }] });
const waPost = (base, body) => fetch(`${base}/webhooks/whatsapp`, { method: 'POST', body,
  headers: { 'x-hub-signature-256': 'sha256=' + crypto.createHmac('sha256', 'wasecret').update(body).digest('hex') } });
const order = (o) => ({ id: 1001, name: '#1001', total_price: '1499.00', currency: 'INR', financial_status: 'pending',
  created_at: iso(NOW), updated_at: iso(NOW), tags: '', phone: '+91 98765-43210', ...o });
const shPost = (base, o, topic = 'orders/create', evId = 'ev1') => {
  const body = JSON.stringify(o);
  return fetch(`${base}/webhooks/shopify`, { method: 'POST', body, headers: {
    'x-shopify-hmac-sha256': crypto.createHmac('sha256', 'shsecret').update(body).digest('base64'),
    'x-shopify-topic': topic, 'x-shopify-event-id': evId, 'x-shopify-shop-domain': 'tm.myshopify.com' } });
};

test('phone last10', () => {
  assert.equal(last10('+91 98765-43210'), '9876543210');
  assert.equal(last10('919876543210'), '9876543210');
  assert.equal(last10('12345'), null);
});

test('whatsapp verify + signature + referral extraction', async () => {
  assert.equal(verifyChallenge(new URLSearchParams('hub.mode=subscribe&hub.verify_token=vt&hub.challenge=77'), cfg), '77');
  assert.equal(verifyChallenge(new URLSearchParams('hub.mode=subscribe&hub.verify_token=no&hub.challenge=77'), cfg), null);
  const c = extractClicks(JSON.parse(waBody('919876543210', 'CLID', 'AD9', NOW)));
  assert.equal(c[0].phone10, '9876543210'); assert.equal(c[0].source_id, 'AD9'); assert.equal(c[0].headline, 'Oud Sale');
  const { server, base } = await setup(fetch);
  try {
    const bad = await fetch(`${base}/webhooks/whatsapp`, { method: 'POST', body: '{}', headers: { 'x-hub-signature-256': 'sha256=00' } });
    assert.equal(bad.status, 401);
  } finally { server.close(); }
});

test('full flow: click → order match → tag → CAPI purchase → dedupe → report', async () => {
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url: String(url), init });
    if (String(url).includes('graph.facebook.com') && String(url).endsWith('/events')) return new Response(JSON.stringify({ events_received: 1 }), { status: 200 });
    return new Response('{}', { status: 200 });
  };
  const { db, handler, server, base } = await setup(fetchImpl);
  try {
    assert.equal((await waPost(base, waBody('919876543210', 'CLID-A', 'AD9', NOW - 2 * 86400))).status, 200);
    assert.equal((await waPost(base, waBody('919876543210', 'CLID-A', 'AD9', NOW - 2 * 86400))).status, 200); // replay
    assert.equal(db.prepare('SELECT COUNT(*) c FROM clicks').get().c, 1);

    // not confirmed yet → matched, nothing sent
    let r = await shPost(base, order({}));
    assert.equal(await r.text(), 'matched'); await handler.idle();
    assert.equal(calls.filter((c) => c.url.endsWith('/events')).length, 0);
    assert.equal(db.prepare('SELECT ad_id FROM orders').get().ad_id, 'AD9');

    // confirmed (paid) → one Purchase
    r = await shPost(base, order({ financial_status: 'paid' }), 'orders/updated', 'ev2'); await handler.idle();
    // same webhook delivered twice + another update → still one event
    await shPost(base, order({ financial_status: 'paid' }), 'orders/updated', 'ev2');
    await shPost(base, order({ financial_status: 'paid', tags: 'CTWA' }), 'orders/updated', 'ev3'); await handler.idle();
    const ev = calls.filter((c) => c.url.endsWith('/events'));
    assert.equal(ev.length, 1);
    assert.match(ev[0].url, /graph\.facebook\.com\/v21\.0\/555\/events$/);
    assert.ok(!ev[0].url.includes('TOK')); assert.equal(ev[0].init.headers.authorization, 'Bearer TOK');
    const b = JSON.parse(ev[0].init.body);
    assert.equal(b.partner_agent, cfg.partnerAgent);
    assert.deepEqual(b.data[0], { event_name: 'Purchase', event_time: b.data[0].event_time, event_id: '1001',
      action_source: 'business_messaging', messaging_channel: 'whatsapp',
      user_data: { whatsapp_business_account_id: '999', ctwa_clid: 'CLID-A' }, custom_data: { currency: 'INR', value: 1499 } });
    assert.equal(db.prepare('SELECT capi_status s FROM orders').get().s, 'sent');

    // report
    const day = new Date(NOW * 1000).toISOString().slice(0, 10);
    db.prepare('INSERT INTO ad_spend VALUES (?,?,?,?,?,?,?)').run(day, 'AD9', 'Oud Sale', 500, 1000, 40, 3);
    const rep = dailyReport(db, { from: day, to: day }, 0);
    const row = rep.rows.find((x) => x.ad_id === 'AD9');
    assert.equal(row.orders, 1); assert.equal(row.revenue, 1499); assert.equal(row.roas, 1499 / 500);
    assert.match(toCsv(rep), /TOTAL/);
    const api = await fetch(`${base}/report?date=${day}`, { headers: { authorization: 'Bearer rt' } });
    assert.equal(api.status, 200); assert.equal((await fetch(`${base}/report`)).status, 401);
  } finally { server.close(); }
});

test('matching window: 6 days matches, 8 days does not; latest click wins; cancelled never sent', async () => {
  const calls = [];
  const { db, handler, server, base } = await setup(async (u, i) => { calls.push(String(u)); return new Response('{}'); });
  try {
    await waPost(base, waBody('9876543210', 'OLD', 'ADOLD', NOW - 8 * 86400, 'm1'));
    let r = await shPost(base, order({ id: 1 }), 'orders/create', 'a');
    assert.equal(await r.text(), 'no match');
    await waPost(base, waBody('9876543210', 'C6', 'AD6', NOW - 6 * 86400, 'm2'));
    await waPost(base, waBody('9876543210', 'C1', 'AD1', NOW - 1 * 86400, 'm3'));
    r = await shPost(base, order({ id: 2, phone: null, customer: { phone: '+919876543210' } }), 'orders/create', 'b');
    assert.equal(await r.text(), 'matched');
    assert.equal(db.prepare('SELECT ad_id FROM orders WHERE shopify_order_id=?').get('2').ad_id, 'AD1');
    await shPost(base, order({ id: 3, financial_status: 'paid', cancelled_at: iso(NOW) }), 'orders/updated', 'c'); await handler.idle();
    assert.equal(calls.filter((u) => u.endsWith('/events')).length, 0);
  } finally { server.close(); }
});

test('CAPI failure is recorded; >7 days old event expires', async () => {
  const { db, handler, server, base } = await setup(async () => new Response(JSON.stringify({ error: { message: 'bad' } }), { status: 400 }));
  try {
    await waPost(base, waBody('9876543210', 'C', 'AD', NOW - 3600));
    await shPost(base, order({ financial_status: 'paid' })); await handler.idle();
    const o = db.prepare('SELECT capi_status s, capi_error e FROM orders').get();
    assert.equal(o.s, 'failed'); assert.equal(o.e, 'bad');
    const { sendPurchase } = await import('../src/meta.js');
    const res = await sendPurchase(db, cfg, 1001, async () => new Response('{}'), NOW + 8 * 86400);
    assert.ok(res.expired);
  } finally { server.close(); }
});

test('shopify bad HMAC rejected; syncSpend parses insights + pages', async () => {
  const { db, server, base } = await setup(fetch);
  try {
    const r = await fetch(`${base}/webhooks/shopify`, { method: 'POST', body: '{}', headers: { 'x-shopify-hmac-sha256': 'nope', 'x-shopify-topic': 'orders/create' } });
    assert.equal(r.status, 401);
  } finally { server.close(); }
  const db2 = openDb(':memory:');
  let page = 0;
  const n = await syncSpend(db2, cfg, 2, async (url) => {
    if (page === 0) { assert.match(String(url), /act_42\/insights/); assert.match(String(url), /level=ad/); }
    return new Response(JSON.stringify(page++ === 0
      ? { data: [{ date_start: '2026-11-21', ad_id: '9', ad_name: 'A', spend: '12.5', impressions: '100', clicks: '5',
          actions: [{ action_type: cfg.conversationAction, value: '4' }] }], paging: { next: 'https://graph.facebook.com/v21.0/next' } }
      : { data: [{ date_start: '2026-11-22', ad_id: '9', ad_name: 'A', spend: '1', impressions: '1', clicks: '1' }] }));
  }, new Date('2026-11-22T10:00:00Z'));
  assert.equal(n, 2);
  assert.equal(db2.prepare("SELECT conversations c FROM ad_spend WHERE day='2026-11-21'").get().c, 4);
  await assert.rejects(syncSpend(db2, cfg, 1, async () => new Response(JSON.stringify({ data: [], paging: { next: 'https://evil.example/x' } })), new Date()));
});
