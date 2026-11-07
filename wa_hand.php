<?php
/**
 * WhatsApp hand-over (loaded by wa_more.php).  Part of the HRMS WhatsApp section.
 *
 *   A customer who is ready to pay must never wait.  This file
 *     - spots such a chat ("HOT"): the customer asks for UPI / QR / payment link / says "order kar do" ... (wa_hot_scan, runs every tick),
 *     - gives it to the best present salesperson at once and opens the "call this customer now" alert,
 *     - lets the salesperson Accept the alert, set "Remind me" (30 min / 1 h / 3 h / tomorrow), send a payment link and press "Order placed",
 *     - finds the next present salesperson when the first one does not react (wa_next_person, used by wa_callnow_scan).
 *
 *   Actions (all need a login; a salesperson can only touch the chats assigned to them, an admin any chat):
 *     wa.accept         POST {phone}                 "I am on it" - closes the call-now alert and stamps accepted_at
 *     wa.remind         POST {phone, mins}           remind me about this chat in N minutes (5 - 2880)
 *     wa.remind_done    POST {phone}                 the reminder was seen
 *     wa.hand_mark      POST {phone, what}           what = 'paid' (order placed: clears HOT + reminder) | 'link' (payment link sent)
 *     wa.paylink_make   POST {phone}                 {url} - a ready payment link when the setting wa_paylink_tpl is filled in, else an empty url (the person pastes one)
 *
 *   Columns used (created by wa_more_tables): wa_contacts.hot, hot_at, hot_why, remind_at, pay_at, accepted_at, callnow, callnow_at, reassigns.
 *   Everything here is defensive: a failure never breaks the chat screens, it is only written to the WhatsApp log.
 */

if (!function_exists('wa_hot_intent')) {

/** Does this customer message say "I want to pay / order now"?  Returns a short reason or '' (not hot). Hindi / Hinglish / English. */
function wa_hot_intent(string $text): string
{
    $t = mb_strtolower(trim($text)); if ($t === '' || mb_strlen($t) > 600) return '';
    // a question about a payment problem or an old order is not a new sale
    if (preg_match('/(refund|return|cancel|not received|nahi mila|nahi aaya|tracking|where is my|kab aayega|delivery kab)/u', $t)) return '';
    $map = [
        'asked for UPI / QR'        => '/(\bupi\b|\bqr\b|gpay|google ?pay|phone ?pe|paytm|scanner|scan kar)/u',
        'asked how to pay'          => '/(payment (link|kar|karna|karu|karta|karti|details?|option|kaise|how)|pay (kar|karna|karu|karta|karti|kaise|how|now)|paise (bhej|send|kaha|kahan)|how (do|can|to) i pay|send (me )?(the )?(payment|link|qr|upi)|link (bhej|send|de do|do)|bank (detail|account)|account number|ifsc)/u',
        'wants to order now'        => '/(order (kar|karna|karu|karo|de do|dena|confirm|place)|i (want|wanna|would like) to (order|buy|book)|(place|confirm|book) (the |my )?order|confirm (kar|karo|karna)|book (kar|karo|karna)|le ?(lunga|lungi|lenge|leta|leti)|kar do order|ready to (buy|pay|order)|buy now|abhi (order|pay|le))/u',
        'agreed to buy (COD / deal)' => '/(\bcod\b.{0,20}(ok|chalega|theek|kar do|confirm)|(ok|theek hai|done|deal|final)\W{0,6}(kar do|karo|send|bhejo|confirm|order))/u',
    ];
    foreach ($map as $why => $re) if (preg_match($re, $t)) return $why;
    return '';
}

/** The next salesperson who is present and not full, other than $except. Returns ['emp_id', 'name', 'why'] or null. */
function wa_next_person(PDO $pdo, int $except = 0, int $numId = 0): ?array
{
    try {
        foreach (wa_people($pdo, $numId) as $p) {
            if ((int)$p['emp_id'] === $except) continue;
            if (!empty($p['present']) && empty($p['full'])) return ['emp_id' => (int)$p['emp_id'], 'name' => (string)$p['name'], 'why' => (string)($p['why'] ?? '')];
        }
    } catch (Throwable $e) { }
    return null;
}

/** Marks one chat HOT, gives it to a person when nobody has it, opens the call-now alert and pushes. Returns true when the chat was newly flagged. */
function wa_hot_flag(PDO $pdo, array $c, string $why): bool
{
    $key = (string)$c['phone']; $now = date('Y-m-d H:i:s');
    $u = $pdo->prepare("UPDATE wa_contacts SET hot = 1, hot_at = ?, hot_why = ? WHERE phone = ? AND hot = 0");
    $u->execute([$now, mb_substr($why, 0, 40), $key]); if ($u->rowCount() < 1) return false;
    $emp = (int)$c['assigned_emp']; $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $cu = trim((string)($c['name'] ?? '')) !== '' ? (string)$c['name'] : '+91 ' . $key;
    wa_event($pdo, $key, '🔥 HOT — ' . $why . '. A person must reply now.');
    if ($emp <= 0 && (int)($c['cx_id'] ?? 0) > 0) { /* a complaint chat with nobody on its desk yet: the Complaint team gets it, never a salesperson */ }
    elseif ($emp <= 0) {   // nobody has it: the best present person gets it right now (this also opens the call-now alert)
        $pick = null; foreach (wa_people($pdo, (int)($c['last_number_id'] ?? 0)) as $p) if (!empty($p['present'])) { if (empty($p['full'])) { $pick = $p; break; } if (!$pick) $pick = $p; }
        if ($pick) { wa_assign_to($pdo, $key, (int)$pick['emp_id'], 0, 'auto'); $emp = (int)$pick['emp_id']; }
    } else {           // somebody has it: make sure the alert is open for them
        $pdo->prepare("UPDATE wa_contacts SET callnow = 1, callnow_at = ?, accepted_at = NULL WHERE phone = ?")->execute([$now, $key]);
    }
    if (function_exists('ct_push')) {
        try {
            if ($emp > 0) ct_push($pdo, [$emp], '🔥 Customer wants to pay', $cu . ' — ' . $why . '. Reply or call now.', '/my_chats.html?phone=' . $key, 'wa-hot-' . $key);
            else {
                $al = function_exists('wa_team_cfg') ? wa_team_cfg($pdo)['alert_emps'] : [];
                if ($al) ct_push($pdo, $al, '🔥 HOT chat — nobody is free', $cu . ' wants to pay and no salesperson is present.', '/whatsapp.html#chats', 'wa-hot-' . $key);
            }
        } catch (Throwable $e) { }
    }
    return true;
}

/** Looks at the customer's newest messages of open chats that are not HOT yet.  Returns how many chats were flagged now. */
function wa_hot_scan(PDO $pdo): int
{
    $since = date('Y-m-d H:i:s', time() - 2 * 86400); $n = 0;
    $q = $pdo->prepare("SELECT COALESCE(MIN(id), 0) FROM wa_messages WHERE ts >= ?"); $q->execute([$since]); $minId = (int)$q->fetchColumn();   // newest messages only: walks the id index backwards, never the whole table
    $st = $pdo->prepare("SELECT m.phone, m.body, m.id FROM (SELECT phone, body, id, ts FROM wa_messages WHERE id >= ? AND dir = 'in' AND body IS NOT NULL AND body != '' ORDER BY id DESC LIMIT 1500) m JOIN wa_contacts c ON c.phone = m.phone
        WHERE c.hot = 0 AND c.status = 'open' AND c.optout = 0 AND (c.hot_at IS NULL OR m.ts > c.hot_at)
        ORDER BY m.id DESC LIMIT 600");
    $st->execute([$minId]); $seen = [];
    foreach ($st->fetchAll() as $m) {
        $p = (string)$m['phone']; if (isset($seen[$p])) continue;
        $why = wa_hot_intent((string)$m['body']); if ($why === '') continue;
        $seen[$p] = 1; $c = wa_contact($pdo, $p); if (!$c) continue;
        if (wa_hot_flag($pdo, $c, $why)) $n++;
        if ($n >= 25) break;
    }
    return $n;
}

/** Clears the HOT flag, alert and reminder of a chat. */
function wa_hot_clear(PDO $pdo, string $key, bool $keepPay = true): void
{
    $pdo->prepare("UPDATE wa_contacts SET hot = 0, remind_at = NULL, callnow = IF(callnow IN (1, 2), 9, callnow)" . ($keepPay ? '' : ', pay_at = NULL') . " WHERE phone = ?")->execute([$key]);
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- actions
$__wh = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wh, ['wa.accept', 'wa.remind', 'wa.remind_done', 'wa.hand_mark', 'wa.paylink_make'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $auth = require_auth();
    if (!wa_tables($pdo) || !wa_more_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null;
    if (!$c) json_response(['error' => 'Chat not found.'], 404);
    if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
    $me = (int)($auth['employee_id'] ?? 0); $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $who = $me > 0 ? ($nm[$me] ?? 'Someone') : 'Admin';
    $now = date('Y-m-d H:i:s');
    switch ($__wh) {
        case 'wa.accept': {
            $pdo->prepare("UPDATE wa_contacts SET accepted_at = ?, callnow = IF(callnow IN (1, 2), 9, callnow) WHERE phone = ?")->execute([$now, $key]);
            wa_event($pdo, $key, $who . ' accepted this chat');
            json_response(['ok' => true]);
        }
        case 'wa.remind': {
            $m = max(5, min(2880, (int)($in['mins'] ?? 60)));
            $at = date('Y-m-d H:i:s', time() + $m * 60);
            $pdo->prepare("UPDATE wa_contacts SET remind_at = ? WHERE phone = ?")->execute([$at, $key]);
            wa_event($pdo, $key, $who . ' set a reminder for ' . date('H:i', strtotime($at)) . ($m >= 1440 ? ' (tomorrow)' : ''));
            json_response(['ok' => true, 'remind_at' => $at]);
        }
        case 'wa.remind_done': {
            $pdo->prepare("UPDATE wa_contacts SET remind_at = NULL WHERE phone = ?")->execute([$key]);
            json_response(['ok' => true]);
        }
        case 'wa.hand_mark': {
            $what = (string)($in['what'] ?? '');
            if ($what === 'paid') {
                wa_hot_clear($pdo, $key); wa_event($pdo, $key, '✅ Order placed — marked by ' . $who);
            } elseif ($what === 'link') {
                $pdo->prepare("UPDATE wa_contacts SET pay_at = ?, hot = 0, callnow = IF(callnow IN (1, 2), 9, callnow) WHERE phone = ?")->execute([$now, $key]);
                wa_event($pdo, $key, '💳 Payment link sent by ' . $who);
            } else json_response(['error' => 'Unknown hand-over action.'], 422);
            json_response(['ok' => true]);
        }
        case 'wa.paylink_make': {
            // Optional: Setup → the payment page of the own website, e.g. https://tmperfumehouse.com/pay?p={phone}. Empty = the person pastes a link.
            $tpl = trim((string)setting_get($pdo, 'wa_paylink_tpl', ''));
            $url = ($tpl !== '' && preg_match('~^https://~i', $tpl)) ? str_replace('{phone}', rawurlencode($key), $tpl) : '';
            json_response(['ok' => true, 'url' => $url]);
        }
    }
}
