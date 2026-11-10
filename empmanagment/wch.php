<?php
/**
 * wch.php — chain + perfume WhatsApp cart, separate GST bill, catalog sync (module "wch").
 *
 * Put this file in the SAME folder as api.php (empmanagment/). It is loaded by wa_more.php (BEFORE wa_seg.php, which must stay the last require),
 * creates its own tables (wch_*) the first time it runs and changes NO existing table. PHP 7.4 safe. No library: the PDF is written by hand.
 *
 * What it does
 *   sync      products + variants (size in ml, variant id, price, compare-at, stock, image) from Shopify into wch_prod / wch_var; chains vs perfumes detected and confirmed once (mapping).
 *   suggest   chain share = split % x the agreed total; up to 3 chains whose MRP is just above the share; two chains for big orders; a gap suggestion when the catalog has no fitting chain.
 *   cart      the salesperson builds the cart in the chat (total, ml, perfume lines); chain options go to the customer with photos; the customer's number (1/2/3/no chain, Hindi / Hinglish / Gujarati too)
 *             is read by an inbound hook; NO cart / draft order exists before the choice.
 *   order     Shopify draft order (line prices add up exactly to the agreed total), invoice link sent on WhatsApp, payment polled in the tick, order matched (prepaid after payment, COD at creation).
 *   bill      ONE GST invoice per order made by us: perfumes and chain in two sections, CGST+SGST (Gujarat) or IGST, exact paise, atomic numbering TM/26-27/00001, PDF in pure PHP + secure token view.
 *   messages  catalogue of every customer message of the flow with status; edit text / photo; templates through the existing template system.
 *   claude    "What I understood" + questions (Teach-bot style) for billing and catalog; the AI is optional, plain rules otherwise.
 *
 * Actions: wch.sync wch.sync_status wch.scope_check wch.mapping wch.search wch.coverage wch.cart_new wch.cart_set wch.cart_get wch.cart_list wch.chain_suggest wch.chain_send wch.chain_pick
 *          wch.cart_link wch.cart_status wch.cart_cancel wch.billing wch.bill_preview wch.bill wch.bill_make wch.bill_resend wch.bills wch.view wch.pdf wch.messages wch.message_save
 *          wch.messages_submit wch.claude_state wch.claude_next wch.claude_answer wch.settings wch.status wch.tick       (contract: /work/wa/Q_M2_api.md)
 * Hooks (all gated with function_exists in the other files): wch_after_in, wch_tick.
 * Kill switch: setting wch_on (default 0). While 0 nothing is sent to a customer and no Shopify write happens; reads and cart building still work.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wch_tables')) {

// ------------------------------------------------------------------------------------------------------------------ small helpers
function wch_now(): int { return defined('WCH_NOW') ? (int)WCH_NOW : time(); }
function wch_dt(int $ts = 0): string { return date('Y-m-d H:i:s', $ts > 0 ? $ts : wch_now()); }
function wch_ts($s): int { $t = $s === null || $s === '' ? 0 : strtotime((string)$s); return $t ? (int)$t : 0; }
function wch_in(array $a): string { return implode(',', array_fill(0, max(1, count($a)), '?')); }
function wch_log(PDO $pdo, string $info, bool $ok = true): void { if (function_exists('wa_log')) { try { wa_log($pdo, 'wch', $ok, $info); } catch (Throwable $e) { } } }
/** Rupees (float / string) -> integer paise. */
function wch_p($rupees): int { return (int)round(((float)$rupees) * 100); }
/** Paise -> rupees float with 2 decimals. */
function wch_r(int $p): float { return round($p / 100, 2); }
/** "Rs.1,500" or "Rs.1,500.50" (the core PDF font has no rupee sign; chat texts use the same wording). */
function wch_rs(int $p): string { return 'Rs.' . ($p % 100 === 0 ? number_format($p / 100, 0, '.', ',') : number_format($p / 100, 2, '.', ',')); }
function wch_amount_str(int $p): string { return number_format($p / 100, 2, '.', ''); }
function wch_phone10($p): string { $d = preg_replace('/\D+/', '', (string)$p); return strlen($d) >= 10 ? substr($d, -10) : ''; }
function wch_json($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function wch_arr($s): array { $a = json_decode((string)$s, true); return is_array($a) ? $a : []; }
function wch_short(string $s, int $n): string { $s = trim((string)preg_replace('/\s+/u', ' ', $s)); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '~' : $s; }
function wch_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ------------------------------------------------------------------------------------------------------------------ settings
function wch_defaults(): array
{
    return ['wch_on' => '1', 'wch_split_pct' => '50', 'wch_window_pct' => '35', 'wch_near_pct' => '80', 'wch_max_chains' => '2', 'wch_max_options' => '3', 'wch_remind_min' => '30', 'wch_pay_remind_min' => '120',
        'wch_expire_hours' => '48', 'wch_slab_from' => '1000', 'wch_slab_to' => '5000', 'wch_slab_step' => '500', 'wch_view_days' => '365', 'wch_ship_free' => '1', 'wch_sync_every_min' => '180', 'wch_poll_sec' => '60', 'wch_api_version' => '2025-10'];
}
function wch_limits(): array
{
    return ['wch_split_pct' => [1, 95], 'wch_window_pct' => [0, 200], 'wch_near_pct' => [10, 100], 'wch_max_chains' => [1, 3], 'wch_max_options' => [1, 5], 'wch_remind_min' => [5, 1440], 'wch_pay_remind_min' => [10, 2880],
        'wch_expire_hours' => [1, 720], 'wch_slab_from' => [100, 100000], 'wch_slab_to' => [200, 200000], 'wch_slab_step' => [100, 50000], 'wch_view_days' => [1, 3650], 'wch_sync_every_min' => [10, 1440], 'wch_poll_sec' => [10, 600]];
}
/** One setting. All wch_* settings are read with ONE query per request (the memo is refreshed by wch_put). */
function wch_get(PDO $pdo, string $k, $d = null)
{
    $m = &$GLOBALS['__wch_set'];
    if (empty($GLOBALS['__wch_loaded'])) {
        $GLOBALS['__wch_loaded'] = true; $m = $m ?: [];
        try { foreach ($pdo->query("SELECT setting_key, setting_value FROM hrms_settings WHERE setting_key LIKE 'wch\\_%'")->fetchAll() as $r) if (!isset($m[$r['setting_key']])) $m[$r['setting_key']] = [(string)$r['setting_value']]; } catch (Throwable $e) { }
    }
    if (!isset($m[$k])) { if (strpos($k, 'wch_') === 0) return $d; try { $q = $pdo->prepare("SELECT setting_value FROM hrms_settings WHERE setting_key = ?"); $q->execute([$k]); $v = $q->fetchColumn(); $m[$k] = [$v === false ? null : $v]; } catch (Throwable $e) { $m[$k] = [null]; } }
    return $m[$k][0] === null ? $d : $m[$k][0];
}
function wch_put(PDO $pdo, string $k, $v): void
{
    try { $pdo->prepare("INSERT INTO hrms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, (string)$v]); } catch (Throwable $e) { return; }
    $GLOBALS['__wch_set'][$k] = [(string)$v];
}
/** One time only: the cart and order flow starts switched ON (it used to start OFF). Turning it off later in Learn & Setup > Cart messages is kept. */
function wch_on_once(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    if ((string)wch_get($pdo, 'wch_on_auto_v1', '') === '1') return;
    wch_put($pdo, 'wch_on', '1'); wch_put($pdo, 'wch_on_auto_v1', '1');
}
function wch_cfg(PDO $pdo): array
{
    wch_on_once($pdo);
    $o = []; foreach (wch_defaults() as $k => $d) { $v = wch_get($pdo, $k, null); $o[$k] = $v === null || $v === '' ? $d : (string)$v; }
    foreach (wch_limits() as $k => [$lo, $hi]) $o[$k] = max($lo, min($hi, (int)$o[$k]));
    $o['wch_on'] = (string)$o['wch_on'] === '1' ? 1 : 0; $o['wch_ship_free'] = (string)$o['wch_ship_free'] === '0' ? 0 : 1;
    if ($o['wch_slab_to'] <= $o['wch_slab_from']) $o['wch_slab_to'] = $o['wch_slab_from'] + $o['wch_slab_step'];
    return $o;
}
function wch_on(PDO $pdo): bool { wch_on_once($pdo); return (string)wch_get($pdo, 'wch_on', '1') === '1'; }
function wch_meta_get(PDO $pdo, string $k, string $d = ''): string { try { $q = $pdo->prepare("SELECT v FROM wch_meta WHERE k = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; } }
function wch_meta_put(PDO $pdo, string $k, string $v): void { try { $pdo->prepare("INSERT INTO wch_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]); } catch (Throwable $e) { } }

// ------------------------------------------------------------------------------------------------------------------ tables
function wch_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    $flag = sys_get_temp_dir() . '/hrms_wch_tables_v2_' . md5(defined('DB_NAME') ? (string)DB_NAME : 'x');   // the same trick api.php uses: the CREATE TABLE statements run once, not on every webhook
    if (is_file($flag)) { try { $pdo->query("SELECT 1 FROM wch_msg LIMIT 1"); return $ok = true; } catch (Throwable $e) { @unlink($flag); } }
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_meta (k VARCHAR(60) NOT NULL PRIMARY KEY, v MEDIUMTEXT NULL) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_prod (pid BIGINT NOT NULL PRIMARY KEY, handle VARCHAR(255) NOT NULL DEFAULT '', title VARCHAR(255) NOT NULL DEFAULT '', short VARCHAR(60) NOT NULL DEFAULT '', ptype VARCHAR(120) NOT NULL DEFAULT '',
            vendor VARCHAR(120) NOT NULL DEFAULT '', tags_txt TEXT NULL, colls_json TEXT NULL, kind VARCHAR(8) NOT NULL DEFAULT 'other', kind_manual VARCHAR(8) NOT NULL DEFAULT '', photo VARCHAR(500) NOT NULL DEFAULT '', photos_json TEXT NULL,
            url VARCHAR(500) NOT NULL DEFAULT '', stock_total INT NULL, min_price_p BIGINT NOT NULL DEFAULT 0, max_price_p BIGINT NOT NULL DEFAULT 0, units INT NOT NULL DEFAULT 0, search_txt TEXT NULL, variants_trunc TINYINT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1, seen_pass INT NOT NULL DEFAULT 0, synced_at DATETIME NULL, KEY k_kind (kind, active), KEY k_pass (seen_pass)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_var (vid BIGINT NOT NULL PRIMARY KEY, pid BIGINT NOT NULL, sku VARCHAR(80) NOT NULL DEFAULT '', title VARCHAR(160) NOT NULL DEFAULT '', ml INT NULL, price_p BIGINT NOT NULL DEFAULT 0,
            compare_p BIGINT NULL, stock INT NULL, policy VARCHAR(10) NOT NULL DEFAULT '', avail TINYINT NOT NULL DEFAULT 1, photo VARCHAR(500) NOT NULL DEFAULT '', pos INT NOT NULL DEFAULT 0, seen_pass INT NOT NULL DEFAULT 0, KEY k_pid (pid), KEY k_pass (seen_pass)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_cart (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, status VARCHAR(12) NOT NULL DEFAULT 'building', total_p BIGINT NOT NULL DEFAULT 0, ml INT NOT NULL DEFAULT 0,
            split_pct DECIMAL(5,2) NOT NULL DEFAULT 50, lines_json MEDIUMTEXT NULL, opts_json MEDIUMTEXT NULL, options_sent_at DATETIME NULL, reminder_sent_at DATETIME NULL, escalated_at DATETIME NULL, picked VARCHAR(60) NOT NULL DEFAULT '', picked_by VARCHAR(12) NOT NULL DEFAULT '',
            picked_at DATETIME NULL, cust_reply TEXT NULL, needs_manual TINYINT NOT NULL DEFAULT 0, plan_json MEDIUMTEXT NULL, draft_gid VARCHAR(120) NOT NULL DEFAULT '', draft_name VARCHAR(40) NOT NULL DEFAULT '', draft_url VARCHAR(600) NOT NULL DEFAULT '',
            draft_status VARCHAR(16) NOT NULL DEFAULT '', draft_at DATETIME NULL, link_sent_at DATETIME NULL, last_poll_at DATETIME NULL, pay_reminded_at DATETIME NULL, order_gid VARCHAR(120) NOT NULL DEFAULT '', order_sid VARCHAR(40) NOT NULL DEFAULT '',
            order_name VARCHAR(40) NOT NULL DEFAULT '', order_mode VARCHAR(8) NOT NULL DEFAULT '', order_paid TINYINT NOT NULL DEFAULT 0, order_at DATETIME NULL, order_total_p BIGINT NULL, ship_json TEXT NULL, order_conf_at DATETIME NULL, bill_err VARCHAR(200) NOT NULL DEFAULT '',
            inv_id INT NOT NULL DEFAULT 0, cancel_reason VARCHAR(200) NOT NULL DEFAULT '', expires_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY k_phone (phone, status), KEY k_status (status, updated_at), KEY k_order (order_name), KEY k_exp (status, expires_at)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_cart_ev (id INT AUTO_INCREMENT PRIMARY KEY, cart_id INT NOT NULL, at DATETIME NOT NULL, kind VARCHAR(20) NOT NULL, text VARCHAR(300) NOT NULL DEFAULT '', KEY k_cart (cart_id, id)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_seq (fy VARCHAR(8) NOT NULL PRIMARY KEY, last_no INT NOT NULL DEFAULT 0) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_inv (id INT AUTO_INCREMENT PRIMARY KEY, inv_no VARCHAR(20) NOT NULL, fy VARCHAR(8) NOT NULL, seq INT NOT NULL, cart_id INT NOT NULL DEFAULT 0, order_name VARCHAR(40) NOT NULL DEFAULT '', order_sid VARCHAR(40) NOT NULL DEFAULT '',
            phone VARCHAR(12) NOT NULL DEFAULT '', customer VARCHAR(120) NOT NULL DEFAULT '', total_p BIGINT NOT NULL DEFAULT 0, token CHAR(32) NOT NULL, token_exp DATETIME NOT NULL, data_json MEDIUMTEXT NULL, sent_at DATETIME NULL, send_err VARCHAR(160) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL, UNIQUE KEY u_no (inv_no), UNIQUE KEY u_fy (fy, seq), UNIQUE KEY u_token (token), UNIQUE KEY u_cart (cart_id), KEY k_order (order_name), KEY k_created (created_at)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_msg (mkey VARCHAR(40) NOT NULL PRIMARY KEY, text TEXT NULL, tpl_text TEXT NULL, photo_url VARCHAR(500) NOT NULL DEFAULT '', photo_lib_id INT NOT NULL DEFAULT 0, tpl_id INT NOT NULL DEFAULT 0, tpl_body_hash CHAR(32) NOT NULL DEFAULT '',
            updated_by INT NOT NULL DEFAULT 0, updated_at DATETIME NULL) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wch_once (okey VARCHAR(80) NOT NULL PRIMARY KEY, at DATETIME NOT NULL) $E");
        // v2: stock is no longer used - clear the out-of-stock flags and counts synced earlier, so every size can go in a cart straight away
        try { $pdo->exec("UPDATE wch_var SET avail = 1, stock = NULL WHERE avail <> 1 OR stock IS NOT NULL"); $pdo->exec("UPDATE wch_prod SET stock_total = NULL WHERE stock_total IS NOT NULL"); } catch (Throwable $e) { error_log('wch_tables v2: ' . $e->getMessage()); }
        $ok = true; @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('wch_tables: ' . $e->getMessage()); $ok = false; }
    return $ok;
}
/** Creates the tables this module reads from other modules when they do not exist yet (fresh install / tests). */
function wch_deps(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    foreach (['wa_tables', 'wa_more_tables', 'wag_tables', 'wa_prod_tables', 'wa_tpl_tables'] as $f) { if (function_exists($f)) { try { $f($pdo); } catch (Throwable $e) { } } }
}
/** Idempotency: true only the first time this key is claimed. */
function wch_once(PDO $pdo, string $key): bool
{
    try { $s = $pdo->prepare("INSERT IGNORE INTO wch_once (okey, at) VALUES (?, ?)"); $s->execute([mb_substr($key, 0, 80), wch_dt()]); return $s->rowCount() > 0; } catch (Throwable $e) { return false; }
}

// ------------------------------------------------------------------------------------------------------------------ India: states, GST codes, Shopify province codes
function wch_states(): array
{
    return ['01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh', '24' => 'Gujarat', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu',
        '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh'];
}
function wch_state_codes_iso(): array
{
    return ['JK' => '01', 'HP' => '02', 'PB' => '03', 'CH' => '04', 'UT' => '05', 'UK' => '05', 'HR' => '06', 'DL' => '07', 'RJ' => '08', 'UP' => '09', 'BR' => '10', 'SK' => '11', 'AR' => '12', 'NL' => '13', 'MN' => '14', 'MZ' => '15', 'TR' => '16',
        'ML' => '17', 'AS' => '18', 'WB' => '19', 'JH' => '20', 'OD' => '21', 'OR' => '21', 'CG' => '22', 'CT' => '22', 'MP' => '23', 'GJ' => '24', 'DH' => '26', 'DN' => '26', 'DD' => '26', 'MH' => '27', 'KA' => '29', 'GA' => '30', 'LD' => '31',
        'KL' => '32', 'TN' => '33', 'PY' => '34', 'AN' => '35', 'TS' => '36', 'TG' => '36', 'AP' => '37', 'LA' => '38'];
}
/** State name / Shopify province code / GST code -> ['code' => '24', 'name' => 'Gujarat'] or null. */
function wch_state_find(string $s): ?array
{
    $s = trim($s); if ($s === '') return null; $st = wch_states();
    if (preg_match('/^\d{2}$/', $s) && isset($st[$s])) return ['code' => $s, 'name' => $st[$s]];
    $u = strtoupper($s); $iso = wch_state_codes_iso(); if (isset($iso[$u])) return ['code' => $iso[$u], 'name' => $st[$iso[$u]]];
    $n = strtolower(trim((string)preg_replace('/[^a-z]+/i', ' ', str_replace('&', ' and ', $s))));
    $alias = ['orissa' => '21', 'uttaranchal' => '05', 'pondicherry' => '34', 'new delhi' => '07', 'nct of delhi' => '07', 'delhi ncr' => '07', 'jammu kashmir' => '01', 'daman and diu' => '26', 'dadra and nagar haveli' => '26', 'dadra nagar haveli' => '26',
        'andaman nicobar islands' => '35', 'andaman and nicobar' => '35', 'telengana' => '36', 'chattisgarh' => '22', 'gujrat' => '24', 'maharastra' => '27', 'tamilnadu' => '33'];
    if (isset($alias[$n])) return ['code' => $alias[$n], 'name' => $st[$alias[$n]]];
    foreach ($st as $c => $name) { if ($n === strtolower(str_replace('&', 'and', $name))) return ['code' => (string)$c, 'name' => $name]; }
    return null;
}

// ------------------------------------------------------------------------------------------------------------------ optional AI (never needed; stubbed in tests)
function wch_ai(PDO $pdo, string $sys, string $ctx, array $schema): array
{
    if (function_exists('wch_ai_override')) return wch_ai_override($sys, $ctx, $schema);
    if (defined('WCH_NO_AI') || !function_exists('convo_ai')) return [false, null, 'The AI is off.'];
    try { return convo_ai($sys, [['text' => $ctx]], $schema, false, 600, 'claude'); } catch (Throwable $e) { return [false, null, 'The AI is not available.']; }
}

// ------------------------------------------------------------------------------------------------------------------ Shopify access (every call goes through wa_prod_shop_token() + wa_http(): the two test seams)
function wch_scope_msg(array $scopes, string $what): string
{
    $q = implode(' and ', array_map(function ($s) { return '"' . $s . '"'; }, $scopes));
    return 'Shopify did not allow ' . $what . '. In Shopify go to Settings > Apps > Develop apps, open the HRMS app, add the access scope' . (count($scopes) > 1 ? 's ' : ' ') . $q . ', save and re-install the app.';
}
/** One Admin GraphQL call. Returns ['ok' => bool, 'data' => array|null, 'error' => string, 'code' => ''|scope_missing|shopify_error|busy, 'user_errors' => array]. */
/** The Shopify access token is kept up to 24 h; a permission added in Shopify after that only reaches HRMS with a NEW token. Forget the kept one (once per request). */
function wch_token_forget(): bool
{
    static $done = false; if ($done) return false; $done = true; $n = 0;
    foreach ((array)glob(sys_get_temp_dir() . '/shopify_token_*.json') as $f) { if (is_file($f) && @unlink($f)) $n++; }
    return $n > 0;
}
function wch_shop(PDO $pdo, string $query, array $vars, array $needScopes, string $what, bool $again = true): array
{
    $bad = function (string $code, string $msg, array $ue = []) { return ['ok' => false, 'data' => null, 'error' => $msg, 'code' => $code, 'user_errors' => $ue]; };
    if (!function_exists('wa_prod_shop_token') || !function_exists('wa_http')) return $bad('shopify_error', 'The Shopify login is not available in this build.');
    [$tok, $err, $domain] = wa_prod_shop_token(); if ($tok === '') return $bad('shopify_error', $err !== '' ? $err : 'Could not log in to Shopify.');
    $need = wch_scope_msg($needScopes, $what);
    for ($try = 0; $try < 3; $try++) {
        $ver = (string)wch_get($pdo, 'wch_api_version', '2025-10'); if (!preg_match('/^\d{4}-(01|04|07|10)$/', $ver)) $ver = '2025-10';
        [$code, $d] = wa_http('POST', 'https://' . $domain . '/admin/api/' . $ver . '/graphql.json', ['Content-Type: application/json', 'X-Shopify-Access-Token: ' . $tok], json_encode(['query' => $query, 'variables' => (object)$vars]));
        $errs = is_array($d) ? ($d['errors'] ?? null) : null; $es = is_array($errs) ? json_encode($errs) : (string)$errs;
        if ($code === 401 || $code === 403 || ($errs && preg_match('/ACCESS_DENIED|access denied|required access|scope/i', $es))) {
            if ($again && wch_token_forget()) return wch_shop($pdo, $query, $vars, $needScopes, $what, false);   // maybe only the kept token is old: ask once more with a fresh one
            return $bad('scope_missing', $need);
        }
        if (($code === 429 || ($errs && preg_match('/THROTTLED/i', $es))) && $try < 2) { if (!defined('WCH_NO_SLEEP')) sleep(2); continue; }
        if ($code < 200 || $code >= 300 || !is_array($d)) return $bad('shopify_error', 'Shopify did not answer properly (HTTP ' . $code . ').');
        if ($errs) return $bad('shopify_error', 'Shopify refused the request: ' . mb_substr($es, 0, 200));
        return ['ok' => true, 'data' => (array)($d['data'] ?? []), 'error' => '', 'code' => '', 'user_errors' => []];
    }
    return $bad('busy', 'Shopify is busy. Try again in a minute.');
}
function wch_gid(string $type, $id): string { return 'gid://shopify/' . $type . '/' . $id; }
function wch_gid_num(string $gid): int { return preg_match('/(\d+)\s*$/', $gid, $m) ? (int)$m[1] : 0; }

// ------------------------------------------------------------------------------------------------------------------ products: normalise one Shopify product (the ONE normaliser for the live read and the tests)
function wch_parse_ml(string $title, array $opts): ?int
{
    foreach ($opts as $o) {
        $nm = strtolower((string)($o['name'] ?? '')); $v = trim((string)($o['value'] ?? ''));
        if (preg_match('/(\d{1,4}(?:\.\d+)?)\s*(ml|millilit\w*)\b/i', $v, $m)) return (int)round((float)$m[1]);
        if (preg_match('/size|volume|ml|quantity/', $nm) && preg_match('/^\d{1,4}$/', $v) && (int)$v >= 3 && (int)$v <= 1000) return (int)$v;
    }
    if (preg_match('/(\d{1,4}(?:\.\d+)?)\s*(ml|millilit\w*)\b/i', $title, $m)) return (int)round((float)$m[1]);
    return null;
}
function wch_short_name(string $title, string $kind): string
{
    $t = trim($title);
    $t = preg_replace('/\s*[\-\x{2013}\x{2014}|]\s*(inspired|impression|alternative|our version).*/iu', '', $t) ?? $t;
    $t = preg_replace('/\b\d{1,4}\s*ml\b/i', '', $t) ?? $t;
    if ($kind === 'perfume') $t = preg_replace('/\b(perfume|fragrance|attar|eau de parfum|edp|edt|for (men|women|him|her|unisex)|inspired)\b/iu', '', $t) ?? $t;
    $t = trim((string)preg_replace('/\s+/u', ' ', $t), " \t-\u{2013}|,");
    return mb_substr($t !== '' ? $t : trim($title), 0, 58);
}
/** Shopify product node -> ['p' => product row, 'v' => [variant rows]]. */
function wch_norm_product(array $n): array
{
    $gid = (string)($n['id'] ?? ''); $pid = wch_gid_num($gid); $title = trim((string)($n['title'] ?? ''));
    $tags = []; foreach ((array)($n['tags'] ?? []) as $t) { $t = trim((string)$t); if ($t !== '') $tags[] = mb_substr($t, 0, 60); }
    $colls = []; foreach ((array)($n['collections']['nodes'] ?? ($n['collections'] ?? [])) as $c) if (is_array($c)) $colls[] = ['handle' => (string)($c['handle'] ?? ''), 'title' => (string)($c['title'] ?? '')];
    $photos = []; $f = (string)($n['featuredImage']['url'] ?? ''); if ($f !== '') $photos[] = $f;
    foreach ((array)($n['images']['nodes'] ?? []) as $im) { $u = (string)($im['url'] ?? ''); if ($u !== '' && !in_array($u, $photos, true)) $photos[] = $u; }
    $vs = []; $minP = 0; $maxP = 0; $stock = 0; $anyTracked = false; $pos = 0;
    foreach ((array)($n['variants']['nodes'] ?? []) as $v) {
        if (!is_array($v)) continue; $vid = wch_gid_num((string)($v['id'] ?? '')); if ($vid <= 0) continue; $pos++;
        $price = wch_p($v['price'] ?? 0); $cmp = isset($v['compareAtPrice']) && $v['compareAtPrice'] !== null && (float)$v['compareAtPrice'] > 0 ? wch_p($v['compareAtPrice']) : null;
        // Every size is sold as in stock: Shopify's stock counts are not kept up to date, so they are not used to block or hide anything in the cart.
        // (The cart goes out as a Shopify draft order, which accepts items Shopify counts as 0.)
        $qty = null; $pol = strtoupper((string)($v['inventoryPolicy'] ?? '')); $avail = 1;
        $vt = trim((string)($v['title'] ?? '')); if (strcasecmp($vt, 'Default Title') === 0) $vt = '';
        $ml = wch_parse_ml($vt, (array)($v['selectedOptions'] ?? []));
        if ($price > 0) { $minP = $minP === 0 ? $price : min($minP, $price); $maxP = max($maxP, $price); }
        if ($qty !== null) { $anyTracked = true; $stock += max(0, $qty); }
        $vs[] = ['vid' => $vid, 'sku' => mb_substr(trim((string)($v['sku'] ?? '')), 0, 80), 'title' => mb_substr($vt, 0, 160), 'ml' => $ml, 'price_p' => $price, 'compare_p' => $cmp, 'stock' => $qty, 'policy' => $pol, 'avail' => $avail,
            'photo' => mb_substr((string)($v['image']['url'] ?? ''), 0, 500), 'pos' => $pos];
    }
    $row = ['pid' => $pid, 'handle' => mb_substr((string)($n['handle'] ?? ''), 0, 255), 'title' => mb_substr($title, 0, 255), 'ptype' => mb_substr(trim((string)($n['productType'] ?? '')), 0, 120), 'vendor' => mb_substr(trim((string)($n['vendor'] ?? '')), 0, 120),
        'tags' => $tags, 'colls' => $colls, 'photo' => mb_substr($photos[0] ?? '', 0, 500), 'photos' => array_slice($photos, 0, 4), 'url' => mb_substr((string)($n['onlineStoreUrl'] ?? ''), 0, 500),
        'stock_total' => null,   // stock is not tracked here (see above)
        'min_p' => (int)($minP ?? 0), 'max_p' => (int)($maxP ?? 0),
        'active' => strtoupper((string)($n['status'] ?? 'ACTIVE')) === 'ACTIVE' ? 1 : 0, 'trunc' => !empty($n['variants']['pageInfo']['hasNextPage']) ? 1 : 0];
    if ($row['url'] === '' && $row['handle'] !== '') $row['url'] = 'https://tmperfumehouse.com/products/' . $row['handle'];
    return ['p' => $row, 'v' => $vs];
}

// ------------------------------------------------------------------------------------------------------------------ chain / perfume detection and the confirmed mapping
function wch_rx_chain(): string { return '/\b(chains?|necklaces?|pendants?|bracelets?|lockets?|mala|kada|jewell?ery|rings?)\b/i'; }
function wch_rx_perfume(): string { return '/perfume|attar|fragrance|parfum|eau de|body mist|deodorant|\bedp\b|\bedt\b|inspired|\boud\b|\bcologne\b/i'; }
function wch_rx_set(): string { return '/\b(combo|set|kit|pack|gift|bundle|hamper|trial)\b|\d+\s*x\s*\d+/i'; }
function wch_map_get(PDO $pdo): array
{
    $m = wch_arr(wch_meta_get($pdo, 'mapping', '')); $blank = ['collection' => '', 'type' => '', 'tag' => ''];
    return ['confirmed' => !empty($m['confirmed']), 'confirmed_at' => $m['confirmed_at'] ?? null, 'chain' => array_merge($blank, (array)($m['chain'] ?? [])), 'perfume' => array_merge($blank, (array)($m['perfume'] ?? []))];
}
function wch_rule_hit(array $r, array $p): bool
{
    $c = strtolower(trim((string)($r['collection'] ?? ''))); $t = strtolower(trim((string)($r['type'] ?? ''))); $g = strtolower(trim((string)($r['tag'] ?? '')));
    if ($c !== '') foreach ($p['colls'] as $x) if (strtolower($x['handle']) === $c || strtolower($x['title']) === $c) return true;
    if ($t !== '' && strtolower($p['ptype']) === $t) return true;
    if ($g !== '') foreach ($p['tags'] as $x) if (strtolower($x) === $g) return true;
    return false;
}
function wch_rule_empty(array $r): bool { return trim((string)($r['collection'] ?? '') . ($r['type'] ?? '') . ($r['tag'] ?? '')) === ''; }
/** The words-based guess, without any confirmed rule. Returns [kind, unclear, why]. */
function wch_auto_kind(array $p): array
{
    $coll = ''; foreach ($p['colls'] as $c) $coll .= ' ' . $c['handle'] . ' ' . $c['title'];
    $tp = $p['ptype']; $isSet = (bool)preg_match(wch_rx_set(), $p['title']);
    $chT = (bool)preg_match(wch_rx_chain(), $tp); $peT = (bool)preg_match(wch_rx_perfume(), $tp);
    $chN = (bool)preg_match(wch_rx_chain(), $p['title']); $peN = (bool)preg_match(wch_rx_perfume(), $p['title']);
    $chC = (bool)preg_match(wch_rx_chain(), $coll . ' ' . implode(' ', $p['tags'])); $peC = (bool)preg_match(wch_rx_perfume(), $coll . ' ' . implode(' ', $p['tags']));
    if ($chT && !$peT) return ['chain', false, 'product type'];
    if ($peT && !$chT) return $isSet ? ['other', true, 'looks like a set / combo'] : ['perfume', false, 'product type'];
    if ($chN && $peN) return ['other', true, 'name mentions both a chain and a perfume'];
    if ($chN) return ['chain', false, 'name'];
    if ($peN) return $isSet ? ['other', true, 'looks like a set / combo'] : ['perfume', false, 'name'];
    if ($chC && !$peC) return ['chain', false, 'collection / tag'];
    if ($peC && !$chC) return $isSet ? ['other', true, 'looks like a set / combo'] : ['perfume', false, 'collection / tag'];
    return ['other', $isSet, 'nothing matched'];
}
function wch_classify(array $p, array $map): array
{
    if ($p['kind_manual'] !== '' && in_array($p['kind_manual'], ['chain', 'perfume', 'other'], true)) return [$p['kind_manual'], false, 'set by hand'];
    if (!empty($map['confirmed'])) {
        $c = !wch_rule_empty($map['chain']) && wch_rule_hit($map['chain'], $p); $f = !wch_rule_empty($map['perfume']) && wch_rule_hit($map['perfume'], $p);
        if ($c && !$f) return ['chain', false, 'confirmed rule']; if ($f && !$c) return ['perfume', false, 'confirmed rule'];
        if ($c && $f) return ['other', true, 'matches both rules'];
    }
    return wch_auto_kind($p);
}
function wch_prod_for_class(array $r): array
{
    return ['pid' => (int)$r['pid'], 'title' => (string)$r['title'], 'ptype' => (string)$r['ptype'], 'tags' => array_values(array_filter(array_map('trim', explode(',', (string)$r['tags_txt'])))), 'colls' => wch_arr($r['colls_json']), 'kind_manual' => (string)$r['kind_manual']];
}
/** Re-detect the kind of every product (cheap, in memory). Returns the counts. */
function wch_reclassify(PDO $pdo): array
{
    $map = wch_map_get($pdo); $cnt = ['chain' => 0, 'perfume' => 0, 'other' => 0, 'unclear' => 0, 'changed' => 0];
    $up = $pdo->prepare("UPDATE wch_prod SET kind = ?, short = ? WHERE pid = ?"); $unclear = [];
    foreach ($pdo->query("SELECT pid, title, ptype, tags_txt, colls_json, kind_manual, kind FROM wch_prod WHERE active = 1")->fetchAll() as $r) {
        [$k, $un] = wch_classify(wch_prod_for_class($r), $map); if ($un) { $cnt['unclear']++; $unclear[] = (int)$r['pid']; }
        $cnt[$k]++; if ($k !== $r['kind']) { $cnt['changed']++; } $up->execute([$k, wch_short_name((string)$r['title'], $k), (int)$r['pid']]);
    }
    wch_meta_put($pdo, 'unclear_pids', wch_json(array_slice($unclear, 0, 400)));
    return $cnt;
}
/** Groups (collection / type / tag) that look like "all chains" or "all perfumes". */
function wch_map_candidates(PDO $pdo): array
{
    $g = []; $tot = ['chain' => 0, 'perfume' => 0];
    foreach ($pdo->query("SELECT pid, title, ptype, tags_txt, colls_json, kind_manual FROM wch_prod WHERE active = 1")->fetchAll() as $r) {
        $p = wch_prod_for_class($r); [$k] = wch_auto_kind($p); if ($k !== 'other') $tot[$k]++;
        $keys = []; foreach ($p['colls'] as $c) $keys['collection|' . ($c['handle'] !== '' ? $c['handle'] : $c['title'])] = $c['title'] !== '' ? 'Collection: ' . $c['title'] : 'Collection: ' . $c['handle'];
        if ($p['ptype'] !== '') $keys['type|' . $p['ptype']] = 'Product type: ' . $p['ptype']; foreach ($p['tags'] as $t) $keys['tag|' . $t] = 'Tag: ' . $t;
        foreach ($keys as $key => $label) { if (!isset($g[$key])) $g[$key] = ['label' => $label, 'n' => 0, 'chain' => 0, 'perfume' => 0]; $g[$key]['n']++; if ($k !== 'other') $g[$key][$k]++; }
    }
    $out = ['chain' => [], 'perfume' => []];
    foreach ($g as $key => $x) {
        [$kind, $val] = explode('|', $key, 2);
        foreach (['chain', 'perfume'] as $want) {
            if ($x[$want] < 1 || $tot[$want] < 1) continue; $pure = $x[$want] / $x['n']; $cover = min(1, $x[$want] / $tot[$want]); if ($pure < 0.6) continue;
            $out[$want][] = ['kind' => $kind, 'key' => $val, 'label' => $x['label'], 'products' => $x['n'], 'confidence' => round($pure * (0.5 + 0.5 * $cover), 2)];
        }
    }
    foreach (['chain', 'perfume'] as $w) { usort($out[$w], function ($a, $b) { return $b['confidence'] <=> $a['confidence'] ?: $b['products'] <=> $a['products']; }); $out[$w] = array_slice($out[$w], 0, 6); }
    return $out;
}
function wch_map_save(PDO $pdo, array $in, int $by): array
{
    $cur = wch_map_get($pdo); $blank = ['collection' => '', 'type' => '', 'tag' => ''];
    foreach (['chain', 'perfume'] as $k) if (isset($in[$k]) && is_array($in[$k])) $cur[$k] = ['collection' => mb_substr(trim((string)($in[$k]['collection'] ?? '')), 0, 120), 'type' => mb_substr(trim((string)($in[$k]['type'] ?? '')), 0, 120), 'tag' => mb_substr(trim((string)($in[$k]['tag'] ?? '')), 0, 60)] + $blank;
    if (!empty($in['confirm'])) {
        if (wch_rule_empty($cur['chain']) && wch_rule_empty($cur['perfume'])) return [false, 'Choose which collection, product type or tag means chains or perfumes before confirming.'];
        $cur['confirmed'] = true; $cur['confirmed_at'] = wch_dt();
    }
    wch_meta_put($pdo, 'mapping', wch_json($cur + ['by' => $by]));
    return [true, wch_reclassify($pdo)];
}

// ------------------------------------------------------------------------------------------------------------------ the sync (resumable: a pass can take several runs; a cursor is kept)
function wch_products_query(): string
{
    return 'query($cursor: String) { products(first: 20, after: $cursor, query: "status:active") { pageInfo { hasNextPage endCursor } nodes { id handle title productType vendor tags status totalInventory onlineStoreUrl featuredImage { url } images(first: 2) { nodes { url } } '
        . 'collections(first: 6) { nodes { id handle title } } variants(first: 25) { pageInfo { hasNextPage } nodes { id sku title price compareAtPrice inventoryQuantity inventoryPolicy availableForSale image { url } selectedOptions { name value } } } } } }';
}
/** One page from Shopify (or from a test callable). ['ok','nodes','hasNext','cursor','error','code'] */
function wch_fetch_page(PDO $pdo, ?string $cursor, ?callable $fetch = null): array
{
    if ($fetch) return $fetch($cursor);
    $r = wch_shop($pdo, wch_products_query(), ['cursor' => $cursor], ['read_products'], 'reading products');
    if (!$r['ok']) return ['ok' => false, 'nodes' => [], 'hasNext' => false, 'cursor' => null, 'error' => $r['error'], 'code' => $r['code']];
    $p = (array)($r['data']['products'] ?? []); return ['ok' => true, 'nodes' => (array)($p['nodes'] ?? []), 'hasNext' => !empty($p['pageInfo']['hasNextPage']), 'cursor' => $p['pageInfo']['endCursor'] ?? null, 'error' => '', 'code' => ''];
}
function wch_store_product(PDO $pdo, array $n, int $pass, string $now): string
{
    $x = wch_norm_product($n); $p = $x['p']; if ($p['pid'] <= 0) return 'skip';
    $q = $pdo->prepare("SELECT pid FROM wch_prod WHERE pid = ?"); $q->execute([$p['pid']]); $exists = (bool)$q->fetchColumn();
    $sk = ''; foreach ($x['v'] as $v) $sk .= ' ' . $v['sku'];
    $search = mb_strtolower($p['title'] . ' ' . $p['handle'] . ' ' . implode(' ', $p['tags']) . ' ' . $sk . ' ' . $p['ptype']);
    $pdo->prepare("INSERT INTO wch_prod (pid, handle, title, short, ptype, vendor, tags_txt, colls_json, kind, photo, photos_json, url, stock_total, min_price_p, max_price_p, search_txt, variants_trunc, active, seen_pass, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'other', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE handle = VALUES(handle), title = VALUES(title), ptype = VALUES(ptype), vendor = VALUES(vendor), tags_txt = VALUES(tags_txt), colls_json = VALUES(colls_json),
        photo = VALUES(photo), photos_json = VALUES(photos_json), url = VALUES(url), stock_total = VALUES(stock_total), min_price_p = VALUES(min_price_p), max_price_p = VALUES(max_price_p), search_txt = VALUES(search_txt), variants_trunc = VALUES(variants_trunc),
        active = VALUES(active), seen_pass = VALUES(seen_pass), synced_at = VALUES(synced_at)")
        ->execute([$p['pid'], $p['handle'], $p['title'], wch_short_name($p['title'], 'perfume'), $p['ptype'], $p['vendor'], implode(', ', $p['tags']), wch_json($p['colls']), $p['photo'], wch_json($p['photos']), $p['url'], $p['stock_total'], $p['min_p'], $p['max_p'], $search, $p['trunc'], $p['active'], $pass, $now]);
    $iv = $pdo->prepare("INSERT INTO wch_var (vid, pid, sku, title, ml, price_p, compare_p, stock, policy, avail, photo, pos, seen_pass) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE pid = VALUES(pid), sku = VALUES(sku), title = VALUES(title), ml = VALUES(ml), price_p = VALUES(price_p), compare_p = VALUES(compare_p), stock = VALUES(stock), policy = VALUES(policy), avail = VALUES(avail), photo = VALUES(photo), pos = VALUES(pos), seen_pass = VALUES(seen_pass)");
    foreach ($x['v'] as $v) $iv->execute([$v['vid'], $p['pid'], $v['sku'], $v['title'], $v['ml'], $v['price_p'], $v['compare_p'], $v['stock'], $v['policy'], $v['avail'], $v['photo'], $v['pos'], $pass]);
    $km = $pdo->prepare("SELECT kind_manual FROM wch_prod WHERE pid = ?"); $km->execute([$p['pid']]);   // classified at once, so a half-finished pass is already usable
    [$kind] = wch_classify(['title' => $p['title'], 'ptype' => $p['ptype'], 'tags' => $p['tags'], 'colls' => $p['colls'], 'kind_manual' => (string)$km->fetchColumn()], wch_map_get($pdo));
    $pdo->prepare("UPDATE wch_prod SET kind = ?, short = ? WHERE pid = ?")->execute([$kind, wch_short_name($p['title'], $kind), $p['pid']]);
    return $exists ? 'updated' : 'added';
}
/**
 * Runs the sync until the deadline. $fresh = start a new pass now. A pass that did not finish continues from the saved cursor on the next call.
 * Returns ['ok','error','code','incomplete','products','variants','chains','perfumes','other','added','updated','deactivated','pages','ms','at'] (counts are of the stored catalog).
 */
function wch_sync_step(PDO $pdo, float $deadline, bool $fresh = false, ?callable $fetch = null): array
{
    $t0 = microtime(true); $res = ['ok' => false, 'error' => '', 'code' => '', 'incomplete' => false, 'added' => 0, 'updated' => 0, 'deactivated' => 0, 'pages' => 0, 'at' => wch_dt()];
    $got = (int)$pdo->query("SELECT GET_LOCK('wch_sync', 0)")->fetchColumn(); if ($got !== 1) { $res['error'] = 'A refresh is already running. Wait a minute.'; $res['code'] = 'busy'; return $res; }
    try {
        $pass = (int)wch_meta_get($pdo, 'sync_pass', '0'); $open = wch_meta_get($pdo, 'sync_open', '0') === '1'; $cursor = wch_meta_get($pdo, 'sync_cursor', '');
        $acc = wch_arr(wch_meta_get($pdo, 'sync_acc', ''));
        if ($fresh || !$open || $pass <= 0) { $pass++; $cursor = ''; $acc = ['added' => 0, 'updated' => 0, 'pages' => 0, 'seen' => 0, 'started' => wch_dt()]; wch_meta_put($pdo, 'sync_pass', (string)$pass); wch_meta_put($pdo, 'sync_open', '1'); }
        $now = wch_dt(); $done = false;
        do {
            $r = wch_fetch_page($pdo, $cursor !== '' ? $cursor : null, $fetch);
            if (!$r['ok']) { $res['error'] = $r['error']; $res['code'] = $r['code']; wch_meta_put($pdo, 'sync_acc', wch_json($acc)); wch_meta_put($pdo, 'sync_cursor', $cursor); $res['pages'] = (int)$acc['pages']; wch_sync_info($pdo, $res, $t0); return $res; }
            $acc['pages']++;
            foreach ($r['nodes'] as $n) if (is_array($n)) { $s = wch_store_product($pdo, $n, $pass, $now); if ($s === 'added') $acc['added']++; elseif ($s === 'updated') $acc['updated']++; if ($s !== 'skip') $acc['seen']++; }
            $cursor = (string)($r['cursor'] ?? ''); $done = !$r['hasNext'] || $cursor === '';
            wch_meta_put($pdo, 'sync_cursor', $done ? '' : $cursor); wch_meta_put($pdo, 'sync_acc', wch_json($acc));
        } while (!$done && microtime(true) < $deadline && $acc['pages'] < 200);
        wch_meta_put($pdo, 'sync_step_at', wch_dt());   // when the last round ran — a stopped read resumes from here
        $res['added'] = (int)$acc['added']; $res['updated'] = (int)$acc['updated']; $res['pages'] = (int)$acc['pages'];
        if (!$done) { $res['ok'] = true; $res['incomplete'] = true; }
        else {
            $activeBefore = (int)$pdo->query("SELECT COUNT(*) FROM wch_prod WHERE active = 1 AND seen_pass < $pass")->fetchColumn(); $seen = (int)$acc['seen'];
            if ($seen === 0) { $res['error'] = 'Shopify returned no products. Nothing was changed.'; wch_meta_put($pdo, 'sync_open', '0'); wch_sync_info($pdo, $res, $t0); return $res; }
            $tot = $activeBefore + $seen;
            if ($activeBefore === 0 || $seen >= (int)ceil($tot * 0.5) || $activeBefore <= 3) {   // products no longer on the site are switched off, but never after a suspiciously small read
                $res['deactivated'] = $pdo->exec("UPDATE wch_prod SET active = 0 WHERE seen_pass < $pass AND active = 1");
                $pdo->exec("DELETE v FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE p.seen_pass = $pass AND v.seen_pass < $pass");
            } else $res['warning'] = 'Shopify returned far fewer products than before, so nothing was switched off.';
            wch_meta_put($pdo, 'sync_open', '0');
            try { $pdo->exec("UPDATE wch_prod p JOIN wa_prod w ON w.shopify_id = p.pid SET p.units = w.units_sold"); } catch (Throwable $e) { }   // best sellers first in the search
            $res['ok'] = true; wch_reclassify($pdo); wch_meta_put($pdo, 'last_sync_ok_at', wch_dt()); if (function_exists('kb_touch')) kb_touch($pdo, 'Products (catalogue refreshed)');
        }
        $res += wch_counts($pdo); wch_sync_info($pdo, $res, $t0); return $res;
    } catch (Throwable $e) {
        error_log('wch_sync: ' . $e->getMessage()); $res['error'] = 'The refresh stopped: ' . mb_substr($e->getMessage(), 0, 160); wch_sync_info($pdo, $res, $t0); return $res;
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wch_sync')"); } catch (Throwable $e) { } }
}
function wch_sync_info(PDO $pdo, array $res, float $t0): void { $res['ms'] = (int)round((microtime(true) - $t0) * 1000); wch_meta_put($pdo, 'sync_info', wch_json($res)); if ($res['ok'] && !$res['incomplete'] || $res['error'] !== '') wch_meta_put($pdo, 'sync_last_at', wch_dt()); }
function wch_counts(PDO $pdo): array
{
    $o = ['products' => 0, 'variants' => 0, 'chains' => 0, 'perfumes' => 0, 'other' => 0, 'inactive' => 0];
    foreach ($pdo->query("SELECT kind, active, COUNT(*) n FROM wch_prod GROUP BY kind, active")->fetchAll() as $r) {
        if ((int)$r['active'] !== 1) { $o['inactive'] += (int)$r['n']; continue; }
        $o['products'] += (int)$r['n']; $k = $r['kind'] === 'chain' ? 'chains' : ($r['kind'] === 'perfume' ? 'perfumes' : 'other'); $o[$k] += (int)$r['n'];
    }
    $o['variants'] = (int)$pdo->query("SELECT COUNT(*) FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE p.active = 1")->fetchColumn();
    return $o;
}
function wch_problems(PDO $pdo, int $max = 100): array
{
    $o = []; $unclear = array_flip(array_map('intval', wch_arr(wch_meta_get($pdo, 'unclear_pids', '[]'))));
    $vars = []; foreach ($pdo->query("SELECT v.pid, v.ml, v.price_p, v.avail FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE p.active = 1 AND p.kind IN ('perfume', 'chain')")->fetchAll() as $v) $vars[(int)$v['pid']][] = $v;
    foreach ($pdo->query("SELECT pid, title, kind, photo, variants_trunc FROM wch_prod WHERE active = 1 ORDER BY kind, title")->fetchAll() as $p) {
        $pid = (int)$p['pid']; $add = function ($k, $t) use (&$o, $pid, $p) { $o[] = ['kind' => $k, 'pid' => $pid, 'title' => (string)$p['title'], 'text' => $t]; };
        if (isset($unclear[$pid]) && $p['kind'] === 'other') $add('unclear_kind', 'Not sure if this is a perfume, a chain or a set');
        if (!in_array($p['kind'], ['perfume', 'chain'], true)) continue;
        $vs = $vars[$pid] ?? [];
        if (!$vs) { $add('no_price', 'No variants found'); continue; }
        if ($p['kind'] === 'perfume') { $n = 0; foreach ($vs as $v) if ($v['ml'] === null) $n++; if ($n === count($vs)) $add('no_size', 'No size (ml) found in the variants'); elseif ($n > 0) $add('no_size', $n . ' variant(s) without a size (ml)'); }
        $np = 0; $ia = 0; foreach ($vs as $v) { if ((int)$v['price_p'] <= 0) $np++; if ((int)$v['avail'] === 1) $ia++; }
        if ($np > 0) $add('no_price', $np . ' variant(s) without a price'); if ($ia === 0) $add('no_stock', 'Out of stock (all variants)');
        if ($p['kind'] === 'chain' && (string)$p['photo'] === '') $add('chain_no_photo', 'No photo - the chain options message needs one');
        if ((int)$p['variants_trunc'] === 1) $add('no_size', 'More than 25 variants - only the first 25 were read');
    }
    return $o;
}
function wch_sync_status(PDO $pdo, array $cfg): array
{
    $info = wch_arr(wch_meta_get($pdo, 'sync_info', '')); $last = wch_meta_get($pdo, 'sync_last_at', ''); $okAt = wch_meta_get($pdo, 'last_sync_ok_at', ''); $sizes = [];
    foreach ($pdo->query("SELECT v.ml, COUNT(*) n FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE p.active = 1 AND p.kind = 'perfume' AND v.ml IS NOT NULL GROUP BY v.ml ORDER BY n DESC, v.ml LIMIT 12")->fetchAll() as $r) $sizes[] = ['ml' => (int)$r['ml'], 'variants' => (int)$r['n']];
    usort($sizes, function ($a, $b) { return $a['ml'] <=> $b['ml']; });
    $pr = wch_problems($pdo, 100); $map = wch_map_get($pdo); $sc = wch_arr(wch_meta_get($pdo, 'scope', ''));
    $open = wch_meta_get($pdo, 'sync_open', '0') === '1';
    $segs = ['perfume' => 0, 'attar' => 0, 'gel' => 0, 'other' => 0, 'chain' => 0];   // the kinds the cart and the Price chart use (same rule as the cart: wch_seg_of)
    try { foreach ($pdo->query("SELECT kind, title, ptype, tags_txt FROM wch_prod WHERE active = 1")->fetchAll() as $p0) { $sg0 = wch_seg_of($p0); $segs[isset($segs[$sg0]) ? $sg0 : 'other']++; } } catch (Throwable $e) { }
    $err0 = (string)($info['error'] ?? ''); $code0 = (string)($info['code'] ?? '');
    $help = $err0 === '' ? '' : ($code0 === 'scope_missing' || stripos($err0, 'scope') !== false || stripos($err0, 'access') !== false ? 'Shopify did not allow reading the products. Press "Check Shopify permissions" below and add what it says is missing in Shopify (Settings → Apps → Develop apps → HRMS app).'
        : (stripos($err0, 'token') !== false || stripos($err0, '401') !== false ? 'The Shopify login of HRMS is missing or expired. Connect the store again in Shopify settings of HRMS, then press Refresh now.'
        : (stripos($err0, 'timeout') !== false || stripos($err0, 'timed out') !== false ? 'Shopify answered too slowly. Press Refresh now again; a big catalogue is read in several steps.' : 'Press Refresh now. If it fails again, send this message to the developer.')));
    return ['ok' => true, 'on' => (bool)$cfg['wch_on'], 'segs' => $segs, 'error_help' => $help, 'last_sync_at' => $okAt !== '' ? $okAt : null, 'last_try_at' => $last !== '' ? $last : null, 'last_ok' => !empty($info['ok']) && ($info['error'] ?? '') === '', 'error' => (string)($info['error'] ?? ''), 'code' => (string)($info['code'] ?? ''),
        'in_progress' => $open, 'synced_ago_min' => $okAt !== '' ? max(0, (int)floor((wch_now() - wch_ts($okAt)) / 60)) : null, 'counts' => wch_counts($pdo), 'sizes' => $sizes, 'problems' => array_slice($pr, 0, 100), 'problems_total' => count($pr),
        'mapping_confirmed' => $map['confirmed'], 'scope' => ['products' => $sc['products'] ?? null, 'draft_orders' => $sc['draft_orders'] ?? null, 'orders' => $sc['orders'] ?? null, 'checked_at' => $sc['checked_at'] ?? null]];
}
/** Cheap reads that tell which scopes the app has. Returns the scope array. */
function wch_scope_check(PDO $pdo): array
{
    $t = [['products', 'query { products(first: 1) { nodes { id } } }', ['read_products']], ['draft_orders', 'query { draftOrders(first: 1) { nodes { id } } }', ['write_draft_orders', 'read_draft_orders']], ['orders', 'query { orders(first: 1) { nodes { id } } }', ['read_orders']]];
    $sc = ['checked_at' => wch_dt()]; $miss = [];
    foreach ($t as [$k, $q, $need]) { $r = wch_shop($pdo, $q, [], $need, 'reading ' . str_replace('_', ' ', $k)); $sc[$k] = $r['ok'] ? true : ($r['code'] === 'scope_missing' ? false : null); if ($sc[$k] === false) $miss = array_merge($miss, $need); if ($k === 'draft_orders' && !$r['ok'] && $r['code'] === 'scope_missing') { $miss = array_values(array_unique($miss)); } }
    $sc['missing'] = array_values(array_unique($miss)); wch_meta_put($pdo, 'scope', wch_json($sc)); return $sc;
}

// ------------------------------------------------------------------------------------------------------------------ search + chain coverage
function wch_size_out(array $v): array
{
    $ml = $v['ml'] === null ? null : (int)$v['ml']; $vt = trim((string)$v['title']); $label = $ml !== null ? $ml . ' ml' : (($vt === '' || strcasecmp($vt, 'Default Title') === 0) ? '＋ Add' : $vt);
    return ['vid' => (int)$v['vid'], 'ml' => $ml, 'label' => $label, 'sku' => (string)$v['sku'], 'price' => wch_r((int)$v['price_p']), 'compare_at' => $v['compare_p'] === null ? null : wch_r((int)$v['compare_p']),
        'stock' => $v['stock'] === null ? null : (int)$v['stock'], 'in_stock' => (int)$v['avail'] === 1];
}
/** How salespeople and customers write brand and perfume names → the short names used in the catalogue (and back). Each line = one group of words that mean the same.
 *  The owner can add more in setting wch_search_alias (one group per line, words separated by commas / =). */
function wch_alias_groups(PDO $pdo = null): array
{
    $g = ['chanel,chnl,chanell,channel', 'dior,dr,christian dior', 'armani,arm,giorgio armani,armaani', 'ysl,yls,yves saint laurent,saint laurent', 'creed,crd', 'sauvage,savage,suvage', 'bleu,blue',
          'homme,home,hom', 'versace,vrsc,versaci', 'gucci,guc', 'tom ford,tf,tomford', 'hugo boss,boss,hb', 'paco rabanne,paco,pr', 'jean paul gaultier,jpg', 'carolina herrera,ch', 'calvin klein,ck', 'dolce gabbana,dg,d&g',
          'giorgio,gio', 'invictus,invicts', 'aventus,aventis', 'eros,erros', 'one million,1 million,1m', 'acqua di gio,adg,acqua', 'bleu de chanel,blue de chanel,bdc', 'dior sauvage,dr savage,sauvage', 'good girl,gg', 'black opium,bo',
          'lattafa,ltfa', 'afnan,afn', 'rasasi,rsi', 'ajmal,ajml', 'attar,ittar,itr', 'oud,oudh,aoud'];
    if ($pdo) { try { $x = trim((string)setting_get($pdo, 'wch_search_alias', '')); if ($x !== '') foreach (preg_split('/\R/u', $x) as $ln) if (trim($ln) !== '') $g[] = str_replace('=', ',', $ln); } catch (Throwable $e) { } }
    $o = []; foreach ($g as $line) { $ws = array_values(array_filter(array_map(fn($w) => mb_strtolower(trim($w)), explode(',', $line)), fn($w) => $w !== '')); if (count($ws) > 1) $o[] = $ws; }
    return $o;
}
/** The query as groups of words: each group = the word the person typed + every name that means the same. Small words (de, la, the …) are dropped; a known full name
 *  ("bleu de chanel") becomes one group with its code (bdc). */
function wch_query_groups(PDO $pdo, string $q): array
{
    $q = ' ' . trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace(['&', '-', '|', '/', '.'], ' ', $q)))) . ' '; $alias = wch_alias_groups($pdo); $groups = [];
    foreach ($alias as $ws) foreach ($ws as $w) if (mb_strpos($w, ' ') !== false && mb_strpos($q, ' ' . $w . ' ') !== false) { $groups[] = $ws; $q = str_replace(' ' . $w . ' ', ' ', $q); break; }   // a full name typed: one group
    $stop = ['de', 'la', 'le', 'du', 'des', 'di', 'da', 'the', 'of', 'by', 'for', 'and', 'a', 'an', 'in', 'perfume', 'perfumes', 'inspired', 'tmperfumehouse', 'ml'];
    foreach (preg_split('/\s+/u', trim($q)) as $w) {
        if ($w === '' || (in_array($w, $stop, true) && mb_strlen(trim($q)) > mb_strlen($w))) continue;
        $grp = [$w]; foreach ($alias as $ws) if (in_array($w, $ws, true)) { $grp = array_values(array_unique(array_merge($grp, $ws))); break; }
        $groups[] = $grp;
    }
    return array_slice($groups, 0, 6);
}
/** One word inside a text: whole word for short words (≤ 3 letters, e.g. "dr", "bdc", "arm"), anywhere for longer ones. */
function wch_has_word(string $txt, string $w, bool $fuzzy = false): bool
{
    if ($w === '') return false;
    if (mb_strpos($w, ' ') !== false) {   // a full name ("bleu de chanel"): every real word of it must be there (each with its short names)
        static $stop = ['de', 'la', 'le', 'du', 'des', 'di', 'da', 'the', 'of', 'by', 'for', 'and', 'a', 'an', 'in'];
        foreach (preg_split('/\s+/u', $w) as $x) { if (in_array($x, $stop, true)) continue; $ok = wch_has_word($txt, $x, $fuzzy); if (!$ok) foreach (wch_alias_groups() as $ws) if (in_array($x, $ws, true)) { foreach ($ws as $y) if (mb_strpos($y, ' ') === false && wch_has_word($txt, $y, $fuzzy)) { $ok = true; break; } break; } if (!$ok) return false; }
        return true;
    }
    if (mb_strlen($w) <= 3) { if (preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($w, '/') . '([^\p{L}\p{N}]|$)/u', $txt)) return true; }
    elseif (mb_strpos($txt, $w) !== false) return true;
    if ($fuzzy && mb_strlen($w) >= 5) {   // a small spelling difference (allure ↔ alurey, aventus ↔ aventis): 1–2 letters, same first two letters
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $txt) as $t) if (mb_strlen($t) >= 4 && mb_substr($t, 0, 2) === mb_substr($w, 0, 2) && levenshtein($t, $w) <= (mb_strlen($w) >= 6 ? 2 : 1)) return true;
    }
    return false;
}
function wch_search(PDO $pdo, string $q, string $cat, int $limit, bool $inStock): array
{
    $kind = $cat === 'chain' ? 'chain' : ($cat === 'other' ? 'other' : 'perfume'); $limit = max(1, min(30, $limit));
    $groups = wch_query_groups($pdo, $q); if (!$groups) return [];
    // candidates: any word of any group in the title, handle, tags or code (the ranking below decides)
    $terms = []; $alias = wch_alias_groups($pdo); $stopW = ['de', 'la', 'le', 'du', 'des', 'di', 'da', 'the', 'of', 'by', 'for', 'and', 'a', 'an', 'in'];
    foreach ($groups as $grp) foreach ($grp as $w) { $terms[$w] = true; if (mb_strpos($w, ' ') !== false) foreach (preg_split('/\s+/u', $w) as $x) { if (in_array($x, $stopW, true)) continue; $terms[$x] = true; foreach ($alias as $ws) if (in_array($x, $ws, true)) foreach ($ws as $y) if (mb_strpos($y, ' ') === false) $terms[$y] = true; } }
    $or = []; $args = [$kind]; foreach (array_slice(array_keys($terms), 0, 40) as $w) { $or[] = "p.search_txt LIKE ?"; $args[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $w) . '%'; }
    $sql = "SELECT p.* FROM wch_prod p WHERE p.active = 1 AND p.kind = ? AND (" . implode(' OR ', $or) . ")" . ($inStock ? " AND EXISTS (SELECT 1 FROM wch_var v WHERE v.pid = p.pid AND v.avail = 1)" : '') . " LIMIT 400";
    $st = $pdo->prepare($sql); $st->execute($args); $cand = $st->fetchAll(); if (!$cand) return [];
    // score: every group must be found; the product NAME counts most, then short name / handle, then tags / code
    $scored = [];
    foreach ($cand as $r) {
        $title = mb_strtolower((string)$r['title'] . ' ' . (string)$r['short']); $handle = mb_strtolower(str_replace('-', ' ', (string)$r['handle'])); $rest = mb_strtolower((string)$r['search_txt']);
        $score = 0; $inName = 0; $found = 0;
        foreach ($groups as $grp) {
            $best = 0; foreach ($grp as $w) { $b = wch_has_word($title, $w) ? 6 : (wch_has_word($handle, $w) ? 4 : (wch_has_word($title, $w, true) ? 5 : (wch_has_word($rest, $w) ? 1 : 0))); if ($b > $best) $best = $b; }
            if ($best > 0) $found++; if ($best >= 4) $inName++; $score += $best;
        }
        if ($found < count($groups)) continue;                       // a word the person typed is nowhere: not this product
        if ($inName === 0 && count($groups) > 1) continue;           // found only in tags (e.g. "similar to Bleu de Chanel" on another perfume): not this product
        if (preg_match('/^' . preg_quote(mb_strtolower(trim($q)), '/') . '/u', $title)) $score += 5;   // starts with what was typed
        $scored[] = [$score + $inName * 3, (int)$r['units'], $r, $inName];
    }
    if (array_filter($scored, fn($x) => $x[3] > 0)) $scored = array_values(array_filter($scored, fn($x) => $x[3] > 0));   // real name matches exist: drop the ones found only in tags
    usort($scored, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    $rows = array_map(fn($x) => $x[2], array_slice($scored, 0, $limit)); $items = []; if (!$rows) return [];
    $pids = array_map(function ($r) { return (int)$r['pid']; }, $rows); $vs = [];
    $vq = $pdo->prepare("SELECT * FROM wch_var WHERE pid IN (" . wch_in($pids) . ") ORDER BY pid, ml IS NULL, ml, pos"); $vq->execute($pids); foreach ($vq->fetchAll() as $v) $vs[(int)$v['pid']][] = $v;
    foreach ($rows as $r) {
        $sizes = array_map('wch_size_out', $vs[(int)$r['pid']] ?? []); $ph = (string)$r['photo']; if ($ph !== '' && preg_match('#^https://cdn\.shopify\.com/#i', $ph)) $ph .= (strpos($ph, '?') === false ? '?' : '&') . 'width=240';
        $items[] = ['pid' => (int)$r['pid'], 'title' => (string)$r['title'], 'short' => (string)$r['short'], 'kind' => $kind, 'seg' => wch_seg_of(['kind' => $kind, 'title' => (string)$r['title'], 'ptype' => (string)($r['ptype'] ?? ''), 'tags_txt' => (string)($r['tags_txt'] ?? '')]), 'photo' => $ph, 'url' => (string)$r['url'], 'sizes' => $sizes, 'min_price' => wch_r((int)$r['min_price_p']), 'max_price' => wch_r((int)$r['max_price_p']),
            'in_stock' => (bool)array_filter($sizes, function ($s) { return $s['in_stock']; })];
    }
    return $items;
}
function wch_slab_label(int $fromP, int $toP): string { return wch_rs($fromP) . '-' . number_format($toP / 100, 0, '.', ','); }
function wch_coverage(PDO $pdo, array $cfg): array
{
    $from = (int)$cfg['wch_slab_from'] * 100; $to = (int)$cfg['wch_slab_to'] * 100; $step = max(100, (int)$cfg['wch_slab_step'] * 100); $split = (float)$cfg['wch_split_pct'];
    $pool = []; foreach ($pdo->query("SELECT v.price_p, v.avail FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE p.active = 1 AND p.kind = 'chain' AND v.price_p > 0")->fetchAll() as $r) $pool[] = [(int)$r['price_p'], (int)$r['avail']];
    $slabs = []; $empty = []; $tot = count($pool); $inS = 0; foreach ($pool as $x) $inS += $x[1];
    for ($a = $from; $a < $to; $a += $step) {
        $b = min($to, $a + $step); $n = 0; $s = 0; foreach ($pool as $x) if ($x[0] >= $a && $x[0] < $b) { $n++; $s += $x[1]; }
        $em = $s === 0; $sugg = '';
        if ($em) { $ord = (int)round($a / ($split / 100) / 100) * 100; $sugg = 'No chain priced ' . wch_rs($a) . '-' . number_format(($b - 1) / 100, 0, '.', ',') . ' in stock. Upload chains in the ' . wch_slab_label($a, $b) . ' range so orders around ' . wch_rs($ord) . ' (chain share ' . wch_rs($a) . ') have a real option.'; $empty[] = wch_slab_label($a, $b); }
        $slabs[] = ['from' => (int)($a / 100), 'to' => (int)($b / 100), 'count' => $n, 'in_stock' => $s, 'empty' => $em, 'suggestion' => $sugg];
    }
    $text = $empty ? count($empty) . ' of ' . count($slabs) . ' slabs have no chain: ' . implode(', ', $empty) . '.' : 'Every slab has at least one chain in stock.';
    return ['ok' => true, 'from' => (int)($from / 100), 'to' => (int)($to / 100), 'step' => (int)($step / 100), 'window_pct' => (int)$cfg['wch_window_pct'], 'slabs' => $slabs, 'total_chains' => $tot, 'in_stock_chains' => $inS, 'empty_slabs' => count($empty), 'text' => $text];
}

// ------------------------------------------------------------------------------------------------------------------ chain suggestion
function wch_chain_pool(PDO $pdo): array
{
    $o = []; foreach ($pdo->query("SELECT v.vid, v.pid, v.sku, v.title vtitle, v.price_p, v.stock, v.photo vphoto, p.title, p.short, p.photo ppho FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE p.active = 1 AND p.kind = 'chain' AND v.avail = 1 AND v.price_p > 0 ORDER BY v.price_p, v.vid")->fetchAll() as $r)
        $o[] = ['vid' => (int)$r['vid'], 'pid' => (int)$r['pid'], 'title' => (string)$r['title'], 'short' => (string)$r['short'], 'variant' => (string)$r['vtitle'], 'sku' => (string)$r['sku'], 'mrp_p' => (int)$r['price_p'], 'stock' => $r['stock'] === null ? null : (int)$r['stock'], 'photo' => (string)($r['vphoto'] !== '' ? $r['vphoto'] : $r['ppho'])];
    return $o;
}
function wch_chain_out(array $c): array { return ['vid' => $c['vid'], 'pid' => $c['pid'], 'title' => $c['title'], 'variant' => $c['variant'], 'sku' => $c['sku'], 'mrp' => wch_r($c['mrp_p']), 'stock' => $c['stock'], 'photo' => $c['photo']]; }
function wch_opt_label(array $o): string
{
    $names = array_map(function ($c) { return $c['title'] . ($c['variant'] !== '' ? ' (' . $c['variant'] . ')' : ''); }, $o['chains']); $nm = implode(' + ', $names);
    return $o['discount_p'] > 0 ? $nm . ' - MRP ' . wch_rs($o['mrp_total_p']) . ', you pay ' . wch_rs($o['pay_p']) : $nm . ' - ' . wch_rs($o['pay_p']);
}
function wch_opt_make(array $chains, int $payP, bool $shifts, bool $rec, bool $wide = false): array
{
    $mrp = 0; $ids = []; foreach ($chains as $c) { $mrp += $c['mrp_p']; $ids[] = $c['vid']; }
    $o = ['id' => count($chains) === 1 ? 'c:' . $ids[0] : 'p:' . $ids[0] . '+' . $ids[1], 'kind' => count($chains) === 1 ? 'single' : 'pair', 'chains' => $chains, 'mrp_total_p' => $mrp, 'pay_p' => $payP, 'discount_p' => max(0, $mrp - $payP), 'shifts_split' => $shifts, 'recommended' => $rec, 'wide' => $wide];
    return $o;
}
function wch_opt_out(array $o): array
{
    return ['id' => $o['id'], 'kind' => $o['kind'], 'recommended' => $o['recommended'], 'shifts_split' => $o['shifts_split'], 'wide' => !empty($o['wide']), 'chains' => array_map('wch_chain_out', $o['chains']), 'mrp_total' => wch_r($o['mrp_total_p']), 'pay' => wch_r($o['pay_p']),
        'discount' => wch_r($o['discount_p']), 'label' => wch_opt_label($o)];
}
/** Pairs of chains (different products, or the same variant twice when stock allows) whose combined MRP is in [lo, hi]. Closest to lo first. */
function wch_pairs(array $pool, int $lo, int $hi, int $max): array
{
    $c = []; $same = []; $n = count($pool);
    for ($i = 0; $i < $n; $i++) for ($j = $i; $j < $n; $j++) {
        $a = $pool[$i]; $b = $pool[$j]; $s = $a['mrp_p'] + $b['mrp_p']; if ($s > $hi) break; if ($s < $lo) continue;
        if ($i === $j) { if ($a['stock'] !== null && $a['stock'] < 2) continue; $same[] = [$s, $a, $b]; continue; } elseif ($a['pid'] === $b['pid']) continue;
        $c[] = [$s, $a, $b];
    }
    if (!$c) $c = $same;   // the same chain twice only when no two different chains fit
    usort($c, function ($x, $y) { return $x[0] <=> $y[0] ?: $x[1]['vid'] <=> $y[1]['vid'] ?: $x[2]['vid'] <=> $y[2]['vid']; });
    $out = []; $used = [];   // spread the choice: a chain is not shown in every pair while another pair exists
    foreach ([true, false] as $strict) foreach ($c as [$s, $a, $b]) {
        if (count($out) >= $max) break 2; $key = $a['vid'] . '+' . $b['vid']; if (isset($used[$key])) continue;
        if ($strict && (isset($used['v' . $a['vid']]) || isset($used['v' . $b['vid']]))) continue;
        $used[$key] = 1; $used['v' . $a['vid']] = 1; $used['v' . $b['vid']] = 1; $out[] = [$a, $b];
    }
    return $out;
}
/** The chain options for an agreed total. Pure function of the pool (no writes). */
function wch_suggest(PDO $pdo, int $totalP, array $cfg, ?array $pool = null): array
{
    $pool = $pool ?? wch_chain_pool($pdo); $split = (float)$cfg['wch_split_pct']; $w = (float)$cfg['wch_window_pct'] / 100; $near = (float)$cfg['wch_near_pct'] / 100; $maxO = (int)$cfg['wch_max_options']; $maxC = (int)$cfg['wch_max_chains'];
    $shareP = (int)(round($totalP * $split / 100 / 100) * 100); $hiP = (int)round($shareP * (1 + $w)); $opts = []; $case = 'none'; $gap = null; $claude = '';
    $res = ['share_p' => $shareP, 'window_from_p' => $shareP, 'window_to_p' => $hiP];
    if (!$pool) {
        $claude = 'There is no chain in stock. Offer perfumes only at ' . wch_rs($totalP) . ', or upload chains and press Refresh.';
        return $res + ['case' => 'none', 'options' => [], 'gap' => null, 'claude' => $claude, 'pool' => 0];
    }
    $best = null; foreach ($pool as $c) if ($best === null || $c['mrp_p'] > $best['mrp_p']) $best = $c;
    // 1. single chains whose MRP is just above the share (one per product, the closest to the share)
    $byPid = []; foreach ($pool as $c) if ($c['mrp_p'] >= $shareP && $c['mrp_p'] <= $hiP) { if (!isset($byPid[$c['pid']]) || $c['mrp_p'] < $byPid[$c['pid']]['mrp_p']) $byPid[$c['pid']] = $c; }
    $singles = array_values($byPid); usort($singles, function ($a, $b) { return $a['mrp_p'] <=> $b['mrp_p'] ?: $a['vid'] <=> $b['vid']; });
    if ($singles) {
        $case = 'normal'; foreach (array_slice($singles, 0, $maxO) as $c) $opts[] = wch_opt_make([$c], $shareP, false, true);
        $claude = count($singles) . ' chain' . (count($singles) > 1 ? 's fit' : ' fits') . ' this order. The closest is ' . $singles[0]['title'] . ' (MRP ' . wch_rs($singles[0]['mrp_p']) . '): the customer pays the chain share ' . wch_rs($shareP) . '.';
    } else {
        $pairsNear = $maxC >= 2 ? wch_pairs($pool, $shareP, $hiP, $maxO) : [];
        if ($best['mrp_p'] < (int)round($shareP * $near) && $pairsNear) {   // big order: the largest chain is far below the share -> two chains
            $case = 'two_chains'; foreach ($pairsNear as [$a, $b]) $opts[] = wch_opt_make([$a, $b], $shareP, false, true);
            $claude = 'No single chain fits a chain share of ' . wch_rs($shareP) . ' (the largest in stock is ' . wch_rs($best['mrp_p']) . '), so two chains are suggested. Upload chains in the ' . wch_rs($shareP) . '-' . number_format(ceil($hiP / 10000) * 100, 0, '.', ',') . ' range to offer one chain.';
        } else {
            $case = 'gap'; $needTo = (int)(ceil($hiP / 10000) * 10000); $choices = []; $below = null; foreach ($pool as $c) if ($c['mrp_p'] < $shareP && ($below === null || $c['mrp_p'] > $below['mrp_p'])) $below = $c;
            if ($below) { $o = wch_opt_make([$below], $below['mrp_p'], true, false); $opts[] = $o; $choices[] = ['key' => 'use_best_full', 'label' => 'Use ' . $below['title'] . ' at its full price ' . wch_rs($below['mrp_p']) . ' (the perfumes then get ' . wch_rs($totalP - $below['mrp_p']) . ' instead of ' . wch_rs($totalP - $shareP) . ')', 'option_id' => $o['id']]; }
            else { $above = null; foreach ($pool as $c) if ($c['mrp_p'] > $hiP && ($above === null || $c['mrp_p'] < $above['mrp_p'])) $above = $c;
                if ($above) { $o = wch_opt_make([$above], $shareP, false, false, true); $opts[] = $o; $choices[] = ['key' => 'use_nearest_above', 'label' => 'Use ' . $above['title'] . ' (MRP ' . wch_rs($above['mrp_p']) . ') with a bigger discount: the customer still pays ' . wch_rs($shareP), 'option_id' => $o['id']]; } }
            $pairs = $maxC >= 2 ? ($pairsNear ?: wch_pairs($pool, $shareP, (int)round($shareP * (1 + 2 * $w)), 1)) : [];
            if ($pairs && count($opts) < $maxO + 1) { [$a, $b] = $pairs[0]; $o = wch_opt_make([$a, $b], $shareP, false, false, !$pairsNear); $opts[] = $o; $choices[] = ['key' => 'two_chains', 'label' => 'Use two chains: ' . $a['title'] . ' + ' . $b['title'] . ' (MRP ' . wch_rs($o['mrp_total_p']) . ', the customer pays ' . wch_rs($shareP) . ')', 'option_id' => $o['id']]; }
            $choices[] = ['key' => 'wait_refresh', 'label' => 'Wait - I will upload chains and refresh', 'option_id' => null];
            $txt = 'No chain between ' . wch_rs($shareP) . ' and ' . wch_rs($needTo) . ' is in stock. The best is ' . ($below ? $below['title'] . ' at ' . wch_rs($below['mrp_p']) : $best['title'] . ' at ' . wch_rs($best['mrp_p'])) . '. Upload chains in the ' . wch_rs($shareP) . '-' . number_format($needTo / 100, 0, '.', ',') . ' range.';
            $gap = ['best' => wch_chain_out($below ?: $best), 'need_from' => (int)($shareP / 100), 'need_to' => (int)($needTo / 100), 'text' => $txt, 'choices' => $choices]; $claude = $txt;
        }
    }
    return $res + ['case' => $case, 'options' => $opts, 'gap' => $gap, 'claude' => $claude, 'pool' => count($pool)];
}
function wch_suggest_out(PDO $pdo, int $cartId, int $totalP, array $cfg): array
{
    $s = wch_suggest($pdo, $totalP, $cfg); $o = ['ok' => true, 'id' => $cartId, 'share' => wch_r($s['share_p']), 'split_pct' => (float)$cfg['wch_split_pct'], 'window' => ['from' => wch_r($s['window_from_p']), 'to' => wch_r($s['window_to_p']), 'pct' => (int)$cfg['wch_window_pct']],
        'case' => $s['case'], 'options' => array_map('wch_opt_out', $s['options']), 'no_chain' => ['pay_total' => wch_r($totalP), 'label' => 'No chain - perfumes only at ' . wch_rs($totalP)], 'gap' => $s['gap'], 'claude' => $s['claude']];
    return $o;
}

/** 🩺 Why does the chain page show no chains? The catalog sync, how products are sorted into chain / perfume, the chains with a price,
 *  and (for a cart) the price window its chain share allows. A verdict + a plain report to copy. */
function wch_chain_diag(PDO $pdo, array $cfg, ?array $cart = null): array
{
    $V = []; $L = []; $add = function ($lv, $t, $d = '', $f = '') use (&$V) { $V[] = ['level' => $lv, 'title' => $t, 'detail' => $d, 'fix' => $f]; };
    $info = wch_arr(wch_meta_get($pdo, 'sync_info', '')); $okAt = wch_meta_get($pdo, 'last_sync_ok_at', ''); $last = wch_meta_get($pdo, 'sync_last_at', ''); $sc = wch_arr(wch_meta_get($pdo, 'scope', ''));
    $L[] = '=== CATALOG SYNC (Shopify → HRMS)'; $L[] = 'last good sync: ' . ($okAt ?: 'never') . ' · last try: ' . ($last ?: 'never') . ' · last error: ' . ((string)($info['error'] ?? '') ?: 'none') . (wch_meta_get($pdo, 'sync_open', '0') === '1' ? ' · a read is UNFINISHED (' . (int)(wch_arr(wch_meta_get($pdo, 'sync_acc', ''))['pages'] ?? 0) * 20 . ' products read so far, last round ' . (wch_meta_get($pdo, 'sync_step_at', '') ?: '?') . ')' : '');
    if (!empty($sc['missing'])) $add('bad', 'Shopify does not allow ' . implode(', ', (array)$sc['missing']) . ' — the cart payment link (draft order) cannot be made.', '', 'Shopify admin → Settings → Apps and sales channels → Develop apps → the HRMS app → Configuration → Admin API scopes: tick write_draft_orders and read_draft_orders → Save → Install / update the app. Then Check Shopify permissions again.');
    if ($sc) $L[] = 'Shopify permission check: ' . json_encode($sc, JSON_UNESCAPED_UNICODE);
    $cnt = []; foreach ($pdo->query("SELECT kind, active, COUNT(*) n FROM wch_prod GROUP BY kind, active")->fetchAll() as $r) $cnt[($r['active'] ? '' : 'inactive ') . $r['kind']] = (int)$r['n'];
    $L[] = 'products in HRMS: ' . ($cnt ? json_encode($cnt) : 'none');
    if ($okAt === '') $add('bad', 'The catalog has never been read from Shopify successfully.', (string)($info['error'] ?? ''), 'Learn & Setup → Cart setup → Catalog sync → press Refresh now (and Check Shopify permissions if it fails).');
    elseif ((string)($info['error'] ?? '') !== '') $add('bad', 'The last catalog sync failed: ' . $info['error'], 'Chains and prices may be old or missing.', 'Learn & Setup → Cart setup → Catalog sync → Refresh now; if it says permission, press Check Shopify permissions.');
    $map = wch_map_get($pdo);
    $L[] = ''; $L[] = '=== HOW PRODUCTS ARE SORTED'; $L[] = 'rule confirmed: ' . (!empty($map['confirmed']) ? 'yes' : 'no (automatic guess by name / type / collection)') . ' · chain rule: ' . json_encode($map['chain'], JSON_UNESCAPED_UNICODE) . ' · perfume rule: ' . json_encode($map['perfume'], JSON_UNESCAPED_UNICODE);
    // chains with a price
    $ch = $pdo->query("SELECT p.pid, p.title, p.ptype, p.photo, p.kind_manual, MIN(v.price_p) mn, MAX(v.price_p) mx, COUNT(v.vid) nv, SUM(v.avail = 1 AND v.price_p > 0) ok FROM wch_prod p LEFT JOIN wch_var v ON v.pid = p.pid WHERE p.active = 1 AND p.kind = 'chain' GROUP BY p.pid ORDER BY mn")->fetchAll();
    $usable = array_values(array_filter($ch, fn($r) => (int)$r['ok'] > 0));
    $L[] = ''; $L[] = '=== CHAINS (' . count($ch) . ' products, ' . count($usable) . ' with a price)';
    foreach (array_slice($ch, 0, 25) as $r) $L[] = '- ' . $r['title'] . ' · ' . ($r['ptype'] ?: 'no type') . ' · ' . ((int)$r['nv'] ? wch_rs((int)$r['mn']) . ((int)$r['mx'] !== (int)$r['mn'] ? '–' . wch_rs((int)$r['mx']) : '') : 'NO VARIANT') . ((int)$r['ok'] ? '' : ' · NO PRICE') . ($r['photo'] === '' ? ' · no photo' : '') . ($r['kind_manual'] !== '' ? ' · set by hand' : '');
    if (!$ch) $add('bad', 'No product is sorted as a CHAIN.', 'So the chain page has nothing to show — even if Shopify has chains.', 'Learn & Setup → Cart setup → Catalog sync → "What is a chain": pick the collection, product type or tag your chains use and confirm.');
    elseif (!$usable) $add('bad', count($ch) . ' chain product(s) but none has a price.', '', 'Give the chains a price in Shopify, then Refresh now.');
    // products that look like chains but are not sorted as chains
    $miss = []; foreach ($pdo->query("SELECT pid, title, ptype, tags_txt, colls_json, kind, kind_manual FROM wch_prod WHERE active = 1 AND kind <> 'chain'")->fetchAll() as $r) {
        $txt = $r['title'] . ' ' . $r['ptype'] . ' ' . $r['tags_txt'] . ' ' . $r['colls_json']; if (!preg_match(wch_rx_chain(), $txt)) continue;
        [$k, , $why] = wch_classify(wch_prod_for_class($r), $map); $miss[] = $r['title'] . ' → saved as ' . $r['kind'] . ($k !== $r['kind'] ? ' (the rule now says ' . $k . ' by ' . $why . ' — Refresh now re-sorts it)' : ' (' . $why . ')'); }
    if ($miss) { $L[] = ''; $L[] = '=== LOOK LIKE CHAINS BUT ARE NOT SORTED AS CHAINS (' . count($miss) . ')'; foreach (array_slice($miss, 0, 20) as $m) $L[] = '- ' . $m;
        $add('warn', count($miss) . ' product(s) look like chains but are sorted as something else.', 'e.g. ' . implode(' · ', array_slice($miss, 0, 3)), 'Confirm the chain rule (collection / type / tag) in Catalog sync, or set those products to Chain by hand there.'); }
    // this cart
    if ($cart) {
        $sp = (float)$cart['split_pct']; $s = wch_suggest($pdo, (int)$cart['total_p'], array_merge($cfg, ['wch_split_pct' => $sp]));
        $L[] = ''; $L[] = '=== THIS CART #' . (int)$cart['id'] . ': total ' . wch_rs((int)$cart['total_p']) . ' · chain part ' . $sp . '% = ' . wch_rs((int)$s['share_p']) . ' · chains looked for between ' . wch_rs((int)$s['window_from_p']) . ' and ' . wch_rs((int)$s['window_to_p']) . ' · found ' . count($s['options']) . ' option(s) · case ' . $s['case'];
        if ($usable && !$s['options']) { $below = array_values(array_filter($usable, fn($r) => (int)$r['mn'] < (int)$s['window_from_p'])); $above = array_values(array_filter($usable, fn($r) => (int)$r['mn'] > (int)$s['window_to_p']));
            $add('warn', 'Chains exist, but none is priced between ' . wch_rs((int)$s['window_from_p']) . ' and ' . wch_rs((int)$s['window_to_p']) . ' for this cart.', count($below) . ' chain(s) are cheaper, ' . count($above) . ' are dearer. Nearest: ' . ($below ? end($below)['title'] . ' ' . wch_rs((int)end($below)['mn']) : '—') . ' / ' . ($above ? $above[0]['title'] . ' ' . wch_rs((int)$above[0]['mn']) : '—'), 'Change the chain % of the cart, the agreed total, or the price window in Cart setup → Settings; or add a chain at that price in Shopify.'); }
        elseif ($s['options']) $add('ok', count($s['options']) . ' chain option(s) fit this cart.');
    }
    if (!$V) $add('ok', 'Chains are read from Shopify and sorted correctly (' . count($usable) . ' with a price).');
    $o = ['bad' => 0, 'warn' => 1, 'ok' => 2]; usort($V, fn($a, $b) => $o[$a['level']] <=> $o[$b['level']]);
    $rep = "🩺 CHAIN DIAGNOSE — " . date('Y-m-d H:i') . "\n\nVERDICT\n" . implode("\n", array_map(fn($v, $i) => ($i + 1) . '. [' . strtoupper($v['level']) . '] ' . $v['title'] . ($v['detail'] ? ' — ' . $v['detail'] : '') . ($v['fix'] ? ' → ' . $v['fix'] : ''), $V, array_keys($V))) . "\n\n" . implode("\n", $L);
    return ['ok' => true, 'verdict' => $V, 'report' => $rep, 'chains' => count($usable)];
}

// ------------------------------------------------------------------------------------------------------------------ the cart: rows, lines, split maths, plan
function wch_status_labels(): array
{
    return ['building' => 'Building the cart', 'sending' => 'Sending the chain options', 'options_sent' => "Waiting for the customer's chain choice", 'chosen' => 'Customer chose - ready for the cart link', 'link_sent' => 'Cart link sent, waiting for payment',
        'ordered' => 'Order placed', 'billed' => 'Order placed and bill sent', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'failed' => 'Failed'];
}
function wch_open_statuses(): array { return ['building', 'options_sent', 'chosen', 'link_sent']; }
function wch_cart_row(PDO $pdo, int $id): ?array { $q = $pdo->prepare("SELECT * FROM wch_cart WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ?: null; }
function wch_cart_open_for(PDO $pdo, string $phone): ?array
{
    $q = $pdo->prepare("SELECT * FROM wch_cart WHERE phone = ? AND status IN ('building','options_sent','chosen','link_sent') ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); $r = $q->fetch(); return $r ?: null;
}
function wch_ev(PDO $pdo, int $cartId, string $kind, string $text): void
{
    try { $pdo->prepare("INSERT INTO wch_cart_ev (cart_id, at, kind, text) VALUES (?, ?, ?, ?)")->execute([$cartId, wch_dt(), mb_substr($kind, 0, 20), mb_substr($text, 0, 300)]); } catch (Throwable $e) { }
}
function wch_cart_touch(PDO $pdo, int $id, array $set = [], bool $extend = true): void
{
    $cfg = wch_cfg($pdo); $set['updated_at'] = wch_dt(); if ($extend) $set['expires_at'] = wch_dt(wch_now() + $cfg['wch_expire_hours'] * 3600);
    $cols = []; $args = []; foreach ($set as $k => $v) { $cols[] = "`$k` = ?"; $args[] = $v; } $args[] = $id;
    $pdo->prepare("UPDATE wch_cart SET " . implode(', ', $cols) . " WHERE id = ?")->execute($args);
}
function wch_vars_by_ids(PDO $pdo, array $vids): array
{
    $vids = array_values(array_unique(array_filter(array_map('intval', $vids)))); if (!$vids) return [];
    $q = $pdo->prepare("SELECT v.*, p.title, p.short, p.kind, p.active, p.photo ppho, p.ptype, p.tags_txt FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE v.vid IN (" . wch_in($vids) . ")"); $q->execute($vids); $o = []; foreach ($q->fetchAll() as $r) $o[(int)$r['vid']] = $r; return $o;
}
/** Even split of $totalP over $weights (same price per unit of weight). Whole rupees on every line but the last, which takes the difference; paise if that would leave a line at 0. */
function wch_split_exact(int $totalP, array $weights): array
{
    $n = count($weights); if ($n === 0) return []; if ($n === 1) return [$totalP];
    $sum = array_sum($weights); if ($sum <= 0) { $weights = array_fill(0, $n, 1); $sum = $n; }
    foreach ([100, 1] as $unit) {
        $out = []; $acc = 0; for ($i = 0; $i < $n - 1; $i++) { $a = (int)(round($totalP * $weights[$i] / $sum / $unit) * $unit); $out[] = $a; $acc += $a; }
        $last = $totalP - $acc; $out[] = $last; $ok = true; foreach ($out as $a) if ($a <= 0) $ok = false;
        if ($ok) return $out;
    }
    return $out;
}
function wch_cart_lines(array $cart): array { $l = wch_arr($cart['lines_json'] ?? ''); $o = []; foreach ($l as $x) { if (is_array($x) && (int)($x['vid'] ?? 0) > 0) $o[] = ['vid' => (int)$x['vid'], 'qty' => max(1, min(20, (int)($x['qty'] ?? 1)))]; } return $o; }
function wch_cart_ml_used(PDO $pdo, array $cart): int
{
    $lines = wch_cart_lines($cart); $vs = wch_vars_by_ids($pdo, array_column($lines, 'vid')); $ml = 0; foreach ($lines as $l) { $v = $vs[$l['vid']] ?? null; if ($v && $v['ml'] !== null && wch_seg_of($v) === 'perfume') $ml += (int)$v['ml'] * $l['qty']; } return $ml;   // perfume ml only: attar, shower gel and wax are counted apart
}
function wch_commission(PDO $pdo, int $ml, int $totalP): ?array
{
    if (!function_exists('ml_slab_get') || !function_exists('coach_slab_pct')) return null;
    try { $pct = coach_slab_pct(ml_slab_get($pdo), $ml, wch_r($totalP), false); } catch (Throwable $e) { return null; }
    if ($pct === null) return null;
    return ['pct' => round((float)$pct, 2), 'amount' => (int)round($totalP / 100 * $pct / 100), 'note' => 'Commission is on the TOTAL (' . wch_rs($totalP) . ', ' . $ml . ' ml) by the price and commission slab chart.'];
}
/** Shopify tags of a WhatsApp cart's order: wch + the cart id + the salesperson's own order tag (employees.sales_tag), so the order is
 *  credited to them (My Sales, Commission) like any order they tag by hand. Order Allocation can still move it. */
function wch_order_tags(PDO $pdo, array $cart): array
{
    $tags = ['wch', 'wch-' . $cart['id']]; $emp = (int)($cart['emp_id'] ?? 0); if ($emp <= 0) return $tags;
    try { $q = $pdo->prepare("SELECT sales_tag FROM employees WHERE employee_id = ?"); $q->execute([$emp]); $t = trim((string)$q->fetchColumn()); if ($t !== '' && !in_array(mb_strtolower($t), ['wch'], true)) $tags[] = mb_substr($t, 0, 40); } catch (Throwable $e) { }
    return $tags;
}
/** Freezes what will be billed: perfume units split by ml over (total - chain pay), chain lines at their pay. $opt = the chosen option array (internal paise form) or null = no chain. */
function wch_plan_build(PDO $pdo, array $cart, ?array $opt): array
{
    $total = (int)$cart['total_p']; $chainPay = $opt ? (int)$opt['pay_p'] : 0; $perfumeP = $total - $chainPay;
    if ($perfumeP <= 0) return [false, 'The chain price is not less than the agreed total, so no money is left for the perfumes.', null];
    $lines = wch_cart_lines($cart); $vs = wch_vars_by_ids($pdo, array_column($lines, 'vid')); $units = [];
    foreach ($lines as $l) { $v = $vs[$l['vid']] ?? null; if (!$v) return [false, 'A perfume in the cart is no longer in the catalog. Remove it and add it again.', null]; for ($i = 0; $i < $l['qty']; $i++) $units[] = $v; }
    if (!$units) return [false, 'The cart has no perfumes.', null];
    // one price chart section: the agreed money is shared between the segments (perfume / attar / shower gel / others) by each part's chart price, then inside a part by ml
    $ph = wch_price_hint($pdo, $cart); $isMix = isset($ph['parts']['mix']);
    if (!empty($ph['combo_missing'])) return [false, 'This combo has no price yet (' . $ph['combo_missing'] . '). Ask the owner to create it in Learn & Setup → Pricing → Combos first.', null];
    if ($isMix) { $am = wch_split_exact($perfumeP, array_map(function ($v) { return max(100, (int)$v['price_p']); }, $units)); $bySeg = []; }   // one combo price: shared over the products by their Shopify price
    else { $bySeg = []; foreach ($units as $v) $bySeg[wch_seg_of($v)][] = $v; }
    if ($isMix) { }
    elseif (count($bySeg) > 1) { $w = []; foreach ($bySeg as $sg => $us) { $lp = (int)($ph['parts'][$sg]['list_p'] ?? 0); if ($lp <= 0) { $lp = 0; foreach ($us as $v) $lp += max(100, (int)$v['price_p']); } $w[$sg] = $lp; }
        $share = wch_split_exact($perfumeP, array_values($w)); $units = []; $am = []; $k = 0;
        foreach ($bySeg as $sg => $us) { $part = $share[$k++]; $units = array_merge($units, $us); $am = array_merge($am, wch_split_exact($part, array_map(function ($v) use ($sg) { return $sg === 'perfume' ? max(1, (int)($v['ml'] ?? 0)) : max(100, (int)$v['price_p']); }, $us))); }
    } else $am = wch_split_exact($perfumeP, array_map(function ($v) { return (int)($v['ml'] ?? 0); }, $units));
    $pl = [];
    foreach ($units as $i => $v) $pl[] = ['kind' => 'perfume', 'seg' => wch_seg_of($v), 'vid' => (int)$v['vid'], 'pid' => (int)$v['pid'], 'sku' => (string)$v['sku'], 'title' => (string)$v['title'], 'short' => (string)$v['short'], 'ml' => $v['ml'] === null ? null : (int)$v['ml'], 'amount_p' => $am[$i]];
    if ($opt) {
        $ch = $opt['chains']; $ca = count($ch) === 1 ? [$chainPay] : wch_split_exact($chainPay, array_map(function ($c) { return $c['mrp_p']; }, $ch));
        foreach ($ch as $i => $c) $pl[] = ['kind' => 'chain', 'vid' => (int)$c['vid'], 'pid' => (int)$c['pid'], 'sku' => (string)$c['sku'], 'title' => (string)$c['title'], 'short' => (string)$c['short'], 'ml' => null, 'amount_p' => $ca[$i], 'mrp_p' => (int)$c['mrp_p']];
    }
    $sum = 0; foreach ($pl as $x) $sum += $x['amount_p'];
    if ($sum !== $total) return [false, 'The bill lines do not add up to the agreed total (internal error).', null];
    return [true, '', ['chain_choice' => $opt ? $opt['id'] : 'none', 'perfume_part_p' => $perfumeP, 'chain_pay_p' => $chainPay, 'lines' => $pl, 'sum_p' => $sum]];
}
function wch_plan_out(?array $p): ?array
{
    if (!$p) return null; $l = [];
    foreach ($p['lines'] as $x) $l[] = ['kind' => $x['kind'], 'vid' => $x['vid'], 'sku' => $x['sku'], 'title' => $x['short'] !== '' ? $x['short'] : $x['title'], 'ml' => $x['ml'], 'amount' => wch_r($x['amount_p'])];
    return ['chain_choice' => $p['chain_choice'], 'perfume_part' => wch_r($p['perfume_part_p']), 'chain_pay' => wch_r($p['chain_pay_p']), 'lines' => $l, 'sum' => wch_r($p['sum_p'])];
}

// ------------------------------------------------------------------------------------------------------------------ cart view
function wch_billing_gate(PDO $pdo): array { $b = wch_billing_state($pdo); return ['ok' => $b['complete'], 'missing' => $b['missing'], 'filled' => $b['filled'], 'total' => $b['total']]; }
// ------------------------------------------------------------------------------------------------------------------ price segments (one brain: the Teach > pricing groups)
/** Which segment a product is: chain | attar | gel (shower gel / body wash) | wax | perfume | other. Perfume and attar are counted apart. */
function wch_seg_of(array $v): string
{
    if ((string)($v['kind'] ?? '') === 'chain') return 'chain';
    $t = mb_strtolower((string)($v['title'] ?? '') . ' ' . (string)($v['ptype'] ?? '') . ' ' . (string)($v['tags_txt'] ?? ''));
    if (preg_match('/\battar|\bittar|\boud oil|\broll[- ]?on\b/u', $t)) return 'attar';
    if (preg_match('/shower ?gel|body ?wash|\bgel\b/u', $t)) return 'gel';
    if (preg_match('/\bwax\b/u', $t)) return 'other';   // wax is priced on the Others chart
    return (string)($v['kind'] ?? '') === 'perfume' ? 'perfume' : 'other';
}
function wch_seg_labels(): array { return ['perfume' => 'Perfume', 'attar' => 'Attar', 'gel' => 'Shower gel', 'wax' => 'Wax', 'other' => 'Others', 'chain' => 'Chain', 'mix' => 'Mix']; }
/** The price book: every price row the owner set in Learn & Setup > Teach (💰 Set up pricing / offers), by segment. A group's segment comes from its
 *  title (or the row label): "Attar …" = attar, "Shower gel …" = gel, "Wax …" = wax, "Other …" = other, anything else = perfume. Running dated offers win. */
function wch_price_book(PDO $pdo): array
{
    static $B = null; if ($B !== null) return $B; $B = [];
    if (!function_exists('teach_limits_get')) return $B; $today = date('Y-m-d');
    try { $groups = teach_limits_get($pdo)['groups']; } catch (Throwable $e) { return $B; }
    $segOf = function (string $s): string { $s = mb_strtolower($s); if (preg_match('/attar|ittar/u', $s)) return 'attar'; if (preg_match('/shower|gel|body ?wash/u', $s)) return 'gel'; if (preg_match('/\bwax\b/u', $s)) return 'wax'; if (preg_match('/\bother/u', $s)) return 'other'; return ''; };
    foreach ($groups as $g) {
        if (!is_array($g) || (function_exists('teach_limit_on') && !teach_limit_on($g, $today))) continue;
        $gs = $segOf((string)($g['title'] ?? '')); $temp = (string)($g['to'] ?? '') !== '' || (string)($g['from'] ?? '') !== '';
        foreach ((array)($g['rows'] ?? []) as $r) {
            if (!is_array($r) || !empty($r['never'])) continue; $lab = (string)($r['label'] ?? ''); if (!preg_match('/(\d+(?:\.\d+)?)\s*ml/iu', $lab, $m)) continue;
            $seg = $segOf($lab) ?: ($gs ?: 'perfume'); $start = isset($r['start']) && $r['start'] !== null ? (float)$r['start'] : null; $low = isset($r['lowest']) && $r['lowest'] !== null ? (float)$r['lowest'] : null;
            if ($start === null && $low === null) continue;
            $B[$seg][] = ['ml' => (float)$m[1], 'upto' => (bool)preg_match('/up ?to|upto|till|below|under|<=|max/iu', $lab), 'start' => $start ?? $low, 'lowest' => $low ?? $start, 'temp' => $temp, 'title' => (string)($g['title'] ?? '')];
        }
    }
    return $B;
}
/** The price row for one size of a segment: an exact size first, else the smallest "up to N ml" band that covers it. Dated offers before normal prices. */
function wch_price_of(array $book, string $seg, ?int $ml): ?array
{
    if ($ml === null || empty($book[$seg])) return null; $best = null;
    foreach ($book[$seg] as $r) {
        $hit = $r['upto'] ? ($ml <= $r['ml']) : (abs($r['ml'] - $ml) < 0.01); if (!$hit) continue;
        $rank = [$r['temp'] ? 0 : 1, $r['upto'] ? 1 : 0, $r['upto'] ? $r['ml'] : 0];
        if ($best === null || $rank < $best[0]) $best = [$rank, $r];
    }
    return $best ? $best[1] : null;
}
/** What the cart lines cost by the price book: list (start) total, lowest total, per line, and which sizes have no price yet. In paise. */
function wch_price_hint(PDO $pdo, array $cart): array
{
    $lines = wch_cart_lines($cart); $vs = wch_vars_by_ids($pdo, array_column($lines, 'vid')); $book = wch_price_book($pdo);
    $o = ['list_p' => 0, 'lowest_p' => 0, 'missing' => [], 'lines' => [], 'perfume_ml' => 0, 'attar_ml' => 0, 'segs' => []];
    foreach ($lines as $l) {
        $v = $vs[$l['vid']] ?? null; if (!$v) continue; $seg = wch_seg_of($v); $ml = $v['ml'] === null ? null : (int)$v['ml'];
        if ($seg === 'perfume' && $ml !== null) $o['perfume_ml'] += $ml * $l['qty']; if ($seg === 'attar' && $ml !== null) $o['attar_ml'] += $ml * $l['qty'];
        $r = wch_price_of($book, $seg, $ml); $shop = (int)$v['price_p'];
        $st = $r ? (int)round($r['start'] * 100) : $shop; $lw = $r ? (int)round($r['lowest'] * 100) : $shop;
        if (!$r) $o['missing'][] = (wch_seg_labels()[$seg] ?? $seg) . ' ' . ($ml !== null ? $ml . ' ml' : (string)$v['title']);
        $o['list_p'] += $st * $l['qty']; $o['lowest_p'] += $lw * $l['qty']; $o['segs'][$seg] = 1;
        $o['lines'][] = ['vid' => (int)$v['vid'], 'seg' => $seg, 'ml' => $ml, 'qty' => $l['qty'], 'start_p' => $st, 'lowest_p' => $lw, 'from' => $r ? ($r['title'] !== '' ? $r['title'] : 'Teach pricing') . ($r['temp'] ? ' (offer)' : '') : 'Shopify price'];
    }
    // one price chart section: every segment is priced as ONE part by ITS OWN total ml on its own chart (list = the first column, lowest = the last)
    $segMl = []; foreach ($o['lines'] as $x) { if ($x['seg'] === 'chain') continue; if ($x['seg'] === 'perfume') { if ($x['ml'] !== null) $segMl['perfume'] = ($segMl['perfume'] ?? 0) + $x['ml'] * $x['qty']; } else $segMl[$x['seg']] = ($segMl[$x['seg']] ?? 0) + $x['qty']; }   // perfume = total ml · attar / shower gel / others = pieces
    $o['parts'] = [];
    // Combo: 2 or more kinds in the cart (perfume ml, shower gel, attar, others) = ONE row with EXACTLY these amounts on the Price chart.
    // No row = no price: the owner creates the row ("create this combo"); nothing is worked out by adding single prices.
    $mp0 = (int)($segMl['perfume'] ?? 0); $mg = (int)($segMl['gel'] ?? 0); $ma = (int)($segMl['attar'] ?? 0); $mx = (int)($segMl['other'] ?? 0);
    if (function_exists('pc_mix_kinds') && pc_mix_kinds($mp0, $mg, $ma, $mx) >= 2) {
        $lab = pc_mix_label($mp0, $mg, $ma, $mx); $mp = pc_mix_prices($pdo, $mp0, $mg, $ma, $mx); $o['missing'] = [];
        foreach ($o['lines'] as $i => $x) if ($x['seg'] !== 'chain') $o['lines'][$i]['from'] = $mp ? 'Combo price (' . $lab . ')' : 'No combo price yet';
        if ($mp) { $o['list_p'] = (int)round($mp['list'] * 100); $o['lowest_p'] = (int)round($mp['lowest'] * 100);
            $o['parts'] = ['mix' => ['ml' => $lab, 'list_p' => $o['list_p'], 'lowest_p' => $o['lowest_p'], 'chart' => true, 'perf' => $mp0, 'g' => $mg, 'a' => $ma, 'x' => $mx, 'p' => $mp['p']]]; }
        else { $o['list_p'] = 0; $o['lowest_p'] = 0; $o['combo_missing'] = $lab; $o['missing'][] = 'Combo ' . $lab . ' — create this combo in Learn & Setup → Pricing → Combos';
            $o['parts'] = ['mix' => ['ml' => $lab, 'list_p' => 0, 'lowest_p' => 0, 'chart' => false, 'perf' => $mp0, 'g' => $mg, 'a' => $ma, 'x' => $mx, 'p' => []]]; }
        $segMl = [];   // one kind alone is priced below on its own rows; a combo never is
    }
    foreach ($segMl as $sg => $sm) {
        $cp = function_exists('pc_prices') ? pc_prices($pdo, $sg, (float)$sm) : ($sg === 'perfume' ? (function () use ($pdo, $sm) { $x = wch_slab_prices($pdo, (int)$sm); return $x ? ['list' => $x['list_p'] / 100, 'lowest' => $x['lowest_p'] / 100, 'ml' => $x['ml'], 'exact' => $x['ml'] == $sm] : null; })() : null);
        if (!$cp) { $o['parts'][$sg] = ['ml' => $sm, 'list_p' => 0, 'lowest_p' => 0, 'chart' => false]; continue; }
        $l0 = 0; $w0 = 0; foreach ($o['lines'] as $i => $x) if ($x['seg'] === $sg) { $l0 += $x['start_p'] * $x['qty']; $w0 += $x['lowest_p'] * $x['qty']; $o['lines'][$i]['from'] = (wch_seg_labels()[$sg] ?? $sg) . ' price chart (' . $sm . ($sg === 'perfume' ? ' ml' : ' pcs') . ')'; }
        $lp = (int)round($cp['list'] * 100); $wp = (int)round($cp['lowest'] * 100);
        $o['list_p'] += $lp - $l0; $o['lowest_p'] += $wp - $w0; $o['parts'][$sg] = ['ml' => $sm, 'list_p' => $lp, 'lowest_p' => $wp, 'chart' => true, 'row_ml' => $cp['ml'], 'exact' => !empty($cp['exact']), 'p' => $cp['p'] ?? []];
        $lb0 = (wch_seg_labels()[$sg] ?? $sg) . ' '; $o['missing'] = array_values(array_filter($o['missing'], function ($m) use ($lb0) { return strpos($m, $lb0) !== 0; }));
    }
    // the price ladder: per % column, every part's price on that column added up (a part with no chart row adds its Shopify price on every step)
    $o['ladder'] = []; $cols0 = function_exists('pc_get') ? pc_get($pdo, 'perfume')['cols'] : [];
    if ($cols0 && $o['parts'] && !array_filter($o['parts'], function ($p) { return !$p['chart']; })) {
        foreach ($cols0 as $i => $pc0) { $sum = 0; foreach ($o['parts'] as $pt) { $pp = array_values((array)($pt['p'] ?? [])); $v = (float)($pp[$i] ?? 0); if ($v <= 0) { $nz = array_values(array_filter($pp, function ($x) { return $x > 0; })); $v = $nz ? (float)end($nz) : 0; } $sum += (int)round($v * 100); }
            $o['ladder'][] = ['pct' => (float)$pc0, 'price_p' => $sum]; }
    }
    foreach ($o['parts'] as $sg => $pt) if (!$pt['chart'] && $sg !== 'mix') $o['missing'][] = (wch_seg_labels()[$sg] ?? $sg) . ' ' . $pt['ml'] . ($sg === 'perfume' ? ' ml' : ' pcs') . ' (no price chart row)';
    $o['missing'] = array_values(array_unique($o['missing'])); $o['segs'] = array_keys($o['segs']);
    return $o;
}
/** The slab chart's prices for a total perfume ml: the row of that ml (or the next lower row). In paise. null when the ml is outside the chart. */
function wch_slab_prices(PDO $pdo, int $ml): ?array
{
    if ($ml <= 0 || !function_exists('ml_slab_get')) return null;
    try { $c = ml_slab_get($pdo); } catch (Throwable $e) { return null; } $row = null;
    foreach ((array)($c['rows'] ?? []) as $r) if ((float)$r['ml'] <= $ml) $row = $r;
    if (!$row || $ml > (float)end($c['rows'])['ml'] + 50) return null; $p = array_values((array)$row['p']); if (!$p) return null;
    return ['ml' => (int)$row['ml'], 'list_p' => (int)round((float)$p[0] * 100), 'offer_p' => (int)round((float)($p[1] ?? $p[0]) * 100), 'lowest_p' => (int)round((float)end($p) * 100)];
}
/** A segment sold without prices (e.g. the first attar): a topic "Attar prices" appears on the owner's Teach board with 3 questions. Once a week at most per segment. */
function wch_price_topic(PDO $pdo, string $seg, array $sizes): void
{
    if (!in_array($seg, ['attar', 'gel', 'wax', 'other', 'mix'], true)) return; $lb = wch_seg_labels()[$seg];
    $mk = 'pricetopic_' . $seg . ($seg === 'mix' ? '_' . substr(md5(implode('|', $sizes)), 0, 10) : '');   // a combo: one topic per exact combination
    if ((int)wch_meta_get($pdo, $mk, '0') > time() - 7 * 86400) return; wch_meta_put($pdo, $mk, (string)time());
    try {
        $q = $pdo->prepare("SELECT body FROM lib_facts WHERE k = 'ivboard'"); $q->execute(); $B = json_decode((string)$q->fetchColumn(), true); if (!is_array($B)) $B = ['topics' => [], 'done' => 0, 'note' => ''];
        $title = $seg === 'mix' ? mb_substr('Combo price: ' . implode(', ', $sizes), 0, 60) : $lb . ' prices';
        foreach ((array)($B['topics'] ?? []) as $t) if (mb_strtolower((string)($t['title'] ?? '')) === mb_strtolower($title)) return;
        $sz = $sizes ? ' (sold so far: ' . implode(', ', array_slice($sizes, 0, 6)) . ')' : '';
        $B['topics'][] = ['id' => 't' . substr(md5(uniqid('', true)), 0, 6), 'title' => $title, 'shelf' => 'customer', 'src' => 'cart', 'rules' => [], 'concept' => [],
            'open' => [$seg === 'mix' ? 'A salesperson is selling a combo with no price yet' . $sz . '. Create this exact combo in Learn & Setup → Pricing → Combos (+ Row, type the amounts and the 7% / 6.5% / 6% … prices). The cart cannot be sent until it exists. Reply "done" when it is added.' : 'The ' . $lb . ' price chart is empty' . $sz . '. Fill it in Learn & Setup → Pricing → ' . $lb . ' (press "Suggest rows", then type the prices). Reply "done" when it is filled.', 'How should I quote ' . $lb . ' to customers — which column first, and when may I go lower?'],
            'seen' => 'A salesperson put ' . $lb . ' in a WhatsApp cart, but the ' . $lb . ' price chart has no row for it yet.'];
        if (function_exists('teach_facts_set')) teach_facts_set($pdo, 'ivboard', json_encode($B, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) { }
}
/** The commission % the owner set for a segment on the Teach board ("Attar commission = 5%"), or null. */
function wch_seg_commission_pct(PDO $pdo, string $seg): ?float
{
    if (!function_exists('teach_limits_get')) return null; $lb = mb_strtolower(wch_seg_labels()[$seg] ?? $seg);
    try { foreach (teach_limits_get($pdo)['groups'] as $g) foreach ((array)($g['gen'] ?? []) as $x) { $n = mb_strtolower((string)($x['name'] ?? '')); if (strpos($n, 'commission') !== false && (strpos($n, $lb) !== false || strpos(mb_strtolower((string)($g['title'] ?? '')), $lb) !== false) && preg_match('/(\d+(?:\.\d+)?)/', (string)$x['value'], $m)) return min(30.0, (float)$m[1]); } } catch (Throwable $e) { }
    return null;
}
/** The reason a salesperson gave for an agreed total below the lowest price (the latest one), or ''. */
function wch_below_reason(PDO $pdo, int $cartId): string
{
    try { $q = $pdo->prepare("SELECT text FROM wch_cart_ev WHERE cart_id = ? AND kind = 'below_lowest' ORDER BY id DESC LIMIT 1"); $q->execute([$cartId]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; }
}

function wch_cart_view(PDO $pdo, array $c, array $cfg, array $names = []): array
{
    $lines = wch_cart_lines($c); $vs = wch_vars_by_ids($pdo, array_column($lines, 'vid')); $lo = []; $mlUsed = 0;
    foreach ($lines as $l) {
        $v = $vs[$l['vid']] ?? null; if (!$v) { $lo[] = ['vid' => $l['vid'], 'pid' => 0, 'title' => '(removed from the catalog)', 'short' => '', 'sku' => '', 'ml' => null, 'qty' => $l['qty'], 'unit_price' => null, 'photo' => '']; continue; }
        $seg = wch_seg_of($v); if ($v['ml'] !== null && $seg === 'perfume') $mlUsed += (int)$v['ml'] * $l['qty'];
        $lo[] = ['seg' => $seg, 'vid' => (int)$v['vid'], 'pid' => (int)$v['pid'], 'title' => (string)$v['title'], 'short' => (string)$v['short'], 'sku' => (string)$v['sku'], 'ml' => $v['ml'] === null ? null : (int)$v['ml'], 'qty' => $l['qty'], 'unit_price' => null, 'photo' => (string)($v['photo'] !== '' ? $v['photo'] : $v['ppho'])];
    }
    $total = (int)$c['total_p']; $split = (float)$c['split_pct']; $shareP = (int)(round($total * $split / 100 / 100) * 100); $ml = (int)$c['ml']; $st = (string)$c['status']; $plan = wch_arr($c['plan_json'] ?? ''); $plan = $plan ? $plan : null;
    $opts = wch_arr($c['opts_json'] ?? ''); $sent = (string)$c['options_sent_at'] !== ''; $picked = (string)$c['picked'];
    if ($plan) { $unit = []; foreach ($plan['lines'] as $x) if ($x['kind'] === 'perfume') { $unit[$x['vid']][] = $x['amount_p']; } foreach ($lo as &$l) if (isset($unit[$l['vid']])) $l['unit_price'] = wch_r((int)round(array_sum($unit[$l['vid']]) / max(1, count($unit[$l['vid']])))); unset($l); }
    $gate = wch_billing_gate($pdo); $on = (bool)$cfg['wch_on']; $blockers = []; $warn = [];
    if (!$on && in_array($st, ['building', 'options_sent', 'chosen'], true)) $blockers[] = ['code' => 'off', 'text' => 'The chain flow is switched off, so nothing can be sent to the customer yet.'];
    if (!$gate['ok'] && in_array($st, ['building', 'chosen'], true)) $blockers[] = ['code' => 'billing_incomplete', 'text' => 'Fill in the Billing tab first (' . $gate['filled'] . ' of ' . $gate['total'] . ' required fields): ' . implode(', ', array_slice($gate['missing'], 0, 4)) . (count($gate['missing']) > 4 ? ' ...' : '') . '.'];
    if ($st === 'building' && !$lines) $blockers[] = ['code' => 'empty', 'text' => 'Add at least one perfume.'];
    $phB = wch_price_hint($pdo, $c); if (!empty($phB['combo_missing']) && in_array($st, ['building', 'chosen'], true)) $blockers[] = ['code' => 'no_combo_price', 'text' => 'Create this combo first: ' . $phB['combo_missing'] . ' (Learn & Setup → Pricing → Combos). Then the price and the commission are counted.'];
    if ($st === 'building' && $lines && $mlUsed !== $ml) $warn[] = 'Perfumes add up to ' . $mlUsed . ' ml, the order says ' . $ml . ' ml.';
    $ph = wch_price_hint($pdo, $c); $below = $lines && $ph['lowest_p'] > 0 && $total < $ph['lowest_p']; $why = $below ? wch_below_reason($pdo, (int)$c['id']) : '';
    $price = ['list' => wch_r($ph['list_p']), 'lowest' => wch_r($ph['lowest_p']), 'missing' => $ph['missing'], 'attar_ml' => $ph['attar_ml'], 'ladder' => array_map(function ($x) { return ['pct' => $x['pct'], 'price' => wch_r($x['price_p'])]; }, $ph['ladder'] ?? []), 'default_col' => function_exists('pc_cust_col') ? pc_cust_col($pdo) : 0, 'parts' => array_map(function ($p) { return ['ml' => $p['ml'], 'list' => wch_r($p['list_p']), 'lowest' => wch_r($p['lowest_p']), 'chart' => $p['chart']]; }, $ph['parts']), 'segs' => $ph['segs'], 'below' => $below, 'below_reason' => $why,
        'lines' => array_map(function ($x) { return ['vid' => $x['vid'], 'seg' => $x['seg'], 'start' => wch_r($x['start_p']), 'lowest' => wch_r($x['lowest_p']), 'from' => $x['from']]; }, $ph['lines'])];
    if ($ph['missing'] && $st === 'building') { foreach ($ph['parts'] as $sg => $pt) if (!$pt['chart']) wch_price_topic($pdo, $sg, [$pt['ml'] . ($sg === 'perfume' ? ' ml' : ' pcs')]); if (!empty($ph['combo_missing'])) wch_price_topic($pdo, 'mix', [$ph['combo_missing']]); }
    $comm = wch_commission($pdo, $ml, $total);   // perfume alone: the slab chart, as before
    if (isset($ph['parts']['mix'])) { $mx0 = $ph['parts']['mix']; $pc = function_exists('pc_mix_pct') ? pc_mix_pct($pdo, (int)$mx0['perf'], (int)$mx0['g'], (int)$mx0['a'], (int)$mx0['x'], $total / 100) : null;
        $comm = $pc === null ? ['pct' => null, 'amount' => 0, 'note' => 'No commission yet: this combo (' . $mx0['ml'] . ') has no row in Learn & Setup → Pricing → Combos. Once the owner adds it, the commission is counted.']
            : ['pct' => round($pc, 2), 'amount' => (int)round($total / 100 * $pc / 100), 'note' => 'Combo ' . $mx0['ml'] . ': on the whole total by its combo row.']; }
    elseif (count($ph['parts']) === 1 && !isset($ph['parts']['perfume']) && function_exists('pc_pct')) { $sg0 = (string)array_key_first($ph['parts']); $pt0 = $ph['parts'][$sg0]; $pc = pc_pct($pdo, $sg0, (float)$pt0['ml'], $total / 100);
        $comm = ['pct' => $pc === null ? null : round($pc, 2), 'amount' => $pc === null ? 0 : (int)round($total / 100 * $pc / 100), 'note' => (wch_seg_labels()[$sg0] ?? $sg0) . ' ' . $pt0['ml'] . ' pcs: on the whole total by its own rows.']; }
    if ($below && $why === '' && $st === 'building') $warn[] = 'The agreed total is below the lowest price (' . wch_rs($ph['lowest_p']) . '). Give a reason to go on.';
    $noOpt = false; if ($st === 'building' && $lines) { try { $s = wch_suggest($pdo, $total, array_merge($cfg, ['wch_split_pct' => $split])); $noOpt = !$s['options']; } catch (Throwable $e) { } }
    $needsManual = (int)$c['needs_manual'] === 1; $chainState = $picked === 'none' ? 'none' : ($picked !== '' ? 'chosen' : ($needsManual ? 'needs_manual' : ($sent ? 'awaiting' : 'none_yet')));
    $locked = !$sent && !($st === 'building' && $noOpt);
    $ord = ['name' => $c['order_name'] !== '' ? $c['order_name'] : null, 'shopify_id' => $c['order_sid'] !== '' ? $c['order_sid'] : null, 'mode' => $c['order_mode'] !== '' ? $c['order_mode'] : null, 'paid' => (int)$c['order_paid'] === 1, 'at' => $c['order_at']];
    $inv = null; if ((int)$c['inv_id'] > 0) { $iq = $pdo->prepare("SELECT id, inv_no, token, sent_at FROM wch_inv WHERE id = ?"); $iq->execute([(int)$c['inv_id']]); $ir = $iq->fetch(); if ($ir) $inv = ['id' => (int)$ir['id'], 'inv_no' => $ir['inv_no'], 'view_url' => wch_view_url($ir['token']), 'pdf_url' => wch_pdf_url($ir['token']), 'sent_at' => $ir['sent_at']]; }
    $sentOpts = []; if ($sent) foreach ($opts as $i => $o) $sentOpts[] = ['n' => $i + 1] + wch_opt_out($o);
    $emp = (int)$c['emp_id'];
    return ['id' => (int)$c['id'], 'phone' => $c['phone'], 'customer' => (string)($c['cname'] ?? ''), 'emp_id' => $emp, 'emp_name' => (string)($names[$emp] ?? ''), 'status' => $st, 'status_label' => wch_status_labels()[$st] ?? $st,
        'total' => wch_r($total), 'ml' => $ml, 'ml_used' => $mlUsed, 'ml_left' => max(0, $ml - $mlUsed), 'progress_pct' => $ml > 0 ? round(min(100, $mlUsed * 100 / $ml), 1) : 0, 'lines' => $lo, 'price' => $price,
        'split' => ['pct' => $split, 'chain_share' => wch_r($shareP), 'perfume_part' => wch_r($total - $shareP)], 'commission' => $comm,
        'chain' => ['state' => $chainState, 'options_sent' => $sent, 'options_sent_at' => $c['options_sent_at'], 'sent_options' => $sentOpts, 'locked_manual' => $locked, 'picked' => $picked !== '' ? $picked : null, 'picked_by' => (string)$c['picked_by'] !== '' ? $c['picked_by'] : null,
            'picked_at' => $c['picked_at'], 'customer_reply' => (string)$c['cust_reply'] !== '' ? $c['cust_reply'] : null, 'needs_manual' => $needsManual, 'reminder_sent_at' => $c['reminder_sent_at']],
        'plan' => wch_plan_out($plan), 'draft' => ['id' => $c['draft_gid'] !== '' ? $c['draft_gid'] : null, 'name' => $c['draft_name'] !== '' ? $c['draft_name'] : null, 'url' => $c['draft_url'] !== '' ? $c['draft_url'] : null, 'status' => $c['draft_status'] !== '' ? $c['draft_status'] : null, 'created_at' => $c['draft_at']],
        'order' => $ord, 'invoice' => $inv,
        'can' => ['edit' => $st === 'building', 'send_options' => $st === 'building' && (bool)$lines && $on && $gate['ok'], 'pick' => ($st === 'options_sent' && $sent) || ($st === 'building' && $noOpt && (bool)$lines), 'link' => $st === 'chosen' && $on && $gate['ok'],
            'cancel' => in_array($st, wch_open_statuses(), true), 'resend_link' => $st === 'link_sent' && $on],
        'blockers' => $blockers, 'warnings' => $warn, 'created_at' => $c['created_at'], 'updated_at' => $c['updated_at'], 'expires_at' => $c['expires_at']];
}
function wch_cart_full(PDO $pdo, int $id, array $cfg, array $names = []): ?array
{
    $q = $pdo->prepare("SELECT c.*, k.name cname FROM wch_cart c LEFT JOIN wa_contacts k ON k.phone = c.phone WHERE c.id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ? wch_cart_view($pdo, $r, $cfg, $names) : null;
}
function wch_cart_create(PDO $pdo, string $phone, int $emp, int $totalP, int $ml, array $cfg): int
{
    $now = wch_dt(); $pdo->prepare("INSERT INTO wch_cart (phone, emp_id, status, total_p, ml, split_pct, lines_json, expires_at, created_at, updated_at) VALUES (?, ?, 'building', ?, ?, ?, '[]', ?, ?, ?)")
        ->execute([$phone, $emp, $totalP, $ml, $cfg['wch_split_pct'], wch_dt(wch_now() + $cfg['wch_expire_hours'] * 3600), $now, $now]);
    $id = (int)$pdo->lastInsertId(); wch_ev($pdo, $id, 'built', 'Cart started: ' . wch_rs($totalP) . ', ' . $ml . ' ml'); return $id;
}
/** Replaces the lines. Returns [ok, error|lines]. */
function wch_lines_clean(PDO $pdo, $raw): array
{
    if (!is_array($raw)) return [false, 'lines must be a list.']; $m = [];
    foreach ($raw as $l) { if (!is_array($l)) return [false, 'Each line needs vid and qty.']; $vid = (int)($l['vid'] ?? 0); $qty = (int)($l['qty'] ?? 1); if ($vid <= 0) return [false, 'Each line needs a vid.']; if ($qty < 1 || $qty > 20) return [false, 'qty must be 1 to 20.']; $m[$vid] = ($m[$vid] ?? 0) + $qty; }
    if (count($m) > 40) return [false, 'A cart can have at most 40 different perfumes.']; $vs = wch_vars_by_ids($pdo, array_keys($m)); $out = [];
    foreach ($m as $vid => $qty) {
        $v = $vs[$vid] ?? null; if (!$v) return [false, 'Variant ' . $vid . ' is not in the catalog. Refresh the catalog and search again.'];
        if ($v['kind'] === 'chain' || (int)$v['active'] !== 1) return [false, '"' . $v['title'] . '" cannot be added here' . ($v['kind'] === 'chain' ? ' (chains come in the next step).' : '.')];
        // no stock check: everything is sold as in stock
        $out[] = ['vid' => $vid, 'qty' => $qty];
    }
    return [true, $out];
}

// ------------------------------------------------------------------------------------------------------------------ the messages of the flow (catalogue; texts can be edited, templates go through the existing template system)
/**
 * mode: window   = a normal message, only inside the customer's 24h window
 *       template = normal message inside the window, an approved UTILITY template outside it (tpl_text, variables {{1}}.. in order of first appearance)
 *       existing = already sent by the order-update module (wa_orders.php), only listed here
 */
function wch_msg_defs(): array
{
    $ex = ['name' => 'Rahul', 'total' => 'Rs.3,000', 'summary' => '120 ml perfume and 1 chain', 'link' => 'https://example.com/pay/abc', 'order' => '#1042', 'mode' => 'Prepaid', 'inv_no' => 'TM/26-27/00012', 'chain' => 'Gold Link Chain', 'choices' => '1, 2 or 3', 'n' => '1', 'price_line' => 'MRP Rs.1,600 - you pay only Rs.1,500', 'new_chain' => 'Silver Rope Chain'];
    $d = [
        'chain_options_intro' => ['title' => 'Chain options: opening line', 'when' => 'Salesperson presses Send chain options', 'mode' => 'window', 'text' => "Hi {name}! Aapke order ke saath chain ke options neeche hain.\nHere are the chain options for your order:", 'vars' => ['name'], 'note' => 'Needs the 24h window (the photos need it too)'],
        'chain_option_photo' => ['title' => 'Chain option (photo caption)', 'when' => 'One photo per option', 'mode' => 'window', 'text' => "Option {n}: {chain}\n{price_line}", 'vars' => ['n', 'chain', 'price_line'], 'note' => 'price_line is "MRP Rs.1,600 - you pay only Rs.1,500" (or just the price when there is no discount)'],
        'chain_options_ask' => ['title' => 'Chain options: closing question', 'when' => 'After the photos', 'mode' => 'window', 'text' => "Aapko kaunsi chain chahiye? Reply {choices} - ya \"no chain\" likhein.\nWhich chain would you like? Reply {choices}, or \"no chain\" to skip it.", 'vars' => ['choices'], 'note' => 'The customer reply is read automatically'],
        'chain_reminder' => ['title' => 'Chain options: one reminder', 'when' => 'No answer after the reminder time (setting)', 'mode' => 'window', 'text' => "Hi {name}, kya aapne chain choose ki? Reply {choices} ya \"no chain\".\nJust checking - please reply {choices}, or \"no chain\", so we can prepare your cart.", 'vars' => ['name', 'choices'], 'note' => 'Only once; if the 24h window is closed the salesperson is told instead'],
        'chain_chosen' => ['title' => 'Chain chosen: confirmation', 'when' => 'Customer (or salesperson) picked a chain', 'mode' => 'window', 'text' => "Thank you {name}! {chain} aapke order mein add ho gayi. Cart link abhi bhej rahe hain.\nGreat choice - {chain} is added. Your cart link is coming next.", 'vars' => ['name', 'chain'], 'note' => 'Sent inside the window the customer just opened'],
        'no_chain' => ['title' => 'No chain: confirmation', 'when' => 'Customer said no chain', 'mode' => 'window', 'text' => "Theek hai {name}, chain nahi lagayenge. Aapka order sirf perfumes ka hoga, total {total}.\nNo problem - no chain. Your order will be perfumes only, total {total}.", 'vars' => ['name', 'total'], 'note' => 'Same agreed total'],
        'cart_ready' => ['title' => 'Cart ready (pay button)', 'when' => 'Salesperson presses Send cart link', 'mode' => 'template', 'text' => "Hi {name}, aapka cart ready hai. Total {total} ({summary}).\nYour cart is ready - total {total} ({summary}). Tap the button to see the items and pay.", 'vars' => ['name', 'total', 'summary'], 'button' => 'View and pay',
            'tpl' => 'tmph_cart_ready', 'tpl_text' => "Hello {name}, your cart is ready. Total {total} for {summary}. You can see the items and pay here: {link} (link valid for 2 days).", 'tpl_vars' => ['name', 'total', 'summary', 'link'], 'note' => 'Inside the window: a message with a "View and pay" button. Outside: the template (link inside)'],
        'payment_pending' => ['title' => 'Payment pending: reminder', 'when' => 'Cart link sent, not paid after the payment reminder time (setting)', 'mode' => 'template', 'text' => "Hi {name}, aapka cart abhi pay hona baaki hai - total {total}. Link: {link}\nYour cart of {total} is still waiting. You can pay here: {link}", 'vars' => ['name', 'total', 'link'],
            'tpl' => 'tmph_cart_payment_pending', 'tpl_text' => "Hello {name}, your cart of {total} is still waiting for payment. You can complete it here: {link} (link valid for 2 days).", 'tpl_vars' => ['name', 'total', 'link'], 'note' => 'Sent once per cart'],
        'order_confirmed' => ['title' => 'Payment received / order confirmed', 'when' => 'The Shopify order exists (COD) or is paid (prepaid)', 'mode' => 'template', 'text' => "Thank you {name}! Aapka order {order} mil gaya ({total}, {mode}). Ship hone par update denge.\nWe have received your order {order} ({total}, {mode}). We will update you when it ships.", 'vars' => ['name', 'order', 'total', 'mode'],
            'tpl' => 'tmph_cart_order_confirmed', 'tpl_text' => "Hello {name}, we have received your order {order} of {total} ({mode}). We will send you an update when it ships.", 'tpl_vars' => ['name', 'order', 'total', 'mode'], 'note' => 'Answers the customer\'s own order'],
        'bill_sent' => ['title' => 'Bill sent (PDF)', 'when' => 'Prepaid: after payment. COD: when the order is created', 'mode' => 'template', 'text' => "Aapka GST bill {inv_no} (order {order}) ready hai.\nYour GST bill {inv_no} for order {order} is attached. View online: {link}", 'vars' => ['inv_no', 'order', 'link'],
            'tpl' => 'tmph_cart_bill_ready', 'tpl_text' => "Hello {name}, the GST bill {inv_no} for your order {order} is ready. You can view and download it here: {link} (link valid for a year).", 'tpl_vars' => ['name', 'inv_no', 'order', 'link'], 'note' => 'Inside the window the PDF itself is sent as a document'],
        'order_packed' => ['title' => 'Packed', 'when' => 'Order packed (no trigger in the order-update module yet)', 'mode' => 'template', 'text' => "Hi {name}, aapka order {order} pack ho gaya hai, jaldi courier ko diya jayega.\nYour order {order} is packed and will be handed to the courier soon.", 'vars' => ['name', 'order'],
            'tpl' => 'tmph_order_packed', 'tpl_text' => "Hello {name}, your order {order} has been packed and will be handed to the courier soon.", 'tpl_vars' => ['name', 'order'], 'note' => 'Text and template are ready; nothing sends it automatically yet', 'trigger' => false],
        'order_cancelled' => ['title' => 'Cancelled', 'when' => 'The cart or order was cancelled', 'mode' => 'template', 'text' => "Hi {name}, aapka order/cart {order} cancel ho gaya hai.\nYour order/cart {order} has been cancelled.", 'vars' => ['name', 'order'],
            'tpl' => 'tmph_order_cancelled', 'tpl_text' => "Hello {name}, your order {order} has been cancelled. If any amount was paid, it goes back to the original payment method as per the payment provider's timeline.", 'tpl_vars' => ['name', 'order'], 'note' => 'Only when the cancel button is pressed with "notify"'],
        'payment_failed' => ['title' => 'Payment failed', 'when' => 'Draft order expired without payment', 'mode' => 'template', 'text' => "Hi {name}, aapka payment complete nahi hua. Cart abhi khula hai: {link}\nThe payment for your cart could not be completed. You can try again here: {link}", 'vars' => ['name', 'link'],
            'tpl' => 'tmph_payment_failed', 'tpl_text' => "Hello {name}, the payment for your cart {order} could not be completed. You can try again here: {link} (link valid for 2 days).", 'tpl_vars' => ['name', 'order', 'link'], 'note' => 'Sent by hand from the cart (no automatic trigger)', 'trigger' => false],
        'chain_replacement' => ['title' => 'Chain out of stock: replacement', 'when' => 'The chosen chain went out of stock', 'mode' => 'template', 'text' => "Hi {name}, {chain} abhi stock mein nahi hai. Hum {new_chain} usi price par de sakte hain. Reply yes ya \"no chain\".\nSorry, {chain} is out of stock. We can offer {new_chain} at the same price. Reply yes, or \"no chain\".", 'vars' => ['name', 'chain', 'new_chain'],
            'tpl' => 'tmph_chain_replacement', 'tpl_text' => "Hello {name}, the chain {chain} in your cart is out of stock. We can offer {new_chain} at the same price. Please reply yes to continue or no chain to skip it.", 'tpl_vars' => ['name', 'chain', 'new_chain'], 'note' => 'Sent by hand', 'trigger' => false],
    ];
    foreach ($d as $k => &$x) { $x += ['source' => 'new', 'button' => '', 'tpl' => '', 'tpl_text' => '', 'tpl_vars' => [], 'trigger' => true]; } unset($x);
    $d['_ex'] = $ex; return $d;
}
function wch_msg_examples(): array { return wch_msg_defs()['_ex']; }
/** The five order-update templates of wa_orders.php (reused, not copied). */
function wch_msg_existing(): array
{
    $map = ['order_shipped' => ['Shipped (with tracking)', 'tmph_order_shipped'], 'order_ofd' => ['Out for delivery', 'tmph_order_out_for_delivery'], 'order_delivered' => ['Delivered', 'tmph_order_delivered'], 'order_delayed' => ['Delayed', 'tmph_delivery_delayed'], 'delivery_failed' => ['Delivery attempt failed', 'tmph_delivery_attempt_failed']];
    $defs = function_exists('wo_tpl_defs') ? wo_tpl_defs() : []; $o = [];
    foreach ($map as $k => [$title, $tpl]) $o[$k] = ['title' => $title, 'when' => 'Courier status changes (automatic order updates)', 'mode' => 'existing', 'source' => 'existing', 'text' => (string)($defs[$tpl]['body'] ?? ''), 'vars' => ['name', 'order', 'courier', 'tracking'], 'tpl' => $tpl, 'tpl_text' => '', 'tpl_vars' => [], 'button' => '', 'note' => 'Sent by the order-update module (Dispatch > Needs attention > Create the 5 templates)', 'trigger' => true];
    return $o;
}
function wch_msg_no_photo(): array { return ['cart_ready', 'chain_option_photo', 'bill_sent', 'chain_options_ask']; }   // these go with their own picture / button / document
function wch_msg_all_defs(): array { $d = wch_msg_defs(); unset($d['_ex']); return $d + wch_msg_existing(); }
/** The text to use now: the owner's edit if any, else the default. */
function wch_msg_row(PDO $pdo, string $key): array
{
    try { $q = $pdo->prepare("SELECT * FROM wch_msg WHERE mkey = ?"); $q->execute([$key]); $r = $q->fetch(); } catch (Throwable $e) { $r = false; }
    return $r ?: ['mkey' => $key, 'text' => null, 'tpl_text' => null, 'photo_url' => '', 'photo_lib_id' => 0, 'tpl_id' => 0, 'tpl_body_hash' => ''];
}
function wch_msg_text(PDO $pdo, string $key, bool $tpl = false): string
{
    $defs = wch_msg_all_defs(); $d = $defs[$key] ?? null; if (!$d) return ''; $r = wch_msg_row($pdo, $key);
    if ($tpl) return trim((string)($r['tpl_text'] ?? '')) !== '' ? (string)$r['tpl_text'] : (string)$d['tpl_text'];
    return trim((string)($r['text'] ?? '')) !== '' ? (string)$r['text'] : (string)$d['text'];
}
function wch_render(string $text, array $vars): string
{
    return trim((string)preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars) { return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0]; }, $text));
}
function wch_placeholders(string $text): array { preg_match_all('/\{([a-z_]+)\}/', $text, $m); return array_values(array_unique($m[1])); }
/** "{name} ... {total} ... {link}" -> body with {{1}} {{2}} {{3}} and the variable names in order. */
function wch_tpl_convert(string $text): array
{
    $names = []; $body = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use (&$names) { $i = array_search($m[1], $names, true); if ($i === false) { $names[] = $m[1]; $i = count($names) - 1; } return '{{' . ($i + 1) . '}}'; }, $text);
    return [$body, $names];
}

// ------------------------------------------------------------------------------------------------------------------ sending (every send of this module goes through here; kill switch, opt-out, 24h window)
function wch_window_open(array $c): bool { return !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) >= time() - 86400; }
function wch_pid_of(PDO $pdo, array $c): string
{
    $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn(); if ($pid === '' && function_exists('wa_cfg_val')) $pid = wa_cfg_val('WA_PHONE_ID'); return $pid;
}
function wch_store_out(PDO $pdo, string $wamid, array $c, string $mtype, string $body, string $mid, string $mime, string $who, int $emp): void
{
    $now = wch_dt(); try {
        $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', ?, ?, ?, ?, 'sent', ?, ?, ?)")->execute([$wamid, $c['phone'], (int)$c['last_number_id'], $mtype, $body, $mid, $mime, $who, $emp, $now]);
        $pdo->prepare("UPDATE wa_contacts SET last_out_at = ? WHERE phone = ?")->execute([$now, $c['phone']]);
    } catch (Throwable $e) { }
}
function wch_send_doc_link(PDO $pdo, array $c, string $url, string $filename, string $caption, string $who, int $emp): array
{
    $pid = wch_pid_of($pdo, $c); if ($pid === '') return [false, 'WA_PHONE_ID is missing in config.php.'];
    $obj = ['link' => $url, 'filename' => $filename]; if ($caption !== '') $obj['caption'] = mb_substr($caption, 0, 1024);
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'document', 'document' => $obj]);
    $wamid = (string)($d['messages'][0]['id'] ?? ''); if ($code < 200 || $code >= 300 || $wamid === '') return [false, wa_err(is_array($d) ? $d : null)];
    wch_store_out($pdo, $wamid, $c, 'document', '📄 Document: ' . $filename . ($caption !== '' ? ' - ' . mb_substr($caption, 0, 200) : ''), 'wch:' . substr(md5($url), 0, 12), 'application/pdf', $who === 'bot' ? 'bot' : $who, $emp); return [true, $wamid];
}
/** A message with one "open link" button (WhatsApp interactive cta_url). Falls back to the text with the link in it when Meta refuses. */
function wch_send_cta(PDO $pdo, array $c, string $body, string $btn, string $url, string $who, int $emp): array
{
    $pid = wch_pid_of($pdo, $c); if ($pid === '') return [false, 'WA_PHONE_ID is missing in config.php.'];
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'interactive',
        'interactive' => ['type' => 'cta_url', 'body' => ['text' => mb_substr($body, 0, 1024)], 'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => mb_substr($btn, 0, 20), 'url' => $url]]]]);
    $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code >= 200 && $code < 300 && $wamid !== '') { wch_store_out($pdo, $wamid, $c, 'interactive', $body . "\n[" . $btn . ']', '', '', $who, $emp); return [true, $wamid]; }
    return wa_send_text($pdo, $c, $body . "\n" . $url, $who, $emp);
}
// ------------------------------------------------------------------------------------------------------------------ photo-on-top (IMAGE header) versions of the cart / order messages
/** Name of the photo version of a template message: "<template>_img". */
function wch_img_name(array $d): string { return $d['tpl'] !== '' ? $d['tpl'] . '_img' : ''; }
function wch_img_id(PDO $pdo, string $name): int { try { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; } }
/** Messages that are template messages and have a library photo chosen: [key => photo library id]. */
/** A message whose photo is only a web link (no media-library photo) cannot be a photo-on-top message: the link is downloaded ONCE into the media library and the message then uses that library photo. Returns the library id (0 = could not). */
function wch_photo_import(PDO $pdo, string $k, string $url): int
{
    try {
        if (!preg_match('#^https://#i', $url) || !function_exists('wa_lib_dir') || !function_exists('curl_init')) return 0;
        $u = function_exists('wch_img') ? wch_img($url) : $url; $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_MAXFILESIZE => 5000000]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($raw === false || $code < 200 || $code >= 300 || strlen((string)$raw) > 5000000) return 0;
        $gi = @getimagesizefromstring((string)$raw); if (!$gi || !in_array($gi[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return 0;
        $ext = $gi[2] === IMAGETYPE_PNG ? 'png' : 'jpg'; $mime = $gi[2] === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg';
        $dir = wa_lib_dir(); if (!is_dir($dir) || !is_writable($dir)) return 0; $stored = 'msg_' . preg_replace('/[^a-z0-9_]/i', '', $k) . '_' . date('Ymd_His') . '.' . $ext; if (@file_put_contents($dir . '/' . $stored, $raw) === false) return 0;
        $pdo->prepare("INSERT INTO wa_media_lib (title, kind, mime, filename, size, path, mids, created_by, created_at) VALUES (?, 'image', ?, ?, ?, ?, '{}', 0, ?)")->execute(['Message photo · ' . $k, $mime, $stored, strlen((string)$raw), $stored, date('Y-m-d H:i:s')]);
        $id = (int)$pdo->lastInsertId(); if ($id > 0) $pdo->prepare("UPDATE wch_msg SET photo_lib_id = ? WHERE mkey = ?")->execute([$id, $k]); return $id;
    } catch (Throwable $e) { return 0; }
}
function wch_img_cfg(PDO $pdo): array
{
    $o = []; foreach (wch_msg_all_defs() as $k => $d) {
        if (($d['mode'] ?? '') !== 'template' || ($d['tpl'] ?? '') === '' || in_array($k, wch_msg_no_photo(), true)) continue; $r = wch_msg_row($pdo, $k);
        $lib = (int)$r['photo_lib_id']; if ($lib <= 0 && trim((string)$r['photo_url']) !== '' && (int)wch_get_state($pdo, 'imp_' . $k) < time() - 86400) { wch_put_state($pdo, 'imp_' . $k, (string)time()); $lib = wch_photo_import($pdo, $k, trim((string)$r['photo_url'])); }   // a photo that is only a link: put it in the media library once (tried once a day)
        if ($lib > 0) $o[$k] = $lib;
    }
    return $o;
}
function wch_get_state(PDO $pdo, string $k): string { try { return (string)setting_get($pdo, 'wch_photo_' . $k, '0'); } catch (Throwable $e) { return '0'; } }
function wch_put_state(PDO $pdo, string $k, string $v): void { try { setting_set($pdo, 'wch_photo_' . $k, $v); } catch (Throwable $e) { } }
function wch_img_sig(PDO $pdo): string { try { return md5(json_encode(wch_img_cfg($pdo))); } catch (Throwable $e) { return ''; } }
/** Makes the photo version of one message (if missing) and sends it to every WhatsApp account that does not have it. The photo itself is taken per send, so a new photo needs no new approval. [ok, text] */
function wch_img_make(PDO $pdo, string $k): array
{
    $d = wch_msg_all_defs()[$k] ?? null; if (!$d || $d['mode'] !== 'template' || $d['tpl'] === '') return [false, 'Not a template message.']; if (in_array($k, wch_msg_no_photo(), true)) return [false, 'This message goes with its own picture, so it gets no photo on top.'];
    $lib = (int)wch_msg_row($pdo, $k)['photo_lib_id']; if ($lib <= 0) return [false, 'No photo from the media library is chosen.'];
    if (!function_exists('wa_tpl_tables') || !function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at') || !wa_tpl_tables($pdo)) return [false, 'The template module is not available.'];
    $name = wch_img_name($d); $id = wch_img_id($pdo, $name); $exv = wch_msg_examples();
    if (!$id) {
        [$body, $names] = wch_tpl_convert(wch_msg_text($pdo, $k, true));
        [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => 'UTILITY', 'header_format' => 'IMAGE', 'header_lib_id' => $lib, 'body' => $body, 'footer' => 'TM Perfume House', 'buttons' => [], 'examples' => ['body' => array_map(function ($n) use ($exv) { return (string)($exv[$n] ?? 'x'); }, $names), 'labels' => $names], 'folder' => 'Cart and bill']);
        if ($err === '' && function_exists('wa_tpl_media_check')) $err = wa_tpl_media_check($pdo, $t); if ($err !== '') return [false, $err];
        $id = wa_tpl_insert($pdo, $t, 0, 'Message about the customer\'s own cart / order / bill with a photo on top (' . $d['title'] . ').', 'Utility message about one cart or order, with a photo.');
    }
    $r = wa_tpl_row($pdo, $id); if (!$r) return [false, 'Could not read the saved template.'];
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; if (!$wabas) return [true, 'Saved. It will go to Meta when a WhatsApp Business Account ID is added.'];
    $have = []; $hq = $pdo->prepare("SELECT waba_id FROM wa_tpl_waba WHERE tpl_id = ?"); $hq->execute([$id]); foreach ($hq->fetchAll() as $h) $have[(string)$h['waba_id']] = 1;
    $sent = 0; $errs = []; foreach ($wabas as $w) { if (isset($have[$w])) continue; [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); if ($ok) $sent++; else $errs[] = $why; }
    if ($errs) return [false, (string)$errs[0]]; return [true, $sent ? 'Sent to Meta for approval.' : 'Already at Meta.'];
}
function wch_photo_reconcile(PDO $pdo): array
{
    $o = []; foreach (wch_img_cfg($pdo) as $k => $lib) { try { [$ok, $m] = wch_img_make($pdo, $k); $o[$k] = ($ok ? 'ok · ' : 'failed · ') . $m; } catch (Throwable $e) { $o[$k] = 'error · ' . $e->getMessage(); } }
    return $o;
}
/** One approved template to the customer, outside the 24h window (same path as the order-update messages). [ok, wamid|error] */
function wch_send_template(PDO $pdo, array $c, string $key, array $vars): array
{
    $defs = wch_msg_all_defs(); $d = $defs[$key] ?? null; if (!$d || $d['tpl'] === '') return [false, 'This message has no template.'];
    if (!function_exists('wa_camp_tpl') || !function_exists('wa_camp_ctx') || !function_exists('wa_pick_number') || !function_exists('wa_camp_request')) return [false, 'The template sender is not available.'];
    $r = wch_msg_row($pdo, $key); $id = (int)$r['tpl_id']; if ($id <= 0) { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$d['tpl']]); $id = (int)$q->fetchColumn(); }
    if ($id <= 0) return [false, 'The template "' . $d['tpl'] . '" has not been created yet (Learn & Setup > Messages > Submit).'];
    $t = wa_camp_tpl($pdo, $id);
    $lib = in_array($key, wch_msg_no_photo(), true) ? 0 : (int)$r['photo_lib_id'];   // 📸 a photo is chosen: the photo-on-top version goes once Meta approved it; until then the plain template
    if ($lib > 0) { $iid = wch_img_id($pdo, wch_img_name($d)); $ti = $iid ? wa_camp_tpl($pdo, $iid, true, $lib) : null; if ($ti) $t = $ti; }
    if (!$t) return [false, 'The template "' . $d['tpl'] . '" is not approved yet.'];
    $vals = []; foreach ($d['tpl_vars'] as $n) $vals[] = trim((string)preg_replace('/\s+/', ' ', (string)($vars[$n] ?? '')));
    $ctx = wa_camp_ctx($pdo, $t, 99); [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if ($nid === 0) return [false, 'No WhatsApp number can send this right now (' . $why . ').'];
    [$ok, $req] = wa_camp_request($pdo, $t, $ctx['nums'][$nid], (string)($c['wa_id'] ?: '91' . $c['phone']), $vals, []); if (!$ok) return [false, (string)$req];
    [$code, $dd] = wa_api('POST', $req['path'], $req['json']); $wamid = (string)($dd['messages'][0]['id'] ?? '');
    if ($code < 200 || $code >= 300 || $wamid === '') return [false, wa_err(is_array($dd) ? $dd : null)];
    if (function_exists('wa_log_template_msg')) wa_log_template_msg($pdo, $wamid, (string)$c['phone'], $nid, function_exists('wa_tpl_shown') ? wa_tpl_shown($t, $vals) : $d['tpl'], 'order');
    return [true, $wamid];
}
/**
 * Sends the catalogue message $key to the customer. $o: who ('emp' = a salesperson pressed a button | 'order' = automatic), emp, cta [url], doc [url, filename], image [url], template_ok (default true).
 * Returns [ok, detail, code] with code '' | off | optout | window_closed | failed.
 */
function wch_deliver(PDO $pdo, string $key, array $c, array $vars, array $o = []): array
{
    if (!wch_on($pdo)) return [false, 'The chain flow is switched off.', 'off'];
    if ((int)($c['optout'] ?? 0) === 1) return [false, 'This customer opted out - do not message them.', 'optout'];
    $defs = wch_msg_all_defs(); $d = $defs[$key] ?? null; if (!$d) return [false, 'Unknown message.', 'failed'];
    $who = (string)($o['who'] ?? 'order'); $emp = (int)($o['emp'] ?? 0); $vars += ['name' => wa_first_name_safe((string)($c['name'] ?? ''))];
    if (wch_window_open($c)) {
        $text = wch_render(wch_msg_text($pdo, $key, false), $vars); $row = wch_msg_row($pdo, $key);
        if (!empty($o['doc'])) { [$ok, $r] = wch_send_doc_link($pdo, $c, $o['doc']['url'], $o['doc']['filename'], $text, $who, $emp); return $ok ? [true, $r, ''] : [false, $r, 'failed']; }
        if (!empty($o['image']) || (trim((string)$row['photo_url']) !== '' && empty($o['no_photo']))) {
            $u = !empty($o['image']) ? $o['image'] : (string)$row['photo_url']; [$ok, $r] = function_exists('wa_send_image_link') ? wa_send_image_link($pdo, $c, wch_img($u), $text, $who, $emp, 0) : [false, 'Photo sender missing'];
            if ($ok) return [true, is_array($r) ? ($r['wamid'] ?? '') : $r, '']; if (empty($o['image'])) { /* the message photo failed: send the text alone */ } else return [false, (string)$r, 'failed'];
        }
        if (!empty($o['cta']) && $d['button'] !== '') { [$ok, $r] = wch_send_cta($pdo, $c, $text, $d['button'], $o['cta'], $who, $emp); return $ok ? [true, $r, ''] : [false, $r, 'failed']; }
        [$ok, $r] = wa_send_text($pdo, $c, $text, $who, $emp); return $ok ? [true, $r, ''] : [false, $r, 'failed'];
    }
    if (($o['template_ok'] ?? true) && $d['tpl'] !== '' && $d['mode'] === 'template') { [$ok, $r] = wch_send_template($pdo, $c, $key, $vars); return $ok ? [true, $r, ''] : [false, $r, 'window_closed']; }
    return [false, "The customer's 24-hour reply window is closed. Send an approved template first so the customer can reply, then try again.", 'window_closed'];
}
function wa_first_name_safe(string $n): string { return function_exists('wa_first_name') ? wa_first_name($n) : (trim(explode(' ', trim($n))[0] ?? '') ?: 'ji'); }
function wch_img(string $u): string { return function_exists('wa_prod_img_url') ? wa_prod_img_url($u) : $u; }
function wch_notify(PDO $pdo, int $emp, string $title, string $body, int $cartId = 0): void
{
    if ($cartId > 0) wch_ev($pdo, $cartId, 'notify', 'Salesperson told: ' . $title);
    if (!function_exists('ct_push') || $emp <= 0) return;
    try { ct_push($pdo, [$emp], $title, $body, '/my_chats.html', 'wch-' . $cartId); } catch (Throwable $e) { }
}

// ------------------------------------------------------------------------------------------------------------------ the customer's answer (1 / 2 / 3 / no chain; English, Hindi, Hinglish, Gujarati)
function wch_norm_text(string $s): string
{
    $s = mb_strtolower($s); $map = []; foreach ([0x0966, 0x0AE6, 0x0660, 0x06F0, 0x09E6, 0xFF10] as $base) for ($i = 0; $i < 10; $i++) $map[html_entity_decode('&#' . ($base + $i) . ';', ENT_QUOTES, 'UTF-8')] = (string)$i;
    $s = strtr($s, $map); $s = preg_replace('/[^\p{L}\p{N}\p{M}\s]/u', ' ', $s) ?? $s; return trim((string)preg_replace('/\s+/u', ' ', $s));
}
/** Reads a customer's reply to the chain options. Returns ['type' => pick|none|ambiguous|unrelated, 'n' => int|null, 'why' => text]. */
function wch_parse_choice(string $text, int $nOptions): array
{
    $t = wch_norm_text($text); if ($t === '') return ['type' => 'unrelated', 'n' => null, 'why' => 'empty'];
    $nonePhrases = ['no chain', 'without chain', 'chain nahi', 'chain nahin', 'chain nai', 'chain nathi', 'chain na', 'nahi chahiye', 'nahin chahiye', 'nai chahiye', 'nahi chaiye', 'no need', 'not needed', 'dont want', 'do not want', 'dont need', 'don t want', 'don t need', 'don t', 'not interested', 'no thanks', 'no thank you', 'no thx',
        'only perfume', 'only perfumes', 'perfume only', 'perfumes only', 'sirf perfume', 'sirf perfumes', 'bas perfume', 'mat do', 'mat dena', 'mat lagao', 'rehne do', 'rehnedo', 'chhodo', 'chodo', 'skip', 'joiye nahi', 'joitu nathi', 'nathi joitu', 'nathi joiye', 'jaruri nahi', 'jarurat nahi', 'zarurat nahi', 'nako', 'chain nako',
        'નથી જોઈતું', 'નથી જોઈએ', 'ચેઈન નથી', 'ચેન નથી', 'જોઈતું નથી', 'नहीं चाहिए', 'नही चाहिए', 'नहीं चाहिये', 'चेन नहीं', 'चैन नहीं', 'चैन नही', 'ज़रूरत नहीं', 'जरूरत नहीं', 'नहीं', 'नही', 'ના', 'નહીં', 'નહિ', 'no', 'nope', 'nahi', 'nahin', 'nai', 'nahee', 'na', 'naa', 'nahi chain'];
    $hasNone = false; foreach ($nonePhrases as $p) { if (preg_match('/(^|\s)' . preg_quote($p, '/') . '(\s|$)/u', $t)) { $hasNone = true; break; } }
    $numWords = ['first' => 1, '1st' => 1, 'pehla' => 1, 'pehle' => 1, 'pahela' => 1, 'pehli' => 1, 'pahla' => 1, 'ek' => 1, 'एक' => 1, 'पहला' => 1, 'पहली' => 1, 'पहले' => 1, 'એક' => 1, 'પહેલું' => 1, 'પેલું' => 1, 'પહેલી' => 1,
        'two' => 2, 'second' => 2, '2nd' => 2, 'dusra' => 2, 'doosra' => 2, 'dusri' => 2, 'dusre' => 2, 'dusara' => 2, 'दूसरा' => 2, 'दूसरी' => 2, 'दो' => 2, 'બીજું' => 2, 'બીજી' => 2, 'બે' => 2, 'biju' => 2,
        'three' => 3, 'third' => 3, '3rd' => 3, 'teesra' => 3, 'tisra' => 3, 'teesri' => 3, 'tisri' => 3, 'teen' => 3, 'तीसरा' => 3, 'तीसरी' => 3, 'तीन' => 3, 'ત્રીજું' => 3, 'ત્રીજી' => 3, 'ત્રણ' => 3, 'triju' => 3,
        'four' => 4, 'fourth' => 4, '4th' => 4, 'chautha' => 4, 'char' => 4, 'चौथा' => 4, 'चार' => 4, 'five' => 5, 'fifth' => 5, '5th' => 5, 'panchva' => 5, 'paanch' => 5];
    $filler = array_flip(['option', 'opt', 'number', 'no', 'num', 'nr', 'wala', 'wali', 'waala', 'waali', 'wale', 'chain', 'chains', 'chahiye', 'chaiye', 'chahie', 'joiye', 'joie', 'joiye', 'please', 'pls', 'plz', 'ok', 'okay', 'haan', 'ha', 'han', 'ji', 'bhai', 'sir', 'madam', 'mam', 'i', 'want', 'the', 'to', 'lena', 'lunga', 'lungi', 'dena', 'de', 'dijiye', 'kijiye', 'karo', 'select', 'choose', 'pick', 'send', 'is', 'it', 'wali', 'ka', 'ki', 'ko', 'yes', 'this', 'one', 'only', 'will', 'take', 'hoon', 'hu', 'me', 'mujhe', 'mane', 'aapo', 'apo', 'thanks', 'thank', 'you', 'ty', 'for', 'of', 'with', 'and', 'hai', 'hain', 'h', 'hi', 'le', 'lo', 'kardo', 'add', 'ખાલી', 'જોઈએ', 'ચેઈન', 'ચેન', 'चाहिए', 'चेन', 'चैन', 'हाँ', 'हां', 'जी', 'वाला', 'वाली', 'नंबर', 'ઓપ્શન', 'નંબર', 'हा', 'હા', 'હાં', 'ऑप्शन', 'option']);
    $yes = ['yes', 'haan', 'han', 'ha', 'ok', 'okay', 'sure', 'theek hai', 'thik hai', 'done', 'ji haan', 'ji ha', 'हाँ', 'हां', 'हा', 'हाँ जी', 'હા', 'હાં', 'ok done'];
    $toks = explode(' ', $t); $cand = [];
    $oneWord = in_array('one', $toks, true);
    foreach ($toks as $w) { if (preg_match('/^\d{1,2}$/', $w)) { $v = (int)$w; if ($v >= 1 && $v <= 9) $cand[$v] = true; continue; } if (preg_match('/^\d+(st|nd|rd|th)$/', $w)) { $v = (int)$w; if ($v >= 1 && $v <= 9) $cand[$v] = true; continue; } if (isset($numWords[$w])) $cand[$numWords[$w]] = true; }
    if (!$cand && $oneWord) $cand[1] = true;   // "one" is a number only when no other number is there ("second one" is 2)
    if ($hasNone) {
        $rest = $t; foreach ($nonePhrases as $p) $rest = (string)preg_replace('/(^|\s)' . preg_quote($p, '/') . '(?=\s|$)/u', ' ', $rest);
        $left = array_values(array_filter(explode(' ', trim($rest)), function ($w) use ($filler, $numWords) { return $w !== '' && !isset($filler[$w]) && !isset($numWords[$w]) && !preg_match('/^\d/', $w); }));
        if ($cand) return ['type' => 'ambiguous', 'n' => null, 'why' => 'mentions a number and "no"'];
        if (count($left) > 3) return ['type' => 'ambiguous', 'n' => null, 'why' => 'long message with a no-word'];
        return ['type' => 'none', 'n' => null, 'why' => 'no-chain words'];
    }
    if ($cand) {
        $keys = array_keys($cand); if (count($keys) > 1) return ['type' => 'ambiguous', 'n' => null, 'why' => 'more than one number'];
        $v = $keys[0]; $left = []; $seen = false;
        foreach ($toks as $w) { $isNum = (preg_match('/^\d{1,2}$/', $w) || preg_match('/^\d+(st|nd|rd|th)$/', $w) || isset($numWords[$w])); if ($isNum && !$seen) { $seen = true; continue; } if (!isset($filler[$w])) $left[] = $w; }
        if ($left) return ['type' => 'ambiguous', 'n' => null, 'why' => 'a number with other words'];
        if ($v > $nOptions) return ['type' => 'ambiguous', 'n' => null, 'why' => 'option ' . $v . ' does not exist'];
        return ['type' => 'pick', 'n' => $v, 'why' => 'option number'];
    }
    if (in_array($t, $yes, true)) return $nOptions === 1 ? ['type' => 'pick', 'n' => 1, 'why' => 'yes to the only option'] : ['type' => 'ambiguous', 'n' => null, 'why' => 'yes, but which option'];
    if (preg_match('/\b\d{3,}\b/', $t)) return ['type' => 'unrelated', 'n' => null, 'why' => 'big number'];
    return ['type' => 'unrelated', 'n' => null, 'why' => 'no answer words'];
}

// ------------------------------------------------------------------------------------------------------------------ chain options: send, pick, reminder
function wch_opt_price_line(array $o): string { return $o['discount_p'] > 0 ? 'MRP ' . wch_rs($o['mrp_total_p']) . ' - you pay only ' . wch_rs($o['pay_p']) : wch_rs($o['pay_p']); }
function wch_opt_title(array $o): string { return implode(' + ', array_map(function ($c) { return $c['title'] . ($c['variant'] !== '' ? ' (' . $c['variant'] . ')' : ''); }, $o['chains'])); }
function wch_choices_text(int $n): string { return $n <= 1 ? '1' : implode(', ', range(1, $n - 1)) . ' or ' . $n; }
/** [ok, error, code, data] */
function wch_chain_send(PDO $pdo, array $cart, array $cfg, array $optionIds, bool $confirmMl, int $emp): array
{
    $id = (int)$cart['id']; $st = (string)$cart['status'];
    if (!wch_on($pdo)) return [false, 'The chain flow is switched off, so nothing can be sent to the customer yet.', 'off', null];
    if ($st === 'options_sent') return [false, 'The options were already sent. Wait for the answer or pick the chain by hand.', 'already_sent', null];
    if ($st !== 'building') return [false, 'This cart is already ' . strtolower(wch_status_labels()[$st] ?? $st) . '.', 'conflict', null];
    $ph0 = wch_price_hint($pdo, $cart); if (!empty($ph0['combo_missing'])) return [false, 'This combo has no price yet (' . $ph0['combo_missing'] . '). Ask the owner to create it in Learn & Setup → Pricing → Combos first.', 'no_combo_price', null];
    if ($ph0['lowest_p'] > 0 && (int)$cart['total_p'] < $ph0['lowest_p'] && wch_below_reason($pdo, $id) === '') return [false, 'The agreed total is below the lowest price (' . wch_rs($ph0['lowest_p']) . '). Write the reason first.', 'below_lowest', null];
    $gate = wch_billing_gate($pdo); if (!$gate['ok']) return [false, 'Fill in the Billing tab first: ' . implode(', ', $gate['missing']) . '.', 'billing_incomplete', ['missing' => $gate['missing']]];
    $lines = wch_cart_lines($cart); if (!$lines) return [false, 'Add at least one perfume first.', 'empty', null];
    $used = wch_cart_ml_used($pdo, $cart); if ($used !== (int)$cart['ml'] && !$confirmMl) return [false, 'The perfumes add up to ' . $used . ' ml but the order says ' . (int)$cart['ml'] . ' ml. Fix the cart, or send again with confirm_ml.', 'ml_mismatch', null];
    $ct = wa_contact($pdo, (string)$cart['phone']); if (!$ct) return [false, 'Chat not found.', 'not_found', null];
    if ((int)$ct['optout'] === 1) return [false, 'This customer opted out - do not message them.', 'optout', null];
    if (!wch_window_open($ct)) return [false, "The customer's 24-hour reply window is closed. Send an approved template first so the customer can reply, then send the options.", 'window_closed', null];
    $sug = wch_suggest($pdo, (int)$cart['total_p'], array_merge($cfg, ['wch_split_pct' => (float)$cart['split_pct']]));
    if (!$sug['options']) return [false, 'There is nothing to offer: no chain fits. Use "No chain" (pick none) or upload chains and refresh.', 'no_options', null];
    $by = []; foreach ($sug['options'] as $o) $by[$o['id']] = $o; $chosen = [];
    if (!$optionIds) { if ($sug['case'] === 'gap') return [false, 'No chain fits the usual way - choose which options to send.', 'choose', null]; foreach ($sug['options'] as $o) if ($o['recommended']) $chosen[] = $o; }
    else foreach ($optionIds as $oid) { if (!isset($by[$oid])) return [false, 'The option ' . $oid . ' is not available any more. Open the suggestions again.', 'gone', null]; if (!in_array($by[$oid], $chosen, true)) $chosen[] = $by[$oid]; }
    $chosen = array_slice($chosen, 0, max(1, (int)$cfg['wch_max_options'])); if (!$chosen) return [false, 'Choose at least one option to send.', 'choose', null];
    $claim = $pdo->prepare("UPDATE wch_cart SET status = 'sending', updated_at = ? WHERE id = ? AND status = 'building'"); $claim->execute([wch_dt(), $id]);   // two presses at once: only one goes on
    if ($claim->rowCount() < 1) return [false, 'The options are being sent right now. Wait a moment.', 'already_sent', null];
    $revert = function () use ($pdo, $id) { $pdo->prepare("UPDATE wch_cart SET status = 'building' WHERE id = ? AND status = 'sending'")->execute([$id]); };
    $o1 = ['who' => 'emp', 'emp' => $emp]; $name = (string)$ct['name']; $photos = 0; $texts = 0; $vars = ['name' => wa_first_name_safe($name)];
    [$ok, $r, $code] = wch_deliver($pdo, 'chain_options_intro', $ct, $vars, $o1); if (!$ok) { $revert(); return [false, (string)$r, $code === 'off' ? 'off' : ($code === 'window_closed' ? 'window_closed' : 'send_failed'), null]; } $texts++;
    foreach ($chosen as $i => $o) {
        $n = $i + 1; $cv = ['n' => (string)$n, 'chain' => wch_opt_title($o), 'price_line' => wch_opt_price_line($o)];
        foreach ($o['chains'] as $j => $c) {
            $v2 = $j === 0 ? $cv : ['n' => (string)$n, 'chain' => $c['title'] . ' (part of option ' . $n . ')', 'price_line' => 'MRP ' . wch_rs($c['mrp_p'])];
            $img = $c['photo'] !== '' ? $c['photo'] : '';
            if ($img !== '') [$ok, $r, $code] = wch_deliver($pdo, 'chain_option_photo', $ct, $v2, $o1 + ['image' => $img]); else [$ok, $r, $code] = wch_deliver($pdo, 'chain_option_photo', $ct, $v2, $o1 + ['no_photo' => true]);
            if (!$ok) { $revert(); return [false, 'Could not send option ' . $n . ': ' . $r, 'send_failed', null]; } $photos += $img !== '' ? 1 : 0; if ($img === '') $texts++;
        }
    }
    [$ok, $r, $code] = wch_deliver($pdo, 'chain_options_ask', $ct, ['choices' => wch_choices_text(count($chosen))], $o1); if (!$ok) { $revert(); return [false, 'Could not send the question: ' . $r, 'send_failed', null]; } $texts++;
    $now = wch_dt(); $upd = $pdo->prepare("UPDATE wch_cart SET status = 'options_sent', opts_json = ?, options_sent_at = ?, reminder_sent_at = NULL, escalated_at = NULL, needs_manual = 0, cust_reply = '', picked = '', picked_by = '', picked_at = NULL WHERE id = ? AND status = 'sending'");
    $upd->execute([wch_json($chosen), $now, $id]); wch_cart_touch($pdo, $id); wch_ev($pdo, $id, 'options_sent', 'Chain options sent (' . count($chosen) . ' option' . (count($chosen) > 1 ? 's' : '') . ', ' . $photos . ' photo' . ($photos === 1 ? '' : 's') . ')');
    return [true, '', '', ['photos' => $photos, 'texts' => $texts]];
}
/** Records the choice (n = number the customer saw, or 'none'), freezes the plan, sends the confirmation. [ok, error, code, data] */
function wch_chain_pick(PDO $pdo, array $cart, $pick, string $by, int $emp): array
{
    $id = (int)$cart['id']; $st = (string)$cart['status']; $cfg = wch_cfg($pdo);
    if ($st === 'chosen' || ($st !== 'options_sent' && $st !== 'building')) return [false, $st === 'chosen' ? 'Already chosen: ' . ((string)$cart['picked'] === 'none' ? 'no chain' : 'option ' . wch_pick_n($cart)) . '.' : 'This cart is already ' . strtolower(wch_status_labels()[$st] ?? $st) . '.', 'conflict', null];
    $opts = wch_arr($cart['opts_json'] ?? ''); $opt = null;
    if ($pick === 'none') {
        if ($st === 'building') {   // allowed only when there is truly nothing to offer
            $s = wch_suggest($pdo, (int)$cart['total_p'], array_merge($cfg, ['wch_split_pct' => (float)$cart['split_pct']])); if ($s['options']) return [false, 'Send the chain options first - the customer must answer before the cart is made.', 'not_sent', null];
            if (!wch_cart_lines($cart)) return [false, 'Add at least one perfume first.', 'empty', null];
        }
    } else {
        if ($st !== 'options_sent' || (string)$cart['options_sent_at'] === '') return [false, 'Send the chain options to the customer first. The manual pick is only possible after the options message went out.', 'not_sent', null];
        $n = (int)$pick; if ($n < 1 || $n > count($opts)) return [false, 'Pick a number from 1 to ' . count($opts) . ', or none.', 'bad_pick', null]; $opt = $opts[$n - 1];
    }
    [$ok, $err, $plan] = wch_plan_build($pdo, $cart, $opt); if (!$ok) return [false, $err, 'plan', null];
    $now = wch_dt(); $u = $pdo->prepare("UPDATE wch_cart SET status = 'chosen', picked = ?, picked_by = ?, picked_at = ?, plan_json = ?, needs_manual = 0 WHERE id = ? AND status = ?");
    $u->execute([$opt ? $opt['id'] : 'none', $by, $now, wch_json($plan), $id, $st]); if ($u->rowCount() < 1) return [false, 'Someone else just made the choice. Reload the cart.', 'conflict', null];
    wch_cart_touch($pdo, $id); wch_ev($pdo, $id, 'chosen', $opt ? 'Chain chosen: option ' . (int)$pick . ' (' . wch_opt_title($opt) . ') - by ' . ($by === 'customer' ? "the customer's reply" : 'the salesperson') : 'No chain - perfumes only (' . ($by === 'customer' ? "customer's reply" : 'salesperson') . ')');
    $ct = wa_contact($pdo, (string)$cart['phone']); $sent = false;
    if ($ct) { [$ok2] = $opt ? wch_deliver($pdo, 'chain_chosen', $ct, ['chain' => wch_opt_title($opt)], ['who' => 'order']) : wch_deliver($pdo, 'no_chain', $ct, ['total' => wch_rs((int)$cart['total_p'])], ['who' => 'order']); $sent = (bool)$ok2; }
    return [true, '', '', ['confirmation_sent' => $sent]];
}
function wch_pick_n(array $cart): int { foreach (wch_arr($cart['opts_json'] ?? '') as $i => $o) if (($o['id'] ?? '') === (string)$cart['picked']) return $i + 1; return 0; }

/** Inbound hook: the customer's message arrived (wired in wa.php after wq_after_in). Never throws, never blocks, ignores everything unless a cart waits for a chain answer. */
function wch_after_in(PDO $pdo, string $key, array $m = [], bool $isNew = false, array $ref = []): void
{
    try {
        if (!wch_on($pdo) || !wch_tables($pdo)) return;
        $q = $pdo->prepare("SELECT * FROM wch_cart WHERE phone = ? AND status = 'options_sent' ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $cart = $q->fetch(); if (!$cart) return;
        $type = (string)($m['type'] ?? ''); $text = '';
        if ($type === 'text') $text = (string)($m['text']['body'] ?? '');
        elseif ($type === 'interactive') $text = (string)($m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? ''));
        elseif ($type === 'button') $text = (string)($m['button']['text'] ?? '');
        if (trim($text) === '') return;
        $wamid = (string)($m['id'] ?? ''); if ($wamid !== '' && !wch_once($pdo, 'in:' . $wamid)) return;
        $ts = isset($m['timestamp']) ? (int)$m['timestamp'] : wch_now(); if ($ts > 0 && (string)$cart['options_sent_at'] !== '' && $ts + 5 < wch_ts($cart['options_sent_at'])) return;
        $opts = wch_arr($cart['opts_json'] ?? ''); $r = wch_parse_choice($text, count($opts)); $id = (int)$cart['id'];
        if ($r['type'] === 'unrelated') return;
        if ($r['type'] === 'ambiguous') {
            $pdo->prepare("UPDATE wch_cart SET needs_manual = 1, cust_reply = ?, updated_at = ? WHERE id = ? AND status = 'options_sent'")->execute([mb_substr($text, 0, 300), wch_dt(), $id]);
            wch_ev($pdo, $id, 'reply_unclear', 'Customer wrote: "' . mb_substr($text, 0, 120) . '" - pick the chain by hand'); wch_notify($pdo, (int)$cart['emp_id'], 'Customer replied about the chain', '"' . mb_substr($text, 0, 80) . '" - open the cart and pick the chain.', $id); return;
        }
        $cfg = wch_cfg($pdo); $pk = $r['type'] === 'none' ? 'none' : (int)$r['n'];
        $pdo->prepare("UPDATE wch_cart SET cust_reply = ? WHERE id = ?")->execute([mb_substr($text, 0, 300), $id]); $cart['cust_reply'] = $text;
        [$ok, $err] = wch_chain_pick($pdo, $cart, $pk, 'customer', 0);
        if ($ok) wch_notify($pdo, (int)$cart['emp_id'], 'Customer chose', ($pk === 'none' ? 'No chain' : 'Option ' . $pk) . ' for cart #' . $id . ' - send the cart link.', $id);
        else { wch_ev($pdo, $id, 'reply_failed', 'Could not use the reply "' . mb_substr($text, 0, 60) . '": ' . $err); }
    } catch (Throwable $e) { error_log('wch_after_in: ' . $e->getMessage()); }
}

// ------------------------------------------------------------------------------------------------------------------ Shopify draft order: create, poll, delete
function wch_draft_payload(array $cart, array $plan, array $cfg, array $tags = []): array
{
    $items = [];
    foreach ($plan['lines'] as $l) {
        $it = ['variantId' => wch_gid('ProductVariant', $l['vid']), 'quantity' => 1, 'priceOverride' => ['amount' => wch_amount_str($l['amount_p']), 'currencyCode' => 'INR'], 'taxable' => false];
        if ($l['ml'] !== null) $it['customAttributes'] = [['key' => 'ml', 'value' => (string)$l['ml']]];
        $items[] = $it;
    }
    $in = ['lineItems' => $items, 'phone' => '+91' . $cart['phone'], 'note' => 'WhatsApp cart #' . $cart['id'] . ' - agreed total ' . wch_rs((int)$cart['total_p']), 'tags' => $tags ?: ['wch', 'wch-' . $cart['id']], 'customAttributes' => [['key' => 'wch_cart', 'value' => (string)$cart['id']], ['key' => 'agreed_total', 'value' => wch_amount_str((int)$cart['total_p'])]],
        'taxExempt' => true, 'allowDiscountCodesInCheckout' => false];
    if (!empty($cfg['wch_ship_free'])) $in['shippingLine'] = ['title' => 'Shipping', 'priceWithCurrency' => ['amount' => '0.00', 'currencyCode' => 'INR']];
    return $in;
}
function wch_draft_create(PDO $pdo, array $cart, array $plan, array $cfg): array
{
    $q = 'mutation($input: DraftOrderInput!) { draftOrderCreate(input: $input) { draftOrder { id name status invoiceUrl totalPriceSet { shopMoney { amount } } } userErrors { field message } } }';
    $r = wch_shop($pdo, $q, ['input' => wch_draft_payload($cart, $plan, $cfg, wch_order_tags($pdo, $cart))], ['write_draft_orders', 'read_draft_orders', 'read_orders'], 'creating draft orders');
    if (!$r['ok']) return [false, $r['error'], $r['code'], null];
    $p = (array)($r['data']['draftOrderCreate'] ?? []); $ue = (array)($p['userErrors'] ?? []);
    if ($ue) return [false, 'Shopify refused the draft order: ' . mb_substr(implode('; ', array_map(function ($e) { return (string)($e['message'] ?? ''); }, $ue)), 0, 220), 'shopify_error', null];
    $d = (array)($p['draftOrder'] ?? []); if (empty($d['id']) || empty($d['invoiceUrl'])) return [false, 'Shopify did not return the draft order link.', 'shopify_error', null];
    $tot = wch_p($d['totalPriceSet']['shopMoney']['amount'] ?? 0);
    if ($tot !== (int)$cart['total_p']) { wch_draft_delete($pdo, (string)$d['id']); return [false, 'Shopify priced the draft order at ' . wch_rs($tot) . ' instead of ' . wch_rs((int)$cart['total_p']) . ' (check tax and shipping settings of the store). It was not sent.', 'shopify_error', null]; }
    return [true, '', '', ['gid' => (string)$d['id'], 'name' => (string)($d['name'] ?? ''), 'url' => (string)$d['invoiceUrl'], 'status' => (string)($d['status'] ?? 'OPEN')]];
}
function wch_draft_delete(PDO $pdo, string $gid): array
{
    $r = wch_shop($pdo, 'mutation($input: DraftOrderDeleteInput!) { draftOrderDelete(input: $input) { deletedId userErrors { field message } } }', ['input' => ['id' => $gid]], ['write_draft_orders'], 'deleting draft orders');
    if (!$r['ok']) return [false, $r['error']]; $ue = (array)($r['data']['draftOrderDelete']['userErrors'] ?? []); return $ue ? [false, (string)($ue[0]['message'] ?? 'refused')] : [true, ''];
}
/** Reads one draft order (+ its order when completed). ['ok','gone','status','order'=>[...]|null,'error','code'] */
function wch_draft_read(PDO $pdo, string $gid): array
{
    $q = 'query($id: ID!) { draftOrder(id: $id) { id name status invoiceUrl completedAt order { id legacyResourceId name displayFinancialStatus cancelledAt paymentGatewayNames totalPriceSet { shopMoney { amount } } shippingAddress { name address1 address2 city province provinceCode zip countryCodeV2 } customer { displayName } } } }';
    $r = wch_shop($pdo, $q, ['id' => $gid], ['read_draft_orders', 'read_orders'], 'reading draft orders'); if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'code' => $r['code']];
    $d = $r['data']['draftOrder'] ?? null; if (!is_array($d)) return ['ok' => true, 'gone' => true, 'status' => '', 'order' => null];
    $o = null; if (!empty($d['order']) && is_array($d['order'])) {
        $x = $d['order']; $sa = (array)($x['shippingAddress'] ?? []); $gw = array_map('strval', (array)($x['paymentGatewayNames'] ?? [])); $fin = strtoupper((string)($x['displayFinancialStatus'] ?? ''));
        $cod = false; foreach ($gw as $g) if (preg_match('/cash on delivery|\bcod\b/i', $g)) $cod = true;
        $o = ['gid' => (string)($x['id'] ?? ''), 'sid' => (string)($x['legacyResourceId'] ?? wch_gid_num((string)($x['id'] ?? ''))), 'name' => (string)($x['name'] ?? ''), 'fin' => $fin, 'cancelled' => !empty($x['cancelledAt']), 'gateways' => $gw, 'cod' => $cod, 'paid' => in_array($fin, ['PAID'], true),
            'total_p' => wch_p($x['totalPriceSet']['shopMoney']['amount'] ?? 0), 'ship' => ['name' => (string)($sa['name'] ?? ''), 'address1' => (string)($sa['address1'] ?? ''), 'address2' => (string)($sa['address2'] ?? ''), 'city' => (string)($sa['city'] ?? ''), 'province' => (string)($sa['province'] ?? ''), 'province_code' => (string)($sa['provinceCode'] ?? ''), 'zip' => (string)($sa['zip'] ?? ''), 'country' => (string)($sa['countryCodeV2'] ?? '')],
            'customer' => (string)($x['customer']['displayName'] ?? '')];
    }
    return ['ok' => true, 'gone' => false, 'status' => (string)($d['status'] ?? ''), 'order' => $o];
}

// ------------------------------------------------------------------------------------------------------------------ the cart link
function wch_summary_text(array $cart, array $plan): string
{
    $ml = 0; $ch = 0; foreach ($plan['lines'] as $l) { if ($l['kind'] === 'perfume') $ml += (int)($l['ml'] ?? 0); else $ch++; }
    return ($ml > 0 ? $ml . ' ml perfume' : 'perfumes') . ($ch > 0 ? ' and ' . $ch . ' chain' . ($ch > 1 ? 's' : '') : '');
}
/** [ok, error, code, data] */
function wch_cart_link(PDO $pdo, array $cart, array $cfg, int $emp): array
{
    $id = (int)$cart['id']; $st = (string)$cart['status'];
    if (!wch_on($pdo)) return [false, 'The chain flow is switched off, so nothing can be sent to the customer yet.', 'off', null];
    if (!in_array($st, ['chosen', 'link_sent'], true)) {
        if ($st === 'building') return [false, "Send the chain options first and wait for the customer's choice. The cart is only made after that.", 'no_choice', null];
        if ($st === 'options_sent') return [false, "Wait for the customer's chain choice first. The cart link can only be sent after the customer chose a chain or said no chain.", 'no_choice', null];
        return [false, 'This cart is already ' . strtolower(wch_status_labels()[$st] ?? $st) . '.', 'conflict', null];
    }
    $gate = wch_billing_gate($pdo); if (!$gate['ok']) return [false, 'Fill in the Billing tab first: ' . implode(', ', $gate['missing']) . '.', 'billing_incomplete', ['missing' => $gate['missing']]];
    $plan = wch_arr($cart['plan_json'] ?? ''); if (!$plan || (string)$cart['picked'] === '') return [false, "Wait for the customer's chain choice first.", 'no_choice', null];
    $ct = wa_contact($pdo, (string)$cart['phone']); if (!$ct) return [false, 'Chat not found.', 'not_found', null]; if ((int)$ct['optout'] === 1) return [false, 'This customer opted out - do not message them.', 'optout', null];
    $resent = false; $url = (string)$cart['draft_url'];
    if ((string)$cart['draft_gid'] === '' || $url === '') {
        $claim = $pdo->prepare("UPDATE wch_cart SET draft_gid = 'creating' WHERE id = ? AND draft_gid = ''"); $claim->execute([$id]); if ($claim->rowCount() < 1) return [false, 'The cart link is being made right now. Wait a moment.', 'busy', null];
        [$ok, $err, $code, $dr] = wch_draft_create($pdo, $cart, $plan, $cfg);
        if (!$ok) { $pdo->prepare("UPDATE wch_cart SET draft_gid = '' WHERE id = ? AND draft_gid = 'creating'")->execute([$id]); wch_ev($pdo, $id, 'draft_failed', mb_substr($err, 0, 250)); return [false, $err, $code, null]; }
        $pdo->prepare("UPDATE wch_cart SET draft_gid = ?, draft_name = ?, draft_url = ?, draft_status = ?, draft_at = ? WHERE id = ?")->execute([$dr['gid'], $dr['name'], $dr['url'], $dr['status'], wch_dt(), $id]); $url = $dr['url'];
        wch_ev($pdo, $id, 'draft', 'Shopify draft order ' . $dr['name'] . ' made');
    } else $resent = true;
    $vars = ['total' => wch_rs((int)$cart['total_p']), 'summary' => wch_summary_text($cart, $plan), 'link' => $url];
    [$ok, $r, $code] = wch_deliver($pdo, 'cart_ready', $ct, $vars, ['who' => 'emp', 'emp' => $emp, 'cta' => $url, 'no_photo' => true]);
    if (!$ok) { wch_ev($pdo, $id, 'link_not_sent', mb_substr((string)$r, 0, 250)); return [false, (string)$r . ' The draft order is ready; press Send cart link again once the customer can receive it.', $code === 'window_closed' ? 'window_closed' : ($code === 'off' ? 'off' : 'send_failed'), null]; }
    $pdo->prepare("UPDATE wch_cart SET status = 'link_sent', link_sent_at = COALESCE(link_sent_at, ?), last_poll_at = NULL WHERE id = ? AND status IN ('chosen','link_sent')")->execute([wch_dt(), $id]); wch_cart_touch($pdo, $id);
    wch_ev($pdo, $id, $resent ? 'link_resent' : 'link_sent', ($resent ? 'Cart link sent again' : 'Cart link sent') . ' (total ' . wch_rs((int)$cart['total_p']) . ')');
    return [true, '', '', ['url' => $url, 'draft_name' => (string)($cart['draft_name'] ?: ($dr['name'] ?? '')), 'resent' => $resent]];
}
function wch_cart_cancel(PDO $pdo, array $cart, string $reason, bool $notify, int $emp): array
{
    $id = (int)$cart['id']; $st = (string)$cart['status']; if (!in_array($st, wch_open_statuses(), true)) return [false, 'This cart is already ' . strtolower(wch_status_labels()[$st] ?? $st) . '.', 'conflict'];
    $gid = (string)$cart['draft_gid']; $note = '';
    if ($gid !== '' && $gid !== 'creating') {
        $rd = wch_draft_read($pdo, $gid);
        if ($rd['ok'] && !empty($rd['order'])) { wch_apply_order($pdo, $cart, $rd); return [false, 'The customer already placed the order (' . $rd['order']['name'] . '). It cannot be cancelled here.', 'conflict']; }
        if ($rd['ok'] && empty($rd['gone'])) { [$dok, $derr] = wch_draft_delete($pdo, $gid); $note = $dok ? ' The Shopify draft order was deleted.' : ' The Shopify draft order could not be deleted (' . $derr . ') - it stays unpaid.'; }
        elseif (!$rd['ok']) $note = ' The Shopify draft order could not be checked (' . $rd['error'] . ').';
    }
    $u = $pdo->prepare("UPDATE wch_cart SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE id = ? AND status = ?"); $u->execute([mb_substr($reason, 0, 190), wch_dt(), $id, $st]); if ($u->rowCount() < 1) return [false, 'Someone else just changed this cart. Reload it.', 'conflict'];
    wch_ev($pdo, $id, 'cancelled', 'Cancelled' . ($reason !== '' ? ': ' . mb_substr($reason, 0, 120) : '') . $note);
    if ($notify && (string)$cart['link_sent_at'] !== '') { $ct = wa_contact($pdo, (string)$cart['phone']); if ($ct) wch_deliver($pdo, 'order_cancelled', $ct, ['order' => $cart['draft_name'] ?: '#' . $id], ['who' => 'emp', 'emp' => $emp]); }
    return [true, trim($note), ''];
}

// ------------------------------------------------------------------------------------------------------------------ order matching (tick): draft status, the order, prepaid / COD, confirmation, bill
function wch_apply_order(PDO $pdo, array $cart, array $rd): bool
{
    $id = (int)$cart['id']; $o = $rd['order']; if (!$o) return false; $now = wch_dt();
    if ($o['cancelled']) { $pdo->prepare("UPDATE wch_cart SET status = 'cancelled', cancel_reason = 'Order cancelled in Shopify', order_gid = ?, order_sid = ?, order_name = ?, updated_at = ? WHERE id = ? AND status IN ('link_sent','chosen','ordered')")->execute([$o['gid'], $o['sid'], $o['name'], $now, $id]); wch_ev($pdo, $id, 'cancelled', 'Order ' . $o['name'] . ' was cancelled in Shopify'); return true; }
    $mode = $o['cod'] ? 'cod' : 'prepaid'; $paid = $o['paid'] ? 1 : 0; $first = (string)$cart['order_name'] === '';
    $pdo->prepare("UPDATE wch_cart SET status = IF(status IN ('billed'), status, 'ordered'), order_gid = ?, order_sid = ?, order_name = ?, order_mode = ?, order_paid = ?, order_at = COALESCE(order_at, ?), order_total_p = ?, ship_json = ?, draft_status = 'COMPLETED', last_poll_at = ?, updated_at = ? WHERE id = ? AND status IN ('link_sent','chosen','ordered','billed')")
        ->execute([$o['gid'], $o['sid'], $o['name'], $mode, $paid, $now, $o['total_p'], wch_json($o['ship']), $now, $now, $id]);
    if ($first) wch_ev($pdo, $id, 'order', 'Order ' . $o['name'] . ' placed (' . ($mode === 'cod' ? 'COD' : ($paid ? 'prepaid, paid' : 'prepaid, payment pending')) . ')');
    elseif ($mode === 'prepaid' && $paid && (int)$cart['order_paid'] === 0) wch_ev($pdo, $id, 'paid', 'Payment received for ' . $o['name']);
    if ($o['total_p'] !== (int)$cart['total_p']) wch_ev($pdo, $id, 'total_differs', 'Shopify order total ' . wch_rs($o['total_p']) . ' differs from the agreed ' . wch_rs((int)$cart['total_p']));
    $ready = $mode === 'cod' || $paid;
    if ($ready && wch_once($pdo, 'confirm:' . $id)) {   // "order confirmed" / "payment received" once
        $ct = wa_contact($pdo, (string)$cart['phone']);
        if ($ct) { [$ok] = wch_deliver($pdo, 'order_confirmed', $ct, ['order' => $o['name'], 'total' => wch_rs((int)$cart['total_p']), 'mode' => $mode === 'cod' ? 'COD' : 'Prepaid'], ['who' => 'order']); $pdo->prepare("UPDATE wch_cart SET order_conf_at = ? WHERE id = ?")->execute([$ok ? wch_dt() : null, $id]); }
    }
    return true;
}
/** Polls carts that wait for payment. Max $limit per run, each at most every wch_poll_sec. */
function wch_poll_carts(PDO $pdo, array $cfg, float $deadline, int $limit = 8): array
{
    $res = ['polled' => 0, 'orders' => 0, 'error' => ''];
    // orders the normal order sync already saw: our tag wch-<id> on shop_orders makes the matching instant
    try {
        $q = $pdo->prepare("SELECT order_name, tags FROM shop_orders WHERE created_at >= ? AND tags LIKE '%wch-%' ORDER BY created_at DESC LIMIT 200"); $q->execute([wch_dt(wch_now() - 3 * 86400)]);
        foreach ($q->fetchAll() as $r) if (preg_match('/(^|,)\s*wch-(\d+)\s*(,|$)/', (string)$r['tags'], $m)) $pdo->prepare("UPDATE wch_cart SET last_poll_at = NULL WHERE id = ? AND status IN ('link_sent','chosen') AND order_name = ''")->execute([(int)$m[2]]);
    } catch (Throwable $e) { }
    $q = $pdo->prepare("SELECT * FROM wch_cart WHERE ((status IN ('link_sent') AND draft_gid LIKE 'gid:%') OR (status = 'ordered' AND order_mode = 'prepaid' AND order_paid = 0 AND order_at >= ?)) AND (last_poll_at IS NULL OR last_poll_at <= ?) ORDER BY last_poll_at IS NULL DESC, last_poll_at LIMIT " . (int)$limit);
    $q->execute([wch_dt(wch_now() - 3 * 86400), wch_dt(wch_now() - (int)$cfg['wch_poll_sec'])]);
    foreach ($q->fetchAll() as $c) {
        if (microtime(true) > $deadline) break; $res['polled']++; $pdo->prepare("UPDATE wch_cart SET last_poll_at = ? WHERE id = ?")->execute([wch_dt(), $c['id']]);
        $rd = wch_draft_read($pdo, (string)$c['draft_gid']);
        if (!$rd['ok']) { $res['error'] = $rd['error']; if ($rd['code'] === 'scope_missing') break; continue; }
        if (!empty($rd['gone'])) { $pdo->prepare("UPDATE wch_cart SET status = 'cancelled', cancel_reason = 'The draft order was deleted in Shopify', updated_at = ? WHERE id = ? AND status = 'link_sent'")->execute([wch_dt(), $c['id']]); wch_ev($pdo, (int)$c['id'], 'cancelled', 'The draft order was deleted in Shopify'); continue; }
        if ($rd['status'] !== (string)$c['draft_status'] && $rd['status'] !== '') $pdo->prepare("UPDATE wch_cart SET draft_status = ? WHERE id = ?")->execute([$rd['status'], $c['id']]);
        if (!empty($rd['order'])) { if (wch_apply_order($pdo, $c, $rd)) $res['orders']++; }
    }
    return $res;
}

// ------------------------------------------------------------------------------------------------------------------ billing settings (the supplier on the bill) and the "required fields filled: 7 of 11" gate
function wch_b_defaults(): array
{
    return ['legal_name' => '', 'trade_name' => '', 'gstin' => '', 'pan' => '', 'address' => '', 'state' => '', 'state_code' => '', 'pincode' => '', 'phone' => '', 'email' => '', 'bank_name' => '', 'bank_account' => '', 'bank_ifsc' => '', 'footer' => '',
        'hsn_perfume' => '3303', 'rate_perfume' => '18', 'hsn_chain' => '7117', 'rate_chain' => '3', 'inv_prefix' => 'TM', 'inv_next' => '1', 'logo' => '', 'show_short_name' => '1', 'show_hsn' => '1', 'show_bank' => '0', 'show_qr' => '1', 'show_logo' => '1', 'show_footer' => '1', 'size' => 'a5'];
}
function wch_b_get(PDO $pdo): array
{
    $o = []; foreach (wch_b_defaults() as $k => $d) { $v = wch_get($pdo, 'wch_b_' . $k, null); $o[$k] = $v === null ? $d : (string)$v; }
    $o['split_pct'] = (string)wch_cfg($pdo)['wch_split_pct']; return $o;
}
function wch_gstin_check_char(string $g14): string
{
    $set = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $sum = 0;
    for ($i = 0; $i < 14; $i++) { $v = strpos($set, $g14[$i]); if ($v === false) return ''; $p = $v * ($i % 2 === 0 ? 1 : 2); $sum += intdiv($p, 36) + ($p % 36); }
    return $set[(36 - ($sum % 36)) % 36];
}
function wch_gstin_ok(string $g): bool { return (bool)preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $g); }
function wch_inv_locked(PDO $pdo): bool { try { return (int)$pdo->query("SELECT COUNT(*) FROM wch_inv")->fetchColumn() > 0; } catch (Throwable $e) { return false; } }
/** Validates posted values. Returns [errors[key=>text], warnings[], clean[key=>string]]. */
function wch_b_validate(array $in, array $cur): array
{
    $err = []; $warn = []; $c = [];
    $txt = function ($k, $max) use ($in, &$c) { if (array_key_exists($k, $in)) $c[$k] = mb_substr(trim((string)preg_replace('/[ \t]+/u', ' ', (string)$in[$k])), 0, $max); };
    foreach (['legal_name' => 120, 'trade_name' => 120, 'address' => 300, 'bank_name' => 80, 'bank_account' => 30, 'footer' => 400, 'email' => 120, 'phone' => 30] as $k => $m) $txt($k, $m);
    if (array_key_exists('gstin', $in)) { $g = strtoupper(preg_replace('/\s+/', '', (string)$in['gstin'])); if ($g !== '' && !wch_gstin_ok($g)) $err['gstin'] = 'A GSTIN has 15 characters, for example 24ABCDE1234F1Z5.'; else { $c['gstin'] = $g; if ($g !== '' && wch_gstin_check_char(substr($g, 0, 14)) !== $g[14]) $warn[] = 'The last character of the GSTIN does not match its check digit - please re-check it.'; } }
    if (array_key_exists('pan', $in)) { $p = strtoupper(preg_replace('/\s+/', '', (string)$in['pan'])); if ($p !== '' && !preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $p)) $err['pan'] = 'A PAN has 10 characters, for example ABCDE1234F.'; else $c['pan'] = $p; }
    if (array_key_exists('email', $in) && ($c['email'] ?? '') !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $err['email'] = 'This email address does not look right.';
    if (array_key_exists('phone', $in) && ($c['phone'] ?? '') !== '' && strlen(preg_replace('/\D/', '', $c['phone'])) < 10) $err['phone'] = 'The phone number needs at least 10 digits.';
    if (array_key_exists('pincode', $in)) { $p = preg_replace('/\s+/', '', (string)$in['pincode']); if ($p !== '' && !preg_match('/^\d{6}$/', $p)) $err['pincode'] = 'A pincode has 6 digits.'; else $c['pincode'] = $p; }
    if (array_key_exists('bank_ifsc', $in)) { $i = strtoupper(preg_replace('/\s+/', '', (string)$in['bank_ifsc'])); if ($i !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $i)) $err['bank_ifsc'] = 'An IFSC has 11 characters, for example HDFC0001234.'; else $c['bank_ifsc'] = $i; }
    $st = $cur['state']; $sc = $cur['state_code'];
    if (array_key_exists('state', $in)) { $s = trim((string)$in['state']); if ($s === '') { $c['state'] = ''; $st = ''; } else { $f = wch_state_find($s); if (!$f) $err['state'] = 'This is not an Indian state or union territory name.'; else { $c['state'] = $f['name']; $st = $f['name']; if (!array_key_exists('state_code', $in) || trim((string)$in['state_code']) === '') { $c['state_code'] = $f['code']; $sc = $f['code']; } } } }
    if (array_key_exists('state_code', $in) && trim((string)$in['state_code']) !== '') { $s = trim((string)$in['state_code']); if (!preg_match('/^\d{1,2}$/', $s) || !isset(wch_states()[str_pad($s, 2, '0', STR_PAD_LEFT)])) $err['state_code'] = 'The state code is a 2-digit GST code, for example 24 for Gujarat.'; else { $c['state_code'] = str_pad($s, 2, '0', STR_PAD_LEFT); $sc = $c['state_code']; } }
    elseif (array_key_exists('state_code', $in) && trim((string)$in['state_code']) === '' && !isset($c['state_code'])) { $c['state_code'] = ''; $sc = ''; }
    if ($sc !== '' && $st !== '' && !isset($err['state']) && !isset($err['state_code'])) { $f = wch_state_find($st); if ($f && $f['code'] !== $sc) $err['state_code'] = 'The state code ' . $sc . ' does not belong to ' . $st . ' (it is ' . $f['code'] . ').'; }
    $g = $c['gstin'] ?? $cur['gstin']; if ($g !== '' && $sc !== '' && !isset($err['gstin']) && !isset($err['state_code']) && substr($g, 0, 2) !== $sc) $err['gstin'] = 'The first two digits of the GSTIN (' . substr($g, 0, 2) . ') must be the state code (' . $sc . ').';
    foreach (['hsn_perfume', 'hsn_chain'] as $k) if (array_key_exists($k, $in)) { $h = preg_replace('/\s+/', '', (string)$in[$k]); if (!preg_match('/^\d{4,8}$/', $h)) $err[$k] = 'An HSN code has 4 to 8 digits (for example 3303).'; else $c[$k] = $h; }
    foreach (['rate_perfume', 'rate_chain'] as $k) if (array_key_exists($k, $in)) { if (!is_numeric($in[$k]) || (float)$in[$k] < 0 || (float)$in[$k] > 40) $err[$k] = 'The GST rate is a number from 0 to 40 (for example 18).'; else $c[$k] = rtrim(rtrim(number_format((float)$in[$k], 2, '.', ''), '0'), '.'); if (isset($c[$k]) && $c[$k] === '') $c[$k] = '0'; }
    if (array_key_exists('inv_prefix', $in)) { $p = strtoupper(trim((string)$in['inv_prefix'])); if (!preg_match('/^[A-Z0-9-]{1,4}$/', $p)) $err['inv_prefix'] = 'The invoice prefix is 1 to 4 letters, digits or "-" (the whole number TM/26-27/00001 may be at most 16 characters).'; else $c['inv_prefix'] = $p; }
    if (array_key_exists('inv_next', $in)) { if (!is_numeric($in['inv_next']) || (int)$in['inv_next'] < 1 || (int)$in['inv_next'] > 99999 || (string)(int)$in['inv_next'] !== trim((string)$in['inv_next'])) $err['inv_next'] = 'The next invoice number is a whole number from 1 to 99999.'; else $c['inv_next'] = (string)(int)$in['inv_next']; }
    if (array_key_exists('split_pct', $in)) { if (!is_numeric($in['split_pct']) || (float)$in['split_pct'] < 1 || (float)$in['split_pct'] > 95 || (float)$in['split_pct'] != (int)$in['split_pct']) $err['split_pct'] = 'The chain share is a whole percent from 1 to 95.'; else $c['split_pct'] = (string)(int)$in['split_pct']; }
    foreach (['show_short_name', 'show_hsn', 'show_bank', 'show_qr', 'show_logo', 'show_footer'] as $k) if (array_key_exists($k, $in)) $c[$k] = !empty($in[$k]) && (string)$in[$k] !== '0' && strtolower((string)$in[$k]) !== 'false' ? '1' : '0';
    if (array_key_exists('size', $in)) { $s = strtolower((string)$in['size']); if (!in_array($s, ['a5', 'phone'], true)) $err['size'] = 'The bill size is a5 or phone.'; else $c['size'] = $s; }
    return [$err, $warn, $c];
}
function wch_b_required(): array
{
    return ['legal_name' => 'Legal name', 'gstin' => 'GSTIN', 'address' => 'Registered address', 'state' => 'State', 'state_code' => 'State code', 'pincode' => 'Pincode', 'phone' => 'Phone', 'hsn_perfume' => 'HSN code of perfumes', 'hsn_chain' => 'HSN code of chains', 'inv_prefix' => 'Invoice prefix', 'inv_next' => 'First invoice number'];
}
function wch_b_filled(string $k, array $v): bool
{
    $x = trim((string)$v[$k]);
    switch ($k) { case 'gstin': return wch_gstin_ok($x); case 'pincode': return (bool)preg_match('/^\d{6}$/', $x); case 'phone': return strlen(preg_replace('/\D/', '', $x)) >= 10; case 'hsn_perfume': case 'hsn_chain': return (bool)preg_match('/^\d{4,8}$/', $x);
        case 'inv_prefix': return (bool)preg_match('/^[A-Z0-9-]{1,4}$/', $x); case 'inv_next': return ctype_digit($x) && (int)$x >= 1; case 'state_code': return $x !== '' && isset(wch_states()[$x]); default: return $x !== ''; }
}
function wch_billing_state(PDO $pdo, ?array $vals = null): array
{
    $v = $vals ?? wch_b_get($pdo); $d = wch_b_defaults(); $req = []; $filled = 0; $missing = []; $hints = ['gstin' => '15 characters, e.g. 24ABCDE1234F1Z5', 'pincode' => '6 digits', 'state_code' => '2-digit GST code, e.g. 24 for Gujarat', 'phone' => 'at least 10 digits', 'hsn_perfume' => '4 to 8 digits, default 3303', 'hsn_chain' => '4 to 8 digits, default 7117', 'inv_prefix' => '1 to 4 letters or digits, e.g. TM', 'inv_next' => 'the number the first invoice gets, e.g. 1'];
    foreach (wch_b_required() as $k => $label) {
        $f = wch_b_filled($k, $v); if ($f) $filled++; else $missing[] = $label;
        $r = ['key' => $k, 'label' => $label, 'filled' => $f, 'default' => $f && isset($d[$k]) && (string)$v[$k] === (string)$d[$k]]; if (!$f && isset($hints[$k])) $r['hint'] = $hints[$k]; $req[] = $r;
    }
    $total = count($req); $locked = wch_inv_locked($pdo);
    return ['required' => $req, 'filled' => $filled, 'total' => $total, 'complete' => $filled === $total, 'missing' => $missing, 'red_dot' => $filled !== $total,
        'locks' => ['inv_prefix' => $locked, 'inv_next' => $locked, 'locked' => $locked, 'why' => $locked ? 'The first invoice has been issued, so the prefix and the numbering can no longer change.' : '', 'invoices' => (int)$pdo->query("SELECT COUNT(*) FROM wch_inv")->fetchColumn()]];
}
function wch_logo_dir(): string { return __DIR__ . '/uploads/wch'; }
function wch_logo_path(array $b): string { $f = basename((string)$b['logo']); return $f !== '' && is_file(wch_logo_dir() . '/' . $f) ? wch_logo_dir() . '/' . $f : ''; }
/** data:image/png;base64,... -> saved as a JPEG-or-PNG file inside uploads/wch. [ok, filename|error] */
function wch_logo_save(string $data): array
{
    if (!preg_match('#^data:image/(png|jpe?g);base64,([A-Za-z0-9+/=\s]+)$#', $data, $m)) return [false, 'The logo must be a PNG or JPEG image.'];
    $raw = base64_decode(preg_replace('/\s+/', '', $m[2]), true); if ($raw === false || strlen($raw) < 100) return [false, 'The logo file could not be read.']; if (strlen($raw) > 600 * 1024) return [false, 'The logo is too big (600 KB at most).'];
    $info = @getimagesizefromstring($raw); if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) return [false, 'The logo must be a PNG or JPEG image.'];
    if ($info[0] > 3000 || $info[1] > 3000) return [false, 'The logo is too large in pixels (3000 x 3000 at most).'];
    // always keep a flattened JPEG (the PDF takes a JPEG as it is); a PNG with transparency is put on white
    if (function_exists('imagecreatefromstring')) { $im = @imagecreatefromstring($raw); if (!$im) return [false, 'The logo could not be opened.']; $w = imagesx($im); $h = imagesy($im); $bg = imagecreatetruecolor($w, $h); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255)); imagecopy($bg, $im, 0, 0, 0, 0, $w, $h); ob_start(); imagejpeg($bg, null, 90); $raw = (string)ob_get_clean(); imagedestroy($im); imagedestroy($bg); $ext = 'jpg'; }
    elseif ($info[2] === IMAGETYPE_JPEG) $ext = 'jpg'; else return [false, 'Upload the logo as a JPEG (this server cannot convert PNG).'];
    $dir = wch_logo_dir(); if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [false, 'The logo folder could not be created on the server.'];
    $fn = 'logo_' . substr(md5($raw), 0, 12) . '.' . $ext; if (@file_put_contents($dir . '/' . $fn, $raw) === false) return [false, 'The logo could not be saved on the server.']; return [true, $fn];
}
function wch_billing_out(PDO $pdo, array $warn = [], bool $withLogo = false): array
{
    $v = wch_b_get($pdo); $st = wch_billing_state($pdo, $v); $logoPath = wch_logo_path($v); $o = $v; unset($o['logo']);
    foreach (['rate_perfume', 'rate_chain', 'inv_next'] as $k) $o[$k] = $k === 'inv_next' ? (int)$v[$k] : (float)$v[$k]; $o['split_pct'] = (float)$v['split_pct']; $o['logo'] = $logoPath !== '';
    foreach (['show_short_name', 'show_hsn', 'show_bank', 'show_qr', 'show_logo', 'show_footer'] as $k) $o[$k] = (int)$v[$k];
    if ($withLogo && $logoPath !== '') $o['logo_data'] = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($logoPath));
    $fy = wch_fy(wch_now()); $next = (int)$v['inv_next']; $seq = (int)$pdo->query("SELECT COALESCE(last_no, 0) FROM wch_seq WHERE fy = " . $pdo->quote($fy))->fetchColumn(); $n = $seq > 0 ? $seq + 1 : (wch_inv_locked($pdo) ? 1 : $next);
    $sts = []; foreach (wch_states() as $c => $nm) $sts[] = ['name' => $nm, 'code' => (string)$c];
    return ['ok' => true, 'values' => $o, 'required' => $st['required'], 'filled' => $st['filled'], 'total' => $st['total'], 'complete' => $st['complete'], 'missing' => $st['missing'], 'red_dot' => $st['red_dot'], 'locks' => $st['locks'],
        'series' => ['example' => wch_inv_format((string)$v['inv_prefix'], $fy, $n), 'fy' => $fy, 'next_number' => $n, 'max_len' => 16], 'states' => $sts, 'limits' => ['prefix_max' => 4], 'warnings' => $warn];
}

// ------------------------------------------------------------------------------------------------------------------ GST engine (integer paise; tax is INCLUDED in the customer's price)
/** One section (all lines at one rate). Lines and section agree to the paise; CGST + SGST = the tax = IGST. */
function wch_tax_section(array $amountsP, float $ratePct, bool $intra): array
{
    $bp = (int)round($ratePct * 100); $den = 10000 + $bp; $rnd = function (int $a) use ($den) { return intdiv(2 * $a * 10000 + $den, 2 * $den); };
    $sum = array_sum($amountsP); $secTaxable = $rnd($sum); $lines = []; $acc = 0; $n = count($amountsP);
    foreach ($amountsP as $i => $a) { $t = $i === $n - 1 ? $secTaxable - $acc : $rnd($a); $acc += $t; $lines[] = ['amount_p' => $a, 'taxable_p' => $t, 'tax_p' => $a - $t]; }
    $tax = $sum - $secTaxable; $cg = intdiv($tax, 2);
    return ['lines' => $lines, 'amount_p' => $sum, 'taxable_p' => $secTaxable, 'tax_p' => $tax, 'cgst_p' => $intra ? $cg : 0, 'sgst_p' => $intra ? $tax - $cg : 0, 'igst_p' => $intra ? 0 : $tax, 'rate' => $ratePct];
}

// ------------------------------------------------------------------------------------------------------------------ invoice numbers (atomic, consecutive, restart each 1 April)
function wch_fy(int $ts): string { $y = (int)date('Y', $ts); $m = (int)date('n', $ts); $a = $m >= 4 ? $y : $y - 1; return sprintf('%02d-%02d', $a % 100, ($a + 1) % 100); }
function wch_inv_format(string $prefix, string $fy, int $seq): string { return $prefix . '/' . $fy . '/' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT); }
/**
 * Allocates the next number AND stores the invoice in ONE transaction: the counter row is locked first (so everybody queues behind it), the number is taken with
 * UPDATE ... LAST_INSERT_ID(last_no + 1), the invoice is inserted, then COMMIT. Any failure rolls the counter back, so nothing is skipped and nothing is reused.
 * [ok, error, invRow]
 */
function wch_inv_issue(PDO $pdo, array $cartRow, array $data, string $phone, string $customer, int $totalP, int $ts): array
{
    $b = wch_b_get($pdo); $fy = wch_fy($ts); $prefix = (string)$b['inv_prefix']; $startFirst = max(1, (int)$b['inv_next']); $cfg = wch_cfg($pdo);
    try {
        $any = (int)$pdo->query("SELECT COUNT(*) FROM wch_seq")->fetchColumn(); $start = $any === 0 ? $startFirst : 1;   // the first year ever begins at the setting, every later year at 1
        $pdo->prepare("INSERT IGNORE INTO wch_seq (fy, last_no) VALUES (?, ?)")->execute([$fy, $start - 1]);
    } catch (Throwable $e) { return [false, 'The invoice counter could not be prepared: ' . mb_substr($e->getMessage(), 0, 100), null]; }
    $lastErr = '';
    for ($try = 0; $try < 8; $try++) {
        $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE wch_seq SET last_no = LAST_INSERT_ID(last_no + 1) WHERE fy = ?")->execute([$fy]); $seq = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
            $ex = $pdo->prepare("SELECT * FROM wch_inv WHERE cart_id = ?"); $ex->execute([(int)$cartRow['id']]); $e = $ex->fetch(); if ($e) { if ($own) $pdo->rollBack(); return [true, '', $e]; }
            $no = wch_inv_format($prefix, $fy, $seq); if (strlen($no) > 16) { if ($own) $pdo->rollBack(); return [false, 'The invoice number ' . $no . ' would be longer than 16 characters. Use a shorter prefix.', null]; }
            $token = bin2hex(random_bytes(16)); $data['inv_no'] = $no; $data['date'] = date('Y-m-d', $ts);
            $pdo->prepare("INSERT INTO wch_inv (inv_no, fy, seq, cart_id, order_name, order_sid, phone, customer, total_p, token, token_exp, data_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$no, $fy, $seq, (int)$cartRow['id'], (string)$cartRow['order_name'], (string)$cartRow['order_sid'], $phone, mb_substr($customer, 0, 120), $totalP, $token, wch_dt($ts + $cfg['wch_view_days'] * 86400), wch_json($data), wch_dt($ts)]);
            $id = (int)$pdo->lastInsertId(); if ($own) $pdo->commit();
            $r = $pdo->prepare("SELECT * FROM wch_inv WHERE id = ?"); $r->execute([$id]); return [true, '', $r->fetch()];
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0; $lastErr = $e->getMessage();
            if ($code === 1213 || $code === 1205) { usleep(random_int(15000, 90000)); continue; }   // deadlock / lock wait: nothing was consumed, try again
            if ($code === 1062) { $q = $pdo->prepare("SELECT * FROM wch_inv WHERE cart_id = ?"); $q->execute([(int)$cartRow['id']]); $x = $q->fetch(); if ($x) return [true, '', $x]; }
            break;
        }
    }
    return [false, 'The invoice could not be saved: ' . mb_substr($lastErr, 0, 120), null];
}

// ------------------------------------------------------------------------------------------------------------------ invoice data
function wch_view_url(string $token): string { return wch_base_url() . '/empmanagment/api.php?action=wch.view&t=' . $token; }
function wch_pdf_url(string $token): string { return wch_base_url() . '/empmanagment/api.php?action=wch.pdf&t=' . $token; }
function wch_base_url(): string
{
    $u = defined('APP_URL') ? rtrim((string)APP_URL, '/') : ''; if ($u !== '') return preg_replace('#/empmanagment$#', '', $u);
    $h = (string)($_SERVER['HTTP_HOST'] ?? 'fourpirates.space'); $sch = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https'; return $sch . '://' . $h;
}
/** Customer state from the Shopify shipping address (or a hand-typed state). [ok, error, ['code','name']] */
function wch_customer_state(array $ship, string $override = ''): array
{
    if ($override !== '') { $f = wch_state_find($override); return $f ? [true, '', $f] : [false, 'This is not an Indian state: ' . $override, null]; }
    foreach ([(string)($ship['province_code'] ?? ''), (string)($ship['province'] ?? '')] as $s) { $f = wch_state_find($s); if ($f) return [true, '', $f]; }
    return [false, "The customer's state is not known. Ask for the address and enter the state.", null];
}
/** Builds the invoice content from a cart's frozen plan. [ok, error, code, data] */
function wch_invoice_build(PDO $pdo, array $cart, array $b, string $stateOverride = ''): array
{
    $plan = wch_arr($cart['plan_json'] ?? ''); if (!$plan) return [false, 'This cart has no frozen plan.', 'no_plan', null];
    $ship = wch_arr($cart['ship_json'] ?? ''); [$ok, $err, $cs] = wch_customer_state($ship, $stateOverride); if (!$ok) return [false, $err, 'state_missing', null];
    if (!wch_b_filled('gstin', $b) || !wch_b_filled('state_code', $b)) return [false, 'The billing details are incomplete.', 'billing_incomplete', null];
    $intra = $cs['code'] === (string)$b['state_code']; $secs = [];
    foreach (['perfume' => ['Perfumes', 'hsn_perfume', 'rate_perfume'], 'chain' => ['Chain', 'hsn_chain', 'rate_chain']] as $kind => [$title, $hk, $rk]) {
        $by = []; foreach ($plan['lines'] as $l) if ($l['kind'] === $kind) { $k = $l['vid']; if (!isset($by[$k])) $by[$k] = ['sku' => $l['sku'], 'name' => $l['short'] !== '' ? $l['short'] : $l['title'], 'ml' => $l['ml'], 'qty' => 0, 'amount_p' => 0]; $by[$k]['qty']++; $by[$k]['amount_p'] += $l['amount_p']; }
        if (!$by) continue; $by = array_values($by); $t = wch_tax_section(array_map(function ($x) { return $x['amount_p']; }, $by), (float)$b[$rk], $intra);
        foreach ($by as $i => &$x) { $x['taxable_p'] = $t['lines'][$i]['taxable_p']; $x['tax_p'] = $t['lines'][$i]['tax_p']; } unset($x);
        $secs[] = ['key' => $kind, 'title' => $title, 'hsn' => (string)$b[$hk], 'rate' => (float)$b[$rk], 'lines' => $by, 'amount_p' => $t['amount_p'], 'taxable_p' => $t['taxable_p'], 'tax_p' => $t['tax_p'], 'cgst_p' => $t['cgst_p'], 'sgst_p' => $t['sgst_p'], 'igst_p' => $t['igst_p']];
    }
    $total = 0; foreach ($secs as $s) $total += $s['amount_p']; if ($total !== (int)$plan['sum_p']) return [false, 'The bill does not add up to the agreed total (internal error).', 'internal', null];
    $sup = []; foreach (['legal_name', 'trade_name', 'gstin', 'pan', 'address', 'state', 'state_code', 'pincode', 'phone', 'email', 'bank_name', 'bank_account', 'bank_ifsc', 'footer', 'logo'] as $k) $sup[$k] = (string)$b[$k];
    $name = (string)($ship['name'] ?? ''); if ($name === '') { $c = wa_contact($pdo, (string)$cart['phone']); $name = $c ? (string)$c['name'] : ''; }
    $mode = $cart['order_mode'] === 'cod' ? 'COD' : 'Prepaid';
    return [true, '', '', ['supplier' => $sup, 'customer' => ['name' => $name, 'phone' => (string)$cart['phone'], 'address' => trim(implode(', ', array_filter([(string)($ship['address1'] ?? ''), (string)($ship['address2'] ?? ''), (string)($ship['city'] ?? ''), (string)($ship['zip'] ?? '')]))), 'state' => $cs['name'], 'state_code' => $cs['code']],
        'intra' => $intra, 'sections' => $secs, 'total_p' => $total, 'payment' => $mode, 'order_name' => (string)$cart['order_name'], 'cart_id' => (int)$cart['id'], 'opts' => ['show_short_name' => (int)$b['show_short_name'], 'show_hsn' => (int)$b['show_hsn'], 'show_bank' => (int)$b['show_bank'], 'show_qr' => (int)$b['show_qr'], 'show_logo' => (int)$b['show_logo'], 'show_footer' => (int)$b['show_footer'], 'size' => (string)$b['size']]]];
}
function wch_sample_data(array $b, string $state = 'Gujarat'): array
{
    $cs = wch_state_find($state) ?: ['code' => '24', 'name' => 'Gujarat']; $intra = $cs['code'] === (string)$b['state_code'] || ($b['state_code'] === '' && $cs['code'] === '24'); $secs = [];
    $defs = ['perfume' => ['Perfumes', 'hsn_perfume', 'rate_perfume', [['OUD-50', 'Oud Royal', 50, 1, 80000], ['AMB-100', 'Amber Night', 100, 1, 70000]]], 'chain' => ['Chain', 'hsn_chain', 'rate_chain', [['CH-1', 'Gold Link Chain', null, 1, 150000]]]];
    foreach ($defs as $kind => [$title, $hk, $rk, $rows]) {
        $t = wch_tax_section(array_map(function ($r) { return $r[4]; }, $rows), (float)$b[$rk], $intra); $lines = [];
        foreach ($rows as $i => $r) $lines[] = ['sku' => $r[0], 'name' => $r[1], 'ml' => $r[2], 'qty' => $r[3], 'amount_p' => $r[4], 'taxable_p' => $t['lines'][$i]['taxable_p'], 'tax_p' => $t['lines'][$i]['tax_p']];
        $secs[] = ['key' => $kind, 'title' => $title, 'hsn' => (string)$b[$hk], 'rate' => (float)$b[$rk], 'lines' => $lines, 'amount_p' => $t['amount_p'], 'taxable_p' => $t['taxable_p'], 'tax_p' => $t['tax_p'], 'cgst_p' => $t['cgst_p'], 'sgst_p' => $t['sgst_p'], 'igst_p' => $t['igst_p']];
    }
    $total = 0; foreach ($secs as $s) $total += $s['amount_p'];
    $sup = []; foreach (['legal_name', 'trade_name', 'gstin', 'pan', 'address', 'state', 'state_code', 'pincode', 'phone', 'email', 'bank_name', 'bank_account', 'bank_ifsc', 'footer', 'logo'] as $k) $sup[$k] = (string)$b[$k];
    return ['supplier' => $sup, 'customer' => ['name' => 'Sample Customer', 'phone' => '9876543210', 'address' => '12 Sample Street, Surat, 395007', 'state' => $cs['name'], 'state_code' => $cs['code']], 'intra' => $intra, 'sections' => $secs, 'total_p' => $total, 'payment' => 'Prepaid', 'order_name' => '#1042', 'cart_id' => 0,
        'inv_no' => wch_inv_format((string)$b['inv_prefix'], wch_fy(wch_now()), max(1, (int)$b['inv_next'])), 'date' => date('Y-m-d', wch_now()), 'opts' => ['show_short_name' => (int)$b['show_short_name'], 'show_hsn' => (int)$b['show_hsn'], 'show_bank' => (int)$b['show_bank'], 'show_qr' => (int)$b['show_qr'], 'show_logo' => (int)$b['show_logo'], 'show_footer' => (int)$b['show_footer'], 'size' => (string)$b['size']]];
}
function wch_data_out(array $d, ?array $inv = null): array
{
    $secs = []; foreach ($d['sections'] as $s) { $ls = []; foreach ($s['lines'] as $l) $ls[] = ['sku' => $l['sku'], 'name' => $l['name'], 'ml' => $l['ml'], 'qty' => $l['qty'], 'amount' => wch_r($l['amount_p'])];
        $secs[] = ['key' => $s['key'], 'title' => $s['title'], 'hsn' => $s['hsn'], 'rate' => $s['rate'], 'lines' => $ls, 'taxable' => wch_r($s['taxable_p']), 'cgst' => wch_r($s['cgst_p']), 'sgst' => wch_r($s['sgst_p']), 'igst' => wch_r($s['igst_p']), 'tax' => wch_r($s['tax_p']), 'total' => wch_r($s['amount_p'])]; }
    $o = ['inv_no' => $d['inv_no'] ?? null, 'date' => $d['date'] ?? null, 'order_name' => $d['order_name'], 'cart_id' => $d['cart_id'], 'phone' => $d['customer']['phone'], 'customer' => $d['customer']['name'], 'state' => $d['customer']['state'], 'state_code' => $d['customer']['state_code'], 'intra' => $d['intra'], 'payment' => $d['payment'], 'sections' => $secs, 'total' => wch_r($d['total_p'])];
    if ($inv) { $o['id'] = (int)$inv['id']; $o['view_url'] = wch_view_url($inv['token']); $o['pdf_url'] = wch_pdf_url($inv['token']); $o['sent_at'] = $inv['sent_at']; $o['created_at'] = $inv['created_at']; }
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------ bill: the same content as HTML (view link, live preview) and as a PDF written by hand
function wch_inr(int $p): string
{
    $neg = $p < 0; $p = abs($p); $r = intdiv($p, 100); $f = str_pad((string)($p % 100), 2, '0', STR_PAD_LEFT); $s = (string)$r;
    if (strlen($s) > 3) { $last = substr($s, -3); $rest = substr($s, 0, -3); $rest = (string)preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest); $s = $rest . ',' . $last; }
    return ($neg ? '-' : '') . $s . '.' . $f;
}
function wch_words(int $p): string
{
    $r = intdiv(abs($p), 100); $ps = abs($p) % 100; $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens) { return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]); };
    $three = function (int $n) use ($two, $ones) { $h = intdiv($n, 100); $x = $n % 100; return trim(($h ? $ones[$h] . ' Hundred' : '') . ($h && $x ? ' ' : '') . ($x ? $two($x) : '')); };
    if ($r === 0) $w = 'Zero'; else { $parts = []; $cr = intdiv($r, 10000000); $r %= 10000000; $lk = intdiv($r, 100000); $r %= 100000; $th = intdiv($r, 1000); $r %= 1000;
        if ($cr) $parts[] = $three($cr) . ' Crore'; if ($lk) $parts[] = $two($lk) . ' Lakh'; if ($th) $parts[] = $two($th) . ' Thousand'; if ($r) $parts[] = $three($r); $w = implode(' ', $parts); }
    return 'Rupees ' . $w . ($ps ? ' and ' . $two($ps) . ' Paise' : '') . ' Only';
}
function wch_rate_txt(float $r): string { return rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.'); }
function wch_date_txt(string $ymd): string { $t = strtotime($ymd); return $t ? date('d M Y', $t) : $ymd; }
function wch_bill_html(array $d, array $o = []): string
{
    $op = $d['opts']; $sup = $d['supplier']; $cu = $d['customer']; $phone = ($op['size'] ?? 'a5') === 'phone'; $max = $phone ? '380px' : '560px'; $logo = '';
    if (!empty($op['show_logo']) && !empty($o['logo_path']) && is_file($o['logo_path'])) $logo = '<img class="lg" alt="" src="data:image/jpeg;base64,' . base64_encode((string)file_get_contents($o['logo_path'])) . '">';
    $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Invoice ' . wch_h((string)($d['inv_no'] ?? '')) . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#f2f2f2;font:' . ($phone ? '14' : '13') . 'px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a}.bill{max-width:' . $max . ';margin:0 auto;background:#fff;padding:18px 16px;min-height:100vh}'
        . '.top{display:flex;gap:12px;align-items:center;border-bottom:2px solid #222;padding-bottom:10px}.lg{width:56px;height:56px;object-fit:contain}.nm{font-size:17px;font-weight:700}.sm{font-size:11.5px;color:#444}.t{margin:12px 0 8px;text-align:center;font-weight:700;letter-spacing:.08em}'
        . '.meta{display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px}.meta>div{flex:1 1 210px;border:1px solid #ddd;border-radius:6px;padding:8px}.k{color:#666;font-size:11px}table{width:100%;border-collapse:collapse;margin-top:6px}th{font-size:11px;text-align:left;color:#555;border-bottom:1px solid #999;padding:4px 3px}'
        . 'td{padding:5px 3px;border-bottom:1px solid #eee;vertical-align:top}.r{text-align:right}.nmsm{display:block;font-size:10.5px;color:#777}.sec{margin-top:14px;font-weight:700;font-size:13px}.secm{font-size:11px;color:#666;font-weight:400}'
        . '.sum td{border:0;padding:2px 3px;font-size:12.5px}.sum .b td{font-weight:700;border-top:1px solid #999}.grand{margin-top:14px;border:2px solid #222;border-radius:6px;padding:10px;display:flex;justify-content:space-between;font-weight:700;font-size:16px}.words{font-size:11.5px;color:#444;margin-top:6px}'
        . '.box{margin-top:12px;font-size:12px;border:1px dashed #bbb;border-radius:6px;padding:8px}.foot{margin-top:14px;font-size:11px;color:#555;white-space:pre-line}.dl{display:inline-block;margin-top:12px;padding:9px 14px;background:#111;color:#fff;border-radius:6px;text-decoration:none;font-size:13px}'
        . '@media print{body{background:#fff}.bill{padding:0}.dl{display:none}}</style></head><body><div class="bill">';
    $h .= '<div class="top">' . $logo . '<div><div class="nm">' . wch_h($sup['trade_name'] !== '' ? $sup['trade_name'] : $sup['legal_name']) . '</div>' . ($sup['trade_name'] !== '' && $sup['legal_name'] !== '' ? '<div class="sm">' . wch_h($sup['legal_name']) . '</div>' : '')
        . '<div class="sm">' . wch_h($sup['address']) . ($sup['pincode'] !== '' ? ' - ' . wch_h($sup['pincode']) : '') . '</div><div class="sm">GSTIN ' . wch_h($sup['gstin']) . ($sup['pan'] !== '' ? ' &middot; PAN ' . wch_h($sup['pan']) : '') . '</div>'
        . '<div class="sm">' . wch_h(trim($sup['phone'] . ($sup['email'] !== '' ? ' &middot; ' . $sup['email'] : ''), ' ')) . '</div></div></div><div class="t">TAX INVOICE</div>' . (!empty($d['cancelled']) ? '<div style="text-align:center;font-weight:800;color:#b00020;border:2px solid #b00020;margin:6px 0;padding:6px;letter-spacing:.06em">CANCELLED' . (is_string($d['cancelled']) ? ' &middot; ' . wch_h($d['cancelled']) : '') . '</div>' : '');
    $h .= '<div class="meta"><div><div class="k">Bill to</div><b>' . wch_h($cu['name'] !== '' ? $cu['name'] : $cu['phone']) . '</b><br>' . wch_h($cu['phone']) . ($cu['address'] !== '' ? '<br>' . wch_h($cu['address']) : '') . '<br>' . wch_h($cu['state']) . ' (' . wch_h($cu['state_code']) . ')</div>'
        . '<div><div class="k">Invoice no.</div><b>' . wch_h((string)($d['inv_no'] ?? '')) . '</b><br><span class="k">Date</span> ' . wch_h(wch_date_txt((string)($d['date'] ?? ''))) . (!empty($op['show_qr']) && $d['order_name'] !== '' ? '<br><span class="k">Order</span> ' . wch_h($d['order_name']) : '')
        . '<br><span class="k">Payment</span> ' . wch_h($d['payment']) . '<br><span class="k">Place of supply</span> ' . wch_h($cu['state']) . ' (' . wch_h($cu['state_code']) . ')</div></div>';
    foreach ($d['sections'] as $s) {
        $h .= '<div class="sec">' . wch_h($s['title']) . ' <span class="secm">' . (!empty($op['show_hsn']) ? 'HSN ' . wch_h($s['hsn']) . ' &middot; ' : '') . 'GST ' . wch_rate_txt($s['rate']) . '% included</span></div><table><tr><th>Item</th>' . (!empty($op['show_hsn']) ? '<th>HSN</th>' : '') . '<th class="r">ML</th><th class="r">Qty</th><th class="r">Amount</th></tr>';
        foreach ($s['lines'] as $l) $h .= '<tr><td><b>' . wch_h($l['sku'] !== '' ? $l['sku'] : '-') . '</b>' . (!empty($op['show_short_name']) ? '<span class="nmsm">' . wch_h($l['name']) . '</span>' : '') . '</td>' . (!empty($op['show_hsn']) ? '<td>' . wch_h($s['hsn']) . '</td>' : '') . '<td class="r">' . ($l['ml'] !== null ? (int)$l['ml'] : '') . '</td><td class="r">' . (int)$l['qty'] . '</td><td class="r">' . wch_inr($l['amount_p']) . '</td></tr>';
        $h .= '</table><table class="sum"><tr><td class="r">Taxable value</td><td class="r" style="width:90px">' . wch_inr($s['taxable_p']) . '</td></tr>';
        if ($d['intra']) $h .= '<tr><td class="r">CGST ' . wch_rate_txt($s['rate'] / 2) . '%</td><td class="r">' . wch_inr($s['cgst_p']) . '</td></tr><tr><td class="r">SGST ' . wch_rate_txt($s['rate'] / 2) . '%</td><td class="r">' . wch_inr($s['sgst_p']) . '</td></tr>';
        else $h .= '<tr><td class="r">IGST ' . wch_rate_txt($s['rate']) . '%</td><td class="r">' . wch_inr($s['igst_p']) . '</td></tr>';
        $h .= '<tr class="b"><td class="r">' . wch_h($s['title']) . ' total</td><td class="r">' . wch_inr($s['amount_p']) . '</td></tr></table>';
    }
    $h .= '<div class="grand"><span>Grand total</span><span>Rs. ' . wch_inr($d['total_p']) . '</span></div><div class="words">' . wch_h(wch_words($d['total_p'])) . '</div>';
    if (!empty($op['show_qr']) && !empty($d['qr_url']) && ($svg = wch_qr_svg((string)$d['qr_url'], 96)) !== '') $h .= '<div class="box" style="display:flex;gap:10px;align-items:center">' . $svg . '<div><b>Scan to see this bill online</b><br><span class="sm">' . wch_h((string)($d['inv_no'] ?? '')) . ($d['order_name'] !== '' ? ' &middot; Order ' . wch_h($d['order_name']) : '') . '</span></div></div>';
    if (!empty($op['show_bank']) && $sup['bank_account'] !== '') $h .= '<div class="box"><b>Bank details</b><br>' . wch_h($sup['bank_name']) . '<br>A/c ' . wch_h($sup['bank_account']) . ' &middot; IFSC ' . wch_h($sup['bank_ifsc']) . '</div>';
    if (!empty($op['show_footer']) && $sup['footer'] !== '') $h .= '<div class="foot">' . wch_h($sup['footer']) . '</div>';
    $h .= '<div class="foot">This is a computer generated invoice. Prices include GST.</div>';
    if (!empty($o['pdf_url'])) $h .= '<a class="dl" href="' . wch_h($o['pdf_url']) . '">Download PDF</a>';
    return $h . '</div></body></html>';
}

// ---- PDF (core Helvetica, no library)
/** Widths of the 95 printable ASCII characters (1000 units per em), from the Adobe core font metrics. */
function wch_pdf_widths(bool $bold): array { static $h = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584], $b = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584]; return $bold ? $b : $h; }
function wch_pdf_text_w(string $s, float $size, bool $bold): float
{
    $w = wch_pdf_widths($bold); $t = 0; $n = strlen($s); for ($i = 0; $i < $n; $i++) { $c = ord($s[$i]); $t += ($c >= 32 && $c <= 126) ? $w[$c - 32] : 556; } return $t * $size / 1000;
}
/** UTF-8 -> bytes of the Windows-1252 page the PDF font uses; the rupee sign becomes "Rs.". */
function wch_pdf_enc(string $s): string
{
    $s = str_replace(["\u{20B9}", "\u{2013}", "\u{2014}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"], ['Rs.', '-', '-', "'", "'", '"', '"'], $s); $s = preg_replace('/[\r\n\t]+/', ' ', $s) ?? $s;
    $o = function_exists('iconv') ? @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) : false; if ($o === false) $o = preg_replace('/[^\x20-\x7E]/', '?', $s); return (string)$o;
}
function wch_pdf_esc(string $s): string { return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s); }
function wch_pdf_wrap(string $s, float $size, bool $bold, float $maxW): array
{
    $s = wch_pdf_enc($s); $out = []; foreach (explode("\n", $s) as $para) {
        $line = ''; foreach (preg_split('/ +/', $para) as $w) { $try = $line === '' ? $w : $line . ' ' . $w; if (wch_pdf_text_w($try, $size, $bold) <= $maxW || $line === '') { $line = $try; } else { $out[] = $line; $line = $w; } } $out[] = $line;
    } return $out ?: [''];
}
// ------------------------------------------------------------------------------------------------------------------ QR code (byte mode, error correction L, versions 1-10: enough for a 270 character link)
/** Returns the matrix (rows of 0/1) of a QR code for $text, or null when the text is too long (> 271 bytes). */
function wch_qr_matrix(string $text, ?int $forceMask = null): ?array
{
    $T = [1 => [19, 7, 1, 0, 0], 2 => [34, 10, 1, 0, 0], 3 => [55, 15, 1, 0, 0], 4 => [80, 20, 1, 0, 0], 5 => [108, 26, 1, 0, 0], 6 => [68, 18, 2, 0, 0], 7 => [78, 20, 2, 0, 0], 8 => [97, 24, 2, 0, 0], 9 => [116, 30, 2, 0, 0], 10 => [68, 18, 2, 69, 2]];   // [data per block, ec per block, blocks, data of the 2nd kind, blocks of the 2nd kind]
    $AL = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
    $n = strlen($text); $ver = 0; $cap = 0;
    foreach ($T as $v => $t) { $cw = $t[0] * $t[2] + $t[3] * $t[4]; $capB = $cw - ($v < 10 ? 2 : 3); if ($n <= $capB) { $ver = $v; $cap = $cw; break; } }
    if ($ver === 0) return null; $t = $T[$ver];
    // 1. data bits
    $bits = '0100' . str_pad(decbin($n), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT); for ($i = 0; $i < $n; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $cap * 8 - strlen($bits))); if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    $data = []; for ($i = 0; $i < strlen($bits); $i += 8) $data[] = bindec(substr($bits, $i, 8)); for ($p = 0; count($data) < $cap; $p++) $data[] = $p % 2 ? 0x11 : 0xEC;
    // 2. Reed-Solomon over GF(256)
    $exp = array_fill(0, 512, 0); $log = array_fill(0, 256, 0); $x = 1; for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11D; } for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    $ecn = $t[1]; $g = [1]; for ($i = 0; $i < $ecn; $i++) { $ng = array_fill(0, count($g) + 1, 0); foreach ($g as $j => $c) { $ng[$j] ^= $c; $ng[$j + 1] ^= $c ? $exp[$log[$c] + $i] : 0; } $g = $ng; }
    $blocks = []; $pos = 0; foreach ([[$t[0], $t[2]], [$t[3], $t[4]]] as [$len, $cnt]) for ($b = 0; $b < $cnt; $b++) { $blocks[] = array_slice($data, $pos, $len); $pos += $len; }
    $ecs = []; foreach ($blocks as $blk) { $r = array_merge($blk, array_fill(0, $ecn, 0)); for ($i = 0; $i < count($blk); $i++) { $f = $r[$i]; if ($f) for ($j = 0; $j <= $ecn; $j++) $r[$i + $j] ^= $g[$j] ? $exp[$log[$g[$j]] + $log[$f]] : 0; } $ecs[] = array_slice($r, count($blk)); }
    $out = []; $maxd = max(array_map('count', $blocks)); for ($i = 0; $i < $maxd; $i++) foreach ($blocks as $blk) if (isset($blk[$i])) $out[] = $blk[$i]; for ($i = 0; $i < $ecn; $i++) foreach ($ecs as $e) $out[] = $e[$i];
    $bs = ''; foreach ($out as $c) $bs .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
    // 3. the matrix: function patterns first (-1 = free, 2 = reserved / fixed)
    $N = 17 + 4 * $ver; $M = array_fill(0, $N, array_fill(0, $N, -1)); $fixed = array_fill(0, $N, array_fill(0, $N, false));
    $set = function ($r, $c, $v) use (&$M, &$fixed, $N) { if ($r >= 0 && $c >= 0 && $r < $N && $c < $N) { $M[$r][$c] = $v; $fixed[$r][$c] = true; } };
    foreach ([[0, 0], [0, $N - 7], [$N - 7, 0]] as [$r0, $c0]) { for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) { $in = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6; $v = $in && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) ? 1 : 0; $set($r0 + $r, $c0 + $c, $v); } }
    for ($i = 8; $i < $N - 8; $i++) { $set(6, $i, $i % 2 ? 0 : 1); $set($i, 6, $i % 2 ? 0 : 1); }
    $a = $AL[$ver]; foreach ($a as $ar) foreach ($a as $ac) { if (($ar === 6 && $ac === 6) || ($ar === 6 && $ac === end($a)) || ($ac === 6 && $ar === end($a))) continue; for ($r = -2; $r <= 2; $r++) for ($c = -2; $c <= 2; $c++) $set($ar + $r, $ac + $c, max(abs($r), abs($c)) === 1 ? 0 : 1); }
    $set($N - 8, 8, 1); for ($i = 0; $i < 9; $i++) { if (!$fixed[8][$i]) $set(8, $i, 0); if (!$fixed[$i][8]) $set($i, 8, 0); } for ($i = 0; $i < 8; $i++) { $set(8, $N - 1 - $i, 0); $set($N - 1 - $i, 8, 0); } $set($N - 8, 8, 1);
    if ($ver >= 7) { $vi = $ver << 12; $rem = $ver; for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) ? 0x1F25 : 0); $vi = ($ver << 12) | ($rem & 0xFFF); for ($i = 0; $i < 18; $i++) { $b = ($vi >> $i) & 1; $set((int)floor($i / 3), $N - 11 + $i % 3, $b); $set($N - 11 + $i % 3, (int)floor($i / 3), $b); } }
    // 4. data placement (zig-zag, bottom right, upwards first)
    $k = 0; $up = true; $total = strlen($bs); $free = [];
    for ($c = $N - 1; $c > 0; $c -= 2) { if ($c === 6) $c--; for ($i = 0; $i < $N; $i++) { $r = $up ? $N - 1 - $i : $i; for ($d = 0; $d < 2; $d++) { $cc = $c - $d; if (!$fixed[$r][$cc]) $free[] = [$r, $cc]; } } $up = !$up; }
    $masks = [function ($r, $c) { return ($r + $c) % 2 === 0; }, function ($r, $c) { return $r % 2 === 0; }, function ($r, $c) { return $c % 3 === 0; }, function ($r, $c) { return ($r + $c) % 3 === 0; }, function ($r, $c) { return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0; },
        function ($r, $c) { return ($r * $c) % 2 + ($r * $c) % 3 === 0; }, function ($r, $c) { return (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0; }, function ($r, $c) { return (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0; }];
    $best = null; $bestP = PHP_INT_MAX;
    for ($m = 0; $m < 8; $m++) {
        if ($forceMask !== null && $m !== $forceMask) continue;
        $Q = $M; foreach ($free as $i => [$r, $c]) { $bit = $i < $total ? (int)$bs[$i] : 0; if ($masks[$m]($r, $c)) $bit ^= 1; $Q[$r][$c] = $bit; }
        // format information (error correction L = 01)
        $fi = (1 << 3) | $m; $rem = $fi; for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) ? 0x537 : 0); $f = (($fi << 10) | ($rem & 0x3FF)) ^ 0x5412;
        for ($i = 0; $i < 15; $i++) { $b = ($f >> $i) & 1; if ($i < 6) $Q[$i][8] = $b; elseif ($i < 8) $Q[$i + 1][8] = $b; else $Q[$N - 15 + $i][8] = $b; if ($i < 8) $Q[8][$N - 1 - $i] = $b; elseif ($i < 9) $Q[8][15 - $i] = $b; else $Q[8][14 - $i] = $b; }
        $Q[$N - 8][8] = 1; $p = wch_qr_penalty($Q, $N); if ($p < $bestP) { $bestP = $p; $best = $Q; }
    }
    return $best;
}
function wch_qr_penalty(array $Q, int $N): int
{
    $p = 0;
    for ($r = 0; $r < $N; $r++) for ($dir = 0; $dir < 2; $dir++) { $run = 1; for ($i = 1; $i < $N; $i++) { $a = $dir ? $Q[$i][$r] : $Q[$r][$i]; $b = $dir ? $Q[$i - 1][$r] : $Q[$r][$i - 1]; if ($a === $b) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; } else $run = 1; } }
    for ($r = 0; $r < $N - 1; $r++) for ($c = 0; $c < $N - 1; $c++) if ($Q[$r][$c] === $Q[$r][$c + 1] && $Q[$r][$c] === $Q[$r + 1][$c] && $Q[$r][$c] === $Q[$r + 1][$c + 1]) $p += 3;
    $pat = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0]; $rev = array_reverse($pat);
    for ($r = 0; $r < $N; $r++) for ($c = 0; $c <= $N - 11; $c++) for ($dir = 0; $dir < 2; $dir++) { $a = true; $b = true; for ($i = 0; $i < 11; $i++) { $v = $dir ? $Q[$c + $i][$r] : $Q[$r][$c + $i]; if ($v !== $pat[$i]) $a = false; if ($v !== $rev[$i]) $b = false; if (!$a && !$b) break; } if ($a) $p += 40; if ($b) $p += 40; }
    $dark = 0; foreach ($Q as $row) $dark += array_sum($row); $p += (int)(abs((int)round($dark * 100 / ($N * $N)) - 50) / 5) * 10;
    return $p;
}
function wch_qr_svg(string $text, int $px = 96): string
{
    $m = wch_qr_matrix($text); if (!$m) return ''; $n = count($m); $d = '';
    for ($r = 0; $r < $n; $r++) { $c = 0; while ($c < $n) { if ($m[$r][$c] === 1) { $s = $c; while ($c < $n && $m[$r][$c] === 1) $c++; $d .= 'M' . ($s + 2) . ' ' . ($r + 2) . 'h' . ($c - $s) . 'v1h-' . ($c - $s) . 'z'; } else $c++; } }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $px . '" height="' . $px . '" viewBox="0 0 ' . ($n + 4) . ' ' . ($n + 4) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
}

class WchPdf
{
    public $pages = [[]]; public $y = 0; public $W; public $Hpage; public $m; public $img = null; public $imgOps = [];
    public function __construct(float $w, float $h, float $m) { $this->W = $w; $this->Hpage = $h; $this->m = $m; $this->y = $m; }
    public function ensure(float $h): bool { if ($this->y + $h > $this->Hpage - $this->m) { $this->pages[] = []; $this->y = $this->m; return true; } return false; }
    public function op(array $o): void { $this->pages[count($this->pages) - 1][] = $o; }
    public function text(float $x, float $y, string $s, float $size, bool $bold = false, string $al = 'l', float $gray = 0): void
    {
        $e = wch_pdf_enc($s); if ($e === '') return; $w = wch_pdf_text_w($e, $size, $bold); if ($al === 'r') $x -= $w; elseif ($al === 'c') $x -= $w / 2; $this->op(['t', $x, $y, $e, $size, $bold, $gray]);
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5, float $gray = 0): void { $this->op(['l', $x1, $y1, $x2, $y2, $w, $gray]); }
    public function rect(float $x, float $y, float $w, float $h, float $lw = 0.8, ?float $fill = null): void { $this->op(['r', $x, $y, $w, $h, $lw, $fill]); }
    public function image(float $x, float $y, float $w, float $h): void { $this->op(['i', $x, $y, $w, $h]); }
    public function fill(float $x, float $y, float $w, float $h): void { $this->op(['f', $x, $y, $w, $h]); }
    /** Whole file. $tall = the page is as high as the content (phone). */
    public function output(bool $tall, ?array $img, string $title): string
    {
        $objs = []; $add = function (string $body) use (&$objs) { $objs[] = $body; return count($objs); };
        $fR = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'); $fB = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $imgId = 0; if ($img) $imgId = $add('<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h'] . ' /ColorSpace /' . $img['cs'] . ' /BitsPerComponent 8 /Filter /DCTDecode' . ($img['cs'] === 'DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '') . ' /Length ' . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream");
        $pagesIdx = count($objs) + 1 + count($this->pages) * 2; $pageIds = []; $H = $tall ? max(120, $this->y + $this->m) : $this->Hpage;
        foreach ($this->pages as $ops) {
            $c = ''; foreach ($ops as $o) {
                if ($o[0] === 't') { $c .= sprintf("BT /%s %.2F Tf %.3F g %.2F %.2F Td (%s) Tj ET\n", $o[5] ? 'F2' : 'F1', $o[4], $o[6], $o[1], $H - $o[2], wch_pdf_esc($o[3])); }
                elseif ($o[0] === 'l') { $c .= sprintf("%.2F w %.3F G %.2F %.2F m %.2F %.2F l S\n", $o[5], $o[6], $o[1], $H - $o[2], $o[3], $H - $o[4]); }
                elseif ($o[0] === 'r') { $c .= ($o[6] !== null ? sprintf("%.3F g %.2F %.2F %.2F %.2F re f 0 g\n", $o[6], $o[1], $H - $o[2] - $o[4], $o[3], $o[4]) : '') . sprintf("%.2F w 0 G %.2F %.2F %.2F %.2F re S\n", $o[5], $o[1], $H - $o[2] - $o[4], $o[3], $o[4]); }
                elseif ($o[0] === 'f') { $c .= sprintf("0 g %.2F %.2F %.2F %.2F re f\n", $o[1], $H - $o[2] - $o[4], $o[3], $o[4]); }
                elseif ($o[0] === 'i' && $imgId) { $c .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $o[3], $o[4], $o[1], $H - $o[2] - $o[4]); }
            }
            $z = function_exists('gzcompress') ? gzcompress($c, 6) : false; $cid = $add($z !== false ? '<< /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n" . $z . "\nendstream" : '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . "\nendstream");
            $pageIds[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >>%s >> /Contents %d 0 R >>', $pagesIdx, $this->W, $H, $fR, $fB, $imgId ? " /XObject << /Im1 $imgId 0 R >>" : '', $cid));
        }
        $pg = $add('<< /Type /Pages /Kids [' . implode(' ', array_map(function ($i) { return $i . ' 0 R'; }, $pageIds)) . '] /Count ' . count($pageIds) . ' >>'); $cat = $add('<< /Type /Catalog /Pages ' . $pg . ' 0 R >>');
        $info = $add('<< /Title (' . wch_pdf_esc(wch_pdf_enc($title)) . ') /Producer (HRMS) /CreationDate (D:' . date('YmdHis', wch_now()) . "+05'30') >>");
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = []; foreach ($objs as $i => $b) { $off[$i + 1] = strlen($out); $out .= ($i + 1) . " 0 obj\n" . $b . "\nendobj\n"; }
        $x = strlen($out); $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n"; foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . ' /Root ' . $cat . ' 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $x . "\n%%EOF\n";
    }
}
function wch_pdf_render(array $d, string $logoPath = ''): string
{
    $op = $d['opts']; $sup = $d['supplier']; $cu = $d['customer']; $phone = ($op['size'] ?? 'a5') === 'phone';
    $W = $phone ? 300.0 : 419.53; $Hp = $phone ? 6000.0 : 595.28; $m = $phone ? 14.0 : 26.0; $p = new WchPdf($W, $Hp, $m); $L = $m; $R = $W - $m; $cw = $R - $L; $fs = $phone ? 8.2 : 7.6; $lh = $fs + 2.6;
    $img = null; if (!empty($op['show_logo']) && $logoPath !== '' && is_file($logoPath)) { $raw = (string)file_get_contents($logoPath); $gi = @getimagesizefromstring($raw); if ($gi && $gi[2] === IMAGETYPE_JPEG) { $ch = $gi['channels'] ?? 3; $img = ['data' => $raw, 'w' => $gi[0], 'h' => $gi[1], 'cs' => $ch === 1 ? 'DeviceGray' : ($ch === 4 ? 'DeviceCMYK' : 'DeviceRGB')]; } }
    // header
    $x0 = $L; $hy = $p->y;
    if ($img) { $sz = $phone ? 34.0 : 40.0; $r = min($sz / $img['w'], $sz / $img['h']); $iw = $img['w'] * $r; $ih = $img['h'] * $r; $p->image($L, $hy, $iw, $ih); $x0 = $L + $sz + 8; }
    $nm = $sup['trade_name'] !== '' ? $sup['trade_name'] : $sup['legal_name']; $p->text($x0, $hy + 11, $nm, $phone ? 11.5 : 13, true); $yy = $hy + 11 + $lh;
    if ($sup['trade_name'] !== '' && $sup['legal_name'] !== '') { $p->text($x0, $yy, $sup['legal_name'], $fs, false, 'l', 0.25); $yy += $lh; }
    foreach (wch_pdf_wrap($sup['address'] . ($sup['pincode'] !== '' ? ' - ' . $sup['pincode'] : ''), $fs, false, $R - $x0) as $ln) { $p->text($x0, $yy, $ln, $fs, false, 'l', 0.25); $yy += $lh; }
    $p->text($x0, $yy, 'GSTIN ' . $sup['gstin'] . ($sup['pan'] !== '' ? '   PAN ' . $sup['pan'] : ''), $fs, false, 'l', 0.25); $yy += $lh;
    $ct = trim($sup['phone'] . ($sup['email'] !== '' ? '   ' . $sup['email'] : '')); if ($ct !== '') { $p->text($x0, $yy, $ct, $fs, false, 'l', 0.25); $yy += $lh; }
    $p->y = max($yy, $hy + ($img ? 44 : 0)) + 2; $p->line($L, $p->y, $R, $p->y, 1.2); $p->y += 14; $p->text($W / 2, $p->y, 'TAX INVOICE', 10.5, true, 'c'); $p->y += 10; if (!empty($d['cancelled'])) { $p->text($W / 2, $p->y + 4, '*** CANCELLED ***', 11, true, 'c'); $p->y += 16; }
    // bill to / invoice
    $half = ($cw - 8) / 2; $by = $p->y; $left = ['Bill to', $cu['name'] !== '' ? $cu['name'] : $cu['phone'], $cu['phone']]; foreach (wch_pdf_wrap($cu['address'], $fs, false, $half - 8) as $ln) if ($ln !== '') $left[] = $ln; $left[] = $cu['state'] . ' (' . $cu['state_code'] . ')';
    $right = [['Invoice no.', (string)($d['inv_no'] ?? '')], ['Date', wch_date_txt((string)($d['date'] ?? ''))], ['Payment', $d['payment']]]; if (!empty($op['show_qr']) && $d['order_name'] !== '') $right[] = ['Order', $d['order_name']]; $right[] = ['Place of supply', $cu['state'] . ' (' . $cu['state_code'] . ')'];
    $bh = max(count($left), count($right)) * $lh + 8; $p->ensure($bh + 4); $by = $p->y; $p->rect($L, $by, $half, $bh, 0.6); $p->rect($L + $half + 8, $by, $half, $bh, 0.6);
    foreach ($left as $i => $ln) $p->text($L + 5, $by + 9 + $i * $lh, $ln, $i === 1 ? $fs + 0.8 : $fs, $i === 1, 'l', $i === 0 ? 0.4 : 0);
    foreach ($right as $i => [$k, $v]) { $p->text($L + $half + 13, $by + 9 + $i * $lh, $k, $fs - 0.6, false, 'l', 0.4); $p->text($R - 5, $by + 9 + $i * $lh, $v, $fs, true, 'r'); }
    $p->y = $by + $bh + 12;
    // columns
    $showHsn = !empty($op['show_hsn']) && !$phone; $cAm = $R; $cQty = $cAm - ($phone ? 56 : 66); $cMl = $cQty - ($phone ? 22 : 26); $cHsn = $showHsn ? $cMl - 30 : $cMl; $cItem = $L + 2;
    $head = function () use ($p, $cItem, $cHsn, $cMl, $cQty, $cAm, $L, $R, $fs, $showHsn) { $p->line($L, $p->y + 2, $R, $p->y + 2, 0.7); $p->text($cItem, $p->y, 'Item', $fs - 0.5, true, 'l', 0.3); if ($showHsn) $p->text($cHsn - 26, $p->y, 'HSN', $fs - 0.5, true, 'l', 0.3); $p->text($cMl, $p->y, 'ML', $fs - 0.5, true, 'r', 0.3); $p->text($cQty, $p->y, 'Qty', $fs - 0.5, true, 'r', 0.3); $p->text($cAm, $p->y, 'Amount', $fs - 0.5, true, 'r', 0.3); $p->y += 6; $p->line($L, $p->y, $R, $p->y, 0.4, 0.5); $p->y += 10; };
    foreach ($d['sections'] as $s) {
        $p->ensure(60); $p->rect($L, $p->y - 8, $cw, 14, 0.4, 0.93); $p->text($L + 4, $p->y + 2, $s['title'] . '   ' . (!empty($op['show_hsn']) ? 'HSN ' . $s['hsn'] . '   ' : '') . 'GST ' . wch_rate_txt($s['rate']) . '% included', $fs + 0.3, true); $p->y += 16; $head();
        foreach ($s['lines'] as $l) {
            $two = !empty($op['show_short_name']) && $l['name'] !== ''; $rh = $two ? $lh + 7 : $lh + 1; if ($p->ensure($rh + 4)) { $head(); }
            $p->text($cItem, $p->y, $l['sku'] !== '' ? $l['sku'] : '-', $fs, true); if ($two) $p->text($cItem, $p->y + 7.4, $l['name'], $fs - 1.4, false, 'l', 0.4);
            if ($showHsn) $p->text($cHsn - 26, $p->y, $s['hsn'], $fs); $p->text($cMl, $p->y, $l['ml'] !== null ? (string)(int)$l['ml'] : '', $fs, false, 'r'); $p->text($cQty, $p->y, (string)(int)$l['qty'], $fs, false, 'r'); $p->text($cAm, $p->y, wch_inr($l['amount_p']), $fs, false, 'r');
            $p->y += $rh;
        }
        $p->line($L, $p->y - 6, $R, $p->y - 6, 0.4, 0.5); $p->ensure(60); $p->y += 4; $lab = $cAm - 70;
        $rows = [['Taxable value', $s['taxable_p'], false]]; if ($d['intra']) { $rows[] = ['CGST ' . wch_rate_txt($s['rate'] / 2) . '%', $s['cgst_p'], false]; $rows[] = ['SGST ' . wch_rate_txt($s['rate'] / 2) . '%', $s['sgst_p'], false]; } else $rows[] = ['IGST ' . wch_rate_txt($s['rate']) . '%', $s['igst_p'], false];
        $rows[] = [$s['title'] . ' total', $s['amount_p'], true];
        foreach ($rows as [$k, $v, $b]) { $p->text($lab, $p->y, $k, $fs, $b, 'r'); $p->text($cAm, $p->y, wch_inr($v), $fs, $b, 'r'); $p->y += $lh; } $p->y += 8;
    }
    $p->ensure(70); $p->rect($L, $p->y, $cw, 24, 1.2); $p->text($L + 8, $p->y + 16, 'Grand total', 10.5, true); $p->text($R - 8, $p->y + 16, 'Rs. ' . wch_inr($d['total_p']), 11.5, true, 'r'); $p->y += 24 + 11;
    foreach (wch_pdf_wrap(wch_words($d['total_p']), $fs - 0.4, false, $cw) as $ln) { $p->text($L, $p->y, $ln, $fs - 0.4, false, 'l', 0.3); $p->y += $lh - 1; } $p->y += 6;
    if (!empty($op['show_qr']) && !empty($d['qr_url']) && ($qm = wch_qr_matrix((string)$d['qr_url']))) {   // the QR opens the same bill online
        $qn = count($qm); $qs = $phone ? 54.0 : 62.0; $mod = $qs / ($qn + 4); $p->ensure($qs + 8); $qy = $p->y;
        for ($r = 0; $r < $qn; $r++) { $c = 0; while ($c < $qn) { if ($qm[$r][$c] === 1) { $s0 = $c; while ($c < $qn && $qm[$r][$c] === 1) $c++; $p->fill($L + ($s0 + 2) * $mod, $qy + ($r + 2) * $mod, ($c - $s0) * $mod, $mod); } else $c++; } }
        $p->text($L + $qs + 8, $qy + 20, 'Scan to see this bill online', $fs, true); if ($d['order_name'] !== '') $p->text($L + $qs + 8, $qy + 20 + $lh, 'Order ' . $d['order_name'], $fs, false, 'l', 0.3); $p->text($L + $qs + 8, $qy + 20 + 2 * $lh, (string)($d['inv_no'] ?? ''), $fs, false, 'l', 0.3); $p->y = $qy + $qs + 8;
    }
    if (!empty($op['show_bank']) && $sup['bank_account'] !== '') { $p->ensure(40); $p->text($L, $p->y, 'Bank details', $fs, true); $p->y += $lh; $p->text($L, $p->y, trim($sup['bank_name'] . '   A/c ' . $sup['bank_account'] . '   IFSC ' . $sup['bank_ifsc']), $fs); $p->y += $lh + 4; }
    if (!empty($op['show_footer']) && $sup['footer'] !== '') foreach (wch_pdf_wrap($sup['footer'], $fs - 0.5, false, $cw) as $ln) { $p->ensure($lh + 2); $p->text($L, $p->y, $ln, $fs - 0.5, false, 'l', 0.3); $p->y += $lh - 0.5; }
    $p->ensure($lh + 6); $p->y += 4; $p->text($L, $p->y, 'This is a computer generated invoice. Prices include GST.', $fs - 1, false, 'l', 0.45); $p->y += $lh;
    return $p->output($phone, $img, 'Invoice ' . (string)($d['inv_no'] ?? ''));
}

// ------------------------------------------------------------------------------------------------------------------ the bill of one order: make, send, public view
function wch_inv_row(PDO $pdo, $by, string $col = 'id'): ?array
{
    if (!in_array($col, ['id', 'inv_no', 'cart_id', 'order_name', 'token'], true)) return null; $q = $pdo->prepare("SELECT * FROM wch_inv WHERE $col = ? ORDER BY id DESC LIMIT 1"); $q->execute([$by]); $r = $q->fetch(); return $r ?: null;
}
/** Makes the invoice of an order (idempotent). [ok, error, code, invRow] */
function wch_bill_make(PDO $pdo, array $cart, string $stateOverride = '', bool $force = false): array
{
    if ((int)$cart['inv_id'] > 0) { $r = wch_inv_row($pdo, (int)$cart['inv_id']); if ($r) return [true, '', '', $r]; }
    $ex = wch_inv_row($pdo, (int)$cart['id'], 'cart_id'); if ($ex) { $pdo->prepare("UPDATE wch_cart SET inv_id = ? WHERE id = ?")->execute([$ex['id'], $cart['id']]); return [true, '', '', $ex]; }
    if ((string)$cart['order_name'] === '') return [false, 'There is no Shopify order for this cart yet.', 'no_order', null];
    if ((string)$cart['order_mode'] === 'prepaid' && (int)$cart['order_paid'] !== 1 && !$force) return [false, 'The order is prepaid and the payment has not arrived yet. The bill is made after payment.', 'not_paid', null];
    $b = wch_b_get($pdo); [$ok, $err, $code, $data] = wch_invoice_build($pdo, $cart, $b, $stateOverride); if (!$ok) return [false, $err, $code, null];
    $ts = wch_now(); [$ok, $err, $inv] = wch_inv_issue($pdo, $cart, $data, (string)$cart['phone'], (string)$data['customer']['name'], (int)$data['total_p'], $ts); if (!$ok) return [false, $err, 'issue_failed', null];
    $pdo->prepare("UPDATE wch_cart SET inv_id = ?, bill_err = '' WHERE id = ?")->execute([$inv['id'], $cart['id']]); wch_ev($pdo, (int)$cart['id'], 'bill', 'Bill ' . $inv['inv_no'] . ' made'); return [true, '', '', $inv];
}
function wch_inv_data(array $inv): array { return wch_arr($inv['data_json']); }
function wch_inv_logo(array $data): string { $f = basename((string)($data['supplier']['logo'] ?? '')); return $f !== '' && is_file(wch_logo_dir() . '/' . $f) ? wch_logo_dir() . '/' . $f : ''; }
/** WhatsApp: the PDF as a document + a short message (inside the 24h window), or the approved template with the link outside it. [ok, error, code] */
function wch_bill_send(PDO $pdo, array $inv, int $emp = 0): array
{
    $ct = wa_contact($pdo, (string)$inv['phone']); if (!$ct) return [false, 'Chat not found.', 'not_found'];
    $vars = ['inv_no' => $inv['inv_no'], 'order' => $inv['order_name'], 'link' => wch_view_url($inv['token'])]; $fn = str_replace('/', '-', $inv['inv_no']) . '.pdf';
    [$ok, $r, $code] = wch_deliver($pdo, 'bill_sent', $ct, $vars, ['who' => $emp > 0 ? 'emp' : 'order', 'emp' => $emp, 'doc' => ['url' => wch_pdf_url($inv['token']), 'filename' => $fn]]);
    if ($ok) { $pdo->prepare("UPDATE wch_inv SET sent_at = ?, send_err = '' WHERE id = ?")->execute([wch_dt(), $inv['id']]); $pdo->prepare("UPDATE wch_cart SET status = IF(status = 'ordered', 'billed', status) WHERE id = ?")->execute([$inv['cart_id']]); wch_ev($pdo, (int)$inv['cart_id'], 'bill_sent', 'Bill ' . $inv['inv_no'] . ' sent on WhatsApp'); return [true, '', '']; }
    $pdo->prepare("UPDATE wch_inv SET send_err = ? WHERE id = ?")->execute([mb_substr((string)$r, 0, 150), $inv['id']]); return [false, (string)$r, $code];
}
/** Public pages: ?t=token. Prints and exits. */
function wch_public_page(PDO $pdo, string $token, bool $pdf): void
{
    $bad = function () { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:15px sans-serif;padding:30px;text-align:center">This bill link is not valid or has expired.</body>'; exit; };
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) $bad(); $inv = wch_inv_row($pdo, $token, 'token'); if (!$inv || !hash_equals((string)$inv['token'], $token) || wch_ts($inv['token_exp']) < wch_now()) $bad();
    $d = wch_inv_data($inv); if (!$d || empty($d['sections'])) $bad(); $d['qr_url'] = wch_view_url($token);
    header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
    if ($pdf) { $bin = wch_pdf_render($d, wch_inv_logo($d)); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . str_replace('/', '-', $inv['inv_no']) . '.pdf"'); header('Content-Length: ' . strlen($bin)); echo $bin; exit; }
    header('Content-Type: text/html; charset=utf-8'); echo wch_bill_html($d, ['logo_path' => wch_inv_logo($d), 'pdf_url' => wch_pdf_url($token)]); exit;
}

// ------------------------------------------------------------------------------------------------------------------ message catalogue: status, edit, templates
function wch_tpl_find(PDO $pdo, array $d, array $row): int
{
    $id = (int)($row['tpl_id'] ?? 0); if ($id > 0) return $id; if ($d['tpl'] === '') return 0;
    try { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$d['tpl']]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function wch_msg_item(PDO $pdo, string $key, array $d, array $wabas): array
{
    $row = wch_msg_row($pdo, $key); $text = wch_msg_text($pdo, $key, false); $ttext = $d['tpl_text'] !== '' ? wch_msg_text($pdo, $key, true) : '';
    $edited = trim((string)($row['text'] ?? '')) !== '' || trim((string)($row['tpl_text'] ?? '')) !== ''; $tpl = null; $status = 'written';
    if ($d['tpl'] !== '') {
        $id = wch_tpl_find($pdo, $d, $row); $st = ['status' => 'missing']; $per = [];
        if ($id > 0 && function_exists('wa_tpl_status')) { $st = wa_tpl_status($pdo, $id, $wabas); try { $q = $pdo->prepare("SELECT waba_id, status FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]); foreach ($q->fetchAll() as $r) $per[] = ['waba' => (string)$r['waba_id'], 'status' => strtoupper((string)$r['status'])]; } catch (Throwable $e) { } }
        $stale = false; if ($d['mode'] === 'template' && $id > 0 && (string)$row['tpl_body_hash'] !== '' && $ttext !== '') $stale = $row['tpl_body_hash'] !== md5(wch_tpl_convert($ttext)[0]);
        $tpl = ['name' => $d['tpl'], 'id' => $id ?: null, 'status' => (string)$st['status'], 'per_waba' => $per, 'stale' => $stale];
        if ($d['mode'] === 'existing') $status = $id > 0 ? 'existing' : 'missing';
        elseif ($id <= 0) $status = 'written'; elseif ($stale) $status = 'changed'; elseif (in_array($st['status'], ['approved', 'partial'], true)) $status = 'approved'; elseif ($st['status'] === 'rejected') $status = 'rejected'; else $status = 'submitted';
    } elseif ($d['mode'] === 'existing') $status = 'missing';
    $labels = ['written' => $d['mode'] === 'template' ? 'Written - needs a template for the closed window (press Submit)' : 'Written - sent as a normal message inside the 24h window', 'submitted' => 'Template sent to Meta, waiting for approval', 'approved' => 'Template approved', 'rejected' => 'Template rejected by Meta - edit the text and submit again',
        'changed' => 'Text changed after the template was sent - submit again', 'existing' => 'Already exists in the order-update module (reused)', 'missing' => 'Not created yet - Dispatch > Needs attention > Create the 5 templates'];
    return ['key' => $key, 'title' => $d['title'], 'when' => $d['when'], 'mode' => $d['mode'], 'source' => $d['source'], 'text' => $text, 'default_text' => $d['text'], 'tpl_text' => $ttext, 'default_tpl_text' => $d['tpl_text'], 'photo_url' => (string)($row['photo_url'] ?? ''), 'photo_lib_id' => (int)($row['photo_lib_id'] ?? 0),
        'edited' => $edited, 'photo_supported' => $d['mode'] !== 'existing' && !in_array($key, wch_msg_no_photo(), true), 'vars' => $d['vars'], 'tpl_vars' => $d['tpl_vars'], 'template' => $tpl, 'status' => $status, 'status_label' => $labels[$status] ?? $status, 'note' => $d['note'], 'automatic' => (bool)$d['trigger']];
}
function wch_messages(PDO $pdo): array
{
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $items = []; $sum = ['total' => 0, 'written' => 0, 'templates_needed' => 0, 'submitted' => 0, 'approved' => 0];
    foreach (wch_msg_all_defs() as $k => $d) { $it = wch_msg_item($pdo, $k, $d, $wabas); $items[] = $it; $sum['total']++; if ($it['status'] === 'written') $sum['written']++; if ($d['mode'] === 'template') { $sum['templates_needed']++; if ($it['status'] === 'submitted' || $it['status'] === 'changed') $sum['submitted']++; if ($it['status'] === 'approved') $sum['approved']++; } }
    return ['ok' => true, 'items' => $items, 'summary' => $sum];
}
function wch_message_save(PDO $pdo, string $key, array $in, int $by): array
{
    $defs = wch_msg_all_defs(); $d = $defs[$key] ?? null; if (!$d) return [false, 'Unknown message.', 404]; if ($d['mode'] === 'existing') return [false, 'This message is managed in the order-update module (Dispatch > Needs attention).', 422];
    $row = wch_msg_row($pdo, $key); $text = $row['text']; $tt = $row['tpl_text']; $url = (string)$row['photo_url']; $lib = (int)$row['photo_lib_id'];
    if (!empty($in['reset'])) { $text = null; $tt = null; }
    if (array_key_exists('text', $in) && empty($in['reset'])) {
        $x = trim((string)$in['text']); if ($x === '' || mb_strlen($x) > 1000) return [false, 'The text must be 1 to 1000 characters.', 422];
        $bad = array_diff(wch_placeholders($x), $d['vars']); if ($bad) return [false, 'Unknown placeholder {' . reset($bad) . '}. You can use: {' . implode('}, {', $d['vars']) . '}.', 422]; $text = $x === trim($d['text']) ? null : $x;
    }
    if (array_key_exists('tpl_text', $in) && empty($in['reset'])) {
        if ($d['tpl_text'] === '') return [false, 'This message has no template.', 422]; $x = trim((string)$in['tpl_text']); if ($x === '' || mb_strlen($x) > 900) return [false, 'The template text must be 1 to 900 characters.', 422];
        $bad = array_diff(wch_placeholders($x), $d['tpl_vars']); if ($bad) return [false, 'Unknown placeholder {' . reset($bad) . '} in the template text. You can use: {' . implode('}, {', $d['tpl_vars']) . '}.', 422];
        if (function_exists('wa_tpl_clean')) { [$body, $names] = wch_tpl_convert($x); $exv = wch_msg_examples(); [, $e] = wa_tpl_clean(['name' => 'wch_check', 'language' => 'en', 'category' => 'UTILITY', 'body' => $body, 'footer' => 'TM Perfume House', 'examples' => ['body' => array_map(function ($n) use ($exv) { return (string)($exv[$n] ?? 'x'); }, $names), 'labels' => $names]]); if ($e !== '') return [false, $e, 422]; }
        $tt = $x === trim($d['tpl_text']) ? null : $x;
    }
    if ((array_key_exists('photo_url', $in) && trim((string)$in['photo_url']) !== '') || (array_key_exists('photo_lib_id', $in) && (int)$in['photo_lib_id'] > 0)) { if (in_array($key, wch_msg_no_photo(), true)) return [false, 'This message already goes with its own picture, button or document, so a photo cannot be added.', 422]; }
    if (array_key_exists('photo_url', $in)) { $u = trim((string)$in['photo_url']); if ($u !== '' && !preg_match('#^https://[^\s]+$#i', $u)) return [false, 'The photo link must start with https://', 422]; $url = mb_substr($u, 0, 500); }
    if (array_key_exists('photo_lib_id', $in)) $lib = max(0, (int)$in['photo_lib_id']);
    $pdo->prepare("INSERT INTO wch_msg (mkey, text, tpl_text, photo_url, photo_lib_id, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE text = VALUES(text), tpl_text = VALUES(tpl_text), photo_url = VALUES(photo_url), photo_lib_id = VALUES(photo_lib_id), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)")
        ->execute([$key, $text, $tt, $url, $lib, $by, wch_dt()]);
    if ($lib > 0 && ($d['mode'] ?? '') === 'template') { try { wch_img_make($pdo, $key); } catch (Throwable $e) { } }   // a photo was chosen: its photo-on-top message goes to Meta now
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; return [true, wch_msg_item($pdo, $key, $d, $wabas), 200];
}
/** Creates the template(s) and sends them to every WhatsApp account (same path as the order-update templates). */
function wch_messages_submit(PDO $pdo, array $keys): array
{
    $res = ['created' => [], 'submitted' => [], 'skipped' => [], 'failed' => []];
    if (!function_exists('wa_tpl_tables') || !function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at') || !wa_tpl_tables($pdo)) { $res['error'] = 'The template module is not available.'; return $res; }
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $defs = wch_msg_all_defs(); $exv = wch_msg_examples();
    foreach ($keys as $k) {
        $d = $defs[$k] ?? null; if (!$d || $d['mode'] !== 'template' || $d['tpl'] === '') { $res['skipped'][$k] = 'Not a template message.'; continue; }
        [$body, $names] = wch_tpl_convert(wch_msg_text($pdo, $k, true)); $row = wch_msg_row($pdo, $k); $hash = md5($body); $id = wch_tpl_find($pdo, $d, $row);
        if ($id > 0 && (string)$row['tpl_body_hash'] === $hash) { $have = true; } else $have = false;
        if ($id <= 0 || !$have) {
            if ($id > 0 && (string)$row['tpl_body_hash'] === '') { $t0 = wa_tpl_row($pdo, $id); if ($t0 && trim((string)$t0['body']) === trim($body)) { $have = true; $pdo->prepare("INSERT INTO wch_msg (mkey, tpl_id, tpl_body_hash, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE tpl_id = VALUES(tpl_id), tpl_body_hash = VALUES(tpl_body_hash)")->execute([$k, $id, $hash, wch_dt()]); } }
        }
        if (!$have) {
            [$t, $err] = wa_tpl_clean(['name' => $d['tpl'], 'language' => 'en', 'category' => 'UTILITY', 'body' => $body, 'footer' => 'TM Perfume House', 'buttons' => [], 'examples' => ['body' => array_map(function ($n) use ($exv) { return (string)($exv[$n] ?? 'x'); }, $names), 'labels' => $names], 'folder' => 'Cart and bill']);
            if ($err === '' && function_exists('wa_tpl_media_check')) $err = wa_tpl_media_check($pdo, $t); if ($err !== '') { $res['failed'][$k] = $err; continue; }
            $id = wa_tpl_insert($pdo, $t, 0, 'Message about the customer\'s own cart / order / bill (' . $d['title'] . ').', 'Utility message about one cart or order.'); $res['created'][] = $k;
            $pdo->prepare("INSERT INTO wch_msg (mkey, tpl_id, tpl_body_hash, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE tpl_id = VALUES(tpl_id), tpl_body_hash = VALUES(tpl_body_hash)")->execute([$k, $id, $hash, wch_dt()]);
        }
        $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][$k] = 'Could not read the saved template.'; continue; }
        if (!$wabas) { $res['skipped'][$k] = 'No WhatsApp Business Account ID yet - saved, will be sent to Meta when one is added.'; continue; }
        $haveW = []; $hq = $pdo->prepare("SELECT waba_id FROM wa_tpl_waba WHERE tpl_id = ?"); $hq->execute([$id]); foreach ($hq->fetchAll() as $h) $haveW[(string)$h['waba_id']] = 1; $sent = false; $errs = [];
        foreach ($wabas as $w) { if (isset($haveW[$w])) continue; [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); if ($ok) $sent = true; else $errs[] = $why; }
        if ($errs) $res['failed'][$k] = $errs[0]; elseif ($sent) $res['submitted'][] = $k; else $res['skipped'][$k] = 'Already at Meta.';
    }
    wch_log($pdo, 'Message templates: ' . count($res['created']) . ' made, ' . count($res['submitted']) . ' sent to Meta, ' . count($res['failed']) . ' failed', !$res['failed']);
    return $res;
}

// ------------------------------------------------------------------------------------------------------------------ Claude: "What I understood" + questions (rules first, the AI is optional)
function wch_chain_style(string $title): array
{
    $t = strtolower($title); $style = 'chain'; foreach (['rope', 'curb', 'cuban', 'figaro', 'box', 'snake', 'link', 'franco', 'wheat', 'byzantine', 'mesh', 'pendant', 'twisted'] as $w) if (strpos($t, $w) !== false) { $style = $w . ' chain'; break; }
    $metal = 'metal look not clear'; foreach (['rose gold' => 'rose gold', 'two tone' => 'two-tone', 'gold' => 'gold look', 'silver' => 'silver look', 'steel' => 'steel look', 'black' => 'black finish', 'antique' => 'antique finish', 'oxidi' => 'oxidised'] as $w => $l) if (strpos($t, $w) !== false) { $metal = $l; break; }
    return [$style, $metal];
}
function wch_claude_state(PDO $pdo, string $area, array $cfg): array
{
    $confirmed = wch_meta_get($pdo, 'claude_ok:' . $area, ''); $lines = []; $extra = [];
    if ($area === 'billing') {
        $b = wch_b_get($pdo); $st = wch_billing_state($pdo, $b); $fy = wch_fy(wch_now());
        $lines[] = $b['legal_name'] !== '' ? 'Bills are issued by ' . $b['legal_name'] . ($b['gstin'] !== '' ? ' (GSTIN ' . $b['gstin'] . ')' : ' - the GSTIN is still missing') . '.' : 'The seller name is not set yet, so no bill can be made.';
        $lines[] = 'Perfumes: ' . wch_rate_txt((float)$b['rate_perfume']) . '% GST, HSN ' . $b['hsn_perfume'] . '. Chains: ' . wch_rate_txt((float)$b['rate_chain']) . '% GST, HSN ' . $b['hsn_chain'] . '. Prices already include GST.';
        $lines[] = $b['state'] !== '' ? 'Customers in ' . $b['state'] . ' get CGST + SGST (half each); customers in every other state get IGST. The state comes from the shipping address.' : 'The state of the business is not set, so I cannot tell CGST + SGST from IGST.';
        $lines[] = 'The chain gets ' . $b['split_pct'] . '% of the agreed total; the perfumes get the rest. Each order has two GST sections on one bill.';
        $lines[] = 'Numbers run ' . wch_inv_format($b['inv_prefix'], $fy, max(1, (int)$b['inv_next'])) . ', then one more each time, and start again at 00001 on 1 April.' . ($st['locks']['locked'] ? ' The series is locked because invoices exist.' : '');
        $lines[] = $st['complete'] ? 'All ' . $st['total'] . ' required fields are filled.' : $st['filled'] . ' of ' . $st['total'] . ' required fields are filled; missing: ' . implode(', ', $st['missing']) . '.';
    } else {
        $c = wch_counts($pdo); $map = wch_map_get($pdo); $sizes = wch_sync_status($pdo, $cfg)['sizes'];
        $lines[] = 'I read ' . $c['products'] . ' products: ' . $c['perfumes'] . ' perfumes, ' . $c['chains'] . ' chains and ' . $c['other'] . ' others (' . $c['variants'] . ' variants).';
        $d = function ($r) { return trim(($r['collection'] !== '' ? 'collection "' . $r['collection'] . '"' : '') . ($r['type'] !== '' ? ' product type "' . $r['type'] . '"' : '') . ($r['tag'] !== '' ? ' tag "' . $r['tag'] . '"' : '')); };
        $lines[] = $map['confirmed'] ? 'Chains are: ' . ($d($map['chain']) ?: 'not set') . '. Perfumes are: ' . ($d($map['perfume']) ?: 'not set') . '.' : 'I guessed which products are chains and which are perfumes from their names - please confirm it once.';
        $lines[] = $sizes ? 'Perfume sizes found: ' . implode(', ', array_map(function ($s) { return $s['ml'] . ' ml'; }, $sizes)) . '.' : 'No perfume sizes found yet.';
        $cov = wch_coverage($pdo, $cfg); $lines[] = $cov['total_chains'] ? 'Chain prices: ' . $cov['text'] : 'There are no chains yet.';
        foreach ($pdo->query("SELECT p.pid, p.title, p.min_price_p FROM wch_prod p WHERE p.active = 1 AND p.kind = 'chain' ORDER BY p.min_price_p LIMIT 60")->fetchAll() as $r) {
            [$style, $metal] = wch_chain_style((string)$r['title']); $pr = (int)$r['min_price_p']; $step = max(100, (int)$cfg['wch_slab_step']) * 100; $a = (int)(floor($pr / $step) * $step);
            $extra[] = ['pid' => (int)$r['pid'], 'title' => (string)$r['title'], 'style' => $style, 'metal' => $metal, 'price' => wch_r($pr), 'slab' => wch_rs($a) . '-' . number_format(($a + $step) / 100, 0, '.', ',')];
        }
        $ai = wch_meta_get($pdo, 'claude_ai_text', ''); if ($ai !== '') $lines[] = $ai;
    }
    $o = ['ok' => true, 'area' => $area, 'understood' => $lines, 'confirmed' => $confirmed !== '', 'confirmed_at' => $confirmed !== '' ? $confirmed : null, 'pending' => count(wch_claude_questions($pdo, $area, $cfg)), 'ai' => !defined('WCH_NO_AI') && function_exists('convo_ai')];
    if ($area === 'catalog') $o['chains'] = $extra; return $o;
}
/** All open questions of an area (without the ones skipped for now). */
function wch_claude_questions(PDO $pdo, string $area, array $cfg): array
{
    $skip = wch_arr(wch_meta_get($pdo, 'q_skip', '')); $q = [];
    if ($area === 'billing') {
        $b = wch_b_get($pdo); $texts = ['legal_name' => 'What is the legal name of the business, exactly as on the GST certificate?', 'gstin' => 'What is your GSTIN?', 'address' => 'What is the registered address (as on the GST certificate)?', 'state' => 'In which state is the business registered?',
            'state_code' => 'What is the GST state code (24 for Gujarat)?', 'pincode' => 'What is the pincode of the registered address?', 'phone' => 'Which phone number should be printed on the bill?', 'hsn_perfume' => 'What is the HSN code of perfumes? (3303 is the usual one)', 'hsn_chain' => 'What is the HSN code of the chains? (7117 is the usual one)',
            'inv_prefix' => 'Which letters should the invoice numbers start with? (for example TM)', 'inv_next' => 'Which number should the very first invoice get? (normally 1)'];
        $si = function_exists('wa_store_info') ? wa_store_info($pdo) : ['name' => '', 'address' => '', 'phone' => ''];
        foreach (wch_b_required() as $k => $label) { if (wch_b_filled($k, $b)) continue; $id = 'b:' . $k; if (isset($skip[$id]) && $skip[$id] > wch_now()) continue; $chips = [];
            if ($k === 'address' && trim((string)$si['address']) !== '') $chips[] = ['key' => (string)$si['address'], 'label' => mb_substr((string)$si['address'], 0, 60)]; if ($k === 'phone' && trim((string)$si['phone']) !== '') $chips[] = ['key' => (string)$si['phone'], 'label' => (string)$si['phone']];
            if ($k === 'state') foreach (['Gujarat', 'Maharashtra', 'Delhi', 'Rajasthan'] as $n) $chips[] = ['key' => $n, 'label' => $n];
            $q[] = ['id' => $id, 'area' => 'billing', 'question' => $texts[$k] ?? $label . '?', 'kind' => $chips ? 'choice_or_text' : 'text', 'field' => $k, 'chips' => $chips, 'why' => 'Needed on every bill. No cart can be sent until it is filled.']; }
    } else {
        $map = wch_map_get($pdo);
        if (!$map['confirmed']) { $cand = wch_map_candidates($pdo);
            foreach (['chain' => 'chains', 'perfume' => 'perfumes'] as $w => $pl) { $id = 'm:' . $w; if (isset($skip[$id]) && $skip[$id] > wch_now()) continue; if (!wch_rule_empty($map[$w]) && $map['confirmed']) continue;
                $chips = []; foreach ($cand[$w] as $c) $chips[] = ['key' => $c['kind'] . '|' . $c['key'], 'label' => $c['label'] . ' (' . $c['products'] . ' products)']; if (!$chips) continue;
                $q[] = ['id' => $id, 'area' => 'catalog', 'question' => 'Which of these means ' . $pl . '?', 'kind' => 'choice', 'chips' => $chips, 'why' => 'Search and chain suggestions only use products of the right kind.']; } }
        $unclear = array_map('intval', wch_arr(wch_meta_get($pdo, 'unclear_pids', '[]')));
        if ($unclear) { $in = implode(',', array_slice($unclear, 0, 60)); foreach ($pdo->query("SELECT pid, title FROM wch_prod WHERE pid IN ($in) AND active = 1 AND kind_manual = '' ORDER BY title LIMIT 40")->fetchAll() as $r) {
            $id = 'k:' . $r['pid']; if (isset($skip[$id]) && $skip[$id] > wch_now()) continue;
            $q[] = ['id' => $id, 'area' => 'catalog', 'question' => "Is '" . $r['title'] . "' a perfume set?", 'kind' => 'choice', 'chips' => [['key' => 'perfume', 'label' => 'Yes, a perfume'], ['key' => 'chain', 'label' => 'A chain'], ['key' => 'other', 'label' => 'Neither (hide it)']], 'why' => 'Sets cannot be split by ml, so I keep them out of the perfume search until you tell me.']; } }
    }
    return $q;
}
function wch_claude_next(PDO $pdo, string $area, array $cfg): array
{
    $q = wch_claude_questions($pdo, $area, $cfg); $item = $q ? $q[0] : null;
    if ($item && strpos($item['id'], 'k:') === 0) { [$ok, $v] = wch_ai($pdo, 'You classify a store product title as perfume, chain or other. Answer only with the schema.', 'Title: ' . preg_replace('/^Is \'(.*)\' a perfume set\?$/', '$1', $item['question']), ['type' => 'object', 'properties' => ['guess' => ['type' => 'string', 'enum' => ['perfume', 'chain', 'other']]], 'required' => ['guess']]); if ($ok && isset($v['guess'])) $item['ai_guess'] = (string)$v['guess']; }
    return ['ok' => true, 'area' => $area, 'pending' => count($q), 'item' => $item];
}
/** [ok, error, code] */
function wch_claude_answer(PDO $pdo, string $id, $answer, array $in, int $by, array $cfg): array
{
    if (!empty($in['skip'])) { $s = wch_arr(wch_meta_get($pdo, 'q_skip', '')); $s[$id] = wch_now() + 86400; wch_meta_put($pdo, 'q_skip', wch_json($s)); return [true, '', 200]; }
    if (strpos($id, 'b:') === 0) {
        $k = substr($id, 2); if (!isset(wch_b_required()[$k])) return [false, 'Unknown question.', 404]; $cur = wch_b_get($pdo); [$err, $warn, $c] = wch_b_validate([$k => $answer], $cur);
        if ($k === 'inv_prefix' || $k === 'inv_next') if (wch_inv_locked($pdo)) return [false, 'The first invoice has been issued, so this can no longer change.', 409];
        if ($err) return [false, reset($err), 422]; if ($k === 'inv_next') { try { $pdo->exec("DELETE FROM wch_seq"); } catch (Throwable $e) { } } foreach ($c as $kk => $vv) wch_put($pdo, 'wch_b_' . $kk, $vv);
        $after = wch_b_get($pdo); [$e2] = wch_b_validate($after, $after); if ($e2) { foreach ($c as $kk => $vv) wch_put($pdo, 'wch_b_' . $kk, $cur[$kk] ?? ''); return [false, reset($e2), 422]; } return [true, '', 200];
    }
    if (strpos($id, 'm:') === 0) {
        $w = substr($id, 2); if (!in_array($w, ['chain', 'perfume'], true)) return [false, 'Unknown question.', 404]; $a = (string)$answer; if (strpos($a, '|') === false) return [false, 'Choose one of the options.', 422];
        [$kind, $val] = explode('|', $a, 2); if (!in_array($kind, ['collection', 'type', 'tag'], true)) return [false, 'Choose one of the options.', 422]; $m = wch_map_get($pdo); $rule = ['collection' => '', 'type' => '', 'tag' => '']; $rule[$kind] = $val; $m[$w] = $rule;
        $both = !wch_rule_empty($m['chain']) && !wch_rule_empty($m['perfume']); [$ok, $r] = wch_map_save($pdo, ['chain' => $m['chain'], 'perfume' => $m['perfume'], 'confirm' => $both], $by); return $ok ? [true, '', 200] : [false, (string)$r, 422];
    }
    if (strpos($id, 'k:') === 0) {
        $pid = (int)substr($id, 2); $a = (string)$answer; if (!in_array($a, ['perfume', 'chain', 'other'], true)) return [false, 'Choose perfume, chain or other.', 422];
        $u = $pdo->prepare("UPDATE wch_prod SET kind_manual = ? WHERE pid = ?"); $u->execute([$a, $pid]); if ($u->rowCount() < 1) { $q = $pdo->prepare("SELECT 1 FROM wch_prod WHERE pid = ?"); $q->execute([$pid]); if (!$q->fetchColumn()) return [false, 'Product not found.', 404]; } wch_reclassify($pdo); return [true, '', 200];
    }
    return [false, 'Unknown question.', 404];
}

// ------------------------------------------------------------------------------------------------------------------ the progress bar of one cart
function wch_cart_status(PDO $pdo, array $c, array $cfg): array
{
    $st = (string)$c['status']; $failed = in_array($st, ['cancelled', 'expired', 'failed'], true); $sent = (string)$c['options_sent_at'] !== ''; $picked = (string)$c['picked'] !== ''; $linked = (string)$c['link_sent_at'] !== ''; $ordered = (string)$c['order_name'] !== '';
    $payOk = $ordered && ((string)$c['order_mode'] === 'cod' || (int)$c['order_paid'] === 1); $inv = (int)$c['inv_id'] > 0 ? wch_inv_row($pdo, (int)$c['inv_id']) : null; $billSent = $inv && (string)$inv['sent_at'] !== '';
    $opts = wch_arr($c['opts_json']); $pn = wch_pick_n($c);
    $s = [['key' => 'built', 'label' => 'Cart built', 'done' => true, 'at' => $c['created_at'], 'detail' => wch_rs((int)$c['total_p']) . ', ' . (int)$c['ml'] . ' ml'],
        ['key' => 'options', 'label' => 'Chain options sent', 'done' => $sent || ($picked && (string)$c['picked'] === 'none'), 'at' => $c['options_sent_at'], 'detail' => $sent ? count($opts) . ' option' . (count($opts) === 1 ? '' : 's') : ($picked ? 'Nothing to offer' : '')],
        ['key' => 'chosen', 'label' => 'Customer chose', 'done' => $picked, 'at' => $c['picked_at'], 'detail' => $picked ? ((string)$c['picked'] === 'none' ? 'No chain' : 'Option ' . $pn) . ($c['picked_by'] === 'customer' ? " (customer's reply)" : ' (picked by the salesperson)') : ((int)$c['needs_manual'] === 1 ? 'The customer wrote something unclear - pick by hand' : ($sent ? 'Waiting for the customer' : ''))],
        ['key' => 'link', 'label' => 'Cart link sent', 'done' => $linked, 'at' => $c['link_sent_at'], 'detail' => $linked ? (string)$c['draft_name'] : ''],
        ['key' => 'paid', 'label' => 'Payment / order', 'done' => $payOk, 'at' => $c['order_at'], 'detail' => $payOk ? $c['order_name'] . ' - ' . ($c['order_mode'] === 'cod' ? 'COD' : 'paid') : ($ordered ? $c['order_name'] . ' - waiting for the payment' : ($linked ? 'Waiting for the customer' : ''))],
        ['key' => 'bill', 'label' => 'Bill sent', 'done' => (bool)$billSent, 'at' => $inv ? $inv['sent_at'] : null, 'detail' => $inv ? $inv['inv_no'] . ($billSent ? '' : ' - not sent yet') : ((string)$c['bill_err'] !== '' ? (string)$c['bill_err'] : '')]];
    $steps = []; $cur = false; foreach ($s as $x) { $state = $x['done'] ? 'done' : (!$cur ? ($failed ? 'failed' : 'current') : 'todo'); if (!$x['done'] && !$cur) $cur = true; if ($failed && $state === 'failed') $x['detail'] = (wch_status_labels()[$st] ?? $st) . ((string)$c['cancel_reason'] !== '' ? ': ' . $c['cancel_reason'] : ''); $steps[] = ['key' => $x['key'], 'label' => $x['label'], 'state' => $state, 'at' => $x['done'] || $state === 'failed' ? $x['at'] : null, 'detail' => $x['detail']]; }
    $tl = []; foreach ($pdo->query("SELECT at, kind, text FROM wch_cart_ev WHERE cart_id = " . (int)$c['id'] . " ORDER BY id LIMIT 200")->fetchAll() as $e) $tl[] = ['at' => $e['at'], 'kind' => $e['kind'], 'text' => $e['text']];
    $next = ['building' => 'Add the perfumes, then send the chain options.', 'options_sent' => "Waiting for the customer's answer. A reminder goes out after " . $cfg['wch_remind_min'] . ' minutes.', 'chosen' => 'Send the cart link.', 'link_sent' => 'Waiting for payment. A reminder goes out after ' . $cfg['wch_pay_remind_min'] . ' minutes.',
        'ordered' => $payOk ? 'The bill is being made.' : 'Waiting for the payment.', 'billed' => 'Done.', 'cancelled' => 'Cancelled.', 'expired' => 'Expired - start a new cart if the customer still wants it.', 'failed' => 'Failed.'][$st] ?? '';
    $rem = null; if ($st === 'options_sent' && (string)$c['reminder_sent_at'] === '' && $sent) $rem = wch_dt(wch_ts($c['options_sent_at']) + $cfg['wch_remind_min'] * 60); elseif ($st === 'link_sent' && (string)$c['pay_reminded_at'] === '' && $linked) $rem = wch_dt(wch_ts($c['link_sent_at']) + $cfg['wch_pay_remind_min'] * 60);
    return ['ok' => true, 'id' => (int)$c['id'], 'status' => $st, 'status_label' => wch_status_labels()[$st] ?? $st, 'steps' => $steps, 'timeline' => $tl, 'next' => $next, 'reminder_at' => $rem, 'expires_at' => $c['expires_at']];
}

// ------------------------------------------------------------------------------------------------------------------ health + the tick
function wch_status(PDO $pdo, array $cfg): array
{
    $last = wch_arr(wch_meta_get($pdo, 'last_tick', '')); $busy = false; try { $busy = (int)$pdo->query("SELECT IS_FREE_LOCK('wch_tick')")->fetchColumn() === 0; } catch (Throwable $e) { }
    $carts = []; foreach ($pdo->query("SELECT status, COUNT(*) n FROM wch_cart WHERE updated_at >= '" . wch_dt(wch_now() - 30 * 86400) . "' GROUP BY status")->fetchAll() as $r) $carts[$r['status']] = (int)$r['n'];
    $lastInv = $pdo->query("SELECT inv_no FROM wch_inv ORDER BY id DESC LIMIT 1")->fetchColumn(); $b = wch_billing_state($pdo); $cnt = [];
    foreach (['wch_prod', 'wch_var', 'wch_cart', 'wch_inv', 'wch_cart_ev'] as $t) { try { $cnt[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable $e) { $cnt[$t] = 0; } }
    $sc = wch_arr(wch_meta_get($pdo, 'scope', ''));
    return ['ok' => true, 'on' => (bool)$cfg['wch_on'], 'tick' => ['last_at' => $last['at'] ?? null, 'last_ms' => $last['ms'] ?? null, 'last_result' => $last['result'] ?? null, 'busy' => $busy], 'sync' => wch_sync_status($pdo, $cfg), 'carts' => $carts ?: new stdClass(),
        'invoices' => ['count' => (int)$pdo->query("SELECT COUNT(*) FROM wch_inv")->fetchColumn(), 'last' => $lastInv ?: null], 'billing' => ['filled' => $b['filled'], 'total' => $b['total'], 'complete' => $b['complete']],
        'scope' => ['products' => $sc['products'] ?? null, 'draft_orders' => $sc['draft_orders'] ?? null, 'orders' => $sc['orders'] ?? null, 'checked_at' => $sc['checked_at'] ?? null], 'tables' => $cnt];
}
function wch_tick(PDO $pdo, array $opt = []): array
{
    $t0 = microtime(true); $r = [];
    try {
        if (!wch_on($pdo)) return ['off' => true];
        if (!wch_tables($pdo)) return ['error' => 'storage'];
        $deadline = $t0 + (float)($opt['budget'] ?? 8); $got = (int)$pdo->query("SELECT GET_LOCK('wch_tick', 0)")->fetchColumn(); if ($got !== 1) return ['skipped' => 'busy'];
        try {
            wch_deps($pdo); $cfg = wch_cfg($pdo); $now = wch_now(); $light = !empty($opt['light']);
            $pdo->prepare("UPDATE wch_cart SET status = 'building' WHERE status = 'sending' AND updated_at <= ?")->execute([wch_dt($now - 300)]);   // a send that crashed halfway can be pressed again
            // 1. the customer did not answer the chain options: one reminder, then the salesperson is told
            $rem = $pdo->prepare("SELECT * FROM wch_cart WHERE status = 'options_sent' AND needs_manual = 0 AND reminder_sent_at IS NULL AND options_sent_at <= ? ORDER BY id LIMIT 20"); $rem->execute([wch_dt($now - $cfg['wch_remind_min'] * 60)]); $r['reminders'] = 0;
            foreach ($rem->fetchAll() as $c) {
                $u = $pdo->prepare("UPDATE wch_cart SET reminder_sent_at = ? WHERE id = ? AND reminder_sent_at IS NULL AND status = 'options_sent'"); $u->execute([wch_dt(), $c['id']]); if ($u->rowCount() < 1) continue;
                $ct = wa_contact($pdo, (string)$c['phone']); $n = count(wch_arr($c['opts_json'])); $ok = false; $why = '';
                if ($ct) [$ok, $why] = wch_deliver($pdo, 'chain_reminder', $ct, ['choices' => wch_choices_text(max(1, $n))], ['who' => 'order']);
                wch_ev($pdo, (int)$c['id'], $ok ? 'reminder' : 'reminder_skipped', $ok ? 'Reminder sent to the customer' : 'Reminder not sent: ' . mb_substr((string)$why, 0, 150)); $r['reminders']++;
                if (!$ok) wch_notify($pdo, (int)$c['emp_id'], 'Customer has not chosen a chain', 'The reminder could not be sent (' . mb_substr((string)$why, 0, 60) . '). Call or message cart #' . $c['id'] . '.', (int)$c['id']);
            }
            $esc = $pdo->prepare("SELECT * FROM wch_cart WHERE status = 'options_sent' AND needs_manual = 0 AND reminder_sent_at IS NOT NULL AND escalated_at IS NULL AND reminder_sent_at <= ? ORDER BY id LIMIT 20"); $esc->execute([wch_dt($now - $cfg['wch_remind_min'] * 60)]); $r['escalated'] = 0;
            foreach ($esc->fetchAll() as $c) { $u = $pdo->prepare("UPDATE wch_cart SET escalated_at = ? WHERE id = ? AND escalated_at IS NULL"); $u->execute([wch_dt(), $c['id']]); if ($u->rowCount() < 1) continue; wch_ev($pdo, (int)$c['id'], 'escalated', 'No answer to the chain options - the salesperson was told'); wch_notify($pdo, (int)$c['emp_id'], 'No answer yet about the chain', 'The customer did not answer the chain options (cart #' . $c['id'] . '). Please follow up.', (int)$c['id']); $r['escalated']++; }
            // 2. payment reminder (once)
            $pr = $pdo->prepare("SELECT * FROM wch_cart WHERE status = 'link_sent' AND pay_reminded_at IS NULL AND link_sent_at <= ? ORDER BY id LIMIT 20"); $pr->execute([wch_dt($now - $cfg['wch_pay_remind_min'] * 60)]); $r['pay_reminders'] = 0;
            foreach ($pr->fetchAll() as $c) {
                $u = $pdo->prepare("UPDATE wch_cart SET pay_reminded_at = ? WHERE id = ? AND pay_reminded_at IS NULL AND status = 'link_sent'"); $u->execute([wch_dt(), $c['id']]); if ($u->rowCount() < 1) continue; $ct = wa_contact($pdo, (string)$c['phone']); $ok = false; $why = '';
                if ($ct && (string)$c['draft_url'] !== '') [$ok, $why] = wch_deliver($pdo, 'payment_pending', $ct, ['total' => wch_rs((int)$c['total_p']), 'link' => $c['draft_url']], ['who' => 'order']);
                wch_ev($pdo, (int)$c['id'], $ok ? 'pay_reminder' : 'pay_reminder_skipped', $ok ? 'Payment reminder sent' : 'Payment reminder not sent: ' . mb_substr((string)$why, 0, 150)); $r['pay_reminders']++;
            }
            if (!$light) {
                // 3. catalog refresh (only after the owner pressed Refresh once; a missing scope is retried hourly)
                $okAt = wch_ts(wch_meta_get($pdo, 'last_sync_ok_at', '')); $open = wch_meta_get($pdo, 'sync_open', '0') === '1'; $inf = wch_arr(wch_meta_get($pdo, 'sync_info', '')); $tried = wch_ts(wch_meta_get($pdo, 'sync_last_at', ''));
                $blocked = ($inf['code'] ?? '') === 'scope_missing' && $tried > $now - 3600;
                if (($okAt > 0 || $open) && !$blocked && ($open || $okAt <= $now - $cfg['wch_sync_every_min'] * 60) && microtime(true) < $deadline) { $s = wch_sync_step($pdo, min($deadline, microtime(true) + 5), false); $r['sync'] = ['ok' => $s['ok'], 'incomplete' => $s['incomplete'], 'error' => $s['error']]; }
                // 4. payment polling + order matching
                if (microtime(true) < $deadline) $r['poll'] = wch_poll_carts($pdo, $cfg, $deadline);
                // 5. expiry
                $ex = $pdo->prepare("SELECT * FROM wch_cart WHERE status IN ('building','options_sent','chosen','link_sent') AND expires_at IS NOT NULL AND expires_at < ? ORDER BY id LIMIT 10"); $ex->execute([wch_dt($now)]); $r['expired'] = 0;
                foreach ($ex->fetchAll() as $c) {
                    $gid = (string)$c['draft_gid']; if ($gid !== '' && $gid !== 'creating') { $rd = wch_draft_read($pdo, $gid); if ($rd['ok'] && !empty($rd['order'])) { wch_apply_order($pdo, $c, $rd); continue; } if ($rd['ok'] && empty($rd['gone'])) wch_draft_delete($pdo, $gid); }
                    $u = $pdo->prepare("UPDATE wch_cart SET status = 'expired', updated_at = ? WHERE id = ? AND status = ?"); $u->execute([wch_dt(), $c['id'], $c['status']]); if ($u->rowCount() < 1) continue;
                    wch_ev($pdo, (int)$c['id'], 'expired', 'Expired after ' . $cfg['wch_expire_hours'] . ' hours without an order'); wch_notify($pdo, (int)$c['emp_id'], 'Cart expired', 'Cart #' . $c['id'] . ' expired without an order.', (int)$c['id']); $r['expired']++;
                }
                // 6. bills: COD when the order exists, prepaid after payment
                $bq = $pdo->query("SELECT * FROM wch_cart WHERE status = 'ordered' AND inv_id = 0 AND bill_err = '' AND (order_mode = 'cod' OR order_paid = 1) ORDER BY id LIMIT 6"); $r['bills'] = 0;
                foreach ($bq->fetchAll() as $c) {
                    [$ok, $err, $code, $inv] = wch_bill_make($pdo, $c);
                    if (!$ok) { $pdo->prepare("UPDATE wch_cart SET bill_err = ? WHERE id = ?")->execute([mb_substr($err, 0, 190), $c['id']]); wch_ev($pdo, (int)$c['id'], 'bill_blocked', $err); wch_notify($pdo, (int)$c['emp_id'], 'Bill needs your help', $err . ' (order ' . $c['order_name'] . ')', (int)$c['id']); continue; }
                    $r['bills']++; wch_bill_send($pdo, $inv, 0);
                }
                $rs = $pdo->prepare("SELECT * FROM wch_inv WHERE sent_at IS NULL AND created_at >= ? ORDER BY id LIMIT 5"); $rs->execute([wch_dt($now - 2 * 86400)]); $r['bill_retries'] = 0;
                foreach ($rs->fetchAll() as $inv) {
                    $m = explode('|', wch_meta_get($pdo, 'bs:' . $inv['id'], '0|0')); $n = (int)$m[0]; $at = (int)($m[1] ?? 0); if ($n >= 3 || ($n > 0 && $at > $now - 1800) || ($n === 0 && wch_ts($inv['created_at']) > $now - 5 && $r['bills'] > 0)) continue;
                    wch_meta_put($pdo, 'bs:' . $inv['id'], ($n + 1) . '|' . $now); [$ok] = wch_bill_send($pdo, $inv, 0); if ($ok) $r['bill_retries']++;
                }
                if (wch_ts(wch_meta_get($pdo, 'house_at', '')) < $now - 3600) { $pdo->prepare("DELETE FROM wch_once WHERE at < ?")->execute([wch_dt($now - 14 * 86400)]); wch_meta_put($pdo, 'house_at', wch_dt()); }
            }
        } finally { try { $pdo->query("SELECT RELEASE_LOCK('wch_tick')"); } catch (Throwable $e) { } }
        wch_meta_put($pdo, 'last_tick', wch_json(['at' => wch_dt(), 'ms' => (int)round((microtime(true) - $t0) * 1000), 'result' => $r]));
    } catch (Throwable $e) { error_log('wch_tick: ' . $e->getMessage()); $r['error'] = mb_substr($e->getMessage(), 0, 160); }
    return $r;
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router (pinned at the end of the file; answers at include time like wq.php / wa_orders.php)
$__wca = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wca, 'wch.') === 0 && in_array($__wca, ['wch.below_reason', 'wch.sync', 'wch.sync_status', 'wch.scope_check', 'wch.mapping', 'wch.search', 'wch.coverage', 'wch.cart_new', 'wch.cart_set', 'wch.cart_get', 'wch.cart_list', 'wch.chain_suggest', 'wch.chain_diagnose', 'wch.chain_send', 'wch.chain_pick', 'wch.cart_link', 'wch.cart_status', 'wch.cart_cancel',
    'wch.billing', 'wch.bill_preview', 'wch.bill', 'wch.bill_make', 'wch.bill_resend', 'wch.bills', 'wch.view', 'wch.pdf', 'wch.messages', 'wch.message_save', 'wch.messages_submit', 'wch.claude_state', 'wch.claude_next', 'wch.claude_answer', 'wch.settings', 'wch.status', 'wch.tick'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available - check database permissions.'], 500);
    wch_deps($pdo); if (!wch_tables($pdo)) json_response(['error' => 'Chain cart storage is not available - check database permissions.'], 500);
    if ($__wca === 'wch.view' || $__wca === 'wch.pdf') { if ($isPost) json_response(['error' => 'Method not allowed.'], 405); wch_public_page($pdo, (string)($_GET['t'] ?? ''), $__wca === 'wch.pdf'); }   // PUBLIC: the secret token is the only key
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : $_GET; $adm = wa_is_admin($pdo, $auth); $cfg = wch_cfg($pdo); $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needGet = function () use ($isPost) { if ($isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needAdmin = function () use ($adm) { if (!$adm) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403); };
    $http = function (string $code): int { static $m = ['off' => 409, 'billing_incomplete' => 409, 'window_closed' => 409, 'already_sent' => 409, 'not_sent' => 409, 'no_choice' => 409, 'conflict' => 409, 'busy' => 409, 'optout' => 409, 'not_paid' => 409, 'cart_exists' => 409, 'locked' => 409,
        'ml_mismatch' => 422, 'empty' => 422, 'choose' => 422, 'gone' => 422, 'no_options' => 422, 'bad_pick' => 422, 'plan' => 422, 'state_missing' => 422, 'no_plan' => 422, 'no_order' => 409, 'send_failed' => 502, 'scope_missing' => 502, 'shopify_error' => 502, 'issue_failed' => 500, 'not_found' => 404, 'internal' => 500]; return $m[$code] ?? 422; };
    $fail = function (string $err, string $code, array $extra = []) use ($http) { json_response(['error' => $err, 'code' => $code] + $extra, $http($code)); };
    $cartOf = function (int $id) use ($pdo, $adm, $auth, $names) {   // the cart row; a salesperson only for chats they may open
        $r = $id > 0 ? wch_cart_row($pdo, $id) : null; if (!$r) json_response(['error' => 'Cart not found.'], 404);
        if (!$adm) { $c = wa_contact($pdo, (string)$r['phone']); if (!$c || !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403); } return $r;
    };
    $phoneIn = function () use ($in) { $k = preg_replace('/\D+/', '', (string)($in['phone'] ?? '')); return strlen($k) >= 10 ? substr($k, -10) : ''; };
    $cartOut = function (int $id) use ($pdo, $cfg, $names) { return wch_cart_full($pdo, $id, $cfg, $names); };
    $on = (bool)$cfg['wch_on'];
    switch ($__wca) {
        case 'wch.sync': { $needPost(); $needAdmin(); @set_time_limit(90); @ignore_user_abort(true);
            $openRecent = wch_meta_get($pdo, 'sync_open', '0') === '1' && wch_ts(wch_meta_get($pdo, 'sync_step_at', '')) > wch_now() - 1800;   // a read that stopped less than 30 min ago goes on (pressing Refresh again never starts from page 1)
            $r = wch_sync_step($pdo, microtime(true) + 25, empty($in['continue']) && !$openRecent);
            if (!$r['ok']) json_response(['ok' => false, 'error' => $r['error'], 'code' => $r['code'] ?: 'shopify_error', 'scopes' => $r['code'] === 'scope_missing' ? ['read_products'] : []], $r['code'] === 'busy' ? 409 : 502);
            unset($r['ok'], $r['error'], $r['code']); json_response(['ok' => true, 'result' => $r]); }
        case 'wch.sync_status': { $needGet(); $needAdmin(); json_response(wch_sync_status($pdo, $cfg)); }
        case 'wch.scope_check': { $needPost(); $needAdmin(); $sc = wch_scope_check($pdo); $miss = $sc['missing']; unset($sc['missing']); json_response(['ok' => true, 'scope' => $sc, 'missing' => $miss, 'how' => $miss ? wch_scope_msg($miss, 'these') : '']); }
        case 'wch.mapping': {
            $needAdmin();
            if ($isPost) {
                if (!empty($in['overrides']) && is_array($in['overrides'])) { foreach ($in['overrides'] as $o) { $k = (string)($o['kind'] ?? ''); if (!in_array($k, ['perfume', 'chain', 'other', 'auto'], true)) json_response(['error' => 'kind must be perfume, chain, other or auto.'], 422); $pdo->prepare("UPDATE wch_prod SET kind_manual = ? WHERE pid = ?")->execute([$k === 'auto' ? '' : $k, (int)($o['pid'] ?? 0)]); } wch_reclassify($pdo); }
                if (isset($in['chain']) || isset($in['perfume']) || !empty($in['confirm'])) { [$ok, $r] = wch_map_save($pdo, $in, $me); if (!$ok) json_response(['error' => $r], 422); }
            }
            $m = wch_map_get($pdo); $cand = wch_map_candidates($pdo); $cur = $m['confirmed'] ? ['chain' => $m['chain'], 'perfume' => $m['perfume']] : ['chain' => ['collection' => '', 'type' => '', 'tag' => ''], 'perfume' => ['collection' => '', 'type' => '', 'tag' => '']];
            if (!$m['confirmed']) foreach (['chain', 'perfume'] as $w) if (!empty($cand[$w])) $cur[$w][$cand[$w][0]['kind']] = $cand[$w][0]['key'];
            $ov = []; foreach ($pdo->query("SELECT pid, title, kind_manual FROM wch_prod WHERE kind_manual <> '' ORDER BY title LIMIT 200")->fetchAll() as $r) $ov[] = ['pid' => (int)$r['pid'], 'title' => $r['title'], 'kind' => $r['kind_manual']];
            $c = wch_counts($pdo); json_response(['ok' => true, 'confirmed' => $m['confirmed'], 'confirmed_at' => $m['confirmed_at'], 'current' => $cur, 'candidates' => $cand, 'counts' => ['chains' => $c['chains'], 'perfumes' => $c['perfumes'], 'other' => $c['other']], 'overrides' => $ov]);
        }
        case 'wch.search': { $needGet(); $t = microtime(true); $cat = (string)($in['cat'] ?? 'perfume'); $items = wch_search($pdo, (string)($in['q'] ?? ''), $cat === 'chain' ? 'chain' : 'perfume', (int)($in['limit'] ?? 12), !isset($in['in_stock']) || (string)$in['in_stock'] !== '0');
            json_response(['ok' => true, 'q' => (string)($in['q'] ?? ''), 'cat' => $cat === 'chain' ? 'chain' : 'perfume', 'items' => $items, 'count' => count($items), 'took_ms' => (int)round((microtime(true) - $t) * 1000)]); }
        case 'wch.coverage': { $needGet(); $needAdmin(); json_response(wch_coverage($pdo, $cfg)); }
        case 'wch.cart_new': {
            $needPost(); $k = $phoneIn(); $c = $k !== '' ? wa_contact($pdo, $k) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404); if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if (!isset($in['total']) || !is_numeric($in['total']) || (float)$in['total'] < 100 || (float)$in['total'] > 500000) json_response(['error' => 'The agreed total must be a number from 100 to 500000.'], 422);
            if (!isset($in['ml']) || !is_numeric($in['ml']) || (int)$in['ml'] < 1 || (int)$in['ml'] > 5000) json_response(['error' => 'The total ml must be a whole number from 1 to 5000.'], 422);
            $old = wch_cart_open_for($pdo, $k);
            if ($old) { if (empty($in['replace'])) json_response(['error' => 'This customer already has an open cart (#' . $old['id'] . ').', 'code' => 'cart_exists', 'cart_id' => (int)$old['id']], 409); [$ok, $e, $cd] = wch_cart_cancel($pdo, $old, 'Replaced by a new cart', false, $me); if (!$ok) $fail($e, $cd); }
            $id = wch_cart_create($pdo, $k, (int)($c['assigned_emp'] ?: $me), wch_p($in['total']), (int)$in['ml'], $cfg); json_response(['ok' => true, 'on' => $on, 'cart' => $cartOut($id)]);
        }
        case 'wch.cart_set': {
            $needPost(); $c = $cartOf((int)($in['id'] ?? 0)); if ($c['status'] !== 'building') json_response(['error' => 'The options were already sent. Cancel the cart and start a new one to change it.', 'code' => 'conflict'], 409); $set = [];
            if (array_key_exists('total', $in)) { if (!is_numeric($in['total']) || (float)$in['total'] < 100 || (float)$in['total'] > 500000) json_response(['error' => 'The agreed total must be a number from 100 to 500000.'], 422); $set['total_p'] = wch_p($in['total']); }
            if (array_key_exists('ml', $in)) { if (!is_numeric($in['ml']) || (int)$in['ml'] < 1 || (int)$in['ml'] > 5000) json_response(['error' => 'The total ml must be a whole number from 1 to 5000.'], 422); $set['ml'] = (int)$in['ml']; }
            if (array_key_exists('lines', $in)) { [$ok, $l] = wch_lines_clean($pdo, $in['lines']); if (!$ok) json_response(['error' => $l], 422); $set['lines_json'] = wch_json($l); }
            if ($set) wch_cart_touch($pdo, (int)$c['id'], $set); json_response(['ok' => true, 'on' => $on, 'cart' => $cartOut((int)$c['id'])]);
        }
        case 'wch.cart_get': {
            $needGet(); if (!empty($in['id'])) { $c = $cartOf((int)$in['id']); json_response(['ok' => true, 'on' => $on, 'cart' => $cartOut((int)$c['id'])]); }
            $k = $phoneIn(); $ct = $k !== '' ? wa_contact($pdo, $k) : null; if (!$ct) json_response(['error' => 'Chat not found.'], 404); if (!wa_can_chat($pdo, $auth, $ct)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $c = wch_cart_open_for($pdo, $k); json_response(['ok' => true, 'on' => $on, 'cart' => $c ? $cartOut((int)$c['id']) : null]);
        }
        case 'wch.cart_list': {
            $needGet(); $days = max(1, min(90, (int)($in['days'] ?? 7))); $w = ["c.updated_at >= ?"]; $a = [wch_dt(wch_now() - $days * 86400)];
            if (!empty($in['status'])) { $w[] = "c.status = ?"; $a[] = (string)$in['status']; } $k = $phoneIn(); if ($k !== '') { $w[] = "c.phone = ?"; $a[] = $k; }
            if (!$adm) { if ($me <= 0) json_response(['ok' => true, 'carts' => []]); $w[] = "k.assigned_emp = ?"; $a[] = $me; }
            $q = $pdo->prepare("SELECT c.id, c.phone, c.status, c.total_p, c.emp_id, c.updated_at, k.name FROM wch_cart c LEFT JOIN wa_contacts k ON k.phone = c.phone WHERE " . implode(' AND ', $w) . " ORDER BY c.id DESC LIMIT 100"); $q->execute($a); $o = [];
            foreach ($q->fetchAll() as $r) $o[] = ['id' => (int)$r['id'], 'phone' => $r['phone'], 'customer' => (string)$r['name'], 'status' => $r['status'], 'status_label' => wch_status_labels()[$r['status']] ?? $r['status'], 'total' => wch_r((int)$r['total_p']), 'emp_name' => (string)($names[(int)$r['emp_id']] ?? ''), 'updated_at' => $r['updated_at']];
            json_response(['ok' => true, 'carts' => $o]);
        }
        case 'wch.chain_diagnose': { $needGet(); $cid = (int)($in['id'] ?? 0); json_response(wch_chain_diag($pdo, $cfg, $cid > 0 ? $cartOf($cid) : null)); }   // 🩺 why no chains
        case 'wch.chain_suggest': { $needGet(); $c = $cartOf((int)($in['id'] ?? 0)); $s = wch_suggest_out($pdo, (int)$c['id'], (int)$c['total_p'], array_merge($cfg, ['wch_split_pct' => (float)$c['split_pct']])); $s['on'] = $on; json_response($s); }
        case 'wch.below_reason': {   // the salesperson's reason for an agreed total below the lowest price (the owner sees it in the cart history)
            $needPost(); $c = $cartOf((int)($in['id'] ?? 0)); $r = trim(mb_substr((string)($in['reason'] ?? ''), 0, 280)); if (mb_strlen($r) < 4) json_response(['error' => 'Write the reason (a few words).'], 422);
            wch_ev($pdo, (int)$c['id'], 'below_lowest', $r); json_response(['ok' => true, 'cart' => $cartOut((int)$c['id'])]);
        }
        case 'wch.chain_send': {
            $needPost(); $c = $cartOf((int)($in['id'] ?? 0)); $ids = isset($in['option_ids']) && is_array($in['option_ids']) ? array_values(array_filter(array_map('strval', $in['option_ids']))) : [];
            [$ok, $e, $cd, $d] = wch_chain_send($pdo, $c, $cfg, $ids, !empty($in['confirm_ml']), $me); if (!$ok) $fail($e, $cd, is_array($d) ? $d : []); json_response(['ok' => true, 'on' => $on, 'cart' => $cartOut((int)$c['id']), 'sent' => $d]);
        }
        case 'wch.chain_pick': {
            $needPost(); $c = $cartOf((int)($in['id'] ?? 0)); $p = $in['pick'] ?? null; if ($p === 'none' || $p === 'no' || $p === 0 || $p === '0') $p = 'none'; elseif (is_numeric($p) && (int)$p >= 1) $p = (int)$p; else json_response(['error' => 'pick must be the option number (1, 2, 3) or "none".'], 422);
            [$ok, $e, $cd, $d] = wch_chain_pick($pdo, $c, $p, 'salesperson', $me); if (!$ok) $fail($e, $cd); json_response(['ok' => true, 'on' => $on, 'cart' => $cartOut((int)$c['id']), 'confirmation_sent' => $d['confirmation_sent']]);
        }
        case 'wch.cart_link': {
            $needPost(); $c = $cartOf((int)($in['id'] ?? 0)); @set_time_limit(60); [$ok, $e, $cd, $d] = wch_cart_link($pdo, $c, $cfg, $me); if (!$ok) $fail($e, $cd, is_array($d) ? $d : []);
            json_response(['ok' => true, 'on' => $on, 'cart' => $cartOut((int)$c['id']), 'link' => ['url' => $d['url'], 'draft_name' => $d['draft_name']], 'resent' => $d['resent']]);
        }
        case 'wch.cart_status': { $needGet(); $c = $cartOf((int)($in['id'] ?? 0)); json_response(wch_cart_status($pdo, $c, $cfg)); }
        case 'wch.cart_cancel': {
            $needPost(); $c = $cartOf((int)($in['id'] ?? 0)); [$ok, $n, $cd] = wch_cart_cancel($pdo, $c, trim((string)($in['reason'] ?? '')), !empty($in['notify']), $me); if (!$ok) $fail($n, $cd); json_response(['ok' => true, 'note' => $n, 'cart' => $cartOut((int)$c['id'])]);
        }
        case 'wch.billing': {
            $needAdmin(); $warn = [];
            if ($isPost) {
                $cur = wch_b_get($pdo); [$err, $warn, $clean] = wch_b_validate($in, $cur);
                if (array_key_exists('inv_prefix', $clean) || array_key_exists('inv_next', $clean)) { if (wch_inv_locked($pdo) && ((array_key_exists('inv_prefix', $clean) && $clean['inv_prefix'] !== $cur['inv_prefix']) || (array_key_exists('inv_next', $clean) && $clean['inv_next'] !== $cur['inv_next']))) json_response(['error' => 'The first invoice has been issued, so the invoice prefix and number can no longer change.', 'code' => 'locked'], 409); }
                $logo = null; if (!empty($in['logo_data'])) { [$lok, $lr] = wch_logo_save((string)$in['logo_data']); if (!$lok) $err['logo'] = $lr; else $logo = $lr; }
                if ($err) json_response(['error' => 'Some values need a change: ' . implode(' ', array_values($err)), 'errors' => $err], 422);
                if (array_key_exists('inv_next', $clean) && !wch_inv_locked($pdo)) { try { $pdo->exec("DELETE FROM wch_seq"); } catch (Throwable $e) { } }   // no invoice yet: the counter starts again from the new number
                foreach ($clean as $k => $v) { if ($k === 'split_pct') wch_put($pdo, 'wch_split_pct', (string)(int)round((float)$v)); else wch_put($pdo, 'wch_b_' . $k, $v); }
                if ($logo !== null) wch_put($pdo, 'wch_b_logo', $logo); if (!empty($in['remove_logo'])) wch_put($pdo, 'wch_b_logo', '');
                $cfg = wch_cfg($pdo);
            }
            json_response(wch_billing_out($pdo, $warn, !empty($in['logo'])));
        }
        case 'wch.bill_preview': {
            $needAdmin(); $b = wch_b_get($pdo); $warn = [];
            if ($isPost && !empty($in['values']) && is_array($in['values'])) { [$err, $warn, $clean] = wch_b_validate($in['values'], $b); foreach ($clean as $k => $v) $b[$k] = $v; foreach ($err as $k => $e) $warn[] = $e; }
            $cartId = (int)($in['cart_id'] ?? 0); $on_ = (string)($in['order_name'] ?? ''); $row = null; $sample = true;
            if ($cartId > 0) $row = wch_cart_row($pdo, $cartId); elseif ($on_ !== '') { $q = $pdo->prepare("SELECT * FROM wch_cart WHERE order_name = ? ORDER BY id DESC LIMIT 1"); $q->execute([$on_]); $row = $q->fetch() ?: null; }
            if (($cartId > 0 || $on_ !== '') && !$row) json_response(['error' => 'Order not found.'], 404);
            if ($row) { $sample = false; $inv = (int)$row['inv_id'] > 0 ? wch_inv_row($pdo, (int)$row['inv_id']) : null;
                if ($inv) { $d = wch_inv_data($inv); $d['qr_url'] = wch_view_url($inv['token']); } else { [$ok, $e, $cd, $d] = wch_invoice_build($pdo, $row, $b, (string)($in['state'] ?? '')); if (!$ok) json_response(['error' => $e, 'code' => $cd], $http($cd)); $d['inv_no'] = wch_inv_format($b['inv_prefix'], wch_fy(wch_now()), max(1, (int)$b['inv_next'])); $d['date'] = date('Y-m-d', wch_now()); $d['qr_url'] = wch_base_url() . '/empmanagment/api.php?action=wch.view&t=' . str_repeat('0', 32); } }
            else { $d = wch_sample_data($b, (string)($in['state'] ?? 'Gujarat')); $d['qr_url'] = wch_base_url() . '/empmanagment/api.php?action=wch.view&t=' . str_repeat('0', 32); $ph = ['legal_name' => '(Legal name)', 'gstin' => '(GSTIN)', 'address' => '(Registered address)', 'phone' => '(Phone)']; foreach ($ph as $k => $v) if ($d['supplier'][$k] === '') $d['supplier'][$k] = $v; }
            $d['opts']['size'] = $b['size']; foreach (['show_short_name', 'show_hsn', 'show_bank', 'show_qr', 'show_logo', 'show_footer'] as $k) $d['opts'][$k] = (int)$b[$k];
            json_response(['ok' => true, 'html' => wch_bill_html($d, ['logo_path' => wch_logo_path($b)]), 'size' => $b['size'], 'sample' => $sample, 'invoice' => wch_data_out($d), 'warnings' => $warn]);
        }
        case 'wch.bill': {
            $needGet(); $inv = null; if (!empty($in['id'])) $inv = wch_inv_row($pdo, (int)$in['id']); elseif (!empty($in['inv_no'])) $inv = wch_inv_row($pdo, (string)$in['inv_no'], 'inv_no'); elseif (!empty($in['cart_id'])) $inv = wch_inv_row($pdo, (int)$in['cart_id'], 'cart_id'); elseif (!empty($in['order_name'])) $inv = wch_inv_row($pdo, (string)$in['order_name'], 'order_name');
            if (!$inv) { $can = false; if (!empty($in['order_name']) || !empty($in['cart_id'])) { $q = $pdo->prepare(!empty($in['cart_id']) ? "SELECT 1 FROM wch_cart WHERE id = ? AND order_name <> ''" : "SELECT 1 FROM wch_cart WHERE order_name = ?"); $q->execute([!empty($in['cart_id']) ? (int)$in['cart_id'] : (string)$in['order_name']]); $can = (bool)$q->fetchColumn(); } json_response(['error' => 'There is no bill for this order yet.', 'can_make' => $can], 404); }
            if (!$adm) { $c = wa_contact($pdo, (string)$inv['phone']); if (!$c || !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403); }
            json_response(['ok' => true, 'invoice' => wch_data_out(wch_inv_data($inv), $inv)]);
        }
        case 'wch.bill_make': {
            $needPost(); $needAdmin(); $row = null; if (!empty($in['cart_id'])) $row = wch_cart_row($pdo, (int)$in['cart_id']); elseif (!empty($in['order_name'])) { $q = $pdo->prepare("SELECT * FROM wch_cart WHERE order_name = ? ORDER BY id DESC LIMIT 1"); $q->execute([(string)$in['order_name']]); $row = $q->fetch() ?: null; }
            if (!$row) json_response(['error' => 'Order not found.'], 404); [$ok, $e, $cd, $inv] = wch_bill_make($pdo, $row, (string)($in['state'] ?? ''), !empty($in['force'])); if (!$ok) $fail($e, $cd);
            if ((string)($in['state'] ?? '') !== '' || (string)$row['bill_err'] !== '') $pdo->prepare("UPDATE wch_cart SET bill_err = '' WHERE id = ?")->execute([$row['id']]);
            json_response(['ok' => true, 'invoice' => wch_data_out(wch_inv_data($inv), $inv)]);
        }
        case 'wch.bill_resend': {
            $needPost(); $inv = wch_inv_row($pdo, (int)($in['id'] ?? 0)); if (!$inv) json_response(['error' => 'Bill not found.'], 404);
            if (!$adm) { $c = wa_contact($pdo, (string)$inv['phone']); if (!$c || !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403); }
            [$ok, $e, $cd] = wch_bill_send($pdo, $inv, $me); if (!$ok) $fail($e, $cd === 'failed' ? 'send_failed' : $cd); $inv = wch_inv_row($pdo, (int)$inv['id']); json_response(['ok' => true, 'invoice' => wch_data_out(wch_inv_data($inv), $inv)]);
        }
        case 'wch.bills': {
            $needGet(); $needAdmin(); $days = max(1, min(365, (int)($in['days'] ?? 30))); $w = ["created_at >= ?"]; $a = [wch_dt(wch_now() - $days * 86400)]; $qq = trim((string)($in['q'] ?? '')); if ($qq !== '') { $w[] = "(inv_no LIKE ? OR order_name LIKE ? OR customer LIKE ? OR phone LIKE ?)"; $l = '%' . $qq . '%'; array_push($a, $l, $l, $l, $l); }
            $st = $pdo->prepare("SELECT id, inv_no, order_name, customer, phone, total_p, sent_at, created_at FROM wch_inv WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT 200"); $st->execute($a); $o = [];
            foreach ($st->fetchAll() as $r) $o[] = ['id' => (int)$r['id'], 'inv_no' => $r['inv_no'], 'order_name' => $r['order_name'], 'customer' => $r['customer'], 'phone' => $r['phone'], 'total' => wch_r((int)$r['total_p']), 'sent_at' => $r['sent_at'], 'created_at' => $r['created_at']]; json_response(['ok' => true, 'items' => $o, 'count' => count($o)]);
        }
        case 'wch.messages': { $needGet(); $needAdmin(); json_response(wch_messages($pdo)); }
        case 'wch.message_save': { $needPost(); $needAdmin(); [$ok, $r, $cd] = wch_message_save($pdo, (string)($in['key'] ?? ''), $in, $me); if (!$ok) json_response(['error' => $r], $cd); json_response(['ok' => true, 'item' => $r]); }
        case 'wch.messages_submit': { $needPost(); $needAdmin(); @set_time_limit(120); $keys = isset($in['keys']) && is_array($in['keys']) ? array_map('strval', $in['keys']) : array_keys(array_filter(wch_msg_all_defs(), function ($d) { return $d['mode'] === 'template'; })); $r = wch_messages_submit($pdo, $keys); json_response(['ok' => empty($r['error']), 'created' => $r['created'], 'submitted' => $r['submitted'], 'skipped' => $r['skipped'], 'failed' => $r['failed']] + (isset($r['error']) ? ['error' => $r['error']] : [])); }
        case 'wch.claude_state': {
            $needAdmin(); $area = (string)($in['area'] ?? ''); if (!in_array($area, ['billing', 'catalog'], true)) json_response(['error' => 'area must be billing or catalog.'], 422);
            if ($isPost) { if (empty($in['confirm'])) json_response(['error' => 'Nothing to change.'], 422); wch_meta_put($pdo, 'claude_ok:' . $area, wch_dt()); } json_response(wch_claude_state($pdo, $area, $cfg));
        }
        case 'wch.claude_next': { $needGet(); $needAdmin(); $area = (string)($in['area'] ?? ''); if (!in_array($area, ['billing', 'catalog'], true)) json_response(['error' => 'area must be billing or catalog.'], 422); json_response(wch_claude_next($pdo, $area, $cfg)); }
        case 'wch.claude_answer': {
            $needPost(); $needAdmin(); $id = (string)($in['id'] ?? ''); if ($id === '') json_response(['error' => 'id is missing.'], 422); [$ok, $e, $cd] = wch_claude_answer($pdo, $id, $in['answer'] ?? '', $in, $me, $cfg); if (!$ok) json_response(['error' => $e], $cd);
            $area = strpos($id, 'b:') === 0 ? 'billing' : 'catalog'; $n = wch_claude_next($pdo, $area, wch_cfg($pdo)); json_response(['ok' => true, 'applied' => empty($in['skip']), 'pending' => $n['pending'], 'next' => $n['item']]);
        }
        case 'wch.settings': {
            if ($isPost) {
                $needAdmin(); $err = [];
                if (array_key_exists('wch_on', $in)) wch_put($pdo, 'wch_on', !empty($in['wch_on']) && (string)$in['wch_on'] !== '0' && strtolower((string)$in['wch_on']) !== 'false' ? '1' : '0');
                if (array_key_exists('wch_ship_free', $in)) wch_put($pdo, 'wch_ship_free', !empty($in['wch_ship_free']) && (string)$in['wch_ship_free'] !== '0' ? '1' : '0');
                foreach (wch_limits() as $k => [$lo, $hi]) if (array_key_exists($k, $in)) { if (!is_numeric($in[$k]) || (int)$in[$k] < $lo || (int)$in[$k] > $hi) $err[] = $k . ' must be a number from ' . $lo . ' to ' . $hi . '.'; else wch_put($pdo, $k, (string)(int)$in[$k]); }
                if (array_key_exists('wch_api_version', $in)) { if (!preg_match('/^\d{4}-(01|04|07|10)$/', (string)$in['wch_api_version'])) $err[] = 'wch_api_version must look like 2025-10 (January, April, July or October).'; else wch_put($pdo, 'wch_api_version', (string)$in['wch_api_version']); }
                if ($err) json_response(['error' => implode(' ', $err)], 422); $cfg = wch_cfg($pdo);
            }
            json_response(['ok' => true, 'editable' => $adm, 'settings' => $cfg, 'limits' => wch_limits()]);
        }
        case 'wch.status': { $needGet(); $needAdmin(); json_response(wch_status($pdo, $cfg)); }
        case 'wch.tick': { $needPost(); $needAdmin(); $t = microtime(true); $r = wch_tick($pdo, ['budget' => 8]); json_response(['ok' => true, 'result' => $r, 'ms' => (int)round((microtime(true) - $t) * 1000)]); }
    }
}
