<?php
// Owner 21 Nov 2026: marketing pauses every night 02:00-09:00 IST; utility keeps going; Module B is 1,000 an hour.
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_dq.php'); $a = strpos($src, 'function wa_mkt_paused'); $b = strpos($src, 'function wa_dq_auto_hold'); eval(substr($src, $a, $b - $a));
$pdo = new PDO('sqlite::memory:');
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
date_default_timezone_set('Europe/Berlin');   /* the server clock is not IST: the window must still follow IST */
function ist($s) { return strtotime($s . ' +0530'); }
ok(wa_mkt_night_end($pdo, ist('2026-11-21 03:45:00')) === ist('2026-11-21 09:00:00'), '03:45 IST: paused until 09:00 the same day');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 02:00:00')) === ist('2026-11-21 09:00:00'), '02:00 IST sharp: paused');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 08:59:59')) === ist('2026-11-21 09:00:00'), '08:59 IST: still paused');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 09:00:00')) === 0, '09:00 IST: marketing runs again');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 01:59:59')) === 0, '01:59 IST: marketing runs');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 14:00:00')) === 0, 'afternoon: marketing runs');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 23:30:00')) === 0, '23:30 IST: marketing runs');
$S['wa_mkt_night_on'] = '0'; ok(wa_mkt_night_end($pdo, ist('2026-11-21 03:45:00')) === 0, 'switched off: never pauses');
$S = ['wa_mkt_night_from' => '22', 'wa_mkt_night_to' => '6'];
ok(wa_mkt_night_end($pdo, ist('2026-11-21 23:00:00')) === ist('2026-11-22 06:00:00'), 'window across midnight: 23:00 pauses until 06:00 next day');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 05:00:00')) === ist('2026-11-21 06:00:00'), 'window across midnight: 05:00 pauses until 06:00');
ok(wa_mkt_night_end($pdo, ist('2026-11-21 12:00:00')) === 0, 'window across midnight: noon runs');
$f = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_dq.php');
ok(strpos($f, 'wa_dq_hold_active($pdo) || wa_mkt_paused($pdo)') !== false, 'the pause zeroes the marketing hour room');
ok(strpos(file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'), 'wa_mkt_paused') === false, 'the night pause does not touch Module B');
echo "night pause ok\n";
