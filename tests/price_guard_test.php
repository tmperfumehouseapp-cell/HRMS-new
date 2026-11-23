<?php
// Price guards must read a price one rupee under the chart (x99, the style of our own cards) as that chart column (owner, 23 Nov 22:46: "6 x 50 ml Rs 2,799" after our card said 3,199; chart 300 ml = 3,200 ... 2,800).
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_growth.php');
foreach (['wag_near_idx', 'wag_price_talk'] as $f) { if (!preg_match('/\nfunction ' . $f . '\([^\n]*\n/', $src, $m)) { echo "FAIL cannot find $f\n"; exit(1); } eval($m[0]); }
foreach (['wag_price_depth', 'wag_arrival_idx', 'wag_pack_price_clamp'] as $f) { if (!preg_match('/\nfunction ' . $f . '\(.*?\n\}\n/s', $src, $m)) { echo "FAIL cannot find $f\n"; exit(1); } eval($m[0]); }
function ml_slab_get($p) { return ['cols' => [7, 6.5, 6, 5, 4, 3.5, 3], 'rows' => [['ml' => 300, 'p' => [3200, 3100, 3000, 2980, 2950, 2900, 2800]], ['ml' => 250, 'p' => [2940, 2850, 2750, 2730, 2700, 2660, 2570]]]]; }
$pdo = new PDO('sqlite::memory:'); $GLOBALS['lib_fu_step'] = 0; $fail = 0; $chk = function ($n, $c) use (&$fail) { if (!$c) { echo "FAIL $n\n"; $fail++; } };
$card = ['who' => 'bot', 'text' => "50ml COMBOS\n5 × 50ml — 250ml — ₹2,940/-\n6 × 50ml — 300ml — ₹3,199/-"];
$reply = 'Sir, 6 x 50 ml ka combo price Rs 2,799 hai 😊 Kaunse 6 perfumes lena chahenge?';
$m1 = [['who' => 'customer', 'text' => '50 ml'], $card, ['who' => 'customer', 'text' => '6x50']];
$chk('x99 deep price after our card -> back to the card level (3,200)', strpos(wag_pack_price_clamp($pdo, $m1, $reply), 'Rs 3,200') !== false);
$m2 = [['who' => 'customer', 'text' => '50 ml'], $card, ['who' => 'customer', 'text' => '6x50 mehenga hai']];
$chk('he says mehenga: one column lower only (3,100)', strpos(wag_pack_price_clamp($pdo, $m2, $reply), 'Rs 3,100') !== false);
$chk('the card price itself (3,199) is left alone', wag_pack_price_clamp($pdo, $m1, 'Sir, 6 x 50 ml ka combo Rs 3,199 hai') === 'Sir, 6 x 50 ml ka combo Rs 3,199 hai');
$chk('exact chart price left alone', wag_pack_price_clamp($pdo, $m1, 'Sir, 6 x 50 ml ka combo Rs 3,200 hai') === 'Sir, 6 x 50 ml ka combo Rs 3,200 hai');
$m3 = [['who' => 'customer', 'text' => '6x50']];
$chk('nothing quoted yet: the first price is the chart price', strpos(wag_pack_price_clamp($pdo, $m3, $reply), 'Rs 3,200') !== false);
$chk('depth sees a x99 price', wag_price_depth($m1, ['ml' => 300, 'p' => [3200, 3100, 3000, 2980, 2950, 2900, 2800], 'chart' => 3200]) === 0);
$chk('near idx', wag_near_idx([3200, 3100], 3199) === 0 && wag_near_idx([3200, 3100], 3150) === -1);
echo $fail ? "price guard FAILED ($fail)\n" : "price guard ok\n"; exit($fail ? 1 : 0);
