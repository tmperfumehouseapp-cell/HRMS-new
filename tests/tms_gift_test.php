<?php
// Website order with the 🎁 gift perfume: in the total (ticket size), not in the ML (no commission). php tests/tms_gift_test.php
$pdo = new stdClass(); function json_response($a, $c = 200) {} $action = '';
require __DIR__ . '/../empmanagment/store_orders.php';
$bad = 0; $eq = function ($n, $a, $b) use (&$bad) { if ($a !== $b) { echo "FAIL $n: " . json_encode($a) . " != " . json_encode($b) . "\n"; $bad++; } };
$o = ['number' => 'TM10050', 'placed_at' => '2026-11-24 10:00:00', 'updated_at' => '2026-11-24 10:05:00', 'total' => 1447, 'amount_paid' => 1447, 'payment_status' => 'paid', 'items' => [
    ['title' => 'Oud Royale', 'variant_title' => '50 ml', 'quantity' => 1, 'price' => 599],
    ['title' => 'Ocean Breeze', 'variant_title' => '50 ml', 'quantity' => 1, 'price' => 599],
    ['title' => 'Cedar Trail', 'variant_title' => '50 ml', 'quantity' => 1, 'price' => 249, 'gift' => true]]];
$r = tms_row($o);
$eq('total has the gift', $r['total'], 1447.0); $eq('ml without the gift', $r['ml'], 100); $eq('pieces with the gift', $r['pieces'], 3);
$eq('gift marked in the names', str_contains($r['items'], 'Cedar Trail 50ml (gift)'), true); $eq('gift line flag', $r['lines'][2]['gift'], true);
unset($o['items'][2]['gift']); $eq('old shop (no flag): ml as before', tms_row($o)['ml'], 150);
echo $bad ? "tms gift test: $bad problem(s)\n" : "tms gift test: ok\n"; exit($bad ? 1 : 0);
