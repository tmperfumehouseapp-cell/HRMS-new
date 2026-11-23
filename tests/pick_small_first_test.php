<?php
// Owner 23 Nov 2026: a new number on its slow start (small daily cap) that still has room is filled first - number 11 got almost nothing before.
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$camp = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php');
ok(preg_match('/function wa_pick_number\(PDO \$pdo, array \$c, array &\$ctx\): array\n\{.*?\n\}\n/s', $camp, $m) === 1, 'picker present');
ok(extension_loaded('pdo_sqlite'), 'sqlite') ;
eval($m[0]);
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_messages (phone TEXT, dir TEXT, mtype TEXT, status TEXT, ts TEXT, number_id INT)");
$mk = fn($id, $cap, $used) => ['id' => $id, 'q' => 'GREEN', 'cap' => $cap, 'used' => $used, 'left' => $cap - $used];
$ctx = ['port_left' => 1000, 'nums' => [2 => $mk(2, 1000000, 5000), 3 => $mk(3, 1000000, 100), 11 => $mk(11, 500, 356)], 'switch' => 2, 'sticky' => 0.05];
$c = ['phone' => '1', 'last_in_at' => null, 'last_number_id' => 0];
ok(wa_pick_number($pdo, $c, $ctx)[0] === 11, 'the small number with room is picked first');
$ctx['nums'][11] = $mk(11, 500, 500);
ok(wa_pick_number($pdo, $c, $ctx)[0] === 3, 'a full small number is skipped; the freest big number sends');
echo "ok\n";
