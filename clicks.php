<?php
/* TM Analytics — "Where people click": every button and product, page by page, in plain words.
   Open:  https://fourpirates.space/analytics/clicks.php   (same view password as report.php) */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

$CFG = require __DIR__ . '/config.php';
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function num($n): string { return number_format((float)$n); }
function rate100($a, $b): string { if ($b <= 0) return '–'; $v = $a / $b * 100; if ($a > 0 && $v < 0.1) return '<0.1'; return $v >= 10 ? (string)round($v) : number_format($v, 1); }
function nice(string $handle): string { return ucwords(str_replace(['-', '_'], ' ', $handle)); }

/* ── access (same password and cookie as report.php) ── */
$RK = (string)($CFG['viewkey'] ?? '');
if ($RK === '') { http_response_code(403); echo '<p style="font:16px sans-serif;padding:24px">Add a viewkey line to config.php first.</p>'; exit; }
$tok = hash('sha256', 'tmrep|' . $RK);
$k = (string)($_GET['k'] ?? '');
$kOk = $k !== '' && hash_equals($RK, $k);
$cOk = hash_equals($tok, (string)($_COOKIE['tm_rep'] ?? ''));
if (!$kOk && !$cOk) { http_response_code(403); echo '<p style="font:16px sans-serif;padding:24px">Open this page with your key:  clicks.php?k=YOUR_VIEW_KEY</p>'; exit; }
if ($kOk) {
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
  setcookie('tm_rep', $tok, ['expires' => time() + 86400 * 60, 'path' => $dir, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
  $keep = array_diff_key($_GET, ['k' => 1]);
  header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'clicks.php') . ($keep ? '?' . http_build_query($keep) : ''));
  exit;
}

$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $db = new PDO($CFG['dsn'], $CFG['dbuser'] ?? null, $CFG['dbpass'] ?? null, $o); }
catch (Throwable $e) { http_response_code(500); echo 'Database not reachable.'; exit; }
function q(PDO $db, string $sql, array $a = []): array { $s = $db->prepare($sql); $s->execute($a); return $s->fetchAll(); }

/* ── choices: store, how many days, which page ── */
$stores = $CFG['stores'];
$store = in_array($_GET['store'] ?? '', $stores, true) ? $_GET['store'] : $stores[0];
$today = date('Y-m-d');
$rng = in_array($_GET['r'] ?? '', ['1', '7', '30'], true) ? (int)$_GET['r'] : 7;
$from = date('Y-m-d', strtotime($today . ' -' . ($rng - 1) . ' day'));
$D = [$from, $today, $store];                       // used with: day BETWEEN ? AND ? AND store=?
$W = 'day BETWEEN ? AND ? AND store=?';

$pageNames = ['index' => 'Homepage', 'collection' => 'Collection page', 'product' => 'Product page', 'search' => 'Search', 'cart' => 'Cart', 'page' => 'Other pages', 'blog' => 'Blog', 'article' => 'Blog post', 'list-collections' => 'All collections'];
$have = array_column(q($db, "SELECT ptype FROM an_pagestats WHERE $W GROUP BY ptype", $D), 'ptype');
$tabs = [];
foreach (['index', 'collection', 'product'] as $t) $tabs[$t] = $pageNames[$t];
foreach ($have as $t) if (!isset($tabs[$t]) && $t !== 'other') $tabs[$t] = $pageNames[$t] ?? ucfirst($t);
$tabs['menu'] = 'Header & menu';
$tabs['popups'] = 'Popups';
$pg = (isset($_GET['pg']) && isset($tabs[$_GET['pg']])) ? $_GET['pg'] : 'collection';
$qs = function (array $x) use ($store, $rng, $pg): string { return '?' . http_build_query(array_merge(['store' => $store, 'r' => $rng, 'pg' => $pg], $x)); };

/* ── what a button is for ── */
$PURPOSE = [
  'WhatsApp'     => 'Starts a chat with you. This is your main way to get sales.',
  'Offer'        => 'Pushes people to buy now with a deal.',
  'Buy'          => 'Moves people toward the cart or checkout.',
  'Help choosing' => 'Helps people who are unsure which scent to pick.',
  'Search'       => 'Lets people find a scent fast. Very important with 450+ products.',
  'Video'        => 'Sends people to a video. Check that it brings buyers, not exits.',
  'Social'       => 'Sends people to your social pages. They leave the shop to go there.',
  'Social proof' => 'Builds trust (like units sold). Not meant to be a button.',
  'Filter'       => 'Helps people narrow down the products.',
  'Navigation'   => 'Moves people around the site (menus, links).',
  'Other'        => 'Other buttons and links.',
];
function splitKey(string $key): array {                       // [purpose|null, name, section]
  $purpose = null;
  if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $key, $m)) { $purpose = $m[1]; $key = $m[2]; }
  $sec = '';
  if (($p = strrpos($key, '|')) !== false) { $sec = substr($key, 0, $p); $key = substr($key, $p + 1); }
  $name = preg_replace('/^(a|button|summary|label|input):/i', '', $key);
  return [$purpose, $name === '' ? $key : $name, $sec];
}
function purposeOf(?string $given, string $name, int $wa = 0): string {
  if ($given) return $given;
  if ($wa > 0) return 'WhatsApp';
  $n = mb_strtolower($name);
  if (preg_match('/whatsapp|wa\.me|chat (with|on)/', $n)) return 'WhatsApp';
  if (preg_match('/add to cart|buy now|buy it|checkout|place order|\bcart\b/', $n)) return 'Buy';
  if (preg_match('/search/', $n)) return 'Search';
  if (preg_match('/youtube|video|watch|reel/', $n)) return 'Video';
  if (preg_match('/instagram|facebook|follow us/', $n)) return 'Social';
  if (preg_match('/find|scent|help|guide|expert|quiz|not sure|which one/', $n)) return 'Help choosing';
  if (preg_match('/offer|% ?off|\boff\b|free|combo|bundle|deal|\bsale\b|save|₹|navratri|garba|discount|gift|\bbox\b|\bpack\b/', $n)) return 'Offer';
  if (preg_match('/filter|sort|availability|price|brand|size|\btype\b/', $n)) return 'Filter';
  if (preg_match('/menu|search|account|log ?in|home|contact|about|policy|faq|track|blog|collections?\b/', $n)) return 'Navigation';
  return 'Other';
}
/* verdict from tap rate; $median = typical rate on this page */
function verdict(int $seen, int $taps, float $median, string $purpose): array {   // [label, color, sentence]
  if ($seen < 50) return ['Too early', 'n', 'Not enough views yet. Check again in a few days.'];
  $r = $taps / $seen;
  if ($purpose === 'Social proof') return ['Info badge · keep', 'k', 'Not a button. It builds trust. Keep it only if the number is real.'];
  if ($purpose === 'Video' || $purpose === 'Social') {
    if ($r >= 0.02) return ['Popular · open in new tab', 'g', 'Many people tap it, but each tap sends a shopper away. Open it in a new tab.'];
    if ($r >= 0.005) return ['Used a little', 'k', 'Fine to keep, but not high on the page.'];
    return ['Few taps · move to footer', 'o', 'Almost nobody uses it. Move it to the footer so shoppers stay in your shop.'];
  }
  if ($r >= max(0.02, 2 * $median)) return ['Very good · push up', 'g', 'Strong. Make it bigger or move it higher.'];
  if ($r >= max(0.005, 0.6 * $median)) return ['Working · keep', 'k', 'Doing its job. Keep it.'];
  if ($r >= 0.002 || $seen < 200) return ['Weak · make clearer', 'o', 'Seen but few people tap. Try clearer words or a better spot.'];
  if ($purpose === 'Navigation') return ['Rarely used', 'n', 'Menus are needed anyway. Leave it unless you want to simplify.'];
  return ['Not working · test removing', 'r', 'Seen a lot, almost nobody taps. Test hiding it for half of your visitors.'];
}
function verdictVisit(int $taps, int $pvs): array {
  if ($pvs < 200) return ['Too early', 'n', 'Not enough visits yet. Check again in a few days.'];
  $r = $taps / $pvs;
  if ($r >= 0.03) return ['Used a lot', 'g', 'Many people use this. Keep it easy to reach.'];
  if ($r >= 0.01) return ['Used · keep', 'k', 'A steady number of people use it.'];
  if ($r >= 0.002) return ['Rarely used', 'o', 'Few people use it. Consider moving it into a "More" group.'];
  return ['Almost never used', 'r', 'Nobody taps it. Fine to remove, but keep trust links like Contact and policies.'];
}
function isChrome(string $sec): bool { return $sec !== '' && preg_match('/header|announcement|menu|drawer|footer|nav(?!ratri)/i', $sec) === 1; }
function median(array $v): float { if (!$v) return 0.0; sort($v); $c = count($v); return $c % 2 ? $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2; }

/* page views for the selected tab */
$pv = 0; $pvs = 0;
if ($pg !== 'popups' && $pg !== 'menu') { $r = q($db, "SELECT COALESCE(SUM(pv),0) pv, COALESCE(SUM(pv_smp),0) smp FROM an_pagestats WHERE $W AND ptype=?", array_merge($D, [$pg]))[0]; $pv = (int)$r['pv']; $pvs = (int)$r['smp']; }
$rangeLabel = $rng === 1 ? 'today' : 'last ' . $rng . ' days';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Where people click</title>
<style>
:root{--bg:#fdf8f1;--card:#fff;--ink:#241c15;--mut:#8a8176;--line:#eadfce;--g:#1b7a3d;--o:#e8761c;--r:#d93636}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:22px 16px 60px}
h1{font-size:28px;margin:0}.sub{color:var(--mut);font-size:15px;margin:2px 0 12px}
.nav{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px;align-items:center}
.nav a{padding:6px 13px;border-radius:999px;border:1px solid var(--line);background:#fff;color:var(--ink);text-decoration:none;font-size:14px;white-space:nowrap}
.nav a.on{background:var(--g);border-color:var(--g);color:#fff}.nav .sp{flex:1}
.banner{background:#fff4e0;border:1px dashed #e7b66a;color:#7a4a00;border-radius:12px;padding:10px 14px;font-size:14px;margin:6px 0 14px}
h2{font-size:15px;letter-spacing:.07em;text-transform:uppercase;color:var(--g);margin:24px 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:6px 20px 12px;margin-bottom:14px;overflow-x:auto}
.grp{font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--mut);padding:14px 0 4px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.grp span:last-child{text-transform:none;font-weight:400;letter-spacing:0}
.row{display:grid;grid-template-columns:minmax(150px,1.4fr) 92px 205px minmax(150px,1.2fr);gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #f1e8da;font-size:15px}.row:last-child{border:0}
.row small{display:block;color:var(--mut);font-size:12.5px}
.n2{text-align:right}.say{font-size:14px;color:#5a5249}
.pill{display:inline-block;padding:3px 12px;border-radius:999px;font-size:12.5px;font-weight:700;color:#fff;white-space:nowrap}
.pill.g{background:var(--g)}.pill.k{background:#6aa77c}.pill.o{background:var(--o)}.pill.r{background:var(--r)}.pill.n{background:#a39b90}
.bar{height:16px;background:#efe8dc;border-radius:10px;overflow:hidden}.bar i{display:block;height:100%;background:var(--g)}
.sec{display:grid;grid-template-columns:minmax(120px,1fr) 2fr 56px;gap:12px;align-items:center;padding:7px 0;font-size:15px}
.prow{display:grid;grid-template-columns:34px minmax(140px,1.6fr) 76px 100px 88px 140px;gap:8px;align-items:center;padding:9px 0;border-bottom:1px solid #f1e8da;font-size:15px;min-width:640px}.prow:last-child{border:0}
.ph{color:var(--mut);font-size:12px;text-transform:uppercase;letter-spacing:.05em}.rank{font-weight:800;color:var(--g)}
.copy{background:#fff;border:2px solid var(--g);border-radius:14px;padding:12px 16px;font-size:14px;margin-bottom:14px}.copy b{color:var(--g)}
.copy button{float:right;background:var(--g);color:#fff;border:0;border-radius:999px;padding:6px 16px;font-size:13px;font-weight:700;cursor:pointer;margin-left:10px}
.copy pre{white-space:pre-wrap;margin:8px 0 0;font:13px/1.5 inherit;color:#5a5249;max-height:160px;overflow:auto}
.empty{color:var(--mut);font-size:15px;padding:12px 0}
.foot{margin-top:26px;color:var(--mut);font-size:14px}.foot a{color:var(--g)}
@media(max-width:680px){.row{grid-template-columns:1fr 1fr}.row .say{grid-column:1/-1}}
</style>
</head>
<body>
<div class="wrap">
  <h1>Where people click</h1>
  <div class="sub"><?= h($store) ?> · <?= h($rangeLabel) ?> · every button and product, page by page</div>

  <div class="nav">
    <?php foreach ($stores as $st): ?><a class="<?= $st === $store ? 'on' : '' ?>" href="<?= h($qs(['store' => $st])) ?>"><?= h($st) ?></a><?php endforeach; ?>
    <span class="sp"></span>
    <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days'] as $d => $lab): ?><a class="<?= $d === $rng ? 'on' : '' ?>" href="<?= h($qs(['r' => $d])) ?>"><?= h($lab) ?></a><?php endforeach; ?>
  </div>
  <div class="nav">
    <?php foreach ($tabs as $key => $lab): ?><a class="<?= $key === $pg ? 'on' : '' ?>" href="<?= h($qs(['pg' => $key])) ?>"><?= h($lab) ?></a><?php endforeach; ?>
  </div>

<?php if ($pg === 'menu'): /* ═════════ HEADER, MENU, SEARCH, FOOTER ═════════ */
  $tot = q($db, "SELECT COALESCE(SUM(pv),0) pv, COALESCE(SUM(pv_smp),0) smp FROM an_pagestats WHERE $W", $D)[0];
  $pvs = (int)$tot['smp'];
  $sess = (int)q($db, "SELECT COALESCE(SUM(sess),0) c FROM an_visitors WHERE $W", $D)[0]['c'];
  $all = q($db, "SELECT ekey, SUM(seen) seen, SUM(taps) taps, SUM(wa) wa FROM an_elements WHERE $W AND ctx='' AND ekey NOT LIKE '[Section]%' GROUP BY ekey", $D);
  $head = []; $foot = []; $search = [];
  foreach ($all as $r) {
    [$gp, $name, $sec] = splitKey($r['ekey']);
    $pp = purposeOf($gp, $name, (int)$r['wa']);
    $row = ['name' => $name, 'sec' => $sec, 'taps' => (int)$r['taps'], 'seen' => (int)$r['seen'], 'p' => $pp];
    if ($pp === 'Search') $search[] = $row;
    elseif (isChrome($sec)) { if (preg_match('/footer/i', $sec)) $foot[] = $row; else $head[] = $row; }
  }
  foreach ([&$head, &$foot, &$search] as &$L) usort($L, function ($a, $b) { return $b['taps'] <=> $a['taps']; }); unset($L);
  $searchTaps = array_sum(array_column($search, 'taps'));
  $searches = (int)q($db, "SELECT COALESCE(SUM(n),0) c FROM an_funnel WHERE $W AND step='search_submitted'", $D)[0]['c'];
  $terms = q($db, "SELECT q, SUM(searches) s, SUM(zero) z FROM an_search WHERE $W GROUP BY q HAVING SUM(searches)>0 ORDER BY SUM(searches) DESC LIMIT 10", $D);
  $zeroTotal = 0; foreach ($terms as $t) $zeroTotal += (int)$t['z'];
  $share = $sess > 0 ? $searches / $sess : 0;
  if ($sess < 100) { $sv = ['Too early', 'n', 'Not enough visits yet.']; }
  elseif ($share >= 0.08) { $sv = ['Very important · keep it big and easy to find', 'g', 'Many visitors search. With 450+ products this button earns its place.']; }
  elseif ($share >= 0.03) { $sv = ['Useful · keep', 'k', 'A steady group of visitors uses search.']; }
  else { $sv = ['Rarely used', 'o', 'Few visitors search. Do not spend extra space on it; check that it is easy to spot on mobile.']; } ?>
  <?php if ($pvs < 200): ?><div class="banner">Only <?= num($pvs) ?> measured visits in this period. Wait for a few hundred before acting on any label.</div><?php endif; ?>

  <h2>Search</h2>
  <div class="card">
    <div class="row"><span>People who searched<small>out of <?= num($sess) ?> visits</small></span><span class="n2"><b><?= rate100($searches, $sess) ?>%</b><small><?= num($searches) ?> searches</small></span><span><span class="pill <?= $sv[1] ?>"><?= h($sv[0]) ?></span></span><span class="say"><?= h($sv[2]) ?></span></div>
    <div class="row"><span>Taps on the search button<small>measured on about 30% of visits</small></span><span class="n2"><b><?= rate100($searchTaps, $pvs) ?>%</b><small><?= num($searchTaps) ?> taps</small></span><span></span><span class="say">Taps per 100 page visits.</span></div>
    <?php if ($terms): ?>
      <div class="grp"><span>What people search for</span><span><?= $zeroTotal > 0 ? num($zeroTotal) . ' searches found nothing. Add those products or a redirect.' : 'Every search found something.' ?></span></div>
      <?php foreach ($terms as $t): ?><div class="sec" style="grid-template-columns:1fr 90px 120px"><span><?= h($t['q']) ?></span><b class="n2"><?= num($t['s']) ?> times</b><span><?= (int)$t['z'] > 0 ? '<span class="pill r">' . num($t['z']) . ' found nothing</span>' : '' ?></span></div><?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php foreach ([['Header and menu bar', $head, 25], ['Footer', $foot, 12]] as [$ttl, $list, $cap]): ?>
    <h2><?= h($ttl) ?> · every link</h2>
    <div class="card">
      <?php if (!$list): ?><div class="empty">No taps recorded yet. Menu links are measured on about 30% of visits.</div><?php endif; ?>
      <?php foreach (array_slice($list, 0, $cap) as $b): [$vl, $vc, $vs] = verdictVisit($b['taps'], $pvs); ?>
        <div class="row"><span><?= h($b['name']) ?><small><?= h($b['sec']) ?></small></span><span class="n2"><b><?= rate100($b['taps'], $pvs) ?>%</b><small><?= num($b['taps']) ?> taps</small></span><span><span class="pill <?= $vc ?>"><?= h($vl) ?></span></span><span class="say"><?= h($vs) ?></span></div>
      <?php endforeach; ?>
      <?php if ($list): ?><div class="empty" style="padding-top:2px">Shown as taps per 100 page visits, because menu items are only visible after the menu opens.</div><?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($pg === 'popups'): /* ═════════ POPUPS ═════════ */
  $popNames = ['home' => 'Homepage popup', 'product' => 'Product popup', 'collection' => 'Collection popup', 'secondary' => 'Secondary popup', 'navratri' => 'Garba / Navratri bundle popup'];
  $rows = q($db, "SELECT ctx, ekey, SUM(seen) seen, SUM(taps) taps, SUM(wa) wa FROM an_elements WHERE $W AND ctx<>'' GROUP BY ctx, ekey", $D);
  $pops = [];
  foreach ($rows as $r) {
    $c = $r['ctx']; $pops[$c] = $pops[$c] ?? ['open' => 0, 'wa' => 0, 'close' => 0, 'rows' => []];
    $pops[$c]['wa'] += (int)$r['wa'];
    if (stripos($r['ekey'], 'close') !== false || strpos($r['ekey'], 'bg:') === 0) { $pops[$c]['close'] += (int)$r['taps']; continue; }
    if (strpos($r['ekey'], '#tmphCard') === 0) continue;
    if ($r['ekey'] !== 'card-tap') $pops[$c]['open'] = max($pops[$c]['open'], (int)$r['seen']);
    $pops[$c]['rows'][] = $r;
  }
  $any = false;
  foreach ($popNames as $c => $nm): if (empty($pops[$c])) continue; $any = true; $P = $pops[$c]; $open = $P['open'];
    $rs = [];
    foreach ($P['rows'] as $r) { [$gp, $name] = splitKey($r['ekey']); if ($r['ekey'] === 'card-tap') $name = 'Tap on the offer card'; $seen = $r['ekey'] === 'card-tap' ? $open : (int)$r['seen']; $rs[] = ['name' => $name, 'seen' => $seen, 'taps' => (int)$r['taps'], 'wa' => (int)$r['wa'], 'p' => purposeOf($gp, $name, (int)$r['wa'])]; }
    usort($rs, function ($a, $b) { return $b['taps'] <=> $a['taps']; });
    $med = median(array_map(function ($x) { return $x['seen'] >= 50 ? $x['taps'] / $x['seen'] : null; }, array_filter($rs, function ($x) { return $x['seen'] >= 50; }))); ?>
    <h2><?= h($nm) ?></h2>
    <div class="card">
      <div class="grp"><span><?= num($open) ?> people saw it · <?= num($P['wa']) ?> tapped WhatsApp (<?= rate100($P['wa'], $open) ?> in 100)</span><span><?= num($P['close']) ?> closed it without tapping a button</span></div>
      <?php foreach ($rs as $x): [$vl, $vc, $vs] = verdict($x['seen'], $x['taps'], $med, $x['p']); ?>
        <div class="row"><span><?= h($x['name']) ?><small><?= h($x['p']) ?> · seen by <?= num($x['seen']) ?></small></span><span class="n2"><b><?= rate100($x['taps'], $x['seen']) ?>%</b><small><?= num($x['taps']) ?> taps</small></span><span><span class="pill <?= $vc ?>"><?= h($vl) ?></span></span><span class="say"><?= h($vs) ?></span></div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; if (!$any): ?><div class="card"><div class="empty">No popup activity in this period yet.</div></div><?php endif; ?>

<?php else: /* ═════════ A PAGE ═════════ */
  $els = q($db, "SELECT ekey, SUM(seen) seen, SUM(taps) taps, SUM(wa) wa FROM an_elements WHERE $W AND ctx='' AND ptype=? GROUP BY ekey", array_merge($D, [$pg]));
  $buttons = []; $sections = [];
  foreach ($els as $r) {
    if (strpos($r['ekey'], '[Section] ') === 0) { $sections[] = ['name' => substr($r['ekey'], 10), 'seen' => (int)$r['seen']]; continue; }
    [$gp, $name, $sec] = splitKey($r['ekey']);
    $pp = purposeOf($gp, $name, (int)$r['wa']);
    if (isChrome($sec) || $pp === 'Search') continue;          // menu, header, footer and search are on the Header & menu tab
    $buttons[] = ['name' => $name, 'sec' => $sec, 'seen' => (int)$r['seen'], 'taps' => (int)$r['taps'], 'wa' => (int)$r['wa'], 'p' => $pp];
  }
  $rates = []; foreach ($buttons as $b) if ($b['seen'] >= 50) $rates[] = $b['taps'] / $b['seen'];
  $med = median($rates);
  $groups = [];
  foreach ($buttons as $b) $groups[$b['p']][] = $b;
  $order = ['WhatsApp', 'Offer', 'Buy', 'Help choosing', 'Video', 'Social', 'Social proof', 'Filter', 'Navigation', 'Other'];
  uksort($groups, function ($a, $b) use ($order) { return (int)array_search($a, $order) <=> (int)array_search($b, $order); });
?>
  <?php if ($pv < 100): ?><div class="banner">Only <?= num($pv) ?> visits on this page type in this period. Wait for a few hundred before acting on any label.</div><?php endif; ?>

  <h2><?= h($tabs[$pg]) ?> · every button</h2>
  <div class="card">
    <?php if (!$buttons): ?><div class="empty">No button data yet. Buttons are measured on about 30% of visits, so give it a day.</div><?php endif; ?>
    <?php foreach ($groups as $purpose => $list):
      usort($list, function ($a, $b) { return max($b['taps'], $b['wa']) <=> max($a['taps'], $a['wa']); }); ?>
      <div class="grp"><span><?= h($purpose) ?></span><span><?= h($PURPOSE[$purpose] ?? $PURPOSE['Other']) ?></span></div>
      <?php foreach (array_slice($list, 0, 14) as $b):
        [$vl, $vc, $vs] = verdict($b['seen'], $b['taps'], $med, $purpose);
        if ($purpose === 'WhatsApp' && $b['wa'] > 0 && $b['seen'] >= 50 && $vc !== 'g') { $vl = 'Working · keep'; $vc = 'k'; $vs = 'This brings WhatsApp chats. Keep it where it is.'; } ?>
        <div class="row">
          <span><?= h($b['name']) ?><small><?= $b['sec'] !== '' ? h($b['sec']) . ' · ' : '' ?>seen by <?= num($b['seen']) ?></small></span>
          <span class="n2"><?php if ($purpose === 'WhatsApp'): ?><b><?= num($b['wa']) ?></b><small>WhatsApp taps</small><?php else: ?><b><?= rate100($b['taps'], $b['seen']) ?>%</b><small><?= num($b['taps']) ?> taps</small><?php endif; ?></span>
          <span><span class="pill <?= $vc ?>"><?= h($vl) ?></span></span>
          <span class="say"><?= h($vs) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <?php if ($sections && $pvs > 0): usort($sections, function ($a, $b) { return $b['seen'] <=> $a['seen']; }); ?>
    <h2><?= h($tabs[$pg]) ?> · how far down people go</h2>
    <div class="card">
      <?php foreach (array_slice($sections, 0, 16) as $s): $pc = min(100, round($s['seen'] / $pvs * 100)); ?>
        <div class="sec"><span><?= h($s['name']) ?></span><div class="bar"><i style="width:<?= $pc ?>%;<?= $pc < 25 ? 'background:var(--r)' : ($pc < 55 ? 'background:var(--o)' : '') ?>"></i></div><b><?= $pc ?>%</b></div>
      <?php endforeach; ?>
      <div class="empty" style="padding-top:2px">Share of visitors who actually scrolled to each block. A great block that few people reach should move higher.</div>
    </div>
  <?php endif; ?>

  <?php if ($pg === 'product'):
    $px = q($db, "SELECT handle, step, SUM(n) n FROM an_pixprod WHERE $W AND step IN ('product_viewed','product_added_to_cart','wa_pdp') GROUP BY handle, step", $D);
    $P = [];
    foreach ($px as $r) { $key = ['product_viewed' => 'v', 'product_added_to_cart' => 'a', 'wa_pdp' => 'w'][$r['step']]; $P[$r['handle']][$key] = (int)$r['n']; }
    $list = [];
    foreach ($P as $hd => $t) { $v = $t['v'] ?? 0; if ($v < 15) continue; $a = $t['a'] ?? 0; $w = $t['w'] ?? 0; $list[] = ['h' => $hd, 'v' => $v, 'a' => $a, 'w' => $w, 'r' => ($a + $w) / $v]; }
    usort($list, function ($x, $y) { return $y['r'] <=> $x['r']; });
    $n = count($list); ?>
    <h2>Product pages · best to worst</h2>
    <div class="card">
      <?php if (!$list): ?><div class="empty">Not enough product page views yet (needs 15+ views per product).</div><?php else: ?>
      <div class="prow ph"><span>#</span><span>Product</span><span class="n2">Views</span><span class="n2">Add to cart</span><span class="n2">WhatsApp</span><span>Do this</span></div>
      <?php foreach (array_slice($list, 0, 40) as $i => $x):
        if ($x['a'] + $x['w'] === 0 && $x['v'] >= 50) { $vl = 'Pull down · nobody acts'; $vc = 'r'; }
        elseif ($i < max(1, (int)round($n * 0.2))) { $vl = 'Push up'; $vc = 'g'; }
        elseif ($i >= $n - max(1, (int)round($n * 0.2)) && $n >= 8) { $vl = 'Weak · check it'; $vc = 'o'; }
        else { $vl = 'Keep'; $vc = 'k'; } ?>
        <div class="prow"><span class="rank"><?= $i + 1 ?></span><span><?= h(nice($x['h'])) ?></span><span class="n2"><?= num($x['v']) ?></span><span class="n2"><?= num($x['a']) ?> · <?= rate100($x['a'], $x['v']) ?>%</span><span class="n2"><?= num($x['w']) ?> · <?= rate100($x['w'], $x['v']) ?>%</span><span><span class="pill <?= $vc ?>"><?= h($vl) ?></span></span></div>
      <?php endforeach; endif; ?>
    </div>
    <?php $ph = q($db, "SELECT idx, SUM(seen) seen, SUM(zoom) zoom FROM an_photos WHERE $W GROUP BY idx ORDER BY idx", $D);
    if ($ph): $first = max(1, (int)$ph[0]['seen']); ?>
      <h2>Product photos · how far people look</h2>
      <div class="card">
        <?php foreach ($ph as $r): $pc = min(100, round($r['seen'] / $first * 100)); ?>
          <div class="sec"><span>Photo <?= (int)$r['idx'] ?></span><div class="bar"><i style="width:<?= $pc ?>%;<?= $pc < 25 ? 'background:var(--r)' : ($pc < 55 ? 'background:var(--o)' : '') ?>"></i></div><b><?= $pc ?>%</b></div>
        <?php endforeach; ?>
        <div class="empty" style="padding-top:2px">Photos that almost nobody reaches can be dropped or replaced (for example with a video).</div>
      </div>
    <?php endif; ?>

  <?php else: /* list pages: products ranked by how often they are clicked */
    $pc = q($db, "SELECT handle, SUM(seen) seen, SUM(taps) taps, SUM(pos_sum) ps, SUM(pos_n) pn FROM an_products WHERE $W AND sec LIKE ? AND sec<>'_pdp' GROUP BY handle", array_merge($D, [$pg . ':%']));
    $wa = []; foreach (q($db, "SELECT handle, SUM(n) n FROM an_pixprod WHERE $W AND step='wa_card' GROUP BY handle", $D) as $r) $wa[$r['handle']] = (int)$r['n'];
    $minSeen = $rng === 1 ? 30 : 60;
    $rank = []; $few = [];
    foreach ($pc as $r) {
      $seen = (int)$r['seen']; $taps = (int)$r['taps'];
      $row = ['h' => $r['handle'], 's' => $seen, 't' => $taps, 'p' => (int)$r['pn'] > 0 ? (int)round($r['ps'] / $r['pn']) : 0, 'w' => $wa[$r['handle']] ?? 0, 'r' => $seen > 0 ? $taps / $seen : 0];
      if ($seen >= $minSeen) $rank[] = $row; else $few[] = $row;
    }
    usort($rank, function ($a, $b) { return $b['r'] <=> $a['r'] ?: $b['t'] <=> $a['t']; });
    $n = count($rank); $pm = median(array_column($rank, 'r')); ?>
    <h2><?= h($tabs[$pg]) ?> · products, most clicked first</h2>
    <div class="card">
      <?php if (!$rank): ?><div class="empty">No product has been seen by <?= $minSeen ?>+ people yet in this period.</div><?php else: ?>
      <div class="prow ph"><span>#</span><span>Product</span><span class="n2">Seen</span><span class="n2">Clicks</span><span class="n2">WhatsApp</span><span>Do this</span></div>
      <?php foreach (array_slice($rank, 0, 60) as $i => $x):
        if ($x['t'] === 0 && $x['s'] >= $minSeen * 3) { $vl = 'Never clicked'; $vc = 'r'; }
        elseif ($i < max(1, (int)round($n * 0.2))) { $vl = $i === 0 ? 'Move to top' : 'Move up'; $vc = 'g'; }
        elseif ($n >= 8 && $i >= $n - max(1, (int)round($n * 0.2)) && $x['r'] < 0.5 * $pm && $x['s'] >= $minSeen * 2) { $vl = 'Move down'; $vc = 'r'; }
        else { $vl = 'Keep'; $vc = 'k'; } ?>
        <div class="prow"><span class="rank"><?= $i + 1 ?></span><span><?= h(nice($x['h'])) ?><?= $x['p'] ? '<small style="color:#8a8176"> · now at position ' . $x['p'] . '</small>' : '' ?></span><span class="n2"><?= num($x['s']) ?></span><span class="n2"><?= num($x['t']) ?> · <?= rate100($x['t'], $x['s']) ?>%</span><span class="n2"><?= num($x['w']) ?></span><span><span class="pill <?= $vc ?>"><?= h($vl) ?></span></span></div>
      <?php endforeach; endif; ?>
    </div>
    <?php if ($rank):
      $names = array_map(function ($x) { return nice($x['h']); }, $rank);
      foreach ($few as $x) $names[] = nice($x['h']); ?>
      <div class="copy"><button type="button" id="cpy">Copy list</button><b>Suggested order for this page:</b> best first.
        <pre id="ord"><?= h(implode("\n", $names)) ?></pre>
        <div style="color:#8a8176;font-size:13px;margin-top:6px">In Shopify: Products → Collections → your collection → Sort: Manually, then drag products into this order. Products with too few views are placed at the end.</div></div>
      <script>document.getElementById('cpy').addEventListener('click',function(){var t=document.getElementById('ord').textContent,b=this;(navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){b.textContent='Copied'}).catch(function(){var r=document.createRange();r.selectNode(document.getElementById('ord'));getSelection().removeAllRanges();getSelection().addRange(r);b.textContent='Selected, press copy'});});</script>
    <?php endif; ?>
  <?php endif; ?>

  <?php $dead = q($db, "SELECT ekey, SUM(n) n FROM an_dead WHERE $W AND ptype=? GROUP BY ekey ORDER BY SUM(n) DESC LIMIT 8", array_merge($D, [$pg])); if ($dead): ?>
    <h2><?= h($tabs[$pg]) ?> · tapped but nothing happened</h2>
    <div class="card"><?php foreach ($dead as $d): ?><div class="sec" style="grid-template-columns:1fr 70px"><span><?= h(preg_replace('/^[^|]*\|/', '', $d['ekey'])) ?></span><b class="n2"><?= num($d['n']) ?></b></div><?php endforeach; ?>
      <div class="empty" style="padding-top:2px">People tapped these and nothing opened. Make them links, or stop them from looking tappable.</div></div>
  <?php endif; ?>
<?php endif; ?>

  <div class="foot"><a href="<?= h('report.php?store=' . urlencode($store)) ?>">← Simple report</a> · <a href="<?= h('details.php?store=' . urlencode($store)) ?>">Detailed numbers</a> · <a href="<?= h('export.php?store=' . urlencode($store)) ?>"><b>Export for Claude</b></a> · Buttons and product clicks are measured on about 30% of visits, WhatsApp taps on all of them.</div>
</div>
</body>
</html>