<?php
/**
 * ORDER FACTS: one row per order with everything we know about it, filled in the background, so any page
 * (Finance, Dispatch, Commission ...) reads one row instead of asking Shopify / Shiprocket again.
 *
 *   payment    pay_ref (KWIK id) · gateway · pay_json (the order's payment transactions, trimmed)
 *   who        emp_id / emp_name (order_allocations), is_direct, order_type, house, order_code, chat owner
 *   commission com_amt · com_pct · share_pct (manual override) · ml · com_day (the business day it was computed for)
 *   shiprocket parcel_id · awb · courier · ship_status · sr_status · sr_at · delivered_at · etd · cod_remit_at
 *   finance    exp_amt (what Finance waits for) · paid_amt · paid_on · disc · closed_kind · refund_status
 *
 * Nothing here is the source of truth: it is a copy, rebuilt from the real tables. A failed step leaves its columns as they were.
 * The cron tick calls of_tick() (dept_dispatch_more.php autoheal); of_get() is what the pages call.
 */

function ofact_ensure(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_facts (
            store_key VARCHAR(20) NOT NULL, shopify_id BIGINT NOT NULL, order_name VARCHAR(60) NOT NULL DEFAULT '', okey VARCHAR(60) NOT NULL DEFAULT '',
            created_at DATETIME NULL, biz_day DATE NULL, total DECIMAL(12,2) NULL, outstanding DECIMAL(12,2) NULL, financial_status VARCHAR(30) NULL, tags TEXT NULL, ml INT NULL,
            customer VARCHAR(120) NULL, phone VARCHAR(30) NULL,
            pay_ref VARCHAR(200) NULL, gateway VARCHAR(80) NULL, pay_json TEXT NULL,
            emp_id INT NULL, emp_name VARCHAR(120) NULL, is_direct TINYINT NULL, order_type VARCHAR(30) NULL, house VARCHAR(30) NULL, order_code VARCHAR(40) NULL, chat_owner VARCHAR(120) NULL,
            com_amt DECIMAL(12,2) NULL, com_pct DECIMAL(6,2) NULL, share_pct DECIMAL(6,2) NULL, com_day DATE NULL, com_at DATETIME NULL,
            parcel_id INT NULL, awb VARCHAR(80) NULL, courier VARCHAR(60) NULL, ship_status VARCHAR(20) NULL, sr_status VARCHAR(60) NULL, sr_at DATETIME NULL, delivered_at DATETIME NULL, etd DATE NULL, cod_remit_at DATETIME NULL,
            exp_amt DECIMAL(12,2) NULL, paid_amt DECIMAL(12,2) NULL, paid_on DATE NULL, disc DECIMAL(12,2) NULL, fin_channel VARCHAR(30) NULL, closed_kind VARCHAR(30) NULL, refund_status VARCHAR(30) NULL,
            refreshed_at DATETIME NULL,
            PRIMARY KEY (store_key, shopify_id), KEY idx_of_name (order_name), KEY idx_of_okey (okey), KEY idx_of_day (biz_day), KEY idx_of_emp (emp_id), KEY idx_of_ref (pay_ref(60)), KEY idx_of_awb (awb)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // ⚡ of_refresh joins on shopify_id + store_key, and store_key has a different collation in the two tables, so the primary key (store_key first) could not be used:
        // every order was compared with every row and one run took 20+ minutes of a full CPU core. An index on the number alone makes it a direct lookup. Added once.
        try { if (function_exists('setting_get') && (string)setting_get($pdo, 'of_idx_sid', '') !== '1') { $q = $pdo->query("SHOW INDEX FROM order_facts WHERE Key_name = 'idx_of_sid'"); if (!($q && $q->fetch())) $pdo->exec("ALTER TABLE order_facts ADD INDEX idx_of_sid (shopify_id)"); setting_set($pdo, 'of_idx_sid', '1'); } } catch (Throwable $e) { error_log('order_facts index: ' . $e->getMessage()); }
        return $ok = true;
    } catch (Throwable $e) { error_log('order_facts: ' . $e->getMessage()); return $ok = false; }
}

/** a short summary of Shopify payment transactions (what the gateway said), at most ~1800 characters */
function of_pay_summary(array $transactions): array
{
    $out = []; $gw = [];
    foreach ($transactions as $t) {
        if (!is_array($t)) continue;
        $g = trim((string)($t['gateway'] ?? '')); if ($g !== '') $gw[$g] = 1;
        $rc = $t['receipt'] ?? null; $rc = is_array($rc) ? json_encode($rc, JSON_UNESCAPED_UNICODE) : (string)$rc;
        $out[] = ['kind' => (string)($t['kind'] ?? ''), 'status' => (string)($t['status'] ?? ''), 'gateway' => $g, 'amount' => (string)($t['amount'] ?? ''), 'at' => substr((string)($t['created_at'] ?? ''), 0, 19),
            'auth' => mb_substr((string)($t['authorization'] ?? ''), 0, 80), 'payment_id' => mb_substr((string)($t['payment_id'] ?? ''), 0, 80), 'receipt' => mb_substr($rc, 0, 300)];
    }
    $j = json_encode($out, JSON_UNESCAPED_UNICODE); while (strlen($j) > 1800 && count($out) > 1) { array_pop($out); $j = json_encode($out, JSON_UNESCAPED_UNICODE); }
    return ['gateway' => mb_substr(implode(', ', array_keys($gw)), 0, 80), 'json' => $j];
}
/** save the payment details read from Shopify for one order (called by dp_dsp_kwik_run) */
function of_save_pay(PDO $pdo, string $store, $sid, array $transactions): void
{
    if (!ofact_ensure($pdo)) return;
    $s = of_pay_summary($transactions);
    try { $pdo->prepare("INSERT INTO order_facts (store_key, shopify_id, gateway, pay_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE gateway = VALUES(gateway), pay_json = VALUES(pay_json)")->execute([$store, (int)$sid, $s['gateway'], $s['json']]); } catch (Throwable $e) {}
}

/** Step 1: copy the cheap facts (database only, no outside calls) for up to $limit orders, the ones not refreshed for the longest. */
function of_refresh(PDO $pdo, int $limit = 400, int $days = 400): int
{
    if (!ofact_ensure($pdo)) return 0;
    $cut = date('Y-m-d', time() - $days * 86400) . ' 00:00:00';
    $q = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(20000) */ so.shopify_id, so.store_key, so.order_name, so.created_at, so.total, so.outstanding, so.financial_status, so.tags, so.ml_total, so.customer, so.phone, so.pay_ref
        FROM shop_orders so LEFT JOIN order_facts f ON f.shopify_id = so.shopify_id AND f.store_key = so.store_key COLLATE utf8mb4_unicode_ci
        WHERE so.created_at >= ? AND (f.refreshed_at IS NULL OR f.refreshed_at < so.synced_at OR f.refreshed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
        ORDER BY (f.refreshed_at IS NULL) DESC, so.created_at DESC LIMIT " . (int)$limit);
    try { $q->execute([$cut]); $rows = $q->fetchAll(); } catch (Throwable $e) {
        try { $q = $pdo->prepare("SELECT shopify_id, store_key, order_name, created_at, total, outstanding, financial_status, tags, ml_total, customer, phone, pay_ref FROM shop_orders WHERE created_at >= ? ORDER BY created_at DESC LIMIT " . (int)$limit); $q->execute([$cut]); $rows = $q->fetchAll(); } catch (Throwable $e2) { return 0; }
    }
    if (!$rows) return 0;
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['shopify_id'], $rows))); $in = implode(',', array_fill(0, count($ids), '?'));
    $okeys = array_values(array_unique(array_filter(array_map(fn($r) => strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$r['order_name'])), $rows)))); $inK = $okeys ? implode(',', array_fill(0, count($okeys), '?')) : "''";
    $emp = []; try { foreach ($pdo->query("SELECT employee_id, full_name FROM employees")->fetchAll() as $e) $emp[(int)$e['employee_id']] = (string)$e['full_name']; } catch (Throwable $e) {}
    $alloc = []; try { $s = $pdo->prepare("SELECT source, order_id, employee_id, is_direct, order_type, house FROM order_allocations WHERE order_id IN ($in)"); $s->execute($ids); foreach ($s->fetchAll() as $a) $alloc[$a['source'] . '|' . $a['order_id']] = $a; } catch (Throwable $e) {}
    $codes = []; try { $s = $pdo->prepare("SELECT source, order_id, code FROM order_codes WHERE order_id IN ($in)"); $s->execute($ids); foreach ($s->fetchAll() as $a) $codes[$a['source'] . '|' . $a['order_id']] = (string)$a['code']; } catch (Throwable $e) {}
    $share = []; try { foreach ($pdo->query("SELECT order_key, share_pct FROM commission_order_share")->fetchAll() as $a) $share[$a['order_key']] = (float)$a['share_pct']; } catch (Throwable $e) {}
    $par = []; try { $s = $pdo->prepare("SELECT id, store_key, shopify_id, tracking_no, courier, status, sr_status, sr_at, delivered_at, etd, remit_at FROM dp_dsp_parcels WHERE shopify_id IN ($in)"); $s->execute($ids); foreach ($s->fetchAll() as $a) $par[$a['store_key'] . '|' . $a['shopify_id']] = $a; } catch (Throwable $e) {}
    $set = []; try { $s = $pdo->prepare("SELECT order_key, channel, SUM(amount) a, MAX(rdate) d, SUM(disc) dc FROM fi_settled WHERE order_key IN ($inK) GROUP BY order_key, channel"); $s->execute($okeys); foreach ($s->fetchAll() as $a) { $set[$a['order_key']] = ($set[$a['order_key']] ?? ['a' => 0, 'd' => '', 'dc' => 0, 'ch' => '']); $set[$a['order_key']]['a'] += (float)$a['a']; $set[$a['order_key']]['dc'] += (float)$a['dc']; if ($a['d'] > $set[$a['order_key']]['d']) $set[$a['order_key']]['d'] = (string)$a['d']; $set[$a['order_key']]['ch'] = (string)$a['channel']; } } catch (Throwable $e) {}
    $cls = []; try { $s = $pdo->prepare("SELECT order_key, kind, refund_status FROM fi_close WHERE status = 'ok' AND order_key IN ($inK)"); $s->execute($okeys); foreach ($s->fetchAll() as $a) $cls[$a['order_key']] = $a; } catch (Throwable $e) {}
    $chat = []; try { $ph = array_values(array_unique(array_filter(array_map(fn($r) => substr(preg_replace('/\D+/', '', (string)$r['phone']), -10), $rows)))); if ($ph) { $s = $pdo->prepare("SELECT phone, assigned_emp FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($ph), '?')) . ")"); $s->execute($ph); foreach ($s->fetchAll() as $a) $chat[$a['phone']] = (int)$a['assigned_emp']; } } catch (Throwable $e) {}
    $up = $pdo->prepare("INSERT INTO order_facts (store_key, shopify_id, order_name, okey, created_at, biz_day, total, outstanding, financial_status, tags, ml, customer, phone, pay_ref,
            emp_id, emp_name, is_direct, order_type, house, order_code, chat_owner, share_pct,
            parcel_id, awb, courier, ship_status, sr_status, sr_at, delivered_at, etd, cod_remit_at,
            exp_amt, paid_amt, paid_on, disc, fin_channel, closed_kind, refund_status, refreshed_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?, NOW())
        ON DUPLICATE KEY UPDATE order_name = VALUES(order_name), okey = VALUES(okey), created_at = VALUES(created_at), biz_day = VALUES(biz_day), total = VALUES(total), outstanding = VALUES(outstanding), financial_status = VALUES(financial_status),
            tags = VALUES(tags), ml = VALUES(ml), customer = VALUES(customer), phone = VALUES(phone), pay_ref = IF(VALUES(pay_ref) = '' AND pay_ref IS NOT NULL AND pay_ref <> '', pay_ref, VALUES(pay_ref)),
            emp_id = VALUES(emp_id), emp_name = VALUES(emp_name), is_direct = VALUES(is_direct), order_type = VALUES(order_type), house = VALUES(house), order_code = VALUES(order_code), chat_owner = VALUES(chat_owner), share_pct = VALUES(share_pct),
            parcel_id = VALUES(parcel_id), awb = VALUES(awb), courier = VALUES(courier), ship_status = VALUES(ship_status), sr_status = VALUES(sr_status), sr_at = VALUES(sr_at), delivered_at = VALUES(delivered_at), etd = VALUES(etd), cod_remit_at = VALUES(cod_remit_at),
            exp_amt = VALUES(exp_amt), paid_amt = VALUES(paid_amt), paid_on = VALUES(paid_on), disc = VALUES(disc), fin_channel = VALUES(fin_channel), closed_kind = VALUES(closed_kind), refund_status = VALUES(refund_status), refreshed_at = NOW()");
    $n = 0;
    foreach ($rows as $r) {
        $k = $r['store_key'] . '|' . $r['shopify_id']; $ok = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$r['order_name']));
        $a = $alloc[$k] ?? null; $p = $par[$k] ?? null; $f = $set[$ok] ?? null; $c = $cls[$ok] ?? null;
        $tot = (float)$r['total']; $out = $r['outstanding'] !== null ? (float)$r['outstanding'] : null;
        $exp = $out !== null ? max(0.0, min($tot, $out)) : null;      // the COD part; the prepaid part is total - this
        $day = function_exists('biz_day_of') ? biz_day_of($pdo, strtotime((string)$r['created_at'])) : substr((string)$r['created_at'], 0, 10);
        $eid = $a ? (int)$a['employee_id'] : null; $chatE = $chat[substr(preg_replace('/\D+/', '', (string)$r['phone']), -10)] ?? 0;
        try {
            $up->execute([$r['store_key'], (int)$r['shopify_id'], mb_substr((string)$r['order_name'], 0, 60), mb_substr($ok, 0, 60), $r['created_at'], $day, $tot, $out, $r['financial_status'], $r['tags'], $r['ml_total'], mb_substr((string)$r['customer'], 0, 120), mb_substr((string)$r['phone'], 0, 30), $r['pay_ref'],
                $eid ?: null, $eid ? ($emp[$eid] ?? '') : '', $a ? (int)$a['is_direct'] : null, $a ? (string)$a['order_type'] : null, $a ? (string)$a['house'] : null, $codes[$k] ?? null, $chatE ? ($emp[$chatE] ?? '') : '', $share[$r['store_key'] . ':' . $r['shopify_id']] ?? null,
                $p ? (int)$p['id'] : null, $p ? (string)$p['tracking_no'] : null, $p ? (string)$p['courier'] : null, $p ? (string)$p['status'] : null, $p ? (string)$p['sr_status'] : null, $p['sr_at'] ?? null, $p['delivered_at'] ?? null, $p['etd'] ?? null, $p['remit_at'] ?? null,
                $exp, $f ? round($f['a'], 2) : null, $f && $f['d'] !== '' ? $f['d'] : null, $f ? round($f['dc'], 2) : null, $f ? $f['ch'] : null, $c ? (string)$c['kind'] : null, $c ? (string)$c['refund_status'] : null]);
            $n++;
        } catch (Throwable $e) { error_log('of_refresh: ' . $e->getMessage()); break; }
    }
    return $n;
}

/** Step 2: the commission of every order of ONE business day, from the same calculation the Coach / Commission pages use. Past days are kept as they were calculated (com_day = the day). */
function of_commission_day(PDO $pdo, string $day): int
{
    if (!ofact_ensure($pdo) || !function_exists('coach_day')) return 0;
    $d = coach_day($pdo, $day, false); $n = 0; $now = date('Y-m-d H:i:s');
    $q = $pdo->prepare("UPDATE order_facts SET com_amt = ?, com_pct = ?, com_day = ?, com_at = ? WHERE store_key = ? AND shopify_id = ?");
    foreach ((array)($d['emps'] ?? []) as $m) foreach ((array)($m['order_com'] ?? []) as $key => $amt) {
        $x = explode('|', (string)$key, 2); if (count($x) !== 2) continue;
        $t = $pdo->prepare("SELECT total FROM order_facts WHERE store_key = ? AND shopify_id = ?"); $t->execute([$x[0], (int)$x[1]]); $tot = (float)$t->fetchColumn();
        $q->execute([round((float)$amt, 2), $tot > 0 ? round((float)$amt / $tot * 100, 2) : null, $day, $now, $x[0], (int)$x[1]]); $n += $q->rowCount();
    }
    return $n;
}
/** which business day to calculate next: today and yesterday every time (they still change), then one older day per run that has orders without a commission yet */
function of_commission_next(PDO $pdo): array
{
    $days = [];
    try { $t = biz_today($pdo); $days[] = $t; $days[] = date('Y-m-d', strtotime($t . ' -1 day')); } catch (Throwable $e) {}
    try { $x = $pdo->query("SELECT biz_day FROM order_facts WHERE emp_id IS NOT NULL AND com_at IS NULL AND biz_day IS NOT NULL ORDER BY biz_day DESC LIMIT 1")->fetchColumn(); if ($x) $days[] = (string)$x; } catch (Throwable $e) {}
    return array_values(array_unique($days));
}

/** the cron part: facts (fast), then commission days (slow, time boxed). Returns what it did. */
function of_tick(PDO $pdo, int $now, float $budget = 10.0): array
{
    $out = ['facts' => 0, 'com_days' => 0, 'error' => ''];
    if (!ofact_ensure($pdo)) { $out['error'] = 'order_facts table could not be made'; return $out; }
    $t0 = microtime(true);
    try { $out['facts'] = of_refresh($pdo, 400); } catch (Throwable $e) { $out['error'] = 'facts: ' . $e->getMessage(); }
    foreach (of_commission_next($pdo) as $day) {
        if (microtime(true) - $t0 > $budget) break;
        try { of_commission_day($pdo, $day); $out['com_days']++; } catch (Throwable $e) { $out['error'] = 'commission ' . $day . ': ' . $e->getMessage(); }
    }
    return $out;
}

/** one order, by name (#TMPH-1234 / 1234), Shopify id, AWB or KWIK id. Returns the row (with pay_json decoded) or null. */
function of_get(PDO $pdo, string $q): ?array
{
    if (!ofact_ensure($pdo)) return null; $q = trim($q); if ($q === '') return null; $ok = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $q));
    foreach ([["SELECT * FROM order_facts WHERE okey = ? ORDER BY created_at DESC LIMIT 1", $ok], ["SELECT * FROM order_facts WHERE shopify_id = ? LIMIT 1", ctype_digit($q) ? (int)$q : -1], ["SELECT * FROM order_facts WHERE awb = ? LIMIT 1", $q], ["SELECT * FROM order_facts WHERE pay_ref LIKE ? LIMIT 1", '%' . $q . '%']] as $s) {
        try { $st = $pdo->prepare($s[0]); $st->execute([$s[1]]); $r = $st->fetch(); } catch (Throwable $e) { continue; }
        if ($r) { $r['pay'] = json_decode((string)($r['pay_json'] ?? ''), true) ?: []; unset($r['pay_json']); return $r; }
    }
    return null;
}
/** how much of the copy is filled: for the health line */
function of_stats(PDO $pdo): array
{
    try { $x = $pdo->query("SELECT COUNT(*) n, SUM(pay_ref <> '') kwik, SUM(pay_json IS NOT NULL) pay, SUM(emp_id IS NOT NULL) emp, SUM(com_at IS NOT NULL) com, SUM(awb <> '') awb, SUM(paid_amt IS NOT NULL) paid FROM order_facts")->fetch(); return array_map('intval', $x ?: []); } catch (Throwable $e) { return []; }
}
