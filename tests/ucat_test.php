<?php
// Campaigns on a UTILITY template: at most 700 in any rolling hour, spread evenly (130% of a sixth in any 10 minutes).
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php'); $a = strpos($src, 'function wa_cat_util_room'); $b = strpos($src, '/** 🔀 Campaigns that run on a plain pool message'); eval(substr($src, $a, $b - $a));
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$pdo = new PDO("sqlite::memory:"); $now = 1000000;
ok(wa_cat_util_room($pdo, $now) === 152, 'empty: the 10-minute slice (152) is the limit (got ' . wa_cat_util_room($pdo, $now) . ')');
wa_cat_util_note($pdo, $now, 100); ok(wa_cat_util_room($pdo, $now + 5) === 52, '100 sent: 52 left in this 10 minutes');
ok(wa_cat_util_room($pdo, $now + 700) === 152, 'ten minutes later the slice is free again');
for ($i = 1; $i <= 6; $i++) wa_cat_util_note($pdo, $now + $i * 650, 110);   // 6 x 110 = 660 in the hour
ok(wa_cat_util_room($pdo, $now + 7 * 650) <= 40, 'about 700 in the hour: little room left (got ' . wa_cat_util_room($pdo, $now + 7 * 650) . ')');
ok(wa_cat_util_room($pdo, $now + 5000) === 152, 'an hour later everything is free');
echo "ucat ok\n";
