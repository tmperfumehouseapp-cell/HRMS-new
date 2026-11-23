# CTWA ROAS ટ્રેકિંગ (TMPerfumehouse)

Click-to-WhatsApp એડ → WhatsApp ચેટ → Shopify ઓર્ડર → Meta Purchase ઇવેન્ટ → રોજિંદો ROAS રિપોર્ટ.
કોઈ npm dependency નથી. Node ≥ 22.13 (બિલ્ટ-ઇન `node:sqlite`).

## ચાલુ કરવા
```bash
cd ctwa-roas
cp .env.example .env     # ટોકન/ID અહીં ભર (.env git માં જતું નથી)
npm test
npm start                # PORT પર સર્વર
```
સર્વર HTTPS પાછળ (reverse proxy) મૂકવું. Meta/Shopify ફક્ત HTTPS URL સ્વીકારે છે.

| Endpoint | કામ |
|---|---|
| `GET/POST /webhooks/whatsapp` | Meta App → WhatsApp → Webhooks. Callback URL આ, Verify token = `WA_VERIFY_TOKEN`, field `messages` subscribe કર |
| `POST /webhooks/shopify` | Shopify માં `orders/create` અને `orders/updated` webhook, આ URL પર |
| `GET /report?date=YYYY-MM-DD&days=N&format=csv` | `Authorization: Bearer $REPORT_TOKEN` સાથે |

## ફ્લો
1. **WhatsApp**: `X-Hub-Signature-256` ચકાસે. `messages[].referral` માંથી `ctwa_clid`, `source_id` (એડ ID), `headline`, ફોન (છેલ્લા 10 અંક) `clicks` ટેબલમાં. એ જ મેસેજ ફરી આવે તો ડુપ્લિકેટ નહીં.
2. **Shopify**: `X-Shopify-Hmac-SHA256` ચકાસે, `X-Shopify-Event-Id` થી ડુપ્લિકેટ રોકે. ઓર્ડરના બધા ફોન (order / customer / shipping / billing) ના છેલ્લા 10 અંક સાથે ઓર્ડર સમયથી પાછળ 7 દિવસના **સૌથી તાજા** ક્લિકને મેચ કરે. મેચ થાય તો `ad_id` DB માં અને (જો `SHOPIFY_ADMIN_TOKEN` હોય તો) ઓર્ડર પર ટેગ `CTWA` અને `CTWA-ad-<ad_id>`.
3. **કન્ફર્મ**: `CONFIRM_FINANCIAL_STATUSES` (ડિફોલ્ટ `paid,partially_paid`) અથવા COD માટે `CONFIRM_TAG` (દા.ત. `Confirmed`). કેન્સલ ઓર્ડર ક્યારેય નથી જતો. `event_id` = Shopify ઓર્ડર ID, એટલે ફરી મોકલાય તો પણ Meta ડુપ્લિકેટ ગણે. નિષ્ફળ થાય તો `failed` અને દર `SYNC_INTERVAL_MIN` પર રીટ્રાય (`npm run retry-capi`).
4. **રિપોર્ટ**: `npm run report` (ડિફોલ્ટ ગઈકાલ, IST), `-- --date 2026-11-21 --days 7 --csv --sync`. કૉલમ: spend, queries (યુનિક ctwa_clid), orders, confirmed_orders, revenue (ફક્ત કન્ફર્મ), ROAS, cost/query, cost/order. Spend માટે `npm run sync-spend` (સર્વર ચાલુ હોય તો આપોઆપ).
   Cron ઉદાહરણ: `30 8 * * * cd /path/ctwa-roas && node src/cli.js report --sync --csv >> daily.csv`

## ડોક્યુમેન્ટ વેરિફિકેશન (2026-11-22)
`developers.facebook.com` / `shopify.dev` સીધા ખૂલ્યા નહીં (નેટવર્ક બ્લોક). Meta Ads MCP ની help-search અને Shopify MCP docs-search થી વાંચ્યું.

| વસ્તુ | સ્થિતિ |
|---|---|
| CAPI: `POST /{version}/{dataset_id}/events`, `data[]`, `partner_agent` | ✅ વેરિફાય |
| `event_name`, `event_time` (≤7 દિવસ જૂનો, નહિતર આખી રિક્વેસ્ટ રિજેક્ટ), `event_id`, `action_source=business_messaging`, `messaging_channel`, `custom_data.currency/value` | ✅ વેરિફાય |
| `ctwa_clid` WhatsApp માટે ફરજિયાત; permission `whatsapp_business_manage_events` | ✅ વેરિફાય |
| Shopify HMAC: base64(HMAC-SHA256(raw body, client secret)), delivery ID થી ડુપ્લિકેટ | ✅ વેરિફાય |
| WhatsApp `user_data` કી: `whatsapp_business_account_id` + `ctwa_clid` | ⚠️ **અનવેરિફાય** (ડોક પેજ WhatsApp સેમ્પલ પહેલાં કપાઈ ગયું) |
| Webhook `messages[].referral` ફીલ્ડ: `ctwa_clid, source_id, source_type, source_url, headline, body, media_type` | ⚠️ **અનવેરિફાય** (યાદદાસ્ત પરથી) |
| X-Hub-Signature-256, `hub.*` GET handshake | ⚠️ અનવેરિફાય (સ્ટાન્ડર્ડ Meta પદ્ધતિ) |
| Insights: `level=ad`, `time_increment=1`, `actions[].action_type` = `onsite_conversion.messaging_conversation_started_7d` | ⚠️ અનવેરિફાય |
| Shopify `tagsAdd` mutation, API વર્ઝન `2026-10` | ⚠️ અનવેરિફાય |
| Graph વર્ઝન: ડોક ઉદાહરણ `v16.0` (જૂનું). ડિફોલ્ટ `v21.0` | ⚠️ તમારું વર્તમાન વર્ઝન `META_GRAPH_VERSION` માં મૂક |

**પ્રોડક્શન પહેલાં**: `META_TEST_EVENT_CODE` મૂકી Events Manager → Test events માં એક ઓર્ડર મોકલી ફીલ્ડ ચકાસ. ખોટું હોય તો ફક્ત `buildPurchaseEvent` (`src/meta.js`) અને `extractClicks` (`src/whatsapp.js`) બદલવા પડે.

## મર્યાદાઓ
- CAPI ઇવેન્ટ મોકલી દીધા પછી ઓર્ડર કેન્સલ/RTO થાય તો Meta માંથી પાછી ખેંચાતી નથી; ROAS રિપોર્ટમાં કેન્સલ ઓર્ડર બાદ થાય છે.
- Click ના દિવસે queries, ઓર્ડરના દિવસે orders/revenue ગણાય; રિપોર્ટ ઘણા દિવસનો હોય ત્યારે જ તુલના સચોટ.
- સિંગલ-પ્રોસેસ SQLite; ઘણા સર્વર પર ચલાવવું નહીં.
