
const GRAPH = 'https://graph.facebook.com';
const SEVEN_DAYS = 7 * 86400;

/*
 * Field names, with verification status (Meta docs read via Meta Ads MCP help search, 2026-11-22):
 *  VERIFIED   POST {graph}/{version}/{dataset_id}/events ; body { data:[...], partner_agent }
 *  VERIFIED   event_name, event_time (unix s, <= 7 days old, else whole request rejected), event_id,
 *             action_source = "business_messaging", messaging_channel, custom_data{currency,value}
 *  VERIFIED   ctwa_clid is required for WhatsApp events; permission whatsapp_business_manage_events
 *  UNVERIFIED user_data keys for WhatsApp: whatsapp_business_account_id + ctwa_clid
 *             (docs page was truncated before the WhatsApp sample; Messenger sample uses page_id +
 *              page_scoped_user_id). Check with the "Test events" tool via META_TEST_EVENT_CODE.
 *  UNVERIFIED Insights: level=ad, time_increment=1, fields ad_id,ad_name,spend,impressions,clicks,actions
 */
export function buildPurchaseEvent(order, cfg) {
  return {
    event_name: 'Purchase',
    event_time: order.confirmed_at,
    event_id: String(order.shopify_order_id),
    action_source: 'business_messaging',
    messaging_channel: 'whatsapp',
    user_data: {
      whatsapp_business_account_id: cfg.wabaId,
      ctwa_clid: order.ctwa_clid,
    },
    custom_data: { currency: order.currency || 'INR', value: Number(order.total) },
  };
}

export async function sendPurchase(db, cfg, orderId, fetchImpl = fetch, now = Math.floor(Date.now() / 1000)) {
  const o = db.prepare('SELECT * FROM orders WHERE shopify_order_id=?').get(String(orderId));
  if (!o || !o.ctwa_clid || o.cancelled || !o.confirmed_at) return { skipped: true };
  if (o.capi_status === 'sent' || o.capi_status === 'expired') return { skipped: true };

  const set = (status, err = null) => db.prepare(
    'UPDATE orders SET capi_status=?, capi_error=?, capi_sent_at=? WHERE shopify_order_id=?')
    .run(status, err, status === 'sent' ? now : null, o.shopify_order_id);

  if (now - o.confirmed_at > SEVEN_DAYS - 600) { set('expired', 'event_time older than 7 days'); return { expired: true }; }
  if (!cfg.metaToken || !cfg.datasetId || !cfg.wabaId) { set('failed', 'META_ACCESS_TOKEN / META_DATASET_ID / META_WABA_ID not set'); return { failed: true }; }

  const body = { data: [buildPurchaseEvent(o, cfg)], partner_agent: cfg.partnerAgent };
  if (cfg.testEventCode) body.test_event_code = cfg.testEventCode;
  try {
    const res = await fetchImpl(`${GRAPH}/${cfg.graphVersion}/${cfg.datasetId}/events`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: `Bearer ${cfg.metaToken}` },
      body: JSON.stringify(body),
    });
    const j = await res.json().catch(() => ({}));
    if (!res.ok || j.error) throw new Error(j.error?.message || `HTTP ${res.status}`);
    set('sent');
    return { sent: true, response: j };
  } catch (e) {
    set('failed', String(e.message).slice(0, 500));
    return { failed: true, error: e.message };
  }
}

export async function retryPending(db, cfg, fetchImpl = fetch, now) {
  const rows = db.prepare(`SELECT shopify_order_id FROM orders
    WHERE capi_status IN ('pending','failed') AND cancelled=0 AND ctwa_clid IS NOT NULL`).all();
  const out = [];
  for (const r of rows) out.push(await sendPurchase(db, cfg, r.shopify_order_id, fetchImpl, now));
  return out;
}

/** Ad-level daily spend from the Marketing API Insights endpoint (every configured ad account), upserted into ad_spend. */
export async function syncSpend(db, cfg, days = 3, fetchImpl = fetch, today = new Date()) {
  if (!cfg.adsToken || !cfg.adAccountIds.length) throw new Error('META_ADS_ACCESS_TOKEN (or META_ACCESS_TOKEN) / META_AD_ACCOUNT_IDS not set');
  const day = (d) => d.toISOString().slice(0, 10);
  const since = day(new Date(today.getTime() - (days - 1) * 86400000));
  const up = db.prepare(`INSERT INTO ad_spend(day,ad_id,ad_name,spend,impressions,link_clicks,conversations,campaign_name,account_id)
    VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(day,ad_id) DO UPDATE SET ad_name=excluded.ad_name, spend=excluded.spend,
    impressions=excluded.impressions, link_clicks=excluded.link_clicks, conversations=excluded.conversations,
    campaign_name=excluded.campaign_name, account_id=excluded.account_id`);
  let n = 0;
  for (const acct of cfg.adAccountIds) {
    const qs = new URLSearchParams({
      level: 'ad', time_increment: '1', limit: '500',
      fields: 'ad_id,ad_name,campaign_name,spend,impressions,clicks,actions',
      time_range: JSON.stringify({ since, until: day(today) }),
    });
    let url = `${GRAPH}/${cfg.graphVersion}/act_${acct}/insights?${qs}`;
    while (url) {
      if (new URL(url).origin !== GRAPH) throw new Error('refusing to follow paging URL off graph.facebook.com');
      const res = await fetchImpl(url, { headers: { authorization: `Bearer ${cfg.adsToken}` } });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(`Insights act_${acct}: ${j.error?.message || res.status}`);
      for (const r of j.data ?? []) {
        const conv = (r.actions ?? []).find((a) => a.action_type === cfg.conversationAction);
        up.run(r.date_start, String(r.ad_id), r.ad_name ?? null, Number(r.spend) || 0,
          Number(r.impressions) || 0, Number(r.clicks) || 0, Number(conv?.value) || 0, r.campaign_name ?? null, acct);
        n++;
      }
      url = j.paging?.next ?? null;
    }
  }
  db.prepare("INSERT INTO kv(k,v) VALUES ('spend_sync_at',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v").run(String(Math.floor(Date.now() / 1000)));
  return n;
}

/**
 * Asks Meta for the dataset tied to the WhatsApp Business Account (returns the existing one, or creates it).
 * UNVERIFIED for WhatsApp: the Meta page that documents it was cut off; the Messenger flow is POST /{page_id}/dataset.
 */
export async function getWabaDataset(cfg, fetchImpl = fetch) {
  if (!cfg.metaToken || !cfg.wabaId) throw new Error('META_ACCESS_TOKEN / META_WABA_ID not set');
  const res = await fetchImpl(`${GRAPH}/${cfg.graphVersion}/${cfg.wabaId}/dataset`, {
    method: 'POST', headers: { authorization: `Bearer ${cfg.metaToken}` },
  });
  const j = await res.json().catch(() => ({}));
  if (!res.ok || j.error) throw new Error(j.error?.message || `HTTP ${res.status}`);
  return j.id;
}
