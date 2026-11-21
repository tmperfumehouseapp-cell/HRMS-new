<?php
// Owner 22 Nov 2026: new numbers (9, 10, 6) send few messages and the hourly allowance climbs slowly; no new utility templates for their accounts.
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php');
ok(preg_match('/function wa_ru_new_ramp\(PDO \$pdo, int \$nowTs\): void\n\{.*?\n\}\n/s', $src, $m) === 1, 'ramp present');
$mix = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_mix.php');
ok(strpos($mix, 'wa_tpl_util_off_wabas') !== false, 'template engine skips utility for switched-off accounts');
ok(strpos($src, '[9, 10, 6]') !== false, 'numbers 9, 10 and 6 are seeded');
ok(strpos($src, '3059322737742478') !== false && strpos($src, "[9, 10, 6]") !== false && strpos($src, "'wa_ru_new_hourly', json_encode") !== false, 'seed writes hourly map');
if (extension_loaded('pdo_sqlite')) {
    $S = []; function setting_get($pdo, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($pdo, $k, $v) { global $S; $S[$k] = $v; } function wa_log(...$a) {}
    eval($m[0]);
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE wa_ru_log (number_id INTEGER, at TEXT, ok INTEGER)");
    $now = 1795300000; $t = date('Y-m-d H:i:s', $now - 600);
    $S['wa_ru_new_hourly'] = '{"9":10,"10":10,"6":40}';
    for ($i = 0; $i < 10; $i++) $pdo->exec("INSERT INTO wa_ru_log VALUES (10,'$t',1)");      // 10 used, 0 failed -> up
    for ($i = 0; $i < 3; $i++) $pdo->exec("INSERT INTO wa_ru_log VALUES (9,'$t',1)");        // barely used -> same
    for ($i = 0; $i < 30; $i++) $pdo->exec("INSERT INTO wa_ru_log VALUES (6,'$t'," . ($i < 5 ? 0 : 1) . ")");   // 5/30 failed -> halved
    wa_ru_new_ramp($pdo, $now); $h = json_decode($S['wa_ru_new_hourly'], true);
    ok($h['10'] === 20 && $h['9'] === 10 && $h['6'] === 20, 'climb / hold / halve: ' . json_encode($h));
    $S['wa_ru_new_hourly'] = '{"10":10}'; wa_ru_new_ramp($pdo, $now + 60); ok($S['wa_ru_new_hourly'] === '{"10":10}', 'only every 30 minutes');
}
echo "ru new ramp ok\n";
