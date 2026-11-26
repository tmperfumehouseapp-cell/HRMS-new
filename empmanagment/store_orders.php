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
 * 🤖 Automatic (owner, 24 Nov): tms_auto_tick() gives a new shop order to the salesperson who holds the customer's WhatsApp chat — the SAME rules as a Shopify order
 *   (order_auto_alloc in api.php): chat given to a salesperson, the customer wrote in the last 7 days, Query / Campaign from order_chat_type(). Run by the engine and when the page opens.
 * Actions: tms.detail · tms.cfg_get · tms.cfg_save · tms.test · tms.people · tms.list · tms.assign · tms.unassign          PHP 7.4 safe. */
if (!isset($pdo) || !function_exists('json_response')) { return; }
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
        try { foreach (['employee_id' => 'INT NOT NULL DEFAULT 0', 'order_type' => "VARCHAR(10) NOT NULL DEFAULT ''", 'house' => "VARCHAR(12) NOT NULL DEFAULT ''", 'batch' => "VARCHAR(16) NOT NULL DEFAULT ''", 'outstanding' => 'DECIMAL(12,2) NULL', 'channel' => "VARCHAR(12) NOT NULL DEFAULT ''", 'mp_no' => "VARCHAR(60) NOT NULL DEFAULT ''", 'ml' => 'INT NULL', 'is_direct' => 'TINYINT NOT NULL DEFAULT 0'] as $c => $d) { if (!$pdo->query("SHOW COLUMNS FROM manual_orders LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE manual_orders ADD COLUMN $c $d"); } } catch (Throwable $e) { error_log('tms_cols: ' . $e->getMessage()); }
    }
    /** one shop order in the few fields the page and the saved row need */
    function tms_row(array $o): array
    {
        $items = is_array($o['items'] ?? null) ? $o['items'] : []; $pcs = 0; $ml = 0; $names = []; $lines = [];
        foreach ($items as $i) { $q = max(1, (int)($i['quantity'] ?? 1)); $pcs += $q; $vt = (string)($i['variant_title'] ?? ''); $gift = !empty($i['gift']);   // 🎁 the complimentary perfume of a payment link: in the total (ticket size), NOT in the ml (no commission on it)
            if (!$gift && preg_match('/(\d{1,4})\s*ml/i', $vt . ' ' . (string)($i['title'] ?? ''), $m)) $ml += (int)$m[1] * $q;
            $t = trim(preg_replace('/\s*[|｜].*$/u', '', (string)($i['title'] ?? ''))); $t = trim(preg_replace('/\s*[-–]\s*(DG|LG|WH)?\s*Inspired.*$/iu', '', $t)); $sz = preg_match('/^\s*(\d{1,4}\s*ml)/i', $vt, $m2) ? ' ' . str_replace(' ', '', strtolower($m2[1])) : ''; $names[] = $q . ' × ' . $t . $sz . ($gift ? ' (gift)' : ''); $lines[] = ['title' => $t, 'size' => trim((string)$vt), 'qty' => $q, 'price' => round((float)($i['price'] ?? 0), 2), 'discount' => round((float)($i['discount'] ?? 0), 2), 'sku' => (string)($i['sku'] ?? ''), 'gift' => $gift, 'raw' => (string)($i['title'] ?? ''), 'props' => (function ($pr) { $o = []; foreach ((array)$pr as $k => $v) { if (is_array($v) && isset($v['name'])) $o[] = ['name' => (string)$v['name'], 'value' => (string)($v['value'] ?? '')]; elseif (is_scalar($v) && is_string($k)) $o[] = ['name' => $k, 'value' => (string)$v]; } return $o; })($i['properties'] ?? [])]; }
        $total = round((float)($o['total'] ?? 0), 2); $paid = round((float)($o['amount_paid'] ?? 0), 2); $ps = strtolower((string)($o['payment_status'] ?? '')); $pm = strtolower((string)($o['payment_method'] ?? ''));
        $fs = $ps === 'paid' || ($total > 0 && $paid >= $total) ? 'paid' : ($paid > 0 ? 'partially_paid' : 'pending');
        $cu = is_array($o['customer'] ?? null) ? $o['customer'] : []; $sh = is_array($o['shipping_address'] ?? null) ? $o['shipping_address'] : [];
        // ⏱ owner (24 Nov): an order counts at the time the customer PAID, not when the payment link was made. The shop has no "paid at", so it is the order's last change while it is still
        //    not packed (the payment is that change); an order paid at once has the same time as placed. Never before the placing time, never in the future.
        $pl = (string)($o['placed_at'] ?? ''); $up = (string)($o['updated_at'] ?? ''); $shp = strtolower((string)($o['fulfillment_status'] ?? '')); $rx = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        $payT = $pl;   // 26 Nov, from the shop itself: a payment link (LINK-xxx) becomes the TM order in the SAME row when it is paid and placed_at is set to the payment time, so placed_at IS the day of the sale. updated_at changes on every edit (AWB, invoice, note), so it is not used any more.
        return ['temp' => stripos((string)($o['number'] ?? ''), 'LINK-') === 0 ? 1 : 0, 'number' => (string)($o['number'] ?? ''), 'placed_at' => (string)($o['placed_at'] ?? ''), 'pay_time' => $payT, 'customer' => (string)(($cu['name'] ?? '') ?: ($sh['name'] ?? '')), 'phone' => substr(preg_replace('/\D+/', '', (string)(($cu['phone'] ?? '') ?: ($sh['phone'] ?? ''))), -10),
            'city' => (string)($sh['city'] ?? ''), 'pieces' => $pcs, 'ml' => $ml, 'items' => implode(', ', $names), 'total' => $total, 'paid' => $paid, 'due' => max(0, round($total - $paid, 2)), 'pay_method' => $pm, 'pay_status' => $fs,
            'cancelled' => (!empty($o['cancelled_at']) || stripos((string)($o['status'] ?? '') . ' ' . (string)($o['fulfillment_status'] ?? '') . ' ' . (string)($o['payment_status'] ?? ''), 'cancel') !== false) ? 1 : 0, 'ship_status' => strtolower((string)($o['fulfillment_status'] ?? '')), 'awb' => (string)($o['awb'] ?? ''), 'source' => (string)($o['source'] ?? ''), 'note' => (string)($o['note'] ?? ''), 'lines' => $lines,
            'addr' => ['name' => (string)($sh['name'] ?? ''), 'phone' => (string)($sh['phone'] ?? ''), 'address1' => (string)($sh['address1'] ?? ''), 'address2' => (string)($sh['address2'] ?? ''), 'city' => (string)($sh['city'] ?? ''), 'state' => (string)($sh['state'] ?? ''), 'pincode' => (string)($sh['pincode'] ?? '')],
            'money' => ['subtotal' => round((float)($o['subtotal'] ?? 0), 2), 'discount' => round((float)($o['discount_total'] ?? 0), 2), 'code' => (string)($o['discount_code'] ?? ''), 'shipping' => round((float)($o['shipping_total'] ?? 0), 2), 'cod_fee' => round((float)($o['cod_fee'] ?? 0), 2)], 'courier' => (string)($o['courier'] ?? '')];
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
        $r = hrms_memo('tms_orders6:' . $from . ':' . $to, 45, $run); if (is_array($r) && ($r[1] ?? '') !== '' && function_exists('hrms_memo_drop')) hrms_memo_drop('tms_orders6:' . $from . ':' . $to);   // an error is not kept
        return is_array($r) ? $r : [[], 'Could not read the shop.'];
    }
    /** save ONE shop order as a manual_orders row for $emp (a new row; the caller checked there is none). Returns the HRMS order number (M0123). */
    /** 🔔 ORDER RING (owner, 25 Nov: "like Shopify — a ring when an order comes, on the phone of the salesperson whose order it is"): one row per website order the robot gave to a salesperson;
     *  his chat app plays the ring + shows a banner (tms.ring), and a push notification goes to his phone (also when the app is closed). Only for a fresh order (paid in the last 3 hours), once per order. */
    function tms_ring(PDO $pdo, int $emp, array $r, string $type): void
    {
        if ($emp <= 0) return;
        try {
            $t = strtotime((string)(($r['pay_time'] ?? '') ?: ($r['placed_at'] ?? ''))) ?: time(); if ($t < time() - 3 * 3600) return;   // an old order given late (a backfill) does not ring
            $pdo->exec("CREATE TABLE IF NOT EXISTS tms_ring (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, number VARCHAR(30) NOT NULL, customer VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(12) NOT NULL DEFAULT '', total DECIMAL(12,2) NOT NULL DEFAULT 0, otype VARCHAR(10) NOT NULL DEFAULT '', at DATETIME NOT NULL, seen TINYINT NOT NULL DEFAULT 0, UNIQUE KEY uq_ring_no (number), KEY k_ring_emp (emp_id, seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $q = $pdo->prepare("INSERT IGNORE INTO tms_ring (emp_id, number, customer, phone, total, otype, at) VALUES (?, ?, ?, ?, ?, ?, ?)"); $q->execute([$emp, (string)$r['number'], mb_substr((string)$r['customer'], 0, 120), (string)$r['phone'], (float)$r['total'], $type, date('Y-m-d H:i:s')]);
            if ($q->rowCount() !== 1) return;
            // the push: no "Punch In / Later" buttons (the phone's default for a notification without its own buttons), and how many of his phones took it is kept (pushed) so it can be checked
            $sent = 0; if (function_exists('push_send_employee')) { try { [$sent] = push_send_employee($pdo, $emp, ['title' => '💰 New order ₹' . number_format((float)$r['total']), 'body' => trim((string)$r['customer']) . ' · ' . $r['number'] . ' · ' . ucfirst($type) . ' — the order is yours.', 'url' => '/my_chats.html?phone=' . (string)$r['phone'], 'tag' => 'ord-' . (string)$r['number'], 'actions' => []]); } catch (Throwable $e) { } }
            try { $pdo->prepare("UPDATE tms_ring SET pushed = ? WHERE number = ?")->execute([(int)$sent, (string)$r['number']]); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE tms_ring ADD COLUMN pushed INT NOT NULL DEFAULT -1"); $pdo->prepare("UPDATE tms_ring SET pushed = ? WHERE number = ?")->execute([(int)$sent, (string)$r['number']]); } catch (Throwable $e2) { } }
        } catch (Throwable $e) { error_log('tms_ring: ' . $e->getMessage()); }
    }
    function tms_give(PDO $pdo, array $r, int $emp, string $ot, string $by): string
    {
        tms_cols($pdo); $rxd = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'; $dt = preg_match($rxd, (string)($r['pay_time'] ?? '')) ? (string)$r['pay_time'] : (preg_match($rxd, $r['placed_at']) ? $r['placed_at'] : date('Y-m-d H:i:s'));   // the time of the payment
        $rem = mb_substr('Website order ' . $r['number'] . ' · ' . $r['items'] . ($r['pay_method'] !== '' ? ' · ' . strtoupper($r['pay_method']) : ''), 0, 200);
        $pdo->prepare("INSERT INTO manual_orders (order_no, customer_name, phone, items, total, payment_status, fulfillment_status, order_date, remarks, created_by, employee_id, order_type, house, batch, outstanding, channel, mp_no, ml) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'tmph', ?, ?, 'TM Store', ?, ?)")
            ->execute([mb_substr($r['customer'] ?: 'Website order ' . $r['number'], 0, 120), $r['phone'], max(1, $r['pieces']), $r['total'], $r['pay_status'], $r['ship_status'] === 'delivered' ? 'fulfilled' : 'unfulfilled', $dt, $rem, $by, $emp, $ot, substr(bin2hex(random_bytes(8)), 0, 12), $r['due'], $r['number'], $r['ml'] ?: null]);
        $id = (int)$pdo->lastInsertId(); $no = 'M' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); $pdo->prepare("UPDATE manual_orders SET order_no = ? WHERE manual_order_id = ?")->execute([$no, $id]);
        return $no;
    }
    function tms_auto_table(PDO $pdo): bool
    {
        static $ok = null; if ($ok !== null) return $ok;
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS tms_auto (number VARCHAR(60) NOT NULL PRIMARY KEY, done TINYINT NOT NULL DEFAULT 0, why VARCHAR(160) NOT NULL DEFAULT '', at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $ok = true; }
        catch (Throwable $e) { error_log('tms_auto_table: ' . $e->getMessage()); $ok = false; }
        return $ok;
    }
    /** 🤖 Give the new shop orders (last 2 days) to the salesperson who holds the customer's WhatsApp chat. The rules are those of order_auto_alloc() (Shopify orders), nothing new:
     *  an order with an amount, a payment received (full or part) and a mobile number · that number has a WhatsApp chat GIVEN to a salesperson · the customer wrote, and not longer than 7 days ago · Query / Campaign from order_chat_type().
     *  The robot gives an order ONCE: an order a person took back is not given again. An order it could not give keeps the reason (shown on the page) and is tried again on the next run.
     *  $minGap = seconds between two runs. Returns how many orders were given. */
    function tms_auto_tick(PDO $pdo, int $minGap = 180): int
    {
        if (tms_cfg($pdo)['key'] === '' || !function_exists('order_chat_type') || !tms_auto_table($pdo)) return 0;
        if ((int)setting_get($pdo, 'tms_auto_at', '0') > time() - $minGap) return 0; setting_set($pdo, 'tms_auto_at', (string)time());
        $to = date('Y-m-d'); $from = date('Y-m-d', time() - 7 * 86400);   // a link made days ago can be paid today
        [$rows, $err] = tms_orders($pdo, $from, $to); if ($err !== '' || !$rows) return 0;
        if ((string)setting_get($pdo, 'tms_paytime_fix1', '') !== '1') {   // 🔧 one time: orders saved at the link's time (23–24 Nov) move to the time of the payment
            setting_set($pdo, 'tms_paytime_fix1', '1'); $fx = 0;
            try { tms_cols($pdo); $u = $pdo->prepare("UPDATE manual_orders SET order_date = ? WHERE channel = 'TM Store' AND mp_no = ? AND order_date = ?");
                foreach ($rows as $x) { if ((string)$x['pay_status'] !== 'pending' && (string)$x['pay_time'] !== '' && $x['pay_time'] > $x['placed_at']) { $u->execute([$x['pay_time'], $x['number'], $x['placed_at']]); $fx += $u->rowCount(); } }
                if (function_exists('bd_log')) { try { bd_log('order', 'website', 'website orders moved to the time of the payment: ' . $fx, 'system'); } catch (Throwable $e) { } }
            } catch (Throwable $e) { error_log('tms paytime fix: ' . $e->getMessage()); }
        }
        tms_cols($pdo); $nos = array_column($rows, 'number'); $given = []; $done = []; $waited = [];
        foreach (array_chunk($nos, 400) as $part) { $in = implode(',', array_fill(0, count($part), '?'));
            $q = $pdo->prepare("SELECT mp_no, created_by, employee_id, manual_order_id, is_direct, total, payment_status, outstanding, ml FROM manual_orders WHERE channel = 'TM Store' AND mp_no IN ($in)"); $q->execute($part); foreach ($q->fetchAll() as $x) $given[(string)$x['mp_no']] = ['by' => (string)$x['created_by'], 'emp' => (int)$x['employee_id'], 'id' => (int)$x['manual_order_id'], 'direct' => (int)$x['is_direct'], 'total' => (float)$x['total'], 'ps' => (string)$x['payment_status'], 'due' => (float)$x['outstanding'], 'ml' => (int)$x['ml']];
            $q = $pdo->prepare("SELECT number FROM tms_auto WHERE done = 1 AND number IN ($in)"); $q->execute($part); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $done[(string)$x] = 1;
            $q = $pdo->prepare("SELECT number FROM tms_auto WHERE done = 0 AND why = 'payment not received yet' AND number IN ($in)"); $q->execute($part); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $waited[(string)$x] = 1; }
        try { $qc = $pdo->prepare("SELECT c.phone, c.assigned_emp, c.source, c.last_in_at, COALESCE(n.camp_tag, 0) AS camp_tag FROM wa_contacts c LEFT JOIN wa_numbers n ON n.id = c.last_number_id WHERE c.phone = ?"); $qc->execute(['0']); }
        catch (Throwable $e) { try { $qc = $pdo->prepare("SELECT phone, assigned_emp, source, last_in_at, 0 AS camp_tag FROM wa_contacts WHERE phone = ?"); $qc->execute(['0']); } catch (Throwable $e2) { return 0; } }   // no WhatsApp tables here: nothing to do
        $mark = $pdo->prepare("INSERT INTO tms_auto (number, done, why, at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE done = VALUES(done), why = VALUES(why), at = VALUES(at)");
        $live = []; try { foreach ($pdo->query("SELECT employee_id FROM employees WHERE status != 'ex'")->fetchAll(PDO::FETCH_COLUMN) as $x) $live[(int)$x] = 1; } catch (Throwable $e) { return 0; }
        $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $n = 0; $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $no = (string)$r['number'];
            // owner (24 Nov): an order with NO payment received (a payment link the customer has not paid yet) is not given to anybody. One the robot already gave is taken back; it is given when the payment comes.
            // ⏱ a TM order that was here UNPAID earlier and has its payment now (an order typed in the shop's admin, paid a day later): the shop has no "paid at" in its feed, so the sale counts at the moment the HRMS sees the payment (this runs every few minutes). An order paid at once keeps the shop's own time.
            if (isset($waited[$no]) && (string)$r['pay_status'] !== 'pending' && empty($given[$no])) $r['pay_time'] = date('Y-m-d H:i:s');
            $row = $given[$no] ?? null; $unpaid = (string)$r['pay_status'] === 'pending';
            if ($unpaid && $row && $row['direct'] !== 1 && ($row['emp'] === 0 || $row['by'] === 'auto (WhatsApp)')) { try { $pdo->prepare("DELETE FROM manual_orders WHERE manual_order_id = ?")->execute([$row['id']]); $mark->execute([$no, 0, 'payment not received yet', $now]); } catch (Throwable $e) { } continue; }
            // 🔄 owner (24 Nov): the shop order changed after it was saved here (the 🎁 gift perfume is added when the customer pays, a balance was paid...): the saved row follows it, so the totals and the ticket size stay right
            if ($row && !$unpaid && (abs($row['total'] - (float)$r['total']) > 0.004 || $row['ps'] !== (string)$r['pay_status'] || abs($row['due'] - (float)$r['due']) > 0.004 || $row['ml'] !== (int)$r['ml'])) {
                try { $pdo->prepare("UPDATE manual_orders SET total = ?, payment_status = ?, outstanding = ?, items = ?, ml = ? WHERE manual_order_id = ?")->execute([$r['total'], $r['pay_status'], $r['due'], max(1, $r['pieces']), $r['ml'] ?: null, $row['id']]); } catch (Throwable $e) { error_log('tms refresh ' . $no . ': ' . $e->getMessage()); }
            }
            if (!empty($r['temp'])) { if (!$row) { try { $mark->execute([$no, 0, 'payment link (temporary): counts when the customer pays and the order gets its TM number', $now]); } catch (Throwable $e) { } } continue; }   // 🔗 owner (26 Nov): a LINK-xxx is temporary — never given, never counted
            if ($row && ($row['emp'] > 0 || $row['direct'] === 1)) continue;          // a salesperson has it, or a person marked it a Direct order
            try {
                $t = strtotime((string)($r['pay_time'] ?: $r['placed_at'])) ?: time(); $why = '';
                if ((float)$r['total'] <= 0) $why = 'order has no amount';
                elseif ((string)$r['pay_status'] === 'pending') $why = 'payment not received yet';
                elseif (isset($done[$no]) || ($row && $row['by'] !== 'auto (pending)')) $why = 'taken back by a person — give it by hand';
                elseif (strlen((string)$r['phone']) !== 10) $why = 'no mobile number on the order';
                else {
                    $qc->execute([$r['phone']]); $c = $qc->fetch(PDO::FETCH_ASSOC);
                    if (!$c) $why = 'no WhatsApp chat with this number';
                    elseif ((int)$c['assigned_emp'] <= 0) $why = 'the WhatsApp chat is not given to any salesperson';
                    elseif (!isset($live[(int)$c['assigned_emp']])) $why = 'the chat is with a person who left';
                    elseif (empty($c['last_in_at'])) $why = 'the customer never wrote to us on WhatsApp';
                    else { $li = strtotime((string)$c['last_in_at']); if ($li < $t - 7 * 86400 && $li < time() - 7 * 86400) $why = 'last WhatsApp message ' . date('j M', $li) . ' — older than 7 days'; }
                }
                if ($why !== '') {
                    if (!isset($done[$no]) && !($row && $row['by'] !== 'auto (pending)')) $mark->execute([$no, 0, $why, $now]);
                    // 📥 owner (24 Nov): a PAID order nobody has goes to the Allocation page by itself, as a pending order (a row with no salesperson) — nobody has to open Website orders to find it
                    if (!$unpaid && (float)$r['total'] > 0 && !$row) tms_give($pdo, $r, 0, '', 'auto (pending)');
                    continue;
                }
                $emp = (int)$c['assigned_emp']; $type = order_chat_type($pdo, $c, $t); if (!in_array($type, ['query', 'campaign'], true)) $type = 'query';
                $mark->execute([$no, 1, '', $now]);          // marked first: whatever happens next, the robot never gives the same order twice
                if ($row) $pdo->prepare("UPDATE manual_orders SET employee_id = ?, order_type = ?, house = 'tmph', created_by = 'auto (WhatsApp)' WHERE manual_order_id = ? AND employee_id = 0")->execute([$emp, $type, $row['id']]);   // it was waiting on the Allocation page
                else tms_give($pdo, $r, $emp, $type, 'auto (WhatsApp)');
                $n++; tms_ring($pdo, $emp, $r, $type);   // 🔔 the salesperson hears it
                if (function_exists('wa_event')) { try { wa_event($pdo, (string)$c['phone'], '🧾 Website order ' . $no . ' allocated to ' . ($names[$emp] ?? 'the salesperson') . ' · ' . ucfirst($type) . ' (automatic)'); } catch (Throwable $e) { } }
            } catch (Throwable $e) { error_log('tms_auto_tick ' . $no . ': ' . $e->getMessage()); }
        }
        if ($n && function_exists('hrms_memo_drop')) { try { hrms_memo_drop('tms_orders6:' . $from . ':' . $to); } catch (Throwable $e) { } }
        return $n;
    }
}

if (strpos((string)($action ?? ($_GET['action'] ?? '')), 'tms.') !== 0) { return; }

$auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $in = $isPost ? json_input() : $_GET;
if ((string)$action === 'tms.gift') {   // 🎁 the website's gift perfume on payment links for one business day: how many kept it / removed it and the add-on money (Sales > Live tile). Read-only.
    $ok = tms_can($pdo, $auth) || (function_exists('user_can') && (user_can($auth, 'control_tower') || user_can($auth, 'orders')));
    if (!$ok) json_response(['error' => 'Not allowed.'], 403);
    $day = (string)($in['date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = function_exists('waa_day') ? waa_day($pdo) : date('Y-m-d');
    [$from, $to] = function_exists('biz_window') ? biz_window($pdo, $day, $day) : [$day . ' 00:00:00', $day . ' 23:59:59'];
    $run = function () use ($pdo, $from, $to) { [$d, $e] = tms_get($pdo, '/gift-report', ['from' => $from, 'to' => date('Y-m-d H:i:s', strtotime($to) + 1)]); return $e !== '' ? ['error' => $e] : (array)$d; };
    $r = function_exists('hrms_memo') ? hrms_memo('tms_gift:' . $from, 60, $run) : $run();
    if (!is_array($r) || !empty($r['error'])) { if (function_exists('hrms_memo_drop')) hrms_memo_drop('tms_gift:' . $from); json_response(['ok' => false, 'error' => (string)($r['error'] ?? 'The shop could not be read.'), 'day' => $day]); }
    $k = (int)($r['kept'] ?? 0); $rm = (int)($r['removed'] ?? 0);
    json_response(['ok' => true, 'day' => $day, 'kept' => $k, 'removed' => $rm, 'rate' => $k + $rm > 0 ? (int)round($k * 100 / ($k + $rm)) : null, 'money' => round((float)($r['money'] ?? 0), 2), 'orders' => array_values(array_map('strval', (array)($r['orders'] ?? [])))]);
}
if ((string)$action === 'tms.ring') {   // 🔔 the orders that just came for ME (asked by the chat app every 20 s). EVERY device of the salesperson rings (owner, 25 Nov: "the pop-up does not come" — a person is logged in on
    // several phones / tabs and only the first one to ask got the order): the device sends the last ring id it has shown (after) and gets the newer ones of the last 3 minutes. after = -1 (a device that just opened): only the newest id, nothing rings.
    $me = (int)($auth['employee_id'] ?? 0); $out = []; $max = 0; $after = (int)($in['after'] ?? -1);
    if ($me > 0) { try {
        $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM tms_ring")->fetchColumn();
        if (!isset($in['after'])) {   // an app that was not refreshed yet (the first version): each order once, as before
            $q = $pdo->prepare("SELECT id, number, customer, phone, total, otype FROM tms_ring WHERE emp_id = ? AND seen = 0 AND at >= ? ORDER BY id LIMIT 5"); $q->execute([$me, date('Y-m-d H:i:s', time() - 1800)]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $u = $pdo->prepare("UPDATE tms_ring SET seen = 1 WHERE id = ? AND seen = 0"); $u->execute([(int)$x['id']]); if ($u->rowCount() === 1) $out[] = ['id' => (int)$x['id'], 'number' => (string)$x['number'], 'customer' => (string)$x['customer'], 'phone' => (string)$x['phone'], 'total' => (float)$x['total'], 'type' => (string)$x['otype']]; }
        } elseif ($after >= 0 && $after < $max) { $q = $pdo->prepare("SELECT id, number, customer, phone, total, otype FROM tms_ring WHERE emp_id = ? AND id > ? AND at >= ? ORDER BY id LIMIT 5"); $q->execute([$me, $after, date('Y-m-d H:i:s', time() - 180)]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $out[] = ['id' => (int)$x['id'], 'number' => (string)$x['number'], 'customer' => (string)$x['customer'], 'phone' => (string)$x['phone'], 'total' => (float)$x['total'], 'type' => (string)$x['otype']]; }
            if ($out) { try { $pdo->prepare("UPDATE tms_ring SET seen = 1 WHERE emp_id = ? AND id > ? AND seen = 0")->execute([$me, $after]); } catch (Throwable $e) { } } }   // seen = at least one of his devices showed it (for the check)
    } catch (Throwable $e) { } }   // no table yet = no order has rung yet
    json_response(['ok' => true, 'rows' => $out, 'max' => $max]);
}
if ((string)$action === 'tms.unpaid_mine') {   // 💳 NOT PAID YET (owner, 25 Nov): the website orders of the last 5 days whose payment has not come, for the salesperson who holds the customer's WhatsApp chat (open, closed or in History),
    // else the last person who wrote to the customer there — the same rule as the name filled in on Website orders. An Admin sees everybody's. An order leaves when it is paid, cancelled, or the customer paid a later order. Read-only.
    $me = (int)($auth['employee_id'] ?? 0); $adm = function_exists('wa_is_admin') ? (bool)wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm && function_exists('wa_boss_ok')) { try { $adm = (bool)wa_boss_ok($pdo, $auth); } catch (Throwable $e) { } }   // a Sales Head sees every salesperson's customers too
    if (tms_cfg($pdo)['key'] === '') json_response(['ok' => true, 'rows' => [], 'amount' => 0, 'days' => 7]);
    $days = 7; [$all, $err] = tms_orders($pdo, date('Y-m-d', time() - $days * 86400), date('Y-m-d'));
    if ($err !== '') json_response(['ok' => false, 'error' => $err, 'rows' => [], 'amount' => 0]);
    $cut = date('Y-m-d H:i:s', time() - $days * 86400); $paidAt = []; $un = []; $anyAt = [];
    foreach ($all as $r) { $ph = (string)$r['phone']; if (strlen($ph) !== 10) continue; $dead = (bool)preg_match('/cancel|refund|void/i', (string)$r['ship_status'] . ' ' . (string)$r['pay_status']);
        if (!$dead && (!isset($anyAt[$ph]) || $r['placed_at'] > $anyAt[$ph])) $anyAt[$ph] = (string)$r['placed_at'];   // his newest order of any kind: a checkout link made before it has done its job
        if ((string)$r['pay_status'] !== 'pending') { if (!$dead && (!isset($paidAt[$ph]) || $r['placed_at'] > $paidAt[$ph])) $paidAt[$ph] = (string)$r['placed_at']; continue; }
        if ($dead || (float)$r['total'] <= 0 || (string)$r['placed_at'] < $cut) continue; $un[] = $r; }
    $un = array_values(array_filter($un, function ($r) use ($paidAt) { return !isset($paidAt[(string)$r['phone']]) || $paidAt[(string)$r['phone']] < (string)$r['placed_at']; }));   // he paid a later order: the older unpaid one is done
    // 🧾 owner (25 Nov: "Kadam already ordered and is still in the list"): the customer ordered ANOTHER way (a manual order typed in HRMS, a Shopify order) around or after the unpaid one — he is done.
    //    [phone => newest other order time]; an order from 12 hours before the unpaid order / link onwards counts (the salesperson often types the manual order a few minutes before or after the website one).
    $otherAt = function (array $phones) use ($pdo, $cut): array {
        $o = []; $set = array_flip($phones); if (!$set) return $o; $from = date('Y-m-d H:i:s', strtotime($cut) - 43200);
        try { $q = $pdo->prepare("SELECT RIGHT(REPLACE(REPLACE(phone, ' ', ''), '-', ''), 10) p, MAX(order_date) t FROM manual_orders WHERE order_date >= ? AND phone <> '' AND COALESCE(fulfillment_status, '') NOT LIKE '%cancel%' AND NOT (COALESCE(channel, '') = 'TM Store' AND COALESCE(payment_status, '') = 'pending') GROUP BY p"); $q->execute([$from]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($set[(string)$r['p']])) $o[(string)$r['p']] = (string)$r['t']; } catch (Throwable $e) { error_log('tms unpaid manual: ' . $e->getMessage()); }
        try { $q = $pdo->prepare("SELECT phone, created_at FROM shop_orders WHERE created_at >= ? AND phone IS NOT NULL AND phone <> '' AND COALESCE(tags, '') NOT LIKE '%wa-rx%' LIMIT 100000"); $q->execute([$from]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if (isset($set[$k]) && (!isset($o[$k]) || (string)$r['created_at'] > $o[$k])) $o[$k] = (string)$r['created_at']; } } catch (Throwable $e) { error_log('tms unpaid shop: ' . $e->getMessage()); }
        return $o; };
    $near = function (string $at) { return date('Y-m-d H:i:s', (strtotime($at) ?: time()) - 43200); };
    $un = array_values(array_filter($un, function ($r) { return (float)$r['total'] >= 50; }));   // a ₹1 test order is not a customer
    if ($un) { $__oa = $otherAt(array_values(array_unique(array_column($un, 'phone')))); $un = array_values(array_filter($un, function ($r) use ($__oa, $near) { $ph = (string)$r['phone']; return !(isset($__oa[$ph]) && $__oa[$ph] >= $near((string)$r['placed_at'])); })); }
    $who = []; $nameOf = [];
    if ($un) { try {
        $phs = array_values(array_unique(array_column($un, 'phone'))); $live = []; foreach ($pdo->query("SELECT employee_id FROM employees WHERE status != 'ex'")->fetchAll(PDO::FETCH_COLUMN) as $x) $live[(int)$x] = 1;
        foreach (array_chunk($phs, 400) as $ch) { $q = $pdo->prepare("SELECT phone, assigned_emp, name FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) { $nameOf[(string)$c['phone']] = (string)$c['name']; $e = (int)$c['assigned_emp']; if ($e > 0 && isset($live[$e])) $who[(string)$c['phone']] = $e; } }
        $ql = $pdo->prepare("SELECT emp_id FROM wa_messages WHERE phone = ? AND dir = 'out' AND emp_id > 0 ORDER BY id DESC LIMIT 1"); $left = 200;
        foreach ($phs as $ph) { if (isset($who[$ph]) || !isset($nameOf[$ph]) || $left-- <= 0) continue; $ql->execute([$ph]); $e = (int)$ql->fetchColumn(); if ($e > 0 && isset($live[$e])) $who[$ph] = $e; }
    } catch (Throwable $e) { error_log('tms unpaid: ' . $e->getMessage()); } }
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $rows = []; $amt = 0.0; $now = time(); $seen = [];
    foreach ($un as $r) { $ph = (string)$r['phone']; $e = (int)($who[$ph] ?? 0); if (!$adm && ($me <= 0 || $e !== $me)) continue; if ($adm && $e <= 0) continue; if (isset($seen[$ph])) continue; $seen[$ph] = 1;   // one row per customer: his newest unpaid order (the list is newest first)
        $t = strtotime((string)$r['placed_at']) ?: $now; $amt += (float)$r['total'];
        $rows[] = ['kind' => 'order', 'number' => (string)$r['number'], 'phone' => $ph, 'customer' => (string)($r['customer'] ?: ($nameOf[$ph] ?? '')), 'total' => (float)$r['total'], 'items' => mb_substr((string)$r['items'], 0, 140), 'placed_at' => (string)$r['placed_at'], 'mins' => max(0, (int)floor(($now - $t) / 60)), 'emp' => $adm ? (string)($names[$e] ?? '') : '']; }
    // 🔗 owner (25 Nov): also the customers who were SENT a checkout link in these 5 days and placed no order after it (the link is in the chat, nothing came). The salesperson who made the link sees it; one row per customer (the newest link).
    try { $lq = $pdo->prepare("SELECT phone, emp_id, total, created_at FROM wso_order WHERE sent = 1 AND created_at >= ?" . ($adm ? '' : ' AND emp_id = ?') . " ORDER BY id DESC LIMIT 1500"); $lq->execute($adm ? [$cut] : [$cut, $me]); $lrows = $lq->fetchAll(PDO::FETCH_ASSOC);
        $lph = []; foreach ($lrows as $l) { $ph = substr(preg_replace('/\D+/', '', (string)$l['phone']), -10); if (strlen($ph) === 10 && !isset($seen[$ph]) && !(isset($anyAt[$ph]) && $anyAt[$ph] >= (string)$l['created_at'])) $lph[$ph] = 1; }
        if ($lph) { $__ob = $otherAt(array_keys($lph)); foreach ($lrows as $l) { $ph = substr(preg_replace('/\D+/', '', (string)$l['phone']), -10); if (isset($lph[$ph]) && isset($__ob[$ph]) && $__ob[$ph] >= $near((string)$l['created_at'])) unset($lph[$ph]); } }   // he ordered another way after (or just before) the link
        $lname = []; foreach (array_chunk(array_keys($lph), 400) as $ch) { $q = $pdo->prepare("SELECT phone, name FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) $lname[(string)$c['phone']] = (string)$c['name']; }
        foreach ($lrows as $l) { $ph = substr(preg_replace('/\D+/', '', (string)$l['phone']), -10); if (!isset($lph[$ph]) || isset($seen[$ph])) continue; $seen[$ph] = 1; $e = (int)$l['emp_id']; if (!$adm && $e !== $me) continue;
            $t = strtotime((string)$l['created_at']) ?: $now; $amt += (float)$l['total'];
            $rows[] = ['kind' => 'link', 'number' => 'Checkout link', 'phone' => $ph, 'customer' => (string)($lname[$ph] ?? ''), 'total' => (float)$l['total'], 'items' => 'link sent in the chat — no order placed yet', 'placed_at' => (string)$l['created_at'], 'mins' => max(0, (int)floor(($now - $t) / 60)), 'emp' => $adm ? (string)($names[$e] ?? '') : '']; }
    } catch (Throwable $e) { error_log('tms unpaid links: ' . $e->getMessage()); }   // no wso_order table yet: only the orders
    usort($rows, function ($a, $b) { return strcmp((string)$b['placed_at'], (string)$a['placed_at']); });
    $__d0 = date('Y-m-d'); $__d1 = date('Y-m-d', time() - 86400); $__d2 = date('Y-m-d', time() - 2 * 86400);   // 🗓 owner (25 Nov): the list in parts — today · yesterday · day before yesterday · earlier (up to 7 days)
    foreach ($rows as &$__r) { $__dd = substr((string)$__r['placed_at'], 0, 10); $__r['part'] = $__dd >= $__d0 ? 0 : ($__dd === $__d1 ? 1 : ($__dd === $__d2 ? 2 : 3)); } unset($__r);
    json_response(['ok' => true, 'rows' => array_slice($rows, 0, 150), 'count' => count($rows), 'amount' => round($amt), 'days' => $days, 'admin' => $adm]);
}
if ((string)$action === 'tms.gift_orders') {   // 🎁 the orders behind the Gift add-on number of one business day: who took the gift perfume and for how much (owner 24 Nov). Read-only; the shop is asked for each order once per 10 minutes.
    $ok = tms_can($pdo, $auth) || (function_exists('user_can') && (user_can($auth, 'control_tower') || user_can($auth, 'orders')));
    if (!$ok) json_response(['error' => 'Not allowed.'], 403);
    $day = (string)($in['date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = function_exists('waa_day') ? waa_day($pdo) : date('Y-m-d');
    [$from, $to] = function_exists('biz_window') ? biz_window($pdo, $day, $day) : [$day . ' 00:00:00', $day . ' 23:59:59'];
    $run = function () use ($pdo, $from, $to) { [$d, $e] = tms_get($pdo, '/gift-report', ['from' => $from, 'to' => date('Y-m-d H:i:s', strtotime($to) + 1)]); return $e !== '' ? ['error' => $e] : (array)$d; };
    $r = function_exists('hrms_memo') ? hrms_memo('tms_gift:' . $from, 60, $run) : $run();
    if (!is_array($r) || !empty($r['error'])) json_response(['ok' => false, 'error' => (string)($r['error'] ?? 'The shop could not be read.'), 'day' => $day]);
    $nos = array_slice(array_values(array_unique(array_filter(array_map(function ($x) { return strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)$x)); }, (array)($r['orders'] ?? []))))), 0, 150);
    $emp = []; try { tms_cols($pdo); foreach (array_chunk($nos, 200) as $ch) { $q = $pdo->prepare("SELECT mp_no, employee_id FROM manual_orders WHERE channel = 'TM Store' AND mp_no IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $emp[strtoupper((string)$x['mp_no'])] = (int)$x['employee_id']; } } catch (Throwable $e) { }
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $rows = []; $miss = 0; $t0 = microtime(true);
    foreach ($nos as $no) {
        $one = function () use ($pdo, $no) { [$d, $e] = tms_get($pdo, '/orders/' . rawurlencode($no)); return $e !== '' ? ['error' => $e] : ['order' => tms_row((array)$d)]; };
        if (microtime(true) - $t0 > 40) { $miss++; continue; }   // never hang the screen: the rest comes on the next open (each order read is kept 10 minutes)
        $o = function_exists('hrms_memo') ? hrms_memo('tms_giftrow:' . $no, 600, $one) : $one();
        if (!is_array($o) || !empty($o['error']) || empty($o['order']['number'])) { if (function_exists('hrms_memo_drop')) hrms_memo_drop('tms_giftrow:' . $no); $miss++; continue; }
        $o = $o['order']; $amt = 0.0; $gf = [];
        foreach ((array)($o['lines'] ?? []) as $l) if (!empty($l['gift'])) { $amt += max(0, (float)$l['price'] * max(1, (int)$l['qty']) - (float)$l['discount']); $gf[] = trim((string)$l['title'] . ' ' . (string)$l['size']); }
        $e = (int)($emp[$no] ?? 0);
        $rows[] = ['number' => (string)$o['number'], 'customer' => (string)$o['customer'], 'emp' => $e > 0 ? (string)($names[$e] ?? '') : '', 'gift' => implode(', ', $gf), 'amount' => round($amt, 2), 'total' => (float)$o['total'], 'time' => (string)($o['pay_time'] ?: $o['placed_at'])];
    }
    usort($rows, function ($a, $b) { return strcmp((string)$b['time'], (string)$a['time']); });
    $k = (int)($r['kept'] ?? 0); $rm = (int)($r['removed'] ?? 0);
    json_response(['ok' => true, 'day' => $day, 'kept' => $k, 'removed' => $rm, 'money' => round((float)($r['money'] ?? 0), 2), 'rows' => $rows, 'missing' => $miss]);
}
if ((string)$action === 'tms.detail') {   // 🔎 one shop order with every product and the delivery address — for the pop-up on the Allocation page. Read-only. Who: Website orders people, the Orders pages, or the order's own salesperson.
    $no = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['number'] ?? ''))); if ($no === '') json_response(['error' => 'Which order?'], 422);
    $ok = tms_can($pdo, $auth) || (function_exists('user_can') && user_can($auth, 'orders'));
    if (!$ok) { try { tms_cols($pdo); $q = $pdo->prepare("SELECT employee_id FROM manual_orders WHERE channel = 'TM Store' AND mp_no = ? LIMIT 1"); $q->execute([$no]); $own = (int)$q->fetchColumn(); $ok = $own > 0 && $own === (int)($auth['employee_id'] ?? 0); } catch (Throwable $e) { } }
    if (!$ok) json_response(['error' => 'Full details are only available for your own orders.'], 403);
    $run = function () use ($pdo, $no) { [$d, $e] = tms_get($pdo, '/orders/' . rawurlencode($no)); return $e !== '' ? ['error' => $e] : ['order' => tms_row((array)$d)]; };
    $r = function_exists('hrms_memo') ? hrms_memo('tms_detail:' . $no, 60, $run) : $run();
    if (!is_array($r) || !empty($r['error']) || (string)($r['order']['number'] ?? '') === '') { if (function_exists('hrms_memo_drop')) hrms_memo_drop('tms_detail:' . $no); json_response(['error' => (string)($r['error'] ?? 'The shop does not have this order.')], 422); }
    json_response(['order' => $r['order']]);
}
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
    case 'tms.offer_leads': {   // 🌐 visitors who pressed "Login to check" on the shop's product page and verified their mobile (they agreed to offers on WhatsApp). Read-only; the Campaigns page shows them above the Cold funnel.
        $days = max(1, min(90, (int)($in['days'] ?? 30))); $since = date('Y-m-d', time() - $days * 86400);
        $get = function () use ($pdo, $since) { [$d, $e] = tms_get($pdo, '/offer-logins', ['since' => $since]); return ['d' => $d, 'e' => $e]; };
        $r = function_exists('hrms_memo') ? hrms_memo('tms_offer_leads:' . $since, 300, $get) : $get();
        if ((string)$r['e'] !== '') { $nr = strpos((string)$r['e'], 'HTTP 404') !== false; if (function_exists('hrms_memo_drop')) { try { hrms_memo_drop('tms_offer_leads:' . $since); } catch (Throwable $e2) { } } json_response(['ok' => true, 'ready' => false, 'message' => $nr ? 'The website has not switched this on yet (the "Login to check" box is being built).' : (string)$r['e']]); }
        $list = (array)$r['d']; if (isset($list['rows']) && is_array($list['rows'])) $list = $list['rows'];
        $rows = []; $seen = []; $today = date('Y-m-d'); $wk = date('Y-m-d', time() - 6 * 86400); $c = ['today' => 0, 'week' => 0, 'all' => 0, 'ordered' => 0];
        foreach ($list as $x) { if (!is_array($x)) continue; $ph = preg_replace('/\D+/', '', (string)($x['phone'] ?? '')); if (strlen($ph) === 10) $ph = '91' . $ph; if (strlen($ph) < 11 || isset($seen[$ph])) continue; if (array_key_exists('consent', $x) && !$x['consent']) continue; $seen[$ph] = 1;
            $at = (string)($x['verified_at'] ?? ''); $ts = $at !== '' ? strtotime($at) : 0; $day = $ts ? date('Y-m-d', $ts) : ''; $ord = !empty($x['ordered']);
            $c['all']++; if ($day === $today) $c['today']++; if ($day !== '' && $day >= $wk) $c['week']++; if ($ord) $c['ordered']++;
            $rows[] = ['phone' => $ph, 'name' => mb_substr((string)($x['name'] ?? ''), 0, 60), 'at' => $ts ? date('Y-m-d H:i', $ts) : '', 'product' => mb_substr((string)($x['product_title'] ?? ($x['product_handle'] ?? '')), 0, 80), 'ordered' => $ord ? 1 : 0, 'order_no' => mb_substr((string)($x['last_order_number'] ?? ''), 0, 30)]; }
        usort($rows, function ($a, $b) { return strcmp($b['at'], $a['at']); });
        json_response(['ok' => true, 'ready' => true, 'days' => $days, 'count' => $c, 'rows' => array_slice($rows, 0, 2000)]);
    }
    case 'tms.diag': {   // 🩺 one day of website orders, the shop beside the HRMS, as plain text to copy (read-only). No names, no phone numbers.
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['day'] ?? '')) ? (string)$in['day'] : date('Y-m-d');
        if (function_exists('hrms_memo_drop')) { try { hrms_memo_drop('tms_orders6:' . date('Y-m-d', strtotime($day) - 7 * 86400) . ':' . $day); } catch (Throwable $e) { } }
        [$all, $err] = tms_orders($pdo, date('Y-m-d', strtotime($day) - 7 * 86400), $day);
        $T = []; $T[] = 'HRMS WEBSITE-ORDERS DIAGNOSE'; $T[] = 'day ' . $day . ' | made ' . date('Y-m-d H:i:s') . ' | shop ' . tms_cfg($pdo)['base'];
        if ($err !== '') { $T[] = 'SHOP ERROR: ' . $err; json_response(['ok' => true, 'text' => implode("\n", $T)]); }
        $d0 = $day . ' 00:00:00'; $d1 = $day . ' 23:59:59'; tms_cols($pdo); $h = [];
        try { $q = $pdo->prepare("SELECT mp_no, total, employee_id, order_type, payment_status, order_date, created_by, is_direct, ml FROM manual_orders WHERE channel = 'TM Store' AND (order_date BETWEEN ? AND ? OR order_date >= ?)"); $q->execute([$d0, $d1, date('Y-m-d', strtotime($day) - 8 * 86400)]); foreach ($q->fetchAll() as $x) $h[(string)$x['mp_no']] = $x; } catch (Throwable $e) { $T[] = 'HRMS rows error: ' . $e->getMessage(); }
        $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        $c = ['tm' => 0, 'tm_amt' => 0.0, 'paid' => 0, 'paid_amt' => 0.0, 'part' => 0, 'part_amt' => 0.0, 'cod' => 0, 'cod_amt' => 0.0, 'unpaid' => 0, 'unpaid_amt' => 0.0, 'link' => 0, 'link_amt' => 0.0, 'cancel' => 0, 'received' => 0.0, 'given' => 0, 'given_amt' => 0.0, 'wait' => 0, 'wait_amt' => 0.0, 'none' => 0, 'none_amt' => 0.0];
        $lines = []; $bad = []; $seen = [];
        foreach ($all as $r) {
            $pl = (string)$r['placed_at']; $pt = (string)($r['pay_time'] ?: $pl); $inP = $pl >= $d0 && $pl <= $d1; $inH = $pt >= $d0 && $pt <= $d1; if (!$inP && !$inH) continue;
            $no = (string)$r['number']; $seen[$no] = 1; $x = $h[$no] ?? null;
            if (!empty($r['temp'])) { if ($inP) { $c['link']++; $c['link_amt'] += $r['total']; } if ($x) $bad[] = $no . ' is a LINK but has an HRMS row'; continue; }
            if (!empty($r['cancelled'])) { $c['cancel']++; if ($x) $bad[] = $no . ' is cancelled in the shop but has an HRMS row (' . (float)$x['total'] . ')'; continue; }
            $kind = $r['pay_status'] === 'paid' ? 'paid' : ($r['pay_status'] === 'partially_paid' ? 'part' : ($r['pay_method'] === 'cod' ? 'cod' : 'unpaid'));
            $c['tm']++; $c['tm_amt'] += $r['total']; $c[$kind]++; $c[$kind . '_amt'] += $r['total']; $c['received'] += $r['paid'];
            $st = !$x ? 'none' : ((int)$x['employee_id'] > 0 ? 'given' : 'wait'); $c[$st]++; $c[$st . '_amt'] += $r['total'];
            if ($x && abs((float)$x['total'] - (float)$r['total']) > 0.5) $bad[] = $no . ' amount differs: shop ' . $r['total'] . ' / HRMS ' . (float)$x['total'];
            if (!$x && $kind !== 'unpaid' && $kind !== 'cod') $bad[] = $no . ' has a payment (' . $kind . ' ' . $r['paid'] . ') but is NOT in the HRMS';
            if ($x && substr((string)$x['order_date'], 0, 10) !== substr($pt, 0, 10)) $bad[] = $no . ' day differs: shop pay-day ' . substr($pt, 0, 10) . ' / HRMS ' . substr((string)$x['order_date'], 0, 10);
            $lines[] = $no . ' | placed ' . substr($pl, 5, 11) . ' | paid-at ' . substr($pt, 5, 11) . ' | total ' . $r['total'] . ' | got ' . $r['paid'] . ' | ' . $r['pay_method'] . '/' . $r['pay_status'] . ' | src ' . $r['source'] . ' | ml ' . $r['ml'] . ' | HRMS: ' . ($st === 'none' ? 'NOT THERE' : ($st === 'wait' ? 'waiting (no salesperson)' : 'emp ' . (int)$x['employee_id'] . ' ' . mb_substr((string)($names[(int)$x['employee_id']] ?? ''), 0, 18) . ' ' . (string)$x['order_type'] . ' total ' . (float)$x['total'] . ' by ' . (string)$x['created_by']));
        }
        foreach ($h as $no => $x) { $od = (string)$x['order_date']; if ($od >= $d0 && $od <= $d1 && !isset($seen[$no])) $bad[] = $no . ' is in the HRMS for this day (' . (float)$x['total'] . ', emp ' . (int)$x['employee_id'] . ') but the shop did not send it for this day'; }
        $hc = 0; $ha = 0.0; $hg = 0; $hga = 0.0; foreach ($h as $x) { $od = (string)$x['order_date']; if ($od >= $d0 && $od <= $d1) { $hc++; $ha += (float)$x['total']; if ((int)$x['employee_id'] > 0) { $hg++; $hga += (float)$x['total']; } } }
        $m = function ($v) { return number_format((float)$v, 2, '.', ''); };
        $T[] = ''; $T[] = 'SHOP (orders placed or paid on this day)';
        $T[] = 'TM orders: ' . $c['tm'] . ' | value ' . $m($c['tm_amt']) . ' | money received ' . $m($c['received']);
        $T[] = '  paid full: ' . $c['paid'] . ' / ' . $m($c['paid_amt']) . ' | part paid: ' . $c['part'] . ' / ' . $m($c['part_amt']) . ' | COD nothing paid: ' . $c['cod'] . ' / ' . $m($c['cod_amt']) . ' | not paid (not COD): ' . $c['unpaid'] . ' / ' . $m($c['unpaid_amt']);
        $T[] = 'LINK (temporary, placed this day, NOT counted): ' . $c['link'] . ' / ' . $m($c['link_amt']) . ' | cancelled: ' . $c['cancel'];
        $T[] = ''; $T[] = 'HRMS (same orders)';
        $T[] = 'given to a salesperson: ' . $c['given'] . ' / ' . $m($c['given_amt']) . ' | waiting: ' . $c['wait'] . ' / ' . $m($c['wait_amt']) . ' | not in HRMS: ' . $c['none'] . ' / ' . $m($c['none_amt']);
        $T[] = 'HRMS rows dated this day: ' . $hc . ' / ' . $m($ha) . ' | of them given: ' . $hg . ' / ' . $m($hga);
        $T[] = ''; $T[] = 'PROBLEMS: ' . count($bad); foreach (array_slice($bad, 0, 200) as $b) $T[] = '  ! ' . $b;
        $T[] = ''; $T[] = 'ORDERS: ' . count($lines); foreach (array_slice($lines, 0, 1500) as $l) $T[] = $l; $T[] = 'END';
        json_response(['ok' => true, 'day' => $day, 'text' => implode("\n", $T)]);
    }
    case 'tms.people': {
        $o = []; foreach ($pdo->query("SELECT employee_id, employee_code, full_name FROM employees WHERE status != 'ex' ORDER BY full_name")->fetchAll() as $e) $o[] = ['id' => (int)$e['employee_id'], 'name' => (string)$e['full_name'], 'code' => (string)$e['employee_code']];
        json_response(['people' => $o]);
    }
    case 'tms.list': {
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? (string)$in['to'] : date('Y-m-d'); $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? (string)$in['from'] : date('Y-m-d', time() - 6 * 86400);
        if ($from > $to) { $x = $from; $from = $to; $to = $x; } if ($from < date('Y-m-d', time() - 120 * 86400)) $from = date('Y-m-d', time() - 120 * 86400);
        if (!empty($in['fresh']) && function_exists('hrms_memo_drop')) hrms_memo_drop('tms_orders6:' . $from . ':' . $to);
        try { tms_auto_tick($pdo, 60); } catch (Throwable $e) { error_log('tms auto: ' . $e->getMessage()); }   // 🤖 new orders go to the chat's salesperson before the list is drawn
        [$rows, $err] = tms_orders($pdo, $from, $to); if ($err !== '') json_response(['error' => $err, 'code' => tms_cfg($pdo)['key'] === '' ? 'no_key' : 'shop'], 422);
        tms_cols($pdo); $given = []; $names = employee_names_by_id($pdo);
        if ($rows) { $nos = array_column($rows, 'number'); foreach (array_chunk($nos, 400) as $part) { $q = $pdo->prepare("SELECT manual_order_id, order_no, mp_no, employee_id, order_type, created_by FROM manual_orders WHERE channel = 'TM Store' AND mp_no IN (" . implode(',', array_fill(0, count($part), '?')) . ")"); $q->execute($part); foreach ($q->fetchAll() as $g) { if ((int)$g['employee_id'] > 0) $given[(string)$g['mp_no']] = $g; } } }   // a row with no salesperson = waiting on the Allocation page = not given
        $whyMap = []; if ($rows && tms_auto_table($pdo)) { try { foreach (array_chunk(array_column($rows, 'number'), 400) as $part) { $q = $pdo->prepare("SELECT number, why FROM tms_auto WHERE done = 0 AND number IN (" . implode(',', array_fill(0, count($part), '?')) . ")"); $q->execute($part); foreach ($q->fetchAll() as $g) $whyMap[(string)$g['number']] = (string)$g['why']; } } catch (Throwable $e) { } }
        // 👤 owner (24 Nov): an order nobody has yet (not paid, so the robot waits) shows the salesperson ALREADY chosen — the one who holds the customer's WhatsApp chat, else the last person who wrote to
        //    the customer there (the shop does not say who made the payment link). Query / Campaign is suggested by the usual rule. The person only presses Give; Admin / Sales Head can pick another name.
        $sug = []; try {
            $live = []; foreach ($pdo->query("SELECT employee_id FROM employees WHERE status != 'ex'")->fetchAll(PDO::FETCH_COLUMN) as $x) $live[(int)$x] = 1;
            try { $qc = $pdo->prepare("SELECT c.phone, c.assigned_emp, c.source, c.last_in_at, COALESCE(n.camp_tag, 0) AS camp_tag FROM wa_contacts c LEFT JOIN wa_numbers n ON n.id = c.last_number_id WHERE c.phone = ?"); $qc->execute(['0']); }
            catch (Throwable $e) { $qc = $pdo->prepare("SELECT phone, assigned_emp, source, last_in_at, 0 AS camp_tag FROM wa_contacts WHERE phone = ?"); $qc->execute(['0']); }
            $ql = $pdo->prepare("SELECT emp_id FROM wa_messages WHERE phone = ? AND dir = 'out' AND emp_id > 0 ORDER BY id DESC LIMIT 1"); $left = 300;
            foreach ($rows as $x) { if (isset($given[$x['number']]) || strlen((string)$x['phone']) !== 10 || $left-- <= 0) continue;
                $qc->execute([$x['phone']]); $c = $qc->fetch(PDO::FETCH_ASSOC); if (!$c) continue; $e = (int)$c['assigned_emp']; $how = 'holds the WhatsApp chat';
                if ($e <= 0 || !isset($live[$e])) { $ql->execute([$x['phone']]); $e = (int)$ql->fetchColumn(); $how = 'last wrote to the customer on WhatsApp'; }
                if ($e <= 0 || !isset($live[$e])) continue;
                $ty = 'query'; if (function_exists('order_chat_type')) { try { $ty = order_chat_type($pdo, $c, strtotime((string)$x['placed_at']) ?: time()) === 'campaign' ? 'campaign' : 'query'; } catch (Throwable $e3) { } }
                $sug[$x['number']] = [$e, $ty, $how]; }
        } catch (Throwable $e) { error_log('tms suggest: ' . $e->getMessage()); }
        $sum = ['orders' => count($rows), 'amount' => 0.0, 'given' => 0, 'given_amount' => 0.0];
        foreach ($rows as &$r) { $g = $given[$r['number']] ?? null; $r['emp'] = $g ? (int)$g['employee_id'] : 0; $r['emp_name'] = $g ? (string)($names[(int)$g['employee_id']] ?? '') : ''; $r['type'] = $g ? (string)$g['order_type'] : ''; $r['hrms_no'] = $g ? (string)$g['order_no'] : ''; $r['by'] = $g ? (string)$g['created_by'] : ''; $r['auto'] = $g && (string)$g['created_by'] === 'auto (WhatsApp)'; $r['auto_why'] = $g ? '' : (string)($whyMap[$r['number']] ?? ''); $sg = $g ? null : ($sug[$r['number']] ?? null); $r['sug_emp'] = $sg ? (int)$sg[0] : 0; $r['sug_type'] = $sg ? (string)$sg[1] : ''; $r['sug_how'] = $sg ? (string)$sg[2] : '';
            $sum['amount'] += $r['total']; if ($r['emp'] > 0) { $sum['given']++; $sum['given_amount'] += $r['total']; } } unset($r);
        json_response(['from' => $from, 'to' => $to, 'rows' => $rows, 'sum' => $sum, 'admin' => $isAdm]);
    }
    case 'tms.assign': {   // give ONE shop order to a salesperson (or move it to another one). The order is read again from the shop, so the amount cannot be typed or changed from the browser.
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $no = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['number'] ?? ''))); $emp = (int)($in['employee_id'] ?? 0); $ot = strtolower(trim((string)($in['order_type'] ?? 'query'))); if (!in_array($ot, ['query', 'campaign'], true)) $ot = 'query';
        if ($no === '' || $emp <= 0) json_response(['error' => 'Choose the order and the salesperson.'], 422);
        $eq = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ? AND status != 'ex'"); $eq->execute([$emp]); $en = (string)$eq->fetchColumn(); if ($en === '') json_response(['error' => 'That salesperson was not found.'], 422);
        [$d, $e] = tms_get($pdo, '/orders/' . rawurlencode($no)); if ($e !== '') json_response(['error' => $e], 422); $r = tms_row((array)$d); if ($r['number'] === '' || strtoupper($r['number']) !== $no) json_response(['error' => 'The shop does not have order ' . $no . '.'], 404);
        if (!empty($r['temp'])) json_response(['error' => $no . ' is a payment link (temporary). It counts when the customer pays and the shop gives the order its TM number.'], 422);
        if ($r['total'] <= 0) json_response(['error' => 'Order ' . $no . ' has no amount, so there is nothing to count.'], 422);
        tms_cols($pdo); $by = (string)($auth['username'] ?? ''); $rxd = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'; $dt = preg_match($rxd, (string)($r['pay_time'] ?? '')) ? (string)$r['pay_time'] : (preg_match($rxd, $r['placed_at']) ? $r['placed_at'] : date('Y-m-d H:i:s'));   // the time of the payment
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
        if (tms_auto_table($pdo)) { try { $pdo->prepare("INSERT INTO tms_auto (number, done, why, at) VALUES (?, 1, '', ?) ON DUPLICATE KEY UPDATE done = 1, why = ''")->execute([$no, date('Y-m-d H:i:s')]); } catch (Throwable $e2) { } }   // a person took it back: the robot does not give it again (a paid one waits on the Allocation page)
        if ($q->rowCount() && function_exists('bd_log')) { try { bd_log('order', $no, 'website order taken back (counts for nobody)', (string)($auth['username'] ?? '')); } catch (Throwable $e2) { } }
        json_response(['ok' => true, 'message' => $q->rowCount() ? $no . ' is taken back. It counts for nobody now.' : $no . ' was not given to anyone.']);
    }
    default: json_response(['error' => 'Unknown website-orders action.'], 404);
}
