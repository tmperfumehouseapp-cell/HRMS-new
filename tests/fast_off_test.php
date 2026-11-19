<?php
// Send fast is OFF by default: old campaigns on the fast list pushed 2,300 messages per 5 minutes, outside the marketing limits.
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php'); $a = strpos($src, 'function wa_camp_fast_all'); $b = strpos($src, 'function wa_camp_fast_put'); eval(substr($src, $a, $b - $a));
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$pdo = new PDO("sqlite::memory:");
$S['wa_cfast_ids'] = '[543,533]'; $S['wa_cfast_543'] = json_encode(['until' => time() + 3600, 'burst_left' => 5000]); $S['wa_cfast_533'] = json_encode(['until' => time() + 3600, 'burst_left' => 5000]);
ok(wa_camp_fast_all($pdo) === [], 'fast list is ignored by default');
$S['wa_fast_ok'] = '1'; ok(count(wa_camp_fast_all($pdo)) === 2, 'switched on by wa_fast_ok = 1');
echo "fast off ok\n";
