<?php
// Ad leads of one day (wa_adleads.php) on a small in-memory SQLite copy of the three tables it reads.
date_default_timezone_set('Asia/Kolkata');
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (["CREATE TABLE wa_contacts (phone TEXT PRIMARY KEY, name TEXT DEFAULT '', ad_id TEXT DEFAULT '', ad_head TEXT DEFAULT '', ref_text TEXT DEFAULT '', assigned_emp INT DEFAULT 0)",
    "CREATE TABLE employees (employee_id INTEGER PRIMARY KEY, full_name TEXT)",
    "CREATE TABLE shop_orders (shopify_id INTEGER, store_key TEXT, order_name TEXT, created_at TEXT, phone TEXT, total REAL, financial_status TEXT DEFAULT '', outstanding REAL)",
    "CREATE TABLE order_allocations (source TEXT, order_id TEXT, employee_id INT)",
    "CREATE TABLE wmo_order (id INTEGER PRIMARY KEY AUTOINCREMENT, order_no TEXT, phone TEXT, emp_id INT, assign_emp INT, status TEXT, pay TEXT, total_p INT, paid_p INT, utr TEXT DEFAULT '', shot_mime TEXT DEFAULT '', created_at TEXT)",
    "CREATE TABLE wa_queries (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, opened_at TEXT, src TEXT DEFAULT '', again INT DEFAULT 0)",
    "CREATE TABLE wa_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, number_id INT DEFAULT 0, dir TEXT, mtype TEXT DEFAULT 'text', body TEXT, ts TEXT)",
    "CREATE TABLE wa_numbers (id INTEGER PRIMARY KEY, label TEXT DEFAULT '', display TEXT DEFAULT '')"] as $q) $pdo->exec($q);
require dirname(__DIR__) . '/empmanagment/wa_adleads.php';
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$pdo->exec("INSERT INTO wa_numbers VALUES (1, 'Main', '0275'), (2, '', '+91 99999 00002')");
$c = $pdo->prepare("INSERT INTO wa_contacts (phone, name, ad_id, ad_head, ref_text) VALUES (?, ?, ?, ?, ?)");
$q = $pdo->prepare("INSERT INTO wa_queries (phone, opened_at, src, again) VALUES (?, ?, ?, ?)");
$m = $pdo->prepare("INSERT INTO wa_messages (phone, number_id, dir, mtype, body, ts) VALUES (?, ?, ?, ?, ?, ?)");
$D = '2026-11-23';
$c->execute(['9000000001', 'Asha', 'AD1', 'Oud offer', '']);      $q->execute(['9000000001', "$D 10:00:00", 'query', 0]);
$m->execute(['9000000001', 1, 'in', 'text', 'old message', '2026-11-22 09:00:00']);   // yesterday: not the first message of the day
$m->execute(['9000000001', 1, 'in', 'text', 'Price kya hai?', "$D 10:00:00"]); $m->execute(['9000000001', 1, 'out', 'text', 'Hello!', "$D 10:01:00"]); $m->execute(['9000000001', 1, 'in', 'text', '50ml', "$D 10:02:00"]);
$c->execute(['9000000002', 'Ravi', '', '', 'Meta ad']);           $q->execute(['9000000002', "$D 11:00:00", 'query', 1]);
$m->execute(['9000000002', 2, 'in', 'image', '', "$D 11:00:00"]);
$c->execute(['9000000003', 'Campaign person', '', '', '']);       $q->execute(['9000000003', "$D 12:00:00", 'campaign', 0]);   // not an ad lead
$m->execute(['9000000003', 1, 'in', 'text', 'hi', "$D 12:00:00"]);
$c->execute(['9000000004', 'Yesterday', 'AD1', 'Oud offer', '']); $q->execute(['9000000004', '2026-11-22 23:59:59', 'query', 0]);   // another day
$c->execute(['9000000005', 'Twice', 'AD2', 'Combo', '']);         $q->execute(['9000000005', "$D 08:00:00", 'query', 0]); $q->execute(['9000000005', "$D 20:00:00", 'query', 1]);
$m->execute(['9000000005', 1, 'in', 'text', 'First of the day', "$D 08:00:00"]); $m->execute(['9000000005', 1, 'in', 'text', 'Evening', "$D 20:00:00"]);

$c->execute(['9000000006', 'Pre', '', '', 'Meta ad']);            $q->execute(['9000000006', "$D 09:00:00", 'query', 0]);   // no Meta tag, but the ad's ready-made first message
$m->execute(['9000000006', 1, 'in', 'text', 'Hello! Can I get more info on this?', "$D 09:00:00"]);
ok(wag_adleads_pre('hello! can i get MORE INFO on this?') && wag_adleads_pre('Can I get more information about this') && !wag_adleads_pre('Price kya hai'), 'the ready-made first message');

$r = wag_adleads($pdo, $D, 0, 1500, 'all');
ok($r['total'] === 4, 'four HRMS ad queries that day, got ' . $r['total']);
$by = []; foreach ($r['rows'] as $x) $by[$x['phone']] = $x;
ok(!isset($by['9000000003']) && !isset($by['9000000004']), 'a campaign query and another day are left out');
ok($by['9000000001']['first'] === 'Price kya hai?', 'the first INCOMING message of that day');
ok($by['9000000001']['number'] === 'Main' && $by['9000000001']['ad'] === 'Oud offer' && $by['9000000001']['new'] === true && $by['9000000001']['tag'] === true && $by['9000000001']['pre'] === false, 'number, ad, new, Meta tag');
ok($by['9000000002']['first'] === '[image]' && $by['9000000002']['number'] === '+91 99999 00002' && $by['9000000002']['ad'] === 'Meta ad' && $by['9000000002']['new'] === false, 'a photo, the number without a label, returning');
ok($by['9000000005']['first'] === 'First of the day', 'one line per customer, with the first message');
ok($by['9000000006']['pre'] === true && $by['9000000006']['tag'] === false, 'the ready-made message without a Meta tag');
ok($r['kinds'] == ['rule' => 3, 'pre' => 1, 'tag' => 2, 'other' => 1, 'all' => 4], 'the kinds: ' . json_encode($r['kinds']));
$n = []; foreach ($r['numbers'] as $x) $n[$x['id']] = $x['n']; ok($n[1] === 3 && $n[2] === 1, 'leads per number');
$rr = wag_adleads($pdo, $D, 0); $pr = array_column($rr['rows'], 'phone'); sort($pr);
ok($rr['kind'] === 'rule' && $pr === ['9000000001', '9000000005', '9000000006'] && $rr['new'] === 3, 'the default list: ready-made message OR Meta tag');
ok(array_column(wag_adleads($pdo, $D, 0, 1500, 'pre')['rows'], 'phone') === ['9000000006'] && array_column(wag_adleads($pdo, $D, 0, 1500, 'other')['rows'], 'phone') === ['9000000002'], 'only the ready-made message / only the HRMS guesses');
$r1 = wag_adleads($pdo, $D, 1, 1500, 'all'); ok($r1['total'] === 3 && $r1['all'] === 4 && $r1['kinds']['other'] === 0, 'the number filter (kind chips count the chosen number)');
$n1 = []; foreach ($r1['numbers'] as $x) $n1[$x['id']] = $x['n']; ok($n1[2] === 1, 'the number chips still count every number');
ok(wag_adleads($pdo, 'nonsense', 0)['date'] === date('Y-m-d'), 'a bad date means today');
ok(wag_adleads($pdo, $D, 0, 2)['more'] === true, 'more than the limit is said');
// 🩺 what happened in each chat: orders (Shopify / manual, who), payment proof, photos the customer sent
$pdo->exec("INSERT INTO employees VALUES (7, 'Kiran'), (8, 'Jay')"); $pdo->exec("UPDATE wa_contacts SET assigned_emp = 7 WHERE phone = '9000000005'");
$pdo->exec("INSERT INTO shop_orders VALUES (5001, 'tmperfumehouse', '#5001', '$D 11:30:00', '+91 90000 00001', 1500, 'paid', 0), (5002, 'tmperfumehouse', 'WM1', '$D 12:00:00', '9000000006', 2000, 'paid', 0), (5003, 'tmperfumehouse', '#5003', '$D 12:00:00', '9111111111', 900, 'paid', 0), (5004, 'tmperfumehouse', '#5004', '$D 13:00:00', '9000000001', 700, 'refunded', 0)");
$pdo->exec("INSERT INTO order_allocations VALUES ('tmperfumehouse', '5001', 7)");
$pdo->exec("INSERT INTO wmo_order (order_no, phone, emp_id, assign_emp, status, pay, total_p, paid_p, utr, shot_mime, created_at) VALUES ('WM1', '9000000006', 8, 8, 'confirmed', 'prepaid', 200000, 200000, 'UTR1', '', '$D 11:50:00'), ('WM2', '9000000006', 8, 8, 'cancelled', 'cod', 100000, 0, '', '', '$D 11:55:00')");
$m->execute(['9000000005', 1, 'in', 'image', '', "$D 20:05:00"]); $m->execute(['9000000005', 1, 'in', 'text', 'Payment done bhai', "$D 20:06:00"]);
$ch = wag_adleads_chat($pdo, ['9000000001', '9000000005', '9000000006', '9000000002'], "$D 00:00:00");
ok($ch['9000000001']['flag'] === 'shopify' && count($ch['9000000001']['orders']) === 1 && $ch['9000000001']['orders'][0]['given_to'] === 'Kiran' && $ch['9000000001']['orders'][0]['where'] === 'shopify', 'a Shopify order and who has it (a refunded one is left out)');
$mo = $ch['9000000006']['orders']; ok($ch['9000000006']['flag'] === 'manual' && count($mo) === 1 && $mo[0]['where'] === 'manual' && $mo[0]['placed_by'] === 'Jay' && $mo[0]['proof'] === false && $mo[0]['utr'] === 'UTR1' && $mo[0]['paid'] == 2000, 'a manual order: who placed it, no screenshot attached, cancelled one left out: ' . json_encode($mo));
ok($ch['9000000005']['flag'] === 'photo_no_order' && $ch['9000000005']['photos'] === 1 && $ch['9000000005']['says_paid'] === true && $ch['9000000005']['photo_at'] === "$D 20:05:00", 'a photo and "payment done" but no order');
ok($ch['9000000002']['flag'] === 'photo_no_order' && $ch['9000000002']['says_paid'] === false, 'a photo only');
// 🩺 Diagnose: both sides of one day, and one customer (the tracking service's answer is given by the test)
$svc = [['ads' => [['ad_id' => 'AD1', 'clicks' => 1]], 'orders' => []], ''];
$d = wag_adcheck($pdo, $D, $svc); $ba = []; foreach ($d['hrms']['by_ad'] as $x) $ba[$x['ad_id']] = $x['n'];
ok($d['hrms']['total'] === 3 && $d['hrms']['pre'] === 1 && $d['hrms']['tag'] === 2 && $d['hrms']['other'] === 1 && $d['hrms']['new'] === 3 && $ba == ['AD1' => 1, 'AD2' => 1] && $d['svc']['ads'][0]['clicks'] === 1 && $d['svc_error'] === '', 'diagnose: HRMS leads by the rule, per ad only with a Meta tag: ' . json_encode($d['hrms']));
ok($d['sum']['leads'] === 3 && $d['sum']['shopify'] === 1 && $d['sum']['manual'] === 1 && $d['sum']['photo_no_order'] === 1 && $d['sum']['manual_amt'] == 2000 && isset($d['chat']['9000000006']) && !isset($d['chat']['9000000002']), 'diagnose: what happened to the leads: ' . json_encode($d['sum']));
ok($d['staff'][0]['name'] === 'Jay' && $d['staff'][0]['manual'] === 1 && $d['staff'][0]['no_proof'] === 1 && $d['staff'][1]['name'] === 'Kiran' && $d['staff'][1]['shopify'] === 1, 'diagnose: per salesperson, manual first: ' . json_encode($d['staff']));
$pa = []; foreach ($d['hrms']['by_ad'] as $x) $pa[$x['ad_id']] = [$x['orders'], $x['amt']];
ok($pa['AD1'] == [1, 1500] && $pa['AD2'] == [0, 0] && $d['hrms']['no_tag'] == ['n' => 1, 'orders' => 1, 'amt' => 2000], 'orders per ad, and the leads without an ad tag: ' . json_encode([$pa, $d['hrms']['no_tag']]));
ok(wag_adcheck($pdo, $D, $svc, 2)['sum']['leads'] === 0 && wag_adcheck($pdo, $D, $svc, 1)['sum']['leads'] === 3, 'diagnose for one number');
$d0 = wag_adcheck($pdo, $D, [null, 'down']); ok($d0['svc'] === null && $d0['svc_error'] === 'down' && $d0['hrms']['total'] === 3, 'diagnose: the HRMS side still answers when the service is down');
$o = wag_adcheck_one($pdo, '+91 90000-00001', [['clicks' => [], 'orders' => []], '']);
ok($o['phone'] === '9000000001' && $o['name'] === 'Asha' && $o['ad'] === 'Oud offer' && count($o['queries']) === 1, 'diagnose one customer');
ok(isset(wag_adcheck_one($pdo, '123', [null, ''])['error']), 'a short number is refused');
[$none, $why] = wag_ctwa_get('/diag', 'date', $D, '', 'x'); ok($none === null && $why !== '', 'switched off: no call, a clear reason');
echo "adleads_test ok\n";
