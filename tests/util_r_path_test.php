<?php
// The "Start as utility" (variant R) path used to send with no hourly limit; it must now use the shared 700-an-hour utility room and note what it takes.
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php');
$a = strpos($src, "AND variant = 'R'\")->fetchColumn() : 0;"); $b = strpos($src, "wa_dq_why_text(\$dqF); break;", $a);
$seg = substr($src, $a, $b - $a);
if ($a === false || $b === false || strpos($seg, 'wa_cat_util_room') === false || strpos($seg, 'wa_cat_util_note') === false) { echo "FAIL: variant R path is not under the utility hourly room\n"; exit(1); }
echo "util R path ok\n";
