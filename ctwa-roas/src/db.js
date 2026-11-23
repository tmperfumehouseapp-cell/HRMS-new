import { DatabaseSync } from 'node:sqlite';
import fs from 'node:fs';
import path from 'node:path';

const SCHEMA = `
CREATE TABLE IF NOT EXISTS clicks(
  id INTEGER PRIMARY KEY,
  wa_message_id TEXT UNIQUE,
  ctwa_clid TEXT NOT NULL,
  source_id TEXT, source_type TEXT, source_url TEXT,
  headline TEXT, body TEXT, media_type TEXT,
  wa_from TEXT, phone10 TEXT, phone_number_id TEXT,
  clicked_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS clicks_phone ON clicks(phone10, clicked_at);
CREATE INDEX IF NOT EXISTS clicks_day ON clicks(clicked_at);
CREATE TABLE IF NOT EXISTS orders(
  shopify_order_id TEXT PRIMARY KEY,
  name TEXT, phone10 TEXT, total REAL, currency TEXT,
  financial_status TEXT, cancelled INTEGER NOT NULL DEFAULT 0, tags TEXT,
  order_created_at INTEGER, confirmed_at INTEGER,
  click_id INTEGER, ctwa_clid TEXT, ad_id TEXT,
  tagged INTEGER NOT NULL DEFAULT 0,
  capi_status TEXT NOT NULL DEFAULT 'none',   -- none | pending | sent | failed | expired
  capi_sent_at INTEGER, capi_error TEXT,
  updated_at INTEGER
);
CREATE INDEX IF NOT EXISTS orders_ad ON orders(ad_id, order_created_at);
CREATE TABLE IF NOT EXISTS webhook_events(
  id TEXT PRIMARY KEY, source TEXT, received_at INTEGER
);
CREATE TABLE IF NOT EXISTS ad_spend(
  day TEXT NOT NULL, ad_id TEXT NOT NULL, ad_name TEXT,
  spend REAL, impressions INTEGER, link_clicks INTEGER, conversations INTEGER,
  PRIMARY KEY(day, ad_id)
);
`;

export function openDb(file) {
  if (file !== ':memory:') fs.mkdirSync(path.dirname(path.resolve(file)), { recursive: true });
  const db = new DatabaseSync(file);
  db.exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
  db.exec(SCHEMA);
  // added later: which campaign / ad account an ad belongs to, and small remembered values (when Meta was last read)
  for (const col of ['campaign_name TEXT', 'account_id TEXT']) { try { db.exec(`ALTER TABLE ad_spend ADD COLUMN ${col}`); } catch { /* already there */ } }
  db.exec('CREATE TABLE IF NOT EXISTS kv(k TEXT PRIMARY KEY, v TEXT)');
  return db;
}
