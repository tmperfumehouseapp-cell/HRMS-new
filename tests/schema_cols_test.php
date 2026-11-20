<?php
// owner 20 Nov 2026: 'Unknown column hold' broke the Campaigns page right after a deploy: a column added by a later deploy was named in a SELECT before the table had it.
// Rule: the columns of wa_numbers that are added by wa_more_tables() (camp_ok, handlers, camp_tag, retired, tier_manual, hold, waba_name, biz_name, portfolio) are NEVER named in a SELECT list — read them with SELECT * and `?? default`. (A probe `SELECT col … LIMIT 0` that decides whether to ALTER is the only exception.)
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$bad = [];
foreach (glob(dirname(__DIR__) . '/empmanagment/*.php') as $f) {
    foreach (file($f) as $i => $line) {
        if (strpos($line, 'LIMIT 0') !== false) continue;   // the probe
        if (strpos($line, 'try {') !== false && preg_match('/catch \\((PDOException|Throwable)/', $line)) continue;   // a read that is already inside its own try / catch on the same line cannot break a page
        if (preg_match_all('/SELECT\s+([^"\';]*?)\s+FROM\s+wa_numbers\b/i', $line, $m)) {
            foreach ($m[1] as $list) { if (trim($list) === '*') continue; if (preg_match('/\b(retired|tier_manual|hold|waba_name|biz_name|portfolio|handlers|camp_tag)\b/', $list)) $bad[] = basename($f) . ':' . ($i + 1) . ' → SELECT ' . trim($list); }
        }
    }
}
ok(!$bad, "a new wa_numbers column is named in a SELECT list (use SELECT * and ??): " . implode(' | ', $bad));
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_more.php'); $a = strpos($src, 'function wa_number_caps'); $g = <<<'X'
if (function_exists('wa_more_tables')) wa_more_tables($pdo);
X;
ok($a !== false && strpos(substr($src, $a, 400), $g) !== false, 'wa_number_caps makes the numbers\' columns before it reads them');
echo "schema_cols ok\n";
