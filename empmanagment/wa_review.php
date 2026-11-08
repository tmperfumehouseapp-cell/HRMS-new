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
            at DATETIME NOT NULL, action VARCHAR(12) NOT NULL DEFAULT '', ticket VARCHAR(20) NOT NULL DEFAULT '',
            offer VARCHAR(9) NOT NULL DEFAULT '', reminded_at DATETIME NULL, shot_at DATETIME NULL, shot_media VARCHAR(80) NOT NULL DEFAULT '', shot_hash CHAR(40) NOT NULL DEFAULT '', verdict VARCHAR(200) NOT NULL DEFAULT '', reward_id INT NOT NULL DEFAULT 0,
            KEY k_phone (phone, at), KEY k_offer (offer, shot_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (["ALTER TABLE wa_review_rating ADD COLUMN offer VARCHAR(9) NOT NULL DEFAULT ''", "ALTER TABLE wa_review_rating ADD COLUMN reminded_at DATETIME NULL", "ALTER TABLE wa_review_rating ADD COLUMN shot_at DATETIME NULL",
                  "ALTER TABLE wa_review_rating ADD COLUMN shot_media VARCHAR(80) NOT NULL DEFAULT ''", "ALTER TABLE wa_review_rating ADD COLUMN shot_hash CHAR(40) NOT NULL DEFAULT ''", "ALTER TABLE wa_review_rating ADD COLUMN verdict VARCHAR(200) NOT NULL DEFAULT ''",
                  "ALTER TABLE wa_review_rating ADD COLUMN reward_id INT NOT NULL DEFAULT 0"] as $alt) { try { $pdo->exec($alt); } catch (Throwable $e) { } }   // an older copy of the table gets the new columns
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
    try { if (!wr_tables($pdo)) return false; $q = $pdo->prepare("SELECT 1 FROM wa_review_rating WHERE phone = ? AND (at >= ? OR shot_at >= ?) LIMIT 1"); $q->execute([$key, wo_dt(wo_now() - 180), wo_dt(wo_now() - 600)]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
/** A customer message: if it is the answer (1-5) to a rating request we sent in the last 7 days, handle it. Returns true when it was a rating. */
function wa_review_hook(PDO $pdo, string $key, string $text): bool
{
    $t = trim($text); if ($t === '') return false;
    if (mb_strpos($t, '📷') === 0) return wr_shot_hook($pdo, $key);   // a photo: maybe the review screenshot
    if (mb_strlen($t) > 40 || !preg_match('/^\W{0,3}([1-5])(?:\s|\W|$)/u', $t, $m)) return false;
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
            $rw = wr_rw_ready($pdo) ? cxrw_cfg($pdo) : null; $offer = $link !== '' && $rw && (int)$rw['review_ml'] > 0;
            $msg = 'Thank you so much, ' . $name . '! 💖 We are so happy you liked it.' . ($link !== '' ? "\n\nIf you have a minute, please share your rating as a review. It really helps us:\n" . $link : '')
                 . ($offer ? "\n\n🎁 As a thank-you: post your review, then send us a screenshot of it right here. You will get " . (int)$rw['review_ml'] . ' ml FREE perfume of your choice with your next order above ₹' . number_format((int)$rw['review_min'], 0, '.', ',') . ' (valid ' . (int)$rw['review_days'] . ' days).' : '')
                 . "\n\nWe are always here if you need anything 🙏";
            if ($offer) $pdo->prepare("UPDATE wa_review_rating SET offer = 'offered' WHERE parcel_id = ?")->execute([(int)$s['parcel_id']]);
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

/** Loads the Complaints module (it holds the rewards ledger) when this request does not have it yet. */
function wr_rw_ready(PDO $pdo): bool
{
    if (function_exists('cxrw_cfg')) return true;
    try { $action = 'wa.review'; if (is_file(__DIR__ . '/boss.php')) require_once __DIR__ . '/boss.php'; } catch (Throwable $e) { error_log('wr_rw_ready: ' . $e->getMessage()); }
    return function_exists('cxrw_cfg');
}
/** A photo from a customer who was offered the review gift in the last 14 days: remember it; the minute tick reads it. */
function wr_shot_hook(PDO $pdo, string $key): bool
{
    try {
        if (!wr_tables($pdo)) return false;
        $q = $pdo->prepare("SELECT parcel_id FROM wa_review_rating WHERE phone = ? AND action = 'judgeme' AND offer = 'offered' AND reward_id = 0 AND at >= ? ORDER BY at DESC LIMIT 1"); $q->execute([$key, wo_dt(wo_now() - 14 * 86400)]); $pid = (int)$q->fetchColumn();
        if ($pid < 1) return false;
        $pdo->prepare("UPDATE wa_review_rating SET offer = 'shot_wait', shot_at = ? WHERE parcel_id = ?")->execute([wo_dt(wo_now()), $pid]);
        return false;   // the photo still shows in the chat as usual
    } catch (Throwable $e) { error_log('wr_shot_hook: ' . $e->getMessage()); return false; }
}
/** Gives the review gift for one rating row (once), tells the customer. Returns ['code', 'id'] or ['error']. */
function wr_reward_for_review(PDO $pdo, array $r, string $why, int $by): array
{
    if (!wr_rw_ready($pdo)) return ['error' => 'The rewards module is not loaded.'];
    if ((int)$r['reward_id'] > 0) return ['error' => 'The gift was already given for this order.'];
    $cfg = cxrw_cfg($pdo); if ((int)$cfg['review_ml'] < 1) return ['error' => 'The review gift is switched off (0 ml in the settings).'];
    $g = cxrw_give($pdo, (string)$r['phone'], 'review', (int)$cfg['review_ml'], (int)$cfg['review_min'], (int)$cfg['review_days'], 'review', (string)$r['parcel_id'], (string)$r['order_name'], 'Review gift: ' . $why, 'ok', $by);
    if (!empty($g['error'])) return $g;
    $u = $pdo->prepare("UPDATE wa_review_rating SET offer = 'paid', reward_id = ? WHERE parcel_id = ? AND reward_id = 0"); $u->execute([(int)$g['id'], (int)$r['parcel_id']]);
    if ($u->rowCount() < 1) { $pdo->prepare("UPDATE rw_reward SET status = 'void' WHERE id = ?")->execute([(int)$g['id']]); return ['error' => 'The gift was already given for this order.']; }
    $c = function_exists('wa_contact') ? wa_contact($pdo, (string)$r['phone']) : null;
    if ($c && function_exists('wa_cxself_say')) wa_cxself_say($pdo, $c, '✅ Thank you ' . wo_first_name((string)($c['name'] ?? '')) . '! 💖 Your ' . (int)$cfg['review_ml'] . " ml FREE perfume (your choice) is added.\n\nCode: " . $g['code'] . "\nValid till " . date('j M Y', strtotime((string)$g['expires'])) . ' on an order above ₹' . number_format((int)$cfg['review_min'], 0, '.', ',') . ".\nJust tell us when you order and which perfume you want 🙏");
    if (function_exists('wa_log')) wa_log($pdo, 'orders', true, 'review gift ' . $g['code'] . ' for ' . $r['order_name'] . ' (' . $why . ')');
    return ['code' => (string)$g['code'], 'id' => (int)$g['id']];
}
/** Asks the AI to read the screenshot. Returns ['ok' => bool, 'why' => text, 'hash' => sha1]. ok = a real 5-star review, confidently. */
function wr_ai_check(PDO $pdo, string $mid): array
{
    $out = ['ok' => false, 'why' => '', 'hash' => ''];
    if (!function_exists('wa_mcache_get') || !function_exists('convo_ai')) { $out['why'] = 'The screenshot reader is not available.'; return $out; }
    [$path, $mime, $err] = wa_mcache_get($pdo, $mid); if ($path === '') { $out['why'] = 'Could not download the photo: ' . mb_substr((string)$err, 0, 80); return $out; }
    if (filesize($path) > 6 * 1048576) { $out['why'] = 'The photo is too big to read.'; return $out; }
    $bin = file_get_contents($path); if ($bin === false || $bin === '') { $out['why'] = 'The photo could not be read.'; return $out; } $out['hash'] = sha1($bin);
    $mime = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/jpeg';
    $schema = ['type' => 'OBJECT', 'properties' => ['is_review' => ['type' => 'BOOLEAN'], 'stars' => ['type' => 'INTEGER'], 'platform' => ['type' => 'STRING'], 'confidence' => ['type' => 'INTEGER'], 'note' => ['type' => 'STRING']], 'required' => ['is_review', 'stars', 'platform', 'confidence', 'note']];
    $sys = 'You check a screenshot a customer sent to claim a free gift for posting a product review. Say whether it shows a real product or store review (Judge.me, Google, Instagram, an app or a website review) and how many stars it gives (0 if no stars are visible). Be strict: a chat screenshot, a product photo, a payment screenshot, a blurred or cropped image, or a review with fewer than 5 stars is NOT enough. confidence is 0-100: how sure you are that it is a genuine 5-star review. note is one short sentence.';
    try { [$good, $v, $why] = convo_ai($sys, [['text' => 'The customer\'s screenshot:'], ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bin)]]], $schema, true, 600); } catch (Throwable $e) { $out['why'] = 'The reader failed: ' . mb_substr($e->getMessage(), 0, 80); return $out; }
    if (!$good || !is_array($v)) { $out['why'] = 'The reader could not answer' . ($why ? ': ' . mb_substr((string)$why, 0, 80) : '.'); return $out; }
    $out['why'] = ((int)($v['stars'] ?? 0)) . ' star(s) · ' . (string)($v['platform'] ?? '') . ' · ' . (int)($v['confidence'] ?? 0) . '% sure · ' . mb_substr((string)($v['note'] ?? ''), 0, 100);
    $out['ok'] = !empty($v['is_review']) && (int)($v['stars'] ?? 0) >= 5 && (int)($v['confidence'] ?? 0) >= 70;
    return $out;
}
/** Every minute (called by wa_ord_autosend): reads waiting screenshots (3 at a time) and marks reminders that went out. */
function wa_review_tick(PDO $pdo, int $nowTs): array
{
    $res = ['read' => 0, 'paid' => 0];
    if (!wr_tables($pdo)) return $res;
    try { $pdo->exec("UPDATE wa_review_rating r SET r.reminded_at = '" . wo_dt($nowTs) . "' WHERE r.reminded_at IS NULL AND EXISTS (SELECT 1 FROM wa_ord_sent s WHERE s.parcel_id = r.parcel_id AND s.status = 'review_nudge' AND s.state = 'sent')"); } catch (Throwable $e) { }
    $q = $pdo->prepare("SELECT * FROM wa_review_rating WHERE offer = 'shot_wait' AND shot_at <= ? ORDER BY shot_at LIMIT 3"); $q->execute([wo_dt($nowTs - 20)]);
    foreach ($q->fetchAll() as $r) {
        $m = $pdo->prepare("SELECT media_id FROM wa_messages WHERE phone = ? AND dir = 'in' AND mtype = 'image' AND media_id <> '' AND ts >= ? ORDER BY id DESC LIMIT 1"); $m->execute([(string)$r['phone'], wo_dt(strtotime((string)$r['shot_at']) - 180)]); $mid = (string)$m->fetchColumn();
        if ($mid === '') { if (strtotime((string)$r['shot_at']) < $nowTs - 900) $pdo->prepare("UPDATE wa_review_rating SET offer = 'offered', verdict = 'No photo was found in the chat' WHERE parcel_id = ?")->execute([(int)$r['parcel_id']]); continue; }
        $res['read']++; $chk = wr_ai_check($pdo, $mid); $c = function_exists('wa_contact') ? wa_contact($pdo, (string)$r['phone']) : null;
        $dup = false; if ($chk['hash'] !== '') { $d = $pdo->prepare("SELECT 1 FROM wa_review_rating WHERE shot_hash = ? AND parcel_id <> ? LIMIT 1"); $d->execute([$chk['hash'], (int)$r['parcel_id']]); $dup = (bool)$d->fetchColumn(); }
        $auto = wr_rw_ready($pdo) && (int)cxrw_cfg($pdo)['auto_shot'] === 1;
        $pdo->prepare("UPDATE wa_review_rating SET shot_media = ?, shot_hash = ?, verdict = ? WHERE parcel_id = ?")->execute([$mid, $chk['hash'], mb_substr(($dup ? 'This same photo was used for another order. ' : '') . $chk['why'], 0, 200), (int)$r['parcel_id']]);
        if ($chk['ok'] && $auto && !$dup) { $g = wr_reward_for_review($pdo, $r, 'screenshot read by the system (' . $chk['why'] . ')', 0); if (!empty($g['code'])) { $res['paid']++; continue; } }
        $pdo->prepare("UPDATE wa_review_rating SET offer = 'check' WHERE parcel_id = ? AND reward_id = 0")->execute([(int)$r['parcel_id']]);   // a person looks at it (Complaints → Reviews)
        if ($c && function_exists('wa_cxself_say')) wa_cxself_say($pdo, $c, 'Thank you ' . wo_first_name((string)($c['name'] ?? '')) . '! 💖 Our team is checking your review and will add your free perfume to your account within a day 🙏');
    }
    return $res;
}

/** Queues the ONE reminder for a 5-star customer who has not sent the review screenshot 2 days after the offer (a pre-made message: the chat window is closed by then). */
function wa_review_nudge_queue(PDO $pdo, int $nowTs, array $cfg): array
{
    $res = ['queued' => 0];
    if (empty($cfg['statuses']['review_nudge']) || !wr_tables($pdo) || wo_tpl_for($pdo, 'review_nudge') === null) return $res;
    $link = wr_clean_link(wr_cfg($pdo)['link']); if ($link === '') return $res;
    $q = $pdo->prepare("SELECT * FROM wa_review_rating WHERE action = 'judgeme' AND offer = 'offered' AND reward_id = 0 AND reminded_at IS NULL AND at <= ? AND at >= ? LIMIT 100"); $q->execute([wo_dt($nowTs - 2 * 86400), wo_dt($nowTs - 9 * 86400)]);
    foreach ($q->fetchAll() as $r) {
        if (wr_blocked_open_case($pdo, (string)$r['phone'], (string)$r['order_name'])) continue;
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_ord_sent (parcel_id, phone, order_name, status, tpl_name, state, created_at, due_at) VALUES (?,?,?,?,?, 'queued', ?, ?)");
        $ins->execute([(int)$r['parcel_id'], (string)$r['phone'], (string)$r['order_name'], 'review_nudge', wo_send_defs()['review_nudge'][0], wo_dt($nowTs), wo_dt(wo_next_open($nowTs))]); if ($ins->rowCount() > 0) $res['queued']++;
    }
    return $res;
}
/** True while the customer has an open complaint (we do not ask for a review or remind then). */
function wr_blocked_open_case(PDO $pdo, string $key, string $order): bool
{
    try { $on = strtolower(ltrim($order, '#')); $q = $pdo->prepare("SELECT 1 FROM cx_cases WHERE status IN ('open','reopened') AND (phone = ? OR LOWER(TRIM(LEADING '#' FROM order_name)) = ?) LIMIT 1"); $q->execute([$key, $on]); if ($q->fetchColumn()) return true; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT 1 FROM wa_ord_cases WHERE status = 'open' AND phone = ? LIMIT 1"); $q->execute([$key]); if ($q->fetchColumn()) return true; } catch (Throwable $e) { }
    return false;
}

}   // function_exists guard
