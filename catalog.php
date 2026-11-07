<?php
// api/catalog.php — grouped product catalog for the Request grid.
// Returns products grouped by base title, with sizes, store stock, best-seller order, category list.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
$user = require_login(true);
$role = $user['role'] ?? '';
if (!in_array($role, ['store', 'admin'], true)) json_out(['ok' => false, 'error' => 'Forbidden'], 403);

// ── Serve from cache when fresh (rebuilt at most once per TTL) ──
$CACHE_FILE = __DIR__ . '/../cache/catalog.json';
$CACHE_TTL  = 120; // seconds
$forceFresh = isset($_GET['fresh']);
if (!$forceFresh && is_file($CACHE_FILE) && (time() - filemtime($CACHE_FILE) < $CACHE_TTL)) {
    $cached = @file_get_contents($CACHE_FILE);
    if ($cached !== false && $cached !== '') {
        header('Content-Type: application/json');
        header('X-Catalog-Cache: hit');
        echo $cached;
        exit;
    }
}

$rows = $pdo->query("
  SELECT p.id, p.title, p.variant_title, p.category, p.source, p.serialized, p.image_url,
         COALESCE(inv.qty, 0)  AS inv_qty,
         COALESCE(su.cnt, 0)   AS su_cnt,
         COALESCE(sold.qty, 0) AS sold_qty
  FROM products p
  LEFT JOIN inventory inv ON inv.product_id = p.id
  LEFT JOIN (SELECT product_id, COUNT(*) AS cnt FROM stock_units WHERE status='in_stock' GROUP BY product_id) su
         ON su.product_id = p.id
  LEFT JOIN (SELECT product_id, SUM(qty) AS qty FROM order_items GROUP BY product_id) sold
         ON sold.product_id = p.id
  WHERE p.status = 1
")->fetchAll();

$catsTbl = $pdo->query("SELECT name FROM categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);

function ml_num($v){ if (preg_match('/(\d+)\s*ml/i', (string)$v, $m)) return (int)$m[1]; if (preg_match('/^\d+$/', trim((string)$v))) return (int)$v; return null; }
function size_label($v){ $v = trim((string)$v); if ($v === '') return 'Add'; if (preg_match('/(\d+)\s*ml/i', $v, $m)) return $m[1]; return $v; }
// Trim long Shopify titles down to the perfume name: cut at the first separator, drop the brand.
function clean_title($t){
    $t = (string)$t;
    // cut at the first separator (removes "- X Inspired perfume | ..." tails)
    $cut = null;
    foreach ([' - ', ' – ', ' — ', ' | ', ' / '] as $sep) {
        $p = mb_strpos($t, $sep);
        if ($p !== false) $cut = ($cut === null) ? $p : min($cut, $p);
    }
    if ($cut !== null) $t = mb_substr($t, 0, $cut);
    // strip any lingering brand mention, however it's written
    $t = preg_replace('/\s*[|\-–—\/]?\s*TM\s*Perfume\s*House\b\.?/iu', '', $t);
    $t = preg_replace('/\s*[|\-–—\/]?\s*TMPH\b\.?/iu', '', $t);
    // trim stray separators left at the ends
    $t = trim($t, " \t\n\r\0\x0B|-–—/");
    return trim($t);
}

$byKey = [];
foreach ($rows as $r) {
    $cat = ($r['category'] !== null && $r['category'] !== '') ? $r['category'] : ($r['source'] === 'shopify' ? 'Perfumes' : 'Uncategorized');
    $title = $r['title'];
    $k = $cat . '||' . $title;
    if (!isset($byKey[$k])) $byKey[$k] = ['title' => clean_title($title), 'category' => $cat, 'image' => null, 'manual' => ($r['source'] === 'manual'), 'sales' => 0, 'smap' => []];
    if (!$byKey[$k]['image'] && $r['image_url']) $byKey[$k]['image'] = $r['image_url'];
    $stock = ((int)$r['serialized'] === 1) ? (int)$r['su_cnt'] : (int)$r['inv_qty'];
    $byKey[$k]['sales'] += (int)$r['sold_qty'];
    // merge duplicate size variants by label (sum stock, keep first product_id)
    $lab = size_label($r['variant_title']);
    if (!isset($byKey[$k]['smap'][$lab])) {
        $byKey[$k]['smap'][$lab] = ['id' => (int)$r['id'], 'label' => $lab, 'ml' => ml_num($r['variant_title']), 'stock' => 0];
    }
    $byKey[$k]['smap'][$lab]['stock'] += $stock;
}

// flatten + sort sizes within each product (ml ascending, then alpha)
foreach ($byKey as &$p) {
    $p['sizes'] = array_values($p['smap']); unset($p['smap']);
    usort($p['sizes'], function ($a, $b) {
        if ($a['ml'] !== null && $b['ml'] !== null) return $a['ml'] - $b['ml'];
        if ($a['ml'] !== null) return -1;
        if ($b['ml'] !== null) return 1;
        return strcmp($a['label'], $b['label']);
    });
}
unset($p);

// ordered category list: table order first, then any extras actually present
$present = [];
foreach ($byKey as $p) $present[$p['category']] = true;
$cats = [];
foreach ($catsTbl as $c) if (isset($present[$c])) $cats[] = $c;
foreach (array_keys($present) as $c) if (!in_array($c, $cats, true)) $cats[] = $c;

// group by category (in cats order), best-sellers first within each
$products = array_values($byKey);
usort($products, function ($a, $b) use ($cats) {
    $ia = array_search($a['category'], $cats); $ib = array_search($b['category'], $cats);
    if ($ia !== $ib) return $ia - $ib;
    return $b['sales'] - $a['sales'];
});

// mark the top seller (first with sales > 0) per category
$seen = [];
foreach ($products as &$p) {
    $p['topseller'] = false;
    if (!isset($seen[$p['category']]) && $p['sales'] > 0) { $p['topseller'] = true; $seen[$p['category']] = true; }
}
unset($p);

$out = ['ok' => true, 'categories' => $cats, 'products' => $products];

// write cache (best-effort; never block the response if it fails)
$json = json_encode($out);
if ($json !== false) {
    $dir = dirname($CACHE_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($CACHE_FILE, $json, LOCK_EX);
}
header('X-Catalog-Cache: miss');
json_out($out);
