<?php
// Owner 23 Nov 2026: number 11 is no longer held to the 250 / 500 / 1,000 age steps; its own typed day limit (tier_manual) counts.
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$s = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_more.php');
ok(strpos($s, '$own11 = $id === 11 && ($man[$id] ?? 0) > 0;') !== false, 'number 11 own-limit rule present');
ok(strpos($s, '$ramp = !$own11 && (($cfg[\'ramp\'] && $ownT < 10000) || $young);') !== false, 'ramp skipped for number 11');
ok(strpos($s, '$man = []; try { foreach ($pdo->query("SELECT * FROM wa_numbers")') !== false, '$man defined before the loop');
ok(strpos($s, 'wa_ramp_cap($age)') !== false, 'other numbers keep the age steps');
echo "ok\n";
