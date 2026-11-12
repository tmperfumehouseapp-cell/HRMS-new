<?php
// Guards against the two mistakes that already stopped sending: (1) code swallowed by a // comment on the same line, (2) the daily plan's key assignments missing from the executable code.
// Run: php tests/guard_test.php   (exit code 1 = something is wrong)
$root = dirname(__DIR__) . '/empmanagment'; $bad = 0;
// (2) wa_dq_left must really assign these (a comment must not have swallowed them)
$must = ['wa_dq.php' => ['effCap', 'rateUsed', 'hourLeft', 'pushCap', 'dayLeft', 'moneyLeft', 'moneyPush', 'memo', 'pace']];
foreach ($must as $file => $vars) {
    $toks = token_get_all(file_get_contents("$root/$file")); $assigned = [];
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        if (is_array($toks[$i]) && $toks[$i][0] === T_VARIABLE) { $j = $i + 1; while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++; if ($j < $n && $toks[$j] === '=') $assigned[ltrim($toks[$i][1], '$')] = true; }
    }
    foreach ($vars as $v) if (empty($assigned[$v])) { echo "FAIL $file: \$$v is never assigned in executable code (swallowed by a comment?)\n"; $bad++; }
}
// (1) a // comment that contains "; $var =" looks like code after the comment marker
foreach (glob("$root/wa_*.php") as $f) {
    foreach (token_get_all(file_get_contents($f)) as $t) {
        if (is_array($t) && $t[0] === T_COMMENT && strpos($t[1], '//') === 0 && preg_match('/\)\s*;\s*\$[A-Za-z_]+\s*=[^=]|\}\s*\$[A-Za-z_]+\s*=[^=]/', $t[1])) { echo "WARN " . basename($f) . ": a comment contains code: " . substr(trim($t[1]), 0, 110) . "\n"; $bad++; }
    }
}
echo $bad ? "guard test: $bad problem(s)\n" : "guard test: ok\n"; exit($bad ? 1 : 0);
