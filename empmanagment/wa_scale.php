<?php
/**
 * wa_scale.php — makes the WhatsApp section fast with a LOT of customers (100,000+ contacts, millions of messages, 1,000–2,000 new queries a day).
 *
 *  1. Indexes the busy tables once (wa_scale_indexes).
 *  2. wa_cust / wa_tagidx: one small summary row per customer (orders, last order, ignored messages, last activity) and one row per customer+tag,
 *     rebuilt in the background every 10 minutes (wa_cust_refresh) — so Contacts, Campaign audiences, Tags and Export are single SQL queries
 *     instead of loading every customer into PHP memory.
 *  3. WaLazyMap: the old "give me the whole map" helpers (orders, results, tags) now fetch only the customers a page really shows.
 */
if (!function_exists('wa_scale_indexes')) {

/** Adds the indexes the busy queries need. Runs once; remembered in the settings table. */
function wa_scale_indexes(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    try { if ((string)setting_get($pdo, 'wa_idx_v1', '') !== '') return; } catch (Throwable $e) { return; }
    $want = [
        ['wa_contacts', 'k_status_in', '(status, last_in_at)'], ['wa_contacts', 'k_emp_status_in', '(assigned_emp, status, last_in_at)'], ['wa_contacts', 'k_source', '(source)'],
        ['wa_contacts', 'k_out', '(last_out_at)'], ['wa_contacts', 'k_assigned_at', '(assigned_at)'], ['wa_contacts', 'k_first', '(first_seen)'],
        ['wa_messages', 'k_dir_type', '(dir, mtype, phone, ts)'], ['wa_messages', 'k_ts', '(ts)'], ['wa_messages', 'k_phone_dir', '(phone, dir, id)'],
        ['wa_outcomes', 'k_code', '(code, at)'], ['wa_assign_log', 'k_at', '(at)'], ['shop_orders', 'k_phone', '(phone)'],
    ];
    foreach ($want as [$t, $name, $cols]) {
        try { $q = $pdo->query("SHOW INDEX FROM $t WHERE Key_name = '$name'"); if ($q && $q->fetch()) continue; $pdo->exec("ALTER TABLE $t ADD INDEX $name $cols"); } catch (Throwable $e) { }
    }
    try { setting_set($pdo, 'wa_idx_v1', date('Y-m-d H:i:s')); } catch (Throwable $e) { }
}
/** Second set: "covering" indexes so the Overview counters read only the index, not 40,000 rows each. */
function wa_scale_indexes2(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    try { if ((string)setting_get($pdo, 'wa_idx_v4', '') !== '') return; } catch (Throwable $e) { return; }
    $want = [['wa_contacts', 'k_cov_src', '(status, source, last_in_at, last_out_at, optout)'], ['wa_contacts', 'k_cov_emp', '(status, assigned_emp, last_in_at, last_out_at, optout)'], ['wa_messages', 'k_num_dir', '(number_id, dir, id)'], ['wa_messages', 'k_status_ts', '(status, ts)']];
    foreach ($want as [$t, $name, $cols]) {
        try { $q = $pdo->query("SHOW INDEX FROM $t WHERE Key_name = '$name'"); if ($q && $q->fetch()) continue; $pdo->exec("ALTER TABLE $t ADD INDEX $name $cols"); } catch (Throwable $e) { }
    }
    try { setting_set($pdo, 'wa_idx_v4', date('Y-m-d H:i:s')); } catch (Throwable $e) { }
}

if (!defined('WA_CUST_COLS')) define('WA_CUST_COLS', "phone VARCHAR(12) NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', n INT NOT NULL DEFAULT 0, last_order DATETIME NULL, spent DECIMAL(12,2) NOT NULL DEFAULT 0, ign INT NOT NULL DEFAULT 0, act_at DATETIME NULL, KEY k_last (last_order), KEY k_act (act_at)");
function wa_cust_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cust (" . WA_CUST_COLS . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_tagidx (phone VARCHAR(12) NOT NULL, tag VARCHAR(40) NOT NULL, PRIMARY KEY (phone, tag), KEY k_tag (tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wa_cust_age(PDO $pdo): int { $t = (string)setting_get($pdo, 'wa_cust_at', ''); return $t === '' ? 999999 : max(0, time() - (int)strtotime($t)); }

/** Rebuilds the customer summary + tag index. Safe to call often: it does nothing unless the data is older than $maxAge seconds, and only one process does it at a time. */
function wa_cust_refresh(PDO $pdo, int $maxAge = 600, bool $force = false): bool
{
    if (!wa_cust_tables($pdo)) return false;
    wa_scale_indexes($pdo); wa_scale_indexes2($pdo);
    if (!$force && wa_cust_age($pdo) < $maxAge) return true;
    try { $got = (int)$pdo->query("SELECT GET_LOCK('wa_cust_refresh', 0)")->fetchColumn(); } catch (Throwable $e) { return false; }
    if ($got !== 1) return false;
    try {
        ignore_user_abort(true); @set_time_limit(300); $t0 = microtime(true);
        $cfg = function_exists('wag_cfg') ? wag_cfg($pdo) : ['vip_orders' => 5, 'vip_ltv' => 15000]; $now = date('Y-m-d H:i:s');
        $pdo->exec("DROP TABLE IF EXISTS wa_cust_new"); $pdo->exec("DROP TABLE IF EXISTS wa_tagidx_new");
        // the new tables take the collation of wa_contacts: a server default (utf8mb4_0900_ai_ci) different from it made every join fail ("Illegal mix of collations")
        $coll = ''; try { $q = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_contacts' AND COLUMN_NAME = 'phone'"); $cn = (string)$q->fetchColumn(); if (preg_match('/^utf8mb4_[a-z0-9_]+$/', $cn)) $coll = ' COLLATE ' . $cn; } catch (Throwable $e) { }
        $pdo->exec("CREATE TABLE wa_cust_new (" . WA_CUST_COLS . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" . $coll);
        $pdo->exec("CREATE TABLE wa_tagidx_new (phone VARCHAR(12) NOT NULL, tag VARCHAR(40) NOT NULL, PRIMARY KEY (phone, tag), KEY k_tag (tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" . $coll);
        // 1. orders per customer (phones are normalised to the last 10 digits)
        try {
            $pdo->exec("INSERT IGNORE INTO wa_cust_new (phone, name, n, last_order, spent)
                SELECT k, nm, n, last, total FROM (SELECT SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), -10) AS k, MAX(customer) AS nm, COUNT(*) AS n, MAX(created_at) AS last, COALESCE(SUM(total), 0) AS total
                FROM shop_orders WHERE phone IS NOT NULL AND phone != '' GROUP BY k) x WHERE CHAR_LENGTH(k) = 10");
        } catch (Throwable $e) { }
        // 2. every WhatsApp contact is a customer too
        $pdo->exec("INSERT IGNORE INTO wa_cust_new (phone, name) SELECT phone, name FROM wa_contacts");
        // 3. template messages that got no answer
        $pdo->exec("UPDATE wa_cust_new s JOIN (SELECT m.phone, COUNT(*) n FROM wa_messages m JOIN wa_contacts c ON c.phone = m.phone WHERE m.dir = 'out' AND m.mtype = 'template' AND (c.last_in_at IS NULL OR m.ts > c.last_in_at) GROUP BY m.phone) x ON x.phone = s.phone SET s.ign = x.n");
        // 4. last activity (message in, message out, order)
        $pdo->exec("UPDATE wa_cust_new s LEFT JOIN wa_contacts c ON c.phone = s.phone SET s.act_at = NULLIF(GREATEST(COALESCE(c.last_in_at, '1970-01-01'), COALESCE(c.last_out_at, '1970-01-01'), COALESCE(s.last_order, '1970-01-01')), '1970-01-01')");
        // 5. tags (same rules as wag_row_tags, written as SQL)
        $vo = (int)$cfg['vip_orders']; $vl = (int)$cfg['vip_ltv']; $cut30 = date('Y-m-d H:i:s', time() - 30 * 86400);
        $oc = "COALESCE((SELECT o.code FROM wa_outcomes o WHERE o.phone = c.phone ORDER BY o.id DESC LIMIT 1), '')";
        $base = "FROM wa_contacts c LEFT JOIN wa_cust_new s ON s.phone = c.phone";
        $rules = [
            'bought' => "COALESCE(s.n,0) >= 1", 'repeat' => "COALESCE(s.n,0) >= 2", 'vip' => "(COALESCE(s.n,0) >= $vo OR COALESCE(s.spent,0) >= $vl)",
            'not_ordered' => "COALESCE(s.n,0) = 0 AND c.last_in_at IS NOT NULL", 'never_replied' => "c.last_in_at IS NULL AND COALESCE(s.ign,0) >= 1",
            'cold' => "c.last_in_at IS NOT NULL AND c.last_in_at < '$cut30'", 'from_ad' => "c.source = 'query'", 'came_back' => "c.source = 'campaign'",
        ];
        foreach ($rules as $tag => $w) $pdo->exec("INSERT IGNORE INTO wa_tagidx_new (phone, tag) SELECT c.phone, '$tag' $base WHERE $w");
        $outRules = ['wants_buy' => "(c.tag = 'buy' OR $oc = 'later')", 'price_issue' => "(c.tag = 'price' OR $oc = 'price')", 'not_interested' => "$oc = 'no_interest'", 'wrong_number' => "$oc = 'wrong'", 'complaint' => "(c.tag = 'complaint' OR $oc = 'complaint')"];
        foreach ($outRules as $tag => $w) { try { $pdo->exec("INSERT IGNORE INTO wa_tagidx_new (phone, tag) SELECT c.phone, '$tag' $base WHERE $w"); } catch (Throwable $e) { } }
        try { if (function_exists('wag_cod_map')) { $st = $pdo->prepare("INSERT IGNORE INTO wa_tagidx_new (phone, tag) VALUES (?, 'cod_pending')"); foreach (array_keys(wag_cod_map($pdo)) as $k) $st->execute([$k]); } } catch (Throwable $e) { }
        try { $pdo->exec("INSERT IGNORE INTO wa_tagidx_new (phone, tag) SELECT phone, tag FROM wa_ctags"); } catch (Throwable $e) { }
        // 6. swap in one step
        $pdo->exec("DROP TABLE IF EXISTS wa_cust_old"); $pdo->exec("DROP TABLE IF EXISTS wa_tagidx_old");
        $pdo->exec("RENAME TABLE wa_cust TO wa_cust_old, wa_cust_new TO wa_cust, wa_tagidx TO wa_tagidx_old, wa_tagidx_new TO wa_tagidx");
        $pdo->exec("DROP TABLE IF EXISTS wa_cust_old"); $pdo->exec("DROP TABLE IF EXISTS wa_tagidx_old");
        setting_set($pdo, 'wa_cust_at', date('Y-m-d H:i:s'));
        try { if (function_exists('wag_tag_counts')) { setting_set($pdo, 'wa_tag_cache', ''); wag_tag_counts($pdo); } } catch (Throwable $e) { }   // warm the tag-count cache
        if (function_exists('wag_log')) wag_log($pdo, 'engine', true, 'Customer summary rebuilt in ' . round(microtime(true) - $t0, 1) . ' s.');
        $ok = true;
    } catch (Throwable $e) { if (function_exists('wag_log')) wag_log($pdo, 'engine', false, 'Customer summary failed: ' . $e->getMessage()); $ok = false; }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_cust_refresh')"); } catch (Throwable $e) { }
    return $ok;
}
/** A request that needs the summary: builds it right now only if it has never been built; otherwise the cron keeps it fresh. */
function wa_cust_ready(PDO $pdo): void
{
    static $chk = false; if ($chk) return; $chk = true;
    if (!wa_cust_tables($pdo)) return;
    wa_scale_indexes($pdo); wa_scale_indexes2($pdo);
    $age = wa_cust_age($pdo); if ($age > 3600) wa_cust_refresh($pdo, 0, true);   // never built (or the cron has been off for an hour)
}

/** A read-only "array" that loads rows from the database only when asked, and many at once with prefetch(). Behaves like the old full maps for $map[$key] and isset/??. */
class WaLazyMap implements ArrayAccess, IteratorAggregate, Countable
{
    private array $rows = []; private array $miss = []; private $loader;
    public function __construct(callable $loader) { $this->loader = $loader; }
    public function prefetch(array $keys): void
    {
        $need = []; foreach ($keys as $k) { $k = (string)$k; if ($k !== '' && !isset($this->rows[$k]) && !isset($this->miss[$k])) $need[$k] = true; } $need = array_keys($need);
        foreach (array_chunk($need, 500) as $ch) { $got = ($this->loader)($ch); foreach ($ch as $k) { if (isset($got[$k])) $this->rows[$k] = $got[$k]; else $this->miss[$k] = true; } }
    }
    public function offsetExists(mixed $k): bool { $this->prefetch([$k]); return isset($this->rows[(string)$k]); }
    public function offsetGet(mixed $k): mixed { $this->prefetch([$k]); return $this->rows[(string)$k] ?? null; }
    public function offsetSet(mixed $k, mixed $v): void { }
    public function offsetUnset(mixed $k): void { }
    public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
    public function count(): int { return count($this->rows); }
}
function wa_in(array $ks): string { return implode(',', array_fill(0, count($ks), '?')); }

// ---------------------------------------------------------------------------------------------------------------- audience: one SQL query instead of loading every customer
/** [FROM … WHERE …, params] for the Contacts / Campaign filters (st · ord · dsl · ign · src · wrote · gtag) over the customer summary. $src: contacts | all. */
function wa_aud_where(string $src, array $f, string $q = ''): array
{
    $now = time(); $d = fn(int $n) => date('Y-m-d H:i:s', $now - $n * 86400); $w = []; $p = [];
    // contacts: start from wa_contacts (indexed, newest first) · all: start from the customer summary
    if ($src === 'contacts') { $from = "wa_contacts c LEFT JOIN wa_cust s ON s.phone = c.phone"; $P = 'c.phone'; }
    else { $from = "wa_cust s LEFT JOIN wa_contacts c ON c.phone = s.phone"; $P = 's.phone'; $w[] = "s.phone REGEXP '^[6-9][0-9]{9}$'"; }
    $st = (string)($f['st'] ?? '');
    if ($st === 'waiting') $w[] = "(c.last_in_at IS NOT NULL AND c.last_in_at > COALESCE(c.last_out_at, '1970-01-01') AND c.optout = 0 AND c.status = 'open')";
    if ($st === 'noreply') $w[] = 'c.last_in_at IS NULL';
    if ($st === 'optout') $w[] = 'c.optout = 1';
    if ($st === 'reach') $w[] = 'COALESCE(c.optout, 0) = 0';   // can be messaged
    // the Customers screen (wa_cust.php): all of these tags · none of these tags · last order / got a campaign between two dates · orders / ₹ spent ranges · number · salesperson
    foreach (['tags_all', 'tags_not'] as $k0) { $tl = array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), is_array($f[$k0] ?? null) ? $f[$k0] : []), fn($x) => $x !== '')), 0, 30);
        if (!$tl) continue; if ($k0 === 'tags_all') { foreach ($tl as $x) { $w[] = "EXISTS (SELECT 1 FROM wa_tagidx ta WHERE ta.phone = $P AND ta.tag = ?)"; $p[] = $x; } }
        else { $w[] = "NOT EXISTS (SELECT 1 FROM wa_tagidx tn WHERE tn.phone = $P AND tn.tag IN (" . wa_in($tl) . "))"; foreach ($tl as $x) $p[] = $x; } }
    if (isset($f['lo_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['lo_from'])) { $w[] = 's.last_order IS NOT NULL AND s.last_order >= ?'; $p[] = $f['lo_from'] . ' 00:00:00'; }
    if (isset($f['lo_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['lo_to'])) { $w[] = 's.last_order IS NOT NULL AND s.last_order <= ?'; $p[] = $f['lo_to'] . ' 23:59:59'; }
    $cf0 = isset($f['camp_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['camp_from']) ? $f['camp_from'] . ' 00:00:00' : ''; $ct0 = isset($f['camp_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['camp_to']) ? $f['camp_to'] . ' 23:59:59' : '';
    if ($cf0 !== '' || $ct0 !== '') { $w[] = "EXISTS (SELECT 1 FROM wa_messages cm WHERE cm.phone = $P AND cm.dir = 'out' AND cm.who = 'campaign'" . ($cf0 !== '' ? ' AND cm.ts >= ?' : '') . ($ct0 !== '' ? ' AND cm.ts <= ?' : '') . ")"; if ($cf0 !== '') $p[] = $cf0; if ($ct0 !== '') $p[] = $ct0; }
    foreach ([['ord_min', 'COALESCE(s.n, 0) >= ?'], ['ord_max', 'COALESCE(s.n, 0) <= ?'], ['sp_min', 'COALESCE(s.spent, 0) >= ?'], ['sp_max', 'COALESCE(s.spent, 0) <= ?']] as [$k0, $sq0]) if (isset($f[$k0]) && is_numeric($f[$k0])) { $w[] = $sq0; $p[] = (int)$f[$k0]; }
    if (isset($f['num']) && (int)$f['num'] > 0) { $w[] = 'c.last_number_id = ?'; $p[] = (int)$f['num']; }
    if (isset($f['emp']) && is_numeric($f['emp']) && (int)$f['emp'] !== 0) { if ((int)$f['emp'] < 0) $w[] = 'COALESCE(c.assigned_emp, 0) = 0'; else { $w[] = 'c.assigned_emp = ?'; $p[] = (int)$f['emp']; } }
    $o = (string)($f['ord'] ?? ''); if ($o === '0') $w[] = 'COALESCE(s.n, 0) = 0'; elseif ($o === '1') $w[] = 's.n = 1'; elseif ($o === '2') $w[] = 's.n = 2'; elseif ($o === '3') $w[] = 's.n >= 3';
    $ds = (string)($f['dsl'] ?? '');
    if ($ds === '30') { $w[] = 's.last_order IS NOT NULL AND s.last_order > ?'; $p[] = $d(31); }
    elseif ($ds === '60') { $w[] = 's.last_order IS NOT NULL AND s.last_order <= ? AND s.last_order > ?'; $p[] = $d(31); $p[] = $d(61); }
    elseif ($ds === '90') { $w[] = 's.last_order IS NOT NULL AND s.last_order <= ? AND s.last_order > ?'; $p[] = $d(61); $p[] = $d(91); }
    elseif ($ds === '91') { $w[] = 's.last_order IS NOT NULL AND s.last_order <= ?'; $p[] = $d(91); }
    $ig = (int)($f['ign'] ?? 0); if ($ig > 0) { $w[] = 's.ign >= ?'; $p[] = $ig; }
    $gt = (string)($f['gtag'] ?? ''); if ($gt !== '') { $w[] = "EXISTS (SELECT 1 FROM wa_tagidx t WHERE t.phone = $P AND t.tag = ?)"; $p[] = $gt; }
    // several tags, "any of these" (the Campaign screen): a customer with at least one of them
    $gts = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), is_array($f['gtags'] ?? null) ? $f['gtags'] : []), fn($x) => $x !== ''))); $gts = array_slice($gts, 0, 30);
    if ($gts) { $w[] = "EXISTS (SELECT 1 FROM wa_tagidx t2 WHERE t2.phone = $P AND t2.tag IN (" . wa_in($gts) . "))"; foreach ($gts as $x) $p[] = $x; }
    $gb = array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), is_array($f['gtags_b'] ?? null) ? $f['gtags_b'] : []), fn($x) => $x !== '')), 0, 30);   // a second "any of these" group (both must hold)
    if ($gb) { $w[] = "EXISTS (SELECT 1 FROM wa_tagidx t3 WHERE t3.phone = $P AND t3.tag IN (" . wa_in($gb) . "))"; foreach ($gb as $x) $p[] = $x; }
    // "last wrote between X and Y days ago" — whole days ago, both ends included (0 = today)
    $wf = $f['wrote_from'] ?? ''; $wt = $f['wrote_to'] ?? '';
    if ($wf !== '' && $wf !== null && is_numeric($wf)) { $w[] = 'c.last_in_at IS NOT NULL AND c.last_in_at <= ?'; $p[] = $d(max(0, min(3650, (int)$wf))); }
    if ($wt !== '' && $wt !== null && is_numeric($wt)) { $w[] = 'c.last_in_at IS NOT NULL AND c.last_in_at > ?'; $p[] = $d(max(0, min(3650, (int)$wt)) + 1); }
    // calendar dates (the Campaign / Contacts filters): last wrote between two dates · added (first message or import) between two dates — YYYY-MM-DD, both days included
    foreach ([['in_from', 'c.last_in_at', '>=', ' 00:00:00'], ['in_to', 'c.last_in_at', '<=', ' 23:59:59'], ['added_from', 'c.first_seen', '>=', ' 00:00:00'], ['added_to', 'c.first_seen', '<=', ' 23:59:59']] as [$k0, $col0, $op0, $tm0])
        if (isset($f[$k0]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f[$k0])) { $w[] = "$col0 IS NOT NULL AND $col0 $op0 ?"; $p[] = $f[$k0] . $tm0; }
    // has / has not ordered since they last wrote to us
    $os = (string)($f['ords'] ?? '');
    if ($os === 'no') $w[] = "c.last_in_at IS NOT NULL AND (s.last_order IS NULL OR s.last_order <= c.last_in_at)";
    elseif ($os === 'yes') $w[] = "c.last_in_at IS NOT NULL AND s.last_order IS NOT NULL AND s.last_order > c.last_in_at";
    $sc = (string)($f['src'] ?? ''); if ($sc === 'none') $w[] = "COALESCE(c.source, '') = ''"; elseif ($sc !== '') { $w[] = 'c.source = ?'; $p[] = $sc; }
    $wr = (string)($f['wrote'] ?? '');
    if ($wr === '30') { $w[] = 'c.last_in_at > ?'; $p[] = $d(30); } elseif ($wr === '7') { $w[] = 'c.last_in_at > ?'; $p[] = $d(7); } elseif ($wr === 'old') { $w[] = 'c.last_in_at IS NOT NULL AND c.last_in_at <= ?'; $p[] = $d(30); }
    // ---- customer story filters (wa_seg.php) — additive: every key above keeps working exactly as before. See wa_seg_filters_clean() for the list and the meaning.
    //  stage[] value[] source[] language[] first_number[] number_used[] · first_from/first_to · last_in_from/last_in_to (whole days ago, like wrote_from/wrote_to) · first_on_from/first_on_to · last_in_on_from/last_in_on_to (dates)
    //  silent_min_days + returned_within_days (came back after a silence) · asked_days · ordered_from/ordered_to (days ago) · order_count_min/max · spent_min/max · exclude_bought_days · exclude_messaged_days · exclude_complaint · _story (force the story join)
    $has = function ($k) use ($f) { return isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null && $f[$k] !== []; };
    $num = function ($k) use ($f, $has) { return $has($k) && is_numeric($f[$k]) ? (int)$f[$k] : null; };
    $list = function ($k) use ($f, $has) { return $has($k) ? array_values(array_unique(array_map('strval', (array)$f[$k]))) : []; };
    $needSt = false; foreach (['stage', 'value', 'source', 'language', 'first_number', 'number_used', 'first_from', 'first_to', 'first_on_from', 'first_on_to', 'last_in_on_from', 'last_in_on_to', 'silent_min_days', 'returned_within_days', 'asked_days', 'exclude_complaint', 'ordered_from', 'ordered_to', 'order_count_min', 'order_count_max', 'spent_min', 'spent_max', '_story'] as $k) if ($has($k)) { $needSt = true; break; }
    if ($needSt) {
        if (function_exists('wsg_tables') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) wsg_tables($GLOBALS['pdo']);
        $from .= " LEFT JOIN wa_cust_story st ON st.phone = $P";
        if ($l = $list('stage')) { $w[] = 'st.stage IN (' . wa_in($l) . ')'; foreach ($l as $x) $p[] = $x; }
        if ($l = $list('value')) { $w[] = 'st.value_tier IN (' . wa_in($l) . ')'; foreach ($l as $x) $p[] = $x; }
        if ($l = $list('source')) { $w[] = 'st.first_source IN (' . wa_in($l) . ')'; foreach ($l as $x) $p[] = $x; }
        if ($l = $list('language')) { $w[] = 'st.language_guess IN (' . wa_in($l) . ')'; foreach ($l as $x) $p[] = $x === 'unknown' ? '' : $x; }
        if ($l = array_values(array_filter(array_map('intval', $list('first_number'))))) { $w[] = 'st.first_number_id IN (' . wa_in($l) . ')'; foreach ($l as $x) $p[] = $x; }
        if ($l = array_values(array_filter(array_map('intval', $list('number_used'))))) { $w[] = 'EXISTS (SELECT 1 FROM wa_cust_sess x WHERE x.phone = st.phone AND x.number_id IN (' . wa_in($l) . '))'; foreach ($l as $x) $p[] = $x; }
        if (($n = $num('first_from')) !== null) { $w[] = 'st.first_seen IS NOT NULL AND st.first_seen <= ?'; $p[] = $d(max(0, min(3650, $n))); }
        if (($n = $num('first_to')) !== null) { $w[] = 'st.first_seen IS NOT NULL AND st.first_seen > ?'; $p[] = $d(max(0, min(3650, $n)) + 1); }
        if ($has('first_on_from') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['first_on_from'])) { $w[] = 'st.first_seen >= ?'; $p[] = $f['first_on_from'] . ' 00:00:00'; }
        if ($has('first_on_to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['first_on_to'])) { $w[] = 'st.first_seen <= ?'; $p[] = $f['first_on_to'] . ' 23:59:59'; }
        if ($has('last_in_on_from') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['last_in_on_from'])) { $w[] = 'st.last_in_at >= ?'; $p[] = $f['last_in_on_from'] . ' 00:00:00'; }
        if ($has('last_in_on_to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['last_in_on_to'])) { $w[] = 'st.last_in_at <= ?'; $p[] = $f['last_in_on_to'] . ' 23:59:59'; }
        if ($has('silent_min_days') || $has('returned_within_days')) {   // "was silent for at least X days, then wrote again within the last Y days" (the woke-up rule)
            $sm = max(1, $num('silent_min_days') ?? 60); $rw = max(0, $num('returned_within_days') ?? 3650);
            $w[] = '((st.woke_gap_days >= ? AND st.woke_at > ?) OR (st.last_gap_days >= ? AND st.last_sess_at > ?))'; array_push($p, $sm, $d($rw), $sm, $d($rw));
        }
        if (($n = $num('asked_days')) !== null) { $w[] = 'st.last_ask_at IS NOT NULL AND st.last_ask_at > ?'; $p[] = $d($n); }
        if (!empty($f['exclude_complaint'])) $w[] = "(st.phone IS NULL OR FIND_IN_SET('complaint', st.flags) = 0)";
    }
    // last-wrote ranges read the chat row (always fresh)
    if (($n = $num('last_in_from')) !== null) { $w[] = 'c.last_in_at IS NOT NULL AND c.last_in_at <= ?'; $p[] = $d(max(0, min(3650, $n))); }
    if (($n = $num('last_in_to')) !== null) { $w[] = 'c.last_in_at IS NOT NULL AND c.last_in_at > ?'; $p[] = $d(max(0, min(3650, $n)) + 1); }
    // order history of the customer (lifetime): from the story (copied from the customer summary every night and whenever the customer writes). Exclusions below use the live summary instead.
    if ($needSt) {
        if (($n = $num('ordered_from')) !== null) { $w[] = 'st.last_order_at IS NOT NULL AND st.last_order_at <= ?'; $p[] = $d(max(0, min(3650, $n))); }
        if (($n = $num('ordered_to')) !== null) { $w[] = 'st.last_order_at IS NOT NULL AND st.last_order_at > ?'; $p[] = $d(max(0, min(3650, $n)) + 1); }
        if (($n = $num('order_count_min')) !== null) { $w[] = 'COALESCE(st.orders, 0) >= ?'; $p[] = $n; }
        if (($n = $num('order_count_max')) !== null) { $w[] = 'COALESCE(st.orders, 0) <= ?'; $p[] = $n; }
        if ($has('spent_min') && is_numeric($f['spent_min'])) { $w[] = 'COALESCE(st.spent, 0) >= ?'; $p[] = (float)$f['spent_min']; }
        if ($has('spent_max') && is_numeric($f['spent_max'])) { $w[] = 'COALESCE(st.spent, 0) <= ?'; $p[] = (float)$f['spent_max']; }
    }
    // exclusions read the live summary / chat row, so a customer who ordered or was messaged a minute ago is never missed
    if (($n = $num('exclude_bought_days')) !== null) { $w[] = '(s.last_order IS NULL OR s.last_order < ?)'; $p[] = $d($n); }
    if (($n = $num('exclude_messaged_days')) !== null) { $w[] = '(c.last_out_at IS NULL OR c.last_out_at < ?)'; $p[] = $d($n); }
    if ($q !== '') { $l = '%' . addcslashes($q, '%_\\') . '%'; $w[] = "($P LIKE ? OR COALESCE(c.name, s.name) LIKE ?)"; $p[] = function_exists('wa_q_like') ? wa_q_like($q) : $l; $p[] = $l; }
    return [$from . ($w ? ' WHERE ' . implode(' AND ', $w) : ''), $p, $P];
}
/** How many customers match the filters (optionally only those who can be messaged: not opted out, no campaign message in the last $freq days). One COUNT query. */
function wa_aud_count(PDO $pdo, string $src, array $f, bool $sendable = false, int $freq = 0): int
{
    wa_cust_ready($pdo); [$fq, $p, $P] = wa_aud_where($src, $f, '');
    if ($sendable) {
        $fq .= (strpos($fq, ' WHERE ') === false ? ' WHERE ' : ' AND ') . "COALESCE(c.optout, 0) = 0";
        if ($freq > 0) { $fq .= " AND $P NOT IN (SELECT m.phone FROM wa_messages m WHERE m.dir = 'out' AND m.mtype = 'template' AND m.who = 'campaign' AND m.status <> 'failed' AND m.ts >= ?)"; $p[] = date('Y-m-d H:i:s', time() - $freq * 86400); }
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM $fq"); $c->execute($p); return (int)$c->fetchColumn();
}
/** One row in the shape the screens always had (see wa_aud_rows). */
function wa_aud_shape(array $r, int $now): array
{
    $days = $r['last_order'] ? (int)floor(($now - strtotime((string)$r['last_order'])) / 86400) : null; $known = $r['c_phone'] !== null;
    return ['phone' => (string)$r['phone'], 'name' => $known ? (string)$r['c_name'] : (string)($r['name'] ?? ''), 'known' => $known, 'orders' => (int)$r['n'], 'last_order' => $r['last_order'] ? substr((string)$r['last_order'], 0, 10) : '', 'days' => $days, 'spent' => (int)round((float)$r['spent']),
        'ignored' => (int)$r['ign'], 'optout' => $known ? (int)$r['optout'] === 1 : false, 'last_in_at' => $known ? $r['last_in_at'] : null, 'last_out_at' => $known ? $r['last_out_at'] : null, 'last_number_id' => $known ? (int)$r['last_number_id'] : 0,
        'source' => $known ? (string)$r['source'] : '', 'assigned_emp' => $known ? (int)$r['assigned_emp'] : 0, 'status' => $known ? (string)$r['status'] : '', 'tag' => $known ? (string)$r['ctag'] : '', 'first_seen' => $known ? $r['first_seen'] : null];
}
if (!defined('WA_AUD_SEL')) define('WA_AUD_SEL', "COALESCE(s.phone, c.phone) AS phone, s.name, COALESCE(s.n, 0) AS n, s.last_order, COALESCE(s.spent, 0) AS spent, COALESCE(s.ign, 0) AS ign, c.phone AS c_phone, c.name AS c_name, c.optout, c.last_in_at, c.last_out_at, c.last_number_id, c.source, c.assigned_emp, c.status, c.tag AS ctag, c.first_seen");
/** ORDER BY for the Customers screen's Sort ('' = the usual newest first). Never takes text from outside. */
function wa_aud_order(string $sort, string $src): string
{
    $P = $src === 'contacts' ? 'c.phone' : 's.phone';
    $m = ['latest' => 'c.last_in_at IS NULL, c.last_in_at DESC', 'spent' => 'COALESCE(s.spent, 0) DESC', 'orders' => 'COALESCE(s.n, 0) DESC, s.last_order DESC', 'order_old' => 's.last_order IS NULL, s.last_order ASC', 'order_new' => 's.last_order IS NULL, s.last_order DESC',
        'added' => 'c.first_seen IS NULL, c.first_seen DESC', 'name' => "COALESCE(NULLIF(c.name, ''), s.name, $P) ASC"];
    return isset($m[$sort]) ? $m[$sort] . ', ' . $P : '';
}
/** A page of the audience: [rows, total matching, total customers in this universe]. */
function wa_aud_page(PDO $pdo, string $src, array $f, string $q, int $off, int $lim, string $sort = ''): array
{
    wa_cust_ready($pdo); [$fq, $p, $P] = wa_aud_where($src, $f, $q); $now = time();
    $plain = !array_filter($f) && $q === '';
    [$aq, $ap, $PP] = wa_aud_where($src, [], ''); $a = $pdo->prepare("SELECT COUNT(*) FROM $aq"); $ak = 'wa_aud_all_' . $src; $allN = (int)setting_get($pdo, $ak . '_n', 0); $allAt = (string)setting_get($pdo, $ak . '_at', '');
    if ($allN <= 0 || $allAt !== (string)setting_get($pdo, 'wa_cust_at', '')) { $a->execute($ap); $allN = (int)$a->fetchColumn(); setting_set($pdo, $ak . '_n', (string)$allN); setting_set($pdo, $ak . '_at', (string)setting_get($pdo, 'wa_cust_at', '')); }
    if ($plain) $total = $allN; else { $c = $pdo->prepare("SELECT COUNT(*) FROM $fq"); $c->execute($p); $total = (int)$c->fetchColumn(); }
    $s = $pdo->prepare("SELECT " . WA_AUD_SEL . " FROM $fq ORDER BY " . (wa_aud_order($sort, $src) ?: ($src === 'contacts' ? 'c.last_in_at DESC, c.phone' : 's.act_at DESC, s.phone')) . " LIMIT " . (int)$lim . " OFFSET " . (int)$off); $s->execute($p);
    $rows = array_map(fn($r) => wa_aud_shape($r, $now), $s->fetchAll());
    return [$rows, $total, $allN];
}
/** Every matching customer (for sending a campaign / exporting): a generator, 2,000 rows at a time, so memory stays flat. */
function wa_aud_each(PDO $pdo, string $src, array $f, string $q = '', array $only = []): Generator
{
    wa_cust_ready($pdo); [$fq, $p, $P] = wa_aud_where($src, $f, $q); $now = time(); $last = '';
    if ($only) { $fq .= (strpos($fq, ' WHERE ') === false ? ' WHERE ' : ' AND ') . $P . ' IN (' . wa_in($only) . ')'; $p = array_merge($p, array_values($only)); }
    while (true) {
        $s = $pdo->prepare("SELECT " . WA_AUD_SEL . " FROM $fq" . ($last !== '' ? (strpos($fq, ' WHERE ') === false ? ' WHERE ' : ' AND ') . $P . ' > ?' : '') . " ORDER BY $P LIMIT 2000"); $s->execute($last !== '' ? array_merge($p, [$last]) : $p); $rows = $s->fetchAll(); if (!$rows) return;
        foreach ($rows as $r) { $last = (string)$r['phone']; yield wa_aud_shape($r, $now); } if (count($rows) < 2000) return;
    }
}

}   // function_exists guard
