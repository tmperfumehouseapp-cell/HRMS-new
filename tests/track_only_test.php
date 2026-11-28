<?php
// Offline check: a "where is my parcel" question is a tracking question (no complaint) only while every order on the way is moving normally. Run: php tests/track_only_test.php
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_orders.php');
if (!preg_match('/function wo_track_only\(array \$orders, int \$now\): bool\n\{.*?\n\}\n/s', $src, $m)) { echo "FAIL: wo_track_only not found\n"; exit(1); } eval($m[0]);
$now = time(); $d = fn($days) => date('Y-m-d H:i:s', $now - (int)($days * 86400)); $bad = 0;
$o = fn($s, $days = 1, $match = 'phone') => ['status' => $s, 'placed_at' => $d($days), 'match' => $match];
$cases = [
    ['shipped on time', [$o('shipped')], true], ['out for delivery', [$o('ofd')], true], ['packed 1 day ago', [$o('packed')], true],
    ['received 5 days ago = not dispatched', [$o('placed', 5)], false], ['delayed', [$o('delayed')], false], ['failed', [$o('failed')], false], ['rto', [$o('rto')], false],
    ['only delivered (says not received)', [$o('delivered')], false], ['no orders', [], false], ['order of another number', [$o('shipped', 1, 'linked')], false],
    ['old delivered + new shipped', [$o('delivered', 30), $o('shipped')], true], ['shipped + delayed', [$o('shipped'), $o('delayed')], false],
];
foreach ($cases as [$name, $orders, $want]) if (wo_track_only($orders, $now) !== $want) { echo "FAIL: $name\n"; $bad++; }
echo $bad ? "track only test: $bad problem(s)\n" : "track only test: ok\n"; exit($bad ? 1 : 0);
