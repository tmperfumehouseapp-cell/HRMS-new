<?php
// Module B ramp: grows 100 -> 150 -> 200 while it runs well; failures stop it for 4 hours and it restarts at 100.
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; } function wa_log() { global $LOG; $LOG[] = func_get_args(); }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'); $a = strpos($src, 'function wa_ru_spam_hours'); $b = strpos($src, 'function wa_ru_tick'); eval(substr($src, $a, $b - $a));
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_ru_log (at TEXT, ok INT, wamid TEXT)"); $pdo->exec("CREATE TABLE wa_messages (wamid TEXT, status TEXT)");
function fill($pdo, $n, $failed, $ageMin) { static $c = 0; for ($i = 0; $i < $n; $i++) { $w = 'w' . (++$c); $st = $i < $failed ? 'failed' : 'delivered'; $pdo->prepare("INSERT INTO wa_ru_log VALUES (?, 1, ?)")->execute([date('Y-m-d H:i:s', time() - $ageMin * 60), $w]); $pdo->prepare("INSERT INTO wa_messages VALUES (?, ?)")->execute([$w, $st]); } }
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$S = ['wa_ru_hourly' => '100', 'wa_ru_ramp_base' => '100', 'wa_ru_ramp_step' => '50', 'wa_ru_ramp_at' => (string)(time() - 4000)];
// 1b) 75% delivered, none failed, the rest only 'sent' -> still good
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 80, 0, 30); $pdo->exec("UPDATE wa_messages SET status = 'sent' WHERE rowid % 4 = 0"); $S = ['wa_ru_hourly' => '100', 'wa_ru_ramp_base' => '100', 'wa_ru_ramp_step' => '50', 'wa_ru_ramp_at' => (string)(time() - 4000)]; wa_ru_ramp($pdo, ['hourly' => 100]); ok($S['wa_ru_hourly'] === '150', '75% delivered grows to 150 (got ' . $S['wa_ru_hourly'] . ')');
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages");
// 1) a good hour: 90 sent, all delivered -> 150 (75% delivered and the rest only 'sent' also counts as good: see 1b)
fill($pdo, 90, 0, 30); wa_ru_ramp($pdo, ['hourly' => 100]); ok($S['wa_ru_hourly'] === '150', 'good hour grows to 150 (got ' . $S['wa_ru_hourly'] . ')');
// 2) the next look (within the hour) does not grow again
$S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 150]); ok($S['wa_ru_hourly'] === '150', 'no second increase within the hour');
// 3) an hour later, limit used (>= 60% of 150 = 90) and good -> 200
$S['wa_ru_ramp_at'] = (string)(time() - 3700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 150]); ok($S['wa_ru_hourly'] === '200', 'grows to 200 (got ' . $S['wa_ru_hourly'] . ')');
// 4) limit not used (few messages) -> no growth
$pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 30, 0, 30); $S['wa_ru_ramp_at'] = (string)(time() - 3700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 200]); ok($S['wa_ru_hourly'] === '200', 'no growth when the limit is not used');
// 5) failures (10 of 40) -> stopped for 2 hours, back to 100
$pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 40, 10, 30); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 200]);
ok($S['wa_ru_hourly'] === '100', 'failures: back to 100'); ok((int)$S['wa_ru_pause_until'] > time() + 2 * 3600 - 60 && (int)$S['wa_ru_pause_until'] < time() + 2 * 3600 + 60, 'failures: paused for 2 hours');
ok((int)$S['wa_ru_ramp_hold'] > time() + 1 * 3600, 'failures: no growth for 2 hours');
// 6) a spam flag sends it back too
$S['wa_ru_hourly'] = '300'; $S['wa_ru_pause_until'] = '0'; wa_ru_ramp_back($pdo, 'spam'); ok($S['wa_ru_hourly'] === '100' && (int)$S['wa_ru_pause_until'] > time() + 3 * 3600 && (int)$S['wa_ru_pause_until'] < time() + 5 * 3600, 'spam: back to 100 and paused 4 hours (first step)');
// 6b) the ladder: 4 hours, then 8, then 12, then 24 hours
$S['wa_ru_spam_n'] = '0'; unset($S['wa_ru_spam_last']); ok(wa_ru_spam_hours($pdo, time()) === 4, 'ladder 1 = 4'); ok(wa_ru_spam_hours($pdo, time()) === 8, 'ladder 2 = 8'); ok(wa_ru_spam_hours($pdo, time()) === 12, 'ladder 3 = 12'); ok(wa_ru_spam_hours($pdo, time()) === 24, 'ladder 4 = 24'); ok(wa_ru_spam_hours($pdo, time()) === 24, 'ladder 5 = 24'); ok(wa_ru_spam_hours($pdo, time() + 4 * 86400) === 4, 'ladder starts again after 3 calm days');
$S['wa_ru_spam_n'] = '0'; unset($S['wa_ru_spam_last']);
// 7) ceiling 600
$S = ['wa_ru_hourly' => '600', 'wa_ru_ramp_base' => '100', 'wa_ru_ramp_step' => '50', 'wa_ru_ramp_at' => (string)(time() - 4000)]; $pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 400, 0, 30); wa_ru_ramp($pdo, ['hourly' => 600]); ok($S['wa_ru_hourly'] === '600', 'ceiling 600');
// 8) failing at 300 restarts at 200 (two steps below), at 150 / 200 it restarts at the base, the spam flag goes to the base
$S['wa_ru_hourly'] = '300'; $S['wa_ru_pause_until'] = '0'; wa_ru_ramp_back($pdo, 'fail'); ok($S['wa_ru_hourly'] === '200', 'failure at 300 restarts at 200');
$S['wa_ru_hourly'] = '150'; wa_ru_ramp_back($pdo, 'fail'); ok($S['wa_ru_hourly'] === '100', 'failure at 150 restarts at 100');
$S['wa_ru_hourly'] = '400'; wa_ru_ramp_back($pdo, 'spam'); ok($S['wa_ru_hourly'] === '100', 'spam flag restarts at the base');
// 9) ladder 200 / 250 / 300: floor 200
$S['wa_ru_ramp_base'] = '200'; $S['wa_ru_hourly'] = '300'; wa_ru_ramp_back($pdo, 'fail'); ok($S['wa_ru_hourly'] === '200', 'ladder: failure at 300 restarts at 200');
$S['wa_ru_hourly'] = '250'; wa_ru_ramp_back($pdo, 'fail'); ok($S['wa_ru_hourly'] === '200', 'ladder: failure at 250 restarts at 200');
$S['wa_ru_hourly'] = '300'; wa_ru_ramp_back($pdo, 'spam'); ok($S['wa_ru_hourly'] === '200', 'ladder: spam flag restarts at the floor 200');
// 10) small steps: +20 every 20 minutes up to 400, drop 100 after a failure, floor 200
$S = ['wa_ru_hourly' => '200', 'wa_ru_ramp_base' => '200', 'wa_ru_ramp_step' => '20', 'wa_ru_ramp_every' => '1200', 'wa_ru_ramp_max' => '400', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 1300)];
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 50, 0, 18);   // 50 messages 18 minutes old (sent after the last change, receipts in), all good
wa_ru_ramp($pdo, ['hourly' => 200]); ok($S['wa_ru_hourly'] === '220', 'small step: 200 -> 220 after 20 good minutes (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 220]); ok($S['wa_ru_hourly'] === '220', 'small step: no second step before 20 minutes pass');
$S['wa_ru_ramp_at'] = (string)(time() - 1300); $S['wa_ru_ramp_chk'] = '0'; $pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 3, 0, 18); wa_ru_ramp($pdo, ['hourly' => 220]); ok($S['wa_ru_hourly'] === '220', 'small step: no growth when the limit is not used');
$S['wa_ru_hourly'] = '400'; $S['wa_ru_ramp_at'] = (string)(time() - 1300); $S['wa_ru_ramp_chk'] = '0'; $pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 150, 0, 18); wa_ru_ramp($pdo, ['hourly' => 400]); ok($S['wa_ru_hourly'] === '400', 'small step: ceiling 400');
$S['wa_ru_hourly'] = '400'; $S['wa_ru_pause_until'] = '0'; wa_ru_ramp_back($pdo, 'fail'); ok($S['wa_ru_hourly'] === '300', 'failure at 400 restarts at 300');
$S['wa_ru_hourly'] = '240'; wa_ru_ramp_back($pdo, 'fail'); ok($S['wa_ru_hourly'] === '200', 'failure at 240 restarts at the floor 200');
// 11) small steps at night: 40 of 60 delivered (67%), the rest only 'sent', no failure -> still grows
$S = ['wa_ru_hourly' => '200', 'wa_ru_ramp_base' => '200', 'wa_ru_ramp_step' => '20', 'wa_ru_ramp_every' => '1200', 'wa_ru_ramp_max' => '400', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 1700)];
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 0, 18); $pdo->exec("UPDATE wa_messages SET status = 'sent' WHERE rowid % 3 = 0"); wa_ru_ramp($pdo, ['hourly' => 200]); ok($S['wa_ru_hourly'] === '220', 'small step: 67% delivered and no failure grows (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_hourly'] = '220'; $S['wa_ru_ramp_at'] = (string)(time() - 1700); $S['wa_ru_ramp_chk'] = '0'; $pdo->exec("UPDATE wa_messages SET status = 'sent' WHERE rowid % 2 = 0 OR rowid % 3 = 0"); wa_ru_ramp($pdo, ['hourly' => 220]); ok($S['wa_ru_hourly'] === '220', 'small step: only 33% delivered does not grow');
// 12) owner 19 Nov 2026 (23:05): looked at every 10 minutes, +20, top 320
$S = ['wa_ru_hourly' => '260', 'wa_ru_ramp_base' => '200', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '350', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 700)];
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 50, 0, 20);
wa_ru_ramp($pdo, ['hourly' => 260]); ok($S['wa_ru_hourly'] === '300', '10-minute look: 260 -> 300 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 300]); ok($S['wa_ru_hourly'] === '300', '10-minute look: no second step before 10 minutes pass');
$S['wa_ru_hourly'] = '350'; $S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 350]); ok($S['wa_ru_hourly'] === '350', '10-minute look: ceiling 350');
$S['wa_ru_hourly'] = '300'; $S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; $pdo->exec("DELETE FROM wa_ru_log"); fill($pdo, 50, 8, 20); wa_ru_ramp($pdo, ['hourly' => 300]); ok($S['wa_ru_hourly'] === '200', '10-minute look: 16% failed stops it and restarts 100 lower (got ' . $S['wa_ru_hourly'] . ')');
$S = ['wa_ru_hourly' => '260', 'wa_ru_ramp_base' => '180', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '350', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 700)]; $pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 50, 0, 20); wa_ru_ramp($pdo, ['hourly' => 260]); ok($S['wa_ru_hourly'] === '300', 'grid: from 260 the next step is 300 (+10 per 10 minutes) (got ' . $S['wa_ru_hourly'] . ')');
// 13) owner 19 Nov 2026 (23:20): ladder 180 / 240 / 300 / 350 an hour (30 / 40 / 50 / 58 per 10 minutes), from 260 the next is 300, rising failures hold it, a second bad look in a row -> 30
$G = ['wa_ru_hourly' => '240', 'wa_ru_ramp_base' => '180', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '350', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 700)]; $S = $G;
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 50, 0, 20);
wa_ru_ramp($pdo, ['hourly' => 240]); ok($S['wa_ru_hourly'] === '300', 'grid: 240 -> 300 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 300]); ok($S['wa_ru_hourly'] === '350', 'grid: 300 -> 350');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 350]); ok($S['wa_ru_hourly'] === '350', 'grid: ceiling 350');
$S = $G; $S['wa_ru_hourly'] = '300'; $pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 50, 4, 20);   /* 8% failed: rising, not yet the 10% brake */
wa_ru_ramp($pdo, ['hourly' => 300]); ok($S['wa_ru_hourly'] === '300' && ($S['wa_ru_freeze_n'] ?? '') === '1', 'grid: first bad look holds at 300 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 300]); ok($S['wa_ru_hourly'] === '180', 'grid: second bad look in a row -> back to 180 (got ' . $S['wa_ru_hourly'] . ')');
$S = $G; $S['wa_ru_hourly'] = '300'; $S['wa_ru_freeze_n'] = '1'; $pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 50, 0, 20);
wa_ru_ramp($pdo, ['hourly' => 300]); ok($S['wa_ru_hourly'] === '350' && $S['wa_ru_freeze_n'] === '0', 'grid: a good look after a hold grows again and clears the count');
// 14) owner 19 Nov 2026 (00:55): above 350 each level needs 2 good hours: 350, 410, 450, 510
$H = ['wa_ru_hourly' => '350', 'wa_ru_ramp_base' => '180', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '600', 'wa_ru_ramp_drop' => '100']; $S = $H; $S['wa_ru_ramp_at'] = (string)(time() - 3000);
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 0, 20);
wa_ru_ramp($pdo, ['hourly' => 350]); ok($S['wa_ru_hourly'] === '360', 'new ladder: no 2 hour wait, 350 -> 360 at once (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 360]); ok($S['wa_ru_hourly'] === '420', 'new ladder: 360 -> 420 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 420]); ok($S['wa_ru_hourly'] === '480', 'new ladder: 420 -> 480 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 480]); ok($S['wa_ru_hourly'] === '540', 'new ladder: 480 -> 540 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 540]); ok($S['wa_ru_hourly'] === '600', 'new ladder: 540 -> 600 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 600]); ok($S['wa_ru_hourly'] === '600', 'new ladder: ceiling 600');
$S = $H; $S['wa_ru_hourly'] = '300'; $S['wa_ru_ramp_at'] = (string)(time() - 700); wa_ru_ramp($pdo, ['hourly' => 300]); ok($S['wa_ru_hourly'] === '360', 'below 350 the 10-minute step still works: 300 -> 360 (got ' . $S['wa_ru_hourly'] . ')');
$S = $H; $S['wa_ru_hourly'] = '410'; $S['wa_ru_ramp_at'] = (string)(time() - 7300); $pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 5, 20); wa_ru_ramp($pdo, ['hourly' => 410]); ok($S['wa_ru_hourly'] === '410' && ($S['wa_ru_freeze_n'] ?? '') === '1', 'a bad look at 410 holds and restarts the 2 hours');
// 15) owner 20 Nov 2026 (02:00): the floor is 260 an hour, never 180
$S = ['wa_ru_hourly' => '350', 'wa_ru_ramp_base' => '260', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '510', 'wa_ru_ramp_drop' => '100', 'wa_ru_freeze_n' => '1', 'wa_ru_ramp_at' => (string)(time() - 700)];
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 5, 20);   /* only 25% delivered, no failure */
wa_ru_ramp($pdo, ['hourly' => 350]); ok($S['wa_ru_hourly'] === '260', 'second bad look goes back to the floor 260, not 180 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; $pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 0, 20); wa_ru_ramp($pdo, ['hourly' => 260]); ok($S['wa_ru_hourly'] === '300', 'from the floor 260 the next step is 300 (got ' . $S['wa_ru_hourly'] . ')');
// 16) owner 20 Nov 2026 (02:15): the third bad look in a row means a 4 hour rest, restart at the floor
$S = ['wa_ru_hourly' => '320', 'wa_ru_ramp_base' => '260', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '510', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 700)];
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 5, 20);
wa_ru_ramp($pdo, ['hourly' => 320]); ok($S['wa_ru_hourly'] === '320' && $S['wa_ru_freeze_n'] === '1', 'bad look 1 holds');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 320]); ok($S['wa_ru_hourly'] === '260' && $S['wa_ru_freeze_n'] === '2', 'bad look 2 goes back to 260 (got ' . $S['wa_ru_hourly'] . ' / ' . ($S['wa_ru_freeze_n'] ?? '') . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 260]); ok(((int)($S['wa_ru_pause_until'] ?? 0)) > time() + 3 * 3600 && ((int)$S['wa_ru_pause_until']) <= time() + 4 * 3600 + 5 && $S['wa_ru_hourly'] === '260' && $S['wa_ru_freeze_n'] === '0', 'bad look 3 rests 4 hours and restarts at 260');
// 17) owner 20 Nov 2026 (02:30): low delivered share without failures is NOT a bad look: it only does not grow
$S = ['wa_ru_hourly' => '260', 'wa_ru_ramp_base' => '260', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '510', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 700)];
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 0, 20); $pdo->exec("UPDATE wa_messages SET status = 'sent' WHERE rowid % 4 <> 0");
wa_ru_ramp($pdo, ['hourly' => 260]); ok($S['wa_ru_hourly'] === '260' && (int)($S['wa_ru_freeze_n'] ?? 0) === 0 && (int)($S['wa_ru_pause_until'] ?? 0) === 0, 'low delivered without failures: no hold count, no rest, no growth (got ' . $S['wa_ru_hourly'] . ' / ' . ($S['wa_ru_freeze_n'] ?? '') . ')');
echo "ramp ok\n";
