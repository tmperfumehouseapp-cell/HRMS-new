<?php
// The hourly speed limit with a ladder (owner, 23 Nov night): Cold 1,500 / 2,000 / 2,500 / 3,000 (start 2,500), rescue 1,000 ... 4,000 (start 1,000); step down at a high failure share, step up when it runs well and the limit is really used.
require __DIR__ . '/../empmanagment/wa_rate.php';
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = $v; }
$pdo = new PDO('sqlite::memory:'); $fail = 0; $chk = function ($n, $c) use (&$fail) { if (!$c) { echo "FAIL $n\n"; $fail++; } };
$cold = [1500, 2000, 2500, 3000]; $resc = [1000, 1500, 2000, 2500, 3000, 3500, 4000];
$chk('cold starts at 2,500', wrt_limit($pdo, 'cold', $cold, 2) === 2500); $chk('rescue starts at 1,000', wrt_limit($pdo, 'resc', $resc, 0) === 1000);
$chk('nothing used yet', wrt_used($pdo, 'cold') === 0); wrt_add($pdo, 'cold', 30); wrt_add($pdo, 'cold', 12); $chk('the meter adds up (atomic update + first insert)', wrt_used($pdo, 'cold') === 42);
$chk('room is an even share per minute, not the whole hour', wrt_room($pdo, 'cold', $cold, 2, 2) <= 23 && wrt_room($pdo, 'cold', $cold, 2, 2) >= 20);
wrt_add($pdo, 'cold', 2458); $chk('the hour is used up: no room', wrt_room($pdo, 'cold', $cold, 2, 1) === 0);
$chk('due once an hour', wrt_due($pdo, 'x') === true && wrt_due($pdo, 'x') === false);
// decisions
$chk('high failure: one step down', wrt_decide(2, 3, 12.0, 2400, 2500, 3.0, 8.0) === 1);
$chk('at the bottom stays at the bottom', wrt_decide(0, 3, 20.0, 1500, 1500, 3.0, 8.0) === 0);
$chk('runs well and the limit was used: one step up', wrt_decide(2, 3, 0.5, 2300, 2500, 3.0, 8.0) === 3);
$chk('runs well but the limit was not used: stays', wrt_decide(2, 3, 0.5, 900, 2500, 3.0, 8.0) === 2);
$chk('top stays at the top', wrt_decide(3, 3, 0.1, 3000, 3000, 3.0, 8.0) === 3);
$chk('too few sends to judge: stays', wrt_decide(2, 3, null, 2500, 2500, 3.0, 8.0) === 2);
$chk('in between: stays', wrt_decide(2, 3, 5.0, 2500, 2500, 3.0, 8.0) === 2);
// adapt end to end: the previous hour used 2,400 of 2,500 with 1% failures -> 3,000; then 30% failures -> back to 2,500
$h = wrt_hour(); $pdo->prepare("INSERT INTO wa_rate (k, hr, n) VALUES ('cold', ?, 2400)")->execute([$h - 1]);
$chk('adapt up to 3,000', wrt_adapt($pdo, 'cold', $cold, 2, 990, 10, 3.0, 8.0) === 3000 && wrt_limit($pdo, 'cold', $cold, 2) === 3000);
$chk('adapt down on failures', wrt_adapt($pdo, 'cold', $cold, 2, 700, 300, 3.0, 8.0) === 2500 && wrt_limit($pdo, 'cold', $cold, 2) === 2500);
$last = json_decode($S['wrt_last_cold'], true); $chk('the decision is recorded', ($last['fail_pct'] ?? 0) == 30.0 && ($last['to'] ?? 0) === 2500);
echo $fail ? "rate FAILED ($fail)\n" : "rate ok\n"; exit($fail ? 1 : 0);
