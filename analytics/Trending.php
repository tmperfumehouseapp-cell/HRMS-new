<?php
/**
 * TM search trending - logs real searches and returns today's top keywords.
 * Upload to Hostinger, e.g. https://fourpirates.space/analytics/trending.php
 *
 *   GET  trending.php?a=top            -> {"terms":["Blue De Chanel","Aventus Creed",...]}
 *   POST trending.php  a=log&q=term    -> 204 (stored)
 *
 * Needs PHP with pdo_sqlite (standard on Hostinger). Creates ./data/ on first run.
 */

// ===== EDIT HERE =====
$SALT       = 'tmlLEQ2nQq0Q8fcdD';  // used to hash visitor IPs (no raw IPs stored)
$MIN_VOTES  = 1;   // TESTING = 1. After you see Trending on the site, change to 2 (keyword must be searched by 2 different visitors)
$MAX_TERMS  = 8;   // how many trending keywords to return
$MIN_SHOW   = 3;   // fewer than this after the 24h window -> widen to 7 days
$BLOCK      = [];  // words you never want shown, e.g. ['test','xxx']  (lowercase)
// ===== END EDIT =====

header('Content-Type: application/json; charset=utf-8');
// public data, no cookies: allow every origin (also keeps it working when Hostinger caches the response)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ---- debug: open trending.php?a=debug  (add &q=aventus to also store one test search) ----
if (($_GET['a'] ?? '') === 'debug') {
  $r = ['version' => 4, 'php' => PHP_VERSION, 'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'mbstring' => extension_loaded('mbstring'), 'file' => basename(__FILE__),
        'dir' => __DIR__, 'dir_writable' => is_writable(__DIR__), 'data_exists' => is_dir(__DIR__ . '/data'),
        'min_votes' => $MIN_VOTES];
  try {
    $d = __DIR__ . '/data';
    if (!is_dir($d)) { $r['mkdir'] = @mkdir($d, 0755, true); }
    $t = new PDO('sqlite:' . $d . '/trending.sqlite');
    $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t->exec('CREATE TABLE IF NOT EXISTS s (term TEXT NOT NULL, iph TEXT NOT NULL, hr INTEGER NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY (term, iph, hr))');
    if (isset($_GET['q']) && $_GET['q'] !== '') {
      $t->prepare('INSERT OR IGNORE INTO s (term, iph, hr, ts) VALUES (?,?,?,?)')->execute([mb_strtolower(trim($_GET['q'])), 'debug' . substr(sha1((string)microtime(true)), 0, 6), intdiv(time(), 3600), time()]);
      $r['test_row_added'] = true;
    }
    $r['rows_total'] = (int)$t->query('SELECT COUNT(*) FROM s')->fetchColumn();
    $r['rows_last_24h'] = (int)$t->query('SELECT COUNT(*) FROM s WHERE ts >= ' . (time() - 86400))->fetchColumn();
  } catch (Throwable $e) { $r['error'] = $e->getMessage(); }
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($r, JSON_PRETTY_PRINT);
  exit;
}

// ---- database ----
$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
  @mkdir($dir, 0755, true);
  @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
}
try {
  $db = new PDO('sqlite:' . $dir . '/trending.sqlite');
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec('CREATE TABLE IF NOT EXISTS s (
    term TEXT NOT NULL, iph TEXT NOT NULL, hr INTEGER NOT NULL, ts INTEGER NOT NULL,
    PRIMARY KEY (term, iph, hr))');
  $db->exec('CREATE INDEX IF NOT EXISTS s_ts ON s (ts)');
} catch (Throwable $e) { http_response_code(500); echo '{"terms":[]}'; exit; }

function clean_term($raw) {
  $t = mb_strtolower(trim((string)$raw), 'UTF-8');
  $t = preg_replace('/[^\p{L}\p{N} \'&\-]+/u', ' ', $t);
  $t = trim(preg_replace('/\s+/u', ' ', $t));
  if (mb_strlen($t, 'UTF-8') < 3 || mb_strlen($t, 'UTF-8') > 40) return '';
  if (preg_match('/^\d+$/', $t)) return '';
  return $t;
}

$action = $_REQUEST['a'] ?? 'top';

// ---- log a search ----
if ($action === 'log' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $term = clean_term($_POST['q'] ?? '');
  if ($term === '' || in_array($term, $BLOCK, true)) { http_response_code(204); exit; }
  $ip   = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0';
  $iph  = substr(sha1($SALT . $ip), 0, 16);
  $now  = time();
  $hr   = intdiv($now, 3600);
  // max 30 different searches per visitor per hour (spam guard)
  $c = $db->prepare('SELECT COUNT(*) FROM s WHERE iph = ? AND hr = ?');
  $c->execute([$iph, $hr]);
  if ((int)$c->fetchColumn() < 30) {
    $i = $db->prepare('INSERT OR IGNORE INTO s (term, iph, hr, ts) VALUES (?,?,?,?)');
    $i->execute([$term, $iph, $hr, $now]);
  }
  if (random_int(1, 100) === 1) $db->prepare('DELETE FROM s WHERE ts < ?')->execute([$now - 14 * 86400]);
  http_response_code(204); exit;
}

// ---- top keywords ----
function top_terms($db, $since, $min, $max, $block) {
  $q = $db->prepare('SELECT term, COUNT(DISTINCT iph) AS v, MAX(ts) AS last
                     FROM s WHERE ts >= ? GROUP BY term HAVING v >= ?
                     ORDER BY v DESC, last DESC LIMIT 30');
  $q->bindValue(1, (int)$since, PDO::PARAM_INT);
  $q->bindValue(2, (int)$min, PDO::PARAM_INT);
  $q->execute();
  $out = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (in_array($r['term'], $block, true)) continue;
    $out[] = mb_convert_case($r['term'], MB_CASE_TITLE, 'UTF-8');
    if (count($out) >= $max) break;
  }
  return $out;
}
$now   = time();
$terms = top_terms($db, $now - 86400, $MIN_VOTES, $MAX_TERMS, $BLOCK);
if (count($terms) < $MIN_SHOW) $terms = top_terms($db, $now - 7 * 86400, $MIN_VOTES, $MAX_TERMS, $BLOCK);

header('Cache-Control: public, max-age=600');
echo json_encode(['terms' => $terms], JSON_UNESCAPED_UNICODE);