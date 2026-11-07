<?php
/* TM SEO — shared code: database tables, the crawler step, and the list of problems found.
   Used by seo_crawl2.php (runs the check) and seo_issues2.php (shows the result). Opening these pages changes nothing on your website. */
declare(strict_types=1);

function seo_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function seo_q(PDO $db, string $sql, array $a = []): array { $s = $db->prepare($sql); $s->execute($a); return $s->fetchAll(); }
function seo_x(PDO $db, string $sql, array $a = []): void { $s = $db->prepare($sql); $s->execute($a); }
function seo_md5(string $u): string { return md5($u); }

function seo_install(PDO $db): void {
  $lite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $tail = $lite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  $db->exec("CREATE TABLE IF NOT EXISTS seo_queue (store VARCHAR(30) NOT NULL, h CHAR(32) NOT NULL, url VARCHAR(600) NOT NULL, type VARCHAR(20) NOT NULL, done TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (store, h))$tail");
  $db->exec("CREATE TABLE IF NOT EXISTS seo_pages (store VARCHAR(30) NOT NULL, h CHAR(32) NOT NULL, url VARCHAR(600) NOT NULL, type VARCHAR(20) NOT NULL, status INT NOT NULL, final_url VARCHAR(600) NOT NULL, hops INT NOT NULL DEFAULT 0,
    title VARCHAR(400) NOT NULL, meta VARCHAR(700) NOT NULL, h1 INT NOT NULL DEFAULT 0, canonical VARCHAR(600) NOT NULL, noindex TINYINT NOT NULL DEFAULT 0, imgs INT NOT NULL DEFAULT 0, noalt INT NOT NULL DEFAULT 0,
    words INT NOT NULL DEFAULT 0, ms INT NOT NULL DEFAULT 0, checked VARCHAR(19) NOT NULL, PRIMARY KEY (store, h))$tail");
  $db->exec("CREATE TABLE IF NOT EXISTS seo_links (store VARCHAR(30) NOT NULL, from_h CHAR(32) NOT NULL, to_h CHAR(32) NOT NULL, to_url VARCHAR(600) NOT NULL, PRIMARY KEY (store, from_h, to_h))$tail");
  $db->exec("CREATE TABLE IF NOT EXISTS seo_linkchk (store VARCHAR(30) NOT NULL, h CHAR(32) NOT NULL, url VARCHAR(600) NOT NULL, status INT NULL, hops INT NOT NULL DEFAULT 0, final_url VARCHAR(600) NOT NULL DEFAULT '', checked VARCHAR(19) NOT NULL DEFAULT '', PRIMARY KEY (store, h))$tail");
  $db->exec("CREATE TABLE IF NOT EXISTS seo_flags (store VARCHAR(30) NOT NULL, from_h CHAR(32) NOT NULL, kind VARCHAR(20) NOT NULL, dh CHAR(32) NOT NULL, detail VARCHAR(500) NOT NULL, PRIMARY KEY (store, from_h, kind, dh))$tail");
}

/* ───────── fetching ───────── */
function seo_fetch(string $url, array $cfg, bool $head = false): array {   // [status, body, final_url, hops, ms]
  $t = microtime(true);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6,
    CURLOPT_TIMEOUT => (int)$cfg['timeout'], CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => (string)$cfg['ua'],
    CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_NOBODY => $head,
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xml;q=0.9,*/*;q=0.8'],
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  $hops = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
  curl_close($ch);
  return [$body === false ? 0 : $code, $body === false ? '' : (string)$body, $final, $hops, (int)round((microtime(true) - $t) * 1000)];
}

function seo_norm(string $href, string $base, string $host): ?string {   // absolute internal address without #fragment and ?query, or null if not an internal page link
  $href = trim(html_entity_decode($href));
  if ($href === '' || $href[0] === '#') return null;
  if (preg_match('#^(mailto:|tel:|javascript:|data:|sms:|whatsapp:)#i', $href)) return null;
  if (strpos($href, '//') === 0) $href = 'https:' . $href;
  if (!preg_match('#^https?://#i', $href)) {
    $b = parse_url($base); if (!$b || empty($b['host'])) return null;
    $root = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if ($href[0] === '/') $href = $root . $href;
    else { $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/'); $href = $root . $dir . $href; }
  }
  $p = parse_url($href); if (!$p || empty($p['host'])) return null;
  if (strcasecmp($p['host'], $host) !== 0) return null;
  $path = $p['path'] ?? '/'; if ($path === '') $path = '/';
  return ($p['scheme'] ?? 'https') . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
}

/* ───────── one step of the crawl. Returns progress ───────── */
function seo_step(PDO $db, string $store, array $cfg, array $shopCfg): array {
  $domain = rtrim((string)($shopCfg['domains'][$store] ?? ''), '/');
  if ($domain === '') return ['phase' => 'error', 'msg' => 'No address for this store in seo_config2.php'];
  $host = (string)parse_url($domain, PHP_URL_HOST);
  $end = microtime(true) + (float)$shopCfg['time_budget'];

  /* phase 1: find every page from the sitemap */
  $has = (int)seo_q($db, "SELECT COUNT(*) c FROM seo_queue WHERE store=?", [$store])[0]['c'];
  if ($has === 0) {
    $urls = [$domain . '/'];
    [$sc, $xml] = seo_fetch($domain . '/sitemap.xml', $shopCfg);
    if ($sc !== 200 || $xml === '') return ['phase' => 'error', 'msg' => "Could not open $domain/sitemap.xml (status $sc)"];
    libxml_use_internal_errors(true);
    $locs = function (string $x, string $tag): array { $o = []; $s = @simplexml_load_string($x); if (!$s) return $o; foreach ($s->children() as $n) { $l = (string)($n->loc ?? ''); if ($l !== '') $o[] = $l; } return $o; };
    $children = $locs($xml, 'sitemap');
    if ($children && preg_match('#<sitemapindex#i', $xml)) {
      foreach ($children as $c) { [$cs, $cx] = seo_fetch($c, $shopCfg); if ($cs === 200) foreach ($locs($cx, 'url') as $u) $urls[] = $u; }
    } else { foreach ($locs($xml, 'url') as $u) $urls[] = $u; }
    $ins = $db->prepare("INSERT INTO seo_queue (store, h, url, type, done) VALUES (?,?,?,?,0)");
    $seen = [];
    foreach ($urls as $u) {
      $n = seo_norm($u, $domain . '/', $host); if (!$n || isset($seen[$n])) continue; $seen[$n] = 1;
      $path = (string)parse_url($n, PHP_URL_PATH);
      $type = $path === '/' ? 'home' : (strpos($path, '/products/') === 0 ? 'product' : (strpos($path, '/collections/') === 0 ? 'collection' : (strpos($path, '/pages/') === 0 ? 'page' : (strpos($path, '/blogs/') === 0 ? 'blog' : 'other'))));
      $ins->execute([$store, seo_md5($n), $n, $type]);
    }
    seo_x($db, "DELETE FROM seo_links WHERE store=?", [$store]);
    seo_x($db, "DELETE FROM seo_flags WHERE store=?", [$store]);
    seo_x($db, "DELETE FROM seo_linkchk WHERE store=?", [$store]);
    return seo_progress($db, $store, 'found');
  }

  /* phase 2: open each page and read its title, description, headings, images and links */
  $batch = seo_q($db, "SELECT h, url, type FROM seo_queue WHERE store=? AND done=0 LIMIT " . (int)$shopCfg['batch_pages'], [$store]);
  if ($batch) {
    foreach ($batch as $row) {
      if (microtime(true) > $end) break;
      seo_page($db, $store, $row, $host, $shopCfg);
      seo_x($db, "UPDATE seo_queue SET done=1 WHERE store=? AND h=?", [$store, $row['h']]);
    }
    return seo_progress($db, $store, 'pages');
  }

  /* phase 3: check the pages that are linked but were not in the sitemap */
  $skip = (string)$shopCfg['skip_paths'];
  $todo = seo_q($db, "SELECT DISTINCT l.to_h h, l.to_url url FROM seo_links l
      WHERE l.store=? AND l.to_h NOT IN (SELECT h FROM seo_pages WHERE store=?) AND l.to_h NOT IN (SELECT h FROM seo_linkchk WHERE store=?) LIMIT " . (int)$shopCfg['batch_links'], [$store, $store, $store]);
  if ($todo) {
    foreach ($todo as $t) {
      if (microtime(true) > $end) break;
      $path = (string)parse_url($t['url'], PHP_URL_PATH);
      if (preg_match($skip, $path) || preg_match('#\.(jpg|jpeg|png|gif|webp|svg|css|js|pdf|mp4|ico|woff2?)$#i', $path)) { seo_x($db, "INSERT INTO seo_linkchk (store,h,url,status,hops,final_url,checked) VALUES (?,?,?,?,?,?,?)", [$store, $t['h'], $t['url'], 200, 0, '', date('Y-m-d H:i:s')]); continue; }
      $f = seo_fetch($t['url'], $shopCfg, true);
      if ($f[0] === 0 || $f[0] === 403 || $f[0] === 405 || $f[0] >= 500) $f = seo_fetch($t['url'], $shopCfg, false);
      seo_x($db, "INSERT INTO seo_linkchk (store,h,url,status,hops,final_url,checked) VALUES (?,?,?,?,?,?,?)", [$store, $t['h'], $t['url'], $f[0], $f[3], mb_substr($f[2], 0, 600), date('Y-m-d H:i:s')]);
    }
    return seo_progress($db, $store, 'links');
  }
  return seo_progress($db, $store, 'done');
}

function seo_progress(PDO $db, string $store, string $phase): array {
  $tot = (int)seo_q($db, "SELECT COUNT(*) c FROM seo_queue WHERE store=?", [$store])[0]['c'];
  $done = (int)seo_q($db, "SELECT COUNT(*) c FROM seo_queue WHERE store=? AND done=1", [$store])[0]['c'];
  $lk = (int)seo_q($db, "SELECT COUNT(DISTINCT to_h) c FROM seo_links WHERE store=? AND to_h NOT IN (SELECT h FROM seo_pages WHERE store=?)", [$store, $store])[0]['c'];
  $lkd = (int)seo_q($db, "SELECT COUNT(*) c FROM seo_linkchk WHERE store=?", [$store])[0]['c'];
  return ['phase' => $phase, 'pages_total' => $tot, 'pages_done' => $done, 'links_total' => $lk, 'links_done' => min($lk, $lkd)];
}

function seo_page(PDO $db, string $store, array $row, string $host, array $cfg): void {
  [$sc, $html, $final, $hops, $ms] = seo_fetch($row['url'], $cfg);
  $title = $meta = $canon = ''; $h1 = 0; $noindex = 0; $imgs = 0; $noalt = 0; $words = 0; $links = []; $flags = [];
  if ($sc >= 200 && $sc < 300 && $html !== '') {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    $xp = new DOMXPath($dom);
    $title = trim(preg_replace('/\s+/u', ' ', (string)$xp->evaluate('string(//title)')));
    $meta = trim(preg_replace('/\s+/u', ' ', (string)$xp->evaluate('string(//meta[translate(@name,"DESCRIPTION","description")="description"]/@content)')));
    $canon = trim((string)$xp->evaluate('string(//link[@rel="canonical"]/@href)'));
    $rob = strtolower((string)$xp->evaluate('string(//meta[translate(@name,"ROBTS","robts")="robots"]/@content)'));
    $noindex = strpos($rob, 'noindex') !== false ? 1 : 0;
    $h1 = (int)$xp->evaluate('count(//h1)');
    $scope = $xp->query('//main')->length ? '//main' : '//body';
    $imgs = (int)$xp->evaluate("count($scope//img)");
    $noalt = (int)$xp->evaluate("count($scope//img[not(@alt) or normalize-space(@alt)=''])");
    foreach ($xp->query('//script|//style|//noscript') as $n) { if ($n->parentNode) $n->parentNode->removeChild($n); }
    $sn = $xp->query($scope)->item(0);
    $words = $sn ? str_word_count(preg_replace('/\s+/u', ' ', (string)$sn->textContent)) : 0;
    $seenL = [];
    foreach ($xp->query('//a[@href]') as $a) {
      $href = (string)$a->getAttribute('href');
      if (preg_match('#(wa\.me/|whatsapp\.com/send)#i', $href)) {
        $phone = '';
        if (preg_match('#wa\.me/([^?/]*)#i', $href, $m)) $phone = rawurldecode($m[1]);
        elseif (preg_match('#[?&]phone=([^&]*)#i', $href, $m)) $phone = rawurldecode($m[1]);
        if (!preg_match('/^\+?\d{10,15}$/', $phone)) $flags[] = ['wa', mb_substr($href, 0, 300)];
        continue;
      }
      $n = seo_norm($href, $final !== '' ? $final : $row['url'], $host);
      if ($n === null || isset($seenL[$n]) || count($seenL) >= 150) continue;
      $seenL[$n] = 1; $links[] = $n;
    }
  }
  $db->beginTransaction();
  seo_x($db, "DELETE FROM seo_pages WHERE store=? AND h=?", [$store, $row['h']]);
  seo_x($db, "INSERT INTO seo_pages (store,h,url,type,status,final_url,hops,title,meta,h1,canonical,noindex,imgs,noalt,words,ms,checked) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
    [$store, $row['h'], $row['url'], $row['type'], $sc, mb_substr($final, 0, 600), $hops, mb_substr($title, 0, 400), mb_substr($meta, 0, 700), $h1, mb_substr($canon, 0, 600), $noindex, $imgs, $noalt, $words, $ms, date('Y-m-d H:i:s')]);
  seo_x($db, "DELETE FROM seo_links WHERE store=? AND from_h=?", [$store, $row['h']]);
  $ins = $db->prepare("INSERT INTO seo_links (store,from_h,to_h,to_url) VALUES (?,?,?,?)");
  foreach ($links as $l) $ins->execute([$store, $row['h'], seo_md5($l), mb_substr($l, 0, 600)]);
  foreach ($flags as $f) { try { seo_x($db, "INSERT INTO seo_flags (store,from_h,kind,dh,detail) VALUES (?,?,?,?,?)", [$store, $row['h'], $f[0], seo_md5($f[1]), $f[1]]); } catch (Throwable $e) {} }
  $db->commit();
}

/* ───────── the list of problems, in plain words ───────── */
function seo_issues(PDO $db, string $store, array $shopCfg): array {
  $skip = (string)$shopCfg['skip_paths'];
  $pages = seo_q($db, "SELECT * FROM seo_pages WHERE store=?", [$store]);
  $byH = []; foreach ($pages as $p) $byH[$p['h']] = $p;
  $ok = array_values(array_filter($pages, function ($p) { return (int)$p['status'] >= 200 && (int)$p['status'] < 300; }));
  $where = ['product' => 'Shopify admin → Products → open the product → scroll to “Search engine listing” → Edit',
            'collection' => 'Shopify admin → Products → Collections → open it → scroll to “Search engine listing” → Edit',
            'page' => 'Shopify admin → Online Store → Pages → open it → “Search engine listing” → Edit',
            'blog' => 'Shopify admin → Online Store → Blog posts → open it → “Search engine listing” → Edit',
            'home' => 'Shopify admin → Online Store → Preferences → Title and meta description',
            'other' => 'Shopify admin → Online Store → Pages → Search engine listing'];
  $path = function ($u) { $p = (string)parse_url($u, PHP_URL_PATH); return $p === '' ? '/' : $p; };
  $G = [];
  $add = function (string $sev, string $key, string $title, string $how, array $items) use (&$G) { if ($items) $G[] = ['sev' => $sev, 'key' => $key, 'title' => $title, 'how' => $how, 'items' => array_values($items), 'count' => count($items)]; };

  /* broken addresses: sitemap pages that fail, and links that point to nothing */
  $brokenT = [];
  foreach ($pages as $p) if ((int)$p['status'] >= 400 || (int)$p['status'] === 0) $brokenT[$p['h']] = [$p['url'], (int)$p['status']];
  foreach (seo_q($db, "SELECT h, url, status FROM seo_linkchk WHERE store=? AND (status>=400 OR status=0)", [$store]) as $r) $brokenT[$r['h']] = [$r['url'], (int)$r['status']];
  $items = [];
  foreach ($brokenT as $h => [$u, $s]) {
    if (preg_match($skip, $path($u))) continue;
    $from = seo_q($db, "SELECT DISTINCT p.url FROM seo_links l JOIN seo_pages p ON p.store=l.store AND p.h=l.from_h WHERE l.store=? AND l.to_h=? LIMIT 4", [$store, $h]);
    $items[] = ['url' => $u, 'note' => ($s === 0 ? 'does not open' : 'error ' . $s) . ($from ? ' · linked from: ' . implode(', ', array_map(function ($x) use ($path) { return $path($x['url']); }, $from)) : ' · listed in your sitemap')];
  }
  $add('urgent', 'broken', 'Broken pages and links (customers and Google land on an error)',
       'For each one: either fix the link on the page named in “linked from”, or add a redirect to the right page. Redirect = Shopify admin → Online Store → Navigation → View URL redirects → Create URL redirect.', $items);

  $items = []; foreach (seo_q($db, "SELECT p.url, f.detail FROM seo_flags f JOIN seo_pages p ON p.store=f.store AND p.h=f.from_h WHERE f.store=? AND f.kind='wa'", [$store]) as $r) $items[] = ['url' => $r['url'], 'note' => 'WhatsApp link with a bad number: ' . $r['detail']];
  $add('urgent', 'wa', 'WhatsApp links with a wrong phone number (these give a 404 on WhatsApp)',
       'Open the page, find the WhatsApp button and fix the number: digits only, with 91 in front, no spaces. In the theme editor it is usually the button’s link or the section’s WhatsApp number setting.', $items);

  $items = []; foreach ($ok as $p) if ((int)$p['noindex'] === 1) $items[] = ['url' => $p['url'], 'note' => 'told Google not to show this page'];
  $add('urgent', 'noindex', 'Pages hidden from Google (noindex) that are in your sitemap',
       'If you want this page on Google, remove the “noindex” (hidden from search engines) setting: product/collection/page → Search engine listing, or the theme’s robots meta tag.', $items);

  $items = []; foreach ($pages as $p) if ((int)$p['hops'] > 1 && (int)$p['status'] < 400) $items[] = ['url' => $p['url'], 'note' => $p['hops'] . ' redirects in a row → ' . $path($p['final_url'])];
  foreach (seo_q($db, "SELECT h, url, hops, final_url FROM seo_linkchk WHERE store=? AND hops>1 AND status<400 AND status>0", [$store]) as $r) {
    $from = seo_q($db, "SELECT DISTINCT p.url FROM seo_links l JOIN seo_pages p ON p.store=l.store AND p.h=l.from_h WHERE l.store=? AND l.to_h=? LIMIT 3", [$store, $r['h']]);
    $items[] = ['url' => $r['url'], 'note' => $r['hops'] . ' redirects in a row → ' . $path($r['final_url']) . ($from ? ' · linked from: ' . implode(', ', array_map(function ($x) use ($path) { return $path($x['url']); }, $from)) : '')];
  }
  $add('medium', 'chain', 'Redirects that jump more than once',
       'Point the old address straight to the final one: Online Store → Navigation → View URL redirects → edit it so it goes directly to the last address.', $items);

  $miss = []; $short = []; $long = []; $dupe = [];
  foreach ($ok as $p) {
    $t = (string)$p['title']; $l = mb_strlen($t);
    if ($t === '') $miss[] = ['url' => $p['url'], 'note' => $where[$p['type']] ?? $where['other']];
    elseif ($l < 25) $short[] = ['url' => $p['url'], 'note' => "$l letters: “$t”"];
    elseif ($l > 65) $long[] = ['url' => $p['url'], 'note' => "$l letters (Google cuts after about 60): “" . mb_substr($t, 0, 70) . "…”"];
  }
  $c = []; foreach ($ok as $p) if ($p['title'] !== '') $c[mb_strtolower($p['title'])][] = $p['url'];
  foreach ($c as $t => $us) if (count($us) > 1) foreach ($us as $u) $dupe[] = ['url' => $u, 'note' => 'same title as ' . (count($us) - 1) . ' other page(s): “' . mb_substr($t, 0, 60) . '”'];
  $add('urgent', 'title_none', 'Pages with no Google title', 'Write a title of 40–60 letters with the main keyword first, for example “Creed Aventus Inspired Perfume | TM Perfume House”.', $miss);
  $add('medium', 'title_dupe', 'Pages sharing the same title', 'Give every page its own title. Google gets confused about which one to show.', $dupe);
  $add('medium', 'title_long', 'Titles that are too long', 'Shorten to about 60 letters, keep the main keyword at the front.', $long);
  $add('low', 'title_short', 'Titles that are too short', 'Add the keyword people search plus the brand, 40–60 letters.', $short);

  $miss = []; $short = []; $long = []; $dupe = [];
  foreach ($ok as $p) {
    $m = (string)$p['meta']; $l = mb_strlen($m);
    if ($m === '') $miss[] = ['url' => $p['url'], 'note' => $where[$p['type']] ?? $where['other']];
    elseif ($l < 70) $short[] = ['url' => $p['url'], 'note' => "$l letters"];
    elseif ($l > 165) $long[] = ['url' => $p['url'], 'note' => "$l letters (Google cuts after about 155)"];
  }
  $c = []; foreach ($ok as $p) if ($p['meta'] !== '') $c[mb_strtolower($p['meta'])][] = $p['url'];
  foreach ($c as $m => $us) if (count($us) > 1) foreach ($us as $u) $dupe[] = ['url' => $u, 'note' => 'same description as ' . (count($us) - 1) . ' other page(s)'];
  $add('urgent', 'meta_none', 'Pages with no Google description', 'Write 120–155 letters: what the page sells, one benefit (30% oil, long-lasting) and “Order on WhatsApp”.', $miss);
  $add('medium', 'meta_dupe', 'Pages sharing the same description', 'Write a different description for each page.', $dupe);
  $add('low', 'meta_long', 'Descriptions that are too long', 'Shorten to about 155 letters.', $long);
  $add('low', 'meta_short', 'Descriptions that are too short', 'Write 120–155 letters.', $short);

  $none = []; $many = [];
  foreach ($ok as $p) { if ((int)$p['h1'] === 0) $none[] = ['url' => $p['url'], 'note' => 'no main heading (H1)']; elseif ((int)$p['h1'] > 1) $many[] = ['url' => $p['url'], 'note' => $p['h1'] . ' main headings (should be 1)']; }
  $add('medium', 'h1_none', 'Pages with no main heading (H1)', 'Every page needs one clear heading with the keyword. In the theme editor, turn on the page/product/collection title or add a Heading block.', $none);
  $add('low', 'h1_many', 'Pages with more than one main heading', 'Keep one H1; make the others H2. This is a theme setting (heading level) in the section.', $many);

  $items = []; foreach ($ok as $p) if ($p['type'] !== 'other' && (int)$p['noalt'] >= 1) $items[] = ['url' => $p['url'], 'note' => $p['noalt'] . ' of ' . $p['imgs'] . ' images have no description (alt text)'];
  usort($items, function ($a, $b) { return strcmp($b['note'], $a['note']); });
  $add('medium', 'alt', 'Images with no description (alt text)', 'Product images: Shopify admin → Products → open product → click the image → “Add alt text”. Describe it with the keyword, e.g. “Creed Aventus inspired perfume 50ml bottle”.', $items);

  $items = []; foreach ($ok as $p) if (in_array($p['type'], ['collection', 'page', 'blog'], true) && (int)$p['words'] < 80) $items[] = ['url' => $p['url'], 'note' => $p['words'] . ' words on the page'];
  $add('low', 'thin', 'Pages with very little text', 'Add 100–200 words of useful text (what is it, who is it for, how long it lasts). Google ranks pages with some text above pages with only pictures.', $items);

  $items = []; foreach ($ok as $p) if ((int)$p['ms'] > 3000) $items[] = ['url' => $p['url'], 'note' => $p['ms'] . ' ms to open'];
  $add('low', 'slow', 'Pages that open slowly', 'Usually heavy images or too many apps. Compress images and remove apps you do not use.', $items);

  $order = ['urgent' => 0, 'medium' => 1, 'low' => 2];
  usort($G, function ($a, $b) use ($order) { return [$order[$a['sev']], -$a['count']] <=> [$order[$b['sev']], -$b['count']]; });
  $n = max(1, count($pages)); $w = ['urgent' => 5, 'medium' => 2, 'low' => 0.5]; $pen = 0.0;
  foreach ($G as $g) $pen += $w[$g['sev']] * min($g['count'], $n);
  $score = (int)max(0, min(100, round(100 - $pen / $n * 12)));
  $sev = ['urgent' => 0, 'medium' => 0, 'low' => 0]; foreach ($G as $g) $sev[$g['sev']] += $g['count'];
  $last = seo_q($db, "SELECT MAX(checked) m FROM seo_pages WHERE store=?", [$store])[0]['m'] ?? null;
  return ['groups' => $G, 'pages' => count($pages), 'score' => $score, 'sev' => $sev, 'last' => $last];
}
