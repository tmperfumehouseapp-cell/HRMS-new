<?php
// Marketing auto hold (131048 surge -> 3 hours) and the delivery-following speed (5,000 start, +1,000 while good up to 10,000, bad -> 1 hour hold and back to 5,000).
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; } function wa_log() { global $LOG; $LOG[] = func_get_args(); }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_dq.php'); $a = strpos($src, 'function wa_dq_hold_active'); $b = strpos($src, 'function wa_dq_cfg'); eval(substr($src, $a, $b - $a));
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_camp_items (err_code INT, sent_at TEXT)"); $pdo->exec("CREATE TABLE wa_messages (dir TEXT, who TEXT, mtype TEXT, status TEXT, ts TEXT)");
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
function items($pdo, $n, $ageMin) { for ($i = 0; $i < $n; $i++) $pdo->prepare("INSERT INTO wa_camp_items VALUES (131048, ?)")->execute([date('Y-m-d H:i:s', time() - $ageMin * 60)]); }
function msgs($pdo, $n, $deliv, $failed, $ageMin) { for ($i = 0; $i < $n; $i++) { $st = $i < $deliv ? 'delivered' : ($i < $deliv + $failed ? 'failed' : 'sent'); $pdo->prepare("INSERT INTO wa_messages VALUES ('out', 'campaign', 'template', ?, ?)")->execute([$st, date('Y-m-d H:i:s', time() - $ageMin * 60)]); } }
// 1) 99 flagged in the last hour -> nothing; 100 -> 3 hours hold
items($pdo, 99, 20); wa_dq_auto_hold($pdo); ok(!wa_dq_hold_active($pdo), '99 flags: no hold');
items($pdo, 1, 20); $S['wa_dq_auto_hold_at'] = '0'; wa_dq_auto_hold($pdo); ok(wa_dq_hold_active($pdo) && (int)$S['wa_dq_hold_until'] > time() + 3 * 3600 - 60 && (int)$S['wa_dq_hold_until'] < time() + 3 * 3600 + 60, '100 flags: 3 hours hold');
// 2) flags before the end of the last hold do not count again
$S['wa_dq_hold_until'] = '0'; $S['wa_dq_hold_end'] = (string)(time() - 60); $S['wa_dq_auto_hold_at'] = '0'; wa_dq_auto_hold($pdo); ok(!wa_dq_hold_active($pdo), 'old flags (before the hold ended) do not hold again');
// 3) speed: good delivery -> +1,000
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '5000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 160, 40, 30);
wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '6000', 'good: 5,000 -> 6,000 (got ' . $S['wa_dq_hourly'] . ')');
// 4) no ceiling of its own: 10,000 -> 11,000, only the technical 60,000 stops it
$S['wa_dq_hourly'] = '10000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '11000', 'no ceiling at 10,000 (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_hourly'] = '60000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '60000', 'technical limit 60,000');
// 5) in between: nothing changes
$S['wa_dq_hourly'] = '6000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 100, 60, 30); wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '6000' && !wa_dq_hold_active($pdo), '25% delivered: nothing changes');
// 6) bad delivery -> back to 5,000 and it keeps sending (no hold)
$S['wa_dq_hourly'] = '8000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 40, 200, 30); wa_dq_auto_step($pdo); ok(!wa_dq_hold_active($pdo) && $S['wa_dq_hourly'] === '5000', 'bad: back to 5,000, no hold (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '5000', 'already at 5,000: stays');
// 7) too few messages -> nothing
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '5000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 50, 10, 30, 30); wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '5000' && !wa_dq_hold_active($pdo), 'under 200 messages: nothing changes');
echo "dq ok\n";
