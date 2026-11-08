<?php
/**
 * wa_lists.php — 📋 Lists: ready perfume lists a salesperson sends in one tap ("Top 5 winter", "Top 10 party", "Best sellers top 10", "Under ₹999"…).
 * A list is a RULE on the live product data (wa_prod: Shopify products, their seasons / occasions, units sold in the last 30 / 90 days), not a typed text,
 * so "Top 5" is always this month's top 5. Products that leave the shop (unpublished / deleted) drop out by themselves.
 *
 *   wl.list         GET                       groups + lists (with their items and counts). Lists older than 20 h are rebuilt first (best sellers move).
 *   wl.preview      GET  {id, phone?}         the message text of one list (name filled in for that customer)
 *   wl.for_chat     GET  {phone}              lists for the chat's 📋 sheet, the ones that match the customer's last message first (✨)
 *   wl.send         POST {phone, id, text?}   send a list into a chat (24-hour window), counts it, tags the customer list_<slug>
 *   wl.save         POST {list}               create / change one list (name, group, words, n, who, price, pins, drops, intro, outro, links, auto)
 *   wl.delete       POST {id}
 *   wl.plan         POST {summary}            ✨ main Claude box: from a general summary → groups + lists + customer tags (a proposal, nothing saved)
 *   wl.plan_save    POST {groups}             saves the proposal the admin kept
 *   wl.ai_edit      POST {id, say}            ✨ the list's own Claude box: "fetch now", "remove X, add Y", "make it 10", "only under 1000" → a proposal (before / after)
 *   wl.suggest      POST {}                   ✨ "suggest more": new lists from what exists + what is selling now (a proposal)
 *
 * Loaded by wa_more.php (before wa_seg.php). Admin / HR edit; everyone in WhatsApp can read and send. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wl_tables')) {

function wl_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_lists (id INT AUTO_INCREMENT PRIMARY KEY, grp VARCHAR(60) NOT NULL DEFAULT '', name VARCHAR(80) NOT NULL, slug VARCHAR(60) NOT NULL DEFAULT '',
            words VARCHAR(200) NOT NULL DEFAULT '', n INT NOT NULL DEFAULT 5, who VARCHAR(10) NOT NULL DEFAULT '', pmin INT NOT NULL DEFAULT 0, pmax INT NOT NULL DEFAULT 0,
            pins TEXT NULL, drops TEXT NULL, items TEXT NULL, manual TINYINT NOT NULL DEFAULT 0, intro VARCHAR(500) NOT NULL DEFAULT '', outro VARCHAR(300) NOT NULL DEFAULT '',
            links TINYINT NOT NULL DEFAULT 0, auto TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0, refreshed_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL, KEY k_grp (grp, sort)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_list_log (id INT AUTO_INCREMENT PRIMARY KEY, list_id INT NOT NULL, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, at DATETIME NOT NULL, KEY k_list (list_id, at), KEY k_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->query("SELECT opts FROM wa_lists LIMIT 0"); } catch (PDOException $e) { $pdo->exec("ALTER TABLE wa_lists ADD COLUMN opts TEXT NULL"); }   // how the list shows its perfumes (price / long lasting / names…)
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
/** How a list shows its perfumes. show: what goes after each name (default: long lasting only — no price). name: short | full (website title) | inspired (+ "inspired by" in the website spelling) | ours (our own name).
 *  rename: {product id: the exact name to show}. hide_samples: complimentary / sample / tester products are never in a list (unless pinned). */
function wl_opts($raw, bool $links = false): array
{
    $o = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []); $known = ['price', 'longevity', 'notes', 'gender', 'link'];
    $show = array_key_exists('show', $o) ? array_values(array_intersect($known, array_map('strval', (array)$o['show']))) : ['longevity'];
    if ($links && !in_array('link', $show, true) && !array_key_exists('show', $o)) $show[] = 'link';
    $ren = []; foreach ((array)($o['rename'] ?? []) as $id => $nm) { $id = (int)$id; $nm = trim(mb_substr((string)$nm, 0, 90)); if ($id > 0 && $nm !== '') $ren[$id] = $nm; if (count($ren) >= 100) break; }
    return ['show' => $show, 'name' => in_array($o['name'] ?? '', ['short', 'full', 'inspired', 'original', 'ours'], true) ? (string)$o['name'] : 'short', 'rename' => $ren, 'hide_samples' => !array_key_exists('hide_samples', $o) || !empty($o['hide_samples'])];
}
/** "Ltfa Kahwa & Coffee - Inspired perfume I TMPerfume House" → "Ltfa Kahwa & Coffee" (the website title without the shop's tail). */
function wl_short_title(string $t): string
{
    $s = preg_replace('/\s*[-–—|]\s*(?:[A-Z]{1,3}\s+)?inspired\b.*$/iu', '', $t) ?? $t; $s = preg_replace('/\s+I\s+TM\s*Perf.*$/iu', '', $s) ?? $s; $s = preg_replace('/\s*[-–—|]\s*TM\s*Perf.*$/iu', '', $s) ?? $s;
    $s = preg_replace('/^[\s\-–—|]+|[\s\-–—|]+$/u', '', $s) ?? $s; return $s !== '' ? $s : $t;   // never trim() with dashes: it cuts multibyte letters / emoji in half
}
function wl_is_sample(array $p): bool { return (bool)preg_match('/complimentary|\bsample\b|\btester\b|gift\s*card|free\s*gift|\bvial\b/i', (string)($p['title'] ?? '') . ' ' . (string)($p['name'] ?? '')) || (wl_price($p) > 0 && wl_price($p) < 150); }
function wl_prod_ready(PDO $pdo): bool
{
    if (!function_exists('wa_prod_pick') && is_file(__DIR__ . '/wa_prod.php')) { try { require_once __DIR__ . '/wa_prod.php'; } catch (Throwable $e) { } }
    return function_exists('wa_prod_pick') && function_exists('wa_prod_tables') && wa_prod_tables($pdo);
}
function wl_ids($v): array { $a = is_array($v) ? $v : json_decode((string)$v, true); return array_values(array_unique(array_filter(array_map('intval', is_array($a) ? $a : []), fn($x) => $x > 0))); }
function wl_slug(string $s): string { $s = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $s) ?? ''), '_'); return substr($s !== '' ? $s : 'list', 0, 50); }
/** Live products by id (active only) — only what a list needs. */
function wl_prods(PDO $pdo, array $ids = []): array
{
    if (!wl_prod_ready($pdo)) return []; $out = [];
    foreach (wa_prod_all($pdo) as $p) if (!$ids || in_array((int)$p['id'], $ids, true)) $out[(int)$p['id']] = $p;
    return $out;
}
function wl_price(array $p): int { $op = (int)($p['our_price'] ?? 0); return $op > 0 ? $op : (int)($p['price_min'] ?? 0); }
function wl_show_name(array $p): string { $t = trim((string)($p['title'] ?? '')); return $t !== '' ? $t : (string)$p['name']; }   // the website title (what the customer sees in the shop) — never our staff abbreviation
/** Builds the list from its rule: pinned perfumes first (in their order), then the best match for the words, by recent sales; never a dropped one. */
function wl_build(PDO $pdo, array $l): array
{
    $pins = wl_ids($l['pins'] ?? []); $drops = wl_ids($l['drops'] ?? []); $n = max(1, min(100, (int)($l['n'] ?? 5)));
    $all = wl_prods($pdo); if (!$all) return [];
    if (!empty($l['manual'])) return array_values(array_filter(wl_ids($l['items'] ?? []), fn($id) => isset($all[$id])));   // a hand-made list keeps its own order
    $hideS = wl_opts($l['opts'] ?? [])['hide_samples'];
    $ok = function (array $p) use ($l, $hideS): bool { if ($hideS && wl_is_sample($p)) return false; $pr = wl_price($p); if ((int)($l['pmin'] ?? 0) > 0 && $pr > 0 && $pr < (int)$l['pmin']) return false; if ((int)($l['pmax'] ?? 0) > 0 && $pr > (int)$l['pmax']) return false; return true; };
    $ids = []; foreach ($pins as $id) if (isset($all[$id]) && !in_array($id, $drops, true)) $ids[] = $id;
    $q = trim((string)($l['words'] ?? '') . ' ' . (string)($l['who'] ?? '')); $q = $q !== '' ? $q : 'best sellers';
    try { $pick = wa_prod_pick($pdo, $q, min(100, $n * 4 + 20), ['exclude' => array_merge($drops, $ids)]); } catch (Throwable $e) { $pick = []; }
    foreach ($pick as $it) { if (count($ids) >= $n) break; $id = (int)$it['id']; if (isset($all[$id]) && $ok($all[$id]) && !in_array($id, $ids, true)) $ids[] = $id; }
    return array_slice($ids, 0, $n);
}
function wl_row(array $r): array
{
    return ['id' => (int)$r['id'], 'grp' => (string)$r['grp'], 'name' => (string)$r['name'], 'slug' => (string)$r['slug'], 'words' => (string)$r['words'], 'n' => (int)$r['n'], 'who' => (string)$r['who'],
        'pmin' => (int)$r['pmin'], 'pmax' => (int)$r['pmax'], 'pins' => wl_ids($r['pins']), 'drops' => wl_ids($r['drops']), 'items' => wl_ids($r['items']), 'manual' => (int)$r['manual'] === 1,
        'intro' => (string)$r['intro'], 'outro' => (string)$r['outro'], 'links' => (int)$r['links'] === 1, 'auto' => (int)$r['auto'] === 1, 'sort' => (int)$r['sort'], 'refreshed_at' => $r['refreshed_at'],
        'opts' => wl_opts($r['opts'] ?? null, (int)$r['links'] === 1)];
}
function wl_get(PDO $pdo, int $id): ?array { $q = $pdo->prepare("SELECT * FROM wa_lists WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ? wl_row($r) : null; }
/** Rebuild the automatic lists that are older than 20 hours (best sellers change). Cheap: runs when the Lists screen / the chat sheet opens. */
function wl_refresh_due(PDO $pdo, bool $all = false): int
{
    $n = 0; $cut = date('Y-m-d H:i:s', time() - 20 * 3600);
    $st = $pdo->query("SELECT * FROM wa_lists WHERE auto = 1 AND manual = 0" . ($all ? '' : " AND (refreshed_at IS NULL OR refreshed_at < '$cut')") . " LIMIT 60");
    $up = $pdo->prepare("UPDATE wa_lists SET items = ?, refreshed_at = ? WHERE id = ?");
    foreach ($st->fetchAll() as $r) { $l = wl_row($r); $up->execute([json_encode(wl_build($pdo, $l)), date('Y-m-d H:i:s'), $l['id']]); $n++; }
    return $n;
}
/** The items as shown on screen: name, price, link, photo, sales. */
function wl_items_view(PDO $pdo, array $ids, $opts = null): array
{
    $o = wl_opts($opts ?? []); $all = wl_prods($pdo, $ids); $out = [];
    foreach ($ids as $id) {
        if (!isset($all[$id])) continue; $p = $all[$id]; $title = wl_show_name($p); $short = wl_short_title($title); $insp = trim((string)($p['original_site'] ?? ''));
        $real = trim((string)($p['original_real'] ?? '')) !== '' && (int)($p['orig_conf'] ?? 0) >= 50 ? trim((string)$p['original_real']) : '';   // the real original perfume (worked out in Products), only when fairly sure
        $readable = $real !== '' ? $real : (trim((string)($p['original_real'] ?? '')) !== '' ? trim((string)$p['original_real']) : '');   // the website spelling made readable ("T0m F0rd T0b@cc0" → "Tom Ford Tobacco")
        $nm = $o['rename'][$id] ?? ($o['name'] === 'full' ? $title : ($o['name'] === 'ours' ? ((string)$p['name'] ?: $short) : ($o['name'] === 'original' ? ($readable !== '' ? $readable : $short) : ($o['name'] === 'inspired' && $insp !== '' ? $short . ' (inspired by ' . $insp . ')' : $short))));
        $lg = trim((string)($p['longevity'] ?? '')); $notes = array_slice(array_values(array_filter(array_map('trim', (array)($p['notes'] ?? [])))), 0, 3);
        $out[] = ['id' => $id, 'name' => $nm, 'title' => $title, 'short' => $short, 'inspired' => $insp, 'original' => $real, 'staff' => (string)$p['name'], 'renamed' => isset($o['rename'][$id]), 'price' => wl_price($p), 'longevity' => $lg, 'notes' => $notes, 'gender' => (string)($p['gender'] ?? ''),
            'url' => (string)$p['url'], 'photo' => (string)($p['photos'][0] ?? ''), 'units_90d' => $p['units_90d'], 'units' => (int)$p['units_sold'], 'seasons' => (array)($p['seasons'] ?? [])];
    }
    return $out;
}
/** A list's items for the screens: names as the list shows them + the extras line. */
function wl_view_x(PDO $pdo, array $l): array { $o = wl_opts($l['opts'] ?? []); $v = wl_items_view($pdo, (array)($l['items'] ?? []), $o); foreach ($v as &$x) $x['extra'] = wl_extras($x, $o, ''); unset($x); return $v; }
/** What follows a perfume's name in the list message, as the list's opts say ("⏳ 8-10 hrs · ₹1,199"). */
function wl_extras(array $it, array $o, string $lead = ' – '): string
{
    $x = [];
    foreach ($o['show'] as $k) {
        if ($k === 'longevity' && $it['longevity'] !== '') $x[] = '⏳ ' . $it['longevity'];
        elseif ($k === 'price' && $it['price'] > 0) $x[] = '₹' . number_format($it['price']);
        elseif ($k === 'notes' && $it['notes']) $x[] = implode(', ', $it['notes']);
        elseif ($k === 'gender' && $it['gender'] !== '') $x[] = ucfirst($it['gender']);
    }
    return $x ? $lead . implode(' · ', $x) : '';
}
/** The WhatsApp text of a list. {name} = the customer's first name. */
function wl_text(PDO $pdo, array $l, string $custName = ''): string
{
    $o = wl_opts($l['opts'] ?? []); $items = wl_items_view($pdo, $l['items'], $o); if (!$items) return '';
    $fn = function_exists('wa_first_name') ? wa_first_name($custName) : (trim(explode(' ', trim($custName))[0] ?? '') ?: 'ji'); if ($fn === 'ji') $fn = '';
    $intro = trim($l['intro'] !== '' ? $l['intro'] : ('Here are our ' . $l['name'] . ' ✨'));
    $intro = trim(str_replace('{name}', $fn, $intro)); $intro = preg_replace('/\s+([,!.])/', '$1', preg_replace('/ {2,}/', ' ', $intro));
    $lines = [$intro, ''];
    foreach ($items as $i => $it) { $lines[] = ($i + 1) . '. ' . $it['name'] . wl_extras($it, $o); if (in_array('link', $o['show'], true) && $it['url'] !== '') $lines[] = '   ' . $it['url']; }
    $out = trim($l['outro'] !== '' ? str_replace('{name}', $fn, $l['outro']) : 'Reply with the number you like and I will share the details 😊');
    if ($out !== '') { $lines[] = ''; $lines[] = $out; }
    return implode("\n", $lines);
}
/** Sent count + customers who ordered within 7 days after getting the list, per list (last 90 days). */
function wl_counts(PDO $pdo): array
{
    $o = []; $since = date('Y-m-d H:i:s', time() - 90 * 86400);
    try { foreach ($pdo->query("SELECT list_id, COUNT(*) n FROM wa_list_log WHERE at >= '$since' GROUP BY list_id")->fetchAll() as $r) $o[(int)$r['list_id']] = ['sent' => (int)$r['n'], 'orders' => 0]; } catch (Throwable $e) { return $o; }
    try {
        $st = $pdo->query("SELECT l.list_id, COUNT(DISTINCT l.phone) n FROM wa_list_log l JOIN shop_orders o ON SUBSTR(REPLACE(REPLACE(REPLACE(o.phone, ' ', ''), '-', ''), '+', ''), -10) = l.phone
            AND o.created_at >= l.at AND o.created_at < DATE_ADD(l.at, INTERVAL 7 DAY) WHERE l.at >= '$since' GROUP BY l.list_id");
        foreach ($st->fetchAll() as $r) { $k = (int)$r['list_id']; if (isset($o[$k])) $o[$k]['orders'] = (int)$r['n']; }
    } catch (Throwable $e) { }
    return $o;
}
/** Every list, grouped, for the screens. */
function wl_all(PDO $pdo, bool $withItems = true): array
{
    $cn = wl_counts($pdo); $out = [];
    foreach ($pdo->query("SELECT * FROM wa_lists ORDER BY grp, sort, id")->fetchAll() as $r) {
        $l = wl_row($r); $l['sent'] = $cn[$l['id']]['sent'] ?? 0; $l['orders'] = $cn[$l['id']]['orders'] ?? 0;
        if ($withItems) { $l['view'] = wl_items_view($pdo, $l['items'], $l['opts']); foreach ($l['view'] as &$v) $v['extra'] = wl_extras($v, $l['opts'], ''); unset($v); }
        $out[] = $l;
    }
    return $out;
}
/** Clean one list coming from the screen / from Claude. */
function wl_clean(array $x): array
{
    $name = mb_substr(trim((string)($x['name'] ?? '')), 0, 80);
    return ['grp' => mb_substr(trim((string)($x['grp'] ?? 'Lists')), 0, 60) ?: 'Lists', 'name' => $name, 'slug' => wl_slug((string)($x['slug'] ?? '') ?: $name), 'words' => mb_substr(trim((string)($x['words'] ?? '')), 0, 200),
        'n' => max(1, min(100, (int)($x['n'] ?? 5))), 'who' => in_array((string)($x['who'] ?? ''), ['men', 'women', 'unisex'], true) ? (string)$x['who'] : '', 'pmin' => max(0, (int)($x['pmin'] ?? 0)), 'pmax' => max(0, (int)($x['pmax'] ?? 0)),
        'pins' => wl_ids($x['pins'] ?? []), 'drops' => wl_ids($x['drops'] ?? []), 'items' => wl_ids($x['items'] ?? []), 'manual' => !empty($x['manual']) ? 1 : 0,
        'intro' => mb_substr(trim((string)($x['intro'] ?? '')), 0, 500), 'outro' => mb_substr(trim((string)($x['outro'] ?? '')), 0, 300), 'links' => !empty($x['links']) ? 1 : 0, 'auto' => array_key_exists('auto', $x) ? (!empty($x['auto']) ? 1 : 0) : 1, 'sort' => (int)($x['sort'] ?? 0),
        'opts' => wl_opts_from($x)];
}
/** opts from the screen or from Claude: {opts:{…}} or the short keys show / name_style / rename / hide_samples; links (old checkbox) = show link. */
function wl_opts_from(array $x): array
{
    $o = is_array($x['opts'] ?? null) ? $x['opts'] : [];
    if (array_key_exists('show', $x)) $o['show'] = (array)$x['show']; if (isset($x['name_style'])) $o['name'] = (string)$x['name_style']; if (is_array($x['rename'] ?? null)) $o['rename'] = ($o['rename'] ?? []) + $x['rename']; if (array_key_exists('hide_samples', $x)) $o['hide_samples'] = $x['hide_samples'];
    $o = wl_opts($o); if (!empty($x['links']) && !in_array('link', $o['show'], true) && !array_key_exists('show', $x) && !isset($x['opts']['show'])) $o['show'][] = 'link'; return $o;
}
function wl_save(PDO $pdo, array $x, int $id = 0): int
{
    $c = wl_clean($x); if ($c['name'] === '') return 0; $now = date('Y-m-d H:i:s'); $c['links'] = in_array('link', $c['opts']['show'], true) ? 1 : 0;
    $c['items'] = wl_build($pdo, $c);
    $cols = ['grp', 'name', 'slug', 'words', 'n', 'who', 'pmin', 'pmax', 'pins', 'drops', 'items', 'manual', 'intro', 'outro', 'links', 'auto', 'sort', 'opts'];
    $vals = array_map(fn($k) => is_array($c[$k]) ? json_encode($c[$k]) : $c[$k], $cols);
    if ($id > 0) { $pdo->prepare("UPDATE wa_lists SET " . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . ", refreshed_at = ?, updated_at = ? WHERE id = ?")->execute(array_merge($vals, [$now, $now, $id])); return $id; }
    $pdo->prepare("INSERT INTO wa_lists (" . implode(', ', $cols) . ", refreshed_at, created_at, updated_at) VALUES (" . implode(', ', array_fill(0, count($cols) + 3, '?')) . ")")->execute(array_merge($vals, [$now, $now, $now]));
    return (int)$pdo->lastInsertId();
}
/** The request words HRMS understands (seasons, occasions, scents…) with how many perfumes fit each — Claude may only use these. */
function wl_vocab(PDO $pdo): array
{
    $lab = function_exists('wa_prod_labels') ? wa_prod_labels() : []; $cnt = [];
    foreach (wl_prods($pdo) as $p) foreach ((array)$p['tags'] as $t) $cnt[$t] = ($cnt[$t] ?? 0) + 1;
    $o = []; foreach ($lab as $slug => $label) $o[] = ['word' => (string)$slug, 'label' => (string)$label, 'perfumes' => (int)($cnt[$slug] ?? 0)];
    return $o;
}
function wl_catalog_brief(PDO $pdo, int $limit = 150, array $only = []): string
{
    $rows = $only ? array_values(wl_prods($pdo, $only)) : array_values(wl_prods($pdo)); $s = '';
    usort($rows, fn($a, $b) => ((int)($b['units_90d'] ?? $b['units_sold'])) <=> ((int)($a['units_90d'] ?? $a['units_sold'])));
    foreach (array_slice($rows, 0, $limit) as $p) $s .= '#' . $p['id'] . ' ' . wl_short_title(wl_show_name($p)) . (trim((string)($p['original_site'] ?? '')) !== '' ? ' (inspired by ' . trim((string)$p['original_site']) . ')' : '') . (trim((string)($p['original_real'] ?? '')) !== '' ? ' [real original: ' . trim((string)$p['original_real']) . ']' : '') . (wl_is_sample($p) ? ' [SAMPLE]' : '') . ' · ₹' . wl_price($p) . ' · sold 90d ' . ($p['units_90d'] ?? '?') . ' / all ' . (int)$p['units_sold'] . ' · ' . ($p['gender'] ?: 'any') . ' · ' . implode(',', array_slice((array)$p['tags'], 0, 6))
        . (trim((string)($p['longevity'] ?? '')) !== '' ? ' · lasts ' . $p['longevity'] : '') . ((array)($p['notes'] ?? []) ? ' · notes ' . implode(', ', array_slice((array)$p['notes'], 0, 5)) : '') . wl_mf_extra($p) . "\n";
    return $s;
}
/** The product's other Shopify fields (metafields not already shown above), short — so Claude can use anything the owner keeps in Shopify (popup names, approvals…). */
function wl_mf_extra(array $p): string
{
    $skip = []; if (function_exists('wa_prod_mf')) foreach (wa_prod_mf() as $nk) $skip[$nk[0] . '.' . $nk[1]] = 1;
    $x = []; foreach ((array)($p['mf'] ?? []) as $k => $v) { if (isset($skip[$k]) || preg_match('/^(reviews|shopify|global|judgeme|spr)\./', $k)) continue; $x[] = preg_replace('/^custom\./', '', $k) . '=' . mb_substr((string)$v, 0, 60); }
    $s = mb_substr(implode('; ', $x), 0, 260); return $s !== '' ? ' · {' . $s . '}' : '';
}
/** EVERY active perfume, one short line each, so any name the owner says can be matched (the detailed catalog only covers the best sellers). */
function wl_names_brief(PDO $pdo): string
{
    $s = ''; foreach (wl_prods($pdo) as $p) { if (wl_is_sample($p)) continue; $real = trim((string)($p['original_real'] ?? ''));
        $s .= '#' . $p['id'] . ' ' . wl_short_title(wl_show_name($p)) . (trim((string)$p['name']) !== '' && $p['name'] !== $p['title'] ? ' / ' . $p['name'] : '') . (trim((string)($p['original_site'] ?? '')) !== '' ? ' / ' . trim((string)$p['original_site']) : '') . ($real !== '' ? ' = ' . $real : '') . "\n"; }
    return $s;
}
function wl_ai(PDO $pdo, string $sys, string $user, int $max = 3000, bool $web = false): ?array
{
    if (!function_exists('brain_ai_json')) return null;
    $big = function_exists('tmph_cfg') ? (string)tmph_cfg('TMPH_MODEL_BIG', 'claude-opus-5-5') : 'claude-opus-5-5';   // the owner's list instructions: the strongest model, it must understand every part in one go
    if ($web) $sys .= "\nYou may use web search to look up perfume facts (original perfume, long lasting, notes) the owner asks for; then give the final answer as the JSON only.";
    $GLOBALS['wl_ai_why'] = '';
    try { $j = brain_ai_json('internal', 'whatsapp', $sys, $user, $max, ['ctx' => false, 'model' => $big, 'timeout' => $web ? 170 : 110] + ($web ? ['web' => 5] : [])); } catch (Throwable $e) { $j = null; $GLOBALS['wl_ai_why'] = $e->getMessage(); }
    $w1 = (string)($GLOBALS['brain_last_error'] ?? '');
    if (!is_array($j)) { try { $j = brain_ai_json('internal', 'whatsapp', $sys, $user, $max, ['ctx' => false, 'timeout' => 110]); } catch (Throwable $e) { $GLOBALS['wl_ai_why'] = $e->getMessage(); return null; } }   // the usual model when the strong one is not available
    if (!is_array($j)) $GLOBALS['wl_ai_why'] = trim((string)($GLOBALS['brain_last_error'] ?? '') ?: $w1 ?: ($GLOBALS['wl_ai_why'] ?? '')) ?: 'Claude answered, but not in a form HRMS can read (the answer may be too long — ask for fewer lists at once)';
    return is_array($j) ? $j : null;
}
function wl_list_schema_txt(): string
{
    return '{"grp":"group name","name":"Top 5 winter","words":"request words from the WORDS list, space separated (e.g. \"winter\", \"party men\", \"best-sellers\")","n":5,"who":"men|women|unisex|\"\"","pmin":0,"pmax":0,"intro":"first line of the WhatsApp message, may use {name}","outro":"last line",'
         . '"show":["what follows each perfume name, any of: longevity, price, notes, gender, link — DEFAULT [\"longevity\"] (no price unless the owner asks)"],"name_style":"short (default: the website name without the shop tail) | original (the REAL original perfume name, e.g. Azzaro The Most Wanted — when the owner says original / real / original name / fetch original) | full (the full website title with the shop tail — only when he says full website title) | inspired (short name + inspired by <the website spelling>) | ours (our own short name)","rename":{"<product id>":"the exact name the owner wants for that perfume"},"hide_samples":true}';
}
/** The owner's newest yes / no about each extra (notes, price, link, long lasting, for men/women), read from his own words: [field => true (wants it) | false (does not)]. */
function wl_owner_wants(array $turns): array
{
    $txt = mb_strtolower(implode("\n", array_map('strval', $turns))); $o = [];
    $words = ['notes' => 'notes?', 'price' => 'prices?|rates?|mrp|keemat|daam', 'link' => 'links?|urls?', 'longevity' => 'long\s*lasting|longevity|lasting', 'gender' => 'gender|for men\/women'];
    foreach ($words as $k => $w) {
        $neg = '/(?:\b(?:no|without|remove|hide|skip|drop|exclude|not|don\'?t\s+(?:want|show|add|need))\s+(?:the\s+|any\s+)?(?:' . $w . ')\b|\b(?:' . $w . ')\s+(?:nahi|nahin|mat|hatao|remove|off|not needed|no)\b)/u';
        $pos = '/\b(?:add|show|with|include|plus|and)\s+(?:the\s+)?(?:' . $w . ')\b/u';
        $n = preg_match_all($neg, $txt, $mn, PREG_OFFSET_CAPTURE) ? end($mn[0])[1] : -1; $p = preg_match_all($pos, $txt, $mp, PREG_OFFSET_CAPTURE) ? end($mp[0])[1] : -1;
        if ($n >= 0 || $p >= 0) $o[$k] = $p > $n;
    }
    return $o;
}
function wl_apply_wants(array $opts, array $want): array { foreach ($want as $k => $yes) { $opts['show'] = array_values(array_diff($opts['show'], [$k])); if ($yes) $opts['show'][] = $k; } return $opts; }
/** Product corrections Claude proposes ("Rasasi Hawas lasts 6-8 hrs") → [{id, name, longevity?, notes?, gender?, before}] for known products only. */
function wl_fixes(PDO $pdo, $raw): array
{
    $known = wl_prods($pdo); $out = [];
    foreach (array_slice((array)$raw, 0, 40) as $f) {
        if (!is_array($f)) continue; $id = (int)($f['id'] ?? 0); if (!isset($known[$id])) continue; $p = $known[$id]; $x = ['id' => $id, 'name' => wl_short_title(wl_show_name($p))]; $ch = [];
        $lg = trim((string)($f['longevity'] ?? '')); if ($lg !== '' && mb_strlen($lg) <= 30) { $x['longevity'] = $lg; $ch[] = 'long lasting ' . (trim((string)$p['longevity']) !== '' ? $p['longevity'] . ' → ' : '') . $lg; }
        $nt = array_values(array_filter(array_map('trim', array_map('strval', (array)($f['notes'] ?? []))))); if ($nt) { $x['notes'] = array_slice($nt, 0, 12); $ch[] = 'notes → ' . implode(', ', array_slice($nt, 0, 5)); }
        $g = strtolower(trim((string)($f['gender'] ?? ''))); if (in_array($g, ['men', 'women', 'unisex'], true)) { $x['gender'] = $g; $ch[] = 'for ' . $g; }
        $og = trim((string)($f['original'] ?? '')); if ($og !== '' && mb_strlen($og) <= 160) { $x['original'] = $og; $ch[] = 'original → ' . $og; }
        if ($ch) { $x['what'] = implode(' · ', $ch); $out[] = $x; }
    }
    return $out;
}
/** Shows the proposed product corrections inside a preview (nothing is saved). */
function wl_patch_view(array $view, array $fixes, array $opts): array
{
    $by = []; foreach ($fixes as $f) $by[(int)$f['id']] = $f;
    foreach ($view as &$v) { $f = $by[(int)$v['id']] ?? null; if ($f) { if (isset($f['longevity'])) $v['longevity'] = $f['longevity']; if (isset($f['notes'])) $v['notes'] = array_slice($f['notes'], 0, 3); if (isset($f['gender'])) $v['gender'] = $f['gender'];
        if (isset($f['original']) && $opts['name'] === 'original' && empty($v['renamed'])) $v['name'] = $f['original']; $v['fixed'] = true; } $v['extra'] = wl_extras($v, $opts, ''); }
    unset($v); return $view;
}
/** ✨ main box: a general summary → groups + lists + customer tags. A proposal: nothing is saved here. */
function wl_plan(PDO $pdo, string $summary, string $mode = 'plan', array $turns = [], $prev = null): array
{
    $words = wl_vocab($pdo); $have = []; $byId = []; foreach ($pdo->query("SELECT * FROM wa_lists ORDER BY grp, sort")->fetchAll() as $r) { $lr = wl_row($r); $byId[$lr['id']] = $lr; $have[] = '#' . $lr['id'] . ' ' . $lr['grp'] . ' / ' . $lr['name'] . ' [' . $lr['words'] . ' · top ' . $lr['n'] . ($lr['who'] ? ' · ' . $lr['who'] : '') . '] shows: ' . (implode(', ', $lr['opts']['show']) ?: 'name only') . ' · names: ' . $lr['opts']['name'] . ($lr['opts']['rename'] ? ' · renamed: ' . json_encode($lr['opts']['rename'], JSON_UNESCAPED_UNICODE) : '') . ($lr['pins'] ? ' · pinned first: ' . implode(', ', array_map(fn($i) => '#' . $i, $lr['pins'])) : '') . ($lr['manual'] ? ' · FIXED list' : '')
        . ' · now holds: ' . implode(', ', array_map(fn($v) => '#' . $v['id'] . ' ' . $v['name'], array_slice(wl_items_view($pdo, $lr['items'], $lr['opts']), 0, 20))); }
    $sys = "LANGUAGE: write EVERYTHING in simple English — understood, questions, list names, group names, intro and outro lines — even when the owner writes in Hindi, Hinglish or Gujarati. Use another language only when the owner explicitly asks for it in this conversation.\n"
         . "You set up ready-to-send perfume LISTS for TM Perfume House (WhatsApp sales, India). Salespeople send a list in one tap when a customer asks (\"winter perfumes?\", \"best sellers?\", \"gift under 1000?\"). "
         . "Each list is a RULE that HRMS fills from live Shopify sales: request words + how many (n) + optional men/women + optional price range. You never pick products yourself here. "
         . "Use ONLY words from the WORDS list (the 'word' field), several separated by spaces when needed. Prefer words that have perfumes. Group the lists sensibly (Season, Occasion, Best sellers, Budget, For him / her, Gifts…). "
         . "Name lists like a salesperson would say them: 'Top 5 winter', 'Top 10 party', 'Best sellers — top 10', 'Under ₹999'. Typical n: 5 or 10 (100 only for a full best-seller list). "
         . "intro: one friendly line in simple English (Hinglish is fine), may start with 'Hi {name}!'; outro: one short call to action. Also give customer TAGS this creates (one per list topic, lowercase_with_underscores) — they mark customers who got that list. "
         . "You have FULL authority over the lists: the owner may also ask to CHANGE existing lists (e.g. 'remove the price from all lists and add long lasting', 'show the original names', 'call #12 Le Male Elixir') — put those in update (only the fields that change, with the list id) — or to DELETE lists — put their ids in delete. "
         . "FULL AUTHORITY means: create lists; for any existing list change ANY field — name, grp (move it to another group or rename a group by changing grp on all its lists), words, n, who, pmin / pmax, intro, outro, show, name_style, rename, hide_samples, auto (refresh daily), "
         . "pins (product ids always in, in this order), drops (product ids never in), manual + items (an exact fixed list in a fixed order); and delete lists. Match every perfume the owner names to its #id in the CATALOG. "
         . "This is a conversation: the owner may correct you several times. Read ALL his messages (newest wins), start from YOUR LAST PROPOSAL and change only what he asks. understood: say plainly, in 2-5 short sentences, what you will do. "
         . "questions: 0-3 short questions ONLY when something is really unclear (which lists he means, what name to use…) — otherwise an empty list. "
         . "show is the COMPLETE set of extras after each name: when you set it, list everything that should appear; when the owner says no / without / remove notes (or price, link…), leave that out — the owner's newest words always win. "
         . "The CATALOG gives every product's fields from Shopify: [real original: …] is the real original perfume (from Shopify's own field or looked up online), lasts = long lasting, notes, and {…} = the other Shopify fields (popup names, approvals…) — use them when the owner refers to them. "
         . "Never show the price unless the owner asks; long lasting is the default extra. Complimentary / sample / tester products never belong in a list (hide_samples true). When the owner dictates a name for a perfume, use it exactly in rename (key = the product #id from the catalog). "
         . "SCOPE: when the owner says a change WITHOUT naming a list ('use original names', 'no notes', 'show long lasting', 'hide samples'), it is for ALL lists: put ONE entry in update with \"id\":\"all\" and those fields. Only when he names a list (or a group) change just that one. "
         . "SEVERAL LISTS: when the owner names more than one list, or says 'both', 'also in', 'same in', 'all winter lists', 'top 5 and top 10', do the change in EACH of those lists (one update entry per list, the same pins / renames in each). Read every sentence: each one is an instruction; do all of them. "
         . "ORDER: when the owner says which perfume is 1st, 2nd, 3rd… in a list, put exactly those ids in pins IN THAT ORDER (the rest of the list still fills from sales); when he gives the WHOLE list, set manual true and items = all ids in order. "
         . "COUNT: 'make it 6' / 'add a 6th' / 'top 6' → n = 6 (and rename the list if its name says another number, e.g. 'Top 5 winter' → 'Top 6 winter'); a perfume he names for the new place goes into pins at that place. "
         . "PERFUME FACTS: when the owner corrects a perfume itself ('Rasasi Hawas long lasting is 6-8 hrs', 'X is for women', 'X notes are …', 'X original is …') put it in products: [{id, longevity / notes / gender / original}] — this fixes that perfume everywhere (every list, every chat). "
         . "Match perfumes the owner names by their website name, our name, their [real original] or how it sounds ('AZ most wanted' = Azz The Most Wanted / Azzaro The Most Wanted, 'JPG Le Male Elixir', 'Lattafa Khamrah Qahwa'). Never drop a request silently: if you cannot match a perfume, ask in questions. "
         . "Answer {\"understood\":\"what you understood, 1-2 lines\",\"groups\":[{\"name\":\"...\",\"lists\":[" . wl_list_schema_txt() . "]}],\"update\":[{\"id\":0,\"name\":\"only when it changes\",\"n\":6,\"pins\":[ids in order],\"drops\":[ids],\"manual\":false,\"items\":[ids when manual],\"...any other field that changes\":\"\"}],\"products\":[{\"id\":0,\"longevity\":\"6-8 hrs\"}],\"delete\":[ids],\"tags\":[\"winter_lovers\"],\"questions\":[\"…\"]}. groups may be empty when the owner only changes or deletes.";
    $talk = ''; if ($turns) { $talk = "OWNER'S MESSAGES (oldest first — the newest correction wins):\n"; foreach (array_slice($turns, -8) as $t) $talk .= '- ' . mb_substr(trim((string)$t), 0, 2000) . "\n"; }
    if ($prev) $talk .= "\nYOUR LAST PROPOSAL (revise it — keep what the owner did not ask to change):\n" . mb_substr(is_string($prev) ? $prev : json_encode($prev, JSON_UNESCAPED_UNICODE), 0, 9000) . "\n";
    $user = ($mode === 'suggest' ? "SUGGEST NEW LISTS that are missing, based on what exists and what is selling now. Do not repeat existing ones.\n" : ($talk !== '' ? $talk : "OWNER'S SUMMARY:\n" . $summary . "\n"))
          . "\nEXISTING LISTS:\n" . ($have ? implode("\n", $have) : '(none yet)') . "\n\nWORDS (word · label · perfumes that fit):\n" . implode("\n", array_map(fn($w) => $w['word'] . ' · ' . $w['label'] . ' · ' . $w['perfumes'], $words))
          . "\n\nBEST SELLING NOW (for your judgement):\n" . wl_catalog_brief($pdo, 40);
    $user .= "\n\nCATALOG (#id website name · price · sold 90d / all · gender · words — use the ids for rename):\n" . wl_catalog_brief($pdo, 120)
          . "\n\nALL PERFUMES — for matching any name the owner says (#id website name / our name / website spelling of the original = real original):\n" . wl_names_brief($pdo);
    $askWeb = (bool)preg_match('/\b(internet|online|google|web|search|look\s*up|fetch|find out|check)\b/i', implode(' ', $turns) . ' ' . $summary);   // "fetch the long lasting from the internet"
    $j = wl_ai($pdo, $sys, $user, 5000, $askWeb); if (!$j || (empty($j['groups']) && empty($j['update']) && empty($j['delete']) && empty($j['products']) && empty($j['questions']))) return ['error' => 'Claude could not plan the lists right now. Reason: ' . ((string)($GLOBALS['wl_ai_why'] ?? '') !== '' ? $GLOBALS['wl_ai_why'] : 'no answer') . ' — try again in a minute.'];
    $fixes = wl_fixes($pdo, $j['products'] ?? []); $known = wl_prods($pdo); $kp = fn($a) => array_values(array_filter(wl_ids($a), fn($i) => isset($known[$i])));
    $okWords = array_flip(array_column($words, 'word')); $groups = [];
    foreach ((array)($j['groups'] ?? []) as $g) {
        $gl = []; foreach ((array)($g['lists'] ?? []) as $x) {
            $x['grp'] = (string)($g['name'] ?? 'Lists'); $c = wl_clean($x); if ($c['name'] === '') continue;
            $c['words'] = implode(' ', array_values(array_filter(preg_split('/\s+/', strtolower($c['words'])) ?: [], fn($w) => isset($okWords[$w]) || $w === 'best' || $w === 'sellers')));
            $c['preview'] = wl_items_view($pdo, wl_build($pdo, $c)); $gl[] = $c;
        }
        if ($gl) $groups[] = ['name' => (string)($g['name'] ?? 'Lists'), 'lists' => $gl];
    }
    $want = wl_owner_wants($turns ?: [$summary]); foreach ($groups as &$g0) foreach ($g0['lists'] as &$c0) $c0['opts'] = wl_apply_wants($c0['opts'], $want); unset($g0, $c0);
    // "all": a change the owner said without naming a list goes to EVERY list (a change for one named list still wins for that list)
    $raw = []; $all = []; foreach ((array)($j['update'] ?? []) as $u) { if (!is_array($u)) continue; if (in_array(strtolower((string)($u['id'] ?? '')), ['all', '*'], true) || !empty($u['all'])) { unset($u['id'], $u['all']); $all = $u + $all; } else $raw[(int)($u['id'] ?? 0)] = $u; }
    if ($all) { unset($all['name'], $all['pins'], $all['items'], $all['manual'], $all['n']); foreach ($byId as $id => $cur) $raw[$id] = ($raw[$id] ?? []) + $all + ['id' => $id]; }
    $upd = []; foreach (array_slice(array_values($raw), 0, 80) as $u) { if (!is_array($u)) continue; $id = (int)($u['id'] ?? 0); if (!isset($byId[$id])) continue; $cur = $byId[$id];
        foreach (['pins', 'drops', 'items'] as $k) if (isset($u[$k])) $u[$k] = $kp($u[$k]);
        $x = $u + $cur; $x['opts'] = $cur['opts']; $c = wl_clean($x); $c['opts'] = wl_apply_wants($c['opts'], $want); if ($want && !isset($u['show'])) $u['show'] = 1; /* the owner's own yes / no about notes, price… always wins */ if ($c['name'] === '') $c['name'] = $cur['name']; $c['id'] = $id;
        $c['what'] = implode(' · ', array_filter([isset($u['show']) ? 'shows: ' . (implode(', ', $c['opts']['show']) ?: 'name only') : '', isset($u['name_style']) ? 'names: ' . $c['opts']['name'] : '', !empty($u['rename']) ? count((array)$u['rename']) . ' renamed' : '', isset($u['n']) ? 'top ' . $c['n'] : '', isset($u['words']) ? 'words: ' . $c['words'] : '', isset($u['name']) ? 'name: ' . $c['name'] : '', isset($u['intro']) || isset($u['outro']) ? 'message lines' : '', !empty($u['pins']) ? 'first: ' . implode(', ', array_map(fn($i) => wl_short_title(wl_show_name($known[$i])), $c['pins'])) : '', !empty($u['drops']) ? count($c['drops']) . ' left out' : '', !empty($c['manual']) && isset($u['manual']) ? 'fixed list of ' . count($c['items']) : '']));
        $c['preview'] = wl_patch_view(wl_items_view($pdo, wl_build($pdo, $c), $c['opts']), $fixes, $c['opts']); $c['before'] = $cur['name']; $upd[] = $c; }
    // a list touched only by a perfume correction is shown too, so the owner sees the change
    $touched = array_flip(array_column($upd, 'id')); $fixIds = array_flip(array_column($fixes, 'id'));
    if ($fixIds) foreach ($byId as $id => $cur) { if (isset($touched[$id]) || !array_intersect_key(array_flip($cur['items']), $fixIds)) continue; if (count($upd) >= 60) break;
        $c = $cur; $c['what'] = 'perfume corrected'; $c['preview'] = wl_patch_view(wl_items_view($pdo, $cur['items'], $cur['opts']), $fixes, $cur['opts']); $c['before'] = $cur['name']; $c['only_fix'] = true; $upd[] = $c; }
    $del = []; foreach ((array)($j['delete'] ?? []) as $id) if (isset($byId[(int)$id])) $del[] = ['id' => (int)$id, 'name' => $byId[(int)$id]['name'], 'grp' => $byId[(int)$id]['grp']];
    foreach ($groups as &$g) foreach ($g['lists'] as &$c) { $c['preview'] = wl_patch_view(wl_items_view($pdo, wl_build($pdo, $c), $c['opts']), $fixes, $c['opts']); } unset($g, $c);
    return ['ok' => true, 'understood' => mb_substr((string)($j['understood'] ?? ''), 0, 900), 'questions' => array_values(array_slice(array_filter(array_map(function ($x) { return trim(mb_substr((string)$x, 0, 300)); }, (array)($j['questions'] ?? []))), 0, 3)), 'groups' => $groups, 'update' => $upd, 'products' => $fixes, 'delete' => $del, 'tags' => array_values(array_slice(array_map('wl_slug', (array)($j['tags'] ?? [])), 0, 30))];
}
/** ✨ one list's box: understands the instruction, returns the changed list + before / after. Not saved until the admin presses Save. */
function wl_ai_edit(PDO $pdo, int $id, string $say): array
{
    $l = wl_get($pdo, $id); if (!$l) return ['error' => 'List not found.'];
    $before = wl_items_view($pdo, $l['items'], $l['opts']); $words = wl_vocab($pdo);
    $cand = []; try { foreach (wa_prod_pick($pdo, trim($l['words'] . ' ' . $l['who']) ?: 'best sellers', 60) as $it) $cand[] = (int)$it['id']; } catch (Throwable $e) { }
    $cand = array_values(array_unique(array_merge($l['items'], $cand)));
    $sys = "LANGUAGE: write everything (understood, list name, intro, outro) in simple English unless the owner explicitly asks for another language.\n"
         . "You change ONE perfume list of TM Perfume House as the owner asks. The list is a rule (words / n / who / price) that HRMS fills from live sales, plus pins (perfumes that must be in, in this order) and drops (never in). "
         . "Understand the instruction: 'fetch now' = rebuild from live data (no other change); 'use only these two' = pins those two and n = 2; 'remove X' = add X to drops; 'add Y' = pin Y; 'make it 10' = n 10; 'only under 1000' = pmax 1000; "
         . "a product named by the owner must be matched to a #id from the CATALOG (use the closest name). manual = true only when the owner wants an exact fixed list in a fixed order (then items = the ids in order). "
         . "You also decide how the list LOOKS: show (the complete set: longevity / price / notes / gender / link — no price unless asked; 'no notes' = leave notes out), name_style (short / original = the real original perfume name, when the owner says original or real name / full = the full website title / inspired / ours) and rename {id: exact name} when the owner dictates a name for a perfume ('call Jpg Layman Elixir \"Le Male Elixir\"'). "
         . "If the owner asks to delete this list, answer {\"understood\":\"…\",\"delete\":true}. "
         . "ORDER: '1st X, 2nd Y, 3rd Z' → pins = [X, Y, Z] in that order (the rest still fills from sales). COUNT: 'make it 6' / 'add a 6th' → n = 6 and fix the number in the list name. "
         . "PERFUME FACTS: 'X long lasting is 6-8 hrs' / 'X is for women' / 'X notes are …' → products: [{id, longevity / gender / notes}] — it fixes that perfume everywhere. Match names by website name, our name, real original or sound ('AZ most wanted' = Azzaro The Most Wanted). "
         . "Use only words from the WORDS list. Answer {\"understood\":\"what you will do, in one or two short lines\",\"list\":" . wl_list_schema_txt() . " plus \"pins\":[ids],\"drops\":[ids],\"manual\":false,\"items\":[ids when manual],\"products\":[{\"id\":0,\"longevity\":\"6-8 hrs\"}]}.";
    $user = "INSTRUCTION: " . $say . "\n\nCURRENT LIST: " . json_encode(['grp' => $l['grp'], 'name' => $l['name'], 'words' => $l['words'], 'n' => $l['n'], 'who' => $l['who'], 'pmin' => $l['pmin'], 'pmax' => $l['pmax'], 'pins' => $l['pins'], 'drops' => $l['drops'], 'manual' => $l['manual'], 'intro' => $l['intro'], 'outro' => $l['outro'], 'show' => $l['opts']['show'], 'name_style' => $l['opts']['name'], 'rename' => (object)$l['opts']['rename'], 'hide_samples' => $l['opts']['hide_samples']], JSON_UNESCAPED_UNICODE)
          . "\nITEMS NOW (in order): " . implode(', ', array_map(fn($x) => '#' . $x['id'] . ' ' . $x['name'], $before))
          . "\n\nWORDS: " . implode(', ', array_column(array_filter($words, fn($w) => $w['perfumes'] > 0), 'word'))
          . "\n\nCATALOG (#id name · price · sold 90d / all · gender · words):\n" . wl_catalog_brief($pdo, 120, [])
          . "\n\nALL PERFUMES — for matching names (#id website name / our name / website spelling = real original):\n" . wl_names_brief($pdo);
    $j = wl_ai($pdo, $sys, $user, 2500, (bool)preg_match('/\b(internet|online|google|web|search|look\s*up|fetch|find out|check)\b/i', $say));
    if ($j && !empty($j['delete'])) return ['ok' => true, 'delete' => true, 'understood' => mb_substr((string)($j['understood'] ?? 'Delete this list.'), 0, 400), 'list' => ['id' => $id, 'name' => $l['name']], 'before' => $before, 'after' => []];
    if (!$j || !is_array($j['list'] ?? null)) return ['error' => 'Claude could not understand that right now' . ((string)($GLOBALS['wl_ai_why'] ?? '') !== '' ? ' (' . $GLOBALS['wl_ai_why'] . ')' : '') . ' — try again or change it by hand.'];
    $x = $j['list'] + ['pins' => $j['pins'] ?? ($j['list']['pins'] ?? $l['pins']), 'drops' => $j['drops'] ?? ($j['list']['drops'] ?? $l['drops']), 'manual' => $j['manual'] ?? ($j['list']['manual'] ?? false), 'items' => $j['items'] ?? ($j['list']['items'] ?? [])];
    $x += ['grp' => $l['grp'], 'name' => $l['name'], 'auto' => $l['auto'], 'sort' => $l['sort'], 'opts' => $l['opts']];   // what Claude leaves out stays as it is (show / names / renames)
    $c = wl_clean($x); if ($c['name'] === '') $c['name'] = $l['name']; $c['opts'] = wl_apply_wants($c['opts'], wl_owner_wants([$say]));
    $known = wl_prods($pdo); $c['pins'] = array_values(array_filter($c['pins'], fn($i) => isset($known[$i]))); $c['drops'] = array_values(array_filter($c['drops'], fn($i) => isset($known[$i]))); $c['items'] = array_values(array_filter($c['items'], fn($i) => isset($known[$i])));
    $fixes = wl_fixes($pdo, $j['products'] ?? []); $after = wl_patch_view(wl_items_view($pdo, wl_build($pdo, $c), $c['opts']), $fixes, $c['opts']);
    foreach ($before as &$v) $v['extra'] = wl_extras($v, $l['opts'], ''); unset($v);
    return ['ok' => true, 'understood' => mb_substr((string)($j['understood'] ?? ''), 0, 400), 'list' => $c + ['id' => $id], 'before' => $before, 'after' => $after, 'products' => $fixes];
}
/** Lists for one chat: the ones that match the customer's last message first. */
function wl_for_chat(PDO $pdo, string $phone): array
{
    $last = ''; try { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 3"); $q->execute([$phone]); $last = implode(' ', $q->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { }
    $parse = ($last !== '' && function_exists('wa_prod_parse')) ? wa_prod_parse($pdo, $last) : ['occ' => [], 'who' => ''];
    $want = array_merge((array)$parse['occ'], $parse['who'] !== '' ? [$parse['who']] : []);
    if (preg_match('/best ?sell|popular|trending|top \d+/i', $last)) $want[] = 'best-sellers';
    $lists = wl_all($pdo, false);
    foreach ($lists as &$l) { $w = preg_split('/\s+/', strtolower($l['words'] . ' ' . $l['who'])) ?: []; $l['match'] = $want ? count(array_intersect($want, $w)) : 0; $l['count'] = count($l['items']); } unset($l);
    usort($lists, fn($a, $b) => [$b['match'], $b['orders'], $b['sent']] <=> [$a['match'], $a['orders'], $a['sent']]);
    return ['lists' => $lists, 'asked' => $want];
}

}   // function_exists guard

$__wl = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wl, 'wl.') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !wl_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    if (!wl_prod_ready($pdo)) json_response(['error' => 'The product data is not ready — run Refresh on the Products tab first.'], 422);
    $adminOnly = ['wl.save', 'wl.delete', 'wl.plan', 'wl.plan_save', 'wl.ai_edit', 'wl.suggest', 'wl.refresh', 'wl.originals', 'wl.orig_key'];
    // who may make / change lists: the same people as the Teach board (Admin / HR / control tower, or a role with the Learning module ticked on the Roles page)
    $canEdit = (function_exists('wa_is_admin') && wa_is_admin($pdo, $auth));
    if (!$canEdit && function_exists('action_permission') && function_exists('user_can')) { $pm = action_permission('teach.board'); if ($pm !== null && user_can($auth, $pm)) $canEdit = true; }
    if (in_array($__wl, $adminOnly, true) && !$canEdit) json_response(['error' => 'Only people who can use the Teach board can change lists (Roles page → Learning).'], 403);
    $me = (int)($auth['employee_id'] ?? 0);
    switch ($__wl) {
        case 'wl.list': { try { wl_refresh_due($pdo); } catch (Throwable $e) { } json_response(['ok' => true, 'lists' => wl_all($pdo), 'words' => wl_vocab($pdo), 'admin' => $canEdit, 'role' => (string)($auth['role'] ?? ''), 'orig' => $canEdit && function_exists('wa_prod_orig_stats') ? wa_prod_orig_stats($pdo) : null]); }
        case 'wl.for_chat': {
            $key = function_exists('wa_key') ? wa_key($_GET['phone'] ?? '') : ''; $c = $key !== '' ? wa_contact($pdo, $key) : null;
            if (!$c || !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);   // only your own chat's messages are read for the ✨ match
            try { wl_refresh_due($pdo); } catch (Throwable $e) { } json_response(['ok' => true] + wl_for_chat($pdo, $key));
        }
        case 'wl.preview': {
            $l = wl_get($pdo, (int)($_GET['id'] ?? 0)); if (!$l) json_response(['error' => 'List not found.'], 404);
            $key = function_exists('wa_key') ? wa_key($_GET['phone'] ?? '') : ''; $c = $key !== '' && function_exists('wa_contact') ? wa_contact($pdo, $key) : null;
            if ($c && !wa_can_chat($pdo, $auth, $c)) $c = null;   // someone else's customer: the list text without their name
            json_response(['ok' => true, 'text' => wl_text($pdo, $l, $c ? (string)$c['name'] : ''), 'list' => $l + ['view' => wl_view_x($pdo, $l)]]);
        }
        case 'wl.send': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $l = wl_get($pdo, (int)($in['id'] ?? 0)); if (!$l) json_response(['error' => 'List not found.'], 404);
            $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $text = trim((string)($in['text'] ?? '')); if ($text === '') $text = wl_text($pdo, $l, (string)$c['name']); if ($text === '') json_response(['error' => 'This list is empty right now.'], 422);
            if (function_exists('wa_send_dup')) { $d = wa_send_dup($pdo, $key, $text, preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['cid'] ?? ''))); if ($d !== null) json_response(['ok' => true, 'duplicate' => true]); }
            [$ok, $r] = wa_send_text($pdo, $c, mb_substr($text, 0, 4000), 'emp', $me); if (!$ok) json_response(['error' => $r], 422);
            $pdo->prepare("INSERT INTO wa_list_log (list_id, phone, emp_id, at) VALUES (?, ?, ?, ?)")->execute([$l['id'], $key, $me, date('Y-m-d H:i:s')]);
            $pdo->prepare("UPDATE wa_contacts SET state = 'human', status = 'open', closed_at = NULL WHERE phone = ?")->execute([$key]);
            try { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'list', ?)")->execute([$key, 'list_' . $l['slug'], date('Y-m-d H:i:s')]); $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, ?)")->execute([$key, 'list_' . $l['slug']]); } catch (Throwable $e) { }   // a customer tag: "got the winter list" — usable in campaigns
            json_response(['ok' => true, 'wamid' => $r]);
        }
        case 'wl.save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $x = (array)($in['list'] ?? []);
            if (function_exists('wa_prod_own_set')) foreach (array_slice((array)($in['products'] ?? []), 0, 40) as $fx) if (is_array($fx) && (int)($fx['id'] ?? 0) > 0) wa_prod_own_set($pdo, (int)$fx['id'], array_intersect_key($fx, array_flip(['longevity', 'notes', 'gender', 'original'])));
            $id = wl_save($pdo, $x, (int)($x['id'] ?? 0)); if (!$id) json_response(['error' => 'Give the list a name.'], 422);
            $lv = wl_get($pdo, $id) ?: ['items' => [], 'opts' => []]; json_response(['ok' => true, 'id' => $id, 'list' => $lv + ['view' => wl_view_x($pdo, $lv)]]);
        }
        case 'wl.delete': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $pdo->prepare("DELETE FROM wa_lists WHERE id = ?")->execute([(int)(json_input()['id'] ?? 0)]); json_response(['ok' => true]); }
        case 'wl.orig_key': {   // which Shopify field holds the real original name
            if (!$isPost || !function_exists('wa_prod_orig_key_set')) json_response(['error' => 'Not available.'], 405);
            $n = wa_prod_orig_key_set($pdo, (string)(json_input()['key'] ?? '')); wl_refresh_due($pdo, true); json_response(['ok' => true, 'found' => $n, 'orig' => wa_prod_orig_stats($pdo)]);
        }
        case 'wl.originals': {   // 🌐 look up the real original perfume online for the ones still unknown
            if (!$isPost || !function_exists('wa_prod_web_originals')) json_response(['error' => 'Not available.'], 405);
            @set_time_limit(250); $r = wa_prod_web_originals($pdo, 12); if (!$r['ok'] && $r['error'] !== '') json_response(['error' => 'Could not look them up: ' . $r['error']], 502);
            $f = ['filled' => 0, 'left' => 0]; if (!$r['asked'] && function_exists('wa_prod_web_fill')) { $f = wa_prod_web_fill($pdo, 10); if (!$f['ok'] && $f['error'] !== '') json_response(['error' => 'Could not look them up: ' . $f['error']], 502); }   // every original known → fill the missing long lasting / notes with it
            if ($r['found'] || $f['filled']) wl_refresh_due($pdo, true); json_response(['ok' => true, 'filled' => $f['filled'], 'fill_left' => $f['left'], 'orig' => wa_prod_orig_stats($pdo)] + $r);
        }
        case 'wl.refresh': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); json_response(['ok' => true, 'rebuilt' => wl_refresh_due($pdo, true)]); }
        case 'wl.plan': case 'wl.suggest': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); @set_time_limit(150);
            $in = json_input(); $sum = trim((string)($in['summary'] ?? '')); $turns = array_values(array_filter(array_map('strval', (array)($in['turns'] ?? []))));
            if ($__wl === 'wl.plan' && mb_strlen($sum) < 8 && !$turns) json_response(['error' => 'Write a few words about what lists you want.'], 422);
            $r = wl_plan($pdo, $sum, $__wl === 'wl.suggest' ? 'suggest' : 'plan', $turns, $in['prev'] ?? null); if (isset($r['error'])) json_response($r, 502); json_response($r);
        }
        case 'wl.plan_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $n = 0; $s = (int)$pdo->query("SELECT COALESCE(MAX(sort), 0) FROM wa_lists")->fetchColumn();
            $in = json_input(); foreach ((array)($in['groups'] ?? []) as $g) foreach ((array)($g['lists'] ?? []) as $x) { $x['grp'] = (string)($g['name'] ?? ($x['grp'] ?? 'Lists')); $x['sort'] = ++$s; if (wl_save($pdo, $x)) $n++; }
            $f = 0; if (function_exists('wa_prod_own_set')) foreach (array_slice((array)($in['products'] ?? []), 0, 40) as $fx) { if (is_array($fx) && (int)($fx['id'] ?? 0) > 0) { wa_prod_own_set($pdo, (int)$fx['id'], array_intersect_key($fx, array_flip(['longevity', 'notes', 'gender', 'original']))); $f++; } }   // the owner's perfume corrections
            $u = 0; foreach ((array)($in['update'] ?? []) as $x) { $id = (int)($x['id'] ?? 0); $cur = $id > 0 ? wl_get($pdo, $id) : null; if (!$cur || !empty($x['only_fix'])) continue; unset($x['preview'], $x['what'], $x['before']); if (wl_save($pdo, (array)$x + $cur, $id)) $u++; }   // what is not in the change keeps its current value   // Claude's changes to existing lists (the ticked ones)
            $d = 0; foreach ((array)($in['delete'] ?? []) as $id) { $q = $pdo->prepare("DELETE FROM wa_lists WHERE id = ?"); $q->execute([(int)$id]); $d += $q->rowCount(); }
            if ($f || $u) { try { wl_refresh_due($pdo, true); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'saved' => $n, 'updated' => $u, 'deleted' => $d, 'fixed' => $f]);
        }
        case 'wl.ai_edit': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); @set_time_limit(120);
            $in = json_input(); $say = trim((string)($in['say'] ?? '')); if ($say === '') json_response(['error' => 'Tell Claude what to change.'], 422);
            $r = wl_ai_edit($pdo, (int)($in['id'] ?? 0), $say); if (isset($r['error'])) json_response($r, 502); json_response($r);
        }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
