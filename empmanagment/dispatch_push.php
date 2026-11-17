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
 * Actions: dpx.get · dpx.save · dpx.run {mode: test|now} · dpx.log   Called every 5 minutes by dpx_tick() from the orders.cron job. PHP 7.4 safe.
 */
if (!function_exists('dpx_cols')) {

/** $n8n = the file for the n8n workflow: two more columns (the state NAME, as Shopify's export has) after the usual ones. */
function dpx_cols(bool $n8n = false): array
{
    return array_merge(['Name', 'Email', 'Financial Status', 'Paid at', 'Fulfillment Status', 'Fulfilled at', 'Accepts Marketing', 'Currency', 'Subtotal', 'Shipping', 'Taxes', 'Total', 'Discount Code', 'Discount Amount', 'Shipping Method', 'Created at', 'Lineitem quantity', 'Lineitem name', 'Lineitem price', 'Lineitem compare at price', 'Lineitem sku', 'Lineitem requires shipping', 'Lineitem taxable', 'Lineitem fulfillment status',
        'Billing Name', 'Billing Street', 'Billing Address1', 'Billing Address2', 'Billing Company', 'Billing City', 'Billing Zip', 'Billing Province', 'Billing Country', 'Billing Phone', 'Shipping Name', 'Shipping Street', 'Shipping Address1', 'Shipping Address2', 'Shipping Company', 'Shipping City', 'Shipping Zip', 'Shipping Province', 'Shipping Country', 'Shipping Phone',
        'Notes', 'Note Attributes', 'Cancelled at', 'Payment Method', 'Payment Reference', 'Refunded Amount', 'Vendor', 'Outstanding Balance', 'Employee', 'Location', 'Device ID', 'Id', 'Tags', 'Risk Level', 'Source', 'Phone', 'Order Source (Query/Campaign)', 'Order Status', 'Invoice No', 'Courier', 'AWB', 'Shiprocket Order Id'], $n8n ? ['Billing Province Name', 'Shipping Province Name'] : []);
}

/** The same rows as sheetRows() in dispatch_manual.html (one row per product; the order's own columns on its first row only). $orders = the rows of wmo_dsp_rows().
 *  $n8n = true makes the rows the n8n workflow expects from a Shopify export: dates as "2026-11-16 19:05:11 +0530" (the workflow splits them at the space; the ISO form of the CSV button ends up whole in the date column),
 *  the state NAME in the "Province Name" columns (it decides CGST + SGST for Gujarat, IGST elsewhere) and the order's real GST in "Taxes". */
function dpx_sheet_rows(array $orders, bool $n8n = false): array
{
    $iso = $n8n ? function ($s) { $s = (string)$s; return $s !== '' ? $s . ' +0530' : ''; } : function ($s) { $s = (string)$s; return $s !== '' ? preg_replace('/ /', 'T', $s, 1) . '+05:30' : ''; };
    $out = [];
    foreach ($orders as $r) {
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
                    'Notes' => $r['note'] ?? '', 'Cancelled at' => $st === 'cancelled' ? $iso($r['created_at'] ?? '') : '', 'Payment Method' => $pm, 'Payment Reference' => $r['utr'] ?? '', 'Outstanding Balance' => $r['cod'], 'Employee' => $r['emp'] ?? '',
                    'Id' => 7000000000000000 + (int)$r['id'], 'Tags' => $tags, 'Source' => 'manual', 'Phone' => $phone, 'Order Source (Query/Campaign)' => $r['type'] ?? '', 'Order Status' => $st,
                    'Invoice No' => is_array($inv) ? ($inv['no'] ?? '') : '', 'Courier' => is_array($par) ? ($par['courier'] ?? '') : '', 'AWB' => is_array($par) ? ($par['awb'] ?? '') : '', 'Shiprocket Order Id' => $r['sr']['id'] ?? '']);
                if ($n8n) { $o['Billing Province Name'] = $c['state'] ?? ''; $o['Shipping Province Name'] = $c['state'] ?? ''; if (isset($r['tax']) && is_numeric($r['tax'])) $o['Taxes'] = $r['tax']; }
            }
            $out[] = $o;
        }
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

function dpx_route(PDO $pdo, string $action): void
{
    $auth = require_auth(); if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin or HR can set up the automatic send.'], 403);
    $in = json_input(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $view = function () use ($pdo) {
        $c = dpx_cfg($pdo); $st = json_decode((string)setting_get($pdo, 'dpx_state', ''), true); $log = json_decode((string)setting_get($pdo, 'dpx_log', ''), true);
        return ['ok' => true, 'cfg' => ['on' => $c['on'], 'url' => $c['url'], 'header' => $c['header'], 'time' => $c['time'], 'has_token' => $c['token'] !== ''], 'state' => is_array($st) ? $st : new stdClass(), 'log' => is_array($log) ? $log : [], 'sent_total' => (function () use ($pdo) { try { dpx_tables($pdo); return (int)$pdo->query("SELECT COUNT(*) FROM dpx_sent")->fetchColumn(); } catch (Throwable $e) { return 0; } })()];
    };
    if ($action === 'dpx.get' || $action === 'dpx.log') json_response($view());
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
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
