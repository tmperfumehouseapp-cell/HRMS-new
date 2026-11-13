<?php
/**
 * wmo.php — 🧾 MANUAL ORDER from a WhatsApp chat (the customer paid by QR / UPI, or pays on delivery; no website checkout).   (actions "wmo.*", loaded by wa_more.php)
 *
 * The flow
 *   1. In the chat the salesperson presses "🧾 Manual order": chooses Full prepaid / Part prepaid + COD / Full COD, uploads the payment screenshot (Claude reads the amount + UTR),
 *      chooses the ML and a combo (6 × 20 ml …), picks the perfumes (and a chain) from the Shopify catalogue the cart already syncs (wch_prod / wch_var), and sends the customer a link.
 *   2. The customer opens the link (no login: wmo_form.php?t=TOKEN), fills name, mobile (checked against the chat's number), second mobile, address, landmark, pincode (city + state fill
 *      themselves) and presses Done.
 *   3. Done = the order is FINAL: a row in shop_orders (so Orders, Sales, Commission, Finance and Dispatch see it exactly like a Shopify order), the allocation to the chat's salesperson
 *      (Query / Campaign from the chat's source), the secondary mobile, the "address received" tick, the parcel in Dispatch, one confirmation message, "order completed" on the chat.
 *
 * Money rules (same as the chain cart): every chain is billed at its FULL price, all of the discount lands on the perfumes: perfumes get = total - chains.
 *   Full prepaid: total = what the screenshot shows.  Part prepaid: total = agreed total, paid = screenshot, COD balance = total - paid.  Full COD: nothing paid, the whole total on delivery.
 *   The order carries total_outstanding the way Shopify does (COD balance), and the UPI reference (UTR) as pay_ref, so a bank statement row with that UTR matches the order in Finance.
 *
 * Nothing here touches wch.php. Own tables: wmo_order. Setting wmo_on (default 1) switches the whole thing off.
 *   wmo.shot_read POST {image(base64), mime}            → what Claude reads on a payment screenshot {amount, utr, date, status, payer, dup}
 *   wmo.create    POST {phone, pay, total, paid, utr, ml, lines[{vid,qty}], chains[{vid,qty}], shot?, note?}  → {order}
 *   wmo.send      POST {id}                             → sends the link into the chat
 *   wmo.list      GET  {phone}                          → this chat's manual orders
 *   wmo.cancel    POST {id}
 *   wmo.form      GET/POST {t}   PUBLIC: the customer's address page (HTML)        wmo.pin  GET {pin}  PUBLIC: city + state of a pincode
 * PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wmo_tables')) {

function wmo_on(PDO $pdo): bool { return (string)setting_get($pdo, 'wmo_on', '1') !== '0'; }
function wmo_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wmo_order (id INT AUTO_INCREMENT PRIMARY KEY, order_no VARCHAR(16) NOT NULL DEFAULT '', token CHAR(32) NOT NULL, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0,
            assign_emp INT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'draft', pay VARCHAR(8) NOT NULL DEFAULT 'prepaid', total_p BIGINT NOT NULL DEFAULT 0, paid_p BIGINT NOT NULL DEFAULT 0, cod_p BIGINT NOT NULL DEFAULT 0,
            utr VARCHAR(40) NOT NULL DEFAULT '', ml INT NOT NULL DEFAULT 0, lines_json MEDIUMTEXT NULL, shot_mime VARCHAR(20) NOT NULL DEFAULT '', shot_b64 MEDIUMTEXT NULL, shot_json TEXT NULL, src VARCHAR(12) NOT NULL DEFAULT '',
            cust_json TEXT NULL, phone_match TINYINT NOT NULL DEFAULT 1, shop_id BIGINT NULL, note VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, sent_at DATETIME NULL, opened_at DATETIME NULL, confirmed_at DATETIME NULL,
            cancelled_at DATETIME NULL, UNIQUE KEY uq_wmo_tok (token), KEY k_wmo_phone (phone, status), KEY k_wmo_utr (utr)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['sr_order_id' => "VARCHAR(24) NOT NULL DEFAULT ''", 'sr_note' => "VARCHAR(255) NOT NULL DEFAULT ''", 'otype' => "VARCHAR(10) NOT NULL DEFAULT ''", 'addr_json' => 'TEXT NULL', 'gateway' => "VARCHAR(24) NOT NULL DEFAULT ''"] as $c => $def) { try { if (!$pdo->query("SHOW COLUMNS FROM wmo_order LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE wmo_order ADD COLUMN $c $def"); } catch (PDOException $e) { } }      // the Shiprocket order id and the last note
        return $ok = true;
    } catch (PDOException $e) { error_log('wmo_tables: ' . $e->getMessage()); return $ok = false; }
}

/** ⚖ grams per bottle size (setting wmo_weight, edited on the Manual order sheet → Weights); a size not typed yet is worked out from its ml. */
function wmo_weights(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wmo_weight', ''), true); if (!is_array($j)) $j = [];
    $sz = []; foreach ((array)($j['sizes'] ?? []) as $k => $v) if (is_numeric($k) && is_numeric($v) && (float)$v >= 0) $sz[(string)(0 + $k)] = (int)round((float)$v);
    $f = function ($v, $d) { return (is_numeric($v) && (float)$v >= 0) ? (int)round((float)$v) : $d; };
    return ['sizes' => $sz, 'chain' => $f($j['chain'] ?? null, 40), 'other' => $f($j['other'] ?? null, 100), 'pack' => $f($j['pack'] ?? null, 60)];
}
function wmo_line_weight(array $W, array $l): int
{
    $q = max(1, (int)($l['qty'] ?? 1)); if (!empty($l['chain'])) return $q * (int)$W['chain'];
    $ml = (int)($l['ml'] ?? 0); if ($ml <= 0 && preg_match('/(\d+)\s*ml/i', (string)($l['label'] ?? ''), $m)) $ml = (int)$m[1];
    if ($ml <= 0) return $q * (int)$W['other'];
    return $q * (isset($W['sizes'][(string)$ml]) ? (int)$W['sizes'][(string)$ml] : (int)round($ml * 1.6 + 55));      // glass + liquid + cap when the size has no typed weight
}
// ------------------------------------------------------------------------------------------------------------------ small helpers
function wmo_p($rupees): int { return (int)round(((float)$rupees) * 100); }
function wmo_rs(int $p): string { return '₹' . ($p % 100 === 0 ? number_format($p / 100, 0, '.', ',') : number_format($p / 100, 2, '.', ',')); }
function wmo_phone10($s): string { return substr(preg_replace('/\D+/', '', (string)$s), -10); }
function wmo_clip($s, int $n): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, $n); }
/** The synthetic shopify_id of a manual order: far above every real Shopify id (those are below 1e14), still exact in JavaScript. */
function wmo_sid(int $id): int { return 7000000000000000 + $id; }
function wmo_store_key(): string { $s = function_exists('shopify_stores') ? array_keys(shopify_stores()) : []; return in_array('tmperfumehouse', $s, true) ? 'tmperfumehouse' : ($s[0] ?? 'tmperfumehouse'); }
function wmo_link(string $token): string
{
    $base = defined('APP_URL') && APP_URL ? rtrim((string)APP_URL, '/') : ((!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/\\') : '/empmanagment';
    return $base . ($dir === '' || $dir === '.' ? '' : $dir) . '/wmo_form.php?t=' . $token;
}
/** Split $totalP over $weights so that the parts add up EXACTLY (whole rupees when possible, else paise); every part at least 1 paise. */
function wmo_split(int $totalP, array $weights): array
{
    $n = count($weights); if ($n === 0) return []; if ($n === 1) return [$totalP];
    $sum = array_sum($weights); if ($sum <= 0) { $weights = array_fill(0, $n, 1); $sum = $n; }
    $out = [];
    foreach ([100, 1] as $unit) {
        $out = []; $acc = 0; for ($i = 0; $i < $n - 1; $i++) { $a = (int)(round($totalP * $weights[$i] / $sum / $unit) * $unit); $out[] = $a; $acc += $a; }
        $out[] = $totalP - $acc; $ok = true; foreach ($out as $a) if ($a <= 0) $ok = false; if ($ok) return $out;
    }
    return $out;
}
/** Query / Campaign for the allocation: the same rule as every automatic allocation (order_chat_type: campaign number, Campaign / Community chat,
 *  or our campaign sent in the 7 days before). Falls back to the chat's source saved on the order. */
function wmo_alloc_type(PDO $pdo, array $r): string
{
    if (in_array((string)($r['otype'] ?? ''), ['query', 'campaign'], true)) return (string)$r['otype'];   // the salesperson said where this order came from (the Manual order page)
    if (function_exists('order_chat_type')) {
        try {
            try { $q = $pdo->prepare("SELECT c.phone, c.source, COALESCE(n.camp_tag, 0) AS camp_tag FROM wa_contacts c LEFT JOIN wa_numbers n ON n.id = c.last_number_id WHERE c.phone = ?"); $q->execute([(string)$r['phone']]); }
            catch (Throwable $e) { $q = $pdo->prepare("SELECT phone, source, 0 AS camp_tag FROM wa_contacts WHERE phone = ?"); $q->execute([(string)$r['phone']]); }
            $c = $q->fetch(PDO::FETCH_ASSOC) ?: ['phone' => (string)$r['phone'], 'source' => (string)$r['src']];
            return order_chat_type($pdo, $c, time());
        } catch (Throwable $e) { }
    }
    return in_array((string)$r['src'], ['campaign', 'community'], true) ? 'campaign' : 'query';
}
function wmo_emp(PDO $pdo, int $id): array
{
    if ($id <= 0) return ['name' => '', 'tag' => ''];
    try { $q = $pdo->prepare("SELECT full_name, sales_tag FROM employees WHERE employee_id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ? ['name' => (string)$r['full_name'], 'tag' => trim((string)$r['sales_tag'])] : ['name' => '', 'tag' => '']; }
    catch (Throwable $e) { return ['name' => '', 'tag' => '']; }
}
/** 5 attempts per minute per visitor and key: the customer page and the pincode lookup are public. */
function wmo_throttle(string $key, int $max): bool
{
    $f = sys_get_temp_dir() . '/hrms_wmo_' . md5($key . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . date('Hi')); $n = is_file($f) ? (int)@file_get_contents($f) : 0; if ($n >= $max) return false; @file_put_contents($f, (string)($n + 1)); return true;
}

// ------------------------------------------------------------------------------------------------------------------ the lines and the money
/** Looks the chosen variants up in the Shopify catalogue copy (wch_var / wch_prod) and splits the money. Returns [lines, perfumeMl, error]. */
function wmo_lines(PDO $pdo, int $totalP, array $perf, array $chains, array $singles = []): array
{
    $want = []; $sPrice = [];
    foreach ([['perfume', $perf, 20], ['chain', $chains, 5], ['single', $singles, 50]] as [$kind, $arr, $max]) foreach ($arr as $x) {
        if (!is_array($x)) continue; $vid = (int)($x['vid'] ?? 0); $q = (int)($x['qty'] ?? 0); if ($vid <= 0 || $q < 1) continue; $want[$vid] = ['kind' => $kind, 'qty' => min($max, $q)] + ($want[$vid] ?? []);
        if ($kind === 'single') $sPrice[$vid] = wmo_p($x['price'] ?? 0);
    }
    if (!$want) return [[], 0, 'Pick at least one product.'];
    try {
        $in = implode(',', array_fill(0, count($want), '?'));
        $st = $pdo->prepare("SELECT v.vid, v.pid, v.sku, v.title vtitle, v.ml, v.price_p, v.avail, p.title, p.short, p.kind FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE v.vid IN ($in)"); $st->execute(array_keys($want)); $rows = $st->fetchAll();
    } catch (Throwable $e) { return [[], 0, 'The product catalogue is not ready. Refresh it once (Sales → WhatsApp → Chain cart) and try again.']; }
    $by = []; foreach ($rows as $r) { if ($r['ml'] === null && function_exists('wch_title_ml10') && ($m10 = wch_title_ml10((string)$r['title'])) !== null) $r['ml'] = $m10; $by[(int)$r['vid']] = $r; }
    $L = []; $chainP = 0; $ml = 0; $singleP = 0;
    foreach ($want as $vid => $w) {
        $r = $by[$vid] ?? null; if (!$r) return [[], 0, 'A product is not in the catalogue any more. Search it again.'];
        if ((int)$r['avail'] !== 1) return [[], 0, trim((string)($r['short'] ?: $r['title'])) . ' is out of stock.'];
        if ($w['kind'] === 'single') {   // 🧴 shower gel, beard balm, attar … : one product, its OWN price (the shop price unless the salesperson changed it), never shared with the perfumes
            if ((string)$r['kind'] !== 'other') return [[], 0, trim((string)($r['short'] ?: $r['title'])) . ' is a perfume or chain: add it in the perfume / chain box.'];
            $up = $sPrice[$vid] ?? 0; if ($up < 100 || $up > 50000000) return [[], 0, 'Type the price of ' . trim((string)($r['short'] ?: $r['title'])) . ' (₹1 to ₹500000).'];
            $L[] = ['vid' => (int)$vid, 'sku' => (string)$r['sku'], 'pid' => (int)$r['pid'], 'title' => (string)$r['title'], 'short' => (string)($r['short'] ?: $r['title']), 'label' => trim((string)$r['vtitle']) === 'Default Title' ? '' : trim((string)$r['vtitle']), 'ml' => null, 'qty' => (int)$w['qty'], 'mrp_p' => (int)$r['price_p'], 'chain' => false, 'single' => true, 'unit_p' => $up, 'total_p' => $up * (int)$w['qty']]; $singleP += $up * (int)$w['qty']; continue;
        }
        $isChain = (string)$r['kind'] === 'chain'; if ($isChain !== ($w['kind'] === 'chain')) return [[], 0, 'A chain was picked as a perfume (or the other way round). Search it again.'];
        $mrp = (int)$r['price_p']; $label = $r['ml'] !== null ? (int)$r['ml'] . ' ml' : trim((string)$r['vtitle']);
        $L[] = ['vid' => (int)$vid, 'sku' => (string)$r['sku'], 'pid' => (int)$r['pid'], 'title' => (string)$r['title'], 'short' => (string)($r['short'] ?: $r['title']), 'label' => $label, 'ml' => $r['ml'] === null ? null : (int)$r['ml'], 'qty' => (int)$w['qty'], 'mrp_p' => $mrp, 'chain' => $isChain, 'total_p' => 0];
        if ($isChain) $chainP += $mrp * (int)$w['qty']; elseif ($r['ml'] !== null) $ml += (int)$r['ml'] * (int)$w['qty'];
    }
    $pi = []; foreach ($L as $i => $l) if (!$l['chain'] && empty($l['single'])) $pi[] = $i;
    $perfP = $totalP - $chainP - $singleP; $pieces = 0; foreach ($pi as $i) $pieces += $L[$i]['qty'];
    if (!$pi) { if (!$singleP) return [[], 0, 'Pick at least one perfume or product.']; if ($perfP !== 0) return [[], 0, 'With no perfume, the order total must equal the products' . ($chainP ? ' + chain' : '') . ' (' . wmo_rs($chainP + $singleP) . ').']; }
    elseif ($perfP < $pieces * 1000) return [[], 0, 'After the chain / other products (' . wmo_rs($chainP + $singleP) . ' at their own prices) too little is left for the perfumes. Raise the total or remove something.'];
    foreach ($L as $i => $l) if ($l['chain']) $L[$i]['total_p'] = $l['mrp_p'] * $l['qty'];
    if ($pi) { $parts = wmo_split($perfP, array_map(fn($i) => max(1, $L[$i]['mrp_p'] * $L[$i]['qty']), $pi)); foreach ($pi as $k => $i) $L[$i]['total_p'] = $parts[$k]; }
    return [$L, $ml, ''];
}
/** Shopify-shaped line items of the order lines (for the ML / colour / chain helpers of the Orders pages). */
function wmo_line_items(array $L): array
{
    $o = []; foreach ($L as $l) $o[] = ['title' => $l['title'], 'name' => $l['title'] . ($l['label'] !== '' ? ' - ' . $l['label'] : ''), 'variant_title' => $l['label'], 'quantity' => (int)$l['qty'], 'price' => round($l['total_p'] / 100 / max(1, (int)$l['qty']), 2), 'total_discount' => 0, 'sku' => '']; return $o;
}

// ------------------------------------------------------------------------------------------------------------------ the payment screenshot
function wmo_read_shot(PDO $pdo, string $b64, string $mime): array
{
    $sys = "You read a screenshot of a UPI / QR payment (PhonePe, Google Pay, Paytm, a bank app …) that a customer sent to TM Perfume House. The business also receives money under its parent company name \"Fourpirates Ventures LLP\" (also written Fourpirates / Four Pirates), including Razorpay receipts and payment-link receipts that may carry a PAID stamp: these are VALID, never mention them as a wrong receiver. Return JSON: ok = true only if it really is a payment confirmation; amount = the amount paid in rupees as a number (0 if unclear); "
        . "utr = the UPI reference / UTR / transaction id exactly as printed (digits and letters only, empty if none); date = the payment date and time as printed; status = success | failed | pending | unknown; payer = the payer's name if shown; app = the app; note = one short sentence of anything odd "
        . "(edited look, a different date, a receiver that is neither TM Perfume House nor Fourpirates Ventures LLP, amount unclear). Never invent a number.";
    $sch = ['type' => 'OBJECT', 'properties' => ['ok' => ['type' => 'BOOLEAN'], 'amount' => ['type' => 'NUMBER'], 'utr' => ['type' => 'STRING'], 'date' => ['type' => 'STRING'], 'status' => ['type' => 'STRING'], 'payer' => ['type' => 'STRING'], 'app' => ['type' => 'STRING'], 'note' => ['type' => 'STRING']], 'required' => ['ok', 'amount', 'utr', 'date', 'status', 'payer', 'app', 'note']];
    [$ok, $v, $why] = convo_ai($sys, [['text' => 'Read this payment screenshot.'], ['inlineData' => ['mimeType' => $mime, 'data' => $b64]]], $sch, false, 700, 'claude');
    if (!$ok) return ['error' => 'Claude could not read it: ' . mb_substr((string)$why, 0, 160) . ' You can type the amount yourself.'];
    $utr = preg_replace('/[^A-Za-z0-9]/', '', (string)($v['utr'] ?? ''));
    if (empty($v['ok']) && (float)($v['amount'] ?? 0) > 0 && (string)($v['status'] ?? '') === 'success' && preg_match('/four\s*pirates?/i', (string)($v['note'] ?? '') . ' ' . (string)($v['app'] ?? ''))) { $v['ok'] = true; $v['note'] = ''; }   // paid to our parent company (Fourpirates Ventures LLP): valid
    $out = ['ok' => !empty($v['ok']), 'amount' => round(max(0, (float)($v['amount'] ?? 0)), 2), 'utr' => mb_substr($utr, 0, 40), 'date' => wmo_clip($v['date'] ?? '', 40), 'status' => in_array(($v['status'] ?? ''), ['success', 'failed', 'pending'], true) ? $v['status'] : 'unknown', 'payer' => wmo_clip($v['payer'] ?? '', 60), 'app' => wmo_clip($v['app'] ?? '', 30), 'note' => wmo_clip($v['note'] ?? '', 200), 'dup' => ''];
    if ($out['utr'] !== '' && wmo_tables($pdo)) { $q = $pdo->prepare("SELECT order_no FROM wmo_order WHERE utr = ? AND status <> 'cancelled' LIMIT 1"); $q->execute([$out['utr']]); $d = $q->fetchColumn(); if ($d) $out['dup'] = (string)$d; }
    return $out;
}

// ------------------------------------------------------------------------------------------------------------------ Dispatch ▸ Manual orders (every manual order, export, invoice / Shiprocket status)
/** Who may open the Dispatch "Manual orders" list: Admin / HR, the Dispatch and Admin departments, a Sales Head, a Dispatch seat holder, the bosses. */
/** 💰 Our purchase cost per piece (owner, 13 Nov: the same cost for every perfume of one size): {sizes: {"50": 120, …}, chain: ₹, other: ₹}. Used by the FP export (Buying Price). */
function wmo_costs(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'fp_cost', ''), true); if (!is_array($j)) $j = [];
    $sz = []; foreach ((array)($j['sizes'] ?? []) as $k => $v) if (is_numeric($k) && is_numeric($v) && (float)$v >= 0) $sz[(string)(0 + $k)] = round((float)$v, 2);
    $f = function ($v) { return (is_numeric($v) && (float)$v >= 0) ? round((float)$v, 2) : null; };
    return ['sizes' => (object)$sz, 'chain' => $f($j['chain'] ?? null), 'other' => $f($j['other'] ?? null)];
}
function wmo_dsp_ok(PDO $pdo, array $auth): bool
{
    if (in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) return true;
    $e = (int)($auth['employee_id'] ?? 0); if ($e <= 0) return false;
    try {
        $q = $pdo->prepare("SELECT d.department_name, e.designation FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?"); $q->execute([$e]); $r = $q->fetch();
        if ($r && (preg_match('/dispatch|admin/i', (string)$r['department_name']) || preg_match('/sales\s*head|dispatch/i', (string)$r['designation']))) return true;
    } catch (Throwable $ex) { }
    try { if (function_exists('bd_who') && !empty(bd_who($pdo, $auth)['manager'])) return true; } catch (Throwable $ex) { }
    try { if (function_exists('dp_can_seat') && (dp_can_seat($pdo, $auth, 'dispatch.tracking') || dp_can_seat($pdo, $auth, 'dispatch.lead'))) return true; } catch (Throwable $ex) { }
    return false;
}
/** The manual orders (WM-xxxxx) of a business-day range with everything the Dispatch people and the export need. */
function wmo_dsp_rows(PDO $pdo, string $from, string $to, string $status, string $pay, string $q, int $limit, int $offset, ?int &$total = null, ?array &$sum = null): array
{
    [$ws, $we] = biz_window($pdo, $from, $to); $w = ["created_at >= ?", "created_at <= ?"]; $a = [$ws, $we];
    $base = $w; $baseA = $a;
    if ($status === 'confirmed' || $status === 'cancelled') { $w[] = "status = ?"; $a[] = $status; } elseif ($status === 'waiting') $w[] = "status IN ('draft', 'sent')";
    if (in_array($pay, ['prepaid', 'partial', 'cod'], true)) { $w[] = "pay = ?"; $a[] = $pay; }
    $q = trim($q); if ($q !== '') { $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], ltrim($q, '#')) . '%'; $w[] = "(order_no LIKE ? OR phone LIKE ? OR utr LIKE ? OR cust_json LIKE ?)"; array_push($a, $l, $l, $l, $l); }
    $c = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total_p), 0), COALESCE(SUM(paid_p), 0), COALESCE(SUM(cod_p), 0) FROM wmo_order WHERE " . implode(' AND ', $w)); $c->execute($a); $cr = $c->fetch(PDO::FETCH_NUM); $total = (int)$cr[0];
    $sum = ['total' => $cr[1] / 100, 'prepaid' => $cr[2] / 100, 'cod' => $cr[3] / 100, 'count' => (int)$cr[0]];
    $cs = $pdo->prepare("SELECT status, COUNT(*) n FROM wmo_order WHERE " . implode(' AND ', $base) . " GROUP BY status"); $cs->execute($baseA); $by = ['confirmed' => 0, 'waiting' => 0, 'cancelled' => 0]; foreach ($cs->fetchAll() as $r0) { $k = $r0['status'] === 'confirmed' ? 'confirmed' : ($r0['status'] === 'cancelled' ? 'cancelled' : 'waiting'); $by[$k] += (int)$r0['n']; } $sum['by'] = $by;
    $st = $pdo->prepare("SELECT * FROM wmo_order WHERE " . implode(' AND ', $w) . " ORDER BY id DESC" . ($limit > 0 ? " LIMIT " . (int)$limit . " OFFSET " . (int)$offset : "")); $st->execute($a); $rows = $st->fetchAll(); if (!$rows) return [];
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $nos = array_map(fn($r) => (string)$r['order_no'], $rows); $in = implode(',', array_fill(0, count($nos), '?'));
    $inv = []; try { $iq = $pdo->prepare("SELECT id, inv_no, token, sent_at, order_name FROM wch_inv WHERE order_name IN ($in) ORDER BY id"); $iq->execute($nos); foreach ($iq->fetchAll() as $r1) $inv[(string)$r1['order_name']] = $r1; } catch (Throwable $e) { }
    $par = []; try { $nos2 = array_merge($nos, array_map(fn($x) => '#' . $x, $nos)); $in2 = implode(',', array_fill(0, count($nos2), '?')); $pq = $pdo->prepare("SELECT order_name, tracking_no, courier, status FROM dp_dsp_parcels WHERE order_name IN ($in2)"); $pq->execute($nos2); foreach ($pq->fetchAll() as $r2) $par[ltrim((string)$r2['order_name'], '#')] = $r2; } catch (Throwable $e) { }
    $out = [];
    foreach ($rows as $r) {
        $L = json_decode((string)$r['lines_json'], true) ?: []; $cu = json_decode((string)($r['cust_json'] ?? ''), true) ?: []; $ad = json_decode((string)($r['addr_json'] ?? ''), true) ?: []; $no = (string)$r['order_no'];
        $conf = $r['status'] === 'confirmed'; $iv = $inv[$no] ?? null; $pa = $par[$no] ?? null;
        $out[] = ['id' => (int)$r['id'], 'order_no' => $no, 'created_at' => (string)$r['created_at'], 'confirmed_at' => (string)($r['confirmed_at'] ?? ''), 'status' => $conf ? 'confirmed' : ($r['status'] === 'cancelled' ? 'cancelled' : 'waiting'), 'raw_status' => (string)$r['status'],
            'pay' => (string)$r['pay'], 'total' => $r['total_p'] / 100, 'paid' => $r['paid_p'] / 100, 'cod' => $r['cod_p'] / 100, 'utr' => (string)$r['utr'], 'ml' => (int)$r['ml'], 'type' => (string)($r['otype'] ?? ''), 'src' => (string)($r['src'] ?? ''), 'note' => (string)($r['note'] ?? ''),
            'emp' => (string)($names[(int)$r['assign_emp']] ?? ''), 'phone' => (string)$r['phone'], 'has_shot' => (string)($r['shot_mime'] ?? '') !== '',
            'cust' => ['name' => (string)($cu['name'] ?? ($ad['name'] ?? '')), 'phone' => (string)($cu['phone'] ?? $r['phone']), 'phone2' => (string)($cu['phone2'] ?? ''), 'addr1' => (string)($cu['addr1'] ?? ($ad['addr1'] ?? '')), 'addr2' => (string)($cu['addr2'] ?? ''), 'landmark' => (string)($cu['landmark'] ?? ($ad['landmark'] ?? '')), 'pin' => (string)($cu['pin'] ?? ($ad['pin'] ?? '')), 'city' => (string)($cu['city'] ?? ($ad['city'] ?? '')), 'state' => (string)($cu['state'] ?? ($ad['state'] ?? '')), 'email' => (string)($cu['email'] ?? '')],
            'lines' => array_map(fn($l) => ['short' => (string)$l['short'], 'title' => (string)($l['title'] ?? $l['short']), 'label' => (string)($l['label'] ?? ''), 'sku' => (string)($l['sku'] ?? ''), 'qty' => (int)$l['qty'], 'total' => $l['total_p'] / 100, 'unit' => round($l['total_p'] / 100 / max(1, (int)$l['qty']), 2), 'chain' => !empty($l['chain']), 'single' => !empty($l['single'])], $L),
            'invoice' => $iv ? ['id' => (int)$iv['id'], 'no' => (string)$iv['inv_no'], 'sent_at' => (string)($iv['sent_at'] ?? ''), 'pdf' => function_exists('wch_pdf_url') ? wch_pdf_url((string)$iv['token']) : '', 'view' => function_exists('wch_view_url') ? wch_view_url((string)$iv['token']) : ''] : null,
            'sr' => ['id' => (string)($r['sr_order_id'] ?? ''), 'note' => (string)($r['sr_note'] ?? '')], 'parcel' => $pa ? ['awb' => (string)$pa['tracking_no'], 'courier' => (string)$pa['courier'], 'status' => (string)$pa['status']] : null,
            'link' => in_array($r['status'], ['draft', 'sent'], true) ? wmo_link((string)$r['token']) : ''];
    }
    // 🧮 weight, tax, payment gateway, commission (the day's figures are cached by the commission engine; at most 8 days are looked up per call so a long export stays light)
    $W = wmo_weights($pdo); $bset = null; try { if (function_exists('wch_b_get')) $bset = wch_b_get($pdo); } catch (Throwable $e) { $bset = null; }
    $store = wmo_store_key(); $paidBy = []; $nDays = 0;
    foreach ($out as $i => &$o) {
        $L2 = json_decode((string)$rows[$i]['lines_json'], true) ?: []; $wt = 0; $sumP = ['perfume' => 0, 'chain' => 0];
        foreach ($L2 as $l) { $wt += wmo_line_weight($W, $l); $sumP[!empty($l['chain']) ? 'chain' : 'perfume'] += (int)$l['total_p']; }
        $o['weight_g'] = $L2 ? $wt + (int)$W['pack'] : 0; $tx = null;
        if ($bset && function_exists('wch_tax_section')) { $tx = 0; foreach (['perfume' => 'rate_perfume', 'chain' => 'rate_chain'] as $k => $rk) if ($sumP[$k] > 0) { $t = wch_tax_section([$sumP[$k]], (float)$bset[$rk], true); $tx += (int)$t['tax_p']; } $tx = $tx / 100; }
        $o['tax'] = $tx;
        $sj = json_decode((string)($rows[$i]['shot_json'] ?? ''), true) ?: [];
        $o['gateway'] = (string)($rows[$i]['gateway'] ?? ''); $o['pay_app'] = (string)($sj['app'] ?? ''); $o['has_shot'] = (string)($rows[$i]['shot_mime'] ?? '') !== '';
        $o['commission'] = null; $o['com_why'] = '';
        if ($rows[$i]['status'] === 'confirmed' && function_exists('cm_paid_day')) {
            try {
                $d = function_exists('wq_day_of') ? wq_day_of($pdo, (int)strtotime((string)$rows[$i]['created_at'])) : substr((string)$rows[$i]['created_at'], 0, 10);
                if (!isset($paidBy[$d])) { if ($nDays < 8) { $paidBy[$d] = cm_paid_day($pdo, $d); $nDays++; } else $paidBy[$d] = null; }
                $k = $store . ':' . wmo_sid((int)$rows[$i]['id']); $pd = $paidBy[$d]['ord'][$k] ?? null; if ($pd) { $o['commission'] = round((float)$pd[0], 2); $o['com_why'] = (string)$pd[1]; }
            } catch (Throwable $e) { }
        }
    } unset($o);
    return $out;
}
// ------------------------------------------------------------------------------------------------------------------ view of one order
function wmo_out(PDO $pdo, array $r, bool $full = false): array
{
    $L = json_decode((string)$r['lines_json'], true) ?: []; $cust = json_decode((string)($r['cust_json'] ?? ''), true) ?: [];
    $o = ['id' => (int)$r['id'], 'order_no' => (string)$r['order_no'], 'status' => (string)$r['status'], 'pay' => (string)$r['pay'], 'total' => $r['total_p'] / 100, 'paid' => $r['paid_p'] / 100, 'cod' => $r['cod_p'] / 100, 'utr' => (string)$r['utr'], 'ml' => (int)$r['ml'],
        'lines' => array_map(fn($l) => ['short' => $l['short'], 'label' => $l['label'], 'qty' => (int)$l['qty'], 'chain' => !empty($l['chain']), 'single' => !empty($l['single']), 'total' => $l['total_p'] / 100], $L), 'created_at' => (string)$r['created_at'], 'sent_at' => (string)($r['sent_at'] ?? ''), 'opened_at' => (string)($r['opened_at'] ?? ''), 'confirmed_at' => (string)($r['confirmed_at'] ?? ''),
        'link' => $r['status'] === 'cancelled' ? '' : wmo_link((string)$r['token']), 'phone_match' => (int)$r['phone_match'], 'name' => (string)($cust['name'] ?? '')];
    if ($full && $r['status'] === 'confirmed') { $o['ship'] = wmo_ship_text($r['order_no'], $cust, $r); try { $q = $pdo->prepare("SELECT inv_no, sent_at FROM wch_inv WHERE order_name = ? ORDER BY id DESC LIMIT 1"); $q->execute([(string)$r['order_no']]); $iv = $q->fetch(); $o['inv'] = $iv ? ['no' => (string)$iv['inv_no'], 'sent' => !empty($iv['sent_at'])] : null; } catch (Throwable $e) { $o['inv'] = null; } $o['sr'] = ['id' => (string)($r['sr_order_id'] ?? ''), 'note' => (string)($r['sr_note'] ?? '')]; }
    return $o;
}
function wmo_ship_text(string $no, array $c, array $r): string
{
    return $no . "\n" . ($c['name'] ?? '') . "\nMobile: " . ($c['phone'] ?? '') . (!empty($c['phone2']) ? ' / ' . $c['phone2'] : '') . "\n" . ($c['addr1'] ?? '') . (!empty($c['addr2']) ? ', ' . $c['addr2'] : '') . (!empty($c['landmark']) ? "\nLandmark: " . $c['landmark'] : '') . "\n" . ($c['city'] ?? '') . ', ' . ($c['state'] ?? '') . ' - ' . ($c['pin'] ?? '')
        . "\n" . ((int)$r['cod_p'] > 0 ? 'COLLECT ON DELIVERY: ' . wmo_rs((int)$r['cod_p']) : 'PREPAID');
}

// ------------------------------------------------------------------------------------------------------------------ the customer finishes the order
function wmo_pin_lookup(string $pin): array
{
    if (!preg_match('/^[1-9]\d{5}$/', $pin)) return [];
    $c = sys_get_temp_dir() . '/hrms_pin_' . $pin; if (is_file($c) && filemtime($c) > time() - 30 * 86400) { $j = json_decode((string)@file_get_contents($c), true); if (is_array($j) && !empty($j['city'])) return $j; }
    $ch = curl_init('https://api.postalpincode.in/pincode/' . $pin); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]); $raw = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$raw, true); $po = $j[0]['PostOffice'][0] ?? null; if (!is_array($po)) return [];
    $out = ['city' => wmo_clip($po['District'] ?? ($po['Block'] ?? ''), 60), 'state' => wmo_clip($po['State'] ?? '', 60)]; if ($out['city'] === '') return [];
    @file_put_contents($c, json_encode($out)); return $out;
}
/** Checks what the customer typed. Returns [clean, error]. */
function wmo_check_form(array $in, string $chatPhone): array
{
    $name = wmo_clip($in['name'] ?? '', 80); $ph = wmo_phone10($in['phone'] ?? ''); $ph2 = wmo_phone10($in['phone2'] ?? ''); $a1 = wmo_clip($in['addr1'] ?? '', 140); $a2 = wmo_clip($in['addr2'] ?? '', 140);
    $lm = wmo_clip($in['landmark'] ?? '', 100); $pin = preg_replace('/\D+/', '', (string)($in['pin'] ?? '')); $city = wmo_clip($in['city'] ?? '', 60); $state = wmo_clip($in['state'] ?? '', 60); $email = wmo_clip($in['email'] ?? '', 120);
    if (mb_strlen($name) < 2) return [[], 'Please write your full name.'];
    if (!preg_match('/^[6-9]\d{9}$/', $ph)) return [[], 'Please write your 10-digit mobile number.'];
    if ($ph2 !== '' && !preg_match('/^[6-9]\d{9}$/', $ph2)) return [[], 'The second mobile number must have 10 digits (or leave it empty).'];
    if (mb_strlen($a1) < 5) return [[], 'Please write your house / flat and street.'];
    if (!preg_match('/^[1-9]\d{5}$/', $pin)) return [[], 'Please write a 6-digit pincode.'];
    if ($city === '' || $state === '') { $g = wmo_pin_lookup($pin); if ($g) { $city = $city ?: $g['city']; $state = $state ?: $g['state']; } }
    if ($city === '' || $state === '') return [[], 'Please write your city and state.'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [[], 'The e-mail looks wrong (or leave it empty).'];
    $bday = ''; $bd = (int)($in['bd'] ?? 0); $bm = (int)($in['bm'] ?? 0); if ($bd > 0 || $bm > 0) { if (!function_exists('cbd_md') && is_file(__DIR__ . '/cust_bday.php')) require_once __DIR__ . '/cust_bday.php'; $bday = function_exists('cbd_md') ? (string)cbd_md($bd, $bm) : ''; if ($bday === '') return [[], 'Please choose both the day and the month of your birthday (or leave both empty).']; }   // 🎂 optional: a free 50 ml gift on the birthday
    return [['bday' => $bday, 'name' => $name, 'phone' => $ph, 'phone2' => $ph2, 'addr1' => $a1, 'addr2' => $a2, 'landmark' => $lm, 'pin' => $pin, 'city' => $city, 'state' => $state, 'email' => $email, 'match' => $ph === $chatPhone ? 1 : 0], ''];
}
/** Makes the order final in the database: shop_orders row, allocation, extra phone, address tick. Returns the order number. (wmo_notify does the messages after the commit.) */
function wmo_finalize(PDO $pdo, array $r, array $cust): string
{
    $id = (int)$r['id']; $L = json_decode((string)$r['lines_json'], true) ?: []; $sid = wmo_sid($id); $store = wmo_store_key(); $key = (string)$r['phone']; $emp = wmo_emp($pdo, (int)$r['assign_emp']);
    $li = wmo_line_items($L); $mlt = order_ml_total($li); $now = date('Y-m-d H:i:s'); $paid = (int)$r['paid_p'] > 0;
    $fs = (int)$r['cod_p'] <= 0 ? 'paid' : ($paid ? 'partially_paid' : 'pending'); $tags = array_values(array_filter([$emp['tag'], 'wa-manual', $r['pay'] === 'cod' ? 'cod' : 'prepaid']));
    $row = ['id' => $sid, 'order_name' => (string)$r['order_no'], 'created_at' => date('c'), 'customer' => $cust['name'], 'phone' => $key, 'items' => count($li), 'colors' => order_product_colors($li), 'ml' => $mlt['ml'], 'ml10' => $mlt['ml10'], 'xparts' => $mlt['parts'] ?: (object)[]]
        + order_x_com(null, $mlt['parts'], (int)$mlt['ml'], $r['total_p'] / 100)
        + ['dti' => order_dti($li) ?: 0, 'total' => round($r['total_p'] / 100, 2), 'currency' => 'INR', 'financial_status' => $fs, 'outstanding' => round($r['cod_p'] / 100, 2), 'paid' => $paid && (int)$r['cod_p'] > 0 ? round($r['paid_p'] / 100, 2) : null,
           'fulfillment_status' => 'unfulfilled', 'tags' => implode(', ', $tags), 'pay_ref' => (string)$r['utr']];
    foreach (['ensure_shop_orders_table', 'ensure_order_allocations_table', 'ensure_order_address_table', 'ensure_order_phones_table'] as $__f) if (function_exists($__f)) { try { $__f($pdo); } catch (Throwable $e) { } }      // the tables the order lands in exist
    orders_upsert($pdo, $store, [$row]);
    try { $pdo->prepare("INSERT INTO order_allocations (source, order_id, house, order_type, employee_id, is_direct, allocated_by) VALUES (?, ?, 'tmph', ?, ?, 0, 'WhatsApp manual order') ON DUPLICATE KEY UPDATE order_type = VALUES(order_type), employee_id = VALUES(employee_id)")->execute([$store, (string)$sid, wmo_alloc_type($pdo, $r), (int)$r['assign_emp']]); } catch (Throwable $e) { error_log('wmo alloc: ' . $e->getMessage()); }
    try {
        $extra = array_values(array_unique(array_filter([$cust['phone2'], $cust['phone'] !== $key ? $cust['phone'] : ''])));
        if ($extra) $pdo->prepare("INSERT INTO order_extra_phones (source, order_id, order_name, phone, updated_by) VALUES (?, ?, ?, ?, 'Customer link') ON DUPLICATE KEY UPDATE phone = VALUES(phone)")->execute([$store, (string)$sid, (string)$r['order_no'], implode(', ', $extra)]);
        $pdo->prepare("INSERT INTO order_address (source, order_id, order_name, updated_by) VALUES (?, ?, ?, 'Customer link') ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by)")->execute([$store, (string)$sid, (string)$r['order_no']]);
    } catch (Throwable $e) { error_log('wmo extra: ' . $e->getMessage()); }
    if (!empty($cust['bday'])) { try { if (!function_exists('cbd_set') && is_file(__DIR__ . '/cust_bday.php')) require_once __DIR__ . '/cust_bday.php'; if (function_exists('cbd_set')) cbd_set($pdo, (string)$cust['phone'], (string)$cust['name'], (string)$cust['bday'], 'order link'); } catch (Throwable $e) { error_log('wmo bday: ' . $e->getMessage()); } }   // 🎂 saved for the birthday gift
    $pdo->prepare("UPDATE wmo_order SET status = 'confirmed', confirmed_at = ?, cust_json = ?, phone_match = ?, shop_id = ? WHERE id = ?")->execute([$now, json_encode($cust, JSON_UNESCAPED_UNICODE), (int)$cust['match'], $sid, $id]);
    return (string)$r['order_no'];
}
/** After the order is saved (outside the database lock): the Dispatch parcel now, one confirmation, "order completed" on the chat. */
function wmo_notify(PDO $pdo, array $r, array $cust): void
{
    $L = json_decode((string)$r['lines_json'], true) ?: []; $key = (string)$r['phone']; $now = date('Y-m-d H:i:s');
    if (function_exists('dp_dsp_sync')) { try { dp_dsp_sync($pdo, time(), true); } catch (Throwable $e) { } }       // the parcel appears in Dispatch now, not in three minutes
    $c = function_exists('wa_contact') ? wa_contact($pdo, $key) : null;
    if ($c) {
        // the customer gets the ready-made "Order received" message of WhatsApp -> Autobot -> Order updates (wa_done_mark sends it); shipped / out for delivery / delivered follow from Dispatch by themselves
        if (function_exists('wa_done_mark')) { try { wa_done_mark($pdo, $key, (int)$r['assign_emp'], 'manual', (string)$r['order_no']); } catch (Throwable $e) { error_log('wmo done: ' . $e->getMessage()); } }
        if (function_exists('wa_event')) { try { wa_event($pdo, $key, '🧾 Manual order ' . $r['order_no'] . ' confirmed by the customer — ' . wmo_rs((int)$r['total_p']) . ((int)$r['cod_p'] > 0 ? ' (COD ' . wmo_rs((int)$r['cod_p']) . ')' : ' (prepaid)') . ((int)$cust['match'] === 1 ? '' : ' · ⚠ the mobile number written is different from this chat')); } catch (Throwable $e) { } }
        $note = wmo_invoice($pdo, $r, $cust, 0); if (function_exists('wa_event')) { try { wa_event($pdo, $key, '🧾 ' . $note); } catch (Throwable $e) { } }
        $note = wmo_shiprocket($pdo, $r, $cust); if (function_exists('wa_event')) { try { wa_event($pdo, $key, '🚚 ' . $note); } catch (Throwable $e) { } }
    }
}

// ------------------------------------------------------------------------------------------------------------------ Shiprocket
/** Shiprocket must know the order, because it pulls Shopify orders by itself but not these. The order is created there with the order id WM-xxxxx, so Dispatch (which matches Shiprocket orders to parcels by that id)
 *  gets the courier, the AWB and the status by itself. Same login as the Dispatch page (config SHIPROCKET_EMAIL / PASSWORD, or the ones saved on the Parcels page). */
function wmo_sr_kv(PDO $pdo, string $k, ?string $set = null): string
{
    try {
        if ($set !== null) { $pdo->prepare("INSERT INTO dp_state (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$k, $set]); return $set; }
        $q = $pdo->prepare("SELECT svalue FROM dp_state WHERE skey = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? '' : (string)$v;
    } catch (Throwable $e) { return ''; }
}
function wmo_sr_http(string $method, string $url, array $headers, $body = null): array
{
    if (!function_exists('curl_init')) return [0, null, 'PHP curl is not switched on on this server.'];
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = $out === false ? curl_error($ch) : ''; curl_close($ch);
    $j = ($out !== false && $out !== '') ? json_decode($out, true) : null; return [$code, is_array($j) ? $j : null, $err];
}
/** [token, error] */
function wmo_sr_token(PDO $pdo, bool $force = false): array
{
    $e = function_exists('tmph_cfg') ? trim((string)tmph_cfg('SHIPROCKET_EMAIL', '')) : ''; $p = function_exists('tmph_cfg') ? (string)tmph_cfg('SHIPROCKET_PASSWORD', '') : '';
    if ($e === '' || $p === '') { $e = trim(wmo_sr_kv($pdo, 'sr_email')); $p = wmo_sr_kv($pdo, 'sr_pass'); }
    if ($e === '' || $p === '') return ['', 'Shiprocket email and password are not saved (Dispatch → Parcels and tracking → Shiprocket).'];
    $base = rtrim(function_exists('tmph_cfg') ? (string)tmph_cfg('SHIPROCKET_BASE', 'https://apiv2.shiprocket.in') : 'https://apiv2.shiprocket.in', '/');
    $tok = wmo_sr_kv($pdo, 'sr_token'); $exp = (int)wmo_sr_kv($pdo, 'sr_token_exp'); $sig = wmo_sr_kv($pdo, 'sr_token_for');
    if (!$force && $tok !== '' && $exp > time() + 300 && $sig === md5($e . '|' . $p)) return [$tok, ''];
    [$code, $j, $err] = wmo_sr_http('POST', $base . '/v1/external/auth/login', [], ['email' => $e, 'password' => $p]);
    if ($err !== '') return ['', 'Could not reach Shiprocket: ' . $err];
    if ($code === 200 && is_array($j) && !empty($j['token'])) { wmo_sr_kv($pdo, 'sr_token', (string)$j['token']); wmo_sr_kv($pdo, 'sr_token_exp', (string)(time() + 8 * 86400)); wmo_sr_kv($pdo, 'sr_token_for', md5($e . '|' . $p)); return [(string)$j['token'], '']; }
    return ['', 'Shiprocket login failed (HTTP ' . $code . ')' . (is_array($j) && !empty($j['message']) ? ': ' . mb_substr((string)$j['message'], 0, 100) : '') . '.'];
}
/** the pickup address name Shiprocket knows: the setting wmo_sr_pickup, else the primary one of the account (saved after the first lookup) */
function wmo_sr_pickup(PDO $pdo, string $tok, string $base): string
{
    $set = trim((string)setting_get($pdo, 'wmo_sr_pickup', '')); if ($set !== '') return $set;
    [$code, $j] = wmo_sr_http('GET', $base . '/v1/external/settings/company/pickup', ['Authorization: Bearer ' . $tok]); $list = (array)($j['data']['shipping_address'] ?? []); if (!$list) return '';
    $pick = $list[0]; foreach ($list as $x) if (!empty($x['is_primary_location'])) { $pick = $x; break; }
    $name = trim((string)($pick['pickup_location'] ?? '')); if ($name !== '') setting_set($pdo, 'wmo_sr_pickup', $name); return $name;
}
/** Creates the order in Shiprocket (once). Returns a short note; never throws. */
function wmo_shiprocket(PDO $pdo, array $r, array $cust): string
{
    try {
        if (trim((string)$r['sr_order_id']) !== '') return 'Shiprocket order ' . $r['sr_order_id'] . ' already exists.';
        [$tok, $er] = wmo_sr_token($pdo); if ($tok === '') return 'Shiprocket order not made: ' . $er;
        $base = rtrim(function_exists('tmph_cfg') ? (string)tmph_cfg('SHIPROCKET_BASE', 'https://apiv2.shiprocket.in') : 'https://apiv2.shiprocket.in', '/');
        $pick = wmo_sr_pickup($pdo, $tok, $base); if ($pick === '') return 'Shiprocket order not made: no pickup address found (add one in Shiprocket, or set wmo_sr_pickup).';
        $L = json_decode((string)$r['lines_json'], true) ?: []; $items = []; $pieces = 0;
        foreach ($L as $l) { $q = max(1, (int)$l['qty']); $pieces += $q; $items[] = ['name' => mb_substr(trim($l['short'] . (!empty($l['chain']) ? '' : ' ' . $l['label'])), 0, 100), 'sku' => (string)(($l['sku'] ?? '') !== '' ? $l['sku'] : 'WM' . (int)$l['vid']), 'units' => $q, 'selling_price' => round($l['total_p'] / 100 / $q, 2), 'discount' => '', 'tax' => '', 'hsn' => '']; }
        $parts = preg_split('/\s+/', trim((string)$cust['name']), 2); $cod = (int)$r['cod_p'] > 0;
        $w = (float)setting_get($pdo, 'wmo_sr_weight', '0.5'); $w = max($w, round(0.12 * $pieces, 2));      // kg: at least the setting, about 120 g a bottle
        $body = ['order_id' => (string)$r['order_no'], 'order_date' => date('Y-m-d H:i'), 'pickup_location' => $pick, 'comment' => 'Manual order from WhatsApp', 'billing_customer_name' => (string)$parts[0], 'billing_last_name' => (string)($parts[1] ?? ''),
            'billing_address' => (string)$cust['addr1'], 'billing_address_2' => trim((string)$cust['addr2'] . ($cust['landmark'] !== '' ? ', ' . $cust['landmark'] : ''), ', '), 'billing_city' => (string)$cust['city'], 'billing_pincode' => (string)$cust['pin'], 'billing_state' => (string)$cust['state'], 'billing_country' => 'India',
            'billing_email' => (string)($cust['email'] !== '' ? $cust['email'] : 'noemail@tmperfumehouse.com'), 'billing_phone' => (string)$cust['phone'], 'shipping_is_billing' => true, 'order_items' => $items, 'payment_method' => $cod ? 'COD' : 'Prepaid',
            'sub_total' => round(($cod ? $r['cod_p'] : $r['total_p']) / 100, 2), 'length' => (float)setting_get($pdo, 'wmo_sr_length', '15'), 'breadth' => (float)setting_get($pdo, 'wmo_sr_breadth', '12'), 'height' => (float)setting_get($pdo, 'wmo_sr_height', '8'), 'weight' => $w];
        $res = null; for ($try = 0; $try < 2; $try++) { [$code, $j, $err] = wmo_sr_http('POST', $base . '/v1/external/orders/create/adhoc', ['Authorization: Bearer ' . $tok], $body); if ($code === 401 && $try === 0) { [$tok, $er] = wmo_sr_token($pdo, true); if ($tok === '') return 'Shiprocket order not made: ' . $er; continue; } $res = [$code, $j, $err]; break; }
        [$code, $j, $err] = $res ?: [0, null, 'no answer']; if ($err !== '') return 'Shiprocket order not made: ' . $err;
        $sid = is_array($j) ? (string)($j['order_id'] ?? '') : ''; if (($code === 200 || $code === 201) && $sid !== '') {
            $pdo->prepare("UPDATE wmo_order SET sr_order_id = ?, sr_note = ? WHERE id = ?")->execute([mb_substr($sid, 0, 24), 'Created in Shiprocket as ' . $r['order_no'], (int)$r['id']]); return 'Shiprocket order created (id ' . $sid . ') as ' . $r['order_no'] . ': the tracking number comes to Dispatch by itself once you ship it.'; }
        $msg = is_array($j) ? (string)($j['message'] ?? '') : ''; if (is_array($j) && !empty($j['errors'])) $msg .= ' ' . mb_substr(json_encode($j['errors'], JSON_UNESCAPED_UNICODE), 0, 160);
        $note = 'Shiprocket refused it (HTTP ' . $code . ')' . ($msg !== '' ? ': ' . mb_substr(trim($msg), 0, 200) : '') . '.'; $pdo->prepare("UPDATE wmo_order SET sr_note = ? WHERE id = ?")->execute([mb_substr($note, 0, 250), (int)$r['id']]); return 'Shiprocket order not made: ' . $note;
    } catch (Throwable $e) { error_log('wmo_shiprocket: ' . $e->getMessage()); return 'Shiprocket order not made: ' . mb_substr($e->getMessage(), 0, 120); }
}
/** The GST invoice of a confirmed manual order: made by the billing module of the chain cart (wch.php, with the GST details of Learn & Setup -> Cart setup -> Billing) and sent to the customer on WhatsApp.
 *  The module makes invoices from a cart, so a finished cart row is written for the order. Returns a short note; never throws. */
function wmo_invoice(PDO $pdo, array $r, array $cust, int $emp = 0): string
{
    foreach (['wch_billing_gate', 'wch_bill_make', 'wch_bill_send', 'wch_arr'] as $f) if (!function_exists($f)) return 'GST invoice is not available on this server.';
    try {
        $g = wch_billing_gate($pdo); if (empty($g['ok'])) return 'GST invoice not made: the billing details are incomplete (Learn & Setup → Cart setup → Billing: ' . implode(', ', array_slice((array)($g['missing'] ?? []), 0, 4)) . '). Then press "GST invoice" on the order.';
        $L = json_decode((string)$r['lines_json'], true) ?: []; $lines = [];
        foreach ($L as $l) { $parts = wmo_split((int)$l['total_p'], array_fill(0, max(1, (int)$l['qty']), 1)); for ($i = 0; $i < max(1, (int)$l['qty']); $i++) $lines[] = ['kind' => !empty($l['chain']) ? 'chain' : 'perfume', 'vid' => (int)$l['vid'], 'sku' => (string)($l['sku'] ?? ''), 'short' => (string)$l['short'], 'title' => (string)$l['title'], 'ml' => $l['ml'] === null ? null : (int)$l['ml'], 'amount_p' => (int)$parts[$i]]; }
        $plan = ['lines' => $lines, 'sum_p' => (int)$r['total_p']];
        $ship = ['name' => $cust['name'], 'address1' => $cust['addr1'], 'address2' => trim($cust['addr2'] . ($cust['landmark'] !== '' ? ', ' . $cust['landmark'] : ''), ', '), 'city' => $cust['city'], 'zip' => $cust['pin'], 'province' => $cust['state'], 'province_code' => ''];
        $q = $pdo->prepare("SELECT * FROM wch_cart WHERE order_name = ? ORDER BY id DESC LIMIT 1"); $q->execute([(string)$r['order_no']]); $cart = $q->fetch();
        if (!$cart) {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO wch_cart (phone, emp_id, status, total_p, ml, lines_json, plan_json, order_sid, order_name, order_mode, order_paid, order_at, order_total_p, ship_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([(string)$r['phone'], (int)$r['assign_emp'], 'ordered', (int)$r['total_p'], (int)$r['ml'], json_encode(array_map(fn($l) => ['vid' => (int)$l['vid'], 'qty' => (int)$l['qty']], $L)), json_encode($plan, JSON_UNESCAPED_UNICODE), (string)wmo_sid((int)$r['id']), (string)$r['order_no'], (int)$r['cod_p'] > 0 ? 'cod' : 'prepaid', 1, $now, (int)$r['total_p'], json_encode($ship, JSON_UNESCAPED_UNICODE), $now, $now]);
            $q = $pdo->prepare("SELECT * FROM wch_cart WHERE id = ?"); $q->execute([(int)$pdo->lastInsertId()]); $cart = $q->fetch();
        }
        [$ok, $err, , $inv] = wch_bill_make($pdo, $cart, ''); if (!$ok || !$inv) return 'GST invoice not made: ' . $err;
        [$ok2, $err2] = wch_bill_send($pdo, $inv, $emp); return $ok2 ? 'GST invoice ' . $inv['inv_no'] . ' sent to the customer.' : 'GST invoice ' . $inv['inv_no'] . ' made but not sent: ' . $err2;
    } catch (Throwable $e) { error_log('wmo_invoice: ' . $e->getMessage()); return 'GST invoice not made: ' . mb_substr($e->getMessage(), 0, 120); }
}


// ------------------------------------------------------------------------------------------------------------------ the customer's page
function wmo_page(string $title, string $body, int $status = 200, ?callable $after = null): void
{
    http_response_code($status); header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title><style>'
        . ':root{--g:#128c5a;--ink:#17202a;--mut:#667781;--line:#e3e8ee;--bad:#c0392b}*{box-sizing:border-box}body{margin:0;background:#f3f6f4;color:var(--ink);font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
        . '.w{max-width:520px;margin:0 auto;padding:16px 14px 40px}h1{font-size:21px;margin:6px 0 2px}.sub{color:var(--mut);font-size:14px;margin-bottom:12px}.card{background:#fff;border-radius:16px;padding:14px;margin:12px 0;box-shadow:0 1px 6px #0001}'
        . '.ln{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dashed var(--line);font-size:14.5px}.ln:last-child{border:0}.tot{font-weight:800}label{display:block;font-weight:700;font-size:14px;margin:12px 0 4px}label small{font-weight:400;color:var(--mut)}'
        . 'input{width:100%;font:inherit;padding:12px;border:1.5px solid #cfd8dc;border-radius:12px;background:#fff}input:focus{outline:0;border-color:var(--g)}.r2{display:grid;grid-template-columns:1fr 1fr;gap:10px}'
        . 'button{width:100%;margin-top:16px;padding:15px;border:0;border-radius:14px;background:var(--g);color:#fff;font-weight:800;font-size:17px;font-family:inherit;cursor:pointer}.err{background:#fdecea;color:var(--bad);border-radius:12px;padding:10px 12px;margin:10px 0;font-weight:700}.ok{font-size:44px;text-align:center}'
        . '</style></head><body><div class="w">' . $body . '</div></body></html>';
    if ($after) {      // the customer already has his page: the messages, the invoice and Shiprocket are done now, without making him wait
        ignore_user_abort(true); @set_time_limit(90);
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { header('Connection: close'); @ob_end_flush(); @flush(); }
        try { $after(); } catch (Throwable $e) { error_log('wmo after: ' . $e->getMessage()); }
    }
    exit;
}
/** GET = the form; POST = the customer pressed Done. */
function wmo_form(PDO $pdo): void
{
    if (!wmo_tables($pdo)) wmo_page('Order', '<div class="card">Please try again in a moment.</div>', 503);
    $t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? '')); $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    if (strlen($t) !== 32 || !wmo_throttle('form' . $t, 30)) wmo_page('Order', '<div class="card"><b>This link is not valid.</b><br>Please ask us on WhatsApp for a new one.</div>', 404);
    $q = $pdo->prepare("SELECT * FROM wmo_order WHERE token = ?"); $q->execute([$t]); $r = $q->fetch();
    if (!$r) wmo_page('Order', '<div class="card"><b>This link is not valid.</b><br>Please ask us on WhatsApp for a new one.</div>', 404);
    $L = json_decode((string)$r['lines_json'], true) ?: [];
    if ($r['status'] === 'confirmed') wmo_page('Order confirmed', '<div class="card"><div class="ok">✅</div><h1 style="text-align:center">Your order ' . $e($r['order_no']) . ' is confirmed</h1><p style="text-align:center" class="sub">Thank you! We will message you the tracking details on WhatsApp.</p></div>');
    if ($r['status'] === 'cancelled' || strtotime((string)$r['created_at']) < time() - 7 * 86400) wmo_page('Order', '<div class="card"><b>This link has expired.</b><br>Please ask us on WhatsApp for a new one.</div>', 410);
    $err = ''; $v = ['name' => '', 'phone' => '', 'phone2' => '', 'addr1' => '', 'addr2' => '', 'landmark' => '', 'pin' => '', 'city' => '', 'state' => '', 'email' => ''];
    $c0 = function_exists('wa_contact') ? wa_contact($pdo, (string)$r['phone']) : null; $v['phone'] = (string)$r['phone']; $v['name'] = $c0 ? trim((string)($c0['name'] ?? '')) : '';      // the chat's number and name are filled in; the customer can change them
    $ad0 = json_decode((string)($r['addr_json'] ?? ''), true); if (is_array($ad0)) { if (!empty($ad0['name'])) $v['name'] = (string)$ad0['name']; foreach (['addr1', 'landmark', 'pin', 'city', 'state'] as $k0) if (!empty($ad0[$k0])) $v[$k0] = (string)$ad0[$k0]; }   // typed by the salesperson on the Manual order page: the customer only checks it
    if (empty($r['opened_at'])) $pdo->prepare("UPDATE wmo_order SET opened_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        foreach ($v as $k => $_) $v[$k] = (string)($_POST[$k] ?? '');
        [$cl, $er] = wmo_check_form($_POST, (string)$r['phone']);
        if ($er === '' && wmo_throttle('submit' . $t, 6)) {
            $pdo->beginTransaction();
            try {
                $lk = $pdo->prepare("SELECT status FROM wmo_order WHERE id = ? FOR UPDATE"); $lk->execute([(int)$r['id']]);
                if ($lk->fetchColumn() === 'confirmed') { $pdo->commit(); wmo_page('Order confirmed', '<div class="card"><div class="ok">✅</div><h1 style="text-align:center">Your order ' . $e($r['order_no']) . ' is confirmed</h1></div>'); }
                $no = wmo_finalize($pdo, $r, $cl); $pdo->commit();
                wmo_page('Order confirmed', '<div class="card"><div class="ok">✅</div><h1 style="text-align:center">Thank you, ' . $e(explode(' ', $cl['name'])[0]) . '!</h1><p style="text-align:center">Your order <b>' . $e($no) . '</b> is confirmed.<br>We will message you the tracking details on WhatsApp.</p></div>', 200, function () use ($pdo, $r, $cl) { wmo_notify($pdo, $r, $cl); });
            } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('wmo_form: ' . $ex->getMessage()); if (function_exists('wa_log')) { try { wa_log($pdo, 'wmo', false, 'Done on ' . $r['order_no'] . ': ' . mb_substr($ex->getMessage(), 0, 300)); } catch (Throwable $e2) { } } $err = 'Something went wrong (' . mb_substr(preg_replace('/\s+/', ' ', $ex->getMessage()), 0, 110) . '). Please press Done once more, or tell us on WhatsApp.'; }
        } else $err = $er !== '' ? $er : 'Please wait a minute and try again.';
    }
    $rows = ''; foreach ($L as $l) $rows .= '<div class="ln"><span>' . (int)$l['qty'] . ' × ' . $e($l['short']) . (!empty($l['chain']) ? '' : ($l['label'] !== '' ? ' · ' . $e($l['label']) : '')) . '</span>' . (!empty($l['single']) ? '<span>' . $e(wmo_rs((int)$l['total_p'])) . '</span>' : '') . '</div>';
    $money = '<div class="ln tot"><span>Order total</span><span>' . $e(wmo_rs((int)$r['total_p'])) . '</span></div>' . ((int)$r['paid_p'] > 0 ? '<div class="ln"><span>Already paid</span><span>' . $e(wmo_rs((int)$r['paid_p'])) . '</span></div>' : '')
        . ((int)$r['cod_p'] > 0 ? '<div class="ln tot"><span>Pay on delivery</span><span>' . $e(wmo_rs((int)$r['cod_p'])) . '</span></div>' : '<div class="ln"><span>Payment</span><span>Fully paid ✅</span></div>');
    $f = function ($n, $lab, $ph = '', $attr = '') use ($v, $e) { return '<label>' . $lab . '</label><input name="' . $n . '" value="' . $e($v[$n]) . '" ' . $attr . ($ph !== '' ? ' placeholder="' . $e($ph) . '"' : '') . '>'; };
    $stf = function ($n) use ($v, $e) {      // a list of the states: the GST on the invoice needs a real state
        $sts = function_exists('wch_states') ? array_values(wch_states()) : [];
        if (!$sts) return '<label>State</label><input name="state" value="' . $e($v['state']) . '" required id="state">';
        $o = '<option value="">— choose —</option>'; foreach ($sts as $x) $o .= '<option value="' . $e($x) . '"' . (strcasecmp($v['state'], $x) === 0 ? ' selected' : '') . '>' . $e($x) . '</option>';
        return '<label>State</label><select name="state" id="state" required style="width:100%;font:inherit;padding:12px;border:1.5px solid #cfd8dc;border-radius:12px;background:#fff">' . $o . '</select>';
    };
    $body = '<h1>🧾 Your order ' . $e($r['order_no']) . '</h1><div class="sub">TM Perfume House · please check and add your delivery details</div><div class="card">' . $rows . '</div><div class="card">' . $money . '</div>'
        . ($err !== '' ? '<div class="err">' . $e($err) . '</div>' : '') . '<form method="post" class="card" autocomplete="on">'
        . $f('name', 'Full name', '', 'required autocomplete="name"') . $f('phone', 'Mobile number <small>(the one you use on WhatsApp)</small>', '10-digit mobile', 'required inputmode="numeric" maxlength="14" autocomplete="tel"') . $f('phone2', 'Second mobile number <small>(optional)</small>', '', 'inputmode="numeric" maxlength="14"')
        . $f('addr1', 'House / flat no., building, street', '', 'required autocomplete="address-line1"') . $f('addr2', 'Area / locality <small>(optional)</small>', '', 'autocomplete="address-line2"') . $f('landmark', 'Landmark <small>(optional)</small>')
        . '<div class="r2"><div>' . $f('pin', 'Pincode <small>(exactly 6 digits)</small>', '6 digits', 'required inputmode="numeric" pattern="[1-9][0-9]{5}" minlength="6" maxlength="6" title="Exactly 6 digits" id="pin" autocomplete="postal-code"') . '</div><div>' . $f('city', 'City', '', 'required id="city"') . '</div></div>' . $stf('state') . $f('email', 'E-mail <small>(optional)</small>', '', 'type="email"')
        . '<button type="submit">Done — confirm my order</button></form>'
        . '<script>(function(){var p=document.getElementById("pin"),c=document.getElementById("city"),s=document.getElementById("state");if(!p)return;p.addEventListener("input",function(){var v=p.value.replace(/\D/g,"").slice(0,6);if(v!==p.value)p.value=v;if(v.length===6){fetch(location.pathname.replace(/[^\/]*$/,"")+"api.php?action=wmo.pin&pin="+v).then(function(r){return r.json()}).then(function(d){if(d&&d.city){if(!c.value)c.value=d.city;if(!s.value)s.value=d.state}}).catch(function(){})}})})();</script>';
    wmo_page('Your order ' . $r['order_no'], $body);
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- actions
$__wmo = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wmo, 'wmo.') === 0) {
    if ($__wmo === 'wmo.form') wmo_form($pdo);
    if ($__wmo === 'wmo.pin') { if (!wmo_throttle('pin', 40)) json_response(['error' => 'Too many tries.'], 429); json_response(wmo_pin_lookup(preg_replace('/\D+/', '', (string)($_GET['pin'] ?? ''))) ?: ['city' => '', 'state' => '']); }
    $auth = require_auth();
    if (!wmo_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    if (!wmo_on($pdo)) json_response(['error' => 'Manual orders are switched off (setting wmo_on).'], 403);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $in = $isPost ? json_input() : $_GET; $me = (int)($auth['employee_id'] ?? 0);
    $chat = function () use ($pdo, $auth, $in) { $k = wmo_phone10($in['phone'] ?? ''); $c = strlen($k) === 10 && function_exists('wa_contact') ? wa_contact($pdo, $k) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404); if (function_exists('wa_can_chat') && !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403); return $c; };
    $mine = function () use ($pdo, $auth, $in, $__wmo) { $q = $pdo->prepare("SELECT * FROM wmo_order WHERE id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(); if (!$r) json_response(['error' => 'Order not found.'], 404); $c = wa_contact($pdo, (string)$r['phone']); if ($c && function_exists('wa_can_chat') && !wa_can_chat($pdo, $auth, $c) && !(in_array($__wmo, ['wmo.invoice', 'wmo.shiprocket'], true) && wmo_dsp_ok($pdo, $auth))) json_response(['error' => 'This chat is assigned to someone else.'], 403); return $r; };
    switch ($__wmo) {
        case 'wmo.dsp_access': { json_response(['ok' => wmo_dsp_ok($pdo, $auth)]); }
        case 'wmo.dsp_list': case 'wmo.dsp_export': {
            if (!wmo_dsp_ok($pdo, $auth)) json_response(['error' => 'The manual orders list is for Admin, Dispatch, the Admin department and Sales Heads.'], 403);
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? (string)$in['from'] : biz_today($pdo); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? (string)$in['to'] : $from; if ($to < $from) $to = $from;
            if ((strtotime($to) - strtotime($from)) > 400 * 86400) json_response(['error' => 'Choose at most 400 days.'], 422);
            $status = (string)($in['status'] ?? ''); $pay = (string)($in['pay'] ?? ''); $qq = (string)($in['q'] ?? ''); $tot = 0; $sum = [];
            if ($__wmo === 'wmo.dsp_export') { @set_time_limit(120); $rows = wmo_dsp_rows($pdo, $from, $to, $status, $pay, $qq, 5000, 0, $tot, $sum); json_response(['ok' => true, 'rows' => $rows, 'total' => $tot, 'from' => $from, 'to' => $to, 'costs' => wmo_costs($pdo)]); }
            $page = max(1, (int)($in['page'] ?? 1)); $per = 30; $rows = wmo_dsp_rows($pdo, $from, $to, $status, $pay, $qq, $per, ($page - 1) * $per, $tot, $sum);
            $bill = ['ok' => true, 'missing' => []]; try { if (function_exists('wch_billing_gate')) { $g = wch_billing_gate($pdo); $bill = ['ok' => !empty($g['ok']), 'missing' => array_values(array_slice((array)($g['missing'] ?? []), 0, 8))]; } else $bill = ['ok' => false, 'missing' => ['GST invoice module is not installed']]; } catch (Throwable $e) { }   // why invoices are not made: the GST billing details
            json_response(['ok' => true, 'rows' => $rows, 'total' => $tot, 'page' => $page, 'pages' => max(1, (int)ceil($tot / $per)), 'sum' => $sum, 'from' => $from, 'to' => $to, 'billing' => $bill]);
        }
        case 'wmo.weight_get': {   // ⚖ grams per size (Manual order sheet → Weights)
            if (!wmo_dsp_ok($pdo, $auth)) json_response(['error' => 'Not allowed.'], 403);
            $sizes = []; try { $sizes = array_map('intval', $pdo->query("SELECT DISTINCT v.ml FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE v.ml IS NOT NULL AND v.ml > 0 AND p.kind NOT IN ('chain','other') ORDER BY v.ml")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { }
            json_response(['ok' => true, 'weights' => wmo_weights($pdo), 'sizes' => $sizes, 'can_edit' => in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)]);
        }
        case 'wmo.weight_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin or HR can change the weights.'], 403);
            $sz = []; foreach ((array)($in['sizes'] ?? []) as $k => $v) { if (!is_numeric($k) || $v === '' || $v === null) continue; if (!is_numeric($v) || (float)$v < 0 || (float)$v > 20000) json_response(['error' => 'Weights are grams from 0 to 20000.'], 422); $sz[(string)(0 + $k)] = (int)round((float)$v); }
            $one = function ($v) { return ($v === '' || $v === null || !is_numeric($v)) ? null : max(0, min(20000, (int)round((float)$v))); };
            setting_set($pdo, 'wmo_weight', json_encode(['sizes' => (object)$sz, 'chain' => $one($in['chain'] ?? null), 'other' => $one($in['other'] ?? null), 'pack' => $one($in['pack'] ?? null)]));
            json_response(['ok' => true, 'weights' => wmo_weights($pdo)]);
        }
        case 'wmo.dsp_gateway': {   // which gateway / QR the money came through (set by Dispatch / Admin)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (!wmo_dsp_ok($pdo, $auth)) json_response(['error' => 'Not allowed.'], 403);
            $g = trim((string)($in['gateway'] ?? '')); if (!in_array($g, ['', 'Razorpay', 'Quick', 'Static QR', 'Dynamic QR', 'HDFC', 'Cash / COD', 'Other'], true)) json_response(['error' => 'Choose one of the listed gateways.'], 422);
            $pdo->prepare("UPDATE wmo_order SET gateway = ? WHERE id = ?")->execute([$g, (int)($in['id'] ?? 0)]); json_response(['ok' => true, 'gateway' => $g]);
        }
        case 'wmo.cost_get': {   // 💰 purchase cost per size (+ chain, other product) and the perfume sizes in the catalogue
            if (!wmo_dsp_ok($pdo, $auth)) json_response(['error' => 'Not allowed.'], 403);
            $sizes = []; try { $sizes = array_map('intval', $pdo->query("SELECT DISTINCT v.ml FROM wch_var v JOIN wch_prod p ON p.pid = v.pid WHERE v.ml IS NOT NULL AND v.ml > 0 AND p.kind NOT IN ('chain','other') ORDER BY v.ml")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { }
            json_response(['ok' => true, 'costs' => wmo_costs($pdo), 'sizes' => $sizes, 'can_edit' => in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)]);
        }
        case 'wmo.cost_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin or HR can change purchase costs.'], 403);
            $sz = []; foreach ((array)($in['sizes'] ?? []) as $k => $v) { if (!is_numeric($k) || $v === '' || $v === null) continue; if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000) json_response(['error' => 'Costs must be numbers from 0 to 100000.'], 422); $sz[(string)(0 + $k)] = round((float)$v, 2); }
            $one = function ($v) { return ($v === '' || $v === null || !is_numeric($v)) ? null : max(0, min(100000, round((float)$v, 2))); };
            setting_set($pdo, 'fp_cost', json_encode(['sizes' => (object)$sz, 'chain' => $one($in['chain'] ?? null), 'other' => $one($in['other'] ?? null)]));
            json_response(['ok' => true, 'costs' => wmo_costs($pdo)]);
        }
        case 'wmo.dsp_shot': {   // the payment screenshot of one manual order (Dispatch people / Admin)
            if (!wmo_dsp_ok($pdo, $auth)) json_response(['error' => 'Not allowed.'], 403);
            $q = $pdo->prepare("SELECT shot_mime, shot_b64 FROM wmo_order WHERE id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(); if (!$r || (string)$r['shot_b64'] === '') json_response(['error' => 'No payment screenshot was saved for this order.'], 404);
            while (ob_get_level() > 0) @ob_end_clean(); header('Content-Type: ' . $r['shot_mime']); header('Cache-Control: private, max-age=600'); echo base64_decode((string)$r['shot_b64']); exit;
        }
        case 'wmo.start': {   // sidebar "Manual order": a customer who is not in HRMS yet → make the chat record (held by the salesperson), then the normal manual-order sheet opens on it
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $k = wmo_phone10($in['phone'] ?? '');
            if (strlen($k) !== 10) json_response(['error' => 'Enter the 10-digit customer mobile number.'], 422);
            $c = wa_contact($pdo, $k);
            if (!$c) {
                $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, state, status, source) VALUES (?, ?, ?, ?, 'unassigned', 'open', '')")->execute([$k, '91' . $k, mb_substr(trim((string)($in['name'] ?? '')), 0, 120), date('Y-m-d H:i:s')]);
                $e = (int)($auth['employee_id'] ?? 0);
                if ($e > 0) { try { $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ?, state = 'human' WHERE phone = ?")->execute([$e, $k]); } catch (Throwable $ex) { } }
                $c = wa_contact($pdo, $k);
            } elseif (function_exists('wa_can_chat') && !wa_can_chat($pdo, $auth, $c)) {
                $e = (int)($auth['employee_id'] ?? 0);
                if ((int)($c['assigned_emp'] ?? 0) <= 0 && $e > 0) { try { $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ?, state = 'human' WHERE phone = ?")->execute([$e, $k]); } catch (Throwable $ex) { } }
                else json_response(['error' => 'This customer is already with another salesperson.'], 403);
            }
            json_response(['ok' => true, 'phone' => $k, 'name' => (string)($c['name'] ?? '')]);
        }
        case 'wmo.shot_read': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $mime = strtolower((string)($in['mime'] ?? 'image/jpeg')); $b64 = preg_replace('/^data:[^,]*,/', '', (string)($in['image'] ?? ''));
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $b64 === '' || strlen($b64) > 6000000 || base64_decode($b64, true) === false) json_response(['error' => 'Send a JPG, PNG or WebP screenshot under 4 MB.'], 422);
            json_response(wmo_read_shot($pdo, $b64, $mime));
        }
        case 'wmo.create': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $c = $chat(); $pay = (string)($in['pay'] ?? ''); if (!in_array($pay, ['prepaid', 'partial', 'cod'], true)) json_response(['error' => 'Choose Full prepaid, Part prepaid or Full COD.'], 422);
            $total = wmo_p($in['total'] ?? 0); $paid = $pay === 'cod' ? 0 : wmo_p($in['paid'] ?? 0); $utr = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['utr'] ?? ''));
            if ($total < 10000 || $total > 50000000) json_response(['error' => 'The order total must be between ₹100 and ₹500000.'], 422);
            if ($pay === 'prepaid') $paid = $total; if ($pay === 'partial' && ($paid < 100 || $paid >= $total)) json_response(['error' => 'For a part payment the paid amount must be more than ₹1 and less than the total.'], 422);
            if ($pay !== 'cod' && empty($in['shot'])) json_response(['error' => 'Upload the payment screenshot first.'], 422);
            if ($utr !== '') { $d = $pdo->prepare("SELECT order_no FROM wmo_order WHERE utr = ? AND status <> 'cancelled' LIMIT 1"); $d->execute([$utr]); $dn = $d->fetchColumn(); if ($dn && empty($in['dup_ok'])) json_response(['error' => 'This payment reference (UTR) is already used on order ' . $dn . '. The same payment cannot pay two orders.', 'code' => 'dup', 'order' => $dn], 409); }
            [$L, $ml, $er] = wmo_lines($pdo, $total, (array)($in['lines'] ?? []), (array)($in['chains'] ?? []), (array)($in['singles'] ?? [])); if ($er !== '') json_response(['error' => $er], 422);
            $shot = (string)($in['shot'] ?? ''); $smime = strtolower((string)($in['shot_mime'] ?? 'image/jpeg')); $sb = preg_replace('/^data:[^,]*,/', '', $shot); if ($sb !== '' && (strlen($sb) > 3000000 || !in_array($smime, ['image/jpeg', 'image/png', 'image/webp'], true))) $sb = '';
            $otype = in_array((string)($in['otype'] ?? ''), ['query', 'campaign'], true) ? (string)$in['otype'] : ''; $assign = $otype !== '' ? $me : (int)($c['assigned_emp'] ?: $me); /* from the Manual order page: it belongs to the person who makes it */ $token = bin2hex(random_bytes(16)); $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO wmo_order (token, phone, emp_id, assign_emp, status, pay, total_p, paid_p, cod_p, utr, ml, lines_json, shot_mime, shot_b64, shot_json, src, note, created_at) VALUES (?,?,?,?, 'draft', ?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$token, (string)$c['phone'], $me, $assign, $pay, $total, $paid, $total - $paid, $utr, $ml, json_encode($L, JSON_UNESCAPED_UNICODE), $sb !== '' ? $smime : '', $sb !== '' ? $sb : null, wmo_clip(json_encode($in['shot_read'] ?? new stdClass(), JSON_UNESCAPED_UNICODE), 1500), (string)($c['source'] ?? ''), wmo_clip($in['note'] ?? '', 250), $now]);
            $id = (int)$pdo->lastInsertId(); $no = 'WM-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); $pdo->prepare("UPDATE wmo_order SET order_no = ? WHERE id = ?")->execute([$no, $id]);
            $cu = is_array($in['cust'] ?? null) ? $in['cust'] : []; $ad = []; foreach (['name' => 60, 'addr1' => 160, 'landmark' => 80, 'pin' => 6, 'city' => 60, 'state' => 60] as $k0 => $n0) $ad[$k0] = wmo_clip($cu[$k0] ?? '', $n0);
            try { $pdo->prepare("UPDATE wmo_order SET otype = ?, addr_json = ? WHERE id = ?")->execute([$otype, array_filter($ad) ? json_encode($ad, JSON_UNESCAPED_UNICODE) : null, $id]); } catch (Throwable $ex) { }
            $q = $pdo->prepare("SELECT * FROM wmo_order WHERE id = ?"); $q->execute([$id]); json_response(['ok' => true, 'order' => wmo_out($pdo, $q->fetch())]);
        }
        case 'wmo.confirm': {   // the salesperson typed the customer's address on the Manual order page: the order is FINAL at once (no link) — Orders, Allocation, Dispatch, invoice, confirmation message
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $r = $mine(); if ($r['status'] === 'confirmed') json_response(['ok' => true, 'order_no' => $r['order_no']]); if (!in_array($r['status'], ['draft', 'sent'], true)) json_response(['error' => 'This order is already ' . $r['status'] . '.'], 422);
            $cu = is_array($in['cust'] ?? null) ? $in['cust'] : []; $f = ['name' => $cu['name'] ?? '', 'phone' => $cu['phone'] ?? $r['phone'], 'phone2' => '', 'addr1' => $cu['addr1'] ?? '', 'addr2' => '', 'landmark' => $cu['landmark'] ?? '', 'pin' => $cu['pin'] ?? '', 'city' => $cu['city'] ?? '', 'state' => $cu['state'] ?? '', 'email' => ''];
            [$cl, $er] = wmo_check_form($f, (string)$r['phone']); if ($er !== '') json_response(['error' => $er], 422);
            try { $pdo->beginTransaction(); $lk = $pdo->prepare("SELECT status FROM wmo_order WHERE id = ? FOR UPDATE"); $lk->execute([(int)$r['id']]); if ($lk->fetchColumn() === 'confirmed') { $pdo->commit(); json_response(['ok' => true, 'order_no' => $r['order_no']]); }
                $no = wmo_finalize($pdo, $r, $cl); if ($pdo->inTransaction()) $pdo->commit(); }
            catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('wmo.confirm: ' . $ex->getMessage()); if (function_exists('wa_log')) { try { wa_log($pdo, 'wmo', false, 'Final order ' . $r['order_no'] . ': ' . mb_substr($ex->getMessage(), 0, 300)); } catch (Throwable $e2) { } } json_response(['error' => 'Could not save the order: ' . mb_substr(preg_replace('/\s+/', ' ', $ex->getMessage()), 0, 160)], 500); }
            register_shutdown_function(function () use ($pdo, $r, $cl) { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); @ignore_user_abort(true); @set_time_limit(90); try { wmo_notify($pdo, $r, $cl); } catch (Throwable $e) { } });
            json_response(['ok' => true, 'order_no' => $no]);
        }
        case 'wmo.send': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $r = $mine(); if (!in_array($r['status'], ['draft', 'sent'], true)) json_response(['error' => 'This order is already ' . $r['status'] . '.'], 422);
            $c = wa_contact($pdo, (string)$r['phone']); $nm = function_exists('wo_first_name') ? wo_first_name((string)($c['name'] ?? '')) : 'ji';
            $text = "Hello " . $nm . "! 🙏 Thank you for your payment" . ((int)$r['paid_p'] > 0 ? " of " . wmo_rs((int)$r['paid_p']) : '') . ".\n\nYour order " . $r['order_no'] . " is ready. Please open this link, check your order and add your delivery address — the order is confirmed as soon as you press Done:\n" . wmo_link((string)$r['token']);
            [$ok, $res] = wa_send_text($pdo, $c, $text, 'emp', $me);
            if (!$ok) json_response(['error' => (string)$res, 'link' => wmo_link((string)$r['token']), 'code' => 'send'], 422);
            $pdo->prepare("UPDATE wmo_order SET status = 'sent', sent_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]);
            if (function_exists('wa_event')) { try { wa_event($pdo, (string)$r['phone'], '🧾 Manual order ' . $r['order_no'] . ' — address link sent (' . wmo_rs((int)$r['total_p']) . ')'); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'link' => wmo_link((string)$r['token'])]);
        }
        case 'wmo.list': {
            $c = $chat(); $q = $pdo->prepare("SELECT * FROM wmo_order WHERE phone = ? ORDER BY id DESC LIMIT 20"); $q->execute([(string)$c['phone']]); json_response(['ok' => true, 'orders' => array_map(fn($r) => wmo_out($pdo, $r, true), $q->fetchAll())]);
        }
        case 'wmo.invoice': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $r = $mine(); if ($r['status'] !== 'confirmed') json_response(['error' => 'The invoice is made after the customer confirms the order.'], 422);
            $cust = json_decode((string)$r['cust_json'], true) ?: []; $note = wmo_invoice($pdo, $r, $cust, $me); json_response(['ok' => strpos($note, 'sent to the customer') !== false, 'note' => $note]);
        }
        case 'wmo.shiprocket': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $r = $mine(); if ($r['status'] !== 'confirmed') json_response(['error' => 'The Shiprocket order is made after the customer confirms.'], 422);
            $cust = json_decode((string)$r['cust_json'], true) ?: []; $note = wmo_shiprocket($pdo, $r, $cust); json_response(['ok' => strpos($note, 'created') !== false || strpos($note, 'already') !== false, 'note' => $note]);
        }
        case 'wmo.cancel': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $r = $mine(); if ($r['status'] === 'confirmed') json_response(['error' => 'The customer already confirmed this order. Cancel it like any other order.'], 422);
            $pdo->prepare("UPDATE wmo_order SET status = 'cancelled', cancelled_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]); json_response(['ok' => true]);
        }
        default: json_response(['error' => 'Unknown manual-order action.'], 404);
    }
}
