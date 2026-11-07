<?php
/**
 * inventory.php - Smart Inventory: fragrance OILS, PERFUMES (400+), automatic oil calculation, ORDERS, DEALERS, alerts and reports.
 * Actions "iv.*", loaded by api.php.  Permission: the "Inventory" tick of the role (api.php action_permission: 'iv.' => 'inventory'); Admin always.
 *
 * How it works
 *   - Every oil has a stock in millilitres (iv_oils.stock_ml) and a MINIMUM. Every change of stock is a line in iv_moves (in / out / count / return / open),
 *     so "incoming, used and remaining" can always be explained.
 *   - Every perfume names its oil and the oil strength in % (default 25). Oil per bottle = bottle size x strength. A perfume may blend a second oil.
 *   - An order has lines (perfume, 20 / 50 / 100 ml, quantity). Oil is TAKEN OUT of stock when the order goes into production ("issue oil").
 *     Orders still waiting are checked in due-date order against stock + purchases on the way: the ones that cannot be covered are marked LATE,
 *     with the days until the dealer can deliver (+ the perfume's resting days).
 *   - Alerts are worked out from the live numbers (nothing is stored): out of stock, below minimum, shortage predicted, late orders, purchase overdue, payment due.
 * Nothing is deleted that has history: oils / perfumes / dealers are switched off (active = 0). PHP 7.4 safe.
 */
if (!isset($pdo, $action) || !function_exists('json_response') || !function_exists('require_auth')) { http_response_code(403); exit; }
if (strpos((string)$action, 'iv.') !== 0) { return; }

const IV_SIZES = [20, 50, 100];
const IV_STATUS = ['received' => 'Received', 'in_production' => 'In production', 'ready' => 'Ready', 'dispatched' => 'Dispatched', 'cancelled' => 'Cancelled'];

function iv_ensure(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    $flag = sys_get_temp_dir() . '/hrms_iv_tables_v1_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_suppliers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, city VARCHAR(80) NOT NULL DEFAULT '', phone VARCHAR(30) NOT NULL DEFAULT '',
            whatsapp VARCHAR(30) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '', address VARCHAR(255) NOT NULL DEFAULT '', lead_days INT NOT NULL DEFAULT 3, terms VARCHAR(80) NOT NULL DEFAULT '',
            notes VARCHAR(255) NOT NULL DEFAULT '', active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_oils (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL DEFAULT '', name VARCHAR(120) NOT NULL, supplier_id INT NOT NULL DEFAULT 0,
            stock_ml DECIMAL(12,2) NOT NULL DEFAULT 0, min_ml DECIMAL(12,2) NOT NULL DEFAULT 0, price_per_ml DECIMAL(10,2) NOT NULL DEFAULT 0, notes VARCHAR(255) NOT NULL DEFAULT '',
            active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, UNIQUE KEY uq_iv_oil_code (code), KEY idx_iv_oil_sup (supplier_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_perfumes (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL DEFAULT '', name VARCHAR(140) NOT NULL, family VARCHAR(40) NOT NULL DEFAULT '',
            oil_id INT NOT NULL DEFAULT 0, oil_pct DECIMAL(5,2) NOT NULL DEFAULT 25, oil2_id INT NOT NULL DEFAULT 0, oil2_pct DECIMAL(5,2) NOT NULL DEFAULT 0, rest_days INT NOT NULL DEFAULT 2,
            price20 DECIMAL(10,2) NOT NULL DEFAULT 0, price50 DECIMAL(10,2) NOT NULL DEFAULT 0, price100 DECIMAL(10,2) NOT NULL DEFAULT 0, notes VARCHAR(255) NOT NULL DEFAULT '',
            active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, UNIQUE KEY uq_iv_perf_code (code), KEY idx_iv_perf_oil (oil_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_moves (id INT AUTO_INCREMENT PRIMARY KEY, oil_id INT NOT NULL, kind VARCHAR(10) NOT NULL, qty_ml DECIMAL(12,2) NOT NULL, balance_ml DECIMAL(12,2) NOT NULL DEFAULT 0,
            ref VARCHAR(40) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '', by_user VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY idx_iv_mv_oil (oil_id, created_at), KEY idx_iv_mv_kind (kind, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_purchases (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(14) NOT NULL DEFAULT '', oil_id INT NOT NULL, supplier_id INT NOT NULL DEFAULT 0, qty_ml DECIMAL(12,2) NOT NULL,
            price_per_ml DECIMAL(10,2) NOT NULL DEFAULT 0, total DECIMAL(14,2) NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'ordered', ordered_at DATE NOT NULL, expected_at DATE NULL, received_at DATETIME NULL,
            paid TINYINT NOT NULL DEFAULT 0, paid_at DATETIME NULL, note VARCHAR(255) NOT NULL DEFAULT '', by_user VARCHAR(80) NOT NULL DEFAULT '', KEY idx_iv_pu_oil (oil_id, status), KEY idx_iv_pu_st (status, expected_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_orders (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL DEFAULT '', customer VARCHAR(120) NOT NULL DEFAULT '', channel VARCHAR(30) NOT NULL DEFAULT '',
            due_date DATE NOT NULL, status VARCHAR(14) NOT NULL DEFAULT 'received', source VARCHAR(10) NOT NULL DEFAULT 'manual', ext_id VARCHAR(40) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '',
            created_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, done_at DATETIME NULL, KEY idx_iv_or_st (status, due_date), UNIQUE KEY uq_iv_or_code (code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_lines (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, perfume_id INT NOT NULL, size_ml INT NOT NULL, qty INT NOT NULL, KEY idx_iv_ln_or (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS iv_settings (k VARCHAR(30) NOT NULL PRIMARY KEY, v VARCHAR(120) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('iv_ensure: ' . $e->getMessage()); return $ok = false; }
    return $ok = true;
}
/** v2: a line can use a different oil % or a fixed oil ml per bottle (with a reason). Added to an existing iv_lines; safe to run again. */
function iv_ensure2(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_iv_tables_v2_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return;
    try {
        $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iv_lines'")->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('oil_ml', $have, true)) $pdo->exec("ALTER TABLE iv_lines ADD COLUMN oil_pct DECIMAL(5,2) NULL, ADD COLUMN oil_ml DECIMAL(8,2) NULL, ADD COLUMN oil_note VARCHAR(120) NOT NULL DEFAULT ''");
        if ($have) @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('iv_ensure2: ' . $e->getMessage()); }
}
function iv_cfg(PDO $pdo, string $k)
{
    static $c = null;
    $def = ['safety_days' => '2', 'wastage_pct' => '0', 'default_pct' => '25', 'window_days' => '30', 'buy_round_ml' => '100', 'target_days' => '14', 'ship_days' => '3', 'sync_days' => '30'];
    if ($c === null) { $c = $def; try { foreach ($pdo->query("SELECT k, v FROM iv_settings")->fetchAll() as $r) $c[$r['k']] = $r['v']; } catch (Throwable $e) {} }
    return $c[$k] ?? ($def[$k] ?? '');
}
function iv_err(string $m, int $c = 422) { json_response(['error' => $m], $c); }
function iv_n($v, float $d = 0.0): float { return is_numeric($v) ? (float)$v : $d; }
function iv_day(?string $d, string $fallback = ''): string { return $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : ($fallback !== '' ? $fallback : date('Y-m-d')); }
function iv_phone(string $p): string { $d = preg_replace('/\D+/', '', $p); if (strlen($d) === 10) $d = '91' . $d; return $d; }

/** Put stock in / out and write the line. Returns the new balance. */
function iv_move(PDO $pdo, int $oilId, string $kind, float $qty, string $ref, string $note, string $by): float
{
    $pdo->prepare("UPDATE iv_oils SET stock_ml = stock_ml + ? WHERE id = ?")->execute([$qty, $oilId]);
    $b = $pdo->prepare("SELECT stock_ml FROM iv_oils WHERE id = ?"); $b->execute([$oilId]); $bal = (float)$b->fetchColumn();
    $pdo->prepare("INSERT INTO iv_moves (oil_id, kind, qty_ml, balance_ml, ref, note, by_user, created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$oilId, $kind, $qty, $bal, mb_substr($ref, 0, 40), mb_substr($note, 0, 255), mb_substr($by, 0, 80), date('Y-m-d H:i:s')]);
    return $bal;
}

/** Oil needed for one order line: [oil_id => ml]. Normal case: bottle size x the perfume's oil % (a blend splits by its two strengths).
 *  Overrides for one line (for any reason): $pct = a different total oil %, $ml = a fixed amount of oil per bottle (wins over %). Wastage % (setting, default 0) is added on top. */
function iv_need(array $p, $size, int $qty, float $wastage, float $pct = 0.0, float $ml = 0.0): array
{
    $k = 1 + $wastage / 100; $size = (float)$size;
    $p1 = (float)$p['oil_pct']; $p2 = ((int)$p['oil2_id'] > 0) ? (float)$p['oil2_pct'] : 0.0; $sum = $p1 + $p2;
    if ($ml > 0) $perBottle = $ml; else $perBottle = $size * ($pct > 0 ? $pct : $sum) / 100;
    $r1 = ($p2 > 0 && $sum > 0) ? $p1 / $sum : 1.0; $out = [];
    if ((int)$p['oil_id'] > 0) $out[(int)$p['oil_id']] = $perBottle * $qty * $k * $r1;
    if ($p2 > 0 && (int)$p['oil2_id'] > 0) $out[(int)$p['oil2_id']] = ($out[(int)$p['oil2_id']] ?? 0) + $perBottle * $qty * $k * (1 - $r1);
    return $out;
}
/** The line columns that change the oil (null / 0 = automatic). */
function iv_line_over(array $l): array { return [isset($l['oil_pct']) ? (float)$l['oil_pct'] : 0.0, isset($l['oil_ml']) ? (float)$l['oil_ml'] : 0.0]; }

/** Everything the screens need about oils in one pass: status, usage, days left, incoming purchases. */
function iv_oils_state(PDO $pdo): array
{
    $win = max(7, (int)iv_cfg($pdo, 'window_days')); $since = date('Y-m-d H:i:s', time() - $win * 86400);
    $use = []; foreach ($pdo->query("SELECT oil_id, SUM(-qty_ml) q FROM iv_moves WHERE kind = 'out' AND created_at >= " . $pdo->quote($since) . " GROUP BY oil_id")->fetchAll() as $r) $use[(int)$r['oil_id']] = (float)$r['q'];
    $inc = []; foreach ($pdo->query("SELECT oil_id, SUM(qty_ml) q, MIN(expected_at) e FROM iv_purchases WHERE status = 'ordered' GROUP BY oil_id")->fetchAll() as $r) $inc[(int)$r['oil_id']] = [(float)$r['q'], $r['e']];
    $rows = $pdo->query("SELECT o.*, s.name AS sup_name, s.phone AS sup_phone, s.whatsapp AS sup_wa, s.lead_days AS lead_d FROM iv_oils o LEFT JOIN iv_suppliers s ON s.id = o.supplier_id WHERE o.active = 1 ORDER BY o.name")->fetchAll();
    $safety = (int)iv_cfg($pdo, 'safety_days'); $out = [];
    foreach ($rows as $o) {
        $id = (int)$o['id']; $stock = (float)$o['stock_ml']; $min = (float)$o['min_ml']; $lead = $o['lead_d'] === null ? 3 : (int)$o['lead_d'];
        $used = $use[$id] ?? 0.0; $daily = $used / $win; $left = $daily > 0 ? $stock / $daily : null;
        $status = $stock <= 0 ? 'out' : ($stock <= $min ? 'low' : (($left !== null && $left < $lead + $safety + 3) ? 'watch' : 'ok'));
        $out[$id] = ['id' => $id, 'code' => $o['code'], 'name' => $o['name'], 'supplier_id' => (int)$o['supplier_id'], 'supplier' => (string)$o['sup_name'], 'phone' => (string)($o['sup_wa'] !== '' && $o['sup_wa'] !== null ? $o['sup_wa'] : $o['sup_phone']),
            'lead_days' => $lead, 'stock_ml' => $stock, 'min_ml' => $min, 'price_per_ml' => (float)$o['price_per_ml'], 'used_ml' => round($used, 1), 'daily_ml' => round($daily, 2), 'days_left' => $left === null ? null : round($left, 1),
            'incoming_ml' => $inc[$id][0] ?? 0, 'incoming_on' => $inc[$id][1] ?? null, 'reorder_ml' => (int)ceil($daily * ($lead + $safety)), 'status' => $status, 'notes' => $o['notes']];
    }
    return $out;
}
/** How much to buy so the oil lasts target_days beyond the delivery and stays above 2.5 x minimum. Rounded up to buy_round_ml. */
function iv_buy_qty(PDO $pdo, array $o, float $openShort = 0.0): int
{
    $round = max(10, (int)iv_cfg($pdo, 'buy_round_ml')); $td = max(7, (int)iv_cfg($pdo, 'target_days'));
    $target = max($o['min_ml'] * 2.5, $o['daily_ml'] * ($o['lead_days'] + (int)iv_cfg($pdo, 'safety_days') + $td)) + $openShort;
    $need = $target - $o['stock_ml'] - $o['incoming_ml'];
    return (int)max($round, ceil($need / $round) * $round);
}

/** Open orders (received) in due-date order against stock + purchases on the way. Returns one entry per order with need, status ok|late, short per oil and delay days. */
function iv_sim(PDO $pdo, array $oils): array
{
    $wast = iv_n(iv_cfg($pdo, 'wastage_pct'));
    $perf = []; foreach ($pdo->query("SELECT * FROM iv_perfumes")->fetchAll() as $p) $perf[(int)$p['id']] = $p;
    $orders = $pdo->query("SELECT * FROM iv_orders WHERE status = 'received' ORDER BY due_date, id")->fetchAll();
    $lines = []; if ($orders) foreach ($pdo->query("SELECT * FROM iv_lines WHERE order_id IN (" . implode(',', array_map(function ($o) { return (int)$o['id']; }, $orders)) . ")")->fetchAll() as $l) $lines[(int)$l['order_id']][] = $l;
    $left = []; $incoming = [];
    foreach ($oils as $id => $o) { $left[$id] = $o['stock_ml']; $incoming[$id] = ['q' => $o['incoming_ml'], 'on' => $o['incoming_on']]; }
    $res = []; $openShort = [];
    foreach ($orders as $or) {
        $need = []; $rest = 0;
        foreach ($lines[(int)$or['id']] ?? [] as $l) { $p = $perf[(int)$l['perfume_id']] ?? null; if (!$p) continue; $rest = max($rest, (int)$p['rest_days']); foreach (iv_need($p, (int)$l['size_ml'], (int)$l['qty'], $wast, ...iv_line_over($l)) as $oid => $ml) $need[$oid] = ($need[$oid] ?? 0) + $ml; }
        $short = []; $delay = 0; $ready = null;
        foreach ($need as $oid => $ml) {
            $have = $left[$oid] ?? 0; $left[$oid] = max(0, $have - $ml);
            if ($have + 0.0001 >= $ml) continue;
            $gap = $ml - max(0, $have); $short[$oid] = $gap; $openShort[$oid] = ($openShort[$oid] ?? 0) + $gap;
            $lead = isset($oils[$oid]) ? $oils[$oid]['lead_days'] : 3;
            $d = $lead;
            if (($incoming[$oid]['q'] ?? 0) >= $gap && !empty($incoming[$oid]['on'])) { $d = max(0, (int)floor((strtotime($incoming[$oid]['on']) - strtotime(date('Y-m-d'))) / 86400)); $incoming[$oid]['q'] -= $gap; }
            $delay = max($delay, $d);
        }
        $due = $or['due_date'];
        $readyDate = date('Y-m-d', strtotime('+' . ($short ? $delay + $rest : $rest) . ' days'));
        $late = $short ? true : false; $lateBy = $late ? max(0, (int)floor((strtotime($readyDate) - strtotime($due)) / 86400)) : 0;
        if (!$late && $readyDate > $due) $lateBy = (int)floor((strtotime($readyDate) - strtotime($due)) / 86400);
        $res[(int)$or['id']] = ['need' => $need, 'short' => $short, 'late' => $late || $lateBy > 0, 'blocked' => (bool)$short, 'delay_days' => $short ? $delay : 0, 'ready_by' => $readyDate, 'late_by' => $lateBy];
    }
    return ['orders' => $res, 'open_short' => $openShort];
}


/** Pulls the live Shopify orders that are not shipped yet into Inventory > Orders (once per order), so the oil need is counted from real orders.
 *  Perfume lines are matched to the perfume list by name; the bottle size is read from the product / variant title ("50 ml"). Orders cancelled or shipped in
 *  Shopify before production started are closed here too. Lines that match no perfume are reported (nothing is guessed). */
function iv_sync_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS iv_alias (title_key VARCHAR(120) NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT '', perfume_id INT NOT NULL DEFAULT 0, size_ml INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS iv_unmatched (title_key VARCHAR(120) NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT '', cnt INT NOT NULL DEFAULT 0, seen_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function iv_norm(string $t): string { return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($t)); }
function iv_core(string $t): string {   // "Azz The Most Desired - LG Inspired perfume | TMPerfumehouse" -> "Azz The Most Desired"
    $t = preg_replace('/\|.*$/u', '', $t); $t = preg_replace('/\s[-\x{2013}]\s*(?:[A-Za-z]{2,3}\s+)?inspired.*$/iu', '', $t); $t = preg_replace('/\b(inspired|perfume|eau de parfum|edp)\b/iu', ' ', $t);
    $t = preg_replace('/\d+(?:\.\d+)?\s*-?\s*ml/iu', ' ', $t); return trim(preg_replace('/\s+/', ' ', $t));
}
function iv_words(string $t): array { $o = []; foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($t)) as $w) if (strlen($w) >= 3 && !in_array($w, ['the', 'and', 'for', 'with', 'pour'], true)) $o[$w] = 1; return array_keys($o); }
function iv_sync_shopify(PDO $pdo, bool $force = false): array
{
    iv_sync_tables($pdo);
    $alias = []; foreach ($pdo->query("SELECT * FROM iv_alias")->fetchAll() as $a) $alias[$a['title_key']] = $a;
    $pwords = []; foreach ($pdo->query("SELECT id, name FROM iv_perfumes WHERE active = 1")->fetchAll() as $p) $pwords[(int)$p['id']] = iv_words((string)$p['name']);
    $now = date('Y-m-d H:i:s'); $last = (string)iv_cfg_raw($pdo, 'sync_at');
    if (!$force && $last !== '' && time() - strtotime($last) < 240) { $inf = array_pad(explode('|', iv_cfg_raw($pdo, 'sync_info')), 6, '0'); return ['skipped' => true, 'at' => $last, 'seen' => (int)$inf[0], 'open' => (int)$inf[1], 'added' => 0, 'waiting' => (int)$inf[3], 'info_only' => true]; }
    if (!function_exists('shopify_stores') || !function_exists('shopify_get_token')) return ['error' => 'Shopify link is not available on this server.'];
    $pdo->exec("DELETE FROM iv_unmatched"); $unmIns = $pdo->prepare("INSERT INTO iv_unmatched (title_key, title, cnt, seen_at) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE cnt = cnt + 1, seen_at = VALUES(seen_at)");
    $perf = []; foreach ($pdo->query("SELECT id, name FROM iv_perfumes WHERE active = 1")->fetchAll() as $p) { $n = iv_norm((string)$p['name']); if (strlen($n) >= 3) $perf[(int)$p['id']] = $n; }
    uasort($perf, function ($a, $b) { return strlen($b) - strlen($a); });
    $ship = max(0, (int)iv_cfg($pdo, 'ship_days')); $days = max(3, min(90, (int)iv_cfg($pdo, 'sync_days')));
    $added = 0; $wait = 0; $closed = 0; $seen = 0; $open = 0; $unm = []; $errs = [];
    $find = $pdo->prepare("SELECT id, status FROM iv_orders WHERE ext_id = ? LIMIT 1");
    $insO = $pdo->prepare("INSERT INTO iv_orders (code, customer, channel, due_date, status, source, ext_id, note, created_by, created_at) VALUES (?,?,?,?,'received','shopify',?,?,'auto',?)");
    $insL = $pdo->prepare("INSERT INTO iv_lines (order_id, perfume_id, size_ml, qty) VALUES (?,?,?,?)");
    $re = '/(?<![0-9.])(\d{1,4}(?:\.\d+)?)\s*-?\s*ml(?![a-z])/i';
    foreach (shopify_stores() as $key => $store) {
        $tok = shopify_get_token((string)$key, $store); if (is_array($tok)) { $errs[] = $tok['error'] ?? 'no token'; continue; }
        $url = (defined('IV_TEST_BASE') ? IV_TEST_BASE : "https://{$store['domain']}") . "/admin/api/2024-10/orders.json?" . http_build_query(['status' => 'any', 'limit' => 250, 'created_at_min' => date('c', strtotime("-$days day")),
            'fields' => 'id,name,created_at,cancelled_at,fulfillment_status,customer,line_items']);
        for ($page = 0; $page < 4 && $url; $page++) {
            $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $tok], CURLOPT_TIMEOUT => 25]);
            $raw = curl_exec($ch); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($raw === false || $code !== 200) { $errs[] = ($store['label'] ?? $key) . ': Shopify answered HTTP ' . $code; break; }
            $head = substr($raw, 0, $hs); $j = json_decode(substr($raw, $hs), true); $url = (preg_match('/<([^>]+)>;\s*rel="next"/', $head, $m)) ? $m[1] : '';
            foreach ((array)($j['orders'] ?? []) as $o) {
                $seen++; $ext = substr($key . '|' . $o['id'], 0, 40); $find->execute([$ext]); $ex = $find->fetch();
                $cancel = !empty($o['cancelled_at']); $done = ($o['fulfillment_status'] ?? '') === 'fulfilled';
                if ($ex) { if ($ex['status'] === 'received' && ($cancel || $done)) { $pdo->prepare("UPDATE iv_orders SET status = ?, done_at = ? WHERE id = ?")->execute([$cancel ? 'cancelled' : 'dispatched', $now, (int)$ex['id']]); $closed++; } continue; }
                if (!$cancel && !$done) $open++;
                if ($cancel || $done) continue;   // never started here and already closed in Shopify: nothing to produce
                $lines = []; $miss = [];
                foreach ((array)($o['line_items'] ?? []) as $li) {
                    $rawTitle = (string)($li['title'] ?? ''); $title = $rawTitle . ' ' . (string)($li['name'] ?? ''); $var = (string)($li['variant_title'] ?? '');
                    if (function_exists('order_line_seg') && order_line_seg($title . ' ' . $var) !== 'perfume') continue;
                    if (preg_match('/complimentary|free gift|\bgift\b|sample/i', $rawTitle) || (isset($li['price']) && (float)$li['price'] <= 0)) continue;   // free gifts / samples take no production oil
                    $core = iv_core($rawTitle); $key = substr(iv_norm($core !== '' ? $core : $rawTitle), 0, 120); $al = $alias[$key] ?? null;
                    $size = 0.0; foreach ([$var, $rawTitle, (string)($li['name'] ?? '')] as $t) { if ($t !== '' && preg_match($re, $t, $mm)) { $size = (float)$mm[1]; break; } }
                    if ($size < 1 && $al && (int)$al['size_ml'] > 0) $size = (float)$al['size_ml'];
                    $qty = max(1, (int)($li['quantity'] ?? 1) - (int)($li['fulfilled_quantity'] ?? 0));
                    $pid = 0;
                    if ($al && (int)$al['perfume_id'] > 0) $pid = (int)$al['perfume_id'];
                    else {
                        $nt = iv_norm($core !== '' ? $core : $title); foreach ($perf as $id => $n) { if (strpos($nt, $n) !== false) { $pid = $id; break; } }
                        if (!$pid) { $cw = iv_words($core); if (count($cw) >= 2) foreach ($pwords as $id => $pw) { if (count($pw) >= 2 && !array_diff($pw, $cw)) { $pid = $id; break; } } }
                    }
                    if (!$pid) { $miss[] = trim($rawTitle); try { $unmIns->execute([$key, mb_substr(trim($rawTitle), 0, 255), $now]); } catch (Throwable $e) {} continue; }
                    if ($size < 1 || $size > 2000) { $miss[] = trim($rawTitle) . ' (no ml size)'; try { $unmIns->execute([$key, mb_substr(trim($rawTitle), 0, 255), $now]); } catch (Throwable $e) {} continue; }
                    $lines[] = [$pid, (int)round($size), $qty];
                }
                foreach ($miss as $mname) $unm[$mname] = ($unm[$mname] ?? 0) + 1;
                if ($miss) { $wait++; continue; }   // an order is added only when EVERY perfume line is matched, so it is never counted with oil missing; it comes in as soon as the products are matched
                if (!$lines) continue;
                $cust = trim((string)(($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? ''))); $created = substr(str_replace('T', ' ', (string)$o['created_at']), 0, 10);
                $due = date('Y-m-d', strtotime(($created ?: date('Y-m-d')) . " +$ship day")); if ($due < date('Y-m-d')) $due = date('Y-m-d');
                $code = 'SH' . preg_replace('/\D+/', '', (string)($o['name'] ?? '')); if ($code === 'SH') $code = 'SH' . $o['id'];
                $code = substr($code, 0, 20);
                try {
                    $pdo->beginTransaction();
                    try { $insO->execute([$code, mb_substr($cust, 0, 120), 'Shopify', $due, $ext, '', $now]); }
                    catch (Throwable $e) { $insO->execute([substr($code, 0, 14) . '-' . substr((string)$o['id'], -4), mb_substr($cust, 0, 120), 'Shopify', $due, $ext, '', $now]); }
                    $oid = (int)$pdo->lastInsertId(); foreach ($lines as $l) $insL->execute([$oid, $l[0], $l[1], $l[2]]); $pdo->commit(); $added++;
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errs[] = 'order ' . ($o['name'] ?? '') . ': ' . $e->getMessage(); }
            }
        }
    }
    try { $pdo->prepare("REPLACE INTO iv_settings (k, v) VALUES ('sync_at', ?)")->execute([$now]); $pdo->prepare("REPLACE INTO iv_settings (k, v) VALUES ('sync_info', ?)")->execute([$seen . '|' . $open . '|' . $added . '|' . $wait . '|' . $closed]); } catch (Throwable $e) {}
    arsort($unm); return ['added' => $added, 'seen' => $seen, 'open' => $open, 'waiting' => $wait, 'closed' => $closed, 'seen' => $seen, 'unmatched' => array_slice(array_map(function ($n, $c) { return ['name' => $n, 'count' => $c]; }, array_keys($unm), $unm), 0, 12), 'errors' => array_slice($errs, 0, 3), 'at' => $now];
}
function iv_cfg_raw(PDO $pdo, string $k): string { try { $s = $pdo->prepare("SELECT v FROM iv_settings WHERE k = ?"); $s->execute([$k]); return (string)$s->fetchColumn(); } catch (Throwable $e) { return ''; } }

function iv_order_rows(PDO $pdo, array $where = [], array $args = []): array
{
    $perf = []; foreach ($pdo->query("SELECT id, code, name, oil_id FROM iv_perfumes")->fetchAll() as $p) $perf[(int)$p['id']] = $p;
    $sql = "SELECT * FROM iv_orders" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY FIELD(status,'received','in_production','ready','dispatched','cancelled'), due_date, id DESC LIMIT 400";
    $st = $pdo->prepare($sql); $st->execute($args); $orders = $st->fetchAll(); if (!$orders) return [];
    $ids = implode(',', array_map(function ($o) { return (int)$o['id']; }, $orders)); $lines = [];
    foreach ($pdo->query("SELECT * FROM iv_lines WHERE order_id IN ($ids)")->fetchAll() as $l) { $p = $perf[(int)$l['perfume_id']] ?? null; $lines[(int)$l['order_id']][] = ['id' => (int)$l['id'], 'perfume_id' => (int)$l['perfume_id'], 'perfume' => $p ? $p['name'] : '(deleted)', 'code' => $p ? $p['code'] : '', 'size_ml' => (int)$l['size_ml'], 'qty' => (int)$l['qty'], 'oil_pct' => $l['oil_pct'] === null ? null : (float)$l['oil_pct'], 'oil_ml' => $l['oil_ml'] === null ? null : (float)$l['oil_ml'], 'oil_note' => (string)($l['oil_note'] ?? '')]; }
    foreach ($orders as &$o) { $o['id'] = (int)$o['id']; $o['lines'] = $lines[$o['id']] ?? []; $o['status_label'] = IV_STATUS[$o['status']] ?? $o['status']; } unset($o);
    return $orders;
}

function iv_alerts(PDO $pdo, array $oils, array $sim): array
{
    $A = []; $today = date('Y-m-d');
    foreach ($oils as $o) {
        $buy = iv_buy_qty($pdo, $o, (float)($sim['open_short'][$o['id']] ?? 0));
        $n = (int)$pdo->query("SELECT COUNT(*) FROM iv_perfumes WHERE active = 1 AND (oil_id = {$o['id']} OR oil2_id = {$o['id']})")->fetchColumn();
        if ($o['status'] === 'out') $A[] = ['sev' => 'bad', 'type' => 'out', 'oil_id' => $o['id'], 'title' => $o['name'] . ' is out of stock', 'text' => $n . ' perfumes depend on it.' . ($o['incoming_ml'] > 0 ? ' A delivery of ' . round($o['incoming_ml']) . ' ml is on the way.' : ''), 'buy_ml' => $buy];
        elseif ($o['status'] === 'low') $A[] = ['sev' => 'bad', 'type' => 'low', 'oil_id' => $o['id'], 'title' => $o['name'] . ' is below minimum', 'text' => round($o['stock_ml']) . ' ml left, minimum ' . round($o['min_ml']) . ' ml.' . ($o['days_left'] !== null ? ' About ' . max(1, (int)floor($o['days_left'])) . ' days at the current pace.' : ''), 'buy_ml' => $buy];
        elseif ($o['status'] === 'watch') $A[] = ['sev' => 'info', 'type' => 'predict', 'oil_id' => $o['id'], 'title' => $o['name'] . ': shortage predicted in ' . (int)floor((float)$o['days_left']) . ' days', 'text' => 'Dealer delivers in ' . $o['lead_days'] . ' days. Order by ' . date('d M', strtotime('+' . max(0, (int)floor((float)$o['days_left']) - $o['lead_days'] - (int)iv_cfg($pdo, 'safety_days')) . ' days')) . ' so it arrives in time.', 'buy_ml' => $buy];
    }
    foreach ($sim['orders'] as $id => $s) if ($s['late']) {
        $or = $pdo->query("SELECT code, customer, due_date FROM iv_orders WHERE id = " . (int)$id)->fetch();
        $names = []; foreach ($s['short'] as $oid => $gap) $names[] = ($oils[$oid]['name'] ?? 'oil') . ' short by ' . round($gap) . ' ml';
        $A[] = ['sev' => 'warn', 'type' => 'late', 'order_id' => $id, 'title' => 'Order ' . $or['code'] . ' will be about ' . max(1, $s['late_by']) . ' days late', 'text' => ($names ? implode(', ', $names) . '. ' : 'Resting time pushes it past the due date. ') . 'Due ' . date('d M', strtotime($or['due_date'])) . ', ready about ' . date('d M', strtotime($s['ready_by'])) . '.'];
    }
    foreach ($pdo->query("SELECT p.*, o.name oil FROM iv_purchases p JOIN iv_oils o ON o.id = p.oil_id WHERE p.status = 'ordered' AND p.expected_at < " . $pdo->quote($today))->fetchAll() as $p)
        $A[] = ['sev' => 'warn', 'type' => 'overdue', 'purchase_id' => (int)$p['id'], 'oil_id' => (int)$p['oil_id'], 'title' => 'Delivery overdue: ' . $p['oil'], 'text' => 'Expected ' . date('d M', strtotime($p['expected_at'])) . ' (' . round($p['qty_ml']) . ' ml). Call the dealer.'];
    foreach ($pdo->query("SELECT p.*, o.name oil FROM iv_purchases p JOIN iv_oils o ON o.id = p.oil_id WHERE p.status = 'received' AND p.paid = 0 AND p.received_at < " . $pdo->quote(date('Y-m-d H:i:s', time() - 7 * 86400)) . " LIMIT 10")->fetchAll() as $p)
        $A[] = ['sev' => 'info', 'type' => 'payment', 'purchase_id' => (int)$p['id'], 'title' => 'Payment due: ' . $p['oil'], 'text' => 'Received ' . date('d M', strtotime($p['received_at'])) . ', ' . number_format((float)$p['total']) . ' not marked paid.'];
    $rank = ['bad' => 0, 'warn' => 1, 'info' => 2]; usort($A, function ($a, $b) use ($rank) { return $rank[$a['sev']] <=> $rank[$b['sev']]; });
    return $A;
}

/* ------------------------------------------------------------------------------------------------------------------------------ */
iv_ensure($pdo); iv_ensure2($pdo);
$auth = require_auth();
if (($auth['role'] ?? '') !== 'Admin' && !user_can($auth, 'inventory')) json_response(['error' => 'Your role does not have Inventory access.'], 403);
$by = (string)($auth['username'] ?? 'user');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? json_input() : [];
$post = function () use ($isPost) { if (!$isPost) iv_err('Method not allowed.', 405); };
$now = date('Y-m-d H:i:s'); $today = date('Y-m-d');
$page = function ($default = 25) { $l = (int)($_GET['limit'] ?? $default); return [max(1, min(200, $l)), max(0, (int)($_GET['offset'] ?? 0))]; };

try {
switch ($action) {

    case 'iv.dash': {
        $oils = iv_oils_state($pdo); $sim = iv_sim($pdo, $oils); $A = iv_alerts($pdo, $oils, $sim);
        $tot = 0; $val = 0; $c = ['out' => 0, 'low' => 0, 'watch' => 0, 'ok' => 0];
        foreach ($oils as $o) { $tot += $o['stock_ml']; $val += $o['stock_ml'] * $o['price_per_ml']; $c[$o['status']]++; }
        $openN = (int)$pdo->query("SELECT COUNT(*) FROM iv_orders WHERE status IN ('received','in_production','ready')")->fetchColumn();
        $lateN = 0; foreach ($sim['orders'] as $s) if ($s['late']) $lateN++;
        $perfN = (int)$pdo->query("SELECT COUNT(*) FROM iv_perfumes WHERE active = 1")->fetchColumn();
        $buy = []; foreach ($oils as $o) if (in_array($o['status'], ['out', 'low'], true)) $buy[] = ['oil_id' => $o['id'], 'oil' => $o['name'], 'supplier' => $o['supplier'], 'stock_ml' => $o['stock_ml'], 'qty_ml' => iv_buy_qty($pdo, $o, (float)($sim['open_short'][$o['id']] ?? 0)), 'est' => round(iv_buy_qty($pdo, $o, (float)($sim['open_short'][$o['id']] ?? 0)) * $o['price_per_ml'])];
        $late = []; $ordersAll = iv_order_rows($pdo, ["status = 'received'"]);
        foreach ($ordersAll as $o) { $s = $sim['orders'][$o['id']] ?? null; if ($s && $s['late']) { $o['sim'] = $s; $late[] = $o; } }
        $recent = $pdo->query("SELECT m.*, o.name oil FROM iv_moves m JOIN iv_oils o ON o.id = m.oil_id ORDER BY m.id DESC LIMIT 8")->fetchAll();
        json_response(['kpi' => ['total_ml' => $tot, 'value' => round($val), 'oils' => count($oils), 'perfumes' => $perfN, 'counts' => $c, 'open_orders' => $openN, 'late_orders' => $lateN, 'alerts' => count($A)],
            'alerts' => array_slice($A, 0, 6), 'buy' => $buy, 'late' => array_slice($late, 0, 6), 'recent' => $recent]);
        break;
    }
    case 'iv.alerts': {
        $oils = iv_oils_state($pdo); $sim = iv_sim($pdo, $oils);
        json_response(['alerts' => iv_alerts($pdo, $oils, $sim)]);
        break;
    }

    /* ---------------- oils ---------------- */
    case 'iv.oils': {
        $oils = array_values(iv_oils_state($pdo)); $q = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $f = (string)($_GET['f'] ?? '');
        $oils = array_values(array_filter($oils, function ($o) use ($q, $f) {
            if ($q !== '' && mb_strpos(mb_strtolower($o['name'] . ' ' . $o['code'] . ' ' . $o['supplier']), $q) === false) return false;
            if ($f === 'action') return in_array($o['status'], ['out', 'low'], true); if ($f === 'watch') return $o['status'] === 'watch'; if ($f === 'ok') return $o['status'] === 'ok'; return true; }));
        [$lim, $off] = $page(40); $total = count($oils);
        json_response(['oils' => array_slice($oils, $off, $lim), 'total' => $total]);
        break;
    }
    case 'iv.oil_get': {
        $id = (int)($_GET['id'] ?? 0); $oils = iv_oils_state($pdo); $o = $oils[$id] ?? null; if (!$o) iv_err('Oil not found.', 404);
        $mv = $pdo->prepare("SELECT * FROM iv_moves WHERE oil_id = ? ORDER BY id DESC LIMIT 25"); $mv->execute([$id]);
        $pf = $pdo->prepare("SELECT id, code, name, oil_pct, oil_id, oil2_id, oil2_pct FROM iv_perfumes WHERE active = 1 AND (oil_id = ? OR oil2_id = ?) ORDER BY name LIMIT 60"); $pf->execute([$id, $id]);
        $pu = $pdo->prepare("SELECT * FROM iv_purchases WHERE oil_id = ? ORDER BY id DESC LIMIT 15"); $pu->execute([$id]);
        json_response(['oil' => $o, 'moves' => $mv->fetchAll(), 'perfumes' => $pf->fetchAll(), 'purchases' => $pu->fetchAll(), 'buy_ml' => iv_buy_qty($pdo, $o)]);
        break;
    }
    case 'iv.oil_save': {
        $post(); $id = (int)($in['id'] ?? 0); $name = trim((string)($in['name'] ?? '')); if ($name === '') iv_err('Give the oil a name.');
        $sup = (int)($in['supplier_id'] ?? 0); $min = max(0, iv_n($in['min_ml'] ?? 0)); $price = max(0, iv_n($in['price_per_ml'] ?? 0)); $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 255);
        $code = strtoupper(trim((string)($in['code'] ?? '')));
        if ($id) {
            if ($code === '') { $c = $pdo->prepare("SELECT code FROM iv_oils WHERE id = ?"); $c->execute([$id]); $code = (string)$c->fetchColumn(); }
            $pdo->prepare("UPDATE iv_oils SET name = ?, code = ?, supplier_id = ?, min_ml = ?, price_per_ml = ?, notes = ? WHERE id = ?")->execute([$name, $code, $sup, $min, $price, $notes, $id]);
        } else {
            if ($code === '') $code = 'OIL-' . str_pad((string)((int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM iv_oils")->fetchColumn()), 3, '0', STR_PAD_LEFT);
            $dup = $pdo->prepare("SELECT id FROM iv_oils WHERE code = ? OR (name = ? AND active = 1)"); $dup->execute([$code, $name]); if ($dup->fetchColumn()) iv_err('An oil with this name or code already exists.');
            $pdo->prepare("INSERT INTO iv_oils (code, name, supplier_id, stock_ml, min_ml, price_per_ml, notes, active, created_at) VALUES (?,?,?,?,?,?,?,1,?)")->execute([$code, $name, $sup, 0, $min, $price, $notes, $now]);
            $id = (int)$pdo->lastInsertId(); $open = max(0, iv_n($in['stock_ml'] ?? 0)); if ($open > 0) iv_move($pdo, $id, 'open', $open, '', 'Opening stock', $by);
        }
        json_response(['ok' => true, 'id' => $id]);
        break;
    }
    case 'iv.oil_off': {
        $post(); $id = (int)($in['id'] ?? 0); $n = (int)$pdo->query("SELECT COUNT(*) FROM iv_perfumes WHERE active = 1 AND (oil_id = $id OR oil2_id = $id)")->fetchColumn();
        if ($n > 0) iv_err($n . ' perfumes still use this oil. Change their oil first.');
        $pdo->prepare("UPDATE iv_oils SET active = 0 WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        break;
    }
    case 'iv.receive': {   // a delivery arrived (against an order placed here, or typed directly)
        $post(); $oil = (int)($in['oil_id'] ?? 0); $qty = iv_n($in['qty_ml'] ?? 0); if ($oil <= 0 || $qty <= 0) iv_err('Choose the oil and the quantity received.');
        $o = $pdo->prepare("SELECT * FROM iv_oils WHERE id = ?"); $o->execute([$oil]); $oilRow = $o->fetch(); if (!$oilRow) iv_err('Oil not found.', 404);
        $price = iv_n($in['price_per_ml'] ?? 0, (float)$oilRow['price_per_ml']); $pid = (int)($in['purchase_id'] ?? 0); $sup = (int)($in['supplier_id'] ?? $oilRow['supplier_id']);
        $pdo->beginTransaction();
        if ($pid) {
            $p = $pdo->prepare("SELECT * FROM iv_purchases WHERE id = ? AND status = 'ordered'"); $p->execute([$pid]); $pr = $p->fetch(); if (!$pr) { $pdo->rollBack(); iv_err('That purchase is not open any more.'); }
            $pdo->prepare("UPDATE iv_purchases SET status = 'received', received_at = ?, qty_ml = ?, price_per_ml = ?, total = ? WHERE id = ?")->execute([$now, $qty, $price, round($qty * $price, 2), $pid]); $code = $pr['code'];
        } else {
            $code = 'PU-' . date('ymd') . '-' . str_pad((string)((int)$pdo->query("SELECT COUNT(*) FROM iv_purchases WHERE ordered_at = '" . date('Y-m-d') . "'")->fetchColumn() + 1), 2, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO iv_purchases (code, oil_id, supplier_id, qty_ml, price_per_ml, total, status, ordered_at, expected_at, received_at, paid, note, by_user) VALUES (?,?,?,?,?,?,'received',?,?,?,0,?,?)")
                ->execute([$code, $oil, $sup, $qty, $price, round($qty * $price, 2), $today, $today, $now, mb_substr((string)($in['note'] ?? ''), 0, 255), $by]);
        }
        if ($price > 0) $pdo->prepare("UPDATE iv_oils SET price_per_ml = ? WHERE id = ?")->execute([$price, $oil]);
        $bal = iv_move($pdo, $oil, 'in', $qty, $code, trim('Delivery' . ((string)($in['note'] ?? '') !== '' ? ': ' . $in['note'] : '')), $by);
        $pdo->commit(); json_response(['ok' => true, 'balance_ml' => $bal, 'purchase' => $code]);
        break;
    }
    case 'iv.adjust': {   // physical count: type what is really in the bottle; the difference is saved
        $post(); $oil = (int)($in['oil_id'] ?? 0); $real = iv_n($in['real_ml'] ?? -1, -1); if ($oil <= 0 || $real < 0) iv_err('Choose the oil and type the counted quantity.');
        $cur = $pdo->prepare("SELECT stock_ml FROM iv_oils WHERE id = ?"); $cur->execute([$oil]); $c = $cur->fetchColumn(); if ($c === false) iv_err('Oil not found.', 404);
        $diff = round($real - (float)$c, 2); if (abs($diff) < 0.01) json_response(['ok' => true, 'diff' => 0]);
        $bal = iv_move($pdo, $oil, 'count', $diff, '', 'Physical count' . ((string)($in['note'] ?? '') !== '' ? ': ' . $in['note'] : ''), $by);
        json_response(['ok' => true, 'diff' => $diff, 'balance_ml' => $bal]);
        break;
    }

    /* ---------------- perfumes ---------------- */
    case 'iv.perfumes': {
        $oils = iv_oils_state($pdo); $q = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $fam = (string)($_GET['family'] ?? ''); $f = (string)($_GET['f'] ?? ''); $so = (string)($_GET['sort'] ?? 'name');
        $rows = []; foreach ($pdo->query("SELECT * FROM iv_perfumes WHERE active = 1 ORDER BY name")->fetchAll() as $p) {
            if ($q !== '' && mb_strpos(mb_strtolower($p['name'] . ' ' . $p['code']), $q) === false) continue; if ($fam !== '' && $p['family'] !== $fam) continue;
            $cap = []; foreach (IV_SIZES as $s) { $n = iv_need($p, $s, 1, iv_n(iv_cfg($pdo, 'wastage_pct'))); $m = null; foreach ($n as $oid => $ml) { $c = $ml > 0 ? (int)floor(($oils[$oid]['stock_ml'] ?? 0) / $ml) : 0; $m = $m === null ? $c : min($m, $c); } $cap[$s] = $m === null ? 0 : $m; }
            if ($f === 'short' && $cap[50] >= 20) continue; if ($f === 'out' && $cap[50] > 0) continue;
            $p['oil'] = $oils[(int)$p['oil_id']]['name'] ?? '(no oil)'; $p['oil2'] = $oils[(int)$p['oil2_id']]['name'] ?? ''; $p['cap'] = $cap;
            $p['need'] = []; foreach (IV_SIZES as $s) $p['need'][$s] = round($s * (float)$p['oil_pct'] / 100 + ((int)$p['oil2_id'] ? $s * (float)$p['oil2_pct'] / 100 : 0), 2);
            $rows[] = $p;
        }
        usort($rows, function ($a, $b) use ($so) { return $so === 'cap' ? $a['cap'][50] <=> $b['cap'][50] : strcasecmp($a['name'], $b['name']); });
        [$lim, $off] = $page(30); $fams = $pdo->query("SELECT DISTINCT family FROM iv_perfumes WHERE active = 1 AND family <> '' ORDER BY family")->fetchAll(PDO::FETCH_COLUMN);
        json_response(['perfumes' => array_slice($rows, $off, $lim), 'total' => count($rows), 'families' => $fams]);
        break;
    }
    case 'iv.perf_save': {
        $post(); $id = (int)($in['id'] ?? 0); $name = trim((string)($in['name'] ?? '')); if ($name === '') iv_err('Give the perfume a name.');
        $oil = (int)($in['oil_id'] ?? 0); if ($oil <= 0) iv_err('Choose the oil this perfume uses.');
        $pct = iv_n($in['oil_pct'] ?? iv_cfg($pdo, 'default_pct'), 25); if ($pct <= 0 || $pct > 60) iv_err('Oil strength must be between 1 and 60 %.');
        $oil2 = (int)($in['oil2_id'] ?? 0); $pct2 = $oil2 ? iv_n($in['oil2_pct'] ?? 0) : 0; if ($oil2 && ($pct2 <= 0 || $pct + $pct2 > 60)) iv_err('The second oil needs its own strength; both together can be at most 60 %.');
        $f = ['family' => mb_substr(trim((string)($in['family'] ?? '')), 0, 40), 'rest' => max(0, min(30, (int)($in['rest_days'] ?? 2))), 'p20' => iv_n($in['price20'] ?? 0), 'p50' => iv_n($in['price50'] ?? 0), 'p100' => iv_n($in['price100'] ?? 0)];
        $code = strtoupper(trim((string)($in['code'] ?? '')));
        if ($id) {
            if ($code === '') { $c = $pdo->prepare("SELECT code FROM iv_perfumes WHERE id = ?"); $c->execute([$id]); $code = (string)$c->fetchColumn(); }
            $pdo->prepare("UPDATE iv_perfumes SET code = ?, name = ?, family = ?, oil_id = ?, oil_pct = ?, oil2_id = ?, oil2_pct = ?, rest_days = ?, price20 = ?, price50 = ?, price100 = ? WHERE id = ?")->execute([$code, $name, $f['family'], $oil, $pct, $oil2, $pct2, $f['rest'], $f['p20'], $f['p50'], $f['p100'], $id]);
        } else {
            if ($code === '') $code = 'P' . str_pad((string)((int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM iv_perfumes")->fetchColumn()), 3, '0', STR_PAD_LEFT);
            $dup = $pdo->prepare("SELECT id FROM iv_perfumes WHERE code = ?"); $dup->execute([$code]); if ($dup->fetchColumn()) iv_err('A perfume with this code already exists.');
            $pdo->prepare("INSERT INTO iv_perfumes (code, name, family, oil_id, oil_pct, oil2_id, oil2_pct, rest_days, price20, price50, price100, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)")->execute([$code, $name, $f['family'], $oil, $pct, $oil2, $pct2, $f['rest'], $f['p20'], $f['p50'], $f['p100'], $now]);
            $id = (int)$pdo->lastInsertId();
        }
        json_response(['ok' => true, 'id' => $id]);
        break;
    }
    case 'iv.perf_off': { $post(); $pdo->prepare("UPDATE iv_perfumes SET active = 0 WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true]); break; }
    case 'iv.perf_import': {   // paste many perfumes at once: [{name, oil (name or code), pct, family, rest_days}]
        $post(); $rows = $in['rows'] ?? []; if (!is_array($rows) || !$rows) iv_err('Nothing to import.'); if (count($rows) > 1000) iv_err('At most 1000 rows at a time.');
        $oilBy = []; foreach ($pdo->query("SELECT id, code, name FROM iv_oils WHERE active = 1")->fetchAll() as $o) { $oilBy[mb_strtolower($o['name'])] = (int)$o['id']; $oilBy[mb_strtolower($o['code'])] = (int)$o['id']; }
        $have = []; foreach ($pdo->query("SELECT name FROM iv_perfumes WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN) as $n) $have[mb_strtolower($n)] = 1;
        $ins = $pdo->prepare("INSERT INTO iv_perfumes (code, name, family, oil_id, oil_pct, rest_days, active, created_at) VALUES (?,?,?,?,?,?,1,?)"); $next = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM iv_perfumes")->fetchColumn();
        $added = 0; $skip = [];
        foreach ($rows as $i => $r) {
            $name = trim((string)($r['name'] ?? '')); if ($name === '') continue; $key = mb_strtolower($name);
            if (isset($have[$key])) { $skip[] = $name . ' (already exists)'; continue; }
            $oid = $oilBy[mb_strtolower(trim((string)($r['oil'] ?? '')))] ?? 0; if (!$oid) { $skip[] = $name . ' (oil "' . ($r['oil'] ?? '') . '" not found)'; continue; }
            $pct = iv_n($r['pct'] ?? iv_cfg($pdo, 'default_pct'), 25); if ($pct <= 0 || $pct > 60) $pct = 25; $next++;
            $ins->execute(['P' . str_pad((string)$next, 3, '0', STR_PAD_LEFT), mb_substr($name, 0, 140), mb_substr(trim((string)($r['family'] ?? '')), 0, 40), $oid, $pct, max(0, min(30, (int)($r['rest_days'] ?? 2))), $now]); $have[$key] = 1; $added++;
        }
        json_response(['ok' => true, 'added' => $added, 'skipped' => array_slice($skip, 0, 40), 'skipped_n' => count($skip)]);
        break;
    }

    /* ---------------- the oil calculator ---------------- */
    case 'iv.calc': {   // any bottle size; a perfume is optional (then choose the oil, or just see the oil needed); a line can use its own oil % or fixed oil ml per bottle
        $oils = iv_oils_state($pdo); $wast = iv_n(iv_cfg($pdo, 'wastage_pct')); $lines = $in['lines'] ?? null; $defPct = iv_n(iv_cfg($pdo, 'default_pct'), 25);
        if (!is_array($lines)) $lines = [['perfume_id' => (int)($_GET['perfume_id'] ?? 0), 'size_ml' => (int)($_GET['size_ml'] ?? 50), 'qty' => (int)($_GET['qty'] ?? 1)]];
        $need = []; $outLines = []; $rest = 0; $noOil = 0.0;
        foreach ($lines as $l) {
            $size = (float)($l['size_ml'] ?? 0); $qty = max(1, (int)($l['qty'] ?? 1)); if ($size < 1 || $size > 2000) iv_err('Bottle size must be between 1 and 2000 ml.');
            $opct = iv_n($l['oil_pct'] ?? 0); $oml = iv_n($l['oil_ml'] ?? 0); if ($opct < 0 || $opct > 100 || $oml < 0 || $oml > 2000) iv_err('Oil % must be 0 to 100 and oil ml per bottle 0 to 2000.');
            $pid = (int)($l['perfume_id'] ?? 0); $pr = null; $name = 'No perfume chosen';
            if ($pid > 0) { $q = $pdo->prepare("SELECT * FROM iv_perfumes WHERE id = ?"); $q->execute([$pid]); $pr = $q->fetch(); if (!$pr) continue; $name = $pr['name']; }
            else { $pr = ['oil_id' => (int)($l['oil_id'] ?? 0), 'oil_pct' => $defPct, 'oil2_id' => 0, 'oil2_pct' => 0, 'rest_days' => 0]; }
            $rest = max($rest, (int)$pr['rest_days']);
            $auto = $size * ($pr['oil2_id'] ? (float)$pr['oil_pct'] + (float)$pr['oil2_pct'] : (float)$pr['oil_pct']) / 100; $perBottle = $oml > 0 ? $oml : ($opct > 0 ? $size * $opct / 100 : $auto);
            $k = 1 + $wast / 100; $n = iv_need($pr, $size, $qty, $wast, $opct, $oml);
            if (!$n) $noOil += $perBottle * $qty * $k; foreach ($n as $oid => $ml) $need[$oid] = ($need[$oid] ?? 0) + $ml;
            $outLines[] = ['perfume' => $name, 'size_ml' => $size, 'qty' => $qty, 'oil_per_bottle' => round($perBottle, 2), 'auto_per_bottle' => round($auto, 2), 'custom' => ($opct > 0 || $oml > 0), 'total_ml' => round($perBottle * $qty * $k, 2)];
        }
        if (!$outLines) iv_err('Add at least one line.');
        $res = []; $ok = true; $delay = 0;
        foreach ($need as $oid => $ml) { $o = $oils[$oid] ?? null; if (!$o) continue; $after = $o['stock_ml'] - $ml; $short = $after < 0 ? -$after : 0; if ($short > 0) { $ok = false; $delay = max($delay, $o['lead_days']); }
            $res[] = ['oil_id' => $oid, 'oil' => $o['name'], 'need_ml' => round($ml, 2), 'stock_ml' => $o['stock_ml'], 'after_ml' => round($after, 2), 'short_ml' => round($short, 2), 'below_min' => $after >= 0 && $after < $o['min_ml'], 'min_ml' => $o['min_ml'], 'supplier' => $o['supplier'], 'lead_days' => $o['lead_days'], 'buy_ml' => $after < $o['min_ml'] ? iv_buy_qty($pdo, array_merge($o, ['stock_ml' => max(0, $after)])) : 0]; }
        json_response(['lines' => $outLines, 'oils' => $res, 'enough' => $ok, 'no_oil_ml' => round($noOil, 2), 'total_oil_ml' => round(array_sum($need) + $noOil, 2), 'ready_by' => date('Y-m-d', strtotime('+' . ($ok ? $rest : $delay + $rest) . ' days')), 'rest_days' => $rest]);
        break;
    }

    /* ---------------- orders ---------------- */
    case 'iv.orders': {
        $oils = iv_oils_state($pdo); $sim = iv_sim($pdo, $oils); $q = trim((string)($_GET['q'] ?? '')); $st = (string)($_GET['status'] ?? 'open'); $w = []; $a = [];
        if ($st === 'open') $w[] = "status IN ('received','in_production','ready')"; elseif (isset(IV_STATUS[$st])) { $w[] = 'status = ?'; $a[] = $st; }
        if ($q !== '') { $w[] = "(code LIKE ? OR customer LIKE ?)"; $a[] = "%$q%"; $a[] = "%$q%"; }
        $rows = iv_order_rows($pdo, $w, $a); $wast = iv_n(iv_cfg($pdo, 'wastage_pct')); $perf = []; foreach ($pdo->query("SELECT * FROM iv_perfumes")->fetchAll() as $p) $perf[(int)$p['id']] = $p;
        foreach ($rows as &$o) { $need = []; foreach ($o['lines'] as $l) { if (!isset($perf[$l['perfume_id']])) continue; foreach (iv_need($perf[$l['perfume_id']], $l['size_ml'], $l['qty'], $wast, (float)($l['oil_pct'] ?? 0), (float)($l['oil_ml'] ?? 0)) as $oid => $ml) $need[$oid] = ($need[$oid] ?? 0) + $ml; }
            $o['oil_ml'] = round(array_sum($need), 1); $o['oil_names'] = array_values(array_map(function ($oid) use ($oils) { return $oils[$oid]['name'] ?? '?'; }, array_keys($need)));
            $o['sim'] = $sim['orders'][$o['id']] ?? null; if ($o['sim']) { $o['sim']['short_named'] = []; foreach ($o['sim']['short'] as $oid => $g) $o['sim']['short_named'][] = ['oil' => $oils[$oid]['name'] ?? '?', 'gap_ml' => round($g, 1)]; } } unset($o);
        $cnt = []; foreach ($pdo->query("SELECT status, COUNT(*) c FROM iv_orders GROUP BY status")->fetchAll() as $r) $cnt[$r['status']] = (int)$r['c'];
        json_response(['orders' => $rows, 'counts' => $cnt]);
        break;
    }
    case 'iv.sync': {   // pull live Shopify orders (at most every 4 minutes unless "Sync now" is pressed)
        $r = iv_sync_shopify($pdo, !empty($_GET['force']));
        json_response($r);
        break;
    }
    case 'iv.unmatched': {   // Shopify products that could not be matched to a perfume (+ what is already matched by hand)
        iv_sync_tables($pdo);
        $items = $pdo->query("SELECT title_key, title, cnt FROM iv_unmatched ORDER BY cnt DESC, title LIMIT 200")->fetchAll(); $pl = $pdo->query("SELECT id, code, name FROM iv_perfumes WHERE active = 1")->fetchAll();
        foreach ($items as &$it) {   // best guess by shared words (you only confirm it)
            $cw = iv_words(iv_core((string)$it['title'])); $best = 0; $bs = 0.0; if ($cw) foreach ($pl as $p) { $pw = iv_words((string)$p['name']); if (!$pw) continue; $c = count(array_intersect($pw, $cw)); $sc = $c / max(count($pw), count($cw)); if ($c >= 1 && $sc > $bs) { $bs = $sc; $best = $p; } }
            $it['guess'] = ($best && $bs >= 0.5) ? $best['name'] . ' (' . $best['code'] . ')' : ''; } unset($it);
        json_response(['items' => $items, 'aliases' => $pdo->query("SELECT a.title_key, a.title, a.size_ml, p.name AS perfume FROM iv_alias a LEFT JOIN iv_perfumes p ON p.id = a.perfume_id ORDER BY a.title LIMIT 300")->fetchAll()]);
        break;
    }
    case 'iv.alias_save': {   // match Shopify product names to perfumes once; after this they are matched automatically
        $post(); iv_sync_tables($pdo); $n = 0; $up = $pdo->prepare("REPLACE INTO iv_alias (title_key, title, perfume_id, size_ml) VALUES (?,?,?,?)");
        foreach ((array)($in['items'] ?? []) as $it) { $k = substr((string)($it['title_key'] ?? ''), 0, 120); $pid = (int)($it['perfume_id'] ?? 0); if ($k === '' || $pid <= 0) continue;
            $e = $pdo->prepare("SELECT id FROM iv_perfumes WHERE id = ?"); $e->execute([$pid]); if (!$e->fetchColumn()) continue;
            $up->execute([$k, mb_substr((string)($it['title'] ?? ''), 0, 255), $pid, max(0, min(2000, (int)($it['size_ml'] ?? 0)))]); $n++; }
        $r = iv_sync_shopify($pdo, true); $r['saved'] = $n; json_response($r);
        break;
    }
    case 'iv.order_save': {
        $post(); $id = (int)($in['id'] ?? 0); $lines = $in['lines'] ?? []; if (!is_array($lines) || !$lines) iv_err('Add at least one perfume line.');
        $due = iv_day((string)($in['due_date'] ?? ''), date('Y-m-d', strtotime('+7 days'))); $cust = mb_substr(trim((string)($in['customer'] ?? '')), 0, 120); $ch = mb_substr(trim((string)($in['channel'] ?? '')), 0, 30); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);
        $clean = []; foreach ($lines as $l) { $pid = (int)($l['perfume_id'] ?? 0); $size = (int)($l['size_ml'] ?? 0); $qty = (int)($l['qty'] ?? 0);
            if ($pid <= 0 || $size < 1 || $size > 2000 || $qty <= 0 || $qty > 5000) iv_err('Each line needs a perfume, a bottle size in ml and a quantity.');
            $opct = iv_n($l['oil_pct'] ?? 0); $oml = iv_n($l['oil_ml'] ?? 0); if ($opct < 0 || $opct > 100 || $oml < 0 || $oml > 2000) iv_err('Oil % must be 0 to 100 and oil ml per bottle 0 to 2000.');
            $e = $pdo->prepare("SELECT id FROM iv_perfumes WHERE id = ? AND active = 1"); $e->execute([$pid]); if (!$e->fetchColumn()) iv_err('A perfume on this order does not exist any more.');
            $clean[] = [$pid, $size, $qty, $opct > 0 ? $opct : null, $oml > 0 ? $oml : null, ($opct > 0 || $oml > 0) ? mb_substr(trim((string)($l['oil_note'] ?? '')), 0, 120) : '']; }
        $pdo->beginTransaction();
        if ($id) { $s = $pdo->prepare("SELECT status FROM iv_orders WHERE id = ?"); $s->execute([$id]); $stt = $s->fetchColumn(); if ($stt !== 'received') { $pdo->rollBack(); iv_err('Only an order that has not started production can be changed.'); }
            $pdo->prepare("UPDATE iv_orders SET customer = ?, channel = ?, due_date = ?, note = ? WHERE id = ?")->execute([$cust, $ch, $due, $note, $id]); $pdo->prepare("DELETE FROM iv_lines WHERE order_id = ?")->execute([$id]);
        } else {
            $code = 'TM-' . str_pad((string)((int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM iv_orders")->fetchColumn() + 1000), 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO iv_orders (code, customer, channel, due_date, status, source, note, created_by, created_at) VALUES (?,?,?,?,'received','manual',?,?,?)")->execute([$code, $cust, $ch, $due, $note, $by, $now]); $id = (int)$pdo->lastInsertId();
        }
        $li = $pdo->prepare("INSERT INTO iv_lines (order_id, perfume_id, size_ml, qty, oil_pct, oil_ml, oil_note) VALUES (?,?,?,?,?,?,?)"); foreach ($clean as $c) $li->execute([$id, $c[0], $c[1], $c[2], $c[3], $c[4], $c[5]]);
        $pdo->commit(); json_response(['ok' => true, 'id' => $id]);
        break;
    }
    case 'iv.order_act': {   // issue (start production, oil taken out) | ready | dispatch | cancel
        $post(); $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? '');
        $s = $pdo->prepare("SELECT * FROM iv_orders WHERE id = ?"); $s->execute([$id]); $or = $s->fetch(); if (!$or) iv_err('Order not found.', 404);
        $wast = iv_n(iv_cfg($pdo, 'wastage_pct'));
        $needOf = function () use ($pdo, $id, $wast) { $need = []; foreach ($pdo->query("SELECT l.size_ml, l.qty, l.oil_pct AS ln_pct, l.oil_ml AS ln_ml, p.oil_id, p.oil_pct, p.oil2_id, p.oil2_pct FROM iv_lines l JOIN iv_perfumes p ON p.id = l.perfume_id WHERE l.order_id = $id")->fetchAll() as $l) foreach (iv_need($l, (int)$l['size_ml'], (int)$l['qty'], $wast, (float)($l['ln_pct'] ?? 0), (float)($l['ln_ml'] ?? 0)) as $oid => $ml) $need[$oid] = ($need[$oid] ?? 0) + $ml; return $need; };
        if ($act === 'issue') {
            if ($or['status'] !== 'received') iv_err('This order is already in production or finished.');
            $need = $needOf(); $pdo->beginTransaction(); $short = [];
            foreach ($need as $oid => $ml) { $c = $pdo->prepare("SELECT name, stock_ml FROM iv_oils WHERE id = ? FOR UPDATE"); $c->execute([$oid]); $o = $c->fetch(); if (!$o || (float)$o['stock_ml'] + 0.0001 < $ml) $short[] = ($o['name'] ?? 'oil') . ' (needs ' . round($ml) . ' ml, has ' . round((float)($o['stock_ml'] ?? 0)) . ' ml)'; }
            if ($short && empty($in['force'])) { $pdo->rollBack(); iv_err('Not enough oil: ' . implode('; ', $short) . '.'); }
            foreach ($need as $oid => $ml) iv_move($pdo, $oid, 'out', -$ml, $or['code'], 'Production for ' . $or['code'], $by);
            $pdo->prepare("UPDATE iv_orders SET status = 'in_production' WHERE id = ?")->execute([$id]); $pdo->commit(); json_response(['ok' => true]);
        } elseif ($act === 'ready') { if ($or['status'] !== 'in_production') iv_err('Only an order in production can be marked ready.'); $pdo->prepare("UPDATE iv_orders SET status = 'ready' WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        } elseif ($act === 'dispatch') { if (!in_array($or['status'], ['ready', 'in_production'], true)) iv_err('Mark the order ready first.'); $pdo->prepare("UPDATE iv_orders SET status = 'dispatched', done_at = ? WHERE id = ?")->execute([$now, $id]); json_response(['ok' => true]);
        } elseif ($act === 'cancel') {
            if (in_array($or['status'], ['dispatched', 'cancelled'], true)) iv_err('This order is already finished.');
            $pdo->beginTransaction(); if (in_array($or['status'], ['in_production', 'ready'], true)) foreach ($needOf() as $oid => $ml) iv_move($pdo, $oid, 'return', $ml, $or['code'], 'Order cancelled: oil returned', $by);
            $pdo->prepare("UPDATE iv_orders SET status = 'cancelled', done_at = ? WHERE id = ?")->execute([$now, $id]); $pdo->commit(); json_response(['ok' => true]);
        } else iv_err('Unknown order action.');
        break;
    }

    /* ---------------- dealers and purchases ---------------- */
    case 'iv.suppliers': {
        $oils = iv_oils_state($pdo); $rows = $pdo->query("SELECT * FROM iv_suppliers WHERE active = 1 ORDER BY name")->fetchAll();
        $spent = []; foreach ($pdo->query("SELECT supplier_id, COUNT(*) n, SUM(total) t, SUM(CASE WHEN status='received' AND paid=0 THEN total ELSE 0 END) due FROM iv_purchases WHERE status <> 'cancelled' GROUP BY supplier_id")->fetchAll() as $r) $spent[(int)$r['supplier_id']] = $r;
        $late = []; foreach ($pdo->query("SELECT supplier_id, SUM(CASE WHEN received_at IS NOT NULL AND DATE(received_at) <= expected_at THEN 1 ELSE 0 END) ok, SUM(CASE WHEN received_at IS NOT NULL THEN 1 ELSE 0 END) tot FROM iv_purchases WHERE status = 'received' GROUP BY supplier_id")->fetchAll() as $r) $late[(int)$r['supplier_id']] = $r;
        foreach ($rows as &$s) { $sid = (int)$s['id']; $s['id'] = $sid; $s['oils'] = []; $s['to_buy'] = [];
            foreach ($oils as $o) if ($o['supplier_id'] === $sid) { $s['oils'][] = ['id' => $o['id'], 'name' => $o['name']]; if (in_array($o['status'], ['out', 'low'], true)) $s['to_buy'][] = $o['name']; }
            $s['purchases'] = (int)($spent[$sid]['n'] ?? 0); $s['spent'] = round((float)($spent[$sid]['t'] ?? 0)); $s['due'] = round((float)($spent[$sid]['due'] ?? 0));
            $s['on_time'] = !empty($late[$sid]['tot']) ? (int)round($late[$sid]['ok'] / $late[$sid]['tot'] * 100) : null; $s['wa'] = iv_phone($s['whatsapp'] !== '' ? $s['whatsapp'] : $s['phone']); } unset($s);
        json_response(['suppliers' => $rows]);
        break;
    }
    case 'iv.sup_save': {
        $post(); $id = (int)($in['id'] ?? 0); $name = trim((string)($in['name'] ?? '')); if ($name === '') iv_err('Give the dealer a name.');
        $v = [$name, mb_substr(trim((string)($in['city'] ?? '')), 0, 80), mb_substr(trim((string)($in['phone'] ?? '')), 0, 30), mb_substr(trim((string)($in['whatsapp'] ?? '')), 0, 30), mb_substr(trim((string)($in['email'] ?? '')), 0, 120), mb_substr(trim((string)($in['address'] ?? '')), 0, 255), max(0, min(60, (int)($in['lead_days'] ?? 3))), mb_substr(trim((string)($in['terms'] ?? '')), 0, 80), mb_substr(trim((string)($in['notes'] ?? '')), 0, 255)];
        if ($id) { $pdo->prepare("UPDATE iv_suppliers SET name=?, city=?, phone=?, whatsapp=?, email=?, address=?, lead_days=?, terms=?, notes=? WHERE id=?")->execute(array_merge($v, [$id])); }
        else { $pdo->prepare("INSERT INTO iv_suppliers (name, city, phone, whatsapp, email, address, lead_days, terms, notes, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,1,?)")->execute(array_merge($v, [$now])); $id = (int)$pdo->lastInsertId(); }
        json_response(['ok' => true, 'id' => $id]);
        break;
    }
    case 'iv.sup_off': {
        $post(); $id = (int)($in['id'] ?? 0); $n = (int)$pdo->query("SELECT COUNT(*) FROM iv_oils WHERE active = 1 AND supplier_id = $id")->fetchColumn(); if ($n > 0) iv_err($n . ' oils are still bought from this dealer. Move them to another dealer first.');
        $pdo->prepare("UPDATE iv_suppliers SET active = 0 WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        break;
    }
    case 'iv.purchases': {
        $w = []; $a = []; if (!empty($_GET['supplier_id'])) { $w[] = 'p.supplier_id = ?'; $a[] = (int)$_GET['supplier_id']; } if (!empty($_GET['oil_id'])) { $w[] = 'p.oil_id = ?'; $a[] = (int)$_GET['oil_id']; }
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') { $w[] = 'p.status = ?'; $a[] = (string)$_GET['status']; }
        [$lim, $off] = $page(30);
        $st = $pdo->prepare("SELECT p.*, o.name AS oil, s.name AS supplier FROM iv_purchases p LEFT JOIN iv_oils o ON o.id = p.oil_id LEFT JOIN iv_suppliers s ON s.id = p.supplier_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY p.id DESC LIMIT $lim OFFSET $off"); $st->execute($a);
        json_response(['purchases' => $st->fetchAll()]);
        break;
    }
    case 'iv.purchase_create': {   // an order placed with a dealer (oil arrives later: iv.receive with purchase_id)
        $post(); $oil = (int)($in['oil_id'] ?? 0); $qty = iv_n($in['qty_ml'] ?? 0); if ($oil <= 0 || $qty <= 0) iv_err('Choose the oil and the quantity to buy.');
        $o = $pdo->prepare("SELECT o.*, s.lead_days FROM iv_oils o LEFT JOIN iv_suppliers s ON s.id = o.supplier_id WHERE o.id = ?"); $o->execute([$oil]); $r = $o->fetch(); if (!$r) iv_err('Oil not found.', 404);
        $price = iv_n($in['price_per_ml'] ?? 0, (float)$r['price_per_ml']); $sup = (int)($in['supplier_id'] ?? $r['supplier_id']); $lead = $r['lead_days'] === null ? 3 : (int)$r['lead_days'];
        $exp = iv_day((string)($in['expected_at'] ?? ''), date('Y-m-d', strtotime('+' . $lead . ' days')));
        $code = 'PU-' . date('ymd') . '-' . str_pad((string)((int)$pdo->query("SELECT COUNT(*) FROM iv_purchases WHERE ordered_at = '" . date('Y-m-d') . "'")->fetchColumn() + 1), 2, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO iv_purchases (code, oil_id, supplier_id, qty_ml, price_per_ml, total, status, ordered_at, expected_at, note, by_user) VALUES (?,?,?,?,?,?,'ordered',?,?,?,?)")->execute([$code, $oil, $sup, $qty, $price, round($qty * $price, 2), $today, $exp, mb_substr((string)($in['note'] ?? ''), 0, 255), $by]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'code' => $code, 'expected_at' => $exp]);
        break;
    }
    case 'iv.purchase_act': {
        $post(); $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? '');
        if ($act === 'cancel') $pdo->prepare("UPDATE iv_purchases SET status = 'cancelled' WHERE id = ? AND status = 'ordered'")->execute([$id]);
        elseif ($act === 'paid') $pdo->prepare("UPDATE iv_purchases SET paid = 1, paid_at = ? WHERE id = ? AND status = 'received'")->execute([$now, $id]);
        else iv_err('Unknown action.');
        json_response(['ok' => true]);
        break;
    }
    case 'iv.message': {   // the WhatsApp text for a dealer, written from the real numbers
        $oil = (int)($_GET['oil_id'] ?? 0); $oils = iv_oils_state($pdo); $o = $oils[$oil] ?? null; if (!$o) iv_err('Oil not found.', 404);
        $qty = (int)($_GET['qty_ml'] ?? 0); if ($qty <= 0) { $sim = iv_sim($pdo, $oils); $qty = iv_buy_qty($pdo, $o, (float)($sim['open_short'][$oil] ?? 0)); }
        $first = trim(explode(' ', (string)$o['supplier'])[0] ?: 'Sir');
        $text = "Hello $first ji,\nWe need {$o['name']} ({$o['code']}).\nQuantity: " . number_format($qty) . " ml\nPlease confirm the price and the delivery date. We need it within {$o['lead_days']} days.\nThank you, TM Perfume House";
        $ph = iv_phone($o['phone']);
        json_response(['text' => $text, 'phone' => $o['phone'], 'qty_ml' => $qty, 'supplier' => $o['supplier'], 'wa_url' => $ph !== '' ? 'https://wa.me/' . $ph . '?text=' . rawurlencode($text) : '', 'tel_url' => $o['phone'] !== '' ? 'tel:' . preg_replace('/[^\d+]/', '', $o['phone']) : '']);
        break;
    }
    case 'iv.order_message': {   // the delay message for a customer
        $id = (int)($_GET['id'] ?? 0); $o = iv_order_rows($pdo, ['id = ' . $id])[0] ?? null; if (!$o) iv_err('Order not found.', 404);
        $oils = iv_oils_state($pdo); $sim = iv_sim($pdo, $oils); $s = $sim['orders'][$id] ?? null; $d = $s ? max(1, $s['late_by']) : 1;
        $first = trim(explode(' ', (string)$o['customer'])[0] ?: 'Sir');
        json_response(['text' => "Hello $first, thank you for your order {$o['code']}. One of the oils for your perfume is arriving soon, so we will dispatch about $d days later than planned. We are sorry for the wait and will update you as soon as it is ready."]);
        break;
    }

    /* ---------------- reports ---------------- */
    case 'iv.reports': {
        $p = (string)($_GET['period'] ?? 'weekly'); $n = $p === 'daily' ? 14 : ($p === 'monthly' ? 6 : 12); $buckets = []; $lab = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($p === 'daily') { $a = date('Y-m-d', strtotime("-$i days")); $b = $a; $l = date('d M', strtotime($a)); }
            elseif ($p === 'monthly') { $a = date('Y-m-01', strtotime("first day of -$i month")); $b = date('Y-m-t', strtotime($a)); $l = date('M', strtotime($a)); }
            else { $a = date('Y-m-d', strtotime("monday this week -$i weeks")); $b = date('Y-m-d', strtotime($a . ' +6 days')); $l = date('d M', strtotime($a)); }
            $buckets[] = [$a, $b]; $lab[] = $l;
        }
        $use = []; $buy = []; $spend = 0.0;
        foreach ($buckets as $k => [$a, $b]) {
            $u = $pdo->prepare("SELECT COALESCE(SUM(-qty_ml),0) FROM iv_moves WHERE kind = 'out' AND DATE(created_at) BETWEEN ? AND ?"); $u->execute([$a, $b]); $use[] = round((float)$u->fetchColumn());
            $r = $pdo->prepare("SELECT COALESCE(SUM(qty_ml),0), COALESCE(SUM(total),0) FROM iv_purchases WHERE status = 'received' AND DATE(received_at) BETWEEN ? AND ?"); $r->execute([$a, $b]); $x = $r->fetch(PDO::FETCH_NUM); $buy[] = round((float)$x[0]); $spend += (float)$x[1];
        }
        $oils = iv_oils_state($pdo); $top = $oils; usort($top, function ($a, $b) { return $b['used_ml'] <=> $a['used_ml']; });
        $cap = []; $perf = $pdo->query("SELECT * FROM iv_perfumes WHERE active = 1")->fetchAll(); $wast = iv_n(iv_cfg($pdo, 'wastage_pct'));
        foreach (IV_SIZES as $s) { $tot = 0; foreach ($perf as $pr) { $m = null; foreach (iv_need($pr, $s, 1, $wast) as $oid => $ml) { $c = $ml > 0 ? (int)floor(($oils[$oid]['stock_ml'] ?? 0) / $ml) : 0; $m = $m === null ? $c : min($m, $c); } $tot += $m ?? 0; } $cap[$s] = $perf ? (int)round($tot / count($perf)) : 0; }
        $total = 0; foreach ($oils as $o) $total += $o['stock_ml'];
        $pl = $pdo->query("SELECT COALESCE(SUM(total),0) FROM iv_purchases WHERE status = 'received' AND paid = 0")->fetchColumn();
        json_response(['labels' => $lab, 'used' => $use, 'bought' => $buy, 'spend' => round($spend), 'total_ml' => $total, 'top' => array_slice(array_map(function ($o) { return ['name' => $o['name'], 'used_ml' => $o['used_ml']]; }, $top), 0, 8), 'capacity' => $cap, 'unpaid' => round((float)$pl)]);
        break;
    }
    case 'iv.settings': {
        if ($isPost) { if (($auth['role'] ?? '') !== 'Admin') iv_err('Only Admin can change the settings.', 403);
            foreach (['safety_days' => [0, 30], 'wastage_pct' => [0, 20], 'default_pct' => [1, 60], 'window_days' => [7, 180], 'buy_round_ml' => [10, 5000], 'target_days' => [7, 90]] as $k => $r) if (isset($in[$k])) { $v = max($r[0], min($r[1], iv_n($in[$k]))); $pdo->prepare("REPLACE INTO iv_settings (k, v) VALUES (?, ?)")->execute([$k, (string)$v]); }
            json_response(['ok' => true]); }
        $o = []; foreach (['safety_days', 'wastage_pct', 'default_pct', 'window_days', 'buy_round_ml', 'target_days'] as $k) $o[$k] = iv_cfg($pdo, $k);
        json_response(['settings' => $o]);
        break;
    }
    case 'iv.options': {   // small lists for the forms
        json_response(['oils' => $pdo->query("SELECT id, code, name, supplier_id FROM iv_oils WHERE active = 1 ORDER BY name")->fetchAll(), 'suppliers' => $pdo->query("SELECT id, name, lead_days FROM iv_suppliers WHERE active = 1 ORDER BY name")->fetchAll(),
            'perfumes' => $pdo->query("SELECT id, code, name, oil_id, oil_pct, oil2_id, oil2_pct FROM iv_perfumes WHERE active = 1 ORDER BY name")->fetchAll(), 'sizes' => IV_SIZES, 'default_pct' => (float)iv_cfg($pdo, 'default_pct')]);
        break;
    }
    default:
        json_response(['error' => 'Unknown inventory action.'], 404);
}
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('inventory.php ' . $action . ': ' . $e->getMessage());
    json_response(['error' => 'Inventory error: ' . $e->getMessage()], 500);
}
