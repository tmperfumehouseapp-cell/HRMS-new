<?php
/**
 * wa_review.php — ⭐ a rating request 5 days after delivery. Loaded by wa_more.php (after wa_orders.php). PHP 7.4 safe.
 *
 *  1. 5 days after the courier marks an order delivered, ONE pre-made message asks "How would you rate it?" with five buttons (5 … 1).
 *     It is the order-update status "review" (template tmph_review_request), so it uses the same queue, quiet hours (9 PM - 8 AM), opt-out,
 *     "template approved" check and switch as the other order messages. It is OFF until Admin switches it on, and then it only goes to orders
 *     delivered AFTER that moment (nobody's old orders are suddenly asked).
 *  2. The customer taps a button (or types 1-5). Rating >= the "good from" setting (default 5) -> a thank-you with the Judge.me review link.
 *     Lower -> a short apology and a Complaints ticket (the normal ticket flow tells the team and the customer).
 *  3. Never asked: a complaint-replacement parcel, a customer with an open complaint / Dispatch case, or a customer who already got a rating
 *     request in the last 30 days. Each order is asked once and rated once.
 *  Settings (Admin, Order-update messages card): wa_review_link (the Judge.me page) and wa_review_good (lowest rating that goes to Judge.me).
 */
if (!function_exists('wa_review_queue')) {

function wr_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_review_rating (parcel_id INT NOT NULL PRIMARY KEY, phone VARCHAR(12) NOT NULL DEFAULT '', order_name VARCHAR(40) NOT NULL DEFAULT '', rating TINYINT NOT NULL DEFAULT 0,
            at DATETIME NOT NULL, action VARCHAR(12) NOT NULL DEFAULT '', ticket VARCHAR(20) NOT NULL DEFAULT '', KEY k_phone (phone, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}
function wr_cfg(PDO $pdo): array
{
    $good = (int)wo_get($pdo, 'wa_review_good', '5'); if ($good < 2 || $good > 5) $good = 5;
    $link = trim(wo_get($pdo, 'wa_review_link', ''));
    return ['link' => $link, 'good_from' => $good];
}
/** The Judge.me page the customer is sent to (Admin pastes it). Only a normal https address is accepted. */
function wr_clean_link(string $s): string
{
    $s = trim($s); if ($s === '') return '';
    return preg_match('#^https://[^\s<>"\']{4,300}$#i', $s) ? $s : '';
}

/** Queues the rating request for orders delivered 5 days ago (never before the feature was switched on). Called by wa_ord_autosend. */
function wa_review_queue(PDO $pdo, int $nowTs, array $cfg, string $sk = ''): array
{
    $res = ['queued' => 0, 'waiting' => 0];
    if (empty($cfg['statuses']['review']) || !wr_tables($pdo)) return $res;
    $since = (int)wo_get($pdo, 'wa_review_since', '0'); if ($since <= 0) { $since = $nowTs; wo_put($pdo, 'wa_review_since', (string)$since); }
    if (wo_tpl_for($pdo, 'review') === null) { $res['waiting'] = 1; return $res; }   // the template is not approved yet: nothing is queued
    $from = wo_dt(max($since, $nowTs - 14 * 86400)); $to = wo_dt($nowTs - 5 * 86400);
    $q = $pdo->prepare("SELECT * FROM dp_dsp_parcels WHERE status = 'delivered' AND delivered_at >= ? AND delivered_at <= ? AND phone IS NOT NULL AND phone <> ''$sk ORDER BY delivered_at LIMIT 200");
    $q->execute([$from, $to]);
    foreach ($q->fetchAll() as $p) {
        if (wo_class($p, $nowTs) !== 'delivered') continue;
        if (function_exists('wo_is_cx_parcel') && wo_is_cx_parcel($pdo, $p)) continue;
        $key = wo_key($p['phone']); if ($key === '' || (function_exists('wa_valid_phone') && !wa_valid_phone($key))) continue;
        if (wr_blocked($pdo, $key, (string)$p['order_name'], $nowTs)) continue;
        $due = wo_next_open(max($nowTs, strtotime((string)$p['delivered_at']) + 5 * 86400));
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_ord_sent (parcel_id, phone, order_name, status, tpl_name, state, created_at, due_at) VALUES (?,?,?,?,?, 'queued', ?, ?)");
        $ins->execute([(int)$p['id'], $key, (string)$p['order_name'], 'review', wo_send_defs()['review'][0], wo_dt($nowTs), wo_dt($due)]); if ($ins->rowCount() > 0) $res['queued']++;
    }
    return $res;
}
/** True = do NOT ask this customer now (open complaint / Dispatch case, or already asked in the last 30 days). */
function wr_blocked(PDO $pdo, string $key, string $order, int $nowTs): bool
{
    try {
        $on = strtolower(ltrim($order, '#'));
        $q = $pdo->prepare("SELECT 1 FROM cx_cases WHERE status IN ('open','reopened') AND (phone = ? OR LOWER(TRIM(LEADING '#' FROM order_name)) = ?) LIMIT 1"); $q->execute([$key, $on]); if ($q->fetchColumn()) return true;
    } catch (Throwable $e) { }   // no complaint tables on this install
    try { $q = $pdo->prepare("SELECT 1 FROM wa_ord_cases WHERE status = 'open' AND phone = ? LIMIT 1"); $q->execute([$key]); if ($q->fetchColumn()) return true; } catch (Throwable $e) { }
    $q = $pdo->prepare("SELECT 1 FROM wa_ord_sent WHERE phone = ? AND status = 'review' AND state IN ('sent','queued') AND created_at >= ? LIMIT 1"); $q->execute([$key, wo_dt($nowTs - 30 * 86400)]);
    return (bool)$q->fetchColumn();
}

/** True for 3 minutes after a customer's rating answer was handled (so the bot does not answer the same "5" as well). */
function wa_review_recent(PDO $pdo, string $key): bool
{
    try { if (!wr_tables($pdo)) return false; $q = $pdo->prepare("SELECT 1 FROM wa_review_rating WHERE phone = ? AND at >= ? LIMIT 1"); $q->execute([$key, wo_dt(wo_now() - 180)]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
/** A customer message: if it is the answer (1-5) to a rating request we sent in the last 7 days, handle it. Returns true when it was a rating. */
function wa_review_hook(PDO $pdo, string $key, string $text): bool
{
    $t = trim($text); if ($t === '' || mb_strlen($t) > 40 || !preg_match('/^\W{0,3}([1-5])(?:\s|\W|$)/u', $t, $m)) return false;
    try {
        if (!function_exists('wo_tables') || !wo_tables($pdo) || !wr_tables($pdo)) return false;
        $q = $pdo->prepare("SELECT s.* FROM wa_ord_sent s LEFT JOIN wa_review_rating r ON r.parcel_id = s.parcel_id WHERE s.phone = ? AND s.status = 'review' AND s.state = 'sent' AND s.sent_at >= ? AND r.parcel_id IS NULL ORDER BY s.id DESC LIMIT 1");
        $q->execute([$key, wo_dt(wo_now() - 7 * 86400)]); $s = $q->fetch(); if (!$s) return false;
        $c = function_exists('wa_contact') ? wa_contact($pdo, $key) : null; if (!$c || (int)($c['optout'] ?? 0) === 1) return false;
        $rating = (int)$m[1]; $cfg = wr_cfg($pdo); $order = (string)$s['order_name'];
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_review_rating (parcel_id, phone, order_name, rating, at, action) VALUES (?,?,?,?,?, ?)");
        $action = $rating >= $cfg['good_from'] ? 'judgeme' : 'ticket'; $ins->execute([(int)$s['parcel_id'], $key, $order, $rating, wo_dt(wo_now()), $action]); if ($ins->rowCount() < 1) return false;   // already rated
        $name = wo_first_name((string)($c['name'] ?? ''));
        if ($action === 'judgeme') {
            $link = wr_clean_link($cfg['link']);
            $msg = 'Thank you so much, ' . $name . '! 💖 We are so happy you liked it.' . ($link !== '' ? "\n\nIf you have a minute, please share your rating as a review. It really helps us:\n" . $link : '') . "\n\nWe are always here if you need anything 🙏";
            if (function_exists('wa_cxself_say')) wa_cxself_say($pdo, $c, $msg);
            if (function_exists('wa_log')) wa_log($pdo, 'orders', true, 'rating ' . $rating . ' for ' . $order . ' → Judge.me link ' . ($link !== '' ? 'sent' : 'NOT set (Admin: add it in Order-update messages)'));
        } else {
            if (function_exists('wa_cxself_say')) wa_cxself_say($pdo, $c, 'Thank you for telling us, ' . $name . ' 🙏 We are really sorry it was not perfect. We want to make it right.');
            $ticket = function_exists('wa_cxself_raise') ? wa_cxself_raise($pdo, $c, 'other', 'Rating ' . $rating . '/5 after delivery of order ' . $order . '. Please contact the customer, ask what went wrong and fix it.', $order) : '';
            if ($ticket !== '') $pdo->prepare("UPDATE wa_review_rating SET ticket = ? WHERE parcel_id = ?")->execute([$ticket, (int)$s['parcel_id']]);
            elseif (function_exists('wa_cxself_say')) wa_cxself_say($pdo, $c, 'Our team will contact you very soon to fix this 🙏');
            if (function_exists('wa_log')) wa_log($pdo, 'orders', true, 'rating ' . $rating . ' for ' . $order . ' → complaint ' . ($ticket !== '' ? $ticket : 'could not be raised'));
        }
        return true;
    } catch (Throwable $e) { error_log('wa_review_hook: ' . $e->getMessage()); return false; }
}

}   // function_exists guard
