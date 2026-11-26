<?php
/**
 * dispatch_push.php — sends the HRMS (manual) orders to the dispatch panel by itself, at a time the admin chooses.
 *
 * What it does, in plain words:
 *   at the chosen time (e.g. 18:00) it takes the CONFIRMED manual orders of the business day (and of the day before, so an order made after the last run is not missed),
 *   leaves out the ones already sent, writes them as the same CSV file the "⬇ CSV (Shopify format)" button of Dispatch → Manual orders makes,
 *   and posts that file to an n8n webhook (field "file"). The n8n workflow then does what it does today and inserts the rows into the dispatch database.
 *   An order is remembered as sent (table dpx_sent) when the webhook answers 2xx, so it is never sent twice.
 *
 * Settings (Admin only, on Dispatch → Manual orders): on / off, the n8n webhook address (https), the name and value of the secret header, the time of day.
 * Actions: dpx.get · dpx.save · dpx.run {mode: test|now} · dpx.log   Called every 5 minutes by dpx_tick() from the orders.cron job.
 * dpx.feed = the same CSV for n8n to PULL (schedule inside n8n), protected by a key made on the HRMS screen (dpx.key_new) or the API_KEY of config.php. PHP 7.4 safe.
 */
if (!function_exists('dpx_cols')) {

/** $n8n = the file for the n8n workflow: two more columns (the state NAME, as Shopify's export has) after the usual ones. */
function dpx_cols(bool $n8n = false): array
{
    return array_merge(['Name', 'Email', 'Financial Status', 'Paid at', 'Fulfillment Status', 'Fulfilled at', 'Accepts Marketing', 'Currency', 'Subtotal', 'Shipping', 'Taxes', 'Total', 'Discount Code', 'Discount Amount', 'Shipping Method', 'Created at', 'Lineitem quantity', 'Lineitem name', 'Lineitem price', 'Lineitem compare at price', 'Lineitem sku', 'Lineitem requires shipping', 'Lineitem taxable', 'Lineitem fulfillment status',
        'Billing Name', 'Billing Street', 'Billing Address1', 'Billing Address2', 'Billing Company', 'Billing City', 'Billing Zip', 'Billing Province', 'Billing Country', 'Billing Phone', 'Shipping Name', 'Shipping Street', 'Shipping Address1', 'Shipping Address2', 'Shipping Company', 'Shipping City', 'Shipping Zip', 'Shipping Province', 'Shipping Country', 'Shipping Phone',
        'Notes', 'Note Attributes', 'Cancelled at', 'Payment Method', 'Payment Reference', 'Refunded Amount', 'Vendor', 'Outstanding Balance', 'Employee', 'Location', 'Device ID', 'Id', 'Tags', 'Risk Level', 'Source', 'Phone', 'Order Source (Query/Campaign)', 'Order Status', 'Invoice No', 'Courier', 'AWB', 'Shiprocket Order Id'], $n8n ? ['Billing Province Name', 'Shipping Province Name'] : []);
}

/** Add-ons (Order Allocation / Performance): one line of text per order for the Notes column, so dispatch always sees them.
 *  Loaded once per run from employee_addons; matched on the order name without "#" (so "#71914" and "71914" are the same). */
function dpx_addon_load(PDO $pdo): void
{
    $GLOBALS['dpx_addon_notes'] = [];
    try {
        if (function_exists('ensure_addons_table')) ensure_addons_table($pdo);
        $rows = $pdo->query("SELECT order_name, addon_group, addon_id, product_name, size_ml, qty, price, prepaid_amt, cod_amt, proof_path FROM employee_addons WHERE order_name <> '' ORDER BY addon_id")->fetchAll();
    } catch (Throwable $e) { return; }
    $g = [];
    foreach ($rows as $r) {
        $o = strtolower(trim(ltrim(trim((string)$r['order_name']), '#')));
        $k = (string)($r['addon_group'] ?: 'single-' . $r['addon_id']);
        if (!isset($g[$o][$k])) $g[$o][$k] = ['items' => [], 'total' => 0.0, 'pre' => 0.0, 'cod' => 0.0, 'proof' => false];
        $x =& $g[$o][$k];
        $x['items'][] = trim((string)$r['product_name'] . ((string)$r['size_ml'] !== '' ? ' ' . $r['size_ml'] . ' ML' : '') . ((int)$r['qty'] > 1 ? ' x' . (int)$r['qty'] : ''));
        $x['total'] += (float)$r['price']; $x['pre'] += (float)$r['prepaid_amt']; $x['cod'] += (float)$r['cod_amt'];
        if ((string)$r['proof_path'] !== '') $x['proof'] = true;
        unset($x);
    }
    foreach ($g as $o => $sets) {
        $parts = [];
        foreach ($sets as $x) {
            $pay = [];
            if ($x['pre'] > 0) $pay[] = 'PREPAID Rs ' . number_format($x['pre']);
            if ($x['cod'] > 0) $pay[] = 'COLLECT Rs ' . number_format($x['cod']) . ' on delivery';
            $parts[] = 'ADD-ON: ' . implode(', ', $x['items']) . ' = Rs ' . number_format($x['total']) . ($pay ? ' [' . implode('; ', $pay) . ']' : '');
        }
        $GLOBALS['dpx_addon_notes'][$o] = implode(' | ', $parts);
    }
}

/** The existing note of an order plus its add-on text (if any). */
function dpx_note_with_addon($note, $orderName): string
{
    $note = (string)$note;
    $key = strtolower(trim(ltrim(trim((string)$orderName), '#')));
    $add = (string)(($GLOBALS['dpx_addon_notes'] ?? [])[$key] ?? '');
    return $add === '' ? $note : ($note !== '' ? $note . "\n" : '') . $add;
}

/** The same rows as sheetRows() in dispatch_manual.html (one row per product; the order's own columns on its first row only). $orders = the rows of wmo_dsp_rows().
 *  $n8n = true makes the rows the n8n workflow expects from a Shopify export: dates as "2026-11-16 19:05:11 +0530" (the workflow splits them at the space; the ISO form of the CSV button ends up whole in the date column),
 *  the state NAME in the "Province Name" columns (it decides CGST + SGST for Gujarat, IGST elsewhere) and the order's real GST in "Taxes". */
function dpx_sheet_rows(array $orders, bool $n8n = false, array $addons = []): array
{
    $iso = $n8n ? function ($s) { $s = (string)$s; return $s !== '' ? $s . ' +0530' : ''; } : function ($s) { $s = (string)$s; return $s !== '' ? preg_replace('/ /', 'T', $s, 1) . '+05:30' : ''; };
    $out = [];
    foreach ($orders as $r) {
        $__start = count($out);
        $c = (array)($r['cust'] ?? []); $st = (string)($r['status'] ?? ''); $pay = (string)($r['pay'] ?? '');
        $fin = $st === 'cancelled' ? 'voided' : ($pay === 'prepaid' ? 'paid' : ($pay === 'partial' ? 'partially_paid' : 'pending'));
        $pm = $pay === 'prepaid' ? 'QR / UPI' : ($pay === 'cod' ? 'Cash on Delivery (COD)' : 'QR / UPI + COD');
        $a1 = (string)($c['addr1'] ?? ''); $a2 = (string)($c['addr2'] ?? ''); $lm = (string)($c['landmark'] ?? '');
        $street = implode(', ', array_filter([$a1, $a2], fn($x) => $x !== ''));
        $addr2 = implode(', ', array_filter([$a2, $lm], fn($x) => $x !== ''));
        $phone = (string)(($c['phone'] ?? '') !== '' ? $c['phone'] : ($r['phone'] ?? ''));
        foreach ((array)($r['lines'] ?? []) as $i => $l) {
            $o = array_fill_keys(dpx_cols($n8n), '');
            $o['Name'] = (string)$r['order_no'];
            $o['Lineitem quantity'] = $l['qty']; $o['Lineitem name'] = (string)$l['title'] . (((string)($l['label'] ?? '')) !== '' ? ' - ' . $l['label'] : ''); $o['Lineitem price'] = $l['unit']; $o['Lineitem sku'] = $l['sku'] ?? '';
            $o['Lineitem requires shipping'] = 'true'; $o['Lineitem taxable'] = 'true'; $o['Lineitem fulfillment status'] = 'pending';
            if ($i === 0) {
                $tags = implode(', ', array_filter(['wa-manual', (string)($r['type'] ?? ''), $pay === 'cod' ? 'cod' : 'prepaid'], fn($x) => $x !== ''));
                $inv = $r['invoice'] ?? null; $par = $r['parcel'] ?? null;
                $o = array_merge($o, [
                    'Email' => $c['email'] ?? '', 'Financial Status' => $fin, 'Paid at' => ($pay !== 'cod' && $st === 'confirmed') ? $iso(($r['confirmed_at'] ?? '') !== '' ? $r['confirmed_at'] : ($r['created_at'] ?? '')) : '',
                    'Fulfillment Status' => 'unfulfilled', 'Accepts Marketing' => 'no', 'Currency' => 'INR', 'Subtotal' => $r['total'], 'Shipping' => 0, 'Taxes' => 0, 'Total' => $r['total'], 'Shipping Method' => 'Manual', 'Created at' => $iso($r['created_at'] ?? ''),
                    'Billing Name' => $c['name'] ?? '', 'Billing Street' => $street, 'Billing Address1' => $a1, 'Billing Address2' => $addr2, 'Billing City' => $c['city'] ?? '', 'Billing Zip' => $c['pin'] ?? '', 'Billing Province' => $c['state'] ?? '', 'Billing Country' => 'IN', 'Billing Phone' => $phone,
                    'Shipping Name' => $c['name'] ?? '', 'Shipping Street' => $street, 'Shipping Address1' => $a1, 'Shipping Address2' => $addr2, 'Shipping City' => $c['city'] ?? '', 'Shipping Zip' => $c['pin'] ?? '', 'Shipping Province' => $c['state'] ?? '', 'Shipping Country' => 'IN', 'Shipping Phone' => $phone,
                    'Notes' => $addons ? (string)($r['note'] ?? '') : dpx_note_with_addon($r['note'] ?? '', $r['order_no'] ?? ''), 'Cancelled at' => $st === 'cancelled' ? $iso($r['created_at'] ?? '') : '', 'Payment Method' => $pm, 'Payment Reference' => $r['utr'] ?? '', 'Outstanding Balance' => $r['cod'], 'Employee' => $r['emp'] ?? '',
                    'Id' => 7000000000000000 + (int)$r['id'], 'Tags' => $tags, 'Source' => 'manual', 'Phone' => $phone, 'Order Source (Query/Campaign)' => $r['type'] ?? '', 'Order Status' => $st,
                    'Invoice No' => is_array($inv) ? ($inv['no'] ?? '') : '', 'Courier' => is_array($par) ? ($par['courier'] ?? '') : '', 'AWB' => is_array($par) ? ($par['awb'] ?? '') : '', 'Shiprocket Order Id' => $r['sr']['id'] ?? '']);
                if ($n8n) { $o['Billing Province Name'] = $c['state'] ?? ''; $o['Shipping Province Name'] = $c['state'] ?? ''; if (isset($r['tax']) && is_numeric($r['tax'])) $o['Taxes'] = $r['tax']; }
            }
            $out[] = $o;
        }
        $__ad = $addons[strtolower(trim(ltrim((string)($r['order_no'] ?? ''), '#')))] ?? null;      // ➕ add-ons of this order (only the n8n feed passes them)
        if ($__ad && $out && count($out) > $__start) { $out[$__start]['Total'] = round((float)$out[$__start]['Total'] + $__ad['total'], 2); $out[$__start]['Outstanding Balance'] = round((float)$out[$__start]['Outstanding Balance'] + $__ad['cod'], 2); foreach (dpx_addon_lines((string)$r['order_no'], $__ad, array_fill_keys(dpx_cols($n8n), '')) as $x) $out[] = $x; }
    }
    return $out;
}

/** The CSV text (no byte-order mark: the n8n "Extract from File" node would read the first column as "﻿Name"). */
function dpx_csv(array $rows, bool $n8n = false): string
{
    $q = function ($v) { $s = (string)($v === null ? '' : $v); return preg_match('/[",\n\r]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s; };
    $cols = dpx_cols($n8n); $lines = [implode(',', array_map($q, $cols))];
    foreach ($rows as $o) { $l = []; foreach ($cols as $k) $l[] = $q($o[$k] ?? ''); $lines[] = implode(',', $l); }
    return implode("\r\n", $lines);
}

function dpx_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS dpx_sent (order_no VARCHAR(40) NOT NULL PRIMARY KEY, sent_at DATETIME NOT NULL, http_code INT NOT NULL DEFAULT 0, mode VARCHAR(8) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

function dpx_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'dpx_cfg', ''), true); if (!is_array($j)) $j = [];
    $t = (string)($j['time'] ?? '18:00'); if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) $t = '18:00';
    return ['on' => !empty($j['on']), 'url' => trim((string)($j['url'] ?? '')), 'header' => trim((string)($j['header'] ?? '')) !== '' ? trim((string)$j['header']) : 'X-HRMS-Token', 'token' => (string)($j['token'] ?? ''), 'time' => $t];
}

/** '' when the address is fine, else why not. https only, and never an address inside our own network. */
function dpx_url_problem(string $u): string
{
    if ($u === '') return 'Paste the n8n webhook address first.';
    $p = parse_url($u); if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) return 'The address must start with https:// .';
    $h = (string)$p['host']; $ip = filter_var($h, FILTER_VALIDATE_IP) ? $h : gethostbyname($h);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'That address does not lead to a public server.';
    return '';
}

function dpx_log_add(PDO $pdo, array $e): void
{
    try { $l = json_decode((string)setting_get($pdo, 'dpx_log', ''), true); if (!is_array($l)) $l = []; array_unshift($l, $e + ['at' => date('Y-m-d H:i:s')]); setting_set($pdo, 'dpx_log', json_encode(array_slice($l, 0, 40), JSON_UNESCAPED_UNICODE)); } catch (Throwable $x) { }
}

/** POST the CSV to the webhook as a multipart file in the field "file" (what the n8n workflow's "Extract from File" node reads). Returns [http code, body, curl error]. */
function dpx_post(string $url, string $header, string $token, string $csv, string $name): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'dpx'); file_put_contents($tmp, $csv);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $token !== '' ? [$header . ': ' . $token] : [], CURLOPT_POSTFIELDS => ['file' => curl_file_create($tmp, 'text/csv', $name)]]);
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); @unlink($tmp);
    return [$code, $body, $err];
}

/** One send. $mode: 'auto' (the timer), 'now' (the button: everything not sent yet), 'test' (one order only). Returns ['ok', 'orders', 'lines', 'http', 'msg']. */
function dpx_run(PDO $pdo, string $mode): array
{
    dpx_addon_load($pdo);
    $cfg = dpx_cfg($pdo); $res = ['ok' => false, 'orders' => 0, 'lines' => 0, 'http' => 0, 'msg' => '', 'mode' => $mode];
    if (!dpx_tables($pdo)) { $res['msg'] = 'The sent-orders table could not be made.'; return $res; }
    $bad = dpx_url_problem($cfg['url']); if ($bad !== '') { $res['msg'] = $bad; return $res; }
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK('hrms_dpx', 0)")->fetchColumn(); } catch (Throwable $e) { }
    if ($got !== 1) { $res['msg'] = 'A send is already running.'; return $res; }
    try {
        if (!function_exists('wmo_dsp_rows') && is_file(__DIR__ . '/wmo.php')) require_once __DIR__ . '/wmo.php';
        if (!function_exists('wmo_dsp_rows')) { $res['msg'] = 'The manual orders module is not available.'; return $res; }
        $today = biz_today($pdo); $from = date('Y-m-d', strtotime($today . ' -1 day')); $tot = 0; $sum = [];
        $orders = wmo_dsp_rows($pdo, $from, $today, 'confirmed', '', '', 5000, 0, $tot, $sum);      // the business day and the one before it: an order made after the last run is sent by the next one
        $sent = []; if ($orders) { $nos = array_map(fn($o) => (string)$o['order_no'], $orders); $in = implode(',', array_fill(0, count($nos), '?')); $q = $pdo->prepare("SELECT order_no FROM dpx_sent WHERE order_no IN ($in)"); $q->execute($nos); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n) $sent[(string)$n] = 1; }
        $todo = array_values(array_filter($orders, fn($o) => empty($sent[(string)$o['order_no']])));
        $todo = array_values(array_filter($todo, fn($o) => !empty($o['lines'])));
        if ($mode === 'test') $todo = array_slice($todo, 0, 1);
        if (!$todo) { $res['ok'] = true; $res['msg'] = 'Nothing new to send.'; return $res; }
        $rows = dpx_sheet_rows($todo, true); $csv = dpx_csv($rows, true); $res['orders'] = count($todo); $res['lines'] = count($rows);
        [$code, $body, $err] = dpx_post($cfg['url'], $cfg['header'], $cfg['token'], $csv, 'hrms_orders_' . $today . '.csv');
        $res['http'] = $code; $res['msg'] = $err !== '' ? 'No answer: ' . mb_substr($err, 0, 120) : mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 140);
        if ($code >= 200 && $code < 300) {
            $res['ok'] = true; $ins = $pdo->prepare("INSERT INTO dpx_sent (order_no, sent_at, http_code, mode) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at), http_code = VALUES(http_code), mode = VALUES(mode)");
            foreach ($todo as $o) $ins->execute([(string)$o['order_no'], date('Y-m-d H:i:s'), $code, substr($mode, 0, 8)]);
        } elseif ($res['msg'] === '') $res['msg'] = 'The webhook answered ' . $code . '.';
        return $res;
    } catch (Throwable $e) { $res['msg'] = 'Error: ' . mb_substr($e->getMessage(), 0, 140); return $res; }
    finally { try { $pdo->query("SELECT RELEASE_LOCK('hrms_dpx')"); } catch (Throwable $e) { } }
}

/** Called every 5 minutes. From the chosen time on, once a day (retried every 15 minutes, up to 6 times, until the webhook says yes). */
function dpx_tick(PDO $pdo): void
{
    try {
        $cfg = dpx_cfg($pdo); if (!$cfg['on'] || $cfg['url'] === '') return;
        $now = time(); $day = date('Y-m-d', $now); if (date('H:i', $now) < $cfg['time']) return;
        $st = json_decode((string)setting_get($pdo, 'dpx_state', ''), true); if (!is_array($st)) $st = [];
        if (($st['done'] ?? '') === $day) return;
        if (($st['day'] ?? '') === $day) { if ((int)($st['tries'] ?? 0) >= 6 || $now - (int)($st['last'] ?? 0) < 900) return; } else $st = ['day' => $day, 'tries' => 0, 'last' => 0];
        $st['tries'] = (int)$st['tries'] + 1; $st['last'] = $now; setting_set($pdo, 'dpx_state', json_encode($st));
        $r = dpx_run($pdo, 'auto'); dpx_log_add($pdo, $r);
        if (!empty($r['ok'])) { $st['done'] = $day; setting_set($pdo, 'dpx_state', json_encode($st)); }
    } catch (Throwable $e) { }
}

/** For n8n to PULL (no login; the API key of the HRMS, in the header X-API-Key or ?key=): the confirmed manual orders as the n8n CSV. Default = yesterday and today (business days), so an order made after the last run is
 *  taken by the next one. Nothing is marked as sent here: the n8n insert skips an order that is already in the dispatch table (its unique key), so asking twice is harmless. */
function dpx_feed(PDO $pdo): void
{
    dpx_addon_load($pdo);
    $key = (string)($_SERVER['HTTP_X_API_KEY'] ?? ''); if ($key === '') $key = (string)($_GET['key'] ?? '');
    $okKey = false;      // the key made on the HRMS screen (only its hash is kept), or the API_KEY of config.php
    if ($key !== '') { $h = (string)setting_get($pdo, 'dpx_feed_key', ''); if ($h !== '' && hash_equals($h, hash('sha256', $key))) $okKey = true; }
    if (!$okKey && $key !== '' && defined('API_KEY') && (string)API_KEY !== '' && hash_equals((string)API_KEY, $key)) $okKey = true;
    if (!$okKey) json_response(['error' => 'Forbidden.'], 403);
    @set_time_limit(120);
    if (!function_exists('wmo_dsp_rows') && is_file(__DIR__ . '/wmo.php')) require_once __DIR__ . '/wmo.php';
    if (!function_exists('wmo_dsp_rows')) json_response(['error' => 'The manual orders module is not available.'], 500);
    $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    $to = $d($_GET['to'] ?? '') ?: biz_today($pdo); $from = $d($_GET['from'] ?? '') ?: date('Y-m-d', strtotime($to . ' -1 day')); if ($from > $to) $from = $to;
    if ((strtotime($to) - strtotime($from)) > 31 * 86400) json_response(['error' => 'Ask for at most 31 days.'], 422);
    $tot = 0; $sum = []; $orders = wmo_dsp_rows($pdo, $from, $to, 'confirmed', '', '', 5000, 0, $tot, $sum);
    $orders = array_values(array_filter($orders, fn($o) => !empty($o['lines'])));
    // ➕ an add-on put on an OLDER manual order must still reach dispatch
    $have = []; foreach ($orders as $o0) $have[strtolower((string)$o0['order_no'])] = 1;
    foreach (array_slice(array_filter(dpx_addon_names_in($pdo, $from, $to), fn($n) => stripos($n, 'WM-') === 0 && !isset($have[strtolower($n)])), 0, 40) as $nm) {
        $t1 = 0; $s1 = []; foreach (wmo_dsp_rows($pdo, '2020-01-01', $to, 'confirmed', '', $nm, 5, 0, $t1, $s1) as $x) if (strcasecmp((string)$x['order_no'], $nm) === 0 && !empty($x['lines'])) { $orders[] = $x; $have[strtolower($nm)] = 1; }
    }
    $addons = dpx_addons_map($pdo, array_map(fn($o0) => (string)$o0['order_no'], $orders));
    $rows = dpx_sheet_rows($orders, true, $addons);
    // 🛍 &web=1 (owner, 24 Nov): the WEBSITE orders (the new TM Perfume House shop, store_orders.php) of the same days are added to the same file, in the same columns, so the same n8n workflow sends them to dispatch.
    //    Without web=1 the file is exactly what it was.
    if (!empty($_GET['web'])) { try { foreach (dpx_web_rows($pdo, $from, $to) as $wr) $rows[] = $wr; } catch (Throwable $e) { error_log('dpx web rows: ' . $e->getMessage()); json_response(['error' => 'The website orders could not be read: ' . $e->getMessage()], 502); } }
    $csv = dpx_csv($rows, true);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="hrms_orders_' . $to . '.csv"'); header('Cache-Control: no-store');
    echo $csv; exit;
}

/** ➕ Add-ons put on orders in the HRMS (table employee_addons): [normalised order name => ['groups' => [[items, price, pre, cod]], 'total', 'cod']]. */
function dpx_addons_map(PDO $pdo, array $names): array
{
    $norm = fn($n) => strtolower(trim(ltrim(trim((string)$n), '#'))); $keys = []; foreach ($names as $n) { $k = $norm($n); if ($k !== '') $keys[$k] = 1; }
    $by = []; if (!$keys || !function_exists('ensure_addons_table')) return [];
    try {
        if (!ensure_addons_table($pdo)) return [];
        foreach (array_chunk(array_keys($keys), 400) as $chunk) {
            $ph = []; $par = []; foreach ($chunk as $k) { $ph[] = '?'; $par[] = $k; $ph[] = '?'; $par[] = '#' . $k; }
            $st = $pdo->prepare("SELECT addon_id, order_name, addon_group, product_name, size_ml, qty, price, prepaid_amt, cod_amt FROM employee_addons WHERE LOWER(order_name) IN (" . implode(',', $ph) . ") ORDER BY addon_id"); $st->execute($par);
            foreach ($st->fetchAll() as $a) {
                $o = $norm($a['order_name']); $g = (string)($a['addon_group'] ?: 'single-' . $a['addon_id']);
                if (!isset($by[$o]['groups'][$g])) $by[$o]['groups'][$g] = ['items' => [], 'price' => 0.0, 'pre' => 0.0, 'cod' => 0.0];
                $by[$o]['groups'][$g]['items'][] = ['product' => (string)$a['product_name'], 'size' => (string)$a['size_ml'], 'qty' => max(1, (int)$a['qty'])];
                $by[$o]['groups'][$g]['price'] += (float)$a['price']; $by[$o]['groups'][$g]['pre'] += (float)$a['prepaid_amt']; $by[$o]['groups'][$g]['cod'] += (float)$a['cod_amt'];
            }
        }
    } catch (Throwable $e) { return []; }
    foreach ($by as $o => &$v) { $v['groups'] = array_values($v['groups']); $v['total'] = 0.0; $v['cod'] = 0.0; foreach ($v['groups'] as $g) { $v['total'] += $g['price']; $v['cod'] += $g['cod']; } } unset($v);
    return $by;
}

/** The order names that got an add-on in a day range (the add-on may be put on an order of an earlier day: it must still reach dispatch). */
function dpx_addon_names_in(PDO $pdo, string $from, string $to): array
{
    try {
        if (!function_exists('ensure_addons_table') || !ensure_addons_table($pdo)) return [];
        $st = $pdo->prepare("SELECT DISTINCT order_name FROM employee_addons WHERE order_name <> '' AND (addon_date BETWEEN ? AND ? OR (created_at >= ? AND created_at < ?))");
        $st->execute([$from, $to, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** The rows of the add-ons of ONE order: every product is its own line "➕ ADD-ON · product", the add-on price is on its first product (the line price is per piece). */
function dpx_addon_lines(string $orderNo, array $add, array $blank): array
{
    $out = [];
    foreach ((array)($add['groups'] ?? []) as $g) {
        foreach ((array)$g['items'] as $i => $it) {
            $r = $blank; $r['Name'] = $orderNo; $q = max(1, (int)$it['qty']);
            $r['Lineitem name'] = '➕ ADD-ON · ' . trim($it['product'] . ($it['size'] !== '' ? ' ' . $it['size'] . (stripos($it['size'], 'ml') === false ? ' ml' : '') : ''));
            $r['Lineitem quantity'] = $q; $r['Lineitem price'] = $i === 0 ? number_format($g['price'] / $q, 2, '.', '') : '0.00'; $r['Lineitem requires shipping'] = 'true'; $r['Lineitem taxable'] = 'true';
            $out[] = $r;
        }
    }
    return $out;
}

/** 🛍 The SHOPIFY orders of a day range, straight from Shopify (like the SKU sheet reads them): [orders, errors]. Cancelled orders are left out. */
function dpx_shop_fetch(PDO $pdo, string $from, string $to): array
{
    $out = []; $errs = []; $win = biz_window($pdo, $from, $to);
    $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
    foreach (shopify_stores() as $key => $store) {
        if (strpos((string)($store['client_id'] ?? ''), 'PASTE_') !== false) continue;
        $tok = shopify_get_token($key, $store); if (is_array($tok)) { $errs[] = ($store['label'] ?? $key) . ': ' . ($tok['error'] ?? 'no access'); continue; }
        $url = "https://{$store['domain']}/admin/api/2024-10/orders.json?" . http_build_query(['status' => 'any', 'limit' => 250, 'created_at_min' => str_replace(' ', 'T', $win[0]) . '+05:30', 'created_at_max' => str_replace(' ', 'T', $win[1]) . '+05:30',
            'fields' => 'id,name,created_at,cancelled_at,email,phone,financial_status,fulfillment_status,total_price,subtotal_price,total_tax,total_discounts,total_outstanding,currency,discount_codes,shipping_lines,note,note_attributes,tags,payment_gateway_names,billing_address,shipping_address,line_items,customer,source_name']);
        for ($page = 0; $page < 40 && $url; $page++) {
            $hdr = []; $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $tok, 'Content-Type: application/json'], CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { $hdr[] = $h; return strlen($h); }]);
            $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code === 429) { usleep(1200000); $page--; static $retry = 0; if (++$retry > 8) { $errs[] = 'Shopify is busy (429)'; break; } continue; }
            if ($resp === false || $code !== 200) { $errs[] = ($store['label'] ?? $key) . ': Shopify answered HTTP ' . $code; break; }
            foreach ((array)(json_decode((string)$resp, true)['orders'] ?? []) as $o) {
                if (!empty($o['cancelled_at']) || isset($cancel[$key . '|' . (string)($o['id'] ?? '')])) continue;
                $o['_store'] = $key; $out[] = $o;
            }
            $url = ''; foreach ($hdr as $h) if (stripos($h, 'link:') === 0 && preg_match('/<([^>]+)>;\s*rel="next"/i', $h, $m)) $url = $m[1];
        }
    }
    return [$out, $errs];
}

/** One Shopify order by its name (#71674): [orders, errors]. */
function dpx_shop_fetch_name(PDO $pdo, string $name): array
{
    $out = []; $errs = [];
    foreach (shopify_stores() as $key => $store) {
        if (strpos((string)($store['client_id'] ?? ''), 'PASTE_') !== false) continue;
        $tok = shopify_get_token($key, $store); if (is_array($tok)) { $errs[] = (string)($tok['error'] ?? 'no access'); continue; }
        $url = "https://{$store['domain']}/admin/api/2024-10/orders.json?" . http_build_query(['status' => 'any', 'name' => $name, 'limit' => 5,
            'fields' => 'id,name,created_at,cancelled_at,email,phone,financial_status,fulfillment_status,total_price,subtotal_price,total_tax,total_discounts,total_outstanding,currency,discount_codes,shipping_lines,note,note_attributes,tags,payment_gateway_names,billing_address,shipping_address,line_items,customer,source_name']);
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $tok]]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($resp === false || $code !== 200) { $errs[] = 'HTTP ' . $code; continue; }
        foreach ((array)(json_decode((string)$resp, true)['orders'] ?? []) as $o) { if (!empty($o['cancelled_at']) || strcasecmp((string)($o['name'] ?? ''), $name) !== 0) continue; $o['_store'] = $key; $out[] = $o; }
    }
    return [$out, $errs];
}

/** Shopify orders → rows in the Shopify EXPORT format (one row per product, the order's own columns on its first row) — what the n8n "Shopify Order Processor" code reads. */
function dpx_shop_rows(array $orders, array $addons = []): array
{
    $cols = dpx_cols(true); $out = [];
    $ist = function ($s) { try { $d = new DateTime((string)$s); $d->setTimezone(new DateTimeZone('Asia/Kolkata')); return $d->format('Y-m-d H:i:s') . ' +0530'; } catch (Throwable $e) { return (string)$s; } };
    foreach ($orders as $o) {
        $sh = (array)($o['shipping_address'] ?? []); $bl = (array)($o['billing_address'] ?? []); if (!$sh) $sh = $bl; if (!$bl) $bl = $sh;
        $na = []; foreach ((array)($o['note_attributes'] ?? []) as $a) if (is_array($a) && (string)($a['name'] ?? '') !== '') $na[] = $a['name'] . ': ' . (is_scalar($a['value'] ?? '') ? $a['value'] : json_encode($a['value']));
        $phone = (string)(($bl['phone'] ?? '') ?: ($sh['phone'] ?? '') ?: ($o['phone'] ?? '') ?: ($o['customer']['phone'] ?? ''));
        $first = true; $__start = count($out);
        foreach ((array)($o['line_items'] ?? []) as $li) {
            $q = (int)($li['quantity'] ?? 0); if (isset($li['current_quantity'])) $q = min($q, max(0, (int)$li['current_quantity'])); if ($q <= 0) continue;
            $r = array_fill_keys($cols, ''); $r['Name'] = (string)($o['name'] ?? '');
            $r['Lineitem quantity'] = $q; $r['Lineitem name'] = (string)($li['name'] ?? $li['title'] ?? ''); $r['Lineitem price'] = (string)($li['price'] ?? '0'); $r['Lineitem sku'] = (string)($li['sku'] ?? '');
            $r['Lineitem requires shipping'] = !empty($li['requires_shipping']) ? 'true' : 'false'; $r['Lineitem taxable'] = !empty($li['taxable']) ? 'true' : 'false';
            $pr = []; $partial = '';
            foreach ((array)($li['properties'] ?? []) as $p) { if (!is_array($p) || (string)($p['name'] ?? '') === '') continue; $v = is_scalar($p['value'] ?? '') ? (string)$p['value'] : json_encode($p['value']); $pr[] = $p['name'] . ': ' . $v; if ($p['name'] === '_partialPaymentProduct' && strtolower($v) === 'true') $partial = 'true'; }
            $r['Lineitem properties'] = implode("\n", $pr); $r['_partialPaymentProduct'] = $partial;
            if ($first) {
                $first = false;
                $r = array_merge($r, [
                    'Email' => (string)($o['email'] ?? ''), 'Financial Status' => (string)($o['financial_status'] ?? ''), 'Fulfillment Status' => (string)(($o['fulfillment_status'] ?? '') ?: 'unfulfilled'), 'Currency' => (string)($o['currency'] ?? 'INR'),
                    'Subtotal' => (string)($o['subtotal_price'] ?? ''), 'Shipping' => (string)($o['shipping_lines'][0]['price'] ?? '0'), 'Taxes' => (string)($o['total_tax'] ?? '0'), 'Total' => (string)($o['total_price'] ?? ''),
                    'Discount Code' => (string)($o['discount_codes'][0]['code'] ?? ''), 'Discount Amount' => (string)($o['total_discounts'] ?? '0'), 'Shipping Method' => (string)($o['shipping_lines'][0]['title'] ?? ''), 'Created at' => $ist($o['created_at'] ?? ''),
                    'Billing Name' => (string)($bl['name'] ?? ''), 'Billing Street' => trim((string)($bl['address1'] ?? '') . ' ' . (string)($bl['address2'] ?? '')), 'Billing Address1' => (string)($bl['address1'] ?? ''), 'Billing Address2' => (string)($bl['address2'] ?? ''), 'Billing Company' => (string)($bl['company'] ?? ''),
                    'Billing City' => (string)($bl['city'] ?? ''), 'Billing Zip' => (string)($bl['zip'] ?? ''), 'Billing Province' => (string)($bl['province_code'] ?? ''), 'Billing Province Name' => (string)($bl['province'] ?? ''), 'Billing Country' => (string)($bl['country_code'] ?? ''), 'Billing Phone' => (string)($bl['phone'] ?? $phone),
                    'Shipping Name' => (string)($sh['name'] ?? ''), 'Shipping Street' => trim((string)($sh['address1'] ?? '') . ' ' . (string)($sh['address2'] ?? '')), 'Shipping Address1' => (string)($sh['address1'] ?? ''), 'Shipping Address2' => (string)($sh['address2'] ?? ''), 'Shipping Company' => (string)($sh['company'] ?? ''),
                    'Shipping City' => (string)($sh['city'] ?? ''), 'Shipping Zip' => (string)($sh['zip'] ?? ''), 'Shipping Province' => (string)($sh['province_code'] ?? ''), 'Shipping Province Name' => (string)($sh['province'] ?? ''), 'Shipping Country' => (string)($sh['country_code'] ?? ''), 'Shipping Phone' => (string)($sh['phone'] ?? ''),
                    'Notes' => $addons ? (string)($o['note'] ?? '') : dpx_note_with_addon((string)($o['note'] ?? ''), $o['name'] ?? ''), 'Note Attributes' => implode("\n", $na), 'Payment Method' => implode(', ', (array)($o['payment_gateway_names'] ?? [])), 'Outstanding Balance' => (string)($o['total_outstanding'] ?? '0'),
                    'Id' => (string)($o['id'] ?? ''), 'Tags' => (string)($o['tags'] ?? ''), 'Source' => (string)($o['source_name'] ?? ''), 'Phone' => $phone]);
            }
            $out[] = $r;
        }
        $__ad = $addons[strtolower(trim(ltrim((string)($o['name'] ?? ''), '#')))] ?? null;      // ➕ add-ons of this order
        if ($__ad && count($out) > $__start) { $out[$__start]['Total'] = round((float)$out[$__start]['Total'] + $__ad['total'], 2); $out[$__start]['Outstanding Balance'] = round((float)$out[$__start]['Outstanding Balance'] + $__ad['cod'], 2); foreach (dpx_addon_lines((string)($o['name'] ?? ''), $__ad, array_fill_keys($cols, '')) as $x) $out[] = $x; }
    }
    return $out;
}

/** 🛍 The website orders (new shop API) placed in [from, to], as rows of the n8n file. Only orders that can be packed: paid / part-paid, or cash on delivery; a prepaid order still waiting for its payment is left out.
 *  Throws when the shop cannot be read, so n8n shows the run as failed instead of silently sending a file without them. */
function dpx_web_rows(PDO $pdo, string $from, string $to): array
{
    if (!function_exists('tms_orders') && is_file(__DIR__ . '/store_orders.php')) { $action = ''; require_once __DIR__ . '/store_orders.php'; }
    if (!function_exists('tms_orders')) throw new RuntimeException('the Website orders module is not installed');
    [$list, $err] = tms_orders($pdo, $from, $to); if ($err !== '') throw new RuntimeException($err);
    $orders = [];
    foreach ($list as $x) {
        $cod = (bool)preg_match('/cod|cash/i', (string)$x['pay_method']);
        if ((string)$x['pay_status'] === 'pending' && !$cod) continue;      // the payment link is not paid yet
        if (preg_match('/cancel/i', (string)$x['ship_status'])) continue;
        $a = (array)($x['addr'] ?? []); $mo = (array)($x['money'] ?? []);
        $adr = ['name' => (string)($a['name'] ?? $x['customer']), 'address1' => (string)($a['address1'] ?? ''), 'address2' => (string)($a['address2'] ?? ''), 'city' => (string)($a['city'] ?? ''), 'zip' => (string)($a['pincode'] ?? ''),
            'province' => (string)($a['state'] ?? ''), 'province_code' => (string)($a['state'] ?? ''), 'country_code' => 'IN', 'phone' => (string)(($a['phone'] ?? '') ?: $x['phone'])];
        $li = []; foreach ((array)($x['lines'] ?? []) as $l) $li[] = ['quantity' => (int)$l['qty'], 'name' => trim((string)$l['title'] . ((string)$l['size'] !== '' ? ' - ' . (string)$l['size'] : '')), 'price' => (string)$l['price'], 'sku' => (string)$l['sku'], 'requires_shipping' => true, 'taxable' => false];
        if (!$li) continue;
        $t = (string)(($x['pay_time'] ?? '') ?: $x['placed_at']);
        $orders[] = ['name' => (string)$x['number'], 'id' => (string)$x['number'], 'created_at' => $t !== '' ? $t . '+05:30' : '', 'financial_status' => (string)$x['pay_status'], 'fulfillment_status' => (string)$x['ship_status'], 'currency' => 'INR',
            'subtotal_price' => (string)($mo['subtotal'] ?? ''), 'total_price' => (string)$x['total'], 'total_discounts' => (string)($mo['discount'] ?? '0'), 'discount_codes' => ((string)($mo['code'] ?? '') !== '' ? [['code' => (string)$mo['code']]] : []),
            'shipping_lines' => [['price' => (string)round((float)($mo['shipping'] ?? 0) + (float)($mo['cod_fee'] ?? 0), 2), 'title' => (string)($x['courier'] ?? '')]], 'shipping_address' => $adr, 'billing_address' => $adr, 'note' => (string)($x['note'] ?? ''),
            'payment_gateway_names' => [(string)$x['pay_method']], 'total_outstanding' => (string)$x['due'], 'tags' => 'TM Store', 'source_name' => 'TM Store', 'phone' => (string)$x['phone'], 'line_items' => $li];
    }
    return dpx_shop_rows($orders, []);
}

/** GET api.php?action=dpx.chain_info&n[]=Lunelle%20Chain&n[]=… — the photo and the shop price of chain (jewellery) products, by the name written on the order. For the Dispatch page (owner, 26 Nov:
 *  "chain with price and photo", also for orders already in the dispatch database) — it asks here when an order is opened, so nothing is stored there and n8n does not change.
 *  No key: the answer is only what the shop's own website shows (a product photo and its price). Only jewellery names are looked up, at most 20 a call; each answer is kept 12 hours. */
function dpx_chain_info(PDO $pdo): void
{
    $names = $_GET['n'] ?? []; if (!is_array($names)) $names = [$names];
    if (!function_exists('sku_chain_info') && is_file(__DIR__ . '/sku_sheet.php')) require_once __DIR__ . '/sku_sheet.php';
    if (!function_exists('sku_chain_info')) json_response(['items' => (object)[]]);
    $rx = '/chain|necklace|bracelet|pendant|signet|earring|anklet|bangle|(?<![a-z0-9])(weave|solenne|veyra|lunelle|velora|lunara|elysian|seraphine|aurelia|herring ?bone|vanta ?crest|apex ?monolith)(?![a-z0-9])/i';
    $by = []; $orig = [];
    foreach (array_slice($names, 0, 20) as $n) { if (!is_string($n)) continue; $n = trim(mb_substr($n, 0, 120)); if ($n === '' || !preg_match($rx, $n)) continue; $k = mb_strtolower($n); $by[$k] = ['title' => $n, 'pid' => '', 'store' => '']; $orig[$k] = $n; }
    $out = [];
    if ($by) {
        try {
            $info = sku_chain_info($pdo, $by);
            // "Lunelle Chain - Silver / 20 inch": when the whole name is not a product title, the part before " - " is tried
            $again = []; foreach ($by as $k => $r) if (empty($info[$k]['img']) && empty($info[$k]['price']) && preg_match('/^(.+?)\s+[-–—]\s+.+$/u', $r['title'], $m)) $again[$k] = ['title' => trim($m[1]), 'pid' => '', 'store' => ''];
            if ($again) foreach (sku_chain_info($pdo, $again) as $k => $v) $info[$k] = $v;
            foreach ($info as $k => $v) if (!empty($v['img']) || !empty($v['price'])) $out[$orig[$k]] = ['img' => (string)($v['img'] ?? ''), 'price' => (float)($v['price'] ?? 0)];
        } catch (Throwable $e) { error_log('dpx chain info: ' . $e->getMessage()); }
    }
    header('Cache-Control: public, max-age=600');
    json_response(['items' => (object)$out]);
}

/** GET api.php?action=dpx.feed_web&from=&to= — ONLY the website orders (the new TM Perfume House shop), as the same CSV the n8n workflow of the manual orders reads (same key as dpx.feed).
 *  Default: yesterday + today. For a NEW n8n workflow that sends the website orders to dispatch. */
function dpx_feed_web(PDO $pdo): void
{
    $key = (string)($_SERVER['HTTP_X_API_KEY'] ?? ''); if ($key === '') $key = (string)($_GET['key'] ?? '');
    $okKey = false;
    if ($key !== '') { $h = (string)setting_get($pdo, 'dpx_feed_key', ''); if ($h !== '' && hash_equals($h, hash('sha256', $key))) $okKey = true; }
    if (!$okKey && $key !== '' && defined('API_KEY') && (string)API_KEY !== '' && hash_equals((string)API_KEY, $key)) $okKey = true;
    if (!$okKey) json_response(['error' => 'Forbidden.'], 403);
    @set_time_limit(120);
    $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    $to = $d($_GET['to'] ?? '') ?: date('Y-m-d'); $from = $d($_GET['from'] ?? '') ?: date('Y-m-d', strtotime($to . ' -1 day')); if ($from > $to) $from = $to;
    if ((strtotime($to) - strtotime($from)) > 31 * 86400) json_response(['error' => 'Ask for at most 31 days.'], 422);
    try { $rows = dpx_web_rows($pdo, $from, $to); } catch (Throwable $e) { json_response(['error' => 'The website orders could not be read: ' . $e->getMessage()], 502); }      // never an empty file when the shop could not be read: n8n shows the run as failed
    $csv = dpx_csv($rows, true);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="website_orders_' . $to . '.csv"'); header('Cache-Control: no-store');
    echo $csv; exit;
}

/** GET api.php?action=dpx.feed_shop&from=&to= — the Shopify orders as a Shopify-export CSV for n8n (same key as dpx.feed). Default: yesterday + today of the business day. */
function dpx_feed_shop(PDO $pdo): void
{
    dpx_addon_load($pdo);
    $key = (string)($_SERVER['HTTP_X_API_KEY'] ?? ''); if ($key === '') $key = (string)($_GET['key'] ?? '');
    $okKey = false;
    if ($key !== '') { $h = (string)setting_get($pdo, 'dpx_feed_key', ''); if ($h !== '' && hash_equals($h, hash('sha256', $key))) $okKey = true; }
    if (!$okKey && $key !== '' && defined('API_KEY') && (string)API_KEY !== '' && hash_equals((string)API_KEY, $key)) $okKey = true;
    if (!$okKey) json_response(['error' => 'Forbidden.'], 403);
    @set_time_limit(120); ignore_user_abort(true);
    $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    $to = $d($_GET['to'] ?? '') ?: biz_today($pdo); $from = $d($_GET['from'] ?? '') ?: date('Y-m-d', strtotime($to . ' -1 day')); if ($from > $to) $from = $to;
    if ((strtotime($to) - strtotime($from)) > 31 * 86400) json_response(['error' => 'Ask for at most 31 days.'], 422);
    [$orders, $errs] = dpx_shop_fetch($pdo, $from, $to);
    // ➕ an add-on put on an OLDER order must still reach dispatch: that order is read again too (its other lines are skipped there as duplicates)
    $have = []; foreach ($orders as $o0) $have[strtolower(ltrim((string)($o0['name'] ?? ''), '#'))] = 1;
    foreach (array_slice(array_filter(dpx_addon_names_in($pdo, $from, $to), fn($n) => $n !== '' && $n[0] === '#' && !isset($have[strtolower(ltrim($n, '#'))])), 0, 40) as $nm) { [$o1] = dpx_shop_fetch_name($pdo, $nm); foreach ($o1 as $x) { $orders[] = $x; $have[strtolower(ltrim($nm, '#'))] = 1; } }
    $addons = dpx_addons_map($pdo, array_map(fn($o0) => (string)($o0['name'] ?? ''), $orders));
    if ($errs && !$orders) json_response(['error' => implode('; ', $errs)], 502);      // never send an empty file when Shopify could not be read: n8n shows the run as failed
    $cols = dpx_cols(true); array_splice($cols, array_search('Notes', $cols), 0, ['Lineitem properties', '_partialPaymentProduct']);
    $rows = dpx_shop_rows($orders, $addons); $f = fopen('php://temp', 'r+'); fputcsv($f, $cols); foreach ($rows as $r) { $line = []; foreach ($cols as $c) $line[] = $r[$c] ?? ''; fputcsv($f, $line); } rewind($f); $csv = stream_get_contents($f); fclose($f);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="shopify_orders_' . $to . '.csv"'); header('Cache-Control: no-store');
    echo $csv; exit;
}

function dpx_route(PDO $pdo, string $action): void
{
    $auth = require_auth(); if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin or HR can set up the automatic send.'], 403);
    $in = json_input(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $view = function () use ($pdo) {
        $c = dpx_cfg($pdo); $st = json_decode((string)setting_get($pdo, 'dpx_state', ''), true); $log = json_decode((string)setting_get($pdo, 'dpx_log', ''), true);
        return ['ok' => true, 'cfg' => ['on' => $c['on'], 'url' => $c['url'], 'header' => $c['header'], 'time' => $c['time'], 'has_token' => $c['token'] !== '', 'feed_key_set' => (string)setting_get($pdo, 'dpx_feed_key', '') !== ''], 'state' => is_array($st) ? $st : new stdClass(), 'log' => is_array($log) ? $log : [], 'sent_total' => (function () use ($pdo) { try { dpx_tables($pdo); return (int)$pdo->query("SELECT COUNT(*) FROM dpx_sent")->fetchColumn(); } catch (Throwable $e) { return 0; } })()];
    };
    if ($action === 'dpx.get' || $action === 'dpx.log') json_response($view());
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    if ($action === 'dpx.key_new') {      // a new key for n8n to pull with: shown ONCE (only its hash is kept); an older key stops working
        $k = bin2hex(random_bytes(20)); setting_set($pdo, 'dpx_feed_key', hash('sha256', $k)); json_response(['ok' => true, 'key' => $k] + $view());
    }
    if ($action === 'dpx.save') {
        $c = dpx_cfg($pdo); $url = trim((string)($in['url'] ?? $c['url'])); $on = !empty($in['on']);
        if ($url !== '' || $on) { $bad = dpx_url_problem($url); if ($bad !== '') json_response(['error' => $bad], 422); }
        $hdr = trim((string)($in['header'] ?? $c['header'])); if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', $hdr)) json_response(['error' => 'The header name may only have letters, numbers and dashes.'], 422);
        $time = (string)($in['time'] ?? $c['time']); if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) json_response(['error' => 'The time must look like 18:00.'], 422);
        $tok = array_key_exists('token', $in) && trim((string)$in['token']) !== '' ? trim((string)$in['token']) : $c['token'];      // empty = keep the saved value
        setting_set($pdo, 'dpx_cfg', json_encode(['on' => $on, 'url' => $url, 'header' => $hdr, 'token' => $tok, 'time' => $time], JSON_UNESCAPED_UNICODE));
        json_response($view());
    }
    if ($action === 'dpx.run') {
        $mode = (string)($in['mode'] ?? 'test') === 'now' ? 'now' : 'test'; @set_time_limit(150);
        $r = dpx_run($pdo, $mode); dpx_log_add($pdo, $r); json_response(['ok' => !empty($r['ok']), 'result' => $r] + $view());
    }
    json_response(['error' => 'Unknown action.'], 404);
}

}
