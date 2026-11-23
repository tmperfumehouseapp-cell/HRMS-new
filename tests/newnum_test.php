<?php
// owner 20 Nov 2026: a brand-new number (another portfolio / token) is never used before Meta shows it CONNECTED and its webhook connected; it starts on the slow start whatever the team setting says; the burst of Send fast / Module B never lifts its slow start (source guards of wa_number_caps and wa_camp_ctx).
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_more.php'); $a = strpos($src, 'function wa_number_caps'); $b = strpos($src, '// ----', $a); $f = substr($src, $a, $b - $a);
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$g = [];
$g['status'] = <<<'X'
(string)($h['status'] ?? '') === '' && empty($h['stale'])
X;
$g['conn'] = <<<'X'
if ($h['status'] !== '' && $h['status'] !== 'CONNECTED')
X;
$g['hook'] = <<<'X'
($h['subscribed'] ?? null) === false && !isset($first[$id])
X;
$g['young'] = <<<'X'
$young = $ownT < 10000 && $age < 42;
X;
$g['ramp'] = <<<'X'
$ramp = !$own11 && (($cfg['ramp'] && $ownT < 10000) || $young);
X;
ok(strpos($f, $g['status']) !== false, 'a number without a readable status gets cap 0');
ok(strpos($f, $g['conn']) !== false, 'a not-connected number still gets cap 0');
ok(strpos($f, $g['hook']) !== false, 'a new number without a connected webhook is not used');
ok(strpos($f, $g['young']) !== false && strpos($f, $g['ramp']) !== false, 'a young number with a small own limit always gets the slow start, whatever the team setting says');
ok(strpos($f, $g['young']) < strpos($f, $g['ramp']), 'the young check comes before the slow start is decided');
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
