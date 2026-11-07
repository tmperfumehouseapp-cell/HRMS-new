<?php
/* TM SEO — runs the website check.   Open:  https://fourpirates.space/analytics/seo_crawl3.php   (same view key as report2.php)
   It reads your public sitemap, opens every page like Google would, and saves what it finds. It never changes your website.
   Press "Start new check"; the page keeps going by itself until it says Finished (about 5–10 minutes for 700 pages).
   Optional: in Hostinger → Advanced → Cron Jobs, run every night:   curl -s "https://fourpirates.space/analytics/seo_crawl3.php?k=YOUR_VIEW_KEY&cron=1&store=tmperfumehouse" */
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
$dash = preg_match('/^[A-Za-z0-9_\-]+\.php$/', (string)($_COOKIE['tm_dash'] ?? '')) ? (string)$_COOKIE['tm_dash'] : 'report4.php';   // the name you gave the dashboard file (set by the dashboard itself)
if ($RK === '') { http_response_code(403); echo 'Add a viewkey line to config.php first.'; exit; }
$tok = hash('sha256', 'tmrep|' . $RK);
$k = (string)($_GET['k'] ?? '');
$kOk = $k !== '' && hash_equals($RK, $k);
$cOk = hash_equals($tok, (string)($_COOKIE['tm_rep'] ?? ''));
if (!$kOk && !$cOk) { http_response_code(403); echo 'Open this page with your key:  seo_crawl3.php?k=YOUR_VIEW_KEY'; exit; }
$cron = isset($_GET['cron']);
if ($kOk && !$cron) {
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
  setcookie('tm_rep', $tok, ['expires' => time() + 86400 * 60, 'path' => $dir, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
  $keep = array_diff_key($_GET, ['k' => 1]);
  header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'seo_crawl3.php') . ($keep ? '?' . http_build_query($keep) : ''));
  exit;
}

$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $db = new PDO($CFG['dsn'], $CFG['dbuser'] ?? null, $CFG['dbpass'] ?? null, $o); seo_install($db); }
catch (Throwable $e) { http_response_code(500); echo 'Database not reachable.'; exit; }

$stores = array_keys($SEO['domains']);
$store = in_array($_GET['store'] ?? '', $stores, true) ? (string)$_GET['store'] : $stores[0];

/* start over: forget the list of pages to check (the old results stay until each page is checked again) */
if (isset($_GET['start'])) { seo_x($db, "DELETE FROM seo_queue WHERE store=?", [$store]); }

/* one go */
$run = isset($_GET['run']) || isset($_GET['start']) || $cron;
$pr = null;
if ($run) { try { $pr = seo_step($db, $store, $SEO, $SEO); } catch (Throwable $e) { $pr = ['phase' => 'error', 'msg' => $e->getMessage()]; } }
else { $pr = seo_progress($db, $store, (int)seo_q($db, "SELECT COUNT(*) c FROM seo_queue WHERE store=?", [$store])[0]['c'] ? 'idle' : 'new'); }

if ($cron) { header('Content-Type: text/plain; charset=utf-8'); echo json_encode($pr), "\n"; exit; }
header('Content-Type: text/html; charset=utf-8');
$phase = (string)$pr['phase'];
$busy = in_array($phase, ['found', 'pages', 'links'], true);
$pt = (int)($pr['pages_total'] ?? 0); $pd = (int)($pr['pages_done'] ?? 0); $lt = (int)($pr['links_total'] ?? 0); $ld = (int)($pr['links_done'] ?? 0);
$total = $pt + $lt; $done = $pd + $ld; $pct = $total > 0 ? (int)floor($done / $total * 100) : 0;
$label = ['new' => 'Not started', 'idle' => 'Stopped', 'found' => 'Found the pages, now opening them…', 'pages' => 'Opening every page…', 'links' => 'Checking the links…', 'done' => 'Finished', 'error' => 'Problem'][$phase] ?? $phase;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Website check</title>
<?php if ($busy): ?><meta http-equiv="refresh" content="1;url=?<?= seo_h(http_build_query(['store' => $store, 'run' => 1])) ?>"><?php endif; ?>
<style>
:root{--bg:#fdf8f1;--ink:#241c15;--mut:#8a8176;--line:#eadfce;--g:#1b7a3d;--o:#e8761c;--r:#d93636}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:720px;margin:0 auto;padding:22px 16px 60px}h1{font-size:28px;margin:0}.sub{color:var(--mut);margin:2px 0 16px}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:16px 18px;margin-bottom:12px}
.bar{height:14px;background:#eadfca;border-radius:9px;overflow:hidden;margin:10px 0}.bar i{display:block;height:100%;background:var(--o);width:<?= $pct ?>%}
.btn{display:inline-block;background:var(--g);color:#fff;border-radius:999px;padding:11px 22px;font-weight:700;text-decoration:none;margin:4px 8px 4px 0}.btn.alt{background:#fff;color:var(--g);border:2px solid var(--g)}
.mut{color:var(--mut);font-size:14px}.err{color:var(--r);font-weight:700}
</style></head><body><div class="wrap">
<h1>Website check</h1>
<div class="sub"><?= seo_h($store) ?> · <?= seo_h($SEO['domains'][$store]) ?></div>
<div class="card">
  <b><?= seo_h($label) ?></b>
  <?php if ($phase === 'error'): ?><p class="err"><?= seo_h($pr['msg'] ?? '') ?></p><?php endif; ?>
  <?php if ($total > 0): ?>
    <div class="bar"><i></i></div>
    <div class="mut"><?= $pd ?> of <?= $pt ?> pages opened · <?= $ld ?> of <?= $lt ?> extra links checked · <?= $pct ?>%</div>
  <?php endif; ?>
  <p>
    <?php if ($busy): ?><span class="mut">Keep this page open. It continues by itself every second.</span>
    <?php else: ?><a class="btn" href="?<?= seo_h(http_build_query(['store' => $store, 'start' => 1])) ?>"><?= $phase === 'done' ? 'Check again' : 'Start new check' ?></a>
      <?php if ($phase === 'idle'): ?><a class="btn alt" href="?<?= seo_h(http_build_query(['store' => $store, 'run' => 1])) ?>">Continue</a><?php endif; ?>
    <?php endif; ?>
    <a class="btn alt" href="seo_issues3.php?store=<?= urlencode($store) ?>">See what was found</a>
    <a class="btn alt" href="<?= seo_h($dash) ?>?store=<?= urlencode($store) ?>#seo">← Dashboard</a>
  </p>
</div>
<p class="mut">This only reads your public pages, like Google does. It does not change anything on your website.</p>
</div></body></html>
