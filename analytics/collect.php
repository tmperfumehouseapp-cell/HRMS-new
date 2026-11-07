<?php
/* TM Analytics collector — receives beacons from tm-analytics.liquid and tm-analytics-pixel.js and
   adds them to daily counters. Always answers 204 (nothing for the visitor's browser to wait on). */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
 
$CFG = require __DIR__ . '/config.php';
 
function s($v, int $n): string { return mb_substr(trim((string)($v ?? '')), 0, $n); }
function n($v): int { return max(0, min(1000000, (int)$v)); }
function logerr(array $CFG, string $m): void {
  $f = $CFG['log'] ?? '';
  if ($f && (!is_file($f) || filesize($f) < 200000)) @file_put_contents($f, date('c') . ' ' . $m . "\n", FILE_APPEND);
}
function db(array $CFG): PDO {
  $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
  if (strpos($CFG['dsn'], 'mysql:') === 0) $o[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
  return new PDO($CFG['dsn'], $CFG['dbuser'] ?? null, $CFG['dbpass'] ?? null, $o);
}
/* add counters to a row (create it if missing). Table/column names come only from this file. */
function add(PDO $db, string $t, array $keys, array $adds, array $init = []): void {
  $adds = array_filter($adds, function ($v) { return $v != 0; });
  if (!$adds) return;
  $set = implode(',', array_map(function ($c) { return "$c=$c+?"; }, array_keys($adds)));
  $where = implode(' AND ', array_map(function ($c) { return "$c=?"; }, array_keys($keys)));
  $upd = $db->prepare("UPDATE $t SET $set WHERE $where");
  $args = array_merge(array_values($adds), array_values($keys));
  $upd->execute($args);
  if ($upd->rowCount() > 0) return;
  $row = array_merge($keys, $init, $adds);
  $ins = $db->prepare("INSERT INTO $t (" . implode(',', array_keys($row)) . ") VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")");
  try { $ins->execute(array_values($row)); }
  catch (PDOException $e) { $upd->execute($args); }   // another request created it first
}
 
/* ── health check (for you): collect.php?health=1&k=YOUR_KEY  ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['health'])) {
  header('Content-Type: application/json');
  if (!hash_equals($CFG['key'], (string)($_GET['k'] ?? ''))) { http_response_code(403); echo '{"ok":false}'; exit; }
  try {
    $db = db($CFG); $d = date('Y-m-d'); $out = ['ok' => true, 'today' => $d];
    foreach (['an_visitors', 'an_pagestats', 'an_elements', 'an_products', 'an_funnel'] as $t) {
      $st = $db->prepare("SELECT COUNT(*) c FROM $t WHERE day=?"); $st->execute([$d]); $out[$t] = (int)$st->fetch()['c'];
    }
    echo json_encode($out);
  } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'db']); logerr($CFG, 'health: ' . $e->getMessage()); }
  exit;
}
 
/* ── receive ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(204); exit; }
$raw = file_get_contents('php://input', false, null, 0, 65537);
$in = ($raw !== false && strlen($raw) <= 65536) ? json_decode($raw, true) : null;
http_response_code(204);
if (!is_array($in) || ($in['v'] ?? 0) !== 1 || !hash_equals($CFG['key'], (string)($in['k'] ?? ''))) exit;
$store = s($in['st'] ?? '', 20);
if (!in_array($store, $CFG['stores'], true)) exit;
if (preg_match('/bot|crawl|spider|headless|lighthouse|pagespeed|gtmetrix|pingdom/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) exit;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();   // visitor's browser is released now
 
try {
  $db = db($CFG);
  $db->beginTransaction();
  $day = date('Y-m-d');
  $K = ['day' => $day, 'store' => $store];
 
  if (($in['kind'] ?? '') === 'pixel') {
    $step = s($in['type'] ?? '', 32);
    $ok = ['product_viewed', 'collection_viewed', 'search_submitted', 'product_added_to_cart', 'cart_viewed', 'checkout_started', 'payment_info_submitted', 'checkout_completed'];
    $dt = is_array($in['data'] ?? null) ? $in['data'] : [];
    if (in_array($step, $ok, true)) {
      $items = isset($dt['items']) && is_array($dt['items']) ? array_slice($dt['items'], 0, 20) : [];
      $qty = 0; foreach ($items as $it) $qty += n($it['q'] ?? 1);
      if (!$items && isset($dt['q']) && is_numeric($dt['q'])) $qty = n($dt['q']);
      add($db, 'an_funnel', $K + ['step' => $step], ['n' => 1, 'qty' => $qty, 'value_sum' => (float)($dt['v'] ?? ($dt['pr'] ?? 0))]);
      foreach ($items as $it) if (!empty($it['h'])) add($db, 'an_pixprod', $K + ['step' => $step, 'handle' => s($it['h'], 120)], ['n' => 1, 'qty' => n($it['q'] ?? 1), 'value_sum' => (float)($it['pr'] ?? 0) * n($it['q'] ?? 1)]);
      if (!$items && !empty($dt['h'])) add($db, 'an_pixprod', $K + ['step' => $step, 'handle' => s($dt['h'], 120)], ['n' => 1, 'qty' => n($dt['q'] ?? 1), 'value_sum' => (float)($dt['pr'] ?? 0)]);
      if ($step === 'search_submitted' && s($dt['q'] ?? '', 60) !== '') {
        $q = mb_strtolower(s($dt['q'], 60));
        add($db, 'an_search', $K + ['q' => $q], ['searches' => 1, 'zero' => ((int)($dt['n'] ?? 1) === 0) ? 1 : 0]);
      }
    }
    $db->commit();
    exit;
  }
 
  /* page beacon */
  $p   = is_array($in['page'] ?? null) ? $in['page'] : [];
  $dev = is_array($in['dev'] ?? null) ? $in['dev'] : [];
  $sr  = is_array($in['src'] ?? null) ? $in['src'] : [];
  $d   = is_array($in['d'] ?? null) ? $in['d'] : [];
  $ptype = s($p['type'] ?? 'other', 20) ?: 'other';
  $dv  = !empty($dev['m']) ? 'm' : 'd';
  $iab = !empty($dev['iab']) ? 1 : 0;
  $src = mb_strtolower(s($sr['u'] ?? '', 40)) ?: (mb_strtolower(s($sr['ref'] ?? '', 40)) ?: 'direct');
  $pv  = !empty($in['pv']) ? 1 : 0;
  $smp = !empty($in['smp']) ? 1 : 0;
  $pr  = s($p['pr'] ?? '', 120);
  $vid = s($in['vid'] ?? '', 32);
 
  $waTotal = 0; $waMap = [];
  if (is_array($d['wa'] ?? null)) foreach (array_slice($d['wa'], 0, 400, true) as $k => $v) { $waMap[s($k, 140)] = n($v); $waTotal += n($v); }
  $atc = n($d['atc'] ?? 0); $err = n($d['err'] ?? 0); $rage = n($d['rage'] ?? 0);
 
  add($db, 'an_pagestats', $K + ['ptype' => $ptype, 'dev' => $dv, 'iab' => $iab, 'src' => $src], [
    'pv' => $pv, 'pv_smp' => ($pv && $smp) ? 1 : 0, 'land' => ($pv && !empty($p['land'])) ? 1 : 0,
    'engaged' => n($d['eng'] ?? 0), 'sc_sum' => n($d['sc'] ?? 0), 'tm_sum' => n($d['tm'] ?? 0),
    'atc' => $atc, 'wa' => $waTotal, 'err' => $err, 'rage' => $rage]);
 
  if ($vid !== '') add($db, 'an_visitors', $K + ['vid' => $vid],
    ['pages' => $pv, 'sess' => !empty($in['ns']) ? 1 : 0, 'atc' => $atc, 'wa' => $waTotal, 'eng' => n($d['eng'] ?? 0)],
    ['nv' => !empty($in['nv']) ? 1 : 0, 'dev' => $dv, 'iab' => $iab, 'src' => $src]);
 
  /* elements (popup buttons and page elements) */
  $els = [];
  if (is_array($d['el'] ?? null)) foreach (array_slice($d['el'], 0, 400, true) as $k => $v) if (is_array($v)) $els[s($k, 140)] = ['s' => n($v[0] ?? 0), 't' => n($v[1] ?? 0)];
  foreach ($waMap as $k => $c) { if (!isset($els[$k])) $els[$k] = ['s' => 0, 't' => 0]; }
  foreach ($els as $k => $v) {
    $ctx = ''; $ek = $k;
    if (strpos($k, 'pop:') === 0 && ($bar = strpos($k, '|')) !== false) { $ctx = s(substr($k, 4, $bar - 4), 16); $ek = substr($k, $bar + 1); }
    add($db, 'an_elements', $K + ['ptype' => $ptype, 'ctx' => $ctx, 'ekey' => s($ek, 120)], ['seen' => $v['s'], 'taps' => $v['t'], 'wa' => $waMap[$k] ?? 0]);
  }
 
  if (is_array($d['dead'] ?? null)) foreach (array_slice($d['dead'], 0, 60, true) as $k => $v) add($db, 'an_dead', $K + ['ptype' => $ptype, 'ekey' => s($k, 120)], ['n' => n($v)]);
 
  /* product cards: how often seen, tapped, and where they sit */
  if (is_array($d['prod'] ?? null)) foreach (array_slice($d['prod'], 0, 300, true) as $h => $v) {
    if (!is_array($v) || $h === '') continue;
    $seen = n($v['s'] ?? 0); $pos = n($v['p'] ?? 0);
    add($db, 'an_products', $K + ['handle' => s($h, 120), 'sec' => s($v['sec'] ?? '', 40)],
      ['seen' => $seen, 'taps' => n($v['t'] ?? 0), 'pos_sum' => ($seen && $pos) ? $pos : 0, 'pos_n' => ($seen && $pos) ? 1 : 0]);
  }
  if ($ptype === 'product' && $pr !== '') add($db, 'an_products', $K + ['handle' => $pr, 'sec' => '_pdp'], ['pdp' => $pv, 'atc' => $atc]);
 
  if ($pr !== '' && is_array($d['photo'] ?? null)) foreach (array_slice($d['photo'], 0, 12, true) as $i => $v)
    if (is_array($v)) add($db, 'an_photos', $K + ['handle' => $pr, 'idx' => min(20, n($i))], ['seen' => n($v[0] ?? 0), 'zoom' => n($v[1] ?? 0)]);
 
  if (is_array($d['flt'] ?? null)) foreach (array_slice($d['flt'], 0, 60, true) as $k => $v) add($db, 'an_filters', $K + ['ptype' => $ptype, 'fkey' => s($k, 80)], ['n' => n($v)]);
  if (is_array($d['ev'] ?? null)) foreach (array_slice($d['ev'], 0, 40, true) as $k => $v) add($db, 'an_events', $K + ['name' => s($k, 40)], ['n' => n($v)]);
  if (!empty($d['q']) && $ptype === 'search') add($db, 'an_search', $K + ['q' => mb_strtolower(s($d['q'], 60))], ['pages' => 1]);
 
  if (is_array($d['vit'] ?? null)) {
    $lcp = n($d['vit']['lcp'] ?? 0); $inp = n($d['vit']['inp'] ?? 0); $cls = (float)($d['vit']['cls'] ?? 0);
    add($db, 'an_vitals', $K + ['ptype' => $ptype, 'dev' => $dv], [
      'n' => 1, 'lcp_sum' => $lcp, 'cls_milli' => (int)round(max(0, min(5, $cls)) * 1000), 'inp_sum' => $inp,
      'lcp_good' => ($lcp > 0 && $lcp <= 2500) ? 1 : 0, 'lcp_poor' => $lcp > 4000 ? 1 : 0, 'inp_poor' => $inp > 500 ? 1 : 0]);
  }
  if (is_array($d['errm'] ?? null)) foreach (array_slice($d['errm'], 0, 3) as $m) if (is_string($m) && $m !== '') add($db, 'an_errors', $K + ['ptype' => $ptype, 'msg' => s($m, 140)], ['n' => 1]);
 
  $db->commit();
} catch (Throwable $e) {
  try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $e2) {}
  logerr($CFG, $e->getMessage());
}
 