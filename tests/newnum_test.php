<?php
// owner 20 Nov 2026: a brand-new number (another portfolio / token) is never used before Meta shows it CONNECTED, and without a readable limit it starts at 250 a day (source guard of wa_number_caps).
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_more.php'); $a = strpos($src, 'function wa_number_caps'); $b = strpos($src, '// ----', $a); $f = substr($src, $a, $b - $a);
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
ok(strpos($f, "(string)(\$h['status'] ?? '') === '' && empty(\$h['stale'])") !== false, 'a number without a readable status gets cap 0');
ok(strpos($f, "!isset(\$first[\$id]) ? 250 : \$tier") !== false, 'a number that never sent and has no readable limit starts at 250');
ok(strpos($f, "if (\$h['status'] !== '' && \$h['status'] !== 'CONNECTED')") !== false, 'a not-connected number still gets cap 0');
echo "newnum ok\n";
