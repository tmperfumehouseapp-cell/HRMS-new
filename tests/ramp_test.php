<?php
// Module B ramp: grows 100 -> 150 -> 200 while it runs well; failures stop it for 4 hours and it restarts at 100.
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; } function wa_log() { global $LOG; $LOG[] = func_get_args(); }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'); $a = strpos($src, 'function wa_ru_ramp_back'); $b = strpos($src, 'function wa_ru_tick'); eval(substr($src, $a, $b - $a));
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_ru_log (at TEXT, ok INT, wamid TEXT)"); $pdo->exec("CREATE TABLE wa_messages (wamid TEXT, status TEXT)");
function fill($pdo, $n, $failed, $ageMin) { static $c = 0; for ($i = 0; $i < $n; $i++) { $w = 'w' . (++$c); $st = $i < $failed ? 'failed' : 'delivered'; $pdo->prepare("INSERT INTO wa_ru_log VALUES (?, 1, ?)")->execute([date('Y-m-d H:i:s', time() - $ageMin * 60), $w]); $pdo->prepare("INSERT INTO wa_messages VALUES (?, ?)")->execute([$w, $st]); } }
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$S = ['wa_ru_hourly' => '100', 'wa_ru_ramp_base' => '100', 'wa_ru_ramp_step' => '50', 'wa_ru_ramp_at' => (string)(time() - 4000)];
// 1) a good hour: 90 sent, all delivered -> 150
fill($pdo, 90, 0, 30); wa_ru_ramp($pdo, ['hourly' => 100]); ok($S['wa_ru_hourly'] === '150', 'good hour grows to 150 (got ' . $S['wa_ru_hourly'] . ')');
// 2) the next look (within the hour) does not grow again
$S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 150]); ok($S['wa_ru_hourly'] === '150', 'no second increase within the hour');
// 3) an hour later, limit used (>= 60% of 150 = 90) and good -> 200
$S['wa_ru_ramp_at'] = (string)(time() - 3700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 150]); ok($S['wa_ru_hourly'] === '200', 'grows to 200 (got ' . $S['wa_ru_hourly'] . ')');
// 4) limit not used (few messages) -> no growth
$pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 30, 0, 30); $S['wa_ru_ramp_at'] = (string)(time() - 3700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 200]); ok($S['wa_ru_hourly'] === '200', 'no growth when the limit is not used');
// 5) failures (10 of 40) -> stopped for 4 hours, back to 100
$pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 40, 10, 30); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 200]);
ok($S['wa_ru_hourly'] === '100', 'failures: back to 100'); ok((int)$S['wa_ru_pause_until'] > time() + 4 * 3600 - 60 && (int)$S['wa_ru_pause_until'] < time() + 4 * 3600 + 60, 'failures: paused for 4 hours');
ok((int)$S['wa_ru_ramp_hold'] > time() + 3 * 3600, 'failures: no growth for 4 hours');
// 6) a spam flag sends it back too
$S['wa_ru_hourly'] = '300'; $S['wa_ru_pause_until'] = '0'; wa_ru_ramp_back($pdo, 'spam'); ok($S['wa_ru_hourly'] === '100' && (int)$S['wa_ru_pause_until'] > time() + 3 * 3600, 'spam: back to 100 and paused');
// 7) ceiling 600
$S = ['wa_ru_hourly' => '600', 'wa_ru_ramp_base' => '100', 'wa_ru_ramp_step' => '50', 'wa_ru_ramp_at' => (string)(time() - 4000)]; $pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 400, 0, 30); wa_ru_ramp($pdo, ['hourly' => 600]); ok($S['wa_ru_hourly'] === '600', 'ceiling 600');
echo "ramp ok\n";
