<?php
/**
 * wa_done.php — ✅ ORDER COMPLETED: one thank-you, then silence.
 *
 * The owner's rule: once the address and the payment are in (the order is completed) the customer gets ONE "order received — thank you" message and
 * after that NO automatic message for the rest of that day and the whole next day — no bot answer, no follow-up, no flow, no campaign. Order updates
 * (shipped / out for delivery / delivered, wa_orders.php) still go, and a person can always write. The chat goes on to the salesperson as usual.
 *
 * An order counts as completed when:
 *   1. a Shopify order comes in for the customer's number (they wrote to us in the last 7 days) — found by wa_done_scan() every 2 minutes, or
 *   2. a person presses "✅ Order completed" in the chat (COD / UPI orders not in Shopify yet)  →  wa.order_done POST {phone, order?}
 *
 * Every completed order also feeds the Teach bot: once a day, when 5+ new orders were completed, Claude reads those chats and writes
 * "how these orders were won" as a Teach report (Approve → the bot learns it; nothing changes without approval).
 *
 *   wa_auto_muted($pdo, $phone)      → true while automatic messages are off for this customer (used by wa_send_text, the bot, follow-ups, campaigns)
 *   wa.order_done  GET {phone}        → {done, at, until, how, by, order}        POST {phone, order?}  → mark completed    POST {phone, undo:1} → switch automatic messages back on
 * Loaded by wa_more.php. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }
if (!function_exists('wa_done_tables')) {

function wa_done_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_order_done (phone VARCHAR(12) NOT NULL PRIMARY KEY, at DATETIME NOT NULL, by_emp INT NOT NULL DEFAULT 0, how VARCHAR(10) NOT NULL DEFAULT 'person',
            order_name VARCHAR(40) NOT NULL DEFAULT '', mute_until DATETIME NOT NULL, thanks_at DATETIME NULL, learned TINYINT NOT NULL DEFAULT 0, KEY k_at (at), KEY k_mute (mute_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true; } catch (PDOException $e) { error_log('wa_done_tables: ' . $e->getMessage()); return $ok = false; }
}
/** Silence ends at the end of the NEXT day (the rest of today + tomorrow). */
function wa_done_until(int $ts): string { return date('Y-m-d 23:59:59', strtotime(date('Y-m-d', $ts) . ' +1 day')); }
function wa_done_row(PDO $pdo, string $key): ?array { if (!wa_done_tables($pdo)) return null; $q = $pdo->prepare("SELECT * FROM wa_order_done WHERE phone = ?"); $q->execute([$key]); $r = $q->fetch(); return $r ?: null; }
/** True while automatic messages are switched off for this customer (cached per request). */
function wa_auto_muted(PDO $pdo, string $key): bool
{
    static $c = []; $key = substr(preg_replace('/\D+/', '', $key), -10); if ($key === '') return false; if (array_key_exists($key, $c)) return $c[$key];
    if (function_exists('wa_cxself_state') && wa_cxself_state($pdo, $key)) return $c[$key] = true;   // 🎫 the customer is raising a ticket: the bot waits
    if (function_exists('wa_bday_recent') && wa_bday_recent($pdo, $key)) return $c[$key] = true;   // 🎂 the customer just told us their birthday: only our own thank-you goes out
    if (function_exists('wa_review_recent') && wa_review_recent($pdo, $key)) return $c[$key] = true;   // ⭐ the customer just answered our rating request: only our own thank-you goes out
    if (function_exists('wq_rescue_human_on') && wq_rescue_human_on($pdo, $key)) return $c[$key] = true;   // 🛟 a person took this customer in Rescue: no automatic message while they work it
    $r = wa_done_row($pdo, $key); return $c[$key] = $r && strtotime((string)$r['mute_until']) > time();
}
/** ⏸ The follow-up plan (Autopilot + Journey) steps aside for a while when a PERSON wrote to the customer (36 h) or a campaign went to them (2 days). Bot replies do not count. Steps due meanwhile are skipped, not caught up.
 *  Returns '' (go on) or the reason in plain words. */
function wq_plan_paused(PDO $pdo, string $key, ?int $now = null): string
{
    static $c = []; $key = substr(preg_replace('/\D+/', '', $key), -10); if ($key === '') return ''; $now = $now ?: time(); $ck = $key . '|' . intdiv($now, 60); if (isset($c[$ck])) return $c[$ck];
    if (isset($GLOBALS['__wq_pz'][intdiv($now, 60)][$key])) return $GLOBALS['__wq_pz'][intdiv($now, 60)][$key] === '-' ? '' : $GLOBALS['__wq_pz'][intdiv($now, 60)][$key];   // answered by the batch below
    $r = '';
    try { $q = $pdo->prepare("SELECT who, MAX(ts) m FROM wa_messages WHERE phone = ? AND dir = 'out' AND status <> 'failed' AND ((who IN ('emp','person','phone')) OR who = 'campaign') AND ts >= ? GROUP BY who"); $q->execute([$key, date('Y-m-d H:i:s', $now - 2 * 86400)]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $t = strtotime((string)$x['m']);
            if ($x['who'] === 'campaign') $r = 'a campaign went in the last 2 days';
            elseif ($t >= $now - 36 * 3600 && $r === '') $r = 'a person wrote in the last 36 h'; }
    } catch (Throwable $e) { }
    return $c[$ck] = $r;
}
/** One query for a whole batch of customers (the engines check hundreds per round): fills the answer wq_plan_paused() gives for each. */
function wq_plan_paused_prefetch(PDO $pdo, array $phones, ?int $now = null): void
{
    $now = $now ?: time(); $m = intdiv($now, 60); $ph = []; foreach ($phones as $p) { $k = substr(preg_replace('/\D+/', '', (string)$p), -10); if ($k !== '') $ph[$k] = '-'; }
    if (!$ph) return;
    try { foreach (array_chunk(array_keys($ph), 400) as $ch) { $in = implode(',', array_fill(0, count($ch), '?'));
            $q = $pdo->prepare("SELECT phone, who, MAX(ts) m FROM wa_messages WHERE dir = 'out' AND status <> 'failed' AND (who IN ('emp','person','phone') OR who = 'campaign') AND ts >= ? AND phone IN ($in) GROUP BY phone, who"); $q->execute(array_merge([date('Y-m-d H:i:s', $now - 2 * 86400)], $ch));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $k = (string)$x['phone']; if ($x['who'] === 'campaign') $ph[$k] = 'a campaign went in the last 2 days'; elseif (strtotime((string)$x['m']) >= $now - 36 * 3600 && ($ph[$k] ?? '-') === '-') $ph[$k] = 'a person wrote in the last 36 h'; } }
        $GLOBALS["__wq_pz"] = [$m => array_replace($GLOBALS["__wq_pz"][$m] ?? [], $ph)];   // only this minute is kept
    } catch (Throwable $e) { }
}
/** Marks the order completed: silence until the end of tomorrow, follow-ups stopped, ONE thank-you (once per day). Returns the state. */
function wa_done_mark(PDO $pdo, string $key, int $by, string $how, string $order = ''): array
{
    if (!wa_done_tables($pdo)) return ['error' => 'Storage is not available.'];
    $now = time(); $old = wa_done_row($pdo, $key); $until = wa_done_until($now);
    $pdo->prepare("INSERT INTO wa_order_done (phone, at, by_emp, how, order_name, mute_until, learned) VALUES (?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE at = VALUES(at), by_emp = VALUES(by_emp), how = VALUES(how), order_name = IF(VALUES(order_name) <> '', VALUES(order_name), order_name), mute_until = VALUES(mute_until), learned = 0")
        ->execute([$key, date('Y-m-d H:i:s', $now), $by, $how, mb_substr($order, 0, 40), $until]);
    try { $pdo->prepare("UPDATE wa_fu_state SET status = 'done', stop_why = 'order completed' WHERE phone = ? AND status NOT IN ('done', 'stopped')")->execute([$key]); } catch (Throwable $e) { }   // follow-ups stop at once
    try { $pdo->prepare("UPDATE wa_flow_runs SET state = 'stopped' WHERE phone = ? AND state IN ('run', 'wait', 'ready')")->execute([$key]); } catch (Throwable $e) { }                     // a running flow stops
    $thanks = ''; if (!$old || empty($old['thanks_at']) || strtotime((string)$old['thanks_at']) < $now - 20 * 3600) $thanks = wa_done_thanks($pdo, $key, $order);
    if (function_exists('wa_event')) { try { wa_event($pdo, $key, '✅ Order completed' . ($order !== '' ? ' (' . $order . ')' : '') . ' — automatic messages off until ' . date('j M', strtotime($until)) . ' night' . ($thanks !== '' ? ' · ' . $thanks : '')); } catch (Throwable $e) { } }
    return wa_done_state($pdo, $key) + ['thanks' => $thanks];
}
/** The "order received — thank you" message: a free chat message inside the 24-hour window, else the approved template. Returns a short note. */
function wa_done_thanks(PDO $pdo, string $key, string $order): string
{
    try {
        if (function_exists('wo_cfg')) { $cfg = wo_cfg($pdo); if (!$cfg['on'] || empty($cfg['statuses']['received'])) return 'thank-you message is switched off'; }
        $c = function_exists('wa_contact') ? wa_contact($pdo, $key) : null; if (!$c || (int)($c['optout'] ?? 0) === 1) return '';
        $name = function_exists('wo_first_name') ? wo_first_name((string)($c['name'] ?? '')) : 'ji'; $ord = $order !== '' ? $order : 'with us';
        $body = function_exists('wo_live_body') ? wo_live_body($pdo, 'received') : (function_exists('wo_tpl_defs') ? (string)(wo_tpl_defs()['tmph_order_received']['body'] ?? '') : '');
        $withPhoto = function_exists('wo_live_has_photo') && wo_live_has_photo($pdo, 'received');   // a photo message must go as the template, not as plain text
        if ($body === '') $body = 'Hello {{1}}, thank you! We have received your order {{2}} at TM Perfume House. We will pack it with care and send you the tracking details as soon as it is shipped.';
        $mark = function () use ($pdo, $key) { $pdo->prepare("UPDATE wa_order_done SET thanks_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s'), $key]); };
        if (!$withPhoto && !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > time() - 23 * 3600 && function_exists('wa_send_text')) {
            [$ok] = wa_send_text($pdo, $c, str_replace(['{{1}}', '{{2}}'], [$name, $ord], $body), 'order', 0); if ($ok) { $mark(); return 'thank-you sent'; }
        }
        if (function_exists('wo_tpl_for') && function_exists('wa_send_tpl_one')) {
            $t = wo_tpl_for($pdo, 'received'); if (!$t) return 'thank-you template not approved yet';
            $ctx = wa_camp_ctx($pdo, $t, 99); [$nid] = wa_pick_number($pdo, $c, $ctx); if ($nid === 0) return 'no number free for the thank-you';
            $num = $ctx['nums'][$nid]; [$ok, $r2] = wa_send_tpl_one($pdo, $c, $t, $num, array_slice([$name, $ord], 0, (int)$t['nvars']), 'order'); if ($ok) { $mark(); $pw = (string)($GLOBALS['wo_photo_why'] ?? ''); return 'thank-you sent' . ($withPhoto && $pw !== '' ? ' WITHOUT the photo — ' . $pw : ''); } if (!$ok && $withPhoto) {   // the photo version could not go (photo upload / Meta refused): the plain approved text must still reach the customer
                $why2 = is_string($r2) ? $r2 : 'Meta refused it'; $bq = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = 'en'"); $bq->execute([wo_send_defs()['received'][0]]); $bid = (int)$bq->fetchColumn(); $bt = $bid ? wa_camp_tpl($pdo, $bid) : null;
                if ($bt) { [$ok2] = wa_send_tpl_one($pdo, $c, $bt, $num, array_slice([$name, $ord], 0, (int)$bt['nvars']), 'order'); if ($ok2) { $mark(); return 'thank-you sent WITHOUT the photo — the photo could not be sent: ' . $why2; } }
                return 'thank-you NOT sent: ' . $why2;
            }
        }
    } catch (Throwable $e) { error_log('wa_done_thanks: ' . $e->getMessage()); }
    return '';
}
function wa_done_state(PDO $pdo, string $key): array
{
    $r = wa_done_row($pdo, $key); if (!$r) return ['done' => false, 'muted' => false];
    $by = (int)$r['by_emp']; $nm = ''; if ($by > 0 && function_exists('employee_names_by_id')) { try { $nm = (string)(employee_names_by_id($pdo)[$by] ?? ''); } catch (Throwable $e) { } }
    return ['done' => true, 'muted' => strtotime((string)$r['mute_until']) > time(), 'at' => (string)$r['at'], 'until' => (string)$r['mute_until'], 'how' => (string)$r['how'], 'by' => $nm, 'order' => (string)$r['order_name'], 'thanks_at' => (string)($r['thanks_at'] ?? '')];
}
/** Shopify orders of the last hour from customers who wrote to us in the last 7 days → completed (every 2 minutes, from the engine tick). */
function wa_done_scan(PDO $pdo): int
{
    if (!wa_done_tables($pdo) || (int)setting_get($pdo, 'wa_done_scan_at', '0') > time() - 110) return 0; setting_set($pdo, 'wa_done_scan_at', (string)time()); $n = 0;
    try {
        $q = $pdo->prepare("SELECT phone, order_name, created_at FROM shop_orders WHERE created_at >= ? AND phone <> '' AND (financial_status IS NULL OR financial_status NOT IN ('refunded', 'voided')) ORDER BY created_at LIMIT 300"); $q->execute([date('Y-m-d H:i:s', time() - 3600)]);
        $chk = $pdo->prepare("SELECT 1 FROM wa_contacts WHERE phone = ? AND last_in_at >= ?"); $seen = $pdo->prepare("SELECT at FROM wa_order_done WHERE phone = ?");
        foreach ($q->fetchAll() as $o) {
            $k = substr(preg_replace('/\D+/', '', (string)$o['phone']), -10); if (strlen($k) !== 10) continue;
            $chk->execute([$k, date('Y-m-d H:i:s', time() - 7 * 86400)]); if (!$chk->fetchColumn()) continue;
            $seen->execute([$k]); $at = (string)$seen->fetchColumn(); if ($at !== '' && strtotime($at) >= strtotime((string)$o['created_at']) - 3600) continue;   // already marked for this order
            wa_done_mark($pdo, $k, 0, 'shopify', (string)$o['order_name']); $n++;
        }
    } catch (Throwable $e) { error_log('wa_done_scan: ' . $e->getMessage()); }
    return $n;
}
/** 🏆 "How orders were won": once a day, with 5+ new completed orders, Claude reads those chats and writes the lessons as a Teach report. */
function wa_done_learn(PDO $pdo): array
{
    if (!wa_done_tables($pdo) || (string)setting_get($pdo, 'wa_done_learn_day', '') === date('Y-m-d')) return ['skipped' => 'today done'];
    $rows = $pdo->query("SELECT phone, at, how, order_name FROM wa_order_done WHERE learned = 0 AND at >= '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "' ORDER BY at DESC LIMIT 10")->fetchAll();
    if (count($rows) < 5) return ['skipped' => 'fewer than 5 new orders']; setting_set($pdo, 'wa_done_learn_day', date('Y-m-d'));
    $chats = []; $m = $pdo->prepare("SELECT dir, who, body, ts FROM wa_messages WHERE phone = ? AND ts <= ? AND ts >= ? AND body <> '' ORDER BY id DESC LIMIT 30");
    foreach ($rows as $r) { $m->execute([$r['phone'], $r['at'], date('Y-m-d H:i:s', strtotime((string)$r['at']) - 4 * 86400)]); $L = array_reverse($m->fetchAll()); if (count($L) < 3) continue;
        $chats[] = "--- ORDER (" . ($r['how'] === 'shopify' ? 'Shopify' : 'marked by a person') . ")\n" . implode("\n", array_map(fn($x) => ($x['dir'] === 'in' ? 'Customer' : (in_array($x['who'], ['bot', 'auto', 'flow', 'followup'], true) ? 'Bot' : 'Salesperson')) . ': ' . mb_substr(preg_replace('/\s+/', ' ', (string)$x['body']), 0, 220), $L)); }
    if (count($chats) < 3) return ['skipped' => 'not enough chat text'];
    $sys = "You study WhatsApp chats of TM Perfume House (perfume shop, Surat) that ENDED IN AN ORDER. Find what made them buy: the moment they decided, which message or follow-up they answered, what the salesperson or bot said that worked, "
         . "how fast replies came, objections and how they were handled. Write 3 to 6 short, general lessons the bot and the team should follow (no customer names, no phone numbers). "
         . "Answer {\"lessons\":[\"...\"],\"best_example\":\"one short winning reply from these chats\",\"watch_out\":\"one thing that almost lost a sale\"}.";
    $j = null; if (function_exists('wcr_ai')) $j = wcr_ai($sys, implode("\n\n", $chats), 1500); elseif (function_exists('brain_ai_json')) { try { $j = brain_ai_json('internal', 'whatsapp', $sys, implode("\n\n", $chats), 1500, ['ctx' => false]); } catch (Throwable $e) { } }
    if (!is_array($j) || empty($j['lessons'])) return ['skipped' => 'Claude did not answer'];
    $note = '🏆 How the last ' . count($chats) . " orders were won (completed orders, read by Claude):\n\n- " . implode("\n- ", array_map('strval', array_slice((array)$j['lessons'], 0, 6)))
          . (!empty($j['best_example']) ? "\n\nWinning reply: " . trim((string)$j['best_example']) : '') . (!empty($j['watch_out']) ? "\nWatch out: " . trim((string)$j['watch_out']) : '')
          . "\n\nApprove = the bot learns these lessons (after you confirm). Dismiss = ignore.";
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS teach_reports (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL DEFAULT 0, by_name VARCHAR(100) NOT NULL DEFAULT '', phone VARCHAR(16) NOT NULL DEFAULT '', note TEXT NULL, chat TEXT NULL, images MEDIUMTEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'new', created_at DATETIME NULL, done_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO teach_reports (emp_id, by_name, phone, note, chat, images, status, created_at) VALUES (0, ?, '', ?, ?, NULL, 'new', NOW())")->execute(['🏆 Claude · how orders were won', mb_substr($note, 0, 4000), mb_substr(implode("\n\n", array_slice($chats, 0, 3)), 0, 4000)]);
        $in = implode(',', array_map(fn($r) => $pdo->quote((string)$r['phone']), $rows)); $pdo->exec("UPDATE wa_order_done SET learned = 1 WHERE phone IN ($in)"); } catch (Throwable $e) { return ['error' => $e->getMessage()]; }
    return ['ok' => true, 'orders' => count($chats)];
}

}   // function_exists guard

$__wdn = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wdn === 'wa.order_done') {
    $auth = require_auth(); if (!function_exists('wa_tables') || !wa_tables($pdo) || !wa_done_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $in = $isPost ? json_input() : $_GET; $key = substr(preg_replace('/\D+/', '', (string)($in['phone'] ?? '')), -10);
    $c = strlen($key) === 10 && function_exists('wa_contact') ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
    if (function_exists('wa_can_chat') && !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
    if (!$isPost) json_response(['ok' => true] + wa_done_state($pdo, $key));
    if (!empty($in['undo'])) { $pdo->prepare("UPDATE wa_order_done SET mute_until = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s'), $key]); if (function_exists('wa_event')) { try { wa_event($pdo, $key, '🔔 Automatic messages switched back on by ' . (string)($auth['username'] ?? 'a person')); } catch (Throwable $e) { } } json_response(['ok' => true] + wa_done_state($pdo, $key)); }
    json_response(['ok' => true] + wa_done_mark($pdo, $key, (int)($auth['employee_id'] ?? 0), 'person', trim(mb_substr((string)($in['order'] ?? ''), 0, 40))));
}
