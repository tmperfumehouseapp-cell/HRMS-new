<?php
// Offer + add-on: 8x50ml 3299 (400 ml) with an add-on 80 ml / 750 = 480 ml at about 4049 earns the offer's %, like the offer itself. Pulls the two pure functions out of budget_cm.php (Total Budgeting's commission engine; it was inside boss2.php before).
$src = file_get_contents(__DIR__ . '/../empmanagment/budget_cm.php'); $bad = 0;
function setting_get($pdo, $k, $d = '') { return $GLOBALS['__S'][$k] ?? $d; }
$pdo = new PDO('sqlite::memory:'); $GLOBALS['pdo'] = $pdo;
foreach (['cm_row_prices', 'cm_pct', 'cm_offer_match', 'cm_offer_addon_list', 'cm_global_addons', 'cm_offer_addons_all', 'cm_addon_chart'] as $fn) { $i = strpos($src, 'function ' . $fn . '('); $j = strpos($src, "\n}\n", $i); if ($i === false || $j === false) { echo "missing $fn\n"; exit(1); } eval(substr($src, $i, $j - $i + 3)); }
$eq = function ($n, $a, $b) use (&$bad) { if ($a !== $b) { $bad++; echo "FAIL $n: got " . json_encode($a) . " expected " . json_encode($b) . "\n"; } };
$of = [['id' => 7, 'ml' => 400, 'price' => 3299.0, 'pct' => 6.0, 'from_date' => '2026-11-01', 'to_date' => null, 'active' => 1, 'addons' => [['ml' => 80, 'amount' => 750.0], ['ml' => 60, 'amount' => 450.0]]]];
$eq('offer itself', cm_offer_match($of, 400, 3299, '2026-11-20', 110)['id'] ?? 0, 7);
$m = cm_offer_match($of, 480, 3968, '2026-11-20', 110); $eq('offer + 80 ml add-on (3968 vs 4049, inside the window)', $m['id'] ?? 0, 7); $eq('add-on amount is reported', $m['addon'] ?? 0, 750.0);
$eq('offer + 60 ml add-on at 3749', cm_offer_match($of, 460, 3760, '2026-11-20', 110)['addon'] ?? 0, 450.0);
$eq('price too far from offer + add-on', cm_offer_match($of, 480, 3500, '2026-11-20', 110), null);
$eq('an ml that is not offer + add-on', cm_offer_match($of, 470, 3900, '2026-11-20', 110), null);
$eq('before the offer starts', cm_offer_match($of, 480, 4049, '2026-10-30', 110), null);
$off = $of; $off[0]['active'] = 0; $off[0]['stopped_at'] = '2026-11-10 12:00:00'; $eq('stopped offer: after its last day', cm_offer_match($off, 480, 4049, '2026-11-20', 110), null);
$no = $of; $no[0]['addons'] = []; $eq('no add-ons: 480 ml does not match', cm_offer_match($no, 480, 4049, '2026-11-20', 110), null);
$eq('typed text', cm_offer_addon_list('80:750, 60:450'), [['ml' => 80, 'amount' => 750.0], ['ml' => 60, 'amount' => 450.0]]);
$eq('typed with words', cm_offer_addon_list("80 ml = Rs 750\n60ml-450"), [['ml' => 80, 'amount' => 750.0], ['ml' => 60, 'amount' => 450.0]]);
$eq('json string', cm_offer_addon_list('[{"ml":80,"amount":750}]'), [['ml' => 80, 'amount' => 750.0]]);
$eq('repeated ml and bad rows are dropped', cm_offer_addon_list('80:750, 80:900, x:5, 0:10, 40:0'), [['ml' => 80, 'amount' => 750.0]]);
// global add-ons (one list for every offer and the price chart)
$GLOBALS['__S']['cm_addons'] = '80:750, 50:450'; cm_global_addons($pdo, true);
$plain = [['id' => 9, 'ml' => 400, 'price' => 3299.0, 'pct' => 6.0, 'from_date' => '2026-11-01', 'to_date' => null, 'active' => 1, 'addons' => []]];
$eq('global add-on works on an offer with no add-ons of its own', cm_offer_match($plain, 480, 3968, '2026-11-20', 110)['id'] ?? 0, 9);
$eq('another global add-on (50 ml / 450) on the same offer', cm_offer_match($plain, 450, 3750, '2026-11-20', 110)['addon'] ?? 0, 450.0);
$ch = ['cols' => [6, 4], 'floor' => 0, 'rows' => [['ml' => 400, 'p' => [3299, 2799]], ['ml' => 500, 'p' => [6999, 5999]]]];
$r = cm_addon_chart($ch, 480, 3968, 110); $eq('chart path: underpriced 480 ml becomes 400 ml + add-on 80', $r ? [$r[1], (int)$r[2]['ml']] : null, [400, 80]); $eq('chart path: pct of the base size', $r[0] ?? null, 6.0);
$eq('chart path: a normal chart price is left alone', cm_addon_chart($ch, 480, 6300, 110), null);
$eq('chart path: base too cheap too', cm_addon_chart($ch, 480, 2000, 110), null);
$GLOBALS['__S']['cm_addons'] = ''; cm_global_addons($pdo, true); $eq('no global add-ons: nothing changes', cm_addon_chart($ch, 480, 3968, 110), null);
// marketplace orders: flat % without a code (setting mp_flat_pct, default 6)
$api = file_get_contents(__DIR__ . '/../empmanagment/api.php'); $i = strpos($api, 'function mp_flat_pct('); $j = strpos($api, "\n", $i); eval(substr($api, $i, $j - $i));
$eq('marketplace flat % defaults to 6', mp_flat_pct($pdo), 6.0);
echo $bad ? "offer addon test: $bad problem(s)\n" : "offer addon test: ok\n"; exit($bad ? 1 : 0);
