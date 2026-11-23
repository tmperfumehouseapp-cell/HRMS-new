<?php
// The final gate (owner, 23 Nov): own FINAL offer words, bigger jumps, unknown amounts, salesperson's price. In-memory database, stubs for the shop functions.
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_growth.php');
preg_match('/\nfunction wag_jump_to\([^\n]*\n/', $src, $mj); eval($mj[0]); preg_match('/\nfunction wag_near_idx\([^\n]*\n/', $src, $mn); eval($mn[0]);
foreach (['wag_fg_count', 'wag_fg_msgs', 'wag_final_gate', 'wag_ml_request', 'wag_chart_row', 'wag_arrival_idx', 'wag_person_price_guard'] as $f) {
    if (!preg_match('/\nfunction ' . $f . '\(.*?\n\}\n/s', $src, $m)) { echo "FAIL cannot find $f\n"; exit(1); } eval($m[0]);
}
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = $v; }
function wag_meta_get($p, $k, $d = '') { global $S; return $S['meta:' . $k] ?? $d; } function wag_log() {}
function ml_slab_get($p) { return ['cols' => [7, 6.5, 6, 5, 4, 3.5, 3], 'rows' => [
    ['ml' => 100, 'p' => [1810, 1750, 1700, 1690, 1680, 1670, 1650]], ['ml' => 150, 'p' => [2100, 2050, 2000, 1980, 1960, 1940, 1900]], ['ml' => 200, 'p' => [2350, 2280, 2200, 2180, 2160, 2130, 2050]],
    ['ml' => 250, 'p' => [2900, 2820, 2750, 2720, 2700, 2680, 2600]], ['ml' => 300, 'p' => [3400, 3300, 3200, 3150, 3100, 3050, 3000]], ['ml' => 350, 'p' => [3900, 3800, 3700, 3650, 3600, 3550, 3500]]]]; }
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, dir TEXT, who TEXT, body TEXT, mtype TEXT)");
function chat($pdo, $rows) { $pdo->exec("DELETE FROM wa_messages"); $st = $pdo->prepare("INSERT INTO wa_messages (phone, dir, who, body, mtype) VALUES ('p', ?, ?, ?, 'text')"); foreach ($rows as $r) $st->execute($r); }
$fail = 0; $chk = function ($name, $cond) use (&$fail) { if (!$cond) { echo "FAIL $name\n"; $fail++; } };
// (0) chart word / price-list promise
chat($pdo, [['in', '', 'price list bhejo']]);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, price list ki chart abhi bhej raha hun 😊 Aap kitni bottle aur kaunsi size lena chahenge?'); $chk('price list promise removed', !$b && stripos($t, 'chart') === false && strpos($t, 'kaunsi size') !== false);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, price list bhej raha hun.'); $chk('only the promise -> a question instead', !$b && stripos($t, 'price list') === false && strpos($t, 'combo price') !== false);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, 100 ml Rs 1,700 hai 😊'); $chk('6% column price is fine', !$b && strpos($t, '1,700') !== false);
[$t, $b] = wag_final_gate($pdo, 'p', 'Ek 100 ml bottle Rs 1,810 hai. Bulk mein ye Rs 1,650 tak aa sakti hai. Kaunse perfumes?'); $chk('below-floor price sentence removed', !$b && strpos($t, '1,650') === false && strpos($t, '1,810') !== false);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, 100 ml ka price Rs 1,650 hai.'); $chk('only a below-floor price -> blocked', $b === true);
chat($pdo, [['in', '', 'mujhe 1650 mein chahiye']]); [$t, $b] = wag_final_gate($pdo, 'p', 'Sir, 100 ml ka price Rs 1,650 hai.'); $chk('his own number is left alone', !$b);
// (1) own FINAL offer
chat($pdo, [['in', '', 'price of 100 ml']]);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, 100 ml Rs 1,810 hai. Ye FINAL offer hai, valid till 10 PM.'); $chk('final offer sentence removed', !$b && stripos($t, 'final offer') === false && strpos($t, '1,810') !== false);
[$t, $b] = wag_final_gate($pdo, 'p', 'Ye FINAL offer sirf 2 hours ke liye hai.'); $chk('only a final offer -> blocked', $b === true);
$S['meta:finaloffer:p'] = (string)(time() + 3000); [$t, $b] = wag_final_gate($pdo, 'p', 'Sir, offer valid till 10 PM, Rs 1,810.'); $chk('system final offer active: left alone', !$b && strpos($t, 'valid till') !== false); unset($S['meta:finaloffer:p']);
[$t, $b] = wag_final_gate($pdo, 'p', 'x', ['final' => true]); $chk('system final text skips the gate', $t === 'x' && !$b);
// (2) bigger jumps: asked 2 x 50 ml, the reply offers 3 x 50 ml -> 4 x 50 ml (200 ml row)
chat($pdo, [['in', '', '2 bottle 50 ml price']]);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, 3 x 50 ml ka combo Rs 2,100 mein ho jayega'); $chk('jump 2 -> 4', strpos($t, '4 x 50 ml') !== false && strpos($t, '2,350') !== false && !$b);
chat($pdo, [['in', '', '2 bottle 50 ml price']]);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, 4 x 50 ml ka combo Rs 2,350 mein'); $chk('already a jump of 2: untouched', strpos($t, '4 x 50 ml') !== false && $t === 'Sir, 4 x 50 ml ka combo Rs 2,350 mein');
chat($pdo, [['in', '', '2 x 50 ml']]);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, 2 x 50 ml Rs 1,810 hai'); $chk('the asked pack itself: untouched', strpos($t, '2 x 50 ml Rs 1,810') !== false);
// (3) unknown amount: counted, not blocked by default
chat($pdo, [['in', '', 'price?']]);
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, total Rs 7,777 padega'); $chk('unknown amount counted only', !$b); $j = json_decode($S['wag_fg_log'], true); $chk('counter', ($j['c']['unknown_amount:seen'] ?? 0) === 1);
$S['wag_fg_unk'] = '1'; [$t, $b] = wag_final_gate($pdo, 'p', 'Sir, total Rs 7,777 padega'); $chk('unknown amount blocks when switched on', $b === true); $S['wag_fg_unk'] = '0';
[$t, $b] = wag_final_gate($pdo, 'p', 'Sir, COD mein 35% advance Rs 823 aur ek bottle Rs 1,199 online'); $chk('known figures pass', !$b);
// salesperson's price
$GLOBALS['lib_fu_step'] = 1; $m = [['who' => 'salesperson', 'text' => "*100ml*\nAzzaro\nRasasi"], ['who' => 'salesperson', 'text' => '2349/-;'], ['who' => 'salesperson', 'text' => 'Shall we proceed order sir??']];
$chk('salesperson price kept', strpos(wag_person_price_guard($m, 'Hi, 100 ml ka total Rs 1,810 hai'), '2,349') !== false && wag_person_price_guard($m, 'Hi Rs 2,349 ok') === 'Hi Rs 2,349 ok');
echo $fail ? "final gate FAILED ($fail)\n" : "final gate ok\n"; exit($fail ? 1 : 0);
