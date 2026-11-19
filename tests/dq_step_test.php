<?php
// Marketing auto hold (131048 surge -> 1 hour) and the delivery-following speed (5,000 start, +1,000 while good up to 10,000, bad -> 1 hour hold and back to 5,000).
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; } function wa_log() { global $LOG; $LOG[] = func_get_args(); }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_dq.php'); $a = strpos($src, 'function wa_dq_hold_active'); $b = strpos($src, 'function wa_dq_cfg'); eval(substr($src, $a, $b - $a));
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_camp_items (err_code INT, sent_at TEXT)"); $pdo->exec("CREATE TABLE wa_messages (dir TEXT, who TEXT, mtype TEXT, status TEXT, ts TEXT)");
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
function items($pdo, $n, $ageMin) { for ($i = 0; $i < $n; $i++) $pdo->prepare("INSERT INTO wa_camp_items VALUES (131048, ?)")->execute([date('Y-m-d H:i:s', time() - $ageMin * 60)]); }
function msgs($pdo, $n, $deliv, $failed, $ageMin) { for ($i = 0; $i < $n; $i++) { $st = $i < $deliv ? 'delivered' : ($i < $deliv + $failed ? 'failed' : 'sent'); $pdo->prepare("INSERT INTO wa_messages VALUES ('out', 'campaign', 'template', ?, ?)")->execute([$st, date('Y-m-d H:i:s', time() - $ageMin * 60)]); } }
// 1) 99 flagged in the last hour -> nothing; 100 -> 3 hours hold
items($pdo, 99, 20); wa_dq_auto_hold($pdo); ok(!wa_dq_hold_active($pdo), '99 flags: no hold');
items($pdo, 1, 20); $S['wa_dq_auto_hold_at'] = '0'; wa_dq_auto_hold($pdo); ok(wa_dq_hold_active($pdo) && (int)$S['wa_dq_hold_until'] > time() + 3600 - 60 && (int)$S['wa_dq_hold_until'] < time() + 3600 + 60, '100 flags: 1 hour hold');
// 2) flags before the end of the last hold do not count again
$S['wa_dq_hold_until'] = '0'; $S['wa_dq_hold_end'] = (string)(time() - 60); $S['wa_dq_auto_hold_at'] = '0'; wa_dq_auto_hold($pdo); ok(!wa_dq_hold_active($pdo), 'old flags (before the hold ended) do not hold again');
// 3) speed: good delivery -> +1,000
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '5000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 160, 40, 30);
wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '6000', 'good: 5,000 -> 6,000 (got ' . $S['wa_dq_hourly'] . ')');
// 4) the usual top is 6,000: good checks at 6,000 stay there; the 4th good check in a row (2 hours) tries 7,000 for one hour; never above 7,000
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '6000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 220, 20, 30);
for ($i = 1; $i <= 3; $i++) { $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '6000', 'good check ' . $i . ' at 6,000: stays 6,000 (got ' . $S['wa_dq_hourly'] . ')'); }
$S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '7000' && (int)$S['wa_dq_trial_until'] > time() + 3500, '4th good check: 7,000 for one hour (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '7000', 'inside the trial hour: stays 7,000, never 8,000 (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_trial_until'] = (string)(time() - 5); $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '6000', 'trial hour over: back to 6,000 (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_hourly'] = '9000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '6000', 'anything above 7,000 comes back to 6,000');
// 5) in between: nothing changes
$S['wa_dq_hourly'] = '6000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 100, 60, 30); wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '6000' && !wa_dq_hold_active($pdo), '25% delivered: nothing changes');
// 5b) failed rising (15% to 25%): the last step stays; the next check still the same: back to 5,000; failed 25%+: back at once
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '7000', 'wa_dq_trial_until' => (string)(time() + 1800)]; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 220, 76, 30);   /* 55% delivered, 19% failed */
wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '7000' && $S['wa_dq_freeze_n'] === '1', 'failed 19%: stays at 7,000 (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '5000' && !wa_dq_hold_active($pdo), 'second check still failing: back to 5,000, no hold (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_hourly'] = '7000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; $S['wa_dq_freeze_n'] = '0'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 200, 100, 30);   /* 25% failed */
wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '5000', 'failed 25%: back to 5,000 at once');
$S['wa_dq_hourly'] = '7000'; $S['wa_dq_trial_until'] = (string)(time() + 1800); $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; $S['wa_dq_freeze_n'] = '1'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 200, 20, 30);   /* good again */
wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '7000' && $S['wa_dq_freeze_n'] === '0', 'good after a freeze: the freeze counter resets');
// 6) bad delivery -> back to 5,000 and it keeps sending (no hold)
$S['wa_dq_hourly'] = '8000'; $S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 400, 40, 200, 30); wa_dq_auto_step($pdo); ok(!wa_dq_hold_active($pdo) && $S['wa_dq_hourly'] === '5000', 'bad: back to 5,000, no hold (got ' . $S['wa_dq_hourly'] . ')');
$S['wa_dq_step_at'] = '0'; $S['wa_dq_step_last'] = '0'; wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '5000', 'already at 5,000: stays');
// 7) too few messages -> nothing
$S = ['wa_dq_hold_end' => (string)(time() - 7200), 'wa_dq_hourly' => '5000']; $pdo->exec("DELETE FROM wa_messages"); msgs($pdo, 50, 10, 30, 30); wa_dq_auto_step($pdo); ok($S['wa_dq_hourly'] === '5000' && !wa_dq_hold_active($pdo), 'under 200 messages: nothing changes');
echo "dq ok\n";
