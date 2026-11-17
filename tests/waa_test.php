<?php
// Auto assign rules (pure functions): php tests/waa_test.php
require __DIR__ . '/../empmanagment/wq_auto_rules.php';
$bad = 0; $eq = function ($n, $a, $b) use (&$bad) { if ($a !== $b) { echo "FAIL $n: " . json_encode($a) . " != " . json_encode($b) . "\n"; $bad++; } };
// ratio band: average 25% → allowed from 20%
$eq('22% allowed', waa_below(20, 4, 0.25), false);   // 20%: exactly on the line = allowed
$eq('19% below', waa_below(21, 4, 0.25), true);      // 19.05%
$eq('not judged before 6 queries', waa_below(5, 0, 0.25), false);
$eq('judged at 6 queries', waa_below(6, 0, 0.25), true);
$eq('no company ratio', waa_below(30, 0, 0.0), false);
// speed
$eq('as fast as the team', waa_speed_factor(1.0, 1.0), 1.0); $eq('twice as slow = half', waa_speed_factor(2.0, 1.0), 0.5); $eq('faster = normal', waa_speed_factor(0.5, 1.0), 1.0); $eq('unknown = normal', waa_speed_factor(null, 1.0), 1.0);
// button
$eq('not punched in', waa_button(['present' => 0])[0], false); $eq('punched in', waa_button(['present' => 1])[0], true);
$eq('admin off', waa_button(['present' => 1, 'm' => 'off'])[0], false); $eq('admin on beats the ratio', waa_button(['present' => 1, 'm' => 'on', 'ao' => 1, 'below' => 1])[0], true);
$eq('ratio switches off', waa_button(['present' => 1, 'below' => 1]), [false, 'ratio']); $eq('stays off (sticky)', waa_button(['present' => 1, 'ao' => 1, 'below' => 0])[0], false);
$eq('admin on, not punched in = off', waa_button(['present' => 0, 'm' => 'on'])[0], false);
// weights: target in proportion (70,000 = 2.8 × 25,000); speed halves; admin ON ignores speed
$a = waa_weight_fresh(['t' => 25000], 40000); $b = waa_weight_fresh(['t' => 70000], 40000); $eq('2.8 times', round($b / $a, 2), 2.8);
$eq('slow = half', waa_weight_fresh(['t' => 40000, 'speed' => 2.0, 'team_speed' => 1.0], 40000), 20000.0);
$eq('admin on ignores speed', waa_weight_fresh(['t' => 40000, 'm' => 'on', 'speed' => 2.0, 'team_speed' => 1.0], 40000), 40000.0);
$eq('no target = average', waa_weight_fresh(['t' => 0], 30000), 30000.0);
$eq('campaign equal', waa_weight_camp(['present' => 1]), 1.0); $eq('campaign 2x below the band', waa_weight_camp(['below' => 1]), 2.0); $eq('campaign: admin on = normal', waa_weight_camp(['m' => 'on', 'below' => 1]), 1.0);
// pick: over 100 queries the shares follow the weights (25,000 : 70,000 → 26 : 74)
$c = [1 => ['w' => 25000.0, 'cnt' => 0], 2 => ['w' => 70000.0, 'cnt' => 0]]; $n = [1 => 0, 2 => 0];
for ($i = 0; $i < 95; $i++) { $e = waa_pick($c, 0); $n[$e]++; $c[$e]['cnt']++; }
$eq('shares follow the target', [$n[1] >= 24 && $n[1] <= 27, $n[2] >= 68 && $n[2] <= 71], [true, true]);
$eq('minimum 6 first', waa_pick([1 => ['w' => 70000.0, 'cnt' => 9], 2 => ['w' => 1000.0, 'cnt' => 2]], 6), 2);
$eq('nobody', waa_pick([], 6), 0);
$eq('tie → lowest id', waa_pick([5 => ['w' => 1.0, 'cnt' => 0], 3 => ['w' => 1.0, 'cnt' => 0]], 0), 3);
// campaign: 2× for the one below the band
$c = [1 => ['w' => 1.0, 'cnt' => 0], 2 => ['w' => 2.0, 'cnt' => 0]]; $n = [1 => 0, 2 => 0]; for ($i = 0; $i < 90; $i++) { $e = waa_pick($c, 0); $n[$e]++; $c[$e]['cnt']++; }
$eq('campaign 1 : 2', [$n[1], $n[2]], [30, 60]);
// overnight
$eq('night hours', [waa_night(0), waa_night(9), waa_night(10), waa_night(23)], [true, true, false, false]);
$eq('night: 5 picked, below = blocked', waa_night_block(true, false, 5), true); $eq('night: 4 picked = ok', waa_night_block(true, false, 4), false);
$eq('night: good ratio unlimited', waa_night_block(false, false, 50), false); $eq('night: admin on unlimited', waa_night_block(true, true, 50), false);
// tiers: green first, then the band / not judged yet; red never
$eq('tier: not judged yet', waa_tier(3, 3, 0.25), 2); $eq('tier: green at the average', waa_tier(10, 3, 0.25), 1); $eq('tier: green above', waa_tier(10, 6, 0.25), 1);
$eq('tier: inside the band', waa_tier(10, 2, 0.25), 2); $eq('tier: red', waa_tier(10, 1, 0.25), 3);
$cand = [1 => ['w' => 1.0, 'cnt' => 6, 'tier' => 2, 'full' => false], 2 => ['w' => 1.0, 'cnt' => 20, 'tier' => 1, 'full' => false]];
$eq('green is chosen first, however many it has', waa_pick_tiers($cand, 6), 2);
$cand[2]['full'] = true; $eq('a full green is skipped', waa_pick_tiers($cand, 6), 1);
$cand[1]['full'] = true; $eq('everybody full: still given, green first', waa_pick_tiers($cand, 6), 2);
$eq('two greens share by target', (function () { $c = [1 => ['w' => 25000.0, 'cnt' => 0, 'tier' => 1], 2 => ['w' => 70000.0, 'cnt' => 0, 'tier' => 1]]; $n = [1 => 0, 2 => 0]; for ($i = 0; $i < 95; $i++) { $e = waa_pick_tiers($c, 6); $n[$e]++; $c[$e]['cnt']++; } return [$n[1] >= 24 && $n[1] <= 27, $n[2] >= 68 && $n[2] <= 71]; })(), [true, true]);
$eq('no red in the list: band people get it when no green', waa_pick_tiers([5 => ['w' => 1.0, 'cnt' => 3, 'tier' => 2]], 6), 5);
$eq('nobody', waa_pick_tiers([], 6), 0);
echo $bad ? "waa test: $bad problem(s)\n" : "waa test: ok\n"; exit($bad ? 1 : 0);
