<?php
/* TM SEO — what the website check found, in plain words, with what to do for each.
   Open:  https://fourpirates.space/analytics/seo_issues3.php   (same view key as report2.php)
   Start with the red ones. Fix a few every day, then press "Check again" on the check page to see them disappear. */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/seo_lib2.php';
$CFG = require __DIR__ . '/config.php';
$SEO = require __DIR__ . '/seo_config2.php';

$RK = (string)($CFG['viewkey'] ?? '');
$dash = preg_match('/^[A-Za-z0-9_\-]+\.php$/', (string)($_COOKIE['tm_dash'] ?? '')) ? (string)$_COOKIE['tm_dash'] : 'report4.php';   // the name you gave the dashboard file (set by the dashboard itself)
if ($RK === '') { http_response_code(403); echo 'Add a viewkey line to config.php first.'; exit; }
$tok = hash('sha256', 'tmrep|' . $RK);
$k = (string)($_GET['k'] ?? '');
$kOk = $k !== '' && hash_equals($RK, $k);
$cOk = hash_equals($tok, (string)($_COOKIE['tm_rep'] ?? ''));
if (!$kOk && !$cOk) { http_response_code(403); echo 'Open this page with your key:  seo_issues3.php?k=YOUR_VIEW_KEY'; exit; }
if ($kOk) {
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
  setcookie('tm_rep', $tok, ['expires' => time() + 86400 * 60, 'path' => $dir, 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
  $keep = array_diff_key($_GET, ['k' => 1]);
  header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'seo_issues3.php') . ($keep ? '?' . http_build_query($keep) : ''));
  exit;
}
$o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $db = new PDO($CFG['dsn'], $CFG['dbuser'] ?? null, $CFG['dbpass'] ?? null, $o); seo_install($db); }
catch (Throwable $e) { http_response_code(500); echo 'Database not reachable.'; exit; }

$stores = array_keys($SEO['domains']);
$store = in_array($_GET['store'] ?? '', $stores, true) ? (string)$_GET['store'] : $stores[0];
$R = seo_issues($db, $store, $SEO);
$domain = rtrim((string)$SEO['domains'][$store], '/');
$names = ['urgent' => 'Fix first', 'medium' => 'Fix next', 'low' => 'Fix later'];
$say = $R['score'] >= 85 ? 'Good' : ($R['score'] >= 65 ? 'Getting there' : 'Needs work');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SEO problems</title>
<style>
:root{--bg:#fdf8f1;--ink:#241c15;--mut:#8a8176;--line:#eadfce;--g:#1b7a3d;--o:#e8761c;--r:#d93636}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:900px;margin:0 auto;padding:22px 16px 60px}h1{font-size:28px;margin:0}.sub{color:var(--mut);margin:2px 0 14px;font-size:15px}
.big{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:10px;margin-bottom:14px}
.k{background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px 16px}.k small{display:block;color:var(--mut);font-size:13px}.k b{font-size:30px;line-height:1.15}
.k.hero{background:var(--g);color:#fff;border-color:var(--g)}.k.hero.mid{background:var(--o);border-color:var(--o)}.k.hero.bad{background:var(--r);border-color:var(--r)}.k.hero small{color:#fff;opacity:.9}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;margin-bottom:10px;overflow:hidden}
.card>summary{list-style:none;cursor:pointer;padding:14px 16px;display:flex;gap:10px;align-items:center}.card>summary::-webkit-details-marker{display:none}
.pill{flex:none;padding:2px 11px;border-radius:999px;font-size:12px;font-weight:700;color:#fff}.urgent{background:var(--r)}.medium{background:var(--o)}.low{background:#a39b90}
.card>summary .t{flex:1;font-weight:700}.card>summary .n{flex:none;font-weight:700;font-size:20px}
.body{padding:0 16px 14px}.how{background:#faf3e6;border-radius:12px;padding:10px 12px;font-size:14px;margin:0 0 10px}
.it{padding:7px 0;border-top:1px solid #f4ece0;font-size:14px;word-break:break-word}.it a{color:var(--g)}.it span{color:var(--mut);display:block;font-size:13px}
.btn{display:inline-block;background:var(--g);color:#fff;border-radius:999px;padding:9px 18px;font-weight:700;text-decoration:none;margin:4px 8px 4px 0;font-size:14px}.btn.alt{background:#fff;color:var(--g);border:2px solid var(--g)}
.empty{background:#fff;border:1px solid var(--line);border-radius:18px;padding:18px}.mut{color:var(--mut);font-size:14px}
</style></head><body><div class="wrap">
<h1>SEO problems</h1>
<div class="sub"><?= seo_h($store) ?> · <?= seo_h($domain) ?><?= $R['last'] ? ' · checked ' . seo_h(date('j M, g:i A', strtotime((string)$R['last']))) : '' ?></div>
<p><a class="btn" href="seo_crawl3.php?store=<?= urlencode($store) ?>">Check again</a><a class="btn alt" href="<?= seo_h($dash) ?>?store=<?= urlencode($store) ?>#seo">← Dashboard</a></p>
<?php if ($R['pages'] === 0): ?>
  <div class="empty"><b>No check has been done yet.</b><p>Press “Check again”, then “Start new check”. It takes about 5–10 minutes and you can leave it running.</p></div>
<?php else: ?>
  <div class="big">
    <div class="k hero <?= $R['score'] >= 85 ? '' : ($R['score'] >= 65 ? 'mid' : 'bad') ?>"><small>Website health (our own simple score)</small><b><?= (int)$R['score'] ?>/100</b><div style="font-size:13px;opacity:.9"><?= seo_h($say) ?></div></div>
    <div class="k"><small>Pages checked</small><b><?= (int)$R['pages'] ?></b></div>
    <div class="k"><small>Fix first</small><b style="color:var(--r)"><?= (int)$R['sev']['urgent'] ?></b></div>
    <div class="k"><small>Fix next</small><b style="color:var(--o)"><?= (int)$R['sev']['medium'] ?></b></div>
  </div>
  <?php if (!$R['groups']): ?><div class="empty"><b>Nothing found.</b> Every page passed the checks.</div><?php endif; ?>
  <?php $firstOpen = true; foreach ($R['groups'] as $g): ?>
    <details class="card"<?= $firstOpen ? ' open' : '' ?>>
      <summary><span class="pill <?= seo_h($g['sev']) ?>"><?= seo_h($names[$g['sev']]) ?></span><span class="t"><?= seo_h($g['title']) ?></span><span class="n"><?= (int)$g['count'] ?></span></summary>
      <div class="body">
        <div class="how"><b>What to do:</b> <?= seo_h($g['how']) ?></div>
        <?php foreach (array_slice($g['items'], 0, 25) as $it): $u = (string)($it['url'] ?? ''); ?>
          <div class="it"><a href="<?= seo_h($u) ?>" target="_blank" rel="noopener"><?= seo_h(parse_url($u, PHP_URL_PATH) ?: $u) ?></a><span><?= seo_h($it['note'] ?? '') ?></span></div>
        <?php endforeach; ?>
        <?php if (count($g['items']) > 25): ?><div class="mut" style="padding-top:8px">…and <?= count($g['items']) - 25 ?> more. Fix these first, then check again.</div><?php endif; ?>
      </div>
    </details>
  <?php $firstOpen = false; endforeach; ?>
<?php endif; ?>
<p class="mut">The score is our own simple measure of how clean your pages are. It is not Google’s ranking. Real Google ranking and keywords come from Search Console in a later step.</p>
</div></body></html>
