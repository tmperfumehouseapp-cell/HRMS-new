<?php
/**
 * store_lib.php — Store inventory + billing: tables and shared helpers (no routing here).
 *
 * Safe to require_once from anywhere (api.php uses st_house_orders() to feed store bills into the same sales
 * list online orders use, so commission / targets treat a store bill exactly like an online order).
 *
 * Tables (made on first use):
 *   st_products    perfumes pulled from Shopify
 *   st_bottles     ONE ROW PER PHYSICAL BOTTLE with its own short code:  generated -> stock -> sold  (or void)
 *   st_bills       a bill: who sold it, total ml, price, order code
 *   st_bill_items  the bottles on a bill
 *   st_duty        employees who may be picked as "Billed by"
 */

const ST_CODE_BASE = 100000;     // code = ST_CODE_BASE + bottle_id  -> always 6 digits (up to 999999): short code = few, fat bars = easy scan
const ST_SIZES = [10, 20, 50, 100];
/** "Where did the customer come from?" — asked at billing. key => label */
const ST_SOURCES = ['insta_ad' => 'Instagram ad', 'fb_ad' => 'Facebook ad', 'flyer' => 'Flyer', 'friend' => 'Friend suggestion', 'first_time' => 'First time visitor', 'google' => 'Google / Maps', 'whatsapp' => 'WhatsApp', 'other' => 'Other'];
const ST_DAY_START_HOUR = 3;      // the store's new day starts at 3 AM: a bill made at 2:30 AM still belongs to the day before

/** The hour the store's new day starts (default 3). */
function st_day_hour(): int
{
    $h = ST_DAY_START_HOUR;
    if (function_exists('setting_get')) { global $pdo; if ($pdo instanceof PDO) { $v = setting_get($pdo, 'st_day_start', ''); if ($v !== '' && $v !== null && is_numeric($v)) $h = max(0, min(11, (int)$v)); } }
    return $h;
}

/** The store's business day (Y-m-d) for a moment: shifts the clock back by the day-start hour, so the date turns over at 3 AM, not at midnight. */
function st_day(?int $ts = null): string
{
    return date('Y-m-d', ($ts ?? time()) - st_day_hour() * 3600);
}

function ensure_st_tables(PDO $pdo): bool
{
    static $done = null;
    if ($done !== null) return $done;
    $flag = sys_get_temp_dir() . '/hrms_st_tables_v1';
    if (is_file($flag)) { st_migrate($pdo); return $done = true; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_products (
            product_id INT AUTO_INCREMENT PRIMARY KEY,
            shopify_id BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            image VARCHAR(500) NOT NULL DEFAULT '',
            active TINYINT NOT NULL DEFAULT 1,
            synced_at DATETIME NULL,
            run_tag VARCHAR(16) NOT NULL DEFAULT '',
            UNIQUE KEY uq_st_shopify (shopify_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_bottles (
            bottle_id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(12) NOT NULL,
            product_id INT NOT NULL,
            ml INT NOT NULL,
            kind VARCHAR(8) NOT NULL DEFAULT 'sale',
            status VARCHAR(10) NOT NULL DEFAULT 'generated',
            batch VARCHAR(20) NOT NULL DEFAULT '',
            mfg_date DATE NOT NULL,
            exp_date DATE NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            stocked_at DATETIME NULL,
            sold_at DATETIME NULL,
            bill_id INT NULL,
            UNIQUE KEY uq_st_code (code),
            KEY idx_st_prod (product_id, status, kind, ml),
            KEY idx_st_status (status),
            KEY idx_st_batch (batch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_bills (
            bill_id INT AUTO_INCREMENT PRIMARY KEY,
            bill_no VARCHAR(20) NOT NULL DEFAULT '',
            biz_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            emp_id INT NOT NULL,
            emp_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_phone VARCHAR(20) NOT NULL DEFAULT '',
            total_ml INT NOT NULL DEFAULT 0,
            bottles INT NOT NULL DEFAULT 0,
            testers INT NOT NULL DEFAULT 0,
            price_col TINYINT NULL,
            list_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            order_code VARCHAR(20) NOT NULL DEFAULT '',
            pay_mode VARCHAR(10) NOT NULL DEFAULT 'cash',
            cash_amt DECIMAL(10,2) NOT NULL DEFAULT 0,
            online_amt DECIMAL(10,2) NOT NULL DEFAULT 0,
            status VARCHAR(8) NOT NULL DEFAULT 'ok',
            created_by INT NULL,
            KEY idx_st_bill_day (biz_date, status),
            KEY idx_st_bill_emp (emp_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_bill_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            bill_id INT NOT NULL,
            bottle_id INT NOT NULL,
            ml INT NOT NULL,
            kind VARCHAR(8) NOT NULL DEFAULT 'sale',
            KEY idx_st_item_bill (bill_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_duty (
            emp_id INT NOT NULL PRIMARY KEY,
            added_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @file_put_contents($flag, '1');
        st_migrate($pdo);
        return $done = true;
    } catch (Throwable $e) {
        return $done = false;
    }
}

/** The 6-digit code a bottle carries (bottle_id 1 -> 100001). */
function st_code_for(int $bottleId): string
{
    return (string)(ST_CODE_BASE + $bottleId);
}

/** Digits only, the way a scanner or a typed code arrives. */
function st_clean_code($c): string
{
    return preg_replace('/\D/', '', (string)$c);
}

/** What a set of scanned bottles adds up to. Testers count as bottles but add no ml and no price. */
function st_totals(array $bottles): array
{
    $ml = 0; $sale = 0; $tester = 0; $gift = 0;
    foreach ($bottles as $b) {
        if (($b['kind'] ?? 'sale') === 'gift') { $gift++; continue; }      // a birthday gift: free, never counted in the ml / price / commission
        if (($b['kind'] ?? 'sale') === 'tester') { $tester++; continue; }
        $ml += (int)$b['ml']; $sale++;
    }
    return ['total_ml' => $ml, 'sale_bottles' => $sale, 'testers' => $tester, 'gifts' => $gift];
}

/**
 * The price of TOTAL ml in every column of the Price & Commission chart (Learn & Setup → Pricing): the same chart the
 * online cart uses. Between two rows the price is worked out in proportion; outside the chart there is no chart price.
 * @return array|null  list of prices, one per column — null when the ml is outside the chart
 */
function st_chart_prices(array $chart, int $ml): ?array
{
    $rows = $chart['rows'] ?? [];
    if (!$rows || $ml <= 0 || $ml < $rows[0]['ml'] || $ml > $rows[count($rows) - 1]['ml']) return null;
    foreach ($rows as $i => $a) {
        if ((float)$a['ml'] === (float)$ml) return array_map('floatval', $a['p']);
        $b = $rows[$i + 1] ?? null;
        if ($b && $ml > $a['ml'] && $ml < $b['ml']) {
            $f = ($ml - $a['ml']) / ($b['ml'] - $a['ml']);
            $p = [];
            foreach ($a['p'] as $j => $v) $p[] = round((float)$v + ((float)$b['p'][$j] - (float)$v) * $f);
            return $p;
        }
    }
    return null;
}

/**
 * Store bills shaped like the other "house" orders, so they flow into the SAME sales / commission / target code as online orders.
 * The bill's total ml is carried in 'ml' — that is what the Price & Commission chart reads to work out the commission %.
 */
function st_house_orders(PDO $pdo, string $dateFrom, string $dateTo): array
{
    static $memo = [];      // api.php asks for the same days many times in one request: one query is enough
    $mk = $dateFrom . '|' . $dateTo;
    if (isset($memo[$mk])) return $memo[$mk];
    if (!ensure_st_tables($pdo)) return $memo[$mk] = [];
    $st = $pdo->prepare("SELECT * FROM st_bills WHERE status = 'ok' AND biz_date BETWEEN ? AND ? ORDER BY bill_id");
    $st->execute([$dateFrom, $dateTo]);
    $out = [];
    foreach ($st->fetchAll() as $b) {
        $out[] = [
            'id' => (string)$b['bill_id'],
            'source' => 'store',
            'order_name' => (string)$b['bill_no'],
            'created_at' => (string)$b['created_at'],
            'biz_date' => (string)$b['biz_date'],      // the STORE day (3 AM to 3 AM): store sales are counted on it; online sales keep the 10 AM to 10 AM HRMS day
            'total' => (float)$b['total'],
            'financial_status' => 'paid',          // paid in full at the counter
            'pre' => false,                         // NOT 'prepaid': no online prepaid discount was given, so the chart's prepaid uplift must not apply
            'outstanding' => 0,
            'customer' => (string)$b['customer_phone'],
            'tags' => 'tmph, Store',
            'house' => 'tmph',                      // TM Perfume House group: targets and commission see it like any perfume sale
            'order_type' => '',
            'emp_id' => (int)$b['emp_id'],
            'salesman' => (string)$b['emp_name'],
            'ml' => (int)$b['total_ml'] ?: null,
            'code' => (string)$b['order_code'],
        ];
    }
    return $memo[$mk] = $out;
}

/**
 * Teach the shared (internal) brain how the Store works, once, so every Claude that reads the internal shelf knows it
 * (Boss checks, Offices, the TMPH assistant ...). Staff-only knowledge: never shown to customers. Safe to call often: a flag file
 * makes it a no-op, and brain_fact_add() never writes the same sentence twice.
 */
function st_brain_seed(PDO $pdo): void
{
    $flag = sys_get_temp_dir() . '/hrms_st_brain_v1';
    if (is_file($flag) || !function_exists('brain_fact_add')) return;
    $facts = [
        'Store billing: every bottle in the store has its own unique 6-digit barcode (made in Store > Inventory). A bill adds up the TOTAL ml of the scanned bottles; testers count as 0 ml and are free. The price comes from the same Price & Commission chart the online sales use, looked up by total ml.',
        'Store sales go into the same sales list as online orders, so commission, daily target and bonus follow exactly the same rules. The salesperson of a store bill is the person chosen in "Billed by"; a store bill is not treated as prepaid.',
        'A store bottle goes Generated -> In stock (when scanned in) -> Sold. A sold bottle can never be billed again or counted in the weekly audit. MFG date is the day the barcode was generated and EXP is 4 years later.',
        'Store cleaning and the store pantry are managed in the Offices section: the store is an office of kind "store" and Store > Cleaning & Pantry opens that same page. Claude checks the cleaning photos first; pantry counts below the minimum go on the shopping list.',
    ];
    try { foreach ($facts as $f) brain_fact_add($pdo, 'internal', 'store', $f, 'Store module', ['source' => 'system', 'no_conflict' => 1]); @file_put_contents($flag, '1'); }
    catch (Throwable $e) { error_log('st_brain_seed: ' . $e->getMessage()); }
}

/* ============================== Shopify product sync (button + every day by itself) ============================== */

/** All active products from every connected Shopify store: [[shopify_id, title, image]] or ['error' => text]. Retries a busy (429) / failing page. */
function st_shopify_products(): array
{
    $out = [];
    foreach (shopify_stores() as $key => $store) {
        if (empty($store['client_id']) || strpos((string)$store['client_id'], 'PASTE_') !== false) continue;
        $tok = shopify_get_token($key, $store);
        if (is_array($tok)) return ['error' => $tok['error'] ?? 'Shopify sign-in failed.'];
        $url = 'https://' . $store['domain'] . '/admin/api/2024-10/products.json?' . http_build_query(['limit' => 250, 'status' => 'active', 'fields' => 'id,title,image']);
        for ($page = 0; $url && $page < 60; $page++) {
            $body = null; $code = 0; $next = null;
            for ($try = 0; $try < 3; $try++) {          // a busy Shopify (429) or a hiccup (5xx) gets two more tries
                $next = null; $wait = 0;
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $tok, 'Accept: application/json'],
                    CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$next, &$wait) {
                        if (stripos($h, 'link:') === 0 && preg_match('/<([^>]+)>;\s*rel="next"/i', $h, $m)) $next = $m[1];
                        if (stripos($h, 'retry-after:') === 0) $wait = (int)trim(substr($h, 12));
                        return strlen($h);
                    }]);
                $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($body !== false && $code === 200) break;
                if ($try < 2) sleep(max(1, min(5, $wait ?: ($try + 1) * 2)));
            }
            if ($body === false || $code !== 200) return ['error' => 'Shopify answered HTTP ' . $code . ' while loading products (page ' . ($page + 1) . ').'];
            foreach ((array)(json_decode($body, true)['products'] ?? []) as $p) $out[(string)$p['id']] = [(string)$p['id'], (string)$p['title'], (string)($p['image']['src'] ?? '')];
            $url = $next;
        }
    }
    return array_values($out);
}

/**
 * Bring st_products in line with Shopify. New products appear, renamed ones are renamed, a product that came back is shown again,
 * a product gone from Shopify is only HIDDEN (its bottles keep their history). SAFETY: if Shopify suddenly returns far fewer
 * products than we know (a partial answer), nothing is hidden and a warning is kept — a bad answer can never empty the list.
 * $list lets a test pass the products in; normally it is fetched.
 */
function st_sync_run(PDO $pdo, string $by = 'button', ?array $list = null): array
{
    if (!ensure_st_tables($pdo)) return ['ok' => false, 'message' => 'The Store tables are not ready.'];
    $list = $list ?? st_shopify_products();
    $rec = function (array $r) use ($pdo) { if (function_exists('setting_set')) { setting_set($pdo, 'st_sync_at', date('Y-m-d H:i:s')); setting_set($pdo, 'st_sync_ok', $r['ok'] ? '1' : '0'); setting_set($pdo, 'st_sync_msg', $r['message']); } return $r; };
    if (isset($list['error'])) return $rec(['ok' => false, 'message' => $list['error']]);
    if (!$list) return $rec(['ok' => false, 'message' => 'Shopify returned no products. Check the store connection (nothing was changed).']);
    $known = (int)$pdo->query("SELECT COUNT(*) FROM st_products WHERE active = 1")->fetchColumn();
    $now = date('Y-m-d H:i:s'); $tag = bin2hex(random_bytes(6));      // every product seen in THIS run is stamped with the tag: no clock tricks
    $up = $pdo->prepare("INSERT INTO st_products (shopify_id, title, image, active, synced_at, run_tag) VALUES (?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), image = VALUES(image), active = 1, synced_at = VALUES(synced_at), run_tag = VALUES(run_tag)");
    $pdo->beginTransaction();
    foreach ($list as $p) $up->execute([$p[0], mb_substr($p[1], 0, 255), mb_substr($p[2], 0, 500), $now, $tag]);
    $hidden = 0; $warn = '';
    if ($known >= 10 && count($list) < $known * 0.5) $warn = ' WARNING: Shopify returned only ' . count($list) . ' products but we knew ' . $known . ', so nothing was hidden.';
    else $hidden = $pdo->exec("UPDATE st_products SET active = 0 WHERE active = 1 AND run_tag <> " . $pdo->quote($tag));
    $pdo->commit();
    return $rec(['ok' => true, 'count' => count($list), 'hidden' => (int)$hidden, 'message' => count($list) . ' products synced from Shopify' . ($hidden ? ', ' . $hidden . ' no longer on Shopify (hidden)' : '') . '.' . $warn]);
}

/**
 * Once a day, by itself (no cron needed): the first request after 5 AM claims the day and syncs AFTER its own answer has gone out, so
 * nobody waits. A failed try is repeated at most every 30 minutes until it works. Shown on the Inventory page ("Last synced ...").
 */
function st_daily_sync(PDO $pdo): void
{
    $day = sys_get_temp_dir() . '/hrms_st_sync_day'; $try = sys_get_temp_dir() . '/hrms_st_sync_try';
    $today = date('Y-m-d');
    if ((int)date('G') < 5 || @file_get_contents($day) === $today) return;
    if (is_file($try) && time() - (int)@filemtime($try) < 1800) return;
    @file_put_contents($try, (string)time());                 // claim: only one request does the work
    register_shutdown_function(function () use ($pdo, $day, $today) {
        if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true); @set_time_limit(180);
        try { $r = st_sync_run($pdo, 'auto'); if (!empty($r['ok'])) @file_put_contents($day, $today); else error_log('st_daily_sync: ' . $r['message']); $o = st_online_sync($pdo, 90); if (empty($o['ok'])) error_log('st_daily_sync ranking: ' . $o['message']); }
        catch (Throwable $e) { error_log('st_daily_sync: ' . $e->getMessage()); }
    });
}

/** Later additions, made once on existing installs (never touches data): label print tracking + the order list tables. */
function st_migrate(PDO $pdo): void
{
    static $ran = false; if ($ran) return; $ran = true;
    $flag = sys_get_temp_dir() . '/hrms_st_mig_v10';
    if (is_file($flag)) return;
    try {
        foreach (['printed_at' => 'DATETIME NULL', 'print_count' => 'INT NOT NULL DEFAULT 0'] as $c => $def)
            if (!$pdo->query("SHOW COLUMNS FROM st_bottles LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE st_bottles ADD COLUMN $c $def");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_orders (
            order_id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(10) NOT NULL DEFAULT 'draft', created_at DATETIME NOT NULL, created_by VARCHAR(80) NOT NULL DEFAULT '',
            ordered_at DATETIME NULL, ordered_by VARCHAR(80) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '', KEY idx_st_ord_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_order_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, product_id INT NOT NULL, ml INT NOT NULL, qty INT NOT NULL, sug_qty INT NULL,
            source VARCHAR(8) NOT NULL DEFAULT 'manual', added_at DATETIME NOT NULL, UNIQUE KEY uq_st_oi (order_id, product_id, ml)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_order_log (
            log_id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, at DATETIME NOT NULL, who VARCHAR(80) NOT NULL DEFAULT '', action VARCHAR(10) NOT NULL,
            product_id INT NULL, ml INT NULL, from_qty INT NULL, to_qty INT NULL, KEY idx_st_ol (order_id, at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_cs_settings (skey VARCHAR(30) NOT NULL PRIMARY KEY, svalue MEDIUMTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_bill_people (
            person_id INT AUTO_INCREMENT PRIMARY KEY, bill_id INT NOT NULL, person_no TINYINT NOT NULL DEFAULT 1, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(20) NOT NULL DEFAULT '',
            token VARCHAR(24) NOT NULL, declined TINYINT NOT NULL DEFAULT 0, reason VARCHAR(60) NOT NULL DEFAULT '', returning_c TINYINT NOT NULL DEFAULT 0, visit_no INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_st_bp_token (token), KEY idx_st_bp_bill (bill_id), KEY idx_st_bp_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_bill_events (
            event_id INT AUTO_INCREMENT PRIMARY KEY, person_id INT NOT NULL, bill_id INT NOT NULL, kind VARCHAR(12) NOT NULL, at DATETIME NOT NULL, who VARCHAR(80) NOT NULL DEFAULT '',
            KEY idx_st_be_person (person_id, kind), KEY idx_st_be_at (at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_online_sales (
            day DATE NOT NULL, shopify_id BIGINT NOT NULL, qty INT NOT NULL DEFAULT 0, PRIMARY KEY (day, shopify_id), KEY idx_st_os_id (shopify_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("UPDATE st_bills SET biz_date = DATE(DATE_SUB(created_at, INTERVAL " . (int)ST_DAY_START_HOUR . " HOUR))");      // the new 3 AM day rule, applied once to bills made before
        // CHAINS sold in the store: the chain models (photo, MRP, price), one barcode per piece, and the GST invoice a chain bill gets
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_chains (
            chain_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, mrp DECIMAL(10,2) NOT NULL DEFAULT 0, price DECIMAL(10,2) NOT NULL DEFAULT 0, photo MEDIUMTEXT NULL,
            active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, created_by VARCHAR(80) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_chain_pieces (
            piece_id INT AUTO_INCREMENT PRIMARY KEY, chain_id INT NOT NULL, code VARCHAR(12) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'generated', batch VARCHAR(20) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL, printed_at DATETIME NULL, print_count INT NOT NULL DEFAULT 0, stocked_at DATETIME NULL, sold_at DATETIME NULL, bill_id INT NULL,
            UNIQUE KEY uq_st_cp_code (code), KEY idx_st_cp_chain (chain_id, status), KEY idx_st_cp_bill (bill_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['chain_amt' => 'DECIMAL(10,2) NOT NULL DEFAULT 0', 'chain_name' => "VARCHAR(120) NOT NULL DEFAULT ''", 'inv_id' => 'INT NOT NULL DEFAULT 0', 'inv_no' => "VARCHAR(20) NOT NULL DEFAULT ''", 'inv_token' => "VARCHAR(40) NOT NULL DEFAULT ''", 'inv_err' => "VARCHAR(200) NOT NULL DEFAULT ''"] as $c => $def)
            if (!$pdo->query("SHOW COLUMNS FROM st_bills LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE st_bills ADD COLUMN $c $def");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_bday_gifts (
            gift_id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '', gift_year SMALLINT NOT NULL, bill_id INT NOT NULL, bottle_id INT NOT NULL,
            given_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, UNIQUE KEY uq_st_bg (phone, gift_year), KEY idx_st_bg_bill (bill_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_bday_vouchers (
            voucher_id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(12) NOT NULL, phone VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '', bday CHAR(5) NOT NULL, bday_year SMALLINT NOT NULL,
            valid_from DATE NOT NULL, valid_to DATE NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'active', wa_status VARCHAR(10) NOT NULL DEFAULT 'pending', wa_note VARCHAR(200) NOT NULL DEFAULT '', wa_at DATETIME NULL,
            redeemed_at DATETIME NULL, redeemed_bill_id INT NULL, redeemed_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
            UNIQUE KEY uq_st_bv_code (code), UNIQUE KEY uq_st_bv_phone_year (phone, bday_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$pdo->query("SHOW COLUMNS FROM st_bill_people LIKE 'bday'")->fetch()) $pdo->exec("ALTER TABLE st_bill_people ADD COLUMN bday CHAR(5) NOT NULL DEFAULT ''");      // customer birthday as MM-DD
        foreach (['source' => "VARCHAR(20) NOT NULL DEFAULT ''", 'source_note' => "VARCHAR(120) NOT NULL DEFAULT ''", 'area' => "VARCHAR(80) NOT NULL DEFAULT ''"] as $c => $def)
            if (!$pdo->query("SHOW COLUMNS FROM st_bills LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE st_bills ADD COLUMN $c $def");
        @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('st_migrate: ' . $e->getMessage()); }
}

/** First barcode number of a chain piece: 8 digits, so it can never be mistaken for a 6-digit bottle code. */
const ST_CHAIN_CODE_BASE = 20000000;

/** Loads the GST invoice engine (wch.php) the same way the WhatsApp parts are loaded. */
function st_wch_load(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        st_wa_load();
        if (!function_exists('wch_tables') && is_file(__DIR__ . '/wch.php')) require_once __DIR__ . '/wch.php';      // the engine of the online chain bills (normally only loaded for wch.* / wa.* calls)
        if (function_exists('wa_tables')) wa_tables($pdo);
        if (function_exists('wch_deps')) wch_deps($pdo);
        $ok = function_exists('wch_tables') && wch_tables($pdo) && function_exists('wch_bill_make') && function_exists('wch_billing_gate');
    } catch (Throwable $e) { error_log('st_wch_load: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

/** The chain share (%) the online chain flow uses; only shown as a hint on the billing screen. */
function st_chain_pct(PDO $pdo): float
{
    try { if (st_wch_load($pdo) && function_exists('wch_cfg')) return (float)wch_cfg($pdo)['wch_split_pct']; } catch (Throwable $e) { }
    return 50.0;
}

/** Why a chain bill cannot be invoiced right now ('' = fine). Checked BEFORE the bill is saved, so a chain is never sold without a GST invoice. */
function st_chain_gate(PDO $pdo): string
{
    if (!st_wch_load($pdo)) return 'The GST invoice module is not available on this server.';
    $g = wch_billing_gate($pdo);
    if (empty($g['ok'])) return 'The GST invoice details are incomplete (Learn & Setup → Cart setup → Billing: ' . implode(', ', array_slice((array)($g['missing'] ?? []), 0, 4)) . '). Fill them in, then bill the chain.';
    return '';
}

/**
 * Splits a chain bill: the chain gets its share (default 50% of the total, the online chain rule), the perfumes get the REST (shared by ml). The total never changes.
 * @param array $bottles sale bottles ['ml'=>int,...]  @return array ['chain_p','perfume_p','parts'=>[paise per bottle, same order]] or ['error'=>...]
 */
function st_chain_share(float $total, float $pct): float
{
    return (float)round($total * $pct / 100);      // whole rupees, the same rule the billing screen uses
}
function st_chain_split(float $total, float $chainPrice, array $bottles, float $mrp = 0): array
{
    $totalP = (int)round($total * 100); $chainP = (int)round($chainPrice * 100);
    if ($chainP <= 0) return ['error' => 'The chain share of this bill is ₹0. Add more perfume.'];
    if ($mrp > 0 && $chainP > (int)round($mrp * 100)) return ['error' => 'The chain share of this bill is ₹' . number_format($chainPrice) . ' but this chain’s MRP is only ₹' . number_format($mrp) . '. Choose a chain with a higher MRP (a chain is never billed above its MRP).'];
    if ($chainP >= $totalP) return ['error' => 'The chain share (₹' . number_format($chainPrice) . ') is not less than the bill (₹' . number_format($total) . '). Add more perfume.'];
    if (!$bottles) return ['error' => 'A chain bill needs at least one perfume bottle.'];
    $perfP = $totalP - $chainP;
    $parts = wch_split_exact($perfP, array_map(fn($b) => max(1, (int)$b['ml']), $bottles));
    foreach ($parts as $a) if ($a <= 0) return ['error' => 'The perfume share is too small to split. Add more perfume or choose a cheaper chain.'];
    return ['chain_p' => $chainP, 'perfume_p' => $perfP, 'parts' => $parts];
}

/**
 * Makes the numbered GST invoice (TM/26-27/00001 series, the SAME engine and counter as the online orders) for a store bill that has a chain:
 * a Perfumes section (HSN 3303) and a Chain section (HSN 7117), CGST+SGST (or IGST). The store is in the supplier state, so it is CGST+SGST.
 * Returns ['ok'=>bool,'error'=>string,'inv'=>row]. Safe to call again: one invoice per bill.
 */
function st_chain_invoice(PDO $pdo, int $billId): array
{
    $e = function (string $m) { return ['ok' => false, 'error' => $m, 'inv' => null]; };
    $gate = st_chain_gate($pdo); if ($gate !== '') return $e($gate);
    $bq = $pdo->prepare("SELECT * FROM st_bills WHERE bill_id = ?"); $bq->execute([$billId]); $bill = $bq->fetch(); if (!$bill) return $e('Bill not found.');
    if ((int)$bill['inv_id'] > 0) { $r = wch_inv_row($pdo, (int)$bill['inv_id']); if ($r) return ['ok' => true, 'error' => '', 'inv' => $r]; }
    $cq = $pdo->prepare("SELECT p.*, c.name cname FROM st_chain_pieces p JOIN st_chains c ON c.chain_id = p.chain_id WHERE p.bill_id = ? LIMIT 1"); $cq->execute([$billId]); $piece = $cq->fetch();
    if (!$piece) return $e('This bill has no chain.');
    $bo = $pdo->prepare("SELECT i.ml, b.product_id, p.title FROM st_bill_items i JOIN st_bottles b ON b.bottle_id = i.bottle_id JOIN st_products p ON p.product_id = b.product_id WHERE i.bill_id = ? AND i.kind = 'sale' ORDER BY i.item_id"); $bo->execute([$billId]); $bots = $bo->fetchAll();
    $sp = st_chain_split((float)$bill['total'], (float)$bill['chain_amt'], $bots); if (isset($sp['error'])) return $e($sp['error']);
    $lines = [];
    foreach ($bots as $i => $bt) $lines[] = ['kind' => 'perfume', 'vid' => (int)$bt['product_id'] * 1000 + (int)$bt['ml'], 'sku' => (string)(int)$bt['ml'] . 'ML', 'short' => trim(preg_replace('/\s*\d+\s*ml\b\.?/i', '', (string)$bt['title'])), 'title' => (string)$bt['title'], 'ml' => (int)$bt['ml'], 'amount_p' => (int)$sp['parts'][$i]];
    $lines[] = ['kind' => 'chain', 'vid' => 900000000 + (int)$piece['chain_id'], 'sku' => 'CH' . (int)$piece['chain_id'], 'short' => (string)$piece['cname'], 'title' => (string)$piece['cname'], 'ml' => null, 'amount_p' => $sp['chain_p']];
    $totalP = (int)round(((float)$bill['total']) * 100);
    $plan = ['lines' => $lines, 'sum_p' => $totalP];
    $b = wch_b_get($pdo); $nm = trim((string)$bill['customer_name']) !== '' ? trim((string)$bill['customer_name']) : 'Walk-in customer';
    $ship = ['name' => $nm, 'address1' => '', 'address2' => '', 'city' => '', 'zip' => '', 'province' => (string)$b['state'], 'province_code' => (string)$b['state_code']];
    $now = date('Y-m-d H:i:s'); $ph = substr(preg_replace('/\D/', '', (string)$bill['customer_phone']), -12);
    try {
        $x = $pdo->prepare("SELECT * FROM wch_cart WHERE order_sid = ? AND order_name = ? ORDER BY id DESC LIMIT 1"); $x->execute(['ST' . $billId, (string)$bill['bill_no']]); $cart = $x->fetch();
        if (!$cart) {
            $pdo->prepare("INSERT INTO wch_cart (phone, emp_id, status, total_p, ml, lines_json, plan_json, order_sid, order_name, order_mode, order_paid, order_at, order_total_p, ship_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$ph, (int)$bill['emp_id'], 'ordered', $totalP, (int)$bill['total_ml'], '[]', json_encode($plan, JSON_UNESCAPED_UNICODE), 'ST' . $billId, (string)$bill['bill_no'], 'prepaid', 1, $now, $totalP, json_encode($ship, JSON_UNESCAPED_UNICODE), $now, $now]);
            $x = $pdo->prepare("SELECT * FROM wch_cart WHERE id = ?"); $x->execute([(int)$pdo->lastInsertId()]); $cart = $x->fetch();
        }
        [$ok, $err, , $inv] = wch_bill_make($pdo, $cart, '');
        if (!$ok || !$inv) { $pdo->prepare("UPDATE st_bills SET inv_err = ? WHERE bill_id = ?")->execute([mb_substr((string)$err, 0, 190), $billId]); return $e((string)$err ?: 'The invoice could not be made.'); }
        $pdo->prepare("UPDATE st_bills SET inv_id = ?, inv_no = ?, inv_token = ?, inv_err = '' WHERE bill_id = ?")->execute([(int)$inv['id'], (string)$inv['inv_no'], (string)$inv['token'], $billId]);
        return ['ok' => true, 'error' => '', 'inv' => $inv];
    } catch (Throwable $ex) { error_log('st_chain_invoice: ' . $ex->getMessage()); return $e('The invoice could not be made: ' . mb_substr($ex->getMessage(), 0, 120)); }
}

/** A cancelled chain bill keeps its invoice number (numbers are never reused or deleted); the invoice is marked CANCELLED. */
function st_chain_invoice_cancel(PDO $pdo, int $invId, string $billNo): void
{
    if ($invId <= 0 || !st_wch_load($pdo)) return;
    try {
        $r = wch_inv_row($pdo, $invId); if (!$r) return; $d = wch_arr($r['data_json']); $d['cancelled'] = date('Y-m-d H:i'); $d['cancelled_note'] = 'Store bill ' . $billNo . ' was cancelled';
        $pdo->prepare("UPDATE wch_inv SET data_json = ? WHERE id = ?")->execute([wch_json($d), $invId]);
    } catch (Throwable $e) { error_log('st_chain_invoice_cancel: ' . $e->getMessage()); }
}

/** The one open DRAFT order list (made on first use). */
function st_draft_id(PDO $pdo, string $who): int
{
    $id = (int)$pdo->query("SELECT order_id FROM st_orders WHERE status = 'draft' ORDER BY order_id LIMIT 1")->fetchColumn();
    if ($id) return $id;
    $pdo->prepare("INSERT INTO st_orders (status, created_at, created_by) VALUES ('draft', ?, ?)")->execute([date('Y-m-d H:i:s'), $who]);
    return (int)$pdo->lastInsertId();
}

function st_order_log(PDO $pdo, int $orderId, string $who, string $action, ?int $pid = null, ?int $ml = null, ?int $from = null, ?int $to = null): void
{
    $pdo->prepare("INSERT INTO st_order_log (order_id, at, who, action, product_id, ml, from_qty, to_qty) VALUES (?,?,?,?,?,?,?,?)")->execute([$orderId, date('Y-m-d H:i:s'), mb_substr($who, 0, 80), $action, $pid, $ml, $from, $to]);
}

/**
 * Orders that were PLACED and are not finished: for each item how many are still to come. An item is received as the barcodes are made:
 * bottles of that perfume + size created after the order date count against it. When everything is received the order closes by itself.
 * @return array order_id => ['order_id','ordered_at','ordered_by','items'=>[['product_id','ml','qty','received','remaining','title']]]
 */
function st_open_orders(PDO $pdo): array
{
    $out = [];
    $orders = $pdo->query("SELECT * FROM st_orders WHERE status = 'ordered' ORDER BY ordered_at")->fetchAll();
    foreach ($orders as $o) {
        $it = $pdo->prepare("SELECT i.*, p.title FROM st_order_items i JOIN st_products p ON p.product_id = i.product_id WHERE i.order_id = ? ORDER BY p.title, i.ml");
        $it->execute([(int)$o['order_id']]);
        $made = $pdo->prepare("SELECT COUNT(*) FROM st_bottles WHERE product_id = ? AND ml = ? AND kind = 'sale' AND status <> 'void' AND created_at >= ?");
        $items = []; $left = 0;
        foreach ($it->fetchAll() as $r) {
            $made->execute([(int)$r['product_id'], (int)$r['ml'], $o['ordered_at']]);
            $rec = min((int)$r['qty'], (int)$made->fetchColumn());
            $items[] = ['product_id' => (int)$r['product_id'], 'ml' => (int)$r['ml'], 'qty' => (int)$r['qty'], 'received' => $rec, 'remaining' => (int)$r['qty'] - $rec, 'title' => $r['title']];
            $left += (int)$r['qty'] - $rec;
        }
        if (!$left) { $pdo->prepare("UPDATE st_orders SET status = 'done' WHERE order_id = ?")->execute([(int)$o['order_id']]); st_order_log($pdo, (int)$o['order_id'], 'system', 'done'); continue; }
        $out[(int)$o['order_id']] = ['order_id' => (int)$o['order_id'], 'ordered_at' => $o['ordered_at'], 'ordered_by' => $o['ordered_by'], 'items' => $items];
    }
    return $out;
}

/** 'pid|ml' => bottles still to come from placed orders */
function st_on_order_map(PDO $pdo): array
{
    $m = [];
    foreach (st_open_orders($pdo) as $o) foreach ($o['items'] as $i) { $k = $i['product_id'] . '|' . $i['ml']; $m[$k] = ($m[$k] ?? 0) + $i['remaining']; }
    return $m;
}

/** Order cycle: how often we order (days), when we last ordered and when the next order is due. */
function st_order_cycle(PDO $pdo): array
{
    $days = max(1, min(30, (int)setting_get($pdo, 'st_order_cycle', 2)));
    $last = $pdo->query("SELECT MAX(ordered_at) FROM st_orders WHERE status IN ('ordered', 'done')")->fetchColumn() ?: null;
    $due = $last ? date('Y-m-d', strtotime($last . ' +' . $days . ' day')) : date('Y-m-d');
    $today = date('Y-m-d');
    return ['cycle_days' => $days, 'last_ordered_at' => $last, 'next_due' => $due, 'due_in_days' => (int)round((strtotime($due) - strtotime($today)) / 86400)];
}

/* ============================== sales ranking: what sells most (Make list: best sellers first) ============================== */

/** A perfume's name without the shop suffix / size, lower case: the fallback way to match an old POS product that has no Shopify id. */
function st_norm_title($t): string
{
    $t = preg_replace('/\s*[|｜].*$/u', '', (string)$t); $t = preg_replace('/\s*\d+\s*ml\b\.?/i', '', $t);
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $t), " \t-–—|·,"));       // 'Creed Aventus - 20 ml' -> 'creed aventus'
}

/**
 * Orders from Shopify for the last $days days, counted per product per day: [[day, shopify_product_id, qty]] or ['error' => text].
 * Cancelled orders are left out. (Needs the same Shopify access the Orders page already uses.)
 */
function st_online_fetch(int $days = 90): array
{
    $acc = [];
    foreach (shopify_stores() as $key => $store) {
        if (empty($store['client_id']) || strpos((string)$store['client_id'], 'PASTE_') !== false) continue;
        $tok = shopify_get_token($key, $store);
        if (is_array($tok)) return ['error' => $tok['error'] ?? 'Shopify sign-in failed.'];
        $url = 'https://' . $store['domain'] . '/admin/api/2024-10/orders.json?' . http_build_query(['status' => 'any', 'limit' => 250, 'created_at_min' => date('c', strtotime('-' . $days . ' day')), 'fields' => 'id,created_at,cancelled_at,line_items']);
        for ($page = 0; $url && $page < 80; $page++) {
            $body = null; $code = 0; $next = null;
            for ($try = 0; $try < 3; $try++) {
                $next = null; $wait = 0;
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $tok, 'Accept: application/json'],
                    CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$next, &$wait) {
                        if (stripos($h, 'link:') === 0 && preg_match('/<([^>]+)>;\s*rel="next"/i', $h, $m)) $next = $m[1];
                        if (stripos($h, 'retry-after:') === 0) $wait = (int)trim(substr($h, 12));
                        return strlen($h);
                    }]);
                $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($body !== false && $code === 200) break;
                if ($try < 2) sleep(max(1, min(5, $wait ?: ($try + 1) * 2)));
            }
            if ($body === false || $code !== 200) return ['error' => 'Shopify answered HTTP ' . $code . ' while loading orders (page ' . ($page + 1) . ').'];
            foreach ((array)(json_decode($body, true)['orders'] ?? []) as $o) {
                if (!empty($o['cancelled_at'])) continue;
                $day = substr((string)$o['created_at'], 0, 10);
                foreach ((array)($o['line_items'] ?? []) as $li) { $pid = (string)($li['product_id'] ?? ''); if ($pid === '') continue; $acc[$day . '|' . $pid] = ($acc[$day . '|' . $pid] ?? 0) + (int)($li['quantity'] ?? 0); }
            }
            $url = $next;
        }
    }
    $rows = []; foreach ($acc as $k => $q) { [$d, $p] = explode('|', $k); $rows[] = [$d, $p, $q]; }
    return $rows;
}

/** Replace the cached online sales (window = $days) with fresh numbers. $rows lets a test pass the lines in. Nothing is changed when Shopify fails or answers empty. */
function st_online_sync(PDO $pdo, int $days = 90, ?array $rows = null): array
{
    if (!ensure_st_tables($pdo)) return ['ok' => false, 'message' => 'The Store tables are not ready.'];
    $rows = $rows ?? st_online_fetch($days);
    $rec = function (array $r) { if (function_exists('setting_set')) { global $pdo; setting_set($pdo, 'st_rank_at', date('Y-m-d H:i:s')); setting_set($pdo, 'st_rank_ok', $r['ok'] ? '1' : '0'); setting_set($pdo, 'st_rank_msg', $r['message']); } return $r; };
    if (isset($rows['error'])) return $rec(['ok' => false, 'message' => $rows['error']]);
    if (!$rows) return $rec(['ok' => false, 'message' => 'Shopify returned no orders for the last ' . $days . ' days (the ranking was not changed).']);
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM st_online_sales WHERE day >= ?")->execute([date('Y-m-d', strtotime('-' . $days . ' day'))]);
    foreach (array_chunk($rows, 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?,?,?)')); $args = [];
        foreach ($chunk as [$d, $p, $q]) array_push($args, $d, $p, $q);
        $pdo->prepare("INSERT INTO st_online_sales (day, shopify_id, qty) VALUES $ph ON DUPLICATE KEY UPDATE qty = VALUES(qty)")->execute($args);
    }
    $pdo->commit();
    return $rec(['ok' => true, 'message' => 'Online sales ranking updated from ' . count($rows) . ' product-days of Shopify orders.']);
}

/**
 * What sells, per perfume, from ALL our sales: this store's own bills (counted extra), the old POS, and the online (Shopify) orders.
 * Recent sales weigh more (30 days 60%, 90 days 40%). Badges: top (best 12), rising (last 14 days clearly above the 14 before), new (first sale in the last 30 days),
 * steady, slow (sold before, little lately), none (nothing in 90 days).
 * @return array product_id => ['score','q30','q90','rank','badge','src'=>['store','pos','online']]
 */
/** The ranking changes slowly (it reads weeks of sales): keep the answer for a few minutes instead of recounting thousands of order lines on every page open. */
function st_rank_cache_file(): string { return sys_get_temp_dir() . '/hrms_st_rank_' . substr(md5(__DIR__), 0, 8) . '.json'; }
function st_rank_cache_clear(): void { @unlink(st_rank_cache_file()); }
function st_rank_cached(PDO $pdo, int $ttl = 180): array
{
    $f = st_rank_cache_file();
    if (is_file($f) && time() - (int)@filemtime($f) < $ttl) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) return $j; }
    $r = st_rank_calc($pdo);
    @file_put_contents($f, json_encode($r), LOCK_EX);
    return $r;
}

function st_rank_calc(PDO $pdo): array
{
    $day = fn($n) => date('Y-m-d', strtotime('-' . $n . ' day'));
    $d14 = $day(14); $d28 = $day(28); $d30 = $day(30); $d90 = $day(90); $d180 = $day(180);
    $bySid = []; $byTitle = []; $acc = [];
    foreach ($pdo->query("SELECT product_id, shopify_id, title FROM st_products WHERE active = 1")->fetchAll() as $r) {
        $bySid[(string)$r['shopify_id']] = (int)$r['product_id']; $byTitle[st_norm_title($r['title'])] = $byTitle[st_norm_title($r['title'])] ?? (int)$r['product_id'];
        $acc[(int)$r['product_id']] = ['q30' => 0, 'q90' => 0, 't14' => 0, 'p14' => 0, 'first' => null, 'src' => ['store' => 0, 'pos' => 0, 'online' => 0], 'score' => 0.0];
    }
    $put = function (int $pid, string $src, float $w, $q30, $q90, $t14, $p14, $first) use (&$acc) {
        if (!isset($acc[$pid])) return;
        $a = &$acc[$pid]; $q30 = (int)$q30; $q90 = (int)$q90;
        $a['q30'] += $q30; $a['q90'] += $q90; $a['t14'] += (int)$t14; $a['p14'] += (int)$p14; $a['src'][$src] += $q90;
        $a['score'] += $w * (0.6 * ($q30 / 30) + 0.4 * ($q90 / 90));
        if ($first && ($a['first'] === null || $first < $a['first'])) $a['first'] = $first;
    };
    $agg = "SUM(%s >= ?) q30, SUM(%s >= ?) q90, SUM(%s >= ?) t14, SUM(%s >= ? AND %s < ?) p14, MIN(%s) first";
    try {       // this store: every bottle on an OK bill is one sale (counted 1.5x: our own counter is the demand we plan for)
        $st = $pdo->prepare("SELECT b.product_id pid, SUM(bl.biz_date >= ?) q30, SUM(bl.biz_date >= ?) q90, SUM(bl.biz_date >= ?) t14, SUM(bl.biz_date >= ? AND bl.biz_date < ?) p14, MIN(bl.biz_date) first
            FROM st_bill_items i JOIN st_bills bl ON bl.bill_id = i.bill_id AND bl.status = 'ok' JOIN st_bottles b ON b.bottle_id = i.bottle_id WHERE i.kind = 'sale' AND bl.biz_date >= ? GROUP BY b.product_id");
        $st->execute([$d30, $d90, $d14, $d28, $d14, $d180]);
        foreach ($st->fetchAll() as $r) $put((int)$r['pid'], 'store', 1.5, $r['q30'], $r['q90'], $r['t14'], $r['p14'], $r['first']);
    } catch (Throwable $e) { error_log('st_rank store: ' . $e->getMessage()); }
    try {       // the old POS (its tables live in this database as pos_*; each POS product knows its Shopify id)
        $st = $pdo->prepare("SELECT pp.shopify_product_id sid, MAX(oi.title) ttl, SUM(oi.qty * (o.created_at >= ?)) q30, SUM(oi.qty * (o.created_at >= ?)) q90, SUM(oi.qty * (o.created_at >= ?)) t14, SUM(oi.qty * (o.created_at >= ? AND o.created_at < ?)) p14, DATE(MIN(o.created_at)) first
            FROM pos_order_items oi JOIN pos_orders o ON o.id = oi.order_id LEFT JOIN pos_products pp ON pp.id = oi.product_id WHERE o.created_at >= ? GROUP BY COALESCE(CAST(pp.shopify_product_id AS CHAR), CONCAT('t:', oi.title))");
        $st->execute([$d30, $d90, $d14, $d28, $d14, $d180]);
        foreach ($st->fetchAll() as $r) { $pid = $bySid[(string)$r['sid']] ?? ($byTitle[st_norm_title($r['ttl'])] ?? 0); if ($pid) $put($pid, 'pos', 1.0, $r['q30'], $r['q90'], $r['t14'], $r['p14'], $r['first']); }
    } catch (Throwable $e) { /* no old POS tables here: fine */ }
    try {       // online (Shopify) lines, cached by st_online_sync
        $st = $pdo->prepare("SELECT shopify_id sid, SUM(qty * (day >= ?)) q30, SUM(qty * (day >= ?)) q90, SUM(qty * (day >= ?)) t14, SUM(qty * (day >= ? AND day < ?)) p14, MIN(day) first FROM st_online_sales WHERE day >= ? GROUP BY shopify_id");
        $st->execute([$d30, $d90, $d14, $d28, $d14, $d180]);
        foreach ($st->fetchAll() as $r) { $pid = $bySid[(string)$r['sid']] ?? 0; if ($pid) $put($pid, 'online', 1.0, $r['q30'], $r['q90'], $r['t14'], $r['p14'], $r['first']); }
    } catch (Throwable $e) { /* not synced yet */ }
    uasort($acc, fn($a, $b) => $b['score'] <=> $a['score']);
    $rank = 0;
    foreach ($acc as $pid => &$a) {
        $a['score'] = round($a['score'] * 30, 2);                    // bottles per 30 days, all channels
        if ($a['score'] <= 0 && $a['q90'] <= 0) { $a['badge'] = 'none'; $a['rank'] = 0; continue; }
        $a['rank'] = ++$rank;
        $isNew = $a['first'] !== null && $a['first'] >= $d30 && $a['q30'] >= 3;
        $a['badge'] = $rank <= 12 ? 'top' : ($isNew ? 'new' : ($a['t14'] >= 3 && $a['t14'] >= 1.5 * max(1, $a['p14']) ? 'rising' : ($a['q30'] === 0 || ($a['p14'] >= 4 && $a['t14'] <= 0.5 * $a['p14']) ? 'slow' : 'steady')));
        if ($rank <= 12 && $isNew) $a['badge'] = 'top';
    }
    unset($a);
    return $acc;
}

/* ============================== customers: numbers, the bill page, contacts, community, review ============================== */

/** A phone as digits with the country code: 10 digits (starting 6-9) = India (91 added); 12 digits starting 91 stays; anything else 11-15 digits is kept; otherwise '' (not valid). */
function st_phone_clean($p): string
{
    $d = preg_replace('/\D/', '', (string)$p); $d = ltrim($d, '0');
    if (strlen($d) === 10 && preg_match('/^[6-9]/', $d)) return '91' . $d;
    if (strlen($d) === 12 && strpos($d, '91') === 0 && preg_match('/^91[6-9]/', $d)) return $d;
    return (strlen($d) >= 11 && strlen($d) <= 15 && $d[0] !== '0') ? $d : '';
}

function st_cs_defaults(): array
{
    return ['store_name' => 'TM Perfume House', 'contacts' => [], 'community_link' => '', 'review_link' => '', 'thanks_text' => 'Thank you for shopping with us! Save our contacts, join our community and tell us how we did.', 'require_phone' => 1, 'skip_returning' => 1, 'auto_wa' => 1];
}
/** Customer-follow-up settings: who we are (numbers), the community link, the Google review link, the thank-you line. */
function st_cs_get(PDO $pdo): array
{
    $d = st_cs_defaults();
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'cs'")->fetchColumn(); $j = $v ? json_decode($v, true) : null; if (is_array($j)) $d = array_merge($d, $j); } catch (Throwable $e) { }
    $d['msgs'] = st_msgs_get($pdo);
    return $d;
}
function st_cs_save(PDO $pdo, array $in): array
{
    $url = function ($u) { $u = trim((string)$u); return ($u === '' || preg_match('#^https?://[^\s]+$#i', $u)) ? mb_substr($u, 0, 500) : null; };
    $c = ['store_name' => mb_substr(trim((string)($in['store_name'] ?? '')), 0, 80) ?: 'TM Perfume House', 'contacts' => [], 'thanks_text' => mb_substr(trim((string)($in['thanks_text'] ?? '')), 0, 300), 'require_phone' => empty($in['require_phone']) ? 0 : 1, 'skip_returning' => array_key_exists('skip_returning', $in) && empty($in['skip_returning']) ? 0 : 1, 'auto_wa' => array_key_exists('auto_wa', $in) && empty($in['auto_wa']) ? 0 : 1];
    foreach (['community_link', 'review_link'] as $k) { $v = $url($in[$k] ?? ''); if ($v === null) return ['error' => 'The ' . ($k === 'community_link' ? 'community' : 'Google review') . ' link must start with https://']; $c[$k] = $v; }
    foreach ((array)($in['contacts'] ?? []) as $x) {
        $name = mb_substr(trim((string)($x['name'] ?? '')), 0, 60); $raw = trim((string)($x['phone'] ?? ''));
        if ($name === '' && $raw === '') continue;
        $ph = st_phone_clean($raw); if ($name === '' || $ph === '') return ['error' => 'Each contact needs a name and a valid mobile number (' . ($name ?: $raw) . ').'];
        $c['contacts'][] = ['name' => $name, 'phone' => $ph, 'role' => mb_substr(trim((string)($x['role'] ?? '')), 0, 40)];
        if (count($c['contacts']) >= 8) break;
    }
    $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('cs', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'settings' => $c];
}

/** The public address of a customer's bill page. */
function st_bill_link(string $token): string
{
    $base = defined('APP_URL') && APP_URL ? rtrim((string)APP_URL, '/') : ((!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/\\') : '/empmanagment';
    return $base . ($dir === '' || $dir === '.' ? '' : $dir) . '/store_bill.php?t=' . $token;
}
/** The wording the cashier sends BY HAND on web.whatsapp after a bill (editable in Store → Messages). {name} {store} {bill_no} {amount} {bill_link} {community_link} {review_link} {friends_link} fill themselves in. */
function st_msgs_legacy(): array      // earlier standard wordings: a saved copy of one of them is treated as "standard", so people get the newest standard
{
    return [md5("Hi {name}, thank you for shopping at {store}! Your bill {bill_no} (₹{amount}) is here: {bill_link}\nSave our contacts and join our community from the same page.\n\nCame with friends? Send them this link so they can save our contacts, join the community and review us too: {friends_link}"),
        md5("Welcome back, {name}! Thank you for shopping at {store} again. Your bill {bill_no} (₹{amount}) is here: {bill_link}\n\nCame with friends? Send them this link so they can save our contacts, join the community and review us too: {friends_link}"),
        md5("Hi {name}, thank you for shopping at {store}! 🙏\n\n🧾 Your bill {bill_no} (₹{amount}) — see it here:\n{bill_link}\n\n📞 Please save our numbers, so you never miss an offer:\n{contacts}\nSave all in one tap: {contacts_link}\n\n👥 Please join our community:\n{community_link}\n\n⭐ Please give us a 5-star review on Google:\n{review_link}\n\nCame with friends? Send them this: {friends_link}")];
}
function st_msgs_defaults(): array
{
    return [
        'thanks' => "Hi {name}, thank you for shopping at {store}! 🙏\n\n🧾 Your bill {bill_no} (₹{amount}) — see it here:\n{bill_link}\n\n📞 Save all our numbers in one tap (they come together as one contact file):\n{contacts_link}\n\n👥 Please join our community:\n{community_link}\n\n⭐ Please give us a 5-star review on Google:\n{review_link}\n\nCame with friends? Send them this: {friends_link}",
        'thanks_back' => "Welcome back, {name}! 🙏 Thank you for shopping at {store} again.\n\n🧾 Your bill {bill_no} (₹{amount}) — see it here:\n{bill_link}\n\nCame with friends? Send them this: {friends_link}",
        'contacts' => "Hi {name}! 📞 Please save our numbers so you never miss an offer — one tap adds all of them to your phone together:\n{contacts_link}",
    ];
}
function st_msgs_get(PDO $pdo): array
{
    $d = st_msgs_defaults(); $j = null;
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'msgs'")->fetchColumn(); $j = $v ? json_decode((string)$v, true) : null; } catch (Throwable $e) { }
    if (is_array($j)) foreach ($d as $k => $def) if (trim((string)($j[$k] ?? '')) !== '' && !in_array(md5((string)$j[$k]), st_msgs_legacy(), true)) $d[$k] = (string)$j[$k];
    return $d;
}
function st_msgs_save(PDO $pdo, array $in): array
{
    $cur = st_msgs_get($pdo); $def = st_msgs_defaults();
    if (!empty($in['reset'])) { $cur = $def; }
    else foreach (['thanks', 'thanks_back', 'contacts'] as $k) if (isset($in[$k])) {
        $t = trim(str_replace("\r", '', (string)$in[$k])); if ($t === '') return ['error' => 'A message cannot be empty.']; if (mb_strlen($t) > 900) return ['error' => 'A message is too long (900 letters at most).']; $cur[$k] = $t;
    }
    $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('msgs', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'msgs' => $cur, 'defaults' => $def];
}
/** The SHORT address of a customer's bill page (b.php in the main folder): https://domain/b.php?t=TOKEN + &c=1 community · &r=1 Google review · &s=1 save our numbers · &g=1 friends. Counts the same taps as the long address. */
function st_short_link(string $token, string $extra = ''): string
{
    $base = defined('APP_URL') && APP_URL ? rtrim((string)APP_URL, '/') : ((!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/\\') : '/empmanagment';
    $root = rtrim(dirname($dir === '' ? '/' : $dir), '/\\');      // the main folder, one above empmanagment
    return $base . ($root === '.' ? '' : $root) . '/b.php?t=' . $token . ($extra !== '' ? '&' . $extra . '=1' : '');
}
/** The store numbers as lines of text: "• Neil (Store): +91 98765 43210". */
function st_contacts_text(array $cs): string
{
    $o = []; foreach ((array)($cs['contacts'] ?? []) as $c) { $ph = substr(preg_replace('/\D/', '', (string)($c['phone'] ?? '')), -10); if ($ph === '') continue; $nm = trim((string)($c['name'] ?? '')); $rl = trim((string)($c['role'] ?? '')); $o[] = '• ' . ($nm !== '' ? $nm : $cs['store_name']) . ($rl !== '' ? ' (' . $rl . ')' : '') . ': +91 ' . substr($ph, 0, 5) . ' ' . substr($ph, 5); }
    return implode("\n", $o);
}
/** The WhatsApp message (and the web.whatsapp link that opens it ready to send, in the side window) for one customer. */
function st_wa_message(array $person, array $bill, array $cs): array
{
    $name = trim((string)$person['name']); $first = $name !== '' ? explode(' ', $name)[0] : 'there';
    $skip = !empty($person['returning']) && !empty($cs['skip_returning']);      // someone who already shops with us is not asked to save / join / review again
    $m = is_array($cs['msgs'] ?? null) ? $cs['msgs'] : st_msgs_defaults(); $tpl = (string)($skip ? ($m['thanks_back'] ?? '') : ($m['thanks'] ?? '')); if (trim($tpl) === '') { $d = st_msgs_defaults(); $tpl = $skip ? $d['thanks_back'] : $d['thanks']; }
    $tk = (string)$person['token'];      // short, counted links: the bill page, the community, the Google review, "save our numbers", the friends link
    $text = strtr($tpl, ['{name}' => $first, '{store}' => (string)$cs['store_name'], '{bill_no}' => (string)$bill['bill_no'], '{amount}' => number_format((float)$bill['total']), '{bill_link}' => st_short_link($tk),
        '{community_link}' => trim((string)($cs['community_link'] ?? '')) !== '' ? st_short_link($tk, 'c') : '', '{review_link}' => trim((string)($cs['review_link'] ?? '')) !== '' ? st_short_link($tk, 'r') : '',
        '{contacts}' => st_contacts_text($cs), '{contacts_link}' => !empty($cs['contacts']) ? st_short_link($tk, 's') : '', '{friends_link}' => st_short_link($tk, 'g')]);
    $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
    $tc = (string)($m['contacts'] ?? ''); if (trim($tc) === '') $tc = st_msgs_defaults()['contacts'];
    $text2 = !empty($cs['contacts']) ? trim(strtr($tc, ['{name}' => $first, '{store}' => (string)$cs['store_name'], '{contacts}' => st_contacts_text($cs), '{contacts_link}' => st_short_link($tk, 's')])) : '';
    return ['text' => $text, 'url' => 'https://web.whatsapp.com/send?phone=' . $person['phone'] . '&text=' . rawurlencode($text),
        'text2' => $text2, 'url2' => $text2 !== '' ? 'https://web.whatsapp.com/send?phone=' . $person['phone'] . '&text=' . rawurlencode($text2) : ''];
}
function st_event(PDO $pdo, int $personId, int $billId, string $kind, string $who = ''): void
{
    $pdo->prepare("INSERT INTO st_bill_events (person_id, bill_id, kind, at, who) VALUES (?,?,?,?,?)")->execute([$personId, $billId, $kind, date('Y-m-d H:i:s'), mb_substr($who, 0, 80)]);
}
/* ============================== AUTOMATIC WhatsApp bill (sent by the server the moment the bill is saved) ============================== */
/*
 * Two approved WhatsApp templates (UTILITY, English) are used:
 *   tmph_store_bill       new customer  → bill link in the text + two buttons: "Join community" and "Review us on Google" (both tracked through the bill page)
 *   tmph_store_bill_back  returning customer (when "skip_returning" is on) → welcome back + bill link, no buttons
 * They are created and sent to Meta once (st_wa_seed). Until Meta approves them nothing is sent automatically and the cashier's
 * "Send on WhatsApp" button works exactly as before. A failed send never stops the bill and never loses the button.
 * Switch: settings 'auto_wa' (default ON) in the Customer follow-up settings.
 */
function st_wa_load(): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { foreach (['wa.php', 'wa_tpl.php'] as $f) if (is_file(__DIR__ . '/' . $f)) require_once __DIR__ . '/' . $f; } catch (Throwable $e) { error_log('st_wa_load: ' . $e->getMessage()); return $ok = false; }
    return $ok = function_exists('wa_api') && function_exists('wa_tpl_tables') && function_exists('wa_tpl_row') && function_exists('wa_tpl_send_components') && function_exists('wa_key');
}
function st_wa_defs(): array
{
    $base = strtok(st_bill_link('X'), '?'); $suffix = 'a1b2c3d4e5f6a7b8c9d0'; $ex = ['Rahul', 'ST-000123', '4310', $base . '?t=' . $suffix]; $lab = ['Name', 'Bill number', 'Amount', 'Bill link'];
    return [
        'tmph_store_bill' => ['body' => "Hello {{1}}, thank you for shopping at TM Perfume House! Your bill {{2}} of Rs. {{3}} is ready, you can see it anytime at {{4}} . Please use the buttons below to join our community and tell us how we did. We hope to see you again soon.",
            'ex' => $ex, 'lab' => $lab,
            'buttons' => [['type' => 'URL', 'text' => 'Join community', 'url' => $base . '?go=community&t={{1}}', 'example' => $suffix], ['type' => 'URL', 'text' => 'Review us on Google', 'url' => $base . '?go=review&t={{1}}', 'example' => $suffix]]],
        'tmph_store_bill_back' => ['body' => "Welcome back, {{1}}! Thank you for shopping at TM Perfume House again. Your bill {{2}} of Rs. {{3}} is ready, you can see it anytime at {{4}} . We are happy to see you again.",
            'ex' => $ex, 'lab' => $lab, 'buttons' => []],
        // sent only when asked for (st_wa_seed with $only): a promotional message, so category MARKETING
        'tmph_store_bday' => ['category' => 'MARKETING', 'folder' => 'Store birthdays', 'about' => 'A birthday gift for a store customer: a code for one free 50 ml perfume.',
            'body' => "Hello {{1}}, greetings from TM Perfume House! Your birthday is on {{2}} and we have a FREE 50 ml perfume gift for you. Collect it from our store any time between {{3}} and {{4}} by showing this code: {{5}}. Happy birthday in advance!",
            'ex' => ['Rahul', '14 Dec', '12 Dec', '19 Dec', 'BD-7K3M9P'], 'lab' => ['Name', 'Birthday', 'From', 'Till', 'Gift code'], 'buttons' => []],
    ];
}
/** Makes the two templates and sends them to Meta (safe to run again: what exists is not made twice, what is already at Meta is not sent twice). */
function st_wa_seed(PDO $pdo, ?array $only = null): array
{
    $res = ['created' => [], 'submitted' => [], 'skipped' => [], 'failed' => []];
    try {
        if (!st_wa_load() || !wa_tpl_tables($pdo) || !function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at')) { $res['error'] = 'The WhatsApp template module is not available.'; return $res; }
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
        foreach (st_wa_defs() as $name => $d) {
            if ($only === null ? $name === 'tmph_store_bday' : !in_array($name, $only, true)) continue;
            $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); $id = (int)$q->fetchColumn();
            if (!$id) {
                [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => $d['category'] ?? 'UTILITY', 'body' => $d['body'], 'footer' => 'TM Perfume House', 'buttons' => $d['buttons'], 'examples' => ['body' => $d['ex'], 'labels' => $d['lab']], 'folder' => $d['folder'] ?? 'Store bills']);
                if ($err === '' && function_exists('wa_tpl_media_check')) $err = wa_tpl_media_check($pdo, $t);
                if ($err !== '') { $res['failed'][$name] = $err; continue; }
                $id = wa_tpl_insert($pdo, $t, 0, $d['about'] ?? 'The bill of a customer who bought in the store: bill link, and buttons to join the community and review us.', ($d['category'] ?? 'UTILITY') === 'MARKETING' ? 'Marketing message for a store customer.' : 'Utility message about one store bill.'); $res['created'][] = $name;
            }
            $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][$name] = 'Could not read the saved template.'; continue; }
            if (!$wabas) { $res['skipped'][$name] = 'No WhatsApp Business Account ID yet - saved, add one and run this again.'; continue; }
            $have = []; $hq = $pdo->prepare("SELECT waba_id FROM wa_tpl_waba WHERE tpl_id = ?"); $hq->execute([$id]); foreach ($hq->fetchAll() as $h) $have[(string)$h['waba_id']] = true;
            $sent = false; $errs = [];
            foreach ($wabas as $w) { if (isset($have[$w])) continue; [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); if ($ok) $sent = true; else $errs[] = $why; }
            if ($errs) $res['failed'][$name] = $errs[0]; elseif ($sent) $res['submitted'][] = $name; else $res['skipped'][$name] = 'Already at Meta.';
        }
        if (function_exists('wa_log')) wa_log($pdo, 'orders', !$res['failed'], 'Store bill templates: ' . count($res['created']) . ' made, ' . count($res['submitted']) . ' sent to Meta, ' . count($res['failed']) . ' failed' . ($res['failed'] ? ' - ' . mb_substr((string)reset($res['failed']), 0, 120) : ''));
    } catch (Throwable $e) { $res['error'] = $e->getMessage(); }
    return $res;
}
/** Where the two templates stand: missing / PENDING / APPROVED / REJECTED (+ the reason). */
function st_wa_status(PDO $pdo): array
{
    $out = ['auto' => !empty(st_cs_get($pdo)['auto_wa']), 'templates' => []];
    try {
        if (!st_wa_load() || !wa_tpl_tables($pdo)) { $out['error'] = 'The WhatsApp template module is not available.'; return $out; }
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
        foreach (array_keys(st_wa_defs()) as $name) {
            $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); $id = (int)$q->fetchColumn();
            $st = $id && function_exists('wa_tpl_status') ? wa_tpl_status($pdo, $id, $wabas) : ['status' => 'missing', 'reason' => ''];
            $out['templates'][$name] = ['status' => (string)$st['status'], 'reason' => (string)($st['reason'] ?? '')];
        }
    } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
    return $out;
}
/** The template as it can be sent now (approved on at least one WhatsApp account), or null. */
function st_wa_tpl(PDO $pdo, string $name): ?array
{
    $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); $id = (int)$q->fetchColumn(); if (!$id) return null;
    $t = wa_tpl_row($pdo, $id); if (!$t) return null;
    $w = $pdo->prepare("SELECT waba_id FROM wa_tpl_waba WHERE tpl_id = ? AND status = 'APPROVED'"); $w->execute([$id]); $t['ok_wabas'] = array_column($w->fetchAll(), 'waba_id');
    return $t['ok_wabas'] ? $t : null;
}
/** One bill → one customer, as a template message from the shop's own WhatsApp number. Returns [ok, note]. */
function st_wa_send_one(PDO $pdo, array $p, string $billNo, float $total, array $cs): array
{
    $phone = (string)$p['phone']; $key = wa_key($phone); if ($key === '' || strlen($key) !== 10) return [false, 'not an Indian mobile number'];
    $back = !empty($p['returning_c']) && !empty($cs['skip_returning']);
    $t = st_wa_tpl($pdo, $back ? 'tmph_store_bill_back' : 'tmph_store_bill'); if (!$t) return [false, 'template not approved yet'];
    $num = null; foreach ($pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll() as $n) if (in_array(wa_waba_of($n), $t['ok_wabas'], true)) { $num = $n; break; }
    if (!$num) return [false, 'no WhatsApp number can send this template'];
    $c = null; try { $q = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$key]); $c = $q->fetch() ?: null; } catch (Throwable $e) { }
    if ($c && (int)($c['optout'] ?? 0) === 1) return [false, 'customer opted out of WhatsApp'];
    if (!$c) { $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, state, status, source) VALUES (?, ?, ?, ?, 'unassigned', 'open', '')")->execute([$key, '91' . $key, mb_substr((string)$p['name'], 0, 120), date('Y-m-d H:i:s')]); }
    $name = trim((string)$p['name']); $first = $name !== '' ? explode(' ', $name)[0] : 'ji'; if (function_exists('wa_first_name')) $first = wa_first_name($name);
    $vars = [$first, $billNo, number_format($total), st_bill_link((string)$p['token'])];
    $r = wa_tpl_send_components($pdo, $t, $vars, (string)$num['phone_id'], ['url_suffix' => (string)$p['token']]);
    if (empty($r['ok'])) return [false, (string)($r['error'] ?? 'could not build the message')];
    $pl = ['messaging_product' => 'whatsapp', 'to' => '91' . $key, 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]];
    if (!empty($r['components'])) $pl['template']['components'] = $r['components'];
    [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', $pl); $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code < 200 || $code >= 300 || $wamid === '') return [false, function_exists('wa_err') ? wa_err(is_array($d) ? $d : null) : 'WhatsApp did not accept the message'];
    try { $shown = function_exists('wa_tpl_render') ? wa_tpl_render((string)$t['body'], $vars) : $billNo; $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'template', ?, 'sent', 'store', 0, ?)")->execute([$wamid, $key, (int)$num['id'], $shown, date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
    return [true, 'sent'];
}
/** After a bill is saved: send it to every customer with a number. Marks each one "sent". Never throws; returns a short note per person for the screen. */
function st_wa_auto(PDO $pdo, int $billId, string $billNo, float $total, array $cs): array
{
    $notes = [];
    try {
        if (empty($cs['auto_wa']) || !st_wa_load() || !wa_tpl_tables($pdo)) return $notes;
        $q = $pdo->prepare("SELECT person_id, name, phone, token, returning_c FROM st_bill_people WHERE bill_id = ? AND declined = 0 AND phone <> '' ORDER BY person_no"); $q->execute([$billId]);
        $people = $q->fetchAll(); if (!$people) return $notes;
        // the first bill ever (or after a failed try): make the templates and send them to Meta, at most once an hour
        $need = !st_wa_tpl($pdo, 'tmph_store_bill') || !st_wa_tpl($pdo, 'tmph_store_bill_back');
        if ($need && (int)setting_get($pdo, 'st_wa_seed_at', '0') < time() - 3600) { setting_set($pdo, 'st_wa_seed_at', (string)time()); st_wa_seed($pdo); }
        foreach ($people as $p) {
            try { [$ok, $why] = st_wa_send_one($pdo, $p, $billNo, $total, $cs); } catch (Throwable $e) { $ok = false; $why = mb_substr($e->getMessage(), 0, 120); }
            if ($ok) st_event($pdo, (int)$p['person_id'], $billId, 'sent', 'auto WhatsApp');
            elseif (function_exists('wa_log')) { try { wa_log($pdo, 'store', false, $billNo . ' · ' . $p['phone'] . ' · ' . mb_substr($why, 0, 150)); } catch (Throwable $e) { } }
            $notes[(int)$p['person_id']] = $ok ? 'sent' : $why;
        }
    } catch (Throwable $e) { error_log('st_wa_auto: ' . $e->getMessage()); }
    return $notes;
}

/** One row per person with what happened: sent / opened / saved contacts / community tap / review tap (counts + first time). */
function st_people_status(PDO $pdo, array $billIds): array
{
    if (!$billIds) return [];
    $in = implode(',', array_fill(0, count($billIds), '?'));
    $st = $pdo->prepare("SELECT p.person_id, p.bill_id, p.person_no, p.name, p.phone, p.token, p.declined, p.reason, p.returning_c, p.visit_no,
            MIN(CASE WHEN e.kind = 'sent' THEN e.at END) sent_at, MIN(CASE WHEN e.kind = 'opened' THEN e.at END) opened_at, MIN(CASE WHEN e.kind = 'contact' THEN e.at END) contact_at,
            MIN(CASE WHEN e.kind = 'community' THEN e.at END) community_at, MIN(CASE WHEN e.kind = 'review' THEN e.at END) review_at
        FROM st_bill_people p LEFT JOIN st_bill_events e ON e.person_id = p.person_id WHERE p.bill_id IN ($in) GROUP BY p.person_id ORDER BY p.bill_id, p.person_no");
    $st->execute(array_values($billIds));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['bill_id']][] = ['person_id' => (int)$r['person_id'], 'no' => (int)$r['person_no'], 'name' => $r['name'], 'phone' => $r['phone'], 'declined' => (int)$r['declined'], 'reason' => $r['reason'], 'returning' => (int)$r['returning_c'], 'visit_no' => (int)$r['visit_no'],
        'sent_at' => $r['sent_at'], 'opened_at' => $r['opened_at'], 'contact_at' => $r['contact_at'], 'community_at' => $r['community_at'], 'review_at' => $r['review_at'], 'link' => st_bill_link((string)$r['token'])];
    return $out;
}

/* ============================== the customer's BILL PAGE (public, opened from the WhatsApp link) ============================== */

function st_vcf_esc(string $t): string { return str_replace(["\\", ",", ";", "\r", "\n"], ["\\\\", "\\,", "\\;", '', "\\n"], $t); }

/** One .vcf with every store contact (the store number + each salesperson): opening it on a phone offers "Add contacts". */
function st_vcf(array $cs): string
{
    $out = '';
    foreach ($cs['contacts'] as $c) {
        $fn = trim($c['name'] . ($c['role'] !== '' ? ' (' . $c['role'] . ')' : ''));
        $out .= "BEGIN:VCARD\r\nVERSION:3.0\r\nN:;" . st_vcf_esc($fn) . ";;;\r\nFN:" . st_vcf_esc($fn) . "\r\nORG:" . st_vcf_esc($cs['store_name']) . "\r\nTEL;TYPE=CELL,VOICE:+" . $c['phone'] . "\r\nEND:VCARD\r\n";
    }
    return $out;
}

/**
 * What the public bill page does for one request (?t=TOKEN, optional &vcf=1 or &go=community|review). Returns ['status','headers','body'].
 * Every visit and every button tap is written to st_bill_events: that is how the Store knows who opened the bill, saved the contacts, tapped community / review.
 */
function st_bill_page(PDO $pdo, array $q): array
{
    $h = ['X-Robots-Tag: noindex, nofollow', 'Cache-Control: no-store'];
    $tok = preg_replace('/[^A-Za-z0-9]/', '', (string)($q['t'] ?? ''));
    $cs = st_cs_get($pdo); $store = htmlspecialchars($cs['store_name'], ENT_QUOTES, 'UTF-8');
    $fail = fn(int $c, string $m) => ['status' => $c, 'headers' => array_merge($h, ['Content-Type: text/html; charset=utf-8']), 'body' => '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;padding:40px;text-align:center;color:#444"><h2>' . $store . '</h2><p>' . htmlspecialchars($m) . '</p>'];
    $sample = $tok === 'SAMPLE';
    if ($sample) { $p = ['person_id' => 0, 'bill_id' => 0, 'name' => 'Rahul Sharma', 'phone' => '919876543210', 'emp_name' => 'Neil Bhatia', 'list_price' => 4500, 'discount' => 190, 'bill_no' => 'ST-000123', 'total' => 4310, 'created_at' => date('Y-m-d H:i:s'), 'pay_mode' => 'online', 'total_ml' => 170]; $items = [['title' => 'Azaro Most Wanted', 'ml' => 20, 'kind' => 'sale'], ['title' => 'Creed Aventus', 'ml' => 50, 'kind' => 'sale'], ['title' => 'Dior Sauvage', 'ml' => 100, 'kind' => 'sale'], ['title' => 'Bleu de Chanel', 'ml' => 10, 'kind' => 'tester']]; }
    else {
        if (strlen($tok) < 10) return $fail(404, 'This bill link is not valid.');
        $st = $pdo->prepare("SELECT p.person_id, p.bill_id, p.name, p.phone, p.returning_c, b.bill_no, b.total, b.list_price, b.discount, b.emp_name, b.created_at, b.pay_mode, b.total_ml, b.status, b.chain_name, b.inv_no, b.inv_token FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id WHERE p.token = ?");
        $st->execute([$tok]); $p = $st->fetch();
        if (!$p) return $fail(404, 'This bill link is not valid.');
        $it = $pdo->prepare("SELECT pr.title, i.ml, i.kind FROM st_bill_items i JOIN st_bottles bo ON bo.bottle_id = i.bottle_id JOIN st_products pr ON pr.product_id = bo.product_id WHERE i.bill_id = ? ORDER BY pr.title, i.ml");
        $it->execute([(int)$p['bill_id']]); $items = $it->fetchAll();
    }
    $pid = (int)$p['person_id']; $bid = (int)$p['bill_id'];
    if (!empty($q['g'])) return st_guest_page($pdo, $q, $p, $tok, $cs, $sample, $h);
    if (!empty($q['vcf']) && $cs['contacts']) {
        if (!$sample) st_event($pdo, $pid, $bid, 'contact', 'customer');
        return ['status' => 200, 'headers' => array_merge($h, ['Content-Type: text/vcard; charset=utf-8', 'Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9]+/', '-', $cs['store_name']) . '-contacts.vcf"']), 'body' => st_vcf($cs)];
    }
    $go = (string)($q['go'] ?? '');
    if (in_array($go, ['community', 'review'], true) && $cs[$go . '_link'] !== '') {
        if (!$sample) st_event($pdo, $pid, $bid, $go, 'customer');
        return ['status' => 302, 'headers' => array_merge($h, ['Location: ' . $cs[$go . '_link']]), 'body' => ''];
    }
    if (!$sample) {      // one "opened" per person per 10 minutes (a refresh is not a new visit)
        $r = $pdo->prepare("SELECT COUNT(*) FROM st_bill_events WHERE person_id = ? AND kind = 'opened' AND at > ?"); $r->execute([$pid, date('Y-m-d H:i:s', time() - 600)]);
        if (!(int)$r->fetchColumn()) st_event($pdo, $pid, $bid, 'opened', 'customer');
    }
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); $first = trim(explode(' ', trim((string)$p['name']))[0] ?? '');
    $inv = '';
    if (!$sample && !empty($p['inv_token']) && st_wch_load($pdo)) {      // a bill with a chain has the numbered GST invoice (perfumes + chain, HSN, CGST/SGST): the customer opens or downloads that
        $inv = '<div class="card inv"><div class="tt">TAX INVOICE</div><div style="text-align:center;padding:6px 4px 2px"><div style="color:#6f7480;font-size:12.5px">Your GST invoice</div><b style="font-size:20px;letter-spacing:-.01em">' . $e($p['inv_no']) . '</b>'
            . '<div style="color:#52565f;font-size:13.5px;margin:6px 0 12px">Perfumes' . ($p['chain_name'] !== '' ? ' + chain ' . $e($p['chain_name']) : '') . '</div>'
            . '<a class="btn p3" style="justify-content:center;color:#fff" href="' . $e(wch_view_url($p['inv_token'])) . '"><span><b>View invoice</b></span></a><a class="btn p1" style="justify-content:center;color:#fff;margin-bottom:0" href="' . $e(wch_pdf_url($p['inv_token'])) . '"><span><b>Download PDF</b></span></a></div></div>';
    }
    if ($inv === '') $inv = st_invoice_html($p, $items, st_company($pdo), $e);
    $btn = function (string $href, string $label, string $sub, string $cls) use ($e) { return '<a class="btn ' . $cls . '" href="' . $e($href) . '"><span><b>' . $e($label) . '</b><small>' . $e($sub) . '</small></span><i>&rsaquo;</i></a>'; };
    $b = ''; $back = !$sample && (int)($p['returning_c'] ?? 0) === 1 && !empty($cs['skip_returning']);
    if (!$back && $cs['contacts']) $b .= $btn('?t=' . $tok . '&vcf=1', 'Save all our contacts', 'All ' . count($cs['contacts']) . ' number' . (count($cs['contacts']) > 1 ? 's' : '') . ' in one file · one tap', 'p1');
    if (!$back && $cs['community_link'] !== '') $b .= $btn('?t=' . $tok . '&go=community', 'Join our community', 'offers and new arrivals on WhatsApp', 'p2');
    if (!$back && $cs['review_link'] !== '') $b .= $btn('?t=' . $tok . '&go=review', 'Review us on Google', 'it takes 20 seconds', 'p3');
    $gl = st_bill_link($tok) . '&g=1'; $gmsg = 'Hi! Save ' . $cs['store_name'] . ' contacts, join the community and leave a review here: ' . $gl;
    $b .= '<div class="card share" style="text-align:center"><b style="font-size:15px">Came with friends or family?</b><p style="color:#52565f;font-size:13.5px;margin:6px 0 12px">Send them this link, so they can also save our contacts, join the community and review us.</p><a class="btn p4" style="justify-content:center;color:#fff" href="https://wa.me/?text=' . rawurlencode($gmsg) . '"><span><b>Share on WhatsApp</b></span></a></div>';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Your bill · ' . $store . '</title><style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#eef0f3;color:#14161a}
.hero{background:linear-gradient(135deg,#14161a,#2a2e36);color:#fff;padding:26px 20px 70px;text-align:center}.hero h1{margin:0;font-size:20px;letter-spacing:-.01em}.hero p{margin:6px 0 0;opacity:.8;font-size:14px}
.wrap{max-width:460px;margin:-48px auto 30px;padding:0 14px}.card{background:#fff;border-radius:22px;padding:18px;box-shadow:0 8px 30px rgba(20,22,26,.1);margin-bottom:14px}
.top{display:flex;justify-content:space-between;align-items:baseline;gap:10px}.top b{font-size:15px}.top span{color:#6f7480;font-size:12.5px}
.it{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid #eef0f3;font-size:14.5px}.it em{font-style:normal;color:#b45309;font-size:12px;font-weight:700}
.tot{display:flex;justify-content:space-between;align-items:baseline;border-top:2px solid #14161a;margin-top:6px;padding-top:12px}.tot b{font-size:28px;letter-spacing:-.02em}.tot span{color:#6f7480;font-size:13px}
.thx{color:#52565f;font-size:14px;line-height:1.5;margin:2px 2px 14px;text-align:center}
.btn{display:flex;align-items:center;justify-content:space-between;gap:12px;text-decoration:none;color:#fff;border-radius:18px;padding:15px 18px;margin-bottom:10px;box-shadow:0 6px 16px rgba(20,22,26,.12)}
.btn b{display:block;font-size:16px}.btn small{opacity:.9;font-size:12.5px}.btn i{font-style:normal;font-size:26px;opacity:.9}
.p1{background:linear-gradient(135deg,#ff7a2f,#e8651a)}.p2{background:linear-gradient(135deg,#1baf7a,#12805a)}.p3{background:linear-gradient(135deg,#2a78d6,#1d5fb0)}.p4{background:linear-gradient(135deg,#25a85a,#168a43);margin-bottom:0}
.ft{text-align:center;color:#8a8f99;font-size:12px;margin-top:18px}
.inv{padding:0;overflow:hidden}.inv .ih{display:flex;justify-content:space-between;gap:12px;padding:16px 16px 12px;border-bottom:2px solid #14161a}
.inv .co b{display:block;font-size:15px;letter-spacing:-.01em}.inv .co span{display:block;color:#52565f;font-size:11.5px;line-height:1.45;margin-top:2px}
.inv .mk{font-weight:900;font-size:22px;line-height:.95;letter-spacing:-.03em;text-align:right;white-space:nowrap}.inv .mk small{display:block;font-size:9px;font-weight:700;letter-spacing:.04em;color:#6f7480;margin-top:3px}
.inv .tt{text-align:center;font-weight:800;letter-spacing:.28em;font-size:12px;padding:8px 0;background:#f4f5f7}
.inv .meta{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;padding:12px 16px;font-size:12.5px}.inv .meta span{color:#6f7480;display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:700}.inv .meta b{font-weight:700}
.inv table{width:100%;border-collapse:collapse;font-size:12.5px}.inv th{background:#14161a;color:#fff;font-size:10.5px;letter-spacing:.04em;text-transform:uppercase;padding:7px 6px;text-align:right}.inv th:first-child,.inv td:first-child{text-align:left;padding-left:16px}.inv th:last-child,.inv td:last-child{padding-right:16px}
.inv td{padding:8px 6px;border-bottom:1px solid #eef0f3;text-align:right;vertical-align:top}.inv td em{font-style:normal;color:#b45309;font-size:11px;font-weight:700}.inv td small{display:block;color:#8a8f99;font-size:10.5px}
.inv .sum{padding:10px 16px 4px}.inv .sum div{display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0;color:#52565f}.inv .sum .g{border-top:2px solid #14161a;margin-top:6px;padding-top:9px;color:#14161a;align-items:baseline}.inv .sum .g b{font-size:26px;letter-spacing:-.02em}.inv .sum .g span{font-size:12px;color:#6f7480}
.inv .ft2{padding:12px 16px 16px;color:#6f7480;font-size:11px;line-height:1.5;border-top:1px dashed #dfe2e7;margin-top:8px}.inv .ft2 b{color:#14161a}
.pdf{display:block;width:100%;margin:0 0 14px;padding:13px;border:0;border-radius:16px;background:#14161a;color:#fff;font:700 15px inherit;cursor:pointer}
@media print{body{background:#fff}.hero,.thx,.btn,.pdf,.ft,.share{display:none!important}.wrap{margin:0;max-width:none;padding:0}.card{box-shadow:none;border-radius:0}}
</style></head><body><div class="hero"><h1>' . $store . '</h1><p>' . ($back ? 'Welcome back' . ($first !== '' ? ', ' . $e($first) : '') . '!' : ($first !== '' ? 'Hi ' . $e($first) . ', thank you for shopping with us' : 'Thank you for shopping with us')) . '</p></div>
<div class="wrap">' . $inv . '
<button class="pdf" onclick="window.print()">Download / print invoice (PDF)</button>
<p class="thx">' . ($back ? 'It is always a pleasure to see you again. Thank you for choosing us.' : $e($cs['thanks_text'])) . '</p>' . $b . '<div class="ft">' . $store . '</div></div></body></html>';
    return ['status' => 200, 'headers' => array_merge($h, ['Content-Type: text/html; charset=utf-8']), 'body' => $html];
}

/**
 * The company on the invoice: the same details the old POS prints (its settings table pos_settings: company_name, gst_number, pan_number, state_name, state_code,
 * store_address, store_phone). Whatever is empty falls back to the Four Pirates details printed on the product box.
 */
function st_company(PDO $pdo): array
{
    $co = ['name' => 'Fourpirates Ventures LLP', 'brand' => 'TMPerfumehouse', 'gstin' => '', 'pan' => '', 'state' => 'Gujarat', 'state_code' => '24', 'address' => '401, Oberon Business Hub, Near Gajera Globle School, Pal, Surat 395009, Gujarat', 'phone' => '1800 889 1071', 'email' => 'support@fourpirates.in', 'web' => 'www.tmperfumehouse.com'];
    try {
        $map = ['company_name' => 'name', 'gst_number' => 'gstin', 'pan_number' => 'pan', 'state_name' => 'state', 'state_code' => 'state_code', 'store_address' => 'address', 'store_phone' => 'phone'];
        foreach ($pdo->query("SELECT skey, svalue FROM pos_settings WHERE skey IN ('" . implode("','", array_keys($map)) . "')")->fetchAll() as $r) {
            $v = trim((string)$r['svalue']); if ($v === '') continue;
            $co[$map[$r['skey']]] = $map[$r['skey']] === 'state' ? trim(preg_replace('/,.*/', '', $v)) : $v;
        }
    } catch (Throwable $e) { /* no old POS settings table: the defaults stay */ }
    return $co;
}

/** The TAX INVOICE card: company, bill details, customer, items with HSN / GST, totals with CGST + SGST (prices are GST-inclusive at 18 %, like the old POS invoice). */
function st_invoice_html(array $p, array $items, array $co, callable $e): string
{
    $list = (float)($p['list_price'] ?? 0); $disc = (float)($p['discount'] ?? 0); $total = (float)$p['total']; if ($list <= 0) $list = $total + $disc;
    $rows = [];
    foreach ($items as $x) { $k = $x['title'] . '|' . (int)$x['ml'] . '|' . (($x['kind'] ?? 'sale') === 'tester' ? 't' : 's'); if (!isset($rows[$k])) $rows[$k] = ['title' => $x['title'], 'ml' => (int)$x['ml'], 'tester' => ($x['kind'] ?? 'sale') === 'tester', 'qty' => 0]; $rows[$k]['qty']++; }
    $saleMl = 0; foreach ($rows as $r) if (!$r['tester']) $saleMl += $r['ml'] * $r['qty'];
    $rate = 18; $out = ''; $sumT = $sumTax = $sumAmt = 0.0; $left = $list; $leftD = $disc; $n = 0; $cnt = count(array_filter($rows, fn($r) => !$r['tester']));
    foreach ($rows as $r) {
        $n++;
        if ($r['tester']) { $out .= '<tr><td>' . $e($r['title']) . ' ' . $r['ml'] . ' ml <em>free tester</em></td><td>' . $r['qty'] . '</td><td>0.00</td><td>0.00</td></tr>'; continue; }
        $last = (--$cnt === 0); $share = $last ? $left : round($list * ($r['ml'] * $r['qty']) / max(1, $saleMl), 2); $dsh = $last ? $leftD : round($disc * ($r['ml'] * $r['qty']) / max(1, $saleMl), 2); $left -= $share; $leftD -= $dsh;
        $amt = max(0, $share - $dsh); $taxable = $amt / (1 + $rate / 100); $tax = $amt - $taxable; $sumT += $taxable; $sumTax += $tax; $sumAmt += $amt;
        $out .= '<tr><td>' . $e($r['title']) . '<small>' . $r['ml'] . ' ml · HSN 33030050</small></td><td>' . $r['qty'] . '</td><td>' . number_format($tax, 2) . '</td><td>' . number_format($amt, 2) . '</td></tr>';
    }
    $addr = $e($co['address']); $phone = trim($co['phone'] . ($co['email'] !== '' ? ' · ' . $co['email'] : ''));
    return '<div class="card inv"><div class="ih"><div class="co"><b>' . $e($co['name']) . '</b><span>' . $addr . '</span>'
        . ($co['gstin'] !== '' ? '<span>GSTIN: <b style="display:inline;font-size:inherit">' . $e($co['gstin']) . '</b>' . ($co['pan'] !== '' ? ' · PAN: ' . $e($co['pan']) : '') . '</span>' : ($co['pan'] !== '' ? '<span>PAN: ' . $e($co['pan']) . '</span>' : '')) . '</div>'
        . '<div class="mk">TM<br>PH<small>' . $e($co['brand']) . '</small></div></div>'
        . '<div class="tt">TAX INVOICE</div>'
        . '<div class="meta"><div><span>Invoice no</span><b>' . $e($p['bill_no']) . '</b></div><div><span>Date</span><b>' . $e(date('d M Y, h:i A', strtotime((string)$p['created_at']))) . '</b></div>'
        . '<div><span>Customer</span><b>' . $e(trim((string)$p['name']) !== '' ? $p['name'] : 'Walk-in customer') . '</b>' . (!empty($p['phone']) ? '<div style="color:#6f7480;font-size:11.5px">+' . $e($p['phone']) . '</div>' : '') . '</div>'
        . '<div><span>Place of supply</span><b>' . $e($co['state']) . ' (' . $e($co['state_code']) . ')</b>' . (trim((string)($p['emp_name'] ?? '')) !== '' ? '<div style="color:#6f7480;font-size:11.5px">Billed by ' . $e($p['emp_name']) . '</div>' : '') . '</div></div>'
        . '<table><thead><tr><th>Item</th><th>Qty</th><th>GST</th><th>Amount</th></tr></thead><tbody>' . $out . '</tbody></table>'
        . '<div class="sum"><div><span>Total price (incl. GST)</span><span>₹' . number_format($list, 2) . '</span></div>'
        . ($disc > 0 ? '<div><span>Discount</span><span>− ₹' . number_format($disc, 2) . '</span></div>' : '')
        . '<div><span>Taxable value</span><span>₹' . number_format($sumT, 2) . '</span></div><div><span>CGST 9%</span><span>₹' . number_format($sumTax / 2, 2) . '</span></div><div><span>SGST 9%</span><span>₹' . number_format($sumTax / 2, 2) . '</span></div>'
        . '<div class="g"><span>Total · ' . (int)$p['total_ml'] . ' ml · ' . $e(['cash' => 'Cash', 'online' => 'Online', 'split' => 'Cash + online'][$p['pay_mode']] ?? $p['pay_mode']) . '</span><b>₹' . number_format($total, 2) . '</b></div></div>'
        . '<div class="ft2"><b>' . $e($co['name']) . '</b> · ' . $addr . '<br>Customer support ' . $e($phone) . ' · ' . $e($co['web']) . '<br>This is a computer-generated invoice and needs no signature. Prices include GST.</div></div>';
}

/** The marker kept in st_bill_people.reason for a friend who joined through a bill's friends link. */
const ST_GUEST = 'joined via link';

/**
 * The FRIENDS page (?t=TOKEN&g=1): somebody who came along with a customer opens the link they were sent. No bill details. They get the same three buttons
 * (their taps are counted on their OWN row, added to the bill the first time they tap, remembered by a cookie) and may leave a name + number.
 */
function st_guest_page(PDO $pdo, array $q, array $p, string $tok, array $cs, bool $sample, array $h): array
{
    $bid = (int)$p['bill_id']; $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); $store = $e($cs['store_name']);
    $ck = 'stg' . $bid; $hdr = $h; $gid = 0;
    if (!$sample) {
        if (preg_match('/^\d+$/', (string)($_COOKIE[$ck] ?? ''))) { $c = $pdo->prepare("SELECT person_id FROM st_bill_people WHERE person_id = ? AND bill_id = ? AND reason = ?"); $c->execute([(int)$_COOKIE[$ck], $bid, ST_GUEST]); $gid = (int)$c->fetchColumn(); }
        if (!empty($q['_gid'])) $gid = (int)$q['_gid'];       // (tests: no cookies on the command line)
    }
    $ensure = function () use ($pdo, $bid, $ck, &$gid, &$hdr, $sample) {
        if ($sample || $gid) return;
        $n = $pdo->prepare("SELECT COUNT(*), COALESCE(MAX(person_no),0) FROM st_bill_people WHERE bill_id = ? AND reason = ?"); $n->execute([$bid, ST_GUEST]); [$cnt] = $n->fetch(PDO::FETCH_NUM);
        if ((int)$cnt >= 8) return;                     // a bill cannot collect an endless crowd
        $mx = $pdo->prepare("SELECT COALESCE(MAX(person_no),0) FROM st_bill_people WHERE bill_id = ?"); $mx->execute([$bid]);
        $pdo->prepare("INSERT INTO st_bill_people (bill_id, person_no, name, phone, token, declined, reason, returning_c, visit_no, created_at) VALUES (?,?,?,?,?,0,?,0,1,?)")->execute([$bid, (int)$mx->fetchColumn() + 1, '', '', bin2hex(random_bytes(10)), ST_GUEST, date('Y-m-d H:i:s')]);
        $gid = (int)$pdo->lastInsertId(); $hdr[] = 'Set-Cookie: ' . $ck . '=' . $gid . '; Max-Age=2592000; Path=/; SameSite=Lax';
    };
    $base = '?t=' . $tok . '&g=1'; $note = '';
    if (!empty($q['vcf']) && $cs['contacts']) {
        $ensure(); if ($gid) st_event($pdo, $gid, $bid, 'contact', 'friend');
        return ['status' => 200, 'headers' => array_merge($hdr, ['Content-Type: text/vcard; charset=utf-8', 'Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9]+/', '-', $cs['store_name']) . '-contacts.vcf"']), 'body' => st_vcf($cs)];
    }
    $go = (string)($q['go'] ?? '');
    if (in_array($go, ['community', 'review'], true) && $cs[$go . '_link'] !== '') {
        $ensure(); if ($gid) st_event($pdo, $gid, $bid, $go, 'friend');
        return ['status' => 302, 'headers' => array_merge($hdr, ['Location: ' . $cs[$go . '_link']]), 'body' => ''];
    }
    if (!empty($q['join']) && !$sample) {
        $ph = st_phone_clean((string)($q['phone'] ?? '')); $nm = trim(mb_substr((string)($q['name'] ?? ''), 0, 60));
        if ($ph === '' && trim((string)($q['phone'] ?? '')) !== '') $note = '<div class="msg bad">That mobile number does not look right. Please check it.</div>';
        else {
            $ensure();
            if ($gid) {
                $dup = $ph !== '' ? $pdo->prepare("SELECT COUNT(*) FROM st_bill_people WHERE bill_id = ? AND phone = ? AND person_id <> ?") : null;
                if ($dup) { $dup->execute([$bid, $ph, $gid]); if ((int)$dup->fetchColumn()) { $ph = ''; } }
                $lk = $ph !== '' ? st_customer_lookup($pdo, $ph, $bid) : ['returning' => false, 'visits' => 0];
                $pdo->prepare("UPDATE st_bill_people SET name = ?, phone = ?, returning_c = ?, visit_no = ? WHERE person_id = ?")->execute([$nm, $ph, $lk['returning'] ? 1 : 0, (int)$lk['visits'] + 1, $gid]);
                $note = '<div class="msg ok">Thank you' . ($nm !== '' ? ', ' . $e(explode(' ', $nm)[0]) : '') . '! We will stay in touch.</div>';
            }
        }
    }
    $btn = function (string $href, string $label, string $sub, string $cls) use ($e) { return '<a class="btn ' . $cls . '" href="' . $e($href) . '"><span><b>' . $e($label) . '</b><small>' . $e($sub) . '</small></span><i>&rsaquo;</i></a>'; };
    $b = '';
    if ($cs['contacts']) $b .= $btn($base . '&vcf=1', 'Save all our contacts', 'All ' . count($cs['contacts']) . ' number' . (count($cs['contacts']) > 1 ? 's' : '') . ' in one file · one tap', 'p1');
    if ($cs['community_link'] !== '') $b .= $btn($base . '&go=community', 'Join our community', 'offers and new arrivals on WhatsApp', 'p2');
    if ($cs['review_link'] !== '') $b .= $btn($base . '&go=review', 'Review us on Google', 'it takes 20 seconds', 'p3');
    $form = $sample ? '' : '<div class="card"><b style="font-size:15px">Stay in touch <span style="color:#8a8f99;font-weight:600;font-size:12.5px">(optional)</span></b><form method="get" action="" style="margin-top:10px;display:grid;gap:8px"><input type="hidden" name="t" value="' . $e($tok) . '"><input type="hidden" name="g" value="1"><input type="hidden" name="join" value="1"><input name="name" placeholder="Your name" autocomplete="name" maxlength="60" style="padding:12px;border:1.5px solid #dfe2e7;border-radius:12px;font:inherit"><input name="phone" placeholder="Your mobile number" inputmode="tel" autocomplete="tel" style="padding:12px;border:1.5px solid #dfe2e7;border-radius:12px;font:inherit"><button style="padding:13px;border:0;border-radius:12px;background:#14161a;color:#fff;font:700 15px inherit">Save</button></form></div>';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Welcome · ' . $store . '</title><style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#eef0f3;color:#14161a}
.hero{background:linear-gradient(135deg,#14161a,#2a2e36);color:#fff;padding:26px 20px 70px;text-align:center}.hero h1{margin:0;font-size:20px}.hero p{margin:6px 0 0;opacity:.8;font-size:14px}
.wrap{max-width:460px;margin:-48px auto 30px;padding:0 14px}.card{background:#fff;border-radius:22px;padding:18px;box-shadow:0 8px 30px rgba(20,22,26,.1);margin-bottom:14px}
.thx{color:#52565f;font-size:14px;line-height:1.5;margin:2px 2px 14px;text-align:center}.msg{border-radius:14px;padding:12px 14px;margin-bottom:12px;font-size:14px;font-weight:600}.msg.ok{background:#e6f4ec;color:#157a3c}.msg.bad{background:#fdecea;color:#b42318}
.btn{display:flex;align-items:center;justify-content:space-between;gap:12px;text-decoration:none;color:#fff;border-radius:18px;padding:15px 18px;margin-bottom:10px;box-shadow:0 6px 16px rgba(20,22,26,.12)}
.btn b{display:block;font-size:16px}.btn small{opacity:.9;font-size:12.5px}.btn i{font-style:normal;font-size:26px;opacity:.9}
.p1{background:linear-gradient(135deg,#ff7a2f,#e8651a)}.p2{background:linear-gradient(135deg,#1baf7a,#12805a)}.p3{background:linear-gradient(135deg,#2a78d6,#1d5fb0)}.ft{text-align:center;color:#8a8f99;font-size:12px;margin-top:18px}
</style></head><body><div class="hero"><h1>' . $store . '</h1><p>Welcome! A friend shared this with you</p></div><div class="wrap">' . $note . '<p class="thx">' . $e($cs['thanks_text']) . '</p>' . $b . $form . '<div class="ft">' . $store . '</div></div></body></html>';
    return ['status' => 200, 'headers' => array_merge($hdr, ['Content-Type: text/html; charset=utf-8']), 'body' => $html];
}

/**
 * Do we already know this customer? From this store's earlier OK bills and from the old POS customers (pos_customers / pos_orders). A returning customer
 * is not asked again to save our contacts, join the community or review. $exceptBill leaves the bill being made out of the count.
 * @return array ['phone','returning','visits','last_visit','name','done'=>['contact','community','review'],'source']
 */
function st_customer_lookup(PDO $pdo, string $phone, int $exceptBill = 0): array
{
    $r = ['phone' => $phone, 'returning' => false, 'visits' => 0, 'last_visit' => null, 'name' => null, 'bday' => '', 'gifted_year' => false, 'done' => ['contact' => false, 'community' => false, 'review' => false], 'source' => null];
    if ($phone === '') return $r;
    try {
        $st = $pdo->prepare("SELECT p.person_id, p.name, p.bday, b.bill_id, b.created_at FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.phone = ? AND p.declined = 0 AND b.bill_id <> ? ORDER BY b.created_at DESC");
        $st->execute([$phone, $exceptBill]); $rows = $st->fetchAll();
        if ($rows) {
            $r['returning'] = true; $r['source'] = 'store'; $r['visits'] = count(array_unique(array_column($rows, 'bill_id'))); $r['last_visit'] = $rows[0]['created_at'];
            foreach ($rows as $x) if (trim((string)$x['name']) !== '') { $r['name'] = $x['name']; break; }
            $bs0 = st_bday_start($pdo); foreach ($rows as $x) if (!empty($x['bday']) && (string)$x['created_at'] >= $bs0) { $r['bday'] = (string)$x['bday']; break; }   // a birthday told before the feature started is not used: it is asked again
            $ids = implode(',', array_map('intval', array_column($rows, 'person_id')));
            foreach ($pdo->query("SELECT DISTINCT kind FROM st_bill_events WHERE person_id IN ($ids) AND kind IN ('contact','community','review')")->fetchAll() as $e) $r['done'][$e['kind']] = true;
        }
    } catch (Throwable $e) { error_log('st_customer_lookup store: ' . $e->getMessage()); }
    try { $g = $pdo->prepare("SELECT 1 FROM st_bday_gifts WHERE phone = ? AND gift_year = ?"); $g->execute([$phone, (int)date('Y')]); $r['gifted_year'] = (bool)$g->fetchColumn(); } catch (Throwable $e) { /* table not made yet */ }
    try {      // the old POS keeps its customers in this database (pos_customers; its phones are stored with 91)
        $q = $pdo->prepare("SELECT c.id, c.name, (SELECT COUNT(*) FROM pos_orders o WHERE o.customer_id = c.id) n, (SELECT MAX(o.created_at) FROM pos_orders o WHERE o.customer_id = c.id) last FROM pos_customers c WHERE c.phone IN (?, ?) LIMIT 1");
        $q->execute([$phone, substr($phone, -10)]); $c = $q->fetch();
        if ($c) { $r['returning'] = true; $r['source'] = $r['source'] ? 'both' : 'pos'; $r['visits'] += max(1, (int)$c['n']); if (!$r['name'] && trim((string)$c['name']) !== '') $r['name'] = $c['name']; if ($c['last'] && (!$r['last_visit'] || $c['last'] > $r['last_visit'])) $r['last_visit'] = $c['last']; }
    } catch (Throwable $e) { /* no old POS tables here */ }
    return $r;
}

/* ============================== ORDER PLANNER (retail store only: old POS + new POS, never online) ============================== */

/** Spreads $budget bottles over $weights (id => weight) in whole numbers that add up to exactly $budget (largest remainder). $caps (id => max) is optional. */
function st_plan_spread(array $weights, int $budget): array
{
    $out = array_fill_keys(array_keys($weights), 0); $sum = array_sum($weights);
    if ($budget <= 0 || $sum <= 0) return $out;
    $rem = [];
    foreach ($weights as $k => $w) { $x = $budget * $w / $sum; $out[$k] = (int)floor($x); $rem[$k] = $x - floor($x); }
    $left = $budget - array_sum($out); arsort($rem);
    foreach ($rem as $k => $_) { if ($left <= 0) break; $out[$k]++; $left--; }
    return $out;
}

/**
 * "I want to order $total bottles of $targetMl ml": which perfumes, how many of each.
 * Demand = what the RETAIL store really sold in the last 30 days (new POS bills + old POS bills, every size turned into ml, testers left out; online sales are NOT used),
 * blended with the last 7 days, projected for $days days. Stock = scanned bottles in stock (all sizes in ml) + orders already placed.
 * 1) a perfume that is out (or under 3 days) of stock gets what it needs first, 2) the rest share what is left in proportion to what they still need,
 * 3) if the real need is smaller than $total, the extra tops up the perfumes with the fewest days of stock, so the order always adds up to exactly $total.
 */
function st_plan_calc(PDO $pdo, int $total, int $targetMl, int $days, string $mode = 'all'): array
{
    $mode = $mode === 'same' ? 'same' : 'all';      // all = every size of a perfume counts (in ml); same = only the sales and stock of the size being ordered
    $total = max(1, min(5000, $total)); $targetMl = in_array($targetMl, ST_SIZES, true) ? $targetMl : 50; $days = max(3, min(30, $days));
    $day = fn($n) => date('Y-m-d', strtotime('-' . $n . ' day'));
    $d7 = $day(6); $d30 = $day(29); $WIN = [5, 10, 20, 30]; $dw = []; foreach ($WIN as $w) $dw[$w] = $day($w - 1);
    $P = []; $bySid = []; $byTitle = [];
    foreach ($pdo->query("SELECT product_id, shopify_id, title FROM st_products WHERE active = 1")->fetchAll() as $r) {
        $pid = (int)$r['product_id']; $P[$pid] = ['title' => (string)$r['title'], 'ml7' => 0, 'ml30' => 0, 'b30' => 0, 'by' => [10 => 0, 20 => 0, 50 => 0, 100 => 0], 'stock_ml' => 0, 'stock_by' => [10 => 0, 20 => 0, 50 => 0, 100 => 0], 'oo_ml' => 0, 'oo_by' => [10 => 0, 20 => 0, 50 => 0, 100 => 0], 'm7' => [10 => 0, 20 => 0, 50 => 0, 100 => 0], 'm30' => [10 => 0, 20 => 0, 50 => 0, 100 => 0], 'w' => [5 => [], 10 => [], 20 => [], 30 => []]];
        $bySid[(string)$r['shopify_id']] = $pid; $k = st_norm_title($r['title']); if (!isset($byTitle[$k])) $byTitle[$k] = $pid;
    }
    $src = ['new_pos_bottles' => 0, 'old_pos_bottles' => 0]; $unmatched = []; $noSize = 0;
    // new POS: every bottle on an OK bill is one sale
    $q = $pdo->prepare("SELECT b.product_id pid, b.ml, SUM(bl.biz_date >= ?) s7, COUNT(*) s30, SUM(bl.biz_date >= ?) w5, SUM(bl.biz_date >= ?) w10, SUM(bl.biz_date >= ?) w20 FROM st_bill_items i JOIN st_bills bl ON bl.bill_id = i.bill_id AND bl.status = 'ok' JOIN st_bottles b ON b.bottle_id = i.bottle_id WHERE i.kind = 'sale' AND bl.biz_date >= ? GROUP BY b.product_id, b.ml");
    $q->execute([$d7, $dw[5], $dw[10], $dw[20], $d30]);
    foreach ($q->fetchAll() as $r) {
        $pid = (int)$r['pid']; $ml = (int)$r['ml']; if (!isset($P[$pid])) continue;
        foreach ([5 => 'w5', 10 => 'w10', 20 => 'w20', 30 => 's30'] as $wn => $col) $P[$pid]['w'][$wn][$ml] = ($P[$pid]['w'][$wn][$ml] ?? 0) + (int)$r[$col];
        $P[$pid]['ml7'] += (int)$r['s7'] * $ml; $P[$pid]['ml30'] += (int)$r['s30'] * $ml; if (isset($P[$pid]['m7'][$ml])) { $P[$pid]['m7'][$ml] += (int)$r['s7'] * $ml; $P[$pid]['m30'][$ml] += (int)$r['s30'] * $ml; } $P[$pid]['b30'] += (int)$r['s30']; if (isset($P[$pid]['by'][$ml])) $P[$pid]['by'][$ml] += (int)$r['s30']; $src['new_pos_bottles'] += (int)$r['s30'];
    }
    // old POS (tables pos_*, same database). The size is read from the variant or the title ("50 ml").
    try {
        $q = $pdo->prepare("SELECT pp.shopify_product_id sid, oi.title t, oi.variant_title vt, SUM(oi.qty * (o.created_at >= ?)) q7, SUM(oi.qty) q30, SUM(oi.qty * (o.created_at >= ?)) q5, SUM(oi.qty * (o.created_at >= ?)) q10, SUM(oi.qty * (o.created_at >= ?)) q20 FROM pos_order_items oi JOIN pos_orders o ON o.id = oi.order_id LEFT JOIN pos_products pp ON pp.id = oi.product_id WHERE o.created_at >= ? GROUP BY pp.shopify_product_id, oi.title, oi.variant_title");
        $q->execute([$d7 . ' 00:00:00', $dw[5] . ' 00:00:00', $dw[10] . ' 00:00:00', $dw[20] . ' 00:00:00', $d30 . ' 00:00:00']);
        foreach ($q->fetchAll() as $r) {
            $title = (string)$r['t']; if (stripos($title, 'tester') !== false || stripos((string)$r['vt'], 'tester') !== false) continue;
            $ml = 0; if (preg_match('/(\d+)\s*ml/i', (string)$r['vt'], $m) || preg_match('/(\d+)\s*ml/i', $title, $m)) $ml = (int)$m[1];
            $qty = (int)$r['q30']; if ($qty <= 0) continue;
            $pid = $bySid[(string)$r['sid']] ?? ($byTitle[st_norm_title(preg_replace('/\s*[-\/|,]?\s*\d+\s*ml.*$/i', '', $title))] ?? ($byTitle[st_norm_title($title)] ?? 0));
            if (!$pid) { $unmatched[$title] = ($unmatched[$title] ?? 0) + $qty; continue; }
            if ($ml <= 0) { $noSize += $qty; continue; }
            foreach ([5 => 'q5', 10 => 'q10', 20 => 'q20', 30 => 'q30'] as $wn => $col) $P[$pid]['w'][$wn][$ml] = ($P[$pid]['w'][$wn][$ml] ?? 0) + (int)$r[$col];
            $P[$pid]['ml7'] += (int)$r['q7'] * $ml; $P[$pid]['ml30'] += $qty * $ml; if (isset($P[$pid]['m7'][$ml])) { $P[$pid]['m7'][$ml] += (int)$r['q7'] * $ml; $P[$pid]['m30'][$ml] += $qty * $ml; } $P[$pid]['b30'] += $qty; if (isset($P[$pid]['by'][$ml])) $P[$pid]['by'][$ml] += $qty; $src['old_pos_bottles'] += $qty;
        }
    } catch (Throwable $e) { error_log('st_plan old POS: ' . $e->getMessage()); $src['old_pos_error'] = 'The old POS tables could not be read.'; }
    // stock now and orders already placed
    foreach ($pdo->query("SELECT product_id, ml, COUNT(*) c FROM st_bottles WHERE status = 'stock' AND kind = 'sale' GROUP BY product_id, ml")->fetchAll() as $r) {
        $pid = (int)$r['product_id']; if (!isset($P[$pid])) continue; $P[$pid]['stock_ml'] += (int)$r['c'] * (int)$r['ml']; if (isset($P[$pid]['stock_by'][(int)$r['ml']])) $P[$pid]['stock_by'][(int)$r['ml']] += (int)$r['c'];
    }
    foreach (st_on_order_map($pdo) as $k => $qty) { [$pid, $ml] = array_map('intval', explode('|', $k)); if (isset($P[$pid])) { $P[$pid]['oo_ml'] += (int)$qty * $ml; if (isset($P[$pid]['oo_by'][$ml])) $P[$pid]['oo_by'][$ml] += (int)$qty * $ml; } }
    if ($mode === 'same') {       // only the size being ordered: its own sales, its own stock, its own placed orders
        foreach ($P as $pid => &$p) { $p['ml7'] = $p['m7'][$targetMl] ?? 0; $p['ml30'] = $p['m30'][$targetMl] ?? 0; $p['b30'] = (int)round($p['ml30'] / $targetMl); $p['stock_ml'] = ($p['stock_by'][$targetMl] ?? 0) * $targetMl; $p['oo_ml'] = $p['oo_by'][$targetMl] ?? 0; }
        unset($p);
    }
    // ml sold in the last 5 / 10 / 20 / 30 days (all sizes, or only the size being ordered) and ml per day
    $winOut = function (array $p) use ($WIN, $mode, $targetMl) {
        $o = [];
        foreach ($WIN as $w) { $ml = 0; foreach ($p['w'][$w] as $size => $cnt) { if ($mode === 'same' && $size !== $targetMl) continue; $ml += $cnt * $size; } $o[$w] = ['ml' => $ml, 'per_day' => round($ml / $w, 1)]; }
        return $o;
    };
    // demand and need, in bottles of the size being ordered
    $rows = []; $need = []; $urgent = [];
    foreach ($P as $pid => $p) {
        if ($p['ml30'] <= 0) continue;
        $rate = 0.6 * ($p['ml7'] / 7) + 0.4 * ($p['ml30'] / 30);               // ml per day
        $fc = $rate * $days; $have = $p['stock_ml'] + $p['oo_ml'];
        $needMl = max(0.0, $fc - $have); $needU = (int)ceil($needMl / $targetMl);
        $left = $rate > 0 ? $p['stock_ml'] / $rate : null;
        $prio = ($p['stock_ml'] <= 0 || ($left !== null && $left < 3)) ? 'urgent' : (($left !== null && $left < 7) ? 'soon' : 'ok');
        $rows[$pid] = ['product_id' => $pid, 'title' => $p['title'], 'sold30' => $p['b30'], 'sold30_by' => $p['by'], 'sold30_ml' => $p['ml30'], 'per_day_ml' => round($rate, 1), 'forecast' => round($fc / $targetMl, 1),
            'win' => $winOut($p), 'stock_ml' => (int)$p['stock_ml'], 'stock' => round($p['stock_ml'] / $targetMl, 1), 'stock_by' => $p['stock_by'], 'on_order' => round($p['oo_ml'] / $targetMl, 1), 'days_left' => $left === null ? null : round($left, 1), 'priority' => $prio, 'need' => $needU, 'qty' => 0, 'extra' => 0];
        if ($needU > 0) { $need[$pid] = $needU; if ($prio === 'urgent') $urgent[$pid] = $needU; }
    }
    $budget = $total; $sumNeed = array_sum($need);
    // 1) the urgent ones first
    $u = $urgent; $su = array_sum($u);
    if ($su > 0) { $give = $su <= $budget ? $u : st_plan_spread($u, $budget); foreach ($give as $pid => $n) { $rows[$pid]['qty'] += $n; $budget -= $n; } }
    // 2) the rest of the real need, shared in proportion
    $rest = array_diff_key($need, $urgent); $sr = array_sum($rest);
    if ($budget > 0 && $sr > 0) { $give = $sr <= $budget ? $rest : st_plan_spread($rest, $budget); foreach ($give as $pid => $n) { $rows[$pid]['qty'] += $n; $budget -= $n; } }
    // 3) still bottles left: raise everybody to the same number of days of stock (the ones with the fewest days of stock get topped up first)
    // a perfume that sells very little (under 3 bottles of this size in 30 days) and is still in stock is never topped up
    $slow = fn($pid) => $P[$pid]['ml30'] < 3 * $targetMl && $P[$pid]['stock_ml'] > 0;
    $elig = array_filter($rows, fn($r) => !$slow($r['product_id']));
    if ($budget > 0 && $elig) {
        $cur = []; foreach ($elig as $pid => $r) $cur[$pid] = $P[$pid]['stock_ml'] + $P[$pid]['oo_ml'] + $r['qty'] * $targetMl;
        $lo = 0.0; $hi = 3650.0;
        for ($i = 0; $i < 60; $i++) { $mid = ($lo + $hi) / 2; $sum = 0.0; foreach ($elig as $pid => $r) $sum += max(0.0, ($r['per_day_ml'] * $mid - $cur[$pid]) / $targetMl); if ($sum > $budget) $hi = $mid; else $lo = $mid; }
        $w = []; foreach ($elig as $pid => $r) { $x = max(0.0, ($r['per_day_ml'] * $lo - $cur[$pid]) / $targetMl); if ($x > 0) $w[$pid] = $x; }
        if (!$w) { foreach ($elig as $pid => $r) $w[$pid] = max(0.01, $r['per_day_ml']); }
        foreach (st_plan_spread($w, $budget) as $pid => $n) { $rows[$pid]['qty'] += $n; $rows[$pid]['extra'] += $n; }
        $budget = 0;
    }
    foreach ($rows as $pid => &$r) { $rate = $r['per_day_ml']; $r['covers_days'] = $rate > 0 ? round(($P[$pid]['stock_ml'] + $P[$pid]['oo_ml'] + $r['qty'] * $targetMl) / $rate, 1) : null; $r['short'] = $r['need'] > $r['qty']; }
    unset($r);
    $list = array_values(array_filter($rows, fn($r) => $r['qty'] > 0));
    usort($list, fn($a, $b) => [$b['qty'], $b['sold30']] <=> [$a['qty'], $a['sold30']]);
    $left0 = array_values(array_filter($rows, fn($r) => $r['qty'] === 0 && ($r['priority'] !== 'ok')));
    // orders already placed: what arrived, what is still pending (pending lines are carried into the next order first)
    $pending = []; $onway = [];
    foreach (st_open_orders($pdo) as $o) {
        $ord = 0; $rec = 0; $rem = 0;
        foreach ($o['items'] as $it) { $ord += $it['qty']; $rec += $it['received']; $rem += $it['remaining']; if ($it['remaining'] > 0) $pending[] = ['order_id' => $o['order_id'], 'product_id' => $it['product_id'], 'title' => $it['title'], 'ml' => $it['ml'], 'ordered' => $it['qty'], 'received' => $it['received'], 'remaining' => $it['remaining'], 'ordered_by' => (string)$o['ordered_by'], 'ordered_at' => (string)$o['ordered_at']]; }
        $onway[] = ['order_id' => $o['order_id'], 'ordered_by' => (string)$o['ordered_by'], 'ordered_at' => (string)$o['ordered_at'], 'ordered' => $ord, 'received' => $rec, 'pending' => $rem];
    }
    arsort($unmatched); $um = []; foreach (array_slice($unmatched, 0, 8, true) as $t => $n) $um[] = ['title' => $t, 'qty' => $n];
    return ['total' => $total, 'ml' => $targetMl, 'days' => $days, 'mode' => $mode, 'windows' => $WIN, 'allocated' => array_sum(array_column($list, 'qty')), 'real_need' => $sumNeed, 'perfumes' => count($list), 'urgent' => count($urgent), 'short' => count(array_filter($list, fn($r) => $r['short'])),
        'items' => $list, 'not_ordered_low' => $left0, 'pending' => $pending, 'on_the_way' => $onway, 'sources' => $src + ['old_pos_unmatched' => $um, 'old_pos_no_size_bottles' => $noSize],
        'method' => 'Retail store only (old POS + new POS, testers left out, online sales not used). Sales of the last 30 days, blended with the last 7 days, every size counted in ml, projected for ' . $days . ' days. Minus the bottles in stock and orders already placed. Out-of-stock or under-3-days perfumes are filled first, then the rest in proportion to what they still need, then any extra tops up the perfumes with the fewest days of stock (perfumes that sell under 3 bottles a month and are in stock are left out), so the order adds up to ' . $total . '.'];
}

/**
 * Several sizes at once: $sizes = [ml => how many bottles], for example [20 => 50, 50 => 200, 100 => 30].
 * The perfumes are chosen ONCE from all the ml that the whole order is worth (same demand, same stock, same rules as st_plan_calc, counted in ml),
 * then every size's bottles are spread over those perfumes in proportion, so each size adds up to exactly what was typed.
 */
function st_plan_multi(PDO $pdo, array $sizes, int $days): array
{
    $clean = [];
    foreach ($sizes as $ml => $n) { $ml = (int)$ml; $n = (int)$n; if (in_array($ml, ST_SIZES, true) && $n > 0) $clean[$ml] = min(5000, $n); }
    if (!$clean) $clean = [50 => 600];
    ksort($clean);
    $u = min(array_keys($clean)); $totalMl = 0; foreach ($clean as $ml => $n) $totalMl += $ml * $n;
    $r = st_plan_calc($pdo, max(1, (int)ceil($totalMl / $u)), $u, $days, 'all');      // qty of every row = its share in bottles of the smallest size
    $items = $r['items'];
    foreach ($items as $i => $it) { $items[$i]['qty_by'] = array_fill_keys(array_keys($clean), 0); $items[$i]['share'] = $it['qty']; }
    foreach ($clean as $ml => $n) {
        $w = []; foreach ($items as $i => $it) if ($it['share'] > 0) $w[$i] = $it['share'];
        foreach (st_plan_spread($w, $n) as $i => $q) $items[$i]['qty_by'][$ml] = $q;
    }
    $items = array_values(array_filter($items, fn($it) => array_sum($it['qty_by']) > 0));
    usort($items, fn($a, $b) => [$b['share'], $b['sold30']] <=> [$a['share'], $a['sold30']]);
    $r['items'] = $items; $r['sizes'] = $clean; $r['ml'] = $u; $r['total_ml'] = $totalMl; $r['perfumes'] = count($items);
    $r['allocated_by'] = []; foreach ($clean as $ml => $n) $r['allocated_by'][$ml] = array_sum(array_map(fn($it) => $it['qty_by'][$ml], $items));
    return $r;
}

/* ============================== ORDER CODE for a store bill: letters of the person + the next number (KC00061) ============================== */

/** The letters of a code from a person's name: first letter of the first two words (Krish Champaneria -> KC); one word -> its first two letters; nothing usable -> ST. */
function st_code_prefix(string $name): string
{
    $words = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\s]/u', ' ', $name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $p = count($words) >= 2 ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1) : (count($words) === 1 ? mb_substr($words[0], 0, 2) : '');
    $p = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $p) ?: ''));
    return strlen($p) >= 2 ? substr($p, 0, 2) : 'ST';
}
/** The next free code of this person: the biggest number already used with the same letters (store bills AND the online Order Codes) + 1. Only a suggestion: the cashier can still type another code. */
function st_next_order_code(PDO $pdo, int $empId): array
{
    $q = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ?"); $q->execute([$empId]);
    $prefix = st_code_prefix((string)$q->fetchColumn()); $max = 0; $width = 5; $like = $prefix . '%';
    $scan = function (string $sql) use ($pdo, $like, $prefix, &$max, &$width) {
        try { $st = $pdo->prepare($sql); $st->execute([$like]); foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) if (preg_match('/^' . $prefix . '(\d+)$/', strtoupper(trim((string)$c)), $m)) { $max = max($max, (int)$m[1]); $width = max($width, strlen($m[1])); } }
        catch (Throwable $e) { /* that table is not there: fine */ }
    };
    $scan("SELECT DISTINCT order_code FROM st_bills WHERE order_code LIKE ?");
    $scan("SELECT DISTINCT code FROM order_codes WHERE code LIKE ?");
    return ['prefix' => $prefix, 'code' => $prefix . str_pad((string)($max + 1), $width, '0', STR_PAD_LEFT)];
}


/** Customers whose birthday falls in the next $days days (today included), from what the store collected at billing. */
function st_birthdays(PDO $pdo, int $days = 14): array
{
    $out = [];
    try {
        $rq = $pdo->prepare("SELECT p.phone, p.name, p.bday, MAX(b.created_at) last FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.bday <> '' AND p.declined = 0 AND b.created_at >= ? GROUP BY p.phone, p.name, p.bday"); $rq->execute([st_bday_start($pdo)]); $rows = $rq->fetchAll();   // only birthdays told since the feature started
        if (is_file(__DIR__ . '/cust_bday.php')) require_once __DIR__ . '/cust_bday.php'; if (function_exists('cbd_all')) foreach (cbd_all($pdo) as $cb) $rows[] = ['phone' => $cb['phone'], 'name' => $cb['name'], 'bday' => $cb['bday'], 'last' => $cb['last']];   // 🎂 birthdays collected when an order was made
        $seen = []; $today = strtotime(date('Y-m-d'));
        foreach ($rows as $r) {
            $k = $r['phone'] !== '' ? $r['phone'] : $r['name']; if (isset($seen[$k])) continue;
            if (!preg_match('/^(\d\d)-(\d\d)$/', $r['bday'], $m)) continue;
            $dt = null;
            foreach ([(int)date('Y'), (int)date('Y') + 1] as $y) { if (checkdate((int)$m[1], (int)$m[2], $y)) { $t = strtotime(sprintf('%04d-%02d-%02d', $y, $m[1], $m[2])); if ($t >= $today) { $dt = $t; break; } } }
            if ($dt === null) continue;
            $left = (int)round(($dt - $today) / 86400); if ($left > $days) continue;
            $seen[$k] = 1;
            $out[] = ['name' => (string)$r['name'], 'phone' => (string)$r['phone'], 'bday' => $r['bday'], 'date' => date('Y-m-d', $dt), 'in_days' => $left, 'last_visit' => $r['last'], 'gifted' => st_gift_given($pdo, (string)$r['phone'], (int)date('Y', $dt)), 'voucher' => st_bday_voucher_for($pdo, (string)$r['phone'], (int)date('Y', $dt))];
        }
    } catch (Throwable $e) { error_log('st_birthdays: ' . $e->getMessage()); }
    usort($out, fn($a, $b) => $a['in_days'] <=> $b['in_days'] ?: strcmp($a['name'], $b['name']));
    return $out;
}

function st_gift_given(PDO $pdo, string $phone, int $year): bool
{
    if ($phone === '') return false;
    try { $q = $pdo->prepare("SELECT 1 FROM st_bday_gifts WHERE phone = ? AND gift_year = ?"); $q->execute([$phone, $year]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}


/* ============================== BIRTHDAY GIFT: a WhatsApp code N days before the birthday, redeemed at billing for one free 50 ml ============================== */

/** The day the birthday feature started ("from now on"): only birthdays told at or after it count. */
function st_bday_start(PDO $pdo): string
{
    static $v = null; if ($v !== null) return $v;
    $s = (string)setting_get($pdo, 'st_bday_start', ''); if ($s === '') { $s = '2026-11-08 00:00:00'; setting_set($pdo, 'st_bday_start', $s); }   // the day the owner started it (fixed, so a late first request cannot move it)
    return $v = $s;
}
function st_bday_defaults(): array { return ['enabled' => 1, 'send_before' => 1, 'valid_after' => 14, 'auto_wa' => 1]; }   // the gift goes out the day BEFORE the birthday and stays valid about 15 days in all
function st_bday_cfg(PDO $pdo): array
{
    $d = st_bday_defaults();
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'bday'")->fetchColumn(); $j = $v ? json_decode($v, true) : null; if (is_array($j)) $d = array_merge($d, $j); } catch (Throwable $e) { }
    return ['enabled' => empty($d['enabled']) ? 0 : 1, 'auto_wa' => empty($d['auto_wa']) ? 0 : 1, 'send_before' => max(0, min(30, (int)$d['send_before'])), 'valid_after' => max(0, min(60, (int)$d['valid_after']))];
}
function st_bday_cfg_save(PDO $pdo, array $in): array
{
    $c = ['enabled' => empty($in['enabled']) ? 0 : 1, 'auto_wa' => empty($in['auto_wa']) ? 0 : 1, 'send_before' => max(0, min(30, (int)($in['send_before'] ?? 0))), 'valid_after' => max(0, min(60, (int)($in['valid_after'] ?? 15)))];
    $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('bday', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($c)]);
    return $c;
}
/** BD-XXXXXX: six letters/digits that cannot be mixed up (no 0 O 1 I L). */
function st_bday_make_code(PDO $pdo): string
{
    $al = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($t = 0; $t < 20; $t++) {
        $c = 'BD-'; for ($i = 0; $i < 6; $i++) $c .= $al[random_int(0, strlen($al) - 1)];
        $q = $pdo->prepare("SELECT 1 FROM st_bday_vouchers WHERE code = ?"); $q->execute([$c]); if (!$q->fetchColumn()) return $c;
    }
    return 'BD-' . strtoupper(bin2hex(random_bytes(4)));
}
/** What the cashier typed → the stored code form ("bd 7k3m9p", "7K3M9P" and "BD-7K3M9P" all work). */
function st_bday_norm(string $c): string
{
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); if (strpos($c, 'BD') === 0) $c = substr($c, 2);
    return $c === '' ? '' : 'BD-' . $c;
}
function st_bday_next(string $md, ?int $today = null): ?int      // the next time MM-DD comes round (today counts), as a day timestamp
{
    if (!preg_match('/^(\d\d)-(\d\d)$/', $md, $m)) return null; $today = $today ?? strtotime(date('Y-m-d'));
    foreach ([(int)date('Y', $today), (int)date('Y', $today) + 1] as $y) { if (checkdate((int)$m[1], (int)$m[2], $y)) { $t = strtotime(sprintf('%04d-%02d-%02d', $y, $m[1], $m[2])); if ($t >= $today) return $t; } }
    return null;
}
/** Makes a code for every customer whose birthday is within "send_before" days and has none this year, then sends the pending ones. Safe to run again and again. */
function st_bday_run(PDO $pdo, bool $send = true, ?int $now = null): array
{
    if (is_file(__DIR__ . '/cust_bday.php')) require_once __DIR__ . '/cust_bday.php';
    if ((string)setting_get($pdo, 'st_bday_v2', '') === '') {   // once: the owner's new rule - the gift goes on the birthday itself and stays valid 15 days
        setting_set($pdo, 'st_bday_v2', date('Y-m-d H:i:s')); $o0 = st_bday_cfg($pdo); st_bday_cfg_save($pdo, ['enabled' => $o0['enabled'], 'auto_wa' => 1, 'send_before' => 0, 'valid_after' => 15]);
    }
    if ((string)setting_get($pdo, 'st_bday_v3', '') === '') {   // once more: the owner wants the message the day BEFORE the birthday (valid until 14 days after it)
        setting_set($pdo, 'st_bday_v3', date('Y-m-d H:i:s')); $o1 = st_bday_cfg($pdo); st_bday_cfg_save($pdo, ['enabled' => $o1['enabled'], 'auto_wa' => 1, 'send_before' => 1, 'valid_after' => 14]);
    }
    $cfg = st_bday_cfg($pdo); $out = ['made' => 0, 'sent' => 0, 'failed' => 0, 'waiting' => 0, 'off' => !$cfg['enabled']];
    if (!$cfg['enabled']) return $out;
    $today = strtotime(date('Y-m-d', $now ?? time())); $seen = [];
    $start = st_bday_start($pdo);   // the feature starts now: birthdays told before it are not used, and codes made before it that were never sent are cancelled (codes already sent stay valid)
    $pdo->prepare("UPDATE st_bday_vouchers SET status = 'cancelled', wa_note = 'Before the new birthday rule started' WHERE status = 'active' AND wa_status IN ('pending','failed','queued') AND created_at < ?")->execute([$start]);
    $rq = $pdo->prepare("SELECT p.phone, p.name, p.bday FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.bday <> '' AND p.declined = 0 AND p.phone <> '' AND b.created_at >= ? ORDER BY b.created_at DESC, p.person_id DESC"); $rq->execute([$start]); $rows = $rq->fetchAll();
    if (st_wa_load() && !function_exists('wo_cfg') && is_file(__DIR__ . '/wa_orders.php')) { try { require_once __DIR__ . '/wa_orders.php'; } catch (Throwable $e) { error_log('st_bday_run wa_orders: ' . $e->getMessage()); } }   // the automatic-message queue
    $online = []; if (function_exists('cbd_all')) foreach (cbd_all($pdo) as $cb) { $online[(string)$cb['phone']] = 1; $rows[] = ['phone' => $cb['phone'], 'name' => $cb['name'], 'bday' => $cb['bday']]; }   // 🎂 birthdays collected at ordering: these customers get the message by themselves
    $ins = $pdo->prepare("INSERT IGNORE INTO st_bday_vouchers (code, phone, name, bday, bday_year, valid_from, valid_to, status, wa_status, created_at) VALUES (?,?,?,?,?,?,?, 'active', 'pending', ?)");
    foreach ($rows as $r) {
        $ph = (string)$r['phone']; if (isset($seen[$ph])) continue; $seen[$ph] = 1;      // the newest answer of that mobile number wins
        $dt = st_bday_next((string)$r['bday'], $today); if ($dt === null) continue;
        $left = (int)round(($dt - $today) / 86400); if ($left > $cfg['send_before']) continue;
        $yr = (int)date('Y', $dt); $q = $pdo->prepare("SELECT 1 FROM st_bday_vouchers WHERE phone = ? AND bday_year = ?"); $q->execute([$ph, $yr]); if ($q->fetchColumn()) continue;
        $ins->execute([st_bday_make_code($pdo), $ph, mb_substr((string)$r['name'], 0, 120), $r['bday'], $yr, date('Y-m-d', $today), date('Y-m-d', $dt + $cfg['valid_after'] * 86400), date('Y-m-d H:i:s', $now ?? time())]);
        if ($ins->rowCount()) $out['made']++;
    }
    if ($send && $cfg['auto_wa']) {
        foreach ($pdo->query("SELECT * FROM st_bday_vouchers WHERE status = 'active' AND wa_status IN ('pending') AND valid_to >= CURDATE() ORDER BY voucher_id LIMIT 40")->fetchAll() as $v) {
            if (!isset($online[(string)$v['phone']])) continue;   // a STORE customer: the cashier sends the wish by hand (Store → Birthdays → To send today)
            if (function_exists('wo_tpl_for') && function_exists('wo_cfg') && !empty(wo_cfg($pdo)['statuses']['birthday']) && wo_tpl_for($pdo, 'birthday') !== null) {   // the automatic message (WhatsApp → Order-update messages → Birthday gift)
                $qi = $pdo->prepare("INSERT IGNORE INTO wa_ord_sent (parcel_id, phone, order_name, status, tpl_name, state, created_at, due_at) VALUES (?,?,?,?,?, 'queued', ?, ?)"); $nw = time();
                $qi->execute([-(int)$v['voucher_id'], wo_key((string)$v['phone']), (string)$v['code'], 'birthday', wo_send_defs()['birthday'][0], wo_dt($nw), wo_dt(wo_next_open($nw))]);
                $pdo->prepare("UPDATE st_bday_vouchers SET wa_status = 'queued', wa_note = 'waiting in the automatic messages', wa_at = ? WHERE voucher_id = ?")->execute([date('Y-m-d H:i:s'), (int)$v['voucher_id']]); $out['sent']++; continue;
            }
            $out['waiting']++; continue;   // an online customer waits until the automatic Birthday gift message is approved and switched ON (it never goes out by the old sender)
            [$ok, $why] = st_bday_send($pdo, $v);
            if ($ok) $out['sent']++; elseif (strpos($why, 'not approved') !== false || strpos($why, 'not available') !== false) $out['waiting']++; else $out['failed']++;
        }
    }
    return $out;
}
/** Makes the WhatsApp birthday template and sends it to Meta for approval BY ITSELF (nobody has to press a button). At most once an hour, and only while it does not exist yet. */
function st_bday_ensure_template(PDO $pdo): string
{
    try {
        if (!st_wa_load() || !wa_tpl_tables($pdo)) return 'WhatsApp is not available on this server';
        if (st_wa_tpl($pdo, 'tmph_store_bday')) return 'approved';
        if ((int)setting_get($pdo, 'st_bday_seed_at', '0') > time() - 3600) return 'waiting for Meta to approve (asked less than an hour ago)';
        setting_set($pdo, 'st_bday_seed_at', (string)time());
        $r = st_wa_seed($pdo, ['tmph_store_bday']);
        return !empty($r['error']) ? (string)$r['error'] : ($r['failed'] ? 'Meta said: ' . mb_substr((string)reset($r['failed']), 0, 120) : ($r['submitted'] ? 'sent to Meta' : 'already with Meta'));
    } catch (Throwable $e) { return mb_substr($e->getMessage(), 0, 120); }
}
/** One voucher → one WhatsApp template message from the shop's own number. Returns [ok, note]; the result is saved on the voucher. */
function st_bday_send(PDO $pdo, array $v): array
{
    $note = function (bool $ok, string $why, string $state) use ($pdo, $v) {
        $pdo->prepare("UPDATE st_bday_vouchers SET wa_status = ?, wa_note = ?, wa_at = ? WHERE voucher_id = ?")->execute([$state, mb_substr($why, 0, 200), date('Y-m-d H:i:s'), (int)$v['voucher_id']]);
        return [$ok, $why];
    };
    try {
        if (!st_wa_load() || !wa_tpl_tables($pdo)) return $note(false, 'WhatsApp is not available on this server', 'pending');
        $key = wa_key((string)$v['phone']); if ($key === '' || strlen($key) !== 10) return $note(false, 'not an Indian mobile number', 'failed');
        $t = st_wa_tpl($pdo, 'tmph_store_bday'); if (!$t) return $note(false, 'birthday template not approved yet', 'pending');
        $num = null; foreach ($pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll() as $n) if (in_array(wa_waba_of($n), $t['ok_wabas'], true)) { $num = $n; break; }
        if (!$num) return $note(false, 'no WhatsApp number can send this template', 'pending');
        $c = null; try { $q = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$key]); $c = $q->fetch() ?: null; } catch (Throwable $e) { }
        if ($c && (int)($c['optout'] ?? 0) === 1) return $note(false, 'customer opted out of WhatsApp', 'failed');
        if (!$c) { $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, state, status, source) VALUES (?, ?, ?, ?, 'unassigned', 'open', '')")->execute([$key, '91' . $key, mb_substr((string)$v['name'], 0, 120), date('Y-m-d H:i:s')]); }
        $nm = trim((string)$v['name']); $first = $nm !== '' ? explode(' ', $nm)[0] : 'ji'; if (function_exists('wa_first_name')) $first = wa_first_name($nm);
        $dt = st_bday_next((string)$v['bday'], strtotime((string)$v['valid_from'])) ?: strtotime((string)$v['valid_to']);
        $vars = [$first, date('j M', $dt), date('j M', strtotime((string)$v['valid_from'])), date('j M', strtotime((string)$v['valid_to'])), (string)$v['code']];
        $r = wa_tpl_send_components($pdo, $t, $vars, (string)$num['phone_id'], []);
        if (empty($r['ok'])) return $note(false, (string)($r['error'] ?? 'could not build the message'), 'failed');
        $pl = ['messaging_product' => 'whatsapp', 'to' => '91' . $key, 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]];
        if (!empty($r['components'])) $pl['template']['components'] = $r['components'];
        [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', $pl); $wamid = (string)($d['messages'][0]['id'] ?? '');
        if ($code < 200 || $code >= 300 || $wamid === '') return $note(false, function_exists('wa_err') ? wa_err(is_array($d) ? $d : null) : 'WhatsApp did not accept the message', 'failed');
        try { $shown = function_exists('wa_tpl_render') ? wa_tpl_render((string)$t['body'], $vars) : (string)$v['code']; $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'template', ?, 'sent', 'store', 0, ?)")->execute([$wamid, $key, (int)$num['id'], $shown, date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
        return $note(true, 'sent', 'sent');
    } catch (Throwable $e) { return $note(false, mb_substr($e->getMessage(), 0, 150), 'failed'); }
}
/** The wording the cashier sends BY HAND (web.whatsapp) and its optional photo. {name} {code} {birthday} {from} {till} are filled in for each customer by the screen. */
function st_bday_msg_default(): string
{
    return "Hello {name}! 🎂 Your birthday is tomorrow ({birthday}), and TM Perfume House has a FREE 50 ml perfume gift for you. Collect it from our store any time between {from} and {till} by showing this code: {code}. Happy birthday in advance! 💖";   // the STORE message (said by hand); the online customers get their own automatic message
}
function st_bday_msg_day_default(): string
{
    return "Happy birthday {name}! 🎂🎉 TM Perfume House has a FREE 50 ml perfume gift for you today. Collect it from our store any time between {from} and {till} by showing this code: {code}. Have a wonderful day! 💖";
}
function st_bday_msg(PDO $pdo, bool $withPhoto = false): array
{
    $j = null; try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'bday_msg'")->fetchColumn(); $j = $v ? json_decode((string)$v, true) : null; } catch (Throwable $e) { }
    $text = is_array($j) && trim((string)($j['text'] ?? '')) !== '' ? (string)$j['text'] : st_bday_msg_default(); $photo = is_array($j) ? (string)($j['photo'] ?? '') : '';
    $dt = is_array($j) && trim((string)($j['day_text'] ?? '')) !== '' ? (string)$j['day_text'] : st_bday_msg_day_default();
    $o = ['text' => $text, 'day_text' => $dt, 'has_photo' => $photo !== '', 'default' => st_bday_msg_default(), 'day_default' => st_bday_msg_day_default()]; if ($withPhoto) $o['photo'] = $photo;
    return $o;
}
function st_bday_msg_save(PDO $pdo, array $in): array
{
    $cur = st_bday_msg($pdo, true); $text = $cur['text']; $day = $cur['day_text']; $photo = (string)($cur['photo'] ?? '');
    if (!empty($in['reset'])) { $text = st_bday_msg_default(); $day = st_bday_msg_day_default(); }
    else foreach (['text', 'day_text'] as $k) if (isset($in[$k])) {
        $t = trim(str_replace("\r", '', (string)$in[$k])); if ($t === '') return ['error' => 'The message cannot be empty.']; if (mb_strlen($t) > 900) return ['error' => 'The message is too long (900 letters at most).'];
        if ($k === 'text') $text = $t; else $day = $t;
    }
    if (array_key_exists('photo', $in)) { $p = (string)$in['photo']; if ($p === '') $photo = ''; elseif (preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $p) && strlen($p) <= 900000) $photo = $p; else return ['error' => 'The photo must be a JPG / PNG picture under about 600 KB.']; }
    $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('bday_msg', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode(['text' => $text, 'day_text' => $day, 'photo' => $photo], JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true];
}
/** Is this code good for a free 50 ml today? Returns ['ok' => true, 'voucher' => row] or ['ok' => false, 'error' => text]. */
function st_bday_check(PDO $pdo, string $code, ?string $today = null): array
{
    $code = st_bday_norm($code); if ($code === '') return ['ok' => false, 'error' => 'Type the birthday code the customer received on WhatsApp (like BD-7K3M9P).'];
    $today = $today ?? date('Y-m-d');
    $q = $pdo->prepare("SELECT * FROM st_bday_vouchers WHERE code = ?"); $q->execute([$code]); $v = $q->fetch();
    if (!$v) return ['ok' => false, 'error' => 'Code ' . $code . ' is not a birthday code of ours. Check the spelling.'];
    if ($v['status'] === 'redeemed') return ['ok' => false, 'error' => 'This code was already used on ' . substr((string)$v['redeemed_at'], 0, 10) . ' (' . $v['name'] . ').'];
    if ($v['status'] !== 'active') return ['ok' => false, 'error' => 'This code is cancelled.'];
    if ($today < $v['valid_from']) return ['ok' => false, 'error' => 'This code starts on ' . date('j M Y', strtotime($v['valid_from'])) . '.'];
    if ($today > $v['valid_to']) return ['ok' => false, 'error' => 'This code expired on ' . date('j M Y', strtotime($v['valid_to'])) . '.'];
    if (st_gift_given($pdo, (string)$v['phone'], (int)substr($today, 0, 4))) return ['ok' => false, 'error' => $v['name'] . ' already took this year’s birthday gift.'];
    return ['ok' => true, 'voucher' => $v];
}
function st_bday_public(array $v): array
{
    $dt = st_bday_next((string)$v['bday'], strtotime((string)$v['valid_from'])) ?: strtotime((string)$v['valid_to']);
    return ['id' => (int)$v['voucher_id'], 'code' => (string)$v['code'], 'name' => (string)$v['name'], 'phone' => (string)$v['phone'], 'bday' => (string)$v['bday'], 'birthday' => date('j M', $dt),
        'valid_from' => (string)$v['valid_from'], 'valid_to' => (string)$v['valid_to'], 'status' => (string)$v['status'], 'wa_status' => (string)$v['wa_status'], 'wa_note' => (string)$v['wa_note'], 'wa_at' => $v['wa_at'], 'redeemed_at' => $v['redeemed_at']];
}
/** Once a day by itself (after 9 AM), like the daily sync: the first request claims the day and does the work after its own answer has gone out. */
function st_bday_daily(PDO $pdo): void
{
    $day = sys_get_temp_dir() . '/hrms_st_bday_day'; $try = sys_get_temp_dir() . '/hrms_st_bday_try'; $today = date('Y-m-d');
    if ((int)date('G') < 9 || @file_get_contents($day) === $today) return;
    if (is_file($try) && time() - (int)@filemtime($try) < 1800) return;
    @file_put_contents($try, (string)time());
    register_shutdown_function(function () use ($pdo, $day, $today) {
        if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true); @set_time_limit(120);
        try { ensure_st_tables($pdo); st_bday_ensure_template($pdo); $r = st_bday_run($pdo, true); if (!$r['waiting'] && !$r['failed']) @file_put_contents($day, $today); else error_log('st_bday_daily: ' . json_encode($r)); }
        catch (Throwable $e) { error_log('st_bday_daily: ' . $e->getMessage()); }
    });
}

function st_bday_voucher_for(PDO $pdo, string $phone, int $year): ?array
{
    try { $q = $pdo->prepare("SELECT * FROM st_bday_vouchers WHERE phone = ? AND bday_year = ?"); $q->execute([$phone, $year]); $v = $q->fetch(); return $v ? st_bday_public($v) : null; } catch (Throwable $e) { return null; }
}

/** Every store bill of a range with the commission the ONLINE engine's rules pay for it (price chart, a matching offer, the order-code rule), no over-target extra.
 *  The code rule works per person per day, so changing who billed one bill recounts the other bills of both people. Returns [] rows + counters. */
if (!function_exists('budget_store_calc')) {
    /** the online commission engine (boss.php → boss2.php cm_*) is loaded and ready */
    function budget_engine_ready(): bool
    {
        try { if (!function_exists('cm_chart') && is_file(__DIR__ . '/boss.php')) require_once __DIR__ . '/boss.php'; } catch (Throwable $e) { error_log('commission engine: ' . $e->getMessage()); }
        return function_exists('cm_chart') && function_exists('cm_pct') && function_exists('cm_offers') && function_exists('cm_offer_match') && function_exists('cm_code_need');
    }
    /** The people whose store bills count as ONE team for the order-code rule (default: Neil, Krish and Anil). Saved by Total Budgeting → Store; anybody else who bills is counted alone. */
    function st_pool_ids(PDO $pdo): array
    {
        $j = null; try { if (function_exists('setting_get')) $j = json_decode((string)setting_get($pdo, 'store_pool', ''), true); } catch (Throwable $e) {}
        if (is_array($j)) return array_values(array_unique(array_filter(array_map('intval', $j))));
        $ids = [];
        try { foreach ($pdo->query("SELECT employee_id, full_name FROM employees WHERE status != 'ex'")->fetchAll() as $r) { $fn = strtolower((string)strtok(trim((string)$r['full_name']), ' ')); if (in_array($fn, ['neil', 'krish', 'anil'], true)) $ids[] = (int)$r['employee_id']; } } catch (Throwable $e) {}
        return $ids;
    }
    /** Old POS bills (pos_orders, kept in this database) of the days in the range that have NO bill in the new store POS, shaped like st_bills rows. So the days before the new POS started (e.g. 1–3 November) still count. */
    function budget_oldpos_rows(PDO $pdo, string $from, string $to, array $newDays): array
    {
        $h = function_exists('st_day_hour') ? st_day_hour() : 3; $dd = "DATE(DATE_SUB(o.created_at, INTERVAL $h HOUR))";
        $lo = $from . sprintf(' %02d:00:00', $h); $hi = date('Y-m-d', strtotime($to . ' +1 day')) . sprintf(' %02d:00:00', $h);
        $rows = [];
        try {
            $hasCode = false; try { $hasCode = (bool)$pdo->query("SHOW COLUMNS FROM pos_orders LIKE 'order_code'")->fetch(); } catch (Throwable $e) {}
            $q = $pdo->prepare("SELECT o.id, $dd d, o.created_at, o.total, o.billed_by" . ($hasCode ? ", o.order_code" : "") . " FROM pos_orders o WHERE o.created_at >= ? AND o.created_at < ? ORDER BY o.id");
            $q->execute([$lo, $hi]); $ord = $q->fetchAll(); if (!$ord) return [];
            $ml = [];
            $qi = $pdo->prepare("SELECT oi.order_id, oi.variant_title vt, oi.title t, oi.qty q FROM pos_order_items oi JOIN pos_orders o ON o.id = oi.order_id WHERE o.created_at >= ? AND o.created_at < ?");
            $qi->execute([$lo, $hi]);
            foreach ($qi->fetchAll() as $r) { if (preg_match('/(\d+)\s*ml/i', (string)$r['vt'], $m) || preg_match('/(\d+)\s*ml/i', (string)$r['t'], $m)) $ml[(int)$r['order_id']] = ($ml[(int)$r['order_id']] ?? 0) + (int)$m[1] * (int)$r['q']; }
            $emps = $pdo->query("SELECT employee_id, full_name FROM employees WHERE status != 'ex'")->fetchAll(); $byFull = []; $byFirst = [];
            foreach ($emps as $e) { $fn = strtolower(trim(preg_replace('/\s+/', ' ', (string)$e['full_name']))); $byFull[$fn] = $e; $f1 = (string)strtok($fn, ' '); $byFirst[$f1][] = $e; }
            foreach ($ord as $o) {
                if (isset($newDays[(string)$o['d']])) continue;
                $nm = trim((string)$o['billed_by']); $k = strtolower(trim(preg_replace('/\s+/', ' ', $nm))); $e = $byFull[$k] ?? null;
                if (!$e) { $f1 = (string)strtok($k, ' '); if (isset($byFirst[$f1]) && count($byFirst[$f1]) === 1) $e = $byFirst[$f1][0]; }
                $rows[] = ['bill_id' => -(int)$o['id'], 'bill_no' => 'POS-' . (int)$o['id'], 'biz_date' => (string)$o['d'], 'created_at' => (string)$o['created_at'], 'emp_id' => $e ? (int)$e['employee_id'] : 0,
                    'emp_name' => $e ? (string)$e['full_name'] : ($nm !== '' ? $nm : 'Old POS'), 'order_code' => $hasCode ? (string)($o['order_code'] ?? '') : '', 'total_ml' => (int)($ml[(int)$o['id']] ?? 0), 'total' => (float)$o['total'], 'real_id' => $e ? (int)$e['employee_id'] : null, 'src' => 'old_pos'];
            }
        } catch (Throwable $e) { error_log('budget_oldpos_rows: ' . $e->getMessage()); return []; }
        return $rows;
    }
    function budget_store_calc(PDO $pdo, string $from, string $to): array
    {
        static $memo = []; $mk = $from . '|' . $to; if (isset($memo[$mk])) return $memo[$mk];
        $ch = cm_chart($pdo); $offers = cm_offers($pdo); $rows = []; $groups = []; $noMl = 0; $unpriced = 0; $pool = array_flip(st_pool_ids($pdo));
        if (function_exists('ensure_st_tables')) ensure_st_tables($pdo);
        $q = $pdo->prepare("SELECT b.bill_id, b.bill_no, b.biz_date, b.created_at, b.emp_id, b.emp_name, b.order_code, b.total_ml, b.total, e.employee_id AS real_id
            FROM st_bills b LEFT JOIN employees e ON e.employee_id = b.emp_id AND e.status != 'ex' WHERE b.status = 'ok' AND b.biz_date BETWEEN ? AND ? ORDER BY b.bill_id");
        $q->execute([$from, $to]);
        $all = $q->fetchAll(); $newDays = []; foreach ($all as $b) $newDays[(string)$b['biz_date']] = 1;
        foreach (budget_oldpos_rows($pdo, $from, $to, $newDays) as $b) $all[] = $b;     // days with no bill in the new POS come from the old POS
        foreach ($all as $i => $b) { $rows[$i] = $b; $eid = (int)$b['emp_id']; $gk = isset($pool[$eid]) ? 'pool' : ($eid > 0 ? (string)$eid : 'n:' . $b['emp_name']); $groups[$gk . '|' . $b['biz_date']][] = $i; }
        $out = [];
        foreach ($groups as $k => $idx) {
            $n = count($idx); $nCode = 0;
            foreach ($idx as $i) if (trim((string)$rows[$i]['order_code']) !== '') $nCode++;
            $need = cm_code_need($n, (float)$ch['codepct']); $codeOk = $need > 0 && $nCode >= $need; $nocodeF = (float)$ch['nocode'] / 100;
            foreach ($idx as $i) {
                $b = $rows[$i]; $day = (string)$b['biz_date']; $gross = (float)$b['total']; $ml = (int)$b['total_ml']; $hasCode = trim((string)$b['order_code']) !== '';
                $f = ($hasCode || $codeOk) ? 1.0 : $nocodeF; $com = 0.0; $pct = null; $rule = '';
                $of = $ml ? cm_offer_match($offers, $ml, $gross, $day, (float)$ch['window']) : null;
                if ($of) { $pct = (float)$of['pct']; $rule = 'offer'; $com = $gross * $pct / 100 * $f; }
                elseif (!$ml) { $noMl++; $rule = 'no ML'; }
                else { $pc = cm_pct($ch, $ml, $gross); if ($pc === null) { $unpriced++; $rule = 'ML not priced'; } else { $pct = (float)$pc; $rule = 'chart'; $com = $gross * $pct / 100 * $f; } }
                $out[$i] = ['bill_id' => (int)$b['bill_id'], 'bill_no' => (string)$b['bill_no'], 'day' => $day, 'time' => substr((string)$b['created_at'], 11, 5), 'emp_id' => (int)$b['emp_id'],
                    'employee_id' => $b['real_id'] ? (int)$b['emp_id'] : 0, 'name' => (string)$b['emp_name'], 'code' => $hasCode, 'total' => $gross, 'ml' => $ml,
                    'pct' => $pct, 'rule' => $rule, 'reduced' => $f < 1.0, 'com' => round($com, 2), 'pooled' => isset($pool[(int)$b['emp_id']]), 'src' => (string)($b['src'] ?? 'new_pos')];
            }
        }
        ksort($out);
        return $memo[$mk] = ['bills' => array_values($out), 'no_ml' => $noMl, 'unpriced' => $unpriced];
    }
}

