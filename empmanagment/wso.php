<?php
/**
 * wso.php — 🛒 WEBSITE ORDER from a WhatsApp chat (owner, 24 Nov): the website's "Create order" without opening the website admin.
 *
 * In the chat the salesperson presses "🛒 Website order", picks the perfumes (the website catalogue), chooses Full payment link / Advance + COD link,
 * optionally a discount, sees the total (the website works it out: same maths as Orders > Create order) and presses "Create & send link".
 * The order is made ON THE WEBSITE through its API (POST /api/v1/orders, with a reference so a double tap never makes two orders); the website answers
 * with the payment link and its WhatsApp text, which is sent into this chat at once. The customer opens the link, checks the address, sees the gift perfume
 * offer and pays. When paid, the order comes back to HRMS like every website order (store_orders.php: given to the chat's salesperson).
 *
 * The website key (Website orders > Connection, setting tms_api_key) needs the scopes read_products and create_orders (and read_orders, already used).
 * Actions: wso.products GET {q} · wso.preview POST {phone, lines, link_type, discount} · wso.create POST {phone, name, lines, link_type, discount, send}
 * PHP 7.4 safe. Own table: wso_order (what was made from which chat).
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wso_post')) {
    /** POST to the website API. [data, error, http code] */
    function wso_post(PDO $pdo, string $path, array $body): array
    {
        $c = tms_cfg($pdo); if ($c['key'] === '') return [null, 'The website key is not saved yet. Admin: Sales > Orders > Website orders > Connection.', 0];
        if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?/#i', $c['base'] . '/')) return [null, 'The website address must start with https://', 0];
        $ch = curl_init($c['base'] . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['key'], 'Accept: application/json', 'Content-Type: application/json']]);
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = $out === false ? curl_error($ch) : ''; curl_close($ch);
        if ($err !== '') return [null, 'The website could not be reached: ' . mb_substr($err, 0, 120), 0];
        $j = json_decode((string)$out, true);
        if ($code === 403 && is_array($j) && stripos((string)($j['error'] ?? ''), 'scope') !== false) return [null, 'The website key may not create orders yet. Admin: on the website, Settings > API keys > the HRMS key: tick "create_orders" and "read_products".', $code];
        if (!is_array($j) || empty($j['ok'])) return [null, (is_array($j) && !empty($j['error'])) ? mb_substr((string)$j['error'], 0, 300) : 'The website answered with an error (HTTP ' . $code . ').', $code];
        return [$j['data'] ?? [], '', $code];
    }
}
if (!function_exists('wso_table')) {
    function wso_table(PDO $pdo): bool
    {
        static $ok = null; if ($ok !== null) return $ok;
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS wso_order (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, ref VARCHAR(60) NOT NULL, number VARCHAR(30) NOT NULL DEFAULT '',
            total DECIMAL(12,2) NOT NULL DEFAULT 0, link VARCHAR(255) NOT NULL DEFAULT '', sent TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, UNIQUE KEY uq_wso_ref (ref), KEY k_wso_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
        catch (Throwable $e) { error_log('wso_table: ' . $e->getMessage()); return $ok = false; }
    }
    /** The order body for the website from what the chat sheet sent. */
    function wso_body(array $in, string $phone, string $name, string $sp): array
    {
        $lines = []; foreach (array_slice((array)($in['lines'] ?? []), 0, 50) as $l) { $v = (int)($l['variant_id'] ?? 0); $q = (int)($l['qty'] ?? 1); if ($v > 0 && $q >= 1 && $q <= 99) $lines[] = ['variant_id' => $v, 'quantity' => $q]; }
        $kind = in_array((string)($in['link_type'] ?? 'full'), ['full', 'advance'], true) ? (string)$in['link_type'] : 'full';
        $d = (array)($in['discount'] ?? []); $disc = [];
        if ((float)($d['amount'] ?? 0) > 0) $disc = ['amount' => round((float)$d['amount'], 2), 'reason' => 'WhatsApp chat'];
        elseif ((float)($d['percent'] ?? 0) > 0) $disc = ['percent' => min(100, round((float)$d['percent'], 2)), 'reason' => 'WhatsApp chat'];
        $b = ['items' => $lines, 'customer' => ['phone' => $phone, 'name' => $name], 'shipping_address' => ['name' => $name, 'phone' => $phone],
            'payment' => ['method' => 'link', 'link_type' => $kind, 'link_hours' => 48], 'note' => 'Made from the WhatsApp chat (HRMS)'];
        if ($disc) $b['discount'] = $disc;
        if ($sp !== '') $b['salesperson'] = $sp;
        return $b;
    }
}

$__wso = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wso, 'wso.') !== 0) { return; }
if (!function_exists('tms_cfg') && is_file(__DIR__ . '/store_orders.php')) { require_once __DIR__ . '/store_orders.php'; }   // tms_cfg / tms_get (the website API) — only for a wso.* call
$auth = require_auth();
if (!function_exists('tms_cfg')) json_response(['error' => 'Website orders are not installed.'], 500);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $in = $isPost ? json_input() : $_GET; $me = (int)($auth['employee_id'] ?? 0);
$chat = function () use ($pdo, $auth, $in) {
    $k = substr(preg_replace('/\D+/', '', (string)($in['phone'] ?? '')), -10); $c = strlen($k) === 10 && function_exists('wa_contact') ? wa_contact($pdo, $k) : null;
    if (!$c) json_response(['error' => 'Chat not found.'], 404);
    if (function_exists('wa_can_chat') && !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
    return $c;
};
// the salesperson's name as the website knows it (its salespeople list), else nothing (the website then asks for none: API orders may leave it empty)
$spName = function () use ($pdo, $me): string {
    $nm = ''; try { $q = $pdo->prepare("SELECT name FROM employees WHERE employee_id = ?"); $q->execute([$me]); $nm = trim((string)$q->fetchColumn()); } catch (Throwable $e) { }
    if ($nm === '') return '';
    $run = function () use ($pdo) { [$d, $e] = tms_get($pdo, '/salespeople'); return $e !== '' ? [] : array_column((array)($d['items'] ?? []), 'name'); };
    $list = function_exists('hrms_memo') ? hrms_memo('wso_sp', 600, $run) : $run();
    foreach ((array)$list as $x) { if (strcasecmp(trim((string)$x), $nm) === 0) return (string)$x; }
    $first = strtolower(strtok($nm, ' ') ?: '');
    foreach ((array)$list as $x) { if ($first !== '' && strtolower(strtok((string)$x, ' ') ?: '') === $first) return (string)$x; }
    return '';
};

if ($__wso === 'wso.products') {   // the website catalogue: perfumes matching the search, with their sizes and prices
    $q = mb_substr(trim((string)($in['q'] ?? '')), 0, 60);
    $run = function () use ($pdo, $q) { [$d, $e] = tms_get($pdo, '/products', ['q' => $q, 'status' => 'active', 'limit' => 25]); return $e !== '' ? ['error' => $e] : (array)$d; };
    $d = function_exists('hrms_memo') ? hrms_memo('wso_p:' . strtolower($q), 120, $run) : $run();
    if (!empty($d['error'])) { if (function_exists('hrms_memo_drop')) hrms_memo_drop('wso_p:' . strtolower($q)); json_response(['error' => stripos((string)$d['error'], 'refused') !== false ? 'The website key may not read products yet. Admin: on the website, Settings > API keys > the HRMS key: tick "read_products" and "create_orders".' : $d['error']], 422); }
    $out = [];
    foreach ((array)($d['items'] ?? []) as $p) {
        $vs = []; foreach ((array)($p['variants'] ?? []) as $v) { if ((float)($v['price'] ?? 0) <= 0) continue; $vs[] = ['id' => (int)$v['id'], 'title' => (string)($v['option1'] ?: $v['title']), 'price' => (float)$v['price'], 'mrp' => (float)($v['compare_at_price'] ?? 0),
            'out' => !empty($v['track_inventory']) && ($v['inventory_policy'] ?? '') === 'deny' && (int)($v['inventory_qty'] ?? 0) < 1]; }
        if ($vs) $out[] = ['id' => (int)$p['id'], 'title' => (string)$p['title'], 'image' => (string)($p['image'] ?? ''), 'variants' => $vs];
    }
    json_response(['ok' => true, 'items' => $out]);
}
if ($__wso === 'wso.preview' || $__wso === 'wso.create') {
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    $c = $chat(); $phone = substr(preg_replace('/\D+/', '', (string)$c['phone']), -10);
    $name = mb_substr(trim((string)($in['name'] ?? '')) ?: trim((string)($c['name'] ?? '')) ?: 'Customer', 0, 80);
    $body = wso_body($in, $phone, $name, $spName());
    if (!$body['items']) json_response(['error' => 'Add at least one perfume.'], 422);
    if ($__wso === 'wso.preview') {
        [$d, $e] = wso_post($pdo, '/orders/preview', $body); if ($e !== '') json_response(['error' => $e], 422);
        json_response(['ok' => true, 'preview' => $d]);
    }
    // create: one reference per press (the sheet sends a fresh nonce per order), so a double tap or a retry never makes two orders
    if (!wso_table($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    $nonce = preg_replace('/[^a-z0-9]/i', '', (string)($in['nonce'] ?? '')); if (strlen($nonce) < 6) $nonce = bin2hex(random_bytes(6));
    $ref = 'hrms-wa:' . $phone . ':' . substr($nonce, 0, 24);
    $body['reference'] = $ref;
    [$d, $e] = wso_post($pdo, '/orders', $body); if ($e !== '') json_response(['error' => $e], 422);
    $o = (array)($d['order'] ?? []); $l = (array)($o['payment_link'] ?? []); $num = (string)($o['number'] ?? ''); $url = (string)($l['url'] ?? '');
    try { $pdo->prepare("INSERT INTO wso_order (phone, emp_id, ref, number, total, link, created_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE number = VALUES(number), link = VALUES(link)")->execute([$phone, $me, $ref, $num, (float)($o['total'] ?? 0), mb_substr($url, 0, 255), date('Y-m-d H:i:s')]); } catch (Throwable $ex) { error_log('wso save: ' . $ex->getMessage()); }
    $text = (string)($l['whatsapp']['text'] ?? ''); if ($text === '' && $url !== '') $text = 'Hello ' . $name . ", here is your order " . $num . " from TM Perfume House.\n\nPay securely here:\n" . $url;
    $sent = false; $why = '';
    $was = 0; try { $q = $pdo->prepare("SELECT sent FROM wso_order WHERE ref = ?"); $q->execute([$ref]); $was = (int)$q->fetchColumn(); } catch (Throwable $ex) { }
    if ($was === 1) { $sent = true; $in['send'] = 0; }      // the same press came twice (double tap / retry): the link is in the chat already, it is not sent again
    if (!empty($in['send']) && $text !== '' && function_exists('wa_send_text')) {
        [$ok, $res] = wa_send_text($pdo, $c, $text, 'emp', $me); $sent = (bool)$ok; $why = $ok ? '' : (string)$res;
        if ($ok) { try { $pdo->prepare("UPDATE wso_order SET sent = 1 WHERE ref = ?")->execute([$ref]); } catch (Throwable $ex) { } }
    }
    if (function_exists('wa_event')) { try { wa_event($pdo, (string)$c['phone'], '🛒 Website order ' . $num . ' made from the chat (₹' . number_format((float)($o['total'] ?? 0)) . ')' . ($sent ? ' — payment link sent' : '')); } catch (Throwable $ex) { } }
    json_response(['ok' => true, 'number' => $num, 'total' => (float)($o['total'] ?? 0), 'pay_now' => (float)($l['amount'] ?? 0), 'later' => (float)($l['balance_on_delivery'] ?? 0), 'link' => $url, 'text' => $text, 'sent' => $sent, 'send_error' => $why, 'again' => empty($d['created'])]);
}
json_response(['error' => 'Unknown action.'], 404);
