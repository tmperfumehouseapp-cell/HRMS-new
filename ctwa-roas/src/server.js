import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { loadEnv, getConfig } from './config.js';
import { openDb } from './db.js';
import { verifyChallenge, verifySignature, extractClicks, saveClicks } from './whatsapp.js';
import { verifyHmac, processOrder, tagOrder } from './shopify.js';
import { sendPurchase, retryPending, syncSpend } from './meta.js';
import crypto from 'node:crypto';
import { dailyReport, toCsv, todayIn, shiftDay, diagDay, diagPhone } from './report.js';
import { last10 } from './phone.js';

const MAX_BODY = 2 * 1024 * 1024;

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []; let n = 0;
    req.on('data', (c) => { n += c.length; if (n > MAX_BODY) { reject(new Error('too large')); req.destroy(); } else chunks.push(c); });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

export function createHandler({ db, cfg, fetchImpl = fetch, log = console }) {
  const inflight = new Set();
  const track = (p) => { inflight.add(p); p.catch((e) => log.error('[bg]', e.message)).finally(() => inflight.delete(p)); };
  const send = (res, code, body, type = 'text/plain') => { res.writeHead(code, { 'content-type': type }); res.end(body); };
  const seen = (id) => !!db.prepare('SELECT 1 FROM webhook_events WHERE id=?').get(id);
  const mark = (id, src) => db.prepare('INSERT OR IGNORE INTO webhook_events(id,source,received_at) VALUES (?,?,?)').run(id, src, Math.floor(Date.now() / 1000));

  const handler = async (req, res) => {
    const url = new URL(req.url, 'http://x');
    try {
      if (url.pathname === '/healthz') return send(res, 200, 'ok');

      // ---- WhatsApp Cloud API webhook ----
      if (url.pathname === '/webhooks/whatsapp') {
        if (req.method === 'GET') {
          const ch = verifyChallenge(url.searchParams, cfg);
          return ch === null ? send(res, 403, 'forbidden') : send(res, 200, ch);
        }
        if (req.method === 'POST') {
          const raw = await readBody(req);
          if (!verifySignature(raw, req.headers['x-hub-signature-256'], cfg.waAppSecret)) return send(res, 401, 'bad signature');
          const saved = saveClicks(db, extractClicks(JSON.parse(raw.toString('utf8'))));
          return send(res, 200, `ok ${saved}`);
        }
      }

      // ---- Shopify orders/create + orders/updated ----
      if (url.pathname === '/webhooks/shopify' && req.method === 'POST') {
        const raw = await readBody(req);
        if (!verifyHmac(raw, req.headers['x-shopify-hmac-sha256'], cfg.shopifySecret)) return send(res, 401, 'bad hmac');
        if (cfg.shopifyDomain && req.headers['x-shopify-shop-domain'] && req.headers['x-shopify-shop-domain'] !== cfg.shopifyDomain)
          return send(res, 403, 'wrong shop');
        const topic = req.headers['x-shopify-topic'];
        if (topic !== 'orders/create' && topic !== 'orders/updated') return send(res, 200, 'ignored');
        const evId = req.headers['x-shopify-event-id'] || req.headers['x-shopify-webhook-id'];
        if (evId && seen(evId)) return send(res, 200, 'duplicate');

        const order = JSON.parse(raw.toString('utf8'));
        const r = processOrder(db, order, cfg);
        if (evId) mark(evId, 'shopify');
        if (r.matched) {
          if (r.needsTag) track(tagOrder(cfg, r.id, r.adId, fetchImpl).then((t) => {
            if (!t.skipped) db.prepare('UPDATE orders SET tagged=1 WHERE shopify_order_id=?').run(r.id);
          }));
          if (r.sendCapi) track(sendPurchase(db, cfg, r.id, fetchImpl));
        }
        return send(res, 200, r.matched ? 'matched' : 'no match');
      }

      // ---- Daily report (bearer-protected) ----
      if (url.pathname === '/report' && req.method === 'GET') {
        if (!cfg.reportToken || req.headers.authorization !== `Bearer ${cfg.reportToken}`) return send(res, 401, 'unauthorized');
        const to = url.searchParams.get('date') || shiftDay(todayIn(cfg.tzOffsetMin), -1);
        const days = Math.max(1, Math.min(90, Number(url.searchParams.get('days') || 1)));
        const rep = dailyReport(db, { from: shiftDay(to, -(days - 1)), to }, cfg.tzOffsetMin);
        return url.searchParams.get('format') === 'csv'
          ? send(res, 200, toCsv(rep), 'text/csv') : send(res, 200, JSON.stringify(rep), 'application/json');
      }
      // ---- Diagnose (called by the HRMS; signed with the WhatsApp app secret both sides already hold, valid 5 minutes) ----
      if ((url.pathname === '/diag' || url.pathname === '/diag/phone') && req.method === 'GET') {
        const ts = Number(url.searchParams.get('ts')); const arg = url.searchParams.get(url.pathname === '/diag' ? 'date' : 'phone') || '';
        const want = cfg.waAppSecret ? crypto.createHmac('sha256', cfg.waAppSecret).update(`${ts}|${url.pathname}|${arg}`).digest('hex') : '';
        const got = String(req.headers['x-ctwa-sign'] || '');
        if (!want || got.length !== want.length || !crypto.timingSafeEqual(Buffer.from(got), Buffer.from(want)) || Math.abs(Date.now() / 1000 - ts) > 300)
          return send(res, 401, 'unauthorized');
        if (url.pathname === '/diag') {
          const day = /^\d{4}-\d{2}-\d{2}$/.test(arg) ? arg : todayIn(cfg.tzOffsetMin);
          return send(res, 200, JSON.stringify(diagDay(db, day, cfg.tzOffsetMin)), 'application/json');
        }
        const p10 = last10(arg); if (!p10) return send(res, 400, 'bad phone');
        return send(res, 200, JSON.stringify(diagPhone(db, p10)), 'application/json');
      }
      send(res, 404, 'not found');
    } catch (e) {
      log.error('[handler]', e.message);
      send(res, 500, 'error');
    }
  };
  handler.idle = () => Promise.allSettled([...inflight]);
  return handler;
}

export function main() {
  loadEnv();
  const cfg = getConfig();
  const db = openDb(cfg.dbPath);
  http.createServer(createHandler({ db, cfg })).listen(cfg.port, () => console.log(`ctwa-roas listening on :${cfg.port}`));
  if (cfg.syncIntervalMin > 0) {
    const tick = async () => {
      try { await retryPending(db, cfg); } catch (e) { console.error('[retry]', e.message); }
      try { if (cfg.adsToken && cfg.adAccountIds.length) await syncSpend(db, cfg, 3); } catch (e) { console.error('[sync]', e.message); }
    };
    setInterval(tick, cfg.syncIntervalMin * 60000).unref();
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main();
