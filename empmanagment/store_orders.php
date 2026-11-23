<?php
/* 🛍 WEBSITE ORDERS (the new TM Perfume House shop, test.tmperfumehouse.store) → give each order to a salesperson so it counts in their sales and target.
 *
 * The shop is read through its own API (GET /orders — read-only here; this file never creates or changes anything in the shop).
 * An order that is GIVEN to a salesperson becomes one row in manual_orders (channel 'TM Store', mp_no = the shop's order number): that is the same row a
 * quick entry / marketplace order uses, so Orders, the Commission page, the tower and the target % count it without any new rule. Taking it back deletes the row.
 * The same shop order can never be given twice (one row per order number).
 *
 * Settings: tms_api_base (the API address) · tms_api_key (the key; typed on the page by Admin, never sent back to the browser).
 * Who: Admin, HR, the Sales Head (qe_viewer_ok) and every role ticked "Website orders" on the Roles page.
 * Actions: tms.cfg_get · tms.cfg_save · tms.test · tms.people · tms.list · tms.assign · tms.unassign          PHP 7.4 safe. */
if (!isset($pdo) || !function_exists('json_response')) { return; }
if (strpos((string)($action ?? ($_GET['action'] ?? '')), 'tms.') !== 0) { return; }

if (!function_exists('tms_cfg')) {
    function tms_cfg(PDO $pdo): array
    {
        $b = rtrim(trim((string)setting_get($pdo, 'tms_api_base', 'https://test.tmperfumehouse.store/api/v1')), '/');
        return ['base' => $b, 'key' => trim((string)setting_get($pdo, 'tms_api_key', ''))];
    }
    /** [data, error]. Read-only: only GET is ever sent. */
    function tms_get(PDO $pdo, string $path, array $params = []): array
    {
        $c = tms_cfg($pdo); if ($c['key'] === '') return [null, 'The shop key is not saved yet. Admin: press "Connection" and paste the key.'];
        if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?/#i', $c['base'] . '/')) return [null, 'The shop address must start with https://'];
        if (!function_exists('curl_init')) return [null, 'PHP curl is not switched on on this server.'];
        $ch = curl_init($c['base'] . $path . ($params ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['key'], 'Accept: application/json']]);
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = $out === false ? curl_error($ch) : ''; curl_close($ch);
        if ($err !== '') return [null, 'The shop could not be reached: ' . mb_substr($err, 0, 120)];
        $j = json_decode((string)$out, true);
        if ($code === 401 || $code === 403) return [null, 'The shop refused the key (HTTP ' . $code . '). Admin: check the key in "Connection".'];
        if (!is_array($j) || empty($j['ok'])) return [null, 'The shop answered with an error' . ($code ? ' (HTTP ' . $code . ')' : '') . (is_array($j) && !empty($j['error']) ? ': ' . mb_substr((string)$j['error'], 0, 160) : '.')];
        return [$j['data'] ?? [], ''];
    }
    function tms_can(PDO $pdo, array $auth): bool
    {
        if (in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) return true;
        if (function_exists('user_can') && user_can($auth, 'store_orders')) return true;
        return function_exists('qe_viewer_ok') && qe_viewer_ok($pdo, $auth);
    }
    function tms_cols(PDO $pdo): void
    {
        static $ok = false; if ($ok) return; $ok = true;
        try { foreach (['employee_id' => 'INT NOT NULL DEFAULT 0', 'order_type' => "VARCHAR(10) NOT NULL DEFAULT ''", 'house' => "VARCHAR(12) NOT NULL DEFAULT ''", 'batch' => "VARCHAR(16) NOT NULL DEFAULT ''", 'outstanding' => 'DECIMAL(12,2) NULL', 'channel' => "VARCHAR(12) NOT NULL DEFAULT ''", 'mp_no' => "VARCHAR(60) NOT NULL DEFAULT ''", 'ml' => 'INT NULL'] as $c => $d) { if (!$pdo->query("SHOW COLUMNS FROM manual_orders LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE manual_orders ADD COLUMN $c $d"); } } catch (Throwable $e) { error_log('tms_cols: ' . $e->getMessage()); }
    }
    /** one shop order in the few fields the page and the saved row need */
    function tms_row(array $o): array
    {
        $items = is_array($o['items'] ?? null) ? $o['items'] : []; $pcs = 0; $ml = 0; $names = [];
        foreach ($items as $i) { $q = max(1, (int)($i['quantity'] ?? 1)); $pcs += $q; $vt = (string)($i['variant_title'] ?? ''); if (preg_match('/(\d{1,4})\s*ml/i', $vt . ' ' . (string)($i['title'] ?? ''), $m)) $ml += (int)$m[1] * $q;
            $t = trim(preg_replace('/\s*[|｜].*$/u', '', (string)($i['title'] ?? ''))); $t = trim(preg_replace('/\s*[-–]\s*(DG|LG|WH)?\s*Inspired.*$/iu', '', $t)); $sz = preg_match('/^\s*(\d{1,4}\s*ml)/i', $vt, $m2) ? ' ' . str_replace(' ', '', strtolower($m2[1])) : ''; $names[] = $q . ' × ' . $t . $sz; }
        $total = round((float)($o['total'] ?? 0), 2); $paid = round((float)($o['amount_paid'] ?? 0), 2); $ps = strtolower((string)($o['payment_status'] ?? '')); $pm = strtolower((string)($o['payment_method'] ?? ''));
        $fs = $ps === 'paid' || ($total > 0 && $paid >= $total) ? 'paid' : ($paid > 0 ? 'partially_paid' : 'pending');
        $cu = is_array($o['customer'] ?? null) ? $o['customer'] : []; $sh = is_array($o['shipping_address'] ?? null) ? $o['shipping_address'] : [];
        return ['number' => (string)($o['number'] ?? ''), 'placed_at' => (string)($o['placed_at'] ?? ''), 'customer' => (string)(($cu['name'] ?? '') ?: ($sh['name'] ?? '')), 'phone' => substr(preg_replace('/\D+/', '', (string)(($cu['phone'] ?? '') ?: ($sh['phone'] ?? ''))), -10),
            'city' => (string)($sh['city'] ?? ''), 'pieces' => $pcs, 'ml' => $ml, 'items' => implode(', ', $names), 'total' => $total, 'paid' => $paid, 'due' => max(0, round($total - $paid, 2)), 'pay_method' => $pm, 'pay_status' => $fs,
            'ship_status' => strtolower((string)($o['fulfillment_status'] ?? '')), 'awb' => (string)($o['awb'] ?? ''), 'source' => (string)($o['source'] ?? ''), 'note' => (string)($o['note'] ?? '')];
    }
    /** every order placed in [from, to] (dates), newest first. [rows, error] — kept for 45 s so the page does not ask the shop on every click */
    function tms_orders(PDO $pdo, string $from, string $to): array
    {
        $run = function () use ($pdo, $from, $to) {
            $out = []; $end = $to . ' 23:59:59';
            for ($p = 1; $p <= 20; $p++) {
                [$d, $e] = tms_get($pdo, '/orders', ['page' => $p, 'limit' => 100, 'since' => $from]); if ($e !== '') return [[], $e];
                foreach ((array)($d['items'] ?? []) as $o) { if (!is_array($o)) continue; $r = tms_row($o); if ($r['number'] === '' || $r['placed_at'] < $from . ' 00:00:00' || $r['placed_at'] > $end) continue; $out[] = $r; }
                if (empty($d['has_more'])) break;
            }
            usort($out, fn($a, $b) => strcmp($b['placed_at'], $a['placed_at'])); return [$out, ''];
        };
        if (!function_exists('hrms_memo')) return $run();
        $r = hrms_memo('tms_orders:' . $from . ':' . $to, 45, $run); if (is_array($r) && ($r[1] ?? '') !== '' && function_exists('hrms_memo_drop')) hrms_memo_drop('tms_orders:' . $from . ':' . $to);   // an error is not kept
        return is_array($r) ? $r : [[], 'Could not read the shop.'];
    }
}

$auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $in = $isPost ? json_input() : $_GET;
if (!tms_can($pdo, $auth)) json_response(['error' => 'Your role does not have "Website orders". Ask Admin to tick it on the Roles page.'], 403);
$isAdm = in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);

switch ((string)$action) {
    case 'tms.cfg_get': { $c = tms_cfg($pdo); json_response(['base' => $c['base'], 'key_set' => $c['key'] !== '', 'key_tail' => $c['key'] !== '' ? substr($c['key'], -4) : '', 'can_edit' => $isAdm]); }
    case 'tms.cfg_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!$isAdm) json_response(['error' => 'Only Admin / HR can change the connection.'], 403);
        $b = rtrim(trim((string)($in['base'] ?? '')), '/'); $k = trim((string)($in['key'] ?? ''));
        if ($b !== '') { if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._/\-]*)?$#i', $b) || strlen($b) > 200) json_response(['error' => 'The address must look like https://your-shop/api/v1'], 422); setting_set($pdo, 'tms_api_base', $b); }
        if ($k !== '') { if (strlen($k) < 20 || strlen($k) > 200 || preg_match('/\s/', $k)) json_response(['error' => 'That does not look like a key. Paste it exactly as the shop shows it.'], 422); setting_set($pdo, 'tms_api_key', $k); }
        json_response(['ok' => true, 'message' => 'Saved. Press "Test".']);
    }
    case 'tms.test': {
        [$d, $e] = tms_get($pdo, ''); if ($e !== '') json_response(['ok' => false, 'message' => $e]);
        $sc = array_keys((array)($d['key']['scopes'] ?? [])); $can = in_array('read_orders', $sc, true);
        json_response(['ok' => $can, 'message' => $can ? 'Connected to "' . (string)($d['store'] ?? 'the shop') . '". The key can read orders.' : 'Connected, but this key is not allowed to read orders. Give it "Orders: read" in the shop.']);
    }
    case 'tms.people': {
        $o = []; foreach ($pdo->query("SELECT employee_id, employee_code, full_name FROM employees WHERE status != 'ex' ORDER BY full_name")->fetchAll() as $e) $o[] = ['id' => (int)$e['employee_id'], 'name' => (string)$e['full_name'], 'code' => (string)$e['employee_code']];
        json_response(['people' => $o]);
    }
    case 'tms.list': {
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? (string)$in['to'] : date('Y-m-d'); $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? (string)$in['from'] : date('Y-m-d', time() - 6 * 86400);
        if ($from > $to) { $x = $from; $from = $to; $to = $x; } if ($from < date('Y-m-d', time() - 120 * 86400)) $from = date('Y-m-d', time() - 120 * 86400);
        if (!empty($in['fresh']) && function_exists('hrms_memo_drop')) hrms_memo_drop('tms_orders:' . $from . ':' . $to);
        [$rows, $err] = tms_orders($pdo, $from, $to); if ($err !== '') json_response(['error' => $err, 'code' => tms_cfg($pdo)['key'] === '' ? 'no_key' : 'shop'], 422);
        tms_cols($pdo); $given = []; $names = employee_names_by_id($pdo);
        if ($rows) { $nos = array_column($rows, 'number'); foreach (array_chunk($nos, 400) as $part) { $q = $pdo->prepare("SELECT manual_order_id, order_no, mp_no, employee_id, order_type, created_by FROM manual_orders WHERE channel = 'TM Store' AND mp_no IN (" . implode(',', array_fill(0, count($part), '?')) . ")"); $q->execute($part); foreach ($q->fetchAll() as $g) $given[(string)$g['mp_no']] = $g; } }
        $sum = ['orders' => count($rows), 'amount' => 0.0, 'given' => 0, 'given_amount' => 0.0];
        foreach ($rows as &$r) { $g = $given[$r['number']] ?? null; $r['emp'] = $g ? (int)$g['employee_id'] : 0; $r['emp_name'] = $g ? (string)($names[(int)$g['employee_id']] ?? '') : ''; $r['type'] = $g ? (string)$g['order_type'] : ''; $r['hrms_no'] = $g ? (string)$g['order_no'] : ''; $r['by'] = $g ? (string)$g['created_by'] : '';
            $sum['amount'] += $r['total']; if ($r['emp'] > 0) { $sum['given']++; $sum['given_amount'] += $r['total']; } } unset($r);
        json_response(['from' => $from, 'to' => $to, 'rows' => $rows, 'sum' => $sum, 'admin' => $isAdm]);
    }
    case 'tms.assign': {   // give ONE shop order to a salesperson (or move it to another one). The order is read again from the shop, so the amount cannot be typed or changed from the browser.
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $no = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['number'] ?? ''))); $emp = (int)($in['employee_id'] ?? 0); $ot = strtolower(trim((string)($in['order_type'] ?? 'query'))); if (!in_array($ot, ['query', 'campaign'], true)) $ot = 'query';
        if ($no === '' || $emp <= 0) json_response(['error' => 'Choose the order and the salesperson.'], 422);
        $eq = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ? AND status != 'ex'"); $eq->execute([$emp]); $en = (string)$eq->fetchColumn(); if ($en === '') json_response(['error' => 'That salesperson was not found.'], 422);
        [$d, $e] = tms_get($pdo, '/orders/' . rawurlencode($no)); if ($e !== '') json_response(['error' => $e], 422); $r = tms_row((array)$d); if ($r['number'] === '' || strtoupper($r['number']) !== $no) json_response(['error' => 'The shop does not have order ' . $no . '.'], 404);
        if ($r['total'] <= 0) json_response(['error' => 'Order ' . $no . ' has no amount, so there is nothing to count.'], 422);
        tms_cols($pdo); $by = (string)($auth['username'] ?? ''); $dt = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r['placed_at']) ? $r['placed_at'] : date('Y-m-d H:i:s');
        $rem = mb_substr('Website order ' . $r['number'] . ' · ' . $r['items'] . ($r['pay_method'] !== '' ? ' · ' . strtoupper($r['pay_method']) : ''), 0, 200);
        $q = $pdo->prepare("SELECT manual_order_id, employee_id FROM manual_orders WHERE channel = 'TM Store' AND mp_no = ? LIMIT 1"); $q->execute([$r['number']]); $old = $q->fetch();
        if ($old) {
            $pdo->prepare("UPDATE manual_orders SET employee_id = ?, order_type = ?, total = ?, payment_status = ?, outstanding = ?, items = ?, ml = ?, remarks = ?, customer_name = ?, phone = ? WHERE manual_order_id = ?")->execute([$emp, $ot, $r['total'], $r['pay_status'], $r['due'], max(1, $r['pieces']), $r['ml'] ?: null, $rem, mb_substr($r['customer'] ?: 'Website order ' . $r['number'], 0, 120), $r['phone'], (int)$old['manual_order_id']]);
            $id = (int)$old['manual_order_id'];
        } else {
            $pdo->prepare("INSERT INTO manual_orders (order_no, customer_name, phone, items, total, payment_status, fulfillment_status, order_date, remarks, created_by, employee_id, order_type, house, batch, outstanding, channel, mp_no, ml) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'tmph', ?, ?, 'TM Store', ?, ?)")
                ->execute([mb_substr($r['customer'] ?: 'Website order ' . $r['number'], 0, 120), $r['phone'], max(1, $r['pieces']), $r['total'], $r['pay_status'], $r['ship_status'] === 'delivered' ? 'fulfilled' : 'unfulfilled', $dt, $rem, $by, $emp, $ot, substr(bin2hex(random_bytes(8)), 0, 12), $r['due'], $r['number'], $r['ml'] ?: null]);
            $id = (int)$pdo->lastInsertId(); $pdo->prepare("UPDATE manual_orders SET order_no = ? WHERE manual_order_id = ?")->execute(['M' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), $id]);
        }
        if (function_exists('bd_log')) { try { bd_log('order', $r['number'], 'website order ' . ($old ? 'moved' : 'given') . ' to ' . $en . ' (' . $ot . ', ₹' . number_format($r['total']) . ')', $by); } catch (Throwable $e2) { } }
        json_response(['ok' => true, 'message' => $r['number'] . ' (₹' . number_format($r['total']) . ') now counts for ' . $en . '.', 'emp' => $emp, 'emp_name' => $en, 'type' => $ot, 'hrms_no' => 'M' . str_pad((string)$id, 4, '0', STR_PAD_LEFT)]);
    }
    case 'tms.unassign': {   // take the order back: it stops counting for anybody
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $no = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['number'] ?? ''))); if ($no === '') json_response(['error' => 'Choose the order.'], 422);
        tms_cols($pdo); $q = $pdo->prepare("DELETE FROM manual_orders WHERE channel = 'TM Store' AND mp_no = ?"); $q->execute([$no]);
        if ($q->rowCount() && function_exists('bd_log')) { try { bd_log('order', $no, 'website order taken back (counts for nobody)', (string)($auth['username'] ?? '')); } catch (Throwable $e2) { } }
        json_response(['ok' => true, 'message' => $q->rowCount() ? $no . ' is taken back. It counts for nobody now.' : $no . ' was not given to anyone.']);
    }
    default: json_response(['error' => 'Unknown website-orders action.'], 404);
}
