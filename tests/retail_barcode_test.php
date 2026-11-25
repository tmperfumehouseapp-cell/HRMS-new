<?php
// Shop wholesale → Barcodes (empmanagment/retail_barcode.php): numbers per bottle, stock in / out / return, linked to orders. SQLite.
class JrOut extends Exception { public $data; public $http; function __construct($d, $c) { parent::__construct('jr'); $this->data = $d; $this->http = $c; } }
function json_response($d, int $c = 200): void { throw new JrOut($d, $c); }
function json_input(): array { return $GLOBALS['IN']; }
function retail_shop_city($pdo, $id) { return $id === 2 ? 'Vadodara' : 'Surat'; }
$GLOBALS['BC_TABLES_READY'] = 1;
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    "CREATE TABLE retail_bc (code TEXT PRIMARY KEY, product_name TEXT, ml TEXT, city TEXT, status TEXT DEFAULT 'stock', shop_id INT, order_id INT, order_no TEXT DEFAULT '', batch TEXT DEFAULT '', created_at TEXT, created_by_name TEXT DEFAULT '', moved_at TEXT)",
    "CREATE TABLE retail_bc_log (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, action TEXT, shop_id INT, order_no TEXT DEFAULT '', city TEXT DEFAULT '', note TEXT DEFAULT '', by_name TEXT DEFAULT '', at TEXT)",
    "CREATE TABLE retail_stock_log (log_id INTEGER PRIMARY KEY AUTOINCREMENT, product_name TEXT, ml TEXT, qty INT, kind TEXT, note TEXT, city TEXT, created_by INT, created_by_name TEXT, created_at TEXT)",
    "CREATE TABLE retail_shops (shop_id INTEGER PRIMARY KEY, shop_name TEXT, area TEXT DEFAULT '', owner_name TEXT DEFAULT '', mobile TEXT DEFAULT '')",
    "CREATE TABLE retail_orders (order_id INTEGER PRIMARY KEY, shop_id INT, order_no TEXT, order_type TEXT, order_date TEXT, qty INT, billed_total REAL, payment TEXT DEFAULT 'pending')",
    "CREATE TABLE retail_order_items (id INTEGER PRIMARY KEY, order_id INT, product_name TEXT, ml TEXT, qty INT)",
] as $q) $pdo->exec($q);
$pdo->exec("INSERT INTO retail_shops VALUES (1, 'Raj Stores', 'Adajan', '', ''), (2, 'Vad Shop', 'Alkapuri', '', '')");
$pdo->exec("INSERT INTO retail_orders VALUES (10, 1, 'R0010', 'display', '2026-11-25', 2, 400, 'pending')");
$pdo->exec("INSERT INTO retail_order_items VALUES (1, 10, 'Bdc', '20 ml', 2)");
require __DIR__ . '/../empmanagment/retail_barcode.php';
$fail = 0; function ok($c, $m) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; }
function call($a, $in = [], $get = []) { global $pdo; $GLOBALS['IN'] = $in; $_GET = $get; $_SERVER['REQUEST_METHOD'] = $in ? 'POST' : 'GET'; try { bc_dispatch($pdo, $a, ['username' => 'boss', 'employee_id' => 1]); } catch (JrOut $e) { return [$e->http, $e->data]; } return [0, null]; }
$stock = function ($n) use ($pdo) { return (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM retail_stock_log WHERE product_name = '$n'")->fetchColumn(); };

[$c, $b] = call('retail.bc_make', ['product_name' => 'Bdc', 'ml' => '20', 'qty' => 5, 'city' => 'Surat', 'add_stock' => 1]);
ok($c === 200 && count($b['codes']) === 5 && count(array_unique($b['codes'])) === 5 && preg_match('/^\d{6}$/', $b['codes'][0]), '5 different 6-digit numbers');
ok($stock('Bdc') === 5, 'new arrival: +5 in stock');
[$c, $l] = call('retail.bc_make', ['product_name' => 'Oud', 'ml' => '50 ml', 'qty' => 2, 'city' => 'Surat', 'add_stock' => 0]); ok($stock('Oud') === 0 && count($l['codes']) === 2, 'labels for counted stock: stock not changed');
$k = $b['codes'];
[$c, $s] = call('retail.bc_scan', [], ['code' => substr($k[0], 0, 3) . ' ' . substr($k[0], 3)]); ok($c === 200 && $s['piece']['status'] === 'stock' && $s['piece']['product_name'] === 'Bdc' && $s['piece']['ml'] === '20', 'scan "123 456" finds the bottle');
[$c, $e] = call('retail.bc_out', ['codes' => [$k[0], $k[1], $k[2]], 'shop_id' => 1, 'order_id' => 10]); ok($c === 422 && strpos($e['error'], 'not on order') !== false, 'an order of 2 cannot take 3 numbers (nothing saved)');
[$c, $o] = call('retail.bc_out', ['codes' => [$k[0], $k[1]], 'shop_id' => 1, 'order_id' => 10]); ok($c === 200 && $stock('Bdc') === 5, 'linked to the order: the order already took the stock, no double minus');
[$c, $s] = call('retail.bc_scan', [], ['code' => $k[0]]); ok($s['piece']['status'] === 'shop' && $s['piece']['shop_name'] === 'Raj Stores' && $s['piece']['order_no'] === 'R0010' && $s['history'][0]['action'] === 'out', 'scan tells the shop and the order');
[$c, $e] = call('retail.bc_out', ['codes' => [$k[0]], 'shop_id' => 1]); ok($c === 422 && strpos($e['error'], 'already at Raj Stores') !== false, 'a bottle at a shop cannot go out again');
[$c, $e] = call('retail.bc_out', ['codes' => [$k[2]], 'shop_id' => 2]); ok($c === 422 && strpos($e['error'], 'Vadodara') !== false, 'Surat stock cannot go to a Vadodara shop');
[$c, $o] = call('retail.bc_out', ['codes' => [$k[2]], 'shop_id' => 1]); ok($c === 200 && $stock('Bdc') === 4, 'out without an order: -1 stock');
[$c, $o] = call('retail.bc_orders', [], ['shop_id' => 1]); ok($o['orders'][0]['linked'] === 2 && $o['orders'][0]['items'][0]['ml'] === '20', 'shop orders show how many numbers they have');
[$c, $r] = call('retail.bc_return', ['codes' => [$k[2]]]); ok($c === 200 && $stock('Bdc') === 5, 'return: +1 stock');
[$c, $L] = call('retail.bc_list', [], ['shop_id' => '1']); ok(count($L['rows']) === 2 && $L['totals']['shop'] === 2, 'list of one shop');
[$c, $L] = call('retail.bc_list', [], []); ok($L['totals']['stock'] === 5 && $L['totals']['shop'] === 2, 'summary: 5 in stock, 2 at shops');
[$c, $v] = call('retail.bc_void', ['code' => $k[0]]); ok($c === 422, 'a bottle at a shop cannot be cancelled');
[$c, $v] = call('retail.bc_void', ['code' => $k[4]]); [$c, $s] = call('retail.bc_scan', [], ['code' => $k[4]]); ok($s['piece']['status'] === 'void' && $stock('Bdc') === 5, 'cancel a label (stock unchanged)');
[$c, $e] = call('retail.bc_scan', [], ['code' => '12345']); ok($c === 422, 'not 6 digits');

// orders: scanned bottles fill the order; a new order needs one number per piece
[$c, $m] = call('retail.bc_make', ['product_name' => 'Rose', 'ml' => '50', 'qty' => 3, 'city' => 'Surat', 'add_stock' => 1]); $R = $m['codes'];
$it = [['Rose', '50 ml', 2, 2, 300, 600]];
$x = bc_order_check($pdo, 0, 1, 'sale', $it, [$R[0]]); ok(!$x['errors'] && $x['missing'] === ['Rose 50 ml: 1 of 2 scanned'], 'new order: one bottle short is reported');
$x = bc_order_check($pdo, 0, 1, 'sale', $it, [$R[0], $R[1], $R[2]]); ok(strpos(implode('|', $x['errors']), '3 scanned but the line says 2') !== false, 'more numbers than pieces is an error');
$x = bc_order_check($pdo, 0, 2, 'sale', $it, [$R[0], $R[1]]); ok($x['errors'] && strpos($x['errors'][0], 'Vadodara') !== false, 'Surat bottles cannot go on a Vadodara order');
$x = bc_order_check($pdo, 0, 1, 'sale', [['Musk', '20', 1, 1, 0, 0]], [$R[0]]); ok(strpos(implode('|', $x['errors']), 'has no line') !== false, 'a bottle with no line on the order');
$x = bc_order_check($pdo, 0, 1, 'sale', $it, [$R[0], $R[1]]); ok(!$x['errors'] && !$x['missing'], 'every piece scanned: ok');
$pdo->exec("INSERT INTO retail_orders VALUES (11, 1, 'R0011', 'sale', '2026-11-25', 2, 600, 'pending')");
bc_order_link($pdo, 11, 'R0011', 1, 'sale', [$R[0], $R[1]], 'boss'); [$c, $s1] = call('retail.bc_scan', [], ['code' => $R[0]]);
ok($s1['piece']['order_no'] === 'R0011' && $s1['piece']['shop_name'] === 'Raj Stores' && $stock('Rose') === 3, 'saved order links its bottles (stock moved by the order itself)');
$x = bc_order_check($pdo, 11, 1, 'sale', [['Rose', '50', 1, 1, 300, 300]], [$R[0]]); ok(!$x['errors'] && !$x['missing'], 'editing the order: its own bottles are fine');
bc_order_link($pdo, 11, 'R0011', 1, 'sale', [$R[0]], 'boss'); [$c, $s2] = call('retail.bc_scan', [], ['code' => $R[1]]); ok($s2['piece']['status'] === 'stock', 'a bottle taken off the order goes back to stock');
[$c, $oc] = call('retail.bc_order_codes', [], ['order_id' => '11']); ok(count($oc['rows']) === 1 && $oc['rows'][0]['code'] === $R[0], 'order shows its numbers');
$pdo->exec("INSERT INTO retail_orders VALUES (12, 1, 'R0012', 'return', '2026-11-26', 1, 0, 'free')");
$x = bc_order_check($pdo, 0, 1, 'return', [['Rose', '50', 1, 1, 0, 0]], [$R[2]]); ok($x['errors'] && strpos($x['errors'][0], 'not at this shop') !== false, 'return: only bottles at this shop');
bc_order_link($pdo, 12, 'R0012', 1, 'return', [$R[0]], 'boss'); [$c, $s3] = call('retail.bc_scan', [], ['code' => $R[0]]); ok($s3['piece']['status'] === 'stock' && $stock('Rose') === 3, 'return order brings the bottle back (stock counted by the order)');
[$c, $oc] = call('retail.bc_order_codes', [], ['order_id' => '12']); ok(count($oc['rows']) === 1, 'return order shows its numbers');
echo $fail ? "\n$fail FAILED\n" : "\nall passed\n"; exit($fail ? 1 : 0);
