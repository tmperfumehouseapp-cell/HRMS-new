import fs from 'node:fs';

/** Minimal .env loader (no dependency). Real environment variables win. */
export function loadEnv(file = '.env') {
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (!m || line.trim().startsWith('#')) continue;
    let v = m[2];
    if (!/^['"]/.test(v)) v = v.replace(/\s+#.*$/, ''); // inline comment
    v = v.replace(/^(['"])(.*)\1$/, '$2');
    if (!(m[1] in process.env)) process.env[m[1]] = v;
  }
}

const list = (s) => String(s || '').split(',').map((x) => x.trim().toLowerCase()).filter(Boolean);

export function getConfig(env = process.env) {
  return {
    port: Number(env.PORT || 8080),
    dbPath: env.DB_PATH || './data/ctwa.sqlite',
    tzOffsetMin: Number(env.REPORT_TZ_OFFSET_MIN ?? 330),
    reportToken: env.REPORT_TOKEN || '',
    waVerifyToken: env.WA_VERIFY_TOKEN || '',
    waAppSecret: env.WA_APP_SECRET || '',
    shopifySecret: env.SHOPIFY_WEBHOOK_SECRET || '',
    shopifyDomain: env.SHOPIFY_SHOP_DOMAIN || '',
    shopifyAdminToken: env.SHOPIFY_ADMIN_TOKEN || '',
    shopifyApiVersion: env.SHOPIFY_API_VERSION || '2026-10',
    confirmStatuses: list(env.CONFIRM_FINANCIAL_STATUSES || 'paid,partially_paid'),
    confirmTag: (env.CONFIRM_TAG || '').trim(),
    metaToken: env.META_ACCESS_TOKEN || '',
    graphVersion: env.META_GRAPH_VERSION || 'v21.0',
    datasetId: env.META_DATASET_ID || '',
    wabaId: env.META_WABA_ID || '',
    adAccountId: String(env.META_AD_ACCOUNT_ID || '').replace(/^act_/, ''),
    partnerAgent: env.META_PARTNER_AGENT || 'TMPerfumehouse-CTWA',
    testEventCode: env.META_TEST_EVENT_CODE || '',
    conversationAction: env.META_CONVERSATION_ACTION_TYPE || 'onsite_conversion.messaging_conversation_started_7d',
    windowDays: Number(env.ATTRIBUTION_WINDOW_DAYS || 7),
    syncIntervalMin: Number(env.SYNC_INTERVAL_MIN ?? 60),
  };
}
