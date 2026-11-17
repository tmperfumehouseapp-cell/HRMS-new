<?php
// Ticket goal rules (pure functions): php tests/tk_test.php
require __DIR__ . '/../empmanagment/tk_rules.php';
$bad = 0; $eq = function ($n, $a, $b) use (&$bad) { if ($a !== $b) { echo "FAIL $n: " . json_encode($a) . " != " . json_encode($b) . "\n"; $bad++; } };
$eq('green at the goal', tk_status(2700, 2700), 'g'); $eq('green above', tk_status(3100, 2700), 'g'); $eq('amber within 10%', tk_status(2430, 2700), 'a'); $eq('red below 90%', tk_status(2429, 2700), 'r'); $eq('no orders yet', tk_status(0, 2700), ''); $eq('no goal', tk_status(2500, 0), '');
// 3 orders, ₹6,300 (ticket 2,100): the next order must be 4 × 2,700 − 6,300 = 4,500
$eq('next order needed', tk_need_next(6300, 3, 2700), 4500); $eq('above the goal: the smallest next order that keeps it', tk_need_next(9000, 3, 2700), 1800); $eq('far above: any order keeps it', tk_need_next(12000, 3, 2700), 0); $eq('first order', tk_need_next(0, 0, 2700), 2700); $eq('no goal', tk_need_next(5000, 2, 0), 0);
$eq('hold: low ticket, 4 orders, 3 waiting', tk_hold(2100, 4, 3, 2300, 4, 3), true); $eq('no hold: only 3 orders', tk_hold(2100, 3, 3, 2300, 4, 3), false);
$eq('no hold: ticket fine', tk_hold(2400, 5, 6, 2300, 4, 3), false); $eq('no hold: nobody waiting', tk_hold(2100, 5, 2, 2300, 4, 3), false); $eq('no hold: switch value 0', tk_hold(2100, 5, 5, 0, 4, 3), false);
// the hold needs company: 30% of the people at the goal
$eq('share: 3 of 10 = 30%', tk_hold_share_ok(3, 10, 30), true); $eq('share: 2 of 10 is too few', tk_hold_share_ok(2, 10, 30), false); $eq('share: nobody working', tk_hold_share_ok(0, 0, 30), false); $eq('share: everybody low = no hold', tk_hold_share_ok(0, 12, 30), false);
// big-order customers and slow repliers
$eq('vip: avg 3000 >= 2700', tk_is_vip(2, 6000, 2700), true); $eq('vip: avg 1800 no', tk_is_vip(3, 5400, 2700), false); $eq('vip: no orders', tk_is_vip(0, 0, 2700), false); $eq('vip: goal off', tk_is_vip(2, 6000, 0), false);
$eq('slow: 2x', tk_slow(10, 5), true); $eq('slow: 1.5x no', tk_slow(7.5, 5), false); $eq('slow: unknown', tk_slow(null, 5), false);
echo $bad ? "tk test: $bad problem(s)\n" : "tk test: ok\n"; exit($bad ? 1 : 0);
