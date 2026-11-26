<?php
// 🖤 Black Friday week: the window, the season text and the message pack. Run: php tests/bf_test.php
require __DIR__ . '/../empmanagment/wa_style.php';
require __DIR__ . '/../empmanagment/wa_ordpack.php';
$fail = 0; $ok = function ($name, $cond) use (&$fail) { if (!$cond) { $fail++; echo "FAIL: $name\n"; } };
$ok('2026: Friday 27 Nov, week 23-30 Nov', wa_bf_window(strtotime('2026-11-26')) === ['2026-11-23', '2026-11-30', '2026-11-27']);
$ok('2027: Friday 26 Nov, week 22-29 Nov', wa_bf_window(strtotime('2027-06-01')) === ['2027-11-22', '2027-11-29', '2027-11-26']);
$ok('26 Nov 2026 is in the week', wa_bf_on(strtotime('2026-11-26 18:00:00')));
$ok('30 Nov 2026 (Cyber Monday) is in the week', wa_bf_on(strtotime('2026-11-30 23:00:00')));
$ok('22 Nov 2026 is not', !wa_bf_on(strtotime('2026-11-22 12:00:00')));
$ok('1 Dec 2026 is not', !wa_bf_on(strtotime('2026-12-01 00:30:00')));
$ok('season text names Black Friday in the week', stripos(wa_style_season(strtotime('2026-11-27')), 'BLACK FRIDAY') !== false);
$ok('season text after the week is winter / wedding again', stripos(wa_style_season(strtotime('2026-12-02')), 'black friday') === false && stripos(wa_style_season(strtotime('2026-12-02')), 'wedding') !== false);
$known = ['20ML', '50ML', '100ML', 'Yes, Order', 'Show Offer', 'BOOK MY COMBO', 'Show more combos', 'For me', 'As a gift', 'Men', 'Women', 'Unisex', 'Order Now', 'Send Details'];
$defs = wa_bf_defs(); $ok('10 messages', count($defs) === 10); $seen = [];
foreach ($defs as $name => $m) { $b = $m['body'];
    $ok("$name says Black Friday", stripos($b, 'black friday') !== false);
    $ok("$name has exactly one {{1}}, not first or last", substr_count($b, '{{1}}') === 1 && strpos($b, '{{1}}') > 0 && substr($b, -5) !== '{{1}}' && !preg_match('/\{\{[2-9]\}\}/', $b));
    $ok("$name has no price, percentage or date", !preg_match('/₹|\brs\.?\s*\d|\d\s*%|\d{3,}|11:59|aaj raat|sirf aaj|kal /iu', $b));
    $ok("$name length", mb_strlen($b) >= 80 && mb_strlen($b) <= 600);
    $ok("$name has blank-line spacing and an emoji", substr_count($b, "\n\n") >= 2 && preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $b));
    $ok("$name buttons are known ones", count($m['buttons']) >= 2 && count($m['buttons']) <= 3 && !array_diff($m['buttons'], $known));
    $ok("$name wording is not a copy", !isset($seen[$b])); $seen[$b] = 1; }
echo $fail ? "black friday: $fail FAILED\n" : "black friday: ok\n"; exit($fail ? 1 : 0);
