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
function sku_norm(string $t): string
{
    $t = mb_strtolower($t);
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
        foreach ([(string)($r[2] ?? ''), (string)($r[1] ?? '')] as $n) { $k = sku_norm($n); if ($k !== '' && !isset($by[$k])) $by[$k] = $sku; }      // the shop / HRMS name wins over the original name
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
    if (preg_match('/(?<![0-9.])(\d{1,4})\s*-?\s*ml(?![a-z])/u', $t, $m)) { $ml = (int)$m[1]; if ($ml === 10) return 0; if ($ml === 20) return 1; if ($ml === 50 || $ml === 60) return 2; if ($ml === 100) return 3; $why = $ml . ' ml has no column in the sheet'; return -1; }
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
    if (preg_match('/(?<![a-z0-9])chains?(?![a-z0-9])|necklace|bracelet|pendant/i', $full)) { $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => 'jewellery: not in the SKU sheet', 'kind' => 'left']; return; }
    $put = function (string $sku, int $col, int $q) use (&$grid) { if (!isset($grid[$sku])) $grid[$sku] = array_fill(0, 10, 0); $grid[$sku][$col] += $q; };
    // 1. a single product: the line itself names one SKU
    $props = array_values(array_filter($props, fn($p) => is_array($p) && (string)($p['name'] ?? '') !== '' && (string)$p['name'][0] !== '_'));      // hidden details (names starting with _) are the shop's own bookkeeping
    $sku = sku_match($title, $shopSku); $why = '';
    $inTitle = 0; foreach (sku_pieces($full) as $x) if (sku_match($x) !== '') $inTitle++;      // 2 or more perfumes written in the line = a combo, even when the whole line also happens to name one
    if ($sku !== '' && !$props && $inTitle < 2) {
        $col = sku_col($full, $why); if ($col >= 0) { $put($sku, $col, $qty); return; }
        $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => $why ?: 'size not known', 'kind' => 'size']; return;
    }
    // 2. a combo: the perfumes are in the line's details, or written in the title / variant separated by + , / or a new line (and, only when that finds nothing, by & / "and":
    //    many perfume names have "&" inside, so a whole text that names a SKU is never cut)
    $cands = [];
    foreach ($props as $p) { $v = trim((string)(is_scalar($p['value'] ?? '') ? ($p['value'] ?? '') : '')); if ($v === '') continue; foreach (sku_pieces($v) as $x) $cands[] = [$x, (string)($p['name'] ?? '')]; }
    if (!$cands) foreach (sku_pieces($full) as $x) $cands[] = [$x, ''];
    $hit = 0; $miss = [];
    foreach ($cands as [$c, $nm]) {
        $s2 = sku_match($c); if ($s2 === '') { if (sku_norm($c) !== '' && !preg_match('/^\d+$/', sku_norm($c))) $miss[] = $c; continue; }
        $w2 = ''; $col = sku_col($c . ' ' . $nm, $w2); if ($col < 0) $col = sku_col($full, $w2);
        if ($col < 0) { $bad[] = ['order' => $order, 'text' => $c . ' (inside: ' . $full . ')', 'qty' => $qty, 'why' => $w2 ?: 'size not known', 'kind' => 'size']; $hit++; continue; }
        $n = 1; if (preg_match('/^\s*(\d{1,2})\s*[x×]\s+/iu', $c, $m)) $n = max(1, (int)$m[1]);
        $put($s2, $col, $qty * $n); $hit++;
    }
    if ($hit > 0) { foreach ($miss as $c) $bad[] = ['order' => $order, 'text' => $c . ' (inside: ' . $full . ')', 'qty' => $qty, 'why' => 'this part of a combo matches no SKU', 'kind' => 'name']; return; }
    if ($sku !== '') { $col = sku_col($full, $why); if ($col >= 0) { $put($sku, $col, $qty); return; } $bad[] = ['order' => $order, 'text' => $full, 'qty' => $qty, 'why' => $why ?: 'size not known', 'kind' => 'size']; return; }
    $det = []; foreach ($props as $p) $det[] = $p['name'] . ': ' . (is_scalar($p['value'] ?? '') ? $p['value'] : json_encode($p['value']));
    $bad[] = ['order' => $order, 'text' => $full . ($det ? ' — details: ' . mb_substr(implode(' · ', $det), 0, 300) : ''), 'qty' => $qty, 'why' => 'this product matches no SKU' . ($shopSku !== '' ? ' (shop SKU "' . $shopSku . '")' : ''), 'kind' => 'name'];
}

/** Every order of one business day with its product lines, straight from Shopify (all pages). Returns [orders, errors]. */
function sku_shopify_day(PDO $pdo, string $day): array
{
    $out = []; $errs = []; $win = biz_window($pdo, $day, $day);
    foreach (shopify_stores() as $key => $store) {
        if (strpos((string)($store['client_id'] ?? ''), 'PASTE_') !== false) continue;
        $tok = shopify_get_token($key, $store); if (is_array($tok)) { $errs[] = ($store['label'] ?? $key) . ': ' . ($tok['error'] ?? 'no access'); continue; }
        $url = "https://{$store['domain']}/admin/api/2024-10/orders.json?" . http_build_query(['status' => 'any', 'limit' => 250, 'created_at_min' => str_replace(' ', 'T', $win[0]) . '+05:30', 'created_at_max' => str_replace(' ', 'T', $win[1]) . '+05:30', 'fields' => 'id,name,created_at,cancelled_at,line_items']);
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

/** The whole sheet of one business day. */
function sku_sheet(PDO $pdo, string $day): array
{
    $grid = []; $bad = []; $nOrd = 0; $nCancel = 0; $nLines = 0; $nMan = 0;
    [$orders, $errs] = sku_shopify_day($pdo, $day);
    $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
    foreach ($orders as $o) {
        if (!empty($o['cancelled_at']) || isset($cancel[$o['_store'] . '|' . (string)$o['id']])) { $nCancel++; continue; }
        $nOrd++;
        foreach ((array)($o['line_items'] ?? []) as $li) {
            $q = (int)($li['quantity'] ?? 0); if (isset($li['current_quantity'])) $q = min($q, max(0, (int)$li['current_quantity'])); if ($q <= 0) continue;      // a line removed from the order after it was placed
            $nLines++; sku_take($grid, $bad, (string)($o['name'] ?? ''), (string)($li['title'] ?? ''), (string)($li['variant_title'] ?? ''), (string)($li['sku'] ?? ''), $q, (array)($li['properties'] ?? []));
        }
    }
    // the manual orders (WM-xxxxx) confirmed on that business day
    try {
        $win = biz_window($pdo, $day, $day);
        $q = $pdo->prepare("SELECT order_no, lines_json FROM wmo_order WHERE status = 'confirmed' AND COALESCE(confirmed_at, created_at) BETWEEN ? AND ?"); $q->execute([$win[0], $win[1]]);
        foreach ($q->fetchAll() as $r) {
            $nMan++;
            foreach ((array)(json_decode((string)$r['lines_json'], true) ?: []) as $l) { $nLines++; sku_take($grid, $bad, (string)$r['order_no'], (string)($l['title'] ?? ($l['short'] ?? '')), (string)($l['label'] ?? ''), (string)($l['sku'] ?? ''), max(1, (int)($l['qty'] ?? 1)), []); }
        }
    } catch (Throwable $e) { }
    $ix = sku_index(); $rows = [];
    foreach ($grid as $sku => $q) $rows[] = ['sku' => (string)$sku, 'name' => (string)($ix['name'][$sku] ?? 'Not Found'), 'q' => $q, 'total' => array_sum($q)];
    usort($rows, function ($a, $b) { $x = ctype_digit($a['sku']) ? (int)$a['sku'] : PHP_INT_MAX; $y = ctype_digit($b['sku']) ? (int)$b['sku'] : PHP_INT_MAX; return $x <=> $y ?: strcmp($a['sku'], $b['sku']); });
    $tot = array_fill(0, 10, 0); foreach ($rows as $r) foreach ($r['q'] as $i => $v) $tot[$i] += $v;
    // the list on top of the page: the same product from many orders is ONE line with its pieces and its orders
    $grp = [];
    foreach ($bad as $b) { $k = $b['kind'] . '|' . mb_strtolower($b['text']) . '|' . $b['why']; if (!isset($grp[$k])) $grp[$k] = ['text' => $b['text'], 'why' => $b['why'], 'kind' => $b['kind'], 'qty' => 0, 'orders' => []]; $grp[$k]['qty'] += $b['qty']; if (count($grp[$k]['orders']) < 12 && !in_array($b['order'], $grp[$k]['orders'], true)) $grp[$k]['orders'][] = $b['order']; }
    $un = array_values($grp); usort($un, fn($a, $b) => ($a['kind'] === 'left') <=> ($b['kind'] === 'left') ?: $b['qty'] <=> $a['qty']);
    return ['day' => $day, 'cols' => sku_cols(), 'rows' => $rows, 'totals' => $tot, 'pieces' => array_sum($tot), 'orders' => $nOrd, 'manual' => $nMan, 'cancelled' => $nCancel, 'lines' => $nLines,
        'unmatched' => array_values(array_filter($un, fn($x) => $x['kind'] !== 'left')), 'left_out' => array_values(array_filter($un, fn($x) => $x['kind'] === 'left')),
        'unmatched_pieces' => array_sum(array_map(fn($x) => $x['kind'] !== 'left' ? $x['qty'] : 0, $un)), 'errors' => $errs, 'sku_count' => count(sku_list())];
}

/** Who may open the SKU sheet: Admin, HR, a role ticked 'Orders', and the SAME people as the Dispatch "Manual orders" page — everyone whose department is Dispatch (or Admin),
 *  whose designation says Dispatch or Sales Head, the Boss-engine managers, and the holders of a Dispatch seat. So a Dispatch person needs no extra tick. */
function sku_can(PDO $pdo, array $auth): bool
{
    if (in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'orders')) return true;
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
        $day = (string)($_GET['day'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = biz_today($pdo);
        if (!sku_list()) json_response(['error' => 'The SKU list is empty. Upload the SKU file (SKU · original name · shop name) on this page.'], 422);
        @set_time_limit(180); json_response(sku_sheet($pdo, $day) + ['today' => biz_today($pdo)]);
    }
    if ($action === 'sku.list') json_response(['rows' => sku_list(), 'custom' => sku_list_file() !== '']);
    if ($action === 'sku.list_save') {
        if (!in_array($role, ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin / HR can replace the SKU list.'], 403);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
        $rows = []; $seen = [];
        foreach ((array)(json_input()['rows'] ?? []) as $r) { if (!is_array($r)) continue; $s = trim((string)($r[0] ?? '')); if ($s === '' || isset($seen[mb_strtolower($s)]) || preg_match('/^sku$/i', $s)) continue; $seen[mb_strtolower($s)] = 1; $rows[] = [mb_substr($s, 0, 20), mb_substr(trim((string)($r[1] ?? '')), 0, 160), mb_substr(trim((string)($r[2] ?? '')), 0, 160)]; }
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
