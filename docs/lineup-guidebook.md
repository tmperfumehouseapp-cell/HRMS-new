# 7-Day Lineup — Guidebook

How the whole system works, how to read it, and ideas to push it harder (including sharper price offers).
The 33 messages themselves are in `docs/lineup-messages.md`. The live screen is on Campaigns (top card) and at `/lineup.html`.

## 1. What it does
A follow-up ladder of up to 7 days for every customer who wrote to us and did not order. Each customer gets the ladder of the **topic he asked about** (price, trust, size, address/payment pending, reorder, general, campaign reply). The newest topic in the chat wins; if the topic changes, the ladder switches.

## 2. Rules (all built in)
- **Who:** customers who wrote or replied (not just "read"), from the day before yesterday 10:00 onward; that backlog counts as "today".
- **When:** step 1 goes 24 h after the END of the conversation (either side's last message — a salesperson's message counts too, Option A). Step 2+ go 24 h after the previous lineup message really went.
- **Stops:** order placed, opt-out, complaint, muted, Autopilot / Journey follow-up that day.
- **Pause:** if the customer replies, the ladder pauses and is re-read after 24 quiet hours.
- **Always on:** no on/off switch. "Send what is due now" is only a manual extra.
- **Independent:** no Module A/B time windows, allowances or campaign holds.
- **Number:** the message leaves from the number the customer wrote to.
- **Messages:** the shop's own already-approved templates (utility or marketing) mapped per step.

## 3. What to watch (Campaigns top card → open)
- Sent / Replies / Orders / Cost, today and since the start.
- Last round: due, sent, and the reasons the rest waited.
- Self-check (issues found / fixed).
- Report: orders credited to the last lineup message within 7 days, per topic / step / wording.
- Customer lookup: one customer's whole ladder.

## 4. How it learns
Each topic/step has two wordings in some places. The report counts orders per wording; compare after enough volume (hundreds of sends per wording, not dozens) and keep the better one for the first step.

## 5. Making it more aggressive — ideas (suggestions, not yet built)
Price is the topic where the biggest lift is likely. Ideas, safest first:
1. **Step 1 of `price` carries a real number.** Instead of "budget batao", state the entry price ("20 ml from ₹xxx") so the customer decides faster. Test as a second wording and let the report decide.
2. **Combo / bundle by step 3–4.** Offer a fixed combo price (2 × 20 ml) instead of asking whether they want to see combos.
3. **Time-boxed offer on the last step (day 6–7).** One clear deadline ("today only"). Needs a marketing-category template; costs ₹1.09 vs ₹0.15 for utility, so use only on the last step, only for price/size topics.
4. **Free-delivery or COD-fee waiver as the nudge** for `pending` (address/payment) customers — they already wanted to buy.
5. **Reorder topic:** a loyalty price for repeat buyers on step 2.
6. **Send-hour:** the Best-hours card on Campaigns shows which hour replies best; shift step 1 toward it.
7. **Stop-loss:** pause a wording whose orders-per-100-sends is clearly lowest after enough volume.

Cautions: Meta can relabel or throttle templates that read as promotions; keep step 1–4 utility-style and put offers late. Never quote a price or discount you are not ready to honour — the message is sent automatically.

## 6. Operating notes
- Tune with real numbers from the report, not feeling; change one thing at a time.
- Watch "failed" and "waiting" reasons daily the first week.
- Costs: utility ₹0.15, marketing ₹1.09 per message (shop rates).

## 7. Update 21 Nov — the Lineup is now the 30-Day Lineup
Twelve steps on day 1, 2, 3, 4, 6, 8, 10, 15, 18, 22, 26, 30 after the end of the conversation (plan and data: `docs/lineup-30day-plan.md`).
Days 6, 10, 18, 26 carry the price of the 4 x 50 ml (200 ml) combo from the shop's price chart (one column deeper each time, never higher than what the customer was told, the chart's last column is the floor); day 8 carries the 2% prepaid. These are marketing messages that Meta must approve; until then the plain message of the same step goes.

## 8. Update 21 Nov — the 24/7 bot answers everyone fast
The existing server bot (wa_growth.php `wag_bot_scan`) now answers every customer who wrote 4 s after the message, for as long as the chat goes on, until a salesperson presses Take over (the bot is then silent for 36 h) or writes to the customer (silent 20 min). Three workers (`wa.bot_run`, started every minute by the sending job) loop every ~2 s, newest chats first; one draft per answer (fast); replies that invent an offer or claim a payment are never sent (a person takes over). Complaint, payment intent, photos/voice, order-status questions keep their old rules. Switch: setting `wa_g_cfg` (bot, bot_after, bot_max_day); the bot's `wag_bot4` migration is run once.
Added the same day (owner's authority): ORDER MODE — in a chat where the customer wants to order / pay (hot), the bot collects perfume, size, quantity, name, full address + pincode, never payment; when a pincode is in the chat it stops (`botoff`), writes a chat note and pushes the salesperson to send the payment. COMPLAINT SWEEP — an unanswered chat that reads like a complaint (leak, wrong item, not delivered, quality, refund) gets a complaint ticket (`cx_raise`, Complaint team owns it) and the customer is told the ticket number.

## 9. Bot follow-up (aggressive, 21 Nov)
The bot no longer only answers: when a customer it spoke to goes silent, it follows up by itself inside the 24-hour window (free text) at 15 min, 30 min, 1 h, 2 h, 4 h, 8 h and 18 h after the customer's last message (9:00–21:30 only).
Each step has its own angle (missing detail → exact price → add-on towards Rs 2,700–2,800 → two options → true urgency → one-word YES → last nudge); after the 7th the chat goes to OPEN (orange). It never follows up when a person took the chat, the bot handed it over, the order is done,
the customer said stop / no, or a flow runs. Switch: `wag_bfu_on`; daily cap `wag_bfu_max_day` (2,500). Every follow-up is written in the chat's event line.
