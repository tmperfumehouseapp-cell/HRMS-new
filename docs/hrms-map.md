# HRMS map

Saved from the workspace survey. No config / `.env` / credential files were opened. Table lists come from SQL in PHP.

## Step 1 — Top-level folders

| Folder | Belongs to HRMS? | Notes |
|--------|------------------|--------|
| `empmanagment` | **Yes** | Core PHP backend (`api.php` + feature modules) |
| `assets` | **Yes** | HRMS CSS / JS / images |
| `icons` | **Yes** | PWA / favicon icons for HRMS |
| `analytics` | **No** | Separate TM store / SEO analytics (Shopify site). Admin can open it via Store Analytics iframe — still its own app |
| `reward` | **No** | Four Pirates loyalty/rewards (Shopify). Contains a **copy** of HRMS `api.php` — that copy is HRMS code sitting in the wrong folder |
| `chain` | **No** | Standalone product catalog page (“Chain Collection”) |
| `.git` | Infra | Git metadata |
| `.vscode` | Infra | Editor settings |

Root `*.html` pages + `hub.html` / `workspaces.js` / `sw.js` are the HRMS frontend (not a folder, but part of HRMS).

---

## Step 2 — HRMS modules only

Organized by the live menu in `assets/js/layout.js`. Backend lives under `empmanagment/`. Tables listed from code refs; anything not clearly tied is **unknown**.

### Shared / foundation

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Auth & shell | Login, sidebar, hubs, PWA | `index.html`, `hub.html`, `assets/js/{api,layout,config}.js`, `manifest.json`, `sw.js`, `icons/` | `users`, `roles`, `hrms_settings`, `push_subscriptions`; auth is JWT (no token table found) |
| Core API | Single entry API for most actions | `empmanagment/api.php` | Core HR + sales tables below; many feature tables auto-created here |

Core HR tables used heavily (some created elsewhere / assumed existing — **create site unknown** for a few):  
`employees`, `users`, `roles`, `departments`, `attendance`, `leave_requests`, `agreements`, `agreement_templates`, `agreement_emails`, `document_types`, `employee_documents`, `employee_penalties`, `salary_rules`, `offices`, `day_marks`, `day_timing`, `office_presence`, `punch_*`, `employee_shifts`, `employee_addons`, …

---

### Home

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Dashboard | “What needs you today” | `dashboard.html` | Aggregates many modules — **unknown** exact set |

---

### Sales

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Sales hub / Control Tower | Orders, targets, people, learn & setup | `sales.html`, `control_tower.html`, `assets/js/{sales_*,ct_*,cx_*}.js`, `empmanagment/ct_people.php` | `shop_orders`, `ct_*`, `order_*`, `panel_*`, `campaign_queries`, `employee_query_log`, … |
| Shopify orders & allocation | Order list, assign codes/phones | `orders.html`, `order_allocation.html`, `order_codes.html` | `shop_orders`, `order_allocations`, `order_codes`, `order_address`, `order_extra_phones`, `order_chain`, `order_community`, `order_cancels`, `order_moved`, `order_remarks`, `order_ratings`, … |
| Targets / performance / commission | Sales targets & pay rules | `targets.html`, `order_targets.html`, `performance.html`, `detailed_report.html`, `commission*.html`, `leaders.html`, `leader_pay.php`, `leader_roas.php` | `order_targets`, `order_target_*`, `panel_targets`, `target_rule(s)`, `commission_*`, `leader_pay_adj`, `leader_roas_manual`, `rating_daily`, `employee_daily_overall`, … |
| Retail shops (dealer) | Shop visits, stock, dues | `retail_shops.html`, `retail_inventory.html`, `retail_field.html`, `retail_field.php` | `retail_*`, `rf_*` |
| Sales chat / queries | My chats & WA query board | `my_chats.html`, `my_sales.html`, `my_orders.html`, `wq.php`, `wa_sales.php` | `wq_*`, `wa_queries`, `wa_query_moves`, … |

---

### WhatsApp

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| WhatsApp console | Cloud API chats, media, templates, campaigns | `whatsapp.html`, `wa_*.html`, `empmanagment/wa*.php`, `assets/js/wa_*.js` | `wa_contacts`, `wa_messages`, `wa_log`, `wa_numbers`, `wa_templates`, `wa_campaigns`, `wa_camp_items`, `wa_flows*`, `wa_fu_*`, `wa_media_*`, `wa_prod*`, `wa_seg_*`, `wa_smart_*`, `wa_ord_*`, … (large `wa_*` set) |
| WhatsApp groups | Staff WA group mapping | `whatsapp_groups.html` | `employee_whatsapp_groups` (inferred from API naming) — **confirm unknown** |
| WhatsApp Sales | Query ownership / focus board | `wa_sales_live.html`, `wq.php`, `wa_sales.php` | `wq_*` |
| WA cart / catalog (`wch`) | Chain+perfume WhatsApp cart | `wa_setup.html` (related), `wch.php` | `wch_*`, `statements` |

---

### Complaints

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Complaints board | One case per customer problem | `complaints.html`, `my_complaints.html`, `complaints.php`, `complaints_more.php` | `cx_cases`, `cx_events`, `cx_links`, `cx_lessons`, `cx_state`, `cx_quick`, `cx_credit`, `cx_files`, `cx_qc` |

---

### Social inbox

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Social inbox | IG / FB / email / reviews in one place | `social_inbox.html`, `social.php`, `social_*.php` | `sx_*`, `sxl_*` |

---

### Boss Engine

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Boss Engine | Duties, seats, inbox, penalties, sections | `boss_engine.html`, `boss_*.html`, `daily_summary.html`, `tracker.html`, `boss.php`, `boss2.php`, `check.php`, `claude_q.php` | `bd_*`, `b2_*`, `ck_*`, `cq_*`, `cm_boosts`, `cm_offers` |
| People (Boss) | Unified people/seats/login UI | `people.html`, `people_admin.html`, `people.php` | Reads `employees`, `departments`, `users`, `bd_seats`, `bd_holders`; cache `pp_cache` |
| Brain | Shared knowledge / teach-by-talking | `brain.html`, `brain.php` | `brain_*`, also `sxl_*` |
| Departments / workbenches | Dispatch, money, shops, supplies, tasks, repairs | `dept_*.html`, `dept.php`, `dept_*.php` | `dp_*` (e.g. `dp_items`, `dp_dsp_*`, `dp_mn_*`, `dp_sh_*`, `dp_su_*`, `dp_tk_*`, `dp_fx_*`, …) |
| Commission (admin under Boss) | Commission setup | `commission_new.html`, `boss_rules.html` | `commission_*` |

---

### Dispatch (menu item)

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Dispatch | Parcels, tracking, Shiprocket | `dept_dispatch.html`, `dept_dispatch*.php` | `dp_dsp_parcels`, `dp_dsp_couriers`, `dp_dsp_events`, `dp_dsp_untr*`, `dp_items`, `shop_orders` |
| Order + Customer popup, Customers list | One big popup (order card, merged timeline, customer card, WhatsApp chat) and one row per person (last 10 digits of the phone: online, store, old POS) | `customers.html`, `assets/js/order_hub.js`, `empmanagment/order_hub.php` (`dp.dsp_hub`, `dp.dsp_pay`, `dp.dsp_cust_list`, `dp.dsp_cust_rebuild`) | `customer_facts` (a copy, rebuilt every 6 h), reads `shop_orders`, `st_bills`, `st_bill_people`, `pos_orders`, `pos_customers`, `cx_cases`, `wa_ord_cases`, `order_facts` |

---

### Store

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Store | Inventory (per-bottle barcodes) / billing / cleaning + pantry | `store_inventory.html`, `store_billing.html`, `store_pos.html`, `store_pos.php`, `store_lib.php` | `st_*`, `retail_cash_use`, … |
| Flyers | Flyer drops & map | `flyers.html`, `flyer_map.html`, `flyers.php` | `fl_assign`, `fl_drops`, `fl_settings` |
| Store analytics page | Embeds external analytics | `store_analytics.html` | Uses **analytics** app tables, not HRMS — see non-HRMS |

---

### Finance

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Finance | Money in, received, outstanding, summary | `finance_in.html`, `finance_sum.html`, `finance_parcels.html`, `finance_in.php` | `fi_*` (old `fin_*`/`lg_*` tables kept, read-only) |

---

### People / HR (hidden workspace; still HRMS)

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Employees | CRUD people / ex-employees / handover | `employees.html`, `employee_detail.html`, `ex_employees.html`, `handover.html` | `employees`, `departments`, related `employee_*` |
| Attendance & punch | Admin attendance + My Day punch | `attendance_admin.html`, `attendance.html`, `my_day.html`, punch geo pages | `attendance`, `day_marks`, `day_timing`, `punch_*`, `office_presence`, `footwear_*` |
| Leave | Leave admin + employee leave | `leave_requests.html`, `leave.html` | `leave_requests` |
| Salary & penalties | Pay calc & fines | `salary.html`, `penalties.html`, `my_salary.html`, `my_penalties.html`, `salary_pen.html` | `salary_rules`, `employee_penalties`, `penalty_rules`, `penalty_notifications` |
| Documents & agreements | Docs, AI doc check, contracts | `documents.html`, `doc_check.html`, `agreements.html`, `my_agreements.html`, `agreement_*.html` | `document_types`, `employee_documents`, `agreements`, `agreement_templates`, `agreement_emails`, `ck_doc_*` |
| Approvals / history | Pending approvals, decline history | `approvals.html`, `history.html` | **unknown** exact tables (likely flags on leave/penalties/docs) |

---

### Offices

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Office care | Cleaning zones, AI photo checks | `office_care.html`, `Offices.html`, `office.php`, `office_place.php` | `of_*`, `of_pl_*` |
| To buy / To fix | Shopping list & repairs | `dept_work.html`, `dept_fix.html`, `dept_work.php`, `dept_repairs.php`, `dept_supplies.php` | `dp_su_*`, `dp_fx_*`, `dp_wk_shots`, `dp_items` |

---

### Admin

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Roles / users / offices / excel | Rare setup | `roles.html`, `users.html`, `Offices.html`/`offices.html`, `excel_files.html`, `departments.html` | `roles`, `users`, `offices`, `departments`, `employee_excel_files` (name inferred) |
| System health / Help | Health checks | `system_health.html`, `help.html`, `health.php`, `help_*.php` | `hlp_*` (SQLite-backed help storage noted in code) |
| TMPH AI assistant | In-app Gemini chat | `assets/js/tmph.js`, actions in `api.php` | `tmph_log`, `tmph_media`, `ai_usage` |

---

### Other HRMS-adjacent features (pages exist; lighter menu presence)

| Module | Purpose | Main files | DB tables |
|--------|---------|------------|-----------|
| Learning / backups | Bot learn backups | `learning.html`, `backups.html`, `learn_backup.php` | **unknown** / file backups under `learn_backups/` |
| Photos / products / price list | Sales learn assets | `photos.html`, `products.html`, `price_list.php`, `wa_photos.php`, `wa_prod.php` | `ph_*`, `photo_cat_*`, `wa_prod*`, `md_items` |
| Control / retail extras | Misc ops pages | `retail_control.html`, `ml_price.html`, `dealer_order.html`, … | mix of `retail_*` / `rf_*` — **partially unknown** |
| Maintenance / diag | Ops utilities | `Maintenance.html`, `maintenance_bypass.php`, `diag.php` | **unknown** |

---

## Explicitly not HRMS modules (folders)

- **`analytics/`** — store traffic/SEO (`an_changes`, `speed_tests`, `seo_*`; also file/SQLite under `analytics/data/`)
- **`reward/`** — loyalty (`reward_codes`, `reward_ledger`, `reward_orders`, `reward_wallet`, `reward_settings`)
- **`chain/`** — static marketing page; no HRMS DB

Also non-HRMS at top level: `.git`, `.vscode` (infra only).

---

## Caveats

- No application files were changed during the survey that produced this map.
- Config / `.env` / DB credential files were not opened.
- Table lists come from SQL in PHP; core tables like `employees` / `users` / `attendance` are clearly used but their original `CREATE TABLE` site in this tree is **unknown** (likely pre-existing schema).
- `reward/api.php` is a misplaced HRMS API copy, not the rewards product itself.
- Anything marked **unknown** was not confirmed from unambiguous code references.
