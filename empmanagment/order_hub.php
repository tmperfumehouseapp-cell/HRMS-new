<?php
/**
 * ORDER HUB: the one big popup of Dispatch (an ORDER card + a CUSTOMER card + the WhatsApp chat) and the Customers list.
 *
 *   dp.dsp_hub     GET  ?id=<parcel id> | ?case=<Needs attention case id> | ?phone=<number> [&order=#1234 &store=key] | ?bill=<store bill id>
 *                         -> order card (payment, gateway, salesperson, commission, items, complaints), merged timeline (order -> payment -> tracking ->
 *                            every courier scan -> delivered -> COD money -> complaints -> Needs attention), customer card (lifetime numbers across
 *                            online + store + old POS, risk flags, who is waiting), and the list of this customer's orders.
 *   dp.dsp_cust_list     GET  ?q= &sort=spent|orders|last|first|name &flt=all|vip|risk|store|online|both|lapsed &page=   the Customers list (one row per person)
 *   dp.dsp_cust_rebuild  POST the Customers list is rebuilt from every order (also runs by itself in the background tick)
 *
 * ONE PERSON = the LAST 10 DIGITS of the phone number, whatever the spaces, +91 or 0 in front. Online orders (shop_orders), Manual orders, store bills
 * (st_bills / st_bill_people) and the old POS (pos_orders / pos_customers) all count toward the same person.
 * Nothing here is a source of truth: customer_facts is a copy rebuilt from the real tables (a failed step leaves the old numbers).  PHP 7.4 safe.
 */

if (!defined('OHUB_LOADED')) define('OHUB_LOADED', 1);

function ohub_p10($p): string { $d = preg_replace('/\D+/', '', (string)$p) ?? ''; return strlen($d) >= 10 ? substr($d, -10) : ''; }
/** SQL: a phone column with spaces, dashes, + ( ) . removed, then its last 10 digits */
function ohub_sqlp(string $col): string { return "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', ''), 10)"; }
/** every 10-digit number inside a field that may hold more than one ("98765 43210, 91234 56789"), or in a list of such fields */
function ohub_keys($raw): array
{
    $out = []; foreach ((array)$raw as $one) foreach (preg_split('/[,;\/|\n\r]+/', (string)$one) ?: [] as $part) { $k = ohub_p10($part); if ($k !== '') $out[$k] = true; }
    return array_map('strval', array_keys($out));
}
function ohub_num($v): float { return round((float)$v, 2); }

function ohub_ensure(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_facts (
            p10 CHAR(10) NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', names VARCHAR(400) NOT NULL DEFAULT '',
            online_n INT NOT NULL DEFAULT 0, online_amt DECIMAL(14,2) NOT NULL DEFAULT 0, store_n INT NOT NULL DEFAULT 0, store_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
            pos_n INT NOT NULL DEFAULT 0, pos_amt DECIMAL(14,2) NOT NULL DEFAULT 0, orders_n INT NOT NULL DEFAULT 0, spent DECIMAL(14,2) NOT NULL DEFAULT 0,
            first_at DATETIME NULL, last_at DATETIME NULL, rto_n INT NOT NULL DEFAULT 0, cx_n INT NOT NULL DEFAULT 0, cx_open INT NOT NULL DEFAULT 0,
            channel VARCHAR(8) NOT NULL DEFAULT '', refreshed_at DATETIME NULL,
            KEY idx_cf_spent (spent), KEY idx_cf_n (orders_n), KEY idx_cf_last (last_at), KEY idx_cf_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { error_log('customer_facts: ' . $e->getMessage()); return $ok = false; }
}

/** "not cancelled" filter for shop_orders (alias s). Empty when the cancel table is not there. */
function ohub_notcancel(PDO $pdo): string
{
    static $s = null; if ($s !== null) return $s;
    try { $pdo->query("SELECT order_id, source FROM order_cancels LIMIT 0"); return $s = " AND NOT EXISTS (SELECT 1 FROM order_cancels oc WHERE oc.order_id = CAST(s.shopify_id AS CHAR))"; } catch (Throwable $e) { return $s = ''; }
}

/* ============================================================== bulk: every customer at once ============================================================== */
/** [p10 => ['name','names','online_n',...]] from the real tables. Each source is read on its own; one failing leaves its columns empty. */
function ohub_bulk(PDO $pdo, $only = null): array
{
    if ($only !== null) { $only = ohub_keys($only); if (!$only) return []; }   // null = everybody; else the list of numbers
    $C = []; $new = function () { return ['names' => [], 'online_n' => 0, 'online_amt' => 0.0, 'store_n' => 0, 'store_amt' => 0.0, 'pos_n' => 0, 'pos_amt' => 0.0, 'first' => null, 'last' => null, 'rto_n' => 0, 'cx_n' => 0, 'cx_open' => 0]; };
    $touch = function (&$c, $first, $last) { if ($first && (!$c['first'] || $first < $c['first'])) $c['first'] = $first; if ($last && (!$c['last'] || $last > $c['last'])) $c['last'] = $last; };
    $addName = function (&$c, $n) { $n = trim((string)$n); if ($n !== '' && !in_array($n, $c['names'], true) && count($c['names']) < 6) $c['names'][] = $n; };
    $run = function (string $sql, string $col, callable $each) use ($pdo, $only) {
        try { $st = $pdo->prepare(str_replace('{W}', $only === null ? " AND LENGTH(" . ohub_sqlp($col) . ") = 10" : " AND " . ohub_sqlp($col) . " IN (" . implode(',', array_fill(0, count($only), '?')) . ")", $sql)); $st->execute($only === null ? [] : $only); foreach ($st->fetchAll() as $r) $each($r); }
        catch (Throwable $e) { error_log('ohub_bulk: ' . $e->getMessage()); }
    };
    // 1. online + manual orders (Shopify cache), without the cancelled ones
    $nc = ohub_notcancel($pdo);
    $run("SELECT " . ohub_sqlp('s.phone') . " ph, MAX(s.customer) nm, COUNT(*) n, COALESCE(SUM(s.total),0) amt, MIN(s.created_at) f, MAX(s.created_at) l FROM shop_orders s WHERE 1=1$nc{W} GROUP BY ph", 's.phone', function ($r) use (&$C, $new, $touch, $addName) {
        $p = (string)$r['ph']; if (!isset($C[$p])) $C[$p] = $new(); $C[$p]['online_n'] = (int)$r['n']; $C[$p]['online_amt'] = ohub_num($r['amt']); $addName($C[$p], $r['nm']); $touch($C[$p], $r['f'], $r['l']); });
    // 2. store bills (new POS): the bill's own number, else the people saved on the bill
    $run("SELECT " . ohub_sqlp('b.customer_phone') . " ph, MAX(b.customer_name) nm, COUNT(*) n, COALESCE(SUM(b.total),0) amt, MIN(b.created_at) f, MAX(b.created_at) l FROM st_bills b WHERE b.status = 'ok'{W} GROUP BY ph", 'b.customer_phone', function ($r) use (&$C, $new, $touch, $addName) {
        $p = (string)$r['ph']; if (!isset($C[$p])) $C[$p] = $new(); $C[$p]['store_n'] += (int)$r['n']; $C[$p]['store_amt'] += ohub_num($r['amt']); $addName($C[$p], $r['nm']); $touch($C[$p], $r['f'], $r['l']); });
    $run("SELECT " . ohub_sqlp('p.phone') . " ph, MAX(p.name) nm, COUNT(*) n, COALESCE(SUM(b.total),0) amt, MIN(b.created_at) f, MAX(b.created_at) l FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id
        WHERE b.status = 'ok' AND TRIM(b.customer_phone) = '' AND p.declined = 0 AND p.person_id = (SELECT MIN(p2.person_id) FROM st_bill_people p2 WHERE p2.bill_id = b.bill_id AND p2.phone <> ''){W} GROUP BY ph", 'p.phone', function ($r) use (&$C, $new, $touch, $addName) {
        $p = (string)$r['ph']; if (!isset($C[$p])) $C[$p] = $new(); $C[$p]['store_n'] += (int)$r['n']; $C[$p]['store_amt'] += ohub_num($r['amt']); $addName($C[$p], $r['nm']); $touch($C[$p], $r['f'], $r['l']); });
    // 3. the old POS
    $run("SELECT " . ohub_sqlp('c.phone') . " ph, MAX(c.name) nm, COUNT(o.id) n, COALESCE(SUM(o.total),0) amt, MIN(o.created_at) f, MAX(o.created_at) l FROM pos_customers c JOIN pos_orders o ON o.customer_id = c.id WHERE 1=1{W} GROUP BY ph", 'c.phone', function ($r) use (&$C, $new, $touch, $addName) {
        $p = (string)$r['ph']; if (!isset($C[$p])) $C[$p] = $new(); $C[$p]['pos_n'] = (int)$r['n']; $C[$p]['pos_amt'] = ohub_num($r['amt']); $addName($C[$p], $r['nm']); $touch($C[$p], $r['f'], $r['l']); });
    // 4. parcels that came back (RTO)
    $run("SELECT " . ohub_sqlp('d.phone') . " ph, COUNT(*) n FROM dp_dsp_parcels d WHERE d.status = 'rto'{W} GROUP BY ph", 'd.phone', function ($r) use (&$C, $new) { $p = (string)$r['ph']; if (!isset($C[$p])) $C[$p] = $new(); $C[$p]['rto_n'] = (int)$r['n']; });
    // 5. complaints
    $run("SELECT " . ohub_sqlp('x.phone') . " ph, MAX(x.cust_name) nm, COUNT(*) n, SUM(x.status IN ('open','reopened')) o FROM cx_cases x WHERE 1=1{W} GROUP BY ph", 'x.phone', function ($r) use (&$C, $new, $addName) {
        $p = (string)$r['ph']; if (!isset($C[$p])) $C[$p] = $new(); $C[$p]['cx_n'] = (int)$r['n']; $C[$p]['cx_open'] = (int)$r['o']; $addName($C[$p], $r['nm']); });
    return $C;
}
function ohub_row(string $p, array $c): array
{
    $n = $c['online_n'] + $c['store_n'] + $c['pos_n']; $amt = $c['online_amt'] + $c['store_amt'] + $c['pos_amt']; $ch = ($c['online_n'] > 0 ? 1 : 0) + (($c['store_n'] + $c['pos_n']) > 0 ? 2 : 0);
    return ['p10' => $p, 'name' => mb_substr((string)($c['names'][0] ?? ''), 0, 120), 'names' => mb_substr(implode(' | ', $c['names']), 0, 400), 'online_n' => $c['online_n'], 'online_amt' => $c['online_amt'], 'store_n' => $c['store_n'], 'store_amt' => $c['store_amt'],
        'pos_n' => $c['pos_n'], 'pos_amt' => $c['pos_amt'], 'orders_n' => $n, 'spent' => round($amt, 2), 'first_at' => $c['first'], 'last_at' => $c['last'], 'rto_n' => $c['rto_n'], 'cx_n' => $c['cx_n'], 'cx_open' => $c['cx_open'],
        'channel' => $ch === 3 ? 'both' : ($ch === 2 ? 'store' : ($ch === 1 ? 'online' : ''))];
}
function ohub_save(PDO $pdo, array $rows): int
{
    if (!$rows) return 0; $now = date('Y-m-d H:i:s'); $n = 0;
    $cols = ['p10', 'name', 'names', 'online_n', 'online_amt', 'store_n', 'store_amt', 'pos_n', 'pos_amt', 'orders_n', 'spent', 'first_at', 'last_at', 'rto_n', 'cx_n', 'cx_open', 'channel', 'refreshed_at'];
    $upd = implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, array_slice($cols, 1)));
    foreach (array_chunk($rows, 150) as $chunk) {
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')'; $par = [];
        foreach ($chunk as $r) { foreach (array_slice($cols, 0, -1) as $c) $par[] = $r[$c]; $par[] = $now; }
        try { $pdo->prepare("INSERT INTO customer_facts (" . implode(',', $cols) . ") VALUES " . implode(',', array_fill(0, count($chunk), $ph)) . " ON DUPLICATE KEY UPDATE $upd")->execute($par); $n += count($chunk); }
        catch (Throwable $e) { error_log('ohub_save: ' . $e->getMessage()); }
    }
    return $n;
}
/** the whole Customers list, rebuilt. Returns the number of people. */
function ohub_rebuild(PDO $pdo): int
{
    if (!ohub_ensure($pdo)) return 0; @set_time_limit(120);
    $C = ohub_bulk($pdo); $rows = []; foreach ($C as $p => $c) $rows[] = ohub_row((string)$p, $c);
    return ohub_save($pdo, $rows);
}
/** background: once every 6 hours */
function ohub_tick(PDO $pdo, int $now): array
{
    $out = ['n' => 0, 'ran' => false, 'error' => ''];
    if (!function_exists('dp_get') || $now - (int)dp_get('ohub_last_ts', '0') < 6 * 3600) return $out;
    dp_put('ohub_last_ts', (string)$now); $out['ran'] = true;
    try { $out['n'] = ohub_rebuild($pdo); } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
    return $out;
}

/* ============================================================== one customer ============================================================== */
/** every order this person ever made, newest first: [['src','name','at','total','status','store','sid','bill','parcel']] */
function ohub_orders(PDO $pdo, $phones, int $limit = 60): array
{
    $out = []; $ph = ohub_keys($phones); if (!$ph) return []; $in = implode(',', array_fill(0, count($ph), '?'));
    try {
        $st = $pdo->prepare("SELECT s.store_key, s.shopify_id, s.order_name, s.created_at, s.total, s.financial_status, s.fulfillment_status, s.customer" . (ohub_notcancel($pdo) !== '' ? ", EXISTS (SELECT 1 FROM order_cancels oc WHERE oc.order_id = CAST(s.shopify_id AS CHAR)) AS cancelled" : ", 0 AS cancelled") . "
            FROM shop_orders s WHERE " . ohub_sqlp('s.phone') . " IN ($in) ORDER BY s.created_at DESC LIMIT " . (int)$limit);
        $st->execute($ph);
        foreach ($st->fetchAll() as $r) $out[] = ['src' => preg_match('/^WM-\d+$/i', (string)$r['order_name']) ? 'manual' : 'online', 'name' => (string)$r['order_name'], 'at' => (string)$r['created_at'], 'total' => ohub_num($r['total']),
            'status' => (int)$r['cancelled'] ? 'cancelled' : ((string)$r['fulfillment_status'] ?: 'unfulfilled'), 'pay' => (string)$r['financial_status'], 'store' => (string)$r['store_key'], 'sid' => (string)$r['shopify_id'], 'who' => (string)$r['customer']];
    } catch (Throwable $e) { }
    try {
        $st = $pdo->prepare("SELECT b.bill_id, b.bill_no, b.created_at, b.total, b.pay_mode, b.emp_name, b.total_ml FROM st_bills b WHERE b.status = 'ok' AND (" . ohub_sqlp('b.customer_phone') . " IN ($in)
            OR (TRIM(b.customer_phone) = '' AND EXISTS (SELECT 1 FROM st_bill_people p WHERE p.bill_id = b.bill_id AND p.declined = 0 AND " . ohub_sqlp('p.phone') . " IN ($in)))) ORDER BY b.created_at DESC LIMIT " . (int)$limit);
        $st->execute(array_merge($ph, $ph));
        foreach ($st->fetchAll() as $r) $out[] = ['src' => 'store', 'name' => (string)($r['bill_no'] ?: ('Bill ' . $r['bill_id'])), 'at' => (string)$r['created_at'], 'total' => ohub_num($r['total']), 'status' => 'billed', 'pay' => (string)$r['pay_mode'], 'bill' => (int)$r['bill_id'], 'who' => (string)$r['emp_name'], 'ml' => (int)$r['total_ml']];
    } catch (Throwable $e) { }
    try {
        $st = $pdo->prepare("SELECT o.id, o.created_at, o.total FROM pos_orders o JOIN pos_customers c ON c.id = o.customer_id WHERE " . ohub_sqlp('c.phone') . " IN ($in) ORDER BY o.created_at DESC LIMIT " . (int)$limit);
        $st->execute($ph);
        foreach ($st->fetchAll() as $r) $out[] = ['src' => 'pos', 'name' => 'POS #' . $r['id'], 'at' => (string)$r['created_at'], 'total' => ohub_num($r['total']), 'status' => 'billed', 'pay' => '', 'who' => ''];
    } catch (Throwable $e) { }
    usort($out, function ($a, $b) { return strcmp((string)$b['at'], (string)$a['at']); });
    return array_slice($out, 0, $limit);
}
/** Risk and value flags: [[kind ok|warn|bad|info, text]] */
function ohub_flags(array $c, ?int $now = null): array
{
    $now = $now ?: time(); $f = [];
    if ((int)$c['rto_n'] >= 2) $f[] = ['bad', $c['rto_n'] . ' parcels came back (RTO)']; elseif ((int)$c['rto_n'] === 1) $f[] = ['warn', '1 parcel came back (RTO)'];
    if ((int)$c['cx_open'] > 0) $f[] = ['bad', $c['cx_open'] . ' complaint' . ($c['cx_open'] > 1 ? 's' : '') . ' open now'];
    if ((int)$c['cx_n'] >= 2) $f[] = ['warn', 'Complained ' . $c['cx_n'] . ' times']; elseif ((int)$c['cx_n'] === 1 && (int)$c['cx_open'] === 0) $f[] = ['info', '1 earlier complaint'];
    if ((float)$c['spent'] >= 20000 || (int)$c['orders_n'] >= 5) $f[] = ['ok', 'High-value customer'];
    if ((int)$c['orders_n'] === 1) $f[] = ['info', 'First order'];
    elseif ((int)$c['orders_n'] >= 2) $f[] = ['ok', 'Returning customer'];
    $last = !empty($c['last_at']) ? strtotime((string)$c['last_at']) : 0;
    if ($last && $now - $last > 120 * 86400 && (int)$c['orders_n'] >= 2) $f[] = ['warn', 'Has not ordered for ' . (int)floor(($now - $last) / 86400) . ' days'];
    return $f;
}
/** the customer card for one phone (reads the live tables, saves the copy) */
function ohub_customer(PDO $pdo, $phones, string $nameHint = ''): array
{
    ohub_ensure($pdo); $now = time(); $ph = ohub_keys($phones); $p10 = $ph ? $ph[0] : '';   // the first number is the main one; the others are the same person's other numbers
    $bulk = ohub_bulk($pdo, $ph); $c = ['names' => [], 'online_n' => 0, 'online_amt' => 0.0, 'store_n' => 0, 'store_amt' => 0.0, 'pos_n' => 0, 'pos_amt' => 0.0, 'first' => null, 'last' => null, 'rto_n' => 0, 'cx_n' => 0, 'cx_open' => 0];
    foreach ($ph as $k) {
        if (empty($bulk[$k])) continue; $x = $bulk[$k];
        foreach (['online_n', 'online_amt', 'store_n', 'store_amt', 'pos_n', 'pos_amt', 'rto_n', 'cx_n', 'cx_open'] as $f) $c[$f] += $x[$f];
        foreach ($x['names'] as $n) if (!in_array($n, $c['names'], true) && count($c['names']) < 6) $c['names'][] = $n;
        if ($x['first'] && (!$c['first'] || $x['first'] < $c['first'])) $c['first'] = $x['first']; if ($x['last'] && (!$c['last'] || $x['last'] > $c['last'])) $c['last'] = $x['last'];
    }
    if ($nameHint !== '' && !in_array($nameHint, $c['names'], true)) array_unshift($c['names'], $nameHint);
    $rows = []; foreach ($ph as $k) if (!empty($bulk[$k])) { $r1 = ohub_row($k, $bulk[$k]); if ($r1['orders_n'] > 0 || $r1['cx_n'] > 0) $rows[] = $r1; } if ($rows) ohub_save($pdo, $rows);
    $row = ohub_row($p10, $c);
    $row['aov'] = $row['orders_n'] ? round($row['spent'] / $row['orders_n']) : 0;
    $row['days_since'] = $row['last_at'] ? (int)floor(($now - strtotime((string)$row['last_at'])) / 86400) : null;
    $row['names_list'] = $c['names']; $row['flags'] = ohub_flags($row, $now); $row['phone'] = $p10; $row['phones'] = $ph;
    $hist = null; try { $hist = $pdo->query("SELECT MIN(created_at) FROM shop_orders")->fetchColumn(); } catch (Throwable $e) { } $row['online_since'] = $hist ? substr((string)$hist, 0, 10) : null;
    return $row;
}
/** who is waiting: the customer wrote and nobody answered, plus the open Needs-attention case and open complaint */
function ohub_wait(PDO $pdo, $phones): array
{
    $w = ['chat' => null, 'case' => null, 'cx' => null]; $now = time(); $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $ph = ohub_keys($phones); if (!$ph) return $w; $in = implode(',', array_fill(0, count($ph), '?')); $in91 = array_merge($ph, array_map(function ($x) { return '91' . $x; }, $ph));
    try {
        $q = $pdo->prepare("SELECT name, assigned_emp, last_in_at, last_out_at, state FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($in91), '?')) . ") ORDER BY last_in_at DESC LIMIT 1"); $q->execute($in91); $r = $q->fetch();
        if ($r) { $inT = $r['last_in_at'] ? strtotime((string)$r['last_in_at']) : 0; $out = $r['last_out_at'] ? strtotime((string)$r['last_out_at']) : 0;
            $w['chat'] = ['holder' => (int)$r['assigned_emp'] ? ($nm[(int)$r['assigned_emp']] ?? '') : '', 'last_in' => $r['last_in_at'], 'last_out' => $r['last_out_at'], 'waiting' => $inT > 0 && $inT > $out, 'mins' => $inT > 0 && $inT > $out ? (int)floor(($now - $inT) / 60) : 0]; }
    } catch (Throwable $e) { }
    try {
        $q = $pdo->prepare("SELECT id, reason, created_at, owner_name FROM wa_ord_cases WHERE status = 'open' AND RIGHT(phone, 10) IN ($in) ORDER BY created_at ASC LIMIT 1"); $q->execute($ph); $r = $q->fetch();
        if ($r) $w['case'] = ['id' => (int)$r['id'], 'reason' => (string)$r['reason'], 'since' => (string)$r['created_at'], 'mins' => (int)floor(($now - strtotime((string)$r['created_at'])) / 60), 'owner' => (string)$r['owner_name']];
    } catch (Throwable $e) { }
    try {
        $q = $pdo->prepare("SELECT id, raised_at, owner_emp FROM cx_cases WHERE status IN ('open','reopened') AND RIGHT(phone, 10) IN ($in) ORDER BY raised_at ASC LIMIT 1"); $q->execute($ph); $r = $q->fetch();
        if ($r) $w['cx'] = ['id' => (int)$r['id'], 'code' => 'C-' . str_pad((string)(int)$r['id'], 4, '0', STR_PAD_LEFT), 'since' => (string)$r['raised_at'], 'mins' => (int)floor(($now - strtotime((string)$r['raised_at'])) / 60), 'owner' => (int)$r['owner_emp'] ? ($nm[(int)$r['owner_emp']] ?? '') : ''];
    } catch (Throwable $e) { }
    return $w;
}

/* ============================================================== one order ============================================================== */
/** the parcel row Dispatch keeps (dp_dsp_parcels), else a stand-in made from the order_facts copy so the old orders open too */
function ohub_parcel(PDO $pdo, array $g): ?array
{
    $pid = (int)($g['id'] ?? 0);
    if ($pid > 0) { $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $q->execute([$pid]); $r = $q->fetch(); if ($r) return $r; }
    $order = trim((string)($g['order'] ?? '')); $store = trim((string)($g['store'] ?? '')); $sid = trim((string)($g['sid'] ?? ''));
    if ($order !== '' || $sid !== '') {
        $on = strtolower(ltrim($order, '#'));
        $sql = "SELECT * FROM dp_dsp_parcels WHERE " . ($sid !== '' ? "shopify_id = ?" : "LOWER(TRIM(LEADING '#' FROM order_name)) = ?") . ($store !== '' ? " AND store_key = ?" : "") . " ORDER BY id DESC LIMIT 1";
        $q = $pdo->prepare($sql); $q->execute(array_merge([$sid !== '' ? $sid : $on], $store !== '' ? [$store] : [])); $r = $q->fetch(); if ($r) return $r;
        if (function_exists('of_get')) {   // an older order: build a stand-in from the order_facts copy
            $f = of_get($pdo, $sid !== '' ? $sid : $order);
            if ($f) return ['id' => 0, 'store_key' => (string)$f['store_key'], 'shopify_id' => (string)$f['shopify_id'], 'order_name' => (string)$f['order_name'], 'created_at' => (string)$f['created_at'], 'customer' => (string)$f['customer'], 'phone' => (string)$f['phone'],
                'tracking_no' => (string)$f['awb'], 'courier' => (string)$f['courier'], 'status' => (string)($f['ship_status'] ?: 'placed'), 'tracked_at' => null, 'tracked_by' => '', 'transit_at' => null, 'delivered_at' => $f['delivered_at'], 'remit_at' => $f['cod_remit_at'],
                'sr_status' => (string)$f['sr_status'], 'sr_at' => $f['sr_at'], 'etd' => $f['etd'], 'live_at' => null, 'live_src' => '', 'track_link' => '', 'manual' => 0, 'standin' => 1];
        }
    }
    return null;
}
function ohub_fdt(?string $s): string { return $s ? substr($s, 0, 16) : ''; }

function ohub_timeline(PDO $pdo, ?array $p, ?array $card, array $events, string $p10, string $orderName): array
{
    $T = []; $add = function ($at, $kind, $title, $text = '', $tone = '') use (&$T) { $at = ohub_fdt((string)$at); if ($at === '') return; $T[] = ['at' => $at, 'kind' => $kind, 'title' => $title, 'text' => $text, 'tone' => $tone]; };
    if ($p) {
        $t = $card['times'] ?? [];
        $add($p['created_at'], 'order', 'Order placed', ($card && $card['sp'] ? 'Taken by ' . $card['sp'] : ''));
        if ($card && !empty($card['wm'])) { $add($card['wm']['made'], 'order', 'Manual order made', $card['wm']['by'] ? 'by ' . $card['wm']['by'] : ''); $add($card['wm']['confirmed'], 'pay', 'Customer confirmed the order'); }
        if ($card && !empty($card['pay']['paid_on'])) $add($card['pay']['paid_on'] . ' 00:00', 'pay', 'Money received in Finance', $card['pay']['paid_amt'] !== null ? '₹' . number_format((float)$card['pay']['paid_amt'], 2) : '', 'ok');
        if ($card && !empty($card['pay']['tx'])) foreach ($card['pay']['tx'] as $x) $add($x['at'] ?? '', 'pay', trim(($x['gateway'] ?? '') . ' ' . ($x['kind'] ?? '') . ' ' . ($x['status'] ?? '')), ($x['amount'] ?? '') !== '' ? '₹' . $x['amount'] : '', (($x['status'] ?? '') === 'success') ? 'ok' : '');
        if ($card && !empty($card['com'])) $add($card['com']['day'] ? $card['com']['day'] . ' 23:59' : '', 'com', 'Commission counted', '₹' . number_format((float)$card['com']['amt'], 2) . ($card['com']['pct'] ? ' · ' . $card['com']['pct'] . '%' : ''), 'ok');
        $add($p['tracked_at'] ?? '', 'ship', 'Tracking ID given', trim(($p['courier'] ?? '') . ' ' . ($p['tracking_no'] ?? '')) . (!empty($p['tracked_by']) ? ' · by ' . $p['tracked_by'] : ''));
        $add($p['transit_at'] ?? '', 'ship', 'Dispatched / in transit');
        foreach ($events as $e) $add($e['at'], 'scan', $e['activity'] !== '' ? $e['activity'] : ($e['label'] ?: 'Courier update'), trim($e['location'] . ($e['label'] !== '' && $e['activity'] !== '' ? ' · ' . $e['label'] : '')));
        $add($p['delivered_at'] ?? '', 'ship', 'Delivered', '', 'ok');
        if ($p['status'] === 'rto') $add($p['sr_at'] ?? ($p['live_at'] ?? ''), 'ship', 'Returning to sender (RTO)', '', 'bad');
        $add($p['remit_at'] ?? '', 'pay', 'COD money came back from the courier', '', 'ok');
    }
    foreach (($card['cx'] ?? []) as $x) { $add($x['raised'], 'cx', 'Complaint ' . $x['code'] . ' raised', $x['label'], 'bad'); }
    try {
        $on = strtolower(ltrim($orderName, '#')); $par = [$p10]; $w = "phone = ?"; if ($on !== '') { $w .= " OR LOWER(TRIM(LEADING '#' FROM order_name)) = ?"; $par[] = $on; }
        $q = $pdo->prepare("SELECT id, reason, created_at, owner_name, taken_at, done_at, done_note, status FROM wa_ord_cases WHERE $w ORDER BY id DESC LIMIT 6"); $q->execute($par); $R = function_exists('wo_reasons') ? wo_reasons() : [];
        foreach ($q->fetchAll() as $r) { $lbl = $R[$r['reason']] ?? $r['reason']; $add($r['created_at'], 'case', 'Needs attention: ' . $lbl, '', 'bad'); if ($r['taken_at']) $add($r['taken_at'], 'case', 'Taken by ' . ($r['owner_name'] ?: 'someone')); if ($r['done_at']) $add($r['done_at'], 'case', 'Case finished', (string)$r['done_note'], 'ok'); }
    } catch (Throwable $e) { }
    usort($T, function ($a, $b) { return strcmp($a['at'], $b['at']); });
    return $T;
}

/** everything for the popup */
function ohub_view(PDO $pdo, array $g): array
{
    $out = ['ok' => true, 'order' => null, 'parcel' => null, 'events' => [], 'timeline' => [], 'customer' => null, 'orders' => [], 'wait' => null, 'case' => null, 'phone' => '', 'order_name' => ''];
    $case = null;
    if (!empty($g['case'])) {
        try { $q = $pdo->prepare("SELECT * FROM wa_ord_cases WHERE id = ?"); $q->execute([(int)$g['case']]); $case = $q->fetch() ?: null; } catch (Throwable $e) { }
        if ($case) { if ((int)$case['parcel_id'] > 0 && empty($g['id'])) $g['id'] = (int)$case['parcel_id']; if (empty($g['order']) && (string)$case['order_name'] !== '') { $g['order'] = (string)$case['order_name']; $g['store'] = (string)$case['store_key']; } if (empty($g['phone'])) $g['phone'] = (string)$case['phone']; }
    }
    if (!empty($g['bill'])) {   // a store bill: its own small card; the customer is read from the bill
        try { $q = $pdo->prepare("SELECT * FROM st_bills WHERE bill_id = ?"); $q->execute([(int)$g['bill']]); $b = $q->fetch();
            if ($b) { $out['bill'] = ['bill_no' => (string)$b['bill_no'], 'at' => ohub_fdt($b['created_at']), 'by' => (string)$b['emp_name'], 'total' => ohub_num($b['total']), 'list' => ohub_num($b['list_price']), 'discount' => ohub_num($b['discount']), 'pay' => (string)$b['pay_mode'], 'cash' => ohub_num($b['cash_amt']), 'online' => ohub_num($b['online_amt']), 'ml' => (int)$b['total_ml'], 'bottles' => (int)$b['bottles'], 'code' => (string)$b['order_code'], 'name' => (string)$b['customer_name']];
                if (empty($g['phone'])) $g['phone'] = (string)$b['customer_phone']; $out['order_name'] = (string)$b['bill_no']; } } catch (Throwable $e) { }
    }
    $p = empty($g['bill']) ? ohub_parcel($pdo, $g) : null;
    if (!$p && empty($g['bill']) && empty($g['phone'])) return ['ok' => false, 'error' => 'No order or customer found for this.'];
    if (!$p && empty($g['bill']) && !empty($g['phone']) && empty($g['order'])) {   // only a phone: show the latest parcel of this customer, if any
        $p10 = ohub_p10($g['phone']); if ($p10 !== '') { try { $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE " . ohub_sqlp('phone') . " = ? ORDER BY created_at DESC LIMIT 1"); $q->execute([$p10]); $p = $q->fetch() ?: null; } catch (Throwable $e) { } }
    }
    // WHO is this customer: every number we know for this order (the order's own number(s), the chat number, other numbers saved on the order or linked by hand)
    $chatPhone = ohub_p10($g['phone'] ?? ''); $nums = [];
    if ($p) {
        foreach (ohub_keys($p['phone'] ?? '') as $k) $nums[] = $k;
        try { $q = $pdo->prepare("SELECT phone FROM order_extra_phones WHERE order_id = ? OR (order_name <> '' AND LOWER(TRIM(LEADING '#' FROM order_name)) = ?)"); $q->execute([(string)$p['shopify_id'], strtolower(ltrim((string)$p['order_name'], '#'))]); foreach ($q->fetchAll() as $x) foreach (ohub_keys($x['phone']) as $k) $nums[] = $k; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone FROM wa_ord_links WHERE shopify_id = ? OR LOWER(TRIM(LEADING '#' FROM order_name)) = ?"); $q->execute([(string)$p['shopify_id'], strtolower(ltrim((string)$p['order_name'], '#'))]); foreach ($q->fetchAll() as $x) foreach (ohub_keys($x['phone']) as $k) $nums[] = $k; } catch (Throwable $e) { }
        if (function_exists('of_get')) { try { $f = of_get($pdo, (string)$p['order_name']); if ($f) foreach (ohub_keys($f['phone'] ?? '') as $k) $nums[] = $k; } catch (Throwable $e) { } }
    }
    if ($chatPhone !== '') $nums[] = $chatPhone;
    $nums = array_values(array_unique($nums));
    $phone = $p && ohub_keys($p['phone'] ?? '') ? ohub_keys($p['phone'])[0] : ($nums ? $nums[0] : '');   // main number = the order's own number
    if ($phone !== '' && ($k = array_search($phone, $nums, true)) !== false && $k > 0) { unset($nums[$k]); array_unshift($nums, $phone); $nums = array_values($nums); }
    $out['chat_phone'] = $chatPhone !== '' ? $chatPhone : $phone; $out['phones'] = $nums;
    $card = null; $events = [];
    if ($p) {
        try { $card = function_exists('dp_dsp_card') ? dp_dsp_card($pdo, $p) : null; } catch (Throwable $e) { error_log('ohub card: ' . $e->getMessage()); }
        if ((int)$p['id'] > 0) { try { $q = $pdo->prepare("SELECT at, label, activity, location FROM dp_dsp_events WHERE parcel_id = ? ORDER BY at ASC, id ASC LIMIT 200"); $q->execute([(int)$p['id']]); $events = $q->fetchAll(); } catch (Throwable $e) { } }
        $out['order_name'] = (string)$p['order_name'];
        $out['parcel'] = ['id' => (int)$p['id'], 'order' => (string)$p['order_name'], 'store' => (string)$p['store_key'], 'sid' => (string)$p['shopify_id'], 'customer' => (string)($p['customer'] ?? ''), 'courier' => (string)($p['courier'] ?? ''), 'tracking_no' => (string)($p['tracking_no'] ?? ''),
            'link' => (string)($p['track_link'] ?? ''), 'status' => (string)$p['status'], 'sr_status' => (string)($p['sr_status'] ?? ''), 'etd' => !empty($p['etd']) ? (string)$p['etd'] : '', 'created' => ohub_fdt($p['created_at']), 'tracked_at' => ohub_fdt($p['tracked_at'] ?? ''), 'transit_at' => ohub_fdt($p['transit_at'] ?? ''),
            'delivered_at' => ohub_fdt($p['delivered_at'] ?? ''), 'live_at' => ohub_fdt($p['live_at'] ?? ''), 'standin' => !empty($p['standin'])];
        if (empty($out['parcel']['link']) && $out['parcel']['tracking_no'] !== '' && function_exists('dp_dsp_courier_map') && function_exists('dp_dsp_link_make')) { try { $cm = dp_dsp_courier_map($pdo); $out['parcel']['link'] = dp_dsp_link_make($cm[strtolower((string)($p['courier'] ?? ''))] ?? '', $p['tracking_no']); } catch (Throwable $e) { } }
    }
    $out['order'] = $card; $out['events'] = $events;
    $out['phone'] = $phone; $out['case'] = $case ? ['id' => (int)$case['id'], 'reason' => (string)$case['reason'], 'status' => (string)$case['status'], 'note' => (string)$case['note'], 'owner' => (string)$case['owner_name'], 'created_at' => (string)$case['created_at']] : null;
    $out['timeline'] = ohub_timeline($pdo, $p, $card, $events, $phone, (string)$out['order_name']);
    if ($nums) {
        $hint = $p ? (string)($p['customer'] ?? '') : ($case ? (string)$case['cust_name'] : '');
        $out['customer'] = ohub_customer($pdo, $nums, $hint); $out['orders'] = ohub_orders($pdo, $nums); $out['wait'] = ohub_wait($pdo, $nums);
        if ($p && (string)$p['order_name'] !== '') {   // the order on screen is always in the list, even when its number is saved somewhere we do not search
            $has = false; foreach ($out['orders'] as $o1) if (strtolower(ltrim($o1['name'], '#')) === strtolower(ltrim((string)$p['order_name'], '#'))) { $has = true; break; }
            if (!$has) { $tot = $card && isset($card['pay']['total']) ? ohub_num($card['pay']['total']) : 0.0; array_unshift($out['orders'], ['src' => preg_match('/^WM-\d+$/i', (string)$p['order_name']) ? 'manual' : 'online', 'name' => (string)$p['order_name'], 'at' => (string)$p['created_at'], 'total' => $tot, 'status' => (string)$p['status'], 'pay' => '', 'store' => (string)$p['store_key'], 'sid' => (string)$p['shopify_id'], 'who' => (string)($p['customer'] ?? '')]);
                if ((int)$out['customer']['orders_n'] < 1) { $c0 = &$out['customer']; $c0['orders_n'] = 1; $c0['online_n'] = max(1, (int)$c0['online_n']); $c0['online_amt'] = max($tot, (float)$c0['online_amt']); $c0['spent'] = max($tot, (float)$c0['spent']); $c0['aov'] = (int)round($c0['spent']); $c0['first_at'] = $c0['last_at'] = (string)$p['created_at']; $c0['days_since'] = (int)floor((time() - strtotime((string)$p['created_at'])) / 86400); $c0['flags'] = ohub_flags($c0); unset($c0); } }
        }
    }
    return $out;
}

/** Customers list: one row per person, from customer_facts (built in the background; the first open builds it) */
function ohub_list(PDO $pdo, array $g): array
{
    ohub_ensure($pdo);
    if (!empty($g['totals'])) {   // only the numbers (the strip at the top of the Dispatch page)
        $n = (int)$pdo->query("SELECT COUNT(*) FROM customer_facts")->fetchColumn(); if ($n === 0) ohub_rebuild($pdo);
        $t = $pdo->query("SELECT COUNT(*) a, SUM(orders_n >= 2) rep, SUM(channel = 'both') b, SUM(rto_n >= 1 OR cx_n >= 2 OR cx_open > 0) risk, SUM(first_at >= (NOW() - INTERVAL 30 DAY)) new30, SUM(spent) spent, MAX(refreshed_at) at FROM customer_facts")->fetch();
        return ['ok' => true, 'people' => (int)$t['a'], 'repeat' => (int)$t['rep'], 'both' => (int)$t['b'], 'risk' => (int)$t['risk'], 'new30' => (int)$t['new30'], 'spent' => (float)$t['spent'], 'at' => (string)$t['at'], 'sync' => ohub_sync_health()];
    }
    $n = (int)$pdo->query("SELECT COUNT(*) FROM customer_facts")->fetchColumn(); $built = $n > 0;
    if (!$built) { ohub_rebuild($pdo); }
    $w = ['1=1']; $par = [];
    $q = trim((string)($g['q'] ?? ''));
    if ($q !== '') { $d = preg_replace('/\D+/', '', $q) ?? ''; if (strlen($d) >= 4) { $w[] = "p10 LIKE ?"; $par[] = '%' . substr($d, -10) . '%'; } else { $w[] = "(name LIKE ? OR names LIKE ?)"; $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; $par[] = $l; $par[] = $l; } }
    $flt = (string)($g['flt'] ?? 'all');
    $map = ['vip' => "(spent >= 20000 OR orders_n >= 5)", 'risk' => "(rto_n >= 1 OR cx_n >= 2 OR cx_open > 0)", 'store' => "channel IN ('store','both')", 'online' => "channel IN ('online','both')", 'both' => "channel = 'both'", 'lapsed' => "(orders_n >= 2 AND last_at < (NOW() - INTERVAL 120 DAY))", 'repeat' => "orders_n >= 2", 'new' => "orders_n = 1"];
    if (isset($map[$flt])) $w[] = $map[$flt];
    $sorts = ['spent' => 'spent DESC', 'orders' => 'orders_n DESC, spent DESC', 'last' => 'last_at DESC', 'first' => 'first_at DESC', 'name' => 'name ASC', 'rto' => 'rto_n DESC, cx_n DESC'];
    $ord = $sorts[(string)($g['sort'] ?? 'last')] ?? $sorts['last']; $per = 50; $page = max(1, (int)($g['page'] ?? 1));
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(spent),0) s FROM customer_facts WHERE $where"); $st->execute($par); $t = $st->fetch();
    $st = $pdo->prepare("SELECT * FROM customer_facts WHERE $where ORDER BY $ord LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($par); $rows = [];
    foreach ($st->fetchAll() as $r) { $r['flags'] = ohub_flags($r); $r['aov'] = (int)$r['orders_n'] ? round((float)$r['spent'] / (int)$r['orders_n']) : 0; $rows[] = $r; }
    $tot = $pdo->query("SELECT COUNT(*) a, SUM(orders_n >= 2) rep, SUM(channel = 'both') b, SUM(rto_n >= 1 OR cx_n >= 2 OR cx_open > 0) risk, MAX(refreshed_at) at FROM customer_facts")->fetch();
    return ['ok' => true, 'rows' => $rows, 'count' => (int)$t['c'], 'spent' => (float)$t['s'], 'page' => $page, 'per' => $per, 'totals' => ['people' => (int)$tot['a'], 'repeat' => (int)$tot['rep'], 'both' => (int)$tot['b'], 'risk' => (int)$tot['risk'], 'at' => (string)$tot['at']]];
}

/* ============================================================== payment details straight from the gateway ============================================================== */
/** Gateway names (GoKwik, Razorpay ...), every transaction with its ids, the KWIK id(s) and the order's payment notes, read live from Shopify for ONE order
 *  (Shopify is where GoKwik writes what it knows). Also saved on the order_facts copy, so Finance and the next opening have it. */
function ohub_pay_live(PDO $pdo, string $store, string $sid): array
{
    if (!function_exists('shopify_stores') || !function_exists('shopify_get_token') || !function_exists('dp_dsp_shop_get')) return ['ok' => false, 'error' => 'The Shopify connection is not part of this server.'];
    if (!preg_match('/^\d+$/', $sid)) return ['ok' => false, 'error' => 'No Shopify order id.'];
    $stores = shopify_stores(); if (!isset($stores[$store])) return ['ok' => false, 'error' => 'Unknown store.'];
    $tok = shopify_get_token($store, $stores[$store]); if (is_array($tok)) return ['ok' => false, 'error' => (string)($tok['error'] ?? 'No Shopify token.')];
    $dom = $stores[$store]['domain'];
    list($c1, $o, $e1) = dp_dsp_shop_get($dom, $tok, '/admin/api/2024-10/orders/' . $sid . '.json', ['fields' => 'id,name,tags,note,note_attributes,payment_gateway_names,financial_status,total_price,total_outstanding,checkout_token,source_name,reference,processing_method']);
    if ($c1 !== 200 || !is_array($o)) return ['ok' => false, 'error' => 'Shopify said ' . ($c1 ?: 'no answer') . ($e1 !== '' ? ' (' . $e1 . ')' : '') . '. Try again in a minute.'];
    $o = (array)($o['order'] ?? []);
    list($c2, $t) = dp_dsp_shop_get($dom, $tok, '/admin/api/2024-10/orders/' . $sid . '/transactions.json', []);
    $tx = ($c2 === 200 && is_array($t)) ? (array)($t['transactions'] ?? []) : [];
    $flat = function ($v, $pre = '') use (&$flat) { $r = []; if (is_array($v)) { foreach ($v as $k => $x) { if (is_array($x)) $r = array_merge($r, $flat($x, $pre . $k . '.')); elseif ($x !== null && $x !== '' && !is_bool($x)) $r[$pre . $k] = mb_substr((string)$x, 0, 120); } } return $r; };
    $list = [];
    foreach ($tx as $x) { if (!is_array($x)) continue;
        $rc = $x['receipt'] ?? null; if (is_string($rc)) { $j = json_decode($rc, true); $rc = is_array($j) ? $j : []; }
        $rcf = array_slice($flat((array)$rc), 0, 14, true);
        $list[] = ['id' => (string)($x['id'] ?? ''), 'kind' => (string)($x['kind'] ?? ''), 'status' => (string)($x['status'] ?? ''), 'gateway' => (string)($x['gateway'] ?? ''), 'amount' => (string)($x['amount'] ?? ''), 'at' => substr(str_replace('T', ' ', (string)($x['created_at'] ?? '')), 0, 19),
            'payment_id' => (string)($x['payment_id'] ?? ''), 'auth' => (string)($x['authorization'] ?? ''), 'message' => mb_substr((string)($x['message'] ?? ''), 0, 160), 'error' => (string)($x['error_code'] ?? ''), 'test' => !empty($x['test']), 'receipt' => $rcf, 'method' => (string)($x['payment_details']['credit_card_company'] ?? '')]; }
    $attrs = []; foreach ((array)($o['note_attributes'] ?? []) as $a) if (is_array($a) && (string)($a['name'] ?? '') !== '') $attrs[] = ['k' => mb_substr((string)$a['name'], 0, 60), 'v' => mb_substr((string)($a['value'] ?? ''), 0, 160)];
    $kwik = function_exists('order_pay_refs') ? order_pay_refs($o + ['note' => (string)($o['note'] ?? '') . ' ' . json_encode($tx, JSON_UNESCAPED_UNICODE)]) : '';
    if (function_exists('of_save_pay') && $tx) { try { of_save_pay($pdo, $store, $sid, $tx); } catch (Throwable $e) { } }
    $tags = array_values(array_filter(array_map('trim', explode(',', (string)($o['tags'] ?? '')))));
    return ['ok' => true, 'gateways' => array_values((array)($o['payment_gateway_names'] ?? [])), 'tags' => $tags, 'kwik' => $kwik, 'tx' => array_slice($list, 0, 12), 'attrs' => array_slice($attrs, 0, 20), 'source' => (string)($o['source_name'] ?? ''), 'method' => (string)($o['processing_method'] ?? ''),
        'status' => (string)($o['financial_status'] ?? ''), 'total' => (string)($o['total_price'] ?? ''), 'outstanding' => isset($o['total_outstanding']) ? (string)$o['total_outstanding'] : '', 'reference' => (string)($o['reference'] ?? ''), 'tx_ok' => $c2 === 200];
}

/** Is the HRMS in step with Shopify and Shiprocket? One line each, read from what the background jobs wrote down (nothing is called here).
 *  [['key','label','state' ok|warn|bad|off,'text','ts']] */
function ohub_sync_health(): array
{
    if (!function_exists('dp_get')) return [];
    $now = time(); $age = function ($ts) use ($now) { if (!$ts) return 'never'; $m = (int)floor(($now - $ts) / 60); return $m < 1 ? 'just now' : ($m < 60 ? $m . ' min ago' : ($m < 1440 ? floor($m / 60) . ' h ago' : floor($m / 1440) . ' d ago')); };
    $L = [];
    // Shiprocket: the quick read, the day-by-day roll, and the instant webhook
    $cfg = function_exists('dp_dsp_sr_cfg') ? dp_dsp_sr_cfg() : ['ok' => false];
    $ts = (int)dp_get('sr_last_ts', '0'); $err = (string)dp_get('sr_last_err', ''); $rts = (int)dp_get('sr_roll_ts', '0'); $rerr = (string)dp_get('sr_roll_err', '');
    $live = function_exists('dp_live_stats') ? dp_live_stats() : null; $hts = $live && !empty($live['hook_ts']) ? (int)$live['hook_ts'] : 0;
    if (!$cfg['ok']) $L[] = ['sr', 'Shiprocket', 'bad', 'Not connected: the Shiprocket email and password are not saved.', 0];
    else {
        $bad = $err !== '' ? $err : $rerr; $last = max($ts, $rts);
        if ($bad !== '') $L[] = ['sr', 'Shiprocket', 'bad', 'Failing: ' . mb_substr($bad, 0, 220), $last];
        elseif (!$last || $now - $last > 3 * 3600) $L[] = ['sr', 'Shiprocket', 'warn', 'Has not been read for ' . ($last ? $age($last) : 'a long time') . ' (the background job may not be running).', $last];
        else $L[] = ['sr', 'Shiprocket', 'ok', 'Read ' . $age($last) . ((string)dp_get('sr_last_msg', '') !== '' ? ' · ' . mb_substr((string)dp_get('sr_last_msg', ''), 0, 90) : ''), $last];
    }
    $L[] = ['hook', 'Shiprocket instant updates', $hts && $now - $hts < 86400 ? 'ok' : ($hts ? 'warn' : 'off'), $hts ? 'Last push from Shiprocket ' . $age($hts) . ' · ' . (int)($live['hook_today'] ?? 0) . ' today' : 'Not connected yet (Shiprocket does not push scans; the HRMS has to ask for them).', $hts];
    $un = (string)dp_get('sr_last_unmatched', ''); $n = (int)$un;
    if ($n > 0) $L[] = ['srm', 'Shiprocket orders not matched here', $n > 20 ? 'warn' : 'info', $n . ' in the last read, e.g. ' . mb_substr(trim(substr($un, (int)strpos($un, '|') + 1)), 0, 120), $ts];
    // Shopify: the orders and their tracking numbers
    $sts = (int)dp_get('shop_last_ts', '0'); $serr = (string)dp_get('shop_last_err', ''); $fts = (int)dp_get('sf_last_ts', '0'); $ferr = (string)dp_get('sf_last_err', '');
    if ($serr !== '' || $ferr !== '') $L[] = ['shop', 'Shopify', 'bad', 'Failing: ' . mb_substr($serr !== '' ? $serr : $ferr, 0, 220), max($sts, $fts)];
    else $L[] = ['shop', 'Shopify', $sts && $now - $sts < 3600 ? 'ok' : 'warn', $sts ? 'Orders read ' . $age($sts) : 'Has not been read yet', $sts];
    return array_map(function ($x) { return ['key' => $x[0], 'label' => $x[1], 'state' => $x[2], 'text' => $x[3], 'ts' => $x[4]]; }, $L);
}
