# Cleanup audit: keep / merge / remove

Rule: **one place per job.** Everything else reads from that place. Nothing is typed or saved in two places.

## DONE (this change)
| Removed | Why | Replaced by |
|---|---|---|
| `finance.html`, `assets/js/finance.js`, `assets/css/finance.css` | old Finance screen | Finance → Summary / Incoming / Refunds & closed |
| `empmanagment/finance.php` (`fin.*` actions, `fin_*` tables) | old bank/settlement system | `finance_in.php` (`fi.*`) |
| `empmanagment/ledger.php` (`bd.lg_*`, `lg_tick` Boss alerts) | second old Finance (ledger) | `finance_in.php` |
| Old Finance bell notices (`fin_review`, `fin_overdue`) | counted the old tables | Finance → Incoming shows what is unpaid |
| Boss page list entry for `finance.html` | page is gone | `finance_in.html` |

DB tables `fin_*` and `lg_*` are **kept** (nothing dropped). Finance → Summary still reads old ledger spend rows if they exist; no new rows are written.

## KEEP (the single source)
| Job | The one place |
|---|---|
| Money booked / received / outstanding | Finance → Incoming (`fi_*`) |
| Per-parcel payment | Dispatch row chip (writes the same `fi_receipts`; Incoming reads it) |
| Store billing | Store → Billing (`st_*`) |
| Stock + barcodes | Store → Inventory |
| Customer contacts from billing | Store → Contacts (one file) |
| AI answers | `brain.php` + the one Admin assistant button |

## DONE (second cut)
| Removed | Notes |
|---|---|
| Old POS: Store Desk, Old POS tabs, `store_pos.html`, `store_data.html`, all `tmpos/` billing, inventory, orders, reports, ledger, cash-out, expenses, flyers, labels | `pos_*` tables kept (Store ranking still reads old POS sales history) |
| Daily money: `dept_money.html/php`, the 9 PM rule, `accounts.money` seat, its Boss questions, hub tile | `dp_mn_*` tables kept; Finance → Summary still reads past entries. Nothing new is written |
| Old Retail Store setup: `retail_store.html/php` (never loaded, never linked) | `rs_*` permissions kept |
| 11 unlinked pages: agreement_template, attendance_geo, commission_card_snippet, footwear_test, hrms_3d_ui, my_store_tasks, punch_checks, salary_pen, store_analytics, assign_queries | each checked for links, scripts and callers first |

**Kept on purpose:** the whole retail / wholesale side (Retail Shops, Retail Inventory, Retail Field, Retail Control, Retail displays, ML price check, dealer order) and **Dispatch → Store orders** (the dispatcher's restock page, `tmpos/restock*.php`): the new Store has no dispatcher-side screen yet.

## Claude → one brain
Moved onto `brain.php`: Department questions (dept.php), Boss chat (dept_boss.php), Boss Engine 2 (boss2.php), Retail Field photo checks, Help health doctor. Left alone (reason): the assistant engine in api.php (streams and uses tools; brain has neither), and the 3 SEO tools in `analytics/` (separate site, own key and config).

## One brain for the bots (what is shared, what is not)
- ONE reply engine (`lib_bot_reply`: guidebook + lessons + corrections + store facts) writes replies for the Teach bot (Practice), WhatsApp suggestions, WhatsApp follow-ups, growth flows and the social Inbox. What is taught once is used everywhere.
- Different on purpose: product prices and photos (Shopify, `wa_prod.php`), fixed answers (greetings, ad / website buttons: plain text, no Claude), campaigns and templates.
- Two stores of taught knowledge are bridged: the guidebook / lessons (Learning, Teach by talking) and the Brain facts (Brain page). The Brain page's "Old notes" tab lists the older Teach-the-bot lines so they can be replaced or stopped.
- Corrections: Teach bot 👍/👎 (`lib_feedback`) and WhatsApp used / edited / own (`wa_learn`) already existed. Now the assistant, the Boss chat and approved / refused change cards are logged too (`ai_log`).
- Nightly review (`learn-review.yml` + `learn_loop.php`): Claude Code reads the masked last 24 hours and drops suggestions into Brain → Suggestions. Approve = a Brain note, a correction for the next guidebook draft, or a Changes request.
