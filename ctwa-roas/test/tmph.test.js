import test from 'node:test';
import assert from 'node:assert/strict';
import { openDb } from '../src/db.js';
import { getConfig } from '../src/config.js';
import { toOrder, syncTmph } from '../src/tmph.js';

const cfg = getConfig({ WA_APP_SECRET: 'x', META_ACCESS_TOKEN: 'TOK', META_DATASET_ID: '555', META_WABA_ID: '999', TMPH_API_KEY: 'tmph_k', TMPH_API_BASE: 'https://store.test/api/v1' });
const store = (o) => ({ number: '1001', placed_at: '2026-11-24 10:00:00', updated_at: '2026-11-24 10:00:00', total: '1499', currency: 'INR', payment_status: 'paid', amount_paid: 1499,
  fulfillment_status: 'unfulfilled', customer: { phone: '+91 98765 43210' }, shipping_address: { phone: '' }, ...o });

test('store order becomes the matcher shape', () => {
  const t = toOrder(store({}));
  assert.equal(t.id, 'tmph-1001'); assert.equal(t.financial_status, 'paid'); assert.equal(t.created_at, '2026-11-24T10:00:00+05:30');
  assert.equal(toOrder(store({ payment_status: 'pending', amount_paid: 500 })).financial_status, 'partially_paid');
  assert.equal(toOrder(store({ payment_status: 'pending', amount_paid: 0 })).financial_status, 'pending');
});

test('sync: reads pages with the key, matches a WhatsApp click, sends Purchase once, keeps a cursor', async () => {
  const db = openDb(':memory:');
  const t0 = Math.floor(Date.parse('2026-11-24T10:05:00+05:30') / 1000);
  db.prepare("INSERT INTO clicks(wa_message_id,ctwa_clid,source_id,phone10,clicked_at,created_at) VALUES ('m1','CL9','AD9','9876543210',?,?)").run(t0 - 3600, t0);
  const calls = []; const sent = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url: String(url), auth: init.headers.authorization });
    if (String(url).includes('graph.facebook.com')) { sent.push(JSON.parse(init.body)); return new Response('{"events_received":1}', { status: 200 }); }
    const page = new URL(url).searchParams.get('page');
    const items = page === '1' ? [store({ number: '1001' }), store({ number: '1002', payment_status: 'pending', amount_paid: 0 })] : [store({ number: '1003' })];
    return new Response(JSON.stringify({ ok: true, data: { items, has_more: page === '1' } }), { status: 200 });
  };
  const r = await syncTmph(db, cfg, fetchImpl, t0);
  assert.equal(r.read, 3); assert.equal(calls[0].auth, 'Bearer tmph_k'); assert.match(calls[0].url, /^https:\/\/store\.test\/api\/v1\/orders\?/);
  assert.equal(calls.filter((c) => c.url.includes('store.test')).length, 2, 'two order pages');
  // 1001 and 1003 are paid and from the same clicked number → two Purchases; 1002 is still pending → none
  assert.deepEqual(sent.map((x) => x.data[0].event_id).sort(), ['tmph-1001', 'tmph-1003']);
  const ev = sent[0].data[0]; assert.equal(ev.event_name, 'Purchase'); assert.equal(ev.messaging_channel, 'whatsapp');
  assert.equal(ev.user_data.ctwa_clid, 'CL9'); assert.equal(r.matched, 3, 'all three match the click; only the two paid ones are sent');
  assert.equal(r.cursor, '2026-11-24 09:55:00', 'cursor = newest order minus 5 minutes');
  // a second run sends nothing new
  const again = await syncTmph(db, cfg, fetchImpl, t0 + 120);
  assert.equal(sent.length, 2, 'no duplicate purchase');
  assert.ok(again.cursor >= r.cursor);
});
