<?php
// owner 20 Nov 2026: a brand-new number (another portfolio / token) is never used before Meta shows it CONNECTED and its webhook connected; without a readable limit it starts at 250 a day; the slow start follows the number's own limit (source guard of wa_number_caps).
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_more.php'); $a = strpos($src, 'function wa_number_caps'); $b = strpos($src, '// ----', $a); $f = substr($src, $a, $b - $a);
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$g1 = <<<'X'
(string)($h['status'] ?? '') === '' && empty($h['stale'])
X;
$g2 = <<<'X'
!isset($first[$id]) ? 250 : $tier
X;
$g3 = <<<'X'
if ($h['status'] !== '' && $h['status'] !== 'CONNECTED')
X;
$g4 = <<<'X'
$ramp = $cfg['ramp'] && (($perNum ? $ownT : $tier) < 10000);
X;
$g5 = <<<'X'
($h['subscribed'] ?? null) === false && !isset($first[$id])
X;
$g6 = <<<'X'
$ownT = $tier; if ($perNum)
X;
$g7 = <<<'X'
$ramp = $cfg
X;
ok(strpos($f, $g1) !== false, 'a number without a readable status gets cap 0');
ok(strpos($f, $g2) !== false, 'a number that never sent and has no readable limit starts at 250');
ok(strpos($f, $g3) !== false, 'a not-connected number still gets cap 0');
ok(strpos($f, $g4) !== false, 'the slow start follows the number\'s own limit in per-number mode');
ok(strpos($f, $g5) !== false, 'a new number without a connected webhook is not used');
ok(strpos($f, $g6) < strpos($f, $g7), 'the number\'s own limit is known before the slow start is decided');
$wc = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php');
$h1 = <<<'X'
$slowN = strpos((string)($c['why'] ?? ''), 'slow start') === 0;
X;
$h2 = <<<'X'
if ($burst > 0 && !$slowN) $c['left'] = max((int)$c['left'], $burst);
X;
$h3 = <<<'X'
if (($burst > 0 && !$slowN) ? (int)$c['cap'] <= 0 : $c['left'] <= 0) {
X;
ok(strpos($wc, $h1) !== false && strpos($wc, $h2) !== false && strpos($wc, $h3) !== false, 'the burst (Send fast / Module B) never lifts the slow start of a new number');
echo "newnum ok\n";
