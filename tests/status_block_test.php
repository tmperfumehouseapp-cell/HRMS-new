<?php
// Delivery receipts applied as ONE block (wa_status_apply) must leave the database exactly as the same receipts applied one by one (wa_webhook_status): message status, billing columns,
// campaign person state, and the set of customers marked for a recount. Random receipts: out of order, repeated, with and without billing info, for messages we know, do not know, and campaign-only.
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa.php');
$a = strpos($src, 'function wa_status_rank'); $b = strpos($src, 'function wa_sig_ok');
if ($a === false || $b === false) { echo "FAIL: functions not found\n"; exit(1); }
function wa_key($x) { return substr(preg_replace('/\D+/', '', (string)$x), -10); }
function wa_fail_raw(...$a) { } function wa_unreach_note(...$a) { } function wa_log(...$a) { } function wa_more_tables($p) { return true; }
$GLOBALS['dirty_old'] = []; $GLOBALS['dirty_new'] = []; $GLOBALS['mode'] = 'old';
function cfun_dirty($pdo, $phone) { $GLOBALS['dirty_old'][$phone] = 1; }
function wa_stq_mark_dirty($pdo, array $phones) { foreach ($phones as $p) $GLOBALS['dirty_new'][$p] = 1; }
function setting_get($p, $k, $d = '') { return $d; } function schema_ok($p, $n) { return true; }
eval(substr($src, $a, $b - $a));
function mk() { $d = new PDO('sqlite::memory:'); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $d->exec("create table wa_messages(wamid text primary key, status text, bill_cat text default '', billable int, err text, err_code int)");
  $d->exec("create table wa_camp_items(id integer primary key autoincrement, wamid text, last_status text, err text, err_code int)"); return $d; }
mt_srand(11); $st0 = ['', 'queued', 'sent', 'delivered', 'read', 'failed'];
$ids = []; for ($i = 0; $i < 400; $i++) $ids[] = 'wamid.' . $i;
$A = mk(); $B = mk();
foreach ($ids as $i => $id) {
  $kind = mt_rand(1, 10);   // 1-6 message + campaign person, 7-8 message only, 9 campaign person only, 10 neither
  if ($kind <= 8) { $s = $st0[mt_rand(0, 5)]; foreach ([$A, $B] as $d) $d->prepare("insert into wa_messages(wamid,status,bill_cat,billable) values(?,?,?,?)")->execute([$id, $s, '', null]); }
  if ($kind <= 6 || $kind == 9) { $s = $st0[mt_rand(0, 5)]; foreach ([$A, $B] as $d) $d->prepare("insert into wa_camp_items(wamid,last_status) values(?,?)")->execute([$id, $s]); }
}
$recv = []; $cats = ['marketing', 'utility', 'service', 'authentication', '']; $types = ['regular', 'free_customer_service', 'free_entry_point', '', 'Free_tier'];
for ($n = 0; $n < 3000; $n++) {
  $k = mt_rand(0, 399); $r = ['id' => $ids[$k], 'status' => ['sent', 'delivered', 'read'][mt_rand(0, 2)], 'recipient_id' => '91' . (9000000000 + $k % 151)];   // a message always has the same customer
  $p = mt_rand(1, 3); if ($p == 1) { $pr = ['category' => $cats[mt_rand(0, 4)], 'type' => $types[mt_rand(0, 4)]]; if (mt_rand(0, 1)) $pr['billable'] = (bool)mt_rand(0, 1); $r['pricing'] = $pr; }
  if (mt_rand(1, 60) == 1) $r['status'] = 'bogus';     // not a receipt we know: ignored by both
  $recv[] = $r;
}
// old: one by one
foreach ($recv as $r) wa_webhook_status($A, $r);
// new: blocks of random size (a quiet moment = a block of 1-3, a busy one = hundreds), in arrival order
$i = 0; $blocks = 0; while ($i < count($recv)) { $sz = mt_rand(0, 3) ? mt_rand(1, 40) : mt_rand(100, 500); $chunk = array_slice($recv, $i, $sz); $i += $sz; $rows = [];
  foreach ($chunk as $r) { $x = wa_stq_row($r); if ($x !== null) $rows[] = ['wamid' => $x[0], 'st' => $x[1], 'phone' => $x[2], 'pc' => $x[3], 'pb' => $x[4]]; }
  if ($rows) { wa_status_apply($B, $rows); $blocks++; } }
$dump = function ($d) { return [$d->query("select wamid,status,bill_cat,billable from wa_messages order by wamid")->fetchAll(PDO::FETCH_NUM), $d->query("select wamid,last_status from wa_camp_items order by id")->fetchAll(PDO::FETCH_NUM)]; };
[$ma, $ca] = $dump($A); [$mb, $cb] = $dump($B); $bad = [];
if ($ma !== $mb) { $n = 0; foreach ($ma as $k => $row) if ($row !== $mb[$k]) { if ($n++ < 3) $bad[] = 'message ' . json_encode($row) . ' vs ' . json_encode($mb[$k]); } $bad[] = "$n messages differ"; }
if ($ca !== $cb) { $n = 0; foreach ($ca as $k => $row) if ($row !== $cb[$k]) { if ($n++ < 3) $bad[] = 'campaign person ' . json_encode($row) . ' vs ' . json_encode($cb[$k]); } $bad[] = "$n campaign people differ"; }
$da = array_keys($GLOBALS['dirty_old']); $db = array_keys($GLOBALS['dirty_new']); sort($da); sort($db);
if ($da !== $db) $bad[] = 'recount marks differ: old ' . count($da) . ' new ' . count($db) . ' · only old: ' . json_encode(array_slice(array_diff($da, $db), 0, 3)) . ' only new: ' . json_encode(array_slice(array_diff($db, $da), 0, 3));
if ($bad) { echo "FAIL: " . implode(' | ', $bad) . "\n"; exit(1); }
echo "status block ok (" . count($recv) . " receipts, $blocks blocks, " . count($ma) . " messages, " . count($ca) . " campaign people, " . count($da) . " recount marks)\n";
