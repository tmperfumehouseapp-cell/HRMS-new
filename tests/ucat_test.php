<?php
// Campaigns on a UTILITY template: at most 700 in any rolling hour, spread evenly (130% of a sixth in any 10 minutes).
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php'); $a = strpos($src, 'function wa_cat_util_room'); $b = strpos($src, '/** 🔀 Campaigns that run on a plain pool message'); eval(substr($src, $a, $b - $a));
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$pdo = new PDO("sqlite::memory:"); $now = 1000000; $S['wa_ucat_hour'] = '700';   /* the mechanism is tested with 700; the default is now 900 (owner 20 Nov 2026) */
ok(wa_cat_util_room($pdo, $now) === 152, 'empty: the 10-minute slice (152) is the limit (got ' . wa_cat_util_room($pdo, $now) . ')');
wa_cat_util_note($pdo, $now, 100); ok(wa_cat_util_room($pdo, $now + 5) === 52, '100 sent: 52 left in this 10 minutes');
ok(wa_cat_util_room($pdo, $now + 700) === 152, 'ten minutes later the slice is free again');
$S = ['wa_ucat_hour' => '700']; for ($i = 1; $i <= 6; $i++) wa_cat_util_note($pdo, $now + $i * 500, 110);   // 6 x 110 = 660 in the hour
ok(wa_cat_util_room($pdo, $now + 3100) <= 40, 'about 700 in the hour: little room left (got ' . wa_cat_util_room($pdo, $now + 3100) . ')');
ok(wa_cat_util_room($pdo, $now + 3500 + 3600) === 152, 'after the hour: free');
ok(wa_cat_util_room($pdo, $now + 5000) === 152, 'an hour later everything is free');
$S = ['wa_ucat_hour' => '700']; $S['wa_dq_hold_until'] = (string)($now + 1800); ok(wa_cat_util_room($pdo, $now + 5) === 0, 'marketing hold running: utility campaigns stop too'); ok(wa_cat_util_room($pdo, $now + 1900) === 152, 'hold over: utility room is back');
$S = []; $L7 = wa_cat_util_room($pdo, $now + 90000); ok($L7 === 152, 'default limit is 700 an hour (the ladder start): slice 152 (got ' . $L7 . ')');
$S = ['wa_ucat_hour' => '900']; $L900 = wa_cat_util_room($pdo, $now + 90000); ok($L900 === 195, 'limit 900 an hour: the 10-minute slice is 195 (got ' . $L900 . ')');
$S = ['wa_ucat_pause_until' => (string)($now + 90000 + 100)]; ok(wa_cat_util_room($pdo, $now + 90000) === 0, 'utility pause (3 hours) running: no room'); ok(wa_cat_util_room($pdo, $now + 90000 + 200) === 152, 'utility pause over: room is back');
/* hourly ladder 700 -> 800 -> 900, bad hour -> 700, bad at 700 -> 3 hour rest */
$db = new PDO('sqlite::memory:'); $db->exec("CREATE TABLE wa_camp_items (id INTEGER PRIMARY KEY, tpl_used INT, sent_at TEXT, wamid TEXT, status TEXT)"); $db->exec("CREATE TABLE wa_templates (id INTEGER PRIMARY KEY, category TEXT)"); $db->exec("CREATE TABLE wa_messages (wamid TEXT, status TEXT)"); $db->exec("INSERT INTO wa_templates VALUES (1, 'UTILITY')");
$T = time();
function lad($db, $n, $f, $T) { $db->exec("DELETE FROM wa_camp_items"); for ($i = 0; $i < $n; $i++) $db->exec("INSERT INTO wa_camp_items (tpl_used, sent_at, wamid, status) VALUES (1, '" . date('Y-m-d H:i:s', $T - 2400) . "', '', '" . ($i < $f ? 'failed' : 'sent') . "')"); }
$S = ['wa_ucat_hour' => '700', 'wa_ucat_step_at' => (string)($T - 3700)]; lad($db, 300, 3, $T); wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '800', 'good hour: 700 -> 800 (got ' . $S['wa_ucat_hour'] . ')');
$S['wa_ucat_step_at'] = (string)($T - 3700); $S['wa_ucat_chk'] = '0'; wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '900', 'good hour: 800 -> 900');
$S['wa_ucat_step_at'] = (string)($T - 3700); $S['wa_ucat_chk'] = '0'; wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '1000', 'good hour: 900 -> 1,000 (owner 21 Nov: ladder up to 1,200)');
for ($z = 1100; $z <= 1200; $z += 100) { $S['wa_ucat_step_at'] = (string)($T - 3700); $S['wa_ucat_chk'] = '0'; wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === (string)$z, 'good hour: -> ' . $z); }
for ($z = 1300; $z <= 1500; $z += 100) { $S['wa_ucat_step_at'] = (string)($T - 1900); $S['wa_ucat_chk'] = '0'; wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === (string)$z, 'good half hour: -> ' . $z); }
$S['wa_ucat_step_at'] = (string)($T - 1900); $S['wa_ucat_chk'] = '0'; wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '1500', 'good half hour: 1,500 is the roof and stays');
$S['wa_ucat_hour'] = '900';
$S['wa_ucat_step_at'] = (string)($T - 1000); $S['wa_ucat_chk'] = '0'; lad($db, 300, 60, $T); wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '900', 'no look before the half hour is over');
$S['wa_ucat_step_at'] = (string)($T - 3700); $S['wa_ucat_chk'] = '0'; wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '700', 'bad hour at 900+ -> back to 700 (got ' . $S['wa_ucat_hour'] . ')');
$S['wa_ucat_step_at'] = (string)($T - 3700); $S['wa_ucat_chk'] = '0'; wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '700' && (int)$S['wa_ucat_pause_until'] > $T + 3 * 3600 - 60 && (int)$S['wa_ucat_pause_until'] <= $T + 3 * 3600 + 5, 'bad hour at 700 -> 3 hour rest');
$S = ['wa_ucat_hour' => '700', 'wa_ucat_step_at' => (string)($T - 3700)]; lad($db, 50, 0, $T); wa_ucat_auto_step($db, $T); ok($S['wa_ucat_hour'] === '700', 'fewer than 100 messages: no decision');
echo "ucat ok\n";
