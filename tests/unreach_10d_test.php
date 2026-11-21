<?php
// Owner 21 Nov 2026: people Meta held (131049) are left out of marketing for 10 days (was 5).
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; } function wa_log() { }
function wa_unreach_tables($p) { return true; }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php'); $a = strpos($src, 'function wa_unreach_cond'); $b = strpos($src, '/** One-time fill of wa_unreach'); eval(substr($src, $a, $b - $a));
$pdo = new PDO("sqlite::memory:");
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
[$cond, $par] = wa_unreach_cond($pdo);
ok($S['wa_unreach_days'] === '10', 'the hold is 10 days');
ok(abs(strtotime($par[0]) - (time() - 10 * 86400)) < 5, '131049 cut-off is 10 days ago');
$S['wa_unreach_days'] = '7'; wa_unreach_cond($pdo); ok($S['wa_unreach_days'] === '7', 'the seed runs once: a later manual change stays');
$S = ['wa_unreach_on' => '0']; ok(wa_unreach_cond($pdo) === ['', []], 'switched off: no condition');
echo "unreach 10d ok\n";
