<?php
/**
 * sku_sheet.php — the SKU sheet (Sales → Orders → SKU sheet, Dispatch → SKU sheet): how many of each perfume and size were ordered on ONE business day.
 *
 *   Reads the day's orders LIVE: every Shopify store's orders with their product lines, and the manual orders (WM-xxxxx). Cancelled orders are left out.
 *   Each product line is matched to a SKU of the SKU list (SKU · original name · the name used in the shop / HRMS) and its quantity goes into the column of its
 *   size or kind: 10ml · 20ml · 50/60ml · 100ml · Shower Gel · Solid Perfume · Beard Balm · Essential Oil (Attar) · Soap · Car Perfume.
 *   A COMBO line (one line that holds several perfumes) is opened: the perfumes written in the line's own details (Shopify "properties") or in its title are matched one by one.
 *   NOTHING is dropped silently: a line, or a perfume inside a combo, that matches no SKU (or has a size the sheet has no column for) is returned in `unmatched`, and the page
 *   shows that list on top.
 *
 *   The SKU list: sku_list.php next to this file (from the owner's Excel), replaced by the file the owner uploads on the page (kept in uploads/ under a name that cannot be guessed).
 *   Actions: sku.sheet (GET day) · sku.list (GET) · sku.list_save (POST rows — Admin / HR)
 */
if (!function_exists('sku_cols')) {

function sku_cols(): array { return ['10ml', '20ml', '50/60ml', '100ml', 'Shower Gel', 'Solid Perfume', 'Beard Balm', 'Essential Oil (Attar)', 'Soap', 'Car Perfume']; }

function sku_list(): array
{
    static $l = null; if ($l !== null) return $l;
    // an uploaded list is kept under a name nobody can guess (the name is in the settings), so the list cannot be downloaded by its address
    $up = sku_list_file(); if ($up !== '' && is_file($up)) { $j = json_decode((string)file_get_contents($up), true); if (is_array($j) && $j) return $l = $j; }
    if (is_file(__DIR__ . '/sku_list.php')) { $j = require __DIR__ . '/sku_list.php'; if (is_array($j) && $j) return $l = $j; }
    return $l = [];
}

function sku_list_file(): string
{
    try { $pdo = $GLOBALS['pdo'] ?? null; $n = ($pdo instanceof PDO && function_exists('setting_get')) ? (string)setting_get($pdo, 'sku_list_file', '') : ''; return preg_match('/^skul_[a-f0-9]{24}\.json$/', $n) ? __DIR__ . '/uploads/' . $n : ''; } catch (Throwable $e) { return ''; }
}

/** A name with everything that is not the perfume's own name taken out: shop suffix, size, bottle colour, filler words, punctuation. */
function sku_fold(string $t): string
{   // accents off, so "Café" = "Cafe", "Lumière" = "Lumiere", "Oudélia" = "Oudelia"
    if (class_exists('Normalizer')) { $d = Normalizer::normalize($t, Normalizer::FORM_D); if ($d !== false) $t = preg_replace('/\p{Mn}+/u', '', $d); }
    return strtr($t, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
}
function sku_norm(string $t): string
{
    $t = sku_fold(mb_strtolower($t));
    $t = preg_replace('/[|｜].*$/u', ' ', $t);                                         // "… | TMPerfumehouse"
    $t = preg_replace('/\(.*?\)/u', ' ', $t);
    $t = preg_replace('/\d+(\.\d+)?\s*-?\s*(ml|gm|g|pcs|pc)\b/u', ' ', $t);
    $t = preg_replace('/\b(dg|lg|wh|edp|edt|perfume|perfumes|parfum|inspired by|inspired|for men|for women|unisex|tmph|tm perfume house|tmperfumehouse|pack of \d+|free|combo|bottle)\b/u', ' ', $t);
    $t = str_replace(['&', '’', "'", '`'], [' and ', '', '', ''], $t);
    $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** [normalised name => sku] for the shop / HRMS names and the original names, and [sku => original name]. */
function sku_index(): array
{
    static $ix = null; if ($ix !== null) return $ix;
    $by = []; $name = []; $ids = [];
    foreach (sku_list() as $r) {
        $sku = (string)($r[0] ?? ''); if ($sku === '') continue; $name[$sku] = (string)($r[1] ?? ''); $ids[mb_strtolower($sku)] = $sku;
        $names = preg_split('/\s*[;\r\n]+\s*/u', (string)($r[2] ?? '')) ?: []; $names[] = (string)($r[1] ?? '');      // the third column may hold several shop names: "GUCH ESHA & FLORA ; Guchhi Esha & Flora"
        foreach ($names as $n) { $k = sku_norm((string)$n); if ($k !== '' && !isset($by[$k])) $by[$k] = $sku; }      // the shop / HRMS names win over the original name
    }
    uksort($by, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));       // the longest name first: "afn 9 night elixir" before "afn 9 night"
    return $ix = ['by' => $by, 'name' => $name, 'ids' => $ids];
}

/** The SKU a text names, or ''. Exact name first; then the longest list name that is inside the text as whole words (at least 5 letters, so short names cannot hit by accident). */
function sku_match(string $text, string $shopSku = ''): string
{
    $ix = sku_index();
    $s = mb_strtolower(trim($shopSku)); if ($s !== '') { if (isset($ix['ids'][$s])) return $ix['ids'][$s]; if (preg_match('/^(og\d+|\d+)(?=[^0-9a-z]|$)/', $s, $m) && isset($ix['ids'][$m[1]]) && !preg_match('/^\d+\s*ml/', $s)) return $ix['ids'][$m[1]]; }
    $n = sku_norm($text); if ($n === '') return '';
    if (isset($ix['by'][$n])) return $ix['by'][$n];
    $pad = ' ' . $n . ' ';
    foreach ($ix['by'] as $k => $sku) { if (mb_strlen($k) >= 5 && mb_strpos($pad, ' ' . $k . ' ') !== false) return $sku; }
    return '';
}

/** The sheet column of a line (0–9) from its words and size, or -1 when the sheet has no column for it. $why says what was found. */
function sku_col(string $text, ?string &$why = null): int
{
    $t = mb_strtolower($text);
    if (preg_match('/shower ?gel|body ?wash/u', $t)) return 4;
    if (preg_match('/solid ?perfume|\bsolid\b|\bwax\b/u', $t)) return 5;
    if (preg_match('/beard/u', $t)) return 6;
    if (preg_match('/\battar\b|\bittar\b|essential ?oil|roll[- ]?on|\boud oil\b/u', $t)) return 7;
    if (preg_match('/\bsoap\b/u', $t)) return 8;
    if (preg_match('/\bcar\b.*(perfume|freshener|fragrance)|car ?perfume/u', $t)) return 9;
    if (preg_match('/(?<![0-9.])(\d{1,4})\s*-?\s*ml(?![a-z])/u', $t, $m) || preg_match('/(?<![0-9.])(10|20|50|60|100)\s*m(?![a-z0-9])/u', $t, $m)) { $ml = (int)$m[1]; if ($ml === 10) return 0; if ($ml === 20) return 1; if ($ml === 50 || $ml === 60) return 2; if ($ml === 100) return 3; $why = $ml . ' ml has no column in the sheet'; return -1; }   // "50m" (the l forgotten) counts as 50 ml
    $why = 'no size found'; return -1;
}

/** The perfumes written in one text: the whole text when it names a SKU; else its pieces (new line , ; + /), and a piece that still names nothing is cut once more at & / "and". */
function sku_pieces(string $v): array
{
    $v = trim($v); if ($v === '') return [];
    $out = []; $named = 0;
    foreach (preg_split('/\s*(?:\r?\n|,|;|\+|\s\/\s|\s\|\s)\s*/u', $v) as $x) {
        $x = trim($x); if ($x === '') continue;
        if (sku_match($x) !== '') { $out[] = $x; $named++; continue; }
        $sub = array_values(array_filter(array_map('trim', preg_split('/\s*(?:&|\band\b)\s*/iu', $x)), fn($y) => $y !== ''));
        $hit = 0; foreach ($sub as $y) if (sku_match($y) !== '') $hit++;
        if ($hit > 0) { foreach ($sub as $y) $out[] = $y; $named += $hit; } else $out[] = $x;
    }
    if ($named < 2 && count($out) > 1 && sku_match($v) !== '') return [$v];      // ONE perfume whose own name has a comma / plus in it: keep it whole
    return $out;
}

/** One product line → adds to $grid / $bad. $parts = the texts of the line: title, variant, its own details (properties). */
function sku_take(array &$grid, array &$bad, string $order, string $title, string $variant, string $shopSku, int $qty, array $props): void
{
    if ($qty <= 0) return;
    $full = trim($title . ' ' . $variant);
    if (preg_match('/(?<![a-z0-9])chain+s?(?![a-z0-9])|necklace|bracelet|pendant|(?<![a-z0-9])(weave|solenne|veyra|lunelle|velora|lunara|elysian|seraphine|aurelia|herring ?bone)(?![a-z0-9])/i', $full)) { $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => 'jewellery: not in the SKU sheet', 'kind' => 'left']; return; }
    if (preg_match('/^\s*(tmperfumehouse|tm perfume house)\s*x\s*\d+\s*\(\s*inside:\s*build your own/i', $full)) { $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => 'build-your-own pack: the perfumes are not named on the order', 'kind' => 'left']; return; }
    $put = function (string $sku, int $col, int $q) use (&$grid, $order) { if (!isset($grid[$sku])) $grid[$sku] = array_fill(0, 10, 0); $grid[$sku][$col] += $q; $GLOBALS['__sku_ord'][$sku][$col][$order] = ($GLOBALS['__sku_ord'][$sku][$col][$order] ?? 0) + $q; };      // which orders make each number of the sheet
    if (preg_match('/^\s*no[ -]?name\b/i', $full) || preg_match('/^NONAME-\d+/i', $shopSku)) {      // 🏷 "No name 50 ml" (a perfume sold without a name, from the manual orders): counted on its own row "No Name", in its size column
        $w1 = ''; $c1 = sku_col($full, $w1); if ($c1 < 0 && preg_match('/^NONAME-(\d+)/i', $shopSku, $m1)) $c1 = sku_col($m1[1] . ' ml', $w1);
        if ($c1 >= 0) { $put('NONAME', $c1, $qty); return; }
        $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => 'No name perfume: size not known', 'kind' => 'size']; return;
    }
    if (preg_match('/^\s*complimentary\b/i', $full)) {      // "complimentary 10ml": a free sample — its perfume is not written on the order, so it is counted on its own row "Complimentary (free gift)" in its size column
        $w0 = ''; $c0 = sku_col($full, $w0); if ($c0 >= 0) { $put('FREE', $c0, $qty); return; }
        $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => 'free gift: size not known', 'kind' => 'size']; return;
    }
    // 1. a single product: the line itself names one SKU
    $props = array_values(array_filter($props, fn($p) => is_array($p) && (string)($p['name'] ?? '') !== '' && (string)$p['name'][0] !== '_'));      // hidden details (names starting with _) are the shop's own bookkeeping
    $sku = sku_match($title, $shopSku); $why = '';
    $inTitle = 0; foreach (sku_pieces($full) as $x) if (sku_match($x) !== '') $inTitle++;      // 2 or more perfumes written in the line = a combo, even when the whole line also happens to name one
    if ($sku !== '' && !$props && $inTitle < 2) {
        $col = sku_col($full, $why); if ($col >= 0) { $put($sku, $col, $qty); return; }
        if (preg_match('/^OG\d/', $sku) || preg_match('/compliment getter|dark side|caffeine club|dress to impress/i', $full)) { $put($sku, 2, $qty); return; }      // TM Originals (Dress to Impress: Compliment Getter / Dark Side / Caffeine Club) are written without a size on a "build your own original combo" line: they are the 50 ml bottles
        $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => $why ?: 'size not known', 'kind' => 'size']; return;
    }
    // 2. a combo: the perfumes are in the line's details, or written in the title / variant separated by + , / or a new line (and, only when that finds nothing, by & / "and":
    //    many perfume names have "&" inside, so a whole text that names a SKU is never cut)
    $cands = [];
    foreach ($props as $p) { $v = trim((string)(is_scalar($p['value'] ?? '') ? ($p['value'] ?? '') : '')); if ($v === '') continue; foreach (sku_pieces($v) as $x) $cands[] = [$x, (string)($p['name'] ?? '')]; }
    if (!$cands) foreach (sku_pieces($full) as $x) $cands[] = [$x, ''];
    $hit = 0; $miss = [];
    foreach ($cands as [$c, $nm]) {
        $s2 = sku_match($c); if ($s2 === '') {
            if (sku_norm($c) !== '' && !preg_match('/^(x\s*)?\d+$/', sku_norm($c))) {      // a box item whose name is not in the SKU list: counted on its own row "Not in SKU list" (its size and quantity are read like any other box item) and still listed in the red box
                $w3 = ''; $col3 = sku_col($c . ' ' . $nm, $w3); if ($col3 < 0) $col3 = sku_col($full, $w3); $n3 = 1; if (preg_match('/^\s*(\d{1,2})\s*[x×]\s+/iu', $c, $m3)) $n3 = max(1, (int)$m3[1]);
                $miss[] = [$c, $col3, $n3];
            }
            continue;
        }
        $w2 = ''; $col = sku_col($c . ' ' . $nm, $w2); if ($col < 0) $col = sku_col($full, $w2);
        if ($col < 0) { $bad[] = ['order' => $order, 'text' => $c . ' (inside: ' . $full . ')', 'qty' => $qty, 'why' => $w2 ?: 'size not known', 'kind' => 'size']; $hit++; continue; }
        $n = 1; if (preg_match('/^\s*(\d{1,2})\s*[x×]\s+/iu', $c, $m)) $n = max(1, (int)$m[1]);
        $put($s2, $col, $qty * $n); $hit++;
    }
    if ($hit > 0) {
        foreach ($miss as [$c, $col3, $n3]) {
            if ($col3 >= 0) { $put('NOTFOUND', $col3, $qty * $n3); $bad[] = ['order' => $order, 'text' => $c . ' (inside: ' . $full . ')', 'qty' => $qty * $n3, 'why' => 'this part of a combo matches no SKU — counted on the row "Not in SKU list"', 'kind' => 'counted']; }
            else $bad[] = ['order' => $order, 'text' => $c . ' (inside: ' . $full . ')', 'qty' => $qty, 'why' => 'this part of a combo matches no SKU', 'kind' => 'name'];
        }
        return;
    }
    if ($sku !== '') { $col = sku_col($full, $why); if ($col >= 0) { $put($sku, $col, $qty); return; } if (preg_match('/^OG\d/', $sku) || preg_match('/compliment getter|dark side|caffeine club|dress to impress/i', $full)) { $put($sku, 2, $qty); return; } $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => $why ?: 'size not known', 'kind' => 'size']; return; }
    $det = []; foreach ($props as $p) $det[] = $p['name'] . ': ' . (is_scalar($p['value'] ?? '') ? $p['value'] : json_encode($p['value']));
    $bad[] = ['order' => $order, 'text' => $full . ($det ? ' — details: ' . mb_substr(implode(' · ', $det), 0, 300) : ''), 'qty' => $qty, 'why' => 'this product matches no SKU' . ($shopSku !== '' ? ' (shop SKU "' . $shopSku . '")' : ''), 'kind' => 'name'];
}

/** Every order of one business day with its product lines, straight from Shopify (all pages). Returns [orders, errors]. */
function sku_shopify_day(PDO $pdo, string $day, string $to = '', ?array $winOverride = null): array
{
    $out = []; $errs = []; $win = $winOverride ?: biz_window($pdo, $day, $to !== '' ? $to : $day);
    foreach (shopify_stores() as $key => $store) {
        if (strpos((string)($store['client_id'] ?? ''), 'PASTE_') !== false) continue;
        $tok = shopify_get_token($key, $store); if (is_array($tok)) { $errs[] = ($store['label'] ?? $key) . ': ' . ($tok['error'] ?? 'no access'); continue; }
        $url = "https://{$store['domain']}/admin/api/2024-10/orders.json?" . http_build_query(['status' => 'any', 'limit' => 250, 'created_at_min' => str_replace(' ', 'T', $win[0]) . '+05:30', 'created_at_max' => str_replace(' ', 'T', $win[1]) . '+05:30', 'fields' => 'id,name,created_at,cancelled_at,line_items,note_attributes']);
        for ($page = 0; $page < 40 && $url; $page++) {
            $hdr = []; $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $tok, 'Content-Type: application/json'], CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { $hdr[] = $h; return strlen($h); }]);
            $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code === 429) { usleep(1200000); $page--; static $retry = 0; if (++$retry > 8) { $errs[] = ($store['label'] ?? $key) . ': Shopify is busy (429)'; break; } continue; }
            if ($resp === false || $code !== 200) { $errs[] = ($store['label'] ?? $key) . ': Shopify answered HTTP ' . $code; break; }
            foreach ((array)(json_decode((string)$resp, true)['orders'] ?? []) as $o) { $o['_store'] = $key; $out[] = $o; }
            $url = ''; foreach ($hdr as $h) if (stripos($h, 'link:') === 0 && preg_match('/<([^>]+)>;\s*rel="next"/i', $h, $m)) $url = $m[1];
        }
    }
    return [$out, $errs];
}

/** ⛓ Every product line whose name has "chain" in it (any shop, any spelling), over a range of business days: which chain, how many pieces, in how many orders, how much money. Cancelled orders left out. */
function sku_chain(PDO $pdo, string $from, string $to, bool $noon = false): array
{
    // 🕛 $noon (kept as the name of the switch) = one calendar day of 24 hours: 12:00 AM on $from to 12:00 AM the next day (not the 10 AM business day)
    $nw = $noon ? [$from . ' 00:00:00', $from . ' 23:59:59'] : null;
    $by = []; $nOrd = 0; $nManC = 0; $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
    $add = function (string $title, string $var, string $sku, int $q, float $amt, string $order) use (&$by) {
        $name = trim($title . ($var !== '' && stripos($title, $var) === false && strcasecmp($var, 'Default Title') !== 0 ? ' - ' . $var : ''));
        if ($name === '' || stripos($name, 'chain') === false) return;
        $k = mb_strtolower($name); if (!isset($by[$k])) $by[$k] = ['name' => $name, 'sku' => $sku, 'qty' => 0, 'amount' => 0.0, 'orders' => []];
        $by[$k]['qty'] += $q; $by[$k]['amount'] += $amt; if ($sku !== '' && $by[$k]['sku'] === '') $by[$k]['sku'] = $sku; $by[$k]['orders'][$order] = 1;
    };
    [$orders, $errs] = sku_shopify_day($pdo, $from, $to, $nw);
    foreach ($orders as $o) {
        if (!empty($o['cancelled_at']) || isset($cancel[$o['_store'] . '|' . (string)$o['id']])) continue; $nOrd++;
        foreach ((array)($o['line_items'] ?? []) as $li) {
            $q = (int)($li['quantity'] ?? 0); if (isset($li['current_quantity'])) $q = min($q, max(0, (int)$li['current_quantity'])); if ($q <= 0) continue;
            $add((string)($li['title'] ?? ''), (string)($li['variant_title'] ?? ''), (string)($li['sku'] ?? ''), $q, (float)($li['price'] ?? 0) * $q, (string)($o['name'] ?? ''));
        }
    }
    try {   // the manual orders (WM-xxxxx) confirmed in the range
        $win = $nw ?: biz_window($pdo, $from, $to);
        $q = $pdo->prepare("SELECT order_no, lines_json FROM wmo_order WHERE status = 'confirmed' AND COALESCE(confirmed_at, created_at) BETWEEN ? AND ?"); $q->execute([$win[0], $win[1]]);
        foreach ($q->fetchAll() as $r) { $nManC++; foreach ((array)(json_decode((string)$r['lines_json'], true) ?: []) as $l) {
            $qq = max(1, (int)($l['qty'] ?? 1)); $add((string)($l['title'] ?? ($l['short'] ?? '')), (string)($l['label'] ?? ''), (string)($l['sku'] ?? ''), $qq, (float)($l['price'] ?? 0) * $qq, (string)$r['order_no']);
        } }
    } catch (Throwable $e) { $errs[] = 'Manual orders: ' . $e->getMessage(); }
    $rows = []; $pieces = 0; $money = 0.0; $withChain = [];
    foreach ($by as $r) { foreach ($r['orders'] as $__on => $__v) $withChain[$__on] = 1; $rows[] = ['name' => $r['name'], 'sku' => $r['sku'], 'qty' => $r['qty'], 'orders' => count($r['orders']), 'amount' => round($r['amount'], 2), 'order_list' => array_slice(array_keys($r['orders']), 0, 60)]; $pieces += $r['qty']; $money += $r['amount']; }
    usort($rows, fn($a, $b) => $b['qty'] <=> $a['qty'] ?: strcmp($a['name'], $b['name']));
    return ['from' => $from, 'to' => $to, 'noon' => $noon, 'window' => $nw, 'rows' => $rows, 'pieces' => $pieces, 'amount' => round($money, 2), 'orders_read' => $nOrd + $nManC, 'orders_with' => count($withChain), 'errors' => $errs];
}

/** The whole sheet of one business day. */
function sku_sheet(PDO $pdo, string $day): array
{
    $grid = []; $bad = []; $nOrd = 0; $nCancel = 0; $nLines = 0; $nMan = 0; $GLOBALS['__sku_ord'] = []; $oid = [];
    // 🕛 the sheet is one calendar day of 24 hours: 12:00 AM to 12:00 AM (not the 10 AM business day)
    $cal = [$day . ' 00:00:00', $day . ' 23:59:59'];
    [$orders, $errs] = sku_shopify_day($pdo, $day, '', $cal);
    $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
    foreach ($orders as $o) {
        if (!empty($o['cancelled_at']) || isset($cancel[$o['_store'] . '|' . (string)$o['id']])) { $nCancel++; continue; }
        $nOrd++; $oid[(string)($o['name'] ?? '')] = [(string)$o['_store'], (string)($o['id'] ?? '')];
        $__bp = order_box_attr_props($o);      // "Box contents" kept as order details (not on the line): used for the ONE build-your-own line
        foreach (array_values((array)($o['line_items'] ?? [])) as $__i => $li) {
            $q = (int)($li['quantity'] ?? 0); if (isset($li['current_quantity'])) $q = min($q, max(0, (int)$li['current_quantity'])); if ($q <= 0) continue;      // a line removed from the order after it was placed
            $nLines++; sku_take($grid, $bad, (string)($o['name'] ?? ''), (string)($li['title'] ?? ''), (string)($li['variant_title'] ?? ''), (string)($li['sku'] ?? ''), $q, ($__bp[$__i] ?? (array)($li['properties'] ?? [])));
        }
    }
    // the manual orders (WM-xxxxx) confirmed on that day (12 AM to 12 AM)
    try {
        $win = $cal;
        $q = $pdo->prepare("SELECT id, order_no, lines_json FROM wmo_order WHERE status = 'confirmed' AND COALESCE(confirmed_at, created_at) BETWEEN ? AND ?"); $q->execute([$win[0], $win[1]]);
        foreach ($q->fetchAll() as $r) {
            $nMan++; $oid[(string)$r['order_no']] = [(string)(array_key_first(shopify_stores()) ?? ''), (string)(7000000000000000 + (int)$r['id'])];      // the order popup opens a manual order with its id + 7,000,000,000,000,000
            foreach ((array)(json_decode((string)$r['lines_json'], true) ?: []) as $l) { $nLines++; sku_take($grid, $bad, (string)$r['order_no'], (string)($l['title'] ?? ($l['short'] ?? '')), (string)($l['label'] ?? ''), (string)($l['sku'] ?? ''), max(1, (int)($l['qty'] ?? 1)), []); }
        }
    } catch (Throwable $e) { }
    $ix = sku_index(); $rows = [];
    foreach ($grid as $sku => $q) $rows[] = ['sku' => (string)$sku, 'name' => $sku === 'FREE' ? 'Complimentary (free gift)' : ($sku === 'NONAME' ? 'No Name (perfume sold without a name)' : ($sku === 'NOTFOUND' ? 'Not in SKU list (names are in the box above)' : (string)($ix['name'][$sku] ?? 'Not Found'))), 'q' => $q, 'total' => array_sum($q), 'o' => (function ($sk) { $m = []; foreach (($GLOBALS['__sku_ord'][$sk] ?? []) as $c => $L) { $x = []; foreach ($L as $on => $n) $x[] = [(string)$on, (int)$n]; $m[$c] = $x; } return (object)$m; })($sku)];
    usort($rows, function ($a, $b) { $la = ['FREE' => 1, 'NONAME' => 1, 'NOTFOUND' => 2][$a['sku']] ?? 0; $lb = ['FREE' => 1, 'NONAME' => 1, 'NOTFOUND' => 2][$b['sku']] ?? 0; if ($la !== $lb) return $la <=> $lb; $x = ctype_digit($a['sku']) ? (int)$a['sku'] : PHP_INT_MAX; $y = ctype_digit($b['sku']) ? (int)$b['sku'] : PHP_INT_MAX; return $x <=> $y ?: strcmp($a['sku'], $b['sku']); });
    $tot = array_fill(0, 10, 0); foreach ($rows as $r) foreach ($r['q'] as $i => $v) $tot[$i] += $v;
    // the list on top of the page: the same product from many orders is ONE line with its pieces and its orders
    $grp = [];
    foreach ($bad as $b) { $k = $b['kind'] . '|' . mb_strtolower($b['text']) . '|' . $b['why']; if (!isset($grp[$k])) $grp[$k] = ['text' => $b['text'], 'why' => $b['why'], 'kind' => $b['kind'], 'qty' => 0, 'orders' => []]; $grp[$k]['qty'] += $b['qty']; if (count($grp[$k]['orders']) < 12 && !in_array($b['order'], $grp[$k]['orders'], true)) $grp[$k]['orders'][] = $b['order']; }
    $un = array_values($grp); usort($un, fn($a, $b) => ($a['kind'] === 'left') <=> ($b['kind'] === 'left') ?: $b['qty'] <=> $a['qty']);
    return ['day' => $day, 'cols' => sku_cols(), 'rows' => $rows, 'totals' => $tot, 'pieces' => array_sum($tot), 'orders' => $nOrd, 'manual' => $nMan, 'cancelled' => $nCancel, 'lines' => $nLines,
        'oid' => (object)$oid, 'unmatched' => array_values(array_filter($un, fn($x) => !in_array($x['kind'], ['left', 'counted'], true))), 'left_out' => array_values(array_filter($un, fn($x) => $x['kind'] === 'left')), 'noted' => array_values(array_filter($un, fn($x) => $x['kind'] === 'counted')),
        'unmatched_pieces' => array_sum(array_map(fn($x) => !in_array($x['kind'], ['left', 'counted'], true) ? $x['qty'] : 0, $un)), 'errors' => $errs, 'sku_count' => count(sku_list())];
}

/** Who may open the SKU sheet: Admin, HR, a role ticked 'Orders', and the SAME people as the Dispatch "Manual orders" page — everyone whose department is Dispatch (or Admin),
 *  whose designation says Dispatch or Sales Head, the Boss-engine managers, and the holders of a Dispatch seat. So a Dispatch person needs no extra tick.
 *  Any other role gets it with the 'SKU sheet' tick on the Roles page. */
function sku_can(PDO $pdo, array $auth): bool
{
    if (in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'sku_sheet') || user_can($auth, 'orders')) return true;      // 'sku_sheet' = the tick on the Roles page
    $e = (int)($auth['employee_id'] ?? 0); if ($e <= 0) return false;
    try {
        $q = $pdo->prepare("SELECT d.department_name, e.designation FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?"); $q->execute([$e]); $r = $q->fetch();
        if ($r && (preg_match('/dispatch|admin/i', (string)$r['department_name']) || preg_match('/sales\s*head|dispatch/i', (string)$r['designation']))) return true;
    } catch (Throwable $ex) { }
    try { if (function_exists('bd_who') && !empty(bd_who($pdo, $auth)['manager'])) return true; } catch (Throwable $ex) { }
    try { if (function_exists('dp_can_seat') && (dp_can_seat($pdo, $auth, 'dispatch.tracking') || dp_can_seat($pdo, $auth, 'dispatch.lead'))) return true; } catch (Throwable $ex) { }
    return false;
}

function sku_route(PDO $pdo, string $action): void
{
    $auth = require_auth(); $role = (string)($auth['role'] ?? '');
    if ($action === 'sku.access') json_response(['ok' => sku_can($pdo, $auth)]);
    if (!sku_can($pdo, $auth)) json_response(['error' => 'The SKU sheet is for Admin, HR, Dispatch, the Admin department, Sales Heads and roles with the Orders page.'], 403);
    if ($action === 'sku.sheet') {
        $day = (string)($_GET['day'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = gmdate('Y-m-d', time() + 19800);      // today, India time (12 AM to 12 AM)
        if (!sku_list()) json_response(['error' => 'The SKU list is empty. Upload the SKU file (SKU · original name · shop name) on this page.'], 422);
        @set_time_limit(180); json_response(sku_sheet($pdo, $day) + ['today' => gmdate('Y-m-d', time() + 19800)]);
    }
    if ($action === 'sku.chain') {
        $f = (string)($_GET['from'] ?? ''); $t = (string)($_GET['to'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) $f = gmdate('Y-m-d', time() + 19800); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || $t < $f) $t = $f;
        if ((strtotime($t) - strtotime($f)) / 86400 > 62) json_response(['error' => 'Pick at most 62 days.'], 422);
        $noon = !empty($_GET['noon']); if ($noon) $t = $f;
        @set_time_limit(240); json_response(sku_chain($pdo, $f, $t, $noon));
    }
    if ($action === 'sku.list') json_response(['rows' => sku_list(), 'custom' => sku_list_file() !== '']);
    if ($action === 'sku.list_save') {
        if (!in_array($role, ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin / HR can replace the SKU list.'], 403);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
        $rows = []; $seen = [];
        foreach ((array)(json_input()['rows'] ?? []) as $r) { if (!is_array($r)) continue; $s = trim((string)($r[0] ?? '')); if ($s === '' || isset($seen[mb_strtolower($s)]) || preg_match('/^sku$/i', $s)) continue; $seen[mb_strtolower($s)] = 1; $rows[] = [mb_substr($s, 0, 20), mb_substr(trim((string)($r[1] ?? '')), 0, 160), mb_substr(trim((string)($r[2] ?? '')), 0, 800)]; }
        if (count($rows) < 5) json_response(['error' => 'That file has fewer than 5 SKUs. The first three columns must be: SKU · original name · shop name.'], 422);
        if (count($rows) > 5000) json_response(['error' => 'At most 5,000 SKUs.'], 422);
        $dir = __DIR__ . '/uploads'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $old = sku_list_file(); $name = 'skul_' . bin2hex(random_bytes(12)) . '.json';
        if (@file_put_contents($dir . '/' . $name, json_encode($rows, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) json_response(['error' => 'The server could not save the file (uploads folder not writable).'], 500);
        setting_set($pdo, 'sku_list_file', $name); if ($old !== '' && is_file($old)) @unlink($old);
        json_response(['ok' => true, 'message' => count($rows) . ' SKUs saved.', 'count' => count($rows)]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}

}
