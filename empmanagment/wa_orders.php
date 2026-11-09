<?php
/**
 * wa_orders.php — live orders in the WhatsApp chat, "Needs attention" cases in Dispatch, order-update messages and Store info.
 *
 * Put this file in the SAME folder as api.php. It creates its own tables (wa_ord_*, wa_store_info) the first time it runs. PHP 7.4 safe.
 * It only READS dp_dsp_parcels / dp_dsp_events / shop_orders (never calls Shiprocket or Shopify), and sends Meta messages only through the
 * existing template module (wa_tpl.php) and the campaign sender internals (wa_camp.php).
 *
 *   wa.ord_for          GET   ?phone=      orders of this customer, matched by the LAST 10 DIGITS of the phone, plus orders linked by hand
 *   wa.ord_share        GET   ?phone=&order=   the CUSTOMER-SAFE view of one order (whitelist: status, progress times, courier, tracking no., expected date, amount, how to pay) for the card sent to the customer
 *   wa.ord_hub          GET   ?phone= [&order= &store= &sid= &bill=]   the full Order + Customer card (payment, prepaid/COD, salesperson, commission, parcel + every scan, complaints, other orders) for this chat's customer; only their own orders
 *   wa.ord_search       GET   ?phone=&q=   find an order by number / phone to link (staff pick one)
 *   wa.ord_link         POST  {phone, order_name | shopify_id, store?}      link an order to this customer (stays linked)
 *   wa.ord_unlink       POST  {phone, order_name, store?}
 *   wa.ord_urgent       POST  {phone, kind:'delivery'|'complaint', reason, note, order_name?}   delivery -> Dispatch "Needs attention"; complaint -> Complaints (cx_raise)
 *   wa.ord_cases        GET   ?status=open|done|all &count_only=1          Dispatch "Needs attention" tab    [Dispatch seats / Admin / HR]
 *   wa.ord_case_take    POST  {id}          wa.ord_case_done POST {id, note?}          wa.ord_case_note POST {id, note}
 *   wa.store_get        GET                 any login            wa.store_save POST {info:{...}}   Admin / HR
 *   wa.ord_seed         POST  {retry?}      make + submit the 5 order-update templates at Meta    [Admin / HR]
 *   wa.ord_edit / wa.ord_edit_cancel / wa.ord_edit_revert   POST {status, body, lib_id?} a new version of one message; Meta approves it, then it wins   [Admin / HR]
 *   wa.ord_photo        POST  {status,lib_id} photo on top of one order-update message (new template, sent to Meta; lib_id 0 = off)  [Admin / HR]
 *   wa.ord_settings     GET / POST {on?, statuses:{shipped:true,...}}      the automatic order-update messages    [Admin / HR]
 *
 * Other code can call: wa_ord_for(), wa_ord_bot_answer(), wa_ord_bot_handover(), wa_store_info(), wa_store_text(), wa_store_open_now(), wa_ord_seed_templates(),
 * wa_ord_tick() (called by wa_tick), wa_ord_auto_scan() / wa_ord_after_apply() (called by the Dispatch tick / live apply), wa_ord_case_open().
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wo_tables')) {

// ------------------------------------------------------------------------------------------------------------------ small helpers
/** "Now" (unix). A test can define WO_NOW (or WA_CAMP_NOW) to move the clock. */
function wo_now(): int { return defined('WO_NOW') ? (int)WO_NOW : (defined('WA_CAMP_NOW') ? (int)WA_CAMP_NOW : time()); }
function wo_ist(int $ts): DateTime { $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Asia/Kolkata')); return $d; }
/** Quiet hours for customer messages: 21:00 - 08:00 India time. */
function wo_quiet(int $ts): bool { $h = (int)wo_ist($ts)->format('G'); return $h >= 21 || $h < 8; }
/** The moment a message may go out: now, or the coming 08:00 (India time) when it is quiet. */
function wo_next_open(int $ts): int
{
    if (!wo_quiet($ts)) return $ts; $d = wo_ist($ts); if ((int)$d->format('G') >= 21) $d->modify('+1 day'); $d->setTime(8, 0, 0); return $d->getTimestamp();
}
function wo_dt(int $ts): string { return date('Y-m-d H:i:s', $ts); }
function wo_get(PDO $pdo, string $k, string $d = ''): string
{
    try { $q = $pdo->prepare("SELECT setting_value FROM hrms_settings WHERE setting_key = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false || $v === null ? $d : (string)$v; } catch (Throwable $e) { return $d; }
}
function wo_put(PDO $pdo, string $k, string $v): void
{
    try { $pdo->prepare("INSERT INTO hrms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]); } catch (Throwable $e) { }
}
/** The last 10 digits of a phone number ('' when there are fewer than 10 digits). Handles +91 / 91 / 0 / spaces / dashes and the ".0" a spreadsheet adds. */
function wo_key($n): string
{
    $s = trim((string)$n); if ($s === '') return '';
    if (preg_match('/^\d(\.\d+)?[eE]\+?\d+$/', $s)) return '';   // a number the spreadsheet turned into 9.87654321E+9: the digits are lost
    $s = preg_replace('/\.0+$/', '', $s); $d = preg_replace('/\D+/', '', (string)$s);
    return strlen($d) >= 10 ? substr($d, -10) : '';
}
/** Every 10-digit key inside a field that may hold more than one number ("98765 43210, 91234 56789"). */
function wo_keys_of($raw): array
{
    $out = []; foreach (preg_split('/[,;\/|\n\r]+/', (string)$raw) ?: [] as $part) { $k = wo_key($part); if ($k !== '') $out[$k] = true; }
    return array_map('strval', array_keys($out));
}
/** SQL: a phone column with spaces, dashes, + ( ) and . removed. */
function wo_sqld(string $col): string { return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', '')"; }
function wo_norm_order(string $s): string { return strtolower(ltrim(trim($s), '#')); }
function wo_names(PDO $pdo): array { try { return function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; } catch (Throwable $e) { return []; } }
function wo_emp_name(PDO $pdo, int $emp): string { if ($emp <= 0) return ''; $n = wo_names($pdo); return (string)($n[$emp] ?? ''); }
/** The name of the logged-in person (employee name, else the login name). */
function wo_me_name(PDO $pdo, array $auth): string { $n = wo_emp_name($pdo, (int)($auth['employee_id'] ?? 0)); return $n !== '' ? $n : (string)($auth['username'] ?? 'Someone'); }
function wo_short(string $s, int $n): string { $s = trim(preg_replace('/\s+/u', ' ', $s) ?? ''); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }
function wo_age_text(int $sec): string
{
    if ($sec < 90) return 'just now'; $m = (int)floor($sec / 60); if ($m < 60) return $m . ' min'; $h = (int)floor($m / 60); if ($h < 48) return $h . ' h ' . ($m % 60) . ' min'; return (int)floor($h / 24) . ' days';
}

// ------------------------------------------------------------------------------------------------------------------ tables
function wo_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ord_links (
            id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, order_name VARCHAR(40) NOT NULL DEFAULT '', store_key VARCHAR(40) NOT NULL DEFAULT '', shopify_id VARCHAR(40) NOT NULL DEFAULT '',
            parcel_id INT NOT NULL DEFAULT 0, linked_by INT NOT NULL DEFAULT 0, linked_at DATETIME NOT NULL, UNIQUE KEY uq_link (phone, store_key, order_name), KEY k_phone (phone)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ord_cases (
            id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL DEFAULT '', cust_name VARCHAR(120) NOT NULL DEFAULT '', order_name VARCHAR(40) NOT NULL DEFAULT '', store_key VARCHAR(40) NOT NULL DEFAULT '',
            parcel_id INT NOT NULL DEFAULT 0, kind VARCHAR(8) NOT NULL DEFAULT 'manual', reason VARCHAR(24) NOT NULL DEFAULT 'other', note VARCHAR(500) NOT NULL DEFAULT '', raised_by INT NOT NULL DEFAULT 0,
            raised_name VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, status VARCHAR(6) NOT NULL DEFAULT 'open', owner_emp INT NOT NULL DEFAULT 0, owner_name VARCHAR(80) NOT NULL DEFAULT '',
            taken_at DATETIME NULL, done_at DATETIME NULL, done_by INT NOT NULL DEFAULT 0, done_note VARCHAR(300) NOT NULL DEFAULT '', alert_ref VARCHAR(60) NOT NULL DEFAULT '', updated_at DATETIME NULL,
            KEY k_open (status, created_at), KEY k_dedupe (phone, order_name, status)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ord_case_notes (id INT AUTO_INCREMENT PRIMARY KEY, case_id INT NOT NULL, at DATETIME NOT NULL, emp_id INT NOT NULL DEFAULT 0, who VARCHAR(80) NOT NULL DEFAULT '',
            kind VARCHAR(8) NOT NULL DEFAULT 'note', text VARCHAR(500) NOT NULL DEFAULT '', KEY k_case (case_id, id)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ord_auto (parcel_id INT NOT NULL, reason VARCHAR(24) NOT NULL, case_id INT NOT NULL DEFAULT 0, at DATETIME NOT NULL, PRIMARY KEY (parcel_id, reason)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ord_sent (
            id INT AUTO_INCREMENT PRIMARY KEY, parcel_id INT NOT NULL, phone VARCHAR(12) NOT NULL DEFAULT '', order_name VARCHAR(40) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL, tpl_name VARCHAR(80) NOT NULL DEFAULT '',
            state VARCHAR(8) NOT NULL DEFAULT 'queued', wamid VARCHAR(120) NOT NULL DEFAULT '', err VARCHAR(200) NOT NULL DEFAULT '', err_code INT NOT NULL DEFAULT 0, tries INT NOT NULL DEFAULT 0, number_id INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, due_at DATETIME NOT NULL, sent_at DATETIME NULL, at DATETIME NULL, UNIQUE KEY uq_once (parcel_id, status), KEY k_due (state, due_at), KEY k_phone (phone), KEY k_sent (state, sent_at)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_store_info (k VARCHAR(30) NOT NULL PRIMARY KEY, v MEDIUMTEXT NULL, updated_at DATETIME NULL, updated_by INT NOT NULL DEFAULT 0) $E");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

// ------------------------------------------------------------------------------------------------------------------ the courier words (EDIT HERE to change how a Shiprocket status is read)
/** Courier status text (dp_dsp_parcels.sr_status, set by Shiprocket live tracking) -> our word. The FIRST rule that matches wins. */
function wo_status_rules(): array
{
    return [
        ['rto',       '/\brto\b|return(ed)? to (origin|seller|sender)|return initiated|returning to/i'],
        ['lost',      '/\blost\b|shipment lost|\bmissing\b/i'],
        ['damaged',   '/damag|destroy|broken|\bleak/i'],
        ['failed',    '/\bndr\b|undeliver|not deliver|deliver\w* (attempt )?(failed|unsuccessful)|attempt(ed)? (failed|unsuccessful)|failed (delivery )?attempt|delivery attempted|customer (unavailable|not available|refus)|consignee (unavailable|refus)|address (incomplete|issue|not found|wrong)|not reachable/i'],
        ['ofd',       '/out for delivery|\bofd\b/i'],
        ['delivered', '/deliver/i'],
        ['delayed',   '/delay|misroute|\bon hold\b|\bheld\b|hold at/i'],
        ['shipped',   '/picked up|in[ -]?transit|shipped|reached|destination|handover|dispatched|\bhub\b/i'],
        ['packed',    '/pickup (scheduled|generated|rescheduled|exception)|ready to ship|^new$|manifest|awb (assigned|generated)|label generated/i'],
    ];
}
/** Our word for a parcel: placed | packed | shipped | ofd | delivered | failed | delayed | rto | lost | damaged | cancelled | fulfilled */
function wo_class(array $p, ?int $nowTs = null): string
{
    $nowTs = $nowTs ?? wo_now(); $st = (string)($p['status'] ?? ''); $sr = trim((string)($p['sr_status'] ?? ''));
    if ($st === 'cancelled') return 'cancelled';
    if ($st === 'delivered') return 'delivered';
    if ($st === 'rto') return 'rto';
    $c = '';
    if ($sr !== '') foreach (wo_status_rules() as $r) if (preg_match($r[1], $sr)) { $c = $r[0]; break; }
    if ($c === '') $c = $st === 'transit' ? 'shipped' : ($st === 'tracked' ? 'packed' : 'placed');
    if ($c === 'shipped' && !empty($p['etd']) && strtotime((string)$p['etd'] . ' 23:59:59') < $nowTs) $c = 'delayed';   // the courier's own date has passed
    return $c;
}
function wo_class_label(string $c): string
{
    $m = ['placed' => 'Order received', 'packed' => 'Packed', 'shipped' => 'On the way', 'ofd' => 'Out for delivery', 'delivered' => 'Delivered', 'failed' => 'Delivery failed', 'delayed' => 'Running late', 'rto' => 'Returning to us',
          'lost' => 'Lost', 'damaged' => 'Damaged', 'cancelled' => 'Cancelled', 'fulfilled' => 'Sent from store'];
    return $m[$c] ?? ucfirst($c);
}
function wo_status_text(string $c, array $p): string
{
    $co = trim((string)($p['courier'] ?? '')); $co = $co !== '' ? $co : 'the courier'; $sr = trim((string)($p['sr_status'] ?? ''));
    switch ($c) {
        case 'placed': return 'Order received. It has not been handed to a courier yet.';
        case 'packed': return 'Packed. ' . ($co !== 'the courier' ? $co : 'The courier') . ' has not scanned it yet.';
        case 'shipped': return 'On the way with ' . $co . '.';
        case 'ofd': return 'Out for delivery with ' . $co . '.';
        case 'delivered': return !empty($p['delivered_at']) ? 'Delivered on ' . date('j M Y', strtotime((string)$p['delivered_at'])) . '.' : 'Delivered.';
        case 'failed': return 'The delivery attempt failed' . ($sr !== '' ? ' (' . mb_strtolower($sr) . ')' : '') . '. The customer may need a call.';
        case 'delayed': return !empty($p['etd']) ? 'Running late with ' . $co . ': the courier date, ' . date('j M', strtotime((string)$p['etd'])) . ', has passed.' : 'Delayed with ' . $co . '.';
        case 'rto': return 'The parcel is returning to us (RTO).';
        case 'lost': return 'The courier reports the parcel as lost.';
        case 'damaged': return 'The courier reports the parcel as damaged.';
        case 'cancelled': return 'Order cancelled.';
        case 'fulfilled': return 'Marked as sent in the store. No courier details yet.';
    }
    return $sr !== '' ? $sr : 'Status not known yet.';
}

// ------------------------------------------------------------------------------------------------------------------ orders of a customer
function wo_find_parcels(PDO $pdo, string $key, int $limit = 40): array
{
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE " . wo_sqld('phone') . " LIKE ? ORDER BY created_at DESC LIMIT " . (int)$limit * 2); $q->execute(['%' . $key . '%']); $o = [];
    foreach ($q->fetchAll() as $r) if (in_array($key, wo_keys_of($r['phone']), true)) { $o[] = $r; if (count($o) >= $limit) break; }
    return $o;
}
function wo_find_shop(PDO $pdo, string $key, int $limit = 40): array
{
    $q = $pdo->prepare("SELECT * FROM shop_orders WHERE " . wo_sqld('phone') . " LIKE ? ORDER BY created_at DESC LIMIT " . (int)$limit * 2); $q->execute(['%' . $key . '%']); $o = [];
    foreach ($q->fetchAll() as $r) if (in_array($key, wo_keys_of($r['phone']), true)) { $o[] = $r; if (count($o) >= $limit) break; }
    return $o;
}
function wo_parcel_by(PDO $pdo, string $store, string $sid, string $name = ''): ?array
{
    if ($sid !== '') { $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE shopify_id = ?" . ($store !== '' ? " AND store_key = ?" : "") . " ORDER BY created_at DESC LIMIT 1"); $q->execute($store !== '' ? [$sid, $store] : [$sid]); $r = $q->fetch(); if ($r) return $r; }
    if ($name !== '') { $n = wo_norm_order($name); $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE (LOWER(order_name) = ? OR LOWER(order_name) = ?)" . ($store !== '' ? " AND store_key = ?" : "") . " ORDER BY created_at DESC LIMIT 1"); $q->execute($store !== '' ? [$n, '#' . $n, $store] : [$n, '#' . $n]); $r = $q->fetch(); if ($r) return $r; }
    return null;
}
function wo_shop_by(PDO $pdo, string $store, string $sid, string $name = ''): ?array
{
    if ($sid !== '') { $q = $pdo->prepare("SELECT * FROM shop_orders WHERE shopify_id = ?" . ($store !== '' ? " AND store_key = ?" : "") . " ORDER BY created_at DESC LIMIT 1"); $q->execute($store !== '' ? [$sid, $store] : [$sid]); $r = $q->fetch(); if ($r) return $r; }
    if ($name !== '') { $n = wo_norm_order($name); $q = $pdo->prepare("SELECT * FROM shop_orders WHERE (LOWER(order_name) = ? OR LOWER(order_name) = ?)" . ($store !== '' ? " AND store_key = ?" : "") . " ORDER BY created_at DESC LIMIT 1"); $q->execute($store !== '' ? [$n, '#' . $n, $store] : [$n, '#' . $n]); $r = $q->fetch(); if ($r) return $r; }
    return null;
}
function wo_events(PDO $pdo, int $pid, int $limit = 10): array
{
    $q = $pdo->prepare("SELECT `at`, label, activity, location FROM dp_dsp_events WHERE parcel_id = ? ORDER BY `at` DESC, id DESC LIMIT " . (int)$limit); $q->execute([$pid]); $o = [];
    foreach ($q->fetchAll() as $e) $o[] = ['at' => (string)$e['at'], 'label' => (string)$e['label'], 'place' => (string)$e['location'], 'activity' => (string)$e['activity']];
    return $o;
}
function wo_send_defs(): array
{
    return ['received' => ['tmph_order_received', 'Order received — thank you'], 'dispatch_late' => ['tmph_order_dispatch_late', 'Sorry for the delay — not shipped in 72 hours'], 'shipped' => ['tmph_order_shipped', 'Shipped'], 'out_for_delivery' => ['tmph_order_out_for_delivery', 'Out for delivery'], 'delivered' => ['tmph_order_delivered', 'Delivered'],
            'failed' => ['tmph_delivery_attempt_failed', 'Delivery attempt failed'], 'delayed' => ['tmph_delivery_delayed', 'Delayed'], 'care_d2' => ['tmph_care_d2', 'Check-in (2 days after delivery)'], 'return_d15' => ['tmph_return_d15', 'Take-back offer (15 days after delivery)'], 'return_d30' => ['tmph_return_d30', 'No-questions return offer (30 days after delivery)'], 'review' => ['tmph_review_request', 'Rating request (5 days after delivery)'], 'review_nudge' => ['tmph_review_nudge', 'Review reminder (2 days after a 5-star rating)'], 'birthday' => ['tmph_birthday_gift', 'Birthday gift (on the birthday)'], 'bday_ask' => ['tmph_birthday_ask', 'Birthday question (1 hour after the order)']];
}
/** Which automatic message belongs to a parcel word ('' = none). */
function wo_send_status(string $cls): string { $m = ['shipped' => 'shipped', 'ofd' => 'out_for_delivery', 'delivered' => 'delivered', 'failed' => 'failed', 'delayed' => 'delayed']; return $m[$cls] ?? ''; }
function wo_sent_for(PDO $pdo, array $pids): array
{
    $pids = array_values(array_unique(array_filter(array_map('intval', $pids)))); if (!$pids) return []; $defs = wo_send_defs(); $out = [];
    $q = $pdo->query("SELECT * FROM wa_ord_sent WHERE parcel_id IN (" . implode(',', $pids) . ") AND state IN ('sent','queued') ORDER BY id");
    foreach ($q->fetchAll() as $r) {
        $when = (string)($r['sent_at'] ?: $r['due_at']); $lab = $defs[$r['status']][1] ?? $r['status'];
        $out[(int)$r['parcel_id']][] = ['status' => $r['status'], 'label' => $lab, 'state' => $r['state'], 'at' => $when, 'text' => $r['state'] === 'sent' ? 'Update sent: ' . $lab . ', ' . (date('Y-m-d', strtotime($when)) === date('Y-m-d', wo_now()) ? '' : date('j M', strtotime($when)) . ' ') . date('H:i', strtotime($when)) : 'Update queued: ' . $lab . ', goes out ' . date('j M H:i', strtotime($when))];
    }
    return $out;
}
function wo_view(PDO $pdo, ?array $p, ?array $s, string $match, array $sent): array
{
    $now = wo_now(); $name = $p ? (string)$p['order_name'] : (string)$s['order_name']; $store = $p ? (string)$p['store_key'] : (string)$s['store_key'];
    if ($p) { $cls = wo_class($p, $now); }
    else { $ful = strtolower((string)($s['fulfillment_status'] ?? '')); $cls = $ful === 'fulfilled' ? 'fulfilled' : 'placed'; if (preg_match('/refund|void/', strtolower((string)($s['financial_status'] ?? '')))) $cls = 'cancelled'; }
    $arr = $p ? $p : ['status' => '', 'courier' => '', 'sr_status' => '', 'etd' => null, 'delivered_at' => null];
    $ev = $p ? wo_events($pdo, (int)$p['id'], 10) : []; $last = $ev ? $ev[0] : null;
    $etd = $p && !empty($p['etd']) ? (string)$p['etd'] : null;
    $etdText = ''; if ($cls !== 'delivered' && $cls !== 'cancelled' && $cls !== 'rto') $etdText = $etd ? 'Courier date: ' . date('D j M', strtotime($etd)) : 'no date from the courier yet';
    $fin = $s ? (string)$s['financial_status'] : ''; $cod = preg_match('/pending|cod|cash/i', $fin) ? 'Pay on delivery / pending' : ($fin !== '' ? ucfirst(str_replace('_', ' ', $fin)) : '');
    return ['order_name' => $name, 'store' => $store, 'placed_at' => $p ? (string)$p['created_at'] : (string)$s['created_at'], 'total' => $s ? (float)$s['total'] : null, 'payment' => $cod, 'fin' => strtolower($fin),
        'courier' => $p ? (string)$p['courier'] : '', 'tracking_no' => $p ? (string)$p['tracking_no'] : '', 'track_link' => $p ? ((string)$p['track_link'] !== '' ? (string)$p['track_link'] : ((string)$p['tracking_no'] !== '' && function_exists('dp_dsp_link_for') ? (string)@dp_dsp_link_for($pdo, (string)$p['courier'], (string)$p['tracking_no']) : '')) : '',
        'status' => $cls, 'status_label' => wo_class_label($cls), 'status_text' => wo_status_text($cls, $arr), 'sr_status' => $p ? (string)$p['sr_status'] : '', 'etd' => $etd, 'etd_text' => $etdText,
        'delivered_at' => $p && !empty($p['delivered_at']) ? (string)$p['delivered_at'] : null, 'last_scan' => $last ? ['at' => $last['at'], 'label' => $last['label'], 'place' => $last['place']] : null,
        'timeline' => $ev, 'match' => $match, 'update_sent' => $p ? ($sent[(int)$p['id']] ?? []) : [], 'parcel_id' => $p ? (int)$p['id'] : 0, 'customer' => $p ? (string)$p['customer'] : (string)$s['customer'],
        'live_at' => $p && !empty($p['live_at']) ? (string)$p['live_at'] : null,
        'tracked_at' => $p && !empty($p['tracked_at']) ? (string)$p['tracked_at'] : null, 'transit_at' => $p && !empty($p['transit_at']) ? (string)$p['transit_at'] : null, 'ofd_at' => wo_ofd_at($ev),
        'due' => ($s && isset($s['outstanding']) && $s['outstanding'] !== null && $s['outstanding'] !== '') ? (float)$s['outstanding'] : null];
}
/** When the courier first said "out for delivery" (from the scans we hold), or null. */
function wo_ofd_at(array $ev): ?string
{
    $t = null; foreach ($ev as $e) { if (preg_match('/out for delivery/i', (string)$e['label'] . ' ' . (string)$e['activity'])) { if ($t === null || (string)$e['at'] < $t) $t = (string)$e['at']; } }
    return $t;
}

/**
 * What the CUSTOMER may see of an order — a strict whitelist. Never: commission, salesperson, gateway / payment ids, complaints,
 * Dispatch cases, other orders, lifetime numbers, raw courier remarks, the customer's address or phone.
 */
function wo_share_view(PDO $pdo, array $v): array
{
    $say = ['placed' => ['Order confirmed', 'We have received your order and are getting it ready.'], 'fulfilled' => ['Packed', 'Your order is packed. The courier details will follow shortly.'], 'packed' => ['Packed and ready to ship', 'Your order is packed and waiting for the courier to collect it.'],
        'shipped' => ['On the way to you', 'Your order has been handed to the courier and is on its way.'], 'ofd' => ['Out for delivery today', 'The courier is on the way to your address today. Please keep your phone nearby.'],
        'delivered' => ['Delivered', 'Your order has been delivered. Thank you for shopping with us!'], 'delayed' => ['Slightly delayed', 'Your parcel is taking longer than planned. We are following it up with the courier.'],
        'failed' => ['Delivery attempt unsuccessful', 'The courier could not deliver on the last try. Our team will contact you to fix it.'], 'rto' => ['Being checked by our team', 'Our team is checking your parcel with the courier and will contact you shortly.'],
        'lost' => ['Being checked by our team', 'Our team is checking your parcel with the courier and will contact you shortly.'], 'damaged' => ['Being checked by our team', 'Our team is checking your parcel with the courier and will contact you shortly.'],
        'cancelled' => ['Order cancelled', 'This order has been cancelled.']];
    $st = (string)$v['status']; $m = $say[$st] ?? ['Order update', ''];
    $cod = (bool)preg_match('/pending|cod|cash/i', (string)($v['payment'] ?? '')); $due = $cod ? ($v['due'] !== null ? (float)$v['due'] : (float)($v['total'] ?? 0)) : 0.0;
    $pay = $st === 'cancelled' ? '' : ($cod && $due > 0 && $st !== 'delivered' ? 'Pay ₹' . number_format($due, 0, '.', ',') . ' on delivery' : (($v['payment'] ?? '') !== '' ? ($cod ? 'Pay on delivery' : 'Paid') : ''));
    $info = function_exists('wa_store_info') ? wa_store_info($pdo) : [];
    $lk = trim((string)$v['track_link']);      // no saved link → build the courier's own tracking page from the courier name + tracking number (Blue Dart, Delhivery...)
    if ($lk === '' && trim((string)$v['tracking_no']) !== '' && function_exists('dp_dsp_link_for')) { try { $lk = (string)dp_dsp_link_for($pdo, (string)$v['courier'], (string)$v['tracking_no']); } catch (Throwable $e) { } }
    $v['track_link'] = $lk;
    return ['order_name' => (string)$v['order_name'], 'status' => $st, 'headline' => $m[0], 'text' => $m[1], 'placed_at' => (string)$v['placed_at'], 'tracked_at' => $v['tracked_at'], 'transit_at' => $v['transit_at'], 'ofd_at' => $v['ofd_at'], 'delivered_at' => $v['delivered_at'],
        'courier' => (string)$v['courier'], 'tracking_no' => (string)$v['tracking_no'], 'track_link' => (string)$v['track_link'], 'etd' => $v['etd'], 'amount' => $v['total'], 'pay' => $pay,
        'last_place' => !empty($v['last_scan']['place']) ? (string)$v['last_scan']['place'] : '', 'brand' => trim((string)($info['name'] ?? '')) !== '' ? (string)$info['name'] : 'TM Perfume House', 'store_phone' => (string)($info['phone'] ?? '')];
}
/** Everything the chat panel needs: {orders, linked, none, phone}. Reads the database only. */
function wa_ord_for(PDO $pdo, $phone): array
{
    wo_tables($pdo); $key = wo_key($phone); $out = ['orders' => [], 'linked' => [], 'none' => true, 'phone' => $key];
    if ($key === '') return $out;
    $rows = []; $shopOf = [];   // "store|shopify_id" => ['p'=>parcel|null,'s'=>shop|null,'match'=>..]
    $add = function (?array $p, ?array $s, string $match) use (&$rows) {
        $id = $p ? (string)$p['store_key'] . '|' . (string)$p['shopify_id'] : (string)$s['store_key'] . '|' . (string)$s['shopify_id'];
        if (!isset($rows[$id])) $rows[$id] = ['p' => $p, 's' => $s, 'match' => $match];
        else { if ($p && !$rows[$id]['p']) $rows[$id]['p'] = $p; if ($s && !$rows[$id]['s']) $rows[$id]['s'] = $s; if ($match === 'phone') $rows[$id]['match'] = 'phone'; }
    };
    foreach (wo_find_parcels($pdo, $key) as $p) $add($p, null, 'phone');
    foreach (wo_find_shop($pdo, $key) as $s) $add(null, $s, 'phone');
    $lq = $pdo->prepare("SELECT * FROM wa_ord_links WHERE phone = ? ORDER BY id DESC"); $lq->execute([$key]); $links = $lq->fetchAll(); $names = wo_names($pdo);
    foreach ($links as $l) {
        $p = wo_parcel_by($pdo, (string)$l['store_key'], (string)$l['shopify_id'], (string)$l['order_name']); $s = wo_shop_by($pdo, (string)$l['store_key'], (string)$l['shopify_id'], (string)$l['order_name']);
        if (!$p && !$s) continue; $add($p, $s, 'linked');
        $out['linked'][] = ['order_name' => (string)$l['order_name'], 'store' => (string)$l['store_key'], 'linked_at' => (string)$l['linked_at'], 'linked_by' => $names[(int)$l['linked_by']] ?? ''];
    }
    foreach ($rows as $id => &$r) {   // an order with a parcel row but no shop row (or the other way round): complete the pair
        if ($r['p'] && !$r['s']) $r['s'] = wo_shop_by($pdo, (string)$r['p']['store_key'], (string)$r['p']['shopify_id']);
        if ($r['s'] && !$r['p']) $r['p'] = wo_parcel_by($pdo, (string)$r['s']['store_key'], (string)$r['s']['shopify_id']);
    } unset($r);
    uasort($rows, function ($a, $b) { $x = $a['p'] ? $a['p']['created_at'] : $a['s']['created_at']; $y = $b['p'] ? $b['p']['created_at'] : $b['s']['created_at']; return strcmp((string)$y, (string)$x); });
    $rows = array_slice($rows, 0, 12, true); $pids = []; foreach ($rows as $r) if ($r['p']) $pids[] = (int)$r['p']['id'];
    $sent = wo_sent_for($pdo, $pids);
    foreach ($rows as $r) $out['orders'][] = wo_view($pdo, $r['p'], $r['s'], $r['match'], $sent);
    $out['none'] = !$out['orders'];
    return $out;
}

// ------------------------------------------------------------------------------------------------------------------ the Boss engine / Complaints / Dispatch helpers (lazy: the WhatsApp requests do not load them)
/** Loads boss.php + check.php (Dispatch seats, Boss alerts, Complaints) when this request has not got them yet. */
function wo_env(): bool
{
    static $done = null; if ($done !== null) return $done;
    global $pdo, $action; $done = false;
    try {
        if (!function_exists('b2_alert') && is_file(__DIR__ . '/boss.php')) require_once __DIR__ . '/boss.php';
        if (!function_exists('dp_can_seat') && is_file(__DIR__ . '/check.php')) require_once __DIR__ . '/check.php';
        $done = function_exists('b2_alert');
    } catch (Throwable $e) { error_log('wa_orders env: ' . $e->getMessage()); }
    return $done;
}
function wo_chat_event(PDO $pdo, string $key, string $text): void
{
    if ($key === '') return;
    if (function_exists('wa_event')) { wa_event($pdo, $key, $text); return; }
    try { $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, ts) VALUES (?, ?, 0, 'sys', 'event', ?, 'sent', 'system', ?)")->execute(['sys.' . uniqid('', true), $key, mb_substr($text, 0, 300), wo_dt(wo_now())]); } catch (Throwable $e) { }
}
function wo_alert_text(array $c): string
{
    $R = wo_reasons(); $who = $c['cust_name'] !== '' ? $c['cust_name'] : ($c['phone'] !== '' ? '+91 ' . $c['phone'] : 'A customer');
    return 'Needs attention (Dispatch): ' . $who . ($c['order_name'] !== '' ? ', order ' . $c['order_name'] : '') . ' — ' . ($R[$c['reason']] ?? $c['reason']) . ($c['note'] !== '' ? ': ' . wo_short((string)$c['note'], 120) : '') . '.';
}
/** The Boss-engine alert (b2_alert) and the push to the Dispatch lead. One alert per manual case; automatic cases share one alert per hour (a digest). */
function wo_raise(PDO $pdo, array $c, bool $auto): void
{
    if (!wo_env()) return;
    try {
        static $ens = false; if (!$ens && function_exists('b2_ensure')) { b2_ensure($pdo); $ens = true; }
        if ($auto) { wo_digest($pdo, (string)$c['alert_ref']); return; }
        b2_alert($pdo, 'system', wo_alert_text($c), 'dispatch', (string)$c['alert_ref']);
        if (function_exists('dp_dsp_push_holders')) dp_dsp_push_holders($pdo, 'dispatch.lead', 'Needs attention', wo_short(wo_alert_text($c), 150));
    } catch (Throwable $e) { error_log('wa_orders alert: ' . $e->getMessage()); }
}
/** One alert for all the cases the system opened in the same hour ("3 parcels need attention"). */
function wo_digest(PDO $pdo, string $ref): void
{
    if (!wo_env() || $ref === '') return;
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ord_cases WHERE alert_ref = ? AND status = 'open'"); $q->execute([$ref]); $n = (int)$q->fetchColumn(); if ($n < 1) return;
        $q = $pdo->prepare("SELECT order_name, reason FROM wa_ord_cases WHERE alert_ref = ? AND status = 'open' ORDER BY id LIMIT 4"); $q->execute([$ref]); $R = wo_reasons(); $bits = [];
        foreach ($q->fetchAll() as $r) $bits[] = ($r['order_name'] !== '' ? $r['order_name'] : 'order') . ' (' . mb_strtolower($R[$r['reason']] ?? $r['reason']) . ')';
        $text = 'Needs attention (Dispatch): ' . $n . ' parcel' . ($n === 1 ? '' : 's') . ' with a delivery problem — ' . implode(', ', $bits) . ($n > 4 ? ' and ' . ($n - 4) . ' more' : '') . '.';
        $id = b2_alert($pdo, 'system', $text, 'dispatch', $ref);
        $pdo->prepare("UPDATE b2_alerts SET text = ? WHERE ref_key = ? AND state = 'open'")->execute([mb_substr($text, 0, 400), $ref]);
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ord_cases WHERE alert_ref = ?"); $q->execute([$ref]);
        if ((int)$q->fetchColumn() === 1 && function_exists('dp_dsp_push_holders')) dp_dsp_push_holders($pdo, 'dispatch.lead', 'Needs attention', wo_short($text, 150));
    } catch (Throwable $e) { error_log('wa_orders digest: ' . $e->getMessage()); }
}
function wo_close_alert(PDO $pdo, array $c): void
{
    if (!function_exists('b2_alert_close') || (string)$c['alert_ref'] === '') return;
    try {
        if ($c['kind'] === 'manual') { b2_alert_close($pdo, (string)$c['alert_ref']); return; }
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ord_cases WHERE alert_ref = ? AND status = 'open'"); $q->execute([$c['alert_ref']]);
        if ((int)$q->fetchColumn() === 0) b2_alert_close($pdo, (string)$c['alert_ref']); else wo_digest($pdo, (string)$c['alert_ref']);
    } catch (Throwable $e) { }
}

// ------------------------------------------------------------------------------------------------------------------ Needs attention cases
function wo_reasons(): array
{
    return ['order_missing' => 'Order missing / not found', 'delivery_problem' => 'Delivery problem', 'wrong_item' => 'Wrong item', 'change_cancel' => 'Change / cancel before pickup', 'other' => 'Other',
            'delivery_failed' => 'Delivery failed (NDR)', 'rto' => 'RTO started — parcel returning', 'lost' => 'Parcel lost', 'damaged' => 'Parcel damaged',
            'overdue_etd' => 'Past the courier date', 'overdue_due4' => 'Not in transit by the deadline', 'no_scan' => 'No courier scan since the tracking number'];
}
function wo_manual_reasons(): array { $R = wo_reasons(); return array_intersect_key($R, array_flip(['order_missing', 'delivery_problem', 'wrong_item', 'change_cancel', 'other'])); }
function wo_case_note(PDO $pdo, int $id, int $emp, string $who, string $text, string $kind = 'note'): void
{
    if (trim($text) === '') return;
    $pdo->prepare("INSERT INTO wa_ord_case_notes (case_id, at, emp_id, who, kind, text) VALUES (?,?,?,?,?,?)")->execute([$id, wo_dt(wo_now()), $emp, mb_substr($who, 0, 80), $kind, mb_substr(trim($text), 0, 500)]);
}
/** The open case for this customer + order, if any. */
function wa_ord_case_open(PDO $pdo, string $phone, string $order = ''): ?array
{
    $q = $pdo->prepare("SELECT * FROM wa_ord_cases WHERE status = 'open' AND phone = ? AND order_name = ? ORDER BY id DESC LIMIT 1"); $q->execute([wo_key($phone), trim($order)]); $r = $q->fetch(); return $r ?: null;
}
/** Opens a case, or adds to the one that is already open for the same phone + order. Returns ['id', 'created', 'case']. */
function wo_case_make(PDO $pdo, array $in): array
{
    wo_tables($pdo); $key = wo_key($in['phone'] ?? ''); $order = trim(mb_substr((string)($in['order_name'] ?? ''), 0, 40)); $reason = (string)($in['reason'] ?? 'other'); if (!isset(wo_reasons()[$reason])) $reason = 'other';
    $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 500)); $by = (int)($in['raised_by'] ?? 0); $byName = (string)($in['raised_name'] ?? ($by ? wo_emp_name($pdo, $by) : 'Dispatch (automatic)')); $auto = ($in['kind'] ?? 'manual') === 'auto';
    $ex = ($key !== '' || $order !== '') ? wa_ord_case_open($pdo, $key, $order) : null;
    if ($ex) {
        wo_case_note($pdo, (int)$ex['id'], $by, $byName, ($auto ? 'Also: ' : 'Raised again: ') . (wo_reasons()[$reason] ?? $reason) . ($note !== '' ? ' — ' . $note : ''), 'event');
        $pdo->prepare("UPDATE wa_ord_cases SET updated_at = ? WHERE id = ?")->execute([wo_dt(wo_now()), (int)$ex['id']]);
        return ['id' => (int)$ex['id'], 'created' => false, 'case' => $ex];
    }
    $cust = trim((string)($in['cust_name'] ?? '')); if ($cust === '' && $key !== '' && function_exists('wa_contact')) { $ct = wa_contact($pdo, $key); if ($ct) $cust = (string)$ct['name']; }
    $now = wo_dt(wo_now());
    $pdo->prepare("INSERT INTO wa_ord_cases (phone, cust_name, order_name, store_key, parcel_id, kind, reason, note, raised_by, raised_name, created_at, status, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'open', ?)")
        ->execute([$key, mb_substr($cust, 0, 120), $order, mb_substr((string)($in['store_key'] ?? ''), 0, 40), (int)($in['parcel_id'] ?? 0), $auto ? 'auto' : 'manual', $reason, $note, $by, mb_substr($byName, 0, 80), $now, $now]);
    $id = (int)$pdo->lastInsertId(); $ref = $auto ? 'wo:auto:' . date('YmdH', wo_now()) : 'wo:case:' . $id;
    $pdo->prepare("UPDATE wa_ord_cases SET alert_ref = ? WHERE id = ?")->execute([$ref, $id]);
    if ($note !== '') wo_case_note($pdo, $id, $by, $byName, $note, 'note');
    $q = $pdo->prepare("SELECT * FROM wa_ord_cases WHERE id = ?"); $q->execute([$id]); $c = $q->fetch();
    wo_raise($pdo, $c, $auto);
    if (!$auto && $key !== '') wo_chat_event($pdo, $key, '⚠ ' . $byName . ' sent this to Dispatch (Needs attention): ' . (wo_reasons()[$reason] ?? $reason) . ($order !== '' ? ' · ' . $order : ''));
    return ['id' => $id, 'created' => true, 'case' => $c];
}
function wo_case_view(PDO $pdo, array $c, bool $notes = true): array
{
    $now = wo_now(); $R = wo_reasons(); $end = $c['status'] === 'done' && $c['done_at'] ? strtotime((string)$c['done_at']) : $now; $age = max(0, $end - strtotime((string)$c['created_at']));
    $v = ['id' => (int)$c['id'], 'phone' => (string)$c['phone'], 'customer' => (string)$c['cust_name'], 'order_name' => (string)$c['order_name'], 'store' => (string)$c['store_key'], 'kind' => (string)$c['kind'], 'reason' => (string)$c['reason'],
        'reason_label' => $R[$c['reason']] ?? (string)$c['reason'], 'note' => (string)$c['note'], 'raised_by' => $c['kind'] === 'auto' && (int)$c['raised_by'] === 0 ? 'Dispatch (automatic)' : (string)$c['raised_name'], 'created_at' => (string)$c['created_at'],
        'age_sec' => $age, 'age_text' => wo_age_text($age), 'status' => (string)$c['status'], 'owner_emp' => (int)$c['owner_emp'], 'owner' => (string)$c['owner_name'], 'taken_at' => $c['taken_at'], 'done_at' => $c['done_at'], 'done_note' => (string)$c['done_note'],
        'chat_url' => $c['phone'] !== '' ? 'whatsapp.html?phone=' . $c['phone'] : '', 'my_chat_url' => $c['phone'] !== '' ? 'my_chats.html?phone=' . $c['phone'] : '', 'parcel' => null];
    if ((int)$c['parcel_id'] > 0) {
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $q->execute([(int)$c['parcel_id']]); $p = $q->fetch();
        if ($p) { $cls = wo_class($p, $now); $v['parcel'] = ['courier' => (string)$p['courier'], 'tracking_no' => (string)$p['tracking_no'], 'status' => $cls, 'status_text' => wo_status_text($cls, $p), 'sr_status' => (string)$p['sr_status'], 'etd' => $p['etd']]; }
    }
    if ($notes) { $q = $pdo->prepare("SELECT `at`, who, kind, text FROM wa_ord_case_notes WHERE case_id = ? ORDER BY id"); $q->execute([(int)$c['id']]); $v['notes'] = array_map(function ($n) { return ['at' => (string)$n['at'], 'who' => (string)$n['who'], 'kind' => (string)$n['kind'], 'text' => (string)$n['text']]; }, $q->fetchAll()); }
    return $v;
}
function wo_cases_list(PDO $pdo, string $status, int $limit): array
{
    $w = $status === 'done' ? "status = 'done'" : ($status === 'all' ? '1=1' : "status = 'open'");
    $rows = $pdo->query("SELECT * FROM wa_ord_cases WHERE $w ORDER BY " . ($status === 'open' ? 'created_at ASC' : 'COALESCE(done_at, created_at) DESC') . " LIMIT " . max(0, $limit))->fetchAll();
    return array_map(function ($r) use ($pdo) { return wo_case_view($pdo, $r); }, $rows);
}
function wo_case_counts(PDO $pdo, int $me): array
{
    $r = $pdo->query("SELECT SUM(status = 'open') o, SUM(status = 'open' AND owner_emp = 0) un, SUM(status = 'open' AND kind = 'auto') au FROM wa_ord_cases")->fetch();
    $m = $pdo->prepare("SELECT COUNT(*) FROM wa_ord_cases WHERE status = 'open' AND owner_emp = ?"); $m->execute([$me]);
    $d = $pdo->prepare("SELECT COUNT(*) FROM wa_ord_cases WHERE status = 'done' AND done_at >= ?"); $d->execute([date('Y-m-d 00:00:00', wo_now())]);
    return ['open' => (int)$r['o'], 'unowned' => (int)$r['un'], 'auto' => (int)$r['au'], 'mine' => (int)$m->fetchColumn(), 'done_today' => (int)$d->fetchColumn()];
}

// ------------------------------------------------------------------------------------------------------------------ automatic cases from delivery problems (called by the Dispatch live apply + tick)
/** Once per parcel and reason. $nowTs: unix. Returns the case id or 0 (already done / no phone). */
function wo_auto_case(PDO $pdo, array $p, string $reason, string $why): int
{
    wo_tables($pdo);
    $ins = $pdo->prepare("INSERT IGNORE INTO wa_ord_auto (parcel_id, reason, case_id, at) VALUES (?,?,0,?)"); $ins->execute([(int)$p['id'], $reason, wo_dt(wo_now())]);
    if ($ins->rowCount() < 1) return 0;
    $r = wo_case_make($pdo, ['phone' => $p['phone'] ?? '', 'cust_name' => (string)($p['customer'] ?? ''), 'order_name' => (string)$p['order_name'], 'store_key' => (string)$p['store_key'], 'parcel_id' => (int)$p['id'], 'kind' => 'auto', 'reason' => $reason, 'note' => $why, 'raised_by' => 0, 'raised_name' => 'Dispatch (automatic)']);
    $pdo->prepare("UPDATE wa_ord_auto SET case_id = ? WHERE parcel_id = ? AND reason = ?")->execute([(int)$r['id'], (int)$p['id'], $reason]);
    return (int)$r['id'];
}
/** The problem this parcel has RIGHT NOW from its courier word: [reason, why] or null. */
function wo_problem_of(array $p): ?array
{
    $c = wo_class($p); $sr = trim((string)($p['sr_status'] ?? ''));
    $map = ['failed' => 'delivery_failed', 'rto' => 'rto', 'lost' => 'lost', 'damaged' => 'damaged'];
    if (!isset($map[$c])) return null;
    return [$map[$c], 'Courier status: ' . ($sr !== '' ? $sr : wo_class_label($c)) . ($p['courier'] ? ' (' . $p['courier'] . ')' : '')];
}
/** Called right after Shiprocket live news changed a parcel (dept_dispatch_live.php, dp_live_apply). Never throws. */
function wa_ord_after_apply(PDO $pdo, int $parcelId): int
{
    try {
        wo_tables($pdo); $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $q->execute([$parcelId]); $p = $q->fetch(); if (!$p) return 0;
        $pr = wo_problem_of($p); if (!$pr) return 0;
        return wo_auto_case($pdo, $p, $pr[0], $pr[1]) > 0 ? 1 : 0;
    } catch (Throwable $e) { error_log('wa_ord_after_apply: ' . $e->getMessage()); return 0; }
}
/** The Dispatch tick: problems missed by the live hook, parcels past their deadline, tracking numbers nobody has scanned. Only things that happen AFTER the first run (no flood from old parcels). Never throws. */
function wa_ord_auto_scan(PDO $pdo, ?int $nowTs = null, string $onlyStore = ''): array
{
    $res = ['status' => 0, 'overdue' => 0, 'no_scan' => 0, 'closed' => 0];
    try {
        wo_tables($pdo); $nowTs = $nowTs ?? wo_now(); $go = (int)wo_get($pdo, 'wa_ord_cases_since', '0');
        if ($go <= 0) { $go = $nowTs; wo_put($pdo, 'wa_ord_cases_since', (string)$go); }
        $goS = wo_dt($go); $nowS = wo_dt($nowTs); $cap = 60; $made = 0; $sk = $onlyStore !== '' ? ' AND store_key = ' . $pdo->quote($onlyStore) : '';
        // 1. a courier word that means trouble (NDR, undelivered, failed attempt, RTO, lost, damaged), news from the last 3 days that came after go-live
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('placed','tracked','transit','rto') AND sr_at >= ? AND sr_at >= ? AND sr_status IS NOT NULL$sk ORDER BY sr_at DESC LIMIT 400"); $q->execute([$goS, wo_dt($nowTs - 3 * 86400)]);
        foreach ($q->fetchAll() as $p) { if ($made >= $cap) break; $pr = wo_problem_of($p); if ($pr && wo_auto_case($pdo, $p, $pr[0], $pr[1]) > 0) { $res['status']++; $made++; } }
        // 2. past the courier's own date (etd) while still on the way
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'transit' AND delivered_at IS NULL AND etd IS NOT NULL AND etd < ? AND etd >= ?$sk ORDER BY etd LIMIT 400"); $q->execute([date('Y-m-d', $nowTs), date('Y-m-d', $nowTs - 14 * 86400)]);
        foreach ($q->fetchAll() as $p) {
            if ($made >= $cap) break; if (strtotime((string)$p['etd'] . ' 23:59:59') < $go) continue;   // the date passed before go-live: not our news
            if (wo_problem_of($p)) continue; if (wo_auto_case($pdo, $p, 'overdue_etd', 'The courier\'s date was ' . date('j M', strtotime((string)$p['etd'])) . ' and the parcel has not arrived.' . ($p['courier'] ? ' Courier: ' . $p['courier'] . '.' : '')) > 0) { $res['overdue']++; $made++; }
        }
        // 3. not in transit by the day-4 deadline the Dispatch page already uses (due4)
        $noid = defined('DP_DSP_NOID_DAYS') ? (int)DP_DSP_NOID_DAYS : 10;
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND due4 <= ? AND due4 >= ? AND created_at >= ?$sk ORDER BY due4 LIMIT 400"); $q->execute([$nowS, $goS, wo_dt($nowTs - $noid * 86400)]);
        foreach ($q->fetchAll() as $p) { if ($made >= $cap) break; if (wo_auto_case($pdo, $p, 'overdue_due4', 'Not in transit by the day-' . (function_exists('dp_dsp_transit_days') ? dp_dsp_transit_days() : 4) . ' deadline (' . date('j M, H:i', strtotime((string)$p['due4'])) . ').') > 0) { $res['overdue']++; $made++; } }
        // 4. a tracking number the courier has not scanned for the "pick" hours the Dispatch page uses
        $ph = function_exists('dp_dsp_pick_hours') ? dp_dsp_pick_hours() : 48; $cut = wo_dt($nowTs - $ph * 3600);
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'tracked' AND tracked_at IS NOT NULL AND tracked_at <= ? AND tracked_at >= ? AND DATE_ADD(tracked_at, INTERVAL ? HOUR) >= ?$sk ORDER BY tracked_at LIMIT 400");
        $q->execute([$cut, wo_dt($nowTs - $noid * 86400), $ph, $goS]);
        foreach ($q->fetchAll() as $p) { if ($made >= $cap) break; if (wo_auto_case($pdo, $p, 'no_scan', 'Tracking number given ' . date('j M, H:i', strtotime((string)$p['tracked_at'])) . ', no courier scan in ' . $ph . ' hours.') > 0) { $res['no_scan']++; $made++; } }
        // 5. a parcel that arrived (or was cancelled) closes its own automatic case
        $q = $pdo->query("SELECT c.id, c.parcel_id FROM wa_ord_cases c WHERE c.status = 'open' AND c.kind = 'auto' AND c.parcel_id > 0 ORDER BY c.id LIMIT 200");
        foreach ($q->fetchAll() as $c) {
            $pp = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $pp->execute([(int)$c['parcel_id']]); $p = $pp->fetch(); if (!$p) continue;
            if (in_array(wo_class($p, $nowTs), ['delivered', 'cancelled'], true)) { wo_case_close($pdo, (int)$c['id'], 0, 'Dispatch (automatic)', 'The parcel now shows ' . strtolower(wo_class_label(wo_class($p, $nowTs))) . '.'); $res['closed']++; }
        }
    } catch (Throwable $e) { error_log('wa_ord_auto_scan: ' . $e->getMessage()); $res['error'] = $e->getMessage(); }
    return $res;
}
function wo_case_close(PDO $pdo, int $id, int $emp, string $who, string $note): bool
{
    $q = $pdo->prepare("SELECT * FROM wa_ord_cases WHERE id = ?"); $q->execute([$id]); $c = $q->fetch(); if (!$c || $c['status'] !== 'open') return false;
    $pdo->prepare("UPDATE wa_ord_cases SET status = 'done', done_at = ?, done_by = ?, done_note = ?, updated_at = ? WHERE id = ?")->execute([wo_dt(wo_now()), $emp, mb_substr($note, 0, 300), wo_dt(wo_now()), $id]);
    wo_case_note($pdo, $id, $emp, $who, 'Done' . ($note !== '' ? ': ' . $note : ''), 'event');
    $c['status'] = 'done'; wo_close_alert($pdo, $c); return true;
}

// ------------------------------------------------------------------------------------------------------------------ the bot: order questions
/** Is this customer message a question about an order? Keywords in English and Hinglish. */
function wa_ord_intent(string $text): bool
{
    $t = mb_strtolower(trim($text)); if ($t === '' || mb_strlen($t) > 400) return false;
    $t = preg_replace('/[^\p{L}\p{N}#\s\?]+/u', ' ', $t) ?? $t;
    $status = '(status|track|tracking|awb|kahan|kaha|kidhar|kab|kabtak|aayega|ayega|aaega|aayegi|milega|pahunch|pahuch|nahi\s+(aaya|aya|mila|pahuncha)|nahin\s+(aaya|mila)|not\s+(received|delivered|arrived|come|here)|reached|late|delay|where|when|delivered|dispatch|dispatched|shipped|bheja|bhej)';
    if (preg_match('/\b(how\s+to|want\s+to|wanna|like\s+to|can\s+i|could\s+i|place|book)\b.*\b(order|buy)\b/u', $t) && !preg_match('/\b(status|track|tracking|awb|where|kahan|kab)\b/u', $t)) return false;
    if (preg_match('/\border\s+(karna|karni|dena|lena)\b|\bnew\s+order\b|\border\s+(kaise)\b/u', $t)) return false;
    if (preg_match('/\b(awb|tracking\s*(id|no|number|link)?|track\s+(my|the|order|parcel|package)|order\s+status|parcel\s+status|delivery\s+status|order\s+track)\b/u', $t)) return true;
    if (preg_match('/\b(kab|kabtak|kab\s+tak)\s+(aayega|ayega|aaega|aayegi|milega|pahunchega|tak)\b/u', $t)) return true;
    if (preg_match('/\b(my|mera|meri|mere|apna)?\s*(order|parcel|package|courier|shipment|delivery)\b.*\b' . $status . '\b/u', $t)) return true;
    if (preg_match('/\b' . $status . '\b.*\b(order|parcel|package|courier|shipment)\b/u', $t)) return true;
    if (preg_match('/\b(is|has|was)\b.*\bdelivered\b|\bdelivered\s*\?/u', $t)) return true;
    return false;
}
/** An order number the customer wrote ("#1042", "order 1042"), or ''. */
function wo_order_in_text(string $text): string
{
    if (preg_match('/#\s*([A-Za-z0-9\-]{3,12})/u', $text, $m)) return strtolower($m[1]);
    if (preg_match('/\border\s*(?:no\.?|number|id)?\s*[:#]?\s*([0-9]{3,10})\b/iu', $text, $m)) return strtolower($m[1]);
    return '';
}
/**
 * The bot's answer to an order question. Returns ['text' => ..., 'handover' => bool] or null when the message is not an order question.
 * PRIVACY: the status is told ONLY for orders whose own phone matches this chat's number (last 10 digits). Orders found by a manual link or under another number are never told:
 * the customer gets "a team member will check" and handover = true (call wa_ord_bot_handover() to hand the chat to a person).
 */
function wa_ord_bot_answer(PDO $pdo, $phone, string $text): ?array
{
    if (!wa_ord_intent($text)) return null;
    $key = wo_key($phone); $none = ['text' => 'Thank you for asking. A team member will check your order and reply here shortly.', 'handover' => true];
    try {
        if ($key === '') return $none;
        $all = wa_ord_for($pdo, $key); $mine = array_values(array_filter($all['orders'], function ($o) { return $o['match'] === 'phone'; }));
        if (!$mine) return $none;
        $want = wo_order_in_text($text);
        if ($want !== '') { $pick = array_values(array_filter($mine, function ($o) use ($want) { return wo_norm_order((string)$o['order_name']) === $want || preg_replace('/\D+/', '', (string)$o['order_name']) === $want; })); if (!$pick) return $none; $mine = $pick; }
        $active = array_values(array_filter($mine, function ($o) { return !in_array($o['status'], ['delivered', 'cancelled'], true); }));
        $use = $active ?: array_slice($mine, 0, 1); $use = array_slice($use, 0, 3);
        $lines = []; $hand = false;
        foreach ($use as $o) {
            $n = (string)$o['order_name']; $co = trim((string)$o['courier']); $awb = trim((string)$o['tracking_no']);
            switch ($o['status']) {
                case 'ofd': $l = 'Your order ' . $n . ' is out for delivery' . ($co !== '' ? ' with ' . $co : '') . '.'; break;
                case 'shipped': $l = 'Your order ' . $n . ' is on the way' . ($co !== '' ? ' with ' . $co : '') . '.' . ($o['etd'] ? ' The courier\'s expected date is ' . date('j M', strtotime((string)$o['etd'])) . '.' : ' The courier has not given a delivery date yet.'); break;
                case 'packed': $l = 'Your order ' . $n . ' is packed and waiting for the courier to collect it.'; break;
                case 'placed': case 'fulfilled': $l = 'Your order ' . $n . ' has been received and is being prepared. It has not been handed to the courier yet.'; break;
                case 'delivered': $l = 'Your order ' . $n . ' shows as delivered' . ($o['delivered_at'] ? ' on ' . date('j M', strtotime((string)$o['delivered_at'])) : '') . '.'; break;
                case 'cancelled': $l = 'Your order ' . $n . ' is cancelled.'; break;
                case 'delayed': $l = 'Your order ' . $n . ' is taking longer than planned' . ($co !== '' ? ' with ' . $co : '') . '. A team member will follow it up and reply here.'; $hand = true; break;
                case 'failed': $l = 'The courier could not deliver your order ' . $n . ' on the last try. A team member will contact you to fix it.'; $hand = true; break;
                case 'rto': $l = 'Your order ' . $n . ' is being returned to us by the courier. A team member will contact you shortly.'; $hand = true; break;
                default: $l = 'Your order ' . $n . ' needs a closer look. A team member will check it and reply here.'; $hand = true;
            }
            if ($awb !== '' && in_array($o['status'], ['shipped', 'ofd'], true)) $l .= ' Tracking number: ' . $awb . '.';
            $lines[] = $l;
        }
        return ['text' => implode("\n", $lines), 'handover' => $hand];
    } catch (Throwable $e) { return $none; }
}
/** Hands the chat to a person with the existing HOT helper (wa_hand.php). Returns true when the chat was newly flagged. */
function wa_ord_bot_handover(PDO $pdo, $phone, string $why = 'order question — a person must check'): bool
{
    $key = wo_key($phone); if ($key === '' || !function_exists('wa_hot_flag') || !function_exists('wa_contact')) return false;
    try { $c = wa_contact($pdo, $key); return $c ? wa_hot_flag($pdo, $c, $why) : false; } catch (Throwable $e) { return false; }
}

// ------------------------------------------------------------------------------------------------------------------ Store info
function wo_days(): array { return ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday']; }
function wo_store_blank(): array
{
    $h = []; foreach (wo_days() as $k => $n) $h[$k] = ['open' => '', 'close' => '', 'closed' => 0];
    return ['name' => '', 'address' => '', 'map_link' => '', 'phone' => '', 'hours' => $h, 'holiday_note' => '', 'delivery_surat' => '', 'delivery_gujarat' => '', 'delivery_india' => '', 'delivery_use_shiprocket' => 0, 'pickup_info' => ''];
}
function wo_hhmm($s): string { $s = trim((string)$s); if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $s, $m)) return ''; return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]); }
/** A clean copy of what was typed in the form. */
function wo_store_clean(array $in): array
{
    $b = wo_store_blank(); $t = function ($k, $n) use ($in) { return mb_substr(trim((string)($in[$k] ?? '')), 0, $n); };
    $o = $b; $o['name'] = $t('name', 120); $o['address'] = $t('address', 400); $o['map_link'] = $t('map_link', 400); $o['phone'] = $t('phone', 40); $o['holiday_note'] = $t('holiday_note', 300);
    $o['delivery_surat'] = $t('delivery_surat', 120); $o['delivery_gujarat'] = $t('delivery_gujarat', 120); $o['delivery_india'] = $t('delivery_india', 120); $o['delivery_use_shiprocket'] = !empty($in['delivery_use_shiprocket']) ? 1 : 0; $o['pickup_info'] = $t('pickup_info', 400);
    if ($o['map_link'] !== '' && !preg_match('#^https?://#i', $o['map_link'])) $o['map_link'] = '';
    foreach (wo_days() as $k => $n) { $h = is_array($in['hours'][$k] ?? null) ? $in['hours'][$k] : []; $o['hours'][$k] = ['open' => wo_hhmm($h['open'] ?? ''), 'close' => wo_hhmm($h['close'] ?? ''), 'closed' => !empty($h['closed']) ? 1 : 0]; }
    return $o;
}
/** What the owner filled in (every field present; empty text when not filled). */
function wa_store_info(PDO $pdo): array
{
    wo_tables($pdo); $b = wo_store_blank(); $q = $pdo->query("SELECT v, updated_at FROM wa_store_info WHERE k = 'info'"); $r = $q->fetch(); $d = $r ? json_decode((string)$r['v'], true) : null;
    $o = is_array($d) ? wo_store_clean($d) : $b; $o['updated_at'] = $r ? (string)$r['updated_at'] : '';
    $o['filled'] = (trim($o['name'] . $o['address'] . $o['map_link'] . $o['phone'] . $o['delivery_surat'] . $o['delivery_gujarat'] . $o['delivery_india'] . $o['pickup_info']) !== '') || wo_hours_set($o);
    return $o;
}
function wo_hours_set(array $o): bool { foreach ($o['hours'] as $h) if ($h['closed'] || ($h['open'] !== '' && $h['close'] !== '')) return true; return false; }
function wo_h12(string $hhmm): string { $t = strtotime('2000-01-01 ' . $hhmm); return $t ? ltrim(date('g:i A', $t), '0') : $hhmm; }
function wo_hours_text(array $o): string
{
    if (!wo_hours_set($o)) return ''; $groups = []; $names = wo_days();   // runs of days with the same hours: "Mon-Sat 10:00 AM - 9:00 PM"
    $label = function ($h) { return $h['closed'] ? 'closed' : ($h['open'] !== '' && $h['close'] !== '' ? wo_h12($h['open']) . ' - ' . wo_h12($h['close']) : ''); };
    $prev = null; foreach ($o['hours'] as $k => $h) { $l = $label($h); if ($l === '') { $prev = null; continue; } if ($prev !== null && $groups[$prev]['l'] === $l) $groups[$prev]['to'] = $k; else { $groups[] = ['from' => $k, 'to' => $k, 'l' => $l]; $prev = count($groups) - 1; } }
    $parts = []; foreach ($groups as $g) { $a = substr($names[$g['from']], 0, 3); $b = substr($names[$g['to']], 0, 3); $parts[] = ($g['from'] === $g['to'] ? $a : $a . '-' . $b) . ' ' . $g['l']; }
    $txt = implode(', ', $parts); if ($o['holiday_note'] !== '') $txt .= '. ' . rtrim($o['holiday_note'], '. ') . '.';
    return $txt;
}
function wo_delivery_text(array $o): string
{
    $p = []; if ($o['delivery_surat'] !== '') $p[] = 'Surat: ' . $o['delivery_surat']; if ($o['delivery_gujarat'] !== '') $p[] = 'Gujarat: ' . $o['delivery_gujarat']; if ($o['delivery_india'] !== '') $p[] = 'Rest of India: ' . $o['delivery_india'];
    $t = implode('. ', $p); if ($o['delivery_use_shiprocket']) $t .= ($t !== '' ? '. ' : '') . 'The exact date comes from the courier once your parcel ships.';
    return $t;
}
/** Plain text from the Store info for the bot / pre-made messages. kind: hours | address | map | delivery | phone | pickup | all. '' when the owner has not filled it in: then say "a person will confirm". */
function wa_store_text(PDO $pdo, string $kind): string
{
    $o = wa_store_info($pdo);
    switch ($kind) {
        case 'hours': return wo_hours_text($o);
        case 'address': return trim($o['address']);
        case 'map': return trim($o['map_link']);
        case 'phone': return trim($o['phone']);
        case 'pickup': return trim($o['pickup_info']);
        case 'delivery': return wo_delivery_text($o);
        case 'all':
            $l = []; if ($o['name'] !== '') $l[] = $o['name']; if ($o['address'] !== '') $l[] = 'Address: ' . $o['address']; if ($o['map_link'] !== '') $l[] = 'Map: ' . $o['map_link']; if ($o['phone'] !== '') $l[] = 'Phone: ' . $o['phone'];
            $h = wo_hours_text($o); if ($h !== '') $l[] = 'Timings: ' . $h; $d = wo_delivery_text($o); if ($d !== '') $l[] = 'Delivery: ' . $d; if ($o['pickup_info'] !== '') $l[] = 'Pick-up: ' . $o['pickup_info'];
            return implode("\n", $l);
    }
    return '';
}
/** Is the shop open at this moment (India time)? ['open' => bool, 'text' => ...] or null when the timings are not filled in (do not guess). */
function wa_store_open_now(PDO $pdo, ?int $ts = null): ?array
{
    $o = wa_store_info($pdo); if (!wo_hours_set($o)) return null; $ts = $ts ?? wo_now(); $d = wo_ist($ts); $k = strtolower(substr($d->format('D'), 0, 3)); $h = $o['hours'][$k] ?? null;
    if (!$h || (!$h['closed'] && ($h['open'] === '' || $h['close'] === ''))) return null;   // that day is not filled in: do not guess
    $nowM = (int)$d->format('G') * 60 + (int)$d->format('i'); $mins = function ($x) { [$a, $b] = array_map('intval', explode(':', $x)); return $a * 60 + $b; };
    // a closing time after midnight (open 18:00 - 02:00) belongs to the day it started: yesterday's late hours are still open this morning
    $keys = array_keys(wo_days()); $yk = $keys[(array_search($k, $keys, true) + 6) % 7]; $yh = $o['hours'][$yk];
    if (!$yh['closed'] && $yh['open'] !== '' && $yh['close'] !== '' && $mins($yh['close']) <= $mins($yh['open']) && $nowM < $mins($yh['close'])) return ['open' => true, 'text' => 'Open now, till ' . wo_h12($yh['close']) . '.'];
    if ($h['closed']) return ['open' => false, 'text' => 'Closed today (' . wo_days()[$k] . ').'];
    $a = $mins($h['open']); $b = $mins($h['close']); $over = $b <= $a; $open = $over ? $nowM >= $a : ($nowM >= $a && $nowM < $b);
    return ['open' => $open, 'text' => $open ? 'Open now, till ' . wo_h12($h['close']) . '.' : ($nowM < $a ? 'Closed now, opens at ' . wo_h12($h['open']) . '.' : 'Closed now.')];
}

// ------------------------------------------------------------------------------------------------------------------ the five order-update templates
function wo_tpl_defs(): array
{
    $qr = [['type' => 'QUICK_REPLY', 'text' => 'Call me'], ['type' => 'QUICK_REPLY', 'text' => 'Change address']];
    $ex = ['Rahul', '1042', 'Delhivery', '30012345678']; $lab = ['Name', 'Order', 'Courier', 'Tracking'];
    return [
        'tmph_order_received' => ['body' => "Hello {{1}}, thank you! We have received your order {{2}} at TM Perfume House. We will pack it with care and send you the tracking details as soon as it is shipped.", 'ex' => array_slice($ex, 0, 2), 'lab' => array_slice($lab, 0, 2), 'buttons' => []],
        'tmph_order_shipped' => ['body' => "Hello {{1}}, your order {{2}} from TM Perfume House has been shipped with {{3}}. Your tracking number is {{4}}. You can use it to follow your parcel on the way.", 'ex' => $ex, 'lab' => $lab, 'buttons' => []],
        'tmph_order_out_for_delivery' => ['body' => "Hello {{1}}, your order {{2}} is out for delivery today with {{3}}. Please keep your phone nearby so the delivery person can reach you.", 'ex' => array_slice($ex, 0, 3), 'lab' => array_slice($lab, 0, 3), 'buttons' => []],
        'tmph_order_delivered' => ['body' => "Hello {{1}}, your order {{2}} has been delivered. We hope it reached you in good condition. If anything is wrong with the parcel, just reply to this message.", 'ex' => array_slice($ex, 0, 2), 'lab' => array_slice($lab, 0, 2), 'buttons' => []],
        'tmph_delivery_attempt_failed' => ['body' => "Hello {{1}}, the delivery of your order {{2}} by {{3}} could not be completed today. The courier will try again. If you want to change anything, please use the buttons below.", 'ex' => array_slice($ex, 0, 3), 'lab' => array_slice($lab, 0, 3), 'buttons' => $qr],
        'tmph_review_request' => ['body' => "Hello {{1}}, we hope you are enjoying your order {{2}} from TM Perfume House. How would you rate it? Just tap a number below: 5 means you loved it, 1 means it was not good.", 'ex' => array_slice($ex, 0, 2), 'lab' => array_slice($lab, 0, 2), 'category' => 'MARKETING',
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => '5 ⭐ Loved it'], ['type' => 'QUICK_REPLY', 'text' => '4 ⭐ Good'], ['type' => 'QUICK_REPLY', 'text' => '3 ⭐ Okay'], ['type' => 'QUICK_REPLY', 'text' => '2 ⭐ Not good'], ['type' => 'QUICK_REPLY', 'text' => '1 ⭐ Poor']]],
        'tmph_review_nudge' => ['body' => "Hello {{1}}, thank you for rating your order {{2}} 5 stars! 💖 Please share your review here: {{3}} Then send us a screenshot in this chat and we will add 20 ml FREE perfume of your choice to your next order above ₹1,500 (valid 15 days).", 'ex' => ['Rahul', '1042', 'https://judge.me/reviews/stores/tmperfumehouse'], 'lab' => ['Name', 'Order', 'Review link'], 'category' => 'MARKETING', 'buttons' => []],
        'tmph_birthday_ask' => ['body' => "Hello {{1}}, thank you for your order {{2}} from TM Perfume House! 🎂 Reply with your birthday (day and month, like 14/12) and we will send you a FREE 50 ml perfume gift on your birthday.", 'ex' => ['Rahul', '1042'], 'lab' => ['Name', 'Order'], 'category' => 'MARKETING', 'buttons' => []],
        'tmph_birthday_gift' => ['body' => "Hello {{1}}! 🎂 Your birthday is coming up on {{2}}, and TM Perfume House has a FREE 50 ml perfume gift for you. Your gift code is {{3}}. Use it online on any order above ₹1,500, or collect it from our store. It is valid until {{4}}. Just reply here and tell us which perfume you would like 💖", 'ex' => ['Rahul', '14 Dec', 'BD-7K3M9P', '29 Dec'], 'lab' => ['Name', 'Birthday', 'Gift code', 'Valid till'], 'category' => 'MARKETING', 'buttons' => []],
        'tmph_care_d2' => ['body' => "Hello {{1}}, it has been 2 days since your order {{2}} from TM Perfume House reached you. We hope you are enjoying your perfume! 💖 If you do not like it, or something is not right, just reply here and tell us. We will take care of it.", 'ex' => ['Rahul', '1042'], 'lab' => ['Name', 'Order'], 'category' => 'MARKETING', 'buttons' => []],
        'tmph_return_d15' => ['body' => "Hello {{1}}, it has been 15 days with your order {{2}} from TM Perfume House. If you still do not like the perfume, just reply here and we will take it back. 🙏", 'ex' => ['Rahul', '1042'], 'lab' => ['Name', 'Order'], 'category' => 'MARKETING', 'buttons' => []],
        'tmph_return_d30' => ['body' => "Hello {{1}}, it has been 30 days with your order {{2}} from TM Perfume House. If you are still not happy with it, you can send it back to us, no questions asked. Just reply here and we will arrange it. 💖", 'ex' => ['Rahul', '1042'], 'lab' => ['Name', 'Order'], 'category' => 'MARKETING', 'buttons' => []],
        'tmph_order_dispatch_late' => ['body' => "Hello {{1}}, we are sorry! Your order {{2}} from TM Perfume House has not left our warehouse yet and it is taking longer than we promised. We are packing it as fast as we can and will send your tracking details the moment it ships. Thank you for your patience. Please use the buttons below if you need help.", 'ex' => array_slice($ex, 0, 2), 'lab' => array_slice($lab, 0, 2), 'buttons' => $qr],
        'tmph_delivery_delayed' => ['body' => "Hello {{1}}, your order {{2}} with {{3}} is taking longer than planned. We are following it up with the courier and are sorry for the wait. Please use the buttons below if you need help.", 'ex' => array_slice($ex, 0, 3), 'lab' => array_slice($lab, 0, 3), 'buttons' => $qr],
    ];
}
function wo_tpl_state(PDO $pdo): array
{
    $out = []; if (!function_exists('wa_tpl_tables') || !wa_tpl_tables($pdo)) return $out; $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
    foreach (wo_tpl_defs() as $name => $d) {
        $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); $id = (int)$q->fetchColumn();
        $st = $id ? wa_tpl_status($pdo, $id, $wabas) : ['status' => 'missing', 'reason' => '']; $out[$name] = ['id' => $id, 'status' => (string)$st['status'], 'reason' => (string)($st['reason'] ?? '')];
    }
    return $out;
}
/** Makes the five order-update templates (UTILITY, English) and submits them to Meta through the template module. Safe to run again: a name that exists is skipped; one that never reached Meta is submitted now. */
function wa_ord_seed_templates(PDO $pdo, array $opt = []): array
{
    $res = ['created' => [], 'submitted' => [], 'skipped' => [], 'failed' => []];
    try {
        if (!function_exists('wa_tpl_tables') || !function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at') || !wa_tpl_tables($pdo)) { $res['error'] = 'The template module is not available.'; return $res; }
        $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $retry = !empty($opt['retry']);
        foreach (wo_tpl_defs() as $name => $d) {
            $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); $id = (int)$q->fetchColumn();
            if (!$id) {
                [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => (string)($d['category'] ?? 'UTILITY'), 'body' => $d['body'], 'footer' => 'TM Perfume House', 'buttons' => $d['buttons'], 'examples' => ['body' => $d['ex'], 'labels' => $d['lab']], 'folder' => 'Order updates']);
                if ($err === '') $err = wa_tpl_media_check($pdo, $t);
                if ($err !== '') { $res['failed'][$name] = $err; continue; }
                $id = wa_tpl_insert($pdo, $t, 0, 'Order update message for the customer\'s own order (shipped / out for delivery / delivered / failed / delayed).', 'Utility message about one order.'); $res['created'][] = $name;
            }
            $r = wa_tpl_row($pdo, $id); if (!$r) { $res['failed'][$name] = 'Could not read the saved template.'; continue; }
            if (!$wabas) { $res['skipped'][$name] = 'No WhatsApp Business Account ID yet - saved, will be sent to Meta when one is added.'; continue; }
            $have = []; $hq = $pdo->prepare("SELECT waba_id, meta_id, status FROM wa_tpl_waba WHERE tpl_id = ?"); $hq->execute([$id]); foreach ($hq->fetchAll() as $h) $have[(string)$h['waba_id']] = $h;
            $sent = false; $errs = [];
            foreach ($wabas as $w) {
                $h = $have[$w] ?? null;
                if ($h && !($retry && (string)$h['meta_id'] === '' && strtoupper((string)$h['status']) === 'REJECTED')) continue;   // already at Meta (or Meta said no): never submitted twice
                [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); if ($ok) $sent = true; else $errs[] = $why;
            }
            if ($errs) $res['failed'][$name] = $errs[0]; elseif ($sent) $res['submitted'][] = $name; else $res['skipped'][$name] = 'Already at Meta.';
        }
        foreach (wo_photo_cfg($pdo) as $st => $lib) {   // a photo is chosen for this message: make its photo version too (once) and send it to Meta
            $pn = wo_photo_name($st); if ($pn === '') continue;
            $pq = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $pq->execute([$pn]); if ((int)$pq->fetchColumn()) continue;
            [$pok, $pmsg] = wo_photo_make($pdo, $st, $lib); if ($pok) $res['submitted'][] = $pn; else $res['failed'][$pn] = $pmsg;
        }
        if (function_exists('wa_log')) wa_log($pdo, 'orders', !$res['failed'], 'Order-update templates: ' . count($res['created']) . ' made, ' . count($res['submitted']) . ' sent to Meta, ' . count($res['failed']) . ' failed' . ($res['failed'] ? ' — ' . mb_substr((string)reset($res['failed']), 0, 120) : ''));
    } catch (Throwable $e) { $res['error'] = $e->getMessage(); if (function_exists('wa_log')) wa_log($pdo, 'orders', false, 'Order-update templates: ' . $e->getMessage()); }
    return $res;
}

// ------------------------------------------------------------------------------------------------------------------ photo versions of the order-update messages
// "Order received" stays text-only. For the other five, the admin can pick a photo from the media library: a second template (same words, photo on top,
// name + "_photo") is made and sent to Meta. Until Meta approves it the plain text version keeps going out, so nothing stops.
function wo_photo_cfg(PDO $pdo): array
{
    $c = json_decode(wo_get($pdo, 'wa_ord_photo', ''), true); $o = []; if (!is_array($c)) return $o;
    foreach (wo_send_defs() as $k => $d) if ($k !== 'received' && (int)($c[$k] ?? 0) > 0) $o[$k] = (int)$c[$k];
    // the same message can also have its photo set in the chat-messages screen (wch_msg): one connected system, so that photo counts here too
    if (function_exists('wch_msg_row')) foreach (['shipped' => 'order_shipped', 'out_for_delivery' => 'order_ofd', 'delivered' => 'order_delivered', 'delayed' => 'order_delayed', 'failed' => 'delivery_failed'] as $st => $mk) {
        if (isset($o[$st])) continue; try { $r = wch_msg_row($pdo, $mk); if ((int)($r['photo_lib_id'] ?? 0) > 0) $o[$st] = (int)$r['photo_lib_id']; } catch (Throwable $e) { }
    }
    return $o;
}
function wo_photo_name(string $status): string { $d = wo_send_defs(); return isset($d[$status]) && $status !== 'received' ? $d[$status][0] . '_photo' : ''; }
/** Makes the photo version of one message (if missing) and sends it to Meta. Returns [ok, text]. */
function wo_photo_make(PDO $pdo, string $status, int $libId): array
{
    $name = wo_photo_name($status); $defs = wo_tpl_defs(); $base = wo_send_defs()[$status][0] ?? '';
    if ($name === '' || !isset($defs[$base])) return [false, 'This message cannot have a photo.'];
    if (!function_exists('wa_tpl_tables') || !function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at') || !wa_tpl_tables($pdo)) return [false, 'The template module is not available.'];
    $d = $defs[$base]; $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); $id = (int)$q->fetchColumn();
    if (!$id) {
        [$t, $err] = wa_tpl_clean(['name' => $name, 'language' => 'en', 'category' => 'UTILITY', 'header_format' => 'IMAGE', 'header_lib_id' => $libId, 'body' => $d['body'], 'footer' => 'TM Perfume House', 'buttons' => $d['buttons'], 'examples' => ['body' => $d['ex'], 'labels' => $d['lab']], 'folder' => 'Order updates']);
        if ($err === '') $err = wa_tpl_media_check($pdo, $t);
        if ($err !== '') return [false, $err];
        $id = wa_tpl_insert($pdo, $t, 0, 'Order update message with a photo on top (' . $status . ').', 'Utility message about one order, with a photo.');
    }
    $r = wa_tpl_row($pdo, $id); if (!$r) return [false, 'Could not read the saved template.'];
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; if (!$wabas) return [true, 'Saved. It will go to Meta when a WhatsApp Business Account ID is added.'];
    $have = []; $hq = $pdo->prepare("SELECT waba_id FROM wa_tpl_waba WHERE tpl_id = ?"); $hq->execute([$id]); foreach ($hq->fetchAll() as $h) $have[(string)$h['waba_id']] = 1;
    $sent = 0; $errs = [];
    foreach ($wabas as $w) { if (isset($have[$w])) continue; [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); if ($ok) $sent++; else $errs[] = $why; }
    if ($errs) return [false, (string)$errs[0]];
    return [true, $sent ? 'Sent to Meta for approval.' : 'Already at Meta.'];
}

// ------------------------------------------------------------------------------------------------------------------ edited versions of the order-update messages
// Edit = a NEW template (new words and / or a photo on top) sent to Meta. The live message keeps going out until Meta approves the new one; then it wins by itself.
// Config "wa_ord_ver": { status: { active: {name, body, lib, since}|null, pending: {name, body, lib, at}|null, history: [ {name, body, lib, since, until} ] } }
function wo_ver_all(PDO $pdo): array { $c = json_decode(wo_get($pdo, 'wa_ord_ver', ''), true); return is_array($c) ? $c : []; }
function wo_ver_put(PDO $pdo, array $all): void { wo_put($pdo, 'wa_ord_ver', json_encode($all, JSON_UNESCAPED_UNICODE)); }
function wo_ver_id(PDO $pdo, string $name): int { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$name]); return (int)$q->fetchColumn(); }
function wo_ver_base_body(string $status): string { $d = wo_send_defs(); $b = wo_tpl_defs(); return isset($d[$status]) ? (string)($b[$d[$status][0]]['body'] ?? '') : ''; }
/** A pending version that Meta has approved becomes the live one (the old live one goes to history). Cheap: nothing happens when nothing is pending. */
function wo_ver_sync(PDO $pdo): array
{
    $all = wo_ver_all($pdo); $ch = false;
    foreach ($all as $st => $v) {
        $pd = is_array($v['pending'] ?? null) ? $v['pending'] : null; if (!$pd || !function_exists('wa_camp_tpl')) continue;
        $id = wo_ver_id($pdo, (string)$pd['name']); if (!$id || !wa_camp_tpl($pdo, $id, true, (int)($pd['lib'] ?? 0))) continue;
        $hist = is_array($v['history'] ?? null) ? $v['history'] : []; $now = wo_dt(wo_now());
        $old = is_array($v['active'] ?? null) ? $v['active'] : ['name' => wo_send_defs()[$st][0] ?? '', 'body' => wo_ver_base_body((string)$st), 'lib' => 0, 'since' => ''];
        $old['until'] = $now; array_unshift($hist, $old);
        $all[$st] = ['active' => ['name' => $pd['name'], 'body' => $pd['body'], 'lib' => (int)($pd['lib'] ?? 0), 'since' => $now], 'pending' => null, 'history' => array_slice($hist, 0, 10)]; $ch = true;
        if (function_exists('wa_log')) wa_log($pdo, 'orders', true, 'Order-update message "' . $st . '": the new version was approved and is now live');
    }
    if ($ch) wo_ver_put($pdo, $all);
    return $all;
}
/** The live message's words (what a customer gets now). */
function wo_live_body(PDO $pdo, string $status): string { $v = wo_ver_all($pdo)[$status]['active'] ?? null; return is_array($v) && trim((string)($v['body'] ?? '')) !== '' ? (string)$v['body'] : wo_ver_base_body($status); }
function wo_live_has_photo(PDO $pdo, string $status): bool { $v = wo_ver_all($pdo)[$status]['active'] ?? null; return is_array($v) && (int)($v['lib'] ?? 0) > 0; }
/** Makes the template for an edited version and sends it to Meta. Returns [ok, text]. */
function wo_ver_make(PDO $pdo, string $status, string $name, string $body, int $lib): array
{
    $defs = wo_tpl_defs(); $base = wo_send_defs()[$status][0] ?? ''; if (!isset($defs[$base])) return [false, 'Unknown message.'];
    if (!function_exists('wa_tpl_tables') || !function_exists('wa_tpl_clean') || !function_exists('wa_tpl_insert') || !function_exists('wa_tpl_create_at') || !wa_tpl_tables($pdo)) return [false, 'The template module is not available.'];
    $d = $defs[$base]; $in = ['name' => $name, 'language' => 'en', 'category' => 'UTILITY', 'body' => $body, 'footer' => 'TM Perfume House', 'buttons' => $d['buttons'], 'examples' => ['body' => $d['ex'], 'labels' => $d['lab']], 'folder' => 'Order updates'];
    if ($lib > 0) { $in['header_format'] = 'IMAGE'; $in['header_lib_id'] = $lib; }
    [$t, $err] = wa_tpl_clean($in); if ($err === '') $err = wa_tpl_media_check($pdo, $t); if ($err !== '') return [false, $err];
    $id = wa_tpl_insert($pdo, $t, 0, 'Edited order update message (' . $status . ').', 'Utility message about one order.');
    $r = wa_tpl_row($pdo, $id); if (!$r) return [false, 'Could not read the saved template.'];
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; if (!$wabas) return [true, 'Saved. It will go to Meta when a WhatsApp Business Account ID is added.'];
    $errs = []; foreach ($wabas as $w) { [$ok, $why] = wa_tpl_create_at($pdo, $r, $w); if (!$ok) $errs[] = $why; }
    return $errs ? [false, (string)$errs[0]] : [true, 'Sent to Meta for approval. The current message keeps going out until Meta approves.'];
}
/** Saves an edit: new words and / or a photo. Returns [ok, text]. */
function wo_ver_save(PDO $pdo, string $status, string $body, int $lib): array
{
    $defs = wo_send_defs(); if (!isset($defs[$status])) return [false, 'Unknown message.'];
    $body = trim(str_replace("\r", '', $body)); if ($body === '') return [false, 'The message text is empty.'];
    if ($lib > 0) { $row = function_exists('wa_tpl_lib_row') ? wa_tpl_lib_row($pdo, $lib) : null; if (!$row || (string)$row['kind'] !== 'image') return [false, 'Pick a photo (JPG or PNG) from the media library.']; }
    $baseBody = wo_ver_base_body($status); $nb = count(wa_tpl_vars($baseBody)); $nn = count(wa_tpl_vars($body));
    if ($nn !== $nb) return [false, 'Keep exactly ' . $nb . ' variable' . ($nb === 1 ? '' : 's') . ' ({{1}}' . ($nb > 1 ? '…{{' . $nb . '}}' : '') . ') in this message. They are filled in with the customer\'s name, order number, courier and tracking.'];
    $all = wo_ver_all($pdo); $v = $all[$status] ?? ['active' => null, 'pending' => null, 'history' => []]; $act = is_array($v['active'] ?? null) ? $v['active'] : null;
    $liveBody = $act ? (string)$act['body'] : $baseBody; $liveLib = $act ? (int)($act['lib'] ?? 0) : 0;
    if ($body === $liveBody && $lib === $liveLib) return [false, 'Nothing was changed.'];
    if ($act && $body === $liveBody && $liveLib > 0 && $lib > 0) {   // only a different photo: Meta needs no new approval
        $act['lib'] = $lib; $v['active'] = $act; $v['pending'] = null; $all[$status] = $v; wo_ver_put($pdo, $all); return [true, 'The photo was changed. No new approval is needed.'];
    }
    $name = $defs[$status][0] . '_e' . date('ymdHis');
    [$ok, $msg] = wo_ver_make($pdo, $status, $name, $body, $lib); if (!$ok) return [false, $msg];
    $v['pending'] = ['name' => $name, 'body' => $body, 'lib' => $lib, 'at' => wo_dt(wo_now())]; $v += ['active' => $act, 'history' => []]; $all[$status] = $v; wo_ver_put($pdo, $all);
    return [true, $msg];
}
/** What the screen shows for one message: live version, pending version with Meta's answer, history. */
function wo_ver_info(PDO $pdo, string $status, array $all): array
{
    $v = $all[$status] ?? []; $lt = function (int $lib) use ($pdo): string { $r = $lib > 0 && function_exists('wa_tpl_lib_row') ? wa_tpl_lib_row($pdo, $lib) : null; return $r ? (string)$r['title'] : ''; };
    $act = is_array($v['active'] ?? null) ? $v['active'] : null; $pd = is_array($v['pending'] ?? null) ? $v['pending'] : null;
    $o = ['live' => ['body' => $act ? (string)$act['body'] : wo_ver_base_body($status), 'lib' => $act ? (int)($act['lib'] ?? 0) : 0, 'since' => $act ? (string)($act['since'] ?? '') : '', 'edited' => (bool)$act], 'pending' => null, 'history' => []];
    $o['live']['photo'] = $lt($o['live']['lib']);
    if ($pd) {
        $id = wo_ver_id($pdo, (string)$pd['name']); $st = ['status' => 'missing', 'reason' => '']; try { if ($id) $st = wa_tpl_status($pdo, $id, function_exists('wa_wabas') ? wa_wabas($pdo) : []); } catch (Throwable $e) { }
        $o['pending'] = ['body' => (string)$pd['body'], 'lib' => (int)($pd['lib'] ?? 0), 'photo' => $lt((int)($pd['lib'] ?? 0)), 'at' => (string)($pd['at'] ?? ''), 'status' => (string)$st['status'], 'reason' => (string)($st['reason'] ?? '')];
    }
    foreach (array_slice(is_array($v['history'] ?? null) ? $v['history'] : [], 0, 5) as $h) $o['history'][] = ['body' => (string)($h['body'] ?? ''), 'photo' => $lt((int)($h['lib'] ?? 0)), 'until' => (string)($h['until'] ?? '')];
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------ automatic order-update messages
function wo_cfg(PDO $pdo): array
{
    $st = json_decode(wo_get($pdo, 'wa_ord_status_on', ''), true); $st = is_array($st) ? $st : []; $o = [];
    foreach (wo_send_defs() as $k => $d) $o[$k] = in_array($k, ['review', 'review_nudge', 'bday_ask', 'birthday', 'dispatch_late', 'care_d2', 'return_d15', 'return_d30'], true) ? !empty($st[$k]) : (!isset($st[$k]) || !empty($st[$k]));   // the rating request stays OFF until Admin switches it on
    return ['on' => wo_get($pdo, 'wa_ord_auto_on', '1') !== '0', 'statuses' => $o];
}
/** The messages whose timing the owner can change in Automation (the others go when the courier's status changes). */
function wo_timing_defs(): array
{
    return ['care_d2' => ['unit' => 'days after the parcel is delivered', 'min' => 1, 'max' => 10, 'def' => 2, 'label' => 'Send the check-in'],
            'return_d15' => ['unit' => 'days after the parcel is delivered', 'min' => 7, 'max' => 28, 'def' => 15, 'label' => 'Send the take-back offer'],
            'return_d30' => ['unit' => 'days after the parcel is delivered', 'min' => 20, 'max' => 60, 'def' => 30, 'label' => 'Send the no-questions return offer'],
            'dispatch_late' => ['unit' => 'hours after the order, if it has not been shipped', 'min' => 24, 'max' => 240, 'def' => 72, 'label' => 'Say sorry for the delay'],
            'review' => ['unit' => 'days after the parcel is delivered', 'min' => 1, 'max' => 30, 'def' => 5, 'label' => 'Send the rating request'],
            'review_nudge' => ['unit' => 'days after the 5-star rating', 'min' => 1, 'max' => 9, 'def' => 2, 'label' => 'Send the reminder'],
            'bday_ask' => ['unit' => 'minutes after the order', 'min' => 5, 'max' => 1440, 'def' => 60, 'label' => 'Ask for the birthday'],
            'birthday' => ['unit' => 'days before the birthday (0 = on the day)', 'min' => 0, 'max' => 7, 'def' => 1, 'label' => 'Send the gift message']];
}
function wo_timing(PDO $pdo, string $k): int
{
    $d = wo_timing_defs(); if (!isset($d[$k])) return 0;
    if ($k === 'birthday' && function_exists('st_bday_cfg')) { try { return (int)st_bday_cfg($pdo)['send_before']; } catch (Throwable $e) { } }
    $j = json_decode(wo_get($pdo, 'wa_ord_timing', ''), true); $v = is_array($j) && isset($j[$k]) ? (int)$j[$k] : $d[$k]['def'];
    return max($d[$k]['min'], min($d[$k]['max'], $v));
}
function wo_titles(PDO $pdo): array { $j = json_decode(wo_get($pdo, 'wa_ord_titles', ''), true); return is_array($j) ? $j : []; }
function wo_first_name(string $name): string
{
    if (function_exists('wa_first_name')) return wa_first_name($name);
    $w = preg_split('/\s+/u', trim($name))[0] ?? ''; $w = trim(preg_replace('/[^\p{L}\p{N}\'.\-]/u', '', $w) ?? ''); return mb_strlen($w) < 2 ? 'ji' : mb_convert_case(mb_substr($w, 0, 30), MB_CASE_TITLE, 'UTF-8');
}
function wo_tpl_for(PDO $pdo, string $status): ?array
{
    $defs = wo_send_defs(); if (!isset($defs[$status]) || !function_exists('wa_camp_tpl') || !function_exists('wa_tpl_tables') || !wa_tpl_tables($pdo)) return null;
    $act = wo_ver_sync($pdo)[$status]['active'] ?? null;   // an edited version that Meta approved wins over everything else
    $GLOBALS['wo_photo_why'] = '';
    if (is_array($act) && trim((string)($act['name'] ?? '')) !== '') { $aid = wo_ver_id($pdo, (string)$act['name']); $at = $aid ? wa_camp_tpl($pdo, $aid, true, (int)($act['lib'] ?? 0)) : null; if ($at) return $at;
        // the edited version (new words / photo) cannot be used → the plain text message goes out instead; say WHY, so it is not a silent fallback
        if ((int)($act['lib'] ?? 0) > 0) { $why = !$aid ? 'the photo version "' . $act['name'] . '" is not in the template list' : 'the photo version is not approved on this WhatsApp account yet, or its photo cannot be read from the media library'; $GLOBALS['wo_photo_why'] = $why; try { wa_log($pdo, 'orders', false, 'Order-update "' . $status . '" went WITHOUT its photo: ' . $why); } catch (Throwable $e) { } } }
    $lib = wo_photo_cfg($pdo)[$status] ?? 0;
    if ($lib > 0) {   // a photo is chosen: use the photo version once Meta approved it; until then the plain text version keeps going out
        $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([wo_photo_name($status)]); $pid = (int)$q->fetchColumn();
        $pt = $pid ? wa_camp_tpl($pdo, $pid, true, $lib) : null; if ($pt) return $pt;
    }
    $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$defs[$status][0]]); $id = (int)$q->fetchColumn(); return $id ? wa_camp_tpl($pdo, $id) : null;   // null = not approved (anywhere)
}
function wo_vars(array $p, string $status): array
{
    $co = trim((string)$p['courier']); $co = $co !== '' ? $co : 'the courier'; $vars = [wo_first_name((string)$p['customer']), (string)$p['order_name'], $co, (string)$p['tracking_no']];
    return array_slice($vars, 0, $status === 'shipped' ? 4 : (in_array($status, ['delivered', 'review', 'review_nudge', 'bday_ask', 'dispatch_late', 'care_d2', 'return_d15', 'return_d30'], true) ? 2 : 3));
}
/** Looks at parcels whose courier word changed recently, queues one message per order + status (never twice), then sends what is due. Called by wa_tick every minute. */
function wa_ord_autosend(PDO $pdo, ?int $nowTs = null, string $onlyStore = ''): array
{
    $res = ['queued' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0]; $nowTs = $nowTs ?? wo_now();
    try {
        if (!wo_tables($pdo)) return $res; $cfg = wo_cfg($pdo); if (!$cfg['on']) return $res;
        $go = (int)wo_get($pdo, 'wa_ord_send_since', '0'); if ($go <= 0) { $go = $nowTs; wo_put($pdo, 'wa_ord_send_since', (string)$go); }
        $since = wo_dt(max($go, $nowTs - 6 * 3600)); $sk = $onlyStore !== '' ? ' AND store_key = ' . $pdo->quote($onlyStore) : '';
        // 1. what changed lately: queue ONE message per order + status
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('tracked','transit','delivered') AND phone IS NOT NULL AND phone <> '' AND (sr_at >= ? OR delivered_at >= ? OR transit_at >= ?)$sk ORDER BY id DESC LIMIT 500");
        $q->execute([$since, $since, $since]); $tplOk = [];
        foreach ($q->fetchAll() as $p) {
            $cls = wo_class($p, $nowTs); $st = wo_send_status($cls); if ($st === '' || empty($cfg['statuses'][$st])) continue;
            if ($st === 'shipped' && trim((string)$p['tracking_no']) === '') continue;
            if (wo_is_cx_parcel($pdo, $p)) continue;   // 🎫 a complaint replacement: the ticket's own messages tell the customer
            $key = wo_key($p['phone']); if ($key === '' || (function_exists('wa_valid_phone') && !wa_valid_phone($key))) continue;
            if (!array_key_exists($st, $tplOk)) $tplOk[$st] = wo_tpl_for($pdo, $st) !== null;
            if (!$tplOk[$st]) { $res['waiting']++; continue; }   // the template is not approved yet: nothing is queued, so an old status can never be sent later
            $due = wo_next_open($nowTs);
            $ins = $pdo->prepare("INSERT IGNORE INTO wa_ord_sent (parcel_id, phone, order_name, status, tpl_name, state, created_at, due_at) VALUES (?,?,?,?,?, 'queued', ?, ?)");
            $ins->execute([(int)$p['id'], $key, (string)$p['order_name'], $st, wo_send_defs()[$st][0], wo_dt($nowTs), wo_dt($due)]); if ($ins->rowCount() > 0) $res['queued']++;
        }
        try { $res['after'] = wa_ord_after_queue($pdo, $nowTs, $cfg, $sk, $go); } catch (Throwable $e) { error_log('wa_ord_after_queue: ' . $e->getMessage()); }   // 💬 2 / 15 / 30 days after delivery
        try { $res['late'] = wa_ord_late_queue($pdo, $nowTs, $cfg, $sk, $go); } catch (Throwable $e) { error_log('wa_ord_late_queue: ' . $e->getMessage()); }   // 🙏 not shipped after N hours: say sorry
        if (function_exists('wa_review_tick')) { try { $res['review_read'] = wa_review_tick($pdo, $nowTs); } catch (Throwable $e) { error_log('wa_review_tick: ' . $e->getMessage()); } }   // ⭐ reads review screenshots (always, even if the request message is off)
        if (function_exists('wa_bdayask_queue')) { try { $res['bday_ask'] = wa_bdayask_queue($pdo, $nowTs, $cfg, $sk); } catch (Throwable $e) { error_log('wa_bdayask_queue: ' . $e->getMessage()); } }   // 🎂 the birthday question, 1 day after an order
        if (function_exists('wa_bday_sync')) { try { wa_bday_sync($pdo); } catch (Throwable $e) { error_log('wa_bday_sync: ' . $e->getMessage()); } }   // 🎂 mark birthday codes as sent / failed
        if (function_exists('wa_review_nudge_queue')) { try { $res['nudge'] = wa_review_nudge_queue($pdo, $nowTs, $cfg); } catch (Throwable $e) { error_log('wa_review_nudge_queue: ' . $e->getMessage()); } }
        if (function_exists('wa_review_queue')) { try { $res['review'] = wa_review_queue($pdo, $nowTs, $cfg, $sk); } catch (Throwable $e) { error_log('wa_review_queue: ' . $e->getMessage()); } }   // ⭐ 5 days after delivery
        // 2. send what is due (quiet hours: due_at is the next 08:00)
        $due = $pdo->prepare("SELECT * FROM wa_ord_sent WHERE state = 'queued' AND due_at <= ?" . ($onlyStore !== '' ? ' AND parcel_id IN (SELECT id FROM dp_dsp_parcels WHERE store_key = ' . $pdo->quote($onlyStore) . ')' : '') . " ORDER BY id LIMIT 40"); $due->execute([wo_dt($nowTs)]); $ctxCache = [];
        foreach ($due->fetchAll() as $row) {
            $r = wo_send_one($pdo, $row, $nowTs, $ctxCache); $res[$r['out']] = ($res[$r['out']] ?? 0) + 1; if (!empty($r['stop'])) { $res['stop'] = $r['stop']; break; }
        }
    } catch (Throwable $e) { $res['error'] = $e->getMessage(); if (function_exists('wa_log')) wa_log($pdo, 'orders', false, 'order updates: ' . mb_substr($e->getMessage(), 0, 200)); }
    return $res;
}
/** 💬 The check-in (2 days), the take-back offer (15 days) and the no-questions return offer (30 days) after a parcel was delivered. ONE of each per order; only parcels delivered after the switch was turned on;
 *  never to a customer with an open complaint / Dispatch case. The days are set in Automation → After delivery. Returns how many were queued. */
function wa_ord_after_queue(PDO $pdo, int $nowTs, array $cfg, string $sk, int $go): int
{
    $n = 0;
    foreach (['care_d2', 'return_d15', 'return_d30'] as $k) {
        if (empty($cfg['statuses'][$k])) continue;
        $sk2 = 'wa_ord_since_' . $k; $since = (int)wo_get($pdo, $sk2, '0'); if ($since <= 0) { $since = $nowTs; wo_put($pdo, $sk2, (string)$since); }
        if (wo_tpl_for($pdo, $k) === null) continue;   // the template is not approved yet: nothing is queued
        $dly = wo_timing($pdo, $k);
        $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'delivered' AND delivered_at >= ? AND delivered_at <= ? AND phone IS NOT NULL AND phone <> ''$sk ORDER BY delivered_at LIMIT 200");
        $q->execute([wo_dt(max($since, $nowTs - ($dly + 9) * 86400)), wo_dt($nowTs - $dly * 86400)]);
        foreach ($q->fetchAll() as $p) {
            if (wo_class($p, $nowTs) !== 'delivered' || wo_is_cx_parcel($pdo, $p)) continue;
            $key = wo_key($p['phone']); if ($key === '' || (function_exists('wa_valid_phone') && !wa_valid_phone($key))) continue;
            if (function_exists('wr_blocked_open_case') && wr_blocked_open_case($pdo, $key, (string)$p['order_name'])) continue;
            $due = wo_next_open(max($nowTs, strtotime((string)$p['delivered_at']) + $dly * 86400));
            $ins = $pdo->prepare("INSERT IGNORE INTO wa_ord_sent (parcel_id, phone, order_name, status, tpl_name, state, created_at, due_at) VALUES (?,?,?,?,?, 'queued', ?, ?)");
            $ins->execute([(int)$p['id'], $key, (string)$p['order_name'], $k, wo_send_defs()[$k][0], wo_dt($nowTs), wo_dt($due)]); if ($ins->rowCount() > 0) $n++;
        }
    }
    return $n;
}
/** 🙏 Orders still not shipped (no courier scan) N hours after they were made (72 by default): ONE sorry message per order. Only orders made after the switch was turned on. Returns how many were queued. */
function wa_ord_late_queue(PDO $pdo, int $nowTs, array $cfg, string $sk, int $go): int
{
    if (empty($cfg['statuses']['dispatch_late'])) return 0;
    if (wo_tpl_for($pdo, 'dispatch_late') === null) return 0;   // the template is not approved yet: nothing is queued
    $hours = wo_timing($pdo, 'dispatch_late');
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND transit_at IS NULL AND created_at >= ? AND created_at <= ? AND phone IS NOT NULL AND phone <> ''$sk ORDER BY id DESC LIMIT 200");
    $q->execute([wo_dt(max($go, $nowTs - 12 * 86400)), wo_dt($nowTs - $hours * 3600)]); $n = 0;
    foreach ($q->fetchAll() as $p) {
        if (wo_is_cx_parcel($pdo, $p)) continue;
        $key = wo_key($p['phone']); if ($key === '' || (function_exists('wa_valid_phone') && !wa_valid_phone($key))) continue;
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_ord_sent (parcel_id, phone, order_name, status, tpl_name, state, created_at, due_at) VALUES (?,?,?,?,?, 'queued', ?, ?)");
        $ins->execute([(int)$p['id'], $key, (string)$p['order_name'], 'dispatch_late', wo_send_defs()['dispatch_late'][0], wo_dt($nowTs), wo_dt(wo_next_open($nowTs))]); if ($ins->rowCount() > 0) $n++;
    }
    return $n;
}
/** A replacement / resend parcel of a complaint ticket: the ticket tells the customer itself ("New goods shipped"), so the order update stays quiet (no double message). */
function wo_is_cx_parcel(PDO $pdo, array $p): bool
{
    $awb = trim((string)($p['tracking_no'] ?? '')); if ($awb === '') return false;
    try { $q = $pdo->prepare("SELECT 1 FROM cx_links WHERE awb = ? AND kind IN ('replacement','resend') AND status <> 'cancelled' LIMIT 1"); $q->execute([$awb]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
/** One queued message. Returns ['out' => 'sent'|'skipped'|'failed'|'waiting', 'stop' => text?]. */
function wo_send_one(PDO $pdo, array $row, int $nowTs, array &$ctxCache): array
{
    $id = (int)$row['id']; $st = (string)$row['status'];
    $fin = function (string $state, string $why, string $out) use ($pdo, $id, $nowTs) { $pdo->prepare("UPDATE wa_ord_sent SET state = ?, err = ?, at = ? WHERE id = ?")->execute([$state, mb_substr($why, 0, 190), wo_dt($nowTs), $id]); return ['out' => $out]; };
    $cfg = wo_cfg($pdo); if (!$cfg['on'] || empty($cfg['statuses'][$st])) return $fin('skipped', 'Switched off', 'skipped');
    $bdV = null;
    if ($st === 'birthday') {   // 🎂 not a parcel: parcel_id is minus the birthday voucher's id
        $vq = $pdo->prepare("SELECT * FROM st_bday_vouchers WHERE voucher_id = ?"); $vq->execute([-(int)$row['parcel_id']]); $bdV = $vq->fetch(); if (!$bdV) return $fin('skipped', 'Birthday code not found', 'skipped');
        if ((string)$bdV['status'] !== 'active' || (string)$bdV['valid_to'] < date('Y-m-d', $nowTs)) return $fin('skipped', 'The birthday code is used or has expired', 'skipped');
        $p = ['id' => 0, 'customer' => (string)$bdV['name'], 'phone' => (string)$bdV['phone'], 'order_name' => (string)$bdV['code'], 'courier' => '', 'tracking_no' => ''];
    } else {
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE id = ?"); $q->execute([(int)$row['parcel_id']]); $p = $q->fetch(); if (!$p) return $fin('skipped', 'Parcel not found', 'skipped');
    if (($st === 'dispatch_late' ? (!in_array((string)$p['status'], ['placed', 'tracked'], true) || !empty($p['transit_at'])) : ($st === 'bday_ask' ? in_array(wo_class($p, $nowTs), ['cancelled', 'rto', 'lost', 'damaged'], true) : (in_array($st, ['review', 'review_nudge', 'care_d2', 'return_d15', 'return_d30'], true) ? wo_class($p, $nowTs) !== 'delivered' : wo_send_status(wo_class($p, $nowTs)) !== $st)))) return $fin('skipped', 'The parcel has moved on - this update is no longer true', 'skipped');
    if (wo_is_cx_parcel($pdo, $p)) return $fin('skipped', 'A complaint replacement - the ticket tells the customer itself', 'skipped');
    }
    $t = wo_tpl_for($pdo, $st); if (!$t) return $fin('skipped', 'The template is not approved', 'skipped');
    $key = (string)$row['phone']; $ct = function_exists('wa_ensure_contact') ? wa_ensure_contact($pdo, $key, (string)$p['customer']) : null; if (!$ct) return $fin('skipped', 'No chat record', 'skipped');
    if ((int)$ct['optout'] === 1) return $fin('skipped', 'Opted out', 'skipped');
    if (wo_quiet($nowTs)) { $pdo->prepare("UPDATE wa_ord_sent SET due_at = ? WHERE id = ?")->execute([wo_dt(wo_next_open($nowTs)), $id]); return ['out' => 'waiting']; }
    $ck = (string)$t['id']; if (!isset($ctxCache[$ck])) $ctxCache[$ck] = wa_camp_ctx($pdo, $t, 99);   // 99: an unanswered earlier message never blocks an order update
    [$nid, $why] = wa_pick_number($pdo, $ct, $ctxCache[$ck]);
    if ($nid === 0) { $pdo->prepare("UPDATE wa_ord_sent SET due_at = ?, err = ? WHERE id = ?")->execute([wo_dt($nowTs + 900), mb_substr($why, 0, 190), $id]); return ['out' => 'waiting']; }
    $vars = wo_vars($p, $st);
    if ($st === 'bday_ask' && function_exists('cbd_has') && cbd_has($pdo, $key)) return $fin('skipped', 'The birthday is already saved', 'skipped');
    if ($st === 'birthday' && $bdV) $vars = [wo_first_name((string)$bdV['name']), (function ($v) { $t = function_exists('st_bday_next') ? st_bday_next((string)$v['bday'], strtotime((string)$v['valid_from'])) : null; return date('j M', $t ?: strtotime((string)$v['valid_from'])); })($bdV), (string)$bdV['code'], date('j M', strtotime((string)$bdV['valid_to']))];
    if ($st === 'review_nudge') {   // name, order, the Judge.me link; only while the customer still has the offer open
        $o1 = $pdo->prepare("SELECT 1 FROM wa_review_rating WHERE parcel_id = ? AND offer = 'offered' AND reward_id = 0"); $o1->execute([(int)$row['parcel_id']]); if (!$o1->fetchColumn()) return $fin('skipped', 'No open review offer', 'skipped');
        $lk = function_exists('wr_clean_link') ? wr_clean_link(wr_cfg($pdo)['link']) : ''; if ($lk === '') return $fin('skipped', 'No Judge.me link set', 'skipped'); $vars[] = $lk;
    }
    [$okReq, $req] = wa_camp_request($pdo, $t, $ctxCache[$ck]['nums'][$nid], (string)($ct['wa_id'] ?: '91' . $key), $vars, []);
    if (!$okReq) return $fin('failed', (string)$req, 'failed');
    [$code, $d] = wa_api('POST', $req['path'], $req['json']); $wamid = (string)($d['messages'][0]['id'] ?? ''); $ec = (int)($d['error']['code'] ?? 0); $tries = (int)$row['tries'] + 1;
    if ($code >= 200 && $code < 300 && $wamid !== '') {
        $pdo->prepare("UPDATE wa_ord_sent SET state = 'sent', wamid = ?, number_id = ?, tries = ?, err = '', err_code = 0, sent_at = ?, at = ? WHERE id = ?")->execute([$wamid, $nid, $tries, wo_dt($nowTs), wo_dt($nowTs), $id]);
        wa_log_template_msg($pdo, $wamid, $key, $nid, wa_tpl_shown($t, $vars), 'order'); return ['out' => 'sent'];
    }
    $msg = mb_substr(function_exists('wa_err') ? wa_err(is_array($d) ? $d : null) : 'Meta did not accept this.', 0, 190);
    if (in_array($ec, [130429, 131056, 131042, 132000, 132001, 132005, 132007, 132012, 132015, 132016, 190, 131031], true) || $code === 401) { $pdo->prepare("UPDATE wa_ord_sent SET err = ?, err_code = ?, due_at = ? WHERE id = ?")->execute([$msg, $ec, wo_dt($nowTs + 600), $id]); if (function_exists('wa_log')) wa_log($pdo, 'orders', false, 'order updates stopped: ' . $msg); return ['out' => 'waiting', 'stop' => $msg]; }
    [$plan, $pw] = function_exists('wa_camp_fail_plan') ? wa_camp_fail_plan($ec, $tries, true) : ['final', 'Failed'];
    if ($plan === 'retry') { $nt = wo_next_open($nowTs + ($tries <= 1 ? 3 * 3600 : 86400)); $pdo->prepare("UPDATE wa_ord_sent SET tries = ?, err = ?, err_code = ?, due_at = ? WHERE id = ?")->execute([$tries, $msg, $ec, wo_dt($nt), $id]); return ['out' => 'waiting']; }
    $pdo->prepare("UPDATE wa_ord_sent SET state = ?, tries = ?, err = ?, err_code = ?, at = ? WHERE id = ?")->execute([$plan === 'skip' ? 'skipped' : 'failed', $tries, mb_substr($plan === 'pause' ? $pw : ($ec === 131026 ? 'Not on WhatsApp / cannot receive' : $msg), 0, 190), $ec, wo_dt($nowTs), $id]);
    if ($plan === 'pause' && function_exists('wa_log')) wa_log($pdo, 'orders', false, 'order updates: ' . $pw);
    return ['out' => $plan === 'skip' ? 'skipped' : 'failed'];
}
/** The numbers on the settings card. */
function wo_stats(PDO $pdo): array
{
    $today = date('Y-m-d 00:00:00', wo_now()); $month = date('Y-m-01 00:00:00', wo_now()); $defs = wo_send_defs(); $out = ['by' => []];
    foreach ($defs as $k => $d) $out['by'][$k] = ['today' => 0, 'month' => 0, 'queued' => 0];
    foreach ($pdo->query("SELECT status, state, COUNT(*) n, SUM(sent_at >= '$today') t FROM wa_ord_sent WHERE (state = 'sent' AND sent_at >= '$month') OR state = 'queued' GROUP BY status, state")->fetchAll() as $r) {
        if (!isset($out['by'][$r['status']])) continue; if ($r['state'] === 'queued') $out['by'][$r['status']]['queued'] += (int)$r['n']; else { $out['by'][$r['status']]['month'] += (int)$r['n']; $out['by'][$r['status']]['today'] += (int)$r['t']; }
    }
    $out['today'] = array_sum(array_column($out['by'], 'today')); $out['month'] = array_sum(array_column($out['by'], 'month'));
    $rate = 0.15; if (function_exists('wa_team_cfg')) { try { $rate = (float)wa_team_cfg($pdo)['rate_utility']; } catch (Throwable $e) { } }
    $out['rate'] = $rate; $out['cost_month'] = round($out['month'] * $rate, 2);
    $out['cost_text'] = 'About Rs ' . number_format($out['cost_month'], 2) . ' this month (' . $out['month'] . ' message' . ($out['month'] === 1 ? '' : 's') . ' x Rs ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '). Meta may charge less: order updates inside a 24-hour chat can be free.';
    return $out;
}
function wo_photo_info(PDO $pdo, string $status): ?array
{
    $nm = wo_photo_name($status); if ($nm === '') return null;   // "Order received" has no photo option
    $lib = wo_photo_cfg($pdo)[$status] ?? 0; $r = ['lib_id' => $lib, 'title' => '', 'template_status' => 'missing', 'template_reason' => ''];
    if ($lib > 0 && function_exists('wa_tpl_lib_row') && ($row = wa_tpl_lib_row($pdo, $lib))) $r['title'] = (string)$row['title']; elseif ($lib > 0) $r['lib_id'] = 0;
    try {
        if (function_exists('wa_tpl_tables') && wa_tpl_tables($pdo)) {
            $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $q->execute([$nm]); $id = (int)$q->fetchColumn();
            if ($id) { $st = wa_tpl_status($pdo, $id, function_exists('wa_wabas') ? wa_wabas($pdo) : []); $r['template_status'] = (string)$st['status']; $r['template_reason'] = (string)($st['reason'] ?? ''); }
        }
    } catch (Throwable $e) { }
    return $r;
}
function wo_settings(PDO $pdo): array
{
    $cfg = wo_cfg($pdo); $stat = wo_stats($pdo); $tpl = wo_tpl_state($pdo); $defs = wo_send_defs(); $st = [];
    $vers = wo_ver_sync($pdo); $bodies = wo_tpl_defs(); foreach ($defs as $k => $d) $st[] = ['key' => $k, 'vars' => array_values($bodies[$d[0]]['lab'] ?? []), 'body' => wo_live_body($pdo, $k), 'edit' => wo_ver_info($pdo, $k, $vers), 'when' => ['received' => 'when the order is completed (Shopify order or ✅ Order completed in the chat)', 'shipped' => 'when Shiprocket shows it picked up / in transit', 'out_for_delivery' => 'when the courier marks it out for delivery', 'delivered' => 'when the courier marks it delivered', 'failed' => 'when a delivery attempt fails', 'delayed' => 'when the courier\'s date has passed', 'care_d2' => 'about 2 days after the courier marks it delivered: how is the perfume, tell us here if you do not like it', 'return_d15' => 'about 15 days after delivery: if you still do not like it, we take it back', 'return_d30' => 'about 30 days after delivery: still not happy? send it back, no questions asked', 'dispatch_late' => 'once per order, when it still has not been shipped (no courier scan) after the hours you set below — 72 by default. Counts from when the order was made, and only for orders made after you switch this on', 'bday_ask' => 'once per customer, within about an hour after an order, only if no birthday is saved yet (the reply "14/12" is saved by itself and the gift goes on the birthday)', 'birthday' => 'the day BEFORE the customer\'s birthday, for online customers only (birthdays told when an order was made); the gift code is made at that moment and works online on an order above ₹1,500, or at the store. Store customers get their own message from the cashier (Store → Birthdays)', 'review_nudge' => 'once, 2 days after a 5-star rating if the review screenshot has not come (only while the offer is open)', 'review' => 'about 5 days after the courier marks it delivered (only orders delivered after you switch this on; never to a customer with an open complaint)'][$k] ?? '', 'label' => (string)(wo_titles($pdo)[$k] ?? $d[1]), 'default_label' => $d[1], 'timing' => isset(wo_timing_defs()[$k]) ? ['value' => wo_timing($pdo, $k)] + wo_timing_defs()[$k] : null, 'template' => $d[0], 'on' => $cfg['statuses'][$k], 'photo' => wo_photo_info($pdo, $k), 'template_status' => $tpl[$d[0]]['status'] ?? 'missing', 'template_reason' => $tpl[$d[0]]['reason'] ?? '', 'sent_today' => $stat['by'][$k]['today'], 'sent_month' => $stat['by'][$k]['month'], 'queued' => $stat['by'][$k]['queued']];
    return ['on' => $cfg['on'], 'statuses' => $st, 'sent_today' => $stat['today'], 'sent_month' => $stat['month'], 'rate' => $stat['rate'], 'cost_month' => $stat['cost_month'], 'cost_text' => $stat['cost_text'],
        'review' => function_exists('wr_cfg') ? wr_cfg($pdo) : ['link' => '', 'good_from' => 5],
        'quiet' => '9 PM - 8 AM India time: messages wait and go out at 8 AM', 'seeded' => wo_get($pdo, 'wa_ord_seeded', '') !== '', 'since' => (int)wo_get($pdo, 'wa_ord_send_since', '0') ? date('Y-m-d H:i', (int)wo_get($pdo, 'wa_ord_send_since', '0')) : ''];
}

// ------------------------------------------------------------------------------------------------------------------ the engine tick (wa_tick calls this every minute)
function wa_ord_tick(PDO $pdo): array
{
    $o = [];
    try {
        if (!wo_tables($pdo)) return $o;
        $last = (int)strtotime(wo_get($pdo, 'wa_ord_tick_at', '2000-01-01')); if (wo_now() - $last < 50 && !defined('WO_FORCE_TICK')) return ['skipped' => true];
        wo_put($pdo, 'wa_ord_tick_at', wo_dt(wo_now()));
        if (wo_get($pdo, 'wa_ord_seeded', '') === '' && function_exists('wa_wabas') && wa_wabas($pdo)) {   // once: make and submit the five templates (no waiting for the owner)
            wo_put($pdo, 'wa_ord_seeded', wo_dt(wo_now())); $o['seed'] = wa_ord_seed_templates($pdo, ['auto' => true]);
        }
        $o['send'] = wa_ord_autosend($pdo);
    } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'orders', false, 'order tick: ' . mb_substr($e->getMessage(), 0, 200)); }
    return $o;
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router
$__woa = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__woa, ['wa.bday_get', 'wa.bday_set', 'wa.ord_for', 'wa.ord_share', 'wa.ord_sum', 'wa.ord_hub', 'wa.ord_search', 'wa.ord_link', 'wa.ord_unlink', 'wa.ord_urgent', 'wa.ord_cases', 'wa.ord_case_take', 'wa.ord_case_done', 'wa.ord_case_note', 'wa.store_get', 'wa.store_save', 'wa.ord_seed', 'wa.ord_photo', 'wa.ord_edit', 'wa.ord_edit_cancel', 'wa.ord_edit_revert', 'wa.ord_settings'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !wo_tables($pdo)) json_response(['error' => 'Order storage is not available — check database permissions.'], 500);
    $in = $isPost ? json_input() : $_GET; $adm = wa_is_admin($pdo, $auth); $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    // May this login see the dispatch cases? Admin / HR, or a person holding a Dispatch seat.
    $dispOk = function () use ($pdo, $auth, $adm) { if ($adm) return true; if (!wo_env() || !function_exists('dp_can_seat')) return false; foreach (['dispatch.lead', 'dispatch.tracking', 'dispatch.untraceable', 'dispatch.untraceable_backup'] as $s) { try { if (dp_can_seat($pdo, $auth, $s)) return true; } catch (Throwable $e) { } } return false; };
    // the chat this request is about (must be a chat this login may open)
    $chat = function () use ($pdo, $auth, $adm, $in) {
        $k = wo_key($in['phone'] ?? ''); if ($k === '') json_response(['error' => 'Write the customer\'s 10-digit phone number.'], 422);
        $c = wa_contact($pdo, $k); if (!$c) { if ($adm) return [$k, null]; json_response(['error' => 'Chat not found.'], 404); }
        if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
        return [$k, $c];
    };
    if ($__woa === 'wa.bday_get') {   // the birthday saved for this chat's customer (md = MM-DD or '')
        [$k] = $chat(); $md = ''; try { if (function_exists('cbd_tables') && cbd_tables($pdo)) { $bq = $pdo->prepare("SELECT md FROM cust_bday WHERE phone = ?"); $bq->execute([$k]); $md = (string)$bq->fetchColumn(); } } catch (Throwable $e) { }
        json_response(['ok' => true, 'md' => $md]);
    }
    if ($__woa === 'wa.bday_set') {   // 🎂 a birthday (day + month) collected while an order is made. Any logged-in team member; never the year.
        $needPost(); $md = function_exists('cbd_md') ? cbd_md($in['day'] ?? 0, $in['month'] ?? 0) : null; if ($md === null) json_response(['error' => 'Write a real day and month, like 14 and 12.'], 422);
        $ph = wo_key($in['phone'] ?? ''); if ($ph === '') json_response(['error' => 'The customer\'s 10-digit mobile number is needed.'], 422);
        if (!cbd_set($pdo, $ph, mb_substr(trim((string)($in['name'] ?? '')), 0, 120), $md, 'order', $me)) json_response(['error' => 'Could not save the birthday.'], 500);
        json_response(['ok' => true, 'message' => 'Birthday saved. The gift code goes on WhatsApp on the day.', 'bday' => $md]);
    }
    switch ($__woa) {
        case 'wa.ord_for': {
            [$k] = $chat(); $r = wa_ord_for($pdo, $k); $r['can_urgent'] = true; $r['reasons'] = wo_manual_reasons(); $r['open_case'] = ($oc = wa_ord_case_open($pdo, $k, '')) ? wo_case_view($pdo, $oc, false) : null;
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ord_cases WHERE phone = ? AND status = 'open'"); $q->execute([$k]); $r['open_cases'] = (int)$q->fetchColumn(); json_response($r);
        }
        case 'wa.ord_share': {   // the customer-safe version of one order, for the card that is sent to the customer
            [$k] = $chat(); $want = wo_norm_order((string)($in['order'] ?? '')); if ($want === '') json_response(['error' => 'Pick an order.'], 422);
            foreach (wa_ord_for($pdo, $k)['orders'] as $o) if (wo_norm_order((string)$o['order_name']) === $want) json_response(['ok' => true, 'card' => wo_share_view($pdo, $o)]);
            json_response(['error' => 'That order is not linked to this customer.'], 404);
        }
        case 'wa.ord_sum': {   // the small summary under the phone number in the chat header: how many orders, how much, what is on the way
            [$k] = $chat(); $r = wa_ord_for($pdo, $k);
            $S = ['n' => 0, 'total' => 0.0, 'delivered' => 0, 'active' => 0, 'problem' => 0, 'cancelled' => 0, 'prepaid' => 0, 'cod' => 0, 'last_at' => null, 'first_at' => null, 'latest' => null, 'more' => count($r['orders']) >= 12];
            foreach ($r['orders'] as $o) {
                $at = (string)$o['placed_at']; if ($S['last_at'] === null || $at > $S['last_at']) $S['last_at'] = $at; if ($S['first_at'] === null || ($at !== '' && $at < $S['first_at'])) $S['first_at'] = $at;
                if ($S['latest'] === null) $S['latest'] = ['order_name' => $o['order_name'], 'status' => $o['status'], 'status_label' => $o['status_label']];
                if ($o['status'] === 'cancelled') { $S['cancelled']++; continue; }
                $S['n']++; $S['total'] += (float)($o['total'] ?? 0);
                if ($o['status'] === 'delivered') $S['delivered']++; elseif (in_array($o['status'], ['failed', 'rto', 'lost', 'damaged', 'delayed'], true)) $S['problem']++; else $S['active']++;
                if (preg_match('/paid/', (string)$o['fin']) && !preg_match('/partial|unpaid/', (string)$o['fin'])) $S['prepaid']++; elseif ($o['fin'] !== '') $S['cod']++;
            }
            $S['aov'] = $S['n'] ? round($S['total'] / $S['n']) : 0; $S['total'] = round($S['total']);
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ord_cases WHERE phone = ? AND status = 'open'"); $q->execute([$k]); $S['open_cases'] = (int)$q->fetchColumn();
            $S['bday'] = function_exists('cbd_get') ? cbd_get($pdo, $k) : '';
            json_response(['ok' => true] + $S);
        }
        case 'wa.ord_hub': {   // the full Order + Customer card (same as Dispatch's big popup) for the customer of THIS chat; only their own orders
            [$k] = $chat();
            if (!wo_env()) json_response(['error' => 'The full order view is not available on this server.'], 500);
            foreach (['dp_ensure', 'dp_dsp_ensure'] as $fn) { if (function_exists($fn)) { try { $fn($pdo); } catch (Throwable $e) { } } }
            if (!function_exists('ohub_view')) json_response(['error' => 'order_hub.php is missing on this server.'], 500);
            $g = ['phone' => $k, 'order' => trim((string)($in['order'] ?? '')), 'store' => trim((string)($in['store'] ?? '')), 'sid' => trim((string)($in['sid'] ?? '')), 'bill' => (int)($in['bill'] ?? 0)];
            if ($g['bill'] > 0) {   // a store bill: only if it is this customer's
                $bq = $pdo->prepare("SELECT customer_phone FROM st_bills WHERE bill_id = ?"); $bq->execute([$g['bill']]);
                if (wo_key((string)$bq->fetchColumn()) !== $k) json_response(['error' => 'That bill is not this customer\'s.'], 403);
            } elseif ($g['order'] !== '' || $g['sid'] !== '') {   // an order: only if this chat's number is on it (order number, extra number, hand link)
                $pr = function_exists('ohub_parcel') ? ohub_parcel($pdo, $g) : null; $ok = false;
                if ($pr) {
                    $keys = wo_keys_of($pr['phone'] ?? '');
                    try { $xq = $pdo->prepare("SELECT phone FROM order_extra_phones WHERE order_id = ? OR (order_name <> '' AND LOWER(TRIM(LEADING '#' FROM order_name)) = ?)"); $xq->execute([(string)$pr['shopify_id'], strtolower(ltrim((string)$pr['order_name'], '#'))]); foreach ($xq->fetchAll() as $x) $keys = array_merge($keys, wo_keys_of($x['phone'])); } catch (Throwable $e) { }
                    try { $lq = $pdo->prepare("SELECT phone FROM wa_ord_links WHERE shopify_id = ? OR LOWER(TRIM(LEADING '#' FROM order_name)) = ?"); $lq->execute([(string)$pr['shopify_id'], strtolower(ltrim((string)$pr['order_name'], '#'))]); foreach ($lq->fetchAll() as $x) $keys[] = (string)$x['phone']; } catch (Throwable $e) { }
                    $ok = in_array($k, array_map('wo_key', $keys), true);
                }
                if (!$ok) json_response(['error' => 'That order is not linked to this customer.'], 403);
            }
            $r = ohub_view($pdo, $g);
            if (empty($r['ok'])) json_response(['error' => $r['error'] ?? 'No order found for this customer.'], 404);
            json_response($r);
        }
        case 'wa.ord_search': {
            [$k] = $chat(); $qs = trim((string)($in['q'] ?? '')); if (mb_strlen($qs) < 3) json_response(['results' => [], 'note' => 'Type at least 3 characters: an order number or a phone number.']);
            $res = []; $seen = []; $digits = preg_replace('/\D+/', '', $qs);
            $push = function ($s, $p) use (&$res, &$seen, $pdo) {
                $id = ($p ? $p['store_key'] . '|' . $p['shopify_id'] : $s['store_key'] . '|' . $s['shopify_id']); if (isset($seen[$id])) return; $seen[$id] = 1; $row = $p ?: ['status' => '', 'courier' => '', 'sr_status' => '', 'etd' => null, 'delivered_at' => null]; $cls = $p ? wo_class($p) : 'placed';
                $ph = (string)($p ? $p['phone'] : $s['phone']); $kk = wo_key($ph);
                $res[] = ['order_name' => $p ? (string)$p['order_name'] : (string)$s['order_name'], 'store' => $p ? (string)$p['store_key'] : (string)$s['store_key'], 'shopify_id' => $p ? (string)$p['shopify_id'] : (string)$s['shopify_id'],
                    'customer' => $p ? (string)$p['customer'] : (string)$s['customer'], 'phone_hint' => $kk !== '' ? '•••••' . substr($kk, -4) : '', 'placed_at' => $p ? (string)$p['created_at'] : (string)$s['created_at'], 'total' => $s ? (float)$s['total'] : null, 'status_text' => wo_status_text($cls, $row)];
            };
            $n = wo_norm_order($qs);
            $a = $pdo->prepare("SELECT * FROM shop_orders WHERE LOWER(order_name) IN (?, ?) ORDER BY created_at DESC LIMIT 8"); $a->execute([$n, '#' . $n]); foreach ($a->fetchAll() as $s) $push($s, wo_parcel_by($pdo, (string)$s['store_key'], (string)$s['shopify_id']));
            $a = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE LOWER(order_name) IN (?, ?) ORDER BY created_at DESC LIMIT 8"); $a->execute([$n, '#' . $n]); foreach ($a->fetchAll() as $p) $push(wo_shop_by($pdo, (string)$p['store_key'], (string)$p['shopify_id']), $p);
            if (strlen($digits) >= 10) { $kk = substr($digits, -10); foreach (wo_find_shop($pdo, $kk, 10) as $s) $push($s, wo_parcel_by($pdo, (string)$s['store_key'], (string)$s['shopify_id'])); foreach (wo_find_parcels($pdo, $kk, 10) as $p) $push(wo_shop_by($pdo, (string)$p['store_key'], (string)$p['shopify_id']), $p); }
            json_response(['results' => array_slice($res, 0, 12)]);
        }
        case 'wa.ord_link': {
            $needPost(); [$k, $c] = $chat(); $name = trim((string)($in['order_name'] ?? '')); $sid = trim((string)($in['shopify_id'] ?? '')); $store = trim((string)($in['store'] ?? ''));
            if ($name === '' && $sid === '') json_response(['error' => 'Choose an order to link.'], 422);
            $p = wo_parcel_by($pdo, $store, $sid, $name); $s = wo_shop_by($pdo, $store, $sid, $name);
            if (!$p && !$s) json_response(['error' => 'No order found with that number.'], 404);
            if ($store === '' && $name !== '' && !$sid) {   // the same number in two stores: the person must choose
                $a = $pdo->prepare("SELECT DISTINCT store_key FROM shop_orders WHERE LOWER(order_name) IN (?, ?)"); $n = wo_norm_order($name); $a->execute([$n, '#' . $n]); $stores = array_column($a->fetchAll(), 'store_key');
                if (count($stores) > 1) json_response(['error' => 'That order number exists in more than one store. Pick the order from the list.', 'stores' => $stores], 409);
            }
            $oname = $p ? (string)$p['order_name'] : (string)$s['order_name']; $ostore = $p ? (string)$p['store_key'] : (string)$s['store_key']; $osid = $p ? (string)$p['shopify_id'] : (string)$s['shopify_id'];
            $pdo->prepare("INSERT INTO wa_ord_links (phone, order_name, store_key, shopify_id, parcel_id, linked_by, linked_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE shopify_id = VALUES(shopify_id), parcel_id = VALUES(parcel_id), linked_by = VALUES(linked_by), linked_at = VALUES(linked_at)")
                ->execute([$k, $oname, $ostore, $osid, $p ? (int)$p['id'] : 0, $me, wo_dt(wo_now())]);
            wo_chat_event($pdo, $k, '🔗 ' . wo_me_name($pdo, $auth) . ' linked order ' . $oname . ' to this chat.');
            json_response(['ok' => true, 'order_name' => $oname] + wa_ord_for($pdo, $k));
        }
        case 'wa.ord_unlink': {
            $needPost(); [$k] = $chat(); $name = trim((string)($in['order_name'] ?? '')); $store = trim((string)($in['store'] ?? '')); if ($name === '') json_response(['error' => 'Choose the order.'], 422);
            $d = $pdo->prepare("DELETE FROM wa_ord_links WHERE phone = ? AND order_name = ?" . ($store !== '' ? " AND store_key = ?" : "")); $d->execute($store !== '' ? [$k, $name, $store] : [$k, $name]);
            if ($d->rowCount() > 0) wo_chat_event($pdo, $k, '🔗 ' . wo_me_name($pdo, $auth) . ' removed the link to order ' . $name . '.');
            json_response(['ok' => true, 'removed' => $d->rowCount()] + wa_ord_for($pdo, $k));
        }
        case 'wa.ord_urgent': {
            $needPost(); [$k, $c] = $chat(); $kind = (string)($in['kind'] ?? 'delivery'); $reason = (string)($in['reason'] ?? ''); $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 300)); $order = trim(mb_substr((string)($in['order_name'] ?? ''), 0, 40));
            if (!in_array($kind, ['delivery', 'complaint'], true)) json_response(['error' => 'Choose: delivery / order problem, or complaint.'], 422);
            if (mb_strlen($note) < 3) json_response(['error' => 'Write one line about the problem.'], 422);
            $nm = $c ? trim((string)$c['name']) : '';
            if ($kind === 'delivery') {
                if (!isset(wo_manual_reasons()[$reason])) json_response(['error' => 'Choose a reason.'], 422);
                $store = ''; $pid = 0; if ($order !== '') { $p = wo_parcel_by($pdo, '', '', $order); if ($p) { $store = (string)$p['store_key']; $pid = (int)$p['id']; $order = (string)$p['order_name']; } }
                $r = wo_case_make($pdo, ['phone' => $k, 'cust_name' => $nm, 'order_name' => $order, 'store_key' => $store, 'parcel_id' => $pid, 'kind' => 'manual', 'reason' => $reason, 'note' => $note, 'raised_by' => $me, 'raised_name' => wo_me_name($pdo, $auth)]);
                json_response(['ok' => true, 'kind' => 'delivery', 'case_id' => $r['id'], 'existing' => !$r['created'], 'message' => $r['created'] ? 'Sent to Dispatch. They will see it under Needs attention.' : 'This customer already has an open case in Dispatch. Your note was added to it.']);
            }
            $map = ['staff' => 'staff', 'quality' => 'quality', 'refund' => 'refund', 'other' => 'other']; if (!isset($map[$reason])) json_response(['error' => 'Choose what the complaint is about.'], 422);
            if (!wo_env() || !function_exists('cx_raise') || !function_exists('cx_ensure') || !function_exists('bd_who') || !function_exists('cx_role')) json_response(['error' => 'Complaints are not available right now. Open Complaints and add it there.'], 503);
            cx_ensure($pdo); $role = cx_role($pdo, bd_who($pdo, $auth));
            $res = cx_raise($pdo, $auth, $role, ['problem' => $map[$reason], 'urgency' => 'normal', 'source' => 'whatsapp', 'phone' => $k, 'name' => $nm, 'order_name' => $order, 'summary' => $note]);
            $x = $res[3] ?? []; json_response(['ok' => (bool)$res[0], 'kind' => 'complaint', 'message' => (string)$res[1], 'code' => (string)($x['code'] ?? ''), 'complaint_id' => (int)($x['id'] ?? 0), 'existing' => !empty($x['existing'])], $res[0] ? 200 : (int)($res[2] ?? 422));
        }
        case 'wa.ord_cases': {
            if (!$dispOk()) json_response(['error' => 'This list is for the Dispatch team, Admin and HR.'], 403);
            $mep = $me > 0 ? $me : -max(1, (int)($auth['user_id'] ?? 1)); $st = (string)($in['status'] ?? 'open'); if (!in_array($st, ['open', 'done', 'all'], true)) $st = 'open'; $counts = wo_case_counts($pdo, $mep);
            if (!empty($in['count_only'])) json_response(['counts' => $counts]);
            json_response(['cases' => wo_cases_list($pdo, $st, max(1, min(300, (int)($in['limit'] ?? 100)))), 'counts' => $counts, 'me' => $mep, 'is_admin' => $adm, 'reasons' => wo_reasons(), 'now' => date('Y-m-d H:i:s', wo_now())]);
        }
        case 'wa.ord_case_take':
        case 'wa.ord_case_done':
        case 'wa.ord_case_note': {
            $needPost(); if (!$dispOk()) json_response(['error' => 'This is for the Dispatch team, Admin and HR.'], 403);
            $id = (int)($in['id'] ?? 0); $q = $pdo->prepare("SELECT * FROM wa_ord_cases WHERE id = ?"); $q->execute([$id]); $c = $q->fetch(); if (!$c) json_response(['error' => 'Case not found.'], 404);
            $oid = $me > 0 ? $me : -max(1, (int)($auth['user_id'] ?? 1)); $mep = $oid;   // a login with no employee record (the owner's Admin login) still gets its own id
            $who = wo_me_name($pdo, $auth); $txt = trim(mb_substr((string)($in['note'] ?? ''), 0, 500));
            if ($__woa === 'wa.ord_case_note') { if (mb_strlen($txt) < 2) json_response(['error' => 'Write the note.'], 422); wo_case_note($pdo, $id, $me, $who, $txt, 'note'); $pdo->prepare("UPDATE wa_ord_cases SET updated_at = ? WHERE id = ?")->execute([wo_dt(wo_now()), $id]); }
            elseif ($c['status'] !== 'open') json_response(['error' => 'This case is already done.'], 409);
            elseif ($__woa === 'wa.ord_case_take') {
                if ((int)$c['owner_emp'] !== 0 && (int)$c['owner_emp'] !== $oid && !$adm) json_response(['error' => (string)$c['owner_name'] . ' already has this case.'], 409);
                $pdo->prepare("UPDATE wa_ord_cases SET owner_emp = ?, owner_name = ?, taken_at = ?, updated_at = ? WHERE id = ?")->execute([$oid, mb_substr($who, 0, 80), wo_dt(wo_now()), wo_dt(wo_now()), $id]);
                wo_case_note($pdo, $id, $me, $who, $who . ' took this case.', 'event'); if ($c['phone'] !== '') wo_chat_event($pdo, (string)$c['phone'], '✋ ' . $who . ' (Dispatch) took the Needs attention case.');
            } else { wo_case_close($pdo, $id, $me, $who, $txt); if ($c['phone'] !== '') wo_chat_event($pdo, (string)$c['phone'], '✔ Dispatch finished the Needs attention case' . ($txt !== '' ? ': ' . wo_short($txt, 120) : '.')); }
            $q->execute([$id]); json_response(['ok' => true, 'case' => wo_case_view($pdo, $q->fetch()), 'counts' => wo_case_counts($pdo, $mep)]);
        }
        case 'wa.store_get': { json_response(['info' => wa_store_info($pdo), 'open_now' => wa_store_open_now($pdo), 'can_edit' => $adm, 'days' => wo_days(), 'text' => ['hours' => wa_store_text($pdo, 'hours'), 'delivery' => wa_store_text($pdo, 'delivery')]]); }
        case 'wa.store_save': {
            $needPost(); wa_guard_admin($pdo, $auth); $info = is_array($in['info'] ?? null) ? $in['info'] : $in; $clean = wo_store_clean($info);
            $pdo->prepare("INSERT INTO wa_store_info (k, v, updated_at, updated_by) VALUES ('info', ?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)")->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), wo_dt(wo_now()), $me]);
            if (function_exists('kb_touch')) kb_touch($pdo, 'Store info');
            json_response(['ok' => true, 'info' => wa_store_info($pdo), 'open_now' => wa_store_open_now($pdo)]);
        }
        case 'wa.ord_seed': {
            $needPost(); wa_guard_admin($pdo, $auth); @set_time_limit(120); $r = wa_ord_seed_templates($pdo, ['retry' => !empty($in['retry'])]); wo_put($pdo, 'wa_ord_seeded', wo_dt(wo_now()));
            json_response(['ok' => empty($r['error']) && !$r['failed']] + $r + ['templates' => wo_tpl_state($pdo)]);
        }
        case 'wa.ord_photo': {   // POST {status, lib_id}: lib_id 0 = go back to the plain text message
            $needPost(); wa_guard_admin($pdo, $auth); @set_time_limit(120);
            $st = (string)($in['status'] ?? ''); $lib = max(0, (int)($in['lib_id'] ?? 0)); if (wo_photo_name($st) === '') json_response(['error' => 'This message cannot have a photo.'], 422);
            $cur = wo_photo_cfg($pdo); $msg = 'The plain text message is used again.';
            if ($lib > 0) {
                $row = function_exists('wa_tpl_lib_row') ? wa_tpl_lib_row($pdo, $lib) : null; if (!$row || (string)$row['kind'] !== 'image') json_response(['error' => 'Pick a photo (JPG or PNG) from the media library.'], 422);
                [$ok, $msg] = wo_photo_make($pdo, $st, $lib); if (!$ok) json_response(['error' => $msg], 422);
                $cur[$st] = $lib;
            } else unset($cur[$st]);
            wo_put($pdo, 'wa_ord_photo', json_encode($cur)); json_response(['ok' => true, 'message' => $msg] + wo_settings($pdo));
        }
        case 'wa.ord_edit': {   // POST {status, body, lib_id?}: a new version of one message (new words and / or a photo), sent to Meta
            $needPost(); wa_guard_admin($pdo, $auth); @set_time_limit(120);
            [$ok, $msg] = wo_ver_save($pdo, (string)($in['status'] ?? ''), (string)($in['body'] ?? ''), max(0, (int)($in['lib_id'] ?? 0))); if (!$ok) json_response(['error' => $msg], 422);
            json_response(['ok' => true, 'message' => $msg] + wo_settings($pdo));
        }
        case 'wa.ord_edit_cancel': {   // POST {status}: drop the version that waits for Meta (the live one is untouched)
            $needPost(); wa_guard_admin($pdo, $auth); $st = (string)($in['status'] ?? ''); $all = wo_ver_all($pdo); if (isset($all[$st])) { $all[$st]['pending'] = null; wo_ver_put($pdo, $all); }
            json_response(['ok' => true, 'message' => 'The new version was cancelled.'] + wo_settings($pdo));
        }
        case 'wa.ord_edit_revert': {   // POST {status}: go back to the original message (the edited one goes to history)
            $needPost(); wa_guard_admin($pdo, $auth); $st = (string)($in['status'] ?? ''); $all = wo_ver_all($pdo);
            if (isset($all[$st]) && is_array($all[$st]['active'] ?? null)) { $a = $all[$st]['active']; $a['until'] = wo_dt(wo_now()); $h = is_array($all[$st]['history'] ?? null) ? $all[$st]['history'] : []; array_unshift($h, $a); $all[$st]['active'] = null; $all[$st]['history'] = array_slice($h, 0, 10); wo_ver_put($pdo, $all); }
            json_response(['ok' => true, 'message' => 'The original message is live again.'] + wo_settings($pdo));
        }
        case 'wa.ord_settings': {
            wa_guard_admin($pdo, $auth);
            if ($isPost) {
                if (array_key_exists('on', $in)) wo_put($pdo, 'wa_ord_auto_on', !empty($in['on']) ? '1' : '0');
                if (is_array($in['timing'] ?? null)) { $TD = wo_timing_defs(); $cur = json_decode(wo_get($pdo, 'wa_ord_timing', ''), true); $cur = is_array($cur) ? $cur : [];
                    foreach ($in['timing'] as $k => $v) { if (!isset($TD[$k]) || !is_numeric($v)) continue; $v = (int)$v; if ($v < $TD[$k]['min'] || $v > $TD[$k]['max']) json_response(['error' => $TD[$k]['label'] . ': choose between ' . $TD[$k]['min'] . ' and ' . $TD[$k]['max'] . '.'], 422);
                        if ($k === 'birthday') { if (function_exists('st_bday_cfg') && function_exists('st_bday_cfg_save')) { $c0 = st_bday_cfg($pdo); $c0['send_before'] = $v; st_bday_cfg_save($pdo, $c0); } } else $cur[$k] = $v; }
                    wo_put($pdo, 'wa_ord_timing', json_encode($cur)); }
                if (is_array($in['titles'] ?? null)) { $tt = wo_titles($pdo); foreach ($in['titles'] as $k => $v) { if (!isset(wo_send_defs()[$k])) continue; $v = trim(mb_substr((string)$v, 0, 80)); if ($v === '' || $v === wo_send_defs()[$k][1]) unset($tt[$k]); else $tt[$k] = $v; } wo_put($pdo, 'wa_ord_titles', json_encode($tt, JSON_UNESCAPED_UNICODE)); }
                if (array_key_exists('review_link', $in) && function_exists('wr_clean_link')) { $rl = trim((string)$in['review_link']); if ($rl !== '' && wr_clean_link($rl) === '') json_response(['error' => 'The review link must start with https://'], 422); wo_put($pdo, 'wa_review_link', $rl); }
                if (array_key_exists('review_good', $in)) { $g = (int)$in['review_good']; if ($g >= 2 && $g <= 5) wo_put($pdo, 'wa_review_good', (string)$g); }
                if (is_array($in['statuses'] ?? null)) { $cur = wo_cfg($pdo)['statuses']; foreach ($in['statuses'] as $k => $v) if (isset($cur[$k])) $cur[$k] = !empty($v); wo_put($pdo, 'wa_ord_status_on', json_encode($cur)); }
            }
            json_response(wo_settings($pdo));
        }
    }
}
