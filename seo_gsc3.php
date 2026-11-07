<?php
/* TM SEO — Google searches + Domain ranking (from Google Search Console, free).
   Open:  https://fourpirates.space/analytics/seo_gsc3.php   (same view key as report2.php)
   Once a week: Search Console → Performance → Export → Download CSV (a .zip) → upload it on the "Upload file" tab.
   Each upload is saved as a snapshot, so you can see which searches moved up or down since the last file.
   Needs seo_lib2.php, config.php and seo_config2.php in the same folder. Never changes your website. */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
require __DIR__ . '/seo_lib2.php';
$CFG = require __DIR__ . '/config.php';
$SEO = require __DIR__ . '/seo_config2.php';
@set_time_limit(60);

/* ── access: same key and cookie as report2.php ── */
$RK = (string)($CFG['viewkey'] ?? '');
if ($RK === '') { http_response_code(403); echo 'Add a viewkey line to config.php first.'; exit; }
$tok = hash('sha256', 'tmrep|' . $RK);
$k = (string)($_GET['k'] ?? '');
$kOk = $k !== '' && hash_equals($RK, $k);
$cOk = hash_equals($tok, (string)($_COOKIE['tm_rep'] ?? ''));
if (!$kOk && !$cOk) { http_response_code(403); echo 'Open this page with your key:  seo_gsc3.php?k=YOUR_VIEW_KEY'; exit; }
if ($kOk) {
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
  setcookie('tm_rep', $tok, ['expires' => time() + 86400 * 60, 'path' => $dir, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
  $keep = array_diff_key($_GET, ['k' => 1]);
  header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'seo_gsc3.php') . ($keep ? '?' . http_build_query($keep) : ''));
  exit;
}
$csrf = hash('sha256', 'gsc|' . $tok);
$dash = preg_match('/^[A-Za-z0-9_\-]+\.php$/', (string)($_COOKIE['tm_dash'] ?? '')) ? (string)$_COOKIE['tm_dash'] : 'report4.php';   // the name you gave the dashboard file (set by the dashboard itself)

$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $db = new PDO($CFG['dsn'], $CFG['dbuser'] ?? null, $CFG['dbpass'] ?? null, $o); seo_install($db); }
catch (Throwable $e) { http_response_code(500); echo 'Database not reachable.'; exit; }

/* ───────── tables ───────── */
function gsc_install(PDO $db): void {
  $lite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $tail = $lite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  $db->exec("CREATE TABLE IF NOT EXISTS seo_gsc_q (store VARCHAR(30) NOT NULL, snap CHAR(10) NOT NULL, q VARCHAR(300) NOT NULL, clicks INT NOT NULL DEFAULT 0, impr INT NOT NULL DEFAULT 0, pos DOUBLE NOT NULL DEFAULT 0, PRIMARY KEY (store, snap, q))$tail");
  $db->exec("CREATE TABLE IF NOT EXISTS seo_gsc_p (store VARCHAR(30) NOT NULL, snap CHAR(10) NOT NULL, h CHAR(32) NOT NULL, url VARCHAR(600) NOT NULL, clicks INT NOT NULL DEFAULT 0, impr INT NOT NULL DEFAULT 0, pos DOUBLE NOT NULL DEFAULT 0, PRIMARY KEY (store, snap, h))$tail");
  $db->exec("CREATE TABLE IF NOT EXISTS seo_gsc_d (store VARCHAR(30) NOT NULL, d CHAR(10) NOT NULL, clicks INT NOT NULL DEFAULT 0, impr INT NOT NULL DEFAULT 0, pos DOUBLE NOT NULL DEFAULT 0, PRIMARY KEY (store, d))$tail");
  $db->exec("CREATE TABLE IF NOT EXISTS seo_da (store VARCHAR(30) NOT NULL, m CHAR(7) NOT NULL, val INT NOT NULL, PRIMARY KEY (store, m))$tail");
}
try { gsc_install($db); } catch (Throwable $e) { http_response_code(500); echo 'Could not create tables.'; exit; }

/* ───────── reading the files from Google ───────── */
function gsc_pos(string $s): float {   // position never has a thousands separator; accept 14.3 and 14,3
  $s = str_replace([',', "\xC2\xA0", ' ', '%'], ['.', '', '', ''], trim($s));
  return is_numeric($s) ? (float)$s : 0.0;
}
function gsc_int(string $s): int { return (int)preg_replace('/[^0-9]/', '', $s); }
function gsc_path(string $url): string {
  $p = (string)(parse_url(trim($url), PHP_URL_PATH) ?? '/');
  $p = rawurldecode($p); $p = rtrim($p, '/');
  return $p === '' ? '/' : $p;
}
/** @return array{kind:string,rows:array,note:string} kind = queries | pages | dates | skip */
function gsc_parse(string $name, string $text): array {
  $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
  $text = str_replace("\r\n", "\n", $text);
  $nl = strpos($text, "\n"); $first = $nl === false ? $text : substr($text, 0, $nl);
  $delim = ','; $best = substr_count($first, ',');
  foreach ([';' => ';', "\t" => "\t"] as $d) { $c = substr_count($first, $d); if ($c > $best) { $best = $c; $delim = $d; } }
  $lines = preg_split('/\n/', $text); $rows = [];
  foreach ($lines as $ln) { if (trim($ln) === '') continue; $rows[] = str_getcsv($ln, $delim, '"', ''); }
  if (count($rows) < 2) return ['kind' => 'skip', 'rows' => [], 'note' => 'empty file'];
  $head = array_shift($rows); $h0 = strtolower(trim((string)$head[0])); $fn = strtolower($name);
  $kind = '';
  if (preg_match('/countr|device|appearance|filter/', $fn)) return ['kind' => 'skip', 'rows' => [], 'note' => 'not needed (countries / devices)'];
  if (preg_match('/quer/', $fn)) $kind = 'queries';
  elseif (preg_match('/page/', $fn)) $kind = 'pages';
  elseif (preg_match('/date|chart/', $fn)) $kind = 'dates';
  if ($kind === '') {
    $v = trim((string)($rows[0][0] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $kind = 'dates';
    elseif (preg_match('#^https?://#i', $v)) $kind = 'pages';
    elseif (preg_match('/quer|search|keyword/', $h0)) $kind = 'queries';
    elseif (preg_match('/country|device/', $h0)) return ['kind' => 'skip', 'rows' => [], 'note' => 'not needed (countries / devices)'];
    else return ['kind' => 'skip', 'rows' => [], 'note' => 'could not tell what this file is'];
  }
  $out = [];
  foreach ($rows as $r) {
    if (count($r) < 4) continue;
    $key = trim((string)$r[0]); if ($key === '') continue;
    $out[] = [$key, gsc_int((string)$r[1]), gsc_int((string)$r[2]), gsc_pos((string)$r[count($r) - 1])];   // last column = position, col 3 = CTR (we work it out ourselves)
    if (count($out) >= 5000) break;
  }
  return ['kind' => $kind, 'rows' => $out, 'note' => ''];
}
function gsc_save(PDO $db, string $store, string $kind, array $rows): int {
  $snap = date('Y-m-d'); $n = 0;
  $db->beginTransaction();
  try {
    if ($kind === 'queries') {
      seo_x($db, "DELETE FROM seo_gsc_q WHERE store=? AND snap=?", [$store, $snap]);
      $st = $db->prepare("REPLACE INTO seo_gsc_q (store,snap,q,clicks,impr,pos) VALUES (?,?,?,?,?,?)");
      foreach ($rows as $r) { $st->execute([$store, $snap, mb_substr($r[0], 0, 300), $r[1], $r[2], $r[3]]); $n++; }
    } elseif ($kind === 'pages') {
      $agg = [];
      foreach ($rows as $r) {
        $h = md5(gsc_path($r[0])); $w = max(1, $r[2]);
        if (!isset($agg[$h])) $agg[$h] = [$r[0], 0, 0, 0.0, 0];
        $agg[$h][1] += $r[1]; $agg[$h][2] += $r[2]; $agg[$h][3] += $r[3] * $w; $agg[$h][4] += $w;
      }
      seo_x($db, "DELETE FROM seo_gsc_p WHERE store=? AND snap=?", [$store, $snap]);
      $st = $db->prepare("REPLACE INTO seo_gsc_p (store,snap,h,url,clicks,impr,pos) VALUES (?,?,?,?,?,?,?)");
      foreach ($agg as $h => $a) { $st->execute([$store, $snap, $h, mb_substr($a[0], 0, 600), $a[1], $a[2], round($a[3] / $a[4], 2)]); $n++; }
    } elseif ($kind === 'dates') {
      $st = $db->prepare("REPLACE INTO seo_gsc_d (store,d,clicks,impr,pos) VALUES (?,?,?,?,?)");
      foreach ($rows as $r) { if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $r[0])) { $st->execute([$store, $r[0], $r[1], $r[2], $r[3]]); $n++; } }
    }
    $db->commit();
  } catch (Throwable $e) { $db->rollBack(); throw $e; }
  return $n;
}

/* ───────── helpers for the pages ───────── */
function gsc_snaps(PDO $db, string $store): array { return array_column(seo_q($db, "SELECT DISTINCT snap FROM seo_gsc_q WHERE store=? ORDER BY snap DESC LIMIT 12", [$store]), 'snap'); }
function gsc_q(PDO $db, string $store, string $snap): array {
  $m = []; foreach (seo_q($db, "SELECT q,clicks,impr,pos FROM seo_gsc_q WHERE store=? AND snap=?", [$store, $snap]) as $r) $m[$r['q']] = ['c' => (int)$r['clicks'], 'i' => (int)$r['impr'], 'p' => (float)$r['pos']];
  return $m;
}
function gsc_stats(array $q): array {
  $i = 0; $c = 0; $pw = 0.0; $b = ['t3' => 0, 't10' => 0, 'p20' => 0, 'p21' => 0];
  foreach ($q as $r) {
    $i += $r['i']; $c += $r['c']; $pw += $r['p'] * $r['i'];
    if ($r['p'] <= 3) $b['t3']++; elseif ($r['p'] <= 10) $b['t10']++; elseif ($r['p'] <= 20) $b['p20']++; else $b['p21']++;
  }
  return ['n' => count($q), 'impr' => $i, 'clicks' => $c, 'avg' => $i > 0 ? $pw / $i : 0.0, 'b' => $b];
}
function gsc_expected_ctr(float $pos): float {
  if ($pos < 1.5) return 0.28; if ($pos < 2.5) return 0.15; if ($pos < 3.5) return 0.10; if ($pos < 4.5) return 0.07;
  if ($pos < 5.5) return 0.05; if ($pos < 7.5) return 0.035; return 0.02;
}
function gsc_todo(float $pos): string {
  if ($pos <= 3.5) return 'Keep as it is.';
  if ($pos <= 10.5) return 'Improve the Google title and description of the page that shows for this.';
  if ($pos <= 20.5) return 'Write or extend a page / article for this exact phrase.';
  return 'Needs its own new page or article for this phrase.';
}
function gsc_ask(string $q, float $pos, int $impr, int $clicks, string $domain): string {
  $what = $pos <= 10.5 ? 'a better Google title + description (and any text change needed)' : 'a new article or page text';
  return "SEO request for {$domain}: please write {$what} for the Google search \"{$q}\". We are at position " . round($pos, 1) . ", shown {$impr} times, {$clicks} clicks in the file period. Use our real products, 30% oil, and WhatsApp ordering. Give me the Google title (max 60 chars), Google description (max 155), URL address, and the text.";
}
function fnum($n): string { return number_format((float)$n, 0, '.', ','); }

/* ───────── what was asked ───────── */
$stores = array_keys($SEO['domains']);
$store = in_array($_GET['store'] ?? '', $stores, true) ? (string)$_GET['store'] : $stores[0];
$domain = rtrim((string)$SEO['domains'][$store], '/');
$view = (string)($_GET['view'] ?? '');
$msg = []; $err = '';

/* upload */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $post = (string)($_POST['do'] ?? '');
  if (!hash_equals($csrf, (string)($_POST['t'] ?? ''))) { http_response_code(400); echo 'Session expired. Go back and try again.'; exit; }
  if ($post === 'upload') {
    $files = [];
    $up = $_FILES['f'] ?? null;
    if ($up && is_array($up['name'])) {
      foreach ($up['name'] as $i => $nm) {
        if (($up['error'][$i] ?? 4) !== UPLOAD_ERR_OK) { if (($up['error'][$i] ?? 4) !== UPLOAD_ERR_NO_FILE) $msg[] = ['bad', $nm . ': upload failed (file too big or interrupted).']; continue; }
        if ((int)$up['size'][$i] > 12 * 1024 * 1024) { $msg[] = ['bad', $nm . ': file is too big.']; continue; }
        $raw = (string)file_get_contents($up['tmp_name'][$i]);
        if (preg_match('/\.zip$/i', (string)$nm)) {
          if (!class_exists('ZipArchive')) { $msg[] = ['bad', $nm . ': this server cannot open zip files. Unzip it on your computer and upload the CSV files inside.']; continue; }
          $z = new ZipArchive();
          if ($z->open($up['tmp_name'][$i]) !== true) { $msg[] = ['bad', $nm . ': could not open the zip.']; continue; }
          for ($j = 0; $j < $z->numFiles; $j++) { $en = (string)$z->getNameIndex($j); if (preg_match('/\.csv$/i', $en)) { $files[] = [basename($en), (string)$z->getFromIndex($j)]; } }
          $z->close();
        } elseif (preg_match('/\.csv$/i', (string)$nm)) { $files[] = [(string)$nm, $raw]; }
        else $msg[] = ['bad', $nm . ': only .zip or .csv files.'];
      }
    }
    $got = 0;
    foreach ($files as [$nm, $txt]) {
      $r = gsc_parse($nm, $txt);
      if ($r['kind'] === 'skip') { $msg[] = ['mut', $nm . ' — ' . $r['note']]; continue; }
      try { $n = gsc_save($db, $store, $r['kind'], $r['rows']); $got++; $lab = ['queries' => 'searches', 'pages' => 'pages', 'dates' => 'days'][$r['kind']]; $msg[] = ['ok', $nm . ' — saved ' . fnum($n) . ' ' . $lab . '.']; }
      catch (Throwable $e) { $msg[] = ['bad', $nm . ' — could not be saved.']; }
    }
    if (!$files && !$msg) $msg[] = ['bad', 'No file chosen.'];
    $view = 'upload';
  } elseif ($post === 'da') {
    $v = (int)($_POST['val'] ?? -1);
    if ($v >= 0 && $v <= 100) {
      seo_x($db, "REPLACE INTO seo_da (store,m,val) VALUES (?,?,?)", [$store, date('Y-m'), $v]);
      $bk = (string)($_POST['back'] ?? '');
      if (preg_match('/^[A-Za-z0-9_\-]+\.php$/', $bk)) { header('Location: ' . $bk . '?' . http_build_query(['store' => $store]) . '#domain'); exit; }   // came from the dashboard: go back there
      header('Location: seo_gsc3.php?' . http_build_query(['store' => $store, 'view' => 'domain'])); exit;
    }
    $err = 'Domain Authority must be a number from 0 to 100.'; $view = 'domain';
  }
}

$snaps = gsc_snaps($db, $store);
$has = (bool)$snaps;
if (!in_array($view, ['keywords', 'domain', 'upload'], true)) $view = $has ? 'keywords' : 'upload';
$cur = $has ? gsc_q($db, $store, $snaps[0]) : [];
$prev = count($snaps) > 1 ? gsc_q($db, $store, $snaps[1]) : [];
$S = gsc_stats($cur);
$tabs = ['keywords' => 'Google searches', 'domain' => 'Domain ranking', 'upload' => 'Upload file'];
$qs = fn(string $v) => '?' . http_build_query(['store' => $store, 'view' => $v]);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Google searches</title>
<style>
:root{--bg:#fdf8f1;--ink:#241c15;--mut:#8a8176;--line:#eadfce;--g:#1b7a3d;--o:#e8761c;--r:#d93636}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:20px 16px 60px}h1{font-size:26px;margin:0}.sub{color:var(--mut);margin:2px 0 12px;font-size:15px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0 14px}.tab{padding:8px 14px;border:2px solid var(--ink);border-radius:999px;font-weight:700;font-size:14px;text-decoration:none;color:var(--ink);background:#fff}.tab.on{background:var(--o);border-color:var(--o);color:#fff}
.tab.back{border-color:var(--g);color:var(--g)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:10px;margin-bottom:12px}
.k{background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px 16px}.k small{display:block;color:var(--mut);font-size:13px}.k b{font-size:28px;line-height:1.15}.k em{display:block;font-style:normal;font-size:12px;color:var(--mut)}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:14px 16px;margin-bottom:12px}.card h2{font-size:17px;margin:0 0 4px}.card p.s{margin:0 0 8px;color:var(--mut);font-size:13.5px}
.sc{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:620px}th{text-align:left;color:var(--mut);font-size:12px;padding:6px 8px;border-bottom:2px solid var(--line);white-space:nowrap}td{padding:8px;border-top:1px dashed #e4d6bd;vertical-align:middle}td.n,th.n{text-align:right;white-space:nowrap}
.tag{display:inline-block;border-radius:7px;padding:1px 8px;font-size:12px;background:#fde6d0;white-space:nowrap}.tag.g{background:#dff0e4;color:#14602f}.tag.r{background:#fbdcdc;color:#a32424}
.btn{display:inline-block;border:0;cursor:pointer;background:var(--o);color:#fff;border-radius:9px;padding:7px 12px;font:700 12.5px/1.2 inherit;white-space:nowrap}.btn.gr{background:var(--g)}.btn.o{background:#fff;color:var(--ink);border:1.5px solid var(--ink)}
.bar{height:12px;background:#eadfca;border-radius:7px;overflow:hidden;flex:1}.bar i{display:block;height:100%;background:var(--o)}.bar i.g{background:var(--g)}.bar i.s{background:#a39b90}
.brow{display:flex;align-items:center;gap:10px;margin:7px 0;font-size:14px}.brow span.l{width:120px;flex:none}.brow b{width:90px;text-align:right;flex:none}
.cols{display:flex;align-items:flex-end;gap:6px;height:120px;margin-top:6px}.cols div{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;font-size:11px;color:var(--mut);height:100%}.cols i{display:block;width:100%;max-width:34px;background:var(--o);border-radius:5px 5px 0 0;min-height:2px}
.note{background:#faf3e6;border-radius:12px;padding:10px 12px;font-size:14px;margin:8px 0}
.ok{color:var(--g);font-weight:700}.bad{color:var(--r);font-weight:700}.mut{color:var(--mut)}
ol.st{padding-left:20px;margin:6px 0}ol.st li{margin:6px 0}
input[type=number],input[type=file]{font:inherit;border:1.5px solid var(--ink);border-radius:9px;padding:8px 10px;background:#fffdf7;max-width:100%}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:760px){.two{grid-template-columns:1fr}.brow span.l{width:96px}}
</style></head><body><div class="wrap">
<h1>Google searches &amp; domain ranking</h1>
<div class="sub"><?= seo_h($store) ?> · <?= seo_h($domain) ?><?= $has ? ' · file from ' . seo_h(date('j M Y', strtotime($snaps[0]))) : '' ?></div>
<div class="tabs"><?php foreach ($tabs as $v => $lab): ?><a class="tab<?= $view === $v ? ' on' : '' ?>" href="<?= seo_h($qs($v)) ?>"><?= seo_h($lab) ?></a><?php endforeach; ?>
<a class="tab back" href="seo_issues3.php?store=<?= urlencode($store) ?>">Website problems</a><a class="tab back" href="<?= seo_h($dash) ?>?store=<?= urlencode($store) ?>#<?= $view === 'domain' ? 'domain' : 'google' ?>">← Dashboard</a></div>

<?php if ($view === 'upload'): ?>
  <?php foreach ($msg as [$cls, $t]): ?><div class="note <?= $cls === 'bad' ? 'bad' : ($cls === 'ok' ? 'ok' : 'mut') ?>"><?= seo_h($t) ?></div><?php endforeach; ?>
  <div class="card"><h2>Upload the file from Google (once a week)</h2>
    <p class="s">Free. Google Search Console tells you what people typed to find you. This page reads that file and keeps a history.</p>
    <ol class="st">
      <li>Open <b>search.google.com/search-console</b>, choose <b><?= seo_h(parse_url($domain, PHP_URL_HOST) ?: $domain) ?></b> (if it is not listed, add it with “Add property” and verify it — Google shows the steps).</li>
      <li>Left menu → <b>Performance</b> → <b>Search results</b>. Date: choose <b>Last 3 months</b> (first time you can choose 16 months).</li>
      <li>Top right → <b>Export</b> → <b>Download CSV</b>. You get a <b>.zip</b> file.</li>
      <li>Upload that .zip below (or the Queries / Pages / Dates CSV files from inside it).</li>
    </ol>
    <form method="post" enctype="multipart/form-data" action="<?= seo_h($qs('upload')) ?>">
      <input type="hidden" name="do" value="upload"><input type="hidden" name="t" value="<?= seo_h($csrf) ?>">
      <input type="file" name="f[]" multiple accept=".zip,.csv"> <button class="btn gr" type="submit">Upload</button>
    </form>
    <div class="note">Uploading again on the same day replaces that day’s file. A new day is saved as a new snapshot, so “moved up / down” works from the second upload.</div>
  </div>
  <?php if ($has): ?><div class="card"><h2>Saved files</h2><p class="s"><?= count($snaps) ?> snapshot<?= count($snaps) > 1 ? 's' : '' ?> of searches: <?= seo_h(implode(', ', array_map(fn($s) => date('j M', strtotime($s)), $snaps))) ?>.</p>
  <a class="btn" href="<?= seo_h($qs('keywords')) ?>">See Google searches</a></div><?php endif; ?>

<?php elseif ($view === 'keywords'): ?>
  <?php
    $dates = seo_q($db, "SELECT d,clicks,impr FROM seo_gsc_d WHERE store=? ORDER BY d DESC LIMIT 90", [$store]);
    $c28 = 0; foreach (array_slice($dates, 0, 28) as $d) $c28 += (int)$d['clicks'];
    $visits = $dates ? $c28 : $S['clicks'];
    $chances = []; $lowctr = []; $moveUp = []; $moveDn = [];
    foreach ($cur as $q => $r) {
      if ($r['p'] > 7.5 && $r['p'] <= 20.5 && $r['i'] >= 20) $chances[$q] = $r;
      if ($r['p'] <= 10.5 && $r['i'] >= 100 && ($r['c'] / max(1, $r['i'])) < gsc_expected_ctr($r['p']) * 0.6) $lowctr[$q] = $r;
      if (isset($prev[$q]) && $prev[$q]['i'] >= 20 && $r['i'] >= 20) { $dlt = $prev[$q]['p'] - $r['p']; if ($dlt >= 2) $moveUp[$q] = [$dlt, $r]; elseif ($dlt <= -2) $moveDn[$q] = [$dlt, $r]; }
    }
    uasort($chances, fn($a, $b) => $b['i'] <=> $a['i']); uasort($lowctr, fn($a, $b) => $b['i'] <=> $a['i']);
    uasort($moveUp, fn($a, $b) => $b[0] <=> $a[0]); uasort($moveDn, fn($a, $b) => $a[0] <=> $b[0]);
    $top = $cur; uasort($top, fn($a, $b) => [$b['c'], $b['i']] <=> [$a['c'], $a['i']]);
    $wk = [];
    foreach (array_reverse($dates) as $d) { $w = date('o-\WW', strtotime($d['d'])); $wk[$w] = ($wk[$w] ?? 0) + (int)$d['clicks']; }
    $wk = array_slice($wk, -12, 12, true); $wmax = max(1, $wk ? max($wk) : 1);
    $delta = function (string $q) use ($prev, $cur): string {
      if (!isset($prev[$q])) return ''; $d = $prev[$q]['p'] - $cur[$q]['p'];
      if (abs($d) < 0.5) return ''; return $d > 0 ? ' <span class="ok" title="better than last file">▲' . number_format($d, 1) . '</span>' : ' <span class="bad" title="worse than last file">▼' . number_format(-$d, 1) . '</span>';
    };
  ?>
  <?php if (!$has): ?><div class="note">No file yet. Go to “Upload file”.</div><?php else: ?>
  <div class="grid">
    <div class="k"><small><?= $dates ? 'Google visits, last 28 days' : 'Google visits (in your file)' ?></small><b><?= fnum($visits) ?></b></div>
    <div class="k"><small>Times you were shown</small><b><?= fnum($S['impr']) ?></b><em>in the file period</em></div>
    <div class="k"><small>Average position</small><b><?= number_format($S['avg'], 1) ?></b><em>lower = better (1 is top)</em></div>
    <div class="k"><small>Searches in top 10</small><b><?= (int)($S['b']['t3'] + $S['b']['t10']) ?></b><em>of <?= fnum($S['n']) ?> searches</em></div>
  </div>

  <div class="card"><h2>Best chances — a small push gives a big gain</h2><p class="s">You are on page 1–2 of Google for these (position 8–20) and people already see you. Do the top ones first.</p>
    <?php if (!$chances): ?><div class="mut">Nothing in position 8–20 with enough views yet.</div><?php else: ?>
    <div class="sc"><table><tr><th>Search</th><th class="n">Position</th><th class="n">Shown</th><th class="n">Clicks</th><th>What to do</th><th></th></tr>
    <?php foreach (array_slice($chances, 0, 25, true) as $q => $r): ?>
      <tr><td><b><?= seo_h($q) ?></b></td><td class="n"><span class="tag"><?= number_format($r['p'], 1) ?></span><?= $delta($q) ?></td><td class="n"><?= fnum($r['i']) ?></td><td class="n"><?= fnum($r['c']) ?></td><td><?= seo_h(gsc_todo($r['p'])) ?></td>
      <td><button class="btn" type="button" data-copy="<?= seo_h(gsc_ask($q, $r['p'], $r['i'], $r['c'], $domain)) ?>">Copy request</button></td></tr>
    <?php endforeach; ?></table></div><?php endif; ?>
  </div>

  <div class="card"><h2>Shown a lot, but few people click</h2><p class="s">You are on page 1 but the Google title or description does not make people click. Easy win.</p>
    <?php if (!$lowctr): ?><div class="mut">None found.</div><?php else: ?>
    <div class="sc"><table><tr><th>Search</th><th class="n">Position</th><th class="n">Shown</th><th class="n">Clicks</th><th class="n">Click rate</th><th></th></tr>
    <?php foreach (array_slice($lowctr, 0, 15, true) as $q => $r): ?>
      <tr><td><b><?= seo_h($q) ?></b></td><td class="n"><span class="tag"><?= number_format($r['p'], 1) ?></span></td><td class="n"><?= fnum($r['i']) ?></td><td class="n"><?= fnum($r['c']) ?></td><td class="n"><span class="tag r"><?= number_format($r['c'] / max(1, $r['i']) * 100, 1) ?>%</span></td>
      <td><button class="btn" type="button" data-copy="<?= seo_h(gsc_ask($q, $r['p'], $r['i'], $r['c'], $domain)) ?>">Copy request</button></td></tr>
    <?php endforeach; ?></table></div><?php endif; ?>
  </div>

  <div class="two">
    <div class="card"><h2>Moved up since last file</h2>
      <?php if (!$prev): ?><div class="mut">Shows after your second upload.</div><?php elseif (!$moveUp): ?><div class="mut">No big moves.</div><?php else: foreach (array_slice($moveUp, 0, 8, true) as $q => [$d, $r]): ?>
      <div class="brow"><span class="l" style="flex:1;width:auto"><?= seo_h($q) ?></span><span class="ok">▲ <?= number_format($d, 1) ?></span><span class="tag"><?= number_format($r['p'], 1) ?></span></div><?php endforeach; endif; ?></div>
    <div class="card"><h2>Moved down since last file</h2>
      <?php if (!$prev): ?><div class="mut">Shows after your second upload.</div><?php elseif (!$moveDn): ?><div class="mut">No big moves.</div><?php else: foreach (array_slice($moveDn, 0, 8, true) as $q => [$d, $r]): ?>
      <div class="brow"><span class="l" style="flex:1;width:auto"><?= seo_h($q) ?></span><span class="bad">▼ <?= number_format(-$d, 1) ?></span><span class="tag"><?= number_format($r['p'], 1) ?></span></div><?php endforeach; endif; ?></div>
  </div>

  <?php if ($wk): ?><div class="card"><h2>Google clicks per week</h2><p class="s">From the “Dates” file. Going up is good.</p>
    <div class="cols"><?php foreach ($wk as $w => $c): ?><div><span><?= fnum($c) ?></span><i style="height:<?= max(2, (int)round($c / $wmax * 90)) ?>px"></i><span><?= seo_h(substr($w, 5)) ?></span></div><?php endforeach; ?></div></div><?php endif; ?>

  <div class="card"><h2>Top searches by clicks</h2>
    <div class="sc"><table><tr><th>Search</th><th class="n">Position</th><th class="n">Shown</th><th class="n">Clicks</th></tr>
    <?php foreach (array_slice($top, 0, 15, true) as $q => $r): ?><tr><td><?= seo_h($q) ?></td><td class="n"><span class="tag<?= $r['p'] <= 10.5 ? ' g' : '' ?>"><?= number_format($r['p'], 1) ?></span><?= $delta($q) ?></td><td class="n"><?= fnum($r['i']) ?></td><td class="n"><?= fnum($r['c']) ?></td></tr><?php endforeach; ?></table></div>
  </div>
  <div class="note">“Copy request” copies a ready message. Paste it to me here and I will write the title, description and text. Then you paste my answer into “Write &amp; publish”.</div>
  <?php endif; ?>

<?php else: /* domain */ ?>
  <?php
    $siteN = (int)(seo_q($db, "SELECT COUNT(*) c FROM seo_pages WHERE store=? AND status=200", [$store])[0]['c'] ?? 0);
    $pg = seo_q($db, "SELECT snap FROM seo_gsc_p WHERE store=? ORDER BY snap DESC LIMIT 1", [$store]);
    $pgSnap = $pg ? (string)$pg[0]['snap'] : '';
    $gp = $pgSnap ? seo_q($db, "SELECT h,url,clicks,impr,pos FROM seo_gsc_p WHERE store=? AND snap=? ORDER BY clicks DESC, impr DESC", [$store, $pgSnap]) : [];
    $inG = []; foreach ($gp as $r) $inG[$r['h']] = true;
    $none = [];
    if ($siteN > 0 && $gp) {
      foreach (seo_q($db, "SELECT url,type FROM seo_pages WHERE store=? AND status=200 AND noindex=0 ORDER BY type, url", [$store]) as $r) {
        if (!isset($inG[md5(gsc_path($r['url']))])) $none[] = $r;
      }
    }
    $da = seo_q($db, "SELECT m,val FROM seo_da WHERE store=? ORDER BY m DESC LIMIT 12", [$store]);
    $hist = []; foreach (array_slice($snaps, 0, 8) as $s) { $hist[$s] = gsc_stats($s === $snaps[0] ? $cur : gsc_q($db, $store, $s)); }
    $pb = $prev ? gsc_stats($prev)['b'] : null; $cb = $S['b'];
    $bmax = max(1, $cb['t3'], $cb['t10'], $cb['p20'], $cb['p21']);
    $rowsB = [['Top 3', 't3', 'g'], ['Position 4–10', 't10', 'g'], ['Position 11–20', 'p20', ''], ['Position 21+', 'p21', 's']];
  ?>
  <?php if ($err): ?><div class="note bad"><?= seo_h($err) ?></div><?php endif; ?>
  <div class="note"><b>Honest note:</b> the single “Domain Authority” number (like 32/100) is a score invented by paid tools (Moz, Ahrefs). Google does not publish it and we cannot calculate it. This page shows your <b>real Google numbers</b> (free). If you want the DA number too, type it once a month below.</div>
  <?php if (!$has): ?><div class="note">No file yet. Go to “Upload file”.</div><?php else: ?>
  <div class="grid">
    <div class="k"><small>Pages on your website</small><b><?= $siteN ? fnum($siteN) : '—' ?></b><em><?= $siteN ? 'from the website check' : 'run the website check first' ?></em></div>
    <div class="k"><small>Pages shown in Google</small><b><?= $gp ? fnum(count($gp)) : '—' ?></b><em><?= $gp ? 'in the Pages file' : 'upload the Pages file' ?></em></div>
    <div class="k"><small>Searches in top 3</small><b><?= (int)$cb['t3'] ?></b></div>
    <div class="k"><small>Searches in top 10</small><b><?= (int)($cb['t3'] + $cb['t10']) ?></b></div>
    <div class="k"><small>Domain Authority (you type)</small><b><?= $da ? (int)$da[0]['val'] : '—' ?></b><em><?= $da ? seo_h($da[0]['m']) : 'optional' ?></em></div>
  </div>

  <div class="card"><h2>How many searches you rank for</h2><p class="s">Goal: the orange and grey bars shrink, the green bars grow<?= $pb ? '. Arrow = change since the previous file.' : '. Arrows appear after your second upload.' ?></p>
    <?php foreach ($rowsB as [$lab, $key, $cl]): $dl = $pb ? $cb[$key] - $pb[$key] : null; ?>
      <div class="brow"><span class="l"><?= seo_h($lab) ?></span><div class="bar"><i class="<?= $cl ?>" style="width:<?= max(1, (int)round($cb[$key] / $bmax * 100)) ?>%"></i></div><b><?= fnum($cb[$key]) ?><?php if ($dl !== null && $dl !== 0): $good = in_array($key, ['t3', 't10'], true) ? $dl > 0 : $dl < 0; ?> <span class="<?= $good ? 'ok' : 'bad' ?>"><?= $dl > 0 ? '▲' : '▼' ?><?= abs($dl) ?></span><?php endif; ?></b></div>
    <?php endforeach; ?>
  </div>

  <?php if (count($hist) > 1): ?><div class="card"><h2>History (each upload)</h2><div class="sc"><table><tr><th>File of</th><th class="n">Searches</th><th class="n">Top 3</th><th class="n">Top 10</th><th class="n">Average position</th></tr>
    <?php foreach ($hist as $s => $h): ?><tr><td><?= seo_h(date('j M Y', strtotime($s))) ?></td><td class="n"><?= fnum($h['n']) ?></td><td class="n"><?= (int)$h['b']['t3'] ?></td><td class="n"><?= (int)($h['b']['t3'] + $h['b']['t10']) ?></td><td class="n"><?= number_format($h['avg'], 1) ?></td></tr><?php endforeach; ?></table></div></div><?php endif; ?>

  <div class="card"><h2>Domain Authority (optional, once a month)</h2><p class="s">Get the number free from any “domain authority checker” website, type it here, and we keep the history.</p>
    <form method="post" action="<?= seo_h($qs('domain')) ?>"><input type="hidden" name="do" value="da"><input type="hidden" name="t" value="<?= seo_h($csrf) ?>">
      <input type="number" name="val" min="0" max="100" placeholder="0–100" style="width:120px"> <button class="btn gr" type="submit">Save for <?= seo_h(date('M Y')) ?></button></form>
    <?php if ($da): ?><div style="margin-top:8px"><?php foreach ($da as $r): ?><span class="tag" style="margin-right:6px"><?= seo_h($r['m']) ?>: <b><?= (int)$r['val'] ?></b></span><?php endforeach; ?></div><?php endif; ?>
  </div>

  <?php if ($gp): ?><div class="card"><h2>Pages that bring Google visitors</h2><p class="s">From the Pages file of <?= seo_h(date('j M', strtotime($pgSnap))) ?>.</p>
    <div class="sc"><table><tr><th>Page</th><th class="n">Position</th><th class="n">Shown</th><th class="n">Clicks</th></tr>
    <?php foreach (array_slice($gp, 0, 15) as $r): ?><tr><td><a href="<?= seo_h($r['url']) ?>" target="_blank" rel="noopener"><?= seo_h(gsc_path($r['url'])) ?></a></td><td class="n"><span class="tag<?= (float)$r['pos'] <= 10.5 ? ' g' : '' ?>"><?= number_format((float)$r['pos'], 1) ?></span></td><td class="n"><?= fnum($r['impr']) ?></td><td class="n"><?= fnum($r['clicks']) ?></td></tr><?php endforeach; ?></table></div></div><?php endif; ?>

  <?php if ($none): ?><div class="card"><h2>Pages with no Google appearance (<?= count($none) ?>)</h2>
    <p class="s">Your website has these pages, but Google never showed them in this period. Fix: add links to them (menu, home, related products), give each a Google title and description, or ignore if the page is not important.<?= count($gp) >= 1000 ? ' Google’s file holds only 1,000 pages, so a few listed here may still appear in Google.' : '' ?></p>
    <?php foreach (array_slice($none, 0, 40) as $r): ?><div class="brow" style="margin:4px 0"><span class="tag"><?= seo_h($r['type']) ?></span><a href="<?= seo_h($r['url']) ?>" target="_blank" rel="noopener" style="word-break:break-all"><?= seo_h(gsc_path($r['url'])) ?></a></div><?php endforeach; ?>
    <?php if (count($none) > 40): ?><div class="mut" style="padding-top:6px">…and <?= count($none) - 40 ?> more.</div><?php endif; ?></div><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<p class="mut">All numbers come from Google Search Console and your own website check. Google ranking changes daily, so look at the trend over weeks, not one day.</p>
</div>
<script>
document.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;var t=b.getAttribute('data-copy'),o=b.textContent;
function done(){b.textContent='Copied ✓';setTimeout(function(){b.textContent=o},1600)}
if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done,fb)}else fb();
function fb(){var a=document.createElement('textarea');a.value=t;a.style.position='fixed';a.style.opacity='0';document.body.appendChild(a);a.select();try{document.execCommand('copy');done()}catch(x){}document.body.removeChild(a)}});
</script>
</body></html>
