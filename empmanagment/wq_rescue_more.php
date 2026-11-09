<?php
/**
 * wq_rescue_more.php — 🛟 Rescue, part 2. Loaded by wa_more.php right after wq_rescue.php. PHP 7.4 safe.
 *
 *   MESSAGES   12 ready "pre-made messages" (WhatsApp templates), one per situation, made as DRAFTS in HRMS (folder "Rescue"). The owner reads / edits them and presses
 *              "Send all to Meta"; Meta approves; a salesperson then sends one to a customer whose 24-hour chat window is closed. An approved message cannot be edited at Meta:
 *              saving a change makes a NEW version (the old one stays in use until the new one is approved).
 *   DRAFT      the AI writes ONE short message for a customer's chat (works inside the 24-hour window).
 *   PEOPLE     per person: chats taken, orders placed, not-a-chance, how fast the first message went out.
 *   ALERTS     push to the Rescue team when new "🔴 Act now" chats appear; an end-of-day summary to the owner.
 *   AI CHECK   every newly found 🔴 / 🟠 chat is read by the AI once; if the customer is clearly not a buyer (tracking, complaint, wrong number …) it is removed
 *              (shows under "Not a chance" with "removed by the AI").
 *
 *   wq.rescue_tpl_list    GET   the 12 messages: text, status at Meta, editable?                                  [admin]
 *   wq.rescue_tpl_save    POST  {topic, body}   change the text (a draft is changed, a sent one gets a new version)  [admin]
 *   wq.rescue_tpl_submit  POST  send every draft to Meta for approval                                              [admin]
 *   wq.rescue_templates   GET   {phone}  the approved messages a team member can send (+ the best one for this chat) [team / admin]
 *   wq.rescue_draft       POST  {phone}  the AI's draft message for this chat                                       [team / admin]
 *   wq.rescue_stats       GET   {days}  per-person table                                                          [admin]
 *   wq.rescue_start       GET   the "getting started" checklist with live ticks                                    [admin]
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wq_rescue_topics')) {

/** topic => [title, when it fits (why codes), text]. {{1}} is the customer's first name. No claims about prices, offers or delivery times the shop has not made. */
function wq_rescue_topics(): array
{
    return [
        'price'      => ['Asked the price', ['price'], "Hi {{1}}, thank you for asking about the price at TM Perfume House. 😊 Shall I send you the best price and the offer running today? Just reply YES and I will share it right away."],
        'pay'        => ['Wanted to pay / order', ['hot'], "Hi {{1}}, you were about to place an order with TM Perfume House. 🙏 Shall I send the payment details so we can confirm it for you today? Reply YES and I will share them."],
        'unanswered' => ['Was not answered', ['ignored'], "Hi {{1}}, sorry if you were waiting for a reply from TM Perfume House. 🙏 Our team is here to help you now. Please tell us which perfume you are looking for."],
        'choose'     => ['Needs help choosing', [], "Hi {{1}}, not sure which perfume to pick? 😊 Tell us who it is for and your budget, and we will suggest the best options from TM Perfume House."],
        'offer'      => ['Offer reminder', [], "Hi {{1}}, there is a special offer running on our perfumes at TM Perfume House. 🎁 Reply YES and I will tell you what is available for you."],
        'payment'    => ['Payment help', [], "Hi {{1}}, we can confirm your order at TM Perfume House with the payment option that is easiest for you. 🙏 Shall we go ahead? Reply YES and I will guide you."],
        'delivery'   => ['Delivery worry', [], "Hi {{1}}, once you confirm, we will dispatch your perfume from TM Perfume House as soon as possible. 🚚 Would you like us to place your order today?"],
        'combo'      => ['Combo suggestion', [], "Hi {{1}}, many of our customers like our perfume combos for better value. 😊 Would you like to see the combos that suit your choice? Reply YES and I will send them."],
        'repeat'     => ['Returning customer', [], "Hi {{1}}, thank you for shopping with TM Perfume House before. 🙏 We have new fragrances you may like. Reply YES and I will share the ones that match your taste."],
        'small'      => ['Wants to try first', [], "Hi {{1}}, want to try before buying a big bottle? 😊 Our smaller sizes are a good way to start. Reply YES and I will send you the options."],
        'address'    => ['Order almost ready', [], "Hi {{1}}, your order at TM Perfume House is almost ready. 📦 Please send your full delivery address and pincode so we can confirm it for you."],
        'lastcall'   => ['Last follow-up', [], "Hi {{1}}, this is our last follow-up about your enquiry at TM Perfume House. 🙏 If you still want the perfume, just reply YES and we will take care of the rest. Thank you!"],
    ];
}
/** The newest template row of one topic (or null). */
function wq_rescue_tpl_row(PDO $pdo, string $topic): ?array
{
    $q = $pdo->prepare("SELECT id FROM wa_templates WHERE purpose = ? ORDER BY id DESC LIMIT 1"); $q->execute(['rescue:' . $topic]); $id = (int)$q->fetchColumn();
    return $id ? wa_tpl_row($pdo, $id) : null;
}
function wq_rescue_tpl_ready(): bool { return function_exists('wa_tpl_row') && function_exists('wa_tpl_clean') && function_exists('wa_tpl_insert') && function_exists('wa_tpl_status') && function_exists('wa_wabas'); }
/** Make the drafts that do not exist yet (HRMS only — nothing goes to Meta). */
function wq_rescue_tpl_seed(PDO $pdo, int $by): int
{
    if (!wq_rescue_tpl_ready()) return 0; $n = 0;
    foreach (wq_rescue_topics() as $k => $t) {
        if (wq_rescue_tpl_row($pdo, $k)) continue;
        [$cl, $err] = wa_tpl_clean(['name' => 'tmph_rescue_' . $k, 'language' => 'en', 'category' => 'MARKETING', 'body' => $t[2], 'footer' => 'TM Perfume House', 'examples' => ['body' => ['Rahul']], 'folder' => 'Rescue'], true);
        if ($err !== '') { error_log('wq_rescue_tpl_seed ' . $k . ': ' . $err); continue; }
        $id = wa_tpl_insert($pdo, $cl, $by, 'rescue:' . $k, 'Rescue message: ' . $t[0]); if ($id) $n++;
    }
    return $n;
}
function wq_rescue_tpl_view(PDO $pdo): array
{
    $o = []; if (!wq_rescue_tpl_ready()) return $o; $wabas = wa_wabas($pdo);
    foreach (wq_rescue_topics() as $k => $t) {
        $r = wq_rescue_tpl_row($pdo, $k); if (!$r) { $o[] = ['topic' => $k, 'title' => $t[0], 'id' => 0, 'name' => '', 'body' => $t[2], 'status' => 'missing', 'reason' => '', 'editable' => true]; continue; }
        $st = wa_tpl_status($pdo, (int)$r['id'], $wabas);
        $o[] = ['topic' => $k, 'title' => $t[0], 'id' => (int)$r['id'], 'name' => (string)$r['name'], 'body' => (string)$r['body'], 'status' => (string)$st['status'], 'reason' => (string)$st['reason'], 'editable' => $st['status'] === 'missing'];
    }
    return $o;
}
/** The approved, sendable rescue messages (newest approved version of each topic). */
function wq_rescue_tpl_sendable(PDO $pdo): array
{
    $o = []; if (!wq_rescue_tpl_ready()) return $o; $wabas = wa_wabas($pdo);
    foreach (wq_rescue_topics() as $k => $t) {
        $q = $pdo->prepare("SELECT id FROM wa_templates WHERE purpose = ? ORDER BY id DESC LIMIT 6"); $q->execute(['rescue:' . $k]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { $r = wa_tpl_row($pdo, (int)$id); if (!$r) continue; $st = wa_tpl_status($pdo, (int)$id, $wabas);
            if (in_array($st['status'], ['approved', 'partial'], true) && wa_tpl_sendable($r, $pdo)) { $o[$k] = ['topic' => $k, 'title' => $t[0], 'id' => (int)$r['id'], 'body' => (string)$r['body'], 'why' => $t[1]]; break; } }
    }
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------ AI
function wq_rescue_chat_text(PDO $pdo, string $phone, int $n = 30): string
{
    $q = $pdo->prepare("SELECT dir, who, body FROM wa_messages WHERE phone = ? AND dir IN ('in','out') ORDER BY id DESC LIMIT " . (int)$n); $q->execute([$phone]); $lines = [];
    foreach (array_reverse($q->fetchAll()) as $m) { $w = $m['dir'] === 'in' ? 'Customer' : (in_array((string)$m['who'], ['emp', 'person'], true) ? 'Salesperson' : 'Bot'); $lines[] = $w . ': ' . mb_substr(trim((string)$m['body']), 0, 220); }
    return implode("\n", $lines);
}
/** One short message for this chat, written by the AI. [text, error]. */
function wq_rescue_ai_draft(PDO $pdo, string $phone): array
{
    if (!function_exists('wq_ai')) return ['', 'The AI is not available.'];
    $name = ''; try { $q = $pdo->prepare("SELECT name FROM wa_contacts WHERE phone = ?"); $q->execute([$phone]); $name = trim((string)$q->fetchColumn()); } catch (Throwable $e) { }
    $sch = ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING']], 'required' => ['text']];
    [$ok, $v] = wq_ai($pdo, "You write WhatsApp messages for TM Perfume House, an Indian perfume shop. Write ONE short follow-up message (2 to 3 sentences) to this customer, in the same language and style the customer used (Hinglish / English / Hindi / Gujarati in Latin script). Be warm, never pushy, mention what they asked about, do not invent prices, discounts or stock, and end with ONE clear question that makes it easy to answer. No emojis except at most one.", "Customer name: " . ($name !== '' ? $name : 'unknown') . "\nThe chat, oldest first:\n" . wq_rescue_chat_text($pdo, $phone), $sch);
    if (!$ok || !is_array($v) || trim((string)($v['text'] ?? '')) === '') return ['', 'The AI could not write a message now. Try again in a minute.'];
    return [mb_substr(trim((string)$v['text']), 0, 600), ''];
}
/** Every newly found 🔴 / 🟠 chat is read once: clearly not a buyer → removed (visible under Not a chance, "removed by the AI"). Max $max per call. */
function wq_rescue_ai_verify(PDO $pdo, float $deadline, int $max = 5): array
{
    $o = ['checked' => 0, 'removed' => 0]; if (!function_exists('wq_ai')) return $o;
    try {
        $rows = $pdo->query("SELECT id, phone FROM wq_rescue WHERE status = 'open' AND ai_state = '' AND tier IN ('act','today') ORDER BY last_in_at DESC LIMIT " . (int)$max)->fetchAll();
        $sch = ['type' => 'OBJECT', 'properties' => ['buyer' => ['type' => 'BOOLEAN'], 'reason' => ['type' => 'STRING']], 'required' => ['buyer', 'reason']];
        $ok1 = $pdo->prepare("UPDATE wq_rescue SET ai_state = 'ok' WHERE id = ? AND status = 'open'");
        $rm = $pdo->prepare("UPDATE wq_rescue SET status = 'skipped', skipped_by = 0, ai_state = 'auto', ai_note = ?, closed_at = ? WHERE id = ? AND status = 'open'");
        foreach ($rows as $r) {
            if (microtime(true) > $deadline) break; $o['checked']++;
            [$ok, $v] = wq_ai($pdo, "You check leads for a perfume shop's WhatsApp. Decide whether this customer could realistically still BUY a perfume (even if hesitant, or only asked the price). Answer buyer = false ONLY if it is clear they are not a prospect: asking about order status / delivery / tracking of an order they already placed, a complaint, return or refund, a wrong number, spam, a reseller asking for something else, or they clearly said they are not interested or already bought. Give ONE short reason in plain English.", wq_rescue_chat_text($pdo, (string)$r['phone']), $sch);
            if (!$ok || !is_array($v)) { $ok1->execute([(int)$r['id']]); continue; }   // the AI is off / failed: leave it on the list
            if (empty($v['buyer'])) { $rm->execute([mb_substr(trim((string)($v['reason'] ?? '')), 0, 230), date('Y-m-d H:i:s'), (int)$r['id']]); $o['removed'] += $rm->rowCount(); } else $ok1->execute([(int)$r['id']]);
        }
    } catch (Throwable $e) { error_log('wq_rescue_ai_verify: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------ alerts
/** Push to the team when new 🔴 Act now chats appeared (at most every 10 minutes); the owner's end-of-day summary (once a day, after 21:00). */
function wq_rescue_alerts_tick(PDO $pdo, float $deadline): array
{
    $o = ['ai' => wq_rescue_ai_verify($pdo, $deadline), 'pushed' => 0, 'summary' => false]; if (!function_exists('ct_push') || !function_exists('wq_meta_get')) return $o;
    try {
        $last = (int)wq_meta_get($pdo, 'rescue_alert_id', '0'); $q = $pdo->prepare("SELECT COUNT(*), COALESCE(MAX(id),0) FROM wq_rescue WHERE status = 'open' AND tier = 'act' AND id > ?"); $q->execute([$last]); [$n, $mx] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
        if ($n > 0 && strtotime(wq_meta_get($pdo, 'rescue_alert_at', '2000-01-01')) < time() - 600) {
            $ids = wq_rescue_team_ids($pdo); if ($ids) { ct_push($pdo, $ids, '🛟 Rescue — act now', $n . ' customer' . ($n === 1 ? '' : 's') . ' need a follow-up now. Open Chats → 🛟 Rescue.', '/my_chats.html', 'wq-rescue-act'); $o['pushed'] = count($ids); }
            wq_meta_put($pdo, 'rescue_alert_id', (string)$mx); wq_meta_put($pdo, 'rescue_alert_at', date('Y-m-d H:i:s'));
        } elseif ($n === 0 && $mx === 0) { /* nothing new */ }
        $today = date('Y-m-d');
        if ((int)date('G') >= 21 && wq_meta_get($pdo, 'rescue_sum_day', '') !== $today && function_exists('wq_admin_ids')) {
            $d0 = $today . ' 00:00:00'; $c = function (string $sql) use ($pdo, $d0): int { $s = $pdo->prepare($sql); $s->execute([$d0]); return (int)$s->fetchColumn(); };
            $found = $c("SELECT COUNT(*) FROM wq_rescue WHERE flagged_at >= ?"); $took = $c("SELECT COUNT(*) FROM wq_rescue WHERE taken_at >= ?"); $won = $c("SELECT COUNT(*) FROM wq_rescue WHERE won_at >= ?"); $no = $c("SELECT COUNT(*) FROM wq_rescue WHERE status = 'skipped' AND closed_at >= ?");
            $open = (int)$pdo->query("SELECT COUNT(*) FROM wq_rescue WHERE status = 'open'")->fetchColumn();
            ct_push($pdo, wq_admin_ids($pdo), '🛟 Rescue — today', "Found $found · taken $took · orders placed $won · not a chance $no · still waiting $open.", '/whatsapp.html#rescue', 'wq-rescue-day');
            wq_meta_put($pdo, 'rescue_sum_day', $today); $o['summary'] = true;
        }
    } catch (Throwable $e) { error_log('wq_rescue_alerts_tick: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------ people table + getting started
function wq_rescue_stats(PDO $pdo, int $days): array
{
    $names = function_exists('wq_names') ? wq_names($pdo) : []; $since = date('Y-m-d H:i:s', time() - max(1, $days) * 86400); $by = [];
    $get = function (int $e) use (&$by, $names) { if (!isset($by[$e])) $by[$e] = ['emp_id' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'taken' => 0, 'won' => 0, 'not_a_chance' => 0, 'returned' => 0, 'mins' => []]; return $by[$e]; };
    try {
        $fm = $pdo->prepare("SELECT MIN(ts) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('emp','person') AND ts >= ?");
        $q = $pdo->prepare("SELECT phone, taken_by, taken_at, status, returns FROM wq_rescue WHERE taken_by > 0 AND taken_at >= ? LIMIT 3000"); $q->execute([$since]);
        foreach ($q->fetchAll() as $r) { $e = (int)$r['taken_by']; $x = $get($e); $x['taken']++; if ($r['status'] === 'won') $x['won']++; $x['returned'] += (int)$r['returns'];
            $fm->execute([(string)$r['phone'], (string)$r['taken_at']]); $t = (string)$fm->fetchColumn(); if ($t !== '') $x['mins'][] = max(0, (strtotime($t) - strtotime((string)$r['taken_at'])) / 60); $by[$e] = $x; }
        $q = $pdo->prepare("SELECT skipped_by, COUNT(*) n FROM wq_rescue WHERE status = 'skipped' AND skipped_by > 0 AND closed_at >= ? GROUP BY skipped_by"); $q->execute([$since]);
        foreach ($q->fetchAll() as $r) { $x = $get((int)$r['skipped_by']); $x['not_a_chance'] = (int)$r['n']; $by[(int)$r['skipped_by']] = $x; }
    } catch (Throwable $e) { error_log('wq_rescue_stats: ' . $e->getMessage()); }
    $rows = []; foreach ($by as $x) { $m = $x['mins']; sort($m); $x['median_min'] = $m ? round($m[intdiv(count($m), 2)], 1) : null; unset($x['mins']); $x['win_pct'] = $x['taken'] > 0 ? round(100 * $x['won'] / $x['taken']) : null; $rows[] = $x; }
    usort($rows, fn($a, $b) => [$b['won'], $b['taken']] <=> [$a['won'], $a['taken']]);
    return ['ok' => true, 'days' => $days, 'people' => $rows];
}
function wq_rescue_start(PDO $pdo): array
{
    $team = count(wq_rescue_team_ids($pdo)); $tv = wq_rescue_tpl_view($pdo); $drafts = 0; $pend = 0; $appr = 0;
    foreach ($tv as $t) { if ($t['status'] === 'missing') $drafts++; elseif (in_array($t['status'], ['approved', 'partial'], true)) $appr++; elseif ($t['status'] === 'pending') $pend++; }
    $bf = json_decode(wq_meta_get($pdo, 'rescue_bf', 'null'), true); $ls = json_decode(wq_meta_get($pdo, 'rescue_last', 'null'), true);
    $c = fn(string $w) => (int)$pdo->query("SELECT COUNT(*) FROM wq_rescue WHERE $w")->fetchColumn();
    return ['ok' => true, 'steps' => [
        ['k' => 'team', 'done' => $team >= 1, 'text' => $team >= 1 ? $team . ' person' . ($team === 1 ? '' : 's') . ' on the Rescue team' : 'Add 2 or 3 people to the Rescue team (start small)'],
        ['k' => 'scan', 'done' => (bool)$ls, 'text' => $ls ? 'The check of recent queries has run' : 'Open WhatsApp once so the first check runs'],
        ['k' => 'bf', 'done' => is_array($bf) && !empty($bf['done']), 'text' => is_array($bf) ? (!empty($bf['done']) ? 'All earlier chats are checked' : 'Loading earlier chats… ' . (int)($bf['pct'] ?? 0) . '%') : 'Earlier chats start loading with the first check'],
        ['k' => 'tpl_sent', 'done' => $drafts === 0, 'text' => $drafts === 0 ? 'The 12 messages were sent to Meta' : $drafts . ' messages are still drafts — read them in the Messages tab, then press "Send all to Meta"'],
        ['k' => 'tpl_ok', 'done' => $appr >= 1, 'text' => $appr >= 1 ? $appr . ' messages approved by Meta' : ($pend ? $pend . ' messages are waiting for Meta (usually minutes to a day)' : 'No message is approved yet')],
        ['k' => 'taken', 'done' => $c("taken_by > 0") >= 1, 'text' => $c("taken_by > 0") >= 1 ? 'A rescue chat has been taken' : 'Ask the team to take their first rescue chat'],
        ['k' => 'won', 'done' => $c("status = 'won'") >= 1, 'text' => $c("status = 'won'") >= 1 ? 'The first order from a rescued chat is in ✅' : 'Waiting for the first order from a rescued chat'],
    ]];
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router
$__wqm = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wqm, 'wq.rescue_') === 0 && in_array($__wqm, ['wq.rescue_tpl_list', 'wq.rescue_tpl_save', 'wq.rescue_tpl_submit', 'wq.rescue_templates', 'wq.rescue_draft', 'wq.rescue_stats', 'wq.rescue_start'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available - check database permissions.'], 500);
    if (function_exists('wq_deps')) wq_deps($pdo); if (!wq_rescue_tables($pdo)) json_response(['error' => 'Rescue storage is not available.'], 500);
    $in = $isPost ? json_input() : $_GET; $adm = wa_is_admin($pdo, $auth);
    $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needGet = function () use ($isPost) { if ($isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needAdmin = function () use ($adm) { if (!$adm) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403); };
    $needTeam = function () use ($pdo, $adm, $me) { if (!$adm && !wq_rescue_is_member($pdo, $me)) json_response(['error' => 'You are not on the rescue team.'], 403); };
    try { switch ($__wqm) {
        case 'wq.rescue_tpl_list': {
            $needGet(); $needAdmin(); if (!wq_rescue_tpl_ready()) json_response(['error' => 'The pre-made messages module is not loaded.'], 500);
            $made = wq_rescue_tpl_seed($pdo, $me); json_response(['ok' => true, 'made' => $made, 'items' => wq_rescue_tpl_view($pdo)]);
        }
        case 'wq.rescue_tpl_save': {
            $needPost(); $needAdmin(); if (!wq_rescue_tpl_ready()) json_response(['error' => 'The pre-made messages module is not loaded.'], 500);
            $topic = (string)($in['topic'] ?? ''); $T = wq_rescue_topics(); if (!isset($T[$topic])) json_response(['error' => 'Unknown message.'], 404); $body = trim((string)($in['body'] ?? ''));
            wq_rescue_tpl_seed($pdo, $me); $r = wq_rescue_tpl_row($pdo, $topic); if (!$r) json_response(['error' => 'The message could not be created.'], 500);
            $d = ['name' => (string)$r['name'], 'language' => (string)$r['language'], 'category' => (string)$r['category'], 'body' => $body, 'footer' => (string)$r['footer'], 'examples' => $r['examples'], 'folder' => 'Rescue'];
            [$cl, $err] = wa_tpl_clean($d, true); if ($err !== '') json_response(['error' => $err], 422);
            $st = wa_tpl_status($pdo, (int)$r['id'], wa_wabas($pdo));
            if ($st['status'] === 'missing') {   // still a draft: just change it
                $pdo->prepare("UPDATE wa_templates SET body = ?, examples = ? WHERE id = ?")->execute([$cl['body'], json_encode($cl['examples'], JSON_UNESCAPED_UNICODE), (int)$r['id']]); json_response(['ok' => true, 'version' => 'same']);
            }
            // already at Meta: a new version (the old one keeps working until the new one is approved)
            $v = 2; if (preg_match('/_v(\d+)$/', (string)$r['name'], $m)) $v = (int)$m[1] + 1; $d['name'] = 'tmph_rescue_' . $topic . '_v' . $v; [$cl2, $err2] = wa_tpl_clean($d, true); if ($err2 !== '') json_response(['error' => $err2], 422);
            $nid = wa_tpl_insert($pdo, $cl2, $me, 'rescue:' . $topic, 'Rescue message: ' . $T[$topic][0] . ' (version ' . $v . ')'); json_response(['ok' => true, 'version' => 'new', 'id' => $nid]);
        }
        case 'wq.rescue_tpl_submit': {   // "Send all to Meta": every rescue draft goes to every WhatsApp account for approval
            $needPost(); $needAdmin(); if (!function_exists('wa_tpl_try_create')) json_response(['error' => 'The pre-made messages module is not loaded.'], 500);
            ignore_user_abort(true); wq_rescue_tpl_seed($pdo, $me); $wabas = wa_wabas($pdo); if (!$wabas) json_response(['error' => 'No WhatsApp account is connected.'], 422);
            $sent = 0; $fails = []; $bud = ['left' => 60];
            foreach (wq_rescue_topics() as $k => $t) {
                $r = wq_rescue_tpl_row($pdo, $k); if (!$r) continue; $st = wa_tpl_status($pdo, (int)$r['id'], $wabas); if ($st['status'] !== 'missing') continue;
                [$cl, $err] = wa_tpl_clean($r, true); if ($err !== '') { $fails[] = $t[0] . ': ' . $err; continue; }
                foreach ($wabas as $w) { [$ok, $why] = wa_tpl_try_create($pdo, $r, $w, $bud); if ($ok) $sent++; else $fails[] = $t[0] . ': ' . $why; }
            }
            json_response(['ok' => !$fails, 'sent' => $sent, 'errors' => $fails, 'items' => wq_rescue_tpl_view($pdo)]);
        }
        case 'wq.rescue_templates': {
            $needGet(); $needTeam(); $k = preg_replace('/\D+/', '', (string)($in['phone'] ?? '')); $k = strlen($k) >= 10 ? substr($k, -10) : ''; $list = wq_rescue_tpl_sendable($pdo); $best = '';
            if ($k !== '') { try { $q = $pdo->prepare("SELECT why FROM wq_rescue WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q->execute([$k]); $why = array_filter(explode(',', (string)$q->fetchColumn()));
                foreach (wq_rescue_topics() as $tk => $t) if (isset($list[$tk]) && array_intersect($t[1], $why)) { $best = $tk; break; } } catch (Throwable $e) { } }
            $nm = ''; if ($k !== '') { try { $q = $pdo->prepare("SELECT name FROM wa_contacts WHERE phone = ?"); $q->execute([$k]); $nm = trim((string)$q->fetchColumn()); } catch (Throwable $e) { } }
            $first = $nm !== '' ? preg_split('/\s+/u', $nm)[0] : 'there';
            json_response(['ok' => true, 'name' => $first, 'best' => $best, 'items' => array_values(array_map(fn($x) => $x + ['preview' => str_replace('{{1}}', $first, $x['body'])], $list))]);
        }
        case 'wq.rescue_draft': {
            $needPost(); $needTeam(); $k = preg_replace('/\D+/', '', (string)($in['phone'] ?? '')); $k = strlen($k) >= 10 ? substr($k, -10) : ''; if ($k === '') json_response(['error' => 'Chat not found.'], 404);
            [$txt, $err] = wq_rescue_ai_draft($pdo, $k); if ($err !== '') json_response(['error' => $err], 503); json_response(['ok' => true, 'text' => $txt]);
        }
        case 'wq.rescue_stats': { $needGet(); $needAdmin(); json_response(wq_rescue_stats($pdo, max(1, min(90, (int)($in['days'] ?? 14))))); }
        case 'wq.rescue_start': { $needGet(); $needAdmin(); wq_rescue_tpl_seed($pdo, $me); json_response(wq_rescue_start($pdo)); }
    } } catch (Throwable $e) { error_log('wq_rescue_more: ' . $e->getMessage()); json_response(['error' => 'Rescue: ' . mb_substr($e->getMessage(), 0, 240)], 500); }
}
