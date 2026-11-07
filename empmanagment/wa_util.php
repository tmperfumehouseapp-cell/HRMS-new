<?php
/**
 * wa_util.php — "✨ Make utility versions" for a campaign message.
 *
 *   wa.tpl_make_utility POST {tpl_id, n (1-15)}  → Claude writes n UTILITY messages that fit the marketing message's topic, but with NO offer:
 *                                                   a follow-up on what the customer asked / ordered. They use the variables HRMS fills by itself at send time
 *                                                   (wa_mix_u_vars): {{1}} = first name · {{2}} = the perfume they asked about or ordered · {{3}} = the date.
 *                                                   Each is saved as a new message (same family as the marketing one) and submitted to every WhatsApp account
 *                                                   through wa_tpl_try_create (Meta's hourly / daily template limits respected; the rest is queued and sent later).
 *   wa.tpl_utility_list GET {tpl_id}             → the utility messages of that family + any approved utility message, with their status.
 *
 * Meta's rule: utility = something the customer did (asked, ordered). The campaign therefore sends a utility version only to people who asked about a perfume
 * or ordered in the last 30 days (wa_mix_cheap_ok); everyone else gets the marketing message. Loaded by wa_more.php (before wa_seg.php). PHP 7.4 safe. Admin only.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wa_util_make')) {

/** Words that make Meta call a message marketing — a utility version must not contain them. */
function wa_util_bad_words(): array { return ['offer', 'discount', 'sale', '% off', 'off on', 'free', 'deal', 'limited time', 'hurry', 'last chance', 'buy now', 'shop now', 'coupon', 'promo', 'diwali offer', 'festive offer', 'save ', 'cashback', 'combo offer', 'best price', 'lowest price']; }

/** Why a utility body cannot be used ('' = fine): Meta's marketing words, or the variables are not right. */
function wa_util_check(string $body): string
{
    $body = trim($body); if ($body === '') return 'The message is empty.';
    $low = mb_strtolower($body); $bad = array_values(array_filter(wa_util_bad_words(), fn($w) => mb_strpos($low, $w) !== false));
    if ($bad) return 'Meta would call it marketing because of: ' . implode(', ', $bad) . '.';
    preg_match_all('/\{\{(\d+)\}\}/', $body, $vm); $vars = array_map('intval', $vm[1]);
    if (!in_array(1, $vars, true) || !in_array(2, $vars, true)) return 'It must use {{1}} (customer name) and {{2}} (the perfume).';
    if (max($vars ?: [0]) > 3) return 'Only {{1}} name, {{2}} perfume and {{3}} date can be used.';
    if (preg_match('/^\s*\{\{|\{\{\d\}\}\s*$/', $body) || preg_match('/\}\}\s*\{\{/', $body)) return 'A variable cannot be at the very start or end, or next to another variable.';
    return '';
}
/** The owner's edits of utility versions (before → after): Claude follows them next time. */
function wa_util_lessons(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wa_util_lessons', ''), true); return is_array($j) ? $j : []; }
function wa_util_lesson_add(PDO $pdo, string $before, string $after): void
{
    if (trim($before) === trim($after)) return; $l = wa_util_lessons($pdo); $l[] = ['before' => mb_substr($before, 0, 700), 'after' => mb_substr($after, 0, 700), 'at' => date('Y-m-d H:i:s')];
    setting_set($pdo, 'wa_util_lessons', json_encode(array_slice($l, -12), JSON_UNESCAPED_UNICODE));
}
/** Send one utility version to Meta on every WhatsApp account (the owner pressed "Send to Meta"). */
function wa_util_submit(PDO $pdo, int $id): array
{
    $row = wa_tpl_row($pdo, $id); if (!$row || strtoupper((string)$row['category']) !== 'UTILITY') return ['error' => 'Utility version not found.'];
    $wabas = function_exists('wa_wabas') ? array_keys(wa_wabas($pdo)) : []; if (!$wabas) return ['error' => 'No WhatsApp account is set up.'];
    $budget = ['left' => count($wabas)]; $subs = [];
    foreach ($wabas as $wb) { [$ok, $note] = function_exists('wa_tpl_try_create') ? wa_tpl_try_create($pdo, $row, (string)$wb, $budget) : wa_tpl_create_at($pdo, $row, (string)$wb); $subs[] = ['waba' => (string)$wb, 'ok' => (bool)$ok, 'note' => is_string($note) ? $note : '']; }
    return ['ok' => true, 'submitted' => $subs];
}
/** ✨ Claude writes utility versions FROM the owner's marketing message: same greeting, same words and style (language, emojis), only what Meta forbids in utility is taken out.
 *  They are kept as drafts — nothing goes to Meta until the owner presses "Send to Meta" (or $send is true). */
function wa_util_make(PDO $pdo, int $tplId, int $n, int $by, bool $send = false): array
{
    $m = function_exists('wa_tpl_row') ? wa_tpl_row($pdo, $tplId) : null; if (!$m) return ['error' => 'Message not found.'];
    $n = max(1, min(6, $n)); $lang = (string)$m['language'];
    $les = ''; foreach (array_slice(wa_util_lessons($pdo), -6) as $l) $les .= "BEFORE (Claude wrote): " . $l['before'] . "\nAFTER (the owner changed it to): " . $l['after'] . "\n\n";
    $sys = "You turn the owner's WhatsApp MARKETING message of TM Perfume House (perfume shop, Surat, India) into UTILITY versions that Meta will approve as UTILITY. "
         . "KEEP the owner's own greeting, wording, sentence style, emojis and language as much as possible — it must read like the owner wrote it, not like a new message. "
         . "Change only what Meta does not allow in utility: take out prices as a deal, discounts, offers, sale, 'free', urgency ('hurry', 'last chance', 'limited'), 'buy now / shop now', coupons, cashback. "
         . "A utility message follows up on something THE CUSTOMER did (asked about a perfume or ordered one), so tie the message to that: e.g. their enquiry about {{2}} or their order of {{2}}. "
         . "Use only these variables: {{1}} = the customer's first name, {{2}} = the perfume they asked about or ordered, {{3}} = the date (optional). Every message uses {{1}} and {{2}}; a variable never starts or ends the message and is never next to another variable. "
         . "Language: " . ($lang === 'en' ? 'English, like the owner\'s message' : 'the language code ' . $lang) . ". Keep about the same length as the owner's message (max 550 characters), end with one simple question. "
         . "Make the " . $n . " versions close to the owner's message, each with a small difference. name = lowercase_with_underscores, max 40 letters, starting with util_. "
         . ($les !== '' ? "The owner corrected earlier versions — follow how the owner writes:\n" . $les : '')
         . "Answer {\"messages\":[{\"name\":\"util_...\",\"body\":\"...\"}]}.";
    $user = "The owner's marketing message:\n" . (string)$m['body'];
    $j = function_exists('brain_ai_json') ? brain_ai_json('internal', 'whatsapp', $sys, $user, 3000, ['ctx' => false]) : null;
    if (!is_array($j) || empty($j['messages'])) return ['error' => 'Claude could not write the utility versions right now — try again in a minute.'];
    $made = []; $skipped = [];
    foreach (array_slice((array)$j['messages'], 0, $n) as $x) {
        $body = trim((string)($x['body'] ?? '')); $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($x['name'] ?? ''))) ?: 'util_follow_up';
        if (strpos($name, 'util_') !== 0) $name = 'util_' . $name; $name = substr($name, 0, 48);
        $why = wa_util_check($body); if ($why !== '') { $skipped[] = ['name' => $name, 'why' => $why]; continue; }
        $id = wa_util_insert($pdo, $m, $name, $body, $by); $row = wa_tpl_row($pdo, $id);
        $made[] = ['id' => $id, 'name' => (string)$row['name'], 'body' => $body, 'submitted' => $send ? (wa_util_submit($pdo, $id)['submitted'] ?? []) : []];
    }
    return ['ok' => true, 'made' => $made, 'skipped' => $skipped, 'drafts' => !$send];
}
function wa_util_insert(PDO $pdo, array $m, string $name, string $body, int $by): int
{
    preg_match_all('/\{\{(\d+)\}\}/', $body, $vm); $nv = max(array_map('intval', $vm[1]) ?: [2]); $labels = array_slice(['name', 'perfume', 'date'], 0, $nv); $ex = array_slice(['Rakesh', 'Dior Sauvage 50 ml', date('j M')], 0, $nv);
    $t = ['name' => $name, 'language' => (string)$m['language'], 'category' => 'UTILITY', 'header_format' => '', 'header_text' => '', 'header_lib_id' => 0, 'body' => $body, 'footer' => '', 'buttons' => [], 'lto' => null,
          'examples' => ['body' => $ex, 'labels' => $labels], 'extra' => ['utility_for' => (int)$m['id']], 'folder' => (string)($m['folder'] ?? ''), 'family_id' => (int)($m['family_id'] ?: $m['id'])];
    return wa_tpl_insert($pdo, $t, $by, 'Utility version of "' . $m['name'] . '" — sent only to people who asked or ordered in the last 30 days', 'Utility follow-up, no offer');
}

/** Every number with its WhatsApp account, for the per-number status chips. */
function wa_util_numbers(PDO $pdo): array
{
    static $o = null; if ($o !== null) return $o; $o = [];
    foreach ($pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll() as $n) $o[] = ['id' => (int)$n['id'], 'waba' => function_exists('wa_waba_of') ? (string)wa_waba_of($n) : '', 'label' => (string)($n['label'] ?: ($n['display'] ?? '') ?: 'Number ' . $n['id'])];
    return $o;
}
/** The approved utility versions of one marketing message's family that a campaign may send (not re-labelled by Meta), newest first. Owner's rule: a version Meta moved to marketing is NOT rewritten — it is just not used. */
function wa_util_usable(PDO $pdo, int $tplId): array
{
    $m = function_exists('wa_tpl_row') ? wa_tpl_row($pdo, $tplId) : null; if (!$m) return []; $fam = (int)($m['family_id'] ?: $m['id']); $o = [];
    $q = $pdo->prepare("SELECT id FROM wa_templates WHERE category = 'UTILITY' AND family_id = ? AND relabelled_at IS NULL AND (category_now IS NULL OR category_now = '' OR category_now <> 'MARKETING') ORDER BY id DESC LIMIT 15");
    try { $q->execute([$fam]); } catch (Throwable $e) { $q = $pdo->prepare("SELECT id FROM wa_templates WHERE category = 'UTILITY' AND family_id = ? AND relabelled_at IS NULL ORDER BY id DESC LIMIT 15"); $q->execute([$fam]); }
    foreach ($q->fetchAll() as $r) { $t = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, (int)$r['id'], true, 0) : null; if ($t && !empty($t['ok_wabas'])) { if (!is_array($t['examples'] ?? null)) { $rr = wa_tpl_row($pdo, (int)$r['id']); $t['examples'] = is_array($rr['examples'] ?? null) ? $rr['examples'] : (json_decode((string)($rr['examples'] ?? ''), true) ?: []); } $o[] = $t; } }
    return $o;
}
/** 3 utility versions are made by themselves for every NEW marketing message (sent to Meta in the last 14 days, or used by a campaign in the last 14 days) — one message per call
 *  (each is one Claude call). Marked util_auto so it never repeats. Called from the template auto-submit tick. */
function wa_util_auto(PDO $pdo): int
{
    return 0;   // owner's rule (Nov 2026): utility versions are made only when the owner asks, and go to Meta only after the owner approves them
    if ((int)strtotime((string)setting_get($pdo, 'wa_util_auto_at', '')) > time() - 300) return 0;
    $since = date('Y-m-d H:i:s', time() - 14 * 86400);
    $q = $pdo->prepare("SELECT t.id, t.extra, t.family_id FROM wa_templates t WHERE t.category = 'MARKETING' AND (t.extra IS NULL OR t.extra NOT LIKE '%util_auto%') AND (t.extra IS NULL OR t.extra NOT LIKE '%\"utility_of\"%')
        AND (EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND w.status <> 'REJECTED' AND w.updated_at >= ?) OR EXISTS (SELECT 1 FROM wa_campaigns c WHERE c.tpl_id = t.id AND c.created_at >= ?)) ORDER BY t.id DESC LIMIT 1");
    try { $q->execute([$since, $since]); $r = $q->fetch(); } catch (Throwable $e) { try { $q = $pdo->prepare("SELECT t.id, t.extra, t.family_id FROM wa_templates t WHERE t.category = 'MARKETING' AND (t.extra IS NULL OR t.extra NOT LIKE '%util_auto%') AND EXISTS (SELECT 1 FROM wa_campaigns c WHERE c.tpl_id = t.id AND c.created_at >= ?) ORDER BY t.id DESC LIMIT 1"); $q->execute([$since]); $r = $q->fetch(); } catch (Throwable $e2) { return 0; } }
    if (!$r) return 0; setting_set($pdo, 'wa_util_auto_at', date('Y-m-d H:i:s'));
    $fam = (int)($r['family_id'] ?: $r['id']); $c = $pdo->prepare("SELECT COUNT(*) FROM wa_templates WHERE category = 'UTILITY' AND family_id = ?"); $c->execute([$fam]); $have = (int)$c->fetchColumn();
    $res = $have >= 3 ? ['made' => []] : wa_util_make($pdo, (int)$r['id'], 3 - $have, 0);
    if (function_exists('wa_mix_extra_set')) wa_mix_extra_set($pdo, (int)$r['id'], ['util_auto' => date('Y-m-d H:i:s') . (isset($res['error']) ? ' error' : '')]);
    if (function_exists('wa_log')) wa_log($pdo, 'template', !isset($res['error']), 'Utility versions for message #' . $r['id'] . ': ' . (isset($res['error']) ? $res['error'] : count($res['made'] ?? []) . ' made'));
    return count($res['made'] ?? []);
}
// ---------------------------------------------------------------------------------------------------------------- 💸 the UTILITY FOLLOW-UP campaign
/** The 6 standard utility follow-ups (owner's "utility campaign"): 4 about an enquiry the customer made on WhatsApp (last 30 days), 2 about an order they placed
 *  (inside the window the owner picks). No offers, no prices — each states true facts about THAT customer. Any reply opens the free 24-hour chat. */
function wa_util_pool_defs(): array
{
    $qr = fn(array $l) => array_map(fn($t) => ['type' => 'QUICK_REPLY', 'text' => $t], $l);
    return [
        'ask_followup' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['Yes, I need it', 'Not now']),
            'body' => "Hi {{1}}, you asked us about {{2}} on {{3}}. Do you still need it? Reply here and we will help you complete it."],
        'ask_stock' => ['kind' => 'ask', 'labels' => ['name', 'perfume'], 'ex' => ['Rahul', 'Dior Sauvage'], 'buttons' => $qr(['Yes, keep it', 'Show options']),
            'body' => "Hello {{1}}, an update on your enquiry about {{2}}: it is available now. Would you like us to keep it for you?"],
        'ask_size' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['Share the sizes', 'Not now']),
            'body' => "Hi {{1}}, about your enquiry for {{2}} on {{3}}: please tell us the size you need, so we can confirm it for you."],
        'ask_pincode' => ['kind' => 'ask', 'labels' => ['name', 'perfume'], 'ex' => ['Rahul', 'Dior Sauvage'], 'buttons' => $qr(['Send my pincode', 'Not now']),
            'body' => "Hi {{1}}, to check the delivery time for {{2}} to your area, please reply with your pincode."],
        'order_check' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Yes, all good', 'Need help']),
            'body' => "Hi {{1}}, did your order {{2}} placed on {{3}} reach you in good condition? Reply here if you need any help with it."],
        'order_care' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Ask a question', 'All good']),
            'body' => "Hello {{1}}, this is about your order {{2}} from {{3}}. If you have any question about using or storing your perfume, just reply here and we will help."],
    ];
}
/** The pool rows that exist: key => template row (+ kind). */
function wa_util_pool_rows(PDO $pdo): array
{
    $o = []; foreach ($pdo->query("SELECT * FROM wa_templates WHERE extra LIKE '%util_pool%' ORDER BY id")->fetchAll() as $r) { $x = json_decode((string)($r['extra'] ?? ''), true); $k = is_array($x) ? (string)($x['util_pool'] ?? '') : ''; if ($k !== '' && !isset($o[$k])) { $r['pool_kind'] = (string)($x['util_kind'] ?? 'ask'); $o[$k] = $r; } }
    return $o;
}
/** Creates the missing pool messages and sends them to Meta on every WhatsApp account. */
function wa_util_pool_make(PDO $pdo, int $by): array
{
    $have = wa_util_pool_rows($pdo); $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 6 * max(1, count($wabas))]; $made = []; $err = [];
    foreach (wa_util_pool_defs() as $k => $d) {
        if (isset($have[$k])) continue;
        [$t, $e] = wa_tpl_clean(['name' => 'tmph_fu_' . $k, 'language' => 'en', 'category' => 'UTILITY', 'body' => $d['body'], 'footer' => '', 'buttons' => $d['buttons'], 'examples' => ['body' => $d['ex'], 'labels' => $d['labels']], 'folder' => 'utility']);
        if ($e !== '') { $err[] = $k . ': ' . $e; continue; }
        $t['extra'] = ['util_pool' => $k, 'util_kind' => $d['kind']];
        $id = wa_tpl_insert($pdo, $t, $by, 'Utility follow-up (utility campaign): ' . $d['kind'], 'Utility follow-up, no offer'); $row = wa_tpl_row($pdo, $id);
        foreach ($wabas as $w) { [$ok, $why] = wa_tpl_try_create($pdo, $row, (string)$w, $budget); if (!$ok) $err[] = (string)$row['name'] . ': ' . $why; }
        $made[] = (string)$row['name'];
    }
    if (function_exists('wa_log') && $made) wa_log($pdo, 'template', !$err, 'Utility follow-ups made: ' . implode(', ', $made) . ($err ? ' — ' . $err[0] : ''));
    return ['ok' => true, 'made' => $made, 'errors' => $err];
}
/** For the screens: every pool message with its text, variables and status on each number. */
function wa_util_pool_view(PDO $pdo): array
{
    $rows = wa_util_pool_rows($pdo); $defs = wa_util_pool_defs(); $o = [];
    $what = ['name' => 'customer name', 'perfume' => 'perfume they asked about', 'date' => 'date they asked / ordered', 'order' => 'their order number'];
    foreach ($defs as $k => $d) {
        $r = $rows[$k] ?? null; $per = [];
        if ($r) { $ws = []; $q = $pdo->prepare("SELECT waba_id, status FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([(int)$r['id']]); foreach ($q->fetchAll() as $w) $ws[(string)$w['waba_id']] = strtolower((string)$w['status']);
            foreach (wa_util_numbers($pdo) as $n) $per[] = ['label' => $n['label'], 'status' => $ws[$n['waba']] ?? 'not sent']; }
        $o[] = ['key' => $k, 'kind' => $d['kind'], 'id' => $r ? (int)$r['id'] : 0, 'body' => $d['body'], 'buttons' => array_column($d['buttons'], 'text'), 'vars' => array_map(fn($l, $i) => ['n' => $i + 1, 'what' => $what[$l] ?? $l], $d['labels'], array_keys($d['labels'])),
                'relabelled' => $r && (!empty($r['relabelled_at']) || strtoupper((string)($r['category_now'] ?? '')) === 'MARKETING'), 'per' => $per, 'exists' => (bool)$r];
    }
    return $o;
}
/** The pool messages a campaign may send now (approved somewhere, not moved to marketing by Meta). */
function wa_util_pool_usable(PDO $pdo): array
{
    $o = []; foreach (wa_util_pool_rows($pdo) as $k => $r) { if (!empty($r['relabelled_at']) || strtoupper((string)($r['category_now'] ?? '')) === 'MARKETING') continue; $t = wa_camp_tpl($pdo, (int)$r['id'], true, 0); if (!$t) continue;
        $x = json_decode((string)($r['examples'] ?? ''), true); $t['labels'] = is_array($x) ? array_map('strval', (array)($x['labels'] ?? [])) : []; $t['pool_kind'] = $r['pool_kind']; $o[] = $t; }
    return $o;
}
/** phone10 → ['order' => '#1234', 'at' => 'Y-m-d H:i:s'] — the latest Shopify order of everyone who ordered in the last $days days (one read per run). */
function wa_util_orders(PDO $pdo, int $days): array
{
    static $c = []; if (isset($c[$days])) return $c[$days]; $m = [];
    try { $q = $pdo->prepare("SELECT phone, order_name, created_at FROM shop_orders WHERE created_at >= ? AND phone <> '' AND (financial_status IS NULL OR financial_status NOT IN ('refunded', 'voided')) ORDER BY created_at"); $q->execute([date('Y-m-d H:i:s', time() - $days * 86400)]);
        foreach ($q->fetchAll() as $r) { $k = substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); if (strlen($k) === 10) $m[$k] = ['order' => (string)$r['order_name'], 'at' => (string)$r['created_at']]; } } catch (Throwable $e) { }
    return $c[$days] = $m;
}
/** Who qualifies and with which message: an enquiry on WhatsApp in the last 30 days with a perfume we can name → an "ask" message; else an order in the last $ordDays
 *  days → an "order" message; else null (this person gets no utility message). $rot makes the messages take turns. Returns ['t', 'vars', 'kind'] or null. */
function wa_util_pick(PDO $pdo, array $pool, array $ct, int $ordDays, int $nowTs, int $rot = 0): ?array
{
    if (!$pool || (int)($ct['optout'] ?? 0) === 1) return null; $phone = (string)$ct['phone']; $name = function_exists('wa_first_name') ? wa_first_name((string)($ct['name'] ?? '')) : 'ji';
    $ask = array_values(array_filter($pool, fn($t) => $t['pool_kind'] === 'ask')); $ord = array_values(array_filter($pool, fn($t) => $t['pool_kind'] === 'order'));
    $in = !empty($ct['last_in_at']) ? (int)strtotime((string)$ct['last_in_at']) : 0; $fill = function (array $t, array $v) { $o = []; foreach (array_slice($t['labels'], 0, $t['nvars']) as $l) { $x = trim((string)($v[$l] ?? '')); if ($x === '') return null; $o[] = mb_substr($x, 0, 60); } return count($o) === (int)$t['nvars'] ? $o : null; };
    if ($ask && $in >= $nowTs - 30 * 86400) {
        $p = ''; try { if (function_exists('wa_camp_lazy') ? wa_camp_lazy('wa_prod_last_asked', 'wa_prod.php') : function_exists('wa_prod_last_asked')) $p = (string)wa_prod_last_asked($pdo, $phone); } catch (Throwable $e) { }
        if ($p !== '') { $t = $ask[$rot % count($ask)]; $v = $fill($t, ['name' => $name, 'perfume' => $p, 'date' => date('j M', $in)]); if ($v) return ['t' => $t, 'vars' => $v, 'kind' => 'ask']; }
    }
    if ($ord && $ordDays > 0) { $o = wa_util_orders($pdo, $ordDays)[$phone] ?? null;
        if ($o && $o['order'] !== '') { $t = $ord[$rot % count($ord)]; $v = $fill($t, ['name' => $name, 'order' => $o['order'], 'date' => date('j M', (int)strtotime($o['at']))]); if ($v) return ['t' => $t, 'vars' => $v, 'kind' => 'order']; } }
    return null;
}
/** For the preview: how many of these people qualify (enquiry in 30 days / order in the window) — counted cheaply, the perfume is checked when sending. */
function wa_util_split(PDO $pdo, array $phones, int $ordDays): array
{
    $o = ['ask' => 0, 'order' => 0, 'none' => 0]; $ord = wa_util_orders($pdo, max(1, $ordDays)); $cut = date('Y-m-d H:i:s', time() - 30 * 86400);
    foreach (array_chunk($phones, 500) as $ch) { if (!$ch) continue; $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ") AND optout = 0 AND last_in_at >= ?"); $q->execute(array_merge($ch, [$cut])); $a = array_flip(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($ch as $p) { if (isset($a[(string)$p])) $o['ask']++; elseif (isset($ord[(string)$p])) $o['order']++; else $o['none']++; } }
    return $o;
}
/** 🎁 The offer goes FREE after a reply: a customer who answers a utility follow-up (sent in the last 48 h) gets the campaign's offer text once, as a normal chat message. */
function wa_util_offer_reply(PDO $pdo, string $key): void
{
    try {
        $q = $pdo->prepare("SELECT i.camp_id, c.extra FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.phone = ? AND i.variant = 'U' AND i.status = 'sent' AND i.sent_at >= ? ORDER BY i.id DESC LIMIT 1"); $q->execute([$key, date('Y-m-d H:i:s', time() - 48 * 3600)]); $r = $q->fetch(); if (!$r) return;
        $x = json_decode((string)$r['extra'], true); $offer = is_array($x) ? trim((string)($x['offer'] ?? '')) : ''; if ($offer === '' || ($x['kind'] ?? '') !== 'util') return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_util_offer (camp_id INT NOT NULL, phone VARCHAR(12) NOT NULL, at DATETIME NULL, PRIMARY KEY (camp_id, phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_util_offer (camp_id, phone, at) VALUES (?, ?, ?)"); $ins->execute([(int)$r['camp_id'], $key, date('Y-m-d H:i:s')]); if ($ins->rowCount() === 0) return;   // once per person per campaign
        $ct = function_exists('wa_contact') ? wa_contact($pdo, $key) : null; if (!$ct || (int)($ct['optout'] ?? 0) === 1 || !function_exists('wa_send_text')) return;
        $name = function_exists('wa_first_name') ? wa_first_name((string)($ct['name'] ?? '')) : ''; wa_send_text($pdo, $ct, str_replace(['{name}', '{{name}}'], $name, $offer), 'campaign', 0);
    } catch (Throwable $e) { error_log('wa_util_offer_reply: ' . $e->getMessage()); }
}
/** The utility messages a campaign can use: this message's family first, then any approved utility message. */
function wa_util_list(PDO $pdo, int $tplId): array
{
    $m = function_exists('wa_tpl_row') ? wa_tpl_row($pdo, $tplId) : null; $fam = $m ? (int)($m['family_id'] ?: $m['id']) : 0; $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
    $o = [];
    foreach ($pdo->query("SELECT * FROM wa_templates WHERE category = 'UTILITY' ORDER BY id DESC LIMIT 300")->fetchAll() as $r) {
        $st = function_exists('wa_tpl_status') ? wa_tpl_status($pdo, (int)$r['id'], $wabas) : ['status' => '?']; $status = is_array($st) ? (string)($st['status'] ?? '?') : (string)$st;
        $mine = $fam > 0 && (int)($r['family_id'] ?? 0) === $fam;
        if (!$mine && !in_array($status, ['approved', 'partial'], true)) continue;
        $ex = json_decode((string)($r['examples'] ?? ''), true); $lab = is_array($ex) ? array_map('strval', (array)($ex['labels'] ?? [])) : []; preg_match_all('/\{\{(\d+)\}\}/', (string)$r['body'], $vm);
        $vars = []; foreach (array_unique(array_map('intval', $vm[1])) as $vi) $vars[] = ['n' => $vi, 'what' => ['name' => 'customer name', 'perfume' => 'perfume they asked about / ordered', 'date' => 'date they asked / ordered'][$lab[$vi - 1] ?? ''] ?? ($lab[$vi - 1] ?? '')];
        $per = []; if ($mine) { $ws = []; $q = $pdo->prepare("SELECT waba_id, status FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([(int)$r['id']]); foreach ($q->fetchAll() as $w) $ws[(string)$w['waba_id']] = strtolower((string)$w['status']);
            foreach (wa_util_numbers($pdo) as $n) $per[] = ['label' => $n['label'], 'status' => $ws[$n['waba']] ?? 'not sent']; }
        $draft = $mine && !array_filter($per, fn($p) => $p['status'] !== 'not sent');
        $o[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'body' => (string)$r['body'], 'status' => $draft ? 'draft' : $status, 'draft' => $draft, 'relabelled' => strtoupper((string)($r['category_now'] ?? '')) === 'MARKETING' || !empty($r['relabelled_at']), 'mine' => $mine, 'vars' => $vars, 'per' => $per];
    }
    usort($o, fn($a, $b) => [$b['mine'], $b['status'] === 'approved'] <=> [$a['mine'], $a['status'] === 'approved']);
    return $o;
}

}   // function_exists guard

$__wut = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wut, ['wa.tpl_make_utility', 'wa.tpl_utility_list', 'wa.util_pool', 'wa.util_pool_make', 'wa.util_send', 'wa.util_edit'], true)) {
    $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    if (function_exists('wa_tpl_tables')) wa_tpl_tables($pdo);
    if ($__wut === 'wa.tpl_utility_list') json_response(['ok' => true, 'items' => wa_util_list($pdo, (int)($_GET['tpl_id'] ?? 0))]);
    if ($__wut === 'wa.util_pool') json_response(['ok' => true, 'items' => wa_util_pool_view($pdo)]);
    if ($__wut === 'wa.util_pool_make') { if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405); wa_util_pool_make($pdo, (int)($auth['employee_id'] ?? 0)); json_response(['ok' => true, 'items' => wa_util_pool_view($pdo)]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); @set_time_limit(180);
    if ($__wut === 'wa.util_send') { $r = wa_util_submit($pdo, (int)($in['id'] ?? 0)); json_response($r, isset($r['error']) ? 422 : 200); }   // ✅ the owner approved the tone → Meta
    if ($__wut === 'wa.util_edit') {   // ✏️ the owner rewrote a version: kept as a lesson for Claude; a version already sent to Meta becomes a new draft (Meta's copy is not touched)
        $row = wa_tpl_row($pdo, (int)($in['id'] ?? 0)); if (!$row || strtoupper((string)$row['category']) !== 'UTILITY') json_response(['error' => 'Utility version not found.'], 404);
        $body = trim((string)($in['body'] ?? '')); $why = wa_util_check($body); if ($why !== '') json_response(['error' => $why], 422);
        wa_util_lesson_add($pdo, (string)$row['body'], $body);
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([(int)$row['id']]); $id = (int)$row['id'];
        if ((int)$q->fetchColumn() === 0) $pdo->prepare("UPDATE wa_templates SET body = ? WHERE id = ?")->execute([$body, $id]);
        else { $m = wa_tpl_row($pdo, (int)(json_decode((string)($row['extra'] ?? ''), true)['utility_for'] ?? 0)) ?: $row; $id = wa_util_insert($pdo, $m, substr((string)$row['name'], 0, 44) . '_e', $body, (int)($auth['employee_id'] ?? 0)); }
        $r = ['ok' => true, 'id' => $id]; if (!empty($in['send'])) $r += wa_util_submit($pdo, $id); json_response($r);
    }
    $r = wa_util_make($pdo, (int)($in['tpl_id'] ?? 0), (int)($in['n'] ?? 3), (int)($auth['employee_id'] ?? 0), false);
    if (isset($r['error'])) json_response($r, 502);
    json_response($r);
}
