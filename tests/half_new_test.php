<?php
// Owner 23 Nov 2026: small new numbers use only HALF of the limit that opens for them.
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$m = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_more.php'); $r = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php');
ok(strpos($m, 'if (($young || $own11) && $cap > 0) $cap = max(1, intdiv($cap, 2));') !== false, 'campaign cap of a young number halved');
ok(strpos($m, "intdiv(\$cap, 2)") < strpos($m, "if (\$h['quality'] === 'RED') { \$cap = 0;"), 'halved before the quality stop');
ok(strpos($r, '$dcap = $tr > 0 ? (int)floor($tr * 0.45) : 2500;') !== false, 'Module B day cap of a new number is 45% of its tier');
echo "ok\n";
