<?php
/* TM Analytics — SHOWER GEL report (plain words).  Open:  https://fourpirates.space/analytics/showergel.php
   Uses the same key / cookie as report.php, the same database and the same tracker. Nothing new to install.
   It looks at every product whose web address (handle) has "shower gel" in it, and (new) at every click on the shower gel pages
   (/pages/originals, /pages/shower_gel-perfume and any page on the originals template). Opening this page changes nothing. */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

$CFG = require __DIR__ . '/config.php';

/* ══ EDIT HERE ═════════════════════════════════════════════════════════════════════════════════════
   A product counts as shower gel when its handle matches this pattern. These two products are shower gels
   but their handle has no "shower gel" in it, so they are listed here. Add any new odd handle in $EXTRA. */
$SG_PATTERN = '/shower-?gell?/i';
$EXTRA = ['lady-milena-tmperfumehouse', 'rasa-ice-inspired-perfume-tmperfumehouse-copy'];
/* The shower gel WhatsApp number (last 10 digits), its custom page name (/pages/originals) and its Shopify product type */
$SG_PHONE = '7359790137';
$SG_PAGE  = 'originals';   /* (info only) every shower gel page is found automatically from the tracker */
$SG_TYPE  = 'showergel';
/* ══ END EDIT ═══════════════════════════════════════════════════════════════════════════════════════ */

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function num($n): string { return number_format((float)$n); }
function inr($n): string { return '₹' . number_format((float)$n); }
function p100(int $a, int $b): ?float { return $b > 0 ? $a / $b * 100 : null; }
function f1(?float $x): string { if ($x === null) return '–'; if ($x <= 0) return '0'; if ($x < 0.05) return '<0.1'; return number_format($x, 1); }
function nice(string $handle): string {
  $x = preg_replace('/-(shower-?gell?|perfume|copy|tmperfumehouse)\b/i', '', $handle);
  $x = preg_replace('/-\d+$/', '', (string)$x);
  return ucwords(str_replace(['-', '_'], ' ', trim((string)$x, '-')));
}

/* ── access: same key and cookie as report.php ── */
$RK = (string)($CFG['viewkey'] ?? '');
if ($RK === '') { http_response_code(403); echo '<p style="font:16px sans-serif;padding:24px">Add a viewkey line to config.php first.</p>'; exit; }
$tok = hash('sha256', 'tmrep|' . $RK);
$k = (string)($_GET['k'] ?? '');
$kOk = $k !== '' && hash_equals($RK, $k);
$cOk = hash_equals($tok, (string)($_COOKIE['tm_rep'] ?? ''));
if (!$kOk && !$cOk) { http_response_code(403); echo '<p style="font:16px sans-serif;padding:24px">Open report.php?k=YOUR_VIEW_KEY once, then come back to this page.</p>'; exit; }
if ($kOk) {
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
  setcookie('tm_rep', $tok, ['expires' => time() + 86400 * 60, 'path' => $dir, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
  $keep = array_diff_key($_GET, ['k' => 1]);
  header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'showergel.php') . ($keep ? '?' . http_build_query($keep) : ''));
  exit;
}

$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $db = new PDO($CFG['dsn'], $CFG['dbuser'] ?? null, $CFG['dbpass'] ?? null, $o); }
catch (Throwable $e) { http_response_code(500); echo 'Database not reachable.'; exit; }
function q(PDO $db, string $sql, array $a = []): array { $s = $db->prepare($sql); $s->execute($a); return $s->fetchAll(); }
function qs_safe(PDO $db, string $sql, array $a = []): array { try { return q($db, $sql, $a); } catch (Throwable $e) { return []; } }

$stores = $CFG['stores'];
$store = in_array($_GET['store'] ?? '', $stores, true) ? (string)$_GET['store'] : $stores[0];
$days = (int)($_GET['days'] ?? 7); if (!in_array($days, [1, 7, 14, 30], true)) $days = 7;
$sort = in_array($_GET['sort'] ?? '', ['sold', 'clicks'], true) ? (string)$_GET['sort'] : 'sold';
$today = date('Y-m-d');
$dates = [];
for ($i = $days - 1; $i >= 0; $i--) $dates[] = date('Y-m-d', strtotime("-$i day"));
$from = $dates[0]; $to = $today;
$qs = function (array $x) use ($store, $days, $sort): string { return '?' . http_build_query(array_merge(['store' => $store, 'days' => $days, 'sort' => $sort], $x)); };
$isSG = function (string $handle) use ($SG_PATTERN, $EXTRA): bool { return in_array($handle, $EXTRA, true) || preg_match($SG_PATTERN, $handle) === 1; };

/* ══ NUMBERS ══ */
$P = [];   // per product
$mk = function (string $hd): array { return ['h' => $hd, 'seen' => 0, 'taps' => 0, 'pdp' => 0, 'atc' => 0, 'wa' => 0, 'sold' => 0, 'rev' => 0.0, 'pixatc' => 0, 'trkatc' => 0]; };
$D = array_fill_keys($dates, ['pdp' => 0, 'atc' => 0, 'wa' => 0]);

foreach (qs_safe($db, "SELECT handle, SUM(seen) s, SUM(taps) t, SUM(pdp) p, SUM(atc) a FROM an_products WHERE store=? AND day BETWEEN ? AND ? GROUP BY handle", [$store, $from, $to]) as $r) {
  $hd = (string)$r['handle']; if (!$isSG($hd)) continue;
  $P[$hd] = $P[$hd] ?? $mk($hd);
  $P[$hd]['seen'] += (int)$r['s']; $P[$hd]['taps'] += (int)$r['t']; $P[$hd]['pdp'] += (int)$r['p']; $P[$hd]['atc'] += (int)$r['a'];
}
foreach (qs_safe($db, "SELECT handle, step, SUM(n) n, SUM(qty) q, SUM(value_sum) v FROM an_pixprod WHERE store=? AND day BETWEEN ? AND ? AND step IN ('wa_card','wa_pdp','checkout_completed','product_added_to_cart') GROUP BY handle, step", [$store, $from, $to]) as $r) {
  $hd = (string)$r['handle']; if (!$isSG($hd)) continue;
  $P[$hd] = $P[$hd] ?? $mk($hd);
  $st = (string)$r['step'];
  if ($st === 'wa_card' || $st === 'wa_pdp') $P[$hd]['wa'] += (int)$r['n'];
  elseif ($st === 'checkout_completed') { $P[$hd]['sold'] += max((int)$r['q'], (int)$r['n']); $P[$hd]['rev'] += (float)$r['v']; }
  else $P[$hd]['pixatc'] += (int)$r['n'];   // number of add-to-cart events, not bottles
}
/* add to cart: the page tracker and Shopify's own cart event both count it; use the higher one so a miss on one side does not show as zero */
foreach ($P as &$p) { $p['trkatc'] = $p['atc']; $p['atc'] = max($p['atc'], $p['pixatc']); $p['clicks'] = $p['pdp'] + $p['atc'] + $p['wa']; } unset($p);

/* per day (product page visits + add to cart + WhatsApp) */
foreach (qs_safe($db, "SELECT day, handle, SUM(pdp) p, SUM(atc) a FROM an_products WHERE store=? AND day BETWEEN ? AND ? GROUP BY day, handle", [$store, $from, $to]) as $r) {
  if (!$isSG((string)$r['handle']) || !isset($D[(string)$r['day']])) continue;
  $D[(string)$r['day']]['pdp'] += (int)$r['p']; $D[(string)$r['day']]['atc'] += (int)$r['a'];
}
foreach (qs_safe($db, "SELECT day, handle, SUM(n) n FROM an_pixprod WHERE store=? AND day BETWEEN ? AND ? AND step IN ('wa_card','wa_pdp') GROUP BY day, handle", [$store, $from, $to]) as $r) {
  if (!$isSG((string)$r['handle']) || !isset($D[(string)$r['day']])) continue;
  $D[(string)$r['day']]['wa'] += (int)$r['n'];
}

/* where on the page the cards were tapped */
$SEC = [];
foreach (qs_safe($db, "SELECT handle, sec, SUM(seen) s, SUM(taps) t FROM an_products WHERE store=? AND day BETWEEN ? AND ? AND sec<>'_pdp' AND sec<>'' GROUP BY handle, sec", [$store, $from, $to]) as $r) {
  if (!$isSG((string)$r['handle'])) continue;
  $sc = (string)$r['sec']; $SEC[$sc] = $SEC[$sc] ?? ['seen' => 0, 'taps' => 0];
  $SEC[$sc]['seen'] += (int)$r['s']; $SEC[$sc]['taps'] += (int)$r['t'];
}
uasort($SEC, function ($a, $b) { return $b['taps'] <=> $a['taps']; });

/* Counted by the tracker's named events (needs the updated tm-analytics.liquid): "WA <number> <place>", "PV <place>", "ATC <place>",
   and for the shower gel pages: "SGC <page>" = every tap on that page, "SGP <product>" = taps on a product from that page */
$EV = ['wa_sg' => 0, 'wa_other' => 0, 'pv_page' => 0, 'pv_prod' => 0, 'since' => '', 'sgc' => 0, 'sgc_since' => ''];
$EVW = []; $EVD = []; $COL = []; $PGS = []; $SGP = []; $evRows = [];
foreach (qs_safe($db, "SELECT day, name, SUM(n) n FROM an_events WHERE store=? AND day BETWEEN ? AND ? AND (name LIKE 'WA %' OR name LIKE 'PV %' OR name LIKE 'ATC %' OR name LIKE 'SGC %' OR name LIKE 'SGP %') GROUP BY day, name", [$store, $from, $to]) as $r) $evRows[] = $r;
/* pass 1: the shower gel pages are whichever pages sent a "PV page ..." or "SGC ..." count (the tracker only sends those on shower gel pages) */
foreach ($evRows as $r) {
  $nm = (string)$r['name']; $n = (int)$r['n'];
  if (preg_match('/^PV page (.+)$/', $nm, $m)) { $PGS[$m[1]] = $PGS[$m[1]] ?? ['pv' => 0, 'clicks' => 0, 'atc' => 0, 'wa' => 0]; $PGS[$m[1]]['pv'] += $n; $EV['pv_page'] += $n; }
  elseif (preg_match('/^SGC (.+)$/', $nm, $m)) { $PGS[$m[1]] = $PGS[$m[1]] ?? ['pv' => 0, 'clicks' => 0, 'atc' => 0, 'wa' => 0]; $PGS[$m[1]]['clicks'] += $n; $EV['sgc'] += $n; }
}
/* pass 2 */
foreach ($evRows as $r) {
  $nm = (string)$r['name']; $n = (int)$r['n']; $dy = (string)$r['day'];
  if (preg_match('/^WA (\d{10}|unknown) (.+)$/', $nm, $m)) {
    if ($m[1] === $SG_PHONE) { $EV['wa_sg'] += $n; $EVW[$m[2]] = ($EVW[$m[2]] ?? 0) + $n; $EVD[$dy] = ($EVD[$dy] ?? 0) + $n; }
    elseif (preg_match('/^page (.+)$/', $m[2], $pm) && isset($PGS[$pm[1]])) $EV['wa_other'] += $n;
    if (preg_match('/^page (.+)$/', $m[2], $pm) && isset($PGS[$pm[1]])) $PGS[$pm[1]]['wa'] += $n;
  } elseif (preg_match('/^ATC page (.+)$/', $nm, $m)) { if (isset($PGS[$m[1]])) $PGS[$m[1]]['atc'] += $n; }
  elseif ($nm === 'PV product ' . $SG_TYPE) $EV['pv_prod'] += $n;
  elseif (preg_match('/^SGP (.+)$/', $nm, $m)) $SGP[$m[1]] = ($SGP[$m[1]] ?? 0) + $n;
  elseif (preg_match('/^(PV|ATC) collection (.+)$/', $nm, $m) && stripos($m[2], 'shower') !== false) {
    $COL[$m[2]] = $COL[$m[2]] ?? ['pv' => 0, 'atc' => 0];
    $COL[$m[2]][$m[1] === 'PV' ? 'pv' : 'atc'] += $n;
  }
}
arsort($EVW); uasort($COL, function ($a, $b) { return $b['pv'] <=> $a['pv']; });
uasort($PGS, function ($a, $b) { return [$b['clicks'], $b['pv']] <=> [$a['clicks'], $a['pv']]; });
arsort($SGP);
/* a product handle from the tracker is cut at 36 letters; use the full handle when we know it */
$SGPF = [];
foreach ($SGP as $hd => $n) {
  $full = $hd;
  foreach (array_keys($P) as $ph) { if (strpos($ph, $hd) === 0) { $full = $ph; break; } }
  $SGPF[$full] = ($SGPF[$full] ?? 0) + $n;
}
arsort($SGPF);
foreach (qs_safe($db, "SELECT MIN(day) d FROM an_events WHERE store=? AND (name LIKE 'WA %' OR name LIKE 'PV %')", [$store]) as $r) $EV['since'] = (string)($r['d'] ?? '');
foreach (qs_safe($db, "SELECT MIN(day) d FROM an_events WHERE store=? AND name LIKE 'SGC %'", [$store]) as $r) $EV['sgc_since'] = (string)($r['d'] ?? '');

/* totals */
$T = ['seen' => 0, 'taps' => 0, 'pdp' => 0, 'atc' => 0, 'wa' => 0, 'sold' => 0, 'rev' => 0.0, 'clicks' => 0, 'pixatc' => 0, 'trkatc' => 0];
foreach ($P as $p) foreach ($T as $kk => $_) $T[$kk] += $p[$kk];
$hasSold = $T['sold'] > 0;

$rows = array_values($P);
$byClicks = $rows; usort($byClicks, function ($a, $b) { return [$b['pdp'], $b['atc'], $b['wa']] <=> [$a['pdp'], $a['atc'], $a['wa']]; });
$bySold = $rows; usort($bySold, function ($a, $b) { return [$b['sold'], $b['atc'], $b['clicks']] <=> [$a['sold'], $a['atc'], $a['clicks']]; });
$main = ($sort === 'clicks' || !$hasSold) ? $byClicks : $bySold;
$main = array_slice(array_values(array_filter($main, function ($p) { return $p['clicks'] > 0 || $p['seen'] > 0 || $p['sold'] > 0; })), 0, 30);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Shower Gel Report</title>
<style>
:root{--bg:#fdf8f1;--card:#fff;--ink:#241c15;--mut:#8a8176;--line:#eadfce;--g:#1b7a3d;--o:#e8761c;--r:#d93636}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:22px 16px 60px}
h1{font-size:30px;margin:0}.sub{color:var(--mut);font-size:15px;margin:2px 0 12px}
.nav{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px;align-items:center}
.nav a{padding:6px 13px;border-radius:999px;border:1px solid var(--line);background:#fff;color:var(--ink);text-decoration:none;font-size:14px}
.nav a.on{background:var(--g);border-color:var(--g);color:#fff}.nav .sp{flex:1}
h2{font-size:16px;letter-spacing:.06em;text-transform:uppercase;color:var(--g);margin:30px 0 6px}
.hint{color:var(--mut);font-size:14px;margin:0 0 10px}
.big{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:12px;margin-bottom:8px}
.k{background:#fff;border:1px solid var(--line);border-radius:18px;padding:14px 18px}.k small{display:block;color:var(--mut);font-size:14px}.k b{font-size:34px;line-height:1.15;display:block}.k i{font-style:normal;font-size:13px;color:var(--mut)}
.k.hero{background:var(--g);color:#fff;border-color:var(--g)}.k.hero small,.k.hero i{color:#fff;opacity:.92}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:6px 14px}
.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:680px}
th{font-size:12px;color:var(--mut);font-weight:600;text-align:right;padding:8px 6px;border-bottom:1px solid var(--line);white-space:nowrap}
th:first-child,td:first-child{text-align:left}
td{padding:9px 6px;border-bottom:1px solid #f4ece0;text-align:right;font-size:15px;white-space:nowrap}
td:first-child{white-space:normal;min-width:170px}tr:last-child td{border:0}
td small{display:block;color:var(--mut);font-size:11px;font-weight:400}
td.n{font-weight:700}.rk{display:inline-block;width:24px;color:var(--mut);font-weight:700}
.bar{display:inline-block;height:10px;border-radius:6px;background:var(--g);vertical-align:middle;min-width:2px}
.empty{color:var(--mut);padding:14px 4px}
.banner{background:#fff4e0;border:1px dashed #e7b66a;color:#7a4a00;border-radius:12px;padding:10px 14px;font-size:14px;margin:0 0 12px}
.foot{margin-top:30px;color:var(--mut);font-size:14px}.foot a{color:var(--g)}
</style>
</head>
<body>
<div class="wrap">
  <h1>Shower gel report</h1>
  <div class="sub"><?= h($store) ?> · <?= h($days === 1 ? 'today so far' : 'last ' . $days . ' days') ?> · updated <?= h(date('g:i A')) ?> IST · <a href="report.php?store=<?= h(urlencode($store)) ?>&days=<?= (int)$days ?>" style="color:var(--g)">← Store report</a></div>

  <div class="nav">
    <?php foreach ($stores as $s2): ?><a class="<?= $s2 === $store ? 'on' : '' ?>" href="<?= h($qs(['store' => $s2])) ?>"><?= h($s2) ?></a><?php endforeach; ?>
    <span class="sp"></span>
    <?php foreach ([1, 7, 14, 30] as $dn): ?><a class="<?= $dn === $days ? 'on' : '' ?>" href="<?= h($qs(['days' => $dn])) ?>"><?= $dn === 1 ? 'Today' : $dn . ' days' ?></a><?php endforeach; ?>
  </div>

  <?php if (!$P): ?><div class="banner">No shower gel visits recorded in this period yet. Try “30 days”, or check that the tracker is on the shower gel product pages.</div><?php endif; ?>

  <?php if ($EV['since'] === ''): ?><div class="banner"><b>Shower gel WhatsApp and page counts have not started yet.</b> Paste the updated <code>tm-analytics.liquid</code> into the theme. From that moment every tap on a link to +91 <?= h(substr($SG_PHONE, 0, 5) . ' ' . substr($SG_PHONE, 5)) ?> is counted as a shower gel tap. Older days cannot be recovered.</div>
  <?php elseif ($EV['since'] > $from): ?><div class="banner">Shower gel WhatsApp and page counts start on <b><?= h(date('j M', strtotime($EV['since']))) ?></b>. Days before that show 0 only because they were not tracked.</div><?php endif; ?>

  <?php if ($EV['sgc_since'] === ''): ?><div class="banner"><b>Click counts for the shower gel pages have not started yet.</b> Paste the updated <code>tm-analytics-sg-1.liquid</code> into the theme (snippet tm-analytics). From that moment every tap on /pages/originals and /pages/shower_gel-perfume is counted. Older days cannot be recovered.</div>
  <?php elseif ($EV['sgc_since'] > $from): ?><div class="banner">Shower gel page click counts start on <b><?= h(date('j M', strtotime($EV['sgc_since']))) ?></b>. Days before that show 0 only because they were not tracked.</div><?php endif; ?>

  <div class="big">
    <div class="k hero"><small>Total clicks on shower gel pages</small><b><?= num($EV['sgc']) ?></b><i>every tap on a shower gel page</i></div>
    <div class="k hero"><small>WhatsApp taps, shower gel number</small><b><?= num($EV['wa_sg']) ?></b><i>taps on any link to +91 <?= h(substr($SG_PHONE, 0, 5) . ' ' . substr($SG_PHONE, 5)) ?></i></div>
    <div class="k"><small>Shower gel pages opened</small><b><?= num($EV['pv_page']) ?></b><i><?= $PGS ? h('/pages/' . implode(', /pages/', array_keys($PGS))) : '/pages/' . h($SG_PAGE) ?></i></div>
    <div class="k"><small>Product pages opened</small><b><?= num($T['pdp']) ?></b><i>people who clicked into a shower gel</i></div>
    <div class="k"><small>Add to cart</small><b><?= num($T['atc']) ?></b><i>page tracker <?= num($T['trkatc']) ?> · Shopify cart event <?= num($T['pixatc']) ?></i></div>
    <div class="k"><small>WhatsApp on product cards</small><b><?= num($T['wa']) ?></b><i>any number, on shower gel cards and product pages</i></div>
    <div class="k"><small>Sold (bottles)</small><b><?= $hasSold ? num($T['sold']) : '–' ?></b><i><?= $hasSold ? h(inr($T['rev'])) . ' from checkout tracking' : 'no checkout data in this period' ?></i></div>
  </div>

  <!-- ═════════ SHOWER GEL PAGES ═════════ -->
  <?php if ($PGS): ?>
  <h2>Shower gel pages</h2>
  <p class="hint">Each shower gel page on its own: how many times it was opened, how many taps happened on it, and what people did.</p>
  <div class="card scroll">
    <table style="min-width:560px">
      <tr><th>Page</th><th>Opened</th><th>Total clicks</th><th>Clicks per 100 opens</th><th>Add to cart</th><th>WhatsApp</th></tr>
      <?php foreach ($PGS as $pn => $x): ?>
      <tr><td>/pages/<?= h($pn) ?></td><td><?= num($x['pv']) ?></td><td class="n"><?= num($x['clicks']) ?></td><td><?= f1(p100($x['clicks'], $x['pv'])) ?></td><td><?= num($x['atc']) ?></td><td><?= num($x['wa']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <h2>Top products clicked on the shower gel pages</h2>
  <p class="hint">Taps on a product link while on a shower gel page. Counted on every visit (not a sample).</p>
  <div class="card scroll">
    <?php if ($SGPF): ?>
    <table style="min-width:420px">
      <tr><th>Product</th><th>Clicks</th><th>Share of page clicks</th></tr>
      <?php $ri = 0; foreach (array_slice($SGPF, 0, 30, true) as $hd => $n): $ri++; ?>
      <tr><td><span class="rk"><?= $ri ?></span><?= h(nice($hd)) ?><small><?= h($hd) ?></small></td><td class="n"><?= num($n) ?></td><td><?= f1(p100($n, $EV['sgc'])) ?>%</td></tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?><div class="empty">No product clicks recorded on the shower gel pages in this period yet.</div><?php endif; ?>
  </div>

  <!-- ═════════ TOP PRODUCTS ═════════ -->
  <h2><?= ($sort === 'clicks' || !$hasSold) ? 'Most opened shower gels' : 'Top selling shower gels' ?></h2>
  <div class="nav" style="margin-top:0">
    <a class="<?= ($sort === 'sold' && $hasSold) ? 'on' : '' ?>" href="<?= h($qs(['sort' => 'sold'])) ?>">Top selling</a>
    <a class="<?= ($sort === 'clicks' || !$hasSold) ? 'on' : '' ?>" href="<?= h($qs(['sort' => 'clicks'])) ?>">Most opened</a>
  </div>
  <?php if (!$hasSold): ?><p class="hint">No finished orders came through the checkout tracking in this period, so the list is sorted by page opens. (Checkout runs on GoKwik, so sold numbers can be missing or lower than your real orders. For exact sales use Shopify orders.)</p>
  <?php else: ?><p class="hint">Sold = bottles in finished orders seen by the tracker. It can be lower than your real orders (GoKwik checkout).</p><?php endif; ?>
  <div class="card scroll">
    <?php if ($main): ?>
    <table>
      <tr><th>Product</th><th>Sold</th><th>Page opens</th><th>Add to cart</th><th>WhatsApp</th><th>Card seen*</th><th>Card taps*</th></tr>
      <?php foreach ($main as $i => $p): ?>
      <tr>
        <td><span class="rk"><?= $i + 1 ?></span><?= h(nice($p['h'])) ?><small><?= h($p['h']) ?></small></td>
        <td class="n"><?= $p['sold'] > 0 ? num($p['sold']) : '–' ?><?php if ($p['rev'] > 0): ?><small><?= h(inr($p['rev'])) ?></small><?php endif; ?></td>
        <td><?= num($p['pdp']) ?></td>
        <td><?= num($p['atc']) ?></td>
        <td><?= num($p['wa']) ?></td>
        <td><?= num($p['seen']) ?></td>
        <td><?= num($p['taps']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?><div class="empty">Nothing recorded yet.</div><?php endif; ?>
  </div>
  <p class="hint" style="margin-top:6px">* Card seen / card taps come from about 30% of visits (a sample), so the counts are low but the pattern is fair. Page opens, add to cart and WhatsApp are counted on every visit.</p>

  <!-- ═════════ WHATSAPP, SHOWER GEL NUMBER ═════════ -->
  <h2>WhatsApp taps on the shower gel number</h2>
  <p class="hint">Every tap on a link to the shower gel number, by where it happened (the shower gel page, a popup, a product page, a collection). Other numbers are not counted here.<?php if ($EV['wa_other'] > 0): ?> The shower gel page also had <?= num($EV['wa_other']) ?> taps on the main number.<?php endif; ?></p>
  <div class="card scroll">
    <?php if ($EVW): ?>
    <table style="min-width:420px">
      <tr><th>Where</th><th>WhatsApp taps</th></tr>
      <?php foreach ($EVW as $wh => $n): ?><tr><td><?= h($wh) ?></td><td class="n"><?= num($n) ?></td></tr><?php endforeach; ?>
    </table>
    <?php else: ?><div class="empty">No taps on the shower gel number in this period.</div><?php endif; ?>
  </div>

  <?php if ($COL): ?>
  <h2>Shower gel collections</h2>
  <div class="card scroll">
    <table style="min-width:420px">
      <tr><th>Collection</th><th>Opened</th><th>Add to cart</th></tr>
      <?php foreach ($COL as $cn => $c): ?><tr><td><?= h($cn) ?></td><td><?= num($c['pv']) ?></td><td><?= num($c['atc']) ?></td></tr><?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <!-- ═════════ BY DAY ═════════ -->
  <?php if ($days > 1): ?>
  <h2>Day by day</h2>
  <div class="card scroll">
    <table>
      <tr><th>Day</th><th>Page opens</th><th>Add to cart</th><th>WhatsApp (cards)</th><th>WhatsApp (shower gel number)</th></tr>
      <?php foreach (array_reverse($dates) as $d): $x = $D[$d]; ?>
      <tr>
        <td><?= h(date('D j M', strtotime($d))) ?><?= $d === $today ? ' <small style="display:inline">(today so far)</small>' : '' ?></td>
        <td><?= num($x['pdp']) ?></td><td><?= num($x['atc']) ?></td><td><?= num($x['wa']) ?></td><td class="n"><?= num($EVD[$d] ?? 0) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <!-- ═════════ WHERE ON THE PAGE ═════════ -->
  <?php if ($SEC): ?>
  <h2>Where the shower gel cards were tapped</h2>
  <p class="hint">Each row is one block of a page (a product row on the shower gel page, a collection, the homepage...). Names come from the section code on your theme.</p>
  <div class="card scroll">
    <table>
      <tr><th>Block</th><th>Card seen*</th><th>Card taps*</th><th>Taps per 100 seen</th></tr>
      <?php foreach (array_slice($SEC, 0, 12, true) as $name => $s): ?>
      <tr><td><?= h($name) ?></td><td><?= num($s['seen']) ?></td><td class="n"><?= num($s['taps']) ?></td><td><?= f1(p100($s['taps'], $s['seen'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="foot">Shower gel = any product whose handle has “shower gel” in it (plus <?= count($EXTRA) ?> listed by name). To add one more, add its handle to <code>$EXTRA</code> at the top of showergel.php. · <a href="report.php">Store report</a></div>
</div>
</body>
</html>