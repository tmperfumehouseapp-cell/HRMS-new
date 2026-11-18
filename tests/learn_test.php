<?php
// Utility follow-up "learning" helpers: the words only the moved messages use, and the safe fallback bank.
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_util.php');
foreach (['wa_util_learn_avoid', 'wa_util_learn_fallback'] as $fn) { $p = strpos($src, "function $fn("); if ($p === false) { echo "missing $fn\n"; exit(1); } $i = strpos($src, '{', $p); $d = 0; for ($j = $i; $j < strlen($src); $j++) { if ($src[$j] === '{') $d++; elseif ($src[$j] === '}') { $d--; if ($d === 0) { eval(substr($src, $p, $j - $p + 1)); break; } } } }
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$kept = ["Hi {{1}}, this is the TM Perfume House customer care team. If you need help with an order, a delivery or a perfume you bought from us, tap a button below."];
$moved = ["Hi {{1}}, your contact is saved with TM Perfume House, so we can help you faster with any order or question. Reply whenever you need us.", "Hello {{1}}, welcome back to TM Perfume House. Tap a button to reply."];
$av = wa_util_learn_avoid($kept, $moved);
ok(in_array('saved', $av, true) && in_array('welcome', $av, true) && in_array('whenever', $av, true), 'words only the moved messages use are found');
ok(!in_array('order', $av, true) && !in_array('delivery', $av, true), 'words of the kept messages are never avoided');
$fb = wa_util_learn_fallback('open', [], 0); ok($fb && strpos($fb[0], '{{1}}') !== false && count($fb[1]) === 2, 'fallback for the open kind');
$fb2 = wa_util_learn_fallback('open', [$fb[0]], 0); ok($fb2 && $fb2[0] !== $fb[0], 'a used wording is not used again');
ok(wa_util_learn_fallback('order', [], 0) !== null && wa_util_learn_fallback('ask', [], 0) === null, 'order has a fallback, ask has none');
echo "learn ok\n";
