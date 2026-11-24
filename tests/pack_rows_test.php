<?php
// A pack named in any word order must carry the price of its TOTAL ml row (owner, 24 Nov: "20 ml ke 8 bottle ka combo Rs 600" - 600 is the 100 ml price, 8 x 20 ml = 160 ml).
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_growth.php');
foreach (['wag_near_idx'] as $f) { if (!preg_match('/\nfunction ' . $f . '\([^\n]*\n/', $src, $m)) { echo "FAIL cannot find $f\n"; exit(1); } eval($m[0]); }
foreach (['wag_price_depth', 'wag_arrival_idx', 'wag_pack_rows_guard'] as $f) { if (!preg_match('/\nfunction ' . $f . '\(.*?\n\}\n/s', $src, $m)) { echo "FAIL cannot find $f\n"; exit(1); } eval($m[0]); }
function ml_slab_get($p) { return ['cols' => [7, 6.5, 6, 5, 4, 3.5, 3], 'rows' => [['ml' => 100, 'p' => [650, 630, 600, 580, 560, 540, 520]], ['ml' => 160, 'p' => [2270, 2200, 2150, 2100, 2050, 2000, 1950]], ['ml' => 200, 'p' => [2349, 2300, 2250, 2200, 2150, 2100, 2050]]]]; }
function wag_fg_count($p, $r, $a) { }
$pdo = new PDO('sqlite::memory:'); $GLOBALS['lib_fu_step'] = 0; $fail = 0; $chk = function ($n, $c) use (&$fail) { if (!$c) { echo "FAIL $n\n"; $fail++; } };
$msgs = [['who' => 'customer', 'text' => 'combo chahiye']];
$r1 = wag_pack_rows_guard($pdo, $msgs, 'Hi Rishav 😊 Sir, 20 ml ke 8 bottle ka combo Rs 600 mein ho jayega. Kaunse 8 perfumes lena chahenge?');
$chk('"20 ml ke 8 bottle" Rs 600 -> the 160 ml chart price', strpos($r1, 'Rs 2,270') === false && strpos($r1, 'Rs 2270') !== false);
$r2 = wag_pack_rows_guard($pdo, $msgs, 'Sir, 8 bottle 20 ml ka combo Rs 600 mein ho jayega 😊 Sirf Rs 100 aur dekar 2 bottle extra le sakte hain, jisse 10 bottle ho jayengi.');
$chk('"8 bottle 20 ml" Rs 600 -> 2270', strpos($r2, 'Rs 2270') !== false);
$chk('extra bottles amount is the row difference (200 ml 2349 - 160 ml 2270 = 79)', strpos($r2, 'Rs 79 aur dekar') !== false);
$r3 = wag_pack_rows_guard($pdo, $msgs, 'Sir, 8 x 20 ml ka combo Rs 600 hai');
$chk('"8 x 20 ml" Rs 600 -> 2270', strpos($r3, 'Rs 2270') !== false);
$ok = 'Sir, 8 x 20 ml ka combo Rs 2270 hai'; $chk('a price on the right row is left alone', wag_pack_rows_guard($pdo, $msgs, $ok) === $ok);
$ok2 = 'Sir, 5 x 20 ml ka combo Rs 600 hai'; $chk('5 x 20 ml = 100 ml row, 600 is its price: left alone', wag_pack_rows_guard($pdo, $msgs, $ok2) === $ok2);
$ok3 = 'Sir, 1 bottle 50 ml Rs 500 hai'; $chk('one bottle untouched', wag_pack_rows_guard($pdo, $msgs, $ok3) === $ok3);
echo $fail ? "pack rows FAILED ($fail)\n" : "pack rows ok\n"; exit($fail ? 1 : 0);
