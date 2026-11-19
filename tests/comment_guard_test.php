<?php
// A "// comment" in the middle of a line swallows everything after it. Twice a line of Auto assign code vanished that way (the ON state, then $avgT) and fresh queries were never given.
// This looks for a trailing comment that still contains code (an assignment) after it, in the Auto assign / ticket files.
$bad = 0; $files = ['wq_auto.php', 'wq_auto_rules.php', 'tk_lib.php', 'tk_rules.php', 'tk_goal.php'];
foreach ($files as $f) {
    $p = __DIR__ . '/../empmanagment/' . $f; if (!is_file($p)) continue; $n = 0;
    foreach (file($p) as $line) { $n++;
        if (preg_match('~[;{}]\s+//[^\n]*?\$\w+(\[[^\]]*\])?\s*=[^=>]~', $line) || preg_match('~[;{}]\s+//[^\n]*?\b(return|foreach|if)\s*\(~', $line)) { echo "comment swallows code: $f:$n\n"; $bad++; }
    }
}
echo $bad ? "comment guard: $bad problem(s)\n" : "comment guard: ok\n"; exit($bad ? 1 : 0);
