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
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 480]); ok($S['wa_ru_hourly'] === '500', 'new ladder: 480 -> 500 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 500]); ok($S['wa_ru_hourly'] === '540', 'new ladder: 500 -> 540 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 540]); ok($S['wa_ru_hourly'] === '550', 'new ladder: 540 -> 550 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 550]); ok($S['wa_ru_hourly'] === '600', 'new ladder: 550 -> 600 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 600]); ok($S['wa_ru_hourly'] === '600', 'max 600: ceiling 600');
// owner 20 Nov 2026 (15:20): slots 550 / 600 / 700 (floor 550, top 700, never 800)
$S['wa_ru_ramp_max'] = '700'; $S['wa_ru_ramp_base'] = '550'; $S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 600]); ok($S['wa_ru_hourly'] === '700', '600 -> 700 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 700]); ok($S['wa_ru_hourly'] === '700', 'ceiling 700, never 800 (got ' . $S['wa_ru_hourly'] . ')');
$S['wa_ru_hourly'] = '700'; $S['wa_ru_pause_until'] = '0'; wa_ru_ramp_back($pdo, 'fail'); ok((int)$S['wa_ru_hourly'] >= 550, 'a failure never goes below the 550 floor (got ' . $S['wa_ru_hourly'] . ')');
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
$S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_ramp_chk'] = '0'; wa_ru_ramp($pdo, ['hourly' => 260]); ok(((int)($S['wa_ru_pause_until'] ?? 0)) > time() + 3 * 3600 - 60 && ((int)$S['wa_ru_pause_until']) <= time() + 3 * 3600 + 5 && $S['wa_ru_hourly'] === '260' && $S['wa_ru_freeze_n'] === '0', 'bad look 3 rests 3 hours and restarts at 260');
// 17) owner 20 Nov 2026 (02:30): low delivered share without failures is NOT a bad look: it only does not grow
$S = ['wa_ru_hourly' => '260', 'wa_ru_ramp_base' => '260', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '510', 'wa_ru_ramp_drop' => '100', 'wa_ru_ramp_at' => (string)(time() - 700)];
$pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 60, 0, 20); $pdo->exec("UPDATE wa_messages SET status = 'sent' WHERE rowid % 4 <> 0");
wa_ru_ramp($pdo, ['hourly' => 260]); ok($S['wa_ru_hourly'] === '260' && (int)($S['wa_ru_freeze_n'] ?? 0) === 0 && (int)($S['wa_ru_pause_until'] ?? 0) === 0, 'low delivered without failures: no hold count, no rest, no growth (got ' . $S['wa_ru_hourly'] . ' / ' . ($S['wa_ru_freeze_n'] ?? '') . ')');
// owner 20 Nov 2026 (18:55): 700 -> 800 -> 900 -> 1,000, one hour at each level, failed under 2% and delivered 85% or more
$H2 = ['wa_ru_hourly' => '700', 'wa_ru_ramp_base' => '550', 'wa_ru_ramp_step' => '60', 'wa_ru_ramp_every' => '600', 'wa_ru_ramp_max' => '1000', 'wa_ru_ramp_drop' => '100'];
$look = function ($hourly, $lvlAgeMin, $failed, $sentOnly) use ($pdo, &$H2, &$S) { $S = $H2; $S['wa_ru_hourly'] = (string)$hourly; $S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_lvl_at'] = (string)(time() - $lvlAgeMin * 60); $pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 130, $failed, 20); if ($sentOnly > 0) $pdo->exec("UPDATE wa_messages SET status = 'sent' WHERE status = 'delivered' AND rowid <= " . (130 + $sentOnly)); wa_ru_ramp($pdo, ['hourly' => $hourly]); return $S['wa_ru_hourly']; };
ok($look(700, 30, 0, 0) === '700', 'only 30 minutes at 700: stays 700 (got ' . $S['wa_ru_hourly'] . ')');
ok($look(700, 130, 0, 0) === '800' && (time() - (int)$S['wa_ru_lvl_at']) < 60, '1 hour at 700, good: 800 and the clock restarts');
ok($look(800, 40, 0, 0) === '800', '800 for 40 minutes: stays 800');
ok($look(800, 125, 0, 0) === '900', '1 hour at 800, good: 900');
ok($look(900, 125, 0, 0) === '1000', '1 hour at 900, good: 1,000');
ok($look(1000, 300, 0, 0) === '1000', 'ceiling 1,000');
// owner 21 Nov 2026: ceiling 1,200 (1,000 -> 1,100 -> 1,200, one hour each)
$H2['wa_ru_ramp_max'] = '1200';
ok($look(1000, 125, 0, 0) === '1100', '1 hour at 1,000, good: 1,100');
ok($look(1000, 30, 0, 0) === '1000', '30 minutes at 1,000: stays');
ok($look(1100, 125, 0, 0) === '1200', '1 hour at 1,100, good: 1,200');
ok($look(1200, 300, 0, 0) === '1200', 'ceiling 1,200');
ok($look(1000, 125, 4, 0) === '1000', 'failed 3% at 1,000: no step to 1,100');
// owner 21 Nov 2026: ceiling 1,500 (1,200 -> 1,300 -> 1,400 -> 1,500, one hour each)
$H2['wa_ru_ramp_max'] = '1500';
$look15 = function ($hourly, $lvlAgeMin, $failed, $sentOnly) use ($pdo, &$H2, &$S) { $S = $H2; $S['wa_ru_hourly'] = (string)$hourly; $S['wa_ru_ramp_at'] = (string)(time() - 700); $S['wa_ru_lvl_at'] = (string)(time() - $lvlAgeMin * 60); $pdo->exec("DELETE FROM wa_ru_log"); $pdo->exec("DELETE FROM wa_messages"); fill($pdo, 220, $failed, 20); wa_ru_ramp($pdo, ['hourly' => $hourly]); return $S['wa_ru_hourly']; };   /* the 1,200+ levels need a bigger look (150+ messages) */
ok($look15(1200, 125, 0, 0) === '1300', '1 hour at 1,200, good: 1,300');
ok($look15(1300, 125, 0, 0) === '1400', '1 hour at 1,300, good: 1,400');
$r14 = $look15(1400, 125, 0, 0); ok($r14 === '1500', '1 hour at 1,400, good: 1,500 (got ' . $r14 . ')');
ok($look15(1500, 300, 0, 0) === '1500', 'ceiling 1,500');
ok($look15(1200, 30, 0, 0) === '1200', '30 minutes at 1,200: stays');
ok($look15(1200, 125, 9, 0) === '1200', 'failed 4% at 1,200: no step to 1,300');
$H2['wa_ru_ramp_max'] = '1000';
ok(strpos(file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'), "'wa_ru_to1200_2111'") !== false, 'one-shot 1,200 seed present'); ok(strpos(file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'), "'wa_ru_to1500_2111'") !== false, 'one-shot 1,500 seed present');
ok($look(700, 130, 4, 0) === '700', 'failed 3%: no step up (and no bad look either)');
ok($look(700, 130, 0, 40) === '700', 'delivered under 85%: no step up');
ok($look(800, 130, 9, 0) === '800' && ($S['wa_ru_freeze_n'] ?? '') === '1', 'failed 7%: a bad look holds the level');
echo "ramp ok\n";
