<?php
// Shop wholesale → Routes (empmanagment/retail_route.php): area-wise split into 10 days, new shops join their area, drag, ⭐, learning, colours, plan sync. SQLite.
class JrOut extends Exception { public $data; public $http; function __construct($d, $c) { parent::__construct('jr'); $this->data = $d; $this->http = $c; } }
function json_response($d, int $c = 200): void { throw new JrOut($d, $c); }
function json_input(): array { return $GLOBALS['IN']; }
function rr_today_override() { return $GLOBALS['TODAY']; }
function retail_default_city($pdo) { return 'Surat'; }
function retail_city_sql($pdo, $a = 's') { return "(CASE WHEN $a.city = '' THEN 'Surat' ELSE $a.city END)"; }
function retail_ledger_balances($pdo, $ids) { return [1 => 1500.0]; }
function retail_display_rows($pdo, $id) { return $id === 1 ? [['product_name' => 'Oud', 'qty' => 3]] : []; }
$GLOBALS['RR_TABLES_READY'] = 1; $GLOBALS['TODAY'] = '2026-11-25';
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    "CREATE TABLE retail_shops (shop_id INTEGER PRIMARY KEY, shop_name TEXT, owner_name TEXT DEFAULT '', mobile TEXT DEFAULT '', area TEXT DEFAULT '', lat REAL, lng REAL, status TEXT DEFAULT 'active', city TEXT DEFAULT '')",
    "CREATE TABLE retail_route_stop (stop_id INTEGER PRIMARY KEY AUTOINCREMENT, city TEXT, route_no INT, shop_id INT, sort_no INT, priority INT DEFAULT 0, disp_flag INT DEFAULT 0, updated_at TEXT, UNIQUE (city, shop_id))",
    "CREATE TABLE retail_route_cfg (city TEXT PRIMARY KEY, start_date TEXT, days INT, learned TEXT, made_by TEXT DEFAULT '', made_at TEXT)",
    "CREATE TABLE retail_route_synced (plan_id INT PRIMARY KEY, city TEXT, plan_date TEXT)",
    "CREATE TABLE retail_visit_plan (plan_id INTEGER PRIMARY KEY AUTOINCREMENT, plan_date TEXT, shop_id INT, status TEXT DEFAULT 'pending', approval TEXT DEFAULT '', done_at TEXT, note TEXT DEFAULT '', created_by INT, created_at TEXT, UNIQUE (plan_date, shop_id))",
    "CREATE TABLE retail_visits (visit_id INTEGER PRIMARY KEY AUTOINCREMENT, shop_id INT, employee_id INT, visit_at TEXT)",
    "CREATE TABLE employees (employee_id INTEGER PRIMARY KEY, full_name TEXT)",
    "CREATE TABLE retail_orders (order_id INTEGER PRIMARY KEY, shop_id INT, order_type TEXT, order_date TEXT, qty INT, delivered_qty INT, billed_total REAL, status TEXT DEFAULT 'delivered')",
    "CREATE TABLE retail_payments (payment_id INTEGER PRIMARY KEY, shop_id INT, pay_date TEXT, amount REAL)",
    "CREATE TABLE retail_shop_perfumes (id INTEGER PRIMARY KEY, shop_id INT, perfume TEXT, sort_no INT DEFAULT 0)",
] as $q) $pdo->exec($q);
require __DIR__ . '/../empmanagment/retail_route.php';
$fail = 0; function ok($c, $m) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; }
function call($a, $in = [], $get = []) { global $pdo; $GLOBALS['IN'] = $in; $_GET = $get; $_SERVER['REQUEST_METHOD'] = $in ? 'POST' : 'GET'; try { rr_dispatch($pdo, $a, ['username' => 'boss']); } catch (JrOut $e) { return [$e->http, $e->data]; } return [0, null]; }

// 120 Surat shops in 12 areas of 10 (pinned along a line), 5 Vadodara shops, 1 inactive
$ins = $pdo->prepare("INSERT INTO retail_shops (shop_id, shop_name, area, lat, lng, city, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
for ($i = 1; $i <= 120; $i++) { $a = intdiv($i - 1, 10); $ins->execute([$i, "Shop $i", "Area $a", 21.0 + $a * 0.02 + ($i % 10) * 0.0005, 72.8, $i % 3 ? '' : 'Surat', 'active']); }
for ($i = 201; $i <= 205; $i++) $ins->execute([$i, "Vad $i", 'Alkapuri', null, null, 'Vadodara', 'active']);
$ins->execute([300, 'Closed', 'Area 1', null, null, '', 'inactive']);

[$c, $b] = call('retail.route_board'); ok($c === 200 && !$b['has_route'] && $b['city'] === 'Surat' && $b['active_shops'] === 120, 'no route yet; "All cities" means the default city');
[$c, $b] = call('retail.route_make', ['days' => 10]);
ok($c === 200 && array_sum($b['sizes']) === 120 && count($b['sizes']) === 10, 'every active Surat shop on one of 10 routes: ' . implode(',', $b['sizes']));
ok(min($b['sizes']) >= 8 && max($b['sizes']) <= 16, 'routes are about the same size');
ok($b['start_date'] === '2026-11-26' && $b['days'][0]['date'] === '2026-11-26' && $b['days'][9]['date'] === '2026-12-05', 'starts tomorrow, 10 days');
$split = 0; $areaDays = []; foreach ($b['days'] as $d) foreach ($d['cards'] as $x) $areaDays[$x['area']][$d['route_no']] = 1; foreach ($areaDays as $a => $ds) if (count($ds) > 1) $split++;
ok($split <= 3, "areas mostly stay on one day ($split split)");
[$c, $b2] = call('retail.route_board', [], ['c' => '1']); ok($b2['days'][0]['date'] === '2026-12-06' && $b2['days'][0]['route_no'] === 1, 'next 10 days: route 1 again on day 11');
[$c, $v] = call('retail.route_make', ['days' => 10, 'city' => 'Vadodara']); ok($v['city'] === 'Vadodara' && array_sum($v['sizes']) === 5, 'Vadodara has its own route');

// new shop joins the route of its area, right after its area mates
$pdo->exec("INSERT INTO retail_shops (shop_id, shop_name, area, lat, lng, city) VALUES (121, 'New one', 'Area 5', NULL, NULL, '')");
[$c, $b] = call('retail.route_board'); $r5 = null; $rNew = null;
foreach ($b['days'] as $d) foreach ($d['cards'] as $x) { if ($x['shop_id'] === 121) $rNew = $d['route_no']; if ($x['shop_id'] === 55) $r5 = $d['route_no']; }
ok($rNew !== null && $rNew === $r5 && $b['changes']['added'] === 1, 'a new shop goes on its area\'s route');

// drag to another day, ⭐ to the top
$day3 = $b['days'][2]['cards']; $mv = $b['days'][0]['cards'][0]['shop_id'];
[$c, $b] = call('retail.route_move', ['shop_id' => $mv, 'route_no' => 3, 'index' => 1]); ok($c === 200 && $b['days'][2]['cards'][1]['shop_id'] === $mv, 'dragged to day 3, 2nd place');
$last = end($b['days'][2]['cards'])['shop_id'];
[$c, $b] = call('retail.route_flag', ['shop_id' => $last, 'priority' => 1]); ok($b['days'][2]['cards'][0]['shop_id'] === $last && $b['days'][2]['cards'][0]['priority'] === 1, '⭐ shop is visited first');

// colours: today = day 1 (move the start back one day)
$pdo->exec("UPDATE retail_route_cfg SET start_date = '2026-11-24' WHERE city = 'Surat'");
[$c, $b] = call('retail.route_board'); $d1 = $b['days'][0]['cards']; $d2 = $b['days'][1]['cards'];
ok($b['days'][1]['is_today'] && $d1[0]['status'] === 'missed' && $d2[0]['status'] === 'today' && $b['days'][3]['cards'][0]['status'] === 'next', 'yesterday not visited = red, today, later');
$a = $d2[0]['shop_id']; $bq = $d2[1]['shop_id']; $cq = $d2[2]['shop_id'];
$pdo->exec("INSERT INTO retail_visits (shop_id, employee_id, visit_at) VALUES ($cq, 1, '2026-11-25 10:00:00'), ($a, 1, '2026-11-25 11:00:00')");
$pdo->exec("INSERT INTO retail_orders (shop_id, order_type, order_date, qty, delivered_qty, billed_total) VALUES ($a, 'display', '2026-11-25', 10, 6, 2000)");
$pdo->exec("INSERT INTO retail_shop_perfumes (shop_id, perfume) VALUES (1, 'Oud'), (1, 'Rose')");
[$c, $b] = call('retail.route_board'); $byId = []; foreach ($b['days'] as $d) foreach ($d['cards'] as $x) $byId[$x['shop_id']] = $x;
ok($byId[$cq]['status'] === 'done' && $byId[$a]['status'] === 'disp', 'visited = green; visited with display pieces still to give = purple');
ok($byId[$a]['orders'] === 1 && $byId[$a]['order_amt'] == 2000 && $byId[$a]['disp_todo'] === 4, 'order of the cycle and display still to give on the card');
ok($byId[1]['missing'] === ['Rose'] && $byId[1]['disp_pcs'] === 3 && $byId[1]['balance'] == 1500, 'perfume missing from the display + outstanding');
$ord = array_column($b['days'][1]['cards'], 'shop_id'); ok($ord[0] === $cq && $ord[1] === $a && $ord[2] === $bq, 'route learns the order the salesman walked: ' . implode(',', array_slice($ord, 0, 3)));

// Visits plan sync: today's route is in the plan, in order; a moved pending shop leaves it; done visits stay
$n = rr_sync_day($pdo, 'Surat', '2026-11-25'); $plan = array_map('intval', $pdo->query("SELECT shop_id FROM retail_visit_plan WHERE plan_date = '2026-11-25'")->fetchAll(PDO::FETCH_COLUMN));
ok(count($plan) === count($b['days'][1]['cards']), 'today\'s route copied into the Visits plan (' . count($plan) . ')');
call('retail.route_move', ['shop_id' => $bq, 'route_no' => 7, 'index' => 0]); rr_sync_day($pdo, 'Surat', '2026-11-25');
ok(!in_array($bq, array_map('intval', $pdo->query("SELECT shop_id FROM retail_visit_plan WHERE plan_date = '2026-11-25'")->fetchAll(PDO::FETCH_COLUMN)), true), 'a shop moved to another day leaves today\'s plan');
ok(rr_sync_day($pdo, 'Surat', '2026-11-20') === 0, 'past days are never changed');
$ann = rr_annotate($pdo, ['date' => '2026-11-25', 'rows' => [['shop_id' => $cq], ['shop_id' => 999]]]); ok($ann['rows'][0]['route_pos'] === 1 && $ann['rows'][1]['route_no'] === 0, 'Visits rows get their place on the route');
[$c, $b] = call('retail.route_clear', ['city' => 'Vadodara']); ok(!$b['has_route'] && rr_cfg($pdo, 'Surat'), 'clear one city only');
echo $fail ? "\n$fail FAILED\n" : "\nall passed\n"; exit($fail ? 1 : 0);
