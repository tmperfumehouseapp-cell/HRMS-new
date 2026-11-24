<?php
/**
 * ws_barcode.php — Shop wholesale · Barcodes: one 6-digit barcode on every bottle the wholesale salespeople carry.
 * Loaded by api.php for every action that starts with "ws.". PHP 7.4 safe.
 *
 * The life of a bottle (ws_bottles.status):
 *   made  -> the label is made (and printed, stuck on the bottle) by the barcode maker (Hemil)
 *   sent  -> handed over to the salesperson (the parcel / the bag leaves the office)
 *   bag   -> the salesperson pressed "Received": it is in HIS stock now (and in his city's stock on the Stock page)
 *   shop  -> he typed its code at a shop: it is on that shop's bill (a Retail order, same row the Shops page writes)
 *   missing / void -> did not arrive / damaged or lost (taken off his stock)
 *
 * Who:
 *   Admin / HR / the "Retail" tick           everything (place orders for a salesperson, set who the salespeople are, see every bag)
 *   "Shop wholesale · Barcodes" tick (ws_barcode) or the Dispatch "Store orders" tick   make barcodes, print, scan into the parcel, hand over
 *   a salesperson on the list (ws_people)    his own bag: order, receive, give at a shop by typing the codes
 *
 *   ws.me · ws.people · ws.people_save · ws.perfumes · ws.label
 *   ws.order_place · ws.orders · ws.order · ws.order_qty · ws.order_cancel
 *   ws.make · ws.mark_printed · ws.pack · ws.send
 *   ws.receive · ws.bag · ws.overview · ws.shops · ws.check · ws.give · ws.find · ws.void
 *
 * Codes: 800000 + bottle_id (800001 … 999999), so they never meet the Store's codes (100000 + id, guarded in store_pos.php).
 */
if (!isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('WS_LOADED')) { return; }
define('WS_LOADED', 1);

const WS_CODE_BASE = 800000;

function ws_now() { return date('Y-m-d H:i:s'); }
function ws_err($m, $c = 422) { json_response(['error' => $m], $c); }
function ws_all(PDO $pdo, $sql, array $a = []) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
function ws_row(PDO $pdo, $sql, array $a = []) { $s = $pdo->prepare($sql); $s->execute($a); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function ws_val(PDO $pdo, $sql, array $a = [], $d = null) { $s = $pdo->prepare($sql); $s->execute($a); $v = $s->fetchColumn(); return $v === false ? $d : $v; }
function ws_code($c) { return preg_replace('/\D/', '', (string)$c); }
function ws_ml($v) { return function_exists('retail_ml_key') ? retail_ml_key((string)$v) : trim(preg_replace('/\s*ml$/i', '', (string)$v)); }
function ws_name($v) { return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$v)), 0, 160); }
function ws_codes($raw, $max = 300) {
    $o = [];
    foreach (array_slice(is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string)$raw), 0, $max) as $c) { $c = ws_code($c); if ($c !== '') $o[$c] = 1; }
    return array_map('strval', array_keys($o));
}
function ws_in(array $a) { return implode(',', array_fill(0, count($a), '?')); }

function ws_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_ws_tables_v1_' . substr(md5(__DIR__), 0, 6);
    if (is_file($flag)) return;
    if (function_exists('ensure_retail_tables')) ensure_retail_tables($pdo);
    $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_people (emp_id INT NOT NULL PRIMARY KEY, city VARCHAR(60) NOT NULL DEFAULT '', active TINYINT NOT NULL DEFAULT 1, added_at DATETIME NOT NULL, added_by VARCHAR(80) NOT NULL DEFAULT '')$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_orders (
        order_id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, city VARCHAR(60) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'placed',
        note VARCHAR(255) NOT NULL DEFAULT '', placed_by VARCHAR(80) NOT NULL DEFAULT '', placed_at DATETIME NOT NULL,
        made_at DATETIME NULL, made_by VARCHAR(80) NOT NULL DEFAULT '', sent_at DATETIME NULL, sent_by VARCHAR(80) NOT NULL DEFAULT '', received_at DATETIME NULL,
        KEY idx_ws_o (emp_id, status), KEY idx_ws_os (status)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_items (item_id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, product VARCHAR(160) NOT NULL, ml VARCHAR(10) NOT NULL, qty INT NOT NULL DEFAULT 0, asked INT NOT NULL DEFAULT 0, KEY idx_ws_i (order_id))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_bottles (
        bottle_id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(16) NOT NULL, order_id INT NOT NULL, item_id INT NOT NULL, product VARCHAR(160) NOT NULL, ml VARCHAR(10) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'made', emp_id INT NOT NULL, shop_id INT NULL, retail_order_id INT NULL, retail_order_no VARCHAR(30) NOT NULL DEFAULT '',
        made_at DATETIME NOT NULL, made_by VARCHAR(80) NOT NULL DEFAULT '', printed_at DATETIME NULL, print_count INT NOT NULL DEFAULT 0, packed_at DATETIME NULL,
        sent_at DATETIME NULL, received_at DATETIME NULL, given_at DATETIME NULL, note VARCHAR(255) NOT NULL DEFAULT '',
        UNIQUE KEY uq_ws_code (code), KEY idx_ws_b (status, emp_id), KEY idx_ws_bo (order_id), KEY idx_ws_shop (shop_id)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ws_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, who VARCHAR(80) NOT NULL DEFAULT '', kind VARCHAR(12) NOT NULL, order_id INT NULL, shop_id INT NULL, codes TEXT NULL, detail VARCHAR(255) NOT NULL DEFAULT '', KEY idx_ws_l (at), KEY idx_ws_lo (order_id))$E");
    @file_put_contents($flag, '1');
}

function ws_log(PDO $pdo, $who, $kind, $orderId, $shopId, array $codes, $detail) {
    try { $pdo->prepare("INSERT INTO ws_log (at, who, kind, order_id, shop_id, codes, detail) VALUES (?,?,?,?,?,?,?)")->execute([ws_now(), mb_substr((string)$who, 0, 80), $kind, $orderId ?: null, $shopId ?: null, $codes ? implode(',', $codes) : null, mb_substr((string)$detail, 0, 255)]); } catch (Throwable $e) { error_log('ws_log: ' . $e->getMessage()); }
}

function ws_emp_name(PDO $pdo, $id) {
    static $c = [];
    $id = (int)$id; if (!$id) return '';
    if (!isset($c[$id])) $c[$id] = (string)ws_val($pdo, "SELECT full_name FROM employees WHERE employee_id = ?", [$id], '#' . $id);
    return $c[$id];
}

/** who is calling and what may they do */
function ws_gate(PDO $pdo) {
    $auth = require_auth(); ws_ensure($pdo);
    $emp = (int)($auth['employee_id'] ?? 0); $role = (string)($auth['role'] ?? '');
    $admin = $role === 'Admin' || $role === 'HR' || user_can($auth, 'retail');
    $maker = $admin || user_can($auth, 'ws_barcode') || user_can($auth, 'dispatch_restock');
    $me = $emp ? ws_row($pdo, "SELECT * FROM ws_people WHERE emp_id = ? AND active = 1", [$emp]) : null;
    if (!$admin && !$maker && !$me) ws_err('Your login is not on the wholesale list. Ask Admin to add you on Shop wholesale → Barcodes → Salespeople.', 403);
    return ['auth' => $auth, 'emp' => $emp, 'admin' => $admin, 'maker' => $maker, 'sp' => $me ? ['emp_id' => $emp, 'city' => (string)$me['city']] : null, 'by' => (string)($auth['username'] ?? 'user')];
}
function ws_need($g, $what) {
    if ($what === 'admin' && !$g['admin']) ws_err('Only Admin / HR (or the Retail tick) can do this.', 403);
    if ($what === 'maker' && !$g['maker']) ws_err('Only the barcode maker can do this (tick "Shop wholesale · Barcodes" on the Roles page).', 403);
}
/** the salesperson an action is for: himself, or (Admin / maker) the one asked for */
function ws_target(PDO $pdo, $g, $empId) {
    $empId = (int)$empId;
    if (!$empId || (!$g['admin'] && !$g['maker'])) { if (!$g['sp']) ws_err('Pick the salesperson.'); return $g['sp']; }
    $r = ws_row($pdo, "SELECT * FROM ws_people WHERE emp_id = ? AND active = 1", [$empId]);
    if (!$r) ws_err('That person is not on the wholesale salespeople list.', 404);
    return ['emp_id' => $empId, 'city' => (string)$r['city']];
}

function ws_people(PDO $pdo) {
    $o = [];
    foreach (ws_all($pdo, "SELECT p.*, e.full_name FROM ws_people p LEFT JOIN employees e ON e.employee_id = p.emp_id WHERE p.active = 1 ORDER BY p.city, e.full_name") as $r)
        $o[] = ['emp_id' => (int)$r['emp_id'], 'name' => (string)($r['full_name'] ?: '#' . $r['emp_id']), 'city' => (string)$r['city']];
    return $o;
}

/** one order with its lines and counts (made / sent / received ... per line) */
function ws_order_out(PDO $pdo, array $o) {
    $id = (int)$o['order_id']; $lines = [];
    $cnt = [];
    foreach (ws_all($pdo, "SELECT item_id, status, COUNT(*) n, SUM(packed_at IS NOT NULL) pk, SUM(print_count > 0) pr FROM ws_bottles WHERE order_id = ? GROUP BY item_id, status", [$id]) as $r) {
        $k = (int)$r['item_id']; if (!isset($cnt[$k])) $cnt[$k] = ['made' => 0, 'packed' => 0, 'printed' => 0, 'by' => []];
        $cnt[$k]['by'][$r['status']] = (int)$r['n']; if ($r['status'] !== 'void') { $cnt[$k]['made'] += (int)$r['n']; $cnt[$k]['packed'] += (int)$r['pk']; $cnt[$k]['printed'] += (int)$r['pr']; }
    }
    $t = ['qty' => 0, 'made' => 0, 'packed' => 0, 'printed' => 0, 'sent' => 0, 'bag' => 0, 'missing' => 0];
    foreach (ws_all($pdo, "SELECT * FROM ws_items WHERE order_id = ? ORDER BY product, CAST(ml AS DECIMAL(8,2))", [$id]) as $i) {
        $c = $cnt[(int)$i['item_id']] ?? ['made' => 0, 'packed' => 0, 'printed' => 0, 'by' => []];
        $after = 0; foreach (['bag', 'shop', 'back'] as $s) $after += $c['by'][$s] ?? 0;
        $l = ['item_id' => (int)$i['item_id'], 'product' => $i['product'], 'ml' => $i['ml'], 'qty' => (int)$i['qty'], 'asked' => (int)$i['asked'], 'made' => $c['made'], 'packed' => $c['packed'], 'printed' => $c['printed'],
              'to_make' => max(0, (int)$i['qty'] - $c['made']), 'sent' => $c['by']['sent'] ?? 0, 'received' => $after, 'missing' => $c['by']['missing'] ?? 0];
        $lines[] = $l;
        $t['qty'] += $l['qty']; $t['made'] += $l['made']; $t['packed'] += $l['packed']; $t['printed'] += $l['printed']; $t['sent'] += $l['sent']; $t['bag'] += $l['received']; $t['missing'] += $l['missing'];
    }
    return ['order_id' => $id, 'no' => 'WS-' . $id, 'emp_id' => (int)$o['emp_id'], 'name' => ws_emp_name($pdo, $o['emp_id']), 'city' => $o['city'], 'status' => $o['status'], 'note' => $o['note'],
            'placed_by' => $o['placed_by'], 'placed_at' => $o['placed_at'], 'made_at' => $o['made_at'], 'sent_at' => $o['sent_at'], 'sent_by' => $o['sent_by'], 'received_at' => $o['received_at'], 'lines' => $lines, 'totals' => $t];
}
function ws_order_get(PDO $pdo, $id) { $o = ws_row($pdo, "SELECT * FROM ws_orders WHERE order_id = ?", [(int)$id]); if (!$o) ws_err('Order not found.', 404); return $o; }
function ws_can_see($g, array $o) { return $g['admin'] || $g['maker'] || ($g['sp'] && (int)$o['emp_id'] === $g['emp']); }

function ws_bottle_out(array $b) {
    return ['code' => (string)$b['code'], 'product' => $b['product'], 'ml' => $b['ml'], 'status' => $b['status'], 'order_id' => (int)$b['order_id'], 'emp_id' => (int)$b['emp_id'],
            'shop_id' => $b['shop_id'] === null ? null : (int)$b['shop_id'], 'retail_order_no' => (string)$b['retail_order_no'], 'made_at' => $b['made_at'], 'print_count' => (int)$b['print_count'],
            'packed' => $b['packed_at'] !== null, 'received_at' => $b['received_at'], 'given_at' => $b['given_at']];
}

/** what a salesperson holds now, grouped by perfume + size (and the codes) */
function ws_bag(PDO $pdo, $empId) {
    $g = [];
    foreach (ws_all($pdo, "SELECT code, product, ml, received_at FROM ws_bottles WHERE emp_id = ? AND status = 'bag' ORDER BY product, CAST(ml AS DECIMAL(8,2)), bottle_id", [(int)$empId]) as $r) {
        $k = mb_strtolower($r['product']) . '|' . $r['ml'];
        if (!isset($g[$k])) $g[$k] = ['product' => $r['product'], 'ml' => $r['ml'], 'qty' => 0, 'codes' => []];
        $g[$k]['qty']++; $g[$k]['codes'][] = (string)$r['code'];
    }
    return array_values($g);
}

/** the city stock on the Stock page (retail_stock_log), one line per perfume + size. $qty can be negative. Never breaks the caller. */
function ws_city_stock(PDO $pdo, $city, $product, $ml, $qty, $note, array $auth) {
    try {
        if ($qty == 0 || $city === '' || !function_exists('ensure_retail_stock_tables') || !ensure_retail_stock_tables($pdo)) return;
        if ((string)setting_get($pdo, 'ws_stock_sync', '1') !== '1') return;
        $key = retail_stock_key($product, $ml); $name = $product; $cur = null;
        foreach (retail_stock_city_rows($pdo, $city) as $r) if ($r['key'] === $key) { $cur = $r; break; }
        if ($cur) $name = $cur['product_name']; else foreach (retail_stock_rows($pdo) as $r) if ($r['key'] === $key) { $name = $r['product_name']; break; }
        $kind = $qty > 0 ? ($cur ? 'in' : 'opening') : 'adjust';
        $pdo->prepare("INSERT INTO retail_stock_log (product_name, ml, qty, kind, note, city, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, ws_ml($ml) . ' ml', (int)$qty, $kind, mb_substr($note, 0, 255), $city, $auth['employee_id'] ?? null, mb_substr((string)($auth['username'] ?? ''), 0, 100), ws_now()]);
    } catch (Throwable $e) { error_log('ws_city_stock: ' . $e->getMessage()); }
}

/** the bill price of one bottle size (the same ml price list the Shops page uses) */
function ws_price(PDO $pdo, $ml) {
    $k = ws_ml($ml);
    foreach (function_exists('retail_ml_prices') ? retail_ml_prices($pdo) : [] as $p) if (ws_ml($p['ml']) === $k) return (float)$p['price'];
    return 0.0;
}
function ws_sizes(PDO $pdo) {
    $s = [];
    foreach (function_exists('retail_ml_prices') ? retail_ml_prices($pdo) : [] as $p) { $k = ws_ml($p['ml']); if ($k !== '') $s[$k] = ['ml' => $k, 'price' => (float)$p['price']]; }
    if (!$s) foreach (['20', '50', '100'] as $k) $s[$k] = ['ml' => $k, 'price' => 0];
    uasort($s, function ($a, $b) { return (float)$a['ml'] <=> (float)$b['ml']; });
    return array_values($s);
}

/* ============================== router ============================== */
$wsPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
try {
    switch ($action) {

    case 'ws.me': {
        $g = ws_gate($pdo);
        $open = ['placed' => 0, 'making' => 0, 'sent' => 0];
        foreach (ws_all($pdo, "SELECT status, COUNT(*) n FROM ws_orders WHERE status IN ('placed','making','sent')" . (($g['admin'] || $g['maker']) ? '' : ' AND emp_id = ' . (int)$g['emp']) . " GROUP BY status") as $r) $open[$r['status']] = (int)$r['n'];
        json_response(['admin' => $g['admin'], 'maker' => $g['maker'], 'sp' => $g['sp'] ? ['emp_id' => $g['emp'], 'name' => ws_emp_name($pdo, $g['emp']), 'city' => $g['sp']['city']] : null,
                       'people' => ($g['admin'] || $g['maker']) ? ws_people($pdo) : [], 'cities' => function_exists('retail_cities_list') ? array_map(function ($c) { return (string)$c['name']; }, retail_cities_list($pdo)) : [],
                       'open' => $open, 'stock_sync' => (string)setting_get($pdo, 'ws_stock_sync', '1') === '1']);
    }

    case 'ws.people': {
        $g = ws_gate($pdo); ws_need($g, 'admin');
        $emps = ws_all($pdo, "SELECT e.employee_id, e.full_name, d.department_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' ORDER BY e.full_name");
        json_response(['people' => ws_people($pdo), 'employees' => array_map(function ($e) { return ['emp_id' => (int)$e['employee_id'], 'name' => (string)$e['full_name'], 'dept' => (string)($e['department_name'] ?? '')]; }, $emps)]);
    }
    case 'ws.people_save': {
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'admin'); $in = json_input(); $emp = (int)($in['emp_id'] ?? 0);
        if (!$emp || !ws_val($pdo, "SELECT 1 FROM employees WHERE employee_id = ?", [$emp])) ws_err('Pick the employee.');
        if (!empty($in['remove'])) {
            $held = (int)ws_val($pdo, "SELECT COUNT(*) FROM ws_bottles WHERE emp_id = ? AND status IN ('sent','bag')", [$emp], 0);
            if ($held) ws_err(ws_emp_name($pdo, $emp) . ' still has ' . $held . ' bottles (in his bag or on the way). Give them at shops or mark them first.');
            $pdo->prepare("UPDATE ws_people SET active = 0 WHERE emp_id = ?")->execute([$emp]);
            ws_log($pdo, $g['by'], 'people', 0, 0, [], 'removed ' . ws_emp_name($pdo, $emp));
            json_response(['ok' => true, 'people' => ws_people($pdo), 'message' => ws_emp_name($pdo, $emp) . ' removed from the list.']);
        }
        $city = function_exists('retail_city_ensure') ? retail_city_ensure($pdo, (string)($in['city'] ?? '')) : trim((string)($in['city'] ?? ''));
        if ($city === '') ws_err('Pick his city (Vadodara, Surat ...).');
        $pdo->prepare("INSERT INTO ws_people (emp_id, city, active, added_at, added_by) VALUES (?,?,1,?,?) ON DUPLICATE KEY UPDATE city = VALUES(city), active = 1")->execute([$emp, $city, ws_now(), $g['by']]);
        ws_log($pdo, $g['by'], 'people', 0, 0, [], ws_emp_name($pdo, $emp) . ' → ' . $city);
        json_response(['ok' => true, 'people' => ws_people($pdo), 'message' => ws_emp_name($pdo, $emp) . ' is a wholesale salesperson for ' . $city . '.']);
    }

    case 'ws.perfumes': {
        ws_gate($pdo);
        $names = []; $seen = [];
        $r = function_exists('retail_perfume_names') ? retail_perfume_names(!empty($_GET['refresh'])) : ['names' => []];
        $raw = (array)($r['names'] ?? []);
        try { foreach ($pdo->query("SELECT DISTINCT product FROM ws_items")->fetchAll(PDO::FETCH_COLUMN) as $p) $raw[] = (string)$p; } catch (Throwable $e) {}
        foreach ($raw as $n) {
            $n = trim((string)$n); if (preg_match('/^(.*?)[\s(\-–]*(\d+(?:\.\d+)?)\s*ml\s*\)?\s*$/i', $n, $m) && trim($m[1]) !== '') $n = trim($m[1]);
            $k = mb_strtolower($n); if ($n === '' || isset($seen[$k])) continue; $seen[$k] = 1; $names[] = $n;
        }
        usort($names, 'strcasecmp');
        json_response(['perfumes' => $names, 'sizes' => ws_sizes($pdo), 'error' => (string)($r['error'] ?? '')]);
    }

    case 'ws.label': {
        ws_gate($pdo);
        $w = (float)setting_get($pdo, 'st_label_w', 0); $h = (float)setting_get($pdo, 'st_label_h', 0);
        if ($w < 15 || $h < 10) { $w = 0; $h = 0; try { $q = $pdo->prepare("SELECT svalue FROM pos_settings WHERE skey = ?"); $q->execute(['label_w']); $w = (float)$q->fetchColumn(); $q->execute(['label_h']); $h = (float)$q->fetchColumn(); } catch (Throwable $e) { $w = $h = 0; } }
        if ($w < 15 || $h < 10) { $w = 31.0; $h = 21.0; }
        json_response(['label_w' => $w, 'label_h' => $h]);
    }

    /* ---------- ORDERS: the salesperson (or Admin for him) asks for bottles ---------- */
    case 'ws.order_place': {
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); $in = json_input(); $t = ws_target($pdo, $g, $in['emp_id'] ?? 0);
        $lines = [];
        foreach ((array)($in['lines'] ?? []) as $l) {
            $p = ws_name($l['product'] ?? ''); $ml = ws_ml((string)($l['ml'] ?? '')); $q = (int)($l['qty'] ?? 0);
            if ($p === '' || $q <= 0) continue;
            if ($ml === '') ws_err('Pick the size of ' . $p . '.');
            $k = mb_strtolower($p) . '|' . $ml; if (!isset($lines[$k])) $lines[$k] = ['p' => $p, 'ml' => $ml, 'q' => 0]; $lines[$k]['q'] = min(2000, $lines[$k]['q'] + $q);
        }
        if (!$lines) ws_err('Add at least one perfume with a quantity.');
        $n = array_sum(array_map(function ($x) { return $x['q']; }, $lines)); if ($n > 3000) ws_err('At most 3000 bottles in one order.');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO ws_orders (emp_id, city, status, note, placed_by, placed_at) VALUES (?,?, 'placed', ?, ?, ?)")->execute([$t['emp_id'], $t['city'], mb_substr(trim((string)($in['note'] ?? '')), 0, 255), $g['by'], ws_now()]);
            $oid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO ws_items (order_id, product, ml, qty, asked) VALUES (?,?,?,?,?)");
            foreach ($lines as $l) $ins->execute([$oid, $l['p'], $l['ml'], $l['q'], $l['q']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); ws_err('The order could not be saved. Try again.', 500); }
        ws_log($pdo, $g['by'], 'order', $oid, 0, [], $n . ' bottles for ' . ws_emp_name($pdo, $t['emp_id']));
        json_response(['ok' => true, 'order' => ws_order_out($pdo, ws_order_get($pdo, $oid)), 'message' => 'Order WS-' . $oid . ' placed: ' . $n . ' bottles for ' . ws_emp_name($pdo, $t['emp_id']) . '. The barcode maker sees it now.']);
    }
    case 'ws.orders': {
        $g = ws_gate($pdo); $f = (string)($_GET['f'] ?? 'open');
        $w = $f === 'done' ? "status IN ('received','cancelled')" : ($f === 'all' ? '1=1' : "status IN ('placed','making','sent')");
        $args = [];
        if (!$g['admin'] && !$g['maker']) { $w .= ' AND emp_id = ?'; $args[] = $g['emp']; }
        elseif (!empty($_GET['emp_id'])) { $w .= ' AND emp_id = ?'; $args[] = (int)$_GET['emp_id']; }
        $out = []; foreach (ws_all($pdo, "SELECT * FROM ws_orders WHERE $w ORDER BY order_id DESC LIMIT " . ($f === 'open' ? 200 : 60), $args) as $o) $out[] = ws_order_out($pdo, $o);
        json_response(['orders' => $out]);
    }
    case 'ws.order': {
        $g = ws_gate($pdo); $o = ws_order_get($pdo, $_GET['id'] ?? 0); if (!ws_can_see($g, $o)) ws_err('Not your order.', 403);
        $b = array_map('ws_bottle_out', ws_all($pdo, "SELECT * FROM ws_bottles WHERE order_id = ? AND status <> 'void' ORDER BY product, CAST(ml AS DECIMAL(8,2)), bottle_id", [(int)$o['order_id']]));
        json_response(['order' => ws_order_out($pdo, $o), 'bottles' => $b]);
    }
    case 'ws.order_qty': {          // before the labels are made: the maker (or Admin) changes a line ("the supplier has only 20"); 0 removes it
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'maker'); $in = json_input();
        $i = ws_row($pdo, "SELECT i.*, o.status FROM ws_items i JOIN ws_orders o ON o.order_id = i.order_id WHERE i.item_id = ?", [(int)($in['item_id'] ?? 0)]); if (!$i) ws_err('Line not found.', 404);
        if (!in_array($i['status'], ['placed', 'making'], true)) ws_err('This order was already handed over.');
        $made = (int)ws_val($pdo, "SELECT COUNT(*) FROM ws_bottles WHERE item_id = ? AND status <> 'void'", [(int)$i['item_id']], 0);
        $q = max(0, min(2000, (int)($in['qty'] ?? 0))); if ($q < $made) ws_err($made . ' labels are already made for this line. To go lower, void the extra labels.');
        $pdo->prepare("UPDATE ws_items SET qty = ? WHERE item_id = ?")->execute([$q, (int)$i['item_id']]);
        ws_log($pdo, $g['by'], 'qty', (int)$i['order_id'], 0, [], $i['product'] . ' ' . $i['ml'] . ' ml: ' . $i['qty'] . ' → ' . $q);
        json_response(['ok' => true, 'order' => ws_order_out($pdo, ws_order_get($pdo, $i['order_id']))]);
    }
    case 'ws.order_cancel': {
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); $o = ws_order_get($pdo, json_input()['order_id'] ?? 0); if (!ws_can_see($g, $o)) ws_err('Not your order.', 403);
        if ($o['status'] !== 'placed') ws_err('Barcodes are already made for this order: only the barcode maker / Admin can stop it now (void the labels).');
        $pdo->prepare("UPDATE ws_orders SET status = 'cancelled' WHERE order_id = ? AND status = 'placed'")->execute([(int)$o['order_id']]);
        ws_log($pdo, $g['by'], 'cancel', (int)$o['order_id'], 0, [], 'cancelled');
        json_response(['ok' => true, 'message' => 'Order WS-' . $o['order_id'] . ' cancelled.']);
    }

    /* ---------- MAKER (Hemil): make the barcodes, print, stick, scan into the parcel, hand over ---------- */
    case 'ws.make': {
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'maker'); $in = json_input(); $o = ws_order_get($pdo, $in['order_id'] ?? 0);
        if (!in_array($o['status'], ['placed', 'making'], true)) ws_err('This order is ' . $o['status'] . '.');
        $out = ws_order_out($pdo, $o); $plan = []; $n = 0;
        foreach ($out['lines'] as $l) if ($l['to_make'] > 0) { $plan[] = $l; $n += $l['to_make']; }
        if (!$n) ws_err('All labels of this order are already made. Print them, stick them, then hand over.');
        $top = (int)ws_val($pdo, "SELECT COALESCE(MAX(bottle_id), 0) FROM ws_bottles", [], 0);
        if (WS_CODE_BASE + $top + $n > 999999) ws_err('The 6-digit code range for wholesale is used up.', 500);
        $now = ws_now(); $tag = 't' . bin2hex(random_bytes(4));
        $pdo->beginTransaction();
        try {
            $rows = []; foreach ($plan as $l) for ($i = 0; $i < $l['to_make']; $i++) $rows[] = $l;
            $seq = 0;
            foreach (array_chunk($rows, 100) as $ch) {
                $ph = []; $a = [];
                foreach ($ch as $l) { $ph[] = "(?,?,?,?,?, 'made', ?, ?, ?)"; array_push($a, $tag . '-' . ($seq++), (int)$o['order_id'], $l['item_id'], $l['product'], $l['ml'], (int)$o['emp_id'], $now, mb_substr($g['by'], 0, 80)); }
                $pdo->prepare("INSERT INTO ws_bottles (code, order_id, item_id, product, ml, status, emp_id, made_at, made_by) VALUES " . implode(',', $ph))->execute($a);
            }
            $pdo->prepare("UPDATE ws_bottles SET code = CAST(bottle_id + " . WS_CODE_BASE . " AS CHAR) WHERE order_id = ? AND code LIKE ?")->execute([(int)$o['order_id'], $tag . '-%']);
            $pdo->prepare("UPDATE ws_orders SET status = 'making', made_at = COALESCE(made_at, ?), made_by = ? WHERE order_id = ?")->execute([$now, mb_substr($g['by'], 0, 80), (int)$o['order_id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('ws.make: ' . $e->getMessage()); ws_err('The barcodes could not be made. Nothing was saved. Try again.', 500); }
        ws_log($pdo, $g['by'], 'make', (int)$o['order_id'], 0, [], $n . ' labels');
        $b = array_map('ws_bottle_out', ws_all($pdo, "SELECT * FROM ws_bottles WHERE order_id = ? AND status <> 'void' ORDER BY product, CAST(ml AS DECIMAL(8,2)), bottle_id", [(int)$o['order_id']]));
        json_response(['ok' => true, 'order' => ws_order_out($pdo, ws_order_get($pdo, $o['order_id'])), 'bottles' => $b, 'message' => $n . ' barcodes made. Print the labels and stick one on each bottle.']);
    }
    case 'ws.mark_printed': {
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'maker'); $codes = ws_codes(json_input()['codes'] ?? [], 3000);
        if (!$codes) json_response(['ok' => true, 'marked' => 0]);
        $u = $pdo->prepare("UPDATE ws_bottles SET printed_at = COALESCE(printed_at, ?), print_count = print_count + 1 WHERE status <> 'void' AND code IN (" . ws_in($codes) . ")"); $u->execute(array_merge([ws_now()], $codes));
        json_response(['ok' => true, 'marked' => $u->rowCount()]);
    }
    case 'ws.pack': {               // the label is on the bottle: scan it (or type it) into the parcel. A check that every bottle really has its label.
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'maker'); $in = json_input(); $o = ws_order_get($pdo, $in['order_id'] ?? 0); $res = [];
        $codes = ws_codes($in['codes'] ?? [], 200); $found = [];
        if ($codes) foreach (ws_all($pdo, "SELECT * FROM ws_bottles WHERE code IN (" . ws_in($codes) . ")", $codes) as $b) $found[(string)$b['code']] = $b;
        $ok = [];
        foreach ($codes as $c) {
            $b = $found[$c] ?? null;
            if (!$b) { $res[] = ['code' => $c, 'ok' => false, 'message' => $c . ' is not a wholesale barcode.']; continue; }
            if ((int)$b['order_id'] !== (int)$o['order_id']) { $res[] = ['code' => $c, 'ok' => false, 'message' => $c . ' belongs to order WS-' . $b['order_id'] . ', not this one.']; continue; }
            if ($b['status'] !== 'made') { $res[] = ['code' => $c, 'ok' => false, 'message' => $c . ' is already ' . $b['status'] . '.']; continue; }
            if ($b['packed_at'] !== null) { $res[] = ['code' => $c, 'ok' => false, 'dup' => true, 'message' => 'Already in the parcel: ' . $b['product'] . ' ' . $b['ml'] . ' ml.']; continue; }
            $ok[] = (int)$b['bottle_id']; $res[] = ['code' => $c, 'ok' => true, 'message' => 'In the parcel: ' . $b['product'] . ' ' . $b['ml'] . ' ml'];
        }
        if ($ok) $pdo->prepare("UPDATE ws_bottles SET packed_at = ? WHERE packed_at IS NULL AND bottle_id IN (" . implode(',', $ok) . ")")->execute([ws_now()]);
        json_response(['results' => $res, 'order' => ws_order_out($pdo, ws_order_get($pdo, $o['order_id']))]);
    }
    case 'ws.send': {               // hand over: every label made for the order goes to the salesperson ("on the way" until he presses Received)
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'maker'); $in = json_input(); $o = ws_order_get($pdo, $in['order_id'] ?? 0);
        if ($o['status'] !== 'making') ws_err($o['status'] === 'placed' ? 'Make the barcodes first.' : 'This order is ' . $o['status'] . '.');
        $out = ws_order_out($pdo, $o); $n = $out['totals']['made'];
        if (!$n) ws_err('No labels are made for this order.');
        if (empty($in['force']) && $out['totals']['packed'] < $n) json_response(['error' => 'Only ' . $out['totals']['packed'] . ' of ' . $n . ' bottles were scanned into the parcel.', 'need_force' => true, 'packed' => $out['totals']['packed'], 'made' => $n], 409);
        $short = 0; foreach ($out['lines'] as $l) $short += $l['to_make'];
        $now = ws_now();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE ws_bottles SET status = 'sent', sent_at = ? WHERE order_id = ? AND status = 'made'")->execute([$now, (int)$o['order_id']]);
            $pdo->prepare("UPDATE ws_orders SET status = 'sent', sent_at = ?, sent_by = ? WHERE order_id = ?")->execute([$now, mb_substr($g['by'], 0, 80), (int)$o['order_id']]);
            if ($short) foreach ($out['lines'] as $l) if ($l['to_make'] > 0) $pdo->prepare("UPDATE ws_items SET qty = ? WHERE item_id = ?")->execute([$l['made'], $l['item_id']]);   // what was not made is not coming: the line shrinks (asked keeps the first number)
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); ws_err('Could not hand over. Try again.', 500); }
        ws_log($pdo, $g['by'], 'send', (int)$o['order_id'], 0, [], $n . ' bottles to ' . ws_emp_name($pdo, $o['emp_id']) . ($short ? ' (' . $short . ' not supplied)' : ''));
        if (function_exists('push_send_employee')) { try { push_send_employee($pdo, (int)$o['emp_id'], ['title' => 'Your bottles are on the way', 'body' => 'WS-' . $o['order_id'] . ': ' . $n . ' barcoded bottles. Count them and press Received.', 'url' => 'retail_barcodes.html', 'tag' => 'hrms-ws']); } catch (Throwable $e) {} }
        json_response(['ok' => true, 'message' => $n . ' bottles handed over to ' . ws_emp_name($pdo, $o['emp_id']) . '. They show "on the way" until he presses Received.' . ($short ? ' ' . $short . ' were not made (not supplied).' : '')]);
    }

    /* ---------- SALESPERSON: receive the parcel, his bag ---------- */
    case 'ws.receive': {            // {order_id, missing:[codes]} everything else of the parcel is now in his bag
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); $in = json_input(); $o = ws_order_get($pdo, $in['order_id'] ?? 0);
        if ((int)$o['emp_id'] !== $g['emp'] && !$g['admin']) ws_err('Only ' . ws_emp_name($pdo, $o['emp_id']) . ' (or Admin) can receive this parcel.', 403);
        if ($o['status'] !== 'sent') ws_err($o['status'] === 'received' ? 'Already received.' : 'This parcel was not handed over yet.');
        $miss = ws_codes($in['missing'] ?? [], 3000); $now = ws_now();
        $all = ws_all($pdo, "SELECT * FROM ws_bottles WHERE order_id = ? AND status = 'sent'", [(int)$o['order_id']]); $codesIn = [];
        foreach ($all as $b) $codesIn[(string)$b['code']] = 1;
        foreach ($miss as $c) if (!isset($codesIn[$c])) ws_err($c . ' is not in this parcel.');
        $byItem = []; $got = 0;
        $pdo->beginTransaction();
        try {
            if ($miss) $pdo->prepare("UPDATE ws_bottles SET status = 'missing', note = 'did not arrive' WHERE order_id = ? AND status = 'sent' AND code IN (" . ws_in($miss) . ")")->execute(array_merge([(int)$o['order_id']], $miss));
            $pdo->prepare("UPDATE ws_bottles SET status = 'bag', received_at = ? WHERE order_id = ? AND status = 'sent'")->execute([$now, (int)$o['order_id']]);
            $pdo->prepare("UPDATE ws_orders SET status = 'received', received_at = ? WHERE order_id = ?")->execute([$now, (int)$o['order_id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); ws_err('Could not save. Try again.', 500); }
        $mset = array_flip($miss);
        foreach ($all as $b) { if (isset($mset[(string)$b['code']])) continue; $k = mb_strtolower($b['product']) . '|' . $b['ml']; if (!isset($byItem[$k])) $byItem[$k] = [$b['product'], $b['ml'], 0]; $byItem[$k][2]++; $got++; }
        foreach ($byItem as $x) ws_city_stock($pdo, (string)$o['city'], $x[0], $x[1], $x[2], 'Barcodes WS-' . $o['order_id'] . ' received by ' . ws_emp_name($pdo, $o['emp_id']), $g['auth']);
        ws_log($pdo, $g['by'], 'receive', (int)$o['order_id'], 0, $miss, $got . ' received' . ($miss ? ', ' . count($miss) . ' missing' : ''));
        if ($miss && function_exists('push_send_employee')) {
            try { foreach ($pdo->query("SELECT DISTINCT employee_id FROM users WHERE role IN ('Admin') AND employee_id > 0")->fetchAll(PDO::FETCH_COLUMN) as $a) push_send_employee($pdo, (int)$a, ['title' => 'Bottles missing in a parcel', 'body' => ws_emp_name($pdo, $o['emp_id']) . ': ' . count($miss) . ' of WS-' . $o['order_id'] . ' did not arrive.', 'url' => 'retail_barcodes.html', 'tag' => 'hrms-ws']); } catch (Throwable $e) {}
        }
        json_response(['ok' => true, 'message' => $got . ' bottles are in your bag now.' . ($miss ? ' ' . count($miss) . ' marked missing: the office is told.' : '')]);
    }
    case 'ws.bag': {
        $g = ws_gate($pdo); $t = ws_target($pdo, $g, $_GET['emp_id'] ?? 0);
        $bag = ws_bag($pdo, $t['emp_id']); $n = 0; foreach ($bag as $x) $n += $x['qty'];
        $way = (int)ws_val($pdo, "SELECT COUNT(*) FROM ws_bottles WHERE emp_id = ? AND status = 'sent'", [$t['emp_id']], 0);
        $given = ws_all($pdo, "SELECT b.retail_order_no, b.shop_id, s.shop_name, MAX(b.given_at) at, COUNT(*) n FROM ws_bottles b LEFT JOIN retail_shops s ON s.shop_id = b.shop_id WHERE b.emp_id = ? AND b.status = 'shop' AND b.given_at >= ? GROUP BY b.retail_order_no, b.shop_id, s.shop_name ORDER BY at DESC LIMIT 40", [$t['emp_id'], date('Y-m-d 00:00:00', time() - 6 * 86400)]);
        json_response(['emp_id' => $t['emp_id'], 'name' => ws_emp_name($pdo, $t['emp_id']), 'city' => $t['city'], 'bag' => $bag, 'total' => $n, 'on_the_way' => $way,
                       'given' => array_map(function ($r) { return ['order_no' => $r['retail_order_no'], 'shop_id' => (int)$r['shop_id'], 'shop' => (string)$r['shop_name'], 'at' => $r['at'], 'n' => (int)$r['n']]; }, $given)]);
    }
    case 'ws.overview': {           // Admin: every salesperson's stock right now
        $g = ws_gate($pdo); if (!$g['admin'] && !$g['maker']) ws_err('No access.', 403);
        $out = []; $since = date('Y-m-d 00:00:00');
        foreach (ws_people($pdo) as $p) {
            $bag = ws_bag($pdo, $p['emp_id']); $n = 0; foreach ($bag as $x) $n += $x['qty'];
            $c = []; foreach (ws_all($pdo, "SELECT status, COUNT(*) n FROM ws_bottles WHERE emp_id = ? GROUP BY status", [$p['emp_id']]) as $r) $c[$r['status']] = (int)$r['n'];
            $out[] = $p + ['bag' => $bag, 'in_bag' => $n, 'on_the_way' => $c['sent'] ?? 0, 'being_made' => $c['made'] ?? 0, 'at_shops' => $c['shop'] ?? 0, 'missing' => $c['missing'] ?? 0,
                           'given_today' => (int)ws_val($pdo, "SELECT COUNT(*) FROM ws_bottles WHERE emp_id = ? AND status = 'shop' AND given_at >= ?", [$p['emp_id'], $since], 0),
                           'open_orders' => (int)ws_val($pdo, "SELECT COUNT(*) FROM ws_orders WHERE emp_id = ? AND status IN ('placed','making','sent')", [$p['emp_id']], 0)];
        }
        $miss = array_map('ws_bottle_out', ws_all($pdo, "SELECT * FROM ws_bottles WHERE status = 'missing' ORDER BY bottle_id DESC LIMIT 100"));
        json_response(['people' => $out, 'missing' => $miss]);
    }

    /* ---------- SALESPERSON at the shop: type the codes -> the shop's bill ---------- */
    case 'ws.shops': {
        $g = ws_gate($pdo); $t = ws_target($pdo, $g, $_GET['emp_id'] ?? 0); $q = trim((string)($_GET['q'] ?? ''));
        $sc = retail_city_sql($pdo, 's'); $args = [$t['city']]; $w = '';
        if ($q !== '') { $w = " AND (s.shop_name LIKE ? OR s.owner_name LIKE ? OR s.mobile LIKE ? OR s.area LIKE ?)"; $lk = '%' . $q . '%'; array_push($args, $lk, $lk, $lk, $lk); }
        $rows = ws_all($pdo, "SELECT s.shop_id, s.shop_name, s.owner_name, s.area, s.mobile FROM retail_shops s WHERE s.status = 'active' AND $sc = ?$w ORDER BY s.shop_name LIMIT 60", $args);
        json_response(['city' => $t['city'], 'shops' => array_map(function ($r) { return ['shop_id' => (int)$r['shop_id'], 'name' => (string)$r['shop_name'], 'owner' => (string)$r['owner_name'], 'area' => (string)$r['area'], 'mobile' => (string)$r['mobile']]; }, $rows)]);
    }
    case 'ws.check': {              // the codes typed so far: is each one in my bag? (what it is, so a typing mistake shows at once)
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); $in = json_input(); $t = ws_target($pdo, $g, $in['emp_id'] ?? 0); $codes = ws_codes($in['codes'] ?? [], 300); $res = []; $found = [];
        if ($codes) foreach (ws_all($pdo, "SELECT b.*, s.shop_name FROM ws_bottles b LEFT JOIN retail_shops s ON s.shop_id = b.shop_id WHERE b.code IN (" . ws_in($codes) . ")", $codes) as $b) $found[(string)$b['code']] = $b;
        foreach ($codes as $c) {
            $b = $found[$c] ?? null;
            if (!$b) { $res[] = ['code' => $c, 'ok' => false, 'message' => strlen($c) === 6 ? 'Not one of our wholesale codes. Check the number.' : 'A code has 6 digits.']; continue; }
            $what = $b['product'] . ' ' . $b['ml'] . ' ml';
            if ((int)$b['emp_id'] !== $t['emp_id']) { $res[] = ['code' => $c, 'ok' => false, 'what' => $what, 'message' => $what . ' — this bottle is ' . ws_emp_name($pdo, $b['emp_id']) . '\'s, not yours.']; continue; }
            if ($b['status'] === 'shop') { $res[] = ['code' => $c, 'ok' => false, 'what' => $what, 'message' => $what . ' — already given to ' . ($b['shop_name'] ?: 'a shop') . ' (' . $b['retail_order_no'] . ').']; continue; }
            $why = ['sent' => 'still "on the way": press Received on its parcel first.', 'made' => 'still at the office (not handed over yet).', 'missing' => 'marked "did not arrive" when the parcel was received. Ask the office.', 'void' => 'written off by the office.'];
            if ($b['status'] !== 'bag') { $res[] = ['code' => $c, 'ok' => false, 'what' => $what, 'message' => $what . ' — ' . ($why[$b['status']] ?? 'it is ' . $b['status'] . '.')]; continue; }
            $res[] = ['code' => $c, 'ok' => true, 'product' => $b['product'], 'ml' => $b['ml'], 'what' => $what, 'price' => ws_price($pdo, $b['ml'])];
        }
        json_response(['results' => $res]);
    }
    case 'ws.give': {               // {shop_id, codes[], type: display|sale, note} -> ONE retail bill for the shop; the bottles leave his bag
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); $in = json_input(); $t = ws_target($pdo, $g, $in['emp_id'] ?? 0);
        $shopId = (int)($in['shop_id'] ?? 0); $shop = ws_row($pdo, "SELECT shop_id, shop_name FROM retail_shops WHERE shop_id = ? AND status = 'active'", [$shopId]); if (!$shop) ws_err('Pick the shop.');
        $city = retail_shop_city($pdo, $shopId); if (!$g['admin'] && mb_strtolower($city) !== mb_strtolower($t['city'])) ws_err($shop['shop_name'] . ' is in ' . $city . ', not in your city (' . $t['city'] . ').', 403);
        $type = in_array((string)($in['type'] ?? ''), ['display', 'sale'], true) ? (string)$in['type'] : 'display';
        $codes = ws_codes($in['codes'] ?? [], 300); if (!$codes) ws_err('Type the barcode of every bottle you give.');
        $bs = ws_all($pdo, "SELECT * FROM ws_bottles WHERE code IN (" . ws_in($codes) . ")", $codes); $by = []; foreach ($bs as $b) $by[(string)$b['code']] = $b;
        $bad = []; $groups = [];
        foreach ($codes as $c) {
            $b = $by[$c] ?? null;
            if (!$b) { $bad[] = $c . ': not a wholesale code'; continue; }
            if ((int)$b['emp_id'] !== $t['emp_id'] || $b['status'] !== 'bag') { $bad[] = $c . ' (' . $b['product'] . ' ' . $b['ml'] . ' ml): ' . ($b['status'] === 'shop' ? 'already given' : ($b['status'] === 'sent' ? 'not received yet' : 'not in your bag')); continue; }
            $k = mb_strtolower($b['product']) . '|' . $b['ml'];
            if (!isset($groups[$k])) { $pr = ws_price($pdo, $b['ml']); if ($pr <= 0) ws_err('There is no price for ' . $b['ml'] . ' ml. Ask the office to set it on the Shops page (ml prices).'); $groups[$k] = ['p' => $b['product'], 'ml' => $b['ml'], 'n' => 0, 'pr' => $pr, 'ids' => []]; }
            $groups[$k]['n']++; $groups[$k]['ids'][] = (int)$b['bottle_id'];
        }
        if ($bad) json_response(['error' => 'Fix these codes first: ' . implode('; ', $bad) . '.', 'bad' => $bad], 422);
        $items = []; $qty = 0; $total = 0.0; $ids = [];
        foreach ($groups as $x) { $items[] = [$x['p'], $x['ml'] . ' ml', $x['n'], $x['n'], $x['pr'], round($x['n'] * $x['pr'], 2)]; $qty += $x['n']; $total += $x['n'] * $x['pr']; $ids = array_merge($ids, $x['ids']); }
        $total = round($total, 2); $now = ws_now(); $today = date('Y-m-d');
        $note = mb_substr('Barcodes (' . ws_emp_name($pdo, $t['emp_id']) . '): ' . implode(', ', $codes) . (trim((string)($in['note'] ?? '')) !== '' ? ' · ' . trim((string)$in['note']) : ''), 0, 255);
        $pdo->beginTransaction();
        try {
            $no = retail_next_order_no($pdo);
            $pdo->prepare("INSERT INTO retail_orders (shop_id, order_no, order_type, order_date, qty, delivered_qty, total, delivered_total, billed_total, bill_delivered, discount_type, discount_value, discount_amount, payment, pay_mode, paid_amount, due_date, status, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, '', 0, 0, 'pending', '', 0, NULL, ?, ?, ?, ?)")
                ->execute([$shopId, $no, $type, $today, $qty, $qty, $total, $total, $total, retail_delivery_status($qty, $qty), $note, $t['emp_id'] ?: null, $now]);
            $oid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO retail_order_items (order_id, product_name, ml, qty, delivered, price, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $it) $ins->execute([$oid, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5]]);
            $u = $pdo->prepare("UPDATE ws_bottles SET status = 'shop', shop_id = ?, retail_order_id = ?, retail_order_no = ?, given_at = ? WHERE status = 'bag' AND emp_id = ? AND bottle_id IN (" . implode(',', $ids) . ")");
            $u->execute([$shopId, $oid, $no, $now, $t['emp_id']]);
            if ($u->rowCount() !== count($ids)) throw new RuntimeException('changed meanwhile');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('ws.give: ' . $e->getMessage()); ws_err('The bill could not be made (nothing was saved). Try again.', 500); }
        try { retail_order_apply_paid($pdo, $oid, $shopId, 0.0, 'pending', $today, null, ''); retail_order_sync_due($pdo, $oid); retail_stock_after_order($pdo, $no, true, $type, $items, $shopId, $g['by']); } catch (Throwable $e) { error_log('ws.give ledger: ' . $e->getMessage()); }
        ws_log($pdo, $g['by'], 'give', 0, $shopId, $codes, $no . ' · ' . $qty . ' bottles · ₹' . $total);
        json_response(['ok' => true, 'order_no' => $no, 'qty' => $qty, 'total' => $total, 'lines' => array_map(function ($x) { return ['product' => $x['p'], 'ml' => $x['ml'], 'qty' => $x['n'], 'price' => $x['pr']]; }, array_values($groups)),
                       'message' => 'Bill ' . $no . ' made for ' . $shop['shop_name'] . ': ' . $qty . ' bottles, ₹' . number_format($total, 0) . '.']);
    }

    /* ---------- lookup + write-off ---------- */
    case 'ws.find': {
        $g = ws_gate($pdo); $c = ws_code($_GET['code'] ?? '');
        $b = ws_row($pdo, "SELECT b.*, s.shop_name FROM ws_bottles b LEFT JOIN retail_shops s ON s.shop_id = b.shop_id WHERE b.code = ?", [$c]); if (!$b) ws_err('Code ' . $c . ' is not a wholesale barcode.', 404);
        if (!$g['admin'] && !$g['maker'] && (int)$b['emp_id'] !== $g['emp']) ws_err('Not your bottle.', 403);
        $log = ws_all($pdo, "SELECT at, who, kind, detail FROM ws_log WHERE (order_id = ? AND kind IN ('order','make','send','receive')) OR FIND_IN_SET(?, codes) ORDER BY id LIMIT 40", [(int)$b['order_id'], $c]);
        json_response(['bottle' => ws_bottle_out($b) + ['name' => ws_emp_name($pdo, $b['emp_id']), 'shop' => (string)($b['shop_name'] ?? ''), 'note' => $b['note']], 'log' => $log]);
    }
    case 'ws.void': {               // Admin / maker: a label that is not needed (made by mistake) or a bottle that is damaged / lost
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'maker'); $in = json_input(); $codes = ws_codes($in['codes'] ?? [], 500); $why = mb_substr(trim((string)($in['reason'] ?? '')), 0, 200);
        if (!$codes) ws_err('Type the code(s).'); if (mb_strlen($why) < 3) ws_err('Write why (damaged, lost, label made by mistake ...).');
        $n = 0;
        foreach (ws_all($pdo, "SELECT * FROM ws_bottles WHERE code IN (" . ws_in($codes) . ")", $codes) as $b) {
            if (!in_array($b['status'], ['made', 'bag', 'missing', 'sent'], true)) continue;
            $pdo->prepare("UPDATE ws_bottles SET status = 'void', note = ? WHERE bottle_id = ?")->execute([$why, (int)$b['bottle_id']]); $n++;
            if ($b['status'] === 'bag') { $city = (string)ws_val($pdo, "SELECT city FROM ws_orders WHERE order_id = ?", [(int)$b['order_id']], ''); ws_city_stock($pdo, $city, $b['product'], $b['ml'], -1, 'Barcode ' . $b['code'] . ' written off: ' . $why, $g['auth']); }
        }
        ws_log($pdo, $g['by'], 'void', 0, 0, $codes, $n . ' written off: ' . $why);
        json_response(['ok' => true, 'message' => $n . ' code(s) written off.']);
    }
    case 'ws.settings_save': {
        if (!$wsPost) ws_err('Method not allowed.', 405);
        $g = ws_gate($pdo); ws_need($g, 'admin'); $in = json_input();
        if (isset($in['stock_sync'])) setting_set($pdo, 'ws_stock_sync', !empty($in['stock_sync']) ? '1' : '0');
        json_response(['ok' => true]);
    }

    default: ws_err('Unknown action.', 400);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ws: ' . $e->getMessage());
    json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
}
