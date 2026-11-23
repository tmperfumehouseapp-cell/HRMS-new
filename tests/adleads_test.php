<?php
// Ad leads of one day (wa_adleads.php) on a small in-memory SQLite copy of the three tables it reads.
date_default_timezone_set('Asia/Kolkata');
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (["CREATE TABLE wa_contacts (phone TEXT PRIMARY KEY, name TEXT DEFAULT '', ad_id TEXT DEFAULT '', ad_head TEXT DEFAULT '', ref_text TEXT DEFAULT '')",
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

$r = wag_adleads($pdo, $D, 0);
ok($r['total'] === 3, 'three ad leads that day, got ' . $r['total']);
$by = []; foreach ($r['rows'] as $x) $by[$x['phone']] = $x;
ok(!isset($by['9000000003']) && !isset($by['9000000004']), 'a campaign query and another day are left out');
ok($by['9000000001']['first'] === 'Price kya hai?', 'the first INCOMING message of that day');
ok($by['9000000001']['number'] === 'Main' && $by['9000000001']['ad'] === 'Oud offer' && $by['9000000001']['new'] === true, 'number, ad and new');
ok($by['9000000002']['first'] === '[image]' && $by['9000000002']['number'] === '+91 99999 00002' && $by['9000000002']['ad'] === 'Meta ad' && $by['9000000002']['new'] === false, 'a photo, the number without a label, returning');
ok($by['9000000005']['first'] === 'First of the day', 'one line per customer, with the first message');
ok($r['new'] === 2 && $by['9000000005']['new'] === true, 'new leads: a customer with two queries that day is new when the FIRST one was, got ' . $r['new']);
$n = []; foreach ($r['numbers'] as $x) $n[$x['id']] = $x['n']; ok($n[1] === 2 && $n[2] === 1, 'leads per number');
$r1 = wag_adleads($pdo, $D, 1); ok($r1['total'] === 2 && $r1['all'] === 3, 'the number filter');
$n1 = []; foreach ($r1['numbers'] as $x) $n1[$x['id']] = $x['n']; ok($n1[2] === 1, 'the chips still count every number');
ok(wag_adleads($pdo, 'nonsense', 0)['date'] === date('Y-m-d'), 'a bad date means today');
ok(wag_adleads($pdo, $D, 0, 2)['more'] === true, 'more than the limit is said');
echo "adleads_test ok\n";
