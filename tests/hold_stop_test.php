<?php
// While the marketing hold (wa_dq_hold_until) runs, Module B returns before ramping or sending; campaign utility room is 0.
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php');
$a = strpos($src, 'function wa_ru_tick'); $h = strpos($src, "wa_dq_hold_until", $a); $r = strpos($src, 'wa_ru_ramp($pdo, $cfg);', $a);
if ($a === false || $h === false || $r === false || $h > $r) { echo "FAIL: Module B hold check missing or after the ramp/send\n"; exit(1); }
echo "hold stop ok\n";
