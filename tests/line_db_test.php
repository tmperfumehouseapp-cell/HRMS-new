<?php
// Lineup engine on a small in-memory SQLite copy of the tables it touches (MySQL-only words in the CREATE statements are translated). No Meta, no WhatsApp: wd_send is a stub that records who got which message.
date_default_timezone_set('Asia/Kolkata');
class T extends PDO { public function exec($q) { $q = preg_replace('/\)\s*ENGINE=InnoDB[^;]*$/', ')', $q); $q = str_replace('BIGINT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q); $q = preg_replace('/,\s*KEY\s+\w+\s*\([^)]*\)/', '', $q); return parent::exec($q); } }
$pdo = new T('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach (["CREATE TABLE wa_contacts (phone TEXT PRIMARY KEY, name TEXT DEFAULT '', last_in_at TEXT, optout INT DEFAULT 0, camp_optout INT DEFAULT 0, source TEXT DEFAULT 'query', tag TEXT DEFAULT '', cx_id INT DEFAULT 0)",
 "CREATE TABLE wa_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, wamid TEXT DEFAULT '', phone TEXT, dir TEXT, mtype TEXT DEFAULT 'text', body TEXT, status TEXT DEFAULT 'received', who TEXT DEFAULT 'customer', ts TEXT)",
 "CREATE TABLE wa_cust (phone TEXT PRIMARY KEY, n INT DEFAULT 0, last_order TEXT)", "CREATE TABLE shop_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, created_at TEXT, total REAL, tags TEXT DEFAULT '')",
 "CREATE TABLE wa_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, category TEXT DEFAULT 'UTILITY', relabelled_at TEXT)", "CREATE TABLE wa_tpl_waba (tpl_id INT, waba_id TEXT, status TEXT)",
 "CREATE TABLE wa_fu_ev (phone TEXT, status TEXT, sent_at TEXT)", "CREATE TABLE wa_wj_ev (phone TEXT, status TEXT, sent_at TEXT)"] as $q) $pdo->exec($q);
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; }
function json_response($a, $c = 200) { throw new Exception('json_response'); } function wa_log($p, $k, $ok, $i) { }
function wa_key($n) { $d = preg_replace('/\D+/', '', (string)$n); return strlen($d) > 10 ? substr($d, -10) : $d; }
function wa_contact($p, $k) { $q = $p->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$k]); return $q->fetch() ?: null; }
function wa_lock($p, $n, $s) { return true; }
$SENT = []; function wd_send($pdo, $c, $step) { global $SENT; $SENT[] = [$c['phone'], $step['tpl_id']]; return [true, '', 1, 'wamid' . count($SENT)]; }
$pdo_ = $pdo; $GLOBALS['pdo'] = $pdo; require dirname(__DIR__) . '/empmanagment/wa_line.php';
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$now = time(); $since = wl_yesterday_start($now); $f = fn($t) => date('Y-m-d H:i:s', $t);
// customers who wrote after "yesterday 10:00 IST"
$cust = ['9810000001' => ['asli hai kya?'], '9810000002' => ['price kya hai'], '9810000003' => ['50ml'], '9810000004' => ['address bhej raha hu'], '9810000005' => ['hello'], '9810000006' => ['original hai'], '9810000007' => ['rate?']];
$t0 = $since + 3600; $i = 0;
foreach ($cust as $ph => $txt) { $t = $t0 + $i * 600; $i++; $pdo->prepare("INSERT INTO wa_contacts (phone, name, last_in_at) VALUES (?, ?, ?)")->execute([$ph, 'Cust' . $i, $f($t)]);
    foreach ($txt as $x) $pdo->prepare("INSERT INTO wa_messages (phone, dir, body, ts) VALUES (?, 'in', ?, ?)")->execute([$ph, $x, $f($t)]); }
$pdo->exec("INSERT INTO wa_cust (phone, n) VALUES ('9810000005', 2)");   // 5 bought before -> 'reorder'
$pdo->prepare("INSERT INTO shop_orders (phone, created_at, total) VALUES ('+919810000007', ?, 899)")->execute([$f($t0 + 5000)]);   // 7 ordered after writing -> not enrolled
$pdo->prepare("INSERT INTO wa_contacts (phone, name, last_in_at, optout) VALUES ('9810000008', 'Opt', ?, 1)")->execute([$f($t0)]);
ok(wln_tables($pdo), 'tables');
$n = wl_enroll($pdo, $since); ok($n === 5 || $n === 6, "enrolled: $n"); 
$tp = []; foreach ($pdo->query("SELECT phone, topic FROM wa_line_cust")->fetchAll() as $r) $tp[$r['phone']] = $r['topic'];
ok(($tp['9810000001'] ?? '') === 'trust' && ($tp['9810000002'] ?? '') === 'price' && ($tp['9810000003'] ?? '') === 'size' && ($tp['9810000004'] ?? '') === 'pending' && ($tp['9810000005'] ?? '') === 'reorder' && ($tp['9810000006'] ?? '') === 'trust', 'topics ' . json_encode($tp));
ok(!isset($tp['9810000007']), 'a customer who already ordered is not enrolled'); ok(!isset($tp['9810000008']), 'opted out is not enrolled');
ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan")->fetchColumn() === 6 * 7, 'seven rows each');
ok(wl_enroll($pdo, $since) === 0, 'a second enrol adds nobody');
// the sending: templates made (ids) and approved
$ids = []; $k = 0; foreach (wl_pack() as $key => $m) { $k++; $ids[$key] = $k; $pdo->exec("INSERT INTO wa_templates (id) VALUES ($k)"); $pdo->exec("INSERT INTO wa_tpl_waba VALUES ($k, 'w1', 'APPROVED')"); } setting_set($pdo, 'wl_tpls', json_encode($ids));
wl_cfg_put($pdo, ['mode' => 'auto', 'cap' => 60, 'from' => 0, 'to' => 24, 'since' => $since, 'started' => '2026-11-21 00:00:00', 'offer' => '']);
ok(wl_ready($pdo), 'all approved -> ready');
// the day-1 steps are due 24 h after the first message: move the clock by making them due
$pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE step = 1");
$r = wl_tick($pdo, ['now' => 1, 'cap' => 60]); ok($r['sent'] === 6, 'six step-1 messages sent, got ' . json_encode($r));
ok(count($SENT) === 6, 'stub got six'); ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'sent'")->fetchColumn() === 6, 'six rows marked sent');
$r = wl_tick($pdo, ['now' => 1, 'cap' => 60]); ok($r['sent'] === 0, 'nothing more is due: one message per customer');
// a customer orders -> out; a customer replies -> ladder stops
$pdo->prepare("INSERT INTO shop_orders (phone, created_at, total) VALUES ('9810000002', ?, 1200)")->execute([$f($now + 10)]);
$pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE step = 2"); $pdo->exec("UPDATE wa_line_plan SET sent_at = '2000-01-01 00:00:00' WHERE status = 'sent'");   // 24 h passed
$pdo->prepare("UPDATE wa_contacts SET last_in_at = ? WHERE phone = '9810000003'")->execute([$f($now + 20)]);
$pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone NOT IN ('9810000003')");
$SENT = []; $r = wl_tick($pdo, ['now' => 1, 'cap' => 60]);
ok(!in_array('9810000002', array_column($SENT, 0), true), 'the customer who ordered gets nothing'); ok(!in_array('9810000003', array_column($SENT, 0), true), 'the customer who replied gets nothing');
ok((string)$pdo->query("SELECT left_why FROM wa_line_cust WHERE phone = '9810000002'")->fetchColumn() === 'ordered', 'left because ordered');
ok((string)$pdo->query("SELECT status FROM wa_line_cust WHERE phone = '9810000003'")->fetchColumn() === 'replied', 'replied -> waits 24 quiet hours');
ok(count($SENT) === 4, 'the other four got step 2, got ' . count($SENT));
// report, status, overview, heal, snapshot
$pdo->prepare("UPDATE wa_line_plan SET sent_at = ? WHERE phone = '9810000002' AND step = 1")->execute([$f($now - 3600)]);
$rep = wl_report($pdo, 30, 1); $tr = $rep['keys']['ln_trust_2']['sent'] ?? 0; ok($tr === 2, 'report counts the trust step 2 sends (two trust customers), got ' . $tr); ok($rep['topics']['price']['buyers'] === 1, 'the order is credited to the price topic');
$st = wl_status($pdo); ok($st['messages']['total'] === 33 && $st['messages']['approved'] === 33 && $st['customers']['identified'] === 6 && $st['sending']['sent'] >= 10, 'status ' . json_encode($st['customers']) . json_encode($st['sending']));
$ov = wl_overview($pdo); ok(isset($ov['board']) && isset($ov['tomorrow']) && isset($ov['status']), 'overview');
$h = wl_heal($pdo); ok(is_array($h['issues']), 'self-check runs'); ok(wl_snap($pdo, true), 'snapshot written'); ok(count(wl_snaps($pdo)) === 1, 'snapshot read back');
$c = wl_customer($pdo, '9810000001'); ok($c['in'] && count($c['plan']) === 7, 'customer timeline');
// replied customer is read again after 24 quiet hours: a new ladder from the unsent messages
$pdo->exec("UPDATE wa_line_cust SET recheck_at = '2000-01-01 00:00:00' WHERE phone = '9810000003'"); $pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone = '9810000003'");
$pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9810000003', 'in', 'kitna rate hai', '" . $f($now + 30) . "')");
ok(wl_recheck($pdo, $now + 100000) === 1, 'recheck re-plans'); ok((string)$pdo->query("SELECT topic FROM wa_line_cust WHERE phone = '9810000003'")->fetchColumn() === 'price', 'new topic price');
ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE phone = '9810000003' AND status = 'planned'")->fetchColumn() === 7, 'new ladder of seven (step 1 of the old topic was sent, a different message)');
$sm = wl_summary($pdo, 0); ok($sm['sent'] >= 10 && $sm['utility'] === $sm['sent'] && $sm['plain'] === 0, 'summary: every message went as utility ' . json_encode([$sm['sent'], $sm['utility'], $sm['plain']]));
ok(abs($sm['cost'] - round($sm['sent'] * $sm['rate_u'], 2)) < 0.001 && $sm['orders'] === 1 && $sm['buyers'] === 1, 'summary: cost = utility messages x rate; the order is credited');
$pdo->exec("UPDATE wa_templates SET category = 'MARKETING' WHERE id = " . $ids['ln_trust_2']); $sm2 = wl_summary($pdo, 0); ok($sm2['plain'] >= 1 && $sm2['cost'] > $sm['cost'], 'a message Meta made marketing is counted and costs more');
echo "lineup db ok\n";
