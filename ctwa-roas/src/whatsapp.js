import crypto from 'node:crypto';
import { last10 } from './phone.js';

/** GET verification handshake: hub.mode / hub.verify_token / hub.challenge. Returns challenge or null. */
export function verifyChallenge(query, cfg) {
  if (!cfg.waVerifyToken) return null;
  if (query.get('hub.mode') !== 'subscribe') return null;
  const given = Buffer.from(query.get('hub.verify_token') || '');
  const want = Buffer.from(cfg.waVerifyToken);
  if (given.length !== want.length || !crypto.timingSafeEqual(given, want)) return null;
  return query.get('hub.challenge') || '';
}

/** X-Hub-Signature-256: "sha256=<hex hmac of raw body with app secret>" */
export function verifySignature(raw, header, secret) {
  if (!secret || !header || !header.startsWith('sha256=')) return false;
  const want = crypto.createHmac('sha256', secret).update(raw).digest('hex');
  const got = header.slice(7);
  return got.length === want.length && crypto.timingSafeEqual(Buffer.from(got), Buffer.from(want));
}

/**
 * Pull CTWA clicks out of a Cloud API webhook payload.
 * Only the first message of a click-to-WhatsApp chat carries messages[].referral.
 */
export function extractClicks(payload) {
  const out = [];
  for (const entry of payload?.entry ?? []) {
    for (const change of entry.changes ?? []) {
      const v = change.value ?? {};
      for (const m of v.messages ?? []) {
        const r = m.referral;
        if (!r?.ctwa_clid) continue;
        out.push({
          wa_message_id: m.id,
          ctwa_clid: r.ctwa_clid,
          source_id: r.source_id ?? null,
          source_type: r.source_type ?? null,
          source_url: r.source_url ?? null,
          headline: r.headline ?? null,
          body: r.body ?? null,
          media_type: r.media_type ?? null,
          wa_from: m.from ?? null,
          phone10: last10(m.from),
          phone_number_id: v.metadata?.phone_number_id ?? null,
          clicked_at: Number(m.timestamp) || Math.floor(Date.now() / 1000),
        });
      }
    }
  }
  return out;
}

export function saveClicks(db, clicks, now = Math.floor(Date.now() / 1000)) {
  const st = db.prepare(`INSERT OR IGNORE INTO clicks
    (wa_message_id,ctwa_clid,source_id,source_type,source_url,headline,body,media_type,wa_from,phone10,phone_number_id,clicked_at,created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`);
  let saved = 0;
  for (const c of clicks) {
    const r = st.run(c.wa_message_id, c.ctwa_clid, c.source_id, c.source_type, c.source_url, c.headline,
      c.body, c.media_type, c.wa_from, c.phone10, c.phone_number_id, c.clicked_at, now);
    saved += Number(r.changes);
  }
  return saved;
}
