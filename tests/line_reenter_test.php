<?php
// Lineup re-entry (owner, 23 Nov): a customer who left the Lineup because he ORDERED comes back as a new Day 0 when he writes again at least 10 days after his last order; earlier messages, active / complaint customers do not. In-memory database.
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_line.php');
preg_match('/\nfunction wl_ordered_after\([^\n]*\n/', $src, $mo); eval($mo[0]);
foreach (['wl_orders_since', 'wl_enroll'] as $f) { if (!preg_match('/\nfunction ' . $f . '\(.*?\n\}\n/s', $src, $m)) { echo "FAIL cannot find $f\n"; exit(1); } eval($m[0]); }
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; }
function wl_classify($t, $b) { return 'general'; } $PLAN = []; function wln_plan($pdo, $phone, $topic, $anchor) { global $PLAN; $PLAN[$phone] = $anchor; }
$pdo = new PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE wa_line_cust (phone TEXT PRIMARY KEY, topic TEXT, anchor_at TEXT, status TEXT, left_why TEXT DEFAULT '', created_at TEXT)");
$pdo->exec("CREATE TABLE wa_contacts (phone TEXT, last_in_at TEXT, optout INT DEFAULT 0, camp_optout INT DEFAULT 0)");
$pdo->exec("CREATE TABLE wa_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, dir TEXT, ts TEXT, mtype TEXT, body TEXT)");
$pdo->exec("CREATE TABLE wa_cust (phone TEXT, n INT)"); $pdo->exec("CREATE TABLE shop_orders (phone TEXT, created_at TEXT, tags TEXT)");
$d = function ($days) { return date('Y-m-d H:i:s', time() - (int)round($days * 86400)); };
$cust = function ($ph, $status, $why, $created) use ($pdo, $d) { $pdo->prepare("INSERT INTO wa_line_cust (phone, topic, anchor_at, status, left_why, created_at) VALUES (?, 'general', ?, ?, ?, ?)")->execute([$ph, $d($created + 1), $status, $why, $d($created)]); };
$msg = function ($ph, $days) use ($pdo, $d) { $pdo->prepare("INSERT INTO wa_messages (phone, dir, ts, mtype, body) VALUES (?, 'in', ?, 'text', 'price kya hai')")->execute([$ph, $d($days)]); $pdo->prepare("DELETE FROM wa_contacts WHERE phone = ?")->execute([$ph]); $pdo->prepare("INSERT INTO wa_contacts (phone, last_in_at) VALUES (?, ?)")->execute([$ph, $d($days)]); };
$ord = function ($ph, $days) use ($pdo, $d) { $pdo->prepare("INSERT INTO shop_orders (phone, created_at, tags) VALUES (?, ?, '')")->execute([$ph, $d($days)]); };
// A: ordered 15 days ago, Lineup record 16 days old (done / ordered), writes again 1 day ago -> comes back
$cust('9000000001', 'done', 'ordered', 16); $ord('9000000001', 15); $msg('9000000001', 20); $msg('9000000001', 1);
// B: ordered 4 days ago, writes 2 days ago (tracking talk) -> not yet
$cust('9000000002', 'done', 'ordered', 5); $ord('9000000002', 4); $msg('9000000002', 2);
// C: still active in the Lineup -> blocked
$cust('9000000003', 'active', '', 3); $msg('9000000003', 1);
// D: left because of a complaint -> blocked
$cust('9000000004', 'done', 'complaint', 3); $msg('9000000004', 1);
// E: ordered 15 days ago, last message 14 days ago (only 1 day after the order) -> not
$cust('9000000005', 'done', 'ordered', 16); $ord('9000000005', 15); $msg('9000000005', 14);
$n = wl_enroll($pdo, time() - 40 * 86400);
$fail = 0; $chk = function ($name, $c) use (&$fail) { if (!$c) { echo "FAIL $name\n"; $fail++; } };
$chk('A comes back', isset($PLAN['9000000001']) && abs($PLAN['9000000001'] - (time() - 86400)) < 120);
$chk('A has one active row now', $pdo->query("SELECT status FROM wa_line_cust WHERE phone = '9000000001'")->fetchColumn() === 'active');
$chk('B stays out', !isset($PLAN['9000000002'])); $chk('C stays out', !isset($PLAN['9000000003'])); $chk('D stays out', !isset($PLAN['9000000004'])); $chk('E stays out', !isset($PLAN['9000000005']));
$n2 = wl_enroll($pdo, time() - 40 * 86400); $chk('second round enrols nobody again', $n2 === 0);
echo $fail ? "line re-enter FAILED ($fail)\n" : "line re-enter ok\n"; exit($fail ? 1 : 0);
