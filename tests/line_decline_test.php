<?php
// A "no" (Stop / Not now / No thanks ...) is not a reply (owner, 20 Nov): pulls wl_is_decline out of wa_line.php.
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_line.php'); $i = strpos($src, 'function wl_is_decline('); $j = strpos($src, "\n}\n", $i); eval(substr($src, $i, $j - $i + 3)); $bad = 0;
$eq = function ($n, $a, $b) use (&$bad) { if ($a !== $b) { $bad++; echo "FAIL $n\n"; } };
foreach (['Stop', 'stop.', 'Not now', 'not now!', 'No thanks', 'no thank you', 'abhi nahi', 'Nahi chahiye', 'unsubscribe', 'band karo'] as $b) $eq('decline: ' . $b, wl_is_decline($b), true);
foreach (['Yes, please', 'Konsi deal me lena hai', 'Not now, call me tomorrow', 'I need help', 'Stop calling me at night please', '', 'Order status'] as $b) $eq('a real reply: ' . $b, wl_is_decline($b), false);
echo $bad ? "line decline test: $bad problem(s)\n" : "line decline test: ok\n"; exit($bad ? 1 : 0);
