<?php
// Owner 21 Nov 2026: Module B leaves people Meta will never deliver to out in SQL (the 2,000-person look was 73% such people).
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
define('WA_REC_NEVER', [131026, 131050, 131047, 131021, 131051, 100, 131008, 131009, 130472, 131048]); define('WA_RU_MARKETING_ONLY', [131049, 131050, 130472]);
$nvr = implode(',', array_map('intval', array_diff(WA_REC_NEVER, WA_RU_MARKETING_ONLY))) ?: '0';
ok($nvr === '131026,131047,131021,131051,100,131008,131009,131048', 'never list without the marketing-only codes (got ' . $nvr . ')');
foreach ([131049, 131050, 130472] as $c) ok(strpos(',' . $nvr . ',', ',' . $c . ',') === false, $c . ' stays in the look (utility can still reach them)');
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php');
ok(strpos($src, "i.err_code <> 131049 AND i.err_code NOT IN (\$nvr)") !== false, 'the failed-people query leaves the never codes out');
ok(strpos($src, "\$nvr = implode(',', array_map('intval'") !== false, 'the never list is built before the query');
echo "ru never sql ok\n";
