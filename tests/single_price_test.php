<?php
// One bottle of 20 / 50 ml is never quoted below its official price (owner, 24 Nov: 'Sir, 50 ml ka price Rs 600 hai').
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_growth.php');
foreach (['wag_official_price', 'wag_one_bottle_msg', 'wag_single_price_guard'] as $f) { if (!preg_match('/\nfunction ' . $f . '\(.*?\n\}\n/s', $src, $m)) { echo "FAIL cannot find $f\n"; exit(1); } eval($m[0]); }
function setting_get($p, $k, $d = '') { return $d; }
function ml_slab_get($p) { return ['cols' => [7], 'rows' => [['ml' => 150, 'p' => [2250]], ['ml' => 200, 'p' => [2349]], ['ml' => 250, 'p' => [2940]]]]; }
function wag_chart_row($pdo, $total) { foreach (ml_slab_get($pdo)['rows'] as $r) if ((int)$r['ml'] === (int)$total) return ['ml' => (int)$r['ml'], 'p' => $r['p'], 'chart' => (int)$r['p'][0]]; return null; }
function wag_fg_count($p, $r, $a) { }
$pdo = new PDO('sqlite::memory:'); $fail = 0; $chk = function ($n, $c) use (&$fail) { if (!$c) { echo "FAIL $n\n"; $fail++; } };
$r = wag_single_price_guard($pdo, 'Sir, 50 ml ka price Rs 600 hai 😊 Kaunse perfumes lena chahoge?');
$chk('50 ml Rs 600 (one bottle) -> the one-bottle message', strpos($r, 'bahut mehenga') !== false && strpos($r, '1,199') !== false);
$ok = 'Sir, 50 ml ka price Rs 1,199 hai 😊'; $chk('the official 1,199 is left alone', wag_single_price_guard($pdo, $ok) === $ok);
$ok2 = 'Sir, 5 x 50 ml ka price Rs 2,940 hai'; $chk('a pack with a count is left alone', wag_single_price_guard($pdo, $ok2) === $ok2);
$ok3 = 'Sir, 5 x 20 ml ka price Rs 600 hai'; $chk('5 x 20 ml Rs 600 is left alone (the pack guard handles packs)', wag_single_price_guard($pdo, $ok3) === $ok3);
$ok4 = 'Sir, 100 ml ka price Rs 1,810 hai'; $chk('100 ml (could be a total) is left alone', wag_single_price_guard($pdo, $ok4) === $ok4);
echo $fail ? "single price FAILED ($fail)\n" : "single price ok\n"; exit($fail ? 1 : 0);
