<?php
// $dqWorker must be defined BEFORE it is used for the pool: a helper campaign worker used to run with the marketing limits switched off (undefined variable), sending 1,000+ in one burst.
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php');
$def = strpos($src, '$dqWorker = $only &&'); $use = strpos($src, '$dq = ($only && !$dqWorker)');
if ($def === false || $use === false || $def > $use) { echo "FAIL: \$dqWorker is used before it is defined\n"; exit(1); }
echo "dq worker order ok\n";
