<?php
// Marketing auto hold (131048 surge -> 1 hour) and the delivery-following speed (5,000 start, +1,000 while good up to 10,000, bad -> 1 hour hold and back to 5,000).
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; } function wa_log() { global $LOG; $LOG[] = func_get_args(); }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_dq.php'); $a = strpos($src, 'function wa_dq_hold_active'); $b = strpos($src, 'function wa_dq_cfg'); eval(substr($src, $a, $b - $a));
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_camp_items (err_code INT, sent_at TEXT)"); $pdo->exec("CREATE TABLE wa_messages (dir TEXT, who TEXT, mtype TEXT, status TEXT, ts TEXT)");
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
function items($pdo, $n, $ageMin) { for ($i = 0; $i < $n; $i++) $pdo->prepare("INSERT INTO wa_camp_items VALUES (131048, ?)")->execute([date('Y-m-d H:i:s', time() - $ageMin * 60)]); }
function msgs($pdo, $n, $deliv, $failed, $ageMin) { for ($i = 0; $i < $n; $i++) { $st = $i < $deliv ? 'delivered' : ($i < $deliv + $failed ? 'failed' : 'sent'); $pdo->prepare("INSERT INTO wa_messages VALUES ('out', 'campaign', 'template', ?, ?)")->execute([$st, date('Y-m-d H:i:s', time() - $ageMin * 60)]); } }
// 1) 99 flagged in the last hour -> nothing; 100 -> 6 hours hold
items($pdo, 99, 20); wa_dq_auto_hold($pdo); ok(!wa_dq_hold_active($pdo), '99 flags: no hold');
items($pdo, 1, 20); $S['wa_dq_auto_hold_at'] = '0'; wa_dq_auto_hold($pdo); ok(wa_dq_hold_active($pdo) && (int)$S['wa_dq_hold_until'] > time() + 6 * 3600 - 60 && (int)$S['wa_dq_hold_until'] < time() + 6 * 3600 + 60, '100 flags: 6 hour hold');
// 2) flags before the end of the last hold do not count again
$S['wa_dq_hold_until'] = '0'; $S['wa_dq_hold_end'] = (string)(time() - 60); $S['wa_dq_auto_hold_at'] = '0'; wa_dq_auto_hold($pdo); ok(!wa_dq_hold_active($pdo), 'old flags (before the hold ended) do not hold again');
// 3) speed ladder 7,000 / 8,000 / 9,000: good delivery -> +1,000, stays at 9,000
function stp() { global $S; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($GLOBALS['pdo']); }
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '7000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 220, 20, 30);
stp(); ok($S['wa_dq_hourly'] === '8000', 'good: 7,000 -> 8,000 (got ' . $S['wa_dq_hourly'] . ')');
stp(); ok($S['wa_dq_hourly'] === '9000', 'good: 8,000 -> 9,000 (got ' . $S['wa_dq_hourly'] . ')');
for ($i = 1; $i <= 3; $i++) { stp(); ok($S['wa_dq_hourly'] === '9000', 'keeps going well: stays 9,000 (got ' . $S['wa_dq_hourly'] . ')'); }
$pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 200, 100, 30); stp(); ok($S['wa_dq_hourly'] === '7000', 'stalls (25% failed): back to the 7,000 floor');
$pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 220, 20, 30); $S['wa_dq_hourly'] = '12000'; stp(); ok($S['wa_dq_hourly'] === '7000', 'anything above 9,000 comes back to 7,000');
$S['wa_dq_hourly'] = '5000'; stp(); ok($S['wa_dq_hourly'] === '7000', 'below the floor: 7,000');
// 4) in between: nothing changes
$S['wa_dq_hourly'] = '8000'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 100, 60, 30); stp(); ok($S['wa_dq_hourly'] === '8000' && !wa_dq_hold_active($pdo), '25% delivered: nothing changes');
// 5) failed rising (15% to 25%): stays; a second check in a row = back to 7,000; failed 25%+: back at once
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '9000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 220, 76, 30);   /* 55% delivered, 19% failed */
stp(); ok($S['wa_dq_hourly'] === '9000' && $S['wa_dq_freeze_n'] === '1', 'failed 19%: stays at 9,000 (got ' . $S['wa_dq_hourly'] . ')');
stp(); ok($S['wa_dq_hourly'] === '7000' && !wa_dq_hold_active($pdo), 'second check still failing: back to 7,000, no hold (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_hourly'] = '9000'; $S['wa_dq_freeze_n'] = '0'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 200, 100, 30);   /* 25% failed */
stp(); ok($S['wa_dq_hourly'] === '7000', 'failed 25%: back to 7,000 at once');
$S['wa_dq_hourly'] = '8000'; $S['wa_dq_freeze_n'] = '1'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 200, 20, 30);   /* good again */
stp(); ok($S['wa_dq_hourly'] === '9000' && $S['wa_dq_freeze_n'] === '0', 'good after a freeze: +1,000 and the freeze counter resets');
// 6) bad delivery -> back to 7,000 and it keeps sending (no hold)
$S['wa_dq_hourly'] = '8000'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 40, 200, 30); stp(); ok(!wa_dq_hold_active($pdo) && $S['wa_dq_hourly'] === '7000', 'bad: back to 7,000, no hold (got ' . $S['wa_dq_hourly'] . ')');
stp(); ok($S['wa_dq_hourly'] === '7000', 'already at 7,000: stays');
// 7) too few messages -> nothing
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '8000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 50, 10, 30, 30); wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '8000' && !wa_dq_hold_active($pdo), 'under 200 messages: nothing changes');
echo "dq ok\n";
