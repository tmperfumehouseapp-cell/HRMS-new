<?php
// Lineup (empmanagment/wa_line.php): the pure part — topic reading, due times, and the rules every message must keep for Meta (utility, no offer words, one blank, not starting with a blank, one short button).
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_line.php'); $a = strpos($src, '// ---- pure:begin'); $b = strpos($src, '// ---- pure:end'); eval(substr($src, $a, $b - $a));
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
date_default_timezone_set('Europe/Berlin');   // the server clock is not IST: the window must still follow IST
function ist($s) { return strtotime($s . ' +0530'); }
// ---- topics
ok(wl_classify(['bhai ye original hai kya?'], false) === 'trust', 'original -> trust');
ok(wl_classify(['COD milega?'], false) === 'trust', 'cod -> trust');
ok(wl_classify(['price kya hai'], false) === 'price', 'price');
ok(wl_classify(['kitna rate hai 50ml ka'], false) === 'price', 'price outranks size');
ok(wl_classify(['50ml'], false) === 'size', '"50ml" -> size');
ok(wl_classify(['address bhejta hu'], false) === 'pending', 'address -> pending');
ok(wl_classify(['આ અસલી છે?'], false) === 'trust', 'Gujarati trust');
ok(wl_classify(['કિંમત કેટલી છે'], false) === 'price', 'Gujarati price');
ok(wl_classify(['really nice'], false) === 'general', '"really" is not "real"');
ok(wl_classify(['facebook se aaya'], false) === 'general', '"facebook" is not "book"');
ok(wl_classify(['hi'], true) === 'reorder', 'nothing clear + bought before -> reorder');
ok(wl_classify([], false) === 'general', 'no words -> general');
ok(wl_classify(['Proof dekhna hai'], false) === 'trust', 'a button tap is read too');
// ---- due times (IST window 10-20)
$anchor = ist('2026-11-20 10:30:00');
ok(wl_due_ts($anchor, 1) === ist('2026-11-21 10:30:00'), 'same minute next day');
ok(wl_due_ts(ist('2026-11-20 03:10:00'), 1) === ist('2026-11-21 10:00:00'), 'night message waits for 10:00 IST');
ok(wl_due_ts(ist('2026-11-20 21:40:00'), 1) === ist('2026-11-22 10:00:00'), 'after 20:00 goes next morning');
ok(wl_due_ts(ist('2026-11-20 19:59:00'), 1) === ist('2026-11-21 19:59:00'), '19:59 stays');
ok(wl_yesterday_start(ist('2026-11-21 15:00:00')) === ist('2026-11-20 10:00:00'), 'yesterday 10:00 IST');
// ---- the messages
$P = wl_pack(); ok(count($P) === 27, '6 topics x 4 + 3 shared = 27 messages, got ' . count($P));
$seen = [];
foreach ($P as $k => $m) {
    ok(preg_match_all('/\{\{\d+\}\}/', $m['body']) === 1 && strpos($m['body'], '{{1}}') !== false, "$k: exactly one blank, the name");
    ok(strpos(trim($m['body']), '{{1}}') !== 0, "$k: must not start with the blank (Meta)"); ok(!preg_match('/\{\{1\}\}\s*$/', $m['body']), "$k: must not end with the blank");
    ok(mb_strlen($m['body']) <= 600, "$k: body length"); ok(mb_strlen($m['button']) <= 25, "$k: button max 25 characters (" . $m['button'] . ')');
    foreach (wl_banned() as $w) ok(stripos($m['body'] . ' ' . $m['button'], $w) === false, "$k: the template must not contain '$w' (Meta would call it marketing)");
    $n = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($m['button']))); ok(!isset($seen[$n]), "$k: button text '$n' is used twice"); $seen[$n] = $k;
    ok(trim($m['free']) !== '', "$k: has a free answer");
}
foreach ($seen as $n => $k) foreach ($seen as $n2 => $k2) if ($k !== $k2) ok(strpos(' ' . $n2 . ' ', ' ' . $n . ' ') === false, "button '$n' ($k) sits inside '$n2' ($k2): the card match would pick the wrong answer");
foreach (array_keys(wl_topics()) as $t) for ($s = 1; $s <= 7; $s++) ok(isset($P[wl_key($t, $s)]), "topic $t step $s has a message");
echo "lineup ok\n";
