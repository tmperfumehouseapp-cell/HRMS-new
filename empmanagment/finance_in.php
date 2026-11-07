<?php
/**
 * finance_in.php - Finance, STEP 1: money IN, per channel and per day, and what is still OUTSTANDING.   (actions "fi.*", loaded by api.php)
 *
 * The idea
 *   Every day, for every channel, three numbers:  BOOKED (what the sales say should come)  ·  RECEIVED (what really reached a bank / cash box)
 *   ·  OUTSTANDING (booked - received, from the first day of the books).  A receipt is applied to the oldest outstanding day first (FIFO),
 *   so nobody has to say "this money is for that day": the old days simply fill up first. Settlement is never a deadline here: money that
 *   comes late just shows as outstanding with its age.
 *
 * Channels
 *   store_cash    cash taken at the store counter (new billing, pay mode cash / the cash part of a split)   - "received" = cash banked / counted into a cash box
 *   store_online  UPI / card taken at the store counter (new billing)                                       - "received" = it reached a bank
 *   online_prepaid  online orders paid in advance (Shopify total minus the COD balance)                     - "received" = gateway settlement
 *   online_cod    online orders paid on delivery (the COD balance)                                           - "received" = courier remittance
 *   retail        dealer money: booked by hand (or from the Retail section later)
 *   other         anything else, booked by hand
 *
 * Own tables: fi_accounts (the 5-6 banks / cash boxes), fi_receipts (every receipt, numbered RC-000001), fi_manual (not used any more: there is no old outstanding),
 * fi_uploads (an Excel / CSV read by Claude, waiting for confirmation), fi_settled (an order that a file proved as paid).
 * Nothing is ever deleted: a wrong receipt is VOIDED (kept, greyed, with the reason). PHP 7.4 safe.
 * Permission: the "Finance" tick of the role (api.php action_permission: 'fi.' => 'finance').
 */
if (!isset($pdo, $action) || !function_exists('json_response') || !function_exists('require_auth')) { http_response_code(403); exit; }
if (strpos((string)$action, 'fi.') !== 0) { return; }

const FI_CHANNELS = [
    'store_cash' => ['Store · cash', 'Cash taken at the store counter'],
    'store_online' => ['Store · UPI / card', 'UPI and card taken at the store counter'],
    'online_prepaid' => ['Online · prepaid', 'Online orders paid in advance (gateway settlement)'],
    'online_cod' => ['Online · COD', 'Cash on delivery, remitted by the courier'],
    'retail' => ['Retail · dealers', 'Money from dealers and shops'],
    'other' => ['Other', 'Anything else that comes in'],
];

function fi_ensure(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    $flag = sys_get_temp_dir() . '/hrms_fi_tables_v1_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return $ok = fi_ensure4($pdo);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fi_accounts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, kind VARCHAR(8) NOT NULL DEFAULT 'bank', last4 VARCHAR(8) NOT NULL DEFAULT '', active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fi_receipts (id INT AUTO_INCREMENT PRIMARY KEY, receipt_no VARCHAR(14) NOT NULL DEFAULT '', rdate DATE NOT NULL, account_id INT NOT NULL DEFAULT 0, account_name VARCHAR(80) NOT NULL DEFAULT '', channel VARCHAR(16) NOT NULL, amount DECIMAL(14,2) NOT NULL,
            ref VARCHAR(120) NOT NULL DEFAULT '', payer VARCHAR(120) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '', source VARCHAR(8) NOT NULL DEFAULT 'manual', upload_id INT NOT NULL DEFAULT 0, status VARCHAR(6) NOT NULL DEFAULT 'ok', void_reason VARCHAR(200) NOT NULL DEFAULT '',
            created_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY idx_fi_r_day (rdate, status), KEY idx_fi_r_ch (channel, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fi_manual (id INT AUTO_INCREMENT PRIMARY KEY, day DATE NOT NULL, channel VARCHAR(16) NOT NULL, amount DECIMAL(14,2) NOT NULL, kind VARCHAR(8) NOT NULL DEFAULT 'manual', note VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(6) NOT NULL DEFAULT 'ok', created_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY idx_fi_m (channel, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fi_uploads (id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(200) NOT NULL DEFAULT '', analysis MEDIUMTEXT NULL, rows_json MEDIUMTEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'preview', created_by VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fi_settled (id INT AUTO_INCREMENT PRIMARY KEY, order_key VARCHAR(40) NOT NULL, channel VARCHAR(16) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, rdate DATE NOT NULL, receipt_id INT NOT NULL DEFAULT 0, UNIQUE KEY uq_fi_set (order_key, channel), KEY idx_fi_set_r (receipt_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fi_settings (k VARCHAR(30) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('fi_ensure: ' . $e->getMessage()); return $ok = false; }
    $ok = fi_ensure2($pdo); return $ok = fi_ensure4($pdo) && $ok;
}
/** v4: the prepaid discount on a proof row, receipts that are not set against booked (untracked), gateway charges and the Pine Labs flags.
 *  Kept apart from the first step because that one stops early on a server that already has its tables (that is what made "Unknown column untracked"). Safe to run again. */
function fi_ensure4(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fi_tables_v4_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    $ok = true;
    $do = function (string $sql) use ($pdo, &$ok) { try { $pdo->exec($sql); } catch (Throwable $e) { $ok = false; error_log('fi_ensure4: ' . $e->getMessage()); } };
    $col = function (string $table, string $c) use ($pdo) { try { return (bool)$pdo->query("SHOW COLUMNS FROM $table LIKE '$c'")->fetch(); } catch (Throwable $e) { return false; } };
    if (!$col('fi_settled', 'disc')) $do("ALTER TABLE fi_settled ADD COLUMN disc DECIMAL(12,2) NOT NULL DEFAULT 0");      // the prepaid discount lost on the order (expected - received)
    if (!$col('fi_receipts', 'untracked')) $do("ALTER TABLE fi_receipts ADD COLUMN untracked TINYINT NOT NULL DEFAULT 0");      // 1 = money for older orders / with no order id: real money in the bank, never set against the booked amount
    $do("CREATE TABLE IF NOT EXISTS fi_pine_flags (id INT AUTO_INCREMENT PRIMARY KEY, tx_key CHAR(32) NOT NULL, receipt_id INT NOT NULL DEFAULT 0, upload_id INT NOT NULL DEFAULT 0, tx_day DATE NOT NULL, amount DECIMAL(14,2) NOT NULL, order_ref VARCHAR(60) NOT NULL DEFAULT '', why VARCHAR(12) NOT NULL, status VARCHAR(8) NOT NULL DEFAULT 'open', note VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, UNIQUE KEY uq_fi_pf (tx_key), KEY idx_fi_pf_s (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $do("CREATE TABLE IF NOT EXISTS fi_seen_rows (row_key CHAR(32) NOT NULL PRIMARY KEY, receipt_id INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");      // rows saved without an order (so a second upload of the same file is not counted twice)
    $do("CREATE TABLE IF NOT EXISTS fi_charges (id INT AUTO_INCREMENT PRIMARY KEY, receipt_id INT NOT NULL, rdate DATE NOT NULL, channel VARCHAR(16) NOT NULL, provider VARCHAR(60) NOT NULL DEFAULT '', amount DECIMAL(14,2) NOT NULL, note VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY idx_fi_c_r (receipt_id), KEY idx_fi_c_d (rdate)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($ok) @file_put_contents($flag, '1');
    return true;
}
/** v2: closed parcels (RTO / not sent / lost, with the refund owed to the customer) and Claude's note on a receipt. */
function fi_ensure2(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fi_tables_v2_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fi_close (id INT AUTO_INCREMENT PRIMARY KEY, order_key VARCHAR(40) NOT NULL, order_name VARCHAR(40) NOT NULL DEFAULT '', channel VARCHAR(16) NOT NULL, day DATE NOT NULL, amount DECIMAL(12,2) NOT NULL, kind VARCHAR(10) NOT NULL, resolution VARCHAR(8) NOT NULL DEFAULT 'none',
            refund_status VARCHAR(6) NOT NULL DEFAULT 'none', refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0, refund_date DATE NULL, refund_account_id INT NOT NULL DEFAULT 0, refund_ref VARCHAR(120) NOT NULL DEFAULT '', customer VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(6) NOT NULL DEFAULT 'ok', closed_by VARCHAR(80) NOT NULL DEFAULT '', closed_at DATETIME NOT NULL, KEY idx_fi_c (channel, status, day), KEY idx_fi_ck (order_key, channel)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$pdo->query("SHOW COLUMNS FROM fi_receipts LIKE 'claude_note'")->fetch()) $pdo->exec("ALTER TABLE fi_receipts ADD COLUMN claude_note VARCHAR(500) NOT NULL DEFAULT ''");
        @file_put_contents($flag, '1');
    } catch (Throwable $e) { error_log('fi_ensure2: ' . $e->getMessage()); return false; }
    return fi_ensure3($pdo);
}
/** v3: an order may be paid in several parts (several files): one proof row per order AND receipt. */
function fi_ensure3(PDO $pdo): bool
{
    $flag = sys_get_temp_dir() . '/hrms_fi_tables_v3_' . substr(md5(__DIR__), 0, 8);
    if (is_file($flag)) return true;
    try {
        $has = $pdo->query("SHOW INDEX FROM fi_settled WHERE Key_name = 'uq_fi_set'")->fetch();
        if ($has) { $pdo->exec("ALTER TABLE fi_settled DROP INDEX uq_fi_set"); }
        if (!$pdo->query("SHOW INDEX FROM fi_settled WHERE Key_name = 'uq_fi_set3'")->fetch()) $pdo->exec("ALTER TABLE fi_settled ADD UNIQUE KEY uq_fi_set3 (order_key, channel, receipt_id)");
        @file_put_contents($flag, '1'); return true;
    } catch (Throwable $e) { error_log('fi_ensure3: ' . $e->getMessage()); return false; }
}

function fi_p($x): int { return (int)round(((float)$x) * 100); }          // rupees -> paise: all sums are integer paise
function fi_m(int $p): float { return round($p / 100, 2); }
function fi_day($s): string { $s = trim((string)$s); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return ''; $t = strtotime($s); return ($t && date('Y-m-d', $t) === $s) ? $s : ''; }
function fi_today(PDO $pdo): string { return function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); }
function fi_who($auth): string { return (string)($auth['username'] ?? $auth['name'] ?? ('user ' . ($auth['user_id'] ?? ''))); }
function fi_err(string $m, int $c = 422) { json_response(['error' => $m], $c); }
function fi_set(PDO $pdo, string $k, string $d = ''): string { try { $q = $pdo->prepare("SELECT v FROM fi_settings WHERE k = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; } }
/** The day tracking STARTS: orders before it are never booked and a file row for such an order is not counted. Empty = not chosen yet. */
function fi_track_from(PDO $pdo): string
{
    $v = fi_set($pdo, 'track_from', ''); if ($v !== '') return $v;
    $v = fi_today($pdo);      // never chosen: the books start TODAY (older orders are not tracked); it can be changed in the settings
    try { $pdo->prepare("INSERT INTO fi_settings (k, v) VALUES ('track_from', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$v]); } catch (Throwable $e) {}
    return $v;
}
/** The day the STORE books start (store bills before it are not booked). Not chosen yet = the day after the online start day (online starts today, the store starts tomorrow). */
function fi_track_from_store(PDO $pdo): string
{
    $v = fi_set($pdo, 'track_from_store', ''); if ($v !== '') return $v;
    $v = date('Y-m-d', strtotime(fi_track_from($pdo) . ' +1 day'));
    try { $pdo->prepare("INSERT INTO fi_settings (k, v) VALUES ('track_from_store', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$v]); } catch (Throwable $e) {}
    return $v;
}
function fi_wait_days(PDO $pdo): int { return max(1, min(60, (int)fi_set($pdo, 'wait_days', '10'))); }
/** Days a PAID / closed parcel stays on the Dispatch screen before it is tucked away (open issues are never tucked away). */
function fi_keep_days(PDO $pdo): int { return max(7, min(60, (int)fi_set($pdo, 'keep_days', '20'))); }
function fi_auto_remit(PDO $pdo): bool { return fi_set($pdo, 'auto_remit', '1') === '1'; }
/** First day of the books: the tracking start day or the oldest "old outstanding", whichever is earlier; else 30 days back. */
function fi_start(PDO $pdo): string
{
    $c = []; $t = fi_track_from($pdo); if ($t !== '') $c[] = $t; $ts = fi_track_from_store($pdo); if ($ts !== '') $c[] = $ts;
    return $c ? min($c) : date('Y-m-d', strtotime(fi_today($pdo) . ' -30 day'));
}

/* ============================== BOOKED: what the sales say should come, per channel and day ============================== */
function fi_booked(PDO $pdo, string $from, string $to): array
{
    $out = []; foreach (array_keys(FI_CHANNELS) as $c) $out[$c] = [];
    $add = function ($ch, $day, $p, $n = 1) use (&$out) { if ($p === 0 && $n === 0) return; if (!isset($out[$ch][$day])) $out[$ch][$day] = [0, 0]; $out[$ch][$day][0] += $p; $out[$ch][$day][1] += $n; };
    $tf = fi_track_from($pdo); $oFrom = ($tf !== '' && $tf > $from) ? $tf : $from; $tsf = fi_track_from_store($pdo); $sFrom = ($tsf !== '' && $tsf > $from) ? $tsf : $from;      // sales before the tracking start day are not booked (there is no old outstanding: the books start clean)
    try {      // the new store billing: the STORE day (3 AM to 3 AM)
        $q = $pdo->prepare("SELECT biz_date, pay_mode, total, cash_amt, online_amt FROM st_bills WHERE status = 'ok' AND biz_date BETWEEN ? AND ?"); $q->execute([$sFrom, $to]);
        foreach ($q->fetchAll() as $b) {
            if ($b['pay_mode'] === 'cash') $add('store_cash', $b['biz_date'], fi_p($b['total']));
            elseif ($b['pay_mode'] === 'split') { $add('store_cash', $b['biz_date'], fi_p($b['cash_amt'])); $add('store_online', $b['biz_date'], fi_p($b['online_amt'])); }
            else $add('store_online', $b['biz_date'], fi_p($b['total']));
        }
    } catch (Throwable $e) { /* the store tables are not here yet */ }
    try {      // online orders: the HRMS day (10 AM to 10 AM). Prepaid = total minus the COD balance.
        $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
        $q = $pdo->prepare("SELECT shopify_id, store_key, created_at, total, financial_status, outstanding FROM shop_orders WHERE created_at >= ? AND created_at < ?");
        $q->execute([date('Y-m-d', strtotime($oFrom . ' -1 day')) . ' 00:00:00', date('Y-m-d', strtotime($to . ' +2 day')) . ' 00:00:00']);
        foreach ($q->fetchAll() as $o) {
            $fs = strtolower((string)$o['financial_status']); if (in_array($fs, ['voided', 'refunded'], true)) continue;
            if (isset($cancel[$o['store_key'] . '|' . $o['shopify_id']])) continue;
            $d = function_exists('biz_day_of') ? biz_day_of($pdo, strtotime((string)$o['created_at'])) : substr((string)$o['created_at'], 0, 10);
            if ($d < $oFrom || $d > $to) continue;
            $tot = fi_p($o['total']); $cod = $o['outstanding'] !== null ? fi_p($o['outstanding']) : (in_array($fs, ['paid', 'partially_refunded'], true) ? 0 : $tot);
            $cod = max(0, min($tot, $cod)); $pre = $tot - $cod;
            if ($pre > 0) $add('online_prepaid', $d, $pre); if ($cod > 0) $add('online_cod', $d, $cod);
        }
    } catch (Throwable $e) { /* no Shopify cache here */ }
    try {      // parcels closed as returned / not sent / lost: their booked money is not expected any more
        $q = $pdo->prepare("SELECT channel, day, SUM(amount) a, COUNT(*) n FROM fi_close WHERE status = 'ok' AND day BETWEEN ? AND ? GROUP BY channel, day"); $q->execute([$oFrom, $to]);
        foreach ($q->fetchAll() as $c) if (isset($out[$c['channel']])) $add($c['channel'], $c['day'], -fi_p($c['a']), -(int)$c['n']);
    } catch (Throwable $e) {}
    return $out;
}

/** Receipts per channel up to a day: [channel => paise], and per day [channel][day] => paise. */
function fi_received(PDO $pdo, string $from, string $to): array
{
    $tot = []; $byDay = [];
    $q = $pdo->prepare("SELECT channel, rdate, SUM(amount) a FROM fi_receipts WHERE status = 'ok' AND untracked = 0 AND rdate BETWEEN ? AND ? GROUP BY channel, rdate"); $q->execute([$from, $to]);
    foreach ($q->fetchAll() as $r) { $p = fi_p($r['a']); $tot[$r['channel']] = ($tot[$r['channel']] ?? 0) + $p; $byDay[$r['channel']][$r['rdate']] = $p; }
    return [$tot, $byDay];
}

/** One channel: booked / received / outstanding up to $to, with the oldest days still open (FIFO: money fills the oldest day first). */
function fi_channel_state(array $bookedCh, int $receivedP, string $to): array
{
    ksort($bookedCh); $bookedP = 0; $n = 0; foreach ($bookedCh as $d => $v) { $bookedP += $v[0]; $n += $v[1]; }
    $left = $receivedP; $open = [];
    foreach ($bookedCh as $d => $v) {
        $a = $v[0]; if ($a <= 0) continue; $pay = min($left, $a); $left -= $pay;
        if ($a - $pay > 0) $open[] = ['day' => $d, 'booked' => fi_m($a), 'pending' => fi_m($a - $pay), 'orders' => $v[1], 'age' => max(0, (int)floor((strtotime($to) - strtotime($d)) / 86400))];
    }
    return ['booked' => fi_m($bookedP), 'received' => fi_m($receivedP), 'outstanding' => fi_m($bookedP - $receivedP), 'advance' => $left > 0 ? fi_m($left) : 0, 'open_days' => $open, 'oldest_age' => $open ? $open[0]['age'] : 0, 'orders' => $n];
}

function fi_receipt_row(array $r): array
{
    return ['id' => (int)$r['id'], 'no' => $r['receipt_no'], 'date' => $r['rdate'], 'account_id' => (int)$r['account_id'], 'account' => $r['account_name'], 'channel' => $r['channel'], 'channel_label' => FI_CHANNELS[$r['channel']][0] ?? $r['channel'],
        'amount' => (float)$r['amount'], 'ref' => $r['ref'], 'payer' => $r['payer'], 'note' => $r['note'], 'source' => $r['source'], 'status' => $r['status'], 'void_reason' => $r['void_reason'], 'claude_note' => (string)($r['claude_note'] ?? ''), 'by' => $r['created_by'], 'at' => $r['created_at']];
}

/**
 * The money story of some orders (by order name), for the Dispatch rows. One answer per order:
 *   st: paid | part | unpaid | refund_due | closed | untracked (placed before the tracking start day) | none (nothing is expected)
 *   exp / paid / paid_on, chs = the channels that expect money (online_cod, online_prepaid), age in days, hide = may be tucked away (paid / closed / untracked and older than keep_days).
 */
function fi_orders_info(PDO $pdo, array $names): array
{
    $names = array_values(array_unique(array_filter(array_map('trim', $names)))); if (!$names) return [];
    $tf = fi_track_from($pdo); $keep = fi_keep_days($pdo); $today = fi_today($pdo); $out = []; $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
    $ph = implode(',', array_fill(0, count($names), '?'));
    try {
        $q = $pdo->prepare("SELECT shopify_id, store_key, order_name, created_at, total, financial_status, outstanding FROM shop_orders WHERE order_name IN ($ph)"); $q->execute($names); $rows = $q->fetchAll();
    } catch (Throwable $e) { return []; }
    $keys = []; foreach ($rows as $o) $keys[] = fi_okey($o['order_name']); if (!$keys) return [];
    $kp = implode(',', array_map(fn($k) => $pdo->quote($k), array_unique($keys)));
    $paid = []; $paidOn = []; $closed = [];
    try { foreach ($pdo->query("SELECT order_key, channel, SUM(amount) a, MAX(rdate) d FROM fi_settled WHERE order_key IN ($kp) GROUP BY order_key, channel")->fetchAll() as $x) { $paid[$x['order_key']][$x['channel']] = (float)$x['a']; if (!isset($paidOn[$x['order_key']]) || $x['d'] > $paidOn[$x['order_key']]) $paidOn[$x['order_key']] = $x['d']; } } catch (Throwable $e) {}
    try { foreach ($pdo->query("SELECT order_key, channel, kind, refund_status, refund_amount, id FROM fi_close WHERE status = 'ok' AND order_key IN ($kp)")->fetchAll() as $x) $closed[$x['order_key']][] = $x; } catch (Throwable $e) {}
    foreach ($rows as $o) {
        $k = fi_okey($o['order_name']); $fs = strtolower((string)$o['financial_status']);
        $d = function_exists('biz_day_of') ? biz_day_of($pdo, strtotime((string)$o['created_at'])) : substr((string)$o['created_at'], 0, 10); $age = max(0, (int)floor((strtotime($today) - strtotime($d)) / 86400));
        $tot = fi_p($o['total']); $cod = $o['outstanding'] !== null ? fi_p($o['outstanding']) : (in_array($fs, ['paid', 'partially_refunded'], true) ? 0 : $tot); $cod = max(0, min($tot, $cod)); $pre = $tot - $cod;
        $exp = ['online_cod' => fi_m($cod), 'online_prepaid' => fi_m($pre)]; $chs = array_keys(array_filter($exp, fn($v) => $v > 0)); $expT = array_sum($exp); $got = array_sum($paid[$k] ?? []);
        $st = 'none'; $refund = 0.0; $ckind = '';
        if (in_array($fs, ['voided', 'refunded'], true) || isset($cancel[$o['store_key'] . '|' . $o['shopify_id']])) $st = 'none';
        elseif (!empty($closed[$k])) { $st = 'closed'; foreach ($closed[$k] as $c) { $ckind = $c['kind']; if ($c['refund_status'] === 'due' || $c['refund_status'] === 'held') { $st = 'refund_due'; $refund += (float)$c['refund_amount']; } } }
        elseif ($tf !== '' && $d < $tf) $st = 'untracked';
        elseif ($expT <= 0) $st = 'none';
        elseif ($got >= 0.9 * $expT) $st = 'paid'; elseif ($got > 0) $st = 'part'; else $st = 'unpaid';
        $out[$k] = ['st' => $st, 'exp' => round($expT, 2), 'cod' => $exp['online_cod'], 'pre' => $exp['online_prepaid'], 'paid' => round($got, 2), 'paid_on' => $paidOn[$k] ?? '', 'chs' => $chs, 'age' => $age, 'kind' => $ckind, 'refund' => round($refund, 2), 'hide' => $age >= $keep && in_array($st, ['paid', 'closed', 'untracked', 'none'], true)];
    }
    return $out;
}
/** When a file proves a COD order is paid, tick "Money received" on the Dispatch parcel (the same field a person ticks; only for delivered parcels, never un-ticks). Returns how many were ticked. */
function fi_tick_dispatch(PDO $pdo, array $orderNames, string $receiptNo): int
{
    if (!$orderNames || !fi_auto_remit($pdo)) return 0;
    try {
        if (function_exists('dp_dsp_done_col')) dp_dsp_done_col($pdo);
        $ph = implode(',', array_fill(0, count($orderNames), '?'));
        $u = $pdo->prepare("UPDATE dp_dsp_parcels SET remit_at = ?, remit_by = ? WHERE order_name IN ($ph) AND status = 'delivered' AND remit_at IS NULL"); $u->execute(array_merge([date('Y-m-d H:i:s'), mb_substr('Finance file ' . $receiptNo, 0, 80)], array_values($orderNames)));
        return $u->rowCount();
    } catch (Throwable $e) { error_log('fi_tick_dispatch: ' . $e->getMessage()); return 0; }
}

/**
 * THE SUMMARY SHEET for a period: orders and sales (online + store), average ticket, money received (by channel and bank), what is still outstanding, what was spent and where
 * (Daily Money entries + the ledger's expense accounts + customer refunds paid), and what needs a look. Every number is read from where it already lives; nothing is typed here.
 */
function fi_summary(PDO $pdo, string $from, string $to): array
{
    $today = fi_today($pdo); $out = ['from' => $from, 'to' => $to, 'today' => $today, 'sources' => []];
    $days = []; for ($d = $from, $g = 0; $d <= $to && $g < 400; $d = date('Y-m-d', strtotime($d . ' +1 day')), $g++) $days[$d] = ['day' => $d, 'orders' => 0, 'sales' => 0.0, 'store_bills' => 0, 'store_sales' => 0.0, 'received' => 0.0];
    // ---- online orders (HRMS day)
    $on = ['orders' => 0, 'sales' => 0, 'cod_orders' => 0, 'cod' => 0, 'prepaid' => 0, 'items' => 0]; $lastSync = '';
    try {
        $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
        $q = $pdo->prepare("SELECT shopify_id, store_key, created_at, total, items, financial_status, outstanding, synced_at FROM shop_orders WHERE created_at >= ? AND created_at < ?");
        $q->execute([date('Y-m-d', strtotime($from . ' -1 day')) . ' 00:00:00', date('Y-m-d', strtotime($to . ' +2 day')) . ' 00:00:00']);
        foreach ($q->fetchAll() as $o) {
            $fs = strtolower((string)$o['financial_status']); if (in_array($fs, ['voided', 'refunded'], true) || isset($cancel[$o['store_key'] . '|' . $o['shopify_id']])) continue;
            $d = function_exists('biz_day_of') ? biz_day_of($pdo, strtotime((string)$o['created_at'])) : substr((string)$o['created_at'], 0, 10); if ($d < $from || $d > $to) continue;
            $tot = fi_p($o['total']); $cod = $o['outstanding'] !== null ? fi_p($o['outstanding']) : (in_array($fs, ['paid', 'partially_refunded'], true) ? 0 : $tot); $cod = max(0, min($tot, $cod));
            $on['orders']++; $on['sales'] += $tot; $on['cod'] += $cod; $on['prepaid'] += $tot - $cod; $on['items'] += (int)$o['items']; if ($cod > 0) $on['cod_orders']++;
            if (isset($days[$d])) { $days[$d]['orders']++; $days[$d]['sales'] += fi_m($tot); }
            if ($o['synced_at'] && $o['synced_at'] > $lastSync) $lastSync = (string)$o['synced_at'];
        }
        $out['sources'][] = ['name' => 'Online orders (Shopify)', 'ok' => true, 'note' => $lastSync !== '' ? 'last synced ' . $lastSync : ''];
    } catch (Throwable $e) { $out['sources'][] = ['name' => 'Online orders (Shopify)', 'ok' => false, 'note' => 'not available here']; }
    // ---- store billing (store day)
    $st = ['bills' => 0, 'sales' => 0, 'ml' => 0, 'cash' => 0, 'online' => 0, 'discount' => 0];
    try {
        $q = $pdo->prepare("SELECT biz_date, pay_mode, total, cash_amt, online_amt, total_ml, discount FROM st_bills WHERE status = 'ok' AND biz_date BETWEEN ? AND ?"); $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $b) {
            $st['bills']++; $st['sales'] += fi_p($b['total']); $st['ml'] += (int)$b['total_ml']; $st['discount'] += fi_p($b['discount']);
            if ($b['pay_mode'] === 'cash') $st['cash'] += fi_p($b['total']); elseif ($b['pay_mode'] === 'split') { $st['cash'] += fi_p($b['cash_amt']); $st['online'] += fi_p($b['online_amt']); } else $st['online'] += fi_p($b['total']);
            if (isset($days[$b['biz_date']])) { $days[$b['biz_date']]['store_bills']++; $days[$b['biz_date']]['store_sales'] += (float)$b['total']; }
        }
        $out['sources'][] = ['name' => 'Store billing', 'ok' => true, 'note' => ''];
    } catch (Throwable $e) { $out['sources'][] = ['name' => 'Store billing', 'ok' => false, 'note' => 'not available here']; }
    $out['orders'] = ['online' => ['orders' => $on['orders'], 'sales' => fi_m($on['sales']), 'avg_ticket' => $on['orders'] ? round(fi_m($on['sales']) / $on['orders'], 2) : 0, 'items' => $on['items'], 'cod_orders' => $on['cod_orders'], 'cod_amount' => fi_m($on['cod']), 'prepaid_amount' => fi_m($on['prepaid']), 'cod_pct' => $on['sales'] ? (int)round(100 * $on['cod'] / $on['sales']) : 0],
        'store' => ['bills' => $st['bills'], 'sales' => fi_m($st['sales']), 'avg_ticket' => $st['bills'] ? round(fi_m($st['sales']) / $st['bills'], 2) : 0, 'ml' => $st['ml'], 'cash' => fi_m($st['cash']), 'online' => fi_m($st['online']), 'discount' => fi_m($st['discount'])],
        'total' => ['orders' => $on['orders'] + $st['bills'], 'sales' => fi_m($on['sales'] + $st['sales']), 'avg_ticket' => ($on['orders'] + $st['bills']) ? round(fi_m($on['sales'] + $st['sales']) / ($on['orders'] + $st['bills']), 2) : 0]];
    // ---- money in (Incoming)
    $q = $pdo->prepare("SELECT channel, account_id, account_name, rdate, untracked, SUM(amount) a, COUNT(*) n FROM fi_receipts WHERE status = 'ok' AND rdate BETWEEN ? AND ? GROUP BY channel, account_id, account_name, rdate, untracked"); $q->execute([$from, $to]);
    $byCh = []; $byAcc = []; $recN = 0; $recT = 0; $older = []; $olderT = 0;      // $byCh = money against tracked orders only; $older = money for older / unreferenced orders (in the bank, but not set against the booked amount)
    foreach ($q->fetchAll() as $r) { $p = fi_p($r['a']); if ((int)$r['untracked'] === 1) { $older[$r['channel']] = ($older[$r['channel']] ?? 0) + $p; $olderT += $p; } else $byCh[$r['channel']] = ($byCh[$r['channel']] ?? 0) + $p; $k = (int)$r['account_id']; $byAcc[$k] = ['name' => $r['account_name'], 'p' => ($byAcc[$k]['p'] ?? 0) + $p]; $recT += $p; $recN += (int)$r['n']; if (isset($days[$r['rdate']])) $days[$r['rdate']]['received'] += fi_m($p); }
    $start = min(fi_start($pdo), $from); $booked = fi_booked($pdo, $start, $to); [$recAll] = fi_received($pdo, '2000-01-01', $to); $chans = []; $outTot = 0;
    foreach (FI_CHANNELS as $key => $meta) {
        $bp = 0; foreach ($booked[$key] as $d => $v) if ($d >= $from && $d <= $to) $bp += $v[0];
        $stt = fi_channel_state($booked[$key], $recAll[$key] ?? 0, $to); $outTot += (int)round($stt['outstanding'] * 100);
        $chans[] = ['key' => $key, 'label' => $meta[0], 'booked' => fi_m($bp), 'received' => fi_m($byCh[$key] ?? 0), 'older_received' => fi_m($older[$key] ?? 0), 'outstanding' => $stt['outstanding'], 'oldest_age' => $stt['oldest_age']];
    }
    $accs = array_values(array_map(fn($x) => ['name' => $x['name'], 'amount' => fi_m($x['p'])], $byAcc)); usort($accs, fn($a, $b) => $b['amount'] <=> $a['amount']);
    $out['money_in'] = ['received' => fi_m($recT - $olderT), 'older_received' => fi_m($olderT), 'bank_total' => fi_m($recT), 'receipts' => $recN, 'channels' => $chans, 'accounts' => $accs, 'outstanding' => fi_m($outTot)];
    $out['sources'][] = ['name' => 'Incoming receipts', 'ok' => true, 'note' => $recN . ' receipt' . ($recN === 1 ? '' : 's') . ' in this period'];
    // ---- money out: Daily Money (not bank deposits: those only move cash) + the ledger's expense accounts + customer refunds paid
    $spend = []; $spendT = 0;
    try {
        $q = $pdo->prepare("SELECT a.name, a.kind, SUM(e.amount) amt, COUNT(*) n FROM dp_mn_entries e JOIN dp_mn_accounts a ON a.id = e.account_id WHERE e.dir = 'out' AND a.kind <> 'bank' AND e.entry_date BETWEEN ? AND ? GROUP BY a.id, a.name, a.kind"); $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) { $p = fi_p($r['amt']); $spend[] = ['label' => $r['name'], 'source' => 'Daily Money', 'kind' => $r['kind'], 'amount' => fi_m($p), 'n' => (int)$r['n']]; $spendT += $p; }
        $out['sources'][] = ['name' => 'Daily Money (spending)', 'ok' => true, 'note' => ''];
    } catch (Throwable $e) { $out['sources'][] = ['name' => 'Daily Money (spending)', 'ok' => false, 'note' => 'not available here']; }
    try {
        $q = $pdo->prepare("SELECT a.name, SUM(l.debit - l.credit) amt, COUNT(*) n FROM lg_lines l JOIN lg_journal j ON j.id = l.journal_id AND j.status = 'posted' JOIN lg_accounts a ON a.id = l.account_id AND a.type = 'expense' WHERE j.entry_date BETWEEN ? AND ? GROUP BY a.id, a.name HAVING SUM(l.debit - l.credit) <> 0"); $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) { $p = fi_p($r['amt']); $spend[] = ['label' => $r['name'], 'source' => 'Ledger', 'kind' => 'expense', 'amount' => fi_m($p), 'n' => (int)$r['n']]; $spendT += $p; }
        $out['sources'][] = ['name' => 'Finance ledger (expenses)', 'ok' => true, 'note' => ''];
    } catch (Throwable $e) { $out['sources'][] = ['name' => 'Finance ledger (expenses)', 'ok' => false, 'note' => 'no ledger entries here']; }
    try { $q = $pdo->prepare("SELECT c.provider, SUM(c.amount) amt, COUNT(*) n FROM fi_charges c JOIN fi_receipts r ON r.id = c.receipt_id AND r.status = 'ok' WHERE c.rdate BETWEEN ? AND ? GROUP BY c.provider"); $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) { $p = fi_p($r['amt']); if ($p <= 0) continue; $spend[] = ['label' => (trim((string)$r['provider']) !== '' ? $r['provider'] : 'Payment gateway') . ' charges', 'source' => 'Uploaded files', 'kind' => 'expense', 'amount' => fi_m($p), 'n' => (int)$r['n']]; $spendT += $p; }
    } catch (Throwable $e) {}
    $rfP = 0; $rfN = 0; try { $q = $pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) a, COUNT(*) n FROM fi_close WHERE status = 'ok' AND refund_status = 'paid' AND refund_date BETWEEN ? AND ?"); $q->execute([$from, $to]); $x = $q->fetch(); $rfP = fi_p($x['a']); $rfN = (int)$x['n']; } catch (Throwable $e) {}
    if ($rfP) { $spend[] = ['label' => 'Customer refunds (RTO / not sent)', 'source' => 'Parcels', 'kind' => 'refund', 'amount' => fi_m($rfP), 'n' => $rfN]; $spendT += $rfP; }
    usort($spend, fn($a, $b) => $b['amount'] <=> $a['amount']);
    $kindName = ['vendor' => 'Suppliers & vendors', 'staff' => 'Staff', 'petty' => 'Petty cash', 'party' => 'Party payouts', 'expense' => 'Expenses', 'income' => 'Other', 'refund' => 'Customer refunds'];
    $grp = []; foreach ($spend as $i => $r) { $spend[$i]['share'] = $spendT ? (int)round(100 * fi_p($r['amount']) / $spendT) : 0; $g = $r['source'] === 'Ledger' ? 'Ledger expenses' : ($kindName[$r['kind']] ?? 'Expenses'); $grp[$g] = ($grp[$g] ?? 0) + fi_p($r['amount']); }
    arsort($grp); $groups = []; foreach ($grp as $g => $p) $groups[] = ['label' => $g, 'amount' => fi_m($p), 'share' => $spendT ? (int)round(100 * $p / $spendT) : 0];
    $out['money_out'] = ['spent' => fi_m($spendT), 'list' => $spend, 'groups' => $groups, 'top' => $spend ? ['label' => $spend[0]['label'], 'amount' => $spend[0]['amount'], 'share' => $spend[0]['share']] : null, 'top_group' => $groups ? $groups[0] : null, 'note' => $spend ? '' : 'Nothing spent is recorded yet for this period (Daily Money, ledger).'];
    $out['net'] = fi_m($recT - $spendT);
    // ---- needs a look
    $wd = fi_wait_days($pdo); $ovN = 0; $ovA = 0.0; foreach (['online_cod', 'online_prepaid'] as $oc) foreach (fi_open_orders($pdo, $oc) as $o) { if ($o['settled'] || $o['closed']) continue; if ((int)floor((strtotime($today) - strtotime($o['day'])) / 86400) >= $wd) { $ovN++; $ovA += $o['amount']; } }
    $rf = 0.0; $rfn = 0; try { $q = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(refund_amount),0) a FROM fi_close WHERE status = 'ok' AND refund_status = 'due'")->fetch(); $rf = (float)$q['a']; $rfn = (int)$q['n']; } catch (Throwable $e) {}
    $out['watch'] = ['outstanding' => fi_m($outTot), 'overdue_parcels' => $ovN, 'overdue_amount' => round($ovA, 2), 'wait_days' => $wd, 'refunds_due' => round($rf, 2), 'refunds_due_n' => $rfn, 'track_from' => fi_track_from($pdo)];
    $out['days'] = array_values(array_map(function ($d) { $d['sales'] = round($d['sales'], 2); $d['store_sales'] = round($d['store_sales'], 2); $d['received'] = round($d['received'], 2); return $d; }, $days));
    return $out;
}

/** Claude's one-line understanding of a receipt, from what it is and what came before from the same payer. Empty when Claude is off: never blocks saving. */
function fi_claude_note(PDO $pdo, array $r): string
{
    if (!function_exists('brain_ai_text')) return '';
    try {
        $q = $pdo->prepare("SELECT rdate, amount, ref, note FROM fi_receipts WHERE status = 'ok' AND channel = ? AND id <> ? AND (payer = ? OR ? = '') ORDER BY id DESC LIMIT 4"); $q->execute([$r['channel'], (int)$r['id'], $r['payer'], $r['payer']]);
        $h = ''; foreach ($q->fetchAll() as $x) $h .= '- ' . $x['rdate'] . ' ₹' . $x['amount'] . ' ' . $x['ref'] . ' ' . $x['note'] . "\n";
        $sys = 'You are the finance assistant of TM Perfume House (perfumes: online Shopify orders prepaid or COD, a retail store, dealers). Write ONE plain sentence (max 30 words) saying what this money most likely is and whether it fits the earlier receipts. If something is unclear, end with one short question for the owner. No greeting.';
        $u = 'New receipt: ' . $r['rdate'] . ' ₹' . $r['amount'] . ' for "' . (FI_CHANNELS[$r['channel']][0] ?? $r['channel']) . '" received in ' . $r['account_name'] . '; payer: ' . ($r['payer'] ?: '—') . '; reference: ' . ($r['ref'] ?: '—') . '; note: ' . ($r['note'] ?: '—') . "\nEarlier receipts of this kind:\n" . ($h ?: '(none yet)');
        return mb_substr(trim((string)brain_ai_text('internal', 'finance', $sys, $u, 160)), 0, 480);
    } catch (Throwable $e) { error_log('fi_claude_note: ' . $e->getMessage()); return ''; }
}

/* ============================== Excel / CSV reading (xlsx without any library: it is a zip of XML) ============================== */
function fi_read_sheet(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) throw new Exception('This server cannot open .xlsx files. Save the sheet as CSV and upload that.');
        $z = new ZipArchive(); if ($z->open($path) !== true) throw new Exception('That .xlsx file could not be opened.');
        $shared = []; $ss = $z->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) { $x = @simplexml_load_string($ss); if ($x) foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; } }
        $sheetName = 'xl/worksheets/sheet1.xml'; if ($z->locateName($sheetName) === false) { for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetName = $n; break; } } }
        $xml = $z->getFromName($sheetName); $z->close(); if ($xml === false) throw new Exception('No sheet found in that file.');
        $x = @simplexml_load_string($xml); if (!$x) throw new Exception('The sheet could not be read.');
        $rows = [];
        foreach ($x->sheetData->row as $row) {
            $r = []; $max = -1;
            foreach ($row->c as $c) {
                $ref = (string)$c['r']; $col = 0; if (preg_match('/^([A-Z]+)/', $ref, $m)) { foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64); $col--; }
                $t = (string)$c['t']; $v = isset($c->v) ? (string)$c->v : '';
                if ($t === 's') $v = $shared[(int)$v] ?? ''; elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
                $r[$col] = trim($v); $max = max($max, $col);
            }
            $line = []; for ($i = 0; $i <= $max; $i++) $line[] = $r[$i] ?? '';
            if (count(array_filter($line, fn($c) => $c !== '')) > 0) $rows[] = $line;
            if (count($rows) >= 20000) break;
        }
        return $rows;
    }
    if (function_exists('fin_read_table')) return fin_read_table($path);        // the old Finance reader: csv with the right separator
    $raw = (string)file_get_contents($path); $rows = [];
    foreach (preg_split('/\r\n|\n|\r/', $raw) as $l) { if (trim($l) === '') continue; $rows[] = array_map('trim', str_getcsv($l)); }
    return $rows;
}
function fi_num($v): float { $s = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$v)); return $s === '' || $s === '-' || $s === '.' ? 0.0 : (float)$s; }
function fi_cell_day($v): string
{
    $v = trim((string)$v); if ($v === '') return '';
    if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float)$v > 30000 && (float)$v < 70000) return date('Y-m-d', (int)(((float)$v - 25569) * 86400));      // an Excel date number
    $t = strtotime(str_replace('/', '-', $v)); if ($t && (int)date('Y', $t) >= 2020 && (int)date('Y', $t) <= (int)date('Y') + 1) return date('Y-m-d', $t);
    if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})#', $v, $m)) { $y = (int)$m[3]; if ($y < 100) $y += 2000; if (checkdate((int)$m[2], (int)$m[1], $y)) return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]); }
    return '';
}

/** Ask Claude (the ONE door = the shared brain, internal shelf) what this file is and where its columns are. Falls back to simple header words when Claude is off. */
function fi_analyse(array $rows, string $fileName): array
{
    $head = array_slice($rows, 0, 14); $tail = array_slice($rows, -3); $aiErr = '';
    $fallback = fi_guess_columns($rows);
    $ai = null;
    if (function_exists('brain_ai_json')) {
        $sample = ''; foreach (array_merge($head, [['...']], $tail) as $i => $r) $sample .= $i . ': ' . implode(' | ', array_map(fn($c) => mb_substr((string)$c, 0, 40), array_slice($r, 0, 18))) . "\n";
        $sys = 'You read payment files for TM Perfume House (an Indian perfume company: Shopify online orders paid prepaid or cash-on-delivery, a retail store, dealers). Work out what the file is and which column is which. '
            . 'Return JSON: {"kind":"courier_cod|gateway_prepaid|bank_statement|upi_report|marketplace|other","provider":"name if you can tell (Shiprocket, Delhivery, Razorpay, PayU, Cashfree, Amazon, HDFC, ...)","channel":"online_cod|online_prepaid|store_online|store_cash|retail|other","summary":"one plain sentence: what money this file shows","header_row":0,"cols":{"date":index or null,"amount":index or null,"credit":index or null,"debit":index or null,"order":index or null,"awb":index or null,"ref":index or null},"confidence":0-100}. '
            . 'Column indexes start at 0 and refer to the rows shown. "amount" = the money received per row (net of fees if shown); use "credit"/"debit" for a bank statement. "order" = our order number column; "awb" = the courier tracking / AWB column. "ref" = the payment transaction id column (for example PayU "txnid" values like KWIK..., a Pine Labs RRN), or the bank UTR. For a Pine Labs POS file: provider "Pine Labs", channel store_online, and "order" = the invoice / bill / order ID typed on the machine (it can be blank).';
        try { $ai = brain_ai_json('internal', 'finance', $sys, "File name: $fileName\nRows (index: cells):\n$sample", 900); } catch (Throwable $e) { error_log('fi_analyse: ' . $e->getMessage()); $aiErr = mb_substr((string)$e->getMessage(), 0, 140); }
    }
    $out = $fallback + ['kind' => 'other', 'provider' => '', 'channel' => 'other', 'summary' => '', 'confidence' => 0, 'by_claude' => false];
    if (is_array($ai) && !empty($ai['cols']) && is_array($ai['cols'])) {
        $cols = []; foreach (['date', 'amount', 'credit', 'debit', 'order', 'ref', 'awb'] as $k) { $v = $ai['cols'][$k] ?? null; $cols[$k] = is_numeric($v) ? (int)$v : null; }
        if ($cols['amount'] !== null || $cols['credit'] !== null) {
            $out['cols'] = $cols; $out['header_row'] = max(0, (int)($ai['header_row'] ?? $fallback['header_row']));
            $out['kind'] = (string)($ai['kind'] ?? 'other'); $out['provider'] = mb_substr((string)($ai['provider'] ?? ''), 0, 60);
            $out['channel'] = isset(FI_CHANNELS[$ai['channel'] ?? '']) ? $ai['channel'] : 'other'; $out['summary'] = mb_substr((string)($ai['summary'] ?? ''), 0, 240);
            $out['confidence'] = (int)($ai['confidence'] ?? 0); $out['by_claude'] = true;
        }
    }
    if (!$out['by_claude']) {      // no Claude (the AI key was refused or the AI is off): guess what the file is from words in its name and header — whole words only ("code" is not "cod")
        $hdr = strtolower(implode(' ', array_map('strval', $rows[$out['header_row']] ?? []))); $t = strtolower($fileName . ' ' . $hdr);
        $pos = 0; foreach (['/\btid\b|terminal/', '/\brrn\b/', '/\bmid\b|merchant id/', '/\bmdr\b|\bmsf\b/', '/batch/', '/auth(orization|orisation)?[ _]?(code|id)/', '/card[ _](type|network|brand)|\bbin\b/', '/invoice|bill[ _](no|number|ref)/'] as $re) if (preg_match($re, $hdr)) $pos++;
        if (preg_match('/pine|plutus/', $t) || $pos >= 3) { $out['kind'] = 'pos_card'; $out['channel'] = 'store_online'; $out['provider'] = 'Pine Labs'; }      // the card / UPI machine of the store: matched by the order id typed on it (see fi_pine_commit)
        elseif (preg_match('/\b(payu|mihpayid)\b/', $t) || count(array_filter(['/\btxnid\b/', '/productinfo/', '/\bpgmid\b/', '/bank_ref_num/', '/error_?desc/'], fn($re) => preg_match($re, $hdr))) >= 3) { $out['kind'] = 'gateway_prepaid'; $out['channel'] = 'online_prepaid'; $out['provider'] = 'PayU'; }      // a PayU transaction report: txnid, productinfo, pgmid ...
        elseif (preg_match('/\b(cod|remit|remitted|remittance|delhivery|shiprocket|ecom|bluedart)\b/', $t)) { $out['kind'] = 'courier_cod'; $out['channel'] = 'online_cod'; }
        elseif (preg_match('/\b(payu|razorpay|cashfree|gateway|payout|settlement)\b/', $t)) { $out['kind'] = 'gateway_prepaid'; $out['channel'] = 'online_prepaid'; }
        elseif (preg_match('/upi|phonepe|paytm|gpay/', $t)) { $out['kind'] = 'upi_report'; $out['channel'] = 'store_online'; }
        $out['summary'] = 'Read by header words — Claude was not used' . ($aiErr !== '' ? ' (' . $aiErr . ')' : ' (the AI did not answer: check the AI key)') . '. Check the columns before saving.';
    }
    return $out;
}
function fi_guess_columns(array $rows): array
{
    $best = 0; $hi = 0; $words = ['date', 'amount', 'credit', 'debit', 'order', 'awb', 'utr', 'ref', 'remit', 'settle', 'cod', 'payout', 'narration', 'description', 'particulars', 'net'];
    foreach (array_slice($rows, 0, 25, true) as $i => $r) { $s = 0; foreach ($r as $c) { $l = strtolower((string)$c); foreach ($words as $w) if ($l !== '' && strpos($l, $w) !== false) { $s++; break; } } if ($s > $best) { $best = $s; $hi = $i; } }
    $cols = ['date' => null, 'amount' => null, 'credit' => null, 'debit' => null, 'order' => null, 'ref' => null, 'awb' => null];
    $rules = [      // column role => [regex => score]; the best scoring header wins (so "Net Remitted" beats "COD Amount", "Order ID" beats "AWB")
        'amount' => ['/net (remit|amount|payout|settle)|remitted|settlement amount|amount settled|payout/' => 6, '/settle|net/' => 4, '/cod amount|collected|\bcod\b/' => 2, '/amount|amt/' => 1],
        'credit' => ['/^(credit|deposit|cr)\b|credit amt|deposit amt|credits?$/' => 6],
        'debit' => ['/^(debit|withdraw|dr)\b|debit amt|withdrawal/' => 6],
        'order' => ['/order (id|no|number|name)|channel order|^order$/' => 6, '/invoice|bill (no|number|ref)|merchant ref|^bill/' => 5, '/order/' => 4],
        'awb' => ['/awb|waybill|tracking (no|id|number)|lr no|docket/' => 6, '/tracking/' => 3],
        'ref' => ['/utr|bank ref|reference|ref no|txn|transaction|neft|rtgs|imps|chq/' => 5, '/ref/' => 3],
        'date' => ['/remit.*date|settle.*date|payment date|credit date|txn date|value date|^date$/' => 6, '/date|time/' => 3],
    ];
    $skipAmount = '/fee|charge|commission|tax|gst|tds|deduct/';
    $used = [];
    foreach ($rules as $role => $rs) {
        $bestScore = 0; $bestI = null;
        foreach (($rows[$hi] ?? []) as $i => $c) {
            $l = strtolower(trim((string)$c)); if ($l === '' || isset($used[$i])) continue; if ($role === 'amount' && preg_match($skipAmount, $l)) continue;
            foreach ($rs as $re => $sc) if (preg_match($re, $l) && $sc > $bestScore) { $bestScore = $sc; $bestI = $i; }
        }
        if ($bestI !== null) { $cols[$role] = $bestI; $used[$bestI] = true; }
    }
    return ['header_row' => $hi, 'cols' => $cols];
}
/** The columns of a gateway file that are what the gateway kept (fee, commission, GST, TDS ...): [index => header]. The columns already used for date / amount / order / ref are left out. */
function fi_charge_cols(array $rows, array $a): array
{
    $used = array_filter(array_values($a['cols']), fn($v) => $v !== null); $out = [];
    foreach (($rows[(int)$a['header_row']] ?? []) as $i => $h) { $l = strtolower(trim((string)$h)); if ($l === '' || in_array($i, $used, true) || preg_match('/error|discount|refund|status|message|desc|requested/', $l)) continue; if (preg_match('/fee|charge|commission|mdr|gst|service.?tax|\btax\b|tds|deduct/', $l)) $out[$i] = (string)$h; }
    return $out;
}
/** The column holding the net settled amount, if the file has one: its index or null. */
function fi_net_col(array $rows, array $a): ?int
{
    $used = array_filter(array_values($a['cols']), fn($v) => $v !== null);
    foreach (($rows[(int)$a['header_row']] ?? []) as $i => $h) { $l = strtolower(trim((string)$h)); if (!in_array($i, $used, true) && preg_match('/amount.?\(?net\)?|net.?(settle|amount|amt|payout|received)|settled.?(amount|amt)|payout.?amount|merchant.?net|amount.?settled/', $l) && !preg_match('/fee|charge|gst|tax|tds|deduct/', $l)) return $i; }
    return null;
}
/** Total of every numeric column of the file (so a wrong amount / fee column is easy to spot): [['col' => header, 'sum' => total], ...]. */
function fi_col_sums(array $rows, array $a): array
{
    $out = []; $hdr = $rows[(int)$a['header_row']] ?? []; $body = array_slice($rows, (int)$a['header_row'] + 1);
    foreach ($hdr as $i => $h) { $h = trim((string)$h); if ($h === '') continue; $sum = 0.0; $n = 0; foreach ($body as $r) { $v = trim((string)($r[$i] ?? '')); if ($v !== '' && preg_match('/^-?[\d,]+(\.\d+)?$/', $v)) { $sum += (float)str_replace(',', '', $v); $n++; } } if ($n > 0 && abs($sum) >= 1 && $n >= count($body) * 0.2) $out[] = ['col' => mb_substr($h, 0, 40), 'sum' => round($sum, 2)]; }
    return array_slice($out, 0, 40);
}
/** The received rows of a file: [['day','amount','order','ref'], ...]  (credits only). */
function fi_file_rows(array $rows, array $a): array
{
    $c = $a['cols']; $out = []; $stI = null; $chI = array_keys(fi_charge_cols($rows, $a)); $phI = null; $netI = null;
    $netI = fi_net_col($rows, $a);
    foreach (($rows[(int)$a['header_row']] ?? []) as $i => $h) if ($phI === null && preg_match('/phone|mobile/i', (string)$h) && !preg_match('/second|alt|other/i', (string)$h)) $phI = $i;
    foreach (($rows[(int)$a['header_row']] ?? []) as $i => $h) if (preg_match('/^(txn |transaction |payment )?status$/i', trim((string)$h))) { $stI = $i; break; }      // a gateway report lists failed / dropped / refunded rows too: only the paid ones are money received
    foreach (array_slice($rows, (int)$a['header_row'] + 1) as $r) {
        if ($stI !== null && !($netI !== null && fi_num($r[$netI] ?? '') > 0) && preg_match('/^(dropped|failed|failure|refunded|refund|pending|initiated|cancel+ed|usercancelled|bounced|aborted|declined|expired)$/i', trim((string)($r[$stI] ?? '')))) continue;
        $amt = 0.0;
        if ($c['credit'] !== null || $c['debit'] !== null) { $cr = $c['credit'] !== null ? fi_num($r[$c['credit']] ?? '') : 0.0; $amt = $cr > 0 ? $cr : 0.0; }
        elseif ($c['amount'] !== null) $amt = fi_num($r[$c['amount']] ?? '');
        if ($amt <= 0) continue;
        $day = $c['date'] !== null ? fi_cell_day($r[$c['date']] ?? '') : '';
        $out[] = ['day' => $day, 'amount' => round($amt, 2), 'order' => $c['order'] !== null ? trim((string)($r[$c['order']] ?? '')) : '', 'awb' => ($c['awb'] ?? null) !== null ? trim((string)($r[$c['awb']] ?? '')) : '', 'ref' => $c['ref'] !== null ? trim((string)($r[$c['ref']] ?? '')) : '', 'phone' => $phI !== null ? substr(preg_replace('/\D+/', '', (string)($r[$phI] ?? '')), -10) : '', 'cells' => array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), $r), fn($x) => mb_strlen($x) >= 6 && preg_match('/\d/', $x))), 0, 12), 'charge' => 0.0, 'gross' => round($amt, 2)];
        $last = count($out) - 1; $fee = round(array_sum(array_map(fn($i) => max(0.0, fi_num($r[$i] ?? '')), $chI)), 2);
        $net = $netI !== null ? fi_num($r[$netI] ?? '') : 0.0;
        if ($net > 0 && $net <= $amt) $fee = round($amt - $net, 2);      // the file says the net settled amount itself
        elseif ($fee > 0 && $fee <= $amt * 0.3) $net = round($amt - $fee, 2);      // gross minus what the gateway kept
        else { $fee = 0.0; $net = 0.0; }      // no fee columns, or a "fee" bigger than 30% of the payment is not a fee
        if ($net > 0 && $fee > 0) { $out[$last]['amount'] = $net; $out[$last]['charge'] = $fee; }
    }
    return $out;
}
/** The fixed prepaid discount, in percent (GoKwik / PayU prepaid orders get 2% off). Set FI_PREPAID_DISC_PCT in the config to change it. */
function fi_disc_pct(): float { return defined('FI_PREPAID_DISC_PCT') ? (float)FI_PREPAID_DISC_PCT : 2.0; }
/** The prepaid discount lost on an order: what the order should bring in (its prepaid part) minus what the file shows, when that gap is the fixed discount (about 2% of the order, give or take ₹1 / 0.5% for rounding).
 *  Any other gap is a part payment or a mismatch, not the discount, so it is 0 here. */
function fi_disc($exp, $amt): float { $exp = (float)$exp; $d = round($exp - (float)$amt, 2); $want = $exp * fi_disc_pct() / 100; return ($exp > 0 && $d > 0 && abs($d - $want) <= max(1.0, $exp * 0.005)) ? $d : 0.0; }
/** Does this file row stay OUT of the booked amount? Money counts against the books only when it is tied to a tracked order by its id, or (store / other channels, no order id in the file) is dated on or after that channel's start day.
 *  Older orders, and online money with no order id (a bank statement, a settlement total), is kept as a record in the bank but never reduces the booked / outstanding. */
function fi_row_untracked(array $r, string $ch, string $day, string $tfOnline, string $tfStore): bool
{
    if (in_array(($r['cls'] ?? ''), ['before_start', 'unknown'], true)) return true;      // older orders and orders we do not know: in the bank, never against the booked amount
    if (($r['cls'] ?? '') !== 'no_order') return false;
    if (in_array($ch, ['online_cod', 'online_prepaid'], true)) return true;
    $tf = in_array($ch, ['store_cash', 'store_online'], true) ? $tfStore : $tfOnline; return $tf !== '' && $day < $tf;
}
function fi_okey($s): string { return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$s)); }

/**
 * Every online order of one channel (last 400 days) as okey => info. 'pre' = placed before the tracking start day (not tracked); 'settled' = a file proved it; 'closed' = closed by hand (RTO / not sent / lost).
 * The amount is what this channel expects: the COD balance (online_cod) or the prepaid part (online_prepaid).
 */
function fi_order_map(PDO $pdo, string $channel): array
{
    $out = []; $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : []; $tf = fi_track_from($pdo);
    $set = []; $closed = [];
    try { $q = $pdo->prepare("SELECT order_key, SUM(amount) a, MAX(rdate) d FROM fi_settled WHERE channel = ? GROUP BY order_key"); $q->execute([$channel]); foreach ($q->fetchAll() as $x) $set[$x['order_key']] = ['a' => (float)$x['a'], 'd' => $x['d']]; } catch (Throwable $e) {}
    try { $q = $pdo->prepare("SELECT order_key, id, kind FROM fi_close WHERE channel = ? AND status = 'ok'"); $q->execute([$channel]); foreach ($q->fetchAll() as $c) $closed[$c['order_key']] = $c; } catch (Throwable $e) {}
    try {
        try { $pdo->exec("ALTER TABLE shop_orders ADD COLUMN pay_ref VARCHAR(200) NULL"); } catch (Throwable $e) {}      // the GoKwik / PayU transaction id read from the order (api.php); added here too so the query below never fails
        $q = $pdo->prepare("SELECT shopify_id, store_key, order_name, created_at, total, financial_status, outstanding, customer, phone, pay_ref FROM shop_orders WHERE created_at >= ?");
        $q->execute([date('Y-m-d', strtotime('-400 day')) . ' 00:00:00']);
        foreach ($q->fetchAll() as $o) {
            $fs = strtolower((string)$o['financial_status']); if (in_array($fs, ['voided', 'refunded'], true)) continue; if (isset($cancel[$o['store_key'] . '|' . $o['shopify_id']])) continue;
            $d = function_exists('biz_day_of') ? biz_day_of($pdo, strtotime((string)$o['created_at'])) : substr((string)$o['created_at'], 0, 10);
            $tot = fi_p($o['total']); $cod = $o['outstanding'] !== null ? fi_p($o['outstanding']) : (in_array($fs, ['paid', 'partially_refunded'], true) ? 0 : $tot); $cod = max(0, min($tot, $cod)); $amt = $channel === 'online_cod' ? $cod : $tot - $cod;
            if ($amt <= 0) continue; $k = fi_okey($o['order_name']); if ($k === '') continue;
            $out[$k] = ['key' => $k, 'name' => (string)$o['order_name'], 'day' => $d, 'amount' => fi_m($amt), 'customer' => (string)$o['customer'], 'phone' => (string)$o['phone'], 'refs' => array_values(array_filter(array_map('fi_okey', explode(',', (string)($o['pay_ref'] ?? ''))))), 'pre' => ($tf !== '' && $d < $tf), 'paid' => isset($set[$k]) ? round($set[$k]['a'], 2) : 0.0, 'paid_on' => $set[$k]['d'] ?? '', 'settled' => isset($set[$k]) && $set[$k]['a'] >= 0.9 * fi_m($amt), 'closed' => isset($closed[$k]), 'close_id' => $closed[$k]['id'] ?? 0, 'close_kind' => $closed[$k]['kind'] ?? ''];
        }
    } catch (Throwable $e) {}
    return $out;
}
/** orders we track (placed on or after the start day) for a channel: key => info. */
function fi_open_orders(PDO $pdo, string $channel, string $from = '', string $to = ''): array
{
    return array_filter(fi_order_map($pdo, $channel), function ($o) use ($from, $to) { return !$o['pre'] && ($from === '' || $o['day'] >= $from) && ($to === '' || $o['day'] <= $to); });
}
/** Which of the rows of a file count? class: matched (an order we wait for) | no_order (the file has no order column) | before_start | unknown | already (proved by an earlier file) | closed (closed by hand). */
function fi_classify(array $recv, string $channel, array $map, bool $hasOrderCol, array $awbMap = []): array
{
    $orderBased = in_array($channel, ['online_cod', 'online_prepaid'], true);
    $refMap = []; foreach ($map as $ok => $o) foreach (($o['refs'] ?? []) as $x) $refMap[$x] = $ok;      // payment transaction id (KWIK...) -> order key
    if ($refMap) foreach ($recv as $r) if (fi_okey($r['ref'] ?? '') !== '' || fi_okey($r['order'] ?? '') !== '') { $hasOrderCol = true; break; }
    if ($orderBased && $map) $hasOrderCol = true;      // every row of an online file is either an order we know or not
    $byPhone = []; foreach ($map as $ok => $o) { $ph = substr(preg_replace('/\D+/', '', (string)($o['phone'] ?? '')), -10); if (strlen($ph) === 10 && empty($o['settled'])) $byPhone[$ph][] = $ok; }
    foreach ($recv as $i => $r) {
        $k = fi_okey($r['order']); if (($k === '' || !isset($map[$k])) && !empty($r['awb'])) { $a = fi_okey($r['awb']); if ($a !== '' && isset($awbMap[$a])) $k = $awbMap[$a]; }
        if ($k === '' || !isset($map[$k])) { foreach ([fi_okey($r['ref'] ?? ''), fi_okey($r['order'] ?? '')] as $t) { if ($t !== '' && isset($refMap[$t])) { $k = $refMap[$t]; break; } } }      // the file's transaction id (PayU txnid) matches the one saved on the order
        if ($orderBased && ($k === '' || !isset($map[$k]))) {      // the column was guessed wrong or is not there: look at every id-like cell of the row, then at phone + amount
            foreach ((array)($r['cells'] ?? []) as $cell) { $t = fi_okey($cell); if (strlen($t) < 5) continue; if (isset($map[$t])) { $k = $t; break; } if (isset($refMap[$t])) { $k = $refMap[$t]; break; } }
            if (($k === '' || !isset($map[$k])) && !empty($r['phone']) && isset($byPhone[$r['phone']])) { $cand = []; foreach ($byPhone[$r['phone']] as $ck) { $ex = (float)$map[$ck]['amount']; if (abs($ex - (float)($r['gross'] ?? $r['amount'])) <= 1.0 || fi_disc($ex, $r['gross'] ?? $r['amount']) > 0) $cand[] = $ck; } if (count($cand) === 1) $k = $cand[0]; }
        }
        $c = 'no_order';
        if ($orderBased && $hasOrderCol) {
            if ($k === '' || !isset($map[$k])) $c = 'unknown'; elseif ($map[$k]['pre']) $c = 'before_start'; elseif ($map[$k]['settled']) $c = 'already'; elseif ($map[$k]['closed']) $c = 'closed'; else $c = 'matched';
        }
        $recv[$i]['cls'] = $c; $recv[$i]['okey'] = $k; $recv[$i]['disc'] = $c === 'matched' ? fi_disc($map[$k]['amount'] ?? 0, $r['gross'] ?? $r['amount']) : 0.0;
    }
    return $recv;
}
/** One row of a file as a key: the same row uploaded twice must not be saved twice. */
function fi_row_key(array $r, string $ch): string { return md5($ch . '|' . ($r['day'] ?? '') . '|' . round((float)($r['amount'] ?? 0), 2) . '|' . fi_okey($r['order'] ?? '') . '|' . fi_okey($r['ref'] ?? '') . '|' . fi_okey($r['awb'] ?? '')); }
/** Rows that are not tied to a tracked order (older, unknown, no id) were saved by an earlier upload of the same file? Then they are 'already'. Sets 'rk' on the others. */
function fi_mark_seen(PDO $pdo, array $recv, string $ch): array
{
    try {
        $keys = []; foreach ($recv as $i => $r) if (in_array($r['cls'] ?? '', ['unknown', 'before_start', 'no_order'], true)) { $recv[$i]['rk'] = fi_row_key($r, $ch); $keys[$recv[$i]['rk']] = 1; }
        if (!$keys) return $recv; $seen = [];
        foreach (array_chunk(array_keys($keys), 500) as $part) { $q = $pdo->prepare("SELECT row_key FROM fi_seen_rows WHERE row_key IN (" . implode(',', array_fill(0, count($part), '?')) . ")"); $q->execute($part); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $k) $seen[$k] = 1; }
        foreach ($recv as $i => $r) if (isset($r['rk']) && isset($seen[$r['rk']])) $recv[$i]['cls'] = 'already';
    } catch (Throwable $e) { error_log('fi_mark_seen: ' . $e->getMessage()); }
    return $recv;
}
/** AWB (tracking number) -> our order key, from the Dispatch parcels (every parcel made there). */
function fi_awb_map(PDO $pdo): array
{
    $out = []; try { foreach ($pdo->query("SELECT tracking_no, order_name FROM dp_dsp_parcels WHERE tracking_no IS NOT NULL AND tracking_no <> ''")->fetchAll() as $r) { $a = fi_okey($r['tracking_no']); if ($a !== '') $out[$a] = fi_okey($r['order_name']); } } catch (Throwable $e) {}
    return $out;
}

/* ============================== Pine Labs (the store card / UPI machine) ==============================
 * The store types the ORDER ID (the store bill number, or the Shopify order name for a courier / COD order) on the machine for every payment.
 * An uploaded Pine Labs file is matched by that id: a store bill -> store UPI / card received; a Shopify order -> its COD or prepaid money received.
 * A transaction with NO order id (or one we do not know) is real money in the bank, so it is saved (untracked, it proves no bill) and FLAGGED for Boss. */
function fi_is_pine(array $a, string $fileName): bool { return (bool)preg_match('/pine|plutus/i', ($a['provider'] ?? '') . ' ' . $fileName); }
/** Store bills paid by UPI / card (last 120 days): [okey => info] and [okey of order_code => okey of bill no]. */
function fi_store_map(PDO $pdo): array
{
    $out = []; $alias = []; $tf = fi_track_from_store($pdo); $set = [];
    try { foreach ($pdo->query("SELECT order_key FROM fi_settled WHERE channel = 'store_online'")->fetchAll(PDO::FETCH_COLUMN) as $k) $set[$k] = 1; } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare("SELECT bill_no, order_code, biz_date, total, online_amt, pay_mode, emp_name FROM st_bills WHERE status = 'ok' AND pay_mode IN ('online','split') AND biz_date >= ?"); $q->execute([date('Y-m-d', strtotime('-120 day'))]);
        foreach ($q->fetchAll() as $b) {
            $k = fi_okey($b['bill_no']); if ($k === '') continue; $amt = $b['pay_mode'] === 'split' ? fi_p($b['online_amt']) : fi_p($b['total']);
            $out[$k] = ['key' => $k, 'name' => (string)$b['bill_no'], 'day' => (string)$b['biz_date'], 'amount' => fi_m($amt), 'emp' => (string)$b['emp_name'], 'pre' => ($tf !== '' && $b['biz_date'] < $tf), 'settled' => isset($set[$k])];
            $c = fi_okey($b['order_code']); if ($c !== '' && $c !== $k) $alias[$c] = $k;
        }
    } catch (Throwable $e) {}
    return [$out, $alias];
}
/** transaction keys already saved as a flag (so a second upload of the same file never counts them twice) */
function fi_pine_seen(PDO $pdo): array { try { return array_fill_keys($pdo->query("SELECT tx_key FROM fi_pine_flags")->fetchAll(PDO::FETCH_COLUMN), 1); } catch (Throwable $e) { return []; } }
/** class per row: matched | before_start | already | closed | noref (no order id typed) | badref (an id we do not know); 'ch' = the channel the money belongs to, 'type' = bill | cod | prepaid */
function fi_pine_classify(array $recv, array $store, array $alias, array $mapCod, array $mapPre, array $seen): array
{
    foreach ($recv as $i => $r) {
        $o = fi_okey($r['order'] ?? ''); $tx = md5(($r['day'] ?? '') . '|' . round((float)$r['amount'], 2) . '|' . $o . '|' . fi_okey($r['ref'] ?? '')); $k = ''; $type = ''; $ch = 'store_online'; $info = null;
        if ($o === '') $c = 'noref';
        else {
            if (isset($store[$o])) { $k = $o; $type = 'bill'; $info = $store[$o]; } elseif (isset($alias[$o])) { $k = $alias[$o]; $type = 'bill'; $info = $store[$k]; }
            elseif (isset($mapCod[$o])) { $k = $o; $type = 'cod'; $ch = 'online_cod'; $info = $mapCod[$o]; } elseif (isset($mapPre[$o])) { $k = $o; $type = 'prepaid'; $ch = 'online_prepaid'; $info = $mapPre[$o]; }
            if ($info === null) $c = 'badref'; elseif ($info['pre']) $c = 'before_start'; elseif (!empty($info['settled'])) $c = 'already'; elseif (!empty($info['closed'])) $c = 'closed'; else $c = 'matched';
        }
        if (in_array($c, ['noref', 'badref'], true) && isset($seen[$tx])) $c = 'already';
        $recv[$i]['cls'] = $c; $recv[$i]['okey'] = $k; $recv[$i]['type'] = $type; $recv[$i]['ch'] = $ch; $recv[$i]['tx'] = $tx; $recv[$i]['disc'] = 0.0;
        $recv[$i]['bill'] = $info['name'] ?? ''; $recv[$i]['emp'] = $info['emp'] ?? '';
    }
    return $recv;
}
/** save a Pine Labs upload: receipts per channel and day, orders proved, flags for the transactions without an order id. Returns the JSON answer. */
function fi_pine_commit(PDO $pdo, array $up, array $a, array $recv, int $aid, string $an, string $who, string $fallback): array
{
    [$store, $alias] = fi_store_map($pdo); $mapCod = fi_order_map($pdo, 'online_cod'); $mapPre = fi_order_map($pdo, 'online_prepaid');
    $recv = fi_pine_classify($recv, $store, $alias, $mapCod, $mapPre, fi_pine_seen($pdo));
    $keep = ['matched', 'before_start', 'noref', 'badref']; $ignored = count(array_filter($recv, fn($r) => !in_array($r['cls'], $keep, true))); $recv = array_values(array_filter($recv, fn($r) => in_array($r['cls'], $keep, true)));
    if (!$recv) fi_err('Nothing new in this file: every row is already saved, or for a closed order.');
    $groups = []; foreach ($recv as $r) { $d = $r['day'] !== '' && $r['day'] <= date('Y-m-d') ? $r['day'] : $fallback; $unt = ($r['cls'] === 'matched') ? 0 : 1; $groups[$r['ch'] . '|' . $d . '|' . $unt][] = $r; } ksort($groups);
    $made = []; $proved = 0; $flags = []; $codNames = []; $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        foreach ($groups as $gk => $rs) {
            [$ch, $d, $unt] = explode('|', $gk); $unt = (int)$unt; $sum = round(array_sum(array_column($rs, 'amount')), 2);
            $note = 'From Pine Labs file' . ($unt ? ' · no order id / older order (not tracked)' : '');
            $pdo->prepare("INSERT INTO fi_receipts (rdate, account_id, account_name, channel, amount, ref, note, source, upload_id, created_by, created_at, untracked) VALUES (?,?,?,?,?,?,?,'file',?,?,?,?)")
                ->execute([$d, $aid, $an, $ch, $sum, mb_substr($up['file_name'] . ' · ' . count($rs) . ' rows', 0, 120), mb_substr($note, 0, 255), (int)$up['id'], $who, $now, $unt]);
            $id = (int)$pdo->lastInsertId(); $no = 'RC-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); $pdo->prepare("UPDATE fi_receipts SET receipt_no = ? WHERE id = ?")->execute([$no, $id]); $made[] = $no;
            $chg = round(array_sum(array_column($rs, 'charge')), 2);
            if ($chg > 0) $pdo->prepare("INSERT INTO fi_charges (receipt_id, rdate, channel, provider, amount, note, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$id, $d, $ch, 'Pine Labs', $chg, mb_substr($up['file_name'], 0, 255), $now]);
            foreach ($rs as $r) {
                if ($r['cls'] === 'matched' && $r['okey'] !== '') {
                    $ins = $pdo->prepare("INSERT IGNORE INTO fi_settled (order_key, channel, amount, rdate, receipt_id, disc) VALUES (?,?,?,?,?,0)"); $ins->execute([$r['okey'], $ch, $r['amount'], $d, $id]); $proved += $ins->rowCount();
                    if ($r['type'] === 'cod' && isset($mapCod[$r['okey']])) $codNames[$mapCod[$r['okey']]['name']] = 1;
                } elseif (in_array($r['cls'], ['noref', 'badref'], true)) {
                    $pdo->prepare("INSERT IGNORE INTO fi_pine_flags (tx_key, receipt_id, upload_id, tx_day, amount, order_ref, why, status, created_at) VALUES (?,?,?,?,?,?,?,'open',?)")
                        ->execute([$r['tx'], $id, (int)$up['id'], $d, $r['amount'], mb_substr((string)$r['order'], 0, 60), $r['cls'] === 'noref' ? 'no_ref' : 'unknown_ref', $now]);
                    $flags[] = ['day' => $d, 'amount' => round((float)$r['amount'], 2), 'ref' => (string)$r['order'], 'why' => $r['cls'] === 'noref' ? 'no order id' : 'unknown order id'];
                }
            }
        }
        $pdo->prepare("UPDATE fi_uploads SET status = 'saved' WHERE id = ?")->execute([(int)$up['id']]); $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log('fi_pine_commit: ' . $e->getMessage()); fi_err('Could not save the Pine Labs file. Nothing was changed.', 500); }
    $ticked = $codNames ? fi_tick_dispatch($pdo, array_keys($codNames), $made ? end($made) : '') : 0;
    if ($flags && function_exists('bd_inbox')) { $fa = round(array_sum(array_column($flags, 'amount')), 2); try { bd_inbox('fin_pine', 'Pine Labs: ' . count($flags) . ' payment' . (count($flags) === 1 ? '' : 's') . ' without a valid order ID (' . dp_inr_safe($fa) . ')', 'These card / UPI payments reached the bank but no order ID was typed on the Pine Labs machine (or it was wrong), so they match no bill or order. Check who took them and decide a penalty. File: ' . $up['file_name'], ['upload_id' => (int)$up['id']], null, null, 'fin_pine:' . (int)$up['id']); } catch (Throwable $e) {} }
    return ['ok' => true, 'receipts' => $made, 'orders_proved' => $proved, 'dispatch_ticked' => $ticked, 'ignored_rows' => $ignored, 'discount' => 0, 'charge' => round(array_sum(array_column($recv, 'charge')), 2), 'total' => round(array_sum(array_column($recv, 'amount')), 2), 'pine_flags' => $flags];
}
function dp_inr_safe($v): string { return '₹' . number_format((float)$v, 2); }

/* ============================== actions ============================== */
if (!fi_ensure($pdo)) fi_err('Could not create the Finance tables.', 500);
$auth = require_auth();

switch ($action) {
    case 'fi.accounts': {
        $rows = $pdo->query("SELECT * FROM fi_accounts ORDER BY active DESC, name")->fetchAll(); $tot = [];
        foreach ($pdo->query("SELECT account_id, SUM(amount) a FROM fi_receipts WHERE status = 'ok' GROUP BY account_id")->fetchAll() as $r) $tot[(int)$r['account_id']] = (float)$r['a'];
        json_response(['accounts' => array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['name'], 'kind' => $a['kind'], 'last4' => $a['last4'], 'active' => (int)$a['active'], 'received_total' => round($tot[(int)$a['id']] ?? 0, 2)], $rows)]);
        break;
    }
    case 'fi.account_save': {
        $in = json_input(); $id = (int)($in['id'] ?? 0); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); $kind = in_array(($in['kind'] ?? 'bank'), ['bank', 'cash', 'wallet'], true) ? $in['kind'] : 'bank';
        $last4 = preg_replace('/\D/', '', (string)($in['last4'] ?? '')); $last4 = substr($last4, -4);
        if (mb_strlen($name) < 2) fi_err('Give the account a name (for example "HDFC current ••1234" or "Store cash box").');
        $dup = $pdo->prepare("SELECT COUNT(*) FROM fi_accounts WHERE LOWER(name) = LOWER(?) AND id <> ?"); $dup->execute([$name, $id]); if ((int)$dup->fetchColumn()) fi_err('There is already an account with this name.');
        if ($id) { $pdo->prepare("UPDATE fi_accounts SET name = ?, kind = ?, last4 = ?, active = ? WHERE id = ?")->execute([$name, $kind, $last4, isset($in['active']) ? (!empty($in['active']) ? 1 : 0) : 1, $id]); }
        else { $pdo->prepare("INSERT INTO fi_accounts (name, kind, last4, active, created_at) VALUES (?,?,?,1,?)")->execute([$name, $kind, $last4, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId(); }
        json_response(['ok' => true, 'id' => $id]);
        break;
    }

    // the day screen: per channel booked / received (that day) / outstanding (all days up to it)
    case 'fi.overview': {
        $day = fi_day($_GET['date'] ?? '') ?: fi_today($pdo); $today = fi_today($pdo); if ($day > $today) $day = $today; $start = min(fi_start($pdo), $day);
        $booked = fi_booked($pdo, $start, $day); [$recTot, $recDay] = fi_received($pdo, '2000-01-01', $day);
        $chans = []; $sumB = $sumR = $sumO = 0.0;
        foreach (FI_CHANNELS as $key => $meta) {
            $st = fi_channel_state($booked[$key], $recTot[$key] ?? 0, $day); $bd = $booked[$key][$day][0] ?? 0; $rd = $recDay[$key][$day] ?? 0;
            $spark = []; for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime($day . " -$i day")); $spark[] = ['day' => $d, 'booked' => fi_m($booked[$key][$d][0] ?? 0), 'received' => fi_m($recDay[$key][$d] ?? 0)]; }
            $chans[] = ['key' => $key, 'label' => $meta[0], 'hint' => $meta[1], 'booked_day' => fi_m($bd), 'orders_day' => $booked[$key][$day][1] ?? 0, 'received_day' => fi_m($rd), 'booked_total' => $st['booked'], 'received_total' => $st['received'], 'outstanding' => $st['outstanding'], 'advance' => $st['advance'], 'oldest_age' => $st['oldest_age'], 'open_days' => count($st['open_days']), 'spark' => $spark];
            $sumB += fi_m($bd); $sumR += fi_m($rd); $sumO += $st['outstanding'];
        }
        $q = $pdo->prepare("SELECT * FROM fi_receipts WHERE rdate = ? ORDER BY id DESC"); $q->execute([$day]); $recs = array_map('fi_receipt_row', $q->fetchAll());
        $byAcc = []; foreach ($recs as $r) if ($r['status'] === 'ok') { $k = $r['account_id'] . '|' . $r['account']; $byAcc[$k] = ($byAcc[$k] ?? 0) + $r['amount']; }
        $accs = []; foreach ($byAcc as $k => $v) { [$id, $nm] = explode('|', $k, 2); $accs[] = ['id' => (int)$id, 'name' => $nm, 'amount' => round($v, 2)]; }
        $n = (int)$pdo->query("SELECT COUNT(*) FROM fi_accounts WHERE active = 1")->fetchColumn();
        $wd = fi_wait_days($pdo); $ovN = 0; $ovA = 0.0;
        foreach (['online_cod', 'online_prepaid'] as $oc) foreach (fi_open_orders($pdo, $oc) as $o) { if ($o['settled'] || $o['closed']) continue; if ((int)floor((strtotime($today) - strtotime($o['day'])) / 86400) >= $wd) { $ovN++; $ovA += $o['amount']; } }
        $rf = 0.0; $rfN = 0; try { $q = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(refund_amount),0) a FROM fi_close WHERE status = 'ok' AND refund_status = 'due'")->fetch(); $rf = (float)$q['a']; $rfN = (int)$q['n']; } catch (Throwable $e) {}
        json_response(['date' => $day, 'today' => $today, 'start' => $start, 'track_from' => fi_track_from($pdo), 'track_from_store' => fi_track_from_store($pdo), 'wait_days' => $wd, 'keep_days' => fi_keep_days($pdo), 'auto_remit' => fi_auto_remit($pdo) ? 1 : 0, 'channels' => $chans, 'totals' => ['booked' => round($sumB, 2), 'received' => round($sumR, 2), 'outstanding' => round($sumO, 2), 'overdue_n' => $ovN, 'overdue_amount' => round($ovA, 2), 'refunds_due' => round($rf, 2), 'refunds_due_n' => $rfN], 'receipts' => $recs, 'by_account' => $accs, 'accounts_n' => $n]);
        break;
    }

    // everything still outstanding of one channel: the open days (oldest first) and the orders still waited for
    case 'fi.outstanding': {
        $ch = (string)($_GET['channel'] ?? ''); if (!isset(FI_CHANNELS[$ch])) fi_err('Unknown channel.');
        $day = fi_today($pdo); $start = fi_start($pdo); $booked = fi_booked($pdo, $start, $day); [$recTot] = fi_received($pdo, '2000-01-01', $day);
        $st = fi_channel_state($booked[$ch], $recTot[$ch] ?? 0, $day); $orders = null;
        if (in_array($ch, ['online_cod', 'online_prepaid'], true)) {
            $all = array_filter(fi_open_orders($pdo, $ch, $start, $day), fn($o) => !$o['closed']); $settled = array_filter($all, fn($o) => $o['settled']); $wait = array_values(array_filter($all, fn($o) => !$o['settled']));
            usort($wait, fn($a, $b) => strcmp($a['day'], $b['day']));
            $orders = ['booked' => count($all), 'settled' => count($settled), 'waiting' => count($wait), 'waiting_amount' => round(array_sum(array_column($wait, 'amount')), 2), 'list' => array_slice($wait, 0, 60)];
        }
        json_response(['channel' => $ch, 'label' => FI_CHANNELS[$ch][0], 'state' => $st, 'orders' => $orders, 'start' => $start]);
        break;
    }

    case 'fi.receipt_save': {
        $in = json_input(); $day = fi_day($in['date'] ?? '') ?: fi_today($pdo); $ch = (string)($in['channel'] ?? ''); $amt = round((float)($in['amount'] ?? 0), 2); $aid = (int)($in['account_id'] ?? 0);
        if (!isset(FI_CHANNELS[$ch])) fi_err('Choose what this money is for (the channel).'); if ($amt <= 0) fi_err('Enter the amount that came.'); if ($amt > 100000000) fi_err('That amount looks wrong.');
        if ($day > fi_today($pdo) && $day > date('Y-m-d')) fi_err('The date is in the future.');
        $a = $pdo->prepare("SELECT name FROM fi_accounts WHERE id = ? AND active = 1"); $a->execute([$aid]); $an = $a->fetchColumn(); if ($an === false) fi_err('Choose the bank account or cash box where it was received.');
        $ref = trim(mb_substr((string)($in['ref'] ?? ''), 0, 120)); $payer = trim(mb_substr((string)($in['payer'] ?? ''), 0, 120)); $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 255));
        if ($ref !== '') { $d = $pdo->prepare("SELECT receipt_no FROM fi_receipts WHERE status = 'ok' AND ref = ? AND account_id = ? AND amount = ?"); $d->execute([$ref, $aid, $amt]); $x = $d->fetchColumn(); if ($x && empty($in['force'])) json_response(['error' => 'The same reference, account and amount is already in receipt ' . $x . '. Tick "save anyway" if this is a different payment.', 'duplicate' => $x], 409); }
        $pdo->prepare("INSERT INTO fi_receipts (rdate, account_id, account_name, channel, amount, ref, payer, note, source, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,'manual',?,?)")->execute([$day, $aid, $an, $ch, $amt, $ref, $payer, $note, fi_who($auth), date('Y-m-d H:i:s')]);
        $id = (int)$pdo->lastInsertId(); $no = 'RC-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); $pdo->prepare("UPDATE fi_receipts SET receipt_no = ? WHERE id = ?")->execute([$no, $id]);
        $r = $pdo->prepare("SELECT * FROM fi_receipts WHERE id = ?"); $r->execute([$id]); $row = $r->fetch(); $cn = fi_claude_note($pdo, $row); if ($cn !== '') { $pdo->prepare("UPDATE fi_receipts SET claude_note = ? WHERE id = ?")->execute([$cn, $id]); $row['claude_note'] = $cn; } json_response(['ok' => true, 'receipt' => fi_receipt_row($row)]);
        break;
    }
    case 'fi.receipt_get': {
        $r = $pdo->prepare("SELECT * FROM fi_receipts WHERE id = ? OR receipt_no = ?"); $r->execute([(int)($_GET['id'] ?? 0), (string)($_GET['no'] ?? '')]); $row = $r->fetch(); if (!$row) fi_err('Receipt not found.', 404);
        $co = ['name' => 'Fourpirates Ventures LLP', 'address' => '', 'gstin' => '', 'brand' => 'TM Perfume House'];
        if (!function_exists('st_company') && is_file(__DIR__ . '/store_lib.php')) { try { require_once __DIR__ . '/store_lib.php'; } catch (Throwable $e) {} }
        if (function_exists('st_company')) { $c = st_company($pdo); $co = ['name' => $c['name'], 'address' => $c['address'], 'gstin' => $c['gstin'], 'brand' => $c['brand']]; }
        $settled = $pdo->prepare("SELECT COUNT(*) FROM fi_settled WHERE receipt_id = ?"); $settled->execute([(int)$row['id']]);
        json_response(['receipt' => fi_receipt_row($row), 'company' => $co, 'orders_proved' => (int)$settled->fetchColumn()]);
        break;
    }
    case 'fi.receipt_void': {
        $in = json_input(); $reason = trim(mb_substr((string)($in['reason'] ?? ''), 0, 200)); if (mb_strlen($reason) < 3) fi_err('Write why this receipt is cancelled.');
        $u = $pdo->prepare("UPDATE fi_receipts SET status = 'void', void_reason = ? WHERE id = ? AND status = 'ok'"); $u->execute([$reason, (int)($in['id'] ?? 0)]); if (!$u->rowCount()) fi_err('Receipt not found, or already cancelled.', 404);
        $pdo->prepare("DELETE FROM fi_settled WHERE receipt_id = ?")->execute([(int)$in['id']]);      // the orders it proved are open again
        json_response(['ok' => true]);
        break;
    }

    // an Excel / CSV: Claude reads what it is, we show the result, nothing is saved until the person confirms
    case 'fi.upload_preview': {
        $f = $_FILES['file'] ?? null; if (!$f || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK) fi_err('The file did not arrive. Try again.');
        if ((int)$f['size'] > 12 * 1024 * 1024) fi_err('The file is too big (max 12 MB).'); $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)); if (!in_array($ext, ['xlsx', 'csv', 'txt', 'tsv'], true)) fi_err('Upload an Excel (.xlsx) or CSV file. An old .xls: open it and Save as .xlsx first.');
        try { $rows = fi_read_sheet((string)$f['tmp_name'], (string)$f['name']); } catch (Throwable $e) { fi_err($e->getMessage()); }
        if (count($rows) < 2) fi_err('The file has no data rows.');
        $a = fi_analyse($rows, (string)$f['name']); $recv = fi_file_rows($rows, $a);
        $pdo->prepare("INSERT INTO fi_uploads (file_name, analysis, rows_json, created_by, created_at) VALUES (?,?,?,?,?)")->execute([mb_substr((string)$f['name'], 0, 200), json_encode($a), json_encode($recv), fi_who($auth), date('Y-m-d H:i:s')]);
        $uid = (int)$pdo->lastInsertId();         $isPine = fi_is_pine($a, (string)$f['name']);
        if ($isPine) { [$stM, $stA] = fi_store_map($pdo); $cls = fi_pine_classify($recv, $stM, $stA, fi_order_map($pdo, 'online_cod'), fi_order_map($pdo, 'online_prepaid'), fi_pine_seen($pdo)); }
        else { $cls = fi_classify($recv, $a['channel'], in_array($a['channel'], ['online_cod', 'online_prepaid'], true) ? fi_order_map($pdo, $a['channel']) : [], ($a['cols']['order'] ?? null) !== null || ($a['cols']['awb'] ?? null) !== null, fi_awb_map($pdo)); $cls = fi_mark_seen($pdo, $cls, (string)$a['channel']); }
        $by = []; foreach ($cls as $r) { $c = $r['cls']; if (!isset($by[$c])) $by[$c] = ['n' => 0, 'amount' => 0.0]; $by[$c]['n']++; $by[$c]['amount'] += $r['amount']; }
        $counted = array_filter($cls, fn($r) => in_array($r['cls'], ['matched', 'no_order', 'before_start', 'unknown', 'noref', 'badref'], true)); $total = array_sum(array_column($counted, 'amount')); $match = $by['matched']['n'] ?? 0; $matchAmt = $by['matched']['amount'] ?? 0.0;
        $days = []; foreach ($counted as $r) { $d = $r['day'] ?: 'no date'; $days[$d] = ($days[$d] ?? 0) + $r['amount']; } ksort($days);
        $uN = 0; $uA = 0.0; if (!$isPine) foreach ($counted as $r) if (fi_row_untracked($r, (string)$a['channel'], $r['day'] ?: fi_today($pdo), fi_track_from($pdo), fi_track_from_store($pdo))) { $uN++; $uA += $r['amount']; }
        json_response(['untracked' => ['n' => $uN, 'amount' => round($uA, 2)], 'pine' => $isPine, 'upload_id' => $uid, 'file' => $f['name'], 'analysis' => $a, 'rows' => count($rows) - 1 - (int)$a['header_row'], 'received_rows' => count($recv), 'counted_rows' => count($counted), 'classes' => array_map(fn($x) => ['n' => $x['n'], 'amount' => round($x['amount'], 2)], $by), 'track_from' => fi_track_from($pdo), 'total' => round($total, 2), 'orders_matched' => $match, 'orders_matched_amount' => round($matchAmt, 2), 'sums' => fi_col_sums($rows, $a), 'read' => ['amount_col' => (string)(($a['cols']['amount'] ?? null) !== null ? ($rows[(int)$a['header_row']][$a['cols']['amount']] ?? '') : ''), 'net_col' => (fi_net_col($rows, $a) !== null ? (string)$rows[(int)$a['header_row']][fi_net_col($rows, $a)] : '')], 'charge' => ['amount' => round(array_sum(array_column($counted, 'charge')), 2), 'gross' => round(array_sum(array_map(fn($r) => (float)($r['gross'] ?? $r['amount']), $counted)), 2), 'cols' => array_values(fi_charge_cols($rows, $a))], 'discount' => ['n' => count(array_filter($cls, fn($r) => ($r['disc'] ?? 0) > 0)), 'amount' => round(array_sum(array_column($cls, 'disc')), 2)],
            'by_day' => array_map(fn($d, $v) => ['day' => $d, 'amount' => round($v, 2)], array_keys($days), array_values($days)), 'sample' => array_slice($cls, 0, 8), 'header' => $rows[(int)$a['header_row']] ?? []]);
        break;
    }
    case 'fi.upload_commit': {
        $in = json_input(); $u = $pdo->prepare("SELECT * FROM fi_uploads WHERE id = ? AND status = 'preview'"); $u->execute([(int)($in['upload_id'] ?? 0)]); $up = $u->fetch(); if (!$up) fi_err('This upload was already saved, or not found.', 404);
        $a = json_decode((string)$up['analysis'], true) ?: []; $recv = json_decode((string)$up['rows_json'], true) ?: []; if (fi_is_pine($a, (string)$up['file_name'])) {      // Pine Labs: matched by the order id typed on the machine
            $aid0 = (int)($in['account_id'] ?? 0); $ac0 = $pdo->prepare("SELECT name FROM fi_accounts WHERE id = ? AND active = 1"); $ac0->execute([$aid0]); $an0 = $ac0->fetchColumn(); if ($an0 === false) fi_err('Choose the account where this money was received.');
            if (!$recv) fi_err('There is no received money in this file.'); json_response(fi_pine_commit($pdo, $up, $a, $recv, $aid0, (string)$an0, fi_who($auth), fi_day($in['date'] ?? '') ?: fi_today($pdo)));
        }
        $ch = (string)($in['channel'] ?? ($a['channel'] ?? '')); if (!isset(FI_CHANNELS[$ch])) fi_err('Choose what this money is for (the channel).');
        $aid = (int)($in['account_id'] ?? 0); $ac = $pdo->prepare("SELECT name FROM fi_accounts WHERE id = ? AND active = 1"); $ac->execute([$aid]); $an = $ac->fetchColumn(); if ($an === false) fi_err('Choose the account where this money was received.');
        if (!$recv) fi_err('There is no received money in this file.'); $fallback = fi_day($in['date'] ?? '') ?: fi_today($pdo);
        $orderBased = in_array($ch, ['online_cod', 'online_prepaid'], true); $known = $orderBased ? fi_order_map($pdo, $ch) : [];
        $recv = fi_classify($recv, $ch, $known, ($a['cols']['order'] ?? null) !== null || ($a['cols']['awb'] ?? null) !== null, fi_awb_map($pdo)); $recv = fi_mark_seen($pdo, $recv, $ch); $okc = ['matched', 'no_order', 'before_start', 'unknown'];
        $ignored = count(array_filter($recv, fn($r) => !in_array($r['cls'], $okc, true))); $recv = array_values(array_filter($recv, fn($r) => in_array($r['cls'], $okc, true)));
        if (!$recv) fi_err('Nothing in this file counts: every row is for an order before the tracking start day, an unknown order, or already proved.');
        $groups = []; foreach ($recv as $r) { $d = $r['day'] !== '' && $r['day'] <= date('Y-m-d') ? $r['day'] : $fallback; $groups[$d . '|' . (fi_row_untracked($r, $ch, $d, fi_track_from($pdo), fi_track_from_store($pdo)) ? 1 : 0)][] = $r; } ksort($groups);
        $made = []; $proved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($groups as $gk => $rs) {
                [$d, $unt] = explode('|', $gk); $unt = (int)$unt;
                $sum = round(array_sum(array_column($rs, 'amount')), 2); $ref = mb_substr($up['file_name'] . ' · ' . count($rs) . ' rows' . ($unt ? ' · older orders' : ''), 0, 120);
                $pdo->prepare("INSERT INTO fi_receipts (rdate, account_id, account_name, channel, amount, ref, note, source, upload_id, created_by, created_at, untracked) VALUES (?,?,?,?,?,?,?,'file',?,?,?,?)")
                    ->execute([$d, $aid, $an, $ch, $sum, $ref, mb_substr((($a['provider'] ?? '') !== '' ? 'From ' . $a['provider'] . ' file' : 'From uploaded file') . ($unt ? ' · for orders before the tracking start day (not tracked)' : ''), 0, 255), (int)$up['id'], fi_who($auth), date('Y-m-d H:i:s'), $unt]);
                $id = (int)$pdo->lastInsertId(); $chg = round(array_sum(array_column($rs, 'charge')), 2);
                if ($chg > 0) $pdo->prepare("INSERT INTO fi_charges (receipt_id, rdate, channel, provider, amount, note, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$id, $d, $ch, mb_substr((string)($a['provider'] ?? ''), 0, 60), $chg, mb_substr($up['file_name'] . ' · ' . count($rs) . ' rows', 0, 255), date('Y-m-d H:i:s')]);
                $no = 'RC-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); $pdo->prepare("UPDATE fi_receipts SET receipt_no = ? WHERE id = ?")->execute([$no, $id]); $made[] = $no;
                if (!empty($a['summary'])) $pdo->prepare("UPDATE fi_receipts SET claude_note = ? WHERE id = ?")->execute([mb_substr((string)$a['summary'], 0, 480), $id]);
                foreach ($rs as $r) { if (!empty($r['rk'])) { try { $pdo->prepare("INSERT IGNORE INTO fi_seen_rows (row_key, receipt_id) VALUES (?, ?)")->execute([$r['rk'], $id]); } catch (Throwable $e) { } } $k = (string)($r['okey'] ?? fi_okey($r['order'])); if ($r['cls'] === 'matched' && $k !== '' && isset($known[$k])) { $ins = $pdo->prepare("INSERT IGNORE INTO fi_settled (order_key, channel, amount, rdate, receipt_id, disc) VALUES (?,?,?,?,?,?)"); $ins->execute([$k, $ch, $r['amount'], $d, $id, (float)($r['disc'] ?? 0)]); $proved += $ins->rowCount(); } }
            }
            $pdo->prepare("UPDATE fi_uploads SET status = 'saved' WHERE id = ?")->execute([(int)$up['id']]); $pdo->commit();
            $ticked = 0;
            if ($ch === 'online_cod') { $paidNames = []; $after = fi_order_map($pdo, $ch); foreach ($recv as $r) { $k = (string)($r['okey'] ?? ''); if ($r['cls'] === 'matched' && isset($after[$k]) && $after[$k]['settled']) $paidNames[$after[$k]['name']] = 1; } $ticked = fi_tick_dispatch($pdo, array_keys($paidNames), $made ? end($made) : ''); }
        } catch (Throwable $e) { $pdo->rollBack(); error_log('fi.upload_commit: ' . $e->getMessage()); fi_err('Could not save the receipts. Nothing was changed.', 500); }
        json_response(['ok' => true, 'receipts' => $made, 'orders_proved' => $proved, 'dispatch_ticked' => $ticked ?? 0, 'ignored_rows' => $ignored, 'charge' => round(array_sum(array_column($recv, 'charge')), 2), 'discount' => round(array_sum(array_column($recv, 'disc')), 2), 'total' => round(array_sum(array_column($recv, 'amount')), 2)]);
        break;
    }

    case 'fi.settings_get': { json_response(['track_from' => fi_track_from($pdo), 'track_from_store' => fi_track_from_store($pdo), 'wait_days' => fi_wait_days($pdo), 'keep_days' => fi_keep_days($pdo), 'auto_remit' => fi_auto_remit($pdo) ? 1 : 0, 'today' => fi_today($pdo)]); break; }
    case 'fi.settings_save': {
        $in = json_input(); $d = fi_day($in['track_from'] ?? ''); if ($d === '') fi_err('Pick the day tracking starts.'); if ($d > date('Y-m-d', strtotime('+45 day'))) fi_err('That day is too far ahead.');
        $wd = max(1, min(60, (int)($in['wait_days'] ?? 10))); $kd = max(7, min(60, (int)($in['keep_days'] ?? 20))); $ar = !isset($in['auto_remit']) || !empty($in['auto_remit']) ? '1' : '0';
        $ds = fi_day($in['track_from_store'] ?? '') ?: fi_track_from_store($pdo);
        foreach (['track_from' => $d, 'track_from_store' => $ds, 'wait_days' => (string)$wd, 'keep_days' => (string)$kd, 'auto_remit' => $ar] as $k => $v) $pdo->prepare("INSERT INTO fi_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]);
        json_response(['ok' => true, 'track_from' => $d, 'track_from_store' => $ds, 'wait_days' => $wd, 'keep_days' => $kd, 'auto_remit' => (int)$ar]);
        break;
    }

    case 'fi.summary': {
        $to = fi_day($_GET['to'] ?? '') ?: fi_today($pdo); $from = fi_day($_GET['from'] ?? '') ?: $to; if ($from > $to) { $t = $from; $from = $to; $to = $t; }
        if ((strtotime($to) - strtotime($from)) > 366 * 86400) fi_err('Choose a period of at most one year.');
        json_response(fi_summary($pdo, $from, $to));
        break;
    }
    // the Dispatch rows ask here: "what is the money story of these orders?"
    case 'fi.pay_info': {
        $names = array_slice(explode(',', (string)($_GET['orders'] ?? '')), 0, 400);
        json_response(['keep_days' => fi_keep_days($pdo), 'track_from' => fi_track_from($pdo), 'info' => (object)fi_orders_info($pdo, $names)]);
        break;
    }
    // parcels: who is still waiting for money, the ones closed by hand, the refunds we owe
    case 'fi.parcels': {
        $view = (string)($_GET['view'] ?? 'waiting'); $chf = (string)($_GET['channel'] ?? 'all'); $today = fi_today($pdo); $older = isset($_GET['older']) && $_GET['older'] !== '' ? max(0, (int)$_GET['older']) : fi_wait_days($pdo);
        $chs = $chf === 'all' ? ['online_cod', 'online_prepaid'] : (in_array($chf, ['online_cod', 'online_prepaid'], true) ? [$chf] : fi_err('Unknown channel.'));
        $list = []; $sum = ['n' => 0, 'amount' => 0.0];
        if ($view === 'waiting') {
            foreach ($chs as $c) foreach (fi_open_orders($pdo, $c) as $o) {
                if ($o['settled'] || $o['closed']) continue; $age = max(0, (int)floor((strtotime($today) - strtotime($o['day'])) / 86400)); if ($age < $older) continue;
                $list[] = ['key' => $o['key'], 'name' => $o['name'], 'channel' => $c, 'channel_label' => FI_CHANNELS[$c][0], 'day' => $o['day'], 'age' => $age, 'amount' => $o['amount'], 'customer' => $o['customer'], 'phone' => $o['phone']];
            }
            usort($list, fn($a, $b) => strcmp($a['day'], $b['day']));
        } else {
            $w = $view === 'refunds' ? "AND refund_status IN ('due','held')" : ($view === 'refunds_paid' ? "AND refund_status = 'paid'" : ''); $in2 = implode(',', array_map(fn($c) => $pdo->quote($c), $chs));
            $q = $pdo->query("SELECT * FROM fi_close WHERE status = 'ok' AND channel IN ($in2) $w ORDER BY id DESC LIMIT 300");
            foreach ($q->fetchAll() as $r) $list[] = ['id' => (int)$r['id'], 'key' => $r['order_key'], 'name' => $r['order_name'], 'channel' => $r['channel'], 'channel_label' => FI_CHANNELS[$r['channel']][0] ?? $r['channel'], 'day' => $r['day'], 'amount' => (float)$r['amount'], 'kind' => $r['kind'], 'resolution' => $r['resolution'],
                'refund_status' => $r['refund_status'], 'refund_amount' => (float)$r['refund_amount'], 'refund_date' => $r['refund_date'], 'refund_ref' => $r['refund_ref'], 'customer' => $r['customer'], 'phone' => $r['phone'], 'note' => $r['note'], 'closed_by' => $r['closed_by'], 'closed_at' => $r['closed_at']];
        }
        foreach ($list as $r) { $sum['n']++; $sum['amount'] += $r['refund_amount'] ?? $r['amount']; }
        $tf = fi_track_from($pdo);
        json_response(['view' => $view, 'channel' => $chf, 'older' => $older, 'wait_days' => fi_wait_days($pdo), 'track_from' => $tf, 'list' => array_slice($list, 0, 400), 'count' => count($list), 'amount' => round($sum['amount'], 2)]);
        break;
    }
    case 'fi.close': {
        $in = json_input(); $ch = (string)($in['channel'] ?? ''); if (!in_array($ch, ['online_cod', 'online_prepaid'], true)) fi_err('Choose the channel.');
        $kind = (string)($in['kind'] ?? ''); if (!in_array($kind, ['rto', 'not_sent', 'lost'], true)) fi_err('Choose what happened: returned (RTO), not sent, or lost.');
        $res = (string)($in['resolution'] ?? 'refund'); if (!in_array($res, ['refund', 'credit', 'reship'], true)) $res = 'refund'; $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 255));
        $keys = array_values(array_unique(array_map('fi_okey', (array)($in['orders'] ?? [])))); if (!$keys) fi_err('Choose at least one order.'); if (count($keys) > 200) fi_err('Too many at once (max 200).');
        $map = fi_open_orders($pdo, $ch); $n = 0; $skip = [];
        $ins = $pdo->prepare("INSERT INTO fi_close (order_key, order_name, channel, day, amount, kind, resolution, refund_status, refund_amount, customer, phone, note, closed_by, closed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($keys as $k) {
            $o = $map[$k] ?? null; if (!$o) { $skip[] = $k . ' (not a tracked order)'; continue; } if ($o['settled']) { $skip[] = $o['name'] . ' (already paid by a file)'; continue; } if ($o['closed']) { $skip[] = $o['name'] . ' (already closed)'; continue; }
            $owed = $ch === 'online_prepaid'; $rs = !$owed ? 'none' : ($res === 'refund' ? 'due' : 'held'); $ra = $owed ? $o['amount'] : 0;
            $ins->execute([$k, $o['name'], $ch, $o['day'], $o['amount'], $kind, $owed ? $res : 'none', $rs, $ra, $o['customer'], $o['phone'], $note, fi_who($auth), date('Y-m-d H:i:s')]); $n++;
        }
        json_response(['ok' => true, 'closed' => $n, 'skipped' => $skip]);
        break;
    }
    case 'fi.close_undo': {
        $in = json_input(); $q = $pdo->prepare("SELECT * FROM fi_close WHERE id = ? AND status = 'ok'"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(); if (!$r) fi_err('Not found.', 404);
        if ($r['refund_status'] === 'paid') fi_err('The refund of this order was already paid. It cannot be re-opened.'); $pdo->prepare("UPDATE fi_close SET status = 'void' WHERE id = ?")->execute([(int)$r['id']]); json_response(['ok' => true]);
        break;
    }
    case 'fi.refund_pay': {
        $in = json_input(); $q = $pdo->prepare("SELECT * FROM fi_close WHERE id = ? AND status = 'ok' AND refund_status IN ('due','held')"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(); if (!$r) fi_err('This refund was not found, or is already paid.', 404);
        $aid = (int)($in['account_id'] ?? 0); $a = $pdo->prepare("SELECT COUNT(*) FROM fi_accounts WHERE id = ?"); $a->execute([$aid]); if (!(int)$a->fetchColumn()) fi_err('Choose the account the refund was paid from.');
        $d = fi_day($in['date'] ?? '') ?: fi_today($pdo); $ref = trim(mb_substr((string)($in['ref'] ?? ''), 0, 120)); if ($ref === '') fi_err('Enter the payment reference (UTR / UPI id).');
        $pdo->prepare("UPDATE fi_close SET refund_status = 'paid', refund_date = ?, refund_account_id = ?, refund_ref = ? WHERE id = ?")->execute([$d, $aid, $ref, (int)$r['id']]); json_response(['ok' => true]);
        break;
    }
    default: json_response(['error' => 'Unknown Finance action.'], 404);
}
