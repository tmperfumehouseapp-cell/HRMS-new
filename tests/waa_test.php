<?php
// Auto assign rules (pure functions): php tests/waa_test.php
require __DIR__ . '/../empmanagment/wq_auto_rules.php';
$bad = 0; $eq = function ($n, $a, $b) use (&$bad) { if ($a !== $b) { echo "FAIL $n: " . json_encode($a) . " != " . json_encode($b) . "\n"; $bad++; } };
// ratio band: average 25% → allowed from 20%
$eq('22% allowed', waa_below(20, 4, 0.25), false);   // 20%: exactly on the line = allowed
$eq('19% below', waa_below(21, 4, 0.25), true);      // 19.05%
$eq('not judged before 5 queries', waa_below(4, 0, 0.25), false);
$eq('judged at 5 queries', waa_below(5, 0, 0.25), true);
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
// tiers (owner, 20 Nov): GREY (under 5 queries) first, one query each in turn; then green, green, yellow; red nothing
$eq('tier: grey under 5 queries', waa_tier(3, 3, 0.25), 0); $eq('tier: 4 queries is still grey', waa_tier(4, 4, 0.25), 0); $eq('tier: 5 queries is judged: green at the average', waa_tier(5, 2, 0.25), 1);
$eq('tier: green above', waa_tier(10, 6, 0.25), 1); $eq('tier: inside the band', waa_tier(10, 2, 0.25), 2); $eq('tier: red', waa_tier(10, 1, 0.25), 3);
$eq('tier: team ratio unknown = yellow', waa_tier(10, 2, 0.0), 2);
$g = fn($c, $t, $extra = []) => array_merge(['w' => 1.0, 'cnt' => $c, 'tier' => $t, 'full' => false], $extra);
// grey first, fewest first, in line (ties by the lower id) — 3 greys with 0 queries get 1, 2, 3 in turn
$cand = [3 => $g(0, 0), 5 => $g(0, 0), 7 => $g(0, 0), 1 => $g(20, 1), 2 => $g(9, 2)];
$seq = []; for ($i = 0; $i < 15; $i++) { $e = waa_pick_tiers($cand, 5, 0); $seq[] = $e; $cand[$e]['cnt']++; if ($cand[$e]['cnt'] >= 5) $cand[$e]['tier'] = 1; }
$eq('grey people are filled one query at a time, in line, until 5 each', array_slice($seq, 0, 15), [3, 5, 7, 3, 5, 7, 3, 5, 7, 3, 5, 7, 3, 5, 7]);
$eq('the greens are untouched until every grey has 5', $cand[1]['cnt'], 20);
// pattern green, green, yellow (6 to the greens = 3 to the yellows), each group in line
$P = [1 => $g(0, 1), 2 => $g(0, 1), 3 => $g(0, 1), 8 => $g(0, 2), 9 => $g(0, 2)]; $n = [1 => 0, 2 => 0, 3 => 0, 8 => 0, 9 => 0]; $pos = 0; $order = [];
for ($i = 0; $i < 9; $i++) { $e = waa_pick_tiers($P, 5, $pos); $n[$e]++; $P[$e]['cnt']++; $pos++; $order[] = $e; }
$eq('9 queries: 6 to the greens, 3 to the yellows', [$n[1] + $n[2] + $n[3], $n[8] + $n[9]], [6, 3]);
$eq('the pattern is green, green, yellow, in line', $order, [1, 2, 8, 3, 1, 9, 2, 3, 8]);
$eq('red is never given', waa_pick_tiers([4 => $g(9, 3)], 5, 0), 0);
$eq('no yellow can take it: the green does', waa_pick_tiers([1 => $g(3, 1)], 5, 2), 1); $eq('no green can take it: the yellow does', waa_pick_tiers([8 => $g(3, 2)], 5, 0), 8);
$eq('nobody', waa_pick_tiers([], 5, 0), 0);
// a full person is skipped (waiting customers), a resting one too while somebody else can take it
$F = [3 => $g(0, 0, ['full' => true]), 5 => $g(2, 0)]; $eq('a full grey is skipped', waa_pick_tiers($F, 5, 0), 5);
$R = [3 => $g(0, 0, ['rest' => true]), 1 => $g(9, 1)]; $eq('the only grey rests (just given one): the next group takes it, never 2 at once', waa_pick_tiers($R, 5, 0), 1);
$eq('only a resting grey exists: he still gets it', waa_pick_tiers([3 => $g(0, 0, ['rest' => true])], 5, 0), 3);
// THE LINE: inside a group the person whose last query is the OLDEST goes next (nobody given one yet = first); the target weight does not change the turn
$L = [1 => $g(14, 1, ['last' => 1000]), 2 => $g(9, 1, ['last' => 2000]), 3 => $g(12, 1, ['last' => 0]), 4 => $g(13, 1, ['last' => 0])];
$eq('green line: nobody given yet today first, fewer queries first', waa_pick_tiers($L, 5, 0), 3);
$L[3]['last'] = 3000; $eq('then the other who was never given', waa_pick_tiers($L, 5, 0), 4); $L[4]['last'] = 4000; $eq('then the oldest last give', waa_pick_tiers($L, 5, 0), 1);
$seq = []; $T = [1 => ['last' => 0, 'cnt' => 12], 2 => ['last' => 0, 'cnt' => 12], 3 => ['last' => 0, 'cnt' => 9]]; $clock = 100;
for ($i = 0; $i < 9; $i++) { $cand = []; foreach ($T as $e => $x) $cand[$e] = $g($x['cnt'], 1, ['last' => $x['last'], 'w' => $e === 1 ? 70000.0 : 25000.0]); $e = waa_pick_tiers($cand, 5, 0); $seq[] = $e; $T[$e]['last'] = ++$clock; $T[$e]['cnt']++; }
$eq('three greens (one with a 70,000 target) take turns: 3 each, in order', $seq, [3, 1, 2, 3, 1, 2, 3, 1, 2]);
$eq('yellows take turns too', (function () use ($g) { $c = [8 => $g(5, 2, ['last' => 10]), 9 => $g(5, 2, ['last' => 5])]; return waa_pick_tiers($c, 5, 2); })(), 9);
// 🔓 "No rules": he takes his turn in the line whatever group is served
$F = [1 => $g(12, 0, ['last' => 900]), 2 => $g(12, 0, ['last' => 950]), 7 => $g(18, 1, ['last' => 0, 'force' => true])];
$eq('normal rules pick an under-5 person', waa_pick_tiers($F, 5, 0), 1); $eq('the No-rules person who was never given goes instead', waa_pick_force($F, 1), 7);
$F[7]['last'] = 1000; $eq('after his give the line goes on with the others', waa_pick_force($F, waa_pick_tiers($F, 5, 0)), 1);
$F[1]['last'] = 1100; $F[2]['last'] = 1200; $eq('his turn comes again when his give is the oldest', waa_pick_force($F, waa_pick_tiers($F, 5, 0)), 7);
$F[7]['rest'] = true; $eq('not while he rests', waa_pick_force($F, 1), 1); $F[7]['rest'] = false; $F[7]['force'] = false; $eq('not without No rules', waa_pick_force($F, 1), 1); $eq('nobody picked stays nobody', waa_pick_force($F, 0), 0);
$seq = []; $F = [1 => $g(12, 0, ['last' => 10]), 2 => $g(12, 0, ['last' => 20]), 3 => $g(12, 0, ['last' => 30]), 7 => $g(18, 1, ['last' => 0, 'force' => true])]; $clock = 100;
for ($i = 0; $i < 8; $i++) { $e = waa_pick_force($F, waa_pick_tiers($F, 5, 0)); $seq[] = $e; $F[$e]['last'] = ++$clock; $F[$e]['cnt']++; }
$eq('over 8 queries he gets 2, like everybody else', count(array_keys($seq, 7)), 2);
// a customer's last message that needs no answer (it must not count as "waiting for his answer")
foreach (['ok', 'Ok thank you', 'K', 'thanks!', '👍', 'Already Ordered', 'order ho gaya', 'Thank you.'] as $b) $eq('no answer needed: ' . $b, waa_no_answer_needed('text', $b), true);
foreach (['Sir ??', 'Kb tk aaega', 'Bro where is my tracking id?', 'Please call me', 'Change address', 'Aa rakho'] as $b) $eq('needs an answer: ' . $b, waa_no_answer_needed('text', $b), false);
$eq('a reaction needs no answer', waa_no_answer_needed('reaction', ''), true); $eq('a voice note needs one', waa_no_answer_needed('audio', '🎤 Voice note'), false);
echo $bad ? "waa test: $bad problem(s)\n" : "waa test: ok\n"; exit($bad ? 1 : 0);
