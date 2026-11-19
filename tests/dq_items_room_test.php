<?php
// The push guard counts campaign items taken in the last 10 minutes, so several campaigns working at once cannot each take the full slice.
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_dq.php'); $a = strpos($src, 'function wa_dq_items_room'); $b = strpos($src, 'function wa_dq_left'); eval(substr($src, $a, $b - $a));
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_camp_items (sent_at TEXT, variant TEXT)");
$S['wa_dq_hourly'] = '5000'; ok(wa_dq_items_room($pdo) === 1250, 'empty: 1,250 per 10 minutes at 5,000 an hour (got ' . wa_dq_items_room($pdo) . ')');
$ins = $pdo->prepare("INSERT INTO wa_camp_items VALUES (?, ?)");
for ($i = 0; $i < 1000; $i++) $ins->execute([date('Y-m-d H:i:s', time() - 30), 'F']);
ok(wa_dq_items_room($pdo) === 250, '1,000 taken by one campaign: 250 left for the others (got ' . wa_dq_items_room($pdo) . ')');
for ($i = 0; $i < 400; $i++) $ins->execute([date('Y-m-d H:i:s', time() - 30), 'F']);
ok(wa_dq_items_room($pdo) === 0, 'over the slice: nothing left');
for ($i = 0; $i < 500; $i++) $ins->execute([date('Y-m-d H:i:s', time() - 30), 'U']);
ok(wa_dq_items_room($pdo) === 0, 'utility-variant people are not counted (still 0, not negative)');
$pdo->exec("DELETE FROM wa_camp_items"); for ($i = 0; $i < 900; $i++) $ins->execute([date('Y-m-d H:i:s', time() - 700), 'F']);
ok(wa_dq_items_room($pdo) === 1250, 'older than 10 minutes: free again');
$S['wa_dq_hourly'] = '6000'; ok(wa_dq_items_room($pdo) === 1500, '6,000 an hour: 1,500 per 10 minutes');
echo "items room ok\n";
