<?php
// The first-messages allowance of a new number (wa_ru_warm_tick): counted, connected-only, after every Module B stop, never more than 3 per number per round.
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'); $bad = 0;
$chk = function (string $what, bool $ok) use (&$bad) { echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n"; if (!$ok) $bad++; };
$f = strpos($src, 'function wa_ru_warm_tick'); $chk('function exists', $f !== false);
$body = substr($src, $f, strpos($src, '/** ROUTE:', $f) - $f);
$chk('allowance lives in the setting wa_ru_warm', strpos($body, "'wa_ru_warm'") !== false);
$chk('seeded once with 10 each for numbers 9 and 10', strpos($body, '{"9":10,"10":10}') !== false && strpos($body, 'wa_ru_warm_seed_2011') !== false);
$chk('at most 3 per number per round', strpos($body, '$round >= 3') !== false);
$chk('only a CONNECTED number sends', strpos($body, "!== 'CONNECTED'") !== false);
$chk('a retired number never sends', strpos($body, "!empty(\$n['retired'])") !== false);
$chk('only messages approved on that number\'s WhatsApp account', strpos($body, "'ok_wabas'") !== false && strpos($body, 'wa_waba_of($n)') !== false);
$chk('the count goes down and is saved', strpos($body, '$left--') !== false && strpos($body, "setting_set(\$pdo, 'wa_ru_warm'") !== false);
$chk('same customers rules: opt-out, wrote back, delivered today, 24 h', strpos($body, "optout") !== false && strpos($body, 'last_in_at') !== false && strpos($body, '$got[$ph]') !== false && strpos($body, '$recent[$ph]') !== false);
$chk('spam flag stops it and Module B', strpos($body, '131048') !== false && strpos($body, 'wa_ru_pause_until') !== false);
$t = strpos($src, 'function wa_ru_tick'); $call = strpos($src, 'wa_ru_warm_tick($pdo, $cfg, $nowTs)', $t);
$q = strpos($src, "'quiet hours'", $t); $h = strpos($src, 'wa_dq_hold_until', $t); $p = strpos($src, "'paused after a Meta warning'", $t);
$chk('called inside Module B\'s round', $call !== false);
$chk('only after the quiet-hours, marketing-hold and 4-hour-pause stops', $call > $q && $call > $h && $call > $p);
exit($bad ? 1 : 0);
