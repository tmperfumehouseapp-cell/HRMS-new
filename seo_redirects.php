<?php
/* TM SEO — fix broken pages in bulk.   Open:  https://fourpirates.space/analytics/seo_redirects.php   (same key / login as report.php)
   What it does: takes the broken addresses found by the website check and, for each one, finds the closest LIVE page on your site
   (by the words in the address). It then gives you files to import in Shopify:  Online Store → Navigation → View URL redirects → Import.
     • "Ready" file  = confident matches. Safe to import.
     • "Check first" = likely matches, look through them (or import after a quick check).
     • "No match"    = addresses where no live page is close. Decide these yourself (list only, nothing to import).
   Nothing here changes your website or Shopify. Claude is NOT used (no cost, no daily limit).
   Optional: upload the file you get from Shopify → URL redirects → Export, so addresses that already have a redirect are left out.
   Needs (same folder):  config.php, seo_lib2.php, seo_config2.php  and a finished website check (seo_crawl3.php). */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
@set_time_limit(120);
@ini_set('memory_limit', '256M');

$CFG = require __DIR__ . '/config.php';
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ── access: same key and cookie as report.php ── */
$RK = (string)($CFG['viewkey'] ?? '');
if ($RK === '') { http_response_code(403); echo 'Add a viewkey line to config.php first.'; exit; }
$tok = hash('sha256', 'tmrep|' . $RK);
$k = (string)($_GET['k'] ?? '');
$kOk = $k !== '' && hash_equals($RK, $k);
$cOk = hash_equals($tok, (string)($_COOKIE['tm_rep'] ?? ''));
if (!$kOk && !$cOk) { http_response_code(403); echo 'Open this page with your key:  ' . h(basename($_SERVER['SCRIPT_NAME'] ?? 'seo_redirects.php')) . '?k=YOUR_VIEW_KEY'; exit; }
if ($kOk) {
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
  setcookie('tm_rep', $tok, ['expires' => time() + 86400 * 60, 'path' => $dir, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
  $keep = array_diff_key($_GET, ['k' => 1]);
  header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'seo_redirects.php') . ($keep ? '?' . http_build_query($keep) : ''));
  exit;
}
$CSRF = hash('sha256', 'rdr|' . $tok);
$self = basename($_SERVER['SCRIPT_NAME'] ?? 'seo_redirects.php');
$dash = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($_COOKIE['tm_dash'] ?? 'report.php')) ?: 'report.php';

$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $db = new PDO($CFG['dsn'], $CFG['dbuser'] ?? null, $CFG['dbpass'] ?? null, $o); }
catch (Throwable $e) { http_response_code(500); echo 'Database not reachable.'; exit; }

$libF = __DIR__ . '/seo_lib2.php'; $cfgF = __DIR__ . '/seo_config2.php';
if (!is_file($libF) || !is_file($cfgF)) { echo 'Upload seo_lib2.php and seo_config2.php (same folder) first.'; exit; }
require_once $libF; $SEOCFG = require $cfgF;
$storeList = array_keys((array)($SEOCFG['domains'] ?? []));
$store = in_array((string)($_GET['store'] ?? ($_POST['store'] ?? '')), $storeList, true) ? (string)($_GET['store'] ?? $_POST['store']) : ($storeList[0] ?? '');
if ($store === '') { echo 'No store in seo_config2.php.'; exit; }
seo_install($db);

$EXIST = __DIR__ . '/data/existing_redirects.json';

/* ═════════ matching ═════════ */
function rd_path(string $u): string { $p = (string)(parse_url($u, PHP_URL_PATH) ?? '/'); $p = rawurldecode($p); $p = rtrim($p, '/'); return $p === '' ? '/' : $p; }
function rd_slug(string $path): array {
  $p = strtolower($path);
  if (preg_match('#^/(products|collections|pages)/([^/?]+)#', $p, $m)) return [$m[1], $m[2]];
  if (preg_match('#^/blogs/[^/]+/([^/?]+)#', $p, $m)) return ['blogs', $m[1]];
  return ['other', basename($p)];
}
function rd_tok(string $slug): array {
  static $stop = null;
  if ($stop === null) $stop = array_flip(['inspired', 'perfume', 'perfumes', 'attar', 'tmperfumehouse', 'tmph', 'tm', 'for', 'the', 'of', 'by', 'and', 'with', 'ml', 'copy', 'long', 'lasting', 'pack', 'edp', 'spray', 'fragrance', 'fragrances', 'a', 'in', 'to']);
  $out = [];
  foreach (preg_split('/[^a-z0-9]+/', strtolower($slug)) as $t) {
    if ($t === '' || isset($stop[$t])) continue;
    if (ctype_digit($t) && strlen($t) > 4) continue;
    $out[$t] = in_array($t, ['men', 'man', 'women', 'woman', 'her', 'him', 'unisex'], true) ? 0.5 : 1.0;
  }
  return $out;
}
function rd_score(array $A, array $B): float {
  $sa = array_sum($A); $sb = array_sum($B); if ($sa <= 0 || $sb <= 0) return 0.0;
  $inter = 0.0; $used = [];
  foreach ($A as $t => $w) {
    $best = 0.0; $bk = null;
    foreach ($B as $u => $w2) {
      if (isset($used[$u])) continue;
      $s = 0.0;
      if ($t === $u) $s = 1.0;
      elseif (strlen($t) >= 4 && strlen($u) >= 4) { if (levenshtein($t, $u) <= 1) $s = 0.8; elseif (strpos($t, $u) === 0 || strpos($u, $t) === 0) $s = 0.7; }
      if ($s > $best) { $best = $s; $bk = $u; }
    }
    if ($bk !== null) { $used[$bk] = 1; $inter += $w * $best; }
  }
  return 0.65 * ($inter / $sa) + 0.35 * (2 * $inter / ($sa + $sb));
}
function rd_load_existing(string $file): array {
  $d = @json_decode((string)@file_get_contents($file), true);
  return is_array($d) && isset($d['paths']) && is_array($d['paths']) ? $d : ['paths' => [], 'updated' => ''];
}
function rd_compute(PDO $db, string $store, array $SEOCFG, array $existing): array {
  $si = seo_issues($db, $store, $SEOCFG);
  $items = [];
  foreach ($si['groups'] as $g) { if (($g['key'] ?? '') === 'broken') { $items = $g['items']; break; } }
  $ex = array_flip(array_map('strtolower', $existing));
  /* live pages we can send people to */
  $targets = [];
  foreach (seo_q($db, "SELECT url, type FROM seo_pages WHERE store=? AND status>=200 AND status<300 AND noindex=0 AND type IN ('product','collection','page','blog')", [$store]) as $r) {
    $p = rd_path((string)$r['url']); [$ty, $sl] = rd_slug($p); $tk = rd_tok($sl); if (!$tk) continue;
    $targets[] = ['path' => $p, 'ty' => $ty, 'tk' => $tk];
  }
  $idx = [];
  foreach ($targets as $i => $t) foreach ($t['tk'] as $tok => $w) { $idx[$tok][$i] = 1; if (strlen($tok) >= 4) $idx['~' . substr($tok, 0, 4)][$i] = 1; }
  $ready = []; $check = []; $none = []; $skipped = 0; $seen = [];
  foreach ($items as $it) {
    $from = rd_path((string)$it['url']);
    if (isset($seen[$from])) continue; $seen[$from] = 1;
    if (isset($ex[strtolower($from)])) { $skipped++; continue; }
    [$fty, $fsl] = rd_slug($from); $A = rd_tok($fsl);
    $note = (string)($it['note'] ?? '');
    if (!$A) { $none[] = ['from' => $from, 'to' => '', 'score' => 0, 'note' => $note]; continue; }
    $cand = [];
    foreach ($A as $tk => $w) { foreach (($idx[$tk] ?? []) as $i => $_) $cand[$i] = 1; if (strlen($tk) >= 4) foreach (($idx['~' . substr($tk, 0, 4)] ?? []) as $i => $_) $cand[$i] = 1; }
    $best = null; $second = 0.0;
    foreach ($cand as $i => $_) {
      $t = $targets[$i]; if ($t['path'] === $from) continue;
      $s = rd_score($A, $t['tk']);
      $same = ($fty === $t['ty']) || ($fty === 'blogs' && $t['ty'] === 'blog');
      if (!$same) $s *= ($fty === 'products' || $fty === 'collections') && in_array($t['ty'], ['product', 'collection'], true) ? 0.7 : 0.6;
      if ($best === null || $s > $best['score']) { if ($best !== null) $second = max($second, $best['score']); $best = ['to' => $t['path'], 'score' => $s]; }
      elseif ($s > $second) $second = $s;
    }
    if ($best === null || $best['score'] < 0.55) { $none[] = ['from' => $from, 'to' => $best['to'] ?? '', 'score' => $best['score'] ?? 0, 'note' => $note]; continue; }
    $row = ['from' => $from, 'to' => $best['to'], 'score' => $best['score'], 'note' => $note];
    $sure = $best['score'] >= 0.80 && ($best['score'] - $second) >= 0.05 && (count($A) >= 2 || $best['score'] >= 0.95);
    if ($sure) $ready[] = $row; else $check[] = $row;
  }
  $by = function ($a, $b) { return $b['score'] <=> $a['score']; };
  usort($ready, $by); usort($check, $by);
  return ['ready' => $ready, 'check' => $check, 'none' => $none, 'total' => count($items), 'skipped' => $skipped, 'live' => count($targets)];
}

/* ═════════ upload of Shopify's current redirects (so we do not repeat them) ═════════ */
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo 'Page expired. Reload and try again.'; exit; }
  if (!empty($_FILES['f']['tmp_name']) && is_uploaded_file($_FILES['f']['tmp_name']) && (int)$_FILES['f']['size'] < 20 * 1024 * 1024) {
    $paths = []; $col = 0; $first = true;
    $raw = (string)file_get_contents($_FILES['f']['tmp_name']); $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);   // Excel adds an invisible mark at the start
    $fp = fopen('php://temp', 'r+'); fwrite($fp, $raw); rewind($fp);
    while ($fp && ($row = fgetcsv($fp)) !== false) {
      if ($first) { $first = false; $hdr = array_map(function ($c) { return strtolower(trim((string)$c)); }, $row); $ci = array_search('redirect from', $hdr, true); if ($ci !== false) { $col = (int)$ci; continue; } }
      $v = trim((string)($row[$col] ?? '')); if ($v === '') continue;
      $paths[strtolower(rd_path($v))] = 1;
    }
    if ($fp) fclose($fp);
    if (!is_dir(dirname($EXIST))) @mkdir(dirname($EXIST), 0755, true);
    @file_put_contents($EXIST, json_encode(['updated' => date('Y-m-d H:i'), 'paths' => array_keys($paths)]));
    $msg = 'Saved ' . number_format(count($paths)) . ' existing redirects. They are left out of the files below.';
  } else { $msg = 'No file received. Choose the CSV you exported from Shopify, then press Upload.'; }
  header('Location: ' . $self . '?' . http_build_query(['store' => $store, 'm' => $msg]));
  exit;
}
$msg = mb_substr((string)($_GET['m'] ?? ''), 0, 200);
$ex = rd_load_existing($EXIST);
$R = rd_compute($db, $store, $SEOCFG, $ex['paths']);

/* ═════════ downloads ═════════ */
$dl = (string)($_GET['dl'] ?? '');
if (in_array($dl, ['ready', 'check', 'none'], true)) {
  $rows = $R[$dl]; $n = (int)($_GET['n'] ?? 0); if ($n > 0) $rows = array_slice($rows, 0, $n);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="redirects-' . $dl . ($n > 0 ? '-first' . $n : '') . '-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  if ($dl === 'none') { fputcsv($out, ['Broken address', 'Closest live page (not close enough)', 'Where it is used']); foreach ($rows as $r) fputcsv($out, [$r['from'], $r['to'], $r['note']]); }
  else { fputcsv($out, ['Redirect from', 'Redirect to']); foreach ($rows as $r) fputcsv($out, [$r['from'], $r['to']]); }
  fclose($out); exit;
}
$q = function (array $x) use ($store): string { return $self . '?' . http_build_query(array_merge(['store' => $store], $x)); };
header('Content-Type: text/html; charset=utf-8');
$show = function (array $rows, int $n = 25): string {
  if (!$rows) return '<div class="empty">None.</div>';
  $o = '<div class="scroll"><table><tr><th>Broken address</th><th>Send visitors to</th><th>Match</th></tr>';
  foreach (array_slice($rows, 0, $n) as $r) $o .= '<tr><td class="l">' . h($r['from']) . '</td><td class="l">' . ($r['to'] !== '' ? h($r['to']) : '<span class="fl">no close page</span>') . '</td><td>' . ($r['score'] > 0 ? round($r['score'] * 100) . '%' : '–') . '</td></tr>';
  $o .= '</table></div>';
  if (count($rows) > $n) $o .= '<p class="hint">Showing the first ' . $n . ' of ' . number_format(count($rows)) . '. The download has all of them.</p>';
  return $o;
};
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fix broken pages in bulk</title>
<style>
:root{--bg:#fdf8f1;--ink:#241c15;--mut:#8a8176;--line:#eadfce;--g:#1b7a3d;--o:#e8761c;--r:#d93636}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:900px;margin:0 auto;padding:20px 16px 60px}h1{font-size:26px;margin:6px 0 2px}.sub{color:var(--mut);margin:0 0 14px}
a{color:var(--g)}.top a{font-weight:700;text-decoration:none}
.big{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:12px 0}
.k{background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px 16px}.k small{display:block;color:var(--mut);font-size:13px}.k b{font-size:28px;line-height:1.2}
.k.g b{color:var(--g)}.k.o b{color:var(--o)}.k.r b{color:var(--r)}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px 16px;margin:12px 0}
h2{font-size:15px;letter-spacing:.06em;text-transform:uppercase;color:var(--g);margin:26px 0 6px}
ol{margin:6px 0 4px;padding-left:20px}li{margin:5px 0}
.btn{display:inline-block;background:var(--g);color:#fff;border:0;border-radius:999px;padding:9px 18px;font:700 14px/1.2 inherit;text-decoration:none;margin:4px 8px 4px 0;cursor:pointer}
.btn.alt{background:#fff;color:var(--g);border:2px solid var(--g)}.btn.o{background:var(--o)}
.hint{color:var(--mut);font-size:14px;margin:4px 0 8px}.empty{color:var(--mut);padding:10px 0}.fl{color:var(--mut)}
.msg{background:#eaf6ee;border:1.5px solid var(--g);border-radius:12px;padding:10px 14px;margin:10px 0}
.warn{background:#fff4e0;border:1px dashed #e7b66a;color:#7a4a00;border-radius:12px;padding:10px 14px;margin:10px 0;font-size:14px}
.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse}th{font-size:12px;color:var(--mut);text-align:left;padding:7px 6px;border-bottom:1px solid var(--line)}
td{padding:7px 6px;border-bottom:1px solid #f4ece0;font-size:14px;text-align:right}td.l{text-align:left;word-break:break-all}tr:last-child td{border:0}
input[type=file]{font:inherit;max-width:100%}
</style></head><body><div class="wrap">
<div class="top"><a href="<?= h($dash) ?>#seo">← Dashboard (SEO)</a></div>
<h1>Fix broken pages in bulk</h1>
<p class="sub">Download a file, import it in Shopify once, and the broken addresses send visitors to the closest live page. Nothing is changed until you import.</p>
<?php if ($msg !== ''): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>

<?php if ($R['total'] === 0): ?>
  <div class="warn">No broken addresses found in the last website check. Run the check again on the Website check page if you expected some.</div>
<?php else: ?>
<div class="big">
  <div class="k"><small>Broken addresses found</small><b><?= number_format($R['total']) ?></b></div>
  <div class="k g"><small>Ready to import</small><b><?= number_format(count($R['ready'])) ?></b></div>
  <div class="k o"><small>Check first</small><b><?= number_format(count($R['check'])) ?></b></div>
  <div class="k r"><small>No close page</small><b><?= number_format(count($R['none'])) ?></b></div>
</div>
<?php if ($R['skipped'] > 0): ?><p class="hint">Left out: <?= number_format($R['skipped']) ?> addresses that already have a redirect in Shopify (from the file you uploaded<?= $ex['updated'] !== '' ? ' on ' . h($ex['updated']) : '' ?>).</p><?php endif; ?>

<h2>Step 1 (optional): leave out redirects you already have</h2>
<div class="card">
  <p style="margin:0 0 6px">In Shopify open <b>Online Store → Navigation → View URL redirects</b>, press <b>Export</b>, choose <b>All redirects</b>, and download the CSV. Upload it here.</p>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= h($CSRF) ?>"><input type="hidden" name="store" value="<?= h($store) ?>">
    <input type="file" name="f" accept=".csv,text/csv" required> <button class="btn alt" type="submit">Upload</button></form>
</div>

<h2>Step 2: download and import the ready file</h2>
<div class="card">
  <ol>
    <li>Press <b>Download test file (first 20)</b> and import it first, to see that it works.</li>
    <li>In Shopify: <b>Online Store → Navigation → View URL redirects → Import</b>, choose the file, press <b>Import</b>.</li>
    <li>Open a few of the “Redirect from” addresses in your browser. They should land on the right product. Then download and import the full file.</li>
  </ol>
  <a class="btn o" href="<?= h($q(['dl' => 'ready', 'n' => 20])) ?>">Download test file (first 20)</a>
  <a class="btn" href="<?= h($q(['dl' => 'ready'])) ?>">Download ready file (<?= number_format(count($R['ready'])) ?>)</a>
  <?= $show($R['ready']) ?>
</div>

<h2>Step 3: look through the “check first” file</h2>
<div class="card">
  <p class="hint" style="margin-top:0">These are likely matches but not certain (the words in the address are only partly the same). Scroll the list; if most look right, import the file. Delete wrong rows in Excel before importing.</p>
  <a class="btn alt" href="<?= h($q(['dl' => 'check'])) ?>">Download check file (<?= number_format(count($R['check'])) ?>)</a>
  <?= $show($R['check']) ?>
</div>

<h2>No close page found</h2>
<div class="card">
  <p class="hint" style="margin-top:0">These products are gone and nothing similar is live. Options: leave them (Google drops them in time), or redirect them yourself to a collection such as Best Sellers. Do not send them all to the home page.</p>
  <a class="btn alt" href="<?= h($q(['dl' => 'none'])) ?>">Download this list (<?= number_format(count($R['none'])) ?>)</a>
  <?= $show($R['none'], 15) ?>
</div>
<p class="hint">Matched against <?= number_format($R['live']) ?> live pages from the last website check. After importing, run <a href="seo_crawl3.php?store=<?= h(urlencode($store)) ?>">Check website again</a> and the broken count should fall.</p>
<?php endif; ?>
</div></body></html>
