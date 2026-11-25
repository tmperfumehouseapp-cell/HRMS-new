<?php
/**
 * retail_barcode.php — Shop wholesale → 🏷 Barcodes: every bottle gets its own 6-digit number (printed as a barcode label).
 * The number tells which perfume + size it is, whether it is in stock (which city) or at a shop, and on which order it went.
 *
 *   retail.bc_make    POST {product_name, ml, qty, city, add_stock, note}  new numbers for a batch; add_stock = also add the pieces to stock
 *                     (a new arrival) — off = labels for bottles that are already counted in stock
 *   retail.bc_scan    GET  {code}                                          one bottle: what it is, where it is, its history
 *   retail.bc_out     POST {codes[], shop_id, order_id}                    bottles go to a shop. With an order: the order already took them off stock,
 *                     the numbers are only linked to it. Without an order: the pieces come off stock here.
 *   retail.bc_return  POST {codes[], note}                                bottles come back from a shop: in stock again (+1 each)
 *   retail.bc_void    POST {code, note}                                   a label printed by mistake / a broken bottle (stock is not changed)
 *   retail.bc_list    GET  {status, shop_id, q, city}                     numbers with filters + a summary per perfume and size
 *   retail.bc_orders  GET  {shop_id}                                      the shop's recent sale / display / replacement orders, with how many numbers each has
 * Loaded by api.php for retail.bc_* (one hook line). Its own tables: retail_bc, retail_bc_log. Stock moves are written to retail_stock_log
 * exactly like the Stock page does, so the Stock numbers stay right. PHP 7.4 safe.
 */
if (defined('BC_LOADED')) { return; }
define('BC_LOADED', 1);

function bc_ensure(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!empty($GLOBALS['BC_TABLES_READY'])) return $ok = true;   // tests (SQLite) make the tables themselves
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS retail_bc (code CHAR(6) NOT NULL PRIMARY KEY, product_name VARCHAR(160) NOT NULL DEFAULT '', ml VARCHAR(20) NOT NULL DEFAULT '', city VARCHAR(60) NOT NULL DEFAULT '', status VARCHAR(8) NOT NULL DEFAULT 'stock', shop_id INT NULL, order_id INT NULL, order_no VARCHAR(20) NOT NULL DEFAULT '', batch VARCHAR(20) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, created_by_name VARCHAR(100) NOT NULL DEFAULT '', moved_at DATETIME NULL, KEY idx_bc_shop (shop_id), KEY idx_bc_item (product_name, ml, status))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS retail_bc_log (id INT AUTO_INCREMENT PRIMARY KEY, code CHAR(6) NOT NULL, action VARCHAR(10) NOT NULL, shop_id INT NULL, order_no VARCHAR(20) NOT NULL DEFAULT '', city VARCHAR(60) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '', by_name VARCHAR(100) NOT NULL DEFAULT '', at DATETIME NOT NULL, KEY idx_bc_log_code (code))");
        $ok = true;
    } catch (PDOException $e) { error_log('retail_bc tables: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

function bc_all(PDO $pdo, string $sql, array $a = []): array { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(PDO::FETCH_ASSOC); }
function bc_now(): string { return date('Y-m-d H:i:s'); }
function bc_mlk(string $ml): string { return function_exists('retail_ml_key') ? retail_ml_key($ml) : (preg_match('/(\d+(?:\.\d+)?)/', $ml, $m) ? $m[1] : ''); }
function bc_key(string $name, string $ml): string { return mb_strtolower(preg_replace('/\s+/', ' ', trim($name))) . '|' . bc_mlk($ml); }
/** "123 456", "#123456", "TM-123456" → "123456" ('' when it is not 6 digits) */
function bc_clean($c): string { $d = preg_replace('/\D+/', '', (string)$c); return strlen($d) === 6 ? $d : ''; }
function bc_codes($v): array { $out = []; foreach ((array)$v as $c) { $c = bc_clean($c); if ($c !== '') $out[$c] = 1; } return array_keys($out); }

function bc_new_code(PDO $pdo, array $taken): string
{
    $chk = $pdo->prepare("SELECT 1 FROM retail_bc WHERE code = ?");
    for ($i = 0; $i < 200; $i++) {
        $c = (string)random_int(100000, 999999);
        if (isset($taken[$c])) continue;
        $chk->execute([$c]); if (!$chk->fetchColumn()) return $c;
    }
    throw new RuntimeException('Could not find a free number, try again.');
}
function bc_log(PDO $pdo, string $code, string $action, ?int $shop, string $orderNo, string $city, string $note, string $by): void
{
    $pdo->prepare("INSERT INTO retail_bc_log (code, action, shop_id, order_no, city, note, by_name, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$code, $action, $shop, $orderNo, $city, mb_substr($note, 0, 255), mb_substr($by, 0, 100), bc_now()]);
}
/** a stock move written the same way as the Stock page (qty > 0 = in, < 0 = out) */
function bc_stock(PDO $pdo, string $name, string $ml, int $qty, string $kind, string $note, string $city, array $auth): void
{
    if ($qty === 0) return;
    $pdo->prepare("INSERT INTO retail_stock_log (product_name, ml, qty, kind, note, city, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$name, bc_mlk($ml) . ' ml', $qty, $kind, mb_substr($note, 0, 255), $city, $auth['employee_id'] ?? null, mb_substr((string)($auth['username'] ?? ''), 0, 100), bc_now()]);
}
function bc_shop(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $r = bc_all($pdo, "SELECT shop_id, shop_name, area, owner_name, mobile FROM retail_shops WHERE shop_id = ?", [$id]);
    if (!$r) return null;
    $r[0]['city'] = function_exists('retail_shop_city') ? retail_shop_city($pdo, $id) : '';
    return $r[0];
}
function bc_row(array $r, array $shops = []): array
{
    $sid = $r['shop_id'] !== null ? (int)$r['shop_id'] : null; $s = $sid ? ($shops[$sid] ?? null) : null;
    return ['code' => (string)$r['code'], 'product_name' => (string)$r['product_name'], 'ml' => (string)$r['ml'], 'city' => (string)$r['city'], 'status' => (string)$r['status'],
        'shop_id' => $sid, 'shop_name' => $s ? (string)$s['shop_name'] : '', 'shop_area' => $s ? (string)$s['area'] : '', 'order_id' => $r['order_id'] !== null ? (int)$r['order_id'] : null, 'order_no' => (string)$r['order_no'],
        'batch' => (string)$r['batch'], 'created_at' => (string)$r['created_at'], 'moved_at' => $r['moved_at']];
}
function bc_shop_map(PDO $pdo, array $rows): array
{
    $ids = array_values(array_unique(array_filter(array_map(function ($r) { return (int)($r['shop_id'] ?? 0); }, $rows))));
    if (!$ids) return [];
    $out = []; foreach (bc_all($pdo, "SELECT shop_id, shop_name, area FROM retail_shops WHERE shop_id IN (" . implode(',', $ids) . ")") as $s) $out[(int)$s['shop_id']] = $s;
    return $out;
}
function bc_get(PDO $pdo, array $codes): array
{
    if (!$codes) return [];
    $out = []; foreach (bc_all($pdo, "SELECT * FROM retail_bc WHERE code IN (" . implode(',', array_fill(0, count($codes), '?')) . ")", $codes) as $r) $out[(string)$r['code']] = $r;
    return $out;
}

function bc_make(PDO $pdo, array $in, array $auth): array
{
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($in['product_name'] ?? ''))), 0, 160);
    $ml = bc_mlk((string)($in['ml'] ?? '')); $qty = (int)($in['qty'] ?? 0);
    $city = function_exists('retail_city_ensure') ? retail_city_ensure($pdo, $in['city'] ?? '') : trim((string)($in['city'] ?? ''));
    if ($name === '') throw new RuntimeException('Enter the perfume name.');
    if ($ml === '') throw new RuntimeException('Pick the size (ml).');
    if ($qty < 1 || $qty > 500) throw new RuntimeException('Make 1 to 500 numbers at a time.');
    if ($city === '') throw new RuntimeException('Pick the city this stock is in.');
    $add = !empty($in['add_stock']); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200); $who = (string)($auth['username'] ?? '');
    if (function_exists('retail_stock_rows')) foreach (retail_stock_rows($pdo) as $r) if ($r['key'] === bc_key($name, $ml)) { $name = $r['product_name']; break; }   // one spelling for the same perfume
    $batch = date('ymd-His'); $codes = []; $taken = [];
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO retail_bc (code, product_name, ml, city, status, batch, created_at, created_by_name) VALUES (?, ?, ?, ?, 'stock', ?, ?, ?)");
        for ($i = 0; $i < $qty; $i++) { $c = bc_new_code($pdo, $taken); $taken[$c] = 1; $ins->execute([$c, $name, $ml, $city, $batch, bc_now(), mb_substr($who, 0, 100)]); bc_log($pdo, $c, $add ? 'in' : 'label', null, '', $city, $note, $who); $codes[] = $c; }
        if ($add) bc_stock($pdo, $name, $ml, $qty, 'in', trim('Barcode batch ' . $batch . ($note !== '' ? ' · ' . $note : '')), $city, $auth);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    if ($add && function_exists('retail_stock_check')) { try { retail_stock_check($pdo); } catch (Throwable $e) {} }
    $warn = '';
    if (!$add && function_exists('retail_stock_city_rows')) {   // labels for stock already counted: say when there are more labels than bottles
        $have = 0; foreach (retail_stock_city_rows($pdo, $city) as $r) if ($r['key'] === bc_key($name, $ml)) $have = (int)$r['on_hand'];
        $lab = (int)bc_all($pdo, "SELECT COUNT(*) AS n FROM retail_bc WHERE product_name = ? AND ml = ? AND city = ? AND status = 'stock'", [$name, $ml, $city])[0]['n'];
        if ($lab > $have) $warn = 'Stock shows ' . $have . ' pcs of ' . $name . ' ' . $ml . ' ml in ' . $city . ' but ' . $lab . ' have a number now. If these are new bottles, use "Add to stock".';
    }
    return ['codes' => $codes, 'batch' => $batch, 'product_name' => $name, 'ml' => $ml, 'city' => $city, 'added' => $add, 'warning' => $warn,
        'message' => count($codes) . ' number' . (count($codes) === 1 ? '' : 's') . ' made for ' . $name . ' ' . $ml . ' ml' . ($add ? ' and +' . count($codes) . ' added to stock in ' . $city : '') . '. Print the labels.'];
}

function bc_scan(PDO $pdo, string $code): array
{
    $c = bc_clean($code); if ($c === '') throw new RuntimeException('Type the 6-digit number.');
    $r = bc_get($pdo, [$c])[$c] ?? null; if (!$r) throw new RuntimeException('Number ' . $c . ' is not in the system.');
    $log = bc_all($pdo, "SELECT action, shop_id, order_no, city, note, by_name, at FROM retail_bc_log WHERE code = ? ORDER BY id DESC", [$c]);
    $shops = bc_shop_map($pdo, array_merge([$r], $log));
    foreach ($log as &$l) { $sid = $l['shop_id'] !== null ? (int)$l['shop_id'] : 0; $l['shop_name'] = $sid && isset($shops[$sid]) ? (string)$shops[$sid]['shop_name'] : ''; }
    unset($l);
    $row = bc_row($r, $shops);
    if ($row['order_id']) { $o = bc_all($pdo, "SELECT order_type, order_date, billed_total, payment FROM retail_orders WHERE order_id = ?", [$row['order_id']]); if ($o) $row['order'] = $o[0]; }
    return ['piece' => $row, 'history' => $log];
}

function bc_out(PDO $pdo, array $in, array $auth): array
{
    $codes = bc_codes($in['codes'] ?? []); if (!$codes) throw new RuntimeException('Scan or type at least one 6-digit number.');
    $shop = bc_shop($pdo, (int)($in['shop_id'] ?? 0)); if (!$shop) throw new RuntimeException('Pick the shop.');
    $oid = (int)($in['order_id'] ?? 0); $order = null; $room = [];
    if ($oid) {
        $o = bc_all($pdo, "SELECT order_id, order_no, shop_id, order_type FROM retail_orders WHERE order_id = ?", [$oid]);
        if (!$o || (int)$o[0]['shop_id'] !== (int)$shop['shop_id']) throw new RuntimeException('That order is not of ' . $shop['shop_name'] . '.');
        $order = $o[0];
        foreach (bc_all($pdo, "SELECT product_name, ml, qty FROM retail_order_items WHERE order_id = ?", [$oid]) as $it) { $k = bc_key((string)$it['product_name'], (string)$it['ml']); $room[$k] = ($room[$k] ?? 0) + (int)$it['qty']; }
        foreach (bc_all($pdo, "SELECT product_name, ml FROM retail_bc WHERE order_id = ? AND status = 'shop'", [$oid]) as $b) { $k = bc_key((string)$b['product_name'], (string)$b['ml']); $room[$k] = ($room[$k] ?? 0) - 1; }
    }
    $rows = bc_get($pdo, $codes); $bad = []; $ok = [];
    foreach ($codes as $c) {
        $r = $rows[$c] ?? null;
        if (!$r) { $bad[] = $c . ': not in the system'; continue; }
        if ((string)$r['status'] === 'shop') { $s = bc_shop($pdo, (int)$r['shop_id']); $bad[] = $c . ': already at ' . ($s ? $s['shop_name'] : 'a shop'); continue; }
        if ((string)$r['status'] === 'void') { $bad[] = $c . ': cancelled number'; continue; }
        if ($shop['city'] !== '' && (string)$r['city'] !== '' && mb_strtolower((string)$r['city']) !== mb_strtolower($shop['city'])) { $bad[] = $c . ': stock of ' . $r['city'] . ', the shop is in ' . $shop['city']; continue; }
        if ($order) { $k = bc_key((string)$r['product_name'], (string)$r['ml']); if (($room[$k] ?? 0) <= 0) { $bad[] = $c . ' (' . $r['product_name'] . ' ' . $r['ml'] . ' ml): not on order ' . $order['order_no'] . ' or all its pieces already have a number'; continue; } $room[$k]--; }
        $ok[] = $r;
    }
    if ($bad) throw new RuntimeException('Nothing saved. ' . implode(' · ', $bad));
    $who = (string)($auth['username'] ?? ''); $groups = [];
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare("UPDATE retail_bc SET status = 'shop', shop_id = ?, order_id = ?, order_no = ?, moved_at = ? WHERE code = ?");
        foreach ($ok as $r) {
            $u->execute([(int)$shop['shop_id'], $order ? (int)$order['order_id'] : null, $order ? (string)$order['order_no'] : '', bc_now(), (string)$r['code']]);
            bc_log($pdo, (string)$r['code'], 'out', (int)$shop['shop_id'], $order ? (string)$order['order_no'] : '', (string)$r['city'], $order ? '' : 'off stock by barcode', $who);
            $k = bc_key((string)$r['product_name'], (string)$r['ml']); if (!isset($groups[$k])) $groups[$k] = ['name' => (string)$r['product_name'], 'ml' => (string)$r['ml'], 'city' => (string)$r['city'], 'n' => 0]; $groups[$k]['n']++;
        }
        if (!$order) foreach ($groups as $g) bc_stock($pdo, $g['name'], $g['ml'], -$g['n'], 'adjust', 'Barcode out → ' . $shop['shop_name'], $g['city'] ?: $shop['city'], $auth);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    if (!$order && function_exists('retail_stock_check')) { try { retail_stock_check($pdo); } catch (Throwable $e) {} }
    return ['message' => count($ok) . ' bottle' . (count($ok) === 1 ? '' : 's') . ' → ' . $shop['shop_name'] . ($order ? ' on order ' . $order['order_no'] : ' (taken off stock)') . '.', 'done' => count($ok)];
}

function bc_return(PDO $pdo, array $in, array $auth): array
{
    $codes = bc_codes($in['codes'] ?? []); if (!$codes) throw new RuntimeException('Scan or type at least one 6-digit number.');
    $rows = bc_get($pdo, $codes); $bad = []; $ok = [];
    foreach ($codes as $c) { $r = $rows[$c] ?? null; if (!$r) $bad[] = $c . ': not in the system'; elseif ((string)$r['status'] !== 'shop') $bad[] = $c . ': it is not at a shop'; else $ok[] = $r; }
    if ($bad) throw new RuntimeException('Nothing saved. ' . implode(' · ', $bad));
    $who = (string)($auth['username'] ?? ''); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200); $groups = [];
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare("UPDATE retail_bc SET status = 'stock', shop_id = NULL, order_id = NULL, order_no = '', moved_at = ? WHERE code = ?");
        foreach ($ok as $r) {
            $s = bc_shop($pdo, (int)$r['shop_id']); $u->execute([bc_now(), (string)$r['code']]);
            bc_log($pdo, (string)$r['code'], 'return', (int)$r['shop_id'], (string)$r['order_no'], (string)$r['city'], $note, $who);
            $k = bc_key((string)$r['product_name'], (string)$r['ml']) . '#' . (int)$r['shop_id'];
            if (!isset($groups[$k])) $groups[$k] = ['name' => (string)$r['product_name'], 'ml' => (string)$r['ml'], 'city' => (string)$r['city'], 'shop' => $s ? (string)$s['shop_name'] : 'a shop', 'n' => 0]; $groups[$k]['n']++;
        }
        foreach ($groups as $g) bc_stock($pdo, $g['name'], $g['ml'], $g['n'], 'adjust', 'Barcode return ← ' . $g['shop'] . ($note !== '' ? ' · ' . $note : ''), $g['city'], $auth);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    if (function_exists('retail_stock_check')) { try { retail_stock_check($pdo); } catch (Throwable $e) {} }
    return ['message' => count($ok) . ' bottle' . (count($ok) === 1 ? '' : 's') . ' back in stock.', 'done' => count($ok)];
}

function bc_list(PDO $pdo, array $g): array
{
    $w = ['1=1']; $a = [];
    $city = trim((string)($g['city'] ?? '')); if ($city !== '') { $w[] = 'b.city = ?'; $a[] = $city; }
    $st = (string)($g['status'] ?? ''); if (in_array($st, ['stock', 'shop', 'void'], true)) { $w[] = 'b.status = ?'; $a[] = $st; } elseif ($st === '') { $w[] = "b.status <> 'void'"; }
    $sid = (int)($g['shop_id'] ?? 0); if ($sid > 0) { $w[] = 'b.shop_id = ?'; $a[] = $sid; }
    $oid = (int)($g['order_id'] ?? 0); if ($oid > 0) { $w[] = 'b.order_id = ?'; $a[] = $oid; }
    $q = trim((string)($g['q'] ?? ''));
    if ($q !== '') { $d = preg_replace('/\D+/', '', $q); if ($d !== '' && strlen($d) >= 3 && $d === str_replace(' ', '', $q)) { $w[] = 'b.code LIKE ?'; $a[] = $d . '%'; } else { $w[] = '(b.product_name LIKE ? OR b.order_no LIKE ?)'; $a[] = '%' . $q . '%'; $a[] = '%' . $q . '%'; } }
    $where = implode(' AND ', $w);
    $rows = bc_all($pdo, "SELECT b.* FROM retail_bc b WHERE $where ORDER BY COALESCE(b.moved_at, b.created_at) DESC, b.code LIMIT 600", $a);
    $shops = bc_shop_map($pdo, $rows);
    $sum = [];
    foreach (bc_all($pdo, "SELECT b.product_name, b.ml, b.status, COUNT(*) AS n FROM retail_bc b WHERE $where GROUP BY b.product_name, b.ml, b.status", $a) as $r) {
        $k = bc_key((string)$r['product_name'], (string)$r['ml']);
        if (!isset($sum[$k])) $sum[$k] = ['product_name' => (string)$r['product_name'], 'ml' => (string)$r['ml'], 'stock' => 0, 'shop' => 0, 'void' => 0];
        $sum[$k][(string)$r['status']] = ($sum[$k][(string)$r['status']] ?? 0) + (int)$r['n'];
    }
    $sum = array_values($sum); usort($sum, function ($x, $y) { return strcasecmp($x['product_name'], $y['product_name']) ?: ((float)$x['ml'] <=> (float)$y['ml']); });
    $tot = ['stock' => 0, 'shop' => 0, 'void' => 0]; foreach ($sum as $s) foreach ($tot as $k => $v) $tot[$k] += (int)$s[$k];
    return ['rows' => array_map(function ($r) use ($shops) { return bc_row($r, $shops); }, $rows), 'summary' => $sum, 'totals' => $tot, 'more' => count($rows) >= 600];
}

function bc_orders(PDO $pdo, int $shopId): array
{
    $shop = bc_shop($pdo, $shopId); if (!$shop) throw new RuntimeException('Pick the shop.');
    $orders = bc_all($pdo, "SELECT order_id, order_no, order_type, order_date, qty, billed_total FROM retail_orders WHERE shop_id = ? AND order_type IN ('sale','display','replacement') ORDER BY order_date DESC, order_id DESC LIMIT 30", [$shopId]);
    $ids = array_map(function ($o) { return (int)$o['order_id']; }, $orders); $items = []; $linked = [];
    if ($ids) {
        $in = implode(',', $ids);
        foreach (bc_all($pdo, "SELECT order_id, product_name, ml, qty FROM retail_order_items WHERE order_id IN ($in)") as $it) $items[(int)$it['order_id']][] = ['product_name' => (string)$it['product_name'], 'ml' => bc_mlk((string)$it['ml']), 'qty' => (int)$it['qty']];
        foreach (bc_all($pdo, "SELECT order_id, COUNT(*) AS n FROM retail_bc WHERE order_id IN ($in) AND status = 'shop' GROUP BY order_id") as $r) $linked[(int)$r['order_id']] = (int)$r['n'];
    }
    foreach ($orders as &$o) { $id = (int)$o['order_id']; $o['order_id'] = $id; $o['qty'] = (int)$o['qty']; $o['billed_total'] = (float)$o['billed_total']; $o['items'] = $items[$id] ?? []; $o['linked'] = $linked[$id] ?? 0; }
    unset($o);
    return ['shop' => $shop, 'orders' => $orders];
}


/* ============================== orders: the bottles of an order are its barcodes ============================== */
/**
 * Checked BEFORE an order is saved (retail.order_save). $items = [[name, ml, qty, delivered, price, total], ...] as order_save builds them.
 * Sale / display / replacement: every number must be in stock (or already on this order) in the shop's city. Return: every number must be at this shop.
 * A line cannot have more numbers than pieces. A NEW sale / display / replacement order needs one number per piece (Admin may save without).
 * Returns ['errors' => [...], 'missing' => [...]] ('missing' = lines short of numbers, the only thing Admin may override).
 */
function bc_order_check(PDO $pdo, int $oid, int $shopId, string $type, array $items, array $codes): array
{
    if (!bc_ensure($pdo)) return ['errors' => [], 'missing' => []];
    $codes = bc_codes($codes); $rows = bc_get($pdo, $codes); $errors = []; $byKey = [];
    $city = function_exists('retail_shop_city') ? retail_shop_city($pdo, $shopId) : '';
    $out = in_array($type, ['sale', 'display', 'replacement'], true);
    foreach ($codes as $c) {
        $r = $rows[$c] ?? null;
        if (!$r) { $errors[] = $c . ': not in the system'; continue; }
        $st = (string)$r['status'];
        if ($st === 'void') { $errors[] = $c . ': cancelled number'; continue; }
        if ($out) {
            $mine = $oid && (int)$r['order_id'] === $oid && $st === 'shop';
            if (!$mine && $st !== 'stock') { $s = bc_shop($pdo, (int)$r['shop_id']); $errors[] = $c . ': already at ' . ($s ? $s['shop_name'] : 'a shop'); continue; }
            if (!$mine && $city !== '' && (string)$r['city'] !== '' && mb_strtolower((string)$r['city']) !== mb_strtolower($city)) { $errors[] = $c . ': stock of ' . $r['city'] . ', this shop is in ' . $city; continue; }
        } elseif ($type === 'return') {
            if (!($st === 'shop' && (int)$r['shop_id'] === $shopId) && !($oid && $st === 'stock' && bc_returned_on($pdo, $c, $oid))) { $errors[] = $c . ': not at this shop'; continue; }
        }
        $k = bc_key((string)$r['product_name'], (string)$r['ml']); $byKey[$k] = ($byKey[$k] ?? 0) + 1;
    }
    $want = []; $names = [];
    foreach ($items as $it) { $k = bc_key((string)$it[0], (string)$it[1]); $want[$k] = ($want[$k] ?? 0) + (int)$it[2]; $names[$k] = trim($it[0] . ' ' . bc_mlk((string)$it[1])) . ' ml'; }
    foreach ($byKey as $k => $n) {
        if (!isset($want[$k])) { [$nm, $ml] = explode('|', $k, 2); $errors[] = 'a scanned bottle (' . $nm . ' ' . $ml . ' ml) has no line on this order'; }
        elseif ($n > $want[$k]) $errors[] = $names[$k] . ': ' . $n . ' scanned but the line says ' . $want[$k];
    }
    $missing = [];
    if (!$oid && $out) foreach ($want as $k => $q) { $n = $byKey[$k] ?? 0; if ($n < $q) $missing[] = $names[$k] . ': ' . $n . ' of ' . $q . ' scanned'; }
    return ['errors' => $errors, 'missing' => $missing];
}
function bc_returned_on(PDO $pdo, string $code, int $oid): bool
{
    $no = bc_all($pdo, "SELECT order_no FROM retail_orders WHERE order_id = ?", [$oid]); if (!$no) return false;
    return (bool)bc_all($pdo, "SELECT 1 FROM retail_bc_log WHERE code = ? AND action = 'return' AND order_no = ? LIMIT 1", [$code, (string)$no[0]['order_no']]);
}
/** AFTER the order is saved: link its numbers (the order already moved the stock, so retail_stock_log is not written here) */
function bc_order_link(PDO $pdo, int $oid, string $no, int $shopId, string $type, array $codes, string $who): int
{
    if (!bc_ensure($pdo)) return 0;
    $codes = bc_codes($codes); $now = bc_now(); $n = 0;
    if (in_array($type, ['sale', 'display', 'replacement'], true)) {
        foreach (bc_all($pdo, "SELECT code, city FROM retail_bc WHERE order_id = ? AND status = 'shop'", [$oid]) as $r) {   // taken off the order while editing: back in stock
            if (in_array((string)$r['code'], $codes, true)) continue;
            $pdo->prepare("UPDATE retail_bc SET status = 'stock', shop_id = NULL, order_id = NULL, order_no = '', moved_at = ? WHERE code = ?")->execute([$now, (string)$r['code']]);
            bc_log($pdo, (string)$r['code'], 'unlink', $shopId, $no, (string)$r['city'], 'taken off the order', $who);
        }
        $rows = bc_get($pdo, $codes);
        foreach ($codes as $c) {
            $r = $rows[$c] ?? null; if (!$r || ((string)$r['status'] === 'shop' && (int)$r['order_id'] === $oid)) continue;
            $pdo->prepare("UPDATE retail_bc SET status = 'shop', shop_id = ?, order_id = ?, order_no = ?, moved_at = ? WHERE code = ?")->execute([$shopId, $oid, $no, $now, $c]);
            bc_log($pdo, $c, 'out', $shopId, $no, (string)$r['city'], '', $who); $n++;
        }
    } elseif ($type === 'return') {
        $rows = bc_get($pdo, $codes);
        foreach ($codes as $c) {
            $r = $rows[$c] ?? null; if (!$r || (string)$r['status'] !== 'shop') continue;
            $pdo->prepare("UPDATE retail_bc SET status = 'stock', shop_id = NULL, order_id = NULL, order_no = '', moved_at = ? WHERE code = ?")->execute([$now, $c]);
            bc_log($pdo, $c, 'return', $shopId, $no, (string)$r['city'], 'return order', $who); $n++;
        }
    }
    return $n;
}

/** retail.bc_* (called from api.php after the login and the 'retail' permission check) */
function bc_dispatch(PDO $pdo, string $action, array $auth): void
{
    if (!bc_ensure($pdo)) json_response(['error' => 'Could not create the barcode tables.'], 500);
    if (function_exists('ensure_retail_stock_tables')) ensure_retail_stock_tables($pdo);
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; $in = $post ? json_input() : [];
    $need = function () use ($post) { if (!$post) json_response(['error' => 'Method not allowed.'], 405); };
    try {
        switch ($action) {
            case 'retail.bc_make': $need(); json_response(bc_make($pdo, $in, $auth)); break;
            case 'retail.bc_scan': json_response(bc_scan($pdo, (string)($_GET['code'] ?? ''))); break;
            case 'retail.bc_out': $need(); json_response(bc_out($pdo, $in, $auth)); break;
            case 'retail.bc_return': $need(); json_response(bc_return($pdo, $in, $auth)); break;
            case 'retail.bc_void': {
                $need(); $c = bc_clean($in['code'] ?? ''); $r = $c !== '' ? (bc_get($pdo, [$c])[$c] ?? null) : null;
                if (!$r) throw new RuntimeException('Number not found.');
                if ((string)$r['status'] === 'shop') throw new RuntimeException('This bottle is at a shop: take it back first (Return).');
                $pdo->prepare("UPDATE retail_bc SET status = 'void', moved_at = ? WHERE code = ?")->execute([bc_now(), $c]);
                bc_log($pdo, $c, 'void', null, '', (string)$r['city'], (string)($in['note'] ?? ''), (string)($auth['username'] ?? ''));
                json_response(['message' => 'Number ' . $c . ' cancelled (stock not changed).']); break;
            }
            case 'retail.bc_list': json_response(bc_list($pdo, $_GET)); break;
            case 'retail.bc_order_codes': { $oid = (int)($_GET['order_id'] ?? 0); $l = bc_list($pdo, ['order_id' => $oid, 'status' => 'shop']);
                $ret = []; $no = bc_all($pdo, "SELECT order_no, order_type FROM retail_orders WHERE order_id = ?", [$oid]);
                if ($no && (string)$no[0]['order_type'] === 'return') { $cs = array_column(bc_all($pdo, "SELECT DISTINCT code FROM retail_bc_log WHERE action = 'return' AND order_no = ?", [(string)$no[0]['order_no']]), 'code'); $ret = array_map(function ($r) { return bc_row($r, []); }, array_values(bc_get($pdo, $cs))); }
                json_response(['rows' => $no && (string)$no[0]['order_type'] === 'return' ? $ret : $l['rows']]); break; }
            case 'retail.bc_orders': json_response(bc_orders($pdo, (int)($_GET['shop_id'] ?? 0))); break;
            default: json_response(['error' => 'Unknown barcode action.'], 404);
        }
    } catch (RuntimeException $e) { json_response(['error' => $e->getMessage()], 422); }
}
